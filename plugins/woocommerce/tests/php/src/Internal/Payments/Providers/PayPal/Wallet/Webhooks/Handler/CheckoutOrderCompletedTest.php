<?php
/**
 * Tests for the CHECKOUT.ORDER.COMPLETED webhook handler.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\CheckoutOrderCompleted;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WC_Order;
use WP_REST_Request;

/**
 * When PayPal reports a checkout order as completed, the WooCommerce order it belongs to is marked paid.
 *
 * @group paypal-wallet
 */
class CheckoutOrderCompletedTest extends WalletTestCase {

	/**
	 * The handler under test.
	 *
	 * @var CheckoutOrderCompleted
	 */
	private $sut;

	/**
	 * Build the handler.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new CheckoutOrderCompleted( new NullLogger() );
	}

	/**
	 * A pending order paid through the given gateway.
	 *
	 * @param string $payment_method The gateway ID.
	 * @return WC_Order
	 */
	private function create_pending_order( string $payment_method ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_total( '10.00' );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/**
	 * A CHECKOUT.ORDER.COMPLETED request whose purchase unit carries the given WooCommerce order ID.
	 *
	 * @param int $wc_order_id The WooCommerce order ID, sent as the custom ID.
	 * @return WP_REST_Request
	 */
	private function make_request( int $wc_order_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_body_params(
			array(
				'event_type' => 'CHECKOUT.ORDER.COMPLETED',
				'id'         => 'WH-1',
				'resource'   => array(
					'id'             => 'PAYPAL-ORDER-1',
					'purchase_units' => array( array( 'custom_id' => (string) $wc_order_id ) ),
				),
			)
		);

		return $request;
	}

	/**
	 * @testdox Should answer for CHECKOUT.ORDER.COMPLETED events only.
	 */
	public function test_is_responsible_for_the_completed_event_only(): void {
		$this->assertTrue( $this->sut->responsible_for_request( $this->make_request( 1 ) ) );

		$other = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$other->set_body_params( array( 'event_type' => 'CHECKOUT.ORDER.APPROVED' ) );
		$this->assertFalse( $this->sut->responsible_for_request( $other ) );
	}

	/**
	 * @testdox Should mark the WooCommerce order of a PayPal payment as paid.
	 */
	public function test_marks_a_paypal_order_paid(): void {
		$order = $this->create_pending_order( 'ppcp-gateway' );

		$response = $this->sut->handle_request( $this->make_request( $order->get_id() ) );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid(), 'The order should be paid' );
	}

	/**
	 * @testdox Should fail without touching any order when the event names no WooCommerce order.
	 */
	public function test_fails_when_the_event_has_no_custom_id(): void {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_body_params(
			array(
				'event_type' => 'CHECKOUT.ORDER.COMPLETED',
				'id'         => 'WH-2',
				'resource'   => array( 'id' => 'PAYPAL-ORDER-2' ),
			)
		);

		$response = $this->sut->handle_request( $request );

		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * @testdox Should fail when no WooCommerce order exists for the custom ID.
	 */
	public function test_fails_when_the_order_does_not_exist(): void {
		$response = $this->sut->handle_request( $this->make_request( 999999999 ) );

		$this->assertFalse( $response->get_data()['success'] );
	}
}
