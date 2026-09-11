#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-fresh-store.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/store" "$TEST_ROOT/results"
readonly TEST_PROFILE_DIR="$(cd "$TEST_ROOT/store" && pwd -P)"
readonly TEST_PROFILE_CONFIG="$TEST_PROFILE_DIR/.wp-env.json"
printf '{"port":8188,"config":{"WP_HOME":"http://fresh-native.localhost:8188","WP_SITEURL":"http://fresh-native.localhost:8188"}}\n' > "$TEST_ROOT/store/.wp-env.json"
jq -n \
	--arg profile_path "$TEST_PROFILE_DIR" \
	--arg wp_env_config "$TEST_PROFILE_CONFIG" \
	--arg store_url 'http://fresh-native.localhost:8188' \
	--arg store_id 'fresh-native-8188' \
	--arg run_id 'fresh-native-proof-1' \
	'{ schema_version: 1, fresh: true, database_id: "fresh-native-db-1", profile_path: $profile_path, wp_env_config: $wp_env_config, store_url: $store_url, store_id: $store_id, run_id: $run_id }' > "$TEST_ROOT/provisioning-receipt.json"

cat > "$TEST_ROOT/bin/wpcom-local" <<'FAKE'
#!/usr/bin/env bash

set -euo pipefail

printf 'wpcom-local %s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
if [[ "${E2E_FAKE_WPCOM_UNREADY:-0}" == '1' && "$*" == '--json env status' ]]; then

	echo 'local WPCOM is unavailable' >&2

	exit 7
fi

case "$*" in
	'--json env status')
		printf '{"status":"success","exit_code":0,"context":{"wpcom_web_generation":"current","ingress_routes":"current"}}\n'
		;;
	'--json transact status')
		printf '{"status":"success","exit_code":0,"context":{"readiness_status":"ready","async_jobs_ready":"true","async_jobs_mode":"automatic"}}\n'
		;;
	'--json store doctor --path '*)
		jq -cn \
			--arg profile_dir "${E2E_FAKE_PROFILE_DIR:?}" \
			--arg profile_config "${E2E_FAKE_PROFILE_CONFIG:?}" \
			'{ status: "success", exit_code: 0, context: { store_dir: $profile_dir, config_path: $profile_config, unique_host: "true", live_checked: "true", host: "fresh-native.localhost" } }'
		;;
	*)
		echo "Unexpected fake wpcom-local invocation: $*" >&2
		exit 1
		;;
esac
FAKE
chmod +x "$TEST_ROOT/bin/wpcom-local"

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE'
#!/usr/bin/env bash

set -euo pipefail

printf 'pnpm %s\n' "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"

if [[ "$*" == *'fresh_install'* ]]; then

	printf '{"fresh_install":true,"native_enabled":true,"standalone_plugin_active":false,"account_connected":false}\n'

	exit 0
fi

if [[ "$*" == *'/test_account/init'* ]]; then

	touch "${E2E_FAKE_ACCOUNT_MARKER:?}"

	printf '{"status":200,"is_error":false,"success":true}\n'

	exit 0
fi

if [[ "$*" == *'/wc-native-payments-e2e/v1/status'* ]]; then

	test -f "${E2E_FAKE_ACCOUNT_MARKER:?}"

	printf '{"runtime_owner":"native","native_enabled":true,"account_connected":true,"account_id":"acct_fresh","wpcom_blog_id":91,"test_mode":true}\n'

	exit 0
fi

if [[ "$*" == *'/wc/v3/payments/accounts'* ]]; then

	test -f "${E2E_FAKE_ACCOUNT_MARKER:?}"

	printf '{"status":200,"is_error":false,"account_id":"acct_fresh","test_mode":true}\n'

	exit 0
fi

echo "Unexpected fake pnpm invocation: $*" >&2
exit 1
FAKE
chmod +x "$TEST_ROOT/bin/pnpm"

cat > "$TEST_ROOT/provider-runner" <<'FAKE'
#!/usr/bin/env bash

set -euo pipefail

printf 'runner WCPAY_RUNTIME=%s %s\n' "${WCPAY_RUNTIME:-}" "$*" >> "${E2E_FAKE_COMMAND_LOG:?}"
test "${WCPAY_RUNTIME:-}" = 'native'

test "$(grep -o 'provider-fidelity-basic-card.spec.ts' <<< "$*" | wc -l | tr -d ' ')" = '1'
test "$(grep -o -- '--provider-fixture' <<< "$*" | wc -l | tr -d ' ')" = '1'

provider_fixture=''
while (( $# > 0 )); do
	case "$1" in
		--provider-fixture)
			provider_fixture="${2:-}"
			shift 2
			;;
		*)
			shift
			;;
	esac
done

jq -e '
	.schema_version == 1 and
	.runtime == "native" and
	.account_id == "acct_fresh" and
	.wpcom_blog_id == 91 and
	.test_mode == true and
	.capabilities == [
		"basic-card-charge",
		"product/payment",
		"basic-card",
		"basic-card-entry",
		"basic-card-charge-coupon",
		"classic-checkout-page",
		"card-testing-protection-setting",
		"block-theme-activation"
	]
' "$provider_fixture" > /dev/null
FAKE
chmod +x "$TEST_ROOT/provider-runner"

common_environment=(
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/success.commands"
	E2E_FAKE_ACCOUNT_MARKER="$TEST_ROOT/account-created"
	E2E_FAKE_PROFILE_DIR="$TEST_PROFILE_DIR"
	E2E_FAKE_PROFILE_CONFIG="$TEST_PROFILE_CONFIG"
	E2E_WPCOM_LOCAL_BIN="$TEST_ROOT/bin/wpcom-local"
	E2E_WOOPAYMENTS_FRESH_PNPM_BIN="$TEST_ROOT/bin/pnpm"
	E2E_WOOPAYMENTS_FRESH_STORE_DIR="$TEST_ROOT/store"
	E2E_WOOPAYMENTS_FRESH_STORE_URL='http://fresh-native.localhost:8188'
	E2E_WOOPAYMENTS_FRESH_WP_ENV_CONFIG="$TEST_ROOT/store/.wp-env.json"
	E2E_WOOPAYMENTS_FRESH_RESULTS_DIR="$TEST_ROOT/results"
	E2E_WOOPAYMENTS_FRESH_STORE_ID='fresh-native-8188'
	E2E_WOOPAYMENTS_FRESH_RUN_ID='fresh-native-proof-1'
	E2E_WOOPAYMENTS_FRESH_PROVISIONING_RECEIPT="$TEST_ROOT/provisioning-receipt.json"
	E2E_WOOPAYMENTS_FRESH_ACCOUNT_ALIAS='fresh-native'
	E2E_WOOPAYMENTS_FRESH_PROVIDER_RUNNER="$TEST_ROOT/provider-runner"
)

env "${common_environment[@]}" "$SCRIPT_DIR/fresh-store-onboarding.sh"

for checkpoint in fresh-install onboarding-init native-runtime native-account; do
	jq -e 'type == "object"' "$TEST_ROOT/results/$checkpoint.json" > /dev/null
done
jq -e '.fresh == true and .database_id == "fresh-native-db-1" and .run_id == "fresh-native-proof-1"' "$TEST_ROOT/results/provisioning-receipt.json" > /dev/null
jq -e '.fresh_install == true and .native_enabled == true' "$TEST_ROOT/results/fresh-install.json" > /dev/null
jq -e '.status == 200 and .success == true' "$TEST_ROOT/results/onboarding-init.json" > /dev/null
jq -e '.runtime_owner == "native" and .account_connected == true' "$TEST_ROOT/results/native-runtime.json" > /dev/null
jq -e '.status == 200 and .account_id == "acct_fresh" and .test_mode == true' "$TEST_ROOT/results/native-account.json" > /dev/null

test "$(grep -c '^wpcom-local ' "$TEST_ROOT/success.commands")" = '3'
grep -q -- "^pnpm --dir .* exec wp-env --config $TEST_PROFILE_CONFIG" "$TEST_ROOT/success.commands"
grep -q -- "^wpcom-local --json store doctor --path $TEST_PROFILE_DIR$" "$TEST_ROOT/success.commands"
test "$(grep -c '/test_account/init' "$TEST_ROOT/success.commands")" = '1'
test "$(grep -c '^runner ' "$TEST_ROOT/success.commands")" = '1'
test "$(grep -n '^wpcom-local ' "$TEST_ROOT/success.commands" | tail -n 1 | cut -d: -f1)" -lt "$(grep -n '/test_account/init' "$TEST_ROOT/success.commands" | cut -d: -f1)"
test "$(grep -n '/test_account/init' "$TEST_ROOT/success.commands" | cut -d: -f1)" -lt "$(grep -n '^runner ' "$TEST_ROOT/success.commands" | cut -d: -f1)"

status=0
env "${common_environment[@]}" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/unready.commands" \
	E2E_FAKE_WPCOM_UNREADY=1 \
	E2E_FAKE_ACCOUNT_MARKER="$TEST_ROOT/unready-account" \
	"$SCRIPT_DIR/fresh-store-onboarding.sh" || status=$?
test "$status" = '7'
if grep -q '/test_account/init\|^runner ' "$TEST_ROOT/unready.commands"; then
	echo 'A local-WPCOM readiness failure must occur before onboarding or provider work.' >&2
	exit 1
fi

status=0
env "${common_environment[@]}" \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/unsafe.commands" \
	E2E_WOOPAYMENTS_FRESH_STORE_URL='http://localhost:8082' \
	"$SCRIPT_DIR/fresh-store-onboarding.sh" || status=$?
test "$status" = '64'
if [[ -e "$TEST_ROOT/unsafe.commands" ]]; then
	echo 'The shared :8082 store must be rejected before any command runs.' >&2
	exit 1
fi

status=0
env "${common_environment[@]}" \
	E2E_WOOPAYMENTS_FRESH_PROVISIONING_RECEIPT='' \
	E2E_FAKE_COMMAND_LOG="$TEST_ROOT/unproven.commands" \
	"$SCRIPT_DIR/fresh-store-onboarding.sh" || status=$?
test "$status" = '1'
if [[ -e "$TEST_ROOT/unproven.commands" ]]; then
	echo 'An unproven native-enabled profile must be rejected before any command runs.' >&2
	exit 1
fi

echo 'fresh-store-onboarding.sh tests passed.'
