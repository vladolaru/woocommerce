#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
ORCHESTRATOR="$SCRIPT_DIR/run-saved-method-transition.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-orchestrator-test.XXXXXX")"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

run_orchestrator() {
	local run_id="${1:-orchestrator-run}"
	local scenario="${2:-}"
	local seed_profile="${3:-10.5.0}"
	local pending_migrator_hook="${4:-0}"
	local -a scenario_environment=()
	if [[ -n "$scenario" ]]; then
		scenario_environment+=( "E2E_TRANSITION_SCENARIO=$scenario" )
	fi
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_RUN_ID="$run_id" \
		E2E_TRANSITION_SEED_ARCHIVE="$TEST_ROOT/seed.tar.gz" \
		E2E_TRANSITION_SEED_MANIFEST="$TEST_ROOT/seed.json" \
		E2E_TRANSITION_SEED_PROFILE="$seed_profile" \
		E2E_TRANSITION_PENDING_MIGRATOR_HOOK="$pending_migrator_hook" \
		E2E_TRANSITION_STORE_PROVISIONER="$SCRIPT_DIR/test-fixtures/fake-transition-provisioner.sh" \
		E2E_TRANSITION_WRAPPER="$SCRIPT_DIR/test-fixtures/fake-transition-wrapper.sh" \
		E2E_TRANSITION_TEST_RUNNER="$SCRIPT_DIR/test-fixtures/fake-transition-test-runner.sh" \
		E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
		${scenario_environment[@]+"${scenario_environment[@]}"} \
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
