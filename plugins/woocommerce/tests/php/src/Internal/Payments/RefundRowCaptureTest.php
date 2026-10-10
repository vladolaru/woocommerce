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
	 * wc_create_refund() fires `woocommerce_create_refund` before it saves the refund again (includes/wc-order-functions.php:672-674);
	 * this case starts from an unsaved refund, as a direct caller of the hook may.
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
	 * @testdox A manual refund created while a gateway refund is kept leaves it kept for that refund's call.
	 *
	 * Codex review 204 F3: a callback on the gateway refund's `woocommerce_create_refund` may create a manual refund
	 * before WooCommerce refunds through the gateway.
	 */
	public function test_a_manual_refund_leaves_the_kept_gateway_refund(): void {
		$order   = $this->create_order();
		$gateway = $this->create_refund( $order, '4.25' );
		$sut     = new RefundRowCapture();

		$sut->handle_create_refund( $gateway, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( $this->create_refund( $order, '1.00' ), array( 'refund_payment' => false ) );

		$this->assertSame( $gateway->get_id(), $sut->consume( $order, 4.25 ) );
	}

	/**
	 * @testdox A gateway refund created inside another one's call is handed to its own call first, then the outer one to its call.
	 *
	 * Codex review 204 F3 and monitor ruling 2026-10-10 16:30: each wc_create_refund() call keeps its own refund, and a
	 * refund call takes the one kept for the innermost call still running.
	 */
	public function test_nested_gateway_refunds_are_handed_to_their_own_calls(): void {
		$outer_order = $this->create_order();
		$inner_order = $this->create_order();
		$outer       = $this->create_refund( $outer_order, '4.25' );
		$inner       = $this->create_refund( $inner_order, '3.00' );
		$sut         = new RefundRowCapture();

		$sut->handle_create_refund( $outer, array( 'refund_payment' => true ) );
		$sut->handle_create_refund( $inner, array( 'refund_payment' => true ) );

		$this->assertSame( $inner->get_id(), $sut->consume( $inner_order, 3.00 ), 'The inner call takes its own refund.' );
		$this->assertSame( $outer->get_id(), $sut->consume( $outer_order, 4.25 ), 'The outer call takes its own refund.' );
		$this->assertNull( $sut->consume( $outer_order, 4.25 ) );
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
	 * @testdox Through wc_create_refund(), a $label refund is stored with marker "$first_save_marker" at its first save and "$final_marker" once created.
	 *
	 * The gateway case has no gateway to refund through, so WooCommerce deletes its row; only its first save is read.
	 * @dataProvider first_save_marker_data
	 *
	 * Codex review 204 F1 and monitor ruling 2026-10-10 16:30: wc_create_refund() first saves the row inside update_taxes()
	 * (includes/wc-order-functions.php:657), before `woocommerce_create_refund` at :672, so the marker is written at that
	 * first save and removed again at the hook when the refund does not go through the gateway.
	 *
	 * @param string      $label             Case label.
	 * @param string      $payment_method    The order's payment method.
	 * @param bool        $refund_payment    Whether the refund goes through the gateway.
	 * @param string      $first_save_marker The marker stored at the row's first save.
	 * @param string|null $final_marker      The marker stored once wc_create_refund() returns; null when the row is deleted.
	 */
	public function test_marks_at_the_first_save_and_unmarks_a_manual_refund( string $label, string $payment_method, bool $refund_payment, string $first_save_marker, ?string $final_marker ): void {
		unset( $label );
		$controller = $this->createMock( ProviderGatewaysController::class );
		$controller->method( 'owns_gateway' )->willReturnCallback( static fn( string $gateway_id ): bool => 'runtime_gateway' === $gateway_id );
		$sut = new RefundRowCapture();
		$sut->init( $controller );
		$sut->register();
		$order = $this->create_order();
		$order->set_payment_method( $payment_method );
		$order->save();
		$stored     = array();
		$first_save = static function ( $refund ) use ( &$stored ): void {
			$stored[] = $refund instanceof WC_Order_Refund ? (string) wc_get_order( $refund->get_id() )->get_meta( RefundRowCapture::GATEWAY_REFUND_META, true ) : null;
		};
		add_action( 'woocommerce_after_order_refund_object_save', $first_save );

		try {
			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => 4.25,
					'refund_payment' => $refund_payment,
				)
			);
		} finally {
			remove_action( 'woocommerce_after_order_refund_object_save', $first_save );
			remove_action( 'woocommerce_before_order_refund_object_save', array( $sut, 'handle_before_refund_save' ) );
			remove_action( 'woocommerce_create_refund', array( $sut, 'handle_create_refund' ) );
		}

		$this->assertNotEmpty( $stored );
		$this->assertSame( $first_save_marker, $stored[0], 'The marker stored at the first save.' );
		if ( null === $final_marker ) {
			$this->assertWPError( $refund );
		} else {
			$this->assertInstanceOf( WC_Order_Refund::class, $refund );
			$this->assertSame( $final_marker, wc_get_order( $refund->get_id() )->get_meta( RefundRowCapture::GATEWAY_REFUND_META, true ) );
		}
	}

	/**
	 * Refunds created through wc_create_refund().
	 *
	 * @return array<string,array{string,string,bool,string,string|null}>
	 */
	public function first_save_marker_data(): array {
		return array(
			'gateway refund on a runtime order'     => array( 'gateway', 'runtime_gateway', true, 'yes', null ),
			'manual refund on a runtime order'      => array( 'manual', 'runtime_gateway', false, 'yes', '' ),
			'manual refund on another plugin order' => array( 'other plugin', 'other_plugin_gateway', false, '', '' ),
		);
	}

	/**
	 * Create a saved refund of an order.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param string   $amount Amount.
	 * @return WC_Order_Refund
	 */
	private function create_refund( WC_Order $order, string $amount ): WC_Order_Refund {
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( $amount );
		$refund->save();

		return $refund;
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
