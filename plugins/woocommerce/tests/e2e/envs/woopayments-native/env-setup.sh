#!/usr/bin/env bash

set -euo pipefail

readonly CLIENT_STORE_DIR="${E2E_WOOPAYMENTS_CLIENT_STORE_DIR:?E2E_WOOPAYMENTS_CLIENT_STORE_DIR is required}"
readonly NATIVE_STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
readonly DIAGNOSTICS_DIR="${E2E_WOOPAYMENTS_DIAGNOSTICS_DIR:?E2E_WOOPAYMENTS_DIAGNOSTICS_DIR is required}"
readonly ACCOUNT_REQUEST_CODE='$request = new WP_REST_Request( "GET", "/wc/v3/payments/accounts" ); $response = rest_do_request( $request ); $data = $response->get_data(); echo wp_json_encode( array( "status" => $response->get_status(), "is_error" => $response->is_error(), "account_id" => is_array( $data ) ? (string) ( $data["account_id"] ?? "" ) : "", "test_mode" => is_array( $data ) ? (bool) ( $data["test_mode"] ?? false ) : false ) );'
readonly CLIENT_RUNTIME_STATUS_CODE='if ( ! function_exists( "is_plugin_active" ) ) { require_once ABSPATH . "wp-admin/includes/plugin.php"; } $plugin_active = is_plugin_active( "woocommerce-payments/woocommerce-payments.php" ); $account = $plugin_active && class_exists( "WC_Payments" ) ? WC_Payments::get_account_service()->get_cached_account_data() : array(); $account = is_array( $account ) ? $account : array(); $settings = get_option( "woocommerce_woocommerce_payments_settings", array() ); $settings = is_array( $settings ) ? $settings : array(); $methods = $settings["upe_enabled_payment_method_ids"] ?? array(); $methods = is_array( $methods ) ? array_values( array_map( "strval", array_filter( $methods, "is_scalar" ) ) ) : array(); echo wp_json_encode( array( "site_url" => get_site_url(), "wpcom_blog_id" => class_exists( "Jetpack_Options" ) ? (int) Jetpack_Options::get_option( "id" ) : 0, "runtime_owner" => $plugin_active ? "plugin" : "none", "native_enabled" => false, "account_id" => (string) ( $account["account_id"] ?? "" ), "account_connected" => ! empty( $account["account_id"] ), "gateway_enabled" => "yes" === ( $settings["enabled"] ?? "no" ), "test_mode" => "yes" === ( $settings["test_mode"] ?? "no" ), "enabled_payment_methods" => $methods, "last_webhook_fetch" => 0, "callback_probe" => array( "registered" => false, "reachable" => false, "wpcom_blog_id" => 0 ) ) );'
readonly NATIVE_RUNTIME_STATUS_CODE='$request = new WP_REST_Request( "GET", "/wc-native-payments-e2e/v1/status" ); $response = rest_do_request( $request ); echo wp_json_encode( $response->get_data() );'

run_store_wp() {
	local store_name="$1"
	shift

	if [[ "$store_name" == 'native' ]]; then
		pnpm exec wp-env run cli wp "$@"
		return
	fi

	pnpm wp "$@"
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
		wpcom-local --json env status > "$output_dir/wpcom-env-status.json"
		wpcom-local --json transact status > "$output_dir/wpcom-transact-status.json"
		wpcom-local --json store doctor > "$output_dir/wpcom-store-doctor.json"
		if [[ "$store_name" == 'native' ]]; then
			run_store_wp "$store_name" --user=1 wc-native-payments status > "$output_dir/native-payments-status.txt"
			run_store_wp_json "$store_name" --user=1 eval "$NATIVE_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
		else
			run_store_wp_json "$store_name" --user=1 eval "$CLIENT_RUNTIME_STATUS_CODE" > "$output_dir/runtime-status.json"
		fi
		run_store_wp_json "$store_name" --user=1 eval "$ACCOUNT_REQUEST_CODE" > "$output_dir/account.json"
	)
}

configure_native_runtime_after_approved_probe() {
	(
		cd "$NATIVE_STORE_DIR"
		pnpm exec wp-env run cli wp config set E2E_WOOPAYMENTS_NATIVE true --raw
		pnpm exec wp-env run cli wp config get E2E_WOOPAYMENTS_NATIVE
	)
}

collect_store_diagnostics 'client' "$CLIENT_STORE_DIR"
collect_store_diagnostics 'native' "$NATIVE_STORE_DIR"

cat >&2 <<'EOF'
WooPayments native E2E readiness stopped before any mutation or checkout:
the owner-approved signed callback/registration probe interface is absent.
No timestamp, synthetic flag, or payment can substitute for a registered,
reachable inbound callback proof tied to this store's exact WPCOM blog ID.
The native PHP constant command remains gated in
configure_native_runtime_after_approved_probe and was not executed.
EOF
exit 1
