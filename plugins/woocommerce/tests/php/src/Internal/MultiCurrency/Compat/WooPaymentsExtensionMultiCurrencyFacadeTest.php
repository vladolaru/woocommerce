<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use WC_Unit_Test_Case;

require_once __DIR__ . '/Fixtures/multi-currency-facade-plugin-callers.inc';

/**
 * Tests the members of the `WCPay\MultiCurrency\MultiCurrency` facade on a store where WooCommerce runs Multi-Currency,
 * through the calls the plugins that use them make.
 *
 * Each case declares the facade, so each runs in its own process.
 */
class WooPaymentsExtensionMultiCurrencyFacadeTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The facade's constants hold the WooPayments extension's values.
	 *
	 * Values from client 11.1.0 `includes/multi-currency/MultiCurrency.php:29-49`.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constants_hold_the_extension_values(): void {
		$this->declare_facade();

		$expected = array(
			'CURRENCY_SESSION_KEY'                  => 'wcpay_currency',
			'CURRENCY_META_KEY'                     => 'wcpay_currency',
			'FILTER_PREFIX'                         => 'wcpay_multi_currency_',
			'CUSTOMER_CURRENCIES_KEY'               => 'wcpay_multi_currency_stored_customer_currencies',
			'RENDERING_MODE_SPEED'                  => 'speed',
			'RENDERING_MODE_CACHE'                  => 'cache',
			'RENDERING_MODE_OPTION'                 => 'wcpay_multi_currency_rendering_mode',
			'CACHE_AUTODETECT_DONE_OPTION'          => 'wcpay_multi_currency_cache_autodetect_done',
			'CACHE_RECOMMENDATION_DISMISSED_OPTION' => 'wcpay_multi_currency_cache_recommendation_dismissed',
		);

		foreach ( $expected as $name => $value ) {
			$this->assertSame( $value, constant( 'WCPay\\MultiCurrency\\MultiCurrency::' . $name ), $name );
		}
	}

	/**
	 * @testdox Payfast reads the shopper's selected currency through the facade's session and user meta keys.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_payfast_reads_the_selected_currency_through_the_key_constants(): void {
		$this->declare_facade();
		$fixture = new MultiCurrencyFacadePluginCallersFixture();

		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'ZAR' );
		$this->assertSame( 'ZAR', $fixture->payfast_filter_currency( 'USD' ), 'A customer\'s currency is read from user meta.' );

		wp_set_current_user( 0 );
		WC()->session = new \WC_Session_Handler();
		WC()->session->init();
		WC()->session->set( 'wcpay_currency', 'ZAR' );
		$this->assertSame( 'ZAR', $fixture->payfast_filter_currency( 'USD' ), 'A guest\'s currency is read from the session.' );

		WC()->session->set( 'wcpay_currency', 'EUR' );
		$this->assertSame( 'USD', $fixture->payfast_filter_currency( 'USD' ) );
	}

	/**
	 * @testdox Advanced Dynamic Pricing stops the module's product price conversion through the facade's hook prefix.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_advanced_dynamic_pricing_reaches_the_module_hook_through_the_prefix(): void {
		$this->declare_facade();
		$product    = \WC_Helper_Product::create_simple_product();
		$controller = wc_get_container()->get( MultiCurrencyCompatibilityController::class );
		$this->assertTrue( $controller->should_convert_product_price( $product ), 'Precondition: the module converts the product price.' );

		( new MultiCurrencyFacadePluginCallersFixture() )->adp_stop_product_price_conversion();

		$this->assertFalse( $controller->should_convert_product_price( $product ) );
	}

	/**
	 * Turn WooCommerce's Multi-Currency on for a USD store with EUR and GBP enabled at manual rates, and declare the
	 * facade as the loader does at `plugins_loaded`.
	 */
	private function declare_facade(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR', 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.73' );

		$this->assertTrue( wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->is_builtin_owner(), 'Precondition: WooCommerce runs Multi-Currency.' );
		wc_get_container()->get( WooPaymentsExtensionMultiCurrencyFacadeLoader::class )->load();
		$this->assertTrue( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'Precondition: the facade is declared.' );
	}
}
