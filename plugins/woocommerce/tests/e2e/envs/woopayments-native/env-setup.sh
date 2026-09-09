#!/usr/bin/env bash

set -euo pipefail

readonly WCPAY_RUNTIME="${WCPAY_RUNTIME:?WCPAY_RUNTIME is required}"
readonly DIAGNOSTICS_DIR="${E2E_WOOPAYMENTS_DIAGNOSTICS_DIR:?E2E_WOOPAYMENTS_DIAGNOSTICS_DIR is required}"
readonly WPCOM_LOCAL_BIN="${E2E_WPCOM_LOCAL_BIN:-wpcom-local}"
readonly CLIENT_ACCOUNT_REQUEST_CODE='$request = new WP_REST_Request( "GET", "/wc/v3/payments/accounts" ); $response = rest_do_request( $request ); $data = $response->get_data(); echo wp_json_encode( array( "status" => $response->get_status(), "is_error" => $response->is_error(), "account_id" => is_array( $data ) ? (string) ( $data["account_id"] ?? "" ) : "", "test_mode" => is_array( $data ) ? (bool) ( $data["test_mode"] ?? false ) : false ) );'
readonly NATIVE_ACCOUNT_REQUEST_CODE='$account_session_rest_controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountSessionRestController::class ); $account_routes_callback = array( $account_session_rest_controller, "register_routes" ); $account_session_rest_controller->register(); if ( false === has_action( "rest_api_init", $account_routes_callback ) ) { throw new RuntimeException( "WooPayments native account session routes were not registered." ); } $account_session_rest_controller->register_routes(); $request = new WP_REST_Request( "GET", "/wc/v3/payments/accounts" ); $response = rest_do_request( $request ); $data = $response->get_data(); echo wp_json_encode( array( "status" => $response->get_status(), "is_error" => $response->is_error(), "account_id" => is_array( $data ) ? (string) ( $data["account_id"] ?? "" ) : "", "test_mode" => is_array( $data ) ? (bool) ( $data["test_mode"] ?? false ) : false ) );'
readonly CLIENT_RUNTIME_STATUS_CODE='if ( ! function_exists( "is_plugin_active" ) ) { require_once ABSPATH . "wp-admin/includes/plugin.php"; } $plugin_active = is_plugin_active( "woocommerce-payments/woocommerce-payments.php" ); $account = $plugin_active && class_exists( "WC_Payments" ) ? WC_Payments::get_account_service()->get_cached_account_data() : array(); $account = is_array( $account ) ? $account : array(); $settings = get_option( "woocommerce_woocommerce_payments_settings", array() ); $settings = is_array( $settings ) ? $settings : array(); $methods = $settings["upe_enabled_payment_method_ids"] ?? array(); $methods = is_array( $methods ) ? array_values( array_map( "strval", array_filter( $methods, "is_scalar" ) ) ) : array(); echo wp_json_encode( array( "site_url" => get_site_url(), "wpcom_blog_id" => class_exists( "Jetpack_Options" ) ? (int) Jetpack_Options::get_option( "id" ) : 0, "runtime_owner" => $plugin_active ? "plugin" : "none", "native_enabled" => false, "account_id" => (string) ( $account["account_id"] ?? "" ), "account_connected" => ! empty( $account["account_id"] ), "gateway_enabled" => "yes" === ( $settings["enabled"] ?? "no" ), "test_mode" => "yes" === ( $settings["test_mode"] ?? "no" ), "enabled_payment_methods" => $methods, "last_webhook_fetch" => 0, "callback_probe" => array( "registered" => false, "reachable" => false, "wpcom_blog_id" => 0 ) ) );'
readonly NATIVE_RUNTIME_STATUS_CODE='$request = new WP_REST_Request( "GET", "/wc-native-payments-e2e/v1/status" ); $response = rest_do_request( $request ); echo wp_json_encode( $response->get_data() );'
readonly SHOPPER_FIXTURE_CODE='$login = "customer"; $user = get_user_by( "login", $login ); if ( ! $user ) { $created = wp_insert_user( array( "user_login" => $login, "user_email" => "customer@woocommercecoree2etestsuite.com", "user_pass" => "password", "first_name" => "Jane", "last_name" => "Smith", "role" => "customer" ) ); if ( is_wp_error( $created ) ) { fwrite( STDERR, $created->get_error_message() ); exit( 1 ); } echo "created"; } else { wp_set_password( "password", $user->ID ); echo "reused"; }'
readonly THEME_STATUS_CODE='echo wp_json_encode( array( "active_theme_exists" => wp_get_theme()->exists() ) );'

case "$WCPAY_RUNTIME" in
	client)
		readonly STORE_NAME='client'
		readonly STORE_DIR="${E2E_WOOPAYMENTS_CLIENT_STORE_DIR:?E2E_WOOPAYMENTS_CLIENT_STORE_DIR is required}"
		readonly STORE_URL="${E2E_WOOPAYMENTS_CLIENT_STORE_URL:-http://localhost:8082}"
		readonly EXPECTED_RUNTIME_OWNER='plugin'
		readonly EXPECTED_NATIVE_ENABLED='false'
		;;
	native)
		readonly STORE_NAME='native'
		readonly STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
		readonly STORE_URL="${E2E_WOOPAYMENTS_NATIVE_STORE_URL:-http://store8889.localhost:8889}"
		readonly EXPECTED_RUNTIME_OWNER='native'
		readonly EXPECTED_NATIVE_ENABLED='true'
		readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_WP_ENV_CONFIG:?E2E_WOOPAYMENTS_WP_ENV_CONFIG is required}"
		;;
	*)
		echo 'WCPAY_RUNTIME must be client or native.' >&2
		exit 1
		;;
esac

readonly SCRIPT_DIR="$(
	cd "$(dirname "${BASH_SOURCE[0]}")"
	pwd -P
)"
readonly PLAYWRIGHT_CONFIG="$SCRIPT_DIR/playwright.config.ts"

# Provider readiness applies exactly when this invocation will run at least one
# test tagged @woopayments-provider or @woopayments-transition. The runner
# passes its Playwright arguments through, so listing with those arguments
# describes exactly the collected set. Anything ambiguous — no arguments, a
# failed listing, unparsable output — fails closed to full provider readiness.
PROVIDER_READINESS_REQUIRED=1
if [[ $# -gt 0 ]]; then
	provider_tag_scan=''
	if provider_list_json="$(
		CI=1 BASE_URL="${BASE_URL:-http://localhost:8086}" \
			pnpm exec playwright test \
			--config="$PLAYWRIGHT_CONFIG" --list --reporter=json "$@" \
			2> /dev/null
	)"; then
		provider_tag_scan="$(
			printf '%s\n' "$provider_list_json" | jq -r '
				[ ..
				  | objects
				  | select( has("specs") )
				  | .specs[]
				  | select(
				      [ .tests[].annotations[]?.type ]
				      | index("profile-unavailable")
				      | not
				    )
				  | .tags[]?
				]
				| any( . == "woopayments-provider" or . == "woopayments-transition" )
			' 2> /dev/null
		)" || provider_tag_scan=''
	fi

	if [[ "$provider_tag_scan" == 'false' ]]; then
		PROVIDER_READINESS_REQUIRED=0
	fi
fi
readonly PROVIDER_READINESS_REQUIRED

run_store_wp() {
	local store_name="$1"
	shift

	if [[ "$store_name" == 'native' ]]; then
		pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp "$@"
		return
	fi

	docker compose exec -T -u www-data wordpress wp "$@"
}

run_store_wp_json() {
	local store_name="$1"
	local raw_output
	local json_output
	shift

	if ! raw_output="$(run_store_wp "$store_name" "$@")"; then
		echo "WooPayments $store_name store JSON probe failed." >&2
		return 1
	fi

	json_output="$(
		printf '%s\n' "$raw_output" |
			sed -n 's/^[^{]*\({.*}\)[^}]*$/\1/p' |
			tail -n 1
	)"

	if ! printf '%s\n' "$json_output" | jq -ce 'select(type == "object")'; then
		echo "WooPayments $store_name store probe did not return a valid JSON object." >&2
		return 1
	fi
}

assert_store_theme() {
	local store_name="$1"
	local store_dir="$2"
	local theme_status

	theme_status="$(
		cd "$store_dir"
		run_store_wp_json "$store_name" eval "$THEME_STATUS_CODE"
	)"
	if ! jq -e '.active_theme_exists == true' <<< "$theme_status" > /dev/null; then
		echo "WooPayments $store_name store has no renderable active theme." >&2
		return 1
	fi
}

collect_store_diagnostics() {
	local store_name="$1"
	local store_dir="$2"
	local output_dir="$DIAGNOSTICS_DIR/$store_name"

	if [[ ! -d "$store_dir" ]]; then
		echo "WooPayments $store_name store directory does not exist: $store_dir" >&2
		exit 1
	fi

	mkdir -p "$output_dir"
	(
		cd "$store_dir"
		if [[ "${E2E_WOOPAYMENTS_NATIVE_FIXTURE:-false}" == 'true' ]]; then
			printf '{"mode":"secretless-ci-fixture"}\n' > "$output_dir/wpcom-env-status.json"
			printf '{"mode":"secretless-ci-fixture"}\n' > "$output_dir/wpcom-transact-status.json"
			printf '{"mode":"secretless-ci-fixture"}\n' > "$output_dir/wpcom-store-doctor.json"
		else
			"$WPCOM_LOCAL_BIN" --json env status > "$output_dir/wpcom-env-status.json"
			"$WPCOM_LOCAL_BIN" --json transact status > "$output_dir/wpcom-transact-status.json"
			"$WPCOM_LOCAL_BIN" --json store doctor > "$output_dir/wpcom-store-doctor.json"
		fi
		if [[ "$store_name" == 'native' ]]; then
			run_store_wp "$store_name" --user=1 wc-native-payments status > "$output_dir/native-payments-status.txt"
			run_store_wp_json "$store_name" --user=1 eval "$NATIVE_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
			run_store_wp_json "$store_name" --user=1 eval "$NATIVE_ACCOUNT_REQUEST_CODE" > "$output_dir/account.json"
		else
			run_store_wp_json "$store_name" --user=1 eval "$CLIENT_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
			run_store_wp_json "$store_name" --user=1 eval "$CLIENT_ACCOUNT_REQUEST_CODE" > "$output_dir/account.json"
		fi
	)
}

runtime_blog_id() {
	local runtime_status_path="$1"

	jq -er '.wpcom_blog_id | select(type == "number" and . > 0)' "$runtime_status_path"
}

merge_callback_proof() {
	local runtime_status_path="$1"
	local callback_probe_path="$2"
	local blog_id="$3"
	local temporary_path

	mkdir -p "${TMPDIR:?TMPDIR is required}"
	temporary_path="$(mktemp "${TMPDIR%/}/woopayments-runtime-status.XXXXXX")"
	jq \
		--argjson blog_id "$blog_id" \
		'.callback_probe = {
			registered: true,
			reachable: true,
			wpcom_blog_id: $blog_id
		}' \
		"$runtime_status_path" > "$temporary_path"
	mv "$temporary_path" "$runtime_status_path"

	jq -e \
		--arg blog_id "$blog_id" \
		'.context.wpcom_blog_id == $blog_id and
		.context.callback_registered == "true" and
		.context.callback_reachable == "true" and
		.context.callback_provider_write == "false" and
		.context.callback_response_result == "success"' \
		"$callback_probe_path" > /dev/null
}

run_callback_probe() {
	local store_name="$1"
	local store_dir="$2"
	local store_url="$3"
	local blog_id="$4"
	local output_dir="$DIAGNOSTICS_DIR/$store_name"
	local callback_probe_path="$output_dir/callback-probe.json"

	if ! (
		cd "$store_dir"
		"$WPCOM_LOCAL_BIN" --json wcpay callback probe \
			--store-url "$store_url" \
			--wpcom-blog-id "$blog_id"
	) > "$callback_probe_path"; then
		echo "WooPayments callback probe failed for $store_name blog $blog_id." >&2
		return 1
	fi

	if ! jq -e \
		--arg store_url "$store_url" \
		--arg blog_id "$blog_id" \
		'.exit_code == 0 and
		(.status == "success" or .status == "warning") and
		.context.store_url == $store_url and
		.context.wpcom_blog_id == $blog_id and
		.context.callback_auth_model == "jetpack_capability" and
		.context.callback_registered == "true" and
		.context.callback_reachable == "true" and
		.context.callback_provider_write == "false" and
		.context.callback_response_result == "success"' \
		"$callback_probe_path" > /dev/null; then
		echo "WooPayments callback probe returned incomplete evidence for $store_name blog $blog_id." >&2
		return 1
	fi

	merge_callback_proof "$output_dir/runtime-status.json" "$callback_probe_path" "$blog_id"
}

assert_store_theme "$STORE_NAME" "$STORE_DIR"
collect_store_diagnostics "$STORE_NAME" "$STORE_DIR"

runtime_status_path="$DIAGNOSTICS_DIR/$STORE_NAME/runtime-status.json"
if ! jq -e \
	--arg owner "$EXPECTED_RUNTIME_OWNER" \
	--argjson native_enabled "$EXPECTED_NATIVE_ENABLED" \
	'.runtime_owner == $owner and .native_enabled == $native_enabled' \
	"$runtime_status_path" > /dev/null; then
	echo "WooPayments $STORE_NAME readiness requires runtime_owner=$EXPECTED_RUNTIME_OWNER and native_enabled=$EXPECTED_NATIVE_ENABLED." >&2
	exit 1
fi

if [[ "$PROVIDER_READINESS_REQUIRED" == '1' ]]; then
	if ! runtime_account_id="$(
		jq -er '.account_id | select(type == "string" and length > 0)' "$runtime_status_path"
	)"; then
		echo "WooPayments $STORE_NAME runtime status has no account identity." >&2
		exit 1
	fi

	account_status_path="$DIAGNOSTICS_DIR/$STORE_NAME/account.json"
	if ! jq -e \
		--arg account_id "$runtime_account_id" \
		'.status >= 200 and
		.status < 300 and
		.is_error == false and
		.account_id == $account_id and
		.test_mode == true' \
		"$account_status_path" > /dev/null; then
		echo "WooPayments $STORE_NAME account readiness is not a matching test-mode account." >&2
		exit 1
	fi

	blog_id="$(runtime_blog_id "$runtime_status_path")"
	if [[ "${E2E_WOOPAYMENTS_NATIVE_FIXTURE:-false}" != 'true' ]]; then
		run_callback_probe "$STORE_NAME" "$STORE_DIR" "$STORE_URL" "$blog_id"
	fi

	# Every provider shopper spec logs in as the shared test customer, so a
	# standing store without that account fails all of them at the login form
	# with an error about an empty email field rather than about a missing
	# user. Establish it here, idempotently: existing accounts keep their id
	# and their orders, and only the password is re-asserted so a store whose
	# customer was created by hand still matches the suite's fixture.
	(
		# The store's own directory, matching every other probe: wp-env
		# resolves from the package, not from wherever this script was called.
		cd "$STORE_DIR"
		run_store_wp "$STORE_NAME" --user=1 eval "$SHOPPER_FIXTURE_CODE"
	) > "$DIAGNOSTICS_DIR/$STORE_NAME/customer.txt"

	printf 'WooPayments shopper fixture %s for %s.\n' \
		"$(cat "$DIAGNOSTICS_DIR/$STORE_NAME/customer.txt")" \
		"$STORE_NAME"

	if [[ "${E2E_WOOPAYMENTS_NATIVE_FIXTURE:-false}" == 'true' ]]; then
		printf 'WooPayments secretless fixture readiness proved for %s blog %s without connected callback topology.\n' \
			"$STORE_NAME" \
			"$blog_id"
	else
		printf 'WooPayments callback readiness proved for %s blog %s.\n' \
			"$STORE_NAME" \
			"$blog_id"
	fi
else
	printf 'WooPayments provider-free readiness proved for %s; this invocation collects no provider-tagged tests.\n' \
		"$STORE_NAME"
fi
