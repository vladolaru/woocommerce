#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
ORCHESTRATOR="$SCRIPT_DIR/run-saved-method-transition.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-orchestrator-test.XXXXXX")"
WRAPPER_PROBE="$TEST_ROOT/wrapper-probe.sh"

cat > "$WRAPPER_PROBE" <<'SH'
#!/usr/bin/env bash

set -euo pipefail

printf 'wrapper action=%s E2E_TRANSITION_SEED_PROFILE=%s E2E_TRANSITION_WCS_ARCHIVE=%s E2E_TRANSITION_WCS_MANIFEST=%s\n' \
	"${1:-}" \
	"${E2E_TRANSITION_SEED_PROFILE:-}" \
	"${E2E_TRANSITION_WCS_ARCHIVE:-}" \
	"${E2E_TRANSITION_WCS_MANIFEST:-}" >> "${E2E_FAKE_COMMAND_LOG:?}"
exec "${E2E_FAKE_WRAPPED_TRANSITION_WRAPPER:?}" "$@"
SH
chmod +x "$WRAPPER_PROBE"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

run_orchestrator() {
	local run_id="${1:-orchestrator-run}"
	local scenario="${2:-}"
	local seed_profile="${3:-}"
	local pending_migrator_hook="${4:-0}"
	local wcs_archive="${5:-}"
	local wcs_manifest="${6:-}"
	local wrapped_transition_wrapper="${7:-$SCRIPT_DIR/test-fixtures/fake-transition-wrapper.sh}"
	local -a transition_environment=()
	if [[ -n "$scenario" ]]; then
		transition_environment+=( "E2E_TRANSITION_SCENARIO=$scenario" )
	fi
	if [[ -n "$seed_profile" ]]; then
		transition_environment+=( "E2E_TRANSITION_SEED_PROFILE=$seed_profile" )
	fi
	if [[ -n "$wcs_archive" || -n "$wcs_manifest" ]]; then
		transition_environment+=(
			"E2E_TRANSITION_WCS_ARCHIVE=$wcs_archive"
			"E2E_TRANSITION_WCS_MANIFEST=$wcs_manifest"
		)
	fi
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID="$run_id" \
		E2E_TRANSITION_SEED_ARCHIVE="$TEST_ROOT/seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$TEST_ROOT/seed.json" \
		E2E_TRANSITION_PENDING_MIGRATOR_HOOK="$pending_migrator_hook" \
		E2E_TRANSITION_STORE_PROVISIONER="$SCRIPT_DIR/test-fixtures/fake-transition-provisioner.sh" \
		E2E_TRANSITION_WRAPPER="$WRAPPER_PROBE" \
		E2E_FAKE_WRAPPED_TRANSITION_WRAPPER="$wrapped_transition_wrapper" \
		E2E_TRANSITION_TEST_RUNNER="$SCRIPT_DIR/test-fixtures/fake-transition-test-runner.sh" \
		E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
		E2E_FAKE_PROVISIONER_LOG="$TEST_ROOT/commands.log" \
		${transition_environment[@]+"${transition_environment[@]}"} \
		"$ORCHESTRATOR"
}

assert_exact_capabilities() {
	local log_path="$1"
	shift
	node - "$log_path" "$@" <<'NODE'
const { readFileSync } = require( 'node:fs' );

const logPath = process.argv[ 2 ];
const expected = process.argv.slice( 3 );
const marker = ' E2E_WOOPAYMENTS_PROVIDER_FIXTURE=';
const runnerLines = readFileSync( logPath, 'utf8' )
	.split( '\n' )
	.filter( ( line ) => line.startsWith( 'runner ' ) && line.includes( marker ) );
if ( runnerLines.length !== 1 ) {
	console.error(
		`Expected exactly one runner approval in ${ logPath }; found ${ runnerLines.length }.`
	);
	process.exit( 1 );
}

let approval;
try {
	approval = JSON.parse(
		runnerLines[ 0 ].slice( runnerLines[ 0 ].indexOf( marker ) + marker.length )
	);
} catch ( error ) {
	console.error( `Runner approval is not valid JSON: ${ error.message }` );
	process.exit( 1 );
}

if (
	! Array.isArray( approval.capabilities ) ||
	JSON.stringify( approval.capabilities ) !== JSON.stringify( expected )
) {
	console.error(
		`Expected capabilities ${ JSON.stringify( expected ) }; received ${ JSON.stringify(
			approval.capabilities
		) }.`
	);
	process.exit( 1 );
}
NODE
}

printf 'seed\n' > "$TEST_ROOT/seed.tar.gz"
printf '{}\n' > "$TEST_ROOT/seed.json"
run_orchestrator

grep -Fq -- '--project=woopayments-native-transition' "$TEST_ROOT/commands.log"
grep -Fq -- 'tests/woopayments-native/pilots/saved-method-cutover.spec.ts' "$TEST_ROOT/commands.log"
grep -Fq -- '--workers=1' "$TEST_ROOT/commands.log"
grep -Fq 'WCPAY_RUNTIME=transition' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_WOOPAYMENTS_WPCOM_BLOG_ID=77' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_WOOPAYMENTS_ACCOUNT_ID=acct_transition_77' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_WOOPAYMENTS_ACCOUNT_ALIAS=reference-client' "$TEST_ROOT/commands.log"
grep -Fq 'E2E_TRANSITION_SEED_PROFILE=10.5.0 ' "$TEST_ROOT/commands.log"
assert_exact_capabilities \
	"$TEST_ROOT/commands.log" \
	'saved-method-cutover' \
	'plugin-owned-saved-card' \
	'saved-card-default' \
	'soft-cutover' \
	'saved-card-state' \
	'saved-card-cleanup' \
	'saved-card-classic' \
	'saved-card-blocks' \
	'product/payment'
if assert_exact_capabilities \
	"$TEST_ROOT/commands.log" \
	'plugin-owned-saved-card' \
	'saved-method-cutover' \
	'saved-card-default' \
	'soft-cutover' \
	'saved-card-state' \
	'saved-card-cleanup' \
	'saved-card-classic' \
	'saved-card-blocks' \
	'product/payment' 2>/dev/null; then
	echo 'The exact capability helper accepted a reordered approval.' >&2
	exit 1
fi
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

: > "$TEST_ROOT/commands.log"
run_orchestrator 'explicit-current-profile-run' '' '11.1.0'
grep -Fq 'wrapper action=create E2E_TRANSITION_SEED_PROFILE=11.1.0 ' "$TEST_ROOT/commands.log"
grep -Eq '^runner .* E2E_TRANSITION_SEED_PROFILE=11[.]1[.]0 ' "$TEST_ROOT/commands.log"
grep -Fq -- '--workers=1' "$TEST_ROOT/commands.log"
if grep -Fq -- '--retries' "$TEST_ROOT/commands.log"; then
	echo 'The explicit transition profile added a Playwright retry override.' >&2
	exit 1
fi

printf 'wcs archive\n' > "$TEST_ROOT/wcs.tar.gz"
printf '{"artifact":"wcs"}\n' > "$TEST_ROOT/wcs.json"
chmod 0444 "$TEST_ROOT/wcs.tar.gz" "$TEST_ROOT/wcs.json"
: > "$TEST_ROOT/commands.log"
run_orchestrator \
	'explicit-wcs-profile-run' \
	'' \
	'11.1.0' \
	'0' \
	"$TEST_ROOT/wcs.tar.gz" \
	"$TEST_ROOT/wcs.json" \
	"$SCRIPT_DIR/provision-transition-store.sh"
grep -Fq "wrapper action=create E2E_TRANSITION_SEED_PROFILE=11.1.0 E2E_TRANSITION_WCS_ARCHIVE=$TEST_ROOT/wcs.tar.gz E2E_TRANSITION_WCS_MANIFEST=$TEST_ROOT/wcs.json" "$TEST_ROOT/commands.log"
node - "$TEST_ROOT/commands.log" "$TEST_ROOT/wcs.tar.gz" "$TEST_ROOT/wcs.json" <<'NODE'
const { createHash } = require( 'node:crypto' );
const { readFileSync } = require( 'node:fs' );

const log = readFileSync( process.argv[ 2 ], 'utf8' );
const runnerLine = log.split( '\n' ).find( ( line ) => line.startsWith( 'runner ' ) );
if ( ! runnerLine ) {
	throw new Error( 'The paired-WCS run did not reach the test runner.' );
}
for ( const path of process.argv.slice( 3 ) ) {
	if ( runnerLine.includes( path ) ) {
		throw new Error( `The test-runner log leaked caller host path ${ path }.` );
	}
}
const marker = ' E2E_TRANSITION_OPTIONAL_ARTIFACTS=';
const endMarker = ' E2E_WOOPAYMENTS_LOCK_DIR=';
const start = runnerLine.indexOf( marker );
const end = runnerLine.indexOf( endMarker, start );
if ( start < 0 || end < 0 ) {
	throw new Error( 'The test runner did not receive immutable optional-artifact identity.' );
}
const actual = JSON.parse( runnerLine.slice( start + marker.length, end ) );
const hash = ( path ) => createHash( 'sha256' ).update( readFileSync( path ) ).digest( 'hex' );
const expected = {
	'woocommerce-subscriptions': {
		archive_format: 'tar.gz',
		archive_profile: 'wcs-transition-v1',
		archive_sha256: hash( process.argv[ 3 ] ),
		canonical_tar_sha256: 'a'.repeat( 64 ),
		canonical_tree_sha256: 'b'.repeat( 64 ),
		directory_mode: '0555',
		file_mode: '0444',
		manifest_sha256: hash( process.argv[ 4 ] ),
		plugin: 'woocommerce-subscriptions',
		plugin_version: '9.2.0',
		source_commit: '4008f7f515f5ea76eea4d9149514b8c1774e51ba',
		source_date_epoch: 1788854213,
	},
};
if ( JSON.stringify( actual ) !== JSON.stringify( expected ) ) {
	throw new Error( `Unexpected optional-artifact identity: ${ JSON.stringify( actual ) }` );
}
if ( ! runnerLine.includes( ' E2E_TRANSITION_SEED_PROFILE=11.1.0 ' ) ) {
	throw new Error( 'The paired-WCS run lost its explicit seed profile.' );
}
if ( ! runnerLine.includes( ' --workers=1 ' ) || runnerLine.includes( ' --retries' ) ) {
	throw new Error( 'The paired-WCS run changed Playwright worker or retry ownership.' );
}
NODE

: > "$TEST_ROOT/commands.log"
run_orchestrator 'cutover-old-profile-run' 'cutover-reconciliation' '10.4.0' '1'
node -e '
	const { readFileSync } = require( "node:fs" );
	const line = readFileSync( process.argv[ 1 ], "utf8" ).split( "\n" ).find( ( value ) => value.startsWith( "runner " ) );
	const approval = JSON.parse( line.slice( line.indexOf( " E2E_WOOPAYMENTS_PROVIDER_FIXTURE=" ) + " E2E_WOOPAYMENTS_PROVIDER_FIXTURE=".length ) );
	const keys = [ "account_alias", "account_id", "approval_id", "capabilities", "execution_scope", "runtime", "schema_version", "site_url", "store_id", "test_mode", "wpcom_blog_id" ];
	if ( JSON.stringify( Object.keys( approval ).sort() ) !== JSON.stringify( keys ) ) {
		throw new Error( "Provider approval must retain the exact existing schema-v1 key set." );
	}
	if ( ! line.includes( " E2E_TRANSITION_SEED_PROFILE=10.4.0 " ) || ! line.includes( " E2E_TRANSITION_PENDING_MIGRATOR_HOOK=1 " ) ) {
		throw new Error( "Transition profile must be exported separately from provider approval." );
	}
' "$TEST_ROOT/commands.log"

: > "$TEST_ROOT/commands.log"
run_orchestrator 'historical-token-run' 'historical-tokens'
grep -Fq -- 'tests/woopayments-native/transitions/historical-tokens.spec.ts' "$TEST_ROOT/commands.log"
assert_exact_capabilities \
	"$TEST_ROOT/commands.log" \
	'historical-tokens' \
	'plugin-owned-saved-card' \
	'saved-card-default' \
	'soft-cutover' \
	'saved-card-state' \
	'saved-card-cleanup' \
	'saved-card-classic' \
	'product/payment'
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

: > "$TEST_ROOT/commands.log"
run_orchestrator 'cutover-reconciliation-run' 'cutover-reconciliation'
grep -Fq -- 'tests/woopayments-native/transitions/cutover-reconciliation.spec.ts' "$TEST_ROOT/commands.log"
assert_exact_capabilities \
	"$TEST_ROOT/commands.log" \
	'cutover-reconciliation' \
	'cutover-ui' \
	'cutover-job' \
	'native-owner' \
	'basic-card' \
	'basic-card-entry' \
	'product/payment'
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

: > "$TEST_ROOT/commands.log"
campaign_id='historical-pay-for-order-campaign'
campaign_boundary="$TEST_ROOT/woopayments-native-historical-pay-for-order-ctp-$campaign_id.json"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-without-false' 'historical-pay-for-order-ctp-true'; then
	echo 'The enabled historical pay-for-order scenario ran without a successful disabled allocation teardown.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'The enabled historical pay-for-order scenario allocated or ran before the disabled teardown boundary.' >&2
	exit 1
fi

printf '{"campaignId":"stale-campaign","runId":"stale-run","scenario":"historical-pay-for-order-ctp-false","schemaVersion":1}\n' > "$campaign_boundary"
: > "$TEST_ROOT/commands.log"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-stale-marker' 'historical-pay-for-order-ctp-true'; then
	echo 'The enabled historical pay-for-order scenario accepted a stale marker.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'A stale marker reached allocation or the test runner.' >&2
	exit 1
fi

printf 'not-json\n' > "$campaign_boundary"
: > "$TEST_ROOT/commands.log"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-malformed-marker' 'historical-pay-for-order-ctp-true'; then
	echo 'The enabled historical pay-for-order scenario accepted a malformed marker.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'A malformed marker reached allocation or the test runner.' >&2
	exit 1
fi

printf '{"campaignId":"other-campaign","runId":"other-run","scenario":"historical-pay-for-order-ctp-false","schemaVersion":1}\n' > "$campaign_boundary"
: > "$TEST_ROOT/commands.log"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-wrong-campaign' 'historical-pay-for-order-ctp-true'; then
	echo 'The enabled historical pay-for-order scenario accepted evidence for another campaign.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'Wrong-campaign evidence reached allocation or the test runner.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-false-prior-success' 'historical-pay-for-order-ctp-false'
if [[ ! -s "$campaign_boundary" ]]; then
	echo 'A successful disabled historical pay-for-order scenario did not record campaign evidence.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	E2E_FAKE_TEST_RUNNER_FAIL=1 \
	run_orchestrator 'historical-pay-for-order-ctp-false-failed-run' 'historical-pay-for-order-ctp-false'; then
	echo 'The disabled historical pay-for-order scenario hid a test-runner failure.' >&2
	exit 1
fi
if [[ -e "$campaign_boundary" ]]; then
	echo 'A failed disabled historical pay-for-order scenario retained prior success evidence.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-after-failed-disabled' 'historical-pay-for-order-ctp-true'; then
	echo 'The enabled historical pay-for-order scenario accepted evidence invalidated by a failed disabled run.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'A failed disabled run allowed a later enabled allocation.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-false-run' 'historical-pay-for-order-ctp-false'
grep -Fq -- 'tests/woopayments-native/transitions/historical-money-records.spec.ts' "$TEST_ROOT/commands.log"
grep -Fq -- '--grep=plugin-origin failed order pays in place after cutover with card-testing protection disabled' "$TEST_ROOT/commands.log"
grep -Fq -- '--workers=1' "$TEST_ROOT/commands.log"
grep -Fq -- '--retries=0' "$TEST_ROOT/commands.log"
assert_exact_capabilities \
	"$TEST_ROOT/commands.log" \
	'historical-pay-for-order-ctp-false' \
	'card-decline-checkout' \
	'card-decline-customer-state' \
	'card-decline-setup-intent' \
	'card-testing-protection-setting' \
	'classic-checkout-page' \
	'basic-card' \
	'basic-card-entry' \
	'product/payment'
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

: > "$TEST_ROOT/commands.log"
E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-run' 'historical-pay-for-order-ctp-true'
grep -Fq -- 'tests/woopayments-native/transitions/historical-money-records.spec.ts' "$TEST_ROOT/commands.log"
grep -Fq -- '--grep=plugin-origin failed order pays in place after cutover with card-testing protection enabled' "$TEST_ROOT/commands.log"
grep -Fq -- '--workers=1' "$TEST_ROOT/commands.log"
grep -Fq -- '--retries=0' "$TEST_ROOT/commands.log"
assert_exact_capabilities \
	"$TEST_ROOT/commands.log" \
	'historical-pay-for-order-ctp-true' \
	'card-decline-checkout' \
	'card-decline-customer-state' \
	'card-decline-setup-intent' \
	'card-testing-protection-setting' \
	'classic-checkout-page' \
	'basic-card' \
	'basic-card-entry' \
	'product/payment'
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

: > "$TEST_ROOT/commands.log"
if E2E_TRANSITION_CTP_CAMPAIGN_ID="$campaign_id" \
	run_orchestrator 'historical-pay-for-order-ctp-true-consumed-marker' 'historical-pay-for-order-ctp-true'; then
	echo 'The enabled historical pay-for-order scenario reused consumed teardown evidence.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'Consumed teardown evidence reached allocation or the test runner.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
if run_orchestrator 'invalid-scenario-run' 'not-allowlisted'; then
	echo 'The transition orchestrator accepted an unknown scenario.' >&2
	exit 1
fi
if [[ -s "$TEST_ROOT/commands.log" ]]; then
	echo 'An unknown transition scenario reached allocation or the test runner.' >&2
	exit 1
fi

run_orchestrator 'orchestrator-run-2'
run_orchestrator 'historical-token-run-2' 'historical-tokens'
if [[ "$( grep -c '^runner ' "$TEST_ROOT/commands.log" )" != '2' ]]; then
	echo 'The shared lock-root assertion requires two successful transition runs.' >&2
	exit 1
fi
if [[ "$(
	sed -n 's/.* E2E_WOOPAYMENTS_LOCK_DIR=\([^ ]*\) E2E_WOOPAYMENTS_PROVIDER_FIXTURE=.*/\1/p' "$TEST_ROOT/commands.log" |
		sort -u |
		wc -l |
		tr -d ' '
)" != '1' ]]; then
	echo 'Transition runs borrowing one provider account must share a lock root.' >&2
	exit 1
fi

if grep -qi 'listen' "$TEST_ROOT/commands.log"; then
	echo 'The transition orchestrator must not start the Stripe listener.' >&2
	exit 1
fi

: > "$TEST_ROOT/commands.log"
if E2E_FAKE_TEST_RUNNER_FAIL=1 run_orchestrator; then
	echo 'The transition orchestrator hid the Playwright failure.' >&2
	exit 1
fi
grep -Fq 'destroy exact-allocation' "$TEST_ROOT/commands.log"

echo 'run-saved-method-transition.sh tests passed.'
