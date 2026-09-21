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

classic_checkout_boundary_workspace="$TEST_ROOT/classic-checkout-boundary"
classic_checkout_boundary_runtime="$TEST_ROOT/classic-checkout-boundary-runtime"
classic_checkout_boundary_log="$TEST_ROOT/classic-checkout-boundary.log"
mkdir -p "$classic_checkout_boundary_workspace/store" "$classic_checkout_boundary_runtime"
printf '{"testsEnvironment":false}\n' > "$classic_checkout_boundary_workspace/store/.wp-env.json"
touch "$classic_checkout_boundary_runtime/wp-env"
(
	cd "$classic_checkout_boundary_workspace/store"
	env \
		E2E_FAKE_TRANSITION_WORKSPACE="$classic_checkout_boundary_workspace" \
		E2E_FAKE_RUNTIME_STATE="$classic_checkout_boundary_runtime" \
		E2E_FAKE_COMMAND_LOG="$classic_checkout_boundary_log" \
		WP_ENV_HOME="$classic_checkout_boundary_workspace/wp-env-home" \
		"$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		run cli wp eval '/* transition_prepare_classic_checkout */'
)
if (
	cd "$classic_checkout_boundary_workspace/store"
	env \
		E2E_FAKE_TRANSITION_WORKSPACE="$classic_checkout_boundary_workspace" \
		E2E_FAKE_RUNTIME_STATE="$classic_checkout_boundary_runtime" \
		E2E_FAKE_COMMAND_LOG="$classic_checkout_boundary_log" \
		WP_ENV_HOME="$classic_checkout_boundary_workspace/wp-env-home" \
		"$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		run cli wp eval '/* transition_prepare_classic_checkout */' unexpected-argument
); then
	echo 'Fake transition wp-env accepted a malformed Classic Checkout preparation command.' >&2
	exit 1
fi

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
		frontend_lock_file: "package-lock.json",
		frontend_package_manager: "npm",
		frontend_package_manager_declaration: "",
		frontend_package_manager_version: "10.2.4",
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
	if ( "optional_artifacts" in plan ) process.exit( 1 );
' "$plan"

# A tiny canonical artifact exercises the same bytes and manifest contract as the release.
python3 - "$TEST_ROOT" <<'PY'
import gzip, hashlib, io, json, pathlib, tarfile
root = pathlib.Path(__import__('sys').argv[1])
slug = 'woocommerce-subscriptions'
packages = {
    'automattic/jetpack-constants': {'version': 'v3.0.12', 'source_reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27', 'dist_reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27'},
    'composer/installers': {'version': 'v2.3.0', 'source_reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e', 'dist_reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e'},
}
files = {slug + '/' + slug + '.php': b'<?php\n/**\n * Plugin Name: WooCommerce Subscriptions\n * Version: 9.2.0\n */\n',
    slug + '/vendor/composer/installed.json': json.dumps({'packages': [dict(name=name, version=p['version'], source={'reference': p['source_reference']}, dist={'reference': p['dist_reference']}) for name, p in packages.items()]}).encode()}
sha = lambda value: hashlib.sha256(value).hexdigest()
def artifact(name, mutation=None):
    content = files.copy()
    if mutation == 'header': content[slug + '/' + slug + '.php'] = content[slug + '/' + slug + '.php'].replace(b'9.2.0', b'9.1.0')
    if mutation in ('escape', 'absolute', 'git', 'root', 'symlink', 'hardlink', 'fifo', 'duplicate'):
        content[{'escape': slug + '/../escaped', 'absolute': '/escaped', 'git': slug + '/.git/config', 'root': 'other/file', 'symlink': slug + '/link', 'hardlink': slug + '/hard', 'fifo': slug + '/fifo', 'duplicate': slug + '/duplicate'}[mutation]] = b'bad'
    data = io.BytesIO()
    records = []
    with tarfile.open(fileobj=data, mode='w', format=tarfile.PAX_FORMAT) as tar:
        directories = {slug, slug + '/vendor', slug + '/vendor/composer'}
        for path in sorted(directories | set(content)):
            info = tarfile.TarInfo(path); info.mtime = 1788854213; info.mode = 0o555 if path in directories else 0o444
            if path in directories: info.type = tarfile.DIRTYPE; tar.addfile(info)
            else:
                if path.endswith('/link'): info.type = tarfile.SYMTYPE; info.linkname = '/outside'
                elif path.endswith('/hard'): info.type = tarfile.LNKTYPE; info.linkname = slug + '/' + slug + '.php'
                elif path.endswith('/fifo'): info.type = tarfile.FIFOTYPE
                info.size = len(content[path]) if info.isreg() else 0
                tar.addfile(info, io.BytesIO(content[path]))
                if mutation == 'duplicate' and path.endswith('/duplicate'): tar.addfile(info, io.BytesIO(content[path]))
                records.append(sha(content[path]) + '  ' + path + '\n')
    canonical = data.getvalue()
    zipped = io.BytesIO()
    with gzip.GzipFile(filename='', fileobj=zipped, mode='wb', compresslevel=9, mtime=0) as stream: stream.write(canonical)
    manifest = {'schema_version': 1, 'plugin': slug, 'plugin_version': '9.2.0', 'main_file': slug + '.php', 'main_file_version': '9.2.0',
        'source_commit': '4008f7f515f5ea76eea4d9149514b8c1774e51ba', 'source_date_epoch': 1788854213,
        'archive_format': 'tar.gz', 'archive_profile': 'wcs-transition-v1', 'production_composer_packages': packages,
        'archive_sha256': sha(zipped.getvalue()), 'canonical_tar_sha256': sha(canonical), 'canonical_tree_sha256': sha(''.join(records).encode()), 'file_count': len(records)}
    if mutation in ('plugin', 'plugin_version', 'source_commit', 'archive_profile', 'main_file', 'main_file_version'): manifest[mutation] = 'wrong'
    if mutation in ('source_date_epoch', 'file_count'): manifest[mutation] = 1
    if mutation in ('archive_sha256', 'canonical_tar_sha256', 'canonical_tree_sha256'): manifest[mutation] = '0' * 64
    if mutation and mutation.startswith('packages-'):
        manifest['production_composer_packages'] = json.loads(json.dumps(packages))
        if mutation == 'packages-missing': del manifest['production_composer_packages']['composer/installers']
        elif mutation == 'packages-extra': manifest['production_composer_packages']['extra/package'] = packages['composer/installers']
        else: manifest['production_composer_packages']['composer/installers']['dist_reference'] = '0' * 40
    for suffix, value in [('.tar.gz', zipped.getvalue()), ('.json', json.dumps(manifest).encode())]:
        path = root / (name + suffix); path.write_bytes(value); path.chmod(0o444)
artifact('wcs')
for mutation in ['header', 'escape', 'absolute', 'git', 'root', 'symlink', 'hardlink', 'fifo', 'duplicate', 'plugin', 'plugin_version', 'source_commit', 'source_date_epoch', 'archive_profile', 'main_file', 'main_file_version', 'file_count', 'archive_sha256', 'canonical_tar_sha256', 'canonical_tree_sha256', 'packages-missing', 'packages-extra', 'packages-reference']:
    artifact('wcs-invalid-' + mutation, mutation)
PY
wcs_plan="$(
	E2E_TRANSITION_PORT=19091 "$PROVISIONER" plan --workspace "$workspace" \
		--seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" --run-id real-run \
		--wcs-archive "$TEST_ROOT/wcs.tar.gz" --wcs-manifest "$TEST_ROOT/wcs.json"
)"
test "$(find "$workspace" -mindepth 1 -print)" = "$before_plan"
node -e '
	const assert = require("node:assert/strict");
	const fs = require("node:fs");
	const artifact = JSON.parse(process.argv[1]).optional_artifacts["woocommerce-subscriptions"];
	const manifest = JSON.parse(fs.readFileSync(process.argv[2]));
	for (const key of ["plugin", "plugin_version", "source_commit", "archive_sha256", "canonical_tar_sha256", "canonical_tree_sha256"]) assert.equal(artifact[key], manifest[key]);
' "$wcs_plan" "$TEST_ROOT/wcs.json"
if E2E_TRANSITION_WP_ENV_BIN=/usr/bin/true E2E_TRANSITION_PORT=19091 "$PROVISIONER" plan \
	--workspace "$workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" \
	--run-id real-run --wcs-archive "$TEST_ROOT/wcs.tar.gz" --wcs-manifest "$TEST_ROOT/wcs.json" \
	> /dev/null 2> "$TEST_ROOT/wcs-unapproved-binary-error"; then
	echo 'WCS plan accepted an executable outside the pinned wp-env adapter boundary.' >&2
	exit 1
fi
grep -Fq 'WCS wp-env executable is outside the pinned adapter boundary' "$TEST_ROOT/wcs-unapproved-binary-error"
test "$(find "$workspace" -mindepth 1 -print)" = "$before_plan"

node - "$TEST_ROOT" <<'JS'
const fs = require( 'node:fs' );
const root = process.argv[2];
fs.cpSync( root + '/seed', root + '/older-seed', { recursive: true } );
for ( const file of [ 'woocommerce-payments.php', 'package.json', 'package-lock.json' ] ) {
	const path = root + '/older-seed/woocommerce-payments/' + file;
	fs.writeFileSync( path, fs.readFileSync( path, 'utf8' ).replaceAll( '10.5.0', '10.4.0' ) );
}
JS
chmod -R a-w "$TEST_ROOT/older-seed/woocommerce-payments"
tar -czf "$TEST_ROOT/older-seed.tar.gz" -C "$TEST_ROOT/older-seed" woocommerce-payments
node - "$TEST_ROOT" <<'JS'
const fs = require( 'node:fs' );
const { createHash } = require( 'node:crypto' );
const root = process.argv[2];
const hash = value => createHash( 'sha256' ).update( value ).digest( 'hex' );
const archive = fs.readFileSync( root + '/older-seed.tar.gz' );
const manifest = JSON.parse( fs.readFileSync( root + '/seed.json' ) );
Object.assign( manifest, {
	plugin_version: '10.4.0',
	source_commit: 'e2a6e70f21ff5827a9e67abeb4bc44c9ccabeb3d',
	archive_sha256: hash( archive ),
	canonical_tar_sha256: hash( require( 'node:zlib' ).gunzipSync( archive ) ),
	frontend_lock_sha256: hash( fs.readFileSync( root + '/older-seed/woocommerce-payments/package-lock.json' ) ),
} );
fs.writeFileSync( root + '/older-seed.json', JSON.stringify( manifest ) );
JS
chmod 0444 "$TEST_ROOT/older-seed.tar.gz" "$TEST_ROOT/older-seed.json"
older_plan="$(E2E_TRANSITION_SEED_PROFILE=10.4.0 \
	E2E_TRANSITION_FRONTEND_LOCK_SHA256="$(shasum -a 256 "$TEST_ROOT/older-seed/woocommerce-payments/package-lock.json" | awk '{ print $1 }')" \
	E2E_TRANSITION_PORT=19091 "$PROVISIONER" plan --workspace "$workspace" \
	--seed-archive "$TEST_ROOT/older-seed.tar.gz" --seed-manifest "$TEST_ROOT/older-seed.json" --run-id older-profile)"
node -e 'if ( JSON.parse( process.argv[1] ).plugin_version !== "10.4.0" ) process.exit( 1 );' "$older_plan"
test "$(find "$workspace" -mindepth 1 -print)" = "$before_plan"

profile_11_tree="$TEST_ROOT/profile-11-seed"
cp -R "$TEST_ROOT/seed" "$profile_11_tree"
node - "$profile_11_tree/woocommerce-payments" <<'JS'
const fs = require( 'node:fs' );
const root = process.argv[ 2 ];
for ( const file of [ 'woocommerce-payments.php', 'package.json' ] ) {
	const path = `${ root }/${ file }`;
	fs.writeFileSync( path, fs.readFileSync( path, 'utf8' ).replaceAll( '10.5.0', '11.1.0' ) );
}
const manifestPath = `${ root }/package.json`;
const manifest = JSON.parse( fs.readFileSync( manifestPath, 'utf8' ) );
manifest.packageManager = 'pnpm@11.13.1+sha512.b2fc7683b8a6525414e7d13e1ba28caaddde96bf66ec540bfaeb7e702b81f3e0be4d1f295edf7f9fe0396740a8dce4509c582ddf79891f4543fea32d37645f25';
fs.writeFileSync( manifestPath, `${ JSON.stringify( manifest, null, '\t' ) }\n` );
fs.writeFileSync( `${ root }/.nvmrc`, '24.17.0\n' );
fs.rmSync( `${ root }/package-lock.json` );
fs.writeFileSync( `${ root }/pnpm-lock.yaml`, `lockfileVersion: '9.0'\n\nsettings:\n  autoInstallPeers: true\n\nimporters:\n  .:\n    devDependencies:\n      fake-webpack:\n        version: 5.93.0\n` );
JS
chmod -R a-w "$profile_11_tree/woocommerce-payments"
tar -czf "$TEST_ROOT/profile-11-seed.tar.gz" -C "$profile_11_tree" woocommerce-payments
chmod -R u+w "$profile_11_tree/woocommerce-payments"
profile_11_seed_hash="$(shasum -a 256 "$TEST_ROOT/profile-11-seed.tar.gz" | awk '{ print $1 }')"
profile_11_canonical_hash="$(gzip -cd "$TEST_ROOT/profile-11-seed.tar.gz" | shasum -a 256 | awk '{ print $1 }')"
profile_11_frontend_lock_hash="$(shasum -a 256 "$profile_11_tree/woocommerce-payments/pnpm-lock.yaml" | awk '{ print $1 }')"
profile_11_composer_lock_hash="$(shasum -a 256 "$profile_11_tree/woocommerce-payments/composer.lock" | awk '{ print $1 }')"
node - \
	"$TEST_ROOT/seed.json" \
	"$TEST_ROOT/profile-11-seed.json" \
	"$profile_11_seed_hash" \
	"$profile_11_canonical_hash" \
	"$profile_11_frontend_lock_hash" \
	"$profile_11_composer_lock_hash" <<'JS'
const fs = require( 'node:fs' );
const manifest = JSON.parse( fs.readFileSync( process.argv[ 2 ], 'utf8' ) );
Object.assign( manifest, {
	plugin_version: '11.1.0',
	source_commit: 'f85392666c9b543cd24dbbf903e0dbe4cb2c5cee',
	archive_sha256: process.argv[ 4 ],
	canonical_tar_sha256: process.argv[ 5 ],
	dependency_lock_sha256: process.argv[ 7 ],
	frontend_lock_sha256: process.argv[ 6 ],
	frontend_lock_file: 'pnpm-lock.yaml',
	frontend_package_manager: 'pnpm',
	frontend_package_manager_declaration: 'pnpm@11.13.1+sha512.b2fc7683b8a6525414e7d13e1ba28caaddde96bf66ec540bfaeb7e702b81f3e0be4d1f295edf7f9fe0396740a8dce4509c582ddf79891f4543fea32d37645f25',
	frontend_package_manager_version: '11.13.1',
	frontend_lockfile_version: '9.0',
	frontend_node_line: '24.17.0',
} );
delete manifest.build_toolchain.npm;
manifest.build_toolchain.node = 'v24.17.0';
manifest.build_toolchain.pnpm = '11.13.1';
fs.writeFileSync( process.argv[ 3 ], `${ JSON.stringify( manifest ) }\n` );
JS
chmod 0444 "$TEST_ROOT/profile-11-seed.tar.gz" "$TEST_ROOT/profile-11-seed.json"

profile_11_plan="$(
	env \
		E2E_TRANSITION_SEED_PROFILE=11.1.0 \
		E2E_TRANSITION_COMPOSER_LOCK_SHA256="$profile_11_composer_lock_hash" \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$profile_11_frontend_lock_hash" \
		E2E_TRANSITION_PORT=19092 \
		"$PROVISIONER" plan \
		--workspace "$workspace" \
		--seed-archive "$TEST_ROOT/profile-11-seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/profile-11-seed.json" \
		--run-id profile-11
)"
node -e '
	const plan = JSON.parse( process.argv[ 1 ] );
	if ( plan.plugin_version !== "11.1.0" ) process.exit( 1 );
	if ( plan.base_url !== "http://transition-profile-11.localhost:19092" ) process.exit( 1 );
' "$profile_11_plan"
test "$(find "$workspace" -mindepth 1 -print)" = "$before_plan"

make_invalid_profile_11_manifest() {
	local variant="$1"
	local variant_manifest="$TEST_ROOT/profile-11-$variant.json"
	node - "$TEST_ROOT/profile-11-seed.json" "$variant_manifest" "$variant" <<'JS'
const fs = require( 'node:fs' );
const manifest = JSON.parse( fs.readFileSync( process.argv[ 2 ], 'utf8' ) );
switch ( process.argv[ 4 ] ) {
	case 'wrong-source-commit': manifest.source_commit = '0'.repeat( 40 ); break;
	case 'wrong-plugin-version': manifest.plugin_version = '11.1.1'; break;
	case 'wrong-node-line': manifest.frontend_node_line = '24.16.0'; break;
	case 'wrong-node-version': manifest.build_toolchain.node = 'v24.16.0'; break;
	case 'wrong-pnpm-version':
		manifest.frontend_package_manager_version = '11.13.0';
		manifest.build_toolchain.pnpm = '11.13.0';
		break;
	case 'wrong-pnpm-declaration': manifest.frontend_package_manager_declaration = 'pnpm@11.13.0'; break;
	case 'wrong-frontend-lock-file': manifest.frontend_lock_file = 'package-lock.json'; break;
	case 'wrong-frontend-lock-hash': manifest.frontend_lock_sha256 = '0'.repeat( 64 ); break;
	case 'wrong-frontend-lock-version': manifest.frontend_lockfile_version = 9; break;
	case 'wrong-composer-lock-hash': manifest.dependency_lock_sha256 = '0'.repeat( 64 ); break;
	case 'wrong-toolchain-key-set': manifest.build_toolchain.npm = '10.2.4'; break;
	default: process.exit( 2 );
}
fs.writeFileSync( process.argv[ 3 ], `${ JSON.stringify( manifest ) }\n` );
JS
	chmod 0444 "$variant_manifest"
}

for invalid_profile_11_manifest in \
	wrong-source-commit \
	wrong-plugin-version \
	wrong-node-line \
	wrong-node-version \
	wrong-pnpm-version \
	wrong-pnpm-declaration \
	wrong-frontend-lock-file \
	wrong-frontend-lock-hash \
	wrong-frontend-lock-version \
	wrong-composer-lock-hash \
	wrong-toolchain-key-set; do
	make_invalid_profile_11_manifest "$invalid_profile_11_manifest"
done

profile_11_package_lock_tree="$TEST_ROOT/profile-11-package-lock-tree"
cp -R "$profile_11_tree" "$profile_11_package_lock_tree"
rm "$profile_11_package_lock_tree/woocommerce-payments/pnpm-lock.yaml"
sed 's/10\.5\.0/11.1.0/g' "$TEST_ROOT/seed/woocommerce-payments/package-lock.json" > \
	"$profile_11_package_lock_tree/woocommerce-payments/package-lock.json"
tar -czf "$TEST_ROOT/profile-11-package-lock-seed.tar.gz" -C "$profile_11_package_lock_tree" woocommerce-payments
node - \
	"$TEST_ROOT/profile-11-seed.json" \
	"$TEST_ROOT/profile-11-package-lock-seed.json" \
	"$TEST_ROOT/profile-11-package-lock-seed.tar.gz" <<'JS'
const fs = require( 'node:fs' );
const { createHash } = require( 'node:crypto' );
const { gunzipSync } = require( 'node:zlib' );
const manifest = JSON.parse( fs.readFileSync( process.argv[ 2 ], 'utf8' ) );
const archive = fs.readFileSync( process.argv[ 4 ] );
manifest.archive_sha256 = createHash( 'sha256' ).update( archive ).digest( 'hex' );
manifest.canonical_tar_sha256 = createHash( 'sha256' ).update( gunzipSync( archive ) ).digest( 'hex' );
fs.writeFileSync( process.argv[ 3 ], `${ JSON.stringify( manifest ) }\n` );
JS
chmod 0444 "$TEST_ROOT/profile-11-package-lock-seed.tar.gz" "$TEST_ROOT/profile-11-package-lock-seed.json"

assert_profile_11_rejected_before_workspace_mutation() {
	local variant="$1"
	local archive="${2:-$TEST_ROOT/profile-11-seed.tar.gz}"
	local manifest="${3:-$TEST_ROOT/profile-11-$variant.json}"
	local rejection_workspace="$TEST_ROOT/profile-11-$variant-workspace"
	mkdir "$rejection_workspace"
	if env \
		E2E_TRANSITION_SEED_PROFILE=11.1.0 \
		E2E_TRANSITION_COMPOSER_LOCK_SHA256="$profile_11_composer_lock_hash" \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$profile_11_frontend_lock_hash" \
		E2E_TRANSITION_PORT=19092 \
		"$PROVISIONER" plan \
		--workspace "$rejection_workspace" \
		--seed-archive "$archive" \
		--seed-manifest "$manifest" \
		--run-id "profile-11-${variant//_/-}" > /dev/null 2>&1; then
		echo "Plan accepted the invalid 11.1.0 transition seed: $variant." >&2
		exit 1
	fi
	test ! -n "$(find "$rejection_workspace" -mindepth 1 -print -quit)"
}

for invalid_profile_11_manifest in \
	wrong-source-commit \
	wrong-plugin-version \
	wrong-node-line \
	wrong-node-version \
	wrong-pnpm-version \
	wrong-pnpm-declaration \
	wrong-frontend-lock-file \
	wrong-frontend-lock-hash \
	wrong-frontend-lock-version \
	wrong-composer-lock-hash \
	wrong-toolchain-key-set; do
	assert_profile_11_rejected_before_workspace_mutation "$invalid_profile_11_manifest"
done
assert_profile_11_rejected_before_workspace_mutation \
	package-lock-substituted \
	"$TEST_ROOT/profile-11-package-lock-seed.tar.gz" \
	"$TEST_ROOT/profile-11-package-lock-seed.json"

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
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="${E2E_TRANSITION_FRONTEND_LOCK_SHA256:-$frontend_lock_hash}" \
		E2E_TRANSITION_COMPOSER_LOCK_SHA256="${E2E_TRANSITION_COMPOSER_LOCK_SHA256:-}" \
		E2E_TRANSITION_CORE_REPO="$TEST_ROOT/mounts/core" \
		E2E_TRANSITION_DEV_TOOLS_REPO="$TEST_ROOT/mounts/dev-tools" \
		E2E_TRANSITION_WP_ENV_BIN="${E2E_TRANSITION_WP_ENV_BIN:-$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh}" \
		E2E_TRANSITION_DOCKER_BIN="${E2E_TRANSITION_DOCKER_BIN:-$SCRIPT_DIR/test-fixtures/fake-transition-docker.sh}" \
		E2E_TRANSITION_FIND_BIN="${E2E_TRANSITION_FIND_BIN:-find}" \
		E2E_FAKE_WP_ENV_CONFIG_MODULE="$SCRIPT_DIR/../../../../node_modules/@wordpress/env/lib/config" \
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
		E2E_FAKE_WP_ENV_WORDPRESS_ARTIFACT_TARGET="${E2E_FAKE_WP_ENV_WORDPRESS_ARTIFACT_TARGET:-}" \
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
		E2E_TRANSITION_TEST_START_ARTIFACT_KIND="${E2E_TRANSITION_TEST_START_ARTIFACT_KIND:-}" \
		E2E_TRANSITION_TEST_START_ARTIFACT_TARGET="${E2E_TRANSITION_TEST_START_ARTIFACT_TARGET:-}" \
		"$PROVISIONER" "$@"
}

assert_wcs_rejected_before_mutation() {
	local case_name="$1"
	shift
	local rejected_workspace="$TEST_ROOT/wcs-rejected-$case_name"
	local rejected_log="$TEST_ROOT/wcs-rejected-$case_name.log"
	mkdir "$rejected_workspace"
	for action in plan create; do
		if run_provisioner "$rejected_workspace" "$TEST_ROOT/wcs-rejected-runtime" "$rejected_log" \
			"$action" --workspace "$rejected_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
			--seed-manifest "$TEST_ROOT/seed.json" --run-id wcs-rejected \
			--base-url http://transition-wcs-rejected.localhost:19091 --store-id woopayments-native-transition-wcs-rejected \
			"$@" > /dev/null 2> "$TEST_ROOT/wcs-rejected-stderr"; then
			echo "WCS $action accepted invalid artifact: $case_name" >&2
			exit 1
		fi
		grep -Eq 'WCS (artifact validation failed|archive and manifest must be supplied together|create identity does not match)' "$TEST_ROOT/wcs-rejected-stderr"
		test -z "$(find "$rejected_workspace" -mindepth 1 -print -quit)"
		test ! -s "$rejected_log"
	done
}
for invalid_wcs_manifest in "$TEST_ROOT"/wcs-invalid-*.json; do
	assert_wcs_rejected_before_mutation "$(basename "$invalid_wcs_manifest" .json)" \
		--wcs-archive "${invalid_wcs_manifest%.json}.tar.gz" --wcs-manifest "$invalid_wcs_manifest"
done
assert_wcs_rejected_before_mutation archive-only --wcs-archive "$TEST_ROOT/wcs.tar.gz"
assert_wcs_rejected_before_mutation manifest-only --wcs-manifest "$TEST_ROOT/wcs.json"
for input in archive manifest; do
	if [[ "$input" == archive ]]; then source_input="$TEST_ROOT/wcs.tar.gz"; else source_input="$TEST_ROOT/wcs.json"; fi
	ln -s "$source_input" "$TEST_ROOT/wcs-symlink-$input"
	cp "$source_input" "$TEST_ROOT/wcs-writable-$input"
	chmod 0644 "$TEST_ROOT/wcs-writable-$input"
	for kind in symlink writable; do
		archive_input="$TEST_ROOT/wcs.tar.gz"
		manifest_input="$TEST_ROOT/wcs.json"
		if [[ "$input" == archive ]]; then archive_input="$TEST_ROOT/wcs-$kind-$input"; else manifest_input="$TEST_ROOT/wcs-$kind-$input"; fi
		assert_wcs_rejected_before_mutation "$kind-$input" --wcs-archive "$archive_input" --wcs-manifest "$manifest_input"
	done
done
E2E_TRANSITION_EXPECTED_WCS_IDENTITY='{}' assert_wcs_rejected_before_mutation changed-since-plan \
	--wcs-archive "$TEST_ROOT/wcs.tar.gz" --wcs-manifest "$TEST_ROOT/wcs.json"

for extraction_failpoint in during-extraction after-extraction; do
	recovery_workspace="$TEST_ROOT/wcs-$extraction_failpoint"
	recovery_log="$TEST_ROOT/wcs-$extraction_failpoint.log"
	mkdir "$recovery_workspace"
	if E2E_TRANSITION_WCS_FAIL_POINT="$extraction_failpoint" E2E_TRANSITION_PORT=19086 \
		run_provisioner "$recovery_workspace" "$TEST_ROOT/wcs-extraction-runtime" "$recovery_log" \
		create --workspace "$recovery_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" \
		--run-id wcs-extraction --base-url http://transition-wcs-extraction.localhost:19086 --store-id woopayments-native-transition-wcs-extraction \
		--wcs-archive "$TEST_ROOT/wcs.tar.gz" --wcs-manifest "$TEST_ROOT/wcs.json" > "$TEST_ROOT/wcs-extraction-result" 2> "$TEST_ROOT/wcs-extraction-error"; then
		echo "WCS extraction failure injection did not fail: $extraction_failpoint" >&2
		exit 1
	fi
	node -e '
		const assert = require("node:assert/strict"), fs = require("node:fs");
		const state = JSON.parse(fs.readFileSync(process.argv[1] + "/resource-state.json"));
		const result = JSON.parse(fs.readFileSync(process.argv[2]));
		assert.equal(result.status, "failed");
		assert.equal(result.rollback_receipt, fs.readFileSync(process.argv[1] + "/rollback-receipt", "utf8"));
		assert.deepEqual(state.optional_artifacts, JSON.parse(process.argv[3]).optional_artifacts);
		assert.deepEqual(result.optional_artifacts, state.optional_artifacts);
		assert.equal(state.wp_env_start_attempted, false);
		assert.equal(state.wcs_extraction_phase, process.argv[4] === "during-extraction" ? "extracting" : "extracted");
		assert.ok(fs.existsSync(process.argv[1] + "/wcs/woocommerce-subscriptions/vendor/composer/installed.json"));
	' "$recovery_workspace" "$TEST_ROOT/wcs-extraction-result" "$wcs_plan" "$extraction_failpoint"
	test ! -s "$recovery_log"
	run_provisioner "$recovery_workspace" "$TEST_ROOT/wcs-extraction-runtime" "$recovery_log" \
		destroy --workspace "$recovery_workspace" --rollback-receipt-file "$recovery_workspace/rollback-receipt"
	test -w "$recovery_workspace/wcs/woocommerce-subscriptions"
	# This is the same final owned-workspace removal performed by wrapper rollback.
	rm -rf -- "$recovery_workspace"
	test ! -e "$recovery_workspace"
	test "$(mode_of "$TEST_ROOT/wcs.tar.gz")" = 444
	test "$(mode_of "$TEST_ROOT/wcs.json")" = 444
done

wcs_workspace="$TEST_ROOT/wcs-create"
wcs_log="$TEST_ROOT/wcs-create.log"
wcs_runtime="$TEST_ROOT/wcs-runtime"
mkdir "$wcs_workspace"
wcs_before="$(shasum -a 256 "$TEST_ROOT/wcs.tar.gz" "$TEST_ROOT/wcs.json")"
wcs_created="$(E2E_TRANSITION_PORT=19086 run_provisioner "$wcs_workspace" "$wcs_runtime" "$wcs_log" \
	create --workspace "$wcs_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" \
	--run-id wcs-create --base-url http://transition-wcs-create.localhost:19086 --store-id woopayments-native-transition-wcs-create \
	--wcs-archive "$TEST_ROOT/wcs.tar.gz" --wcs-manifest "$TEST_ROOT/wcs.json")"
node -e '
	const assert = require("node:assert/strict");
	const fs = require("node:fs");
	const path = require("node:path");
	const workspace = process.argv[1];
	const planned = JSON.parse(process.argv[2]).optional_artifacts;
	const created = JSON.parse(process.argv[3]);
	const state = JSON.parse(fs.readFileSync(path.join(workspace, "resource-state.json")));
	assert.deepEqual(created.optional_artifacts, planned);
	assert.deepEqual(state.optional_artifacts, planned);
	assert.equal(created.rollback_receipt, fs.readFileSync(path.join(workspace, "rollback-receipt"), "utf8"));
	const config = JSON.parse(fs.readFileSync(path.join(workspace, "store/.wp-env.json")));
	assert.equal(config.mappings["wp-content/plugins/woocommerce-subscriptions"], workspace + "/wcs/woocommerce-subscriptions");
	const visit = directory => {
		assert.equal(fs.statSync(directory).mode & 0o777, 0o555);
		for (const name of fs.readdirSync(directory)) {
			const item = path.join(directory, name), stat = fs.lstatSync(item);
			assert.ok(!stat.isSymbolicLink());
			if (stat.isDirectory()) visit(item); else assert.equal(stat.mode & 0o777, 0o444);
		}
	};
	visit(config.mappings["wp-content/plugins/woocommerce-subscriptions"]);
	assert.ok(!JSON.stringify(planned).includes(process.argv[4]));
' "$wcs_workspace" "$wcs_plan" "$wcs_created" "$TEST_ROOT"
if grep -Eq 'plugin (activate|install).*woocommerce-subscriptions' "$wcs_log"; then
	echo 'The provisioner activated WCS.' >&2
	exit 1
fi
# Exercise the installed compose builder, not a fake mount implementation.
node - "$wcs_workspace" "$SCRIPT_DIR/../../../.." <<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const workspace = process.argv[2];
const packageRoot = path.dirname(require.resolve('@wordpress/env/package.json', {paths: [process.argv[3]]}));
const adapter = workspace + '/wcs-compose-preload.cjs';
const builderPath = require.resolve(packageRoot + '/lib/runtime/docker/build-docker-compose-config.js');
const originalBuilder = require(builderPath);
if (fs.existsSync(adapter)) require(adapter);
const builder = require(builderPath);
const {parseSourceString} = require(packageRoot + '/lib/config/parse-source-string.js');
const sourceConfig = parseSourceString(workspace + '/wcs/woocommerce-subscriptions', {cacheDirectoryPath: workspace});
const source = sourceConfig.path;
const target = '/var/www/html/wp-content/plugins/woocommerce-subscriptions';
const environment = {mappings: {'wp-content/plugins/woocommerce-subscriptions': sourceConfig}, pluginSources: [], themeSources: [], port: 19086, configDirectoryPath: workspace + '/store'};
const makeConfig = testsEnvironment => ({workDirectoryPath: workspace + '/wp-env-home', testsEnvironment, env: {development: environment, tests: environment}});
for (const testsEnvironment of [false, true]) {
    const compose = builder(makeConfig(testsEnvironment));
    const services = testsEnvironment ? ['wordpress', 'cli', 'tests-wordpress', 'tests-cli'] : ['wordpress', 'cli'];
    for (const service of services) {
        const mounts = compose.services[service].volumes.filter(volume => volume.includes(target));
        assert.deepEqual(mounts, [source + ':' + target + ':ro'], service + ' must mount WCS read-only at the Docker boundary');
        if (testsEnvironment && process.env.E2E_TRANSITION_WCS_DOCKER_PROBE_IMAGE) {
            const {spawnSync} = require('node:child_process');
            const commands = [
                'file=/var/www/html/wp-content/plugins/woocommerce-subscriptions/woocommerce-subscriptions.php',
                'directory=/var/www/html/wp-content/plugins/woocommerce-subscriptions',
                'test "$(id -u)" = "$(stat -c %u "$file")" || exit 10',
                'if chmod 0644 "$file"; then exit 11; fi',
                'if chmod 0755 "$directory"; then exit 12; fi',
                'if printf tampered >> "$file"; then exit 13; fi',
                'if mv "$file" "$directory/replaced.php"; then exit 14; fi',
            ].join('\n');
            const probe = spawnSync('docker', ['run', '--rm', '--pull', 'never', '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--user', process.getuid() + ':' + process.getgid(), '--volume', mounts[0], '--entrypoint', 'sh', process.env.E2E_TRANSITION_WCS_DOCKER_PROBE_IMAGE, '-c', commands], {encoding: 'utf8', timeout: 30000});
            assert.equal(probe.status, 0, service + ' Docker probe failed: ' + probe.stderr);
            console.log('WCS Docker read-only probe passed: ' + service + ' (chmod file/directory, overwrite, replace rejected)');
        }
    }
}
assert.equal(fs.statSync(adapter).mode & 0o777, 0o400);
const state = JSON.parse(fs.readFileSync(workspace + '/resource-state.json'));
assert.equal(state.wcs_mount_mode, 'ro');
assert.equal(state.wcs_wp_env_version, '11.9.0');
for (const fault of ['missing-service', 'extra-service', 'object-volume', 'missing-volume', 'duplicate-volume', 'wrong-source', 'nested-target', 'writable-alias', 'wrong-export']) {
    delete require.cache[require.resolve(adapter)];
    require.cache[builderPath].exports = fault === 'wrong-export' ? {} : config => {
        const compose = originalBuilder(config);
        const volumes = compose.services.wordpress.volumes;
        if (fault === 'missing-service') delete compose.services.cli;
        if (fault === 'extra-service') compose.services.extra = {};
        if (fault === 'object-volume') volumes.push({source, target});
        if (fault === 'missing-volume') compose.services.wordpress.volumes = volumes.filter(volume => !volume.includes(target));
        if (fault === 'duplicate-volume') volumes.push(source + ':' + target);
        if (fault === 'wrong-source') compose.services.wordpress.volumes = volumes.map(volume => volume.includes(target) ? '/wrong:' + target : volume);
        if (fault === 'nested-target') volumes.push('/wrong:' + target + '/vendor');
        if (fault === 'writable-alias') volumes.push(source + ':/writable-wcs');
        return compose;
    };
    assert.throws(() => { require(adapter); require(builderPath)(makeConfig(false)); }, /WCS/, fault);
}
for (const fault of ['package-version', 'module-hash']) {
    const root = workspace + '/' + fault;
    fs.mkdirSync(root);
    fs.writeFileSync(root + '/package.json', JSON.stringify({name: '@wordpress/env', version: fault === 'package-version' ? '99.0.0' : '11.9.0'}));
    if (fault === 'module-hash') {
        fs.mkdirSync(root + '/lib/runtime/docker', {recursive: true});
        fs.writeFileSync(root + '/lib/runtime/docker/build-docker-compose-config.js', 'module.exports = () => ({});');
    }
    const Module = require('node:module');
    const testModule = new Module(workspace + '/adapter-' + fault + '.cjs', module);
    const content = fs.readFileSync(adapter, 'utf8').replace(JSON.stringify(packageRoot), JSON.stringify(root));
    assert.throws(() => testModule._compile(content, testModule.id), /Unsupported WCS wp-env/, fault);
}
JS
run_provisioner "$wcs_workspace" "$wcs_runtime" "$wcs_log" destroy --workspace "$wcs_workspace" \
	--rollback-receipt-file "$wcs_workspace/rollback-receipt"
test "$(mode_of "$wcs_workspace/wcs/woocommerce-subscriptions")" = 755
test "$wcs_before" = "$(shasum -a 256 "$TEST_ROOT/wcs.tar.gz" "$TEST_ROOT/wcs.json")"
test "$(mode_of "$TEST_ROOT/wcs.tar.gz")" = 444
test "$(mode_of "$TEST_ROOT/wcs.json")" = 444
node -e '
	const assert = require("node:assert/strict");
	const state = JSON.parse(require("node:fs").readFileSync(process.argv[1]));
	assert.equal(state.phase, "destroyed");
	assert.deepEqual(state.optional_artifacts, JSON.parse(process.argv[2]).optional_artifacts);
' "$wcs_workspace/resource-state.json" "$wcs_plan"

wcs_failed_workspace="$TEST_ROOT/wcs-failed-create"
mkdir "$wcs_failed_workspace"
if E2E_TRANSITION_PORT=19086 E2E_FAKE_WP_ENV_START_AFTER_CREATE_FAIL=1 \
	run_provisioner "$wcs_failed_workspace" "$TEST_ROOT/wcs-failed-runtime" "$TEST_ROOT/wcs-failed.log" \
	create --workspace "$wcs_failed_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" --seed-manifest "$TEST_ROOT/seed.json" \
	--run-id wcs-failed --base-url http://transition-wcs-failed.localhost:19086 --store-id woopayments-native-transition-wcs-failed \
	--wcs-archive "$TEST_ROOT/wcs.tar.gz" --wcs-manifest "$TEST_ROOT/wcs.json" > "$TEST_ROOT/wcs-failed-result" 2> /dev/null; then
	echo 'WCS partial-create failure injection did not fail.' >&2
	exit 1
fi
node -e '
	const assert = require("node:assert/strict"), fs = require("node:fs");
	const failed = JSON.parse(fs.readFileSync(process.argv[1]));
	const state = JSON.parse(fs.readFileSync(process.argv[2]));
	assert.equal(failed.status, "failed");
	assert.deepEqual(failed.optional_artifacts, JSON.parse(process.argv[3]).optional_artifacts);
	assert.deepEqual(state.optional_artifacts, failed.optional_artifacts);
' "$TEST_ROOT/wcs-failed-result" "$wcs_failed_workspace/resource-state.json" "$wcs_plan"
run_provisioner "$wcs_failed_workspace" "$TEST_ROOT/wcs-failed-runtime" "$TEST_ROOT/wcs-failed.log" \
	destroy --workspace "$wcs_failed_workspace" --rollback-receipt-file "$wcs_failed_workspace/rollback-receipt"

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

for identity_field in site_url home marker wpcom_blog_id account_id is_live blog_token_present user_token_present; do
	identity_run="identity-${identity_field//_/-}"
	identity_workspace="$TEST_ROOT/identity-$identity_field"
	identity_runtime="$TEST_ROOT/identity-$identity_field-runtime"
	identity_log="$TEST_ROOT/identity-$identity_field.log"
	identity_error="$TEST_ROOT/identity-$identity_field.stderr"
	mkdir "$identity_workspace"
	if E2E_FAKE_IDENTITY_FIELD="$identity_field" E2E_TRANSITION_PORT=19145 \
		run_provisioner "$identity_workspace" "$identity_runtime" "$identity_log" \
		create --workspace "$identity_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" --run-id "$identity_run" \
		--base-url "http://transition-$identity_run.localhost:19145" \
		--store-id "woopayments-native-transition-$identity_run" > /dev/null 2> "$identity_error"; then
		echo "Creation accepted an invalid identity field: $identity_field." >&2
		exit 1
	fi
	if [[ "$(< "$identity_error")" != "Transition identity mismatch: $identity_field." ]]; then
		echo "Identity failure did not report only its field name: $identity_field." >&2
		exit 1
	fi
	run_provisioner "$identity_workspace" "$identity_runtime" "$identity_log" \
		destroy --workspace "$identity_workspace" --rollback-receipt-file "$identity_workspace/rollback-receipt"
done

first_start_flake_wp_env="$TEST_ROOT/first-start-flake-wp-env"
cat > "$first_start_flake_wp_env" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$*" != 'start' ]]; then
	exec "${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
fi

attempt_file="$E2E_FAKE_RUNTIME_STATE/wp-env-start-attempts"
mkdir -p "$E2E_FAKE_RUNTIME_STATE"
attempt=0
if [[ -f "$attempt_file" ]]; then
	attempt="$(< "$attempt_file")"
fi
attempt=$((attempt + 1))
printf '%s\n' "$attempt" > "$attempt_file"

wp_env_project_path() {
	node -e '
		const { realpathSync } = require( "node:fs" );
		const { loadConfig } = require( process.env.E2E_FAKE_WP_ENV_CONFIG_MODULE );
		loadConfig( process.argv[ 1 ] ).then( ( config ) => {
			process.stdout.write( realpathSync( config.workDirectoryPath ) );
		} ).catch( ( error ) => {
			console.error( error );
			process.exit( 1 );
		} );
	' "$PWD"
}

case "${E2E_FAKE_WP_ENV_FIRST_START_MODE:?}" in
	second-succeeds)
		if (( attempt == 1 )); then
			printf 'Injected first start stdout.\n'
			printf 'Injected first start stderr.\n' >&2
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			exit 45
		fi
		;;
	config-exists-after-first-failure|wordpress-artifact-overwrite-after-first-failure|wordpress-artifact-symlink-after-first-failure)
		if (( attempt == 1 )); then
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			wp_env_project="$(wp_env_project_path)"
			touch "$wp_env_project/wordpress-latest/wp-config.php"
			case "${E2E_FAKE_WP_ENV_FIRST_START_MODE}" in
				wordpress-artifact-overwrite-after-first-failure)
					printf 'pre-existing transition diagnostic\n' > "$E2E_FAKE_TRANSITION_WORKSPACE/wp-env-wordpress.log"
					;;
				wordpress-artifact-symlink-after-first-failure)
					ln -s "${E2E_FAKE_WP_ENV_WORDPRESS_ARTIFACT_TARGET:?}" "$E2E_FAKE_TRANSITION_WORKSPACE/wp-env-wordpress.log"
					;;
			esac
			exit 45
		fi
		;;
	second-fails)
		if (( attempt == 1 )); then
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			exit 45
		fi
		if (( attempt == 2 )); then
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			exit 46
		fi
		;;
	missing-wordpress-after-first-failure)
		if (( attempt == 1 )); then
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			wp_env_project="$(wp_env_project_path)"
			rm -rf "$wp_env_project/wordpress-latest"
			exit 45
		fi
		;;
	ambiguous-compose-after-first-failure)
		if (( attempt == 1 )); then
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			wp_env_project="$(wp_env_project_path)"
			touch "$wp_env_project/wordpress-latest/wp-config.php"
			mkdir -p "$WP_ENV_HOME/wp-env-unrelated"
			printf 'services:\n  wordpress: {}\n' > "$WP_ENV_HOME/wp-env-unrelated/docker-compose.yml"
			exit 45
		fi
		;;
	absent-compose-after-first-failure|ancestor-symlink-after-first-failure)
		if (( attempt == 1 )); then
			"${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
			wp_env_project="$(wp_env_project_path)"
			touch "$wp_env_project/wordpress-latest/wp-config.php"
			if [[ "${E2E_FAKE_WP_ENV_FIRST_START_MODE}" == absent-compose-after-first-failure ]]; then
				rm "$wp_env_project/docker-compose.yml"
			else
				mv "$wp_env_project/wordpress-latest" "$WP_ENV_HOME/wordpress-latest-target"
				ln -s "$WP_ENV_HOME/wordpress-latest-target" "$wp_env_project/wordpress-latest"
			fi
			exit 45
		fi
		;;
	*)
		echo "Unknown first-start flake mode: ${E2E_FAKE_WP_ENV_FIRST_START_MODE}" >&2
		exit 1
		;;
esac

exec "${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
SH
chmod 0700 "$first_start_flake_wp_env"

assert_run_artifact() {
	local artifact="$1"
	local expected="$2"
	if [[ ! -f "$artifact" || -L "$artifact" ]] ||
		[[ "$(mode_of "$artifact")" != '600' ]] ||
		! grep -Fq "$expected" "$artifact"; then
		echo "Transition start diagnostic artifact was invalid: $artifact" >&2
		exit 1
	fi
}

first_start_retry_port=19160
first_start_retry_workspace="$TEST_ROOT/first-start-retry"
first_start_retry_runtime="$TEST_ROOT/first-start-retry-runtime"
first_start_retry_log="$TEST_ROOT/first-start-retry.log"
mkdir "$first_start_retry_workspace"
E2E_TRANSITION_PORT="$first_start_retry_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_FAKE_WP_ENV_FIRST_START_MODE=second-succeeds \
	run_provisioner "$first_start_retry_workspace" "$first_start_retry_runtime" "$first_start_retry_log" \
	create --workspace "$first_start_retry_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" --run-id first-start-retry \
	--base-url "http://transition-first-start-retry.localhost:$first_start_retry_port" \
	--store-id woopayments-native-transition-first-start-retry > /dev/null
test "$(< "$first_start_retry_runtime/wp-env-start-attempts")" = '2'
assert_run_artifact "$first_start_retry_workspace/wp-env-start-1.log" 'Injected first start stdout.'
assert_run_artifact "$first_start_retry_workspace/wp-env-start-1.log" 'Injected first start stderr.'
assert_run_artifact "$first_start_retry_workspace/wp-env-start-2.log" 'Fake wp-env start output.'
E2E_TRANSITION_PORT="$first_start_retry_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$first_start_retry_workspace" "$first_start_retry_runtime" "$first_start_retry_log" \
	destroy --workspace "$first_start_retry_workspace" --rollback-receipt-file "$first_start_retry_workspace/rollback-receipt"

missing_wordpress_workspace="$TEST_ROOT/first-start-missing-wordpress"
missing_wordpress_runtime="$TEST_ROOT/first-start-missing-wordpress-runtime"
missing_wordpress_log="$TEST_ROOT/first-start-missing-wordpress.log"
missing_wordpress_port=$((first_start_retry_port + 1))
mkdir "$missing_wordpress_workspace"
E2E_TRANSITION_PORT="$missing_wordpress_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_FAKE_WP_ENV_FIRST_START_MODE=missing-wordpress-after-first-failure \
	run_provisioner "$missing_wordpress_workspace" "$missing_wordpress_runtime" "$missing_wordpress_log" \
	create --workspace "$missing_wordpress_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" --run-id first-start-missing-wordpress \
	--base-url "http://transition-first-start-missing-wordpress.localhost:$missing_wordpress_port" \
	--store-id woopayments-native-transition-first-start-missing-wordpress > /dev/null
test "$(< "$missing_wordpress_runtime/wp-env-start-attempts")" = '2'
assert_run_artifact "$missing_wordpress_workspace/wp-env-start-2.log" 'Fake wp-env start output.'
E2E_TRANSITION_PORT="$missing_wordpress_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$missing_wordpress_workspace" "$missing_wordpress_runtime" "$missing_wordpress_log" \
	destroy --workspace "$missing_wordpress_workspace" --rollback-receipt-file "$missing_wordpress_workspace/rollback-receipt"

first_start_config_workspace="$TEST_ROOT/first-start-config"
first_start_config_runtime="$TEST_ROOT/first-start-config-runtime"
first_start_config_log="$TEST_ROOT/first-start-config.log"
first_start_config_port=$((first_start_retry_port + 1))
mkdir "$first_start_config_workspace"
set +e
E2E_TRANSITION_PORT="$first_start_config_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_FAKE_WP_ENV_FIRST_START_MODE=config-exists-after-first-failure \
	run_provisioner "$first_start_config_workspace" "$first_start_config_runtime" "$first_start_config_log" \
	create --workspace "$first_start_config_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" --run-id first-start-config \
	--base-url "http://transition-first-start-config.localhost:$first_start_config_port" \
	--store-id woopayments-native-transition-first-start-config > /dev/null
first_start_config_status=$?
set -e
if [[ "$first_start_config_status" != 45 ]]; then
	echo "Transition did not preserve the first start status (got $first_start_config_status)." >&2
	exit 1
fi
test "$(< "$first_start_config_runtime/wp-env-start-attempts")" = '1'
assert_run_artifact "$first_start_config_workspace/wp-env-start-1.log" 'Fake wp-env start output.'
test ! -e "$first_start_config_workspace/wp-env-start-2.log"
assert_run_artifact "$first_start_config_workspace/wp-env-wordpress.log" 'Fake exact WordPress service log.'
test "$(< "$first_start_config_runtime/compose-wordpress-log-attempts")" = '1'
E2E_TRANSITION_PORT="$first_start_config_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$first_start_config_workspace" "$first_start_config_runtime" "$first_start_config_log" \
	destroy --workspace "$first_start_config_workspace" --rollback-receipt-file "$first_start_config_workspace/rollback-receipt"

first_start_second_failure_workspace="$TEST_ROOT/first-start-second-failure"
first_start_second_failure_runtime="$TEST_ROOT/first-start-second-failure-runtime"
first_start_second_failure_log="$TEST_ROOT/first-start-second-failure.log"
first_start_second_failure_port=$((first_start_config_port + 1))
mkdir "$first_start_second_failure_workspace"
set +e
E2E_TRANSITION_PORT="$first_start_second_failure_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_FAKE_WP_ENV_FIRST_START_MODE=second-fails \
	run_provisioner "$first_start_second_failure_workspace" "$first_start_second_failure_runtime" "$first_start_second_failure_log" \
	create --workspace "$first_start_second_failure_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" --run-id first-start-second-failure \
	--base-url "http://transition-first-start-second-failure.localhost:$first_start_second_failure_port" \
	--store-id woopayments-native-transition-first-start-second-failure > /dev/null
first_start_second_failure_status=$?
set -e
if [[ "$first_start_second_failure_status" != 46 ]]; then
	echo "Transition did not preserve the second start status (got $first_start_second_failure_status)." >&2
	exit 1
fi
test "$(< "$first_start_second_failure_runtime/wp-env-start-attempts")" = '2'
assert_run_artifact "$first_start_second_failure_workspace/wp-env-start-1.log" 'Fake wp-env start output.'
assert_run_artifact "$first_start_second_failure_workspace/wp-env-start-2.log" 'Fake wp-env start output.'
assert_run_artifact "$first_start_second_failure_workspace/wp-env-wordpress.log" 'Fake exact WordPress service log.'
test "$(< "$first_start_second_failure_runtime/compose-wordpress-log-attempts")" = '1'
E2E_TRANSITION_PORT="$first_start_second_failure_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$first_start_second_failure_workspace" "$first_start_second_failure_runtime" "$first_start_second_failure_log" \
	destroy --workspace "$first_start_second_failure_workspace" --rollback-receipt-file "$first_start_second_failure_workspace/rollback-receipt"

first_start_ambiguous_workspace="$TEST_ROOT/first-start-ambiguous"
first_start_ambiguous_runtime="$TEST_ROOT/first-start-ambiguous-runtime"
first_start_ambiguous_log="$TEST_ROOT/first-start-ambiguous.log"
first_start_ambiguous_port=$((first_start_second_failure_port + 1))
mkdir "$first_start_ambiguous_workspace"
if E2E_TRANSITION_PORT="$first_start_ambiguous_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_FAKE_WP_ENV_FIRST_START_MODE=ambiguous-compose-after-first-failure \
	run_provisioner "$first_start_ambiguous_workspace" "$first_start_ambiguous_runtime" "$first_start_ambiguous_log" \
	create --workspace "$first_start_ambiguous_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" --run-id first-start-ambiguous \
	--base-url "http://transition-first-start-ambiguous.localhost:$first_start_ambiguous_port" \
	--store-id woopayments-native-transition-first-start-ambiguous > /dev/null; then
	echo 'Transition create accepted an ambiguous wp-env Compose identity.' >&2
	exit 1
fi
test "$(< "$first_start_ambiguous_runtime/wp-env-start-attempts")" = '1'
assert_run_artifact "$first_start_ambiguous_workspace/wp-env-start-1.log" 'Fake wp-env start output.'
test ! -e "$first_start_ambiguous_runtime/compose-wordpress-log-attempts"
test ! -e "$first_start_ambiguous_workspace/wp-env-wordpress.log"
E2E_TRANSITION_PORT="$first_start_ambiguous_port" \
	E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$first_start_ambiguous_workspace" "$first_start_ambiguous_runtime" "$first_start_ambiguous_log" \
	destroy --workspace "$first_start_ambiguous_workspace" --rollback-receipt-file "$first_start_ambiguous_workspace/rollback-receipt"

assert_terminal_start_case() {
	local name="$1"
	local mode="$2"
	local port="$3"
	local find_bin="${4:-find}"
	local case_workspace="$TEST_ROOT/$name"
	local case_runtime="$TEST_ROOT/$name-runtime"
	local case_log="$TEST_ROOT/$name.log"
	local case_status
	mkdir "$case_workspace"
	set +e
	E2E_TRANSITION_PORT="$port" \
		E2E_TRANSITION_FIND_BIN="$find_bin" \
		E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
		E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		E2E_FAKE_WP_ENV_FIRST_START_MODE="$mode" \
		run_provisioner "$case_workspace" "$case_runtime" "$case_log" \
		create --workspace "$case_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" --run-id "$name" \
		--base-url "http://transition-$name.localhost:$port" \
		--store-id "woopayments-native-transition-$name" > /dev/null
	case_status=$?
	set -e
	if [[ "$case_status" != 45 ]]; then
		echo "Transition did not preserve status 45 for $name (got $case_status)." >&2
		exit 1
	fi
	test "$(< "$case_runtime/wp-env-start-attempts")" = '1'
	assert_run_artifact "$case_workspace/wp-env-start-1.log" 'Fake wp-env start output.'
	test ! -e "$case_runtime/compose-wordpress-log-attempts"
	test ! -e "$case_workspace/wp-env-wordpress.log"
	E2E_TRANSITION_PORT="$port" \
		E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
		E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		run_provisioner "$case_workspace" "$case_runtime" "$case_log" \
		destroy --workspace "$case_workspace" --rollback-receipt-file "$case_workspace/rollback-receipt"
}

assert_terminal_start_case first-start-absent-compose absent-compose-after-first-failure 19164
assert_terminal_start_case first-start-ancestor-symlink ancestor-symlink-after-first-failure 19165

failing_find="$TEST_ROOT/failing-find"
printf '#!/usr/bin/env bash\nexit 68\n' > "$failing_find"
chmod 0700 "$failing_find"
assert_terminal_start_case first-start-enumeration-error config-exists-after-first-failure 19166 "$failing_find"

assert_diagnostic_output_rejected() {
	local name="$1"
	local artifact="$2"
	local target="$3"
	local artifact_kind="$4"
	local mode="$5"
	local port="$6"
	local output_workspace="$TEST_ROOT/$name"
	local output_runtime="$TEST_ROOT/$name-runtime"
	local output_log="$TEST_ROOT/$name.log"
	local output_status
	mkdir "$output_workspace"
	set +e
	E2E_TRANSITION_PORT="$port" \
		E2E_TRANSITION_TEST_START_ARTIFACT_KIND="$([[ "$artifact" == wp-env-start-1.log ]] && printf '%s' "$artifact_kind")" \
		E2E_TRANSITION_TEST_START_ARTIFACT_TARGET="$target" \
		E2E_TRANSITION_WP_ENV_BIN="$first_start_flake_wp_env" \
		E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		E2E_FAKE_WP_ENV_WORDPRESS_ARTIFACT_TARGET="$target" \
		E2E_FAKE_WP_ENV_FIRST_START_MODE="$mode" \
		run_provisioner "$output_workspace" "$output_runtime" "$output_log" \
		create --workspace "$output_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/seed.json" --run-id "$name" \
		--base-url "http://transition-$name.localhost:$port" \
		--store-id "woopayments-native-transition-$name" > /dev/null
	output_status=$?
	set -e
	if [[ "$output_status" == 0 ]]; then
		echo "Transition accepted a pre-existing diagnostic artifact: $artifact." >&2
		exit 1
	fi
	if [[ "$artifact_kind" == symlink ]]; then
		if [[ -e "$target" || -L "$target" ]]; then
			echo "Transition wrote through a diagnostic artifact symlink: $artifact." >&2
			exit 1
		fi
	else
		if [[ "$(< "$output_workspace/$artifact")" != 'pre-existing transition diagnostic' ]]; then
			echo "Transition overwrote a diagnostic artifact: $artifact." >&2
			exit 1
		fi
	fi
	if [[ "$artifact" == wp-env-start-1.log && -e "$output_runtime/wp-env" ]] ||
		[[ -e "$output_runtime/compose-wordpress-log-attempts" ]]; then
		echo "Transition invoked a diagnostic command after rejecting $artifact." >&2
		exit 1
	fi
}

assert_diagnostic_output_rejected first-start-diagnostic-overwrite wp-env-start-1.log "$TEST_ROOT/start-diagnostic-overwrite-target" overwrite second-succeeds 19167
assert_diagnostic_output_rejected first-start-diagnostic-symlink wp-env-start-1.log "$TEST_ROOT/start-diagnostic-symlink-target" symlink second-succeeds 19168
assert_diagnostic_output_rejected first-wordpress-diagnostic-overwrite wp-env-wordpress.log "$TEST_ROOT/wordpress-diagnostic-overwrite-target" overwrite wordpress-artifact-overwrite-after-first-failure 19169
assert_diagnostic_output_rejected first-wordpress-diagnostic-symlink wp-env-wordpress.log "$TEST_ROOT/wordpress-diagnostic-symlink-target" symlink wordpress-artifact-symlink-after-first-failure 19170

create_workspace="$TEST_ROOT/woopayments-native-transition-create-run"
create_runtime="$TEST_ROOT/runtime-create"
create_log="$TEST_ROOT/create-commands.log"
mkdir "$create_workspace"
create_result="$(
	E2E_TRANSITION_SCENARIO=cutover-reconciliation run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
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
	if ( "wp-content/plugins/woocommerce-subscriptions" in config.mappings ) process.exit( 1 );
	if ( "optional_artifacts" in JSON.parse( readFileSync( process.argv[ 3 ] + "/resource-state.json", "utf8" ) ) ) process.exit( 1 );
	if ( require( "node:fs" ).existsSync( process.argv[ 3 ] + "/wcs-compose-preload.cjs" ) ) process.exit( 1 );
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
if ! grep -Fq 'option set woocommerce_woocommerce_payments_settings --format=json {"enabled":"yes","saved_cards":"yes","upe_enabled_payment_method_ids":["card","future_lpm"]}' "$create_log"; then
	echo 'Reconciliation store did not seed the exact unsupported payment method blocker.' >&2
	exit 1
fi
test -f "$create_runtime/reference-fixture-injected"
if [[ ! -f "$create_runtime/reference-account-seeded" ]]; then
	echo 'Cutover setup did not seed the validated reference account after refresh.' >&2
	exit 1
fi
if [[ ! -f "$create_runtime/native-active-seeded" ]]; then
	echo 'Cutover setup did not persist native ACTIVE state after the account seed.' >&2
	exit 1
fi
if [[ -e "$create_runtime/native-available-seeded" ]]; then
	echo 'Cutover setup incorrectly persisted native AVAILABLE state.' >&2
	exit 1
fi
if grep -Fq '<!-- wp:woocommerce/checkout /-->' "$create_log"; then
	echo 'Transition setup replaced the installed Checkout inner-block tree.' >&2
	exit 1
fi
if grep -Eq 'transition_register_blog|test-lab account create|wcpay callback probe' "$create_log"; then
	echo 'Transition create provisioned external resources instead of borrowing the reference fixture.' >&2
	exit 1
fi
if grep -RqE '77\\.real-(blog|user)-token|private-account-payload' "$create_workspace" "$create_log"; then
	echo 'Transition setup persisted the borrowed Jetpack credentials.' >&2
	exit 1
fi

E2E_FAKE_ACCOUNT_RUNTIME=native_core E2E_FAKE_POST_CUTOVER_IDENTITY=1 run_provisioner "$create_workspace" "$create_runtime" "$create_log" \
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

profile_11_wp_env="$TEST_ROOT/profile-11-wp-env"
cat > "$profile_11_wp_env" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$*" == *'plugin get woocommerce-payments --field=version'* ]]; then
	printf '11.1.0\n'
	exit 0
fi
exec "${E2E_FAKE_WP_ENV_TARGET:?}" "$@"
SH
chmod 0700 "$profile_11_wp_env"
profile_11_workspace="$TEST_ROOT/woopayments-native-transition-profile-11-create"
profile_11_runtime="$TEST_ROOT/runtime-profile-11-create"
profile_11_log="$TEST_ROOT/profile-11-create-commands.log"
mkdir "$profile_11_workspace"
profile_11_result="$(
	E2E_TRANSITION_SEED_PROFILE=11.1.0 \
	E2E_TRANSITION_FRONTEND_LOCK_SHA256="$profile_11_frontend_lock_hash" \
	E2E_TRANSITION_COMPOSER_LOCK_SHA256="$profile_11_composer_lock_hash" \
	E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_TRANSITION_SCENARIO=cutover-reconciliation \
	E2E_TRANSITION_PORT=19092 \
	run_provisioner "$profile_11_workspace" "$profile_11_runtime" "$profile_11_log" \
		create \
		--workspace "$profile_11_workspace" \
		--seed-archive "$TEST_ROOT/profile-11-seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/profile-11-seed.json" \
		--run-id profile-11-create \
		--base-url 'http://transition-profile-11-create.localhost:19092' \
		--store-id 'woopayments-native-transition-profile-11-create'
)"
node -e '
	const { createHash } = require( "node:crypto" );
	const { readFileSync } = require( "node:fs" );
	const result = JSON.parse( process.argv[ 1 ] );
	const state = JSON.parse( readFileSync( process.argv[ 2 ], "utf8" ) );
	const config = JSON.parse( readFileSync( process.argv[ 3 ], "utf8" ) );
	const seedHash = createHash( "sha256" ).update( readFileSync( process.argv[ 4 ] ) ).digest( "hex" );
	if ( result.plugin_version !== "11.1.0" ) process.exit( 1 );
	if ( state.plugin_version !== "11.1.0" || state.seed_hash !== seedHash ) process.exit( 1 );
	if ( config.mappings[ "wp-content/plugins/woocommerce-payments" ] !== `${ process.argv[ 5 ] }/seed/woocommerce-payments` ) process.exit( 1 );
' \
	"$profile_11_result" \
	"$profile_11_workspace/resource-state.json" \
	"$profile_11_workspace/store/.wp-env.json" \
	"$TEST_ROOT/profile-11-seed.tar.gz" \
	"$profile_11_workspace"
cmp \
	"$profile_11_tree/woocommerce-payments/pnpm-lock.yaml" \
	"$profile_11_workspace/seed/woocommerce-payments/pnpm-lock.yaml"
test ! -e "$profile_11_workspace/seed/woocommerce-payments/package-lock.json"
E2E_TRANSITION_SEED_PROFILE=11.1.0 \
	E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$profile_11_workspace" "$profile_11_runtime" "$profile_11_log" \
	destroy \
	--workspace "$profile_11_workspace" \
	--rollback-receipt-file "$profile_11_workspace/rollback-receipt"
test "$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1])).phase" "$profile_11_workspace/resource-state.json")" = 'destroyed'

historical_transition_port=19093
for historical_scenario in historical-pay-for-order-ctp-false historical-pay-for-order-ctp-true; do
	historical_workspace="$TEST_ROOT/$historical_scenario"
	historical_runtime="$TEST_ROOT/$historical_scenario-runtime"
	historical_log="$TEST_ROOT/$historical_scenario-commands.log"
	mkdir "$historical_workspace"
	E2E_TRANSITION_SEED_PROFILE=11.1.0 \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$profile_11_frontend_lock_hash" \
		E2E_TRANSITION_COMPOSER_LOCK_SHA256="$profile_11_composer_lock_hash" \
		E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
		E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		E2E_TRANSITION_SCENARIO="$historical_scenario" \
		E2E_TRANSITION_PORT="$historical_transition_port" \
		run_provisioner "$historical_workspace" "$historical_runtime" "$historical_log" \
		create \
		--workspace "$historical_workspace" \
		--seed-archive "$TEST_ROOT/profile-11-seed.tar.gz" \
		--seed-manifest "$TEST_ROOT/profile-11-seed.json" \
		--run-id "$historical_scenario" \
		--base-url "http://transition-$historical_scenario.localhost:$historical_transition_port" \
		--store-id "woopayments-native-transition-$historical_scenario" > /dev/null
	if [[ ! -f "$historical_runtime/reference-account-seeded" ]]; then
		echo "Historical scenario did not seed the exact validated reference account: $historical_scenario." >&2
		exit 1
	fi
	if [[ ! -f "$historical_runtime/native-available-seeded" ]]; then
		echo "Historical scenario did not persist native AVAILABLE state after the account seed: $historical_scenario." >&2
		exit 1
	fi
	if [[ -e "$historical_runtime/native-active-seeded" ]]; then
		echo "Historical scenario incorrectly seeded native ACTIVE state: $historical_scenario." >&2
		exit 1
	fi
	if grep -Fq 'transition_prepare_classic_checkout' "$historical_log"; then
		echo "Historical scenario provisioned a standing Classic Checkout page: $historical_scenario." >&2
		exit 1
	fi
	E2E_TRANSITION_SEED_PROFILE=11.1.0 \
		E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
		E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
		run_provisioner "$historical_workspace" "$historical_runtime" "$historical_log" \
		destroy \
		--workspace "$historical_workspace" \
		--rollback-receipt-file "$historical_workspace/rollback-receipt"
	historical_transition_port=$((historical_transition_port + 1))
done

unseeded_workspace="$TEST_ROOT/unseeded-reference-account"
unseeded_runtime="$TEST_ROOT/unseeded-reference-account-runtime"
unseeded_log="$TEST_ROOT/unseeded-reference-account-commands.log"
mkdir "$unseeded_workspace"
E2E_TRANSITION_SEED_PROFILE=11.1.0 \
	E2E_TRANSITION_FRONTEND_LOCK_SHA256="$profile_11_frontend_lock_hash" \
	E2E_TRANSITION_COMPOSER_LOCK_SHA256="$profile_11_composer_lock_hash" \
	E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_TRANSITION_SCENARIO=unrelated-scenario \
	E2E_TRANSITION_PORT="$historical_transition_port" \
	run_provisioner "$unseeded_workspace" "$unseeded_runtime" "$unseeded_log" \
	create \
	--workspace "$unseeded_workspace" \
	--seed-archive "$TEST_ROOT/profile-11-seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/profile-11-seed.json" \
	--run-id unrelated-scenario \
	--base-url "http://transition-unrelated-scenario.localhost:$historical_transition_port" \
	--store-id woopayments-native-transition-unrelated-scenario > /dev/null
test ! -e "$unseeded_runtime/reference-account-seeded"
test ! -e "$unseeded_runtime/native-active-seeded"
test ! -e "$unseeded_runtime/native-available-seeded"
if [[ "$(grep -Fc 'transition_prepare_classic_checkout' "$unseeded_log")" != '1' ]]; then
	echo 'Non-historical scenario did not provision exactly one standing Classic Checkout page.' >&2
	exit 1
fi
E2E_TRANSITION_SEED_PROFILE=11.1.0 \
	E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	run_provisioner "$unseeded_workspace" "$unseeded_runtime" "$unseeded_log" \
	destroy \
	--workspace "$unseeded_workspace" \
	--rollback-receipt-file "$unseeded_workspace/rollback-receipt"

state_failure_workspace="$TEST_ROOT/native-state-failure"
state_failure_runtime="$TEST_ROOT/native-state-failure-runtime"
state_failure_log="$TEST_ROOT/native-state-failure.log"
mkdir "$state_failure_workspace"
if E2E_FAKE_NATIVE_STATE_WRITE_FAIL=1 E2E_TRANSITION_SCENARIO=cutover-reconciliation E2E_TRANSITION_PORT=19146 \
	run_provisioner "$state_failure_workspace" "$state_failure_runtime" "$state_failure_log" \
	create --workspace "$state_failure_workspace" --seed-archive "$TEST_ROOT/seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/seed.json" --run-id native-state-failure \
	--base-url 'http://transition-native-state-failure.localhost:19146' \
	--store-id 'woopayments-native-transition-native-state-failure' > /dev/null 2> "$TEST_ROOT/native-state-failure.stderr"; then
	echo 'Creation accepted a failed native ACTIVE state write.' >&2
	exit 1
fi
grep -Fq 'Native payments ACTIVE state could not be seeded.' "$TEST_ROOT/native-state-failure.stderr"
test ! -e "$state_failure_runtime/native-active-seeded"
run_provisioner "$state_failure_workspace" "$state_failure_runtime" "$state_failure_log" \
	destroy --workspace "$state_failure_workspace" --rollback-receipt-file "$state_failure_workspace/rollback-receipt"
test ! -e "$state_failure_runtime/wp-env"
test ! -e "$SHARED_TMPDIR/woopayments-native-transition-port-leases/19146"

available_state_failure_workspace="$TEST_ROOT/native-available-state-failure"
available_state_failure_runtime="$TEST_ROOT/native-available-state-failure-runtime"
available_state_failure_log="$TEST_ROOT/native-available-state-failure.log"
mkdir "$available_state_failure_workspace"
if E2E_FAKE_NATIVE_STATE_WRITE_FAIL=1 E2E_TRANSITION_SCENARIO=historical-pay-for-order-ctp-false E2E_TRANSITION_SEED_PROFILE=11.1.0 \
	E2E_TRANSITION_FRONTEND_LOCK_SHA256="$profile_11_frontend_lock_hash" \
	E2E_TRANSITION_COMPOSER_LOCK_SHA256="$profile_11_composer_lock_hash" \
	E2E_TRANSITION_WP_ENV_BIN="$profile_11_wp_env" \
	E2E_FAKE_WP_ENV_TARGET="$SCRIPT_DIR/test-fixtures/fake-transition-pnpm.sh" \
	E2E_TRANSITION_PORT=19147 \
	run_provisioner "$available_state_failure_workspace" "$available_state_failure_runtime" "$available_state_failure_log" \
	create --workspace "$available_state_failure_workspace" --seed-archive "$TEST_ROOT/profile-11-seed.tar.gz" \
	--seed-manifest "$TEST_ROOT/profile-11-seed.json" --run-id native-available-state-failure \
	--base-url 'http://transition-native-available-state-failure.localhost:19147' \
	--store-id 'woopayments-native-transition-native-available-state-failure' > /dev/null 2> "$TEST_ROOT/native-available-state-failure.stderr"; then
	echo 'Creation accepted a failed native AVAILABLE state write.' >&2
	exit 1
fi
grep -Fq 'Native payments AVAILABLE state could not be seeded.' "$TEST_ROOT/native-available-state-failure.stderr"
test ! -e "$available_state_failure_runtime/native-available-seeded"
E2E_TRANSITION_SEED_PROFILE=11.1.0 \
	run_provisioner "$available_state_failure_workspace" "$available_state_failure_runtime" "$available_state_failure_log" \
	destroy --workspace "$available_state_failure_workspace" --rollback-receipt-file "$available_state_failure_workspace/rollback-receipt"
test ! -e "$available_state_failure_runtime/wp-env"
test ! -e "$SHARED_TMPDIR/woopayments-native-transition-port-leases/19147"

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
const network = /multisite-convert|site create|site list|--network|sub_transition_network_marker|"transition-network-(mixed|reopened)"|transition_network_secondary_fixture|woocommerce_woopayments_cutover_state|_wcpay_subscription_id|wcpay_migrate_subscription_retry|wp_create_nonce/.test(command);
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
 if (command.includes('wp_create_nonce')) {
  const base = JSON.parse(fs.readFileSync(process.env.E2E_FAKE_TRANSITION_WORKSPACE + '/resource-state.json')).base_url;
  if (!args.includes('--url=' + base) && !args.includes('--url=' + base + '/cutover-secondary')) throw Error('Nonce generation needs exact site context');
 }
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
const nonce = site === 9 ? 'nonce00009' : 'nonce00003';
const expected = state.http % 2 ? siteUrl + '/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=' + nonce : siteUrl + '/wp-cron.php?doing_wp_cron=';
if (state.http % 2 && new URL(url).searchParams.get('nonce') !== nonce) throw Error('Async request missing the exact anonymous site nonce');
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
$fixture_user = 1;
function wp_set_current_user( $id ) { global $fixture_user; $fixture_user = $id; }
function wp_create_nonce( $action ) {
 global $fixture, $blog_id, $fixture_user;
 if ( $action !== 'as_async_request_queue_runner' || $fixture_user !== 0 ) throw new Exception( 'Async nonce must use exact action and anonymous user' );
 $fixture['nonce_sites'][] = $blog_id;
 $fault = getenv( 'E2E_FAKE_NETWORK_FAULT' );
 if ( $fault === 'nonce-empty' ) return '';
 if ( $fault === 'nonce-invalid' ) return 'bad&nonce=';
 if ( $fault === 'nonce-swapped' ) return $blog_id === 9 ? 'nonce00003' : 'nonce00009';
 return $blog_id === 9 ? 'nonce00009' : 'nonce00003';
}
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
 [ '/cutover-secondary', '', '', '/cutover-secondary', '', '/cutover-secondary' ].flatMap( site => [ base + site + '/wp-cron.php?doing_wp_cron=TIME', base + site + '/wp-admin/admin-ajax.php?action=as_async_request_queue_runner&nonce=' + ( site ? 'nonce00009' : 'nonce00003' ) ] ) );
const commands = fs.readFileSync( process.env.E2E_FAKE_NETWORK_COMMAND_LOG, 'utf8' ).trim().split( '\n' ).map( line => JSON.parse( line ).join( ' ' ) ).join( '\n' );
for ( const pattern of [ 'core multisite-convert', 'site create', 'transition-network-mixed', 'transition-network-reopened', 'woocommerce_woopayments_cutover_state', 'scheduled_date_gmt', 'scheduled_date_local' ] ) assert.ok( commands.includes( pattern ), 'Missing executed command: ' + pattern );
assert.doesNotMatch( commands, /do_action|action-scheduler\s+(run|execute)|ActionScheduler_.*Runner|action_scheduler_run/ );
assert.doesNotMatch( commands, /transition_seed_reference_account/ );
assert.ok( commands.indexOf( 'plugin activate woocommerce --network' ) >= 0 );
assert.ok( commands.indexOf( 'plugin activate woocommerce --network' ) < commands.indexOf( 'plugin activate woocommerce-payments --network' ) );
const secondaryCommands = fs.readFileSync( process.env.E2E_FAKE_NETWORK_COMMAND_LOG, 'utf8' ).trim().split( '\n' ).map( line => JSON.parse( line ).join( ' ' ) ).filter( command => command.includes( '--url=http://transition-network-create.localhost:19119/cutover-secondary' ) ).join( '\n' );
for ( const command of [ 'local_wpcom_jetpack enable', 'wcpay_dev redirect_to', 'transition_inject_reference_fixture', 'wcpay_dev refresh_account_data', 'woocommerce_woocommerce_payments_settings', 'transition_network_secondary_fixture' ] ) assert.ok( secondaryCommands.includes( command ), 'Missing secondary fixture command: ' + command );
const runtime = JSON.parse( fs.readFileSync( process.env.E2E_FAKE_NETWORK_STATE ) );
assert.equal( runtime.http, 12 );
assert.deepEqual( runtime.nonce_sites, [ 9, 3, 3, 9, 3, 9 ] );
assert.deepEqual( runtime.due.sort(), [ 3, 9 ] );
for ( const site of [ 3, 9 ] ) assert.ok( runtime.events.indexOf( 'inspect:' + site ) < runtime.events.indexOf( 'due:' + site ) );
JS

for fault in final-duplicate final-generation final-active action-args action-hook action-group action-status state-generation nonce-empty nonce-invalid nonce-swapped; do
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
const fault = process.argv[ 3 ];
assert.equal( runtime.http, fault.startsWith( 'final-' ) ? 12 : fault === 'nonce-swapped' ? 1 : fault.startsWith( 'nonce-' ) ? 0 : 8 );
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
