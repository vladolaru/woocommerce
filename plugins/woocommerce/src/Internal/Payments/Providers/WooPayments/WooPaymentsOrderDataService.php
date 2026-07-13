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
	 * Get the fee breakdown order note from a PaymentIntent object.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intent           Native PaymentIntent response.
	 * @param bool                $use_first_charge Whether to use the first charge in the list.
	 * @return string
	 */
	public function get_fee_breakdown_note_from_intent( array $intent, bool $use_first_charge = true ): string {
		$charge = $this->get_charge( $intent, $use_first_charge );

		return $this->get_fee_breakdown_note_from_charge_like_data( $charge );
	}

	/**
	 * Tell whether a PaymentIntent fee-breakdown envelope includes a renderable fee rate.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intent           Native PaymentIntent response.
	 * @param bool                $use_first_charge Whether to use the first charge in the list.
	 * @return bool
	 */
	public function intent_has_fee_breakdown_rate( array $intent, bool $use_first_charge = true ): bool {
		$charge           = $this->get_charge( $intent, $use_first_charge );
		$fee_breakdown_v1 = $charge['fee_breakdown_v1'] ?? null;

		return is_array( $fee_breakdown_v1 ) && $this->fee_breakdown_has_rate( $fee_breakdown_v1 );
	}

	/**
	 * Tell whether a PaymentIntent fee-breakdown envelope should be refreshed before writing a persisted note.
	 *
	 * Early webhook payloads can include fee and net totals before the provider has attached the display rate. The note is persisted and de-duplicated, so a rate-less non-FX envelope should be refreshed from the latest intent before it is written.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intent           Native PaymentIntent response.
	 * @param bool                $use_first_charge Whether to use the first charge in the list.
	 * @return bool
	 */
	public function intent_needs_fee_breakdown_refresh( array $intent, bool $use_first_charge = true ): bool {
		return $this->charge_like_data_needs_fee_breakdown_refresh( $this->get_charge( $intent, $use_first_charge ) );
	}

	/**
	 * Tell whether a captured timeline event should be refreshed before writing a persisted note.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $event Native timeline event.
	 * @return bool
	 */
	public function timeline_event_needs_fee_breakdown_refresh( array $event ): bool {
		return 'captured' === ( $event['type'] ?? null ) && $this->charge_like_data_needs_fee_breakdown_refresh( $event );
	}

	/**
	 * Get the fee breakdown order note from a captured timeline event.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $event Native timeline event.
	 * @return string
	 */
	public function get_fee_breakdown_note_from_timeline_event( array $event ): string {
		if ( 'captured' !== ( $event['type'] ?? null ) ) {
			return '';
		}

		return $this->get_fee_breakdown_note_from_charge_like_data( $event );
	}

	/**
	 * Add the WooPayments fee-breakdown note when the native response includes it.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order            $order            Order being charged.
	 * @param array<string,mixed> $intent           Native PaymentIntent response.
	 * @param bool                $use_first_charge Whether to use the first charge in the list.
	 * @return bool True when a note was added.
	 */
	public function add_fee_breakdown_note_from_intent( WC_Order $order, array $intent, bool $use_first_charge = true ): bool {
		$charge = $this->get_charge( $intent, $use_first_charge );

		return $this->add_fee_breakdown_note(
			$order,
			$this->get_fee_breakdown_note_from_charge_like_data( $charge ),
			$this->get_fee_breakdown_note_marker( $charge )
		);
	}

	/**
	 * Add the WooPayments fee-breakdown note when the timeline includes it.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order            $order Order being updated.
	 * @param array<string,mixed> $event Native timeline event.
	 * @return bool True when a note was added.
	 */
	public function add_fee_breakdown_note_from_timeline_event( WC_Order $order, array $event ): bool {
		return $this->add_fee_breakdown_note(
			$order,
			$this->get_fee_breakdown_note_from_timeline_event( $event ),
			$this->get_fee_breakdown_note_marker( $event )
		);
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
	 * Add a fee-breakdown note if it is not already present.
	 *
	 * @param WC_Order $order  Order being updated.
	 * @param string   $note   Note content.
	 * @param string   $marker Stable provider event marker.
	 * @return bool True when a note was added.
	 */
	public function add_fee_breakdown_note( WC_Order $order, string $note, string $marker = '' ): bool {
		$identity = '' === $marker ? '' : 'fee:' . $marker;

		return wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $order, $note, $identity );
	}

	/**
	 * Get a fee-breakdown note from charge-shaped provider data.
	 *
	 * @param array<string,mixed> $charge Native charge or captured event response.
	 * @return string
	 */
	private function get_fee_breakdown_note_from_charge_like_data( array $charge ): string {
		$fee_breakdown_v1 = $charge['fee_breakdown_v1'] ?? null;
		if ( ! is_array( $fee_breakdown_v1 ) || ! $this->is_renderable_fee_breakdown( $fee_breakdown_v1 ) ) {
			return '';
		}

		$lines = array();
		$fx    = $fee_breakdown_v1['fx'] ?? null;
		if ( is_array( $fx ) && isset( $fx['from_currency'], $fx['to_currency'], $fx['to_amount'] ) ) {
			$exchange_rate = $fee_breakdown_v1['sources']['balance_transaction_exchange_rate'] ?? null;
			if ( is_numeric( $exchange_rate ) ) {
				$arrow   = html_entity_decode( '&rarr;', ENT_QUOTES, 'UTF-8' );
				$lines[] = sprintf(
					'1.00 %1$s %2$s %3$s %4$s: %5$s',
					strtoupper( (string) $fx['from_currency'] ),
					$arrow,
					$this->format_exchange_rate( $exchange_rate ),
					strtoupper( (string) $fx['to_currency'] ),
					$this->format_explicit_currency_amount( (int) $fx['to_amount'], (string) $fx['to_currency'] )
				);
			}
		}

		$fee_amount   = (int) $fee_breakdown_v1['totals']['fee']['amount'];
		$fee_currency = (string) $fee_breakdown_v1['totals']['fee']['currency'];
		$fee_rate     = $this->get_fee_breakdown_rate_text( $fee_breakdown_v1, $fee_currency );
		if ( '' !== $fee_rate ) {
			$lines[] = sprintf(
				/* translators: %1$s: fee rate, %2$s: fee amount. */
				__( 'Fee (%1$s): %2$s', 'woocommerce' ),
				$fee_rate,
				$this->format_explicit_currency_amount( $fee_amount, $fee_currency )
			);
		} else {
			$lines[] = sprintf(
				/* translators: %s: fee amount. */
				__( 'Fee: %1$s', 'woocommerce' ),
				$this->format_explicit_currency_amount( $fee_amount, $fee_currency )
			);
		}
		$this->append_fee_row_breakdown_lines( $lines, $fee_breakdown_v1, $fee_currency );

		$net_amount   = isset( $fee_breakdown_v1['totals']['capture_net']['amount'] ) ? (int) $fee_breakdown_v1['totals']['capture_net']['amount'] : (int) $fee_breakdown_v1['totals']['net']['amount'];
		$net_currency = (string) ( $fee_breakdown_v1['totals']['capture_net']['currency'] ?? $fee_breakdown_v1['totals']['net']['currency'] );
		$lines[]      = sprintf(
			/* translators: %s: net payout amount. */
			__( 'Net payout: %1$s', 'woocommerce' ),
			$this->format_explicit_currency_amount( $net_amount, $net_currency )
		);

		$html = '';
		foreach ( $lines as $line ) {
			$html .= '<p>' . $line . '</p>' . PHP_EOL;
		}

		return $this->get_fee_details_note_title() . '<div class="captured-event-details">' . PHP_EOL . $html . '</div>';
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
	 * Get a charge from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $intent           Native PaymentIntent response.
	 * @param bool                $use_first_charge Whether to use the first charge in the list.
	 * @return array<string,mixed>
	 */
	private function get_charge( array $intent, bool $use_first_charge ): array {
		$charges = isset( $intent['charges']['data'] ) && is_array( $intent['charges']['data'] ) ? $intent['charges']['data'] : array();
		$charge  = empty( $charges ) ? array() : ( $use_first_charge ? reset( $charges ) : end( $charges ) );

		return is_array( $charge ) ? $charge : array();
	}

	/**
	 * Tell whether a fee breakdown has the minimum shape needed for the order note.
	 *
	 * @param array<string,mixed> $fee_breakdown Fee breakdown envelope.
	 * @return bool
	 */
	private function is_renderable_fee_breakdown( array $fee_breakdown ): bool {
		return isset(
			$fee_breakdown['totals']['fee']['amount'],
			$fee_breakdown['totals']['fee']['currency'],
			$fee_breakdown['totals']['net']['amount'],
			$fee_breakdown['totals']['net']['currency']
		);
	}

	/**
	 * Tell whether a fee breakdown has a fee rate worth rendering.
	 *
	 * @param array<string,mixed> $fee_breakdown Fee breakdown envelope.
	 * @return bool
	 */
	private function fee_breakdown_has_rate( array $fee_breakdown ): bool {
		return '' !== $this->get_fee_breakdown_rate_text( $fee_breakdown, (string) ( $fee_breakdown['totals']['fee']['currency'] ?? '' ) );
	}

	/**
	 * Tell whether charge-shaped fee breakdown data should be refreshed before writing a persisted note.
	 *
	 * @param array<string,mixed> $charge Native charge or captured event response.
	 * @return bool
	 */
	private function charge_like_data_needs_fee_breakdown_refresh( array $charge ): bool {
		$fee_breakdown_v1 = $charge['fee_breakdown_v1'] ?? null;

		if ( ! is_array( $fee_breakdown_v1 ) || ! $this->is_renderable_fee_breakdown( $fee_breakdown_v1 ) ) {
			return true;
		}

		if ( $this->fee_breakdown_has_rate( $fee_breakdown_v1 ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the fee rate text for the totals row, deriving it from typed rows when needed.
	 *
	 * @param array<string,mixed> $fee_breakdown Fee breakdown envelope.
	 * @param string              $currency      Fallback currency code.
	 * @return string
	 */
	private function get_fee_breakdown_rate_text( array $fee_breakdown, string $currency ): string {
		$total_rate_text = $this->format_fee_rate_text( $fee_breakdown['totals']['fee']['rate'] ?? null, $currency );
		if ( '' !== $total_rate_text ) {
			return $total_rate_text;
		}

		return $this->derive_fee_rate_text_from_rows( $fee_breakdown, $currency );
	}

	/**
	 * Derive an aggregate fee rate from the server-provided row breakdown.
	 *
	 * @param array<string,mixed> $fee_breakdown Fee breakdown envelope.
	 * @param string              $currency      Fallback currency code.
	 * @return string
	 */
	private function derive_fee_rate_text_from_rows( array $fee_breakdown, string $currency ): string {
		$fee_rows = $this->get_fee_rows( $fee_breakdown );
		if ( empty( $fee_rows ) ) {
			return '';
		}

		$percentage     = 0.0;
		$fixed_minor    = 0;
		$fixed_currency = '';

		foreach ( $fee_rows as $row ) {
			$rate = $row['rate'] ?? null;
			if ( ! is_array( $rate ) || ! empty( $rate['capped'] ) ) {
				return '';
			}

			$percentage += isset( $rate['percentage'] ) && is_numeric( $rate['percentage'] ) ? (float) $rate['percentage'] : 0.0;
			$row_fixed   = isset( $rate['fixed'] ) && is_numeric( $rate['fixed'] ) ? (int) $rate['fixed'] : 0;
			if ( 0 === $row_fixed ) {
				continue;
			}

			$row_fixed_currency = (string) ( $rate['fixed_currency'] ?? $row['currency'] ?? $currency );
			if ( '' === $row_fixed_currency ) {
				return '';
			}

			if ( '' === $fixed_currency ) {
				$fixed_currency = $row_fixed_currency;
			} elseif ( $fixed_currency !== $row_fixed_currency ) {
				return '';
			}

			$fixed_minor += $row_fixed;
		}

		return $this->format_fee_rate_text(
			array(
				'percentage'     => $percentage,
				'fixed'          => $fixed_minor,
				'fixed_currency' => '' !== $fixed_currency ? $fixed_currency : $currency,
			),
			$currency
		);
	}

	/**
	 * Append per-row fee breakdown lines when the rows add detail beyond the totals line.
	 *
	 * @param array<int,string>   $lines         Note lines.
	 * @param array<string,mixed> $fee_breakdown Fee breakdown envelope.
	 * @param string              $currency      Fallback currency code.
	 */
	private function append_fee_row_breakdown_lines( array &$lines, array $fee_breakdown, string $currency ): void {
		$fee_rows = $this->get_fee_rows( $fee_breakdown );
		if ( count( $fee_rows ) <= 1 ) {
			return;
		}

		$indent = str_repeat( '&nbsp;', 4 );
		foreach ( $fee_rows as $row ) {
			$label         = $this->get_fee_row_label( $row );
			$row_currency  = (string) ( $row['rate']['fixed_currency'] ?? $row['currency'] ?? $currency );
			$row_rate_text = $this->format_fee_rate_text( $row['rate'] ?? null, $row_currency );
			$lines[]       = $indent . ( '' !== $row_rate_text ? sprintf( '%1$s: %2$s', esc_html( $label ), esc_html( $row_rate_text ) ) : esc_html( $label ) );
		}
	}

	/**
	 * Get non-tax fee rows from a fee breakdown envelope.
	 *
	 * @param array<string,mixed> $fee_breakdown Fee breakdown envelope.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_fee_rows( array $fee_breakdown ): array {
		$rows = $fee_breakdown['rows'] ?? array();
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$fee_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || 'tax' === ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$fee_rows[] = $row;
		}

		return $fee_rows;
	}

	/**
	 * Get a merchant-facing label for a fee row.
	 *
	 * @param array<string,mixed> $row Fee breakdown row.
	 * @return string
	 */
	private function get_fee_row_label( array $row ): string {
		if ( ! empty( $row['label'] ) ) {
			return (string) $row['label'];
		}

		switch ( (string) ( $row['key'] ?? '' ) ) {
			case 'base':
				return __( 'Base fee', 'woocommerce' );
			case 'additional.international':
				return __( 'International card fee', 'woocommerce' );
			case 'additional.fx':
				return __( 'Currency conversion fee', 'woocommerce' );
			case 'additional.wcpay-subscription':
				return __( 'Subscription transaction fee', 'woocommerce' );
			case 'additional.device':
				return __( 'Device fee', 'woocommerce' );
			case 'tax_on_fee':
				return __( 'Tax on fee', 'woocommerce' );
			case 'dispute_fee':
				return __( 'Dispute fee', 'woocommerce' );
			case 'dispute_fee_refund':
				return __( 'Dispute fee refund', 'woocommerce' );
			case 'refund_fee':
				return __( 'Refund fee', 'woocommerce' );
			case 'financing_paydown':
				return __( 'Loan paydown', 'woocommerce' );
		}

		$key = (string) ( $row['key'] ?? '' );
		if ( 0 === strpos( $key, 'discount.' ) ) {
			return __( 'Discount', 'woocommerce' );
		}

		return $key;
	}

	/**
	 * Format a provider fee rate for an order note.
	 *
	 * @param mixed  $rate     Provider fee rate data.
	 * @param string $currency Fallback currency code.
	 * @return string
	 */
	private function format_fee_rate_text( $rate, string $currency ): string {
		if ( ! is_array( $rate ) ) {
			return '';
		}

		$parts       = array();
		$percentage  = isset( $rate['percentage'] ) && is_numeric( $rate['percentage'] ) ? (float) $rate['percentage'] : 0.0;
		$fixed_minor = isset( $rate['fixed'] ) && is_numeric( $rate['fixed'] ) ? (int) $rate['fixed'] : 0;
		$fixed_curr  = isset( $rate['fixed_currency'] ) ? (string) $rate['fixed_currency'] : $currency;

		if ( 0.0 !== $percentage ) {
			$parts[] = $this->format_percentage_rate( $percentage ) . '%';
		}

		if ( 0 !== $fixed_minor && '' !== $fixed_curr ) {
			$parts[] = $this->format_currency_minor_amount( $fixed_minor, $fixed_curr );
		}

		return implode( ' + ', $parts );
	}

	/**
	 * Format a decimal fee rate as a percentage without insignificant zeroes.
	 *
	 * @param float $rate Decimal fee rate.
	 * @return string
	 */
	private function format_percentage_rate( float $rate ): string {
		return rtrim( rtrim( number_format( $rate * 100, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Format a Stripe integer amount with an explicit currency code.
	 *
	 * @param int    $amount   Stripe integer amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_explicit_currency_amount( int $amount, string $currency ): string {
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

	/**
	 * Get a stable fee-breakdown marker from charge-shaped data.
	 *
	 * @param array<string,mixed> $charge Charge or captured-event data.
	 * @return string
	 */
	private function get_fee_breakdown_note_marker( array $charge ): string {
		$charge_id = isset( $charge['id'] ) && is_scalar( $charge['id'] ) ? trim( (string) $charge['id'] ) : '';
		if ( '' !== $charge_id ) {
			return 'charge:' . $charge_id;
		}

		$balance_transaction = $charge['balance_transaction'] ?? null;
		$transaction_id      = is_array( $balance_transaction ) && isset( $balance_transaction['id'] )
			? trim( (string) $balance_transaction['id'] )
			: ( is_scalar( $balance_transaction ) ? trim( (string) $balance_transaction ) : '' );

		return '' !== $transaction_id ? 'balance_transaction:' . $transaction_id : '';
	}
}
