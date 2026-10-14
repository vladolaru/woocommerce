<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutAddressNormalizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the native WooPayments tokenized cart session controller.
 */
class WooPaymentsTokenizedCartSessionControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsTokenizedCartSessionController|null
	 */
	private $sut;

	/**
	 * Original WooCommerce session object.
	 *
	 * @var object|null
	 */
	private $original_session;

	/**
	 * Original request URI.
	 *
	 * @var string|null
	 */
	private ?string $original_request_uri = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_session     = WC()->session;
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : null;
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsTokenizedCartSessionController ) {
			remove_filter( 'woocommerce_session_handler', array( $this->sut, 'handle_woocommerce_session_handler' ), 20 );
			remove_filter( 'woocommerce_persistent_cart_enabled', array( $this->sut, 'disable_persistent_cart' ) );
			remove_filter( 'woocommerce_get_return_url', array( $this->sut, 'add_tokenized_cart_return_url_marker' ) );
			remove_filter( 'rest_post_dispatch', array( $this->sut, 'handle_store_api_response' ), 10 );
			remove_filter( 'rest_pre_dispatch', array( $this->sut, 'maybe_reject_invalid_tokenized_cart_session' ), 10 );
			remove_filter( 'rest_pre_dispatch', array( $this->sut, 'reject_invalid_tokenized_cart_session' ), 10 );
			remove_filter( 'woocommerce_should_clear_cart_after_payment', array( $this->sut, 'preserve_cart_after_tokenized_payment' ) );
		}

		WC()->session = $this->original_session;
		unset(
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_IS_EPHEMERAL_CART'],
			$_GET['rest_route']
		);
		$_SERVER['REQUEST_URI'] = $this->original_request_uri ?? '/';
		parent::tearDown();
	}

	/**
	 * @testdox Should register tokenized cart session hooks.
	 */
	public function test_registers_session_handler_filter(): void {
		$this->sut = $this->create_controller();
		$this->sut->register();

		$this->assertSame( 20, has_filter( 'woocommerce_session_handler', array( $this->sut, 'handle_woocommerce_session_handler' ) ) );
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', array( $this->sut, 'maybe_reject_invalid_tokenized_cart_session' ) ) );

		remove_filter( 'woocommerce_session_handler', array( $this->sut, 'handle_woocommerce_session_handler' ), 20 );
		remove_filter( 'rest_pre_dispatch', array( $this->sut, 'maybe_reject_invalid_tokenized_cart_session' ), 10 );
	}

	/**
	 * @dataProvider provider_store_api_request_shapes
	 * @testdox Should switch Store API requests with a valid product-page session nonce to the tokenized handler ($_dataName).
	 *
	 * @param string $set_request Name of the helper that shapes the Store API request.
	 */
	public function test_uses_tokenized_handler_for_store_api_request_with_valid_session_nonce( string $set_request ): void {
		$this->{$set_request}();
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'] = wp_create_nonce( 'woopayments_tokenized_cart_session_nonce' );
		$this->sut = $this->create_controller();

		$this->assertSame( WooPaymentsTokenizedCartSessionHandler::class, $this->sut->handle_woocommerce_session_handler( 'WC_Session_Handler' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_persistent_cart_enabled', array( $this->sut, 'disable_persistent_cart' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_return_url', array( $this->sut, 'add_tokenized_cart_return_url_marker' ) ) );
		$this->assertNotFalse( has_filter( 'rest_post_dispatch', array( $this->sut, 'handle_store_api_response' ) ) );
	}

	/**
	 * Store API request shapes: the pretty permalink path and the plain permalink rest_route query.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_store_api_request_shapes(): array {
		return array(
			'pretty permalinks' => array( 'set_store_api_request' ),
			'plain permalinks'  => array( 'set_query_store_api_request' ),
		);
	}

	/**
	 * @testdox Should leave normal WooCommerce sessions untouched when the product-page nonce is missing.
	 */
	public function test_keeps_default_handler_without_session_nonce(): void {
		$this->set_store_api_request();
		$this->sut = $this->create_controller();

		$this->assertSame( 'WC_Session_Handler', $this->sut->handle_woocommerce_session_handler( 'WC_Session_Handler' ) );
	}

	/**
	 * @testdox Should leave the session of a page request alone when only its query string holds the Store API path.
	 *
	 * WordPress routes /some-page/?redirect_to=/wp-json/wc/store/v1/cart to the page, not to the REST API, so the request
	 * keeps its session even with a valid product-page session nonce.
	 */
	public function test_keeps_default_handler_for_a_page_whose_query_string_holds_the_store_api_path(): void {
		$_SERVER['REQUEST_URI']                                     = '/some-page/?redirect_to=/wp-json/wc/store/v1/cart';
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'] = wp_create_nonce( 'woopayments_tokenized_cart_session_nonce' );
		$this->sut = $this->create_controller();

		$this->assertSame( 'WC_Session_Handler', $this->sut->handle_woocommerce_session_handler( 'WC_Session_Handler' ) );
		$this->assertFalse( has_filter( 'woocommerce_persistent_cart_enabled', array( $this->sut, 'disable_persistent_cart' ) ) );
	}

	/**
	 * @testdox Should isolate and reject Store API requests when the incoming tokenized session is invalid.
	 */
	public function test_rejects_invalid_session_token_without_using_default_handler(): void {
		$this->set_store_api_request();
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'] = wp_create_nonce( 'woopayments_tokenized_cart_session_nonce' );
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION']       = 'not-a-valid-token';
		$this->sut = $this->create_controller();
		$this->sut->register();

		$this->assertSame( WooPaymentsTokenizedCartSessionHandler::class, $this->sut->handle_woocommerce_session_handler( 'WC_Session_Handler' ) );
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', array( $this->sut, 'maybe_reject_invalid_tokenized_cart_session' ) ) );

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' ) );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @testdox Should leave non-tokenized Store API requests untouched during pre-dispatch.
	 */
	public function test_pre_dispatch_leaves_non_tokenized_requests_untouched(): void {
		$this->set_store_api_request();
		$this->sut = $this->create_controller();

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );

		$this->assertNull( $result );
	}

	/**
	 * @testdox Should reject tokenized Store API markers when the product-page session nonce is missing.
	 */
	public function test_rejects_tokenized_markers_without_session_nonce(): void {
		$this->set_store_api_request();
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'] = 'token-without-valid-nonce';
		$this->sut = $this->create_controller();

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' ) );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @testdox Should leave a REST request outside the Store API untouched during pre-dispatch, tokenized markers or not.
	 */
	public function test_pre_dispatch_leaves_other_rest_requests_untouched(): void {
		$_SERVER['REQUEST_URI']                               = '/wp-json/wc/v3/orders';
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'] = 'token-without-valid-nonce';
		$this->sut = $this->create_controller();

		$this->assertNull( $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, new WP_REST_Request( 'GET', '/wc/v3/orders' ) ) );
	}

	/**
	 * @testdox Should reject tokenized checkout markers when the product-page session nonce is invalid.
	 */
	public function test_rejects_tokenized_checkout_marker_with_invalid_session_nonce(): void {
		$this->set_store_api_request();
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'] = 'expired-session-nonce';
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART']               = 'true';
		$this->sut = $this->create_controller();

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @testdox Should normalize generic tokenized checkout Hong Kong addresses without a product session.
	 */
	public function test_normalizes_generic_tokenized_checkout_hong_kong_addresses_without_product_session(): void {
		$this->set_store_api_request();
		$this->sut = $this->create_controller();
		$request   = $this->create_generic_tokenized_store_api_request( 'POST', '/wc/store/v1/checkout', wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) );
		$request->set_param(
			'billing_address',
			array(
				'country'  => 'HK',
				'state'    => '',
				'postcode' => '',
				'city'     => 'Tai Po',
			)
		);

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, $request );

		$this->assertNull( $result );
		$billing_address = $request->get_param( 'billing_address' );
		$this->assertSame( 'NEW TERRITORIES', $billing_address['state'] );
	}

	/**
	 * @testdox Should normalize tokenized checkout address lines before Store API validation.
	 */
	public function test_normalizes_tokenized_checkout_address_lines_before_store_api_validation(): void {
		$this->set_store_api_request();
		$this->sut = $this->create_controller();
		$request   = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param(
			'billing_address',
			array(
				'country'   => 'DE',
				'address_1' => '',
				'address_2' => 'Meininger Strasse 58',
			)
		);

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, $request );

		$this->assertNull( $result );
		$billing_address = $request->get_param( 'billing_address' );
		$this->assertSame( 'Meininger Strasse 58', $billing_address['address_1'] );
		$this->assertSame( '', $billing_address['address_2'] );
	}

	/**
	 * @testdox Should preserve the shopper cart on tokenized product order-received redirects.
	 */
	public function test_preserves_cart_clear_after_payment_for_tokenized_order_received_redirect(): void {
		$_SERVER['REQUEST_URI'] = '/checkout/order-received/123/?woopayments-custom-session=1';

		$this->sut = $this->create_controller();
		$this->sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_should_clear_cart_after_payment', array( $this->sut, 'preserve_cart_after_tokenized_payment' ) ) );
		$this->assertFalse( $this->sut->preserve_cart_after_tokenized_payment() );
	}

	/**
	 * @testdox Should restore the shopper cart and session state after tokenized order-received empty-cart calls.
	 */
	public function test_restores_cart_session_state_after_tokenized_order_received_empty_cart(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->set_applied_coupons( array( 'save10' ) );
		WC()->cart->set_coupon_discount_totals( array( 'save10' => 5.0 ) );
		WC()->cart->set_coupon_discount_tax_totals( array( 'save10' => 1.0 ) );
		WC()->cart->set_totals(
			array(
				'subtotal' => 20,
				'total'    => 15,
			)
		);
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
		WC()->session->set( 'shipping_for_package_0', array( 'package_hash' => 'package-hash' ) );

		$this->sut = $this->create_controller();
		$this->sut->save_old_cart_data_for_restore();

		WC()->cart->empty_cart( false );
		$this->sut->restore_old_cart_data();

		$this->assertSame( 2, WC()->cart->get_cart_contents_count() );
		$this->assertSame( array( 'save10' ), WC()->cart->get_applied_coupons() );
		$this->assertSame( array( 'save10' => 5.0 ), WC()->cart->get_coupon_discount_totals() );
		$this->assertSame( array( 'save10' => 1.0 ), WC()->cart->get_coupon_discount_tax_totals() );
		$this->assertSame( 15, WC()->cart->get_totals()['total'] );
		$this->assertSame( array( 'flat_rate:1' ), WC()->session->get( 'chosen_shipping_methods' ) );
		$this->assertSame( array( 'package_hash' => 'package-hash' ), WC()->session->get( 'shipping_for_package_0' ) );
	}

	/**
	 * @testdox Should emit the next tokenized session header from Store API responses.
	 */
	public function test_emits_next_tokenized_session_header(): void {
		$this->set_store_api_request();
		WC()->session = new class() {
			/**
			 * Get the customer id.
			 *
			 * @return string
			 */
			public function get_customer_id(): string {
				return 't_native_product_session';
			}
		};

		$this->sut = $this->create_controller();

		$response = $this->sut->handle_store_api_response( new WP_REST_Response( array() ), null, new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'X-WooPayments-Tokenized-Cart-Session', $headers );
		$payload = JsonWebToken::get_parts( $headers['X-WooPayments-Tokenized-Cart-Session'] )->payload;
		$this->assertSame( 't_native_product_session', $payload->session_id );
		$this->assertSame( 'woopayments/product-page', $payload->iss );
	}

	/**
	 * Create the System Under Test.
	 *
	 * @return WooPaymentsTokenizedCartSessionController
	 */
	private function create_controller(): WooPaymentsTokenizedCartSessionController {
		$controller = new WooPaymentsTokenizedCartSessionController();
		$controller->init( new WooPaymentsExpressCheckoutAddressNormalizer() );

		return $controller;
	}

	/**
	 * Mark the current request as a Store API request.
	 */
	private function set_store_api_request(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
		$_GET['rest_route']     = '/wc/store/v1/cart';
	}

	/**
	 * Mark the current request as a plain-permalink Store API request.
	 */
	private function set_query_store_api_request(): void {
		$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcart';
		$_GET['rest_route']     = '/wc/store/v1/cart';
	}

	/**
	 * Create a tokenized Store API request.
	 *
	 * @param string $method Request method.
	 * @param string $route Request route.
	 * @return WP_REST_Request
	 */
	private function create_tokenized_store_api_request( string $method, string $route ): WP_REST_Request {
		$session_nonce   = wp_create_nonce( 'woopayments_tokenized_cart_session_nonce' );
		$tokenized_nonce = wp_create_nonce( 'woopayments_tokenized_cart_nonce' );

		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'] = $session_nonce;
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART']               = 'true';
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE']         = $tokenized_nonce;

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WooPayments-Tokenized-Cart-Session-Nonce', $session_nonce );
		$request->set_header( 'X-WooPayments-Tokenized-Cart', 'true' );
		$request->set_header( 'X-WooPayments-Tokenized-Cart-Nonce', $tokenized_nonce );

		return $request;
	}

	/**
	 * Create a generic tokenized-cart Store API request without a product-page session.
	 *
	 * @param string      $method          Request method.
	 * @param string      $route           Request route.
	 * @param string|null $tokenized_nonce Generic tokenized cart nonce.
	 * @return WP_REST_Request
	 */
	private function create_generic_tokenized_store_api_request( string $method, string $route, ?string $tokenized_nonce ): WP_REST_Request {
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART'] = 'true';

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WooPayments-Tokenized-Cart', 'true' );

		if ( null !== $tokenized_nonce ) {
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'] = $tokenized_nonce;
			$request->set_header( 'X-WooPayments-Tokenized-Cart-Nonce', $tokenized_nonce );
		}

		return $request;
	}
}
