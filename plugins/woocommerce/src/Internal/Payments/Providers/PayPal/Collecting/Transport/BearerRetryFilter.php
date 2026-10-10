<?php
/**
 * BearerRetryFilter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Signs the single retry after an authentication failure with a fresh token of the app the failed call went through.
 *
 * The wallet's own retry filter runs first and drops only its first-party token. This one runs after it and signs the
 * retry with a new token of the call's app. It drops the cached token first only when the transport's bearer is a
 * PerAppBearer, the transport's own; another Bearer is asked for its token as it is.
 *
 * It acts only on a request to the app's API host, so a platform token is never signed onto another host, and leaves a
 * request that carries the seller's PayPal-Auth-Assertion to AssertedRefundSigner.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class BearerRetryFilter {

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
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * The collecting state, for the store payee.
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
	 * Re-sign a Bearer-signed request with a fresh token of the app the call goes through.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $args The request arguments.
	 * @param mixed $url  The request URL.
	 * @return mixed The arguments, with the Authorization header replaced when a fresh token could be issued.
	 */
	public function handle_ppcp_retry_request_args( $args, $url = '' ) {
		if ( ! is_array( $args ) || ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			return $args;
		}
		$authorization = $args['headers']['Authorization'] ?? '';
		if ( ! is_string( $authorization ) || 0 !== strpos( $authorization, 'Bearer ' ) || ! $this->connection_state->is_served_by_platform() ) {
			return $args;
		}
		// A refund signed for the seller is re-signed by AssertedRefundSigner with the platform app; dropping the order
		// app's token here would only cost it a token request.
		if ( AssertedRefundSigner::has_assertion( $args['headers'] ) ) {
			return $args;
		}

		try {
			$app = $this->context->for_call( $this->transport, $this->state->payee_email() );
			if ( ! is_string( $url ) || 0 !== strpos( $url, trailingslashit( $this->transport->host( $app ) ) ) ) {
				return $args;
			}

			$bearer = $this->transport->bearer( $app );
			if ( $bearer instanceof PerAppBearer ) {
				$bearer->forget();
			}
			$args['headers']['Authorization'] = 'Bearer ' . $bearer->bearer()->token();
		} catch ( RuntimeException $exception ) {
			$this->logger->warning( 'Could not refresh the platform access token for request retry: ' . $exception->getMessage() );
		}

		return $args;
	}
}
