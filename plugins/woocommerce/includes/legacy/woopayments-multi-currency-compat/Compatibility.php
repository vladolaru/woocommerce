<?php
/**
 * WCPay\MultiCurrency\Compatibility facade.
 */

declare( strict_types = 1 );

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

/**
 * Stands in for the WooPayments extension's Multi-Currency compatibility class, which `MultiCurrency::get_compatibility()`
 * returns, while WooCommerce runs Multi-Currency: its seven predicates answer by the module's rules.
 *
 * It does not extend the extension's `BaseCompatibility`. Removed in WooCommerce 12.0.0.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 No public replacement. Scheduled for removal in WooCommerce 12.0.0.
 */
class Compatibility {

	/**
	 * Get a currency code that overrides the shopper's selected currency, or false.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return mixed
	 */
	public function override_selected_currency() {
		_deprecated_function( 'WCPay\\MultiCurrency\\Compatibility::override_selected_currency', '11.0.0', '' );

		return $this->get_controller()->override_selected_currency();
	}

	/**
	 * Tell whether currency switching is disabled, under the name the extension deprecated in 6.5.0.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function should_hide_widgets(): bool {
		wc_deprecated_function( __FUNCTION__, '6.5.0', 'Compatibility::should_disable_currency_switching' );

		return $this->get_controller()->should_disable_currency_switching();
	}

	/**
	 * Tell whether currency switching, the switchers and the switch by location, is disabled on this request.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function should_disable_currency_switching(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\Compatibility::should_disable_currency_switching', '11.0.0', '' );

		return $this->get_controller()->should_disable_currency_switching();
	}

	/**
	 * Tell whether a coupon's amount is converted to the selected currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	public function should_convert_coupon_amount( $coupon = null ): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\Compatibility::should_convert_coupon_amount', '11.0.0', '' );

		return $this->get_controller()->should_convert_coupon_amount( $coupon );
	}

	/**
	 * Tell whether a product's price is converted to the selected currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed $product Product object.
	 * @return bool
	 */
	public function should_convert_product_price( $product = null ): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\Compatibility::should_convert_product_price', '11.0.0', '' );

		return $this->get_controller()->should_convert_product_price( $product );
	}

	/**
	 * Tell whether a shipping method's rate cost is converted to the selected currency; with no method, it is.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @param mixed $shipping_method Shipping method object.
	 * @return bool
	 */
	public function should_convert_shipping_amount( $shipping_method = null ): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\Compatibility::should_convert_shipping_amount', '11.0.0', '' );

		if ( ! $shipping_method ) {
			return true;
		}

		/**
		 * Filters whether a shipping amount should be converted.
		 *
		 * @since 11.2.0
		 *
		 * @param bool  $should_convert  Whether the shipping amount should be converted.
		 * @param mixed $shipping_method Shipping method object.
		 */
		return (bool) apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'should_convert_shipping_amount', true, $shipping_method );
	}

	/**
	 * Tell whether WooCommerce sees the store currency instead of the selected one.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 No public replacement.
	 *
	 * @return bool
	 */
	public function should_return_store_currency(): bool {
		_deprecated_function( 'WCPay\\MultiCurrency\\Compatibility::should_return_store_currency', '11.0.0', '' );

		return $this->get_controller()->should_return_store_currency();
	}

	/**
	 * Get the module's compatibility controller.
	 *
	 * @return MultiCurrencyCompatibilityController
	 */
	private function get_controller(): MultiCurrencyCompatibilityController {
		return wc_get_container()->get( MultiCurrencyCompatibilityController::class );
	}
}
