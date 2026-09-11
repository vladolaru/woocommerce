<?php
/**
 * Trace native WooPayments checkout request/response boundaries for local debugging.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;

$mode           = $args[0] ?? 'order';
$order_or_sku   = $args[1] ?? '';
$payment_method = $args[2] ?? 'pm_card_visa';
$quantity       = isset( $args[3] ) ? max( 1, (int) $args[3] ) : 1;
$gateway_id     = class_exists( OrderPaymentStore::class ) ? OrderPaymentStore::GATEWAY_ID : 'woocommerce_payments';
$trace          = array(
	'request_params' => array(),
	'http'           => array(),
);

add_filter(
	'wcpay_api_request_params',
	static function ( $params, $api = '', $method = '' ) use ( &$trace ) {
		if ( is_string( $api ) && preg_match( '#^(customers|customers/|intentions|setup_intents)#', $api ) ) {
			$trace['request_params'][] = array(
				'api'    => $api,
				'method' => strtoupper( (string) $method ),
				'params' => woopayments_merge_trace_redact( is_array( $params ) ? $params : array() ),
			);
		}

		return $params;
	},
	PHP_INT_MAX,
	3
);

add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) use ( &$trace ) {
		unset( $class );

		if ( ! is_string( $url ) || false === strpos( $url, '/wcpay/' ) ) {
			return;
		}

		$body = is_array( $args ) && isset( $args['body'] ) ? $args['body'] : null;
		$item = array(
			'context'       => (string) $context,
			'method'        => is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : '',
			'url'           => preg_replace( '#([?&](?:_wpnonce|nonce|token)=[^&]+)#', '$1[redacted]', $url ),
			'request_body'  => woopayments_merge_trace_redact_json( is_string( $body ) ? $body : '' ),
			'response_code' => is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0,
			'response_body' => is_array( $response ) ? woopayments_merge_trace_redact_json( wp_remote_retrieve_body( $response ) ) : array(),
		);

		if ( is_wp_error( $response ) ) {
			$item['wp_error'] = array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			);
		}

		$trace['http'][] = $item;
	},
	10,
	5
);

$order = 'order' === $mode
	? wc_get_order( absint( $order_or_sku ) )
	: woopayments_merge_trace_create_order( (string) $order_or_sku, $quantity, $gateway_id );

if ( ! $order instanceof WC_Order ) {
	WP_CLI::error( 'Could not load or create the probe order.' );
}

WP_CLI::log( sprintf( 'Tracing native WooPayments process_payment for order #%d with %s.', $order->get_id(), $payment_method ) );

$order->set_payment_method( $gateway_id );
$order->save();

$_POST['wcpay-payment-method']                        = $payment_method;
$_POST[ 'wc-' . $gateway_id . '-new-payment-method' ] = '1';

$gateways = WC()->payment_gateways()->payment_gateways();
$gateway  = $gateways[ $gateway_id ] ?? null;

if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_payment' ) ) ) {
	WP_CLI::error( 'Native WooPayments gateway is not available.' );
}

$result = $gateway->process_payment( $order->get_id() );
$order  = wc_get_order( $order->get_id() );

if ( ! $order instanceof WC_Order ) {
	WP_CLI::error( 'Order disappeared after checkout probe.' );
}

$payload = array(
	'mode'                 => $mode,
	'order_id'             => $order->get_id(),
	'order_status'         => $order->get_status(),
	'payment_result'       => is_array( $result ) ? $result : array(),
	'payment_method'       => $order->get_payment_method(),
	'transaction_id'       => $order->get_transaction_id(),
	'meta'                 => woopayments_merge_trace_order_meta( $order ),
	'related_subscriptions' => woopayments_merge_trace_related_subscriptions( $order ),
	'notes'                => woopayments_merge_trace_order_notes( $order ),
	'trace'                => $trace,
);

WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );

/**
 * Create a basic WooPayments order for fresh probes.
 *
 * @param string $sku SKU to add.
 * @param int    $quantity Product quantity.
 * @param string $gateway_id Gateway ID.
 * @return WC_Order|null
 */
function woopayments_merge_trace_create_order( string $sku, int $quantity, string $gateway_id ): ?WC_Order {
	$product_id = wc_get_product_id_by_sku( $sku );
	if ( ! $product_id && is_numeric( $sku ) ) {
		$product_id = (int) $sku;
	}

	if ( ! $product_id ) {
		WP_CLI::error( "Could not find product {$sku}." );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		WP_CLI::error( "Could not load product {$sku}." );
	}

	$order = wc_create_order();
	$order->add_product( $product, $quantity );
	$order->set_created_via( 'harness-native-checkout-trace' );
	$order->set_billing_first_name( 'Harness' );
	$order->set_billing_last_name( 'Trace' );
	$order->set_billing_email( 'native.trace@example.com' );
	$order->set_billing_address_1( '123 Test Street' );
	$order->set_billing_city( 'San Francisco' );
	$order->set_billing_state( 'CA' );
	$order->set_billing_postcode( '94103' );
	$order->set_billing_country( 'US' );
	$order->set_payment_method( $gateway_id );
	$order->calculate_totals();
	$order->save();

	return $order;
}

/**
 * Redact sensitive fields recursively.
 *
 * @param mixed $value Value to redact.
 * @return mixed
 */
function woopayments_merge_trace_redact( $value ) {
	if ( is_array( $value ) ) {
		$redacted = array();
		foreach ( $value as $key => $child ) {
			$key_string = is_string( $key ) ? $key : (string) $key;
			if ( preg_match( '/secret|authorization|client_secret|password|nonce|token/i', $key_string ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			$redacted[ $key ] = woopayments_merge_trace_redact( $child );
		}

		return $redacted;
	}

	return $value;
}

/**
 * Decode and redact a JSON payload when possible.
 *
 * @param string $json JSON body.
 * @return mixed
 */
function woopayments_merge_trace_redact_json( string $json ) {
	if ( '' === $json ) {
		return '';
	}

	$decoded = json_decode( $json, true );
	if ( is_array( $decoded ) ) {
		return woopayments_merge_trace_redact( $decoded );
	}

	return $json;
}

/**
 * Capture payment-relevant order metadata.
 *
 * @param WC_Order $order Order object.
 * @return array<string,string>
 */
function woopayments_merge_trace_order_meta( WC_Order $order ): array {
	$keys = array(
		'_intent_id',
		'_intention_status',
		'_charge_id',
		'_payment_method_id',
		'_stripe_customer_id',
		'_wcpay_intent_currency',
		'_wcpay_mode',
		'_wcpay_transaction_fee',
		'_wcpay_net',
		'_wcpay_payment_method_details',
	);

	$meta = array();
	foreach ( $keys as $key ) {
		$meta[ $key ] = (string) $order->get_meta( $key, true );
	}

	return $meta;
}

/**
 * Capture related subscription IDs and facts.
 *
 * @param WC_Order $order Order object.
 * @return array<int,array<string,mixed>>
 */
function woopayments_merge_trace_related_subscriptions( WC_Order $order ): array {
	if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
		return array();
	}

	$subscriptions = wcs_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'any' ) );
	$facts         = array();
	foreach ( $subscriptions as $subscription ) {
		if ( ! $subscription instanceof WC_Order ) {
			continue;
		}

		$facts[] = array(
			'id'             => $subscription->get_id(),
			'status'         => $subscription->get_status(),
			'user_id'        => $subscription->get_user_id(),
			'payment_method' => $subscription->get_payment_method(),
			'tokens'         => method_exists( $subscription, 'get_payment_tokens' ) ? array_values( array_map( 'absint', $subscription->get_payment_tokens() ) ) : array(),
			'meta'           => woopayments_merge_trace_order_meta( $subscription ),
		);
	}

	return $facts;
}

/**
 * Capture recent order notes.
 *
 * @param WC_Order $order Order object.
 * @return string[]
 */
function woopayments_merge_trace_order_notes( WC_Order $order ): array {
	$notes = wc_get_order_notes(
		array(
			'order_id' => $order->get_id(),
			'type'     => 'any',
			'limit'    => 8,
		)
	);

	return array_map(
		static function ( $note ): string {
			return (string) $note->content;
		},
		$notes
	);
}
