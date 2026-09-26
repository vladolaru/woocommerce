<?php
/**
 * WooPaymentsWooPayPreflightGuard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WP_REST_Request;

/**
 * Suppresses checkout side effects during a WooPay preflight request.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPayPreflightGuard implements RegisterHooksInterface {

	/** Store API checkout route. */
	private const STORE_API_CHECKOUT_ROUTE = '/wc/store/v1/checkout';

	/** WooPay payment-data marker. */
	private const PREFLIGHT_PAYMENT_DATA_KEY = 'is-woopay-preflight-check';

	/** Checkout hooks whose callbacks are temporarily changed during a preflight request. */
	private const CHECKOUT_SIDE_EFFECT_HOOKS = array(
		'woocommerce_store_api_checkout_update_order_meta',
		'woocommerce_store_api_checkout_order_processed',
		'woocommerce_order_status_pending',
		'woocommerce_checkout_registration_required',
		'woocommerce_coupon_get_usage_limit',
		'woocommerce_coupon_get_usage_limit_per_user',
	);

	/**
	 * Checkout hook snapshots keyed by their owning REST request object.
	 *
	 * @var array<int,array<string,\WP_Hook|null>>
	 */
	private $checkout_hook_snapshots = array();

	/**
	 * Register request lifecycle callbacks once.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function register() {
		if ( false === has_filter( 'rest_request_before_callbacks', array( $this, 'suppress_checkout_side_effects' ) ) ) {
			add_filter( 'rest_request_before_callbacks', array( $this, 'suppress_checkout_side_effects' ), 10, 3 );
		}

		if ( false === has_filter( 'rest_request_after_callbacks', array( $this, 'restore_checkout_side_effects' ) ) ) {
			add_filter( 'rest_request_after_callbacks', array( $this, 'restore_checkout_side_effects' ), 10, 3 );
		}
	}

	/**
	 * Suppress checkout side effects for an exact WooPay preflight request.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed           $response The response object.
	 * @param mixed           $handler The REST route handler.
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed The unchanged response object.
	 */
	public function suppress_checkout_side_effects( $response, $handler, WP_REST_Request $request ) {
		unset( $handler );

		if ( self::STORE_API_CHECKOUT_ROUTE !== $request->get_route() || ! $this->is_woopay_preflight_request( $request ) ) {
			return $response;
		}

		$this->snapshot_checkout_side_effect_hooks( $request );
		remove_all_actions( 'woocommerce_store_api_checkout_update_order_meta' );
		remove_all_actions( 'woocommerce_store_api_checkout_order_processed' );
		remove_all_actions( 'woocommerce_order_status_pending' );
		remove_all_filters( 'woocommerce_checkout_registration_required' );
		add_filter( 'woocommerce_coupon_get_usage_limit', '__return_null' );
		add_filter( 'woocommerce_coupon_get_usage_limit_per_user', '__return_zero' );

		return $response;
	}

	/**
	 * Restore checkout side effects after their owning WooPay preflight request.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed           $response The response object.
	 * @param mixed           $handler The REST route handler.
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed The unchanged response object.
	 */
	public function restore_checkout_side_effects( $response, $handler, WP_REST_Request $request ) {
		unset( $handler );

		$request_id = spl_object_id( $request );

		if ( ! isset( $this->checkout_hook_snapshots[ $request_id ] ) ) {
			return $response;
		}

		$this->restore_checkout_side_effect_hooks( $this->checkout_hook_snapshots[ $request_id ] );
		unset( $this->checkout_hook_snapshots[ $request_id ] );

		return $response;
	}

	/**
	 * Snapshot checkout hooks before suppressing them for a WooPay preflight request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return void
	 */
	private function snapshot_checkout_side_effect_hooks( WP_REST_Request $request ) {
		$request_id = spl_object_id( $request );

		if ( isset( $this->checkout_hook_snapshots[ $request_id ] ) ) {
			return;
		}

		$this->checkout_hook_snapshots[ $request_id ] = array();

		foreach ( self::CHECKOUT_SIDE_EFFECT_HOOKS as $hook ) {
			$this->checkout_hook_snapshots[ $request_id ][ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) && $GLOBALS['wp_filter'][ $hook ] instanceof \WP_Hook ? clone $GLOBALS['wp_filter'][ $hook ] : null;
		}
	}

	/**
	 * Restore checkout hooks to a preflight request's original callbacks.
	 *
	 * @param array<string,\WP_Hook|null> $hook_snapshots Hook snapshots keyed by hook name.
	 * @return void
	 */
	private function restore_checkout_side_effect_hooks( array $hook_snapshots ) {
		foreach ( $hook_snapshots as $hook => $snapshot ) {
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
	 * Check whether a Store API request contains the exact WooPay preflight marker.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool Whether the request is a WooPay preflight request.
	 */
	private function is_woopay_preflight_request( WP_REST_Request $request ): bool {
		$payment_data = $request->get_param( 'payment_data' );

		if ( ! is_array( $payment_data ) ) {
			return false;
		}

		foreach ( $payment_data as $payment_data_record ) {
			if ( is_array( $payment_data_record ) && self::PREFLIGHT_PAYMENT_DATA_KEY === ( $payment_data_record['key'] ?? null ) && ! empty( $payment_data_record['value'] ) ) {
				return true;
			}
		}

		return false;
	}
}
