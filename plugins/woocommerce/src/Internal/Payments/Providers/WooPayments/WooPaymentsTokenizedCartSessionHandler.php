<?php
/**
 * WooPaymentsTokenizedCartSessionHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;
use RuntimeException;

/**
 * WooPayments product-page tokenized cart session handler.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsTokenizedCartSessionHandler extends \WC_Session_Handler {

	private const SESSION_HEADER = 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION';

	private const TOKEN_ISSUER = 'woopayments/product-page';

	/**
	 * Session key holding the customer the tokenized session belongs to: the user ID, or 0 for a guest.
	 */
	private const TOKEN_CUSTOMER_KEY = 'token_customer_id';

	/**
	 * Init tokenized session data without binding the request to the shopper's normal WooCommerce session cookie.
	 *
	 * @internal
	 */
	final public function init(): void {
		$this->init_tokenized_session();

		if ( ! $this->is_ephemeral_request() ) {
			add_action( 'shutdown', array( $this, 'save_data' ), 20 );
		}
	}

	/**
	 * Always treat initialized tokenized sessions as active even though they do not use cookies.
	 *
	 * @return bool
	 */
	public function has_session() {
		return '' !== $this->get_customer_id();
	}

	/**
	 * Generate tokenized guest session IDs even when the shopper is logged in.
	 *
	 * @return string
	 */
	public function generate_customer_id() {
		return wc_rand_hash( 't_', 30 );
	}

	/**
	 * Prevent tokenized product-page requests from setting normal WooCommerce session cookies.
	 *
	 * @param bool $set Whether a cookie should be set.
	 */
	public function set_customer_session_cookie( $set ): void {}

	/**
	 * Keep the tokenized session when the Store API checkout logs in the account it just created.
	 *
	 * Core's wc_set_customer_auth_cookie() calls this; the parent method would load the shopper's browser session and migrate it to
	 * the new user, so the payment would run on that session. Keep the tokenized session, as core's Cart-Token session does
	 * (it has no such method), and bind it to the new user. Core migrates the browser session on the shopper's next request.
	 */
	public function init_session_cookie(): void {
		$this->_data[ self::TOKEN_CUSTOMER_KEY ] = self::get_current_customer_id();
		$this->_dirty                            = true;
	}

	/**
	 * Forget tokenized session data without clearing the shopper's normal browser cookie.
	 */
	public function forget_session(): void {
		$this->_data        = array();
		$this->_dirty       = false;
		$this->_customer_id = $this->generate_customer_id();
		$this->_has_cookie  = false;
	}

	/**
	 * Return an empty cart for a newly-created isolated session.
	 *
	 * @param string $key           Session key.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	public function get( $key, $default_value = null ) {
		if ( 'cart' === $key && ! array_key_exists( 'cart', $this->_data ) ) {
			return array();
		}

		return parent::get( $key, $default_value );
	}

	/**
	 * Initialize this request from the incoming tokenized cart session header or a new guest session.
	 *
	 * The session belongs to the customer who started it, a user or a guest, and is refused for anyone else, like client 11.1.0
	 * (class-wc-payments-payment-request-session-handler.php:50-66). Any guest may use a guest's token, as with core's
	 * Cart-Token: this handler never reads or sets the shopper's browser session cookie, so there is nothing else to bind to.
	 *
	 * @throws RuntimeException When the tokenized session belongs to another customer.
	 */
	private function init_tokenized_session(): void {
		$session_id = $this->get_session_id_from_header();

		if ( '' === $session_id ) {
				$session_id = $this->generate_customer_id();
		}

		$this->_customer_id = $session_id;
		$this->_data        = (array) $this->get_session( $session_id, array() );
		$customer_id        = self::get_current_customer_id();

		if ( isset( $this->_data[ self::TOKEN_CUSTOMER_KEY ] ) && $this->_data[ self::TOKEN_CUSTOMER_KEY ] !== $customer_id ) {
			wc_get_container()->get( WooPaymentsLogger::class )->error(
				'Tokenized cart session customer mismatch.',
				array(
					'session_customer_id' => $this->_data[ self::TOKEN_CUSTOMER_KEY ],
					'customer_id'         => $customer_id,
				)
			);

			throw new RuntimeException( 'Tokenized cart session customer mismatch.' );
		}

		$this->_data[ self::TOKEN_CUSTOMER_KEY ] = $customer_id;
	}

	/**
	 * Get the current customer the tokenized session is bound to.
	 *
	 * @return string The user ID, or '0' for a guest.
	 */
	private static function get_current_customer_id(): string {
		return (string) get_current_user_id();
	}

	/**
	 * Get the tokenized cart session ID from the signed header.
	 *
	 * @return string
	 */
	private function get_session_id_from_header(): string {
		$token = $this->get_server_header( self::SESSION_HEADER );
		if ( '' === $token || ! JsonWebToken::validate( $token, self::get_token_secret() ) ) {
			return '';
		}

		$parts   = JsonWebToken::get_parts( $token );
		$payload = is_object( $parts ) && isset( $parts->payload ) ? $parts->payload : null;
		if ( ! is_object( $payload ) || ! isset( $payload->session_id, $payload->iss ) || self::TOKEN_ISSUER !== $payload->iss ) {
			return '';
		}

		$session_id = is_scalar( $payload->session_id ) ? sanitize_text_field( (string) $payload->session_id ) : '';

		return 0 === strpos( $session_id, 't_' ) ? $session_id : '';
	}

	/**
	 * Get a sanitized HTTP request header from the server environment.
	 *
	 * @param string $key Server header key.
	 * @return string
	 */
	private function get_server_header( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
			return '';
		}

		$value = wc_clean( wp_unslash( (string) $_SERVER[ $key ] ) );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Tell whether this request should be cleaned up after the response.
	 *
	 * @return bool
	 */
	private function is_ephemeral_request(): bool {
		return '1' === $this->get_server_header( 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_IS_EPHEMERAL_CART' );
	}

	/**
	 * Get the JWT signing secret shared by the session controller.
	 *
	 * @return string
	 */
	public static function get_token_secret(): string {
		return '@' . wp_salt();
	}
}
