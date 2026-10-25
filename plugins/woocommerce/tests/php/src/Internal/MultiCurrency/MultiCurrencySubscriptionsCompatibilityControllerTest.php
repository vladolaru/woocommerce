<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySubscriptionsCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
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
		$this->assertSame( 100, has_filter( 'woocommerce_coupon_get_minimum_amount', array( $sut, 'get_subscription_session_coupon_spend_limit' ) ) );
		$this->assertSame( 100, has_filter( 'woocommerce_coupon_get_maximum_amount', array( $sut, 'get_subscription_session_coupon_spend_limit' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_subscriptions_product_price', array( $sut, 'get_subscription_product_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_subscriptions_product_sale_price', array( $sut, 'get_stored_price_for_active_price' ) ) );
		$this->assertSame( 50, has_filter( 'woocommerce_subscriptions_product_regular_price', array( $sut, 'get_stored_price_for_active_price' ) ) );
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
				'woocommerce_coupon_get_maximum_amount'   => array( 'get_subscription_session_coupon_spend_limit' => array( 100, 2 ) ),
				'woocommerce_coupon_get_minimum_amount'   => array( 'get_subscription_session_coupon_spend_limit' => array( 100, 2 ) ),
				'woocommerce_get_formatted_subscription_total' => array( 'maybe_clear_current_my_account_subscription' => array( 50, 2 ) ),
				'woocommerce_product_get__subscription_sign_up_fee' => array( 'get_subscription_product_signup_fee' => array( 50, 2 ) ),
				'woocommerce_product_variation_get__subscription_sign_up_fee' => array( 'get_subscription_product_signup_fee' => array( 50, 2 ) ),
				'woocommerce_subscription_price_string_details' => array( 'maybe_set_current_my_account_subscription' => array( 50, 2 ) ),
				'woocommerce_subscriptions_product_price' => array( 'get_subscription_product_price' => array( 50, 2 ) ),
				'woocommerce_subscriptions_product_regular_price' => array( 'get_stored_price_for_active_price' => array( 50, 2 ) ),
				'woocommerce_subscriptions_product_sale_price' => array( 'get_stored_price_for_active_price' => array( 50, 2 ) ),
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

		$selected = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->create()->build()->get_selected_currency();

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
		$resubscribe->set_subscription_cart_item( 'resubscribe', $this->create_subscription( 'GBP' ), 1 );

		$this->assertFalse( CallStack::run( array( 'WCS_Cart_Renewal->setup_cart', 'WC_Cart_Totals->calculate_item_totals' ), fn() => $resubscribe->should_convert_product_price( true, $this->create_product( 1 ) ) ) );
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
			$sut = new MultiCurrencySubscriptionsCompatibilityController();
			$sut->init(
				$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
				new LegacyProxy(),
				wc_get_container()->get( MultiCurrencyStateBuilderFactory::class ),
				wc_get_container()->get( MultiCurrencyServiceFactory::class )
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
		$product = $this->create_product( 10, false, true );
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
		$product = $this->create_product( 10, false, true );
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @testdox Should apply a subscription cart's coupon at the amount, minimum and maximum it holds.
	 *
	 * WooCommerce Subscriptions 9.3.0 sets up renewal and initial payment carts by storing each coupon's view-context amount,
	 * minimum and maximum in the session (`includes/core/class-wcs-cart-renewal.php:1108-1166`) and rebuilding the coupon from
	 * there on every later read, as a virtual coupon (`:1041-1077`); a manual or deleted discount becomes a `renewal_cart` or
	 * `initial_cart` coupon holding the order's discount (`:1708-1852`). The session amounts were converted when they were stored,
	 * and an order discount is already in the order's currency, so neither converts again. Amounts are from a recorded store run
	 * at GBP 0.73: a 10.00 coupon stored as 7.30 with a 50.00 minimum stored as 36.45 (the 73.00 maximum is a synthetic value), and a
	 * 5.00 manual discount. The fixture converts by doubling.
	 *
	 * @dataProvider subscription_cart_coupons
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string|null          $cart_type Subscription cart type, or null for an initial payment cart.
	 * @param string[]             $calls     Calls on the stack while the coupon is read.
	 * @param string               $origin    `session` (rebuilt from the session), `virtual`, `virtual beside stored`, `order` or `database`.
	 * @param array<string,string> $props     Coupon properties.
	 * @param string               $amount    Expected amount.
	 * @param string[]             $limits    Expected minimum and maximum spend.
	 */
	public function test_applies_a_subscription_cart_coupon_at_the_amount_it_holds( ?string $cart_type, array $calls, string $origin, array $props, string $amount, array $limits ): void {
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
	 * Coupons a renewal or initial payment cart reads while it is set up, applied, and turned into the order's coupon line.
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

		return array(
			'renewal, manual discount, stored'           => array( 'renewal', $setup, 'order', $order_amount + array( 'discount_type' => 'renewal_cart' ), '5.00', array( '0', '0' ) ),
			'renewal, manual discount, applied'          => array( 'renewal', $apply, 'session', $order_amount + array( 'discount_type' => 'renewal_cart' ), '5.00', array( '0', '0' ) ),
			'initial payment, manual discount, stored'   => array( null, $setup, 'order', array( 'code' => 'discount_initial' ) + $order_amount + array( 'discount_type' => 'initial_cart' ), '5.00', array( '0', '0' ) ),
			'initial payment, manual discount, applied'  => array( null, $apply, 'session', array( 'code' => 'discount_initial' ) + $order_amount + array( 'discount_type' => 'initial_cart' ), '5.00', array( '0', '0' ) ),
			'initial payment, stored coupon, applied'    => array( null, $apply, 'session', $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, stored non-recurring coupon, applied' => array( 'renewal', $apply, 'session', $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, stored renewal coupon, applied'    => array( 'renewal', $apply, 'session', array( 'discount_type' => 'renewal_fee' ) + $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, stored coupon, order coupon line'  => array( 'renewal', array(), 'session', $stored, '7.30', array( '36.45', '73.00' ) ),
			'renewal, database coupon, stored'           => array( 'renewal', $setup, 'database', $database, '20', array( '100', '120' ) ),
			'initial payment, database coupon, stored'   => array( null, $setup, 'database', $database, '20', array( '100', '120' ) ),
			'initial payment, coupon typed in, applied'  => array( null, $apply, 'database', $database, '20', array( '100', '120' ) ),
			'recurring percent coupon, limits convert'   => array( null, $apply, 'database', array( 'discount_type' => 'recurring_percent' ) + $database, '10.00', array( '100', '120' ) ),
			'renewal, another plugin\'s virtual coupon'  => array( 'renewal', $apply, 'virtual', $database, '20', array( '100', '120' ) ),
			'renewal, virtual coupon beside stored ones' => array( 'renewal', $apply, 'virtual beside stored', $database, '20', array( '100', '120' ) ),
		);
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
		$this->assertFalse( has_filter( 'woocommerce_coupon_get_minimum_amount', array( $sut, 'get_subscription_session_coupon_spend_limit' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_coupon_get_maximum_amount', array( $sut, 'get_subscription_session_coupon_spend_limit' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_subscriptions_product_price', array( $sut, 'get_subscription_product_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_subscriptions_product_sale_price', array( $sut, 'get_stored_price_for_active_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_subscriptions_product_regular_price', array( $sut, 'get_stored_price_for_active_price' ) ) );
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
	 * Register the frontend price conversions.
	 *
	 * @param float $rate Rate the fixture's projection service converts by.
	 */
	private function register_frontend_prices( float $rate = 2.0 ): void {
		$frontend_prices = new MultiCurrencyFrontendPricesController();
		$frontend_prices->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter( $rate ) ) ),
			new class() extends MultiCurrencyRequestContext {
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
	 * Create a coupon the way a subscription cart reads it.
	 *
	 * `session`: Subscriptions rebuilt it from the coupon properties it stored in the session, through its shop coupon data
	 * filter (Subscriptions 9.3.0 `includes/core/class-wcs-cart-renewal.php:1041-1077,1108-1166`). `virtual`: a coupon another
	 * plugin builds through the same filter (`virtual beside stored`: while the session holds another code). `order`: the pseudo coupon Subscriptions makes for an order discount (`:1813-1828`).
	 * `database`: a coupon read from the database.
	 *
	 * @param string               $origin Where the coupon comes from.
	 * @param array<string,string> $props  Coupon properties, the code included.
	 * @return \WC_Coupon
	 */
	private function create_subscription_cart_coupon( string $origin, array $props ): \WC_Coupon {
		$code = $props['code'];
		unset( $props['code'] );
		// Discount types Subscriptions registers (`includes/core/class-wc-subscriptions-coupon.php:136-147,1179-1189`).
		add_filter(
			'woocommerce_coupon_discount_types',
			static fn( array $types ): array => $types + array_fill_keys( array( 'recurring_percent', 'renewal_fee', 'renewal_cart', 'initial_cart' ), 'Subscriptions' )
		);

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
	 * @return MultiCurrencySubscriptionsCompatibilityController
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $subscriptions_available = true,
		bool $is_admin = false,
		bool $is_cron = false,
		bool $has_additional_currencies_enabled = true,
		float $rate = 2.0
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
			new LegacyProxy(),
			ServiceFactories::state_builder( $this, $this->create_state_builder( $has_additional_currencies_enabled ) ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter( $rate ) ) )
		);

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
	 * @param float $rate Rate it converts by.
	 * @return MultiCurrencyPriceConverter
	 */
	private function create_price_converter( float $rate = 2.0 ): MultiCurrencyPriceConverter {
		return new class( $rate ) extends MultiCurrencyPriceConverter {
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
			 * @param mixed  $price Price.
			 * @param string $type  Price type.
			 * @return float
			 */
			public function get_price( $price, string $type ): float {
				unset( $type );

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
