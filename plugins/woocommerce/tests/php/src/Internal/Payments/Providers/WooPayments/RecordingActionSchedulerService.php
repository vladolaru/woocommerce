<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;

/**
 * Recording scheduler test double.
 */
class RecordingActionSchedulerService extends WooPaymentsActionSchedulerService {

	/**
	 * Scheduled jobs.
	 *
	 * @var array<int,array{hook:string,args:array<int|string,mixed>,timestamp?:int}>
	 */
	public array $scheduled_jobs = array();

	/**
	 * Record a scheduled job.
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Action args.
	 * @param int|null                $timestamp Optional scheduled timestamp.
	 * @param string                  $group Action Scheduler group (not recorded).
	 */
	public function schedule_job( string $hook, array $args = array(), ?int $timestamp = null, string $group = self::GROUP_ID ): void {
		$job = array(
			'hook' => $hook,
			'args' => $args,
		);

		if ( null !== $timestamp ) {
			$job['timestamp'] = $timestamp;
		}

		$this->scheduled_jobs[] = $job;
	}

	/**
	 * Get the recorded jobs of one hook, in the order they were scheduled.
	 *
	 * @param string $hook Hook name.
	 * @return array<int,array{hook:string,args:array<int|string,mixed>,timestamp?:int}>
	 */
	public function jobs_for( string $hook ): array {
		return array_values( array_filter( $this->scheduled_jobs, static fn( array $job ): bool => $hook === $job['hook'] ) );
	}
}
