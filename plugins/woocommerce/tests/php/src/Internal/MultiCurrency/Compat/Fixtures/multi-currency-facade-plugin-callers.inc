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
	 * Tell whether shoppers can see converted prices, as Themify Product Filter 1.5.8 does before it converts its price
	 * range (`includes/plugin-compat/wooPayments.php:44-53`).
	 *
	 * @return bool
	 */
	public function themify_multi_price_enabled(): bool {
		$multi_currency = \WCPay\MultiCurrency\MultiCurrency::instance();
		if ( ! $multi_currency->get_compatibility()->should_disable_currency_switching() ) {
			$enabled_currencies = $multi_currency->get_enabled_currencies();
			if ( count( $enabled_currencies ) > 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a price range in the selected currency back to the store currency for the price query, as Themify Product
	 * Filter 1.5.8 does (`includes/plugin-compat/wooPayments.php:26-38`).
	 *
	 * @param array{from:float,to:float} $price Price range in the selected currency.
	 * @return array{from:float,to:float}
	 */
	public function themify_filter_by_price( array $price ): array {
		if ( $this->themify_multi_price_enabled() ) {
			$multi_currency        = \WCPay\MultiCurrency\MultiCurrency::instance();
			$current_currency_code = $multi_currency->get_selected_currency()->get_code();
			$default_currency_code = $multi_currency->get_default_currency()->get_code();
			if ( $current_currency_code !== $default_currency_code ) {
				$price['from'] = $multi_currency->get_raw_conversion( $price['from'], $default_currency_code, $current_currency_code );
				$price['to']   = $multi_currency->get_raw_conversion( $price['to'], $default_currency_code, $current_currency_code );
			}
		}

		return $price;
	}

	/**
	 * Name the WooPayments Multi-Currency as incompatible unless it enables only FOX's default currency, as FOX Currency
	 * Switcher 1.5.4 does after its guard (`classes/woocs.php`, `notice_incompatibility_plugin()`).
	 *
	 * @param string $default_currency FOX's default currency.
	 * @return string The incompatible option named in FOX's notice, or an empty string.
	 */
	public function fox_incompatible_plugin_notice( string $default_currency ): string {
		$multi_currency      = \WCPay\MultiCurrency\MultiCurrency::instance();
		$woocurrencies       = $multi_currency->get_enabled_currencies();
		$incompatible_plugin = 'Option: Multi-currency of WooPayments plugin by Woo.';
		if ( count( $woocurrencies ) === 1 && $multi_currency->get_default_currency()->get_code() === $default_currency ) {
			$incompatible_plugin = '';
		}

		return $incompatible_plugin;
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

	/**
	 * Clear the Multi-Currency inbox notes, as WooPayments Dev Tools does on its "clear notes" action
	 * (`woocommerce-payments-dev-tools.php:1219-1222`).
	 *
	 * @return bool Whether a notes class answered.
	 */
	public function dev_tools_clear_notes(): bool {
		$notes_cleared = false;
		if ( class_exists( 'WCPay\MultiCurrency\MultiCurrency' ) ) {
			\WCPay\MultiCurrency\MultiCurrency::remove_woo_admin_notes();
			$notes_cleared = true;
		}

		return $notes_cleared;
	}
}
