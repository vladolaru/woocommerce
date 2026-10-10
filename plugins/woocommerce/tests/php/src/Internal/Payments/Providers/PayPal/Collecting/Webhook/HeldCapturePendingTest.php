<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WC_Order;

/**
 * Tests for the handler that records a held capture from PAYMENT.CAPTURE.PENDING, for an order the checkout request did
 * not record.
 *
 * @group paypal-wallet
 */
class HeldCapturePendingTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * The note the wallet's own pending handler adds.
	 */
	private const WAITING_NOTE = 'Payment initiation was successful, and is waiting for the buyer to complete the payment.';

	/**
	 * The booted container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Boot a collecting store over a fake transport.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_collecting();
		$this->container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * A pending wallet order whose checkout request died before the capture was recorded: no held meta.
	 *
	 * @return WC_Order
	 */
	private function unrecorded_order(): WC_Order {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP, false );
		$order->set_status( OrderStatus::PENDING );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * The number of the wallet's "waiting for the buyer" notes, which carry the status change after the text.
	 *
	 * @param WC_Order $order The order.
	 * @return int
	 */
	private function count_waiting_notes( WC_Order $order ): int {
		return count(
			array_filter(
				$this->notes( $order ),
				static function ( string $note ): bool {
					return 0 === strpos( $note, self::WAITING_NOTE );
				}
			)
		);
	}

	/**
	 * A pending capture resource with a status-details reason and PayPal's create time.
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $reason The reason.
	 * @return array
	 */
	private function pending_resource( WC_Order $order, string $reason ): array {
		$event_resource                   = $this->capture_resource( $order, 'PP-ORDER-1', 'PENDING' );
		$event_resource['status_details'] = array( 'reason' => $reason );
		$event_resource['create_time']    = '2026-10-01T10:00:00Z';

		return $event_resource;
	}

	/**
	 * @testdox Should record a capture PayPal holds for the payee from the pending webhook: held meta from PayPal's create time, on hold, the held note, the first order.
	 * @testWith ["UNILATERAL"]
	 *           ["PAYEE_SETUP_PENDING"]
	 *
	 * @param string $reason The status-details reason.
	 */
	public function test_records_a_held_capture( string $reason ): void {
		$order = $this->unrecorded_order();

		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->pending_resource( $order, $reason ) ) );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $saved->get_status() );
		$this->assertSame( $reason, $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( 'CAPTURE-1', $saved->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ) );
		$this->assertSame( (string) strtotime( '2026-10-01T10:00:00Z' ), $saved->get_meta( HeldCapture::HELD_AT_META_KEY, true ) );
		$this->assertSame( 0, $this->count_waiting_notes( $saved ), 'The held note replaces the wallet\'s waiting note' );
		$this->assertSame( 1, ( new HeldOrders() )->count() );
		$this->assertSame( $order->get_id(), ( new Options() )->first_order_id() );
	}

	/**
	 * @testdox Should change nothing for an order the checkout request already recorded, on a repeated delivery.
	 */
	public function test_a_recorded_order_is_left_as_it_is(): void {
		$order = $this->wallet_order( '1' );
		$notes = count( $this->notes( $order ) );

		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->pending_resource( $order, 'UNILATERAL' ) ) );

		$this->assertSame( OrderStatus::ON_HOLD, wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( $notes, $this->notes( $order ), 'No second held note' );
	}

	/**
	 * @testdox Should leave a pending capture with another reason to the wallet's own handler.
	 */
	public function test_other_reasons_go_to_the_wallet(): void {
		$order = $this->unrecorded_order();

		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->pending_resource( $order, 'ECHECK' ) ) );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( '', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( 1, $this->count_waiting_notes( $saved ) );
	}
	/**
	 * @testdox Should record a held capture for a failed order too, which a checkout that threw after PayPal's answer leaves, and put it on hold.
	 */
	public function test_records_a_held_capture_for_a_failed_order(): void {
		$order = $this->unrecorded_order();
		$order->set_status( OrderStatus::FAILED );
		$order->save();

		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->pending_resource( $order, 'UNILATERAL' ) ) );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( OrderStatus::ON_HOLD, $saved->get_status() );
		$this->assertSame( 'UNILATERAL', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
	}

	/**
	 * @testdox Should not hold again an order that already settled when a late pending delivery arrives: $settled.
	 * @testWith ["completed"]
	 *           ["returned"]
	 *
	 * @param string $settled How the order settled.
	 */
	public function test_a_late_pending_delivery_does_not_hold_a_settled_order( string $settled ): void {
		$order      = $this->wallet_order( '1' );
		$settlement = $this->container->get( 'collecting.held-settlement' );
		if ( 'completed' === $settled ) {
			$settlement->complete( $order );
		} else {
			$settlement->return_to_customer( $order );
		}
		$before = wc_get_order( $order->get_id() );
		$notes  = count( $this->notes( $before ) );

		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->pending_resource( $before, 'UNILATERAL' ), 'WH-LATE' ) );

		$after = wc_get_order( $order->get_id() );
		$this->assertSame( $before->get_status(), $after->get_status() );
		$this->assertSame( '', $after->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ), 'The order is not held again' );
		$this->assertCount( $notes, $this->notes( $after ), 'No new held note' );
	}
}
