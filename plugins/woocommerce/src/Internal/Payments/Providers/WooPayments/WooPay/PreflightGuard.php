<?php
/**
 * PreflightGuard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WP_Error;
use WP_REST_Request;

/**
 * For a Store API checkout, or pay-for-order checkout, that names the WooPayments gateway and carries WooPay's preflight
 * marker: when WooPay proved it composed the request, empties the order meta, order processed, pending status and
 * registration-required hooks and holds no coupon use for that request, then restores them; otherwise refuses it before
 * any route callback runs. Coupon usage limits apply. The WooPayments gateway answers a proved preflight without charging.
 *
 * @since 11.2.0
 * @internal
 */
class PreflightGuard implements RegisterHooksInterface {

	/** Store API checkout and pay-for-order checkout routes, in any Store API namespace, as WooPay's own route list matches them. */
	private const STORE_API_CHECKOUT_ROUTE_PATTERN = '@^/wc/store(/v[\d]+)?/checkout(/[\d]+)?$@';

	/** WooPay payment-data marker. */
	private const PREFLIGHT_PAYMENT_DATA_KEY = 'is-woopay-preflight-check';

	/** Error code of a refused preflight, the WooPayments extension's (client 11.3.0 class-wc-payment-gateway-wcpay.php:948). */
	public const INVALID_PREFLIGHT_ERROR_CODE = 'wcpay_invalid_woopay_preflight';

	/** Checkout hooks whose callbacks are temporarily changed during a preflight request. */
	private const CHECKOUT_SIDE_EFFECT_HOOKS = array(
		'woocommerce_store_api_checkout_update_order_meta',
		'woocommerce_store_api_checkout_order_processed',
		'woocommerce_order_status_pending',
		'woocommerce_checkout_registration_required',
		'woocommerce_coupon_hold_minutes',
	);

	/**
	 * Checkout hook snapshots keyed by their owning REST request object.
	 *
	 * @var array<int,array<string,\WP_Hook|null>>
	 */
	private $checkout_hook_snapshots = array();

	/**
	 * Handler of WooPay's Store API requests, which tells whether WooPay composed the request.
	 *
	 * @var StoreApiRequestHandler
	 */
	private StoreApiRequestHandler $store_api_request_handler;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StoreApiRequestHandler $store_api_request_handler Handler of WooPay's Store API requests.
	 */
	final public function init( StoreApiRequestHandler $store_api_request_handler ): void {
		$this->store_api_request_handler = $store_api_request_handler;
	}

	/**
	 * Register request lifecycle callbacks once.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'suppress_checkout_side_effects' ), 10, 3 );

		add_filter( 'rest_request_after_callbacks', array( $this, 'restore_checkout_side_effects' ), 10, 3 );
	}

	/**
	 * Suppress checkout side effects for a WooPay preflight request WooPay proved it composed, and refuse one it did not.
	 *
	 * An earlier error passes through unchanged. A refused request gets a 403 before its permission and route callbacks
	 * run, and leaves the hooks as they are (client 11.3.0 class-wc-payment-gateway-wcpay.php:928-958).
	 *
	 * @since 11.2.0
	 *
	 * @param mixed           $response The response object.
	 * @param mixed           $handler The REST route handler.
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed The unchanged response object, or the refusal.
	 */
	public function suppress_checkout_side_effects( $response, $handler, WP_REST_Request $request ) {
		unset( $handler );

		if ( is_wp_error( $response ) || ! $this->is_woopayments_preflight_checkout( $request ) ) {
			return $response;
		}

		if ( ! $this->store_api_request_handler->is_request_composed_by_woopay() ) {
			$this->store_api_request_handler->log_preflight_without_proof();

			return new WP_Error(
				self::INVALID_PREFLIGHT_ERROR_CODE,
				__( "We're not able to verify this WooPay preflight request. Please try again.", 'woocommerce' ),
				array( 'status' => 403 )
			);
		}

		$this->snapshot_checkout_side_effect_hooks( $request );
		remove_all_actions( 'woocommerce_store_api_checkout_update_order_meta' );
		remove_all_actions( 'woocommerce_store_api_checkout_order_processed' );
		remove_all_actions( 'woocommerce_order_status_pending' );
		remove_all_filters( 'woocommerce_checkout_registration_required' );
		// Coupon usage limits stay on; a zero hold time keeps the preflight from holding a coupon use, last so no other
		// callback puts the hold back (client 11.3.0 class-wc-payment-gateway-wcpay.php:962-964).
		add_filter( 'woocommerce_coupon_hold_minutes', '__return_zero', PHP_INT_MAX );

		return $response;
	}

	/**
	 * Tell whether checkout payment data carries WooPay's preflight marker.
	 *
	 * The one reading of the marker: the REST guard passes the request's payment data keyed as the Store API keys it, and
	 * the gateway passes the posted fields, which the Store API fills from that same keyed data (StoreApi\Legacy).
	 *
	 * @param array<mixed> $payment_data Payment data keyed by field name.
	 * @return bool
	 */
	public static function is_marked_as_preflight( array $payment_data ): bool {
		return ! empty( $payment_data[ self::PREFLIGHT_PAYMENT_DATA_KEY ] );
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
	 * Check whether a request is a Store API checkout or pay-for-order checkout that names the WooPayments gateway and
	 * carries WooPay's preflight marker.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	private function is_woopayments_preflight_checkout( WP_REST_Request $request ): bool {
		return 1 === preg_match( self::STORE_API_CHECKOUT_ROUTE_PATTERN, $request->get_route() )
			&& WooPaymentsPersistenceVocabulary::GATEWAY_ID === $request->get_param( 'payment_method' )
			&& self::is_marked_as_preflight( $this->get_keyed_payment_data( $request ) );
	}

	/**
	 * Get a request's payment data keyed by field name, sanitized as the Store API sanitizes it before the gateway reads it
	 * (CheckoutTrait::get_request_payment_data()).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string,mixed>
	 */
	private function get_keyed_payment_data( WP_REST_Request $request ): array {
		$payment_data = $request->get_param( 'payment_data' );
		$keyed        = array();

		if ( ! is_array( $payment_data ) ) {
			return $keyed;
		}

		foreach ( $payment_data as $record ) {
			if ( is_array( $record ) && isset( $record['key'] ) && is_string( $record['key'] ) ) {
				$keyed[ sanitize_key( $record['key'] ) ] = wc_clean( $record['value'] ?? '' );
			}
		}

		return $keyed;
	}
}
