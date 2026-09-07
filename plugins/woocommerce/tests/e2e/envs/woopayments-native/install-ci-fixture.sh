#!/usr/bin/env bash

set -euo pipefail

readonly PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_WP_ENV_CONFIG:?E2E_WOOPAYMENTS_WP_ENV_CONFIG is required}"
readonly -a WP_ENV=( pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli )
readonly IDENTITY_ASSERT_CODE='$manager = new Automattic\Jetpack\Connection\Manager(); $identity = array( "site_connected" => $manager->is_connected(), "owner_present" => $manager->has_connected_owner(), "owner_id" => $manager->get_connection_owner_id(), "user_connected" => $manager->is_user_connected( 1 ) ); echo wp_json_encode( $identity ); if ( true !== $identity["site_connected"] || true !== $identity["owner_present"] || 1 !== $identity["owner_id"] || true !== $identity["user_connected"] ) { fwrite( STDERR, "Dummy Jetpack identity did not satisfy the WooPayments connection contract.\n" ); exit( 1 ); }'
readonly AUTHORIZATION_CACHE_CLEAR_CODE='delete_option( "wcpay_authorization_summary_cache" ); delete_option( "wcpay_test_authorization_summary_cache" ); wp_cache_delete( "wcpay_authorization_summary_cache", "options" ); wp_cache_delete( "wcpay_test_authorization_summary_cache", "options" );'

cd "$PLUGIN_ROOT"
readonly source_dir='wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native'
"${WP_ENV[@]}" mkdir -p wp-content/mu-plugins
"${WP_ENV[@]}" cp "$source_dir/ci-provider-fixture.php" wp-content/mu-plugins/ci-provider-fixture.php
"${WP_ENV[@]}" cp "$source_dir/stripe-messaging-adapter.js" wp-content/mu-plugins/stripe-messaging-adapter.js
"${WP_ENV[@]}" cp "$source_dir/../../test-plugins/woopayments-native-runtime/woopayments-native-runtime.php" wp-content/mu-plugins/woopayments-native-runtime.php
"${WP_ENV[@]}" wp config set E2E_WOOPAYMENTS_NATIVE true --raw
"${WP_ENV[@]}" wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE true --raw
"${WP_ENV[@]}" wp option delete e2e_woopayments_native_provider_state
"${WP_ENV[@]}" wp option delete e2e_woopayments_native_request_log
"${WP_ENV[@]}" wp option delete e2e_woopayments_native_failure_log
"${WP_ENV[@]}" wp eval "$AUTHORIZATION_CACHE_CLEAR_CODE"
"${WP_ENV[@]}" wp --user=1 eval "$IDENTITY_ASSERT_CODE"
