#!/usr/bin/env bash

set -euo pipefail

case "${1:-}" in
	create)
		workspace="${TMPDIR:?TMPDIR is required}/woopayments-native-transition-${E2E_TRANSITION_RUN_ID:?}"
		mkdir "$workspace"
		allocation="$workspace/allocation.json"
		node -e '
			const { writeFileSync } = require( "node:fs" );
			const value = {
				base_url: "http://transition-orchestrator-run.localhost:19091",
				store_id: "woopayments-native-transition-orchestrator-run",
				seed_hash: "a".repeat( 64 ),
				plugin_version: "10.5.0",
				wpcom_blog_id: 77,
				account_id: "acct_transition_77",
				teardown_token: "b".repeat( 64 ),
				run_id: "orchestrator-run",
				workspace: process.argv[ 1 ],
				allocation_path: process.argv[ 2 ],
			};
			const json = `${ JSON.stringify( value ) }\n`;
			writeFileSync( process.argv[ 2 ], json );
			process.stdout.write( json );
		' "$workspace" "$allocation"
		;;
	destroy)
		printf 'destroy exact-allocation\n' >> "${E2E_FAKE_COMMAND_LOG:?}"
		allocation="${3:-}"
		workspace="$(node -p 'JSON.parse(process.argv[1]).workspace' "$allocation")"
		rm -rf "$workspace"
		;;
	*)
		exit 1
		;;
esac
