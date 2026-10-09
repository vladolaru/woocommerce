<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrdersCount;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * Tests for the held-orders query.
 *
 * @group paypal-wallet
 */
class HeldOrdersTest extends WalletTestCase {

	/**
	 * An order, held or not.
	 *
	 * @param string   $status         The order status, without the prefix.
	 * @param string   $payment_method The payment method.
	 * @param int|null $held_at        The held-at timestamp, or null for an order that is not held.
	 * @return WC_Order
	 */
	private function order( string $status, string $payment_method, ?int $held_at ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		if ( null !== $held_at ) {
			// An order is held from the moment it is captured, so the order dates follow the held-at times.
			$order->set_date_created( $held_at );
			$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
			$order->update_meta_data( HeldCapture::HELD_AT_META_KEY, $held_at );
			$order->update_meta_data( HeldCapture::CAPTURE_ID_META_KEY, 'CAPTURE-' . $order->get_id() );
		}
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * @testdox Should implement the held-orders count and be built with no container.
	 */
	public function test_it_is_a_held_orders_count(): void {
		$this->assertInstanceOf( HeldOrdersCount::class, new HeldOrders() );
	}

	/**
	 * @testdox Should return only on-hold wallet orders with the held meta, and count them without loading orders.
	 */
	public function test_all_returns_the_held_on_hold_wallet_orders_only(): void {
		$held        = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$processing  = $this->order( 'processing', 'ppcp-gateway', 1000 );
		$not_held    = $this->order( 'on-hold', 'ppcp-gateway', null );
		$other_gate  = $this->order( 'on-hold', 'bacs', 1000 );
		$second_held = $this->order( 'on-hold', 'ppcp-gateway', 2000 );
		$sut         = new HeldOrders();

		$ids = array_map(
			static function ( WC_Order $order ): int {
				return $order->get_id();
			},
			$sut->all()
		);
		sort( $ids );

		$this->assertSame( array( $held->get_id(), $second_held->get_id() ), $ids );
		$this->assertSame( 2, $sut->count() );
		$this->assertNotContains( $processing->get_id(), $ids );
		$this->assertNotContains( $not_held->get_id(), $ids );
		$this->assertNotContains( $other_gate->get_id(), $ids );
	}

	/**
	 * @testdox Should return no orders and a count of zero when none is held.
	 */
	public function test_nothing_held(): void {
		$this->order( 'on-hold', 'ppcp-gateway', null );
		$sut = new HeldOrders();

		$this->assertSame( array(), $sut->all() );
		$this->assertSame( 0, $sut->count() );
		$this->assertNull( $sut->earliest_deadline() );
	}

	/**
	 * @testdox Should give each held order a deadline of 30 days after its own held-at, and the earliest across them.
	 */
	public function test_deadlines(): void {
		$early = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$late  = $this->order( 'on-hold', 'ppcp-gateway', 5000 );
		$this->order( 'processing', 'ppcp-gateway', 10 );
		$sut = new HeldOrders();

		$this->assertSame( 1000 + 30 * DAY_IN_SECONDS, $sut->deadline_for( $early ) );
		$this->assertSame( 5000 + 30 * DAY_IN_SECONDS, $sut->deadline_for( $late ) );
		$this->assertSame( 1000 + 30 * DAY_IN_SECONDS, $sut->earliest_deadline(), 'A released or settled order does not count' );
	}

	/**
	 * @testdox Should find the earliest deadline by loading the one oldest held order, however many are held.
	 */
	public function test_the_earliest_deadline_loads_one_order(): void {
		$this->order( 'on-hold', 'ppcp-gateway', 3000 );
		$this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$this->order( 'on-hold', 'ppcp-gateway', 2000 );
		$sut   = new HeldOrders();
		$loads = 0;
		$count = static function ( $class_name ) use ( &$loads ) {
			++$loads;
			return $class_name;
		};
		add_filter( 'woocommerce_order_class', $count );

		$deadline = $sut->earliest_deadline();

		remove_filter( 'woocommerce_order_class', $count );
		$this->assertSame( 1000 + 30 * DAY_IN_SECONDS, $deadline );
		$this->assertSame( 1, $loads, 'One order is loaded, not three' );
	}

	/**
	 * @testdox Should fall back to the order's creation date for the deadline when the held-at meta is missing.
	 */
	public function test_deadline_without_a_held_at_uses_the_creation_date(): void {
		$order = $this->order( 'on-hold', 'ppcp-gateway', null );
		$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
		$order->save();

		$this->assertSame( $order->get_date_created()->getTimestamp() + 30 * DAY_IN_SECONDS, ( new HeldOrders() )->deadline_for( $order ) );
	}

	/**
	 * @testdox Should tell a held order from one that is not, or is no longer on hold.
	 */
	public function test_is_held(): void {
		$sut = new HeldOrders();

		$this->assertTrue( $sut->is_held( $this->order( 'on-hold', 'ppcp-gateway', 1000 ) ) );
		$this->assertFalse( $sut->is_held( $this->order( 'on-hold', 'ppcp-gateway', null ) ) );
		$this->assertFalse( $sut->is_held( $this->order( 'processing', 'ppcp-gateway', 1000 ) ) );
	}

	/**
	 * @testdox Should release an order by deleting the three held metas and saving, so it leaves the held list.
	 */
	public function test_release(): void {
		$order = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$sut   = new HeldOrders();

		$sut->release( $order );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( '', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( '', $saved->get_meta( HeldCapture::HELD_AT_META_KEY, true ) );
		$this->assertSame( '', $saved->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ) );
		$this->assertSame( 0, $sut->count() );
		$this->assertFalse( $sut->is_held( $saved ) );
	}
}
