<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFrontendTrackingController class.
 */
class WooPaymentsFrontendTrackingControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'wp_ajax_platform_tracks' );
		remove_all_actions( 'wp_ajax_nopriv_platform_tracks' );
		remove_all_actions( 'wp_ajax_get_identity' );
		remove_all_actions( 'wp_ajax_nopriv_get_identity' );
		remove_all_filters( 'wcpay_tracks_event_properties' );
		remove_all_filters( 'wcpay_shopper_tracking_enabled' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'pre_http_request' );
		unset( $_COOKIE['tk_opt-out'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox Should register platform Tracks AJAX hooks when native owns runtime.
	 */
	public function test_registers_platform_tracks_ajax_hooks_when_native_owns_runtime(): void {
		$sut = $this->create_controller( true );

		$sut->register();

		$this->assertSame( 10, has_action( 'wp_ajax_platform_tracks', array( $sut, 'handle_tracks' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_nopriv_platform_tracks', array( $sut, 'handle_tracks' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_get_identity', array( $sut, 'handle_tracks_identity' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_nopriv_get_identity', array( $sut, 'handle_tracks_identity' ) ) );
	}

	/**
	 * @testdox Should not register platform Tracks AJAX hooks when native runtime is inactive.
	 */
	public function test_does_not_register_ajax_hooks_when_native_runtime_is_inactive(): void {
		$sut = $this->create_controller( false );

		$sut->register();

		$this->assertFalse( has_action( 'wp_ajax_platform_tracks', array( $sut, 'handle_tracks' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_platform_tracks', array( $sut, 'handle_tracks' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_get_identity', array( $sut, 'handle_tracks_identity' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_get_identity', array( $sut, 'handle_tracks_identity' ) ) );
	}

	/**
	 * @testdox Should reject platform Tracks requests with invalid nonces.
	 */
	public function test_tracks_response_rejects_invalid_nonce(): void {
		$sut = $this->create_controller( true );

		$response = $sut->get_tracks_response(
			array(
				'tracksNonce'     => 'bad',
				'tracksEventName' => 'woopay_button_click',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 403, $response['status_code'] );
		$this->assertSame( 'You aren’t authorized to do that.', $response['data'] );
	}

	/**
	 * @testdox Should require a platform Tracks event name.
	 */
	public function test_tracks_response_requires_event_name(): void {
		$sut = $this->create_controller( true );

		$response = $sut->get_tracks_response(
			array(
				'tracksNonce' => wp_create_nonce( 'platform_tracks_nonce' ),
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 403, $response['status_code'] );
		$this->assertSame( 'No valid event name or type.', $response['data'] );
	}

	/**
	 * @testdox Should record prefixed WooPayments shopper events and apply the reference filter.
	 */
	public function test_records_prefixed_wcpay_event_and_applies_reference_filter(): void {
		$captured_url = '';
		$user_id      = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user_id );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wcpay_tracks_event_properties',
			static function ( array $properties, string $event_name ): array {
				$properties['filtered_prop']  = 'yes';
				$properties['filtered_event'] = $event_name;
				return $properties;
			},
			10,
			2
		);
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				// The response array WP_Http::request() returns (wp-includes/class-wp-http.php).
				return array(
					'headers'  => array(),
					'body'     => '',
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
		$sut = $this->create_controller( true, $this->create_account_service( true ) );

		$response = $sut->get_tracks_response(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'woopay_button_click',
				'tracksEventProp' => wp_json_encode( array( 'source' => 'checkout' ) ),
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertNotSame( '', $captured_url );
		parse_str( (string) wp_parse_url( $captured_url, PHP_URL_QUERY ), $pixel_args );
		$this->assertSame( 'wcpay_woopay_button_click', $pixel_args['_en'] );
		$this->assertSame( 'anon', $pixel_args['_ut'] );
		$this->assertStringStartsWith( 'jetpack:', $pixel_args['_ui'] );
		$this->assertSame( 'checkout', $pixel_args['source'] );
		$this->assertSame( 'yes', $pixel_args['filtered_prop'] );
		$this->assertSame( 'wcpay_woopay_button_click', $pixel_args['filtered_event'] );
		$this->assertSame( '1', $pixel_args['test_mode'] );
	}

	/**
	 * @testdox Should send a shopper event without the shopper's IP, referrer or request URL, keeping the identity properties.
	 *
	 * Client 11.1.0 `includes/class-woopay-tracker.php:368-405` builds shopper events from `_lg`, the blog and store ids,
	 * `test_mode`, `wcpay_version` and `_via_ua` only; core's `WC_Tracks::get_server_details()` would add `_via_ip`, `_dr`
	 * and `_dl` (`includes/tracks/class-wc-tracks.php:61-73`), and on pay-for-order pages the referrer carries the order key.
	 */
	public function test_shopper_event_omits_ip_referrer_and_request_url(): void {
		$server       = $_SERVER;
		$captured_url = '';
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				// The response array WP_Http::request() returns (wp-includes/class-wp-http.php).
				return array(
					'headers'  => array(),
					'body'     => '',
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
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_REFERER']    = 'https://store.test/checkout/order-pay/12/?pay_for_order=true&key=wc_order_abc123';
		$_SERVER['REQUEST_SCHEME']  = 'https';
		$_SERVER['HTTP_HOST']       = 'store.test';
		$_SERVER['REQUEST_URI']     = '/wp-admin/admin-ajax.php';
		$_SERVER['HTTP_USER_AGENT'] = 'Shopper browser';

		try {
			$response = $this->create_controller( true, $this->create_account_service( true ) )->get_tracks_response(
				array(
					'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
					'tracksEventName' => 'pay_for_order_page_view',
					'tracksEventProp' => wp_json_encode( array() ),
				)
			);
		} finally {
			$_SERVER = $server;
		}

		$this->assertTrue( $response['success'] );
		parse_str( (string) wp_parse_url( $captured_url, PHP_URL_QUERY ), $pixel_args );
		$this->assertSame( 'wcpay_pay_for_order_page_view', $pixel_args['_en'] );
		$this->assertArrayNotHasKey( '_via_ip', $pixel_args );
		$this->assertArrayNotHasKey( '_dr', $pixel_args );
		$this->assertArrayNotHasKey( '_dl', $pixel_args );
		$this->assertSame( 'Shopper browser', $pixel_args['_via_ua'] );
	}

	/**
	 * Native events name their source instead of a plugin version (the Tracks event source scheme the owner approved
	 * on 2026-10-07): `payments_runtime` is `woocommerce_core`, and `wcpay_version`, which client 11.1.0 fills with the
	 * plugin version (`class-woopay-tracker.php:395`), is not sent; `wc_version` from core's blog details stays.
	 *
	 * @testdox Should mark a recorded shopper event as native WooCommerce core, whatever the browser sends.
	 */
	public function test_shopper_event_names_the_native_runtime_and_no_plugin_version(): void {
		$captured_url = '';
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
				unset( $preempt, $parsed_args );
				$captured_url = $url;

				// The response array WP_Http::request() returns (wp-includes/class-wp-http.php).
				return array(
					'headers'  => array(),
					'body'     => '',
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

		$response = $this->create_controller( true, $this->create_account_service( true ) )->get_tracks_response(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'pay_for_order_page_view',
				'tracksEventProp' => wp_json_encode(
					array(
						'payments_runtime' => 'woopayments_plugin',
						'wcpay_version'    => '9.9.9',
					)
				),
			)
		);

		$this->assertTrue( $response['success'] );
		parse_str( (string) wp_parse_url( $captured_url, PHP_URL_QUERY ), $pixel_args );
		$this->assertSame( 'woocommerce_core', $pixel_args['payments_runtime'] ?? null );
		$this->assertArrayNotHasKey( 'wcpay_version', $pixel_args );
		$this->assertNotEmpty( $pixel_args['wc_version'] ?? null );
	}

	/**
	 * @testdox Should still record the event when a property filter returns null, as the client does.
	 *
	 * Client 11.1.0 `includes/class-woopay-tracker.php:372-380` adds its properties to the null result, which PHP turns into
	 * an array, and `:400` merges `(array) $properties`.
	 */
	public function test_property_filter_returning_null_does_not_break_recording(): void {
		$captured_url = '';
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wcpay_tracks_event_properties', '__return_null' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				// The response array WP_Http::request() returns (wp-includes/class-wp-http.php).
				return array(
					'headers'  => array(),
					'body'     => '',
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

		$response = $this->create_controller( true, $this->create_account_service( true ) )->get_tracks_response(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'woopay_button_click',
				'tracksEventProp' => wp_json_encode( array( 'source' => 'checkout' ) ),
			)
		);

		$this->assertTrue( $response['success'] );
		parse_str( (string) wp_parse_url( $captured_url, PHP_URL_QUERY ), $pixel_args );
		$this->assertSame( 'wcpay_woopay_button_click', $pixel_args['_en'] );
	}

	/**
	 * @testdox Should hand a page-request event to core's Tracks footer pixel instead of sending it mid-request.
	 */
	public function test_records_through_core_tracks_event_transport(): void {
		\WC_Tracks_Footer_Pixel::clear_events();
		$requests = 0;
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				++$requests;
				return new \WP_Error( 'blocked', 'blocked' );
			}
		);
		$sut = $this->create_controller( true );

		$result = $sut->record_user_event( 'woopay_registered', array( 'source' => 'checkout' ) );

		$events = \WC_Tracks_Footer_Pixel::get_events();
		\WC_Tracks_Footer_Pixel::clear_events();
		$this->assertTrue( $result );
		$this->assertSame( 0, $requests );
		$this->assertCount( 1, $events );
		$this->assertInstanceOf( \WC_Tracks_Event::class, $events[0] );
		$this->assertSame( 'wcpay_woopay_registered', $events[0]->_en );
		$this->assertSame( 'checkout', $events[0]->source );
	}

	/**
	 * @testdox Should queue nothing and load no footer script when the store has shopper tracking off.
	 */
	public function test_queue_user_event_is_a_no_op_when_store_tracking_is_off(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'no' );
		$sut = $this->create_controller( true );

		$sut->queue_user_event( 'product_page_view', array( 'theme_type' => 'short_code' ) );
		$sut->enqueue_frontend_events_script();

		$this->assertFalse( has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
		$this->assertFalse( wp_script_is( 'wc-woopayments-frontend-tracks', 'enqueued' ) );
	}

	/**
	 * @testdox Should queue nothing and track nothing while the WooPayments gateway is disabled.
	 *
	 * Client 11.1.0 `should_enable_tracking()` returns false when the gateway is disabled (`class-woopay-tracker.php:242-246`),
	 * so a connected store with the gateway off loads no Tracks script on its thank-you page (review 36 F3).
	 */
	public function test_tracking_is_off_while_the_gateway_is_disabled(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$sut = $this->create_controller( true, $this->create_account_service( true, false ) );

		$sut->queue_user_event( 'order_success_page_view', array( 'theme_type' => 'blocks' ) );

		$this->assertFalse( has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
		$this->assertFalse( $sut->is_shopper_tracking_enabled( false, true ) );
	}

	/**
	 * A recorded departure from client 11.1.0, which computes isShopperTrackingEnabled with the
	 * WooPay check (`class-woopay-tracker.php:660-674`, PR 11199), so its sender drops the page views that PR 6870 and
	 * PR 8821 meant to record on every store. Native queues them; they carry track_on_all_stores.
	 *
	 * @testdox Should queue page views when WooPay is off, since they are recorded on every store.
	 */
	public function test_queue_user_event_queues_page_views_when_woopay_is_off(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled' ) )
			->getMock();
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'can_process_payments' )->willReturn( true );
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'platform_checkout_eligible' => true ) );
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$sut = $this->create_controller( true, $account_service );

		$sut->queue_user_event( 'cart_page_view', array( 'theme_type' => 'blocks' ) );

		try {
			$this->assertSame( 10, has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
		} finally {
			remove_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) );
		}
		$this->assertTrue( $sut->is_shopper_tracking_enabled( false, true ) );
	}

	/**
	 * @testdox Should arm no cart click tracking, and not ask about direct checkout, when WooPay is off, as client 11.1.0's recorder drops the event then.
	 */
	public function test_proceed_to_checkout_tracking_is_a_no_op_when_woopay_is_off(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled' ) )
			->getMock();
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'can_process_payments' )->willReturn( true );
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'platform_checkout_eligible' => true ) );
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$sut          = $this->create_controller( true, $account_service );
		$direct_calls = 0;

		$sut->track_proceed_to_checkout_clicks(
			static function () use ( &$direct_calls ): bool {
				++$direct_calls;
				return true;
			}
		);
		$sut->enqueue_frontend_events_script();

		$this->assertSame( 0, $direct_calls );
		$this->assertFalse( has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
		$this->assertFalse( wp_script_is( 'wc-woopayments-frontend-tracks', 'enqueued' ) );
	}

	/**
	 * @testdox Should load the footer script for cart click tracking even with no queued page view, and arm it once.
	 */
	public function test_proceed_to_checkout_tracking_loads_the_footer_script_on_its_own(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$sut = $this->create_controller( true );

		try {
			$sut->track_proceed_to_checkout_clicks( '__return_true' );
			$this->assertSame( 10, has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
			$sut->enqueue_frontend_events_script();
			$this->assertTrue( wp_script_is( 'wc-woopayments-frontend-tracks', 'enqueued' ) );
			$localized = (string) wp_scripts()->get_data( 'wc-woopayments-frontend-tracks', 'data' );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			$sut->enqueue_frontend_events_script();
			$this->assertFalse( wp_script_is( 'wc-woopayments-frontend-tracks', 'enqueued' ) );
		} finally {
			remove_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			wp_deregister_script( 'wc-woopayments-frontend-tracks' );
		}

		$this->assertSame( 1, preg_match( '/^var wc_woopayments_frontend_tracks_params = (\{.*\});$/s', $localized, $matches ) );
		$params = json_decode( $matches[1], true );
		$this->assertSame( array(), $params['events'] );
		$this->assertSame( array( 'woopayDirectCheckout' => true ), $params['proceedToCheckout'] );
	}

	/**
	 * @testdox Should queue page views even when the visitor who primes a page cache opted out; the AJAX recorder applies the per-visitor checks.
	 */
	public function test_queue_user_event_ignores_per_visitor_opt_out(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$_COOKIE['tk_opt-out'] = 'yes';
		$sut                   = $this->create_controller( true );

		$sut->queue_user_event( 'product_page_view', array( 'theme_type' => 'short_code' ) );

		$this->assertSame( 10, has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
		$this->assertFalse( $sut->is_shopper_tracking_enabled( false, true ) );
	}

	/**
	 * @testdox Should preserve WooPayments Jetpack identity meta continuity.
	 */
	public function test_tracks_identity_prefers_jetpack_identity_meta(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, 'jetpack_tracks_anon_id', 'jetpack:anon-id' );
		wp_set_current_user( $user_id );

		$sut = $this->create_controller( true );

		$response = $sut->get_tracks_identity_response();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 200, $response['status_code'] );
		$this->assertSame( 'anon', $response['data']['_ut'] );
		$this->assertSame( 'jetpack:anon-id', $response['data']['_ui'] );
		$this->assertSame( '', get_user_meta( $user_id, '_woocommerce_tracks_anon_id', true ) );
	}

	/**
	 * Create the controller under test.
	 *
	 * @param bool                           $native_register Whether native runtime owns WooPayments.
	 * @param WooPaymentsAccountService|null $account_service Account service double.
	 * @return WooPaymentsFrontendTrackingController
	 */
	private function create_controller( bool $native_register, ?WooPaymentsAccountService $account_service = null ): WooPaymentsFrontendTrackingController {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$sut = new WooPaymentsFrontendTrackingController();
		$sut->init( $arbiter, $account_service ?? $this->create_account_service( true ) );

		return $sut;
	}

	/**
	 * Create an account service double.
	 *
	 * @param bool $test_mode       Whether the account is in test mode.
	 * @param bool $gateway_enabled Whether the WooPayments gateway is enabled.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode, bool $gateway_enabled = true ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'get_cached_account_data', 'get_gateway_setting', 'is_test_mode_enabled', 'is_gateway_enabled' ) )
			->getMock();

		$account_service->method( 'is_gateway_enabled' )->willReturn( $gateway_enabled );
		$account_service->method( 'can_process_payments' )->willReturn( true );
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'                    => 'US',
				'platform_checkout_eligible' => true,
			)
		);
		$account_service->method( 'get_gateway_setting' )->willReturnMap(
			array(
				array( 'platform_checkout', 'no', 'yes' ),
			)
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );

		return $account_service;
	}
}
