/**
 * WooPayments native provider helper (T.4 D4).
 *
 * The one module a rewritten provider spec may import beyond the shared e2e
 * surfaces: entering a card into the Payment Element, answering a 3D Secure
 * challenge, refusing to run against a live account, and reading the store's
 * own mirror of the provider's PaymentIntent and Charge objects.
 *
 * Provider command (D16, as actually run against `:8889`):
 * `WCPAY_RUNTIME=native BASE_URL=<store> WP_BASE_URL=<store>
 * ADMIN_USER=admin ADMIN_PASSWORD=<the account's real login password>
 * E2E_WOOPAYMENTS_NATIVE_STORE_URL=<store>
 * E2E_WOOPAYMENTS_NATIVE_STORE_DIR=<checkout>/plugins/woocommerce
 * E2E_WOOPAYMENTS_WP_ENV_CONFIG=<config> E2E_WP_ENV_CONFIG=.wp-env.json
 * E2E_WOOPAYMENTS_DIAGNOSTICS_DIR=<dir> E2E_WOOPAYMENTS_ACCOUNT_ID=<id>
 * pnpm --dir plugins/woocommerce exec playwright test
 * --config=tests/e2e/envs/woopayments-native/playwright.config.ts
 * --project=woopayments-native-provider <spec>`
 * (the variables `env-setup.sh` requires, plus the two below it needs). A
 * spec not yet rewritten still runs through `run-provider-families.sh`.
 *
 * The shared `restApi` fixture sends Basic Auth with the account's own login
 * password, which WordPress core only honours over the REST API when a
 * Basic-Auth plugin is active - install the same one the checked-in e2e
 * profile installs (`.wp-env.e2e.json`'s plugin list): `wp plugin install
 * https://github.com/WP-API/Basic-Auth/archive/master.zip --activate` (via
 * the store's own `wp-env` container), once. That keeps `ADMIN_PASSWORD` the
 * real password, so `wp-login.php` flows (My Account, wp-admin) keep
 * working for the same run. `E2E_WP_ENV_CONFIG` points the shared
 * `wpCLI`/`wpEvalJson` at the same store, which `B1p` needs to force and
 * restore card-testing-protection eligibility directly on the account cache.
 */

import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import type { Locator, Page } from '@playwright/test';

export interface ProviderTestCard {
	number: string;
	expiry: string;
	cvc: string;
}

/**
 * Stripe's documented test numbers the retained cases use, cited to client
 * 11.1.0 `tests/e2e/config/default.ts:188-323`.
 */
export const TEST_CARDS = {
	basic: { number: '4242424242424242', expiry: '0245', cvc: '424' },
	genericDecline: { number: '4000000000000002', expiry: '0245', cvc: '424' },
	threeDSChallenge: {
		number: '4000000000003220',
		expiry: '0245',
		cvc: '424',
	},
	threeDSOtp: { number: '4000002500003155', expiry: '0245', cvc: '424' },
	declinedAfter3DS: {
		number: '4000008400001629',
		expiry: '0245',
		cvc: '424',
	},
	dispute: { number: '4000000000000259', expiry: '0245', cvc: '424' },
} satisfies Record< string, ProviderTestCard >;

const CARD_FRAME_SELECTORS = {
	blocks: '#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]',
	classic:
		'#payment .payment_method_woocommerce_payments #wcpay-core-payment-element iframe[name^="__privateStripeFrame"]',
	'my-account':
		'#wcpay-core-payment-element iframe[name^="__privateStripeFrame"]',
} as const;

export type CardSurface = keyof typeof CARD_FRAME_SELECTORS;

const EXPIRY_FIELD_NAME = /^Expir/i;
const ENTRY_ATTEMPTS = 3;
const ENTRY_SETTLE_MS = 1_500;
const ENTRY_STEP_TIMEOUT_MS = 5_000;

function digitsOnly( value: string ): string {
	return value.replace( /\D/g, '' );
}

/**
 * Types a card into a native Payment Element and proves the element kept it.
 *
 * The element clears every field it holds when its deferred
 * `elements/sessions` response arrives, which can land after typing already
 * finished; a caller that types once and moves on may submit an empty card.
 * The entry is read back and retyped until the element holds exactly what
 * was typed, so a submission built on it is a precondition rather than a
 * hope.
 */
export async function fillCardDetails(
	page: Page,
	card: ProviderTestCard,
	surface: CardSurface
): Promise< void > {
	const frame = page.frameLocator( CARD_FRAME_SELECTORS[ surface ] );
	const fields: Array< { label: string; locator: Locator; value: string } > =
		[
			{
				label: 'card number',
				locator: frame.getByRole( 'textbox', {
					name: 'Card number',
				} ),
				value: card.number,
			},
			{
				label: 'expiry',
				locator: frame.getByRole( 'textbox', {
					name: EXPIRY_FIELD_NAME,
				} ),
				value: card.expiry,
			},
			{
				label: 'security code',
				locator: frame.getByRole( 'textbox', {
					name: 'Security code',
				} ),
				value: card.cvc,
			},
		];

	let lastProblem = '';
	for ( let attempt = 1; attempt <= ENTRY_ATTEMPTS; attempt += 1 ) {
		try {
			for ( const field of fields ) {
				await field.locator.fill( field.value, {
					timeout: ENTRY_STEP_TIMEOUT_MS,
				} );
			}
			await new Promise( ( resolve ) =>
				setTimeout( resolve, ENTRY_SETTLE_MS )
			);
			const lost: string[] = [];
			for ( const field of fields ) {
				const kept = digitsOnly(
					await field.locator.inputValue( {
						timeout: ENTRY_STEP_TIMEOUT_MS,
					} )
				);
				if ( kept !== digitsOnly( field.value ) ) {
					lost.push( field.label );
				}
			}
			if ( lost.length === 0 ) {
				return;
			}
			lastProblem = `dropped ${ lost.join( ', ' ) }`;
		} catch ( error ) {
			lastProblem = `could not be read back (${
				error instanceof Error
					? error.message.split( '\n' )[ 0 ]
					: error
			})`;
		}
	}
	throw new Error(
		`WooPayments ${ surface } payment element kept no complete card after ${ ENTRY_ATTEMPTS } entries - the last one ${ lastProblem } - so a submission would carry an incomplete card.`
	);
}

const OUTER_FRAME = 'body > div > iframe[name^="__privateStripeFrame"]';
const CHALLENGE_FRAME = 'iframe[name="stripe-challenge-frame"]';
const LOADING_INDICATOR = '.LightboxModalLoadingIndicator';
const CHALLENGE_FRAME_TIMEOUT_MS = 20_000;
const CHALLENGE_SETTLE_TIMEOUT_MS = 20_000;

/**
 * Answers the provider's 3D Secure challenge and proves one was actually
 * presented.
 *
 * A helper that returns silently when the challenge iframe never appears
 * would pass whether or not a challenge happened - one of the contracts this
 * suite has previously recorded as unable to fail. Here an expected
 * challenge that never shows is an error, not a frictionless pass.
 */
export async function completeThreeDSChallenge(
	page: Page,
	answer: 'complete' | 'fail'
): Promise< void > {
	await page
		.locator( OUTER_FRAME )
		.waitFor( { state: 'visible', timeout: CHALLENGE_FRAME_TIMEOUT_MS } );
	const outer = page.frameLocator( OUTER_FRAME );
	const challengeBody = outer
		.frameLocator( CHALLENGE_FRAME )
		.locator( 'body' );
	await challengeBody.waitFor( {
		state: 'visible',
		timeout: CHALLENGE_FRAME_TIMEOUT_MS,
	} );
	await outer
		.locator( LOADING_INDICATOR )
		.waitFor( { state: 'hidden', timeout: CHALLENGE_SETTLE_TIMEOUT_MS } )
		.catch( () => undefined );
	await outer
		.frameLocator( CHALLENGE_FRAME )
		.getByRole( 'button', {
			name: answer === 'complete' ? 'Complete' : 'Fail',
			exact: true,
		} )
		.click();
	await challengeBody.waitFor( {
		state: 'hidden',
		timeout: CHALLENGE_SETTLE_TIMEOUT_MS,
	} );
}

const ACCOUNTS_ROUTE = 'wc/v3/payments/accounts';
const SETTINGS_ROUTE = 'wc/v3/payments/settings';

/**
 * The provider-write safety guard (D5): fails before any checkout unless the
 * account reports test mode, the gateway's own `test_mode` setting agrees,
 * the account carries an id, and - when `E2E_WOOPAYMENTS_ACCOUNT_ID` is set -
 * that id matches it.
 */
export async function requireTestModeAccount(
	restApi: ApiClient
): Promise< void > {
	const account = ( await restApi.get( ACCOUNTS_ROUTE ) ).data as Record<
		string,
		unknown
	>;
	const settings = ( await restApi.get( SETTINGS_ROUTE ) ).data as Record<
		string,
		unknown
	>;
	if (
		account.test_mode !== true ||
		settings.is_test_mode_enabled !== true
	) {
		throw new Error(
			'WooPayments is not confirmed to be running in test mode; refusing to run a provider write.'
		);
	}
	const accountId = account.account_id;
	if ( typeof accountId !== 'string' || accountId === '' ) {
		throw new Error(
			'WooPayments account carries no account id; refusing to run a provider write.'
		);
	}
	const expectedAccountId = process.env.E2E_WOOPAYMENTS_ACCOUNT_ID;
	if ( expectedAccountId && accountId !== expectedAccountId ) {
		throw new Error(
			`WooPayments account id ${ accountId } does not match E2E_WOOPAYMENTS_ACCOUNT_ID ${ expectedAccountId }; refusing to run a provider write.`
		);
	}
}

/**
 * Reads the store's own mirror of a provider PaymentIntent
 * (`WooPaymentsPaymentDetailsRestController.php:102-106`), so a case's
 * intent assertions read what the store records without a second transport.
 */
export async function getPaymentIntent(
	restApi: ApiClient,
	intentId: string
): Promise< Record< string, unknown > > {
	return (
		await restApi.get(
			`wc/v3/payments/payment_intents/${ encodeURIComponent( intentId ) }`
		)
	).data as Record< string, unknown >;
}

/** Reads the store's own mirror of a provider Charge, the same way. */
export async function getCharge(
	restApi: ApiClient,
	chargeId: string
): Promise< Record< string, unknown > > {
	return (
		await restApi.get(
			`wc/v3/payments/charges/${ encodeURIComponent( chargeId ) }`
		)
	).data as Record< string, unknown >;
}
