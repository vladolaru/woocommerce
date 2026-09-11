<?php
/**
 * Deterministic native WooPayments charge driver for local parity probes.
 *
 * This runs inside the target WordPress store via WP-CLI with the WooPayments
 * plugin inactive and the local native-runtime MU toggle enabled.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;

$sku            = $args[0] ?? 'test-lab-beaker-001';
$quantity       = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 2;
$payment_method = $args[2] ?? 'pm_card_visa';
$manual_capture = isset( $args[3] ) && ! in_array( strtolower( (string) $args[3] ), array( '', '0', 'false', 'no' ), true );
$currency       = strtoupper( trim( (string) ( $args[4] ?? '' ) ) );
$run_token      = trim( (string) ( $args[5] ?? '' ) );
$store_currency = strtoupper( (string) get_option( 'woocommerce_currency' ) );

if ( '' !== $run_token && ! preg_match( '/^wcpay-verify-[a-f0-9]{32}$/', $run_token ) ) {
	WP_CLI::error( 'Invalid verifier run token.' );
}

if ( '' === $currency ) {
	add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );
	add_filter( 'wcpay_multi_currency_should_convert_product_price', '__return_false' );
}

$arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
if ( NativePaymentsRuntimeArbiter::OWNER_NATIVE !== $arbiter->get_runtime_owner() ) {
	WP_CLI::error( 'Native runtime does not own this request.' );
}

$product_id = wc_get_product_id_by_sku( $sku );
if ( ! $product_id ) {
	WP_CLI::error( "Could not find product SKU {$sku}." );
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	WP_CLI::error( "Could not load product SKU {$sku}." );
}

$normalize_fixture_price = 'test-lab-beaker-001' === $sku;
$fixed_fixture_price     = static function ( $price, $candidate ) use ( $product_id ) {
	return $candidate instanceof WC_Product && $product_id === $candidate->get_id() ? '25.00' : $price;
};
$empty_fixture_sale_price = static function ( $price, $candidate ) use ( $product_id ) {
	return $candidate instanceof WC_Product && $product_id === $candidate->get_id() ? '' : $price;
};
if ( $normalize_fixture_price ) {
	add_filter( 'woocommerce_product_get_price', $fixed_fixture_price, 10, 2 );
	add_filter( 'woocommerce_product_get_regular_price', $fixed_fixture_price, 10, 2 );
	add_filter( 'woocommerce_product_get_sale_price', $empty_fixture_sale_price, 10, 2 );
}

$currency_user_id          = get_current_user_id();
$restore_currency_meta     = false;
$previous_currency_exists  = $currency_user_id ? metadata_exists( 'user', $currency_user_id, 'wcpay_currency' ) : false;
$previous_currency_meta    = $previous_currency_exists ? get_user_meta( $currency_user_id, 'wcpay_currency', true ) : '';
$selected_currency         = '';
$frontend_prices_controller = null;

if ( '' !== $currency ) {
	if (
		! class_exists( MultiCurrencyRuntimeServiceFactory::class ) ||
		! class_exists( MultiCurrencyProjectionServiceFactory::class ) ||
		! class_exists( MultiCurrencyFrontendCurrenciesController::class ) ||
		! class_exists( MultiCurrencyFrontendPricesController::class )
	) {
		WP_CLI::error( 'Native multi-currency runtime is not loaded.' );
	}

	$frontend_request_context = new class() extends MultiCurrencyRequestContext {
		/**
		 * Force frontend hook registration for this deterministic WP-CLI probe.
		 *
		 * @return bool
		 */
		public function should_register_frontend_hooks(): bool {
			return true;
		}
	};

	$service = wc_get_container()->get( MultiCurrencyRuntimeServiceFactory::class )->create_selected_currency_persistence_service();
	if ( ! $service->update_selected_currency( $currency, true ) ) {
		WP_CLI::error( "Native multi-currency could not select {$currency}." );
	}

	$projection_service_factory      = wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class );
	$frontend_currencies_controller = wc_get_container()->get( MultiCurrencyFrontendCurrenciesController::class );
	$frontend_currencies_controller->set_request_context( $frontend_request_context );
	$frontend_currencies_controller->set_frontend_projection_service( $projection_service_factory->create_frontend_projection_service() );
	$frontend_currencies_controller->register();

	$frontend_prices_controller = wc_get_container()->get( MultiCurrencyFrontendPricesController::class );
	$frontend_prices_controller->set_request_context( $frontend_request_context );
	$frontend_prices_controller->set_price_projection_service( $projection_service_factory->create_price_projection_service() );
	$frontend_prices_controller->register();

	$selected_currency = strtoupper( get_woocommerce_currency() );
	if ( $selected_currency !== $currency ) {
		WP_CLI::error( "Selected currency {$currency} did not become the WooCommerce order currency; got {$selected_currency}." );
	}

	$restore_currency_meta = true;
} elseif ( $currency_user_id && $previous_currency_exists ) {
	delete_user_meta( $currency_user_id, 'wcpay_currency' );
	$restore_currency_meta = true;
}

$order = wc_create_order();
if ( '' !== $currency ) {
	$order->set_currency( $selected_currency );
} else {
	$order->set_currency( $store_currency );
}
$order->add_product( $product, $quantity );
$order->set_created_via( 'harness-native-charge' );
$order->set_billing_first_name( 'Harness' );
$order->set_billing_last_name( 'Native' );
$order->set_billing_email( 'native.harness@example.com' );
$order->set_billing_address_1( '123 Test Street' );
$order->set_billing_city( 'San Francisco' );
$order->set_billing_state( 'CA' );
$order->set_billing_postcode( '94103' );
$order->set_billing_country( 'US' );
$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
$order->update_meta_data( '_wcpay_verify_run_token', $run_token );
$order->calculate_totals();
$order->save();

if ( '' !== $currency && $frontend_prices_controller instanceof MultiCurrencyFrontendPricesController ) {
	$frontend_prices_controller->add_order_meta( $order->get_id(), $order );
}
// No-currency case: the driver context is already deterministic (leftover
// wcpay_currency user meta is cleared above and the store-currency filters are
// armed), so any multi-currency meta the runtime stamps on this order is REAL
// behavior. It must stay on the order for Bucket-E parity and reconciliation to
// see — scrubbing it here would hide exactly the divergence those gates exist
// to catch. The emitted JSON reports the meta as persisted.

$_POST['wcpay-payment-method'] = $payment_method;

$gateways = WC()->payment_gateways()->payment_gateways();
$gateway  = $gateways[ OrderPaymentStore::GATEWAY_ID ] ?? null;

if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_payment' ) ) ) {
	WP_CLI::error( 'Native WooPayments gateway is not available.' );
}

$settings_option   = 'woocommerce_woocommerce_payments_settings';
$missing_settings  = new stdClass();
$original_settings = get_option( $settings_option, $missing_settings );

if ( $manual_capture ) {
	$settings                   = is_array( $original_settings ) ? $original_settings : array();
	$settings['manual_capture'] = 'yes';
	update_option( $settings_option, $settings, false );
}

$session                   = WC()->session;
$session_key               = WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER;
$session_data              = $session instanceof WC_Session && method_exists( $session, 'get_session_data' ) ? $session->get_session_data() : array();
$processing_order_existed  = array_key_exists( $session_key, $session_data );
$previous_processing_order = $processing_order_existed ? maybe_unserialize( $session_data[ $session_key ] ) : null;
if ( $session instanceof WC_Session ) {
	$session->set( $session_key, null );
	if ( method_exists( $session, 'save_data' ) ) {
		$session->save_data();
	}
}

try {
	$result = $gateway->process_payment( $order->get_id() );
} finally {
	if ( $session instanceof WC_Session ) {
		$session->set( $session_key, $processing_order_existed ? $previous_processing_order : null );
		if ( method_exists( $session, 'save_data' ) ) {
			$session->save_data();
		}
	}

	if ( $manual_capture ) {
		if ( $original_settings === $missing_settings ) {
			delete_option( $settings_option );
		} else {
			update_option( $settings_option, $original_settings, false );
		}
	}

	if ( $restore_currency_meta && $currency_user_id ) {
		if ( $previous_currency_exists ) {
			update_user_meta( $currency_user_id, 'wcpay_currency', $previous_currency_meta );
		} else {
			delete_user_meta( $currency_user_id, 'wcpay_currency' );
		}
	}

	if ( $normalize_fixture_price ) {
		remove_filter( 'woocommerce_product_get_price', $fixed_fixture_price, 10 );
		remove_filter( 'woocommerce_product_get_regular_price', $fixed_fixture_price, 10 );
		remove_filter( 'woocommerce_product_get_sale_price', $empty_fixture_sale_price, 10 );
	}
}

$order  = wc_get_order( $order->get_id() );

if ( ! is_array( $result ) || 'success' !== ( $result['result'] ?? '' ) ) {
	WP_CLI::error(
		wp_json_encode(
			array(
				'error'    => 'native_charge_failed',
				'order_id' => $order ? $order->get_id() : 0,
				'result'   => $result,
			)
		)
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'op'             => 'native_charge',
			'order_id'       => $order ? $order->get_id() : 0,
			'charge_id'      => $order ? (string) $order->get_meta( '_charge_id', true ) : '',
			'intent_id'      => $order ? (string) $order->get_meta( '_intent_id', true ) : '',
				'intention_status' => $order ? (string) $order->get_meta( '_intention_status', true ) : '',
				'manual_capture' => $manual_capture ? 'yes' : 'no',
				'currency_requested' => $currency,
				'order_currency' => $order ? (string) $order->get_currency() : '',
				'order_exchange_rate' => $order ? (string) $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) : '',
				'order_default_currency' => $order ? (string) $order->get_meta( '_wcpay_multi_currency_order_default_currency', true ) : '',
				'stripe_exchange_rate' => $order ? (string) $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) : '',
				'transaction_id' => $order ? $order->get_transaction_id() : '',
			'status'         => $order ? $order->get_status() : '',
			'result'         => $result['result'] ?? '',
			'redirect'       => $result['redirect'] ?? '',
		)
	)
);
