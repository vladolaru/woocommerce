<?php
/**
 * Session utility functions for WooCommerce Blocks.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Utilities;

use Automattic\WooCommerce\StoreApi\Authentication;
use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;

/**
 * Session utility functions.
 */
class SessionUtils {
	/**
	 * Generate a cart token.
	 *
	 * @param int $customer_id The customer ID.
	 * @return string
	 */
	public static function get_cart_token_for_customer( $customer_id ) {
		return JsonWebToken::create(
			array(
				'user_id' => $customer_id,
				'exp'     => self::get_cart_token_expiration(),
				'iss'     => 'store-api',
			),
			self::get_cart_token_secret()
		);
	}

	/**
	 * Generate a unique customer ID for guests, or return user ID if logged in.
	 *
	 * @return string
	 */
	public static function generate_customer_id() {
		return is_user_logged_in() ? strval( get_current_user_id() ) : wc_rand_hash( 't_' );
	}

	/**
	 * Get the cart token from the request header.
	 *
	 * @return string
	 */
	public static function get_cart_token() {
		return wc_clean( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ?? '' ) );
	}

	/**
	 * Validate the cart token.
	 *
	 * @param string $cart_token The cart token.
	 * @return bool
	 */
	public static function validate_cart_token( $cart_token ) {
		return JsonWebToken::validate( $cart_token, self::get_cart_token_secret() );
	}

	/**
	 * Get the cart token secret.
	 *
	 * @return string
	 */
	public static function get_cart_token_secret() {
		return '@' . wp_salt();
	}

	/**
	 * Gets the expiration of the cart token. Defaults to 48h.
	 *
	 * @return int
	 */
	protected static function get_cart_token_expiration() {
		/**
		 * Filters the session expiration.
		 *
		 * @since 8.7.0
		 * @param int $expiration Expiration in seconds.
		 */
		return time() + intval( apply_filters( 'wc_session_expiration', DAY_IN_SECONDS * 2 ) );
	}
}
