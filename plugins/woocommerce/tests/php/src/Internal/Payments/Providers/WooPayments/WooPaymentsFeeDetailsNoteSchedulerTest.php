<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFeeDetailsNoteScheduler class.
 */
class WooPaymentsFeeDetailsNoteSchedulerTest extends WC_Unit_Test_Case {

	/**
	 * A payment's first success or capture note schedules the job with client 11.1.0's arguments.
	 *
	 * @dataProvider success_note_identities
	 *
	 * @param string $identity  Lifecycle note identity.
	 * @param string $intent_id Intent the job reads.
	 */
	public function test_first_success_or_capture_note_schedules_the_job( string $identity, string $intent_id ): void {
		$order = $this->create_order();

		$this->assertTrue( wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $order, 'A note.', $identity ) );

		$this->assertSame(
			array(
				array(
					'order_id'     => $order->get_id(),
					'intent_id'    => $intent_id,
					'is_test_mode' => false,
				),
			),
			$this->get_pending_job_args()
		);
	}

	/**
	 * Success and capture note identities as the payment lifecycle builds them.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function success_note_identities(): array {
		return array(
			'payment success'                  => array( 'payment_lifecycle:pi_123|completed|payment_success', 'pi_123' ),
			'capture success'                  => array( 'payment_lifecycle:pi_456|completed|capture_success', 'pi_456' ),
			'setup intent, as the client does' => array( 'payment_lifecycle:seti_789|completed|payment_success', 'seti_789' ),
		);
	}

	/**
	 * Other notes schedule nothing.
	 *
	 * @dataProvider other_note_identities
	 *
	 * @param string $identity Note identity.
	 */
	public function test_other_notes_schedule_nothing( string $identity ): void {
		$order = $this->create_order();

		wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $order, 'A note.', $identity );

		$this->assertSame( array(), $this->get_pending_job_args() );
	}

	/**
	 * Identities of notes that are not a payment's success or capture note.
	 *
	 * @return array<string,array{string}>
	 */
	public function other_note_identities(): array {
		return array(
			'authorized'          => array( 'payment_lifecycle:pi_123|authorized|payment_authorized' ),
			'failed'              => array( 'payment_lifecycle:pi_123|failed|payment_failed' ),
			'refund'              => array( 'refund:re_123:created_successful' ),
			'no identity'         => array( '' ),
			'success suffix only' => array( 'other:pi_123|completed|payment_success' ),
		);
	}

	/**
	 * @testdox A note that already exists schedules nothing, as the client returns once its note exists.
	 */
	public function test_existing_note_schedules_nothing(): void {
		$order        = $this->create_order();
		$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$note_service->add_note_once( $order, 'A note.', 'payment_lifecycle:pi_123|completed|payment_success' );
		as_unschedule_all_actions( WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION );

		$this->assertFalse( $note_service->add_note_once( $order, 'A note.', 'payment_lifecycle:pi_123|completed|payment_success' ) );

		$this->assertSame( array(), $this->get_pending_job_args() );
	}

	/**
	 * @testdox The job carries the store's test mode, as the client's does.
	 */
	public function test_job_carries_test_mode(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$order = $this->create_order();

		wc_get_container()->get( WooPaymentsFeeDetailsNoteScheduler::class )->schedule_after_lifecycle_note( $order, 'payment_lifecycle:pi_123|completed|payment_success' );

		$this->assertTrue( $this->get_pending_job_args()[0]['is_test_mode'] );
	}

	/**
	 * Create a saved order.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
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
