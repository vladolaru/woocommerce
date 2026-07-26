#!/usr/bin/env bash

set -euo pipefail
umask 077

readonly SEED_COMMIT='a1f755fc903966387f8629f78f75976ac8d2016e'
readonly SEED_VERSION='10.5.0'
readonly FRONTEND_NODE_LINE='20.11'
readonly FRONTEND_LOCK_SHA256="${E2E_TRANSITION_FRONTEND_LOCK_SHA256:-6e279cfadb1851486976f67a72a11bc9ea36fa62c7f74d31b4d0d73c006b34b1}"
readonly SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
readonly PROJECTS_ROOT="$(
	cd "$SCRIPT_DIR/../../../../../../.."
	pwd -P
)"
readonly WCPAY_REPOSITORY="${E2E_TRANSITION_WCPAY_REPO:-$PROJECTS_ROOT/woocommerce-payments}"
readonly GIT_BIN="${E2E_TRANSITION_GIT_BIN:-git}"
readonly COMPOSER_BIN="${E2E_TRANSITION_COMPOSER_BIN:-composer}"
readonly NODE_BIN="${E2E_TRANSITION_NODE_BIN:-node}"
readonly NPM_BIN="${E2E_TRANSITION_NPM_BIN:-npm}"
readonly TEMP_ROOT="${TMPDIR:?TMPDIR is required}"

output_dir=''
while (( $# > 0 )); do
	case "$1" in
		--output-dir)
			output_dir="${2:-}"
			shift 2
			;;
		*)
			echo "Unknown seed-builder argument: $1" >&2
			exit 1
			;;
	esac
done

if [[ -z "$output_dir" ]]; then
	echo 'build-transition-seed.sh requires --output-dir.' >&2
	exit 1
fi
if [[ ! -d "$WCPAY_REPOSITORY" || -L "$WCPAY_REPOSITORY" ]]; then
	echo 'The primary WooPayments repository must be an exact, non-symlinked directory.' >&2
	exit 1
fi
if [[ -e "$output_dir" ]]; then
	echo "Seed output already exists; refusing overwrite: $output_dir" >&2
	exit 1
fi

resolved_commit="$(
	"$GIT_BIN" -C "$WCPAY_REPOSITORY" rev-parse --verify "${SEED_COMMIT}^{commit}"
)"
if [[ "$resolved_commit" != "$SEED_COMMIT" ]]; then
	echo 'The WooPayments seed floor did not resolve to the approved immutable commit.' >&2
	exit 1
fi

staging="$(mktemp -d "$TEMP_ROOT/woopayments-transition-seed.XXXXXX")"
cleanup() {
	chmod -R u+w "$staging" 2> /dev/null || true
	rm -rf "$staging"
}
trap cleanup EXIT

raw_archive="$staging/source.tar"
extracted="$staging/extracted"
mkdir "$extracted"
"$GIT_BIN" -C "$WCPAY_REPOSITORY" archive \
	--format=tar \
	--prefix=woocommerce-payments/ \
	"$SEED_COMMIT" > "$raw_archive"
tar -xf "$raw_archive" -C "$extracted"

plugin_root="$extracted/woocommerce-payments"
plugin_file="$plugin_root/woocommerce-payments.php"
composer_lock="$plugin_root/composer.lock"
node_version_file="$plugin_root/.nvmrc"
package_manifest="$plugin_root/package.json"
frontend_lock="$plugin_root/package-lock.json"
if [[ ! -f "$plugin_file" ]] ||
	! grep -Eq '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*10\.5\.0[[:space:]]*$' "$plugin_file"; then
	echo 'The approved seed tree does not identify WooPayments 10.5.0.' >&2
	exit 1
fi
if [[ ! -f "$plugin_root/composer.json" || -L "$plugin_root/composer.json" ]] ||
	[[ ! -f "$composer_lock" || -L "$composer_lock" ]]; then
	echo 'The approved seed tree has no exact Composer manifest and lock.' >&2
	exit 1
fi
for frontend_metadata in "$node_version_file" "$package_manifest" "$frontend_lock"; do
	if [[ ! -f "$frontend_metadata" || -L "$frontend_metadata" ]]; then
		echo 'The approved seed tree has no exact frontend manifest, lock, and Node line.' >&2
		exit 1
	fi
done
if find "$extracted" -name .git -print -quit | grep -q .; then
	echo 'The approved seed tree unexpectedly contains Git metadata.' >&2
	exit 1
fi
if [[ -e "$plugin_root/vendor" || -e "$plugin_root/node_modules" || -e "$plugin_root/dist" ]]; then
	echo 'The approved tracked seed unexpectedly contains mutable dependency or distribution artifacts.' >&2
	exit 1
fi
if [[ "$(< "$node_version_file")" != "$FRONTEND_NODE_LINE" ]]; then
	echo "The approved seed requires the pinned Node $FRONTEND_NODE_LINE line." >&2
	exit 1
fi
executing_node_version="$("$NODE_BIN" --version 2> /dev/null || true)"
if [[ ! "$executing_node_version" =~ ^v20\.11\.[0-9]+$ ]]; then
	echo "Transition frontend build requires Node 20.11.x; found ${executing_node_version:-no executable version}." >&2
	exit 1
fi
frontend_lock_hash="$(shasum -a 256 "$frontend_lock" | awk '{ print $1 }')"
if [[ "$frontend_lock_hash" != "$FRONTEND_LOCK_SHA256" ]]; then
	echo 'The approved seed frontend lock does not match the pinned commit provenance.' >&2
	exit 1
fi
if ! "$NODE_BIN" -e '
	const { readFileSync } = require( "node:fs" );
	const manifest = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
	const lock = JSON.parse( readFileSync( process.argv[ 2 ], "utf8" ) );
	const root = lock.packages?.[ "" ];
	if (
		manifest.name !== "woocommerce-payments" ||
		manifest.version !== "10.5.0" ||
		manifest.scripts?.[ "build:client" ] !== "NODE_ENV=production webpack" ||
		lock.name !== "woocommerce-payments" ||
		lock.version !== "10.5.0" ||
		lock.lockfileVersion !== 3 ||
		root?.name !== "woocommerce-payments" ||
		root?.version !== "10.5.0"
	) process.exit( 1 );
' "$package_manifest" "$frontend_lock"; then
	echo 'The approved seed frontend metadata does not match WooPayments 10.5.0.' >&2
	exit 1
fi

"$COMPOSER_BIN" install \
	"--working-dir=$extracted/woocommerce-payments" \
	--no-dev \
	--prefer-dist \
	--no-interaction \
	--no-progress \
	--no-ansi \
	--optimize-autoloader > /dev/null

if ! (
	cd "$plugin_root"
	"$NPM_BIN" ci --ignore-scripts --no-audit --no-fund
) > /dev/null 2>&1; then
	echo 'Locked transition frontend dependency installation failed.' >&2
	exit 1
fi
if ! (
	cd "$plugin_root"
	"$NPM_BIN" run --ignore-scripts build:client
) > /dev/null 2>&1; then
	echo 'Pinned transition frontend production build failed.' >&2
	exit 1
fi

readonly -a EXECUTABLE_SEED_FILES=(
	'vendor/autoload_packages.php'
	'vendor/autoload.php'
	'vendor/composer/installed.php'
	'vendor/composer/installed.json'
	'dist/index.js'
	'dist/index.css'
	'dist/checkout.js'
	'dist/blocks-checkout.js'
)
for executable_file in "${EXECUTABLE_SEED_FILES[@]}"; do
	artifact="$plugin_root/$executable_file"
	if [[ ! -s "$artifact" || -L "$artifact" ]]; then
		echo "Materialized transition seed lacks exact activation artifact: $executable_file" >&2
		exit 1
	fi
done
production_package_count="$(
	"$NODE_BIN" -e '
		const { readFileSync } = require( "node:fs" );
		const lock = JSON.parse( readFileSync( process.argv[ 1 ], "utf8" ) );
		const installedDocument = JSON.parse(
			readFileSync( process.argv[ 2 ], "utf8" )
		);
		const installed = Array.isArray( installedDocument )
			? installedDocument
			: installedDocument.packages;
		if ( ! Array.isArray( lock.packages ) || ! Array.isArray( installed ) ) {
			process.exit( 1 );
		}
		const normalize = ( packages ) =>
			packages
				.map( ( value ) => {
					if (
						typeof value.name !== "string" ||
						typeof value.version !== "string"
					) process.exit( 1 );
					return `${ value.name }@${ value.version }`;
				} )
				.sort();
		if (
			JSON.stringify( normalize( lock.packages ) ) !==
			JSON.stringify( normalize( installed ) )
		) {
			console.error(
				"Materialized transition dependencies do not match the pinned production lock."
			);
			process.exit( 1 );
		}
		process.stdout.write( String( lock.packages.length ) );
	' \
		"$composer_lock" \
		"$extracted/woocommerce-payments/vendor/composer/installed.json"
)"
dependency_lock_hash="$(shasum -a 256 "$composer_lock" | awk '{ print $1 }')"
readonly -a FRONTEND_BUNDLES=(
	'dist/index.js'
	'dist/index.css'
	'dist/checkout.js'
	'dist/blocks-checkout.js'
)
frontend_bundle_hashes=()
for frontend_bundle in "${FRONTEND_BUNDLES[@]}"; do
	frontend_bundle_hashes+=("$(
		shasum -a 256 "$plugin_root/$frontend_bundle" |
			awk '{ print $1 }'
	)")
done
rm -rf "$plugin_root/node_modules"

find "$extracted" -type d -exec chmod 0555 {} +
find "$extracted" -type f -exec chmod 0444 {} +

mkdir "$output_dir"
archive_path="$output_dir/woocommerce-payments-${SEED_VERSION}-${SEED_COMMIT}.tar.gz"
manifest_path="$output_dir/woocommerce-payments-${SEED_VERSION}-${SEED_COMMIT}.json"
tar -czf "$archive_path" -C "$extracted" woocommerce-payments
archive_hash="$(shasum -a 256 "$archive_path" | awk '{ print $1 }')"

"$NODE_BIN" -e '
	const { writeFileSync } = require( "node:fs" );
	const manifest = {
		schema_version: 1,
		plugin: "woocommerce-payments",
		plugin_version: process.argv[ 2 ],
		source_commit: process.argv[ 3 ],
		archive_sha256: process.argv[ 4 ],
		archive_format: "tar.gz",
		dependency_lock_sha256: process.argv[ 5 ],
		production_package_count: Number( process.argv[ 6 ] ),
		frontend_lock_sha256: process.argv[ 7 ],
		frontend_lockfile_version: 3,
		frontend_node_line: process.argv[ 8 ],
		frontend_build_script: "build:client",
		executable_seed_files: [
			"vendor/autoload_packages.php",
			"vendor/autoload.php",
			"vendor/composer/installed.php",
			"vendor/composer/installed.json",
			"dist/index.js",
			"dist/index.css",
			"dist/checkout.js",
			"dist/blocks-checkout.js",
		],
		frontend_bundles: [
			{ path: "dist/index.js", sha256: process.argv[ 9 ] },
			{ path: "dist/index.css", sha256: process.argv[ 10 ] },
			{ path: "dist/checkout.js", sha256: process.argv[ 11 ] },
			{ path: "dist/blocks-checkout.js", sha256: process.argv[ 12 ] },
		],
	};
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( manifest ) }\n`, {
		mode: 0o600,
		flag: "wx",
	} );
' \
	"$manifest_path" \
	"$SEED_VERSION" \
	"$SEED_COMMIT" \
	"$archive_hash" \
	"$dependency_lock_hash" \
	"$production_package_count" \
	"$frontend_lock_hash" \
	"$FRONTEND_NODE_LINE" \
	"${frontend_bundle_hashes[@]}"
chmod 0444 "$archive_path" "$manifest_path"

"$NODE_BIN" -e '
	process.stdout.write( `${ JSON.stringify( {
		archive_path: process.argv[ 1 ],
		manifest_path: process.argv[ 2 ],
		plugin_version: process.argv[ 3 ],
		source_commit: process.argv[ 4 ],
	} ) }\n` );
' "$archive_path" "$manifest_path" "$SEED_VERSION" "$SEED_COMMIT"
