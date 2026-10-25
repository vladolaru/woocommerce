<?php
/**
 * MultiCurrencyLocalizationInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Interfaces;

/**
 * Gives Multi-Currency each currency's format for the user's locale and each country's locale data.
 *
 * @since 11.0.0
 * @internal
 */
interface MultiCurrencyLocalizationInterface {

	/**
	 * Get a currency format.
	 *
	 * @param string $currency_code Currency code.
	 * @return array<string,mixed>
	 */
	public function get_currency_format( $currency_code ): array;

	/**
	 * Get locale data for a country.
	 *
	 * @param string $country Country code.
	 * @return array<string,mixed>
	 */
	public function get_country_locale_data( $country ): array;
}
