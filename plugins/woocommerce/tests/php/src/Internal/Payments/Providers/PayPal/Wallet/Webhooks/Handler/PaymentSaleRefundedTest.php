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
 * Every case runs on both order storages, posts and HPOS (the custom orders table). The transaction ID is a column of
 * the orders table under HPOS and a meta row under posts, so the handler has to look the order up in a way that works
 * on both.
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
	 * Remember the order storage in use and build the handler over a mocked fees updater.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->hpos_was_enabled    = OrderUtil::custom_orders_table_usage_is_enabled();
		$this->refund_fees_updater = $this->mock( RefundFeesUpdater::class );
		$this->sut                 = new PaymentSaleRefunded( new NullLogger(), $this->refund_fees_updater );
	}

	/**
	 * Restore the order storage the suite runs with.
	 */
	public function tearDown(): void {
		try {
			parent::tearDown();
			wp_cache_flush();
		} finally {
			OrderHelper::toggle_cot_feature_and_usage( $this->hpos_was_enabled );
		}
	}

	/**
	 * The two order storages every case runs on.
	 *
	 * @return array<string, array{bool}> Whether to store orders in the custom orders table.
	 */
	public function provider_order_storages(): array {
		return array(
			'posts' => array( false ),
			'hpos'  => array( true ),
		);
	}

	/**
	 * Store orders in the custom orders table (HPOS) or in posts. Call before creating the order.
	 *
	 * @param bool $hpos Whether to use the custom orders table.
	 */
	private function use_order_storage( bool $hpos ): void {
		OrderHelper::toggle_cot_feature_and_usage( $hpos );
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
	 * @dataProvider provider_order_storages
	 * @testdox Should create no refund and answer 200 when the refund ID is already recorded on the order.
	 *
	 * @param bool $hpos Whether to use the custom orders table.
	 */
	public function test_handle_request_creates_no_refund_when_refund_id_already_recorded( bool $hpos ): void {
		$this->use_order_storage( $hpos );
		$order = $this->create_paid_order();
		$order->update_meta_data( PayPalGateway::REFUNDS_META_KEY, array( 'REFUND-ALREADY-PROCESSED' ) );
		$order->save();

		$this->refund_fees_updater->shouldNotReceive( 'update' );

		$response = $this->sut->handle_request( $this->make_request( 'REFUND-ALREADY-PROCESSED' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $this->reload( $order )->get_refunds() );
	}

	/**
	 * @dataProvider provider_order_storages
	 * @testdox Should create exactly one refund, record its ID on the order and update the fees when the refund ID is new.
	 *
	 * @param bool $hpos Whether to use the custom orders table.
	 */
	public function test_handle_request_creates_one_refund_for_a_new_refund_id( bool $hpos ): void {
		$this->use_order_storage( $hpos );
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
	 * @dataProvider provider_order_storages
	 * @testdox Should refund the order once, not once per delivery, when the same webhook arrives twice.
	 *
	 * @param bool $hpos Whether to use the custom orders table.
	 */
	public function test_handle_request_creates_one_refund_when_delivered_twice( bool $hpos ): void {
		$this->use_order_storage( $hpos );
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
	 * @dataProvider provider_order_storages
	 * @testdox Should create the refund when the order carries no refund meta at all.
	 *
	 * @param bool $hpos Whether to use the custom orders table.
	 */
	public function test_handle_request_creates_refund_when_order_has_no_refund_meta( bool $hpos ): void {
		$this->use_order_storage( $hpos );
		$order = $this->create_paid_order();
		$this->assertSame( '', $order->get_meta( PayPalGateway::REFUNDS_META_KEY ), 'The order should carry no refund meta' );

		$this->refund_fees_updater->shouldReceive( 'update' )->once();

		$response = $this->sut->handle_request( $this->make_request( 'REFUND-NULL-META' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->reload( $order )->get_refunds() );
	}
}
