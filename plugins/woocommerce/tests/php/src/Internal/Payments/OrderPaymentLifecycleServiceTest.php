<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
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
	 * Order payment store.
	 *
	 * @var OrderPaymentStore
	 */
	private $order_payment_store;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private WooPaymentsPersistenceVocabulary $persistence_profile;

	/**
	 * Test-only gettext replacements.
	 *
	 * @var array<string,string>
	 */
	private array $gettext_replacements = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                 = wc_get_container()->get( OrderPaymentLifecycleService::class );
		$this->order_payment_store = wc_get_container()->get( OrderPaymentStore::class );
		$this->persistence_profile = new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'gettext', array( $this, 'translate_woocommerce_test_string' ), 10 );
		restore_current_locale();
		$this->gettext_replacements = array();
		parent::tearDown();
	}

	/**
	 * @testdox Completed events mark the order paid and preserve payment meta.
	 */
	public function test_completed_event_marks_order_paid_and_preserves_meta(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_123',
				array(
					'_intent_id'        => 'pi_123',
					'_charge_id'        => 'ch_123',
					'_intention_status' => 'succeeded',
				),
				array(),
				'Payment complete.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_123', $order->get_transaction_id() );
		$this->assertSame( 'pi_123', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_123', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertOrderHasNote( $order, 'Payment complete.' );
	}

	/**
	 * @testdox A normal completed event preserves unsaved changes owned by its caller.
	 */
	public function test_unlocked_completed_event_preserves_caller_owned_unsaved_changes(): void {
		$order = $this->create_woopayments_order();
		$order->set_customer_note( 'Checkout note saved with payment completion.' );
		$order->update_meta_data( '_caller_owned_unsaved_meta', 'preserve this value' );

		$this->assertNotNull( $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'pi_caller_changes', 'payment operation' ) );
		try {
			$this->sut->apply_unlocked( $order, $this->completed_event( 'pi_caller_changes' ), $this->persistence_profile );
		} finally {
			$this->clear_order_payment_lock( $order, $this->persistence_profile );
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
	 * @testdox Completed events received after a dispute keep the order on hold.
	 */
	public function test_success_after_dispute_created_keeps_on_hold(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_1' ) );
		$order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$order->update_status( 'on-hold' );
		$order->save();

		$this->sut->apply( $order, $this->completed_event( 'pi_1' ), $this->persistence_profile );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A late success event must preserve the dispute hold.' );
		$this->assertSame( array( 'dp_1' ), $order->get_meta( '_wcpay_open_dispute_ids', true ), 'The open dispute record must remain unchanged.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A skipped success event must not update lifecycle metadata.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
		$this->assertLogged(
			'debug',
			'open WooPayments dispute',
			array(
				'source'     => 'native-payments-webhook',
				'order_id'   => $order->get_id(),
				'event_type' => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'     => 'open_dispute',
			)
		);
	}

	/**
	 * @testdox A completed event refreshes disputed state that was persisted after its order object loaded.
	 */
	public function test_success_after_dispute_created_through_a_second_order_instance_keeps_on_hold(): void {
		$stale_order = $this->create_woopayments_order();

		$fresh_order = clone $stale_order;
		/**
		 * Fresh order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $fresh_order->get_data_store();
		$data_store->read( $fresh_order );
		$fresh_order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_stale' ) );
		$fresh_order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$fresh_order->update_status( 'on-hold' );

		$this->sut->apply( $stale_order, $this->completed_event( 'pi_stale' ), $this->persistence_profile );

		$order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A success event must refresh and preserve a dispute hold written by another order instance.' );
		$this->assertSame( array( 'dp_stale' ), $order->get_meta( '_wcpay_open_dispute_ids', true ), 'A success event must preserve freshly persisted open disputes.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A skipped success event must not update lifecycle metadata.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
	}

	/**
	 * @testdox A completed event refreshes disputed state when its caller already owns the order payment lock.
	 */
	public function test_unlocked_success_after_dispute_created_through_a_second_order_instance_keeps_on_hold(): void {
		$stale_order = $this->create_woopayments_order();

		$fresh_order = clone $stale_order;
		/**
		 * Fresh order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $fresh_order->get_data_store();
		$data_store->read( $fresh_order );
		$fresh_order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_stale_unlocked' ) );
		$fresh_order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$fresh_order->update_status( 'on-hold' );

		$this->assertNotNull( $this->order_payment_store->claim_order_payment_lock_for_operation( $stale_order, $this->persistence_profile, 'pi_stale_unlocked', 'payment operation' ) );
		try {
			$this->sut->apply_unlocked( $stale_order, $this->completed_event( 'pi_stale_unlocked' ), $this->persistence_profile );
		} finally {
			$this->clear_order_payment_lock( $stale_order, $this->persistence_profile );
		}

		$order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A success event must refresh and preserve a dispute hold while its caller owns the payment lock.' );
		$this->assertSame( array( 'dp_stale_unlocked' ), $order->get_meta( '_wcpay_open_dispute_ids', true ), 'A success event must preserve freshly persisted open disputes.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A skipped success event must not update lifecycle metadata.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
	}

	/**
	 * @testdox A completed-event skip synchronizes persisted dispute state before a stale caller saves.
	 */
	public function test_unlocked_success_skip_prevents_a_stale_caller_save_from_overwriting_a_dispute(): void {
		$stale_order = $this->create_woopayments_order();
		$fresh_order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$fresh_order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_persisted_winner' ) );
		$fresh_order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$fresh_order->update_status( 'on-hold' );

		$stale_order->set_status( 'processing' );
		$stale_order->update_meta_data( '_wcpay_open_dispute_ids', array() );
		$this->assertNotNull( $this->order_payment_store->claim_order_payment_lock_for_operation( $stale_order, $this->persistence_profile, 'pi_stale_save', 'payment operation' ) );
		try {
			$this->sut->apply_unlocked( $stale_order, $this->completed_event( 'pi_stale_save' ), $this->persistence_profile );
			$stale_order->save();
		} finally {
			$this->clear_order_payment_lock( $stale_order, $this->persistence_profile );
		}

		$order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A stale caller must not restore its pre-dispute status.' );
		$this->assertSame( array( 'dp_persisted_winner' ), $order->get_meta( '_wcpay_open_dispute_ids', true ), 'A stale caller must not erase persisted open disputes.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A skipped success event must not update lifecycle metadata.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
	}

	/**
	 * @testdox A completed event sees a dispute hold that another request persisted after this request cached the order.
	 */
	public function test_success_sees_a_dispute_persisted_by_another_request_after_the_order_was_cached(): void {
		$this->enable_hpos_data_caching_when_hpos_is_active();
		$stale_order = $this->read_order_into_request_caches( $this->create_woopayments_order() );

		$this->persist_order_state_as_another_request( $stale_order->get_id(), 'on-hold', array( '_wcpay_open_dispute_ids' => array( 'dp_other_request' ) ) );

		$this->sut->apply( $stale_order, $this->completed_event( 'pi_other_request' ), $this->persistence_profile );

		$this->assertSame( 'wc-on-hold', $this->get_persisted_order_status( $stale_order->get_id() ), 'A success event must not move an order out of a dispute hold persisted by another request.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $stale_order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
		$this->assertLogged(
			'debug',
			'open WooPayments dispute',
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
		$this->enable_hpos_data_caching_when_hpos_is_active();
		$stale_order = $this->read_order_into_request_caches( $this->create_woopayments_order() );

		$this->persist_order_state_as_another_request( $stale_order->get_id(), 'processing', array() );

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
			$this->persistence_profile
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

		$this->sut->apply( $order, $event, $this->persistence_profile );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );

		$order->update_meta_data( '_wcpay_transaction_fee', '2.34' );
		$order->update_status( 'on-hold' );
		$order->save();

		$this->sut->apply( $order, $event, $this->persistence_profile );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A replayed success event must not re-complete the order.' );
		$this->assertSame( 'pi_1', $order->get_transaction_id(), 'A replayed success event must not change the transaction reference.' );
		$this->assertSame( '2.34', $order->get_meta( '_wcpay_transaction_fee', true ), 'A replayed success event must not overwrite lifecycle metadata.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A replayed success event must not add another completion note.' );
		$this->assertLogged(
			'debug',
			'already persisted success note',
			array(
				'source'     => 'native-payments-webhook',
				'order_id'   => $order->get_id(),
				'event_type' => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'     => 'success_note_exists',
			)
		);
	}

	/**
	 * @testdox A payment success event skips a plugin-rendered equivalent success note before lifecycle mutation.
	 */
	public function test_payment_success_replay_with_a_plugin_equivalent_note_keeps_existing_order_state(): void {
		// An additional currency makes the native (explicit-currency) and plugin (suffix-free) renderings differ.
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$order           = $this->create_woopayments_order();
		$note_candidates = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_payment_success_note_candidates( $order, 'pi_cutover', 'ch_cutover', 'txn_cutover' );

		$this->assertGreaterThanOrEqual( 2, count( $note_candidates ) );
		$order->add_order_note( $note_candidates[1] );
		$order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$order->update_status( 'on-hold' );
		$order->save();

		$event = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			'pi_cutover',
			array(
				'_intent_id'             => 'pi_cutover',
				'_intention_status'      => 'succeeded',
				'_wcpay_transaction_fee' => '1.23',
			),
			array(),
			$note_candidates[0],
			PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS,
			$note_candidates
		);

		$this->sut->apply( $order, $event, $this->persistence_profile );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A replayed payment-success event must not re-complete a plugin-owned order.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'A replayed payment-success event must not overwrite lifecycle metadata.' );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ), 'A replayed payment-success event must not add payment metadata.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, $note_candidates[1] ), 'The plugin-written success note must remain the only equivalent note.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, $note_candidates[0] ), 'A replayed payment-success event must not add the Core rendering.' );
	}

	/**
	 * @testdox A payment success replay with an existing equivalent note backfills its stable identity before lifecycle mutation.
	 */
	public function test_payment_success_replay_with_an_existing_equivalent_note_backfills_identity(): void {
		$order       = $this->create_woopayments_order();
		$note        = 'Core payment success note.';
		$plugin_note = 'Plugin payment success note.';
		$order->add_order_note( $plugin_note );
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
			array( $plugin_note )
		);

		$this->sut->apply( $order, $event, $this->persistence_profile );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'An existing equivalent note must prevent a completed event from changing status.' );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ), 'An existing equivalent note must prevent lifecycle metadata mutation.' );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, $plugin_note ), 'A replay must not duplicate the existing equivalent note.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, $note ), 'A replay must not add the Core note.' );

		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $order_note ): bool => $plugin_note === (string) $order_note->content
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

		$this->apply_event_unlocked(
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
	 * @testdox Authorized events move the order on hold.
	 */
	public function test_authorized_event_moves_order_on_hold(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_AUTHORIZED,
				'pi_auth',
				array( '_intention_status' => 'requires_capture' ),
				array(),
				'Payment authorized.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'pi_auth', $order->get_transaction_id() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertOrderHasNote( $order, 'Payment authorized.' );
	}

	/**
	 * @testdox Failed events mark the order failed and unlock the order.
	 */
	public function test_failed_event_marks_order_failed_and_unlocks_order(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_FAILED,
				'pi_failed',
				array( '_intention_status' => 'requires_payment_method' ),
				array(),
				'Payment failed.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'requires_payment_method', $order->get_meta( '_intention_status', true ) );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_profile, 'pi_failed' ) );
		$this->assertOrderHasNote( $order, 'Payment failed.' );
	}

	/**
	 * @testdox Canceled events mark the order cancelled and delete stale fee meta.
	 */
	public function test_canceled_event_marks_order_cancelled_and_deletes_fee_meta(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_wcpay_transaction_fee', '123' );
		$order->update_meta_data( '_wcpay_net', '900' );
		$order->save();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_CANCELED,
				'pi_canceled',
				array( '_intention_status' => 'canceled' ),
				array( '_wcpay_transaction_fee', '_wcpay_net' ),
				'Payment canceled.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
		$this->assertOrderHasNote( $order, 'Payment canceled.' );
	}

	/**
	 * @testdox Capture-expired events mark the order failed.
	 */
	public function test_capture_expired_event_marks_order_failed(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
				'ch_expired',
				array( '_charge_id' => 'ch_expired' ),
				array(),
				'Payment authorization expired.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'ch_expired', $order->get_meta( '_charge_id', true ) );
		$this->assertOrderHasNote( $order, 'Payment authorization expired.' );
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
	 */
	public function test_late_failure_event_does_not_downgrade_paid_processing_order( string $event_status, string $payment_reference, string $late_intent_status, string $note, string $note_type ): void {
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
				$note_type
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status(), "{$event_status} must not downgrade a paid order." );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ), "{$event_status} must not overwrite successful lifecycle metadata." );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ), "{$event_status} must not delete payment fee metadata." );
		$this->assertSame( '8.77', $order->get_meta( '_wcpay_net', true ), "{$event_status} must not delete payment net metadata." );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, $note ), "{$event_status} must not add a late lifecycle note." );
	}

	/**
	 * @testdox Late failure events re-read persisted order state before mutating a stale caller instance.
	 */
	public function test_late_failure_event_does_not_mutate_stale_order_when_persisted_order_is_paid(): void {
		$stale_order = $this->create_woopayments_order();
		$stale_order->update_meta_data( '_intention_status', 'succeeded' );
		$stale_order->update_meta_data( '_wcpay_transaction_fee', '1.23' );
		$stale_order->save();

		$paid_order = wc_get_order( $stale_order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $paid_order );
		$paid_order->set_transaction_id( 'pi_paid' );
		$paid_order->set_status( 'processing' );
		$paid_order->save();

		$note      = 'Late payment failure.';
		$note_type = 'late_payment_failure';
		$this->apply_event(
			$stale_order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_FAILED,
				'pi_failed_late',
				array( '_intention_status' => 'requires_payment_method' ),
				array( '_wcpay_transaction_fee' ),
				$note,
				$note_type
			)
		);

		$order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, $note ) );
	}

	/**
	 * Data provider for late failure events received after payment.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public static function paid_order_late_failure_event_provider(): array {
		return array(
			'failed'          => array( PaymentLifecycleEvent::STATUS_FAILED, 'pi_failed_late', 'requires_payment_method', 'Late payment failure.', 'late_payment_failure' ),
			'canceled'        => array( PaymentLifecycleEvent::STATUS_CANCELED, 'pi_canceled_late', 'canceled', 'Late payment cancellation.', 'late_payment_cancellation' ),
			'capture expired' => array( PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED, 'ch_expired_late', 'canceled', 'Late payment authorization expiry.', 'late_capture_expiry' ),
		);
	}

	/**
	 * @testdox Started events add a note without changing order status.
	 */
	public function test_started_event_adds_note_without_status_change(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_started',
				array( '_intent_id' => 'pi_started' ),
				array(),
				'Payment started.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_started', $order->get_transaction_id() );
		$this->assertSame( 'pi_started', $order->get_meta( '_intent_id', true ) );
		$this->assertOrderHasNote( $order, 'Payment started.' );
	}

	/**
	 * @testdox Started events without a note persist the payment reference and keep the order pending.
	 */
	public function test_started_event_without_note_persists_the_payment_reference(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_started',
				array( '_intent_id' => 'pi_started' )
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_started', $order->get_transaction_id() );
		$this->assertSame( 'pi_started', $order->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox Duplicate lifecycle notes are not added twice.
	 */
	public function test_duplicate_note_is_not_added_twice(): void {
		$order = $this->create_woopayments_order();
		$event = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_STARTED,
			'pi_started',
			array( '_intent_id' => 'pi_started' ),
			array(),
			'Payment started.'
		);

		$this->apply_event( $order, $event );
		$this->apply_event( wc_get_order( $order->get_id() ), $event );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment started.' ) );
	}

	/**
	 * @testdox Completed events keep the order on hold while the provider's own open dispute key holds an ID.
	 */
	public function test_success_keeps_on_hold_under_the_provider_open_dispute_key(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_provider_open_dispute_ids', array( 'dp_custom' ) );
		$order->update_status( 'on-hold' );
		$order->save();
		$profile = $this->createMock( ProviderPersistenceVocabularyInterface::class );
		$profile->method( 'get_order_lock_key' )->willReturn( 'provider_lifecycle_lock_' . $order->get_id() );
		$profile->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$profile->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$profile->method( 'get_open_dispute_ids_meta_key' )->willReturn( '_provider_open_dispute_ids' );

		$this->sut->apply( $order, $this->completed_event( 'pi_custom' ), $profile );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'An open dispute under the provider key must keep the order on hold.' );
		$this->assertSame( array( 'dp_custom' ), $order->get_meta( '_provider_open_dispute_ids', true ), 'The provider dispute record must remain unchanged.' );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ), 'A skipped success event must not add a completion note.' );
	}

	/**
	 * @testdox A completed-event skip keeps the dispute persisted under the provider's own key when a stale caller saves.
	 */
	public function test_success_skip_keeps_the_provider_key_dispute_when_a_stale_caller_saves(): void {
		$stale_order = $this->create_woopayments_order();
		$fresh_order = wc_get_order( $stale_order->get_id() );
		$profile     = $this->createMock( ProviderPersistenceVocabularyInterface::class );
		$profile->method( 'get_order_lock_key' )->willReturn( 'provider_lifecycle_lock_' . $stale_order->get_id() );
		$profile->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$profile->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$profile->method( 'get_open_dispute_ids_meta_key' )->willReturn( '_provider_open_dispute_ids' );

		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$fresh_order->update_meta_data( '_provider_open_dispute_ids', array( 'dp_custom_winner' ) );
		$fresh_order->update_status( 'on-hold' );

		$stale_order->set_status( 'processing' );
		$stale_order->update_meta_data( '_provider_open_dispute_ids', array() );
		$this->assertNotNull( $this->order_payment_store->claim_order_payment_lock_for_operation( $stale_order, $profile, 'pi_custom_stale_save', 'payment operation' ) );
		try {
			$this->sut->apply_unlocked( $stale_order, $this->completed_event( 'pi_custom_stale_save' ), $profile );
			$stale_order->save();
		} finally {
			$this->clear_order_payment_lock( $stale_order, $profile );
		}

		$order = wc_get_order( $stale_order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'A stale caller must not restore its pre-dispute status.' );
		$this->assertSame( array( 'dp_custom_winner' ), $order->get_meta( '_provider_open_dispute_ids', true ), 'A stale caller must not erase the dispute persisted under the provider key.' );
	}

	/**
	 * @testdox Lifecycle events lock the order with the supplied provider persistence vocabulary.
	 */
	public function test_apply_uses_provider_vocabulary_for_locks(): void {
		$order   = $this->create_woopayments_order();
		$profile = $this->createMock( ProviderPersistenceVocabularyInterface::class );
		$profile->method( 'get_order_lock_key' )->willReturn( 'provider_lifecycle_lock_' . $order->get_id() );
		$profile->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$profile->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$lock_token = $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'woopayments_operation', 'payment operation' );
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
			$profile
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Provider payment started.' ), 'A lock held under another provider key must not block the event.' );
		$this->assertFalse( get_transient( 'provider_lifecycle_lock_' . $order->get_id() ), 'The provider lock must be released after the event.' );
		$this->order_payment_store->release_order_payment_lock( $order, $this->persistence_profile, $lock_token );
	}

	/**
	 * @testdox Current-locale payment-complete notes written by the extension are not duplicated by native replays.
	 */
	public function test_current_locale_payment_complete_note_from_extension_is_not_duplicated(): void {
		$this->install_woocommerce_test_translations(
			array(
				'Payment complete.' => 'Zahlung abgeschlossen.',
			)
		);
		switch_to_locale( 'de_DE' );

		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_123' );
		$order->set_status( 'processing' );
		$order->save();
		$order->add_order_note( __( 'Payment complete.', 'woocommerce' ) );

		$this->apply_event_unlocked(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_123',
				array( '_intent_id' => 'pi_123' ),
				array(),
				__( 'Payment complete.', 'woocommerce' ),
				'payment_complete'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Zahlung abgeschlossen.' ) );
	}

	/**
	 * @testdox English payment-complete notes written by the extension are not duplicated by native replays.
	 */
	public function test_english_payment_complete_note_from_extension_is_not_duplicated(): void {
		$order = $this->create_woopayments_order();
		$order->set_transaction_id( 'pi_123' );
		$order->set_status( 'processing' );
		$order->save();
		$order->add_order_note( __( 'Payment complete.', 'woocommerce' ) );

		$this->apply_event_unlocked(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_123',
				array( '_intent_id' => 'pi_123' ),
				array(),
				__( 'Payment complete.', 'woocommerce' ),
				'payment_complete'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
	}

	/**
	 * @testdox Native lifecycle note identities use stable note type instead of rendered note content.
	 */
	public function test_lifecycle_note_identity_uses_note_type_instead_of_rendered_note_content(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event_unlocked(
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

		$this->apply_event_unlocked(
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

		$this->apply_event_unlocked(
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
	 * @testdox Different lifecycle note types with the same reference are each written once.
	 */
	public function test_different_lifecycle_note_types_are_each_written_once(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event_unlocked(
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
		$this->apply_event_unlocked(
			wc_get_order( $order->get_id() ),
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_started',
				array( '_intent_id' => 'pi_started' ),
				array(),
				'Payment follow-up.',
				'payment_followup'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment started.' ) );
		$this->assertSame( 1, $this->countOrderNotesMatching( $order, 'Payment follow-up.' ) );
	}

	/**
	 * @testdox Locked orders are not mutated for the same payment reference.
	 */
	public function test_locked_order_is_not_mutated_for_same_reference(): void {
		$order = $this->create_woopayments_order();
		$this->hold_order_payment_lock( $order, $this->persistence_profile, 'pi_locked' );

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_locked',
				array( '_intent_id' => 'pi_locked' ),
				array(),
				'Payment complete.'
			)
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
		$this->clear_order_payment_lock( $order, $this->persistence_profile );
	}

	/**
	 * @testdox Locked orders are not mutated or unlocked while another operation is active.
	 */
	public function test_locked_order_is_not_mutated_or_unlocked_for_active_operation(): void {
		$order = $this->create_woopayments_order();
		$this->assertNotNull( $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'native_charge_operation', 'payment operation' ) );

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

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Payment complete.' ) );
		$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_profile, 'native_charge_operation' ) );
		$this->clear_order_payment_lock( $order, $this->persistence_profile );
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
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $persistence_profile Provider persistence vocabulary.
			 * @throws \RuntimeException Always.
			 */
			public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $persistence_profile ): void {
				unset( $order, $event, $persistence_profile );
				throw new \RuntimeException( 'Simulated lifecycle failure.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$sut->init( $this->order_payment_store );

		$thrown = null;
		try {
			$sut->apply( $order, $this->completed_event( 'pi_apply_throws' ), $this->persistence_profile );
		} catch ( \RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'The application failure must reach the caller.' );
		$this->assertFalse( get_transient( $this->persistence_profile->get_order_lock_key( $order ) ), 'A throwing application must release the order payment lock.' );
		$lock_token = $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'next_operation', 'payment operation' );
		$this->assertNotNull( $lock_token, 'The next operation must be able to claim the lock.' );
		$this->order_payment_store->release_order_payment_lock( $order, $this->persistence_profile, $lock_token );
	}

	/**
	 * @testdox A lifecycle event skipped because the order is lock-contested is logged as a warning with full context.
	 */
	public function test_skipped_locked_event_is_logged_with_context(): void {
		$order = $this->create_woopayments_order();
		$this->assertNotNull( $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'native_charge_operation', 'payment operation' ) );

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
				'source'            => 'native-payments-webhook',
				'order_id'          => $order->get_id(),
				'payment_reference' => 'pi_webhook',
				'event_type'        => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'            => 'order_locked',
				'refused_operation' => 'payment status update',
			)
		);

		$this->clear_order_payment_lock( $order, $this->persistence_profile );
	}

	/**
	 * @testdox A lifecycle event applied with an available lock does not log a skip warning.
	 */
	public function test_applied_event_does_not_log_skip_warning(): void {
		$order = $this->create_woopayments_order();

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'pi_applied',
				array( '_intent_id' => 'pi_applied' ),
				array(),
				'Payment complete.'
			)
		);

		$warnings = array_filter(
			$this->captured_logs,
			static fn( $log ) => 'warning' === $log['level'] && str_contains( $log['message'], 'order payment lock refused' )
		);
		$this->assertCount( 0, $warnings, 'A successfully applied lifecycle event must not emit a lock-contention warning.' );
	}

	/**
	 * Apply a lifecycle event with the WooPayments profile unless a test supplies another profile.
	 *
	 * @param WC_Order                                    $order               Order object.
	 * @param PaymentLifecycleEvent                       $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface|null $persistence_profile Optional provider vocabulary.
	 */
	private function apply_event( WC_Order $order, PaymentLifecycleEvent $event, ?ProviderPersistenceVocabularyInterface $persistence_profile = null ): void {
		$this->sut->apply( $order, $event, $persistence_profile ?? $this->persistence_profile );
	}

	/**
	 * Apply an unlocked lifecycle event with the WooPayments profile.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	private function apply_event_unlocked( WC_Order $order, PaymentLifecycleEvent $event ): void {
		$this->sut->apply_unlocked( $order, $event, $this->persistence_profile );
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
	 * Turn on the HPOS order data cache when orders live in the HPOS tables, so stale reads can come from it.
	 */
	private function enable_hpos_data_caching_when_hpos_is_active(): void {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			update_option( CustomOrdersTableController::HPOS_DATASTORE_CACHING_ENABLED_OPTION, 'yes' );
		}
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
	 * Write order status and meta straight to the database, as another request would, leaving this request's caches untouched.
	 *
	 * @param int                 $order_id Order ID.
	 * @param string              $status   Order status without the wc- prefix.
	 * @param array<string,mixed> $meta     Meta values to add.
	 */
	private function persist_order_state_as_another_request( int $order_id, string $status, array $meta ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Raw writes emulate another request without cache invalidation.
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( OrdersTableDataStore::get_orders_table_name(), array( 'status' => 'wc-' . $status ), array( 'id' => $order_id ) );
			foreach ( $meta as $key => $value ) {
				$wpdb->insert(
					OrdersTableDataStore::get_meta_table_name(),
					array(
						'order_id'   => $order_id,
						'meta_key'   => $key,
						'meta_value' => maybe_serialize( $value ),
					)
				);
			}
		} else {
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-' . $status ), array( 'ID' => $order_id ) );
			foreach ( $meta as $key => $value ) {
				$wpdb->insert(
					$wpdb->postmeta,
					array(
						'post_id'    => $order_id,
						'meta_key'   => $key,
						'meta_value' => maybe_serialize( $value ),
					)
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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

	/**
	 * Install test-only WooCommerce translations.
	 *
	 * @param array<string,string> $replacements Source text to translated text.
	 */
	private function install_woocommerce_test_translations( array $replacements ): void {
		$this->gettext_replacements = $replacements;
		add_filter( 'gettext', array( $this, 'translate_woocommerce_test_string' ), 10, 3 );
	}

	/**
	 * Translate a WooCommerce string for tests.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_woocommerce_test_string( string $translation, string $text, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}

		return $this->gettext_replacements[ $text ] ?? $translation;
	}
}
