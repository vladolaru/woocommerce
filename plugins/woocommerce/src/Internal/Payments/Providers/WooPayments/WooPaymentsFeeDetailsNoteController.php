<?php
/**
 * WooPaymentsFeeDetailsNoteController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the "Fee details" note job when a payment's success or capture note is first added, and runs it.
 *
 * Client 11.1.0 schedules `wcpay_add_fee_breakdown_to_order_notes` from `mark_payment_completed()` and
 * `mark_payment_capture_completed()`, which return early once their note exists, so the job runs once per completed
 * payment or capture, never when the same payment is reported again, and the note never depends on a webhook
 * (class-wc-payments-order-service.php:1565-1599, :1659-1668). The job adds its note every time it runs.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsFeeDetailsNoteController implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook of the job that adds the "Fee details" note, as the client names it.
	 *
	 * @var string
	 */
	public const ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION = 'wcpay_add_fee_breakdown_to_order_notes';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Order payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Order payment notes.
	 *
	 * @var OrderPaymentNotes
	 */
	private OrderPaymentNotes $order_payment_notes;

	/**
	 * WooPayments persistence vocabulary.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private WooPaymentsPersistenceVocabulary $vocabulary;

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
	 * @param OrderPaymentLifecycleService      $lifecycle_service   Order payment lifecycle service.
	 * @param OrderPaymentNotes                 $order_payment_notes Order payment notes.
	 * @param WooPaymentsPersistenceVocabulary  $vocabulary          WooPayments persistence vocabulary.
	 * @param WooPaymentsActionSchedulerService $action_scheduler    Action scheduler service.
	 * @param WooPaymentsAccountService         $account_service     Account service.
	 * @param WooPaymentsRuntimeArbiter         $arbiter             Runtime owner arbiter.
	 * @param WooPaymentsApiClient              $api_client          WooPayments API client.
	 * @param WooPaymentsOrderDataService       $order_data_service  WooPayments order data service.
	 * @param WooPaymentsLogger                 $logger              WooPayments logger.
	 */
	final public function init( OrderPaymentLifecycleService $lifecycle_service, OrderPaymentNotes $order_payment_notes, WooPaymentsPersistenceVocabulary $vocabulary, WooPaymentsActionSchedulerService $action_scheduler, WooPaymentsAccountService $account_service, WooPaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsOrderDataService $order_data_service, WooPaymentsLogger $logger ): void {
		$this->arbiter             = $arbiter;
		$this->api_client          = $api_client;
		$this->order_data_service  = $order_data_service;
		$this->logger              = $logger;
		$this->lifecycle_service   = $lifecycle_service;
		$this->order_payment_notes = $order_payment_notes;
		$this->vocabulary          = $vocabulary;
		$this->action_scheduler    = $action_scheduler;
		$this->account_service     = $account_service;
	}

	/**
	 * Register the job's handler while the built-in WooPayments owns the store.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_action( self::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION, array( $this, 'handle_wcpay_add_fee_breakdown_to_order_notes' ), 10, 3 );
	}

	/**
	 * Add fee-breakdown details to an order note from the intent timeline.
	 *
	 * The job runs in the test mode it was scheduled in. Like the client's, it adds its note every time it runs, and logs a
	 * timeline it cannot use (client 11.1.0 class-wc-payments-order-service.php:845-861).
	 *
	 * @internal
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $intent_id    PaymentIntent ID.
	 * @param bool   $is_test_mode Whether this queued job should run in test mode.
	 */
	public function handle_wcpay_add_fee_breakdown_to_order_notes( $order_id, $intent_id, $is_test_mode = false ): void {
		$this->account_service->run_in_test_mode_context(
			(bool) $is_test_mode,
			function () use ( $order_id, $intent_id ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order || ! is_string( $intent_id ) || '' === $intent_id ) {
					return;
				}

				try {
					$events = $this->api_client->get_timeline( $intent_id );
				} catch ( Throwable $exception ) {
					wc_get_logger()->error(
						'Failed to read native WooPayments intent timeline.',
						array_merge(
							array(
								'action'    => self::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
								'order_id'  => $order->get_id(),
								'intent_id' => $intent_id,
							),
							WooPaymentsLogger::get_failure_context( $exception ),
							array( 'source' => WooPaymentsLogger::SOURCE )
						)
					);
					return;
				}

				if ( ! isset( $events['data'] ) || ! is_array( $events['data'] ) ) {
					$this->logger->log( sprintf( 'Timeline data missing or malformed for intent_id %s.', $intent_id ) );
					return;
				}

				foreach ( $events['data'] as $event ) {
					if ( is_array( $event ) && 'captured' === ( $event['type'] ?? null ) ) {
						// The client adds the note every time the job runs; the job is scheduled once per completed payment or capture.
						$note = $this->order_data_service->get_fee_breakdown_note_from_timeline_event( $event );
						if ( '' !== $note ) {
							$order->add_order_note( $note );
							$order->save();
						}
						return;
					}
				}

				$this->logger->log( sprintf( 'No captured event found in timeline for intent_id %s.', $intent_id ) );
			}
		);
	}

	/**
	 * Apply a payment lifecycle event under the order payment lock the caller holds, and schedule the job when the event
	 * first adds a payment's success or capture note.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	public function apply_and_schedule_fee_details( WC_Order $order, PaymentLifecycleEvent $event ): void {
		$this->schedule_when_event_added_note( $order, $event, $this->lifecycle_service->apply_under_lock( $order, $event, $this->vocabulary ) );
	}

	/**
	 * Apply a payment lifecycle event through the payment lifecycle, which claims the order payment lock for it, and
	 * schedule the job as apply_and_schedule_fee_details() does.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool False when the order payment lock refused the event, true otherwise.
	 */
	public function apply_and_schedule_fee_details_with_lock( WC_Order $order, PaymentLifecycleEvent $event ): bool {
		$result = $this->lifecycle_service->apply( $order, $event, $this->vocabulary );
		$this->schedule_when_event_added_note( $order, $event, $result );

		return OrderPaymentLifecycleService::RESULT_REFUSED !== $result;
	}

	/**
	 * Tell whether the order has the success or capture note a payment runtime outcome brings, before the runtime applies
	 * it.
	 *
	 * @param WC_Order       $order   Order object.
	 * @param PaymentOutcome $outcome Provider outcome, with its order note.
	 * @return bool|null Null when the outcome brings no success or capture note.
	 */
	public function has_outcome_note( WC_Order $order, PaymentOutcome $outcome ): ?bool {
		$event = $this->get_outcome_event( $outcome );

		return null === $event ? null : $this->has_note( $order, $event );
	}

	/**
	 * Schedule the job after the payment runtime applied an outcome whose success or capture note the order did not have.
	 *
	 * The note was absent, by identity and by text, before the runtime applied the outcome under its lock, so a note found
	 * now either way was written by the lifecycle, even when its identity meta was not stored.
	 *
	 * @param WC_Order       $order   Order object.
	 * @param PaymentOutcome $outcome Applied provider outcome, with its order note.
	 */
	public function schedule_when_outcome_note_added( WC_Order $order, PaymentOutcome $outcome ): void {
		$event = $this->get_outcome_event( $outcome );
		if ( null !== $event && $this->has_note( $order, $event ) ) {
			$this->schedule( $order, (string) $event->get_payment_reference() );
		}
	}

	/**
	 * Schedule the note job for an order's payment intent.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Payment intent the job reads.
	 */
	public function schedule( WC_Order $order, string $intent_id ): void {
		$this->action_scheduler->schedule_job(
			self::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
			array(
				'order_id'     => $order->get_id(),
				'intent_id'    => $intent_id,
				'is_test_mode' => $this->account_service->is_test_mode_enabled(),
			)
		);
	}

	/**
	 * Schedule the job when the payment lifecycle first added an event's success or capture note.
	 *
	 * @param WC_Order              $order  Order object.
	 * @param PaymentLifecycleEvent $event  Applied lifecycle event.
	 * @param string                $result The payment lifecycle's result for the event.
	 */
	private function schedule_when_event_added_note( WC_Order $order, PaymentLifecycleEvent $event, string $result ): void {
		if ( OrderPaymentLifecycleService::RESULT_NOTE_ADDED === $result && $this->is_success_or_capture_event( $event ) ) {
			$this->schedule( $order, (string) $event->get_payment_reference() );
		}
	}

	/**
	 * Tell whether the order has an event's note, found the way the payment lifecycle finds it: by its identity, or else
	 * by its text.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event with a note.
	 * @return bool
	 */
	private function has_note( WC_Order $order, PaymentLifecycleEvent $event ): bool {
		return 0 < $this->order_payment_notes->find_by_identity( $order, $event->get_note_identity(), $this->vocabulary )
			|| 0 < $this->order_payment_notes->find_by_content( $order, (string) $event->get_note(), $event->get_note_equivalents() );
	}

	/**
	 * Tell whether an event completes a payment or a capture with its success or capture note and a payment reference.
	 *
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool
	 */
	private function is_success_or_capture_event( PaymentLifecycleEvent $event ): bool {
		return PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status()
			&& in_array( $event->get_note_type(), array( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS, PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS ), true )
			&& '' !== (string) $event->get_payment_reference()
			&& '' !== (string) $event->get_note();
	}

	/**
	 * Get the lifecycle event's note facts of an outcome the payment runtime applies, when it is a success or capture.
	 *
	 * The runtime applies a completed outcome, or a payment with no external charge, as a completed event with the
	 * outcome's payment ID, note, note type and other texts of the note.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return PaymentLifecycleEvent|null Null when the outcome brings no success or capture note.
	 */
	private function get_outcome_event( PaymentOutcome $outcome ): ?PaymentLifecycleEvent {
		if ( ! in_array( $outcome->get_status(), array( PaymentOutcome::STATUS_COMPLETED, PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT ), true ) ) {
			return null;
		}

		$data      = $outcome->get_data();
		$note      = isset( $data[ PaymentOutcome::DATA_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE ] ) ? $data[ PaymentOutcome::DATA_NOTE ] : '';
		$note_type = isset( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) ? $data[ PaymentOutcome::DATA_NOTE_TYPE ] : '';
		$event     = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			'' === $outcome->get_provider_payment_id() ? null : $outcome->get_provider_payment_id(),
			array(),
			array(),
			'' === $note ? null : $note,
			$note_type,
			isset( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) && is_array( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) ? $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] : array()
		);

		return $this->is_success_or_capture_event( $event ) ? $event : null;
	}
}
