#!/usr/bin/env bash

set -euo pipefail

readonly PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
readonly WP_ENV_CONFIG="${E2E_WOOPAYMENTS_WP_ENV_CONFIG:?E2E_WOOPAYMENTS_WP_ENV_CONFIG is required}"
readonly -a WP_ENV=( pnpm exec wp-env --config "$WP_ENV_CONFIG" run cli )
readonly TRANSPORT_ASSERT_CODE='$api_base = defined( "JETPACK__API_BASE" ) ? JETPACK__API_BASE : Automattic\Jetpack\Connection\Utils::DEFAULT_JETPACK__API_BASE; $wpcom_json_api_base = defined( "JETPACK__WPCOM_JSON_API_BASE" ) ? JETPACK__WPCOM_JSON_API_BASE : Automattic\Jetpack\Connection\Utils::DEFAULT_JETPACK__WPCOM_JSON_API_BASE; if ( "https://jetpack.wordpress.com/jetpack." !== $api_base || "https://public-api.wordpress.com" !== $wpcom_json_api_base ) { fwrite( STDERR, "Readonly WooPayments fixture requires the canonical public Jetpack transport.\n" ); exit( 1 ); }'
readonly IDENTITY_ASSERT_CODE='$manager = new Automattic\Jetpack\Connection\Manager(); $identity = array( "site_connected" => $manager->is_connected(), "owner_present" => $manager->has_connected_owner(), "owner_id" => $manager->get_connection_owner_id(), "user_connected" => $manager->is_user_connected( 1 ) ); echo wp_json_encode( $identity ); if ( true !== $identity["site_connected"] || true !== $identity["owner_present"] || 1 !== $identity["owner_id"] || true !== $identity["user_connected"] ) { fwrite( STDERR, "Dummy Jetpack identity did not satisfy the WooPayments connection contract.\n" ); exit( 1 ); }'
readonly AUTHORIZATION_CACHE_CLEAR_CODE='delete_option( "wcpay_authorization_summary_cache" ); delete_option( "wcpay_test_authorization_summary_cache" ); wp_cache_delete( "wcpay_authorization_summary_cache", "options" ); wp_cache_delete( "wcpay_test_authorization_summary_cache", "options" );'
readonly RECONCILE_FIXTURE_STATE_CODE='if ( class_exists( "WooCommerce_WooPayments_Native_CI_Provider_Fixture" ) ) { $fixture = WooCommerce_WooPayments_Native_CI_Provider_Fixture::registered_instance(); if ( $fixture ) { $fixture->reconcile_fixture_state_before_reinstall(); } }'
readonly ACCOUNT_CACHE_PREPARE_CODE='$fixture = WooCommerce_WooPayments_Native_CI_Provider_Fixture::registered_instance(); if ( ! $fixture ) { fwrite( STDERR, "The WooPayments CI provider fixture is not registered.\n" ); exit( 1 ); } $account = $fixture->prepare_physical_account_cache_for_run( static function () use ( $fixture ): array { $audit = $fixture->audit(); if ( empty( $audit["fraud_services_transient"]["run_isolated"] ) || empty( $audit["jetpack_identity"]["run_isolated"] ) ) { fwrite( STDERR, "The WooPayments CI provider fixture did not isolate physical state before refreshing account data.\n" ); exit( 1 ); } return wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class )->refresh_account_data(); } ); echo wp_json_encode( array( "account_id" => $account["account_id"] ?? "" ) );'

install_file() {
	"${WP_ENV[@]}" sh -c 'source="$1"; target="$2"; if [ "$source" -ef "$target" ] || cmp -s "$source" "$target"; then exit 0; fi; cp -- "$source" "$target"' sh "$1" "$2"
}

cd "$PLUGIN_ROOT"
readonly source_dir='wp-content/plugins/woocommerce/tests/e2e/envs/woopayments-native'
"${WP_ENV[@]}" wp eval "$TRANSPORT_ASSERT_CODE"
"${WP_ENV[@]}" mkdir -p wp-content/mu-plugins
install_file "$source_dir/ci-provider-fixture.php" wp-content/mu-plugins/ci-provider-fixture.php
install_file "$source_dir/stripe-messaging-adapter.js" wp-content/mu-plugins/stripe-messaging-adapter.js
install_file "$source_dir/../../test-plugins/woopayments-native-runtime/woopayments-native-runtime.php" wp-content/mu-plugins/woopayments-native-runtime.php
"${WP_ENV[@]}" wp eval "$RECONCILE_FIXTURE_STATE_CODE"
"${WP_ENV[@]}" wp config set E2E_WOOPAYMENTS_NATIVE true --raw
"${WP_ENV[@]}" wp config set E2E_WOOPAYMENTS_NATIVE_FIXTURE true --raw
"${WP_ENV[@]}" wp option delete e2e_woopayments_native_provider_state
"${WP_ENV[@]}" wp option delete e2e_woopayments_native_request_log
"${WP_ENV[@]}" wp option delete e2e_woopayments_native_failure_log
"${WP_ENV[@]}" wp eval "$AUTHORIZATION_CACHE_CLEAR_CODE"
"${WP_ENV[@]}" wp --user=1 eval "$IDENTITY_ASSERT_CODE"
"${WP_ENV[@]}" wp --user=1 eval "$ACCOUNT_CACHE_PREPARE_CODE"
