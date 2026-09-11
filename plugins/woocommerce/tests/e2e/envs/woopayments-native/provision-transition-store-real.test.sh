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
test ! -e "$state_writer_root/resource-state.json.lock"

mkdir "$state_writer_root/resource-state.json.lock"
touch -t 200001010000 "$state_writer_root/resource-state.json.lock"
state_before_stale_lock="$(< "$state_writer_root/resource-state.json")"
if node "$STATE_WRITER" "$state_writer_root/resource-state.json" phase must-not-land string \
	> /dev/null 2> "$state_writer_root/stale-lock-stderr"; then
	echo 'The state writer stole a stale transition state lock.' >&2
	exit 1
fi
grep -Fq 'is stale; a writer died mid-update' "$state_writer_root/stale-lock-stderr"
test "$(< "$state_writer_root/resource-state.json")" = "$state_before_stale_lock"
test -d "$state_writer_root/resource-state.json.lock"

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
source_only_canonical_hash="$(
	gzip -cd "$TEST_ROOT/source-only-seed.tar.gz" |
		shasum -a 256 |
		awk '{ print $1 }'
)"
node -e '
	const { writeFileSync } = require( "node:fs" );
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
		schema_version: 2,
		plugin: "woocommerce-payments",
		plugin_version: "10.5.0",
		source_commit: "a1f755fc903966387f8629f78f75976ac8d2016e",
		archive_sha256: process.argv[ 2 ],
		canonical_tar_sha256: process.argv[ 3 ],
		archive_profile: "git-sha1-fixed-pax+gzip-n9-v1",
		archive_format: "tar.gz",
		build_toolchain: {
			composer: "Composer version 2.8.6 2025-06-10 13:15:10",
			git: "git version 2.54.0",
			gzip: "Apple gzip 479",
			node: "v20.11.1",
			npm: "10.2.4",
			zlib: "1.2.13",
		},
		dependency_lock_sha256: "0".repeat( 64 ),
		production_package_count: 2,
		executable_seed_files: [
			"vendor/autoload_packages.php",
			"vendor/autoload.php",
			"vendor/composer/installed.php",
			"vendor/composer/installed.json",
		],
	} ) }\n` );
' \
	"$TEST_ROOT/source-only-seed.json" \
	"$source_only_seed_hash" \
	"$source_only_canonical_hash"
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
printf 'unselected canonical payload\n' > "$TEST_ROOT/seed/woocommerce-payments/payload.txt"
chmod -R a-w "$TEST_ROOT/seed/woocommerce-payments"
tar -czf "$TEST_ROOT/seed.tar.gz" -C "$TEST_ROOT/seed" woocommerce-payments
chmod -R u+w "$TEST_ROOT/seed/woocommerce-payments"
seed_hash="$(shasum -a 256 "$TEST_ROOT/seed.tar.gz" | awk '{ print $1 }')"
seed_canonical_hash="$(
	gzip -cd "$TEST_ROOT/seed.tar.gz" |
		shasum -a 256 |
		awk '{ print $1 }'
)"
dependency_lock_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/composer.lock" | awk '{ print $1 }')"
frontend_lock_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/package-lock.json" | awk '{ print $1 }')"
frontend_index_js_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/index.js" | awk '{ print $1 }')"
frontend_index_css_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/index.css" | awk '{ print $1 }')"
frontend_checkout_js_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/checkout.js" | awk '{ print $1 }')"
frontend_blocks_checkout_js_hash="$(shasum -a 256 "$TEST_ROOT/seed/woocommerce-payments/dist/blocks-checkout.js" | awk '{ print $1 }')"
node -e '
	const { writeFileSync } = require( "node:fs" );
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
		schema_version: 2,
		plugin: "woocommerce-payments",
		plugin_version: "10.5.0",
		source_commit: "a1f755fc903966387f8629f78f75976ac8d2016e",
		archive_sha256: process.argv[ 2 ],
		canonical_tar_sha256: process.argv[ 9 ],
		archive_profile: "git-sha1-fixed-pax+gzip-n9-v1",
		archive_format: "tar.gz",
		build_toolchain: {
			composer: "Composer version 2.8.6 2025-06-10 13:15:10",
			git: "git version 2.54.0",
			gzip: "Apple gzip 479",
			node: "v20.11.1",
			npm: "10.2.4",
			zlib: "1.2.13",
		},
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
	"$frontend_blocks_checkout_js_hash" \
	"$seed_canonical_hash"
export E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash"
export E2E_TRANSITION_TEST_MODE=1
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

# A profile names both artifact identities, so a manifest from either profile
# cannot be paired with the other profile before the run workspace is touched.
for rejected_profile in unknown 10.4.0; do
	profile_workspace="$TEST_ROOT/profile-$rejected_profile-workspace"
	mkdir "$profile_workspace"
	if env E2E_TRANSITION_PORT=19091 E2E_TRANSITION_SEED_PROFILE="$rejected_profile" \
		"$PROVISIONER" plan \
		--workspace "$profile_workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "profile-${rejected_profile//./-}" > /dev/null 2>&1; then
		echo "Plan accepted a mismatched immutable profile: $rejected_profile." >&2
		exit 1
	fi
	test ! -n "$(find "$profile_workspace" -mindepth 1 -print -quit)"
done

make_invalid_seed_manifest() {
	local variant="$1"
	local variant_manifest="$TEST_ROOT/$variant-seed.json"
	node -e '
		const { readFileSync, writeFileSync } = require( "node:fs" );
		const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		switch ( process.argv[ 3 ] ) {
			case "schema-one":
				manifest.schema_version = 1;
				break;
			case "missing-canonical":
				delete manifest.canonical_tar_sha256;
				break;
			case "malformed-canonical":
				manifest.canonical_tar_sha256 = "not-a-sha256";
				break;
			case "wrong-canonical":
				manifest.canonical_tar_sha256 = "0".repeat( 64 );
				break;
			case "unsupported-profile":
				manifest.archive_profile = "unsupported-profile";
				break;
			case "missing-toolchain":
				delete manifest.build_toolchain;
				break;
			case "malformed-toolchain":
				manifest.build_toolchain.extra = "not-an-observed-tool";
				break;
			default:
				process.exit( 2 );
		}
		writeFileSync( process.argv[ 2 ], `${ JSON.stringify( manifest ) }\n` );
	' "$TEST_ROOT/seed.json" "$variant_manifest" "$variant"
	chmod 0444 "$variant_manifest"
}

for invalid_manifest in \
	schema-one \
	missing-canonical \
	malformed-canonical \
	wrong-canonical \
	unsupported-profile \
	missing-toolchain \
	malformed-toolchain; do
	make_invalid_seed_manifest "$invalid_manifest"
done

tampered_tree="$TEST_ROOT/unselected-tampered-tree"
cp -R "$TEST_ROOT/seed" "$tampered_tree"
printf 'tampered outside selected validation files\n' > \
	"$tampered_tree/woocommerce-payments/payload.txt"
tar -czf "$TEST_ROOT/unselected-tampered-seed.tar.gz" \
	-C "$tampered_tree" woocommerce-payments
tampered_archive_hash="$(
	shasum -a 256 "$TEST_ROOT/unselected-tampered-seed.tar.gz" |
		awk '{ print $1 }'
)"
node -e '
	const { readFileSync, writeFileSync } = require( "node:fs" );
	const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	manifest.archive_sha256 = process.argv[ 3 ];
	writeFileSync( process.argv[ 2 ], `${ JSON.stringify( manifest ) }\n` );
' \
	"$TEST_ROOT/seed.json" \
	"$TEST_ROOT/unselected-tampered-seed.json" \
	"$tampered_archive_hash"
chmod 0444 \
	"$TEST_ROOT/unselected-tampered-seed.tar.gz" \
	"$TEST_ROOT/unselected-tampered-seed.json"

assert_seed_rejected_before_workspace_mutation() {
	local variant="$1"
	local archive="$2"
	local manifest="$3"
	local before_rejection
	before_rejection="$(find "$workspace" -mindepth 1 -print)"
	if env E2E_TRANSITION_PORT=19091 \
		"$PROVISIONER" plan \
		--workspace "$workspace" \
		--seed-archive "$archive" \
		--seed-manifest "$manifest" \
		--run-id "$variant" > /dev/null 2>&1; then
		echo "Plan accepted the invalid transition seed: $variant." >&2
		exit 1
	fi
	if [[ "$(find "$workspace" -mindepth 1 -print)" != "$before_rejection" ]]; then
		echo "Seed validation mutated the workspace before rejecting: $variant." >&2
		exit 1
	fi
}

for invalid_manifest in \
	schema-one \
	missing-canonical \
	malformed-canonical \
	wrong-canonical \
	unsupported-profile \
	missing-toolchain \
	malformed-toolchain; do
	assert_seed_rejected_before_workspace_mutation \
		"$invalid_manifest" \
		"$TEST_ROOT/seed.tar.gz" \
		"$TEST_ROOT/$invalid_manifest-seed.json"
done
assert_seed_rejected_before_workspace_mutation \
	'unselected-tampered' \
	"$TEST_ROOT/unselected-tampered-seed.tar.gz" \
	"$TEST_ROOT/unselected-tampered-seed.json"

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
	local variant_canonical_hash
	variant_canonical_hash="$(
		gzip -cd "$variant_archive" |
			shasum -a 256 |
			awk '{ print $1 }'
	)"
	local variant_lock_hash
	variant_lock_hash="$(shasum -a 256 "$variant_tree/woocommerce-payments/package-lock.json" | awk '{ print $1 }')"
	node -e '
		const { readFileSync, writeFileSync } = require( "node:fs" );
		const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		manifest.archive_sha256 = process.argv[ 3 ];
		manifest.canonical_tar_sha256 = process.argv[ 6 ];
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
		"$variant" \
		"$variant_canonical_hash"
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

# Concurrent state writers must serialize: without a lock, two writers
# read the same snapshot and one key's update is lost.
concurrent_state="$TEST_ROOT/concurrent-state.json"
printf '{}\n' > "$concurrent_state"
chmod 600 "$concurrent_state"
for i in $(seq 1 20); do
	node "$STATE_WRITER" "$concurrent_state" alpha "a$i" &
	node "$STATE_WRITER" "$concurrent_state" beta "b$i" &
	wait
done
node -e '
	const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
	if ( state.alpha !== "a20" || state.beta !== "b20" ) {
		console.error( "Lost transition state update:", JSON.stringify( state ) );
		process.exit( 1 );
	}
' "$concurrent_state"
test ! -e "$concurrent_state.lock"

# Destroy is idempotent after completion: the phase gate answers before
# the claim, and wp-env destroy does not run a second time.
E2E_FAKE_ACCOUNT_RUNTIME=native_core run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
	destroy \
	--workspace "$create_workspace" \
	--rollback-receipt-file "$create_workspace/rollback-receipt"
test "$(grep -Fc $'\tdestroy --force' "$create_log")" = '1'

# A pre-existing destroy claim on an undestroyed workspace fails closed.
node "$STATE_WRITER" "$create_workspace/resource-state.json" phase created
node "$STATE_WRITER" "$create_workspace/resource-state.json" wp_env_destroyed false boolean
claim_stderr="$TEST_ROOT/destroy-claim-stderr"
if E2E_FAKE_ACCOUNT_RUNTIME=native_core run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
	destroy \
	--workspace "$create_workspace" \
	--rollback-receipt-file "$create_workspace/rollback-receipt" 2> "$claim_stderr"; then
	echo 'Transition destroy proceeded despite an existing destroy claim.' >&2
	exit 1
fi
grep -Fq 'Transition destroy claim already exists' "$claim_stderr" "$create_log"
test "$(grep -Fc $'\tdestroy --force' "$create_log")" = '1'

# Execute the real create path; only the external WordPress/HTTP boundary is fake.
# The PHP snippets themselves run, including their state/action guards and updates.
mkdir "$TEST_ROOT/network-bin"
node - "$TEST_ROOT/network-bin" <<'JS'
const { writeFileSync } = require( 'node:fs' );
const root = process.argv[ 2 ];
writeFileSync( `${ root }/wp-env`, `#!/usr/bin/env node
const fs = require('node:fs');
const cp = require('node:child_process');
const args = process.argv.slice(2);
const command = args.join(' ');
const path = process.env.E2E_FAKE_NETWORK_STATE;
const log = process.env.E2E_FAKE_NETWORK_COMMAND_LOG;
fs.appendFileSync(log, JSON.stringify(args) + '\\n');
if (/do_action|action-scheduler\\s+(run|execute)|ActionScheduler_.*Runner|action_scheduler_run/.test(command)) throw Error('CLI callback execution is forbidden');
const network = /multisite-convert|site create|site list|--network|sub_transition_network_marker|"transition-network-(mixed|reopened)"|transition_network_secondary_fixture|woocommerce_woopayments_cutover_state|_wcpay_subscription_id|wcpay_migrate_subscription_retry/.test(command);
if (!network) {
 const result = cp.spawnSync(process.env.E2E_FAKE_NETWORK_FALLBACK, args, {stdio:'inherit'});
 if (result.status === 0 && args.some(arg => /^--url=.*\\/cutover-secondary$/.test(arg))) {
  const state = JSON.parse(fs.readFileSync(path));
  if (!state.active || !state.coreActive) throw Error('Secondary configured before network plugins');
  if (command.includes('local_wpcom_jetpack enable http://wpcom.localhost:8080')) state.secondaryLocal = true;
  if (command.includes('wcpay_dev redirect_to http://wpcom.localhost:8080')) state.secondaryRedirect = true;
  if (command.includes('transition_inject_reference_fixture')) state.secondaryInjected = true;
  if (command.includes('wcpay_dev refresh_account_data')) state.secondaryRefreshed = true;
  if (command.includes('woocommerce_woocommerce_payments_settings') && command.includes('"enabled":"yes"') && command.includes('"saved_cards":"yes"')) state.secondarySettings = true;
  fs.writeFileSync(path, JSON.stringify(state));
 }
 process.exit(result.status ?? 1);
}
let state = fs.existsSync(path) ? JSON.parse(fs.readFileSync(path)) : {http:0, due:[], events:[], generation:41};
if (command.includes('core multisite-convert')) { state.converted = true; }
else if (command.includes('site create')) { if (!state.converted || !args.includes('--slug=cutover-secondary')) throw Error('Network conversion or secondary slug missing'); state.secondary = 9; console.log(9); }
else if (command.includes('site list')) { console.log(3); }
else if (command.includes('plugin activate woocommerce --network')) { state.coreActive = true; }
else if (command.includes('plugin activate woocommerce-payments --network')) { if (state.secondary !== 9 || !state.coreActive) throw Error('Secondary site must load WooCommerce before network WooPayments'); state.active = true; }
else if (command.includes('post meta delete')) { if (state.http !== 4 || !command.includes('701 _wcpay_subscription_id')) throw Error('Marker removed before exclusion'); state.marker = false; }
else {
 const index = args.indexOf('eval');
 if (index < 0) throw Error('Unexpected network command: ' + command);
 const result = cp.spawnSync('php', ['-r', 'require getenv("E2E_FAKE_NETWORK_PHP"); eval($argv[1]);', args[index + 1]], {stdio:'inherit', env:{...process.env, E2E_FAKE_NETWORK_SITE_ID:args.some(arg => /^--url=.*\\/cutover-secondary$/.test(arg)) ? '9' : '3'}});
 process.exit(result.status ?? 1);
}
fs.writeFileSync(path, JSON.stringify(state));
`, { mode: 0o755 } );
writeFileSync( `${ root }/curl`, `#!/usr/bin/env node
const fs = require('node:fs');
const path = process.env.E2E_FAKE_NETWORK_STATE;
const state = JSON.parse(fs.readFileSync(path));
const url = process.argv.at(-1);
fs.appendFileSync(process.env.E2E_FAKE_NETWORK_CURL_LOG, url + '\\n');
const base = JSON.parse(fs.readFileSync(process.env.E2E_FAKE_TRANSITION_WORKSPACE + '/resource-state.json')).base_url;
const sites = [9,3,3,9,3,9];
const site = sites[Math.floor(state.http / 2)];
const siteUrl = base + (site === 9 ? '/cutover-secondary' : '');
const expected = state.http % 2 ? siteUrl + '/wp-admin/admin-ajax.php?action=as_async_request_queue_runner' : siteUrl + '/wp-cron.php?doing_wp_cron=';
if (!site || (state.http % 2 ? url !== expected : !url.startsWith(expected) || !/^[0-9]+N?$/.test(url.slice(expected.length)))) throw Error('Wrong HTTP dispatch order: ' + url);
if (state.http < 4 && (!state.mixed || !state.marker)) throw Error('Mixed generation not seeded');
if (!state.secondaryLocal || !state.secondaryRedirect || !state.secondaryInjected || !state.secondaryRefreshed || !state.secondarySettings || !state.secondaryValidated) throw Error('Secondary site lacks its verified local reference fixture');
if (state.http >= 4 && (!state.reopened || state.marker)) throw Error('Generation not reopened');
if (state.http >= 8 && !state.due.includes(site)) throw Error('Ownership verification action is still scheduled in the future');
state.http++;
if (state.http === 8) {
 state.active = false;
 state.actions = {};
 for (const id of [3,9]) state.actions[id] = {action_id:900 + id, hook:'woocommerce_woopayments_cutover_reconcile', status:'pending', args:JSON.stringify({generation:42,attempt:2}), group_id:7, group_slug:'woocommerce_woopayments_cutover', scheduled_date_gmt:'2099-01-01 00:00:00', scheduled_date_local:'2099-01-01 03:00:00'};
 if (process.env.E2E_FAKE_NETWORK_FAULT === 'action-args') state.actions[3].args = JSON.stringify({generation:41,attempt:2});
 if (process.env.E2E_FAKE_NETWORK_FAULT === 'action-hook') state.actions[3].hook = 'unrelated_action';
 if (process.env.E2E_FAKE_NETWORK_FAULT === 'action-group') state.actions[3].group_slug = 'unrelated_group';
 if (process.env.E2E_FAKE_NETWORK_FAULT === 'action-status') state.actions[3].status = 'in-progress';
}
fs.writeFileSync(path, JSON.stringify(state));
`, { mode: 0o755 } );
JS
node - "$TEST_ROOT/network-bin/runtime.php" <<'JS'
require( 'node:fs' ).writeFileSync( process.argv[ 2 ], String.raw`<?php
$fixture_path = getenv( 'E2E_FAKE_NETWORK_STATE' );
$fixture = file_exists( $fixture_path ) ? json_decode( file_get_contents( $fixture_path ), true ) : array( 'http' => 0, 'due' => array(), 'events' => array(), 'generation' => 41 );
$blog_id = (int) getenv( 'E2E_FAKE_NETWORK_SITE_ID' );
$blog_stack = array();
register_shutdown_function( function() { global $fixture_path, $fixture; file_put_contents( $fixture_path, json_encode( $fixture ) ); } );
const HOUR_IN_SECONDS = 3600;
class WP_CLI { public static function error( $message ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function get_current_blog_id() { global $blog_id; return $blog_id; }
class Jetpack_Options {
 public static function get_option( $key ) {
  global $fixture, $blog_id;
  if ( $blog_id !== 9 || empty( $fixture['secondaryInjected'] ) ) throw new Exception( 'Secondary fixture not injected' );
  $fixture['secondaryValidated'] = true;
  return array( 'id' => 77, 'blog_token' => '77.real-blog-token', 'user_tokens' => array( 1 => '77.real-user-token.1' ) )[ $key ] ?? null;
 }
}
class WC_Payments {
 public static function get_account_service() { return new self(); }
 public function get_cached_account_data() { global $fixture; if ( empty( $fixture['secondaryRefreshed'] ) ) throw new Exception( 'Secondary account not refreshed' ); return array( 'account_id' => 'acct_transition_77', 'is_live' => false ); }
}
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_sites( $args ) { global $fixture; return $fixture['http'] === 12 && getenv( 'E2E_FAKE_NETWORK_FAULT' ) === 'final-duplicate' ? array( 3, 3 ) : array( 9, 3 ); }
function switch_to_blog( $id ) { global $blog_id, $blog_stack, $wpdb; $blog_stack[] = $blog_id; $blog_id = $id; $wpdb->prefix = 'wp_' . $id . '_'; }
function restore_current_blog() { global $blog_id, $blog_stack, $wpdb; $blog_id = array_pop( $blog_stack ); $wpdb->prefix = 'wp_' . $blog_id . '_'; }
function is_plugin_active_for_network( $plugin ) { global $fixture; return $fixture['http'] === 12 && getenv( 'E2E_FAKE_NETWORK_FAULT' ) === 'final-active' ? true : $fixture['active']; }
function get_option( $key, $default = null ) {
 global $fixture, $blog_id;
 if ( $key !== 'woocommerce_woopayments_cutover_state' ) throw new Exception( 'Unexpected option' );
 $http = $fixture['http'];
 $record = array( 'generation' => $fixture['generation'], 'state' => 'pending', 'current_step' => '', 'deferred_codes' => array(), 'exclusion_codes' => array(), 'attempt' => 1, 'action_id' => 900 + $blog_id );
 if ( $http === 2 && $blog_id === 9 ) { $record['state'] = 'deferred'; $record['current_step'] = 'network_barrier'; $record['deferred_codes'] = array( 'network_barrier' ); }
 elseif ( $http === 4 ) { $record['state'] = 'excluded'; $record['current_step'] = 'legacy_stripe_billing_subscriptions_present'; $record['exclusion_codes'] = array( 'legacy_stripe_billing_subscriptions_present' ); }
 elseif ( $http >= 8 ) { $record['current_step'] = 'verify_native_ownership'; if ( $http >= ( $blog_id === 3 ? 10 : 12 ) ) { $record['state'] = 'done'; $record['current_step'] = 'done'; } }
 if ( $http === 12 && $blog_id === 9 && getenv( 'E2E_FAKE_NETWORK_FAULT' ) === 'final-generation' ) $record['generation'] = 43;
 if ( $http === 8 && getenv( 'E2E_FAKE_NETWORK_FAULT' ) === 'state-generation' ) $record['generation'] = 41;
 return $record;
}
function as_schedule_single_action( $timestamp, $hook ) {
 global $fixture;
 if ( $hook !== 'wcpay_migrate_subscription_retry' || $timestamp <= time() ) throw new Exception( 'Wrong migrator seed' );
 $fixture['migrator'] = 811;
 return 811;
}
class FixtureOrder {
 public function update_meta_data( $key, $value ) { global $fixture; if ( $key !== '_wcpay_subscription_id' || $value !== 'sub_transition_network_marker' ) throw new Exception( 'Wrong marker' ); $fixture['marker'] = true; }
 public function save() {}
 public function get_id() { return 701; }
}
function wc_create_order() { return new FixtureOrder(); }
class FixtureJob {
 public function get( $class ) { if ( $class !== 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob' ) throw new Exception( 'Wrong job' ); return $this; }
 public function enqueue( $source ) {
  global $fixture;
  if ( $source === 'transition-network-mixed' && ! empty( $fixture['marker'] ) && ! empty( $fixture['active'] ) ) { $fixture['mixed'] = true; return true; }
  if ( $source === 'transition-network-reopened' && $fixture['http'] === 4 && ! $fixture['marker'] ) { $fixture['reopened'] = true; $fixture['generation'] = 42; return true; }
  return false;
 }
}
function wc_get_container() { return new FixtureJob(); }
function get_date_from_gmt( $date ) { return gmdate( 'Y-m-d H:i:s', strtotime( $date . ' UTC' ) + 10800 ); }
class FixtureDatabase {
 public $prefix = 'wp_3_';
 public function prepare( $query, ...$args ) { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $query, 1 ); return $query; }
 public function get_row( $query ) {
  global $fixture, $blog_id;
  if ( ! str_contains( $query, $this->prefix . 'actionscheduler_actions' ) || ! str_contains( $query, $this->prefix . 'actionscheduler_groups' ) || ! preg_match( '/action_id\s*=\s*' . ( 900 + $blog_id ) . '\b/', $query ) ) throw new Exception( 'Action lookup lost exact site/ID/group' );
  $fixture['events'][] = 'inspect:' . $blog_id;
  return isset( $fixture['actions'][ $blog_id ] ) ? (object) $fixture['actions'][ $blog_id ] : null;
 }
 public function update( $table, $data, $where, $format = null, $where_format = null ) {
  global $fixture, $blog_id;
  $row = $fixture['actions'][ $blog_id ];
  if ( $table !== $this->prefix . 'actionscheduler_actions' || array_keys( $data ) !== array( 'scheduled_date_gmt', 'scheduled_date_local' ) || strtotime( $data['scheduled_date_gmt'] . ' UTC' ) >= time() || get_date_from_gmt( $data['scheduled_date_gmt'] ) !== $data['scheduled_date_local'] ) throw new Exception( 'Update must only make the existing action due in both timezones' );
  foreach ( array( 'action_id', 'hook', 'status', 'args', 'group_id' ) as $key ) if ( ! array_key_exists( $key, $where ) || (string) $where[ $key ] !== (string) $row[ $key ] ) throw new Exception( 'Update lost action identity: ' . $key );
  $fixture['actions'][ $blog_id ] = array_merge( $row, $data );
  $fixture['due'][] = $blog_id;
  $fixture['events'][] = 'due:' . $blog_id;
  return 1;
 }
}
$wpdb = new FixtureDatabase();
` );
JS

network_workspace="$TEST_ROOT/network-create"
mkdir "$network_workspace"
export E2E_FAKE_NETWORK_STATE="$TEST_ROOT/network-state.json"
export E2E_FAKE_NETWORK_COMMAND_LOG="$TEST_ROOT/network-commands.jsonl"
export E2E_FAKE_NETWORK_CURL_LOG="$TEST_ROOT/network-curl.log"
export E2E_FAKE_NETWORK_PHP="$TEST_ROOT/network-bin/runtime.php"
export E2E_FAKE_NETWORK_FALLBACK="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh"
if ! E2E_TRANSITION_SCENARIO=cutover-network-reconciliation E2E_TRANSITION_PENDING_MIGRATOR_HOOK=1 \
	E2E_TRANSITION_WP_ENV_BIN="$TEST_ROOT/network-bin/wp-env" E2E_TRANSITION_CURL_BIN="$TEST_ROOT/network-bin/curl" \
	E2E_TRANSITION_PORT=19119 run_provisioner "$network_workspace" "$TEST_ROOT/network-runtime" "$TEST_ROOT/network-ordinary.log" \
	create --workspace "$network_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" \
	--run-id network-create --base-url http://transition-network-create.localhost:19119 --store-id woopayments-native-transition-network-create \
	> "$TEST_ROOT/network-result.json"; then
	echo 'Network create failed before fresh HTTP ownership verification completed.' >&2
	exit 1
fi
node - "$TEST_ROOT/network-result.json" "$network_workspace/resource-state.json" <<'JS'
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const result = JSON.parse( fs.readFileSync( process.argv[ 2 ] ) );
const durable = JSON.parse( fs.readFileSync( process.argv[ 3 ] ) );
assert.equal( result.pending_migrator_hook, 'wcpay_migrate_subscription_retry' );
assert.equal( result.pending_migrator_action_id, 811 );
assert.equal( result.network_primary_site_id, 3 );
assert.equal( result.network_secondary_site_id, 9 );
const mixed = result.network_mixed_site_states;
assert.equal( mixed.length, 2 );
assert.deepEqual( mixed.map( s => s.site_id ).sort(), [ 3, 9 ] );
assert.equal( mixed.find( s => s.site_id === 3 ).state, 'pending' );
const secondary = mixed.find( s => s.site_id === 9 );
assert.equal( secondary.state, 'deferred' );
assert.equal( secondary.current_step, 'network_barrier' );
assert.deepEqual( secondary.deferred_codes, [ 'network_barrier' ] );
assert.ok( mixed.every( s => s.generation === 41 ) );
const excluded = result.network_excluded_site_states;
assert.equal( excluded.network_active, true );
assert.deepEqual( excluded.states.map( s => s.site_id ).sort(), [ 3, 9 ] );
assert.ok( excluded.states.every( s => s.state === 'excluded' && s.generation === 41 && s.current_step === 'legacy_stripe_billing_subscriptions_present' && s.exclusion_codes.includes( 'legacy_stripe_billing_subscriptions_present' ) ) );
const final = result.network_final_site_states;
assert.equal( final.network_active, false );
assert.deepEqual( final.states.map( s => s.site_id ).sort(), [ 3, 9 ] );
assert.ok( final.states.every( s => s.state === 'done' && s.generation === 42 && s.generation > 41 ) );
for ( const key of Object.keys( result ).filter( key => /^(network_|pending_migrator_)/.test( key ) ) ) {
 const value = typeof durable[ key ] === 'string' && key.endsWith( '_states' ) ? JSON.parse( durable[ key ] ) : durable[ key ];
 assert.deepEqual( value, result[ key ], 'Durable evidence differs: ' + key );
}
const urls = fs.readFileSync( process.env.E2E_FAKE_NETWORK_CURL_LOG, 'utf8' ).trim().split( '\n' );
const base = 'http://transition-network-create.localhost:19119';
assert.deepEqual( urls.map( url => url.replace( /doing_wp_cron=[0-9]+N?$/, 'doing_wp_cron=TIME' ) ),
 [ '/cutover-secondary', '', '', '/cutover-secondary', '', '/cutover-secondary' ].flatMap( site => [ base + site + '/wp-cron.php?doing_wp_cron=TIME', base + site + '/wp-admin/admin-ajax.php?action=as_async_request_queue_runner' ] ) );
const commands = fs.readFileSync( process.env.E2E_FAKE_NETWORK_COMMAND_LOG, 'utf8' ).trim().split( '\n' ).map( line => JSON.parse( line ).join( ' ' ) ).join( '\n' );
for ( const pattern of [ 'core multisite-convert', 'site create', 'transition-network-mixed', 'transition-network-reopened', 'woocommerce_woopayments_cutover_state', 'scheduled_date_gmt', 'scheduled_date_local' ] ) assert.ok( commands.includes( pattern ), 'Missing executed command: ' + pattern );
assert.doesNotMatch( commands, /do_action|action-scheduler\s+(run|execute)|ActionScheduler_.*Runner|action_scheduler_run/ );
assert.ok( commands.indexOf( 'plugin activate woocommerce --network' ) >= 0 );
assert.ok( commands.indexOf( 'plugin activate woocommerce --network' ) < commands.indexOf( 'plugin activate woocommerce-payments --network' ) );
const secondaryCommands = fs.readFileSync( process.env.E2E_FAKE_NETWORK_COMMAND_LOG, 'utf8' ).trim().split( '\n' ).map( line => JSON.parse( line ).join( ' ' ) ).filter( command => command.includes( '--url=http://transition-network-create.localhost:19119/cutover-secondary' ) ).join( '\n' );
for ( const command of [ 'local_wpcom_jetpack enable', 'wcpay_dev redirect_to', 'transition_inject_reference_fixture', 'wcpay_dev refresh_account_data', 'woocommerce_woocommerce_payments_settings', 'transition_network_secondary_fixture' ] ) assert.ok( secondaryCommands.includes( command ), 'Missing secondary fixture command: ' + command );
const runtime = JSON.parse( fs.readFileSync( process.env.E2E_FAKE_NETWORK_STATE ) );
assert.equal( runtime.http, 12 );
assert.deepEqual( runtime.due.sort(), [ 3, 9 ] );
for ( const site of [ 3, 9 ] ) assert.ok( runtime.events.indexOf( 'inspect:' + site ) < runtime.events.indexOf( 'due:' + site ) );
JS

for fault in final-duplicate final-generation final-active action-args action-hook action-group action-status state-generation; do
	fault_workspace="$TEST_ROOT/network-$fault"
	mkdir "$fault_workspace"
	export E2E_FAKE_NETWORK_STATE="$TEST_ROOT/network-$fault-state.json"
	export E2E_FAKE_NETWORK_COMMAND_LOG="$TEST_ROOT/network-$fault-commands.jsonl"
	export E2E_FAKE_NETWORK_CURL_LOG="$TEST_ROOT/network-$fault-curl.log"
	if E2E_FAKE_NETWORK_FAULT="$fault" E2E_TRANSITION_SCENARIO=cutover-network-reconciliation E2E_TRANSITION_PENDING_MIGRATOR_HOOK=1 \
		E2E_TRANSITION_WP_ENV_BIN="$TEST_ROOT/network-bin/wp-env" E2E_TRANSITION_CURL_BIN="$TEST_ROOT/network-bin/curl" \
		E2E_TRANSITION_PORT=19120 run_provisioner "$fault_workspace" "$TEST_ROOT/network-$fault-runtime" "$TEST_ROOT/network-$fault-ordinary.log" \
		create --workspace "$fault_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" \
		--run-id "network-$fault" --base-url "http://transition-network-$fault.localhost:19120" --store-id "woopayments-native-transition-network-$fault" \
		> "$TEST_ROOT/network-$fault-result.json" 2> "$TEST_ROOT/network-$fault-stderr"; then
		echo "Network create accepted invalid evidence: $fault." >&2
		exit 1
	fi
	node - "$TEST_ROOT/network-$fault-result.json" "$fault" "$fault_workspace/resource-state.json" <<'JS'
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const output = fs.readFileSync( process.argv[ 2 ], 'utf8' ).trim();
const result = output ? JSON.parse( output ) : {};
const durable = JSON.parse( fs.readFileSync( process.argv[ 4 ] ) );
const runtime = JSON.parse( fs.readFileSync( process.env.E2E_FAKE_NETWORK_STATE ) );
assert.equal( durable.phase, 'create-failed' );
if ( output ) assert.equal( result.status, 'failed' );
assert.equal( runtime.http, process.argv[ 3 ].startsWith( 'final-' ) ? 12 : 8 );
assert.equal( result.network_final_site_states, undefined );
assert.equal( durable.network_final_site_states, undefined );
if ( ! process.argv[ 3 ].startsWith( 'final-' ) ) assert.deepEqual( runtime.due, [] );
JS
	run_provisioner "$fault_workspace" "$TEST_ROOT/network-$fault-runtime" "$TEST_ROOT/network-$fault-ordinary.log" \
		destroy --workspace "$fault_workspace" --rollback-receipt-file "$fault_workspace/rollback-receipt"
done

# Supplemental source checks guard the callback execution boundary.
# These are deliberately checked as a production-path contract: WP-CLI seeds
# and inspects Action Scheduler state, while only HTTP cron/async dispatches
# the callbacks. Removing any scheduling, receipt, or state fence breaks this
# suite before a browser rehearsal can hide it.
grep -Fq 'wcpay_migrate_subscription_retry' "$PROVISIONER"
grep -Fq 'pending_migrator_action_id' "$PROVISIONER"
grep -Fq 'core multisite-convert' "$PROVISIONER"
grep -Fq 'site create' "$PROVISIONER"
grep -Fq 'transition-network-mixed' "$PROVISIONER"
grep -Fq 'transition-network-reopened' "$PROVISIONER"
grep -Fq 'wp-cron.php?doing_wp_cron=' "$PROVISIONER"
grep -Fq 'as_async_request_queue_runner' "$PROVISIONER"
grep -Fq 'network_primary_site_id' "$PROVISIONER"
grep -Fq 'network_secondary_site_id' "$PROVISIONER"
grep -Fq 'network_final_site_states' "$PROVISIONER"
if grep -Eq 'action-scheduler[[:space:]]+(run|execute)|action_scheduler_run' "$PROVISIONER"; then
	echo 'The network rehearsal must not execute Action Scheduler callbacks through WP-CLI.' >&2
	exit 1
fi

echo 'provision-transition-store-real.sh reference fixture tests passed.'
