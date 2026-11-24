<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\WooCommerceNameYourPrice;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\IntegrationsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidgetController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\LocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Tests\Internal\Compatibility\WooPaymentsCompatibilityNoticeTestTrait;
use Automattic\WooCommerce\Utilities\MultiCurrencyUtil;
use WC_Unit_Test_Case;

require_once __DIR__ . '/Fixtures/multi-currency-facade-plugin-callers.inc';

/**
 * Tests the members of the `WCPay\MultiCurrency\MultiCurrency` facade on a store where WooCommerce runs Multi-Currency,
 * through the calls the plugins that use them make.
 *
 * PHP cannot undeclare the facade classes, so the class declares them once and they stay declared for the rest of the
 * process, as on a store; the facade's singleton stays too, and reads every service from the container on each call.
 */
class WooPaymentsExtensionMultiCurrencyFacadeTest extends WC_Unit_Test_Case {

	use WooPaymentsCompatibilityNoticeTestTrait;

	/**
	 * @testdox Product Add-Ons reverses the posted shopper price before tax and display convert it again.
	 */
	public function test_addons_converts_the_posted_price_once(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.88' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR' );
		$base = 44.0;
		if ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
			$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
			$this->expect_facade_warnings( 'is_initialized', 'get_raw_conversion', 'get_default_currency', 'get_selected_currency' );
			$module = WC_Payments_Multi_Currency();
			if ( $module->is_initialized() ) {
				$base = $module->get_raw_conversion( $base, $module->get_default_currency()->get_code(), $module->get_selected_currency()->get_code() );
			}
		}
		$this->assertSame( 44.0, MultiCurrencyUtil::convert_price( $base ) );
		$this->assertSame( 49.0, MultiCurrencyUtil::convert_price( $base * 1.1 ) );
	}

	/**
	 * @testdox Name Your Price converts suggested, minimum and maximum prices once when its own integration runs.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_name_your_price_limits_use_one_conversion(): void {
		$this->declare_facade();
		$this->load_name_your_price_fixture();
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.88' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR' );
		wc_get_container()->get( WooCommerceNameYourPrice::class )->register();
		$this->assertTrue( function_exists( 'WC_Payments_Multi_Currency' ) );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$this->expect_facade_warnings( 'get_price' );
		foreach ( array( 'minimum', 'suggested', 'maximum' ) as $limit ) {
			add_filter( 'wc_nyp_raw_' . $limit . '_price', static fn( $price ) => WC_Payments_Multi_Currency()->get_price( $price, 'product' ) );
		}
		$minimum = apply_filters( 'wc_nyp_raw_minimum_price', 25.0 );
		$this->assertSame( 22.0, $minimum );
		$this->assertSame( 44.0, apply_filters( 'wc_nyp_raw_suggested_price', 50.0 ) );
		$this->assertSame( 88.0, apply_filters( 'wc_nyp_raw_maximum_price', 100.0 ) );
	}

	/**
	 * @testdox Session restore removes the unavailable EUR price and keeps a line convertible to held EUR.
	 * @testWith [false]
	 *           [true]
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool $held Whether EUR is held as the selected currency.
	 */
	public function test_name_your_price_session_after_disabling_eur( bool $held ): void {
		$this->prepare_name_your_price_cart( $held );
		$product = \WC_Helper_Product::create_simple_product();
		WC()->session->set(
			'cart',
			array(
				'nyp' => array(
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'quantity'     => 1,
					'nyp'          => 15.0,
					'nyp_original' => 15.0,
					'nyp_currency' => $held ? 'USD' : 'EUR',
				),
			)
		);

		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		( new \WC_Cart_Session( WC()->cart ) )->get_cart_from_session();

		$lines = WC()->cart->get_cart();
		if ( $held ) {
			$this->assertArrayHasKey( 'nyp', $lines );
			$this->assertSame( 13.5, $lines['nyp']['nyp'] );
			$this->assertSame( 15.0, $lines['nyp']['nyp_original'] );
		} else {
			$this->assertArrayNotHasKey( 'nyp', $lines );
			$this->assertStringContainsString( $product->get_name(), wc_get_notices( 'error' )[0]['notice'] );
		}
	}

	/**
	 * @testdox Order again skips only the unconvertible NYP line and accepts a conversion to held EUR.
	 * @testWith [false]
	 *           [true]
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool $held Whether EUR is held as the selected currency.
	 */
	public function test_name_your_price_reorder_after_disabling_eur( bool $held ): void {
		$this->prepare_name_your_price_cart( $held );
		$customer = \WC_Helper_Customer::create_customer();
		wp_set_current_user( $customer->get_id() );
		$product  = \WC_Helper_Product::create_simple_product();
		$ordinary = \WC_Helper_Product::create_simple_product();
		$order    = new \WC_Order();
		$order->set_customer_id( $customer->get_id() );
		$order->set_currency( $held ? 'USD' : 'EUR' );
		$order->stage_product_item( $product )->add_meta_data( '_wc_nyp_price', '15.00' );
		$order->stage_product_item( $ordinary );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		$populate = new \ReflectionMethod( \WC_Cart_Session::class, 'populate_cart_from_order' );
		$populate->setAccessible( true );
		$cart  = $populate->invoke( new \WC_Cart_Session( WC()->cart ), $order->get_id(), array() );
		$lines = array_column( $cart, null, 'product_id' );
		$this->assertArrayHasKey( $ordinary->get_id(), $lines );
		if ( $held ) {
			$this->assertArrayHasKey( $product->get_id(), $lines );
			$this->assertSame( 13.5, $lines[ $product->get_id() ]['nyp'] );
			$this->assertSame( 15.0, $lines[ $product->get_id() ]['nyp_original'] );
		} else {
			$this->assertArrayNotHasKey( $product->get_id(), $lines );
		}
	}

	/**
	 * @testdox A fee uses price rounding and an invoice uses the raw rate, including for a held renewal currency.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $held Whether the subscription holds a currency no longer enabled.
	 */
	public function test_accessor_preserves_fee_and_raw_amounts( bool $held ): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_enabled_currencies', $held ? array( 'GBP' ) : array( 'EUR' ) );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.88' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function () use ( $held ) {
				if ( $held ) {
					StateBuilder::set_order_currency_override( 'EUR' );
				}
				return 'EUR';
			}
		);
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$this->expect_facade_warnings( 'get_price', 'get_raw_conversion' );
		$facade = WC_Payments_Multi_Currency();
		$this->assertSame( 11.0, $facade->get_price( 12.34, 'product' ) );
		$this->assertSame( 11.0, MultiCurrencyUtil::convert_price( 12.34, 'product' ) );
		$this->assertEqualsWithDelta( 4.40, $facade->get_raw_conversion( 5, 'EUR' ), 0.00001 );
		$this->assertEqualsWithDelta( 4.40, MultiCurrencyUtil::convert( 5, 'EUR' ), 0.00001 );
	}

	/**
	 * @testdox Affiliate records the store-currency basis and commission for a shopper-currency order.
	 */
	public function test_affiliate_converts_the_order_to_store_currency(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.88' );
		$amount = 44.0;
		if ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
			$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
			$this->expect_facade_warnings( 'get_raw_conversion', 'get_default_currency' );
			$facade = WC_Payments_Multi_Currency();
			$code   = $facade->get_default_currency()->get_code();
			$this->assertSame( 'USD', $code );
			$amount = $facade->get_raw_conversion( $amount, $code, 'EUR' );
		}
		$this->assertEqualsWithDelta( 50.0, $amount, 0.00001 );
		$this->assertEqualsWithDelta( 5.0, $amount * 0.1, 0.00001 );
	}

	/**
	 * @testdox The helper distinguishes admin REST from shopper and storefront requests.
	 * @testWith ["/wp-json/wc/v3/products", true, true]
	 *           ["/wp-json/wc/store/v1/cart", true, false]
	 *           ["/shop/", true, false]
	 *           ["/wp-json/wc/v3/products", false, false]
	 *
	 * @param string $uri      Request path.
	 * @param bool   $admin    Whether an admin screen referred the request.
	 * @param bool   $expected Whether the plugin should use admin currency formatting.
	 */
	public function test_marketplace_request_context( string $uri, bool $admin, bool $expected ): void {
		$this->declare_facade();
		$request = $_SERVER;
		try {
			$_SERVER['REQUEST_URI'] = $uri;
			RequestContext::set_store_api_address( (bool) WC()->is_store_api_request() );
			if ( $admin ) {
				$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php' );
			} else {
				unset( $_SERVER['HTTP_REFERER'] );
			}
			$this->setExpectedIncorrectUsage( 'WCPay\MultiCurrency\Utils::is_admin_api_request()' );
			$this->assertSame( $expected, \WCPay\MultiCurrency\Utils::is_admin_api_request() );
		} finally {
			$_SERVER = $request;
			RequestContext::set_store_api_address( false );
		}
	}

	/**
	 * @testdox An invoice keeps its original amount after catching the specific failed conversion.
	 * @testWith [false, true]
	 *           [true, true]
	 *           [false, false]
	 *           [true, false]
	 *
	 * @param bool $invalid_rate Whether the source rate is unusable.
	 * @param bool $use_function Whether the caller uses the global or the existing singleton.
	 */
	public function test_invoice_fallback_receives_the_matching_conversion_error( bool $invalid_rate, bool $use_function ): void {
		$this->declare_facade();
		$this->expect_facade_warnings( 'get_raw_conversion' );
		if ( $use_function ) {
			$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
			$module = WC_Payments_Multi_Currency();
		} else {
			$module = $this->get_facade();
		}
		if ( $invalid_rate ) {
			$this->expect_facade_warnings( 'get_enabled_currencies' );
			$module->get_enabled_currencies()['EUR']->set_rate( 0 );
		}
		$amount = 5.0;
		try {
			$amount = $module->get_raw_conversion( $amount, 'USD', $invalid_rate ? 'EUR' : 'BAD' );
			$this->fail( 'The invoice must enter its fallback.' );
		} catch ( \WCPay\MultiCurrency\Exceptions\InvalidCurrencyRateException $error ) {
			$this->assertTrue( $invalid_rate );
			$this->assertInstanceOf( \Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException::class, $error );
			$this->assertSame( 'Invalid source currency rate: 0', $error->getMessage() );
		} catch ( \WCPay\MultiCurrency\Exceptions\InvalidCurrencyException $error ) {
			$this->assertFalse( $invalid_rate );
			$this->assertInstanceOf( InvalidCurrencyException::class, $error );
			$this->assertSame( 'Currency is not enabled for conversion: BAD', $error->getMessage() );
		}
		$this->assertSame( 5.0, $amount );
	}

	/**
	 * @testdox A pricing plugin can remove the registered shipping callback and convert each discounted line once.
	 */
	public function test_discounted_lines_use_the_registered_price_controller(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.88' );
		$total = 80.0;
		if ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
			$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
			$this->expect_facade_warnings( 'is_initialized', 'get_frontend_prices', 'get_raw_conversion' );
			$facade = WC_Payments_Multi_Currency();
			if ( $facade->is_initialized() ) {
				$prices = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController::class );
				$prices->register();
				$this->assertTrue( remove_filter( 'woocommerce_shipping_method_add_rate_args', array( $facade->get_frontend_prices(), 'convert_shipping_method_rate_cost' ), 99 ) );
				$total = 2 * $facade->get_raw_conversion( 40.0, 'EUR' );
			}
		}
		$this->assertEqualsWithDelta( 70.40, $total, 0.00001 );
	}

	/**
	 * @testdox Both accessors share one facade and report only the public entry the plugin used.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_function_and_direct_singleton_report_separately(): void {
		$this->declare_facade();
		$this->assertTrue( function_exists( 'WC_Payments_Multi_Currency' ) );
		$calls = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $name, $message, $version ) use ( &$calls ) {
				$calls[] = array( $name, $message, $version );
				if ( 'WC_Payments_Multi_Currency()' === $name && 1 === count( $calls ) ) {
					WC_Payments_Multi_Currency();
				}
			},
			10,
			3
		);
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$module = WC_Payments_Multi_Currency();
		$this->assertSame( $module, WC_Payments_Multi_Currency() );
		$this->assertSame( array( array( 'WC_Payments_Multi_Currency()', 'WC_Payments_Multi_Currency() is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.', '11.4.0' ) ), $calls );
		$this->assertSame( $module, $this->get_facade() );
		$this->assertCount( 2, $calls );
		$this->assertFalse( is_callable( array( $module, 'get_adjusted_price' ) ) );
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'Call to undefined method WCPay\MultiCurrency\MultiCurrency::get_adjusted_price()' );
		$module->get_adjusted_price( 10, 'EUR' );
	}

	/**
	 * @testdox Marketplace formatters remain for an add-only consumer and detach at the end of a temporary display.
	 * @testWith ["post-purchase offer", false]
	 *           ["gift wrapper", true]
	 *           ["order bump", true]
	 *
	 * @param string $display Name of the consumer display.
	 * @param bool   $removes Whether the consumer removes the formatters after displaying a price.
	 */
	public function test_function_exposes_the_registered_formatters( string $display, bool $removes ): void {
		$this->declare_facade();
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$this->expect_facade_warnings( 'get_frontend_currencies' );
		$this->setExpectedIncorrectUsage( 'WCPay\MultiCurrency\Utils::is_admin_api_request()' );
		$this->assertFalse( ( new \WCPay\MultiCurrency\Utils() )->is_admin_api_request() );
		$module     = WC_Payments_Multi_Currency();
		$formatters = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController::class );
		$this->assertSame( $formatters, $module->get_frontend_currencies() );
		foreach ( array(
			'woocommerce_currency'            => array( 'get_woocommerce_currency', 'USD' ),
			'wc_get_price_decimals'           => array( 'get_price_decimals', 2 ),
			'wc_get_price_decimal_separator'  => array( 'get_price_decimal_separator', '.' ),
			'wc_get_price_thousand_separator' => array( 'get_price_thousand_separator', ',' ),
			'woocommerce_price_format'        => array( 'get_woocommerce_price_format', '%1$s%2$s' ),
		) as $hook => list( $method, $input ) ) {
			add_filter( $hook, array( $formatters, $method ), 900 );
			$this->assertSame( $formatters->$method( $input ), apply_filters( $hook, $input ) );
			$callback = array( $module->get_frontend_currencies(), $method );
			if ( $removes ) {
				$this->assertTrue( remove_filter( $hook, $callback, 900 ), $display );
				$this->assertFalse( has_filter( $hook, $callback ), $display );
			} else {
				$this->assertSame( 900, has_filter( $hook, $callback ), $display );
			}
		}
	}

	/**
	 * @testdox An invoice can catch an invalid currency using the extension name and retain its original amount.
	 */
	public function test_invoice_catches_invalid_currency_through_the_accessor(): void {
		$this->declare_facade();
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$this->expect_facade_warnings( 'get_single_currency_settings' );
		try {
			WC_Payments_Multi_Currency()->get_single_currency_settings( 'BAD' );
			$this->fail( 'Invalid settings should reach the invoice fallback.' );
		} catch ( \WCPay\MultiCurrency\Exceptions\InvalidCurrencyException $error ) {
			$this->assertInstanceOf( \Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException::class, $error );
			$this->assertSame( 'Invalid currency passed to get_single_currency_settings: BAD', $error->getMessage() );
			$this->assertSame( 500, $error->getCode() );
		}
	}

	/**
	 * The session a case may replace.
	 *
	 * @var \WC_Session
	 */
	private $session;

	/**
	 * The widgets registered before a case runs.
	 *
	 * @var array<string,object>
	 */
	private array $widgets = array();

	/**
	 * The request cookies before a case runs.
	 *
	 * @var array<string,mixed>
	 */
	private array $cookies = array();

	/**
	 * Declare the facade as the loader does at `plugins_loaded` while WooCommerce runs Multi-Currency.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$loader = new WooPaymentsExtensionFacadeLoader();
		$loader->init(
			new class() extends MultiCurrencyRuntimeArbiter {
				/**
				 * WooCommerce runs Multi-Currency.
				 *
				 * @return string
				 */
				public function get_runtime_owner(): string {
					return self::OWNER_BUILTIN;
				}
			}
		);
		$loader->load();
	}

	/**
	 * Keep the session, the request cookies and the registered widgets a case may change.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_compatibility_notices( \WCPay\MultiCurrency\MultiCurrency::class, \WCPay\MultiCurrency\Compatibility::class, \WCPay\MultiCurrency\Utils::class, \Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionFacadeAccessor::class );

		global $wp_widget_factory;
		$this->session = WC()->session;
		$this->cookies = $_COOKIE;
		$this->widgets = $wp_widget_factory->widgets;
	}

	/**
	 * Restore the session, the cookies, the user, the screen, the settings tab, the widgets, the query argument and the services a case changes.
	 */
	public function tearDown(): void {
		global $wp_widget_factory, $current_tab;

		WC()->session               = $this->session;
		$_COOKIE                    = $this->cookies;
		$wp_widget_factory->widgets = $this->widgets;
		$current_tab                = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the settings tab a case set.
		unset( $_GET['pay_for_order'] );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		wc_get_container()->reset_all_resolved();
		parent::tearDown();
	}

	/**
	 * @testdox The facade's constants hold the WooPayments extension's values.
	 *
	 * Values from client 11.1.0 `includes/multi-currency/MultiCurrency.php:29-49`.
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
	 */
	public function test_advanced_dynamic_pricing_reaches_the_module_hook_through_the_prefix(): void {
		$this->declare_facade();
		$product    = \WC_Helper_Product::create_simple_product();
		$controller = wc_get_container()->get( IntegrationsController::class );
		$this->assertTrue( $controller->should_convert_product_price( $product ), 'Precondition: the module converts the product price.' );

		( new MultiCurrencyFacadePluginCallersFixture() )->adp_stop_product_price_conversion();

		$this->assertFalse( $controller->should_convert_product_price( $product ) );
	}

	/**
	 * @testdox FOX Currency Switcher finds the enabled currencies and the store currency through the facade.
	 *
	 * The calls after FOX's guard; the guard's `WC_Payments_Features::is_customer_multi_currency_enabled()` belongs to the
	 * `WC_Payments_Features` facade.
	 */
	public function test_fox_reads_the_enabled_and_store_currencies(): void {
		$this->declare_facade();
		$fixture = new MultiCurrencyFacadePluginCallersFixture();
		$this->expect_facade_warnings( 'instance', 'get_enabled_currencies', 'get_default_currency' );

		$this->assertSame( 'Option: Multi-currency of WooPayments plugin by Woo.', $fixture->fox_incompatible_plugin_notice( 'USD' ), 'Two more currencies are enabled.' );

		update_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$this->assertSame( '', $fixture->fox_incompatible_plugin_notice( 'USD' ), 'Only the store currency is enabled, and it is FOX\'s default.' );
	}

	/**
	 * @testdox The facade's currency reads answer with the module's state.
	 */
	public function test_currency_reads_answer_with_the_module_state(): void {
		$this->declare_facade();
		$facade = $this->get_facade();
		$state  = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build();
		$this->expect_facade_warnings(
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
	 * @testdox The facade says Multi-Currency runs and gives the shopper's own selection, or null while nothing is selected.
	 *
	 * Client 11.2.0 `includes/multi-currency/MultiCurrency.php:272-278,876-899`.
	 */
	public function test_answers_whether_multi_currency_runs_and_the_explicit_selection(): void {
		$this->declare_facade();
		$facade = $this->get_facade();
		$this->expect_facade_warnings( 'is_enabled', 'get_explicit_selected_currency' );

		$this->assertTrue( \WCPay\MultiCurrency\MultiCurrency::is_enabled() );
		$this->assertNull( $facade->get_explicit_selected_currency(), 'Nothing is selected.' );

		$customer_id = self::factory()->user->create();
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		wp_set_current_user( $customer_id );
		$this->assertSame( 'EUR', $facade->get_explicit_selected_currency()->get_code() );
	}

	/**
	 * @testdox The facade's settings and display reads answer with the module's settings.
	 */
	public function test_settings_and_display_reads_answer_with_the_module_settings(): void {
		global $current_tab;

		$this->declare_facade();
		$facade = $this->get_facade();
		$this->expect_facade_warnings(
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
		$this->assertSame( wc_get_container()->get( ServiceFactory::class )->create_display_service()->get_public_config(), $facade->get_public_config(), 'get_public_config' );
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

		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] );
		WC()->session = new \WC_Session_Handler();
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
	 * @testdox Price conversion uses the requested currency and keeps the selected currency as the default.
	 *
	 * @testWith ["product", 9.95, 7.49]
	 *           ["shipping", 10.0, 7.5]
	 *           ["tax", 9.15, 7.42]
	 *           ["coupon", 9.15, 7.42]
	 *           ["exchange_rate", 9.15, 7.42]
	 *
	 * @param string $type           Price type.
	 * @param float  $selected_price Expected price in EUR.
	 * @param float  $explicit_price Expected price in GBP.
	 */
	public function test_get_price_with_an_explicit_currency( string $type, float $selected_price, float $explicit_price ): void {
		$this->declare_facade();
		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );
		update_option( 'wcpay_multi_currency_price_charm_eur', '-0.05' );
		update_option( 'wcpay_multi_currency_price_rounding_gbp', '0.50' );
		update_option( 'wcpay_multi_currency_price_charm_gbp', '-0.01' );

		$this->expect_facade_warnings( 'get_price', 'get_enabled_currencies' );
		$facade = $this->get_facade();
		$gbp    = $facade->get_enabled_currencies()['GBP'];

		$this->assertSame( $explicit_price, MultiCurrencyUtil::convert_price( 10.17, $type, 'gbp' ) );
		$this->assertSame( $selected_price, MultiCurrencyUtil::convert_price( 10.17, $type ) );
		$this->assertSame( $explicit_price, $facade->get_price( 10.17, $type, $gbp ), 'An explicit target uses GBP.' );
		$this->assertSame( $selected_price, $facade->get_price( 10.17, $type ), 'An omitted target uses EUR.' );
		$this->assertSame( $selected_price, $facade->get_price( 10.17, $type, null ), 'A null target uses EUR.' );
	}

	/**
	 * @testdox Both public conversions accept the same store-currency amounts as the compatibility price method.
	 *
	 * @testWith [""]
	 *           [null]
	 *           ["12.34"]
	 *           ["unpriced"]
	 *           ["12.34 USD"]
	 *           [12.34]
	 *
	 * @param mixed $amount Store price passed by a plugin.
	 */
	public function test_public_conversions_accept_compatibility_price_inputs( $amount ): void {
		$this->declare_facade();
		$this->expect_facade_warnings( 'get_price' );
		$expected = $this->get_facade()->get_price( $amount, 'product' );

		$this->assertSame( $expected, MultiCurrencyUtil::convert_price( $amount ) );
		$this->assertSame( $expected, MultiCurrencyUtil::convert( $amount ) );
	}

	/**
	 * @testdox A price with a numeric prefix follows compatibility rounding in a foreign currency.
	 */
	public function test_public_price_preserves_compatibility_rounding_for_a_numeric_prefix(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_manual_rate_eur', '1.1' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );

		$this->expect_facade_warnings( 'get_price', 'get_enabled_currencies' );
		$facade   = $this->get_facade();
		$currency = $facade->get_enabled_currencies()['EUR'];
		$expected = $facade->get_price( '50 USD', 'product', $currency );

		$this->assertSame( $expected, MultiCurrencyUtil::convert_price( '50 USD', 'product', 'EUR' ) );
	}

	/**
	 * @testdox The public replacements use the rate of a selected order currency after the merchant disables it.
	 */
	public function test_public_replacements_match_a_held_order_currency(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function () {
				StateBuilder::set_order_currency_override( 'GBP' );
				return 'GBP';
			}
		);

		$facade = $this->get_facade();
		$this->expect_facade_warnings( 'get_selected_currency', 'is_enabled', 'get_raw_conversion', 'get_price' );
		$held = $facade->get_selected_currency();
		$this->assertSame( 'GBP', $held->get_code() );
		$this->assertSame( 0.73, $held->get_rate() );
		$this->assertArrayNotHasKey( 'GBP', MultiCurrencyUtil::get_enabled_currencies() );

		$this->assertSame( $facade::is_enabled(), MultiCurrencyUtil::is_active() );
		$this->assertSame( $facade->get_raw_conversion( 10.17, 'gbp', 'USD' ), MultiCurrencyUtil::convert( 10.17, 'gbp', 'USD' ) );
		$this->assertSame( $facade->get_raw_conversion( 10.17, 'USD', 'gbp' ), MultiCurrencyUtil::convert( 10.17, 'USD', 'gbp' ) );
		$this->assertSame( $facade->get_price( 10.17, 'tax', $held ), MultiCurrencyUtil::convert_price( 10.17, 'tax', 'gbp' ) );
		$this->assertSame( $facade->get_price( 10.17, 'tax' ), MultiCurrencyUtil::convert_price( 10.17, 'tax' ) );
	}

	/**
	 * @testdox The first public read builds the same currency state that compatibility callers see.
	 */
	public function test_public_utility_matches_compatibility_reads_and_raw_conversion(): void {
		$this->declare_facade();
		$customer_id = self::factory()->user->create();
		$this->assertIsInt( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		wp_set_current_user( $customer_id );

		$this->reset_container_resolutions();
		$rates = MultiCurrencyUtil::get_enabled_currencies();
		$this->assertSame(
			array(
				'USD' => 1.0,
				'EUR' => 0.9,
				'GBP' => 0.73,
			),
			$rates
		);

		$facade = $this->get_facade();
		$this->expect_facade_warnings( 'is_enabled', 'get_default_currency', 'get_enabled_currencies', 'has_additional_currencies_enabled', 'get_raw_conversion' );
		$this->assertSame( $facade::is_enabled(), MultiCurrencyUtil::is_active() );
		$this->assertSame( $facade->get_default_currency()->get_code(), MultiCurrencyUtil::get_store_currency() );
		$this->assertSame( $facade->has_additional_currencies_enabled(), count( $rates ) > 1 );
		foreach ( $facade->get_enabled_currencies() as $code => $currency ) {
			$this->assertSame( $currency->get_rate(), $rates[ $code ] );
		}

		$this->assertSame( $facade->get_raw_conversion( 10.17, 'EUR' ), MultiCurrencyUtil::convert( 10.17 ) );
		$this->assertSame( $facade->get_raw_conversion( 10.17, 'GBP', 'EUR' ), MultiCurrencyUtil::convert( 10.17, 'gbp', 'eur' ) );

		update_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$this->assertTrue( MultiCurrencyUtil::is_active() );
		$this->assertSame( array( 'USD' => 1.0 ), MultiCurrencyUtil::get_enabled_currencies() );
		$this->assertSame( $facade->has_additional_currencies_enabled(), count( MultiCurrencyUtil::get_enabled_currencies() ) > 1 );
	}

	/**
	 * @testdox The facade rounds an amount in the selected currency, with its charm unless the caller asks not to.
	 *
	 * Client 11.1.0 `includes/multi-currency/MultiCurrency.php:1632-1634` and `:1657-1685`: a 1.00 rounding step ceils
	 * 10.20 to 11.00, and a -0.01 charm makes it 10.99.
	 */
	public function test_adjusts_an_amount_for_the_selected_currency(): void {
		$this->declare_facade();
		update_option( 'wcpay_multi_currency_price_rounding_eur', '1.00' );
		update_option( 'wcpay_multi_currency_price_charm_eur', '-0.01' );
		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		$this->expect_facade_warnings( 'adjust_amount_for_selected_currency' );
		$facade = $this->get_facade();

		$this->assertSame( 10.99, $facade->adjust_amount_for_selected_currency( 10.2 ) );
		$this->assertSame( 11.0, $facade->adjust_amount_for_selected_currency( 10.2, false ) );

		$converted = MultiCurrencyUtil::convert( 10.2 );
		$this->assertSame( 9.99, MultiCurrencyUtil::convert_price( 10.2 ) );
		$this->assertSame( $facade->adjust_amount_for_selected_currency( $converted ), MultiCurrencyUtil::convert_price( 10.2 ) );
	}

	/**
	 * @testdox Dev Tools' clear notes action deletes the Multi-Currency inbox note through the facade and leaves other notes.
	 *
	 * Client 11.1.0 `includes/multi-currency/MultiCurrency.php:1152-1156` deletes the notes named
	 * `wc-payments-notes-multi-currency-available` (`Notes/NoteMultiCurrencyAvailable.php:25`).
	 */
	public function test_dev_tools_clears_the_multi_currency_note(): void {
		$this->declare_facade();
		$this->expect_facade_warnings( 'remove_woo_admin_notes' );
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
	 */
	public function test_themify_reads_the_compatibility_predicate_and_converts_its_price_range(): void {
		$this->declare_facade();
		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		update_user_meta( $customer_id, 'wcpay_currency', 'EUR' );
		$this->expect_facade_warnings( 'instance', 'get_compatibility', 'get_enabled_currencies', 'get_selected_currency', 'get_default_currency', 'get_raw_conversion' );
		$this->setExpectedIncorrectUsage( 'WCPay\\MultiCurrency\\Compatibility::should_disable_currency_switching()' );
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
	 * @testdox get_compatibility() returns one Compatibility facade whose eight predicates answer by the module's rules.
	 *
	 * Client 11.1.0 `includes/multi-currency/Compatibility.php:82-237`: each predicate's default and the filter that changes it;
	 * client 11.3.0 `includes/multi-currency/Compatibility.php:178-193` for the coupon spend limits.
	 */
	public function test_compatibility_predicates_answer_by_the_module_rules(): void {
		$this->declare_facade();
		$this->expect_facade_warnings( 'get_compatibility' );
		foreach ( array( 'override_selected_currency', 'should_disable_currency_switching', 'should_hide_widgets', 'should_convert_coupon_amount', 'should_convert_coupon_min_max_amount', 'should_convert_product_price', 'should_convert_shipping_amount', 'should_return_store_currency' ) as $predicate ) {
			$this->setExpectedIncorrectUsage( 'WCPay\\MultiCurrency\\Compatibility::' . $predicate . '()' );
		}
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
		$this->assertTrue( $compatibility->should_convert_coupon_min_max_amount(), 'should_convert_coupon_min_max_amount without a coupon' );
		$this->assertTrue( $compatibility->should_convert_coupon_min_max_amount( $coupon ), 'should_convert_coupon_min_max_amount' );
		$this->assertTrue( $compatibility->should_convert_product_price(), 'should_convert_product_price without a product' );
		$this->assertTrue( $compatibility->should_convert_product_price( $product ), 'should_convert_product_price' );
		$this->assertTrue( $compatibility->should_convert_shipping_amount(), 'should_convert_shipping_amount without a method' );
		$this->assertTrue( $compatibility->should_convert_shipping_amount( $shipping ), 'should_convert_shipping_amount' );
		$this->assertFalse( $compatibility->should_return_store_currency(), 'should_return_store_currency' );

		$filtered_shipping_method = null;
		$filtered_coupon          = null;
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR' );
		add_filter( 'wcpay_multi_currency_should_disable_currency_switching', '__return_true' );
		add_filter( 'wcpay_multi_currency_should_convert_coupon_amount', '__return_false' );
		add_filter(
			'wcpay_multi_currency_should_convert_coupon_min_max_amount',
			static function ( $convert, $filtered ) use ( &$filtered_coupon ) {
				unset( $convert );
				$filtered_coupon = $filtered;

				return false;
			},
			10,
			2
		);
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
		$this->assertFalse( $compatibility->should_convert_coupon_min_max_amount( $coupon ), 'should_convert_coupon_min_max_amount filtered' );
		$this->assertSame( $coupon, $filtered_coupon, 'The spend limit filter receives the coupon.' );
		$this->assertTrue( $compatibility->should_convert_coupon_min_max_amount(), 'should_convert_coupon_min_max_amount without a coupon ignores the filter' );
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
	 */
	public function test_returned_currencies_answer_the_extension_currency_members(): void {
		$this->declare_facade();
		$this->expect_facade_warnings( 'get_enabled_currencies' );
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
		$this->assertSame( ( new LocalizationService() )->get_currency_format( 'EUR' )['currency_pos'], $eur->get_symbol_position(), 'get_symbol_position' );
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
	 */
	public function test_facades_leave_the_listed_members_undeclared(): void {
		$this->declare_facade();
		$not_declared = array(
			'WCPay\\MultiCurrency\\MultiCurrency' => array( '__construct', 'init_hooks', 'init', 'init_rest_api', 'init_widgets', 'init_settings_pages', 'enqueue_admin_scripts', 'add_props_to_wcpay_js_config', 'add_woo_admin_notes', 'maybe_update_customer_currencies_option', 'update_selected_currency_by_url', 'update_selected_currency_by_geolocation', 'maybe_auto_enable_cache_rendering_mode', 'display_geolocation_currency_update_notice', 'set_new_customer_currency_meta', 'add_order_meta_on_refund', 'recalculate_cart', 'possible_simulation_activation', 'register_script_with_dependencies', 'get_file_version', 'get_storefront_integration', 'get_currency_switcher_widget', 'set_enabled_currencies', 'update_selected_currency', 'update_single_currency_settings', 'update_settings', 'is_simulation_enabled', 'get_multi_currency_onboarding_simulation_variables', 'get_backend_formatted_wc_price', 'get_adjusted_price' ),
			'WCPay\\MultiCurrency\\Compatibility' => array( 'init', 'init_compatibility_classes', 'get_compatibility_classes', 'attach_order_modifier', 'convert_order_prices' ),
			MultiCurrencyCurrency::class          => array( 'get_currency_name_from_code' ),
		);

		$this->assertCount( 30, $not_declared['WCPay\\MultiCurrency\\MultiCurrency'] );
		foreach ( $not_declared as $class => $members ) {
			foreach ( $members as $member ) {
				$this->assertFalse( method_exists( $class, $member ), $class . '::' . $member );
			}
		}
		$this->assertFalse( class_exists( 'WCPay\\MultiCurrency\\Compatibility\\BaseCompatibility' ), 'The compatibility facade has no extension base class.' );
	}

	/**
	 * @testdox REST and AJAX requests report incorrect usage without writing to the PHP error log.
	 * @testWith ["/wp-json/wc/store/v1/cart"]
	 *           ["/wp-admin/admin-ajax.php"]
	 *           ["/?wc-ajax=update_order_review"]
	 *
	 * @param string $path Request path.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_compatibility_warnings_leave_the_error_log_empty( string $path ): void {
		if ( WP_DEBUG ) {
			$this->markTestSkipped( 'This test requires WP_DEBUG to be off.' );
		}

		$gateway                = wc_get_container()->get( WooPaymentsGateway::class );
		$_SERVER['REQUEST_URI'] = $path;
		if ( false !== strpos( $path, 'ajax' ) ) {
			wc_maybe_define_constant( 'DOING_AJAX', true );
		}
		$this->assertTrue( WC()->is_rest_api_request() || wp_doing_ajax() );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$this->setExpectedIncorrectUsage( WooPaymentsGateway::class . '::is_enabled()' );
		$this->setExpectedIncorrectUsage( 'WCPay\MultiCurrency\MultiCurrency::is_enabled()' );

		$error_log = tempnam( sys_get_temp_dir(), 'wc-compat-' );
		$this->assertIsString( $error_log );
		try {
			$this->iniSet( 'error_log', $error_log );
			$gateway->is_enabled();
			WC_Payments_Multi_Currency();
			WC_Payments_Multi_Currency();
			\WCPay\MultiCurrency\MultiCurrency::is_enabled();

			$this->assertStringEqualsFile( $error_log, '' );
		} finally {
			wp_delete_file( $error_log );
		}
	}

	/**
	 * @testdox Each method warns once per request and identifies its replacement or removal.
	 */
	public function test_each_member_warns_once_with_its_replacement_or_removal(): void {
		$this->declare_facade();
		$notices = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $function_name, $message, $version ) use ( &$notices ) {
				$notices[ $function_name ][] = array( $message, $version );
				if ( 'WCPay\\MultiCurrency\\MultiCurrency::is_enabled()' === $function_name && 1 === count( $notices[ $function_name ] ) ) {
					\WCPay\MultiCurrency\MultiCurrency::is_enabled();
				}
			},
			10,
			3
		);
		$this->expect_facade_warnings( 'get_price', 'get_raw_conversion', 'get_selected_currency', 'get_explicit_selected_currency', 'get_default_currency', 'get_enabled_currencies', 'get_compatibility', 'remove_woo_admin_notes', 'is_enabled', 'is_initialized', 'get_frontend_prices', 'get_frontend_currencies' );
		$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
		$this->setExpectedIncorrectUsage( 'WCPay\MultiCurrency\Utils::is_admin_api_request()' );
		$this->setExpectedIncorrectUsage( 'WCPay\\MultiCurrency\\Compatibility::should_convert_shipping_amount()' );

		$facade = $this->get_facade();
		$facade->get_price( 10.0, 'product' );
		$facade->get_raw_conversion( 10.0, 'EUR' );
		$facade->get_selected_currency();
		$facade->get_explicit_selected_currency();
		$facade->get_default_currency();
		$facade->get_enabled_currencies();
		$facade->get_compatibility()->should_convert_shipping_amount();
		\WCPay\MultiCurrency\MultiCurrency::remove_woo_admin_notes();
		for ( $i = 0; $i < 20; ++$i ) {
			WC_Payments_Multi_Currency()->is_initialized();
			$facade->get_frontend_prices();
			$facade->get_frontend_currencies();
			\WCPay\MultiCurrency\Utils::is_admin_api_request();
			$this->assertTrue( \WCPay\MultiCurrency\MultiCurrency::is_enabled() );
		}

		$replacements = array(
			'WCPay\\MultiCurrency\\MultiCurrency::is_initialized()'                 => 'Use Automattic\WooCommerce\Utilities\MultiCurrencyUtil::is_active() instead.',
			'WCPay\\MultiCurrency\\MultiCurrency::get_price()'                      => 'Use Automattic\WooCommerce\Utilities\MultiCurrencyUtil::convert_price() instead.',
			'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion()'             => 'Use Automattic\WooCommerce\Utilities\MultiCurrencyUtil::convert() instead.',
			'WCPay\\MultiCurrency\\MultiCurrency::is_enabled()'                     => 'Use Automattic\WooCommerce\Utilities\MultiCurrencyUtil::is_active() instead.',
			'WCPay\\MultiCurrency\\MultiCurrency::remove_woo_admin_notes()'         => "Use Automattic\\WooCommerce\\Admin\\Notes\\Notes::delete_notes_with_name( 'wc-payments-notes-multi-currency-available' ) instead.",
			'WCPay\\MultiCurrency\\Compatibility::should_convert_shipping_amount()' => "Use ! \$shipping_method || (bool) apply_filters( 'wcpay_multi_currency_should_convert_shipping_amount', true, \$shipping_method ) instead.",
		);
		$this->assertCount( 16, $notices );
		foreach ( $notices as $member => $warnings ) {
			$message = $replacements[ $member ] ?? "$member is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.";
			$this->assertSame( array( array( esc_html( $message ), '11.4.0' ) ), $warnings, $member );
		}
	}

	/** Load the Name Your Price singleton in this test's isolated PHP process. */
	private function load_name_your_price_fixture(): void {
		$singleton = require dirname( __DIR__ ) . '/Integrations/Fixtures/name-your-price-cart.inc';
		$this->assertIsObject( $singleton );
		$GLOBALS['wc_name_your_price'] = $singleton;
		class_alias( get_class( $singleton ), 'WC_Name_Your_Price' );
	}

	/**
	 * Model the Name Your Price 4.0.1 cart callbacks with their real priorities and the WooPayments facade.
	 *
	 * @param bool $held Whether an order holds the disabled EUR currency.
	 */
	private function prepare_name_your_price_cart( bool $held ): void {
		$this->declare_facade();
		$this->load_name_your_price_fixture();
		wc_get_container()->get( WooCommerceNameYourPrice::class )->register();
		if ( $held ) {
			add_filter(
				'wcpay_multi_currency_override_selected_currency',
				static function () {
					StateBuilder::set_order_currency_override( 'EUR' );
					return 'EUR';
				}
			);
		}
		$convert = function ( $amount, $from ) {
			$this->setExpectedIncorrectUsage( 'WC_Payments_Multi_Currency()' );
			$this->expect_facade_warnings( 'get_selected_currency', 'get_raw_conversion' );
			$facade = WC_Payments_Multi_Currency();
			$to     = $facade->get_selected_currency()->get_code();
			return $to === $from ? $amount : $facade->get_raw_conversion( $amount, $to, $from );
		};
		add_filter(
			'woocommerce_order_again_cart_item_data',
			static function ( $line, $item ) {
				$price = $item->get_meta( '_wc_nyp_price' );
				return is_numeric( $price ) ? array( 'nyp' => (float) $price ) + $line : $line;
			},
			5,
			2
		);
		add_filter(
			'woocommerce_order_again_cart_item_data',
			static function ( $line, $item, $order ) use ( $convert ) {
				if ( isset( $line['nyp'] ) && is_numeric( $line['nyp'] ) ) {
					$line['nyp_currency'] = $order->get_currency();
					$line['nyp_original'] = $line['nyp'];
					$line['nyp']          = $convert( $line['nyp'], $line['nyp_currency'] );
				}
				return $line;
			},
			10,
			3
		);
		add_filter(
			'woocommerce_get_cart_item_from_session',
			static function ( $line ) use ( $convert ) {
				if ( isset( $line['nyp_original'], $line['nyp_currency'] ) ) {
					$line['nyp'] = $convert( $line['nyp_original'], $line['nyp_currency'] );
					$line['data']->set_price( $line['nyp'] );
				}
				return $line;
			},
			20
		);
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
		wc_get_container()->get( WooPaymentsExtensionFacadeLoader::class )->load();
		$this->assertTrue( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'Precondition: the facade is declared.' );
	}

	/**
	 * Get the facade instance, as callers do.
	 *
	 * @return \WCPay\MultiCurrency\MultiCurrency
	 */
	private function get_facade() {
		$this->expect_facade_warnings( 'instance' );

		return \WCPay\MultiCurrency\MultiCurrency::instance();
	}

	/**
	 * Expect the incorrect usage notice for each named facade method.
	 *
	 * @param string ...$members Member names.
	 */
	private function expect_facade_warnings( string ...$members ): void {
		foreach ( $members as $member ) {
			$this->setExpectedIncorrectUsage( 'WCPay\\MultiCurrency\\MultiCurrency::' . $member . '()' );
		}
	}
}
