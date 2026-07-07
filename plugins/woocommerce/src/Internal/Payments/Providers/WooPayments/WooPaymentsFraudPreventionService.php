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
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsFraudPreventionService {

	/**
	 * Checkout POST field and session key used by the WooPayments extension.
	 */
	public const TOKEN_NAME = 'wcpay-fraud-prevention-token';

	/**
	 * Fraud-prevention token length used by the WooPayments extension.
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
		if ( $this->session instanceof \WC_Session ) {
			return $this->session;
		}

		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( $woocommerce && $woocommerce->session instanceof \WC_Session ) {
			$this->session = $woocommerce->session;
		}

		return $this->session instanceof \WC_Session ? $this->session : null;
	}
}
