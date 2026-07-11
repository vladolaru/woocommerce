<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundEventHandler;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsRefundEventHandler class.
 */
class WooPaymentsRefundEventHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsRefundEventHandler
	 */
	private WooPaymentsRefundEventHandler $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsRefundEventHandler::class );
	}

	/**
	 * @testdox A successful synchronous refund and its webhook converge on one canonical note and refund row.
	 */
	public function test_successful_synchronous_refund_followed_by_webhook_converges(): void {
		$previous_enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', false );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		try {
			$order  = $this->create_refundable_order();
			$refund = $this->create_local_refund( $order );
			$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
			$refund->save_meta_data();
			$order->update_meta_data( '_wcpay_refund_status', 'successful' );
			$order->add_order_note(
				WooPaymentsOrderEffects::refund_note(
					PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 4.00, 'Requested by customer' ),
					're_123',
					false
				)
			);
			$order->save();

			$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );

			$order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertCount( 1, $order->get_refunds() );

			$refund_notes = array_values(
				array_filter(
					wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
					static fn( $note ): bool => str_contains( $note->content, 're_123' )
				)
			);
			$this->assertCount( 1, $refund_notes );
			$this->assertSame(
				sprintf(
					'A refund of %s USD was successfully processed using WooPayments. Reason: Requested by customer. (<code>re_123</code>)',
					wc_price( 4.00, array( 'currency' => 'USD' ) )
				),
				$refund_notes[0]->content
			);
			$this->assertSame( '', $order->get_meta( '_wc_native_woopayments_refund_note_' . md5( 're_123|created_successful' ), true ) );
		} finally {
			false === $previous_enabled_currencies
				? delete_option( 'wcpay_multi_currency_enabled_currencies' )
				: update_option( 'wcpay_multi_currency_enabled_currencies', $previous_enabled_currencies );
		}
	}

	/**
	 * Create a refundable WooPayments order.
	 *
	 * @return WC_Order
	 */
	private function create_refundable_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		return $order;
	}

	/**
	 * Create the local refund row produced by the synchronous path.
	 *
	 * @param WC_Order $order Parent order.
	 * @return WC_Order_Refund
	 */
	private function create_local_refund( WC_Order $order ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'amount'   => '4.00',
				'reason'   => 'Requested by customer',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		return $refund;
	}

	/**
	 * Get a successful charge.refunded payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_successful_refund_charge(): array {
		return array(
			'id'       => 'ch_123',
			'status'   => 'succeeded',
			'captured' => true,
			'amount'   => 1000,
			'currency' => 'usd',
			'refunds'  => array(
				'data' => array(
					array(
						'id'                  => 're_123',
						'amount'              => 400,
						'reason'              => 'Requested by customer',
						'status'              => 'succeeded',
						'balance_transaction' => array( 'id' => 'txn_123' ),
					),
				),
			),
		);
	}
}
