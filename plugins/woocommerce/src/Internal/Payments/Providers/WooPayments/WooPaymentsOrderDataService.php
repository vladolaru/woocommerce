<?php
/**
 * WooPaymentsOrderDataService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

/**
 * Builds the order data sent to the platform (billing details, shipping, amounts in minor units), reads a charge's
 * settlement exchange rate into order meta, and titles the Fee details note.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsOrderDataService {

	private const META_KEY_STRIPE_EXCHANGE_RATE = '_wcpay_multi_currency_stripe_exchange_rate';

	/**
	 * Check a direct refund against the stored balance before any order getter can round it for display.
	 *
	 * @param WC_Order $order Order under the payment lock.
	 * @param int      $amount Requested amount in provider minor units.
	 * @return bool
	 */
	public function can_cover_refund_amount( WC_Order $order, int $amount ): bool {
		global $wpdb;

		$scale = 10 ** WooPaymentsCurrencyUtils::get_stripe_minor_unit_for_currency( $order->get_currency() );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS stores refund totals as negative decimals.
			$covered = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT %d <= %d * (o.total_amount + COALESCE((SELECT SUM(r.total_amount) FROM {$wpdb->prefix}wc_orders r WHERE r.parent_order_id = o.id AND r.type = 'shop_order_refund'), 0))
					FROM {$wpdb->prefix}wc_orders o WHERE o.id = %d",
					$amount,
					$scale,
					$order->get_id()
				)
			);
		} else {
			// Legacy refund amounts are positive strings. Cast before summing to keep the decimal arithmetic exact.
			$covered = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT %d <= %d * (CAST(o.meta_value AS DECIMAL(65,30)) - COALESCE((
						SELECT SUM(CAST(r.meta_value AS DECIMAL(65,30))) FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_refund_amount'
						WHERE p.post_parent = o.post_id AND p.post_type = 'shop_order_refund'
					), 0)) FROM {$wpdb->postmeta} o WHERE o.post_id = %d AND o.meta_key = '_order_total' ORDER BY o.meta_id LIMIT 1",
					$amount,
					$scale,
					$order->get_id()
				)
			);
		}

		return '1' === (string) $covered;
	}

	/**
	 * Build the billing-details payload for payment method updates.
	 *
	 * Sends each billing field the checkout shows for the order's country, empty ones included, so a cleared field
	 * also clears at the provider; only an empty country is dropped. Client 11.1.0 does the
	 * same in `WC_Payments_Order_Service::get_billing_data_from_order()` (class-wc-payments-order-service.php:1455-1488).
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array<string,mixed>
	 */
	public function get_billing_data_from_order( WC_Order $order ): array {
		$billing_fields       = array_keys( WC()->countries->get_address_fields( $order->get_billing_country() ) );
		$address_field_to_key = array(
			'billing_city'      => 'city',
			'billing_country'   => 'country',
			'billing_address_1' => 'line1',
			'billing_address_2' => 'line2',
			'billing_postcode'  => 'postal_code',
			'billing_state'     => 'state',
		);
		$field_to_key         = array(
			'billing_email' => 'email',
			'billing_phone' => 'phone',
		);
		$address              = array();
		$billing_details      = array();
		foreach ( $billing_fields as $field ) {
			if ( isset( $address_field_to_key[ $field ] ) ) {
				$address[ $address_field_to_key[ $field ] ] = $order->{"get_{$field}"}();
			} elseif ( isset( $field_to_key[ $field ] ) ) {
				$billing_details[ $field_to_key[ $field ] ] = $order->{"get_{$field}"}();
			}
		}

		if ( in_array( 'billing_first_name', $billing_fields, true ) && in_array( 'billing_last_name', $billing_fields, true ) ) {
			$billing_details['name'] = trim( $order->get_formatted_billing_full_name() );
		}

		if ( empty( $address['country'] ) ) {
			unset( $address['country'] );
		}

		return array_merge( array( 'address' => $address ), $billing_details );
	}

	/**
	 * Build the shipping payload for a provider intent from the order's shipping address.
	 *
	 * Client 11.1.0 does the same in `WC_Payments_Order_Service::get_shipping_data_from_order()` (class-wc-payments-order-service.php:1426-1446).
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array{name:string,address:array<string,string>}
	 */
	public function get_shipping_data_from_order( WC_Order $order ): array {
		return array(
			'name'    => implode( ' ', array_filter( array( $order->get_shipping_first_name(), $order->get_shipping_last_name() ) ) ),
			'address' => array(
				'line1'       => $order->get_shipping_address_1(),
				'line2'       => $order->get_shipping_address_2(),
				'postal_code' => $order->get_shipping_postcode(),
				'city'        => $order->get_shipping_city(),
				'state'       => $order->get_shipping_state(),
				'country'     => $order->get_shipping_country(),
			),
		);
	}

	/**
	 * Get the fee breakdown order note from a captured timeline event.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $event Platform timeline event.
	 * @return string Empty when the event is not a captured event or cannot be rendered.
	 */
	public function get_fee_breakdown_note_from_timeline_event( array $event ): string {
		if ( 'captured' !== ( $event['type'] ?? null ) ) {
			return '';
		}

		$details = ( new WooPaymentsCapturedEventNote( $event ) )->generate_html_note();

		return '' === $details ? '' : $this->get_fee_details_note_title() . $details;
	}

	/**
	 * Get settlement exchange-rate meta from a completed provider charge.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order            $order                    Order being charged.
	 * @param array<string,mixed> $charge                   Platform Charge response.
	 * @param string              $account_default_currency WooPayments account default currency.
	 * @return array<string,string>
	 */
	public function get_settlement_exchange_rate_order_meta( WC_Order $order, array $charge, string $account_default_currency ): array {
		$store_currency   = strtolower( trim( (string) get_option( 'woocommerce_currency', '' ) ) );
		$order_currency   = strtolower( trim( (string) $order->get_currency() ) );
		$account_currency = strtolower( trim( $account_default_currency ) );

		if (
			'' === $store_currency ||
			'' === $order_currency ||
			'' === $account_currency ||
			$store_currency !== $account_currency ||
			$order_currency === $account_currency
		) {
			return array();
		}

		$balance_transaction = is_array( $charge['balance_transaction'] ?? null ) ? $charge['balance_transaction'] : array();
		$exchange_rate       = $balance_transaction['exchange_rate'] ?? null;
		if ( ! is_numeric( $exchange_rate ) ) {
			return array();
		}

		return array(
			self::META_KEY_STRIPE_EXCHANGE_RATE => $this->format_exchange_rate(
				WooPaymentsCurrencyUtils::interpret_string_exchange_rate( (float) $exchange_rate, $order_currency, $account_currency )
			),
		);
	}

	/**
	 * Get the translated fee-details note title.
	 *
	 * @return string
	 */
	private function get_fee_details_note_title(): string {
		return WooPaymentsHtmlUtils::escape_interpolated_html(
			// phpcs:ignore WordPress.WP.I18n.NoHtmlWrappedStrings
			__( '<strong>Fee details:</strong>', 'woocommerce' ),
			array(
				'strong' => '<strong>',
			)
		);
	}

	/**
	 * Format a provider exchange rate without changing its meaningful precision.
	 *
	 * @param mixed $exchange_rate Provider exchange rate.
	 * @return string
	 */
	private function format_exchange_rate( $exchange_rate ): string {
		$formatted = (string) $exchange_rate;

		if ( false === strpos( $formatted, '.' ) ) {
			return $formatted;
		}

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}
}
