<?php
/**
 * Deterministic capture driver for local parity probes.
 *
 * This runs inside a WordPress store via WP-CLI and captures an existing WooPayments authorization. The native runtime path uses the Core payment service; the reference runtime path uses the WooPayments gateway capture method.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;

$order_id = isset( $args[0] ) ? (int) $args[0] : 0;

if ( $order_id <= 0 ) {
	WP_CLI::error( 'An authorized order id is required.' );
}

$wc_order = wc_get_order( $order_id );
if ( ! $wc_order instanceof WC_Order ) {
	WP_CLI::error( "Order #{$order_id} was not found." );
}

if ( 'woocommerce_payments' !== $wc_order->get_payment_method() ) {
	WP_CLI::error( "Order #{$order_id} was not paid with WooPayments." );
}

$intention_status = (string) $wc_order->get_meta( '_intention_status', true );
if ( 'requires_capture' !== $intention_status && 'on-hold' !== $wc_order->get_status() ) {
	WP_CLI::error( "Order #{$order_id} does not look like an authorized WooPayments order." );
}

$success            = false;
$result             = array();
$use_native_runtime = false;
$transport_kind     = 'plugin_http';

if ( class_exists( NativePaymentsRuntimeArbiter::class ) ) {
	$container          = wc_get_container();
	$arbiter            = $container->get( NativePaymentsRuntimeArbiter::class );
	$use_native_runtime = NativePaymentsRuntimeArbiter::OWNER_NATIVE === $arbiter->get_runtime_owner();
}

if ( $use_native_runtime ) {
	$transport_kind = 'native_outcome';
	$service        = $container->get( PaymentProcessingService::class );
	$provider       = $container->get( WooPaymentsProvider::class );
	$outcome        = $service->capture(
		PaymentContext::for_capture( $wc_order, OrderPaymentStore::GATEWAY_ID ),
		$provider
	);
	$outcome_data   = $outcome->get_data();
	$success        = PaymentOutcome::STATUS_COMPLETED === $outcome->get_status();
	$result         = array(
		'status'        => $outcome->get_status(),
		'id'            => $outcome->get_provider_payment_id(),
		'error_message' => (string) ( $outcome_data['error_message'] ?? '' ),
		'error_code'    => (string) ( $outcome_data['error_code'] ?? '' ),
		'http_code'     => (int) ( $outcome_data['http_code'] ?? 0 ),
	);
} else {
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways['woocommerce_payments'] ?? null;

	if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'capture_charge' ) ) ) {
		WP_CLI::error( 'WooPayments gateway capture is not available.' );
	}

	$result  = $gateway->capture_charge( $wc_order );
	$success = is_array( $result ) && 'succeeded' === (string) ( $result['status'] ?? '' );
}

if ( empty( $result ) ) {
	WP_CLI::error( 'No WooPayments capture runtime was available.' );
}

$wc_order = wc_get_order( $order_id );

$payload = array(
	'op'               => 'capture',
	'order_id'         => $order_id,
	'intent_id'        => $wc_order instanceof WC_Order ? (string) $wc_order->get_meta( '_intent_id', true ) : '',
	'charge_id'        => $wc_order instanceof WC_Order ? (string) $wc_order->get_meta( '_charge_id', true ) : '',
	'status'           => $wc_order instanceof WC_Order ? $wc_order->get_status() : '',
	'intention_status' => $wc_order instanceof WC_Order ? (string) $wc_order->get_meta( '_intention_status', true ) : '',
	'success'          => $success,
	'transport_kind'   => $transport_kind,
	'provider_status'  => is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '',
	'http_code'        => is_array( $result ) ? (int) ( $result['http_code'] ?? 0 ) : 0,
);

if ( ! $success ) {
	$payload['error_message'] = is_array( $result ) ? (string) ( $result['message'] ?? $result['error_message'] ?? '' ) : '';
	$payload['error_code']    = is_array( $result ) ? (string) ( $result['error_code'] ?? '' ) : '';
	WP_CLI::error( wp_json_encode( $payload ) );
}

WP_CLI::line( wp_json_encode( $payload ) );
