#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-readonly-setup.XXXXXX")"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly ORIGINAL_PATH="$PATH"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/store"

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf 'pnpm %s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
[[ "$*" == *'wp-env --config .wp-env.e2e.json run cli wp --user=1 eval'* ]]
[[ "$*" == *'_wcpay_feature_customer_multi_currency'* ]]
[[ "$*" == *'wcpay_multi_currency_enabled_currencies'* ]]
[[ "$*" == *'express_checkout_checkout_methods'* ]]
grep -Fq '$settings["platform_checkout"] = "no"' <<< "$*"
grep -Fq '$settings["manual_capture"] = "no"' <<< "$*"
grep -Fq 'E2E_WOOPAYMENTS_NATIVE_FIXTURE ) { update_option( "woocommerce_coming_soon", "no" );' <<< "$*"
grep -Fq '$currency_cache_time = time();' <<< "$*"
grep -Fq 'update_option( "wcpay_multi_currency_cached_currencies", array( "data" => array( "currencies" => array(), "updated" => $currency_cache_time ), "fetched" => $currency_cache_time, "errored" => false, "consecutive_errors" => 0 ), false );' <<< "$*"
grep -Fq 'wp_cache_delete( "wcpay_multi_currency_cached_currencies", "options" );' <<< "$*"
[[ "$*" == *'upe_enabled_payment_method_ids'* ]]
[[ "$*" == *'"card", "klarna"'* ]]
[[ "$*" == *'WooCommerce_WooPayments_Native_CI_Provider_Fixture'* ]]
grep -Fq 'registered_instance()' <<< "$*"
grep -Fq 'remove_filter( "pre_option_wcpay_account_data", $account_cache_callback )' <<< "$*"
grep -Fq 'add_filter( "pre_option_wcpay_account_data", $account_cache_callback )' <<< "$*"
grep -Fq '$physical_account_cache = get_option( "wcpay_account_data", null )' <<< "$*"
grep -Fq 'finally' <<< "$*"
[[ "$*" == *'wcpay_account_data'* ]]
[[ "$*" == *'account_id'* ]]
[[ "$*" == *'readonly-preconditions-seeded'* ]]
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"

if PATH="$TEST_ROOT/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	WCPAY_RUNTIME=client \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$TEST_ROOT/store" \
	"$SCRIPT_DIR/seed-readonly.sh" > "$TEST_ROOT/client.out" 2>&1; then
	echo 'readonly setup must reject a non-native runtime' >&2
	exit 1
fi

PATH="$TEST_ROOT/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	WCPAY_RUNTIME=native \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$TEST_ROOT/store" \
	E2E_WOOPAYMENTS_WP_ENV_CONFIG='.wp-env.e2e.json' \
	"$SCRIPT_DIR/seed-readonly.sh"

PATH="$TEST_ROOT/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	WCPAY_RUNTIME=native \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$TEST_ROOT/store" \
	E2E_WOOPAYMENTS_WP_ENV_CONFIG='.wp-env.e2e.json' \
	"$SCRIPT_DIR/seed-readonly.sh"

test "$(wc -l < "$TEST_ROOT/commands.log" | tr -d ' ')" = '2'
grep -Fq 'WooCommerce_WooPayments_Native_CI_Provider_Fixture' "$TEST_ROOT/commands.log"
grep -Fq 'wcpay_account_data' "$TEST_ROOT/commands.log"
grep -Fq 'account_id' "$TEST_ROOT/commands.log"
test "$(grep -Fc 'wcpay_multi_currency_cached_currencies' "$TEST_ROOT/commands.log")" = '2'
test "$(sed 's/^pnpm //' "$TEST_ROOT/commands.log" | sort -u | wc -l | tr -d ' ')" = '1'

for project in \
	woopayments-native-provider \
	woopayments-native-transition \
	woopayments-native-extension-compat; do
	PATH="$ORIGINAL_PATH" WCPAY_RUNTIME=client BASE_URL='http://localhost:8086' \
		pnpm --dir "$PLUGIN_ROOT" exec playwright test \
		--config="$SCRIPT_DIR/playwright.config.ts" \
		--project="$project" --grep='NO_MATCH_GLOBAL_SETUP_PROBE' \
		--pass-with-no-tests \
		> "$TEST_ROOT/$project.out" 2>&1
done

echo 'seed-readonly.sh tests passed.'
