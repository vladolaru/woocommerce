#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
BUILDER="$SCRIPT_DIR/build-transition-seed.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-seed-test.XXXXXX")"
ARCHIVE_GIT_BIN="$(command -v git)"
GZIP_BIN="$(command -v gzip)"

cleanup() {
	find "$TEST_ROOT" -type d -exec chmod u+w {} + 2> /dev/null || true
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

mode_of() {
	stat -f '%Lp' "$1" 2> /dev/null || stat -c '%a' "$1"
}

mkdir -p "$TEST_ROOT/repository" "$TEST_ROOT/tree/woocommerce-payments"
printf 'source must remain unchanged\n' > "$TEST_ROOT/repository/sentinel"
cat > "$TEST_ROOT/tree/woocommerce-payments/woocommerce-payments.php" <<'PHP'
<?php
/**
 * Plugin Name: WooPayments
 * Version: 10.5.0
 */
PHP
printf 'immutable payload\n' > "$TEST_ROOT/tree/woocommerce-payments/payload.txt"
printf 'preserve\r\nexactly\r\n' > "$TEST_ROOT/tree/woocommerce-payments/crlf-payload.txt"
long_filename="canonical-$(printf 'x%.0s' {1..110}).txt"
if (( ${#long_filename} <= 100 )); then
	echo 'The canonical archive test requires a final filename longer than 100 bytes.' >&2
	exit 1
fi
printf 'long PAX path payload\n' > "$TEST_ROOT/tree/woocommerce-payments/$long_filename"
cat > "$TEST_ROOT/tree/woocommerce-payments/composer.json" <<'JSON'
{
	"name": "woocommerce/payments",
	"require": {
		"automattic/jetpack-autoloader": "5.0.15",
		"automattic/jetpack-config": "3.1.1"
	}
}
JSON
cat > "$TEST_ROOT/tree/woocommerce-payments/composer.lock" <<'JSON'
{
	"content-hash": "fake-content-hash",
	"packages": [
		{
			"name": "automattic/jetpack-autoloader",
			"version": "v5.0.15"
		},
		{
			"name": "automattic/jetpack-config",
			"version": "v3.1.1"
		}
	],
	"packages-dev": [
		{
			"name": "phpunit/phpunit",
			"version": "9.6.34"
		}
	]
}
JSON
printf '20.11\n' > "$TEST_ROOT/tree/woocommerce-payments/.nvmrc"
cat > "$TEST_ROOT/tree/woocommerce-payments/package.json" <<'JSON'
{
	"name": "woocommerce-payments",
	"version": "10.5.0",
	"scripts": {
		"build:client": "NODE_ENV=production webpack"
	}
}
JSON
cat > "$TEST_ROOT/tree/woocommerce-payments/package-lock.json" <<'JSON'
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
frontend_lock_hash="$(
	shasum -a 256 "$TEST_ROOT/tree/woocommerce-payments/package-lock.json" |
		awk '{ print $1 }'
)"
mkdir -p "$TEST_ROOT/poison-home/xdg/git"
cat > "$TEST_ROOT/poison-home/.gitconfig" <<'CONFIG'
[core]
	autocrlf = true
	filemode = true
[tar]
	umask = 0000
[user]
	name = Poisoned User
	email = poisoned@example.invalid
CONFIG
cat > "$TEST_ROOT/poison-home/xdg/git/config" <<'CONFIG'
[core]
	autocrlf = input
[tar]
	umask = 0777
CONFIG
test ! -e "$TEST_ROOT/tree/woocommerce-payments/vendor/autoload_packages.php"
test ! -e "$TEST_ROOT/tree/woocommerce-payments/node_modules"
test ! -e "$TEST_ROOT/tree/woocommerce-payments/dist"

run_builder() {
	local output_path="$1"
	shift
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_WCPAY_REPO="$TEST_ROOT/repository" \
		E2E_TRANSITION_GIT_BIN="$SCRIPT_DIR/test-fixtures/bin/git" \
		E2E_TRANSITION_ARCHIVE_GIT_BIN="$ARCHIVE_GIT_BIN" \
		E2E_TRANSITION_GZIP_BIN="$GZIP_BIN" \
		E2E_TRANSITION_COMPOSER_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-composer.sh" \
		E2E_TRANSITION_NODE_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-node.sh" \
		E2E_TRANSITION_NPM_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-npm.sh" \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash" \
		E2E_FAKE_SEED_TREE="$TEST_ROOT/tree" \
		E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
		E2E_FAKE_COMPOSER_SECRET='must-not-be-persisted' \
		E2E_FAKE_NPM_SECRET='frontend-secret-must-not-be-persisted' \
		HOME="$TEST_ROOT/poison-home" \
		XDG_CONFIG_HOME="$TEST_ROOT/poison-home/xdg" \
		"$@" \
		"$BUILDER" --output-dir "$output_path"
}

red_case_failed=0
wrong_node_log="$TEST_ROOT/wrong-node-commands.log"
if run_builder \
	"$TEST_ROOT/wrong-node" \
	E2E_FAKE_NODE_VERSION='v18.20.0' \
	E2E_FAKE_COMMAND_LOG="$wrong_node_log" > /dev/null 2>&1; then
	echo 'The seed builder accepted an executing Node outside the pinned 20.11 line.' >&2
	red_case_failed=1
fi
if [[ -f "$wrong_node_log" ]] &&
	grep -Eq '^composer |^npm ' "$wrong_node_log"; then
	echo 'The seed builder installed dependencies before rejecting the executing Node.' >&2
	red_case_failed=1
fi
if run_builder \
	"$TEST_ROOT/wrong-node-patch" \
	E2E_FAKE_NODE_VERSION='v20.11.9' > /dev/null 2>&1; then
	echo 'The seed builder accepted a Node patch release outside exact 20.11.1.' >&2
	red_case_failed=1
fi
wrong_npm_log="$TEST_ROOT/wrong-npm-commands.log"
if run_builder \
	"$TEST_ROOT/wrong-npm" \
	E2E_FAKE_NPM_VERSION='10.2.5' \
	E2E_FAKE_COMMAND_LOG="$wrong_npm_log" > /dev/null 2>&1; then
	echo 'The seed builder accepted npm outside exact 10.2.4.' >&2
	red_case_failed=1
fi
if [[ -f "$wrong_npm_log" ]] &&
	grep -Eq '^composer install |^npm ci ' "$wrong_npm_log"; then
	echo 'The seed builder installed dependencies before rejecting the executing npm.' >&2
	red_case_failed=1
fi

mkdir "$TEST_ROOT/tree/woocommerce-payments/node_modules"
printf 'mutable dependency\n' > "$TEST_ROOT/tree/woocommerce-payments/node_modules/forged.js"
if run_builder "$TEST_ROOT/preexisting-node-modules" > /dev/null 2>&1; then
	echo 'The seed builder accepted archived mutable node_modules.' >&2
	red_case_failed=1
fi
rm -rf "$TEST_ROOT/tree/woocommerce-payments/node_modules"

mkdir "$TEST_ROOT/tree/woocommerce-payments/dist"
printf 'forged distribution\n' > "$TEST_ROOT/tree/woocommerce-payments/dist/index.js"
if run_builder "$TEST_ROOT/preexisting-dist" > /dev/null 2>&1; then
	echo 'The seed builder accepted archived forged dist assets.' >&2
	red_case_failed=1
fi
rm -rf "$TEST_ROOT/tree/woocommerce-payments/dist"

ln -s payload.txt "$TEST_ROOT/tree/woocommerce-payments/unsafe-link"
if run_builder "$TEST_ROOT/symlink" > /dev/null 2>&1; then
	echo 'The seed builder accepted a symbolic link.' >&2
	red_case_failed=1
fi
rm "$TEST_ROOT/tree/woocommerce-payments/unsafe-link"

mkdir "$TEST_ROOT/tree/woocommerce-payments/empty-directory"
if run_builder "$TEST_ROOT/empty-directory" > /dev/null 2>&1; then
	echo 'The seed builder accepted an empty directory.' >&2
	red_case_failed=1
fi
rmdir "$TEST_ROOT/tree/woocommerce-payments/empty-directory"

control_path="$TEST_ROOT/tree/woocommerce-payments/"$'unsafe\npath.txt'
printf 'unsafe path\n' > "$control_path"
if run_builder "$TEST_ROOT/control-path" > /dev/null 2>&1; then
	echo 'The seed builder accepted a control character in an archive path.' >&2
	red_case_failed=1
fi
rm "$control_path"

printf '* text=auto\n' > "$TEST_ROOT/tree/woocommerce-payments/.gitattributes"
if run_builder "$TEST_ROOT/git-attributes" > /dev/null 2>&1; then
	echo 'The seed builder accepted archive-affecting .gitattributes.' >&2
	red_case_failed=1
fi
rm "$TEST_ROOT/tree/woocommerce-payments/.gitattributes"

special_stderr="$TEST_ROOT/special-stderr"
if run_builder \
	"$TEST_ROOT/special-entry" \
	E2E_FAKE_COMPOSER_MODE='special' > /dev/null 2> "$special_stderr"; then
	echo 'The seed builder accepted a special filesystem entry.' >&2
	red_case_failed=1
elif ! grep -Fq 'special filesystem entry' "$special_stderr"; then
	echo 'The seed builder did not explicitly reject a special filesystem entry.' >&2
	red_case_failed=1
fi

cp \
	"$TEST_ROOT/tree/woocommerce-payments/package-lock.json" \
	"$TEST_ROOT/original-package-lock.json"
node -e '
	const { readFileSync, writeFileSync } = require( "node:fs" );
	const lock = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	lock.packages[ "" ].version = "99.0.0-forged";
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( lock ) }\n` );
' "$TEST_ROOT/tree/woocommerce-payments/package-lock.json"
if run_builder "$TEST_ROOT/forged-lock-hash" > /dev/null 2>&1; then
	echo 'The seed builder accepted a frontend lock outside the pinned provenance.' >&2
	red_case_failed=1
fi
forged_frontend_lock_hash="$(
	shasum -a 256 "$TEST_ROOT/tree/woocommerce-payments/package-lock.json" |
		awk '{ print $1 }'
)"
if run_builder \
	"$TEST_ROOT/forged-lock-metadata" \
	E2E_TRANSITION_FRONTEND_LOCK_SHA256="$forged_frontend_lock_hash" > /dev/null 2>&1; then
	echo 'The seed builder accepted forged self-consistent frontend lock metadata.' >&2
	red_case_failed=1
fi
cp \
	"$TEST_ROOT/original-package-lock.json" \
	"$TEST_ROOT/tree/woocommerce-payments/package-lock.json"

if run_builder \
	"$TEST_ROOT/partial-dist" \
	E2E_FAKE_NPM_MODE='partial' > /dev/null 2>&1; then
	echo 'The seed builder accepted a partial generated distribution.' >&2
	red_case_failed=1
fi
if (( red_case_failed != 0 )); then
	exit 1
fi

find "$TEST_ROOT/tree" -exec touch -t 200101010101 {} +
output="$(
	run_builder \
		"$TEST_ROOT/output-one" \
		E2E_FAKE_GENERATED_MTIME='200102020202'
)"
find "$TEST_ROOT/tree" -exec touch -t 203012121212 {} +
second_output="$(
	run_builder \
		"$TEST_ROOT/output-two" \
		E2E_FAKE_GENERATED_MTIME='203011111111'
)"

archive_path="$(node -p 'JSON.parse(process.argv[1]).archive_path' "$output")"
manifest_path="$(node -p 'JSON.parse(process.argv[1]).manifest_path' "$output")"
second_archive_path="$(node -p 'JSON.parse(process.argv[1]).archive_path' "$second_output")"
second_manifest_path="$(node -p 'JSON.parse(process.argv[1]).manifest_path' "$second_output")"
cmp "$archive_path" "$second_archive_path"
cmp "$manifest_path" "$second_manifest_path"
test "$(mode_of "$archive_path")" = '444'
test "$(mode_of "$manifest_path")" = '444'
test "$(< "$TEST_ROOT/repository/sentinel")" = 'source must remain unchanged'
if grep -q 'worktree' "$TEST_ROOT/commands.log"; then
	echo 'The immutable seed builder must not create a Git worktree.' >&2
	exit 1
fi
canonical_tar_hash="$(
	"$GZIP_BIN" -cd "$archive_path" |
		shasum -a 256 |
		awk '{ print $1 }'
)"
expected_git_version="$("$ARCHIVE_GIT_BIN" --version)"
expected_gzip_version="$("$GZIP_BIN" --version 2>&1 | sed -n '1p')"
expected_zlib_version="$("$SCRIPT_DIR/test-fixtures/fake-transition-node.sh" -p 'process.versions.zlib')"
node -e '
	const { createHash } = require( "node:crypto" );
	const { readFileSync } = require( "node:fs" );
	const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	const digest = createHash( "sha256" )
		.update( readFileSync( process.argv[ 2 ] ) )
		.digest( "hex" );
	if ( manifest.schema_version !== 2 ) process.exit( 1 );
	if ( manifest.plugin_version !== "10.5.0" ) process.exit( 1 );
	if ( manifest.source_commit !== "a1f755fc903966387f8629f78f75976ac8d2016e" ) process.exit( 1 );
	if ( manifest.archive_sha256 !== digest ) process.exit( 1 );
	if ( manifest.canonical_tar_sha256 !== process.argv[ 4 ] ) process.exit( 1 );
	if ( manifest.archive_profile !== "git-sha1-fixed-pax+gzip-n9-v1" ) process.exit( 1 );
	if ( JSON.stringify( Object.keys( manifest.build_toolchain ).sort() ) !== JSON.stringify( [
		"composer", "git", "gzip", "node", "npm", "zlib",
	] ) ) process.exit( 1 );
	if ( manifest.build_toolchain.composer !== "Composer version 2.8.6 2025-06-10 13:15:10" ) process.exit( 1 );
	if ( manifest.build_toolchain.git !== process.argv[ 5 ] ) process.exit( 1 );
	if ( manifest.build_toolchain.gzip !== process.argv[ 6 ] ) process.exit( 1 );
	if ( manifest.build_toolchain.node !== "v20.11.1" ) process.exit( 1 );
	if ( manifest.build_toolchain.npm !== "10.2.4" ) process.exit( 1 );
	if ( manifest.build_toolchain.zlib !== process.argv[ 7 ] ) process.exit( 1 );
	if ( ! /^[a-f0-9]{64}$/.test( manifest.dependency_lock_sha256 ) ) process.exit( 1 );
	if ( manifest.production_package_count !== 2 ) process.exit( 1 );
	if ( manifest.frontend_lock_sha256 !== process.argv[ 3 ] ) process.exit( 1 );
	if ( manifest.frontend_lockfile_version !== 3 ) process.exit( 1 );
	if ( manifest.frontend_node_line !== "20.11" ) process.exit( 1 );
	if ( manifest.frontend_build_script !== "build:client" ) process.exit( 1 );
	if ( JSON.stringify( manifest.executable_seed_files ) !== JSON.stringify( [
		"vendor/autoload_packages.php",
		"vendor/autoload.php",
		"vendor/composer/installed.php",
		"vendor/composer/installed.json",
		"dist/index.js",
		"dist/index.css",
		"dist/checkout.js",
		"dist/blocks-checkout.js",
	] ) ) process.exit( 1 );
	if ( JSON.stringify( manifest.frontend_bundles.map( ( value ) => value.path ) ) !== JSON.stringify( [
		"dist/index.js",
		"dist/index.css",
		"dist/checkout.js",
		"dist/blocks-checkout.js",
	] ) ) process.exit( 1 );
	if ( manifest.frontend_bundles.some( ( value ) => ! /^[a-f0-9]{64}$/.test( value.sha256 ) ) ) process.exit( 1 );
	if ( JSON.stringify( manifest ).includes( "must-not-be-persisted" ) ) process.exit( 1 );
	if ( JSON.stringify( manifest ).includes( "frontend-secret-must-not-be-persisted" ) ) process.exit( 1 );
' \
	"$manifest_path" \
	"$archive_path" \
	"$frontend_lock_hash" \
	"$canonical_tar_hash" \
	"$expected_git_version" \
	"$expected_gzip_version" \
	"$expected_zlib_version"
node -e '
	const { readFileSync } = require( "node:fs" );
	const header = readFileSync( process.argv[ 1 ] ).subarray( 0, 10 );
	if ( header.length !== 10 || header[ 0 ] !== 0x1f || header[ 1 ] !== 0x8b ) process.exit( 1 );
	if ( ( header[ 3 ] & 0x08 ) !== 0 ) process.exit( 1 );
	if ( ! header.subarray( 4, 8 ).every( ( value ) => value === 0 ) ) process.exit( 1 );
' "$archive_path"
tar -tzf "$archive_path" | grep -Fqx 'woocommerce-payments/woocommerce-payments.php'
for executable_file in \
	'woocommerce-payments/vendor/autoload_packages.php' \
	'woocommerce-payments/vendor/autoload.php' \
	'woocommerce-payments/vendor/composer/installed.php' \
	'woocommerce-payments/vendor/composer/installed.json' \
	'woocommerce-payments/dist/index.js' \
	'woocommerce-payments/dist/index.css' \
	'woocommerce-payments/dist/checkout.js' \
	'woocommerce-payments/dist/blocks-checkout.js'; do
	tar -tzf "$archive_path" | grep -Fqx "$executable_file"
done
exact_extraction="$TEST_ROOT/exact-extraction"
mkdir "$exact_extraction"
tar -xzf "$archive_path" -C "$exact_extraction"
test "$(< "$exact_extraction/woocommerce-payments/$long_filename")" = 'long PAX path payload'
cmp \
	"$TEST_ROOT/tree/woocommerce-payments/crlf-payload.txt" \
	"$exact_extraction/woocommerce-payments/crlf-payload.txt"
if tar -tzf "$archive_path" | grep -Eq '(^|/)\.git(/|$)'; then
	echo 'The immutable seed archive must not contain Git metadata.' >&2
	exit 1
fi
if tar -tzf "$archive_path" | grep -Eq '(^|/)node_modules(/|$)'; then
	echo 'The immutable runtime seed must not contain build-only node_modules.' >&2
	exit 1
fi
grep -Fq 'composer install' "$TEST_ROOT/commands.log"
grep -Fq -- '--no-dev' "$TEST_ROOT/commands.log"
grep -Fq -- '--prefer-dist' "$TEST_ROOT/commands.log"
grep -Fq -- '--optimize-autoloader' "$TEST_ROOT/commands.log"
grep -Fq 'npm ci --ignore-scripts --no-audit --no-fund' "$TEST_ROOT/commands.log"
grep -Fq 'npm run --ignore-scripts build:client' "$TEST_ROOT/commands.log"
test ! -e "$TEST_ROOT/tree/woocommerce-payments/vendor"
test ! -e "$TEST_ROOT/tree/woocommerce-payments/node_modules"
test ! -e "$TEST_ROOT/tree/woocommerce-payments/dist"

if env \
	TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_WCPAY_REPO="$TEST_ROOT/repository" \
		E2E_TRANSITION_GIT_BIN="$SCRIPT_DIR/test-fixtures/bin/git" \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash" \
		E2E_FAKE_SEED_TREE="$TEST_ROOT/tree" \
	E2E_FAKE_GIT_COMMIT='0000000000000000000000000000000000000000' \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	"$BUILDER" --output-dir "$TEST_ROOT/wrong-commit" > /dev/null 2>&1; then
	echo 'The seed builder accepted the wrong immutable commit.' >&2
	exit 1
fi

for composer_mode in missing mismatch; do
	if env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_WCPAY_REPO="$TEST_ROOT/repository" \
		E2E_TRANSITION_GIT_BIN="$SCRIPT_DIR/test-fixtures/bin/git" \
		E2E_TRANSITION_COMPOSER_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-composer.sh" \
		E2E_TRANSITION_NODE_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-node.sh" \
		E2E_TRANSITION_NPM_BIN="$SCRIPT_DIR/test-fixtures/fake-transition-npm.sh" \
		E2E_TRANSITION_FRONTEND_LOCK_SHA256="$frontend_lock_hash" \
		E2E_FAKE_SEED_TREE="$TEST_ROOT/tree" \
		E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
		E2E_FAKE_COMPOSER_MODE="$composer_mode" \
		"$BUILDER" --output-dir "$TEST_ROOT/composer-$composer_mode" > /dev/null 2>&1; then
		echo "The seed builder accepted $composer_mode dependency artifacts." >&2
		exit 1
	fi
done

echo 'build-transition-seed.sh tests passed.'
