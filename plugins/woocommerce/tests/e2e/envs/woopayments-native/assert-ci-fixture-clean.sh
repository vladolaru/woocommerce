#!/usr/bin/env bash

set -euo pipefail

readonly STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_WP_ENV_CONFIG:?E2E_WOOPAYMENTS_WP_ENV_CONFIG is required}"
readonly DIAGNOSTICS_DIR="${E2E_WOOPAYMENTS_DIAGNOSTICS_DIR:?E2E_WOOPAYMENTS_DIAGNOSTICS_DIR is required}/native"
readonly ASSERT_CODE='$fixture = WooCommerce_WooPayments_Native_CI_Provider_Fixture::registered_instance(); if ( ! $fixture ) { fwrite( STDERR, "The WooPayments CI provider fixture is not registered.\n" ); exit( 1 ); } $fixture->restore_pre_fixture_physical_account_cache(); $request = new WP_REST_Request( "GET", "/wc-native-payments-e2e/v1/provider-fixture-audit" ); $response = rest_do_request( $request ); if ( is_wp_error( $response ) ) { fwrite( STDERR, $response->get_error_message() . "\n" ); exit( 1 ); } $audit = $response->get_data(); echo wp_json_encode( $audit ); if ( empty( $audit["clean"] ) ) { exit( 1 ); }'

mkdir -p "$DIAGNOSTICS_DIR"

(
	cd "$STORE_DIR"
	pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli wp --user=1 eval "$ASSERT_CODE"
) | tee "$DIAGNOSTICS_DIR/provider-fixture-audit.json"
