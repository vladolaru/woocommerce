<?php
/**
 * WooPaymentsCutoverActionScheduler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the canonical Action Scheduler identity for WooPayments cutover jobs.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverActionScheduler {

	/** Reconciliation action hook. */
	public const ACTION_HOOK = 'woocommerce_woopayments_cutover_reconcile';

	/** Reconciliation action group. */
	public const GROUP_ID = 'woocommerce_woopayments_cutover';

	/**
	 * Build canonical action arguments.
	 *
	 * @since 11.2.0
	 *
	 * @param int $generation Job generation.
	 * @param int $attempt    Monotonic attempt number.
	 * @return array{generation:int,attempt:int}
	 */
	public static function get_action_args( int $generation, int $attempt ): array {
		return array(
			'generation' => $generation,
			'attempt'    => $attempt,
		);
	}

	/**
	 * Schedule one unique action or return the matching scheduled action ID.
	 *
	 * @since 11.2.0
	 *
	 * @param int $timestamp  Due timestamp.
	 * @param int $generation Job generation.
	 * @param int $attempt    Monotonic attempt number.
	 * @return int Positive action ID, or zero when scheduling failed.
	 */
	public function schedule( int $timestamp, int $generation, int $attempt ): int {
		$existing_id = $this->get_scheduled_action_id( $generation, $attempt );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}

		$action_id = as_schedule_single_action(
			$timestamp,
			self::ACTION_HOOK,
			self::get_action_args( $generation, $attempt ),
			self::GROUP_ID,
			true
		);

		return is_int( $action_id ) && $action_id > 0 ? $action_id : 0;
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
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$args = self::get_action_args( $generation, $attempt );
		if ( ! as_has_scheduled_action( self::ACTION_HOOK, $args, self::GROUP_ID ) ) {
			return 0;
		}

		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION_HOOK,
				'args'     => $args,
				'group'    => self::GROUP_ID,
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
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK, self::get_action_args( $generation, $attempt ), self::GROUP_ID );
		}
	}
}
