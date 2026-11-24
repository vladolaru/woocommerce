<?php
/**
 * ExtensionCurrencyBridge class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;

/**
 * Reads rates and converts amounts while the extension owns Multi-Currency.
 *
 * @internal
 * @since 11.4.0
 */
final class ExtensionCurrencyBridge {

	/**
	 * Get the enabled currencies' rates.
	 *
	 * @return array<string,float>
	 */
	public static function get_rates(): array {
		$currencies = self::call( self::extension(), 'get_enabled_currencies' );
		$rates      = array();
		foreach ( $currencies as $code => $currency ) {
			$rates[ $code ] = (float) self::call( $currency, 'get_rate' );
		}
		return $rates;
	}

	/**
	 * Convert at the extension's raw rate.
	 *
	 * @param float  $amount Amount to convert.
	 * @param string $to     Target code, or empty for the selected currency.
	 * @param string $from   Source code, or empty for the store currency.
	 * @return float
	 */
	public static function convert( float $amount, string $to, string $from ): float {
		$extension = self::extension();
		if ( '' === $to ) {
			$to = self::call( self::call( $extension, 'get_selected_currency' ), 'get_code' );
		}
		return self::call_converter( $extension, 'get_raw_conversion', $amount, $to, $from );
	}

	/**
	 * Convert a price with the extension's price rules.
	 *
	 * @param float  $amount Amount in store currency.
	 * @param string $type   Price type.
	 * @param string $to     Target code, or empty for the selected currency.
	 * @return float
	 * @throws InvalidCurrencyException When the target is unavailable.
	 */
	public static function convert_price( float $amount, string $type, string $to ): float {
		$extension = self::extension();
		$currency  = null;
		if ( '' !== $to ) {
			$code     = strtoupper( $to );
			$currency = self::call( $extension, 'get_enabled_currencies' )[ $code ] ?? null;
			if ( null === $currency ) {
				$currency = self::call( $extension, 'get_selected_currency' );
				if ( self::call( $currency, 'get_code' ) !== $code ) {
					throw new InvalidCurrencyException( esc_html( 'Currency is not enabled for conversion: ' . $code ) );
				}
			}
		}
		return self::call_converter( $extension, 'get_price', $amount, $type, $currency );
	}

	/**
	 * Translate a refusal from the installed extension into the public utility's conversion fallback.
	 *
	 * @param object|null $extension Extension instance.
	 * @param string      $method    Conversion method.
	 * @param mixed       ...$args   Conversion arguments.
	 * @return float
	 * @throws InvalidCurrencyException When the extension cannot convert the amount.
	 */
	private static function call_converter( $extension, string $method, ...$args ): float {
		try {
			return self::call( $extension, $method, ...$args );
		} catch ( \Throwable $error ) {
			throw new InvalidCurrencyException( esc_html( $error->getMessage() ) );
		}
	}

	/**
	 * Get the extension's instance when its accessor has loaded.
	 *
	 * @return object|null
	 */
	private static function extension() {
		return function_exists( 'WC_Payments_Multi_Currency' ) ? WC_Payments_Multi_Currency() : null;
	}

	/**
	 * Call a public extension method when the installed version provides it.
	 *
	 * @param object|null $instance Extension instance or currency.
	 * @param string      $method   Public method name.
	 * @param mixed       ...$args  Method arguments.
	 * @return mixed
	 * @throws \BadMethodCallException When the extension cannot answer.
	 */
	private static function call( $instance, string $method, ...$args ) {
		if ( ! is_object( $instance ) || ! method_exists( $instance, $method ) || ! is_callable( array( $instance, $method ) ) ) {
			throw new \BadMethodCallException( esc_html( 'The Multi-Currency extension cannot provide ' . $method . '().' ) );
		}
		return $instance->$method( ...$args );
	}
}
