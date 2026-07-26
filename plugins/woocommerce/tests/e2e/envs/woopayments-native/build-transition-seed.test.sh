#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
BUILDER="$SCRIPT_DIR/build-transition-seed.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-seed-test.XXXXXX")"

cleanup() {
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

output="$(
	env \
		TMPDIR="$TEST_ROOT" \
		E2E_TRANSITION_WCPAY_REPO="$TEST_ROOT/repository" \
		E2E_TRANSITION_GIT_BIN="$SCRIPT_DIR/test-fixtures/bin/git" \
		E2E_FAKE_SEED_TREE="$TEST_ROOT/tree" \
		E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
		"$BUILDER" --output-dir "$TEST_ROOT/output"
)"

archive_path="$(node -p 'JSON.parse(process.argv[1]).archive_path' "$output")"
manifest_path="$(node -p 'JSON.parse(process.argv[1]).manifest_path' "$output")"
test "$(mode_of "$archive_path")" = '444'
test "$(mode_of "$manifest_path")" = '444'
test "$(< "$TEST_ROOT/repository/sentinel")" = 'source must remain unchanged'
if grep -q 'worktree' "$TEST_ROOT/commands.log"; then
	echo 'The immutable seed builder must not create a Git worktree.' >&2
	exit 1
fi
node -e '
	const { createHash } = require( "node:crypto" );
	const { readFileSync } = require( "node:fs" );
	const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	const digest = createHash( "sha256" )
		.update( readFileSync( process.argv[ 2 ] ) )
		.digest( "hex" );
	if ( manifest.schema_version !== 1 ) process.exit( 1 );
	if ( manifest.plugin_version !== "10.5.0" ) process.exit( 1 );
	if ( manifest.source_commit !== "a1f755fc903966387f8629f78f75976ac8d2016e" ) process.exit( 1 );
	if ( manifest.archive_sha256 !== digest ) process.exit( 1 );
' "$manifest_path" "$archive_path"
tar -tzf "$archive_path" | grep -Fqx 'woocommerce-payments/woocommerce-payments.php'
if tar -tzf "$archive_path" | grep -Eq '(^|/)\.git(/|$)'; then
	echo 'The immutable seed archive must not contain Git metadata.' >&2
	exit 1
fi

if env \
	TMPDIR="$TEST_ROOT" \
	E2E_TRANSITION_WCPAY_REPO="$TEST_ROOT/repository" \
	E2E_TRANSITION_GIT_BIN="$SCRIPT_DIR/test-fixtures/bin/git" \
	E2E_FAKE_SEED_TREE="$TEST_ROOT/tree" \
	E2E_FAKE_GIT_COMMIT='0000000000000000000000000000000000000000' \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log" \
	"$BUILDER" --output-dir "$TEST_ROOT/wrong-commit" > /dev/null 2>&1; then
	echo 'The seed builder accepted the wrong immutable commit.' >&2
	exit 1
fi

echo 'build-transition-seed.sh tests passed.'
