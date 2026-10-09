<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use ArrayObject;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\HeldCaptureReturned;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WC_Order;

/**
 * Tests for the handler that settles a held order when PayPal reports its capture returned, after re-reading it.
 *
 * @group paypal-wallet
 */
class HeldCaptureReturnedTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * The note on an order whose held payment PayPal returned.
	 */
	private const RETURNED_NOTE = 'PayPal returned the held payment to the customer because setup was not completed';

	/**
	 * The booted container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The System Under Test.
	 *
	 * @var HeldCaptureReturned
	 */
	private HeldCaptureReturned $sut;

	/**
	 * The orders the returned-payment action fired for.
	 *
	 * @var ArrayObject
	 */
	private ArrayObject $returned;

	/**
	 * The reasons the returned-payment action fired with.
	 *
	 * @var ArrayObject
	 */
	private ArrayObject $reasons;

	/**
	 * Boot a collecting store over a fake transport and spy on the returned-payment action.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_collecting();
		$this->container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$this->sut       = $this->container->get( 'collecting.webhook.held-returned' );

		$this->returned = new ArrayObject();
		$this->reasons  = new ArrayObject();
		$spy            = $this->returned;
		$reasons        = $this->reasons;
		add_action(
			'woocommerce_paypal_wallet_held_payment_returned',
			static function ( $order, $reason ) use ( $spy, $reasons ) {
				$spy->append( $order );
				$reasons->append( $reason );
			},
			10,
			2
		);
	}

	/**
	 * The event resource for a type: a capture for DENIED, a refund of the capture for REVERSED and REFUNDED.
	 *
	 * @param string   $event_type The event type.
	 * @param WC_Order $order      The order.
	 * @return array
	 */
	private function resource_for( string $event_type, WC_Order $order ): array {
		return 'PAYMENT.CAPTURE.DENIED' === $event_type ? $this->capture_resource( $order, 'PP-ORDER-1', 'DECLINED' ) : $this->refund_resource( $order, 'CAPTURE-1' );
	}

	/**
	 * @testdox Should re-read the capture through the pinned app and cancel the held order with a restock when PayPal returned it.
	 * @testWith ["PAYMENT.CAPTURE.REVERSED"]
	 *           ["PAYMENT.CAPTURE.REFUNDED"]
	 *           ["PAYMENT.CAPTURE.DENIED"]
	 *
	 * @param string $event_type The event type.
	 */
	public function test_returned_capture_cancels_with_restock( string $event_type ): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$this->assertSame( 8, $this->stock(), 'Putting the order on hold reduced the stock' );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'REFUNDED' ) ) );
		$request = $this->event_request( $event_type, $this->resource_for( $event_type, $order ) );

		$this->assertTrue( $this->sut->responsible_for_request( $request ) );
		$response = $this->sut->handle_request( $request );

		$this->assertTrue( $response->get_data()['success'] );
		$reads = $this->capture_reads();
		$this->assertCount( 1, $reads );
		$this->assertSame( 'https://api.merchant-app.fake.test/v2/payments/captures/CAPTURE-1', $reads[0]['url'] );
		$this->assertSame( 'Bearer token-merchant_app', $reads[0]['request']['headers']['Authorization'] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', $fresh->get_status() );
		$this->assertSame( 10, $this->stock(), 'The stock is restored once' );
		$this->assertSame( 1, $this->count_notes( $order, self::RETURNED_NOTE ) );
		$this->assertFalse( $this->has_held_meta( $order ) );
		$this->assertCount( 1, $this->returned );
		$this->assertSame( $order->get_id(), $this->returned[0]->get_id() );
		$this->assertSame( array(), $fresh->get_refunds(), 'No WooCommerce refund for money PayPal returned' );
		$this->assertSame( array( 'refunded' ), $this->reasons->getArrayCopy() );
	}

	/**
	 * @testdox Should cancel with a restock and a denied note when the re-read capture was declined or failed.
	 * @testWith ["DECLINED", "declined"]
	 *           ["FAILED", "failed"]
	 *
	 * @param string $status The re-read capture status.
	 * @param string $reason The reason passed to the action.
	 */
	public function test_denied_capture_cancels_with_the_denied_note( string $status, string $reason ): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', $status ) ) );

		$this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.DENIED', $this->capture_resource( $order, 'PP-ORDER-1', 'DECLINED' ) ) );

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes( $order, 'PayPal denied the held payment, so the order was cancelled' ) );
		$this->assertSame( 0, $this->count_notes( $order, self::RETURNED_NOTE ) );
		$this->assertSame( array( $reason ), $this->reasons->getArrayCopy() );
	}

	/**
	 * @testdox Should complete the order once, with a note, when the re-read capture is partially refunded.
	 */
	public function test_partially_refunded_reread_completes_the_order(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'PARTIALLY_REFUNDED' ) ) );

		$this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REFUNDED', $this->refund_resource( $order, 'CAPTURE-1' ) ) );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertFalse( $this->has_held_meta( $order ), 'Not held any more, so it is not read again' );
		$this->assertSame( 1, $this->count_notes( $order, 'PayPal reports this payment as partially refunded. Record the refunded amount in WooCommerce if it is not shown yet.' ) );
		$this->assertCount( 0, $this->returned );
	}

	/**
	 * @testdox Should change nothing more when PayPal delivers the returned event again, through the endpoint.
	 */
	public function test_redelivery_is_a_no_op(): void {
		$order    = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$endpoint = $this->container->get( 'webhook.endpoint.controller' );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'REFUNDED' ) ) );

		$endpoint->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REFUNDED', $this->refund_resource( $order, 'CAPTURE-1' ) ) );
		$second = $endpoint->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REFUNDED', $this->refund_resource( $order, 'CAPTURE-1' ) ) );
		$endpoint->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REVERSED', $this->refund_resource( $order, 'CAPTURE-1' ), 'WH-EVENT-2' ) );

		$this->assertTrue( $second->get_data()['success'] );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', $fresh->get_status() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes( $order, self::RETURNED_NOTE ) );
		$this->assertCount( 1, $this->returned );
		$this->assertSame( array(), $fresh->get_refunds(), 'The wallet\'s refund handler never runs for the returned order' );
	}

	/**
	 * @testdox Should complete the order instead when the re-read capture is completed.
	 */
	public function test_completed_reread_completes_the_order(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'COMPLETED' ) ) );

		$this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REVERSED', $this->refund_resource( $order, 'CAPTURE-1' ) ) );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertFalse( $this->has_held_meta( $order ) );
		$this->assertSame( 1, $this->count_notes( $order, 'Payment successfully captured.' ) );
		$this->assertSame( 8, $this->stock() );
		$this->assertCount( 0, $this->returned );
	}

	/**
	 * @testdox Should leave the order held when the re-read capture is still pending.
	 */
	public function test_pending_reread_leaves_the_order_held(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'PENDING', 'UNILATERAL' ) ) );

		$this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.DENIED', $this->capture_resource( $order, 'PP-ORDER-1', 'DECLINED' ) ) );

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertTrue( $this->has_held_meta( $order ) );
		$this->assertCount( 0, $this->returned );
	}

	/**
	 * @testdox Should answer an event for another store's capture with success and change nothing.
	 */
	public function test_mismatched_event_changes_nothing(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );

		$response = $this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REFUNDED', $this->refund_resource( $order, 'CAPTURE-FOREIGN' ) ) );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( array(), $this->capture_reads() );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 0, $this->returned );
	}
}
