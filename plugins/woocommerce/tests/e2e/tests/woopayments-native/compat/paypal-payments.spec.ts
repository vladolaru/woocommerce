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

test( '@woopayments-extension-compat PayPal suppresses competing wallet onboarding when native WooPayments is present', async () => {
	const result = await runExtensionCompatProbe< {
		version: string;
		flags: Record< string, boolean >;
	} >( String.raw`
if ( ! class_exists( 'WooCommerce\\PayPalCommerce\\PPCP' ) || ! class_exists( 'WC_Payments' ) ) {
	throw new RuntimeException( 'PayPal Payments or the native WooPayments facade is not active.' );
}

$profile = WooCommerce\PayPalCommerce\PPCP::container()->get( 'settings.data.onboarding' );

return array(
	'version' => $wcpay_extension_version( 'paypal-payments' ),
	'flags'   => $profile->get_flags(),
);
` );

	expect( [ '2.9.6', '4.1.3' ] ).toContain( result.version );
	const expectedSuppressionByVersion = {
		'2.9.6': { can_use_card_payments: false },
		'4.1.3': { should_skip_payment_methods: true },
	} as const;
	const expectedDigitalWalletFlagByVersion = {
		'2.9.6': false,
		'4.1.3': true,
	} as const;
	const expectedSuppression =
		expectedSuppressionByVersion[
			result.version as keyof typeof expectedSuppressionByVersion
		];
	expect( result.flags ).toMatchObject( expectedSuppression );
	expect( Object.hasOwn( result.flags, 'can_use_digital_wallets' ) ).toBe(
		expectedDigitalWalletFlagByVersion[
			result.version as keyof typeof expectedDigitalWalletFlagByVersion
		]
	);
} );
