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
COMMAND_LOGS=()

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

mkdir -p "$CLIENT_STORE" "$NATIVE_STORE"

# Every artifact of a scenario is derived from its slug, so a new case names a
# runtime, a slug, and only the fake-behavior flags it actually varies.
run_setup() {
	local runtime="$1"
	local slug="$2"
	shift 2

	local command_log="$TEST_ROOT/$slug-commands.log"
	local -a setup_env=(
		PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH"
		E2E_FAKE_COMMAND_LOG="$command_log"
		E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/$slug-diagnostics"
	)

	if [[ -n "$runtime" ]]; then
		setup_env+=( WCPAY_RUNTIME="$runtime" )
	fi

	case "$runtime" in
		client)
			setup_env+=(
				E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE"
				E2E_WOOPAYMENTS_CLIENT_STORE_URL='http://client.test:8082'
			)
			;;
		native)
			setup_env+=(
				E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE"
				E2E_WOOPAYMENTS_NATIVE_STORE_URL='http://native.test:8889'
			)
			;;
	esac

	local status=0
	env "${setup_env[@]}" "$@" "$SCRIPT_PATH/env-setup.sh" \
		> "$TEST_ROOT/$slug-stdout" 2> "$TEST_ROOT/$slug-stderr" || status=$?

	# The earliest failures exit before the fakes log anything, so only collect
	# logs that exist for the read-only sweep at the end.
	if [[ -e "$command_log" ]]; then
		COMMAND_LOGS+=( "$command_log" )
	fi

	return "$status"
}

fail() {
	echo "$1" >&2
	exit 1
}

expect_readiness_failure() {
	local runtime="$1"
	local slug="$2"
	local message="$3"
	shift 3

	if run_setup "$runtime" "$slug" "$@"; then
		fail "$message"
	fi
}

assert_stderr_contains() {
	grep -q "$2" "$TEST_ROOT/$1-stderr"
}

assert_failed_before_callback_proof() {
	if grep -Fq 'wcpay callback probe' "$TEST_ROOT/$1-commands.log"; then
		fail "$2"
	fi
}

assert_no_environment_repair() {
	if grep -Eq 'theme activate|config set|config delete|reset|restart|destroy|start --update' "$@"; then
		fail 'Readiness must not repair or restart a shared environment.'
	fi
}

expect_readiness_failure '' 'missing-runtime' \
	'Readiness must require WCPAY_RUNTIME=client|native.'
assert_stderr_contains 'missing-runtime' 'WCPAY_RUNTIME is required'

expect_readiness_failure 'unsupported' 'invalid-runtime' \
	'Readiness must reject an unsupported WCPAY_RUNTIME.'
assert_stderr_contains 'invalid-runtime' 'WCPAY_RUNTIME must be client or native.'

expect_readiness_failure 'client' 'client-disabled' \
	'A disabled client runtime must fail readiness.' \
	E2E_FAKE_CLIENT_DISABLED=1
assert_failed_before_callback_proof 'client-disabled' \
	'A disabled client runtime must fail before callback proof.'
assert_stderr_contains 'client-disabled' \
	'client readiness requires runtime_owner=plugin and native_enabled=false'

CLIENT_DIAGNOSTICS="$TEST_ROOT/client-diagnostics"
CLIENT_COMMAND_LOG="$TEST_ROOT/client-commands.log"
run_setup 'client' 'client'

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
	fail 'Client readiness must not collect native diagnostics.'
fi
if grep -Fq "$NATIVE_STORE" "$CLIENT_COMMAND_LOG"; then
	fail 'Client readiness must not probe the native store.'
fi
if grep -Eq 'wc-native-payments status|/wc-native-payments-e2e/v1/status|config (get|set|delete) E2E_WOOPAYMENTS_NATIVE' "$CLIENT_COMMAND_LOG"; then
	fail 'Client readiness must not invoke native commands.'
fi
if ! grep -Fq "$CLIENT_STORE	docker compose exec -T -u www-data wordpress wp --user=1" "$CLIENT_COMMAND_LOG"; then
	fail 'Client readiness must use the repository Docker WP-CLI path.'
fi
if grep -F "$CLIENT_STORE	" "$CLIENT_COMMAND_LOG" | grep -Fq 'pnpm '; then
	fail 'Client readiness must not invoke pnpm under an incompatible host Node runtime.'
fi
if ! grep -F "$CLIENT_STORE	" "$CLIENT_COMMAND_LOG" | grep -Fq '"runtime_owner"'; then
	fail 'Client readiness must collect normalized plugin-owned runtime status.'
fi
if ! grep -F "$CLIENT_STORE	" "$CLIENT_COMMAND_LOG" | grep -Fq 'get_account_service()->get_cached_account_data()'; then
	fail 'Client readiness must use the WooPayments account service.'
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
run_setup 'native' 'native'

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
	fail 'Native readiness must not collect client diagnostics.'
fi
if grep -Fq "$CLIENT_STORE" "$NATIVE_COMMAND_LOG"; then
	fail 'Native readiness must not probe the client store.'
fi
if grep -Fq 'docker compose' "$NATIVE_COMMAND_LOG"; then
	fail 'Native readiness must not invoke client commands.'
fi
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp --user=1 wc-native-payments status" "$NATIVE_COMMAND_LOG"; then
	fail 'Native readiness must invoke WP-CLI through the native wp-env.'
fi
if ! grep -F "$NATIVE_STORE	" "$NATIVE_COMMAND_LOG" | grep -Fq '/wc-native-payments-e2e/v1/status'; then
	fail 'Native readiness must collect the authenticated native runtime status route.'
fi
if grep -Eq 'config (get|set|delete) E2E_WOOPAYMENTS_NATIVE' "$NATIVE_COMMAND_LOG"; then
	fail 'Native readiness must not mutate the native runtime constant.'
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

expect_readiness_failure 'native' 'account-error' \
	'A REST account error must fail readiness.' \
	E2E_FAKE_ACCOUNT_ERROR=1
assert_failed_before_callback_proof 'account-error' \
	'A REST account error must fail before callback proof.'
assert_stderr_contains 'account-error' 'account readiness is not a matching test-mode account'

expect_readiness_failure 'native' 'non-test-account' \
	'A non-test account must fail readiness.' \
	E2E_FAKE_ACCOUNT_NOT_TEST_MODE=1
assert_failed_before_callback_proof 'non-test-account' \
	'A non-test account must fail before callback proof.'
assert_stderr_contains 'non-test-account' 'account readiness is not a matching test-mode account'

expect_readiness_failure 'native' 'account-mismatch' \
	'A runtime/account identity mismatch must fail readiness.' \
	E2E_FAKE_ACCOUNT_ID_MISMATCH=1
assert_failed_before_callback_proof 'account-mismatch' \
	'A runtime/account identity mismatch must fail before callback proof.'
assert_stderr_contains 'account-mismatch' 'account readiness is not a matching test-mode account'

expect_readiness_failure 'native' 'theme' \
	'A missing active theme must fail readiness without repair.' \
	E2E_FAKE_NATIVE_THEME_MISSING=1
assert_no_environment_repair "$TEST_ROOT/theme-commands.log"
assert_stderr_contains 'theme' 'WooPayments native store has no renderable active theme.'

expect_readiness_failure 'native' 'disabled' \
	'A disabled native runtime must fail readiness without mutation.' \
	E2E_FAKE_NATIVE_DISABLED=1
if grep -Eq 'config (set|delete) E2E_WOOPAYMENTS_NATIVE' "$TEST_ROOT/disabled-commands.log"; then
	fail 'Readiness must not set E2E_WOOPAYMENTS_NATIVE.'
fi
assert_stderr_contains 'disabled' 'runtime_owner=native and native_enabled=true'

expect_readiness_failure 'native' 'callback' \
	'A failed native callback probe must fail closed.' \
	E2E_FAKE_NATIVE_CALLBACK_FAIL=1
if jq -e '.callback_probe.reachable == true' "$TEST_ROOT/callback-diagnostics/native/runtime-status.json" > /dev/null; then
	fail 'A failed native callback probe must not leave reachable readiness evidence.'
fi

if [[ ${#COMMAND_LOGS[@]} -eq 0 ]]; then
	fail 'No readiness command logs were captured.'
fi
if grep -Eq 'theme activate|config set|config delete|reset|restart|destroy|start --update' "${COMMAND_LOGS[@]}"; then
	fail 'Readiness must remain read-only in every runtime-scoped case.'
fi

echo 'env-setup.sh tests passed.'
