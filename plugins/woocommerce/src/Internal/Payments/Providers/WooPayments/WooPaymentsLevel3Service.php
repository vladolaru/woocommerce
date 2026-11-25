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
 * Builds the Level 3 (enhanced interchange) data sent with WooPayments charges and captures.
 *
 * As client 11.3.0's Level3Service (src/Internal/Service/Level3Service.php:68-135): for US merchant accounts,
 * every charge and capture carries per-line-item card-network data so
 * commercial-card transactions qualify for Level 2/3 interchange rates.
 * Non-US accounts send nothing — the networks only price it domestically.
 *
 * Builds from the order object, the account country and the store postcode option, then passes
 * the result through the `wcpay_payment_request_level3_data` filter and matches it to the order
 * total, sending nothing when it cannot match. Loading the order's line items and fees can query
 * when the order has not loaded them yet, and reading the account country can fetch the account
 * from the platform when its cache is stale.
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
	 * Initialize the service.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Build the Level 3 data for an order.
	 *
	 * @param WC_Order $order Order being charged or captured.
	 * @return array<string,mixed> Level 3 payload, or an empty array when the account does not qualify or the data cannot match the order total.
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
			'shipping_amount'    => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), $currency ),
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
		 * Keeps this name because extensions listen to it. The result is matched to the order total afterwards, and
		 * dropped when it cannot match or carries a value the card networks refuse.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed> $level3_data Precalculated Level 3 data based on order.
		 * @param WC_Order            $order       The order object.
		 */
		$level3_data = apply_filters( 'wcpay_payment_request_level3_data', $level3_data, $order );

		$level3_data = $this->reconcile_with_order_total( $level3_data, $order );
		if ( array() === $level3_data ) {
			return array();
		}

		if ( count( $level3_data['line_items'] ) > 200 ) {
			// The networks accept at most 200 line items; bundle the tail into one.
			$level3_data['line_items'] = array_merge(
				array_slice( $level3_data['line_items'], 0, 199 ),
				array( $this->bundle_level3_data_from_items( array_slice( $level3_data['line_items'], 199 ) ) )
			);
		}

		// The provider requires at least one line item: an order of shipping only gets client 11.3.0's placeholder
		// item instead of an empty list (includes/core/server/request/trait-level3.php:20-37).
		if ( 0 === count( $level3_data['line_items'] ) ) {
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
	 * @return array<int,\stdClass> One line item, or none for an item with no units.
	 */
	private function process_item( $item, string $currency ): array {
		$quantity = (int) ceil( (float) $item->get_quantity() );
		// A line with no units has no unit cost. Leaving it out is safe: if it still carries money,
		// reconcile_with_order_total() covers a small gap with a rounding fix, or drops the Level 3 data.
		if ( $quantity <= 0 ) {
			return array();
		}

		if ( $item instanceof WC_Order_Item_Product ) {
			$subtotal     = (float) $item->get_subtotal();
			$product_id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$product_code = substr( (string) $product_id, 0, 12 );
		} else {
			$subtotal     = (float) $item->get_total();
			$product_code = substr( sanitize_title( $item->get_name() ), 0, 12 );
			// The provider refuses empty codes, and symbol-only fee names sanitize to nothing.
			if ( '' === $product_code ) {
				$product_code = 'fee';
			}
		}

		$description = substr( $item->get_name(), 0, 26 );
		// The provider refuses empty descriptions.
		if ( '' === trim( $description ) ) {
			$description = __( 'Item', 'woocommerce' );
		}

		$tax_amount = WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $item->get_total_tax(), $currency );
		if ( $subtotal >= 0 ) {
			$unit_cost       = WooPaymentsCurrencyUtils::amount_to_minor_units( $subtotal / $quantity, $currency );
			$discount_amount = WooPaymentsCurrencyUtils::amount_to_minor_units( $subtotal - (float) $item->get_total(), $currency );
		} else {
			// Products can carry a negative price — represent the line as free, discounted by its whole total.
			$discount_amount = abs( WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $item->get_total(), $currency ) );
			$unit_cost       = 0;
		}

		// Tax must not be negative either — fold a refund-shaped tax into the discount.
		if ( $tax_amount < 0 ) {
			$discount_amount += abs( $tax_amount );
			$tax_amount       = 0;
		}

		return array(
			(object) array(
				'product_code'        => (string) $product_code,
				'product_description' => $description,
				'unit_cost'           => $unit_cost,
				'quantity'            => $quantity,
				'tax_amount'          => $tax_amount,
				'discount_amount'     => $discount_amount,
			),
		);
	}

	/**
	 * Make the Level 3 line items add up to the order total, or drop the Level 3 data when they cannot.
	 *
	 * As client 11.3.0 (src/Internal/Service/Level3Service.php:215-272): the provider needs an exact match. A rounding
	 * gap gets a `rounding-fix` line; a bigger gap (a deposit, store credit) or an invalid value drops the data.
	 * Descriptions are cut to 26 characters, the most card networks keep.
	 *
	 * @param mixed    $level3_data Level 3 data, as returned by the filter.
	 * @param WC_Order $order       The order being charged or captured.
	 * @return array<string,mixed> The Level 3 data, or an empty array when none should be sent.
	 */
	private function reconcile_with_order_total( $level3_data, WC_Order $order ): array {
		if (
			! is_array( $level3_data )
			|| ! is_array( $level3_data['line_items'] ?? null )
			|| ! $this->is_level3_amount( $level3_data['shipping_amount'] ?? 0 )
		) {
			return array();
		}

		$level3_total = (int) ( $level3_data['shipping_amount'] ?? 0 );
		// Shipping is rounded once: up to a cent.
		$max_rounding_error = 1;
		foreach ( $level3_data['line_items'] as $index => $line_item ) {
			$line_item = (array) $line_item;
			if ( is_string( $line_item['product_description'] ?? null ) && mb_strlen( $line_item['product_description'] ) > 26 ) {
				$line_item['product_description']    = mb_substr( $line_item['product_description'], 0, 26 );
				$level3_data['line_items'][ $index ] = (object) $line_item;
			}

			$tax_amount      = $line_item['tax_amount'] ?? 0;
			$discount_amount = $line_item['discount_amount'] ?? 0;
			if (
				! $this->is_level3_string( $line_item['product_code'] ?? null, 12 )
				|| ! $this->is_level3_string( $line_item['product_description'] ?? null, 26 )
				|| ! $this->is_level3_amount( $line_item['unit_cost'] ?? null )
				|| ! $this->is_level3_amount( $line_item['quantity'] ?? null )
				|| ! $this->is_level3_amount( $tax_amount )
				|| ! $this->is_level3_amount( $discount_amount )
			) {
				return array();
			}

			$quantity      = (int) $line_item['quantity'];
			$level3_total += (int) $line_item['unit_cost'] * $quantity + (int) $tax_amount - (int) $discount_amount;
			// Half a cent per unit from the rounded unit cost, plus a cent for tax and discount.
			$max_rounding_error += (int) ceil( $quantity / 2 ) + 1;
		}

		$difference = WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), (string) $order->get_currency() ) - $level3_total;
		if ( 0 === $difference ) {
			return $level3_data;
		}

		if ( abs( $difference ) > $max_rounding_error ) {
			return array();
		}

		$level3_data['line_items'][] = (object) array(
			'product_code'        => 'rounding-fix',
			'product_description' => mb_substr( __( 'Rounding fix', 'woocommerce' ), 0, 26 ),
			'unit_cost'           => max( 0, $difference ),
			'quantity'            => 1,
			'tax_amount'          => 0,
			'discount_amount'     => max( 0, -$difference ),
		);

		return $level3_data;
	}

	/**
	 * Tell whether a Level 3 value is a non-negative whole number. Filtered quantities can be floats like 2.0.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private function is_level3_amount( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0 && floor( (float) $value ) === (float) $value;
	}

	/**
	 * Tell whether a Level 3 value is a non-empty string or integer of at most `$max_length` characters.
	 *
	 * @param mixed $value      Value to check.
	 * @param int   $max_length Maximum number of characters.
	 * @return bool
	 */
	private function is_level3_string( $value, int $max_length ): bool {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return false;
		}

		$length = mb_strlen( (string) $value );

		return $length > 0 && $length <= $max_length;
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
			'product_code'        => (string) substr( uniqid(), 0, 12 ),
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
