<?php
/**
 * Legacy WooPayments MultiCurrency facade.
 */

declare( strict_types = 1 );

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\LegacyMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;

// phpcs:disable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- The plugin-owned file and class casing are the compatibility contract.
/**
 * Compatibility facade for Woo extensions that use the WooPayments MultiCurrency class.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 Use WooCommerce's native multi-currency APIs. Scheduled for removal in WooCommerce 12.0.0.
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
	 * Return the request-local compatibility facade.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use WooCommerce's native multi-currency APIs.
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
	 * Project a price into the selected currency.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use WooCommerce's native multi-currency APIs.
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
	 * Project a raw conversion between two currencies.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use WooCommerce's native multi-currency APIs.
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
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use WooCommerce's native multi-currency APIs.
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
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use WooCommerce's native multi-currency APIs.
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
	 * @return LegacyMultiCurrencyFacadeLoader
	 */
	private function get_loader(): LegacyMultiCurrencyFacadeLoader {
		return wc_get_container()->get( LegacyMultiCurrencyFacadeLoader::class );
	}
}
