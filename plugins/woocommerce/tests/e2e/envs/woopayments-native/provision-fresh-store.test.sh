#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly PROVISION_SCRIPT="$SCRIPT_DIR/provision-fresh-store.sh"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-fresh-provision.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/profiles"
printf '%s\n' '<?php // local WPCOM transport fixture.' > "$TEST_ROOT/wpcom-local-store-transport.php"
transport_adapter="$(cd "$TEST_ROOT" && pwd -P)/wpcom-local-store-transport.php"

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash

set -euo pipefail

readonly COMMAND_LOG="${E2E_FAKE_COMMAND_LOG:?}"
readonly PROFILE_DIR="${E2E_FAKE_PROFILE_DIR:?}"

printf 'pnpm %s\n' "$*" >> "$COMMAND_LOG"

if [[ "$*" == *'exec wp-env --config '*'.wp-env.json start' ]]; then

	test -f "$PROFILE_DIR/.wp-env.json"
	test ! -L "$PROFILE_DIR/.wp-env.json"
	test ! -e "$E2E_FAKE_DATABASE_STATE"
	touch "$E2E_FAKE_DATABASE_STATE"

	exit 0
fi

if [[ "$*" == *'WC_Install::create_pages'* ]]; then

	test -f "$E2E_FAKE_DATABASE_STATE"
	if [[ "$*" == *'$setup_input'* || "$*" != *'fresh-native-proof-1'* ]]; then
		echo 'Fresh setup must receive the concrete run identity.' >&2
		exit 1
	fi
	printf '%s\n' '{"pages_installed":true,"product_id":11,"customer_id":12}'

	exit 0
fi

echo "Unexpected fake pnpm invocation: $*" >&2
exit 1
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"

profile_dir="$(cd "$TEST_ROOT/profiles" && pwd -P)/fresh-native-proof"
common_environment=(
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log"
	E2E_FAKE_DATABASE_STATE="$TEST_ROOT/database-created"
	E2E_FAKE_PROFILE_DIR="$profile_dir"
	E2E_WOOPAYMENTS_FRESH_PNPM_BIN="$TEST_ROOT/bin/pnpm"
	E2E_WOOPAYMENTS_FRESH_STORE_DIR="$profile_dir"
	E2E_WOOPAYMENTS_FRESH_STORE_URL='http://fresh-native.localhost:8188'
	E2E_WOOPAYMENTS_FRESH_STORE_ID='fresh-native-8188'
	E2E_WOOPAYMENTS_FRESH_RUN_ID='fresh-native-proof-1'
	E2E_WOOPAYMENTS_FRESH_WPCOM_TRANSPORT_ADAPTER="$transport_adapter"
)

allocation="$(env "${common_environment[@]}" "$PROVISION_SCRIPT" create)"

jq -e \
	--arg profile_dir "$profile_dir" '
		.profile_path == $profile_dir and
		.wp_env_config == ($profile_dir + "/.wp-env.json") and
		.store_url == "http://fresh-native.localhost:8188" and
		.store_id == "fresh-native-8188" and
		.run_id == "fresh-native-proof-1" and
		.fresh_setup.pages_installed == true and
		.fresh_setup.product_id == 11 and
		.fresh_setup.customer_id == 12
	' <<< "$allocation" > /dev/null
jq -e \
	--arg plugin_root "$PLUGIN_ROOT" \
	--arg transport_adapter "$transport_adapter" '
		type == "object" and
		.testsEnvironment == false and
		.plugins == [ $plugin_root ] and
		.config.WP_HOME == "http://fresh-native.localhost:8188" and
		.config.WP_SITEURL == "http://fresh-native.localhost:8188" and
		.config.E2E_WOOPAYMENTS_NATIVE == true and
		.config.JETPACK_DEV_DEBUG == false and
		.config.WP_DEBUG == false and
		.config.WP_DEBUG_DISPLAY == false and
		.mappings["wp-content/plugins/woocommerce"] == $plugin_root and
		.mappings["wp-content/mu-plugins/woopayments-native-runtime.php"] == ($plugin_root + "/tests/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php") and
		.mappings["wp-content/mu-plugins/wpcom-local-store-transport.php"] == $transport_adapter
	' "$profile_dir/.wp-env.json" > /dev/null
test -f "$TEST_ROOT/database-created"
grep -q -- "^pnpm --dir .* exec wp-env --config $profile_dir/.wp-env.json start$" "$TEST_ROOT/commands.log"
grep -q 'WC_Install::create_pages' "$TEST_ROOT/commands.log"

preexisting_profile="$TEST_ROOT/profiles/existing-native-store"
mkdir "$preexisting_profile"
status=0
env "${common_environment[@]}" \
	E2E_WOOPAYMENTS_FRESH_STORE_DIR="$preexisting_profile" \
	E2E_FAKE_PROFILE_DIR="$preexisting_profile" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/existing.commands" \
	E2E_FAKE_DATABASE_STATE="$TEST_ROOT/existing-database" \
	"$PROVISION_SCRIPT" create > "$TEST_ROOT/unexpected-output" 2> "$TEST_ROOT/expected-error" || status=$?
test "$status" -ne 0
grep -q 'refusing reuse' "$TEST_ROOT/expected-error"
test ! -e "$TEST_ROOT/existing.commands"
test ! -e "$TEST_ROOT/existing-database"

echo 'provision-fresh-store.sh tests passed.'
