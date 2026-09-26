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
	 * Process a queued failed webhook event.
	 *
	 * The stored event is removed only after the ingestor settles it: on success because it is
	 * handled, and on InvalidArgumentException because a malformed event can never succeed and must
	 * not loop on every retry. Any other failure is treated as transient, so the event is left in the
	 * store for Action Scheduler retries or the next failed-event poll to re-attempt. The exception is
	 * always re-thrown so the failure surfaces to the caller and the Action Scheduler retry machinery.
	 *
	 * @param string $event_id Event ID.
	 * @throws \InvalidArgumentException When the event is malformed and gets dropped before re-throwing; any other ingestor failure also propagates, but the event is preserved for retry.
	 */
	public function process_event( string $event_id ): void {
		$event = $this->failed_event_store->get_event( $event_id );
		if ( null === $event ) {
			return;
		}

		try {
			$this->event_ingestor->process( $event );
		} catch ( \InvalidArgumentException $exception ) {
			// Malformed/unprocessable event: drop it so it does not retry forever, then surface the failure.
			$this->failed_event_store->delete_event( $event_id );
			throw $exception;
		}

		$this->failed_event_store->delete_event( $event_id );
	}
}
