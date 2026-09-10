import { expect, test } from '@playwright/test';

import { runExtensionCompatProbe } from './wp-cli';

test.skip(
	process.env.E2E_WOOPAYMENTS_EXTENSION_COMPAT !== 'true',
	'The external-extension compatibility profile is opt-in.'
);

test( '@woopayments-extension-compat Square suppresses native express methods for gift cards', async () => {
	const result = await runExtensionCompatProbe< {
		version: string;
		productExpressSupported: boolean;
		cartExpressSupported: boolean;
		productWooPaySupported: boolean;
		cartWooPaySupported: boolean;
	} >( String.raw`
if ( ! class_exists( 'WooCommerce\\Square\\WC_Payments_Compatibility' ) ) {
	throw new RuntimeException( 'WooCommerce Square is not active.' );
}

$product = new WC_Product_Simple();
$product->set_name( 'Extension compatibility gift card' );
$product->set_regular_price( '50' );
$product->set_price( '50' );
$product->update_meta_data( '_square_gift_card', 'yes' );
$GLOBALS['product'] = $product;
$wcpay_fresh_cart(
	array(
		'gift-card' => array(
			'data'     => $product,
			'quantity' => 1,
		),
	)
);

$container = wc_get_container();
$express   = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService::class );
$woopay    = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService::class );

return array(
	'version'                  => $wcpay_extension_version( 'square' ),
	'productExpressSupported'  => $wcpay_invoke_private( $express, 'is_product_supported' ),
	'cartExpressSupported'     => $wcpay_invoke_private( $express, 'is_cart_supported' ),
	'productWooPaySupported'   => $wcpay_invoke_private( $woopay, 'is_woopay_product_supported', array( $product ) ),
	'cartWooPaySupported'      => $wcpay_invoke_private( $woopay, 'has_allowed_woopay_cart_items' ),
);
` );

	expect( [ '4.7.4', '5.5.0' ] ).toContain( result.version );
	expect( result.productExpressSupported ).toBe( false );
	expect( result.cartExpressSupported ).toBe( false );
	expect( result.productWooPaySupported ).toBe( false );
	expect( result.cartWooPaySupported ).toBe( false );
} );
