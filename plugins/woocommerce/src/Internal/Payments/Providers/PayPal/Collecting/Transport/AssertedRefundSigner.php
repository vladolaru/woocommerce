<?php
/**
 * AssertedRefundSigner class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Signs a capture refund with the platform app and the PayPal-Auth-Assertion for the connected seller, once the store is
 * platform connected.
 *
 * The one signing path that differs from the order's pin. A capture made by the merchant app can be refunded, after the
 * seller consented, only by the platform app acting for the seller (sandbox run 1, refund by capture ID). So only the
 * `POST v2/payments/captures/{id}/refund` call is re-signed; the order GET before it and every capture read stay on the
 * pinned app, which alone can see the order. While collecting no seller is known, so nothing is re-signed.
 *
 * It acts only on a request to one of the transport's API hosts, so the platform token never goes to another host.
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
	 * @param LoggerInterface   $logger           The logger.
	 */
	public function __construct( ConnectionState $connection_state, PlatformTransport $transport, LoggerInterface $logger ) {
		$this->connection_state = $connection_state;
		$this->transport        = $transport;
		$this->logger           = $logger;
	}

	/**
	 * Sign a capture refund with the platform app's token and the seller's assertion.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $args The request arguments.
	 * @param mixed $url  The request URL.
	 * @return mixed The arguments, signed for the seller when the request is a capture refund of a platform-connected store.
	 */
	public function handle_ppcp_request_args( $args, $url = '' ) {
		return $this->sign( $args, $url, false );
	}

	/**
	 * Sign the retry of a capture refund with a fresh platform token and the seller's assertion, after the retry filter
	 * that re-signs with the order's app.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $args The retry's request arguments.
	 * @param mixed $url  The request URL.
	 * @return mixed The arguments, signed for the seller when the request is a capture refund of a platform-connected store.
	 */
	public function handle_ppcp_retry_request_args( $args, $url = '' ) {
		return $this->sign( $args, $url, true );
	}

	/**
	 * Sign the request when it is a capture refund of a platform-connected store; leave anything else as it is.
	 *
	 * @param mixed $args  The request arguments.
	 * @param mixed $url   The request URL.
	 * @param bool  $fresh Whether to drop the platform app's cached token first, for a retry.
	 * @return mixed
	 */
	private function sign( $args, $url, bool $fresh ) {
		if ( ! is_array( $args ) || ! is_string( $url ) || ! is_string( $args['method'] ?? null ) || 'POST' !== strtoupper( $args['method'] ) ) {
			return $args;
		}

		// A transport without credentials signs nothing, and asking it for a host would only log a failure.
		if ( ! $this->transport->is_ready() ) {
			return $args;
		}

		try {
			if ( ! $this->is_capture_refund( $url ) || ConnectionState::PLATFORM_CONNECTED !== $this->connection_state->resolve() ) {
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
			$this->logger->warning( 'Could not sign the PayPal refund for the connected seller: ' . $exception->getMessage() );
			// The bearer and the assertion go together or not at all: a retry another filter re-signed with the order's
			// app must not carry the seller's assertion.
			if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
				$args['headers'] = self::without_assertion( $args['headers'] );
			}
		}

		return $args;
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
	 * Whether a URL is a capture refund on one of the transport's API hosts.
	 *
	 * @param string $url The request URL.
	 * @return bool
	 *
	 * @throws RuntimeException When the transport cannot name a host.
	 */
	private function is_capture_refund( string $url ): bool {
		foreach ( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ) as $app ) {
			$host = trailingslashit( $this->transport->host( $app ) );
			if ( 0 === strpos( $url, $host ) ) {
				return 1 === preg_match( self::REFUND_PATH, substr( $url, strlen( $host ) ) );
			}
		}

		return false;
	}
}
