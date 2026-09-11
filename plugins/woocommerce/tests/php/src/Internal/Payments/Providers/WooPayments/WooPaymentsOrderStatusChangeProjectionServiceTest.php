<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderStatusChangeProjectionService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderStatusChangeProjectionService class.
 */
class WooPaymentsOrderStatusChangeProjectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderStatusChangeProjectionService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = wc_get_container()->get( WooPaymentsOrderStatusChangeProjectionService::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();
	}

	/**
	 * @testdox Should not offer confirmation for orders paid with another gateway.
	 */
	public function test_does_not_offer_confirmation_for_non_woopayments_order(): void {
		$order = $this->create_order( 'bacs' );

		$this->assertFalse(
			$this->sut->should_offer_confirmation( $order ),
			'Orders paid with another gateway should never get the WooPayments confirmation.'
		);
	}

	/**
	 * @testdox Should offer confirmation for orders paid with a WooPayments gateway.
	 *
	 * @dataProvider woopayments_payment_method_provider
	 *
	 * @param string $payment_method Order payment method ID.
	 */
	public function test_offers_confirmation_for_woopayments_orders( string $payment_method ): void {
		$order = $this->create_order( $payment_method );

		$this->assertTrue(
			$this->sut->should_offer_confirmation( $order ),
			"Orders paid with {$payment_method} should get the WooPayments confirmation."
		);
	}

	/**
	 * WooPayments gateway IDs that an order can carry.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function woopayments_payment_method_provider(): array {
		return array(
			'card gateway'           => array( 'woocommerce_payments' ),
			'split gateway'          => array( 'woocommerce_payments_bancontact' ),
			'express wallet gateway' => array( 'woocommerce_payments_apple_pay' ),
		);
	}

	/**
	 * @testdox Should project the order status with the dropdown prefix.
	 */
	public function test_projects_order_status_with_dropdown_prefix(): void {
		$order = $this->create_order();
		$order->set_status( 'processing' );
		$order->save();

		$config = $this->sut->get_config( $order );

		$this->assertSame( 'wc-processing', $config['order_status'], 'The status must match the dropdown option values.' );
	}

	/**
	 * @testdox Should project the remaining and refunded amounts for a partially refunded order.
	 */
	public function test_projects_amounts_for_partially_refunded_order(): void {
		$order = $this->create_order();
		$this->refund( $order, 10.0 );

		$config = $this->sut->get_config( $order );

		$this->assertSame( 15.0, $config['refund_amount'], 'Remaining refundable should be the order total minus refunds.' );
		$this->assertSame( 10.0, $config['refunded_amount'], 'Refunded amount should reflect the existing refund.' );
	}

	/**
	 * @testdox Should project a zero remaining amount for a fully refunded order.
	 */
	public function test_projects_zero_remaining_amount_for_fully_refunded_order(): void {
		$order = $this->create_order();
		$this->refund( $order, 25.0 );

		$config = $this->sut->get_config( $order );

		$this->assertSame( 0.0, $config['refund_amount'], 'A fully refunded order has nothing left to refund.' );
		$this->assertSame( 25.0, $config['refunded_amount'], 'Refunded amount should cover the whole order.' );
	}

	/**
	 * @testdox Should format the refund amount in the order currency rather than the store currency.
	 */
	public function test_formats_refund_amount_in_order_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );

		$order = $this->create_order();
		$order->set_currency( 'EUR' );
		$order->save();

		$config = $this->sut->get_config( $order );

		$this->assertStringContainsString( '€', $config['formatted_refund_amount'], 'The order currency symbol should be used.' );
		$this->assertStringNotContainsString( '$', $config['formatted_refund_amount'], 'The store currency symbol should not leak in.' );
		$this->assertStringContainsString( '25.00', $config['formatted_refund_amount'] );
		$this->assertStringNotContainsString( '<', $config['formatted_refund_amount'], 'Markup should be stripped for use in modal copy.' );
		$this->assertStringNotContainsString( '&', $config['formatted_refund_amount'], 'Entities should be decoded so the merchant never sees them verbatim.' );
	}

	/**
	 * @testdox Should report the order as refundable when the native gateway can refund it.
	 */
	public function test_reports_order_as_refundable_when_native_gateway_can_refund(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_mock' );
		$order->save();

		$this->fake_order_gateway( $this->create_native_gateway() );

		$config = $this->sut->get_config( $order );

		$this->assertTrue( $config['can_refund'], 'An order with a provider charge should be refundable.' );
	}

	/**
	 * @testdox Should report the order as not refundable when it carries no provider charge.
	 */
	public function test_reports_order_as_not_refundable_without_a_provider_charge(): void {
		$order = $this->create_order();

		$this->fake_order_gateway( $this->create_native_gateway() );

		$config = $this->sut->get_config( $order );

		$this->assertFalse( $config['can_refund'], 'An order without a provider charge cannot be refunded at the provider.' );
	}

	/**
	 * @testdox Should report the order as not refundable when no native gateway is resolved.
	 */
	public function test_reports_order_as_not_refundable_without_a_native_gateway(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_charge_id', 'ch_mock' );
		$order->save();

		$this->fake_order_gateway( false );

		$config = $this->sut->get_config( $order );

		$this->assertFalse( $config['can_refund'], 'Without the native gateway there is nothing that can refund at the provider.' );
	}

	/**
	 * @testdox Should project exactly the documented config keys.
	 */
	public function test_projects_exactly_the_documented_config_keys(): void {
		$config = $this->sut->get_config( $this->create_order() );

		$this->assertSame(
			array( 'order_status', 'can_refund', 'refund_amount', 'formatted_refund_amount', 'refunded_amount' ),
			array_keys( $config ),
			'The config contract is consumed by the browser and must not drift.'
		);
	}

	/**
	 * Create a saved order with a known total.
	 *
	 * @param string $payment_method Order payment method ID.
	 * @return WC_Order
	 */
	private function create_order( string $payment_method = 'woocommerce_payments' ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_payment_method( $payment_method );
		$order->set_total( '25.00' );
		$order->save();

		return $order;
	}

	/**
	 * Refund part or all of an order.
	 *
	 * @param WC_Order $order  Order to refund.
	 * @param float    $amount Refund amount.
	 */
	private function refund( WC_Order $order, float $amount ): void {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
			)
		);

		$this->assertNotWPError( $refund );
	}

	/**
	 * Create a native WooPayments card gateway instance.
	 *
	 * @return NativeWooPaymentsGateway
	 */
	private function create_native_gateway(): NativeWooPaymentsGateway {
		return new NativeWooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( 'card' ) );
	}

	/**
	 * Control the gateway that the projection service resolves for an order.
	 *
	 * @param mixed $gateway Gateway instance, or false when none resolves.
	 */
	private function fake_order_gateway( $gateway ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_payment_gateway_by_order' => function () use ( $gateway ) {
					return $gateway;
				},
			)
		);
	}
}
