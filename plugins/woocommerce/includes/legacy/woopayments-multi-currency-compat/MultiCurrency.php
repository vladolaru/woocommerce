<?php
/**
 * WCPay\MultiCurrency\MultiCurrency facade.
 */

declare( strict_types = 1 );

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;

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
