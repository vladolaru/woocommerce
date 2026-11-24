<?php
/**
 * WebhookReliabilityService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Fetches the WooPayments webhook events the platform could not deliver, and processes and retries failed events
 * through Action Scheduler, keeping each event in an option row until its job ends it. A daily sweep queues again a retried
 * event whose job was lost.
 *
 * @since 11.0.0
 * @internal
 */
class WebhookReliabilityService implements RegisterHooksInterface {

	/**
	 * Account-data flag that indicates failed webhook events remain on the server.
	 *
	 * @var string
	 */
	const CONTINUOUS_FETCH_FLAG_ACCOUNT_DATA = 'has_more_failed_events';

	/**
	 * Action Scheduler hook that fetches failed events from the platform.
	 *
	 * @var string
	 */
	const WEBHOOK_FETCH_EVENTS_ACTION = 'wcpay_webhook_fetch_events';

	/**
	 * Action Scheduler hook that processes one stored failed event.
	 *
	 * @var string
	 */
	const WEBHOOK_PROCESS_EVENT_ACTION = 'wcpay_webhook_process_event';

	/**
	 * Action Scheduler hook that sweeps queued events whose job was lost.
	 *
	 * @var string
	 */
	const QUEUED_EVENTS_SWEEP_ACTION = 'woocommerce_woopayments_sweep_queued_events';

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
	 * Key on the stored event that keeps the seconds a held-up success's second retry was brought forward, so its last
	 * retry runs when it would have without that.
	 *
	 * @var string
	 */
	private const RETRY_BROUGHT_FORWARD_EVENT_KEY = '_wcpay_retry_brought_forward';

	/**
	 * Seconds after the refusing holder's lock expires that a held-up success is retried. A retry before the expiry is
	 * refused and costs an attempt, with the next one an hour away; 10 seconds covers the takeover's strict comparison,
	 * the second between the holder's claim time and its expiry row, and clocks of other web nodes.
	 *
	 * @var int
	 */
	private const LOCK_EXPIRY_RETRY_MARGIN_SECONDS = 10;

	/**
	 * Option key for the last failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	const LAST_FETCH_OPTION_KEY = 'woocommerce_woopayments_last_webhook_fetch';

	/**
	 * Prefix of the extension's name for a queued event: read only when no row exists, and written beside the row so the
	 * extension can run queued jobs after a store returns to it. The event ID's md5 follows it.
	 *
	 * @var string
	 */
	const FAILED_EVENT_TRANSIENT_PREFIX = 'wcpay_failed_event_';

	/**
	 * Lifetime of the extension-name copy, in seconds.
	 *
	 * @var int
	 */
	private const FAILED_EVENT_TRANSIENT_TTL_SECONDS = DAY_IN_SECONDS;

	/**
	 * Prefix of the option row that keeps a queued event until its job ends it; the event ID's md5 follows it.
	 *
	 * @var string
	 */
	private const QUEUED_EVENT_OPTION_PREFIX = 'woocommerce_woopayments_queued_event_';

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Event ingestor.
	 *
	 * @var EventIngestor
	 */
	private EventIngestor $event_ingestor;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsActionSchedulerService $scheduler      Action Scheduler service.
	 * @param Client                            $api_client     WooPayments API client.
	 * @param EventIngestor                     $event_ingestor Event ingestor.
	 */
	final public function init(
		WooPaymentsActionSchedulerService $scheduler,
		Client $api_client,
		EventIngestor $event_ingestor
	): void {
		$this->scheduler      = $scheduler;
		$this->api_client     = $api_client;
		$this->event_ingestor = $event_ingestor;
	}

	/**
	 * Register the account refresh listener that starts a fetch, and the fetch and processing actions.
	 */
	public function register() {
		add_action( 'woocommerce_payments_account_refreshed', array( $this, 'maybe_schedule_fetch_events' ), 10, 1 );
		add_action( self::WEBHOOK_FETCH_EVENTS_ACTION, array( $this, 'fetch_events_and_schedule_processing_jobs' ) );
		add_action( self::WEBHOOK_PROCESS_EVENT_ACTION, array( $this, 'process_event' ), 10, 1 );
		add_action( self::QUEUED_EVENTS_SWEEP_ACTION, array( $this, 'sweep_queued_events' ) );
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
		$response = $this->fetch_failed_events_page();
		// The status report shows this as the last fetch; a failed one must not look like a healthy fetch.
		if ( null === $response ) {
			$response = array(
				'data'     => array(),
				'has_more' => false,
			);
		} else {
			update_option( self::LAST_FETCH_OPTION_KEY, time(), false );
		}

		foreach ( $response['data'] as $event ) {
			if ( empty( $event['id'] ) || ! is_string( $event['id'] ) ) {
				continue;
			}

			$this->store_failed_event( $event['id'], $event );
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
	 * it itself; client 11.1.0 loses such an event. Types that are not retried get one attempt. Every type the order
	 * payment lock can refuse is retried after a refusal, and its order is noted only when the retries run out.
	 *
	 * @param array<string,mixed> $event   Event payload.
	 * @param \Throwable|null     $failure Failure of the delivery, when known.
	 * @return bool Whether a job to process the event again was scheduled.
	 */
	public function retry_failed_event( array $event, ?\Throwable $failure = null ): bool {
		if ( empty( $event['id'] ) || ! is_string( $event['id'] ) || ! $this->is_retried_failure( $event, $failure ) ) {
			return false;
		}

		$this->store_failed_event( $event['id'], $event );
		$this->scheduler->schedule_job( self::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => $event['id'] ) );

		return true;
	}

	/**
	 * Process a queued failed webhook event.
	 *
	 * The stored event is removed once the ingestor handles it. An event the ingestor refuses as malformed can never
	 * succeed, so it is removed with one error line and the job completes. Any other failure of a retried type schedules
	 * the job again, at most three more times after 1 minute, 10 minutes and 1 hour, then the event is removed with an
	 * error line; other types get this one attempt. The failure is re-thrown so Action Scheduler records it, since
	 * Action Scheduler does not retry a failed action by itself. A retry that finds no stored copy logs an error and completes.
	 * An event of a retried type whose job is lost is queued again by sweep_queued_events(); any other is dropped there.
	 *
	 * @param string $event_id Event ID.
	 * @throws \Throwable When the ingestor fails other than by refusing the event; the event is kept for the next attempt unless none is left.
	 */
	public function process_event( string $event_id ): void {
		$event = $this->get_failed_event( $event_id );
		if ( null === $event ) {
			wc_get_logger()->error(
				sprintf( 'WooPayments webhook event %s has no stored copy: another attempt already applied it, or its copy was lost.', $event_id ),
				array(
					'source'   => WooPaymentsLogger::SOURCE,
					'event_id' => $event_id,
				)
			);
			return;
		}

		$attempts        = isset( $event[ self::RETRY_ATTEMPTS_EVENT_KEY ] ) ? (int) $event[ self::RETRY_ATTEMPTS_EVENT_KEY ] : 0;
		$brought_forward = (int) ( $event[ self::RETRY_BROUGHT_FORWARD_EVENT_KEY ] ?? 0 );
		unset( $event[ self::RETRY_ATTEMPTS_EVENT_KEY ], $event[ self::RETRY_BROUGHT_FORWARD_EVENT_KEY ] );

		try {
			$this->event_ingestor->process( $event );
		} catch ( \InvalidArgumentException $exception ) {
			$this->delete_failed_event( $event_id );
			// Client 11.1.0 `class-wc-payments-webhook-reliability-service.php:146-148`. Every refusal's reason is written by the
			// store, except a Stripe Billing refusal that wraps a platform error: that one is logged by its codes instead.
			wc_get_logger()->error(
				sprintf( 'Failed processing event %1$s.%2$s', $event_id, EventIngestor::get_refusal_reason_for_log( $exception ) ),
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);
			return;
		} catch ( \Throwable $exception ) {
			if ( $this->is_retried_failure( $event, $exception ) ) {
				$this->schedule_retry_or_give_up( $event_id, $event, $attempts, $exception, $brought_forward );
			} else {
				$this->delete_failed_event( $event_id );
			}
			throw $exception;
		}

		$this->delete_failed_event( $event_id );
	}

	/**
	 * Queue again each event last stored over a day ago with no pending or running job, counting that as a retry, so a job
	 * that keeps dying is not queued again for ever; drop with an error line one whose retries are used up, whose type the
	 * store tries only once, or whose row cannot be read. A row's age counts from its last write: each retry stores the
	 * event again, so a row goes a day after its last attempt, not its first. The sweep runs again while queued events remain.
	 *
	 * @internal
	 */
	public function sweep_queued_events(): void {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100", $wpdb->esc_like( self::QUEUED_EVENT_OPTION_PREFIX ) . '%' ) );
		$again = 100 === count( $rows ) || '' !== $wpdb->last_error;
		foreach ( $rows as $row ) {
			$value     = maybe_unserialize( $row->option_value );
			$event     = is_array( $value ) && is_array( $value['event'] ?? null ) ? $value['event'] : array();
			$event_id  = is_string( $event['id'] ?? null ) ? $event['id'] : '';
			$stored_at = is_array( $value ) ? (int) ( $value['stored_at'] ?? 0 ) : 0;
			if ( $stored_at > time() - DAY_IN_SECONDS || ( '' !== $event_id && $this->scheduler->has_scheduled_job( self::WEBHOOK_PROCESS_EVENT_ACTION, array( array( 'event_id' => $event_id ) ) ) ) ) {
				$again = true;
				continue;
			}
			if ( '' === $event_id ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $row->option_name ) );
				wp_cache_delete( $row->option_name, 'options' );
				wc_get_logger()->error( sprintf( 'WooPayments queued webhook event row %s could not be read and was dropped.', $row->option_name ), array( 'source' => WooPaymentsLogger::SOURCE ) );
				continue;
			}
			$attempts = (int) ( $event[ self::RETRY_ATTEMPTS_EVENT_KEY ] ?? 0 );
			$type     = is_string( $event['type'] ?? null ) ? $event['type'] : 'unknown type';
			if ( $attempts >= count( self::RETRY_DELAYS_SECONDS ) || ! $this->event_ingestor->is_retried_lock_refusal( $event ) ) {
				$this->delete_failed_event( $event_id );
				$this->note_dropped_event( $event_id, $event );
				wc_get_logger()->error(
					sprintf( 'WooPayments webhook event %1$s (%2$s) was dropped after %3$d retries: no job will process it again.', $event_id, $type, $attempts ),
					array(
						'source'   => WooPaymentsLogger::SOURCE,
						'event_id' => $event_id,
					)
				);
				continue;
			}
			$event[ self::RETRY_ATTEMPTS_EVENT_KEY ] = $attempts + 1;
			$value['event']                          = $event;
			$value['stored_at']                      = time();
			// Written only over the bytes read, so a row a job has ended or stored again since then is left to that job.
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $value ), $row->option_name, $row->option_value ) ) ) {
				$again = true;
				continue;
			}
			wp_cache_delete( $row->option_name, 'options' );
			set_transient( $this->get_failed_event_transient_name( $event_id ), $event, self::FAILED_EVENT_TRANSIENT_TTL_SECONDS );
			$this->scheduler->schedule_job( self::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => $event_id ) );
			wc_get_logger()->warning(
				sprintf( 'WooPayments webhook event %1$s (%2$s) had no job left to process it; it is queued again (retry %3$d of %4$d).', $event_id, $type, $attempts + 1, count( self::RETRY_DELAYS_SECONDS ) ),
				array(
					'source'   => WooPaymentsLogger::SOURCE,
					'event_id' => $event_id,
				)
			);
			$again = true;
		}
		if ( $again ) {
			$this->scheduler->schedule_job( self::QUEUED_EVENTS_SWEEP_ACTION, array(), time() + DAY_IN_SECONDS + HOUR_IN_SECONDS );
		}
	}

	/**
	 * Tell whether a failed delivery is processed again later.
	 *
	 * Retried event types are, after any passing failure, and the types safe to apply late are when the order payment
	 * lock refused them (EventIngestor::is_retried_lock_refusal()). The retries run 1, 11 and 71 minutes after
	 * the processing job's first attempt, so the last two run after a lock left by a request that died has expired
	 * (WooPaymentsPersistenceVocabulary::LOCK_TTL_SECONDS, five minutes). A payment success the same holder refuses
	 * again at the one-minute retry runs just after that holder's lock expires instead (schedule_retry_or_give_up()).
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
	 * Schedule the next attempt for a failed event, or drop it and log an error when no attempt is left.
	 *
	 * A dropped update also leaves a note when its handler can identify an order, so the merchant sees
	 * that a payment update did not reach it. The dropped event is not kept: the platform marks a listed event as fetched
	 * and the queued copy is deleted here, so the note and the error line naming the event are the trail.
	 *
	 * A payment success or authorization refused at its one-minute retry by a holder at least a minute old, so the one
	 * that refused its first attempt too, is retried just after that holder's lock expires rather than ten minutes later.
	 * A younger holder is a live one, and keeps the ten minutes. The seconds saved are added to the last retry, so the
	 * event is dropped when it would have been.
	 * A holder whose lock expired over 10 seconds ago also keeps the ten minutes: its record cannot be what refused.
	 *
	 * @param string              $event_id        Event ID.
	 * @param array<string,mixed> $event           Event payload.
	 * @param int                 $attempts        Retries already scheduled.
	 * @param \Throwable          $exception       Failure of this attempt.
	 * @param int                 $brought_forward Seconds an earlier retry of this event was brought forward.
	 */
	private function schedule_retry_or_give_up( string $event_id, array $event, int $attempts, \Throwable $exception, int $brought_forward ): void {
		if ( $attempts >= count( self::RETRY_DELAYS_SECONDS ) ) {
			$this->delete_failed_event( $event_id );
			$this->note_dropped_event( $event_id, $event, $exception instanceof OrderPaymentLockRefusedException ? $exception : null );
			wc_get_logger()->error(
				sprintf(
					'WooPayments webhook event %1$s (%2$s) could not be processed after %3$d retries and was dropped.',
					$event_id,
					isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : 'unknown type',
					$attempts
				),
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);
			return;
		}

		$due         = time() + self::RETRY_DELAYS_SECONDS[ $attempts ] + ( 2 === $attempts ? $brought_forward : 0 );
		$claimed_at  = $exception instanceof OrderPaymentLockRefusedException ? $exception->get_holder_claimed_at() : null;
		$lock_free   = (int) $claimed_at + WooPaymentsPersistenceVocabulary::LOCK_TTL_SECONDS + self::LOCK_EXPIRY_RETRY_MARGIN_SECONDS;
		$is_held_up  = 1 === $attempts && null !== $claimed_at && time() - $claimed_at >= MINUTE_IN_SECONDS && time() < $lock_free && in_array( $event['type'] ?? '', array( 'payment_intent.succeeded', 'payment_intent.amount_capturable_updated' ), true );
		$holder_free = $is_held_up ? $lock_free : $due;
		if ( $holder_free < $due ) {
			$event[ self::RETRY_BROUGHT_FORWARD_EVENT_KEY ] = $due - $holder_free;
		}

		$event[ self::RETRY_ATTEMPTS_EVENT_KEY ] = $attempts + 1;
		$this->store_failed_event( $event_id, $event );
		$this->scheduler->schedule_job(
			self::WEBHOOK_PROCESS_EVENT_ACTION,
			array( 'event_id' => $event_id ),
			min( $due, $holder_free )
		);
	}

	/**
	 * Leave the refund's final outcome on its order when the event has no further attempts.
	 *
	 * @param array<string,mixed> $event Dropped event.
	 * @return bool Whether this refund takes the place of the ordinary lock-refusal note.
	 */
	private function note_dropped_refund( array $event ): bool {
		if ( 'charge.refunded' !== ( $event['type'] ?? '' ) || ! is_array( $event['data']['object'] ?? null ) ) {
			return false;
		}
		try {
			return wc_get_container()->get( RefundEventHandler::class )->note_dropped_refund( $event['data']['object'] );
		} catch ( \Throwable $failure ) {
			wc_get_logger()->error(
				'Could not note the dropped WooPayments refund on its order.',
				array_merge(
					WooPaymentsLogger::get_failure_context( $failure ),
					array(
						'source'   => WooPaymentsLogger::SOURCE,
						'event_id' => $event['id'],
					)
				)
			);
			// An order-note hook can throw after the refund note and its identity are saved.
			return true;
		}
	}

	/**
	 * Leave one diagnostic per dropped update, whether the worker and sweep run in turn or overlap.
	 *
	 * @param string                                $event_id Event ID.
	 * @param array<string,mixed>                   $event    Dropped event.
	 * @param OrderPaymentLockRefusedException|null $refusal  Final lock refusal, when known.
	 */
	private function note_dropped_event( string $event_id, array $event, ?OrderPaymentLockRefusedException $refusal = null ): void {
		try {
			if ( $this->note_dropped_refund( $event ) ) {
				return;
			}
			$order = $refusal ? wc_get_order( $refusal->get_order_id() ) : $this->find_dropped_event_order( $event );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			// The order's payment lock may still be held. Serialize only the diagnostic for this event.
			$lock  = wc_get_container()->get( TransientRowLock::class );
			$key   = 'woopayments_dropped_event_' . md5( $event_id );
			$token = wp_generate_uuid4();
			if ( ! $lock->claim( $key, $token, MINUTE_IN_SECONDS ) ) {
				return;
			}
			try {
				$this->add_dropped_event_note( $order, $event_id, $event, null !== $refusal );
			} finally {
				$lock->release( $key, $token );
			}
		} catch ( \Throwable $exception ) {
			wc_get_logger()->error(
				$refusal ? sprintf( 'Could not note the lock-refused WooPayments webhook event %s on its order.', $event_id ) : sprintf( 'Could not note the dropped WooPayments webhook event %s on its order.', $event_id ),
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);
		}
	}

	/**
	 * Prefer an existing outcome when another delivery has already left its note.
	 *
	 * @param \WC_Order           $order    Order the handler resolved.
	 * @param string              $event_id Event ID.
	 * @param array<string,mixed> $event    Dropped event.
	 * @param bool                $locked   Whether the final worker attempt was refused the order lock.
	 */
	private function add_dropped_event_note( \WC_Order $order, string $event_id, array $event, bool $locked ): void {
		$notes         = wc_get_container()->get( OrderPaymentNotes::class );
		$vocabulary    = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		$lock_identity = 'woopayments_lock_refused_event:' . $event_id;
		$drop_identity = 'woopayments_dropped_event:' . $event_id;
		if ( $notes->find_by_identity( $order, $locked ? $drop_identity : $lock_identity, $vocabulary ) ) {
			return;
		}
		if ( 'charge.refund.updated' === ( $event['type'] ?? '' ) && is_array( $event['data']['object'] ?? null ) && wc_get_container()->get( RefundEventHandler::class )->has_refund_update_note( $order, $event['data']['object'] ) ) {
			return;
		}

		$message = $locked
			/* translators: %s: Payment platform event ID. */
			? __( 'A WooPayments update for this order (event %s) could not be applied, because another payment operation kept the order locked. Check the payment in your WooPayments dashboard.', 'woocommerce' )
			/* translators: %s: Payment platform event ID. */
			: __( 'A WooPayments update for this order (event %s) could not be applied. Check the payment in your WooPayments dashboard.', 'woocommerce' );
		wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $order, sprintf( $message, $event_id ), $locked ? $lock_identity : $drop_identity );
	}

	/**
	 * Use the handler's order lookup without applying the event.
	 *
	 * @param array<string,mixed> $event Dropped event.
	 * @return \WC_Order|null
	 */
	private function find_dropped_event_order( array $event ): ?\WC_Order {
		$type   = $event['type'] ?? '';
		$object = $event['data']['object'] ?? null;
		if ( ! is_string( $type ) || ! is_array( $object ) ) {
			return null;
		}

		if ( $this->event_ingestor->is_webhook_mode_mismatch( $event ) ) {
			return null;
		}
		$container = wc_get_container();
		$payment   = $container->get( PaymentIntentEventHandler::class );
		if ( $payment->is_supported_event( $type ) ) {
			return $payment->find_event_order( $type, $object );
		}
		if ( 'charge.refund.updated' === $type || $container->get( DisputeEventHandler::class )->is_supported_event( $type ) || $container->get( EarlyFraudWarningEventHandler::class )->is_supported_event( $type ) ) {
			return $container->get( EventOrderResolver::class )->find_order_by_charge_id( $object['charge'] ?? '' );
		}
		return $this->event_ingestor->find_existing_invoice_order( $type, $object );
	}

	/**
	 * Fetch a page of the webhook events the platform could not deliver.
	 *
	 * A platform error is logged with its status and code, never its message, and answers null; client 11.1.0 appends
	 * the platform's message (class-wc-payments-webhook-reliability-service.php:104).
	 *
	 * @return array{data:array<int,mixed>,has_more:bool}|null The page, or null when the fetch failed; entries without an ID are skipped by the caller.
	 */
	private function fetch_failed_events_page(): ?array {
		try {
			$events = $this->api_client->get_failed_webhook_events();
		} catch ( ApiException $exception ) {
			wc_get_logger()->error(
				'Can not fetch failed events from the server.',
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);

			return null;
		}

		$data = $events['data'] ?? array();

		return array(
			'data'     => is_array( $data ) ? array_values( $data ) : array(),
			'has_more' => (bool) ( $events['has_more'] ?? false ),
		);
	}

	/**
	 * Get the transient name that keeps a failed event.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	private function get_failed_event_transient_name( string $event_id ): string {
		return self::FAILED_EVENT_TRANSIENT_PREFIX . md5( $event_id );
	}

	/**
	 * Keep a failed event in its option row, with SQL so no cached answer hides it, and its extension-name copy beside it.
	 *
	 * @param string              $event_id Event ID.
	 * @param array<string,mixed> $event    Event payload.
	 * @return int|false Rows the write affected: 1 inserted, 2 updated, 0 for the same bytes stored again; false when it failed.
	 */
	private function store_failed_event( string $event_id, array $event ) {
		global $wpdb;
		$name   = self::QUEUED_EVENT_OPTION_PREFIX . md5( $event_id );
		$stored = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$name,
				maybe_serialize(
					array(
						'event'     => $event,
						'stored_at' => time(),
					)
				)
			)
		);
		wp_cache_delete( $name, 'options' );
		set_transient( $this->get_failed_event_transient_name( $event_id ), $event, self::FAILED_EVENT_TRANSIENT_TTL_SECONDS );
		if ( 1 === $stored ) {
			$this->scheduler->schedule_job( self::QUEUED_EVENTS_SWEEP_ACTION, array(), time() + DAY_IN_SECONDS + HOUR_IN_SECONDS );
		}
		if ( false === $stored ) {
			wc_get_logger()->error(
				sprintf( 'WooPayments webhook event %s could not be stored for its retry.', $event_id ),
				array(
					'source'   => WooPaymentsLogger::SOURCE,
					'event_id' => $event_id,
				)
			);
		}
		return false === $stored ? false : (int) $stored;
	}

	/**
	 * Read a kept failed event: its option row, or the extension-name copy only when no row holds it.
	 *
	 * @param string $event_id Event ID.
	 * @return array<string,mixed>|null Event payload, or null when none is kept.
	 */
	private function get_failed_event( string $event_id ): ?array {
		global $wpdb;
		$row = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::QUEUED_EVENT_OPTION_PREFIX . md5( $event_id ) ) ) );
		if ( is_array( $row ) && isset( $row['event'] ) && is_array( $row['event'] ) ) {
			return $row['event'];
		}

		$event = get_transient( $this->get_failed_event_transient_name( $event_id ) );

		return is_array( $event ) ? $event : null;
	}

	/**
	 * Forget a kept failed event: its option row and the extension-name copy.
	 *
	 * @param string $event_id Event ID.
	 */
	private function delete_failed_event( string $event_id ): void {
		global $wpdb;
		$name = self::QUEUED_EVENT_OPTION_PREFIX . md5( $event_id );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		wp_cache_delete( $name, 'options' );
		delete_transient( $this->get_failed_event_transient_name( $event_id ) );
	}
}
