<?php
/**
 * WooPaymentsActionSchedulerService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * WooPayments-compatible Action Scheduler wrapper.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsActionSchedulerService {

	/**
	 * Canonical WooPayments Action Scheduler group.
	 *
	 * @var string
	 */
	const GROUP_ID = 'woocommerce_payments';

	/**
	 * Jobs asked for before Action Scheduler initialized, keyed by blog, hook, args and group, with the latest timestamp.
	 *
	 * @var array<string,array{hook:string,args:array<int|string,mixed>,timestamp:int,group:string,blog_id:int}>
	 */
	private array $deferred_jobs = array();

	/**
	 * Schedule a single action unless the same hook/args/group is already pending.
	 *
	 * Before Action Scheduler initializes, its functions return without scheduling, so a job asked for that early is
	 * kept and scheduled on action_scheduler_init, once per blog, hook, args and group with the latest timestamp asked for
	 * (client 11.1.0 `class-wc-payments-action-scheduler-service.php:220-249`). Once initialized, a job already pending
	 * keeps its first timestamp; the client replaces it instead. Every job reads the store's state when it runs, so
	 * the first due time loses nothing, and no pending action is unscheduled.
	 *
	 * @since 11.0.0
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Action args.
	 * @param int|null                $timestamp Scheduled timestamp. Defaults to now.
	 * @param string                  $group Action Scheduler group. Defaults to the canonical group.
	 */
	public function schedule_job( string $hook, array $args = array(), ?int $timestamp = null, string $group = self::GROUP_ID ): void {
		if ( ! did_action( 'action_scheduler_init' ) ) {
			$this->defer_job( $hook, $args, $timestamp ?? time(), $group );
			return;
		}

		if ( $this->has_pending_action( $hook, $args, $group ) ) {
			return;
		}

		as_schedule_single_action( $timestamp ?? time(), $hook, $args, $group );
	}

	/**
	 * Schedule a recurring Action Scheduler job, unless an action of its hook is already scheduled or running in the group,
	 * whatever its arguments.
	 *
	 * Called once Action Scheduler is loaded, from its `action_scheduler_ensure_recurring_actions` hook.
	 *
	 * @since 11.2.0
	 *
	 * @param string                  $hook      Hook name.
	 * @param int                     $timestamp First run timestamp.
	 * @param int                     $interval  Seconds between runs.
	 * @param array<int|string,mixed> $args      Action args.
	 * @param string                  $group     Action Scheduler group. Defaults to the canonical group.
	 */
	public function schedule_recurring_job( string $hook, int $timestamp, int $interval, array $args = array(), string $group = self::GROUP_ID ): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( $hook, null, $group ) ) {
			return;
		}

		as_schedule_recurring_action( $timestamp, $interval, $hook, $args, $group, true );
	}

	/**
	 * Keep a job asked for before Action Scheduler initialized, and schedule it when it does.
	 *
	 * @param string                  $hook      Hook name.
	 * @param array<int|string,mixed> $args      Action args.
	 * @param int                     $timestamp Scheduled timestamp.
	 * @param string                  $group     Action Scheduler group.
	 */
	private function defer_job( string $hook, array $args, int $timestamp, string $group ): void {
		// A job keeps the blog it was asked for on, so a network request that switched blogs schedules it for that store.
		$blog_id = get_current_blog_id();
		$key     = md5( (string) wp_json_encode( array( $blog_id, $hook, $args, $group ) ) );
		if ( array() === $this->deferred_jobs && false === has_action( 'action_scheduler_init', array( $this, 'handle_action_scheduler_init' ) ) ) {
			add_action( 'action_scheduler_init', array( $this, 'handle_action_scheduler_init' ) );
		}

		$this->deferred_jobs[ $key ] = array(
			'hook'      => $hook,
			'args'      => $args,
			'timestamp' => $timestamp,
			'group'     => $group,
			'blog_id'   => $blog_id,
		);
	}

	/**
	 * Schedule the jobs asked for before Action Scheduler initialized, each on its own blog, once.
	 *
	 * @internal
	 */
	public function handle_action_scheduler_init(): void {
		remove_action( 'action_scheduler_init', array( $this, 'handle_action_scheduler_init' ) );
		$jobs                = $this->deferred_jobs;
		$this->deferred_jobs = array();

		foreach ( $jobs as $job ) {
			$switched = is_multisite() && get_current_blog_id() !== $job['blog_id'] && switch_to_blog( $job['blog_id'] );
			try {
				$this->schedule_job( $job['hook'], $job['args'], $job['timestamp'], $job['group'] );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Tell whether the same hook/args/group is already pending.
	 *
	 * This intentionally excludes running actions so a currently executing
	 * failed-event fetch can schedule the next page when the provider reports
	 * more events.
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Action args.
	 * @param string                  $group Action Scheduler group.
	 * @return bool
	 */
	private function has_pending_action( string $hook, array $args, string $group ): bool {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			)
		);

		return ! empty( $actions );
	}
}
