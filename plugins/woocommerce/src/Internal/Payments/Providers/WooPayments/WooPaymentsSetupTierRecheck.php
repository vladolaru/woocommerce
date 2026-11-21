<?php
/**
 * WooPaymentsSetupTierRecheck class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Reads the WooPayments account after the setup tier dropped from connected or active, so a tier left low while the
 * account exists heals within minutes.
 *
 * WooPaymentsAccountService::sync_setup_tier() starts the reads when it writes available over a connected or active tier
 * while the built-in WooPayments owns payments: four WP-Cron events, 15 minutes, 1 h 15, 7 h 15 and 31 h 15 after the drop,
 * dropping any left from an earlier drop. The tier loader lists this class on cron and WP-CLI requests only while one is
 * pending. A read can only raise the tier: an account the platform finds is written as a normal read writes it, and a read
 * that finds no account, or fails, writes nothing. Lowering stays with the foreground account reads.
 *
 * Known limits: under ALTERNATE_WP_CRON, WordPress runs due events inside a front request, where this class is not loaded,
 * so a read run there is lost and the tier waits for a foreground read. It does not cover a tier write that failed upward
 * (the setup tier's readback check logs it), nor the few lines of the switch's finalize between the extension's
 * deactivation and its tier write.
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsSetupTierRecheck implements RegisterHooksInterface {

	/** The WP-Cron action the recheck runs on. */
	public const ACTION = 'woocommerce_woopayments_recheck_setup_tier';

	/**
	 * Seconds from the drop to each read: 15 minutes, then one hour, six hours and a day after the read before.
	 *
	 * @var array<int,int>
	 */
	private const READ_OFFSETS = array( 15 * MINUTE_IN_SECONDS, 75 * MINUTE_IN_SECONDS, 435 * MINUTE_IN_SECONDS, 1875 * MINUTE_IN_SECONDS );

	/**
	 * Account service, which reads the account and raises the tier from it.
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
	 * Tell whether a read is waiting. One lookup in the autoloaded cron array.
	 *
	 * @return bool
	 */
	public static function is_pending(): bool {
		return false !== wp_next_scheduled( self::ACTION );
	}

	/**
	 * Start the reads afresh: drop any left from an earlier drop and schedule all four.
	 *
	 * A schedule that already holds an identical event, written by a concurrent drop, is not a failure.
	 */
	public static function start(): void {
		$failed = self::clear_failed();
		$now    = time();
		foreach ( self::READ_OFFSETS as $offset ) {
			$result = wp_schedule_single_event( $now + $offset, self::ACTION, array(), true );
			if ( true !== $result && ! ( is_wp_error( $result ) && 'duplicate_event' === $result->get_error_code() ) ) {
				$failed = true;
			}
		}

		if ( $failed ) {
			self::log_schedule_failure();
		}
	}

	/**
	 * Drop the reads still pending.
	 */
	public static function stop(): void {
		if ( self::clear_failed() ) {
			self::log_schedule_failure();
		}
	}

	/**
	 * Read the account while the stored tier is still available, and end the reads once the platform answered.
	 *
	 * Nothing is read once the tier is back, or while the extension owns payments or the store waits for its switch. A read
	 * that failed leaves the later reads pending; when none is left, it logs one line.
	 *
	 * @internal
	 */
	public function recheck(): void {
		if ( ! $this->runtime_arbiter->is_builtin_owner() || WooPaymentsSetupTier::AVAILABLE !== $this->setup_tier->get_stored_tier() ) {
			self::stop();
			return;
		}

		if ( $this->account_service->read_account_to_raise_setup_tier() ) {
			self::stop();
			return;
		}

		if ( ! self::is_pending() ) {
			$this->logger->log_always( 'The WooPayments setup tier could not be confirmed: the account reads after it dropped from connected or active did not complete.', 'warning' );
		}
	}

	/**
	 * Clear every pending read and tell whether WordPress could not.
	 *
	 * @return bool
	 */
	private static function clear_failed(): bool {
		$result = wp_clear_scheduled_hook( self::ACTION, array(), true );

		return false === $result || is_wp_error( $result );
	}

	/**
	 * Log that the reads could not be scheduled or cleared, whatever the logging setting.
	 */
	private static function log_schedule_failure(): void {
		wc_get_container()->get( WooPaymentsLogger::class )->log_always( 'The WooPayments setup tier recheck could not update its WP-Cron events.', 'warning' );
	}
}
