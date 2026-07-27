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

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

mkdir -p "$CLIENT_STORE" "$NATIVE_STORE"

if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/missing-runtime-commands.log" \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/missing-runtime-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/missing-runtime-stdout" 2> "$TEST_ROOT/missing-runtime-stderr"; then
	echo 'Readiness must require WCPAY_RUNTIME=client|native.' >&2
	exit 1
fi
grep -q 'WCPAY_RUNTIME is required' "$TEST_ROOT/missing-runtime-stderr"

if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='unsupported' \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/invalid-runtime-commands.log" \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/invalid-runtime-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/invalid-runtime-stdout" 2> "$TEST_ROOT/invalid-runtime-stderr"; then
	echo 'Readiness must reject an unsupported WCPAY_RUNTIME.' >&2
	exit 1
fi
grep -q 'WCPAY_RUNTIME must be client or native.' "$TEST_ROOT/invalid-runtime-stderr"

CLIENT_DISABLED_COMMAND_LOG="$TEST_ROOT/client-disabled-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='client' \
	E2E_FAKE_COMMAND_LOG="$CLIENT_DISABLED_COMMAND_LOG" \
	E2E_FAKE_CLIENT_DISABLED=1 \
	E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE" \
	E2E_WOOPAYMENTS_CLIENT_STORE_URL='http://client.test:8082' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/client-disabled-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/client-disabled-stdout" 2> "$TEST_ROOT/client-disabled-stderr"; then
	echo 'A disabled client runtime must fail readiness.' >&2
	exit 1
fi
if grep -Fq 'wcpay callback probe' "$CLIENT_DISABLED_COMMAND_LOG"; then
	echo 'A disabled client runtime must fail before callback proof.' >&2
	exit 1
fi
grep -q 'client readiness requires runtime_owner=plugin and native_enabled=false' "$TEST_ROOT/client-disabled-stderr"

CLIENT_DIAGNOSTICS="$TEST_ROOT/client-diagnostics"
CLIENT_COMMAND_LOG="$TEST_ROOT/client-commands.log"
env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='client' \
	E2E_FAKE_COMMAND_LOG="$CLIENT_COMMAND_LOG" \
	E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE" \
	E2E_WOOPAYMENTS_CLIENT_STORE_URL='http://client.test:8082' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$CLIENT_DIAGNOSTICS" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/client-stdout" 2> "$TEST_ROOT/client-stderr"

grep -q 'WooPayments callback readiness proved for client blog 1.' "$TEST_ROOT/client-stdout"
for artifact in \
	wpcom-env-status.json \
	wpcom-transact-status.json \
	wpcom-store-doctor.json \
	runtime-status.json \
	account.json \
	callback-probe.json; do
	test -s "$CLIENT_DIAGNOSTICS/client/$artifact"
	jq -e . "$CLIENT_DIAGNOSTICS/client/$artifact" > /dev/null
done
if [[ -e "$CLIENT_DIAGNOSTICS/native" ]]; then
	echo 'Client readiness must not collect native diagnostics.' >&2
	exit 1
fi
if grep -Fq "$NATIVE_STORE" "$CLIENT_COMMAND_LOG"; then
	echo 'Client readiness must not probe the native store.' >&2
	exit 1
fi
if grep -Eq 'wc-native-payments status|/wc-native-payments-e2e/v1/status|config (get|set|delete) E2E_WOOPAYMENTS_NATIVE' "$CLIENT_COMMAND_LOG"; then
	echo 'Client readiness must not invoke native commands.' >&2
	exit 1
fi
if ! grep -Fq "$CLIENT_STORE	docker compose exec -T -u www-data wordpress wp --user=1" "$CLIENT_COMMAND_LOG"; then
	echo 'Client readiness must use the repository Docker WP-CLI path.' >&2
	exit 1
fi
if grep -F "$CLIENT_STORE	" "$CLIENT_COMMAND_LOG" | grep -Fq 'pnpm '; then
	echo 'Client readiness must not invoke pnpm under an incompatible host Node runtime.' >&2
	exit 1
fi
if ! grep -F "$CLIENT_STORE	" "$CLIENT_COMMAND_LOG" | grep -Fq '"runtime_owner"'; then
	echo 'Client readiness must collect normalized plugin-owned runtime status.' >&2
	exit 1
fi
if ! grep -F "$CLIENT_STORE	" "$CLIENT_COMMAND_LOG" | grep -Fq 'get_account_service()->get_cached_account_data()'; then
	echo 'Client readiness must use the WooPayments account service.' >&2
	exit 1
fi
jq -e '
	.runtime_owner == "plugin" and
	.native_enabled == false and
	.wpcom_blog_id == 1 and
	.account_connected == true and
	.callback_probe.registered == true and
	.callback_probe.reachable == true and
	.callback_probe.wpcom_blog_id == 1
' "$CLIENT_DIAGNOSTICS/client/runtime-status.json" > /dev/null
jq -e '
	.status == "success" and
	.context.store_url == "http://client.test:8082" and
	.context.wpcom_blog_id == "1" and
	.context.callback_registered == "true" and
	.context.callback_reachable == "true" and
	.context.callback_provider_write == "false"
' "$CLIENT_DIAGNOSTICS/client/callback-probe.json" > /dev/null
jq -e '
	.status == 200 and
	.is_error == false and
	.account_id == "acct_client" and
	.test_mode == true
' "$CLIENT_DIAGNOSTICS/client/account.json" > /dev/null

NATIVE_DIAGNOSTICS="$TEST_ROOT/native-diagnostics"
NATIVE_COMMAND_LOG="$TEST_ROOT/native-commands.log"
env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$NATIVE_COMMAND_LOG" \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$NATIVE_DIAGNOSTICS" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/native-stdout" 2> "$TEST_ROOT/native-stderr"

grep -q 'WooPayments callback readiness proved for native blog 2.' "$TEST_ROOT/native-stdout"
for artifact in \
	wpcom-env-status.json \
	wpcom-transact-status.json \
	wpcom-store-doctor.json \
	runtime-status.json \
	account.json \
	callback-probe.json \
	native-payments-status.txt; do
	test -s "$NATIVE_DIAGNOSTICS/native/$artifact"
done
for artifact in \
	wpcom-env-status.json \
	wpcom-transact-status.json \
	wpcom-store-doctor.json \
	runtime-status.json \
	account.json \
	callback-probe.json; do
	jq -e . "$NATIVE_DIAGNOSTICS/native/$artifact" > /dev/null
done
if [[ -e "$NATIVE_DIAGNOSTICS/client" ]]; then
	echo 'Native readiness must not collect client diagnostics.' >&2
	exit 1
fi
if grep -Fq "$CLIENT_STORE" "$NATIVE_COMMAND_LOG"; then
	echo 'Native readiness must not probe the client store.' >&2
	exit 1
fi
if grep -Fq 'docker compose' "$NATIVE_COMMAND_LOG"; then
	echo 'Native readiness must not invoke client commands.' >&2
	exit 1
fi
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp --user=1 wc-native-payments status" "$NATIVE_COMMAND_LOG"; then
	echo 'Native readiness must invoke WP-CLI through the native wp-env.' >&2
	exit 1
fi
if ! grep -F "$NATIVE_STORE	" "$NATIVE_COMMAND_LOG" | grep -Fq '/wc-native-payments-e2e/v1/status'; then
	echo 'Native readiness must collect the authenticated native runtime status route.' >&2
	exit 1
fi
if grep -Eq 'config (get|set|delete) E2E_WOOPAYMENTS_NATIVE' "$NATIVE_COMMAND_LOG"; then
	echo 'Native readiness must not mutate the native runtime constant.' >&2
	exit 1
fi
jq -e '
	.runtime_owner == "native" and
	.native_enabled == true and
	.wpcom_blog_id == 2 and
	.account_connected == true and
	.callback_probe.registered == true and
	.callback_probe.reachable == true and
	.callback_probe.wpcom_blog_id == 2
' "$NATIVE_DIAGNOSTICS/native/runtime-status.json" > /dev/null
jq -e '
	.status == "success" and
	.context.store_url == "http://native.test:8889" and
	.context.wpcom_blog_id == "2" and
	.context.callback_registered == "true" and
	.context.callback_reachable == "true" and
	.context.callback_provider_write == "false"
' "$NATIVE_DIAGNOSTICS/native/callback-probe.json" > /dev/null
jq -e '
	.status == 200 and
	.is_error == false and
	.account_id == "acct_native" and
	.test_mode == true
' "$NATIVE_DIAGNOSTICS/native/account.json" > /dev/null

ACCOUNT_ERROR_COMMAND_LOG="$TEST_ROOT/account-error-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$ACCOUNT_ERROR_COMMAND_LOG" \
	E2E_FAKE_ACCOUNT_ERROR=1 \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/account-error-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/account-error-stdout" 2> "$TEST_ROOT/account-error-stderr"; then
	echo 'A REST account error must fail readiness.' >&2
	exit 1
fi
if grep -Fq 'wcpay callback probe' "$ACCOUNT_ERROR_COMMAND_LOG"; then
	echo 'A REST account error must fail before callback proof.' >&2
	exit 1
fi
grep -q 'account readiness is not a matching test-mode account' "$TEST_ROOT/account-error-stderr"

NON_TEST_ACCOUNT_COMMAND_LOG="$TEST_ROOT/non-test-account-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$NON_TEST_ACCOUNT_COMMAND_LOG" \
	E2E_FAKE_ACCOUNT_NOT_TEST_MODE=1 \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/non-test-account-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/non-test-account-stdout" 2> "$TEST_ROOT/non-test-account-stderr"; then
	echo 'A non-test account must fail readiness.' >&2
	exit 1
fi
if grep -Fq 'wcpay callback probe' "$NON_TEST_ACCOUNT_COMMAND_LOG"; then
	echo 'A non-test account must fail before callback proof.' >&2
	exit 1
fi
grep -q 'account readiness is not a matching test-mode account' "$TEST_ROOT/non-test-account-stderr"

ACCOUNT_MISMATCH_COMMAND_LOG="$TEST_ROOT/account-mismatch-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$ACCOUNT_MISMATCH_COMMAND_LOG" \
	E2E_FAKE_ACCOUNT_ID_MISMATCH=1 \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/account-mismatch-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/account-mismatch-stdout" 2> "$TEST_ROOT/account-mismatch-stderr"; then
	echo 'A runtime/account identity mismatch must fail readiness.' >&2
	exit 1
fi
if grep -Fq 'wcpay callback probe' "$ACCOUNT_MISMATCH_COMMAND_LOG"; then
	echo 'A runtime/account identity mismatch must fail before callback proof.' >&2
	exit 1
fi
grep -q 'account readiness is not a matching test-mode account' "$TEST_ROOT/account-mismatch-stderr"

THEME_COMMAND_LOG="$TEST_ROOT/theme-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$THEME_COMMAND_LOG" \
	E2E_FAKE_NATIVE_THEME_MISSING=1 \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/theme-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/theme-stdout" 2> "$TEST_ROOT/theme-stderr"; then
	echo 'A missing active theme must fail readiness without repair.' >&2
	exit 1
fi
if grep -Eq 'theme activate|config set|config delete|reset|restart|destroy|start --update' "$THEME_COMMAND_LOG"; then
	echo 'Readiness must not repair or restart a shared environment.' >&2
	exit 1
fi
grep -q 'WooPayments native store has no renderable active theme.' "$TEST_ROOT/theme-stderr"

DISABLED_COMMAND_LOG="$TEST_ROOT/disabled-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$DISABLED_COMMAND_LOG" \
	E2E_FAKE_NATIVE_DISABLED=1 \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/disabled-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/disabled-stdout" 2> "$TEST_ROOT/disabled-stderr"; then
	echo 'A disabled native runtime must fail readiness without mutation.' >&2
	exit 1
fi
if grep -Eq 'config (set|delete) E2E_WOOPAYMENTS_NATIVE' "$DISABLED_COMMAND_LOG"; then
	echo 'Readiness must not set E2E_WOOPAYMENTS_NATIVE.' >&2
	exit 1
fi
grep -q 'runtime_owner=native and native_enabled=true' "$TEST_ROOT/disabled-stderr"

CALLBACK_COMMAND_LOG="$TEST_ROOT/callback-commands.log"
if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	WCPAY_RUNTIME='native' \
	E2E_FAKE_COMMAND_LOG="$CALLBACK_COMMAND_LOG" \
	E2E_FAKE_NATIVE_CALLBACK_FAIL=1 \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889' \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/callback-diagnostics" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/callback-stdout" 2> "$TEST_ROOT/callback-stderr"; then
	echo 'A failed native callback probe must fail closed.' >&2
	exit 1
fi
if jq -e '.callback_probe.reachable == true' "$TEST_ROOT/callback-diagnostics/native/runtime-status.json" > /dev/null; then
	echo 'A failed native callback probe must not leave reachable readiness evidence.' >&2
	exit 1
fi

if grep -Eq 'theme activate|config set|config delete|reset|restart|destroy|start --update' \
	"$CLIENT_COMMAND_LOG" \
	"$NATIVE_COMMAND_LOG" \
	"$CLIENT_DISABLED_COMMAND_LOG" \
	"$ACCOUNT_ERROR_COMMAND_LOG" \
	"$NON_TEST_ACCOUNT_COMMAND_LOG" \
	"$ACCOUNT_MISMATCH_COMMAND_LOG" \
	"$THEME_COMMAND_LOG" \
	"$DISABLED_COMMAND_LOG" \
	"$CALLBACK_COMMAND_LOG"; then
	echo 'Readiness must remain read-only in every runtime-scoped case.' >&2
	exit 1
fi

echo 'env-setup.sh tests passed.'
