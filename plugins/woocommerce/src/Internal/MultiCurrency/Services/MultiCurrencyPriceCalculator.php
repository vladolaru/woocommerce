<?php
/**
 * MultiCurrencyPriceCalculator class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;

/**
 * Pure price conversion calculator for the native multi-currency runtime.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyPriceCalculator {

	/**
	 * Relative distance from a whole rounding step within which the step is decided on exact decimals. Float error in
	 * price * rate / step is a few units in the last place (about 1e-15), so beyond this the float ceil is already right.
	 */
	private const NEAR_STEP = 1e-9;

	/**
	 * Localization service.
	 *
	 * @var MultiCurrencyLocalizationInterface
	 */
	private MultiCurrencyLocalizationInterface $localization_service;

	/**
	 * Constructor.
	 *
	 * @param MultiCurrencyLocalizationInterface $localization_service Localization service.
	 */
	public function __construct( MultiCurrencyLocalizationInterface $localization_service ) {
		$this->localization_service = $localization_service;
	}

	/**
	 * Get the converted price.
	 *
	 * @param mixed                 $price                         Price to convert.
	 * @param string                $type                          Price type.
	 * @param MultiCurrencyCurrency $currency                      Target currency.
	 * @param bool                  $apply_charm_only_to_products Whether charm applies only to product prices.
	 * @return float
	 */
	public function get_price( $price, string $type, MultiCurrencyCurrency $currency, bool $apply_charm_only_to_products = true ): float {
		$supported_types = array( 'product', 'shipping', 'tax', 'coupon', 'exchange_rate' );

		if ( ! in_array( $type, $supported_types, true ) || $currency->get_is_default() ) {
			return (float) $price;
		}

		$converted_price = (float) $price * $currency->get_rate();

		if ( in_array( $type, array( 'tax', 'coupon', 'exchange_rate' ), true ) ) {
			return round( $converted_price, $this->get_currency_decimals( $currency ) );
		}

		$apply_charm_pricing = $apply_charm_only_to_products
			? 'product' === $type
			: in_array( $type, array( 'product', 'shipping' ), true );

		return $this->get_adjusted_price( $converted_price, $apply_charm_pricing, $currency, $price, $currency->get_rate() );
	}

	/**
	 * Round an amount already in the given currency and add its charm, as the client's adjusted price.
	 *
	 * @param mixed                 $amount   Amount in the currency.
	 * @param MultiCurrencyCurrency $currency Currency whose rounding and charm apply.
	 * @return float
	 */
	public function get_adjusted_amount( $amount, MultiCurrencyCurrency $currency ): float {
		return $this->get_adjusted_price( (float) $amount, true, $currency, $amount, 1.0 );
	}

	/**
	 * Convert an amount between enabled currencies.
	 *
	 * @param float                               $amount             Amount to convert.
	 * @param string                              $to_currency        Target currency code.
	 * @param string                              $from_currency      Source currency code.
	 * @param array<string,MultiCurrencyCurrency> $enabled_currencies Enabled currencies keyed by code.
	 * @return float
	 *
	 * @throws InvalidCurrencyException When either currency is not enabled.
	 * @throws InvalidCurrencyRateException When the source currency rate is invalid.
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency, array $enabled_currencies ): float {
		$to_currency   = strtoupper( $to_currency );
		$from_currency = strtoupper( $from_currency );

		foreach ( array( $to_currency, $from_currency ) as $code ) {
			if ( ! isset( $enabled_currencies[ $code ] ) ) {
				throw new InvalidCurrencyException( esc_html( 'Currency is not enabled for conversion: ' . $code ) );
			}
		}

		$to_currency_rate   = $enabled_currencies[ $to_currency ]->get_rate();
		$from_currency_rate = $enabled_currencies[ $from_currency ]->get_rate();

		if ( 0 >= $from_currency_rate ) {
			throw new InvalidCurrencyRateException( esc_html( 'Invalid source currency rate: ' . $from_currency_rate ) );
		}

		return $amount * ( $to_currency_rate / $from_currency_rate );
	}

	/**
	 * Apply rounding and charm pricing.
	 *
	 * @param float                 $price               Converted price.
	 * @param bool                  $apply_charm_pricing Whether charm applies.
	 * @param MultiCurrencyCurrency $currency            Target currency.
	 * @param mixed                 $source_price        Price before conversion.
	 * @param float                 $rate                Rate the price was converted at.
	 * @return float
	 */
	private function get_adjusted_price( float $price, bool $apply_charm_pricing, MultiCurrencyCurrency $currency, $source_price, float $rate ): float {
		$rounding = (string) $currency->get_rounding();

		if ( 0.0 === (float) $rounding ) {
			$price = round( $price, $this->get_currency_decimals( $currency ) );
		} else {
			$price = $this->ceil_price( $price, $rounding, $source_price, $rate );
		}

		if ( $apply_charm_pricing ) {
			$price += $currency->get_charm();
		}

		return max( 0, $price );
	}

	/**
	 * Ceil a converted price to the next rounding step, as the async renderer does on exact decimals.
	 *
	 * Away from a whole step the float quotient decides. Near one, where float error could tip the ceil either way (3.00 at a 0.1 rate
	 * is 0.30000000000000004 in floats, and the client's float ceil charges 0.40 where 0.30 was shown; includes/multi-currency/
	 * MultiCurrency.php:1695-1700), the next step is taken only when the source price times the rate exactly exceeds the nearest step.
	 *
	 * @param float  $converted    Converted price.
	 * @param string $rounding     Rounding step, as stored.
	 * @param mixed  $source_price Price before conversion.
	 * @param float  $rate         Rate the price was converted at.
	 * @return float
	 */
	private function ceil_price( float $converted, string $rounding, $source_price, float $rate ): float {
		$step     = (float) $rounding;
		$quotient = $converted / $step;
		$nearest  = round( $quotient );
		if ( abs( $quotient - $nearest ) > max( 1.0, abs( $quotient ) ) * self::NEAR_STEP ) {
			return ceil( $quotient ) * $step;
		}

		$product = self::multiply_decimals( self::to_decimal( $source_price ), self::to_decimal( $rate ) );
		$bound   = self::multiply_decimals( self::to_decimal( $nearest ), self::to_decimal( $rounding ) );
		if ( null === $product || null === $bound ) {
			return ceil( $quotient ) * $step;
		}

		return ( 0 < self::compare_decimals( $product, $bound ) ? $nearest + 1 : $nearest ) * $step;
	}

	/**
	 * Read a non-negative number as decimal digits and a power of ten, using the shortest form of a float, as the renderer receives it.
	 *
	 * @param mixed $value Number or numeric string.
	 * @return array{0:string,1:int}|null Digits without leading zeros and their exponent, or null when not a non-negative number.
	 */
	private static function to_decimal( $value ): ?array {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		} elseif ( is_float( $value ) ) {
			$value = wp_json_encode( $value );
		}
		if ( ! is_string( $value ) || ! preg_match( '/^(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', trim( $value ), $parts ) || '' === $parts[1] . ( $parts[2] ?? '' ) ) {
			return null;
		}

		$fraction = $parts[2] ?? '';
		$digits   = ltrim( $parts[1] . $fraction, '0' );

		return array( '' === $digits ? '0' : $digits, (int) ( $parts[3] ?? 0 ) - strlen( $fraction ) );
	}

	/**
	 * Multiply two decimals exactly.
	 *
	 * @param array{0:string,1:int}|null $a First decimal.
	 * @param array{0:string,1:int}|null $b Second decimal.
	 * @return array{0:string,1:int}|null The product, or null when either is null.
	 */
	private static function multiply_decimals( ?array $a, ?array $b ): ?array {
		if ( null === $a || null === $b ) {
			return null;
		}

		$x      = array_map( 'intval', str_split( strrev( $a[0] ) ) );
		$y      = array_map( 'intval', str_split( strrev( $b[0] ) ) );
		$result = array_fill( 0, count( $x ) + count( $y ), 0 );
		foreach ( $x as $i => $xi ) {
			foreach ( $y as $j => $yj ) {
				$result[ $i + $j ] += $xi * $yj;
			}
		}
		$count = count( $result );
		for ( $k = 0; $k < $count - 1; $k++ ) {
			$result[ $k + 1 ] += intdiv( $result[ $k ], 10 );
			$result[ $k ]     %= 10;
		}
		$digits = ltrim( strrev( implode( '', $result ) ), '0' );

		return array( '' === $digits ? '0' : $digits, $a[1] + $b[1] );
	}

	/**
	 * Compare two decimals.
	 *
	 * @param array{0:string,1:int} $a First decimal.
	 * @param array{0:string,1:int} $b Second decimal.
	 * @return int Negative, zero or positive as $a is less than, equal to or greater than $b.
	 */
	private static function compare_decimals( array $a, array $b ): int {
		$exponent = min( $a[1], $b[1] );
		$x        = ltrim( $a[0] . str_repeat( '0', $a[1] - $exponent ), '0' );
		$y        = ltrim( $b[0] . str_repeat( '0', $b[1] - $exponent ), '0' );

		if ( strlen( $x ) !== strlen( $y ) ) {
			return strlen( $x ) <=> strlen( $y );
		}

		return strcmp( $x, $y ) <=> 0;
	}

	/**
	 * Get decimals for a currency.
	 *
	 * @param MultiCurrencyCurrency $currency Currency.
	 * @return int
	 */
	private function get_currency_decimals( MultiCurrencyCurrency $currency ): int {
		return absint( $this->localization_service->get_currency_format( $currency->get_code() )['num_decimals'] );
	}
}
