<?php
/**
 * MultiCurrencyAccountInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Interfaces;

/**
 * The payments account the WooPayments rate provider reads.
 *
 * @since 11.0.0
 * @internal
 */
interface MultiCurrencyAccountInterface {

	/**
	 * Tell whether the rate provider account is connected.
	 *
	 * @param bool $on_error Value to return on provider errors.
	 * @return bool
	 */
	public function is_provider_connected( bool $on_error = false ): bool;

	/**
	 * Tell whether the connected account is rejected.
	 *
	 * @return bool
	 */
	public function is_account_rejected(): bool;

	/**
	 * Get cached provider account data.
	 *
	 * @param bool $force_refresh Whether to force-refresh provider data.
	 * @return array<string,mixed>|bool
	 */
	public function get_cached_account_data( bool $force_refresh = false );

	/**
	 * Get account-supported customer currencies.
	 *
	 * @return string[]
	 */
	public function get_account_customer_supported_currencies(): array;
}
