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
	 * @param string   $payee          The payee email the held order records; empty for none.
	 * @return WC_Order
	 */
	private function order( string $status, string $payment_method, ?int $held_at, string $payee = '' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		if ( null !== $held_at ) {
			// An order is held from the moment it is captured, so the order dates follow the held-at times.
			$order->set_date_created( $held_at );
			$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
			$order->update_meta_data( HeldCapture::HELD_AT_META_KEY, $held_at );
			$order->update_meta_data( HeldCapture::CAPTURE_ID_META_KEY, 'CAPTURE-' . $order->get_id() );
			if ( '' !== $payee ) {
				$order->update_meta_data( HeldCapture::PAYEE_META_KEY, $payee );
			}
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
	 * @testdox Should list the wallet orders with the held meta whatever their status, cancelled ones included, except refunded ones, and count them without loading orders.
	 */
	public function test_lists_the_wallet_orders_with_the_held_meta(): void {
		$held        = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$processing  = $this->order( 'processing', 'ppcp-gateway', 1500 );
		$not_held    = $this->order( 'on-hold', 'ppcp-gateway', null );
		$other_gate  = $this->order( 'on-hold', 'bacs', 1000 );
		$cancelled   = $this->order( 'cancelled', 'ppcp-gateway', 1000 );
		$refunded    = $this->order( 'refunded', 'ppcp-gateway', 1000 );
		$second_held = $this->order( 'on-hold', 'ppcp-gateway', 2000 );
		$sut         = new HeldOrders();

		$ids = $sut->ids( 25 );

		$this->assertSame( array( $held->get_id(), $cancelled->get_id(), $processing->get_id(), $second_held->get_id() ), $ids, 'Oldest first, ties by ID' );
		$this->assertSame( 4, $sut->count() );
		$this->assertNotContains( $not_held->get_id(), $ids );
		$this->assertNotContains( $other_gate->get_id(), $ids );
		$this->assertNotContains( $refunded->get_id(), $ids, 'A refunded order renders no Refund button and waits for nothing' );
	}

	/**
	 * @testdox Should page orders held in the same second by their ID, so a page never repeats or skips one.
	 */
	public function test_ids_breaks_ties_by_id(): void {
		$first  = $this->order( 'on-hold', 'ppcp-gateway', 5000 );
		$second = $this->order( 'on-hold', 'ppcp-gateway', 5000 );
		$third  = $this->order( 'on-hold', 'ppcp-gateway', 5000 );
		$sut    = new HeldOrders();

		$this->assertSame( array( $first->get_id(), $second->get_id(), $third->get_id() ), $sut->ids( 3 ) );
		$this->assertSame( array( $second->get_id() ), $sut->ids( 1, 1 ) );
	}

	/**
	 * @testdox Should not list an order whose held meta is empty, as is_held() does not count it.
	 */
	public function test_an_empty_held_meta_is_not_listed(): void {
		$order = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, '' );
		$order->save();
		$sut = new HeldOrders();

		$this->assertFalse( $sut->is_held( $order ) );
		$this->assertSame( array(), $sut->ids( 25 ) );
		$this->assertSame( 0, $sut->count() );
	}

	/**
	 * @testdox Should list a page of held order IDs from an offset, at most the limit.
	 */
	public function test_ids_pages_through_the_held_orders(): void {
		$first  = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$second = $this->order( 'on-hold', 'ppcp-gateway', 2000 );
		$third  = $this->order( 'on-hold', 'ppcp-gateway', 3000 );
		$sut    = new HeldOrders();

		$this->assertSame( array( $first->get_id(), $second->get_id() ), $sut->ids( 2 ) );
		$this->assertSame( array( $third->get_id() ), $sut->ids( 2, 2 ) );
		$this->assertSame( array(), $sut->ids( 2, 3 ) );
	}

	/**
	 * @testdox Should return no orders and a count of zero when none is held.
	 */
	public function test_nothing_held(): void {
		$this->order( 'on-hold', 'ppcp-gateway', null );
		$sut = new HeldOrders();

		$this->assertSame( array(), $sut->ids( 25 ) );
		$this->assertSame( 0, $sut->count() );
		$this->assertNull( $sut->earliest_deadline() );
	}

	/**
	 * @testdox Should give each held order a deadline of 30 days after its own held-at, and the earliest across them.
	 */
	public function test_deadlines(): void {
		$early = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$late  = $this->order( 'on-hold', 'ppcp-gateway', 5000 );
		$this->order( 'refunded', 'ppcp-gateway', 10 );
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
	 * @testdox Should tell a held order by its held meta alone, whatever its status.
	 */
	public function test_is_held(): void {
		$sut = new HeldOrders();

		$this->assertTrue( $sut->is_held( $this->order( 'on-hold', 'ppcp-gateway', 1000 ) ) );
		$this->assertFalse( $sut->is_held( $this->order( 'on-hold', 'ppcp-gateway', null ) ) );
		$this->assertTrue( $sut->is_held( $this->order( 'processing', 'ppcp-gateway', 1000 ) ), 'Moved on by hand, still held' );
		$this->assertTrue( $sut->is_held( $this->order( 'cancelled', 'ppcp-gateway', 1000 ) ), 'Cancelled by hand, still held' );
	}

	/**
	 * @testdox Should release an order by deleting the held metas and saving, so it leaves the held list.
	 */
	public function test_release(): void {
		$order = $this->order( 'on-hold', 'ppcp-gateway', 1000 );
		$sut   = new HeldOrders();

		$sut->release( $order );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( '', $saved->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) );
		$this->assertSame( '', $saved->get_meta( HeldCapture::HELD_AT_META_KEY, true ) );
		$this->assertSame( '', $saved->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ) );
		$this->assertSame( '', $saved->get_meta( HeldCapture::PAYEE_META_KEY, true ) );
		$this->assertSame( 0, $sut->count() );
		$this->assertFalse( $sut->is_held( $saved ) );
	}

	/**
	 * @testdox Should count the held orders that record a payee other than the one asked about, and not those that record the same payee, record none, or are not held.
	 */
	public function test_counts_the_orders_held_for_another_payee(): void {
		$this->order( 'on-hold', 'ppcp-gateway', 1000, 'payee@example.com' );
		$this->order( 'on-hold', 'ppcp-gateway', 1100, 'payee@example.com' );
		$this->order( 'on-hold', 'ppcp-gateway', 1200 );
		$this->order( 'on-hold', 'ppcp-gateway', null );
		$this->order( 'on-hold', 'bacs', 1300 );
		$sut = new HeldOrders();

		$this->assertSame( 0, $sut->count_for_other_payee( 'payee@example.com' ), 'The same payee, an order that records none and orders that are not held are not another payee' );
		$this->assertSame( 0, $sut->count_for_other_payee( 'Payee@Example.COM' ), 'The lookup ignores case, whatever the collation' );
		$this->assertSame( 2, $sut->count_for_other_payee( 'other@example.com' ) );

		$this->order( 'cancelled', 'ppcp-gateway', 1400, 'other@example.com' );
		$this->assertSame( 2, $sut->count_for_other_payee( 'other@example.com' ), 'A held order of the asked payee is not counted' );
		$this->assertSame( 3, $sut->count_for_other_payee( 'third@example.com' ) );
	}
}
