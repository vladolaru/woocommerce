#!/usr/bin/env bash

set -euo pipefail
umask 077

readonly FRONTEND_NODE_LINE='20.11'
readonly FRONTEND_NODE_VERSION='v20.11.1'
readonly FRONTEND_NPM_VERSION='10.2.4'
readonly ARCHIVE_PROFILE='git-sha1-fixed-pax+gzip-n9-v1'
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
readonly ARCHIVE_GIT_BIN="${E2E_TRANSITION_ARCHIVE_GIT_BIN:-git}"
readonly GZIP_BIN="${E2E_TRANSITION_GZIP_BIN:-gzip}"
readonly COMPOSER_BIN="${E2E_TRANSITION_COMPOSER_BIN:-composer}"
readonly NODE_BIN="${E2E_TRANSITION_NODE_BIN:-node}"
readonly NPM_BIN="${E2E_TRANSITION_NPM_BIN:-npm}"
readonly TEMP_ROOT="${TMPDIR:?TMPDIR is required}"

output_dir=''
seed_profile='10.5.0'
while (( $# > 0 )); do
	case "$1" in
		--profile)
			seed_profile="${2:-}"
			shift 2
			;;
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

case "$seed_profile" in
	10.5.0)
		readonly SEED_COMMIT='a1f755fc903966387f8629f78f75976ac8d2016e'
		readonly SEED_VERSION='10.5.0'
		readonly FRONTEND_LOCK_SHA256="${E2E_TRANSITION_FRONTEND_LOCK_SHA256:-6e279cfadb1851486976f67a72a11bc9ea36fa62c7f74d31b4d0d73c006b34b1}"
		;;
	10.4.0)
		readonly SEED_COMMIT='e2a6e70f21ff5827a9e67abeb4bc44c9ccabeb3d'
		readonly SEED_VERSION='10.4.0'
		readonly FRONTEND_LOCK_SHA256="${E2E_TRANSITION_FRONTEND_LOCK_SHA256:-934b317f080dc26760be7364ef64a748b33f6055e03e7accec37f23461ae7bb7}"
		;;
	*)
		echo "Unknown immutable transition seed profile: $seed_profile" >&2
		exit 1
		;;
esac

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

staging="$(mktemp -d "$TEMP_ROOT/woopayments-transition-seed.XXXXXX")"
cleanup() {
	chmod -R u+w "$staging" 2> /dev/null || true
	rm -rf "$staging"
}
trap cleanup EXIT

source_git_home="$staging/source-git-home"
source_git_xdg="$source_git_home/xdg"
mkdir -p "$source_git_home" "$source_git_xdg"
source_git() {
	env \
		-u GIT_ALTERNATE_OBJECT_DIRECTORIES \
		-u GIT_ATTR_SOURCE \
		-u GIT_CEILING_DIRECTORIES \
		-u GIT_COMMON_DIR \
		-u GIT_CONFIG \
		-u GIT_CONFIG_COUNT \
		-u GIT_CONFIG_PARAMETERS \
		-u GIT_DIR \
		-u GIT_DISCOVERY_ACROSS_FILESYSTEM \
		-u GIT_INDEX_FILE \
		-u GIT_NAMESPACE \
		-u GIT_OBJECT_DIRECTORY \
		-u GIT_REPLACE_REF_BASE \
		-u GIT_WORK_TREE \
		GIT_ATTR_NOSYSTEM=1 \
		GIT_CONFIG_GLOBAL=/dev/null \
		GIT_CONFIG_NOSYSTEM=1 \
		GIT_CONFIG_SYSTEM=/dev/null \
		GIT_NO_REPLACE_OBJECTS=1 \
		HOME="$source_git_home" \
		XDG_CONFIG_HOME="$source_git_xdg" \
		"$GIT_BIN" \
		-c core.attributesFile=/dev/null \
		-C "$WCPAY_REPOSITORY" \
		"$@"
}

resolved_commit="$(
	source_git rev-parse --verify "${SEED_COMMIT}^{commit}"
)"
if [[ "$resolved_commit" != "$SEED_COMMIT" ]]; then
	echo 'The WooPayments seed floor did not resolve to the approved immutable commit.' >&2
	exit 1
fi

source_git_dir="$(source_git rev-parse --path-format=absolute --git-dir)"
source_git_common_dir="$(source_git rev-parse --path-format=absolute --git-common-dir)"
source_git_info_attributes="$(
	source_git rev-parse --path-format=absolute --git-path info/attributes
)"
for repository_attributes in \
	"$source_git_info_attributes" \
	"$source_git_dir/info/attributes" \
	"$source_git_common_dir/info/attributes"; do
	if [[ -z "$repository_attributes" || "$repository_attributes" != /* ]]; then
		echo 'The WooPayments source Git attributes path could not be resolved exactly.' >&2
		exit 1
	fi
	if [[ -e "$repository_attributes" || -L "$repository_attributes" ]]; then
		echo "The WooPayments source repository has forbidden repository-local Git attributes: $repository_attributes" >&2
		exit 1
	fi
done

validate_seed_tree() {
	if find "$plugin_root" -name .git -print -quit | grep -q .; then
		echo 'The materialized transition seed contains Git metadata.' >&2
		exit 1
	fi
	if find "$plugin_root" -type l -print -quit | grep -q .; then
		echo 'The materialized transition seed contains a symbolic link.' >&2
		exit 1
	fi
	if find "$plugin_root" ! -type d ! -type f -print -quit | grep -q .; then
		echo 'The materialized transition seed contains a special filesystem entry.' >&2
		exit 1
	fi
	if find "$plugin_root" -type d -empty -print -quit | grep -q .; then
		echo 'The materialized transition seed contains an empty directory.' >&2
		exit 1
	fi
	if find "$plugin_root" -name .gitattributes -print -quit | grep -q .; then
		echo 'The materialized transition seed contains forbidden .gitattributes.' >&2
		exit 1
	fi
	if ! "$NODE_BIN" -e '
		const { readdirSync } = require( "node:fs" );
		const { join } = require( "node:path" );
		const visit = ( root, relative = "" ) => {
			for ( const entry of readdirSync( root, { withFileTypes: true } ) ) {
				const path = relative ? `${ relative }/${ entry.name }` : entry.name;
				if ( /[\u0000-\u001f\u007f]/u.test( path ) ) process.exit( 1 );
				if ( entry.isDirectory() ) visit( join( root, entry.name ), path );
			}
		};
		visit( process.argv[ 1 ] );
	' "$plugin_root"; then
		echo 'The materialized transition seed contains a control character in a path.' >&2
		exit 1
	fi
}

raw_archive="$staging/source.tar"
extracted="$staging/extracted"
mkdir "$extracted"
source_git archive \
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
	! grep -Eq "^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*${SEED_VERSION//./\\.}[[:space:]]*$" "$plugin_file"; then
	echo "The approved seed tree does not identify WooPayments $SEED_VERSION." >&2
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
validate_seed_tree
if [[ "$(< "$node_version_file")" != "$FRONTEND_NODE_LINE" ]]; then
	echo "The approved seed requires the pinned Node $FRONTEND_NODE_LINE line." >&2
	exit 1
fi
executing_node_version="$("$NODE_BIN" --version 2> /dev/null || true)"
if [[ "$executing_node_version" != "$FRONTEND_NODE_VERSION" ]]; then
	echo "Transition frontend build requires Node ${FRONTEND_NODE_VERSION#v}; found ${executing_node_version:-no executable version}." >&2
	exit 1
fi
executing_npm_version="$("$NPM_BIN" --version 2> /dev/null || true)"
if [[ "$executing_npm_version" != "$FRONTEND_NPM_VERSION" ]]; then
	echo "Transition frontend build requires npm $FRONTEND_NPM_VERSION; found ${executing_npm_version:-no executable version}." >&2
	exit 1
fi
if ! composer_version_output="$("$COMPOSER_BIN" --version 2>&1)"; then
	echo 'Transition seed build could not observe the Composer version.' >&2
	exit 1
fi
composer_version="${composer_version_output%%$'\n'*}"
if ! archive_git_version_output="$("$ARCHIVE_GIT_BIN" --version 2>&1)"; then
	echo 'Transition seed build could not observe the archive Git version.' >&2
	exit 1
fi
archive_git_version="${archive_git_version_output%%$'\n'*}"
if ! gzip_version_output="$("$GZIP_BIN" --version 2>&1)"; then
	echo 'Transition seed build could not observe the gzip version.' >&2
	exit 1
fi
gzip_version="${gzip_version_output%%$'\n'*}"
zlib_version="$("$NODE_BIN" -p 'process.versions.zlib' 2> /dev/null || true)"
if [[ -z "$composer_version" || -z "$archive_git_version" || -z "$gzip_version" || -z "$zlib_version" ]]; then
	echo 'Transition seed build could not observe its complete build toolchain.' >&2
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
		manifest.version !== process.argv[ 3 ] ||
		manifest.scripts?.[ "build:client" ] !== "NODE_ENV=production webpack" ||
		lock.name !== "woocommerce-payments" ||
		lock.version !== process.argv[ 3 ] ||
		lock.lockfileVersion !== 3 ||
		root?.name !== "woocommerce-payments" ||
		root?.version !== process.argv[ 3 ]
	) process.exit( 1 );
' "$package_manifest" "$frontend_lock" "$SEED_VERSION"; then
	echo "The approved seed frontend metadata does not match WooPayments $SEED_VERSION." >&2
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
rm -rf "$plugin_root/node_modules"
validate_seed_tree

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
find "$extracted" -type d -exec chmod 0555 {} +
find "$extracted" -type f -exec chmod 0444 {} +

mkdir "$output_dir"
archive_path="$output_dir/woocommerce-payments-${SEED_VERSION}-${SEED_COMMIT}.tar.gz"
manifest_path="$output_dir/woocommerce-payments-${SEED_VERSION}-${SEED_COMMIT}.json"
archive_repository="$staging/archive.git"
archive_index="$staging/archive.index"
archive_home="$staging/archive-home"
archive_xdg="$archive_home/xdg"
canonical_tar="$staging/canonical.tar"
mkdir -p "$archive_home" "$archive_xdg"

if ! env -i \
	PATH="$PATH" \
	LC_ALL=C \
	HOME="$archive_home" \
	XDG_CONFIG_HOME="$archive_xdg" \
	GIT_CONFIG_NOSYSTEM=1 \
	GIT_CONFIG_GLOBAL=/dev/null \
	"$ARCHIVE_GIT_BIN" init \
		--quiet \
		--bare \
		--object-format=sha1 \
		"$archive_repository" > /dev/null; then
	echo 'Transition seed build could not initialize its private SHA-1 archive repository.' >&2
	exit 1
fi

archive_git() {
	env -i \
		PATH="$PATH" \
		LC_ALL=C \
		HOME="$archive_home" \
		XDG_CONFIG_HOME="$archive_xdg" \
		GIT_CONFIG_NOSYSTEM=1 \
		GIT_CONFIG_GLOBAL=/dev/null \
		GIT_DIR="$archive_repository" \
		GIT_WORK_TREE="$plugin_root" \
		GIT_INDEX_FILE="$archive_index" \
		GIT_AUTHOR_NAME='WooCommerce E2E' \
		GIT_AUTHOR_EMAIL='e2e@example.invalid' \
		GIT_AUTHOR_DATE='2000-01-01T00:00:00+0000' \
		GIT_COMMITTER_NAME='WooCommerce E2E' \
		GIT_COMMITTER_EMAIL='e2e@example.invalid' \
		GIT_COMMITTER_DATE='2000-01-01T00:00:00+0000' \
		"$ARCHIVE_GIT_BIN" \
		-c core.autocrlf=false \
		-c core.filemode=false \
		"$@"
}

archive_git add --force --all -- .
archive_tree="$(archive_git write-tree)"
archive_commit="$(
	printf 'WooPayments transition seed\n' |
		archive_git commit-tree "$archive_tree"
)"
if [[ ! "$archive_commit" =~ ^[a-f0-9]{40}$ ]]; then
	echo 'Transition seed build did not create an exact SHA-1 archive commit.' >&2
	exit 1
fi
archive_git \
	-c tar.umask=0222 \
	archive \
	--format=tar \
	--prefix=woocommerce-payments/ \
	"$archive_commit" > "$canonical_tar"
canonical_tar_hash="$(shasum -a 256 "$canonical_tar" | awk '{ print $1 }')"
"$GZIP_BIN" -n -9 -c "$canonical_tar" > "$archive_path"
archive_hash="$(shasum -a 256 "$archive_path" | awk '{ print $1 }')"

"$NODE_BIN" -e '
	const { writeFileSync } = require( "node:fs" );
	const manifest = {
		schema_version: 2,
		plugin: "woocommerce-payments",
		plugin_version: process.argv[ 2 ],
		source_commit: process.argv[ 3 ],
		archive_sha256: process.argv[ 4 ],
		canonical_tar_sha256: process.argv[ 13 ],
		archive_profile: process.argv[ 14 ],
		archive_format: "tar.gz",
		build_toolchain: {
			composer: process.argv[ 15 ],
			git: process.argv[ 16 ],
			gzip: process.argv[ 17 ],
			node: process.argv[ 18 ],
			npm: process.argv[ 19 ],
			zlib: process.argv[ 20 ],
		},
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
	"${frontend_bundle_hashes[@]}" \
	"$canonical_tar_hash" \
	"$ARCHIVE_PROFILE" \
	"$composer_version" \
	"$archive_git_version" \
	"$gzip_version" \
	"$executing_node_version" \
	"$executing_npm_version" \
	"$zlib_version"
chmod 0444 "$archive_path" "$manifest_path"

"$NODE_BIN" -e '
	process.stdout.write( `${ JSON.stringify( {
		archive_path: process.argv[ 1 ],
		manifest_path: process.argv[ 2 ],
		plugin_version: process.argv[ 3 ],
		source_commit: process.argv[ 4 ],
	} ) }\n` );
' "$archive_path" "$manifest_path" "$SEED_VERSION" "$SEED_COMMIT"
