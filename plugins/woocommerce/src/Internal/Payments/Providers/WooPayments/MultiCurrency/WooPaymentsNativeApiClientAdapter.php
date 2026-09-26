<?php
/**
 * WooPaymentsNativeApiClientAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * Adapts the native WooPayments API client to the native multi-currency boundary.
 *
 * @since 11.0.0
 * @internal Transitional bridge while WooPayments provider transport is absorbed into core.
 */
class WooPaymentsNativeApiClientAdapter implements MultiCurrencyApiClientInterface {

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient $api_client Native WooPayments API client.
	 */
	final public function init( WooPaymentsApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Tell whether the API client is connected to its server.
	 *
	 * @return bool
	 */
	public function is_server_connected(): bool {
		try {
			return $this->api_client->is_available();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get currency rates.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 */
	public function get_currency_rates( string $currency_from, $currencies_to = null ): array {
		try {
			return $this->api_client->get_currency_rates( $currency_from, $currencies_to );
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}
