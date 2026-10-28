#!/usr/bin/env bash

set -euo pipefail

usage() {
	echo 'Usage: extensions-profile.sh --pin-set oldest|latest --store-url URL --wp-env-config JSON --wp-env-service cli|tests-cli' >&2
	exit 2
}

PIN_SET=''
STORE_URL=''
WP_ENV_CONFIG=''
WP_ENV_SERVICE=''

while [[ $# -gt 0 ]]; do
	case "$1" in
		--pin-set) [[ $# -ge 2 ]] || usage; PIN_SET="$2"; shift 2 ;;
		--store-url) [[ $# -ge 2 ]] || usage; STORE_URL="$2"; shift 2 ;;
		--wp-env-config) [[ $# -ge 2 ]] || usage; WP_ENV_CONFIG="$2"; shift 2 ;;
		--wp-env-service) [[ $# -ge 2 ]] || usage; WP_ENV_SERVICE="$2"; shift 2 ;;
		*) usage ;;
	esac
done

[[ "$PIN_SET" == 'oldest' || "$PIN_SET" == 'latest' ]] || usage
[[ -n "$STORE_URL" && -n "$WP_ENV_CONFIG" ]] || usage
[[ "$WP_ENV_SERVICE" == 'cli' || "$WP_ENV_SERVICE" == 'tests-cli' ]] || usage
: "${TMPDIR:?TMPDIR is required}"

if [[ "$STORE_URL" =~ :8889(/|$) ]]; then
	echo 'The extension compatibility profile must never target the manual native store on port 8889.' >&2
	exit 1
fi
if [[ "$WP_ENV_SERVICE" == 'tests-cli' && ! "$STORE_URL" =~ :8187(/|$) ]]; then
	echo 'The local tests-cli extension profile must target the disposable tests environment on port 8187.' >&2
	exit 1
fi

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
WORK_ROOT="$(mktemp -d "${TMPDIR%/}/woopayments-extension-profile.XXXXXX")"
readonly PLUGIN_ROOT WORK_ROOT
trap 'rm -rf -- "$WORK_ROOT"' EXIT

readonly -a EXTENSION_KEYS=( subscriptions bookings deposits square paypal-payments )
readonly -a EXTENSION_SLUGS=(
	'woocommerce-subscriptions'
	'woocommerce-bookings'
	'woocommerce-deposits'
	'woocommerce-square'
	'woocommerce-paypal-payments'
)
readonly -a EXTENSION_OLDEST_PINS=( 7.5.0 3.5.3 2.2.9 4.7.4 2.9.6 )
readonly -a EXTENSION_LATEST_PINS=( 9.2.0 3.10.0 2.4.7 5.5.0 4.1.3 )
readonly -a EXTENSION_MAIN_FILES=(
	'woocommerce-subscriptions.php'
	'woocommerce-bookings.php'
	'woocommerce-deposits.php'
	'woocommerce-square.php'
	'woocommerce-paypal-payments.php'
)
readonly -a WP_ENV=( pnpm exec wp-env --config "$WP_ENV_CONFIG" run "$WP_ENV_SERVICE" )

normalize_origin() {
	printf '%s\n' "$1" | sed -E 's#/+$##'
}

# Prints the unpacked plugin directory of a WooCommerce.com release: from the runner's
# E2E_WOOPAYMENTS_EXTENSION_ZIPS directory when set, else from `wccom-ext get`.
woocommerce_com_package() {
	local slug="$1"
	local version="$2"

	if [[ -n "${E2E_WOOPAYMENTS_EXTENSION_ZIPS:-}" ]]; then
		local zip="${E2E_WOOPAYMENTS_EXTENSION_ZIPS%/}/$slug.$version.zip"
		[[ -f "$zip" ]] || {
			echo "Missing WooCommerce.com package: $zip" >&2
			return 1
		}
		unzip -q "$zip" -d "$WORK_ROOT/$slug"
		printf '%s\n' "$WORK_ROOT/$slug/$slug"
	elif command -v wccom-ext > /dev/null 2>&1; then
		wccom-ext get "$slug" "$version"
	else
		echo "Missing WooCommerce.com package $slug.$version.zip: set E2E_WOOPAYMENTS_EXTENSION_ZIPS to a directory holding it, or put wccom-ext on PATH." >&2
		return 1
	fi
}

cd "$PLUGIN_ROOT"
[[ -f "$WP_ENV_CONFIG" ]] || {
	echo "WooPayments extension profile wp-env config does not exist: $WP_ENV_CONFIG" >&2
	exit 1
}

actual_store_url="$("${WP_ENV[@]}" wp option get siteurl)"
actual_store_url="$(printf '%s\n' "$actual_store_url" | sed -nE 's#.*(https?://[^[:space:]]+).*#\1#p' | tail -n 1)"
if [[ -z "$actual_store_url" || "$(normalize_origin "$actual_store_url")" != "$(normalize_origin "$STORE_URL")" ]]; then
	echo "The selected wp-env service is not the requested extension compatibility store: expected $STORE_URL, got ${actual_store_url:-no URL}." >&2
	exit 1
fi

declare -a selected_versions=()
declare -a profile_plugins=()

for index in "${!EXTENSION_KEYS[@]}"; do
	key="${EXTENSION_KEYS[$index]}"
	slug="${EXTENSION_SLUGS[$index]}"
	if [[ "$PIN_SET" == 'oldest' ]]; then
		version="${EXTENSION_OLDEST_PINS[$index]}"
	else
		version="${EXTENSION_LATEST_PINS[$index]}"
	fi

	echo "Preparing $key $version"
	if "${WP_ENV[@]}" wp plugin is-active "$slug" > /dev/null 2>&1; then
		"${WP_ENV[@]}" wp plugin deactivate "$slug" --quiet
	fi
	if [[ "$key" == 'square' || "$key" == 'paypal-payments' ]]; then
		"${WP_ENV[@]}" wp plugin install "https://downloads.wordpress.org/plugin/$slug.$version.zip" --force --quiet
	else
		package_directory="$(woocommerce_com_package "$slug" "$version")"
		"${WP_ENV[@]}" rm -rf "wp-content/plugins/$slug"
		"${WP_ENV[@]}" mkdir -p "wp-content/plugins/$slug"
		tar --no-xattrs -C "$package_directory" -cf - . | \
			"${WP_ENV[@]}" tar -xf - -C "wp-content/plugins/$slug"
	fi

	selected_versions+=( "$version" )
	profile_plugins+=( "$slug/${EXTENSION_MAIN_FILES[$index]}" )
done

"${WP_ENV[@]}" wp plugin activate "${profile_plugins[@]}" --quiet

profile_json="$(
	jq -cn \
		--arg pin_set "$PIN_SET" \
		--arg subscriptions "${selected_versions[0]}" \
		--arg bookings "${selected_versions[1]}" \
		--arg deposits "${selected_versions[2]}" \
		--arg square "${selected_versions[3]}" \
		--arg paypal_payments "${selected_versions[4]}" \
		'{pin_set:$pin_set,versions:{subscriptions:$subscriptions,bookings:$bookings,deposits:$deposits,square:$square,"paypal-payments":$paypal_payments}}'
)"
"${WP_ENV[@]}" wp option update e2e_woopayments_extension_compat_profile "$profile_json" --format=json --quiet

for index in "${!EXTENSION_KEYS[@]}"; do
	actual_version="$("${WP_ENV[@]}" wp plugin get "${profile_plugins[$index]}" --field=version)"
	if [[ "$actual_version" != "${selected_versions[$index]}" ]]; then
		echo "Activated ${EXTENSION_KEYS[$index]} version mismatch: expected ${selected_versions[$index]}, got $actual_version." >&2
		exit 1
	fi
	"${WP_ENV[@]}" wp plugin is-active "${profile_plugins[$index]}"
	printf '%s\t%s\tactive\n' "${EXTENSION_KEYS[$index]}" "$actual_version"
done
