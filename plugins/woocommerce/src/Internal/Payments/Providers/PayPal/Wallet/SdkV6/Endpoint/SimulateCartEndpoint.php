<?php
/**
 * Prices a product without touching the shopper's cart, for the Pay Later
 * messaging simulation.
 *
 * Button\Endpoint\SimulateCartEndpoint answers the same question but cannot serve
 * these pages: it requires button.smart-button to be a real SmartButton, which this
 * module replaces with DisabledSmartButton wherever it owns the page (see
 * extensions.php). Extending this endpoint is the way to add the Pay Later
 * eligibility and button-disabled answers that one also returns.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\IsolatedCartSimulator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\AbstractCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\CartProductsHelper;

/**
 * Prices a product through the SDK v6 endpoint, without touching the shopper's cart.
 */
class SimulateCartEndpoint extends AbstractCartEndpoint {

	const ENDPOINT = 'ppc-sdk-v6-simulate-cart';

	/**
	 * The cart simulator.
	 *
	 * @var IsolatedCartSimulator
	 */
	private IsolatedCartSimulator $cart_simulator;

	/**
	 * SimulateCartEndpoint constructor.
	 *
	 * @param RequestData           $request_data   The request data.
	 * @param CartProductsHelper    $cart_products  The cart products.
	 * @param IsolatedCartSimulator $cart_simulator The cart simulator.
	 * @param LoggerInterface       $logger         The logger.
	 */
	public function __construct(
		RequestData $request_data,
		CartProductsHelper $cart_products,
		IsolatedCartSimulator $cart_simulator,
		LoggerInterface $logger
	) {
		$this->request_data   = $request_data;
		$this->cart_products  = $cart_products;
		$this->cart_simulator = $cart_simulator;
		$this->logger         = $logger;

		$this->logger_tag = 'simulation';
	}

	/**
	 * Responds with the simulated total for the posted products.
	 *
	 * @throws Exception If the cart simulation fails.
	 */
	protected function handle_data(): void {
		/**
		 * The filter that switches cart simulation off, honoured here too so a
		 * merchant who disabled it does not get it back through this endpoint.
		 *
		 * @since 11.3.0
		 *
		 * @param bool $enabled Whether cart simulation is enabled; true by default.
		 */
		if ( ! apply_filters( 'woocommerce_paypal_payments_simulate_cart_enabled', true ) ) {
			wp_send_json_error(
				array(
					'name'    => '',
					'message' => 'Cart simulation is disabled.',
					'code'    => 0,
					'details' => array(),
				)
			);
		}

		// Validates the nonce, and responds on its own when no usable products were posted.
		$products = $this->products_from_request();
		if ( ! $products ) {
			return;
		}

		// Nothing here is meant to outlive the response, and persisting the
		// session would discard a concurrent add-to-cart.
		$this->prevent_session_persistence();

		$result = $this->cart_simulator->simulate( $products );

		wp_send_json_success(
			array(
				// A string at the currency's own precision, because the caller puts it
				// straight into a payment sheet: a float renders as 10 where the shopper
				// expects 10.00, and a fixed 2 truncates 3-decimal currencies.
				'total'         => wc_format_decimal( $result['total'], wc_get_price_decimals() ),
				'currency_code' => get_woocommerce_currency(),
			)
		);
	}
}
