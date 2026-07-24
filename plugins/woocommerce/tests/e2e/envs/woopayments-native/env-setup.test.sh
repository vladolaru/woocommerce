#!/usr/bin/env bash

set -euo pipefail

SCRIPT_PATH="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-native-env-setup-test.XXXXXX")"
TEST_ROOT="$(
	cd "$TEST_ROOT"
	pwd -P
)"
CLIENT_STORE="$TEST_ROOT/client-store"
NATIVE_STORE="$TEST_ROOT/native-store"
DIAGNOSTICS="$TEST_ROOT/diagnostics"
COMMAND_LOG="$TEST_ROOT/commands.log"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

mkdir -p "$CLIENT_STORE" "$NATIVE_STORE"

env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$COMMAND_LOG" \
	E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE" \
	E2E_WOOPAYMENTS_CLIENT_STORE_URL='http://client.test:8082' \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$DIAGNOSTICS" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/stdout" 2> "$TEST_ROOT/stderr"

grep -q 'WooPayments callback readiness proved for client blog 1 and native blog 2.' "$TEST_ROOT/stdout"

for store in client native; do
	test -s "$DIAGNOSTICS/$store/wpcom-env-status.json"
	test -s "$DIAGNOSTICS/$store/wpcom-transact-status.json"
	test -s "$DIAGNOSTICS/$store/wpcom-store-doctor.json"
	test -s "$DIAGNOSTICS/$store/runtime-status.json"
	test -s "$DIAGNOSTICS/$store/account.json"
	test -s "$DIAGNOSTICS/$store/callback-probe.json"
	jq -e . "$DIAGNOSTICS/$store/wpcom-env-status.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/wpcom-transact-status.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/wpcom-store-doctor.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/runtime-status.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/account.json" > /dev/null
	jq -e '
		.status == "success" and
		.context.callback_registered == "true" and
		.context.callback_reachable == "true" and
		.context.callback_provider_write == "false"
	' "$DIAGNOSTICS/$store/callback-probe.json" > /dev/null
	jq -e '
		.callback_probe.registered == true and
		.callback_probe.reachable == true and
		.callback_probe.wpcom_blog_id > 0
	' "$DIAGNOSTICS/$store/runtime-status.json" > /dev/null
done
test -s "$DIAGNOSTICS/native/native-payments-status.txt"
if [[ -e "$DIAGNOSTICS/client/native-payments-status.txt" ]]; then
	echo 'Client diagnostics must not require a Core-native CLI artifact.' >&2
	exit 1
fi

grep -q "^$CLIENT_STORE" "$COMMAND_LOG"
grep -q "^$NATIVE_STORE" "$COMMAND_LOG"
if grep -F "$CLIENT_STORE	" "$COMMAND_LOG" | grep -F 'wc-native-payments status' > /dev/null; then
	echo 'Client diagnostics must not invoke the Core-native CLI.' >&2
	exit 1
fi
if ! grep -F "$CLIENT_STORE	" "$COMMAND_LOG" | grep -F '"runtime_owner"' > /dev/null; then
	echo 'Client diagnostics must collect normalized plugin-owned runtime status.' >&2
	exit 1
fi
if ! grep -F "$CLIENT_STORE	" "$COMMAND_LOG" | grep -F 'get_account_service()->get_cached_account_data()' > /dev/null; then
	echo 'Client diagnostics must use the WooPayments account service instead of reading the cache envelope directly.' >&2
	exit 1
fi
if ! grep -Fq "$CLIENT_STORE	docker compose exec -T -u www-data wordpress wp --user=1" "$COMMAND_LOG"; then
	echo 'Client diagnostics must use the repository Docker WP-CLI path without invoking the host pnpm engine gate.' >&2
	exit 1
fi
if grep -F "$CLIENT_STORE	" "$COMMAND_LOG" | grep -Fq 'pnpm '; then
	echo 'Client diagnostics must not invoke pnpm under an incompatible host Node runtime.' >&2
	exit 1
fi
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp --user=1 wc-native-payments status" "$COMMAND_LOG"; then
	echo 'Native diagnostics must invoke WP-CLI through the native wp-env.' >&2
	exit 1
fi
for store in "$CLIENT_STORE" "$NATIVE_STORE"; do
	if ! grep -F "$store	" "$COMMAND_LOG" | grep -Fq 'active_theme_exists'; then
		echo 'Setup must prove that each standing store has a renderable active theme.' >&2
		exit 1
	fi
done
if ! grep -F "$NATIVE_STORE	" "$COMMAND_LOG" | grep -F '/wc-native-payments-e2e/v1/status' > /dev/null; then
	echo 'Native diagnostics must collect the authenticated native runtime status route.' >&2
	exit 1
fi
if grep -F 'exit( 1 )' "$COMMAND_LOG" | grep -Fq '/wc/v3/payments/accounts'; then
	echo 'Account diagnostics must record REST errors without hiding the callback readiness gate.' >&2
	exit 1
fi
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp config set E2E_WOOPAYMENTS_NATIVE true --raw" "$COMMAND_LOG"; then
	echo 'Native activation must occur before its callback proof.' >&2
	exit 1
fi
client_probe_line="$(grep -n -F "$CLIENT_STORE	wpcom-local --json wcpay callback probe" "$COMMAND_LOG" | cut -d: -f1)"
activation_line="$(grep -n -F 'config set E2E_WOOPAYMENTS_NATIVE true --raw' "$COMMAND_LOG" | cut -d: -f1)"
native_probe_line="$(grep -n -F "$NATIVE_STORE	wpcom-local --json wcpay callback probe" "$COMMAND_LOG" | cut -d: -f1)"
if [[ -z "$client_probe_line" || -z "$activation_line" || -z "$native_probe_line" ]] ||
	(( client_probe_line >= activation_line || activation_line >= native_probe_line )); then
	echo 'Callback setup must prove client, activate native, then prove native.' >&2
	exit 1
fi
if grep -Eq 'reset|transact-platform-server' "$COMMAND_LOG"; then
	echo 'env-setup.sh executed a forbidden provider/reset command.' >&2
	exit 1
fi

ROLLBACK_LOG="$TEST_ROOT/rollback-commands.log"
ROLLBACK_DIAGNOSTICS="$TEST_ROOT/rollback-diagnostics"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$ROLLBACK_LOG" \
	E2E_FAKE_NATIVE_CALLBACK_FAIL=1 \
	E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE" \
	E2E_WOOPAYMENTS_CLIENT_STORE_URL='http://client.test:8082' \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$ROLLBACK_DIAGNOSTICS" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/rollback-stdout" 2> "$TEST_ROOT/rollback-stderr"; then
	echo 'A failed native callback probe must fail closed.' >&2
	exit 1
fi
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp config delete E2E_WOOPAYMENTS_NATIVE --yes" "$ROLLBACK_LOG"; then
	echo 'A failed native callback probe must restore an initially absent native constant.' >&2
	exit 1
fi
if jq -e '.callback_probe.reachable == true' "$ROLLBACK_DIAGNOSTICS/native/runtime-status.json" > /dev/null; then
	echo 'A failed native callback probe must not leave reachable readiness evidence.' >&2
	exit 1
fi

THEME_REPAIR_LOG="$TEST_ROOT/theme-repair-commands.log"
THEME_REPAIR_DIAGNOSTICS="$TEST_ROOT/theme-repair-diagnostics"
env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$THEME_REPAIR_LOG" \
	E2E_FAKE_NATIVE_THEME_MISSING=1 \
	E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE" \
	E2E_WOOPAYMENTS_CLIENT_STORE_URL='http://client.test:8082' \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$THEME_REPAIR_DIAGNOSTICS" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/theme-repair-stdout" 2> "$TEST_ROOT/theme-repair-stderr"
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp theme activate twentytwentyfive" "$THEME_REPAIR_LOG"; then
	echo 'Setup must repair a missing native active theme with an installed fallback.' >&2
	exit 1
fi

echo 'env-setup.sh tests passed.'
