<?php
/**
 * MultiCurrencyBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyExplicitPriceController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use WC_Helper_Order;
use WC_Unit_Test_Case;

/** Tests for MultiCurrencyBootstrap. */
class MultiCurrencyBootstrapTest extends WC_Unit_Test_Case {

	/** All roots formerly registered by the core-owned bootstrap. */
	private const CORE_ROOTS = array(
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyStoreCurrencyLifecycleController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyFrontendCurrenciesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyFrontendPricesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySelectedCurrencyController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAnalyticsController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyBookingsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyDepositsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyPreOrdersCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyUpsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyFedExCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyPointsRewardsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyNameYourPriceCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyProductAddOnsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySubscriptionsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyExplicitPriceController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySwitcherWidgetController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySwitcherBlockController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySettingsController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyStorefrontIntegrationController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAsyncPriceRendererController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyRestController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyRestRequestOverrideController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyTrackingController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAdminNoticesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAdminNoteController',
	);

	/** Start each case without the options the Multi-Currency handover reads and writes. */
	public function setUp(): void {
		parent::setUp();
		$this->delete_handover_options();
	}

	/** Clear the WP_CLI override the CLI case sets, and the services and screen a booted request leaves behind. */
	public function tearDown(): void {
		$this->delete_handover_options();
		Constants::clear_single_constant( 'WP_CLI' );
		set_current_screen( 'front' );
		wc_get_container()->reset_all_resolved();
		parent::tearDown();
	}

	/** @testdox Should arm the Multi-Currency handover on a request the WooPayments plugin owns. */
	public function test_plugin_owned_request_arms_the_handover(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );

		$this->assertSame( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_EXTENSION, get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION ) );
		$this->assertArrayHasKey( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, wp_load_alloptions(), 'Every request reads the marker, so it is autoloaded.' );
	}

	/** @testdox Should hand the plugin's Multi-Currency state over on the first native-owned request, before the arbiter reads the option. */
	public function test_first_native_request_hands_over_before_the_arbiter_reads(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		// EUR set up in the plugin (client 11.1.0 `includes/multi-currency/MultiCurrency.php:767-783`); a Features page save stored "no"
		// while the plugin owned payments.
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );

		$container = $this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'yes', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
		$this->assertSame( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_BUILTIN, get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION ) );
		$this->assertSame( 'yes', $container->get( MultiCurrencyRuntimeArbiter::class )->feature_option_reads[0], 'The arbiter must read the handed-over value.' );
	}

	/** @testdox Should keep a merchant choice made under native ownership on later requests. */
	public function test_later_native_request_keeps_the_merchant_choice(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );

		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'no', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/** @testdox Should hand over again after the plugin is reactivated and native takes over a second time. */
	public function test_reactivation_rearms_the_handover(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );

		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'yes', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/** @testdox Should mark a store native on its first native-owned request without a marker, leaving the option unset without plugin data. */
	public function test_first_native_request_without_marker_marks_the_store_native(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_BUILTIN, get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION ), 'Later requests then read the marker from the autoloaded options.' );
		$this->assertArrayHasKey( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, wp_load_alloptions() );
		$this->assertFalse( get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, false ) );
	}

	/** @testdox Should apply the upgrade seed on a first native-owned request without a marker, never over a stored choice. */
	public function test_first_native_request_without_marker_applies_the_upgrade_seed(): void {
		// A network site that got no request between the upgrade and a network deactivation: its upgrade seed never ran. The plugin stores
		// a list of currency codes (client 11.1.0 `includes/multi-currency/MultiCurrency.php:767-783`).
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'yes', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );

		delete_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'no', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ), 'The seed adds the option only when it is unset.' );
	}

	/** @testdox Should write nothing when no payments runtime owns the site. */
	public function test_no_payments_owner_writes_nothing(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_NONE );

		$this->assertFalse( get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, false ) );
		$this->assertFalse( get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, false ) );
	}

	/** @testdox Should count a completed plugin setup as plugin use at the switch. */
	public function test_completed_setup_counts_as_plugin_use_at_the_switch(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		// The plugin saves true when its setup task finishes (client 11.1.0 `includes/multi-currency/client/setup/tasks/setup-complete-task/index.js:32`).
		update_option( 'wcpay_multi_currency_setup_completed', '1' );

		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'yes', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/** @testdox Should retain core roots when no provider roots are configured. */
	public function test_empty_provider_resolver_keeps_core_registration_providerless(): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );

		$sut->register( $container, '__return_false' );

		$this->assertSame( self::core_root_matrix()['configured front'][3], $container->registered );
	}

	/** @testdox Should resolve only the ownership arbiter when the runtime has no owner. */
	public function test_owner_none_resolves_only_the_arbiter(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);
		add_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );

		try {
			$sut->register( $container, '__return_false' );
		} finally {
			remove_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );
		}

		$this->assertSame( array( MultiCurrencyRuntimeArbiter::class ), $container->resolved );
		$this->assertSame( 0, $resolver_calls );
		$this->assertFalse( has_filter( 'woocommerce_cart_total' ) );
	}

	/** @testdox Should order and de-duplicate provider roots before core roots. */
	public function test_core_registration_orders_and_deduplicates_provider_roots_before_core_roots(): void {
		$provider_roots = array( 'ProviderRoot', self::CORE_ROOTS[0], 'ProviderRoot' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$provider_roots, &$resolver_calls ): array {
				++$resolver_calls;
				return $provider_roots;
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( array_merge( array( 'ProviderRoot' ), self::core_root_matrix()['configured front'][3] ), $container->registered );
		$this->assertSame( array_values( array_unique( $container->registered ) ), $container->registered );
		$this->assertSame( 1, $resolver_calls );
	}

	/** @testdox Should order provider roots before an enabled plugin shadow root. */
	public function test_plugin_shadow_registers_provider_roots_before_shadow(): void {
		add_filter( MultiCurrencyShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( array( 'ProviderRoot', MultiCurrencyShadowMode::class ), $container->registered );
		$this->assertSame( 1, $resolver_calls );
	}

	/** @testdox Should not resolve provider roots when plugin shadow mode is disabled. */
	public function test_plugin_owner_with_disabled_shadow_does_not_resolve_provider_roots(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/** @testdox Should not resolve provider roots for an empty front request. */
	public function test_empty_front_request_does_not_resolve_provider_roots(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
		$this->assertFalse( has_filter( 'woocommerce_cart_total' ) );
		$this->assertFalse( has_filter( 'woocommerce_get_formatted_order_total' ) );
		$this->assertFalse( has_action( 'woocommerce_admin_order_totals_after_tax' ) );
		$this->assertFalse( has_action( 'woocommerce_admin_order_totals_after_total' ) );
	}

	/**
	 * @testdox Should apply an explicit price filter added after WooCommerce loaded on a core-owned single-currency front request.
	 */
	public function test_core_owned_single_currency_front_request_registers_explicit_price_filter_without_provider_roots(): void {
		$controller     = $this->create_explicit_price_controller( false );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false, $controller );
		$resolver_calls = 0;
		$defaults       = array();
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);
		$sut->register( $container, '__return_false' );
		// Added after WooCommerce loaded, as a theme or a later plugin does; the client applies it at render
		// (client 11.1.0 `includes/class-wc-payments-explicit-price-formatter.php:55-73`).
		add_filter(
			'wcpay_multi_currency_should_output_explicit_price',
			static function ( bool $current_default ) use ( &$defaults ): bool {
				$defaults[] = $current_default;
				return true;
			}
		);

		$this->assertSame( '$10.30 USD', apply_filters( 'woocommerce_cart_total', '$10.30' ) );
		$this->assertSame( array( false ), $defaults );
		$this->assertSame( 0, $container->foreign_currency_order_checks );
		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
		$this->assertSame( 100, has_filter( 'woocommerce_cart_total', array( $controller, 'get_explicit_price' ) ) );
		$this->assertNotContains( 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Services\\MultiCurrencyStateBuilderFactory', $container->resolved );
	}

	/**
	 * @testdox Should retain provider roots, and the explicit-price controller, for an empty core-owned REST request without the public filter.
	 */
	public function test_empty_core_owned_rest_request_retains_provider_roots_without_the_public_filter(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_true' );

		$this->assertSame( 1, $resolver_calls );
		$this->assertSame( array( 'ProviderRoot', self::CORE_ROOTS[21], MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/** @testdox Should append the existing controller to empty REST roots when the public filter is attached before bootstrap. */
	public function test_empty_core_owned_rest_request_appends_the_existing_controller_when_the_public_filter_is_attached(): void {
		$controller     = $this->create_explicit_price_controller( false );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false, $controller );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);
		add_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );

		try {
			$sut->register( $container, '__return_true' );
		} finally {
			remove_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );
		}

		$this->assertSame( 1, $resolver_calls );
		$this->assertSame( array( 'ProviderRoot', self::CORE_ROOTS[21], MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/** @testdox Should not resolve provider roots for an empty AJAX request. */
	public function test_empty_ajax_request_does_not_resolve_provider_roots(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		try {
			$sut->register( $container, '__return_true' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/**
	 * @testdox Should treat an empty Action Scheduler request as cron before AJAX, REST, and admin.
	 */
	public function test_empty_action_scheduler_request_precedes_ajax_rest_and_admin_without_resolving_provider_roots(): void {
		$_REQUEST['action'] = 'as_async_request_queue_runner';
		add_filter( 'wp_doing_ajax', '__return_true' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		try {
			$sut->register( $container, '__return_true' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/**
	 * @testdox Should not resolve provider roots for an empty CLI request.
	 */
	public function test_empty_cli_request_does_not_resolve_provider_roots(): void {
		Constants::set_constant( 'WP_CLI', true );

		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_true' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/**
	 * @testdox Should select the exact core root matrix for each persisted-data tier and request class.
	 *
	 * @dataProvider core_root_matrix
	 *
	 * @param bool              $configured Whether an additional currency is configured.
	 * @param bool              $historical Whether historical Multi-Currency data exists.
	 * @param string            $request    Request class.
	 * @param array<int,string> $expected   Expected roots.
	 */
	public function test_selects_exact_core_root_matrix( bool $configured, bool $historical, string $request, array $expected ): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $configured, $historical );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );
		$this->assertTrue( method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) );
		if ( ! method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) ) {
			return;
		}
		$method = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'get_core_roots' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( $sut, $container, $request ) );
	}

	/** @return array<string,array{bool,bool,string,array<int,string>}> */
	public static function core_root_matrix(): array {
		$base       = array( self::CORE_ROOTS[0] );
		$price      = array( self::CORE_ROOTS[1], self::CORE_ROOTS[2], self::CORE_ROOTS[3] );
		$compat     = array_slice( self::CORE_ROOTS, 5, 11 );
		$storefront = array( self::CORE_ROOTS[16], self::CORE_ROOTS[17], self::CORE_ROOTS[19], self::CORE_ROOTS[20] );
		$history    = array( self::CORE_ROOTS[4], self::CORE_ROOTS[23] );
		$settings   = self::CORE_ROOTS[18];
		$rest       = self::CORE_ROOTS[21];
		$override   = self::CORE_ROOTS[22];
		$admin      = array( self::CORE_ROOTS[24], self::CORE_ROOTS[25] );
		// Outside the configured tier the explicit-price controller registers on every request, so a late filter still applies.
		$explicit = array( self::CORE_ROOTS[15] );
		return array(
			'configured front' => array( true, false, 'front', array_merge( $base, $price, $compat, $storefront ) ),
			'configured ajax'  => array( true, false, 'ajax', array_merge( $base, $price, $compat, array( self::CORE_ROOTS[19], self::CORE_ROOTS[20] ), $history ) ),
			'configured rest'  => array( true, false, 'rest', array_merge( $base, $price, $compat, array( self::CORE_ROOTS[17], self::CORE_ROOTS[19], self::CORE_ROOTS[20] ), $history, array( $rest, $override ) ) ),
			'configured admin' => array( true, false, 'admin', array_merge( $base, $price, $compat, array( self::CORE_ROOTS[4], self::CORE_ROOTS[17], $settings, self::CORE_ROOTS[23] ), $admin ) ),
			'configured cron'  => array( true, false, 'cron', array_merge( $base, array( self::CORE_ROOTS[2] ), $compat, $history ) ),
			'configured cli'   => array( true, false, 'cli', array_merge( $base, array( self::CORE_ROOTS[2] ), $compat, $history ) ),
			'historical admin' => array( false, true, 'admin', array_merge( array_merge( $base, array( self::CORE_ROOTS[4], $settings, self::CORE_ROOTS[23] ), $admin ), $explicit ) ),
			'historical rest'  => array( false, true, 'rest', array_merge( array_merge( $base, array( self::CORE_ROOTS[4], $rest, $override, self::CORE_ROOTS[23] ) ), $explicit ) ),
			'historical cron'  => array( false, true, 'cron', array_merge( array_merge( $base, $history ), $explicit ) ),
			'historical front' => array( false, true, 'front', $explicit ),
			'historical ajax'  => array( false, true, 'ajax', $explicit ),
			'historical cli'   => array( false, true, 'cli', $explicit ),
			'empty admin'      => array( false, false, 'admin', array_merge( array( $settings, self::CORE_ROOTS[25] ), $explicit ) ),
			'empty rest'       => array( false, false, 'rest', array_merge( array( $rest ), $explicit ) ),
			'empty front'      => array( false, false, 'front', $explicit ),
			'empty ajax'       => array( false, false, 'ajax', $explicit ),
			'empty cron'       => array( false, false, 'cron', $explicit ),
			'empty cli'        => array( false, false, 'cli', $explicit ),
		);
	}

	/**
	 * @testdox Should retain historical roots when historical-order detection fails.
	 *
	 * @dataProvider historical_detection_failure_data
	 *
	 * @param string            $request  Request class.
	 * @param array<int,string> $expected Expected roots.
	 */
	public function test_retains_historical_roots_when_historical_order_detection_fails( string $request, array $expected ): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, true );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );
		$method    = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'get_core_roots' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( $sut, $container, $request ) );
		$this->assertSame( 1, $container->foreign_currency_order_checks, $request . ' must check historical orders once.' );
	}

	/** @return array<string,array{string,array<int,string>}> */
	public static function historical_detection_failure_data(): array {
		$matrix = self::core_root_matrix();

		return array(
			'admin' => array( 'admin', $matrix['historical admin'][3] ),
			'rest'  => array( 'rest', $matrix['historical rest'][3] ),
			'cron'  => array( 'cron', $matrix['historical cron'][3] ),
		);
	}

	/** @testdox Should avoid historical storage checks for no-config front, AJAX, and CLI requests. */
	public function test_no_config_front_ajax_and_cli_skip_historical_order_detection(): void {
		foreach ( array( 'front', 'ajax', 'cli' ) as $request ) {
			$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, true );
			$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );
			$this->assertTrue( method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) );
			if ( ! method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) ) {
				return;
			}
			$method = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'get_core_roots' );
			$method->setAccessible( true );

			$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $method->invoke( $sut, $container, $request ) );
			$this->assertSame( 0, $container->foreign_currency_order_checks, $request . ' must return before querying order storage.' );
		}
	}

	/** @testdox Should retain every former core root in the configured request-matrix union. */
	public function test_configured_matrix_inventory_is_the_former_core_root_list_without_duplicates(): void {
		$roots = array();
		foreach ( self::core_root_matrix() as $case ) {
			if ( $case[0] ) {
				$roots = array_merge( $roots, $case[3] );
			}
		}

		$actual   = array_values( array_unique( $roots ) );
		$expected = self::CORE_ROOTS;
		sort( $actual );
		sort( $expected );

		$this->assertSame( $expected, $actual );
	}

	/** @testdox Should classify CLI, cron, AJAX, REST, admin and front signals in that precedence order. */
	public function test_classifies_request_signals_with_required_precedence(): void {
		$classifier = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'classify_signals' );
		$classifier->setAccessible( true );
		$cases = array(
			'CLI'            => array( array( true, false, false, false, false ), 'cli' ),
			'cron'           => array( array( false, true, false, false, false ), 'cron' ),
			'AJAX'           => array( array( false, false, true, false, false ), 'ajax' ),
			'REST'           => array( array( false, false, false, true, false ), 'rest' ),
			'admin'          => array( array( false, false, false, false, true ), 'admin' ),
			'front'          => array( array( false, false, false, false, false ), 'front' ),
			'CLI collision'  => array( array( true, true, true, true, true ), 'cli' ),
			'cron collision' => array( array( false, true, true, true, true ), 'cron' ),
			'AJAX collision' => array( array( false, false, true, true, true ), 'ajax' ),
			'REST collision' => array( array( false, false, false, true, true ), 'rest' ),
		);

		foreach ( $cases as $label => list( $signals, $expected ) ) {
			$this->assertSame( $expected, $classifier->invokeArgs( null, $signals ), $label );
		}
	}

	/**
	 * @testdox A $request request on a $tier store registers the cron roots it lacks once, when Action Scheduler first runs an action in it.
	 * @dataProvider page_request_cron_root_gaps
	 *
	 * @param string            $tier            Persisted data tier: configured, historical or empty.
	 * @param string            $request         Request class.
	 * @param array<int,string> $load_roots      Roots the request registers when WooCommerce loads.
	 * @param array<int,string> $on_demand_roots Roots the first action registers, in cron order.
	 */
	public function test_page_request_registers_its_missing_cron_roots_once_when_an_action_runs( string $tier, string $request, array $load_roots, array $on_demand_roots ): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'configured' === $tier, 'historical' === $tier );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array( 'ProviderRoot' ) );

		$this->register_as( $sut, $container, $request );

		$this->assertSame( $load_roots, $container->registered, 'Loading must register only the request roots.' );
		$this->assertSame( 0, $container->foreign_currency_order_checks, 'Loading must not query order history.' );
		do_action( 'action_scheduler_before_execute', 1, 'WP Cron' );
		do_action( 'action_scheduler_before_execute', 2, 'WP Cron' );
		$this->assertSame( array_merge( $load_roots, $on_demand_roots ), $container->registered, 'The first action must register the missing cron roots, once.' );
		$this->assertSame( 'configured' === $tier ? 0 : 1, $container->foreign_currency_order_checks, 'Only a store without currencies checks order history, and only once.' );
	}

	/** @return array<string,array{string,string,array<int,string>,array<int,string>}> */
	public static function page_request_cron_root_gaps(): array {
		$matrix          = self::core_root_matrix();
		$history         = array( self::CORE_ROOTS[4], self::CORE_ROOTS[23] );
		$explicit        = array( self::CORE_ROOTS[15] );
		$historical_cron = array_merge( array( 'ProviderRoot' ), array_values( array_diff( $matrix['historical cron'][3], $explicit ) ) );

		return array(
			'configured front' => array( 'configured', 'front', array_merge( array( 'ProviderRoot' ), $matrix['configured front'][3] ), $history ),
			'historical front' => array( 'historical', 'front', $explicit, $historical_cron ),
			'historical ajax'  => array( 'historical', 'ajax', $explicit, $historical_cron ),
			'historical cli'   => array( 'historical', 'cli', $explicit, $historical_cron ),
			'empty front'      => array( 'empty', 'front', $explicit, array() ),
		);
	}

	/** @testdox Adds no Action Scheduler listener without core ownership, on cron requests, or when the request already has every cron root. */
	public function test_adds_no_action_scheduler_listener_when_no_cron_root_is_missing(): void {
		$cases = array(
			'no owner, configured front' => array( MultiCurrencyRuntimeArbiter::OWNER_NONE, 'configured', 'front' ),
			'plugin owner, front'        => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, 'configured', 'front' ),
			'configured cron'            => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'configured', 'cron' ),
			'configured cli'             => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'configured', 'cli' ),
			'configured ajax'            => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'configured', 'ajax' ),
			'configured rest'            => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'configured', 'rest' ),
			'configured admin'           => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'configured', 'admin' ),
			'historical cron'            => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'historical', 'cron' ),
			'historical rest'            => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'historical', 'rest' ),
			'historical admin'           => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'historical', 'admin' ),
			'empty rest'                 => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'empty', 'rest' ),
			'empty admin'                => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'empty', 'admin' ),
		);
		foreach ( $cases as $label => list( $owner, $tier, $request ) ) {
			$sut = new MultiCurrencyBootstrap( static fn(): array => array( 'ProviderRoot' ) );

			$this->register_as( $sut, $this->make_container( $owner, 'configured' === $tier, 'historical' === $tier ), $request );

			$this->assertFalse( has_action( 'action_scheduler_before_execute', array( $sut, 'handle_action_scheduler_before_execute' ) ), $label );
		}
	}

	/**
	 * @testdox $label: a refund copies the order's exchange-rate meta at the client's priority.
	 * @dataProvider refund_request_classes
	 *
	 * @param string $label      Case label.
	 * @param bool   $configured Whether an additional currency is configured; otherwise the store only has order history.
	 * @param string $request    Request class: front, ajax, rest, admin, cron or cli.
	 */
	public function test_refund_copies_order_exchange_rate_meta_on_every_request_class( string $label, bool $configured, string $request ): void {
		unset( $label );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_STRIPE_EXCHANGE_RATE, '0.49' );
		$order->save();
		$this->enable_core_multi_currency( $configured );
		$sut = new MultiCurrencyBootstrap( static fn(): array => array() );

		$refund_id = 0;

		// The refund is created while the request signals are set, as an admin-ajax or REST refund runs.
		$this->register_as(
			$sut,
			wc_get_container(),
			$request,
			function () use ( $sut, $order, &$refund_id ): void {
				// Client 11.1.0 registers the copy at priority 50 on every request (`includes/multi-currency/MultiCurrency.php:371`).
				$this->assertSame( 50, has_action( 'woocommerce_order_refunded', array( $sut, 'handle_woocommerce_order_refunded' ) ) );
				$refund_id = wc_create_refund(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 1,
					)
				)->get_id();
			}
		);

		$refund = wc_get_order( $refund_id );
		$this->assertSame( '0.5', $refund->get_meta( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE ) );
		$this->assertSame( 'USD', $refund->get_meta( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY ) );
		$this->assertSame( '0.49', $refund->get_meta( MultiCurrencyPriceProjectionService::META_KEY_STRIPE_EXCHANGE_RATE ) );
	}

	/** @return array<string,array{string,bool,string}> */
	public static function refund_request_classes(): array {
		return array(
			'configured front'     => array( 'configured front', true, 'front' ),
			'configured admin'     => array( 'configured admin', true, 'admin' ),
			'order history, ajax'  => array( 'order history, ajax', false, 'ajax' ),
			'order history, admin' => array( 'order history, admin', false, 'admin' ),
			'order history, rest'  => array( 'order history, rest', false, 'rest' ),
			'order history, cron'  => array( 'order history, cron', false, 'cron' ),
			'order history, cli'   => array( 'order history, cli', false, 'cli' ),
			'order history, front' => array( 'order history, front', false, 'front' ),
		);
	}

	/**
	 * @testdox $request: an order status change adds the order's currency to the stored customer currencies.
	 * @testWith ["front"]
	 *           ["cron"]
	 *
	 * @param string $request Request class.
	 */
	public function test_order_status_change_records_the_customer_currency( string $request ): void {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 'USD' ) );
		$orders = array();
		foreach ( array( 'EUR', 'GBP' ) as $currency ) {
			$order = WC_Helper_Order::create_order();
			$order->set_currency( $currency );
			$order->save();
			$orders[] = $order;
		}
		$this->enable_core_multi_currency( false );
		$sut = new MultiCurrencyBootstrap( static fn(): array => array() );

		// Client 11.1.0 registers this on every request and rereads the list each time (`includes/multi-currency/MultiCurrency.php:384`, `:700-717`, `:1562`).
		$this->register_as(
			$sut,
			wc_get_container(),
			$request,
			static function () use ( $orders ): void {
				foreach ( $orders as $order ) {
					$order->update_status( 'completed' );
				}
			}
		);

		$this->assertSame( array( 'USD', 'EUR', 'GBP' ), get_option( 'wcpay_multi_currency_stored_customer_currencies' ) );
	}

	/**
	 * @testdox An Analytics import run inside a $request request, as with woocommerce_analytics_disable_action_scheduling, converts the order.
	 * @testWith ["front"]
	 *           ["cli"]
	 *
	 * @param string $request Request class: front for ?wc-ajax=checkout, cli for an order import.
	 */
	public function test_synchronous_analytics_import_in_a_checkout_request_converts_the_order( string $request ): void {
		global $wpdb;
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
		$order->save();
		$order_id  = $order->get_id();
		$net_total = static function () use ( $wpdb, $order_id ): float {
			return (float) $wpdb->get_var( $wpdb->prepare( "SELECT net_total FROM {$wpdb->prefix}wc_order_stats WHERE order_id = %d", $order_id ) );
		};
		OrdersScheduler::import( $order_id );
		$unconverted = $net_total();
		$this->assertGreaterThan( 0.0, $unconverted, 'The order must have an Analytics row before the case.' );
		$this->enable_core_multi_currency( true );

		// ?wc-ajax=checkout is classified as a front request: WooCommerce defines DOING_AJAX for it only on init.
		$sut = new MultiCurrencyBootstrap( static fn(): array => array() );
		$this->register_as( $sut, wc_get_container(), $request, static fn() => OrdersScheduler::import( $order_id ) );

		// The client converts order stats on every request, last (client 11.1.0 `includes/multi-currency/Analytics.php:26,79-80`).
		$this->assertSame( 99999, has_filter( 'woocommerce_analytics_update_order_stats_data', array( $sut, 'handle_woocommerce_analytics_update_order_stats_data' ) ) );
		$this->assertEqualsWithDelta( $unconverted * 2, $net_total(), 0.001 );
	}

	/** @testdox Leaves a refund of a store-currency order without exchange-rate meta. */
	public function test_refund_of_a_store_currency_order_gets_no_exchange_rate_meta(): void {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$order = WC_Helper_Order::create_order();
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
		$order->save();
		$this->enable_core_multi_currency( true );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );
		$refund_id = 0;

		$this->register_as(
			$sut,
			wc_get_container(),
			'ajax',
			static function () use ( $order, &$refund_id ): void {
				$refund_id = wc_create_refund(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 1,
					)
				)->get_id();
			}
		);
		$refund = wc_get_order( $refund_id );

		$this->assertSame( '', $refund->get_meta( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE ) );
		$this->assertSame( '', $refund->get_meta( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY ) );
	}

	/** @testdox Adds no order listeners when the plugin or nobody owns Multi-Currency. */
	public function test_adds_no_refund_listener_without_core_ownership(): void {
		foreach ( array( MultiCurrencyRuntimeArbiter::OWNER_NONE, MultiCurrencyRuntimeArbiter::OWNER_EXTENSION ) as $owner ) {
			$sut = new MultiCurrencyBootstrap( static fn(): array => array() );

			$this->register_as( $sut, $this->make_container( $owner, true, false ), 'admin' );

			$this->assertFalse( has_action( 'woocommerce_order_refunded', array( $sut, 'handle_woocommerce_order_refunded' ) ), $owner );
			$this->assertFalse( has_action( 'woocommerce_order_status_changed', array( $sut, 'handle_woocommerce_order_status_changed' ) ), $owner );
			$this->assertFalse( has_filter( 'woocommerce_analytics_update_order_stats_data', array( $sut, 'handle_woocommerce_analytics_update_order_stats_data' ) ), $owner );
		}
	}

	/**
	 * @testdox $label: the Analytics import that Action Scheduler runs inside a page request converts a foreign-currency order to the store currency.
	 * @dataProvider page_request_import_runs
	 *
	 * @param string $label      Case label.
	 * @param bool   $configured Whether an additional currency is configured; otherwise the store only has order history.
	 * @param string $request    How the action runs: 'alternate_wp_cron' on a front page, or 'admin_run' from Tools > Scheduled Actions.
	 */
	public function test_analytics_import_run_inside_a_page_request_converts_the_order( string $label, bool $configured, string $request ): void {
		global $wpdb;
		unset( $label );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceProjectionService::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
		$order->save();
		$order_id  = $order->get_id();
		$net_total = static function () use ( $wpdb, $order_id ): float {
			return (float) $wpdb->get_var( $wpdb->prepare( "SELECT net_total FROM {$wpdb->prefix}wc_order_stats WHERE order_id = %d", $order_id ) );
		};
		OrdersScheduler::import( $order_id );
		$unconverted = $net_total();
		$this->assertGreaterThan( 0.0, $unconverted, 'The order must have an Analytics row before the case.' );
		update_option( 'wcpay_multi_currency_enabled_currencies', $configured ? array( 'USD', 'EUR' ) : array() );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->reset_all_resolved();
		if ( 'admin_run' === $request ) {
			set_current_screen( 'woocommerce_page_wc-status' );
		}

		( new MultiCurrencyBootstrap( static fn(): array => array() ) )->register( wc_get_container(), '__return_false' );
		if ( 'alternate_wp_cron' === $request ) {
			// ALTERNATE_WP_CRON runs wp-cron.php on wp_loaded, so DOING_CRON appears only after WooCommerce loaded.
			add_filter( 'wp_doing_cron', '__return_true' );
		}
		$action_id = as_enqueue_async_action( OrdersScheduler::get_action( 'import' ), array( $order_id ), 'wc-admin-data' );
		ActionScheduler::runner()->process_action( $action_id, 'alternate_wp_cron' === $request ? 'WP Cron' : 'Admin List Table' );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $action_id ) );
		$this->assertEqualsWithDelta( $unconverted * 2, $net_total(), 0.001, 'The import must convert the EUR order at the stored 0.5 rate.' );
	}

	/** @return array<string,array{string,bool,string}> */
	public static function page_request_import_runs(): array {
		return array(
			'configured, ALTERNATE_WP_CRON on a front page'  => array( 'configured, ALTERNATE_WP_CRON', true, 'alternate_wp_cron' ),
			'historical, ALTERNATE_WP_CRON on a front page'  => array( 'historical, ALTERNATE_WP_CRON', false, 'alternate_wp_cron' ),
			'configured, Scheduled Actions Run link (admin)' => array( 'configured, Scheduled Actions Run', true, 'admin_run' ),
			'historical, Scheduled Actions Run link (admin)' => array( 'historical, Scheduled Actions Run', false, 'admin_run' ),
		);
	}

	/**
	 * Run the bootstrap as WooCommerce loads it in one request class.
	 *
	 * @param MultiCurrencyBootstrap                             $sut       Bootstrap under test.
	 * @param \Automattic\WooCommerce\Container|RuntimeContainer $container Recording or real container.
	 * @param string                                             $request   Request class: front, ajax, rest, admin, cron or cli.
	 * @param callable|null                                      $then      Work to run while the request signals are still set.
	 */
	private function register_as( MultiCurrencyBootstrap $sut, $container, string $request, ?callable $then = null ): void {
		$filter = array(
			'ajax' => 'wp_doing_ajax',
			'cron' => 'wp_doing_cron',
		)[ $request ] ?? null;
		if ( null !== $filter ) {
			add_filter( $filter, '__return_true' );
		}
		if ( 'cli' === $request ) {
			Constants::set_constant( 'WP_CLI', true );
		}
		if ( 'admin' === $request ) {
			set_current_screen( 'edit-page' );
		}

		try {
			$sut->register( $container, 'rest' === $request ? '__return_true' : '__return_false' );
			if ( null !== $then ) {
				$then();
			}
		} finally {
			if ( null !== $filter ) {
				remove_filter( $filter, '__return_true' );
			}
			Constants::clear_single_constant( 'WP_CLI' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Hand Multi-Currency to core with the real container, as an upgraded store with native payments has it.
	 *
	 * @param bool $configured Whether an additional currency is configured.
	 */
	private function enable_core_multi_currency( bool $configured ): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', $configured ? array( 'USD', 'EUR' ) : array() );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->reset_all_resolved();
	}

	/** Delete the options the Multi-Currency handover reads and writes. */
	private function delete_handover_options(): void {
		foreach ( array( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'wcpay_multi_currency_enabled_currencies', 'wcpay_multi_currency_setup_completed' ) as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Run one request's registration for a payments owner, with Multi-Currency following it.
	 *
	 * @param string $payments_owner Payments runtime owner.
	 * @return RuntimeContainer The recording container.
	 */
	private function register_for_payments_owner( string $payments_owner ): RuntimeContainer {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false, false, null, $payments_owner );
		( new MultiCurrencyBootstrap( static fn(): array => array() ) )->register( $container, '__return_false' );

		return $container;
	}

	/**
	 * @param string                                    $owner Multi-Currency owner.
	 * @param bool                                      $configured Whether extra configured currencies exist.
	 * @param bool                                      $historical Whether historical orders exist.
	 * @param bool                                      $throw_on_foreign_currency_order_detection Whether historical-order detection throws.
	 * @param MultiCurrencyExplicitPriceController|null $explicit_price_controller Existing explicit-price controller.
	 * @param string|null                               $payments_owner Payments owner; defaults to the one the Multi-Currency owner follows.
	 * @return RuntimeContainer&object{resolved:array<int,string>,registered:array<int,string>,foreign_currency_order_checks:int}
	 */
	private function make_container( string $owner, bool $configured, bool $historical, bool $throw_on_foreign_currency_order_detection = false, ?MultiCurrencyExplicitPriceController $explicit_price_controller = null, ?string $payments_owner = null ): RuntimeContainer {
		$payments_owner = $payments_owner ?? array(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN   => WooPaymentsRuntimeArbiter::OWNER_BUILTIN,
			MultiCurrencyRuntimeArbiter::OWNER_EXTENSION => WooPaymentsRuntimeArbiter::OWNER_EXTENSION,
			MultiCurrencyRuntimeArbiter::OWNER_NONE      => WooPaymentsRuntimeArbiter::OWNER_NONE,
		)[ $owner ];
		$arbiter        = new class( $owner, $payments_owner ) extends MultiCurrencyRuntimeArbiter {
			/** @var string */
			private $owner;

			/** @var string */
			private $payments_owner;

			/** @var array<int,mixed> Feature option values seen when the owner was read. */
			public array $feature_option_reads = array();

			/**
			 * Initialize the configured owners.
			 *
			 * @param string $owner          Configured Multi-Currency owner.
			 * @param string $payments_owner Configured payments owner.
			 */
			public function __construct( string $owner, string $payments_owner ) {
				$this->owner          = $owner;
				$this->payments_owner = $payments_owner;
			}

			/** Return the configured owner, recording the feature option it would read. @return string Configured owner. */
			public function get_runtime_owner(): string {
				$this->feature_option_reads[] = get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
				return $this->owner;
			}

			/** Tell whether the configured payments owner is the WooPayments extension. @return bool */
			public function is_payments_extension_owner(): bool {
				return WooPaymentsRuntimeArbiter::OWNER_EXTENSION === $this->payments_owner;
			}

			/** Tell whether the configured payments owner is the built-in WooPayments. @return bool */
			public function is_payments_builtin_owner(): bool {
				return WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $this->payments_owner;
			}

			/** Tell whether core may register the configured owner. @return bool Whether core owns the runtime. */
			public function should_core_register(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};

		return new class( $arbiter, $configured, $historical, $throw_on_foreign_currency_order_detection, $explicit_price_controller ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var array<int,string> */
			public array $registered = array();

			/** @var int */
			public int $foreign_currency_order_checks = 0;

			/** @var MultiCurrencyRuntimeArbiter */
			private $arbiter;

			/** @var bool */
			private $configured;

			/** @var bool */
			private $historical;

			/** @var bool */
			private $throw_on_foreign_currency_order_detection;

			/** @var MultiCurrencyExplicitPriceController|null */
			private $explicit_price_controller;

			/**
			 * Initialize the recording container.
			 *
			 * @param MultiCurrencyRuntimeArbiter               $arbiter Runtime owner arbiter.
			 * @param bool                                      $configured Whether extra configured currencies exist.
			 * @param bool                                      $historical Whether historical orders exist.
			 * @param bool                                      $throw_on_foreign_currency_order_detection Whether historical-order detection throws.
			 * @param MultiCurrencyExplicitPriceController|null $explicit_price_controller Existing explicit-price controller.
			 */
			public function __construct( MultiCurrencyRuntimeArbiter $arbiter, bool $configured, bool $historical, bool $throw_on_foreign_currency_order_detection, ?MultiCurrencyExplicitPriceController $explicit_price_controller ) {
				parent::__construct( array() );
				$this->arbiter                                   = $arbiter;
				$this->configured                                = $configured;
				$this->historical                                = $historical;
				$this->throw_on_foreign_currency_order_detection = $throw_on_foreign_currency_order_detection;
				$this->explicit_price_controller                 = $explicit_price_controller;
			}

			/**
			 * Resolve a recorder for the requested class.
			 *
			 * @param string $class_name Requested class.
			 * @return object Recorder.
			 */
			public function get( string $class_name ) {
				$this->resolved[] = $class_name;
				if ( MultiCurrencyRuntimeArbiter::class === $class_name ) {
					return $this->arbiter;
				}

				if ( MultiCurrencyUsageDetector::class === $class_name ) {
					$configured                                = $this->configured;
					$historical                                = $this->historical;
					$container                                 = $this;
					$throw_on_foreign_currency_order_detection = $this->throw_on_foreign_currency_order_detection;

					return new class( $configured, $historical, $container, $throw_on_foreign_currency_order_detection ) {
						/** @var bool */
						private $configured;

						/** @var bool */
						private $historical;

						/** @var object */
						private $container;

						/** @var bool */
						private $throw_on_foreign_currency_order_detection;

						/**
						 * Initialize persisted-data signals.
						 *
						 * @param bool   $configured Whether extra configured currencies exist.
						 * @param bool   $historical Whether historical orders exist.
						 * @param object $container                                  Recording container.
						 * @param bool   $throw_on_foreign_currency_order_detection Whether historical-order detection throws.
						 */
						public function __construct( bool $configured, bool $historical, object $container, bool $throw_on_foreign_currency_order_detection ) {
							$this->configured                                = $configured;
							$this->historical                                = $historical;
							$this->container                                 = $container;
							$this->throw_on_foreign_currency_order_detection = $throw_on_foreign_currency_order_detection;
						}

						/** Return the configured-currency signal. @return bool Whether configured currencies exist. */
						public function has_additional_enabled_currencies(): bool {
							return $this->configured;
						}

						/** Count and return the historical-order signal. @return bool Whether historical orders exist. */
						public function has_foreign_currency_orders(): bool {
							++$this->container->foreign_currency_order_checks;
							if ( $this->throw_on_foreign_currency_order_detection ) {
								throw new \RuntimeException( 'Historical order detection failed.' );
							}

							return $this->historical;
						}
					};
				}

				if ( MultiCurrencyExplicitPriceController::class === $class_name && null !== $this->explicit_price_controller ) {
					$this->registered[] = $class_name;
					return $this->explicit_price_controller;
				}

				$registered = &$this->registered;
				return new class( $class_name, $registered ) {
					/** @var string */
					private $class_name;

					/** @var array<int,string> */
					private $registered;

					/**
					 * Initialize the recording registrar.
					 *
					 * @param string            $class_name Class name.
					 * @param array<int,string> $registered Registered roots.
					 */
					public function __construct( string $class_name, array &$registered ) {
						$this->class_name = $class_name;
						$this->registered = &$registered;
					}

					/** Record registration. */
					public function register(): void {
						$this->registered[] = $this->class_name;
					}
				};
			}
		};
	}

	/**
	 * Create the existing explicit-price controller with a fixed state default.
	 *
	 * @param bool $has_additional_currencies_enabled Whether the controller state has additional currencies.
	 * @return MultiCurrencyExplicitPriceController Explicit-price controller.
	 */
	private function create_explicit_price_controller( bool $has_additional_currencies_enabled ): MultiCurrencyExplicitPriceController {
		// The plugin stores the enabled list as currency codes (client 11.1.0 `includes/multi-currency/MultiCurrency.php:767-783`).
		update_option( 'wcpay_multi_currency_enabled_currencies', $has_additional_currencies_enabled ? array( 'USD', 'EUR' ) : array() );
		$state = $this->createMock( MultiCurrencyState::class );
		$state->method( 'has_additional_currencies_enabled' )->willReturn( $has_additional_currencies_enabled );
		$builder = $this->getMockBuilder( MultiCurrencyStateBuilder::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'build' ) )
			->getMock();
		$builder->method( 'build' )->willReturn( $state );
		$factory = $this->getMockBuilder( MultiCurrencyStateBuilderFactory::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create' ) )
			->getMock();
		$factory->method( 'create' )->willReturn( $builder );
		$controller = new MultiCurrencyExplicitPriceController();
		$controller->init(
			new class() extends MultiCurrencyRuntimeArbiter {
				/** Tell whether core may register. @return bool */
				public function should_core_register(): bool {
					return true;
				}
			},
			$factory,
			new MultiCurrencyUsageDetector()
		);

		return $controller;
	}
}
