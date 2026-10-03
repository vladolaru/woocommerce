<?php
/**
 * Tests for the PayPal wallet PAYMENT.SALE.REFUNDED webhook handler (ported from the extension's PaymentSaleRefundedTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\RefundFeesUpdater;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentSaleRefunded;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WC_Order;
use WP_REST_Request;

/**
 * A refund PayPal reports for a sale creates one WooCommerce refund, however often the webhook is delivered. Runs over
 * real orders and refunds; the order is found by its transaction ID, which is the PayPal sale ID.
 *
 * The tests run on posts-based order storage. The handler finds the order with wc_get_orders( 'meta_key' =>
 * '_transaction_id' ), which matches nothing under HPOS (the transaction ID is a column of the orders table there), so
 * on an HPOS store the handler never finds the order. That limitation is inherited from the extension and reported
 * separately; it is not pinned here.
 *
 * @group paypal-wallet
 */
class PaymentSaleRefundedTest extends WalletTestCase {

	private const SALE_ID = 'SALE-TX-001';

	/**
	 * The refund fees updater mock.
	 *
	 * @var RefundFeesUpdater|\Mockery\MockInterface
	 */
	private $refund_fees_updater;

	/**
	 * The handler under test.
	 *
	 * @var PaymentSaleRefunded
	 */
	private $sut;

	/**
	 * Whether orders were stored in the custom orders table when the test started, restored on tearDown.
	 *
	 * @var bool
	 */
	private $hpos_was_enabled;

	/**
	 * Switch to posts-based order storage and build the handler over a mocked fees updater.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->hpos_was_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
		OrderHelper::toggle_cot_feature_and_usage( false );

		$this->refund_fees_updater = $this->mock( RefundFeesUpdater::class );
		$this->sut                 = new PaymentSaleRefunded( new NullLogger(), $this->refund_fees_updater );
	}

	/**
	 * Restore the order storage the suite runs with.
	 */
	public function tearDown(): void {
		parent::tearDown();
		wp_cache_flush();
		OrderHelper::toggle_cot_feature_and_usage( $this->hpos_was_enabled );
	}

	/**
	 * A processing order of 10.00 whose transaction ID is the PayPal sale ID.
	 *
	 * @return WC_Order
	 */
	private function create_paid_order(): WC_Order {
		$order = wc_create_order();
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_transaction_id( self::SALE_ID );
		$order->save();

		return $order;
	}

	/**
	 * A PAYMENT.SALE.REFUNDED webhook request refunding 10.00 of the sale.
	 *
	 * @param string $refund_id The PayPal refund ID.
	 * @return WP_REST_Request
	 */
	private function make_request( string $refund_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_body_params(
			array(
				'event_type' => 'PAYMENT.SALE.REFUNDED',
				'resource'   => array(
					'id'                    => $refund_id,
					'sale_id'               => self::SALE_ID,
					'total_refunded_amount' => array( 'value' => '10.00' ),
				),
			)
		);

		return $request;
	}

	/**
	 * The current state of an order, read back from the database.
	 *
	 * @param WC_Order $order The order.
	 * @return WC_Order
	 */
	private function reload( WC_Order $order ): WC_Order {
		return wc_get_order( $order->get_id() );
	}

	/**
	 * @testdox Should create no refund and answer 200 when the refund ID is already recorded on the order.
	 */
	public function test_wc_create_refund_is_not_called_when_refund_id_already_in_meta(): void {
		$order = $this->create_paid_order();
		$order->update_meta_data( PayPalGateway::REFUNDS_META_KEY, array( 'REFUND-ALREADY-PROCESSED' ) );
		$order->save();

		$this->refund_fees_updater->shouldNotReceive( 'update' );

		$response = $this->sut->handle_request( $this->make_request( 'REFUND-ALREADY-PROCESSED' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $this->reload( $order )->get_refunds() );
	}

	/**
	 * @testdox Should create exactly one refund, record its ID on the order and update the fees when the refund ID is new.
	 */
	public function test_wc_create_refund_is_called_once_when_refund_id_not_in_meta(): void {
		$order = $this->create_paid_order();

		$this->refund_fees_updater->shouldReceive( 'update' )->once();

		$response = $this->sut->handle_request( $this->make_request( 'REFUND-NEW' ) );

		$this->assertSame( 200, $response->get_status() );
		$reloaded = $this->reload( $order );
		$this->assertCount( 1, $reloaded->get_refunds() );
		$this->assertEquals( 10, $reloaded->get_total_refunded() );
		$this->assertSame( array( 'REFUND-NEW' ), $reloaded->get_meta( PayPalGateway::REFUNDS_META_KEY ) );
	}

	/**
	 * The handler moves the order's transaction ID to the refund ID, so a second delivery would normally not find the
	 * order at all. The ID is put back here so the second delivery reaches the order and the recorded refund ID is what
	 * stops it from refunding again.
	 *
	 * @testdox Should refund the order once, not once per delivery, when the same webhook arrives twice.
	 */
	public function test_wc_create_refund_is_called_only_once_across_repeated_deliveries(): void {
		$order = $this->create_paid_order();

		$this->refund_fees_updater->shouldReceive( 'update' )->once();

		$request = $this->make_request( 'REFUND-REPEATED' );
		$this->sut->handle_request( $request );

		$order = $this->reload( $order );
		$order->set_transaction_id( self::SALE_ID );
		$order->save();

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$reloaded = $this->reload( $order );
		$this->assertCount( 1, $reloaded->get_refunds() );
		$this->assertEquals( 10, $reloaded->get_total_refunded() );
	}

	/**
	 * An order without refund meta has no prior refund recorded: the handler proceeds and refunds normally.
	 *
	 * @testdox Should create the refund when the order carries no refund meta at all.
	 */
	public function test_wc_create_refund_is_called_when_meta_returns_null(): void {
		$order = $this->create_paid_order();
		$this->assertSame( '', $order->get_meta( PayPalGateway::REFUNDS_META_KEY ), 'The order should carry no refund meta' );

		$this->refund_fees_updater->shouldReceive( 'update' )->once();

		$response = $this->sut->handle_request( $this->make_request( 'REFUND-NULL-META' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->reload( $order )->get_refunds() );
	}
}
