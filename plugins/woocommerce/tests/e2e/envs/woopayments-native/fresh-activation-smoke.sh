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

cd "$PLUGIN_ROOT"

pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp eval 'delete_option( "wcpay_account_data" ); delete_option( "woocommerce_woopayments_account_cache" );'
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp plugin deactivate woocommerce
pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp plugin activate woocommerce
