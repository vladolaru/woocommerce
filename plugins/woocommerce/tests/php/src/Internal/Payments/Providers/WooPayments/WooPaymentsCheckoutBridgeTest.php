<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCheckoutBridge class.
 */
class WooPaymentsCheckoutBridgeTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should expose checkout bootstrap through the standard hook registration contract.
	 */
	public function test_implements_register_hooks_interface(): void {
		$sut = new WooPaymentsCheckoutBridge();

		$this->assertInstanceOf( RegisterHooksInterface::class, $sut );
	}

	/**
	 * @testdox Should bootstrap payment-list wallets when no ordinary WooPayments fields render.
	 */
	public function test_after_checkout_form_bootstraps_payment_list_wallets_without_card_fields(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'payment_request_method_ids'          => array( 'apple_pay', 'google_pay' ),
				'upe_enabled_payment_method_ids'      => array( 'apple_pay', 'google_pay' ),
			)
		);
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$sut->register();
		$this->assertSame( 10, has_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) ) );

		ob_start();
		$sut->handle_woocommerce_after_checkout_form();
		$output = (string) ob_get_clean();

		remove_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
		remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString( 'var wcpay_core_checkout_config = ', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_apple_pay', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_google_pay', $script_data );
	}

	/**
	 * @testdox Should track classic and Blocks checkout page views once with the exact WooPayments contract.
	 */
	public function test_tracks_classic_and_blocks_checkout_page_views_once(): void {
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$recorded_events = array();
		$tracker         = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled', 'record_user_event' ) )
			->getMock();
		$tracker->method( 'is_shopper_tracking_enabled' )->willReturn( true );
		$tracker->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				$recorded_events[] = array( $event_name, $properties );
				return true;
			}
		);

		$sut = new WooPaymentsCheckoutBridge();
		$sut->init(
			$this->create_legacy_runtime_for_bridge(),
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( true ),
			$this->create_frontend_styles_service_for_bridge(),
			$tracker
		);

		try {
			$sut->register();
			$sut->register();
			$this->assertSame( 10, has_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) ) );
			$this->assertSame( 10, has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) ) );
			$sut->record_classic_checkout_page_view();
			$sut->record_blocks_checkout_page_view();
		} finally {
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) );
			remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) );
			remove_action( 'woocommerce_pay_order_before_payment', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		}

		$this->assertSame(
			array(
				array(
					'checkout_page_view',
					array(
						'theme_type'        => 'short_code',
						'woopay_enabled'    => true,
						'record_event_data' => array( 'track_on_all_stores' => true ),
					),
				),
				array(
					'checkout_page_view',
					array(
						'theme_type'        => 'blocks',
						'woopay_enabled'    => true,
						'record_event_data' => array( 'track_on_all_stores' => true ),
					),
				),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should track classic and Store API order placement before payment with exact oracle guards.
	 */
	public function test_tracks_classic_and_store_api_order_placement_before_payment(): void {
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$recorded_events = array();
		$tracker         = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled', 'record_user_event' ) )
			->getMock();
		$tracker->method( 'is_shopper_tracking_enabled' )->willReturn( true );
		$tracker->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				$recorded_events[] = array( $event_name, $properties );
				return true;
			}
		);

		$sut = new WooPaymentsCheckoutBridge();
		$sut->init(
			$this->create_legacy_runtime_for_bridge(),
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$tracker
		);

		$classic_order = wc_create_order();
		$classic_order->set_payment_method( 'woocommerce_payments' );
		$classic_order->set_payment_method_title( 'Card' );
		$classic_order->save();
		$store_api_order = wc_create_order();
		$store_api_order->set_payment_method( 'woocommerce_payments_klarna' );
		$store_api_order->set_payment_method_title( 'Klarna' );
		$store_api_order->save();
		$other_order = wc_create_order();
		$other_order->set_payment_method( 'cod' );
		$other_order->set_payment_method_title( 'Cash on delivery' );
		$other_order->save();

		try {
			$sut->register();
			$sut->register();
			$this->assertSame( 10, has_action( 'woocommerce_checkout_order_processed', array( $sut, 'record_checkout_order_placed' ) ) );
			$this->assertSame( 10, has_action( 'woocommerce_store_api_checkout_order_processed', array( $sut, 'record_checkout_order_placed' ) ) );
			$get_accepted_args = static function ( string $hook_name ) use ( $sut ): int {
				global $wp_filter;
				foreach ( $wp_filter[ $hook_name ]->callbacks[10] ?? array() as $callback ) {
					if ( array( $sut, 'record_checkout_order_placed' ) === $callback['function'] ) {
						return (int) $callback['accepted_args'];
					}
				}

				return 0;
			};
			$this->assertSame( 2, $get_accepted_args( 'woocommerce_checkout_order_processed' ) );
			$this->assertSame( 2, $get_accepted_args( 'woocommerce_store_api_checkout_order_processed' ) );
			$this->assertSame( 'pending', $classic_order->get_status() );

			/**
			 * Fires after a classic checkout order is created and before payment processing.
			 *
			 * @since 2.1.0
			 *
			 * @param int $order_id Order ID.
			 */
			do_action( 'woocommerce_checkout_order_processed', $classic_order->get_id() );
			$classic_order->update_status( 'failed' );

			/**
			 * Fires after a Store API checkout order is created and before payment processing.
			 *
			 * @since 7.2.0
			 *
			 * @param \WC_Order $order Checkout order.
			 */
			do_action( 'woocommerce_store_api_checkout_order_processed', $store_api_order );
			$sut->record_checkout_order_placed( $other_order->get_id() );

			$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
			$sut->record_checkout_order_placed( $classic_order->get_id() );
			$_SERVER['HTTP_USER_AGENT'] = 'woopay';
			$sut->record_checkout_order_placed( $classic_order->get_id() );
		} finally {
			unset( $_SERVER['HTTP_USER_AGENT'] );
			remove_action( 'woocommerce_checkout_order_processed', array( $sut, 'record_checkout_order_placed' ) );
			remove_action( 'woocommerce_store_api_checkout_order_processed', array( $sut, 'record_checkout_order_placed' ) );
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) );
			remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) );
			remove_action( 'woocommerce_pay_order_before_payment', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		}

		$this->assertSame(
			array(
				array(
					'checkout_order_placed',
					array(
						'payment_title'     => 'Card',
						'record_event_data' => array( 'track_on_all_stores' => true ),
					),
				),
				array(
					'checkout_order_placed',
					array(
						'payment_title'     => 'Klarna',
						'record_event_data' => array( 'track_on_all_stores' => true ),
					),
				),
				array(
					'checkout_order_placed',
					array(
						'payment_title'     => 'Card',
						'record_event_data' => array( 'track_on_all_stores' => true ),
					),
				),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should bootstrap payment-list wallets on the classic order-pay form when card fields do not render.
	 */
	public function test_order_pay_before_payment_bootstraps_payment_list_wallets_without_card_fields(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'USD' );
		$order->set_total( '12.34' );
		$order->save();
		wp_set_current_user( $customer_id );
		set_query_var( 'order-pay', $order->get_id() );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'payment_request_method_ids'          => array( 'apple_pay', 'google_pay' ),
				'upe_enabled_payment_method_ids'      => array( 'apple_pay', 'google_pay' ),
			)
		);
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_pay_order_before_payment', array( $sut, 'handle_woocommerce_after_checkout_form' ) ) );
		ob_start();
		$sut->handle_woocommerce_after_checkout_form();
		$output = (string) ob_get_clean();
		remove_action( 'woocommerce_pay_order_before_payment', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
		remove_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
		remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString( 'var wcpay_core_checkout_config = ', $script_data );
		$this->assertStringContainsString( '"isOrderPay":"1"', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_apple_pay', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_google_pay', $script_data );
	}

	/**
	 * @testdox Should not relocalize base checkout config after ordinary card fields render.
	 */
	public function test_after_checkout_form_does_not_duplicate_rendered_card_config(): void {
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$sut->register();
		$this->assertSame( 10, has_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) ) );

		ob_start();
		$sut->render_payment_fields();
		$sut->handle_woocommerce_after_checkout_form();
		ob_get_clean();

		remove_action( 'woocommerce_after_checkout_form', array( $sut, 'handle_woocommerce_after_checkout_form' ) );
		remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( 1, substr_count( $script_data, 'var wcpay_core_checkout_config = ' ) );
	}

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_surface_state();
		// The fraud service falls back to the platform's public fraud-services
		// config when the account payload carries none; unit tests must never
		// reach the real platform.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, string $url ) {
				if ( false !== strpos( $url, 'public-api.wordpress.com' ) ) {
					return new \WP_Error( 'blocked_in_test', 'Platform requests are blocked in unit tests.' );
				}

				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_frontend_surface_state();
		unset( $_GET['change_payment_method'], $GLOBALS['wcpay_test_subscription_ids'] );
		delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );
		remove_all_filters( 'wcpay_payment_fields_js_config' );
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'woocommerce_woopayments_public_fraud_services' );
		wp_dequeue_script( 'wc-woopayments-checkout' );
		wp_deregister_script( 'wc-woopayments-checkout' );
		wp_dequeue_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'wc-woopayments-appearance' );
		wp_dequeue_style( 'wc-woopayments-checkout' );
		wp_deregister_style( 'wc-woopayments-checkout' );
		wp_dequeue_script( 'wcpay-fraud-prevention-token' );
		wp_deregister_script( 'wcpay-fraud-prevention-token' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Reset shopper-surface globals that earlier broad-suite tests may leave behind.
	 */
	private function reset_frontend_surface_state(): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		$this->reset_cart_checkout_page_cache();
		unset( $GLOBALS['post'], $GLOBALS['product'] );
		wp_reset_postdata();
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Reset cached cart/checkout page checks between simulated requests.
	 */
	private function reset_cart_checkout_page_cache(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return;
		}

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * @testdox Should preserve the card checkout config shape and filter it through wcpay_payment_fields_js_config.
	 */
	public function test_get_payment_fields_js_config_preserves_card_checkout_shape(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn(
			array(
				'name'  => 'Test Customer',
				'email' => 'merchant@example.com',
			)
		);
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['filtered'] = 'yes';
				return $config;
			}
		);

		$config = $bridge->get_payment_fields_js_config();

		$this->assertSame( 'pk_test_123', $config['publishableKey'] );
		$this->assertSame( 'acct_123', $config['accountId'] );
		$this->assertSame( 'woocommerce_payments', $config['gatewayId'] );
		$this->assertTrue( $config['testMode'] );
		$this->assertSame( 'yes', $config['filtered'] );
		$this->assertArrayHasKey( 'paymentMethodsConfig', $config );
		$this->assertSame( 'Card', $config['paymentMethodsConfig']['card']['title'] );
		$this->assertSame( 'Card', $config['paymentMethodsConfig']['card']['label'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
		$this->assertSame( 'visa', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['id'] );
		$this->assertSame( 'Visa', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['alt'] );
		$this->assertStringContainsString( '/assets/images/payment-methods-cards/visa.svg', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['src'] );
		$this->assertStringContainsString( '4000 0064 2000 0001', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'js-woopayments-copy-test-number', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'Click to copy the test number to clipboard', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'testing guide', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertArrayHasKey( 'enabledBillingFields', $config );
		$this->assertArrayHasKey( 'currency', $config );
		$this->assertArrayHasKey( 'cartTotal', $config );
		$this->assertFalse( $config['cartContainsSubscription'] );
		$this->assertSame( 'styles-v1', $config['stylesCacheVersion'] );
		$this->assertSame( defined( 'WC_VERSION' ) ? WC_VERSION : '', $config['wcpayVersionNumber'] );
		$this->assertArrayHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayHasKey( 'updateOrderStatusNonce', $config );
		$this->assertSame( 'https://pay.woo.com', $config['woopayHost'] );
		$this->assertSame( '12345', $config['woopayMerchantId'] );
		$this->assertArrayHasKey( 'initWooPayNonce', $config );
		$this->assertArrayNotHasKey( 'woopayInitNonce', $config );
		$this->assertArrayHasKey( 'woopaySessionNonce', $config );
		$this->assertArrayHasKey( 'woopaySignatureNonce', $config );
		$this->assertSame( array( 'encrypted' => 'minimum' ), $config['woopayMinimumSessionData'] );
		$this->assertSame( 'There was a problem processing the payment. Please check your email inbox and refresh the page to try again.', $config['genericErrorMessage'] );
		// With no account fraud-services config and the platform unreachable,
		// the prepared config falls back to the bare stripe default.
		$this->assertSame( array( 'stripe' => array() ), $config['fraudServices'] );
		$this->assertContains( 'products', $config['features'] );
		$this->assertFalse( $config['isPreview'] );
		$this->assertFalse( $config['isShortcodeCheckout'] );
		$this->assertSame( '', $config['accountIdForIntentConfirmation'] );
		$this->assertSame( '', $config['icon'] );
		$this->assertFalse( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertTrue( $config['isWoopayFirstPartyAuthEnabled'] );
		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['isWooPayDirectCheckoutEnabled'] );
		$this->assertFalse( $config['isWooPayGlobalThemeSupportEnabled'] );
		$this->assertFalse( $config['forceNetworkSavedCards'] );
		$this->assertArrayHasKey( 'platformTrackerNonce', $config );
		$this->assertSame(
			array(
				'type'    => 'default',
				'theme'   => 'dark',
				'height'  => '48',
				'radius'  => '4',
				'size'    => 'default',
				'context' => 'checkout',
			),
			$config['woopayButton']
		);
		$this->assertArrayHasKey( 'woopayButtonNonce', $config );
		$this->assertArrayHasKey( 'addToCartNonce', $config );
		$this->assertTrue( $config['shouldShowWooPayButton'] );
		$this->assertSame( 'shopper@example.com', $config['woopaySessionEmail'] );
		$this->assertTrue( $config['woopayIsCountryAvailable'] );
		$this->assertNull( $config['woopayAppearance'] );
		$this->assertSame( array(), $config['woopayFontRules'] );
		$this->assertSame( 'Securely save my information for 1-click checkout', $config['woopaySaveUserLabel'] );
		$this->assertSame( 'Mobile phone number', $config['woopayPhoneLabel'] );
		$this->assertArrayHasKey( 'isShopperTrackingEnabled', $config );
		$this->assertFalse( $config['usesLegacySetupIntentBridge'] );
		$this->assertFalse( $config['usesLegacyOrderStatusBridge'] );
		$this->assertTrue( $config['usesNativeSetupIntentBridge'] );
		$this->assertTrue( $config['usesNativeOrderStatusBridge'] );
	}

	/**
	 * @testdox Should flag a renewal-only cart as containing a subscription, like the extension.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_payment_fields_js_config_flags_renewal_cart_as_subscription(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public cart contract.
		eval( 'namespace { class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return false; } } }' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public renewal detector.
		eval( 'namespace { function wcs_cart_contains_renewal() { return true; } }' );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$this->create_legacy_runtime_for_bridge(),
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$config = $bridge->get_payment_fields_js_config();

		$this->assertTrue( $config['cartContainsSubscription'], 'A renewal cart pays for a subscription; the forced-save signal must fire for it.' );
	}

	/**
	 * @testdox Should expose change-payment state only for an order-pay subscription request without mutating it.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_payment_fields_js_config_exposes_subscription_change_payment_request_state(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its availability marker.
		eval( 'namespace { class WC_Subscriptions_Core_Plugin {} }' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public detector.
		eval( 'namespace { function wcs_is_subscription( $subscription_id ) { return in_array( $subscription_id, $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ); } }' );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$this->create_legacy_runtime_for_bridge(),
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$filter_calls = 0;
		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ) use ( &$filter_calls ): array {
				++$filter_calls;
				$config['testFilterMutation'] = true;
				return $config;
			}
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The test verifies read-only request detection and non-mutation.
		$GLOBALS['wcpay_test_subscription_ids'] = array( '123' );
		$_GET['change_payment_method']          = '123';
		$original_request                       = $_GET;
		$not_order_pay                          = $bridge->get_payment_fields_js_config();
		$this->assertArrayNotHasKey( 'isChangingPayment', $not_order_pay );
		$this->assertTrue( $not_order_pay['testFilterMutation'] );
		$this->assertSame( $original_request, $_GET );

		global $wp;
		$wp->query_vars['order-pay'] = 456;
		unset( $_GET['change_payment_method'] );
		$missing_request = $bridge->get_payment_fields_js_config();
		$this->assertArrayNotHasKey( 'isChangingPayment', $missing_request );
		$this->assertTrue( $missing_request['testFilterMutation'] );

		$_GET['change_payment_method'] = '456';
		$non_subscription_request      = $_GET;
		$non_subscription              = $bridge->get_payment_fields_js_config();
		$this->assertArrayNotHasKey( 'isChangingPayment', $non_subscription );
		$this->assertTrue( $non_subscription['testFilterMutation'] );
		$this->assertSame( $non_subscription_request, $_GET );

		$_GET['change_payment_method'] = '123';
		$subscription_request          = $_GET;
		$changing_payment              = $bridge->get_payment_fields_js_config();
		$this->assertArrayHasKey( 'isChangingPayment', $changing_payment );
		$this->assertTrue( $changing_payment['isChangingPayment'] );
		$this->assertArrayNotHasKey( 'testFilterMutation', $changing_payment );
		$this->assertSame( $subscription_request, $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->assertSame( 3, $filter_calls );
	}

	/**
	 * @testdox Should resolve a subscription payment-method change to the cart context, not the order behind its order-pay URL.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_payment_fields_js_config_change_payment_request_uses_cart_context(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its availability marker.
		eval( 'namespace { class WC_Subscriptions_Core_Plugin {} }' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public detector.
		eval( 'namespace { function wcs_is_subscription( $subscription_id ) { return in_array( $subscription_id, $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ); } }' );

		update_option( 'woocommerce_currency', 'USD' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'EUR' );
		$order->set_total( '12.34' );
		$order->set_billing_country( 'BE' );
		$order->save();
		wp_set_current_user( $customer_id );

		// WooCommerce Subscriptions serves the change form at
		// order-pay/<subscription_id>?change_payment_method=<subscription_id>.
		// The endpoint detector reads `$wp->query_vars` while the context
		// reads `$wp_query`, so the request must exist in both.
		global $wp;
		$wp->query_vars['order-pay'] = $order->get_id();
		set_query_var( 'order-pay', $order->get_id() );
		$GLOBALS['wcpay_test_subscription_ids'] = array( (string) $order->get_id() );
		$_GET['change_payment_method']          = (string) $order->get_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context matching WooCommerce Subscriptions.

		$legacy_runtime = $this->create_legacy_runtime_for_bridge();
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$legacy_runtime,
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$config = $bridge->get_payment_fields_js_config();

		// A payment-method change collects no payment, so the order's 12.34 EUR
		// must not become the element's context: the checkout script derives a
		// payment-mode Payment Element from any positive `cartTotal`, where the
		// WooPayments client plugin serves this surface a setup-mode element.
		$this->assertTrue( $config['isChangingPayment'] );
		$this->assertSame( 0, $config['cartTotal'] );
		$this->assertSame( 'USD', $config['currency'] );
		$this->assertArrayNotHasKey( 'isOrderPay', $config );
		$this->assertArrayNotHasKey( 'orderId', $config );
		// The order still legitimately prefills the element's default billing
		// details - the client plugin does the same for any pay_for_order URL.
		$this->assertSame( 'BE', $config['customerData']['billing_country'] );
	}

	/**
	 * @testdox Should fold enabled Link configuration into the card Payment Element.
	 */
	public function test_get_payment_fields_js_config_folds_link_into_card(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array( 'link' => array() ),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
		);
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertArrayHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( 'link', $config['paymentMethodsConfig']['link']['id'] );
		$this->assertTrue( $config['paymentMethodsConfig']['link']['isReusable'] );
		$this->assertSame( array( 'card', 'link' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should fold enabled Link into the production explicit-card definition path.
	 */
	public function test_get_payment_fields_js_config_folds_link_into_explicit_card_definition(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array( 'link' => array() ),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
		);
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$card_definition = $registry->get( 'card' );
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge(), null, $registry );

		$config = $sut->get_payment_fields_js_config( $card_definition );

		$this->assertArrayHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card', 'link' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should expose country-aware shopper icons for a split payment method.
	 */
	public function test_get_payment_fields_js_config_exposes_country_aware_afterpay_icons(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'US' )
		);
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge(), null, $registry );

		$config          = $sut->get_payment_fields_js_config( $registry->get( 'afterpay_clearpay' ) );
		$afterpay_config = $config['paymentMethodsConfig']['afterpay_clearpay'];

		$this->assertStringEndsWith( '/assets/images/payment-methods/afterpay-cashapp-logo.svg', $afterpay_config['icon'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/afterpay-cashapp-logo-dark.svg', $afterpay_config['darkIcon'] );
	}

	/**
	 * @testdox Should expose enabled payment-list wallets separately from Payment Element methods.
	 */
	public function test_get_payment_fields_js_config_exposes_payment_list_wallets_with_gateway_identity(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'active' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'payment_request_method_ids'          => array( 'apple_pay', 'google_pay' ),
				'upe_enabled_payment_method_ids'      => array( 'card' ),
			)
		);
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $sut->get_payment_fields_js_config();

		$this->assertTrue( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertArrayNotHasKey( 'apple_pay', $config['paymentMethodsConfig'] );
		$this->assertArrayNotHasKey( 'google_pay', $config['paymentMethodsConfig'] );
		$this->assertSame( 'woocommerce_payments_apple_pay', $config['paymentListWalletsConfig']['apple_pay']['gatewayId'] );
		$this->assertSame( 'woocommerce_payments_google_pay', $config['paymentListWalletsConfig']['google_pay']['gatewayId'] );
		$this->assertTrue( $config['paymentListWalletsConfig']['apple_pay']['isExpressCheckout'] );
		$this->assertTrue( $config['paymentListWalletsConfig']['google_pay']['isExpressCheckout'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should not fold Link into card when Link does not support the checkout currency.
	 */
	public function test_get_payment_fields_js_config_excludes_link_for_unsupported_currency(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				'fees'         => array( 'link' => array() ),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
		);
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertArrayNotHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should use one authorized order-pay context for payment configuration and eligibility.
	 */
	public function test_get_payment_fields_js_config_uses_order_pay_context(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'EUR' );
		$order->set_total( '12.34' );
		$order->set_billing_country( 'BE' );
		$order->save();
		wp_set_current_user( $customer_id );
		set_query_var( 'order-pay', $order->get_id() );

		$legacy_runtime = $this->create_legacy_runtime_for_bridge();
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$legacy_runtime,
			$this->create_account_service_for_bridge(
				true,
				array(
					'country'      => 'US',
					'capabilities' => array(
						'card_payments' => 'active',
						'link_payments' => 'active',
					),
					'fees'         => array( 'link' => array() ),
				),
				array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
			),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$config = $bridge->get_payment_fields_js_config();

		$this->assertTrue( $config['isOrderPay'] );
		$this->assertSame( $order->get_id(), $config['orderId'] );
		$this->assertSame( 'EUR', $config['currency'] );
		$this->assertSame( 1234, $config['cartTotal'] );
		$this->assertSame( 'BE', $config['customerData']['billing_country'] );
		$this->assertArrayNotHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should source add-payment-method billing details from the native customer service.
	 */
	public function test_get_payment_fields_js_config_uses_native_customer_data_on_add_payment_method_page(): void {
		$user_id = self::factory()->user->create(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'user_email' => 'ada@example.com',
			)
		);
		update_user_meta( $user_id, 'billing_country', 'RO' );
		wp_set_current_user( $user_id );

		$my_account_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );
		$this->go_to( get_permalink( $my_account_page_id ) );
		$GLOBALS['wp']->query_vars['add-payment-method'] = '';

		$legacy_runtime = $this->create_legacy_runtime_for_bridge();
		$legacy_runtime->expects( $this->never() )->method( 'get_gateway_prepared_customer_data' );
		$account_service  = $this->create_account_service_for_bridge( true );
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $this->createStub( WooPaymentsApiClient::class ), $account_service, new WooPaymentsSessionService() );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$legacy_runtime,
			$account_service,
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge(),
			null,
			null,
			$customer_service
		);

		$config = $bridge->get_payment_fields_js_config();

		$this->assertSame( 'Ada Lovelace', $config['customerData']['name'] );
		$this->assertSame( 'ada@example.com', $config['customerData']['email'] );
		$this->assertSame( 'RO', $config['customerData']['billing_country'] );
	}

	/**
	 * @testdox Should expose card platform checkout config independently from WooPay frontend config.
	 */
	public function test_card_platform_checkout_config_is_independent_from_woopay_frontend_config(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'                    => 'US',
				'platform_checkout_eligible' => true,
			),
			array(
				'force_network_saved_cards' => 'yes',
			)
		);
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertTrue( $config['forceNetworkSavedCards'] );
		$this->assertTrue( $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
	}

	/**
	 * @testdox Should hide the card save-payment checkbox for logged-in WooPay shoppers.
	 */
	public function test_get_payment_fields_js_config_hides_card_save_option_for_logged_in_woopay_shoppers(): void {
		wp_set_current_user( self::factory()->user->create() );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertTrue( $config['isSavedCardsEnabled'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'] );
	}

	/**
	 * @testdox Should hide saved-card controls when saved cards are disabled.
	 */
	public function test_get_payment_fields_js_config_hides_saved_card_controls_when_saved_cards_are_disabled(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'RO' ),
			array( 'saved_cards' => 'no' )
		);
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertFalse( $config['isSavedCardsEnabled'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'] );
	}

	/**
	 * @testdox Should expose Cartes Bancaires card branding for France merchants.
	 */
	public function test_get_payment_fields_js_config_includes_cartes_bancaires_for_france_merchants(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country' => 'FR',
			)
		);
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();
		$icons  = $config['paymentMethodsConfig']['card']['cardBrandIcons'];

		$this->assertSame( 'FR', $config['storeCountry'] );
		$this->assertContains( 'cartes_bancaires', array_column( $icons, 'id' ) );
		$this->assertContains( 'Cartes Bancaires', array_column( $icons, 'alt' ) );
		$this->assertStringContainsString( '/assets/images/woopayments-card-brands/jcb.svg', $icons[4]['src'] );
		$this->assertStringContainsString( '/assets/images/woopayments-card-brands/unionpay.svg', $icons[5]['src'] );
		$this->assertStringContainsString( '/assets/images/payment-methods-cards/cartes_bancaires.svg', $icons[6]['src'] );
	}

	/**
	 * @testdox Should enqueue core-owned checkout assets when rendering payment fields.
	 */
	public function test_payment_fields_enqueues_core_owned_assets_and_preserves_wcpay_config_filter(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['filtered'] = 'yes';
				return $config;
			}
		);

		ob_start();
		$bridge->render_payment_fields();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-core-checkout-form', $output );
		$this->assertStringContainsString( 'wcpay-core-test-mode-instructions', $output );
		$this->assertStringContainsString( '4000 0064 2000 0001', $output );
		$this->assertStringContainsString( 'js-woopayments-copy-test-number', $output );
		$this->assertStringContainsString( 'data-wcpay-config', $output );
		$this->assertStringContainsString( 'filtered', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/js/frontend/woopayments-checkout',
			wp_scripts()->registered['wc-woopayments-checkout']->src
		);
		$this->assertContains( 'wc-woopayments-appearance', wp_scripts()->registered['wc-woopayments-checkout']->deps );
		$this->assertTrue( wp_style_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/css/woopayments-checkout.css',
			wp_styles()->registered['wc-woopayments-checkout']->src
		);
	}

	/**
	 * @testdox Should preserve the appearance dependency when WooCommerce registers frontend scripts before the bridge.
	 */
	public function test_payment_fields_preserves_appearance_dependency_after_frontend_script_registration(): void {
		\WC_Frontend_Scripts::load_scripts();

		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'registered' ) );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();

		ob_start();
		$bridge->render_payment_fields( $payment_method_registry->get( 'ideal' ) );
		ob_end_clean();

		$this->assertTrue( wp_script_is( 'wc-woopayments-appearance', 'registered' ) );
		$this->assertContains( 'wc-woopayments-appearance', wp_scripts()->registered['wc-woopayments-checkout']->deps );
	}

	/**
	 * @testdox Should localize split gateway classic config under a gateway-specific object name.
	 */
	public function test_payment_fields_localizes_split_gateway_config_under_gateway_specific_object_name(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();

		ob_start();
		$bridge->render_payment_fields( $payment_method_registry->get( 'klarna' ) );
		ob_get_clean();

		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertStringContainsString( 'var wcpay_core_checkout_config_woocommerce_payments_klarna = ', $script_data );
		$this->assertStringContainsString( '"gatewayId":"woocommerce_payments_klarna"', $script_data );
		$this->assertStringContainsString( '"paymentMethodTypes":["klarna"]', $script_data );
		$this->assertStringNotContainsString( 'var wcpay_core_checkout_config = ', $script_data );
	}

	/**
	 * @testdox Should include WooPay save-user data in Blocks payment method data.
	 */
	public function test_get_blocks_payment_method_data_includes_woopay_save_user_data(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$data = $bridge->get_blocks_payment_method_data();

		$this->assertTrue( $data['isWooPayEnabled'] );
		$this->assertTrue( $data['PRE_CHECK_SAVE_MY_INFO'] );
		$this->assertSame( array( 'products' ), $data['supports'] );
		$this->assertSame( array( 'products' ), $data['paymentMethodsConfig']['card']['supports'] );
	}

	/**
	 * @testdox Should strip script tags from filter-injected card testing instructions in the Blocks payload.
	 */
	public function test_get_blocks_payment_method_data_sanitizes_filtered_testing_instructions(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['paymentMethodsConfig']['card']['testingInstructions'] = '<script>alert(1)</script><strong>ok</strong>';
				return $config;
			}
		);

		$data                 = $bridge->get_blocks_payment_method_data();
		$testing_instructions = $data['paymentMethodsConfig']['card']['testingInstructions'];

		$this->assertStringNotContainsString( '<script>', $testing_instructions );
		$this->assertStringContainsString( '<strong>ok</strong>', $testing_instructions );
	}

	/**
	 * @testdox Should expose native bridge nonces independently from the removed legacy callback bridge.
	 */
	public function test_get_payment_fields_js_config_exposes_native_bridge_nonces_when_checkout_surface_is_available(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( false );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertArrayHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayHasKey( 'updateOrderStatusNonce', $config );
		$this->assertFalse( $config['usesLegacySetupIntentBridge'] );
		$this->assertFalse( $config['usesLegacyOrderStatusBridge'] );
		$this->assertTrue( $config['usesNativeSetupIntentBridge'] );
		$this->assertTrue( $config['usesNativeOrderStatusBridge'] );
	}

	/**
	 * @testdox Should expose the fraud-prevention token in card checkout config and Blocks payment method data.
	 */
	public function test_get_payment_fields_js_config_includes_fraud_prevention_token_when_enabled(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'fraud-token-123' );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$legacy_runtime,
			$account_service,
			$this->create_woopay_session_service_for_bridge( true ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge(),
			$this->create_fraud_prevention_service( true, $session )
		);

		$config = $bridge->get_payment_fields_js_config();
		$data   = $bridge->get_blocks_payment_method_data();

		$this->assertSame( 'fraud-token-123', $config['fraudPreventionToken'] );
		$this->assertSame( 'fraud-token-123', $data['fraudPreventionToken'] );
	}

	/**
	 * @testdox Should publish the fraud-prevention token on window when rendering classic payment fields.
	 */
	public function test_payment_fields_publish_fraud_prevention_token_inline_script_when_enabled(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'fraud-token-123' );

		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$legacy_runtime->method( 'can_handle_checkout_bridge_callbacks' )->willReturn( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$legacy_runtime,
			$account_service,
			$this->create_woopay_session_service_for_bridge( true ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge(),
			$this->create_fraud_prevention_service( true, $session )
		);

		ob_start();
		$bridge->render_payment_fields();
		ob_get_clean();

		$this->assertTrue( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
		$inline_scripts = wp_scripts()->registered[ WooPaymentsFraudPreventionService::TOKEN_NAME ]->extra['after'] ?? array();
		$this->assertStringContainsString(
			"window.wcpayFraudPreventionToken = 'fraud-token-123';",
			implode( "\n", $inline_scripts )
		);
	}

	/**
	 * @testdox Should hide checkout surface controls when the Core-owned account readiness fails.
	 */
	public function test_get_payment_fields_js_config_hides_native_controls_when_account_is_not_ready(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( false );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertSame( 'pk_test_123', $config['publishableKey'] );
		$this->assertSame( 'acct_123', $config['accountId'] );
		$this->assertFalse( $config['isCoreNativeCheckoutAvailable'] );
		$this->assertArrayNotHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayNotHasKey( 'updateOrderStatusNonce', $config );
		$this->assertArrayNotHasKey( 'initWooPayNonce', $config );
		$this->assertArrayNotHasKey( 'woopaySessionNonce', $config );
		$this->assertArrayNotHasKey( 'woopaySignatureNonce', $config );
		$this->assertArrayNotHasKey( 'woopayMerchantId', $config );
		$this->assertArrayNotHasKey( 'woopayMinimumSessionData', $config );
	}

	/**
	 * @testdox Should preserve WooPay config shape when WooPay is disabled.
	 */
	public function test_get_payment_fields_js_config_preserves_woopay_config_shape_when_woopay_is_disabled(): void {
		$legacy_runtime  = $this->create_legacy_runtime_for_bridge();
		$account_service = $this->create_account_service_for_bridge( true );
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertArrayHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayHasKey( 'updateOrderStatusNonce', $config );
		$this->assertArrayHasKey( 'initWooPayNonce', $config );
		$this->assertArrayHasKey( 'woopaySessionNonce', $config );
		$this->assertArrayHasKey( 'woopaySignatureNonce', $config );
		$this->assertSame( '12345', $config['woopayMerchantId'] );
		$this->assertSame( array(), $config['woopayMinimumSessionData'] );
		$this->assertSame( 'There was a problem processing the payment. Please check your email inbox and refresh the page to try again.', $config['genericErrorMessage'] );
		// With no account fraud-services config and the platform unreachable,
		// the prepared config falls back to the bare stripe default.
		$this->assertSame( array( 'stripe' => array() ), $config['fraudServices'] );
		$this->assertContains( 'products', $config['features'] );
		$this->assertFalse( $config['isPreview'] );
		$this->assertFalse( $config['isShortcodeCheckout'] );
		$this->assertSame( '', $config['accountIdForIntentConfirmation'] );
		$this->assertSame( '', $config['icon'] );
		$this->assertFalse( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertFalse( $config['isWooPayEnabled'] );
		$this->assertFalse( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertFalse( $config['isWoopayFirstPartyAuthEnabled'] );
		$this->assertFalse( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['isWooPayDirectCheckoutEnabled'] );
		$this->assertFalse( $config['isWooPayGlobalThemeSupportEnabled'] );
	}

	/**
	 * @testdox Should expose fraud-services config from the preserved account payload.
	 */
	public function test_get_payment_fields_js_config_uses_account_fraud_services(): void {
		$legacy_runtime = $this->create_legacy_runtime_for_bridge();
		$legacy_runtime->method( 'get_gateway_prepared_customer_data' )->willReturn( array() );
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'        => 'US',
				'fraud_services' => array( 'stripe' => array() ),
			)
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $legacy_runtime, $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$this->inject_fraud_service_for_bridge( $bridge, $account_service );

		$config = $bridge->get_payment_fields_js_config();

		$this->assertSame( array( 'stripe' => array() ), $config['fraudServices'] );
	}

	/**
	 * Inject a fraud service wired to the given (mocked) account service into a bridge.
	 *
	 * The bridge otherwise resolves the fraud service from the container, whose
	 * account service is the real one — the test's mocked account payload would
	 * never reach it.
	 *
	 * @param WooPaymentsCheckoutBridge $bridge          Bridge under test.
	 * @param WooPaymentsAccountService $account_service Account service (usually a mock) the fraud service should read.
	 */
	private function inject_fraud_service_for_bridge( WooPaymentsCheckoutBridge $bridge, WooPaymentsAccountService $account_service ): void {
		$api_client       = new WooPaymentsApiClient();
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $api_client, $account_service, new WooPaymentsSessionService() );

		$fraud_service = new WooPaymentsFraudService();
		$fraud_service->init( $account_service, $customer_service, new WooPaymentsSessionService(), $api_client );

		$property = new \ReflectionProperty( WooPaymentsCheckoutBridge::class, 'fraud_service' );
		$property->setAccessible( true );
		$property->setValue( $bridge, $fraud_service );
	}

	/**
	 * Create a legacy runtime mock for checkout bridge tests.
	 *
	 * @return WooPaymentsLegacyRuntime|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_legacy_runtime_for_bridge() {
		$legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'get_gateway_publishable_key',
					'get_gateway_account_id',
					'get_gateway_prepared_customer_data',
					'get_gateway_upe_enabled_payment_method_ids',
					'can_handle_checkout_bridge_callbacks',
				)
			)
			->getMock();

		$legacy_runtime
			->expects( $this->never() )
			->method( 'get_gateway_publishable_key' );
		$legacy_runtime
			->expects( $this->never() )
			->method( 'get_gateway_account_id' );
		$legacy_runtime
			->method( 'get_gateway_upe_enabled_payment_method_ids' )
			->willReturn( array( 'card' ) );

		return $legacy_runtime;
	}

	/**
	 * Create an account service mock for checkout bridge tests.
	 *
	 * @param bool  $can_process_payments Whether the account can process payments.
	 * @param array $account_data         Account cache data.
	 * @param array $gateway_settings     Gateway settings.
	 * @return WooPaymentsAccountService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_account_service_for_bridge( bool $can_process_payments, array $account_data = array( 'country' => 'RO' ), array $gateway_settings = array() ) {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_publishable_key', 'get_account_id', 'get_cached_account_data', 'get_gateway_setting', 'is_payment_request_method_enabled', 'can_process_payments', 'is_test_mode_enabled' ) )
			->getMock();

		$account_service
			->method( 'get_publishable_key' )
			->willReturn( 'pk_test_123' );
		$account_service
			->method( 'get_account_id' )
			->willReturn( 'acct_123' );
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn( $account_data );
		$account_service
			->method( 'get_gateway_setting' )
			->willReturnCallback(
				static function ( string $key, $fallback = null ) use ( $gateway_settings ) {
					return array_key_exists( $key, $gateway_settings ) ? $gateway_settings[ $key ] : $fallback;
				}
			);
		$account_service
			->method( 'is_payment_request_method_enabled' )
			->willReturnCallback(
				static function ( string $method_id ) use ( $gateway_settings ): bool {
					$enabled_method_ids = $gateway_settings['payment_request_method_ids'] ?? array();

					return is_array( $enabled_method_ids ) && in_array( $method_id, $enabled_method_ids, true );
				}
			);
		$account_service
			->method( 'can_process_payments' )
			->willReturn( $can_process_payments );
		$account_service
			->method( 'is_test_mode_enabled' )
			->willReturn( true );

		return $account_service;
	}

	/**
	 * Create a fraud-prevention service with controlled account eligibility.
	 *
	 * @param bool        $eligible Whether card-testing protection is eligible.
	 * @param \WC_Session $session  WooCommerce session test double.
	 * @return WooPaymentsFraudPreventionService
	 */
	private function create_fraud_prevention_service( bool $eligible, \WC_Session $session ): WooPaymentsFraudPreventionService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn( array( 'card_testing_protection_eligible' => $eligible ) );

		$service = new WooPaymentsFraudPreventionService( $session );
		$service->init( $account_service );

		return $service;
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return \WC_Session
	 */
	private function create_session(): \WC_Session {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}

	/**
	 * Create a WooPay session service mock for checkout bridge tests.
	 *
	 * @param bool $enabled Whether WooPay is enabled.
	 * @return WooPaymentsWooPaySessionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_woopay_session_service_for_bridge( bool $enabled ) {
		$service = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_enabled', 'get_woopay_frontend_config', 'get_save_user_checkout_data' ) )
			->getMock();

		$service->method( 'is_woopay_enabled' )->willReturn( $enabled );
		$service->method( 'get_woopay_frontend_config' )->willReturn(
			array(
				'isWooPayEnabled'                   => $enabled,
				'isWoopayExpressCheckoutEnabled'    => $enabled,
				'isWoopayFirstPartyAuthEnabled'     => $enabled,
				'isWooPayEmailInputEnabled'         => $enabled,
				'isWooPayDirectCheckoutEnabled'     => false,
				'isWooPayGlobalThemeSupportEnabled' => false,
				'forceNetworkSavedCards'            => false,
				'platformTrackerNonce'              => 'platform-tracks-nonce',
				'woopayHost'                        => 'https://pay.woo.com',
				'wcpayVersionNumber'                => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'woopayMerchantId'                  => '12345',
				'initWooPayNonce'                   => 'init-woopay-nonce',
				'woopaySessionNonce'                => 'woopay-session-nonce',
				'woopaySignatureNonce'              => 'woopay-signature-nonce',
				'woopayMinimumSessionData'          => $enabled ? array( 'encrypted' => 'minimum' ) : array(),
				'woopayButton'                      => array(
					'type'    => 'default',
					'theme'   => 'dark',
					'height'  => '48',
					'radius'  => '4',
					'size'    => 'default',
					'context' => 'checkout',
				),
				'woopayButtonNonce'                 => 'woopay-button-nonce',
				'addToCartNonce'                    => 'add-to-cart-nonce',
				'shouldShowWooPayButton'            => true,
				'woopaySessionEmail'                => 'shopper@example.com',
				'woopayIsCountryAvailable'          => true,
				'woopayAppearance'                  => null,
				'woopayFontRules'                   => array(),
				'woopaySaveUserLabel'               => 'Securely save my information for 1-click checkout',
				'woopayPhoneLabel'                  => 'Mobile phone number',
			)
		);
		$service->method( 'get_save_user_checkout_data' )->willReturn( array( 'PRE_CHECK_SAVE_MY_INFO' => true ) );

		return $service;
	}

	/**
	 * Create a frontend styles service mock for checkout bridge tests.
	 *
	 * @return WooPaymentsFrontendStylesService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_frontend_styles_service_for_bridge() {
		$service = $this->getMockBuilder( WooPaymentsFrontendStylesService::class )
			->onlyMethods( array( 'get_styles_cache_version' ) )
			->getMock();

		$service->method( 'get_styles_cache_version' )->willReturn( 'styles-v1' );

		return $service;
	}

	/**
	 * Create a frontend tracking controller mock for checkout bridge tests.
	 *
	 * @return WooPaymentsFrontendTrackingController|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_frontend_tracking_controller_for_bridge() {
		$controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled', 'record_user_event' ) )
			->getMock();

		$controller->method( 'is_shopper_tracking_enabled' )->willReturn( true );

		return $controller;
	}
}
