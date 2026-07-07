<?php
/**
 * Deterministic refund driver for local parity probes.
 *
 * This runs inside a WordPress store via WP-CLI and exercises the normal WooCommerce
 * refund path. `wc_create_refund()` calls the active `woocommerce_payments` gateway
 * when `refund_payment` is true, so the same driver covers the reference extension
 * runtime and the Core native runtime.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$order_id = isset( $args[0] ) ? (int) $args[0] : 0;
$type     = isset( $args[1] ) ? (string) $args[1] : 'full';

if ( $order_id <= 0 ) {
	WP_CLI::error( 'A paid order id is required.' );
}

$order = wc_get_order( $order_id );
if ( ! $order instanceof WC_Order ) {
	WP_CLI::error( "Order #{$order_id} was not found." );
}

if ( 'woocommerce_payments' !== $order->get_payment_method() ) {
	WP_CLI::error( "Order #{$order_id} was not paid with WooPayments." );
}

$remaining = (float) $order->get_remaining_refund_amount();
if ( $remaining <= 0.0 ) {
	WP_CLI::error( "Order #{$order_id} has no remaining refundable amount." );
}

if ( 'partial' === $type ) {
	$amount = max( 0.01, round( $remaining / 2, 2 ) );
	if ( $amount >= $remaining ) {
		$amount = max( 0.01, round( $remaining - 0.01, 2 ) );
	}
} elseif ( 'full' === $type ) {
	$amount = $remaining;
} else {
	WP_CLI::error( "Unknown refund type {$type}; use full or partial." );
}

if ( $amount <= 0.0 || $amount > $remaining ) {
	WP_CLI::error( "Computed refund amount {$amount} is invalid for remaining amount {$remaining}." );
}

$refund = wc_create_refund(
	array(
		'order_id'       => $order_id,
		'amount'         => $amount,
		'reason'         => 'Harness deterministic ' . $type . ' refund',
		'refund_payment' => true,
	)
);

if ( is_wp_error( $refund ) ) {
	WP_CLI::error( $refund->get_error_message() );
}

if ( ! $refund instanceof WC_Order_Refund ) {
	WP_CLI::error( 'Refund creation did not return a refund object.' );
}

$refund->update_meta_data(
	'_wcpay_test_lab',
	wp_json_encode(
		array(
			'created_by' => 'harness',
			'created_at' => gmdate( 'c' ),
			'operation'  => 'refunds',
			'type'       => $type,
		)
	)
);
$refund->save();

$order            = wc_get_order( $order_id );
$persisted_refund = wc_get_order( $refund->get_id() );
$refund_amount    = $persisted_refund instanceof WC_Order_Refund
	? (string) $persisted_refund->get_amount()
	: (string) $refund->get_amount();
$provider_refund_id = $persisted_refund instanceof WC_Order_Refund
	? (string) $persisted_refund->get_meta( '_wcpay_refund_id', true )
	: (string) $refund->get_meta( '_wcpay_refund_id', true );

WP_CLI::line(
	wp_json_encode(
		array(
			'op'                 => 'refund',
			'order_id'           => $order_id,
			'refund_id'          => $refund->get_id(),
			'amount'             => $refund_amount,
			'type'               => $type,
			'order_status'       => $order instanceof WC_Order ? $order->get_status() : '',
			'remaining_refund'   => $order instanceof WC_Order ? (string) $order->get_remaining_refund_amount() : '',
			'provider_refund_id' => $provider_refund_id,
			'success'            => true,
		)
	)
);
