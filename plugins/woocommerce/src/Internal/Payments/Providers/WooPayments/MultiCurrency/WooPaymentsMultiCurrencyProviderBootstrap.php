<?php
/**
 * WooPaymentsMultiCurrencyProviderBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\MultiCurrencyProviderAccountResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Configures WooPayments-owned native multi-currency provider boundaries.
 *
 * @since 11.0.0
 * @internal Transitional bootstrap while WooPayments multi-currency runtime is absorbed into core.
 */
class WooPaymentsMultiCurrencyProviderBootstrap implements RegisterHooksInterface {

	/**
	 * Provider account resolver.
	 *
	 * @var MultiCurrencyProviderAccountResolver
	 */
	private MultiCurrencyProviderAccountResolver $account_resolver;

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Legacy WooPayments account adapter.
	 *
	 * @var WooPaymentsLegacyAccountAdapter
	 */
	private WooPaymentsLegacyAccountAdapter $legacy_account_adapter;

	/**
	 * Legacy WooPayments API client adapter.
	 *
	 * @var WooPaymentsLegacyApiClientAdapter
	 */
	private WooPaymentsLegacyApiClientAdapter $legacy_api_client_adapter;

	/**
	 * Native WooPayments account adapter.
	 *
	 * @var WooPaymentsNativeAccountAdapter
	 */
	private WooPaymentsNativeAccountAdapter $native_account_adapter;

	/**
	 * Native WooPayments API client adapter.
	 *
	 * @var WooPaymentsNativeApiClientAdapter
	 */
	private WooPaymentsNativeApiClientAdapter $native_api_client_adapter;

	/**
	 * Rate provider registry factory.
	 *
	 * @var CurrencyRateProviderRegistryFactory
	 */
	private CurrencyRateProviderRegistryFactory $provider_registry_factory;

	/**
	 * WooPayments rate provider registrar.
	 *
	 * @var WooPaymentsCurrencyRateProviderRegistrar
	 */
	private WooPaymentsCurrencyRateProviderRegistrar $provider_registrar;

	/**
	 * Initialize the bootstrap.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyProviderAccountResolver     $account_resolver          Provider account resolver.
	 * @param WooPaymentsRuntimeArbiter                $arbiter                   Runtime ownership arbiter.
	 * @param WooPaymentsLegacyAccountAdapter          $legacy_account_adapter    Legacy WooPayments account adapter.
	 * @param WooPaymentsLegacyApiClientAdapter        $legacy_api_client_adapter Legacy WooPayments API client adapter.
	 * @param WooPaymentsNativeAccountAdapter          $native_account_adapter    Native WooPayments account adapter.
	 * @param WooPaymentsNativeApiClientAdapter        $native_api_client_adapter Native WooPayments API client adapter.
	 * @param CurrencyRateProviderRegistryFactory      $provider_registry_factory Rate provider registry factory.
	 * @param WooPaymentsCurrencyRateProviderRegistrar $provider_registrar        WooPayments rate provider registrar.
	 */
	final public function init(
		MultiCurrencyProviderAccountResolver $account_resolver,
		WooPaymentsRuntimeArbiter $arbiter,
		WooPaymentsLegacyAccountAdapter $legacy_account_adapter,
		WooPaymentsLegacyApiClientAdapter $legacy_api_client_adapter,
		WooPaymentsNativeAccountAdapter $native_account_adapter,
		WooPaymentsNativeApiClientAdapter $native_api_client_adapter,
		CurrencyRateProviderRegistryFactory $provider_registry_factory,
		WooPaymentsCurrencyRateProviderRegistrar $provider_registrar
	): void {
		$this->account_resolver          = $account_resolver;
		$this->arbiter                   = $arbiter;
		$this->legacy_account_adapter    = $legacy_account_adapter;
		$this->legacy_api_client_adapter = $legacy_api_client_adapter;
		$this->native_account_adapter    = $native_account_adapter;
		$this->native_api_client_adapter = $native_api_client_adapter;
		$this->provider_registry_factory = $provider_registry_factory;
		$this->provider_registrar        = $provider_registrar;
	}

	/**
	 * Register WooPayments multi-currency provider boundaries.
	 *
	 * @since 11.0.0
	 */
	public function register(): void {
		// The Blocks totals show the currency code while Multi-Currency runs (client 11.1.0 `includes/multi-currency/MultiCurrency.php:290`).
		if ( false === has_filter( 'wcpay_payment_fields_js_config', array( $this, 'add_multi_currency_config' ) ) ) {
			add_filter( 'wcpay_payment_fields_js_config', array( $this, 'add_multi_currency_config' ) );
		}

		if ( $this->arbiter->is_builtin_owner() ) {
			$this->register_adapters( $this->native_account_adapter, $this->native_api_client_adapter );
			return;
		}

		if ( ! $this->arbiter->is_extension_owner() ) {
			return;
		}

		$this->register_adapters( $this->legacy_account_adapter, $this->legacy_api_client_adapter );
	}

	/**
	 * Tell the payment fields that Multi-Currency is on (client 11.1.0 `includes/multi-currency/MultiCurrency.php:478-480`).
	 *
	 * @internal
	 *
	 * @param mixed $config Payment fields JS config.
	 * @return mixed
	 */
	public function add_multi_currency_config( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}

		$config['isMultiCurrencyEnabled'] = true;

		return $config;
	}

	/**
	 * Register the selected WooPayments multi-currency adapter pair.
	 *
	 * @param MultiCurrencyAccountInterface   $account_adapter    Selected account adapter.
	 * @param MultiCurrencyApiClientInterface $api_client_adapter Selected API client adapter.
	 */
	private function register_adapters( MultiCurrencyAccountInterface $account_adapter, MultiCurrencyApiClientInterface $api_client_adapter ): void {
		$this->account_resolver->set_account( $account_adapter );
		$this->provider_registrar->set_adapters( $account_adapter, $api_client_adapter );
		$this->provider_registry_factory->set_provider_registrars( array( $this->provider_registrar ) );
	}
}
