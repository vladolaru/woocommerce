<?php
/**
 * Inspect a local WooPayments order fixture for perf probes.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

if ( $order_id <= 0 ) {
	WP_CLI::error( 'Order id is required.' );
}

$gateway_id = class_exists( '\Automattic\WooCommerce\Internal\Payments\OrderPaymentStore' )
	? \Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::GATEWAY_ID
	: 'woocommerce_payments';

$order = wc_get_order( $order_id );

if ( ! $order instanceof WC_Order ) {
	WP_CLI::line(
		wp_json_encode(
			array(
				'order_id' => $order_id,
				'exists'   => false,
			)
		)
	);
	return;
}

WP_CLI::line(
	wp_json_encode(
		array(
			'order_id'                 => $order->get_id(),
			'exists'                   => true,
			'gateway_id'               => $gateway_id,
			'payment_method'           => $order->get_payment_method(),
			'status'                   => $order->get_status(),
			'needs_payment'            => $order->needs_payment(),
			'remaining_refund_amount'  => (string) $order->get_remaining_refund_amount(),
			'transaction_id'           => (string) $order->get_transaction_id(),
			'total'                    => (string) $order->get_total(),
			'refunded_amount'          => (string) $order->get_total_refunded(),
			'intention_status'         => (string) $order->get_meta( '_intention_status', true ),
			'intent_id'                => (string) $order->get_meta( '_intent_id', true ),
			'charge_id'                => (string) $order->get_meta( '_charge_id', true ),
		)
	)
);
