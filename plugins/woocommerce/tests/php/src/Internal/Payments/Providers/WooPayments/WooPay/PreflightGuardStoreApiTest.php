<?php
/**
 * PreflightGuard tests through the Store API checkout routes.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\PreflightGuard;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use WC_Coupon;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Dispatches WooPay preflight checkouts through the REST server, with the current user resolved the way WordPress resolves
 * it before any REST route runs, so the signed-request filter is set (or not) by WooPay's own signature check.
 */
class PreflightGuardStoreApiTest extends WC_Unit_Test_Case {

	use WooPayServicesFixture {
		setUp as fixture_set_up;
		tearDown as fixture_tear_down;
	}

	/** Coupon code at its usage limit. */
	private const COUPON_CODE = 'woopay-preflight-exhausted';

	/**
	 * Guard under test.
	 *
	 * @var PreflightGuard
	 */
	private PreflightGuard $guard;

	/**
	 * Callback runs on the guarded hooks, by hook.
	 *
	 * @var array<string,int>
	 */
	private array $callback_runs = array();

	/**
	 * Callbacks added by this test, to remove in tearDown.
	 *
	 * @var array<int,array{0:string,1:callable,2:int}>
	 */
	private array $added_callbacks = array();

	/**
	 * Gateway registry before the test.
	 *
	 * @var array<string,\WC_Payment_Gateway>
	 */
	private array $gateways_before = array();

	/**
	 * Original guest-checkout options.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_options = array();

	/**
	 * The request globals this test sets, as they were before it: each key's value, or null when it was absent.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $original_request_globals = array();

	/**
	 * The current user before the test, and the global WordPress keeps it in.
	 *
	 * @var array{0:int,1:mixed}
	 */
	private array $original_user = array( 0, null );

	/**
	 * The customer before the test; the test checks out with its own session and customer.
	 *
	 * @var \WC_Customer|null
	 */
	private $original_customer = null;

	/**
	 * Set up the Store API server, the guard and a cart with a coupon at its usage limit.
	 */
	public function setUp(): void {
		$this->fixture_set_up();
		$this->save_request_globals();
		$this->original_user     = array( get_current_user_id(), $GLOBALS['current_user'] ?? null );
		$this->original_customer = WC()->customer;
		// A session and customer of the test's own, so the checkout's address and email stay out of later tests; the
		// fixture puts the original session back.
		WC()->session = new \WC_Session_Handler();
		WC()->session->init();
		WC()->customer = new \WC_Customer( 0, true );

		foreach ( array( 'woocommerce_enable_guest_checkout', 'woocommerce_enable_signup_and_login_from_checkout' ) as $option ) {
			$this->original_options[ $option ] = get_option( $option, null );
		}
		// Guest checkout without sign-up, so the route asks woocommerce_checkout_registration_required before the cart checks.
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

		// The checkout schema lists the registered gateways when its route registers, so the gateway is registered first.
		$this->gateways_before = WC()->payment_gateways()->payment_gateways;
		WC()->payment_gateways()->payment_gateways['woocommerce_payments'] = $this->create_woopayments_gateway();
		$this->initialize_store_api_server();
		$this->guard = new PreflightGuard();
		$this->guard->register();
		$this->add_callback( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$this->add_callback( 'woocommerce_available_payment_gateways', array( $this, 'offer_test_gateways' ), 20 );
		foreach ( array( 'woocommerce_checkout_registration_required', 'woocommerce_store_api_checkout_order_processed' ) as $hook ) {
			$this->callback_runs[ $hook ] = 0;
			$this->add_callback(
				$hook,
				function ( $value = null ) use ( $hook ) {
					++$this->callback_runs[ $hook ];

					return $value;
				},
				5
			);
		}
	}

	/**
	 * Remove this test's callbacks and the REST server, and put back every request global, the current user, the session and
	 * the customer the test replaced.
	 */
	public function tearDown(): void {
		try {
			remove_filter( 'rest_request_before_callbacks', array( $this->guard, 'suppress_checkout_side_effects' ), 10 );
			remove_filter( 'rest_request_after_callbacks', array( $this->guard, 'restore_checkout_side_effects' ), 10 );
			foreach ( $this->added_callbacks as $callback ) {
				remove_filter( $callback[0], $callback[1], $callback[2] );
			}
			foreach ( $this->original_options as $option => $value ) {
				null === $value ? delete_option( $option ) : update_option( $option, $value );
			}
			remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
			WC()->customer                             = $this->original_customer;
			$GLOBALS['wp_rest_server']                 = null;
			WC()->payment_gateways()->payment_gateways = $this->gateways_before;
		} finally {
			try {
				$this->fixture_tear_down();
			} finally {
				// After the fixture, which clears the user agent and the current user for every WooPay test.
				$this->restore_request_globals();
				wp_set_current_user( $this->original_user[0] );
				$GLOBALS['current_user'] = $this->original_user[1]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the user object the test replaced.
			}
		}
	}

	/**
	 * Offer the WooPayments gateway and Cash on delivery at checkout.
	 *
	 * @param mixed $gateways Available gateways.
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function offer_test_gateways( $gateways ): array {
		$gateways     = is_array( $gateways ) ? $gateways : array();
		$cod          = new \WC_Gateway_COD();
		$cod->enabled = 'yes';

		$gateways['woocommerce_payments'] = WC()->payment_gateways()->payment_gateways['woocommerce_payments'];
		$gateways['cod']                  = $cod;

		return $gateways;
	}

	/**
	 * Create the WooPayments gateway, which answers a preflight without charging.
	 *
	 * @return WooPaymentsGateway
	 */
	private function create_woopayments_gateway(): WooPaymentsGateway {
		$gateway = new WooPaymentsGateway();
		$gateway->init( new RecordingPaymentProcessingService(), new WooPaymentsProvider() );

		return $gateway;
	}

	/**
	 * @testdox The preflight marker lifts coupon limits and checkout callbacks only for a signed WooPay request naming the WooPayments gateway: $_dataName.
	 *
	 * WooPay's preflight names woocommerce_payments and posts to checkout, or to checkout/{order_id} to pay an order
	 * (WooPay WooPayStoreApiHandler.php:574-598), signed with the store's blog token for a store reporting client 11.1.0.
	 *
	 * @dataProvider provide_preflight_requests
	 *
	 * @param string $route          'checkout' or 'checkout-order'.
	 * @param string $payment_method Gateway the request names.
	 * @param bool   $signed         Whether the request is a signed WooPay request.
	 * @param bool   $lifted         Whether the limits and callbacks are lifted for it.
	 */
	public function test_preflight_marker_is_honoured_only_for_a_signed_woopay_request( string $route, string $payment_method, bool $signed, bool $lifted ): void {
		$this->create_exhausted_coupon_cart();
		$request = 'checkout' === $route ? $this->make_checkout_request( $payment_method ) : $this->make_pay_for_order_request( $payment_method );
		$this->resolve_current_user( $signed );

		$response = rest_get_server()->dispatch( $request );

		if ( 'checkout' === $route ) {
			$this->assertSame( $lifted ? 200 : 409, $response->get_status(), 'An exhausted coupon is refused unless the limits are lifted.' );
			$this->assertSame( $lifted ? 0 : 1, $this->callback_runs['woocommerce_checkout_registration_required'] );
		} else {
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $lifted ? 0 : 1, $this->callback_runs['woocommerce_store_api_checkout_order_processed'] );
		}
		if ( $lifted ) {
			$order = wc_get_order( (int) $response->get_data()['order_id'] );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'pending', $order->get_status(), 'The gateway answers the preflight without charging.' );
		}
	}

	/**
	 * Preflight requests: route, gateway named, signed, lifted.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:bool}>
	 */
	public function provide_preflight_requests(): array {
		return array(
			'Cash on delivery with the marker, signed' => array( 'checkout', 'cod', true, false ),
			'unsigned request naming the gateway'      => array( 'checkout', 'woocommerce_payments', false, false ),
			'signed WooPay preflight, cart checkout'   => array( 'checkout', 'woocommerce_payments', true, true ),
			'signed WooPay preflight, pay for order'   => array( 'checkout-order', 'woocommerce_payments', true, true ),
		);
	}

	/**
	 * Start a REST server with the Store API routes, as the Store API route tests do (StoreApiRestTestCaseTrait).
	 */
	private function initialize_store_api_server(): void {
		$server                    = new \Spy_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		self::with_rest_route_context(
			'/wc/store/v1',
			static function () use ( $server ): void {
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
				do_action( 'rest_api_init', $server );
			}
		);
	}

	/**
	 * Resolve the current user as WordPress does before any REST route runs (WP::init()), signed as WooPay or not.
	 *
	 * @param bool $signed Whether the request is a signed WooPay request.
	 */
	private function resolve_current_user( bool $signed ): void {
		// A REST checkout is a POST; WordPress reads the method while it validates a login cookie. No login cookie: the
		// shopper is a guest, whatever an earlier test left in $_COOKIE.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/wp-json/wc/store/v1/checkout';
		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ] );
		$handler = $this->create_handler();
		if ( $signed ) {
			$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
			$this->force_real_blog_token_signed();
		}
		$this->add_callback( 'determine_current_user', array( $handler, 'determine_current_user_for_woopay' ), 20 );

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unset so the user is resolved again, as WP::init() does once per request.
		wp_get_current_user();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertSame( $signed, (bool) apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * Put a virtual product and a coupon in the cart, then use the coupon up.
	 */
	private function create_exhausted_coupon_cart(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->save();
		$coupon = new WC_Coupon();
		$coupon->set_code( self::COUPON_CODE );
		$coupon->set_amount( 1 );
		$coupon->set_usage_limit( 1 );
		$coupon->save();

		wc_empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->apply_coupon( self::COUPON_CODE );

		$coupon->set_usage_count( 1 );
		$coupon->save();
	}

	/**
	 * Build a Store API checkout request with WooPay's preflight marker.
	 *
	 * @param string $payment_method Gateway the request names.
	 * @return WP_REST_Request<array<string,mixed>>
	 */
	private function make_checkout_request( string $payment_method ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_body_params( $this->get_preflight_body( $payment_method ) );

		return $request;
	}

	/**
	 * Build a Store API pay-for-order request with WooPay's preflight marker, for a pending order.
	 *
	 * @param string $payment_method Gateway the request names.
	 * @return WP_REST_Request<array<string,mixed>>
	 */
	private function make_pay_for_order_request( string $payment_method ): WP_REST_Request {
		$order = \WC_Helper_Order::create_order( 0 );
		$order->set_status( 'pending' );
		$order->save();

		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout/' . $order->get_id() );
		$request->set_query_params(
			array(
				'key'           => $order->get_order_key(),
				'billing_email' => $order->get_billing_email(),
			)
		);
		$body                  = $this->get_preflight_body( $payment_method );
		$body['billing_email'] = $order->get_billing_email();
		$request->set_body_params( $body );

		return $request;
	}

	/**
	 * Get the preflight body WooPay sends: addresses, the gateway and the marker.
	 *
	 * @param string $payment_method Gateway the request names.
	 * @return array<string,mixed>
	 */
	private function get_preflight_body( string $payment_method ): array {
		$address = array(
			'first_name' => 'Wanda',
			'last_name'  => 'Pay',
			'company'    => '',
			'address_1'  => '1 Main Street',
			'address_2'  => '',
			'city'       => 'San Francisco',
			'state'      => 'CA',
			'postcode'   => '94107',
			'country'    => 'US',
			'phone'      => '+15555550100',
		);

		return array(
			'billing_address'  => array_merge( $address, array( 'email' => 'wanda@example.com' ) ),
			'shipping_address' => $address,
			'payment_method'   => $payment_method,
			'payment_data'     => array(
				array(
					'key'   => 'is-woopay-preflight-check',
					'value' => true,
				),
			),
		);
	}

	/**
	 * Save the request globals this test sets: the server keys and the login cookies.
	 */
	private function save_request_globals(): void {
		foreach ( array( 'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_USER_AGENT' ) as $key ) {
			$this->original_request_globals['server'][ $key ] = $_SERVER[ $key ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as is, to be put back as is.
		}
		foreach ( array( LOGGED_IN_COOKIE, AUTH_COOKIE, SECURE_AUTH_COOKIE ) as $key ) {
			$this->original_request_globals['cookie'][ $key ] = $_COOKIE[ $key ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as is, to be put back as is.
		}
	}

	/**
	 * Put the saved request globals back, absent keys absent.
	 */
	private function restore_request_globals(): void {
		foreach ( $this->original_request_globals['server'] ?? array() as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		foreach ( $this->original_request_globals['cookie'] ?? array() as $key => $value ) {
			if ( null === $value ) {
				unset( $_COOKIE[ $key ] );
			} else {
				$_COOKIE[ $key ] = $value;
			}
		}
	}

	/**
	 * Add a callback and remember it for tearDown.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_callback( string $hook, callable $callback, int $priority = 10 ): void {
		add_filter( $hook, $callback, $priority, 1 );
		$this->added_callbacks[] = array( $hook, $callback, $priority );
	}
}
