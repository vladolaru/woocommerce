<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentOperationContext class.
 */
class PaymentOperationContextTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Each factory maps its arguments to the order, gateway, method, amount, payment and provider data ($_dataName).
	 *
	 * @dataProvider factory_cases
	 *
	 * @param callable             $create            Builds the context for the order.
	 * @param string               $payment_method_id Expected payment method ID.
	 * @param float|null           $amount            Expected amount.
	 * @param array<string, mixed> $payment_data      Expected payment data.
	 * @param array<string, mixed> $provider_data     Expected provider data.
	 */
	public function test_each_factory_maps_its_arguments( callable $create, string $payment_method_id, ?float $amount, array $payment_data, array $provider_data ): void {
		$order = wc_create_order();

		$context = $create( $order );

		$this->assertSame( $order, $context->get_order() );
		$this->assertSame( $order->get_id(), $context->get_order_id() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $context->get_gateway_id() );
		$this->assertSame( $payment_method_id, $context->get_payment_method_id() );
		$this->assertSame( $amount, $context->get_amount() );
		$this->assertSame( $payment_data, $context->get_payment_data() );
		$this->assertSame( $provider_data, $context->get_provider_data() );
	}

	/**
	 * Each factory, with the values its context must expose.
	 *
	 * @return array<string, array<mixed>>
	 */
	public function factory_cases(): array {
		return array(
			'checkout' => array(
				static fn( WC_Order $order ) => PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_123', array( 'save_payment_method' => true ), array( 'confirmation_token' => 'ctoken_123' ) ),
				'pm_123',
				null,
				array( 'save_payment_method' => true ),
				array( 'confirmation_token' => 'ctoken_123' ),
			),
			'refund'   => array(
				static fn( WC_Order $order ) => PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 7.25, 'Requested by customer' ),
				'',
				7.25,
				array(
					'amount' => 7.25,
					'reason' => 'Requested by customer',
				),
				array(),
			),
			'capture'  => array(
				static fn( WC_Order $order ) => PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 4.25, array( 'include_level3' => true ) ),
				'',
				4.25,
				array(),
				array( 'include_level3' => true ),
			),
			'cancel'   => array(
				static fn( WC_Order $order ) => PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, array( 'source' => 'order_action' ) ),
				'',
				null,
				array(),
				array( 'source' => 'order_action' ),
			),
		);
	}
}
