<?php
/**
 * WCPay\MultiCurrency\MultiCurrency facade.
 */

declare( strict_types = 1 );

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

// phpcs:disable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- The WooPayments extension's file and class casing are the compatibility contract.
/**
 * Stands in for the WooPayments extension's Multi-Currency class while WooCommerce runs Multi-Currency, so Woo
 * extensions that call it get WooCommerce's answer.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 No public replacement. Scheduled for removal in WooCommerce 12.0.0.
 */
class MultiCurrency {
	// phpcs:enable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

	/**
	 * Session key holding the shopper's selected currency. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CURRENCY_SESSION_KEY = MultiCurrencyStateBuilder::CURRENCY_STORAGE_KEY;

	/**
	 * User meta key holding the customer's selected currency. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CURRENCY_META_KEY = MultiCurrencyStateBuilder::CURRENCY_STORAGE_KEY;

	/**
	 * Prefix of the Multi-Currency hook names. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const FILTER_PREFIX = MultiCurrencyStateBuilder::FILTER_PREFIX;

	/**
	 * Option holding the currencies customers have ordered in. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CUSTOMER_CURRENCIES_KEY = MultiCurrencyStateBuilder::CUSTOMER_CURRENCIES_KEY;

	/**
	 * Rendering mode that converts prices on the server. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const RENDERING_MODE_SPEED = MultiCurrencyDisplayService::RENDERING_MODE_SPEED;

	/**
	 * Rendering mode that converts prices in the browser, for cached pages. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const RENDERING_MODE_CACHE = MultiCurrencyDisplayService::RENDERING_MODE_CACHE;

	/**
	 * Option holding the rendering mode. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const RENDERING_MODE_OPTION = MultiCurrencyDisplayService::RENDERING_MODE_OPTION;

	/**
	 * Option set once the page-cache detection has run. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CACHE_AUTODETECT_DONE_OPTION = MultiCurrencyCacheRenderingService::AUTODETECT_DONE_OPTION;

	/**
	 * Option set when the merchant dismissed the cache-mode recommendation. Removed in WooCommerce 12.0.0.
	 *
	 * @deprecated 11.0.0 No public replacement.
	 */
	const CACHE_RECOMMENDATION_DISMISSED_OPTION = MultiCurrencyCacheRenderingService::DISMISSED_OPTION;

	/**
	 * Request-local facade instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Return the request's facade instance.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return self
	 */
	public static function instance(): self {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::instance', '11.0.0', "WooCommerce's native multi-currency APIs" );

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Convert a price to the selected currency; a product price is recorded for the Deposits controller.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed  $amount Price amount.
	 * @param string $type   Price type.
	 * @return float
	 */
	public function get_price( $amount, string $type ): float {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_price', '11.0.0', "WooCommerce's native multi-currency APIs" );

		return $this->get_loader()->get_price( $amount, $type );
	}

	/**
	 * Convert an amount between two currencies at their rates; an empty source currency is the store currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param float  $amount        Amount.
	 * @param string $to_currency   Target currency code.
	 * @param string $from_currency Source currency code.
	 * @return float
	 */
	public function get_raw_conversion( float $amount, string $to_currency, string $from_currency = '' ): float {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion', '11.0.0', "WooCommerce's native multi-currency APIs" );

		return $this->get_loader()->get_raw_conversion( $amount, $to_currency, $from_currency );
	}

	/**
	 * Get the selected currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_selected_currency(): MultiCurrencyCurrency {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_selected_currency', '11.0.0', "WooCommerce's native multi-currency APIs" );

		return $this->get_loader()->get_selected_currency();
	}

	/**
	 * Get the store's default currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return MultiCurrencyCurrency
	 */
	public function get_default_currency(): MultiCurrencyCurrency {
		_deprecated_function( 'WCPay\\MultiCurrency\\MultiCurrency::get_default_currency', '11.0.0', "WooCommerce's native multi-currency APIs" );

		return $this->get_loader()->get_default_currency();
	}

	/**
	 * Get the container-owned compatibility loader.
	 *
	 * @return WooPaymentsExtensionMultiCurrencyFacadeLoader
	 */
	private function get_loader(): WooPaymentsExtensionMultiCurrencyFacadeLoader {
		return wc_get_container()->get( WooPaymentsExtensionMultiCurrencyFacadeLoader::class );
	}
}
