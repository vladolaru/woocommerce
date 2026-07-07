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

$order = wc_get_order( $order_id );
if ( ! $order instanceof WC_Order ) {
	WP_CLI::error( "Order #{$order_id} was not found." );
}

if ( 'woocommerce_payments' !== $order->get_payment_method() ) {
	WP_CLI::error( "Order #{$order_id} was not paid with WooPayments." );
}

$intention_status = (string) $order->get_meta( '_intention_status', true );
if ( 'requires_capture' !== $intention_status && 'on-hold' !== $order->get_status() ) {
	WP_CLI::error( "Order #{$order_id} does not look like an authorized WooPayments order." );
}

$success = false;
$result  = array();
$use_native_runtime = false;

if ( class_exists( NativePaymentsRuntimeArbiter::class ) ) {
	$container = wc_get_container();
	$arbiter   = $container->get( NativePaymentsRuntimeArbiter::class );
	$use_native_runtime = NativePaymentsRuntimeArbiter::OWNER_NATIVE === $arbiter->get_runtime_owner();
}

if ( $use_native_runtime ) {
	$service  = $container->get( PaymentProcessingService::class );
	$provider = $container->get( WooPaymentsProvider::class );
	$outcome  = $service->capture(
		PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID ),
		$provider
	);
	$success  = PaymentOutcome::STATUS_COMPLETED === $outcome->get_status();
	$result   = array(
		'status'        => $outcome->get_status(),
		'id'            => $outcome->get_provider_payment_id(),
		'error_message' => (string) ( $outcome->get_data()['error_message'] ?? '' ),
		'error_code'    => (string) ( $outcome->get_data()['error_code'] ?? '' ),
	);
} else {
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways['woocommerce_payments'] ?? null;

	if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'capture_charge' ) ) ) {
		WP_CLI::error( 'WooPayments gateway capture is not available.' );
	}

	$result  = $gateway->capture_charge( $order );
	$success = is_array( $result ) && 'succeeded' === (string) ( $result['status'] ?? '' );
}

if ( empty( $result ) ) {
	WP_CLI::error( 'No WooPayments capture runtime was available.' );
}

$order = wc_get_order( $order_id );

$payload = array(
	'op'               => 'capture',
	'order_id'         => $order_id,
	'intent_id'        => $order instanceof WC_Order ? (string) $order->get_meta( '_intent_id', true ) : '',
	'charge_id'        => $order instanceof WC_Order ? (string) $order->get_meta( '_charge_id', true ) : '',
	'status'           => $order instanceof WC_Order ? $order->get_status() : '',
	'intention_status' => $order instanceof WC_Order ? (string) $order->get_meta( '_intention_status', true ) : '',
	'success'          => $success,
	'provider_status'  => is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '',
);

if ( ! $success ) {
	$payload['error_message'] = is_array( $result ) ? (string) ( $result['message'] ?? $result['error_message'] ?? '' ) : '';
	$payload['error_code']    = is_array( $result ) ? (string) ( $result['error_code'] ?? '' ) : '';
	WP_CLI::error( wp_json_encode( $payload ) );
}

WP_CLI::line( wp_json_encode( $payload ) );
