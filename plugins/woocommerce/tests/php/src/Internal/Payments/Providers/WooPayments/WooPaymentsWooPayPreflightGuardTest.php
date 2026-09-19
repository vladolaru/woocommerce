<?php
/**
 * WooPaymentsWooPayPreflightGuard tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPayPreflightGuard;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use WC_Coupon;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for WooPaymentsWooPayPreflightGuard.
 */
class WooPaymentsWooPayPreflightGuardTest extends WC_Unit_Test_Case {

	/** @var array<int,string> Hooks the preflight guard may change. */
	private const GUARDED_HOOKS = array(
		'rest_request_before_callbacks',
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

		$this->post_snapshot = $_POST;
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

	/** @testdox Registers one REST pre-callback and keeps repeated registration idempotent. */
	public function test_registers_pre_callback_once(): void {
		$guard    = new WooPaymentsWooPayPreflightGuard();
		$callback = array( $guard, 'suppress_checkout_side_effects' );

		$this->assertFalse( has_filter( 'rest_request_before_callbacks', $callback ) );

		$guard->register();
		$guard->register();

		$this->assertSame( 10, has_filter( 'rest_request_before_callbacks', $callback ) );
		$this->assertCount( 1, $GLOBALS['wp_filter']['rest_request_before_callbacks']->callbacks[10] );
		$this->assertSame( 3, array_values( $GLOBALS['wp_filter']['rest_request_before_callbacks']->callbacks[10] )[0]['accepted_args'] );
	}

	/** @testdox Leaves checkout hooks unchanged for unrelated, malformed, and missing-marker requests. */
	public function test_non_preflight_requests_leave_checkout_hooks_unchanged(): void {
		$guard                 = new WooPaymentsWooPayPreflightGuard();
		$action_counts         = array( 'meta' => 0, 'processed' => 0, 'pending' => 0 );
		$registration_calls    = 0;
		$coupon_limit_calls    = 0;
		$per_user_limit_calls  = 0;
		$requests              = array(
			'wrong route' => array( '/wc/store/v1/cart', array( array( 'key' => 'is-woopay-preflight-check', 'value' => true ) ) ),
			'malformed payment data' => array( '/wc/store/v1/checkout', 'not-an-array' ),
			'missing exact marker' => array( '/wc/store/v1/checkout', array( array( 'key' => 'not-is-woopay-preflight-check', 'value' => true ) ) ),
		);

		add_action( 'woocommerce_store_api_checkout_update_order_meta', static function () use ( &$action_counts ): void {
			++$action_counts['meta'];
		}, 10, 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', static function () use ( &$action_counts ): void {
			++$action_counts['processed'];
		}, 10, 0 );
		add_action( 'woocommerce_order_status_pending', static function () use ( &$action_counts ): void {
			++$action_counts['pending'];
		}, 10, 0 );
		add_filter( 'woocommerce_checkout_registration_required', static function ( $required ) use ( &$registration_calls ) {
			++$registration_calls;

			return 'sentinel-' . $required;
		} );
		add_filter( 'woocommerce_coupon_get_usage_limit', static function ( $limit ) use ( &$coupon_limit_calls ) {
			++$coupon_limit_calls;

			return 6;
		} );
		add_filter( 'woocommerce_coupon_get_usage_limit_per_user', static function ( $limit ) use ( &$per_user_limit_calls ) {
			++$per_user_limit_calls;

			return 2;
		} );
		$guard->register();
		$expected_callback_count = 0;

		foreach ( $requests as $label => $request_data ) {
			++$expected_callback_count;
			$request = new WP_REST_Request( 'POST', $request_data[0] );
			$response = new \stdClass();
			$request->set_body_params( array( 'payment_data' => $request_data[1] ) );

			$this->assertSame( $response, apply_filters( 'rest_request_before_callbacks', $response, array(), $request ), $label );
			do_action( 'woocommerce_store_api_checkout_update_order_meta' );
			do_action( 'woocommerce_store_api_checkout_order_processed' );
			do_action( 'woocommerce_order_status_pending' );
			$this->assertSame( $expected_callback_count, $action_counts['meta'], $label );
			$this->assertSame( $expected_callback_count, $action_counts['processed'], $label );
			$this->assertSame( $expected_callback_count, $action_counts['pending'], $label );
			$this->assertSame( 'sentinel-original', apply_filters( 'woocommerce_checkout_registration_required', 'original' ), $label );
			$this->assertSame( 6, apply_filters( 'woocommerce_coupon_get_usage_limit', 5, new WC_Coupon() ), $label );
			$this->assertSame( 2, apply_filters( 'woocommerce_coupon_get_usage_limit_per_user', 1, 123, new WC_Coupon() ), $label );
		}

		$this->assertSame( 3, $registration_calls );
		$this->assertSame( 3, $coupon_limit_calls );
		$this->assertSame( 3, $per_user_limit_calls );
	}

	/** @testdox Suppresses checkout and coupon side effects for an exact WooPay preflight request. */
	public function test_preflight_request_suppresses_checkout_and_coupon_side_effects(): void {
		$guard              = new WooPaymentsWooPayPreflightGuard();
		$order              = $this->create_order();
		$coupon             = $this->create_coupon();
		$action_counts      = array( 'meta' => 0, 'processed' => 0, 'pending' => 0 );
		$registration_calls = 0;

		$order->update_status( 'failed' );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', static function () use ( &$action_counts ): void {
			++$action_counts['meta'];
		}, 10, 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', static function () use ( &$action_counts ): void {
			++$action_counts['processed'];
		}, 10, 0 );
		add_action( 'woocommerce_order_status_pending', static function () use ( $coupon, &$action_counts ): void {
			++$action_counts['pending'];
			$coupon->set_usage_count( $coupon->get_usage_count() + 1 );
			$coupon->save();
		}, 10, 0 );
		add_filter( 'woocommerce_checkout_registration_required', static function ( $required ) use ( &$registration_calls ) {
			++$registration_calls;

			return 'sentinel-' . $required;
		} );
		add_filter( 'woocommerce_coupon_get_usage_limit', static function (): int {
			return 6;
		} );
		add_filter( 'woocommerce_coupon_get_usage_limit_per_user', static function (): int {
			return 2;
		} );
		$guard->register();

		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$response = new \stdClass();
		$request->set_body_params(
			array(
				'payment_data' => array(
					array(
						'key'   => 'is-woopay-preflight-check',
						'value' => true,
					),
				),
			)
		);

		$this->assertSame( $response, apply_filters( 'rest_request_before_callbacks', $response, array(), $request ) );
		$this->assertSame( $this->post_snapshot, $_POST, 'The REST pre-callback must not alter request globals.' );
		$_POST['is-woopay-preflight-check'] = '1';
		$service                             = new RecordingPaymentProcessingService();
		$gateway                             = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider() );

		$result = $gateway->process_payment( $order->get_id() );
		$order  = wc_get_order( $order->get_id() );
		$coupon = new WC_Coupon( $coupon->get_id() );

		$this->assertSame( array( 'result' => 'success', 'redirect' => '' ), $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( array( 'meta' => 0, 'processed' => 0, 'pending' => 0 ), $action_counts );
		$this->assertSame( 0, $coupon->get_usage_count() );
		$this->assertSame( 'original', apply_filters( 'woocommerce_checkout_registration_required', 'original' ) );
		$this->assertSame( 0, $registration_calls );
		$this->assertNull( apply_filters( 'woocommerce_coupon_get_usage_limit', 5, $coupon ) );
		$this->assertSame( 0, apply_filters( 'woocommerce_coupon_get_usage_limit_per_user', 1, 123, $coupon ) );
		$this->assertNull( $service->last_checkout_context );
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
