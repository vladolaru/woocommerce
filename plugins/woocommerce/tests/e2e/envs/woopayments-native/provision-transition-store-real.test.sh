#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
PROVISIONER="$SCRIPT_DIR/provision-transition-store-real.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-real-test.XXXXXX")"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

mode_of() {
	stat -f '%Lp' "$1" 2> /dev/null || stat -c '%a' "$1"
}

mkdir -p "$TEST_ROOT/seed/woocommerce-payments"
cat > "$TEST_ROOT/seed/woocommerce-payments/woocommerce-payments.php" <<'PHP'
<?php
/**
 * Plugin Name: WooPayments
 * Version: 10.5.0
 */
PHP
tar -czf "$TEST_ROOT/seed.tar.gz" -C "$TEST_ROOT/seed" woocommerce-payments
seed_hash="$(shasum -a 256 "$TEST_ROOT/seed.tar.gz" | awk '{ print $1 }')"
node -e '
	const { writeFileSync } = require( "node:fs" );
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
		schema_version: 1,
		plugin: "woocommerce-payments",
		plugin_version: "10.5.0",
		source_commit: "a1f755fc903966387f8629f78f75976ac8d2016e",
		archive_sha256: process.argv[ 2 ],
		archive_format: "tar.gz",
	} ) }\n` );
' "$TEST_ROOT/seed.json" "$seed_hash"
chmod 0444 "$TEST_ROOT/seed.tar.gz" "$TEST_ROOT/seed.json"

workspace="$TEST_ROOT/woopayments-native-transition-real-run"
mkdir "$workspace"
before_plan="$(find "$workspace" -mindepth 1 -print)"
plan="$(
	env E2E_TRANSITION_PORT=19091 \
		"$PROVISIONER" plan \
		--workspace "$workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id real-run
)"
test "$(find "$workspace" -mindepth 1 -print)" = "$before_plan"
node -e '
	const plan = JSON.parse( process.argv[ 1 ] );
	if ( plan.base_url !== "http://transition-real-run.localhost:19091" ) process.exit( 1 );
	if ( plan.store_id !== "woopayments-native-transition-real-run" ) process.exit( 1 );
	if ( plan.plugin_version !== "10.5.0" ) process.exit( 1 );
' "$plan"

sed 's/"plugin":"woocommerce-payments"/"plugin":"forged-plugin"/' \
	"$TEST_ROOT/seed.json" > "$TEST_ROOT/forged-seed.json"
chmod 0444 "$TEST_ROOT/forged-seed.json"
if env E2E_TRANSITION_PORT=19091 \
	"$PROVISIONER" plan \
	--workspace "$workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/forged-seed.json" \
	--run-id forged-seed > /dev/null 2>&1; then
	echo 'Plan accepted a seed manifest for a different plugin.' >&2
	exit 1
fi

if env E2E_TRANSITION_PORT=8082 "$PROVISIONER" plan \
	--workspace "$workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id standing > /dev/null 2>&1; then
	echo 'Plan accepted a standing store port.' >&2
	exit 1
fi

mkdir -p "$TEST_ROOT/mounts/core" "$TEST_ROOT/mounts/dev-tools" "$TEST_ROOT/mounts/wpcom-helper"

run_provisioner() {
	local owned_workspace="$1"
	local runtime_state="$2"
	local command_log="$3"
	shift 3
	env \
		E2E_TRANSITION_PORT="${E2E_TRANSITION_PORT:-19091}" \
		E2E_TRANSITION_CORE_REPO="$TEST_ROOT/mounts/core" \
		E2E_TRANSITION_DEV_TOOLS_REPO="$TEST_ROOT/mounts/dev-tools" \
		E2E_TRANSITION_WPCOM_HELPER_REPO="$TEST_ROOT/mounts/wpcom-helper" \
		E2E_TRANSITION_WPCOM_URL='http://wpcom.localhost:8080' \
		E2E_TRANSITION_WPCOM_API_URL='http://host.docker.internal:8080/wp-json/' \
		E2E_TRANSITION_PNPM_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		E2E_WPCOM_LOCAL_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-wpcom-local.sh" \
		E2E_FAKE_TRANSITION_WORKSPACE="$owned_workspace" \
		E2E_FAKE_RUNTIME_STATE="$runtime_state" \
		E2E_FAKE_COMMAND_LOG="$command_log" \
		E2E_FAKE_ACCOUNT_CREATE_FAIL="${E2E_FAKE_ACCOUNT_CREATE_FAIL:-0}" \
		E2E_FAKE_ACCOUNT_MISMATCH="${E2E_FAKE_ACCOUNT_MISMATCH:-0}" \
		"$PROVISIONER" "$@"
}

create_workspace="$TEST_ROOT/woopayments-native-transition-create-run"
create_runtime="$TEST_ROOT/runtime-create"
create_log="$TEST_ROOT/create-commands.log"
mkdir "$create_workspace"
create_result="$(
	run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
		create \
		--workspace "$create_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id create-run \
		--base-url 'http://transition-create-run.localhost:19091' \
		--store-id 'woopayments-native-transition-create-run'
)"

node -e '
	const { readFileSync } = require( "node:fs" );
	const result = JSON.parse( process.argv[ 1 ] );
	const receipt = readFileSync( process.argv[ 2 ], "utf8" );
	if ( result.base_url !== "http://transition-create-run.localhost:19091" ) process.exit( 1 );
	if ( result.store_id !== "woopayments-native-transition-create-run" ) process.exit( 1 );
	if ( result.plugin_version !== "10.5.0" ) process.exit( 1 );
	if ( result.wpcom_blog_id !== 77 ) process.exit( 1 );
	if ( result.account_id !== "acct_transition_77" ) process.exit( 1 );
	if ( result.rollback_receipt !== receipt ) process.exit( 1 );
' "$create_result" "$create_workspace/rollback-receipt"
test "$(mode_of "$create_workspace/rollback-receipt")" = '600'
test "$(mode_of "$create_workspace/resource-state.json")" = '600'
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$create_workspace/resource-state.json")" = 'ready'

node -e '
	const { readFileSync } = require( "node:fs" );
	const config = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( config.port !== 19091 ) process.exit( 1 );
	if ( config.mappings[ "wp-content/plugins/woocommerce" ] !== process.argv[ 2 ] ) process.exit( 1 );
	if ( config.mappings[ "wp-content/plugins/woocommerce-payments" ] !== `${ process.argv[ 3 ] }/seed/woocommerce-payments` ) process.exit( 1 );
	if ( config.mappings[ "wp-content/plugins/woocommerce-payments-dev-tools" ] !== process.argv[ 4 ] ) process.exit( 1 );
	if ( config.mappings[ "wp-content/plugins/wpcom-local-helper" ] !== process.argv[ 5 ] ) process.exit( 1 );
' \
	"$create_workspace/store/.wp-env.json" \
	"$TEST_ROOT/mounts/core" \
	"$create_workspace" \
	"$TEST_ROOT/mounts/dev-tools" \
	"$TEST_ROOT/mounts/wpcom-helper"
grep -Fq "$create_workspace/wp-env-home" "$create_log"
if grep -Rq 'must-not-be-persisted' "$create_workspace/evidence"; then
	echo 'Provider callback evidence persisted a fake secret.' >&2
	exit 1
fi

run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
	destroy \
	--workspace "$create_workspace" \
	--rollback-receipt-file "$create_workspace/rollback-receipt"
account_delete_line="$(grep -nF 'test-lab account delete --format=json' "$create_log" | tail -1 | cut -d: -f1)"
blog_delete_line="$(grep -nF 'transition_delete_blog' "$create_log" | tail -1 | cut -d: -f1)"
store_destroy_line="$(grep -nF 'exec wp-env destroy' "$create_log" | tail -1 | cut -d: -f1)"
if (( account_delete_line >= blog_delete_line || blog_delete_line >= store_destroy_line )); then
	echo 'Transition teardown did not delete account, blog, then wp-env in order.' >&2
	exit 1
fi
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$create_workspace/resource-state.json")" = 'destroyed'
test ! -e "$create_runtime/account"
test ! -e "$create_runtime/blog"
test ! -e "$create_runtime/wp-env"

mismatch_workspace="$TEST_ROOT/woopayments-native-transition-mismatch-run"
mismatch_runtime="$TEST_ROOT/runtime-mismatch"
mismatch_log="$TEST_ROOT/mismatch-commands.log"
mkdir "$mismatch_workspace"
E2E_TRANSITION_PORT=19092 run_provisioner \
	"$mismatch_workspace" "$mismatch_runtime" "$mismatch_log" \
	create \
	--workspace "$mismatch_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id mismatch-run \
	--base-url 'http://transition-mismatch-run.localhost:19092' \
	--store-id 'woopayments-native-transition-mismatch-run' > /dev/null
if E2E_TRANSITION_PORT=19092 E2E_FAKE_ACCOUNT_MISMATCH=1 run_provisioner \
	"$mismatch_workspace" "$mismatch_runtime" "$mismatch_log" \
	destroy \
	--workspace "$mismatch_workspace" \
	--rollback-receipt-file "$mismatch_workspace/rollback-receipt" > /dev/null 2>&1; then
	echo 'Transition teardown accepted a mismatched test-drive account identity.' >&2
	exit 1
fi
if grep -Fq 'test-lab account delete --format=json' "$mismatch_log" ||
	grep -Fq 'transition_delete_blog' "$mismatch_log" ||
	grep -Fq 'exec wp-env destroy' "$mismatch_log"; then
	echo 'Transition teardown mutated resources after an identity mismatch.' >&2
	exit 1
fi
test -f "$mismatch_workspace/resource-state.json"
test -f "$mismatch_runtime/account"
test -f "$mismatch_runtime/blog"
test -f "$mismatch_runtime/wp-env"

partial_workspace="$TEST_ROOT/woopayments-native-transition-partial-run"
partial_runtime="$TEST_ROOT/runtime-partial"
partial_log="$TEST_ROOT/partial-commands.log"
mkdir "$partial_workspace"
set +e
partial_result="$(
	E2E_TRANSITION_PORT=19093 E2E_FAKE_ACCOUNT_CREATE_FAIL=1 run_provisioner \
		"$partial_workspace" "$partial_runtime" "$partial_log" \
		create \
		--workspace "$partial_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id partial-run \
		--base-url 'http://transition-partial-run.localhost:19093' \
		--store-id 'woopayments-native-transition-partial-run' \
		2> "$TEST_ROOT/partial-create.stderr"
)"
partial_status=$?
set -e
if (( partial_status == 0 )); then
	echo 'The fake account failure did not fail transition create.' >&2
	exit 1
fi
node -e '
	const { readFileSync } = require( "node:fs" );
	const result = JSON.parse( process.argv[ 1 ] );
	if ( result.status !== "failed" ) process.exit( 1 );
	if ( result.rollback_receipt !== readFileSync( process.argv[ 2 ], "utf8" ) ) process.exit( 1 );
' "$partial_result" "$partial_workspace/rollback-receipt"
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.phase !== "create-failed" ) process.exit( 1 );
	if ( state.wp_env_created !== true || state.wpcom_blog_created !== true ) process.exit( 1 );
	if ( state.account_created !== false ) process.exit( 1 );
' "$partial_workspace/resource-state.json"
E2E_TRANSITION_PORT=19093 run_provisioner \
	"$partial_workspace" "$partial_runtime" "$partial_log" \
	destroy \
	--workspace "$partial_workspace" \
	--rollback-receipt-file "$partial_workspace/rollback-receipt"
if grep -Fq 'test-lab account delete --format=json' "$partial_log"; then
	echo 'Partial recovery attempted to delete an account that was never created.' >&2
	exit 1
fi
grep -Fq 'transition_delete_blog' "$partial_log"
grep -Fq 'exec wp-env destroy' "$partial_log"
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$partial_workspace/resource-state.json")" = 'destroyed'

echo 'provision-transition-store-real.sh tests passed.'
