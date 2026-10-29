<?php
/**
 * WooPaymentsFraudPreventionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Manages WooPayments card-testing fraud-prevention session tokens.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsFraudPreventionService {

	/**
	 * Checkout POST field and session key; client 11.1.0 uses the same (fraud-prevention/class-fraud-prevention-service.php:18).
	 */
	public const TOKEN_NAME = 'wcpay-fraud-prevention-token';

	/**
	 * Fraud-prevention token length, client 11.1.0's (fraud-prevention/class-fraud-prevention-service.php:157).
	 */
	private const TOKEN_LENGTH = 16;

	/**
	 * WooCommerce session.
	 *
	 * @var \WC_Session|null
	 */
	private ?\WC_Session $session;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Constructor.
	 *
	 * @param \WC_Session|null $session Optional WooCommerce session.
	 */
	public function __construct( ?\WC_Session $session = null ) {
		$this->session = $session;
	}

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Tell whether a WooCommerce session is available for token storage.
	 *
	 * @return bool
	 */
	public function has_session(): bool {
		return $this->get_session() instanceof \WC_Session;
	}

	/**
	 * Tell whether card-testing fraud-prevention checks are enabled for the account.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();

		return ! empty( $account_data['card_testing_protection_eligible'] );
	}

	/**
	 * Get the current fraud-prevention token, generating one when missing.
	 *
	 * @return string
	 */
	public function get_token(): string {
		$session = $this->get_session();
		if ( ! $session instanceof \WC_Session ) {
			return '';
		}

		$token = $session->get( self::TOKEN_NAME );
		if ( ! is_string( $token ) || '' === $token ) {
			return $this->regenerate_token();
		}

		return $token;
	}

	/**
	 * Regenerate and persist the fraud-prevention token.
	 *
	 * @return string
	 */
	public function regenerate_token(): string {
		$session = $this->get_session();
		if ( ! $session instanceof \WC_Session ) {
			return '';
		}

		$token = wp_generate_password( self::TOKEN_LENGTH, false );
		$session->set( self::TOKEN_NAME, $token );

		return $token;
	}

	/**
	 * Enqueue the inline script exposing the fraud-prevention token to
	 * shopper-side scripts, when protection applies to this session and page.
	 *
	 * Callers call it unguarded: the page check lives here, in client 11.2.0's
	 * order (fraud-prevention/class-fraud-prevention-service.php:73-105), so the
	 * checkout page counts by page id however its Checkout block renders. The
	 * enqueued-script guard makes the token script a per-page singleton.
	 */
	public function maybe_enqueue_token_script(): void {
		if ( wp_script_is( self::TOKEN_NAME, 'enqueued' ) ) {
			return;
		}
		if ( ! $this->has_session() || ! $this->is_enabled() ) {
			return;
		}
		// Product and cart pages count because express buttons pay from them; the add payment method page guards setup intents.
		if ( ! is_checkout() && ! has_block( 'woocommerce/checkout' ) && ! is_cart() && ! is_product() && ! $this->is_pay_for_order_page() && ! is_add_payment_method_page() ) {
			return;
		}

		if ( ! wp_script_is( self::TOKEN_NAME, 'registered' ) ) {
			wp_register_script( self::TOKEN_NAME, false, array(), WC_VERSION, true );
		}
		wp_add_inline_script(
			self::TOKEN_NAME,
			"window.wcpayFraudPreventionToken = '" . esc_js( $this->get_token() ) . "';"
		);
		wp_enqueue_script( self::TOKEN_NAME );
	}

	/**
	 * Verify a submitted token against the session token.
	 *
	 * @param string|null $token Submitted token.
	 * @return bool
	 */
	public function verify_token( ?string $token ): bool {
		$session_token = $this->get_token();
		if ( '' === $session_token || ! is_string( $token ) ) {
			return false;
		}

		return hash_equals( $session_token, $token );
	}

	/**
	 * Tell whether the current request is the pay-for-order page, as client 11.2.0 reads it
	 * (fraud-prevention/class-fraud-prevention-service.php:112-114).
	 *
	 * @return bool
	 */
	private function is_pay_for_order_page(): bool {
		return is_checkout() && isset( $_GET['pay_for_order'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
	}

	/**
	 * Get the WooPayments account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( ! isset( $this->account_service ) ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Get the current WooCommerce session.
	 *
	 * @return \WC_Session|null
	 */
	private function get_session(): ?\WC_Session {
		if ( ! $this->session instanceof \WC_Session ) {
			$this->session = WooPaymentsSessionService::get_wc_session();
		}

		return $this->session;
	}
}
