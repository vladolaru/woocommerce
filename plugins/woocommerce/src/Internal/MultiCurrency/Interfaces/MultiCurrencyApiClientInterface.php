<?php
/**
 * MultiCurrencyApiClientInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Interfaces;

/**
 * The API client the WooPayments rate provider fetches currency rates through, and whether it reaches its server.
 *
 * @since 11.0.0
 * @internal
 */
interface MultiCurrencyApiClientInterface {

	/**
	 * Tell whether the API client is connected to its server.
	 *
	 * @return bool
	 */
	public function is_server_connected(): bool;

	/**
	 * Get currency rates.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 * @throws \Throwable When the fetch fails; the rate cache then keeps its last good rates.
	 */
	public function get_currency_rates( string $currency_from, $currencies_to = null ): array;
}
