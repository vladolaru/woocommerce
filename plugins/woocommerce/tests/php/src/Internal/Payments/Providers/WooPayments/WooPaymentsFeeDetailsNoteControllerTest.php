<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFeeDetailsNoteController class.
 */
class WooPaymentsFeeDetailsNoteControllerTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsFeeDetailsNoteController
	 */
	private WooPaymentsFeeDetailsNoteController $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class );
		as_unschedule_all_actions( WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		as_unschedule_all_actions( WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION );
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
				'hook'   => WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'group'  => 'woocommerce_payments',
			)
		);

		return array_values( array_map( static fn( $action ): array => $action->get_args(), $actions ) );
	}
}
