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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the "Fee details" note job when a payment's success or capture note is first added, and runs it.
 *
 * Client 11.1.0 schedules `wcpay_add_fee_breakdown_to_order_notes` from `mark_payment_completed()` and
 * `mark_payment_capture_completed()`, which return early once their note exists, and after the capture an order status
 * change runs (class-wc-payments-order-service.php:1565-1599, :1659-1668, :1873-1874). So the job runs once per
 * completed payment or capture, never when the same payment is reported again, and the note never depends on a webhook.
 * This code schedules on the status-change capture too (WooPaymentsOrderAdminActionsController::run_operation()); a
 * job with the same arguments already pending is not scheduled again. The job adds its note every time it runs.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsFeeDetailsNoteController implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook of the job that adds the "Fee details" note, client 11.1.0's name for it
	 * (class-wc-payments-order-service.php:29).
	 *
	 * @var string
	 */
	public const ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION = 'wcpay_add_fee_breakdown_to_order_notes';

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

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
	 * @param Client                            $api_client          WooPayments API client.
	 * @param WooPaymentsOrderDataService       $order_data_service  WooPayments order data service.
	 * @param WooPaymentsLogger                 $logger              WooPayments logger.
	 */
	final public function init( OrderPaymentLifecycleService $lifecycle_service, OrderPaymentNotes $order_payment_notes, WooPaymentsPersistenceVocabulary $vocabulary, WooPaymentsActionSchedulerService $action_scheduler, WooPaymentsAccountService $account_service, Client $api_client, WooPaymentsOrderDataService $order_data_service, WooPaymentsLogger $logger ): void {
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
	 * Register the job's handler.
	 */
	public function register() {
		add_action( self::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION, array( $this, 'handle_wcpay_add_fee_breakdown_to_order_notes' ), 10, 3 );
	}

	/**
	 * Add fee-breakdown details to an order note from the intent timeline.
	 *
	 * The job runs in the test mode it was scheduled in. Like client 11.1.0's, it adds its note every time it runs, and logs a
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
					$context = array(
						'action'    => self::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
						'order_id'  => $order->get_id(),
						'intent_id' => $intent_id,
					);
					if ( 0 === strpos( $intent_id, 'seti_' ) ) {
						// The platform has no timeline for a SetupIntent; client 11.1.0 logs the refusal at info level, only with
						// logging on (class-wc-payments-order-service.php:879-880).
						$this->logger->log_throwable( 'Failed to read WooPayments intent timeline.', $exception, $context, 'info' );
						return;
					}

					wc_get_logger()->error(
						'Failed to read WooPayments intent timeline.',
						array_merge(
							$context,
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
						// Client 11.1.0 adds the note every time the job runs (class-wc-payments-order-service.php:864-877); the job
						// is scheduled once per completed payment or capture.
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
	 * first adds a payment's success or capture note. A payment's success and capture notes each count as the other
	 * already applied.
	 *
	 * For a capture, or a payment the caller records whatever the order's status, as client 11.3.0's capture writer does
	 * (class-wc-payments-order-service.php:529-532).
	 *
	 * @param WC_Order                                   $order          Order object.
	 * @param PaymentLifecycleEvent                      $event          Lifecycle event.
	 * @param array{notes:string[],log_line:string}|null $hold           The amount-mismatch hold the event applies, logged once its note is added.
	 * @param array<string,mixed>|null                   $payment_intent The payment intent the event was built from, or null when the
	 *                                                                   caller passes none; see with_completion_rules().
	 */
	public function apply_and_schedule_fee_details( WC_Order $order, PaymentLifecycleEvent $event, ?array $hold = null, ?array $payment_intent = null ): void {
		$this->apply_under_lock_and_schedule( $order, $event, $hold, false, $payment_intent );
	}

	/**
	 * Apply a payment lifecycle event built from a payment intent's status, as apply_and_schedule_fee_details() does, except
	 * that its completion or authorization leaves an order already in a paid status as it is.
	 *
	 * Client 11.3.0 updates an order from an intent's status only while the order is not paid
	 * (class-wc-payments-order-service.php:475, :3287-3289, :3398-3410).
	 *
	 * @param WC_Order                                   $order          Order object.
	 * @param PaymentLifecycleEvent                      $event          Lifecycle event.
	 * @param array{notes:string[],log_line:string}|null $hold           The amount-mismatch hold the event applies, logged once its note is added.
	 * @param array<string,mixed>|null                   $payment_intent The payment intent the event was built from, or null when the
	 *                                                                   caller passes none; see with_completion_rules().
	 */
	public function apply_intent_status_and_schedule_fee_details( WC_Order $order, PaymentLifecycleEvent $event, ?array $hold = null, ?array $payment_intent = null ): void {
		$this->apply_under_lock_and_schedule( $order, $event, $hold, true, $payment_intent );
	}

	/**
	 * Apply a payment lifecycle event under the order payment lock the caller holds, and schedule the job.
	 *
	 * @param WC_Order                                   $order            Order object.
	 * @param PaymentLifecycleEvent                      $event            Lifecycle event.
	 * @param array{notes:string[],log_line:string}|null $hold             The amount-mismatch hold the event applies.
	 * @param bool                                       $leave_paid_order Whether a completion or an authorization leaves an order already in a paid status as it is.
	 * @param array<string,mixed>|null                   $payment_intent   The payment intent the event was built from, or null.
	 */
	private function apply_under_lock_and_schedule( WC_Order $order, PaymentLifecycleEvent $event, ?array $hold, bool $leave_paid_order, ?array $payment_intent ): void {
		// A subscription gets no payment event: no lifecycle, no note, no Fee details job.
		if ( SubscriptionMethodPolicy::only_saves_the_card( $order ) ) {
			return;
		}

		$event = $this->with_completion_rules( $order, $event, $leave_paid_order, $payment_intent );
		$this->schedule_when_event_added_note( $order, $event, $this->lifecycle_service->apply_under_lock( $order, $event, $this->vocabulary ), $hold );
	}

	/**
	 * Apply a payment lifecycle event through the payment lifecycle, which claims the order payment lock for it, and
	 * schedule the job as apply_and_schedule_fee_details() does.
	 *
	 * A caller whose event depends on the order passes a builder: under the claim, the payment lifecycle reads the caller's
	 * order again in place and the builder builds the event, with its amount-mismatch hold, on that object, or returns no
	 * event when there is nothing to apply. The event passed gives the payment reference, and is the one refused when
	 * another operation holds the lock.
	 *
	 * Its callers, the order-status callback, the redirect return and the attached-intent check, update the order from an
	 * intent's status, so a completion or an authorization leaves an order already in a paid status as it is, as
	 * apply_intent_status_and_schedule_fee_details() does.
	 *
	 * @param WC_Order                 $order            Order object.
	 * @param PaymentLifecycleEvent    $event            Lifecycle event.
	 * @param callable|null            $build_under_lock Gets the order read under the claim and returns the event to apply, or null
	 *                                                   when there is nothing to apply, and its amount-mismatch hold or null, as
	 *                                                   array{0:PaymentLifecycleEvent|null,1:array|null}.
	 * @param array<string,mixed>|null $payment_intent The payment intent the event is built from, or null; see with_completion_rules().
	 * @return string The payment lifecycle's result for the event, OrderPaymentLifecycleService::RESULT_REFUSED when the
	 *                order payment lock refused it.
	 */
	public function apply_and_schedule_fee_details_with_lock( WC_Order $order, PaymentLifecycleEvent $event, ?callable $build_under_lock = null, ?array $payment_intent = null ): string {
		// A subscription gets no payment event: no lock claim, no lifecycle, no note, no Fee details job.
		if ( SubscriptionMethodPolicy::only_saves_the_card( $order ) ) {
			return OrderPaymentLifecycleService::RESULT_NOTE_NOT_ADDED;
		}

		$hold = null;
		if ( null === $build_under_lock ) {
			$event  = $this->with_completion_rules( $order, $event, true, $payment_intent );
			$result = $this->lifecycle_service->apply( $order, $event, $this->vocabulary );
		} else {
			$result = $this->lifecycle_service->apply_rebuilt_under_claim(
				$order,
				$event,
				$this->vocabulary,
				function ( WC_Order $stored_order ) use ( $build_under_lock, $payment_intent, &$event, &$hold ): ?PaymentLifecycleEvent {
					list( $built_event, $hold ) = $build_under_lock( $stored_order );
					if ( null === $built_event ) {
						return null;
					}
					$event = $this->with_completion_rules( $stored_order, $built_event, true, $payment_intent );

					return $event;
				}
			);
		}
		$this->schedule_when_event_added_note( $order, $event, $result, $hold );

		return $result;
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
			$this->schedule_first_invoice_job_now( $order );
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
	 * Schedule the job when the payment lifecycle first added an event's success or capture note, and log an
	 * amount-mismatch hold when it first added the hold's note.
	 *
	 * @param WC_Order                                   $order  Order object.
	 * @param PaymentLifecycleEvent                      $event  Applied lifecycle event.
	 * @param string                                     $result The payment lifecycle's result for the event.
	 * @param array{notes:string[],log_line:string}|null $hold   The amount-mismatch hold the event applies.
	 */
	private function schedule_when_event_added_note( WC_Order $order, PaymentLifecycleEvent $event, string $result, ?array $hold ): void {
		if ( OrderPaymentLifecycleService::RESULT_NOTE_ADDED === $result && $this->is_success_or_capture_event( $event ) ) {
			$this->schedule( $order, (string) $event->get_payment_reference() );
			$this->schedule_first_invoice_job_now( $order );
		}

		if ( null !== $hold && OrderPaymentLifecycleService::RESULT_NOTE_ADDED === $result && $event->should_hold_order() ) {
			wc_get_container()->get( WooPaymentsAmountMismatchHold::class )->log_hold( $hold );
		}
	}

	/**
	 * Keep Stripe Billing scheduling failures out of the completed payment.
	 *
	 * @param WC_Order $order Paying order.
	 */
	private function schedule_first_invoice_job_now( WC_Order $order ): void {
		try {
			wc_get_container()->get( StripeBillingModule::class )->schedule_first_invoice_job_now( $order );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( 'Could not schedule the first invoice payment.', $exception );
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
	 * Give a payment's success event the capture note of the same intent as an applied alternative, and its capture event
	 * the success note, so the payment lifecycle completes the payment once, whichever of the two wrote first; mark a
	 * completion or an authorization that leaves an order already in a paid status as it is; and give a completed event of
	 * an online payment the note the lifecycle adds when it skips the event on an order already in a paid status.
	 *
	 * Client 11.3.0's success writer returns once the capture note is on the order (class-wc-payments-order-service.php:2040-2046),
	 * and leaves a paid order with no note (:3292-3294).
	 *
	 * @param WC_Order                 $order            Order the event applies to, read under the order payment lock when the caller holds it.
	 * @param PaymentLifecycleEvent    $event            Lifecycle event.
	 * @param bool                     $leave_paid_order Whether a completion or an authorization leaves an order already in a paid status as it is.
	 * @param array<string,mixed>|null $payment_intent   The payment intent the event was built from, or null.
	 * @return PaymentLifecycleEvent The event, with the other note as an applied alternative when it is a success or capture.
	 */
	private function with_completion_rules( WC_Order $order, PaymentLifecycleEvent $event, bool $leave_paid_order, ?array $payment_intent ): PaymentLifecycleEvent {
		if ( ! in_array( $event->get_status(), array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::STATUS_AUTHORIZED ), true ) ) {
			return $event;
		}

		$alternatives = $event->get_applied_alternatives();
		if ( $this->is_success_or_capture_event( $event ) ) {
			$alternatives[] = $this->get_other_completion_note_event( $order, $event );
		}

		return new PaymentLifecycleEvent(
			$event->get_status(),
			$event->get_payment_reference(),
			$event->get_meta_to_update(),
			$event->get_meta_to_delete(),
			$event->get_note(),
			$event->get_note_type(),
			$event->get_note_equivalents(),
			$event->should_preserve_order_status(),
			$event->should_hold_order(),
			$alternatives,
			$leave_paid_order,
			$event->adds_note_per_occurrence(),
			$this->get_paid_order_note( $order, $event, $payment_intent ) ?? $event->get_paid_order_note()
		);
	}

	/**
	 * Build the note the payment lifecycle adds when it skips a completed event on an order already in a paid status.
	 *
	 * Only for a success, capture or held event of a payment intent, built from the intent passed, whose first charge, the
	 * one the webhook stores on the order, was not taken in person: a merchant who takes an in-person payment on an order
	 * already marked paid takes it knowingly. The amount is the intent's, as the amount-mismatch hold reads it. The own-note
	 * events are this payment's success, capture, held and authorized notes, any of which means the order already records it.
	 *
	 * @param WC_Order                 $order          Order the event applies to.
	 * @param PaymentLifecycleEvent    $event          Completed or authorized lifecycle event.
	 * @param array<string,mixed>|null $payment_intent The payment intent the event was built from, or null.
	 * @return array{note:string,identity:string,own_note_events:PaymentLifecycleEvent[]}|null Null when the event gets none.
	 */
	private function get_paid_order_note( WC_Order $order, PaymentLifecycleEvent $event, ?array $payment_intent ): ?array {
		$intent_id = (string) $event->get_payment_reference();
		if (
			null === $payment_intent
			|| PaymentLifecycleEvent::STATUS_COMPLETED !== $event->get_status()
			|| ! ( $this->is_success_or_capture_event( $event ) || $event->should_hold_order() )
			|| 0 !== strpos( $intent_id, 'pi_' )
		) {
			return null;
		}

		$charge_id = WooPaymentsIntentCodec::first_charge_id( $payment_intent );
		if ( '' === $charge_id || WooPaymentsIntentCodec::is_in_person_charge( WooPaymentsIntentCodec::first_charge( $payment_intent ) ) ) {
			return null;
		}

		$amount       = is_numeric( $payment_intent['amount'] ?? null ) ? (int) $payment_intent['amount'] : 0;
		$currency     = isset( $payment_intent['currency'] ) && is_string( $payment_intent['currency'] ) ? $payment_intent['currency'] : '';
		$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$own_notes    = array(
			array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS, $note_service->format_payment_success_note_candidates( $order, $intent_id, $charge_id ) ),
			array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS, $note_service->format_capture_success_note_candidates( $order, $intent_id, $charge_id ) ),
			array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_HELD, $note_service->format_amount_mismatch_hold_note_candidates( $order, $intent_id, $charge_id, $amount, $currency ) ),
			array( PaymentLifecycleEvent::STATUS_AUTHORIZED, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED, $note_service->format_payment_authorized_note_candidates( $order, $intent_id, $charge_id ) ),
		);

		return array(
			'note'            => $note_service->format_paid_order_payment_note( $order, $intent_id, $charge_id, $amount, $currency ),
			'identity'        => 'paid_order_payment|' . $intent_id,
			'own_note_events' => array_map(
				static fn( array $own_note ): PaymentLifecycleEvent => new PaymentLifecycleEvent( $own_note[0], $intent_id, array(), array(), $own_note[2][0], $own_note[1], $own_note[2] ),
				$own_notes
			),
		);
	}

	/**
	 * Build the event whose note completes the same payment by its other writer: the capture note for a success event, the
	 * success note for a capture event.
	 *
	 * @param WC_Order              $order Order the event applies to.
	 * @param PaymentLifecycleEvent $event Success or capture event.
	 * @return PaymentLifecycleEvent
	 */
	private function get_other_completion_note_event( WC_Order $order, PaymentLifecycleEvent $event ): PaymentLifecycleEvent {
		$intent_id    = (string) $event->get_payment_reference();
		$charge_id    = $event->get_meta_to_update()[ WooPaymentsPersistenceVocabulary::CHARGE_ID_META_KEY ] ?? (string) $order->get_meta( WooPaymentsPersistenceVocabulary::CHARGE_ID_META_KEY, true );
		$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$is_success   = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS === $event->get_note_type();
		$candidates   = $is_success
			? $note_service->format_capture_success_note_candidates( $order, $intent_id, $charge_id )
			: $note_service->format_payment_success_note_candidates( $order, $intent_id, $charge_id );

		return new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			$intent_id,
			array(),
			array(),
			$candidates[0],
			$is_success ? PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS : PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS,
			$candidates
		);
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
