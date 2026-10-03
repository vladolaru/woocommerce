<?php
/**
 * WooPaymentsCapturedEventNote class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Fee details" order note from a captured timeline event.
 *
 * A port of client 11.1.0 `WC_Payments_Captured_Event_Note`: the note comes from the event's `fee_breakdown_v1` envelope when it
 * is renderable, and from `fee_rates` and `transaction_details` otherwise. Output must stay byte-identical to the client's.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsCapturedEventNote {

	private const HTML_BLACK_BULLET = '<span style="font-size: 7px;vertical-align: middle;">&#9679;</span>';
	private const HTML_WHITE_BULLET = '<span style="font-size: 7px;vertical-align: middle;">&#9675;</span>';
	private const HTML_SPACE        = '&nbsp;';

	/**
	 * Captured event data.
	 *
	 * @var array<string,mixed>
	 */
	private array $captured_event;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $captured_event Captured timeline event.
	 * @throws InvalidArgumentException When the event is not a captured event.
	 */
	public function __construct( array $captured_event ) {
		if ( 'captured' !== ( $captured_event['type'] ?? null ) ) {
			throw new InvalidArgumentException( 'Not a captured event' );
		}

		$this->captured_event = $captured_event;
	}

	/**
	 * Generate the HTML note.
	 *
	 * @return string Empty when the event has neither a renderable envelope nor fee rates; the client errors out and adds no note then.
	 */
	public function generate_html_note(): string {
		if ( ! empty( $this->captured_event['fee_breakdown_v1'] )
			&& is_array( $this->captured_event['fee_breakdown_v1'] )
			&& self::is_renderable_breakdown( $this->captured_event['fee_breakdown_v1'] ) ) {
			return $this->generate_html_note_from_breakdown( $this->captured_event['fee_breakdown_v1'] );
		}

		if ( ! isset( $this->captured_event['fee_rates'], $this->captured_event['transaction_details'] ) ) {
			return '';
		}

		$lines = array();

		$fx_string = $this->compose_fx_string();
		if ( null !== $fx_string ) {
			$lines[] = $fx_string;
		}

		$lines[] = $this->compose_fee_string();

		$fee_breakdown_lines = $this->compose_fee_break_down();
		if ( null !== $fee_breakdown_lines ) {
			$lines = array_merge( $lines, $fee_breakdown_lines );
		}

		if ( $this->has_tax() ) {
			// A zero tax gives null, which the client still prints as an empty paragraph.
			$lines[] = (string) $this->compose_tax_string();
		}

		$lines[] = $this->compose_net_string();

		return self::wrap_lines( $lines );
	}

	/**
	 * Render the note from the envelope's totals, rows and notes, with no arithmetic.
	 *
	 * @param array<string,mixed> $breakdown The `fee_breakdown_v1` envelope.
	 * @return string
	 */
	private function generate_html_note_from_breakdown( array $breakdown ): string {
		$store_currency   = (string) $breakdown['totals']['fee']['currency'];
		$total_fee_amount = (int) $breakdown['totals']['fee']['amount'];
		$total_tax_amount = (int) $breakdown['totals']['tax']['amount'];
		// The capture-time net keeps the note stable if it is regenerated after a refund or dispute.
		$total_net_amount = isset( $breakdown['totals']['capture_net']['amount'] )
			? (int) $breakdown['totals']['capture_net']['amount']
			: (int) $breakdown['totals']['net']['amount'];
		$net_currency     = (string) ( $breakdown['totals']['capture_net']['currency'] ?? ( $breakdown['totals']['net']['currency'] ?? $store_currency ) );

		$lines = array();

		$fx_string = $this->compose_fx_string();
		if ( null !== $fx_string ) {
			$lines[] = $fx_string;
		}

		$total_rate      = $breakdown['totals']['fee']['rate'] ?? null;
		$total_rate_text = self::format_rate_text( is_array( $total_rate ) ? $total_rate : null, $store_currency );
		$fee_amount_text = WooPaymentsCurrencyUtils::format_explicit_currency(
			WooPaymentsCurrencyUtils::amount_from_minor_units( $total_fee_amount, $store_currency ),
			$store_currency,
			false
		);
		$fee_line_label  = self::fee_label_from_key( isset( $breakdown['totals']['fee']['key'] ) ? (string) $breakdown['totals']['fee']['key'] : '' );
		$lines[]         = '' !== $total_rate_text
			? sprintf(
				/* translators: 1: fee label (e.g. "Fee") 2: fee rate (e.g. 2.9% + $0.30) 3: monetary amount */
				__( '%1$s (%2$s): %3$s', 'woocommerce' ),
				esc_html( $fee_line_label ),
				esc_html( $total_rate_text ),
				esc_html( $fee_amount_text )
			)
			: sprintf(
				/* translators: 1: fee label (e.g. "Fee" or "Processing fee") 2: monetary amount */
				__( '%1$s: %2$s', 'woocommerce' ),
				esc_html( $fee_line_label ),
				esc_html( $fee_amount_text )
			);

		// A single fee row adds nothing to the line above, so rows are listed only when there are several.
		$fee_rows = array_values(
			array_filter(
				is_array( $breakdown['rows'] ) ? $breakdown['rows'] : array(),
				static function ( $row ): bool {
					return is_array( $row ) && 'tax' !== ( $row['kind'] ?? '' );
				}
			)
		);

		if ( count( $fee_rows ) > 1 ) {
			$indent     = str_repeat( self::HTML_SPACE, 4 );
			$sub_indent = str_repeat( self::HTML_SPACE, 8 );
			foreach ( $fee_rows as $row ) {
				$label    = self::label_from_row( $row );
				$row_curr = (string) ( $row['rate']['fixed_currency'] ?? ( $row['currency'] ?? $store_currency ) );

				$adjustment_split = self::adjustment_split_lines( $row, $row_curr, $indent, $sub_indent );
				if ( null !== $adjustment_split ) {
					$lines = array_merge( $lines, $adjustment_split );
					continue;
				}

				$row_rate  = $row['rate'] ?? null;
				$rate_text = self::format_rate_text( is_array( $row_rate ) ? $row_rate : null, $row_curr );
				$lines[]   = $indent . ( '' !== $rate_text ? sprintf( '%1$s: %2$s', esc_html( $label ), esc_html( $rate_text ) ) : esc_html( $label ) );
			}
		}

		if ( 0 !== $total_tax_amount ) {
			$tax_row = null;
			foreach ( $breakdown['rows'] as $candidate ) {
				if ( is_array( $candidate ) && 'tax' === ( $candidate['kind'] ?? '' ) ) {
					$tax_row = $candidate;
					break;
				}
			}
			$tax_description = '';
			if ( null !== $tax_row && ! empty( $tax_row['label'] ) ) {
				$tax_description = ' ' . self::localize_tax_description_code( (string) $tax_row['label'] );
			}
			$tax_percentage = '';
			if ( null !== $tax_row && isset( $tax_row['rate']['percentage'] ) && 0.0 !== (float) $tax_row['rate']['percentage'] ) {
				$tax_percentage = ' (' . number_format( (float) $tax_row['rate']['percentage'] * 100, 2 ) . '%)';
			}
			$tax_currency    = (string) $breakdown['totals']['tax']['currency'];
			$tax_amount_text = WooPaymentsCurrencyUtils::format_currency(
				-abs( WooPaymentsCurrencyUtils::amount_from_minor_units( $total_tax_amount, $tax_currency ) ),
				$tax_currency
			);
			$lines[]         = sprintf(
				/* translators: 1: tax description 2: tax percentage 3: tax amount */
				__( 'Tax%1$s%2$s: %3$s', 'woocommerce' ),
				esc_html( $tax_description ),
				esc_html( $tax_percentage ),
				esc_html( $tax_amount_text )
			);
		}

		$lines[] = sprintf(
			/* translators: %s is a monetary amount */
			__( 'Net payout: %s', 'woocommerce' ),
			esc_html(
				WooPaymentsCurrencyUtils::format_explicit_currency(
					WooPaymentsCurrencyUtils::amount_from_minor_units( $total_net_amount, $net_currency ),
					$net_currency,
					false
				)
			)
		);

		if ( ! empty( $breakdown['notes'] ) && is_array( $breakdown['notes'] ) ) {
			foreach ( $breakdown['notes'] as $note ) {
				$note_text = is_array( $note ) ? self::text_from_note( $note ) : null;
				if ( null !== $note_text && '' !== $note_text ) {
					// Already escaped by text_from_note().
					$lines[] = $note_text;
				}
			}
		}

		return self::wrap_lines( $lines );
	}

	/**
	 * Wrap note lines in the client's markup.
	 *
	 * @param string[] $lines Note lines.
	 * @return string
	 */
	private static function wrap_lines( array $lines ): string {
		$html = '';
		foreach ( $lines as $line ) {
			$html .= '<p>' . $line . '</p>' . PHP_EOL;
		}

		return '<div class="captured-event-details">' . PHP_EOL . $html . '</div>';
	}

	/**
	 * Tell whether an envelope has the shape the renderer needs; otherwise the note falls back to `fee_rates`.
	 *
	 * @param array<string,mixed> $breakdown The `fee_breakdown_v1` envelope candidate.
	 * @return bool
	 */
	private static function is_renderable_breakdown( array $breakdown ): bool {
		return isset(
			$breakdown['totals']['fee']['amount'],
			$breakdown['totals']['fee']['currency'],
			$breakdown['totals']['tax']['amount'],
			$breakdown['rows']
		) && is_array( $breakdown['rows'] )
			&& (
				isset( $breakdown['totals']['capture_net']['amount'] )
				|| isset( $breakdown['totals']['net']['amount'] )
			);
	}

	/**
	 * Get the label of the total fee line from the envelope's typed key.
	 *
	 * @param string $key Key on `totals.fee`, or '' when absent.
	 * @return string
	 */
	private static function fee_label_from_key( string $key ): string {
		switch ( $key ) {
			case 'processing_fee':
				return __( 'Processing fee', 'woocommerce' );
			default:
				return __( 'Fee', 'woocommerce' );
		}
	}

	/**
	 * Get a fee row's label: the envelope's own label, escaped, or the one for its typed key.
	 *
	 * @param array<string,mixed> $row Row entry from the envelope.
	 * @return string
	 */
	private static function label_from_row( array $row ): string {
		if ( ! empty( $row['label'] ) ) {
			return esc_html( (string) $row['label'] );
		}
		$key = (string) ( $row['key'] ?? '' );

		$map = array(
			'base'                          => __( 'Base fee', 'woocommerce' ),
			'additional.international'      => __( 'International card fee', 'woocommerce' ),
			'additional.fx'                 => __( 'Currency conversion fee', 'woocommerce' ),
			'additional.wcpay-subscription' => __( 'Subscription transaction fee', 'woocommerce' ),
			'additional.device'             => __( 'Device fee', 'woocommerce' ),
			'tax_on_fee'                    => __( 'Tax on fee', 'woocommerce' ),
			'dispute_fee'                   => __( 'Dispute fee', 'woocommerce' ),
			'dispute_fee_refund'            => __( 'Dispute fee refund', 'woocommerce' ),
			'refund_fee'                    => __( 'Refund fee', 'woocommerce' ),
			'financing_paydown'             => __( 'Loan paydown', 'woocommerce' ),
		);
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		if ( 0 === strpos( $key, 'discount.' ) ) {
			return __( 'Discount', 'woocommerce' );
		}
		return $key;
	}

	/**
	 * Split an adjustment row with both a percentage and a fixed part into a label line and two sub-bullets.
	 *
	 * @param array<string,mixed> $row        Row entry from the envelope.
	 * @param string              $row_curr   Currency of the fixed part.
	 * @param string              $indent     Indent of the label line.
	 * @param string              $sub_indent Indent of the sub-bullets.
	 * @return string[]|null Null when the row is not such an adjustment.
	 */
	private static function adjustment_split_lines( array $row, string $row_curr, string $indent, string $sub_indent ): ?array {
		if ( 'adjustment' !== ( $row['kind'] ?? '' ) || empty( $row['rate'] ) || ! is_array( $row['rate'] ) ) {
			return null;
		}
		$rate        = $row['rate'];
		$percentage  = isset( $rate['percentage'] ) ? (float) $rate['percentage'] : 0.0;
		$fixed_minor = isset( $rate['fixed'] ) ? (int) $rate['fixed'] : 0;
		if ( 0.0 === $percentage || 0 === $fixed_minor ) {
			return null;
		}

		$label         = self::label_from_row( $row );
		$variable_text = self::format_fee( $percentage ) . '%';
		$fixed_text    = WooPaymentsCurrencyUtils::format_currency(
			WooPaymentsCurrencyUtils::amount_from_minor_units( $fixed_minor, $row_curr ),
			$row_curr
		);

		return array(
			$indent . esc_html( $label ),
			$sub_indent . self::HTML_WHITE_BULLET . ' ' . esc_html(
				sprintf(
					/* translators: %s is a percentage number */
					__( 'Variable fee: %s', 'woocommerce' ),
					$variable_text
				)
			),
			$sub_indent . self::HTML_WHITE_BULLET . ' ' . esc_html(
				sprintf(
					/* translators: %s is a monetary amount */
					__( 'Fixed fee: %s', 'woocommerce' ),
					$fixed_text
				)
			),
		);
	}

	/**
	 * Format an envelope rate as "2.9% + $0.30", or "capped at $X" for a capped fee.
	 *
	 * @param array<string,mixed>|null $rate           Rate with percentage, fixed and fixed_currency keys.
	 * @param string                   $store_currency Currency of the fixed part when the rate names none.
	 * @return string Empty when the rate has neither part.
	 */
	private static function format_rate_text( ?array $rate, string $store_currency ): string {
		if ( null === $rate ) {
			return '';
		}
		if ( ! empty( $rate['capped'] ) ) {
			$cap_amount = isset( $rate['cap_amount'] ) ? (int) $rate['cap_amount'] : (int) ( $rate['fixed'] ?? 0 );
			$cap_curr   = (string) ( $rate['fixed_currency'] ?? $store_currency );
			return sprintf(
				/* translators: %s is a monetary amount */
				__( 'capped at %s', 'woocommerce' ),
				WooPaymentsCurrencyUtils::format_currency(
					WooPaymentsCurrencyUtils::amount_from_minor_units( $cap_amount, $cap_curr ),
					$cap_curr
				)
			);
		}
		$parts       = array();
		$percentage  = isset( $rate['percentage'] ) ? (float) $rate['percentage'] : 0.0;
		$fixed_minor = isset( $rate['fixed'] ) ? (int) $rate['fixed'] : 0;
		$fixed_curr  = (string) ( $rate['fixed_currency'] ?? $store_currency );

		if ( 0.0 !== $percentage ) {
			$parts[] = self::format_fee( $percentage ) . '%';
		}
		if ( 0 !== $fixed_minor ) {
			$parts[] = WooPaymentsCurrencyUtils::format_currency(
				WooPaymentsCurrencyUtils::amount_from_minor_units( $fixed_minor, $fixed_curr ),
				$fixed_curr
			);
		}
		return implode( ' + ', $parts );
	}

	/**
	 * Get the merchant-facing text of an envelope note, escaped; null for internal-only codes.
	 *
	 * @param array<string,mixed> $note Note entry from the envelope.
	 * @return string|null
	 */
	private static function text_from_note( array $note ): ?string {
		$code = (string) ( $note['code'] ?? '' );
		$meta = is_array( $note['meta'] ?? null ) ? $note['meta'] : array();

		if ( 'application_fee_refunded' === $code ) {
			$refunded_amount   = isset( $meta['refunded_amount'] ) ? (int) $meta['refunded_amount'] : 0;
			$refunded_currency = (string) ( $meta['refunded_currency'] ?? '' );
			if ( $refunded_amount <= 0 || '' === $refunded_currency ) {
				return __( 'WooPayments refunded its application fee on this transaction.', 'woocommerce' );
			}
			$formatted = WooPaymentsCurrencyUtils::format_explicit_currency(
				WooPaymentsCurrencyUtils::amount_from_minor_units( $refunded_amount, $refunded_currency ),
				$refunded_currency,
				false
			);
			return esc_html(
				sprintf(
					/* translators: %s is a monetary amount */
					__( 'WooPayments refunded its %s application fee on this transaction.', 'woocommerce' ),
					$formatted
				)
			);
		}

		return null;
	}

	/**
	 * Compose the currency conversion line.
	 *
	 * @return string|null Null when the payment was not converted.
	 */
	private function compose_fx_string(): ?string {
		if ( ! $this->is_fx_event() ) {
			return null;
		}

		$details = $this->captured_event['transaction_details'];

		return $this->format_fx(
			(string) $details['customer_currency'],
			(int) $details['customer_amount_captured'],
			(string) $details['store_currency'],
			(int) $details['store_amount_captured']
		);
	}

	/**
	 * Compose the total fee line from `fee_rates`.
	 *
	 * @return string
	 */
	private function compose_fee_string(): string {
		$data = $this->captured_event;

		$fee_rates      = $data['fee_rates'];
		$percentage     = (float) $fee_rates['percentage'];
		$fixed_currency = (string) $fee_rates['fixed_currency'];
		$fixed          = WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $fee_rates['fixed'], $fixed_currency );
		$history        = $fee_rates['history'];

		if ( $this->has_tax() ) {
			$before_tax   = $data['fee_rates']['before_tax'];
			$fee_amount   = (float) $before_tax['amount'];
			$fee_currency = (string) $before_tax['currency'];
		} else {
			$fee_currency = (string) $data['transaction_details']['customer_currency'];
			$fee_amount   = (float) (int) $data['transaction_details']['customer_fee'];
		}

		$formatted_fee_amount = $this->convert_and_format_fee_amount( $fee_amount, $fee_currency );

		$base_fee_label = $this->is_base_fee_only()
			? __( 'Base fee', 'woocommerce' )
			: __( 'Fee', 'woocommerce' );

		$is_capped = isset( $history[0]['capped'] ) && true === $history[0]['capped'];

		if ( $this->is_base_fee_only() && $is_capped ) {
			return sprintf(
				'%1$s (capped at %2$s): %3$s',
				$base_fee_label,
				WooPaymentsCurrencyUtils::format_currency( $fixed, $fixed_currency ),
				$formatted_fee_amount
			);
		}
		$is_same_symbol = $this->has_same_currency_symbol( (string) $data['transaction_details']['store_currency'], (string) $data['transaction_details']['customer_currency'] );

		return sprintf(
			'%1$s (%2$s%% + %3$s%4$s): %5$s%6$s',
			$base_fee_label,
			self::format_fee( $percentage ),
			WooPaymentsCurrencyUtils::format_currency( $fixed, $fixed_currency ),
			$is_same_symbol ? ' ' . $data['transaction_details']['customer_currency'] : '',
			$formatted_fee_amount,
			$is_same_symbol ? ' ' . $data['transaction_details']['store_currency'] : ''
		);
	}

	/**
	 * Compose the per-fee breakdown lines from the fee history.
	 *
	 * @return string[]|null Null when there is no breakdown to show.
	 */
	private function compose_fee_break_down(): ?array {
		$fee_history_strings = $this->get_fee_breakdown();

		if ( null === $fee_history_strings || 0 === count( $fee_history_strings ) ) {
			return null;
		}

		$res = array();
		foreach ( $fee_history_strings as $type => $fee ) {
			$res[] = self::HTML_BLACK_BULLET . ' ' . ( 'discount' === $type ? $fee['label'] : $fee );

			if ( 'discount' === $type ) {
				$res[] = str_repeat( self::HTML_SPACE . ' ', 2 ) . self::HTML_WHITE_BULLET . ' ' . $fee['variable'];
				$res[] = str_repeat( self::HTML_SPACE . ' ', 2 ) . self::HTML_WHITE_BULLET . ' ' . $fee['fixed'];
			}
		}

		return $res;
	}

	/**
	 * Compose the net payout line from `transaction_details`.
	 *
	 * @return string
	 */
	private function compose_net_string(): string {
		$data = $this->captured_event['transaction_details'];

		// A converted payment shows its net in the store currency.
		if ( $this->is_fx_event() ) {
			$amount          = $data['store_amount'];
			$captured_amount = $data['store_amount_captured'];
			$fee             = $data['store_fee'];
			$currency        = (string) $data['store_currency'];
		} else {
			$amount          = $data['customer_amount'];
			$captured_amount = $data['customer_amount_captured'];
			$fee             = $data['customer_fee'];
			$currency        = (string) $data['customer_currency'];
		}

		$gross_amount = $captured_amount ?? $amount;
		$net          = WooPaymentsCurrencyUtils::amount_from_minor_units( (int) ( $gross_amount - $fee ), $currency );

		return sprintf(
			/* translators: %s is a monetary amount */
			__( 'Net payout: %s', 'woocommerce' ),
			WooPaymentsCurrencyUtils::format_explicit_currency( $net, $currency )
		);
	}

	/**
	 * Get the fee breakdown keyed by fee type; a discount carries its label and its variable and fixed parts.
	 *
	 * @return array<string,mixed>|null Null when there is no history or only a base fee.
	 */
	private function get_fee_breakdown(): ?array {
		$data = $this->captured_event;

		if ( ! isset( $data['fee_rates']['history'] ) ) {
			return null;
		}

		if ( $this->is_base_fee_only() ) {
			return null;
		}

		$fee_history_strings = array();

		foreach ( $data['fee_rates']['history'] as $fee ) {
			$label_type = (string) $fee['type'];
			if ( $fee['additional_type'] ?? '' ) {
				$label_type .= '-' . $fee['additional_type'];
			}

			$percentage_rate = (float) $fee['percentage_rate'];
			$fixed_rate      = (int) $fee['fixed_rate'];
			$currency        = strtoupper( (string) $fee['currency'] );
			$is_capped       = isset( $fee['capped'] ) && true === $fee['capped'];

			$percentage_rate_formatted = self::format_fee( $percentage_rate );
			// The client converts the fixed rate as a two-decimal amount whatever its currency.
			$fix_rate_formatted = WooPaymentsCurrencyUtils::format_currency(
				WooPaymentsCurrencyUtils::amount_from_minor_units( $fixed_rate, 'usd' ),
				$currency
			);

			if ( $this->has_same_currency_symbol( (string) $data['transaction_details']['customer_currency'], (string) $data['transaction_details']['store_currency'] ) ) {
				$fix_rate_formatted = $fix_rate_formatted . ' ' . $data['transaction_details']['store_currency'];
			}

			// An unknown fee type has no format; the client then prints an empty label.
			$label = sprintf(
				$this->fee_label_mapping( $fixed_rate, $is_capped )[ $label_type ] ?? '',
				$percentage_rate_formatted,
				$fix_rate_formatted
			);

			if ( 'discount' === $label_type ) {
				$fee_history_strings[ $label_type ] = array(
					'label'    => $label,
					'variable' => sprintf(
						/* translators: %s is a percentage number */
						__( 'Variable fee: %s', 'woocommerce' ),
						$percentage_rate_formatted
					) . '%',
					'fixed'    => sprintf(
						/* translators: %s is a monetary amount */
						__( 'Fixed fee: %s', 'woocommerce' ),
						$fix_rate_formatted
					),
				);
			} else {
				$fee_history_strings[ $label_type ] = $label;
			}
		}

		return $fee_history_strings;
	}

	/**
	 * Compose the tax line from `fee_rates.tax`.
	 *
	 * @return string|null
	 */
	private function compose_tax_string(): ?string {
		if ( ! $this->has_tax() ) {
			return null;
		}

		$tax        = $this->captured_event['fee_rates']['tax'];
		$tax_amount = $tax['amount'];
		if ( 0 === $tax_amount ) {
			return null;
		}

		$tax_currency     = (string) $tax['currency'];
		$formatted_amount = $this->convert_and_format_fee_amount( (float) $tax_amount, $tax_currency );

		$tax_description      = ' ' . $this->get_localized_tax_description();
		$formatted_percentage = ' (' . self::format_fee( (float) $tax['percentage_rate'] ) . '%)';

		return sprintf(
			/* translators: 1: tax description 2: tax percentage 3: tax amount */
			__( 'Tax%1$s%2$s: %3$s', 'woocommerce' ),
			$tax_description,
			$formatted_percentage,
			$formatted_amount
		);
	}

	/**
	 * Tell whether the payment was converted between currencies.
	 *
	 * @return bool
	 */
	private function is_fx_event(): bool {
		$customer_currency = $this->captured_event['transaction_details']['customer_currency'] ?? null;
		$store_currency    = $this->captured_event['transaction_details']['store_currency'] ?? null;

		return ! (
			is_null( $customer_currency )
			|| is_null( $store_currency )
			|| $customer_currency === $store_currency
		);
	}

	/**
	 * Tell whether the base fee is the only fee applied.
	 *
	 * @return bool
	 */
	private function is_base_fee_only(): bool {
		if ( ! isset( $this->captured_event['fee_rates']['history'] ) ) {
			return false;
		}

		$history = $this->captured_event['fee_rates']['history'];

		return 1 === ( is_countable( $history ) ? count( $history ) : 0 ) && 'base' === $history[0]['type'];
	}

	/**
	 * Get the line format of each fee type.
	 *
	 * @param int  $fixed_rate Fixed rate in minor units.
	 * @param bool $is_capped  Whether the fee is capped.
	 * @return array<string,string>
	 */
	private function fee_label_mapping( int $fixed_rate, bool $is_capped ): array {
		$res = array();

		$res['base'] = $is_capped
			/* translators: %2$s is the capped fee */
			? __( 'Base fee: capped at %2$s', 'woocommerce' )
			: ( 0 !== $fixed_rate
				/* translators: %1$s% is the fee percentage and %2$s is the fixed rate */
				? __( 'Base fee: %1$s%% + %2$s', 'woocommerce' )
				/* translators: %1$s% is the fee percentage */
				: __( 'Base fee: %1$s%%', 'woocommerce' )
			);

		$res['additional-international'] = 0 !== $fixed_rate
			/* translators: %1$s% is the fee percentage and %2$s is the fixed rate */
			? __( 'International card fee: %1$s%% + %2$s', 'woocommerce' )
			/* translators: %1$s% is the fee percentage */
			: __( 'International card fee: %1$s%%', 'woocommerce' );

		$res['additional-fx'] = 0 !== $fixed_rate
			/* translators: %1$s% is the fee percentage and %2$s is the fixed rate */
			? __( 'Currency conversion fee: %1$s%% + %2$s', 'woocommerce' )
			/* translators: %1$s% is the fee percentage */
			: __( 'Currency conversion fee: %1$s%%', 'woocommerce' );

		$res['additional-wcpay-subscription'] = 0 !== $fixed_rate
			/* translators: %1$s% is the fee percentage and %2$s is the fixed rate */
			? __( 'Subscription transaction fee: %1$s%% + %2$s', 'woocommerce' )
			/* translators: %1$s% is the fee percentage */
			: __( 'Subscription transaction fee: %1$s%%', 'woocommerce' );

		$res['discount'] = __( 'Discount', 'woocommerce' );

		return $res;
	}

	/**
	 * Format a decimal fee rate as a percentage with at most three decimals.
	 *
	 * @param float $percentage Rate as a fraction.
	 * @return string
	 */
	private static function format_fee( float $percentage ): string {
		return (string) round( $percentage * 100, 3 );
	}

	/**
	 * Format the conversion line: "1.00 EUR → 1.125 USD: $9.00 USD".
	 *
	 * @param string $from_currency Currency the shopper paid in.
	 * @param int    $from_amount   Captured amount in that currency, in minor units.
	 * @param string $to_currency   Store currency.
	 * @param int    $to_amount     Captured amount in the store currency, in minor units.
	 * @return string
	 */
	private function format_fx( string $from_currency, int $from_amount, string $to_currency, int $to_amount ): string {
		$exchange_rate = (float) ( 0 !== $from_amount ? $to_amount / $from_amount : 0 );

		if ( WooPaymentsCurrencyUtils::is_zero_decimal_currency( $to_currency ) ) {
			$exchange_rate *= 100;
		}

		if ( WooPaymentsCurrencyUtils::is_zero_decimal_currency( $from_currency ) ) {
			$exchange_rate /= 100;
		}

		$to_display_amount = WooPaymentsCurrencyUtils::amount_from_minor_units( $to_amount, $to_currency );

		return sprintf(
			'%1$s → %2$s: %3$s',
			$this->format_explicit_currency_with_base( 1, $from_currency, $to_currency, true ),
			$this->format_exchange_rate( $exchange_rate, $to_currency ),
			WooPaymentsCurrencyUtils::format_explicit_currency( $to_display_amount, $to_currency, false )
		);
	}

	/**
	 * Format an exchange rate with five or six decimals and no trailing zeros.
	 *
	 * @param float  $rate     Exchange rate.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_exchange_rate( float $rate, string $currency ): string {
		$formatted = WooPaymentsCurrencyUtils::format_explicit_currency(
			$rate,
			$currency,
			true,
			array( 'decimals' => $rate > 1 ? 5 : 6 )
		);

		return implode(
			' ',
			array_map(
				static function ( string $part ): string {
					return rtrim( $part, '0' );
				},
				explode( ' ', $formatted )
			)
		);
	}

	/**
	 * Format an amount in one currency with another currency's separators and position.
	 *
	 * @param float  $amount        Amount.
	 * @param string $currency      Currency code.
	 * @param string $base_currency Currency whose format is used.
	 * @param bool   $skip_symbol   Whether to drop the currency symbol.
	 * @return string
	 */
	private function format_explicit_currency_with_base( float $amount, string $currency, string $base_currency, bool $skip_symbol = false ): string {
		$custom_format = WooPaymentsCurrencyUtils::get_currency_format_for_wc_price( $base_currency );
		unset( $custom_format['currency'] );

		// The amount is in $currency, so its decimals follow $currency.
		$custom_format['decimals'] = WooPaymentsCurrencyUtils::get_currency_format_for_wc_price( $currency )['decimals'];

		return WooPaymentsCurrencyUtils::format_explicit_currency( $amount, $currency, $skip_symbol, $custom_format );
	}

	/**
	 * Tell whether two different currencies share a symbol.
	 *
	 * @param string $base_currency Currency code.
	 * @param string $currency      Currency code to compare.
	 * @return bool
	 */
	private function has_same_currency_symbol( string $base_currency, string $currency ): bool {
		return 0 !== strcasecmp( $base_currency, $currency ) && get_woocommerce_currency_symbol( $base_currency ) === get_woocommerce_currency_symbol( $currency );
	}

	/**
	 * Tell whether the event carries tax on the fee.
	 *
	 * @return bool
	 */
	private function has_tax(): bool {
		return isset( $this->captured_event['fee_rates']['tax'] );
	}

	/**
	 * Get the localized tax description of the event's tax.
	 *
	 * @return string|null
	 */
	private function get_localized_tax_description(): ?string {
		if ( ! isset( $this->captured_event['fee_rates']['tax']['description'] ) ) {
			return null;
		}
		return self::localize_tax_description_code( (string) $this->captured_event['fee_rates']['tax']['description'] );
	}

	/**
	 * Localize a tax description code such as "IT VAT"; an unknown code reads "Tax".
	 *
	 * @param string $tax_description_id Tax description code.
	 * @return string
	 */
	private static function localize_tax_description_code( string $tax_description_id ): string {
		$tax_descriptions = array(
			// European Union VAT.
			'AT VAT' => __( 'AT VAT', 'woocommerce' ),
			'BE VAT' => __( 'BE VAT', 'woocommerce' ),
			'BG VAT' => __( 'BG VAT', 'woocommerce' ),
			'CY VAT' => __( 'CY VAT', 'woocommerce' ),
			'CZ VAT' => __( 'CZ VAT', 'woocommerce' ),
			'DE VAT' => __( 'DE VAT', 'woocommerce' ),
			'DK VAT' => __( 'DK VAT', 'woocommerce' ),
			'EE VAT' => __( 'EE VAT', 'woocommerce' ),
			'ES VAT' => __( 'ES VAT', 'woocommerce' ),
			'FI VAT' => __( 'FI VAT', 'woocommerce' ),
			'FR VAT' => __( 'FR VAT', 'woocommerce' ),
			'GB VAT' => __( 'UK VAT', 'woocommerce' ),
			'GR VAT' => __( 'GR VAT', 'woocommerce' ),
			'HR VAT' => __( 'HR VAT', 'woocommerce' ),
			'HU VAT' => __( 'HU VAT', 'woocommerce' ),
			'IE VAT' => __( 'IE VAT', 'woocommerce' ),
			'IT VAT' => __( 'IT VAT', 'woocommerce' ),
			'LT VAT' => __( 'LT VAT', 'woocommerce' ),
			'LU VAT' => __( 'LU VAT', 'woocommerce' ),
			'LV VAT' => __( 'LV VAT', 'woocommerce' ),
			'MT VAT' => __( 'MT VAT', 'woocommerce' ),
			'NO VAT' => __( 'NO VAT', 'woocommerce' ),
			'NL VAT' => __( 'NL VAT', 'woocommerce' ),
			'PL VAT' => __( 'PL VAT', 'woocommerce' ),
			'PT VAT' => __( 'PT VAT', 'woocommerce' ),
			'RO VAT' => __( 'RO VAT', 'woocommerce' ),
			'SE VAT' => __( 'SE VAT', 'woocommerce' ),
			'SI VAT' => __( 'SI VAT', 'woocommerce' ),
			'SK VAT' => __( 'SK VAT', 'woocommerce' ),
			// GST countries.
			'AU GST' => __( 'AU GST', 'woocommerce' ),
			'NZ GST' => __( 'NZ GST', 'woocommerce' ),
			'SG GST' => __( 'SG GST', 'woocommerce' ),
			// Other tax systems.
			'CH VAT' => __( 'CH VAT', 'woocommerce' ),
			'JP JCT' => __( 'JP JCT', 'woocommerce' ),
		);

		return $tax_descriptions[ $tax_description_id ] ?? __( 'Tax', 'woocommerce' );
	}

	/**
	 * Format a fee amount as a negative amount, converted to the store currency when the fee is in the shopper's currency.
	 *
	 * @param float  $fee_amount   Fee amount in minor units.
	 * @param string $fee_currency Fee currency.
	 * @return string
	 */
	private function convert_and_format_fee_amount( float $fee_amount, string $fee_currency ): string {
		$fee_exchange_rate = $this->captured_event['fee_rates']['fee_exchange_rate'] ?? null;
		$store_currency    = (string) ( $this->captured_event['transaction_details']['store_currency'] ?? '' );
		if ( ( strtoupper( $fee_currency ) === strtoupper( $store_currency ) ) || ! $this->is_fx_event() || ! $fee_exchange_rate ) {
			// The client passes this float to an int parameter, which drops any fraction.
			return WooPaymentsCurrencyUtils::format_currency(
				-abs( WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $fee_amount, $fee_currency ) ),
				$fee_currency
			);
		}

		$rate          = (float) $fee_exchange_rate['rate'];
		$from_currency = (string) ( $fee_exchange_rate['from_currency'] ?? '' );

		$converted_amount = strtoupper( $fee_currency ) === strtoupper( $from_currency )
			? $fee_amount / $rate
			: $fee_amount * $rate;

		return WooPaymentsCurrencyUtils::format_currency(
			-abs( WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $converted_amount, $store_currency ) ),
			$store_currency
		);
	}
}
