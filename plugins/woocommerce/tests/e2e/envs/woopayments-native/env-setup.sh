#!/usr/bin/env bash

set -euo pipefail

readonly CLIENT_STORE_DIR="${E2E_WOOPAYMENTS_CLIENT_STORE_DIR:?E2E_WOOPAYMENTS_CLIENT_STORE_DIR is required}"
readonly NATIVE_STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
readonly DIAGNOSTICS_DIR="${E2E_WOOPAYMENTS_DIAGNOSTICS_DIR:?E2E_WOOPAYMENTS_DIAGNOSTICS_DIR is required}"
readonly ACCOUNT_REQUEST_CODE='$request = new WP_REST_Request( "GET", "/wc/v3/payments/accounts" ); $response = rest_do_request( $request ); if ( $response->is_error() ) { fwrite( STDERR, wp_json_encode( $response->as_error() ) ); exit( 1 ); } echo wp_json_encode( $response->get_data() );'

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
		pnpm wp --user=1 wc-native-payments status > "$output_dir/native-payments-status.txt"
		pnpm wp --user=1 eval "$ACCOUNT_REQUEST_CODE" > "$output_dir/account.json"
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
