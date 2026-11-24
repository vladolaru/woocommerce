<?php
/**
 * MultiCurrencyUtil class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\ExtensionCurrencyBridge;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\IncorrectUsageNotice;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;

/**
 * Read currencies and convert amounts using the active Multi-Currency runtime.
 * With neither runtime active, amounts stay unchanged, including on stores using a third-party switcher.
 * Use get_woocommerce_currency() for the current display currency.
 *
 * @since 11.4.0
 */
final class MultiCurrencyUtil {

	use IncorrectUsageNotice;

	/**
	 * Whether WooCommerce or its payments extension runs Multi-Currency on this site.
	 *
	 * @since 11.4.0
	 * @return bool
	 */
	public static function is_active(): bool {
		return MultiCurrencyRuntimeArbiter::OWNER_NONE !== self::owner();
	}

	/**
	 * Get the store's base currency code.
	 *
	 * @since 11.4.0
	 * @return string
	 */
	public static function get_store_currency(): string {
		return strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
	}

	/**
	 * Get enabled currency codes and their exchange rates for one unit of the store currency.
	 *
	 * @since 11.4.0
	 * @return array<string,float> Rates keyed by currency code, including the store currency.
	 */
	public static function get_enabled_currencies(): array {
		switch ( self::owner() ) {
			case MultiCurrencyRuntimeArbiter::OWNER_BUILTIN:
				return array_map(
					static fn( MultiCurrencyCurrency $currency ): float => $currency->get_rate(),
					self::state()->get_enabled_currencies()
				);
			case MultiCurrencyRuntimeArbiter::OWNER_EXTENSION:
				try {
					return ExtensionCurrencyBridge::get_rates() + array( self::get_store_currency() => 1.0 );
				} catch ( \BadMethodCallException $exception ) {
					self::warn_once( __METHOD__, $exception->getMessage() );
				}
		}
		return array( self::get_store_currency() => 1.0 );
	}

	/**
	 * Convert an amount by exchange rate alone. An unavailable currency leaves the amount unchanged.
	 *
	 * @since 11.4.0
	 * @param float  $amount Amount to convert.
	 * @param string $to     Target code, or empty for the selected currency.
	 * @param string $from   Source code, or empty for the store currency.
	 * @return float
	 */
	public static function convert( float $amount, string $to = '', string $from = '' ): float {
		try {
			switch ( self::owner() ) {
				case MultiCurrencyRuntimeArbiter::OWNER_EXTENSION:
					return ExtensionCurrencyBridge::convert( $amount, $to, $from );
				case MultiCurrencyRuntimeArbiter::OWNER_BUILTIN:
					$state  = self::state();
					$source = '' === $from ? $state->get_default_currency() : self::enabled_currency( $state, $from );
					$target = '' === $to ? $state->get_selected_currency() : self::enabled_currency( $state, $to );
					return wc_get_container()->get( ServiceFactory::class )->create_price_converter()->get_raw_conversion(
						$amount,
						$target->get_code(),
						$source->get_code()
					);
			}
		} catch ( InvalidCurrencyException | \BadMethodCallException $exception ) {
			self::warn_once( __METHOD__, $exception->getMessage() );
		}
		return $amount;
	}

	/**
	 * Convert a store price using the target currency's rounding and charm settings for its price type.
	 *
	 * @since 11.4.0
	 * @param float  $amount Amount in store currency.
	 * @param string $type   One of 'product', 'shipping', 'tax', 'coupon', or 'exchange_rate'.
	 * @param string $to     Target code, or empty for the selected currency. An unavailable target leaves the price unchanged.
	 * @return float
	 */
	public static function convert_price( float $amount, string $type = 'product', string $to = '' ): float {
		try {
			switch ( self::owner() ) {
				case MultiCurrencyRuntimeArbiter::OWNER_EXTENSION:
					return ExtensionCurrencyBridge::convert_price( $amount, $type, $to );
				case MultiCurrencyRuntimeArbiter::OWNER_BUILTIN:
					return wc_get_container()->get( ServiceFactory::class )->create_price_converter()->get_price(
						$amount,
						$type,
						'' === $to ? null : self::enabled_currency( self::state(), $to )
					);
			}
		} catch ( InvalidCurrencyException | \BadMethodCallException $exception ) {
			self::warn_once( __METHOD__, $exception->getMessage() );
		}
		return $amount;
	}

	/**
	 * Get an explicitly requested target or source currency.
	 *
	 * @param MultiCurrencyState $state Current currency state.
	 * @param string             $code  Currency code.
	 * @return MultiCurrencyCurrency
	 * @throws InvalidCurrencyException When the code is not enabled.
	 */
	private static function enabled_currency( MultiCurrencyState $state, string $code ): MultiCurrencyCurrency {
		$code       = strtoupper( $code );
		$currencies = $state->get_enabled_currencies();
		if ( ! isset( $currencies[ $code ] ) ) {
			throw new InvalidCurrencyException( esc_html( 'Currency is not enabled for conversion: ' . $code ) );
		}
		return $currencies[ $code ];
	}

	/**
	 * Get the built-in runtime's current state.
	 *
	 * @return MultiCurrencyState
	 */
	private static function state(): MultiCurrencyState {
		return wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->build();
	}

	/**
	 * Get the owner for this site.
	 *
	 * @return string
	 */
	private static function owner(): string {
		return wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->get_runtime_owner();
	}
}
