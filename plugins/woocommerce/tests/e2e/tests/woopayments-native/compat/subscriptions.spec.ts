import { expect, test } from '@playwright/test';

import { runExtensionCompatProbe } from './wp-cli';

test.skip(
	process.env.E2E_WOOPAYMENTS_EXTENSION_COMPAT !== 'true',
	'The external-extension compatibility profile is opt-in.'
);

test( '@woopayments-extension-compat Subscriptions renews and changes payment method through the native gateway', async () => {
	const result = await runExtensionCompatProbe< {
		version: string;
		gatewayId: string;
		missingSupports: string[];
		renewalPaymentMethod: string;
		changedPaymentMethod: string;
		subscriptionTotal: string;
		subscriptionManual: boolean;
		renewalTotal: string;
		apfsPaymentRequestCallbacks: number;
		apfsWooPayCallbacks: number;
		apfsQuickPaySupported: boolean | null;
	} >( String.raw`
if ( ! class_exists( 'WC_Subscriptions' ) || ! function_exists( 'wcs_create_subscription' ) || ! class_exists( 'WC_Subscriptions_Manager' ) ) {
	throw new RuntimeException( 'WooCommerce Subscriptions is not active.' );
}

$version      = $wcpay_extension_version( 'subscriptions' );
$gateway      = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway::class );
$required     = array(
	'products',
	'subscriptions',
	'multiple_subscriptions',
	'subscription_cancellation',
	'subscription_suspension',
	'subscription_reactivation',
	'subscription_amount_changes',
	'subscription_date_changes',
	'subscription_payment_method_change',
	'subscription_payment_method_change_customer',
	'subscription_payment_method_change_admin',
);
$missing      = array_values( array_filter( $required, static fn( string $feature ): bool => ! $gateway->supports( $feature ) ) );
$gateway_manager    = WC()->payment_gateways();
$registered_gateways = $gateway_manager->payment_gateways;
$gateway_manager->payment_gateways[] = $gateway;
add_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_false' );
$product      = new WC_Product_Subscription();
$subscription = null;
$renewal      = null;
$product->set_name( 'Extension compatibility subscription' );
$product->set_regular_price( '12' );
$product->set_price( '12' );
$product->update_meta_data( '_subscription_price', '12' );
$product->update_meta_data( '_subscription_period', 'month' );
$product->update_meta_data( '_subscription_period_interval', '1' );
$product->update_meta_data( '_subscription_length', '0' );
$product->save();

try {
	$subscription = wcs_create_subscription(
		array(
			'customer_id'     => 1,
			'billing_period'  => 'month',
			'billing_interval' => 1,
			'status'          => 'active',
		)
	);
	if ( is_wp_error( $subscription ) ) {
		throw new RuntimeException( $subscription->get_error_message() );
	}
	$subscription->add_product( $product, 1, array( 'subtotal' => 12, 'total' => 12 ) );
	$subscription->set_payment_method( $gateway );
	$subscription->set_requires_manual_renewal( false );
	$subscription->set_billing_email( 'admin@example.org' );
	$subscription->calculate_totals();
	$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + MONTH_IN_SECONDS ) ) );
	$subscription->save();
	$subscription_total  = $subscription->get_total();
	$subscription_manual = $subscription->is_manual();

	$renewal = WC_Subscriptions_Manager::process_renewal(
		$subscription->get_id(),
		'active',
		'Extension compatibility scheduled renewal.'
	);
	if ( is_wp_error( $renewal ) ) {
		throw new RuntimeException( $renewal->get_error_message() );
	}
	if ( ! $renewal instanceof WC_Order ) {
		throw new RuntimeException( 'WooCommerce Subscriptions did not create a scheduled renewal order.' );
	}

	$subscription->set_payment_method( 'bacs' );
	$subscription->save();
	$subscription->set_payment_method( $gateway );
	$subscription->save();
	$reloaded = wcs_get_subscription( $subscription->get_id() );

	$count_apfs_callback = static function ( string $hook ): int {
		global $wp_filter;
		$count = 0;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return 0;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && 'WCS_ATT_Intgeration_WC_Payments' === $function[0] && 'handle_quick_pay_buttons' === $function[1] ) {
					++$count;
				}
			}
		}
		return $count;
	};

	$apfs_quick_pay_supported = null;
	if ( class_exists( 'WCS_ATT_Scheme' ) && class_exists( 'WCS_ATT_Product_Schemes' ) ) {
		$apfs_product = new WC_Product_Simple();
		$apfs_product->set_regular_price( '20' );
		$apfs_product->set_price( '20' );
		$scheme = new WCS_ATT_Scheme(
			array(
				'data' => array(
					'subscription_period'          => 'month',
					'subscription_period_interval' => 1,
					'subscription_length'          => 0,
				),
			)
		);
		WCS_ATT_Product_Schemes::set_subscription_schemes( $apfs_product, array( $scheme->get_key() => $scheme ) );
		$apfs_quick_pay_supported = (bool) apply_filters( 'wcpay_payment_request_is_product_supported', true, $apfs_product );
	}

	return array(
		'version'                       => $version,
		'gatewayId'                     => $gateway->id,
		'missingSupports'               => $missing,
		'renewalPaymentMethod'          => $renewal->get_payment_method(),
		'changedPaymentMethod'          => $reloaded->get_payment_method(),
		'subscriptionTotal'             => $subscription_total,
		'subscriptionManual'            => $subscription_manual,
		'renewalTotal'                  => $renewal->get_total(),
		'apfsPaymentRequestCallbacks'   => $count_apfs_callback( 'wcpay_payment_request_is_product_supported' ),
		'apfsWooPayCallbacks'           => $count_apfs_callback( 'wcpay_woopay_button_is_product_supported' ),
		'apfsQuickPaySupported'         => $apfs_quick_pay_supported,
	);
} finally {
	remove_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_false' );
	$gateway_manager->payment_gateways = $registered_gateways;
	if ( $renewal instanceof WC_Order ) {
		$renewal->delete( true );
	}
	if ( $subscription instanceof WC_Subscription ) {
		$subscription->delete( true );
	}
	wp_delete_post( $product->get_id(), true );
}
` );

	expect( [ '7.5.0', '9.2.0' ] ).toContain( result.version );
	expect( result.gatewayId ).toBe( 'woocommerce_payments' );
	expect( result.missingSupports ).toEqual( [] );
	expect( result ).toMatchObject( {
		subscriptionTotal: '12.00',
		subscriptionManual: false,
		renewalTotal: '12.00',
	} );
	expect( result.renewalPaymentMethod ).toBe( 'woocommerce_payments' );
	expect( result.changedPaymentMethod ).toBe( 'woocommerce_payments' );
	const expectedApfsByVersion = {
		'7.5.0': {
			paymentRequestCallbacks: 0,
			wooPayCallbacks: 0,
			quickPaySupported: null,
		},
		'9.2.0': {
			paymentRequestCallbacks: 1,
			wooPayCallbacks: 1,
			quickPaySupported: false,
		},
	} as const;
	const expectedApfs =
		expectedApfsByVersion[
			result.version as keyof typeof expectedApfsByVersion
		];
	expect( {
		paymentRequestCallbacks: result.apfsPaymentRequestCallbacks,
		wooPayCallbacks: result.apfsWooPayCallbacks,
		quickPaySupported: result.apfsQuickPaySupported,
	} ).toEqual( expectedApfs );
} );
