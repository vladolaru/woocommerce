<?php
/**
 * WordPress.org plugin callers of the WooPayments extension's Multi-Currency class, reduced to their calls.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

/**
 * Reproduces the calls WooCommerce Payfast Gateway, Themify Product Filter, FOX Currency Switcher, Advanced Dynamic
 * Pricing and WooPayments Dev Tools make on `WCPay\MultiCurrency\MultiCurrency`, with the guards in front of them.
 */
class MultiCurrencyFacadePluginCallersFixture {

	/**
	 * Keep ZAR when the shopper selected it, as WooCommerce Payfast Gateway 1.7.8 does
	 * (`includes/class-wc-gateway-payfast.php`, `filter_currency()`): a guest's currency from the session, a customer's from
	 * user meta, both through the facade's key constants.
	 *
	 * @param string $currency Store currency.
	 * @return string
	 */
	public function payfast_filter_currency( string $currency ): string {
		if ( ! class_exists( '\WCPay\MultiCurrency\MultiCurrency' ) || is_admin() ) {
			return $currency;
		}

		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			$currency_code = WC()->session ? WC()->session->get( \WCPay\MultiCurrency\MultiCurrency::CURRENCY_SESSION_KEY ) : null;
		} else {
			$currency_code = get_user_meta( $user_id, \WCPay\MultiCurrency\MultiCurrency::CURRENCY_META_KEY, true );
		}

		return 'ZAR' === $currency_code ? 'ZAR' : $currency;
	}

	/**
	 * Stop the Multi-Currency product price conversion of a product already converted elsewhere, as Advanced Dynamic
	 * Pricing 4.14.0 does (`BaseVersion/Includes/Compatibility/WooCommerceMultiCurrencyCmp.php:74`).
	 *
	 * @return callable The callback added, for removal.
	 */
	public function adp_stop_product_price_conversion(): callable {
		$return_false = static function () {
			return false;
		};
		add_filter( \WCPay\MultiCurrency\MultiCurrency::FILTER_PREFIX . 'should_convert_product_price', $return_false );

		return $return_false;
	}
}
