<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPaySessionService;

/**
 * Tests for the WooPaymentsFrontendTrackingController class.
 */
class WooPaymentsFrontendTrackingControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Later tests build a fresh server.
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
	 * @testdox Should register platform Tracks AJAX hooks when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_platform_tracks_ajax_hooks_when_builtin_owns_runtime(): void {
		$sut = $this->create_controller();

		$sut->register();

		$this->assertSame( 10, has_action( 'wp_ajax_platform_tracks', array( $sut, 'handle_tracks' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_nopriv_platform_tracks', array( $sut, 'handle_tracks' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_get_identity', array( $sut, 'handle_tracks_identity' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_nopriv_get_identity', array( $sut, 'handle_tracks_identity' ) ) );
	}

	/**
	 * The shopper recorder's REST route (the Tracks event source scheme the owner approved on 2026-10-07); the
	 * platform_tracks AJAX action stays as an alias for pages and scripts that still post there.
	 *
	 * @testdox Should register the shopper Tracks REST route.
	 */
	public function test_registers_the_tracks_rest_route(): void {
		$controller = $this->create_controller();
		$controller->register();

		try {
			$this->assertSame( 10, has_action( 'rest_api_init', array( $controller, 'register_rest_routes' ) ) );
			$this->assertArrayHasKey( '/wc/v3/payments/tracks', $this->get_rest_routes_after_init() );
		} finally {
			remove_action( 'rest_api_init', array( $controller, 'register_rest_routes' ) );
		}
	}

	/**
	 * @testdox Should record a declared event posted to the REST route, and refuse an unknown event or a bad nonce.
	 */
	public function test_rest_route_records_declared_events_only(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$sut = $this->create_controller();
		add_action( 'rest_api_init', array( $sut, 'register_rest_routes' ) );
		$this->get_rest_routes_after_init();
		remove_action( 'rest_api_init', array( $sut, 'register_rest_routes' ) );
		$post = static function ( array $params ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', '/wc/v3/payments/tracks' );
			$request->set_body_params( $params );

			return rest_get_server()->dispatch( $request );
		};

		\WC_Tracks_Footer_Pixel::clear_events();
		$recorded = $post(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'cart_page_view',
				'tracksEventProp' => wp_json_encode( array( 'theme_type' => 'blocks' ) ),
			)
		);
		$events   = \WC_Tracks_Footer_Pixel::get_events();
		\WC_Tracks_Footer_Pixel::clear_events();
		$unknown = $post(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'free_text_event',
			)
		);
		$forged  = $post(
			array(
				'tracksNonce'     => 'not-a-nonce',
				'tracksEventName' => 'cart_page_view',
			)
		);

		$this->assertSame( 200, $recorded->get_status(), wp_json_encode( $recorded->get_data() ) );
		$this->assertSame( 'wcpay_cart_page_view', $events[0]->_en ?? null, wp_json_encode( $events ) );
		$this->assertSame( 400, $unknown->get_status() );
		$this->assertSame( 403, $forged->get_status() );
	}

	/**
	 * The recorder takes only the shopper events native scripts send, each with its declared properties (the request
	 * schema the owner decided on 2026-10-07; client 11.1.0 `class-woopay-tracker.php:93-120` forwards any name and any
	 * property).
	 *
	 * @testdox Should refuse a platform Tracks request and record nothing: $_dataName.
	 * @dataProvider provider_refused_tracks_requests
	 *
	 * @param bool        $valid_nonce      Whether the request carries a valid nonce.
	 * @param string|null $event_name       Event name the browser sends, or null for none.
	 * @param int         $expected_status  Expected status code.
	 * @param string|null $expected_message Expected error message, or null when the row does not pin it.
	 */
	public function test_tracks_request_refuses( bool $valid_nonce, ?string $event_name, int $expected_status, ?string $expected_message ): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$request = array( 'tracksNonce' => $valid_nonce ? wp_create_nonce( 'platform_tracks_nonce' ) : 'bad' );
		if ( null !== $event_name ) {
			$request['tracksEventName'] = $event_name;
			$request['tracksEventProp'] = wp_json_encode( array() );
		}
		\WC_Tracks_Footer_Pixel::clear_events();

		$response = $this->create_controller()->record_tracks_request( $request );

		$events = \WC_Tracks_Footer_Pixel::get_events();
		\WC_Tracks_Footer_Pixel::clear_events();
		$this->assertFalse( $response['success'] );
		$this->assertSame( $expected_status, $response['status_code'] );
		if ( null !== $expected_message ) {
			$this->assertSame( $expected_message, $response['data'] );
		}
		$this->assertSame( array(), $events );
	}

	/**
	 * Requests the recorder refuses.
	 *
	 * @return array<string,array{bool,string|null,int,string|null}>
	 */
	public function provider_refused_tracks_requests(): array {
		return array(
			'bad nonce'     => array( false, 'woopay_button_click', 403, 'You aren’t authorized to do that.' ),
			'no event name' => array( true, null, 403, 'No valid event name or type.' ),
			'unknown event' => array( true, 'free_text_event', 400, null ),
		);
	}

	/**
	 * @testdox Should record prefixed WooPayments shopper events and apply the reference filter.
	 */
	public function test_records_prefixed_wcpay_event_and_applies_reference_filter(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
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
		$requests = $this->capture_http_requests();
		$sut      = $this->create_controller( $this->create_account_service( true ) );

		$response = $sut->record_tracks_request(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'woopay_button_click',
				'tracksEventProp' => wp_json_encode( array( 'source' => 'checkout' ) ),
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 200, $response['status_code'] );
		$captured_url = (string) ( $requests[ count( $requests ) - 1 ]['url'] ?? '' );
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
		$server = $_SERVER;
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$requests                   = $this->capture_http_requests();
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_REFERER']    = 'https://store.test/checkout/order-pay/12/?pay_for_order=true&key=wc_order_abc123';
		$_SERVER['REQUEST_SCHEME']  = 'https';
		$_SERVER['HTTP_HOST']       = 'store.test';
		$_SERVER['REQUEST_URI']     = '/wp-admin/admin-ajax.php';
		$_SERVER['HTTP_USER_AGENT'] = 'Shopper browser';

		try {
			$response = $this->create_controller( $this->create_account_service( true ) )->record_tracks_request(
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
		$captured_url = (string) ( $requests[ count( $requests ) - 1 ]['url'] ?? '' );
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
	 * plugin version (`class-woopay-tracker.php:380`), is not sent; `wc_version` from core's blog details stays.
	 *
	 * @testdox Should mark a recorded shopper event as WooCommerce core, whatever the browser sends.
	 */
	public function test_shopper_event_names_the_builtin_runtime_and_no_plugin_version(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$requests = $this->capture_http_requests();

		$response = $this->create_controller( $this->create_account_service( true ) )->record_tracks_request(
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
		$captured_url = (string) ( $requests[ count( $requests ) - 1 ]['url'] ?? '' );
		parse_str( (string) wp_parse_url( $captured_url, PHP_URL_QUERY ), $pixel_args );
		$this->assertSame( 'woocommerce_core', $pixel_args['payments_runtime'] ?? null );
		$this->assertArrayNotHasKey( 'wcpay_version', $pixel_args );
		$this->assertNotEmpty( $pixel_args['wc_version'] ?? null );
	}

	/**
	 * @testdox Should still record the event when a property filter returns null.
	 *
	 * Client 11.1.0 `includes/class-woopay-tracker.php:372-380` adds its properties to the null result, which PHP turns into
	 * an array, and `:400` merges `(array) $properties`.
	 */
	public function test_property_filter_returning_null_does_not_break_recording(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wcpay_tracks_event_properties', '__return_null' );
		$requests = $this->capture_http_requests();

		$response = $this->create_controller( $this->create_account_service( true ) )->record_tracks_request(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => 'woopay_button_click',
				'tracksEventProp' => wp_json_encode( array( 'source' => 'checkout' ) ),
			)
		);

		$this->assertTrue( $response['success'] );
		$captured_url = (string) ( $requests[ count( $requests ) - 1 ]['url'] ?? '' );
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
		$sut = $this->create_controller();

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
	 * Only the store-wide checks decide whether a page view is queued for the footer script; the AJAX recorder applies
	 * the per-visitor ones, so a page view is queued even when the visitor who primes a page cache opted out.
	 *
	 * - Client 11.1.0 `should_enable_tracking()` returns false when the gateway is disabled (`class-woopay-tracker.php:242-246`),
	 *   so a connected store with the gateway off loads no Tracks script on its thank-you page (review 36 F3).
	 * - A recorded departure from client 11.1.0, which computes isShopperTrackingEnabled with the
	 *   WooPay check (`class-woopay-tracker.php:660-674`, PR 11199), so its sender drops the page views that PR 6870 and
	 *   PR 8821 meant to record on every store. Native queues them; they carry track_on_all_stores.
	 *
	 * @testdox Should queue a page view for the footer script only when the store-wide checks pass: $_dataName.
	 * @dataProvider provider_page_view_queue_gates
	 *
	 * @param string              $allow_tracking          The woocommerce_allow_tracking option.
	 * @param bool                $gateway_enabled         Whether the WooPayments gateway is enabled.
	 * @param bool                $woopay_enabled          Whether WooPay is enabled.
	 * @param bool                $opt_out_cookie          Whether the visitor sends the Tracks opt-out cookie.
	 * @param string              $event_name              Page view queued.
	 * @param array<string,mixed> $properties              Page view properties.
	 * @param bool                $expect_footer           Whether the footer script is hooked.
	 * @param bool|null           $expect_tracking_enabled Expected shopper tracking answer, or null when the row does not check it.
	 * @param bool                $runs_footer_script      Whether the row also runs the footer callback and checks no script loads.
	 */
	public function test_queue_user_event_follows_the_store_wide_checks( string $allow_tracking, bool $gateway_enabled, bool $woopay_enabled, bool $opt_out_cookie, string $event_name, array $properties, bool $expect_footer, ?bool $expect_tracking_enabled, bool $runs_footer_script ): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', $allow_tracking );
		if ( $opt_out_cookie ) {
			$_COOKIE['tk_opt-out'] = 'yes';
		}
		$sut = $this->create_controller( $this->create_account_service( true, $gateway_enabled, $woopay_enabled ) );

		$sut->queue_user_event( $event_name, $properties );
		if ( $runs_footer_script ) {
			$sut->enqueue_frontend_events_script();
		}

		try {
			$this->assertSame( $expect_footer ? 10 : false, has_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) ) );
		} finally {
			remove_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) );
		}
		if ( $runs_footer_script ) {
			$this->assertFalse( wp_script_is( 'wc-woopayments-frontend-tracks', 'enqueued' ) );
		}
		if ( null !== $expect_tracking_enabled ) {
			$this->assertSame( $expect_tracking_enabled, $sut->is_shopper_event_tracking_enabled() );
		}
	}

	/**
	 * Store-wide and per-visitor states with whether a page view is queued.
	 *
	 * @return array<string,array{string,bool,bool,bool,string,array<string,mixed>,bool,bool|null,bool}>
	 */
	public function provider_page_view_queue_gates(): array {
		return array(
			'store tracking off' => array( 'no', true, true, false, 'product_page_view', array( 'theme_type' => 'short_code' ), false, null, true ),
			'gateway disabled'   => array( 'yes', false, true, false, 'order_success_page_view', array( 'theme_type' => 'blocks' ), false, false, false ),
			'WooPay off'         => array( 'yes', true, false, false, 'cart_page_view', array( 'theme_type' => 'blocks' ), true, true, false ),
			'opt-out cookie'     => array( 'yes', true, true, true, 'product_page_view', array( 'theme_type' => 'short_code' ), true, false, false ),
		);
	}

	/**
	 * @testdox Should arm no cart click tracking, and not ask about direct checkout, when WooPay is off.
	 */
	public function test_proceed_to_checkout_tracking_is_a_no_op_when_woopay_is_off(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_gateway_enabled' ) )
			->getMock();
		$account_service->method( 'is_gateway_enabled' )->willReturn( true );
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'platform_checkout_eligible' => true ) );
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$sut          = $this->create_controller( $account_service );
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
		$sut = $this->create_controller();

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
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $params['tracksUrl'] );
		$this->assertArrayHasKey( 'restNonce', $params );
		$this->assertArrayNotHasKey( 'ajaxUrl', $params );
	}

	/**
	 * The `get_identity` answer is the JSON success envelope client 11.1.0 sends (`class-woopay-tracker.php:128-133`) and
	 * the shopper Tracks script reads as `data._ui` and `data._ut` (`client/blocks/assets/js/extensions/payment-methods/woopayments/tracks.js:81-95`).
	 *
	 * @testdox Should answer get_identity with the Jetpack identity in a JSON success envelope.
	 */
	public function test_tracks_identity_prefers_jetpack_identity_meta(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, 'jetpack_tracks_anon_id', 'jetpack:anon-id' );
		wp_set_current_user( $user_id );

		$response = $this->capture_ajax_json( array( $this->create_controller(), 'handle_tracks_identity' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'_ut' => 'anon',
					'_ui' => 'jetpack:anon-id',
				),
			),
			$response
		);
		$this->assertSame( '', get_user_meta( $user_id, '_woocommerce_tracks_anon_id', true ) );
	}

	/**
	 * Run an AJAX handler that answers with wp_send_json() and return the decoded body.
	 *
	 * @param callable $handler AJAX handler.
	 * @return mixed
	 */
	private function capture_ajax_json( callable $handler ) {
		$die_handler = static function () {
			return static function (): void {
				throw new \WPAjaxDieContinueException();
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			$handler();
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} finally {
			$body = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		return json_decode( $body, true );
	}

	/**
	 * @testdox Should drop properties the event does not declare and values outside a declared set.
	 */
	public function test_tracks_request_keeps_only_declared_properties(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );

		$button   = $this->record_through_tracks_request(
			$this->create_controller(),
			'woopay_button_click',
			array(
				'source' => 'checkout',
				'email'  => 'shopper@example.com',
			)
		);
		$save     = $this->record_through_tracks_request( $this->create_controller(), 'checkout_save_my_info_click', array( 'status' => 'maybe' ) );
		$checkout = $this->record_through_tracks_request(
			$this->create_controller(),
			'checkout_page_view',
			array(
				'theme_type'     => 'blocks',
				'woopay_enabled' => 'true',
			)
		);

		$this->assertSame( 'checkout', $button['events'][0]->source );
		$this->assertObjectNotHasProperty( 'email', $button['events'][0] );
		$this->assertObjectNotHasProperty( 'status', $save['events'][0] );
		$this->assertSame( 'blocks', $checkout['events'][0]->theme_type );
		$this->assertTrue( (bool) $checkout['events'][0]->woopay_enabled );
	}

	/**
	 * Page views are recorded on every store (client PR 6870, PR 8821); WooPay events only while WooPay is on, and a
	 * browser cannot claim otherwise through `record_event_data`.
	 *
	 * @testdox Should record a page view but refuse a WooPay event while WooPay is off, whatever the browser claims.
	 */
	public function test_tracks_request_applies_the_per_event_gate_while_woopay_is_off(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$account_service = $this->create_account_service( true, true, false );

		$page_view = $this->record_through_tracks_request( $this->create_controller( $account_service ), 'cart_page_view', array( 'theme_type' => 'blocks' ) );
		$woopay    = $this->record_through_tracks_request(
			$this->create_controller( $account_service ),
			'woopay_button_click',
			array(
				'source'            => 'checkout',
				'record_event_data' => array(
					'is_admin_event'      => true,
					'track_on_all_stores' => true,
				),
			)
		);

		$this->assertSame( 'wcpay_cart_page_view', $page_view['events'][0]->_en ?? null );
		$this->assertSame( array(), $woopay['events'] );
	}

	/**
	 * The `wcpay_shopper_tracking_enabled` filter is the first thing every recorder asks: once per event, before any
	 * account read, with the merchant's `woocommerce_allow_tracking` answer as its one argument. A refusal there stops
	 * the event before the account is read.
	 *
	 * @testdox Should run the shopper tracking filter once, first, with the tracking setting ($recorder, tracking $allow_tracking).
	 * @dataProvider provider_tracking_filter_reads
	 *
	 * @param string   $recorder       Recorder called.
	 * @param string   $allow_tracking The woocommerce_allow_tracking option.
	 * @param string[] $expected_names Event names recorded.
	 */
	public function test_tracking_filter_runs_once_before_the_account_reads( string $recorder, string $allow_tracking, array $expected_names ): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', $allow_tracking );
		$reads = new \ArrayObject();
		add_filter(
			'wcpay_shopper_tracking_enabled',
			static function ( ...$args ) use ( $reads ) {
				$reads[] = 'filter ' . wp_json_encode( $args );

				return $args[0];
			},
			10,
			5
		);
		$sut = $this->create_controller( $this->create_logging_account_service( $reads ) );
		\WC_Tracks_Footer_Pixel::clear_events();

		switch ( $recorder ) {
			case 'every-store event':
				$sut->record_user_event( 'cart_page_view', array( 'record_event_data' => array( 'track_on_all_stores' => true ) ) );
				break;
			case 'WooPay event':
				$sut->record_user_event( 'woopay_button_click', array( 'source' => 'checkout' ) );
				break;
			case 'WooPay admin event':
				$sut->record_woopay_admin_event( 'woopay_enabled', array( 'test_mode' => 1 ) );
				break;
			case 'queued page view':
				$sut->queue_user_event( 'product_page_view', array( 'theme_type' => 'blocks' ) );
				break;
		}
		$names = array_map( static fn( $event ) => $event->_en ?? null, \WC_Tracks_Footer_Pixel::get_events() );
		\WC_Tracks_Footer_Pixel::clear_events();

		$reads       = $reads->getArrayCopy();
		$filter_runs = array_values( preg_grep( '/^filter /', $reads ) );
		$this->assertSame( array( 'filter ' . wp_json_encode( array( 'no' !== $allow_tracking ) ) ), $filter_runs, 'The filter runs once, with the tracking setting.' );
		$this->assertSame( $filter_runs[0], $reads[0], 'The filter runs before any account read.' );
		if ( 'no' === $allow_tracking ) {
			$this->assertCount( 1, $reads, 'A refusal reads no account data.' );
		}
		$this->assertSame( $expected_names, $names );
	}

	/**
	 * Recorders with the events they record, tracking on and off.
	 *
	 * @return array<string,array{string,string,string[]}>
	 */
	public function provider_tracking_filter_reads(): array {
		return array(
			'every-store event, on'   => array( 'every-store event', 'yes', array( 'wcpay_cart_page_view' ) ),
			'every-store event, off'  => array( 'every-store event', 'no', array() ),
			'WooPay event, on'        => array( 'WooPay event', 'yes', array( 'wcpay_woopay_button_click' ) ),
			'WooPay event, off'       => array( 'WooPay event', 'no', array() ),
			'WooPay admin event, on'  => array( 'WooPay admin event', 'yes', array( 'wcadmin_woopay_enabled' ) ),
			'WooPay admin event, off' => array( 'WooPay admin event', 'no', array() ),
			'queued page view, on'    => array( 'queued page view', 'yes', array() ),
			'queued page view, off'   => array( 'queued page view', 'no', array() ),
		);
	}

	/**
	 * @testdox Should not record a WooPay admin event for a visitor who opted out of Tracks.
	 */
	public function test_woopay_admin_event_respects_the_tracks_opt_out_cookie(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$_COOKIE['tk_opt-out'] = '1';
		$sut                   = $this->create_controller( $this->create_account_service( true ) );
		\WC_Tracks_Footer_Pixel::clear_events();

		$this->assertFalse( $sut->record_woopay_admin_event( 'woopay_enabled', array( 'test_mode' => 1 ) ) );
		$this->assertSame( array(), \WC_Tracks_Footer_Pixel::get_events() );
	}

	/**
	 * Client 11.1.0 sends `wcpay_proceed_to_checkout_button_click` (cart/index.js:10) and its recorder adds `wcpay_`
	 * again; native records the single-prefixed name (the event source scheme the owner approved on 2026-10-07). A page
	 * cached before the change still sends the doubled name, which records under the corrected one.
	 *
	 * @testdox Should record the cart Proceed to checkout click under its single-prefixed name.
	 * @dataProvider provider_proceed_to_checkout_sent_names
	 *
	 * @param string $sent_name Event name the browser sends.
	 */
	public function test_tracks_request_records_proceed_to_checkout_single_prefixed( string $sent_name ): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allow_tracking', 'yes' );

		$recorded = $this->record_through_tracks_request( $this->create_controller(), $sent_name, array( 'woopay_direct_checkout' => false ) );

		$this->assertSame( 'wcpay_proceed_to_checkout_button_click', $recorded['events'][0]->_en ?? null );
	}

	/**
	 * Proceed to checkout names the browser may send.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_proceed_to_checkout_sent_names(): array {
		return array(
			'current script'     => array( 'proceed_to_checkout_button_click' ),
			'page cached before' => array( 'wcpay_proceed_to_checkout_button_click' ),
		);
	}

	/**
	 * Build a fresh REST server, which fires rest_api_init, and return its routes.
	 *
	 * @return array<string,mixed>
	 */
	private function get_rest_routes_after_init(): array {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server runs rest_api_init again.

		return rest_get_server()->get_routes();
	}

	/**
	 * Post an event to the shopper recorder and collect what it records into core's footer pixel queue.
	 *
	 * @param WooPaymentsFrontendTrackingController $sut        Controller under test.
	 * @param string                                $event_name Event name the browser sends.
	 * @param array<string,mixed>                   $properties Event properties the browser sends.
	 * @return array{response:array<string,mixed>,events:array<int,\WC_Tracks_Event>}
	 */
	private function record_through_tracks_request( WooPaymentsFrontendTrackingController $sut, string $event_name, array $properties ): array {
		\WC_Tracks_Footer_Pixel::clear_events();
		$response = $sut->record_tracks_request(
			array(
				'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksEventName' => $event_name,
				'tracksEventProp' => wp_json_encode( $properties ),
			)
		);
		$events   = \WC_Tracks_Footer_Pixel::get_events();
		\WC_Tracks_Footer_Pixel::clear_events();

		return array(
			'response' => $response,
			'events'   => $events,
		);
	}

	/**
	 * @testdox Should track classic and Blocks checkout page views once with the exact WooPayments contract.
	 */
	public function test_tracks_classic_and_blocks_checkout_page_views_once(): void {
		$recorded_events = array();
		// The emitters are mocked: this pins which events the funnel hooks send, and the emitter tests pin how they record.
		$sut = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_event_tracking_enabled', 'queue_user_event' ) )
			->getMock();
		$sut->method( 'is_shopper_event_tracking_enabled' )->willReturn( true );
		$sut->method( 'queue_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): void {
				$recorded_events[] = array( $event_name, $properties );
			}
		);

		$sut->init( $this->create_account_service( true ) );
		wc_get_container()->replace( WooPaymentsWooPayEligibility::class, $this->create_woopay_eligibility( true ) );

		try {
			$sut->register();
			$this->assertSame( 10, has_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) ) );
			$this->assertSame( 10, has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) ) );
			$sut->record_classic_checkout_page_view();
			$sut->record_blocks_checkout_page_view();
		} finally {
			remove_action( 'woocommerce_after_checkout_form', array( $sut, 'record_classic_checkout_page_view' ) );
			remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $sut, 'record_blocks_checkout_page_view' ) );
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
	 * @testdox Should track the WooPayments extension's shopper funnel on the cart, product and pay-for-order hooks and on WooPay sign-up.
	 */
	public function test_tracks_shopper_funnel_events_on_their_hooks(): void {
		$recorded_events = array();
		// The emitters are mocked: this pins which events the funnel hooks send, and the emitter tests pin how they record.
		$sut = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'queue_user_event', 'record_user_event', 'track_proceed_to_checkout_clicks' ) )
			->getMock();
		$sut->method( 'queue_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties = array() ) use ( &$recorded_events ): void {
				$recorded_events[] = array( 'queued', $event_name, $properties );
			}
		);
		$sut->method( 'track_proceed_to_checkout_clicks' )->willReturnCallback(
			static function ( callable $is_direct_checkout_enabled ) use ( &$recorded_events ): void {
				$recorded_events[] = array( 'armed', 'proceed_to_checkout_button_click', array( 'woopay_direct_checkout' => $is_direct_checkout_enabled() ) );
			}
		);
		$sut->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				$recorded_events[] = array( 'recorded', $event_name, $properties );
				return true;
			}
		);
		$sut->init( $this->create_account_service( true ) );
		wc_get_container()->replace( WooPaymentsWooPayEligibility::class, $this->create_woopay_eligibility( true, true ) );

		try {
			$sut->register();
			foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form', 'woocommerce_payments_save_user_in_woopay' ) as $hook ) {
				$this->assertSame( 10, has_action( $hook, array( $sut, 'record_shopper_funnel_event' ) ) );
				$sut->record_shopper_funnel_event(); // Outside the hook: no event.
				do_action( $hook ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			}
		} finally {
			foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form', 'woocommerce_payments_save_user_in_woopay' ) as $hook ) {
				remove_action( $hook, array( $sut, 'record_shopper_funnel_event' ) );
			}
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
	 * @testdox Should queue guest page views for the footer script instead of recording them during render.
	 */
	public function test_page_views_render_without_recording_and_queue_for_the_footer_script(): void {
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

		$tracker = $this->create_controller();
		wc_get_container()->replace( WooPaymentsWooPayEligibility::class, $this->create_woopay_eligibility( false ) );

		try {
			$tracker->register();
			foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form' ) as $hook ) {
				do_action( $hook ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			}
			$tracker->record_classic_checkout_page_view();
			$tracker->record_blocks_checkout_page_view();

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
		}

		$this->assertSame( 1, preg_match( '/^var wc_woopayments_frontend_tracks_params = (\{.*\});$/s', $localized, $matches ) );
		$params = json_decode( $matches[1], true );
		$this->assertSame( rest_url( 'wc/v3/payments/tracks' ), $params['tracksUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $params['nonce'], 'platform_tracks_nonce' ) );
		$this->assertSame(
			array(
				array(
					'event'      => 'cart_page_view',
					'properties' => array( 'theme_type' => 'short_code' ),
				),
				array(
					'event'      => 'cart_page_view',
					'properties' => array( 'theme_type' => 'blocks' ),
				),
				array(
					'event'      => 'product_page_view',
					'properties' => array( 'theme_type' => 'short_code' ),
				),
				array(
					'event'      => 'pay_for_order_page_view',
					'properties' => array(),
				),
				array(
					'event'      => 'checkout_page_view',
					'properties' => array(
						'theme_type'     => 'short_code',
						'woopay_enabled' => false,
					),
				),
				array(
					'event'      => 'checkout_page_view',
					'properties' => array(
						'theme_type'     => 'blocks',
						'woopay_enabled' => false,
					),
				),
			),
			$params['events']
		);
	}

	/**
	 * @testdox Should arm the Blocks cart Proceed to checkout click in the footer script without building the WooPay config (the WooPayments extension cart/index.js).
	 * @dataProvider direct_checkout_provider
	 *
	 * @param bool $direct_checkout_enabled Whether WooPay direct checkout is enabled.
	 */
	public function test_blocks_cart_arms_proceed_to_checkout_tracking_without_woopay_config( bool $direct_checkout_enabled ): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		wp_set_current_user( 0 );

		$tracker = $this->create_controller();
		$woopay  = $this->getMockBuilder( WooPaymentsWooPayEligibility::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_direct_checkout_enabled' ) )
			->getMock();
		$woopay->expects( $this->once() )->method( 'is_woopay_direct_checkout_enabled' )->willReturn( $direct_checkout_enabled );
		wc_get_container()->replace( WooPaymentsWooPayEligibility::class, $woopay );
		$session = $this->getMockBuilder( WooPaymentsWooPaySessionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_frontend_config' ) )
			->getMock();
		$session->expects( $this->never() )->method( 'get_woopay_frontend_config' );
		wc_get_container()->replace( WooPaymentsWooPaySessionService::class, $session );

		try {
			$tracker->register();
			do_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			$tracker->enqueue_frontend_events_script();
			$localized = (string) wp_scripts()->get_data( 'wc-woopayments-frontend-tracks', 'data' );
		} finally {
			remove_action( 'wp_footer', array( $tracker, 'enqueue_frontend_events_script' ) );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			wp_deregister_script( 'wc-woopayments-frontend-tracks' );
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
		$recorded_events = array();
		// The emitters are mocked: this pins which events the funnel hooks send, and the emitter tests pin how they record.
		$sut = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_event_tracking_enabled', 'record_user_event' ) )
			->getMock();
		$sut->method( 'is_shopper_event_tracking_enabled' )->willReturn( true );
		$sut->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				$recorded_events[] = array( $event_name, $properties );
				return true;
			}
		);

		$sut->init( $this->create_account_service( true ) );
		wc_get_container()->replace( WooPaymentsWooPayEligibility::class, $this->create_woopay_eligibility( false ) );

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
			$this->assertSame( 10, has_action( 'woocommerce_checkout_order_processed', array( $sut, 'record_checkout_order_placed' ) ) );
			$this->assertSame( 10, has_action( 'woocommerce_store_api_checkout_order_processed', array( $sut, 'record_checkout_order_placed' ) ) );

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
	 * Client 11.1.0 `checkout_order_processed()` counts an order paid with another gateway on the WordPress.com stats pixel
	 * (`includes/class-woopay-tracker.php:581-602`, `bump_stats()` at :545-574); native does not wait for the answer.
	 *
	 * @testdox Should send one stats counter request, carrying nothing but its URL and without waiting for the answer, for an order paid with another gateway.
	 * @dataProvider order_processed_action_provider
	 *
	 * @param string $hook           Order-processed action.
	 * @param string $payment_method Gateway ID of the order; empty for a free order.
	 * @param bool   $admin_ajax     Whether checkout runs in an admin-ajax request.
	 */
	public function test_counts_an_order_paid_with_another_gateway_once_without_waiting( string $hook, string $payment_method, bool $admin_ajax ): void {
		$requests = $this->capture_http_requests();
		$sut      = $this->arrange_counter_eligible_store( $this->create_account_service( false ) );
		$order    = $this->create_order_paid_with( $payment_method, '' === $payment_method ? '' : 'Cash on delivery' );
		if ( $admin_ajax ) {
			// Client 11.1.0 tracks admin-ajax requests by shoppers (`includes/class-woopay-tracker.php:276-283`).
			set_current_screen( 'dashboard' );
			add_filter( 'wp_doing_ajax', '__return_true' );
		}

		$sut->register();
		$this->fire_order_processed( $hook, $order );

		// The pixel URL client 11.1.0 builds (`includes/class-woopay-tracker.php:50,557-561`).
		$this->assertSame( array( 'https://pixel.wp.com/g.gif?v=wpcom-no-pv&x_wcpay_order_completed_gateway=other' ), array_column( $requests->getArrayCopy(), 'url' ), 'One counter request' );
		$this->assertFalse( $requests[0]['blocking'], 'Checkout does not wait for the answer' );
		$this->assertSame( 1, $requests[0]['timeout'], 'One-second timeout' );
		// WP_Http::request() defaults (wp-includes/class-wp-http.php): no headers, body or cookies are added.
		$this->assertSame( array(), $requests[0]['headers'], 'No headers' );
		$this->assertNull( $requests[0]['body'], 'No body' );
		$this->assertSame( array(), $requests[0]['cookies'], 'No cookies' );
	}

	/**
	 * Both order-processed actions client 11.1.0 counts from (`includes/class-woopay-tracker.php:78-79`).
	 *
	 * Client 11.1.0 counts any order its gateway lookup does not match to WooPayments, a free order with no payment method
	 * included (`includes/class-woopay-tracker.php:583-600`).
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function order_processed_action_provider(): array {
		return array(
			'classic checkout'                  => array( 'woocommerce_checkout_order_processed', 'cod', false ),
			'Store API checkout'                => array( 'woocommerce_store_api_checkout_order_processed', 'cod', false ),
			'free order with no payment method' => array( 'woocommerce_checkout_order_processed', '', false ),
			'admin-ajax checkout by a shopper'  => array( 'woocommerce_checkout_order_processed', 'cod', true ),
		);
	}

	/**
	 * @testdox Should finish the order-processed action when sending the stats counter throws.
	 */
	public function test_a_failing_counter_request_does_not_interrupt_order_processing(): void {
		$sut   = $this->arrange_counter_eligible_store( $this->create_account_service( false ) );
		$order = $this->create_order_paid_with( 'cod', 'Cash on delivery' );
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'Transport failure.' );
			}
		);
		$later_callback_ran = false;
		add_action(
			'woocommerce_checkout_order_processed',
			static function () use ( &$later_callback_ran ): void {
				$later_callback_ran = true;
			},
			20
		);

		$sut->register();
		$this->fire_order_processed( 'woocommerce_checkout_order_processed', $order );

		$this->assertTrue( $later_callback_ran );
	}

	/**
	 * @testdox Should send no stats counter for an order paid with WooPayments ($payment_method).
	 * @testWith ["woocommerce_payments"]
	 *           ["woocommerce_payments_klarna"]
	 *
	 * @param string $payment_method WooPayments gateway ID.
	 */
	public function test_sends_no_counter_for_an_order_paid_with_woopayments( string $payment_method ): void {
		$requests = $this->capture_http_requests();
		$sut      = $this->arrange_counter_eligible_store( $this->create_account_service( false ) );
		$order    = $this->create_order_paid_with( $payment_method, 'WooPayments' );

		$sut->register();
		$this->fire_order_processed( 'woocommerce_checkout_order_processed', $order );
		\WC_Tracks_Footer_Pixel::clear_events();

		$counter_requests = array_filter(
			array_column( $requests->getArrayCopy(), 'url' ),
			static fn( string $url ): bool => 0 === strpos( $url, 'https://pixel.wp.com/g.gif' )
		);
		$this->assertSame( array(), $counter_requests );
	}

	/**
	 * Each row turns off one of the client 11.1.0 gates (`should_enable_tracking( false, true )`, `includes/class-woopay-tracker.php:228-287`, and
	 * live mode, :553-555) on a store where the counter is sent.
	 *
	 * @testdox Should send no stats counter for an order paid with another gateway when the $gate check fails.
	 * @dataProvider counter_gate_provider
	 *
	 * @param string $gate Gate turned off.
	 */
	public function test_sends_no_counter_when_a_gate_is_off( string $gate ): void {
		$requests = $this->capture_http_requests();
		$sut      = $this->arrange_counter_eligible_store( $this->create_account_service( 'test mode' === $gate, 'gateway enabled' !== $gate, true, 'account connected' !== $gate ) );
		switch ( $gate ) {
			case 'tracking option':
				update_option( 'woocommerce_allow_tracking', 'no' );
				break;
			case 'tracking filter':
				add_filter( 'wcpay_shopper_tracking_enabled', '__return_false' );
				break;
			case 'US store':
				update_option( 'woocommerce_default_country', 'DE:BE' );
				break;
			case 'opt-out cookie':
				$_COOKIE['tk_opt-out'] = '1';
				break;
			case 'front-end request':
				set_current_screen( 'dashboard' );
				break;
			case 'not an administrator':
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				break;
		}
		$order = $this->create_order_paid_with( 'cod', 'Cash on delivery' );

		$sut->register();
		$this->fire_order_processed( 'woocommerce_checkout_order_processed', $order );

		$this->assertSame( array(), $requests->getArrayCopy() );
	}

	/**
	 * The client 11.1.0 counter gates, by name.
	 *
	 * @return array<string,array{string}>
	 */
	public function counter_gate_provider(): array {
		$gates = array( 'tracking option', 'tracking filter', 'gateway enabled', 'account connected', 'US store', 'opt-out cookie', 'front-end request', 'not an administrator', 'test mode' );

		return array_combine( $gates, array_map( static fn( string $gate ): array => array( $gate ), $gates ) );
	}

	/**
	 * Record every outgoing HTTP request without sending it.
	 *
	 * @return \ArrayObject<int,array{url:string,blocking:mixed,timeout:mixed,headers:mixed,body:mixed,cookies:mixed}>
	 */
	private function capture_http_requests(): \ArrayObject {
		$requests = new \ArrayObject();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( $requests ) {
				unset( $preempt ); // Avoid parameter not used PHPCS errors.
				$requests[] = array(
					'url'      => $url,
					'blocking' => $parsed_args['blocking'],
					'timeout'  => $parsed_args['timeout'],
					'headers'  => $parsed_args['headers'],
					'body'     => $parsed_args['body'],
					'cookies'  => $parsed_args['cookies'],
				);

				return new \WP_Error( 'http_request_blocked', 'Blocked in tests.' );
			},
			10,
			3
		);

		return $requests;
	}

	/**
	 * Set up a US store that allows tracking, with a guest shopper, where the stats counter is sent.
	 *
	 * @param WooPaymentsAccountService $account_service Account service double.
	 * @return WooPaymentsFrontendTrackingController
	 */
	private function arrange_counter_eligible_store( WooPaymentsAccountService $account_service ): WooPaymentsFrontendTrackingController {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		wp_set_current_user( 0 );

		return $this->create_controller( $account_service );
	}

	/**
	 * Create a checkout order paid with a gateway.
	 *
	 * @param string $payment_method Gateway ID.
	 * @param string $title          Gateway title.
	 * @return \WC_Order
	 */
	private function create_order_paid_with( string $payment_method, string $title ): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_payment_method_title( $title );
		$order->save();

		return $order;
	}

	/**
	 * Fire an order-processed action with the argument checkout passes: the order ID on classic, the order on the Store API.
	 *
	 * @param string    $hook  Order-processed action.
	 * @param \WC_Order $order Checkout order.
	 */
	private function fire_order_processed( string $hook, \WC_Order $order ): void {
		if ( 'woocommerce_store_api_checkout_order_processed' === $hook ) {
			/** This action is documented in src/StoreApi/Routes/V1/Checkout.php */
			do_action( 'woocommerce_store_api_checkout_order_processed', $order );
			return;
		}

		/** This action is documented in includes/class-wc-checkout.php */
		do_action( 'woocommerce_checkout_order_processed', $order->get_id() );
	}

	/**
	 * Client 11.1.0 `should_enable_tracking()` checks `is_stripe_connected()` (`includes/class-woopay-tracker.php:248-252`):
	 * any cached account counts, onboarded or not, and an errored refresh serves the earlier account
	 * (`includes/class-database-cache.php:176-179`); only no account, or an errored cache with no account, sends nothing.
	 *
	 * @testdox Should send shopper events for a connected account, onboarded or not, and none without an account.
	 * @dataProvider connected_account_provider
	 *
	 * @param bool   $earlier_account Whether an expired account cache holds an earlier account.
	 * @param string $error_code      Error code the platform answers with, or '' to return the account.
	 * @param bool   $expected_sent   Whether the shopper events are sent.
	 */
	public function test_shopper_events_follow_a_connected_account( bool $earlier_account, string $error_code, bool $expected_sent ): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		delete_option( 'wcpay_account_data' );
		if ( $earlier_account ) {
			// The client's database cache wrapper, expired so the read refreshes (`includes/class-database-cache.php`).
			update_option(
				'wcpay_account_data',
				array(
					'data'    => $this->get_partially_onboarded_account(),
					'fetched' => time() - 2 * DAY_IN_SECONDS,
					'errored' => false,
				)
			);
		}
		wp_set_current_user( 0 );
		$sut = $this->create_controller( $this->create_account_service_on_platform( $this->get_partially_onboarded_account(), $error_code ) );
		wc_get_container()->replace( WooPaymentsWooPayEligibility::class, $this->create_woopay_eligibility( false ) );
		$order = wc_create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_payment_method_title( 'Card' );
		$order->save();
		\WC_Tracks_Footer_Pixel::clear_events();

		try {
			$sut->register();

			// The page view the product page queues for the footer script.
			do_action( 'woocommerce_after_single_product' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			$sut->enqueue_frontend_events_script();
			$localized = (string) wp_scripts()->get_data( 'wc-woopayments-frontend-tracks', 'data' );

			// The page view the footer script posts to the shopper Tracks route.
			$this->get_rest_routes_after_init();
			$request = new \WP_REST_Request( 'POST', '/wc/v3/payments/tracks' );
			$request->set_body_params(
				array(
					'tracksNonce'     => wp_create_nonce( 'platform_tracks_nonce' ),
					'tracksEventName' => 'product_page_view',
					'tracksEventProp' => wp_json_encode( array( 'theme_type' => 'short_code' ) ),
				)
			);
			rest_get_server()->dispatch( $request );
			$page_view_events = \WC_Tracks_Footer_Pixel::get_events();
			\WC_Tracks_Footer_Pixel::clear_events();

			/** This action is documented in includes/class-wc-checkout.php */
			do_action( 'woocommerce_checkout_order_processed', $order->get_id() );
			$order_events = \WC_Tracks_Footer_Pixel::get_events();
		} finally {
			\WC_Tracks_Footer_Pixel::clear_events();
			remove_action( 'wp_footer', array( $sut, 'enqueue_frontend_events_script' ) );
			wp_dequeue_script( 'wc-woopayments-frontend-tracks' );
			wp_deregister_script( 'wc-woopayments-frontend-tracks' );
		}

		$queued = array();
		if ( 1 === preg_match( '/^var wc_woopayments_frontend_tracks_params = (\{.*\});$/s', $localized, $matches ) ) {
			$queued = array_column( json_decode( $matches[1], true )['events'], 'event' );
		}
		$this->assertSame( $expected_sent ? array( 'product_page_view' ) : array(), $queued, 'Queued page views' );
		$this->assertSame( $expected_sent ? array( 'wcpay_product_page_view' ) : array(), array_column( $page_view_events, '_en' ), 'Recorded page views' );
		$this->assertSame( $expected_sent ? array( 'wcpay_checkout_order_placed' ) : array(), array_column( $order_events, '_en' ), 'Order-placed events' );
		if ( $expected_sent ) {
			$this->assertSame( 'Card', $order_events[0]->payment_title );
		}
	}

	/**
	 * Account cache states and platform answers.
	 *
	 * @return array<string,array{bool,string,bool}>
	 */
	public function connected_account_provider(): array {
		return array(
			'partially onboarded account'                => array( false, '', true ),
			'errored refresh with an earlier account'    => array( true, 'wcpay_temporary_failure', true ),
			'errored cache with no account'              => array( false, 'wcpay_temporary_failure', false ),
			'no account, the platform answers not found' => array( false, 'wcpay_account_not_found', false ),
		);
	}

	/**
	 * A connected account that has not finished onboarding.
	 *
	 * The recorded account response (Fixtures/platform-test-drive-account.json, REC-T60-2) as a live account whose details
	 * are not submitted, so it cannot take payments yet.
	 *
	 * @return array<string,mixed>
	 */
	private function get_partially_onboarded_account(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-test-drive-account.json' ), true );

		return array_merge(
			$fixture['account'],
			array(
				'is_live'           => true,
				'payments_enabled'  => false,
				'details_submitted' => false,
			)
		);
	}

	/**
	 * Create a real account service whose platform transport returns an account or fails with an error code.
	 *
	 * @param array<string,mixed> $account    Account the platform returns.
	 * @param string              $error_code Error code the platform fails with, or '' to return the account.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_on_platform( array $account, string $error_code ): WooPaymentsAccountService {
		$api_client = new class( $account, $error_code ) extends WooPaymentsApiClient {
			/**
			 * Account the platform returns.
			 *
			 * @var array<string,mixed>
			 */
			private array $account;

			/**
			 * Error code the platform fails with.
			 *
			 * @var string
			 */
			private string $error_code;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account    Account the platform returns.
			 * @param string              $error_code Error code the platform fails with.
			 */
			public function __construct( array $account, string $error_code ) {
				$this->account    = $account;
				$this->error_code = $error_code;
			}

			/**
			 * Tell whether the site has a platform connection.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the account or fail.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When set to fail.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				// Account-not-found becomes no account; a temporary failure becomes a failed read (`includes/class-wc-payments-account.php:2464-2477`, where onboarding-disabled also becomes no account).
				if ( 'wcpay_account_not_found' === $this->error_code ) {
					throw new WooPaymentsApiException( 'Account not found.', 'wcpay_account_not_found', 404 );
				}
				if ( '' !== $this->error_code ) {
					throw new WooPaymentsApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
				}

				return $this->account;
			}
		};

		$account_service = new class( $api_client ) extends WooPaymentsAccountService {
			/**
			 * Fake platform transport.
			 *
			 * @var WooPaymentsApiClient
			 */
			private WooPaymentsApiClient $api_client;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsApiClient $api_client Fake platform transport.
			 */
			public function __construct( WooPaymentsApiClient $api_client ) {
				$this->api_client = $api_client;
			}

			/**
			 * Get the fake platform transport.
			 *
			 * @return WooPaymentsApiClient
			 */
			protected function get_api_client(): WooPaymentsApiClient {
				return $this->api_client;
			}
		};
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service->init( new LegacyProxy(), $database_cache );

		return $account_service;
	}

	/**
	 * Create the controller under test.
	 *
	 * @param WooPaymentsAccountService|null $account_service Account service double.
	 * @return WooPaymentsFrontendTrackingController
	 */
	private function create_controller( ?WooPaymentsAccountService $account_service = null ): WooPaymentsFrontendTrackingController {
		$sut = new WooPaymentsFrontendTrackingController();
		$sut->init( $account_service ?? $this->create_account_service( true ) );

		return $sut;
	}

	/**
	 * Create a WooPay eligibility double for the checkout funnel events.
	 *
	 * @param bool $enabled                 Whether WooPay is enabled.
	 * @param bool $direct_checkout_enabled Whether WooPay direct checkout is enabled.
	 * @return WooPaymentsWooPayEligibility
	 */
	private function create_woopay_eligibility( bool $enabled, bool $direct_checkout_enabled = false ): WooPaymentsWooPayEligibility {
		$service = $this->getMockBuilder( WooPaymentsWooPayEligibility::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_woopay_enabled', 'is_woopay_direct_checkout_enabled' ) )
			->getMock();
		$service->method( 'is_woopay_enabled' )->willReturn( $enabled );
		$service->method( 'is_woopay_direct_checkout_enabled' )->willReturn( $direct_checkout_enabled );

		return $service;
	}

	/**
	 * Create an account service double.
	 *
	 * @param bool $test_mode       Whether the account is in test mode.
	 * @param bool $gateway_enabled Whether the WooPayments gateway is enabled.
	 * @param bool $woopay_enabled  Whether WooPay is enabled.
	 * @param bool $connected       Whether an account is connected; without one the account cache reads empty.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode, bool $gateway_enabled = true, bool $woopay_enabled = true, bool $connected = true ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_test_mode_enabled', 'is_gateway_enabled' ) )
			->getMock();

		$account_service->method( 'is_gateway_enabled' )->willReturn( $gateway_enabled );
		$account_service->method( 'get_cached_account_data' )->willReturn(
			$connected
				// Two fields of the recorded account response (Fixtures/platform-test-drive-account.json, REC-T60-2).
				? array(
					'country'                    => 'US',
					'platform_checkout_eligible' => true,
				)
				: array()
		);
		$account_service->method( 'get_gateway_setting' )->willReturnMap(
			array(
				array( 'platform_checkout', 'no', $woopay_enabled ? 'yes' : 'no' ),
			)
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );

		return $account_service;
	}

	/**
	 * Create an account service on a connected, eligible US account with WooPay on that logs each read.
	 *
	 * @param \ArrayObject $reads Read log.
	 * @return WooPaymentsAccountService
	 */
	private function create_logging_account_service( \ArrayObject $reads ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_test_mode_enabled', 'is_gateway_enabled' ) )
			->getMock();
		$account_service->method( 'is_gateway_enabled' )->willReturnCallback(
			static function () use ( $reads ): bool {
				$reads[] = 'is_gateway_enabled';

				return true;
			}
		);
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( $reads ): array {
				$reads[] = 'get_cached_account_data';

				// Two fields of the recorded account response (Fixtures/platform-test-drive-account.json, REC-T60-2).
				return array(
					'country'                    => 'US',
					'platform_checkout_eligible' => true,
				);
			}
		);
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) use ( $reads ) {
				$reads[] = 'get_gateway_setting ' . $key;

				return 'platform_checkout' === $key ? 'yes' : $fallback;
			}
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturnCallback(
			static function () use ( $reads ): bool {
				$reads[] = 'is_test_mode_enabled';

				return true;
			}
		);

		return $account_service;
	}
}
