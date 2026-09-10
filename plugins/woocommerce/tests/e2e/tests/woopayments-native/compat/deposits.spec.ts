import { expect, test } from '@playwright/test';

import { runExtensionCompatProbe } from './wp-cli';

test.skip(
	process.env.E2E_WOOPAYMENTS_EXTENSION_COMPAT !== 'true',
	'The external-extension compatibility profile is opt-in.'
);

test( '@woopayments-extension-compat Deposits hides express line items and converts a fixed deposit once', async () => {
	const result = await runExtensionCompatProbe< {
		version: string;
		displayItemsPresent: boolean;
		depositAmount: number;
		cartDepositAmount: number;
	} >( String.raw`
if ( ! class_exists( 'WC_Deposits_Product_Manager' ) || ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency' ) ) {
	throw new RuntimeException( 'WooCommerce Deposits or the native multi-currency facade is not active.' );
}

$product = new WC_Product_Simple();
$product->set_name( 'Extension compatibility deposit' );
$product->set_regular_price( '100' );
$product->set_price( '100' );
$product->save();
$missing_option = new stdClass();
$rate_options   = array(
	'woocommerce_currency'                    => get_option( 'woocommerce_currency', $missing_option ),
	'wcpay_multi_currency_enabled_currencies' => get_option( 'wcpay_multi_currency_enabled_currencies', $missing_option ),
	'wcpay_multi_currency_exchange_rate_eur' => get_option( 'wcpay_multi_currency_exchange_rate_eur', $missing_option ),
	'wcpay_multi_currency_manual_rate_eur'   => get_option( 'wcpay_multi_currency_manual_rate_eur', $missing_option ),
);

try {
	$product->update_meta_data( '_wc_deposit_enabled', 'yes' );
	$product->update_meta_data( '_wc_deposit_type', 'fixed' );
	$product->update_meta_data( '_wc_deposit_amount', '10' );
	$product->save();

	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
	update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
	update_option( 'wcpay_multi_currency_manual_rate_eur', '0.8' );
	add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR', PHP_INT_MAX );
	$state_builder = wc_get_container()->get( Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory::class )->create();
	$state_builder->reset();
	$deposit_amount = (float) WC_Deposits_Product_Manager::get_deposit_amount( $product, 0, 'order' );

	$GLOBALS['product'] = $product;
	$cart               = $wcpay_fresh_cart(
		array(
			'deposit' => array(
				'data'           => $product,
				'quantity'       => 1,
				'is_deposit'     => true,
				'deposit_amount' => $deposit_amount,
				'full_amount'    => 100,
			),
		)
	);
	$express            = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService::class );
	$product_data        = $wcpay_invoke_private( $express, 'get_product_data' );
	$cart_contents       = $cart->get_cart();

	return array(
		'version'           => $wcpay_extension_version( 'deposits' ),
		'displayItemsPresent' => array_key_exists( 'displayItems', $product_data ),
		'depositAmount'     => $deposit_amount,
		'cartDepositAmount' => (float) $cart_contents['deposit']['deposit_amount'],
	);
} finally {
	foreach ( $rate_options as $option_name => $option_value ) {
		if ( $missing_option === $option_value ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $option_value );
		}
	}
	wp_delete_post( $product->get_id(), true );
}
` );

	expect( [ '2.2.9', '2.4.7' ] ).toContain( result.version );
	expect( result.displayItemsPresent ).toBe( false );
	expect( result.depositAmount ).toBeCloseTo( 8, 8 );
	expect( result.cartDepositAmount ).toBeCloseTo( 8, 8 );
} );
