#!/usr/bin/env bash

set -euo pipefail

optional_artifacts=''
if [[ "${1:-}" == plan || "${1:-}" == create ]]; then
	optional_artifacts="$(node -e '
		const { createHash } = require("node:crypto");
		const { readFileSync } = require("node:fs");
		const args = process.argv.slice(2);
		const archiveIndex = args.indexOf("--wcs-archive");
		const manifestIndex = args.indexOf("--wcs-manifest");
		if (archiveIndex < 0 && manifestIndex < 0) process.exit(0);
		if (archiveIndex < 0 || manifestIndex < 0) process.exit(1);
		const hash = path => createHash("sha256").update(readFileSync(path)).digest("hex");
		const identity = {
			plugin: "woocommerce-subscriptions", plugin_version: "9.2.0",
			source_commit: "4008f7f515f5ea76eea4d9149514b8c1774e51ba", source_date_epoch: 1788854213,
			archive_format: "tar.gz", archive_profile: "wcs-transition-v1", directory_mode: "0555", file_mode: "0444",
			archive_sha256: hash(args[archiveIndex + 1]), manifest_sha256: hash(args[manifestIndex + 1]),
			canonical_tar_sha256: "a".repeat(64), canonical_tree_sha256: "b".repeat(64),
		};
		if (process.argv[1] === "create") {
			const expected = JSON.parse(process.env.E2E_TRANSITION_EXPECTED_WCS_IDENTITY);
			if (expected["woocommerce-subscriptions"].archive_sha256 !== identity.archive_sha256) process.exit(1);
			if (process.env.E2E_FAKE_WCS_MISMATCH === "missing") process.exit(0);
			if (process.env.E2E_FAKE_WCS_MISMATCH === "extra") identity.host_path = args[archiveIndex + 1];
			if (process.env.E2E_FAKE_WCS_MISMATCH === "hash") identity.canonical_tree_sha256 = "c".repeat(64);
		}
		process.stdout.write(JSON.stringify({"woocommerce-subscriptions": identity}));
	' "$@")"
fi

case "${1:-}" in
	plan)
		node -e '
			const result = {base_url: process.argv[1], store_id: process.argv[2], plugin_version: process.argv[3]};
			if (process.argv[4]) result.optional_artifacts = JSON.parse(process.argv[4]);
			console.log(JSON.stringify(result));
		' \
			"${E2E_FAKE_TRANSITION_BASE_URL:-http://transition.localhost:8899}" \
			"${E2E_FAKE_TRANSITION_STORE_ID:-transition-store-test}" \
			"${E2E_FAKE_TRANSITION_PLUGIN_VERSION:-10.5.0}" "$optional_artifacts"
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
		if [[ "${E2E_FAKE_PORT_LEASE_COLLISION:-0}" == '1' ]]; then
			node -e '
				const { createHash } = require( "node:crypto" );
				const { readFileSync, writeFileSync } = require( "node:fs" );
				const receipt = readFileSync( process.argv[ 2 ] );
				writeFileSync( process.argv[ 1 ], `${ JSON.stringify( {
					receipt_sha256: createHash( "sha256" ).update( receipt ).digest( "hex" ),
					port_lease_attempted: true,
					port_lease_collision: true,
					port_lease_acquired: false,
					wp_env_start_attempted: false,
				} ) }\n`, { mode: 0o600, flag: "wx" } );
			' "$workspace/resource-state.json" "$workspace/rollback-receipt"
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
			if ( process.argv[ 7 ] ) result.optional_artifacts = JSON.parse( process.argv[ 7 ] );
			process.stdout.write( `${ JSON.stringify( result ) }\n` );
		' "$actual_base_url" "$actual_store_id" "$plugin_version" "$receipt_mode" "$wpcom_blog_id" "$account_id" "$optional_artifacts"
		if [[ "${E2E_FAKE_CREATE_PARTIAL_FAILURE:-0}" == '1' ]]; then
			echo 'Fake transition create failed after creating the store.' >&2
			exit 1
		fi
		if [[ "${E2E_FAKE_PORT_LEASE_COLLISION:-0}" == '1' ]]; then
			echo 'Fake transition create observed an existing port lease.' >&2
			exit 72
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
