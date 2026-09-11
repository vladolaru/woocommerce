import { expect, test } from '@playwright/test';

import { runExtensionCompatProbe } from './wp-cli';

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
