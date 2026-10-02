<?php
/**
 * WooPaymentsFeeDetailsNoteScheduler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the "Fee details" note job when a payment's success or capture note is first written.
 *
 * Client 11.1.0 schedules `wcpay_add_fee_breakdown_to_order_notes` from `mark_payment_completed()` and
 * `mark_payment_capture_completed()`, which return early once their note exists, so the job runs once per
 * completed payment or capture and the note never depends on a webhook.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsFeeDetailsNoteScheduler {

	/**
	 * Action scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $action_scheduler;

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsActionSchedulerService $action_scheduler Action scheduler service.
	 * @param WooPaymentsAccountService         $account_service  Account service.
	 */
	final public function init( WooPaymentsActionSchedulerService $action_scheduler, WooPaymentsAccountService $account_service ): void {
		$this->action_scheduler = $action_scheduler;
		$this->account_service  = $account_service;
	}

	/**
	 * Schedule the note job after a lifecycle note was added, when that note is a payment's success or capture note.
	 *
	 * @param WC_Order $order    Order the note was added to.
	 * @param string   $identity Identity of the added note, as the payment lifecycle builds it.
	 */
	public function schedule_after_lifecycle_note( WC_Order $order, string $identity ): void {
		$pattern = sprintf(
			'/^payment_lifecycle:(.+)\|%1$s\|(?:%2$s|%3$s)$/',
			preg_quote( PaymentLifecycleEvent::STATUS_COMPLETED, '/' ),
			preg_quote( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS, '/' ),
			preg_quote( PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS, '/' )
		);
		if ( 1 !== preg_match( $pattern, $identity, $matches ) ) {
			return;
		}

		$this->action_scheduler->schedule_job(
			WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
			array(
				'order_id'     => $order->get_id(),
				'intent_id'    => $matches[1],
				'is_test_mode' => $this->account_service->is_test_mode_enabled(),
			)
		);
	}
}
