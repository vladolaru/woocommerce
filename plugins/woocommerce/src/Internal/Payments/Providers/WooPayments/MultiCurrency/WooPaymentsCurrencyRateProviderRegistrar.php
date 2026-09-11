<?php
/**
 * WooPaymentsCurrencyRateProviderRegistrar class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;

/**
 * Registers the WooPayments-backed automatic FX rate provider.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class WooPaymentsCurrencyRateProviderRegistrar implements CurrencyRateProviderRegistrarInterface {

	/**
	 * WooPayments account adapter.
	 *
	 * @var MultiCurrencyAccountInterface
	 */
	private MultiCurrencyAccountInterface $account_adapter;

	/**
	 * WooPayments API client adapter.
	 *
	 * @var MultiCurrencyApiClientInterface
	 */
	private MultiCurrencyApiClientInterface $api_client_adapter;

	/**
	 * Initialize the registrar.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyAccountAdapter   $account_adapter    Legacy WooPayments account adapter.
	 * @param WooPaymentsLegacyApiClientAdapter $api_client_adapter Legacy WooPayments API client adapter.
	 */
	final public function init( WooPaymentsLegacyAccountAdapter $account_adapter, WooPaymentsLegacyApiClientAdapter $api_client_adapter ): void {
		$this->set_adapters( $account_adapter, $api_client_adapter );
	}

	/**
	 * Set the adapter pair selected by runtime ownership.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyAccountInterface   $account_adapter    WooPayments account adapter.
	 * @param MultiCurrencyApiClientInterface $api_client_adapter WooPayments API client adapter.
	 */
	public function set_adapters( MultiCurrencyAccountInterface $account_adapter, MultiCurrencyApiClientInterface $api_client_adapter ): void {
		$this->account_adapter    = $account_adapter;
		$this->api_client_adapter = $api_client_adapter;
	}

	/**
	 * Register the WooPayments rate provider.
	 *
	 * @param CurrencyRateProviderRegistry $registry Rate provider registry.
	 */
	public function register( CurrencyRateProviderRegistry $registry ): void {
		$registry->register( new WooPaymentsCurrencyRateProvider( $this->account_adapter, $this->api_client_adapter ) );
	}
}
