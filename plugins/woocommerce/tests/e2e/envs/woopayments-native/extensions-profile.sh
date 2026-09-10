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

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly WORK_ROOT="$(mktemp -d "${TMPDIR%/}/woopayments-extension-profile.XXXXXX")"

cleanup() {
	local status=$?
	if [[ "$WORK_ROOT" == "${TMPDIR%/}"/woopayments-extension-profile.* && -d "$WORK_ROOT" ]]; then
		rm -rf -- "$WORK_ROOT"
	fi
	return "$status"
}
trap cleanup EXIT

readonly -a EXTENSION_KEYS=( subscriptions bookings deposits square paypal-payments )
readonly -a EXTENSION_REPOSITORIES=(
	'https://github.com/woocommerce/woocommerce-subscriptions.git'
	'https://github.com/woocommerce/woocommerce-bookings.git'
	'https://github.com/woocommerce/woocommerce-deposits.git'
	'https://github.com/woocommerce/woocommerce-square.git'
	'https://github.com/woocommerce/woocommerce-paypal-payments.git'
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
readonly -a EXTENSION_CANONICAL_SLUGS=(
	'woocommerce-subscriptions'
	'woocommerce-bookings'
	'woocommerce-deposits'
	'woocommerce-square'
	'woocommerce-paypal-payments'
)
readonly -a WP_ENV=( pnpm exec wp-env --config "$WP_ENV_CONFIG" run "$WP_ENV_SERVICE" )

normalize_origin() {
	printf '%s\n' "$1" | sed -E 's#/+$##'
}

clone_extension() {
	local repository="$1"
	local version="$2"
	local destination="$3"
	local token="${E2E_WOOPAYMENTS_EXTENSION_READ_TOKEN:-}"

	if [[ -z "$token" ]]; then
		GIT_TERMINAL_PROMPT=0 git clone --quiet --depth 1 --branch "$version" "$repository" "$destination"
		return
	fi

	local authorization
	authorization="$(printf 'x-access-token:%s' "$token" | base64 | tr -d '\r\n')"
	GIT_TERMINAL_PROMPT=0 \
		GIT_CONFIG_COUNT=1 \
		GIT_CONFIG_KEY_0='http.https://github.com/.extraheader' \
		GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $authorization" \
		git clone --quiet --depth 1 --branch "$version" "$repository" "$destination"
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
declare -a source_directories=()
declare -a profile_plugins=()

for index in "${!EXTENSION_KEYS[@]}"; do
	key="${EXTENSION_KEYS[$index]}"
	repository="${EXTENSION_REPOSITORIES[$index]}"
	main_file="${EXTENSION_MAIN_FILES[$index]}"
	if [[ "$PIN_SET" == 'oldest' ]]; then
		version="${EXTENSION_OLDEST_PINS[$index]}"
	else
		version="${EXTENSION_LATEST_PINS[$index]}"
	fi
	source_directory="$WORK_ROOT/$(basename "$repository" .git)"
	build_log="$WORK_ROOT/$key-composer.log"

	echo "Preparing $key $version"
	clone_extension "$repository" "$version" "$source_directory"
	if ! (
		cd "$source_directory"
		if grep -Fq 'vendor/bin/strauss' composer.json; then
			composer install --prefer-dist --no-interaction --no-progress --optimize-autoloader
			composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts
		else
			composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
		fi
	) > "$build_log" 2>&1; then
		cat "$build_log" >&2
		exit 1
	fi
	if [[ "$key" == 'square' ]] && ! (
		cd "$source_directory"
		npm ci --no-audit --no-fund
		npm run build:webpack
	) > "$WORK_ROOT/$key-npm.log" 2>&1; then
		cat "$WORK_ROOT/$key-npm.log" >&2
		exit 1
	fi

	selected_versions+=( "$version" )
	source_directories+=( "$source_directory" )
	profile_plugins+=( "woopayments-extension-compat-$key/$main_file" )
done

for index in "${!EXTENSION_KEYS[@]}"; do
	canonical_slug="${EXTENSION_CANONICAL_SLUGS[$index]}"
	profile_plugin="${profile_plugins[$index]}"
	profile_directory="${profile_plugin%%/*}"
	if "${WP_ENV[@]}" wp plugin is-active "$canonical_slug" > /dev/null 2>&1; then
		"${WP_ENV[@]}" wp plugin deactivate "$canonical_slug" --quiet
	fi
	if "${WP_ENV[@]}" wp plugin is-active "$profile_plugin" > /dev/null 2>&1; then
		"${WP_ENV[@]}" wp plugin deactivate "$profile_plugin" --quiet
	fi
	"${WP_ENV[@]}" rm -rf "wp-content/plugins/$profile_directory"
	"${WP_ENV[@]}" mkdir -p "wp-content/plugins/$profile_directory"
	tar --no-xattrs --exclude='.git' --exclude='node_modules' -C "${source_directories[$index]}" -cf - . | \
		"${WP_ENV[@]}" tar -xf - -C "wp-content/plugins/$profile_directory"
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
