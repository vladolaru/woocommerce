<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySubscriptionsCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencySubscriptionsCompatibilityController class.
 */
class MultiCurrencySubscriptionsCompatibilityControllerTest extends WC_Unit_Test_Case {

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
	);

	/**
	 * Cart-lifecycle hooks that must invalidate the subscription cart-type cache.
	 *
	 * @var string[]
	 */
	private array $cache_invalidation_hooks = array(
		'woocommerce_cart_loaded_from_session',
		'woocommerce_cart_updated',
		'woocommerce_cart_emptied',
		'woocommerce_add_to_cart',
		'woocommerce_cart_item_removed',
		'woocommerce_cart_item_restored',
	);

	/**
	 * Controllers that scheduled deferred plugin-loaded registration.
	 *
	 * @var MultiCurrencySubscriptionsCompatibilityController[]
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

		parent::tearDown();
	}

	/**
	 * @testdox Should register subscription filters for core frontend runtime.
	 */
	public function test_registers_subscription_filters_for_core_frontend_runtime(): void {
		$sut = $this->create_controller();

		$sut->register();
		$sut->register();

		$this->assertSame( 50, has_filter( 'wcpay_multi_currency_override_selected_currency', array( $sut, 'override_selected_currency' ) ) );
		$this->assertSame( 50, has_filter( 'wcpay_multi_currency_should_disable_currency_switching', array( $sut, 'should_disable_currency_switching' ) ) );
		$this->assertSame( 50, has_filter( 'wcpay_multi_currency_should_convert_product_price', array( $sut, 'should_convert_product_price' ) ) );
		$this->assertSame( 50, has_filter( 'wcpay_multi_currency_should_convert_coupon_amount', array( $sut, 'should_convert_coupon_amount' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_subscriptions_product_price', array( $sut, 'get_subscription_product_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_get__subscription_sign_up_fee', array( $sut, 'get_subscription_product_signup_fee' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_product_variation_get__subscription_sign_up_fee', array( $sut, 'get_subscription_product_signup_fee' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_subscription_price_string_details', array( $sut, 'maybe_set_current_my_account_subscription' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_get_formatted_subscription_total', array( $sut, 'maybe_clear_current_my_account_subscription' ) ) );
		$this->assertSame( 50, has_filter( 'wc_price', array( $sut, 'maybe_get_explicit_format_for_subscription_total' ) ) );
		$this->assertSame( 50, has_filter( 'option_woocommerce_subscriptions_multiple_purchase', array( $sut, 'maybe_disable_mixed_cart' ) ) );

		foreach ( $this->cache_invalidation_hooks as $hook ) {
			$this->assertSame( 10, has_action( $hook, array( $sut, 'clear_subscription_type_cache' ) ), $hook );
		}

		$this->assertSame(
			array(
				'option_woocommerce_subscriptions_multiple_purchase' => array( 'maybe_disable_mixed_cart' => array( 50, 1 ) ),
				'wc_price'                                => array( 'maybe_get_explicit_format_for_subscription_total' => array( 50, 1 ) ),
				'wcpay_multi_currency_override_selected_currency' => array( 'override_selected_currency' => array( 50, 1 ) ),
				'wcpay_multi_currency_should_convert_coupon_amount' => array( 'should_convert_coupon_amount' => array( 50, 2 ) ),
				'wcpay_multi_currency_should_convert_product_price' => array( 'should_convert_product_price' => array( 50, 2 ) ),
				'wcpay_multi_currency_should_disable_currency_switching' => array( 'should_disable_currency_switching' => array( 50, 1 ) ),
				'woocommerce_add_to_cart'                 => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
				'woocommerce_cart_emptied'                => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
				'woocommerce_cart_item_removed'           => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
				'woocommerce_cart_item_restored'          => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
				'woocommerce_cart_loaded_from_session'    => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
				'woocommerce_cart_updated'                => array( 'clear_subscription_type_cache' => array( 10, 1 ) ),
				'woocommerce_get_formatted_subscription_total' => array( 'maybe_clear_current_my_account_subscription' => array( 50, 2 ) ),
				'woocommerce_product_get__subscription_sign_up_fee' => array( 'get_subscription_product_signup_fee' => array( 50, 2 ) ),
				'woocommerce_product_variation_get__subscription_sign_up_fee' => array( 'get_subscription_product_signup_fee' => array( 50, 2 ) ),
				'woocommerce_subscription_price_string_details' => array( 'maybe_set_current_my_account_subscription' => array( 50, 2 ) ),
				'woocommerce_subscriptions_product_price' => array( 'get_subscription_product_price' => array( 50, 2 ) ),
			),
			RegisteredHooks::of( $sut )
		);
	}

	/**
	 * @testdox Should not register subscription filters when plugin owns runtime.
	 */
	public function test_does_not_register_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$sut->register();

		$this->assert_subscription_hooks_not_registered( $sut );
	}

	/**
	 * @testdox Should not register subscription filters when subscriptions runtime is absent.
	 */
	public function test_does_not_register_when_subscriptions_runtime_is_absent(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false );

		$sut->register();

		$this->assert_subscription_hooks_not_registered( $sut );
	}

	/**
	 * @testdox Should not register subscription filters for admin or cron requests.
	 */
	public function test_does_not_register_for_admin_or_cron_requests(): void {
		$admin = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, false );
		$cron  = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, true );

		$admin->register();
		$cron->register();

		$this->assert_subscription_hooks_not_registered( $admin );
		$this->assert_subscription_hooks_not_registered( $cron );
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
	 * @testdox Should preserve an existing selected currency override.
	 */
	public function test_preserves_existing_selected_currency_override(): void {
		$sut = $this->create_controller();
		$sut->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );

		$this->assertSame( 'GBP', $sut->override_selected_currency( 'GBP' ) );
	}

	/**
	 * @testdox Should override selected currency while a My Account subscription total is formatting.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @testdox Should preserve explicit currency formatting when no code is needed.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_preserves_explicit_currency_formatting_when_no_code_is_needed(): void {
		$sut          = $this->create_controller();
		$subscription = $this->create_subscription( 'EUR' );
		CallStack::run(
			array( 'WCS_Template_Loader::get_my_subscriptions' ),
			function () use ( $sut, $subscription ): void {
				$sut->maybe_set_current_my_account_subscription( array(), $subscription );

				$this->assertSame( '<span>$10.00 EUR</span>', $sut->maybe_get_explicit_format_for_subscription_total( '<span>$10.00 EUR</span>' ) );
				$this->assertSame( 'GBP', $sut->override_selected_currency( 'GBP' ) );
				$this->assertSame( 'EUR', $sut->override_selected_currency( false ), 'The template sets the subscription the row formats.' );
			}
		);
	}

	/**
	 * @testdox Should skip explicit subscription total formatting when additional currencies are disabled.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_skips_explicit_subscription_total_formatting_when_additional_currencies_are_disabled(): void {
		$sut          = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, false, false );
		$subscription = $this->create_subscription( 'EUR' );
		CallStack::run(
			array( 'WC_Subscription->get_formatted_order_total' ),
			function () use ( $sut, $subscription ): void {
				$sut->maybe_set_current_my_account_subscription( array(), $subscription );

				$this->assertSame( '<span>$10.00</span>', $sut->maybe_get_explicit_format_for_subscription_total( '<span>$10.00</span>' ) );
			}
		);
	}

	/**
	 * @testdox Should leave subscription totals unchanged for a subscription without a currency code.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_leaves_subscription_totals_unchanged_without_a_currency_code(): void {
		$sut          = $this->create_controller();
		$subscription = $this->create_subscription( ' ' );
		CallStack::run(
			array( 'WC_Subscription->get_formatted_order_total' ),
			function () use ( $sut, $subscription ): void {
				$sut->maybe_set_current_my_account_subscription( array(), $subscription );

				$this->assertSame( '<span>$10.00</span>', $sut->maybe_get_explicit_format_for_subscription_total( '<span>$10.00</span>' ) );
				$this->assertFalse( $sut->override_selected_currency( false ), 'A blank currency code is no currency to switch to.' );
			}
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_keeps_resubscribe_cart_prices_while_the_cart_is_set_up(): void {
		$resubscribe = $this->create_controller();
		$resubscribe->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'GBP' ) );

		$this->assertFalse( CallStack::run( array( 'WCS_Cart_Renewal->setup_cart', 'WC_Cart_Totals->calculate_item_totals' ), fn() => $resubscribe->should_convert_product_price( true, (object) array( 'id' => 1 ) ) ) );
	}

	/**
	 * @testdox Should apply subscription product conversion decisions.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
		$resubscribe->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'GBP' ) );

		$this->assertFalse( CallStack::run( array( 'WC_Cart->get_product_subtotal' ), fn() => $resubscribe->should_convert_product_price( true, $product ) ) );
	}

	/**
	 * @testdox Should convert direct subscription product prices through the native projection service.
	 */
	public function test_converts_direct_subscription_product_prices_through_native_projection_service(): void {
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
	public function test_converts_subscription_signup_fees_through_native_projection_service(): void {
		$product = $this->create_product( 10 );
		$sut     = $this->create_controller();

		$this->assertSame( 20.0, $sut->get_subscription_product_signup_fee( '10.00', $product ) );
		$this->assertSame( 0, $sut->get_subscription_product_signup_fee( 0, $product ) );
	}

	/**
	 * @testdox Should preserve switch subscription sign-up fees during price setup.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_preserves_switch_subscription_signup_fees_during_price_setup(): void {
		$product = $this->create_product( 10 );
		$sut     = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );
		$this->assertSame( '10.00', CallStack::run( array( 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation' ), fn() => $sut->get_subscription_product_signup_fee( '10.00', $product ) ) );
	}

	/**
	 * @testdox Should preserve repeated switch sign-up fees during proration total calculation.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_preserves_repeated_switch_signup_fees_during_proration_total_calculation(): void {
		$product = $this->create_product( 10 );
		$sut     = $this->create_controller();
		$sut->set_switch_cart_item( 10, 0, 'switch-item' );

		$this->assertSame( 20.0, $sut->get_subscription_product_signup_fee( '10.00', $product ) );

		$this->assertSame( '10.00', CallStack::run( array( 'WC_Subscriptions_Product::get_sign_up_fee', 'WC_Cart->calculate_totals' ), fn() => $sut->get_subscription_product_signup_fee( '10.00', $product ) ) );
	}

	/**
	 * @testdox Should convert repeated switch sign-up fees while apportioning switch totals.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_converts_repeated_switch_signup_fees_while_apportioning_switch_totals(): void {
		$product = $this->create_product( 10 );
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
	 * @testdox Should apply subscription coupon conversion decisions.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
				$this->assertFalse( $renewal->should_convert_coupon_amount( true, $this->create_coupon( 'renewal_fee' ) ) );
				$this->assertTrue( $renewal->should_convert_coupon_amount( true, $this->create_coupon( 'fixed_cart' ) ) );
			}
		);

		$early_renewal = $this->create_controller();
		$early_renewal->set_subscription_cart_item( 'renewal', $this->create_subscription( 'EUR' ) );
		$this->assertTrue( CallStack::run( array( 'WCS_Cart_Early_Renewal->setup_cart', 'WC_Discounts->apply_coupon' ), fn() => $early_renewal->should_convert_coupon_amount( true, $this->create_coupon( 'renewal_fee' ) ) ) );
	}

	/**
	 * @testdox Should cache the cart subscription-type lookup per request until the cart changes.
	 */
	public function test_caches_cart_subscription_type_until_invalidated(): void {
		$sut = new class() extends MultiCurrencySubscriptionsCompatibilityController {
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
			 * @return array<string,mixed>|null
			 */
			protected function resolve_subscription_type_from_cart( string $type ): ?array {
				unset( $type );
				++$this->resolve_calls;

				return $this->next_value;
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

		// A null result is cached too, so an early "no subscription" answer is not recomputed.
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
	 * Assert subscription compatibility hooks are not registered for a controller.
	 *
	 * @param MultiCurrencySubscriptionsCompatibilityController $sut The controller.
	 */
	private function assert_subscription_hooks_not_registered( MultiCurrencySubscriptionsCompatibilityController $sut ): void {
		$this->assertFalse( has_filter( 'wcpay_multi_currency_override_selected_currency', array( $sut, 'override_selected_currency' ) ) );
		$this->assertFalse( has_filter( 'wcpay_multi_currency_should_disable_currency_switching', array( $sut, 'should_disable_currency_switching' ) ) );
		$this->assertFalse( has_filter( 'wcpay_multi_currency_should_convert_product_price', array( $sut, 'should_convert_product_price' ) ) );
		$this->assertFalse( has_filter( 'wcpay_multi_currency_should_convert_coupon_amount', array( $sut, 'should_convert_coupon_amount' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_subscriptions_product_price', array( $sut, 'get_subscription_product_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_get__subscription_sign_up_fee', array( $sut, 'get_subscription_product_signup_fee' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_variation_get__subscription_sign_up_fee', array( $sut, 'get_subscription_product_signup_fee' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_subscription_price_string_details', array( $sut, 'maybe_set_current_my_account_subscription' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_formatted_subscription_total', array( $sut, 'maybe_clear_current_my_account_subscription' ) ) );
		$this->assertFalse( has_filter( 'wc_price', array( $sut, 'maybe_get_explicit_format_for_subscription_total' ) ) );
		$this->assertFalse( has_filter( 'option_woocommerce_subscriptions_multiple_purchase', array( $sut, 'maybe_disable_mixed_cart' ) ) );

		foreach ( $this->cache_invalidation_hooks as $hook ) {
			$this->assertFalse( has_action( $hook, array( $sut, 'clear_subscription_type_cache' ) ), $hook );
		}
	}

	/**
	 * Create a subscriptions compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner                   Runtime owner.
	 * @param bool   $subscriptions_available Whether Subscriptions runtime is available.
	 * @param bool   $is_admin                Whether this is an admin request.
	 * @param bool   $is_cron                 Whether this is a cron request.
	 * @param bool   $has_additional_currencies_enabled Whether additional currencies are enabled.
	 * @return MultiCurrencySubscriptionsCompatibilityController
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $subscriptions_available = true,
		bool $is_admin = false,
		bool $is_cron = false,
		bool $has_additional_currencies_enabled = true
	): MultiCurrencySubscriptionsCompatibilityController {
		$controller = new class( $subscriptions_available, $is_admin, $is_cron ) extends MultiCurrencySubscriptionsCompatibilityController {
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
			 */
			public function set_subscription_cart_item( string $type, object $subscription ): void {
				$this->cart_items[ $type ] = array(
					'subscription_' . $type => array( 'subscription_id' => $type . '-subscription' ),
					'subscription_object'   => $subscription,
				);
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
			 * Get a deterministic subscription cart item.
			 *
			 * @param string $type Subscription cart type.
			 * @return array<string,mixed>|null
			 */
			protected function get_subscription_type_from_cart( string $type ): ?array {
				return $this->cart_items[ $type ] ?? null;
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
			new LegacyProxy(),
			wc_get_container()->get( MultiCurrencyStateBuilderFactory::class ),
			wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class )
		);
		$controller->set_price_projection_service( $this->create_price_projection_service() );
		$controller->set_state_builder( $this->create_state_builder( $has_additional_currencies_enabled ) );

		return $controller;
	}

	/**
	 * Create a deterministic state builder.
	 *
	 * @param bool $has_additional_currencies_enabled Whether additional currencies are enabled.
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_state_builder( bool $has_additional_currencies_enabled ): MultiCurrencyStateBuilder {
		return new class( $has_additional_currencies_enabled, $this->create_localization() ) extends MultiCurrencyStateBuilder {
			/**
			 * Whether additional currencies are enabled.
			 *
			 * @var bool
			 */
			private bool $has_additional_currencies_enabled;

			/**
			 * Localization service.
			 *
			 * @var MultiCurrencyLocalizationInterface
			 */
			private MultiCurrencyLocalizationInterface $localization_service;

			/**
			 * Constructor.
			 *
			 * @param bool                               $has_additional_currencies_enabled Whether additional currencies are enabled.
			 * @param MultiCurrencyLocalizationInterface $localization_service              Localization service.
			 */
			public function __construct( bool $has_additional_currencies_enabled, MultiCurrencyLocalizationInterface $localization_service ) {
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
	 * @return MultiCurrencyPriceProjectionService
	 */
	private function create_price_projection_service(): MultiCurrencyPriceProjectionService {
		return new class() extends MultiCurrencyPriceProjectionService {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Project a converted price for the selected currency.
			 *
			 * @param mixed  $price Price.
			 * @param string $type  Price type.
			 * @return float
			 */
			public function get_price( $price, string $type ): float {
				unset( $type );

				return (float) $price * 2;
			}
		};
	}

	/**
	 * Create a localization test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
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
			public function should_core_register(): bool {
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
	 * @return \WC_Product
	 */
	private function create_product( int $product_id, bool $has_changed_signup_fee_meta = false ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->add_meta_data( '_subscription_sign_up_fee', '10.00', true );
		if ( $has_changed_signup_fee_meta ) {
			$product->update_meta_data( '_subscription_sign_up_fee', '12.00' );
		}
		// Set after the meta, so the product reads no meta from the database.
		$product->set_id( $product_id );

		return $product;
	}
}
