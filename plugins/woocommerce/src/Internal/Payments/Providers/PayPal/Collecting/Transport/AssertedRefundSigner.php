<?php
/**
 * AssertedRefundSigner class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCreateAnswer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Signs three kinds of call with the platform app and the PayPal-Auth-Assertion for the connected seller, once the store
 * is platform connected: a capture refund, the creation of a Pay with Crypto order, and the reads of an order or a
 * capture that go through the platform app.
 *
 * A capture made by the merchant app can be refunded, after the seller consented, only by the platform app acting for
 * the seller (sandbox run 1, refund by capture ID). So the `POST v2/payments/captures/{id}/refund` call is re-signed.
 *
 * PayPal accepted a crypto order for the seller carrying the seller's assertion (probe rerun 2), as its Pay with Crypto
 * guide asks for platform merchants. So a `POST v2/checkout/orders` whose body has the `crypto` payment source is signed
 * too. Every other order creation, such as a PayPal button order, has the same URL and stays as the wallet signed it.
 *
 * An order made for the seller is hidden from the platform app's own token: `GET v2/checkout/orders/{id}` answers 404
 * and `GET v2/payments/captures/{id}` 401 without the assertion, 200 with it (I2 probe). So those two reads are signed
 * when the call goes through the platform app: the entered order's pin, or the transport's pick, names it, and the
 * request already carries the platform app's cached token. An order pinned to the merchant app keeps that app's own
 * token, and a read signed with other credentials, such as the manual connection's check of typed-in credentials, is
 * left alone. The check reads the token cache only and never asks PayPal for a token.
 *
 * While collecting no seller is known, so nothing is re-signed. It acts only on a request to one of the transport's API
 * hosts, so the platform token never goes to another host.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class AssertedRefundSigner {

	/**
	 * The path of a capture refund under the API host.
	 */
	private const REFUND_PATH = '#^v2/payments/captures/[^/?]+/refund/?$#';

	/**
	 * The paths of an order read and a capture read under the API host, without their query string.
	 */
	private const READ_PATH = '#^v2/(checkout/orders|payments/captures)/[^/?]+/?$#';

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The order app context, for the app a read goes through.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * The collecting state, for the store payee the transport picks by.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState   $connection_state The connection state.
	 * @param PlatformTransport $transport        The platform transport.
	 * @param OrderAppContext   $context          The order app context.
	 * @param CollectingState   $state            The collecting state.
	 * @param LoggerInterface   $logger           The logger.
	 */
	public function __construct( ConnectionState $connection_state, PlatformTransport $transport, OrderAppContext $context, CollectingState $state, LoggerInterface $logger ) {
		$this->connection_state = $connection_state;
		$this->transport        = $transport;
		$this->context          = $context;
		$this->state            = $state;
		$this->logger           = $logger;
	}

	/**
	 * Sign a capture refund, a crypto order creation or a platform app's order or capture read with the platform app's
	 * token and the seller's assertion.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $args The request arguments.
	 * @param mixed $url  The request URL.
	 * @return mixed The arguments, signed for the seller when the request is one of the signed calls of a
	 *               platform-connected store.
	 */
	public function handle_ppcp_request_args( $args, $url = '' ) {
		return $this->sign( $args, $url, false );
	}

	/**
	 * Sign the retry of a capture refund, a crypto order creation or a platform app's order or capture read with a fresh
	 * platform token and the seller's assertion, after the retry filter that re-signs with the order's app.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $args The retry's request arguments.
	 * @param mixed $url  The request URL.
	 * @return mixed The arguments, signed for the seller when the request is one of the signed calls of a
	 *               platform-connected store.
	 */
	public function handle_ppcp_retry_request_args( $args, $url = '' ) {
		return $this->sign( $args, $url, true );
	}

	/**
	 * Sign the request when it is one of the signed calls of a platform-connected store; leave anything else as it is.
	 *
	 * @param mixed $args  The request arguments.
	 * @param mixed $url   The request URL.
	 * @param bool  $fresh Whether to drop the platform app's cached token first, for a retry.
	 * @return mixed
	 */
	private function sign( $args, $url, bool $fresh ) {
		if ( ! is_array( $args ) || ! is_string( $url ) ) {
			return $args;
		}
		// The wallet's reads go through wp_remote_get() with no method argument.
		$method = $args['method'] ?? 'GET';
		if ( ! is_string( $method ) || ! in_array( strtoupper( $method ), array( 'GET', 'POST' ), true ) ) {
			return $args;
		}
		$method = strtoupper( $method );

		// A transport without credentials signs nothing, and asking it for a host would only log a failure.
		if ( ! $this->transport->is_ready() ) {
			return $args;
		}

		try {
			if (
				! $this->is_signed_call( $method, $url, $args )
				|| ConnectionState::PLATFORM_CONNECTED !== $this->connection_state->resolve()
				|| ( 'GET' === $method && ( PlatformTransport::APP_PLATFORM !== $this->context->for_call( $this->transport, $this->state->payee_email() ) || ! self::carries_platform_token( $args ) ) )
			) {
				return $args;
			}
			$assertion = $this->transport->assertion_header( PlatformTransport::APP_PLATFORM );
			if ( array() === $assertion ) {
				return $args;
			}

			$bearer = $this->transport->bearer( PlatformTransport::APP_PLATFORM );
			if ( $fresh && $bearer instanceof PerAppBearer ) {
				$bearer->forget();
			}
			$headers                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? self::without_assertion( $args['headers'] ) : array();
			$headers['Authorization'] = 'Bearer ' . $bearer->bearer()->token();
			$args['headers']          = array_merge( $headers, $assertion );
		} catch ( RuntimeException $exception ) {
			$this->logger->warning( 'Could not sign the PayPal request for the connected seller: ' . $exception->getMessage() );
			// The bearer and the assertion go together or not at all: a retry another filter re-signed with the order's
			// app must not carry the seller's assertion.
			if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
				$args['headers'] = self::without_assertion( $args['headers'] );
			}
		}

		return $args;
	}

	/**
	 * Whether a request's Authorization header carries the platform app's cached token. With no token cached, the
	 * request cannot carry it.
	 *
	 * @param array $args The request arguments.
	 * @return bool
	 */
	private static function carries_platform_token( array $args ): bool {
		$authorization = $args['headers']['Authorization'] ?? null;
		$token         = PerAppBearer::cached_token( PlatformTransport::APP_PLATFORM );

		return null !== $token && is_string( $authorization ) && hash_equals( 'Bearer ' . $token, $authorization );
	}

	/**
	 * Whether request headers carry a PayPal-Auth-Assertion, whatever the case of its name.
	 *
	 * @since 11.3.0
	 *
	 * @param array $headers The request headers.
	 * @return bool
	 */
	public static function has_assertion( array $headers ): bool {
		return count( self::without_assertion( $headers ) ) !== count( $headers );
	}

	/**
	 * The headers without any PayPal-Auth-Assertion, whatever the case of its name.
	 *
	 * @param array $headers The request headers.
	 * @return array
	 */
	private static function without_assertion( array $headers ): array {
		return array_filter(
			$headers,
			static function ( $name ): bool {
				return ! is_string( $name ) || 'paypal-auth-assertion' !== strtolower( $name );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Whether a request is a capture refund, a crypto order creation, or an order or capture read, on one of the
	 * transport's API hosts.
	 *
	 * @param string $method The upper-case HTTP method, GET or POST.
	 * @param string $url    The request URL.
	 * @param array  $args   The request arguments.
	 * @return bool
	 *
	 * @throws RuntimeException When the transport cannot name a host.
	 */
	private function is_signed_call( string $method, string $url, array $args ): bool {
		foreach ( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ) as $app ) {
			$host = trailingslashit( $this->transport->host( $app ) );
			if ( 0 === strpos( $url, $host ) ) {
				$path = substr( $url, strlen( $host ) );
				if ( 'GET' === $method ) {
					return 1 === preg_match( self::READ_PATH, explode( '?', $path, 2 )[0] );
				}
				if ( 1 === preg_match( self::REFUND_PATH, $path ) ) {
					return true;
				}

				// The check and the checkout re-check read PayPal's answer for exactly the creations signed here.
				return CryptoCreateAnswer::is_crypto_create( $url, $args, $this->transport );
			}
		}

		return false;
	}
}
