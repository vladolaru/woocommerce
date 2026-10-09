<?php
/**
 * WooPaymentsCurrencyUtils class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use WC_Order;

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
	 * Get the Stripe zero-decimal currency codes, lowercase, as client 11.1.0 `WC_Payments_Utils::zero_decimal_currencies()` lists them.
	 *
	 * @since 11.2.0
	 *
	 * @return string[]
	 */
	public static function get_zero_decimal_currencies(): array {
		return self::ZERO_DECIMAL_CURRENCIES;
	}

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
	 * Convert a decimal amount to provider minor units, keeping fractions of a minor unit, for Stripe's decimal amount fields.
	 *
	 * The amount is rounded to WooCommerce's rounding precision, then multiplied by 100 unless the currency has no decimals,
	 * as client 11.1.0 `WC_Payments_Subscription_Service::format_item_price_data()` does for `unit_amount_decimal`.
	 *
	 * @since 11.2.0
	 *
	 * @param float  $amount   Decimal amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function amount_to_minor_units_decimal( float $amount, string $currency ): float {
		$amount = round( $amount, wc_get_rounding_precision() );

		return self::is_zero_decimal_currency( $currency ) ? $amount : $amount * 100;
	}

	/**
	 * Format an amount with `wc_price()` in its currency's own format, whatever currency the acting user selected.
	 *
	 * Order notes written in webhook requests run as the platform's connection user, whose selection would otherwise apply.
	 *
	 * @since 11.2.0
	 *
	 * @param float               $amount   Decimal amount.
	 * @param string              $currency Currency code.
	 * @param array<string,mixed> $args     Other `wc_price()` arguments.
	 * @return string
	 */
	public static function format_price_in_currency( float $amount, string $currency, array $args = array() ): string {
		$args['currency'] = $currency;
		$format           = static fn(): string => wc_price( $amount, $args );

		$container = wc_get_container();
		if ( ! $container->get( MultiCurrencyRuntimeArbiter::class )->should_core_register() ) {
			return $format();
		}

		return $container->get( MultiCurrencyFrontendCurrenciesController::class )->format_in_order_currency( $currency, $format );
	}

	/**
	 * Format an amount for an order note: in its own currency, with the order's currency code added where prices show it.
	 *
	 * The code follows WooCommerce's explicit admin price setting; while the WooPayments extension is loaded, its
	 * explicit price formatter decides instead. An empty currency means the order's.
	 *
	 * @since 11.2.0
	 *
	 * @param float    $amount   Decimal amount.
	 * @param string   $currency Currency code.
	 * @param WC_Order $order    Order the note belongs to.
	 * @return string
	 */
	public static function format_explicit_order_price( float $amount, string $currency, WC_Order $order ): string {
		$currency        = strtoupper( '' !== $currency ? $currency : $order->get_currency() );
		$formatted_price = self::format_price_in_currency( $amount, $currency );
		$formatter       = array( 'WC_Payments_Explicit_Price_Formatter', 'get_explicit_price' );

		if ( class_exists( 'WC_Payments_Explicit_Price_Formatter' ) && is_callable( $formatter ) ) {
			return (string) call_user_func( $formatter, $formatted_price, $order );
		}

		return MultiCurrencyExplicitPriceProjectionService::get_explicit_price_with_currency(
			$formatted_price,
			strtoupper( $order->get_currency() ),
			MultiCurrencyExplicitPriceProjectionService::should_output_explicit_admin_price()
		);
	}

	/**
	 * Interpret a Stripe exchange rate between a presentment and a base currency when one of them has no decimals.
	 *
	 * Stripe expresses the rate in minor units, so a zero-decimal presentment currency against a decimal base divides it
	 * by 100 and the reverse multiplies it by 100, as client 11.1.0 `WC_Payments_Utils::interpret_string_exchange_rate()` does.
	 *
	 * @since 11.2.0
	 *
	 * @param float  $exchange_rate        Provider exchange rate.
	 * @param string $presentment_currency Currency the shopper paid in.
	 * @param string $base_currency        WooPayments account default currency.
	 * @return float
	 */
	public static function interpret_string_exchange_rate( float $exchange_rate, string $presentment_currency, string $base_currency ): float {
		$is_presentment_currency_zero_decimal = self::is_zero_decimal_currency( $presentment_currency );
		$is_base_currency_zero_decimal        = self::is_zero_decimal_currency( $base_currency );

		if ( $is_presentment_currency_zero_decimal && ! $is_base_currency_zero_decimal ) {
			return $exchange_rate / 100;
		}

		if ( ! $is_presentment_currency_zero_decimal && $is_base_currency_zero_decimal ) {
			return $exchange_rate * 100;
		}

		return $exchange_rate;
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
