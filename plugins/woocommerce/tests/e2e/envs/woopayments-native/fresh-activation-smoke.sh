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
readonly ABSENT_ACCOUNT_ASSERT_CODE='delete_option( "wcpay_account_data" ); delete_option( "woocommerce_woopayments_account_cache" ); $account = get_option( "wcpay_account_data", "__missing__" ); $legacy = get_option( "woocommerce_woopayments_account_cache", "__missing__" ); if ( "__missing__" !== $account || "__missing__" !== $legacy || false !== has_filter( "pre_option_wcpay_account_data" ) ) { fwrite( STDERR, "WooPayments account state and provider filter must be absent during fresh activation.\n" ); exit( 1 ); }'

cd "$PLUGIN_ROOT"

readonly source_dir='wp-content/plugins/woocommerce/tests/e2e/test-plugins/woopayments-native-runtime'
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli mkdir -p wp-content/mu-plugins
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli cp "$source_dir/woopayments-native-runtime.php" wp-content/mu-plugins/woopayments-native-runtime.php
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp config set E2E_WOOPAYMENTS_NATIVE true --raw
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE false --raw
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp plugin deactivate woocommerce
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp eval "$ABSENT_ACCOUNT_ASSERT_CODE"
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp plugin activate woocommerce
