<?php
/**
 * WooPaymentsLevel3Service class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Level 3 (enhanced interchange) data for native WooPayments charges.
 *
 * Ports the WooPayments plugin's Level3Service: for US merchant accounts,
 * every charge and capture carries per-line-item card-network data so
 * commercial-card transactions qualify for Level 2/3 interchange rates.
 * Non-US accounts send nothing — the networks only price it domestically.
 *
 * Builds exclusively from the already-loaded order object: no queries.
 *
 * @internal
 */
class WooPaymentsLevel3Service {

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * Initialize the service.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService   $account_service    Account service.
	 * @param WooPaymentsOrderDataService $order_data_service Order data service.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsOrderDataService $order_data_service ): void {
		$this->account_service    = $account_service;
		$this->order_data_service = $order_data_service;
	}

	/**
	 * Build the Level 3 data for an order.
	 *
	 * @param WC_Order $order Order being charged or captured.
	 * @return array<string,mixed> Level 3 payload, or an empty array when the account does not qualify.
	 */
	public function get_data_from_order( WC_Order $order ): array {
		// The card networks only price Level 2/3 interchange for US merchants.
		if ( 'US' !== $this->account_service->get_account_country() ) {
			return array();
		}

		$order_items = array_values( $order->get_items( array( 'line_item', 'fee' ) ) );
		$currency    = (string) $order->get_currency();

		$items_to_send = array();
		foreach ( $order_items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product && ! $item instanceof WC_Order_Item_Fee ) {
				continue;
			}
			$items_to_send = array_merge( $items_to_send, $this->process_item( $item, $currency ) );
		}

		$level3_data = array(
			'merchant_reference' => (string) $order->get_id(),
			'customer_reference' => (string) $order->get_id(),
			'shipping_amount'    => $this->order_data_service->prepare_amount( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), $currency ),
			'line_items'         => $items_to_send,
		);

		$shipping_address_zip = $order->get_shipping_postcode();
		if ( $this->is_valid_us_zip_code( (string) $shipping_address_zip ) ) {
			$level3_data['shipping_address_zip'] = $shipping_address_zip;
		}

		$store_postcode = get_option( 'woocommerce_store_postcode' );
		if ( is_string( $store_postcode ) && $this->is_valid_us_zip_code( $store_postcode ) ) {
			$level3_data['shipping_from_zip'] = $store_postcode;
		}

		/**
		 * Filters the Level 3 data based on order.
		 *
		 * Kept under the WooPayments plugin's filter name: extensions (gift
		 * cards, for example) already hook it to adjust discounts.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed> $level3_data Precalculated Level 3 data based on order.
		 * @param WC_Order            $order       The order object.
		 */
		$level3_data = apply_filters( 'wcpay_payment_request_level3_data', $level3_data, $order );

		if ( is_array( $level3_data['line_items'] ?? null ) && count( $level3_data['line_items'] ) > 200 ) {
			// The networks accept at most 200 line items; bundle the tail into one.
			$level3_data['line_items'] = array_merge(
				array_slice( $level3_data['line_items'], 0, 199 ),
				array( $this->bundle_level3_data_from_items( array_slice( $level3_data['line_items'], 199 ) ) )
			);
		}

		if ( ! is_array( $level3_data ) ) {
			return array();
		}

		// The provider requires at least one line item; an item-less order
		// (fees stripped by the filter, deposits, zero-item edge cases) gets
		// the plugin's synthetic placeholder instead of an empty list.
		if ( ! isset( $level3_data['line_items'] ) || ! is_array( $level3_data['line_items'] ) || 0 === count( $level3_data['line_items'] ) ) {
			$level3_data['line_items'] = array(
				(object) array(
					'discount_amount'     => 0,
					'product_code'        => 'empty-order',
					'product_description' => 'The order is empty',
					'quantity'            => 1,
					'tax_amount'          => 0,
					'unit_cost'           => 0,
				),
			);
		}

		return $level3_data;
	}

	/**
	 * Process a single order item into Level 3 line items.
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Fee $item     Line item or fee.
	 * @param string                                  $currency Order currency.
	 * @return array<int,\stdClass>
	 */
	private function process_item( $item, string $currency ): array {
		if ( $item instanceof WC_Order_Item_Product ) {
			$subtotal     = (float) $item->get_subtotal();
			$product_id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$product_code = substr( (string) $product_id, 0, 12 );
		} else {
			$subtotal     = (float) $item->get_total();
			$product_code = substr( sanitize_title( $item->get_name() ), 0, 12 );
		}

		$description = substr( $item->get_name(), 0, 26 );
		// Admin-created orders can carry zero-quantity items; never divide by
		// zero on the money path (the plugin would fatal here — deliberate
		// hardening, not parity).
		$quantity   = max( 1, (int) ceil( (float) $item->get_quantity() ) );
		$tax_amount = $this->order_data_service->prepare_amount( (float) $item->get_total_tax(), $currency );
		if ( $subtotal >= 0 ) {
			$unit_cost       = $this->order_data_service->prepare_amount( $subtotal / $quantity, $currency );
			$discount_amount = $this->order_data_service->prepare_amount( $subtotal - (float) $item->get_total(), $currency );
		} else {
			// Products can carry a negative price — represent it as a free item with a discount.
			$discount_amount = abs( $this->order_data_service->prepare_amount( $subtotal / $quantity, $currency ) );
			$unit_cost       = 0;
		}

		// Tax must not be negative either — fold a refund-shaped tax into the discount.
		if ( $tax_amount < 0 ) {
			$discount_amount += abs( $tax_amount );
			$tax_amount       = 0;
		}

		$line_item  = (object) array(
			'product_code'        => (string) $product_code,
			'product_description' => $description,
			'unit_cost'           => $unit_cost,
			'quantity'            => $quantity,
			'tax_amount'          => $tax_amount,
			'discount_amount'     => $discount_amount,
		);
		$line_items = array( $line_item );

		// Rounding after the per-unit division can drop cents (10/3 at two
		// decimals is 3.33, but 3.33*3 is 9.99); re-add the difference so the
		// line items still sum to the charged amount.
		if ( $subtotal > 0 ) {
			$prepared_subtotal = $this->order_data_service->prepare_amount( $subtotal, $currency );
			$difference        = $prepared_subtotal - ( $unit_cost * $quantity );
			if ( $difference > 0 ) {
				$line_items[] = (object) array(
					'product_code'        => 'rounding-fix',
					'product_description' => __( 'Rounding fix', 'woocommerce' ),
					'unit_cost'           => $difference,
					'quantity'            => 1,
					'tax_amount'          => 0,
					'discount_amount'     => 0,
				);
			}
		}

		return $line_items;
	}

	/**
	 * Bundle overflow line items into a single Level 3 item.
	 *
	 * @param array<int,\stdClass> $items Level 3 items beyond the network limit.
	 * @return \stdClass
	 */
	private function bundle_level3_data_from_items( array $items ): \stdClass {
		$items_count = count( $items );
		$total_cost  = array_sum(
			array_map(
				static function ( $cost, $qty ) {
					return $cost * $qty;
				},
				array_column( $items, 'unit_cost' ),
				array_column( $items, 'quantity' )
			)
		);

		return (object) array(
			'product_code'        => (string) substr( uniqid(), 0, 26 ),
			'product_description' => "{$items_count} more items",
			'unit_cost'           => $total_cost,
			'quantity'            => 1,
			'tax_amount'          => array_sum( array_column( $items, 'tax_amount' ) ),
			'discount_amount'     => array_sum( array_column( $items, 'discount_amount' ) ),
		);
	}

	/**
	 * Tell whether a value is a valid US ZIP code.
	 *
	 * @param string $zip Candidate ZIP code.
	 * @return bool
	 */
	private function is_valid_us_zip_code( string $zip ): bool {
		return '' !== $zip && (bool) preg_match( '/^\d{5}(-\d{4})?$/', $zip );
	}
}
