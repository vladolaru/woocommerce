<?php
/**
 * Dismissals class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;

/**
 * Remembers, per user and per surface, that the user dismissed a PayPal Wallet setup notice.
 *
 * The user meta `wc_paypal_wallet_dismissed_<surface>` holds the ID of the latest wallet order the user had seen when
 * they dismissed. A surface stays dismissed until an order with a higher ID arrives while setup is still open.
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
