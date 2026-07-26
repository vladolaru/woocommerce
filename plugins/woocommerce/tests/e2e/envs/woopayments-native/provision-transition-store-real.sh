#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly SEED_COMMIT='a1f755fc903966387f8629f78f75976ac8d2016e'
readonly SEED_VERSION='10.5.0'
readonly FRONTEND_LOCK_SHA256="${E2E_TRANSITION_FRONTEND_LOCK_SHA256:-6e279cfadb1851486976f67a72a11bc9ea36fa62c7f74d31b4d0d73c006b34b1}"
readonly SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
readonly PLUGIN_ROOT="$(
	cd "$SCRIPT_DIR/../../../.."
	pwd -P
)"
readonly PROJECTS_ROOT="$(
	cd "$PLUGIN_ROOT/../../.."
	pwd -P
)"
readonly PNPM_BIN="${E2E_TRANSITION_PNPM_BIN:-pnpm}"
readonly WPCOM_LOCAL_BIN="${E2E_WPCOM_LOCAL_BIN:-wpcom-local}"
readonly STATE_WRITER="$SCRIPT_DIR/write-transition-state.js"

workspace=''
seed_archive=''
seed_manifest=''
run_id=''
base_url=''
store_id=''
rollback_receipt_file=''

parse_arguments() {
	while (( $# > 0 )); do
		case "$1" in
			--workspace)
				workspace="${2:-}"
				shift 2
				;;
			--seed-archive)
				seed_archive="${2:-}"
				shift 2
				;;
			--seed-manifest)
				seed_manifest="${2:-}"
				shift 2
				;;
			--run-id)
				run_id="${2:-}"
				shift 2
				;;
			--base-url)
				base_url="${2:-}"
				shift 2
				;;
			--store-id)
				store_id="${2:-}"
				shift 2
				;;
			--rollback-receipt-file)
				rollback_receipt_file="${2:-}"
				shift 2
				;;
			*)
				echo "Unknown real-provisioner argument: $1" >&2
				exit 1
				;;
		esac
	done
}

require_safe_identity() {
	if [[ ! "$run_id" =~ ^[a-z0-9][a-z0-9-]{0,47}$ ]]; then
		echo 'Transition run ID must be a bounded lowercase DNS label.' >&2
		exit 1
	fi
	if [[ -z "$workspace" || ! -d "$workspace" || -L "$workspace" ]]; then
		echo 'Transition workspace must be an existing, non-symlinked directory.' >&2
		exit 1
	fi
}

file_mode() {
	stat -f '%Lp' "$1" 2> /dev/null || stat -c '%a' "$1"
}

validate_seed() {
	if [[ ! -f "$seed_archive" || -L "$seed_archive" ]] ||
		[[ ! -f "$seed_manifest" || -L "$seed_manifest" ]]; then
		echo 'Transition seed archive and manifest must be exact regular files.' >&2
		exit 1
	fi
	if (( ( 8#$(file_mode "$seed_archive") & 8#222 ) != 0 )) ||
		(( ( 8#$(file_mode "$seed_manifest") & 8#222 ) != 0 )); then
		echo 'Transition seed archive and manifest must be read-only.' >&2
		exit 1
	fi

	node -e '
		const { createHash } = require( "node:crypto" );
		const { readFileSync } = require( "node:fs" );
		const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const archiveHash = createHash( "sha256" )
			.update( readFileSync( process.argv[ 2 ] ) )
			.digest( "hex" );
		if (
			manifest.schema_version !== 1 ||
			manifest.plugin !== "woocommerce-payments" ||
			manifest.plugin_version !== process.argv[ 3 ] ||
			manifest.source_commit !== process.argv[ 4 ] ||
			manifest.archive_sha256 !== archiveHash ||
			manifest.archive_format !== "tar.gz" ||
			! /^[a-f0-9]{64}$/.test( manifest.dependency_lock_sha256 ) ||
			! Number.isSafeInteger( manifest.production_package_count ) ||
			manifest.production_package_count <= 0 ||
			manifest.frontend_lock_sha256 !== process.argv[ 5 ] ||
			manifest.frontend_lockfile_version !== 3 ||
			manifest.frontend_node_line !== "20.11" ||
			manifest.frontend_build_script !== "build:client" ||
			JSON.stringify( manifest.executable_seed_files ) !== JSON.stringify( [
				"vendor/autoload_packages.php",
				"vendor/autoload.php",
				"vendor/composer/installed.php",
				"vendor/composer/installed.json",
				"dist/index.js",
				"dist/index.css",
				"dist/checkout.js",
				"dist/blocks-checkout.js",
			] ) ||
			! Array.isArray( manifest.frontend_bundles ) ||
			JSON.stringify( manifest.frontend_bundles.map( ( value ) => value.path ) ) !==
				JSON.stringify( [
					"dist/index.js",
					"dist/index.css",
					"dist/checkout.js",
					"dist/blocks-checkout.js",
				] ) ||
			manifest.frontend_bundles.some(
				( value ) => ! /^[a-f0-9]{64}$/.test( value.sha256 )
			)
		) {
			console.error( "Transition seed manifest does not match the approved immutable floor." );
			process.exit( 1 );
		}
	' \
		"$seed_manifest" \
		"$seed_archive" \
		"$SEED_VERSION" \
		"$SEED_COMMIT" \
		"$FRONTEND_LOCK_SHA256"

	if ! tar -tzf "$seed_archive" |
		grep -Fqx 'woocommerce-payments/woocommerce-payments.php'; then
		echo 'Transition seed archive has no canonical WooPayments plugin entry.' >&2
		exit 1
	fi
	if ! tar -xOzf "$seed_archive" woocommerce-payments/woocommerce-payments.php |
		grep -Eq '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*10\.5\.0[[:space:]]*$'; then
		echo 'Transition seed archive does not contain the approved WooPayments version.' >&2
		exit 1
	fi
	if tar -tzf "$seed_archive" | grep -Eq '(^|/)\.git(/|$)'; then
		echo 'Transition seed archive contains forbidden Git metadata.' >&2
		exit 1
	fi
	if tar -tzf "$seed_archive" | grep -Eq '(^|/)node_modules(/|$)'; then
		echo 'Transition seed archive contains build-only frontend dependencies.' >&2
		exit 1
	fi

	local validation_root
	validation_root="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-transition-seed-validation.XXXXXX")"
	local -a executable_entries=(
		'woocommerce-payments/composer.lock'
		'woocommerce-payments/.nvmrc'
		'woocommerce-payments/package.json'
		'woocommerce-payments/package-lock.json'
		'woocommerce-payments/vendor/autoload_packages.php'
		'woocommerce-payments/vendor/autoload.php'
		'woocommerce-payments/vendor/composer/installed.php'
		'woocommerce-payments/vendor/composer/installed.json'
		'woocommerce-payments/dist/index.js'
		'woocommerce-payments/dist/index.css'
		'woocommerce-payments/dist/checkout.js'
		'woocommerce-payments/dist/blocks-checkout.js'
	)
	if ! tar -xzf "$seed_archive" -C "$validation_root" "${executable_entries[@]}"; then
		rm -rf "$validation_root"
		echo 'Transition seed archive cannot materialize its exact executable boundary.' >&2
		exit 1
	fi
	local executable_entry
	for executable_entry in "${executable_entries[@]}"; do
		if [[ ! -s "$validation_root/$executable_entry" || -L "$validation_root/$executable_entry" ]]; then
			rm -rf "$validation_root"
			echo "Transition seed executable boundary is missing or ambiguous: $executable_entry" >&2
			exit 1
		fi
	done
	if ! node -e '
		const { createHash } = require( "node:crypto" );
		const { readFileSync } = require( "node:fs" );
		const { join } = require( "node:path" );
		const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const lock = JSON.parse( readFileSync( process.argv[ 2 ], "utf8" ) );
		const installedDocument = JSON.parse(
			readFileSync( process.argv[ 3 ], "utf8" )
		);
		const frontendManifest = JSON.parse(
			readFileSync( process.argv[ 4 ], "utf8" )
		);
		const frontendLock = JSON.parse(
			readFileSync( process.argv[ 5 ], "utf8" )
		);
		const installed = Array.isArray( installedDocument )
			? installedDocument
			: installedDocument.packages;
		const lockHash = createHash( "sha256" )
			.update( readFileSync( process.argv[ 2 ] ) )
			.digest( "hex" );
		const frontendLockHash = createHash( "sha256" )
			.update( readFileSync( process.argv[ 5 ] ) )
			.digest( "hex" );
		const normalize = ( packages ) => {
			if ( ! Array.isArray( packages ) ) process.exit( 1 );
			return packages
				.map( ( value ) => {
					if (
						typeof value.name !== "string" ||
						typeof value.version !== "string"
					) process.exit( 1 );
					return `${ value.name }@${ value.version }`;
				} )
				.sort();
		};
		if (
			lockHash !== manifest.dependency_lock_sha256 ||
			lock.packages.length !== manifest.production_package_count ||
			JSON.stringify( normalize( lock.packages ) ) !==
				JSON.stringify( normalize( installed ) ) ||
			frontendLockHash !== manifest.frontend_lock_sha256 ||
			frontendLock.lockfileVersion !== manifest.frontend_lockfile_version ||
			frontendLock.name !== "woocommerce-payments" ||
			frontendLock.version !== "10.5.0" ||
			frontendLock.packages?.[ "" ]?.name !== "woocommerce-payments" ||
			frontendLock.packages?.[ "" ]?.version !== "10.5.0" ||
			frontendManifest.name !== "woocommerce-payments" ||
			frontendManifest.version !== "10.5.0" ||
			frontendManifest.scripts?.[ "build:client" ] !==
				"NODE_ENV=production webpack" ||
			readFileSync( process.argv[ 6 ], "utf8" ).trim() !==
				manifest.frontend_node_line
		) process.exit( 1 );
		for ( const bundle of manifest.frontend_bundles ) {
			const digest = createHash( "sha256" )
				.update( readFileSync( join( process.argv[ 7 ], bundle.path ) ) )
				.digest( "hex" );
			if ( digest !== bundle.sha256 ) process.exit( 1 );
		}
	' \
		"$seed_manifest" \
		"$validation_root/woocommerce-payments/composer.lock" \
		"$validation_root/woocommerce-payments/vendor/composer/installed.json" \
		"$validation_root/woocommerce-payments/package.json" \
		"$validation_root/woocommerce-payments/package-lock.json" \
		"$validation_root/woocommerce-payments/.nvmrc" \
		"$validation_root/woocommerce-payments"; then
		rm -rf "$validation_root"
		echo 'Transition seed executable dependencies or frontend bundles do not match its safe manifest.' >&2
		exit 1
	fi
	rm -rf "$validation_root"
}

planned_port() {
	local port="${E2E_TRANSITION_PORT:-}"
	if [[ -z "$port" ]]; then
		port="$(
			node -e '
				const { createHash } = require( "node:crypto" );
				const value = createHash( "sha256" ).update( process.argv[ 1 ] ).digest().readUInt32BE();
				process.stdout.write( String( 18000 + ( value % 20000 ) ) );
			' "$run_id"
		)"
	fi
	if [[ ! "$port" =~ ^[1-9][0-9]{0,4}$ ]] ||
		(( port > 65535 )) ||
		[[ "$port" == '8082' || "$port" == '8889' ]]; then
		echo 'Transition port is invalid or belongs to a standing developer store.' >&2
		exit 1
	fi
	printf '%s' "$port"
}

emit_plan() {
	local port
	port="$(planned_port)"
	node -e '
		process.stdout.write( `${ JSON.stringify( {
			base_url: `http://transition-${ process.argv[ 1 ] }.localhost:${ process.argv[ 2 ] }`,
			store_id: `woopayments-native-transition-${ process.argv[ 1 ] }`,
			plugin_version: process.argv[ 3 ],
		} ) }\n` );
	' "$run_id" "$port" "$SEED_VERSION"
}

validate_base_store_identity() {
	node -e '
		const url = new URL( process.argv[ 1 ] );
		const expectedHost = `transition-${ process.argv[ 2 ] }.localhost`;
		if (
			url.protocol !== "http:" ||
			url.hostname !== expectedHost ||
			! url.port ||
			[ "8082", "8889" ].includes( url.port ) ||
			url.pathname !== "/" ||
			url.search ||
			url.hash ||
			process.argv[ 3 ] !== `woopayments-native-transition-${ process.argv[ 2 ] }`
		) process.exit( 1 );
	' "$base_url" "$run_id" "$store_id"
}

state_field() {
	node -e '
		const { readFileSync } = require( "node:fs" );
		const value = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) )[ process.argv[ 2 ] ];
		if ( value === undefined || value === null ) process.exit( 1 );
		process.stdout.write( typeof value === "string" ? value : JSON.stringify( value ) );
	' "$workspace/resource-state.json" "$1"
}

json_object_from_stdin() {
	node -e '
		const { readFileSync } = require( "node:fs" );
		const output = readFileSync( 0, "utf8" );
		const start = output.indexOf( "{" );
		let depth = 0;
		let inString = false;
		let escaped = false;
		for ( let index = start; index >= 0 && index < output.length; index++ ) {
			const character = output[ index ];
			if ( inString ) {
				if ( escaped ) {
					escaped = false;
				} else if ( character === "\\" ) {
					escaped = true;
				} else if ( character === "\"" ) {
					inString = false;
				}
				continue;
			}
			if ( character === "\"" ) {
				inString = true;
			} else if ( character === "{" ) {
				depth++;
			} else if ( character === "}" && --depth === 0 ) {
				const json = output.slice( start, index + 1 );
				JSON.parse( json );
				process.stdout.write( json );
				process.exit();
			}
		}
		console.error( "Transition command output contained no complete JSON object." );
		process.exit( 1 );
	'
}

write_initial_state() {
	local receipt_hash="$1"
	local marker="$2"
	local port="$3"
	local port_lease_path="$4"
	node -e '
		const {
			closeSync,
			fsyncSync,
			openSync,
			writeFileSync,
		} = require( "node:fs" );
		const { dirname } = require( "node:path" );
		const state = {
			schema_version: 1,
			run_id: process.argv[ 2 ],
			workspace: process.argv[ 3 ],
			base_url: process.argv[ 4 ],
			domain: new URL( process.argv[ 4 ] ).hostname,
			home: process.argv[ 4 ],
			store_id: process.argv[ 5 ],
			plugin_version: process.argv[ 6 ],
			seed_hash: process.argv[ 7 ],
			marker: process.argv[ 8 ],
			receipt_sha256: process.argv[ 9 ],
			wp_env_home: process.argv[ 10 ],
			port: Number( process.argv[ 11 ] ),
			port_lease_path: process.argv[ 12 ],
			wpcom_blog_id: 0,
			account_id: "",
			account_alias: "",
			wp_env_start_attempted: false,
			wp_env_created: false,
			port_lease_attempted: false,
			port_lease_acquired: false,
			port_lease_collision: false,
			port_lease_release_attempted: false,
			port_lease_released: false,
			wpcom_blog_registration_attempted: false,
			wpcom_blog_created: false,
			wpcom_blog_id_recovered: false,
			account_creation_attempted: false,
			account_created: false,
			account_id_recovered: false,
			account_deleted: false,
			wpcom_blog_deleted: false,
			wp_env_destroyed: false,
			phase: "prepared",
		};
		const stateDescriptor = openSync( process.argv[ 1 ], "wx", 0o600 );
		try {
			writeFileSync( stateDescriptor, `${ JSON.stringify( state ) }\n` );
			fsyncSync( stateDescriptor );
		} finally {
			closeSync( stateDescriptor );
		}
		const directoryDescriptor = openSync( dirname( process.argv[ 1 ] ), "r" );
		try {
			fsyncSync( directoryDescriptor );
		} finally {
			closeSync( directoryDescriptor );
		}
	' \
		"$workspace/resource-state.json" \
		"$run_id" \
		"$workspace" \
		"$base_url" \
		"$store_id" \
		"$SEED_VERSION" \
		"$(shasum -a 256 "$seed_archive" | awk '{ print $1 }')" \
		"$marker" \
		"$receipt_hash" \
		"$workspace/wp-env-home" \
		"$port" \
		"$port_lease_path"
}

update_state() {
	local key="$1"
	local value="$2"
	local type="${3:-string}"
	node "$STATE_WRITER" "$workspace/resource-state.json" "$key" "$value" "$type"
}

base_url_port() {
	node -p '
		const url = new URL( process.argv[ 1 ] );
		if ( ! url.port ) process.exit( 1 );
		Number( url.port );
	' "$base_url"
}

port_lease_path_for() {
	local port="$1"
	local temporary_root="${TMPDIR:?TMPDIR is required}"
	if [[ ! -d "$temporary_root" || -L "$temporary_root" ]]; then
		echo 'Transition port leasing requires an exact non-symlinked TMPDIR.' >&2
		return 1
	fi
	temporary_root="$(
		cd "$temporary_root"
		pwd -P
	)"
	printf '%s/woopayments-native-transition-port-leases/%s' "$temporary_root" "$port"
}

ensure_port_lease_namespace() {
	local lease_path
	lease_path="$(state_field port_lease_path)"
	local lease_root
	lease_root="$(dirname "$lease_path")"
	if mkdir "$lease_root" 2> /dev/null; then
		chmod 0700 "$lease_root"
	elif [[ ! -d "$lease_root" || -L "$lease_root" ]] ||
		[[ "$(file_mode "$lease_root")" != '700' ]]; then
		echo 'Transition port lease namespace is not an exact private directory.' >&2
		return 1
	fi
}

write_port_lease_owner() {
	local lease_path
	lease_path="$(state_field port_lease_path)"
	node -e '
		const {
			closeSync,
			fsyncSync,
			openSync,
			writeFileSync,
		} = require( "node:fs" );
		const { dirname, join } = require( "node:path" );
		const leasePath = process.argv[ 1 ];
		const ownerPath = join( leasePath, "owner.json" );
		const owner = {
			port: Number( process.argv[ 2 ] ),
			run_id: process.argv[ 3 ],
			workspace: process.argv[ 4 ],
			base_url: process.argv[ 5 ],
			receipt_sha256: process.argv[ 6 ],
		};
		const ownerDescriptor = openSync( ownerPath, "wx", 0o600 );
		try {
			writeFileSync( ownerDescriptor, `${ JSON.stringify( owner ) }\n` );
			fsyncSync( ownerDescriptor );
		} finally {
			closeSync( ownerDescriptor );
		}
		for ( const path of [ leasePath, dirname( leasePath ), dirname( dirname( leasePath ) ) ] ) {
			const descriptor = openSync( path, "r" );
			try {
				fsyncSync( descriptor );
			} finally {
				closeSync( descriptor );
			}
		}
	' \
		"$lease_path" \
		"$(state_field port)" \
		"$run_id" \
		"$workspace" \
		"$base_url" \
		"$(state_field receipt_sha256)"
}

acquire_port_lease() {
	update_state port_lease_attempted true boolean
	update_state phase 'port-lease-attempted'
	ensure_port_lease_namespace
	local lease_path
	lease_path="$(state_field port_lease_path)"
	if ! mkdir "$lease_path" 2> /dev/null; then
		update_state port_lease_collision true boolean
		echo "Transition port $(state_field port) already has a lease; existing ownership was preserved." >&2
		return 1
	fi
	chmod 0700 "$lease_path"
	write_port_lease_owner
	update_state port_lease_acquired true boolean
	update_state phase 'port-lease-acquired'
}

validate_port_lease_owner() {
	local state_port
	state_port="$(state_field port)"
	local lease_path
	lease_path="$(state_field port_lease_path)"
	local expected_lease_path
	expected_lease_path="$(port_lease_path_for "$state_port")"
	if [[ "$lease_path" != "$expected_lease_path" ]] ||
		[[ ! -d "$lease_path" || -L "$lease_path" ]] ||
		[[ "$(file_mode "$lease_path")" != '700' ]] ||
		[[ ! -f "$lease_path/owner.json" || -L "$lease_path/owner.json" ]] ||
		[[ "$(file_mode "$lease_path/owner.json")" != '600' ]]; then
		return 1
	fi
	node -e '
		const { readFileSync } = require( "node:fs" );
		const owner = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		if (
			owner.port !== Number( process.argv[ 2 ] ) ||
			owner.run_id !== process.argv[ 3 ] ||
			owner.workspace !== process.argv[ 4 ] ||
			owner.base_url !== process.argv[ 5 ] ||
			owner.receipt_sha256 !== process.argv[ 6 ] ||
			Object.keys( owner ).sort().join( "," ) !==
				"base_url,port,receipt_sha256,run_id,workspace"
		) process.exit( 1 );
	' \
		"$lease_path/owner.json" \
		"$state_port" \
		"$run_id" \
		"$workspace" \
		"$base_url" \
		"$(state_field receipt_sha256)"
}

prepare_port_lease_for_destroy() {
	if [[ "$(state_field port_lease_released)" == 'true' ]]; then
		if [[ "$(state_field wp_env_destroyed)" != 'true' ]]; then
			echo 'Transition port lease was released before durable environment cleanup.' >&2
			return 1
		fi
		return
	fi
	local lease_path
	lease_path="$(state_field port_lease_path)"
	if [[ "$(state_field port_lease_acquired)" == 'true' ]]; then
		if validate_port_lease_owner; then
			return
		fi
		if [[ "$(state_field wp_env_destroyed)" == 'true' ]] &&
			[[ "$(state_field port_lease_release_attempted)" == 'true' ]]; then
			if [[ ! -e "$lease_path" ]]; then
				return
			fi
			if [[ -d "$lease_path" && ! -L "$lease_path" ]] &&
				[[ "$(file_mode "$lease_path")" == '700' ]] &&
				! find "$lease_path" -mindepth 1 -print -quit | grep -q .; then
				return
			fi
		fi
		echo 'Transition destroy cannot prove exact port lease ownership.' >&2
		return 1
	fi
	if [[ "$(state_field port_lease_collision)" == 'true' ]]; then
		if [[ "$(state_field wp_env_start_attempted)" != 'false' ]]; then
			echo 'A colliding transition port lease has unexpected environment mutation state.' >&2
			return 1
		fi
		return
	fi
	if validate_port_lease_owner; then
		update_state port_lease_acquired true boolean
		update_state phase 'port-lease-recovered'
		return
	fi
	if [[ -e "$lease_path" ]]; then
		echo 'Transition destroy found ambiguous port lease ownership.' >&2
		return 1
	fi
	if [[ "$(state_field wp_env_start_attempted)" != 'false' ]]; then
		echo 'Transition environment mutation has no exact port lease owner.' >&2
		return 1
	fi
}

release_port_lease() {
	if [[ "$(state_field port_lease_released)" == 'true' ]]; then
		return
	fi
	if [[ "$(state_field wp_env_destroyed)" != 'true' ]]; then
		echo 'Transition port lease release requires durable environment cleanup.' >&2
		return 1
	fi
	if [[ "$(state_field port_lease_acquired)" != 'true' ]]; then
		update_state port_lease_released true boolean
		return
	fi
	local lease_path
	lease_path="$(state_field port_lease_path)"
	update_state port_lease_release_attempted true boolean
	if [[ -L "$lease_path" ]]; then
		echo 'Transition port lease release refuses a symlinked lease path.' >&2
		return 1
	fi
	if [[ -e "$lease_path" ]]; then
		if [[ -f "$lease_path/owner.json" ]]; then
			validate_port_lease_owner
			rm "$lease_path/owner.json"
		elif find "$lease_path" -mindepth 1 -print -quit | grep -q .; then
			echo 'Transition port lease release found unexpected directory contents.' >&2
			return 1
		fi
		rmdir "$lease_path"
		node -e '
			const { closeSync, fsyncSync, openSync } = require( "node:fs" );
			const descriptor = openSync( process.argv[ 1 ], "r" );
			try {
				fsyncSync( descriptor );
			} finally {
				closeSync( descriptor );
			}
		' "$(dirname "$lease_path")"
	fi
	if [[ "${E2E_TRANSITION_TEST_KILL_AFTER_LEASE_RELEASE:-0}" == '1' ]]; then
		kill -KILL "$$"
	fi
	update_state port_lease_released true boolean
	update_state phase 'port-lease-released'
}

wp_env() {
	(
		cd "$workspace/store"
		WP_ENV_HOME="$workspace/wp-env-home" "$PNPM_BIN" exec wp-env "$@"
	)
}

validate_exact_wp_env_scope() {
	local expected_wp_env_home="$workspace/wp-env-home"
	local config_path="$workspace/store/.wp-env.json"
	if [[ "$(state_field wp_env_home)" != "$expected_wp_env_home" ]] ||
		[[ ! -d "$expected_wp_env_home" || -L "$expected_wp_env_home" ]] ||
		[[ ! -d "$workspace/store" || -L "$workspace/store" ]] ||
		[[ ! -f "$config_path" || -L "$config_path" ]]; then
		echo 'Transition wp-env recovery scope does not match its exact isolated workspace.' >&2
		return 1
	fi
	node -e '
		const { readFileSync } = require( "node:fs" );
		const config = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const expected = new URL( process.argv[ 2 ] );
		if (
			config.port !== Number( expected.port ) ||
			config.port !== Number( process.argv[ 3 ] ) ||
			config.config?.WP_SITEURL !== process.argv[ 2 ] ||
			config.config?.WP_HOME !== process.argv[ 2 ]
			) process.exit( 1 );
	' "$config_path" "$base_url" "$(state_field port)"
}

store_wp() {
	wp_env run cli wp "$@"
}

validate_store_scope() {
	node -e '
		const value = JSON.parse( process.argv[ 1 ] );
		const exactRoot = ( candidate ) => {
			const url = new URL( candidate );
			if ( url.pathname !== "/" || url.search || url.hash ) process.exit( 1 );
			return url.origin;
		};
		if (
			exactRoot( value.site_url ) !== process.argv[ 2 ] ||
			exactRoot( value.home ) !== process.argv[ 2 ] ||
			value.marker !== process.argv[ 3 ]
		) process.exit( 1 );
	' "$1" "$base_url" "$(state_field marker)"
}

validate_created_identity() {
	validate_store_scope "$1"
	node -e '
		const value = JSON.parse( process.argv[ 1 ] );
		if ( value.wpcom_blog_id !== Number( process.argv[ 2 ] ) ) process.exit( 1 );
	' "$1" "$(state_field wpcom_blog_id)"
}

validate_blog_identity() {
	node -e '
		const value = JSON.parse( process.argv[ 1 ] );
		const home = new URL( value.home );
		if (
			value.wpcom_blog_id !== Number( process.argv[ 2 ] ) ||
			value.domain !== process.argv[ 3 ] ||
			home.pathname !== "/" ||
			home.search ||
			home.hash ||
			home.origin !== process.argv[ 4 ] ||
			value.marker !== process.argv[ 5 ]
		) process.exit( 1 );
	' \
		"$1" \
		"$(state_field wpcom_blog_id)" \
		"$(state_field domain)" \
		"$(state_field home)" \
		"$(state_field marker)"
}

classify_blog_identity() {
	node -e '
		const value = JSON.parse( process.argv[ 1 ] );
		if ( value.wpcom_blog_id !== Number( process.argv[ 2 ] ) ) process.exit( 1 );
		if ( value.exists === false ) {
			process.stdout.write( "absent" );
			process.exit();
		}
		if ( value.exists !== true ) process.exit( 1 );
		const home = new URL( value.home );
		if (
			value.domain !== process.argv[ 3 ] ||
			home.pathname !== "/" ||
			home.search ||
			home.hash ||
			home.origin !== process.argv[ 4 ] ||
			value.marker !== process.argv[ 5 ]
		) process.exit( 1 );
		process.stdout.write( "present" );
	' \
		"$1" \
		"$(state_field wpcom_blog_id)" \
		"$(state_field domain)" \
		"$(state_field home)" \
		"$(state_field marker)"
}

validate_callback_probe() {
	node -e '
		const proof = JSON.parse( process.argv[ 1 ] );
		const context = proof.context || {};
		if (
			proof.exit_code !== 0 ||
			! [ "success", "warning" ].includes( proof.status ) ||
			context.store_url !== process.argv[ 2 ] ||
			context.wpcom_blog_id !== process.argv[ 3 ] ||
			context.callback_registered !== "true" ||
			context.callback_reachable !== "true" ||
			context.callback_auth_model !== "jetpack_capability" ||
			context.callback_provider_write !== "false" ||
			context.callback_response_result !== "success" ||
			typeof context.callback_route !== "string" ||
			! context.callback_route ||
			context.callback_delivered_route !== context.callback_route ||
			context.ingress_routes !== "current"
		) process.exit( 1 );
	' "$1" "$base_url" "$(state_field wpcom_blog_id)"
}

query_account_snapshot() {
	store_wp --user=1 eval '
		/* transition_account_recovery_evidence */
		if ( ! class_exists( "\WCPayDev\TestLab\Operations\Environment" ) ) {
			WP_CLI::error( "WooPayments Test Lab environment is unavailable." );
		}
		$environment = new \WCPayDev\TestLab\Operations\Environment();
		echo wp_json_encode(
			array(
				"store" => array(
					"site_url" => untrailingslashit( site_url() ),
					"home" => untrailingslashit( home_url() ),
					"marker" => get_option( "e2e_woopayments_transition_marker" ),
					"wpcom_blog_id" => class_exists( "Jetpack_Options" ) ? (int) Jetpack_Options::get_option( "id" ) : 0,
				),
				"status" => $environment->get_status_summary(),
				"account_data" => $environment->get_account_data(),
			)
		);
	' | json_object_from_stdin
}

classify_account_snapshot() {
	node -e '
		const snapshot = JSON.parse( process.argv[ 1 ] );
		const store = snapshot.store || {};
		const status = snapshot.status || {};
		const account = snapshot.account_data;
		const exactRoot = ( candidate ) => {
			if ( typeof candidate !== "string" ) process.exit( 1 );
			const url = new URL( candidate );
			if ( url.pathname !== "/" || url.search || url.hash ) process.exit( 1 );
			return url.origin;
		};
		if (
			exactRoot( store.site_url ) !== process.argv[ 2 ] ||
			exactRoot( store.home ) !== process.argv[ 2 ] ||
			store.marker !== process.argv[ 3 ] ||
			store.wpcom_blog_id !== Number( process.argv[ 4 ] ) ||
			status.environment !== "local" ||
			status.runtime !== "extension" ||
			status.wcpay_active !== true ||
			status.is_test_mode !== true ||
			status.is_live_mode !== false ||
			status.is_dev_environment !== true ||
			! status.guardrail ||
			typeof status.guardrail.allowed !== "boolean"
		) process.exit( 1 );
		const accountIsEmpty =
			( Array.isArray( account ) && account.length === 0 ) ||
			( account && ! Array.isArray( account ) &&
				typeof account === "object" &&
				Object.keys( account ).length === 0 );
		if (
			status.connected === false &&
			status.account_id === "" &&
			status.account_type === "unknown" &&
			status.guardrail.allowed === false &&
			accountIsEmpty
		) {
			process.stdout.write( "absent" );
			process.exit();
		}
		if (
			status.connected === true &&
			typeof status.account_id === "string" &&
			/^acct_[A-Za-z0-9_]+$/.test( status.account_id ) &&
			status.account_type === "test_drive" &&
			status.guardrail.allowed === true &&
			account &&
			! Array.isArray( account ) &&
			typeof account === "object" &&
			account.account_id === status.account_id &&
			account.is_test_drive === true &&
			account.is_live !== true
		) {
			process.stdout.write( `present:${ status.account_id }` );
			process.exit();
		}
		process.exit( 1 );
	' \
		"$1" \
		"$base_url" \
		"$(state_field marker)" \
		"$(state_field wpcom_blog_id)"
}

require_fresh_account_snapshot() {
	if [[ "$(classify_account_snapshot "$1")" != 'absent' ]]; then
		echo 'Transition account creation requires exact disconnected empty Test Lab evidence.' >&2
		return 1
	fi
}

account_id_from_snapshot() {
	local classification
	classification="$(classify_account_snapshot "$1")"
	if [[ "$classification" != present:* ]]; then
		echo 'Transition account recovery requires one exact test-drive Test Lab account.' >&2
		return 1
	fi
	printf '%s' "${classification#present:}"
}

emit_create_result() {
	local receipt="$1"
	local status="${2:-success}"
	node -e '
		const result = {
			base_url: process.argv[ 1 ],
			store_id: process.argv[ 2 ],
			plugin_version: process.argv[ 3 ],
			rollback_receipt: process.argv[ 4 ],
		};
		const blog = Number( process.argv[ 5 ] );
		if ( Number.isSafeInteger( blog ) && blog > 0 ) result.wpcom_blog_id = blog;
		if ( process.argv[ 6 ] ) result.account_id = process.argv[ 6 ];
		if ( process.argv[ 7 ] !== "success" ) result.status = process.argv[ 7 ];
		process.stdout.write( `${ JSON.stringify( result ) }\n` );
	' \
		"$base_url" \
		"$store_id" \
		"$SEED_VERSION" \
		"$receipt" \
		"$(state_field wpcom_blog_id 2> /dev/null || printf 0)" \
		"$(state_field account_id 2> /dev/null || true)" \
		"$status"
}

create_store() {
	local expected_plan
	expected_plan="$(emit_plan)"
	if [[ "$base_url" != "$(node -p 'JSON.parse(process.argv[1]).base_url' "$expected_plan")" ]] ||
		[[ "$store_id" != "$(node -p 'JSON.parse(process.argv[1]).store_id' "$expected_plan")" ]]; then
		echo 'Create identity does not exactly match the validated transition plan.' >&2
		exit 1
	fi
	validate_base_store_identity
	if find "$workspace" -mindepth 1 -print -quit | grep -q .; then
		echo 'Transition create requires an unused workspace.' >&2
		exit 1
	fi

	local receipt
	receipt="$(
		node -e '
			const { randomBytes } = require( "node:crypto" );
			process.stdout.write( `receipt_${ randomBytes( 32 ).toString( "hex" ) }` );
		'
	)"
	printf '%s' "$receipt" > "$workspace/rollback-receipt"
	chmod 0600 "$workspace/rollback-receipt"
	local receipt_hash
	receipt_hash="$(printf '%s' "$receipt" | shasum -a 256 | awk '{ print $1 }')"
	local marker="wc-native-transition:${run_id}:${receipt_hash:0:16}"
	local port
	port="$(base_url_port)"
	local port_lease_path
	port_lease_path="$(port_lease_path_for "$port")"
	write_initial_state "$receipt_hash" "$marker" "$port" "$port_lease_path"

	on_create_error() {
		local status=$?
		trap - ERR
		update_state phase 'create-failed' || true
		emit_create_result "$receipt" 'failed'
		exit "$status"
	}
	trap on_create_error ERR
	acquire_port_lease

	local core_repo="${E2E_TRANSITION_CORE_REPO:-$PLUGIN_ROOT}"
	local dev_tools="${E2E_TRANSITION_DEV_TOOLS_REPO:-$PROJECTS_ROOT/woocommerce-payments-dev-tools}"
	local helper="${E2E_TRANSITION_WPCOM_HELPER_REPO:-$PROJECTS_ROOT/wpcom-local-helper}"
	local wpcom_url="${E2E_TRANSITION_WPCOM_URL:?E2E_TRANSITION_WPCOM_URL is required}"
	local wpcom_api_url="${E2E_TRANSITION_WPCOM_API_URL:?E2E_TRANSITION_WPCOM_API_URL is required}"
	for mount in "$core_repo" "$dev_tools" "$helper"; do
		if [[ ! -d "$mount" || -L "$mount" ]]; then
			echo "Transition mount is unavailable or ambiguous: $mount" >&2
			return 1
		fi
	done
	if [[ ! "$wpcom_url" =~ ^http://([A-Za-z0-9-]+\.)?localhost(:[1-9][0-9]{0,4})?$ ]] ||
		[[ ! "$wpcom_api_url" =~ ^http://(host\.docker\.internal|([A-Za-z0-9-]+\.)?localhost)(:[1-9][0-9]{0,4})?/wp-json/?$ ]]; then
		echo 'Transition WPCOM URLs must be explicit local-only HTTP endpoints.' >&2
		return 1
	fi

	mkdir "$workspace/store" "$workspace/wp-env-home" "$workspace/evidence" "$workspace/seed"
	tar -xzf "$seed_archive" -C "$workspace/seed"
	node -e '
		const { writeFileSync } = require( "node:fs" );
		const config = {
			core: "https://wordpress.org/wordpress-latest.zip",
			phpVersion: "8.1",
			port: Number( process.argv[ 2 ] ),
			config: {
				WP_SITEURL: process.argv[ 3 ],
				WP_HOME: process.argv[ 3 ],
				E2E_WOOPAYMENTS_NATIVE: true,
				WP_DEBUG_DISPLAY: false,
			},
			mappings: {
				"wp-content/plugins/woocommerce": process.argv[ 4 ],
				"wp-content/plugins/woocommerce-payments": process.argv[ 5 ],
				"wp-content/plugins/woocommerce-payments-dev-tools": process.argv[ 6 ],
				"wp-content/plugins/wpcom-local-helper": process.argv[ 7 ],
				"wp-content/plugins/e2e-test-helpers": process.argv[ 8 ],
				"wp-content/mu-plugins/woopayments-native-runtime.php": process.argv[ 9 ],
			},
		};
		writeFileSync( process.argv[ 1 ], `${ JSON.stringify( config, null, 2 ) }\n`, {
			mode: 0o600,
			flag: "wx",
		} );
	' \
		"$workspace/store/.wp-env.json" \
		"${base_url##*:}" \
		"$base_url" \
		"$core_repo" \
		"$workspace/seed/woocommerce-payments" \
		"$dev_tools" \
		"$helper" \
		"$PLUGIN_ROOT/tests/e2e/bin" \
		"$PLUGIN_ROOT/tests/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php"

	update_state wp_env_start_attempted true boolean
	update_state phase 'wp-env-start-attempted'
	local wp_env_start_status
	set +e
	wp_env start > /dev/null
	wp_env_start_status=$?
	set -e
	if (( wp_env_start_status != 0 )); then
		return "$wp_env_start_status"
	fi
	update_state wp_env_created true boolean
	update_state phase 'wp-env-created'
	store_wp plugin activate woocommerce woocommerce-payments woocommerce-payments-dev-tools wpcom-local-helper > /dev/null
	local installed_version
	installed_version="$(store_wp plugin get woocommerce-payments --field=version)"
	if [[ "$installed_version" != "$SEED_VERSION" ]]; then
		echo 'The transition store did not activate the immutable WooPayments floor.' >&2
		return 1
	fi
	store_wp --user=1 wc tool run install_pages > /dev/null
	store_wp eval '
		/* transition_prepare_store */
		$user = get_user_by( "login", "customer" );
		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					"user_login" => "customer",
					"user_pass" => "password",
					"user_email" => "customer@woocommercecoree2etestsuite.com",
					"first_name" => "Jane",
					"last_name" => "Smith",
					"role" => "customer",
				)
			);
			if ( is_wp_error( $user_id ) ) {
				WP_CLI::error( $user_id->get_error_message() );
			}
		} else {
			$user_id = $user->ID;
		}
		foreach (
			array(
				"billing_first_name" => "Maggie",
				"billing_last_name" => "Simpson",
				"billing_address_1" => "123 Evergreen Terrace",
				"billing_city" => "Springfield",
				"billing_country" => "US",
				"billing_state" => "OR",
				"billing_postcode" => "97403",
				"billing_phone" => "555 555-5555",
				"billing_email" => "customer@woocommercecoree2etestsuite.com",
				"shipping_first_name" => "Maggie",
				"shipping_last_name" => "Simpson",
				"shipping_address_1" => "123 Evergreen Terrace",
				"shipping_city" => "Springfield",
				"shipping_country" => "US",
				"shipping_state" => "OR",
				"shipping_postcode" => "97403",
			) as $key => $value
		) {
			update_user_meta( $user_id, $key, $value );
		}
		$classic = get_page_by_path( "classic-checkout" );
		if ( ! $classic ) {
			$classic_id = wp_insert_post(
				array(
					"post_type" => "page",
					"post_status" => "publish",
					"post_title" => "Classic checkout",
					"post_name" => "classic-checkout",
					"post_content" => "<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->",
				),
				true
			);
			if ( is_wp_error( $classic_id ) ) {
				WP_CLI::error( $classic_id->get_error_message() );
			}
		}
		$checkout_id = (int) get_option( "woocommerce_checkout_page_id" );
		if ( $checkout_id <= 0 ) {
			WP_CLI::error( "WooCommerce checkout page is missing." );
		}
		wp_update_post(
			array(
				"ID" => $checkout_id,
				"post_content" => "<!-- wp:woocommerce/checkout /-->",
			)
		);
		echo "prepared";
	' > /dev/null
	store_wp option update woocommerce_coming_soon no > /dev/null
	store_wp option update woocommerce_currency USD > /dev/null
	store_wp option update e2e_woopayments_transition_marker "$marker" > /dev/null

	update_state wpcom_blog_registration_attempted true boolean
	update_state phase 'wpcom-blog-registration-attempted'
	local registration_output
	local registration_status
	set +e
	registration_output="$(
		"$WPCOM_LOCAL_BIN" wp -- --user=1 eval \
			"/* transition_register_blog */
			if ( ! class_exists( 'Jetpack_Data' ) || ! defined( 'JETPACK__SKIP_IS_ACCESSIBLE_CHECK_SECRET' ) ) {
				WP_CLI::error( 'Local Jetpack registration is unavailable.' );
			}
			\$registered = Jetpack_Data::register_site(
				array(
					'siteurl' => '$base_url',
					'home' => '$base_url',
					'site_name' => '$marker',
					'state' => '1',
					'skip_is_accessible_check' => JETPACK__SKIP_IS_ACCESSIBLE_CHECK_SECRET,
				)
			);
			if ( is_wp_error( \$registered ) || empty( \$registered->jetpack_id ) ) {
				WP_CLI::error( is_wp_error( \$registered ) ? \$registered->get_error_message() : 'Registration returned no blog ID.' );
			}
			\$blog_id = (int) \$registered->jetpack_id;
			switch_to_blog( \$blog_id );
			update_option( 'e2e_woopayments_transition_marker', '$marker' );
			\$identity = array(
				'wpcom_blog_id' => \$blog_id,
				'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
				'home' => untrailingslashit( home_url() ),
				'marker' => get_option( 'e2e_woopayments_transition_marker' ),
			);
			restore_current_blog();
			echo wp_json_encode( \$identity );"
	)"
	registration_status=$?
	set -e
	local registration=''
	local blog_id
	blog_id=''
	if registration="$(
		printf '%s' "$registration_output" |
			json_object_from_stdin 2> /dev/null
	)"; then
		blog_id="$(
			node -p '
				const value = JSON.parse( process.argv[ 1 ] );
				const home = new URL( value.home );
				if (
					! Number.isSafeInteger( value.wpcom_blog_id ) ||
					value.wpcom_blog_id <= 0 ||
					value.domain !== process.argv[ 2 ] ||
					home.pathname !== "/" ||
					home.search ||
					home.hash ||
					home.origin !== process.argv[ 3 ] ||
					value.marker !== process.argv[ 4 ]
				) process.exit( 1 );
				value.wpcom_blog_id;
			' \
			"$registration" \
			"$(state_field domain)" \
			"$(state_field home)" \
			"$(state_field marker)"
		)"
	fi
	if [[ -n "$blog_id" ]]; then
		update_state wpcom_blog_id "$blog_id" number
		update_state wpcom_blog_created true boolean
		update_state phase 'wpcom-blog-created'
	fi
	if (( registration_status != 0 )); then
		return "$registration_status"
	fi
	if [[ -z "$blog_id" ]]; then
		echo 'Local WPCOM registration returned no exact transition blog identity.' >&2
		return 1
	fi

	store_wp wcpay_dev local_wpcom_jetpack enable "$wpcom_url" > /dev/null
	store_wp wcpay_dev redirect_to "$wpcom_api_url" > /dev/null
	store_wp wcpay_dev set_blog_id "$blog_id" > /dev/null

	require_fresh_account_snapshot "$(query_account_snapshot)"
	update_state account_creation_attempted true boolean
	update_state phase 'account-creation-attempted'
	local account_output
	local account_status
	if account_output="$(
		E2E_TRANSITION_PROVISIONER_PID="$$" \
			store_wp --user=1 wcpay-dev test-lab account create --type=test_drive --country=US --format=json
	)"; then
		account_status=0
	else
		account_status=$?
	fi
	local returned_account_id=''
	local account=''
	if account="$(
		printf '%s' "$account_output" |
			json_object_from_stdin 2> /dev/null
	)"; then
		returned_account_id="$(
			node -p '
				const value = JSON.parse( process.argv[ 1 ] );
				if (
					value.success !== true ||
					value.is_test_drive !== true ||
					typeof value.account_id !== "string" ||
					! /^acct_[A-Za-z0-9_]+$/.test( value.account_id ) ||
					value.deleted_account
				) process.exit( 1 );
				value.account_id;
			' "$account" 2> /dev/null || true
		)"
	fi
	local account_id
	account_id="$(account_id_from_snapshot "$(query_account_snapshot)")"
	if [[ -n "$returned_account_id" && "$returned_account_id" != "$account_id" ]]; then
		echo 'Transition account creation response does not match exact local Test Lab evidence.' >&2
		return 1
	fi
	update_state account_id "$account_id"
	update_state account_alias "transition-${run_id}"
	if (( account_status != 0 )) || [[ -z "$returned_account_id" ]]; then
		update_state account_id_recovered true boolean
	fi
	update_state account_created true boolean
	update_state phase 'account-created'
	if (( account_status != 0 )); then
		return "$account_status"
	fi

	local store_identity
	store_identity="$(query_store_identity)"
	validate_created_identity "$store_identity"
	local callback
	callback="$(
		"$WPCOM_LOCAL_BIN" --json wcpay callback probe \
			--store-url "$base_url" \
			--wpcom-blog-id "$blog_id" |
			json_object_from_stdin
	)"
	validate_callback_probe "$callback"
	node -e '
		const { writeFileSync } = require( "node:fs" );
		const proof = JSON.parse( process.argv[ 2 ] );
		const context = proof.context;
		const evidence = {
			store_url: context.store_url,
			wpcom_blog_id: Number( context.wpcom_blog_id ),
			callback_registered: true,
			callback_reachable: true,
			callback_auth_model: context.callback_auth_model,
			callback_provider_write: false,
			callback_response_result: context.callback_response_result,
			callback_route: context.callback_route,
			callback_delivered_route: context.callback_delivered_route,
			ingress_routes: context.ingress_routes,
		};
		writeFileSync( process.argv[ 1 ], `${ JSON.stringify( evidence ) }\n`, { mode: 0o600 } );
	' "$workspace/evidence/callback-probe.json" "$callback"
	update_state phase 'ready'
	trap - ERR
	emit_create_result "$receipt"
}

query_store_identity() {
	store_wp eval '
		/* transition_identity_probe */
		echo wp_json_encode(
			array(
				"site_url" => untrailingslashit( site_url() ),
				"home" => untrailingslashit( home_url() ),
				"marker" => get_option( "e2e_woopayments_transition_marker" ),
				"wpcom_blog_id" => class_exists( "Jetpack_Options" ) ? (int) Jetpack_Options::get_option( "id" ) : 0,
			)
		);
	' | json_object_from_stdin
}

query_blog_identity() {
	"$WPCOM_LOCAL_BIN" wp -- --user=1 eval \
		"/* transition_blog_identity */
		\$blog_id = $(state_field wpcom_blog_id);
		\$details = get_blog_details( \$blog_id );
		if ( ! \$details ) {
			echo wp_json_encode( array( 'exists' => false, 'wpcom_blog_id' => \$blog_id ) );
			return;
		}
		switch_to_blog( \$blog_id );
		\$identity = array(
			'exists' => true,
			'wpcom_blog_id' => \$blog_id,
			'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'home' => untrailingslashit( home_url() ),
			'marker' => get_option( 'e2e_woopayments_transition_marker' ),
		);
		restore_current_blog();
		echo wp_json_encode( \$identity );" |
		json_object_from_stdin
}

find_expected_blog_identity() {
	"$WPCOM_LOCAL_BIN" wp -- --user=1 eval \
		"/* transition_find_blog_identity */
		global \$wpdb;
		\$expected_domain = '$(state_field domain)';
		\$expected_home = '$(state_field home)';
		\$expected_marker = '$(state_field marker)';
		\$candidate_ids = \$wpdb->get_col(
			\$wpdb->prepare(
				\"SELECT blog_id FROM {\$wpdb->blogs} WHERE domain = %s\",
				\$expected_domain
			)
		);
		\$matches = array();
		foreach ( array_unique( array_map( 'intval', \$candidate_ids ) ) as \$candidate_id ) {
			if ( \$candidate_id <= 0 || ! get_blog_details( \$candidate_id ) ) {
				continue;
			}
			switch_to_blog( \$candidate_id );
			\$candidate_home = untrailingslashit( home_url() );
			\$candidate_marker = get_option( 'e2e_woopayments_transition_marker' );
			restore_current_blog();
			if (
				\$expected_home === \$candidate_home &&
				\$expected_marker === \$candidate_marker
			) {
				\$matches[] = array(
					'wpcom_blog_id' => \$candidate_id,
					'domain' => \$expected_domain,
					'home' => \$candidate_home,
					'marker' => \$candidate_marker,
				);
			}
		}
		echo wp_json_encode( array( 'matches' => \$matches ) );" |
		json_object_from_stdin
}

recover_exact_blog_id() {
	node -p '
		const value = JSON.parse( process.argv[ 1 ] );
		if ( ! Array.isArray( value.matches ) || value.matches.length !== 1 ) {
			console.error( "Transition blog recovery requires one unique exact match." );
			process.exit( 1 );
		}
		const match = value.matches[ 0 ];
		const home = new URL( match.home );
		if (
			! Number.isSafeInteger( match.wpcom_blog_id ) ||
			match.wpcom_blog_id <= 0 ||
			match.domain !== process.argv[ 2 ] ||
			home.pathname !== "/" ||
			home.search ||
			home.hash ||
			home.origin !== process.argv[ 3 ] ||
			match.marker !== process.argv[ 4 ]
		) process.exit( 1 );
		match.wpcom_blog_id;
	' \
		"$1" \
		"$(state_field domain)" \
		"$(state_field home)" \
		"$(state_field marker)"
}

destroy_store() {
	local receipt_path="$workspace/rollback-receipt"
	if [[ ! -f "$receipt_path" || -L "$receipt_path" ]] ||
		[[ "$(file_mode "$receipt_path")" != '600' ]] ||
		[[ ! -f "$workspace/resource-state.json" || -L "$workspace/resource-state.json" ]] ||
		[[ "$(file_mode "$workspace/resource-state.json")" != '600' ]]; then
		echo 'Transition destroy requires exact 0600 receipt and resource state files.' >&2
		exit 1
	fi
	local supplied_receipt_path="${rollback_receipt_file:-}"
	if [[ "$supplied_receipt_path" != "$receipt_path" ]]; then
		echo 'Transition destroy receipt path does not match the run-owned workspace.' >&2
		exit 1
	fi
	local receipt_hash
	receipt_hash="$(shasum -a 256 "$receipt_path" | awk '{ print $1 }')"
	if [[ "$receipt_hash" != "$(state_field receipt_sha256)" ]] ||
		[[ "$workspace" != "$(state_field workspace)" ]] ||
		[[ "$(state_field plugin_version)" != "$SEED_VERSION" ]]; then
		echo 'Transition destroy receipt hash or immutable resource identity is invalid.' >&2
		exit 1
	fi
	base_url="$(state_field base_url)"
	store_id="$(state_field store_id)"
	run_id="$(state_field run_id)"
	require_safe_identity
	validate_base_store_identity
	prepare_port_lease_for_destroy

	if [[ "$(state_field account_deleted)" == 'false' ]]; then
		if [[ "$(state_field account_created)" == 'true' ]] ||
			[[ "$(state_field account_creation_attempted)" == 'true' ]]; then
			validate_created_identity "$(query_store_identity)"
			validate_blog_identity "$(query_blog_identity)"
			local account_classification
			account_classification="$(classify_account_snapshot "$(query_account_snapshot)")"
			if [[ "$account_classification" == present:* ]]; then
				local exact_account_id="${account_classification#present:}"
				if [[ "$(state_field account_created)" == 'true' ]]; then
					if [[ "$exact_account_id" != "$(state_field account_id)" ]]; then
						echo 'Transition account teardown identity does not match durable state.' >&2
						return 1
					fi
				else
					update_state account_id "$exact_account_id"
					update_state account_alias "transition-${run_id}"
					update_state account_id_recovered true boolean
					update_state account_created true boolean
					update_state phase 'account-id-recovered'
				fi
				local deleted
				deleted="$(
					E2E_TRANSITION_PROVISIONER_PID="$$" \
						store_wp --user=1 wcpay-dev test-lab account delete --format=json |
						json_object_from_stdin
				)"
				node -e '
					const value=JSON.parse(process.argv[1]);
					if(value.success!==true||value.deleted_account!==process.argv[2])process.exit(1);
				' "$deleted" "$(state_field account_id)"
				if [[ "$(classify_account_snapshot "$(query_account_snapshot)")" != 'absent' ]]; then
					echo 'Transition account deletion did not establish exact local absence.' >&2
					return 1
				fi
			elif [[ "$account_classification" != 'absent' ]]; then
				echo 'Transition account teardown returned ambiguous local evidence.' >&2
				return 1
			fi
		fi
		update_state account_deleted true boolean
		update_state phase 'account-deleted'
	fi

	if [[ "$(state_field wpcom_blog_deleted)" == 'false' ]]; then
		if [[ "$(state_field wpcom_blog_created)" == 'false' ]] &&
			[[ "$(state_field wpcom_blog_registration_attempted)" == 'true' ]]; then
			local recovered_blog_id
			recovered_blog_id="$(recover_exact_blog_id "$(find_expected_blog_identity)")"
			update_state wpcom_blog_id "$recovered_blog_id" number
			update_state wpcom_blog_id_recovered true boolean
			update_state wpcom_blog_created true boolean
			update_state phase 'wpcom-blog-id-recovered'
		fi
		if [[ "$(state_field wpcom_blog_created)" == 'true' ]]; then
			if [[ "$(state_field wpcom_blog_id_recovered)" == 'true' ]]; then
				validate_store_scope "$(query_store_identity)"
			else
				validate_created_identity "$(query_store_identity)"
			fi
			local blog_classification
			blog_classification="$(classify_blog_identity "$(query_blog_identity)")"
			if [[ "$blog_classification" == 'present' ]]; then
				if [[ "$(classify_account_snapshot "$(query_account_snapshot)")" != 'absent' ]]; then
					echo 'Transition blog deletion requires exact local account absence.' >&2
					return 1
				fi
				E2E_TRANSITION_PROVISIONER_PID="$$" \
					"$WPCOM_LOCAL_BIN" wp -- --user=1 eval \
					"/* transition_delete_blog */ wpmu_delete_blog( $(state_field wpcom_blog_id), true ); echo 'deleted';" > /dev/null
				if [[ "$(classify_blog_identity "$(query_blog_identity)")" != 'absent' ]]; then
					echo 'Transition blog deletion did not establish exact absence.' >&2
					return 1
				fi
			elif [[ "$blog_classification" != 'absent' ]]; then
				echo 'Transition blog teardown returned ambiguous identity evidence.' >&2
				return 1
			fi
		fi
		update_state wpcom_blog_deleted true boolean
		update_state phase 'wpcom-blog-deleted'
	fi

	if [[ "$(state_field wp_env_destroyed)" == 'false' ]]; then
		if [[ "$(state_field wp_env_start_attempted)" == 'true' ]]; then
			validate_port_lease_owner
			validate_exact_wp_env_scope
			if [[ "$(state_field wp_env_created)" == 'true' ]]; then
				if [[ "$(state_field wpcom_blog_id_recovered)" == 'true' ]]; then
					validate_store_scope "$(query_store_identity)"
				else
					validate_created_identity "$(query_store_identity)"
				fi
				if [[ "$(classify_account_snapshot "$(query_account_snapshot)")" != 'absent' ]]; then
					echo 'Transition wp-env deletion requires exact local account absence.' >&2
					return 1
				fi
				if [[ "$(state_field wpcom_blog_created)" == 'true' ]]; then
					if [[ "$(classify_blog_identity "$(query_blog_identity)")" != 'absent' ]]; then
						echo 'Transition wp-env deletion requires exact blog absence.' >&2
						return 1
					fi
				fi
			fi
			E2E_TRANSITION_PROVISIONER_PID="$$" wp_env destroy > /dev/null
		fi
		update_state wp_env_destroyed true boolean
	fi
	release_port_lease
	update_state phase 'destroyed'
}

action="${1:-}"
shift || true
parse_arguments "$@"

case "$action" in
	plan)
		require_safe_identity
		validate_seed
		emit_plan
		;;
	create)
		require_safe_identity
		validate_seed
		create_store
		;;
	destroy)
		if [[ -z "$workspace" || ! -d "$workspace" || -L "$workspace" ]]; then
			echo 'Transition workspace must be an existing, non-symlinked directory.' >&2
			exit 1
		fi
		destroy_store
		;;
	*)
		echo 'Usage: provision-transition-store-real.sh plan|create|destroy ...' >&2
		exit 1
		;;
esac
