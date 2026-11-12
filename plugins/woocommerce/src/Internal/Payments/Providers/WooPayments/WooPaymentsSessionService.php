<?php
/**
 * WooPaymentsSessionService class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the Sift session ID that links a shopper's browsing session to
 * their WooPayments customer, and keeps the payment values a session stores
 * readable only in that session.
 *
 * The ID combines a persisted random store ID with the WooCommerce session
 * customer ID, with the pre-login cookie identity used when the shopper
 * logged in during the current request, as in client 11.1.0's session
 * service (class-wc-payments-session-service.php:71-81).
 *
 * @internal
 */
class WooPaymentsSessionService implements RegisterHooksInterface {

	/**
	 * Session values read only in the session that stored them, each with the session key that records the ID of that
	 * session. The WooPayments extension's key names (client 11.3.0 class-wc-payments-customer-service.php:48,
	 * class-duplicate-payment-prevention-service.php:42, class-wc-payments-order-service.php:58).
	 */
	private const OWNER_KEYS = array(
		WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY                        => 'wcpay_customer_id_session',
		WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER => 'wcpay_processing_order_session',
		WooPaymentsOrderSuccessPage::PAID_INTENT_ID_SESSION_KEY                    => 'wcpay_paid_intent_id_session',
	);

	/**
	 * Option holding the persisted random store ID used to derive Sift session IDs.
	 *
	 * Client 11.1.0's option name (class-wc-payments-session-service.php:19): a
	 * store that switches from the WooPayments extension must keep deriving the
	 * same session IDs, or Sift loses the continuity of every ongoing browsing
	 * session.
	 */
	private const SESSION_STORE_ID_OPTION = 'wcpay_session_store_id';

	/**
	 * Get the current WooCommerce session, or null while WooCommerce has none.
	 *
	 * The one lookup the session-backed services share; each keeps the session it finds.
	 *
	 * @return \WC_Session|null
	 */
	public static function get_wc_session(): ?\WC_Session {
		$session = WC()->session;

		return $session instanceof \WC_Session ? $session : null;
	}

	/**
	 * Register the move of the stored values' owner when a guest session becomes a user's.
	 *
	 * @internal
	 */
	public function register() {
		add_action( 'woocommerce_guest_session_to_user_id', array( $this, 'move_owned_values_to_user_session' ), 10, 2 );
	}

	/**
	 * Store a session value with the ID of the session that stores it; null removes the value.
	 *
	 * @param \WC_Session $session Session to store the value in.
	 * @param string      $key     A key of OWNER_KEYS.
	 * @param mixed       $value   Value to store, or null to remove it.
	 */
	public static function set_owned_value( \WC_Session $session, string $key, $value ): void {
		$session->set( $key, $value );
		$session->set( self::OWNER_KEYS[ $key ], null === $value ? null : (string) $session->get_customer_id() );
	}

	/**
	 * Read a session value only when the current session stored it; a value with no recorded session reads as absent
	 * (client 11.3.0 class-wc-payments-customer-service.php:396-405).
	 *
	 * @param \WC_Session $session Session to read the value from.
	 * @param string      $key     A key of OWNER_KEYS.
	 * @return mixed The value, or null.
	 */
	public static function get_owned_value( \WC_Session $session, string $key ) {
		$value = $session->get( $key );
		if ( null === $value || $session->get( self::OWNER_KEYS[ $key ] ) !== (string) $session->get_customer_id() ) {
			return null;
		}

		return $value;
	}

	/**
	 * When WooCommerce moves a guest session to the shopper's account, record the account's session as the owner of each
	 * value the guest session stored (client 11.3.0 class-wc-payments-session-service.php:43-75).
	 *
	 * @internal
	 *
	 * @param mixed $guest_session_id The former session ID.
	 * @param mixed $user_session_id  The ID of the session the guest session moved to.
	 */
	public function move_owned_values_to_user_session( $guest_session_id, $user_session_id ): void {
		$session = self::get_wc_session();
		if (
			null === $session
			|| ! is_string( $guest_session_id ) || '' === $guest_session_id
			|| ! is_string( $user_session_id ) || '' === $user_session_id
			|| (string) $session->get_customer_id() !== $user_session_id
		) {
			return;
		}

		foreach ( self::OWNER_KEYS as $owner_key ) {
			if ( $session->get( $owner_key ) === $guest_session_id ) {
				$session->set( $owner_key, $user_session_id );
			}
		}
	}

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
	public function user_just_logged_in(): bool {
		if ( ! get_current_user_id() ) {
			return false;
		}

		WC()->initialize_session();
		$session_handler = WC()->session;
		// Some session handlers (e.g. the Store API one) do not expose the cookie.
		if ( ! method_exists( $session_handler, 'get_session_cookie' ) ) {
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
		return $this->get_or_create_store_id() . '_' . $session_customer_id;
	}

	/**
	 * Get the persisted random store ID, generating it on first use.
	 *
	 * @return string
	 */
	private function get_or_create_store_id(): string {
		$store_id = get_option( self::SESSION_STORE_ID_OPTION, false );
		if ( ! is_string( $store_id ) || '' === $store_id ) {
			// 'st_' prefix plus alphanumerics only, within Sift's user_id charset.
			$store_id = 'st_' . wp_generate_password( 29, false, false );
			update_option( self::SESSION_STORE_ID_OPTION, $store_id );
		}

		return $store_id;
	}
}
