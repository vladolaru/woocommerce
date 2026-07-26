#!/usr/bin/env bash

set -euo pipefail

readonly WORKSPACE="${E2E_FAKE_TRANSITION_WORKSPACE:?E2E_FAKE_TRANSITION_WORKSPACE is required}"
readonly RUNTIME_STATE="${E2E_FAKE_RUNTIME_STATE:?E2E_FAKE_RUNTIME_STATE is required}"
readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"

mkdir -p "$RUNTIME_STATE"
printf 'wpcom-local\t%s\t%s\n' "$PWD" "$*" >> "$COMMAND_LOG"

if [[ "$*" == *'transition_register_blog'* ]]; then
	touch "$RUNTIME_STATE/blog"
	node -e '
		const { readFileSync } = require( "node:fs" );
		const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		process.stdout.write( `wpcom-local starting\n${ JSON.stringify( {
			wpcom_blog_id: 77,
			domain: new URL( state.base_url ).hostname,
			home: state.home,
			marker: state.marker,
		} ) }\nwpcom-local completed\n` );
	' "$WORKSPACE/resource-state.json"
	exit 0
fi

if [[ "$*" == *'transition_blog_identity'* ]]; then
	node -e '
		const { existsSync, readFileSync } = require( "node:fs" );
		const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		if ( ! existsSync( process.argv[ 2 ] ) ) {
			process.stdout.write( `wpcom-local starting\n${ JSON.stringify( {
				exists: false,
				wpcom_blog_id: state.wpcom_blog_id,
			} ) }\nwpcom-local completed\n` );
			process.exit();
		}
		process.stdout.write( `wpcom-local starting\n${ JSON.stringify( {
			exists: true,
			wpcom_blog_id: state.wpcom_blog_id,
			domain: state.domain,
			home: state.home,
			marker: state.marker,
		} ) }\nwpcom-local completed\n` );
	' "$WORKSPACE/resource-state.json" "$RUNTIME_STATE/blog"
	exit 0
fi

if [[ "$*" == *'transition_delete_blog'* ]]; then
	rm -f "$RUNTIME_STATE/blog"
	printf 'deleted\n'
	exit 0
fi

if [[ "$*" == *'wcpay callback probe'* ]]; then
	store_url=''
	blog_id=''
	while (( $# > 0 )); do
		case "$1" in
			--store-url)
				store_url="${2:-}"
				shift 2
				;;
			--wpcom-blog-id)
				blog_id="${2:-}"
				shift 2
				;;
			*)
				shift
				;;
		esac
	done
	printf 'wpcom-local starting\n{"status":"success","exit_code":0,"provider_secret":"must-not-be-persisted","context":{"store_url":"%s","wpcom_blog_id":"%s","callback_registered":"true","callback_reachable":"true","callback_auth_model":"jetpack_capability","callback_provider_write":"false","callback_response_result":"success"}}\nwpcom-local completed\n' \
		"$store_url" \
		"$blog_id"
	exit 0
fi

echo "Unexpected fake transition wpcom-local invocation: $*" >&2
exit 1
