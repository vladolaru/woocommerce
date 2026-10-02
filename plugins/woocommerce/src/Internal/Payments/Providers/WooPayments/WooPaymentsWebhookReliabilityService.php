<?php
/**
 * WooPaymentsWebhookReliabilityService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
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
	const RETRY_ATTEMPTS_EVENT_KEY = '_native_retry_attempts';

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
	const LAST_FETCH_OPTION_KEY = 'woocommerce_native_woopayments_last_webhook_fetch';

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
		update_option( self::LAST_FETCH_OPTION_KEY, time(), false );

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
	 * Keep an event whose processing failed for a passing reason, and schedule a job to process it again.
	 *
	 * The platform counts the store's error reply as delivered and never sends the event again, so the store retries
	 * it itself. Client 11.1.0 loses such an event.
	 *
	 * @param array<string,mixed> $event Event payload.
	 */
	public function retry_failed_event( array $event ): void {
		if ( empty( $event['id'] ) || ! is_string( $event['id'] ) ) {
			return;
		}

		$this->failed_event_store->set_event( $event['id'], $event );
		$this->scheduler->schedule_job( self::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => $event['id'] ) );
	}

	/**
	 * Process a queued failed webhook event.
	 *
	 * The stored event is removed once the ingestor handles it, or when it is malformed, since a malformed event can never
	 * succeed. Any other failure schedules the same job again, at most three more times after 1 minute, 10 minutes and
	 * 1 hour; after the last attempt the event is removed and an error is logged. Action Scheduler does not retry a
	 * failed action by itself. The exception is always re-thrown, so Action Scheduler records the failed attempt.
	 *
	 * @param string $event_id Event ID.
	 * @throws \InvalidArgumentException When the event is malformed; it is dropped.
	 * @throws \Throwable When the ingestor fails otherwise; the event is kept for the next attempt unless none is left.
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
			throw $exception;
		} catch ( \Throwable $exception ) {
			$this->schedule_retry_or_give_up( $event_id, $event, $attempts, $exception );
			throw $exception;
		}

		$this->failed_event_store->delete_event( $event_id );
	}

	/**
	 * Schedule the next attempt for a failed event, or drop it and log an error when no attempt is left.
	 *
	 * @param string              $event_id  Event ID.
	 * @param array<string,mixed> $event     Event payload.
	 * @param int                 $attempts  Retries already scheduled.
	 * @param \Throwable          $exception Failure of this attempt.
	 */
	private function schedule_retry_or_give_up( string $event_id, array $event, int $attempts, \Throwable $exception ): void {
		if ( $attempts >= count( self::RETRY_DELAYS_SECONDS ) ) {
			$this->failed_event_store->delete_event( $event_id );
			wc_get_logger()->error(
				sprintf(
					'WooPayments webhook event %1$s (%2$s) could not be processed after %3$d retries and was dropped: %4$s',
					$event_id,
					isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : 'unknown type',
					$attempts,
					$exception->getMessage()
				),
				array( 'source' => 'native-payments-webhook' )
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
}
