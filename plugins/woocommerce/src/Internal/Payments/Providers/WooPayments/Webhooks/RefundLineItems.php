<?php
/**
 * RefundLineItems class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\RestApi\Refunds\DataUtils;
use Automattic\WooCommerce\Internal\Utilities\CurrencyUtil;
use Automattic\WooCommerce\Utilities\NumberUtil;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Builds the `line_items` of a webhook refund that names the order's lines, at what is left to refund on each line.
 *
 * Each line gets the units not yet refunded (core's `WC_Order::get_qty_refunded_for_item()`), with its remaining total
 * and tax per rate. Those subtract the refund engine's unrounded history (`DataUtils::compute_refunded_quantities_and_totals()`)
 * and round once at the order currency's decimals, never below zero, since core's own getters round at the store's
 * decimals. A line with no units left is left out. This matches core's order screen refund (`WC_AJAX::refund_line_items()`),
 * which refunds a line only up to its units not yet refunded.
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
		$refunded   = wc_get_container()->get( DataUtils::class )->compute_refunded_quantities_and_totals( $order );
		$decimals   = CurrencyUtil::get_price_decimals( $order->get_currency() );
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
				$refund_tax[ $tax_id ] = self::get_amount_left( (float) $tax_total, (float) ( $refunded['tax_totals'][ $item_id ][ $tax_id ] ?? 0.0 ), $decimals );
			}

			$line_items[ $item_id ] = array(
				'qty'          => $remaining_qty,
				'refund_total' => self::get_amount_left( (float) $item->get_total(), (float) ( $refunded['net_totals'][ $item_id ] ?? 0.0 ), $decimals ),
				'refund_tax'   => $refund_tax,
			);
		}

		return $line_items;
	}

	/**
	 * Get what is left of a line amount after its refunds, rounded at the given decimals, with the line's sign.
	 *
	 * Earlier refunds that took more than the amount leave zero, so the refund line never lowers the line's refunded tally.
	 *
	 * @param float $line_amount The line's total or tax on one rate.
	 * @param float $refunded    What earlier refunds took of it.
	 * @param int   $decimals    The order currency's decimals.
	 * @return float
	 */
	private static function get_amount_left( float $line_amount, float $refunded, int $decimals ): float {
		$left = NumberUtil::round( max( 0.0, abs( $line_amount ) - abs( $refunded ) ), $decimals );

		return $line_amount < 0 ? -$left : $left;
	}
}
