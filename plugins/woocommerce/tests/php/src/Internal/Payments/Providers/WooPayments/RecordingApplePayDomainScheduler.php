<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;

/**
 * Recording scheduler for Apple Pay domain registration tests.
 */
class RecordingApplePayDomainScheduler extends WooPaymentsActionSchedulerService {

	/**
	 * Scheduled jobs.
	 *
	 * @var array<int,array{hook:string,args:array<int|string,mixed>,timestamp:int|null}>
	 */
	public array $scheduled_jobs = array();

	/**
	 * Schedule a single action.
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Action args.
	 * @param int|null                $timestamp Scheduled timestamp.
	 */
	public function schedule_job( string $hook, array $args = array(), ?int $timestamp = null ): void {
		$this->scheduled_jobs[] = array(
			'hook'      => $hook,
			'args'      => $args,
			'timestamp' => $timestamp,
		);
	}
}
