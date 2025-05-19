<?php
/**
 * Session utility functions for WooCommerce Blocks.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

/**
 * Session utility functions for WooCommerce.
 */
class SessionUtils {
	/**
	 * Generate a unique customer ID for guests, or return user ID if logged in.
	 *
	 * Uses Portable PHP password hashing framework to generate a unique cryptographically strong ID.
	 *
	 * @return string
	 */
	public static function generate_customer_id() {
		$customer_id = '';

		if ( is_user_logged_in() ) {
			$customer_id = strval( get_current_user_id() );
		}

		if ( empty( $customer_id ) ) {
			require_once ABSPATH . 'wp-includes/class-phpass.php';
			$hasher      = new \PasswordHash( 8, false );
			$customer_id = 't_' . substr( md5( $hasher->get_random_bytes( 32 ) ), 2 );
		}

		return $customer_id;
	}

	/**
	 * Get the cart token.
	 *
	 * @return string
	 */
	public static function get_cart_token() {
		return wc_clean( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ?? '' ) );
	}

	/**
	 * Get the cart token secret.
	 *
	 * @return string
	 */
	public static function get_cart_token_secret() {
		return '@' . wp_salt();
	}
}
