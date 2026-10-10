<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCheckoutBridge class.
 */
class WooPaymentsCheckoutBridgeTest extends WC_Unit_Test_Case {

	/**
	 * Card gateway support features passed to the config builders where the test does not exercise them.
	 */
	private const CARD_SUPPORTS = array( 'products' );

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
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );

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
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		try {
			$gateway = $this->create_card_gateway_for_bridge( $sut );
			ob_start();
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
			$output = (string) ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringContainsString( 'woocommerce_payments_apple_pay', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_google_pay', $script_data );
		$features = $this->get_localized_core_checkout_config( $script_data )['features'];
		$this->assertSame( $gateway->supports, $features );
		$this->assertContains( 'refunds', $features );
		$this->assertContains( 'tokenization', $features );
	}

	/**
	 * @testdox Should localize no checkout config from the fallback while the WooPayments plugin owns payments.
	 */
	public function test_after_checkout_form_fallback_does_nothing_when_native_does_not_own_payments(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_false' );
		$sut = new WooPaymentsCheckoutBridge();
		$sut->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		try {
			$this->create_card_gateway_for_bridge( $sut );
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_false' );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		}

		$this->assertFalse( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertStringNotContainsString( 'wcpay_core_checkout_config', (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' ) );
	}

	/**
	 * @testdox Should track classic and Blocks checkout page views once with the exact WooPayments contract.
	 */
	public function test_tracks_classic_and_blocks_checkout_page_views_once(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$recorded_events = array();
		$tracker         = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled', 'queue_user_event' ) )
			->getMock();
		$tracker->method( 'is_shopper_tracking_enabled' )->willReturn( true );
		$tracker->method( 'queue_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): void {
				$recorded_events[] = array( $event_name, $properties );
			}
		);

		$sut = new WooPaymentsCheckoutBridge();
		$sut->init(
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
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) );
			remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}

		$this->assertSame(
			array(
				array(
					'checkout_page_view',
					array(
						'theme_type'     => 'short_code',
						'woopay_enabled' => true,
					),
				),
				array(
					'checkout_page_view',
					array(
						'theme_type'     => 'blocks',
						'woopay_enabled' => true,
					),
				),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should track the client 11.1.0 shopper funnel on the cart, product and pay-for-order hooks and on WooPay sign-up.
	 */
	public function test_tracks_shopper_funnel_events_on_their_hooks(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$recorded_events = array();
		$tracker         = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'queue_user_event', 'record_user_event', 'track_proceed_to_checkout_clicks' ) )
			->getMock();
		$tracker->method( 'queue_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties = array() ) use ( &$recorded_events ): void {
				$recorded_events[] = array( 'queued', $event_name, $properties );
			}
		);
		$tracker->method( 'track_proceed_to_checkout_clicks' )->willReturnCallback(
			static function ( callable $is_direct_checkout_enabled ) use ( &$recorded_events ): void {
				$recorded_events[] = array( 'armed', 'proceed_to_checkout_button_click', array( 'woopay_direct_checkout' => $is_direct_checkout_enabled() ) );
			}
		);
		$tracker->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				$recorded_events[] = array( 'recorded', $event_name, $properties );
				return true;
			}
		);
		$sut = new WooPaymentsCheckoutBridge();
		$sut->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( true, true ), $this->create_frontend_styles_service_for_bridge(), $tracker );

		try {
			$sut->register();
			foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form', 'woocommerce_payments_save_user_in_woopay' ) as $hook ) {
				$this->assertSame( 10, has_action( $hook, array( $sut, 'record_shopper_funnel_event' ) ) );
				$sut->record_shopper_funnel_event(); // Outside the hook: no event.
				do_action( $hook ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			}
		} finally {
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}

		$this->assertSame(
			array(
				array( 'queued', 'cart_page_view', array( 'theme_type' => 'short_code' ) ),
				array( 'armed', 'proceed_to_checkout_button_click', array( 'woopay_direct_checkout' => true ) ),
				array( 'queued', 'cart_page_view', array( 'theme_type' => 'blocks' ) ),
				array( 'armed', 'proceed_to_checkout_button_click', array( 'woopay_direct_checkout' => true ) ),
				array( 'queued', 'product_page_view', array( 'theme_type' => 'short_code' ) ),
				array( 'queued', 'pay_for_order_page_view', array() ),
				array( 'recorded', 'woopay_registered', array( 'source' => 'checkout' ) ),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should queue guest page views for the footer script instead of recording them during render, like client 11.1.0.
	 */
	public function test_page_views_render_without_recording_and_queue_for_the_footer_script(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		wp_set_current_user( 0 );
		$recorder_calls = 0;
		$http_calls     = 0;
		$count_recorder = static function ( $properties ) use ( &$recorder_calls ) {
			++$recorder_calls;
			return $properties;
		};
		$count_http     = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'wcpay_tracks_event_properties', $count_recorder );
		add_filter( 'pre_http_request', $count_http );

		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );
		$tracker = new WooPaymentsFrontendTrackingController();
		$tracker->init(
			$arbiter,
			$this->create_account_service_for_bridge(
				true,
				array(
					'country'                    => 'US',
					'platform_checkout_eligible' => true,
				),
				array(
					'enabled'           => 'yes',
					'platform_checkout' => 'yes',
				)
			)
		);
		$sut = new WooPaymentsCheckoutBridge();
		$sut->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $tracker );

		try {
			$sut->register();
			foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form' ) as $hook ) {
				do_action( $hook ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			}
			$sut->record_classic_checkout_page_view();
			$sut->record_blocks_checkout_page_view();

			// No recorder, identity (tk_ai cookie) or pixel during render; headers_sent() is true under the CLI, so the cookie itself is not observable.
			$this->assertSame( 0, $recorder_calls );
			$this->assertSame( 0, $http_calls );
			$this->assertSame( 10, has_action( 'wp_footer', array( $tracker, 'enqueue_frontend_events_script' ) ) );
			$tracker->enqueue_frontend_events_script();
			$this->assertTrue( wp_script_is( 'wc-woopayments-frontend-tracks', 'enqueued' ) );
			$localized = (string) wp_scripts()->get_data( 'wc-woopayments-frontend-tracks', 'data' );
		} finally {
			remove_filter( 'wcpay_tracks_event_properties', $count_recorder );
			remove_filter( 'pre_http_request', $count_http );
			remove_action( 'wp_footer', array( $tracker, 'enqueue_frontend_events_script' ) );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			wp_deregister_script( 'wc-woopayments-frontend-tracks' );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}

		$this->assertSame( 1, preg_match( '/^var wc_woopayments_frontend_tracks_params = (\{.*\});$/s', $localized, $matches ) );
		$params      = json_decode( $matches[1], true );
		$record_data = array(
			'record_event_data' => array(
				'is_admin_event'      => false,
				'track_on_all_stores' => true,
			),
		);
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $params['tracksUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $params['nonce'], 'platform_tracks_nonce' ) );
		$this->assertSame(
			array(
				array(
					'event'      => 'cart_page_view',
					'properties' => array( 'theme_type' => 'short_code' ) + $record_data,
				),
				array(
					'event'      => 'cart_page_view',
					'properties' => array( 'theme_type' => 'blocks' ) + $record_data,
				),
				array(
					'event'      => 'product_page_view',
					'properties' => array( 'theme_type' => 'short_code' ) + $record_data,
				),
				array(
					'event'      => 'pay_for_order_page_view',
					'properties' => $record_data,
				),
				array(
					'event'      => 'checkout_page_view',
					'properties' => array(
						'theme_type'     => 'short_code',
						'woopay_enabled' => false,
					) + $record_data,
				),
				array(
					'event'      => 'checkout_page_view',
					'properties' => array(
						'theme_type'     => 'blocks',
						'woopay_enabled' => false,
					) + $record_data,
				),
			),
			$params['events']
		);
	}

	/**
	 * @testdox Should arm the Blocks cart Proceed to checkout click in the footer script without building the WooPay config, like client 11.1.0 cart/index.js.
	 * @dataProvider direct_checkout_provider
	 *
	 * @param bool $direct_checkout_enabled Whether WooPay direct checkout is enabled.
	 */
	public function test_blocks_cart_arms_proceed_to_checkout_tracking_without_woopay_config( bool $direct_checkout_enabled ): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		wp_set_current_user( 0 );

		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );
		$tracker = new WooPaymentsFrontendTrackingController();
		$tracker->init(
			$arbiter,
			$this->create_account_service_for_bridge(
				true,
				array(
					'country'                    => 'US',
					'platform_checkout_eligible' => true,
				),
				array(
					'enabled'           => 'yes',
					'platform_checkout' => 'yes',
				)
			)
		);
		$woopay = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_direct_checkout_enabled', 'get_woopay_frontend_config' ) )
			->getMock();
		$woopay->expects( $this->once() )->method( 'is_woopay_direct_checkout_enabled' )->willReturn( $direct_checkout_enabled );
		$woopay->expects( $this->never() )->method( 'get_woopay_frontend_config' );
		$sut = new WooPaymentsCheckoutBridge();
		$sut->init( $this->create_account_service_for_bridge( true ), $woopay, $this->create_frontend_styles_service_for_bridge(), $tracker );

		try {
			$sut->register();
			do_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			$tracker->enqueue_frontend_events_script();
			$localized = (string) wp_scripts()->get_data( 'wc-woopayments-frontend-tracks', 'data' );
		} finally {
			remove_action( 'wp_footer', array( $tracker, 'enqueue_frontend_events_script' ) );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			wp_deregister_script( 'wc-woopayments-frontend-tracks' );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}

		$this->assertSame( 1, preg_match( '/^var wc_woopayments_frontend_tracks_params = (\{.*\});$/s', $localized, $matches ) );
		$params = json_decode( $matches[1], true );
		$this->assertSame( array( 'woopayDirectCheckout' => $direct_checkout_enabled ), $params['proceedToCheckout'] );
	}

	/**
	 * Provide WooPay direct checkout states.
	 *
	 * @return array<string,array{bool}>
	 */
	public function direct_checkout_provider(): array {
		return array(
			'direct checkout off' => array( false ),
			'direct checkout on'  => array( true ),
		);
	}

	/**
	 * @testdox Should track classic and Store API order placement before payment with exact oracle guards.
	 */
	public function test_tracks_classic_and_store_api_order_placement_before_payment(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
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
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) );
			remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
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
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_currency( 'USD' );
		$order->set_total( '12.34' );
		$order->save();
		wp_set_current_user( $customer_id );
		set_query_var( 'order-pay', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

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
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		try {
			$gateway = $this->create_card_gateway_for_bridge( $sut );
			ob_start();
			/** This action is documented in templates/checkout/form-pay.php */
			do_action( 'woocommerce_pay_order_before_payment' );
			$output = (string) ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( '', $output );
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertSame( $gateway->supports, $this->get_localized_core_checkout_config( $script_data )['features'] );
		$this->assertStringContainsString( '"isOrderPay":"1"', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_apple_pay', $script_data );
		$this->assertStringContainsString( 'woocommerce_payments_google_pay', $script_data );
	}

	/**
	 * @testdox Should print the classic checkout error region with an assertive live-region role.
	 *
	 * T.3 Task 4 (`plan-task-t3.md`), the classic-error-region owner MISSING row: a screen reader
	 * only announces a payment failure as soon as it appears when the region is an assertive live
	 * region (`role="alert"`); a plain or absent live-region role can leave it unannounced while
	 * focus stays on the payment fields. `render_payment_fields()` prints the region hidden up
	 * front so the checkout script can fill and reveal it once a native confirmation callback
	 * (`update_order_status`/`confirm_intent_for_order`) reports a failure
	 * (`WooPaymentsCheckoutBridge.php:567`). Oracle: WooPayments 11.1.0
	 * `client/checkout/utils/show-error-checkout.js:15` wraps a checkout error in
	 * `<ul class="woocommerce-error" role="alert">`, the same `woocommerce-error` class and
	 * `role="alert"` pairing native's region carries; the assertion below checks only that pairing,
	 * not the surrounding `hidden` markup, which is native-only structure with no client
	 * counterpart to cite.
	 */
	public function test_render_payment_fields_prints_assertive_payment_error_region(): void {
		$account_service = $this->create_account_service_for_bridge( true );
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		ob_start();
		$sut->render_payment_fields( self::CARD_SUPPORTS );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'woocommerce-error', $output );
		$this->assertStringContainsString( 'role="alert"', $output );
	}

	/**
	 * @testdox Should not relocalize base checkout config after ordinary card fields render.
	 */
	public function test_after_checkout_form_does_not_duplicate_rendered_card_config(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );

		$account_service = $this->create_account_service_for_bridge( true );
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		try {
			$gateway = $this->create_card_gateway_for_bridge( $sut );
			ob_start();
			$gateway->form();
			/** This action is documented in templates/checkout/form-checkout.php */
			do_action( 'woocommerce_after_checkout_form', WC()->checkout() );
			ob_get_clean();
		} finally {
			delete_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings' );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		}
		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertSame( 1, substr_count( $script_data, 'var wcpay_core_checkout_config = ' ) );
	}

	/**
	 * Build the card gateway on the given bridge, with saved cards on, so it adds its classic checkout fallback hooks.
	 *
	 * @param WooPaymentsCheckoutBridge $bridge Checkout bridge under test.
	 * @return NativeWooPaymentsGateway
	 */
	private function create_card_gateway_for_bridge( WooPaymentsCheckoutBridge $bridge ): NativeWooPaymentsGateway {
		$this->reset_classic_checkout_fallback_hooks_flag();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		update_option( 'woocommerce_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_settings', array( 'saved_cards' => 'yes' ) );

		$provider = $this->createMock( WooPaymentsProvider::class );
		$provider->method( 'can_process_payments' )->willReturn( true );

		$gateway = new NativeWooPaymentsGateway();
		$gateway->init( $this->createMock( PaymentProcessingService::class ), $provider, $bridge );

		return $gateway;
	}

	/**
	 * Let the next card gateway add its classic checkout fallback hooks again.
	 */
	private function reset_classic_checkout_fallback_hooks_flag(): void {
		$hooks_added = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$hooks_added->setAccessible( true );
		$hooks_added->setValue( null, false );
	}

	/**
	 * Decode the `wcpay_core_checkout_config` object localized on the classic checkout script.
	 *
	 * @param string $script_data Localized script data.
	 * @return array<string,mixed>
	 */
	private function get_localized_core_checkout_config( string $script_data ): array {
		$this->assertSame( 1, preg_match( '/^var wcpay_core_checkout_config = (.+);$/m', $script_data, $matches ) );

		return json_decode( $matches[1], true );
	}

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_surface_state();
		// A post or query left by an earlier test would give the express checkout handler a product or cart context.
		unset( $GLOBALS['post'] );
		$GLOBALS['wp_query']     = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
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
		unset( $_GET['change_payment_method'], $_GET['pay_for_order'], $_GET['key'], $_POST['email'], $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] );
		delete_option( '_wcpay_feature_woopay_express_checkout' );
		remove_all_filters( 'wcpay_woopay_enabled' );
		delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );
		remove_all_filters( 'wcpay_payment_fields_js_config' );
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'woocommerce_woopayments_public_fraud_services' );
		wp_dequeue_script( 'wc-woopayments-checkout' );
		wp_deregister_script( 'wc-woopayments-checkout' );
		wp_dequeue_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'wc-woopayments-fingerprintjs' );
		wp_dequeue_style( 'wc-woopayments-checkout' );
		wp_deregister_style( 'wc-woopayments-checkout' );
		wp_dequeue_script( 'wcpay-fraud-prevention-token' );
		wp_deregister_script( 'wcpay-fraud-prevention-token' );
		wp_dequeue_script( 'woocommerce-tokenization-form' );
		wp_deregister_script( 'woocommerce-tokenization-form' );
		wp_set_current_user( 0 );
		$this->reset_classic_checkout_fallback_hooks_flag();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		parent::tearDown();
	}

	/**
	 * Report the WooCommerce Subscriptions core library as loaded, through the LegacyProxy check the bridge's subscription
	 * policy uses (the mock is reset after every test), and define the shared `wcs_is_subscription()` double.
	 */
	private function report_subscriptions_loaded(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions_Core_Plugin' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
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
	 * @testdox Should keep the unfiltered checkout config when a wcpay_payment_fields_js_config callback returns no array.
	 */
	public function test_get_payment_fields_js_config_ignores_a_non_array_filter_result(): void {

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		add_filter( 'wcpay_payment_fields_js_config', '__return_null' );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'woocommerce_payments', $config['gatewayId'], 'The payment fields must still get their config.' );
	}

	/**
	 * The classic card form names its brand logos and brand popover for screen readers (woopayments-checkout.js:1893,
	 * :1966); the labels come from the config so they are translated.
	 *
	 * @testdox Should send translated labels for the card brand logos and popover.
	 */
	public function test_get_payment_fields_js_config_translates_card_brand_labels(): void {

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$translate = static function ( $translation, $text ) {
			return 'Supported credit card brands' === $text || 'Show all supported credit card brands' === $text ? 'translated: ' . $text : $translation;
		};
		add_filter( 'gettext', $translate, 10, 2 );

		try {
			$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( 'translated: Supported credit card brands', $config['cardBrandPopoverLabel'] ?? null );
		$this->assertSame( 'translated: Show all supported credit card brands', $config['cardBrandLogosLabel'] ?? null );
	}

	/**
	 * @testdox Should preserve the card checkout config shape and filter it through wcpay_payment_fields_js_config.
	 */
	public function test_get_payment_fields_js_config_preserves_card_checkout_shape(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['filtered'] = 'yes';
				return $config;
			}
		);

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

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
		$this->assertStringContainsString( '/assets/images/payment-methods/visa-color.svg', $config['paymentMethodsConfig']['card']['cardBrandIcons'][0]['src'] );
		$this->assertStringContainsString( '4000 0064 2000 0001', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'js-woopayments-copy-test-number', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringNotContainsString( 'aria-label', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'role="status"', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'data-copied-message="Copied to clipboard."', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertStringContainsString( 'testing guide', $config['paymentMethodsConfig']['card']['testingInstructions'] );
		$this->assertArrayHasKey( 'enabledBillingFields', $config );
		$this->assertArrayHasKey( 'currency', $config );
		$this->assertArrayHasKey( 'cartTotal', $config );
		$this->assertFalse( $config['cartContainsSubscription'] );
		$this->assertSame( 'styles-v1', $config['stylesCacheVersion'] );
		$this->assertSame( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion::VERSION, $config['wcpayVersionNumber'] );
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
		$this->assertTrue( $config['isShopperTrackingEnabled'] );
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $config['tracksUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['tracksRestNonce'], 'wp_rest' ) );
	}

	/**
	 * @testdox Should flag a renewal-only cart as containing a subscription, like the extension.
	 */
	public function test_get_payment_fields_js_config_flags_renewal_cart_as_subscription(): void {
		$this->report_subscriptions_loaded();
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] = true;

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['cartContainsSubscription'], 'A renewal cart pays for a subscription; the forced-save signal must fire for it.' );
	}

	/**
	 * @testdox Should expose change-payment state only for an order-pay subscription request without mutating it.
	 */
	public function test_get_payment_fields_js_config_exposes_subscription_change_payment_request_state(): void {
		$this->report_subscriptions_loaded();

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
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
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( 123 );
		$_GET['change_payment_method']                                = '123';
		$original_request = $_GET;
		$not_order_pay    = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayNotHasKey( 'isChangingPayment', $not_order_pay );
		$this->assertTrue( $not_order_pay['testFilterMutation'] );
		$this->assertSame( $original_request, $_GET );

		global $wp;
		$wp->query_vars['order-pay'] = 456;
		unset( $_GET['change_payment_method'] );
		$missing_request = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayNotHasKey( 'isChangingPayment', $missing_request );
		$this->assertTrue( $missing_request['testFilterMutation'] );

		$_GET['change_payment_method'] = '456';
		$non_subscription_request      = $_GET;
		$non_subscription              = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayNotHasKey( 'isChangingPayment', $non_subscription );
		$this->assertTrue( $non_subscription['testFilterMutation'] );
		$this->assertSame( $non_subscription_request, $_GET );

		$_GET['change_payment_method'] = '123';
		$subscription_request          = $_GET;
		$changing_payment              = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayHasKey( 'isChangingPayment', $changing_payment );
		$this->assertTrue( $changing_payment['isChangingPayment'] );
		$this->assertArrayNotHasKey( 'testFilterMutation', $changing_payment );
		$this->assertSame( $subscription_request, $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->assertSame( 3, $filter_calls );
	}

	/**
	 * @testdox Should resolve a subscription payment-method change to the cart context, not the order behind its order-pay URL.
	 */
	public function test_get_payment_fields_js_config_change_payment_request_uses_cart_context(): void {
		$this->report_subscriptions_loaded();

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
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( $order->get_id() );
		$_GET['change_payment_method']                                = (string) $order->get_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context matching WooCommerce Subscriptions.

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$this->create_account_service_for_bridge( true ),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		// A payment-method change collects no payment, so the order's 12.34 EUR
		// must not become the element's context: the checkout script derives a
		// payment-mode Payment Element from any positive `cartTotal`, where the
		// WooPayments client plugin serves this surface a setup-mode element.
		$this->assertTrue( $config['isChangingPayment'] );
		$this->assertSame( 0, $config['cartTotal'] );
		$this->assertSame( 'USD', $config['currency'] );
		$this->assertArrayNotHasKey( 'isOrderPay', $config );
		$this->assertArrayNotHasKey( 'orderId', $config );
		// A change-payment request carries no pay_for_order flag, so the client
		// plugin ships no prefilled billing details there - and neither must we:
		// the order's BE billing address stays out of the element defaults.
		$this->assertSame( array(), $config['customerData'] );
	}

	/**
	 * @testdox Should fold enabled Link configuration into the card Payment Element.
	 */
	public function test_get_payment_fields_js_config_folds_link_into_card(): void {
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
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertArrayHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( 'link', $config['paymentMethodsConfig']['link']['id'] );
		$this->assertTrue( $config['paymentMethodsConfig']['link']['isReusable'] );
		$this->assertSame( array( 'card', 'link' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should offer the card alone when no enabled payment methods are saved, even with Link available to the account.
	 */
	public function test_get_payment_fields_js_config_offers_card_alone_without_saved_enabled_methods(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => 'active',
				),
				// Client 11.1.0 returns the account's fees array (includes/class-wc-payments-account.php:639-641) and reads its keys as
				// payment method IDs (includes/class-wc-payment-gateway-wcpay.php:4867).
				'fees'         => array( 'link' => array() ),
			)
		);
		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( array( 'card' ), array_keys( $config['paymentMethodsConfig'] ) );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * Link folds into card only while `link_payments` is exactly `active` and the account has a `link` fee entry. The account
	 * reports capability statuses keyed by capability (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:4696-4717`)
	 * and fees keyed by payment method ID (`:4867`, `includes/class-wc-payments-account.php:639`).
	 *
	 * @testdox Should not fold Link into card when its capability is $link_status or its fee entry is missing.
	 * @testWith ["inactive", true]
	 *           ["pending", true]
	 *           ["disabled", true]
	 *           ["active", false]
	 *
	 * @param string $link_status Platform status of the link_payments capability.
	 * @param bool   $has_fee     Whether the account lists a link fee.
	 */
	public function test_get_payment_fields_js_config_does_not_fold_link_without_active_capability_and_fee( string $link_status, bool $has_fee ): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array(
					'card_payments' => 'active',
					'link_payments' => $link_status,
				),
				'fees'         => $has_fee ? array( 'link' => array() ) : array( 'card' => array() ),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) )
		);
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertArrayNotHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should fold enabled Link into the production explicit-card definition path.
	 */
	public function test_get_payment_fields_js_config_folds_link_into_explicit_card_definition(): void {
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
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge(), null, $registry );

		$config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS, $card_definition );

		$this->assertArrayHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card', 'link' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should expose country-aware shopper icons for a split payment method.
	 */
	public function test_get_payment_fields_js_config_exposes_country_aware_afterpay_icons(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'US' )
		);
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge(), null, $registry );

		$config          = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS, $registry->get( 'afterpay_clearpay' ) );
		$afterpay_config = $config['paymentMethodsConfig']['afterpay_clearpay'];

		$this->assertStringEndsWith( '/assets/images/payment-methods/afterpay-cashapp-logo.svg', $afterpay_config['icon'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/afterpay-cashapp-logo-dark.svg', $afterpay_config['darkIcon'] );
	}

	/**
	 * Client 11.1.0 `P24Definition.php` titles the method "Przelewy24 (P24)", ships `p24.svg` as every icon and limits shoppers
	 * to Poland; `class-upe-payment-method.php::get_countries()` (:386-397) is what checkout filters billing countries by.
	 *
	 * @testdox Should expose the P24 split gateway to checkout with the client's title, icon and Polish shopper rule.
	 */
	public function test_get_payment_fields_js_config_exposes_p24_like_the_client(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'PL',
				'capabilities' => array(
					'card_payments' => 'active',
					'p24_payments'  => 'active',
				),
			),
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'p24' ) )
		);
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge(), null, $registry );

		$p24_config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS, $registry->get( 'p24' ) )['paymentMethodsConfig']['p24'];

		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'p24', $p24_config['gatewayId'] );
		$this->assertSame( 'Przelewy24 (P24)', $p24_config['title'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/p24-color.svg', $p24_config['icon'] );
		$this->assertStringEndsWith( '/assets/images/payment-methods/p24-color.svg', $p24_config['darkIcon'] );
		$this->assertSame( array( 'PL' ), $p24_config['countries'] );
		$this->assertFalse( $p24_config['isReusable'] );
		$this->assertFalse( $p24_config['isBnpl'] );
	}

	/**
	 * @testdox Should expose enabled payment-list wallets separately from Payment Element methods.
	 */
	public function test_get_payment_fields_js_config_exposes_payment_list_wallets_with_gateway_identity(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
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
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS );

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
	 * Apple Pay and Google Pay run on the card_payments capability; the client offers a method only while it is `active`
	 * (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:908-913`).
	 *
	 * @testdox Should leave the payment-list wallets out while card payments are reported but not active.
	 */
	public function test_get_payment_fields_js_config_omits_payment_list_wallets_without_active_card_payments(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'      => 'US',
				'capabilities' => array( 'card_payments' => 'pending' ),
			),
			array(
				'express_checkout_in_payment_methods' => 'yes',
				'payment_request_method_ids'          => array( 'apple_pay', 'google_pay' ),
				'upe_enabled_payment_method_ids'      => array( 'card' ),
			)
		);
		$sut             = new WooPaymentsCheckoutBridge();
		$sut->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $sut->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isExpressCheckoutInPaymentMethodsEnabled'] );
		$this->assertEmpty( $config['paymentListWalletsConfig'] ?? array() );
	}

	/**
	 * @testdox Should not fold Link into card when Link does not support the checkout currency.
	 */
	public function test_get_payment_fields_js_config_excludes_link_for_unsupported_currency(): void {
		update_option( 'woocommerce_currency', 'EUR' );
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
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertArrayNotHasKey( 'link', $config['paymentMethodsConfig'] );
		$this->assertSame( array( 'card' ), $config['paymentMethodTypes'] );
	}

	/**
	 * @testdox Should publish a guest order's pay context only to a pay link that carries the order key.
	 *
	 * @testWith ["", false]
	 *           ["wrong_key", false]
	 *           ["order_key", true]
	 *
	 * @param string $key           The pay link's key: none, a wrong one, or the order's ("order_key").
	 * @param bool   $expects_order Whether the order's pay context is published.
	 */
	public function test_guest_order_pay_context_needs_the_order_key( string $key, bool $expects_order ): void {
		update_option( 'woocommerce_currency', 'USD' );
		// A cart with its own total, so the fallback is shown to be the cart's.
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => 10 ) )->get_id(), 1 );
		WC()->cart->calculate_totals();
		// No customer: core grants pay_for_order on such an order to every visitor (wc-user-functions.php).
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '12.34' );
		$order->save();
		wp_set_current_user( 0 );
		set_query_var( 'order-pay', $order->get_id() );
		if ( '' !== $key ) {
			$_GET['key'] = 'order_key' === $key ? $order->get_order_key() : $key;
		}

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$this->create_account_service_for_bridge(
				true,
				array(
					'country'      => 'US',
					'capabilities' => array( 'card_payments' => 'active' ),
				)
			),
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge()
		);

		$config     = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$cart_total = (int) round( (float) WC()->cart->get_total( '' ) * 100 );
		WC()->cart->empty_cart();

		$this->assertTrue( current_user_can( 'pay_for_order', $order->get_id() ) );
		if ( $expects_order ) {
			$this->assertSame( $order->get_id(), $config['orderId'] );
			$this->assertSame( 'EUR', $config['currency'] );
			$this->assertSame( 1234, $config['cartTotal'] );
		} else {
			$this->assertArrayNotHasKey( 'orderId', $config );
			$this->assertArrayNotHasKey( 'isOrderPay', $config );
			$this->assertSame( 'USD', $config['currency'] );
			$this->assertNotSame( 1234, $config['cartTotal'] );
			$this->assertSame( $cart_total, $config['cartTotal'] );
		}
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
		$_GET['key'] = $order->get_order_key();

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
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

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

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

		$account_service  = $this->create_account_service_for_bridge( true );
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $this->createStub( WooPaymentsApiClient::class ), $account_service, new WooPaymentsSessionService() );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$account_service,
			$this->create_woopay_session_service_for_bridge( false ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge(),
			null,
			null,
			$customer_service
		);

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( 'Ada Lovelace', $config['customerData']['name'] );
		$this->assertSame( 'ada@example.com', $config['customerData']['email'] );
		$this->assertSame( 'RO', $config['customerData']['billing_country'] );
	}

	/**
	 * @testdox Should expose card platform checkout config independently from WooPay frontend config.
	 */
	public function test_card_platform_checkout_config_is_independent_from_woopay_frontend_config(): void {
		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'                    => 'US',
				'platform_checkout_eligible' => true,
			)
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['forceNetworkSavedCards'] );
		$this->assertTrue( $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
	}

	/**
	 * @testdox Should merge direct checkout eligibility from the WooPay session service.
	 */
	public function test_get_payment_fields_js_config_merges_direct_checkout_eligibility(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( true, true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isWooPayDirectCheckoutEnabled'] );
	}

	/**
	 * @testdox The card config forces network saved cards exactly when the WooPay session service's platform predicate holds ($platform).
	 *
	 * Client 11.1.0 has one should_use_stripe_platform_on_checkout_page() predicate for the card config and the WooPay
	 * config (class-wc-payments-checkout.php:194, :599); the WooPay session service owns it and its own tests cover its cases.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $platform Whether the session service says checkout uses the platform account.
	 */
	public function test_card_config_reads_the_session_service_platform_predicate( bool $platform ): void {

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( false, false, $platform ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( $platform, $bridge->should_use_stripe_platform_on_checkout_page() );
		$this->assertSame( $platform, $config['forceNetworkSavedCards'] );
		$this->assertSame( $platform, $config['paymentMethodsConfig']['card']['forceNetworkSavedCards'] );
	}

	/**
	 * @testdox Should hide the card save-payment checkbox for logged-in WooPay shoppers.
	 */
	public function test_get_payment_fields_js_config_hides_card_save_option_for_logged_in_woopay_shoppers(): void {
		wp_set_current_user( self::factory()->user->create() );

		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isSavedCardsEnabled'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'] );
	}

	/**
	 * @testdox Should keep the save option for a reusable non-card method when WooPay hides it for cards.
	 *
	 * Source: client 11.1.0 class-wc-payments-checkout.php:610-620 applies the logged-in WooPay guard to the card method only;
	 * other reusable methods (Link carries TOKENIZATION in LinkDefinition.php) keep the saved-cards rule.
	 */
	public function test_get_payment_fields_js_config_keeps_non_card_save_option_for_logged_in_woopay_shoppers(): void {
		wp_set_current_user( self::factory()->user->create() );

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
			array(
				'saved_cards'                    => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['paymentMethodsConfig']['link']['isReusable'] );
		$this->assertTrue( $config['paymentMethodsConfig']['link']['showSaveOption'], 'The WooPay guard must not hide the save option for a reusable non-card method.' );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'], 'Logged-in WooPay shoppers must not see the card save option.' );
	}

	/**
	 * @testdox Should hide saved-card controls when saved cards are disabled.
	 */
	public function test_get_payment_fields_js_config_hides_saved_card_controls_when_saved_cards_are_disabled(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'RO' ),
			array( 'saved_cards' => 'no' )
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertFalse( $config['isSavedCardsEnabled'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'] );
	}

	/**
	 * @testdox Should offer the card save option in the config once saved cards are enabled.
	 *
	 * Client 11.1.0 shows the save option once a payment method is reusable, saved cards are
	 * enabled for the gateway, and the cart carries no subscription item
	 * (class-wc-payments-checkout.php:579, :604-619).
	 */
	public function test_get_payment_fields_js_config_shows_card_save_option_when_saved_cards_enabled(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'RO' ),
			array( 'saved_cards' => 'yes' )
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isSavedCardsEnabled'] );
		$this->assertTrue( $config['paymentMethodsConfig']['card']['showSaveOption'] );
	}

	/**
	 * @testdox Should build no payment methods for a stored empty list of enabled methods (client 11.1.0 `get_enabled_payment_method_config()`, `class-wc-payments-checkout.php:291-320`, builds them from the enabled list).
	 */
	public function test_get_payment_fields_js_config_builds_no_methods_for_an_empty_enabled_list(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'RO' ),
			array(
				'saved_cards'                    => 'yes',
				'upe_enabled_payment_method_ids' => array(),
			)
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertSame( array(), $config['paymentMethodsConfig'] );
	}

	/**
	 * @testdox Should hide the card save option on a renewal-only cart (client 11.1.0 `class-wc-payments-checkout.php:616`).
	 *
	 * The client shows the save option only when the cart has no subscription item, and its is_subscription_item_in_cart()
	 * counts a renewal (trait-wc-payments-subscriptions-utilities.php:96-101).
	 */
	public function test_get_payment_fields_js_config_hides_card_save_option_on_a_renewal_only_cart(): void {
		$this->report_subscriptions_loaded();
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] = true;
		$account_service = $this->create_account_service_for_bridge(
			true,
			array( 'country' => 'RO' ),
			array( 'saved_cards' => 'yes' )
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertTrue( $config['isSavedCardsEnabled'] );
		$this->assertFalse( $config['paymentMethodsConfig']['card']['showSaveOption'] );
	}

	/**
	 * @testdox Should expose Cartes Bancaires card branding for France merchants.
	 */
	public function test_get_payment_fields_js_config_includes_cartes_bancaires_for_france_merchants(): void {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country' => 'FR',
			)
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$icons  = $config['paymentMethodsConfig']['card']['cardBrandIcons'];

		$this->assertSame( 'FR', $config['storeCountry'] );
		$this->assertContains( 'cartes_bancaires', array_column( $icons, 'id' ) );
		$this->assertContains( 'Cartes Bancaires', array_column( $icons, 'alt' ) );
		$this->assertStringContainsString( '/assets/images/payment-methods/jcb-color.svg', $icons[4]['src'] );
		$this->assertStringContainsString( '/assets/images/payment-methods/unionpay-color.svg', $icons[5]['src'] );
		$this->assertStringContainsString( '/assets/images/payment-methods/cartes_bancaires-color.svg', $icons[6]['src'] );
	}

	/**
	 * @testdox Should enqueue core-owned checkout assets when rendering payment fields.
	 */
	public function test_payment_fields_enqueues_core_owned_assets_and_preserves_wcpay_config_filter(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['filtered'] = 'yes';
				return $config;
			}
		);

		ob_start();
		$bridge->render_payment_fields( self::CARD_SUPPORTS );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-core-checkout-form', $output );
		// Core's tokenization-form.js hides `.wc-payment-form` while a saved method is selected, so it must wrap the card element.
		// The wrapper is the client's fieldset (client includes/class-wc-payments-checkout.php), inline padding included.
		$this->assertStringContainsString( '<fieldset style="padding: 7px" class="wc-payment-form"><div id="wcpay-core-payment-element" class="wcpay-core-payment-element wcpay-upe-element" data-payment-method-type="card"></div></fieldset>', $output );
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
	 * @testdox Should print the test-mode instructions above the saved payment methods, inside the payment form, as client 11.1.0 does.
	 */
	public function test_render_payment_fields_prints_test_mode_instructions_above_saved_payment_methods(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		ob_start();
		$bridge->render_payment_fields(
			self::CARD_SUPPORTS,
			null,
			static function (): void {
				echo '<ul class="woocommerce-SavedPaymentMethods"></ul>';
			}
		);
		$output = (string) ob_get_clean();

		// Client 11.1.0 includes/class-wc-payments-checkout.php:462-502: the form wrapper opens, then the test-mode
		// instructions, then the saved payment methods, then the fieldset with the card element.
		$wrapper      = strpos( $output, 'id="wcpay-core-checkout-form"' );
		$instructions = strpos( $output, 'wcpay-core-test-mode-instructions' );
		$saved        = strpos( $output, 'woocommerce-SavedPaymentMethods' );
		$fieldset     = strpos( $output, '<fieldset style="padding: 7px" class="wc-payment-form">' );

		$this->assertIsInt( $wrapper );
		$this->assertIsInt( $instructions );
		$this->assertIsInt( $saved );
		$this->assertIsInt( $fieldset );
		$this->assertLessThan( $instructions, $wrapper );
		$this->assertLessThan( $saved, $instructions, 'The test-mode instructions print above the saved payment methods.' );
		$this->assertLessThan( $fieldset, $saved, 'The saved payment methods print above the card element.' );
	}

	/**
	 * @testdox Should make core's tokenization-form.js a dependency of the classic checkout script when the card gateway supports tokenization, as client 11.1.0 does.
	 */
	public function test_classic_script_depends_on_tokenization_form_when_tokenization_is_supported(): void {
		wp_deregister_script( 'woocommerce-tokenization-form' );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		ob_start();
		$bridge->render_payment_fields( array( 'products', 'tokenization' ) );
		ob_end_clean();

		// Client 11.1.0 includes/class-wc-payments-checkout.php:130-131: WordPress then prints tokenization-form.js first,
		// so its listener is bound before the checkout script mounts the card element.
		$this->assertContains( 'woocommerce-tokenization-form', wp_scripts()->registered['wc-woopayments-checkout']->deps );
		// A missing dependency would make WordPress drop the checkout script, so the handle is registered with core's params.
		$this->assertTrue( wp_script_is( 'woocommerce-tokenization-form', 'registered' ) );
		$this->assertStringContainsString( '/assets/js/frontend/tokenization-form', wp_scripts()->registered['woocommerce-tokenization-form']->src );
		$this->assertStringContainsString( 'wc_tokenization_form_params', (string) wp_scripts()->get_data( 'woocommerce-tokenization-form', 'data' ) );
	}

	/**
	 * @testdox Should not load core's tokenization-form.js with the classic checkout script when the card gateway does not support tokenization.
	 */
	public function test_classic_script_does_not_depend_on_tokenization_form_without_tokenization(): void {
		wp_deregister_script( 'woocommerce-tokenization-form' );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $this->create_account_service_for_bridge( true ), $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		ob_start();
		$bridge->render_payment_fields( self::CARD_SUPPORTS );
		ob_end_clean();

		$this->assertNotContains( 'woocommerce-tokenization-form', wp_scripts()->registered['wc-woopayments-checkout']->deps );
		$this->assertFalse( wp_script_is( 'woocommerce-tokenization-form', 'registered' ) );
	}

	/**
	 * @testdox Should register the classic checkout script with its full dependencies when WooCommerce registers frontend scripts first, as on classic themes.
	 */
	public function test_payment_fields_registers_full_checkout_script_after_frontend_script_registration(): void {
		\WC_Frontend_Scripts::load_scripts();

		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();

		ob_start();
		$bridge->render_payment_fields( self::CARD_SUPPORTS, $payment_method_registry->get( 'ideal' ) );
		ob_end_clean();

		$checkout_script = wp_scripts()->registered['wc-woopayments-checkout'];
		$this->assertTrue( wp_script_is( 'wc-woopayments-checkout', 'enqueued' ) );
		$this->assertContains( 'wc-woopayments-fingerprintjs', $checkout_script->deps, 'Without FingerprintJS the classic checkout posts an empty device fingerprint.' );
		$this->assertContains( 'stripe', $checkout_script->deps );
		$this->assertContains( 'wc-woopayments-appearance', $checkout_script->deps );
		$this->assertContains( 'wc-checkout', $checkout_script->deps );
		$this->assertStringStartsWith( WC()->plugin_url() . '/assets/js/frontend/woopayments-checkout', $checkout_script->src );
		$this->assertSame( 1, wp_scripts()->get_data( 'wc-woopayments-checkout', 'group' ), 'The checkout script loads in the footer.' );
		$this->assertFalse( wp_scripts()->get_data( 'wc-woopayments-checkout', 'strategy' ) );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		$this->assertTrue( wp_script_is( 'wc-woopayments-fingerprintjs', 'registered' ) );
		$this->assertTrue( wp_script_is( 'wc-woopayments-appearance', 'registered' ) );
	}

	/**
	 * @testdox Should localize split gateway classic config under a gateway-specific object name.
	 */
	public function test_payment_fields_localizes_split_gateway_config_under_gateway_specific_object_name(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$payment_method_registry = new WooPaymentsPaymentMethodRegistry();

		ob_start();
		$bridge->render_payment_fields( self::CARD_SUPPORTS, $payment_method_registry->get( 'klarna' ) );
		ob_get_clean();

		$script_data = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );

		$this->assertStringContainsString( 'var wcpay_core_checkout_config_woocommerce_payments_klarna = ', $script_data );
		$this->assertStringContainsString( '"gatewayId":"woocommerce_payments_klarna"', $script_data );
		$this->assertStringContainsString( '"paymentMethodTypes":["klarna"]', $script_data );
		$this->assertStringNotContainsString( 'var wcpay_core_checkout_config = ', $script_data );
	}

	/**
	 * @testdox Split gateways sharing one holder build the gateway-independent Blocks config once, and still get their own gateway keys and filter pass.
	 */
	public function test_split_gateway_blocks_data_builds_the_shared_config_once(): void {
		$account_service = $this->create_account_service_for_bridge( true );
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$registry = new WooPaymentsPaymentMethodRegistry();
		$builds   = 0;
		$filtered = 0;
		$count    = static function ( $value ) use ( &$builds ) {
			++$builds;
			return $value;
		};
		$filter   = static function ( $config ) use ( &$filtered ) {
			++$filtered;
			return $config;
		};
		add_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
		add_filter( 'wcpay_payment_fields_js_config', $filter );

		try {
			$shared   = new \ArrayObject();
			$gateways = array();
			foreach ( array( 'card', 'klarna', 'affirm' ) as $payment_method_id ) {
				$gateways[] = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS, $registry->get( $payment_method_id ), $shared )['gatewayId'];
			}
		} finally {
			remove_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
			remove_filter( 'wcpay_payment_fields_js_config', $filter );
		}

		$this->assertSame( 1, $builds, 'Client 11.1.0 builds the payment fields config once for its single Blocks method.' );
		$this->assertSame( 3, $filtered, 'Each gateway config still passes the wcpay_payment_fields_js_config filter.' );
		$this->assertSame( array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_affirm' ), $gateways );
	}

	/**
	 * @testdox On the $_dataName the classic payment list builds the gateway-independent config once per request, and again when $changed changes.
	 *
	 * Client 11.1.0 builds the classic config once per request: payment_fields() builds it only while the checkout script
	 * is not yet enqueued (`includes/class-wc-payments-checkout.php:409-424`). Native shares one base between the
	 * gateways in the same way, with no hook window, and builds it again only when an input of the base changes.
	 *
	 * @testWith ["checkout page", "the cart total"]
	 *           ["order-pay page", "the order"]
	 *           ["update_order_review refresh", "the shopper"]
	 *           ["checkout page", "the card gateway supports"]
	 *
	 * @param string $surface Where the payment list renders.
	 * @param string $changed Which input of the config base changes before the last render.
	 */
	public function test_classic_payment_list_builds_the_shared_config_once( string $surface, string $changed ): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$account_service = $this->create_account_service_for_bridge( true );
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$registry = new WooPaymentsPaymentMethodRegistry();
		$builds   = 0;
		$filtered = 0;
		$count    = static function ( $value ) use ( &$builds ) {
			++$builds;
			return $value;
		};
		$filter   = static function ( $config ) use ( &$filtered ) {
			++$filtered;
			return $config;
		};
		$render   = function ( array $supports, string $payment_method_id = 'card' ) use ( $bridge, $registry ): array {
			ob_start();
			$bridge->render_payment_fields( $supports, $registry->get( $payment_method_id ) );
			$this->assertSame( 1, preg_match( '/data-wcpay-config="([^"]*)"/', (string) ob_get_clean(), $matches ) );

			return json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
		};
		add_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
		add_filter( 'wcpay_payment_fields_js_config', $filter );

		$gateways = array();
		try {
			$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
			wp_set_current_user( $customer_id );
			if ( 'order-pay page' === $surface ) {
				$order = wc_create_order( array( 'customer_id' => $customer_id ) );
				$order->set_total( '12.34' );
				$order->save();
				set_query_var( 'order-pay', $order->get_id() );
				$_GET['key'] = $order->get_order_key();
			} else {
				if ( 'update_order_review refresh' === $surface ) {
					add_filter( 'wp_doing_ajax', '__return_true' );
				}
				WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
				WC()->cart->calculate_totals();
			}
			$bridge->register();

			foreach ( array( 'card', 'klarna', 'affirm' ) as $payment_method_id ) {
				$gateways[] = $render( self::CARD_SUPPORTS, $payment_method_id )['gatewayId'] ?? '';
			}
			$builds_in_list   = $builds;
			$before           = $render( self::CARD_SUPPORTS );
			$builds_unchanged = $builds;

			$supports = self::CARD_SUPPORTS;
			switch ( $changed ) {
				case 'the cart total':
					WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
					WC()->cart->calculate_totals();
					$field = 'cartTotal';
					break;
				case 'the order':
					$other_order = wc_create_order( array( 'customer_id' => $customer_id ) );
					$other_order->set_total( '56.78' );
					$other_order->save();
					set_query_var( 'order-pay', $other_order->get_id() );
					$_GET['key'] = $other_order->get_order_key();
					$field       = 'orderId';
					break;
				case 'the shopper':
					wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
					$field = 'createSetupIntentNonce';
					break;
				default:
					$supports = array_merge( self::CARD_SUPPORTS, array( 'tokenization' ) );
					$field    = 'features';
			}
			$after = $render( $supports );
		} finally {
			remove_filter( 'wc_payments_account_id_for_intent_confirmation', $count );
			remove_filter( 'wcpay_payment_fields_js_config', $filter );
			remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			set_query_var( 'order-pay', '' );
			WC()->cart->empty_cart();
		}

		$this->assertSame( 1, $builds_in_list, 'The payment list builds the shared config once.' );
		$this->assertSame( 1, $builds_unchanged, 'A later render in the same request with the same inputs reuses it.' );
		$this->assertSame( 2, $builds, 'A render after ' . $changed . ' changed builds fresh config.' );
		$this->assertNotSame( $before[ $field ] ?? null, $after[ $field ] ?? null, 'The fresh config carries the changed input.' );
		$this->assertSame( 5, $filtered, 'Each gateway config still passes the wcpay_payment_fields_js_config filter.' );
		$this->assertSame( array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_affirm' ), $gateways );
		$localized = (string) wp_scripts()->get_data( 'wc-woopayments-checkout', 'data' );
		$this->assertStringContainsString( 'var wcpay_core_checkout_config = ', $localized, 'The card gateway config is localized under the base object.' );
		$this->assertStringNotContainsString( 'var wcpay_core_checkout_config_woocommerce_payments =', $localized, 'The checkout script reads the card config from the base object only.' );
	}

	/**
	 * @testdox Should include WooPay save-user data in Blocks payment method data.
	 */
	public function test_get_blocks_payment_method_data_includes_woopay_save_user_data(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$data = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isWooPayEnabled'] );
		$this->assertTrue( $data['PRE_CHECK_SAVE_MY_INFO'] );
		$this->assertSame( array( 'products' ), $data['supports'] );
	}

	/**
	 * @testdox Should send the card gateway's own supports as features and Blocks supports, with no per-method copy.
	 *
	 * Client 11.1.0 sends the gateway's `$supports` as `features` (`class-wc-payments-checkout.php:193`), so values the
	 * gateway declares reach the checkout and values a filter removed stay out.
	 */
	public function test_config_features_are_the_supports_passed_in(): void {
		$account_service = $this->create_account_service_for_bridge( true );
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$registry = new WooPaymentsPaymentMethodRegistry();

		// An extension removed `products` from the gateway's supports, leaving a gap in the keys.
		$supports = array( 'products', 'refunds', 'tokenization', 'add_payment_method' );
		unset( $supports[0] );
		$expected = array( 'refunds', 'tokenization', 'add_payment_method' );

		$classic = $bridge->get_payment_fields_js_config( $supports );
		$blocks  = $bridge->get_blocks_payment_method_data( $supports );
		$split   = $bridge->get_blocks_payment_method_data( $supports, $registry->get( 'klarna' ) );

		$this->assertSame( $expected, $classic['features'] );
		$this->assertSame( $expected, $blocks['features'] );
		$this->assertSame( $expected, $blocks['supports'] );
		$this->assertSame( $expected, $split['features'] );
		$this->assertSame( $expected, $split['supports'] );
		$this->assertArrayNotHasKey( 'supports', $classic['paymentMethodsConfig']['card'] );
		$this->assertArrayNotHasKey( 'supports', $split['paymentMethodsConfig']['klarna'] );
	}

	/**
	 * @testdox Should expose the WooPay express-method gate flags in Blocks payment method data.
	 *
	 * The Blocks bundle only registers WooPay's express payment method once
	 * `isWooPayEnabled` and `shouldShowWooPayButton` both come back true (client
	 * client/checkout/blocks/index.js:123-134).
	 */
	public function test_get_blocks_payment_method_data_exposes_woopay_express_gate_flags(): void {
		$account_service = $this->create_account_service_for_bridge( true, self::EXPRESS_ACCOUNT_DATA, self::EXPRESS_GATEWAY_SETTINGS );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$data = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isWooPayEnabled'] );
		$this->assertTrue( $data['shouldShowWooPayButton'] );
	}

	/**
	 * Top-level keys of the client 11.1.0 Blocks payment method data on a checkout page with WooPay enabled.
	 *
	 * Sources: class-wc-payments-blocks-payment-method.php:88-104 (title, description, is_admin, woopayHost),
	 * class-wc-payments-checkout.php:183-274 (payment fields config), class-wc-payments-woopay-button-handler.php:144-160
	 * (WooPay button keys) and class-woopay-tracker.php:660-662 (isShopperTrackingEnabled).
	 */
	private const CLIENT_BLOCKS_DATA_KEYS = array(
		'title',
		'description',
		'is_admin',
		'woopayHost',
		'publishableKey',
		'testMode',
		'accountId',
		'ajaxUrl',
		'wcAjaxUrl',
		'createSetupIntentNonce',
		'initWooPayNonce',
		'genericErrorMessage',
		'fraudServices',
		'features',
		'forceNetworkSavedCards',
		'locale',
		'isPreview',
		'isSavedCardsEnabled',
		'isWooPayEnabled',
		'isWoopayExpressCheckoutEnabled',
		'isWoopayFirstPartyAuthEnabled',
		'isWooPayEmailInputEnabled',
		'isWooPayDirectCheckoutEnabled',
		'isWooPayGlobalThemeSupportEnabled',
		'isShortcodeCheckout',
		'platformTrackerNonce',
		'accountIdForIntentConfirmation',
		'wcpayVersionNumber',
		'woopaySignatureNonce',
		'woopaySessionNonce',
		'woopayMerchantId',
		'icon',
		'woopayMinimumSessionData',
		'gatewayId',
		'isCheckout',
		'paymentMethodsConfig',
		'cartContainsSubscription',
		'currency',
		'stylesCacheVersion',
		'cartTotal',
		'enabledBillingFields',
		'storeCountry',
		'isExpressCheckoutInPaymentMethodsEnabled',
		'isShopperTrackingEnabled',
		'woopayButton',
		'woopayButtonNonce',
		'addToCartNonce',
		'shouldShowWooPayButton',
		'woopaySessionEmail',
		'woopayIsCountryAvailable',
		'woopayAppearance',
		'woopayFontRules',
		'isPaymentRequestEnabled',
		'isAmazonPayEnabled',
	);

	/**
	 * Native-only Blocks data keys that the native Blocks bundle reads (client/blocks/assets/js/extensions/payment-methods/woopayments).
	 */
	private const NATIVE_BLOCKS_DATA_KEYS_WITH_CONSUMERS = array(
		'fraudPreventionToken',            // index.js:113-116, the only token source on Blocks pages.
		'isCoreNativeCheckoutAvailable',   // index.js:1040,1268 and woopay/index.js:736,748.
		'paymentMethodTypes',              // index.js:517-521, the split gateway's own Stripe method type.
		'supports',                        // index.js:1277, woopay/index.js:18.
		'PRE_CHECK_SAVE_MY_INFO',          // index.js:706 (the client localizes it as woopayCheckout).
		'woopayOtpIframeTitle',            // woopay/email-input-iframe.js:269, woopay/express-checkout-iframe.js:98.
		'woopayOtpCloseLabel',             // woopay/email-input-iframe.js:420, woopay/express-checkout-iframe.js:187.
		'woopayUnavailableMessage',        // woopay/email-input-iframe.js:432.
		'woopayExpressUnavailableMessage', // woopay/express-checkout-iframe.js:254.
		'tracksUrl',                       // tracks.js:21, the shopper Tracks REST route.
		'tracksRestNonce',                 // tracks.js:38-39, the REST nonce sent with it.
	);

	/**
	 * Keys the Blocks data must not carry: the client never sends them and the native Blocks bundle does not need them.
	 */
	private const DROPPED_BLOCKS_DATA_KEYS = array(
		'confirmationErrorMessage',
		'customerData',
		'paymentListWalletsConfig',
		'updateOrderStatusNonce',
		'woopayButtonLabels',
		'woopayAdditionalInfoText',
		'woopayAgreementText',
		'woopayTermsOfServiceLabel',
		'woopayPrivacyPolicyLabel',
		'woopaySaveUserLabel',
		'woopayPhoneLabel',
		'cardBrandPopoverLabel',
		'cardBrandLogosLabel',
		'is_shopper_tracking_enabled',
	);

	/**
	 * WooPay button keys the client adds only while its WooPay button handler runs (class-wc-payments-woopay-button-handler.php:144-160).
	 */
	private const WOOPAY_BUTTON_KEYS = array(
		'woopayButton',
		'woopayButtonNonce',
		'addToCartNonce',
		'shouldShowWooPayButton',
		'woopaySessionEmail',
		'woopayIsCountryAvailable',
		'woopayAppearance',
		'woopayFontRules',
	);

	/**
	 * Build a checkout bridge whose WooPay config carries every key the real WooPay session service emits.
	 *
	 * @return WooPaymentsCheckoutBridge
	 */
	private function create_bridge_with_full_woopay_config(): WooPaymentsCheckoutBridge {
		$real_account_service = new WooPaymentsAccountService();
		$real_account_service->init( new LegacyProxy() );
		$real_woopay_service = new WooPaymentsWooPaySessionService();
		$real_woopay_service->init( $real_account_service, new WooPaymentsFrontendStylesService(), $this->create_frontend_tracking_controller_for_bridge() );
		$woopay_config = array_merge(
			array_fill_keys( array_keys( $real_woopay_service->get_woopay_frontend_config( 'checkout' ) ), '' ),
			array(
				'isWooPayEnabled'        => true,
				'shouldShowWooPayButton' => true,
				'forceNetworkSavedCards' => false,
			)
		);

		$woopay_service = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_enabled', 'get_woopay_frontend_config', 'get_save_user_checkout_data' ) )
			->getMock();
		$woopay_service->method( 'is_woopay_enabled' )->willReturn( true );
		$woopay_service->method( 'get_woopay_frontend_config' )->willReturn( $woopay_config );
		$woopay_service->method( 'get_save_user_checkout_data' )->willReturn( array( 'PRE_CHECK_SAVE_MY_INFO' => true ) );

		$account_service = $this->create_account_service_for_bridge( true, self::EXPRESS_ACCOUNT_DATA, self::EXPRESS_GATEWAY_SETTINGS );
		$bridge          = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $woopay_service, $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$this->inject_express_checkout_service( $bridge, $account_service );

		return $bridge;
	}

	/**
	 * @testdox Blocks payment method data carries exactly the client's keys plus the native keys the Blocks bundle reads.
	 */
	public function test_get_blocks_payment_method_data_has_the_client_key_set_plus_consumed_native_keys(): void {
		$data = $this->create_bridge_with_full_woopay_config()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$expected = array_merge( self::CLIENT_BLOCKS_DATA_KEYS, self::NATIVE_BLOCKS_DATA_KEYS_WITH_CONSUMERS );
		sort( $expected );
		$actual = array_keys( $data );
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * @testdox Blocks payment method data never carries the dropped native-only keys, while the classic config keeps them.
	 */
	public function test_get_blocks_payment_method_data_omits_dropped_native_keys(): void {
		$bridge = $this->create_bridge_with_full_woopay_config();
		$data   = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$shared = new \ArrayObject();
		$split  = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS, ( new WooPaymentsPaymentMethodRegistry() )->get( 'klarna' ), $shared );

		foreach ( self::DROPPED_BLOCKS_DATA_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $data, "Blocks data must not carry {$key}." );
			$this->assertArrayNotHasKey( $key, $split, "Split-gateway Blocks data must not carry {$key}." );
		}

		$classic = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$this->assertArrayHasKey( 'customerData', $classic );
		$this->assertArrayHasKey( 'paymentListWalletsConfig', $classic );
		$this->assertArrayHasKey( 'woopayAgreementText', $classic );
	}

	/**
	 * @testdox Blocks payment method data reports is_admin like the client: false on the storefront, true in wp-admin.
	 */
	public function test_get_blocks_payment_method_data_reports_is_admin(): void {
		$bridge = $this->create_bridge_with_full_woopay_config();

		$this->assertFalse( $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS )['is_admin'] );

		set_current_screen( 'edit-post' );
		try {
			$this->assertTrue( $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS )['is_admin'] );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Account data under which the client runs its express checkout handlers and can use Amazon Pay.
	 */
	private const EXPRESS_ACCOUNT_DATA = array(
		'country'          => 'US',
		'payments_enabled' => true,
		'capabilities'     => array( 'amazon_pay_payments' => 'active' ),
		'fees'             => array( 'amazon_pay' => array( 'base' => array( 'currency' => 'usd' ) ) ),
	);

	/**
	 * Gateway settings with the gateway enabled, Apple Pay/Google Pay on and Amazon Pay switched on and listed at checkout.
	 */
	private const EXPRESS_GATEWAY_SETTINGS = array(
		'enabled'                           => 'yes',
		'payment_request'                   => 'yes',
		'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
		'express_checkout_product_methods'  => array( 'payment_request' ),
		'express_checkout_cart_methods'     => array( 'payment_request' ),
		'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
	);

	/**
	 * @testdox Blocks data carries the client's express checkout switches for the checkout page's own location settings.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_data_express_switches_follow_the_checkout_location(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$data = $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$this->assertTrue( $data['isPaymentRequestEnabled'] );
		$this->assertTrue( $data['isAmazonPayEnabled'] );

		$data = $this->create_bridge_for_express_handlers( array( 'express_checkout_checkout_methods' => array( 'amazon_pay' ) ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$this->assertFalse( $data['isPaymentRequestEnabled'], 'Apple Pay/Google Pay is not listed at checkout.' );
		$this->assertTrue( $data['isAmazonPayEnabled'] );

		$data = $this->create_bridge_for_express_handlers( array( 'express_checkout_checkout_methods' => array( 'payment_request' ) ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$this->assertTrue( $data['isPaymentRequestEnabled'] );
		$this->assertFalse( $data['isAmazonPayEnabled'], 'Amazon Pay is not listed at checkout.' );
	}

	/**
	 * @testdox Blocks data reports Amazon Pay off once the merchant switched it off or the account cannot use it.
	 */
	public function test_blocks_data_amazon_pay_switch_uses_native_amazon_pay_availability(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$toggled_off = $this->create_bridge_for_express_handlers( array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$ineligible  = $this->create_bridge_for_express_handlers( array(), array( 'capabilities' => array() ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $toggled_off['isPaymentRequestEnabled'] );
		$this->assertFalse( $toggled_off['isAmazonPayEnabled'] );
		$this->assertFalse( $ineligible['isAmazonPayEnabled'] );
	}

	/**
	 * @testdox Blocks data outside the product, cart and checkout pages reports the switches without a location, as the client does.
	 *
	 * A separate process, because an earlier test can define WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_blocks_data_express_switches_ignore_locations_without_a_page_context(): void {
		$data = $this->create_bridge_for_express_handlers(
			array(
				'express_checkout_checkout_methods' => array(),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_product_methods'  => array(),
			)
		)->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isPaymentRequestEnabled'] );
		$this->assertTrue( $data['isAmazonPayEnabled'] );
	}

	/**
	 * @testdox Blocks data leaves out the express checkout switches while the client's express checkout handler does not run.
	 *
	 * @dataProvider provider_express_handler_off
	 *
	 * @param array<string,mixed> $settings     Gateway setting overrides.
	 * @param array<string,mixed> $account_data Account data overrides.
	 * @param bool                $change_page  Whether the request is a change payment method page.
	 */
	public function test_blocks_data_omits_express_switches_while_the_client_handler_is_off( array $settings, array $account_data, bool $change_page ): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		if ( $change_page ) {
			$_GET['change_payment_method'] = '123';
		}

		$data = $this->create_bridge_for_express_handlers( $settings, $account_data )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertArrayNotHasKey( 'isPaymentRequestEnabled', $data );
		$this->assertArrayNotHasKey( 'isAmazonPayEnabled', $data );
	}

	/**
	 * Cases where the client's express checkout button handler does not add its config.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:bool}>
	 */
	public function provider_express_handler_off(): array {
		return array(
			'gateway disabled'                  => array( array( 'enabled' => 'no' ), array(), false ),
			'payments not enabled on account'   => array( array(), array( 'payments_enabled' => false ), false ),
			'no express checkout method usable' => array(
				array(
					'payment_request'                => 'no',
					'upe_enabled_payment_method_ids' => array( 'card' ),
				),
				array(),
				false,
			),
			'change payment method page'        => array( array(), array(), true ),
		);
	}

	/**
	 * @testdox Blocks data carries the client's order-pay keys on a pay-for-order link, with the order's email for its own customer.
	 */
	public function test_blocks_data_carries_the_client_order_pay_keys(): void {
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = \WC_Helper_Order::create_order( $customer_id );
		$order->set_billing_email( 'order@example.com' );
		$order->save();
		wp_set_current_user( $customer_id );
		$this->go_to_pay_for_order_link( $order );

		$data = $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( 'true', $data['pay_for_order'] );
		$this->assertSame( $order->get_order_key(), $data['key'] );
		$this->assertSame( 'order@example.com', $data['billing_email'] );
	}

	/**
	 * @testdox Blocks data carries no order-pay keys for a pay-for-order link with a wrong key.
	 */
	public function test_blocks_data_carries_no_order_pay_keys_with_a_wrong_key(): void {
		$order = \WC_Helper_Order::create_order( 0 );
		wp_set_current_user( 0 );
		$this->go_to_pay_for_order_link( $order );
		$_GET['key'] = 'wc_order_wrong';

		$data = $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( current_user_can( 'pay_for_order', $order->get_id() ) );
		foreach ( array( 'order_id', 'pay_for_order', 'key', 'billing_email' ) as $order_pay_key ) {
			$this->assertArrayNotHasKey( $order_pay_key, $data );
		}
	}

	/**
	 * @testdox Blocks data gives a shopper who cannot see the order the email they typed, not the order's.
	 *
	 * On the configured checkout page, where core defines DONOTCACHEPAGE (WC_Cache_Helper::prevent_caching()); the constant
	 * is set here because an earlier test in the process may have defined it either way.
	 */
	public function test_blocks_data_order_pay_email_hides_the_order_email_from_other_payers(): void {
		$order = \WC_Helper_Order::create_order( 0 );
		$order->set_billing_email( 'order@example.com' );
		$order->save();
		$this->go_to_pay_for_order_link( $order );
		$_POST['email'] = 'typed@example.com';
		Constants::set_constant( 'DONOTCACHEPAGE', true );

		try {
			$data = $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		} finally {
			Constants::clear_single_constant( 'DONOTCACHEPAGE' );
		}

		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( 'typed@example.com', $data['billing_email'] );
	}

	/**
	 * @testdox Blocks data keeps a guest's $source email out of the order-pay keys on another page carrying the checkout shortcode.
	 *
	 * Core defines DONOTCACHEPAGE only on the configured cart, checkout and My Account pages (WC_Cache_Helper::prevent_caching()),
	 * so a page cache could serve this page's HTML to the next visitor. The constant is set to what that page gets, because an
	 * earlier test in the process may have defined it.
	 *
	 * @testWith ["session"]
	 *           ["posted"]
	 *
	 * @param string $source Where the visitor's email comes from.
	 */
	public function test_blocks_data_keeps_a_guest_email_off_a_page_core_does_not_protect( string $source ): void {
		$order = \WC_Helper_Order::create_order( 0 );
		$order->set_billing_email( 'order@example.com' );
		$order->save();
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		$this->go_to_pay_for_order_link( $order );
		$session          = WC()->session;
		$session_customer = $session->get( 'customer' );
		$session->set( 'customer', 'session' === $source ? array( 'email' => 'session@example.com' ) : null );
		if ( 'posted' === $source ) {
			$_POST['email'] = 'typed@example.com';
		}
		Constants::set_constant( 'DONOTCACHEPAGE', false );

		try {
			$data = $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		} finally {
			Constants::clear_single_constant( 'DONOTCACHEPAGE' );
			$session->set( 'customer', $session_customer );
		}

		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( $order->get_order_key(), $data['key'] );
		$this->assertSame( '', $data['billing_email'] );
	}

	/**
	 * @testdox Blocks data leaves out the order-pay keys without the order key, for a user who cannot pay the order, or without payments enabled.
	 */
	public function test_blocks_data_omits_order_pay_keys_outside_an_authorized_pay_for_order_link(): void {
		$order_keys  = array( 'order_id', 'pay_for_order', 'key', 'billing_email' );
		$owner_id    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$other_id    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order       = \WC_Helper_Order::create_order( $owner_id );
		$assert_none = function ( array $data, string $message ) use ( $order_keys ): void {
			foreach ( $order_keys as $key ) {
				$this->assertArrayNotHasKey( $key, $data, $message );
			}
		};

		wp_set_current_user( $owner_id );
		$this->go_to_pay_for_order_link( $order );
		unset( $_GET['key'] );
		$assert_none( $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS ), 'Missing order key.' );

		$this->go_to_pay_for_order_link( $order );
		wp_set_current_user( $other_id );
		$assert_none( $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS ), 'Another customer cannot pay the order.' );

		wp_set_current_user( $owner_id );
		$assert_none( $this->create_bridge_for_express_handlers( array(), array( 'payments_enabled' => false ) )->get_blocks_payment_method_data( self::CARD_SUPPORTS ), 'Payments are not enabled.' );
	}

	/**
	 * @testdox Blocks data leaves out the order-pay keys on a subscription's change payment method page, where the client skips its config filters.
	 */
	public function test_blocks_data_omits_order_pay_keys_while_changing_a_subscription_payment_method(): void {
		$this->report_subscriptions_loaded();

		$order = \WC_Helper_Order::create_order( 0 );
		$this->go_to_pay_for_order_link( $order );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( 123 );
		$_GET['change_payment_method']                                = '123';

		$data = $this->create_bridge_for_express_handlers()->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertTrue( $data['isChangingPayment'] );
		$this->assertArrayNotHasKey( 'order_id', $data );
		$this->assertArrayNotHasKey( 'billing_email', $data );
	}

	/**
	 * Simulate opening an order's pay-for-order link.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function go_to_pay_for_order_link( \WC_Order $order ): void {
		global $wp;

		$_GET['pay_for_order']       = 'true';
		$_GET['key']                 = $order->get_order_key();
		$wp->query_vars['order-pay'] = (string) $order->get_id();
	}

	/**
	 * @testdox Blocks data carries the client's WooPay button keys while WooPay and its express button are enabled.
	 */
	public function test_blocks_data_carries_woopay_button_keys_while_woopay_is_enabled(): void {
		$data = $this->create_bridge_for_express_handlers( array(), array(), true )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		foreach ( self::WOOPAY_BUTTON_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
	}

	/**
	 * @testdox Blocks data leaves out the WooPay button keys while the client's WooPay button handler does not run.
	 *
	 * @dataProvider provider_woopay_button_handler_off
	 *
	 * @param string $scenario Scenario.
	 */
	public function test_blocks_data_omits_woopay_button_keys_while_woopay_is_off( string $scenario ): void {
		$woopay   = 'woopay disabled' !== $scenario;
		$settings = 'gateway disabled' === $scenario ? array( 'enabled' => 'no' ) : array();
		if ( 'express button flag off' === $scenario ) {
			update_option( '_wcpay_feature_woopay_express_checkout', '0' );
		}
		if ( 'filtered off' === $scenario ) {
			add_filter( 'wcpay_woopay_enabled', '__return_false' );
		}
		if ( 'change payment method page' === $scenario ) {
			$_GET['change_payment_method'] = '123';
		}

		$data = $this->create_bridge_for_express_handlers( $settings, array(), $woopay )->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		foreach ( self::WOOPAY_BUTTON_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $data, "{$scenario}: {$key}" );
		}
		$this->assertArrayHasKey( 'isWooPayEnabled', $data );
	}

	/**
	 * Cases where the client's WooPay button handler does not add its config.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_woopay_button_handler_off(): array {
		return array(
			'woopay disabled'            => array( 'woopay disabled' ),
			'express button flag off'    => array( 'express button flag off' ),
			'filtered off'               => array( 'filtered off' ),
			'gateway disabled'           => array( 'gateway disabled' ),
			'change payment method page' => array( 'change payment method page' ),
		);
	}

	/**
	 * @testdox Should strip script tags from filter-injected card testing instructions in the Blocks payload.
	 */
	public function test_get_blocks_payment_method_data_sanitizes_filtered_testing_instructions(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		add_filter(
			'wcpay_payment_fields_js_config',
			static function ( array $config ): array {
				$config['paymentMethodsConfig']['card']['testingInstructions'] = '<script>alert(1)</script><strong>ok</strong>';
				return $config;
			}
		);

		$data                 = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS );
		$testing_instructions = $data['paymentMethodsConfig']['card']['testingInstructions'];

		$this->assertStringNotContainsString( '<script>', $testing_instructions );
		$this->assertStringContainsString( '<strong>ok</strong>', $testing_instructions );
	}

	/**
	 * @testdox Should expose the setup intent and order status nonces when the checkout surface is available.
	 */
	public function test_get_payment_fields_js_config_exposes_native_bridge_nonces_when_checkout_surface_is_available(): void {
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

		$this->assertArrayHasKey( 'createSetupIntentNonce', $config );
		$this->assertArrayHasKey( 'updateOrderStatusNonce', $config );
	}

	/**
	 * @testdox Should expose the fraud-prevention token in card checkout config and Blocks payment method data.
	 */
	public function test_get_payment_fields_js_config_includes_fraud_prevention_token_when_enabled(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'fraud-token-123' );

		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$account_service,
			$this->create_woopay_session_service_for_bridge( true ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge(),
			$this->create_fraud_prevention_service( true, $session )
		);

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );
		$data   = $bridge->get_blocks_payment_method_data( self::CARD_SUPPORTS );

		$this->assertSame( 'fraud-token-123', $config['fraudPreventionToken'] );
		$this->assertSame( 'fraud-token-123', $data['fraudPreventionToken'] );
	}

	/**
	 * @testdox Should publish the fraud-prevention token on window when rendering classic payment fields.
	 */
	public function test_payment_fields_publish_fraud_prevention_token_inline_script_when_enabled(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'fraud-token-123' );

		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init(
			$account_service,
			$this->create_woopay_session_service_for_bridge( true ),
			$this->create_frontend_styles_service_for_bridge(),
			$this->create_frontend_tracking_controller_for_bridge(),
			$this->create_fraud_prevention_service( true, $session )
		);

		ob_start();
		$bridge->render_payment_fields( self::CARD_SUPPORTS );
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
		$account_service = $this->create_account_service_for_bridge( false );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( true ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

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
		$account_service = $this->create_account_service_for_bridge( true );

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

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
		$account_service = $this->create_account_service_for_bridge(
			true,
			array(
				'country'        => 'US',
				'fraud_services' => array( 'stripe' => array() ),
			)
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( false ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$this->inject_fraud_service_for_bridge( $bridge, $account_service );

		$config = $bridge->get_payment_fields_js_config( self::CARD_SUPPORTS );

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
	 * Create an account service mock for checkout bridge tests.
	 *
	 * @param bool  $can_process_payments Whether the account can process payments.
	 * @param array $account_data         Account cache data, in the platform account shape the client caches (country,
	 *                                    capabilities keyed by capability with a status; client 11.1.0
	 *                                    includes/class-wc-payments-account.php:269-292).
	 * @param array $gateway_settings     Gateway settings.
	 * @return WooPaymentsAccountService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_account_service_for_bridge( bool $can_process_payments, array $account_data = array( 'country' => 'RO' ), array $gateway_settings = array() ) {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_publishable_key', 'get_account_id', 'get_cached_account_data', 'get_gateway_setting', 'is_payment_request_method_enabled', 'is_payment_request_enabled', 'can_process_payments', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service
			->method( 'is_payment_request_enabled' )
			->willReturn( 'yes' === ( $gateway_settings['payment_request'] ?? 'no' ) );

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
	 * Give the bridge a real express checkout service backed by the bridge's account service.
	 *
	 * @param WooPaymentsCheckoutBridge $bridge          Checkout bridge.
	 * @param WooPaymentsAccountService $account_service Account service mock.
	 */
	private function inject_express_checkout_service( WooPaymentsCheckoutBridge $bridge, WooPaymentsAccountService $account_service ): void {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$service = new WooPaymentsExpressCheckoutService();
		$service->init( $account_service, $provider, $this->create_frontend_tracking_controller_for_bridge() );

		$property = new \ReflectionProperty( WooPaymentsCheckoutBridge::class, 'express_checkout_service' );
		$property->setAccessible( true );
		$property->setValue( $bridge, $service );
	}

	/**
	 * Build a bridge whose account can run the client's express checkout handlers.
	 *
	 * @param array<string,mixed> $settings     Gateway setting overrides.
	 * @param array<string,mixed> $account_data Account data overrides.
	 * @param bool                $woopay       Whether the WooPay session service reports WooPay enabled.
	 * @return WooPaymentsCheckoutBridge
	 */
	private function create_bridge_for_express_handlers( array $settings = array(), array $account_data = array(), bool $woopay = false ): WooPaymentsCheckoutBridge {
		$account_service = $this->create_account_service_for_bridge(
			true,
			array_merge( self::EXPRESS_ACCOUNT_DATA, $account_data ),
			array_merge( self::EXPRESS_GATEWAY_SETTINGS, $settings )
		);

		$bridge = new WooPaymentsCheckoutBridge();
		$bridge->init( $account_service, $this->create_woopay_session_service_for_bridge( $woopay ), $this->create_frontend_styles_service_for_bridge(), $this->create_frontend_tracking_controller_for_bridge() );
		$this->inject_express_checkout_service( $bridge, $account_service );

		return $bridge;
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
	 * @param bool $enabled                 Whether WooPay is enabled.
	 * @param bool $direct_checkout_enabled Whether WooPay direct checkout is enabled.
	 * @param bool $uses_stripe_platform    Whether checkout uses the Stripe platform account for WooPay.
	 * @return WooPaymentsWooPaySessionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_woopay_session_service_for_bridge( bool $enabled, bool $direct_checkout_enabled = false, bool $uses_stripe_platform = false ) {
		$service = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_enabled', 'is_woopay_direct_checkout_enabled', 'get_woopay_frontend_config', 'get_save_user_checkout_data', 'should_use_stripe_platform_on_checkout_page' ) )
			->getMock();

		$service->method( 'is_woopay_enabled' )->willReturn( $enabled );
		$service->method( 'should_use_stripe_platform_on_checkout_page' )->willReturn( $uses_stripe_platform );
		$service->method( 'is_woopay_direct_checkout_enabled' )->willReturn( $direct_checkout_enabled );
		$service->method( 'get_woopay_frontend_config' )->willReturn(
			array(
				'isWooPayEnabled'                   => $enabled,
				'isWoopayExpressCheckoutEnabled'    => $enabled,
				'isWoopayFirstPartyAuthEnabled'     => $enabled,
				'isWooPayEmailInputEnabled'         => $enabled,
				'isWooPayDirectCheckoutEnabled'     => $direct_checkout_enabled,
				'isWooPayGlobalThemeSupportEnabled' => false,
				'forceNetworkSavedCards'            => false,
				'platformTrackerNonce'              => 'platform-tracks-nonce',
				'woopayHost'                        => 'https://pay.woo.com',
				'wcpayVersionNumber'                => \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion::VERSION,
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

		// The page flag asks the store-level question only: not an admin event, recorded on every store.
		$controller->method( 'is_shopper_tracking_enabled' )->willReturnCallback(
			static function ( bool $is_admin_event = false, bool $track_on_all_stores = false ): bool {
				return ! $is_admin_event && $track_on_all_stores;
			}
		);

		return $controller;
	}
}
