<?php
/**
 * MultiCurrencyController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RateService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Connects WooPayments to Multi-Currency: flags it in the payment fields config and sets the WooPayments rate provider on
 * the rate service, over the account service and API client of whichever WooPayments owns payments.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyController implements RegisterHooksInterface {

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Adapter over the WooPayments extension's account service.
	 *
	 * @var WooPaymentsLegacyAccountAdapter
	 */
	private WooPaymentsLegacyAccountAdapter $legacy_account_adapter;

	/**
	 * Adapter over the WooPayments extension's API client.
	 *
	 * @var WooPaymentsLegacyApiClientAdapter
	 */
	private WooPaymentsLegacyApiClientAdapter $legacy_api_client_adapter;

	/**
	 * Multi-Currency rate service.
	 *
	 * @var RateService
	 */
	private RateService $rate_service;

	/**
	 * Initialize the controller.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter         $arbiter                   Runtime ownership arbiter.
	 * @param WooPaymentsLegacyAccountAdapter   $legacy_account_adapter    Adapter over the WooPayments extension's account service.
	 * @param WooPaymentsLegacyApiClientAdapter $legacy_api_client_adapter Adapter over the WooPayments extension's API client.
	 * @param RateService                       $rate_service              Multi-Currency rate service.
	 */
	final public function init(
		WooPaymentsRuntimeArbiter $arbiter,
		WooPaymentsLegacyAccountAdapter $legacy_account_adapter,
		WooPaymentsLegacyApiClientAdapter $legacy_api_client_adapter,
		RateService $rate_service
	): void {
		$this->arbiter                   = $arbiter;
		$this->legacy_account_adapter    = $legacy_account_adapter;
		$this->legacy_api_client_adapter = $legacy_api_client_adapter;
		$this->rate_service              = $rate_service;
	}

	/**
	 * Add the payment fields flag, and set the rate provider for the current payments owner.
	 *
	 * @since 11.0.0
	 */
	public function register(): void {
		// The Blocks totals show the currency code while Multi-Currency runs (client 11.1.0 `includes/multi-currency/MultiCurrency.php:290`).
		add_filter( 'wcpay_payment_fields_js_config', array( $this, 'add_multi_currency_config' ) );

		if ( $this->arbiter->is_builtin_owner() ) {
			// Resolved here, so a store that runs Multi-Currency without the built-in WooPayments loads no WooPayments account service or API client.
			$container = wc_get_container();
			$this->set_rate_provider( $container->get( WooPaymentsNativeAccountAdapter::class ), $container->get( WooPaymentsNativeApiClientAdapter::class ) );
			add_filter( 'woocommerce_analytics_report_should_use_cache', array( $this, 'skip_analytics_report_cache_in_dev_mode' ) );
			return;
		}

		if ( ! $this->arbiter->is_extension_owner() ) {
			return;
		}

		$this->set_rate_provider( $this->legacy_account_adapter, $this->legacy_api_client_adapter );
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
	 * Serve uncached Analytics reports in WooPayments dev mode (client 11.1.0 `includes/multi-currency/Analytics.php:75-77`, `:176-178`).
	 *
	 * Dev mode is read when a report runs, which is after every plugin has loaded and `init` has fired.
	 *
	 * @internal
	 *
	 * @param mixed $use_cache Whether Analytics reports use the cache.
	 * @return mixed
	 */
	public function skip_analytics_report_cache_in_dev_mode( $use_cache ) {
		return wc_get_container()->get( WooPaymentsAccountService::class )->is_dev_mode_enabled() ? false : $use_cache;
	}

	/**
	 * Set the WooPayments rate provider over the selected adapter pair on the Multi-Currency rate service.
	 *
	 * @param MultiCurrencyAccountInterface   $account_adapter    Selected account adapter.
	 * @param MultiCurrencyApiClientInterface $api_client_adapter Selected API client adapter.
	 */
	private function set_rate_provider( MultiCurrencyAccountInterface $account_adapter, MultiCurrencyApiClientInterface $api_client_adapter ): void {
		$this->rate_service->set_provider( new CurrencyRateProvider( $account_adapter, $api_client_adapter ) );
	}
}
