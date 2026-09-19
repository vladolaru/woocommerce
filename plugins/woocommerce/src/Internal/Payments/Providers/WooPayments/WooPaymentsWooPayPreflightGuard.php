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

	/**
	 * Register the request pre-callback once.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function register() {
		if ( false === has_filter( 'rest_request_before_callbacks', array( $this, 'suppress_checkout_side_effects' ) ) ) {
			add_filter( 'rest_request_before_callbacks', array( $this, 'suppress_checkout_side_effects' ), 10, 3 );
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

		remove_all_actions( 'woocommerce_store_api_checkout_update_order_meta' );
		remove_all_actions( 'woocommerce_store_api_checkout_order_processed' );
		remove_all_actions( 'woocommerce_order_status_pending' );
		remove_all_filters( 'woocommerce_checkout_registration_required' );
		add_filter( 'woocommerce_coupon_get_usage_limit', '__return_null' );
		add_filter( 'woocommerce_coupon_get_usage_limit_per_user', '__return_zero' );

		return $response;
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
