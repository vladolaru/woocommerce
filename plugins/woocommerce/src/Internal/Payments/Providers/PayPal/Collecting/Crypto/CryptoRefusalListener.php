<?php
/**
 * CryptoRefusalListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CryptoCheckMarker;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Records "not available" when PayPal refuses a checkout's Pay with Crypto order because the merchant cannot take crypto.
 *
 * A recorded "available" can go stale when the merchant loses crypto. The gateway's refusal is the first sign, so on a
 * platform-connected store a crypto order creation that PayPal refuses with `NOT_ENABLED_FOR_PAYMENT_SOURCE` records
 * "not available", and the method stops showing until an operator runs the check again. It listens on WordPress's
 * `http_api_debug` action, so `PWCGateway` stays verbatim. The check's own order is left to the check. A refusal counts
 * only for an order made for the platform merchant (its payee and the seller's assertion) in the platform connection's
 * environment.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CryptoRefusalListener {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The platform transport, to recognise a crypto order creation.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The recorded answer.
	 *
	 * @var CryptoCheckMarker
	 */
	private CryptoCheckMarker $marker;

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
	 * @param CryptoCheckMarker $marker           The recorded answer.
	 * @param LoggerInterface   $logger           The logger.
	 */
	public function __construct( ConnectionState $connection_state, PlatformTransport $transport, CryptoCheckMarker $marker, LoggerInterface $logger ) {
		$this->connection_state = $connection_state;
		$this->transport        = $transport;
		$this->marker           = $marker;
		$this->logger           = $logger;
	}

	/**
	 * Record "not available" for a refused crypto order creation of a platform-connected store.
	 *
	 * Any other request is left after a method and URL comparison; only a crypto order creation has its body decoded.
	 *
	 * @since 11.3.0
	 * @internal
	 *
	 * @param mixed $response        The response, or a WP_Error.
	 * @param mixed $context         The context; `response` once a request is answered.
	 * @param mixed $transport_class The HTTP transport class.
	 * @param mixed $args            The request arguments.
	 * @param mixed $url             The request URL.
	 */
	public function handle_http_api_debug( $response, $context = '', $transport_class = '', $args = array(), $url = '' ): void {
		unset( $transport_class );
		if (
			'response' !== $context
			|| ! is_array( $args )
			|| ! is_string( $url )
			|| ! CryptoCreateAnswer::is_crypto_create( $url, $args, $this->transport )
			|| CryptoCreateAnswer::REFUSED !== CryptoCreateAnswer::outcome( $response )
			|| CryptoCheckBody::CHECK_ID === CryptoCreateAnswer::custom_id( $args )
			|| ConnectionState::PLATFORM_CONNECTED !== $this->connection_state->resolve()
			|| ! CryptoCreateAnswer::is_for_merchant( $args, $this->marker->platform_merchant_id() )
		) {
			return;
		}

		// The order went to the environment the transport serves; an answer from another one is not recorded.
		if ( $this->marker->record( false, $this->transport->environment() ) ) {
			$this->logger->warning( sprintf( 'PayPal refused a Pay with Crypto order (%s), so Pay with Crypto is recorded as not available until the check runs again. PayPal debug ID: %s', CryptoCreateAnswer::NOT_ENABLED_ISSUE, CryptoCreateAnswer::debug_id( $response ) ) );
		}
	}
}
