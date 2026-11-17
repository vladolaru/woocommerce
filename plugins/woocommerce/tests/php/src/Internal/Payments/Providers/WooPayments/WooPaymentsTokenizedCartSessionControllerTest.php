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
	 * Original WooCommerce cart object.
	 *
	 * @var \WC_Cart|null
	 */
	private $original_cart;

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
		$this->original_cart        = WC()->cart;
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
		WC()->cart    = $this->original_cart;
		unset(
			$GLOBALS['wp']->query_vars['order-received'],
			$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ],
			$_GET['key'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'],
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_IS_EPHEMERAL_CART'],
			$_GET['rest_route']
		);
		$_SERVER['REQUEST_URI'] = $this->original_request_uri ?? '/';
		wp_set_current_user( 0 );
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
	 * @testdox Should leave the WooCommerce session alone for a Store API request without the product-page nonce, and for a page whose query string holds the Store API path: $_dataName.
	 *
	 * WordPress routes /some-page/?redirect_to=/wp-json/wc/store/v1/cart to the page, not to the REST API, so the request
	 * keeps its session even with a valid product-page session nonce.
	 *
	 * @dataProvider provide_requests_that_keep_the_default_handler
	 *
	 * @param string $request_uri Request URI.
	 * @param string $rest_route  The rest_route query argument, or '' for none.
	 * @param bool   $with_nonce  Whether the request carries a valid product-page session nonce.
	 */
	public function test_keeps_default_handler( string $request_uri, string $rest_route, bool $with_nonce ): void {
		$_SERVER['REQUEST_URI'] = $request_uri;
		if ( '' !== $rest_route ) {
			$_GET['rest_route'] = $rest_route;
		}
		if ( $with_nonce ) {
			$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE'] = wp_create_nonce( 'woopayments_tokenized_cart_session_nonce' );
		}
		$this->sut = $this->create_controller();

		$this->assertSame( 'WC_Session_Handler', $this->sut->handle_woocommerce_session_handler( 'WC_Session_Handler' ) );
		$this->assertFalse( has_filter( 'woocommerce_persistent_cart_enabled', array( $this->sut, 'disable_persistent_cart' ) ) );
	}

	/**
	 * Requests that keep the default session handler.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function provide_requests_that_keep_the_default_handler(): array {
		return array(
			'Store API request without the nonce' => array( '/wp-json/wc/store/v1/cart', '/wc/store/v1/cart', false ),
			'page whose query string holds the Store API path, with the nonce' => array( '/some-page/?redirect_to=/wp-json/wc/store/v1/cart', '', true ),
		);
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
	 * @testdox Should reject a Store API request with tokenized markers but no valid product-page session nonce during pre-dispatch, and leave other requests untouched: $_dataName.
	 *
	 * @dataProvider provide_pre_dispatch_requests
	 *
	 * @param string               $request_uri     Request URI.
	 * @param string               $rest_route      The rest_route query argument, or '' for none.
	 * @param array<string,string> $headers         Request headers, as $_SERVER keys.
	 * @param string               $method          REST request method.
	 * @param string               $route           REST route.
	 * @param int|null             $expected_status Status of the rejection, or null when the request is left untouched.
	 */
	public function test_pre_dispatch_rejects_tokenized_markers_without_a_valid_session_nonce( string $request_uri, string $rest_route, array $headers, string $method, string $route, ?int $expected_status ): void {
		$_SERVER['REQUEST_URI'] = $request_uri;
		if ( '' !== $rest_route ) {
			$_GET['rest_route'] = $rest_route;
		}
		foreach ( $headers as $header => $value ) {
			$_SERVER[ $header ] = $value;
		}
		$this->sut = $this->create_controller();

		$result = $this->sut->maybe_reject_invalid_tokenized_cart_session( null, null, new WP_REST_Request( $method, $route ) );

		$this->assertSame( null !== $expected_status, $result instanceof \WP_Error );
		$this->assertSame( $expected_status, $result instanceof \WP_Error ? ( $result->get_error_data()['status'] ?? 'no status' ) : $result );
	}

	/**
	 * Pre-dispatch requests and whether they are rejected.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,string>,3:string,4:string,5:int|null}>
	 */
	public function provide_pre_dispatch_requests(): array {
		$store_api = '/wp-json/wc/store/v1/cart';
		$route     = '/wc/store/v1/cart';

		return array(
			'non-tokenized Store API request'       => array( $store_api, $route, array(), 'GET', '/wc/store/v1/cart', null ),
			'REST request outside the Store API, with markers' => array( '/wp-json/wc/v3/orders', '', array( 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION' => 'token-without-valid-nonce' ), 'GET', '/wc/v3/orders', null ),
			'tokenized markers without the nonce'   => array( $store_api, $route, array( 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION' => 'token-without-valid-nonce' ), 'POST', '/wc/store/v1/cart/add-item', 400 ),
			'checkout marker with an invalid nonce' => array(
				$store_api,
				$route,
				array(
					'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE' => 'expired-session-nonce',
					'HTTP_X_WOOPAYMENTS_TOKENIZED_CART' => 'true',
				),
				'POST',
				'/wc/store/v1/checkout',
				400,
			),
		);
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
	 * @testdox Should keep the shopper's saved cart through the order-received page of an express payment, and empty it on any other order-received page: $_dataName.
	 *
	 * The checkout shortcode empties the cart on the order-received page (WC_Shortcode_Checkout::order_received()). The
	 * extension keeps core's cart session hooks off on the page an express payment returns to, so the stored cart is
	 * never cleared there (client class-wc-payments-payment-request-session.php:47-58); every other order-received page
	 * empties the cart as core does.
	 *
	 * @dataProvider provide_order_received_pages
	 *
	 * @param string $marker     Query argument appended to the order-received address.
	 * @param bool   $keeps_cart Whether the shopper's cart is kept.
	 */
	public function test_order_received_page_keeps_the_saved_cart_only_after_an_express_payment( string $marker, bool $keeps_cart ): void {
		$product    = \WC_Helper_Product::create_simple_product();
		$guest_id   = wc_rand_hash( 't_', 30 );
		$expiration = time() + 2 * DAY_IN_SECONDS;
		\WC_Helper_Coupon::create_coupon( 'save10' );

		$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $guest_id . '|' . $expiration . '|' . ( time() + DAY_IN_SECONDS ) . '|' . hash_hmac( 'md5', $guest_id . '|' . $expiration, wp_hash( $guest_id . '|' . $expiration ) );
		$this->start_request();
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->apply_coupon( 'save10' );
		WC()->session->save_data();
		$saved_before = $this->get_stored_session( $guest_id );
		$this->assertSame( 2, $this->get_stored_quantity( $saved_before ), 'The shopper starts with two items in the saved cart.' );

		$this->render_order_received_page( $marker );

		$saved_after = $this->get_stored_session( $guest_id );
		$this->assertSame( $keeps_cart ? 2 : 0, $this->get_stored_quantity( $saved_after ), 'Items in the saved cart after the page.' );
		$this->assertSame( $keeps_cart ? $saved_before['cart'] : null, $saved_after['cart'] ?? null );
		$this->assertSame( $keeps_cart ? $saved_before['cart_totals'] : null, $saved_after['cart_totals'] ?? null );
		$this->assertSame( $keeps_cart ? $saved_before['applied_coupons'] : null, $saved_after['applied_coupons'] ?? null );
		$this->assertSame( $keeps_cart ? array( $product->get_id() => 2 ) : array(), array_column( WC()->cart->get_cart(), 'quantity', 'product_id' ), 'Items in the cart for the rest of the page.' );
		$this->assertSame( $keeps_cart ? array( 'save10' ) : array(), WC()->cart->get_applied_coupons(), 'Coupons in the cart for the rest of the page.' );
	}

	/**
	 * Order-received pages: the one an express payment from a product page returns to, and an ordinary one.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_order_received_pages(): array {
		return array(
			'express payment from a product page' => array( '&woopayments-custom-session=1', true ),
			'ordinary order-received page'        => array( '', false ),
		);
	}

	/**
	 * @testdox Should keep a customer's saved cart only on the order-received page an express payment returns to, when the cart is emptied with its saved copy: $_dataName.
	 *
	 * The extension turns core's cart session hooks and the persistent cart off only on that page (client
	 * class-wc-payments-payment-request-session.php:47-58, :110-114), so an empty_cart() that also clears the saved copy, as
	 * wc_clear_cart_after_payment() does, keeps both there. Any other address carrying the marker keeps core's hooks.
	 *
	 * @dataProvider provide_marked_addresses
	 *
	 * @param string      $request_uri Request URI.
	 * @param string|null $endpoint    The order-received endpoint setting, or null for the default.
	 * @param bool        $keeps_cart  Whether the customer's cart is kept.
	 */
	public function test_express_payment_order_received_page_keeps_the_customer_saved_cart( string $request_uri, ?string $endpoint, bool $keeps_cart ): void {
		if ( null !== $endpoint ) {
			update_option( 'woocommerce_checkout_order_received_endpoint', $endpoint );
		}
		$product = \WC_Helper_Product::create_simple_product();
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user_id );
		$this->start_request();
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->session->save_data();
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		$this->assertSame( 2, $this->get_stored_quantity( get_user_meta( $user_id, $meta_key, true ) ), 'The customer starts with two items saved in their account.' );

		$_SERVER['REQUEST_URI'] = $request_uri;
		$this->sut              = $this->create_controller();
		$this->sut->register();
		$this->start_request();
		WC()->cart->get_cart_contents_count();
		WC()->cart->empty_cart();
		WC()->session->save_data();

		$this->assertSame( $keeps_cart ? 2 : 0, $this->get_stored_quantity( get_user_meta( $user_id, $meta_key, true ) ), 'Items saved in the account after the page.' );
		$this->assertSame( $keeps_cart ? 2 : 0, $this->get_stored_quantity( $this->get_stored_session( (string) $user_id ) ), 'Items in the saved cart after the page.' );
	}

	/**
	 * Addresses carrying the express payment's marker, with the order-received endpoint setting.
	 *
	 * @return array<string,array{0:string,1:string|null,2:bool}>
	 */
	public function provide_marked_addresses(): array {
		return array(
			'order-received page'                      => array( '/checkout/order-received/1/?key=wc_order_key&woopayments-custom-session=1', null, true ),
			'order-received page under a renamed slug' => array( '/checkout/thank-you/1/?key=wc_order_key&woopayments-custom-session=1', 'thank-you', true ),
			'cart page'                                => array( '/cart/?woopayments-custom-session=1', null, false ),
		);
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
	 * Start a storefront request: the session handler loads the shopper's session, then the cart is built, as on init.
	 * Nothing has loaded the cart from the session yet.
	 */
	private function start_request(): void {
		// A request builds one cart, so the hooks of carts built earlier in this process go first.
		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && ( $callback['function'][0] instanceof \WC_Cart || $callback['function'][0] instanceof \WC_Cart_Session ) ) {
						remove_action( $hook_name, $callback['function'], $priority );
					}
				}
			}
		}

		$session = new \WC_Session_Handler();
		$session->init();
		WC()->session = $session;
		WC()->cart    = new \WC_Cart();
		unset( $GLOBALS['wp_actions']['woocommerce_load_cart_from_session'] );
	}

	/**
	 * Request a guest order's order-received page through the checkout shortcode, then save the session as at shutdown.
	 *
	 * @param string $marker Query argument appended to the order-received address.
	 */
	private function render_order_received_page( string $marker ): void {
		$order                  = \WC_Helper_Order::create_order( 0 );
		$_SERVER['REQUEST_URI'] = '/checkout/order-received/' . $order->get_id() . '/?key=' . $order->get_order_key() . $marker;
		$this->sut              = $this->create_controller();
		$this->sut->register();
		$this->start_request();

		// The page header's cart count loads the cart before the content renders, as a classic theme's header cart does.
		WC()->cart->get_cart_contents_count();
		$GLOBALS['wp']->query_vars['order-received'] = $order->get_id();
		$_GET['key']                                 = $order->get_order_key();
		ob_start();
		\WC_Shortcode_Checkout::output( array() );
		ob_end_clean();

		WC()->session->save_data();
	}

	/**
	 * Read a stored session straight from the database, bypassing the object cache.
	 *
	 * @param string $customer_id Session key.
	 * @return array<string,mixed>
	 */
	private function get_stored_session( string $customer_id ): array {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $customer_id ) );

		return (array) maybe_unserialize( $value );
	}

	/**
	 * Count the items of a stored cart: a session row, or the persistent cart saved in user meta.
	 *
	 * @param mixed $stored Stored data holding a 'cart' entry.
	 * @return int
	 */
	private function get_stored_quantity( $stored ): int {
		$cart = is_array( $stored ) ? maybe_unserialize( $stored['cart'] ?? array() ) : array();

		return (int) array_sum( array_column( (array) $cart, 'quantity' ) );
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
