<?php
/**
 * Minimal Woo-owned extension consumers of the legacy MultiCurrency facade.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

/**
 * Reproduces the guarded WooCommerce Deposits and Table Rate Shipping call shapes.
 */
class LegacyMultiCurrencyConsumerFixture {

	/**
	 * Convert a fixed deposit through the WooPayments MultiCurrency facade.
	 *
	 * @param float $amount Fixed deposit amount.
	 * @return float
	 */
	public function get_woopayments_multicurrency_price( float $amount ): float {
		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency' ) ) {
			return $amount;
		}

		return \WCPay\MultiCurrency\MultiCurrency::instance()->get_price( $amount, 'product' );
	}

	/**
	 * Convert a selected-currency price back to the store currency for rate rules.
	 *
	 * @param float $amount Selected-currency amount.
	 * @return float
	 */
	public function get_table_rate_base_currency_price( float $amount ): float {
		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency' ) ) {
			return $amount;
		}

		$multi_currency    = \WCPay\MultiCurrency\MultiCurrency::instance();
		$selected_currency = $multi_currency->get_selected_currency();
		$base_currency     = $multi_currency->get_default_currency();

		if ( $selected_currency->get_code() === $base_currency->get_code() ) {
			return $amount;
		}

		return $multi_currency->get_raw_conversion( $amount, $base_currency->get_code(), $selected_currency->get_code() );
	}
}
