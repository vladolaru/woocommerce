<?php
/**
 * WooPaymentsCurrencyUtils class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;

/**
 * WooPayments currency helpers for provider-boundary amount handling.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsCurrencyUtils {

	/**
	 * Stripe zero-decimal currencies.
	 *
	 * @var string[]
	 */
	private const ZERO_DECIMAL_CURRENCIES = array(
		'bif',
		'clp',
		'djf',
		'gnf',
		'jpy',
		'kmf',
		'krw',
		'mga',
		'pyg',
		'rwf',
		'vnd',
		'vuv',
		'xaf',
		'xof',
		'xpf',
	);

	/**
	 * Tell whether the currency uses zero decimal places at the provider boundary.
	 *
	 * @param string $currency Currency code.
	 * @return bool
	 */
	public static function is_zero_decimal_currency( string $currency ): bool {
		return in_array( strtolower( $currency ), self::ZERO_DECIMAL_CURRENCIES, true );
	}

	/**
	 * Get the Stripe minor-unit decimal count for a currency.
	 *
	 * Returns 0 for true zero-decimal currencies and 2 for everything else,
	 * including Stripe special-case currencies that WooCommerce can display
	 * without decimals while Stripe still expects two-decimal minor units.
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	public static function get_stripe_minor_unit_for_currency( string $currency ): int {
		return self::is_zero_decimal_currency( $currency ) ? 0 : 2;
	}

	/**
	 * Convert a provider minor-unit amount to a decimal amount.
	 *
	 * @since 11.0.0
	 *
	 * @param int    $amount   Minor-unit amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function amount_from_minor_units( int $amount, string $currency ): float {
		return self::is_zero_decimal_currency( $currency ) ? (float) $amount : (float) $amount / 100;
	}

	/**
	 * Convert a decimal amount to provider minor units.
	 *
	 * @since 11.0.0
	 *
	 * @param float  $amount   Decimal amount.
	 * @param string $currency Currency code.
	 * @return int
	 */
	public static function amount_to_minor_units( float $amount, string $currency ): int {
		$minor_unit = self::get_stripe_minor_unit_for_currency( $currency );

		return (int) round( $amount * ( 10 ** $minor_unit ) );
	}

	/**
	 * Format an amount with the currency's localized format, as client 11.1.0 `WC_Payments_Utils::format_currency()` does.
	 *
	 * @since 11.2.0
	 *
	 * @param float  $amount   Decimal amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function format_currency( float $amount, string $currency ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, self::get_currency_format_for_wc_price( $currency ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	}

	/**
	 * Format an amount with the currency code on the right, as client 11.1.0 `WC_Payments_Utils::format_explicit_currency()` does.
	 *
	 * @since 11.2.0
	 *
	 * @param float               $amount          Decimal amount.
	 * @param string              $currency        Currency code.
	 * @param bool                $skip_symbol     Whether to drop the currency symbol.
	 * @param array<string,mixed> $currency_format Format arguments for `wc_price()` that override the currency's own.
	 * @return string
	 */
	public static function format_explicit_currency( float $amount, string $currency, bool $skip_symbol = false, array $currency_format = array() ): string {
		$currency         = strtoupper( $currency );
		$formatted_amount = html_entity_decode( wp_strip_all_tags( wc_price( $amount, wp_parse_args( $currency_format, self::get_currency_format_for_wc_price( $currency ) ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

		if ( $skip_symbol ) {
			$formatted_amount = (string) preg_replace( '/[^0-9,\.]+/', '', $formatted_amount );
		}

		if ( false === strpos( $formatted_amount, $currency ) ) {
			return $formatted_amount . ' ' . $currency;
		}

		return $formatted_amount;
	}

	/**
	 * Get the `wc_price()` arguments for a currency's localized format, as client 11.1.0 `WC_Payments_Utils::get_currency_format_for_wc_price()` does.
	 *
	 * @since 11.2.0
	 *
	 * @param string $currency Currency code.
	 * @return array<string,mixed>
	 */
	public static function get_currency_format_for_wc_price( string $currency ): array {
		$currency      = strtoupper( $currency );
		$price_formats = array(
			'right'       => '%2$s%1$s',
			'left_space'  => '%1$s %2$s',
			'right_space' => '%2$s %1$s',
		);

		$args = array();
		foreach ( wc_get_container()->get( MultiCurrencyLocalizationService::class )->get_currency_format( $currency ) as $key => $format ) {
			switch ( $key ) {
				case 'thousand_sep':
					$args['thousand_separator'] = $format;
					break;
				case 'decimal_sep':
					$args['decimal_separator'] = $format;
					break;
				case 'num_decimals':
					$args['decimals'] = $format;
					break;
				case 'currency_pos':
					$args['price_format'] = $price_formats[ $format ] ?? '%1$s%2$s';
					break;
			}
		}
		$args['currency'] = $currency;

		return $args;
	}

	/**
	 * Cache the platform-reported per-currency minimum charge amount.
	 *
	 * Uses the same transient the WooPayments plugin writes, so the learned
	 * floor survives switching between the plugin and the native runtime.
	 *
	 * @since 11.0.0
	 *
	 * @param string $currency Currency code.
	 * @param int    $amount   Minimum amount in provider minor units.
	 */
	public static function cache_minimum_amount( string $currency, int $amount ): void {
		set_transient( 'wcpay_minimum_amount_' . strtolower( $currency ), $amount, DAY_IN_SECONDS );
	}

	/**
	 * Get the cached platform-reported minimum charge amount for a currency.
	 *
	 * @since 11.0.0
	 *
	 * @param string $currency Currency code.
	 * @return int|null Minimum amount in provider minor units, or null when unknown.
	 */
	public static function get_cached_minimum_amount( string $currency ): ?int {
		$cached = (int) get_transient( 'wcpay_minimum_amount_' . strtolower( $currency ) );

		return 0 < $cached ? $cached : null;
	}
}
