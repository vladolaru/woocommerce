#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
PROVISIONER="$SCRIPT_DIR/provision-transition-store-real.sh"
PROVISION_WRAPPER="$SCRIPT_DIR/provision-transition-store.sh"
STATE_WRITER="$SCRIPT_DIR/write-transition-state.js"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-real-test.XXXXXX")"
SHARED_TMPDIR="$TEST_ROOT/shared-tmp"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT
mkdir "$SHARED_TMPDIR"
SHARED_TMPDIR="$(
	cd "$SHARED_TMPDIR"
	pwd -P
)"

mode_of() {
	stat -f '%Lp' "$1" 2> /dev/null || stat -c '%a' "$1"
}

assert_exact_lease_owner() {
	local lease_path="$1"
	local owned_workspace="$2"
	local owned_run_id="$3"
	local owned_base_url="$4"
	local owned_port="$5"
	local receipt_path="$6"
	node -e '
		const { createHash } = require( "node:crypto" );
		const { lstatSync, readFileSync } = require( "node:fs" );
		const stat = lstatSync( process.argv[ 1 ] );
		const owner = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const receiptHash = createHash( "sha256" )
			.update( readFileSync( process.argv[ 6 ] ) )
			.digest( "hex" );
		if (
			! stat.isFile() ||
			stat.isSymbolicLink() ||
			( stat.mode & 0o777 ) !== 0o600 ||
			owner.workspace !== process.argv[ 2 ] ||
			owner.run_id !== process.argv[ 3 ] ||
			owner.base_url !== process.argv[ 4 ] ||
			owner.port !== Number( process.argv[ 5 ] ) ||
			owner.receipt_sha256 !== receiptHash ||
			Object.keys( owner ).sort().join( "," ) !==
				"base_url,port,receipt_sha256,run_id,workspace"
		) process.exit( 1 );
	' \
		"$lease_path" \
		"$owned_workspace" \
		"$owned_run_id" \
		"$owned_base_url" \
		"$owned_port" \
		"$receipt_path"
}

state_writer_root="$TEST_ROOT/state-writer"
mkdir "$state_writer_root"
printf '{"phase":"original"}\n' > "$state_writer_root/resource-state.json"
printf 'stale and untrusted\n' > "$state_writer_root/resource-state.json.next"
node "$STATE_WRITER" "$state_writer_root/resource-state.json" phase stale-safe string
test "$(< "$state_writer_root/resource-state.json.next")" = 'stale and untrusted'
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$state_writer_root/resource-state.json")" = 'stale-safe'
test "$(mode_of "$state_writer_root/resource-state.json")" = '600'

node "$STATE_WRITER" "$state_writer_root/resource-state.json" phase writer-one string &
writer_one_pid=$!
node "$STATE_WRITER" "$state_writer_root/resource-state.json" phase writer-two string &
writer_two_pid=$!
wait "$writer_one_pid"
wait "$writer_two_pid"
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( ! [ "writer-one", "writer-two" ].includes( state.phase ) ) process.exit( 1 );
' "$state_writer_root/resource-state.json"
if find "$state_writer_root" -maxdepth 1 -name 'resource-state.json.tmp-*' -print -quit | grep -q .; then
	echo 'Concurrent state writers leaked an owned temporary file.' >&2
	exit 1
fi

state_before_failure="$(< "$state_writer_root/resource-state.json")"
if E2E_TRANSITION_STATE_WRITE_FAIL_POINT=before-rename \
	node "$STATE_WRITER" "$state_writer_root/resource-state.json" phase must-not-land string > /dev/null 2>&1; then
	echo 'The state writer failure point did not fail.' >&2
	exit 1
fi
test "$(< "$state_writer_root/resource-state.json")" = "$state_before_failure"
if find "$state_writer_root" -maxdepth 1 -name 'resource-state.json.tmp-*' -print -quit | grep -q .; then
	echo 'A failed state writer leaked its owned temporary file.' >&2
	exit 1
fi

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
printf '20.11\n' > "$TEST_ROOT/seed/woocommerce-payments/.nvmrc"
cat > "$TEST_ROOT/seed/woocommerce-payments/package.json" <<'JSON'
{
	"name": "woocommerce-payments",
	"version": "10.5.0",
	"scripts": {
		"build:client": "NODE_ENV=production webpack"
	}
}
JSON
cat > "$TEST_ROOT/seed/woocommerce-payments/package-lock.json" <<'JSON'
{
	"name": "woocommerce-payments",
	"version": "10.5.0",
	"lockfileVersion": 3,
	"requires": true,
	"packages": {
		"": {
			"name": "woocommerce-payments",
			"version": "10.5.0"
		},
		"node_modules/fake-webpack": {
			"version": "5.93.0"
		}
	}
}
JSON
mkdir "$TEST_ROOT/seed/woocommerce-payments/dist"
printf 'generated index JavaScript\n' > "$TEST_ROOT/seed/woocommerce-payments/dist/index.js"
printf 'generated index CSS\n' > "$TEST_ROOT/seed/woocommerce-payments/dist/index.css"
printf 'generated checkout JavaScript\n' > "$TEST_ROOT/seed/woocommerce-payments/dist/checkout.js"
printf 'generated blocks checkout JavaScript\n' > "$TEST_ROOT/seed/woocommerce-payments/dist/blocks-checkout.js"
tar -czf "$TEST_ROOT/seed.tar.gz" -C "$TEST_ROOT/seed" woocommerce-payments
seed_hash="$(shasum -a 256 "$TEST_ROOT/seed.tar.gz" | awk '{ print $1 }')"
dependency_lock_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/composer.lock" | awk '{ print $1 }')"
frontend_lock_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/package-lock.json" | awk '{ print $1 }')"
frontend_index_js_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/index.js" | awk '{ print $1 }')"
frontend_index_css_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/index.css" | awk '{ print $1 }')"
frontend_checkout_js_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/checkout.js" | awk '{ print $1 }')"
frontend_blocks_checkout_js_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/blocks-checkout.js" | awk '{ print $1 }')"
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
		frontend_lock_sha256: process.argv[ 4 ],
		frontend_lockfile_version: 3,
		frontend_node_line: "20.11",
		frontend_build_script: "build:client",
		executable_seed_files: [
			"vendor/autoload_packages.php",
			"vendor/autoload.php",
			"vendor/composer/installed.php",
			"vendor/composer/installed.json",
			"dist/index.js",
			"dist/index.css",
			"dist/checkout.js",
			"dist/blocks-checkout.js",
		],
		frontend_bundles: [
			{ path: "dist/index.js", sha256: process.argv[ 5 ] },
			{ path: "dist/index.css", sha256: process.argv[ 6 ] },
			{ path: "dist/checkout.js", sha256: process.argv[ 7 ] },
			{ path: "dist/blocks-checkout.js", sha256: process.argv[ 8 ] },
		],
	} ) }\n` );
' \
	"$TEST_ROOT/seed.json" \
	"$seed_hash" \
	"$dependency_lock_hash" \
	"$frontend_lock_hash" \
	"$frontend_index_js_hash" \
	"$frontend_index_css_hash" \
	"$frontend_checkout_js_hash" \
	"$frontend_blocks_checkout_js_hash"
export E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash"
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

make_invalid_frontend_seed() {
	local variant="$1"
	local variant_tree="$TEST_ROOT/$variant-tree"
	cp -R "$TEST_ROOT/seed" "$variant_tree"
	case "$variant" in
		vendor-only)
			rm -rf "$variant_tree/woocommerce-payments/dist"
			;;
		partial-dist)
			rm "$variant_tree/woocommerce-payments/dist/blocks-checkout.js"
			;;
		forged-dist)
			printf 'forged distribution payload\n' > "$variant_tree/woocommerce-payments/dist/index.js"
			;;
		forged-hash)
			;;
		forged-lock)
			node -e '
				const { readFileSync, writeFileSync } = require( "node:fs" );
				const lock = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
				lock.packages[ "" ].version = "99.0.0-forged";
				writeFileSync( process.argv[ 1 ], `${ JSON.stringify( lock ) }\n` );
			' "$variant_tree/woocommerce-payments/package-lock.json"
			;;
	esac
	local variant_archive="$TEST_ROOT/$variant-seed.tar.gz"
	local variant_manifest="$TEST_ROOT/$variant-seed.json"
	tar -czf "$variant_archive" -C "$variant_tree" woocommerce-payments
	local variant_archive_hash
	variant_archive_hash="$(shasum -a 256 "$variant_archive" | awk '{ print $1 }')"
	local variant_lock_hash
	variant_lock_hash="$(shasum -a 256 "$variant_tree/woocommerce-payments/package-lock.json" | awk '{ print $1 }')"
	node -e '
		const { readFileSync, writeFileSync } = require( "node:fs" );
		const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		manifest.archive_sha256 = process.argv[ 3 ];
		if ( process.argv[ 5 ] === "forged-hash" ) {
			manifest.frontend_bundles[ 0 ].sha256 = "0".repeat( 64 );
		}
		if ( process.argv[ 5 ] === "forged-lock" ) {
			manifest.frontend_lock_sha256 = process.argv[ 4 ];
		}
		writeFileSync( process.argv[ 2 ], `${ JSON.stringify( manifest ) }\n` );
	' \
		"$TEST_ROOT/seed.json" \
		"$variant_manifest" \
		"$variant_archive_hash" \
		"$variant_lock_hash" \
		"$variant"
	chmod 0444 "$variant_archive" "$variant_manifest"
}

for invalid_frontend_seed in \
	vendor-only \
	partial-dist \
	forged-dist \
	forged-hash \
	forged-lock; do
	make_invalid_frontend_seed "$invalid_frontend_seed"
done

invalid_frontend_seed_accepted=0
for invalid_frontend_seed in \
	vendor-only \
	partial-dist \
	forged-dist \
	forged-hash \
	forged-lock; do
	if env E2E_TRANSITION_PORT=19091 \
		"$PROVISIONER" plan \
		--workspace "$workspace" \
		--seed-archive "$TEST_ROOT/$invalid_frontend_seed-seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/$invalid_frontend_seed-seed.json" \
		--run-id "$invalid_frontend_seed" > /dev/null 2>&1; then
		echo "Plan accepted the invalid frontend seed: $invalid_frontend_seed." >&2
		invalid_frontend_seed_accepted=1
	fi
done
if (( invalid_frontend_seed_accepted != 0 )); then
	exit 1
fi
test "$(find "$workspace" -mindepth 1 -print)" = "$before_plan"

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

non_executable_wp_env="$TEST_ROOT/non-executable-wp-env"
printf '#!/usr/bin/env bash\nexit 99\n' > "$non_executable_wp_env"
chmod 0600 "$non_executable_wp_env"
for invalid_wp_env in "$TEST_ROOT/missing-wp-env" "$non_executable_wp_env"; do
	binary_plan_workspace="$TEST_ROOT/wp-env-binary-plan-$(basename "$invalid_wp_env")"
	mkdir "$binary_plan_workspace"
	if env E2E_TRANSITION_PORT=19089 E2E_TRANSITION_WP_ENV_BIN="$invalid_wp_env" \
		"$PROVISIONER" plan \
		--workspace "$binary_plan_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id binary-plan > /dev/null 2>&1; then
		echo "Transition plan accepted an unavailable wp-env executable: $invalid_wp_env" >&2
		exit 1
	fi
	test ! -n "$(find "$binary_plan_workspace" -mindepth 1 -print -quit)"
done

mkdir -p "$TEST_ROOT/mounts/core" "$TEST_ROOT/mounts/dev-tools" "$TEST_ROOT/mounts/wpcom-helper"

run_provisioner() {
	local owned_workspace="$1"
	local runtime_state="$2"
	local command_log="$3"
	shift 3
	env \
		TMPDIR="$SHARED_TMPDIR" \
		E2E_TRANSITION_PORT="${E2E_TRANSITION_PORT:-19091}" \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash" \
		E2E_TRANSITION_CORE_REPO="$TEST_ROOT/mounts/core" \
		E2E_TRANSITION_DEV_TOOLS_REPO="$TEST_ROOT/mounts/dev-tools" \
		E2E_TRANSITION_WPCOM_HELPER_REPO="$TEST_ROOT/mounts/wpcom-helper" \
		E2E_TRANSITION_WPCOM_URL='http://wpcom.localhost:8080' \
		E2E_TRANSITION_WPCOM_API_URL='http://host.docker.internal:8080/wp-json/' \
		E2E_TRANSITION_WP_ENV_BIN="${E2E_TRANSITION_WP_ENV_BIN:-$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh}" \
		E2E_TRANSITION_PNPM_BIN="$TEST_ROOT/poison-pnpm-must-not-run" \
		E2E_WPCOM_LOCAL_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-wpcom-local.sh" \
		E2E_FAKE_TRANSITION_WORKSPACE="$owned_workspace" \
		E2E_FAKE_RUNTIME_STATE="$runtime_state" \
		E2E_FAKE_COMMAND_LOG="$command_log" \
		E2E_FAKE_ACCOUNT_CREATE_FAIL="${E2E_FAKE_ACCOUNT_CREATE_FAIL:-0}" \
		E2E_FAKE_ACCOUNT_CREATE_MODE="${E2E_FAKE_ACCOUNT_CREATE_MODE:-success}" \
		E2E_FAKE_ACCOUNT_EVIDENCE_MODE="${E2E_FAKE_ACCOUNT_EVIDENCE_MODE:-exact}" \
		E2E_FAKE_ACCOUNT_RUNTIME="${E2E_FAKE_ACCOUNT_RUNTIME:-extension}" \
		E2E_FAKE_ACCOUNT_MISMATCH="${E2E_FAKE_ACCOUNT_MISMATCH:-0}" \
		E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL="${E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL:-0}" \
		E2E_FAKE_WP_ENV_DESTROY_FAIL="${E2E_FAKE_WP_ENV_DESTROY_FAIL:-0}" \
		E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL="${E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL:-0}" \
		E2E_FAKE_BLOG_RECOVERY_MODE="${E2E_FAKE_BLOG_RECOVERY_MODE:-unique}" \
		E2E_FAKE_BLOG_IDENTITY_MODE="${E2E_FAKE_BLOG_IDENTITY_MODE:-exact}" \
		E2E_FAKE_CALLBACK_MODE="${E2E_FAKE_CALLBACK_MODE:-valid}" \
		E2E_FAKE_TRANSITION_TO_NATIVE_CORE_BEFORE_EXIT="${E2E_FAKE_TRANSITION_TO_NATIVE_CORE_BEFORE_EXIT:-0}" \
		E2E_FAKE_KILL_AFTER_DELETE="${E2E_FAKE_KILL_AFTER_DELETE:-}" \
		E2E_TRANSITION_RECEIPT_FAIL_POINT="${E2E_TRANSITION_RECEIPT_FAIL_POINT:-}" \
		E2E_TRANSITION_LEASE_FAIL_POINT="${E2E_TRANSITION_LEASE_FAIL_POINT:-}" \
		E2E_TRANSITION_TEST_KILL_DURING_LEASE="${E2E_TRANSITION_TEST_KILL_DURING_LEASE:-}" \
		E2E_TRANSITION_TEST_KILL_AFTER_LEASE_RELEASE="${E2E_TRANSITION_TEST_KILL_AFTER_LEASE_RELEASE:-0}" \
		"$PROVISIONER" "$@"
}

binary_create_port=19087
for invalid_wp_env in "$TEST_ROOT/missing-wp-env" "$non_executable_wp_env"; do
	binary_create_workspace="$TEST_ROOT/wp-env-binary-create-$(basename "$invalid_wp_env")"
	binary_create_runtime="$TEST_ROOT/runtime-wp-env-binary-create-$(basename "$invalid_wp_env")"
	binary_create_log="$TEST_ROOT/wp-env-binary-create-$(basename "$invalid_wp_env").log"
	mkdir "$binary_create_workspace"
	if E2E_TRANSITION_PORT="$binary_create_port" E2E_TRANSITION_WP_ENV_BIN="$invalid_wp_env" run_provisioner \
		"$binary_create_workspace" "$binary_create_runtime" "$binary_create_log" \
		create \
		--workspace "$binary_create_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "binary-create-$binary_create_port" \
		--base-url "http://transition-binary-create-$binary_create_port.localhost:$binary_create_port" \
		--store-id "woopayments-native-transition-binary-create-$binary_create_port" \
		> /dev/null 2>&1; then
		echo "Transition create accepted an unavailable wp-env executable: $invalid_wp_env" >&2
		exit 1
	fi
	test ! -n "$(find "$binary_create_workspace" -mindepth 1 -print -quit)"
	test ! -e "$SHARED_TMPDIR/woopayments-native-transition-port-leases/$binary_create_port"
	test ! -s "$binary_create_log"
	binary_create_port=$((binary_create_port + 1))
done

receipt_failure_port=19100
for receipt_fail_point in \
	after-write \
	before-file-fsync \
	before-directory-fsync \
	kill-before-directory-fsync; do
	receipt_failure_workspace="$TEST_ROOT/woopayments-native-transition-receipt-$receipt_fail_point"
	receipt_failure_runtime="$TEST_ROOT/runtime-receipt-$receipt_fail_point"
	receipt_failure_log="$TEST_ROOT/receipt-$receipt_fail_point-commands.log"
	mkdir "$receipt_failure_workspace"
	set +e
	E2E_TRANSITION_PORT="$receipt_failure_port" E2E_TRANSITION_RECEIPT_FAIL_POINT="$receipt_fail_point" run_provisioner \
		"$receipt_failure_workspace" "$receipt_failure_runtime" "$receipt_failure_log" \
		create \
		--workspace "$receipt_failure_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "receipt-$receipt_fail_point" \
		--base-url "http://transition-receipt-$receipt_fail_point.localhost:$receipt_failure_port" \
		--store-id "woopayments-native-transition-receipt-$receipt_fail_point" \
		> /dev/null 2> "$TEST_ROOT/receipt-$receipt_fail_point.stderr"
	receipt_failure_status=$?
	set -e
	if (( receipt_failure_status == 0 )); then
		echo "The $receipt_fail_point receipt durability injection did not fail create." >&2
		exit 1
	fi
	test ! -e "$receipt_failure_workspace/resource-state.json"
	test ! -e "$SHARED_TMPDIR/woopayments-native-transition-port-leases/$receipt_failure_port"
	test ! -s "$receipt_failure_log"
	if [[ -e "$receipt_failure_workspace/rollback-receipt" ]]; then
		test -f "$receipt_failure_workspace/rollback-receipt"
		test "$(mode_of "$receipt_failure_workspace/rollback-receipt")" = '600'
	fi
	receipt_failure_port=$((receipt_failure_port + 1))
done

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
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).account_creation_attempted" "$create_workspace/resource-state.json")" = 'true'
create_lease_path="$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_path" "$create_workspace/resource-state.json")"
test "$create_lease_path" = "$SHARED_TMPDIR/woopayments-native-transition-port-leases/19091"
test -f "$create_lease_path"
test ! -L "$create_lease_path"
test "$(mode_of "$create_lease_path")" = '600'
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_acquired" "$create_workspace/resource-state.json")" = 'true'

node -e '
	const { readFileSync } = require( "node:fs" );
	const config = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( config.port !== 19091 ) process.exit( 1 );
	if ( config.testsEnvironment !== false ) process.exit( 1 );
	if ( Object.hasOwn( config, "testsPort" ) ) process.exit( 1 );
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
node -e '
	const evidence = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( evidence.callback_delivered_route !== evidence.callback_route ) process.exit( 1 );
	if ( evidence.ingress_routes !== "current" ) process.exit( 1 );
' "$create_workspace/evidence/callback-probe.json"

E2E_FAKE_ACCOUNT_RUNTIME=native_core run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
	destroy \
	--workspace "$create_workspace" \
	--rollback-receipt-file "$create_workspace/rollback-receipt"
account_delete_line="$(grep -nF 'test-lab account delete --format=json' "$create_log" | tail -1 | cut -d: -f1)"
blog_delete_line="$(grep -nF 'transition_delete_blog' "$create_log" | tail -1 | cut -d: -f1)"
store_destroy_line="$(grep -nF $'\tdestroy --force' "$create_log" | tail -1 | cut -d: -f1)"
if (( account_delete_line >= blog_delete_line || blog_delete_line >= store_destroy_line )); then
	echo 'Transition teardown did not delete account, blog, then wp-env in order.' >&2
	exit 1
fi
test "$(grep -Fc $'\tdestroy --force' "$create_log")" = '1'
grep -Fq $'\tstart' "$create_log"
grep -Fq $'\trun cli wp ' "$create_log"
if grep -Fq 'exec wp-env' "$create_log" || grep -Fq $'pnpm\t' "$create_log"; then
	echo 'Transition store commands still used package-manager wp-env resolution.' >&2
	exit 1
fi
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$create_workspace/resource-state.json")" = 'destroyed'
test ! -e "$create_runtime/account"
test ! -e "$create_runtime/blog"
test ! -e "$create_runtime/wp-env"
test ! -e "$create_lease_path"
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_released" "$create_workspace/resource-state.json")" = 'true'

native_precreate_workspace="$TEST_ROOT/woopayments-native-transition-native-precreate"
native_precreate_runtime="$TEST_ROOT/runtime-native-precreate"
native_precreate_log="$TEST_ROOT/native-precreate-commands.log"
mkdir "$native_precreate_workspace"
if E2E_TRANSITION_PORT=19109 E2E_FAKE_ACCOUNT_RUNTIME=native_core run_provisioner \
	"$native_precreate_workspace" "$native_precreate_runtime" "$native_precreate_log" \
	create \
	--workspace "$native_precreate_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id native-precreate \
	--base-url 'http://transition-native-precreate.localhost:19109' \
	--store-id 'woopayments-native-transition-native-precreate' > /dev/null 2>&1; then
	echo 'Transition create accepted native_core pre-create account evidence.' >&2
	exit 1
fi
if grep -Fq 'test-lab account create' "$native_precreate_log"; then
	echo 'Transition create mutated account state after native_core pre-create evidence.' >&2
	exit 1
fi
E2E_TRANSITION_PORT=19109 E2E_FAKE_ACCOUNT_RUNTIME=native_core run_provisioner \
	"$native_precreate_workspace" "$native_precreate_runtime" "$native_precreate_log" \
	destroy \
	--workspace "$native_precreate_workspace" \
	--rollback-receipt-file "$native_precreate_workspace/rollback-receipt"
test ! -e "$native_precreate_runtime/blog"
test ! -e "$native_precreate_runtime/wp-env"

native_exit_runtime="$TEST_ROOT/runtime-native-exit"
native_exit_log="$TEST_ROOT/native-exit-commands.log"
set +e
env \
	TMPDIR="$SHARED_TMPDIR" \
	E2E_TRANSITION_PORT=19108 \
	E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash" \
	E2E_TRANSITION_RUN_ID=native-exit \
	E2E_TRANSITION_SEED_ARCHIVE="$TEST_ROOT/seed.tar.gz" \
	E2E_TRANSITION_SEED_MANIFEST="$TEST_ROOT/seed.json" \
	E2E_TRANSITION_STORE_PROVISIONER="$PROVISIONER" \
	E2E_TRANSITION_CORE_REPO="$TEST_ROOT/mounts/core" \
	E2E_TRANSITION_DEV_TOOLS_REPO="$TEST_ROOT/mounts/dev-tools" \
	E2E_TRANSITION_WPCOM_HELPER_REPO="$TEST_ROOT/mounts/wpcom-helper" \
	E2E_TRANSITION_WPCOM_URL='http://wpcom.localhost:8080' \
	E2E_TRANSITION_WPCOM_API_URL='http://host.docker.internal:8080/wp-json/' \
	E2E_TRANSITION_WP_ENV_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_TRANSITION_PNPM_BIN="$TEST_ROOT/poison-pnpm-must-not-run" \
	E2E_WPCOM_LOCAL_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-wpcom-local.sh" \
	E2E_FAKE_TRANSITION_WORKSPACE="$SHARED_TMPDIR/woopayments-native-transition-native-exit" \
	E2E_FAKE_RUNTIME_STATE="$native_exit_runtime" \
	E2E_FAKE_COMMAND_LOG="$native_exit_log" \
	E2E_FAKE_CALLBACK_MODE=stale-ingress-routes \
	E2E_FAKE_TRANSITION_TO_NATIVE_CORE_BEFORE_EXIT=1 \
	"$PROVISION_WRAPPER" create \
	> /dev/null 2> "$TEST_ROOT/native-exit.stderr"
native_exit_status=$?
set -e
if (( native_exit_status == 0 )); then
	echo 'The native_core EXIT cleanup fixture did not fail after transition.' >&2
	exit 1
fi
test ! -e "$SHARED_TMPDIR/woopayments-native-transition-native-exit"
test ! -e "$native_exit_runtime/account"
test ! -e "$native_exit_runtime/blog"
test ! -e "$native_exit_runtime/wp-env"
test "$(grep -Fc $'\tdestroy --force' "$native_exit_log")" = '1'

lease_owner_workspace="$TEST_ROOT/woopayments-native-transition-lease-owner"
lease_owner_runtime="$TEST_ROOT/runtime-lease-owner"
lease_owner_log="$TEST_ROOT/lease-owner-commands.log"
lease_collision_workspace="$TEST_ROOT/woopayments-native-transition-lease-collision"
lease_collision_runtime="$TEST_ROOT/runtime-lease-collision"
lease_collision_log="$TEST_ROOT/lease-collision-commands.log"
mkdir "$lease_owner_workspace" "$lease_collision_workspace"
E2E_TRANSITION_PORT=19150 run_provisioner \
	"$lease_owner_workspace" "$lease_owner_runtime" "$lease_owner_log" \
	create \
	--workspace "$lease_owner_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id lease-owner \
	--base-url 'http://transition-lease-owner.localhost:19150' \
	--store-id 'woopayments-native-transition-lease-owner' > /dev/null
lease_owner_path="$SHARED_TMPDIR/woopayments-native-transition-port-leases/19150"
lease_owner_before="$(shasum -a 256 "$lease_owner_path" | awk '{ print $1 }')"
if E2E_TRANSITION_PORT=19150 run_provisioner \
	"$lease_collision_workspace" "$lease_collision_runtime" "$lease_collision_log" \
	create \
	--workspace "$lease_collision_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id lease-collision \
	--base-url 'http://transition-lease-collision.localhost:19150' \
	--store-id 'woopayments-native-transition-lease-collision' > /dev/null 2>&1; then
	echo 'Transition create accepted a deterministic port lease collision.' >&2
	exit 1
fi
test ! -s "$lease_collision_log"
test -f "$lease_collision_workspace/rollback-receipt"
test -f "$lease_collision_workspace/resource-state.json"
lease_collision_candidate="$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_candidate_path" "$lease_collision_workspace/resource-state.json")"
test ! -e "$lease_collision_candidate"
test "$lease_owner_before" = "$(shasum -a 256 "$lease_owner_path" | awk '{ print $1 }')"
E2E_TRANSITION_PORT=19150 run_provisioner \
	"$lease_collision_workspace" "$lease_collision_runtime" "$lease_collision_log" \
	destroy \
	--workspace "$lease_collision_workspace" \
	--rollback-receipt-file "$lease_collision_workspace/rollback-receipt"
test -f "$lease_owner_path"
E2E_TRANSITION_PORT=19150 run_provisioner \
	"$lease_owner_workspace" "$lease_owner_runtime" "$lease_owner_log" \
	destroy \
	--workspace "$lease_owner_workspace" \
	--rollback-receipt-file "$lease_owner_workspace/rollback-receipt"
test ! -e "$lease_owner_path"

lease_namespace="$SHARED_TMPDIR/woopayments-native-transition-port-leases"
stale_lease_path="$lease_namespace/19151"
node -e '
	const { writeFileSync } = require( "node:fs" );
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
		port: 19151,
		run_id: "stale-owner",
		workspace: "/stale/untrusted/workspace",
		base_url: "http://transition-stale-owner.localhost:19151",
		receipt_sha256: "0".repeat( 64 ),
	} ) }\n`, { mode: 0o600 } );
	' "$stale_lease_path"
stale_owner_before="$(shasum -a 256 "$stale_lease_path" | awk '{ print $1 }')"
stale_collision_workspace="$TEST_ROOT/woopayments-native-transition-stale-collision"
stale_collision_runtime="$TEST_ROOT/runtime-stale-collision"
stale_collision_log="$TEST_ROOT/stale-collision-commands.log"
mkdir "$stale_collision_workspace"
if E2E_TRANSITION_PORT=19151 run_provisioner \
	"$stale_collision_workspace" "$stale_collision_runtime" "$stale_collision_log" \
	create \
	--workspace "$stale_collision_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id stale-collision \
	--base-url 'http://transition-stale-collision.localhost:19151' \
	--store-id 'woopayments-native-transition-stale-collision' > /dev/null 2>&1; then
	echo 'Transition create stole a stale deterministic port lease.' >&2
	exit 1
fi
test ! -s "$stale_collision_log"
test "$stale_owner_before" = "$(shasum -a 256 "$stale_lease_path" | awk '{ print $1 }')"
E2E_TRANSITION_PORT=19151 run_provisioner \
	"$stale_collision_workspace" "$stale_collision_runtime" "$stale_collision_log" \
	destroy \
	--workspace "$stale_collision_workspace" \
	--rollback-receipt-file "$stale_collision_workspace/rollback-receipt"
test -f "$stale_lease_path"
rm "$stale_lease_path"

lease_failure_port=19160
for lease_failure_mode in \
	candidate-write \
	candidate-fsync \
	link \
	directory-fsync \
	kill-before-link \
	kill-after-link; do
	lease_failure_workspace="$TEST_ROOT/woopayments-native-transition-lease-$lease_failure_mode"
	lease_failure_runtime="$TEST_ROOT/runtime-lease-$lease_failure_mode"
	lease_failure_log="$TEST_ROOT/lease-$lease_failure_mode-commands.log"
	lease_failure_base_url="http://transition-lease-$lease_failure_mode.localhost:$lease_failure_port"
	mkdir "$lease_failure_workspace"
	set +e
	if [[ "$lease_failure_mode" == kill-* ]]; then
		E2E_TRANSITION_PORT="$lease_failure_port" E2E_TRANSITION_TEST_KILL_DURING_LEASE="${lease_failure_mode#kill-}" run_provisioner \
			"$lease_failure_workspace" "$lease_failure_runtime" "$lease_failure_log" \
			create \
			--workspace "$lease_failure_workspace" \
			--seed-archive "$TEST_ROOT/seed.tar.gz" \
			--seed-manifest "$TEST_ROOT/seed.json" \
			--run-id "lease-$lease_failure_mode" \
			--base-url "$lease_failure_base_url" \
			--store-id "woopayments-native-transition-lease-$lease_failure_mode" \
			> /dev/null 2> "$TEST_ROOT/lease-$lease_failure_mode.stderr"
	else
		E2E_TRANSITION_PORT="$lease_failure_port" E2E_TRANSITION_LEASE_FAIL_POINT="$lease_failure_mode" run_provisioner \
			"$lease_failure_workspace" "$lease_failure_runtime" "$lease_failure_log" \
			create \
			--workspace "$lease_failure_workspace" \
			--seed-archive "$TEST_ROOT/seed.tar.gz" \
			--seed-manifest "$TEST_ROOT/seed.json" \
			--run-id "lease-$lease_failure_mode" \
			--base-url "$lease_failure_base_url" \
			--store-id "woopayments-native-transition-lease-$lease_failure_mode" \
			> /dev/null 2> "$TEST_ROOT/lease-$lease_failure_mode.stderr"
	fi
	lease_failure_status=$?
	set -e
	if (( lease_failure_status == 0 )); then
		echo "The $lease_failure_mode lease publication injection did not fail create." >&2
		exit 1
	fi
	test -f "$lease_failure_workspace/resource-state.json"
	lease_failure_path="$lease_namespace/$lease_failure_port"
	lease_failure_candidate="$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_candidate_path" "$lease_failure_workspace/resource-state.json")"
	case "$lease_failure_mode" in
		candidate-write|candidate-fsync|link)
			test ! -e "$lease_failure_path"
			test ! -e "$lease_failure_candidate"
			;;
		kill-before-link)
			test ! -e "$lease_failure_path"
			assert_exact_lease_owner \
				"$lease_failure_candidate" \
				"$lease_failure_workspace" \
				"lease-$lease_failure_mode" \
				"$lease_failure_base_url" \
				"$lease_failure_port" \
				"$lease_failure_workspace/rollback-receipt"
			;;
		directory-fsync)
			assert_exact_lease_owner \
				"$lease_failure_path" \
				"$lease_failure_workspace" \
				"lease-$lease_failure_mode" \
				"$lease_failure_base_url" \
				"$lease_failure_port" \
				"$lease_failure_workspace/rollback-receipt"
			test ! -e "$lease_failure_candidate"
			;;
		kill-after-link)
			assert_exact_lease_owner \
				"$lease_failure_path" \
				"$lease_failure_workspace" \
				"lease-$lease_failure_mode" \
				"$lease_failure_base_url" \
				"$lease_failure_port" \
				"$lease_failure_workspace/rollback-receipt"
			assert_exact_lease_owner \
				"$lease_failure_candidate" \
				"$lease_failure_workspace" \
				"lease-$lease_failure_mode" \
				"$lease_failure_base_url" \
				"$lease_failure_port" \
				"$lease_failure_workspace/rollback-receipt"
			test "$(stat -f '%i' "$lease_failure_path" 2> /dev/null || stat -c '%i' "$lease_failure_path")" = \
				"$(stat -f '%i' "$lease_failure_candidate" 2> /dev/null || stat -c '%i' "$lease_failure_candidate")"
			;;
	esac
	test ! -s "$lease_failure_log"
	E2E_TRANSITION_PORT="$lease_failure_port" run_provisioner \
		"$lease_failure_workspace" "$lease_failure_runtime" "$lease_failure_log" \
		destroy \
		--workspace "$lease_failure_workspace" \
		--rollback-receipt-file "$lease_failure_workspace/rollback-receipt"
	test ! -e "$lease_failure_path"
	test ! -e "$lease_failure_candidate"
	test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$lease_failure_workspace/resource-state.json")" = 'destroyed'
	lease_failure_port=$((lease_failure_port + 1))
done

lease_retry_workspace="$TEST_ROOT/woopayments-native-transition-lease-retry"
lease_retry_runtime="$TEST_ROOT/runtime-lease-retry"
lease_retry_log="$TEST_ROOT/lease-retry-commands.log"
mkdir "$lease_retry_workspace"
E2E_TRANSITION_PORT=19152 run_provisioner \
	"$lease_retry_workspace" "$lease_retry_runtime" "$lease_retry_log" \
	create \
	--workspace "$lease_retry_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id lease-retry \
	--base-url 'http://transition-lease-retry.localhost:19152' \
	--store-id 'woopayments-native-transition-lease-retry' > /dev/null
lease_retry_path="$lease_namespace/19152"
set +e
E2E_TRANSITION_PORT=19152 E2E_TRANSITION_TEST_KILL_AFTER_LEASE_RELEASE=1 run_provisioner \
	"$lease_retry_workspace" "$lease_retry_runtime" "$lease_retry_log" \
	destroy \
	--workspace "$lease_retry_workspace" \
	--rollback-receipt-file "$lease_retry_workspace/rollback-receipt" \
	> /dev/null 2> "$TEST_ROOT/lease-retry-destroy.stderr"
lease_retry_status=$?
set -e
if (( lease_retry_status == 0 )); then
	echo 'The post-lease-release kill point did not interrupt destroy.' >&2
	exit 1
fi
test ! -e "$lease_retry_path"
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.wp_env_destroyed !== true ) process.exit( 1 );
	if ( state.port_lease_released !== false ) process.exit( 1 );
' "$lease_retry_workspace/resource-state.json"
E2E_TRANSITION_PORT=19152 run_provisioner \
	"$lease_retry_workspace" "$lease_retry_runtime" "$lease_retry_log" \
	destroy \
	--workspace "$lease_retry_workspace" \
	--rollback-receipt-file "$lease_retry_workspace/rollback-receipt"
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_released" "$lease_retry_workspace/resource-state.json")" = 'true'

lease_wrong_owner_workspace="$TEST_ROOT/woopayments-native-transition-lease-wrong-owner"
lease_wrong_owner_runtime="$TEST_ROOT/runtime-lease-wrong-owner"
lease_wrong_owner_log="$TEST_ROOT/lease-wrong-owner-commands.log"
mkdir "$lease_wrong_owner_workspace"
E2E_TRANSITION_PORT=19153 run_provisioner \
	"$lease_wrong_owner_workspace" "$lease_wrong_owner_runtime" "$lease_wrong_owner_log" \
	create \
	--workspace "$lease_wrong_owner_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id lease-wrong-owner \
	--base-url 'http://transition-lease-wrong-owner.localhost:19153' \
	--store-id 'woopayments-native-transition-lease-wrong-owner' > /dev/null
lease_wrong_owner_path="$lease_namespace/19153"
cp "$lease_wrong_owner_path" "$lease_wrong_owner_workspace/exact-owner.json"
node -e '
	const { readFileSync, writeFileSync } = require( "node:fs" );
	const owner = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	owner.run_id = "wrong-owner";
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( owner ) }\n`, { mode: 0o600 } );
' "$lease_wrong_owner_path"
if E2E_TRANSITION_PORT=19153 run_provisioner \
	"$lease_wrong_owner_workspace" "$lease_wrong_owner_runtime" "$lease_wrong_owner_log" \
	destroy \
	--workspace "$lease_wrong_owner_workspace" \
	--rollback-receipt-file "$lease_wrong_owner_workspace/rollback-receipt" \
	> /dev/null 2>&1; then
	echo 'Transition destroy accepted the wrong port lease owner.' >&2
	exit 1
fi
test -f "$lease_wrong_owner_runtime/account"
test -f "$lease_wrong_owner_runtime/blog"
test -f "$lease_wrong_owner_runtime/wp-env"
cp "$lease_wrong_owner_workspace/exact-owner.json" "$lease_wrong_owner_path"
E2E_TRANSITION_PORT=19153 run_provisioner \
	"$lease_wrong_owner_workspace" "$lease_wrong_owner_runtime" "$lease_wrong_owner_log" \
	destroy \
	--workspace "$lease_wrong_owner_workspace" \
	--rollback-receipt-file "$lease_wrong_owner_workspace/rollback-receipt"
test ! -e "$lease_wrong_owner_path"

callback_case_port=19110
for callback_mode in \
	missing-delivered-route \
	mismatched-delivered-route \
	missing-ingress-routes \
	stale-ingress-routes; do
	callback_workspace="$TEST_ROOT/woopayments-native-transition-callback-$callback_mode"
	callback_runtime="$TEST_ROOT/runtime-callback-$callback_mode"
	callback_log="$TEST_ROOT/callback-$callback_mode-commands.log"
	mkdir "$callback_workspace"
	if E2E_TRANSITION_PORT="$callback_case_port" E2E_FAKE_CALLBACK_MODE="$callback_mode" run_provisioner \
		"$callback_workspace" "$callback_runtime" "$callback_log" \
		create \
		--workspace "$callback_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "callback-$callback_mode" \
		--base-url "http://transition-callback-$callback_mode.localhost:$callback_case_port" \
		--store-id "woopayments-native-transition-callback-$callback_mode" > /dev/null 2>&1; then
		echo "Transition create accepted invalid callback proof: $callback_mode." >&2
		exit 1
	fi
	E2E_TRANSITION_PORT="$callback_case_port" run_provisioner \
		"$callback_workspace" "$callback_runtime" "$callback_log" \
		destroy \
		--workspace "$callback_workspace" \
		--rollback-receipt-file "$callback_workspace/rollback-receipt"
	callback_case_port=$((callback_case_port + 1))
done

mismatched_absence_port=19135
for blog_identity_mode in wrong-absent ambiguous; do
	blog_identity_workspace="$TEST_ROOT/woopayments-native-transition-blog-identity-$blog_identity_mode"
	blog_identity_runtime="$TEST_ROOT/runtime-blog-identity-$blog_identity_mode"
	blog_identity_log="$TEST_ROOT/blog-identity-$blog_identity_mode-commands.log"
	mkdir "$blog_identity_workspace"
	E2E_TRANSITION_PORT="$mismatched_absence_port" run_provisioner \
		"$blog_identity_workspace" "$blog_identity_runtime" "$blog_identity_log" \
		create \
		--workspace "$blog_identity_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "blog-identity-$blog_identity_mode" \
		--base-url "http://transition-blog-identity-$blog_identity_mode.localhost:$mismatched_absence_port" \
		--store-id "woopayments-native-transition-blog-identity-$blog_identity_mode" > /dev/null
	if E2E_TRANSITION_PORT="$mismatched_absence_port" E2E_FAKE_BLOG_IDENTITY_MODE="$blog_identity_mode" run_provisioner \
		"$blog_identity_workspace" "$blog_identity_runtime" "$blog_identity_log" \
		destroy \
		--workspace "$blog_identity_workspace" \
		--rollback-receipt-file "$blog_identity_workspace/rollback-receipt" \
		> /dev/null 2>&1; then
		echo "Transition destroy accepted $blog_identity_mode blog identity as absence." >&2
		exit 1
	fi
	test -f "$blog_identity_runtime/blog"
	E2E_TRANSITION_PORT="$mismatched_absence_port" run_provisioner \
		"$blog_identity_workspace" "$blog_identity_runtime" "$blog_identity_log" \
		destroy \
		--workspace "$blog_identity_workspace" \
		--rollback-receipt-file "$blog_identity_workspace/rollback-receipt"
	mismatched_absence_port=$((mismatched_absence_port + 1))
done

delete_kill_port=19140
for deleted_resource in account blog wp-env; do
	delete_kill_workspace="$TEST_ROOT/woopayments-native-transition-delete-kill-$deleted_resource"
	delete_kill_runtime="$TEST_ROOT/runtime-delete-kill-$deleted_resource"
	delete_kill_log="$TEST_ROOT/delete-kill-$deleted_resource-commands.log"
	mkdir "$delete_kill_workspace"
	E2E_TRANSITION_PORT="$delete_kill_port" run_provisioner \
		"$delete_kill_workspace" "$delete_kill_runtime" "$delete_kill_log" \
		create \
		--workspace "$delete_kill_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "delete-kill-$deleted_resource" \
		--base-url "http://transition-delete-kill-$deleted_resource.localhost:$delete_kill_port" \
		--store-id "woopayments-native-transition-delete-kill-$deleted_resource" > /dev/null
	set +e
	E2E_TRANSITION_PORT="$delete_kill_port" E2E_FAKE_KILL_AFTER_DELETE="$deleted_resource" run_provisioner \
		"$delete_kill_workspace" "$delete_kill_runtime" "$delete_kill_log" \
		destroy \
		--workspace "$delete_kill_workspace" \
		--rollback-receipt-file "$delete_kill_workspace/rollback-receipt" \
		> /dev/null 2> "$TEST_ROOT/delete-kill-$deleted_resource.stderr"
	delete_kill_status=$?
	set -e
	if (( delete_kill_status == 0 )); then
		echo "The $deleted_resource delete kill point did not interrupt destroy." >&2
		exit 1
	fi
	case "$deleted_resource" in
		account)
			test ! -e "$delete_kill_runtime/account"
			test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).account_deleted" "$delete_kill_workspace/resource-state.json")" = 'false'
			;;
		blog)
			test ! -e "$delete_kill_runtime/blog"
			test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).wpcom_blog_deleted" "$delete_kill_workspace/resource-state.json")" = 'false'
			;;
		wp-env)
			test ! -e "$delete_kill_runtime/wp-env"
			test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).wp_env_destroyed" "$delete_kill_workspace/resource-state.json")" = 'false'
			for incomplete_phase in account_deleted wpcom_blog_deleted; do
				node "$STATE_WRITER" \
					"$delete_kill_workspace/resource-state.json" \
					"$incomplete_phase" \
					false \
					boolean
				if E2E_TRANSITION_PORT="$delete_kill_port" run_provisioner \
					"$delete_kill_workspace" "$delete_kill_runtime" "$delete_kill_log" \
					destroy \
					--workspace "$delete_kill_workspace" \
					--rollback-receipt-file "$delete_kill_workspace/rollback-receipt" \
					> /dev/null 2> "$TEST_ROOT/delete-kill-$deleted_resource-$incomplete_phase.stderr"; then
					echo "Transition destroy skipped required probes with $incomplete_phase incomplete." >&2
					exit 1
				fi
				test "$(grep -Fc $'\tdestroy --force' "$delete_kill_log")" = '1'
				node "$STATE_WRITER" \
					"$delete_kill_workspace/resource-state.json" \
					"$incomplete_phase" \
					true \
					boolean
			done
			;;
	esac
	E2E_TRANSITION_PORT="$delete_kill_port" run_provisioner \
		"$delete_kill_workspace" "$delete_kill_runtime" "$delete_kill_log" \
		destroy \
		--workspace "$delete_kill_workspace" \
		--rollback-receipt-file "$delete_kill_workspace/rollback-receipt"
	test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$delete_kill_workspace/resource-state.json")" = 'destroyed'
	test ! -e "$delete_kill_runtime/account"
	test ! -e "$delete_kill_runtime/blog"
	test ! -e "$delete_kill_runtime/wp-env"
		if [[ "$deleted_resource" == 'wp-env' ]]; then
			test "$(< "$delete_kill_runtime/wp-env-destroy-observed-absent")" = 'true'
			test "$(grep -Fc $'\tdestroy --force' "$delete_kill_log")" = '2'
		fi
	delete_kill_port=$((delete_kill_port + 1))
done

preexisting_account_workspace="$TEST_ROOT/woopayments-native-transition-preexisting-account"
preexisting_account_runtime="$TEST_ROOT/runtime-preexisting-account"
preexisting_account_log="$TEST_ROOT/preexisting-account-commands.log"
mkdir "$preexisting_account_workspace"
if E2E_TRANSITION_PORT=19120 E2E_FAKE_ACCOUNT_EVIDENCE_MODE=preexisting run_provisioner \
	"$preexisting_account_workspace" "$preexisting_account_runtime" "$preexisting_account_log" \
	create \
	--workspace "$preexisting_account_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id preexisting-account \
	--base-url 'http://transition-preexisting-account.localhost:19120' \
	--store-id 'woopayments-native-transition-preexisting-account' > /dev/null 2>&1; then
	echo 'Transition create accepted nonempty pre-create account evidence.' >&2
	exit 1
fi
if grep -Fq 'test-lab account create' "$preexisting_account_log"; then
	echo 'Transition create mutated account state after nonempty pre-create evidence.' >&2
	exit 1
fi
E2E_TRANSITION_PORT=19120 run_provisioner \
	"$preexisting_account_workspace" "$preexisting_account_runtime" "$preexisting_account_log" \
	destroy \
	--workspace "$preexisting_account_workspace" \
	--rollback-receipt-file "$preexisting_account_workspace/rollback-receipt"

account_nonzero_workspace="$TEST_ROOT/woopayments-native-transition-account-nonzero"
account_nonzero_runtime="$TEST_ROOT/runtime-account-nonzero"
account_nonzero_log="$TEST_ROOT/account-nonzero-commands.log"
mkdir "$account_nonzero_workspace"
set +e
account_nonzero_result="$(
	E2E_TRANSITION_PORT=19121 E2E_FAKE_ACCOUNT_CREATE_MODE=after-create-nonzero run_provisioner \
		"$account_nonzero_workspace" "$account_nonzero_runtime" "$account_nonzero_log" \
		create \
		--workspace "$account_nonzero_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id account-nonzero \
		--base-url 'http://transition-account-nonzero.localhost:19121' \
		--store-id 'woopayments-native-transition-account-nonzero' \
		2> "$TEST_ROOT/account-nonzero-create.stderr"
)"
account_nonzero_status=$?
set -e
if (( account_nonzero_status == 0 )); then
	echo 'The create-then-nonzero account fixture did not fail create.' >&2
	exit 1
fi
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.account_creation_attempted !== true ) process.exit( 1 );
	if ( state.account_created !== true || state.account_id_recovered !== true ) process.exit( 1 );
	if ( state.account_id !== "acct_transition_77" ) process.exit( 1 );
' "$account_nonzero_workspace/resource-state.json"
E2E_TRANSITION_PORT=19121 run_provisioner \
	"$account_nonzero_workspace" "$account_nonzero_runtime" "$account_nonzero_log" \
	destroy \
	--workspace "$account_nonzero_workspace" \
	--rollback-receipt-file "$account_nonzero_workspace/rollback-receipt"
test ! -e "$account_nonzero_runtime/account"

account_kill_workspace="$TEST_ROOT/woopayments-native-transition-account-kill"
account_kill_runtime="$TEST_ROOT/runtime-account-kill"
account_kill_log="$TEST_ROOT/account-kill-commands.log"
mkdir "$account_kill_workspace"
set +e
E2E_TRANSITION_PORT=19122 E2E_FAKE_ACCOUNT_CREATE_MODE=after-create-kill run_provisioner \
	"$account_kill_workspace" "$account_kill_runtime" "$account_kill_log" \
	create \
	--workspace "$account_kill_workspace" \
	--seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" \
	--run-id account-kill \
	--base-url 'http://transition-account-kill.localhost:19122' \
	--store-id 'woopayments-native-transition-account-kill' \
	> /dev/null 2> "$TEST_ROOT/account-kill-create.stderr"
account_kill_status=$?
set -e
if (( account_kill_status == 0 )); then
	echo 'The account create kill point did not interrupt create.' >&2
	exit 1
fi
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.account_creation_attempted !== true ) process.exit( 1 );
	if ( state.account_created !== false || state.account_id !== "" ) process.exit( 1 );
' "$account_kill_workspace/resource-state.json"
test -f "$account_kill_runtime/account"
E2E_TRANSITION_PORT=19122 run_provisioner \
	"$account_kill_workspace" "$account_kill_runtime" "$account_kill_log" \
	destroy \
	--workspace "$account_kill_workspace" \
	--rollback-receipt-file "$account_kill_workspace/rollback-receipt"
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.account_id !== "acct_transition_77" ) process.exit( 1 );
	if ( state.account_id_recovered !== true || state.account_created !== true ) process.exit( 1 );
	if ( state.account_deleted !== true || state.phase !== "destroyed" ) process.exit( 1 );
' "$account_kill_workspace/resource-state.json"

account_rejection_port=19123
for evidence_mode in ambiguous live wrong-account wrong-runtime wrong-native-identity guardrail-denied; do
	rejection_workspace="$TEST_ROOT/woopayments-native-transition-account-$evidence_mode"
	rejection_runtime="$TEST_ROOT/runtime-account-$evidence_mode"
	rejection_log="$TEST_ROOT/account-$evidence_mode-commands.log"
	mkdir "$rejection_workspace"
	set +e
	E2E_TRANSITION_PORT="$account_rejection_port" E2E_FAKE_ACCOUNT_CREATE_MODE=after-create-kill run_provisioner \
		"$rejection_workspace" "$rejection_runtime" "$rejection_log" \
		create \
		--workspace "$rejection_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "account-$evidence_mode" \
		--base-url "http://transition-account-$evidence_mode.localhost:$account_rejection_port" \
		--store-id "woopayments-native-transition-account-$evidence_mode" \
		> /dev/null 2> "$TEST_ROOT/account-$evidence_mode-create.stderr"
	rejection_create_status=$?
	set -e
	if (( rejection_create_status == 0 )); then
		echo "The $evidence_mode account recovery fixture did not interrupt create." >&2
		exit 1
	fi
	if E2E_TRANSITION_PORT="$account_rejection_port" E2E_FAKE_ACCOUNT_EVIDENCE_MODE="$evidence_mode" run_provisioner \
		"$rejection_workspace" "$rejection_runtime" "$rejection_log" \
		destroy \
		--workspace "$rejection_workspace" \
		--rollback-receipt-file "$rejection_workspace/rollback-receipt" \
		> /dev/null 2> "$TEST_ROOT/account-$evidence_mode-destroy.stderr"; then
		echo "Transition destroy accepted $evidence_mode account recovery evidence." >&2
		exit 1
	fi
	test -f "$rejection_runtime/account"
	if grep -Fq 'test-lab account delete --format=json' "$rejection_log"; then
		echo "Transition destroy deleted an account after $evidence_mode recovery evidence." >&2
		exit 1
	fi
	E2E_TRANSITION_PORT="$account_rejection_port" run_provisioner \
		"$rejection_workspace" "$rejection_runtime" "$rejection_log" \
		destroy \
		--workspace "$rejection_workspace" \
		--rollback-receipt-file "$rejection_workspace/rollback-receipt"
	account_rejection_port=$((account_rejection_port + 1))
done

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
	grep -Fq $'\tdestroy --force' "$mismatch_log"; then
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
grep -Fq $'\tdestroy --force' "$partial_log"
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
		grep -Fq $'\tdestroy --force' "$recovery_log"; then
		echo "Transition cleanup mutated resources after a $recovery_mode blog recovery result." >&2
		exit 1
	fi
done

echo 'provision-transition-store-real.sh tests passed.'
