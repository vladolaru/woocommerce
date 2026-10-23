<?php
/**
 * CryptoCheck class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CryptoCheckMarker;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\Orders;
use Exception;
use RuntimeException;

/**
 * The Pay with Crypto check: asks PayPal to create one crypto order for the platform merchant and records the answer.
 *
 * The order goes through the wallet's `Orders::create()`, the gateway's own path, so seam 10 names the payee and the
 * signer adds the seller's assertion. It creates no WooCommerce order, and nobody approves the PayPal one, which expires
 * unpaid. An accepted order records "available", a refusal for the payment source records "not available", and any other
 * answer leaves the recorded answer as it is. It refuses to run while the transport serves another environment than the
 * platform connection's. Only an operator starts it, from WP-CLI.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CryptoCheck {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The platform transport, to recognise the check's request.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The check order's body.
	 *
	 * @var CryptoCheckBody
	 */
	private CryptoCheckBody $body;

	/**
	 * The recorded answer.
	 *
	 * @var CryptoCheckMarker
	 */
	private CryptoCheckMarker $marker;

	/**
	 * Returns the wallet's Orders endpoint, built only when the check sends.
	 *
	 * @var callable
	 */
	private $orders;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState   $connection_state The connection state.
	 * @param PlatformTransport $transport        The platform transport.
	 * @param CryptoCheckBody   $body             The check order's body.
	 * @param CryptoCheckMarker $marker           The recorded answer.
	 * @param callable          $orders           Returns the wallet's Orders endpoint.
	 */
	public function __construct( ConnectionState $connection_state, PlatformTransport $transport, CryptoCheckBody $body, CryptoCheckMarker $marker, callable $orders ) {
		$this->connection_state = $connection_state;
		$this->transport        = $transport;
		$this->body             = $body;
		$this->marker           = $marker;
		$this->orders           = $orders;
	}

	/**
	 * The body the check would send, with nothing sent.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 * @throws RuntimeException When the store is not platform connected.
	 */
	public function body(): array {
		if ( ConnectionState::PLATFORM_CONNECTED !== $this->connection_state->resolve() ) {
			throw new RuntimeException( 'The Pay with Crypto check runs only on a store that is platform connected to its PayPal merchant.' );
		}

		return $this->body->build( time() );
	}

	/**
	 * Create the check order and record PayPal's answer.
	 *
	 * The answer is recorded only for an order made for the platform merchant (its payee and the seller's assertion, as
	 * WordPress reports the request) in the platform connection's environment; anything else is reported as unknown.
	 *
	 * @since 11.3.0
	 *
	 * @return array{outcome: string, recorded: bool, http_status: int, order_id: string, order_status: string, debug_id: string, error: string}
	 *               The outcome (a CryptoCreateAnswer constant), whether it was stored, and what PayPal answered.
	 * @throws RuntimeException When the store is not platform connected, or the transport serves another environment than
	 *                          the platform connection's.
	 */
	public function run(): array {
		$body        = $this->body();
		$environment = $this->transport->environment();
		if ( $environment !== $this->marker->platform_environment() ) {
			throw new RuntimeException(
				sprintf(
					'The platform transport serves the %1$s environment, but the platform connection was made in %2$s, so the check would not answer for it.',
					esc_html( $environment ),
					esc_html( '' === $this->marker->platform_environment() ? 'an unknown environment' : $this->marker->platform_environment() )
				)
			);
		}

		// Orders::create() turns a refusal into an exception with PayPal's description, so the answer itself, and the
		// request as sent, are read from the request WordPress reports.
		$reported      = null;
		$reported_args = null;
		$capture       = function ( $response, $context = '', $transport_class = '', $args = array(), $url = '' ) use ( &$reported, &$reported_args ): void {
			unset( $transport_class );
			if ( 'response' === $context && is_array( $args ) && is_string( $url ) && CryptoCreateAnswer::is_crypto_create( $url, $args, $this->transport ) ) {
				$reported      = $response;
				$reported_args = $args;
			}
		};
		add_action( 'http_api_debug', $capture, 10, 5 );
		$error = '';
		try {
			$orders = ( $this->orders )();
			assert( $orders instanceof Orders );
			$response = $orders->create( $body );
		} catch ( Exception $exception ) {
			$response = $reported;
			$error    = $exception->getMessage();
		} finally {
			remove_action( 'http_api_debug', $capture, 10 );
		}

		$outcome = CryptoCreateAnswer::outcome( $response );
		if ( CryptoCreateAnswer::UNKNOWN !== $outcome && ( null === $reported_args || ! CryptoCreateAnswer::is_for_merchant( $reported_args, $this->marker->platform_merchant_id() ) ) ) {
			$outcome = CryptoCreateAnswer::UNKNOWN;
			$error   = 'PayPal\'s answer could not be matched to an order for the platform merchant with the seller\'s assertion, so it is not recorded.';
		}
		$recorded = CryptoCreateAnswer::UNKNOWN !== $outcome && $this->marker->record( CryptoCreateAnswer::ACCEPTED === $outcome, $environment );

		return array(
			'outcome'      => $outcome,
			'recorded'     => $recorded,
			'http_status'  => CryptoCreateAnswer::status_code( $response ),
			'order_id'     => CryptoCreateAnswer::order_id( $response ),
			'order_status' => CryptoCreateAnswer::order_status( $response ),
			'debug_id'     => CryptoCreateAnswer::debug_id( $response ),
			'error'        => $error,
		);
	}
}
