#!/usr/bin/env bash

set -euo pipefail

readonly PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
readonly WP_ENV_PROFILE="${1:-}"

case "$WP_ENV_PROFILE" in
	default) config_args=() ;;
	e2e) config_args=( --config=.wp-env.e2e.json ) ;;
	*) echo 'Usage: install-ci-fixture.sh default|e2e' >&2; exit 2 ;;
esac

cd "$PLUGIN_ROOT"
readonly source_dir='wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native'
pnpm exec wp-env "${config_args[@]}" run cli mkdir -p wp-content/mu-plugins
pnpm exec wp-env "${config_args[@]}" run cli cp "$source_dir/ci-provider-fixture.php" wp-content/mu-plugins/ci-provider-fixture.php
pnpm exec wp-env "${config_args[@]}" run cli cp "$source_dir/stripe-messaging-adapter.js" wp-content/mu-plugins/stripe-messaging-adapter.js
if [[ "$WP_ENV_PROFILE" == 'e2e' ]]; then
	pnpm exec wp-env "${config_args[@]}" run cli cp "$source_dir/../../test-plugins/woopayments-native-runtime/woopayments-native-runtime.php" wp-content/mu-plugins/woopayments-native-runtime.php
fi
pnpm exec wp-env "${config_args[@]}" run cli wp config set E2E_WOOPAYMENTS_NATIVE true --raw
pnpm exec wp-env "${config_args[@]}" run cli wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE true --raw
