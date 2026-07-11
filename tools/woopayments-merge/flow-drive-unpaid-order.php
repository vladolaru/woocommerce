<?php
/**
 * Deterministic unpaid WooPayments order fixture for local perf probes.
 *
 * This runs inside a local WordPress store via WP-CLI. It creates a normal
 * pending WooCommerce order assigned to the WooPayments gateway, without
 * hand-editing payment provider meta.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$sku      = $args[0] ?? 'test-lab-beaker-001';
$quantity = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 2;

$gateway_id = class_exists( '\Automattic\WooCommerce\Internal\Payments\OrderPaymentStore' )
	? \Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::GATEWAY_ID
	: 'woocommerce_payments';

$product_id = wc_get_product_id_by_sku( $sku );
if ( ! $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Harness Test Product' );
	$product->set_sku( $sku );
	$product->set_regular_price( '25.00' );
	$product->set_price( '25.00' );
	$product->set_catalog_visibility( 'hidden' );
	$product->save();

	$product_id = $product->get_id();
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

$gateways = WC()->payment_gateways()->payment_gateways();
$gateway  = $gateways[ $gateway_id ] ?? null;

if ( ! is_object( $gateway ) ) {
	WP_CLI::error( "WooPayments gateway {$gateway_id} is not available." );
}

$order = wc_create_order();
$order->add_product( $product, $quantity );
$order->set_created_via( 'harness-unpaid-order' );
$order->set_billing_first_name( 'Harness' );
$order->set_billing_last_name( 'Unpaid' );
$order->set_billing_email( 'unpaid.harness@example.com' );
$order->set_billing_address_1( '123 Test Street' );
$order->set_billing_city( 'San Francisco' );
$order->set_billing_state( 'CA' );
$order->set_billing_postcode( '94103' );
$order->set_billing_country( 'US' );
$order->set_payment_method( $gateway_id );
$order->set_payment_method_title( is_string( $gateway->title ?? null ) ? $gateway->title : 'WooPayments' );
$order->calculate_totals();
$order->save();

if ( ! $order->needs_payment() ) {
	WP_CLI::error( "Created order #{$order->get_id()} does not need payment." );
}

WP_CLI::line(
	wp_json_encode(
		array(
			'op'             => 'unpaid_order',
			'order_id'       => $order->get_id(),
			'payment_method' => $order->get_payment_method(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
		)
	)
);
