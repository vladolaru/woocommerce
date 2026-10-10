<?php
/**
 * HeldOrders class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use WC_Order;

/**
 * The orders whose payment PayPal holds for the collecting payee, found by an order query on the held-capture meta.
 *
 * A held order is an on-hold wallet order that carries the held-capture meta. Nothing is stored apart from the orders
 * themselves, so each order keeps its own deadline. The query goes through wc_get_orders(), which works with orders in
 * posts and with high-performance order storage.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class HeldOrders implements HeldOrdersCount {

	/**
	 * How long PayPal holds a payment for a payee that has not finished setup, before it returns the payment to the buyer.
	 *
	 * @since 11.3.0
	 */
	public const HOLD_PERIOD = 30 * DAY_IN_SECONDS;

	/**
	 * The held orders.
	 *
	 * @since 11.3.0
	 *
	 * @return WC_Order[]
	 */
	public function all(): array {
		$orders = wc_get_orders( array_merge( $this->query_args(), array( 'limit' => -1 ) ) );

		return is_array( $orders ) ? array_values( $orders ) : array();
	}

	/**
	 * The oldest held orders, oldest first, so the earliest deadline leads. Loads at most `$limit` orders.
	 *
	 * @since 11.3.0
	 *
	 * @param int $limit The most orders to load; at least one.
	 * @return WC_Order[]
	 */
	public function oldest( int $limit ): array {
		$orders = wc_get_orders(
			array_merge(
				$this->query_args(),
				array(
					'limit'   => max( 1, $limit ),
					'orderby' => 'date',
					'order'   => 'ASC',
				)
			)
		);

		return is_array( $orders ) ? array_values( $orders ) : array();
	}

	/**
	 * The number of held orders. Runs a count query: no order is loaded.
	 *
	 * @since 11.3.0
	 *
	 * @return int
	 */
	public function count(): int {
		$result = wc_get_orders(
			array_merge(
				$this->query_args(),
				array(
					'limit'    => 1,
					'paginate' => true,
					'return'   => 'ids',
				)
			)
		);

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * The earliest deadline across the held orders, or null when none is held.
	 *
	 * Loads one order, not all of them: the oldest held order, because an order is held from the moment its payment is
	 * captured, so the oldest order is the one whose 30 days end first.
	 *
	 * @since 11.3.0
	 *
	 * @return int|null A UTC timestamp.
	 */
	public function earliest_deadline(): ?int {
		$ids   = wc_get_orders(
			array_merge(
				$this->query_args(),
				array(
					'limit'   => 1,
					'return'  => 'ids',
					'orderby' => 'date',
					'order'   => 'ASC',
				)
			)
		);
		$first = is_array( $ids ) && isset( $ids[0] ) ? $ids[0] : 0;
		$order = $first ? wc_get_order( $first ) : false;

		return $order instanceof WC_Order ? $this->deadline_for( $order ) : null;
	}

	/**
	 * The time by which PayPal needs the payee to finish setup for an order: 30 days after the capture was held.
	 *
	 * An order that carries no held-at time reads from its creation date, which is when the capture happened.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return int A UTC timestamp.
	 */
	public function deadline_for( WC_Order $order ): int {
		$held_at = (int) $order->get_meta( HeldCapture::HELD_AT_META_KEY, true );
		if ( $held_at <= 0 ) {
			$created = $order->get_date_created();
			$held_at = null === $created ? 0 : $created->getTimestamp();
		}

		return $held_at + self::HOLD_PERIOD;
	}

	/**
	 * Whether an order is held: an on-hold order that carries the held-capture meta, as the query reads it.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function is_held( WC_Order $order ): bool {
		return ! empty( $order->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) ) && $order->has_status( OrderStatus::ON_HOLD );
	}

	/**
	 * Release an order: delete its held meta so it is no longer held or refund-locked, and save it.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 */
	public function release( WC_Order $order ): void {
		$order->delete_meta_data( RefundLock::HELD_CAPTURE_META_KEY );
		$order->delete_meta_data( HeldCapture::HELD_AT_META_KEY );
		$order->delete_meta_data( HeldCapture::CAPTURE_ID_META_KEY );
		$order->save();
	}

	/**
	 * The arguments of the held-orders query, without a limit or a return type.
	 *
	 * @return array
	 */
	private function query_args(): array {
		return array(
			'payment_method' => PayPalGateway::ID,
			'status'         => OrderStatus::ON_HOLD,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The held-capture meta is the held-order marker, and the query is already narrowed to on-hold wallet orders.
				array(
					'key'     => RefundLock::HELD_CAPTURE_META_KEY,
					'compare' => 'EXISTS',
				),
			),
		);
	}
}
