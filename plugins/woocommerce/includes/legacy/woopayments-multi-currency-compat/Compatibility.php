<?php
/**
 * WCPay\MultiCurrency\Compatibility facade.
 */

declare( strict_types = 1 );

namespace WCPay\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\IntegrationsController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsCompatibilityNotice;

/**
 * Stands in for the WooPayments extension's Multi-Currency compatibility class, which `MultiCurrency::get_compatibility()`
 * returns, while WooCommerce runs Multi-Currency: its eight predicates answer by the module's rules.
 *
 * It does not extend the extension's `BaseCompatibility`.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 No public replacement. Scheduled for removal in WooCommerce 12.0.0.
 */
class Compatibility {

	use WooPaymentsCompatibilityNotice;

	/**
	 * Get a currency code that overrides the shopper's selected currency, or false.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev Use apply_filters( 'wcpay_multi_currency_override_selected_currency', false ) instead.
	 *
	 * @return mixed
	 */
	public function override_selected_currency() {
		self::warn_compatibility_method( __METHOD__, "apply_filters( 'wcpay_multi_currency_override_selected_currency', false )" );

		return $this->get_controller()->override_selected_currency();
	}

	/**
	 * Tell whether currency switching is disabled, under the name the extension deprecated in 6.5.0.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev No public replacement.
	 *
	 * @return bool
	 */
	public function should_hide_widgets(): bool {
		self::warn_compatibility_method( __METHOD__ );

		return $this->get_controller()->should_disable_currency_switching();
	}

	/**
	 * Tell whether currency switching, the switchers and the switch by location, is disabled on this request.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev No public replacement.
	 *
	 * @return bool
	 */
	public function should_disable_currency_switching(): bool {
		self::warn_compatibility_method( __METHOD__ );

		return $this->get_controller()->should_disable_currency_switching();
	}

	/**
	 * Tell whether a coupon's amount is converted to the selected currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev Use ! $coupon || (bool) apply_filters( 'wcpay_multi_currency_should_convert_coupon_amount', true, $coupon ) instead.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	public function should_convert_coupon_amount( $coupon = null ): bool {
		self::warn_compatibility_method( __METHOD__, "! \$coupon || (bool) apply_filters( 'wcpay_multi_currency_should_convert_coupon_amount', true, \$coupon )" );

		return $this->get_controller()->should_convert_coupon_amount( $coupon );
	}

	/**
	 * Tell whether a coupon's minimum and maximum spend are converted to the selected currency; with no coupon, they are.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev Use ! $coupon || (bool) apply_filters( 'wcpay_multi_currency_should_convert_coupon_min_max_amount', true, $coupon ) instead.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	public function should_convert_coupon_min_max_amount( $coupon = null ): bool {
		self::warn_compatibility_method( __METHOD__, "! \$coupon || (bool) apply_filters( 'wcpay_multi_currency_should_convert_coupon_min_max_amount', true, \$coupon )" );

		if ( ! $coupon ) {
			return true;
		}

		/**
		 * Filters whether a coupon's minimum and maximum spend should be converted to the selected currency.
		 *
		 * @since 11.4.0
		 *
		 * @param bool  $should_convert Whether the minimum and maximum spend should be converted.
		 * @param mixed $coupon         Coupon object.
		 */
		return (bool) apply_filters( StateBuilder::FILTER_PREFIX . 'should_convert_coupon_min_max_amount', true, $coupon );
	}

	/**
	 * Tell whether a product's price is converted to the selected currency.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev Use ! $product || (bool) apply_filters( 'wcpay_multi_currency_should_convert_product_price', true, $product ) instead.
	 *
	 * @param mixed $product Product object.
	 * @return bool
	 */
	public function should_convert_product_price( $product = null ): bool {
		self::warn_compatibility_method( __METHOD__, "! \$product || (bool) apply_filters( 'wcpay_multi_currency_should_convert_product_price', true, \$product )" );

		return $this->get_controller()->should_convert_product_price( $product );
	}

	/**
	 * Tell whether a shipping method's rate cost is converted to the selected currency; with no method, it is.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev Use ! $shipping_method || (bool) apply_filters( 'wcpay_multi_currency_should_convert_shipping_amount', true, $shipping_method ) instead.
	 *
	 * @param mixed $shipping_method Shipping method object.
	 * @return bool
	 */
	public function should_convert_shipping_amount( $shipping_method = null ): bool {
		self::warn_compatibility_method( __METHOD__, "! \$shipping_method || (bool) apply_filters( 'wcpay_multi_currency_should_convert_shipping_amount', true, \$shipping_method )" );

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
		return (bool) apply_filters( StateBuilder::FILTER_PREFIX . 'should_convert_shipping_amount', true, $shipping_method );
	}

	/**
	 * Tell whether WooCommerce sees the store currency instead of the selected one.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.0.0
	 * @deprecated 11.4.0-dev Use (bool) apply_filters( 'wcpay_multi_currency_should_return_store_currency', false ) instead.
	 *
	 * @return bool
	 */
	public function should_return_store_currency(): bool {
		self::warn_compatibility_method( __METHOD__, "(bool) apply_filters( 'wcpay_multi_currency_should_return_store_currency', false )" );

		return $this->get_controller()->should_return_store_currency();
	}

	/**
	 * Get the module's integrations controller.
	 *
	 * @return IntegrationsController
	 */
	private function get_controller(): IntegrationsController {
		return wc_get_container()->get( IntegrationsController::class );
	}
}
