<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Tax;

/**
 * Builds an order with two taxed lines, refunds some of its units, and reads the lines a refund names, for the webhook
 * refund tests.
 *
 * Line A is three units at 10.00 with 1.00 tax each; line B is one unit at 20.00 with 2.00 tax. The order totals 55.00.
 */
trait RefundLinesTestTrait {

	/**
	 * Add lines A and B to the order, with their tax line, and total it.
	 *
	 * @param WC_Order $order Order to add the lines to.
	 * @return array<string,int> Line item IDs by line name.
	 */
	private function add_two_taxed_lines( WC_Order $order ): array {
		$rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'Tax',
				'tax_rate_priority' => '1',
				'tax_rate_order'    => '1',
			)
		);
		$items   = array();
		$lines   = array(
			'A' => array( 3, 30, 3 ),
			'B' => array( 1, 20, 2 ),
		);
		foreach ( $lines as $name => $line ) {
			$item = new WC_Order_Item_Product();
			$item->set_props(
				array(
					'name'     => $name,
					'quantity' => $line[0],
					'subtotal' => $line[1],
					'total'    => $line[1],
				)
			);
			$item->set_taxes(
				array(
					'total'    => array( $rate_id => $line[2] ),
					'subtotal' => array( $rate_id => $line[2] ),
				)
			);
			$order->add_item( $item );
			$items[ $name ] = $item;
		}
		$order->update_taxes();
		$order->calculate_totals( false );
		$order->save();
		$this->assertSame( '55.00', wc_format_decimal( $order->get_total(), 2 ) );

		return array_map( static fn( WC_Order_Item_Product $item ): int => $item->get_id(), $items );
	}

	/**
	 * Refund units of one line through WooCommerce, as the order screen does.
	 *
	 * @param WC_Order $order      Order to refund.
	 * @param int      $item_id    Line item ID.
	 * @param int      $units      Units to refund.
	 * @param bool     $with_money Whether the refund also returns the units' total and tax; without it, the amount is 0.00.
	 */
	private function refund_line_units( WC_Order $order, int $item_id, int $units, bool $with_money ): void {
		$item = $order->get_item( $item_id );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$rate_ids = array_keys( $item->get_taxes()['total'] );
		$refund   = wc_create_refund(
			array(
				'amount'     => $with_money ? 11 * $units : 0,
				'order_id'   => $order->get_id(),
				'line_items' => array(
					$item_id => array(
						'qty'          => $units,
						'refund_total' => $with_money ? 10 * $units : 0,
						'refund_tax'   => $with_money ? array( $rate_ids[0] => $units ) : array(),
					),
				),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
	}

	/**
	 * Read the lines a refund names, by line name, as "quantity total tax" (for example "-2 -20.00 -2.00").
	 *
	 * @param WC_Order_Refund $refund Refund to read.
	 * @return array<string,string>
	 */
	private function get_refund_lines( WC_Order_Refund $refund ): array {
		$lines = array();
		foreach ( $refund->get_items() as $item ) {
			$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
			$lines[ $item->get_name() ] = sprintf( '%s %s %s', $item->get_quantity(), wc_format_decimal( $item->get_total(), 2 ), wc_format_decimal( $item->get_total_tax(), 2 ) );
		}

		return $lines;
	}

	/**
	 * Read the units refunded so far on each line, by line name.
	 *
	 * @param WC_Order          $order Order to read.
	 * @param array<string,int> $items Line item IDs by line name.
	 * @return array<string,int>
	 */
	private function get_refunded_units( WC_Order $order, array $items ): array {
		return array_map( static fn( int $item_id ): int => (int) abs( $order->get_qty_refunded_for_item( $item_id ) ), $items );
	}
}
