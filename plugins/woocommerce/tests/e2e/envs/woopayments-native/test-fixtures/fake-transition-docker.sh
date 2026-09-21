#!/usr/bin/env bash

set -euo pipefail

readonly WORKSPACE="${E2E_FAKE_TRANSITION_WORKSPACE:?E2E_FAKE_TRANSITION_WORKSPACE is required}"
readonly RUNTIME_STATE="${E2E_FAKE_RUNTIME_STATE:?E2E_FAKE_RUNTIME_STATE is required}"

expected_project="$(
	WP_ENV_HOME="$WORKSPACE/wp-env-home" node -e '
		const { realpathSync } = require( "node:fs" );
		const { loadConfig } = require( process.env.E2E_FAKE_WP_ENV_CONFIG_MODULE );
		loadConfig( process.argv[ 1 ] ).then( ( config ) => {
			process.stdout.write( realpathSync( config.workDirectoryPath ) );
		} ).catch( ( error ) => {
			console.error( error );
			process.exit( 1 );
		} );
	' "$WORKSPACE/store"
)"
readonly expected_project
readonly expected_compose="$expected_project/docker-compose.yml"

if [[ "$*" != "compose --project-directory $expected_project --file $expected_compose logs wordpress" ]] ||
	[[ ! -f "$expected_compose" || -L "$expected_compose" ]]; then
	echo 'Fake Docker was not asked for the exact wp-env WordPress service logs.' >&2
	exit 44
fi

mkdir -p "$RUNTIME_STATE"
printf '1\n' >> "$RUNTIME_STATE/compose-wordpress-log-attempts"
printf 'Fake exact WordPress service log.\n'
printf 'Fake exact WordPress service stderr.\n' >&2
