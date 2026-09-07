#!/usr/bin/env bash

set -euo pipefail

if [[ "${WCPAY_RUNTIME:?WCPAY_RUNTIME is required}" != 'native' ]]; then
	echo 'Readonly preconditions are supported only for WCPAY_RUNTIME=native.' >&2
	exit 1
fi

readonly STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_WP_ENV_CONFIG:?E2E_WOOPAYMENTS_WP_ENV_CONFIG is required}"

if [[ ! -d "$STORE_DIR" ]]; then
	echo "Native store directory does not exist: $STORE_DIR" >&2
	exit 1
fi

readonly SEED_CODE='$settings = get_option( "woocommerce_woocommerce_payments_settings", array() ); $settings = is_array( $settings ) ? $settings : array(); $settings["enabled"] = "yes"; $settings["test_mode"] = "yes"; $settings["platform_checkout"] = "no"; $settings["upe_enabled_payment_method_ids"] = array( "card", "klarna" ); $settings["express_checkout_product_methods"] = array( "woopay" ); $settings["express_checkout_cart_methods"] = array( "woopay" ); $settings["express_checkout_checkout_methods"] = array( "woopay" ); if ( defined( "E2E_WOOPAYMENTS_NATIVE_FIXTURE" ) && E2E_WOOPAYMENTS_NATIVE_FIXTURE ) { update_option( "woocommerce_coming_soon", "no" ); $settings["manual_capture"] = "no"; $currency_cache_time = time(); update_option( "wcpay_multi_currency_cached_currencies", array( "data" => array( "currencies" => array(), "updated" => $currency_cache_time ), "fetched" => $currency_cache_time, "errored" => false, "consecutive_errors" => 0 ), false ); wp_cache_delete( "wcpay_multi_currency_cached_currencies", "options" ); wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" ); } update_option( "woocommerce_woocommerce_payments_settings", $settings ); update_option( "_wcpay_feature_woopay_express_checkout", "1" ); update_option( "_wcpay_feature_customer_multi_currency", "1" ); update_option( "wcpay_multi_currency_enabled_currencies", array( "USD", "EUR" ) ); update_option( "wcpay_multi_currency_exchange_rate_eur", "manual" ); update_option( "wcpay_multi_currency_manual_rate_eur", "0.8" ); update_option( "wcpay_multi_currency_price_rounding_eur", "0" ); update_option( "wcpay_multi_currency_price_charm_eur", "0" ); if ( defined( "E2E_WOOPAYMENTS_NATIVE_FIXTURE" ) && E2E_WOOPAYMENTS_NATIVE_FIXTURE ) { if ( ! class_exists( "WooCommerce_WooPayments_Native_CI_Provider_Fixture" ) ) { throw new RuntimeException( "WooPayments native CI provider fixture is unavailable." ); } $fixture = new WooCommerce_WooPayments_Native_CI_Provider_Fixture(); $account_cache = $fixture->account_cache(); if ( empty( $account_cache["data"]["account_id"] ) || empty( $account_cache["data"]["payments_enabled"] ) || empty( $account_cache["data"]["details_submitted"] ) ) { throw new RuntimeException( "WooPayments native CI account fixture is not connected." ); } update_option( "wcpay_onboarding_test_mode", "yes", false ); update_option( "wcpay_account_data", $account_cache, false ); } echo "readonly-preconditions-seeded";'

(
	cd "$STORE_DIR"
	pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp --user=1 eval "$SEED_CODE"
)
