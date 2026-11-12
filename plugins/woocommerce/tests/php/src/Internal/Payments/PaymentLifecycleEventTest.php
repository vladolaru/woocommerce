<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
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
	 * @testdox Unknown statuses are rejected.
	 */
	public function test_unknown_status_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new PaymentLifecycleEvent( 'unknown-status' );
	}

	/**
	 * @testdox Only a completed event can hold the order: a $status event that holds it is rejected.
	 *
	 * @testWith ["authorized"]
	 *           ["failed"]
	 *           ["canceled"]
	 *           ["capture_expired"]
	 *           ["started"]
	 *
	 * @param string $status Lifecycle status.
	 */
	public function test_hold_on_an_event_that_is_not_completed_is_rejected( string $status ): void {
		$this->expectException( InvalidArgumentException::class );

		new PaymentLifecycleEvent( $status, 'pi_123', array(), array(), null, null, array(), false, true );
	}

	/**
	 * @testdox The event of a provider outcome holds the order only for a completed outcome the provider holds: $_dataName.
	 *
	 * @dataProvider provider_outcomes_and_hold
	 *
	 * @param string              $status   Outcome status.
	 * @param array<string,mixed> $data     Outcome data.
	 * @param bool                $expected Whether the event holds the order.
	 */
	public function test_event_of_an_outcome_holds_the_order_only_when_completed_and_held( string $status, array $data, bool $expected ): void {
		$this->assertSame( $expected, PaymentLifecycleEvent::should_hold_order_for_outcome( new PaymentOutcome( $status, 'pi_123', '', '', '', $data ) ) );
	}

	/**
	 * Outcomes and whether their event holds the order.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:bool}>
	 */
	public function provider_outcomes_and_hold(): array {
		return array(
			'completed, held'                      => array( PaymentOutcome::STATUS_COMPLETED, array( PaymentOutcome::DATA_HOLD_ORDER => true ), true ),
			'completed, not held'                  => array( PaymentOutcome::STATUS_COMPLETED, array(), false ),
			'completed, held flag not true'        => array( PaymentOutcome::STATUS_COMPLETED, array( PaymentOutcome::DATA_HOLD_ORDER => 'yes' ), false ),
			'completed, held, payment method only' => array(
				PaymentOutcome::STATUS_COMPLETED,
				array(
					PaymentOutcome::DATA_HOLD_ORDER => true,
					PaymentOutcome::DATA_PAYMENT_METHOD_ONLY => true,
				),
				false,
			),
			'authorized, held'                     => array( PaymentOutcome::STATUS_AUTHORIZED, array( PaymentOutcome::DATA_HOLD_ORDER => true ), false ),
			'failed, held'                         => array( PaymentOutcome::STATUS_FAILED, array( PaymentOutcome::DATA_HOLD_ORDER => true ), false ),
		);
	}
}
