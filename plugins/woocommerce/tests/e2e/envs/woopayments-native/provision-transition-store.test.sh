#!/usr/bin/env bash

set -euo pipefail

SCRIPT_PATH="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
PROVISION_SCRIPT="$SCRIPT_PATH/provision-transition-store.sh"
FIXTURES="$SCRIPT_PATH/test-fixtures"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-native-transition-test.XXXXXX")"
TEST_ROOT="$(
	cd "$TEST_ROOT"
	pwd -P
)"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

expect_failure() {
	if "$@" > "$TEST_ROOT/unexpected-output" 2> "$TEST_ROOT/expected-error"; then
		echo "Expected command to fail: $*" >&2
		exit 1
	fi
}

expect_log_absent() {
	local pattern="$1"
	local log_path="$2"
	if grep -q "$pattern" "$log_path"; then
		echo "Unexpected provisioner log entry: $pattern" >&2
		exit 1
	fi
}

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='missing-seed' \
	"$PROVISION_SCRIPT" create

allocation="$(
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID='run-safe' \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
		E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
		"$PROVISION_SCRIPT" create
)"

node -e '
	const allocation = JSON.parse( process.argv[ 1 ] );
	if ( allocation.base_url !== "http://transition.localhost:8899" ) process.exit( 1 );
	if ( allocation.store_id !== "transition-store-test" ) process.exit( 1 );
	if ( allocation.plugin_version !== "10.5.0" ) process.exit( 1 );
	if ( ! /^[a-f0-9]{64}$/.test( allocation.seed_hash ) ) process.exit( 1 );
	if ( ! /^[a-f0-9]{64}$/.test( allocation.teardown_token ) ) process.exit( 1 );
	if ( ! allocation.allocation_path.endsWith( "/allocation.json" ) ) process.exit( 1 );
	if ( "rollback_receipt" in allocation ) process.exit( 1 );
' "$allocation"
receipt_path="$TEST_ROOT/woopayments-native-transition-run-safe/rollback-receipt"
test -f "$receipt_path"
node -e '
	const { lstatSync } = require( "node:fs" );
	const stat = lstatSync( process.argv[ 1 ] );
	if ( ! stat.isFile() || stat.isSymbolicLink() ) process.exit( 1 );
	if ( ( stat.mode & 0o777 ) !== 0o600 ) process.exit( 1 );
' "$receipt_path"
if grep -Eq 'transition-store-test|transition\.localhost' "$receipt_path"; then
	echo 'Rollback receipt must remain opaque.' >&2
	exit 1
fi

tampered="$(
	node -e '
		const allocation = JSON.parse( process.argv[ 1 ] );
		allocation.teardown_token = "0".repeat( 64 );
		process.stdout.write( JSON.stringify( allocation ) );
	' "$allocation"
)"
expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_PROVISIONER_LOG="$TEST_ROOT/provisioner.log" \
	"$PROVISION_SCRIPT" destroy --allocation "$tampered"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='standing-port' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_TRANSITION_BASE_URL='http://localhost:8082' \
	"$PROVISION_SCRIPT" create

rollback_log="$TEST_ROOT/rollback.log"
expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='post-create-mismatch' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_CREATED_STORE_ID='unexpected-created-store' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -Fqx \
	"destroy actual-store-id=unexpected-created-store actual-base-url=http://transition.localhost:8899 receipt-file=$TEST_ROOT/woopayments-native-transition-post-create-mismatch/rollback-receipt" \
	"$rollback_log"
test ! -e "$TEST_ROOT/woopayments-native-transition-post-create-mismatch"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='partial-create-failure' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_CREATE_PARTIAL_FAILURE='1' \
	E2E_FAKE_CREATED_STORE_ID='partial-created-store' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -Fqx \
	"destroy actual-store-id=partial-created-store actual-base-url=http://transition.localhost:8899 receipt-file=$TEST_ROOT/woopayments-native-transition-partial-create-failure/rollback-receipt" \
	"$rollback_log"
test ! -e "$TEST_ROOT/woopayments-native-transition-partial-create-failure"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='missing-rollback-receipt' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_CREATED_STORE_ID='missing-receipt-store' \
	E2E_FAKE_RECEIPT_MODE='missing' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -q 'valid rollback receipt' "$TEST_ROOT/expected-error"
grep -q 'cleanup is impossible' "$TEST_ROOT/expected-error"
test -d "$TEST_ROOT/woopayments-native-transition-missing-rollback-receipt"
expect_log_absent 'actual-store-id=missing-receipt-store' "$rollback_log"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='invalid-rollback-receipt' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_CREATED_STORE_ID='invalid-receipt-store' \
	E2E_FAKE_RECEIPT_MODE='invalid' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -q 'valid rollback receipt' "$TEST_ROOT/expected-error"
grep -q 'cleanup is impossible' "$TEST_ROOT/expected-error"
test -d "$TEST_ROOT/woopayments-native-transition-invalid-rollback-receipt"
expect_log_absent 'actual-store-id=invalid-receipt-store' "$rollback_log"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='allocation-write-failure' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_BLOCK_ALLOCATION_WRITE='1' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -Fqx \
	"destroy actual-store-id=transition-store-test actual-base-url=http://transition.localhost:8899 receipt-file=$TEST_ROOT/woopayments-native-transition-allocation-write-failure/rollback-receipt" \
	"$rollback_log"
test ! -e "$TEST_ROOT/woopayments-native-transition-allocation-write-failure"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='rollback-cleanup-failure' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_CREATED_STORE_ID='unexpected-created-store' \
	E2E_FAKE_DESTROY_FAILURE='1' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -q 'does not exactly match its validated plan' "$TEST_ROOT/expected-error"
grep -q 'Transition rollback cleanup failed' "$TEST_ROOT/expected-error"
test -d "$TEST_ROOT/woopayments-native-transition-rollback-cleanup-failure"

env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_PROVISIONER_LOG="$TEST_ROOT/provisioner.log" \
	"$PROVISION_SCRIPT" destroy --allocation "$allocation"

test ! -d "$TEST_ROOT/woopayments-native-transition-run-safe"
grep -Fqx \
	"destroy actual-store-id=transition-store-test actual-base-url=http://transition.localhost:8899 receipt-file=$receipt_path" \
	"$TEST_ROOT/provisioner.log"

echo 'provision-transition-store.sh tests passed.'
