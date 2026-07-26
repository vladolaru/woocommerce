#!/usr/bin/env bash

set -euo pipefail
umask 077

readonly SEED_COMMIT='a1f755fc903966387f8629f78f75976ac8d2016e'
readonly SEED_VERSION='10.5.0'
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

plugin_file="$extracted/woocommerce-payments/woocommerce-payments.php"
if [[ ! -f "$plugin_file" ]] ||
	! grep -Eq '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*10\.5\.0[[:space:]]*$' "$plugin_file"; then
	echo 'The approved seed tree does not identify WooPayments 10.5.0.' >&2
	exit 1
fi
if find "$extracted" -name .git -print -quit | grep -q .; then
	echo 'The approved seed tree unexpectedly contains Git metadata.' >&2
	exit 1
fi

find "$extracted" -type d -exec chmod 0555 {} +
find "$extracted" -type f -exec chmod 0444 {} +

mkdir "$output_dir"
archive_path="$output_dir/woocommerce-payments-${SEED_VERSION}-${SEED_COMMIT}.tar.gz"
manifest_path="$output_dir/woocommerce-payments-${SEED_VERSION}-${SEED_COMMIT}.json"
tar -czf "$archive_path" -C "$extracted" woocommerce-payments
archive_hash="$(shasum -a 256 "$archive_path" | awk '{ print $1 }')"

node -e '
	const { writeFileSync } = require( "node:fs" );
	const manifest = {
		schema_version: 1,
		plugin: "woocommerce-payments",
		plugin_version: process.argv[ 2 ],
		source_commit: process.argv[ 3 ],
		archive_sha256: process.argv[ 4 ],
		archive_format: "tar.gz",
	};
	writeFileSync( process.argv[ 1 ], `${ JSON.stringify( manifest ) }\n`, {
		mode: 0o600,
		flag: "wx",
	} );
' "$manifest_path" "$SEED_VERSION" "$SEED_COMMIT" "$archive_hash"
chmod 0444 "$archive_path" "$manifest_path"

node -e '
	process.stdout.write( `${ JSON.stringify( {
		archive_path: process.argv[ 1 ],
		manifest_path: process.argv[ 2 ],
		plugin_version: process.argv[ 3 ],
		source_commit: process.argv[ 4 ],
	} ) }\n` );
' "$archive_path" "$manifest_path" "$SEED_VERSION" "$SEED_COMMIT"
