#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
readonly PNPM_BIN="${E2E_WOOPAYMENTS_FRESH_PNPM_BIN:-pnpm}"
readonly WPCOM_LOCAL_BIN="${E2E_WPCOM_LOCAL_BIN:-wpcom-local}"
readonly PROVIDER_RUNNER="${E2E_WOOPAYMENTS_FRESH_PROVIDER_RUNNER:-$SCRIPT_DIR/run-provider-families.sh}"
readonly STORE_DIR="${E2E_WOOPAYMENTS_FRESH_STORE_DIR:?E2E_WOOPAYMENTS_FRESH_STORE_DIR is required}"
readonly STORE_URL="${E2E_WOOPAYMENTS_FRESH_STORE_URL:?E2E_WOOPAYMENTS_FRESH_STORE_URL is required}"
readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_FRESH_WP_ENV_CONFIG:?E2E_WOOPAYMENTS_FRESH_WP_ENV_CONFIG is required}"
readonly RESULTS_DIR="${E2E_WOOPAYMENTS_FRESH_RESULTS_DIR:?E2E_WOOPAYMENTS_FRESH_RESULTS_DIR is required}"
readonly STORE_ID="${E2E_WOOPAYMENTS_FRESH_STORE_ID:?E2E_WOOPAYMENTS_FRESH_STORE_ID is required}"
readonly ACCOUNT_ALIAS="${E2E_WOOPAYMENTS_FRESH_ACCOUNT_ALIAS:?E2E_WOOPAYMENTS_FRESH_ACCOUNT_ALIAS is required}"
readonly LOCATION="${E2E_WOOPAYMENTS_FRESH_LOCATION:-US}"
readonly BASIC_CARD_SPEC="$PLUGIN_ROOT/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-basic-card.spec.ts"
readonly FRESH_INSTALL_STATUS_CODE='$account_cache = get_option( "wcpay_account_data", array() ); $account_data = is_array( $account_cache ) && is_array( $account_cache["data"] ?? null ) ? $account_cache["data"] : array(); $account_id = $account_data["account_id"] ?? ""; $account_connected = is_scalar( $account_id ) && "" !== trim( (string) $account_id ); if ( ! function_exists( "is_plugin_active" ) ) { require_once ABSPATH . "wp-admin/includes/plugin.php"; } echo wp_json_encode( array( "fresh_install" => "yes" === get_option( "woocommerce_native_payments_enabled", "no" ) && false === get_option( "woocommerce_woocommerce_payments_version", false ) && ! $account_connected, "native_enabled" => "yes" === get_option( "woocommerce_native_payments_enabled", "no" ), "standalone_plugin_active" => is_plugin_active( "woocommerce-payments/woocommerce-payments.php" ), "account_connected" => $account_connected ) );'
readonly ONBOARDING_INIT_CODE='$controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsRestController::class ); $controller->register_routes(); $request = new WP_REST_Request( "POST", "/wc-admin/settings/payments/woopayments/onboarding/step/test_account/init" ); $request->set_param( "location", "'"$LOCATION"'" ); $request->set_param( "source", "wc_settings_payments" ); $response = rest_do_request( $request ); $data = $response->get_data(); echo wp_json_encode( array( "status" => $response->get_status(), "is_error" => $response->is_error(), "success" => is_array( $data ) && ! empty( $data["success"] ) ) );'
readonly NATIVE_RUNTIME_STATUS_CODE='$request = new WP_REST_Request( "GET", "/wc-native-payments-e2e/v1/status" ); $response = rest_do_request( $request ); echo wp_json_encode( $response->get_data() );'
readonly NATIVE_ACCOUNT_STATUS_CODE='$account_session_rest_controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountSessionRestController::class ); $account_routes_callback = array( $account_session_rest_controller, "register_routes" ); $account_session_rest_controller->register(); if ( false === has_action( "rest_api_init", $account_routes_callback ) ) { throw new RuntimeException( "WooPayments native account session routes were not registered." ); } $account_session_rest_controller->register_routes(); $request = new WP_REST_Request( "GET", "/wc/v3/payments/accounts" ); $response = rest_do_request( $request ); $data = $response->get_data(); echo wp_json_encode( array( "status" => $response->get_status(), "is_error" => $response->is_error(), "account_id" => is_array( $data ) ? (string) ( $data["account_id"] ?? "" ) : "", "test_mode" => is_array( $data ) ? (bool) ( $data["test_mode"] ?? false ) : false ) );'

validate_isolated_profile() {
	if [[ ! "$STORE_URL" =~ ^https?://[^[:space:]]+$ ]]; then
		echo 'Fresh-store onboarding requires an HTTP(S) store URL.' >&2
		exit 64
	fi

	case "$STORE_URL" in
		*:8082* | *:8889*)
			echo 'Fresh-store onboarding refuses the shared WooPayments stores on :8082 and :8889.' >&2
			exit 64
			;;
	esac

	if [[ ! -d "$STORE_DIR" || ! -f "$WP_ENV_CONFIG" || -L "$WP_ENV_CONFIG" || ! -x "$PROVIDER_RUNNER" || ! -f "$BASIC_CARD_SPEC" ]]; then
		echo 'Fresh-store onboarding requires an isolated store, wp-env config, provider runner, and basic-card spec.' >&2
		exit 64
	fi

	if [[ ! "$STORE_ID" =~ ^[a-z0-9][a-z0-9-]{0,63}$ || ! "$ACCOUNT_ALIAS" =~ ^[a-z0-9][a-z0-9-]{0,63}$ || ! "$LOCATION" =~ ^[A-Za-z]{2}$ ]]; then
		echo 'Fresh-store onboarding received an invalid store identity, account alias, or location.' >&2
		exit 64
	fi

	PROFILE_DIR="$(cd "$STORE_DIR" && pwd -P)"
	PROFILE_CONFIG="$(cd "$(dirname "$WP_ENV_CONFIG")" && pwd -P)/$(basename "$WP_ENV_CONFIG")"
	if [[ "$(dirname "$PROFILE_CONFIG")" != "$PROFILE_DIR" || ( "$(basename "$PROFILE_CONFIG")" != '.wp-env.json' && "$(basename "$PROFILE_CONFIG")" != '.wp-env.override.json' ) ]]; then
		echo 'Fresh-store onboarding requires the exact wp-env config in the disposable profile directory.' >&2
		exit 64
	fi

	if [[ -f "$PROFILE_DIR/.wp-env.json" && -f "$PROFILE_DIR/.wp-env.override.json" ]]; then
		echo 'Fresh-store onboarding requires one unambiguous wp-env profile config for wp-env and store doctor.' >&2
		exit 64
	fi

	if ! jq -e --arg store_url "$STORE_URL" '
		def effective_config: (.config // {}) * (.env.development.config // {});
		type == "object" and effective_config.WP_HOME == $store_url and effective_config.WP_SITEURL == $store_url
	' "$PROFILE_CONFIG" > /dev/null; then
		echo 'Fresh-store onboarding requires the disposable profile config to declare the exact store URL.' >&2
		exit 64
	fi

	transport_adapter="$(jq -er '.mappings["wp-content/mu-plugins/wpcom-local-store-transport.php"] | select(type == "string" and length > 0)' "$PROFILE_CONFIG")"
	if [[ ! -f "$transport_adapter" || -L "$transport_adapter" ]]; then
		echo 'Fresh-store onboarding requires the exact regular local WPCOM transport adapter in its profile.' >&2
		exit 64
	fi

	if ! jq -e \
		--arg plugin_root "$PLUGIN_ROOT" \
		--arg store_url "$STORE_URL" \
		--arg transport_adapter "$transport_adapter" '
			type == "object" and
			.testsEnvironment == false and
			.plugins == [ $plugin_root ] and
			.config.WP_HOME == $store_url and
			.config.WP_SITEURL == $store_url and
			.config.E2E_WOOPAYMENTS_NATIVE == true and
			.config.JETPACK_DEV_DEBUG == false and
			.config.WP_DEBUG == false and
			.config.WP_DEBUG_DISPLAY == false and
			.mappings["wp-content/plugins/woocommerce"] == $plugin_root and
			.mappings["wp-content/mu-plugins/woopayments-native-runtime.php"] == ($plugin_root + "/tests/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php") and
			.mappings["wp-content/mu-plugins/wpcom-local-store-transport.php"] == $transport_adapter
		' "$PROFILE_CONFIG" > /dev/null; then
		echo 'Fresh-store onboarding requires the provisioned native WooCommerce, diagnostics, and local transport mappings.' >&2
		exit 64
	fi
}

run_store_wp_json() {
	local code="$1"
	local raw_output
	local json_output

	if ! raw_output="$(
		"$PNPM_BIN" --dir "$PLUGIN_ROOT" exec wp-env --config "$PROFILE_CONFIG" run cli wp --user=1 eval "$code"
	)"; then
		echo 'Fresh-store wp-env command failed.' >&2
		return 1
	fi

	json_output="$(
		printf '%s\n' "$raw_output" |
			sed -n 's/^[^{]*\({.*}\)[^}]*$/\1/p' |
			tail -n 1
	)"

	if ! printf '%s\n' "$json_output" | jq -ce 'select(type == "object")'; then
		echo 'Fresh-store wp-env command did not return a JSON object.' >&2
		return 1
	fi
}

assert_local_wpcom_ready() {
	local readiness_file
	local readiness_status

	readiness_file="$RESULTS_DIR/wpcom-env-status.json"
	if "$WPCOM_LOCAL_BIN" --json env status > "$readiness_file"; then
		:
	else
		readiness_status=$?
		echo 'Fresh-store onboarding requires local WPCOM readiness: env status.' >&2
		return "$readiness_status"
	fi
	if ! jq -e '.status == "success" and .exit_code == 0 and (.context | type == "object") and .context["wpcom-web_generation"] == "current" and .context.ingress_routes == "current"' "$readiness_file" > /dev/null; then
		echo 'Fresh-store onboarding requires a current local WPCOM web runtime and ingress routes.' >&2
		return 1
	fi

	readiness_file="$RESULTS_DIR/wpcom-transact-status.json"
	if "$WPCOM_LOCAL_BIN" --json transact status > "$readiness_file"; then
		:
	else
		readiness_status=$?
		echo 'Fresh-store onboarding requires local WPCOM readiness: transact status.' >&2
		return "$readiness_status"
	fi
	if ! jq -e '.status == "success" and .exit_code == 0 and (.context | type == "object") and .context.readiness_status == "ready" and .context.async_jobs_ready == "true" and .context.async_jobs_mode == "automatic"' "$readiness_file" > /dev/null; then
		echo 'Fresh-store onboarding requires ready local Transact credentials and automatic async jobs.' >&2
		return 1
	fi

	readiness_file="$RESULTS_DIR/wpcom-store-doctor.json"
	if "$WPCOM_LOCAL_BIN" --json store doctor --path "$PROFILE_DIR" > "$readiness_file"; then
		:
	else
		readiness_status=$?
		echo 'Fresh-store onboarding requires local WPCOM readiness: store doctor.' >&2
		return "$readiness_status"
	fi
	if ! jq -e \
		--arg profile_dir "$PROFILE_DIR" \
		--arg profile_config "$PROFILE_CONFIG" \
		--arg store_host "${STORE_URL#*://}" '
			.status == "success" and .exit_code == 0 and
			.context.store_dir == $profile_dir and
			.context.config_path == $profile_config and
			.context.unique_host == "true" and
			.context.live_checked == "true" and
			.context.host == ($store_host | split(":")[0])
		' "$readiness_file" > /dev/null; then
		echo 'Fresh-store onboarding requires store doctor to verify the same explicit wp-env profile and unique store host.' >&2
		return 1
	fi
}

write_provider_fixture() {
	local runtime_status="$1"
	local fixture_path="$RESULTS_DIR/fresh-provider-fixture.json"
	local account_id
	local wpcom_blog_id
	local execution_scope='local'

	account_id="$(jq -er '.account_id | select(type == "string" and length > 0)' <<< "$runtime_status")"
	wpcom_blog_id="$(jq -er '.wpcom_blog_id | select(type == "number" and . > 0)' <<< "$runtime_status")"
	if [[ -n "${CI:-}" ]]; then
		execution_scope='ci'
	fi

	node -e '
		const fixture = {
			schema_version: 1,
			approval_id: `fresh-store-onboarding:${ process.argv[ 1 ] }`,
			execution_scope: process.argv[ 2 ],
			runtime: "native",
			store_id: process.argv[ 1 ],
			site_url: process.argv[ 3 ],
			wpcom_blog_id: Number( process.argv[ 4 ] ),
			account_id: process.argv[ 5 ],
			account_alias: process.argv[ 6 ],
			test_mode: true,
			capabilities: [
				"basic-card-charge",
				"product/payment",
				"basic-card",
				"basic-card-entry",
				"basic-card-charge-coupon",
				"classic-checkout-page",
				"card-testing-protection-setting",
				"block-theme-activation",
			],
		};
		process.stdout.write( JSON.stringify( fixture ) );
	' "$STORE_ID" "$execution_scope" "$STORE_URL" "$wpcom_blog_id" "$account_id" "$ACCOUNT_ALIAS" > "$fixture_path"

	printf '%s\n' "$fixture_path"
}

validate_isolated_profile
mkdir -p "$RESULTS_DIR"

fresh_status="$(run_store_wp_json "$FRESH_INSTALL_STATUS_CODE")"
printf '%s\n' "$fresh_status" > "$RESULTS_DIR/fresh-install.json"
if ! jq -e '
	.fresh_install == true and
	.native_enabled == true and
	.standalone_plugin_active == false and
	.account_connected == false
' <<< "$fresh_status" > /dev/null; then
	echo 'Fresh-store onboarding requires an unconnected fresh native install without the standalone WooPayments plugin.' >&2
	exit 1
fi

assert_local_wpcom_ready

onboarding_status="$(run_store_wp_json "$ONBOARDING_INIT_CODE")"
printf '%s\n' "$onboarding_status" > "$RESULTS_DIR/onboarding-init.json"
if ! jq -e '.status >= 200 and .status < 300 and .is_error == false and .success == true' <<< "$onboarding_status" > /dev/null; then
	echo 'The public NOX test-account onboarding action did not succeed.' >&2
	exit 1
fi

runtime_status="$(run_store_wp_json "$NATIVE_RUNTIME_STATUS_CODE")"
printf '%s\n' "$runtime_status" > "$RESULTS_DIR/native-runtime.json"
if ! jq -e '
	.runtime_owner == "native" and
	.native_enabled == true and
	.account_connected == true and
	(.account_id | type == "string" and length > 0) and
	.test_mode == true and
	(.wpcom_blog_id | type == "number" and . > 0)
' <<< "$runtime_status" > /dev/null; then
	echo 'Fresh-store onboarding did not leave a connected native test account.' >&2
	exit 1
fi

account_status="$(run_store_wp_json "$NATIVE_ACCOUNT_STATUS_CODE")"
printf '%s\n' "$account_status" > "$RESULTS_DIR/native-account.json"
if ! jq -e --arg account_id "$(jq -r '.account_id' <<< "$runtime_status")" '
	.status >= 200 and .status < 300 and
	.is_error == false and
	.account_id == $account_id and
	.test_mode == true
' <<< "$account_status" > /dev/null; then
	echo 'Fresh-store onboarding account session does not match the native test account.' >&2
	exit 1
fi

provider_fixture="$(write_provider_fixture "$runtime_status")"
export WCPAY_RUNTIME=native
"$PROVIDER_RUNNER" \
	--results-dir "$RESULTS_DIR/provider-basic-card" \
	--store-url "$STORE_URL" \
	--native-store-dir "$PROFILE_DIR" \
	--account-id "$(jq -r '.account_id' <<< "$runtime_status")" \
	--account-alias "$ACCOUNT_ALIAS" \
	--store-id "$STORE_ID" \
	--wpcom-blog-id "$(jq -r '.wpcom_blog_id' <<< "$runtime_status")" \
	--wp-env-config "$PROFILE_CONFIG" \
	--provider-fixture "$provider_fixture" \
	"$BASIC_CARD_SPEC"

printf 'Fresh native WooPayments onboarding and the serialized basic-card family passed for %s.\n' "$STORE_URL"
