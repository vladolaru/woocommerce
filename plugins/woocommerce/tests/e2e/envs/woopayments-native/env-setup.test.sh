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

if env \
	PATH="$SCRIPT_PATH/test-fixtures/bin:$PATH" \
	E2E_FAKE_COMMAND_LOG="$COMMAND_LOG" \
	E2E_WOOPAYMENTS_CLIENT_STORE_DIR="$CLIENT_STORE" \
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$NATIVE_STORE" \
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$DIAGNOSTICS" \
	"$SCRIPT_PATH/env-setup.sh" > "$TEST_ROOT/stdout" 2> "$TEST_ROOT/stderr"; then
	echo 'env-setup.sh must fail closed without an approved callback probe.' >&2
	exit 1
fi

grep -q 'owner-approved signed callback/registration probe interface is absent' "$TEST_ROOT/stderr"
if [[ "$(wc -l < "$COMMAND_LOG" | tr -d ' ')" != '11' ]]; then
	echo 'Split client/native diagnostics must execute exactly eleven read-only commands.' >&2
	exit 1
fi

for store in client native; do
	test -s "$DIAGNOSTICS/$store/wpcom-env-status.json"
	test -s "$DIAGNOSTICS/$store/wpcom-transact-status.json"
	test -s "$DIAGNOSTICS/$store/wpcom-store-doctor.json"
	test -s "$DIAGNOSTICS/$store/runtime-status.json"
	test -s "$DIAGNOSTICS/$store/account.json"
	jq -e . "$DIAGNOSTICS/$store/wpcom-env-status.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/wpcom-transact-status.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/wpcom-store-doctor.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/runtime-status.json" > /dev/null
	jq -e . "$DIAGNOSTICS/$store/account.json" > /dev/null
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
if ! grep -Fq "$NATIVE_STORE	pnpm exec wp-env run cli wp --user=1 wc-native-payments status" "$COMMAND_LOG"; then
	echo 'Native diagnostics must invoke WP-CLI through the native wp-env.' >&2
	exit 1
fi
if ! grep -F "$NATIVE_STORE	" "$COMMAND_LOG" | grep -F '/wc-native-payments-e2e/v1/status' > /dev/null; then
	echo 'Native diagnostics must collect the authenticated native runtime status route.' >&2
	exit 1
fi
if grep -F 'exit( 1 )' "$COMMAND_LOG" | grep -Fq '/wc/v3/payments/accounts'; then
	echo 'Account diagnostics must record REST errors without hiding the callback readiness gate.' >&2
	exit 1
fi
if grep -Eq 'config set|reset|transact-platform-server' "$COMMAND_LOG"; then
	echo 'env-setup.sh executed a forbidden mutating command.' >&2
	exit 1
fi

echo 'env-setup.sh tests passed.'
