<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;

/**
 * Tests for RefundRowCapture.
 */
class RefundRowCaptureTest extends WC_Unit_Test_Case {

	/**
	 * @testdox A refund WooCommerce creates to refund through the gateway is handed to that order's refund call of its amount, once, with the ID it got when saved.
	 *
	 * wc_create_refund() fires `woocommerce_create_refund` before it saves the refund (includes/wc-order-functions.php:672-674).
	 */
	public function test_hands_over_the_gateway_refund_once_with_its_saved_id(): void {
		$order  = $this->create_order();
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '4.25' );
		$sut = new RefundRowCapture();

		$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );
		$refund->save();

		$this->assertSame( $refund->get_id(), $sut->consume( $order, 4.25 ) );
		$this->assertNull( $sut->consume( $order, 4.25 ), 'It is handed over once.' );
	}

	/**
	 * @testdox Nothing is handed over for $refund_kind.
	 * @dataProvider refunds_not_handed_over_data
	 *
	 * @param string $refund_kind    What differs.
	 * @param bool   $refund_payment Whether the refund goes through the gateway.
	 * @param bool   $other_order    Whether the refund belongs to another order.
	 * @param float  $amount         The refund call's amount.
	 */
	public function test_hands_nothing_over_when_the_refund_does_not_match( string $refund_kind, bool $refund_payment, bool $other_order, float $amount ): void {
		unset( $refund_kind );
		$order  = $this->create_order();
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $other_order ? $this->create_order()->get_id() : $order->get_id() );
		$refund->set_amount( '4.25' );
		$refund->save();
		$sut = new RefundRowCapture();

		$sut->handle_create_refund( $refund, array( 'refund_payment' => $refund_payment ) );

		$this->assertNull( $sut->consume( $order, $amount ) );
	}

	/**
	 * Refunds that are not this call's.
	 *
	 * @return array<string,array{string,bool,bool,float}>
	 */
	public function refunds_not_handed_over_data(): array {
		return array(
			'a manual refund' => array( 'a manual refund', false, false, 4.25 ),
			'another order'   => array( 'another order', true, true, 4.25 ),
			'another amount'  => array( 'another amount', true, false, 4.00 ),
		);
	}

	/**
	 * @testdox A manual refund created after a gateway refund clears it, so a later refund call never takes the earlier row.
	 */
	public function test_a_later_manual_refund_clears_what_was_kept(): void {
		$order   = $this->create_order();
		$gateway = new WC_Order_Refund();
		$gateway->set_parent_id( $order->get_id() );
		$gateway->set_amount( '4.25' );
		$gateway->save();
		$sut = new RefundRowCapture();

		$sut->handle_create_refund( $gateway, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( new WC_Order_Refund(), array( 'refund_payment' => false ) );

		$this->assertNull( $sut->consume( $order, 4.25 ) );
	}

	/**
	 * @testdox A refund through one of the runtime's gateways is marked as a gateway refund before it is saved; a refund through another plugin's gateway is left untouched.
	 *
	 * Monitor ruling 2026-10-10 15:05 (R1b): the marker keeps another request's in-flight gateway row from passing for the
	 * merchant's manual record.
	 */
	public function test_marks_only_refunds_through_the_runtime_gateways(): void {
		$controller = $this->createMock( ProviderGatewaysController::class );
		$controller->method( 'owns_gateway' )->willReturnCallback( static fn( string $gateway_id ): bool => 'runtime_gateway' === $gateway_id );
		$sut = new RefundRowCapture();
		$sut->init( $controller );
		$marked = array();

		foreach ( array( 'runtime_gateway', 'other_plugin_gateway' ) as $payment_method ) {
			$order = $this->create_order();
			$order->set_payment_method( $payment_method );
			$order->save();
			$refund = new WC_Order_Refund();
			$refund->set_parent_id( $order->get_id() );
			$refund->set_amount( '4.25' );

			$sut->handle_create_refund( $refund, array( 'refund_payment' => true ) );
			$refund->save();

			$marked[ $payment_method ] = wc_get_order( $refund->get_id() )->get_meta( RefundRowCapture::GATEWAY_REFUND_META, true );
		}

		$this->assertSame( 'yes', $marked['runtime_gateway'] );
		$this->assertSame( '', $marked['other_plugin_gateway'] );
	}

	/**
	 * Create a saved order.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_total( '10.00' );
		$order->save();

		return $order;
	}
}
