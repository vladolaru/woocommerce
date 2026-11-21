<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\CapturedEventFeeNotes;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\StoredPaymentRecords;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
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
	 * @testdox A success note the order already has, by its identity or its text, schedules nothing, as the WooPayments extension returns once its note exists.
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
	 * @testdox The job carries the store's test mode, as the WooPayments extension's does.
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
	public function test_apply_with_lock_schedules_only_an_applied_event(): void {
		$order      = $this->create_order();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$this->hold_order_payment_lock( $order, $vocabulary, 'native_charge_operation' );

		try {
			$this->sut->apply_and_schedule_fee_details_with_lock( $order, $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ) );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$this->assertSame( array(), $this->get_pending_job_args() );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$this->sut->apply_and_schedule_fee_details_with_lock( $order, $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ) );

		$this->assertCount( 1, $this->get_pending_job_args() );
	}

	/**
	 * @testdox A refused locked apply answers and logs as the payment lifecycle's own refused apply, and builds and writes nothing.
	 */
	public function test_refused_apply_with_lock_answers_and_logs_as_the_lifecycle(): void {
		$order      = $this->create_order();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$event      = $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
		$builds     = 0;
		$this->hold_order_payment_lock( $order, $vocabulary, 'native_charge_operation' );
		$logger = RecordingWcLogger::install();

		try {
			$result           = $this->sut->apply_and_schedule_fee_details_with_lock(
				$order,
				$event,
				static function () use ( &$builds, $event ): array {
					++$builds;

					return array( $event, null );
				}
			);
			$lifecycle_result = wc_get_container()->get( OrderPaymentLifecycleService::class )->apply( $order, $event, $vocabulary );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$this->assertSame( OrderPaymentLifecycleService::RESULT_REFUSED, $result );
		$this->assertSame( $lifecycle_result, $result );
		$this->assertSame( 0, $builds );
		$this->assertCount( 2, $logger->lines );
		$this->assertSame( $logger->lines[1], $logger->lines[0] );
		$this->assertSame( $logger->contexts[1], $logger->contexts[0] );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array(), $this->get_pending_job_args() );
	}

	/**
	 * @testdox A locked apply builds its event under the order payment lock, held as the payment lifecycle holds it.
	 */
	public function test_apply_with_lock_builds_under_the_lifecycle_claim(): void {
		$order      = $this->create_order();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$event      = $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
		$logger     = RecordingWcLogger::install();

		$this->sut->apply_and_schedule_fee_details_with_lock(
			$order,
			$event,
			static function ( WC_Order $stored_order ) use ( $event, $vocabulary ): array {
				wc_get_container()->get( OrderPaymentLifecycleService::class )->apply( $stored_order, $event, $vocabulary );

				return array( $event, null );
			}
		);

		$refusals = array_values( array_filter( array_column( $logger->lines, 1 ), static fn( string $line ): bool => false !== strpos( $line, ', refused ' ) ) );
		$this->assertCount( 1, $refusals );
		$this->assertStringContainsString( 'refused payment status update, held by payment status update', $refusals[0] );
		$this->assertCount( 1, $this->get_pending_job_args() );
	}

	/**
	 * @testdox A subscription gets no payment event from either applier: no lock claim, no note, no status change and no job ($_dataName).
	 *
	 * Client 11.3.0 never completes a subscription from an intent (includes/class-wc-payments-order-service.php:466-472). The
	 * order payment lock is held elsewhere: an applier that claimed it would answer the refusal.
	 *
	 * @dataProvider provider_appliers
	 *
	 * @param bool $with_lock Whether the applier claims the order payment lock itself.
	 */
	public function test_appliers_apply_no_event_to_a_subscription( bool $with_lock ): void {
		$order      = $this->create_order();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( $order->get_id() );
		$this->hold_order_payment_lock( $order, $vocabulary, 'native_charge_operation' );
		$event  = $this->completed_event( 'pi_123', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
		$result = null;

		try {
			if ( $with_lock ) {
				$result = $this->sut->apply_and_schedule_fee_details_with_lock( $order, $event );
			} else {
				$this->sut->apply_and_schedule_fee_details( $order, $event );
			}
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] );
		}
		$reloaded = wc_get_order( $order->get_id() );

		if ( $with_lock ) {
			$this->assertSame( OrderPaymentLifecycleService::RESULT_NOTE_NOT_ADDED, $result, 'No lock claim, so no refusal.' );
		}
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_transaction_id() );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array(), $this->get_pending_job_args() );
	}

	/**
	 * The two lifecycle appliers.
	 *
	 * @return array<string,array{bool}>
	 */
	public function provider_appliers(): array {
		return array(
			'under the caller\'s lock' => array( false ),
			'claiming the lock'        => array( true ),
		);
	}

	/**
	 * @testdox A completed event skipped on a paid order through $entry gets the paid-order note only for an online payment's intent passed with it: $_dataName.
	 *
	 * Client 11.3.0 leaves a paid order with no note (class-wc-payments-order-service.php:3292-3294); the note is the
	 * built-in's. The plain entry applies a plain success on a paid order, so it reaches the paid skip only with a held
	 * event, the shape Stripe Billing's hold on a paid renewal sends. The intent is passed as the webhook, the confirmation
	 * service and Stripe Billing pass it; the in-person route passes none.
	 *
	 * @dataProvider paid_order_note_entries_and_intents
	 *
	 * @param string      $entry       FDNC entry: `plain`, `intent status`, `lock`, or `lock with builder`.
	 * @param string      $reference   The event's payment reference.
	 * @param string|null $charge_type The passed intent's first charge type, '' for a charge with no type, `none` for no charge, null for no intent passed, or `plain completion` for a card intent whose event carries a plain completion note.
	 * @param bool        $noted       Whether the note is written.
	 */
	public function test_paid_order_note_needs_an_online_payments_intent( string $entry, string $reference, ?string $charge_type, bool $noted ): void {
		$order  = StoredPaymentRecords::payment( OrderStatus::PROCESSING, $reference, 'ch_paid' );
		$intent = null === $charge_type ? null : $this->paid_order_intent( $reference, 1099, 'plain completion' === $charge_type ? 'card' : $charge_type );
		$event  = 'plain completion' === $charge_type
			? new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_COMPLETED, $reference, array(), array(), 'Completed ' . $reference . '.', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE )
			: $this->completed_payment_event( $reference, 'plain' === $entry );

		$this->apply_through_entry( $entry, $order, $event, $intent );

		$this->assertCount( $noted ? 1 : 0, $this->get_paid_order_notes( $order ) );
	}

	/**
	 * Entries, references and intents, with whether the note is written.
	 *
	 * @return array<string,array{string,string,string|null,bool}>
	 */
	public function paid_order_note_entries_and_intents(): array {
		$rows = array();
		foreach ( array( 'plain', 'intent status', 'lock', 'lock with builder' ) as $entry ) {
			$rows[ $entry . ', no intent passed' ]         = array( $entry, 'pi_paid', null, false );
			$rows[ $entry . ', an online card intent' ]    = array( $entry, 'pi_paid', 'card', true );
			$rows[ $entry . ', an Interac reader intent' ] = array( $entry, 'pi_paid', 'interac_present', false );
			$rows[ $entry . ', a card reader intent' ]     = array( $entry, 'pi_paid', 'card_present', false );
		}
		$rows['intent status, a charge with no type']    = array( 'intent status', 'pi_paid', '', true );
		$rows['intent status, an intent with no charge'] = array( 'intent status', 'pi_paid', 'none', false );
		$rows['intent status, a setup intent']           = array( 'intent status', 'seti_paid', 'card', false );
		$rows['intent status, a plain completion note']  = array( 'intent status', 'pi_paid', 'plain completion', false );

		return $rows;
	}

	/**
	 * @testdox The paid-order note names the payment and its charge, and says that the transaction ID names it only when the order's transaction ID and stored charge are this payment's: $_dataName.
	 *
	 * @dataProvider paid_order_payment_records
	 *
	 * @param string $transaction_id The order's transaction ID.
	 * @param string $stored_charge  The order's stored charge.
	 * @param bool   $sentence_three Whether the note says the transaction ID names the payment.
	 */
	public function test_paid_order_note_names_the_transaction_id_only_when_it_is_true( string $transaction_id, string $stored_charge, bool $sentence_three ): void {
		$order = StoredPaymentRecords::payment( OrderStatus::PROCESSING, 'pi_paid', $stored_charge );
		$order->set_transaction_id( $transaction_id );
		$order->save();

		$this->sut->apply_intent_status_and_schedule_fee_details( $order, $this->completed_payment_event( 'pi_paid', false ), null, $this->paid_order_intent( 'pi_paid', 1099, 'card' ) );

		$notes = $this->get_paid_order_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringStartsWith( 'WooPayments payment <a href="', $notes[0] );
		$this->assertStringContainsString( '>pi_paid</a> (charge <code>ch_paid</code>) for ', $notes[0] );
		$this->assertStringContainsString( ' succeeded, but this order already had a paid status, so its status was not changed. If the customer also paid another way, one of the payments needs a refund.', $notes[0] );
		$this->assertSame( $sentence_three, str_contains( $notes[0], 'At this moment, this order&#039;s transaction ID names that payment, so a refund or a dispute of it changes this order.' ) );
		$this->assertStringEndsWith( $sentence_three ? 'changes this order.' : 'needs a refund.', $notes[0] );
	}

	/**
	 * The order's transaction ID and stored charge, against the payment pi_paid with its charge ch_paid.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function paid_order_payment_records(): array {
		return array(
			'both name the payment'   => array( 'pi_paid', 'ch_paid', true ),
			'another charge stored'   => array( 'pi_paid', 'ch_other', false ),
			'no charge stored'        => array( 'pi_paid', '', false ),
			'another transaction ID'  => array( 'pi_other', 'ch_paid', false ),
			'an empty transaction ID' => array( '', 'ch_paid', false ),
		);
	}

	/**
	 * @testdox A paid order that already records the payment by its $_dataName gets no paid-order note.
	 *
	 * The payment lifecycle writes each of the payment's notes under its own identity (PaymentLifecycleEvent::get_note_identity());
	 * a note the WooPayments extension wrote carries none and is found by its text. The event under test is one whose own
	 * note is not the one on the order, so only the paid-order note's own-note events can stop it.
	 *
	 * @dataProvider notes_that_record_the_payment
	 *
	 * @param string $status    Status of the lifecycle event that wrote the note.
	 * @param string $note_type Its note type, or `extension success` for the extension's success note, found by its text.
	 * @param bool   $held      Whether the event under test is a held one, through the plain entry; else a success, through the intent-status entry.
	 */
	public function test_paid_order_note_is_not_added_on_an_order_that_records_the_payment( string $status, string $note_type, bool $held ): void {
		$order = StoredPaymentRecords::payment( OrderStatus::PROCESSING, 'pi_paid', 'ch_paid' );
		if ( 'extension success' === $note_type ) {
			$text = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_payment_success_note_candidates( $order, 'pi_paid', 'ch_paid' )[0];
			$order->add_order_note( $text );
		} else {
			$written = new PaymentLifecycleEvent( $status, 'pi_paid', array(), array(), 'Recorded by the lifecycle.', $note_type );
			$order->add_order_note( 'Recorded by the lifecycle.', 0, false, array( WooPaymentsPersistenceVocabulary::NOTE_IDENTITY_META_KEY => hash( 'sha256', $written->get_note_identity() ) ) );
		}
		$intent = $this->paid_order_intent( 'pi_paid', 1099, 'card' );

		if ( $held ) {
			$this->sut->apply_and_schedule_fee_details( $order, $this->completed_payment_event( 'pi_paid', true ), null, $intent );
		} else {
			$this->sut->apply_intent_status_and_schedule_fee_details( $order, $this->completed_payment_event( 'pi_paid', false ), null, $intent );
		}

		$this->assertCount( 0, $this->get_paid_order_notes( $order ) );
	}

	/**
	 * Notes that mean the order records the payment, with the event that meets each.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function notes_that_record_the_payment(): array {
		return array(
			'success note'             => array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS, true ),
			"extension's success note" => array( PaymentLifecycleEvent::STATUS_COMPLETED, 'extension success', true ),
			'capture note'             => array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS, true ),
			'authorized note'          => array( PaymentLifecycleEvent::STATUS_AUTHORIZED, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED, true ),
			'held note'                => array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_HELD, false ),
		);
	}

	/**
	 * @testdox On an intent with two charge entries, the paid-order note reads the first, the one the webhook stores: its charge, the in-person test and the transaction ID sentence.
	 *
	 * The first entry is an online card charge the order stores; the last is a reader's Interac charge. Reading the last
	 * would name the other charge, drop the transaction ID sentence, and withhold the note as an in-person payment.
	 */
	public function test_paid_order_note_reads_the_first_charge_entry(): void {
		$order  = StoredPaymentRecords::payment( OrderStatus::PROCESSING, 'pi_paid', 'ch_paid' );
		$intent = $this->paid_order_intent( 'pi_paid', 1099, 'card' );

		$intent['charges']['data'][] = array(
			'id'                     => 'ch_last',
			'payment_method_details' => array( 'type' => 'interac_present' ),
		);

		$this->sut->apply_intent_status_and_schedule_fee_details( $order, $this->completed_payment_event( 'pi_paid', false ), null, $intent );

		$notes = $this->get_paid_order_notes( $order );
		$this->assertCount( 1, $notes, 'The first entry is not in person.' );
		$this->assertStringContainsString( '(charge <code>ch_paid</code>)', $notes[0] );
		$this->assertStringNotContainsString( 'ch_last', $notes[0] );
		$this->assertStringContainsString( 'transaction ID names that payment', $notes[0], "The first entry is the order's stored charge." );
	}

	/**
	 * @testdox A held payment on a paid order states the amount the payment charged, in its currency, not the order's total.
	 *
	 * The amount is read from the intent as the amount-mismatch hold reads it (WooPaymentsAmountMismatchHold::decide_hold()).
	 */
	public function test_paid_order_note_states_the_payments_amount(): void {
		$order = StoredPaymentRecords::payment( OrderStatus::PROCESSING, 'pi_paid', 'ch_paid' );

		$this->sut->apply_and_schedule_fee_details( $order, $this->completed_payment_event( 'pi_paid', true ), null, $this->paid_order_intent( 'pi_paid', 1500, 'card', 'eur' ) );

		$notes = $this->get_paid_order_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( ' for ' . wc_price( 15.0, array( 'currency' => 'EUR' ) ) . ' EUR succeeded', $notes[0] );
		$this->assertStringNotContainsString( '10.99', $notes[0] );
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
	 * @testdox The job's handler is registered.
	 */
	public function test_registers_the_job_handler(): void {
		$owner             = $this->create_controller( $this->createMock( Client::class ) );
		$this->hook_owners = array( $owner );

		$owner->register();

		$this->assertSame( 10, has_action( 'wcpay_add_fee_breakdown_to_order_notes', array( $owner, 'handle_wcpay_add_fee_breakdown_to_order_notes' ) ) );
	}

	/**
	 * Fee-breakdown jobs, run through their action with all three arguments, write the note client 11.1.0 renders for the
	 * recorded captured timeline event, reading the timeline in the job's test mode.
	 *
	 * @dataProvider recorded_captured_event_names
	 *
	 * @param string $name Case name in `Fixtures/captured-event-fee-notes.json`.
	 */
	public function test_add_fee_breakdown_to_order_notes_renders_captured_timeline_event( string $name ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();

		$case       = CapturedEventFeeNotes::get( $name );
		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
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

		remove_all_actions( 'wcpay_add_fee_breakdown_to_order_notes' );
		$controller        = $this->create_controller( $api_client );
		$this->hook_owners = array( $controller );
		$controller->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Running the queued job as Action Scheduler does.
		do_action( 'wcpay_add_fee_breakdown_to_order_notes', $order->get_id(), 'pi_123', true );

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
	 * @testdox A timeline the job cannot use ($_dataName) is logged as the WooPayments extension logs it, and adds no note.
	 *
	 * Client 11.1.0 add_fee_breakdown_to_order_notes() logs both cases through its gated Logger::log() at info level
	 * (class-wc-payments-order-service.php:845-861).
	 *
	 * @dataProvider unusable_timelines
	 *
	 * @param array<string,mixed> $timeline Timeline the platform returns.
	 * @param string              $message  Line the client logs.
	 */
	public function test_unusable_timeline_is_logged_as_the_woopayments_extension_logs_it( array $timeline, string $message ): void {
		self::enable_woopayments_debug_logging();
		$order = wc_create_order();
		$order->save();
		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willReturn( $timeline );
		$logger = RecordingWcLogger::install();

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', false );

		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );
		$this->assertSame( array( array( 'info', $message, 'woopayments' ) ), $lines );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox With WooPayments logging off, a timeline the job cannot use ($_dataName) writes no line, as the WooPayments extension's gated logger writes none.
	 *
	 * @dataProvider unusable_timelines
	 *
	 * @param array<string,mixed> $timeline Timeline the platform returns.
	 */
	public function test_unusable_timeline_writes_no_line_with_logging_off( array $timeline ): void {
		$order = wc_create_order();
		$order->save();
		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
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
	 * @testdox A SetupIntent's timeline read the platform refuses writes one info line with WooPayments logging $logging, as the WooPayments extension logs it.
	 *
	 * The job is queued for a completed SetupIntent too (client 11.1.0 class-wc-payments-order-service.php:1582, :2993-3000),
	 * and the platform refuses a SetupIntent's timeline: its timeline route takes only a pi_ ID and otherwise throws
	 * Bad_Request_Exception( 'Invalid intent ID' ) (wpcom `wcpay/class-timeline-controller.php:220-221`, the check at
	 * :1309-1311), which answers 400 with code wcpay_bad_request (`wcpay/core/exceptions/class-bad-request-exception.php:25`).
	 * Client 11.1.0 logs that through its gated Logger::log() at info level (class-wc-payments-order-service.php:879-880).
	 *
	 * @testWith ["on", true]
	 *           ["off", false]
	 *
	 * @param string $logging Whether WooPayments logging is on.
	 * @param bool   $logged  Whether a line is written.
	 */
	public function test_setup_intent_timeline_refusal_follows_the_woopayments_extension_level( string $logging, bool $logged ): void {
		unset( $logging );
		if ( $logged ) {
			self::enable_woopayments_debug_logging();
		}
		$order = wc_create_order();
		$order->save();
		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willThrowException( new ApiException( 'Invalid intent ID', 'wcpay_bad_request', 400 ) );
		$logger = RecordingWcLogger::install();

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'seti_123', false );

		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );
		$this->assertSame( $logged ? array( array( 'info', 'Failed to read WooPayments intent timeline.', 'woopayments' ) ) : array(), $lines );
		if ( $logged ) {
			$context = $this->get_logged_context_at_level( $logger, 'info' );
			$this->assertSame( array( $order->get_id(), 'seti_123', 400, 'wcpay_bad_request' ), array( $context['order_id'], $context['intent_id'], $context['http_status'], $context['error_code'] ) );
			$this->assert_log_holds_no_provider_text( $logger );
		}
	}

	/**
	 * @testdox Fee-breakdown job failures log order and intent correlation context.
	 */
	public function test_add_fee_breakdown_failure_logs_order_and_intent_context(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();

		$api_client = $this->getMockBuilder( Client::class )->disableOriginalConstructor()->onlyMethods( array( 'get_timeline' ) )->getMock();
		$api_client->method( 'get_timeline' )->willThrowException( self::make_provider_error() );

		$logger = RecordingWcLogger::install();

		$this->create_controller( $api_client )->handle_wcpay_add_fee_breakdown_to_order_notes( $order->get_id(), 'pi_123', false );

		$this->assertCount( 1, $logger->get_errors(), 'A single error should be logged when the timeline read fails.' );
		$context = $this->get_logged_context_at_level( $logger, 'error' );
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
	 * @param Client $api_client Platform API client.
	 * @return WooPaymentsFeeDetailsNoteController
	 */
	private function create_controller( Client $api_client ): WooPaymentsFeeDetailsNoteController {
		$container  = wc_get_container();
		$controller = new WooPaymentsFeeDetailsNoteController();
		$controller->init(
			$container->get( OrderPaymentLifecycleService::class ),
			$container->get( OrderPaymentNotes::class ),
			new WooPaymentsPersistenceVocabulary(),
			$container->get( WooPaymentsActionSchedulerService::class ),
			$container->get( WooPaymentsAccountService::class ),
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
	 * Build a completed event of a payment: a success, or a held payment.
	 *
	 * @param string $payment_reference Payment reference.
	 * @param bool   $held              Whether the event holds the order.
	 * @return PaymentLifecycleEvent
	 */
	private function completed_payment_event( string $payment_reference, bool $held ): PaymentLifecycleEvent {
		return $held
			? new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_COMPLETED, $payment_reference, array(), array(), 'Held ' . $payment_reference . '.', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_HELD, array(), false, true )
			: new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_COMPLETED, $payment_reference, array(), array(), 'Paid ' . $payment_reference . '.', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
	}

	/**
	 * A payment intent as the platform returns it, with one charge of the given type.
	 *
	 * @param string $intent_id   Intent ID.
	 * @param int    $amount      Amount, in minor units.
	 * @param string $charge_type The charge's payment method type, '' for none, or `none` for an intent with no charge.
	 * @param string $currency    Currency.
	 * @return array<string,mixed>
	 */
	private function paid_order_intent( string $intent_id, int $amount, string $charge_type, string $currency = 'usd' ): array {
		$charge = array( 'id' => 'ch_paid' );
		if ( '' !== $charge_type ) {
			$charge['payment_method_details'] = array( 'type' => $charge_type );
		}

		return array(
			'id'       => $intent_id,
			'status'   => 'succeeded',
			'amount'   => $amount,
			'currency' => $currency,
			'charges'  => array( 'data' => 'none' === $charge_type ? array() : array( $charge ) ),
		);
	}

	/**
	 * Apply an event with an intent through one of the controller's entries.
	 *
	 * @param string                   $entry  `plain`, `intent status`, `lock` or `lock with builder`.
	 * @param WC_Order                 $order  Order.
	 * @param PaymentLifecycleEvent    $event  Event.
	 * @param array<string,mixed>|null $intent The intent passed, or null.
	 */
	private function apply_through_entry( string $entry, WC_Order $order, PaymentLifecycleEvent $event, ?array $intent ): void {
		switch ( $entry ) {
			case 'plain':
				$this->sut->apply_and_schedule_fee_details( $order, $event, null, $intent );
				return;
			case 'intent status':
				$this->sut->apply_intent_status_and_schedule_fee_details( $order, $event, null, $intent );
				return;
			case 'lock':
				$this->sut->apply_and_schedule_fee_details_with_lock( $order, $event, null, $intent );
				return;
			default:
				$this->sut->apply_and_schedule_fee_details_with_lock( $order, $event, static fn(): array => array( $event, null ), $intent );
		}
	}

	/**
	 * Get the order's paid-order notes.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private function get_paid_order_notes( WC_Order $order ): array {
		return array_values( array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), static fn( string $note ): bool => false !== strpos( $note, 'already had a paid status' ) ) );
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
