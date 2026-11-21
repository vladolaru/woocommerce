import { expect, test } from '@playwright/test';

import { wpEvalJson } from '../../../utils/woopayments-cli';

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

const SETUP_TIER_OPTION = 'woocommerce_woopayments_setup_tier';

/**
 * Write the built-in WooPayments setup tier (or delete the option for a store never set up) and return the value it
 * held, so the case can put it back. The facade loader reads this tier at `plugins_loaded`, so it takes effect for the
 * next probe's WordPress bootstrap. A store with a platform account holds `connected` or `active`; the compatibility
 * profile has no account, so the connected case writes the tier itself.
 */
function setSetupTier( tier: string | null ): Promise< string | null > {
	const tierPhp = tier === null ? 'null' : `'${ tier }'`;

	return wpEvalJson< string | null >( `
$previous = get_option( '${ SETUP_TIER_OPTION }', null );
$tier     = ${ tierPhp };
if ( null === $tier ) {
	delete_option( '${ SETUP_TIER_OPTION }' );
} else {
	update_option( '${ SETUP_TIER_OPTION }', $tier, true );
}
return is_string( $previous ) ? $previous : null;
` );
}

/**
 * Read what PayPal Payments' onboarding profile decides and what the WooPayments extension names it probes, in one
 * request, as the plugin does at its own bootstrap.
 */
function probePayPalOnboarding() {
	return runExtensionCompatProbe< {
		version: string;
		flags: Record< string, boolean >;
		card_eligible: boolean | null;
		facade: { wc_payments: boolean; features: boolean; version: boolean };
	} >( String.raw`
if ( ! class_exists( 'WooCommerce\\PayPalCommerce\\PPCP' ) ) {
	throw new RuntimeException( 'PayPal Payments is not active.' );
}

$container = WooCommerce\PayPalCommerce\PPCP::container();
$profile   = $container->get( 'settings.data.onboarding' );

return array(
	'version'       => $wcpay_extension_version( 'paypal-payments' ),
	'flags'         => $profile->get_flags(),
	'card_eligible' => $container->has( 'card-fields.eligible' ) ? (bool) $container->get( 'card-fields.eligible' ) : null,
	'facade'        => array(
		'wc_payments' => class_exists( 'WC_Payments' ),
		'features'    => class_exists( 'WC_Payments_Features' ),
		'version'     => defined( 'WCPAY_VERSION_NUMBER' ),
	),
);
` );
}

test( '@woopayments-extension-compat PayPal keeps its card step on a store with no platform account, where the built-in WooPayments declares nothing', async () => {
	const previousTier = await setSetupTier( null );
	try {
		const result = await probePayPalOnboarding();

		expect( [ '2.9.6', '4.1.3' ] ).toContain( result.version );
		// No WC_Payments surface exists before an account is connected, so PayPal does not read WooPayments as present.
		expect( result.facade ).toEqual( {
			wc_payments: false,
			features: false,
			version: false,
		} );
		// PayPal keeps its card step. The 2.9.6 flag follows its own country eligibility, which the fixture store (US or
		// GB) meets; 4.1.3 reads whether to skip the payment-methods step.
		const expectedCardStepByVersion = {
			'2.9.6': { can_use_card_payments: true },
			'4.1.3': { should_skip_payment_methods: false },
		} as const;
		expect( result.flags ).toMatchObject(
			expectedCardStepByVersion[
				result.version as keyof typeof expectedCardStepByVersion
			]
		);
		expect( result.card_eligible ).toBe(
			{ '2.9.6': true, '4.1.3': null }[
				result.version as '2.9.6' | '4.1.3'
			]
		);
	} finally {
		await setSetupTier( previousTier );
	}
} );

test( '@woopayments-extension-compat PayPal suppresses competing wallet onboarding when native WooPayments is present', async () => {
	const previousTier = await setSetupTier( 'connected' );
	try {
		const result = await probePayPalOnboarding();

		expect( [ '2.9.6', '4.1.3' ] ).toContain( result.version );
		// Once an account is connected the surface exists: both classes and the version constant.
		expect( result.facade ).toEqual( {
			wc_payments: true,
			features: true,
			version: true,
		} );
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
	} finally {
		await setSetupTier( previousTier );
	}
} );
