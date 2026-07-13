<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderPaymentLifecycleService class.
 */
class OrderPaymentLifecycleServiceTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

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
	 * @var WooPaymentsPersistenceProfile
	 */
	private WooPaymentsPersistenceProfile $persistence_profile;

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
		$this->persistence_profile = new WooPaymentsPersistenceProfile();
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
		$this->assertFalse( $this->order_payment_store->is_order_payment_locked( $order, $this->persistence_profile, 'pi_failed' ) );
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

		$order      = wc_get_order( $order->get_id() );
		$marker_key = '_wc_native_payments_note_' . md5( "{$payment_reference}|{$event_status}|{$note_type}" );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status(), "{$event_status} must not downgrade a paid order." );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ), "{$event_status} must not overwrite successful lifecycle metadata." );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ), "{$event_status} must not delete payment fee metadata." );
		$this->assertSame( '8.77', $order->get_meta( '_wcpay_net', true ), "{$event_status} must not delete payment net metadata." );
		$this->assertSame( '', $order->get_meta( $marker_key, true ), "{$event_status} must not persist a lifecycle note marker." );
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

		$order      = wc_get_order( $stale_order->get_id() );
		$marker_key = '_wc_native_payments_note_' . md5( 'pi_failed_late|failed|' . $note_type );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '1.23', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( $marker_key, true ) );
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
	 * @testdox Started events without a status transition are persisted with a single order save.
	 */
	public function test_started_event_without_status_transition_uses_single_order_save(): void {
		$order = $this->getMockBuilder( WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods(
				array(
					'get_meta',
					'get_transaction_id',
					'save',
					'save_meta_data',
					'set_transaction_id',
					'update_meta_data',
				)
			)
			->getMock();

		$order->method( 'get_transaction_id' )->willReturn( '' );
		$order->method( 'get_meta' )->willReturn( '' );
		$order->expects( $this->once() )->method( 'update_meta_data' )->with( '_intent_id', 'pi_started' );
		$order->expects( $this->once() )->method( 'set_transaction_id' )->with( 'pi_started' );
		$order->expects( $this->never() )->method( 'save_meta_data' );
		$order->expects( $this->once() )->method( 'save' );

		$this->apply_event_unlocked(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'pi_started',
				array( '_intent_id' => 'pi_started' )
			)
		);
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
	 * @testdox Provider persistence profiles control lock vocabulary and duplicate-note policy.
	 */
	public function test_apply_uses_provider_profile_for_locks_and_note_policy(): void {
		$order   = $this->create_woopayments_order();
		$profile = $this->createMock( ProviderPersistenceProfile::class );
		$profile->method( 'get_order_lock_key' )->willReturn( 'provider_lifecycle_lock_' . $order->get_id() );
		$profile->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$profile->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$profile->method( 'should_skip_note' )->willReturn( true );

		$this->apply_event(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_STARTED,
				'provider_payment_123',
				array(),
				array(),
				'Provider already wrote this note.'
			),
			$profile
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 0, $this->countOrderNotesMatching( $order, 'Provider already wrote this note.' ) );
		$this->assertFalse( get_transient( 'provider_lifecycle_lock_' . $order->get_id() ) );
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
		$legacy_marker_key = '_wc_native_payments_note_' . md5( 'pi_started|started|payment_started' );
		$expected_identity = hash( 'sha256', 'payment_lifecycle:pi_started|started|payment_started' );
		$notes             = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $notes );
		$this->assertSame( $expected_identity, get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', true ) );
		$this->assertSame( '', $order->get_meta( $legacy_marker_key, true ) );

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
	 * @testdox Legacy lifecycle markers backfill the unified identity onto an equivalent note.
	 */
	public function test_legacy_lifecycle_marker_backfills_unified_identity(): void {
		$order             = $this->create_woopayments_order();
		$legacy_note_id    = $order->add_order_note( 'Payment started.' );
		$legacy_marker_key = '_wc_native_payments_note_' . md5( 'pi_legacy|started|payment_started' );
		$order->update_meta_data( $legacy_marker_key, 'yes' );
		$order->save_meta_data();

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
		$this->order_payment_store->lock_order_payment( $order, $this->persistence_profile, 'pi_locked' );

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
		$this->order_payment_store->unlock_order_payment( $order, $this->persistence_profile );
	}

	/**
	 * @testdox Locked orders are not mutated or unlocked while another operation is active.
	 */
	public function test_locked_order_is_not_mutated_or_unlocked_for_active_operation(): void {
		$order = $this->create_woopayments_order();
		$this->assertTrue( $this->order_payment_store->claim_order_payment_lock( $order, $this->persistence_profile, 'native_charge_operation' ) );

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
		$this->assertTrue( $this->order_payment_store->is_order_payment_locked( $order, $this->persistence_profile, 'native_charge_operation' ) );
		$this->order_payment_store->unlock_order_payment( $order, $this->persistence_profile );
	}

	/**
	 * @testdox A lifecycle event skipped because the order is lock-contested is logged as a warning with full context.
	 */
	public function test_skipped_locked_event_is_logged_with_context(): void {
		$order = $this->create_woopayments_order();
		$this->assertTrue( $this->order_payment_store->claim_order_payment_lock( $order, $this->persistence_profile, 'native_charge_operation' ) );

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
			'lock contention',
			array(
				'source'            => 'native-payments-webhook',
				'order_id'          => $order->get_id(),
				'payment_reference' => 'pi_webhook',
				'event_type'        => PaymentLifecycleEvent::STATUS_COMPLETED,
				'reason'            => 'order_locked',
			)
		);

		$this->order_payment_store->unlock_order_payment( $order, $this->persistence_profile );
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
			static fn( $log ) => 'warning' === $log['level'] && str_contains( $log['message'], 'lock contention' )
		);
		$this->assertCount( 0, $warnings, 'A successfully applied lifecycle event must not emit a lock-contention warning.' );
	}

	/**
	 * Apply a lifecycle event with the WooPayments profile unless a test supplies another profile.
	 *
	 * @param WC_Order                        $order               Order object.
	 * @param PaymentLifecycleEvent           $event               Lifecycle event.
	 * @param ProviderPersistenceProfile|null $persistence_profile Optional provider profile.
	 */
	private function apply_event( WC_Order $order, PaymentLifecycleEvent $event, ?ProviderPersistenceProfile $persistence_profile = null ): void {
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
	 * Create a WooPayments order for lifecycle tests.
	 *
	 * @return WC_Order
	 */
	private function create_woopayments_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->save();

		return $order;
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
