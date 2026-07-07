<?php
/**
 * Deterministic Test Lab charge driver for cross-store parity.
 *
 * This runs inside the target WordPress store via WP-CLI. It uses the same
 * WooPayments Dev Tools checkout simulator as `wp wcpay-dev test-lab charges`,
 * but supplies product, quantity, and customer inputs so reference/target runs
 * exercise equivalent orders instead of random catalog picks.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$sku      = $args[0] ?? 'test-lab-beaker-001';
$quantity = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 2;
$type     = $args[2] ?? 'success';
$manual_capture = isset( $args[3] ) && ! in_array( strtolower( (string) $args[3] ), array( '', '0', 'false', 'no' ), true );
$currency = strtoupper( trim( (string) ( $args[4] ?? '' ) ) );

if ( ! class_exists( '\WCPayDev\TestLab\Operations\Environment' ) || ! class_exists( '\WCPayDev\TestLab\Operations\Checkout_Simulator' ) ) {
	WP_CLI::error( 'WooPayments Dev Tools Test Lab classes are not loaded.' );
}

$environment = new \WCPayDev\TestLab\Operations\Environment();
if ( ! $environment->is_operations_allowed() ) {
	$status = $environment->get_guardrail_status();
	WP_CLI::error( $status['message'] ?? 'Test Lab operations are not allowed.' );
}

if ( class_exists( '\WCPayDev\TestLab\Operations\Catalog_Setup' ) ) {
	$catalog = new \WCPayDev\TestLab\Operations\Catalog_Setup( $environment );
	if ( ! wc_get_product_id_by_sku( $sku ) ) {
		$catalog->stock_lab( false );
	}
}

$product_id = wc_get_product_id_by_sku( $sku );
if ( ! $product_id ) {
	WP_CLI::error( "Could not find Test Lab product SKU {$sku}." );
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	WP_CLI::error( "Could not load Test Lab product SKU {$sku}." );
}

if ( 'test-lab-beaker-001' === $sku ) {
	$product->set_regular_price( '25.00' );
	$product->set_sale_price( '' );
	$product->set_price( '25.00' );
	$product->save();
}

$customer_id = 0;
global $wpdb;
$customer_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id ASC",
		'_wcpay_test_lab'
	)
);

if ( empty( $customer_ids ) && isset( $catalog ) ) {
	$catalog->create_customers( 1, 'harness-deterministic-charge' );
	$customer_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id ASC",
			'_wcpay_test_lab'
		)
	);
}

if ( ! empty( $customer_ids ) ) {
	$customer_id = (int) $customer_ids[0];
}

if ( ! $customer_id ) {
	WP_CLI::error( 'Could not find or create a Test Lab customer.' );
}

$restore_currency_meta     = false;
$previous_currency_exists  = metadata_exists( 'user', $customer_id, 'wcpay_currency' );
$previous_currency_meta    = $previous_currency_exists ? get_user_meta( $customer_id, 'wcpay_currency', true ) : '';
if ( '' !== $currency ) {
	$original_user            = get_current_user_id();
	wp_set_current_user( $customer_id );

	try {
		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
			WP_CLI::error( 'WooPayments multi-currency runtime is not loaded.' );
		}

		WC_Payments_Multi_Currency()->update_selected_currency( $currency, true );
		$selected_currency = strtoupper( get_woocommerce_currency() );
		if ( $selected_currency !== $currency ) {
			WP_CLI::error( "Selected currency {$currency} did not become the WooCommerce order currency; got {$selected_currency}." );
		}

		$restore_currency_meta = true;
	} finally {
		wp_set_current_user( $original_user );
	}
} elseif ( $previous_currency_exists ) {
	delete_user_meta( $customer_id, 'wcpay_currency' );
	$restore_currency_meta = true;
}

$payment_methods = array(
	'success' => \WCPayDev\TestLab\Operations\Checkout_Simulator::PM_SUCCESS,
	'instant' => \WCPayDev\TestLab\Operations\Checkout_Simulator::PM_BYPASS_PENDING,
	'fail'    => \WCPayDev\TestLab\Operations\Checkout_Simulator::PM_DECLINED,
	'3ds'     => \WCPayDev\TestLab\Operations\Checkout_Simulator::PM_3DS_REQUIRED,
	'dispute' => \WCPayDev\TestLab\Operations\Checkout_Simulator::PM_DISPUTE,
);

$settings_option  = 'woocommerce_woocommerce_payments_settings';
$missing_settings = new stdClass();
$original_settings = get_option( $settings_option, $missing_settings );
$gateway = WC()->payment_gateways()->payment_gateways()['woocommerce_payments'] ?? null;

if ( $manual_capture ) {
	$settings                   = is_array( $original_settings ) ? $original_settings : array();
	$settings['manual_capture'] = 'yes';
	update_option( $settings_option, $settings, false );
	if ( is_object( $gateway ) && is_callable( array( $gateway, 'update_option' ) ) ) {
		$gateway->update_option( 'manual_capture', 'yes' );
	}
}

try {
	$simulator = new \WCPayDev\TestLab\Operations\Checkout_Simulator( $environment );
	$result = $simulator->simulate(
		array(
			'payment_method' => $payment_methods[ $type ] ?? $payment_methods['success'],
			'product_id'     => $product_id,
			'customer_id'    => $customer_id,
			'quantity'       => $quantity,
			'operation'      => 'charges',
			'protocol'       => 'harness-deterministic-charge',
		)
	);
} finally {
	if ( $manual_capture ) {
		if ( $original_settings === $missing_settings ) {
			delete_option( $settings_option );
		} else {
			update_option( $settings_option, $original_settings, false );
		}
	}

	if ( $restore_currency_meta ) {
		if ( $previous_currency_exists ) {
			update_user_meta( $customer_id, 'wcpay_currency', $previous_currency_meta );
		} else {
			delete_user_meta( $customer_id, 'wcpay_currency' );
		}
	}
}

if ( empty( $result['order_id'] ) ) {
	WP_CLI::error( $result['error'] ?? 'Deterministic charge did not create an order.' );
}

$order = wc_get_order( (int) $result['order_id'] );
$result['manual_capture']   = $manual_capture ? 'yes' : 'no';
$result['intention_status'] = $order instanceof WC_Order ? (string) $order->get_meta( '_intention_status', true ) : '';
$result['currency_requested']     = $currency;
$result['order_currency']         = $order instanceof WC_Order ? (string) $order->get_currency() : '';
$result['order_exchange_rate']    = $order instanceof WC_Order ? (string) $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) : '';
$result['order_default_currency'] = $order instanceof WC_Order ? (string) $order->get_meta( '_wcpay_multi_currency_order_default_currency', true ) : '';
$result['stripe_exchange_rate']   = $order instanceof WC_Order ? (string) $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate', true ) : '';

WP_CLI::line( wp_json_encode( $result ) );
