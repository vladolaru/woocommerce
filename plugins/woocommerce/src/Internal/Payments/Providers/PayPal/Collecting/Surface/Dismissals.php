<?php
/**
 * Dismissals class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use WC_AJAX;

/**
 * Remembers, per user and per surface, that the user dismissed a PayPal Wallet setup notice.
 *
 * The user meta `wc_paypal_wallet_dismissed_<surface>` holds the ID of the latest wallet order the user had seen when
 * they dismissed. A surface stays dismissed until an order with a higher ID arrives while setup is still open. The order
 * notice stores the ID of the order it was dismissed on.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class Dismissals {

	/**
	 * The start of the user meta key; the surface slug follows.
	 *
	 * @since 11.3.0
	 */
	public const META_PREFIX = 'wc_paypal_wallet_dismissed_';

	/**
	 * The `wc_ajax` action that stores a dismissal, and the action of its nonce.
	 *
	 * @since 11.3.0
	 */
	public const AJAX_ACTION = 'wc_paypal_wallet_dismiss_notice';

	/**
	 * The surfaces a request may dismiss.
	 *
	 * @since 11.3.0
	 */
	public const SURFACES = array( ProviderRow::SURFACE, OrderScreen::SURFACE );

	/**
	 * Hook the dismiss action. Attached once by OwnerIndependent on a store with wallet history.
	 *
	 * @since 11.3.0
	 */
	public function register(): void {
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'handle_wc_ajax_wc_paypal_wallet_dismiss_notice' ) );
	}

	/**
	 * The nonced `wc_ajax` URL that dismisses a surface for the current user.
	 *
	 * @since 11.3.0
	 *
	 * @param string $surface  The surface slug.
	 * @param int    $order_id The order the notice is on, for the order notice; 0 otherwise.
	 * @return string
	 */
	public function dismiss_url( string $surface, int $order_id = 0 ): string {
		$args = array(
			'surface'  => $surface,
			'_wpnonce' => wp_create_nonce( self::AJAX_ACTION ),
		);
		if ( $order_id > 0 ) {
			$args['order_id'] = $order_id;
		}

		return add_query_arg( $args, WC_AJAX::get_endpoint( self::AJAX_ACTION ) );
	}

	/**
	 * Store the dismissal and answer the request with its status.
	 *
	 * Hooked to `wc_ajax_wc_paypal_wallet_dismiss_notice`.
	 *
	 * @since 11.3.0
	 */
	public function handle_wc_ajax_wc_paypal_wallet_dismiss_notice(): void {
		// The nonce is checked in dismiss_from_request().
		$status = $this->dismiss_from_request( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 200 === $status ) {
			wp_send_json_success();
		}

		wp_send_json_error( null, $status );
	}

	/**
	 * Store the dismissal of a surface for the current user, and say how the request went.
	 *
	 * @since 11.3.0
	 *
	 * @param array $request The request parameters: `_wpnonce`, `surface` (the row notice when absent), `order_id` for the order notice.
	 * @return int 403 for a bad nonce or a user without the capability, 400 for an unknown surface or a missing order ID, 500 when the write did not round-trip, 200 when stored.
	 */
	public function dismiss_from_request( array $request ): int {
		$nonce = isset( $request['_wpnonce'] ) && is_string( $request['_wpnonce'] ) ? $request['_wpnonce'] : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, self::AJAX_ACTION ) ) {
			return 403;
		}

		$surface = isset( $request['surface'] ) && is_string( $request['surface'] ) ? sanitize_key( $request['surface'] ) : ProviderRow::SURFACE;
		if ( ! in_array( $surface, self::SURFACES, true ) ) {
			return 400;
		}

		$latest_order_id = 0;
		if ( OrderScreen::SURFACE === $surface ) {
			$latest_order_id = isset( $request['order_id'] ) && is_numeric( $request['order_id'] ) ? (int) $request['order_id'] : 0;
			if ( $latest_order_id <= 0 ) {
				return 400;
			}
		}

		return $this->dismiss( $surface, get_current_user_id(), $latest_order_id ) ? 200 : 500;
	}

	/**
	 * Record that a user dismissed a surface.
	 *
	 * @since 11.3.0
	 *
	 * @param string $surface         The surface slug, such as `row-notice`.
	 * @param int    $user_id         The user.
	 * @param int    $latest_order_id The latest wallet order the user has seen; the first order's ID when left out.
	 * @return bool Whether the dismissal is stored.
	 */
	public function dismiss( string $surface, int $user_id, int $latest_order_id = 0 ): bool {
		$key = $this->meta_key( $surface );
		if ( '' === $key || $user_id <= 0 ) {
			return false;
		}
		if ( $latest_order_id <= 0 ) {
			$latest_order_id = ( new Options() )->first_order_id();
		}

		update_user_meta( $user_id, $key, $latest_order_id );

		$stored = (int) get_user_meta( $user_id, $key, true );

		return $stored === $latest_order_id;
	}

	/**
	 * Whether a user dismissed a surface and no newer order has arrived since.
	 *
	 * @since 11.3.0
	 *
	 * @param string $surface        The surface slug.
	 * @param int    $user_id        The user.
	 * @param int    $since_order_id The latest wallet order the surface knows of.
	 * @return bool
	 */
	public function is_dismissed( string $surface, int $user_id, int $since_order_id ): bool {
		$key = $this->meta_key( $surface );
		if ( '' === $key || $user_id <= 0 ) {
			return false;
		}

		$stored = get_user_meta( $user_id, $key, true );

		return '' !== $stored && (int) $stored >= $since_order_id;
	}

	/**
	 * The user meta key of a surface, or an empty string when the slug is empty.
	 *
	 * @param string $surface The surface slug.
	 * @return string
	 */
	private function meta_key( string $surface ): string {
		$surface = sanitize_key( $surface );

		return '' === $surface ? '' : self::META_PREFIX . $surface;
	}
}
