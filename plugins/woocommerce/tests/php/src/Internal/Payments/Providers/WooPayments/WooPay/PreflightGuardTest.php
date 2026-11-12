<?php
/**
 * PreflightGuard tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\PreflightGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\StoreApiRequestHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use WC_Coupon;
use WC_Order;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;

/**
 * Tests for PreflightGuard.
 */
class PreflightGuardTest extends WC_Unit_Test_Case {

	/** @var array<int,string> Hooks the preflight guard may change. */
	private const GUARDED_HOOKS = array(
		'rest_request_before_callbacks',
		'rest_request_after_callbacks',
		'woocommerce_store_api_checkout_update_order_meta',
		'woocommerce_store_api_checkout_order_processed',
		'woocommerce_order_status_pending',
		'woocommerce_checkout_registration_required',
		'woocommerce_coupon_get_usage_limit',
		'woocommerce_coupon_get_usage_limit_per_user',
	);

	/**
	 * Hook snapshots from before each test.
	 *
	 * @var array<string,\WP_Hook|null>
	 */
	private array $hook_snapshots = array();

	/**
	 * Refusal log lines the handler wrote in this test.
	 *
	 * @var int
	 */
	private int $logged_refusals = 0;

	/**
	 * POST data from before each test.
	 *
	 * @var array<string,mixed>
	 */
	private array $post_snapshot = array();

	/**
	 * Set up isolated hook registries.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->post_snapshot = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The fixture restores the inherited request state exactly.
		$this->snapshot_and_remove_guarded_hooks();
	}

	/**
	 * Restore hook registries even when an assertion fails.
	 */
	public function tearDown(): void {
		try {
			$this->restore_guarded_hook_snapshots();
			$_POST = $this->post_snapshot;
			wc_clear_notices();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Leaves checkout hooks unchanged for a request that is not a WooPay preflight: $label.
	 *
	 * @testWith ["wrong route", "/wc/store/v1/cart", [{"key": "is-woopay-preflight-check", "value": true}]]
	 *           ["malformed payment data", "/wc/store/v1/checkout", "not-an-array"]
	 *           ["malformed payment data record", "/wc/store/v1/checkout", ["not-an-array-record"]]
	 *           ["missing exact marker", "/wc/store/v1/checkout", [{"key": "not-is-woopay-preflight-check", "value": true}]]
	 *           ["exact marker with empty value", "/wc/store/v1/checkout", [{"key": "is-woopay-preflight-check", "value": false}]]
	 *
	 * @param string $label        Case label.
	 * @param string $route        Store API route.
	 * @param mixed  $payment_data The request's payment_data body parameter.
	 */
	public function test_non_preflight_requests_leave_checkout_hooks_unchanged( string $label, string $route, $payment_data ): void {
		unset( $label );
		$guard                = $this->create_guard();
		$action_counts        = array(
			'meta'      => 0,
			'processed' => 0,
			'pending'   => 0,
		);
		$registration_calls   = 0;
		$coupon_limit_calls   = 0;
		$per_user_limit_calls = 0;
		add_action(
			'woocommerce_store_api_checkout_update_order_meta',
			static function () use ( &$action_counts ): void {
				++$action_counts['meta'];
			},
			10,
			0
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			static function () use ( &$action_counts ): void {
				++$action_counts['processed'];
			},
			10,
			0
		);
		add_action(
			'woocommerce_order_status_pending',
			static function () use ( &$action_counts ): void {
				++$action_counts['pending'];
			},
			10,
			0
		);
		add_filter(
			'woocommerce_checkout_registration_required',
			static function ( $required ) use ( &$registration_calls ) {
				++$registration_calls;

				return 'sentinel-' . $required;
			}
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit',
			static function () use ( &$coupon_limit_calls ) {
				++$coupon_limit_calls;

				return 6;
			}
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit_per_user',
			static function () use ( &$per_user_limit_calls ) {
				++$per_user_limit_calls;

				return 2;
			}
		);
		$guard->register();
		$request  = new WP_REST_Request( 'POST', $route );
		$response = new \stdClass();
		$request->set_body_params(
			array(
				'payment_method' => 'woocommerce_payments',
				'payment_data'   => $payment_data,
			)
		);

		$this->assertSame( $response, apply_filters( 'rest_request_before_callbacks', $response, array(), $request ) );
		do_action( 'woocommerce_store_api_checkout_update_order_meta' );
		do_action( 'woocommerce_store_api_checkout_order_processed' );
		do_action( 'woocommerce_order_status_pending' );

		$this->assertSame(
			array(
				'meta'      => 1,
				'processed' => 1,
				'pending'   => 1,
			),
			$action_counts
		);
		$this->assertSame( 'sentinel-original', apply_filters( 'woocommerce_checkout_registration_required', 'original' ) );
		$this->assertSame( 6, apply_filters( 'woocommerce_coupon_get_usage_limit', 5, new WC_Coupon() ) );
		$this->assertSame( 2, apply_filters( 'woocommerce_coupon_get_usage_limit_per_user', 1, 123, new WC_Coupon() ) );
		$this->assertSame( 1, $registration_calls );
		$this->assertSame( 1, $coupon_limit_calls );
		$this->assertSame( 1, $per_user_limit_calls );
	}

	/** @testdox Suppresses checkout and coupon side effects for a WooPay preflight request WooPay composed, naming the WooPayments gateway. */
	public function test_preflight_request_suppresses_checkout_and_coupon_side_effects(): void {
		$guard              = $this->create_guard();
		$order              = $this->create_order();
		$coupon             = $this->create_coupon();
		$action_counts      = array(
			'meta'      => 0,
			'processed' => 0,
			'pending'   => 0,
		);
		$registration_calls = 0;

		$order->update_status( 'failed' );
		add_action(
			'woocommerce_store_api_checkout_update_order_meta',
			static function () use ( &$action_counts ): void {
				++$action_counts['meta'];
			},
			10,
			0
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			static function () use ( &$action_counts ): void {
				++$action_counts['processed'];
			},
			10,
			0
		);
		add_action(
			'woocommerce_order_status_pending',
			static function () use ( $coupon, &$action_counts ): void {
				++$action_counts['pending'];
				$coupon->set_usage_count( $coupon->get_usage_count() + 1 );
				$coupon->save();
			},
			10,
			0
		);
		add_filter(
			'woocommerce_checkout_registration_required',
			static function ( $required ) use ( &$registration_calls ) {
				++$registration_calls;

				return 'sentinel-' . $required;
			}
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit',
			static function (): int {
				return 6;
			}
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit_per_user',
			static function (): int {
				return 2;
			}
		);
		$guard->register();

		$request  = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$response = new \stdClass();
		$request->set_body_params(
			array(
				'payment_method' => 'woocommerce_payments',
				'payment_data'   => array(
					array(
						'key'   => 'is-woopay-preflight-check',
						'value' => true,
					),
				),
			)
		);

		$this->assertSame( $response, apply_filters( 'rest_request_before_callbacks', $response, array(), $request ) );
		$this->assertSame( $this->post_snapshot, $_POST, 'The REST pre-callback must not alter request globals.' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The assertion proves the callback does not mutate request globals.
		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$_POST['is-woopay-preflight-check'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This simulates the gateway's exact preflight marker.
		$service                            = new RecordingPaymentProcessingService();
		$gateway                            = new WooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, null, null, null, null, null, null, $this->create_handler( true ) );

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );
		$coupon = new WC_Coupon( $coupon->get_id() );

		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => '',
			),
			$result
		);
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame(
			array(
				'meta'      => 0,
				'processed' => 0,
				'pending'   => 0,
			),
			$action_counts
		);
		$this->assertSame( 0, $coupon->get_usage_count() );
		$this->assertSame( 'original', apply_filters( 'woocommerce_checkout_registration_required', 'original' ) );
		$this->assertSame( 0, $registration_calls );
		$this->assertNull( apply_filters( 'woocommerce_coupon_get_usage_limit', 5, $coupon ) );
		$this->assertSame( 0, apply_filters( 'woocommerce_coupon_get_usage_limit_per_user', 1, 123, $coupon ) );
		$this->assertNull( $service->last_checkout_context );
	}

	/** @testdox Restores checkout hooks after a preflight request before the next checkout request. */
	public function test_restores_checkout_hooks_after_preflight_request(): void {
		$guard                = $this->create_guard();
		$order                = $this->create_order();
		$coupon               = $this->create_coupon();
		$action_counts        = array(
			'meta'      => 0,
			'processed' => 0,
			'pending'   => 0,
		);
		$registration_calls   = 0;
		$coupon_limit_calls   = 0;
		$per_user_limit_calls = 0;

		add_action(
			'woocommerce_store_api_checkout_update_order_meta',
			static function ( WC_Order $hook_order ) use ( $order, &$action_counts ): void {
				if ( $order === $hook_order ) {
					++$action_counts['meta'];
				}
			},
			20,
			1
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			static function ( WC_Order $hook_order ) use ( $order, &$action_counts ): void {
				if ( $order === $hook_order ) {
					++$action_counts['processed'];
				}
			},
			30,
			1
		);
		add_action(
			'woocommerce_order_status_pending',
			static function ( int $order_id, WC_Order $hook_order ) use ( $order, &$action_counts ): void {
				if ( $order->get_id() === $order_id && $order === $hook_order ) {
					++$action_counts['pending'];
				}
			},
			40,
			2
		);
		add_filter(
			'woocommerce_checkout_registration_required',
			static function ( $required ) use ( &$registration_calls ) {
				++$registration_calls;

				return 'sentinel-' . $required;
			},
			50,
			1
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit',
			static function ( $limit, WC_Coupon $hook_coupon ) use ( $coupon, &$coupon_limit_calls ): int {
				if ( 5 === $limit && $coupon->get_id() === $hook_coupon->get_id() ) {
					++$coupon_limit_calls;
				}

				return 6;
			},
			5,
			2
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit_per_user',
			static function ( $limit, $user_id, WC_Coupon $hook_coupon ) use ( $coupon, &$per_user_limit_calls ): int {
				if ( 1 === $limit && 123 === $user_id && $coupon->get_id() === $hook_coupon->get_id() ) {
					++$per_user_limit_calls;
				}

				return 2;
			},
			5,
			3
		);
		$guard->register();

		$preflight_request  = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$preflight_response = new \stdClass();
		$preflight_request->set_body_params(
			array(
				'payment_method' => 'woocommerce_payments',
				'payment_data'   => array(
					array(
						'key'   => 'is-woopay-preflight-check',
						'value' => true,
					),
				),
			)
		);

		$this->assertSame( $preflight_response, apply_filters( 'rest_request_before_callbacks', $preflight_response, array(), $preflight_request ) );
		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		do_action( 'woocommerce_order_status_pending', $order->get_id(), $order );
		$this->assertSame(
			array(
				'meta'      => 0,
				'processed' => 0,
				'pending'   => 0,
			),
			$action_counts
		);
		$this->assertSame( 'original', apply_filters( 'woocommerce_checkout_registration_required', 'original' ) );
		$this->assertNull( apply_filters( 'woocommerce_coupon_get_usage_limit', 5, $coupon ) );
		$this->assertSame( 0, apply_filters( 'woocommerce_coupon_get_usage_limit_per_user', 1, 123, $coupon ) );
		$this->assertSame( 0, $registration_calls );
		$this->assertSame( 1, $coupon_limit_calls );
		$this->assertSame( 1, $per_user_limit_calls );
		$this->assertSame( $preflight_response, apply_filters( 'rest_request_after_callbacks', $preflight_response, array(), $preflight_request ) );

		$following_request  = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$following_response = new \stdClass();
		$following_request->set_body_params(
			array(
				'payment_method' => 'woocommerce_payments',
				'payment_data'   => array(
					array(
						'key'   => 'ordinary-request',
						'value' => true,
					),
				),
			)
		);

		$this->assertSame( $following_response, apply_filters( 'rest_request_before_callbacks', $following_response, array(), $following_request ) );
		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		do_action( 'woocommerce_order_status_pending', $order->get_id(), $order );
		$this->assertSame(
			array(
				'meta'      => 1,
				'processed' => 1,
				'pending'   => 1,
			),
			$action_counts
		);
		$this->assertSame( 'sentinel-original', apply_filters( 'woocommerce_checkout_registration_required', 'original' ) );
		$this->assertSame( 6, apply_filters( 'woocommerce_coupon_get_usage_limit', 5, $coupon ) );
		$this->assertSame( 2, apply_filters( 'woocommerce_coupon_get_usage_limit_per_user', 1, 123, $coupon ) );
		$this->assertSame( 1, $registration_calls );
		$this->assertSame( 2, $coupon_limit_calls );
		$this->assertSame( 2, $per_user_limit_calls );
		$this->assertSame( $following_response, apply_filters( 'rest_request_after_callbacks', $following_response, array(), $following_request ) );
	}

	/**
	 * @testdox Refuses a preflight without WooPay's proof only on a Store API checkout naming the WooPayments gateway, before any hook changes: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payment-gateway-wcpay.php:928-952 answers the marker without proof with a 403
	 * `wcpay_invalid_woopay_preflight` and one log line. Here the refusal covers the requests whose suppression it
	 * guards: the checkout routes naming the WooPayments gateway. The marker is read with its key sanitized as the
	 * Store API sanitizes it for the gateway.
	 *
	 * @testWith ["/wc/store/v1/checkout", "woocommerce_payments", "is-woopay-preflight-check", true, true]
	 *           ["/wc/store/v1/checkout/123", "woocommerce_payments", "is-woopay-preflight-check", true, true]
	 *           ["/wc/store/checkout", "woocommerce_payments", "Is-WooPay-Preflight-Check", true, true]
	 *           ["/wc/store/v1/checkout", "woocommerce_payments", "is-woopay-preflight-check", false, false]
	 *           ["/wc/store/v1/checkout", "cod", "is-woopay-preflight-check", true, false]
	 *           ["/wc/store/v1/cart", "woocommerce_payments", "is-woopay-preflight-check", true, false]
	 *
	 * @param string $route          Store API route.
	 * @param string $payment_method Gateway the request names.
	 * @param string $key            Payment data record key.
	 * @param bool   $value          Payment data record value.
	 * @param bool   $refused        Whether the request is refused.
	 */
	public function test_preflight_without_proof_is_refused_before_any_hook_changes( string $route, string $payment_method, string $key, bool $value, bool $refused ): void {
		$guard         = $this->create_guard( false );
		$pending_calls = 0;
		$coupon_calls  = 0;
		add_action(
			'woocommerce_order_status_pending',
			static function () use ( &$pending_calls ): void {
				++$pending_calls;
			},
			10,
			0
		);
		add_filter(
			'woocommerce_coupon_get_usage_limit',
			static function () use ( &$coupon_calls ) {
				++$coupon_calls;

				return 6;
			}
		);
		$guard->register();
		$request  = new WP_REST_Request( 'POST', $route );
		$response = new \stdClass();
		$request->set_body_params(
			array(
				'payment_method' => $payment_method,
				'payment_data'   => array(
					array(
						'key'   => $key,
						'value' => $value,
					),
				),
			)
		);

		$result = apply_filters( 'rest_request_before_callbacks', $response, array(), $request );
		do_action( 'woocommerce_order_status_pending' );

		if ( $refused ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wcpay_invalid_woopay_preflight', $result->get_error_code() );
			$this->assertSame( "We're not able to verify this WooPay preflight request. Please try again.", $result->get_error_message() );
			$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		} else {
			$this->assertSame( $response, $result );
		}
		$this->assertSame( $refused ? 1 : 0, $this->logged_refusals, 'Each refusal logs one line.' );
		$this->assertSame( 1, $pending_calls );
		$this->assertSame( 6, apply_filters( 'woocommerce_coupon_get_usage_limit', 5, new WC_Coupon() ) );
		$this->assertSame( 1, $coupon_calls );
		$this->assertSame( $response, apply_filters( 'rest_request_after_callbacks', $response, array(), $request ) );
	}

	/**
	 * @testdox Passes an earlier error through unchanged and changes no hook for it, with or without WooPay's proof: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payment-gateway-wcpay.php:929-931.
	 *
	 * @testWith ["proved", true]
	 *           ["not proved", false]
	 *
	 * @param string $label  Case label.
	 * @param bool   $proved Whether WooPay proved it composed the request.
	 */
	public function test_earlier_error_passes_through_unchanged( string $label, bool $proved ): void {
		unset( $label );
		$guard         = $this->create_guard( $proved );
		$pending_calls = 0;
		add_action(
			'woocommerce_order_status_pending',
			static function () use ( &$pending_calls ): void {
				++$pending_calls;
			},
			10,
			0
		);
		$guard->register();
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$error   = new WP_Error( 'rest_invalid_param', 'Invalid parameter(s).', array( 'status' => 400 ) );
		$request->set_body_params(
			array(
				'payment_method' => 'woocommerce_payments',
				'payment_data'   => array(
					array(
						'key'   => 'is-woopay-preflight-check',
						'value' => true,
					),
				),
			)
		);

		$this->assertSame( $error, apply_filters( 'rest_request_before_callbacks', $error, array(), $request ) );
		do_action( 'woocommerce_order_status_pending' );

		$this->assertSame( 1, $pending_calls );
		$this->assertSame( 0, $this->logged_refusals );
	}

	/**
	 * Create the guard over a handler that answers whether WooPay composed every request the same way, and counts the refusal
	 * log lines. Each test request fails on at most one other condition; PreflightGuardStoreApiTest proves the marker
	 * against a real vouch and a real signature.
	 *
	 * @param bool $proved Whether WooPay proved it composed every request.
	 * @return PreflightGuard
	 */
	private function create_guard( bool $proved = true ): PreflightGuard {
		$guard = new PreflightGuard();
		$guard->init( $this->create_handler( $proved ) );

		return $guard;
	}

	/**
	 * Create a handler of WooPay's Store API requests that answers whether WooPay composed every request the same way, and
	 * counts the refusal log lines in $this->logged_refusals.
	 *
	 * @param bool $proved Whether WooPay proved it composed every request.
	 * @return StoreApiRequestHandler
	 */
	private function create_handler( bool $proved ): StoreApiRequestHandler {
		$logged_refusals = &$this->logged_refusals;

		return new class( $proved, $logged_refusals ) extends StoreApiRequestHandler {
			/**
			 * Whether WooPay proved it composed every request.
			 *
			 * @var bool
			 */
			private bool $proved;

			/**
			 * Refusal log lines written.
			 *
			 * @var int
			 */
			private int $logged;

			/**
			 * Constructor.
			 *
			 * @param bool $proved Whether WooPay proved it composed every request.
			 * @param int  $logged Refusal log line counter.
			 */
			public function __construct( bool $proved, int &$logged ) {
				$this->proved = $proved;
				$this->logged = &$logged;
			}

			/**
			 * Answer the fixed proof.
			 *
			 * @return bool
			 */
			public function is_request_composed_by_woopay(): bool {
				return $this->proved;
			}

			/**
			 * Count a refusal log line.
			 */
			public function log_preflight_without_proof(): void {
				++$this->logged;
			}
		};
	}

	/**
	 * Clone the affected hooks before isolating this test's callbacks.
	 */
	private function snapshot_and_remove_guarded_hooks(): void {
		foreach ( self::GUARDED_HOOKS as $hook ) {
			$this->hook_snapshots[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
		}

		foreach ( self::GUARDED_HOOKS as $hook ) {
			remove_all_actions( $hook );
		}
	}

	/**
	 * Restore the callbacks that were present before this test.
	 */
	private function restore_guarded_hook_snapshots(): void {
		foreach ( $this->hook_snapshots as $hook => $snapshot ) {
			remove_all_actions( $hook );

			if ( null === $snapshot ) {
				continue;
			}

			foreach ( $snapshot->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					add_filter( $hook, $callback['function'], $priority, $callback['accepted_args'] );
				}
			}
		}
	}

	/**
	 * Create an order for the preflight integration test.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_total( '12.00' );
		$order->save();

		return $order;
	}

	/**
	 * Create a coupon whose pending-order sentinel would mutate real usage state.
	 *
	 * @return WC_Coupon
	 */
	private function create_coupon(): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'woopay-preflight-guard-test-coupon' );
		$coupon->set_amount( '1.00' );
		$coupon->save();

		return $coupon;
	}
}
