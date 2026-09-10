import { expect, test } from '@playwright/test';

import { runExtensionCompatProbe } from './wp-cli';

test.skip(
	process.env.E2E_WOOPAYMENTS_EXTENSION_COMPAT !== 'true',
	'The external-extension compatibility profile is opt-in.'
);

test( '@woopayments-extension-compat Bookings suppresses native express methods for bookable products', async () => {
	const result = await runExtensionCompatProbe< {
		version: string;
		productExpressSupported: boolean;
		cartExpressSupported: boolean;
		productWooPaySupported: boolean;
		cartWooPaySupported: boolean;
	} >( String.raw`
if ( ! class_exists( 'WC_Product_Booking' ) ) {
	throw new RuntimeException( 'WooCommerce Bookings is not active.' );
}

$product = new WC_Product_Booking();
$product->set_name( 'Extension compatibility booking' );
$product->set_regular_price( '100' );
$product->set_price( '100' );
$product->set_requires_confirmation( true );
$GLOBALS['product'] = $product;
$wcpay_fresh_cart(
	array(
		'booking' => array(
			'data'     => $product,
			'quantity' => 1,
		),
	)
);

$container = wc_get_container();
$express   = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService::class );
$woopay    = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService::class );

return array(
	'version'                  => $wcpay_extension_version( 'bookings' ),
	'productExpressSupported'  => $wcpay_invoke_private( $express, 'is_product_supported' ),
	'cartExpressSupported'     => $wcpay_invoke_private( $express, 'is_cart_supported' ),
	'productWooPaySupported'   => $wcpay_invoke_private( $woopay, 'is_woopay_product_supported', array( $product ) ),
	'cartWooPaySupported'      => $wcpay_invoke_private( $woopay, 'has_allowed_woopay_cart_items' ),
);
` );

	expect( [ '3.5.3', '3.10.0' ] ).toContain( result.version );
	expect( result.productExpressSupported ).toBe( false );
	expect( result.cartExpressSupported ).toBe( false );
	expect( result.productWooPaySupported ).toBe( false );
	expect( result.cartWooPaySupported ).toBe( false );
} );
