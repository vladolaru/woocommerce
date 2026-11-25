<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\OrderContextService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use WC_Order;

/**
 * Runs payments code on a store with the built-in Multi-Currency answering the currency format, as on a storefront request.
 *
 * A test case using it resets the container replacements in its tear down.
 */
trait StorefrontMultiCurrencyTestTrait {

	/**
	 * Save a USD order with a total while the store is a USD store at 2 decimals, before any currency filter runs.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_saved_usd_order( string $total ): WC_Order {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	/**
	 * Turn the built-in Multi-Currency on and register its storefront currency filters.
	 *
	 * @param string               $store_currency Store currency, set with its own decimals (0 for JPY, else 2).
	 * @param array<string,string> $manual_rates   Manual rates of the other enabled currencies, keyed by code.
	 */
	private function register_storefront_multi_currency( string $store_currency, array $manual_rates ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', 'JPY' === $store_currency ? '0' : '2' );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array_merge( array( $store_currency ), array_keys( $manual_rates ) ) );
		foreach ( $manual_rates as $currency_code => $rate ) {
			update_option( 'wcpay_multi_currency_exchange_rate_' . strtolower( $currency_code ), 'manual' );
			update_option( 'wcpay_multi_currency_manual_rate_' . strtolower( $currency_code ), $rate );
		}

		$arbiter = wc_get_container()->get( MultiCurrencyRuntimeArbiter::class );
		$this->assertTrue( $arbiter->is_builtin_owner(), 'Precondition: the built-in Multi-Currency owns the store.' );
		// A fresh controller, so no state cached by an earlier test is read; the payment notes format through the container's.
		$controller = new MultiCurrencyFrontendCurrenciesController();
		$controller->init( $arbiter, wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ), wc_get_container()->get( OrderContextService::class ) );
		wc_get_container()->replace( MultiCurrencyFrontendCurrenciesController::class, $controller );
		$controller->register();
	}
}
