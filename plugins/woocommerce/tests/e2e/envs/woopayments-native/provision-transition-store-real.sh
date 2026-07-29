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
readonly WP_ENV_BIN_INPUT="${E2E_TRANSITION_WP_ENV_BIN:-$PLUGIN_ROOT/node_modules/.bin/wp-env}"
readonly REFERENCE_STORE_DIR="${E2E_TRANSITION_REFERENCE_STORE_DIR:-$PROJECTS_ROOT/woocommerce-payments}"
readonly REFERENCE_WP_BIN="${E2E_TRANSITION_REFERENCE_WP_BIN:-}"
readonly DOCKER_BIN="${E2E_TRANSITION_DOCKER_BIN:-docker}"
readonly STATE_WRITER="$SCRIPT_DIR/write-transition-state.js"

workspace=''
seed_archive=''
seed_manifest=''
run_id=''
base_url=''
store_id=''
rollback_receipt_file=''
wp_env_bin=''

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

validate_wp_env_binary() {
	local resolved_wp_env_bin
	if ! resolved_wp_env_bin="$(
		node -e '
			const { realpathSync, statSync } = require( "node:fs" );
			let resolved;
			try {
				resolved = realpathSync( process.argv[ 1 ] );
				if ( ! statSync( resolved ).isFile() ) process.exit( 1 );
			} catch {
				process.exit( 1 );
			}
			process.stdout.write( resolved );
		' "$WP_ENV_BIN_INPUT"
	)" ||
		[[ ! -x "$resolved_wp_env_bin" ]]; then
		echo "Transition wp-env executable is unavailable or unsafe: $WP_ENV_BIN_INPUT" >&2
		return 1
	fi
	wp_env_bin="$resolved_wp_env_bin"
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
	local port_lease_candidate_path="$5"
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
			port_lease_candidate_path: process.argv[ 13 ],
			wpcom_blog_id: 0,
			account_id: "",
			account_alias: "reference-client",
			reference_fixture_borrowed: false,
			wp_env_start_attempted: false,
			wp_env_created: false,
			port_lease_attempted: false,
			port_lease_acquired: false,
			port_lease_collision: false,
			port_lease_release_attempted: false,
			port_lease_released: false,
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
		"$port_lease_path" \
		"$port_lease_candidate_path"
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

port_lease_candidate_path_for() {
	local port="$1"
	local receipt_hash="$2"
	local lease_path
	lease_path="$(port_lease_path_for "$port")"
	local nonce
	nonce="$(
		node -e '
			const { randomBytes } = require( "node:crypto" );
			process.stdout.write( randomBytes( 16 ).toString( "hex" ) );
		'
	)"
	printf '%s/.%s.%s.%s.candidate' \
		"$(dirname "$lease_path")" \
		"$port" \
		"$receipt_hash" \
		"$nonce"
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
	node -e '
		const { closeSync, fsyncSync, openSync } = require( "node:fs" );
		const { dirname } = require( "node:path" );
		for ( const path of [ process.argv[ 1 ], dirname( process.argv[ 1 ] ) ] ) {
			const descriptor = openSync( path, "r" );
			try {
				fsyncSync( descriptor );
			} finally {
				closeSync( descriptor );
			}
		}
	' "$lease_root"
}

validate_port_lease_candidate_path() {
	local lease_path
	lease_path="$(state_field port_lease_path)"
	local candidate_path
	candidate_path="$(state_field port_lease_candidate_path)"
	node -e '
		const { basename, dirname } = require( "node:path" );
		const port = process.argv[ 3 ];
		const receiptHash = process.argv[ 4 ];
		if (
			dirname( process.argv[ 2 ] ) !== dirname( process.argv[ 1 ] ) ||
			! new RegExp(
				`^\\.${ port }\\.${ receiptHash }\\.[a-f0-9]{32}\\.candidate$`
			).test( basename( process.argv[ 2 ] ) )
		) process.exit( 1 );
	' \
		"$lease_path" \
		"$candidate_path" \
		"$(state_field port)" \
		"$(state_field receipt_sha256)"
}

validate_port_lease_owner_file() {
	local owner_path="$1"
	if [[ ! -f "$owner_path" || -L "$owner_path" ]] ||
		[[ "$(file_mode "$owner_path")" != '600' ]]; then
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
		"$owner_path" \
		"$(state_field port)" \
		"$run_id" \
		"$workspace" \
		"$base_url" \
		"$(state_field receipt_sha256)"
}

validate_port_lease_owner() {
	local state_port
	state_port="$(state_field port)"
	local lease_path
	lease_path="$(state_field port_lease_path)"
	if [[ "$lease_path" != "$(port_lease_path_for "$state_port")" ]]; then
		return 1
	fi
	validate_port_lease_owner_file "$lease_path"
}

validate_port_lease_candidate_owner() {
	validate_port_lease_candidate_path
	validate_port_lease_owner_file "$(state_field port_lease_candidate_path)"
}

fsync_port_lease_namespace() {
	node -e '
		const { closeSync, fsyncSync, openSync } = require( "node:fs" );
		const descriptor = openSync( process.argv[ 1 ], "r" );
		try {
			fsyncSync( descriptor );
		} finally {
			closeSync( descriptor );
		}
	' "$(dirname "$(state_field port_lease_path)")"
}

remove_exact_port_lease_candidate() {
	local candidate_path
	candidate_path="$(state_field port_lease_candidate_path)"
	if [[ -e "$candidate_path" || -L "$candidate_path" ]]; then
		validate_port_lease_candidate_owner
		rm "$candidate_path"
		fsync_port_lease_namespace
	fi
}

publish_port_lease_owner() {
	local lease_path
	lease_path="$(state_field port_lease_path)"
	local candidate_path
	candidate_path="$(state_field port_lease_candidate_path)"
	validate_port_lease_candidate_path
	node -e '
		const {
			closeSync,
			fsyncSync,
			linkSync,
			openSync,
			unlinkSync,
			writeSync,
		} = require( "node:fs" );
		const { dirname } = require( "node:path" );
		const finalPath = process.argv[ 1 ];
		const candidatePath = process.argv[ 2 ];
		const owner = Buffer.from( `${ JSON.stringify( {
			port: Number( process.argv[ 3 ] ),
			run_id: process.argv[ 4 ],
			workspace: process.argv[ 5 ],
			base_url: process.argv[ 6 ],
			receipt_sha256: process.argv[ 7 ],
		} ) }\n` );
		const failPoint = process.env.E2E_TRANSITION_LEASE_FAIL_POINT || "";
		const killPoint = process.env.E2E_TRANSITION_TEST_KILL_DURING_LEASE || "";
		const leaseRoot = dirname( finalPath );
		let descriptor;
		let candidateExists = false;
		let published = false;
		const fsyncDirectory = () => {
			const directoryDescriptor = openSync( leaseRoot, "r" );
			try {
				fsyncSync( directoryDescriptor );
			} finally {
				closeSync( directoryDescriptor );
			}
		};
		try {
			descriptor = openSync( candidatePath, "wx", 0o600 );
			candidateExists = true;
			if ( failPoint === "candidate-write" ) {
				writeSync(
					descriptor,
					owner,
					0,
					Math.max( 1, Math.floor( owner.length / 2 ) )
				);
				throw new Error( "Injected lease candidate write failure." );
			}
			let offset = 0;
			while ( offset < owner.length ) {
				const bytesWritten = writeSync(
					descriptor,
					owner,
					offset,
					owner.length - offset
				);
				if ( bytesWritten <= 0 ) {
					throw new Error( "Lease candidate write made no progress." );
				}
				offset += bytesWritten;
			}
			if ( failPoint === "candidate-fsync" ) {
				throw new Error( "Injected lease candidate fsync failure." );
			}
			fsyncSync( descriptor );
			closeSync( descriptor );
			descriptor = undefined;
			if ( killPoint === "before-link" ) {
				process.kill( process.ppid, "SIGKILL" );
				process.exit( 137 );
			}
			if ( failPoint === "link" ) {
				throw new Error( "Injected lease publication link failure." );
			}
			try {
				linkSync( candidatePath, finalPath );
				published = true;
			} catch ( error ) {
				if ( error.code === "EEXIST" ) {
					unlinkSync( candidatePath );
					candidateExists = false;
					fsyncDirectory();
					process.exit( 73 );
				}
				throw error;
			}
			if ( killPoint === "after-link" ) {
				process.kill( process.ppid, "SIGKILL" );
				process.exit( 137 );
			}
			if ( failPoint === "directory-fsync" ) {
				throw new Error( "Injected lease directory fsync failure." );
			}
			fsyncDirectory();
			unlinkSync( candidatePath );
			candidateExists = false;
			fsyncDirectory();
		} catch ( error ) {
			if ( descriptor !== undefined ) {
				closeSync( descriptor );
			}
			if ( candidateExists ) {
				try {
					unlinkSync( candidatePath );
					if ( ! published ) fsyncDirectory();
				} catch {}
			}
			console.error( error.message );
			process.exit( 1 );
		}
	' \
		"$lease_path" \
		"$candidate_path" \
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
	local publication_status
	set +e
	publish_port_lease_owner
	publication_status=$?
	set -e
	if (( publication_status == 73 )); then
		update_state port_lease_collision true boolean
		echo "Transition port $(state_field port) already has a lease; existing ownership was preserved." >&2
		return 1
	fi
	if (( publication_status != 0 )); then
		return "$publication_status"
	fi
	update_state port_lease_acquired true boolean
	update_state phase 'port-lease-acquired'
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
			remove_exact_port_lease_candidate
			return
		fi
		if [[ "$(state_field wp_env_destroyed)" == 'true' ]] &&
			[[ "$(state_field port_lease_release_attempted)" == 'true' ]]; then
			if [[ ! -e "$lease_path" && ! -L "$lease_path" ]]; then
				remove_exact_port_lease_candidate
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
		remove_exact_port_lease_candidate
		return
	fi
	if validate_port_lease_owner; then
		remove_exact_port_lease_candidate
		update_state port_lease_acquired true boolean
		update_state phase 'port-lease-recovered'
		return
	fi
	if [[ "$(state_field wp_env_start_attempted)" != 'false' ]]; then
		echo 'Transition environment mutation has no exact port lease owner.' >&2
		return 1
	fi
	remove_exact_port_lease_candidate
	if [[ -e "$lease_path" || -L "$lease_path" ]]; then
		update_state port_lease_collision true boolean
		update_state phase 'port-lease-collision-recovered'
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
		remove_exact_port_lease_candidate
		update_state port_lease_released true boolean
		return
	fi
	local lease_path
	lease_path="$(state_field port_lease_path)"
	local release_was_attempted
	release_was_attempted="$(state_field port_lease_release_attempted)"
	update_state port_lease_release_attempted true boolean
	remove_exact_port_lease_candidate
	if [[ -e "$lease_path" || -L "$lease_path" ]]; then
		validate_port_lease_owner
		rm "$lease_path"
		fsync_port_lease_namespace
	elif [[ "$release_was_attempted" != 'true' ]]; then
		echo 'Transition port lease disappeared before exact release.' >&2
		return 1
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
		WP_ENV_HOME="$workspace/wp-env-home" "$wp_env_bin" "$@"
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
			config.testsEnvironment !== false ||
			Object.hasOwn( config, "testsPort" ) ||
			config.config?.WP_SITEURL !== process.argv[ 2 ] ||
			config.config?.WP_HOME !== process.argv[ 2 ]
			) process.exit( 1 );
	' "$config_path" "$base_url" "$(state_field port)"
}

store_wp() {
	wp_env run cli wp "$@"
}

reference_wp() {
	if [[ -n "$REFERENCE_WP_BIN" ]]; then
		"$REFERENCE_WP_BIN" "$@"
		return
	fi
	if [[ ! -d "$REFERENCE_STORE_DIR" || -L "$REFERENCE_STORE_DIR" ]]; then
		echo "WooPayments reference store checkout is unavailable or ambiguous: $REFERENCE_STORE_DIR" >&2
		return 1
	fi
	(
		cd "$REFERENCE_STORE_DIR"
		"$DOCKER_BIN" compose exec -T -u www-data wordpress wp "$@"
	)
}

query_reference_fixture() {
	reference_wp --user=1 eval '
		/* transition_reference_fixture */
		if ( ! class_exists( "Jetpack_Options" ) || ! class_exists( "WC_Payments" ) ) {
			WP_CLI::error( "The WooPayments reference fixture is unavailable." );
		}
		$master_user = (int) Jetpack_Options::get_option( "master_user" );
		$user_tokens = Jetpack_Options::get_option( "user_tokens" );
		$user_tokens = is_array( $user_tokens ) ? $user_tokens : array();
		$user_token = isset( $user_tokens[ $master_user ] )
			? $user_tokens[ $master_user ]
			: reset( $user_tokens );
		$account = WC_Payments::get_account_service()->get_cached_account_data();
		$account = is_array( $account ) ? $account : array();
		echo wp_json_encode(
			array(
				"blog_id" => (int) Jetpack_Options::get_option( "id" ),
				"blog_token" => (string) Jetpack_Options::get_option( "blog_token" ),
				"user_token" => is_string( $user_token ) ? $user_token : "",
				"account_id" => (string) ( $account["account_id"] ?? "" ),
				"is_live" => ! empty( $account["is_live"] ),
				"local_wpcom_enabled" => "1" === (string) get_option( "wcpaydev_local_wpcom_jetpack_connection", "0" ),
				"local_wpcom_base_url" => (string) get_option( "wcpaydev_local_wpcom_base_url", "" ),
				"redirect_enabled" => "1" === (string) get_option( "wcpaydev_redirect", "0" ),
				"redirect_to" => (string) get_option( "wcpaydev_redirect_to", "" ),
			)
		);
	' | json_object_from_stdin
}

validate_reference_fixture() {
	node -e '
		const { readFileSync } = require( "node:fs" );
		const fixture = JSON.parse( readFileSync( 0, "utf8" ) );
		const localOnly = ( candidate, rootOnly ) => {
			let url;
			try {
				url = new URL( candidate );
			} catch {
				return false;
			}
			const localHost =
				url.hostname === "localhost" ||
				url.hostname.endsWith( ".localhost" ) ||
				url.hostname === "host.docker.internal";
			return (
				url.protocol === "http:" &&
				localHost &&
				( ! rootOnly || ( url.pathname === "/" && ! url.search && ! url.hash ) )
			);
		};
		if (
			! Number.isSafeInteger( fixture.blog_id ) ||
			fixture.blog_id <= 0 ||
			typeof fixture.blog_token !== "string" ||
			! fixture.blog_token ||
			fixture.blog_token === "123.ABC" ||
			typeof fixture.user_token !== "string" ||
			! fixture.user_token ||
			fixture.user_token === "123.ABC.1" ||
			typeof fixture.account_id !== "string" ||
			! /^acct_[A-Za-z0-9_]+$/.test( fixture.account_id ) ||
			fixture.is_live !== false ||
			fixture.local_wpcom_enabled !== true ||
			! localOnly( fixture.local_wpcom_base_url, true ) ||
			fixture.redirect_enabled !== true ||
			! localOnly( fixture.redirect_to, false )
		) {
			console.error( "The WooPayments reference store is not an established local test fixture." );
			process.exit( 1 );
		}
	' <<< "$1"
}

reference_fixture_field() {
	local fixture="$1"
	local field="$2"
	node -e '
		const { readFileSync } = require( "node:fs" );
		const value = JSON.parse( readFileSync( 0, "utf8" ) )[ process.argv[ 1 ] ];
		if ( value === undefined || value === null || value === "" ) process.exit( 1 );
		process.stdout.write( String( value ) );
	' "$field" <<< "$fixture"
}

inject_reference_fixture() {
	printf '%s' "$1" | store_wp eval '
		/* transition_inject_reference_fixture */
		$fixture = json_decode(
			stream_get_contents( STDIN ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		if (
			! class_exists( "Jetpack_Options" ) ||
			! is_array( $fixture ) ||
			empty( $fixture["blog_id"] ) ||
			empty( $fixture["blog_token"] ) ||
			empty( $fixture["user_token"] )
		) {
			WP_CLI::error( "The borrowed WooPayments fixture is invalid." );
		}
		Jetpack_Options::update_option( "id", (int) $fixture["blog_id"] );
		Jetpack_Options::update_option( "master_user", 1 );
		Jetpack_Options::update_option( "blog_token", (string) $fixture["blog_token"] );
		Jetpack_Options::update_option(
			"user_tokens",
			array( 1 => (string) $fixture["user_token"] )
		);
		echo "configured";
	'
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

write_durable_receipt() {
	local receipt="$1"
	node -e '
		const {
			closeSync,
			fsyncSync,
			openSync,
			writeSync,
		} = require( "node:fs" );
		const receiptPath = process.argv[ 1 ];
		const workspace = process.argv[ 2 ];
		const receipt = Buffer.from( process.argv[ 3 ] );
		const failPoint = process.env.E2E_TRANSITION_RECEIPT_FAIL_POINT || "";
		const receiptDescriptor = openSync( receiptPath, "wx", 0o600 );
		try {
			if ( failPoint === "after-write" ) {
				writeSync( receiptDescriptor, receipt, 0, Math.max( 1, Math.floor( receipt.length / 2 ) ) );
				throw new Error( "Injected receipt write failure." );
			}
			let offset = 0;
			while ( offset < receipt.length ) {
				const bytesWritten = writeSync(
					receiptDescriptor,
					receipt,
					offset,
					receipt.length - offset
				);
				if ( bytesWritten <= 0 ) {
					throw new Error( "Receipt write made no progress." );
				}
				offset += bytesWritten;
			}
			if ( failPoint === "before-file-fsync" ) {
				throw new Error( "Injected receipt file fsync failure." );
			}
			fsyncSync( receiptDescriptor );
		} finally {
			closeSync( receiptDescriptor );
		}
		if ( failPoint === "kill-before-directory-fsync" ) {
			process.kill( process.ppid, "SIGKILL" );
			process.exit( 137 );
		}
		if ( failPoint === "before-directory-fsync" ) {
			throw new Error( "Injected receipt directory fsync failure." );
		}
		const workspaceDescriptor = openSync( workspace, "r" );
		try {
			fsyncSync( workspaceDescriptor );
		} finally {
			closeSync( workspaceDescriptor );
		}
	' "$workspace/rollback-receipt" "$workspace" "$receipt"
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
	validate_wp_env_binary

	local receipt
	receipt="$(
		node -e '
			const { randomBytes } = require( "node:crypto" );
			process.stdout.write( `receipt_${ randomBytes( 32 ).toString( "hex" ) }` );
		'
	)"
	write_durable_receipt "$receipt"
	local receipt_hash
	receipt_hash="$(shasum -a 256 "$workspace/rollback-receipt" | awk '{ print $1 }')"
	local marker="wc-native-transition:${run_id}:${receipt_hash:0:16}"
	local port
	port="$(base_url_port)"
	local port_lease_path
	port_lease_path="$(port_lease_path_for "$port")"
	local port_lease_candidate_path
	port_lease_candidate_path="$(port_lease_candidate_path_for "$port" "$receipt_hash")"
	write_initial_state \
		"$receipt_hash" \
		"$marker" \
		"$port" \
		"$port_lease_path" \
		"$port_lease_candidate_path"

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
	for mount in "$core_repo" "$dev_tools"; do
		if [[ ! -d "$mount" || -L "$mount" ]]; then
			echo "Transition mount is unavailable or ambiguous: $mount" >&2
			return 1
		fi
	done

	mkdir "$workspace/store" "$workspace/wp-env-home" "$workspace/seed"
	tar -xzf "$seed_archive" -C "$workspace/seed"
	node -e '
		const { writeFileSync } = require( "node:fs" );
		const config = {
			core: "https://wordpress.org/wordpress-latest.zip",
			phpVersion: "8.1",
			port: Number( process.argv[ 2 ] ),
			testsEnvironment: false,
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
				"wp-content/plugins/e2e-test-helpers": process.argv[ 7 ],
				"wp-content/mu-plugins/woopayments-native-runtime.php": process.argv[ 8 ],
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
	store_wp plugin activate woocommerce woocommerce-payments woocommerce-payments-dev-tools > /dev/null
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
		echo "prepared";
	' > /dev/null
	store_wp option update woocommerce_coming_soon no > /dev/null
	store_wp option update woocommerce_currency USD > /dev/null
	store_wp option update e2e_woopayments_transition_marker "$marker" > /dev/null

	local reference_fixture
	reference_fixture="$(query_reference_fixture)"
	validate_reference_fixture "$reference_fixture"
	local blog_id
	local account_id
	local local_wpcom_base_url
	local redirect_to
	blog_id="$(reference_fixture_field "$reference_fixture" blog_id)"
	account_id="$(reference_fixture_field "$reference_fixture" account_id)"
	local_wpcom_base_url="$(reference_fixture_field "$reference_fixture" local_wpcom_base_url)"
	redirect_to="$(reference_fixture_field "$reference_fixture" redirect_to)"

	update_state wpcom_blog_id "$blog_id" number
	update_state account_id "$account_id"
	update_state account_alias 'reference-client'
	update_state phase 'reference-fixture-validated'

	store_wp wcpay_dev local_wpcom_jetpack enable "$local_wpcom_base_url" > /dev/null
	store_wp wcpay_dev redirect_to "$redirect_to" > /dev/null
	inject_reference_fixture "$reference_fixture" > /dev/null
	store_wp wcpay_dev refresh_account_data > /dev/null
	store_wp option set woocommerce_woocommerce_payments_settings \
		--format=json '{"enabled":"yes","saved_cards":"yes"}' > /dev/null
	update_state reference_fixture_borrowed true boolean

	local store_identity
	store_identity="$(query_store_identity)"
	validate_created_identity "$store_identity"
	node -e '
		const identity = JSON.parse( process.argv[ 1 ] );
		if (
			identity.account_id !== process.argv[ 2 ] ||
			identity.is_live !== false ||
			identity.blog_token_present !== true ||
			identity.user_token_present !== true
		) process.exit( 1 );
	' "$store_identity" "$account_id"
	update_state phase 'ready'
	trap - ERR
	emit_create_result "$receipt"
}

query_store_identity() {
	store_wp eval '
		/* transition_identity_probe */
		$account = class_exists( "WC_Payments" )
			? WC_Payments::get_account_service()->get_cached_account_data()
			: array();
		$account = is_array( $account ) ? $account : array();
		$user_tokens = class_exists( "Jetpack_Options" )
			? Jetpack_Options::get_option( "user_tokens" )
			: array();
		$user_tokens = is_array( $user_tokens ) ? $user_tokens : array();
		echo wp_json_encode(
			array(
				"site_url" => untrailingslashit( site_url() ),
				"home" => untrailingslashit( home_url() ),
				"marker" => get_option( "e2e_woopayments_transition_marker" ),
				"wpcom_blog_id" => class_exists( "Jetpack_Options" ) ? (int) Jetpack_Options::get_option( "id" ) : 0,
				"blog_token_present" => class_exists( "Jetpack_Options" ) && "" !== (string) Jetpack_Options::get_option( "blog_token" ),
				"user_token_present" => ! empty( array_filter( $user_tokens, "is_string" ) ),
				"account_id" => (string) ( $account["account_id"] ?? "" ),
				"is_live" => ! empty( $account["is_live"] ),
			)
		);
	' | json_object_from_stdin
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
	validate_wp_env_binary
	if [[ "$(state_field phase)" == 'destroyed' ]]; then
		return 0
	fi
	if ! mkdir "$workspace/.destroy-claim" 2> /dev/null; then
		echo 'Transition destroy claim already exists; a concurrent or interrupted destroy owns this workspace.' >&2
		exit 1
	fi
	prepare_port_lease_for_destroy

	if [[ "$(state_field wp_env_destroyed)" == 'false' ]]; then
		if [[ "$(state_field wp_env_start_attempted)" == 'true' ]]; then
			validate_port_lease_owner
			validate_exact_wp_env_scope
			if [[ "$(state_field wp_env_created)" == 'true' ]]; then
				validate_store_scope "$(query_store_identity)"
			fi
			E2E_TRANSITION_PROVISIONER_PID="$$" wp_env destroy --force > /dev/null
		fi
		update_state wp_env_destroyed true boolean
	fi
	if [[ -d "$workspace/seed" && ! -L "$workspace/seed" ]]; then
		find "$workspace/seed" -type d -exec chmod u+w {} +
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
		validate_wp_env_binary
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
