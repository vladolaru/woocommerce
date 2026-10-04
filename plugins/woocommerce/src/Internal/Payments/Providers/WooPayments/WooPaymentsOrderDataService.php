<?php
/**
 * WooPaymentsOrderDataService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WC_Order;

/**
 * Builds WooPayments-compatible order data payloads and notes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderDataService {

	/**
	 * WC session key that proves this session completed a native PaymentIntent.
	 */
	public const PAID_INTENT_ID_SESSION_KEY = 'wcpay_paid_intent_id';

	private const META_KEY_STRIPE_EXCHANGE_RATE = '_wcpay_multi_currency_stripe_exchange_rate';

	/**
	 * Build the billing-details payload for payment method updates.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array<string,mixed>
	 */
	public function get_billing_data_from_order( WC_Order $order ): array {
		$billing_details = array();
		$address         = array_filter(
			array(
				'country'     => $order->get_billing_country(),
				'line1'       => $order->get_billing_address_1(),
				'line2'       => $order->get_billing_address_2(),
				'city'        => $order->get_billing_city(),
				'state'       => $order->get_billing_state(),
				'postal_code' => $order->get_billing_postcode(),
			),
			static fn( string $value ): bool => '' !== $value
		);

		if ( ! empty( $address ) ) {
			$billing_details['address'] = $address;
		}

		if ( '' !== $order->get_billing_email() ) {
			$billing_details['email'] = $order->get_billing_email();
		}

		if ( '' !== $order->get_billing_phone() ) {
			$billing_details['phone'] = $order->get_billing_phone();
		}

		if ( '' !== trim( $order->get_formatted_billing_full_name() ) ) {
			$billing_details['name'] = trim( $order->get_formatted_billing_full_name() );
		}

		return $billing_details;
	}

	/**
	 * Build the shipping payload for a provider intent from the order's shipping address.
	 *
	 * Port of client 11.1.0 `WC_Payments_Order_Service::get_shipping_data_from_order()` (class-wc-payments-order-service.php:1426-1446).
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
	 * @param array<string,mixed> $event Native timeline event.
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
	 * Convert a decimal amount into provider minor units.
	 *
	 * @since 11.0.0
	 *
	 * @param float  $amount   Decimal amount.
	 * @param string $currency Currency code.
	 * @return int
	 */
	public function prepare_amount( float $amount, string $currency ): int {
		return WooPaymentsCurrencyUtils::amount_to_minor_units( $amount, $currency );
	}

	/**
	 * Get settlement exchange-rate meta from a completed provider charge.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order            $order                    Order being charged.
	 * @param array<string,mixed> $charge                   Native Charge response.
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
				$this->interpret_string_exchange_rate( (float) $exchange_rate, $order_currency, $account_currency )
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
	 * Format a Stripe integer amount with an explicit currency code.
	 *
	 * @param int    $amount   Stripe integer amount.
	 * @param string $currency Currency code.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function format_explicit_currency_amount( int $amount, string $currency ): string {
		return $this->format_currency_minor_amount( $amount, $currency ) . ' ' . strtoupper( $currency );
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

	/**
	 * Interpret a Stripe exchange rate for presentment/base currency decimal semantics.
	 *
	 * @param float  $exchange_rate        Provider exchange rate.
	 * @param string $presentment_currency Currency the shopper paid in.
	 * @param string $base_currency        WooPayments account default currency.
	 * @return float
	 */
	private function interpret_string_exchange_rate( float $exchange_rate, string $presentment_currency, string $base_currency ): float {
		$is_presentment_currency_zero_decimal = WooPaymentsCurrencyUtils::is_zero_decimal_currency( $presentment_currency );
		$is_base_currency_zero_decimal        = WooPaymentsCurrencyUtils::is_zero_decimal_currency( $base_currency );

		if ( $is_presentment_currency_zero_decimal && ! $is_base_currency_zero_decimal ) {
			return $exchange_rate / 100;
		}

		if ( ! $is_presentment_currency_zero_decimal && $is_base_currency_zero_decimal ) {
			return $exchange_rate * 100;
		}

		return $exchange_rate;
	}

	/**
	 * Format a Stripe integer amount with its currency symbol.
	 *
	 * @param int    $amount   Stripe integer amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_currency_minor_amount( int $amount, string $currency ): string {
		$decimals = WooPaymentsCurrencyUtils::is_zero_decimal_currency( $currency ) ? 0 : 2;
		$value    = number_format( WooPaymentsCurrencyUtils::amount_from_minor_units( $amount, $currency ), $decimals, '.', '' );
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol( strtoupper( $currency ) ), ENT_QUOTES, 'UTF-8' );

		return $symbol . $value;
	}
}
