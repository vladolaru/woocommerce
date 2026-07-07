<?php
/**
 * Local token-continuity state driver for the WooPayments merge harness.
 *
 * This file is normally executed through `wp eval-file -` by
 * token-continuity-gate.sh. It is intentionally local-only: it checks and
 * toggles the target store between the separate WooPayments plugin and native
 * WooPayments so persisted token rows can be verified across the cutover.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;

$tool_args = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$mode      = $tool_args[0] ?? '';

if ( 'preflight-plugin' === $mode ) {
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_preflight_plugin() );
	exit( 0 );
}

if ( 'cutover-native' === $mode ) {
	$token_id = isset( $tool_args[1] ) ? (int) $tool_args[1] : 0;
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_cutover_native( $token_id ) );
	exit( 0 );
}

if ( 'restore' === $mode ) {
	woopayments_merge_token_continuity_emit( woopayments_merge_token_continuity_restore_plugin() );
	exit( 0 );
}

woopayments_merge_token_continuity_emit(
	array(
		'success' => false,
		'mode'    => $mode,
		'errors'  => array( 'Unknown mode. Use preflight-plugin, cutover-native, or restore.' ),
	)
);
exit( 2 );

/**
 * Emit a single JSON line.
 *
 * @param array<string,mixed> $payload Payload.
 */
function woopayments_merge_token_continuity_emit( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * Ensure plugin helper functions are available.
 */
function woopayments_merge_token_continuity_load_plugin_helpers(): void {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
}

/**
 * Run the plugin-side preflight before the browser token save.
 *
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_preflight_plugin(): array {
	woopayments_merge_token_continuity_load_plugin_helpers();

	$errors                  = array();
	$wcpay_plugin_active     = is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' );
	$sepa_gateway_registered = woopayments_merge_token_continuity_gateway_registered( 'woocommerce_payments_sepa_debit' );
	$sepa_gateway_enabled    = woopayments_merge_token_continuity_gateway_enabled( 'woocommerce_payments_sepa_debit' );
	$woocommerce_active      = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	$subscriptions_available = class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscription' );

	if ( ! $woocommerce_active ) {
		$errors[] = 'WooCommerce is not active.';
	}
	if ( ! $wcpay_plugin_active ) {
		$errors[] = 'The separate WooPayments plugin must be active before saving the source SEPA token.';
	}
	if ( ! $sepa_gateway_registered ) {
		$errors[] = 'The WooPayments SEPA gateway is not registered on the plugin-side target store.';
	}
	if ( ! $sepa_gateway_enabled ) {
		$errors[] = 'The WooPayments SEPA gateway is not enabled on the plugin-side target store.';
	}
	if ( ! $subscriptions_available ) {
		$errors[] = 'WC Subscriptions is unavailable; renewal validation cannot run.';
	}

	return array(
		'success'                 => empty( $errors ),
		'mode'                    => 'preflight-plugin',
		'errors'                  => $errors,
		'wcpay_plugin_active'     => $wcpay_plugin_active,
		'sepa_gateway_registered' => $sepa_gateway_registered,
		'sepa_gateway_enabled'    => $sepa_gateway_enabled,
		'subscriptions_available' => $subscriptions_available,
	);
}

/**
 * Cut the target store over to native WooPayments.
 *
 * @param int $token_id Saved token ID.
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_cutover_native( int $token_id ): array {
	woopayments_merge_token_continuity_load_plugin_helpers();

	$errors            = array();
	$plugin_file       = 'woocommerce-payments/woocommerce-payments.php';
	$was_plugin_active = is_plugin_active( $plugin_file );

	if ( $token_id <= 0 ) {
		$errors[] = 'Saved token ID must be positive before cutover.';
	}

	if ( $was_plugin_active ) {
		deactivate_plugins( $plugin_file, true );
	}

	$is_plugin_active = is_plugin_active( $plugin_file );
	if ( $is_plugin_active ) {
		$errors[] = 'The separate WooPayments plugin is still active after cutover.';
	}
	if ( ! class_exists( NativeWooPaymentsGateway::class ) ) {
		$errors[] = 'Native WooPayments gateway class is unavailable after cutover.';
	}
	if ( ! class_exists( WooPaymentsSepaToken::class ) ) {
		$errors[] = 'Native WooPayments SEPA token class is unavailable after cutover.';
	}

	return array(
		'success'                           => empty( $errors ),
		'mode'                              => 'cutover-native',
		'errors'                            => $errors,
		'token_id'                          => $token_id,
		'was_plugin_active'                 => $was_plugin_active,
		'wcpay_plugin_active'               => $is_plugin_active,
		'native_gateway_class_available'    => class_exists( NativeWooPaymentsGateway::class ),
		'native_sepa_token_class_available' => class_exists( WooPaymentsSepaToken::class ),
	);
}

/**
 * Restore the separate WooPayments plugin after the gate.
 *
 * @return array<string,mixed>
 */
function woopayments_merge_token_continuity_restore_plugin(): array {
	woopayments_merge_token_continuity_load_plugin_helpers();

	$errors      = array();
	$plugin_file = 'woocommerce-payments/woocommerce-payments.php';

	if ( ! is_plugin_active( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/woocommerce-payments/woocommerce-payments.php' ) ) {
		$result = activate_plugin( $plugin_file, '', false, true );
		if ( is_wp_error( $result ) ) {
			$errors[] = 'Could not restore WooPayments plugin: ' . $result->get_error_message();
		}
	}

	return array(
		'success'             => empty( $errors ),
		'mode'                => 'restore',
		'errors'              => $errors,
		'wcpay_plugin_active' => is_plugin_active( $plugin_file ),
	);
}

/**
 * Check if a payment gateway is registered.
 *
 * @param string $gateway_id Gateway ID.
 * @return bool
 */
function woopayments_merge_token_continuity_gateway_registered( string $gateway_id ): bool {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		return false;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways[ $gateway_id ] );
}

/**
 * Check if a gateway is enabled.
 *
 * @param string $gateway_id Gateway ID.
 * @return bool
 */
function woopayments_merge_token_continuity_gateway_enabled( string $gateway_id ): bool {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		return false;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways[ $gateway_id ] ?? null;
	return is_object( $gateway ) && method_exists( $gateway, 'is_available' )
		? (bool) $gateway->is_available()
		: false;
}
