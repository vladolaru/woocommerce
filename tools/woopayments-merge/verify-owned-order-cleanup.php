<?php
/**
 * Delete only orders owned by one aggregate-verifier run token.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$results   = array();
$success   = true;
$run_token = (string) array_shift( $args );

if ( ! preg_match( '/^wcpay-verify-[a-f0-9]{32}$/', $run_token ) ) {
	$success   = false;
	$results[] = array( 'order_id' => 0, 'status' => 'invalid_run_token' );
}

$order_ids = array();
foreach ( $args as $raw_id ) {
	if ( ! is_scalar( $raw_id ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $raw_id ) ) {
		$success   = false;
		$results[] = array( 'order_id' => 0, 'status' => 'invalid_order_id' );
		continue;
	}
	$order_ids[] = absint( $raw_id );
}

if ( $success ) {
	try {
		$discovered_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact run-token recovery is a bounded local harness cleanup.
					'relation' => 'OR',
					array(
						'key'   => '_wcpay_verify_run_token',
						'value' => $run_token,
					),
					array(
						'key'     => '_wcpay_test_lab',
						'value'   => $run_token,
						'compare' => 'LIKE',
					),
				),
			)
		);
		$order_ids      = array_values( array_unique( array_merge( $order_ids, array_map( 'absint', $discovered_ids ) ) ) );
	} catch ( Throwable $throwable ) {
		$success   = false;
		$results[] = array( 'order_id' => 0, 'status' => 'discovery_failed' );
	}
}

foreach ( $order_ids as $order_id ) {
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		$results[] = array( 'order_id' => $order_id, 'status' => 'already_absent' );
		continue;
	}

	$test_lab_meta = $order->get_meta( '_wcpay_test_lab', true );
	if ( is_string( $test_lab_meta ) ) {
		$decoded_test_lab_meta = json_decode( $test_lab_meta, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded_test_lab_meta ) ) {
			$test_lab_meta = $decoded_test_lab_meta;
		}
	}

	$test_lab_owned = is_array( $test_lab_meta )
		&& 'test-lab' === ( $test_lab_meta['created_by'] ?? '' )
		&& 'charges' === ( $test_lab_meta['operation'] ?? '' )
		&& $run_token === ( $test_lab_meta['protocol'] ?? '' );
	$native_owned   = 'harness-native-charge' === $order->get_created_via()
		&& $run_token === $order->get_meta( '_wcpay_verify_run_token', true );
	if ( ! $test_lab_owned && ! $native_owned ) {
		$success   = false;
		$results[] = array( 'order_id' => $order_id, 'status' => 'ownership_mismatch' );
		continue;
	}

	$order->delete( true );
	$deleted   = ! wc_get_order( $order_id );
	$success   = $success && $deleted;
	$results[] = array( 'order_id' => $order_id, 'status' => $deleted ? 'deleted' : 'delete_failed' );
}

echo 'WCPAY_VERIFY_OWNED_ORDER_CLEANUP:' . wp_json_encode(
	array(
		'success' => $success,
		'results' => $results,
	)
) . PHP_EOL;

if ( ! $success ) {
	WP_CLI::halt( 1 );
}
