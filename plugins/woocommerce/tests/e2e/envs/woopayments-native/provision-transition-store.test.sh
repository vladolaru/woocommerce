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
' "$allocation"

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

env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_PROVISIONER_LOG="$TEST_ROOT/provisioner.log" \
	"$PROVISION_SCRIPT" destroy --allocation "$allocation"

test ! -d "$TEST_ROOT/woopayments-native-transition-run-safe"
grep -q '^destroy ' "$TEST_ROOT/provisioner.log"

echo 'provision-transition-store.sh tests passed.'
