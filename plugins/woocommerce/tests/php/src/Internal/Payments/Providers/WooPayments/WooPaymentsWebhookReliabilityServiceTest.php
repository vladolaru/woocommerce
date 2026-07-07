<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedEventStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedEventsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWebhookReliabilityService class.
 */
class WooPaymentsWebhookReliabilityServiceTest extends WC_Unit_Test_Case {

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
	 * @testdox Processing keeps the stored event when the ingestor fails transiently.
	 */
	public function test_process_event_preserves_event_on_transient_failure(): void {
		$store   = wc_get_container()->get( WooPaymentsFailedEventStore::class );
		$event   = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service = $this->create_service(
			new RecordingActionSchedulerService(),
			$store,
			new StaticFailedEventsProvider(),
			new ThrowingEventIngestor( new \RuntimeException( 'transient boom' ) )
		);

		$store->set_event( 'evt_process', $event );

		$thrown = null;
		try {
			$service->process_event( 'evt_process' );
		} catch ( \RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'A transient failure should propagate to the caller.' );
		$this->assertSame( $event, $store->get_event( 'evt_process' ), 'A transiently failed event should remain in the store for retry.' );
	}

	/**
	 * @testdox Processing drops the stored event when the ingestor reports it is malformed.
	 */
	public function test_process_event_drops_event_on_invalid_argument(): void {
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

		$thrown = null;
		try {
			$service->process_event( 'evt_process' );
		} catch ( \InvalidArgumentException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \InvalidArgumentException::class, $thrown, 'A malformed event should still surface the failure to the caller.' );
		$this->assertNull( $store->get_event( 'evt_process' ), 'A malformed event should be dropped so it does not retry forever.' );
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
