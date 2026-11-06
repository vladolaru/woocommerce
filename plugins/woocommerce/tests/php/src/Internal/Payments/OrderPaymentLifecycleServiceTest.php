<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderPaymentLifecycleService class.
 */
class OrderPaymentLifecycleServiceTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;
	use OrderPaymentLockTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private $sut;

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private $order_payment_lock;

	/**
	 * WooPayments persistence vocabulary.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private WooPaymentsPersistenceVocabulary $persistence_vocabulary;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                    = wc_get_container()->get( OrderPaymentLifecycleService::class );
		$this->order_payment_lock     = wc_get_container()->get( OrderPaymentLock::class );
		$this->persistence_vocabulary = new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * @testdox A $event_status event moves the order to its status, writes its meta and note, and leaves no lock and no lock warning ($_dataName).
	 * @dataProvider status_event_provider
	 *
	 * @param string               $event_status      Lifecycle event status.
	 * @param string               $payment_reference Payment reference.
	 * @param array<string,string> $meta_to_update    Meta the event writes.
	 * @param string[]             $meta_to_delete    Meta keys the event deletes; the order carries them before the event.
	 * @param string|null          $note              Lifecycle note, or null for none.
	 * @param string               $expected_status   Order status after the event.
	 * @param string               $expected_txn_id   Transaction ID after the event.
	 */
	public function test_event_moves_the_order_to_its_status_and_writes_its_meta( string $event_status, string $payment_reference, array $meta_to_update, array $meta_to_delete, ?string $note, string $expected_status, string $expected_txn_id ): void {
		$order = $this->create_woopayments_order();
		foreach ( $meta_to_delete as $key ) {
			$order->update_meta_data( $key, 'stale ' . $key );
		}
		$order->save();

		$this->apply_event( $order, new PaymentLifecycleEvent( $event_status, $payment_reference, $meta_to_update, $meta_to_delete, $note ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( $expected_txn_id, $order->get_transaction_id() );
		foreach ( $meta_to_update as $key => $value ) {
			$this->assertSame( $value, $order->get_meta( $key, true ), "The event must write {$key}." );
		}
		foreach ( $meta_to_delete as $key ) {
			$this->assertSame( '', $order->get_meta( $key, true ), "The event must delete {$key}." );
		}
		if ( null !== $note ) {
			$this->assertOrderHasNote( $order, $note );
		}
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, $payment_reference ), 'The event must release the order payment lock.' );
		$warnings = array_filter(
			$this->captured_logs,
			static fn( $log ) => 'warning' === $log['level'] && str_contains( $log['message'], 'order payment lock refused' )
		);
		$this->assertCount( 0, $warnings, 'An event applied with an available lock must not log a lock-contention warning.' );
	}

	/**
	 * Every lifecycle status, with the order state its event leaves.
	 *
	 * @return array<string,array{string,string,array<string,string>,string[],?string,string,string}>
	 */
	public static function status_event_provider(): array {
		return array(
			'completed'              => array(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_123',
				array(
					'_intent_id'        => 'pi_123',
					'_charge_id'        => 'ch_123',
					'_intention_status' => 'succeeded',
				),
				array(),
				'Payment complete.',
				'completed',
				'pi_123',
			),
			'authorized'             => array( PaymentLifecycleEvent::STATUS_AUTHORIZED, 'pi_auth', array( '_intention_status' => 'requires_capture' ), array(), 'Payment authorized.', 'on-hold', 'pi_auth' ),
			'failed'                 => array( PaymentLifecycleEvent::STATUS_FAILED, 'pi_failed', array( '_intention_status' => 'requires_payment_method' ), array(), 'Payment failed.', 'failed', '' ),
			'canceled'               => array( PaymentLifecycleEvent::STATUS_CANCELED, 'pi_canceled', array( '_intention_status' => 'canceled' ), array( '_wcpay_transaction_fee', '_wcpay_net' ), 'Payment canceled.', 'cancelled', '' ),
			'capture expired'        => array( PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED, 'ch_expired', array( '_charge_id' => 'ch_expired' ), array(), 'Payment authorization expired.', 'failed', '' ),
			'started'                => array( PaymentLifecycleEvent::STATUS_STARTED, 'pi_started', array( '_intent_id' => 'pi_started' ), array(), 'Payment started.', 'pending', 'pi_started' ),
			'started without a note' => array( PaymentLifecycleEvent::STATUS_STARTED, 'pi_started', array( '_intent_id' => 'pi_started' ), array(), null, 'pending', 'pi_started' ),
		);
	}

	/**
	 * @testdox A normal completed event preserves unsaved changes owned by its caller.
	 */
	public function test_under_lock_completed_event_preserves_caller_owned_unsaved_changes(): void {
		$order = $this->create_woopayments_order();
		$order->set_customer_note( 'Checkout note saved with payment completion.' );
		$order->update_meta_data( '_caller_owned_unsaved_meta', 'preserve this value' );

		$this->assertNotNull( $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, 'pi_caller_changes', 'payment operation' ) );
		try {
			$this->sut->apply_under_lock( $order, $this->completed_event( 'pi_caller_changes' ), $this->persistence_vocabulary );
		} finally {
			$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'Checkout note saved with payment completion.', $order->get_customer_note() );
		$this->assertSame( 'preserve this value', $order->get_meta( '_caller_owned_unsaved_meta', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
	}

	/**
	 * @testdox Completed events received after a dispute under the $dispute_key key keep the order on hold.
	 * @testWith ["_wcpay_open_dispute_ids"]
	 *           ["_provider_open_dispute_ids"]
	 *
	 * @param string $dispute_key Open dispute IDs meta key of the provider's vocabulary.
	 */
	public function test_success_after_dispute_created_keeps_on_hold( string $dispute_key ): void {
		$order      = $this->create_woopayments_order();
		$vocabulary = $this->vocabulary_with_dispute_key( $order, $dispute_key );
		$order->update_meta_data( $dispute_key, array( 'dp_1' ) );
		$order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$order->update_status( 'on-hold' );
		$order->save();

		$this->sut->apply( $order, $this->completed_event( 'pi_1' ), $vocabulary );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A late success event must preserve the dispute hold.' );
		$this->assertSame( array( 'dp_1' ), $order->get_meta( $dispute_key, true ), 'The open dispute record must remain unchanged.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A skipped success event must not update lifecycle metadata.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
		$this->assertLogged(
			'debug',
			'an open dispute',
			array(
				'source'     => 'order-payments',
				'order_id'   => $order->get_id(),
				'event_type' => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'     => 'open_dispute',
			)
		);
	}

	/**
	 * @testdox A completed-event skip brings the dispute persisted under $dispute_key onto a stale caller ($caller_state), whose save then keeps the dispute and the hold.
	 * @testWith ["_wcpay_open_dispute_ids", "sets processing"]
	 *           ["_provider_open_dispute_ids", "sets processing"]
	 *           ["_wcpay_open_dispute_ids", "still pending"]
	 *           ["_wcpay_open_dispute_ids", "already on hold"]
	 *
	 * @param string $dispute_key  Open dispute IDs meta key of the provider's vocabulary.
	 * @param string $caller_state What the caller's object holds: a status change of its own to processing, the pending
	 *                             status it loaded, or the on-hold status of an authorization, which matches the stored
	 *                             order so only its dispute IDs differ.
	 */
	public function test_under_lock_success_skip_prevents_a_stale_caller_save_from_overwriting_a_dispute( string $dispute_key, string $caller_state ): void {
		$stale_order = $this->create_woopayments_order();
		if ( 'already on hold' === $caller_state ) {
			$stale_order->update_status( 'on-hold' );
		}
		$vocabulary  = $this->vocabulary_with_dispute_key( $stale_order, $dispute_key );
		$fresh_order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$fresh_order->update_meta_data( $dispute_key, array( 'dp_persisted_winner' ) );
		$fresh_order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$fresh_order->update_status( 'on-hold' );

		if ( 'sets processing' === $caller_state ) {
			$stale_order->set_status( 'processing' );
		}
		// A dispute-closed handler that clears an ID on its copy and then applies a completion.
		$stale_order->update_meta_data( $dispute_key, array() );
		$this->assertNotNull( $this->order_payment_lock->claim( $stale_order, $vocabulary, 'pi_stale_save', 'payment operation' ) );
		try {
			$this->sut->apply_under_lock( $stale_order, $this->completed_event( 'pi_stale_save' ), $vocabulary );
			$on_hold_transitions_before = did_action( 'woocommerce_order_status_pending_to_on-hold' );
			$status_changed_before      = did_action( 'woocommerce_order_status_changed' );
			$stale_order->save();
		} finally {
			$this->clear_order_payment_lock( $stale_order, $vocabulary );
		}

		$order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A stale caller must not restore its pre-dispute status.' );
		$this->assertSame( array( 'dp_persisted_winner' ), $order->get_meta( $dispute_key, true ), 'A stale caller must not erase persisted open disputes.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A skipped success event must not update lifecycle metadata.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
		if ( 'sets processing' !== $caller_state ) {
			// A caller's own status change keeps its transition through the re-read, so only the other callers are counted.
			$this->assertSame( $on_hold_transitions_before, did_action( 'woocommerce_order_status_pending_to_on-hold' ), 'The stale caller save must not fire the pending to on-hold transition again.' );
			$this->assertSame( $status_changed_before, did_action( 'woocommerce_order_status_changed' ), 'The stale caller save must not fire a status change.' );
		}
	}

	/**
	 * @testdox A completed event skipped because another request already completed the order does not repeat the status change when its stale caller saves.
	 */
	public function test_under_lock_success_note_skip_does_not_repeat_the_status_change_when_a_stale_caller_saves(): void {
		// The skip leaves the caller's order object as client 11.1.0 includes/class-wc-payments-order-service.php:2863-2879 does: its replay guard reads a clone.
		$stale_order = $this->create_woopayments_order();
		$stale_order->add_product( \WC_Helper_Product::create_simple_product(), 1 );
		$stale_order->save();
		$order_id = $stale_order->get_id();

		$webhook_order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $webhook_order );
		$this->sut->apply( $webhook_order, $this->completed_event( 'pi_stale_success' ), $this->persistence_vocabulary );
		$webhook_order->set_date_paid( time() - HOUR_IN_SECONDS );
		$webhook_order->save();
		$persisted_date_paid = $webhook_order->get_date_paid();
		$this->assertNotNull( $persisted_date_paid );
		$this->assertSame( 'wc-processing', $this->get_persisted_order_status( $order_id ) );

		$status_change_note         = sprintf( 'Order status changed from %1$s to %2$s.', wc_get_order_status_name( 'pending' ), wc_get_order_status_name( 'processing' ) );
		$status_change_notes_before = $this->countOrderNotesMatching( $stale_order, $status_change_note );

		$this->assertNotNull( $this->order_payment_lock->claim( $stale_order, $this->persistence_vocabulary, 'pi_stale_success', 'payment operation' ) );
		try {
			$this->sut->apply_under_lock( $stale_order, $this->completed_event( 'pi_stale_success' ), $this->persistence_vocabulary );
			$pending_to_processing_before = did_action( 'woocommerce_order_status_pending_to_processing' );
			$status_changed_before        = did_action( 'woocommerce_order_status_changed' );
			$stale_order->save();
		} finally {
			$this->clear_order_payment_lock( $stale_order, $this->persistence_vocabulary );
		}

		$this->assertSame( $pending_to_processing_before, did_action( 'woocommerce_order_status_pending_to_processing' ), 'The stale caller save must not fire the pending to processing transition again.' );
		$this->assertSame( $status_changed_before, did_action( 'woocommerce_order_status_changed' ), 'The stale caller save must not fire a status change.' );
		$this->assertSame( $status_change_notes_before, $this->countOrderNotesMatching( $stale_order, $status_change_note ), 'The stale caller save must not add a second status change note.' );
		$this->assertSame( 'wc-processing', $this->get_persisted_order_status( $order_id ), 'The stale caller save must keep the persisted status.' );

		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertNotNull( $order->get_date_paid() );
		$this->assertSame( $persisted_date_paid->getTimestamp(), $order->get_date_paid()->getTimestamp(), 'The stale caller save must not move the paid date.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
	}

	/**
	 * @testdox A completed event skipped because its note is already on the order keeps a matching caller's unsaved changes for its save.
	 */
	public function test_under_lock_success_note_skip_keeps_a_matching_caller_unsaved_changes(): void {
		$order = $this->create_woopayments_order();
		$this->sut->apply( $order, $this->completed_event( 'pi_matching_caller' ), $this->persistence_vocabulary );
		$order_id = $order->get_id();

		$caller = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $caller );
		$this->assertTrue( $caller->is_paid() );
		$this->assertSame( $this->get_persisted_order_status( $order_id ), 'wc-' . $caller->get_status(), 'The caller must match the persisted status.' );
		$caller->set_customer_note( 'Leave the parcel at the door.' );
		$caller->update_meta_data( '_caller_unsaved_meta', 'kept' );

		$this->assertNotNull( $this->order_payment_lock->claim( $caller, $this->persistence_vocabulary, 'pi_matching_caller', 'payment operation' ) );
		try {
			$this->sut->apply_under_lock( $caller, $this->completed_event( 'pi_matching_caller' ), $this->persistence_vocabulary );
			$caller->save();
		} finally {
			$this->clear_order_payment_lock( $caller, $this->persistence_vocabulary );
		}

		$persisted = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $persisted );
		$this->assertSame( 'Leave the parcel at the door.', $persisted->get_customer_note(), 'The caller save must persist its customer note.' );
		$this->assertSame( 'kept', $persisted->get_meta( '_caller_unsaved_meta', true ), 'The caller save must persist its meta value.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $persisted, 'Payment complete.' ), 'The skipped event must not add a second success note.' );
	}

	/**
	 * @testdox A completed event sees a dispute hold that another request persisted after this request cached the order.
	 */
	public function test_success_sees_a_dispute_persisted_by_another_request_after_the_order_was_cached(): void {
		UncachedOrderWriter::enable_hpos_data_caching();
		$stale_order = $this->read_order_into_request_caches( $this->create_woopayments_order() );

		UncachedOrderWriter::write( $stale_order->get_id(), array( 'status' => 'on-hold' ), array( '_wcpay_open_dispute_ids' => maybe_serialize( array( 'dp_other_request' ) ) ) );

		$this->sut->apply( $stale_order, $this->completed_event( 'pi_other_request' ), $this->persistence_vocabulary );

		$this->assertSame( 'wc-on-hold', $this->get_persisted_order_status( $stale_order->get_id() ), 'A success event must not move an order out of a dispute hold persisted by another request.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $stale_order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
		$this->assertLogged(
			'debug',
			'an open dispute',
			array(
				'order_id' => $stale_order->get_id(),
				'reason'   => 'open_dispute',
			)
		);
	}

	/**
	 * @testdox A late failure event sees a paid status that another request persisted after this request cached the order.
	 */
	public function test_late_failure_sees_a_paid_status_persisted_by_another_request_after_the_order_was_cached(): void {
		UncachedOrderWriter::enable_hpos_data_caching();
		$stale_order = $this->read_order_into_request_caches( $this->create_woopayments_order() );

		UncachedOrderWriter::write( $stale_order->get_id(), array( 'status' => 'processing' ) );

		$this->sut->apply(
			$stale_order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_FAILED,
				'pi_failed_after_paid',
				array( '_intention_status' => 'requires_payment_method' ),
				array(),
				'Late payment failure.',
				'late_payment_failure'
			),
			$this->persistence_vocabulary
		);

		$this->assertSame( 'wc-processing', $this->get_persisted_order_status( $stale_order->get_id() ), 'A late failure event must not downgrade an order another request already marked paid.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $stale_order, 'Late payment failure.' ), 'A skipped late failure event must not add a note.' );
	}

	/**
	 * @testdox Replayed completed events preserve the state established after their first application.
	 */
	public function test_success_is_applied_once_then_ignored(): void {
		$order = $this->create_woopayments_order();
		$event = $this->completed_event( 'pi_1' );

		$this->sut->apply( $order, $event, $this->persistence_vocabulary );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );

		$order->update_meta_data( '_wcpay_transaction_fee', '2.34' );
		$order->update_status( 'on-hold' );
		$order->save();

		$this->sut->apply( $order, $event, $this->persistence_vocabulary );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A replayed success event must not re-complete the order.' );
		$this->assertSame( 'pi_1', $order->get_transaction_id(), 'A replayed success event must not change the transaction reference.' );
		$this->assertSame( '2.34', $order->get_meta( '_wcpay_transaction_fee', true ), 'A replayed success event must not overwrite lifecycle metadata.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A replayed success event must not add another completion note.' );
		$this->assertLogged(
			'debug',
			'the payment note is already on the order',
			array(
				'source'     => 'order-payments',
				'order_id'   => $order->get_id(),
				'event_type' => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'     => 'success_note_exists',
			)
		);
	}

	/**
	 * @testdox A payment success replay with an existing equivalent note backfills its stable identity before lifecycle mutation.
	 */
	public function test_payment_success_replay_with_an_existing_equivalent_note_backfills_identity(): void {
		$order         = $this->create_woopayments_order();
		$note          = 'Core payment success note.';
		$existing_note = 'Plugin payment success note.';
		$order->add_order_note( $existing_note );
		$order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$order->update_status( 'on-hold' );
		$order->save();

		$event = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			'pi_legacy_existing',
			array(
				'_intent_id'        => 'pi_legacy_existing',
				'_intention_status' => 'succeeded',
			),
			array(),
			$note,
			PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS,
			array( $existing_note )
		);

		$this->sut->apply( $order, $event, $this->persistence_vocabulary );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'An existing equivalent note must prevent a completed event from changing status.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'An existing equivalent note must prevent lifecycle metadata mutation.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, $existing_note ), 'A replay must not duplicate the existing equivalent note.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, $note ), 'A replay must not add the Core note.' );

		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $order_note ): bool => $existing_note === (string) $order_note->content
			)
		);
		$this->assertCount( 1, $notes );
		$this->assertSame( hash( 'sha256', 'payment_lifecycle:pi_legacy_existing|completed|payment_success' ), get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', true ), 'Canonical persisted-note detection must backfill the stable identity.' );
	}

	/**
	 * @testdox Completed events update paid orders without adding duplicate generic completion notes.
	 */
	public function test_completed_event_updates_paid_order_without_generic_completion_note(): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_123' );
		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( 'processing', $order->get_status() );

		$this->apply_event_under_lock(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_123',
				array(
					'_intent_id'             => 'pi_123',
					'_wcpay_transaction_fee' => '1.23',
				),
				array(),
				'Payment complete.',
				'payment_complete'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
	}

	/**
	 * @testdox Late failure events do not downgrade paid processing orders.
	 * @dataProvider paid_order_late_failure_event_provider
	 *
	 * @param string $event_status      Lifecycle event status.
	 * @param string $payment_reference Payment reference.
	 * @param string $late_intent_status Late provider intent status.
	 * @param string $note              Lifecycle note.
	 * @param string $note_type         Lifecycle note type.
	 * @param bool   $preserve_status   Whether the event keeps the order status, as a fraud block does.
	 */
	public function test_late_failure_event_does_not_downgrade_paid_processing_order( string $event_status, string $payment_reference, string $late_intent_status, string $note, string $note_type, bool $preserve_status ): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_paid' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_wcpay_transaction_fee', '1.23' );
		$order->update_meta_data( '_wcpay_net', '8.77' );
		$order->save();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				$event_status,
				$payment_reference,
				array( '_intention_status' => $late_intent_status ),
				array( '_wcpay_transaction_fee', '_wcpay_net' ),
				$note,
				$note_type,
				array(),
				$preserve_status
			)
		);

		$order = wc_get_order( $order->get_id() );

		// A status-preserving event leaves the status alone either way, so its meta and note assertions carry the case.
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status(), "{$event_status} must not downgrade a paid order." );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ), "{$event_status} must not overwrite successful lifecycle metadata." );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ), "{$event_status} must not delete payment fee metadata." );
		$this->assertSame( '8.77', $order->get_meta( '_wcpay_net', true ), "{$event_status} must not delete payment net metadata." );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, $note ), "{$event_status} must not add a late lifecycle note." );
	}

	/**
	 * @testdox A late $event_status event on a paid order delivered twice leaves $expected_notes note naming the amount and $expected_warnings warnings.
	 *
	 * A released or expired authorization is noted once and warned about on every delivery; a failed attempt moved no
	 * money on the paid order, so it stays skipped silently, as in client 11.1.0.
	 *
	 * @testWith ["failed", "pi_failed_late", "", 0, 0]
	 *           ["canceled", "pi_canceled_late", "was canceled, so that amount was released", 1, 2]
	 *           ["capture_expired", "ch_expired_late", "expired before it was captured, so that amount was never collected", 1, 2]
	 *
	 * @param string $event_status      Lifecycle event status.
	 * @param string $payment_reference Payment reference.
	 * @param string $note_text         Text the note carries, or '' when none is expected.
	 * @param int    $expected_notes    Number of authorization notes after both deliveries.
	 * @param int    $expected_warnings Number of order-payments warnings after both deliveries.
	 */
	public function test_late_release_on_a_paid_order_is_noted_once_and_warned_each_time( string $event_status, string $payment_reference, string $note_text, int $expected_notes, int $expected_warnings ): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_paid' );
		$order->set_status( 'processing' );
		$order->save();
		$event = new PaymentLifecycleEvent( $event_status, $payment_reference, array( '_intention_status' => 'canceled' ), array(), 'Late payment event.', 'late_payment_event' );

		$this->apply_event( $order, $event );
		$this->apply_event( wc_get_order( $order->get_id() ), $event );

		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'payment authorization of' )
			)
		);
		$this->assertCount( $expected_notes, $notes, 'An authorization is noted once, however often its event arrives.' );
		if ( 0 < $expected_notes ) {
			$this->assertStringContainsString( $note_text, $notes[0]->content );
			$this->assertStringContainsString( '10.00', $notes[0]->content, 'The note names the authorized amount.' );
		}
		$warnings = array_values( array_filter( $this->captured_logs, static fn( array $log ): bool => 'warning' === $log['level'] && 'order-payments' === ( $log['context']['source'] ?? '' ) ) );
		$this->assertCount( $expected_warnings, $warnings, 'Every delivery of a released or expired authorization is warned about.' );
		foreach ( $warnings as $warning ) {
			$this->assertSame( $payment_reference, $warning['context']['payment_reference'] );
			$this->assertSame( '10.00', $warning['context']['amount'] );
		}
		$this->assertSame( 0, $this->countOrderNotesMatching( wc_get_order( $order->get_id() ), 'Late payment event.' ), 'The skipped event adds no lifecycle note.' );
	}

	/**
	 * Data provider for late failure events received after payment.
	 *
	 * @return array<string,array{string,string,string,string,string,bool}>
	 */
	public static function paid_order_late_failure_event_provider(): array {
		return array(
			'failed'                   => array( PaymentLifecycleEvent::STATUS_FAILED, 'pi_failed_late', 'requires_payment_method', 'Late payment failure.', 'late_payment_failure', false ),
			'canceled'                 => array( PaymentLifecycleEvent::STATUS_CANCELED, 'pi_canceled_late', 'canceled', 'Late payment cancellation.', 'late_payment_cancellation', false ),
			'capture expired'          => array( PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED, 'ch_expired_late', 'canceled', 'Late payment authorization expiry.', 'late_capture_expiry', false ),
			'failed, status preserved' => array( PaymentLifecycleEvent::STATUS_FAILED, 'pi_blocked_late', 'canceled', 'A payment was blocked by risk filters.', 'payment_blocked', true ),
		);
	}

	/**
	 * @testdox Event meta is in the database before the order save that applies a $event_status event: $expected.
	 * @dataProvider meta_saved_before_order_save_provider
	 *
	 * @param string $event_status Lifecycle event status.
	 * @param bool   $expected     Whether the meta is persisted before the order save.
	 */
	public function test_event_meta_is_saved_before_the_order_save_for_every_status_but_started( string $event_status, bool $expected ): void {
		$order          = $this->create_woopayments_order();
		$order_id       = $order->get_id();
		$meta_persisted = null;
		$observer       = function ( $saved_order ) use ( $order_id, &$meta_persisted ): void {
			if ( null === $meta_persisted && $saved_order instanceof WC_Order && $saved_order->get_id() === $order_id ) {
				$meta_persisted = $this->is_order_meta_persisted( $order_id, '_lifecycle_probe' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $observer );

		try {
			$this->apply_event_under_lock( $order, new PaymentLifecycleEvent( $event_status, 'pi_probe', array( '_lifecycle_probe' => 'saved' ) ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $observer );
		}

		$this->assertSame( $expected, $meta_persisted );
		$this->assertTrue( $this->is_order_meta_persisted( $order_id, '_lifecycle_probe' ), 'Every event saves its meta by the end.' );
	}

	/**
	 * Every lifecycle status and whether its meta is saved before the order save.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function meta_saved_before_order_save_provider(): array {
		return array(
			'completed'       => array( PaymentLifecycleEvent::STATUS_COMPLETED, true ),
			'authorized'      => array( PaymentLifecycleEvent::STATUS_AUTHORIZED, true ),
			'failed'          => array( PaymentLifecycleEvent::STATUS_FAILED, true ),
			'canceled'        => array( PaymentLifecycleEvent::STATUS_CANCELED, true ),
			'capture expired' => array( PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED, true ),
			'started'         => array( PaymentLifecycleEvent::STATUS_STARTED, false ),
		);
	}

	/**
	 * @testdox Lifecycle events lock the order with the supplied provider persistence vocabulary.
	 */
	public function test_apply_uses_provider_vocabulary_for_locks(): void {
		$order      = $this->create_woopayments_order();
		$vocabulary = $this->createMock( ProviderPersistenceVocabularyInterface::class );
		$vocabulary->method( 'get_order_lock_key' )->willReturn( 'provider_lifecycle_lock_' . $order->get_id() );
		$vocabulary->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$vocabulary->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$lock_token = $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, 'woopayments_operation', 'payment operation' );
		$this->assertNotNull( $lock_token );

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'provider_payment_123',
				array(),
				array(),
				'Provider payment started.'
			),
			$vocabulary
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Provider payment started.' ), 'A lock held under another provider key must not block the event.' );
		$this->assertFalse( get_transient( 'provider_lifecycle_lock_' . $order->get_id() ), 'The provider lock must be released after the event.' );
		$this->order_payment_lock->release( $order, $this->persistence_vocabulary, $lock_token );
	}

	/**
	 * @testdox Lifecycle note identities use stable note type instead of rendered note content.
	 */
	public function test_lifecycle_note_identity_uses_note_type_instead_of_rendered_note_content(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event_under_lock(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_started',
				array( '_intent_id' => 'pi_started' ),
				array(),
				'Payment started.',
				'payment_started'
			)
		);

		$order             = wc_get_order( $order->get_id() );
		$expected_identity = hash( 'sha256', 'payment_lifecycle:pi_started|started|payment_started' );
		$notes             = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $notes );
		$this->assertSame( $expected_identity, get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', true ) );

		$this->apply_event_under_lock(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_started',
				array( '_intent_id' => 'pi_started' ),
				array(),
				'Zahlung gestartet.',
				'payment_started'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment started.' ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Zahlung gestartet.' ) );
	}

	/**
	 * @testdox An existing equivalent lifecycle note gets the unified identity backfilled instead of a second note.
	 */
	public function test_equivalent_lifecycle_note_backfills_unified_identity(): void {
		$order          = $this->create_woopayments_order();
		$legacy_note_id = $order->add_order_note( 'Payment started.' );

		$this->apply_event_under_lock(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_legacy',
				array( '_intent_id' => 'pi_legacy' ),
				array(),
				'Zahlung gestartet.',
				'payment_started',
				array( 'Payment started.' )
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment started.' ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Zahlung gestartet.' ) );
		$this->assertSame(
			hash( 'sha256', 'payment_lifecycle:pi_legacy|started|payment_started' ),
			get_comment_meta( $legacy_note_id, '_wc_woopayments_note_identity', true )
		);
	}

	/**
	 * @testdox A locked order is not mutated or unlocked by an event for $event_reference while the lock holds $held_reference.
	 * @testWith ["pi_locked", "pi_locked"]
	 *           ["native_charge_operation", "pi_webhook"]
	 *
	 * @param string $held_reference  Payment reference of the operation that claimed the lock.
	 * @param string $event_reference Payment reference of the event.
	 */
	public function test_locked_order_is_not_mutated_or_unlocked( string $held_reference, string $event_reference ): void {
		$order = $this->create_woopayments_order();
		$this->assertNotNull( $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, $held_reference, 'payment operation' ) );

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				$event_reference,
				array( '_intent_id' => $event_reference ),
				array(),
				'Payment complete.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
		$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, $held_reference ), 'A refused event must leave the holder its lock.' );
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox Applying an event should release the order payment lock when applying the outcome throws.
	 */
	public function test_apply_releases_the_lock_when_applying_the_outcome_throws(): void {
		$order = $this->create_woopayments_order();
		$sut   = new class() extends OrderPaymentLifecycleService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail every outcome application.
			 *
			 * @param WC_Order                                                                         $order               Order object.
			 * @param PaymentLifecycleEvent                                                            $event               Lifecycle event.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
			 * @throws \RuntimeException Always.
			 */
			public function apply_under_lock( WC_Order $order, PaymentLifecycleEvent $event, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $persistence_vocabulary ): string {
				unset( $order, $event, $persistence_vocabulary );
				throw new \RuntimeException( 'Simulated lifecycle failure.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$sut->init( $this->order_payment_lock, wc_get_container()->get( OrderPaymentNotes::class ) );

		$thrown = null;
		try {
			$sut->apply( $order, $this->completed_event( 'pi_apply_throws' ), $this->persistence_vocabulary );
		} catch ( \RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'The application failure must reach the caller.' );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'A throwing application must release the order payment lock.' );
		$lock_token = $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, 'next_operation', 'payment operation' );
		$this->assertNotNull( $lock_token, 'The next operation must be able to claim the lock.' );
		$this->order_payment_lock->release( $order, $this->persistence_vocabulary, $lock_token );
	}

	/**
	 * @testdox A lifecycle event skipped because the order is lock-contested is logged as a warning with full context.
	 */
	public function test_skipped_locked_event_is_logged_with_context(): void {
		$order = $this->create_woopayments_order();
		$this->assertNotNull( $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, 'native_charge_operation', 'payment operation' ) );

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_webhook',
				array( '_intent_id' => 'pi_webhook' ),
				array(),
				'Payment complete.'
			)
		);

		$this->assertLogged(
			'warning',
			'order payment lock refused: order ',
			array(
				'source'            => 'order-payments',
				'order_id'          => $order->get_id(),
				'payment_reference' => 'pi_webhook',
				'event_type'        => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'            => 'order_locked',
				'refused_operation' => 'payment status update',
			)
		);

		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox The result tells whether the event first added its note: added on the first delivery, not added on a replay, when the order has the note by its text or when the event has no note, and refused under a held lock.
	 */
	public function test_apply_reports_whether_it_first_added_the_note(): void {
		$order = $this->create_woopayments_order();
		$this->assertSame( OrderPaymentLifecycleService::RESULT_NOTE_ADDED, $this->sut->apply( $order, $this->paid_event( 'pi_result' ), $this->persistence_vocabulary ) );
		$this->assertSame( OrderPaymentLifecycleService::RESULT_NOTE_NOT_ADDED, $this->sut->apply( $order, $this->paid_event( 'pi_result' ), $this->persistence_vocabulary ), 'A replay finds the note it added.' );

		$text_order = $this->create_woopayments_order();
		$text_order->add_order_note( 'Paid by pi_text.' );
		$this->assertSame( OrderPaymentLifecycleService::RESULT_NOTE_NOT_ADDED, $this->sut->apply( $text_order, $this->paid_event( 'pi_text' ), $this->persistence_vocabulary ), 'A note found by its text is not added again.' );

		$no_note_order = $this->create_woopayments_order();
		$this->assertSame( OrderPaymentLifecycleService::RESULT_NOTE_NOT_ADDED, $this->sut->apply_under_lock( $no_note_order, new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_STARTED, 'pi_no_note' ), $this->persistence_vocabulary ) );

		$locked_order = $this->create_woopayments_order();
		$this->hold_order_payment_lock( $locked_order, $this->persistence_vocabulary, 'native_charge_operation' );
		try {
			$this->assertSame( OrderPaymentLifecycleService::RESULT_REFUSED, $this->sut->apply( $locked_order, $this->paid_event( 'pi_locked_result' ), $this->persistence_vocabulary ) );
		} finally {
			$this->clear_order_payment_lock( $locked_order, $this->persistence_vocabulary );
		}
	}

	/**
	 * @testdox An event without a payment reference applies without the order payment lock, even while another operation holds it.
	 */
	public function test_event_without_a_payment_reference_applies_without_the_lock(): void {
		$order = $this->create_woopayments_order();
		$this->assertNotNull( $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, 'native_charge_operation', 'payment operation' ) );

		try {
			$result = $this->sut->apply( $order, new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_STARTED, null, array(), array(), 'Payment started.' ), $this->persistence_vocabulary );

			$this->assertSame( OrderPaymentLifecycleService::RESULT_NOTE_ADDED, $result );
			$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, 'native_charge_operation' ), 'The holder keeps its lock.' );
		} finally {
			$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
		}

		$this->assertSame( 1, $this->countOrderNotesMatching( wc_get_order( $order->get_id() ), 'Payment started.' ) );
	}

	/**
	 * Apply a lifecycle event with the WooPayments vocabulary unless a test supplies another one.
	 *
	 * @param WC_Order                                    $order               Order object.
	 * @param PaymentLifecycleEvent                       $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface|null $persistence_vocabulary Optional provider vocabulary.
	 */
	private function apply_event( WC_Order $order, PaymentLifecycleEvent $event, ?ProviderPersistenceVocabularyInterface $persistence_vocabulary = null ): void {
		$this->sut->apply( $order, $event, $persistence_vocabulary ?? $this->persistence_vocabulary );
	}

	/**
	 * Get the WooPayments vocabulary, or a provider vocabulary of its own that keeps open dispute IDs under another key.
	 *
	 * @param WC_Order $order       Order the provider vocabulary locks.
	 * @param string   $dispute_key Open dispute IDs meta key.
	 * @return ProviderPersistenceVocabularyInterface
	 */
	private function vocabulary_with_dispute_key( WC_Order $order, string $dispute_key ): ProviderPersistenceVocabularyInterface {
		if ( $this->persistence_vocabulary->get_open_dispute_ids_meta_key() === $dispute_key ) {
			return $this->persistence_vocabulary;
		}

		$vocabulary = $this->createMock( ProviderPersistenceVocabularyInterface::class );
		$vocabulary->method( 'get_order_lock_key' )->willReturn( 'provider_lifecycle_lock_' . $order->get_id() );
		$vocabulary->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$vocabulary->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$vocabulary->method( 'get_open_dispute_ids_meta_key' )->willReturn( $dispute_key );

		return $vocabulary;
	}

	/**
	 * Apply a lifecycle event under the caller's lock with the WooPayments vocabulary.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	private function apply_event_under_lock( WC_Order $order, PaymentLifecycleEvent $event ): void {
		$this->sut->apply_under_lock( $order, $event, $this->persistence_vocabulary );
	}

	/**
	 * Create a completed lifecycle event with mutable payment metadata.
	 *
	 * @param string $payment_reference Provider payment reference.
	 * @return PaymentLifecycleEvent
	 */
	private function completed_event( string $payment_reference ): PaymentLifecycleEvent {
		return new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			$payment_reference,
			array(
				'_intent_id'             => $payment_reference,
				'_intention_status'      => 'succeeded',
				'_wcpay_transaction_fee' => '1.23',
			),
			array(),
			'Payment complete.',
			PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE
		);
	}

	/**
	 * Build a completed event whose success note names its payment.
	 *
	 * @param string $payment_reference Payment reference.
	 * @return PaymentLifecycleEvent
	 */
	private function paid_event( string $payment_reference ): PaymentLifecycleEvent {
		return new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_COMPLETED, $payment_reference, array(), array(), 'Paid by ' . $payment_reference . '.', PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS );
	}

	/**
	 * Create a WooPayments order for lifecycle tests.
	 *
	 * @return WC_Order
	 */
	private function create_woopayments_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->save();

		return $order;
	}

	/**
	 * Read an order through its data store, so this request caches its row and meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Order The order instance a caller of this request holds.
	 */
	private function read_order_into_request_caches( WC_Order $order ): WC_Order {
		$cached_order = clone $order;
		/**
		 * Order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $cached_order->get_data_store();
		$data_store->read( $cached_order );
		/**
		 * Order read through its data store.
		 *
		 * @var WC_Order $cached_order
		 */

		return $cached_order;
	}

	/**
	 * Read an order status straight from the database, bypassing every cache.
	 *
	 * @param int $order_id Order ID.
	 * @return string Stored status, with its wc- prefix.
	 */
	private function get_persisted_order_status( int $order_id ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The assertion must see the database, not a cache.
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders_table = OrdersTableDataStore::get_orders_table_name();
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$orders_table} WHERE id = %d", $order_id ) );
		}

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tell whether an order meta key is stored in the database, bypassing every cache.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private function is_order_meta_persisted( int $order_id, string $meta_key ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The assertion must see the database, not a cache.
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$meta_table = OrdersTableDataStore::get_meta_table_name();
			return null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = %s", $order_id, $meta_key ) );
		}

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $order_id, $meta_key ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	/**
	 * Assert that an order has a note with the expected exact content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assertOrderHasNote( WC_Order $order, string $expected ): void {
		$this->assertGreaterThan( 0, $this->countOrderNotesMatching( $order, $expected ), "Missing order note: {$expected}" );
	}

	/**
	 * Count order notes with exact content.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 * @return int
	 */
	private function countOrderNotesMatching( WC_Order $order, string $expected ): int {
		$count = 0;
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( $expected === $note->content ) {
				++$count;
			}
		}

		return $count;
	}
}
