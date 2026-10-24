<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Utilities;

/**
 * Helper methods for amounts in a given currency.
 *
 * @since 11.2.0
 */
class CurrencyUtil {

	/**
	 * Decimals of each currency code listed in i18n/locale-info.php, loaded on first use.
	 *
	 * @var array<string, int>|null
	 */
	private static ?array $locale_currency_decimals = null;

	/**
	 * Get the number of decimals to round amounts in a currency to.
	 *
	 * The store currency uses the store's price decimals. Another currency uses its own decimals when
	 * it has more, so an order placed in it is never rounded more coarsely than the store rounds its own.
	 *
	 * @param string $currency Currency code, for example the order's currency.
	 * @return int
	 *
	 * @since 11.2.0
	 */
	public static function get_price_decimals( string $currency ): int {
		$store_decimals = wc_get_price_decimals();

		if ( get_woocommerce_currency() === $currency ) {
			return $store_decimals;
		}

		return max( $store_decimals, self::get_locale_currency_decimals()[ $currency ] ?? 0 );
	}

	/**
	 * Get the decimals of each currency code listed in i18n/locale-info.php.
	 *
	 * @return array<string, int>
	 */
	private static function get_locale_currency_decimals(): array {
		if ( null === self::$locale_currency_decimals ) {
			self::$locale_currency_decimals = array();
			$locale_info                    = include WC()->plugin_path() . '/i18n/locale-info.php';

			foreach ( (array) $locale_info as $info ) {
				if ( isset( $info['currency_code'], $info['num_decimals'] ) ) {
					self::$locale_currency_decimals[ $info['currency_code'] ] = (int) $info['num_decimals'];
				}
			}
		}

		return self::$locale_currency_decimals;
	}
}
