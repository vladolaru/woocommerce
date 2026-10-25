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
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyExplicitPriceController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySubscriptionsCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
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
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyTrackerController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAdminNoticesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAdminNoteController',
	);

	/** Start each case without the extension-flag mirror and the options the Multi-Currency handover reads and writes. */
	public function setUp(): void {
		parent::setUp();
		// WooCommerce's own load registers the mirror on a test site without a payments owner; the cases register it themselves.
		remove_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, array( MultiCurrencyFeatureController::class, 'mirror_extension_flag' ), 10 );
		$this->delete_handover_options();
	}

	/** Clear the WP_CLI override the CLI case sets, and the services and screen a booted request leaves behind. */
	public function tearDown(): void {
		remove_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, array( MultiCurrencyFeatureController::class, 'mirror_extension_flag' ), 10 );
		$this->delete_handover_options();
		Constants::clear_single_constant( 'WP_CLI' );
		set_current_screen( 'front' );
		wc_get_container()->reset_all_resolved();
		parent::tearDown();
	}

	/**
	 * @testdox $label: on a store with no additional currency left, a cart holding a subscription in another currency runs in it.
	 *
	 * The merchant disabled GBP, the store's only additional currency, while a GBP 7.45 subscription was active: through the
	 * enabled list (GBP's manual rate 0.73 kept) or through the settings screen (GBP's options deleted, the provider's cached
	 * rate 0.75 left). A recorded store run charged its renewal and resubscribe as USD 7.45. The renewal and resubscribe lines
	 * hold the subscription's stored amounts; the cart's currency, and what the cart converts (an ordinary product beside a
	 * resubscribe, a switch's new plan), follow the subscription's currency. Carts without such a subscription stay as they
	 * were: nothing more registers and no currency state is built.
	 *
	 * @dataProvider subscription_currency_carts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string              $label     Case label.
	 * @param array<string,mixed> $options   Options the store holds besides an enabled list without additional currencies.
	 * @param string              $cart_type Subscription cart type of the cart item, `switch request` for the switch page, or
	 *                                       `ordinary` for a cart without a subscription.
	 * @param string              $currency  The subscription's currency.
	 * @param string              $request   Request type.
	 * @param array<int,mixed>    $expected  Cart currency, a new order's currency, a 20.00 product's price, whether WooCommerce's
	 *                                       currency is filtered, whether the currency state was built, and the cart total.
	 */
	public function test_runs_a_subscription_cart_in_its_currency_on_a_single_currency_store( string $label, array $options, string $cart_type, string $currency, string $request, array $expected ): void {
		unset( $label );
		$this->arrange_single_currency_subscriptions_store( $options, $currency );
		if ( 'switch request' === $cart_type ) {
			// The switch link Subscriptions builds for the subscription's customer (`includes/switching/class-wc-subscriptions-switcher.php`).
			$_GET['switch-subscription'] = '7';
			$_GET['_wcsnonce']           = wp_create_nonce( 'wcs_switch_request' );
		} else {
			WC()->cart->set_cart_contents( array( 'item' => $this->create_cart_item( $cart_type, 'ordinary' === $cart_type ? '20' : '7.45' ) ) );
		}

		try {
			$this->register_as( new MultiCurrencyBootstrap( static fn(): array => array( 'CachedRateProviderRoot' ) ), wc_get_container(), $request );
			// Subscriptions sets the cart up or WooCommerce loads it from the session; the switch page arrives as a request.
			do_action( 'switch request' === $cart_type ? 'wp_loaded' : 'woocommerce_cart_loaded_from_session', WC()->cart );
			$product = new \WC_Product_Simple();
			$product->set_regular_price( '20' );
			$product->set_price( '20' );
			$total = null;
			if ( ! WC()->cart->is_empty() ) {
				WC()->cart->calculate_totals();
				$total = (float) WC()->cart->get_total( 'edit' );
			}

			$this->assertSame(
				$expected,
				array(
					get_woocommerce_currency(),
					wc_create_order()->get_currency(),
					$product->get_price(),
					false !== has_filter( 'woocommerce_currency' ),
					false !== has_action( 'updated_option', array( wc_get_container()->get( MultiCurrencyStateBuilderFactory::class ), 'handle_option_change' ) ),
					$total,
				)
			);
		} finally {
			unset( $_GET['switch-subscription'], $_GET['_wcsnonce'] );
			WC()->cart->set_cart_contents( array() );
			wc_get_container()->reset_all_replacements();
		}
	}

	/**
	 * Carts on a store whose only additional currency, GBP, is disabled.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function subscription_currency_carts(): array {
		$kept_rate = array(
			'wcpay_multi_currency_exchange_rate_gbp'  => 'manual',
			'wcpay_multi_currency_manual_rate_gbp'    => '0.73',
			'wcpay_multi_currency_price_rounding_gbp' => '0',
		);
		// The rate cache's envelope as the provider stores it (client 11.1.0 `includes/class-database-cache.php:377-382`,
		// data from `includes/multi-currency/MultiCurrency.php:492-508`).
		$cached_rate = array(
			'wcpay_multi_currency_cached_currencies' => array(
				'data'               => array(
					'currencies' => array( 'gbp' => 0.75 ),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
		);
		$gbp         = array( 'GBP', 'GBP', '14.6', true, true );
		$usd         = array( 'USD', 'USD', '20', false, false );

		return array(
			'renewal, enabled list edited'             => array( 'renewal, enabled list edited', $kept_rate, 'renewal', 'GBP', 'front', array_merge( $gbp, array( 7.45 ) ) ),
			'renewal, settings screen'                 => array( 'renewal, settings screen', $cached_rate, 'renewal', 'GBP', 'front', array( 'GBP', 'GBP', '15', true, true, 7.45 ) ),
			'renewal through the Store API'            => array( 'renewal through the Store API', $kept_rate, 'renewal', 'GBP', 'rest', array_merge( $gbp, array( 7.45 ) ) ),
			'resubscribe, enabled list edited'         => array( 'resubscribe, enabled list edited', $kept_rate, 'resubscribe', 'GBP', 'front', array_merge( $gbp, array( 7.45 ) ) ),
			'switch cart, enabled list edited'         => array( 'switch cart, enabled list edited', $kept_rate, 'switch', 'GBP', 'front', array_merge( $gbp, array( 5.44 ) ) ),
			'switch page, enabled list edited'         => array( 'switch page, enabled list edited', $kept_rate, 'switch request', 'GBP', 'front', array_merge( $gbp, array( null ) ) ),
			'renewal of a store-currency subscription' => array( 'renewal of a store-currency subscription', $kept_rate, 'renewal', 'USD', 'front', array_merge( $usd, array( 7.45 ) ) ),
			'cart without a subscription'              => array( 'cart without a subscription', $kept_rate, 'ordinary', 'GBP', 'front', array_merge( $usd, array( 20.0 ) ) ),
		);
	}

	/**
	 * @testdox Should watch carts only on a single-currency store with WooCommerce Subscriptions, on storefront, AJAX and REST requests: subscriptions $subscriptions, additional currencies $configured, $request request.
	 * @testWith [false, false, "front", false]
	 *           [true, true, "front", false]
	 *           [true, false, "front", true]
	 *           [true, false, "ajax", true]
	 *           [true, false, "rest", true]
	 *           [true, false, "admin", false]
	 *           [true, false, "cron", false]
	 *           [true, false, "cli", false]
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool   $subscriptions Whether WooCommerce Subscriptions is active.
	 * @param bool   $configured    Whether an additional currency is enabled.
	 * @param string $request       Request type.
	 * @param bool   $watched       Whether carts are watched.
	 */
	public function test_watches_carts_only_on_a_single_currency_store_with_subscriptions( bool $subscriptions, bool $configured, string $request, bool $watched ): void {
		if ( $subscriptions && ! class_exists( 'WC_Subscriptions', false ) ) {
			eval( 'class WC_Subscriptions {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Stub of the extension's class, declared in this process only.
		}
		$this->enable_core_multi_currency( $configured );
		$sut = new MultiCurrencyBootstrap( static fn(): array => array() );
		$this->register_as( $sut, wc_get_container(), $request );

		foreach ( array( 'woocommerce_cart_loaded_from_session', 'woocommerce_add_to_cart', 'woocommerce_cart_item_restored', 'wp_loaded' ) as $hook ) {
			$this->assertSame( $watched, (bool) has_action( $hook, array( $sut, 'handle_subscription_currency_context' ) ), $hook );
		}
	}

	/**
	 * @testdox Should check an ordinary cart without a query, a subscription load or a session, and wake once $hook brings a renewal in.
	 *
	 * The single-currency watch runs on every request that loads a cart, up to four times; on a cart without a subscription
	 * item it must cost nothing, a repeated check on an unchanged cart reads the cached cart items, and on a request without
	 * a loaded session it must not start one. A renewal item then enters through add_to_cart() (Subscriptions' setup,
	 * `includes/core/class-wcs-cart-renewal.php:370`) or an Undo restore.
	 * @testWith ["woocommerce_add_to_cart"]
	 *           ["woocommerce_cart_item_restored"]
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $hook Hook the renewal item arrives with.
	 */
	public function test_checks_an_ordinary_cart_for_free_and_wakes_for_a_renewal_set_up_later( string $hook ): void {
		$controller = $this->arrange_single_currency_subscriptions_store( self::subscription_currency_carts()['renewal, enabled list edited'][1], 'GBP' );
		WC()->cart->set_cart_contents( array( 'item' => $this->create_cart_item( 'ordinary', '20' ) ) );
		$session = WC()->session;
		global $wpdb;

		try {
			$sut = new MultiCurrencyBootstrap( static fn(): array => array( 'CachedRateProviderRoot' ) );
			$this->register_as( $sut, wc_get_container(), 'front' );
			WC()->session = null;
			$queries      = $wpdb->num_queries;
			$sut->handle_subscription_currency_context();
			$sut->handle_subscription_currency_context();

			$this->assertSame( array( 0, 0, null, false ), array( $wpdb->num_queries - $queries, $GLOBALS['wcs_subscription_loads'], WC()->session, false !== has_filter( 'woocommerce_currency' ) ) );

			// Through the listeners as they fire: the cart loads, then wp_loaded reads the same cart from the cache.
			WC()->session = $session;
			do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
			$walks     = $controller->cart_walks;
			$wp_loaded = $GLOBALS['wp_filter']['wp_loaded'] ?? null;
			remove_all_actions( 'wp_loaded' );
			add_action( 'wp_loaded', array( $sut, 'handle_subscription_currency_context' ) );
			do_action( 'wp_loaded' );
			$GLOBALS['wp_filter']['wp_loaded'] = $wp_loaded; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the hook this case emptied.
			$this->assertSame( $walks, $controller->cart_walks, 'wp_loaded reads the cart items the cart load cached.' );

			WC()->cart->set_cart_contents( array( 'item' => $this->create_cart_item( 'renewal', '7.45' ) ) );
			do_action( $hook, 'item', 31, 1, 0, array(), array() );

			$this->assertSame( 'GBP', get_woocommerce_currency() );
		} finally {
			WC()->session = $session;
			WC()->cart->set_cart_contents( array() );
			wc_get_container()->reset_all_replacements();
		}
	}


	/** @testdox Should arm the Multi-Currency handover on a request the WooPayments plugin owns. */
	public function test_extension_owned_request_arms_the_handover(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );

		$this->assertSame( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_EXTENSION, get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION ) );
		$this->assertArrayHasKey( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, wp_load_alloptions(), 'Every request reads the marker, so it is autoloaded.' );
	}

	/** @testdox Should hand the plugin's Multi-Currency state over on the first native-owned request, before the arbiter reads the option. */
	public function test_first_builtin_request_hands_over_before_the_arbiter_reads(): void {
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
	public function test_later_builtin_request_keeps_the_merchant_choice(): void {
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
		// The disable also turned the extension's flag off; the merchant turns Multi-Currency back on in the extension.
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( 'yes', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
	}

	/**
	 * @testdox Should keep the WooPayments extension's Multi-Currency flag in step with the Features page: $_dataName.
	 *
	 * The extension runs its Multi-Currency from that flag alone (client 11.1.0 includes/class-wc-payments-features.php:67-69),
	 * so a return to the extension keeps the merchant's last choice.
	 *
	 * @dataProvider provide_feature_changes
	 *
	 * @param string $feature_id     The feature the Features page changes.
	 * @param string $before         The feature's option before the change.
	 * @param string $flag           The extension's flag before the change.
	 * @param bool   $enable         The new feature state.
	 * @param string $expected       The extension's flag after the change.
	 * @param string $payments_owner The payments owner of the request.
	 */
	public function test_features_page_change_mirrors_the_extension_flag( string $feature_id, string $before, string $flag, bool $enable, string $expected, string $payments_owner ): void {
		$features = wc_get_container()->get( FeaturesController::class );
		$features->change_feature_enable( $feature_id, 'yes' === $before );
		update_option( '_wcpay_feature_customer_multi_currency', $flag );
		$this->register_for_payments_owner( $payments_owner );

		$features->change_feature_enable( $feature_id, $enable );

		$this->assertSame( $expected, get_option( '_wcpay_feature_customer_multi_currency' ) );
	}

	/**
	 * Features page changes: Multi-Currency disabled and enabled, and another feature, under each payments owner.
	 *
	 * @return array<string,array{string,string,string,bool,string,string}>
	 */
	public function provide_feature_changes(): array {
		return array(
			'disabled on the Features page'         => array( MultiCurrencyFeatureController::FEATURE_ID, 'yes', '1', false, '0', WooPaymentsRuntimeArbiter::OWNER_BUILTIN ),
			'enabled on the Features page'          => array( MultiCurrencyFeatureController::FEATURE_ID, 'no', '0', true, '1', WooPaymentsRuntimeArbiter::OWNER_BUILTIN ),
			'another feature changed'               => array( 'cost_of_goods_sold', 'yes', '1', false, '1', WooPaymentsRuntimeArbiter::OWNER_BUILTIN ),
			'disabled without a payments owner'     => array( MultiCurrencyFeatureController::FEATURE_ID, 'yes', '1', false, '0', WooPaymentsRuntimeArbiter::OWNER_NONE ),
			'enabled without a payments owner'      => array( MultiCurrencyFeatureController::FEATURE_ID, 'no', '0', true, '1', WooPaymentsRuntimeArbiter::OWNER_NONE ),
			'not mirrored while the extension owns' => array( MultiCurrencyFeatureController::FEATURE_ID, 'yes', '1', false, '1', WooPaymentsRuntimeArbiter::OWNER_EXTENSION ),
		);
	}

	/** @testdox Should mark a store native on its first native-owned request without a marker, leaving the option unset without plugin data. */
	public function test_first_builtin_request_without_marker_marks_the_store_builtin(): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_BUILTIN, get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION ), 'Later requests then read the marker from the autoloaded options.' );
		$this->assertArrayHasKey( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, wp_load_alloptions() );
		$this->assertFalse( get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, false ) );
	}

	/** @testdox Should apply the upgrade seed on a first native-owned request without a marker, never over a stored choice. */
	public function test_first_builtin_request_without_marker_applies_the_upgrade_seed(): void {
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

	/**
	 * @testdox Should hand the extension's Multi-Currency state over when the extension is deactivated and nothing owns payments: $_dataName.
	 *
	 * @testWith ["0", "yes", "no"]
	 *           ["1", "no", "yes"]
	 *
	 * @param string $flag     The extension's own Multi-Currency flag when it is deactivated.
	 * @param string $stored   The core feature option at that moment (the upgrade seed, or a Features page save).
	 * @param string $expected The core feature option after the first request without the extension.
	 */
	public function test_extension_deactivation_without_a_payments_owner_hands_over( string $flag, string $stored, string $expected ): void {
		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, $stored );
		update_option( '_wcpay_feature_customer_multi_currency', $flag );

		$container = $this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_NONE );

		$this->assertSame( $expected, get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ), 'Core Multi-Currency follows the extension\'s last state.' );
		$this->assertSame( $expected, $container->get( MultiCurrencyRuntimeArbiter::class )->feature_option_reads[0], 'The arbiter must read the handed-over value.' );
	}

	/**
	 * @testdox Should write no option on a request without a payments owner on a store that never ran the extension: feature $feature.
	 *
	 * @testWith ["yes"]
	 *           ["no"]
	 *
	 * @param string $feature The core Multi-Currency feature option.
	 */
	public function test_store_without_the_extension_writes_no_option_without_a_payments_owner( string $feature ): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, $feature );
		$written = array();
		$record  = static function ( $option ) use ( &$written ): void {
			$written[] = $option;
		};
		add_action( 'added_option', $record );
		add_action( 'updated_option', $record );

		try {
			$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_NONE );
		} finally {
			remove_action( 'added_option', $record );
			remove_action( 'updated_option', $record );
		}

		$this->assertSame( array(), $written );
	}

	/** @testdox Should write nothing when no payments runtime owns the site. */
	public function test_no_payments_owner_writes_nothing(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$this->register_for_payments_owner( WooPaymentsRuntimeArbiter::OWNER_NONE );

		$this->assertFalse( get_option( MultiCurrencyFeatureController::LAST_PAYMENTS_OWNER_OPTION, false ) );
		$this->assertFalse( get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, false ) );
	}

	/** @testdox Should count a completed plugin setup as plugin use at the switch. */
	public function test_completed_setup_counts_as_extension_use_at_the_switch(): void {
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

		$sut->register( $container, 'front' );

		$this->assertSame( self::core_root_matrix()['configured front'][3], $container->registered );
	}

	/**
	 * @testdox Should resolve only the ownership arbiter when the runtime has no owner, whoever owns payments.
	 * @dataProvider provide_payments_owners_without_multi_currency
	 *
	 * @param string $payments_owner Payments owner while Multi-Currency is off.
	 */
	public function test_owner_none_resolves_only_the_arbiter( string $payments_owner ): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false, false, null, $payments_owner );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);
		add_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );

		try {
			$sut->register( $container, 'front' );
		} finally {
			remove_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );
		}

		$this->assertSame( array( MultiCurrencyRuntimeArbiter::class ), $container->resolved );
		$this->assertSame( 0, $resolver_calls );
		$this->assertFalse( has_filter( 'woocommerce_cart_total' ) );
	}

	/**
	 * Payments owners of a store with Multi-Currency off.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_payments_owners_without_multi_currency(): array {
		return array(
			'no payments owner'          => array( WooPaymentsRuntimeArbiter::OWNER_NONE ),
			'built-in WooPayments owner' => array( WooPaymentsRuntimeArbiter::OWNER_BUILTIN ),
		);
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

		$sut->register( $container, 'front' );

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

		$sut->register( $container, 'front' );

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

		$sut->register( $container, 'front' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/**
	 * @testdox Should register only the explicit price filter on an empty front request when no payments runtime owns the store.
	 */
	public function test_empty_front_request_without_a_payments_owner_registers_only_the_explicit_price_filter(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false, null, WooPaymentsRuntimeArbiter::OWNER_NONE );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, 'front' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/**
	 * @testdox Should not resolve provider roots for an empty $request request.
	 * @dataProvider provide_empty_requests_without_provider_roots
	 *
	 * @param string $request Request type.
	 */
	public function test_empty_request_does_not_resolve_provider_roots( string $request ): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$this->register_as( $sut, $container, $request );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
	}

	/** @return array<string,array{string}> */
	public static function provide_empty_requests_without_provider_roots(): array {
		return array(
			'front' => array( 'front' ),
			'AJAX'  => array( 'ajax' ),
			'CLI'   => array( 'cli' ),
			'cron'  => array( 'cron' ),
		);
	}

	/** @testdox Should add no cart or order total filter on an empty front request. */
	public function test_empty_front_request_adds_no_total_filters(): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array( 'ProviderRoot' ) );

		$sut->register( $container, 'front' );

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
		$sut->register( $container, 'front' );
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

		$sut->register( $container, 'rest' );

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
			$sut->register( $container, 'rest' );
		} finally {
			remove_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );
		}

		$this->assertSame( 1, $resolver_calls );
		$this->assertSame( array( 'ProviderRoot', self::CORE_ROOTS[21], MultiCurrencyExplicitPriceController::class ), $container->registered );
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

		$this->register_as( new MultiCurrencyBootstrap( static fn(): array => array() ), $container, $request );

		$this->assertSame( $expected, $container->registered );
	}

	/**
	 * @testdox Should select the same core root matrix when no payments runtime owns the store ($_dataName).
	 *
	 * @dataProvider core_root_matrix
	 *
	 * @param bool              $configured Whether an additional currency is configured.
	 * @param bool              $historical Whether historical Multi-Currency data exists.
	 * @param string            $request    Request class.
	 * @param array<int,string> $expected   Expected roots.
	 */
	public function test_selects_the_same_core_root_matrix_without_a_payments_owner( bool $configured, bool $historical, string $request, array $expected ): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $configured, $historical, false, null, WooPaymentsRuntimeArbiter::OWNER_NONE );

		$this->register_as( new MultiCurrencyBootstrap( static fn(): array => array() ), $container, $request );

		$this->assertSame( $expected, $container->registered );
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

		$this->register_as( new MultiCurrencyBootstrap( static fn(): array => array() ), $container, $request );

		$this->assertSame( $expected, $container->registered );
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

			$this->register_as( new MultiCurrencyBootstrap( static fn(): array => array() ), $container, $request );

			$this->assertSame( array( MultiCurrencyExplicitPriceController::class ), $container->registered );
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
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_STRIPE_EXCHANGE_RATE, '0.49' );
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
		$this->assertSame( '0.5', $refund->get_meta( MultiCurrencyPriceConverter::META_KEY_ORDER_EXCHANGE_RATE ) );
		$this->assertSame( 'USD', $refund->get_meta( MultiCurrencyPriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY ) );
		$this->assertSame( '0.49', $refund->get_meta( MultiCurrencyPriceConverter::META_KEY_STRIPE_EXCHANGE_RATE ) );
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
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
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
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
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

		$this->assertSame( '', $refund->get_meta( MultiCurrencyPriceConverter::META_KEY_ORDER_EXCHANGE_RATE ) );
		$this->assertSame( '', $refund->get_meta( MultiCurrencyPriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY ) );
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
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_EXCHANGE_RATE, '0.5' );
		$order->update_meta_data( MultiCurrencyPriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->reset_all_resolved();
		if ( 'admin_run' === $request ) {
			set_current_screen( 'woocommerce_page_wc-status' );
		}

		( new MultiCurrencyBootstrap( static fn(): array => array() ) )->register( wc_get_container(), 'admin_run' === $request ? 'admin' : 'front' );
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
	 * Run the bootstrap as WooCommerce loads it in one request type, with that request's signals set.
	 *
	 * @param MultiCurrencyBootstrap                             $sut       Bootstrap under test.
	 * @param \Automattic\WooCommerce\Container|RuntimeContainer $container Recording or real container.
	 * @param string                                             $request   Request type: front, ajax, rest, admin, cron or cli.
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
			$sut->register( $container, $request );
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
	 * Arrange a store whose only additional currency is disabled, with WooCommerce Subscriptions loaded and a subscription.
	 *
	 * Declares the Subscriptions class and `wcs_get_subscription()` in this process (the test must run in its own), stores the
	 * subscription it returns in `$GLOBALS['wcs_subscription']` and counts its calls in `$GLOBALS['wcs_subscription_loads']`,
	 * logs its customer in, serves the provider root `CachedRateProviderRoot` that installs a provider whose cached rates serve,
	 * and puts a Subscriptions compatibility controller that counts its cart walks in the container.
	 *
	 * @param array<string,mixed> $options  Options the store holds besides an enabled list without additional currencies.
	 * @param string              $currency The subscription's currency.
	 * @return MultiCurrencySubscriptionsCompatibilityController&object{cart_walks:int}
	 */
	private function arrange_single_currency_subscriptions_store( array $options, string $currency ): MultiCurrencySubscriptionsCompatibilityController {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$this->enable_core_multi_currency( false );
		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}
		if ( ! class_exists( 'WC_Subscriptions', false ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Stubs of the extension's class and lookup, declared in this process only.
			eval( 'class WC_Subscriptions {} function wcs_get_subscription( $id ) { ++$GLOBALS["wcs_subscription_loads"]; return $GLOBALS["wcs_subscription"]; }' );
		}
		$customer_id = self::factory()->user->create();
		wp_set_current_user( $customer_id );
		// Subscriptions 9.3.0's `WC_Subscription` extends `WC_Order` (`includes/core/class-wc-subscription.php:15`).
		$subscription = new \WC_Order();
		$subscription->set_currency( $currency );
		$subscription->set_customer_id( $customer_id );
		$GLOBALS['wcs_subscription']       = $subscription;
		$GLOBALS['wcs_subscription_loads'] = 0;
		wc_get_container()->replace(
			'CachedRateProviderRoot',
			new class( $this->create_cached_rate_provider() ) {
				/** @var CurrencyRateProviderInterface */
				private CurrencyRateProviderInterface $provider;

				/**
				 * Constructor.
				 *
				 * @param CurrencyRateProviderInterface $provider A provider whose cached rates serve.
				 */
				public function __construct( CurrencyRateProviderInterface $provider ) {
					$this->provider = $provider;
				}

				/** Set the provider, as a payments provider's Multi-Currency classes do. */
				public function register(): void {
					wc_get_container()->get( MultiCurrencyRateService::class )->set_provider( $this->provider );
				}
			}
		);
		$controller = new class() extends MultiCurrencySubscriptionsCompatibilityController {
			/** @var int Walks of the cart and session for one cart type. */
			public int $cart_walks = 0;

			/**
			 * Walk the cart and session for one cart type, counting the walks.
			 *
			 * @param string $type Subscription cart type.
			 * @return array<int,array<string,mixed>>
			 */
			protected function resolve_subscription_cart_items( string $type ): array {
				++$this->cart_walks;
				return parent::resolve_subscription_cart_items( $type );
			}
		};
		$controller->init(
			wc_get_container()->get( MultiCurrencyRuntimeArbiter::class ),
			new LegacyProxy(),
			wc_get_container()->get( MultiCurrencyStateBuilderFactory::class ),
			wc_get_container()->get( MultiCurrencyServiceFactory::class )
		);
		wc_get_container()->replace( MultiCurrencySubscriptionsCompatibilityController::class, $controller );

		return $controller;
	}

	/**
	 * Create a cart item, keyed for a subscription cart type as Subscriptions 9.3.0 keys them: renewal
	 * (`includes/core/class-wcs-cart-renewal.php:228-233`), resubscribe (`includes/core/class-wcs-cart-resubscribe.php:110-112`)
	 * and switch (`includes/switching/class-wc-subscriptions-switcher.php:2431-2436`); `ordinary` carries no key.
	 *
	 * @param string $cart_type Subscription cart type or `ordinary`.
	 * @param string $price     The line's price, a renewal's or resubscribe's stored amount.
	 * @return array<string,mixed>
	 */
	private function create_cart_item( string $cart_type, string $price ): array {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_virtual( true );
		$product->set_id( 31 );
		$item = array(
			'key'          => 'item',
			'product_id'   => 31,
			'variation_id' => 0,
			'quantity'     => 1,
			'data'         => $product,
		);

		return 'ordinary' === $cart_type ? $item : $item + array( 'subscription_' . $cart_type => array( 'subscription_id' => 7 ) );
	}

	/**
	 * Create a rate provider that cannot be reached, so the cached rates serve.
	 *
	 * @return CurrencyRateProviderInterface
	 */
	private function create_cached_rate_provider(): CurrencyRateProviderInterface {
		return new class() implements CurrencyRateProviderInterface {
			/** @return string */
			public function get_id(): string {
				return 'unreachable-provider';
			}

			/** @return bool */
			public function is_available(): bool {
				return false;
			}

			/** @return bool */
			public function is_connected(): bool {
				return true;
			}

			/** @return string[] */
			public function get_supported_currencies(): array {
				return array();
			}

			/**
			 * Get currency rates.
			 *
			 * @param string        $currency_from Currency to convert from.
			 * @param string[]|null $currencies_to Currencies to convert into.
			 * @return array<string,mixed>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );
				return array();
			}
		};
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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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
		( new MultiCurrencyBootstrap( static fn(): array => array() ) )->register( $container, 'front' );

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
			public function is_builtin_owner(): bool {
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
						public function has_configured_additional_currencies(): bool {
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
			->onlyMethods( array( 'get_state_builder' ) )
			->getMock();
		$factory->method( 'get_state_builder' )->willReturn( $builder );
		$controller = new MultiCurrencyExplicitPriceController();
		$controller->init(
			new class() extends MultiCurrencyRuntimeArbiter {
				/** Tell whether core may register. @return bool */
				public function is_builtin_owner(): bool {
					return true;
				}
			},
			$factory,
			new MultiCurrencyUsageDetector()
		);

		return $controller;
	}
}
