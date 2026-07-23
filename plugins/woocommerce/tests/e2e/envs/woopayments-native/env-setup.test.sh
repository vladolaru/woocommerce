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
test "$(wc -l < "$COMMAND_LOG" | tr -d ' ')" = '10'

for store in client native; do
	test -s "$DIAGNOSTICS/$store/wpcom-env-status.json"
	test -s "$DIAGNOSTICS/$store/wpcom-transact-status.json"
	test -s "$DIAGNOSTICS/$store/wpcom-store-doctor.json"
	test -s "$DIAGNOSTICS/$store/native-payments-status.txt"
	test -s "$DIAGNOSTICS/$store/account.json"
done

grep -q "^$CLIENT_STORE" "$COMMAND_LOG"
grep -q "^$NATIVE_STORE" "$COMMAND_LOG"
if grep -Eq 'config set|reset|transact-platform-server' "$COMMAND_LOG"; then
	echo 'env-setup.sh executed a forbidden mutating command.' >&2
	exit 1
fi

echo 'env-setup.sh tests passed.'
