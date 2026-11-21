<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\WooCommerceSubscriptions;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\StoreApi\Utilities\DraftOrderTrait;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\CallStack;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RegisteredHooks;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RequestSignals;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\ServiceFactories;

/**
 * Tests for the WooCommerceSubscriptions class.
 */
class WooCommerceSubscriptionsTest extends WC_Unit_Test_Case {

	/**
	 * The extension's session key marking coupon copies stored unconverted (client 11.3.0
	 * `includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:37`).
	 */
	private const UNCONVERTED_SESSION_COUPONS_KEY = 'wcpay_multi_currency_unconverted_session_coupons';

	private const RENEWAL_SETUP_ACTION = 'woocommerce_setup_cart_for_subscription_renewal';

	private const INITIAL_PAYMENT_SETUP_ACTION = 'woocommerce_setup_cart_for_subscription_initial_payment';

	/**
	 * A fixed coupon's properties as Subscriptions copies them, in the store currency.
	 */
	private const COUPON_COPY = array(
		'discount_type'  => 'fixed_cart',
		'amount'         => '10.00',
		'minimum_amount' => '50.00',
		'maximum_amount' => '60.00',
	);

	/**
	 * Hooks touched by the subscriptions compatibility controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'wcpay_multi_currency_override_selected_currency',
		'wcpay_multi_currency_should_disable_currency_switching',
		'wcpay_multi_currency_should_convert_product_price',
		'wcpay_multi_currency_should_convert_coupon_amount',
		'woocommerce_subscriptions_product_price',
		'woocommerce_subscriptions_product_sale_price',
		'woocommerce_subscriptions_product_regular_price',
		'woocommerce_product_get_sale_price',
		'woocommerce_product_get__subscription_sign_up_fee',
		'woocommerce_product_variation_get__subscription_sign_up_fee',
		'woocommerce_subscription_price_string_details',
		'woocommerce_get_formatted_subscription_total',
		'wc_price',
		'option_woocommerce_subscriptions_multiple_purchase',
		'woocommerce_cart_loaded_from_session',
		'woocommerce_cart_updated',
		'woocommerce_cart_emptied',
		'woocommerce_add_to_cart',
		'woocommerce_cart_item_removed',
		'woocommerce_cart_item_restored',
		'wcpay_multi_currency_should_output_explicit_price',
		'woocommerce_coupon_get_minimum_amount',
		'woocommerce_coupon_get_maximum_amount',
		'wcpay_multi_currency_should_convert_coupon_min_max_amount',
		'woocommerce_setup_cart_for_subscription_renewal',
		'woocommerce_setup_cart_for_subscription_initial_payment',
	);

	/**
	 * Controllers that scheduled deferred plugin-loaded registration.
	 *
	 * @var WooCommerceSubscriptions[]
	 */
	private array $deferred_controllers = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		foreach ( $this->deferred_controllers as $controller ) {
			remove_action( 'plugins_loaded', array( $controller, 'register_subscription_filters' ), 20 );
		}

		// The session object outlives the test: its cart decides later cart-type lookups, and the copies and their mark later
		// coupon reads.
		if ( WC()->session instanceof \WC_Session ) {
			WC()->session->set( 'cart', null );
			WC()->session->set( 'wcs_renewal_coupons', null );
			WC()->session->set( self::UNCONVERTED_SESSION_COUPONS_KEY, null );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should register exactly the subscription filters the request allows, once: $_dataName.
	 * @dataProvider provide_subscriptions_requests
	 *
	 * @param string                            $owner                   Runtime owner.
	 * @param bool                              $subscriptions_available Whether Subscriptions is loaded.
	 * @param bool                              $is_admin                Whether this is an admin request.
	 * @param bool                              $is_cron                 Whether this is a cron request.
	 * @param array<string,array<string,int[]>> $expected                Every hook the controller registers.
	 */
	public function test_registers_the_subscription_filters_the_request_allows( string $owner, bool $subscriptions_available, bool $is_admin, bool $is_cron, array $expected ): void {
		$sut = $this->create_controller( $owner, $subscriptions_available, $is_admin, $is_cron );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Requests and the subscription filters each registers: all of them, with the cart-type cache invalidation, on the
	 * storefront under core with Subscriptions loaded, none otherwise.
	 *
	 * @return array<string,array{0:string,1:bool,2:bool,3:bool,4:array<string,array<string,int[]>>}>
	 */
	public function provide_subscriptions_requests(): array {
		// First and last on each cart setup action (client 11.3.0 `includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:98-101`).
		$marker_callbacks = array(
			'finish_storing_unconverted_session_coupons' => array( PHP_INT_MAX, 1 ),
			'start_storing_unconverted_session_coupons'  => array( 0, 1 ),
		);
		$hooks            = array(
			'option_woocommerce_subscriptions_multiple_purchase' => array( 'maybe_disable_mixed_cart' => array( 50, 1 ) ),
			'wc_price'                                     => array( 'maybe_get_explicit_format_for_subscription_total' => array( 50, 1 ) ),
			'wcpay_multi_currency_override_selected_currency' => array( 'override_selected_currency' => array( 50, 1 ) ),
			'wcpay_multi_currency_should_convert_coupon_amount' => array( 'should_convert_coupon_amount' => array( 50, 2 ) ),
			'wcpay_multi_currency_should_convert_coupon_min_max_amount' => array( 'should_convert_coupon_min_max_amount' => array( 50, 2 ) ),
			'wcpay_multi_currency_should_convert_product_price' => array( 'should_convert_product_price' => array( 50, 2 ) ),
			'wcpay_multi_currency_should_disable_currency_switching' => array( 'should_disable_currency_switching' => array( 50, 1 ) ),
			'woocommerce_add_to_cart'                      => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
			'woocommerce_cart_emptied'                     => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
			'woocommerce_cart_item_removed'                => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
			'woocommerce_cart_item_restored'               => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
			'woocommerce_cart_loaded_from_session'         => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
			'woocommerce_cart_updated'                     => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
			'woocommerce_get_formatted_subscription_total' => array( 'maybe_clear_current_my_account_subscription' => array( 50, 2 ) ),
			'woocommerce_product_get__subscription_sign_up_fee' => array( 'get_subscription_product_signup_fee' => array( 50, 2 ) ),
			'woocommerce_product_variation_get__subscription_sign_up_fee' => array( 'get_subscription_product_signup_fee' => array( 50, 2 ) ),
			'woocommerce_setup_cart_for_subscription_initial_payment' => $marker_callbacks,
			'woocommerce_setup_cart_for_subscription_renewal' => $marker_callbacks,
			'woocommerce_subscription_price_string_details' => array( 'maybe_set_current_my_account_subscription' => array( 50, 2 ) ),
			'woocommerce_subscriptions_product_price'      => array( 'get_subscription_product_price' => array( 50, 2 ) ),
			'woocommerce_subscriptions_product_regular_price' => array( 'get_stored_price_for_active_price' => array( 50, 2 ) ),
			'woocommerce_subscriptions_product_sale_price' => array( 'get_stored_price_for_active_price' => array( 50, 2 ) ),
		);

		return array(
			'storefront under core'    => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, false, $hooks ),
			'the extension owns'       => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true, false, false, array() ),
			'Subscriptions not loaded' => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false, array() ),
			'an admin request'         => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, false, array() ),
			'cron'                     => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, true, array() ),
		);
	}

	/**
	 * @testdox Should defer registration until subscriptions runtime loads.
	 */
	public function test_defers_registration_until_subscriptions_runtime_loads(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false );
		$sut->set_plugins_loaded( false );
		$this->deferred_controllers[] = $sut;

		$sut->register();

		$this->assertFalse( has_filter( 'wcpay_multi_currency_should_convert_product_price', array( $sut, 'should_convert_product_price' ) ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $sut, 'register_subscription_filters' ) ) );

		$sut->set_subscriptions_available( true );
		$sut->register_subscription_filters();

		$this->assertSame( 50, has_filter( 'wcpay_multi_currency_should_convert_product_price', array( $sut, 'should_convert_product_price' ) ) );
	}

	/**
	 * @testdox Should override selected currency from subscription cart items and switch requests.
	 */
	public function test_overrides_selected_currency_from_subscription_contexts(): void {
		foreach (
			array(
				'renewal'     => 'EUR',
				'resubscribe' => 'GBP',
				'switch'      => 'CAD',
			) as $type => $currency_code
		) {
			$sut = $this->create_controller();
			$sut->set_subscription_cart_item( $type, $this->create_subscription( $currency_code ) );

			$this->assertSame( $currency_code, $sut->override_selected_currency( false ) );
		}

		$switch_request = $this->create_controller();
		$switch_request->set_switch_request_subscription( $this->create_subscription( 'AUD' ) );

		$this->assertSame( 'AUD', $switch_request->override_selected_currency( false ) );
	}

	/**
	 * @testdox Should keep a renewal cart in its subscription's currency after the merchant disabled it.
	 *
	 * The cart holds the subscription's stored amounts in that currency; a recorded store run with GBP disabled charged a
	 * 7.45 GBP renewal as 7.45 USD. The merchant only edited the enabled list here, so GBP's manual rate is kept.
	 */
	public function test_keeps_a_renewal_cart_in_a_disabled_subscription_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.73' );
		$sut = $this->create_controller();
		$sut->set_subscription_cart_item( 'renewal', $this->create_subscription( 'GBP' ) );
		$sut->register();

		$selected = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build()->get_selected_currency();

		$this->assertSame( array( 'GBP', 0.73 ), array( $selected->get_code(), $selected->get_rate() ) );
	}

	/**
	 * @testdox Should preserve an existing selected currency override.
	 */
	public function test_preserves_existing_selected_currency_override(): void {
		$sut = $this->create_controller();
		$sut->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );

		$this->assertSame( 'GBP', $sut->override_selected_currency( 'GBP' ) );
	}

	/**
	 * @testdox Should pay a signup order again in its currency, read from the session cart, with switching off.
	 *
	 * WooCommerce Subscriptions 9.4.1 rebuilds a failed or pending signup order's cart with items keyed
	 * `subscription_initial_payment` holding the `order_id`. Client 11.3.0
	 * `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:479-506` (the order's currency) and
	 * `:1247-1255` (switching disabled).
	 */
	public function test_pays_a_signup_order_again_in_its_currency(): void {
		$order_id = $this->create_order_in_currency( 'EUR' );
		$sut      = $this->create_real_controller();
		WC()->cart->set_cart_contents( array() );
		WC()->session->set( 'cart', array( 'abc123' => $this->get_initial_payment_cart_item( $order_id ) ) );

		$this->assertSame( 'EUR', $sut->override_selected_currency( false ) );
		$this->assertTrue( $sut->should_disable_currency_switching( false ) );
	}

	/**
	 * @testdox Should keep a signup order paid again in its currency after the merchant disabled it.
	 *
	 * Modelled on the renewal row above: the order's currency is marked as an existing order's, so GBP at its manual 0.73
	 * is selected. Client 11.3.0 returns the bare code here and falls back to the store currency.
	 */
	public function test_keeps_a_signup_order_in_a_disabled_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.73' );
		$sut = $this->create_controller();
		$sut->set_initial_payment_cart_item( $this->create_order_in_currency( 'GBP' ) );
		$sut->register();

		$selected = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build()->get_selected_currency();

		$this->assertSame( array( 'GBP', 0.73 ), array( $selected->get_code(), $selected->get_rate() ) );
	}

	/**
	 * @testdox Should load the signup order once for every read in a request.
	 *
	 * The selected currency is asked on every price read, so the order's currency is kept per order (client 11.3.0
	 * `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:479-506`).
	 */
	public function test_loads_the_signup_order_once_per_request(): void {
		$order_id = $this->create_order_in_currency( 'EUR' );
		$sut      = $this->create_controller();
		$sut->set_initial_payment_cart_item( $order_id );
		$loads = 0;
		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_order' => static function ( $id ) use ( &$loads ) {
					++$loads;
					return wc_get_order( $id );
				},
			)
		);

		$this->assertSame( array( 'EUR', 'EUR' ), array( $sut->override_selected_currency( false ), $sut->override_selected_currency( false ) ) );
		$this->assertSame( 1, $loads );
	}

	/**
	 * @testdox Should skip a read nested in the signup order's load, and read again after it.
	 *
	 * Loading the order reads the selected currency again; that read returns what it was given (client 11.3.0
	 * `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:510-537`).
	 */
	public function test_skips_a_read_nested_in_the_signup_order_load(): void {
		$order_id = $this->create_order_in_currency( 'EUR' );
		$sut      = $this->create_controller();
		$sut->set_initial_payment_cart_item( $order_id );
		$nested = array();
		add_filter(
			'woocommerce_order_class',
			static function ( $classname ) use ( $sut, &$nested ) {
				if ( array() === $nested ) {
					$nested[] = $sut->override_selected_currency( false );
				}

				return $classname;
			}
		);
		wp_cache_flush();

		$this->assertSame( 'EUR', $sut->override_selected_currency( false ) );
		$this->assertSame( array( false ), $nested );
		$this->assertSame( 'EUR', $sut->override_selected_currency( false ) );
	}

	/**
	 * @testdox Should release the override after a signup order load throws.
	 */
	public function test_releases_the_override_after_a_signup_order_load_throws(): void {
		$sut = $this->create_controller();
		$sut->set_initial_payment_cart_item( $this->create_order_in_currency( 'EUR' ) );
		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_order' => static function () {
					throw new \Exception( 'Order read failed.' );
				},
			)
		);

		try {
			$sut->override_selected_currency( false );
			$this->fail( 'The order read was expected to throw.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'Order read failed.', $e->getMessage() );
		}
		$this->reset_legacy_proxy_mocks();

		$this->assertSame( 'EUR', $sut->override_selected_currency( false ) );
	}

	/**
	 * @testdox Should pay a signup order in its currency after a read made before post types are registered: $_dataName.
	 * @dataProvider provide_signup_order_currency_states
	 *
	 * The price filter widget's constructor reads the currency at widgets_init (init 1), before WooCommerce registers its
	 * post types (init 5), where wc_get_order() logs a notice and reads nothing. The store currency, USD, is selected.
	 *
	 * @param bool $eur_enabled Whether EUR is still enabled when the order is paid again.
	 */
	public function test_pays_a_signup_order_in_its_currency_after_a_read_before_post_types( bool $eur_enabled ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', $eur_enabled ? array( 'USD', 'EUR' ) : array( 'USD' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$sut = $this->create_controller();
		$sut->set_initial_payment_cart_item( $this->create_order_in_currency( 'EUR' ) );
		$sut->register();
		$state_builder = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder();

		$early_notices = 0;
		$count_notices = static function ( $function_name ) use ( &$early_notices ) {
			if ( 'wc_get_order' === $function_name ) {
				++$early_notices;
			}
		};
		add_action( 'doing_it_wrong_run', $count_notices );
		global $wp_actions;
		$registered = $wp_actions['woocommerce_after_register_post_type'] ?? null;
		// The read at widgets_init, before WooCommerce registers its post types.
		unset( $wp_actions['woocommerce_after_register_post_type'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		try {
			$early = $state_builder->build()->get_selected_currency()->get_code();
		} finally {
			if ( null !== $registered ) {
				$wp_actions['woocommerce_after_register_post_type'] = $registered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the count unset above.
			}
			remove_action( 'doing_it_wrong_run', $count_notices );
		}
		$selected = $state_builder->build()->get_selected_currency();

		$this->assertSame( 'USD', $early, 'Before post types exist the signup order is not read.' );
		$this->assertSame( array( 'EUR', 0.9 ), array( $selected->get_code(), $selected->get_rate() ), 'The rest of the request pays the signup order in its currency.' );
		$this->assertSame( 0, $early_notices, 'No order is read before post types are registered.' );
	}

	/** @return array<string,array{bool}> */
	public static function provide_signup_order_currency_states(): array {
		return array(
			'EUR enabled'                   => array( true ),
			'EUR disabled after the signup' => array( false ),
		);
	}

	/**
	 * @testdox Should find a $type item in the session cart after a lookup made before the request had a cart or a session.
	 * @testWith ["initial_payment"]
	 *           ["renewal"]
	 *
	 * A Store API request reads the selected currency at widgets_init, before it starts the session (rest_pre_dispatch) and
	 * loads the cart. That lookup has nothing to read, so its empty answer must not stand for the session cart.
	 *
	 * @param string $type Subscriptions cart type.
	 */
	public function test_finds_a_session_cart_item_after_a_lookup_before_the_session( string $type ): void {
		$item = 'initial_payment' === $type ? $this->get_initial_payment_cart_item( $this->create_order_in_currency( 'EUR' ) ) : array(
			'subscription_renewal' => array( 'subscription_id' => 31 ),
			'product_id'           => 0,
			'key'                  => 'abc123',
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'function_exists'      => static fn( string $function_name ): bool => 'wcs_get_subscription' === $function_name || function_exists( $function_name ),
				'wcs_get_subscription' => fn() => $this->create_subscription( 'EUR' ),
			)
		);
		$sut     = $this->create_real_controller();
		$cart    = WC()->cart;
		$session = WC()->session;
		$cart->set_cart_contents( array() );
		$session->set( 'cart', array( 'abc123' => $item ) );

		WC()->cart    = null;
		WC()->session = null;
		try {
			$early = $sut->override_selected_currency( false );
			// The request starts the session; the cart is not loaded yet.
			WC()->session = $session;
			$later        = $sut->override_selected_currency( false );
		} finally {
			WC()->cart    = $cart;
			WC()->session = $session;
		}

		$this->assertSame( array( false, 'EUR' ), array( $early, $later ) );
	}

	/**
	 * @testdox Should write the cart hash the Store API checkout checks on a signup order re-saved when the cart loads.
	 *
	 * WooCommerce Subscriptions 9.4.1 is not loaded in these tests, so its restore is modelled on the same hook, added
	 * before the integration's: at woocommerce_cart_loaded_from_session it names the failed signup order as the draft and
	 * re-saves it with the cart hash (`includes/core/class-wcs-cart-renewal.php:266-286`, `:1272-1302`). The Checkout route
	 * then reads the draft through DraftOrderTrait (`Checkout.php:847`), which reuses a failed order only when the hashes
	 * match. The selected currency is read first at widgets_init, before the session starts. USD is the store currency.
	 */
	public function test_writes_the_cart_hash_the_store_api_checks_on_a_signup_order_paid_again(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '9' ) );
		$order   = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '9.00' );
		$order->set_status( OrderStatus::FAILED );
		$order->save();
		$order_id = $order->get_id();
		$cart     = WC()->cart;
		$session  = WC()->session;
		$cart->set_cart_contents( array() );
		$session->set(
			'cart',
			array(
				'abc123' => array(
					'key'                          => 'abc123',
					'product_id'                   => $product->get_id(),
					'variation_id'                 => 0,
					'variation'                    => array(),
					'quantity'                     => 1,
					'subscription_initial_payment' => array( 'order_id' => $order_id ),
				),
			)
		);
		$session->set( 'cart_totals', array( 'total' => '9.00' ) );
		add_action(
			'woocommerce_cart_loaded_from_session',
			static function () use ( $order_id ) {
				WC()->session->set( 'store_api_draft_order', $order_id );
				$order = wc_get_order( $order_id );
				$order->set_cart_hash( WC()->cart->get_cart_hash() );
				$order->save();
			}
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( string $class_name, bool $autoload = true ): bool => 'WC_Subscriptions' === $class_name || class_exists( $class_name, $autoload ),
			)
		);
		$this->create_real_controller()->register();
		add_filter( 'woocommerce_cart_hash', array( wc_get_container()->get( ServiceFactory::class )->create_display_service(), 'add_currency_to_cart_hash' ), 900 );
		$draft_orders = new class() {
			use DraftOrderTrait;

			/**
			 * Read the draft order as the Checkout route does.
			 *
			 * @return \WC_Order|null
			 */
			public function read(): ?\WC_Order {
				return $this->get_draft_order();
			}
		};

		WC()->cart    = null;
		WC()->session = null;
		try {
			$early = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build()->get_selected_currency()->get_code();
			// rest_pre_dispatch starts the session, then the Store API loads the cart from it.
			WC()->session = $session;
			WC()->cart    = $cart;
			( new \WC_Cart_Session( $cart ) )->get_cart_from_session();
		} finally {
			WC()->cart    = $cart;
			WC()->session = $session;
		}
		$checked_hash = $cart->get_cart_hash();
		$draft        = $draft_orders->read();
		$base         = md5( wp_json_encode( ( new \WC_Cart_Session( $cart ) )->get_cart_for_session() ) . $cart->get_total( 'edit' ) );

		$this->assertSame( 'USD', $early, 'The read before the session has no signup order to pay.' );
		$this->assertSame( array( md5( $base . 'EUR0.9' ), md5( $base . 'EUR0.9' ) ), array( wc_get_order( $order_id )->get_cart_hash(), $checked_hash ), 'The re-saved order holds the hash the checkout computes.' );
		$this->assertSame( $order_id, $draft ? $draft->get_id() : null, 'The checkout reuses the failed signup order.' );
	}

	/**
	 * @testdox Should leave the selection alone when the cart pays no readable signup order: $_dataName.
	 * @dataProvider provide_carts_without_a_signup_order_currency
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:539-546` (a missing
	 * order); an incoming currency and a renewal item are asked first (client
	 * `includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:205-233`).
	 *
	 * @param string       $cart     Cart: 'missing order', 'renewal first' or 'ordinary'.
	 * @param string|false $incoming Currency the filter receives.
	 * @param string|false $expected Currency the override returns.
	 * @param bool         $switches Whether the switcher stays on.
	 */
	public function test_leaves_the_selection_without_a_signup_order_currency( string $cart, $incoming, $expected, bool $switches ): void {
		$sut = $this->create_controller();
		if ( 'ordinary' !== $cart ) {
			$sut->set_initial_payment_cart_item( 'missing order' === $cart ? PHP_INT_MAX : $this->create_order_in_currency( 'EUR' ) );
		}
		if ( 'renewal first' === $cart ) {
			$sut->set_subscription_cart_item( 'renewal', $this->create_subscription( 'GBP' ) );
		}

		$this->assertSame( $expected, $sut->override_selected_currency( $incoming ) );
		$this->assertSame( ! $switches, $sut->should_disable_currency_switching( false ) );
	}

	/** @return array<string,array{string,string|false,string|false,bool}> */
	public static function provide_carts_without_a_signup_order_currency(): array {
		return array(
			'a missing signup order'          => array( 'missing order', false, false, false ),
			'an incoming currency'            => array( 'signup order', 'JPY', 'JPY', false ),
			'a renewal item before the order' => array( 'renewal first', false, 'GBP', false ),
			'an ordinary cart'                => array( 'ordinary', false, false, true ),
		);
	}

	/**
	 * @testdox Should override selected currency while a My Account subscription total is formatting.
	 */
	public function test_overrides_selected_currency_while_my_account_subscription_total_is_formatting(): void {
		$sut          = $this->create_controller();
		$subscription = $this->create_subscription( 'EUR' );
		$details      = array( 'price' => 'placeholder' );
		$calls        = array();
		add_filter(
			'wcpay_multi_currency_should_output_explicit_price',
			static function ( bool $current_default ) use ( &$calls ): bool {
				$calls[] = $current_default;
				return ! $current_default;
			}
		);

		CallStack::run(
			array( 'WC_Subscription->get_formatted_order_total' ),
			function () use ( $sut, $details, $subscription ): void {
				$this->assertSame( $details, $sut->maybe_set_current_my_account_subscription( $details, $subscription ) );
				$this->assertSame( 'EUR', $sut->override_selected_currency( false ) );
				$this->assertSame( '<span>$10.00</span> EUR', $sut->maybe_get_explicit_format_for_subscription_total( '<span>$10.00</span>' ) );
				$this->assertSame( '<span>$10.00</span> EUR', $sut->maybe_clear_current_my_account_subscription( '<span>$10.00</span> EUR', $subscription ) );
				$this->assertFalse( $sut->override_selected_currency( false ) );
			}
		);
		$this->assertSame( array(), $calls );
	}

	/**
	 * @testdox Should not set current My Account subscription outside subscription total formatting.
	 */
	public function test_does_not_set_current_my_account_subscription_outside_total_formatting(): void {
		$sut          = $this->create_controller();
		$subscription = $this->create_subscription( 'EUR' );
		$details      = array( 'price' => 'placeholder' );

		$this->assertSame( $details, $sut->maybe_set_current_my_account_subscription( $details, $subscription ) );
		$this->assertFalse( $sut->override_selected_currency( false ) );
		$this->assertSame( '<span>$10.00</span>', $sut->maybe_get_explicit_format_for_subscription_total( '<span>$10.00</span>' ) );
	}

	/**
	 * @testdox Should leave a My Account subscription total's explicit format as it is: $_dataName.
	 * @dataProvider provide_unchanged_subscription_totals
	 *
	 * @param bool               $additional_currencies Whether additional currencies are enabled.
	 * @param string             $code                  The subscription's currency code.
	 * @param string[]           $calls                 Calls on the stack while the total formats.
	 * @param string             $html                  Total markup.
	 * @param array<int,mixed[]> $overrides            Selected currency in and expected override out.
	 */
	public function test_leaves_a_subscription_total_without_a_needed_code_unchanged( bool $additional_currencies, string $code, array $calls, string $html, array $overrides ): void {
		$sut          = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, false, $additional_currencies );
		$subscription = $this->create_subscription( $code );
		CallStack::run(
			$calls,
			function () use ( $sut, $subscription, $html, $overrides ): void {
				$sut->maybe_set_current_my_account_subscription( array(), $subscription );

				$this->assertSame( $html, $sut->maybe_get_explicit_format_for_subscription_total( $html ) );
				foreach ( $overrides as $override ) {
					$this->assertSame( $override[1], $sut->override_selected_currency( $override[0] ) );
				}
			}
		);
	}

	/**
	 * Totals the explicit format leaves alone: one that already shows its code (under the My Account template, which sets
	 * the subscription the row formats), one while additional currencies are disabled, and one for a subscription without
	 * a currency code, which is no currency to switch to (both under the subscription's own total formatting).
	 *
	 * @return array<string,array{0:bool,1:string,2:string[],3:string,4:array<int,mixed[]>}>
	 */
	public static function provide_unchanged_subscription_totals(): array {
		return array(
			'the code already shown'         => array( true, 'EUR', array( 'WCS_Template_Loader::get_my_subscriptions' ), '<span>$10.00 EUR</span>', array( array( 'GBP', 'GBP' ), array( false, 'EUR' ) ) ),
			'additional currencies disabled' => array( false, 'EUR', array( 'WC_Subscription->get_formatted_order_total' ), '<span>$10.00</span>', array() ),
			'a subscription without a code'  => array( true, ' ', array( 'WC_Subscription->get_formatted_order_total' ), '<span>$10.00</span>', array( array( false, false ) ) ),
		);
	}

	/**
	 * @testdox Should disable switching for subscription cart or switch request contexts.
	 */
	public function test_disables_switching_for_subscription_contexts(): void {
		$sut = $this->create_controller();

		$this->assertFalse( $sut->should_disable_currency_switching( false ) );
		$this->assertTrue( $sut->should_disable_currency_switching( true ) );

		$sut->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'EUR' ) );

		$this->assertTrue( $sut->should_disable_currency_switching( false ) );

		$switch_request = $this->create_controller();
		$switch_request->set_switch_request_subscription( $this->create_subscription( 'AUD' ) );

		$this->assertTrue( $switch_request->should_disable_currency_switching( false ) );
	}

	/**
	 * @testdox Should keep a resubscribe cart's prices while Subscriptions sets up the cart.
	 *
	 * Subscriptions sets up a resubscribe cart through the setup_cart() it inherits from the renewal cart
	 * (Subscriptions 9.3.0 `includes/core/class-wcs-cart-resubscribe.php:16,110`, `class-wcs-cart-renewal.php:298,370`), so the
	 * renewal setup call is on the stack; client 11.1.0 lets only renewal carts convert there
	 * (`includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:226-243`).
	 */
	public function test_keeps_resubscribe_cart_prices_while_the_cart_is_set_up(): void {
		$resubscribe = $this->create_controller();
		$resubscribe->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'GBP' ), 1 );

		$this->assertFalse( CallStack::run( array( 'WCS_Cart_Renewal->setup_cart', 'WC_Cart_Totals->calculate_item_totals' ), fn() => $resubscribe->should_convert_product_price( true, $this->create_product( 1 ) ) ) );
	}

	/**
	 * @testdox Should apply subscription product conversion decisions.
	 */
	public function test_applies_subscription_product_conversion_decisions(): void {
		$product = (object) array( 'id' => 1 );
		$sut     = $this->create_controller();

		$this->assertTrue( $sut->should_convert_product_price( true, $product ) );
		$this->assertFalse( $sut->should_convert_product_price( false, $product ) );

		$renewal = $this->create_controller();
		$renewal->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );
		$this->assertFalse( CallStack::run( array( 'WC_Cart_Totals->calculate_item_totals' ), fn() => $renewal->should_convert_product_price( true, $product ) ) );
		$this->assertTrue( $renewal->should_convert_product_price( true, $product ), 'Outside a price calculation the renewal cart converts.' );

		$renewal_setup = $this->create_controller();
		$renewal_setup->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );

		$this->assertTrue( CallStack::run( array( 'WCS_Cart_Renewal->setup_cart', 'WC_Cart_Totals->calculate_item_totals' ), fn() => $renewal_setup->should_convert_product_price( true, $product ) ) );

		$resubscribe = $this->create_controller();
		$resubscribe->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'GBP' ), 1 );

		$this->assertFalse( CallStack::run( array( 'WC_Cart->get_product_subtotal' ), fn() => $resubscribe->should_convert_product_price( true, $this->create_product( 1 ) ) ) );
	}

	/**
	 * @testdox Should convert the other products of a resubscribe cart while totals calculate.
	 *
	 * With mixed checkout on, WooCommerce Subscriptions 9.3.0 keeps other products in a resubscribe cart (only renewal carts are
	 * isolated, `includes/core/class-wc-subscriptions-cart-validator.php:57,82,207-211`). Only the resubscribed item holds the
	 * subscription's stored price; a simple product and a new subscription bought beside it are priced in the store currency
	 * and convert. A recorded store run at GBP 0.73 charged them 10.00 and 20.00 a month where 7.45 and 14.95 were due. The
	 * fixture converts by doubling.
	 */
	public function test_converts_the_other_products_of_a_resubscribe_cart_while_totals_calculate(): void {
		$sut = $this->create_controller();
		$sut->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'GBP' ), 21 );
		$sut->register();
		$this->register_frontend_prices();
		$resubscribed     = $this->create_priced_product( 21, '10' );
		$simple           = $this->create_priced_product( 22, '10' );
		$new_subscription = $this->create_priced_product( 23, '20' );

		$prices = CallStack::run(
			array( 'WC_Cart_Totals->calculate_item_totals' ),
			// Subscriptions reads a subscription product's recurring price through its own filter (`includes/core/class-wc-subscriptions-product.php:570`).
			static fn() => array( $resubscribed->get_price(), $simple->get_price(), apply_filters( 'woocommerce_subscriptions_product_price', '20', $new_subscription ) )
		);

		$this->assertSame( '10', $prices[0], 'The resubscribed item keeps its stored price.' );
		$this->assertSame( '20', $prices[1], 'A simple product beside it converts.' );
		$this->assertSame( 40.0, $prices[2], 'A new subscription beside it converts its recurring price.' );
	}

	/**
	 * @testdox Should find every resubscribed item in the cart, or in the session cart before the cart loads.
	 *
	 * A resubscribe cart holds one item per line of the old subscription, each keyed `subscription_resubscribe` (Subscriptions
	 * 9.3.0 `includes/core/class-wcs-cart-renewal.php:298-370`, `class-wcs-cart-resubscribe.php:19`). Totals read each line
	 * through its own product object, so a plain line of the same product, which a cart merged at login can hold
	 * (`includes/core/class-wc-subscriptions-cart-validator.php:409-415`), converts; the session cart holds only ids.
	 */
	public function test_finds_every_resubscribed_item_in_the_cart_or_the_session_cart(): void {
		$resubscribe   = array( 'subscription_resubscribe' => array( 'subscription_id' => 31 ) );
		$resubscribed  = $this->create_priced_product( 21, '10' );
		$variation     = $this->create_priced_product( 25, '10' );
		$other         = $this->create_priced_product( 22, '10' );
		$plain_line    = $this->create_priced_product( 21, '10' );
		$session_items = array(
			'a' => array(
				'product_id'   => 21,
				'variation_id' => 0,
			) + $resubscribe,
			'b' => array(
				'product_id'   => 24,
				'variation_id' => 25,
			) + $resubscribe,
			'c' => array(
				'product_id'   => 22,
				'variation_id' => 0,
			),
		);
		$cart_items    = array(
			'a' => array( 'data' => $resubscribed ) + $session_items['a'],
			'b' => array( 'data' => $variation ) + $session_items['b'],
			'c' => array( 'data' => $other ) + $session_items['c'],
			'd' => array(
				'data'       => $plain_line,
				'product_id' => 21,
			) + $session_items['c'],
		);
		$products      = array( $resubscribed, $variation, $other, $plain_line );
		$decisions     = function () use ( $products ): array {
			$sut = new WooCommerceSubscriptions();
			$sut->init(
				$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
				new LegacyProxy(),
				wc_get_container()->get( StateBuilderFactory::class ),
				wc_get_container()->get( ServiceFactory::class ),
				CallStack::request_context()
			);

			return CallStack::run( array( 'WC_Cart_Totals->calculate_item_totals' ), static fn() => array_map( static fn( $product ) => $sut->should_convert_product_price( true, $product ), $products ) );
		};

		WC()->cart->set_cart_contents( $cart_items );
		$this->assertSame( array( false, false, true, true ), $decisions(), 'Cart' );

		WC()->cart->set_cart_contents( array() );
		WC()->session->set( 'cart', $session_items );
		$this->assertSame( array( false, false, true, false ), $decisions(), 'Session cart' );
	}

	/**
	 * @testdox Should convert direct subscription product prices through the native projection service.
	 */
	public function test_converts_direct_subscription_product_prices_through_the_price_converter(): void {
		$product = $this->create_product( 10 );
		$sut     = $this->create_controller();

		$this->assertSame( 20.0, $sut->get_subscription_product_price( '10.00', $product ) );
		$this->assertSame( 0, $sut->get_subscription_product_price( 0, $product ) );

		$renewal = $this->create_controller();
		$renewal->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );
		$this->assertSame( '10.00', CallStack::run( array( 'WC_Cart_Totals->calculate_item_totals' ), fn() => $renewal->get_subscription_product_price( '10.00', $product ) ) );
	}

	/**
	 * @testdox Should convert subscription sign-up fees through the native projection service.
	 */
	public function test_converts_subscription_signup_fees_through_the_price_converter(): void {
		$product = $this->create_product( 10 );
		$sut     = $this->create_controller();

		$this->assertSame( 20.0, $sut->get_subscription_product_signup_fee( '10.00', $product ) );
		$this->assertSame( 0, $sut->get_subscription_product_signup_fee( 0, $product ) );
	}

	/**
	 * @testdox Should preserve switch subscription sign-up fees during price setup.
	 */
	public function test_preserves_switch_subscription_signup_fees_during_price_setup(): void {
		$product = $this->create_product( 10, false, true );
		$sut     = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );
		$this->assertSame( '10.00', CallStack::run( array( 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation' ), fn() => $sut->get_subscription_product_signup_fee( '10.00', $product ) ) );
	}

	/**
	 * @testdox Should preserve repeated switch sign-up fees during proration total calculation.
	 */
	public function test_preserves_repeated_switch_signup_fees_during_proration_total_calculation(): void {
		$product = $this->create_product( 10, false, true );
		$sut     = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );

		$this->assertSame( 20.0, $sut->get_subscription_product_signup_fee( '10.00', $product ) );

		$this->assertSame( '10.00', CallStack::run( array( 'WC_Subscriptions_Product::get_sign_up_fee', 'WC_Cart->calculate_totals' ), fn() => $sut->get_subscription_product_signup_fee( '10.00', $product ) ) );
	}

	/**
	 * @testdox Should convert repeated switch sign-up fees while apportioning switch totals.
	 */
	public function test_converts_repeated_switch_signup_fees_while_apportioning_switch_totals(): void {
		$product = $this->create_product( 10, false, true );
		$sut     = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );

		$this->assertSame( 20.0, $sut->get_subscription_product_signup_fee( '10.00', $product ) );

		$this->assertSame( 20.0, CallStack::run( array( 'WC_Subscriptions_Product::get_sign_up_fee', 'WC_Cart->calculate_totals', 'WCS_Switch_Totals_Calculator->apportion_sign_up_fees' ), fn() => $sut->get_subscription_product_signup_fee( '10.00', $product ) ) );
	}

	/**
	 * @testdox Should preserve repeated switch sign-up fees after product meta has already changed.
	 */
	public function test_preserves_repeated_switch_signup_fees_after_product_meta_has_already_changed(): void {
		$product = $this->create_product( 10, true );
		$sut     = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );

		$this->assertSame( 20.0, $sut->get_subscription_product_signup_fee( '10.00', $product ) );

		$this->assertSame( '10.00', $sut->get_subscription_product_signup_fee( '10.00', $product ) );
	}

	/**
	 * @testdox Should charge a switch's sign-up fee converted once, for each sign-up fee and proration setting.
	 *
	 * Each row replays the sign-up fee reads WooCommerce Subscriptions 9.3.0 makes while a switch cart calculates, with the
	 * calls on the stack at each read and the fee meta writes in between: the price setup adds the fee to the line price
	 * (`includes/core/class-wc-subscriptions-cart.php:215-236`), the totals calculator writes a dropped fee or the fee plus the
	 * prorated upgrade cost, both from converted reads (`includes/switching/class-wcs-switch-totals-calculator.php:306-333,510-530`).
	 * The fixture converts by doubling, so a fee of 5 converts to 10.0. Under the full fee without proration the calculator writes
	 * nothing, so every read holds the stored fee and must convert; where it wrote, the reads that follow hold a converted amount.
	 *
	 * @dataProvider switch_sign_up_fee_read_sequences
	 *
	 * @param array<string,string> $stored_meta Meta the product stores before the switch.
	 * @param array<mixed>         $steps       Reads (`read`, calls, raw fee, expected fee) and meta writes (`write`, key, value) in order.
	 */
	public function test_charges_a_switch_sign_up_fee_converted_once( array $stored_meta, array $steps ): void {
		$product = new \WC_Product_Simple();
		foreach ( $stored_meta as $key => $value ) {
			$product->add_meta_data( $key, $value, true );
		}
		// Set after the meta, so the product reads no meta from the database.
		$product->set_id( 10 );
		$sut = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );

		foreach ( $steps as $index => $step ) {
			if ( 'write' === $step[0] ) {
				$product->update_meta_data( $step[1], $step[2] );
				continue;
			}

			$this->assertSame( $step[3], CallStack::run( $step[1], fn() => $sut->get_subscription_product_signup_fee( $step[2], $product ) ), 'Read ' . $index );
		}
	}

	/**
	 * Sign-up fee read sequences of a switch from a $10 to a $20 plan with a $5 fee, per setting.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<mixed>}>
	 */
	public function switch_sign_up_fee_read_sequences(): array {
		$set_prices = array( 'WC_Cart->calculate_totals', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 'WC_Subscriptions_Product::get_sign_up_fee' );
		$fee_read   = array( 'WC_Cart->calculate_totals', 'WC_Subscriptions_Product::get_sign_up_fee' );
		$apportion  = array( 'WC_Cart->calculate_totals', 'WCS_Switch_Totals_Calculator->apportion_sign_up_fees', 'WC_Subscriptions_Product::get_sign_up_fee' );
		$stored_fee = array( '_subscription_sign_up_fee' => '5' );

		return array(
			'full fee, no proration'                     => array(
				$stored_fee,
				array(
					array( 'read', $set_prices, '5', 10.0 ),
					array( 'read', $set_prices, '5', 10.0 ),
					array( 'read', $fee_read, '5', 10.0 ),
				),
			),
			'full fee, prorated upgrade'                 => array(
				$stored_fee,
				array(
					array( 'read', $fee_read, '5', 10.0 ),
					array( 'write', '_subscription_sign_up_fee_prorated', 10.0 ),
					array( 'write', '_subscription_price_prorated', 7.5 ),
					array( 'write', '_subscription_sign_up_fee', 17.5 ),
					array( 'read', $set_prices, 17.5, 17.5 ),
					array( 'read', $fee_read, 17.5, 17.5 ),
					array( 'write', '_subscription_sign_up_fee', 10.0 ),
					array( 'read', $fee_read, 10.0, 10.0 ),
					array( 'write', '_subscription_sign_up_fee', 17.5 ),
					array( 'read', $set_prices, 17.5, 17.5 ),
				),
			),
			'no fee, no proration'                       => array(
				$stored_fee,
				array(
					array( 'write', '_subscription_sign_up_fee', 0 ),
					array( 'read', $set_prices, 0, 0 ),
					array( 'read', $fee_read, 0, 0 ),
				),
			),
			'no fee, prorated upgrade'                   => array(
				$stored_fee,
				array(
					array( 'write', '_subscription_sign_up_fee', 0 ),
					array( 'read', $fee_read, 0, 0 ),
					array( 'write', '_subscription_sign_up_fee_prorated', 0 ),
					array( 'write', '_subscription_price_prorated', 7.5 ),
					array( 'write', '_subscription_sign_up_fee', 7.5 ),
					array( 'read', $set_prices, 7.5, 7.5 ),
					array( 'read', $fee_read, 7.5, 7.5 ),
				),
			),
			'prorated upgrade, no stored fee meta'       => array(
				array(),
				// Without fee meta, Subscriptions' get_sign_up_fee() returns its default without calling the product's meta getter, so
				// the filter sees no read before the calculator writes (Subscriptions 9.3.0 `class-wc-subscriptions-product.php:671,1342-1369`).
				array(
					array( 'write', '_subscription_sign_up_fee_prorated', 0 ),
					array( 'write', '_subscription_price_prorated', 7.5 ),
					array( 'write', '_subscription_sign_up_fee', 7.5 ),
					array( 'read', $set_prices, 7.5, 7.5 ),
				),
			),
			'prorated fee'                               => array(
				$stored_fee,
				// "Prorate the sign up fee": the calculator reads the fee, writes the remaining part from that converted read, then
				// reads it back as the prorated fee (`class-wcs-switch-totals-calculator.php:306-328`).
				array(
					array( 'read', $apportion, '5', 10.0 ),
					array( 'write', '_subscription_sign_up_fee', 6.0 ),
					array( 'read', $apportion, 6.0, 6.0 ),
					array( 'write', '_subscription_sign_up_fee_prorated', 6.0 ),
					array( 'read', $fee_read, 6.0, 6.0 ),
					array( 'read', $set_prices, 6.0, 6.0 ),
				),
			),
			'full fee, no proration, length apportioned' => array(
				$stored_fee + array( '_subscription_length' => '12' ),
				// Apportioning the length rewrites the length meta, not the fee (`class-wcs-switch-totals-calculator.php:442-465`).
				array(
					array( 'write', '_subscription_length', 11 ),
					array( 'read', $set_prices, '5', 10.0 ),
					array( 'read', $set_prices, '5', 10.0 ),
					array( 'read', $fee_read, '5', 10.0 ),
				),
			),
		);
	}

	/**
	 * @testdox Should convert an on-sale subscription product's active price once.
	 *
	 * Replays `WC_Subscriptions_Product::get_price()` (WooCommerce Subscriptions 9.3.0
	 * `includes/core/class-wc-subscriptions-product.php:553-571`): it compares the sale price, read through its own filter
	 * (`:598-606`), with the stored subscription price, and passes the price it picks through
	 * `woocommerce_subscriptions_product_price`, which converts. A switch prorates from that price
	 * (`includes/switching/class-wcs-switch-cart-item.php:220`). Product: subscription price 10, regular price 10, sale price 8.
	 * A recorded store run at GBP 0.73 charged an upgrade to such a product 0.50 where about 3.50 was due. In the store currency
	 * nothing converts, so a pricing plugin's sale price is used as it is.
	 * @dataProvider on_sale_subscription_prices
	 *
	 * @param float  $rate               Rate the fixture converts by.
	 * @param string $subscription_price Stored subscription price.
	 * @param string $sale               Sale price.
	 * @param float  $expected           Active price.
	 * @param string $plugin_sale        Sale price a pricing plugin supplies through the product getter, if any.
	 */
	public function test_converts_an_on_sale_subscription_price_once( float $rate, string $subscription_price, string $sale, float $expected, string $plugin_sale = '' ): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, false, true, $rate );
		$sut->register();
		$this->register_frontend_prices( $rate );
		$product = new \WC_Product_Simple();
		$product->add_meta_data( '_subscription_price', $subscription_price, true );
		$product->set_regular_price( '10' );
		$product->set_sale_price( $sale );
		$product->set_price( '' === $sale ? '10' : $sale );
		// Set after the meta, so the product reads no meta from the database.
		$product->set_id( 10 );
		if ( '' !== $plugin_sale ) {
			add_filter( 'woocommerce_product_get_sale_price', static fn() => $plugin_sale );
		}

		$active_price = CallStack::run(
			array( 'WC_Subscriptions_Product::get_price' ),
			static function () use ( $product ) {
				$subscription_price = $product->get_meta( '_subscription_price' );
				$sale_price         = apply_filters( 'woocommerce_subscriptions_product_sale_price', $product->get_sale_price(), $product );
				$active_price       = $subscription_price ? $subscription_price : apply_filters( 'woocommerce_subscriptions_product_regular_price', $product->get_regular_price(), $product );
				if ( $product->is_on_sale() && '' !== $sale_price && $subscription_price > $sale_price ) {
					$active_price = $sale_price;
				}

				return apply_filters( 'woocommerce_subscriptions_product_price', $active_price, $product );
			}
		);

		$this->assertSame( $expected, $active_price );
		$this->assertSame( '16', apply_filters( 'woocommerce_subscriptions_product_sale_price', '16', $product ), 'Outside the active price lookup the sale price is left as it is.' );
	}

	/**
	 * Rates on both sides of 1, which convert a sale price below and above the stored subscription price, the regular price
	 * the lookup falls back to without a stored subscription price, and the store currency.
	 *
	 * @return array<string,array<int,float|string>>
	 */
	public function on_sale_subscription_prices(): array {
		return array(
			'rate above 1, sale kept'                    => array( 2.0, '10', '8', 16.0 ),
			'rate below 1, sale kept'                    => array( 0.5, '10', '8', 4.0 ),
			'not on sale'                                => array( 2.0, '10', '', 20.0 ),
			'no stored subscription price'               => array( 2.0, '', '', 20.0 ),
			'store currency, sale from a pricing plugin' => array( 1.0, '10', '', 8.0, '8' ),
		);
	}

	/**
	 * @testdox Should apply subscription coupon conversion decisions.
	 *
	 * A recurring coupon applied in a renewal cart converts like any fixed coupon (client 11.3.0
	 * `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:1017-1036`).
	 */
	public function test_applies_subscription_coupon_conversion_decisions(): void {
		$sut = $this->create_controller();

		$this->assertTrue( $sut->should_convert_coupon_amount( true, $this->create_coupon( 'fixed_cart' ) ) );
		$this->assertFalse( $sut->should_convert_coupon_amount( false, $this->create_coupon( 'fixed_cart' ) ) );
		$this->assertFalse( $sut->should_convert_coupon_amount( true, $this->create_coupon( 'recurring_percent' ) ) );

		$renewal = $this->create_controller();
		$renewal->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );
		CallStack::run(
			array( 'WC_Discounts->apply_coupon' ),
			function () use ( $renewal ): void {
				$this->assertTrue( $renewal->should_convert_coupon_amount( true, $this->create_coupon( 'renewal_fee' ) ) );
				$this->assertTrue( $renewal->should_convert_coupon_amount( true, $this->create_coupon( 'fixed_cart' ) ) );
			}
		);

		$early_renewal = $this->create_controller();
		$early_renewal->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );
		$this->assertTrue( CallStack::run( array( 'WCS_Cart_Early_Renewal->setup_cart', 'WC_Discounts->apply_coupon' ), fn() => $early_renewal->should_convert_coupon_amount( true, $this->create_coupon( 'renewal_fee' ) ) ) );
	}

	/**
	 * @testdox Should apply a coupon copied into the session before the copies were marked at the amount, minimum and maximum it holds.
	 *
	 * WooCommerce Subscriptions 9.4.1 sets up renewal and initial payment carts by storing each coupon's view-context amount,
	 * minimum and maximum in the session (`includes/core/class-wcs-cart-renewal.php:1115-1170`) and rebuilding the coupon from
	 * there on every later read, as a virtual coupon (`:1048-1084`); a manual or deleted discount becomes a `renewal_cart` or
	 * `initial_cart` coupon holding the order's discount (`:1774-1918`). Copies stored before they were marked, by an earlier
	 * version of either runtime, were converted when they were stored, and an order discount is already in the order's currency,
	 * so neither converts again (client 11.3.0 `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:1040-1048`,
	 * `:1111-1118`). Database, typed-in and other plugins' virtual coupons convert. Amounts are from a recorded store run
	 * at GBP 0.73: a 10.00 coupon stored as 7.30 with a 50.00 minimum stored as 36.45 (the 73.00 maximum is a synthetic value), and a
	 * 5.00 manual discount. The fixture converts by doubling, which exceeds a 50.00/60.00 band's max/min ratio: rebuilding such a
	 * coupon must keep its maximum.
	 *
	 * @dataProvider subscription_cart_coupons
	 *
	 * @param string|null          $cart_type Subscription cart type, or null for an initial payment cart.
	 * @param string[]             $calls     Calls on the stack while the coupon is read.
	 * @param string               $origin    `session` (rebuilt from the session), `virtual`, `virtual beside stored`, `order` or `database`.
	 * @param array<string,string> $props     Coupon properties.
	 * @param string               $amount    Expected amount.
	 * @param string[]             $limits    Expected minimum and maximum spend.
	 */
	public function test_applies_a_coupon_copied_before_the_mark_at_the_amount_it_holds( ?string $cart_type, array $calls, string $origin, array $props, string $amount, array $limits ): void {
		$sut = $this->create_controller();
		if ( null !== $cart_type ) {
			$sut->set_subscription_cart_item( $cart_type, $this->create_subscription( 'GBP' ) );
		}
		$sut->register();
		$this->register_frontend_prices();
		$coupon = $this->create_subscription_cart_coupon( $origin, $props );

		$read = CallStack::run( $calls, static fn() => array( $coupon->get_amount(), $coupon->get_minimum_amount(), $coupon->get_maximum_amount() ) );

		$this->assertSame( $amount, $read[0], 'Amount' );
		$this->assertSame( $limits, array( $read[1], $read[2] ), 'Minimum and maximum spend' );
	}

	/**
	 * Coupons a renewal or initial payment cart reads in the set-up frame, applied, and turned into the order's coupon line, with
	 * the session holding unmarked copies.
	 *
	 * @return array<string,array<mixed>>
	 */
	public function subscription_cart_coupons(): array {
		$setup        = array( 'WCS_Cart_Renewal->setup_cart' );
		$apply        = array( 'WC_Discounts->apply_coupon' );
		$stored       = array(
			'code'           => 'ten',
			'discount_type'  => 'fixed_cart',
			'amount'         => '7.30',
			'minimum_amount' => '36.45',
			'maximum_amount' => '73.00',
		);
		$order_amount = array(
			'code'   => 'discount_renewal',
			'amount' => '5.00',
		);
		$database     = array(
			'code'           => 'other',
			'discount_type'  => 'fixed_cart',
			'amount'         => '10.00',
			'minimum_amount' => '50.00',
			'maximum_amount' => '60.00',
		);
		$tight_band   = array(
			'minimum_amount' => '50.00',
			'maximum_amount' => '60.00',
		) + $stored;

		return array(
			'renewal, manual discount, set-up frame'     => array( 'renewal', $setup, 'order', $order_amount + array( 'discount_type' => 'renewal_cart' ), '5.00', array( '0', '0' ) ),
			'renewal, manual discount, applied'          => array( 'renewal', $apply, 'session', $order_amount + array( 'discount_type' => 'renewal_cart' ), '5.00', array( '0', '0' ) ),
			'initial payment, manual discount, set-up frame' => array( null, $setup, 'order', array( 'code' => 'discount_initial' ) + $order_amount + array( 'discount_type' => 'initial_cart' ), '5.00', array( '0', '0' ) ),
			'initial payment, manual discount, applied'  => array( null, $apply, 'session', array( 'code' => 'discount_initial' ) + $order_amount + array( 'discount_type' => 'initial_cart' ), '5.00', array( '0', '0' ) ),
			'initial payment, stored coupon, applied'    => array( null, $apply, 'session', $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, stored non-recurring coupon, applied' => array( 'renewal', $apply, 'session', $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, stored renewal coupon, applied'    => array( 'renewal', $apply, 'session', array( 'discount_type' => 'renewal_fee' ) + $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, stored coupon above its max/min ratio, applied' => array( 'renewal', $apply, 'session', $tight_band, '7.30', array( '50.00', '60.00' ) ),
			'renewal, stored coupon, order coupon line'  => array( 'renewal', array(), 'session', $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, database coupon, set-up frame'     => array( 'renewal', $setup, 'database', $database, '20', array( '100', '120' ) ),
			'initial payment, database coupon, set-up frame' => array( null, $setup, 'database', $database, '20', array( '100', '120' ) ),
			'initial payment, coupon typed in, applied'  => array( null, $apply, 'database', $database, '20', array( '100', '120' ) ),
			'recurring percent coupon, limits convert'   => array( null, $apply, 'database', array( 'discount_type' => 'recurring_percent' ) + $database, '10.00', array( '100', '120' ) ),
			'renewal, another plugin\'s virtual coupon'  => array( 'renewal', $apply, 'virtual', $database, '20', array( '100', '120' ) ),
			'renewal, virtual coupon beside stored ones' => array( 'renewal', $apply, 'virtual beside stored', $database, '20', array( '100', '120' ) ),
		);
	}

	/**
	 * @testdox Should read a coupon at its own amounts while Subscriptions copies it into the session, and convert it otherwise: $_dataName.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:957-1011`, `:1163-1205`: while
	 * a cart setup action runs and `WCS_Cart_Renewal->store_coupon` is on the stack, neither the amount nor the spend limits
	 * convert; a read outside that call during the setup, such as the coupon's validation, converts, and so does a read in that
	 * call outside a setup action. The fixture converts by doubling.
	 *
	 * @dataProvider provide_cart_setup_actions
	 *
	 * @param string $action Cart setup action Subscriptions fires.
	 */
	public function test_reads_a_coupon_at_its_own_amounts_while_subscriptions_copies_it( string $action ): void {
		$sut = $this->create_controller();
		$sut->set_subscription_cart_item( 'renewal', $this->create_subscription( 'GBP' ) );
		$sut->register();
		$this->register_frontend_prices();
		$coupon = $this->create_subscription_cart_coupon( 'database', array( 'code' => 'ten' ) + self::COUPON_COPY );
		$store  = array( 'WCS_Cart_Renewal->store_coupon' );
		$reads  = array();
		add_action(
			$action,
			function () use ( $coupon, $store, &$reads ): void {
				$reads['copied during the setup']    = $this->read_coupon( $store, $coupon );
				$reads['validated during the setup'] = $this->read_coupon( array( 'WC_Discounts->apply_coupon' ), $coupon );
			}
		);

		do_action( $action ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Subscriptions' action, fired as it fires it.
		$reads['copied outside a setup'] = $this->read_coupon( $store, $coupon );

		$this->assertSame(
			array(
				'copied during the setup'    => array( '10.00', '50.00', '60.00' ),
				'validated during the setup' => array( '20', '100', '120' ),
				'copied outside a setup'     => array( '20', '100', '120' ),
			),
			$reads
		);
	}

	/**
	 * The two actions Subscriptions fires while it sets a cart up from an order or a subscription.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_cart_setup_actions(): array {
		return array(
			'renewal'         => array( self::RENEWAL_SETUP_ACTION ),
			'initial payment' => array( self::INITIAL_PAYMENT_SETUP_ACTION ),
		);
	}

	/**
	 * @testdox Should convert a coupon copied into the session once, when the cart uses it: $_dataName.
	 *
	 * Client 11.3.0 `includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:296-362`, `:641-699` and its tests
	 * `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:930-950`, `:1017-1091`: a copy stored
	 * during a setup, marked `'storing'` and then with the hash of the copies, converts when the cart reads it, during the setup
	 * and after it; a session in the extension's own form reads the same; a percent copy keeps its percentage and converts its
	 * limits; an order discount never converts. A copy rewritten after the setup without the mark, and any coupon whose code is
	 * among copies from before the mark, a database coupon included, hold converted amounts. The fixture converts by doubling.
	 *
	 * @dataProvider provide_coupon_copies_in_use
	 *
	 * @param string               $session  How the session got its copies: `renewal setup`, `initial payment setup`, `rewritten after setup`,
	 *                                       `extension form`, `still storing` or `unmarked`.
	 * @param string               $origin   `copy` (built from the session copy, as Subscriptions does) or `database`.
	 * @param array<string,string> $props  Coupon properties, the code included.
	 * @param string[]             $expected Expected amount, minimum and maximum spend when the cart applies the coupon.
	 * @param string[]|null        $during   Expected reads while the setup still runs, or null outside a setup.
	 */
	public function test_converts_a_coupon_copy_once_when_the_cart_uses_it( string $session, string $origin, array $props, array $expected, ?array $during ): void {
		$sut = $this->create_controller();
		$sut->set_subscription_cart_item( 'renewal', $this->create_subscription( 'GBP' ) );
		$sut->register();
		$this->register_frontend_prices();
		$this->register_subscription_coupon_types();
		$code  = $props['code'];
		$copy  = array_diff_key( $props, array( 'code' => true ) );
		$apply = array( 'WC_Discounts->apply_coupon' );
		$read  = null;

		if ( in_array( $session, array( 'renewal setup', 'initial payment setup', 'rewritten after setup' ), true ) ) {
			$action = 'initial payment setup' === $session ? self::INITIAL_PAYMENT_SETUP_ACTION : self::RENEWAL_SETUP_ACTION;
			// Subscriptions copies the coupon, then applies it, inside the setup (9.4.1 `class-wcs-cart-renewal.php:1903-1918`).
			$store_and_apply = function () use ( $code, $copy, $apply, &$read ): void {
				WC()->session->set( 'wcs_renewal_coupons', array( 41 => array( $code => $copy ) ) );
				$read = $this->read_coupon( $apply, $this->create_copy_built_coupon( $code ) );
			};
			add_action( $action, $store_and_apply );
			do_action( $action ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Subscriptions' action, fired as it fires it.
			remove_action( $action, $store_and_apply );
		} else {
			WC()->session->set( 'wcs_renewal_coupons', array( 41 => array( $code => $copy ) ) );
		}

		$copies = WC()->session->get( 'wcs_renewal_coupons' );
		if ( 'rewritten after setup' === $session ) {
			// Code that converts a copy when it stores it, and leaves the mark alone.
			$copies[41][ $code ]['amount'] = '15.00';
			WC()->session->set( 'wcs_renewal_coupons', $copies );
		} elseif ( 'extension form' === $session ) {
			WC()->session->set( self::UNCONVERTED_SESSION_COUPONS_KEY, md5( (string) wp_json_encode( $copies ) ) );
		} elseif ( 'still storing' === $session ) {
			WC()->session->set( self::UNCONVERTED_SESSION_COUPONS_KEY, 'storing' );
		}

		$coupon = 'database' === $origin ? $this->create_subscription_cart_coupon( 'database', $props ) : $this->create_copy_built_coupon( $code );

		$this->assertSame( $expected, $this->read_coupon( $apply, $coupon ), 'Applied by the cart' );
		$this->assertSame( $during, $read, 'Applied while the setup runs' );
	}

	/**
	 * Copies in the session and the coupon the cart applies: amount, minimum and maximum spend, after the setup and during it.
	 *
	 * @return array<string,array<mixed>>
	 */
	public function provide_coupon_copies_in_use(): array {
		$copy      = array( 'code' => 'ten' ) + self::COUPON_COPY;
		$converted = array( '20', '100', '120' );
		$own       = array( '10.00', '50.00', '60.00' );
		$discount  = array(
			'code'          => 'discount_renewal',
			'discount_type' => 'renewal_cart',
			'amount'        => '5.00',
		);

		return array(
			'stored during a renewal setup'                => array( 'renewal setup', 'copy', $copy, $converted, $converted ),
			'stored during an initial payment setup'       => array( 'initial payment setup', 'copy', $copy, $converted, $converted ),
			'stored in the extension\'s form'              => array( 'extension form', 'copy', $copy, $converted, null ),
			'stored by a request that ended while storing' => array( 'still storing', 'copy', $copy, $converted, null ),
			'a renewal coupon stored during a renewal setup' => array( 'renewal setup', 'copy', array( 'discount_type' => 'renewal_fee' ) + $copy, $converted, $converted ),
			'a renewal percent coupon stored during a setup' => array(
				'renewal setup',
				'copy',
				array(
					'discount_type' => 'renewal_percent',
					'amount'        => '15',
				) + $copy,
				array( '15', '100', '120' ),
				array( '15', '100', '120' ),
			),
			'an order discount stored during a setup'      => array( 'renewal setup', 'copy', $discount, array( '5.00', '0', '0' ), array( '5.00', '0', '0' ) ),
			'rewritten after the setup without the mark'   => array( 'rewritten after setup', 'copy', $copy, array( '15.00', '50.00', '60.00' ), $converted ),
			'a database coupon of an unmarked copy\'s code' => array( 'unmarked', 'database', $copy, $own, null ),
		);
	}

	/**
	 * @testdox Should write no mark when a cart setup action fires without a session.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/compatibility/test-class-woocommerce-subscriptions.php:1094-1109`.
	 */
	public function test_writes_no_mark_without_a_session(): void {
		$sut = $this->create_controller();
		$sut->register();
		$session      = WC()->session;
		WC()->session = null;

		try {
			do_action( self::RENEWAL_SETUP_ACTION ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Subscriptions' action, fired as it fires it.
			do_action( self::INITIAL_PAYMENT_SETUP_ACTION ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Subscriptions' action, fired as it fires it.
		} finally {
			WC()->session = $session;
		}

		$this->assertNull( WC()->session->get( self::UNCONVERTED_SESSION_COUPONS_KEY ) );
	}

	/**
	 * @testdox Should cache the cart subscription-type lookup per request until the cart changes, and only once there is a cart to read.
	 */
	public function test_caches_cart_subscription_type_until_invalidated(): void {
		$sut = new class() extends WooCommerceSubscriptions {
			/**
			 * Number of times the underlying cart/session resolver ran.
			 *
			 * @var int
			 */
			public int $resolve_calls = 0;

			/**
			 * Value the resolver returns on its next invocation.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $next_value = null;

			/**
			 * Invoke the cached public lookup under test.
			 *
			 * @param string $type Subscription cart type.
			 * @return array<string,mixed>|null
			 */
			public function lookup( string $type ): ?array {
				return $this->get_subscription_type_from_cart( $type );
			}

			/**
			 * Spy resolver standing in for the real cart/session traversal.
			 *
			 * @param string $type Subscription cart type.
			 * @return array<int,array<string,mixed>>
			 */
			protected function resolve_subscription_cart_items( string $type ): array {
				unset( $type );
				++$this->resolve_calls;

				return null === $this->next_value ? array() : array( $this->next_value );
			}
		};

		$renewal         = array( 'subscription_renewal' => array( 'subscription_id' => 1 ) );
		$sut->next_value = $renewal;

		// First lookup resolves once and caches the value.
		$this->assertSame( $renewal, $sut->lookup( 'renewal' ) );
		$this->assertSame( 1, $sut->resolve_calls );

		// Repeated lookups return the cached value without re-traversing the cart.
		$this->assertSame( $renewal, $sut->lookup( 'renewal' ) );
		$this->assertSame( 1, $sut->resolve_calls );

		// An empty answer is cached too while the cart is there to read.
		$sut->next_value = null;
		$this->assertNull( $sut->lookup( 'switch' ) );
		$this->assertNull( $sut->lookup( 'switch' ) );
		$this->assertSame( 2, $sut->resolve_calls );

		// Invalidation forces a recompute that reflects the new cart state (transparency).
		$updated_renewal = array( 'subscription_renewal' => array( 'subscription_id' => 2 ) );
		$sut->next_value = $updated_renewal;
		$sut->clear_subscription_type_cache();

		$this->assertSame( $updated_renewal, $sut->lookup( 'renewal' ) );
		$this->assertSame( 3, $sut->resolve_calls );

		// Before the request has a cart or a session, each lookup answers and nothing is kept.
		$sut->clear_subscription_type_cache();
		$cart         = WC()->cart;
		$session      = WC()->session;
		WC()->cart    = null;
		WC()->session = null;
		try {
			$sut->lookup( 'renewal' );
			$sut->lookup( 'renewal' );
			$calls_without_a_cart = $sut->resolve_calls;
			WC()->session         = $session;
			$sut->lookup( 'renewal' );
			$sut->lookup( 'renewal' );
		} finally {
			WC()->cart    = $cart;
			WC()->session = $session;
		}

		$this->assertSame( array( 5, 6 ), array( $calls_without_a_cart, $sut->resolve_calls ), 'Two walks without a cart or a session, then one kept with the session.' );
	}

	/**
	 * @testdox Should disable mixed purchases for switch cart items.
	 */
	public function test_disables_mixed_purchase_for_switch_cart_items(): void {
		$sut = $this->create_controller();

		$this->assertSame( 'yes', $sut->maybe_disable_mixed_cart( 'yes' ) );

		$sut->set_subscription_cart_item( 'switch', $this->create_subscription( 'CAD' ) );

		$this->assertSame( 'no', $sut->maybe_disable_mixed_cart( 'yes' ) );
	}

	/**
	 * Register the frontend price conversions.
	 *
	 * @param float $rate Rate the fixture's projection service converts by.
	 */
	private function register_frontend_prices( float $rate = 2.0 ): void {
		$frontend_prices = new MultiCurrencyFrontendPricesController();
		$frontend_prices->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter( $rate ) ) ),
			new class() extends RequestContext {
				/**
				 * Register the storefront hooks.
				 *
				 * @return bool
				 */
				public function should_register_frontend_hooks(): bool {
					return true;
				}
			}
		);
		$frontend_prices->register();
	}

	/**
	 * Register the coupon discount types Subscriptions registers (`includes/core/class-wc-subscriptions-coupon.php:136-147,1179-1189`).
	 */
	private function register_subscription_coupon_types(): void {
		add_filter(
			'woocommerce_coupon_discount_types',
			static fn( array $types ): array => $types + array_fill_keys( array( 'recurring_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart', 'initial_cart' ), 'Subscriptions' )
		);
	}

	/**
	 * Build a coupon from its copy in the session, as Subscriptions' shop coupon data filter does on every read of that code
	 * (Subscriptions 9.4.1 `includes/core/class-wcs-cart-renewal.php:1048-1084`).
	 *
	 * @param string $code Coupon code.
	 * @return \WC_Coupon
	 */
	private function create_copy_built_coupon( string $code ): \WC_Coupon {
		add_filter(
			'woocommerce_get_shop_coupon_data',
			static function ( $data, $coupon_code ) {
				foreach ( (array) WC()->session->get( 'wcs_renewal_coupons' ) as $coupons ) {
					if ( is_array( $coupons ) && isset( $coupons[ $coupon_code ] ) ) {
						return array_merge(
							$coupons[ $coupon_code ],
							array(
								'id'           => true,
								'usage_limit'  => '',
								'usage_count'  => '',
								'date_expires' => '',
							)
						);
					}
				}

				return $data;
			},
			10,
			2
		);

		return new \WC_Coupon( $code );
	}

	/**
	 * Read a coupon's amount, minimum and maximum spend with the calls on the stack.
	 *
	 * @param string[]   $calls  Calls on the stack while the coupon is read.
	 * @param \WC_Coupon $coupon Coupon.
	 * @return string[]
	 */
	private function read_coupon( array $calls, \WC_Coupon $coupon ): array {
		return CallStack::run( $calls, static fn() => array( $coupon->get_amount(), $coupon->get_minimum_amount(), $coupon->get_maximum_amount() ) );
	}

	/**
	 * Create a coupon the way a subscription cart reads it.
	 *
	 * `session`: Subscriptions rebuilt it from the coupon properties it stored in the session, through its shop coupon data
	 * filter (Subscriptions 9.4.1 `includes/core/class-wcs-cart-renewal.php:1048-1084`, `:1115-1170`), with no mark. `virtual`: a coupon another
	 * plugin builds through the same filter (`virtual beside stored`: while the session holds another code). `order`: the pseudo coupon Subscriptions makes for an order discount (`:1879-1894`).
	 * `database`: a coupon read from the database.
	 *
	 * @param string               $origin Where the coupon comes from.
	 * @param array<string,string> $props  Coupon properties, the code included.
	 * @return \WC_Coupon
	 */
	private function create_subscription_cart_coupon( string $origin, array $props ): \WC_Coupon {
		$code = $props['code'];
		unset( $props['code'] );
		$this->register_subscription_coupon_types();

		if ( 'order' !== $origin && 'database' !== $origin ) {
			if ( 'virtual' !== $origin ) {
				$stored_code = 'session' === $origin ? $code : 'ten';
				WC()->session->set( 'wcs_renewal_coupons', array( 41 => array( $stored_code => $props ) ) );
			}
			add_filter(
				'woocommerce_get_shop_coupon_data',
				static function ( $data, $coupon_code ) use ( $code, $props ) {
					return $code === $coupon_code ? $props + array(
						'id'           => true,
						'usage_limit'  => '',
						'usage_count'  => '',
						'date_expires' => '',
					) : $data;
				},
				10,
				2
			);

			return new \WC_Coupon( $code );
		}

		$coupon = 'order' === $origin ? new \WC_Coupon( $code ) : new \WC_Coupon();
		$coupon->set_props( $props );
		$coupon->set_code( $code );

		return $coupon;
	}

	/**
	 * Create a subscriptions compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner                   Runtime owner.
	 * @param bool   $subscriptions_available Whether Subscriptions runtime is available.
	 * @param bool   $is_admin                Whether this is an admin request.
	 * @param bool   $is_cron                 Whether this is a cron request.
	 * @param bool   $has_additional_currencies_enabled Whether additional currencies are enabled.
	 * @param float  $rate                    Rate the projection service converts by.
	 * @return WooCommerceSubscriptions
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $subscriptions_available = true,
		bool $is_admin = false,
		bool $is_cron = false,
		bool $has_additional_currencies_enabled = true,
		float $rate = 2.0
	): WooCommerceSubscriptions {
		$controller = new class( $subscriptions_available, $is_admin, $is_cron ) extends WooCommerceSubscriptions {
			/**
			 * Whether Subscriptions runtime is available.
			 *
			 * @var bool
			 */
			private bool $subscriptions_available;

			/**
			 * Whether this is an admin request.
			 *
			 * @var bool
			 */
			private bool $is_admin;

			/**
			 * Whether this is a cron request.
			 *
			 * @var bool
			 */
			private bool $is_cron;

			/**
			 * Whether WordPress has finished loading plugins.
			 *
			 * @var bool
			 */
			private bool $plugins_loaded = true;

			/**
			 * Subscription cart items keyed by type.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			private array $cart_items = array();

			/**
			 * Switch request subscription.
			 *
			 * @var object|null
			 */
			private ?object $switch_request_subscription = null;

			/**
			 * Constructor.
			 *
			 * @param bool $subscriptions_available Whether Subscriptions runtime is available.
			 * @param bool $is_admin                Whether this is an admin request.
			 * @param bool $is_cron                 Whether this is a cron request.
			 */
			public function __construct( bool $subscriptions_available, bool $is_admin, bool $is_cron ) {
				$this->subscriptions_available = $subscriptions_available;
				$this->is_admin                = $is_admin;
				$this->is_cron                 = $is_cron;
			}

			/**
			 * Set whether Subscriptions runtime is available.
			 *
			 * @param bool $subscriptions_available Whether Subscriptions runtime is available.
			 */
			public function set_subscriptions_available( bool $subscriptions_available ): void {
				$this->subscriptions_available = $subscriptions_available;
			}

			/**
			 * Set whether plugins_loaded has fired.
			 *
			 * @param bool $plugins_loaded Whether plugins_loaded has fired.
			 */
			public function set_plugins_loaded( bool $plugins_loaded ): void {
				$this->plugins_loaded = $plugins_loaded;
			}

			/**
			 * Set a deterministic subscription cart item.
			 *
			 * @param string $type         Subscription cart type.
			 * @param object $subscription Subscription object.
			 * @param int    $product_id   ID of the cart item's product.
			 */
			public function set_subscription_cart_item( string $type, object $subscription, int $product_id = 0 ): void {
				$this->cart_items[ $type ] = array(
					'subscription_' . $type => array( 'subscription_id' => $type . '-subscription' ),
					'product_id'            => $product_id,
					'variation_id'          => 0,
					'subscription_object'   => $subscription,
				);
				$this->clear_subscription_type_cache();
			}

			/**
			 * Set a deterministic subscription switch cart item.
			 *
			 * @param int    $product_id   Product ID.
			 * @param int    $variation_id Variation ID.
			 * @param string $key          Cart item key.
			 */
			public function set_switch_cart_item( int $product_id, int $variation_id, string $key ): void {
				$this->cart_items['switch'] = array(
					'subscription_switch' => array( 'subscription_id' => 'switch-subscription' ),
					'product_id'          => $product_id,
					'variation_id'        => $variation_id,
					'key'                 => $key,
					'subscription_object' => (object) array(),
				);
				$this->clear_subscription_type_cache();
			}

			/**
			 * Set a cart item paying a signup order again, as WooCommerce Subscriptions 9.4.1 adds it.
			 *
			 * @param int $order_id Signup order ID.
			 */
			public function set_initial_payment_cart_item( int $order_id ): void {
				$this->cart_items['initial_payment'] = array(
					'subscription_initial_payment' => array( 'order_id' => $order_id ),
					'product_id'                   => 0,
					'variation_id'                 => 0,
				);
				$this->clear_subscription_type_cache();
			}

			/**
			 * Set a deterministic switch request subscription.
			 *
			 * @param object $subscription Subscription object.
			 */
			public function set_switch_request_subscription( object $subscription ): void {
				$this->switch_request_subscription = $subscription;
			}

			/**
			 * Tell whether Subscriptions runtime is available.
			 *
			 * @return bool
			 */
			protected function is_subscriptions_runtime_available(): bool {
				return $this->subscriptions_available;
			}

			/**
			 * Get the deterministic cart items of a subscription cart type.
			 *
			 * @param string $type Subscription cart type.
			 * @return array<int,array<string,mixed>>
			 */
			protected function resolve_subscription_cart_items( string $type ): array {
				return isset( $this->cart_items[ $type ] ) ? array( $this->cart_items[ $type ] ) : array();
			}

			/**
			 * Get a subscription object from a deterministic cart item.
			 *
			 * @param array<string,mixed> $cart_item Subscription cart item.
			 * @param string              $type      Subscription cart type.
			 * @return object|null
			 */
			protected function get_subscription_from_cart_item( array $cart_item, string $type ): ?object {
				unset( $type );

				return $cart_item['subscription_object'] ?? null;
			}

			/**
			 * Get a deterministic switch request subscription.
			 *
			 * @return object|null
			 */
			protected function get_subscription_from_switch_request(): ?object {
				return $this->switch_request_subscription;
			}

			/**
			 * The request signals this double's case sets.
			 *
			 * @return array<string,bool>
			 */
			private function request_signals(): array {
				return array(
					'admin'          => $this->is_admin,
					'cron'           => $this->is_cron,
					'plugins_loaded' => $this->plugins_loaded,
				);
			}

			/** Register with this case's request signals set. */
			public function register() {
				RequestSignals::run( $this->request_signals(), fn() => parent::register() );
			}
		};

		$controller->init(
			$this->create_arbiter( $owner ),
			wc_get_container()->get( LegacyProxy::class ),
			ServiceFactories::state_builder( $this, $this->create_state_builder( $has_additional_currencies_enabled ) ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter( $rate ) ) ),
			CallStack::request_context()
		);

		return $controller;
	}

	/**
	 * Create the integration on the real services and the real cart and session reads.
	 *
	 * @return WooCommerceSubscriptions
	 */
	private function create_real_controller(): WooCommerceSubscriptions {
		$sut = new WooCommerceSubscriptions();
		$sut->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			wc_get_container()->get( LegacyProxy::class ),
			wc_get_container()->get( StateBuilderFactory::class ),
			wc_get_container()->get( ServiceFactory::class ),
			CallStack::request_context()
		);

		return $sut;
	}

	/**
	 * Save an order in a currency.
	 *
	 * @param string $currency Order currency.
	 * @return int The order ID.
	 */
	private function create_order_in_currency( string $currency ): int {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$order->save();

		return $order->get_id();
	}

	/**
	 * A cart item paying a signup order again, as WooCommerce Subscriptions 9.4.1 stores it in the session.
	 *
	 * @param int $order_id Signup order ID.
	 * @return array<string,mixed>
	 */
	private function get_initial_payment_cart_item( int $order_id ): array {
		return array(
			'subscription_initial_payment' => array( 'order_id' => $order_id ),
			'product_id'                   => 0,
			'key'                          => 'abc123',
		);
	}

	/**
	 * Create a deterministic state builder.
	 *
	 * @param bool $has_additional_currencies_enabled Whether additional currencies are enabled.
	 * @return StateBuilder
	 */
	private function create_state_builder( bool $has_additional_currencies_enabled ): StateBuilder {
		return new class( $has_additional_currencies_enabled, $this->create_localization() ) extends StateBuilder {
			/**
			 * Whether additional currencies are enabled.
			 *
			 * @var bool
			 */
			private bool $has_additional_currencies_enabled;

			/**
			 * Localization service.
			 *
			 * @var LocalizationInterface
			 */
			private LocalizationInterface $localization_service;

			/**
			 * Constructor.
			 *
			 * @param bool                  $has_additional_currencies_enabled Whether additional currencies are enabled.
			 * @param LocalizationInterface $localization_service              Localization service.
			 */
			public function __construct( bool $has_additional_currencies_enabled, LocalizationInterface $localization_service ) {
				$this->has_additional_currencies_enabled = $has_additional_currencies_enabled;
				$this->localization_service              = $localization_service;
			}

			/**
			 * Build a deterministic multi-currency state.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				$default = new MultiCurrencyCurrency( $this->localization_service, 'USD', 1.0, true );
				$enabled = array( 'USD' => $default );

				if ( $this->has_additional_currencies_enabled ) {
					$enabled['EUR'] = new MultiCurrencyCurrency( $this->localization_service, 'EUR', 0.9 );
				}

				return new MultiCurrencyState( $enabled, $enabled, $default, $default );
			}
		};
	}

	/**
	 * Create a deterministic price projection service.
	 *
	 * @param float $rate Rate it converts by.
	 * @return PriceConverter
	 */
	private function create_price_converter( float $rate = 2.0 ): PriceConverter {
		return new class( $rate ) extends PriceConverter {
			/**
			 * Rate it converts by.
			 *
			 * @var float
			 */
			private float $rate;

			/**
			 * Constructor.
			 *
			 * @param float $rate Rate it converts by.
			 */
			public function __construct( float $rate ) {
				$this->rate = $rate;
			}

			/**
			 * Project a converted price for the selected currency.
			 *
			 * @param mixed                      $price    Price.
			 * @param string                     $type     Price type.
			 * @param MultiCurrencyCurrency|null $currency Currency to convert to.
			 * @return float
			 */
			public function get_price( $price, string $type, ?MultiCurrencyCurrency $currency = null ): float {
				unset( $type, $currency );

				return (float) $price * $this->rate;
			}

			/**
			 * Tell whether the selected currency differs from the store currency.
			 *
			 * @return bool
			 */
			public function should_convert_between_selected_and_default_currency(): bool {
				return 1.0 !== $this->rate;
			}
		};
	}

	/**
	 * Create a localization test double.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				unset( $currency_code );

				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}

	/**
	 * Create a static multi-currency runtime arbiter.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Get the multi-currency runtime owner for the current site.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core multi-currency may register hooks.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}

	/**
	 * Create a subscription: WooCommerce Subscriptions 9.3.0's `WC_Subscription` extends `WC_Order`
	 * (`includes/core/class-wc-subscription.php:15`), and the double reports any currency
	 * code, an empty one included, which WC_Order::set_currency() would refuse.
	 *
	 * @param string $currency_code Currency code.
	 * @return \WC_Order
	 */
	private function create_subscription( string $currency_code ): \WC_Order {
		return new class( $currency_code ) extends \WC_Order {
			/**
			 * Currency code.
			 *
			 * @var string
			 */
			private string $currency_code;

			/**
			 * Constructor.
			 *
			 * @param string $currency_code Currency code.
			 */
			public function __construct( string $currency_code ) {
				parent::__construct();
				$this->currency_code = $currency_code;
			}

			/**
			 * Get the subscription currency.
			 *
			 * @param string $context View or edit context.
			 * @return string
			 */
			public function get_currency( $context = 'view' ) {
				unset( $context );

				return $this->currency_code;
			}
		};
	}

	/**
	 * Create a coupon of a discount type, including the types WooCommerce Subscriptions 9.3.0 registers
	 * (`includes/core/class-wc-subscriptions-coupon.php:136-147`, renewal types from `:60`).
	 *
	 * @param string $discount_type Discount type.
	 * @return \WC_Coupon
	 */
	private function create_coupon( string $discount_type ): \WC_Coupon {
		return new class( $discount_type ) extends \WC_Coupon {
			/**
			 * Discount type.
			 *
			 * @var string
			 */
			private string $coupon_discount_type;

			/**
			 * Constructor.
			 *
			 * @param string $coupon_discount_type Discount type.
			 */
			public function __construct( string $coupon_discount_type ) {
				parent::__construct();
				$this->coupon_discount_type = $coupon_discount_type;
			}

			/**
			 * Get the coupon discount type.
			 *
			 * @param string $context View or edit context.
			 * @return string
			 */
			public function get_discount_type( $context = 'view' ) {
				unset( $context );

				return $this->coupon_discount_type;
			}
		};
	}

	/**
	 * Create an unsaved subscription product with a sign-up fee in its meta, the key WooCommerce Subscriptions 9.3.0
	 * stores it under (`includes/core/class-wc-subscriptions-product.php:26`).
	 *
	 * @param int  $product_id                  Product ID.
	 * @param bool $has_changed_signup_fee_meta Whether the sign-up fee meta has changed since it was read.
	 * @param bool $has_prorated_signup_fee     Whether the switch totals calculator stored the prorated fee, as it does when it
	 *                                          rewrites the fee (Subscriptions 9.3.0 `class-wcs-switch-totals-calculator.php:527`).
	 * @return \WC_Product
	 */
	private function create_product( int $product_id, bool $has_changed_signup_fee_meta = false, bool $has_prorated_signup_fee = false ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->add_meta_data( '_subscription_sign_up_fee', '10.00', true );
		if ( $has_changed_signup_fee_meta ) {
			$product->update_meta_data( '_subscription_sign_up_fee', '12.00' );
		}
		if ( $has_prorated_signup_fee ) {
			$product->add_meta_data( '_subscription_sign_up_fee_prorated', '10.00', true );
		}
		// Set after the meta, so the product reads no meta from the database.
		$product->set_id( $product_id );

		return $product;
	}

	/**
	 * Create an unsaved simple product with a price.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $price      Regular and active price.
	 * @return \WC_Product
	 */
	private function create_priced_product( int $product_id, string $price ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_id( $product_id );

		return $product;
	}
}
