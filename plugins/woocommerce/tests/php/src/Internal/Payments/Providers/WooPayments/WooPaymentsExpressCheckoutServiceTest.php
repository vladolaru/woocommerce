<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\ExpressCheckoutExtensionDoubles;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsExpressCheckoutService class.
 */
class WooPaymentsExpressCheckoutServiceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_empty_cart();
		delete_option( 'woocommerce_default_country' );
		delete_option( 'woocommerce_currency' );
		delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );
		delete_option( 'woocommerce_tax_based_on' );
		delete_option( 'woocommerce_calc_taxes' );
		delete_option( 'woocommerce_prices_include_tax' );
		delete_option( 'woocommerce_price_num_decimals' );
		delete_option( 'woocommerce_enable_guest_checkout' );
		delete_option( 'woocommerce_enable_signup_and_login_from_checkout' );
		delete_option( 'woocommerce_enable_signup_from_checkout_for_subscriptions' );
		delete_option( 'woocommerce_registration_generate_username' );
		delete_option( 'woocommerce_registration_generate_password' );
		wp_set_current_user( 0 );
		unset( $_GET['pay_for_order'], $_GET['key'], $_GET['attribute_size'] );
		remove_all_filters( 'woocommerce_woopayments_express_checkout_enabled_methods' );
		remove_all_filters( 'wcpay_payment_request_supported_types' );
		remove_all_filters( 'wcpay_payment_request_is_cart_supported' );
		remove_all_filters( 'wcpay_payment_request_hide_itemization' );
		remove_all_filters( 'wcpay_payment_request_total_label' );
		remove_all_filters( 'wcpay_payment_request_total_label_suffix' );
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		$this->set_order_pay_query_var( 0 );
		unset(
			$GLOBALS['product'],
			$GLOBALS[ ExpressCheckoutExtensionDoubles::DEPOSIT_AMOUNT_PLAN_IDS ],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RESUBSCRIBE ],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SWITCHES ]
		);
		wp_reset_postdata();
		parent::tearDown();
	}

	/**
	 * @testdox Should require native provider readiness before exposing payment-request express checkout.
	 */
	public function test_payment_request_requires_provider_readiness(): void {
		$this->set_up_virtual_product_context( 'checkout' );
		$sut = $this->create_service( array(), false );

		$this->assertFalse( $sut->should_show_payment_request_button( 'checkout' ) );

		$sut = $this->create_service( array(), true );

		$this->assertTrue( $sut->should_show_payment_request_button( 'checkout' ) );
	}

	/**
	 * @testdox Should honor context-specific payment-request express checkout settings.
	 */
	public function test_payment_request_is_context_specific(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'regular_price' => '12.34',
				'virtual'       => true,
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );

		$sut = $this->create_service(
			array(
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);

		$this->assertTrue( $sut->should_show_payment_request_button( 'product' ) );
		$this->assertFalse( $sut->should_show_payment_request_button( 'cart' ) );
		$this->assertFalse( $sut->should_show_payment_request_button( 'checkout' ) );
	}

	/**
	 * @testdox Should fire the legacy cart-support filter for each cart product.
	 */
	public function test_cart_support_filter_is_fired_for_each_cart_product(): void {
		$first_product    = \WC_Helper_Product::create_simple_product();
		$second_product   = \WC_Helper_Product::create_simple_product();
		$filtered_product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $first_product->get_id() );
		WC()->cart->add_to_cart( $second_product->get_id() );
		$substitute_product = static function ( \WC_Product $product ) use ( $first_product, $filtered_product ): \WC_Product {
			return $first_product->get_id() === $product->get_id() ? $filtered_product : $product;
		};
		add_filter( 'woocommerce_cart_item_product', $substitute_product );

		$seen_product_ids = array();
		add_filter(
			'wcpay_payment_request_is_cart_supported',
			static function ( bool $supported, \WC_Product $product ) use ( &$seen_product_ids, $second_product ): bool {
				$seen_product_ids[] = $product->get_id();

				return $supported && $second_product->get_id() !== $product->get_id();
			},
			10,
			2
		);

		try {
			$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'cart' ) );
			$this->assertSame( array( $filtered_product->get_id(), $second_product->get_id() ), $seen_product_ids );
		} finally {
			remove_filter( 'woocommerce_cart_item_product', $substitute_product );
		}
	}

	/**
	 * @testdox Should honor the legacy cart-support filter during checkout.
	 */
	public function test_cart_support_filter_is_fired_during_checkout(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		add_filter( 'wcpay_payment_request_is_cart_supported', '__return_false' );

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'checkout' ) );
	}

	/**
	 * @testdox Should hide every express method on a no-shipping $context when tax follows the billing address and prices exclude tax.
	 *
	 * Client 11.1.0 button helper :634-651 and its test :521-544.
	 *
	 * @testWith ["product"]
	 *           ["cart"]
	 *           ["checkout"]
	 *
	 * @param string $context Express checkout context.
	 */
	public function test_hides_no_shipping_express_checkout_when_billing_tax_is_added_at_placement( string $context ): void {
		$this->set_billing_address_taxes( 'no' );
		$this->set_up_virtual_product_context( $context );

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( $context ) );
	}

	/**
	 * @testdox Should keep express checkout on a no-shipping $context when tax follows the billing address but prices include tax.
	 *
	 * Client 11.1.0 button helper test :470-496.
	 *
	 * @testWith ["product"]
	 *           ["cart"]
	 *           ["checkout"]
	 *
	 * @param string $context Express checkout context.
	 */
	public function test_keeps_no_shipping_express_checkout_when_prices_include_tax( string $context ): void {
		$this->set_billing_address_taxes( 'yes' );
		$this->set_up_virtual_product_context( $context );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( $context ) );
	}

	/**
	 * @testdox Should hide the express buttons on $context at page load when the cart total is zero.
	 *
	 * Client 11.1.0 button helper :652-660 and its test :981-1010. A free product, or a free-trial subscription with no
	 * sign-up fee, leaves nothing for the wallet sheet to charge now.
	 *
	 * @testWith ["cart"]
	 *           ["checkout"]
	 *
	 * @param string $context Express checkout context.
	 */
	public function test_hides_express_buttons_for_a_zero_cart_total( string $context ): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'regular_price' => '0',
				'price'         => '0',
				'virtual'       => true,
			)
		);
		WC()->cart->add_to_cart( $product->get_id() );
		WC()->cart->calculate_totals();

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( $context ) );
	}

	/**
	 * @testdox Should hide the product-page express button for a zero price even when a filter marks the product supported.
	 *
	 * Client 11.1.0 checks the raw price after `wcpay_payment_request_is_product_supported` (button helper :652-660,
	 * filter at :993), so the filter cannot bring the button back for a free product.
	 */
	public function test_hides_product_express_button_for_a_zero_price_whatever_the_support_filter_says(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'regular_price' => '0',
				'price'         => '0',
				'virtual'       => true,
			)
		);
		$this->set_current_product( $product );
		add_filter( 'wcpay_payment_request_is_product_supported', '__return_true' );

		try {
			$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'product' ) );
		} finally {
			remove_filter( 'wcpay_payment_request_is_product_supported', '__return_true' );
		}
	}

	/**
	 * @testdox Should keep express checkout for a cart that needs shipping when tax follows the billing address.
	 */
	public function test_keeps_express_checkout_for_shipping_cart_with_billing_address_taxes(): void {
		$this->set_billing_address_taxes( 'no' );
		\WC_Helper_Shipping::create_simple_flat_rate();
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id() );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'cart' ) );
	}

	/**
	 * @testdox Should keep express checkout on order-pay when tax follows the billing address.
	 */
	public function test_keeps_pay_for_order_express_checkout_with_billing_address_taxes(): void {
		$this->set_billing_address_taxes( 'no' );
		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->save();
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'pay_for_order' ) );
	}

	/**
	 * @testdox Should not show the express buttons on $context while express methods sit in the payment-method list.
	 *
	 * Client 11.1.0 button helper :582-586 and its test :937-952.
	 *
	 * @testWith ["product", "yes", false]
	 *           ["cart", "yes", false]
	 *           ["checkout", "yes", false]
	 *           ["checkout", "no", true]
	 *
	 * @param string $context  Express checkout context.
	 * @param string $in_list  The express_checkout_in_payment_methods setting.
	 * @param bool   $expected Whether the buttons show.
	 */
	public function test_express_buttons_follow_the_express_methods_in_payment_list_setting( string $context, string $in_list, bool $expected ): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		$this->set_up_virtual_product_context( $context );

		$sut = $this->create_service( array( 'express_checkout_in_payment_methods' => $in_list ) );

		$this->assertSame( $expected, $sut->should_show_payment_request_button( $context ) );
	}

	/**
	 * @testdox Should refuse a cart holding a product type outside the supported list, unless the supported types filter adds it.
	 *
	 * Client 11.1.0 button helper :726-730, after `woocommerce_cart_item_product`.
	 */
	public function test_cart_requires_supported_product_types(): void {
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id() );
		$external = $this->create_typed_product( 'external', '10.00' );
		add_filter( 'woocommerce_cart_item_product', static fn() => $external );

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'cart' ) );

		add_filter( 'wcpay_payment_request_supported_types', static fn( array $types ): array => array_merge( $types, array( 'external' ) ) );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'cart' ) );
	}

	/**
	 * @testdox Should refuse a cart with a WooCommerce Pre-Orders product charged $when, as the client does only for upon release.
	 *
	 * Client 11.1.0 button helper :717-722.
	 *
	 * @testWith ["upon_release", false]
	 *           ["upfront", true]
	 *
	 * @param string $when     When the pre-order is charged.
	 * @param bool   $expected Whether the buttons show.
	 */
	public function test_cart_refuses_pre_orders_charged_upon_release( string $when, bool $expected ): void {
		ExpressCheckoutExtensionDoubles::load_pre_orders();
		$product = \WC_Helper_Product::create_simple_product();
		$product->update_meta_data( '_wc_pre_orders_enabled', 'yes' );
		$product->update_meta_data( '_wc_pre_orders_when_to_charge', $when );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );

		$this->assertSame( $expected, $this->create_service()->should_show_payment_request_button( 'checkout' ) );
	}

	/**
	 * @testdox Should refuse a cart split into $count shipping packages when there is more than one.
	 *
	 * Client 11.1.0 button helper :744-748: the wallet sheet can only pick one rate.
	 *
	 * @testWith [2, false]
	 *           [1, true]
	 *
	 * @param int  $count    Number of shipping packages.
	 * @param bool $expected Whether the buttons show.
	 */
	public function test_cart_refuses_more_than_one_shipping_package( int $count, bool $expected ): void {
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id() );
		add_filter(
			'woocommerce_cart_shipping_packages',
			static function ( array $packages ) use ( $count ): array {
				return array_fill( 0, $count, $packages[0] );
			}
		);

		$this->assertSame( $expected, $this->create_service()->should_show_payment_request_button( 'cart' ) );
	}

	/**
	 * @testdox Should let the legacy itemization filter remove product-page display items.
	 */
	public function test_hide_itemization_filter_removes_product_display_items(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'regular_price' => '12.34',
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );
		add_filter( 'wcpay_payment_request_hide_itemization', '__return_true' );

		$data = $this->create_service()->get_express_checkout_params( 'product' )['product'];

		$this->assertArrayNotHasKey( 'displayItems', $data );
		$this->assertArrayHasKey( 'total', $data );
	}

	/**
	 * @testdox Should build reference-shaped product page express checkout params.
	 */
	public function test_builds_product_page_express_checkout_params(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Express Widget',
				'regular_price' => '12.34',
				'virtual'       => true,
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );

		$params  = $this->create_service()->get_express_checkout_params( 'product' );
		$product = $params['product'];

		$this->assertSame( 'product', $params['button_context'] );
		$this->assertSame( 'simple', $product['product_type'] );
		$this->assertSame( 'usd', $product['currency'] );
		$this->assertSame( 'US', $product['country_code'] );
		$this->assertFalse( $product['needs_shipping'] );
		$this->assertSame(
			array(
				array(
					'label'  => 'Express Widget',
					'amount' => 1234,
				),
			),
			$product['displayItems']
		);
		$this->assertSame( 1234, $product['total']['amount'] );
	}

	/**
	 * @testdox Should prepare product-page amounts in Stripe minor units, not WooCommerce price decimals.
	 */
	public function test_product_page_amounts_use_stripe_minor_units_for_zero_decimal_currency(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'JPY' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Yen Widget',
				'regular_price' => '1234',
				'virtual'       => true,
				'price'         => '1234',
			)
		);
		$this->set_current_product( $product );

		$params  = $this->create_service()->get_express_checkout_params( 'product' );
		$product = $params['product'];

		$this->assertSame( 1234, $product['displayItems'][0]['amount'] );
		$this->assertSame( 1234, $product['total']['amount'] );
	}

	/**
	 * @testdox Should add the sign-up fee to the product-page amount of a $type product.
	 *
	 * Client 11.1.0 button helper `get_product_price()` :1052-1061.
	 *
	 * @testWith ["subscription", 1500]
	 *           ["subscription_variation", 1500]
	 *           ["variable-subscription", 1000]
	 *           ["simple", 1000]
	 *
	 * @param string $type     Product type.
	 * @param int    $expected Expected amount in minor units.
	 */
	public function test_product_page_amount_includes_the_subscription_sign_up_fee( string $type, int $expected ): void {
		WooCommerceSubscriptionsDoubles::load_product();
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$product = $this->create_typed_product( $type, '10.00' );
		$product->update_meta_data( '_subscription_sign_up_fee', '5.00' );
		$product->save();

		$data = $this->get_product_page_data( $product );

		$this->assertSame( $expected, $data['total']['amount'] );
		$this->assertSame( $expected, $data['displayItems'][0]['amount'] );
	}

	/**
	 * @testdox Should keep hiding product-page express checkout for a free subscription whose sign-up fee is the only charge.
	 *
	 * Client 11.1.0 button helper :655-658 checks the product's raw price, not the amount with the fee.
	 */
	public function test_product_page_hides_a_free_subscription_with_only_a_sign_up_fee(): void {
		WooCommerceSubscriptionsDoubles::load_product();
		$product = $this->create_typed_product( 'subscription', '0' );
		$product->update_meta_data( '_subscription_sign_up_fee', '5.00' );
		$product->save();
		$GLOBALS['product'] = $product;
		$shown              = null;
		$capture            = function () use ( &$shown ): void {
			$shown = $this->create_service()->should_show_payment_request_button( 'product' );
		};
		add_action( 'woocommerce_after_add_to_cart_form', $capture );
		do_action( 'woocommerce_after_add_to_cart_form' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core hook, fired to render the product form context.

		$this->assertFalse( $shown );
	}

	/**
	 * @testdox Should show the WooCommerce Deposits amount of the product's default choice on the product page ($scenario).
	 *
	 * Client 11.1.0 button helper `get_product_price()` :1031-1050, with the client's Deposits test helper as the double.
	 *
	 * @testWith ["deposit by default", "percent", "deposit", [], 1000, [0]]
	 *           ["full payment by default", "percent", "full", [], 4000, []]
	 *           ["payment plan, first plan", "plan", "deposit", [7, 9], 1500, [7]]
	 *           ["payment plan, no plans", "plan", "deposit", [], 1500, [0]]
	 *
	 * @param string $scenario      Scenario label.
	 * @param string $deposit_type  Deposit type meta.
	 * @param string $selected_type Default selected type meta.
	 * @param int[]  $plans         Payment plan IDs.
	 * @param int    $expected      Expected amount in minor units.
	 * @param int[]  $plan_ids      Plan IDs the deposit amount is asked for.
	 */
	public function test_product_page_amount_uses_the_deposits_default_choice( string $scenario, string $deposit_type, string $selected_type, array $plans, int $expected, array $plan_ids ): void {
		unset( $scenario );
		ExpressCheckoutExtensionDoubles::load_deposits();
		$GLOBALS[ ExpressCheckoutExtensionDoubles::DEPOSIT_AMOUNT_PLAN_IDS ] = array();
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$product = $this->create_typed_product( 'simple', '40.00' );
		$product->update_meta_data( '_wc_deposit_enabled', 'optional' );
		$product->update_meta_data( '_wc_deposit_type', $deposit_type );
		$product->update_meta_data( '_wc_deposit_selected_type', $selected_type );
		$product->update_meta_data( '_wc_deposit_amount', 'percent' === $deposit_type ? '25' : '15' );
		$product->update_meta_data( '_wc_deposit_payment_plans', $plans );
		$product->save();

		$data = $this->get_product_page_data( $product );

		$this->assertSame( $expected, $data['total']['amount'] );
		$this->assertSame( $plan_ids, $GLOBALS[ ExpressCheckoutExtensionDoubles::DEPOSIT_AMOUNT_PLAN_IDS ] );
	}

	/**
	 * @testdox Should send no product-page data when the Deposits amount is not a number, so the page prices an ephemeral cart instead.
	 *
	 * Client 11.1.0 button helper :1063-1072 throws, and `get_product_data()` returns false (:789-794).
	 */
	public function test_product_page_data_is_empty_without_a_numeric_price(): void {
		ExpressCheckoutExtensionDoubles::load_deposits();
		$product = $this->create_typed_product( 'simple', '40.00' );
		$product->update_meta_data( '_wc_deposit_enabled', 'forced' );
		$product->update_meta_data( '_wc_deposit_type', 'percent' );
		$product->update_meta_data( '_wc_deposit_selected_type', 'deposit' );
		$product->save();

		$this->assertSame( array(), $this->get_product_page_data( $product ) );
	}

	/**
	 * @testdox Should price the variation the product form starts with on a variable product page ($scenario).
	 *
	 * Client 11.1.0 button helper `get_product_data()` :768-786: an attribute in the URL wins over the default
	 * attribute, and the matching variation is the product that gets priced.
	 *
	 * @testWith ["default variation", "", 2000]
	 *           ["URL attribute over the default", "L", 3000]
	 *
	 * @param string $scenario       Scenario label.
	 * @param string $url_attribute  Size passed in the URL, or empty for none.
	 * @param int    $expected       Expected amount in minor units.
	 */
	public function test_product_page_prices_the_initially_selected_variation( string $scenario, string $url_attribute, int $expected ): void {
		unset( $scenario );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$product = $this->create_sized_variable_product( 'M' );
		if ( '' !== $url_attribute ) {
			$_GET['attribute_size'] = $url_attribute;
		}

		$data = $this->get_product_page_data( $product );

		$this->assertSame( $expected, $data['total']['amount'] );
		$this->assertSame( $expected, $data['displayItems'][0]['amount'] );
		$this->assertSame( 'variation', $data['product_type'] );
	}

	/**
	 * @testdox Should price a variable product without a default variation at its own price.
	 */
	public function test_product_page_prices_a_variable_product_without_a_default_variation(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$product = $this->create_sized_variable_product( '' );

		$data = $this->get_product_page_data( $product );

		$this->assertSame( 1000, $data['total']['amount'] );
		$this->assertSame( 'variable', $data['product_type'] );
	}

	/**
	 * @testdox Should flag has_subscription on the product page for a subscription product.
	 */
	public function test_product_page_has_subscription_for_subscription_product(): void {
		WooCommerceSubscriptionsDoubles::load_product();

		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Sub Widget',
				'regular_price' => '10',
				'virtual'       => true,
				'price'         => '10',
			)
		);
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ] = array( $product->get_id() );
		$this->set_current_product( $product );

		$params = $this->create_service()->get_express_checkout_params( 'product' );

		$this->assertTrue( $params['has_subscription'] );
	}

	/**
	 * @testdox Should not use an unrelated cart subscription on an ordinary product page.
	 */
	public function test_product_page_subscription_context_ignores_global_cart(): void {
		WooCommerceSubscriptionsDoubles::load_product();
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ] = true;

		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Regular Widget',
				'regular_price' => '10',
				'virtual'       => true,
				'price'         => '10',
			)
		);
		$this->set_current_product( $product );

		$this->assertFalse( $this->create_service()->get_express_checkout_params( 'product' )['has_subscription'] );
	}

	/**
	 * @testdox Should expose subscription state for each supported cart detector.
	 *
	 * @dataProvider provider_checkout_subscription_contexts
	 *
	 * @param bool        $initial     Whether the initial cart detector matches.
	 * @param array|false $renewal     Renewal detector result.
	 * @param array|false $resubscribe Resubscribe detector result.
	 * @param array|false $switch_result Switch detector result.
	 * @param bool        $expected    Expected localized subscription state.
	 */
	public function test_checkout_subscription_context_uses_supported_cart_detectors( bool $initial, $renewal, $resubscribe, $switch_result, bool $expected ): void {
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ] = $initial;
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ]      = $renewal;
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RESUBSCRIBE ]  = $resubscribe;
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SWITCHES ]     = $switch_result;

		$this->assertSame( $expected, $this->create_service()->get_express_checkout_params( 'checkout' )['has_subscription'] );
	}

	/**
	 * Provide initial, renewal, resubscribe, switch, and ordinary cart states.
	 *
	 * @return array<string,array{bool,array|false,array|false,array|false,bool}>
	 */
	public function provider_checkout_subscription_contexts(): array {
		return array(
			'initial subscription' => array( true, false, false, false, true ),
			'renewal'              => array( false, array( 'renewal' ), false, false, true ),
			'resubscribe'          => array( false, false, array( 'resubscribe' ), false, true ),
			'switch'               => array( false, false, false, array( 'switch' ), true ),
			'ordinary cart'        => array( false, false, false, false, false ),
		);
	}

	/**
	 * @testdox Should require login confirmation on a subscription product page even with guest checkout enabled.
	 */
	public function test_login_confirmation_required_for_subscription_product_page(): void {
		WooCommerceSubscriptionsDoubles::load_product();

		wp_set_current_user( 0 );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_from_checkout_for_subscriptions', 'no' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Sub Widget',
				'regular_price' => '10',
				'virtual'       => true,
				'price'         => '10',
			)
		);
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ] = array( $product->get_id() );
		$this->set_current_product( $product );

		$params = $this->create_service()->get_express_checkout_params( 'product' );

		$this->assertIsArray( $params['login_confirmation'] );
	}

	/**
	 * @testdox Should not require login confirmation on a subscription product page when subscription signup is allowed.
	 */
	public function test_login_confirmation_false_when_subscription_signup_possible(): void {
		WooCommerceSubscriptionsDoubles::load_product();

		wp_set_current_user( 0 );
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_from_checkout_for_subscriptions', 'yes' );
		update_option( 'woocommerce_registration_generate_username', 'yes' );
		update_option( 'woocommerce_registration_generate_password', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Sub Widget',
				'regular_price' => '10',
				'virtual'       => true,
				'price'         => '10',
			)
		);
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ] = array( $product->get_id() );
		$this->set_current_product( $product );

		$params = $this->create_service()->get_express_checkout_params( 'product' );

		$this->assertFalse( $params['login_confirmation'] );
	}

	/**
	 * @testdox Should compute login confirmation settings when authentication is required.
	 */
	public function test_login_confirmation_settings_when_authentication_required(): void {
		wp_set_current_user( 0 );
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertIsArray( $params['login_confirmation'] );
		$this->assertStringContainsString( '**the selected payment method**', $params['login_confirmation']['message'] );
		$this->assertStringContainsString( 'wcpay_express_checkout_redirect_url=', $params['login_confirmation']['redirect_url'] );
		$this->assertStringContainsString( '_wpnonce=', $params['login_confirmation']['redirect_url'] );
	}

	/**
	 * @testdox Should not require login confirmation when guest checkout is enabled.
	 */
	public function test_login_confirmation_false_when_guest_checkout_enabled(): void {
		wp_set_current_user( 0 );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertFalse( $params['login_confirmation'] );
	}

	/**
	 * @testdox Should not require login confirmation for logged-in shoppers.
	 */
	public function test_login_confirmation_false_for_logged_in_user(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertFalse( $params['login_confirmation'] );
	}

	/**
	 * @testdox Should not require login confirmation when checkout signup is possible.
	 */
	public function test_login_confirmation_false_when_account_creation_possible(): void {
		wp_set_current_user( 0 );
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_registration_generate_username', 'yes' );
		update_option( 'woocommerce_registration_generate_password', 'yes' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertFalse( $params['login_confirmation'] );
	}

	/**
	 * @testdox Should build the product total label from the apostrophe-free account statement descriptor and exact default suffix.
	 */
	public function test_product_total_label_matches_statement_descriptor_oracle(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Express Widget',
				'regular_price' => '12.34',
				'virtual'       => true,
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );

		$params = $this->create_service( array(), true, array( 'statement_descriptor' => "Merchant's Store" ) )->get_express_checkout_params( 'product' );

		$this->assertSame( 'Merchants Store (via WooCommerce)', $params['product']['total']['label'] );
	}

	/**
	 * @testdox Should use the exact suffix-only fallback when the account statement descriptor is absent.
	 */
	public function test_product_total_label_matches_empty_descriptor_fallback(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Express Widget',
				'regular_price' => '12.34',
				'virtual'       => true,
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );

		$params = $this->create_service()->get_express_checkout_params( 'product' );

		$this->assertSame( ' (via WooCommerce)', $params['product']['total']['label'] );
	}

	/**
	 * @testdox Should apply suffix and total-label filters once with the exact oracle values and uncast suffix behavior.
	 */
	public function test_product_total_label_preserves_filter_shape_timing_and_type_behavior(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Express Widget',
				'regular_price' => '12.34',
				'virtual'       => true,
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );

		$suffix_filter_calls = array();
		$total_filter_calls  = array();
		add_filter(
			'wcpay_payment_request_total_label_suffix',
			static function ( ...$suffix_args ) use ( &$suffix_filter_calls ): int {
				$suffix_filter_calls[] = $suffix_args;
				return 7;
			}
		);
		add_filter(
			'wcpay_payment_request_total_label',
			static function ( $label ) use ( &$total_filter_calls ) {
				$total_filter_calls[] = func_get_args();
				return $label;
			}
		);

		$params = $this->create_service( array(), true, array( 'statement_descriptor' => 'Merchant' ) )->get_express_checkout_params( 'product' );

		$this->assertSame( 'Merchant7', $params['product']['total']['label'] );
		$this->assertSame( array( array( ' (via WooCommerce)' ) ), $suffix_filter_calls );
		$this->assertSame( array( array( 'Merchant7' ) ), $total_filter_calls );
	}

	/**
	 * @testdox Should include a pending shipping display item for physical product express checkout.
	 */
	public function test_physical_product_includes_pending_shipping_display_item(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$zone               = new \WC_Shipping_Zone( 0 );
		$shipping_method_id = $zone->add_shipping_method( 'flat_rate' );
		$product            = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Shipped Widget',
				'regular_price' => '12.34',
				'virtual'       => false,
				'price'         => '12.34',
			)
		);
		$this->set_current_product( $product );

		try {
			$product_data = $this->create_service()->get_express_checkout_params( 'product' )['product'];
		} finally {
			$zone->delete_shipping_method( $shipping_method_id );
		}

		$this->assertTrue( $product_data['needs_shipping'] );
		$this->assertSame(
			array(
				array(
					'label'  => 'Shipped Widget',
					'amount' => 1234,
				),
				array(
					'label'   => 'Shipping',
					'amount'  => 0,
					'pending' => true,
				),
			),
			$product_data['displayItems']
		);
		$this->assertSame(
			array(
				'id'     => 'pending',
				'label'  => 'Pending',
				'detail' => '',
				'amount' => 0,
			),
			$product_data['shippingOptions']
		);
	}

	/**
	 * @testdox Should build product page express checkout params for product_page shortcode pages.
	 * @dataProvider product_page_shortcode_syntax_provider
	 *
	 * @param string $shortcode_template Product-page shortcode template.
	 * @param bool   $use_sku            Whether to substitute an SKU instead of an ID.
	 */
	public function test_builds_product_page_shortcode_express_checkout_params( string $shortcode_template, bool $use_sku ): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Shortcode Widget',
				'regular_price' => '7.89',
				'virtual'       => true,
				'price'         => '7.89',
			)
		);
		$product->set_sku( 'shortcode-widget-' . $product->get_id() );
		$product->save();
		$value = $use_sku ? $product->get_sku() : (string) $product->get_id();
		$this->set_current_page_with_content( sprintf( $shortcode_template, $value ) );
		unset( $GLOBALS['product'] );

		$service = $this->create_service();
		$params  = $service->get_express_checkout_params( 'product' );

		$this->assertTrue( $service->should_show_payment_request_button( 'product' ) );
		$this->assertSame( 'Shortcode Widget', $params['product']['displayItems'][0]['label'] );
		$this->assertSame( 789, $params['product']['total']['amount'] );
	}

	/**
	 * Provide supported product-page shortcode syntaxes.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function product_page_shortcode_syntax_provider(): array {
		return array(
			'double-quoted ID'     => array( '[product_page id="%s"]', false ),
			'single-quoted ID'     => array( "[product_page id='%s']", false ),
			'unquoted ID'          => array( '[product_page id=%s]', false ),
			'attributes after ID'  => array( '[product_page id="%s" show_title="false"]', false ),
			'attributes before ID' => array( '[product_page show_title="false" id="%s"]', false ),
			'double-quoted SKU'    => array( '[product_page sku="%s"]', true ),
			'single-quoted SKU'    => array( "[product_page sku='%s']", true ),
			'unquoted SKU'         => array( '[product_page sku=%s]', true ),
		);
	}

	/**
	 * @testdox Should ignore an unrelated global product on product_page shortcode hosts.
	 */
	public function test_product_page_shortcode_ignores_unrelated_global_product(): void {
		$shortcode_product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Shortcode Product',
				'price'         => '12.34',
				'regular_price' => '12.34',
				'virtual'       => true,
			)
		);
		$unrelated_product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Unrelated Product',
				'price'         => '98.76',
				'regular_price' => '98.76',
				'virtual'       => true,
			)
		);
		$this->set_current_page_with_content( '[product_page id="' . $shortcode_product->get_id() . '"]' );
		$GLOBALS['product'] = $unrelated_product;

		$service = $this->create_service();
		$params  = $service->get_express_checkout_params( 'product' );

		$this->assertTrue( $service->should_show_payment_request_button( 'product' ) );
		$this->assertSame( 'Shortcode Product', $params['product']['displayItems'][0]['label'] );
		$this->assertSame( 1234, $params['product']['total']['amount'] );

		$hook_label        = '';
		$read_hook_product = static function () use ( $service, &$hook_label ): void {
			$hook_label = $service->get_express_checkout_params( 'product' )['product']['displayItems'][0]['label'];
		};
		add_action( 'woocommerce_after_add_to_cart_form', $read_hook_product );

		try {
			/**
			 * Fires after the add-to-cart form so the product global has a defined owner.
			 *
			 * @since 11.2.0
			 */
			do_action( 'woocommerce_after_add_to_cart_form' );
		} finally {
			remove_action( 'woocommerce_after_add_to_cart_form', $read_hook_product );
		}

		$this->assertSame( 'Unrelated Product', $hook_label );
	}

	/**
	 * @testdox Should resolve a product_page SKU once per request.
	 */
	public function test_product_page_shortcode_resolves_sku_once_per_request(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Cached SKU Product',
				'price'         => '12.34',
				'regular_price' => '12.34',
				'virtual'       => true,
			)
		);
		$product->set_sku( 'cached-shortcode-sku' );
		$product->save();
		$this->set_current_page_with_content( '[product_page sku="cached-shortcode-sku"]' );
		unset( $GLOBALS['product'] );

		$lookups      = 0;
		$count_lookup = static function ( $product_id ) use ( &$lookups ) {
			++$lookups;
			return $product_id;
		};
		add_filter( 'woocommerce_get_product_id_by_sku', $count_lookup );

		try {
			$service = $this->create_service();
			$this->assertTrue( $service->should_show_payment_request_button( 'product' ) );
			$this->assertSame( 'Cached SKU Product', $service->get_express_checkout_params( 'product' )['product']['displayItems'][0]['label'] );
			$this->assertTrue( $service->should_show_payment_request_button( 'product' ) );
			$this->assertSame( 'Cached SKU Product', $service->get_express_checkout_params( 'product' )['product']['displayItems'][0]['label'] );
		} finally {
			remove_filter( 'woocommerce_get_product_id_by_sku', $count_lookup );
		}

		$this->assertSame( 1, $lookups );
	}

	/**
	 * @testdox Should ignore escaped product_page shortcode text.
	 */
	public function test_product_page_shortcode_ignores_escaped_shortcode_text(): void {
		$product = \WC_Helper_Product::create_simple_product( true );
		$this->set_current_page_with_content( '[[product_page id="' . $product->get_id() . '"]]' );
		unset( $GLOBALS['product'] );

		$service = $this->create_service();

		$this->assertFalse( $service->should_show_payment_request_button( 'product' ) );
		$this->assertSame( array(), $service->get_express_checkout_params( 'product' )['product'] );
	}

	/**
	 * @testdox Should keep the first live product_page shortcode result when its product is missing.
	 */
	public function test_product_page_shortcode_does_not_skip_a_missing_first_product(): void {
		$product = \WC_Helper_Product::create_simple_product( true );
		$this->set_current_page_with_content( '[product_page id="999999"] [product_page id="' . $product->get_id() . '"]' );
		unset( $GLOBALS['product'] );

		$service = $this->create_service();

		$this->assertFalse( $service->should_show_payment_request_button( 'product' ) );
		$this->assertSame( array(), $service->get_express_checkout_params( 'product' )['product'] );
	}

	/**
	 * @testdox Should resolve product_page shortcode context after an early query call.
	 */
	public function test_product_page_shortcode_context_recovers_after_an_early_call(): void {
		$this->reset_main_query();
		$service = $this->create_service();
		$this->assertFalse( $service->should_show_payment_request_button( 'product' ) );

		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Late Query Product',
				'price'         => '12.34',
				'regular_price' => '12.34',
				'virtual'       => true,
			)
		);
		$this->set_current_page_with_content( '[product_page id="' . $product->get_id() . '"]' );
		unset( $GLOBALS['product'] );

		$this->assertTrue( $service->should_show_payment_request_button( 'product' ) );
		$this->assertSame( 'Late Query Product', $service->get_express_checkout_params( 'product' )['product']['displayItems'][0]['label'] );
	}

	/**
	 * @testdox Should fail closed for product-page express checkout when the product has no payable amount.
	 */
	public function test_payment_request_fails_closed_for_zero_amount_product(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'virtual'       => true,
				'price'         => '0',
				'regular_price' => '0',
			)
		);
		$this->set_current_product( $product );

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'product' ) );
	}

	/**
	 * @testdox Should apply product availability after the public product-support filters.
	 */
	public function test_payment_request_requires_purchasable_product_after_support_filters(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'virtual'       => true,
				'price'         => '12.34',
				'regular_price' => '12.34',
			)
		);
		$this->set_current_product( $product );

		$events        = array();
		$legacy_filter = static function ( bool $supported ) use ( &$events ): bool {
			$events[] = 'legacy';
			return $supported;
		};
		$native_filter = static function ( bool $supported ) use ( &$events ): bool {
			$events[] = 'native';
			self::assertTrue( $supported );
			return true;
		};
		$deny_purchase = static function ( bool $purchasable, \WC_Product $filtered_product ) use ( &$events, $product ): bool {
			if ( $filtered_product->get_id() === $product->get_id() ) {
				$events[] = 'purchasable';
				return false;
			}

			return $purchasable;
		};
		add_filter( 'wcpay_payment_request_is_product_supported', $legacy_filter );
		add_filter( 'woocommerce_woopayments_express_checkout_is_product_supported', $native_filter );
		add_filter( 'woocommerce_is_purchasable', $deny_purchase, 10, 2 );

		try {
			$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'product' ) );
			$this->assertSame( array( 'legacy', 'native', 'purchasable' ), $events );
		} finally {
			remove_filter( 'wcpay_payment_request_is_product_supported', $legacy_filter );
			remove_filter( 'woocommerce_woopayments_express_checkout_is_product_supported', $native_filter );
			remove_filter( 'woocommerce_is_purchasable', $deny_purchase );
		}
	}

	/**
	 * @testdox Should hide product-page payment request buttons when the product is out of stock.
	 */
	public function test_payment_request_requires_in_stock_product(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'virtual'       => true,
				'price'         => '12.34',
				'regular_price' => '12.34',
			)
		);
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$this->set_current_product( $product );

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'product' ) );
	}

	/**
	 * @testdox Should show product-page payment request buttons for backordered products.
	 */
	public function test_payment_request_allows_backordered_product(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'virtual'       => true,
				'price'         => '12.34',
				'regular_price' => '12.34',
			)
		);
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_backorders( 'yes' );
		$product->save();
		$this->set_current_product( $product );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'product' ) );
	}

	/**
	 * @testdox Should preserve the public WooPayments supported product types filter contract.
	 */
	public function test_product_support_preserves_public_supported_types_filter_contract(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'virtual'       => true,
				'price'         => '12.34',
				'regular_price' => '12.34',
			)
		);
		$this->set_current_product( $product );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'product' ) );

		add_filter(
			'wcpay_payment_request_supported_types',
			static function (): array {
				return array();
			}
		);

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'product' ) );
	}

	/**
	 * @testdox Should use location-centric payment-request settings when the migrated legacy switch is absent.
	 */
	public function test_payment_request_uses_location_settings_without_legacy_switch(): void {
		$this->set_up_virtual_product_context( 'checkout' );
		$sut = $this->create_service(
			array(
				'express_checkout_product_methods'  => array(),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			),
			true,
			array(),
			false
		);

		$this->assertTrue( $sut->should_show_payment_request_button( 'checkout' ) );
	}

	/**
	 * @testdox Should fall back to the legacy payment-request switch when per-context settings are absent.
	 */
	public function test_payment_request_uses_legacy_switch_when_context_settings_are_absent(): void {
		$this->set_up_virtual_product_context( 'checkout' );
		$sut = $this->create_service(
			array(
				'payment_request' => 'yes',
			),
			true,
			array(),
			false
		);

		$this->assertTrue( $sut->should_show_payment_request_button( 'checkout' ) );
	}

	/**
	 * @testdox Should build reference-shaped ECE params for Apple Pay and Google Pay.
	 */
	public function test_builds_payment_request_express_checkout_params(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );

		$sut    = $this->create_service(
			array(
				'manual_capture'                       => 'yes',
				'payment_request_button_type'          => 'buy',
				'payment_request_button_theme'         => 'light',
				'payment_request_button_size'          => 'large',
				'payment_request_button_border_radius' => '6',
			)
		);
		$params = $sut->get_express_checkout_params( 'checkout' );

		$this->assertSame( 'pk_test_123', $params['stripe']['publishableKey'] );
		$this->assertSame( 'acct_123', $params['stripe']['accountId'] );
		$this->assertSame( 'checkout', $params['button_context'] );
		$this->assertSame( array( 'payment_request' ), $params['enabled_methods'] );
		$this->assertSame( get_bloginfo( 'name' ), $params['store_name'] );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $params['ajax_url'] );
		$this->assertSame( \WC_AJAX::get_endpoint( '%%endpoint%%' ), $params['wc_ajax_url'] );
		$this->assertSame( 'usd', $params['checkout']['currency_code'] );
		$this->assertSame( 2, $params['checkout']['stripe_minor_unit'] );
		$this->assertSame( 'US', $params['checkout']['country_code'] );
		$this->assertTrue( $params['is_manual_capture'] );
		$this->assertSame( 'buy', $params['button']['type'] );
		$this->assertSame( 'light', $params['button']['theme'] );
		$this->assertSame( '55', $params['button']['height'] );
		$this->assertSame( '6', $params['button']['radius'] );
		$this->assertSame( 'large', $params['button']['size'] );
		$this->assertTrue( $params['isShopperTrackingEnabled'] );
		$this->assertTrue( $params['is_shopper_tracking_enabled'] );
		$this->assertArrayHasKey( 'platform_tracker', $params['nonce'] );
		$this->assertArrayHasKey( 'tokenized_cart_nonce', $params['nonce'] );
		$this->assertArrayHasKey( 'tokenized_cart_session_nonce', $params['nonce'] );
		$this->assertArrayHasKey( 'store_api_nonce', $params['nonce'] );
		$this->assertTrue( $params['flags']['isEceUsingConfirmationTokens'] );
	}

	/**
	 * @testdox Express params expose the account confirmation-token policy.
	 */
	public function test_express_params_disable_confirmation_tokens_from_account_policy(): void {
		$params = $this->create_service(
			array(),
			true,
			array( 'ece_confirmation_tokens_disabled' => true )
		)->get_express_checkout_params( 'checkout' );

		$this->assertFalse( $params['flags']['isEceUsingConfirmationTokens'] );
	}

	/**
	 * @testdox Should use the Stripe zero-decimal minor unit for zero-decimal currencies.
	 */
	public function test_express_checkout_params_use_stripe_zero_minor_unit_for_zero_decimal_currency(): void {
		update_option( 'woocommerce_default_country', 'JP' );
		update_option( 'woocommerce_currency', 'JPY' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertSame( 'jpy', $params['checkout']['currency_code'] );
		$this->assertSame( 0, $params['checkout']['stripe_minor_unit'] );
	}

	/**
	 * @testdox Should keep Stripe two-decimal minor units for Stripe special-case currencies.
	 */
	public function test_express_checkout_params_keep_stripe_two_minor_unit_for_special_case_currency(): void {
		update_option( 'woocommerce_default_country', 'UG' );
		update_option( 'woocommerce_currency', 'UGX' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertSame( 'ugx', $params['checkout']['currency_code'] );
		$this->assertSame( 2, $params['checkout']['stripe_minor_unit'] );
	}

	/**
	 * @testdox Should expose disabled shopper tracking to express checkout clients.
	 */
	public function test_express_checkout_params_expose_disabled_shopper_tracking(): void {
		$params = $this->create_service(
			array(),
			true,
			array(),
			true,
			false
		)->get_express_checkout_params( 'checkout' );

		$this->assertFalse( $params['isShopperTrackingEnabled'] );
		$this->assertFalse( $params['is_shopper_tracking_enabled'] );
	}

	/**
	 * @testdox Should allow the enabled platform methods list to be narrowed without coupling it to WooPay.
	 */
	public function test_enabled_methods_can_be_filtered(): void {
		add_filter(
			'woocommerce_woopayments_express_checkout_enabled_methods',
			static function ( array $methods, string $context ): array {
				return 'checkout' === $context ? array( 'payment_request', 'klarna' ) : $methods;
			},
			10,
			2
		);

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertSame( array( 'payment_request', 'klarna' ), $params['enabled_methods'] );
	}

	/**
	 * @testdox Should pass the store currency, never an empty one, to the enabled-methods filter on the button path.
	 *
	 * The charge path passes the order currency (`WooPaymentsIntentRequestBuilder`), so a callback keyed on the currency
	 * must see a real currency on the button path too, or it shows a method the charge then refuses (review 46 F1).
	 */
	public function test_enabled_methods_filter_gets_the_store_currency_on_the_button_path(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->set_up_virtual_product_context( 'checkout' );
		$seen = array();
		add_filter(
			'woocommerce_woopayments_express_checkout_enabled_methods',
			static function ( array $methods, string $context, string $currency ) use ( &$seen ): array {
				unset( $context );
				$seen[] = $currency;

				return 'EUR' === $currency ? $methods : array();
			},
			10,
			3
		);

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'checkout' ) );
		$this->assertSame( array( 'EUR' ), array_values( array_unique( $seen ) ) );
	}

	/**
	 * @testdox Should expose server-authoritative Stripe payment method types for enabled express methods.
	 */
	public function test_allowed_payment_method_types_include_eligible_amazon_pay(): void {
		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$this->assertSame( array( 'card', 'amazon_pay' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ) );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $sut->get_enabled_methods_for_context( 'checkout' ) );
		$this->assertSame( array( 'card', 'amazon_pay' ), $sut->get_express_checkout_params( 'checkout' )['payment_method_types'] );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $sut->get_express_checkout_params( 'checkout' )['enabled_methods'] );
	}

	/**
	 * @testdox Should offer Amazon Pay in every express checkout surface only while each of the client's availability conditions holds.
	 *
	 * @dataProvider provider_amazon_pay_availability_conditions
	 *
	 * @param array<string,mixed> $settings  Gateway setting overrides.
	 * @param bool                $test_mode Whether the account is in test mode.
	 * @param bool                $in_list   Whether the dynamic checkout feature is on (express methods may sit in the payment-method list).
	 * @param bool                $expected  Whether Amazon Pay should be usable.
	 */
	public function test_amazon_pay_honors_client_availability_conditions( array $settings, bool $test_mode, bool $in_list, bool $expected ): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', $in_list ? '1' : '0' );
		$sut = $this->create_service(
			array_merge(
				array(
					'express_checkout_product_methods'  => array( 'payment_request', 'amazon_pay' ),
					'express_checkout_cart_methods'     => array( 'payment_request', 'amazon_pay' ),
					'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
					'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				),
				$settings
			),
			true,
			array( 'ece_confirmation_tokens_disabled' => false ),
			true,
			true,
			$test_mode
		);

		$this->assertSame( $expected, $sut->can_use_amazon_pay( 'USD' ), 'Store API extension data' );
		$this->assertSame( $expected, $sut->is_amazon_pay_usable( 'checkout', 'USD' ), 'Location-independent usability' );
		$this->assertSame( $expected ? array( 'payment_request', 'amazon_pay' ) : array( 'payment_request' ), $sut->get_enabled_methods_for_context( 'checkout' ), 'Button enabled methods' );
		$this->assertSame( $expected ? array( 'card', 'amazon_pay' ) : array( 'card' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ), 'Button payment method types' );
	}

	/**
	 * Data provider for the Amazon Pay availability conditions.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:bool,3:bool}>
	 */
	public function provider_amazon_pay_availability_conditions(): array {
		return array(
			'all conditions hold'                        => array( array(), true, false, true ),
			'merchant switched amazon pay off'           => array( array( 'upe_enabled_payment_method_ids' => array( 'card' ) ), true, false, false ),
			'express methods in the payment-method list' => array( array( 'express_checkout_in_payment_methods' => 'yes' ), true, true, false ),
			'list setting on without the feature flag'   => array( array( 'express_checkout_in_payment_methods' => 'yes' ), true, false, true ),
			'woopayments gateway disabled'               => array( array( 'enabled' => 'no' ), true, false, false ),
			'live mode without https'                    => array( array(), false, false, false ),
		);
	}

	/**
	 * @testdox Should skip the live-mode HTTPS guard for Amazon Pay in admin, like the client's express availability check.
	 */
	public function test_amazon_pay_skips_https_guard_in_admin(): void {
		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
			),
			true,
			array( 'ece_confirmation_tokens_disabled' => false ),
			true,
			true,
			false
		);

		$this->assertFalse( $sut->can_use_amazon_pay( 'USD' ), 'Storefront requires HTTPS in live mode.' );

		$GLOBALS['current_screen'] = \WP_Screen::get( 'dashboard' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			$this->assertTrue( $sut->can_use_amazon_pay( 'USD' ) );
		} finally {
			unset( $GLOBALS['current_screen'] );
		}
	}

	/**
	 * @testdox Should reject a submitted Amazon Pay payment type once the merchant switched Amazon Pay off.
	 */
	public function test_payment_time_allowlist_rejects_amazon_pay_the_merchant_switched_off(): void {
		$settings = array(
			'enabled'                           => 'yes',
			'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
			'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
			'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
		);
		$eligible = array( 'ece_confirmation_tokens_disabled' => false );

		$enabled  = $this->create_account_service( $settings, $eligible );
		$disabled = $this->create_account_service( array_merge( $settings, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) ), $eligible );

		$this->assertSame( array( 'card', 'amazon_pay' ), WooPaymentsExpressPaymentMethodTypes::get_allowed_payment_method_types_for_context( $enabled, 'checkout', 'USD' ) );
		$this->assertSame( array( 'card' ), WooPaymentsExpressPaymentMethodTypes::get_allowed_payment_method_types_for_context( $disabled, 'checkout', 'USD' ) );
	}

	/**
	 * @testdox Should tell Amazon Pay usable when any express checkout location enables it and the account is eligible.
	 */
	public function test_can_use_amazon_pay_checks_every_location_and_eligibility(): void {
		$eligible = array( 'ece_confirmation_tokens_disabled' => false );

		$only_product = $this->create_service(
			array(
				'express_checkout_product_methods'  => array( 'amazon_pay' ),
				'express_checkout_cart_methods'     => array( 'payment_request' ),
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			),
			true,
			$eligible
		);
		$no_location  = $this->create_service( array(), true, $eligible );
		$unavailable  = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card' ),
			),
			true,
			$eligible
		);

		$this->assertTrue( $only_product->can_use_amazon_pay( 'USD' ) );
		$this->assertFalse( $only_product->can_use_amazon_pay( 'EUR' ) );
		$this->assertFalse( $no_location->can_use_amazon_pay( 'USD' ) );
		$this->assertFalse( $unavailable->can_use_amazon_pay( 'USD' ) );
	}

	/**
	 * @testdox Should tell Amazon Pay usable whichever locations list it, like the client's can_use_amazon_pay().
	 */
	public function test_is_amazon_pay_usable_ignores_location_settings(): void {
		$eligible    = array( 'ece_confirmation_tokens_disabled' => false );
		$no_location = $this->create_service( array(), true, $eligible );
		$unavailable = $this->create_service( array( 'upe_available_payment_methods' => array( 'card' ) ), true, $eligible );

		$this->assertTrue( $no_location->is_amazon_pay_usable( 'checkout', 'USD' ) );
		$this->assertFalse( $no_location->is_amazon_pay_usable( 'checkout', 'EUR' ) );
		$this->assertFalse( $unavailable->is_amazon_pay_usable( 'checkout', 'USD' ) );
	}

	/**
	 * @testdox Should tell express checkout available only when the client's express checkout registration guards hold.
	 *
	 * @dataProvider provider_express_checkout_availability
	 *
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Account data.
	 * @param bool                $expected     Expected availability.
	 */
	public function test_is_express_checkout_available( array $settings, array $account_data, bool $expected ): void {
		$sut = $this->create_service( $settings, true, array_merge( array( 'ece_confirmation_tokens_disabled' => false ), $account_data ) );

		$this->assertSame( $expected, $sut->is_express_checkout_available() );
	}

	/**
	 * Data provider for express checkout availability.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:bool}>
	 */
	public function provider_express_checkout_availability(): array {
		$amazon_only = array(
			'enabled'                           => 'yes',
			'payment_request'                   => 'no',
			'express_checkout_checkout_methods' => array( 'amazon_pay' ),
		);

		return array(
			'payment request enabled'          => array( array( 'enabled' => 'yes' ), array(), true ),
			'amazon pay usable'                => array( $amazon_only, array(), true ),
			'gateway disabled'                 => array( array( 'enabled' => 'no' ), array(), false ),
			'payments disabled on the account' => array( array( 'enabled' => 'yes' ), array( 'payments_enabled' => false ), false ),
			'no usable express method'         => array(
				array(
					'enabled'                        => 'yes',
					'payment_request'                => 'no',
					'upe_enabled_payment_method_ids' => array( 'card' ),
				),
				array(),
				false,
			),
			'amazon pay not eligible'          => array( $amazon_only, array( 'capabilities' => array() ), false ),
			'amazon pay switched off'          => array( array_merge( $amazon_only, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) ), array(), false ),
			// Client 11.1.0 class-wc-payments-express-checkout-button-handler.php:79 ignores checkout locations.
			'amazon pay usable with no location listing it' => array( array_diff_key( $amazon_only, array( 'express_checkout_checkout_methods' => true ) ), array(), true ),
		);
	}

	/**
	 * @testdox Should fail closed when Amazon Pay is configured but not available on the connected account.
	 */
	public function test_allowed_payment_method_types_exclude_unavailable_amazon_pay(): void {
		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$this->assertSame( array( 'card' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ) );
		$this->assertSame( array( 'payment_request' ), $sut->get_enabled_methods_for_context( 'checkout' ) );
		$this->assertSame( array( 'payment_request' ), $sut->get_express_checkout_params( 'checkout' )['enabled_methods'] );
	}

	/**
	 * @testdox Should fail closed when account data disables ECE confirmation tokens.
	 */
	public function test_allowed_payment_method_types_exclude_amazon_pay_when_confirmation_tokens_are_disabled(): void {
		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => true,
			)
		);

		$this->assertSame( array( 'card' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ) );
	}

	/**
	 * @testdox Should block Amazon Pay when taxes are based on billing address.
	 */
	public function test_allowed_payment_method_types_exclude_amazon_pay_for_billing_address_taxes(): void {
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$this->assertSame( array( 'card' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ) );
	}

	/**
	 * @testdox Should allow Amazon Pay with billing-address tax settings when taxes are disabled.
	 */
	public function test_allowed_payment_method_types_include_amazon_pay_for_billing_address_when_taxes_disabled(): void {
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_calc_taxes', 'no' );

		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$this->assertSame( array( 'card', 'amazon_pay' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ) );
	}

	/**
	 * @testdox Should validate pay-for-order express method types against the order currency.
	 */
	public function test_pay_for_order_payment_method_types_use_order_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );

		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_currency( 'EUR' );
		$order->set_billing_email( 'shopper@example.test' );
		$order->save();
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$params = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		)->get_express_checkout_params( 'pay_for_order' );

		$this->assertSame( 'eur', $params['checkout']['currency_code'] );
		$this->assertSame( array( 'payment_request' ), $params['enabled_methods'] );
		$this->assertSame( array( 'card' ), $params['payment_method_types'] );
	}

	/**
	 * @testdox Should keep Amazon Pay available for pay-for-order when billing-address taxes are enabled.
	 */
	public function test_pay_for_order_allows_amazon_pay_with_billing_address_taxes(): void {
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_currency( 'USD' );
		$order->set_billing_email( 'shopper@example.test' );
		$order->save();
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$params = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_available_payment_methods'     => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		)->get_express_checkout_params( 'pay_for_order' );

		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $params['enabled_methods'] );
		$this->assertSame( array( 'card', 'amazon_pay' ), $params['payment_method_types'] );
	}

	/**
	 * @testdox Should build pay-for-order express checkout params from the current order for its logged-in owner.
	 */
	public function test_builds_pay_for_order_express_checkout_params(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'shopper@example.test' );
		$order->save();
		wp_set_current_user( $user_id );
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$params = $this->create_service()->get_express_checkout_params( 'pay_for_order' );

		$this->assertSame( 'pay_for_order', $params['button_context'] );
		$this->assertSame( $order->get_id(), $params['order_id'] );
		$this->assertSame( 'true', $params['pay_for_order'] );
		$this->assertSame( $order->get_order_key(), $params['key'] );
		$this->assertSame( 'shopper@example.test', $params['billing_email'] );
		$this->assertSame( array( 'payment_request' ), $params['enabled_methods'] );
		$this->assertSame( array( 'card' ), $params['payment_method_types'] );
	}

	/**
	 * @testdox Pay-for-order params give a guest order's email only to a shop manager, and to anyone else the email they verified or their session email.
	 *
	 * @testWith ["logged-out visitor", ""]
	 *           ["shop manager", "order@example.test"]
	 *           ["visitor with a session email", "session@example.test"]
	 *           ["visitor who posted the email check", "typed@example.test"]
	 *
	 * @param string $viewer   Who opens the pay link.
	 * @param string $expected Expected billing email.
	 */
	public function test_pay_for_order_params_hide_a_guest_order_email_from_other_visitors( string $viewer, string $expected ): void {
		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_billing_email( 'order@example.test' );
		$order->save();
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$session          = WC()->session;
		$session_customer = $session->get( 'customer' );
		$session->set( 'customer', null );
		if ( 'shop manager' === $viewer ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		} elseif ( 'visitor with a session email' === $viewer ) {
			$session->set( 'customer', array( 'email' => 'session@example.test' ) );
		} elseif ( 'visitor who posted the email check' === $viewer ) {
			$_POST['email'] = 'typed@example.test';
		}

		try {
			$params = $this->create_service()->get_express_checkout_params( 'pay_for_order' );
		} finally {
			$session->set( 'customer', $session_customer );
			unset( $_POST['email'] );
		}

		$this->assertSame( $order->get_id(), $params['order_id'] );
		$this->assertSame( $expected, $params['billing_email'] );
	}

	/**
	 * @testdox Should allow an authorized owner to pay for an order without a billing email.
	 */
	public function test_payment_request_allows_pay_for_order_without_billing_email(): void {
		$user_id = self::factory()->user->create();
		$order   = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_customer_id( $user_id );
		$order->set_billing_email( '' );
		$order->save();
		wp_set_current_user( $user_id );
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$empty_billing_email = static function ( string $email, \WC_Order $filtered_order ) use ( $order ): string {
			return $order->get_id() === $filtered_order->get_id() ? '' : $email;
		};
		add_filter( 'woocommerce_order_get_billing_email', $empty_billing_email, 10, 2 );

		try {
			$service = $this->create_service();

			$this->assertTrue( $service->should_show_payment_request_button( 'pay_for_order' ) );
			$this->assertSame( '', $service->get_express_checkout_params( 'pay_for_order' )['billing_email'] );
		} finally {
			remove_filter( 'woocommerce_order_get_billing_email', $empty_billing_email, 10 );
		}
	}

	/**
	 * @testdox Should allow a guest to pay for an order without a billing email using its exact key.
	 */
	public function test_payment_request_allows_guest_pay_for_order_without_billing_email(): void {
		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->save();
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$this->assertTrue( $this->create_service()->should_show_payment_request_button( 'pay_for_order' ) );
	}

	/**
	 * @testdox Should retain pay-for-order authorization and availability boundaries without a billing email.
	 *
	 * @dataProvider pay_for_order_ineligible_cases
	 *
	 * @param string $scenario Ineligible boundary to exercise.
	 */
	public function test_payment_request_keeps_pay_for_order_boundaries_without_billing_email( string $scenario ): void {
		$user_id = self::factory()->user->create();
		$order   = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_customer_id( $user_id );
		$order->save();
		wp_set_current_user( $user_id );
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$settings             = array();
		$can_process_payments = true;
		switch ( $scenario ) {
			case 'missing key':
				unset( $_GET['key'] );
				break;
			case 'wrong key':
				$_GET['key'] = 'wc_order_wrong';
				break;
			case 'wrong user':
				wp_set_current_user( self::factory()->user->create() );
				break;
			case 'missing order':
				$this->set_order_pay_query_var( 999999 );
				break;
			case 'nonpayable order':
				$order->set_status( 'processing' );
				$order->save();
				break;
			case 'unavailable provider':
				$can_process_payments = false;
				break;
			case 'no allowed method':
				$settings = array( 'express_checkout_checkout_methods' => array() );
				break;
		}

		$this->assertFalse( $this->create_service( $settings, $can_process_payments )->should_show_payment_request_button( 'pay_for_order' ) );
	}

	/**
	 * Provide pay-for-order boundaries that must remain closed.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function pay_for_order_ineligible_cases(): array {
		return array(
			'missing key'            => array( 'missing key' ),
			'wrong key'              => array( 'wrong key' ),
			'wrong user'             => array( 'wrong user' ),
			'missing order'          => array( 'missing order' ),
			'nonpayable order'       => array( 'nonpayable order' ),
			'unavailable provider'   => array( 'unavailable provider' ),
			'no allowed method type' => array( 'no allowed method' ),
		);
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param array<string,mixed> $settings             Gateway settings.
	 * @param bool                $can_process_payments Whether the provider can process native payments.
	 * @param array<string,mixed> $account_data         Account data.
	 * @param bool                $merge_defaults       Whether to merge default gateway settings.
	 * @param bool                $shopper_tracking     Whether shopper tracking is enabled.
	 * @param bool                $test_mode            Whether the account is in test mode.
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_service( array $settings = array(), bool $can_process_payments = true, array $account_data = array(), bool $merge_defaults = true, bool $shopper_tracking = true, bool $test_mode = true ): WooPaymentsExpressCheckoutService {
		$default_settings = array(
			'enabled'                              => 'yes',
			'upe_enabled_payment_method_ids'       => array( 'card', 'amazon_pay' ),
			'manual_capture'                       => 'no',
			'payment_request'                      => 'yes',
			'payment_request_button_type'          => 'default',
			'payment_request_button_theme'         => 'dark',
			'payment_request_button_size'          => 'medium',
			'payment_request_button_border_radius' => '',
			'express_checkout_product_methods'     => array( 'payment_request' ),
			'express_checkout_cart_methods'        => array( 'payment_request' ),
			'express_checkout_checkout_methods'    => array( 'payment_request' ),
		);
		$settings         = $merge_defaults ? array_merge( $default_settings, $settings ) : $settings;
		$account_service  = $this->create_account_service( $settings, $account_data, $test_mode );

		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( $can_process_payments );

		$tracking_controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_tracking_enabled' )->willReturn( $shopper_tracking );

		$sut = new WooPaymentsExpressCheckoutService();
		$sut->init( $account_service, $provider, $tracking_controller );

		return $sut;
	}

	/**
	 * Create a WooPayments account service mock backed by gateway settings and account data.
	 *
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Account data overrides.
	 * @param bool                $test_mode    Whether the account is in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( array $settings, array $account_data = array(), bool $test_mode = true ): WooPaymentsAccountService {
		$account_data = array_merge(
			array(
				'country'          => 'US',
				'payments_enabled' => true,
				'capabilities'     => array(
					'amazon_pay_payments' => 'active',
				),
				'fees'             => array(
					'amazon_pay' => array(
						'base' => array(
							'currency' => 'usd',
						),
					),
				),
			),
			$account_data
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'is_test_mode_enabled', 'get_gateway_setting', 'is_payment_request_enabled' ) )
			->getMock();

		$account_service->method( 'get_account_id' )->willReturn( 'acct_123' );
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( 'yes' === ( $settings['payment_request'] ?? 'no' ) );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback
		);

		return $account_service;
	}

	/**
	 * Create and save a product whose type is the given one, without its extension's product class.
	 *
	 * @param string $type  Product type.
	 * @param string $price Product price.
	 * @return \WC_Product
	 */
	private function create_typed_product( string $type, string $price ): \WC_Product {
		$product = new class( $type ) extends \WC_Product_Simple {
			/**
			 * Product type this product reports.
			 *
			 * @var string
			 */
			private string $test_type;

			/**
			 * Constructor.
			 *
			 * @param string $test_type Product type.
			 */
			public function __construct( string $test_type ) {
				$this->test_type = $test_type;
				parent::__construct();
			}

			/**
			 * Get the product type.
			 *
			 * @return string
			 */
			public function get_type() {
				return $this->test_type;
			}
		};
		$product->set_props(
			array(
				'name'          => 'Typed product',
				'regular_price' => $price,
				'price'         => $price,
				'virtual'       => true,
			)
		);
		$product->save();

		return $product;
	}

	/**
	 * Create a virtual variable product with S ($10), M ($20) and L ($30) variations of a local Size attribute.
	 *
	 * @param string $default_size Default Size, or empty for none.
	 * @return \WC_Product_Variable
	 */
	private function create_sized_variable_product( string $default_size ): \WC_Product_Variable {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S', 'M', 'L' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new \WC_Product_Variable();
		$product->set_name( 'Sized Widget' );
		$product->set_attributes( array( $attribute ) );
		if ( '' !== $default_size ) {
			$product->set_default_attributes( array( 'size' => $default_size ) );
		}
		$product->save();

		foreach ( array(
			'S' => '10',
			'M' => '20',
			'L' => '30',
		) as $size => $price ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( $price );
			$variation->set_virtual( true );
			$variation->save();
		}
		\WC_Product_Variable::sync( $product->get_id() );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Get the product-page express checkout data while the product's add-to-cart form renders.
	 *
	 * @param \WC_Product $product Product on the page.
	 * @return array<string,mixed>
	 */
	private function get_product_page_data( \WC_Product $product ): array {
		$GLOBALS['product'] = $product;
		$params             = array();
		$capture            = function () use ( &$params ): void {
			$params = $this->create_service()->get_express_checkout_params( 'product' );
		};
		add_action( 'woocommerce_after_add_to_cart_form', $capture );
		do_action( 'woocommerce_after_add_to_cart_form' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core hook, fired to render the product form context.

		return $params['product'];
	}

	/**
	 * Turn taxes on, based on the billing address, with prices entered inclusive or exclusive of tax.
	 *
	 * @param string $prices_include_tax `yes` or `no`.
	 */
	private function set_billing_address_taxes( string $prices_include_tax ): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax );
	}

	/**
	 * Put a virtual product on the product page, or in the cart for the cart and checkout contexts.
	 *
	 * @param string $context Express checkout context.
	 */
	private function set_up_virtual_product_context( string $context ): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'regular_price' => '12.34',
				'price'         => '12.34',
				'virtual'       => true,
			)
		);

		if ( 'product' === $context ) {
			$this->set_current_product( $product );
			return;
		}

		WC()->cart->add_to_cart( $product->get_id() );
	}

	/**
	 * Set the current order-pay query var.
	 *
	 * @param int $order_id Order ID.
	 */
	private function set_order_pay_query_var( int $order_id ): void {
		global $wp;

		if ( ! is_object( $wp ) ) {
			$wp = new \WP(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		if ( $order_id > 0 ) {
			$wp->query_vars['order-pay'] = $order_id;
			return;
		}

		unset( $wp->query_vars['order-pay'] );
	}

	/**
	 * Set the current queried product.
	 *
	 * @param \WC_Product $product Product object.
	 */
	private function set_current_product( \WC_Product $product ): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );

		global $post;

		$post               = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $product;
		$this->go_to( get_permalink( $product->get_id() ) );
		setup_postdata( $post );
		add_filter( 'woocommerce_is_product', '__return_true' );
	}

	/**
	 * Set the current request to a page containing the given content.
	 *
	 * @param string $content Page content.
	 */
	private function set_current_page_with_content( string $content ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		global $post;
		$this->go_to( get_permalink( $page_id ) );
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set the page global for the shortcode request under test.
		setup_postdata( $post );
	}

	/**
	 * Reset the main query to its pre-request state.
	 */
	private function reset_main_query(): void {
		unset( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
		$GLOBALS['wp_the_query'] = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset main-query globals to simulate pre-request resolution.
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset main-query globals to simulate pre-request resolution.
	}
}
