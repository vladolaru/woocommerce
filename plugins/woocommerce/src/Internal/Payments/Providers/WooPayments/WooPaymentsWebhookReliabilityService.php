<?php
/**
 * WooPaymentsWebhookReliabilityService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Native owner for WooPayments-compatible webhook reliability queue consumers.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWebhookReliabilityService implements RegisterHooksInterface {

	/**
	 * Account-data flag that indicates failed webhook events remain on the server.
	 *
	 * @var string
	 */
	const CONTINUOUS_FETCH_FLAG_ACCOUNT_DATA = 'has_more_failed_events';

	/**
	 * Preserved failed-event fetch hook.
	 *
	 * @var string
	 */
	const WEBHOOK_FETCH_EVENTS_ACTION = 'wcpay_webhook_fetch_events';

	/**
	 * Preserved failed-event processing hook.
	 *
	 * @var string
	 */
	const WEBHOOK_PROCESS_EVENT_ACTION = 'wcpay_webhook_process_event';

	/**
	 * Key on the stored event that counts the retries already scheduled for it.
	 *
	 * @var string
	 */
	const RETRY_ATTEMPTS_EVENT_KEY = '_wcpay_retry_attempts';

	/**
	 * Delays, in seconds, of the retries after a failed processing job: three more attempts at most.
	 *
	 * @var int[]
	 */
	const RETRY_DELAYS_SECONDS = array( MINUTE_IN_SECONDS, 10 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS );

	/**
	 * Option key for the last failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	const LAST_FETCH_OPTION_KEY = 'woocommerce_woopayments_last_webhook_fetch';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * Failed event store.
	 *
	 * @var WooPaymentsFailedEventStore
	 */
	private WooPaymentsFailedEventStore $failed_event_store;

	/**
	 * Failed events provider.
	 *
	 * @var WooPaymentsFailedEventsProvider
	 */
	private WooPaymentsFailedEventsProvider $failed_events_provider;

	/**
	 * Event ingestor.
	 *
	 * @var WooPaymentsEventIngestor
	 */
	private WooPaymentsEventIngestor $event_ingestor;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter      $arbiter                Runtime owner arbiter.
	 * @param WooPaymentsActionSchedulerService $scheduler             Action Scheduler service.
	 * @param WooPaymentsFailedEventStore       $failed_event_store     Failed event store.
	 * @param WooPaymentsFailedEventsProvider   $failed_events_provider Failed events provider.
	 * @param WooPaymentsEventIngestor          $event_ingestor         Event ingestor.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		WooPaymentsActionSchedulerService $scheduler,
		WooPaymentsFailedEventStore $failed_event_store,
		WooPaymentsFailedEventsProvider $failed_events_provider,
		WooPaymentsEventIngestor $event_ingestor
	): void {
		$this->arbiter                = $arbiter;
		$this->scheduler              = $scheduler;
		$this->failed_event_store     = $failed_event_store;
		$this->failed_events_provider = $failed_events_provider;
		$this->event_ingestor         = $event_ingestor;
	}

	/**
	 * Register preserved reliability queue consumers.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'woocommerce_payments_account_refreshed', array( $this, 'maybe_schedule_fetch_events' ), 10, 1 );
		add_action( self::WEBHOOK_FETCH_EVENTS_ACTION, array( $this, 'fetch_events_and_schedule_processing_jobs' ) );
		add_action( self::WEBHOOK_PROCESS_EVENT_ACTION, array( $this, 'process_event' ), 10, 1 );
	}

	/**
	 * Schedule failed-event fetch when refreshed account data says more remain.
	 *
	 * @param mixed $account_data Account data.
	 */
	public function maybe_schedule_fetch_events( $account_data ): void {
		if ( ! is_array( $account_data ) || empty( $account_data[ self::CONTINUOUS_FETCH_FLAG_ACCOUNT_DATA ] ) ) {
			return;
		}

		$this->scheduler->schedule_job( self::WEBHOOK_FETCH_EVENTS_ACTION );
	}

	/**
	 * Fetch failed webhook events and schedule processing jobs.
	 */
	public function fetch_events_and_schedule_processing_jobs(): void {
		$response = $this->failed_events_provider->get_failed_webhook_events();
		// The status report shows this as the last fetch; a failed one must not look like a healthy fetch.
		if ( ! $this->failed_events_provider->did_last_fetch_fail() ) {
			update_option( self::LAST_FETCH_OPTION_KEY, time(), false );
		}

		foreach ( $response['data'] as $event ) {
			if ( empty( $event['id'] ) || ! is_string( $event['id'] ) ) {
				continue;
			}

			$this->failed_event_store->set_event( $event['id'], $event );
			$this->scheduler->schedule_job(
				self::WEBHOOK_PROCESS_EVENT_ACTION,
				array(
					'event_id' => $event['id'],
				)
			);
		}

		if ( ! empty( $response['has_more'] ) ) {
			$this->scheduler->schedule_job( self::WEBHOOK_FETCH_EVENTS_ACTION );
		}
	}

	/**
	 * Keep an event whose processing failed for a passing reason, and schedule a job to process it again, when it is retried.
	 *
	 * The platform counts the store's error reply as delivered and never sends the event again, so the store retries
	 * it itself. Client 11.1.0 loses such an event; event types that are not retried get one attempt, as on the client.
	 * A lock refusal that is not retried is noted on its order.
	 *
	 * @param array<string,mixed> $event   Event payload.
	 * @param \Throwable|null     $failure Failure of the delivery, when known.
	 */
	public function retry_failed_event( array $event, ?\Throwable $failure = null ): void {
		if ( empty( $event['id'] ) || ! is_string( $event['id'] ) ) {
			return;
		}

		if ( ! $this->is_retried_failure( $event, $failure ) ) {
			$this->note_unretried_lock_refusal( $event['id'], $failure );
			return;
		}

		$this->failed_event_store->set_event( $event['id'], $event );
		$this->scheduler->schedule_job( self::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => $event['id'] ) );
	}

	/**
	 * Process a queued failed webhook event.
	 *
	 * The stored event is removed once the ingestor handles it. An event the ingestor refuses as malformed can never
	 * succeed: as on the client, it is removed, one error line names it and the job completes. Any other failure of a
	 * retried event type schedules the same job again, at most three more times after 1 minute, 10 minutes and 1 hour;
	 * after the last attempt the event is removed and an error is logged. Other types get this one attempt, as on the
	 * client. Action Scheduler does not retry a failed action by itself. Such a failure is re-thrown, so Action Scheduler
	 * records the failed attempt.
	 *
	 * @param string $event_id Event ID.
	 * @throws \Throwable When the ingestor fails other than by refusing the event; the event is kept for the next attempt unless none is left.
	 */
	public function process_event( string $event_id ): void {
		$event = $this->failed_event_store->get_event( $event_id );
		if ( null === $event ) {
			return;
		}

		$attempts = isset( $event[ self::RETRY_ATTEMPTS_EVENT_KEY ] ) ? (int) $event[ self::RETRY_ATTEMPTS_EVENT_KEY ] : 0;
		unset( $event[ self::RETRY_ATTEMPTS_EVENT_KEY ] );

		try {
			$this->event_ingestor->process( $event );
		} catch ( \InvalidArgumentException $exception ) {
			$this->failed_event_store->delete_event( $event_id );
			// Client 11.1.0 `class-wc-payments-webhook-reliability-service.php:146-148`. Every refusal's reason is a constant
			// written here, except a Stripe Billing refusal that wraps a platform error: that one is logged by its codes instead.
			wc_get_logger()->error(
				sprintf( 'Failed processing event %1$s.%2$s', $event_id, WooPaymentsEventIngestor::get_refusal_reason_for_log( $exception ) ),
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'native-payments-webhook' ) )
			);
			return;
		} catch ( \Throwable $exception ) {
			if ( $this->is_retried_failure( $event, $exception ) ) {
				$this->schedule_retry_or_give_up( $event_id, $event, $attempts, $exception );
			} else {
				$this->note_unretried_lock_refusal( $event_id, $exception );
			}
			throw $exception;
		}

		$this->failed_event_store->delete_event( $event_id );
	}

	/**
	 * Tell whether a failed delivery is processed again later.
	 *
	 * Retried event types are, after any passing failure, and the types safe to apply late are when the order payment
	 * lock refused them (WooPaymentsEventIngestor::is_retried_lock_refusal()). The holder's lock expires after its TTL
	 * (WooPaymentsPersistenceVocabulary::LOCK_TTL_SECONDS), and the delays outlast it: the retries run 1, 11 and 71 minutes after the
	 * first refused attempt of the processing job.
	 *
	 * @param array<string,mixed> $event   Event payload.
	 * @param \Throwable|null     $failure Failure of the delivery, when known.
	 * @return bool
	 */
	private function is_retried_failure( array $event, ?\Throwable $failure ): bool {
		if ( $failure instanceof OrderPaymentLockRefusedException ) {
			return $this->event_ingestor->is_retried_lock_refusal( $event );
		}

		return $this->event_ingestor->is_retried_event( $event );
	}

	/**
	 * Note on its order a lock refusal that gets no other attempt, so the merchant sees the update did not reach it.
	 *
	 * @param string          $event_id Event ID.
	 * @param \Throwable|null $failure  Failure of the delivery, when known.
	 */
	private function note_unretried_lock_refusal( string $event_id, ?\Throwable $failure ): void {
		if ( $failure instanceof OrderPaymentLockRefusedException ) {
			$this->add_lock_refusal_note( $failure->get_order_id(), $event_id );
		}
	}

	/**
	 * Schedule the next attempt for a failed event, or drop it and log an error when no attempt is left.
	 *
	 * An event the order payment lock refused on every attempt also leaves a note on its order, so the merchant sees
	 * that a payment update did not reach it. As on the client, the dropped event is not kept: the platform marks a
	 * listed event as fetched and the failed-event store is a one-day transient nothing else reads, so the note and the
	 * error line naming the event are the trail.
	 *
	 * @param string              $event_id  Event ID.
	 * @param array<string,mixed> $event     Event payload.
	 * @param int                 $attempts  Retries already scheduled.
	 * @param \Throwable          $exception Failure of this attempt.
	 */
	private function schedule_retry_or_give_up( string $event_id, array $event, int $attempts, \Throwable $exception ): void {
		if ( $attempts >= count( self::RETRY_DELAYS_SECONDS ) ) {
			$this->failed_event_store->delete_event( $event_id );
			// The note goes first: it contains its own failures, so a throwing logger cannot cost the merchant the trail.
			if ( $exception instanceof OrderPaymentLockRefusedException ) {
				$this->add_lock_refusal_note( $exception->get_order_id(), $event_id );
			}
			wc_get_logger()->error(
				sprintf(
					'WooPayments webhook event %1$s (%2$s) could not be processed after %3$d retries and was dropped.',
					$event_id,
					isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : 'unknown type',
					$attempts
				),
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'native-payments-webhook' ) )
			);
			return;
		}

		$event[ self::RETRY_ATTEMPTS_EVENT_KEY ] = $attempts + 1;
		$this->failed_event_store->set_event( $event_id, $event );
		$this->scheduler->schedule_job(
			self::WEBHOOK_PROCESS_EVENT_ACTION,
			array( 'event_id' => $event_id ),
			time() + self::RETRY_DELAYS_SECONDS[ $attempts ]
		);
	}

	/**
	 * Note on an order that a payment platform event could not be applied because its payment lock stayed held.
	 *
	 * @param int    $order_id Order the event belongs to.
	 * @param string $event_id Dropped event ID.
	 */
	private function add_lock_refusal_note( int $order_id, string $event_id ): void {
		// The note is a diagnostic: a failure writing it must not replace the refusal the caller is handling.
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			// One note per event, however often the same refused event is delivered or run again.
			wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once(
				$order,
				sprintf(
					/* translators: %s: Payment platform event ID. */
					__( 'A WooPayments update for this order (event %s) could not be applied, because another payment operation kept the order locked. Check the payment in your WooPayments dashboard.', 'woocommerce' ),
					$event_id
				),
				'woopayments_lock_refused_event:' . $event_id
			);
		} catch ( \Throwable $exception ) {
			try {
				wc_get_logger()->error(
					sprintf( 'Could not note the lock-refused WooPayments webhook event %s on its order.', $event_id ),
					array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'native-payments-webhook' ) )
				);
			} catch ( \Throwable $logger_exception ) {
				unset( $logger_exception );
			}
		}
	}
}
