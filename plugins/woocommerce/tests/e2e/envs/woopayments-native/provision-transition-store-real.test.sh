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
	local status=$?
	trap - EXIT
	find "$TEST_ROOT" -type d -exec chmod u+w {} + 2> /dev/null || true
	rm -rf "$TEST_ROOT"
	exit "$status"
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
chmod -R a-w "$TEST_ROOT/seed/woocommerce-payments"
tar -czf "$TEST_ROOT/seed.tar.gz" -C "$TEST_ROOT/seed" woocommerce-payments
chmod -R u+w "$TEST_ROOT/seed/woocommerce-payments"
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

mkdir -p "$TEST_ROOT/mounts/core" "$TEST_ROOT/mounts/dev-tools"

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
		E2E_TRANSITION_WP_ENV_BIN="${E2E_TRANSITION_WP_ENV_BIN:-$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh}" \
		E2E_TRANSITION_REFERENCE_WP_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-reference-wp.sh" \
		E2E_TRANSITION_PNPM_BIN="$TEST_ROOT/poison-pnpm-must-not-run" \
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
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.reference_fixture_borrowed !== true ) process.exit( 1 );
	for ( const field of [
		"account_creation_attempted",
		"account_created",
		"account_deleted",
		"wpcom_blog_registration_attempted",
		"wpcom_blog_created",
		"wpcom_blog_deleted",
	] ) {
		if ( Object.hasOwn( state, field ) ) process.exit( 1 );
	}
' "$create_workspace/resource-state.json"
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
	if ( Object.hasOwn( config.mappings, "wp-content/plugins/wpcom-local-helper" ) ) process.exit( 1 );
' \
	"$create_workspace/store/.wp-env.json" \
	"$TEST_ROOT/mounts/core" \
	"$create_workspace" \
	"$TEST_ROOT/mounts/dev-tools"
grep -Fq "$create_workspace/wp-env-home" "$create_log"
grep -Fq $'reference-wp\t' "$create_log"
grep -Fq 'transition_inject_reference_fixture' "$create_log"
grep -Fq 'wc tool run install_pages' "$create_log"
grep -Fq 'option set woocommerce_woocommerce_payments_settings --format=json {"enabled":"yes","saved_cards":"yes"}' "$create_log"
test -f "$create_runtime/reference-fixture-injected"
if grep -Fq '<!-- wp:woocommerce/checkout /-->' "$create_log"; then
	echo 'Transition setup replaced the installed Checkout inner-block tree.' >&2
	exit 1
fi
if grep -Eq 'transition_register_blog|test-lab account create|wcpay callback probe' "$create_log"; then
	echo 'Transition create provisioned external resources instead of borrowing the reference fixture.' >&2
	exit 1
fi
if grep -RqE '77\\.real-(blog|user)-token' "$create_workspace" "$create_log"; then
	echo 'Transition setup persisted the borrowed Jetpack credentials.' >&2
	exit 1
fi

E2E_FAKE_ACCOUNT_RUNTIME=native_core run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
	destroy \
	--workspace "$create_workspace" \
	--rollback-receipt-file "$create_workspace/rollback-receipt"
if grep -Eq 'test-lab account delete|transition_delete_blog' "$create_log"; then
	echo 'Transition destroy mutated the borrowed reference fixture.' >&2
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
test -w "$create_workspace/seed/woocommerce-payments"
test -f "$create_runtime/account"
test ! -e "$create_runtime/wp-env"
test ! -e "$create_lease_path"
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).port_lease_released" "$create_workspace/resource-state.json")" = 'true'

echo 'provision-transition-store-real.sh reference fixture tests passed.'
