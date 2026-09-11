<?php
/**
 * Local-only native payments boot isolation helper.
 *
 * Enables native ownership but makes WooPayments account data unavailable so
 * gateway registration should stay dormant. This is for root-cause isolation
 * only and must not be used as a product workaround.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );
add_filter(
	'pre_option_wcpay_account_data',
	static function () {
		return array();
	}
);
