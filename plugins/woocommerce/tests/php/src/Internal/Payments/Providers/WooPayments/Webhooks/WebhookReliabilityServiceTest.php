<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\OtherChargeRecorder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockWithClaimHook;
use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingActionSchedulerService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Unit_Test_Case;

/**
 * Tests for the WebhookReliabilityService class.
 */
class WebhookReliabilityServiceTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	use ProviderTextLogAssertions;

	/**
	 * Expected option key for the native failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	private const EXPECTED_LAST_FETCH_OPTION = 'woocommerce_woopayments_last_webhook_fetch';

	/**
	 * The System Under Test.
	 *
	 * @var WebhookReliabilityService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WebhookReliabilityService::class );
		$this->remove_reliability_hooks();
		$this->unschedule_reliability_actions();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->remove_reliability_hooks();
		$this->unschedule_reliability_actions();
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->reset_all_replacements();
		foreach ( array( 'evt_1', 'evt_process', 'evt_backlog_1', 'evt_backlog_2', 'evt_backlog_3', 'evt_backlog_4', 'evt_backlog_5' ) as $event_id ) {
			delete_transient( QueuedEventRows::name( $event_id ) );
			( new QueuedEventRows() )->delete_event( $event_id );
		}
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		parent::tearDown();
	}

	/**
	 * @testdox Registers the account refresh listener, the two scheduled webhook actions and the sweep.
	 */
	public function test_registers_the_reliability_hooks(): void {
		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_schedule_fetch_events' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_webhook_fetch_events', array( $this->sut, 'fetch_events_and_schedule_processing_jobs' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_webhook_process_event', array( $this->sut, 'process_event' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_woopayments_sweep_queued_events', array( $this->sut, 'sweep_queued_events' ) ) );
	}

	/**
	 * @testdox Account refresh flags schedule the preserved failed-event fetch action.
	 */
	public function test_account_refresh_flag_schedules_fetch_job(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service(
			$scheduler,
			new StaticFailedEventsApiClient(),
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
	 * @testdox Fetching failed events records the last fetch timestamp for supportability.
	 */
	public function test_fetch_events_records_last_fetch_timestamp(): void {
		$service = $this->create_service(
			new RecordingActionSchedulerService(),
			new StaticFailedEventsApiClient(),
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
	 * @testdox A fetch that succeeds after a failed one records its time again.
	 */
	public function test_fetch_after_a_failure_records_its_time(): void {
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000, false );
		$client  = new class() extends Client {
			/**
			 * Calls made so far.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Fail on the first call, as during a platform outage, then answer with an empty page.
			 *
			 * @return array<string,mixed>
			 * @throws ApiException On the first call.
			 */
			public function get_failed_webhook_events(): array {
				++$this->calls;
				if ( 1 === $this->calls ) {
					throw new ApiException( 'Service unavailable.', 'wcpay_server_error', 503 );
				}

				// Page shape of the platform's failed-events list (wpcom class-webhook-controller.php:346-357).
				return array(
					'object'   => 'list',
					'data'     => array(),
					'has_more' => false,
				);
			}
		};
		$service = $this->create_service( new RecordingActionSchedulerService(), $client, new RecordingEventIngestor() );

		$service->fetch_events_and_schedule_processing_jobs();
		$before = time();
		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertGreaterThanOrEqual( $before, (int) get_option( self::EXPECTED_LAST_FETCH_OPTION ) );
	}

	/**
	 * @testdox A failed fetch from the platform leaves the last fetch time alone, so the status report does not show a healthy fetch.
	 */
	public function test_failed_fetch_does_not_record_a_fetch_time(): void {
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000, false );
		$client  = new class() extends Client {
			/**
			 * Fail as the platform does when it cannot answer.
			 *
			 * @return array<string,mixed>
			 * @throws ApiException Always, while the platform is down.
			 */
			public function get_failed_webhook_events(): array {
				if ( 0 < time() ) {
					throw new ApiException( 'Service unavailable.', 'wcpay_server_error', 503 );
				}

				return array();
			}
		};
		$service = $this->create_service( new RecordingActionSchedulerService(), $client, new RecordingEventIngestor() );

		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertSame( 1700000000, (int) get_option( self::EXPECTED_LAST_FETCH_OPTION ) );
	}

	/**
	 * @testdox A fetched page keeps only its event entries and asks for the next page when the platform has more.
	 */
	public function test_fetch_keeps_only_event_entries_and_follows_has_more(): void {
		$scheduler = new RecordingActionSchedulerService();
		// The page envelope client 11.1.0 reads (`includes/class-wc-payments-webhook-reliability-service.php:100-121`):
		// it stores each entry with an `id` and schedules it by that ID, skips one without, and fetches again on
		// `has_more`. The event is reduced to its ID because the ingestor is stubbed and nothing reads the rest;
		// `not-an-event` is deliberately malformed input.
		$service = $this->create_service(
			$scheduler,
			new StaticFailedEventsApiClient(
				array(
					'data'     => array(
						array(
							'id' => 'evt_1',
						),
						'not-an-event',
					),
					'has_more' => true,
				)
			),
			new RecordingEventIngestor()
		);

		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertSame( array( 'id' => 'evt_1' ), ( new QueuedEventRows() )->get_event( 'evt_1' ) );
		$this->assertSame(
			array(
				array(
					'hook' => 'wcpay_webhook_process_event',
					'args' => array( 'event_id' => 'evt_1' ),
				),
			),
			$scheduler->jobs_for( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION )
		);
		$this->assertSame(
			array(
				array(
					'hook' => 'wcpay_webhook_fetch_events',
					'args' => array(),
				),
			),
			$scheduler->jobs_for( WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION )
		);
	}

	/**
	 * @testdox A platform error fetching failed events schedules nothing and is logged with its status and code, never its message.
	 *
	 * Client 11.1.0 appends the platform's message to the line (`class-wc-payments-webhook-reliability-service.php`).
	 *
	 * @dataProvider provider_fetch_failures
	 *
	 * @param ApiException $failure         Error the API client throws.
	 * @param int          $expected_status Logged HTTP status.
	 * @param string       $expected_code   Logged error code.
	 */
	public function test_fetch_failure_log_leaves_out_platform_text( ApiException $failure, int $expected_status, string $expected_code ): void {
		$api_client = $this->createMock( Client::class );
		$api_client->method( 'get_failed_webhook_events' )->willThrowException( $failure );
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, $api_client, new RecordingEventIngestor() );
		$logger    = RecordingWcLogger::install();

		$service->fetch_events_and_schedule_processing_jobs();

		$this->assertSame( array(), $scheduler->scheduled_jobs );
		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( $expected_status, $expected_code ), array( $context['http_status'], $context['error_code'] ) );
		$this->assertSame( 'woopayments', $context['source'] ?? null, 'Fetch failures land in the WooPayments log with every other webhook line.' );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Platform errors the failed-events fetch can meet.
	 *
	 * @return array<string,array{0:ApiException,1:int,2:string}>
	 */
	public function provider_fetch_failures(): array {
		return array(
			'server error'                => array( new ApiException( 'Remote fetch failed.', 'wcpay_failed_events_unavailable', 500 ), 500, 'unknown_error' ),
			'error carrying shopper text' => array( self::make_provider_error(), 404, 'resource_missing' ),
		);
	}

	/**
	 * @testdox Fetching failed events with the production scheduler queues every distinct event and skips an event without an ID.
	 */
	public function test_fetch_events_schedules_each_distinct_event_with_production_scheduler(): void {
		$store   = new QueuedEventRows();
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
			new StaticFailedEventsApiClient(
				array(
					// The last entry is deliberately malformed: an event without an ID, which the fetch skips.
					'data'     => array_merge( $events, array( array( 'type' => 'missing.id' ) ) ),
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
					WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					array( 'event_id' => $event['id'] )
				),
				'Each failed event should receive its own processing action.'
			);
		}

		$this->assertSame(
			5,
			$this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ),
			'The webhook backlog should schedule one processing action per distinct event, and none for an event without an ID.'
		);
	}

	/**
	 * @testdox Fetching failed events schedules a follow-up fetch when the provider has more.
	 */
	public function test_fetch_events_schedules_follow_up_when_provider_has_more(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service(
			$scheduler,
			new StaticFailedEventsApiClient(
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
	 * @testdox Processing a failed event consumes the stored copy and invokes the ingestor.
	 */
	public function test_process_event_consumes_the_stored_copy_and_invokes_ingestor(): void {
		$store    = new QueuedEventRows();
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );

		$store->set_event( 'evt_process', $event );
		$service->process_event( 'evt_process' );

		$this->assertNull( $store->get_event( 'evt_process' ) );
		$this->assertSame( array( $event ), $ingestor->processed_events );
	}

	/**
	 * @testdox A queued event is kept in one row without autoload, with the extension-name copy beside it, and both go once it is applied.
	 */
	public function test_queued_event_is_kept_in_one_row_without_autoload_and_under_the_extension_name(): void {
		$store    = new QueuedEventRows();
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );

		$this->assertTrue( $service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) ) );

		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", QueuedEventRows::row_name( 'evt_process' ) ) ) );
		$row = $store->get_row( 'evt_process' );
		$this->assertSame( 'off', $row['autoload'] ?? null, 'The row is never loaded with every request.' );
		$this->assertSame( $event, $row['value']['event'] ?? null );
		$this->assertIsInt( $row['value']['stored_at'] ?? null );
		$this->assertSame( $event, $store->get_transient_event( 'evt_process' ), 'The extension can still find the event after a return to it.' );

		$service->process_event( 'evt_process' );

		$this->assertSame( array( $event ), $ingestor->processed_events );
		$this->assertNull( $store->get_row( 'evt_process' ) );
		$this->assertNull( $store->get_transient_event( 'evt_process' ) );
	}

	/**
	 * @testdox A queued event survives a flush of a persistent object cache and is applied once.
	 */
	public function test_queued_event_survives_a_cache_flush_and_is_applied(): void {
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );
		$was_ext  = wp_using_ext_object_cache( true );
		try {
			$service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) );
			wp_cache_flush();
			$service->process_event( 'evt_process' );
		} finally {
			wp_using_ext_object_cache( (bool) $was_ext );
		}

		$this->assertSame( array( $event ), $ingestor->processed_events );
	}

	/**
	 * @testdox A stale "missing" entry for the row in WordPress's options cache does not hide a queued event.
	 */
	public function test_stale_missing_entry_in_the_options_cache_does_not_hide_a_queued_event(): void {
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );
		$service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) );
		// The extension-name copy is gone, as a flush leaves it on an object-cache store, so only the row can answer.
		delete_transient( QueuedEventRows::name( 'evt_process' ) );
		wp_cache_set( 'notoptions', array( QueuedEventRows::row_name( 'evt_process' ) => true ), 'options' );

		$service->process_event( 'evt_process' );

		$this->assertSame( array( $event ), $ingestor->processed_events );
	}

	/**
	 * @testdox An event kept only under the extension's transient name, as the extension or an earlier build queued it, is applied and removed.
	 */
	public function test_event_kept_only_under_the_extension_name_is_applied_and_removed(): void {
		$store    = new QueuedEventRows();
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );
		$store->set_transient_event( 'evt_process', $event );

		$service->process_event( 'evt_process' );

		$this->assertSame( array( $event ), $ingestor->processed_events );
		$this->assertNull( $store->get_transient_event( 'evt_process' ) );
	}

	/**
	 * @testdox A queued event whose row $scenario logs $errors error line(s).
	 * @testWith ["cannot be written", 1]
	 *           ["is stored again with the same bytes in one second, changing nothing,", 0]
	 *
	 * @param string $scenario How the row write goes.
	 * @param int    $errors   Expected error lines.
	 */
	public function test_queued_event_that_cannot_be_stored_is_logged( string $scenario, int $errors ): void {
		$event   = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), new RecordingEventIngestor() );
		$logger  = RecordingWcLogger::install();

		if ( 0 === $errors ) {
			// Both stores must fall in one second, so the second writes the same bytes and MySQL reports 0 rows changed.
			for ( $try = 0; $try < 3; $try++ ) {
				$second = time();
				$service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) );
				$service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) );
				if ( time() === $second ) {
					break;
				}
			}
			$this->assertSame( $event, ( new QueuedEventRows() )->get_event( 'evt_process' ) );
		} else {
			$failed = false;
			$fail   = function ( $query ) use ( &$fail, &$failed ) {
				if ( false !== strpos( (string) $query, QueuedEventRows::row_name( 'evt_process' ) ) && 0 === strpos( ltrim( (string) $query ), 'INSERT' ) ) {
					$failed = true;
					remove_filter( 'query', $fail );
					return 'INSERT INTO a_table_that_does_not_exist (a) VALUES (1)';
				}
				return $query;
			};
			add_filter( 'query', $fail );
			$suppress = $GLOBALS['wpdb']->suppress_errors( true );
			try {
				$service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) );
			} finally {
				$GLOBALS['wpdb']->suppress_errors( $suppress );
				remove_filter( 'query', $fail );
			}
			$this->assertTrue( $failed, 'The row write must have failed (' . $scenario . ').' );
		}

		$lines = $logger->get_errors();
		$this->assertCount( $errors, $lines );
		if ( 1 === $errors ) {
			$this->assertStringContainsString( 'evt_process could not be stored', $lines[0][1] );
			$this->assertSame( 'woopayments', $lines[0][2] );
		}
	}

	/**
	 * @testdox Processing a missing failed event payload is skipped, with one error line naming the event.
	 */
	public function test_process_event_skips_missing_payload(): void {
		$ingestor = new RecordingEventIngestor();
		$service  = $this->create_service(
			new RecordingActionSchedulerService(),
			new StaticFailedEventsApiClient(),
			$ingestor
		);
		$logger   = RecordingWcLogger::install();

		$service->process_event( 'evt_missing' );

		$this->assertSame( array(), $ingestor->processed_events );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors, 'A retry that finds no stored copy says so once.' );
		$this->assertStringContainsString( 'evt_missing', $errors[0][1] );
		$this->assertSame( 'woopayments', $errors[0][2] );
	}

	/**
	 * @testdox A failed attempt $attempts keeps the event, counts the retry and schedules the next one $delay seconds later.
	 * @dataProvider retry_schedule
	 *
	 * @param int $attempts Retries already scheduled before this attempt.
	 * @param int $delay    Expected delay of the next attempt, in seconds.
	 */
	public function test_process_event_schedules_a_bounded_retry_on_a_passing_failure( int $attempts, int $delay ): void {
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( new \RuntimeException( 'transient boom' ) ) );
		$store->set_event( 'evt_process', $event + ( $attempts > 0 ? array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $attempts ) : array() ) );

		$thrown = null;
		$before = time();
		try {
			$service->process_event( 'evt_process' );
		} catch ( \RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'The failure must still reach Action Scheduler.' );
		$this->assertSame( $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $attempts + 1 ), $store->get_event( 'evt_process' ) );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertSame( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, $scheduler->scheduled_jobs[0]['hook'] );
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
	 * @testdox A payment_intent.succeeded refused by a dead checkout's order payment lock is retried past the lock TTL, then marks the order paid once.
	 */
	public function test_lock_refused_succeeded_event_is_retried_past_the_lock_ttl_and_then_applies(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_feature_multi_currency_enabled', 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '1.04' );
		$frontend = wc_get_container()->get( MultiCurrencyFrontendCurrenciesController::class );
		$order    = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->add_product( \WC_Helper_Product::create_simple_product() );
		$order->set_currency( 'EUR' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		// The shopper's request uses the EUR decimals. The retry job has no frontend currency hooks.
		add_filter( 'wc_get_price_decimals', array( $frontend, 'get_price_decimals' ), 900 );
		try {
			$frontend->format_in_order_currency(
				'EUR',
				static function () use ( $order ): string {
					$order->set_total( '10.40' );
					$order->save();
					return (string) $order->get_total();
				}
			);
		} finally {
			remove_filter( 'wc_get_price_decimals', array( $frontend, 'get_price_decimals' ), 900 );
		}
		$this->assertSame( '10.40', $order->get_total() );
		$this->assertSame( 0, wc_get_price_decimals() );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		// A checkout that died after the platform captured keeps the lock until its TTL runs out.
		$this->hold_order_payment_lock( $order, $vocabulary, 'pi_lock_ttl' );
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
					'currency'       => 'eur',
					'amount'         => 1040,
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
		$ingestor  = wc_get_container()->get( EventIngestor::class );
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), $ingestor );

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
			while ( $waited <= WooPaymentsPersistenceVocabulary::LOCK_TTL_SECONDS ) {
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
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$scheduler->scheduled_jobs = array();
		$logger                    = RecordingWcLogger::install();
		$on_hold_notifications     = did_action( 'woocommerce_order_status_pending_to_on-hold_notification' );
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			$frontend->register();
			$this->assertFalse( has_filter( 'wc_get_price_decimals', array( $frontend, 'get_price_decimals' ) ) );
			$service->process_event( 'evt_lock_ttl' );
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status() );
		$this->assertSame( $on_hold_notifications, did_action( 'woocommerce_order_status_pending_to_on-hold_notification' ) );
		$this->assertStringNotContainsString( 'put on hold: intent', implode( ' ', array_column( $logger->get_errors(), 1 ) ) );
		$this->assertStringNotContainsString( 'was put <strong>on hold</strong>', implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) ) );
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
	 * @testdox A $event_type the order payment lock refuses at attempt $attempts, held $holder_age seconds, is retried at the holder's expiry ($at_expiry) or after $delay seconds.
	 * @dataProvider held_up_retry_cases
	 *
	 * @param string   $event_type Event type.
	 * @param int      $attempts   Retries already scheduled before this attempt.
	 * @param int|null $holder_age Age of the refusing holder, in seconds; null when its record does not describe the lock.
	 * @param bool     $at_expiry  Whether the next attempt is due just after the holder's lock expires.
	 * @param int      $delay      Otherwise, the delay of the next attempt, in seconds.
	 */
	public function test_lock_refused_retry_runs_at_the_holder_expiry_only_for_a_held_up_success( string $event_type, int $attempts, ?int $holder_age, bool $at_expiry, int $delay ): void {
		$store      = new QueuedEventRows();
		$scheduler  = new RecordingActionSchedulerService();
		$before     = time();
		$claimed_at = null === $holder_age ? null : $before - $holder_age;
		$event      = array(
			'id'   => 'evt_process',
			'type' => $event_type,
		);
		$service    = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( new OrderPaymentLockRefusedException( 1, 'payment status update', $claimed_at ) ) );
		$store->set_event( 'evt_process', $event + ( $attempts > 0 ? array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $attempts ) : array() ) );

		try {
			$service->process_event( 'evt_process' );
			$this->fail( 'The refusal must reach Action Scheduler.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			unset( $exception );
		}

		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$due    = $scheduler->scheduled_jobs[0]['timestamp'];
		$stored = $store->get_event( 'evt_process' );
		$this->assertSame( $attempts + 1, $stored[ WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY ] ?? null );
		if ( $at_expiry ) {
			$this->assertSame( $claimed_at + WooPaymentsPersistenceVocabulary::LOCK_TTL_SECONDS + 10, $due, 'Due 10 seconds after the holder\'s lock expires.' );
			$this->assertGreaterThanOrEqual( $before + 10 * MINUTE_IN_SECONDS - $due, $stored['_wcpay_retry_brought_forward'] ?? null, 'The seconds brought forward are kept for the last retry.' );
			$this->assertLessThanOrEqual( time() + 10 * MINUTE_IN_SECONDS - $due, $stored['_wcpay_retry_brought_forward'] ?? null );
			return;
		}
		$this->assertGreaterThanOrEqual( $before + $delay, $due );
		$this->assertLessThanOrEqual( time() + $delay, $due );
		$this->assertArrayNotHasKey( '_wcpay_retry_brought_forward', $stored );
	}

	/** @return array<string,array{string,int,?int,bool,int}> */
	public static function held_up_retry_cases(): array {
		return array(
			'success, live holder at the one-minute retry' => array( 'payment_intent.succeeded', 1, 20, false, 10 * MINUTE_IN_SECONDS ),
			'success, holder a minute and a half old'      => array( 'payment_intent.succeeded', 1, 90, true, 0 ),
			'authorization, holder a minute and a half old' => array( 'payment_intent.amount_capturable_updated', 1, 90, true, 0 ),
			'success, holder past its lock expiry and margin' => array( 'payment_intent.succeeded', 1, 330, false, 10 * MINUTE_IN_SECONDS ),
			'success, holder inside the margin after its expiry' => array( 'payment_intent.succeeded', 1, 305, true, 0 ),
			'success, first job, old holder'               => array( 'payment_intent.succeeded', 0, 200, false, MINUTE_IN_SECONDS ),
			'success, first job, holder near its expiry'   => array( 'payment_intent.succeeded', 0, 280, false, MINUTE_IN_SECONDS ),
			'success, holder record not describing the lock' => array( 'payment_intent.succeeded', 1, null, false, 10 * MINUTE_IN_SECONDS ),
			'refund, holder a minute and a half old'       => array( 'charge.refunded', 1, 90, false, 10 * MINUTE_IN_SECONDS ),
			'dispute, holder a minute and a half old'      => array( 'charge.dispute.created', 1, 90, false, 10 * MINUTE_IN_SECONDS ),
			'failed payment, holder a minute and a half old' => array( 'payment_intent.payment_failed', 1, 90, false, 10 * MINUTE_IN_SECONDS ),
		);
	}

	/**
	 * @testdox The last retry of a success brought forward to its holder's expiry is due when it would have been without that.
	 */
	public function test_last_retry_of_a_held_up_success_adds_the_seconds_brought_forward(): void {
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( new OrderPaymentLockRefusedException( 1, 'payment status update', time() - 90 ) ) );
		$store->set_event(
			'evt_process',
			$event + array(
				WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 2,
				'_wcpay_retry_brought_forward' => 380,
			)
		);

		$before = time();
		try {
			$service->process_event( 'evt_process' );
			$this->fail( 'The refusal must reach Action Scheduler.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			unset( $exception );
		}

		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertGreaterThanOrEqual( $before + HOUR_IN_SECONDS + 380, $scheduler->scheduled_jobs[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS + 380, $scheduler->scheduled_jobs[0]['timestamp'] );
		$this->assertSame( $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3 ), $store->get_event( 'evt_process' ) );
	}

	/**
	 * @testdox A success retried at its holder's expiry reaches the ingestor as delivered, without the retry count or the seconds brought forward.
	 */
	public function test_success_retried_at_the_holder_expiry_reaches_the_ingestor_as_delivered(): void {
		$store    = new QueuedEventRows();
		$event    = array(
			'id'   => 'evt_retried',
			'type' => 'payment_intent.succeeded',
		);
		$ingestor = new class( new OrderPaymentLockRefusedException( 1, 'payment status update', time() - 90 ) ) extends EventIngestor {
			/**
			 * Events the ingestor received.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $processed_events = array();

			/**
			 * Refusal of the first event, then none.
			 *
			 * @var \Throwable|null
			 */
			private ?\Throwable $refusal;

			/**
			 * Constructor.
			 *
			 * @param \Throwable $refusal Refusal of the first event.
			 */
			public function __construct( \Throwable $refusal ) {
				$this->refusal = $refusal;
			}

			/**
			 * Record the event, refusing the first.
			 *
			 * @param array<string,mixed> $event Event payload.
			 * @throws \Throwable The refusal, for the first event.
			 */
			public function process( array $event ): void {
				$this->processed_events[] = $event;
				$refusal                  = $this->refusal;
				$this->refusal            = null;
				if ( null !== $refusal ) {
					throw $refusal;
				}
			}
		};
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );
		$store->set_event( 'evt_retried', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 1 ) );
		try {
			$service->process_event( 'evt_retried' );
			$this->fail( 'The one-minute retry is refused.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			unset( $exception );
		}
		$this->assertArrayHasKey( '_wcpay_retry_brought_forward', $store->get_event( 'evt_retried' ) );

		$service->process_event( 'evt_retried' );

		$this->assertSame( array( $event, $event ), $ingestor->processed_events );
		$this->assertNull( $store->get_event( 'evt_retried' ) );
	}

	/**
	 * @testdox A success refused at its last attempt logs the refusal as a notice, then the drop's error line and its order note.
	 */
	public function test_refusal_at_the_last_attempt_is_a_notice_followed_by_the_drop_error(): void {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->save();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$token      = $lock->claim( $order, $vocabulary, 'pi_last_holder', 'checkout' );
		$this->assertNotNull( $token );
		$event   = array(
			'id'   => 'evt_last_attempt',
			'type' => 'payment_intent.succeeded',
			'data' => array(
				'object' => array(
					'id'             => 'pi_last_attempt',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'amount'         => 1000,
					'payment_method' => 'pm_last_attempt',
					'metadata'       => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => $order->get_order_key(),
					),
				),
			),
		);
		$store   = new QueuedEventRows();
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), wc_get_container()->get( EventIngestor::class ) );
		$store->set_event( 'evt_last_attempt', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3 ) );
		$logger = RecordingWcLogger::install();

		try {
			$service->process_event( 'evt_last_attempt' );
			$this->fail( 'The last attempt is refused too.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			unset( $exception );
		} finally {
			$lock->release( $order, $vocabulary, (string) $token );
		}

		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => in_array( $line[0], array( 'notice', 'warning', 'error' ), true ) ) );
		$this->assertCount( 2, $lines );
		$this->assertSame( 'notice', $lines[0][0] );
		$this->assertStringStartsWith( sprintf( 'order payment lock refused: order %d, refused payment status update', $order->get_id() ), $lines[0][1] );
		$this->assertSame( 'error', $lines[1][0] );
		$this->assertStringContainsString( 'evt_last_attempt (payment_intent.succeeded) could not be processed after 3 retries and was dropped', $lines[1][1] );
		$this->assertCount(
			1,
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( $note->content, 'event evt_last_attempt' )
			)
		);
		$this->assertNull( $store->get_event( 'evt_last_attempt' ) );
	}

	/**
	 * @testdox A payment_intent.succeeded held up by a dead checkout's lock is retried at the lock's expiry, after its one-minute retry is refused, then marks the order paid once.
	 */
	public function test_lock_refused_succeeded_event_of_a_dead_holder_is_retried_at_its_expiry_and_then_applies(): void {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->save();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		// A checkout that died after the platform captured holds the lock; its claim was 90 seconds ago.
		$this->assertNotNull( wc_get_container()->get( OrderPaymentLock::class )->claim( $order, $vocabulary, 'pi_dead_holder', 'checkout' ) );
		$claimed_at = time() - 90;
		$this->set_order_payment_lock_claim_time( $order, $vocabulary, $claimed_at );
		$event     = array(
			'id'   => 'evt_dead_holder',
			'type' => 'payment_intent.succeeded',
			'data' => array(
				'object' => array(
					'id'             => 'pi_dead_holder',
					'status'         => 'succeeded',
					'currency'       => 'usd',
					'amount'         => 1000,
					'payment_method' => 'pm_dead_holder',
					'metadata'       => array(
						'order_id'  => (string) $order->get_id(),
						'order_key' => $order->get_order_key(),
					),
					'charges'        => array(
						'data' => array(
							array(
								'id'             => 'ch_dead_holder',
								'payment_method' => 'pm_dead_holder',
							),
						),
					),
				),
			),
		);
		$ingestor  = wc_get_container()->get( EventIngestor::class );
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), $ingestor );

		try {
			try {
				$ingestor->process( $event );
				$this->fail( 'A delivery refused by the lock must fail instead of being acknowledged.' );
			} catch ( OrderPaymentLockRefusedException $refusal ) {
				$this->assertSame( $claimed_at, $refusal->get_holder_claimed_at(), 'The refusal carries when the holder claimed the lock.' );
				$service->retry_failed_event( $event, $refusal );
			}

			$due = array();
			foreach ( array( 'first job', 'one-minute retry' ) as $attempt ) {
				$scheduler->scheduled_jobs = array();
				$before                    = time();
				try {
					$service->process_event( 'evt_dead_holder' );
					$this->fail( "The $attempt must be refused while the holder's lock lives." );
				} catch ( OrderPaymentLockRefusedException $exception ) {
					unset( $exception );
				}
				$this->assertCount( 1, $scheduler->scheduled_jobs );
				$due[] = $scheduler->scheduled_jobs[0]['timestamp'] - $before;
			}
			$this->assertContains( $due[0], array( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS + 1 ), 'The first job of a refused success is retried a minute later.' );
			$this->assertSame( $claimed_at + WooPaymentsPersistenceVocabulary::LOCK_TTL_SECONDS + 10, $scheduler->scheduled_jobs[0]['timestamp'], 'The refused one-minute retry is retried just after the holder\'s lock expires.' );
		} finally {
			// The holder's lock has expired by the time that retry is due.
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$scheduler->scheduled_jobs = array();
		$service->process_event( 'evt_dead_holder' );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->needs_payment(), 'The retry after the lock expired must record the payment.' );
		$this->assertSame( 'pi_dead_holder', $order->get_transaction_id() );
		$charge_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'successfully charged' )
		);
		$this->assertCount( 1, $charge_notes, 'The payment is recorded once.' );
		$this->assertNull( $store->get_event( 'evt_dead_holder' ) );
		$this->assertSame( array(), $scheduler->scheduled_jobs );
	}

	/**
	 * @testdox A $event_type delivery refused by the order payment lock is kept and retried, although the type gets one attempt after other failures.
	 * @dataProvider event_types_retried_after_a_lock_refusal
	 *
	 * @param string $event_type Event type safe to apply late, retried only after a lock refusal.
	 */
	public function test_lock_refusal_is_retried_for_an_event_type_safe_to_apply_late( string $event_type ): void {
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => $event_type,
		);
		$refusal   = new OrderPaymentLockRefusedException( 123, 'refund webhook' );
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( $refusal ) );

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

		$this->assertSame( $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 1 ), $store->get_event( 'evt_process' ) );
		$this->assertCount( 1, $scheduler->scheduled_jobs );
		$this->assertGreaterThanOrEqual( $before + MINUTE_IN_SECONDS, $scheduler->scheduled_jobs[0]['timestamp'] );
	}

	/** @return array<string,array{string}> */
	public static function event_types_retried_after_a_lock_refusal(): array {
		return array(
			'fraud warning created' => array( 'radar.early_fraud_warning.created' ),
			'fraud warning updated' => array( 'radar.early_fraud_warning.updated' ),
			'capture expiry'        => array( 'charge.expired' ),
			'funds withdrawn'       => array( 'charge.dispute.funds_withdrawn' ),
			'funds reinstated'      => array( 'charge.dispute.funds_reinstated' ),
		);
	}

	/**
	 * @testdox A failure writing the lock-refusal note through $hook on the last attempt leaves the refusal for Action Scheduler and drops the event.
	 * @testWith ["woocommerce_new_order_note_data"]
	 *           ["woocommerce_order_note_added"]
	 *
	 * @param string $hook Order note hook a third-party callback throws from.
	 */
	public function test_failing_lock_refusal_note_on_the_last_attempt_keeps_the_refusal( string $hook ): void {
		$order    = wc_create_order();
		$store    = new QueuedEventRows();
		$event    = array(
			'id'   => 'evt_lock_note_failure',
			'type' => 'charge.dispute.funds_withdrawn',
		);
		$refusal  = new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' );
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), new ThrowingEventIngestor( $refusal ) );
		$throwing = static function () {
			throw new \RuntimeException( 'Order note extension failure.' );
		};
		$store->set_event( 'evt_lock_note_failure', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => count( WebhookReliabilityService::RETRY_DELAYS_SECONDS ) ) );
		add_filter( $hook, $throwing );

		$thrown = null;
		try {
			$service->process_event( 'evt_lock_note_failure' );
		} catch ( \Throwable $exception ) {
			$thrown = $exception;
		} finally {
			remove_filter( $hook, $throwing );
		}

		$this->assertSame( $refusal, $thrown );
		$this->assertNull( $store->get_event( 'evt_lock_note_failure' ) );
	}

	/**
	 * @testdox An event the order payment lock refused until its retries ran out, delivered and exhausted again, leaves one note on its order.
	 *
	 * A second cycle needs a second delivery of the same event, such as a push the platform also listed as failed because
	 * its own request timed out while the store answered.
	 */
	public function test_lock_refusal_exhausted_twice_notes_its_order_once(): void {
		$order   = wc_create_order();
		$store   = new QueuedEventRows();
		$event   = array(
			'id'   => 'evt_lock_exhausted_twice',
			'type' => 'charge.dispute.funds_withdrawn',
		);
		$refusal = new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' );
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), new ThrowingEventIngestor( $refusal ) );

		for ( $cycle = 1; $cycle <= 2; $cycle++ ) {
			$store->set_event( 'evt_lock_exhausted_twice', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => count( WebhookReliabilityService::RETRY_DELAYS_SECONDS ) ) );
			try {
				$service->process_event( 'evt_lock_exhausted_twice' );
			} catch ( OrderPaymentLockRefusedException $exception ) {
				unset( $exception );
			}
		}

		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'evt_lock_exhausted_twice' ) && false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertCount( 1, $notes );
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
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		// Page shape of the platform's failed-events list (wpcom class-webhook-controller.php:346-357: object 'list', data,
		// has_more). The event is reduced to id and type, all the reliability service reads; the ingestor is a stub.
		$provider = new StaticFailedEventsApiClient(
			array(
				'data'     => 'pull' === $path ? array( $event ) : array(),
				'has_more' => false,
			)
		);
		$service  = $this->create_service( $scheduler, $provider, new ThrowingEventIngestor( $refusal ) );
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
			$attempts = 1 + count( WebhookReliabilityService::RETRY_DELAYS_SECONDS );
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
		$retries = array_filter( $scheduler->jobs_for( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ), static fn( array $job ): bool => isset( $job['timestamp'] ) );
		$this->assertCount( count( WebhookReliabilityService::RETRY_DELAYS_SECONDS ), $retries, 'No attempt is scheduled after the last one.' );
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

	/**
	 * @testdox An event the lock refused on every attempt still leaves its order note when the drop line's logger throws.
	 */
	public function test_lock_refusal_note_survives_a_throwing_drop_line(): void {
		$order     = wc_create_order();
		$event_id  = 'evt_lock_exhausted_throwing_log';
		$event     = array(
			'id'   => $event_id,
			'type' => 'payment_intent.succeeded',
		);
		$refusal   = new OrderPaymentLockRefusedException( $order->get_id(), 'payment webhook' );
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		// Empty page of the platform's failed-events list (wpcom class-webhook-controller.php:346-357); unused here.
		$provider       = new StaticFailedEventsApiClient(
			array(
				'data'     => array(),
				'has_more' => false,
			)
		);
		$service        = $this->create_service( $scheduler, $provider, new ThrowingEventIngestor( $refusal ) );
		$logger_failure = new \RuntimeException( 'Log handler failed.' );
		$logger         = function ( $message ) use ( $logger_failure ) {
			if ( false !== strpos( (string) $message, 'was dropped' ) ) {
				throw $logger_failure;
			}
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $logger );

		$last_failure = null;
		try {
			$service->retry_failed_event( $event, $refusal );
			$attempts = 1 + count( WebhookReliabilityService::RETRY_DELAYS_SECONDS );
			for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
				try {
					$service->process_event( $event_id );
				} catch ( \Throwable $exception ) {
					$last_failure = $exception;
				}
			}
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $logger );
		}

		$this->assertSame( $logger_failure, $last_failure, 'The last attempt reached the throwing drop line.' );
		$this->assertNull( $store->get_event( $event_id ) );
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, $event_id ) && false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertCount( 1, $notes, 'The merchant still sees that the update did not reach the order.' );
	}

	/** @return array<string,array{string}> */
	public static function delivery_paths(): array {
		return array(
			'webhook' => array( 'webhook' ),
			'pull'    => array( 'pull' ),
		);
	}

	/**
	 * @testdox An event out of retries is dropped with nothing more scheduled, and its line carries the platform error's status and code, never its message.
	 */
	public function test_dropped_event_log_leaves_out_platform_text(): void {
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( self::make_provider_error() ) );
		$store->set_event(
			'evt_exhausted',
			array(
				'id'   => 'evt_exhausted',
				'type' => 'payment_intent.succeeded',
				WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
			)
		);
		$logger = RecordingWcLogger::install();

		try {
			$service->process_event( 'evt_exhausted' );
			$this->fail( 'The last failure must still reach Action Scheduler.' );
		} catch ( ApiException $exception ) {
			// Only the platform error the ingestor threw: PHPUnit's own failure is a RuntimeException too.
			unset( $exception );
		}

		$this->assertNull( $store->get_event( 'evt_exhausted' ), 'An event out of retries is dropped.' );
		$this->assertSame( array(), $scheduler->scheduled_jobs, 'An event out of retries gets no further attempt.' );
		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		// The event, its type and the retry count are only on the line itself.
		$dropped = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'error' === $line[0] && 'woopayments' === $line[2] ) )[0][1];
		foreach ( array( 'evt_exhausted', 'payment_intent.succeeded', '3 retries' ) as $fact ) {
			$this->assertStringContainsString( $fact, $dropped );
		}
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A refusal that wraps a platform error is logged by the platform's codes, without the refusal's message.
	 *
	 * The Stripe Billing handler passes on its module's refusal (StripeBillingEventHandler::handle_event()), which can carry
	 * the platform's text; every other refusal's reason is a constant and stays on the line.
	 */
	public function test_refusal_wrapping_a_platform_error_leaves_out_its_text(): void {
		$store   = new QueuedEventRows();
		$service = $this->create_service(
			new RecordingActionSchedulerService(),
			new StaticFailedEventsApiClient(),
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

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( \InvalidArgumentException::class, 404, 'resource_missing' ), array( $context['exception'], $context['http_status'], $context['error_code'] ) );
		$this->assertStringContainsString( 'evt_process', (string) wp_json_encode( array_column( $logger->lines, 1 ) ), 'The line names the refused event.' );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A $event_type delivery that failed ($failure) is kept and its retry scheduled, since the platform never sends an event the store answered with an error again.
	 *
	 * A late refund or dispute event cannot undo a newer one, so a lock refusal is retried like any passing failure, and
	 * the order gets a note only when the retries run out.
	 *
	 * @dataProvider refund_and_dispute_failures
	 *
	 * @param string $event_type Refund or dispute event type.
	 * @param string $failure    How the delivery failed: 'passing failure' or 'lock refusal'.
	 */
	public function test_failed_refund_or_dispute_delivery_is_kept_and_retried( string $event_type, string $failure ): void {
		$order     = wc_create_order();
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_money',
			'type' => $event_type,
		);
		$exception = 'lock refusal' === $failure ? new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' ) : new \RuntimeException( 'transient boom' );
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( $exception ) );

		$service->retry_failed_event( $event, $exception );

		$this->assertSame( $event, $store->get_event( 'evt_money' ) );
		$jobs = $scheduler->jobs_for( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION );
		$this->assertCount( 1, $jobs );
		$this->assertSame( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, $jobs[0]['hook'] );
		$this->assertSame( array( 'event_id' => 'evt_money' ), $jobs[0]['args'] );
		$lock_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertSame( array(), $lock_notes, 'Nothing is noted while a retry is due.' );
	}

	/** @return array<string,array{string,string}> */
	public static function refund_and_dispute_failures(): array {
		$rows = array();
		foreach ( array( 'charge.refunded', 'charge.refund.updated', 'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' ) as $event_type ) {
			foreach ( array( 'passing failure', 'lock refusal' ) as $failure ) {
				$rows[ "$event_type, $failure" ] = array( $event_type, $failure );
			}
		}

		return $rows;
	}

	/**
	 * @testdox An event type off the retry list that fails gets one attempt, as with the WooPayments extension: no retry is scheduled, and its stored copy is removed.
	 */
	public function test_process_event_does_not_retry_an_event_type_off_the_list(): void {
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_refund',
			'type' => 'charge.expired',
		);
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( new \RuntimeException( 'transient boom' ) ) );
		$store->set_event( 'evt_refund', $event );
		$store->set_transient_event( 'evt_refund', $event );

		try {
			$service->process_event( 'evt_refund' );
			$this->fail( 'The failure must still reach Action Scheduler.' );
		} catch ( \RuntimeException $exception ) {
			// The ingestor's failure, not PHPUnit's own: AssertionFailedError is a RuntimeException too.
			$this->assertSame( 'transient boom', $exception->getMessage() );
		}

		$this->assertSame( array(), $scheduler->scheduled_jobs );
		$this->assertNull( $store->get_event( 'evt_refund' ), 'The row is removed, as the extension removes its copy.' );
		$this->assertNull( $store->get_transient_event( 'evt_refund' ), 'The extension-name copy is removed too.' );
	}

	/**
	 * @testdox A retried event reaches the ingestor without the retry count and is removed once processed.
	 */
	public function test_retried_event_is_processed_without_the_retry_count(): void {
		$store    = new QueuedEventRows();
		$ingestor = new RecordingEventIngestor();
		$event    = array(
			'id'   => 'evt_retried',
			'type' => 'payment_intent.succeeded',
		);
		$service  = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );
		$store->set_event( 'evt_retried', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 2 ) );

		$service->process_event( 'evt_retried' );

		$this->assertSame( array( $event ), $ingestor->processed_events );
		$this->assertNull( $store->get_event( 'evt_retried' ) );
	}

	/**
	 * @testdox A refused event is dropped without a retry, one error line names it and the job completes, as with the WooPayments extension.
	 *
	 * Client 11.1.0 `class-wc-payments-webhook-reliability-service.php:136-149`: the stored event is deleted, and an
	 * `Invalid_Webhook_Data_Exception` is logged as "Failed processing event {id}. Reason: {message}" and not re-thrown.
	 */
	public function test_process_event_drops_and_logs_a_refused_event_without_failing_the_job(): void {
		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service(
			$scheduler,
			new StaticFailedEventsApiClient(),
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
		$this->assertSame( array(), $scheduler->scheduled_jobs, 'A refused event gets no retry.' );
		$this->assertSame(
			array( array( 'error', 'Failed processing event evt_process. Reason: malformed event', 'woopayments' ) ),
			$logger->get_errors()
		);
	}

	/**
	 * @testdox Without the Stripe Billing module, a stored invoice event is refused with exactly one error line, from the job.
	 */
	public function test_invoice_event_refused_without_the_stripe_billing_module_logs_one_line(): void {
		$module = $this->getMockBuilder( StripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( false );
		$module->expects( $this->never() )->method( 'handle_invoice_event' );
		wc_get_container()->replace( StripeBillingModule::class, $module );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$store     = new QueuedEventRows();
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), wc_get_container()->get( EventIngestor::class ) );
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
			array( array( 'error', 'Failed processing event evt_process. Reason: Cannot find subscription for the incoming "invoice.paid" event.', 'woopayments' ) ),
			$logger->get_errors()
		);
	}

	/**
	 * @testdox Queuing an event schedules one sweep a day and an hour later, and storing it again for its retry schedules none.
	 */
	public function test_queuing_an_event_schedules_one_sweep_and_a_retry_schedules_none(): void {
		$scheduler = new RecordingActionSchedulerService();
		$event     = array(
			'id'   => 'evt_process',
			'type' => 'payment_intent.succeeded',
		);
		$service   = $this->create_service( $scheduler, new StaticFailedEventsApiClient(), new ThrowingEventIngestor( new \RuntimeException( 'transient boom' ) ) );

		$before = time();
		$service->retry_failed_event( $event, new \RuntimeException( 'transient boom' ) );
		$after  = time();
		$sweeps = $scheduler->jobs_for( WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION );
		$this->assertCount( 1, $sweeps );
		$this->assertSame( array(), $sweeps[0]['args'] );
		$this->assertGreaterThanOrEqual( $before + DAY_IN_SECONDS + HOUR_IN_SECONDS, $sweeps[0]['timestamp'] );
		$this->assertLessThanOrEqual( $after + DAY_IN_SECONDS + HOUR_IN_SECONDS, $sweeps[0]['timestamp'] );

		try {
			$service->process_event( 'evt_process' );
			$this->fail( 'The failure must still reach Action Scheduler.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'transient boom', $exception->getMessage() );
		}

		$this->assertCount( 2, $scheduler->jobs_for( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ), 'The job and its retry.' );
		$this->assertCount( 1, $scheduler->jobs_for( WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION ), 'The retry\'s store schedules no second sweep.' );
	}

	/**
	 * @testdox The sweep queues again, as one more retry, an event last stored over a day ago whose job is gone, and keeps a younger one and one whose job is pending: $type.
	 * @testWith ["payment_intent.succeeded"]
	 *           ["charge.expired"]
	 *
	 * @param string $type Event type; `charge.expired` is retried only after a lock refusal, not after any passing failure.
	 */
	public function test_sweep_queues_again_a_queued_event_whose_job_is_gone_and_counts_it( string $type ): void {
		$store   = new QueuedEventRows();
		$service = $this->create_service( wc_get_container()->get( WooPaymentsActionSchedulerService::class ), new StaticFailedEventsApiClient(), new RecordingEventIngestor() );
		$old     = time() - 2 * DAY_IN_SECONDS;
		$events  = array(
			'evt_sweep_lost'    => array( $old, 1 ),
			'evt_sweep_pending' => array( $old, 1 ),
			'evt_sweep_young'   => array( time() - HOUR_IN_SECONDS, 0 ),
		);
		foreach ( $events as $event_id => list( $stored_at, $attempts ) ) {
			$store->set_event(
				$event_id,
				array(
					'id'   => $event_id,
					'type' => 'evt_sweep_lost' === $event_id ? $type : 'payment_intent.succeeded',
					WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $attempts,
				),
				$stored_at
			);
		}
		as_schedule_single_action( time() + HOUR_IN_SECONDS, WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => 'evt_sweep_pending' ), WooPaymentsActionSchedulerService::GROUP_ID );
		$kept   = array(
			'evt_sweep_pending' => $store->get_row( 'evt_sweep_pending' ),
			'evt_sweep_young'   => $store->get_row( 'evt_sweep_young' ),
		);
		$logger = RecordingWcLogger::install();

		$before = time();
		$service->sweep_queued_events();

		$lost = $store->get_row( 'evt_sweep_lost' );
		$this->assertSame( 2, $lost['value']['event'][ WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY ] ?? null, 'Queuing again counts as a retry.' );
		$this->assertGreaterThanOrEqual( $before, $lost['value']['stored_at'] ?? 0, 'The row is written again.' );
		// No copy was seeded, so this is the one the sweep wrote; a return to the extension before the job runs reads it.
		$this->assertSame( 2, ( $store->get_transient_event( 'evt_sweep_lost' ) ?? array() )[ WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY ] ?? null, 'The extension\'s copy holds the raised count.' );
		$this->assertSame( 1, $this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => 'evt_sweep_lost' ) ) );
		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( "evt_sweep_lost ($type) had no job left to process it; it is queued again (retry 2 of 3)", $warnings[0][1] );
		$this->assertSame( array(), $logger->get_errors() );
		foreach ( $kept as $event_id => $row ) {
			$this->assertSame( $row, $store->get_row( $event_id ), "$event_id is kept as it was." );
		}
		$this->assertSame( 1, $this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => 'evt_sweep_pending' ) ) );
		$this->assertSame( 0, $this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => 'evt_sweep_young' ) ) );
		$this->assertSame( 1, $this->count_pending_reliability_actions( WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION ), 'The sweep runs again while queued events remain.' );
	}

	/**
	 * @testdox The sweep drops, with one error line and no job, a queued event whose $scenario.
	 * @testWith ["retries are used up"]
	 *           ["row cannot be read"]
	 *           ["type is tried only once"]
	 *
	 * @param string $scenario Which drop.
	 */
	public function test_sweep_drops_a_queued_event_whose_attempts_are_used_up_or_that_cannot_be_read( string $scenario ): void {
		global $wpdb;
		$store    = new QueuedEventRows();
		$service  = $this->create_service( wc_get_container()->get( WooPaymentsActionSchedulerService::class ), new StaticFailedEventsApiClient(), new RecordingEventIngestor() );
		$messages = array(
			'retries are used up'     => 'evt_sweep_drop (charge.refunded) was dropped after 3 retries: no job will process it again.',
			'row cannot be read'      => QueuedEventRows::row_name( 'evt_sweep_drop' ) . ' could not be read and was dropped',
			// A type the store runs once, which reaches a row only through the missed-event fetch.
			'type is tried only once' => 'evt_sweep_drop (invoice.payment_failed) was dropped after 0 retries: no job will process it again.',
		);
		if ( 'row cannot be read' !== $scenario ) {
			$used_up = 'retries are used up' === $scenario;
			$event   = array(
				'id'   => 'evt_sweep_drop',
				'type' => $used_up ? 'charge.refunded' : 'invoice.payment_failed',
				WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $used_up ? count( WebhookReliabilityService::RETRY_DELAYS_SECONDS ) : 0,
			);
			$store->set_event( 'evt_sweep_drop', $event, time() - 2 * DAY_IN_SECONDS );
			$store->set_transient_event( 'evt_sweep_drop', $event );
		} else {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => QueuedEventRows::row_name( 'evt_sweep_drop' ),
					'option_value' => 'not-an-event',
					'autoload'     => 'off',
				)
			);
		}
		$logger = RecordingWcLogger::install();

		$service->sweep_queued_events();

		$this->assertNull( $store->get_row( 'evt_sweep_drop' ) );
		$this->assertNull( $store->get_transient_event( 'evt_sweep_drop' ) );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( $messages[ $scenario ], $errors[0][1] );
		$this->assertSame( 'woopayments', $errors[0][2] );
		$this->assertSame( 0, $this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ) );
		$this->assertSame( 0, $this->count_pending_reliability_actions( WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION ), 'With no queued event left, no sweep is scheduled.' );
	}

	/**
	 * @testdox The sweep schedules no job for a queued event when $scenario, and does not bring back a row it did not write.
	 * @testWith ["its row is deleted just before the write"]
	 *           ["its row is stored again just before the write"]
	 *           ["its write fails"]
	 *           ["its scan fails"]
	 *
	 * @param string $scenario What happens around the sweep's read or write.
	 */
	public function test_sweep_schedules_no_job_when_its_write_or_its_scan_does_not_land( string $scenario ): void {
		global $wpdb;
		$store   = new QueuedEventRows();
		$service = $this->create_service( wc_get_container()->get( WooPaymentsActionSchedulerService::class ), new StaticFailedEventsApiClient(), new RecordingEventIngestor() );
		$event   = array(
			'id'   => 'evt_sweep_race',
			'type' => 'payment_intent.succeeded',
			WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 1,
		);
		$store->set_event( 'evt_sweep_race', $event, time() - 2 * DAY_IN_SECONDS );
		$store->set_transient_event( 'evt_sweep_race', $event );
		$row      = $store->get_row( 'evt_sweep_race' );
		$deleted  = 'its row is deleted just before the write' === $scenario;
		$stored   = 'its row is stored again just before the write' === $scenario;
		$hit      = false;
		$fresh    = null;
		$filter   = function ( $query ) use ( &$filter, &$hit, &$fresh, $scenario, $deleted, $stored, $store ) {
			$sql   = ltrim( (string) $query );
			$scan  = 'its scan fails' === $scenario && 0 === strpos( $sql, 'SELECT option_name, option_value FROM' ) && false !== strpos( $sql, ' LIKE ' );
			$write = 'its scan fails' !== $scenario && false !== strpos( $sql, QueuedEventRows::row_name( 'evt_sweep_race' ) ) && ( 0 === strpos( $sql, 'UPDATE' ) || 0 === strpos( $sql, 'INSERT' ) );
			if ( ! $scan && ! $write ) {
				return $query;
			}
			$hit = true;
			remove_filter( 'query', $filter );
			if ( $deleted ) {
				// A job applied the event and ended it between the sweep's read and its write.
				$store->delete_event( 'evt_sweep_race' );
				delete_transient( QueuedEventRows::name( 'evt_sweep_race' ) );
				return $query;
			}
			if ( $stored ) {
				// A job ended in a retry and stored the event again, with its count raised, between the sweep's read and its write.
				$store->set_event(
					'evt_sweep_race',
					array(
						'id'   => 'evt_sweep_race',
						'type' => 'payment_intent.succeeded',
						WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 2,
					),
					time() - 100 // Never the second the sweep writes in, so its write would change the row.
				);
				$fresh = $store->get_row( 'evt_sweep_race' );
				return $query;
			}
			return $scan ? 'SELECT a FROM a_table_that_does_not_exist' : 'UPDATE a_table_that_does_not_exist SET a = 1';
		};
		$logger   = RecordingWcLogger::install();
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$service->sweep_queued_events();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertTrue( $hit, 'The query must have been reached (' . $scenario . ').' );
		$this->assertSame( 0, $this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ) );
		$this->assertSame( array(), array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ) );
		if ( $deleted ) {
			$this->assertNull( $store->get_row( 'evt_sweep_race' ), 'The row a job ended stays gone.' );
			$this->assertNull( $store->get_transient_event( 'evt_sweep_race' ), 'Its extension-name copy stays gone too.' );
			return;
		}
		$this->assertSame( $fresh ?? $row, $store->get_row( 'evt_sweep_race' ), 'The row is kept as it was when the write came, not written over.' );
		$this->assertSame( $event, $store->get_transient_event( 'evt_sweep_race' ), 'Its extension-name copy is kept as it was.' );
		$this->assertSame( 1, $this->count_pending_reliability_actions( WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION ), 'The sweep runs again, so the event is not left without one.' );
	}

	/**
	 * @testdox An event whose job dies hard after every queue again is queued again three times, then dropped.
	 */
	public function test_a_job_that_dies_hard_after_each_queue_again_is_dropped_after_its_attempts(): void {
		$store   = new QueuedEventRows();
		$service = $this->create_service( wc_get_container()->get( WooPaymentsActionSchedulerService::class ), new StaticFailedEventsApiClient(), new RecordingEventIngestor() );
		$store->set_event(
			'evt_sweep_dies',
			array(
				'id'   => 'evt_sweep_dies',
				'type' => 'payment_intent.succeeded',
			),
			time() - 2 * DAY_IN_SECONDS
		);
		$logger = RecordingWcLogger::install();

		for ( $sweep = 1; $sweep <= 4; $sweep++ ) {
			$service->sweep_queued_events();
			$this->assertSame( $sweep <= 3 ? 1 : 0, $this->count_pending_reliability_actions( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION, array( 'event_id' => 'evt_sweep_dies' ) ), "Sweep $sweep: a job is pending for each queue again." );
			$this->assertSame( $sweep <= 3 ? 1 : 0, $this->count_pending_reliability_actions( WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION ), "Sweep $sweep: the sweep runs again while the event is queued." );
			// The hard death: the job the sweep scheduled never runs process_event().
			$this->unschedule_reliability_actions();
			$event = $store->get_event( 'evt_sweep_dies' );
			if ( null !== $event ) {
				$store->set_event( 'evt_sweep_dies', $event, time() - 2 * DAY_IN_SECONDS );
			}
		}

		$requeued = array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && false !== strpos( $line[1], 'evt_sweep_dies' ) && false !== strpos( $line[1], 'queued again' ) );
		$this->assertCount( 3, $requeued );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'evt_sweep_dies (payment_intent.succeeded) was dropped after 3 retries', $errors[0][1] );
		$this->assertNull( $store->get_row( 'evt_sweep_dies' ) );
	}

	/**
	 * Remove reliability hooks for this SUT.
	 */
	private function remove_reliability_hooks(): void {
		remove_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'maybe_schedule_fetch_events' ) );
		remove_action( 'wcpay_webhook_fetch_events', array( $this->sut, 'fetch_events_and_schedule_processing_jobs' ) );
		remove_action( 'wcpay_webhook_process_event', array( $this->sut, 'process_event' ) );
		remove_action( 'woocommerce_woopayments_sweep_queued_events', array( $this->sut, 'sweep_queued_events' ) );
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
				WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION,
				WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
				WebhookReliabilityService::QUEUED_EVENTS_SWEEP_ACTION,
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
	 * Set when the order payment lock's holder claimed it, as a holder whose request died that long ago left it.
	 *
	 * @param \WC_Order                        $order      Order whose lock a claim holds.
	 * @param WooPaymentsPersistenceVocabulary $vocabulary Persistence vocabulary.
	 * @param int                              $claimed_at Claim time to record.
	 */
	private function set_order_payment_lock_claim_time( \WC_Order $order, WooPaymentsPersistenceVocabulary $vocabulary, int $claimed_at ): void {
		global $wpdb;

		$holder_key           = $vocabulary->get_order_lock_key( $order ) . '_holder';
		$envelope             = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $holder_key ) ) );
		$holder               = maybe_unserialize( $envelope['record'] );
		$holder['claimed_at'] = $claimed_at;
		$envelope['record']   = maybe_serialize( $holder );
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $envelope ) ), array( 'option_name' => '_transient_' . $holder_key ) );
	}

	/**
	 * Create a reliability service with supplied collaborators.
	 *
	 * @param WooPaymentsActionSchedulerService $scheduler  Scheduler service.
	 * @param Client                            $api_client API client that answers the failed-events fetch.
	 * @param EventIngestor                     $ingestor   Event ingestor.
	 * @return WebhookReliabilityService
	 */
	private function create_service(
		WooPaymentsActionSchedulerService $scheduler,
		Client $api_client,
		EventIngestor $ingestor
	): WebhookReliabilityService {
		$service = new WebhookReliabilityService();
		$service->init( $scheduler, $api_client, $ingestor );

		return $service;
	}

	/**
	 * A queued refund of this order's own charge.
	 *
	 * @param int    $minor    Payment amount.
	 * @param string $currency Payment currency.
	 * @return array
	 */
	private function refund_event( int $minor, string $currency = 'usd' ): array {
		$refund = array(
			'id'     => 're_order',
			'amount' => $minor,
			'status' => 'succeeded',
		);
		return array(
			'id'   => 'evt_refund',
			'type' => 'charge.refunded',
			'data' => array(
				'object' => array(
					'id'       => 'ch_order',
					'status'   => 'succeeded',
					'captured' => true,
					'amount'   => 2000,
					'currency' => $currency,
					'refunds'  => array( 'data' => array( $refund ) ),
				),
			),
		);
	}

	/** @return \WC_Order Order holding the event's charge. */
	private function refundable_order(): \WC_Order {
		$order = new \WC_Order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->set_status( OrderStatus::PROCESSING );
		$order->set_transaction_id( 'pi_order' );
		$order->update_meta_data( '_intent_id', 'pi_order' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->update_meta_data( '_charge_id', 'ch_order' );
		$order->save();
		return $order;
	}

	/** @return \WC_Order Order referenced by the queued update. */
	private function order_for_dropped_update(): \WC_Order {
		$order = new \WC_Order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->update_meta_data( '_intent_id', 'pi_order' );
		$order->update_meta_data( '_charge_id', 'ch_order' );
		$order->save();
		return $order;
	}

	/**
	 * @testdox A terminal drop identifies its update even after the note text changes.
	 * @testWith ["sweep", "payment_intent.succeeded", "pi_order"]
	 *           ["worker", "payment_intent.payment_failed", "pi_order"]
	 *           ["sweep", "payment_intent.amount_capturable_updated", "pi_order"]
	 *           ["sweep", "charge.expired", "ch_metadata"]
	 *           ["sweep", "charge.dispute.created", "dp_order"]
	 *           ["sweep", "charge.dispute.updated", "dp_order"]
	 *           ["sweep", "charge.dispute.closed", "dp_order"]
	 *           ["sweep", "charge.dispute.funds_reinstated", "dp_order"]
	 *           ["sweep", "charge.dispute.funds_withdrawn", "dp_order"]
	 *           ["sweep", "radar.early_fraud_warning.created", "issfr_order"]
	 *           ["sweep", "radar.early_fraud_warning.updated", "issfr_order"]
	 *           ["sweep", "charge.refund.updated", "re_order"]
	 * @param string $path      Worker or sweep.
	 * @param string $type      Event type.
	 * @param string $object_id Platform object ID.
	 */
	public function test_dropped_update_note( string $path, string $type, string $object_id ): void {
		$order  = $this->order_for_dropped_update();
		$object = array(
			'id'       => $object_id,
			'charge'   => 'ch_order',
			'amount'   => 400,
			'currency' => 'usd',
			'status'   => 'charge.refund.updated' === $type ? 'failed' : 'requires_capture',
		);
		if ( 'charge.expired' === $type ) {
			$object['metadata'] = array( 'order_id' => $order->get_id() );
		}
		$failure = new \RuntimeException( 'Processing failed.' );
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), new ThrowingEventIngestor( $failure ) );
		$store   = new QueuedEventRows();
		$key     = ( new WooPaymentsPersistenceVocabulary() )->get_note_identity_meta_key();
		foreach ( array( 'evt_drop', 'evt_drop', 'evt_next' ) as $delivery => $id ) {
			$event = array(
				'id'   => $id,
				'type' => $type,
				'data' => array( 'object' => $object ),
				WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
			);
			$store->set_event( $id, $event, time() - 2 * DAY_IN_SECONDS );
			if ( 'worker' === $path ) {
				try {
					$service->process_event( $id );
					$this->fail( 'Action Scheduler must receive the final failure.' );
				} catch ( \RuntimeException $exception ) {
					$this->assertSame( $failure, $exception );
				}
			} else {
				$service->sweep_queued_events();
			}
			$this->assertNull( $store->get_row( $id ) );
			$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$this->assertCount( 2 === $delivery ? 2 : 1, $notes );
			if ( 1 !== $delivery ) {
				$this->assertSame( "A WooPayments update for this order (event $id) could not be applied. Check the payment in your WooPayments dashboard.", $notes[0]->content );
				$this->assertSame( hash( 'sha256', 'woopayments_dropped_event:' . $id ), get_comment_meta( $notes[0]->id, $key, true ) );
				wp_update_comment(
					array(
						'comment_ID'      => $notes[0]->id,
						'comment_content' => 'Edited order note.',
					)
				);
			}
		}
	}

	/**
	 * @testdox A dropped update leaves existing lock and refund outcomes alone and ignores unrelated metadata.
	 * @testWith ["charge.dispute.updated", "woopayments_lock_refused_event:evt_drop"]
	 *           ["charge.refund.updated", "refund:re_order:failed"]
	 *           ["charge.refund.updated", "other_charge|charge.refund.updated|re_order|failed"]
	 *           ["payment_intent.canceled", ""]
	 *           ["account.updated", ""]
	 *           ["wcpay.notification", ""]
	 *           ["charge.dispute.closed", ""]
	 * @param string $type     Event type.
	 * @param string $identity Prior note identity, or empty when no order should match.
	 */
	public function test_dropped_update_keeps_existing_history( string $type, string $identity ): void {
		$order  = $this->order_for_dropped_update();
		$before = array();
		if ( '' !== $identity ) {
			$key = ( new WooPaymentsPersistenceVocabulary() )->get_note_identity_meta_key();
			$order->add_order_note( 'Existing outcome.', 0, false, array( $key => hash( 'sha256', $identity ) ) );
			$before[] = 'Existing outcome.';
		}
		$object = array(
			'id'       => 're_order',
			'charge'   => '' === $identity ? 'ch_other' : 'ch_order',
			'metadata' => array( 'order_id' => $order->get_id() ),
			'status'   => 'failed',
		);
		$event  = array(
			'id'   => 'evt_drop',
			'type' => $type,
			'data' => array( 'object' => $object ),
			WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
		);
		$store  = new QueuedEventRows();
		$store->set_event( 'evt_drop', $event, time() - 2 * DAY_IN_SECONDS );
		$this->sut->sweep_queued_events();
		$this->assertNull( $store->get_row( 'evt_drop' ) );
		$this->assertSame( $before, wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * @testdox A dropped invoice leaves a note only for a paid invoice with an existing renewal.
	 * @testWith ["invoice.paid"]
	 *           ["invoice.payment_failed"]
	 *           ["invoice.upcoming"]
	 *           ["invoice.upcoming", true, true]
	 *           ["invoice.paid", false]
	 * @param string $type   Invoice event type.
	 * @param bool   $loaded Whether the billing module loaded.
	 * @param bool   $worker Whether to exhaust a worker retry instead of sweeping the event.
	 */
	public function test_dropped_update_invoice( string $type, bool $loaded = true, bool $worker = false ): void {
		WooCommerceSubscriptionsDoubles::load();
		$module = $this->getMockBuilder( StripeBillingModule::class )
			->onlyMethods( array( 'is_loaded' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( $loaded );
		$container = $GLOBALS['wc_container'];
		$this->assertInstanceOf( \Automattic\WooCommerce\Testing\Tools\TestingContainer::class, $container );
		$container->replace( StripeBillingModule::class, $module );
		$subscription = $this->order_for_dropped_update();
		$subscription->update_meta_data( '_wcpay_subscription_id', 'sub_order' );
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();
		$renewal = new \WC_Order();
		$renewal->update_meta_data( '_wcpay_billing_invoice_id', 'in_order' );
		$renewal->save();
		$store = new QueuedEventRows();
		$event = array(
			'id'   => 'evt_invoice',
			'type' => $type,
			'data' => array(
				'object' => array(
					'id'           => 'in_order',
					'subscription' => 'sub_order',
				),
			),
			WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => $worker || 'invoice.paid' === $type ? 3 : 0,
		);
		try {
			$store->set_event( 'evt_invoice', $event, time() - 2 * DAY_IN_SECONDS );
			if ( $worker ) {
				// Upcoming invoices normally get one attempt. Exercise the final retry with the same order lookup.
				$failure  = new \RuntimeException( 'Processing failed.' );
				$ingestor = $this->createPartialMock( EventIngestor::class, array( 'process', 'is_retried_event' ) );
				$ingestor->method( 'process' )->willThrowException( $failure );
				$ingestor->method( 'is_retried_event' )->willReturn( true );
				$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), $ingestor );
				try {
					$service->process_event( 'evt_invoice' );
					$this->fail( 'Action Scheduler must receive the final failure.' );
				} catch ( \RuntimeException $exception ) {
					$this->assertSame( $failure, $exception );
				}
			} else {
				$this->sut->sweep_queued_events();
			}
			$expected = $loaded && 'invoice.paid' === $type ? array( 'A WooPayments update for this order (event evt_invoice) could not be applied. Check the payment in your WooPayments dashboard.' ) : array();
			$this->assertSame( array(), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ), 'content' ) );
			$this->assertSame( $expected, wp_list_pluck( wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ), 'content' ) );
			$this->assertNull( $store->get_row( 'evt_invoice' ) );
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] );
		}
	}

	/** @testdox A redelivery overlapping the note insert leaves only the original event note. */
	public function test_dropped_update_overlapping_sweeps(): void {
		$order   = $this->order_for_dropped_update();
		$event   = array(
			'id'   => 'evt_drop',
			'type' => 'payment_intent.succeeded',
			'data' => array( 'object' => array( 'id' => 'pi_order' ) ),
			WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
		);
		$store   = new QueuedEventRows();
		$sweep   = function () use ( $store, $event ) {
			$store->set_event( 'evt_drop', $event, time() - 2 * DAY_IN_SECONDS );
			$this->sut->sweep_queued_events();
		};
		$overlap = function ( $data ) use ( &$overlap, $sweep ) {
			remove_filter( 'woocommerce_new_order_note_data', $overlap );
			$sweep();
			return $data;
		};
		add_filter( 'woocommerce_new_order_note_data', $overlap );
		try {
			$sweep();
		} finally {
			remove_filter( 'woocommerce_new_order_note_data', $overlap );
		}
		$this->assertSame( array( 'A WooPayments update for this order (event evt_drop) could not be applied. Check the payment in your WooPayments dashboard.' ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertNull( $store->get_row( 'evt_drop' ) );
	}

	/** @testdox A failed refund already explained by the extension needs no dropped-update note. */
	public function test_dropped_update_preserves_legacy_refund_note(): void {
		$order  = $this->order_for_dropped_update();
		$refund = array(
			'id'             => 're_order',
			'charge'         => 'ch_order',
			'status'         => 'failed',
			'failure_reason' => 'declined',
			'amount'         => 400,
			'currency'       => 'usd',
		);
		$price  = WooPaymentsCurrencyUtils::format_explicit_order_price( 4, 'usd', $order );
		$note   = "A refund of $price was <strong>unsuccessful</strong> using WooPayments (<code>re_order</code>): The refund was declined by the card issuer.";
		$order->add_order_note( $note );
		$store = new QueuedEventRows();
		$store->set_event(
			'evt_drop',
			array(
				'id'   => 'evt_drop',
				'type' => 'charge.refund.updated',
				'data' => array( 'object' => $refund ),
				WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
			),
			time() - 2 * DAY_IN_SECONDS
		);
		$this->sut->sweep_queued_events();
		$this->assertSame( array( $note ), wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * @testdox A failing terminal note cannot keep the queued row or replace the worker failure.
	 * @testWith ["woocommerce_new_order_note_data"]
	 *           ["woocommerce_order_note_added"]
	 * @param string $hook Order note hook that fails.
	 */
	public function test_dropped_update_note_write_failure( string $hook ): void {
		$this->order_for_dropped_update();
		$failure = new \RuntimeException( 'Processing failed.' );
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), new ThrowingEventIngestor( $failure ) );
		$event   = array(
			'id'   => 'evt_drop',
			'type' => 'payment_intent.succeeded',
			'data' => array( 'object' => array( 'id' => 'pi_order' ) ),
			WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3,
		);
		$store   = new QueuedEventRows();
		$store->set_event( 'evt_drop', $event );
		$attempted = false;
		$throw     = static function () use ( &$attempted ) {
			$attempted = true;
			throw new \RuntimeException( 'Note write failed.' );
		};
		add_action( $hook, $throw );
		try {
			$service->process_event( 'evt_drop' );
			$this->fail( 'The worker failure must reach Action Scheduler.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( $failure, $exception );
		} finally {
			remove_action( $hook, $throw );
		}
		$this->assertTrue( $attempted );
		$this->assertNull( $store->get_row( 'evt_drop' ) );
	}

	/**
	 * @testdox A dropped refund only needs a recording note when the merchant has not recorded it in response to its earlier note.
	 * @testWith ["worker", "succeeded", "succeeded", "after", true]
	 *           ["worker", "pending", "pending", "after", true]
	 *           ["sweep", "succeeded", "succeeded", "after", true]
	 *           ["worker", "succeeded", "succeeded", "none", false]
	 *           ["worker", "succeeded", "", "after", false]
	 *           ["worker", "succeeded", "succeeded", "before", false]
	 *           ["worker", "succeeded", "pending", "after", false]
	 *
	 * @param string $path        Worker or sweep.
	 * @param string $status      Event refund status.
	 * @param string $note_status Refund status in the earlier note, or empty for no note.
	 * @param string $row_time    When the manual row was saved relative to the note, or none.
	 * @param bool   $matches     Whether the merchant recorded this refund.
	 */
	public function test_dropped_refund_with_manual_history( string $path, string $status, string $note_status, string $row_time, bool $matches ): void {
		$order  = $this->refundable_order();
		$amount = 'sweep' === $path ? 10 : 4;
		$event  = $this->refund_event( $amount * 100 );

		$event['data']['object']['amount']                       = 1000;
		$event['data']['object']['refunds']['data'][0]['status'] = $status;

		wc_get_container()->get( RefundRowCapture::class )->register();
		if ( '' !== $note_status ) {
			wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $order, 'Earlier note for re_order.', 'other_charge|charge.refunded|re_order|' . $note_status );
		}
		$manual = null;
		if ( 'none' !== $row_time ) {
			$manual = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount'   => $amount,
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $manual );
			if ( 'before' === $row_time ) {
				$manual->set_date_created( time() - DAY_IN_SECONDS );
				$manual->save();
			}
		}

		$note_query   = array( 'order_id' => $order->get_id() );
		$before_notes = wc_get_order_notes( $note_query );
		$store        = new QueuedEventRows();
		$service      = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), wc_get_container()->get( EventIngestor::class ) );
		if ( 'sweep' === $path ) {
			$store->set_event( 'evt_refund', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3 ), time() - 2 * DAY_IN_SECONDS );
			$service->sweep_queued_events();
		} else {
			$vocabulary = new WooPaymentsPersistenceVocabulary();
			$store->set_event( 'evt_refund', $event );
			$this->hold_order_payment_lock( $order, $vocabulary );
			try {
				for ( $attempt = 0; $attempt <= 3; ++$attempt ) {
					$this->assertSame( $attempt, $store->get_event( 'evt_refund' )[ WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY ] ?? 0 );
					try {
						$service->process_event( 'evt_refund' );
						$this->fail( 'The held order lock must refuse every attempt.' );
					} catch ( OrderPaymentLockRefusedException $failure ) {
						$this->assertSame( $order->get_id(), $failure->get_order_id() );
					}
				}
			} finally {
				$this->clear_order_payment_lock( $order, $vocabulary );
			}
		}

		$this->assertNull( $store->get_event( 'evt_refund' ) );
		$writer = wc_get_container()->get( RefundEventHandler::class );
		$order  = $writer->get_fresh_order( $order );
		$this->assertCount( $manual ? 1 : 0, $order->get_refunds() );
		$this->assertSame( $manual ? (float) $amount : 0.0, (float) $order->get_total_refunded() );
		if ( $manual ) {
			$manual->read_meta_data( true );
			$this->assertSame( '', $manual->get_meta( '_wcpay_refund_id' ) );
		}
		$expected = $matches ? array() : array( 'A refund of 4.00 USD was made using WooPayments (<code>re_order</code>). No refund was recorded on this order, because the order could not be updated after several tries.' );
		$this->assertSame( $expected, $this->refund_recording_notes( $order ) );
		$contents = wp_list_pluck( wc_get_order_notes( $note_query ), 'content' );
		$this->assertStringNotContainsString( 'event evt_refund) could not be applied', implode( "\n", $contents ) );
		$this->assertCount( count( $before_notes ) + count( $expected ), $contents );

		if ( 'worker' === $path && 'succeeded' === $status && $matches ) {
			$this->assertNotNull( $manual );
			$store->set_event( 'evt_refund', $event );
			$service->process_event( 'evt_refund' );
			$manual->read_meta_data( true );
			$this->assertSame( 're_order', $manual->get_meta( '_wcpay_refund_id' ) );
			$order = $writer->get_fresh_order( $order );
			$this->assertCount( 1, $order->get_refunds() );
			$this->assertSame( 4.0, (float) $order->get_total_refunded() );
			$this->assertSame( array(), $this->refund_recording_notes( $order ) );
			$created = array_filter( wp_list_pluck( wc_get_order_notes( $note_query ), 'content' ), static fn( $content ) => false !== strpos( $content, 'was successfully processed' ) );
			$this->assertCount( 1, $created );
		}
	}

	/**
	 * @testdox Only an exhausted refund leaves a note, using the reason that still applies on the order.
	 * @testWith ["remainder", 550, "5.50"]
	 *           ["lowered decimals", 60, "0.60"]
	 *           ["total", 1050, "10.50"]
	 *           ["write", 449, "4.49"]
	 *           ["lock", 449, "4.49"]
	 *           ["other currency lock", 449, "4.49"]
	 *           ["other currency remainder", 550, "5.50"]
	 *           ["manual refund", 800, "8.00"]
	 *           ["lock note hook", 449, "4.49"]
	 * @param string $reason Reason for failure.
	 * @param int    $minor  Refund in minor units.
	 * @param string $money  Expected display of the payment amount.
	 */
	public function test_exhausted_refund_history( string $reason, int $minor, string $money ): void {
		$order      = $this->refundable_order();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$other      = 0 === strpos( $reason, 'other currency' );
		$locked     = false !== strpos( $reason, 'lock' );
		$prior      = 0;
		if ( 'lowered decimals' === $reason ) {
			$order->set_total( '10.40' );
			$order->save();
			$prior = 10;
			update_option( 'woocommerce_price_num_decimals', 0 );
		} elseif ( 'manual refund' === $reason ) {
			$prior = 3;
		} elseif ( false !== strpos( $reason, 'remainder' ) ) {
			$prior = 5;
		}
		if ( 'remainder' === $reason ) {
			update_option( 'woocommerce_price_num_decimals', 0 );
			$earlier = $this->refund_event( 450 );
			$earlier['data']['object']['refunds']['data'][0]['id'] = 're_earlier';
			wc_get_container()->get( EventIngestor::class )->process( $earlier );
		} elseif ( $prior ) {
			$manual = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount'   => $prior,
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $manual );
		}
		update_option( 'woocommerce_price_num_decimals', 0 );
		$event   = $this->refund_event( $minor, $other ? 'eur' : 'usd' );
		$store   = new QueuedEventRows();
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), wc_get_container()->get( EventIngestor::class ) );
		$store->set_event( 'evt_refund', $event );
		$throw = static function () {
			throw new \Exception( 'Refund hook failed.' );
		};
		if ( 'write' === $reason ) {
			add_action( 'woocommerce_create_refund', $throw );
		}
		if ( 'lock note hook' === $reason ) {
			add_action( 'woocommerce_order_note_added', $throw );
		}
		if ( $locked ) {
			$this->hold_order_payment_lock( $order, $vocabulary );
		}
		try {
			for ( $attempt = 0; $attempt <= 3; ++$attempt ) {
				$this->assertSame( $attempt, $store->get_event( 'evt_refund' )[ WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY ] ?? 0 );
				$this->assertSame( array(), $this->refund_recording_notes( $order ) );
				try {
					$service->process_event( 'evt_refund' );
				} catch ( \RuntimeException $failure ) {
					$this->assertSame( $locked ? OrderPaymentLockRefusedException::class : \RuntimeException::class, get_class( $failure ) );
					continue;
				}
				$this->fail( 'The failure must be retried and reported to the worker.' );
			}
		} finally {
			remove_action( 'woocommerce_order_note_added', $throw );
			remove_action( 'woocommerce_create_refund', $throw );
			$this->clear_order_payment_lock( $order, $vocabulary );
		}
		$this->assertNull( $store->get_event( 'evt_refund' ) );
		$lock_text = 'A WooPayments update for this order (event evt_refund) could not be applied, because another payment operation kept the order locked. Check the payment in your WooPayments dashboard.';
		$contents  = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertCount( $other && $locked ? 1 : 0, array_keys( $contents, $lock_text, true ) );
		$this->assertCount( $prior ? 1 : 0, wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_refunds() );
		$this->assertSame( 'remainder' === $reason ? 'successful' : '', wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_meta( '_wcpay_refund_status' ) );
		if ( $other ) {
			$this->assertSame( array(), $this->refund_recording_notes( $order ) );
			return;
		}
		$why      = $locked || 'write' === $reason ? 'the order could not be updated after several tries' : 'the amount is more than the order has left to refund';
		$expected = "A refund of $money USD was made using WooPayments (<code>re_order</code>). No refund was recorded on this order, because $why.";
		$this->assertSame( array( $expected ), $this->refund_recording_notes( $order ) );
		$writer = wc_get_container()->get( RefundEventHandler::class );
		$charge = $event['data']['object'];
		$this->assertFalse( $writer->has_legacy_failed_refund_note( $order, $writer->parse_refunded_charge( $charge ) ) );
		$order->set_total( '20.00' );
		$order->save();
		$writer->process( 'charge.refunded', $charge );
		$this->assertCount( $prior ? 1 : 0, $writer->get_fresh_order( $order )->get_refunds() );
		$this->assertSame( array( $expected ), $this->refund_recording_notes( $order ) );
		$writer->process(
			'charge.refund.updated',
			array(
				'charge'         => 'ch_order',
				'id'             => 're_order',
				'amount'         => $minor,
				'currency'       => 'usd',
				'status'         => 'failed',
				'failure_reason' => 'declined',
			)
		);
		$this->assertSame( 'failed', wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_meta( '_wcpay_refund_status' ) );
		$this->assertSame( array( $expected ), $this->refund_recording_notes( $order ) );
		$price = WooPaymentsCurrencyUtils::format_explicit_order_price( $minor / 100, 'usd', $order );
		$this->assertContains( "A refund of $price was <strong>unsuccessful</strong> using WooPayments (<code>re_order</code>): The refund was declined by the card issuer.", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * @param \WC_Order $order Refunded order.
	 * @return string[] Notes carrying this refund's recording identity.
	 */
	private function refund_recording_notes( \WC_Order $order ): array {
		$key   = ( new WooPaymentsPersistenceVocabulary() )->get_note_identity_meta_key();
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		return array_values( wp_list_pluck( array_filter( $notes, static fn( $note ) => hash( 'sha256', 'refund:re_order:unrecorded' ) === get_comment_meta( $note->id, $key, true ) ), 'content' ) );
	}

	/**
	 * @testdox A retry after the manual refund is removed records the rounded row.
	 */
	public function test_refund_recovers_inside_retry_window(): void {
		$order = $this->refundable_order();
		update_option( 'woocommerce_price_num_decimals', 0 );
		$manual = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 5,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $manual );
		$service = wc_get_container()->get( WebhookReliabilityService::class );
		( new QueuedEventRows() )->set_event( 'evt_refund', $this->refund_event( 550 ) );
		try {
			$service->process_event( 'evt_refund' );
			$this->fail( 'The manual refund leaves only five dollars.' );
		} catch ( \RuntimeException $failure ) {
			$this->assertStringContainsString( 'Could not create local refund', $failure->getMessage() );
			$this->assertSame( array(), $this->refund_recording_notes( $order ) );
		}
		$manual->delete( true );
		$service->process_event( 'evt_refund' );
		$this->assertCount( 1, wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_refunds() );
		$this->assertSame( array( 'A refund of 5.50 USD was made using WooPayments (<code>re_order</code>). This order records it as 6 USD, rounded to the decimals this store uses for USD.' ), $this->refund_recording_notes( $order ) );
	}

	/**
	 * @testdox The sweep checks the order inside its claim before recording a dropped refund.
	 * @testWith ["empty"]
	 *           ["row"]
	 *           ["failure"]
	 * @param string $history What the preceding holder wrote.
	 */
	public function test_swept_refund_history( string $history ): void {
		$order       = $this->refundable_order();
		$event       = $this->refund_event( 400 );
		$writer      = wc_get_container()->get( RefundEventHandler::class );
		$lock        = new OrderPaymentLockWithClaimHook(
			static function () use ( $writer, $event, $history ) {
				if ( 'row' === $history ) {
					$writer->process( 'charge.refunded', $event['data']['object'] );
				} elseif ( 'failure' === $history ) {
					$writer->process(
						'charge.refund.updated',
						array(
							'charge'   => 'ch_order',
							'id'       => 're_order',
							'amount'   => 400,
							'currency' => 'usd',
							'status'   => 'failed',
						)
					);
				}
			}
		);
		$drop_writer = new RefundEventHandler();
		$drop_writer->init( $lock, new WooPaymentsPersistenceVocabulary(), wc_get_container()->get( EventOrderResolver::class ), wc_get_container()->get( OtherChargeRecorder::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );
		$container = $GLOBALS['wc_container'];
		$this->assertInstanceOf( \Automattic\WooCommerce\Testing\Tools\TestingContainer::class, $container );
		$container->replace( RefundEventHandler::class, $drop_writer );
		$store   = new QueuedEventRows();
		$service = $this->create_service( new RecordingActionSchedulerService(), new StaticFailedEventsApiClient(), wc_get_container()->get( EventIngestor::class ) );
		try {
			for ( $sweep = 0; $sweep < 2; ++$sweep ) {
				$store->set_event( 'evt_refund', $event + array( WebhookReliabilityService::RETRY_ATTEMPTS_EVENT_KEY => 3 ), time() - 2 * DAY_IN_SECONDS );
				$service->sweep_queued_events();
			}
			$this->assertTrue( $lock->hook_ran );
			$this->assertNull( $store->get_event( 'evt_refund' ) );
			$expected = 'empty' === $history ? array( 'A refund of 4.00 USD was made using WooPayments (<code>re_order</code>). No refund was recorded on this order, because the order could not be updated after several tries.' ) : array();
			$this->assertSame( $expected, $this->refund_recording_notes( $order ) );
		} finally {
			$container->replace( RefundEventHandler::class, $writer );
		}
	}

	/**
	 * @testdox The webhook retries a passing write failure and never reports a refund that subsequently failed.
	 * @testWith [false]
	 *           [true]
	 * @param bool $failed Whether the provider returns the money before the retry.
	 */
	public function test_passing_refund_write_failure( bool $failed ): void {
		$order = $this->refundable_order();
		$event = $this->refund_event( 400 );
		$throw = static function () {
			throw new \Exception( 'Refund temporarily refused.' );
		};
		add_action( 'woocommerce_create_refund', $throw );
		try {
			$response = $this->deliver_refund_webhook( $event );
		} finally {
			remove_action( 'woocommerce_create_refund', $throw );
		}
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( $event, ( new QueuedEventRows() )->get_event( 'evt_refund' ) );
		$this->assertSame( array(), $this->refund_recording_notes( $order ) );
		if ( $failed ) {
			$refund = array(
				'charge'   => 'ch_order',
				'id'       => 're_order',
				'amount'   => 400,
				'currency' => 'usd',
				'status'   => 'failed',
			);
			wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refund.updated', $refund );
		}
		$this->sut->process_event( 'evt_refund' );
		$this->assertNull( ( new QueuedEventRows() )->get_event( 'evt_refund' ) );
		$this->assertCount( $failed ? 0 : 1, wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_refunds() );
		$this->assertSame( array(), $this->refund_recording_notes( $order ) );
		$this->assertSame( $failed ? 'failed' : 'successful', wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_meta( '_wcpay_refund_status' ) );
	}

	/**
	 * @testdox The webhook retries an amount above the total only when the currencies match.
	 * @testWith ["usd", 500]
	 *           ["eur", 400]
	 * @param string $currency Payment currency.
	 * @param int    $status   Expected HTTP status.
	 */
	public function test_refund_above_total_webhook( string $currency, int $status ): void {
		$order = $this->refundable_order();
		$event = $this->refund_event( 1500, $currency );
		$this->assertSame( $status, $this->deliver_refund_webhook( $event )->get_status() );
		$this->assertSame( 500 === $status ? $event : null, ( new QueuedEventRows() )->get_event( 'evt_refund' ) );
		$this->assertSame( array(), $this->refund_recording_notes( $order ) );
		if ( 500 === $status ) {
			$order->set_total( '20.00' );
			$order->save();
			$this->sut->process_event( 'evt_refund' );
			$this->assertCount( 1, wc_get_container()->get( RefundEventHandler::class )->get_fresh_order( $order )->get_refunds() );
			$this->assertSame( array(), $this->refund_recording_notes( $order ) );
		}
	}

	/**
	 * @param array $event Provider event.
	 * @return \WP_REST_Response
	 */
	private function deliver_refund_webhook( array $event ): \WP_REST_Response {
		/** @var \WP_REST_Request<array<string,mixed>> $request */
		$request = new \WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
		$request->set_body_params( $event );
		return wc_get_container()->get( WebhookRestController::class )->handle_webhook( $request );
	}
}
