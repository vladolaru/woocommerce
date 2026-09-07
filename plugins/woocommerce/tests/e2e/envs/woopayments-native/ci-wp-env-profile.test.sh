#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-ci-wp-env.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/diagnostics"
touch "$TEST_ROOT/commands.log"

if [[ "$(jq -r '.testsEnvironment' "$PLUGIN_ROOT/.wp-env.e2e.json")" != 'false' ]]; then
	echo 'The E2E config service contract changed; update the smoke service proof.' >&2
	exit 1
fi

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
if [[ "$*" == *'provider-fixture-audit'* ]]; then
	printf '{"requests":8,"failures":[],"coverage":true,"state_restored":true,"clean":true}\n'
fi
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"
ln -s "$TEST_ROOT/bin/pnpm" "$TEST_ROOT/bin/npx"

readonly -a PROFILE_ENV=(
	PATH="$TEST_ROOT/bin:$PATH"
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/commands.log"
	E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$PLUGIN_ROOT"
	E2E_WOOPAYMENTS_DIAGNOSTICS_DIR="$TEST_ROOT/diagnostics"
	E2E_WOOPAYMENTS_WP_ENV_CONFIG='.wp-env.e2e.json'
)

env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/fresh-activation-smoke.sh"
env "${PROFILE_ENV[@]}" "$SCRIPT_DIR/install-ci-fixture.sh"
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
for predicate in 'is_connected()' 'has_connected_owner()' 'get_connection_owner_id()' 'is_user_connected( 1 )'; do
	if ! grep -Fq "$predicate" "$TEST_ROOT/commands.log"; then
		echo "Fixture installation must prove Jetpack identity predicate: $predicate" >&2
		exit 1
	fi
done
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

test -s "$TEST_ROOT/diagnostics/native/provider-fixture-audit.json"
jq -e '.coverage == true and .state_restored == true' \
	"$TEST_ROOT/diagnostics/native/provider-fixture-audit.json" > /dev/null

echo 'CI wp-env profile tests passed.'
