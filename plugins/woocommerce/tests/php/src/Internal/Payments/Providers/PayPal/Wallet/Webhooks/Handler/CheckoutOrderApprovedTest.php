<?php
/**
 * Tests for the CHECKOUT.ORDER.APPROVED webhook handler.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\CheckoutOrderApproved;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use Mockery\MockInterface;
use WC_Order;
use WP_REST_Request;

/**
 * When PayPal reports a checkout order as approved, the WooCommerce order it belongs to is processed (captured or
 * authorized).
 *
 * @group paypal-wallet
 */
class CheckoutOrderApprovedTest extends WalletTestCase {

	/**
	 * The order processor mock.
	 *
	 * @var OrderProcessor&MockInterface
	 */
	private $order_processor;

	/**
	 * The order endpoint mock.
	 *
	 * @var OrderEndpoint&MockInterface
	 */
	private $order_endpoint;

	/**
	 * The handler under test.
	 *
	 * @var CheckoutOrderApproved
	 */
	private $sut;

	/**
	 * Build the handler over mocks.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->order_processor = $this->mock( OrderProcessor::class );
		$this->order_endpoint  = $this->mock( OrderEndpoint::class );
		$this->order_endpoint->shouldReceive( 'order' )->andReturn( $this->mock( Order::class ) )->byDefault();

		$this->sut = new CheckoutOrderApproved(
			new NullLogger(),
			$this->order_endpoint,
			$this->mock( SessionHandler::class ),
			$this->mock( FundingSourceRenderer::class ),
			$this->order_processor
		);
	}

	/**
	 * Remove the listeners a test added to the before-process filter.
	 */
	public function tearDown(): void {
		try {
			remove_all_filters( 'woocommerce_paypal_payments_before_order_process' );
		} finally {
			parent::tearDown();
		}
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
	 * A CHECKOUT.ORDER.APPROVED request for the given WooCommerce order.
	 *
	 * @param int $wc_order_id The WooCommerce order ID, sent as the custom ID.
	 * @return WP_REST_Request
	 */
	private function make_request( int $wc_order_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_body_params(
			array(
				'event_type' => 'CHECKOUT.ORDER.APPROVED',
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
	 * @testdox Should answer for CHECKOUT.ORDER.APPROVED events only.
	 */
	public function test_is_responsible_for_the_approved_event_only(): void {
		$this->assertTrue( $this->sut->responsible_for_request( $this->make_request( 1 ) ) );

		$other = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$other->set_body_params( array( 'event_type' => 'CHECKOUT.ORDER.COMPLETED' ) );
		$this->assertFalse( $this->sut->responsible_for_request( $other ) );
	}

	/**
	 * @testdox Should process the WooCommerce order of a PayPal payment.
	 */
	public function test_processes_a_paypal_order(): void {
		$order = $this->create_pending_order( 'ppcp-gateway' );
		$this->order_processor->shouldReceive( 'process' )->once()->with( \Mockery::on( fn( $arg ) => $arg instanceof WC_Order && $arg->get_id() === $order->get_id() ) );

		$response = $this->sut->handle_request( $this->make_request( $order->get_id() ) );

		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * @testdox Should skip processing when a filter on the before-process hook says no, and ask it with the order.
	 */
	public function test_a_filter_can_skip_the_processing(): void {
		$order = $this->create_pending_order( 'ppcp-gateway' );
		$this->order_processor->shouldReceive( 'process' )->never();
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_before_order_process', false );

		$response = $this->sut->handle_request( $this->make_request( $order->get_id() ) );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertCount( 1, $calls );
		$this->assertTrue( $calls[0][0], 'The filter should start from true' );
		$this->assertSame( $order->get_id(), $calls[0][2]->get_id() );
	}

	/**
	 * @testdox Should answer with a failure that names the order when processing it throws.
	 */
	public function test_a_failed_processing_is_reported(): void {
		$order = $this->create_pending_order( 'ppcp-gateway' );
		$this->order_processor->shouldReceive( 'process' )->once()->andThrow( new RuntimeException( 'declined' ) );

		$response = $this->sut->handle_request( $this->make_request( $order->get_id() ) );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( (string) $order->get_id(), $data['message'] );
		$this->assertStringContainsString( 'declined', $data['message'] );
	}

	/**
	 * @testdox Should fail when the event carries no PayPal order ID.
	 */
	public function test_fails_without_a_paypal_order_id(): void {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_body_params(
			array(
				'event_type' => 'CHECKOUT.ORDER.APPROVED',
				'id'         => 'WH-2',
				'resource'   => array(),
			)
		);
		$this->order_processor->shouldReceive( 'process' )->never();

		$response = $this->sut->handle_request( $request );

		$this->assertFalse( $response->get_data()['success'] );
	}
}
