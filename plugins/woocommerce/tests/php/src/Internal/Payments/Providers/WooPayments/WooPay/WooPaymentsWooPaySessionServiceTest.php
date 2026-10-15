<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
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
	 * @testdox Should require platform checkout to treat WooPay as enabled.
	 */
	public function test_woopay_requires_platform_checkout_to_be_enabled(): void {
		$sut = $this->create_service(
			array(
				'platform_checkout'                 => 'no',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);

		$this->assertFalse( $this->woopay_eligibility->is_woopay_enabled() );

		$sut = $this->create_service(
			array(
				'platform_checkout'                 => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array(),
			)
		);

		$this->assertTrue( $this->woopay_eligibility->is_woopay_enabled() );

		$sut = $this->create_service(
			array(
				'platform_checkout' => 'yes',
			),
			array(
				'platform_checkout_eligible' => false,
			)
		);

		$this->assertFalse( $this->woopay_eligibility->is_woopay_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * @testdox Should not treat WooPay as enabled for restricted account status $status.
	 * @dataProvider restricted_account_statuses
	 *
	 * @param string $status Restricted account status.
	 */
	public function test_woopay_is_not_enabled_for_restricted_accounts( string $status ): void {
		$sut = $this->create_service(
			array( 'platform_checkout' => 'yes' ),
			array( 'status' => $status )
		);

		$this->assertFalse( $this->woopay_eligibility->is_woopay_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * Restricted account statuses.
	 *
	 * @return array<string,array{string}>
	 */
	public function restricted_account_statuses(): array {
		return array(
			'under review' => array( 'under_review' ),
			'rejected'     => array( 'rejected.fraud' ),
		);
	}

	/**
	 * @testdox Should not treat WooPay as enabled for invalid account data.
	 * @dataProvider invalid_woopay_accounts
	 *
	 * @param array<string,mixed> $account_data Invalid account data.
	 */
	public function test_woopay_is_not_enabled_for_invalid_accounts( array $account_data ): void {
		$sut = $this->create_service(
			array( 'platform_checkout' => 'yes' ),
			$account_data
		);

		$this->assertFalse( $this->woopay_eligibility->is_woopay_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * Invalid WooPay accounts.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function invalid_woopay_accounts(): array {
		return array(
			'missing account'           => array( array( 'account_id' => '' ) ),
			'details not submitted'     => array( array( 'details_submitted' => false ) ),
			'missing card payments'     => array( array( 'capabilities' => array() ) ),
			'card payments unrequested' => array( array( 'capabilities' => array( 'card_payments' => 'unrequested' ) ) ),
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
	 * @testdox The checkout config's WooPay email lookup for a $shopper is $expected, as client 11.1.0's guest rule decides.
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
	 * @testdox Should not leak optional extension aliases to later tests.
	 *
	 * Runs isolated on purpose. The assertion is about what *this* test class
	 * defines, but class definitions are process-global and cannot be undone, so
	 * in a shared process it also sees classes other suites define — for example
	 * WooPaymentsGatewayTest evals a WC_Subscriptions_Cart double. A fresh
	 * process is what makes the assertion mean what its name says.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woopay_optional_extension_aliases_do_not_leak(): void {
		$this->assertFalse( class_exists( 'WC_Pre_Orders_Product', false ) );
		$this->assertFalse( class_exists( 'WC_Pre_Orders_Cart', false ) );
		$this->assertFalse( class_exists( 'WC_Product_Booking', false ) );
		$this->assertFalse( class_exists( 'WC_Subscriptions_Cart', false ) );
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
		$result = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );

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
			$result = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );

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

		$result = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );

		$this->assertSame( get_rest_url( null, 'wc/v3/payments/file/file_logo' ), $result['store_data']['store_logo'] );
	}

	/**
	 * @testdox WooPay session data names the versioned Store API base, wc/store/v1.
	 *
	 * Client 11.1.0 class-woopay-session.php:908-919 sends the Store API cart route's namespace.
	 */
	public function test_init_session_request_sends_the_versioned_store_api_url(): void {
		$result = $this->create_service()->get_init_session_request( 'shopper@example.com' );

		$this->assertSame( get_rest_url( null, 'wc/store/v1' ), $result['store_data']['store_api_url'] );
	}

	/**
	 * @testdox WooPay session data reports WooCommerce Subscriptions inactive while its class is not loaded.
	 */
	public function test_init_session_request_reports_woocommerce_subscriptions_inactive(): void {
		$result = $this->create_service()->get_init_session_request( 'shopper@example.com' );

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

		$result = $this->create_service()->get_init_session_request( 'shopper@example.com' );

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
		$result  = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );
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

		$result = $this->create_service()->get_init_session_request( null, null, null, $order->get_id(), $order->get_order_key(), $order->get_billing_email() );

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

		$result  = $this->create_service()->get_init_session_request( null, null, null, $order->get_id(), $order->get_order_key(), $order->get_billing_email() );
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

		$result = $this->create_service()->get_init_session_request( null, null, null, $order->get_id(), $key, $order->get_billing_email() );

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
		$result  = $sut->get_init_session_request( 'shopper@example.com', null, $request );
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
	 * @testdox A classic checkout after an unfinished WooPay checkout places the WooPay draft order instead of a new one.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-direct-checkout.php:56-79 moves store_api_draft_order to the pending
	 * order_awaiting_payment that WC_Checkout::create_order() resumes, so the draft's own stock hold cannot block the order.
	 */
	public function test_classic_checkout_resumes_the_woopay_draft_order(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		// The draft the Store API saves when WooPay places the order (Checkout::create_or_update_draft_order()).
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertSame( $draft->get_id(), $order_id );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $order_id )->get_status() );
		$this->assertNull( WC()->session->get( 'store_api_draft_order' ) );
		// The takeover released the order payment lock, so the checkout that follows can pay the order.
		$store = wc_get_container()->get( OrderPaymentLock::class );
		$token = $store->claim( $draft, new WooPaymentsPersistenceVocabulary(), 'attempt-key', 'checkout' );
		$this->assertIsString( $token );
		$store->release( $draft, new WooPaymentsPersistenceVocabulary(), $token );
	}

	/**
	 * @testdox A classic checkout resumes a $status WooPay draft order after an earlier order in the same session was paid.
	 *
	 * WC_Order::payment_complete() and the unpaid-order cancellation write false to order_awaiting_payment
	 * (class-wc-order.php:147, class-wc-form-handler.php:918), and WC_Session::__isset() reports a stored false as set, so
	 * core reads that key with get() and treats a falsy value as no order (class-wc-cart.php:886-891). Client 11.1.0 uses
	 * isset() (class-wc-payments-woopay-direct-checkout.php:62) and skips the draft here. The Store API keeps a failed order
	 * (a failed WooPay payment) under the draft key for a retry (DraftOrderTrait::is_valid_draft_order()).
	 *
	 * @testWith ["checkout-draft"]
	 *           ["failed"]
	 *
	 * @param string $status Status of the order the Store API draft key names.
	 */
	public function test_classic_checkout_resumes_the_woopay_draft_order_after_an_earlier_paid_order( string $status ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( $status );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', false );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertSame( $draft->get_id(), $order_id );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $order_id )->get_status() );
	}

	/**
	 * @testdox A classic checkout never resumes a $status order the Store API draft key names, nor stops for its lock; it places a new order.
	 *
	 * Core resumes only a checkout draft, or a pending or failed order (DraftOrderTrait::is_valid_draft_order(),
	 * WC_Checkout::create_order() at class-wc-checkout.php:424). Client 11.1.0 sets whatever order the key names to pending
	 * (class-wc-payments-woopay-direct-checkout.php:71-73). A refund holds the settled order's payment lock throughout: only
	 * an order that can still be paid stops the checkout while its lock is held.
	 *
	 * @testWith ["processing"]
	 *           ["completed"]
	 *           ["on-hold"]
	 *           ["cancelled"]
	 *           ["refunded"]
	 *
	 * @param string $status Status of the order the Store API draft key names.
	 */
	public function test_classic_checkout_never_resumes_a_settled_order_named_as_the_woopay_draft( string $status ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$settled = new \WC_Order();
		$settled->set_status( $status );
		$settled->set_cart_hash( WC()->cart->get_cart_hash() );
		$settled->save();
		WC()->session->set( 'store_api_draft_order', $settled->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $settled, $vocabulary, 'refund-key', 'refund' );
		$this->assertIsString( $token );

		try {
			$order_id = $this->create_classic_checkout_order();
		} finally {
			$store->release( $settled, $vocabulary, $token );
		}

		$this->assertIsInt( $order_id );
		$this->assertNotSame( $settled->get_id(), $order_id, 'A new order is placed.' );
		$this->assertSame( $status, wc_get_order( $settled->get_id() )->get_status() );
		$this->assertSame( $settled->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
	}

	/**
	 * @testdox A classic checkout never resumes a pending WooPay order whose payment lock is free; it places a new order.
	 *
	 * The Store API checkout sets the order to pending just before it calls the gateway (Routes/V1/Checkout.php, "Set initial
	 * status to 'pending'"), and the payment holds the order payment lock only from there on. Client 11.1.0 resumes it
	 * (class-wc-payments-woopay-direct-checkout.php:71-73); core's Store API resumes a pending order only while the cart is
	 * unchanged (DraftOrderTrait::is_valid_draft_order()). An abandoned pending order is left to core's unpaid-order cleanup;
	 * while a payment holds its lock, checkout stops instead (test_classic_checkout_stops_while_the_woopay_order_payment_lock_is_held).
	 */
	public function test_classic_checkout_never_resumes_a_pending_woopay_order(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$pending = new \WC_Order();
		$pending->set_status( OrderStatus::PENDING );
		$pending->set_cart_hash( WC()->cart->get_cart_hash() );
		$pending->save();
		WC()->session->set( 'store_api_draft_order', $pending->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );

		$order_id = $this->create_classic_checkout_order();

		$this->assertIsInt( $order_id );
		$this->assertNotSame( $pending->get_id(), $order_id, 'A new order is placed.' );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $pending->get_id() )->get_status() );
		$this->assertSame( $pending->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
		$this->assert_order_payment_lock_is_free( $pending, 'The status check under the lock returned early; the lock must be released.' );
	}

	/**
	 * @testdox A classic checkout stops with a notice, places no order and leaves the $status WooPay order alone while another request holds its payment lock (refusal log line throws: $log_throws).
	 *
	 * Native checkout holds the order payment lock across the platform charge, with its attempt key as the lock value
	 * (PaymentProcessingService::process_checkout_outcome()). Placing a new order instead would let this checkout charge a
	 * second order while the first payment is unresolved. Client 11.1.0 takes the draft over without any lock
	 * (class-wc-payments-woopay-direct-checkout.php:56-79). The refusal's log line runs the woocommerce_logger_log_message
	 * filter (WC_Logger::log()); a filter that throws there must not change the refusal.
	 *
	 * @testWith ["checkout-draft", false]
	 *           ["pending", false]
	 *           ["failed", false]
	 *           ["pending", true]
	 *
	 * @param string $status     Status of the order the Store API draft key names.
	 * @param bool   $log_throws Whether the log filter throws on the lock refusal line.
	 */
	public function test_classic_checkout_stops_while_the_woopay_order_payment_lock_is_held( string $status, bool $log_throws ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( $status );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $draft, $vocabulary, 'attempt-key-of-another-request', 'checkout' );
		$new_orders = array();
		$record     = static function ( $order_id ) use ( &$new_orders ): void {
			$new_orders[] = $order_id;
		};
		$this->assertIsString( $token );
		add_action( 'woocommerce_new_order', $record );
		$throws = 0;
		if ( $log_throws ) {
			add_filter(
				'woocommerce_logger_log_message',
				static function ( $message ) use ( &$throws ) {
					if ( 0 === strpos( (string) $message, 'order payment lock refused: ' ) ) {
						++$throws;
						throw new \Error( 'Log write failed.' );
					}

					return $message;
				}
			);
		}

		try {
			$result = $this->create_classic_checkout_order();
		} finally {
			remove_action( 'woocommerce_new_order', $record );
			$store->release( $draft, $vocabulary, $token );
		}

		$this->assertSame( $log_throws ? 1 : 0, $throws );
		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_woopay_payment_in_progress', $result->get_error_code() );
		$this->assertSame( array(), $new_orders, 'No order is placed.' );
		$this->assertSame( $status, wc_get_order( $draft->get_id() )->get_status() );
		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
	}

	/**
	 * @testdox A full classic checkout retried while WooPay pays the pending order stops with a notice, places and pays no order, and keeps the order's stock hold (stock $stock).
	 *
	 * WC_Checkout::process_checkout() turns the WP_Error that create_order() returns from the woocommerce_create_order filter
	 * into its checkout error notice (class-wc-checkout.php:407-410, :1425-1430, :1464-1468). With one unit left, a second
	 * order could not reserve it while the first holds it (ReserveStock::reserve_stock_for_order()); client 11.1.0 names that
	 * last-unit case (class-wc-payments-woopay-direct-checkout.php:46-51). Runs in a separate process because
	 * process_checkout() defines WOOCOMMERCE_CHECKOUT for the rest of the process.
	 *
	 * @testWith [5]
	 *           [1]
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param int $stock Stock of the product in the cart.
	 */
	public function test_full_classic_checkout_stops_while_woopay_pays_the_pending_order( int $stock ): void {
		global $wpdb;

		// A guest checkout: with registration required, process_customer() logs the new account in and the test session,
		// never saved, would migrate empty (WC_Session_Handler::migrate_guest_session_to_user_session()).
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();
		// The order WooPay's Store API checkout placed: its draft held the stock (Checkout::create_or_update_draft_order() through
		// wc_reserve_stock_for_order()), then the route set it pending before calling the gateway.
		$paying = new \WC_Order();
		$paying->add_product( $product, 1 );
		$paying->set_cart_hash( WC()->cart->get_cart_hash() );
		$paying->calculate_totals();
		$paying->set_status( OrderStatus::CHECKOUT_DRAFT );
		$paying->save();
		wc_reserve_stock_for_order( $paying );
		$paying->set_status( OrderStatus::PENDING );
		$paying->save();
		WC()->session->set( 'store_api_draft_order', $paying->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$reserved = static function () use ( $wpdb, $paying ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(stock_quantity) FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d", $paying->get_id() ) );
		};
		$this->assertSame( 1, $reserved() );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $paying, $vocabulary, 'attempt-key-of-the-woopay-charge', 'checkout' );
		$new_orders = array();
		$record     = static function ( $order_id ) use ( &$new_orders ): void {
			$new_orders[] = $order_id;
		};
		$this->assertIsString( $token );
		add_action( 'woocommerce_new_order', $record );
		wc_clear_notices();
		// The classic checkout form's fields and nonce (WC_Checkout::process_checkout() reads $_REQUEST, get_posted_data() $_POST).
		$posted   = array(
			'billing_first_name'                 => 'Jane',
			'billing_last_name'                  => 'Doe',
			'billing_address_1'                  => '1 Main Street',
			'billing_city'                       => 'San Francisco',
			'billing_state'                      => 'CA',
			'billing_postcode'                   => '94110',
			'billing_country'                    => 'US',
			'billing_phone'                      => '5555555555',
			'billing_email'                      => 'guest@example.com',
			'payment_method'                     => 'woocommerce_payments',
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);
		$_POST    = $posted;
		$_REQUEST = $posted;

		try {
			WC()->checkout()->process_checkout();
		} finally {
			remove_action( 'woocommerce_new_order', $record );
			$store->release( $paying, $vocabulary, $token );
			$_POST    = array();
			$_REQUEST = array();
		}

		$this->assertSame(
			array( 'Your previous payment attempt is still being processed, so please wait a moment and check for an order confirmation before trying again.' ),
			array_column( wc_get_notices( 'error' ), 'notice' )
		);
		$this->assertSame( array(), $new_orders, 'No order is placed.' );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ), 'No payment ran: WC_Checkout::process_order_payment() records the order it pays.' );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $paying->get_id() )->get_status() );
		$this->assertSame( $paying->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertSame( 1, $reserved(), 'The paying order keeps its stock hold.' );
	}

	/**
	 * @testdox A classic checkout reads the WooPay draft again under the payment lock and leaves it alone once it was paid.
	 *
	 * Another request pays the failed draft after this checkout read it and before the takeover claims the lock. The core
	 * `query` filter (wpdb::query()) runs before each statement, so the payment is written right before the claim's
	 * INSERT IGNORE of the lock row (TransientRowLock::claim()).
	 */
	public function test_classic_checkout_leaves_a_woopay_draft_paid_before_the_takeover(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::FAILED );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$draft_id = $draft->get_id();
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		$lock_row = '_transient_' . WooPaymentsPersistenceVocabulary::LOCK_TRANSIENT_PREFIX . $draft_id;
		$paid     = false;
		$pay      = static function ( $query ) use ( $lock_row, $draft_id, &$paid ) {
			if ( ! $paid && is_string( $query ) && false !== strpos( $query, 'INSERT IGNORE' ) && false !== strpos( $query, "'" . $lock_row . "'" ) ) {
				$paid  = true;
				$order = wc_get_order( $draft_id );
				$order->set_status( OrderStatus::PROCESSING );
				$order->save();
			}

			return $query;
		};
		add_filter( 'query', $pay );

		try {
			$order_id = $this->create_classic_checkout_order();
		} finally {
			remove_filter( 'query', $pay );
		}

		$this->assertTrue( $paid, 'The payment landed before the claim.' );
		$this->assertIsInt( $order_id );
		$this->assertNotSame( $draft_id, $order_id, 'A new order is placed.' );
		$this->assertSame( OrderStatus::PROCESSING, wc_get_order( $draft_id )->get_status() );
		$this->assertSame( $draft_id, WC()->session->get( 'store_api_draft_order' ) );
		$this->assert_order_payment_lock_is_free( $draft, 'The status check under the lock returned early; the lock must be released.' );
	}

	/**
	 * @testdox The WooPay draft takeover holds the order payment lock while it writes the order.
	 *
	 * A payment request that starts while the takeover saves the order (WC_Abstract_Order::save() fires
	 * woocommerce_before_order_object_save before the data store write) is refused by the lock.
	 */
	public function test_woopay_draft_takeover_holds_the_payment_lock_while_it_writes(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$draft_id = $draft->get_id();
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$claims     = array();
		$pay        = static function ( $order ) use ( $store, $vocabulary, $draft_id, &$claims ): void {
			// The first save of the order is the takeover's; WC_Checkout::create_order() saves it again after the release.
			if ( array() === $claims && $order instanceof \WC_Order && $draft_id === $order->get_id() && OrderStatus::PENDING === $order->get_status() ) {
				$token    = $store->claim( $order, $vocabulary, 'attempt-key-of-another-request', 'checkout' );
				$claims[] = $token;
				if ( null !== $token ) {
					$store->release( $order, $vocabulary, $token );
				}
			}
		};
		add_action( 'woocommerce_before_order_object_save', $pay );

		try {
			$order_id = $this->create_classic_checkout_order();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $pay );
		}

		$this->assertSame( array( null ), $claims, 'The payment that started during the write was refused.' );
		$this->assertSame( $draft_id, $order_id );
		$this->assert_order_payment_lock_is_free( $draft, 'The takeover released the lock.' );
	}

	/**
	 * @testdox The WooPay draft takeover releases the order payment lock when the $step under it throws, and leaves the order and session alone.
	 *
	 * The fresh read fails through a lifecycle service whose read throws (a native class, replaced in the container). The
	 * save fails through woocommerce_before_order_object_save, which WC_Abstract_Order::save() fires before the data store
	 * write; save() catches an Exception and only logs it (abstract-wc-order.php:278-317), so the failure that reaches the
	 * takeover is an Error.
	 *
	 * @testWith ["read"]
	 *           ["save"]
	 *
	 * @param string $step The step that throws.
	 */
	public function test_woopay_draft_takeover_releases_the_payment_lock_when_it_fails( string $step ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$draft_id = $draft->get_id();
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		$fail = static function ( $order ) use ( $draft_id ): void {
			if ( $order instanceof \WC_Order && $draft_id === $order->get_id() ) {
				throw new \Error( 'The save failed.' );
			}
		};
		if ( 'read' === $step ) {
			wc_get_container()->replace(
				OrderPaymentLifecycleService::class,
				new class() extends OrderPaymentLifecycleService {
					/**
					 * Fail the fresh read.
					 *
					 * @param \WC_Order $order Order.
					 * @throws \RuntimeException Always.
					 */
					public function get_fresh_order_from_data_store( \WC_Order $order ): \WC_Order {
						throw new \RuntimeException( 'The read failed.' );
					}
				}
			);
		} else {
			add_action( 'woocommerce_before_order_object_save', $fail );
		}

		$thrown = null;
		try {
			$this->create_classic_checkout_order();
		} catch ( \Throwable $failure ) {
			$thrown = $failure->getMessage();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $fail );
		}

		$this->assertSame( 'read' === $step ? 'The read failed.' : 'The save failed.', $thrown );
		$this->assertSame( OrderStatus::CHECKOUT_DRAFT, wc_get_order( $draft_id )->get_status() );
		$this->assertSame( $draft_id, WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
		$this->assert_order_payment_lock_is_free( $draft, 'The takeover released the lock in finally.' );
	}

	/**
	 * @testdox The WooPay draft order is left alone outside a checkout request and while another order awaits payment.
	 */
	public function test_woopay_draft_order_is_left_alone_outside_its_case(): void {
		$sut   = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );

		// An earlier test in the process can define WOOCOMMERCE_CHECKOUT; the override wins over it.
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', false );
		try {
			$this->assertNull( $sut->maybe_use_store_api_draft_order_id( null ), 'Outside a checkout request.' );
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}
		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );

		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );
		WC()->session->set( 'order_awaiting_payment', 123 );
		try {
			$this->assertNull( $sut->maybe_use_store_api_draft_order_id( null ), 'With an order awaiting payment.' );
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertSame( OrderStatus::CHECKOUT_DRAFT, wc_get_order( $draft->get_id() )->get_status() );
	}

	/**
	 * @testdox A WooPay order credits the AutomateWoo advocate WooPay sends back, and only on WooPay requests.
	 *
	 * Client 11.1.0 class-woopay-session.php:67, :273-289: on a WooPay Store API request the advocate is the
	 * automatewoo_referral_id query parameter, or none; other requests keep AutomateWoo's own value.
	 */
	public function test_woopay_order_credits_the_automatewoo_advocate_from_the_woopay_request(): void {
		$this->register_controller( $this->create_service() );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter; its argument is the advocate ID it found.
		$this->assertSame( 7, apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ), 'A store request keeps AutomateWoo\'s advocate.' );

		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$this->simulate_woopay_store_api_request();
		$_GET['automatewoo_referral_id'] = '42';
		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter.
			$this->assertSame( 42, apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ) );
			unset( $_GET['automatewoo_referral_id'] );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter.
			$this->assertFalse( apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ), 'A WooPay order without a referral credits nobody.' );
		} finally {
			unset( $_GET['automatewoo_referral_id'] );
			$_SERVER['REQUEST_URI'] = $request_uri;
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
	 * @testdox The browser sees the session a WooPay Store API request saved, even with a persistent object cache.
	 *
	 * Client 11.1.0 class-woopay-store-api-session-handler.php:108-125 refreshes the cache entry the browser's
	 * WC_Session_Handler::get_session() reads first.
	 */
	public function test_woopay_store_api_save_reaches_the_browser_session(): void {
		$customer_id = 't_' . wp_generate_password( 30, false );
		$browser     = new \WC_Session_Handler();
		// The browser's handler cached the session before the shopper went to WooPay.
		wp_cache_set( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $customer_id, array( 'coupon' => 'before-woopay' ), WC_SESSION_CACHE_GROUP );
		$sut = $this->create_service();
		$this->register_controller( $sut );
		$this->simulate_woopay_store_api_request();
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( $customer_id );
		$original_session           = WC()->session;
		WC()->session               = new \Automattic\WooCommerce\StoreApi\SessionHandler();
		WC()->session->init();

		try {
			WC()->session->set( 'coupon', 'added-in-woopay' );
			// What the Store API handler's own shutdown callback (priority 20) does, then the WooPay refresh after it.
			WC()->session->save_data();
			$this->assertSame( 21, has_action( 'shutdown', array( $sut, 'refresh_woopay_browser_session_cache' ) ) );
			$sut->refresh_woopay_browser_session_cache();
		} finally {
			remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
			WC()->session = $original_session;
		}

		$this->assertSame( 'added-in-woopay', maybe_unserialize( $browser->get_session( $customer_id ) )['coupon'] );
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
		$this->assertTrue( $sut->validate_appearance_schema( $config['woopayAppearance'] ) );
		$this->assertSame( $theme_background, $config['woopayAppearance']['variables']['colorBackground'] ?? null );
		$this->assertIsArray( $stored, 'The computed appearance is stored.' );
		$this->assertSame( $config['woopayAppearance'], $stored['appearance'] ?? null );
	}

	/**
	 * @testdox On a classic theme with nothing stored, WooPay gets no appearance.
	 */
	public function test_classic_theme_gives_woopay_no_computed_appearance(): void {
		$original_theme = get_stylesheet();
		$sut            = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => 'yes' ),
			array( 'platform_global_theme_support_enabled' => true )
		);

		try {
			switch_theme( 'storefront' );
			$appearance = $sut->get_woopay_appearance();
		} finally {
			switch_theme( $original_theme );
		}

		$this->assertSame( array(), $appearance );
		$this->assertFalse( get_option( 'wcpay_woopay_checkout_appearance' ) );
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
			$request = $sut->get_init_session_request( 'shopper@example.com' );
		} finally {
			switch_theme( $original_theme );
		}

		$this->assertTrue( $config['isWooPayGlobalThemeSupportEnabled'] );
		$this->assertNull( $config['woopayAppearance'] );
		$this->assertNull( $request['appearance'] );
	}

	/**
	 * @testdox A WooPay $0 subscription order needs payment even when WooCommerce Subscriptions says it does not.
	 *
	 * Client 11.1.0 class-woopay-session.php:62, :298-316: on a WooPay Store API request, a $0 order with a subscription in
	 * the cart needs payment; other requests, other totals and carts keep the value.
	 */
	public function test_woopay_zero_total_subscription_order_needs_payment(): void {
		$sut = $this->create_service();
		$this->register_controller( $sut );
		$this->woopay_eligibility->cart_contains_subscription = true;
		$free_order = new \WC_Order();
		$free_order->set_total( 0 );
		$paid_order = new \WC_Order();
		$paid_order->set_total( 10 );

		// phpcs:disable WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core filter WC_Order::needs_payment() applies.
		$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ), 'A store request keeps the value.' );

		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$this->simulate_woopay_store_api_request();
		try {
			$this->assertTrue( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ) );
			$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $paid_order, array() ), 'A paid order keeps the value.' );
			$this->woopay_eligibility->cart_contains_subscription = false;
			$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ), 'A cart without a subscription keeps the value.' );
		} finally {
			$_SERVER['REQUEST_URI'] = $request_uri;
		}
		// phpcs:enable WooCommerce.Commenting.CommentHooks.MissingHookComment
	}

	/**
	 * @testdox A classic checkout places a new order, without a fatal, when the WooPay draft no longer exists.
	 *
	 * Client 11.1.0 calls set_status() on the wc_get_order() result unchecked (class-wc-payments-woopay-direct-checkout.php:71-72).
	 */
	public function test_classic_checkout_places_a_new_order_when_the_woopay_draft_is_gone(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->save();
		$draft_id = $draft->get_id();
		$draft->delete( true );
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertIsInt( $order_id );
		$this->assertNotSame( $draft_id, $order_id );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
		$this->assertSame( $draft_id, WC()->session->get( 'store_api_draft_order' ), 'The session is left alone.' );
	}

	/**
	 * @testdox A classic checkout keeps the order another woocommerce_create_order callback supplied and leaves the WooPay draft alone.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-direct-checkout.php:60: only when no order ID is defined yet.
	 */
	public function test_classic_checkout_keeps_an_order_another_callback_supplied(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$other = wc_create_order();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$supply_order = static fn() => $other->get_id();
		add_filter( 'woocommerce_create_order', $supply_order, 5 );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order( array( 'payment_method' => 'bacs' ) );
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
			remove_filter( 'woocommerce_create_order', $supply_order, 5 );
		}

		$this->assertSame( $other->get_id(), $order_id );
		$this->assertSame( OrderStatus::CHECKOUT_DRAFT, wc_get_order( $draft->get_id() )->get_status() );
		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );
	}

	/**
	 * @testdox With WooPay off, a WooPay request leaves the AutomateWoo advocate and the $0 subscription needs-payment value alone.
	 *
	 * Client 11.1.0 class-woopay-session.php:278-280 and :303-305 check that WooPay is enabled; both callbacks are registered
	 * on every request, so the check is what keeps them out.
	 */
	public function test_woopay_order_filters_pass_through_with_woopay_off(): void {
		$sut = $this->create_service( array( 'platform_checkout' => 'no' ) );
		$this->register_controller( $sut );
		$this->woopay_eligibility->cart_contains_subscription = true;
		$free_order = new \WC_Order();
		$free_order->set_total( 0 );

		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$this->simulate_woopay_store_api_request();
		$_GET['automatewoo_referral_id'] = '42';
		try {
			$this->assertFalse( $this->woopay_eligibility->is_woopay_enabled() );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter; its argument is the advocate ID it found.
			$this->assertSame( 7, apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ) );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core filter WC_Order::needs_payment() applies.
			$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ) );
		} finally {
			unset( $_GET['automatewoo_referral_id'] );
			$_SERVER['REQUEST_URI'] = $request_uri;
		}
	}

	/**
	 * @testdox The WooPay session refresh keeps the browser's cached session when the request did not save through the Store API: $label.
	 *
	 * Client 11.1.0 swaps its handler only for WooPay Store API requests (class-woopay-session.php:79-91), and only that
	 * handler writes the browser's cache entry (class-woopay-store-api-session-handler.php:108-125).
	 *
	 * @testWith ["a WooPay Store API request on the browser's own session handler", "/wp-json/wc/store/v1/checkout", false]
	 *           ["a WooPay request to a route outside the Store API", "/wp-json/wc/v3/orders", true]
	 *
	 * @param string $label                Case label.
	 * @param string $request_uri          Request URI WooPay calls.
	 * @param bool   $store_api_session    Whether the request runs on core's Store API session handler.
	 */
	public function test_woopay_session_refresh_keeps_the_browser_cache_outside_its_case( string $label, string $request_uri, bool $store_api_session ): void {
		unset( $label );
		$browser = new \WC_Session_Handler();
		$sut     = $this->create_service();
		$this->register_controller( $sut );
		$original_uri               = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = $request_uri;
		$original_session           = WC()->session;
		if ( $store_api_session ) {
			$customer_id                = 't_' . wp_generate_password( 30, false );
			$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( $customer_id );
			WC()->session               = new \Automattic\WooCommerce\StoreApi\SessionHandler();
			WC()->session->init();
		} else {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
			$customer_id = (string) WC()->session->get_customer_id();
		}
		// The browser's handler cached the session; nothing in the database backs it.
		wp_cache_set( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $customer_id, array( 'coupon' => 'cached-by-the-browser' ), WC_SESSION_CACHE_GROUP );

		try {
			$sut->refresh_woopay_browser_session_cache();
		} finally {
			remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
			WC()->session           = $original_session;
			$_SERVER['REQUEST_URI'] = $original_uri;
			unset( $_SERVER['HTTP_CART_TOKEN'] );
		}

		$this->assertSame( array( 'coupon' => 'cached-by-the-browser' ), $browser->get_session( $customer_id ) );
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
	 * @testdox Should preserve legacy flat WooPay phone values while accepting the frontend payload shape.
	 */
	public function test_persists_legacy_flat_woopay_phone_session_data(): void {
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
	 * @testdox Should build the preserved WooPay checkout frontend config.
	 */
	public function test_builds_woopay_checkout_frontend_config(): void {
		\Jetpack_Options::update_option( 'id', 12345 );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$sut = $this->create_service();
		$sut->save_woopay_appearance(
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
	 * @testdox Should include the current frontend style extractor schema in the cache version.
	 */
	public function test_frontend_styles_cache_version_includes_current_extractor_schema(): void {
		update_option( 'wcpay_styles_cache_version', 'version-one' );

		$service = new WooPaymentsFrontendStylesService();

		$this->assertSame( 'version-one|appearance-extractor-v4', $service->get_styles_cache_version() );
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
	 * @testdox Should match the extension WooPay button style settings.
	 */
	public function test_uses_extension_woopay_button_style_settings(): void {
		$sut = $this->create_service(
			array(
				'payment_request_button_size'          => 'medium',
				'payment_request_button_height'        => '44',
				'payment_request_button_border_radius' => '',
				'payment_request_button_radius'        => '9',
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertSame( '48', $config['woopayButton']['height'] );
		$this->assertSame( '', $config['woopayButton']['radius'] );
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
	 * @testdox Should store WooPay checkout appearance in the preserved option shape.
	 */
	public function test_stores_appearance_in_preserved_option_shape(): void {
		$sut        = $this->create_service();
		$appearance = $this->get_valid_appearance();
		$font_rules = array(
			array(
				'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
			),
		);

		$sut->save_woopay_appearance( $appearance, $font_rules );

		$stored = get_option( 'wcpay_woopay_checkout_appearance' );
		$this->assertIsArray( $stored );
		$this->assertSame( $appearance, $stored['appearance'] );
		$this->assertSame( $font_rules, $stored['font_rules'] );
		$this->assertNotEmpty( $stored['version'] );
		$this->assertSame( $appearance, $sut->get_woopay_appearance() );
		$this->assertSame( $font_rules, $sut->get_woopay_font_rules() );
	}

	/**
	 * @testdox Should ignore stale WooPay appearance data when the styles cache version changes.
	 */
	public function test_ignores_stale_appearance_when_styles_cache_version_changes(): void {
		$sut        = $this->create_service();
		$appearance = $this->get_valid_appearance();

		update_option( 'wcpay_styles_cache_version', 'version-one' );
		$sut->save_woopay_appearance(
			$appearance,
			array(
				array(
					'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
				),
			)
		);

		update_option( 'wcpay_styles_cache_version', 'version-two' );

		$this->assertSame( array(), $sut->get_woopay_appearance() );
		$this->assertSame( array(), $sut->get_woopay_font_rules() );
		$this->assertTrue(
			$sut->maybe_save_woopay_appearance(
				array(
					'theme'     => 'night',
					'variables' => array(
						'colorText' => '#ffffff',
					),
				)
			)
		);
	}

	/**
	 * @testdox Should ignore appearance stored before the current frontend style extractor schema.
	 */
	public function test_ignores_appearance_stored_before_current_frontend_style_extractor_schema(): void {
		$sut        = $this->create_service();
		$appearance = $this->get_valid_appearance();

		update_option( 'wcpay_styles_cache_version', 'version-one' );
		update_option(
			'wcpay_woopay_checkout_appearance',
			array(
				'appearance' => $appearance,
				'font_rules' => array(),
				'version'    => 'version-one',
			)
		);

		$this->assertSame( array(), $sut->get_woopay_appearance() );
		$this->assertSame( array(), $sut->get_woopay_font_rules() );
	}

	/**
	 * @testdox Should conditionally store shopper appearance only when no appearance exists.
	 */
	public function test_conditionally_stores_shopper_appearance_only_once(): void {
		$sut    = $this->create_service();
		$first  = $this->get_valid_appearance();
		$second = array(
			'theme'     => 'night',
			'variables' => array(
				'colorText' => '#ffffff',
			),
		);

		$this->assertTrue( $sut->maybe_save_woopay_appearance( $first ) );
		$this->assertFalse( $sut->maybe_save_woopay_appearance( $second ) );
		$this->assertSame( $first, $sut->get_woopay_appearance() );
	}

	/**
	 * @testdox Should reject invalid WooPay appearance schema.
	 */
	public function test_rejects_invalid_appearance_schema(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->validate_appearance_schema( array( 'buttonTheme' => 'dark' ) ) );
		$this->assertFalse( $sut->maybe_save_woopay_appearance( array( 'buttonTheme' => 'dark' ) ) );
		$this->assertFalse( get_option( 'wcpay_woopay_checkout_appearance' ) );
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
	 * @testdox A WooPay init error code that WordPress or Jetpack does not set is logged as unknown_error ($_dataName).
	 *
	 * WP_Http::request() hands back whatever a pre_http_request callback returns, and WP_Error keeps any code
	 * (wp-includes/class-wp-error.php add(), get_error_code()), so an extension's error code can be a URL, an email or a token.
	 *
	 * @dataProvider woopay_init_error_codes
	 *
	 * @param string $code     The WP_Error code a pre_http_request callback returns.
	 * @param string $expected The logged error_code.
	 */
	public function test_logs_only_known_woopay_init_error_codes( string $code, string $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->connect_woopay_blog();
		add_filter( 'pre_http_request', static fn() => new \WP_Error( $code, 'Request blocked by an extension.' ) );
		$lines = $this->record_woopayments_log_lines();

		$this->assertSame( array( 'result' => 'failure' ), $this->create_service()->init_woopay_session( array( 'email' => 'shopper@example.com' ) ) );
		$this->assertContains(
			array( 'error', 'HTTP_REQUEST_ERROR: WooPay session init request failed.', array( 'error_code' => $expected ) ),
			$lines->getArrayCopy()
		);
		if ( $code !== $expected ) {
			$this->assertStringNotContainsString( $code, (string) wp_json_encode( $lines->getArrayCopy(), JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * @testdox A failed WooPay init request logs its own cURL error number, and none for a transport message of another form ($_dataName).
	 *
	 * Requests words a transport failure as "cURL error <number>: <curl_error()>" (wp-includes/Requests/src/Transport/Curl.php);
	 * WP_Http::request() sets other messages under the same http_request_failed code (wp-includes/class-wp-http.php), such as
	 * "A valid URL was not provided.".
	 *
	 * @dataProvider woopay_init_transport_failures
	 *
	 * @param string              $message  The WP_Error message.
	 * @param array<string,mixed> $expected The logged context.
	 */
	public function test_logs_the_curl_error_number_of_a_failed_woopay_init( string $message, array $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->connect_woopay_blog();
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', $message ) );
		$lines = $this->record_woopayments_log_lines();

		$this->create_service()->init_woopay_session( array( 'email' => 'shopper@example.com' ) );

		$this->assertContains( array( 'error', 'HTTP_REQUEST_ERROR: WooPay session init request failed.', $expected ), $lines->getArrayCopy() );
	}

	/**
	 * Transport failures of a WooPay init request and the context logged for each.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function woopay_init_transport_failures(): array {
		return array(
			'a host that does not resolve'  => array(
				'cURL error 6: Could not resolve host: pay.woo.com',
				array(
					'error_code' => 'http_request_failed',
					'curl_error' => 6,
				),
			),
			'a timeout'                     => array(
				'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
				array(
					'error_code' => 'http_request_failed',
					'curl_error' => 28,
				),
			),
			'a message that is not cURL\'s' => array(
				'A valid URL was not provided.',
				array( 'error_code' => 'http_request_failed' ),
			),
		);
	}

	/**
	 * Error codes of a failed WooPay init request and what is logged for each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function woopay_init_error_codes(): array {
		return array(
			'a signed URL'                => array( 'https://pay.woo.test/wp-json/platform-checkout/v1/init?token=tok_secret_123&signature=sig_abc', 'unknown_error' ),
			'an email'                    => array( 'other-shopper@example.com', 'unknown_error' ),
			'a token'                     => array( 'tok_secret_123', 'unknown_error' ),
			"WordPress's blocked request" => array( 'http_request_not_executed', 'http_request_not_executed' ),
			"Jetpack's missing token"     => array( 'missing_token', 'missing_token' ),
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
		$request = $sut->get_init_session_request( 'shopper@example.com' );

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
		$request = $sut->get_init_session_request( 'points-shopper@example.com' );

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
	 * @testdox Should leave the resolved user untouched when the request does not carry the WooPay user agent.
	 */
	public function test_determine_current_user_passes_through_without_woopay_user_agent(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should leave the resolved user untouched outside Store API requests.
	 */
	public function test_determine_current_user_passes_through_outside_store_api_requests(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/checkout/';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should leave the resolved user untouched when WooPay is disabled.
	 */
	public function test_determine_current_user_passes_through_when_woopay_disabled(): void {
		$this->simulate_woopay_store_api_request();

		$sut = $this->create_service( array( 'platform_checkout' => 'no' ) );

		$this->assertFalse( $sut->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should die with a 401 when a WooPay Store API request is not signed with the blog token.
	 */
	public function test_determine_current_user_dies_401_when_request_is_not_signed(): void {
		$this->simulate_woopay_store_api_request();

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'WooPay request is not signed correctly.' );

		$this->create_service()->determine_current_user_for_woopay( false );
	}

	/**
	 * @testdox Should detect Store API requests routed through the rest_route query argument.
	 */
	public function test_determine_current_user_covers_rest_route_query_form(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/index.php?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout';
		$_REQUEST['rest_route']     = '/wc/store/v1/checkout';

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'WooPay request is not signed correctly.' );

		try {
			$this->create_service()->determine_current_user_for_woopay( false );
		} finally {
			unset( $_REQUEST['rest_route'] );
		}
	}

	/**
	 * @testdox Should flag the request through wcpay_is_woopay_store_api_request when signed, even without a resolvable cart token.
	 */
	public function test_determine_current_user_flags_woopay_store_api_request_when_signed(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();
		$_SERVER['HTTP_CART_TOKEN'] = 'garbage-token';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertTrue( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should resolve the shopper from an authenticated cart-token session.
	 */
	public function test_determine_current_user_resolves_user_from_authenticated_cart_token(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		$user_id = $this->factory->user->create( array( 'user_email' => 'cart-token-shopper@example.com' ) );
		$this->insert_store_api_session(
			(string) $user_id,
			array(
				'id'    => (string) $user_id,
				'email' => 'cart-token-shopper@example.com',
			)
		);
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( (string) $user_id );

		$this->assertSame( $user_id, $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should resolve a WooPay-verified email to the matching store account when an adapted extension is active.
	 */
	public function test_determine_current_user_resolves_verified_email_user_when_adapted_extension_active(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		$user_id = $this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$this->insert_store_api_session(
			't_guesthash',
			array(
				'id'    => '0',
				'email' => 'verified-shopper@example.com',
			)
		);
		$_SERVER['HTTP_CART_TOKEN']                      = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'verified-shopper@example.com';

		$this->assertSame( $user_id, $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should not resolve a WooPay-verified email without an active adapted extension.
	 */
	public function test_determine_current_user_ignores_verified_email_without_adapted_extension(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		$this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		$this->insert_store_api_session(
			't_guesthash',
			array(
				'id'    => '0',
				'email' => 'verified-shopper@example.com',
			)
		);
		$_SERVER['HTTP_CART_TOKEN']                      = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'verified-shopper@example.com';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should not resolve a WooPay-verified email when $_dataName.
	 *
	 * @dataProvider unmatched_verified_email_provider
	 *
	 * @param string   $session_email The email the cart-token session holds.
	 * @param string   $header_email  The X-WooPay-Verified-Email-Address value.
	 * @param string[] $accounts      Emails of the store accounts that exist.
	 */
	public function test_determine_current_user_ignores_an_unmatched_verified_email( string $session_email, string $header_email, array $accounts ): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		foreach ( $accounts as $account_email ) {
			$this->factory->user->create( array( 'user_email' => $account_email ) );
		}
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$this->insert_store_api_session(
			't_guesthash',
			array(
				'id'    => '0',
				'email' => $session_email,
			)
		);
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		// The header WooPay sends with the email it verified (client 11.1.0 reads it at class-woopay-session.php:872-875).
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $header_email;

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * Verified emails that must not log the WooPay request in.
	 *
	 * @return array<string,array{0:string,1:string,2:string[]}>
	 */
	public function unmatched_verified_email_provider(): array {
		return array(
			'the header names another account than the session' => array( 'shopper-a@example.com', 'shopper-b@example.com', array( 'shopper-a@example.com', 'shopper-b@example.com' ) ),
			'no account owns the header email' => array( 'guest-shopper@example.com', 'guest-shopper@example.com', array( 'shopper-a@example.com' ) ),
		);
	}

	/**
	 * @testdox Should detach the customer id from a verified-email guest order and schedule its restoration.
	 */
	public function test_payment_status_change_detaches_customer_id_for_verified_email_guest_order(): void {
		$this->simulate_woopay_store_api_request();

		$user_id = $this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$_SERVER['HTTP_CART_TOKEN']                      = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'verified-shopper@example.com';

		$order = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'verified-shopper@example.com' );
		$order->save();

		$this->create_service()->woopay_order_payment_status_changed( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 0, $order->get_customer_id() );
		$this->assertSame( (string) $user_id, $order->get_meta( 'woopay_merchant_customer_id' ) );
		$this->assertNotFalse( wp_next_scheduled( 'woopay_restore_order_customer_id', array( $order->get_id() ) ) );
	}

	/**
	 * @testdox Should not detach the customer id when no verified-email header is present.
	 */
	public function test_payment_status_change_leaves_order_untouched_without_verified_email(): void {
		$this->simulate_woopay_store_api_request();

		$user_id = $this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );

		$order = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'verified-shopper@example.com' );
		$order->save();

		$this->create_service()->woopay_order_payment_status_changed( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $user_id, $order->get_customer_id() );
		$this->assertFalse( $order->meta_exists( 'woopay_merchant_customer_id' ) );
	}

	/**
	 * @testdox Should update adapted-extension order data for every WooPay Store API order, verified email or not.
	 */
	public function test_payment_status_change_updates_extension_order_data_without_verified_email(): void {
		$this->simulate_woopay_store_api_request();

		$order = wc_create_order();
		$order->save();

		$adapted_extensions = $this->createMock( WooPaymentsWooPayAdaptedExtensions::class );
		$adapted_extensions->expects( $this->once() )
			->method( 'update_order_extension_data' )
			->with( $order->get_id() );

		$this->create_service( array(), array(), $adapted_extensions )->woopay_order_payment_status_changed( $order->get_id() );
	}

	/**
	 * @testdox Should not touch adapted-extension order data outside a WooPay Store API request.
	 */
	public function test_payment_status_change_skips_extension_order_data_outside_woopay_request(): void {
		$order = wc_create_order();
		$order->save();

		$adapted_extensions = $this->createMock( WooPaymentsWooPayAdaptedExtensions::class );
		$adapted_extensions->expects( $this->never() )->method( 'update_order_extension_data' );

		$this->create_service( array(), array(), $adapted_extensions )->woopay_order_payment_status_changed( $order->get_id() );
	}

	/**
	 * @testdox Should fall back to the WooCommerce customer's billing email when no email is supplied.
	 */
	public function test_init_session_request_falls_back_to_customer_billing_email(): void {
		WC()->customer->set_billing_email( 'billing-fallback@example.com' );

		$request = $this->create_service()->get_init_session_request( null );

		$this->assertSame( 'billing-fallback@example.com', $request['email'] );
	}

	/**
	 * @testdox Should fall back to the logged-in user's email when neither a request email nor a customer email exists.
	 */
	public function test_init_session_request_falls_back_to_logged_in_user_email(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'account-fallback@example.com' ) );
		wp_set_current_user( $user_id );
		WC()->customer->set_billing_email( '' );
		WC()->customer->set_email( '' );

		$request = $this->create_service()->get_init_session_request( null );

		$this->assertSame( 'account-fallback@example.com', $request['email'] );
	}

	/**
	 * @testdox Should prefer the supplied email over every fallback.
	 */
	public function test_init_session_request_prefers_supplied_email(): void {
		WC()->customer->set_billing_email( 'billing-fallback@example.com' );

		$request = $this->create_service()->get_init_session_request( 'supplied@example.com' );

		$this->assertSame( 'supplied@example.com', $request['email'] );
	}

	/**
	 * @testdox Should carry the platform customer id for the current user in the init-session request.
	 */
	public function test_init_session_request_carries_platform_customer_id(): void {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$captured_user_id = null;
		$customer_service = $this->create_recording_customer_service( 'cus_test_123', $captured_user_id );

		$request = $this->create_service( array(), array(), null, null, $customer_service )->get_init_session_request( 'shopper@example.com' );

		$this->assertSame( 'cus_test_123', $request['customer_id'] );
		$this->assertSame( $user_id, $captured_user_id );
	}

	/**
	 * @testdox Should create a guest platform customer for the init-session request when nobody is logged in.
	 */
	public function test_init_session_request_creates_guest_platform_customer(): void {
		$captured_user_id = null;
		$customer_service = $this->create_recording_customer_service( 'cus_guest_456', $captured_user_id );

		$request = $this->create_service( array(), array(), null, null, $customer_service )->get_init_session_request( 'guest@example.com' );

		$this->assertSame( 'cus_guest_456', $request['customer_id'] );
		$this->assertSame( 0, $captured_user_id );
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
		$this->assertSame( $expected, $sut->get_init_session_request( 'shopper@example.com' )['wcpay_version'] );
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
	public function test_email_input_flag_honors_plugin_filter(): void {
		add_filter( 'wcpay_is_woopay_email_input_enabled', '__return_false' );

		$sut = $this->create_service();

		$this->assertFalse( $this->woopay_eligibility->is_woopay_email_input_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEmailInputEnabled'] );
	}

	/**
	 * @testdox Should withhold stored appearance and font rules from the session when global theme support is off.
	 */
	public function test_init_session_request_gates_stored_appearance_on_global_theme_support(): void {
		$sut = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => 'no' ),
			array( 'platform_global_theme_support_enabled' => true )
		);
		$sut->save_woopay_appearance( array( 'theme' => 'night' ), array( array( 'src' => 'https://example.com/font.woff2' ) ) );

		$request = $sut->get_init_session_request( 'shopper@example.com' );

		$this->assertNull( $request['appearance'] );
		$this->assertSame( array(), $request['font_rules'] );
	}

	/**
	 * @testdox Should fall back to stored appearance and font rules when global theme support is on.
	 */
	public function test_init_session_request_uses_stored_appearance_when_global_theme_support_is_on(): void {
		$sut = $this->create_service(
			array( 'is_woopay_global_theme_support_enabled' => 'yes' ),
			array( 'platform_global_theme_support_enabled' => true )
		);
		$sut->save_woopay_appearance( array( 'theme' => 'night' ), array() );

		$request = $sut->get_init_session_request( 'shopper@example.com' );

		$this->assertSame( array( 'theme' => 'night' ), $request['appearance'] );
	}

	/**
	 * @testdox Should record a WooPay checkout fatal as an order note with the error's first line.
	 */
	public function test_woopay_checkout_fatal_is_recorded_as_an_order_note(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$order                      = wc_create_order();

		$sut = $this->create_service();
		$sut->catch_woopay_checkout_errors( $order );
		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_ERROR,
				'message' => "Allowed memory size exhausted\nStack trace: #0",
				'file'    => 'checkout.php',
				'line'    => 42,
			)
		);

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertSame( 'WooPay checkout encountered a fatal error: Allowed memory size exhausted', $notes[0]->content );
	}

	/**
	 * @testdox Should log a WooPay checkout fatal with its type and place, never its message.
	 */
	public function test_woopay_checkout_fatal_log_line_leaves_out_the_message(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$order                      = wc_create_order();
		$logged                     = array();
		$record                     = static function ( $message, $level, $context ) use ( &$logged ) {
			$logged[] = array(
				'message' => (string) $message,
				'level'   => (string) $level,
				'context' => is_array( $context ) ? $context : array(),
			);

			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $record, 10, 3 );

		$sut = $this->create_service();
		$sut->catch_woopay_checkout_errors( $order );
		try {
			// The array error_get_last() returns, with its type, message, file and line keys (PHP manual, error_get_last).
			$sut->maybe_record_woopay_checkout_fatal(
				array(
					'type'    => E_ERROR,
					'message' => "Uncaught Exception: card for shopper@example.com declined\nStack trace: #0",
					'file'    => 'checkout.php',
					'line'    => 42,
				)
			);
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $record, 10 );
		}

		$lines = array_values( array_filter( $logged, static fn( array $line ): bool => 'woopayments-woopay-session' === ( $line['context']['source'] ?? null ) ) );
		$this->assertNotEmpty( $lines );
		$this->assertSame( 'error', $lines[0]['level'] );
		$this->assertSame( 'WooPay checkout fatal error.', $lines[0]['message'] );
		$this->assertSame( E_ERROR, $lines[0]['context']['error_type'] );
		$this->assertSame( 'checkout.php', $lines[0]['context']['file'] );
		$this->assertSame( 42, $lines[0]['context']['line'] );
		$this->assertSame( $order->get_id(), $lines[0]['context']['order_id'] );
		foreach ( $logged as $line ) {
			$this->assertStringNotContainsString( 'shopper@example.com', $line['message'] . wp_json_encode( $line['context'] ) );
		}
	}

	/**
	 * @testdox Should ignore non-fatal errors and non-WooPay requests when recording checkout fatals.
	 */
	public function test_woopay_checkout_fatal_capture_ignores_non_fatals_and_non_woopay_requests(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$order                      = wc_create_order();

		$sut = $this->create_service();
		$sut->catch_woopay_checkout_errors( $order );

		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_WARNING,
				'message' => 'Just a warning',
				'file'    => 'checkout.php',
				'line'    => 7,
			)
		);
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		unset( $_SERVER['HTTP_USER_AGENT'] );
		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'Fatal after UA vanished',
				'file'    => 'checkout.php',
				'line'    => 9,
			)
		);
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should not track checkout orders for fatal capture outside WooPay requests.
	 */
	public function test_woopay_checkout_fatal_capture_requires_a_woopay_request_at_registration(): void {
		$order = wc_create_order();

		$sut = $this->create_service();
		$sut->catch_woopay_checkout_errors( $order );

		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'Fatal without registration',
				'file'    => 'checkout.php',
				'line'    => 3,
			)
		);
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should carry the shopper's Tracks identity in the init-session request.
	 */
	public function test_init_session_request_carries_tracks_user_identity(): void {
		$request = $this->create_service()->get_init_session_request( 'shopper@example.com' );

		$this->assertIsArray( $request['tracks_user_identity'] );
		$this->assertSame( 'anon', $request['tracks_user_identity']['_ut'] );
		$this->assertNotEmpty( $request['tracks_user_identity']['_ui'] );
	}

	/**
	 * @testdox Should run the inbound identity chain on the WooPay session route itself.
	 */
	public function test_determine_current_user_covers_the_woopay_session_route(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/wp-json/payments/woopay/session';

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'WooPay request is not signed correctly.' );

		$this->create_service()->determine_current_user_for_woopay( false );
	}

	/**
	 * @testdox Should ignore Store API routes outside the plugin's WooPay allowlist.
	 */
	public function test_determine_current_user_ignores_store_api_routes_outside_the_allowlist(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/products';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
	}
}
