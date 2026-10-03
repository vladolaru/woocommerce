<?php
/**
 * WooPaymentsSessionService class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

defined( 'ABSPATH' ) || exit;

/**
 * Browsing-session identity for native WooPayments.
 *
 * Derives the Sift session ID that links a shopper's browsing session to
 * their WooPayments customer, mirroring the WooPayments plugin's session
 * service: a persisted random store ID combined with the WooCommerce session
 * customer ID, with the pre-login cookie identity used when the shopper
 * logged in during the current request.
 *
 * @internal
 */
class WooPaymentsSessionService {

	/**
	 * Option holding the persisted random store ID used to derive Sift session IDs.
	 *
	 * Deliberately reuses the WooPayments plugin's option name: a store cut over
	 * from the plugin to the native integration must keep deriving the same
	 * session IDs, or Sift loses the continuity of every ongoing browsing session.
	 */
	private const SESSION_STORE_ID_OPTION = 'wcpay_session_store_id';

	/**
	 * Get the Sift session ID for the current browsing session.
	 *
	 * @return string|null Session ID, or null when there is no valid session for the current process.
	 */
	public function get_sift_session_id(): ?string {
		if ( $this->user_just_logged_in() ) {
			return $this->get_cookie_session_id();
		}

		if ( WC()->session instanceof \WC_Session ) {
			return $this->generate_session_id( (string) WC()->session->get_customer_id() );
		}

		return null;
	}

	/**
	 * Tell whether the current user logged in during this request — their
	 * session cookie still carries the pre-login customer ID.
	 *
	 * @return bool
	 */
	private function user_just_logged_in(): bool {
		if ( ! get_current_user_id() ) {
			return false;
		}

		WC()->initialize_session();
		$session_handler = WC()->session;
		// Some session handlers (e.g. the Store API one) do not expose the cookie.
		if ( ! $session_handler || ! method_exists( $session_handler, 'get_session_cookie' ) ) {
			return false;
		}
		$cookie = $session_handler->get_session_cookie();
		if ( ! $cookie ) {
			return false;
		}

		return $session_handler->get_customer_id() !== $cookie[0];
	}

	/**
	 * Get the session ID carried by the session cookie — the ID used for the
	 * browsing session up to now, before any login rotated the customer ID.
	 *
	 * @return string|null Session ID, or null when unknown.
	 */
	private function get_cookie_session_id(): ?string {
		$session_handler = WC()->session;
		if ( ! $session_handler || ! method_exists( $session_handler, 'get_session_cookie' ) ) {
			return null;
		}
		$cookie = $session_handler->get_session_cookie();
		if ( ! $cookie || ! isset( $cookie[0] ) ) {
			return null;
		}

		return $this->generate_session_id( (string) $cookie[0] );
	}

	/**
	 * Derive a Sift session ID from the persisted store ID and a session customer ID.
	 *
	 * @param string $session_customer_id WooCommerce session customer ID.
	 * @return string
	 */
	private function generate_session_id( string $session_customer_id ): string {
		return $this->get_store_id() . '_' . $session_customer_id;
	}

	/**
	 * Get the persisted random store ID, generating it on first use.
	 *
	 * @return string
	 */
	private function get_store_id(): string {
		$store_id = get_option( self::SESSION_STORE_ID_OPTION, false );
		if ( ! is_string( $store_id ) || '' === $store_id ) {
			// 'st_' prefix plus alphanumerics only, within Sift's user_id charset.
			$store_id = 'st_' . wp_generate_password( 29, false, false );
			update_option( self::SESSION_STORE_ID_OPTION, $store_id );
		}

		return $store_id;
	}
}
