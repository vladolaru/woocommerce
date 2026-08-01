#!/usr/bin/env bash

set -euo pipefail

printf 'composer %s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"

if [[ "${1:-}" == '--version' ]]; then
	printf 'Composer version 2.8.6 2025-06-10 13:15:10\n'
	exit 0
fi

working_dir=''
for argument in "$@"; do
	case "$argument" in
		--working-dir=*)
			working_dir="${argument#--working-dir=}"
			;;
	esac
done
if [[ -z "$working_dir" || ! -f "$working_dir/composer.lock" ]]; then
	echo 'Fake Composer requires an extracted working directory with composer.lock.' >&2
	exit 1
fi

mkdir -p "$working_dir/vendor/composer"
printf '<?php // generated Composer autoloader\n' > "$working_dir/vendor/autoload.php"
printf '<?php // generated installed package map\n' > "$working_dir/vendor/composer/installed.php"
node -e '
	const { readFileSync, writeFileSync } = require( "node:fs" );
	const lock = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	const packages = lock.packages.map( ( value ) => ( {
		name: value.name,
		version: value.version,
	} ) );
	if ( process.argv[ 3 ] === "mismatch" ) packages[ 0 ].version = "v0.0.0-forged";
	writeFileSync(
		process.argv[ 2 ],
		`${ JSON.stringify( { packages } ) }\n`
	);
' \
	"$working_dir/composer.lock" \
	"$working_dir/vendor/composer/installed.json" \
	"${E2E_FAKE_COMPOSER_MODE:-good}"

if [[ "${E2E_FAKE_COMPOSER_MODE:-good}" == 'special' ]]; then
	mkfifo "$working_dir/vendor/unsupported-pipe"
elif [[ "${E2E_FAKE_COMPOSER_MODE:-good}" != 'missing' ]]; then
	printf '<?php // generated Jetpack package autoloader\n' > "$working_dir/vendor/autoload_packages.php"
fi

if [[ -n "${E2E_FAKE_GENERATED_MTIME:-}" ]]; then
	find "$working_dir/vendor" -exec touch -t "$E2E_FAKE_GENERATED_MTIME" {} +
fi

printf 'fake-composer-output-with-secret=%s\n' "${E2E_FAKE_COMPOSER_SECRET:-unset}"
