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
SEED_MANIFEST="$TEST_ROOT/transition-seed.json"
printf '{}\n' > "$SEED_MANIFEST"
PROFILE_LOG="$TEST_ROOT/profile.log"
PROFILE_PROVISIONER="$TEST_ROOT/profile-transition-provisioner.sh"
cat > "$PROFILE_PROVISIONER" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == 'plan' || "${1:-}" == 'create' ]]; then
	printf '%s %s\n' "$1" "${E2E_TRANSITION_SEED_PROFILE:-missing}" >> "${E2E_FAKE_PROFILE_LOG:?}"
fi
exec "${E2E_FAKE_PROFILE_TARGET:?}" "$@"
SH
chmod 0700 "$PROFILE_PROVISIONER"

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

expected_run_id_error='E2E_TRANSITION_RUN_ID must be a bounded lowercase DNS label (1-48 lowercase letters, numbers, or hyphens).'
for invalid_run_id in \
	'Run-safe' \
	'run.safe' \
	'run_safe' \
	'-run-safe' \
	'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; do
	expect_failure env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID="$invalid_run_id" \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
		E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
		"$PROVISION_SCRIPT" create
	if ! diff -u \
		<(printf '%s\n' "$expected_run_id_error") \
		"$TEST_ROOT/expected-error"; then
		echo "Unexpected validation error for transition run ID: $invalid_run_id" >&2
		exit 1
	fi
	if [[ -e "$TEST_ROOT/woopayments-native-transition-$invalid_run_id" ]]; then
		echo "Invalid transition run ID left a workspace behind: $invalid_run_id" >&2
		exit 1
	fi
done

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='missing-seed' \
	"$PROVISION_SCRIPT" create

# Dropping either explicit input must fail before even calling the provisioner.
for missing_wcs_input in archive manifest; do
	expect_failure env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID="wcs-missing-$missing_wcs_input" \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
		E2E_TRANSITION_WCS_ARCHIVE="$( [[ "$missing_wcs_input" == archive ]] || printf '%s' "$SEED_MANIFEST" )" \
		E2E_TRANSITION_WCS_MANIFEST="$( [[ "$missing_wcs_input" == manifest ]] || printf '%s' "$SEED_MANIFEST" )" \
		E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
		"$PROVISION_SCRIPT" create
	grep -Fq 'WCS archive and manifest must be supplied together' "$TEST_ROOT/expected-error"
	test ! -e "$TEST_ROOT/woopayments-native-transition-wcs-missing-$missing_wcs_input"
done

allocation="$(
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID='run-safe' \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
		E2E_TRANSITION_STORE_PROVISIONER="$PROFILE_PROVISIONER" \
		E2E_FAKE_PROFILE_LOG="$PROFILE_LOG" \
		E2E_FAKE_PROFILE_TARGET="$FIXTURES/fake-transition-provisioner.sh" \
		"$PROVISION_SCRIPT" create
)"

node -e '
	const allocation = JSON.parse( process.argv[ 1 ] );
	if ( allocation.base_url !== "http://transition.localhost:8899" ) process.exit( 1 );
	if ( allocation.store_id !== "transition-store-test" ) process.exit( 1 );
	if ( allocation.plugin_version !== "10.5.0" ) process.exit( 1 );
	if ( allocation.wpcom_blog_id !== 77 ) process.exit( 1 );
	if ( allocation.account_id !== "acct_transition_test" ) process.exit( 1 );
	if ( ! /^[a-f0-9]{64}$/.test( allocation.seed_hash ) ) process.exit( 1 );
	if ( ! /^[a-f0-9]{64}$/.test( allocation.teardown_token ) ) process.exit( 1 );
	if ( ! allocation.allocation_path.endsWith( "/allocation.json" ) ) process.exit( 1 );
	if ( "rollback_receipt" in allocation ) process.exit( 1 );
	if ( "optional_artifacts" in allocation ) process.exit( 1 );
	if ( Object.keys( allocation ).sort().join( "," ) !== "account_id,allocation_path,base_url,plugin_version,run_id,seed_hash,store_id,teardown_token,workspace,wpcom_blog_id" ) process.exit( 1 );
' "$allocation"
test "$(< "$PROFILE_LOG")" = $'plan 10.5.0\ncreate 10.5.0'
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

profile_11_log="$TEST_ROOT/profile-11.log"
profile_11_allocation="$(
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID='run-profile-11' \
		E2E_TRANSITION_SEED_PROFILE='11.1.0' \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
		E2E_TRANSITION_STORE_PROVISIONER="$PROFILE_PROVISIONER" \
		E2E_FAKE_PROFILE_LOG="$profile_11_log" \
		E2E_FAKE_PROFILE_TARGET="$FIXTURES/fake-transition-provisioner.sh" \
		E2E_FAKE_TRANSITION_PLUGIN_VERSION='11.1.0' \
		"$PROVISION_SCRIPT" create
)"
profile_11_allocation_path="$TEST_ROOT/woopayments-native-transition-run-profile-11/allocation.json"
profile_11_seed_hash="$(shasum -a 256 "$FIXTURES/transition-seed.tar.gz" | awk '{ print $1 }')"
node -e '
	const { readFileSync } = require( "node:fs" );
	const returned = JSON.parse( process.argv[ 1 ] );
	const stored = JSON.parse( readFileSync( process.argv[ 2 ], "utf8" ) );
	for ( const allocation of [ returned, stored ] ) {
		if ( allocation.plugin_version !== "11.1.0" ) process.exit( 1 );
		if ( allocation.seed_hash !== process.argv[ 3 ] ) process.exit( 1 );
	}
' "$profile_11_allocation" "$profile_11_allocation_path" "$profile_11_seed_hash"
test "$(< "$profile_11_log")" = $'plan 11.1.0\ncreate 11.1.0'

cp "$SEED_MANIFEST" "$TEST_ROOT/wcs.json"
cp "$FIXTURES/transition-seed.tar.gz" "$TEST_ROOT/wcs.tar.gz"
chmod 0444 "$TEST_ROOT/wcs.json" "$TEST_ROOT/wcs.tar.gz"
for invalid_wcs_input in missing symlink writable; do
	invalid_wcs_path="$TEST_ROOT/wcs-$invalid_wcs_input"
	if [[ "$invalid_wcs_input" == symlink ]]; then ln -s "$TEST_ROOT/wcs.tar.gz" "$invalid_wcs_path"; fi
	if [[ "$invalid_wcs_input" == writable ]]; then cp "$TEST_ROOT/wcs.tar.gz" "$invalid_wcs_path"; chmod 0644 "$invalid_wcs_path"; fi
	expect_failure env TMPDIR="$TEST_ROOT" E2E_TRANSITION_RUN_ID="wcs-$invalid_wcs_input" \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
		E2E_TRANSITION_WCS_ARCHIVE="$invalid_wcs_path" E2E_TRANSITION_WCS_MANIFEST="$TEST_ROOT/wcs.json" \
		E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" "$PROVISION_SCRIPT" create
	test ! -e "$TEST_ROOT/woopayments-native-transition-wcs-$invalid_wcs_input"
done
wcs_allocation="$(env TMPDIR="$TEST_ROOT" E2E_TRANSITION_RUN_ID=wcs-paired \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
	E2E_TRANSITION_WCS_ARCHIVE="$TEST_ROOT/wcs.tar.gz" E2E_TRANSITION_WCS_MANIFEST="$TEST_ROOT/wcs.json" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" "$PROVISION_SCRIPT" create)"
node -e '
	const assert = require("node:assert/strict");
	const fs = require("node:fs");
	const crypto = require("node:crypto");
	const allocation = JSON.parse(process.argv[1]);
	const saved = JSON.parse(fs.readFileSync(allocation.allocation_path));
	assert.deepEqual(saved, allocation);
	assert.deepEqual(Object.keys(allocation.optional_artifacts), ["woocommerce-subscriptions"]);
	const artifact = allocation.optional_artifacts["woocommerce-subscriptions"];
	assert.equal(artifact.plugin, "woocommerce-subscriptions");
	assert.equal(artifact.plugin_version, "9.2.0");
	assert.equal(artifact.source_commit, "4008f7f515f5ea76eea4d9149514b8c1774e51ba");
	assert.equal(artifact.archive_sha256, crypto.createHash("sha256").update(fs.readFileSync(process.argv[2])).digest("hex"));
	assert.ok(!JSON.stringify(artifact).includes(process.argv[3]));
' "$wcs_allocation" "$TEST_ROOT/wcs.tar.gz" "$TEST_ROOT"
for mismatch in missing extra hash; do
	expect_failure env TMPDIR="$TEST_ROOT" E2E_TRANSITION_RUN_ID="wcs-mismatch-$mismatch" \
		E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
		E2E_TRANSITION_WCS_ARCHIVE="$TEST_ROOT/wcs.tar.gz" E2E_TRANSITION_WCS_MANIFEST="$TEST_ROOT/wcs.json" \
		E2E_FAKE_WCS_MISMATCH="$mismatch" E2E_FAKE_PROVISIONER_LOG="$TEST_ROOT/wcs-rollback.log" \
		E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" "$PROVISION_SCRIPT" create
	test ! -e "$TEST_ROOT/woopayments-native-transition-wcs-mismatch-$mismatch"
done
test "$(wc -l < "$TEST_ROOT/wcs-rollback.log" | tr -d ' ')" = 3
env TMPDIR="$TEST_ROOT" E2E_FAKE_PROVISIONER_LOG="$TEST_ROOT/wcs-destroy.log" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" "$PROVISION_SCRIPT" destroy --allocation "$wcs_allocation"
test -f "$TEST_ROOT/wcs.json"
test -f "$TEST_ROOT/wcs.tar.gz"

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
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_TRANSITION_BASE_URL='http://localhost:8082' \
	"$PROVISION_SCRIPT" create
if [[ -e "$TEST_ROOT/woopayments-native-transition-standing-port" ]]; then
	echo 'Invalid transition plan left its pre-resource workspace behind.' >&2
	exit 1
fi

rollback_log="$TEST_ROOT/rollback.log"
expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='post-create-mismatch' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
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
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
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
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
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
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
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
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
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
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_CREATED_STORE_ID='unexpected-created-store' \
	E2E_FAKE_DESTROY_FAILURE='1' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -q 'does not exactly match its validated plan' "$TEST_ROOT/expected-error"
grep -q 'Transition rollback cleanup failed' "$TEST_ROOT/expected-error"
test -d "$TEST_ROOT/woopayments-native-transition-rollback-cleanup-failure"

expect_failure env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_RUN_ID='port-lease-collision' \
	E2E_TRANSITION_SEED_ARCHIVE="$FIXTURES/transition-seed.tar.gz" \
	E2E_TRANSITION_SEED_MANIFEST="$SEED_MANIFEST" \
	E2E_TRANSITION_STORE_PROVISIONER="$FIXTURES/fake-transition-provisioner.sh" \
	E2E_FAKE_PORT_LEASE_COLLISION='1' \
	E2E_FAKE_PROVISIONER_LOG="$rollback_log" \
	"$PROVISION_SCRIPT" create
grep -q 'preserved the collision recovery workspace' "$TEST_ROOT/expected-error"
test -f "$TEST_ROOT/woopayments-native-transition-port-lease-collision/rollback-receipt"
test -f "$TEST_ROOT/woopayments-native-transition-port-lease-collision/resource-state.json"
expect_log_absent 'port-lease-collision' "$rollback_log"

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
