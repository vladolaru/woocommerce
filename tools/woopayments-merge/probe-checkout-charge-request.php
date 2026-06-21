<?php
/**
 * Capture WooPayments checkout charge request params for local parity debugging.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$sku            = $args[0] ?? 'test-lab-beaker-001';
$quantity       = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 2;
$payment_method = $args[2] ?? 'pm_card_visa';
$label          = $args[3] ?? 'probe';
$gateway_id     = class_exists( '\Automattic\WooCommerce\Internal\Payments\OrderPaymentStore' )
	? \Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::GATEWAY_ID
	: 'woocommerce_payments';
$captured       = array();

add_filter(
	'wcpay_api_request_params',
	static function ( $params, $api = '', $method = '' ) use ( &$captured ) {
		if ( 'intentions' === $api && 'POST' === strtoupper( (string) $method ) ) {
			$captured[] = array(
				'api'    => $api,
				'method' => $method,
				'params' => $params,
			);
		}

		return $params;
	},
	PHP_INT_MAX,
	3
);

$product_id = wc_get_product_id_by_sku( $sku );
if ( ! $product_id ) {
	WP_CLI::error( "Could not find product SKU {$sku}." );
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	WP_CLI::error( "Could not load product SKU {$sku}." );
}

$order = wc_create_order();
$order->add_product( $product, $quantity );
$order->set_created_via( 'harness-request-probe-' . sanitize_key( $label ) );
$order->set_billing_first_name( 'Harness' );
$order->set_billing_last_name( 'Probe' );
$order->set_billing_email( 'probe.harness@example.com' );
$order->set_billing_address_1( '123 Test Street' );
$order->set_billing_city( 'San Francisco' );
$order->set_billing_state( 'CA' );
$order->set_billing_postcode( '94103' );
$order->set_billing_country( 'US' );
$order->set_payment_method( $gateway_id );
$order->calculate_totals();
$order->save();

$_POST['wcpay-payment-method'] = $payment_method;

$gateways = WC()->payment_gateways()->payment_gateways();
$gateway  = $gateways[ $gateway_id ] ?? null;

if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_payment' ) ) ) {
	WP_CLI::error( 'WooPayments gateway is not available.' );
}

$result = $gateway->process_payment( $order->get_id() );
$order  = wc_get_order( $order->get_id() );

WP_CLI::line(
	wp_json_encode(
		array(
			'label'          => $label,
			'order_id'       => $order ? $order->get_id() : 0,
			'charge_id'      => $order ? (string) $order->get_meta( '_charge_id', true ) : '',
			'transaction_id' => $order ? $order->get_transaction_id() : '',
			'status'         => $order ? $order->get_status() : '',
			'result'         => is_array( $result ) ? ( $result['result'] ?? '' ) : '',
			'fee'            => $order ? $order->get_meta( '_wcpay_transaction_fee', true ) : '',
			'net'            => $order ? $order->get_meta( '_wcpay_net', true ) : '',
			'captured'       => $captured,
		)
	)
);
