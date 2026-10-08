<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentOperationContext class.
 */
class PaymentOperationContextTest extends WC_Unit_Test_Case {

	/**
	 * @testdox PaymentOperationContext exposes order, gateway, payment, and provider payload data.
	 */
	public function test_exposes_payment_operation_context_data(): void {
		$order = wc_create_order();

		$context = new PaymentOperationContext(
			$order,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			'card',
			array(
				'capture' => true,
			),
			array(
				'stripe_customer_id' => 'cus_123',
			)
		);

		$this->assertSame( $order, $context->get_order() );
		$this->assertSame( $order->get_id(), $context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $context->get_gateway_id() );
		$this->assertSame( 'card', $context->get_payment_method_id() );
		$this->assertNull( $context->get_amount() );
		$this->assertSame( array( 'capture' => true ), $context->get_payment_data() );
		$this->assertSame( array( 'stripe_customer_id' => 'cus_123' ), $context->get_provider_data() );
	}

	/**
	 * @testdox Checkout factory should preserve payment and provider data.
	 */
	public function test_checkout_factory_preserves_payment_and_provider_data(): void {
		$order = wc_create_order();

		$context = PaymentOperationContext::for_checkout(
			$order,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			'pm_123',
			array(
				'save_payment_method' => true,
			),
			array(
				'confirmation_token' => 'ctoken_123',
			)
		);

		$this->assertSame( $order, $context->get_order() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $context->get_gateway_id() );
		$this->assertSame( 'pm_123', $context->get_payment_method_id() );
		$this->assertSame( array( 'save_payment_method' => true ), $context->get_payment_data() );
		$this->assertSame( array( 'confirmation_token' => 'ctoken_123' ), $context->get_provider_data() );
	}

	/**
	 * @testdox Refund factory should expose amount and reason as generic payment data.
	 */
	public function test_refund_factory_exposes_amount_and_reason(): void {
		$order = wc_create_order();

		$context = PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 7.25, 'Requested by customer' );

		$this->assertSame(
			array(
				'amount' => 7.25,
				'reason' => 'Requested by customer',
			),
			$context->get_payment_data()
		);
		$this->assertSame( 7.25, $context->get_amount() );
	}

	/**
	 * @testdox Capture and cancel factories should expose provider data without card-specific assumptions.
	 */
	public function test_capture_and_cancel_factories_expose_provider_data(): void {
		$order = wc_create_order();

		$capture = PaymentOperationContext::for_capture(
			$order,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			4.25,
			array(
				'include_level3' => true,
			)
		);
		$cancel  = PaymentOperationContext::for_cancel(
			$order,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			array(
				'source' => 'order_action',
			)
		);

		$this->assertSame( 4.25, $capture->get_amount() );
		$this->assertSame( array( 'include_level3' => true ), $capture->get_provider_data() );
		$this->assertNull( $cancel->get_amount() );
		$this->assertSame( array( 'source' => 'order_action' ), $cancel->get_provider_data() );
	}
}
