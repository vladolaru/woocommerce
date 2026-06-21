<?php
/**
 * Deterministic WooPayments plugin checkout-charge driver for local parity probes.
 *
 * This runs inside a target WordPress store through WP-CLI while the WooPayments
 * plugin owns the `woocommerce_payments` gateway.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$sku            = $args[0] ?? 'test-lab-beaker-001';
$quantity       = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 2;
$payment_method = $args[2] ?? 'pm_card_visa';
$gateway_id     = class_exists( '\Automattic\WooCommerce\Internal\Payments\OrderPaymentStore' )
	? \Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::GATEWAY_ID
	: 'woocommerce_payments';

if ( ! is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' ) ) {
	WP_CLI::error( 'WooPayments plugin is not active.' );
}

$product_id = wc_get_product_id_by_sku( $sku );
if ( ! $product_id ) {
	WP_CLI::error( "Could not find product SKU {$sku}." );
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	WP_CLI::error( "Could not load product SKU {$sku}." );
}

if ( 'test-lab-beaker-001' === $sku ) {
	$product->set_regular_price( '25.00' );
	$product->set_sale_price( '' );
	$product->set_price( '25.00' );
	$product->save();
}

$order = wc_create_order();
$order->add_product( $product, $quantity );
$order->set_created_via( 'harness-plugin-checkout-charge' );
$order->set_billing_first_name( 'Harness' );
$order->set_billing_last_name( 'Plugin' );
$order->set_billing_email( 'plugin.harness@example.com' );
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
	WP_CLI::error( 'WooPayments plugin gateway is not available.' );
}

$result = $gateway->process_payment( $order->get_id() );
$order  = wc_get_order( $order->get_id() );

if ( ! is_array( $result ) || 'success' !== ( $result['result'] ?? '' ) ) {
	WP_CLI::error(
		wp_json_encode(
			array(
				'error'    => 'plugin_checkout_charge_failed',
				'order_id' => $order ? $order->get_id() : 0,
				'result'   => $result,
			)
		)
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'op'             => 'plugin_checkout_charge',
			'order_id'       => $order ? $order->get_id() : 0,
			'charge_id'      => $order ? (string) $order->get_meta( '_charge_id', true ) : '',
			'transaction_id' => $order ? $order->get_transaction_id() : '',
			'status'         => $order ? $order->get_status() : '',
			'result'         => $result['result'] ?? '',
			'redirect'       => $result['redirect'] ?? '',
		)
	)
);
