<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\ClientRenderedCapturedEvents;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFeeDetailsNoteController class.
 */
class WooPaymentsFeeDetailsNoteControllerTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;
	use ProviderTextLogAssertions;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsFeeDetailsNoteController
	 */
	private WooPaymentsFeeDetailsNoteController $sut;

	/**
	 * Controllers whose job handler a test registered, removed on tear down.
	 *
	 * @var WooPaymentsFeeDetailsNoteController[]
	 */
	private array $hook_owners = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class );
		as_unschedule_all_actions( WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hook_owners as $controller ) {
			remove_action( 'wcpay_add_fee_breakdown_to_order_notes', array( $controller, 'handle_wcpay_add_fee_breakdown_to_order_notes' ), 10 );
		}
		$this->hook_owners = array();
		as_unschedule_all_actions( WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION );
		parent::tearDown();
	}

	/**
	 * A payment's first success or capture note schedules the job with client 11.1.0's arguments.
	 *
	 * @dataProvider success_or_capture_events
	 *
	 * @param string $payment_reference Payment reference the event carries.
	 * @param string $note_type         Note type.
	 */
	public function test_first_success_or_capture_note_schedules_the_job( string $payment_reference, string $note_type ): void {
		$order = $this->create_order();

		$this->sut->apply_and_schedule_fee_details( $order, $this->completed_event( $payment_reference, $note_type ) );

		$this->assertSame(
			array(
				array(
					'order_id'     => $order->get_id(),
					'intent_id'    => $payment_reference,
					'is_test_mode' => false,
				),
			),
			$this->get_pending_job_args()
		);
	}

	/**
	 * Success and capture events, as the payment lifecycle receives them.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function success_or_capture_events(): array {
		return array(
			'payment success'                  => array( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ),
			'capture success'                  => array( 'pi_456', PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS ),
			'setup intent, as the client does' => array( 'seti_789', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ),
		);
	}

	/**
	 * Other events schedule nothing.
	 *
	 * @dataProvider other_events
	 *
	 * @param string      $status            Lifecycle status.
	 * @param string|null $payment_reference Payment reference.
	 * @param string|null $note_type         Note type.
	 */
	public function test_other_events_schedule_nothing( string $status, ?string $payment_reference, ?string $note_type ): void {
		$order = $this->create_order();

		$this->sut->apply_and_schedule_fee_details( $order, new PaymentLifecycleEvent( $status, $payment_reference, array(), array(), 'A note.', $note_type ) );

		$this->assertSame( array(), $this->get_pending_job_args() );
	}

	/**
	 * Events that do not complete a payment or a capture with its note.
	 *
	 * @return array<string,array{string,?string,?string}>
	 */
	public function other_events(): array {
		return array(
			'authorized'           => array( PaymentLifecycleEvent::STATUS_AUTHORIZED, 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED ),
			'failed'               => array( PaymentLifecycleEvent::STATUS_FAILED, 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED ),
			'plain completion'     => array( PaymentLifecycleEvent::STATUS_COMPLETED, 'seti_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE ),
			'no payment reference' => array( PaymentLifecycleEvent::STATUS_COMPLETED, null, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ),
			'no note type'         => array( PaymentLifecycleEvent::STATUS_COMPLETED, 'pi_123', null ),
		);
	}

	/**
	 * @testdox A success note the order already has, by its identity or its text, schedules nothing, as the client returns once its note exists.
	 *
	 * @dataProvider existing_note_kinds
	 *
	 * @param bool $with_identity Whether the existing note carries its identity.
	 */
	public function test_existing_note_schedules_nothing( bool $with_identity ): void {
		$order = $this->create_order();
		$event = $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
		$order->add_order_note( 'A note.', 0, false, $with_identity ? array( WooPaymentsPersistenceVocabulary::NOTE_IDENTITY_META_KEY => hash( 'sha256', $event->get_note_identity() ) ) : array() );

		$this->sut->apply_and_schedule_fee_details( $order, $event );

		$this->assertSame( array(), $this->get_pending_job_args() );
	}

	/**
	 * Ways the order can already have the note.
	 *
	 * @return array<string,array{bool}>
	 */
	public function existing_note_kinds(): array {
		return array(
			'by identity' => array( true ),
			'by text'     => array( false ),
		);
	}

	/**
	 * @testdox The job carries the store's test mode, as the client's does.
	 */
	public function test_job_carries_test_mode(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_order();

		$this->sut->apply_and_schedule_fee_details( $order, $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ) );

		$this->assertTrue( $this->get_pending_job_args()[0]['is_test_mode'] );
	}

	/**
	 * @testdox Applying through the lifecycle's lock schedules the job, and a held lock refuses the event without writing or scheduling.
	 */
	public function test_apply_with_lock_reports_a_refusal_and_schedules_only_an_applied_event(): void {
		$order      = $this->create_order();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$this->hold_order_payment_lock( $order, $vocabulary, 'native_charge_operation' );

		try {
			$this->assertFalse( $this->sut->apply_and_schedule_fee_details_with_lock( $order, $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ) ) );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$this->assertSame( array(), $this->get_pending_job_args() );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$this->assertTrue( $this->sut->apply_and_schedule_fee_details_with_lock( $order, $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ) ) );

		$this->assertCount( 1, $this->get_pending_job_args() );
	}

	/**
	 * @testdox A runtime outcome's success note is checked before the lifecycle applies it, and the job is scheduled once it was added.
	 */
	public function test_outcome_note_check_and_schedule(): void {
		$order   = $this->create_order();
		$event   = $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_123',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_NOTE      => 'A note.',
				PaymentOutcome::DATA_NOTE_TYPE => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS,
			)
		);

		$this->assertFalse( $this->sut->has_outcome_note( $order, $outcome ) );
		$this->assertNull( $this->sut->has_outcome_note( $order, new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, 'pi_123' ) ) );

		$this->sut->schedule_when_outcome_note_added( $order, $outcome );
		$this->assertSame( array(), $this->get_pending_job_args(), 'No job while the note is not on the order.' );

		$order->add_order_note( 'A note.', 0, false, array( WooPaymentsPersistenceVocabulary::NOTE_IDENTITY_META_KEY => hash( 'sha256', $event->get_note_identity() ) ) );
		$this->assertTrue( $this->sut->has_outcome_note( $order, $outcome ) );

		$this->sut->schedule_when_outcome_note_added( $order, $outcome );
		$this->assertCount( 1, $this->get_pending_job_args() );
	}

	/**
	 * @testdox The job's handler is registered while the built-in WooPayments owns the store, and not otherwise.
	 */
	public function test_registers_the_job_handler_only_for_the_builtin_owner(): void {
		$owner             = $this->create_controller( $this->createMock( WooPaymentsApiClient::class ) );
		$not_owner         = $this->create_controller( $this->createMock( WooPaymentsApiClient::class ), false );
		$this->hook_owners = array( $owner, $not_owner );

		$owner->register();
		$not_owner->register();

		$this->assertSame( 10, has_action( 'wcpay_add_fee_breakdown_to_order_notes', array( $owner, 'handle_wcpay_add_fee_breakdown_to_order_notes' ) ) );
		$this->assertFalse( has_action( 'wcpay_add_fee_breakdown_to_order_notes', array( $not_owner, 'handle_wcpay_add_fee_breakdown_to_order_notes' ) ) );
	}

	/**
	 * Fee-breakdown jobs write the note client 11.1.0 renders for the recorded captured timeline event.
	 *
	 * @dataProvider recorded_captured_event_names
	 *
	 * @param string $name Case name in `Fixtures/rec-n296-captured-event-notes.json`.
	 */
	public function test_add_fee_breakdown_to_order_notes_renders_captured_timeline_event( string $name ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();

		$case       = ClientRenderedCapturedEvents::get( $name );
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->expects( $this->once() )
			->method( 'get_timeline' )
			->with( 'pi_123' )
			->willReturnCallback(
				function () use ( $case ): array {
					$this->assertTrue( $this->is_wcpay_test_mode(), 'The timeline is read in the test mode the job was scheduled in.' );

					return array(
						'data' => array(
							array( 'type' => 'authorized' ),
							$case['event'],
						),
					);
				}
			);

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', true );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( '<strong>Fee details:</strong>' . $case['client_html'], $notes[0]->content );
		$this->assertFalse( $this->is_wcpay_test_mode() );
	}

	/**
	 * Recorded REC-5a captured events: one without conversion, one converted from EUR.
	 *
	 * @return array<string,array{string}>
	 */
	public function recorded_captured_event_names(): array {
		return array(
			'USD, no conversion' => array( 'recorded-rec-5a:usd_full_refund_free_text_reason' ),
			'converted from EUR' => array( 'recorded-rec-5a:eur_full_refund' ),
		);
	}

	/**
	 * @testdox Fee-breakdown jobs skip malformed timeline data without adding an empty note.
	 */
	public function test_add_fee_breakdown_to_order_notes_skips_malformed_timeline(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willReturn( array( 'data' => array( array( 'type' => 'authorized' ) ) ) );

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', false );

		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox A timeline the job cannot use ($_dataName) is logged as the client logs it, and adds no note.
	 *
	 * Client 11.1.0 add_fee_breakdown_to_order_notes() logs both cases through its gated Logger::log() at info level
	 * (class-wc-payments-order-service.php:845-861).
	 *
	 * @dataProvider unusable_timelines
	 *
	 * @param array<string,mixed> $timeline Timeline the platform returns.
	 * @param string              $message  Line the client logs.
	 */
	public function test_unusable_timeline_is_logged_as_the_client_logs_it( array $timeline, string $message ): void {
		self::enable_woopayments_debug_logging();
		$order = wc_create_order();
		$order->save();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willReturn( $timeline );
		$logger = RecordingWcLogger::install();

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', false );

		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );
		$this->assertSame( array( array( 'info', $message, 'woopayments' ) ), $lines );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox With WooPayments logging off, a timeline the job cannot use ($_dataName) writes no line, as the client's gated logger writes none.
	 *
	 * @dataProvider unusable_timelines
	 *
	 * @param array<string,mixed> $timeline Timeline the platform returns.
	 */
	public function test_unusable_timeline_writes_no_line_with_logging_off( array $timeline ): void {
		$order = wc_create_order();
		$order->save();
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willReturn( $timeline );
		$logger = RecordingWcLogger::install();

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', false );

		$this->assertSame( array(), $logger->lines );
	}

	/**
	 * Timelines the job cannot use, and the line the client logs for each.
	 *
	 * @return array<string,array{array<string,mixed>,string}>
	 */
	public function unusable_timelines(): array {
		return array(
			'no data'           => array( array(), 'Timeline data missing or malformed for intent_id pi_123.' ),
			'data not a list'   => array( array( 'data' => 'unexpected' ), 'Timeline data missing or malformed for intent_id pi_123.' ),
			'no captured event' => array( array( 'data' => array( array( 'type' => 'authorized' ) ) ), 'No captured event found in timeline for intent_id pi_123.' ),
		);
	}

	/**
	 * @testdox Fee-breakdown job failures log order and intent correlation context.
	 */
	public function test_add_fee_breakdown_failure_logs_order_and_intent_context(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willThrowException( self::make_provider_error() );

		$logger = RecordingWcLogger::install();

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', false );

		$errors = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'error' === $line[0] ) );
		$this->assertCount( 1, $errors, 'A single error should be logged when the timeline read fails.' );
		$this->assertSame( 'Failed to read native WooPayments intent timeline.', $logger->lines[ $errors[0] ][1] );
		$context = $logger->contexts[ $errors[0] ];
		$this->assertSame( 'woopayments', $context['source'] );
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'pi_123', $context['intent_id'] );
		$this->assertSame( 'wcpay_add_fee_breakdown_to_order_notes', $context['action'] );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Build a controller over a given platform API client.
	 *
	 * @param WooPaymentsApiClient $api_client    Platform API client.
	 * @param bool                 $builtin_owner Whether the built-in WooPayments owns the store.
	 * @return WooPaymentsFeeDetailsNoteController
	 */
	private function create_controller( WooPaymentsApiClient $api_client, bool $builtin_owner = true ): WooPaymentsFeeDetailsNoteController {
		$container  = wc_get_container();
		$controller = new WooPaymentsFeeDetailsNoteController();
		$controller->init(
			$container->get( OrderPaymentLifecycleService::class ),
			$container->get( OrderPaymentNotes::class ),
			new WooPaymentsPersistenceVocabulary(),
			$container->get( WooPaymentsActionSchedulerService::class ),
			$container->get( WooPaymentsAccountService::class ),
			new StaticWooPaymentsRuntimeArbiter( $builtin_owner ),
			$api_client,
			$container->get( WooPaymentsOrderDataService::class ),
			$container->get( WooPaymentsLogger::class )
		);

		return $controller;
	}

	/**
	 * Tell whether WooPayments runs in test mode for this request.
	 *
	 * @return bool
	 */
	private function is_wcpay_test_mode(): bool {
		/**
		 * Filters whether the current WooPayments request runs in test mode.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( 'wcpay_test_mode', false );
	}

	/**
	 * Build a completed event with a note.
	 *
	 * @param string $payment_reference Payment reference.
	 * @param string $note_type         Note type.
	 * @return PaymentLifecycleEvent
	 */
	private function completed_event( string $payment_reference, string $note_type ): PaymentLifecycleEvent {
		return new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_COMPLETED, $payment_reference, array(), array(), 'A note.', $note_type );
	}

	/**
	 * Create a saved WooPayments order.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->save();

		return $order;
	}

	/**
	 * Get the arguments of every pending Fee details job.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_pending_job_args(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'group'  => 'woocommerce_payments',
			)
		);

		return array_values( array_map( static fn( $action ): array => $action->get_args(), $actions ) );
	}
}
