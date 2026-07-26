#!/usr/bin/env bash

set -euo pipefail

case "${1:-}" in
	plan)
		printf '{"base_url":"%s","store_id":"%s","plugin_version":"%s"}\n' \
			"${E2E_FAKE_TRANSITION_BASE_URL:-http://transition.localhost:8899}" \
			"${E2E_FAKE_TRANSITION_STORE_ID:-transition-store-test}" \
			"${E2E_FAKE_TRANSITION_PLUGIN_VERSION:-10.5.0}"
		;;
	create)
		workspace=''
		shift
		while (( $# > 0 )); do
			case "$1" in
				--workspace)
					workspace="${2:-}"
					shift 2
					;;
				*)
					shift
					;;
			esac
		done
		actual_base_url="${E2E_FAKE_CREATED_BASE_URL:-${E2E_FAKE_TRANSITION_BASE_URL:-http://transition.localhost:8899}}"
		actual_store_id="${E2E_FAKE_CREATED_STORE_ID:-${E2E_FAKE_TRANSITION_STORE_ID:-transition-store-test}}"
		plugin_version="${E2E_FAKE_CREATED_PLUGIN_VERSION:-${E2E_FAKE_TRANSITION_PLUGIN_VERSION:-10.5.0}}"
		wpcom_blog_id="${E2E_FAKE_WPCOM_BLOG_ID:-77}"
		account_id="${E2E_FAKE_ACCOUNT_ID:-acct_transition_test}"
		rollback_receipt="$(
			node -e '
				const { createHash } = require( "node:crypto" );
				const ownership = JSON.stringify( process.argv.slice( 1 ) );
				process.stdout.write(
					`receipt_${ createHash( "sha256" ).update( ownership ).digest( "hex" ) }`
				);
			' "$workspace" "$actual_base_url" "$actual_store_id"
		)"
		receipt_mode="${E2E_FAKE_RECEIPT_MODE:-valid}"
		if [[ "$receipt_mode" == 'valid' ]]; then
			printf '%s' "$rollback_receipt" > "$workspace/rollback-receipt"
			chmod 0600 "$workspace/rollback-receipt"
		elif [[ "$receipt_mode" == 'invalid' ]]; then
			printf 'invalid receipt' > "$workspace/rollback-receipt"
			chmod 0600 "$workspace/rollback-receipt"
		fi
		state_path="$workspace/fake-created-resource.json"
		printf '%s' "$rollback_receipt" | node -e '
			const {
				closeSync,
				openSync,
				readFileSync,
				writeFileSync,
			} = require( "node:fs" );
			const receipt = readFileSync( 0, "utf8" );
			const statePath = process.argv[ 1 ];
			const state = {
				base_url: process.argv[ 2 ],
				store_id: process.argv[ 3 ],
				rollback_receipt: receipt,
				wpcom_blog_id: Number( process.argv[ 4 ] ),
				account_id: process.argv[ 5 ],
			};
			const fd = openSync( statePath, "wx", 0o600 );
			try {
				writeFileSync( fd, `${ JSON.stringify( state ) }\n` );
			} finally {
				closeSync( fd );
			}
		' "$state_path" "$actual_base_url" "$actual_store_id" "$wpcom_blog_id" "$account_id"
		if [[ "${E2E_FAKE_BLOCK_ALLOCATION_WRITE:-0}" == '1' ]]; then
			mkdir "$workspace/allocation.json"
		fi
		printf '%s' "$rollback_receipt" | node -e '
			const { readFileSync } = require( "node:fs" );
			const result = {
				base_url: process.argv[ 1 ],
				store_id: process.argv[ 2 ],
				plugin_version: process.argv[ 3 ],
				wpcom_blog_id: Number( process.argv[ 5 ] ),
				account_id: process.argv[ 6 ],
			};
			const receipt = readFileSync( 0, "utf8" );
			if ( process.argv[ 4 ] === "valid" ) {
				result.rollback_receipt = receipt;
			} else if ( process.argv[ 4 ] === "invalid" ) {
				result.rollback_receipt = "invalid receipt";
			}
			process.stdout.write( `${ JSON.stringify( result ) }\n` );
		' "$actual_base_url" "$actual_store_id" "$plugin_version" "$receipt_mode" "$wpcom_blog_id" "$account_id"
		if [[ "${E2E_FAKE_CREATE_PARTIAL_FAILURE:-0}" == '1' ]]; then
			echo 'Fake transition create failed after creating the store.' >&2
			exit 1
		fi
		;;
	destroy)
		if [[ -z "${E2E_FAKE_PROVISIONER_LOG:-}" ]]; then
			echo 'E2E_FAKE_PROVISIONER_LOG is required.' >&2
			exit 1
		fi
		workspace=''
		rollback_receipt_file=''
		shift
		while (( $# > 0 )); do
			case "$1" in
				--workspace)
					workspace="${2:-}"
					shift 2
					;;
				--rollback-receipt-file)
					rollback_receipt_file="${2:-}"
					shift 2
					;;
				*)
					echo "Unknown fake destroy argument: $1" >&2
					exit 1
					;;
			esac
		done
		if [[ ! -f "$rollback_receipt_file" ]]; then
			echo 'Fake transition destroy requires a rollback receipt file.' >&2
			exit 1
		fi
		state_path="$workspace/fake-created-resource.json"
		if [[ ! -f "$state_path" ]]; then
			echo 'Fake transition destroy cannot find the created resource.' >&2
			exit 1
		fi
		if ! node -e '
			const { readFileSync } = require( "node:fs" );
			const state = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
			const receipt = readFileSync( process.argv[ 2 ], "utf8" ).trim();
			if ( receipt !== state.rollback_receipt ) process.exit( 1 );
		' "$state_path" "$rollback_receipt_file"; then
			echo 'Fake transition destroy received the wrong rollback receipt.' >&2
			exit 1
		fi
		actual_base_url="$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1], 'utf8')).base_url" "$state_path")"
		actual_store_id="$(node -p "JSON.parse(require('node:fs').readFileSync(process.argv[1], 'utf8')).store_id" "$state_path")"
		printf 'destroy actual-store-id=%s actual-base-url=%s receipt-file=%s\n' \
			"$actual_store_id" \
			"$actual_base_url" \
			"$rollback_receipt_file" >> "$E2E_FAKE_PROVISIONER_LOG"
		if [[ "${E2E_FAKE_DESTROY_FAILURE:-0}" == '1' ]]; then
			echo 'Fake transition destroy failed.' >&2
			exit 1
		fi
		rm "$state_path"
		;;
	*)
		echo 'Expected create or destroy.' >&2
		exit 1
		;;
esac
