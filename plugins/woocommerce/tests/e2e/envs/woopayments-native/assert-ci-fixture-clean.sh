#!/usr/bin/env bash

set -euo pipefail

readonly STORE_DIR="${E2E_WOOPAYMENTS_NATIVE_STORE_DIR:?E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required}"
readonly ASSERT_CODE='$requests = get_option( "e2e_woopayments_native_request_log", array() ); $failures = get_option( "e2e_woopayments_native_failure_log", array() ); if ( ! is_array( $requests ) || count( $requests ) === 0 ) { fwrite( STDERR, "No provider fixture requests were recorded.\n" ); exit( 1 ); } if ( ! is_array( $failures ) || count( $failures ) !== 0 ) { fwrite( STDERR, wp_json_encode( $failures ) . "\n" ); exit( 1 ); } echo wp_json_encode( array( "requests" => count( $requests ), "failures" => 0 ) );'

(
	cd "$STORE_DIR"
	pnpm exec wp-env --config=.wp-env.e2e.json run cli wp --user=1 eval "$ASSERT_CODE"
)
