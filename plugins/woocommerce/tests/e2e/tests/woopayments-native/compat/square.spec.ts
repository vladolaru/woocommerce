import { expect, test } from '@playwright/test';

import { wpEvalJson } from '../../../utils/cli';

const COMMON_PROBE_PHP = String.raw`
$wcpay_extension_profile = get_option( 'e2e_woopayments_extension_compat_profile', array() );
if ( ! is_array( $wcpay_extension_profile ) || ! isset( $wcpay_extension_profile['pin_set'], $wcpay_extension_profile['versions'] ) || ! is_array( $wcpay_extension_profile['versions'] ) ) {
	throw new RuntimeException( 'The WooPayments extension compatibility profile marker is missing.' );
}
$wcpay_extension_version = static function ( string $extension ) use ( $wcpay_extension_profile ): string {
	$version = $wcpay_extension_profile['versions'][ $extension ] ?? '';
	if ( ! is_string( $version ) || '' === $version ) {
		throw new RuntimeException( sprintf( 'The extension compatibility profile has no %s version.', $extension ) );
	}
	return $version;
};
$wcpay_invoke_private = static function ( object $target, string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( $target, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $target, $arguments );
};
$wcpay_fresh_cart = static function ( array $contents ): WC_Cart {
	$cart                = new WC_Cart();
	$cart->cart_contents = $contents;
	WC()->cart           = $cart;
	return $cart;
};
`;

/**
 * Run a provider-free extension integration probe through the shared
 * `wpEvalJson` runner, with the profile's PHP prelude prepended and its
 * settings-feature flag env var set ahead of bootstrap.
 */
function runExtensionCompatProbe< Result >(
	phpBody: string
): Promise< Result > {
	return wpEvalJson< Result >( `${ COMMON_PROBE_PHP }\n${ phpBody }`, [
		'PCP_SETTINGS_ENABLED=1',
	] );
}

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
