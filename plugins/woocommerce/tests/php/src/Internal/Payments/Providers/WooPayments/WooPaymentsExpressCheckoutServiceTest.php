<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
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
		delete_option( 'woocommerce_tax_based_on' );
		delete_option( 'woocommerce_calc_taxes' );
		delete_option( 'woocommerce_price_num_decimals' );
		delete_option( 'woocommerce_enable_guest_checkout' );
		delete_option( 'woocommerce_enable_signup_and_login_from_checkout' );
		delete_option( 'woocommerce_enable_signup_from_checkout_for_subscriptions' );
		delete_option( 'woocommerce_registration_generate_username' );
		delete_option( 'woocommerce_registration_generate_password' );
		wp_set_current_user( 0 );
		unset( $_GET['pay_for_order'], $_GET['key'] );
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
		unset( $GLOBALS['product'] );
		wp_reset_postdata();
		parent::tearDown();
	}

	/**
	 * @testdox Should require native provider readiness before exposing payment-request express checkout.
	 */
	public function test_payment_request_requires_provider_readiness(): void {
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
	 * @testdox Should flag has_subscription on the product page for a subscription product.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_product_page_has_subscription_for_subscription_product(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test double for an absent WCS class.
		eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { return true; } } }' );

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
		$this->set_current_product( $product );

		$params = $this->create_service()->get_express_checkout_params( 'product' );

		$this->assertTrue( $params['has_subscription'] );
	}

	/**
	 * @testdox Should not use an unrelated cart subscription on an ordinary product page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_product_page_subscription_context_ignores_global_cart(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test doubles for absent WCS symbols.
		eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { return false; } } class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return true; } } }' );

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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool        $initial     Whether the initial cart detector matches.
	 * @param array|false $renewal     Renewal detector result.
	 * @param array|false $resubscribe Resubscribe detector result.
	 * @param array|false $switch_result Switch detector result.
	 * @param bool        $expected    Expected localized subscription state.
	 */
	public function test_checkout_subscription_context_uses_supported_cart_detectors( bool $initial, $renewal, $resubscribe, $switch_result, bool $expected ): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test doubles for absent WCS symbols use the public detector return shapes.
		eval( 'namespace { class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return $GLOBALS["wcpay_ece_subscription_detectors"]["initial"]; } } function wcs_cart_contains_renewal() { return $GLOBALS["wcpay_ece_subscription_detectors"]["renewal"]; } function wcs_cart_contains_resubscribe() { return $GLOBALS["wcpay_ece_subscription_detectors"]["resubscribe"]; } function wcs_cart_contains_switches() { return $GLOBALS["wcpay_ece_subscription_detectors"]["switch"]; } }' );
		$GLOBALS['wcpay_ece_subscription_detectors'] = array(
			'initial'     => $initial,
			'renewal'     => $renewal,
			'resubscribe' => $resubscribe,
			'switch'      => $switch_result,
		);

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
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_login_confirmation_required_for_subscription_product_page(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test double for an absent WCS class.
		eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { return true; } } }' );

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
		$this->set_current_product( $product );

		$params = $this->create_service()->get_express_checkout_params( 'product' );

		$this->assertIsArray( $params['login_confirmation'] );
	}

	/**
	 * @testdox Should not require login confirmation on a subscription product page when subscription signup is allowed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_login_confirmation_false_when_subscription_signup_possible(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test double for an absent WCS class.
		eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { return true; } } }' );

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
	 * @testdox Should not require Amazon Pay in UPE card method settings when express checkout enables it.
	 */
	public function test_amazon_pay_eligibility_uses_express_checkout_settings_not_upe_card_settings(): void {
		$sut = $this->create_service(
			array(
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
				'upe_enabled_payment_method_ids'    => array( 'card' ),
			),
			true,
			array(
				'ece_confirmation_tokens_disabled' => false,
			)
		);

		$this->assertSame( array( 'card', 'amazon_pay' ), $sut->get_allowed_payment_method_types_for_context( 'checkout' ) );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $sut->get_enabled_methods_for_context( 'checkout' ) );
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
	 * @testdox Should build pay-for-order express checkout params from the current order.
	 */
	public function test_builds_pay_for_order_express_checkout_params(): void {
		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->set_billing_email( 'shopper@example.test' );
		$order->save();
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
	 * @testdox Should fail closed for pay-for-order when billing email is missing.
	 */
	public function test_payment_request_fails_closed_for_pay_for_order_without_billing_email(): void {
		$order = wc_create_order();
		$order->set_total( '24.00' );
		$order->save();
		$_GET['pay_for_order'] = 'true';
		$_GET['key']           = $order->get_order_key();
		$this->set_order_pay_query_var( $order->get_id() );

		$this->assertFalse( $this->create_service()->should_show_payment_request_button( 'pay_for_order' ) );
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param array<string,mixed> $settings             Gateway settings.
	 * @param bool                $can_process_payments Whether the provider can process native payments.
	 * @param array<string,mixed> $account_data         Account data.
	 * @param bool                $merge_defaults       Whether to merge default gateway settings.
	 * @param bool                $shopper_tracking     Whether shopper tracking is enabled.
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_service( array $settings = array(), bool $can_process_payments = true, array $account_data = array(), bool $merge_defaults = true, bool $shopper_tracking = true ): WooPaymentsExpressCheckoutService {
		$default_settings = array(
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
		$account_data     = array_merge(
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
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( 'yes' === ( $settings['payment_request'] ?? 'no' ) );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback
		);

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
