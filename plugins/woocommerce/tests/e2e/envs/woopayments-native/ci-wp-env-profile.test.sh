#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-ci-wp-env.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/diagnostics"
touch "$TEST_ROOT/commands.log"
printf 'true\n' > "$TEST_ROOT/fixture-state"
readonly CONTAINER_ROOT="$TEST_ROOT/container"
readonly RUNTIME_SOURCE='wp-content/plugins/woocommerce/tests/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php'
readonly RUNTIME_TARGET='wp-content/mu-plugins/woopayments-native-runtime.php'

mkdir -p "$(dirname "$CONTAINER_ROOT/$RUNTIME_SOURCE")" "$(dirname "$CONTAINER_ROOT/$RUNTIME_TARGET")"
printf '<?php\n// Fake mounted WooPayments runtime.\n' > "$CONTAINER_ROOT/$RUNTIME_SOURCE"
ln "$CONTAINER_ROOT/$RUNTIME_SOURCE" "$CONTAINER_ROOT/$RUNTIME_TARGET"
mkdir -p "$CONTAINER_ROOT/wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native"
printf '<?php\n// Fake provider fixture.\n' > "$CONTAINER_ROOT/wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native/ci-provider-fixture.php"
printf '// Fake Stripe messaging adapter.\n' > "$CONTAINER_ROOT/wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native/stripe-messaging-adapter.js"
readonly RUNTIME_SOURCE_SHA256="$(shasum -a 256 "$CONTAINER_ROOT/$RUNTIME_SOURCE" | awk '{ print $1 }')"

assert_runtime_source_unchanged() {
	if [[ "$(shasum -a 256 "$CONTAINER_ROOT/$RUNTIME_SOURCE" | awk '{ print $1 }')" != "$RUNTIME_SOURCE_SHA256" ]]; then
		echo 'Installing native fixtures must not modify the mounted runtime source.' >&2
		exit 1
	fi
}

if [[ "$(jq -r '.testsEnvironment' "$PLUGIN_ROOT/.wp-env.e2e.json")" != 'false' ]]; then
	echo 'The E2E config service contract changed; update the smoke service proof.' >&2
	exit 1
fi

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
arguments=( "$@" )
for index in "${!arguments[@]}"; do
	if [[ "${arguments[$index]}" == 'cli' ]]; then
		container_command="${arguments[$(( index + 1 ))]}"
		container_arguments=( "${arguments[@]:$(( index + 2 ))}" )
		break
	fi
done
case "${container_command:-}" in
	mkdir|sh|cmp|cp)
		(
			cd "${E2E_FAKE_CONTAINER_ROOT:?}"
			command "$container_command" "${container_arguments[@]}"
		)
		;;
esac
if [[ "$*" == *'wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE false --raw'* ]]; then
	printf 'false\n' > "${E2E_FAKE_FIXTURE_STATE:?}"
fi
if [[ "$*" == *'get_option( "wcpay_account_data", "__missing__" )'* ]]; then
	if [[ "$(< "${E2E_FAKE_FIXTURE_STATE:?}")" != 'false' ]]; then
		echo 'The connected-account fixture was still enabled during the activation premise.' >&2
		exit 1
	fi
	if [[ "$*" != *'false !== has_filter( "pre_option_wcpay_account_data" )'* ]]; then
		echo 'The activation premise did not reject a connected-account pre-option filter.' >&2
		exit 1
	fi
fi
if [[ "$*" == *'DEFAULT_JETPACK__API_BASE'* && "$*" == *'DEFAULT_JETPACK__WPCOM_JSON_API_BASE'* ]]; then
	if [[ "$*" != *'https://jetpack.wordpress.com/jetpack.'* || "$*" != *'https://public-api.wordpress.com'* || "${E2E_FAKE_JETPACK_API_BASE:-https://jetpack.wordpress.com/jetpack.}" != 'https://jetpack.wordpress.com/jetpack.' || "${E2E_FAKE_JETPACK_WPCOM_JSON_API_BASE:-https://public-api.wordpress.com}" != 'https://public-api.wordpress.com' ]]; then
		echo 'Readonly WooPayments fixture requires the canonical public Jetpack transport.' >&2
		exit 1
	fi
fi
if [[ "$*" == *'provider-fixture-audit'* ]]; then
	printf '{"requests":8,"failures":[],"coverage":true,"state_restored":true,"clean":true}\n'
fi
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"
ln -s "$TEST_ROOT/bin/pnpm" "$TEST_ROOT/bin/npx"

readonly -a PROFILE_ENV=(
	PATH="$TEST_ROOT/bin:$PATH"
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log"
	E2E_FAKE_FIXTURE_STATE="$TEST_ROOT/fixture-state"
	E2E_FAKE_CONTAINER_ROOT="$CONTAINER_ROOT"
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$PLUGIN_ROOT"
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/diagnostics"
	E2E_WOOPAYMENTS_WP_ENV_CONFIG='.wp-env.e2e.json'
)

env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/fresh-activation-smoke.sh"
assert_runtime_source_unchanged
env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/fresh-activation-smoke.sh"
assert_runtime_source_unchanged
env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/install-ci-fixture.sh"
assert_runtime_source_unchanged
env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/install-ci-fixture.sh"
assert_runtime_source_unchanged
env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/assert-ci-fixture-clean.sh"

if [[ "$(wc -l < "$TEST_ROOT/commands.log" | tr -d ' ')" -lt 8 ]]; then
	echo 'Expected smoke, fixture installation, and audit commands.' >&2
	exit 1
fi
if grep -Ev '^exec wp-env --config \.wp-env\.e2e\.json run cli ' "$TEST_ROOT/commands.log"; then
	echo 'Every CI WP-CLI operation must select .wp-env.e2e.json and cli.' >&2
	exit 1
fi
if grep -Fq 'tests-cli' "$TEST_ROOT/commands.log"; then
	echo 'The tests-cli service does not exist when testsEnvironment is false.' >&2
	exit 1
fi
if ! grep -Fq 'wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE false --raw' "$TEST_ROOT/commands.log"; then
	echo 'Fresh activation must explicitly disable the connected-account fixture.' >&2
	exit 1
fi
if [[ "$(grep -Fc 'get_option( "wcpay_account_data", "__missing__" )' "$TEST_ROOT/commands.log")" -ne 2 ]]; then
	echo 'Fresh activation must observe the physical account option as absent immediately before activation.' >&2
	exit 1
fi
if [[ "$(grep -Fc 'get_option( "woocommerce_woopayments_account_cache", "__missing__" )' "$TEST_ROOT/commands.log")" -ne 2 ]]; then
	echo 'Fresh activation must observe the legacy account option as absent immediately before activation.' >&2
	exit 1
fi
if ! grep -Fq 'false !== has_filter( "pre_option_wcpay_account_data" )' "$TEST_ROOT/commands.log"; then
	echo 'Fresh activation must prove the connected-account pre-option filter is absent.' >&2
	exit 1
fi
fixture_disable_line="$(grep -n 'wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE false --raw' "$TEST_ROOT/commands.log" | head -n 1 | cut -d: -f1)"
fixture_enable_line="$(grep -n 'wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE true --raw' "$TEST_ROOT/commands.log" | head -n 1 | cut -d: -f1)"
if [[ "$fixture_disable_line" -ge "$fixture_enable_line" ]]; then
	echo 'The provider fixture may only be enabled after the no-account activation smoke.' >&2
	exit 1
fi
for predicate in 'is_connected()' 'has_connected_owner()' 'get_connection_owner_id()' 'is_user_connected( 1 )'; do
	if ! grep -Fq "$predicate" "$TEST_ROOT/commands.log"; then
		echo "Fixture installation must prove Jetpack identity predicate: $predicate" >&2
		exit 1
	fi
done
if ! grep -Fq 'prepare_physical_account_cache_for_run' "$TEST_ROOT/commands.log"; then
	echo 'Fixture installation must capture and neutralize the fresh-activation account error.' >&2
	exit 1
fi
if ! grep -Fq 'refresh_account_data()' "$TEST_ROOT/commands.log"; then
	echo 'The real account service must own the physical cache refresh.' >&2
	exit 1
fi
if ! grep -Fq 'restore_pre_fixture_physical_account_cache' "$TEST_ROOT/commands.log"; then
	echo 'The always-run audit must restore the captured pre-fixture physical cache.' >&2
	exit 1
fi
fixture_enable_line="$(grep -n 'wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE true --raw' "$TEST_ROOT/commands.log" | head -n 1 | cut -d: -f1)"
cache_refresh_line="$(grep -n 'prepare_physical_account_cache_for_run' "$TEST_ROOT/commands.log" | head -n 1 | cut -d: -f1)"
if [[ "$fixture_enable_line" -ge "$cache_refresh_line" ]]; then
	echo 'The provider fixture must be enabled before refreshing the physical account cache.' >&2
	exit 1
fi
for cache_key in wcpay_authorization_summary_cache wcpay_test_authorization_summary_cache; do
	if ! grep -Fq "delete_option( \"$cache_key\" )" "$TEST_ROOT/commands.log"; then
		echo "Fixture installation must clear authorization cache: $cache_key" >&2
		exit 1
	fi
	if ! grep -Fq "wp_cache_delete( \"$cache_key\", \"options\" )" "$TEST_ROOT/commands.log"; then
		echo "Fixture installation must clear authorization object cache: $cache_key" >&2
		exit 1
	fi
done

assert_rejected_transport() {
	local transport="$1"
	local transport_base="$2"
	local output="$TEST_ROOT/transport-preflight.out"
	local api_base='https://jetpack.wordpress.com/jetpack.'
	local wpcom_json_api_base='https://public-api.wordpress.com'
	local preflight_line
	local mutation_commands

	case "$transport" in
		JETPACK__API_BASE)
			api_base="$transport_base"
			;;
		JETPACK__WPCOM_JSON_API_BASE)
			wpcom_json_api_base="$transport_base"
			;;
		*)
			echo "Unknown Jetpack transport: $transport" >&2
			exit 1
			;;
	esac

	: > "$TEST_ROOT/commands.log"
	if env "${PROFILE_ENV[@]}" "E2E_FAKE_JETPACK_API_BASE=$api_base" "E2E_FAKE_JETPACK_WPCOM_JSON_API_BASE=$wpcom_json_api_base" "$SCRIPT_DIR/install-ci-fixture.sh" > "$output" 2>&1; then
		echo "Readonly WooPayments fixture must reject transport override: $transport=$transport_base" >&2
		exit 1
	fi
	if ! grep -Fxq 'Readonly WooPayments fixture requires the canonical public Jetpack transport.' "$output"; then
		echo "Readonly WooPayments fixture must diagnose transport override: $transport=$transport_base" >&2
		exit 1
	fi
	preflight_line="$(grep -n 'DEFAULT_JETPACK__API_BASE' "$TEST_ROOT/commands.log" | head -n 1 | cut -d: -f1)"
	if [[ -z "$preflight_line" ]]; then
		echo "Readonly WooPayments fixture must check the effective Jetpack transport: $transport=$transport_base" >&2
		exit 1
	fi
	mutation_commands="$(tail -n "+$(( preflight_line + 1 ))" "$TEST_ROOT/commands.log")"
	if grep -Fq 'run cli sh -c' <<< "$mutation_commands"; then
		echo "Readonly WooPayments fixture must not copy fixture files after rejecting: $transport=$transport_base" >&2
		exit 1
	fi
	if grep -Fq 'wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE true --raw' <<< "$mutation_commands"; then
		echo "Readonly WooPayments fixture must not enable the fixture after rejecting: $transport=$transport_base" >&2
		exit 1
	fi
	if grep -Fq 'wp option delete' <<< "$mutation_commands"; then
		echo "Readonly WooPayments fixture must not delete options after rejecting: $transport=$transport_base" >&2
		exit 1
	fi
	if grep -Fq 'refresh_account_data()' <<< "$mutation_commands"; then
		echo "Readonly WooPayments fixture must not refresh account data after rejecting: $transport=$transport_base" >&2
		exit 1
	fi
}

readonly -a REJECTED_TRANSPORTS=(
	'JETPACK__API_BASE|http://wpcom.localhost:30001/jetpack.'
	'JETPACK__WPCOM_JSON_API_BASE|http://wpcom.localhost:30001'
	'JETPACK__WPCOM_JSON_API_BASE|https://public-api.wordpress.com.evil.test'
	'JETPACK__WPCOM_JSON_API_BASE|https://user@public-api.wordpress.com'
	'JETPACK__WPCOM_JSON_API_BASE|https://public-api.wordpress.com:443'
)

for rejected_transport in "${REJECTED_TRANSPORTS[@]}"; do
	IFS='|' read -r transport transport_base <<< "$rejected_transport"
	assert_rejected_transport "$transport" "$transport_base"
done

test -s "$TEST_ROOT/diagnostics/native/provider-fixture-audit.json"
jq -e '.coverage == true and .state_restored == true' \
	"$TEST_ROOT/diagnostics/native/provider-fixture-audit.json" > /dev/null

echo 'CI wp-env profile tests passed.'
