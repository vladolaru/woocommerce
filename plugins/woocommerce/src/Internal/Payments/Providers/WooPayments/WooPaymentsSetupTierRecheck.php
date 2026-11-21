<?php
/**
 * WooPaymentsSetupTierRecheck class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Reads the WooPayments account once after the setup tier dropped from connected or active, so a tier left low by a
 * write that no later read corrects heals within minutes.
 *
 * WooPaymentsAccountService::sync_setup_tier() schedules the check, one WP-Cron event about 15 minutes out, when it writes
 * available over a connected or active tier while the built-in WooPayments owns payments. The tier loader lists this class
 * on cron and WP-CLI requests only while that event is pending, so a store that never held a connected or active tier
 * schedules nothing and loads nothing. The handler refetches the account; the account write rewrites the tier from it. A read
 * the platform did not answer is retried one hour, six hours and a day later, then logged and left.
 *
 * It does not cover a tier write that failed upward (the setup tier's readback check logs it), nor the few lines of the
 * switch's finalize between the extension's deactivation and its tier write.
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsSetupTierRecheck implements RegisterHooksInterface {

	/** The WP-Cron action the recheck runs on. */
	public const ACTION = 'woocommerce_woopayments_recheck_setup_tier';

	/**
	 * Seconds from the drop, or from the errored read before, to each read, keyed by the read's number from 0.
	 *
	 * @var array<int,int>
	 */
	private const DELAYS = array( 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS );

	/**
	 * Account service, which refetches the account and rewrites the tier from it.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Setup tier store.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * Logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @param WooPaymentsSetupTier      $setup_tier      Setup tier store.
	 * @param WooPaymentsRuntimeArbiter $runtime_arbiter Runtime ownership arbiter.
	 * @param WooPaymentsLogger         $logger          Logger.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsSetupTier $setup_tier, WooPaymentsRuntimeArbiter $runtime_arbiter, WooPaymentsLogger $logger ): void {
		$this->account_service = $account_service;
		$this->setup_tier      = $setup_tier;
		$this->runtime_arbiter = $runtime_arbiter;
		$this->logger          = $logger;
	}

	/**
	 * Register the recheck action.
	 */
	public function register() {
		add_action( self::ACTION, array( $this, 'recheck' ) );
	}

	/**
	 * Tell whether a recheck event is waiting, whichever read it is.
	 *
	 * @return bool
	 */
	public static function is_pending(): bool {
		foreach ( array_keys( self::DELAYS ) as $read ) {
			if ( false !== wp_next_scheduled( self::ACTION, array( $read ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Schedule the first read, unless a recheck is already waiting.
	 */
	public static function schedule_first_read(): void {
		if ( ! self::is_pending() ) {
			self::schedule_read( 0 );
		}
	}

	/**
	 * Read the account again while the stored tier is still available, and retry a read the platform did not answer.
	 *
	 * Nothing is read once the tier is back, or while the extension owns payments or the store waits for its switch.
	 *
	 * @internal
	 *
	 * @param mixed $read Number of this read, from 0.
	 */
	public function recheck( $read = 0 ): void {
		$read = is_int( $read ) ? $read : 0;
		if ( ! $this->runtime_arbiter->is_builtin_owner() || WooPaymentsSetupTier::AVAILABLE !== $this->setup_tier->get_stored_tier() ) {
			return;
		}

		if ( $this->account_service->try_refresh_account_data() ) {
			return;
		}

		if ( isset( self::DELAYS[ $read + 1 ] ) ) {
			self::schedule_read( $read + 1 );
			return;
		}

		$this->logger->log_always( 'The WooPayments setup tier could not be confirmed: the account reads after it dropped from connected or active did not complete.', 'warning', array( 'reads' => $read + 1 ) );
	}

	/**
	 * Schedule one read.
	 *
	 * @param int $read Number of the read, from 0.
	 */
	private static function schedule_read( int $read ): void {
		wp_schedule_single_event( time() + self::DELAYS[ $read ], self::ACTION, array( $read ) );
	}
}
