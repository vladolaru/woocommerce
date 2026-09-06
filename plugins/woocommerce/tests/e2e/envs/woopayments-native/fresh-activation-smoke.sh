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

cd "$PLUGIN_ROOT"

npx wp-env run tests-cli wp eval 'delete_option( "wcpay_account_data" ); delete_option( "woocommerce_woopayments_account_cache" );'
npx wp-env run tests-cli wp plugin deactivate woocommerce
npx wp-env run tests-cli wp plugin activate woocommerce
