<?php
/**
 * WooPaymentsFailedTransactionRateLimiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Tracks failed WooPayments card transactions in the WooCommerce session.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsFailedTransactionRateLimiter {

	/**
	 * Session key used by the standalone WooPayments extension for declined-card attempts.
	 */
	public const SESSION_KEY = 'wcpay_card_declined_registry';

	/**
	 * Number of failed card attempts needed to enable the limiter.
	 */
	private const THRESHOLD = 5;

	/**
	 * Number of seconds the limiter remains active after the threshold is reached.
	 */
	private const DELAY = 10 * MINUTE_IN_SECONDS;

	/**
	 * WooCommerce session.
	 *
	 * @var \WC_Session|null
	 */
	private ?\WC_Session $session;

	/**
	 * Constructor.
	 *
	 * @param \WC_Session|null $session Optional WooCommerce session.
	 */
	public function __construct( ?\WC_Session $session = null ) {
		$this->session = $session;
	}

	/**
	 * Tell whether a WooCommerce session is available for limiter storage.
	 *
	 * @return bool
	 */
	public function has_session(): bool {
		return $this->get_session() instanceof \WC_Session;
	}

	/**
	 * Record a failed transaction attempt.
	 *
	 * @return void
	 */
	public function bump(): void {
		$session = $this->get_session();
		if ( ! $session instanceof \WC_Session ) {
			return;
		}

		$registry = $session->get( self::SESSION_KEY ) ?? array();
		if ( ! is_array( $registry ) ) {
			$registry = array();
		}

		$registry[] = time();
		$session->set( self::SESSION_KEY, $registry );
	}

	/**
	 * Tell whether failed transactions should be rate limited.
	 *
	 * @return bool
	 */
	public function is_limited(): bool {
		$session = $this->get_session();
		if ( ! $session instanceof \WC_Session ) {
			return false;
		}

		if ( 'yes' === get_option( 'wcpay_session_rate_limiter_disabled_' . self::SESSION_KEY ) ) {
			return false;
		}

		$registry = $session->get( self::SESSION_KEY ) ?? array();
		if ( ! is_array( $registry ) || count( $registry ) < self::THRESHOLD ) {
			return false;
		}

		$start_time_limiter  = end( $registry );
		$next_try_allowed_at = (int) $start_time_limiter + self::DELAY;
		$is_limited          = time() <= $next_try_allowed_at;

		if ( ! $is_limited ) {
			$session->set( self::SESSION_KEY, array() );
		}

		return $is_limited;
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
