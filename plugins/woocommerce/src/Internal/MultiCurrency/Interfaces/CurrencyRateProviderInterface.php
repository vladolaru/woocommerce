<?php
/**
 * CurrencyRateProviderInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Interfaces;

/**
 * The automatic-rate source the rate service holds: its ID, availability, account connection, supported currencies
 * and rates.
 *
 * Manual rates need no provider. The WooPayments provider implements it.
 *
 * @since 11.0.0
 * @internal
 */
interface CurrencyRateProviderInterface {

	/**
	 * Get the provider identifier.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Tell whether automatic rates are currently available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Tell whether the provider's account is connected, whether or not rates are available now.
	 *
	 * @return bool
	 */
	public function is_connected(): bool;

	/**
	 * Get supported customer currencies.
	 *
	 * An empty array means all WooCommerce currencies are supported.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies(): array;

	/**
	 * Get currency rates.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 * @throws \Throwable When the fetch fails; the rate cache then keeps its last good rates.
	 */
	public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array;
}
