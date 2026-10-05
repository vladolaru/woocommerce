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
	 * Latest timestamp of each job scheduled before Action Scheduler initialized, keyed by hook, args and group.
	 *
	 * @var array<string,int>
	 */
	private array $deferred_jobs = array();

	/**
	 * Schedule a single action unless the same hook/args/group is already pending.
	 *
	 * Before Action Scheduler initializes, its functions return without scheduling, so a job asked for that early is
	 * kept and scheduled on action_scheduler_init, once per hook, args and group with the latest timestamp asked for
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
	 * Keep a job asked for before Action Scheduler initialized, and schedule it when it does.
	 *
	 * @param string                  $hook      Hook name.
	 * @param array<int|string,mixed> $args      Action args.
	 * @param int                     $timestamp Scheduled timestamp.
	 * @param string                  $group     Action Scheduler group.
	 */
	private function defer_job( string $hook, array $args, int $timestamp, string $group ): void {
		$key = md5( (string) wp_json_encode( array( $hook, $args, $group ) ) );
		if ( ! isset( $this->deferred_jobs[ $key ] ) ) {
			add_action(
				'action_scheduler_init',
				function () use ( $hook, $args, $group, $key ): void {
					$timestamp = $this->deferred_jobs[ $key ];
					unset( $this->deferred_jobs[ $key ] );
					$this->schedule_job( $hook, $args, $timestamp, $group );
				}
			);
		}

		$this->deferred_jobs[ $key ] = $timestamp;
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
