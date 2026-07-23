#!/usr/bin/env bash

set -euo pipefail

readonly ACTION="${1:-}"
shift || true

ROLLBACK_ARMED=0
ROLLBACK_PROVISIONER=''
ROLLBACK_WORKSPACE=''
ROLLBACK_STORE_ID=''
ROLLBACK_BASE_URL=''

rollback_created_store() {
	local primary_status=$?
	trap - EXIT
	if [[ "$ROLLBACK_ARMED" == '1' ]]; then
		local cleanup_output=''
		if cleanup_output="$(
			"$ROLLBACK_PROVISIONER" destroy \
				--workspace "$ROLLBACK_WORKSPACE" \
				--store-id "$ROLLBACK_STORE_ID" \
				--base-url "$ROLLBACK_BASE_URL" 2>&1
		)"; then
			if ! rm -rf -- "$ROLLBACK_WORKSPACE"; then
				echo "Transition rollback removed the provisioned store but could not remove workspace $ROLLBACK_WORKSPACE." >&2
			fi
		else
			echo "Transition rollback cleanup failed for $ROLLBACK_WORKSPACE: $cleanup_output" >&2
		fi
	fi
	exit "$primary_status"
}

require_command() {
	local command_name="$1"
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Required command is unavailable: $command_name" >&2
		exit 1
	fi
}

validate_run_id() {
	local run_id="$1"
	if [[ ! "$run_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
		echo 'E2E_TRANSITION_RUN_ID must contain only letters, numbers, dots, underscores, and hyphens.' >&2
		exit 1
	fi
}

validate_base_url() {
	local base_url="$1"
	node -e '
		let url;
		try {
			url = new URL( process.argv[ 1 ] );
		} catch {
			console.error( "Transition provisioner returned an invalid base URL." );
			process.exit( 1 );
		}
		if ( ! [ "http:", "https:" ].includes( url.protocol ) ) {
			console.error( "Transition base URL must use HTTP or HTTPS." );
			process.exit( 1 );
		}
		if ( [ "8082", "8889" ].includes( url.port ) ) {
			console.error( `Transition allocation refuses standing store port ${ url.port }.` );
			process.exit( 1 );
		}
	' "$base_url"
}

json_field() {
	local json="$1"
	local field="$2"
	node -e '
		const value = JSON.parse( process.argv[ 1 ] )[ process.argv[ 2 ] ];
		if ( typeof value !== "string" || ! value ) process.exit( 1 );
		process.stdout.write( value );
	' "$json" "$field"
}

create_store() {
	readonly RUN_ID="${E2E_TRANSITION_RUN_ID:?E2E_TRANSITION_RUN_ID is required}"
	readonly SEED_ARCHIVE="${E2E_TRANSITION_SEED_ARCHIVE:?E2E_TRANSITION_SEED_ARCHIVE is required}"
	readonly PROVISIONER="${E2E_TRANSITION_STORE_PROVISIONER:?E2E_TRANSITION_STORE_PROVISIONER is required}"
	readonly TEMP_ROOT="${TMPDIR:?TMPDIR is required}"

	validate_run_id "$RUN_ID"
	require_command node
	require_command shasum
	require_command openssl

	if [[ ! -f "$SEED_ARCHIVE" ]]; then
		echo "Immutable transition seed archive does not exist: $SEED_ARCHIVE" >&2
		exit 1
	fi
	if [[ ! -x "$PROVISIONER" ]]; then
		echo "Owner-approved transition provisioner is not executable: $PROVISIONER" >&2
		exit 1
	fi

	readonly WORKSPACE="$TEMP_ROOT/woopayments-native-transition-$RUN_ID"
	readonly ALLOCATION_PATH="$WORKSPACE/allocation.json"
	if [[ -e "$WORKSPACE" ]]; then
		echo "Transition workspace already exists; refusing reuse: $WORKSPACE" >&2
		exit 1
	fi
	mkdir "$WORKSPACE"

	local planned
	planned="$(
		"$PROVISIONER" plan \
			--workspace "$WORKSPACE" \
			--seed-archive "$SEED_ARCHIVE" \
			--run-id "$RUN_ID"
	)"
	local base_url
	local store_id
	local plugin_version
	base_url="$(json_field "$planned" 'base_url')"
	store_id="$(json_field "$planned" 'store_id')"
	plugin_version="$(json_field "$planned" 'plugin_version')"
	validate_base_url "$base_url"

	ROLLBACK_PROVISIONER="$PROVISIONER"
	ROLLBACK_WORKSPACE="$WORKSPACE"
	ROLLBACK_STORE_ID="$store_id"
	ROLLBACK_BASE_URL="$base_url"
	ROLLBACK_ARMED=1
	trap rollback_created_store EXIT

	local created
	created="$(
		"$PROVISIONER" create \
			--workspace "$WORKSPACE" \
			--seed-archive "$SEED_ARCHIVE" \
			--run-id "$RUN_ID" \
			--base-url "$base_url" \
				--store-id "$store_id"
	)"

	if [[ "$(json_field "$created" 'base_url')" != "$base_url" ]] ||
		[[ "$(json_field "$created" 'store_id')" != "$store_id" ]] ||
		[[ "$(json_field "$created" 'plugin_version')" != "$plugin_version" ]]; then
		echo 'Transition provisioner result does not exactly match its validated plan.' >&2
		exit 1
	fi

	local seed_hash
	local teardown_token
	seed_hash="$(shasum -a 256 "$SEED_ARCHIVE" | awk '{ print $1 }')"
	teardown_token="$(openssl rand -hex 32)"

	local allocation_json
	allocation_json="$(
	node -e '
		const {
			closeSync,
			fsyncSync,
			openSync,
			writeSync,
		} = require( "node:fs" );
		const allocation = {
			base_url: process.argv[ 1 ],
			store_id: process.argv[ 2 ],
			seed_hash: process.argv[ 3 ],
			plugin_version: process.argv[ 4 ],
			teardown_token: process.argv[ 5 ],
			run_id: process.argv[ 6 ],
			workspace: process.argv[ 7 ],
			allocation_path: process.argv[ 8 ],
		};
		const json = `${ JSON.stringify( allocation ) }\n`;
		const allocationFd = openSync(
			allocation.allocation_path,
			"wx",
			0o600
		);
		try {
			writeSync( allocationFd, json );
			fsyncSync( allocationFd );
		} finally {
			closeSync( allocationFd );
		}
		const workspaceFd = openSync( allocation.workspace, "r" );
		try {
			fsyncSync( workspaceFd );
		} finally {
			closeSync( workspaceFd );
		}
		process.stdout.write( json );
	' "$base_url" "$store_id" "$seed_hash" "$plugin_version" "$teardown_token" "$RUN_ID" "$WORKSPACE" "$ALLOCATION_PATH"
	)"
	ROLLBACK_ARMED=0
	trap - EXIT
	printf '%s\n' "$allocation_json"
}

destroy_store() {
	readonly PROVISIONER="${E2E_TRANSITION_STORE_PROVISIONER:?E2E_TRANSITION_STORE_PROVISIONER is required}"
	readonly TEMP_ROOT="${TMPDIR:?TMPDIR is required}"
	local allocation_input=''

	while (( $# > 0 )); do
		case "$1" in
			--allocation)
				allocation_input="${2:-}"
				shift 2
				;;
			*)
				echo "Unknown destroy argument: $1" >&2
				exit 1
				;;
		esac
	done
	if [[ -z "$allocation_input" ]]; then
		echo 'destroy requires --allocation with the emitted JSON or allocation path.' >&2
		exit 1
	fi
	if [[ ! -x "$PROVISIONER" ]]; then
		echo "Owner-approved transition provisioner is not executable: $PROVISIONER" >&2
		exit 1
	fi

	local supplied
	if [[ -f "$allocation_input" ]]; then
		supplied="$(< "$allocation_input")"
	else
		supplied="$allocation_input"
	fi

	local run_id
	local workspace
	local allocation_path
	local base_url
	local store_id
	run_id="$(json_field "$supplied" 'run_id')"
	workspace="$(json_field "$supplied" 'workspace')"
	allocation_path="$(json_field "$supplied" 'allocation_path')"
	base_url="$(json_field "$supplied" 'base_url')"
	store_id="$(json_field "$supplied" 'store_id')"
	validate_run_id "$run_id"
	validate_base_url "$base_url"

	local expected_workspace="$TEMP_ROOT/woopayments-native-transition-$run_id"
	if [[ "$workspace" != "$expected_workspace" ]] ||
		[[ "$allocation_path" != "$expected_workspace/allocation.json" ]] ||
		[[ ! -f "$allocation_path" ]]; then
		echo 'Transition allocation does not resolve to its exact run-scoped TMPDIR workspace.' >&2
		exit 1
	fi

	local saved
	saved="$(< "$allocation_path")"
	node -e '
		const supplied = JSON.parse( process.argv[ 1 ] );
		const saved = JSON.parse( process.argv[ 2 ] );
		for ( const field of [
			"base_url",
			"store_id",
			"seed_hash",
			"plugin_version",
			"teardown_token",
			"run_id",
			"workspace",
			"allocation_path",
		] ) {
			if ( supplied[ field ] !== saved[ field ] ) {
				console.error( `Transition teardown refused: ${ field } does not match the saved allocation.` );
				process.exit( 1 );
			}
		}
	' "$supplied" "$saved"

	"$PROVISIONER" destroy \
		--workspace "$workspace" \
		--store-id "$store_id" \
		--base-url "$base_url"
	rm -rf "$workspace"
}

case "$ACTION" in
	create)
		create_store "$@"
		;;
	destroy)
		destroy_store "$@"
		;;
	*)
		echo 'Usage: provision-transition-store.sh create|destroy [--allocation <json-or-path>]' >&2
		exit 1
		;;
esac
