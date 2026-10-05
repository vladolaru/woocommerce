<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedEventStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedEventsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWebhookReliabilityService class.
 */
class WooPaymentsWebhookReliabilityServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Expected option key for the native failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	private const EXPECTED_LAST_FETCH_OPTION = 'woocommerce_native_woopayments_last_webhook_fetch';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsWebhookReliabilityService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsWebhookReliabilityService::class );
		$this->remove_reliability_hooks();
		$this->unschedule_reliability_actions();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->remove_reliability_hooks();
		$this->unschedule_reliability_actions();
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->reset_all_replacements();
		foreach ( array( 'evt_1', 'evt_process', 'evt_backlog_1', 'evt_backlog_2', 'evt_backlog_3', 'evt_backlog_4', 'evt_backlog_5' ) as $event_id ) {
			delete_transient( WooPaymentsFailedEventStore::TRANSIENT_PREFIX . md5( $event_id ) );
		}
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		parent::tearDown();
	}

	/**
	 * @testdox Reliability hooks are not registered when the plugin owns runtime.
	 */
	public function test_registers_no_actions_when_plugin_owns_runtime(): void {
		$this->fake_plugin( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );

		$this->sut->register();

		$this->assertFalse( has_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_schedule_fetch_events' ) ) );
		$this->assertFalse( has_action( 'wcpay_webhook_fetch_events', array( $this->sut, 'fetch_events_and_schedule_processing_jobs' ) ) );
		$this->assertFalse( has_action( 'wcpay_webhook_process_event', array( $this->sut, 'process_event' ) ) );
	}

	/**
	 * @testdox Preserved reliability hooks are registered when native owns runtime.
	 */
	public function test_registers_preserved_actions_when_native_owns_runtime(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );

		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_schedule_fetch_events' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_webhook_fetch_events', array( $this->sut, 'fetch_events_and_schedule_processing_jobs' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_webhook_process_event', array( $this->sut, 'process_event' ) ) );
	}

	/**
	 * @testdox Account refresh flags schedule the preserved failed-event fetch action.
	 */
	public function test_account_refresh_flag_schedules_fetch_job(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service(
			$scheduler,
			wc_get_container()->get( WooPaymentsFailedEventStore::class ),
			new StaticFailedEventsProvider(),
			new RecordingEventIngestor()
		);

		$service->maybe_schedule_fetch_events( array( 'has_more_failed_events' => true ) );

		$this->assertSame(
			array(
				array(
					'hook' => 'wcpay_webhook_fetch_events',
					'args' => array(),
				),
			),
			$scheduler->scheduled_jobs
		);
	}

	/**
	 * @testdox Fetching failed events stores payloads and schedules processing jobs.
	 */
	public function test_fetch_events_stores_events_and_schedules_processing_jobs(): void {
		$scheduler = new RecordingActionSchedulerService();
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$event     = array(
			'id'   => 'evt_1',
			'type' => 'payment_intent.succeeded',
		);
		$service   = $this->create_service(
			$scheduler,
			$store,
			new StaticFailedEventsProvider(
				array(
					'data'     => array( $event, array( 'type' => 'missing.id' ) ),
					'has_more' => false,
				)
			),
			new RecordingEventIngestor()
		);

		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertSame( $event, $store->get_event( 'evt_1' ) );
		$this->assertSame(
			array(
				array(
					'hook' => 'wcpay_webhook_process_event',
					'args' => array( 'event_id' => 'evt_1' ),
				),
			),
			$scheduler->scheduled_jobs
		);
	}

	/**
	 * @testdox Fetching failed events records the last fetch timestamp for supportability.
	 */
	public function test_fetch_events_records_last_fetch_timestamp(): void {
		$this->assertTrue( defined( WooPaymentsWebhookReliabilityService::class . '::LAST_FETCH_OPTION_KEY' ), 'Webhook reliability should expose its last-fetch option key.' );
		$this->assertSame( self::EXPECTED_LAST_FETCH_OPTION, constant( WooPaymentsWebhookReliabilityService::class . '::LAST_FETCH_OPTION_KEY' ) );

		$service = $this->create_service(
			new RecordingActionSchedulerService(),
			wc_get_container()->get( WooPaymentsFailedEventStore::class ),
			new StaticFailedEventsProvider(),
			new RecordingEventIngestor()
		);

		$before = time();
		$service->fetch_events_and_schedule_processing_jobs();
		$after = time();

		$last_fetch = (int) get_option( self::EXPECTED_LAST_FETCH_OPTION, 0 );
		$this->assertGreaterThanOrEqual( $before, $last_fetch );
		$this->assertLessThanOrEqual( $after, $last_fetch );
	}

	/**
	 * @testdox A failed fetch from the platform leaves the last fetch time alone, so the status report does not show a healthy fetch.
	 */
	public function test_failed_fetch_does_not_record_a_fetch_time(): void {
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000, false );
		$provider = new WooPaymentsFailedEventsProvider();
		$provider->init(
			new class() extends WooPaymentsApiClient {
				/**
				 * Fail as the platform does when it cannot answer.
				 *
				 * @return array<string,mixed>
				 * @throws WooPaymentsApiException Always, while the platform is down.
				 */
				public function get_failed_webhook_events(): array {
					if ( 0 < time() ) {
						throw new WooPaymentsApiException( 'Service unavailable.', 'wcpay_server_error', 503 );
					}

					return array();
				}
			}
		);
		$service = $this->create_service( new RecordingActionSchedulerService(), wc_get_container()->get( WooPaymentsFailedEventStore::class ), $provider, new RecordingEventIngestor() );

		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertSame( 1700000000, (int) get_option( self::EXPECTED_LAST_FETCH_OPTION ) );
	}

	/**
	 * @testdox Fetching failed events with the production scheduler queues every distinct event.
	 */
	public function test_fetch_events_schedules_each_distinct_event_with_production_scheduler(): void {
		$store   = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$events  = array_map(
			static function ( int $index ): array {
				return array(
					'id'   => 'evt_backlog_' . $index,
					'type' => 'payment_intent.succeeded',
				);
			},
			range( 1, 5 )
		);
		$service = $this->create_service(
			wc_get_container()->get( WooPaymentsActionSchedulerService::class ),
			$store,
			new StaticFailedEventsProvider(
				array(
					'data'     => $events,
					'has_more' => false,
				)
			),
			new RecordingEventIngestor()
		);

		$service->fetch_events_and_schedule_processing_jobs();

		foreach ( $events as $event ) {
			$this->assertSame( $event, $store->get_event( $event['id'] ) );
			$this->assertSame(
				1,
				$this->count_pending_reliability_actions(
					WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					array( 'event_id' => $event['id'] )
				),
				'Each failed event should receive its own processing action.'
			);
		}

		$this->assertSame(
			5,
			$this->count_pending_reliability_actions( WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ),
			'The webhook backlog should schedule one processing action per distinct event.'
		);
	}

	/**
	 * @testdox Fetching failed events schedules a follow-up fetch when the provider has more.
	 */
	public function test_fetch_events_schedules_follow_up_when_provider_has_more(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service(
			$scheduler,
			wc_get_container()->get( WooPaymentsFailedEventStore::class ),
			new StaticFailedEventsProvider(
				array(
					'data'     => array(),
					'has_more' => true,
				)
			),
			new RecordingEventIngestor()
		);

		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertSame(
			array(
				array(
					'hook' => 'wcpay_webhook_fetch_events',
					'args' => array(),
				),
			),
			$scheduler->scheduled_jobs
		);
	}

	/**
	 * @testdox Processing a failed event consumes the transient and invokes the ingestor.
	 */
	public function test_process_event_consumes_transient_and_invokes_ingestor(): void {
		$store    = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), $store, new StaticFailedEventsProvider(), $ingestor );

		$store->set_event( 'evt_process', $event );
		$service->process_event( 'evt_process' );

		$this->assertNull( $store->get_event( 'evt_process' ) );
		$this->assertSame( array( $event ), $ingestor->processed_events );
	}

	/**
	 * @testdox Processing a missing failed event payload is skipped.
	 */
	public function test_process_event_skips_missing_payload(): void {
		$ingestor = new RecordingEventIngestor();
		$service  = $this->create_service(
			new RecordingActionSchedulerService(),
			wc_get_container()->get( WooPaymentsFailedEventStore::class ),
			new StaticFailedEventsProvider(),
			$ingestor
		);

		$service->process_event( 'evt_missing' );

		$this->assertSame( array(), $ingestor->processed_events );
	}

	/**
	 * @testdox A failed attempt $attempts keeps the event, counts the retry and schedules the next one $delay seconds later.
	 * @dataProvider retry_schedule
	 *
	 * @param int $attempts Retries already scheduled before this attempt.
	 * @param int $delay    Expected delay of the next attempt, in seconds.
	 */
	public function test_process_event_schedules_a_bounded_retry_on_a_passing_failure( int $attempts, int $delay ): void {
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( new \RuntimeException( 'transient boom' ) ) );
		$store->set_event( 'evt_process', $event + ( $attempts > 0 ? array( WooPaymentsWebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $attempts ) : array() ) );

		$thrown = null;
		$before = time();
		try {
			$service->process_event( 'evt_process' );
		} catch ( \RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'The failure must still reach Action Scheduler.' );
		$this->assertSame( $event + array( WooPaymentsWebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $attempts + 1 ), $store->get_event( 'evt_process' ) );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertSame( WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, $scheduler->scheduled_jobs[0]['hook'] );
		$this->assertSame( array( 'event_id' => 'evt_process' ), $scheduler->scheduled_jobs[0]['args'] );
		$this->assertGreaterThanOrEqual( $before + $delay, $scheduler->scheduled_jobs[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + $delay, $scheduler->scheduled_jobs[0]['timestamp'] );
	}

	/** @return array<string,array{int,int}> */
	public static function retry_schedule(): array {
		return array(
			'first failure'  => array( 0, MINUTE_IN_SECONDS ),
			'second failure' => array( 1, 10 * MINUTE_IN_SECONDS ),
			'third failure'  => array( 2, HOUR_IN_SECONDS ),
		);
	}

	/**
	 * @testdox After the third retry fails, the event is dropped, nothing more is scheduled and an error names it.
	 */
	public function test_process_event_gives_up_after_three_retries(): void {
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( new \RuntimeException( 'still failing' ) ) );
		$store->set_event(
			'evt_exhausted',
			array(
				'id'   => 'evt_exhausted',
				'type' => 'payment_intent.succeeded',
				WooPaymentsWebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
			)
		);
		$logged = array();
		$logger = function ( $message, $level ) use ( &$logged ) {
			$logged[] = array( $level, $message );
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $logger, 10, 2 );

		try {
			$service->process_event( 'evt_exhausted' );
			$this->fail( 'The last failure must still reach Action Scheduler.' );
		} catch ( \RuntimeException $exception ) {
			unset( $exception );
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $logger, 10 );
		}

		$this->assertNull( $store->get_event( 'evt_exhausted' ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
		$errors = array_values( array_filter( $logged, static fn( array $entry ): bool => 'error' === $entry[0] && false !== strpos( $entry[1], 'evt_exhausted' ) ) );
		$this->assertNotEmpty( $errors, 'An error naming the dropped event must be logged.' );
		$this->assertStringContainsString( 'payment_intent.succeeded', $errors[0][1] );
	}

	/**
	 * @testdox A payment_intent.succeeded refused by a dead checkout's order payment lock is retried past the lock TTL, then marks the order paid once.
	 */
	public function test_lock_refused_succeeded_event_is_retried_past_the_lock_ttl_and_then_applies(): void {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->save();
		$payment_store = wc_get_container()->get( OrderPaymentStore::class );
		$profile       = new WooPaymentsPersistenceProfile();
		// A checkout that died after the platform captured keeps the lock until its TTL runs out.
		$payment_store->lock_order_payment( $order, $profile, 'pi_lock_ttl' );
		// Shape read by client 11.1.0 class-wc-payments-webhook-processing-service.php:494-519 (object id, currency,
		// amount, payment_method, charges.data[0]) and :968-1002 (metadata.order_id for the order lookup); status is
		// the PaymentIntent's own field (Stripe API PaymentIntent object).
		$event     = array(
			'id'   => 'evt_lock_ttl',
			'type' => 'payment_intent.succeeded',
			'data' => array(
				'object' => array(
					'id'             => 'pi_lock_ttl',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'amount'         => 1000,
					'payment_method' => 'pm_lock_ttl',
					'metadata'       => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => $order->get_order_key(),
					),
					'charges'        => array(
						'data' => array(
							array(
								'id'             => 'ch_lock_ttl',
								'payment_method' => 'pm_lock_ttl',
							),
						),
					),
				),
			),
		);
		$ingestor  = wc_get_container()->get( WooPaymentsEventIngestor::class );
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), $ingestor );

		try {
			// The webhook route hands the failed delivery to the reliability service.
			try {
				$ingestor->process( $event );
				$this->fail( 'A delivery refused by the lock must fail instead of being acknowledged.' );
			} catch ( OrderPaymentLockRefusedException $refusal ) {
				$service->retry_failed_event( $event, $refusal );
			}
			$this->assertSame( $event, $store->get_event( 'evt_lock_ttl' ) );

			$waited = 0;
			while ( $waited <= OrderPaymentStore::LOCK_TTL_SECONDS ) {
				$scheduler->scheduled_jobs = array();
				try {
					$service->process_event( 'evt_lock_ttl' );
					$this->fail( 'Each attempt while the lock is held must be refused.' );
				} catch ( OrderPaymentLockRefusedException $exception ) {
					unset( $exception );
				}
				$this->assertCount( 1, $scheduler->scheduled_jobs, 'Every refused attempt must schedule the next one until the lock has expired.' );
				$waited += $scheduler->scheduled_jobs[0]['timestamp'] - time();
			}
		} finally {
			// The holder's TTL has run out by the time this attempt is due.
			$payment_store->unlock_order_payment( $order, $profile );
		}

		$scheduler->scheduled_jobs = array();
		$service->process_event( 'evt_lock_ttl' );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->needs_payment(), 'The retry after the lock expired must record the payment.' );
		$this->assertSame( 'pi_lock_ttl', $order->get_transaction_id() );
		$charge_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'successfully charged' )
		);
		$this->assertCount( 1, $charge_notes, 'The payment is recorded once.' );
		$this->assertNull( $store->get_event( 'evt_lock_ttl' ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox A $event_type delivery refused by the order payment lock is kept and retried, although the type gets one attempt after other failures.
	 * @dataProvider event_types_retried_after_a_lock_refusal
	 *
	 * @param string $event_type Event type safe to apply late, retried only after a lock refusal.
	 */
	public function test_lock_refusal_is_retried_for_an_event_type_safe_to_apply_late( string $event_type ): void {
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => $event_type,
		);
		$refusal   = new OrderPaymentLockRefusedException( 123, 'refund webhook' );
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( $refusal ) );

		$service->retry_failed_event( $event, new \RuntimeException( 'failed after a write' ) );
		$this->assertNull( $store->get_event( 'evt_process' ), 'Another failure of this type keeps its one attempt.' );

		$service->retry_failed_event( $event, $refusal );
		$this->assertSame( $event, $store->get_event( 'evt_process' ) );

		$scheduler->scheduled_jobs = array();
		$before                    = time();
		try {
			$service->process_event( 'evt_process' );
			$this->fail( 'The refusal must still reach Action Scheduler.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			unset( $exception );
		}

		$this->assertSame( $event + array( WooPaymentsWebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 1 ), $store->get_event( 'evt_process' ) );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertGreaterThanOrEqual( $before + MINUTE_IN_SECONDS, $scheduler->scheduled_jobs[0]['timestamp'] );
	}

	/** @return array<string,array{string}> */
	public static function event_types_retried_after_a_lock_refusal(): array {
		return array(
			'fraud warning created' => array( 'radar.early_fraud_warning.created' ),
			'fraud warning updated' => array( 'radar.early_fraud_warning.updated' ),
			'capture expiry'        => array( 'charge.expired' ),
		);
	}

	/**
	 * @testdox A $event_type delivery refused by the order payment lock keeps its one attempt, since a late one could undo a newer event, and is noted on its order.
	 * @dataProvider event_types_not_safe_to_apply_late
	 *
	 * @param string $event_type Refund or dispute event type.
	 */
	public function test_lock_refusal_of_a_refund_or_dispute_event_is_noted_and_not_retried( string $event_type ): void {
		$order     = wc_create_order();
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => $event_type,
		);
		$refusal   = new OrderPaymentLockRefusedException( $order->get_id(), 'refund webhook' );
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( $refusal ) );
		$store->set_event( 'evt_process', $event );

		// The failed action runs twice (Action Scheduler retried it, or someone ran it again by hand).
		for ( $run = 1; $run <= 2; $run++ ) {
			try {
				$service->process_event( 'evt_process' );
				$this->fail( 'The refusal must still reach Action Scheduler.' );
			} catch ( OrderPaymentLockRefusedException $exception ) {
				unset( $exception );
			}
		}

		$this->assertSame( array(), $scheduler->scheduled_jobs );
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'evt_process' ) && false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertCount( 1, $notes );
	}

	/** @return array<string,array{string}> */
	public static function event_types_not_safe_to_apply_late(): array {
		return array(
			'refund'          => array( 'charge.refunded' ),
			'refund updated'  => array( 'charge.refund.updated' ),
			'dispute created' => array( 'charge.dispute.created' ),
			'dispute closed'  => array( 'charge.dispute.closed' ),
		);
	}

	/**
	 * @testdox An event the order payment lock refused on every attempt via the $path path is dropped with an error line and a note on its order.
	 * @dataProvider delivery_paths
	 *
	 * @param string $path How the event reached the store: 'webhook' or 'pull'.
	 */
	public function test_lock_refusal_on_every_attempt_drops_the_event_with_an_order_note( string $path ): void {
		$order     = wc_create_order();
		$event_id  = 'evt_lock_exhausted_' . $path;
		$event     = array(
			'id'   => $event_id,
			'type' => 'radar.early_fraud_warning.created',
		);
		$refusal   = new OrderPaymentLockRefusedException( $order->get_id(), 'early fraud warning webhook' );
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		// Page shape of the platform's failed-events list (wpcom class-webhook-controller.php:346-357: object 'list', data,
		// has_more). The event is reduced to id and type, all the reliability service reads; the ingestor is a stub.
		$provider = new StaticFailedEventsProvider(
			array(
				'data'     => 'pull' === $path ? array( $event ) : array(),
				'has_more' => false,
			)
		);
		$service  = $this->create_service( $scheduler, $store, $provider, new ThrowingEventIngestor( $refusal ) );
		$logged   = array();
		$logger   = function ( $message, $level ) use ( &$logged ) {
			$logged[] = array( $level, $message );
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $logger, 10, 2 );

		try {
			if ( 'webhook' === $path ) {
				$service->retry_failed_event( $event, $refusal );
			} else {
				$service->fetch_events_and_schedule_processing_jobs();
			}
			$attempts = 1 + count( WooPaymentsWebhookReliabilityService::RETRY_DELAYS_SECONDS );
			for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
				$this->assertNotNull( $store->get_event( $event_id ), 'The event is kept until its last attempt.' );
				try {
					$service->process_event( $event_id );
				} catch ( OrderPaymentLockRefusedException $exception ) {
					unset( $exception );
				}
			}
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $logger, 10 );
		}

		$this->assertNull( $store->get_event( $event_id ) );
		$retries = array_filter( $scheduler->scheduled_jobs, static fn( array $job ): bool => isset( $job['timestamp'] ) );
		$this->assertCount( count( WooPaymentsWebhookReliabilityService::RETRY_DELAYS_SECONDS ), $retries, 'No attempt is scheduled after the last one.' );
		// The message filter runs once per log handler, so a line is counted once.
		$errors = array_values( array_unique( array_column( array_filter( $logged, static fn( array $entry ): bool => 'error' === $entry[0] && false !== strpos( $entry[1], $event_id ) ), 1 ) ) );
		$this->assertCount( 1, $errors, 'One error line names the dropped event.' );
		$this->assertStringContainsString( 'radar.early_fraud_warning.created', $errors[0] );
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, $event_id ) && false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertCount( 1, $notes, 'The merchant sees that the update did not reach the order.' );
	}

	/** @return array<string,array{string}> */
	public static function delivery_paths(): array {
		return array(
			'webhook' => array( 'webhook' ),
			'pull'    => array( 'pull' ),
		);
	}

	/**
	 * @testdox A dropped event's line carries the platform error's status and code, never its message.
	 */
	public function test_dropped_event_log_leaves_out_platform_text(): void {
		$store   = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$service = $this->create_service( new RecordingActionSchedulerService(), $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( self::make_provider_error() ) );
		$store->set_event(
			'evt_exhausted',
			array(
				'id'   => 'evt_exhausted',
				'type' => 'payment_intent.succeeded',
				WooPaymentsWebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
			)
		);
		$logger = RecordingWcLogger::install();

		try {
			$service->process_event( 'evt_exhausted' );
		} catch ( \RuntimeException $exception ) {
			unset( $exception );
		}

		$context = $this->get_logged_context( $logger, 'WooPayments webhook event evt_exhausted (payment_intent.succeeded) could not be processed after 3 retries and was dropped.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A refusal that wraps a platform error is logged by the platform's codes, without the refusal's message.
	 *
	 * The Stripe Billing handler passes on its module's refusal (StripeBillingEventHandler::handle_event()), which can carry
	 * the platform's text; every other refusal's reason is a constant and stays on the line.
	 */
	public function test_refusal_wrapping_a_platform_error_leaves_out_its_text(): void {
		$store   = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$service = $this->create_service(
			new RecordingActionSchedulerService(),
			$store,
			new StaticFailedEventsProvider(),
			new ThrowingEventIngestor( new \InvalidArgumentException( self::make_provider_error()->getMessage(), 0, self::make_provider_error() ) )
		);
		$store->set_event(
			'evt_process',
			array(
				'id'   => 'evt_process',
				'type' => 'invoice.paid',
			)
		);
		$logger = RecordingWcLogger::install();

		$service->process_event( 'evt_process' );

		$context = $this->get_logged_context( $logger, 'Failed processing event evt_process.' );
		$this->assertSame( array( \InvalidArgumentException::class, 404, 'resource_missing' ), array( $context['exception'], $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A refund event that fails gets one attempt, as on the client: no retry is scheduled.
	 */
	public function test_process_event_does_not_retry_an_event_type_off_the_list(): void {
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_refund',
			'type' => 'charge.refunded',
		);
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( new \RuntimeException( 'transient boom' ) ) );
		$store->set_event( 'evt_refund', $event );

		try {
			$service->process_event( 'evt_refund' );
			$this->fail( 'The failure must still reach Action Scheduler.' );
		} catch ( \RuntimeException $exception ) {
			unset( $exception );
		}

		$this->assertSame( array(), $scheduler->scheduled_jobs );
		$this->assertSame( $event, $store->get_event( 'evt_refund' ), 'The stored event is left as it was, with no retry count.' );
	}

	/**
	 * @testdox A retried event reaches the ingestor without the retry count and is removed once processed.
	 */
	public function test_retried_event_is_processed_without_the_retry_count(): void {
		$store    = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_retried',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), $store, new StaticFailedEventsProvider(), $ingestor );
		$store->set_event( 'evt_retried', $event + array( WooPaymentsWebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 2 ) );

		$service->process_event( 'evt_retried' );

		$this->assertSame( array( $event ), $ingestor->processed_events );
		$this->assertNull( $store->get_event( 'evt_retried' ) );
	}

	/**
	 * @testdox A malformed retried event is dropped without another attempt.
	 */
	public function test_malformed_event_is_dropped_without_retry(): void {
		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), new ThrowingEventIngestor( new \InvalidArgumentException( 'malformed event' ) ) );
		$store->set_event(
			'evt_malformed',
			array(
				'id'   => 'evt_malformed',
				'type' => 'payment_intent.succeeded',
			)
		);

		$service->process_event( 'evt_malformed' );

		$this->assertNull( $store->get_event( 'evt_malformed' ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox A refused event is dropped, one error line names it and the job completes, as on the client.
	 *
	 * Client 11.1.0 `class-wc-payments-webhook-reliability-service.php:136-149`: the stored event is deleted, and an
	 * `Invalid_Webhook_Data_Exception` is logged as "Failed processing event {id}. Reason: {message}" and not re-thrown.
	 */
	public function test_process_event_drops_and_logs_a_refused_event_without_failing_the_job(): void {
		$store   = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$service = $this->create_service(
			new RecordingActionSchedulerService(),
			$store,
			new StaticFailedEventsProvider(),
			new ThrowingEventIngestor( new \InvalidArgumentException( 'malformed event' ) )
		);
		$store->set_event(
			'evt_process',
			array(
				'id'   => 'evt_process',
				'type' => 'payment_intent.succeeded',
			)
		);
		$logger = RecordingWcLogger::install();

		$service->process_event( 'evt_process' );

		$this->assertNull( $store->get_event( 'evt_process' ), 'A refused event should be dropped so it is not processed again.' );
		$this->assertSame(
			array( array( 'error', 'Failed processing event evt_process. Reason: malformed event', 'native-payments-webhook' ) ),
			$logger->get_errors()
		);
	}

	/**
	 * @testdox Without the Stripe Billing module, a stored invoice event is refused with exactly one error line, from the job.
	 */
	public function test_invoice_event_refused_without_the_stripe_billing_module_logs_one_line(): void {
		$module = $this->getMockBuilder( WooPaymentsStripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( false );
		$module->expects( $this->never() )->method( 'handle_invoice_event' );
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );
		add_filter( WooPaymentsEventIngestor::FILTER_LIVE_MODE, '__return_false' );

		$store     = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, $store, new StaticFailedEventsProvider(), wc_get_container()->get( WooPaymentsEventIngestor::class ) );
		$store->set_event(
			'evt_process',
			array(
				'id'       => 'evt_process',
				'type'     => 'invoice.paid',
				'livemode' => false,
				'data'     => array( 'object' => array( 'id' => 'in_123' ) ),
			)
		);
		$logger = RecordingWcLogger::install();

		$service->process_event( 'evt_process' );

		$this->assertNull( $store->get_event( 'evt_process' ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
		$this->assertSame(
			array( array( 'error', 'Failed processing event evt_process. Reason: Cannot find subscription for the incoming "invoice.paid" event.', 'native-payments-webhook' ) ),
			$logger->get_errors()
		);
	}

	/**
	 * Remove reliability hooks for this SUT.
	 */
	private function remove_reliability_hooks(): void {
		remove_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_schedule_fetch_events' ) );
		remove_action( 'wcpay_webhook_fetch_events', array( $this->sut, 'fetch_events_and_schedule_processing_jobs' ) );
		remove_action( 'wcpay_webhook_process_event', array( $this->sut, 'process_event' ) );
	}

	/**
	 * Count pending webhook reliability actions.
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Action args.
	 * @return int
	 */
	private function count_pending_reliability_actions( string $hook, array $args = array() ): int {
		$query_args = array(
			'hook'   => $hook,
			'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
			'status' => ActionScheduler_Store::STATUS_PENDING,
		);

		if ( array() !== $args ) {
			$query_args['args'] = $args;
		}

		return count( as_get_scheduled_actions( $query_args ) );
	}

	/**
	 * Remove scheduled webhook reliability actions.
	 */
	private function unschedule_reliability_actions(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		foreach (
			array(
				WooPaymentsWebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION,
				WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
			) as $hook
		) {
			foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
				$action_ids = as_get_scheduled_actions(
					array(
						'hook'   => $hook,
						'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
						'status' => $status,
					),
					'ids'
				);

				foreach ( $action_ids as $action_id ) {
					ActionScheduler::store()->cancel_action( (int) $action_id );
				}
			}
		}
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * @param bool $active Whether the WooPayments plugin should appear active.
	 */
	private function fake_plugin( bool $active ): void {
		$entry = NativePaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $active, $entry ) {
					if ( 'active_plugins' === $name ) {
						return $active ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return array();
					}
					return get_site_option( $name, $default_value );
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $active ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $active;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);
	}

	/**
	 * Create a reliability service with supplied collaborators.
	 *
	 * @param WooPaymentsActionSchedulerService $scheduler Scheduler service.
	 * @param WooPaymentsFailedEventStore       $store     Failed event store.
	 * @param WooPaymentsFailedEventsProvider   $provider  Failed events provider.
	 * @param WooPaymentsEventIngestor          $ingestor  Event ingestor.
	 * @return WooPaymentsWebhookReliabilityService
	 */
	private function create_service(
		WooPaymentsActionSchedulerService $scheduler,
		WooPaymentsFailedEventStore $store,
		WooPaymentsFailedEventsProvider $provider,
		WooPaymentsEventIngestor $ingestor
	): WooPaymentsWebhookReliabilityService {
		$service = new WooPaymentsWebhookReliabilityService();
		$service->init( wc_get_container()->get( NativePaymentsRuntimeArbiter::class ), $scheduler, $store, $provider, $ingestor );

		return $service;
	}
}
