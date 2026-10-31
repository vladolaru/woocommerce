<?php
/**
 * CutoverActionScheduler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the Action Scheduler identity of the cutover job: hook, group, arguments, and scheduling, finding, cancelling and dispatching its actions.
 *
 * @since 11.2.0
 * @internal
 */
class CutoverActionScheduler {

	/** Reconciliation action hook. */
	public const ACTION_HOOK = 'woocommerce_woopayments_cutover_reconcile';

	/** Reconciliation action group. */
	public const ACTION_GROUP = 'woocommerce_woopayments_cutover';

	/**
	 * Build canonical action arguments.
	 *
	 * @since 11.2.0
	 *
	 * @param int $generation Job generation.
	 * @param int $attempt    Monotonic attempt number.
	 * @return array{generation:int,attempt:int}
	 */
	private static function get_action_args( int $generation, int $attempt ): array {
		return array(
			'generation' => $generation,
			'attempt'    => $attempt,
		);
	}

	/**
	 * Schedule one unique action; Action Scheduler refuses a duplicate of a pending or running attempt.
	 *
	 * @since 11.2.0
	 *
	 * @param int $timestamp  Due timestamp.
	 * @param int $generation Job generation.
	 * @param int $attempt    Monotonic attempt number.
	 * @return int Positive action ID, or zero when scheduling failed or the attempt is already scheduled.
	 */
	public function schedule( int $timestamp, int $generation, int $attempt ): int {
		$action_id = as_schedule_single_action(
			$timestamp,
			self::ACTION_HOOK,
			self::get_action_args( $generation, $attempt ),
			self::ACTION_GROUP,
			true
		);

		return $action_id > 0 ? $action_id : 0;
	}

	/**
	 * Dispatch the async queue runner when due work exists.
	 *
	 * @since 11.2.0
	 */
	public function dispatch_async(): void {
		$async_request = new \ActionScheduler_AsyncRequest_QueueRunner( \ActionScheduler_Store::instance() );
		$async_request->maybe_dispatch();
	}

	/**
	 * Get the pending or running action ID for one exact attempt.
	 *
	 * @since 11.2.0
	 *
	 * @param int $generation Job generation.
	 * @param int $attempt    Monotonic attempt number.
	 * @return int Positive action ID, or zero when absent.
	 */
	public function get_scheduled_action_id( int $generation, int $attempt ): int {
		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION_HOOK,
				'args'     => self::get_action_args( $generation, $attempt ),
				'group'    => self::ACTION_GROUP,
				'status'   => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			),
			'ids'
		);

		return empty( $action_ids ) ? 0 : (int) reset( $action_ids );
	}

	/**
	 * Cancel the pending action for one exact attempt.
	 *
	 * @since 11.2.0
	 *
	 * @param int $generation Job generation.
	 * @param int $attempt    Monotonic attempt number.
	 */
	public function cancel( int $generation, int $attempt ): void {
		as_unschedule_all_actions( self::ACTION_HOOK, self::get_action_args( $generation, $attempt ), self::ACTION_GROUP );
	}
}
