#!/usr/bin/env bash

set -euo pipefail

readonly WORKSPACE="${E2E_FAKE_TRANSITION_WORKSPACE:?E2E_FAKE_TRANSITION_WORKSPACE is required}"
readonly RUNTIME_STATE="${E2E_FAKE_RUNTIME_STATE:?E2E_FAKE_RUNTIME_STATE is required}"
readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?E2E_FAKE_COMMAND_LOG is required}"

mkdir -p "$RUNTIME_STATE"
printf 'wpcom-local\t%s\t%s\n' "$PWD" "$*" >> "$COMMAND_LOG"

if [[ "$*" == *'transition_register_blog'* ]]; then
	node -e '
		const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
		require( "node:fs" ).writeFileSync(
			process.argv[ 2 ],
			String( state.wpcom_blog_registration_attempted === true )
		);
	' \
		"$WORKSPACE/resource-state.json" \
		"$RUNTIME_STATE/blog-intent-before-registration"
	touch "$RUNTIME_STATE/blog"
	if [[ "${E2E_FAKE_BLOG_REGISTER_AFTER_CREATE_FAIL:-0}" == '1' ]]; then
		echo 'Fake blog registration failed after creating the exact blog.' >&2
		exit 51
	fi
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

if [[ "$*" == *'transition_find_blog_identity'* ]]; then
	node -e '
		const { existsSync, readFileSync } = require( "node:fs" );
		const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const mode = process.argv[ 3 ];
		let matches = [];
		if ( existsSync( process.argv[ 2 ] ) && mode !== "none" ) {
			matches.push( {
				wpcom_blog_id: 77,
				domain: state.domain,
				home: state.home,
				marker: state.marker,
			} );
			if ( mode === "ambiguous" ) {
				matches.push( {
					wpcom_blog_id: 78,
					domain: state.domain,
					home: state.home,
					marker: state.marker,
				} );
			}
		}
		process.stdout.write( JSON.stringify( { matches } ) );
	' \
		"$WORKSPACE/resource-state.json" \
		"$RUNTIME_STATE/blog" \
		"${E2E_FAKE_BLOG_RECOVERY_MODE:-unique}"
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
	node -e '
		const state = JSON.parse( require( "node:fs" ).readFileSync( process.argv[ 1 ], "utf8" ) );
		if ( ! Number.isSafeInteger( state.wpcom_blog_id ) || state.wpcom_blog_id <= 0 ) {
			process.exit( 1 );
		}
		require( "node:fs" ).writeFileSync( process.argv[ 2 ], String( state.wpcom_blog_id ) );
	' \
		"$WORKSPACE/resource-state.json" \
		"$RUNTIME_STATE/blog-id-before-delete"
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
