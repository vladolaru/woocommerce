<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPaySessionService;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\LateLoadedSubscriptions;

/**
 * Tests for the WooPaymentsWooPaySessionService class.
 */
class WooPaymentsWooPaySessionServiceTest extends WC_Unit_Test_Case {

	use WooPayServicesFixture;

	/**
	 * @testdox Should build WooPay REST URLs from the default host.
	 */
	public function test_builds_woopay_rest_urls_from_default_host(): void {
		$this->assertSame( 'https://pay.woo.com', WooPaymentsWooPaySessionService::get_woopay_url() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/init', WooPaymentsWooPaySessionService::get_woopay_rest_url( 'init' ) );
	}

	/**
	 * @testdox WooPay is enabled only with platform checkout on for an eligible account, onboarded or not: $_dataName.
	 * @dataProvider woopay_enabled_provider
	 *
	 * Client 11.1.0 decides WooPay on the setting and `WC_Payments_Features::is_woopay_eligible()`, with no account validity
	 * check (includes/woopay/class-woopay-session.php:883-890, includes/class-wc-payments.php:1711-1715,
	 * includes/class-wc-payments-features.php:193-210); its own tests call an account holding only platform_checkout_eligible
	 * eligible and enabled (tests/unit/test-class-wc-payments-features.php:133-136, :213-218).
	 *
	 * @param array<string,mixed> $settings     Gateway settings overrides.
	 * @param array<string,mixed> $account_data Account data overrides.
	 * @param bool                $expected     Whether WooPay is enabled.
	 */
	public function test_woopay_enabled_follows_the_setting_and_the_account( array $settings, array $account_data, bool $expected ): void {
		$sut = $this->create_service( $settings, $account_data );

		$this->assertSame( $expected, $this->woopay_eligibility->is_woopay_enabled() );
		$this->assertSame( $expected, $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * WooPay settings and accounts, and whether WooPay is enabled for them.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:bool}>
	 */
	public function woopay_enabled_provider(): array {
		$on = array( 'platform_checkout' => 'yes' );

		return array(
			'platform checkout off'              => array(
				array(
					'platform_checkout'                 => 'no',
					'express_checkout_product_methods'  => array( 'payment_request' ),
					'express_checkout_cart_methods'     => array(),
					'express_checkout_checkout_methods' => array( 'woopay' ),
				),
				array(),
				false,
			),
			'platform checkout on without WooPay express methods' => array(
				array(
					'platform_checkout'                 => 'yes',
					'express_checkout_product_methods'  => array( 'payment_request' ),
					'express_checkout_cart_methods'     => array(),
					'express_checkout_checkout_methods' => array(),
				),
				array(),
				true,
			),
			'an account not eligible for WooPay' => array( $on, array( 'platform_checkout_eligible' => false ), false ),
			'an account under review'            => array( $on, array( 'status' => 'under_review' ), false ),
			'a rejected account'                 => array( $on, array( 'status' => 'rejected.fraud' ), false ),
			'details not submitted'              => array( $on, array( 'details_submitted' => false ), true ),
			'card payments missing'              => array( $on, array( 'capabilities' => array() ), true ),
			'card payments unrequested'          => array( $on, array( 'capabilities' => array( 'card_payments' => 'unrequested' ) ), true ),
			'no account ID'                      => array( $on, array( 'account_id' => '' ), true ),
		);
	}

	/**
	 * @testdox Should not show the checkout WooPay button unless the checkout express method is enabled.
	 */
	public function test_woopay_checkout_button_requires_checkout_express_method(): void {
		$sut = $this->create_service(
			array(
				'platform_checkout'                 => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array(),
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertFalse( $config['shouldShowWooPayButton'] );
	}

	/**
	 * @testdox The checkout config's WooPay email lookup for a $shopper is $expected, as the guest rule decides.
	 *
	 * Client 11.1.0 ANDs should_enable_woopay_on_guest_checkout() into the checkout config's isWooPayEnabled
	 * (class-wc-payments-checkout.php:198, class-woopay-utilities.php:46-62); its only readers are the email input on Blocks and
	 * classic checkout (client/checkout/blocks/index.js:123-130, client/checkout/classic/event-handlers.js:165-171), which also
	 * require isWooPayEmailInputEnabled.
	 *
	 * @dataProvider guest_rule_provider
	 *
	 * @param string $shopper                    Shopper description.
	 * @param bool   $logged_in                  Whether the shopper is logged in.
	 * @param string $guest_checkout             woocommerce_enable_guest_checkout value.
	 * @param bool   $cart_contains_subscription Whether the cart holds a subscription.
	 * @param bool   $expected                   Whether the email lookup is offered.
	 */
	public function test_checkout_config_applies_the_guest_rule_to_the_woopay_email_input( string $shopper, bool $logged_in, string $guest_checkout, bool $cart_contains_subscription, bool $expected ): void {
		unset( $shopper );
		update_option( 'woocommerce_enable_guest_checkout', $guest_checkout );
		wp_set_current_user( $logged_in ? self::factory()->user->create() : 0 );
		$sut = $this->create_service();
		$this->woopay_eligibility->cart_contains_subscription = $cart_contains_subscription;

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertTrue( $config['isWooPayEnabled'], 'WooPay stays enabled: the save-user section, which the client does not exclude, reads it.' );
		$this->assertSame( $expected, $config['isWooPayEmailInputEnabled'] );
	}

	/**
	 * Shoppers the client's guest rule includes or excludes.
	 *
	 * @return array<string,array{0:string,1:bool,2:string,3:bool,4:bool}>
	 */
	public function guest_rule_provider(): array {
		return array(
			'guest with guest checkout on'              => array( 'guest with guest checkout on', false, 'yes', false, true ),
			'guest with guest checkout off'             => array( 'guest with guest checkout off', false, 'no', false, false ),
			'guest with a subscription in the cart'     => array( 'guest with a subscription in the cart', false, 'yes', true, false ),
			'logged-in shopper with guest checkout off' => array( 'logged-in shopper with guest checkout off', true, 'no', false, true ),
			'logged-in shopper with a subscription in the cart' => array( 'logged-in shopper with a subscription in the cart', true, 'no', true, true ),
		);
	}

	/**
	 * @testdox Should evaluate the button filter once while keeping frontend capability fields internally consistent.
	 */
	public function test_woopay_frontend_config_applies_button_filter_once(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$enabled_filter_calls = 0;
		add_filter(
			'wcpay_woopay_enabled',
			static function () use ( &$enabled_filter_calls ): bool {
				++$enabled_filter_calls;

				return false;
			}
		);

		$config = $this->create_service()->get_woopay_frontend_config( 'cart' );

		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['shouldShowWooPayButton'] );
		$this->assertSame( 1, $enabled_filter_calls );
	}

	/**
	 * @testdox The order-pay WooPay button follows the checkout location setting and reports the pay_for_order context.
	 *
	 * Client 11.1.0: is_checkout() holds on order-pay (class-wc-payments-woopay-button-handler.php:273), and the button
	 * settings carry get_button_context(), which is pay_for_order there (class-wc-payments-express-checkout-button-helper.php:459-461).
	 */
	public function test_order_pay_woopay_button_follows_the_checkout_location(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$config = $this->create_service()->get_woopay_frontend_config( 'pay_for_order' );

		$this->assertTrue( $config['shouldShowWooPayButton'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertSame( 'pay_for_order', $config['woopayButton']['context'] );
		$this->assertFalse(
			$this->create_eligibility( array( 'express_checkout_checkout_methods' => array() ) )->should_show_woopay_button( 'pay_for_order' ),
			'The checkout location setting turns the order-pay button off.'
		);
		$this->assertTrue(
			$this->create_eligibility( array( 'express_checkout_cart_methods' => array() ) )->should_show_woopay_button( 'pay_for_order' ),
			'Only the checkout location applies on order-pay.'
		);
	}

	/**
	 * @testdox Should generate the reference WooPay request signature.
	 */
	public function test_generates_reference_request_signature(): void {
		\Jetpack_Options::update_option( 'id', 12345 );
		\Jetpack_Options::update_option( 'blog_token', 'blog-token' );

		$sut = $this->create_service();
		// The signature covers the current 30-second window; the call can cross into the next one.
		$window_before = floor( time() / 30 );
		$signature     = $sut->get_woopay_request_signature();
		$window_after  = floor( time() / 30 );

		$this->assertContains(
			$signature,
			array(
				hash_hmac( 'sha512', '12345' . $window_before, 'blog-token' ),
				hash_hmac( 'sha512', '12345' . $window_after, 'blog-token' ),
			)
		);
	}

	/**
	 * @testdox Should preserve the WooPay string user session in init payloads.
	 */
	public function test_preserves_string_user_session_in_init_payloads(): void {
		$sut    = $this->create_service();
		$result = $sut->start_init_session_request( 'shopper@example.com', 'qwerty123' );

		$this->assertSame( 'qwerty123', $result['user_session'] );
	}

	/**
	 * @testdox Should fall back to the theme custom logo in WooPay init session store data.
	 */
	public function test_init_session_request_uses_theme_custom_logo_when_woopay_logo_is_not_configured(): void {
		$custom_logo_id = 123;
		$filter         = static function ( $downsize, $id, $size ) use ( $custom_logo_id ) {
			if ( $custom_logo_id === $id && 'full' === $size ) {
				return array( 'https://example.test/theme-logo.png', 128, 64, false );
			}

			return $downsize;
		};
		add_filter( 'image_downsize', $filter, 10, 3 );
		set_theme_mod( 'custom_logo', $custom_logo_id );

		try {
			$sut    = $this->create_service();
			$result = $sut->start_init_session_request( 'shopper@example.com', 'qwerty123' );

			$this->assertSame( 'https://example.test/theme-logo.png', $result['store_data']['store_logo'] );
		} finally {
			remove_filter( 'image_downsize', $filter, 10 );
		}
	}

	/**
	 * @testdox Should send the WooPay store logo file URL when a custom logo is configured.
	 */
	public function test_init_session_request_uses_configured_woopay_store_logo_file_url(): void {
		$sut = $this->create_service(
			array(
				'platform_checkout_store_logo' => 'file_logo',
			)
		);

		$result = $sut->start_init_session_request( 'shopper@example.com', 'qwerty123' );

		$this->assertSame( get_rest_url( null, 'wc/v3/payments/file/file_logo' ), $result['store_data']['store_logo'] );
	}

	/**
	 * @testdox WooPay session data sends the custom message with the terms link and the privacy label filled in.
	 *
	 * Client 11.1.0 sends get_formatted_custom_terms() as store_data.custom_message (includes/woopay/class-woopay-session.php:551,
	 * :942-960); WooPay shows it under its pay button when a block checkout has no Terms block.
	 */
	public function test_init_session_request_sends_the_formatted_custom_message(): void {
		$terms_page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Terms',
			)
		);
		update_option( 'woocommerce_terms_page_id', $terms_page );
		update_option( 'wp_page_for_privacy_policy', 0 );
		$sut = $this->create_service(
			array(
				'platform_checkout_custom_message' => 'By placing this order, you agree to our [terms] and understand our [privacy_policy].',
			)
		);

		$result = $sut->start_init_session_request( 'shopper@example.com' );

		$this->assertSame(
			'By placing this order, you agree to our <a href="' . esc_url( (string) get_permalink( $terms_page ) ) . '">Terms of Service</a> and understand our Privacy Policy.',
			$result['store_data']['custom_message']
		);
	}

	/**
	 * @testdox WooPay session data names the versioned Store API base, wc/store/v1.
	 *
	 * Client 11.1.0 class-woopay-session.php:908-919 sends the Store API cart route's namespace.
	 */
	public function test_init_session_request_sends_the_versioned_store_api_url(): void {
		$result = $this->create_service()->start_init_session_request( 'shopper@example.com' );

		$this->assertSame( get_rest_url( null, 'wc/store/v1' ), $result['store_data']['store_api_url'] );
	}

	/**
	 * @testdox WooPay session data reports WooCommerce Subscriptions inactive while its class is not loaded.
	 */
	public function test_init_session_request_reports_woocommerce_subscriptions_inactive(): void {
		$result = $this->create_service()->start_init_session_request( 'shopper@example.com' );

		$this->assertFalse( $result['store_data']['is_subscriptions_plugin_active'] );
	}

	/**
	 * @testdox WooPay session data reports WooCommerce Subscriptions active once its class is loaded.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_session_request_reports_woocommerce_subscriptions_active(): void {
		// The global WC_Subscriptions class cannot be unloaded, so this test runs in its own process.
		require_once dirname( __DIR__ ) . '/Fixtures/LateLoadedSubscriptions.php';
		class_alias( LateLoadedSubscriptions::class, 'WC_Subscriptions' );

		$result = $this->create_service()->start_init_session_request( 'shopper@example.com' );

		$this->assertTrue( $result['store_data']['is_subscriptions_plugin_active'] );
	}

	/**
	 * @testdox Should preload current Store API cart and checkout data for WooPay init sessions.
	 */
	public function test_preloads_current_store_api_cart_and_checkout_data(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'    => 'WooPay Preload Product',
				'virtual' => true,
			)
		);
		wc_empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$sut     = $this->create_service();
		$result  = $sut->start_init_session_request( 'shopper@example.com', 'qwerty123' );
		$preload = $result['preloaded_requests'];

		$this->assertSame( 1, $preload['cart']['items_count'] );
		$this->assertSame( $product->get_id(), $preload['cart']['items'][0]['id'] );
		$this->assertArrayHasKey( 'billing_address', $preload['checkout'] );
		$this->assertArrayHasKey( 'order_id', $preload['checkout'] );
	}

	/**
	 * @testdox WooPay session for an order-pay link carries the order's pay URL and order preload when the key lets the visitor pay it.
	 */
	public function test_init_session_request_uses_the_order_pay_url_with_the_order_key(): void {
		$order = \WC_Helper_Order::create_order( 0 );

		$result = $this->create_service()->start_init_session_request( null, null, null, $order->get_id(), $order->get_order_key(), $order->get_billing_email() );

		$this->assertSame( $order->get_checkout_payment_url(), $result['store_data']['blog_checkout_url'] );
		$this->assertSame( $order->get_checkout_payment_url(), $result['store_data']['return_url'] );
		$this->assertSame( array( 'order_id' => $order->get_id() ), $result['preloaded_requests']['checkout'] );
	}

	/**
	 * @testdox WooPay session for an order's pay link carries that order's total, not the shopper's cart, for the owner and for a guest who verified the order email.
	 *
	 * The preload is the Store API order response (src/StoreApi/Schemas/V1/OrderSchema.php: id, totals.total_price in minor
	 * units, totals.currency_code), as client 11.1.0 preloads it (class-woopay-session.php:389-394).
	 *
	 * @testWith ["owner"]
	 *           ["verified guest"]
	 *
	 * @param string $visitor Who opens the pay link.
	 */
	public function test_init_session_request_for_an_order_pay_link_carries_the_order_not_the_cart( string $visitor ): void {
		$owner_id = 'owner' === $visitor ? self::factory()->user->create( array( 'role' => 'customer' ) ) : 0;
		$order    = \WC_Helper_Order::create_order( $owner_id );
		$order->set_currency( get_woocommerce_currency() );
		$order->save();
		wp_set_current_user( $owner_id );
		$cart_product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '999' ) );
		wc_empty_cart();
		WC()->cart->add_to_cart( $cart_product->get_id(), 1 );

		$result  = $this->create_service()->start_init_session_request( null, null, null, $order->get_id(), $order->get_order_key(), $order->get_billing_email() );
		$preload = $result['preloaded_requests'];

		$this->assertSame( $order->get_checkout_payment_url(), $result['store_data']['blog_checkout_url'] );
		$this->assertSame( $order->get_id(), $preload['cart']['id'] );
		$totals = (array) $preload['cart']['totals'];
		$this->assertSame( (string) round( (float) $order->get_total() * 100 ), $totals['total_price'] );
		$this->assertSame( $order->get_currency(), $totals['currency_code'] );
		$this->assertSame( array( 'order_id' => $order->get_id() ), $preload['checkout'] );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), "The shopper's cart is left alone." );
	}

	/**
	 * @testdox WooPay session leaves out a posted order's pay URL when the key is wrong or missing, or the visitor cannot pay the order.
	 *
	 * @testWith ["wrong key"]
	 *           ["missing key"]
	 *           ["another customer's order"]
	 *
	 * @param string $scenario Case to exercise.
	 */
	public function test_init_session_request_ignores_an_order_the_key_does_not_unlock( string $scenario ): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( "another customer's order" === $scenario ? $owner_id : 0 );
		$key      = array(
			'wrong key'                => 'wc_order_not_the_key',
			'missing key'              => null,
			"another customer's order" => $order->get_order_key(),
		)[ $scenario ];

		$result = $this->create_service()->start_init_session_request( null, null, null, $order->get_id(), $key, $order->get_billing_email() );

		$this->assertStringNotContainsString( $order->get_order_key(), (string) wp_json_encode( $result['store_data'] ) );
		$this->assertSame( wc_get_checkout_url(), $result['store_data']['blog_checkout_url'] );
		$this->assertSame( wc_get_cart_url(), $result['store_data']['return_url'] );
		$this->assertArrayHasKey( 'billing_address', $result['preloaded_requests']['checkout'], 'Falls back to the cart session preload.' );
	}

	/**
	 * @testdox Should forward WooPay Cart-Token to Store API preload subrequests.
	 */
	public function test_forwards_woopay_cart_token_to_store_api_preload_subrequests(): void {
		$seen_headers = array();
		add_filter(
			'rest_pre_dispatch',
			static function ( $preempt, $server, WP_REST_Request $request ) use ( &$seen_headers ) {
				unset( $server );
				if ( ! in_array( $request->get_route(), array( '/wc/store/v1/cart', '/wc/store/v1/checkout' ), true ) ) {
					return $preempt;
				}

				$seen_headers[ $request->get_route() ] = $request->get_header( 'Cart-Token' );

				return new WP_REST_Response(
					'/wc/store/v1/cart' === $request->get_route()
						? array(
							'items_count' => 1,
							'items'       => array(
								array( 'id' => 123 ),
							),
						)
						: array(
							'order_id'        => 456,
							'payment_methods' => array( 'woocommerce_payments' ),
						)
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'Cart-Token', 'cart-token-123' );

		$sut     = $this->create_service();
		$result  = $sut->start_init_session_request( 'shopper@example.com', null, $request );
		$preload = $result['preloaded_requests'];

		$this->assertSame( 'cart-token-123', $seen_headers['/wc/store/v1/cart'] );
		$this->assertSame( 'cart-token-123', $seen_headers['/wc/store/v1/checkout'] );
		$this->assertSame( 1, $preload['cart']['items_count'] );
		$this->assertSame( array( 'woocommerce_payments' ), $preload['checkout']['payment_methods'] );
	}

	/**
	 * @testdox Should encrypt minimum session data with the WooPay reference shape.
	 */
	public function test_encrypts_minimum_session_data_with_reference_shape(): void {
		\Jetpack_Options::update_option( 'id', 12345 );
		\Jetpack_Options::update_option( 'blog_token', 'blog-token' );

		$sut    = $this->create_service();
		$result = $sut->get_encrypted_minimum_session_data();

		$this->assertSame( '12345', $result['blog_id'] );
		$this->assertArrayHasKey( 'session', $result['data'] );
		$this->assertArrayHasKey( 'iv', $result['data'] );
		$this->assertArrayHasKey( 'hash', $result['data'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies WooPay encrypted payload encoding.
		$this->assertNotSame( '', base64_decode( $result['data']['session'], true ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies WooPay encrypted payload encoding.
		$this->assertNotSame( false, base64_decode( $result['data']['iv'], true ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies WooPay encrypted payload encoding.
		$this->assertNotSame( false, base64_decode( $result['data']['hash'], true ) );
	}

	/**
	 * @testdox Should expose direct checkout only when the feature, account, gateway, and WooPay are enabled.
	 * @dataProvider direct_checkout_eligibility_provider
	 *
	 * @param string|null $feature_option Feature option value, or null when absent.
	 * @param bool        $account_eligible Whether the account is direct-checkout eligible.
	 * @param bool        $gateway_enabled Whether the base gateway's enabled setting is on.
	 * @param bool        $woopay_enabled Whether WooPay is enabled.
	 * @param bool        $expected Expected direct-checkout config value.
	 */
	public function test_frontend_config_reports_direct_checkout_eligibility( ?string $feature_option, bool $account_eligible, bool $gateway_enabled, bool $woopay_enabled, bool $expected ): void {
		if ( null === $feature_option ) {
			delete_option( '_wcpay_feature_woopay_direct_checkout' );
		} else {
			update_option( '_wcpay_feature_woopay_direct_checkout', $feature_option );
		}

		$sut = $this->create_service(
			array(
				'enabled'           => $gateway_enabled ? 'yes' : 'no',
				'platform_checkout' => $woopay_enabled ? 'yes' : 'no',
			),
			array( 'platform_direct_checkout_eligible' => $account_eligible )
		);

		$this->assertSame( $expected, $sut->get_woopay_frontend_config( 'cart' )['isWooPayDirectCheckoutEnabled'] );
	}

	/**
	 * Provide direct-checkout eligibility combinations from WooPayments Client 11.1.0.
	 *
	 * @return array<string,array{string|null,bool,bool,bool,bool}>
	 */
	public function direct_checkout_eligibility_provider(): array {
		return array(
			'eligible with absent option' => array( null, true, true, true, true ),
			'explicitly disabled option'  => array( '0', true, true, true, false ),
			'ineligible account'          => array( '1', false, true, true, false ),
			'disabled gateway'            => array( '1', true, false, true, false ),
			'disabled WooPay'             => array( '1', true, true, false, false ),
		);
	}

	/**
	 * @testdox The WooPay config forces network saved cards for a visitor outside the WooPay countries, whose save-user fields stay unloaded.
	 *
	 * Client 11.1.0 reads one predicate for both configs (class-wc-payments-checkout.php:194, :599) and gates only the
	 * save-user script on the visitor's country (class-woopay-save-user.php:55).
	 */
	public function test_woopay_config_and_card_config_share_the_platform_predicate_for_a_visitor_outside_the_woopay_countries(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		// WC_Geolocation::geolocate_ip() returns the country this filter gives before any lookup.
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$sut = $this->create_service( array(), array(), null, null, null, false );

		try {
			$this->assertTrue( $sut->get_woopay_frontend_config( 'checkout' )['forceNetworkSavedCards'] );
			$this->assertFalse( $this->woopay_eligibility->should_load_woopay_save_user_assets( 'checkout' ) );
			$this->assertTrue( $this->woopay_eligibility->should_use_stripe_platform_on_checkout_page() );
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}
	}

	/**
	 * @testdox The WooPay OTP pre-fill puts the shopper's email into page HTML only where no page cache serves it to others: $label.
	 *
	 * Client 11.1.0 pre-fills every button surface, product pages included, from WooPay_Session::get_user_email()
	 * (class-wc-payments-woopay-button-handler.php:151, class-woopay-session.php:433-472), which reads a guest's billing
	 * email. Core defines DONOTCACHEPAGE only on the configured cart, checkout and My Account pages
	 * (WC_Cache_Helper::prevent_caching() on wp_headers, which go_to() runs). Another page carrying the cart or checkout
	 * shortcode reads as cart or checkout (CartCheckoutUtils::is_page_type()) without that protection. Logged-in pages are not
	 * page-cached. A separate process, because DONOTCACHEPAGE stays defined once a page defines it, and CartCheckoutUtils
	 * keeps its first page answer.
	 *
	 * @testWith ["a guest on a product page", "product", false, ""]
	 *           ["a guest on the cart page", "cart", false, "guest@example.com"]
	 *           ["a guest on the checkout page", "checkout", false, "guest@example.com"]
	 *           ["a guest on another page carrying the cart shortcode", "other cart", false, ""]
	 *           ["a guest on another page carrying the checkout shortcode", "other checkout", false, ""]
	 *           ["a logged-in shopper on a product page", "product", true, "shopper-billing@example.com"]
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $label     Case label.
	 * @param string $page      Page the shopper views.
	 * @param bool   $logged_in Whether the shopper is logged in.
	 * @param string $expected  The pre-filled email.
	 */
	public function test_frontend_config_prefills_the_shopper_email_only_on_uncached_pages( string $label, string $page, bool $logged_in, string $expected ): void {
		unset( $label );
		$create_page = fn( string $content ): int => $this->factory->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		update_option( 'woocommerce_cart_page_id', $create_page( '[woocommerce_cart]' ) );
		update_option( 'woocommerce_checkout_page_id', $create_page( '[woocommerce_checkout]' ) );
		$page_ids = array(
			'product'        => \WC_Helper_Product::create_simple_product()->get_id(),
			'cart'           => (int) get_option( 'woocommerce_cart_page_id' ),
			'checkout'       => (int) get_option( 'woocommerce_checkout_page_id' ),
			'other cart'     => $create_page( '[woocommerce_cart]' ),
			'other checkout' => $create_page( '[woocommerce_checkout]' ),
		);
		$user_id  = 0;
		if ( $logged_in ) {
			$user_id = $this->factory->user->create( array( 'user_email' => 'shopper@example.com' ) );
			wp_set_current_user( $user_id );
		}
		$this->go_to( get_permalink( $page_ids[ $page ] ) );
		if ( 0 === strpos( $page, 'other ' ) ) {
			$this->assertTrue( is_cart() || is_checkout(), 'The other page reads as cart or checkout.' );
		}
		$customer      = WC()->customer;
		WC()->customer = new \WC_Customer( $user_id, true );
		WC()->customer->set_billing_email( $logged_in ? 'shopper-billing@example.com' : 'guest@example.com' );

		try {
			$config = $this->create_service()->get_woopay_frontend_config( 'product' );
		} finally {
			WC()->customer = $customer;
		}

		$this->assertSame( $expected, $config['woopaySessionEmail'] );
	}

	/**
	 * @testdox On a block theme with nothing stored, WooPay gets an appearance computed from the theme, and it is stored.
	 *
	 * Client 11.1.0 class-wc-payments-styles-cache.php:69-88 computes and stores the appearance for block themes, read
	 * behind global theme support (class-wc-payments-woopay-button-handler.php:153-158).
	 */
	public function test_block_theme_gives_woopay_an_appearance_computed_from_the_theme(): void {
		$original_theme = get_stylesheet();
		$sut            = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => 'yes' ),
			array( 'platform_global_theme_support_enabled' => true )
		);

		try {
			switch_theme( 'twentytwentyfive' );
			$theme_background = wp_get_global_styles( array( 'color', 'background' ), array( 'transforms' => array( 'resolve-variables' ) ) );
			$config           = $sut->get_woopay_frontend_config( 'checkout' );
			$stored           = get_option( 'wcpay_woopay_checkout_appearance' );
		} finally {
			switch_theme( $original_theme );
		}

		$this->assertIsArray( $config['woopayAppearance'] );
		$this->assertNotEmpty( $config['woopayAppearance'], 'WooPay gets the theme\'s appearance.' );
		$this->assertTrue( $this->appearance_store->validate_appearance_schema( $config['woopayAppearance'] ) );
		$this->assertSame( $theme_background, $config['woopayAppearance']['variables']['colorBackground'] ?? null );
		$this->assertIsArray( $stored, 'The computed appearance is stored.' );
		$this->assertSame( $config['woopayAppearance'], $stored['appearance'] ?? null );
	}

	/**
	 * @testdox With global theme support on and no appearance on a classic theme, the page and WooPay get null, so the classic checkout measures its own.
	 *
	 * Client 11.1.0 WC_Payments_Styles_Cache::get_woopay_appearance() returns null then (class-wc-payments-styles-cache.php:87),
	 * localized by class-wc-payments-woopay-button-handler.php:153-155 and sent by class-woopay-session.php:488-490. The
	 * classic checkout script falls back to the page's own styles only when woopayAppearance is falsy
	 * (woopayments-checkout.js getWooPayEmailInputAppearance()), and an empty PHP array prints as a truthy `[]`.
	 */
	public function test_no_woopay_appearance_is_sent_as_null(): void {
		$original_theme = get_stylesheet();
		$sut            = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => 'yes' ),
			array( 'platform_global_theme_support_enabled' => true )
		);

		try {
			switch_theme( 'storefront' );
			$config  = $sut->get_woopay_frontend_config( 'checkout' );
			$request = $sut->start_init_session_request( 'shopper@example.com' );
		} finally {
			switch_theme( $original_theme );
		}

		$this->assertTrue( $config['isWooPayGlobalThemeSupportEnabled'] );
		$this->assertNull( $config['woopayAppearance'] );
		$this->assertNull( $request['appearance'] );
	}

	/**
	 * @testdox A block theme whose computed appearance fails the schema gives WooPay no appearance and stores nothing.
	 *
	 * Client 11.1.0 class-wc-payments-styles-cache.php:79-84 stores a computed appearance only when it passes
	 * validate_appearance_schema(); every value must be a string of at most 200 characters.
	 */
	public function test_block_theme_appearance_failing_the_schema_is_not_sent_or_stored(): void {
		$original_theme = get_stylesheet();
		$sut            = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => 'yes' ),
			array( 'platform_global_theme_support_enabled' => true )
		);
		// A merchant's Site Editor font stack longer than the schema allows (WP_Theme_JSON_Data::update_with() merges it into
		// the user origin of the global styles wp_get_global_styles() reads).
		$long_font_stack = implode( ', ', array_fill( 0, 30, 'Merchant Display Font' ) );
		$user_styles     = static fn( $theme_json ) => $theme_json->update_with(
			array(
				'version' => \WP_Theme_JSON::LATEST_SCHEMA,
				'styles'  => array( 'typography' => array( 'fontFamily' => $long_font_stack ) ),
			)
		);
		add_filter( 'wp_theme_json_data_user', $user_styles );

		try {
			switch_theme( 'twentytwentyfive' );
			wp_clean_theme_json_cache();
			$config = $sut->get_woopay_frontend_config( 'checkout' );
		} finally {
			remove_filter( 'wp_theme_json_data_user', $user_styles );
			switch_theme( $original_theme );
			wp_clean_theme_json_cache();
		}

		$this->assertNull( $config['woopayAppearance'] );
		$this->assertFalse( get_option( 'wcpay_woopay_checkout_appearance' ) );
	}

	/**
	 * @testdox Should prefer a sanitized email from a valid encrypted identity envelope.
	 */
	public function test_encrypted_session_data_uses_valid_encrypted_identity_email(): void {
		WC()->customer->set_billing_email( 'fallback@example.com' );
		$request = array(
			'encrypted_data' => $this->create_encrypted_identity_envelope( '{"user_email":"shopper+direct@example.com"}' ),
		);

		$session = $this->decrypt_store_session( $this->create_service()->get_encrypted_session_data( $request ) );

		$this->assertSame( 'shopper+direct@example.com', $session['email'] );
	}

	/**
	 * @testdox Should ignore an invalid encrypted identity envelope and retain the customer email fallback.
	 * @dataProvider invalid_encrypted_identity_provider
	 *
	 * @param string $failure Invalid envelope failure to create.
	 */
	public function test_encrypted_session_data_ignores_invalid_encrypted_identity( string $failure ): void {
		WC()->customer->set_billing_email( 'fallback@example.com' );
		$json     = 'invalid-json' === $failure ? '{' : sprintf( '{"user_email":"%s"}', 'invalid-email' === $failure ? 'not an email' : 'secret@example.com' );
		$envelope = $this->create_encrypted_identity_envelope( $json );

		if ( 'invalid-hmac' === $failure ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a malformed WooPay identity fixture.
			$envelope['hash'] = base64_encode( 'invalid-hmac' );
		} elseif ( 'invalid-base64' === $failure ) {
			$envelope['data'] = '%%%not-base64%%%';
		} elseif ( 'malformed-fields' === $failure ) {
			unset( $envelope['iv'] );
		}

		$session = $this->decrypt_store_session(
			$this->create_service()->get_encrypted_session_data( array( 'encrypted_data' => $envelope ) )
		);

		$this->assertSame( 'fallback@example.com', $session['email'] );
		$this->assertStringNotContainsString( 'secret@example.com', wp_json_encode( $session ) );
	}

	/**
	 * Provide invalid encrypted identity failures.
	 *
	 * @return array<string,array{string}>
	 */
	public function invalid_encrypted_identity_provider(): array {
		return array(
			'invalid HMAC'     => array( 'invalid-hmac' ),
			'invalid base64'   => array( 'invalid-base64' ),
			'malformed fields' => array( 'malformed-fields' ),
			'invalid JSON'     => array( 'invalid-json' ),
			'invalid email'    => array( 'invalid-email' ),
		);
	}

	/**
	 * @testdox Should persist and clear WooPay phone session data.
	 */
	public function test_persists_and_clears_woopay_phone_session_data(): void {
		$this->original_session = WC()->session;
		WC()->session           = $this->create_session();
		$sut                    = $this->create_service();

		$sut->set_woopay_phone_session_data(
			array(
				'save_user_in_woopay'     => 'true',
				'woopay_source_url'       => 'https://example.test/checkout',
				'woopay_is_blocks'        => 'yes',
				'woopay_viewport'         => 'desktop',
				'woopay_user_phone_field' => array(
					'full' => '+15555550123',
				),
			)
		);

		$this->assertSame(
			array(
				'save_user_in_woopay'     => true,
				'woopay_source_url'       => 'https://example.test/checkout',
				'woopay_is_blocks'        => true,
				'woopay_viewport'         => 'desktop',
				'woopay_user_phone_field' => array(
					'full' => '+15555550123',
				),
			),
			WC()->session->get( 'woopay-user-data' )
		);

		$sut->clear_woopay_session_data();

		$this->assertNull( WC()->session->get( 'woopay-user-data' ) );
	}

	/**
	 * @testdox Should keep flat WooPay phone values while accepting the frontend payload shape.
	 */
	public function test_persists_flat_woopay_phone_session_data(): void {
		$this->original_session = WC()->session;
		WC()->session           = $this->create_session();
		$sut                    = $this->create_service();

		$sut->set_woopay_phone_session_data(
			array(
				'phone_number' => '+15555550124',
			)
		);

		$this->assertSame(
			'+15555550124',
			WC()->session->get( 'woopay-user-data' )['woopay_user_phone_field']['full']
		);
	}

	/**
	 * @testdox Should not geolocate the shopper while building the frontend config when WooPay is off.
	 */
	public function test_frontend_config_does_not_geolocate_when_woopay_is_off(): void {
		$geolocations = 0;
		add_filter(
			'woocommerce_geolocate_ip',
			static function () use ( &$geolocations ) {
				++$geolocations;
				return 'US';
			}
		);

		$config = $this->create_service( array( 'platform_checkout' => 'no' ), array(), null, null, null, false )->get_woopay_frontend_config( 'checkout' );

		$this->assertSame( 0, $geolocations );
		$this->assertFalse( $config['woopayIsCountryAvailable'] );
	}

	/**
	 * @testdox Should not resolve the available gateways while building the frontend config when WooPay is off.
	 */
	public function test_frontend_config_does_not_resolve_available_gateways_when_woopay_is_off(): void {
		$resolutions      = 0;
		$count_resolution = static function ( $gateways ) use ( &$resolutions ) {
			++$resolutions;
			return $gateways;
		};
		add_filter( 'woocommerce_available_payment_gateways', $count_resolution );

		try {
			$config = $this->create_service( array( 'platform_checkout' => 'no' ) )->get_woopay_frontend_config( 'checkout' );
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $count_resolution );
		}

		$this->assertSame( 0, $resolutions );
		$this->assertFalse( $config['shouldShowWooPayButton'] );
	}

	/**
	 * @testdox Should report live mode in the WooPay frontend config so the express OTP iframe opens in live mode.
	 */
	public function test_woopay_frontend_config_reports_live_mode(): void {
		$config = $this->create_service( array(), array(), null, null, null, false )->get_woopay_frontend_config( 'checkout' );

		$this->assertFalse( $config['testMode'] );
	}

	/**
	 * @testdox Should build the WooPay checkout frontend config.
	 */
	public function test_builds_woopay_checkout_frontend_config(): void {
		\Jetpack_Options::update_option( 'id', 12345 );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$sut = $this->create_service();
		$this->appearance_store->save_woopay_appearance(
			array( 'theme' => 'stripe' ),
			array(
				array( 'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter' ),
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['isWooPayDirectCheckoutEnabled'] );
		$this->assertFalse( $config['isWooPayGlobalThemeSupportEnabled'] );
		$this->assertFalse( $config['forceNetworkSavedCards'] );
		$this->assertSame( 'https://pay.woo.com', $config['woopayHost'] );
		$this->assertSame( '12345', $config['woopayMerchantId'] );
		$this->assertTrue( $config['testMode'] );
		$this->assertSame( 'checkout', $config['woopayButton']['context'] );
		$this->assertSame( 'default', $config['woopayButton']['type'] );
		$this->assertSame( 'dark', $config['woopayButton']['theme'] );
		$this->assertSame( '48', $config['woopayButton']['height'] );
		$this->assertSame( '', $config['woopayButton']['radius'] );
		$this->assertArrayHasKey( 'woopayButtonNonce', $config );
		$this->assertArrayHasKey( 'addToCartNonce', $config );
		$this->assertTrue( $config['shouldShowWooPayButton'] );
		$this->assertTrue( $config['woopayIsCountryAvailable'] );
		$this->assertNull( $config['woopayAppearance'] );
		$this->assertSame( array(), $config['woopayFontRules'] );
		// Translated copy the scripts render as is: the wording is presentation, so only its presence is pinned.
		foreach ( array( 'woopayOtpIframeTitle', 'woopayOtpCloseLabel', 'woopayUnavailableMessage', 'woopayExpressUnavailableMessage', 'woopaySaveUserLabel', 'woopayPhoneLabel', 'woopayAdditionalInfoText', 'woopayAgreementText', 'woopayTermsOfServiceLabel', 'woopayPrivacyPolicyLabel' ) as $copy_key ) {
			$this->assertIsString( $config[ $copy_key ], $copy_key );
			$this->assertNotSame( '', $config[ $copy_key ], $copy_key );
		}
		// The classic script turns these placeholders into the terms and privacy links (client 11.1.0
		// client/components/woopay/save-user/agreement.js).
		$this->assertStringContainsString( '<termsOfService/>', $config['woopayAgreementText'] );
		$this->assertStringContainsString( '<privacyPolicy/>', $config['woopayAgreementText'] );
		$this->assertArrayHasKey( 'platformTrackerNonce', $config );
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $config['tracksUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['tracksRestNonce'], 'wp_rest' ) );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
		$this->assertArrayHasKey( 'isShopperTrackingEnabled', $config );
		$this->assertArrayNotHasKey( 'is_shopper_tracking_enabled', $config );
	}

	/**
	 * @testdox WooPay first-party auth follows the express feature option, eligibility and country, not the button locations.
	 */
	public function test_first_party_auth_flag_follows_the_express_option_eligibility_and_country(): void {
		$this->assertTrue(
			$this->create_service()->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'US eligible WooPay checkout should enable first-party auth.'
		);

		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );
		$this->assertFalse(
			$this->create_service( array(), array(), null, null, null, false )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Live-mode shoppers outside the synced WooPay country list should not enable first-party auth.'
		);
		remove_all_filters( 'woocommerce_geolocate_ip' );

		$this->assertFalse(
			$this->create_service( array(), array( 'platform_checkout_eligible' => false ) )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Accounts that are not eligible for platform checkout should not enable first-party auth.'
		);

		$this->assertFalse(
			$this->create_service( array( 'platform_checkout' => 'no' ) )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Stores with WooPay disabled should not enable first-party auth.'
		);

		// Client 11.1.0 class-woopay-utilities.php:80-82 checks the express feature option, eligibility and country only.
		$this->assertTrue(
			$this->create_service( array( 'express_checkout_checkout_methods' => array() ) )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Removing the WooPay button from checkout must keep first-party auth for the email input.'
		);

		update_option( '_wcpay_feature_woopay_express_checkout', '0' );
		try {
			$this->assertFalse(
				$this->create_service()->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
				'The WooPay express feature option turned off disables first-party auth.'
			);
		} finally {
			delete_option( '_wcpay_feature_woopay_express_checkout' );
		}
	}

	/**
	 * @testdox The WooPay button takes its height from the express checkout size and its radius as stored: $_dataName.
	 * @dataProvider woopay_button_size_provider
	 *
	 * Heights and radius follow client includes/express-checkout/class-wc-payments-express-checkout-button-helper.php:475-487
	 * (size to height) and :441 (radius as stored).
	 *
	 * @param string $size            The express checkout button size.
	 * @param string $radius          The stored button border radius.
	 * @param string $expected_height The expected button height.
	 */
	public function test_uses_extension_woopay_button_style_settings( string $size, string $radius, string $expected_height ): void {
		$sut = $this->create_service(
			array(
				'payment_request_button_size'          => $size,
				'payment_request_button_border_radius' => $radius,
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertSame( $expected_height, $config['woopayButton']['height'] );
		$this->assertSame( $radius, $config['woopayButton']['radius'] );
	}

	/**
	 * Express checkout button sizes, each with its own stored radius, and the height each gets.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function woopay_button_size_provider(): array {
		return array(
			'small'  => array( 'small', '4', '40' ),
			'medium' => array( 'medium', '12', '48' ),
			'large'  => array( 'large', '0', '55' ),
		);
	}

	/**
	 * @testdox Should build WooPay save-user checkout data from the account cache.
	 */
	public function test_builds_woopay_save_user_checkout_data(): void {
		$sut = $this->create_service(
			array(),
			array(
				'country'                    => 'US',
				'pre_check_save_my_info'     => true,
				'platform_checkout_eligible' => true,
			)
		);

		$this->assertSame(
			array(
				'PRE_CHECK_SAVE_MY_INFO'         => true,
				'woopayPhoneValidationScriptUrl' => WooPaymentsFrontendAssets::get_phone_validation_script_url(),
			),
			$sut->get_save_user_checkout_data()
		);
	}

	/**
	 * @testdox Should add WooPay save-user session data to order metadata.
	 */
	public function test_adds_woopay_session_data_to_order_metadata(): void {
		$this->original_session = WC()->session;
		WC()->session           = $this->create_session();
		WC()->session->set(
			'woopay-user-data',
			array(
				'save_user_in_woopay'     => true,
				'woopay_source_url'       => 'https://example.test/checkout',
				'woopay_is_blocks'        => true,
				'woopay_viewport'         => 'desktop',
				'woopay_user_phone_field' => array(
					'full' => '+15555550123',
				),
			)
		);

		$order = \WC_Helper_Order::create_order();
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_phone( '+15555550000' );
		$order->set_billing_company( 'Analytical Engines' );
		$order->set_shipping_first_name( 'Grace' );
		$order->set_shipping_last_name( 'Hopper' );
		$order->set_shipping_phone( '+15555550001' );
		$order->set_shipping_company( 'Compilers Inc' );

		$sut      = $this->create_service();
		$metadata = $sut->maybe_add_woopay_user_metadata( array( 'existing' => 'kept' ), $order );

		$this->assertSame( 'kept', $metadata['existing'] );
		$this->assertSame( 'Ada', $metadata['platform_checkout_primary_first_name'] );
		$this->assertSame( 'Lovelace', $metadata['platform_checkout_primary_last_name'] );
		$this->assertSame( '+15555550000', $metadata['platform_checkout_primary_phone'] );
		$this->assertSame( 'Analytical Engines', $metadata['platform_checkout_primary_company'] );
		$this->assertSame( 'Grace', $metadata['platform_checkout_secondary_first_name'] );
		$this->assertSame( 'Hopper', $metadata['platform_checkout_secondary_last_name'] );
		$this->assertSame( '+15555550001', $metadata['platform_checkout_secondary_phone'] );
		$this->assertSame( 'Compilers Inc', $metadata['platform_checkout_secondary_company'] );
		$this->assertSame( '+15555550123', $metadata['platform_checkout_phone'] );
		$this->assertSame( 'https://example.test/checkout', $metadata['platform_checkout_source_url'] );
		$this->assertTrue( $metadata['platform_checkout_is_blocks'] );
		$this->assertSame( 'desktop', $metadata['platform_checkout_viewport'] );
	}

	/**
	 * @testdox Should forward init session requests through filterable transport.
	 */
	public function test_forwards_init_session_request_through_filterable_transport(): void {
		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::update_option( 'id', 12345 );
			\Jetpack_Options::update_option( 'blog_token', 'token-key.blog-token' );
			\Jetpack_Options::update_option( 'time_diff', 0 );
		}

		$captured_request = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $parsed_args, string $url ) use ( &$captured_request ) {
				$captured_request = array(
					'url'     => $url,
					'body'    => json_decode( (string) $parsed_args['body'], true ),
					'headers' => $parsed_args['headers'],
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'result' => 'success' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$appearance = $this->get_valid_appearance();
		$font_rules = array(
			array(
				'cssSrc' => 'https://fonts.wp.com/font.css',
				'family' => 'Inter',
			),
		);

		$expected_font_rules = array(
			array(
				'cssSrc' => 'https://fonts.wp.com/font.css',
			),
		);

		$sut    = $this->create_service();
		$result = $sut->init_woopay_session(
			array(
				'email'        => 'shopper@example.com',
				'user_session' => 'qwerty123',
				'appearance'   => wp_json_encode( $appearance ),
				'font_rules'   => wp_json_encode( $font_rules ),
			)
		);

		$this->assertSame( array( 'result' => 'success' ), $result );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/init', strtok( $captured_request['url'], '?' ) );
		$query = wp_parse_url( $captured_request['url'], PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $query_args );
		$this->assertArrayHasKey( 'token', $query_args );
		$this->assertArrayHasKey( 'body-hash', $query_args );
		$this->assertArrayHasKey( 'signature', $query_args );
		$this->assertStringStartsWith( 'X_JETPACK ', $captured_request['headers']['Authorization'] );
		$this->assertSame( 'acct_123', $captured_request['body']['store_data']['account_id'] );
		$this->assertTrue( $captured_request['body']['store_data']['test_mode'] );
		$this->assertSame( 'qwerty123', $captured_request['body']['user_session'] );
		$this->assertSame( $appearance, $captured_request['body']['appearance'] );
		$this->assertSame( $expected_font_rules, $captured_request['body']['font_rules'] );
		$this->assertArrayHasKey( 'session_nonce', $captured_request['body'] );
		$this->assertArrayHasKey( 'store_api_token', $captured_request['body'] );
	}

	/**
	 * @testdox WooPay init failures and responses are logged with the result, HTTP status and error code only, while WooPayments logging is on.
	 *
	 * Client 11.1.0 logs the whole WP_Error and every response body (class-woopay-session.php:707-715); the init answer's URL
	 * carries the WooPay session key (client/checkout/woopay/direct-checkout/woopay-direct-checkout.js:162-170), so native logs
	 * neither.
	 */
	public function test_logs_woopay_init_failures_and_responses_while_logging_is_on(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->connect_woopay_blog();
		$request = array( 'email' => 'shopper@example.com' );
		// WP_Http::request() hands back the pre_http_request value; a transport failure is a WP_Error with the http_request_failed
		// code and Requests' "cURL error <number>: <curl_error()>" message (wp-includes/class-wp-http.php,
		// wp-includes/Requests/src/Transport/Curl.php).
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: pay.woo.com' ) );
		$lines = $this->record_woopayments_log_lines();

		$this->assertSame( array( 'result' => 'failure' ), $this->create_service()->init_woopay_session( $request ) );
		$this->assertSame(
			array(
				array(
					'error',
					'HTTP_REQUEST_ERROR: WooPay session init request failed.',
					array(
						'error_code' => 'http_request_failed',
						'curl_error' => 6,
					),
				),
				array( 'info', 'WooPay session init response.', array( 'result' => 'failure' ) ),
			),
			array_values( array_unique( $lines->getArrayCopy(), SORT_REGULAR ) )
		);
		$this->assert_log_lines_leak_nothing( $lines );

		// WooPay's init answer: the result and the WooPay URL the shopper is sent to, which carries the session key (client
		// 11.1.0 client/checkout/woopay/email-input-iframe.js:496-498 and client/components/woopay/index.tsx:47-48 read them).
		$body = '{"result":"success","url":"https://pay.woo.com/woopay/?platform_checkout_key=session-key-abc123"}';
		$this->answer_woopay_requests_with( 200, $body );
		$lines->exchangeArray( array() );

		$this->assertSame( json_decode( $body, true ), $this->create_service()->init_woopay_session( $request ) );
		$this->assertSame(
			array(
				array(
					'info',
					'WooPay session init response.',
					array(
						'result'      => 'success',
						'http_status' => 200,
					),
				),
			),
			array_values( array_unique( $lines->getArrayCopy(), SORT_REGULAR ) )
		);
		$this->assert_log_lines_leak_nothing( $lines );

		// A WordPress REST error from WooPay's init route: core's {code, message, data: {status}} (wp-includes/rest-api.php
		// rest_convert_error_to_response(); rest_forbidden is WP_REST_Server's permission refusal).
		$this->answer_woopay_requests_with( 403, '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":403}}' );
		$lines->exchangeArray( array() );

		$this->create_service()->init_woopay_session( $request );
		$this->assertSame(
			array(
				array(
					'info',
					'WooPay session init response.',
					array(
						'result'      => 'failure',
						'http_status' => 403,
					),
				),
			),
			array_values( array_unique( $lines->getArrayCopy(), SORT_REGULAR ) )
		);
	}

	/**
	 * @testdox A failed WooPay init request logs only an error code WordPress or Jetpack sets, and its own cURL error number ($_dataName).
	 *
	 * WP_Http::request() hands back whatever a pre_http_request callback returns, and WP_Error keeps any code
	 * (wp-includes/class-wp-error.php add(), get_error_code()), so an extension's error code can be a URL, an email or a token.
	 * Requests words a transport failure as "cURL error <number>: <curl_error()>" (wp-includes/Requests/src/Transport/Curl.php);
	 * WP_Http::request() sets other messages under the same http_request_failed code (wp-includes/class-wp-http.php), such as
	 * "A valid URL was not provided.".
	 *
	 * @dataProvider woopay_init_request_errors
	 *
	 * @param string              $code     The WP_Error code a pre_http_request callback returns.
	 * @param string              $message  The WP_Error message.
	 * @param array<string,mixed> $expected The logged context.
	 */
	public function test_logs_only_known_woopay_init_error_codes( string $code, string $message, array $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->connect_woopay_blog();
		add_filter( 'pre_http_request', static fn() => new \WP_Error( $code, $message ) );
		$lines = $this->record_woopayments_log_lines();

		$this->assertSame( array( 'result' => 'failure' ), $this->create_service()->init_woopay_session( array( 'email' => 'shopper@example.com' ) ) );
		$this->assertContains(
			array( 'error', 'HTTP_REQUEST_ERROR: WooPay session init request failed.', $expected ),
			$lines->getArrayCopy()
		);
		if ( $code !== $expected['error_code'] ) {
			$this->assertStringNotContainsString( $code, (string) wp_json_encode( $lines->getArrayCopy(), JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * Failed WooPay init requests and the context logged for each.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public function woopay_init_request_errors(): array {
		$blocked = 'Request blocked by an extension.';

		return array(
			'a signed URL'                  => array( 'https://pay.woo.test/wp-json/platform-checkout/v1/init?token=tok_secret_123&signature=sig_abc', $blocked, array( 'error_code' => 'unknown_error' ) ),
			'an email'                      => array( 'other-shopper@example.com', $blocked, array( 'error_code' => 'unknown_error' ) ),
			'a token'                       => array( 'tok_secret_123', $blocked, array( 'error_code' => 'unknown_error' ) ),
			"WordPress's blocked request"   => array( 'http_request_not_executed', $blocked, array( 'error_code' => 'http_request_not_executed' ) ),
			"Jetpack's missing token"       => array( 'missing_token', $blocked, array( 'error_code' => 'missing_token' ) ),
			'a host that does not resolve'  => array(
				'http_request_failed',
				'cURL error 6: Could not resolve host: pay.woo.com',
				array(
					'error_code' => 'http_request_failed',
					'curl_error' => 6,
				),
			),
			'a timeout'                     => array(
				'http_request_failed',
				'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
				array(
					'error_code' => 'http_request_failed',
					'curl_error' => 28,
				),
			),
			'a message that is not cURL\'s' => array( 'http_request_failed', 'A valid URL was not provided.', array( 'error_code' => 'http_request_failed' ) ),
		);
	}

	/**
	 * @testdox A failed WooPay init request writes no log line while WooPayments logging and dev mode are off.
	 */
	public function test_woopay_init_failure_logs_nothing_while_logging_is_off(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$this->connect_woopay_blog();
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' ) );
		$lines = $this->record_woopayments_log_lines();

		$this->assertSame( array( 'result' => 'failure' ), $this->create_service()->init_woopay_session( array() ) );
		$this->assertSame( array(), $lines->getArrayCopy() );
	}

	/**
	 * @testdox Session data that cannot be signed for want of a blog token or blog ID is logged while WooPayments logging is on.
	 *
	 * @testWith ["", "blog-token", "WooPay blog_id is missing, so the WooPay session data cannot be signed."]
	 *           ["12345", "", "WooPay blog_token is currently misconfigured."]
	 *
	 * @param string $blog_id    Store blog ID.
	 * @param string $blog_token Store blog token.
	 * @param string $expected   Expected log line.
	 */
	public function test_logs_unsignable_session_data_while_logging_is_on( string $blog_id, string $blog_token, string $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		\Jetpack_Options::update_option( 'id', $blog_id );
		\Jetpack_Options::update_option( 'blog_token', $blog_token );
		$lines = $this->record_woopayments_log_lines();

		$this->assertSame( array(), $this->create_service()->encrypt_and_sign_data( array( 'email' => 'shopper@example.com' ) ) );
		$this->assertContains( array( 'info', $expected, array() ), $lines->getArrayCopy() );
	}

	/**
	 * @testdox Init session request includes checkout block extension data and optional field status.
	 *
	 * A separate process: the extension class alias lives for the whole PHP process, and later WooPay tests must not see
	 * the extension as active.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_session_request_includes_blocks_data_and_optional_fields_status(): void {
		$this->register_fake_mailchimp_blocks_integration();
		delete_option( 'woocommerce_checkout_page_id' );

		$sut     = $this->create_service();
		$request = $sut->start_init_session_request( 'shopper@example.com' );

		$this->assertArrayHasKey( 'mailchimp-newsletter_data', $request['store_data']['blocks_data'] );
		$this->assertSame(
			array(
				'enabled' => true,
				'source'  => 'fake-mailchimp',
			),
			$request['store_data']['blocks_data']['mailchimp-newsletter_data']
		);
		$this->assertArrayHasKey( 'company', $request['store_data']['optional_fields_status'] );
		$this->assertSame( 'required', $request['store_data']['optional_fields_status']['phone'] );
		$this->assertIsArray( $request['store_data']['checkout_schema_namespaces'] );
	}

	/**
	 * @testdox Init session request includes adapted extension data and guest email verification nonce.
	 *
	 * A separate process: the extension class alias lives for the whole PHP process, and later WooPay tests must not see
	 * the extension as active.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_session_request_includes_points_and_rewards_adapted_extension_data(): void {
		if ( ! class_exists( '\WC_Points_Rewards_Manager', false ) ) {
			class_alias( FakeWooPayPointsRewardsManager::class, 'WC_Points_Rewards_Manager' );
		}

		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'points-shopper@example.com',
			)
		);
		FakeWooPayPointsRewardsManager::$points[ $user_id ] = 250;
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-points-and-rewards' ) );
		update_option( 'wc_points_rewards_redeem_points_ratio', '10:1' );

		$adapted_extensions = new WooPaymentsWooPayAdaptedExtensions();
		$adapted_extensions->register( new FakeWooPayPointsRewardsBlocksIntegration() );

		$sut     = $this->create_service( array(), array(), $adapted_extensions );
		$request = $sut->start_init_session_request( 'points-shopper@example.com' );

		$this->assertSame( array(), $request['extension_data'] );
		$this->assertSame(
			array(
				'minimum_points_amount' => 100,
				'points_ratio'          => array(
					'points'         => 10.0,
					'monetary_value' => 1.0,
				),
				'should_verify_email'   => true,
				'points_available'      => 250,
			),
			$request['adapted_extensions']['points-and-rewards']
		);

		$nonce_tick = wp_nonce_tick( 'wc_store_api' );
		$this->assertSame(
			substr( wp_hash( $nonce_tick . '|wc_store_api|' . $user_id . '|', 'nonce' ), -12, 10 ),
			$request['email_verified_session_nonce']
		);
	}

	/**
	 * @testdox Init session request forwards the extension data of the adapted extensions registry.
	 *
	 * Client 11.1.0 puts the registry's extension data in the init request (includes/woopay/class-woopay-session.php:584-585),
	 * in the shape Affiliate for WooCommerce gets (includes/woopay/class-woopay-adapted-extensions.php:172-176).
	 */
	public function test_init_session_request_forwards_extension_data(): void {
		$adapted_extensions = new class() extends WooPaymentsWooPayAdaptedExtensions {
			/**
			 * Get the extension data of an active Affiliate for WooCommerce referral.
			 *
			 * @return array<string,mixed>
			 */
			public function get_extension_data(): array {
				return array( 'affiliate-for-woocommerce' => array( 'affiliate-user' => 42 ) );
			}
		};

		$request = $this->create_service( array(), array(), $adapted_extensions )->start_init_session_request( 'shopper@example.com' );

		$this->assertSame( array( 'affiliate-for-woocommerce' => array( 'affiliate-user' => 42 ) ), $request['extension_data'] );
	}

	/**
	 * @testdox The init-session request email is $_dataName.
	 * @dataProvider init_session_email_provider
	 *
	 * @param string|null $user_email     Email of a store account to log in, or null for a guest.
	 * @param string      $billing_email  The WooCommerce customer's billing email.
	 * @param string|null $customer_email The WooCommerce customer's email to set, or null to leave it.
	 * @param string|null $supplied_email The email the request supplies.
	 * @param string      $expected       The request email.
	 */
	public function test_init_session_request_email_fallbacks( ?string $user_email, string $billing_email, ?string $customer_email, ?string $supplied_email, string $expected ): void {
		if ( null !== $user_email ) {
			wp_set_current_user( $this->factory->user->create( array( 'user_email' => $user_email ) ) );
		}
		WC()->customer->set_billing_email( $billing_email );
		if ( null !== $customer_email ) {
			WC()->customer->set_email( $customer_email );
		}

		$request = $this->create_service()->start_init_session_request( $supplied_email );

		$this->assertSame( $expected, $request['email'] );
	}

	/**
	 * Init-session request emails: the supplied one, then the customer's billing email, then the logged-in user's email.
	 *
	 * @return array<string,array{0:string|null,1:string,2:string|null,3:string|null,4:string}>
	 */
	public function init_session_email_provider(): array {
		return array(
			'the customer billing email when no email is supplied' => array( null, 'billing-fallback@example.com', null, null, 'billing-fallback@example.com' ),
			'the logged-in user email when neither a request email nor a customer email exists' => array( 'account-fallback@example.com', '', '', null, 'account-fallback@example.com' ),
			'the supplied email over every fallback' => array( null, 'billing-fallback@example.com', null, 'supplied@example.com', 'supplied@example.com' ),
		);
	}

	/**
	 * @testdox The init-session request carries the platform customer of the current shopper: $_dataName.
	 * @dataProvider init_session_platform_customer_provider
	 *
	 * @param bool   $logged_in   Whether a shopper is logged in.
	 * @param string $customer_id The platform customer ID the customer service answers.
	 * @param string $email       The email the request supplies.
	 */
	public function test_init_session_request_carries_platform_customer_id( bool $logged_in, string $customer_id, string $email ): void {
		$user_id = 0;
		if ( $logged_in ) {
			$user_id = $this->factory->user->create();
			wp_set_current_user( $user_id );
		}

		$captured_user_id = null;
		$customer_service = $this->create_recording_customer_service( $customer_id, $captured_user_id );

		$request = $this->create_service( array(), array(), null, null, $customer_service )->start_init_session_request( $email );

		$this->assertSame( $customer_id, $request['customer_id'] );
		$this->assertSame( $user_id, $captured_user_id );
	}

	/**
	 * Shoppers whose platform customer WooPay charges.
	 *
	 * @return array<string,array{0:bool,1:string,2:string}>
	 */
	public function init_session_platform_customer_provider(): array {
		return array(
			'the logged-in user'                   => array( true, 'cus_test_123', 'shopper@example.com' ),
			'a guest platform customer for user 0' => array( false, 'cus_guest_456', 'guest@example.com' ),
		);
	}

	/**
	 * @testdox Should read the WooPay save-user opt-in from the posted field or the WooPay session.
	 */
	public function test_should_save_user_in_woopay_reads_posted_and_session_flags(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->should_save_user_in_woopay() );

		$_POST['save_user_in_woopay'] = 'true';
		$this->assertTrue( $sut->should_save_user_in_woopay() );
		unset( $_POST['save_user_in_woopay'] );

		WC()->session->set( 'woopay-user-data', array( 'save_user_in_woopay' => 'true' ) );
		$this->assertTrue( $sut->should_save_user_in_woopay() );
		WC()->session->set( 'woopay-user-data', null );
	}

	/**
	 * @testdox Should report the WooPayments compatibility version to WooPay, not the WooCommerce core version.
	 */
	public function test_woopay_payloads_report_the_woopayments_client_version(): void {
		$sut = $this->create_service();

		$expected = \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion::VERSION;

		$this->assertSame( $expected, $sut->get_minimum_session_data()['wcpay_version'] );
		$this->assertSame( $expected, $sut->start_init_session_request( 'shopper@example.com' )['wcpay_version'] );
		$this->assertSame( $expected, $sut->get_woopay_frontend_config( 'checkout' )['wcpayVersionNumber'] );
	}

	/**
	 * @testdox Should advertise the WooPay email-input flow by default while direct checkout stays unported.
	 */
	public function test_frontend_config_advertises_email_input_but_not_direct_checkout(): void {
		// A guest the client's guest rule includes (see test_checkout_config_applies_the_guest_rule_to_the_woopay_email_input).
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$config = $this->create_service( array( 'is_woopay_direct_checkout_enabled' => 'yes' ) )->get_woopay_frontend_config( 'checkout' );

		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['isWooPayDirectCheckoutEnabled'] );
	}

	/**
	 * @testdox Should let the wcpay_is_woopay_email_input_enabled filter switch the email-input flow off.
	 */
	public function test_email_input_flag_honors_the_woopayments_extension_filter(): void {
		add_filter( 'wcpay_is_woopay_email_input_enabled', '__return_false' );

		$sut = $this->create_service();

		$this->assertFalse( $this->woopay_eligibility->is_woopay_email_input_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEmailInputEnabled'] );
	}

	/**
	 * @testdox The init-session request carries the stored appearance and font rules only while global theme support is on: $_dataName.
	 * @dataProvider stored_appearance_provider
	 *
	 * @param string                              $theme_support       The global theme support setting.
	 * @param array<int,array<string,mixed>>      $font_rules          The stored font rules.
	 * @param array<string,mixed>|null            $expected_appearance The request appearance.
	 * @param array<int,array<string,mixed>>|null $expected_font_rules The request font rules, or null when not checked.
	 */
	public function test_init_session_request_gates_stored_appearance_on_global_theme_support( string $theme_support, array $font_rules, ?array $expected_appearance, ?array $expected_font_rules ): void {
		$sut = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => $theme_support ),
			array( 'platform_global_theme_support_enabled' => true )
		);
		$this->appearance_store->save_woopay_appearance( array( 'theme' => 'night' ), $font_rules );

		$request = $sut->start_init_session_request( 'shopper@example.com' );

		$this->assertSame( $expected_appearance, $request['appearance'] );
		if ( null !== $expected_font_rules ) {
			$this->assertSame( $expected_font_rules, $request['font_rules'] );
		}
	}

	/**
	 * Global theme support settings and what the init-session request carries of the stored appearance.
	 *
	 * @return array<string,array{0:string,1:array<int,array<string,mixed>>,2:array<string,mixed>|null,3:array<int,array<string,mixed>>|null}>
	 */
	public function stored_appearance_provider(): array {
		return array(
			'theme support off withholds both' => array( 'no', array( array( 'src' => 'https://example.com/font.woff2' ) ), null, array() ),
			'theme support on falls back to the stored appearance' => array( 'yes', array(), array( 'theme' => 'night' ), null ),
		);
	}

	/**
	 * @testdox Should carry the shopper's Tracks identity in the init-session request.
	 */
	public function test_init_session_request_carries_tracks_user_identity(): void {
		$request = $this->create_service()->start_init_session_request( 'shopper@example.com' );

		$this->assertIsArray( $request['tracks_user_identity'] );
		$this->assertSame( 'anon', $request['tracks_user_identity']['_ut'] );
		$this->assertNotEmpty( $request['tracks_user_identity']['_ui'] );
	}
}
