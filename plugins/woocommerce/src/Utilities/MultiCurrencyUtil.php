<?php
/**
 * MultiCurrencyUtil class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\ExtensionCurrencyBridge;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\IncorrectUsageNotice;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;

/**
 * Read currencies and convert amounts using the active Multi-Currency runtime.
 * With neither runtime active, amounts stay unchanged, including on stores using a third-party switcher.
 * When a currency has no usable rate, conversion returns the amount unchanged and emits a developer notice once per method per request (see get_enabled_currencies() for enabled rates).
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
	 * Convert an amount by exchange rate alone.
	 *
	 * @since 11.4.0
	 * @param mixed  $amount Amount to convert using PHP's float cast. Null and strings without a numeric prefix become zero.
	 * @param string $to     Target code, or empty for the selected currency.
	 * @param string $from   Source code, or empty for the store currency.
	 * @return float
	 */
	public static function convert( $amount, string $to = '', string $from = '' ): float {
		$amount = (float) $amount;
		try {
			switch ( self::owner() ) {
				case MultiCurrencyRuntimeArbiter::OWNER_EXTENSION:
					return ExtensionCurrencyBridge::convert( $amount, $to, $from );
				case MultiCurrencyRuntimeArbiter::OWNER_BUILTIN:
					return wc_get_container()->get( ServiceFactory::class )->create_price_converter()->get_raw_conversion(
						$amount,
						'' === $to ? self::state()->get_selected_currency()->get_code() : $to,
						'' === $from ? self::state()->get_default_currency()->get_code() : $from
					);
			}
		} catch ( InvalidCurrencyException | InvalidCurrencyRateException | \BadMethodCallException $exception ) {
			self::warn_once( __METHOD__, $exception->getMessage() );
		}
		return $amount;
	}

	/**
	 * Convert a store price using the target currency's rounding and charm settings for its price type.
	 *
	 * @since 11.4.0
	 * @param mixed  $amount Store price converted using PHP's float cast. Null and strings without a numeric prefix become zero.
	 * @param string $type   One of 'product', 'shipping', 'tax', 'coupon', or 'exchange_rate'.
	 * @param string $to     Target code, or empty for the selected currency.
	 * @return float
	 */
	public static function convert_price( $amount, string $type = 'product', string $to = '' ): float {
		try {
			switch ( self::owner() ) {
				case MultiCurrencyRuntimeArbiter::OWNER_EXTENSION:
					return ExtensionCurrencyBridge::convert_price( (float) $amount, $type, $to );
				case MultiCurrencyRuntimeArbiter::OWNER_BUILTIN:
					return wc_get_container()->get( ServiceFactory::class )->create_price_converter()->get_price_for_currency_code( $amount, $type, $to );
			}
		} catch ( InvalidCurrencyException | \BadMethodCallException $exception ) {
			self::warn_once( __METHOD__, $exception->getMessage() );
		}
		return (float) $amount;
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
