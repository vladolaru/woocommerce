<?php
/**
 * WooPaymentsSetupTierRecheck class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Reads the WooPayments account after the built-in dropped the setup tier from connected or active, so a tier left low
 * while the account exists heals without anyone visiting an admin page.
 *
 * The drop schedules four Action Scheduler steps, 15 minutes, 1 h 15, 7 h 15 and 31 h 15 after it, each with the drop time
 * and its own number as arguments. A step only raises the tier, through WooPaymentsAccountService::recheck_low_tier();
 * lowering stays with the foreground account reads. A step that settles the tier removes this drop's later steps; a step
 * that cannot settle it leaves them. The step that ends the schedule logs exactly one line, whatever the logging setting.
 *
 * A step that settles the tier also schedules one look a minute later, as step 0 of the same drop. On a store with a
 * persistent object cache, a request that loaded the options from the database just before a raise can put them back into
 * the cache just after it, and every request then runs at the lowest tier. The look compares the tier in the cached options
 * with the database row and, when they differ, drops the cached options so the next request loads the stored tier, with
 * one logged line. It sends no platform request, writes no option and schedules nothing.
 *
 * Known limits: a step whose process dies, or a last step that throws, logs only Action Scheduler's own failure, and one
 * that dies between the row and the tier write at the last step leaves the tier low. Steps of one drop that a queue backlog
 * runs at once or out of order can each log their line. A store with no account row at a step's start is not recovered,
 * such as after a refused reset whose tier restore failed on a store whose row was already gone, or whose row restore failed.
 * A look that runs where nothing registers its hook, because the extension took over or the feature was turned off within
 * that minute, fails in Action Scheduler and leaves the cache as it is. If the race also put back an older account row, the
 * look drops that cached row the same way when it differs from the database row, with one logged line.
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsSetupTierRecheck {

	/** The last step's number. */
	public const LAST_STEP = 4;

	/** The look's step number: it runs after a step settled the tier, and is not one of the four steps. */
	public const LOOK_STEP = 0;

	/** Seconds from a settled step to its look, past the queue run that settled it. */
	private const LOOK_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Seconds from the drop to each step.
	 *
	 * @var array<int,int>
	 */
	private const STEP_OFFSETS = array(
		1 => 15 * MINUTE_IN_SECONDS,
		2 => 75 * MINUTE_IN_SECONDS,
		3 => 435 * MINUTE_IN_SECONDS,
		4 => 1875 * MINUTE_IN_SECONDS,
	);

	/**
	 * The outcomes that settle the tier, with the level and the text of the line that ends the schedule.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const SETTLED = array(
		WooPaymentsAccountService::RECHECK_RAISED_ACTIVE => array( 'info', 'raised the tier to active: the platform found the account' ),
		WooPaymentsAccountService::RECHECK_RAISED_CONNECTED => array( 'info', 'raised the tier to connected: the platform found the account' ),
		WooPaymentsAccountService::RECHECK_ROW_REFRESHED => array( 'info', 'refreshed the account; the tier had already moved' ),
		WooPaymentsAccountService::RECHECK_NOT_OWNER     => array( 'info', 'ended: the built-in WooPayments no longer owns payments' ),
		WooPaymentsAccountService::RECHECK_TIER_MOVED    => array( 'info', 'ended: the tier was already raised' ),
	);

	/**
	 * The outcomes that leave the later steps, with the level and the text of the line the last step logs.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const UNSETTLED = array(
		WooPaymentsAccountService::RECHECK_NO_ACCOUNT    => array( 'info', 'ended after four reads: the platform reports no account, so the tier stays available' ),
		WooPaymentsAccountService::RECHECK_NO_ROW        => array( 'info', 'ended: there is no account row to compare against, so the tier stays available' ),
		WooPaymentsAccountService::RECHECK_READ_FAILED   => array( 'warning', 'ended after four reads without raising the tier: the account read failed' ),
		WooPaymentsAccountService::RECHECK_NO_ACCOUNT_ID => array( 'warning', 'ended after four reads without raising the tier: the account answer had no account ID' ),
		WooPaymentsAccountService::RECHECK_ROW_CHANGED   => array( 'warning', 'ended after four reads without raising the tier: the account row kept changing' ),
		WooPaymentsAccountService::RECHECK_TIER_WRITE_FAILED => array( 'warning', 'ended after four reads without raising the tier: the tier write failed' ),
	);

	/**
	 * Account service, which reads the account and raises the tier.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Setup tier store, whose cached tier the look compares.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $action_scheduler;

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
	 * @param WooPaymentsAccountService         $account_service  Account service.
	 * @param WooPaymentsSetupTier              $setup_tier       Setup tier store.
	 * @param WooPaymentsActionSchedulerService $action_scheduler Action Scheduler service.
	 * @param WooPaymentsLogger                 $logger           Logger.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsSetupTier $setup_tier, WooPaymentsActionSchedulerService $action_scheduler, WooPaymentsLogger $logger ): void {
		$this->account_service  = $account_service;
		$this->setup_tier       = $setup_tier;
		$this->action_scheduler = $action_scheduler;
		$this->logger           = $logger;
	}

	/**
	 * Run one step from its Action Scheduler hook, resolving the handler only now.
	 *
	 * @internal
	 *
	 * @param mixed $drop_time When the tier dropped.
	 * @param mixed $step      The step's number, from 1, or the look's.
	 */
	public static function run_step( $drop_time = null, $step = null ): void {
		wc_get_container()->get( self::class )->handle( $drop_time, $step );
	}

	/**
	 * Schedule the four steps for a drop. A step that fails to schedule is logged once, and the others are still tried.
	 *
	 * @param int $drop_time When the tier dropped.
	 */
	public function schedule( int $drop_time ): void {
		$failed = array();
		foreach ( self::STEP_OFFSETS as $step => $offset ) {
			try {
				$this->action_scheduler->schedule_job( WooPaymentsSetupTier::RECHECK_HOOK, array( $drop_time, $step ), $drop_time + $offset );
			} catch ( \Throwable $e ) {
				$failed[] = $step;
			}
		}

		if ( array() !== $failed ) {
			$this->log( 'warning', 'could not schedule steps ' . implode( ', ', $failed ) . ' after the tier dropped', $drop_time );
		}
	}

	/**
	 * Cancel a drop's steps after a refused reset put the tier back, with one line.
	 *
	 * @param int $drop_time When the tier dropped.
	 */
	public function cancel( int $drop_time ): void {
		$this->remove_steps( $drop_time, 1 );
		$this->log( 'info', 'was cancelled: the refused reset put the tier back', $drop_time );
	}

	/**
	 * Run one step: read the account, remove this drop's later steps once the tier is settled, schedule the look, and log
	 * the line that ends the schedule. The look only compares what the cache holds with the database.
	 *
	 * @internal
	 *
	 * @param mixed $drop_time When the tier dropped.
	 * @param mixed $step      The step's number, from 1, or the look's.
	 */
	public function handle( $drop_time, $step ): void {
		if ( ! is_numeric( $drop_time ) || ! is_numeric( $step ) ) {
			return;
		}
		$drop_time = (int) $drop_time;
		$step      = (int) $step;

		if ( self::LOOK_STEP === $step ) {
			$this->look( $drop_time );
			return;
		}
		if ( ! isset( self::STEP_OFFSETS[ $step ] ) ) {
			return;
		}

		$outcome = $this->account_service->recheck_low_tier();
		if ( isset( self::SETTLED[ $outcome ] ) ) {
			$this->remove_steps( $drop_time, $step + 1 );
			$this->log( self::SETTLED[ $outcome ][0], self::SETTLED[ $outcome ][1], $drop_time );
			$this->schedule_look( $drop_time );
			return;
		}

		if ( self::LAST_STEP === $step && isset( self::UNSETTLED[ $outcome ] ) ) {
			$this->log( self::UNSETTLED[ $outcome ][0], self::UNSETTLED[ $outcome ][1], $drop_time );
		}
	}

	/**
	 * Schedule the look a minute after a step settled the tier. A drop has at most one pending look.
	 *
	 * @param int $drop_time When the tier dropped.
	 */
	private function schedule_look( int $drop_time ): void {
		try {
			$this->action_scheduler->schedule_job( WooPaymentsSetupTier::RECHECK_HOOK, array( $drop_time, self::LOOK_STEP ), time() + self::LOOK_DELAY );
		} catch ( \Throwable $e ) {
			$this->log( 'warning', 'could not schedule the look at the cached tier after the tier settled', $drop_time );
		}
	}

	/**
	 * Drop the cached tier and the cached account row where they differ from the database, with one line for each.
	 *
	 * @param int $drop_time When the tier dropped.
	 */
	private function look( int $drop_time ): void {
		$tiers = $this->setup_tier->confirm_cached_tier();
		if ( null !== $tiers ) {
			$this->log( 'warning', sprintf( 'look found the tier %1$s in the cached options and %2$s in the database, and dropped the cached options', $tiers[0], $tiers[1] ), $drop_time );
		}

		if ( $this->account_service->confirm_cached_account_row() ) {
			$this->log( 'warning', 'look found a cached account row that differs from the database, and dropped it', $drop_time );
		}
	}

	/**
	 * Remove a drop's pending steps from a step on. Other drops' steps stay.
	 *
	 * @param int $drop_time When the tier dropped.
	 * @param int $from_step The first step to remove.
	 */
	private function remove_steps( int $drop_time, int $from_step ): void {
		if ( ! did_action( 'action_scheduler_init' ) ) {
			return;
		}

		for ( $step = $from_step; $step <= self::LAST_STEP; $step++ ) {
			as_unschedule_action( WooPaymentsSetupTier::RECHECK_HOOK, array( $drop_time, $step ), WooPaymentsActionSchedulerService::GROUP_ID );
		}
	}

	/**
	 * Log one re-check line whatever the logging setting.
	 *
	 * @param string $level     Log level.
	 * @param string $text      What happened.
	 * @param int    $drop_time When the tier dropped.
	 */
	private function log( string $level, string $text, int $drop_time ): void {
		$this->logger->log_always( 'The WooPayments setup tier re-check ' . $text . '.', $level, array( 'drop_time' => $drop_time ) );
	}
}
