<?php

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;

$expected_routes = array(
	'/wc/v3/payments/connection_tokens',
	'/wc/v3/payments/orders/(?P<order_id>\w+)/capture_terminal_payment',
	'/wc/v3/payments/orders/(?P<order_id>\w+)/prepare_terminal_payment',
	'/wc/v3/payments/orders/(?P<order_id>\w+)/create_terminal_intent',
	'/wc/v3/payments/orders/(?P<order_id>\d+)/create_customer',
	'/wc/v3/payments/readers',
	'/wc/v3/payments/readers/charges/(?P<transaction_id>\w+)',
	'/wc/v3/payments/readers/receipts/preview',
	'/wc/v3/payments/readers/receipts/(?P<payment_intent_id>\w+)',
	'/wc/v3/payments/terminal/locations/store',
	'/wc/v3/payments/terminal/locations',
	'/wc/v3/payments/terminal/locations/(?P<location_id>\w+)',
);

$api_requests = array();

$normalize_route = static function ( string $route ): string {
	$normalized = trim( str_replace( '\\/', '/', $route ) );
	$normalized = (string) preg_replace( '/\(\?P<([^>]+)>[^)]*\)/', '{$1}', $normalized );
	$normalized = (string) preg_replace( '#/+#', '/', $normalized );

	if ( strlen( $normalized ) > 1 ) {
		$normalized = rtrim( $normalized, '/' );
	}

	return $normalized;
};

$response = static function ( array $data, int $code = 200 ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $data ),
		'response' => array(
			'code'    => $code,
			'message' => 400 <= $code ? 'Error' : 'OK',
		),
		'cookies'  => array(),
	);
};

$http_filter = static function ( $preempt, array $args, string $url ) use ( &$api_requests, $response ) {
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$body   = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );

	$api_requests[] = array(
		'method' => $method,
		'path'   => $path,
		'body'   => is_array( $body ) ? $body : null,
	);

	if ( false !== strpos( $path, '/terminal/connection_tokens' ) ) {
		return $response( array( 'secret' => 'cnctok_probe_secret' ) );
	}

	if ( preg_match( '#/wcpay/customers$#', $path ) ) {
		return $response( array( 'id' => 'cus_probe' ) );
	}

	if ( preg_match( '#/wcpay/intentions$#', $path ) && 'POST' === $method ) {
		return $response(
			array(
				'id'     => 'pi_created',
				'status' => 'requires_capture',
			)
		);
	}

	if ( preg_match( '#/wcpay/intentions/pi_probe/prepare_terminal_payment$#', $path ) ) {
		return $response(
			array(
				'reader_id' => 'tmr_probe',
				'status'    => 'collecting_payment_method',
			)
		);
	}

	if ( preg_match( '#/wcpay/intentions/pi_probe/capture$#', $path ) ) {
		return $response(
			array(
				'id'       => 'pi_probe',
				'status'   => 'succeeded',
				'currency' => 'usd',
				'charges'  => array(
					'data' => array(
						array(
							'id'                     => 'ch_probe',
							'payment_method'         => 'pm_probe',
							'payment_method_details' => array(
								'type'         => 'card_present',
								'card_present' => array(
									'brand' => 'visa',
									'last4' => '4242',
								),
							),
						),
					),
				),
			)
		);
	}

	if ( preg_match( '#/wcpay/intentions/pi_probe$#', $path ) ) {
		return $response(
			array(
				'id'       => 'pi_probe',
				'status'   => 'requires_capture',
				'currency' => 'usd',
				'metadata' => array(
					'order_id' => (string) ( $GLOBALS['probe_order_id'] ?? 0 ),
				),
				'charges'  => array( 'data' => array() ),
			)
		);
	}

	if ( preg_match( '#/wcpay/intentions/pi_receipt$#', $path ) ) {
		return $response(
			array(
				'id'       => 'pi_receipt',
				'status'   => 'succeeded',
				'currency' => 'usd',
				'amount'   => 1234,
				'charges'  => array(
					'data' => array(
						array( 'id' => 'ch_receipt' ),
					),
				),
			)
		);
	}

	if ( preg_match( '#/wcpay/charges/ch_receipt$#', $path ) ) {
		return $response(
			array(
				'id'                     => 'ch_receipt',
				'amount_captured'        => 1234,
				'currency'               => 'usd',
				'order'                  => array(
					'number' => (string) ( $GLOBALS['probe_order_id'] ?? 0 ),
				),
				'payment_method_details' => array(
					'type'         => 'card_present',
					'card_present' => array(
						'brand' => 'visa',
						'last4' => '4242',
					),
				),
			)
		);
	}

	if ( preg_match( '#/wcpay/terminal/readers$#', $path ) && 'GET' === $method ) {
		return $response(
			array(
				'data' => array(
					array(
						'id'          => 'tmr_probe',
						'livemode'    => false,
						'device_type' => 'bbpos_wisepos_e',
						'label'       => 'Counter',
						'location'    => 'tml_probe',
						'metadata'    => array(),
						'status'      => 'online',
					),
				),
			)
		);
	}

	if ( preg_match( '#/wcpay/terminal/readers$#', $path ) && 'POST' === $method ) {
		return $response(
			array(
				'id'          => 'tmr_registered',
				'livemode'    => false,
				'device_type' => 'bbpos_wisepos_e',
				'label'       => $body['label'] ?? 'Counter',
				'location'    => $body['location'] ?? 'tml_probe',
				'metadata'    => array(),
				'status'      => 'online',
			)
		);
	}

	if ( false !== strpos( $path, '/reader-charges/summary' ) ) {
		return $response(
			array(
				array(
					'reader_id' => 'tmr_probe',
					'status'    => 'active',
				),
			)
		);
	}

	if ( preg_match( '#/wcpay/transactions/txn_probe$#', $path ) ) {
		return $response(
			array(
				'id'      => 'txn_probe',
				'created' => strtotime( '2026-06-01 12:00:00 UTC' ),
			)
		);
	}

	if ( preg_match( '#/wcpay/terminal/locations/tml_probe$#', $path ) && 'POST' === $method ) {
		return $response(
			array(
				'id'           => 'tml_probe',
				'display_name' => $body['display_name'] ?? 'Updated Probe',
				'address'      => $body['address'] ?? array( 'country' => 'US', 'line1' => '456 Market' ),
				'livemode'     => false,
			)
		);
	}

	if ( preg_match( '#/wcpay/terminal/locations/tml_probe$#', $path ) && 'DELETE' === $method ) {
		return $response( array( 'deleted' => true ) );
	}

	if ( preg_match( '#/wcpay/terminal/locations$#', $path ) && 'GET' === $method ) {
		return $response(
			array(
				'data' => array(
					array(
						'id'           => 'tml_probe',
						'display_name' => 'Probe Store',
						'address'      => array(
							'country'     => 'US',
							'state'       => 'CA',
							'city'        => 'San Francisco',
							'postal_code' => '94107',
							'line1'       => '123 Main St',
						),
						'livemode'     => false,
					),
				),
			)
		);
	}

	if ( preg_match( '#/wcpay/terminal/locations$#', $path ) && 'POST' === $method ) {
		return $response(
			array(
				'id'           => 'tml_created',
				'display_name' => $body['display_name'] ?? 'Created Probe',
				'address'      => $body['address'] ?? array( 'country' => 'US', 'line1' => '123 Main St' ),
				'livemode'     => false,
			)
		);
	}

	return $preempt;
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

$dispatch = static function ( string $method, string $route, array $params = array() ): array {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );

	return array(
		'route'  => $route,
		'method' => $method,
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
};

$original_options = array(
	'woocommerce_default_country' => get_option( 'woocommerce_default_country' ),
	'woocommerce_store_address'   => get_option( 'woocommerce_store_address' ),
	'woocommerce_store_address_2' => get_option( 'woocommerce_store_address_2' ),
	'woocommerce_store_city'      => get_option( 'woocommerce_store_city' ),
	'woocommerce_store_postcode'  => get_option( 'woocommerce_store_postcode' ),
);

$order = wc_create_order();
$order->set_total( 12.34 );
$order->set_currency( 'USD' );
$order->set_status( 'pending' );
$order->save();
$GLOBALS['probe_order_id'] = $order->get_id();

update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_store_address', '123 Main St' );
update_option( 'woocommerce_store_address_2', '' );
update_option( 'woocommerce_store_city', 'San Francisco' );
update_option( 'woocommerce_store_postcode', '94107' );

try {
	$routes            = rest_get_server()->get_routes();
	$registered_routes = array_fill_keys( array_map( $normalize_route, array_keys( $routes ) ), true );
	$missing = array_values(
		array_filter(
			$expected_routes,
			static function ( string $route ) use ( $registered_routes, $normalize_route ): bool {
				return ! isset( $registered_routes[ $normalize_route( $route ) ] );
			}
		)
	);

	delete_transient( 'wcpay_store_terminal_readers' );
	delete_transient( 'wcpay_store_terminal_locations' );

	$order_route_base = '/wc/v3/payments/orders/' . $order->get_id();
	$results          = array(
		$dispatch( 'POST', '/wc/v3/payments/connection_tokens' ),
		$dispatch( 'POST', $order_route_base . '/create_customer', array( 'order_id' => $order->get_id() ) ),
		$dispatch(
			'POST',
			$order_route_base . '/create_terminal_intent',
			array(
				'order_id'  => $order->get_id(),
				'metadata'  => array( 'channel' => 'probe' ),
				'customer_id' => 'cus_probe',
			)
		),
		$dispatch( 'POST', $order_route_base . '/prepare_terminal_payment', array( 'order_id' => $order->get_id(), 'payment_intent_id' => 'pi_probe' ) ),
		$dispatch( 'POST', $order_route_base . '/capture_terminal_payment', array( 'order_id' => $order->get_id(), 'payment_intent_id' => 'pi_probe' ) ),
		$dispatch( 'GET', '/wc/v3/payments/readers' ),
		$dispatch( 'POST', '/wc/v3/payments/readers', array( 'location' => 'tml_probe', 'registration_code' => 'code_probe', 'label' => 'Counter' ) ),
		$dispatch( 'GET', '/wc/v3/payments/readers/charges/txn_probe', array( 'transaction_id' => 'txn_probe' ) ),
		$dispatch( 'POST', '/wc/v3/payments/readers/receipts/preview', array( 'business_name' => 'Probe Store' ) ),
		$dispatch( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt', array( 'payment_intent_id' => 'pi_receipt' ) ),
		$dispatch( 'GET', '/wc/v3/payments/terminal/locations' ),
		$dispatch( 'POST', '/wc/v3/payments/terminal/locations', array( 'display_name' => 'Created Probe', 'address' => array( 'country' => 'US', 'line1' => '123 Main St' ) ) ),
		$dispatch( 'GET', '/wc/v3/payments/terminal/locations/tml_probe', array( 'location_id' => 'tml_probe' ) ),
		$dispatch( 'POST', '/wc/v3/payments/terminal/locations/tml_probe', array( 'location_id' => 'tml_probe', 'display_name' => 'Updated Probe', 'address' => array( 'country' => 'US', 'line1' => '456 Market' ) ) ),
		$dispatch( 'DELETE', '/wc/v3/payments/terminal/locations/tml_probe', array( 'location_id' => 'tml_probe' ) ),
		$dispatch( 'GET', '/wc/v3/payments/terminal/locations/store' ),
	);

		$order = wc_get_order( $order->get_id() );
		$owner = wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->get_runtime_owner();
		$route_failures = array_values(
			array_filter(
				$results,
				static function ( array $result ): bool {
					$status = (int) ( $result['status'] ?? 0 );
					return 200 > $status || 300 <= $status;
				}
			)
		);

		echo wp_json_encode(
			array(
				'owner'          => $owner,
				'missing_routes' => $missing,
				'route_failures' => $route_failures,
				'results'        => $results,
				'order_meta'     => array(
					'payment_method' => $order ? $order->get_payment_method() : '',
				'intent_id'      => $order ? $order->get_meta( '_intent_id', true ) : '',
				'charge_id'      => $order ? $order->get_meta( '_charge_id', true ) : '',
				'status'         => $order ? $order->get_meta( '_intention_status', true ) : '',
				'receipt_url'    => $order ? $order->get_meta( 'receipt_url', true ) : '',
				'raw_details'    => $order ? $order->get_meta( '_wcpay_raw_payment_method_details', true ) : '',
			),
			'api_requests'   => $api_requests,
			),
			JSON_PRETTY_PRINT
		) . PHP_EOL;

		if ( ! empty( $missing ) || ! empty( $route_failures ) ) {
			exit( 1 );
		}
	} finally {
	foreach ( $original_options as $option => $value ) {
		update_option( $option, $value );
	}

	if ( $order instanceof WC_Order ) {
		$order->delete( true );
	}

	remove_filter( 'pre_http_request', $http_filter, 10 );
	delete_transient( 'wcpay_store_terminal_readers' );
	delete_transient( 'wcpay_store_terminal_locations' );
}
