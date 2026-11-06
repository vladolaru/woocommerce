<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use InvalidArgumentException;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentLifecycleEvent class.
 */
class PaymentLifecycleEventTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Completed events expose status, reference, meta changes, the note and its note type.
	 */
	public function test_completed_event_exposes_status_reference_meta_and_note(): void {
		$event = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			'pi_123',
			array(
				'_intent_id' => 'pi_123',
				'_charge_id' => 'ch_123',
			),
			array(),
			'Payment complete.',
			'payment_complete'
		);

		$this->assertSame( PaymentLifecycleEvent::STATUS_COMPLETED, $event->get_status() );
		$this->assertSame( 'pi_123', $event->get_payment_reference() );
		$this->assertSame(
			array(
				'_charge_id' => 'ch_123',
				'_intent_id' => 'pi_123',
			),
			$event->get_meta_to_update()
		);
		$this->assertSame( array(), $event->get_meta_to_delete() );
		$this->assertSame( 'Payment complete.', $event->get_note() );
		$this->assertSame( 'payment_complete', $event->get_note_type() );
	}

	/**
	 * @testdox Note equivalents are normalized to unique non-empty strings.
	 */
	public function test_note_equivalents_are_normalized(): void {
		$event = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			'pi_123',
			array(),
			array(),
			'Core note.',
			'payment_success',
			array( 'Plugin note.', '', 'Plugin note.', 123, null, array( 'invalid' ) )
		);

		$this->assertSame( array( 'Plugin note.' ), $event->get_note_equivalents() );
	}

	/**
	 * @testdox Meta deletes come back as passed, in order.
	 */
	public function test_meta_deletes_come_back_in_order(): void {
		$event = new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_CANCELED,
			'pi_123',
			array(),
			array( '_wcpay_transaction_fee', '_wcpay_net' )
		);

		$this->assertSame( array( '_wcpay_transaction_fee', '_wcpay_net' ), $event->get_meta_to_delete() );
	}

	/**
	 * @testdox Unknown statuses are rejected.
	 */
	public function test_unknown_status_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new PaymentLifecycleEvent( 'unknown-status' );
	}
}
