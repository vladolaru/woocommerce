import { expect, test } from '@playwright/test';

import { wpCLI, wpEvalJson } from '../../../utils/cli';

// The native multi-currency facade this probe requires (`WCPay\MultiCurrency\MultiCurrency`)
// only declares itself once the store has opted into WooCommerce's independent multi-currency
// feature, which defaults to off. That is store configuration, not part of what this probe
// asserts, so it is set up here and restored after, the way `checkout.spec.ts` handles its own
// settings through `updateIfNeeded`/`resetValue`.
const MULTI_CURRENCY_FEATURE_OPTION =
	'woocommerce_feature_multi_currency_enabled';
const MULTI_CURRENCY_FEATURE_ABSENT_MARKER =
	'__woopayments_native_e2e_multi_currency_feature_absent__';

let initialMultiCurrencyFeatureValue: string;

test.beforeAll( async () => {
	initialMultiCurrencyFeatureValue = (
		await wpCLI( [
			'wp',
			'eval',
			`echo get_option( '${ MULTI_CURRENCY_FEATURE_OPTION }', '${ MULTI_CURRENCY_FEATURE_ABSENT_MARKER }' );`,
		] )
	).stdout.trim();
	await wpCLI( [
		'wp',
		'option',
		'update',
		MULTI_CURRENCY_FEATURE_OPTION,
		'yes',
	] );
} );

test.afterAll( async () => {
	if (
		initialMultiCurrencyFeatureValue ===
		MULTI_CURRENCY_FEATURE_ABSENT_MARKER
	) {
		await wpCLI( [
			'wp',
			'option',
			'delete',
			MULTI_CURRENCY_FEATURE_OPTION,
		] );
	} else {
		await wpCLI( [
			'wp',
			'option',
			'update',
			MULTI_CURRENCY_FEATURE_OPTION,
			initialMultiCurrencyFeatureValue,
		] );
	}
} );

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
