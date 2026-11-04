<?php
/**
 * RefundLineItems class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Utilities\NumberUtil;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Builds the `line_items` of a webhook refund that names the order's lines, at what is left to refund on each line.
 *
 * Each line gets the units not yet refunded, with its remaining total and tax per rate, read with core's
 * `WC_Order::get_qty_refunded_for_item()`, `get_total_refunded_for_item()` and `get_tax_refunded_for_item()`. A line
 * with no units left is left out. This matches core's order screen refund (`WC_AJAX::refund_line_items()`), which
 * refunds a line only up to its units not yet refunded.
 *
 * @since 11.2.0
 * @internal
 */
final class RefundLineItems {

	/**
	 * Get the order's line items at what is left to refund, keyed by item ID, in the shape `wc_create_refund()` takes.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int,array{qty:float,refund_total:float,refund_tax:array<int|string,float>}>
	 */
	public static function get_remaining( WC_Order $order ): array {
		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$item_id       = (int) $item_id;
			$remaining_qty = NumberUtil::round( abs( (float) $item->get_quantity() ) - abs( (float) $order->get_qty_refunded_for_item( $item_id ) ), WC_ROUNDING_PRECISION );
			if ( $remaining_qty <= 0 ) {
				continue;
			}

			$refund_tax = array();
			$taxes      = $item->get_taxes();
			foreach ( $taxes['total'] ?? array() as $tax_id => $tax_total ) {
				$refund_tax[ $tax_id ] = (float) $tax_total - (float) $order->get_tax_refunded_for_item( $item_id, $tax_id );
			}

			$line_items[ $item_id ] = array(
				'qty'          => $remaining_qty,
				'refund_total' => (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id ),
				'refund_tax'   => $refund_tax,
			);
		}

		return $line_items;
	}
}
