<?php
/**
 * WooPaymentsCurrencyRateProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;

/**
 * The WooPayments automatic-rate provider: currency rates from the WooPayments API, available while the API client
 * reaches its server and the account is connected and not rejected.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsCurrencyRateProvider implements CurrencyRateProviderInterface {

	const PROVIDER_ID = 'woopayments';

	/**
	 * Account boundary.
	 *
	 * @var MultiCurrencyAccountInterface
	 */
	private MultiCurrencyAccountInterface $account;

	/**
	 * API client boundary.
	 *
	 * @var MultiCurrencyApiClientInterface
	 */
	private MultiCurrencyApiClientInterface $api_client;

	/**
	 * Constructor.
	 *
	 * @param MultiCurrencyAccountInterface   $account    Account boundary.
	 * @param MultiCurrencyApiClientInterface $api_client API client boundary.
	 */
	public function __construct( MultiCurrencyAccountInterface $account, MultiCurrencyApiClientInterface $api_client ) {
		$this->account    = $account;
		$this->api_client = $api_client;
	}

	/**
	 * Get the provider identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Tell whether automatic rates are currently available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->api_client->is_server_connected()
			&& $this->account->is_provider_connected()
			&& ! $this->account->is_account_rejected();
	}

	/**
	 * Tell whether the WooPayments account is connected.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->account->is_provider_connected();
	}

	/**
	 * Get supported customer currencies.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies(): array {
		$wc_currencies        = array_keys( get_woocommerce_currencies() );
		$account              = $this->account->get_cached_account_data();
		$supported_currencies = $this->account->get_account_customer_supported_currencies();

		if ( $account && ! empty( $supported_currencies ) ) {
			return array_values(
				array_intersect(
					array_values( array_unique( array_map( 'strtoupper', $supported_currencies ) ) ),
					$wc_currencies
				)
			);
		}

		return $wc_currencies;
	}

	/**
	 * Get currency rates.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 */
	public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
		return $this->api_client->get_currency_rates( $currency_from, $currencies_to );
	}
}
