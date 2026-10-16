#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
readonly PLUGIN_ROOT="$(
	cd "$SCRIPT_DIR/../../../.."
	pwd -P
)"
readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_WP_ENV_CONFIG:-.wp-env.json}"
readonly INITIALIZE_FIXTURE_STATE_CODE='if ( class_exists( "WooCommerce_WooPayments_Native_CI_Provider_Fixture" ) ) { WooCommerce_WooPayments_Native_CI_Provider_Fixture::initialize_fixture_state_for_install(); }'
readonly ABSENT_ACCOUNT_ASSERT_CODE='delete_option( "wcpay_account_data" ); delete_option( "woocommerce_woopayments_account_cache" ); $account = get_option( "wcpay_account_data", "__missing__" ); $legacy = get_option( "woocommerce_woopayments_account_cache", "__missing__" ); if ( "__missing__" !== $account || "__missing__" !== $legacy || false !== has_filter( "pre_option_wcpay_account_data" ) ) { fwrite( STDERR, "WooPayments account state and provider filter must be absent during fresh activation.\n" ); exit( 1 ); }'
readonly -a WP_ENV=( pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli )

install_file() {
	"${WP_ENV[@]}" sh -c 'source="$1"; target="$2"; if [ "$source" -ef "$target" ] || cmp -s "$source" "$target"; then exit 0; fi; cp -- "$source" "$target"' sh "$1" "$2"
}

cd "$PLUGIN_ROOT"

readonly source_dir='wp-content/plugins/woocommerce/tests/e2e/test-plugins/woopayments-native-runtime'
"${WP_ENV[@]}" mkdir -p wp-content/mu-plugins
install_file "$source_dir/woopayments-native-runtime.php" wp-content/mu-plugins/woopayments-native-runtime.php
"${WP_ENV[@]}" wp eval "$INITIALIZE_FIXTURE_STATE_CODE"
"${WP_ENV[@]}" wp config set E2E_WOOPAYMENTS_NATIVE true --raw
"${WP_ENV[@]}" wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE false --raw
"${WP_ENV[@]}" wp plugin deactivate woocommerce
"${WP_ENV[@]}" wp eval "$ABSENT_ACCOUNT_ASSERT_CODE"
"${WP_ENV[@]}" wp plugin activate woocommerce
