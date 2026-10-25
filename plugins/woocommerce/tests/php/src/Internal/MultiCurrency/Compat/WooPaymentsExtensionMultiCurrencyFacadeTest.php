<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidgetController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
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
	 * @testdox FOX Currency Switcher finds the enabled currencies and the store currency through the facade.
	 *
	 * The calls after FOX's guard; the guard's `WC_Payments_Features::is_customer_multi_currency_enabled()` belongs to the
	 * `WC_Payments_Features` facade.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fox_reads_the_enabled_and_store_currencies(): void {
		$this->declare_facade();
		$fixture = new MultiCurrencyFacadePluginCallersFixture();
		$this->expect_facade_deprecations( 'instance', 'get_enabled_currencies', 'get_default_currency' );

		$this->assertSame( 'Option: Multi-currency of WooPayments plugin by Woo.', $fixture->fox_incompatible_plugin_notice( 'USD' ), 'Two more currencies are enabled.' );

		update_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$this->assertSame( '', $fixture->fox_incompatible_plugin_notice( 'USD' ), 'Only the store currency is enabled, and it is FOX\'s default.' );
	}

	/**
	 * @testdox The facade's currency reads answer with the module's state.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_currency_reads_answer_with_the_module_state(): void {
		$this->declare_facade();
		$facade = $this->get_facade();
		$state  = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->get_state_builder()->build();
		$this->expect_facade_deprecations(
			'get_enabled_currencies',
			'get_available_currencies',
			'has_additional_currencies_enabled',
			'get_store_currencies',
			'get_single_currency_settings',
			'get_all_customer_currencies',
			'validate_currency_code',
			'get_cached_currencies'
		);

		$this->assertSame( $state->get_enabled_currencies(), $facade->get_enabled_currencies(), 'get_enabled_currencies' );
		$this->assertSame( array( 'USD', 'EUR', 'GBP' ), array_keys( $facade->get_enabled_currencies() ), 'get_enabled_currencies keys' );
		$this->assertSame( $state->get_available_currencies(), $facade->get_available_currencies(), 'get_available_currencies' );
		$this->assertTrue( $facade->has_additional_currencies_enabled(), 'has_additional_currencies_enabled' );
		$this->assertSame(
			array(
				'available' => $state->get_available_currencies(),
				'enabled'   => $state->get_enabled_currencies(),
				'default'   => $state->get_default_currency(),
			),
			$facade->get_store_currencies(),
			'get_store_currencies'
		);
		$this->assertSame(
			array(
				'exchange_rate_type' => 'manual',
				'manual_rate'        => '0.9',
				'price_rounding'     => null,
				'price_charm'        => null,
			),
			$facade->get_single_currency_settings( 'eur' ),
			'get_single_currency_settings'
		);
		$this->assertSame( 'EUR', $facade->validate_currency_code( 'eur' ), 'validate_currency_code' );
		$this->assertFalse( $facade->validate_currency_code( 'jpy' ), 'validate_currency_code for a currency the store does not offer' );

		update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 'eur', 'usd' ) );
		$this->assertSame( array( 'EUR', 'USD' ), $facade->get_all_customer_currencies(), 'get_all_customer_currencies' );

		$this->assertNull( $facade->get_cached_currencies(), 'get_cached_currencies with nothing cached' );
		// The minimal shape the extension caches and reads back: the payload's `currencies` and `updated` (client 11.1.0
		// `includes/multi-currency/MultiCurrency.php:492-516`) inside the cache envelope's `data`, `fetched` and `errored`
		// (`includes/class-database-cache.php:377-382`, which also writes `consecutive_errors`, not read here); a forced read
		// returns `data` whatever its age (`:197-207`).
		$cached = array(
			'currencies' => array( 'EUR' => 0.91 ),
			'updated'    => 1761400000,
		);
		update_option(
			'wcpay_multi_currency_cached_currencies',
			array(
				'data'    => $cached,
				'fetched' => time(),
				'errored' => false,
			)
		);
		$this->assertSame( $cached, $facade->get_cached_currencies(), 'get_cached_currencies returns what is cached when no rate provider is connected' );

		$this->expectException( InvalidCurrencyException::class );
		$facade->get_single_currency_settings( 'jpy' );
	}

	/**
	 * @testdox The facade's settings and display reads answer with the module's settings.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_and_display_reads_answer_with_the_module_settings(): void {
		global $current_tab;

		$this->declare_facade();
		$facade = $this->get_facade();
		$this->expect_facade_deprecations(
			'get_settings',
			'get_public_config',
			'get_rendering_mode',
			'is_cache_optimized_mode',
			'is_using_auto_currency_switching',
			'is_using_storefront_switcher',
			'has_active_session',
			'get_apply_charm_only_to_products',
			'get_switcher_widget_markup',
			'is_multi_currency_settings_page'
		);

		$rest_settings = wc_get_container()->get( MultiCurrencyRestController::class )->get_settings()->get_data();
		$this->assertArrayNotHasKey( 'is_cache_optimized_feature_enabled', $rest_settings, 'Precondition: the module has no cache-optimized feature flag.' );
		// The extension's flag defaults on (client 11.1.0 `includes/class-wc-payments-features.php:418-420`).
		$this->assertSame( array_merge( $rest_settings, array( 'is_cache_optimized_feature_enabled' => true ) ), $facade->get_settings(), 'get_settings' );
		$this->assertSame( wc_get_container()->get( MultiCurrencyServiceFactory::class )->create_display_service()->get_public_config(), $facade->get_public_config(), 'get_public_config' );
		$this->assertSame( array( 'USD', 'EUR', 'GBP' ), array_keys( $facade->get_public_config()['currencies'] ), 'get_public_config currencies' );

		$this->assertSame( 'speed', $facade->get_rendering_mode(), 'get_rendering_mode' );
		$this->assertFalse( $facade->is_cache_optimized_mode(), 'is_cache_optimized_mode' );
		update_option( 'wcpay_multi_currency_rendering_mode', 'cache' );
		$this->assertSame( 'cache', $facade->get_rendering_mode(), 'get_rendering_mode after the merchant chose cache' );
		$this->assertTrue( $facade->is_cache_optimized_mode(), 'is_cache_optimized_mode after the merchant chose cache' );

		$this->assertFalse( $facade->is_using_auto_currency_switching(), 'is_using_auto_currency_switching' );
		$this->assertFalse( $facade->is_using_storefront_switcher(), 'is_using_storefront_switcher' );
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		$this->assertTrue( $facade->is_using_auto_currency_switching(), 'is_using_auto_currency_switching once on' );
		$this->assertTrue( $facade->is_using_storefront_switcher(), 'is_using_storefront_switcher once on' );
		$this->assertTrue( $facade->get_settings()['wcpay_multi_currency_enable_auto_currency'], 'get_settings follows the stored setting' );

		$this->assertFalse( $facade->has_active_session(), 'has_active_session without a session' );
		wp_set_current_user( self::factory()->user->create() );
		WC()->session = new \WC_Session_Handler();
		WC()->session->init();
		$this->assertTrue( $facade->has_active_session(), 'has_active_session for a signed-in customer' );

		$this->assertTrue( $facade->get_apply_charm_only_to_products(), 'get_apply_charm_only_to_products' );
		add_filter( 'wcpay_multi_currency_apply_charm_only_to_products', '__return_false' );
		$this->assertFalse( $facade->get_apply_charm_only_to_products(), 'get_apply_charm_only_to_products follows its filter' );

		$widget_controller = wc_get_container()->get( MultiCurrencySwitcherWidgetController::class );
		$widget_controller->handle_widgets_init();
		$markup = $facade->get_switcher_widget_markup( array( 'symbol' => false ) );
		$this->assertSame( $widget_controller->get_switcher_widget_markup( array( 'symbol' => false ) ), $markup, 'get_switcher_widget_markup' );
		$this->assertStringContainsString( 'value="EUR"', $markup, 'get_switcher_widget_markup offers the enabled currencies' );

		$this->assertFalse( $facade->is_multi_currency_settings_page(), 'is_multi_currency_settings_page on the front end' );
		set_current_screen( 'woocommerce_page_wc-settings' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The settings tab WooCommerce's settings page sets.
		$current_tab = 'wcpay_multi_currency';
		$this->assertTrue( $facade->is_multi_currency_settings_page(), 'is_multi_currency_settings_page on the Multi-currency tab' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Another settings tab.
		$current_tab = 'general';
		$this->assertFalse( $facade->is_multi_currency_settings_page(), 'is_multi_currency_settings_page on another tab' );
	}

	/**
	 * @testdox The facade rounds an amount in the selected currency, with its charm unless the caller asks not to.
	 *
	 * Client 11.1.0 `includes/multi-currency/MultiCurrency.php:1632-1634` and `:1657-1685`: a 1.00 rounding step ceils
	 * 10.20 to 11.00, and a -0.01 charm makes it 10.99.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_adjusts_an_amount_for_the_selected_currency(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );
		update_option( 'wcpay_multi_currency_price_charm_eur', '-0.01' );
		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		$this->expect_facade_deprecations( 'adjust_amount_for_selected_currency' );
		$facade = $this->get_facade();

		$this->assertSame( 10.99, $facade->adjust_amount_for_selected_currency( 10.2 ) );
		$this->assertSame( 11.0, $facade->adjust_amount_for_selected_currency( 10.2, false ) );
	}

	/**
	 * @testdox Dev Tools' clear notes action deletes the Multi-Currency inbox note through the facade and leaves other notes.
	 *
	 * Client 11.1.0 `includes/multi-currency/MultiCurrency.php:1152-1156` deletes the notes named
	 * `wc-payments-notes-multi-currency-available` (`Notes/NoteMultiCurrencyAvailable.php:25`).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dev_tools_clears_the_multi_currency_note(): void {
		$this->declare_facade();
		$this->expect_facade_deprecations( 'remove_woo_admin_notes' );
		foreach ( array( 'wc-payments-notes-multi-currency-available', 'wc-admin-another-note' ) as $name ) {
			$note = new Note();
			$note->set_name( $name );
			$note->set_title( 'Title' );
			$note->set_content( 'Content' );
			$note->save();
		}

		$this->assertTrue( ( new MultiCurrencyFacadePluginCallersFixture() )->dev_tools_clear_notes() );

		$this->assertFalse( Notes::get_note_by_name( 'wc-payments-notes-multi-currency-available' ) );
		$this->assertInstanceOf( Note::class, Notes::get_note_by_name( 'wc-admin-another-note' ) );
	}

	/**
	 * @testdox Themify Product Filter converts its price range only while currency switching is on and another currency is enabled.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_themify_reads_the_compatibility_predicate_and_converts_its_price_range(): void {
		$this->declare_facade();
		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		$this->expect_facade_deprecations( 'instance', 'get_compatibility', 'get_enabled_currencies', 'get_selected_currency', 'get_default_currency', 'get_raw_conversion' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\Compatibility::should_disable_currency_switching' );
		$fixture = new MultiCurrencyFacadePluginCallersFixture();

		$this->assertTrue( $fixture->themify_multi_price_enabled() );
		$price = $fixture->themify_filter_by_price(
			array(
				'from' => 90.0,
				'to'   => 180.0,
			)
		);
		$this->assertEqualsWithDelta( 100.0, $price['from'], 0.000001, 'EUR 90 at the 0.9 rate is USD 100.' );
		$this->assertEqualsWithDelta( 200.0, $price['to'], 0.000001 );

		$_GET['pay_for_order'] = 'true';
		$this->assertFalse( $fixture->themify_multi_price_enabled(), 'Currency switching is off on the order-pay page.' );
		unset( $_GET['pay_for_order'] );
	}

	/**
	 * @testdox get_compatibility() returns one Compatibility facade whose seven predicates answer by the module's rules.
	 *
	 * Client 11.1.0 `includes/multi-currency/Compatibility.php:82-237`: each predicate's default and the filter that changes it.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_compatibility_predicates_answer_by_the_module_rules(): void {
		$this->declare_facade();
		$this->expect_facade_deprecations( 'get_compatibility' );
		foreach ( array( 'override_selected_currency', 'should_disable_currency_switching', 'should_convert_coupon_amount', 'should_convert_product_price', 'should_convert_shipping_amount', 'should_return_store_currency' ) as $predicate ) {
			$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\Compatibility::' . $predicate );
		}
		$this->setExpectedDeprecated( 'should_hide_widgets' );
		$facade        = $this->get_facade();
		$compatibility = $facade->get_compatibility();
		$product       = \WC_Helper_Product::create_simple_product();
		$coupon        = new \WC_Coupon();
		$shipping      = new \WC_Shipping_Flat_Rate();

		$this->assertInstanceOf( 'WCPay\\MultiCurrency\\Compatibility', $compatibility );
		$this->assertSame( $compatibility, $facade->get_compatibility(), 'One instance per request.' );

		$this->assertFalse( $compatibility->override_selected_currency(), 'override_selected_currency' );
		$this->assertFalse( $compatibility->should_disable_currency_switching(), 'should_disable_currency_switching' );
		$this->assertFalse( $compatibility->should_hide_widgets(), 'should_hide_widgets' );
		$this->assertTrue( $compatibility->should_convert_coupon_amount(), 'should_convert_coupon_amount without a coupon' );
		$this->assertTrue( $compatibility->should_convert_coupon_amount( $coupon ), 'should_convert_coupon_amount' );
		$this->assertTrue( $compatibility->should_convert_product_price(), 'should_convert_product_price without a product' );
		$this->assertTrue( $compatibility->should_convert_product_price( $product ), 'should_convert_product_price' );
		$this->assertTrue( $compatibility->should_convert_shipping_amount(), 'should_convert_shipping_amount without a method' );
		$this->assertTrue( $compatibility->should_convert_shipping_amount( $shipping ), 'should_convert_shipping_amount' );
		$this->assertFalse( $compatibility->should_return_store_currency(), 'should_return_store_currency' );

		$filtered_shipping_method = null;
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR' );
		add_filter( 'wcpay_multi_currency_should_disable_currency_switching', '__return_true' );
		add_filter( 'wcpay_multi_currency_should_convert_coupon_amount', '__return_false' );
		add_filter( 'wcpay_multi_currency_should_convert_product_price', '__return_false' );
		add_filter(
			'wcpay_multi_currency_should_convert_shipping_amount',
			static function ( $convert, $shipping_method ) use ( &$filtered_shipping_method ) {
				unset( $convert );
				$filtered_shipping_method = $shipping_method;

				return false;
			},
			10,
			2
		);
		add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );

		$this->assertSame( 'EUR', $compatibility->override_selected_currency(), 'override_selected_currency filtered' );
		$this->assertTrue( $compatibility->should_disable_currency_switching(), 'should_disable_currency_switching filtered' );
		$this->assertTrue( $compatibility->should_hide_widgets(), 'should_hide_widgets filtered' );
		$this->assertFalse( $compatibility->should_convert_coupon_amount( $coupon ), 'should_convert_coupon_amount filtered' );
		$this->assertTrue( $compatibility->should_convert_coupon_amount(), 'should_convert_coupon_amount without a coupon ignores the filter' );
		$this->assertFalse( $compatibility->should_convert_product_price( $product ), 'should_convert_product_price filtered' );
		$this->assertFalse( $compatibility->should_convert_shipping_amount( $shipping ), 'should_convert_shipping_amount filtered' );
		$this->assertSame( $shipping, $filtered_shipping_method, 'The shipping filter receives the method.' );
		$this->assertTrue( $compatibility->should_convert_shipping_amount(), 'should_convert_shipping_amount without a method ignores the filter' );
		$this->assertTrue( $compatibility->should_return_store_currency(), 'should_return_store_currency filtered' );
	}

	/**
	 * @testdox The currencies the facade returns answer every member of the extension's Currency class it reads.
	 *
	 * Client 11.1.0 `includes/multi-currency/Currency.php:115-272`; the EUR flag from `CountryFlags.php:87` and `:287-300`.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_returned_currencies_answer_the_extension_currency_members(): void {
		$this->declare_facade();
		$this->expect_facade_deprecations( 'get_enabled_currencies' );
		$eur = $this->get_facade()->get_enabled_currencies()['EUR'];

		$this->assertSame( 'EUR', $eur->get_code(), 'get_code' );
		$this->assertSame( 'eur', $eur->get_id(), 'get_id' );
		$this->assertSame( '🇪🇺', $eur->get_flag(), 'get_flag' );
		$this->assertFalse( $eur->get_is_default(), 'get_is_default' );
		$this->assertSame( 'Euro', $eur->get_name(), 'get_name' );
		$this->assertSame( 0.9, $eur->get_rate(), 'get_rate' );
		$this->assertSame( 0.0, $eur->get_charm(), 'get_charm' );
		$this->assertSame( '1.00', $eur->get_rounding(), 'get_rounding' );
		$this->assertSame( '&euro;', $eur->get_symbol(), 'get_symbol' );
		$this->assertSame( ( new MultiCurrencyLocalizationService() )->get_currency_format( 'EUR' )['currency_pos'], $eur->get_symbol_position(), 'get_symbol_position' );
		$this->assertFalse( $eur->get_is_zero_decimal(), 'get_is_zero_decimal' );
		$this->assertNull( $eur->get_last_updated(), 'get_last_updated' );
		$this->assertSame( '🇪🇺', $eur->jsonSerialize()['flag'], 'jsonSerialize' );
		$this->assertSame( '€', $eur->jsonSerialize()['symbol'], 'jsonSerialize decodes the symbol' );

		$eur->set_charm( -0.01 );
		$eur->set_rate( 0.95 );
		$eur->set_rounding( '0.50' );
		$eur->set_last_updated( 1761400000 );
		$this->assertSame( -0.01, $eur->get_charm(), 'set_charm' );
		$this->assertSame( 0.95, $eur->get_rate(), 'set_rate' );
		$this->assertSame( '0.50', $eur->get_rounding(), 'set_rounding' );
		$this->assertSame( 1761400000, $eur->get_last_updated(), 'set_last_updated' );
	}

	/**
	 * @testdox The facades declare none of the members the PR's compatibility notes list as not answered.
	 *
	 * Calling one is the undefined-method error a caller of the extension's internals gets; the list is the contract.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facades_leave_the_listed_members_undeclared(): void {
		$this->declare_facade();
		$not_declared = array(
			'WCPay\\MultiCurrency\\MultiCurrency' => array( '__construct', 'init_hooks', 'init', 'init_rest_api', 'init_widgets', 'init_settings_pages', 'enqueue_admin_scripts', 'add_props_to_wcpay_js_config', 'add_woo_admin_notes', 'maybe_update_customer_currencies_option', 'update_selected_currency_by_url', 'update_selected_currency_by_geolocation', 'maybe_auto_enable_cache_rendering_mode', 'display_geolocation_currency_update_notice', 'set_new_customer_currency_meta', 'add_order_meta_on_refund', 'recalculate_cart', 'possible_simulation_activation', 'register_script_with_dependencies', 'get_file_version', 'get_frontend_prices', 'get_frontend_currencies', 'get_storefront_integration', 'get_currency_switcher_widget', 'set_enabled_currencies', 'update_selected_currency', 'update_single_currency_settings', 'update_settings', 'is_simulation_enabled', 'get_multi_currency_onboarding_simulation_variables', 'get_backend_formatted_wc_price', 'is_initialized' ),
			'WCPay\\MultiCurrency\\Compatibility' => array( 'init', 'init_compatibility_classes', 'get_compatibility_classes', 'attach_order_modifier', 'convert_order_prices' ),
			MultiCurrencyCurrency::class          => array( 'get_currency_name_from_code' ),
		);

		$this->assertCount( 32, $not_declared['WCPay\\MultiCurrency\\MultiCurrency'] );
		foreach ( $not_declared as $class => $members ) {
			foreach ( $members as $member ) {
				$this->assertFalse( method_exists( $class, $member ), $class . '::' . $member );
			}
		}
		$this->assertFalse( class_exists( 'WCPay\\MultiCurrency\\Compatibility\\BaseCompatibility' ), 'The compatibility facade has no extension base class.' );
		$this->assertFalse( function_exists( 'WC_Payments_Multi_Currency' ), 'WC_Payments_Multi_Currency() stays undefined.' );
	}

	/**
	 * @testdox Every facade member's deprecation notice names WooCommerce 11.0.0 and no replacement.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deprecation_notices_name_no_replacement(): void {
		$this->declare_facade();
		$notices = array();
		add_action(
			'deprecated_function_run',
			static function ( $function_name, $replacement, $version ) use ( &$notices ) {
				$notices[ $function_name ] = array( $replacement, $version );
			},
			10,
			3
		);
		$this->expect_facade_deprecations( 'get_price', 'get_raw_conversion', 'get_selected_currency', 'get_default_currency', 'get_enabled_currencies', 'get_compatibility', 'remove_woo_admin_notes' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\Compatibility::should_convert_shipping_amount' );

		$facade = $this->get_facade();
		$facade->get_price( 10.0, 'product' );
		$facade->get_raw_conversion( 10.0, 'EUR' );
		$facade->get_selected_currency();
		$facade->get_default_currency();
		$facade->get_enabled_currencies();
		$facade->get_compatibility()->should_convert_shipping_amount();
		\WCPay\MultiCurrency\MultiCurrency::remove_woo_admin_notes();

		$this->assertCount( 9, $notices );
		foreach ( $notices as $function_name => $notice ) {
			$this->assertSame( array( '', '11.0.0' ), $notice, $function_name );
		}
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

	/**
	 * Get the facade instance, as callers do.
	 *
	 * @return \WCPay\MultiCurrency\MultiCurrency
	 */
	private function get_facade() {
		$this->expect_facade_deprecations( 'instance' );

		return \WCPay\MultiCurrency\MultiCurrency::instance();
	}

	/**
	 * Expect the deprecation notice each named facade member emits.
	 *
	 * @param string ...$members Member names.
	 */
	private function expect_facade_deprecations( string ...$members ): void {
		foreach ( $members as $member ) {
			$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::' . $member );
		}
	}
}
