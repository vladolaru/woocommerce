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
tar -czf "$TEST_ROOT/source-only-seed.tar.gz" -C "$TEST_ROOT/seed" woocommerce-payments
source_only_seed_hash="$(shasum -a 256 "$TEST_ROOT/source-only-seed.tar.gz" | awk '{ print $1 }')"
node -e '
	const { writeFileSync } = require( "node:fs" );
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
		schema_version: 1,
		plugin: "woocommerce-payments",
		plugin_version: "10.5.0",
		source_commit: "a1f755fc903966387f8629f78f75976ac8d2016e",
		archive_sha256: process.argv[ 2 ],
		archive_format: "tar.gz",
		dependency_lock_sha256: "0".repeat( 64 ),
		production_package_count: 2,
		executable_seed_files: [
			"vendor/autoload_packages.php",
			"vendor/autoload.php",
			"vendor/composer/installed.php",
			"vendor/composer/installed.json",
		],
	} ) }\n` );
' "$TEST_ROOT/source-only-seed.json" "$source_only_seed_hash"
mkdir -p "$TEST_ROOT/seed/woocommerce-payments/vendor/composer"
cat > "$TEST_ROOT/seed/woocommerce-payments/composer.lock" <<'JSON'
{
	"packages": [
		{
			"name": "automattic/jetpack-autoloader",
			"version": "v5.0.15"
		},
		{
			"name": "automattic/jetpack-config",
			"version": "v3.1.1"
		}
	]
}
JSON
printf '<?php // generated Jetpack package autoloader\n' > "$TEST_ROOT/seed/woocommerce-payments/vendor/autoload_packages.php"
printf '<?php // generated Composer autoloader\n' > "$TEST_ROOT/seed/woocommerce-payments/vendor/autoload.php"
printf '<?php // generated installed map\n' > "$TEST_ROOT/seed/woocommerce-payments/vendor/composer/installed.php"
cat > "$TEST_ROOT/seed/woocommerce-payments/vendor/composer/installed.json" <<'JSON'
{
	"packages": [
		{
			"name": "automattic/jetpack-autoloader",
			"version": "v5.0.15"
		},
		{
			"name": "automattic/jetpack-config",
			"version": "v3.1.1"
		}
	]
}
JSON
tar -czf "$TEST_ROOT/seed.tar.gz" -C "$TEST_ROOT/seed" woocommerce-payments
seed_hash="$(shasum -a 256 "$TEST_ROOT/seed.tar.gz" | awk '{ print $1 }')"
dependency_lock_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/composer.lock" | awk '{ print $1 }')"
node -e '
	const { writeFileSync } = require( "node:fs" );
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
		schema_version: 1,
		plugin: "woocommerce-payments",
		plugin_version: "10.5.0",
		source_commit: "a1f755fc903966387f8629f78f75976ac8d2016e",
		archive_sha256: process.argv[ 2 ],
		archive_format: "tar.gz",
		dependency_lock_sha256: process.argv[ 3 ],
		production_package_count: 2,
		executable_seed_files: [
			"vendor/autoload_packages.php",
			"vendor/autoload.php",
			"vendor/composer/installed.php",
			"vendor/composer/installed.json",
		],
	} ) }\n` );
' "$TEST_ROOT/seed.json" "$seed_hash" "$dependency_lock_hash"
chmod 0444 \
	"$TEST_ROOT/source-only-seed.tar.gz" \
	"$TEST_ROOT/source-only-seed.json" \
	"$TEST_ROOT/seed.tar.gz" \
	"$TEST_ROOT/seed.json"

workspace="$TEST_ROOT/woopayments-native-transition-real-run"
mkdir "$workspace"
if env E2E_TRANSITION_PORT=19091 \
	"$PROVISIONER" plan \
	--workspace "$workspace" \
	--seed-archive "$TEST_ROOT/source-only-seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/source-only-seed.json" \
	--run-id source-only > /dev/null 2>&1; then
	echo 'Plan accepted a source-only seed without its executable dependency boundary.' >&2
	exit 1
fi
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
		E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL="${E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL:-0}" \
		E2E_FAKE_WP_ENV_DESTROY_FAIL="${E2E_FAKE_WP_ENV_DESTROY_FAIL:-0}" \
		E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL="${E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL:-0}" \
		E2E_FAKE_BLOG_RECOVERY_MODE="${E2E_FAKE_BLOG_RECOVERY_MODE:-unique}" \
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
	if ( config.config.WP_SITEURL !== "http://transition-create-run.localhost:19091" ) process.exit( 1 );
	if ( config.config.WP_HOME !== "http://transition-create-run.localhost:19091" ) process.exit( 1 );
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

wp_env_failure_workspace="$TEST_ROOT/woopayments-native-transition-wp-env-failure"
wp_env_failure_runtime="$TEST_ROOT/runtime-wp-env-failure"
wp_env_failure_log="$TEST_ROOT/wp-env-failure-commands.log"
mkdir "$wp_env_failure_workspace"
set +e
wp_env_failure_result="$(
	E2E_TRANSITION_PORT=19094 E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL=1 run_provisioner \
		"$wp_env_failure_workspace" "$wp_env_failure_runtime" "$wp_env_failure_log" \
		create \
		--workspace "$wp_env_failure_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id wp-env-failure \
		--base-url 'http://transition-wp-env-failure.localhost:19094' \
		--store-id 'woopayments-native-transition-wp-env-failure' \
		2> "$TEST_ROOT/wp-env-failure-create.stderr"
)"
wp_env_failure_status=$?
set -e
if (( wp_env_failure_status == 0 )); then
	echo 'The fake wp-env post-create failure did not fail transition create.' >&2
	exit 1
fi
node -e '
	const result = JSON.parse( process.argv[ 1 ] );
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 2 ], "utf8" ) );
	if ( result.status !== "failed" ) process.exit( 1 );
	if ( state.wp_env_start_attempted !== true ) process.exit( 1 );
	if ( state.wp_env_created !== false ) process.exit( 1 );
' "$wp_env_failure_result" "$wp_env_failure_workspace/resource-state.json"
test "$(< "$wp_env_failure_runtime/wp-env-intent-before-start")" = 'true'
test -f "$wp_env_failure_runtime/wp-env"
E2E_TRANSITION_PORT=19094 run_provisioner \
	"$wp_env_failure_workspace" "$wp_env_failure_runtime" "$wp_env_failure_log" \
	destroy \
	--workspace "$wp_env_failure_workspace" \
	--rollback-receipt-file "$wp_env_failure_workspace/rollback-receipt"
test ! -e "$wp_env_failure_runtime/wp-env"
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$wp_env_failure_workspace/resource-state.json")" = 'destroyed'

wp_env_cleanup_workspace="$TEST_ROOT/woopayments-native-transition-wp-env-cleanup-failure"
wp_env_cleanup_runtime="$TEST_ROOT/runtime-wp-env-cleanup-failure"
wp_env_cleanup_log="$TEST_ROOT/wp-env-cleanup-failure-commands.log"
mkdir "$wp_env_cleanup_workspace"
set +e
E2E_TRANSITION_PORT=19095 E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL=1 run_provisioner \
	"$wp_env_cleanup_workspace" "$wp_env_cleanup_runtime" "$wp_env_cleanup_log" \
	create \
	--workspace "$wp_env_cleanup_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id wp-env-cleanup-failure \
	--base-url 'http://transition-wp-env-cleanup-failure.localhost:19095' \
	--store-id 'woopayments-native-transition-wp-env-cleanup-failure' \
	> /dev/null 2> "$TEST_ROOT/wp-env-cleanup-create.stderr"
wp_env_cleanup_create_status=$?
set -e
if (( wp_env_cleanup_create_status == 0 )); then
	echo 'The cleanup-retention fixture did not fail transition create.' >&2
	exit 1
fi
if E2E_TRANSITION_PORT=19095 E2E_FAKE_WP_ENV_DESTROY_FAIL=1 run_provisioner \
	"$wp_env_cleanup_workspace" "$wp_env_cleanup_runtime" "$wp_env_cleanup_log" \
	destroy \
	--workspace "$wp_env_cleanup_workspace" \
	--rollback-receipt-file "$wp_env_cleanup_workspace/rollback-receipt" \
	> /dev/null 2> "$TEST_ROOT/wp-env-cleanup-destroy.stderr"; then
	echo 'Transition destroy hid the exact wp-env cleanup failure.' >&2
	exit 1
fi
test -f "$wp_env_cleanup_workspace/resource-state.json"
test -f "$wp_env_cleanup_workspace/rollback-receipt"
test -f "$wp_env_cleanup_runtime/wp-env"

blog_failure_workspace="$TEST_ROOT/woopayments-native-transition-blog-failure"
blog_failure_runtime="$TEST_ROOT/runtime-blog-failure"
blog_failure_log="$TEST_ROOT/blog-failure-commands.log"
mkdir "$blog_failure_workspace"
set +e
blog_failure_result="$(
	E2E_TRANSITION_PORT=19096 E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL=1 run_provisioner \
		"$blog_failure_workspace" "$blog_failure_runtime" "$blog_failure_log" \
		create \
		--workspace "$blog_failure_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id blog-failure \
		--base-url 'http://transition-blog-failure.localhost:19096' \
		--store-id 'woopayments-native-transition-blog-failure' \
		2> "$TEST_ROOT/blog-failure-create.stderr"
)"
blog_failure_status=$?
set -e
if (( blog_failure_status == 0 )); then
	echo 'The fake blog post-create failure did not fail transition create.' >&2
	exit 1
fi
node -e '
	const result = JSON.parse( process.argv[ 1 ] );
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 2 ], "utf8" ) );
	if ( result.status !== "failed" ) process.exit( 1 );
	if ( state.wpcom_blog_registration_attempted !== true ) process.exit( 1 );
	if ( state.wpcom_blog_created !== false ) process.exit( 1 );
	if ( state.wpcom_blog_id !== 0 ) process.exit( 1 );
' "$blog_failure_result" "$blog_failure_workspace/resource-state.json"
test "$(< "$blog_failure_runtime/blog-intent-before-registration")" = 'true'
test -f "$blog_failure_runtime/blog"
E2E_TRANSITION_PORT=19096 run_provisioner \
	"$blog_failure_workspace" "$blog_failure_runtime" "$blog_failure_log" \
	destroy \
	--workspace "$blog_failure_workspace" \
	--rollback-receipt-file "$blog_failure_workspace/rollback-receipt"
test "$(< "$blog_failure_runtime/blog-id-before-delete")" = '77'
test ! -e "$blog_failure_runtime/blog"
test ! -e "$blog_failure_runtime/wp-env"
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.wpcom_blog_id !== 77 ) process.exit( 1 );
	if ( state.wpcom_blog_id_recovered !== true ) process.exit( 1 );
	if ( state.phase !== "destroyed" ) process.exit( 1 );
' "$blog_failure_workspace/resource-state.json"

for recovery_mode in none ambiguous; do
	recovery_workspace="$TEST_ROOT/woopayments-native-transition-blog-$recovery_mode"
	recovery_runtime="$TEST_ROOT/runtime-blog-$recovery_mode"
	recovery_log="$TEST_ROOT/blog-$recovery_mode-commands.log"
	mkdir "$recovery_workspace"
	recovery_port="$(( 19096 + ${#recovery_mode} ))"
	set +e
	E2E_TRANSITION_PORT="$recovery_port" E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL=1 run_provisioner \
		"$recovery_workspace" "$recovery_runtime" "$recovery_log" \
		create \
		--workspace "$recovery_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "blog-$recovery_mode" \
		--base-url "http://transition-blog-$recovery_mode.localhost:$recovery_port" \
		--store-id "woopayments-native-transition-blog-$recovery_mode" \
		> /dev/null 2> "$TEST_ROOT/blog-$recovery_mode-create.stderr"
	recovery_create_status=$?
	set -e
	if (( recovery_create_status == 0 )); then
		echo "The $recovery_mode blog recovery fixture did not fail create." >&2
		exit 1
	fi
	if E2E_TRANSITION_PORT="$recovery_port" E2E_FAKE_BLOG_RECOVERY_MODE="$recovery_mode" run_provisioner \
		"$recovery_workspace" "$recovery_runtime" "$recovery_log" \
		destroy \
		--workspace "$recovery_workspace" \
		--rollback-receipt-file "$recovery_workspace/rollback-receipt" \
		> /dev/null 2> "$TEST_ROOT/blog-$recovery_mode-destroy.stderr"; then
		echo "Transition destroy accepted a $recovery_mode exact blog recovery result." >&2
		exit 1
	fi
	test -f "$recovery_workspace/resource-state.json"
	test -f "$recovery_workspace/rollback-receipt"
	test -f "$recovery_runtime/blog"
	test -f "$recovery_runtime/wp-env"
	if grep -Fq 'transition_delete_blog' "$recovery_log" ||
		grep -Fq 'exec wp-env destroy' "$recovery_log"; then
		echo "Transition cleanup mutated resources after a $recovery_mode blog recovery result." >&2
		exit 1
	fi
done

echo 'provision-transition-store-real.sh tests passed.'
