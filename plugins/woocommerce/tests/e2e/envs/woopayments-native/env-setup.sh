#!/usr/bin/env bash

set -euo pipefail

readonly CLIENT_STORE_DIR="${E2E_WOOPAYMENTS_CLIENT_STORE_DIR:?E2E_WOOPAYMENTS_CLIENT_STORE_DIR is required}"
readonly NATIVE_STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
readonly DIAGNOSTICS_DIR="${E2E_WOOPAYMENTS_DIAGNOSTICS_DIR:?E2E_WOOPAYMENTS_DIAGNOSTICS_DIR is required}"
readonly CLIENT_STORE_URL="${E2E_WOOPAYMENTS_CLIENT_STORE_URL:-http://localhost:8082}"
readonly NATIVE_STORE_URL="${E2E_WOOPAYMENTS_NATIVE_STORE_URL:-http://store8889.localhost:8889}"
readonly WPCOM_LOCAL_BIN="${E2E_WPCOM_LOCAL_BIN:-wpcom-local}"
readonly ACCOUNT_REQUEST_CODE='$request = new WP_REST_Request( "GET", "/wc/v3/payments/accounts" ); $response = rest_do_request( $request ); $data = $response->get_data(); echo wp_json_encode( array( "status" => $response->get_status(), "is_error" => $response->is_error(), "account_id" => is_array( $data ) ? (string) ( $data["account_id"] ?? "" ) : "", "test_mode" => is_array( $data ) ? (bool) ( $data["test_mode"] ?? false ) : false ) );'
readonly CLIENT_RUNTIME_STATUS_CODE='if ( ! function_exists( "is_plugin_active" ) ) { require_once ABSPATH . "wp-admin/includes/plugin.php"; } $plugin_active = is_plugin_active( "woocommerce-payments/woocommerce-payments.php" ); $account = $plugin_active && class_exists( "WC_Payments" ) ? WC_Payments::get_account_service()->get_cached_account_data() : array(); $account = is_array( $account ) ? $account : array(); $settings = get_option( "woocommerce_woocommerce_payments_settings", array() ); $settings = is_array( $settings ) ? $settings : array(); $methods = $settings["upe_enabled_payment_method_ids"] ?? array(); $methods = is_array( $methods ) ? array_values( array_map( "strval", array_filter( $methods, "is_scalar" ) ) ) : array(); echo wp_json_encode( array( "site_url" => get_site_url(), "wpcom_blog_id" => class_exists( "Jetpack_Options" ) ? (int) Jetpack_Options::get_option( "id" ) : 0, "runtime_owner" => $plugin_active ? "plugin" : "none", "native_enabled" => false, "account_id" => (string) ( $account["account_id"] ?? "" ), "account_connected" => ! empty( $account["account_id"] ), "gateway_enabled" => "yes" === ( $settings["enabled"] ?? "no" ), "test_mode" => "yes" === ( $settings["test_mode"] ?? "no" ), "enabled_payment_methods" => $methods, "last_webhook_fetch" => 0, "callback_probe" => array( "registered" => false, "reachable" => false, "wpcom_blog_id" => 0 ) ) );'
readonly NATIVE_RUNTIME_STATUS_CODE='$request = new WP_REST_Request( "GET", "/wc-native-payments-e2e/v1/status" ); $response = rest_do_request( $request ); echo wp_json_encode( $response->get_data() );'
readonly THEME_STATUS_CODE='$active = wp_get_theme(); $installed = wp_get_themes(); $fallback = ""; foreach ( array( "twentytwentyfive", "twentytwentyfour", "storefront" ) as $candidate ) { if ( isset( $installed[ $candidate ] ) ) { $fallback = $candidate; break; } } if ( "" === $fallback && ! empty( $installed ) ) { $fallback = (string) array_key_first( $installed ); } echo wp_json_encode( array( "active_theme_exists" => $active->exists(), "active_stylesheet" => get_stylesheet(), "fallback_stylesheet" => $fallback ) );'

NATIVE_CONSTANT_ORIGINAL_STATE='unchanged'
NATIVE_ACTIVATION_CHANGED='false'
NATIVE_ACTIVATION_COMMITTED='false'

run_store_wp() {
	local store_name="$1"
	shift

	if [[ "$store_name" == 'native' ]]; then
		pnpm exec wp-env run cli wp "$@"
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

ensure_store_theme() {
	local store_name="$1"
	local store_dir="$2"
	local theme_status
	local fallback

	theme_status="$(
		cd "$store_dir"
		run_store_wp_json "$store_name" eval "$THEME_STATUS_CODE"
	)"
	if jq -e '.active_theme_exists == true' <<< "$theme_status" > /dev/null; then
		return
	fi

	fallback="$(jq -er '.fallback_stylesheet | select(type == "string" and length > 0)' <<< "$theme_status")"
	(
		cd "$store_dir"
		run_store_wp "$store_name" theme activate "$fallback"
	)

	theme_status="$(
		cd "$store_dir"
		run_store_wp_json "$store_name" eval "$THEME_STATUS_CODE"
	)"
	if ! jq -e \
		--arg fallback "$fallback" \
		'.active_theme_exists == true and .active_stylesheet == $fallback' \
		<<< "$theme_status" > /dev/null; then
		echo "WooPayments $store_name store did not activate fallback theme $fallback." >&2
		return 1
	fi
}

restore_native_runtime_on_failure() {
	if [[ "$NATIVE_ACTIVATION_CHANGED" != 'true' || "$NATIVE_ACTIVATION_COMMITTED" == 'true' ]]; then
		return
	fi

	if [[ "$NATIVE_CONSTANT_ORIGINAL_STATE" == 'absent' ]]; then
		(
			cd "$NATIVE_STORE_DIR"
			run_store_wp 'native' config delete E2E_WOOPAYMENTS_NATIVE --yes
		) || echo 'Failed to remove E2E_WOOPAYMENTS_NATIVE while rolling back readiness.' >&2
		return
	fi

	(
		cd "$NATIVE_STORE_DIR"
		run_store_wp 'native' config set E2E_WOOPAYMENTS_NATIVE "$NATIVE_CONSTANT_ORIGINAL_STATE" --raw
	) || echo 'Failed to restore E2E_WOOPAYMENTS_NATIVE while rolling back readiness.' >&2
}
trap restore_native_runtime_on_failure EXIT

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
		"$WPCOM_LOCAL_BIN" --json env status > "$output_dir/wpcom-env-status.json"
		"$WPCOM_LOCAL_BIN" --json transact status > "$output_dir/wpcom-transact-status.json"
		"$WPCOM_LOCAL_BIN" --json store doctor > "$output_dir/wpcom-store-doctor.json"
		if [[ "$store_name" == 'native' ]]; then
			run_store_wp "$store_name" --user=1 wc-native-payments status > "$output_dir/native-payments-status.txt"
			run_store_wp_json "$store_name" --user=1 eval "$NATIVE_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
		else
			run_store_wp_json "$store_name" --user=1 eval "$CLIENT_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
		fi
		run_store_wp_json "$store_name" --user=1 eval "$ACCOUNT_REQUEST_CODE" > "$output_dir/account.json"
	)
}

collect_native_runtime_diagnostics() {
	local output_dir="$DIAGNOSTICS_DIR/native"

	(
		cd "$NATIVE_STORE_DIR"
		run_store_wp 'native' --user=1 wc-native-payments status > "$output_dir/native-payments-status.txt"
		run_store_wp_json 'native' --user=1 eval "$NATIVE_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
		run_store_wp_json 'native' --user=1 eval "$ACCOUNT_REQUEST_CODE" > "$output_dir/account.json"
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

enable_native_runtime_reversibly() {
	local raw_output
	local prior_value

	if raw_output="$(
		cd "$NATIVE_STORE_DIR"
		run_store_wp 'native' config get E2E_WOOPAYMENTS_NATIVE --format=json 2> /dev/null
	)"; then
		prior_value="$(
			printf '%s\n' "$raw_output" |
				sed -n -E '/^(true|false)$/p' |
				tail -n 1
		)"
		if [[ "$prior_value" != 'true' && "$prior_value" != 'false' ]]; then
			echo 'E2E_WOOPAYMENTS_NATIVE exists but is not a boolean constant.' >&2
			return 1
		fi
		NATIVE_CONSTANT_ORIGINAL_STATE="$prior_value"
		if [[ "$prior_value" == 'true' ]]; then
			return
		fi
	else
		NATIVE_CONSTANT_ORIGINAL_STATE='absent'
	fi

	(
		cd "$NATIVE_STORE_DIR"
		run_store_wp 'native' config set E2E_WOOPAYMENTS_NATIVE true --raw
	)
	NATIVE_ACTIVATION_CHANGED='true'
}

ensure_store_theme 'client' "$CLIENT_STORE_DIR"
ensure_store_theme 'native' "$NATIVE_STORE_DIR"
collect_store_diagnostics 'client' "$CLIENT_STORE_DIR"
collect_store_diagnostics 'native' "$NATIVE_STORE_DIR"

client_blog_id="$(runtime_blog_id "$DIAGNOSTICS_DIR/client/runtime-status.json")"
native_blog_id="$(runtime_blog_id "$DIAGNOSTICS_DIR/native/runtime-status.json")"

run_callback_probe 'client' "$CLIENT_STORE_DIR" "$CLIENT_STORE_URL" "$client_blog_id"
enable_native_runtime_reversibly
collect_native_runtime_diagnostics
if [[ "$(runtime_blog_id "$DIAGNOSTICS_DIR/native/runtime-status.json")" != "$native_blog_id" ]]; then
	echo 'Native WPCOM blog ID changed during reversible activation.' >&2
	exit 1
fi
run_callback_probe 'native' "$NATIVE_STORE_DIR" "$NATIVE_STORE_URL" "$native_blog_id"

NATIVE_ACTIVATION_COMMITTED='true'
printf 'WooPayments callback readiness proved for client blog %s and native blog %s.\n' \
	"$client_blog_id" \
	"$native_blog_id"
