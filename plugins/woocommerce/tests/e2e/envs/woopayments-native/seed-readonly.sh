#!/usr/bin/env bash

set -euo pipefail

if [[ "${WCPAY_RUNTIME:?WCPAY_RUNTIME is required}" != 'native' ]]; then
	echo 'Readonly preconditions are supported only for WCPAY_RUNTIME=native.' >&2
	exit 1
fi

readonly STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"

if [[ ! -d "$STORE_DIR" ]]; then
	echo "Native store directory does not exist: $STORE_DIR" >&2
	exit 1
fi

readonly SEED_CODE='$settings = get_option( "woocommerce_woocommerce_payments_settings", array() ); $settings = is_array( $settings ) ? $settings : array(); $settings["enabled"] = "yes"; $settings["test_mode"] = "yes"; $settings["platform_checkout"] = "no"; $settings["upe_enabled_payment_method_ids"] = array( "card", "klarna" ); $settings["express_checkout_product_methods"] = array( "woopay" ); $settings["express_checkout_cart_methods"] = array( "woopay" ); $settings["express_checkout_checkout_methods"] = array( "woopay" ); update_option( "woocommerce_woocommerce_payments_settings", $settings ); update_option( "_wcpay_feature_woopay_express_checkout", "1" ); update_option( "_wcpay_feature_customer_multi_currency", "1" ); update_option( "wcpay_multi_currency_enabled_currencies", array( "USD", "EUR" ) ); update_option( "wcpay_multi_currency_exchange_rate_eur", "manual" ); update_option( "wcpay_multi_currency_manual_rate_eur", "0.8" ); update_option( "wcpay_multi_currency_price_rounding_eur", "0" ); update_option( "wcpay_multi_currency_price_charm_eur", "0" ); echo "readonly-preconditions-seeded";'

(
	cd "$STORE_DIR"
	pnpm exec wp-env run cli wp --user=1 eval "$SEED_CODE"
)
