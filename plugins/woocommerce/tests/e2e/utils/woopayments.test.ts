/**
 * Unit coverage for `utils/woopayments.ts` (T.4 D4).
 *
 * One happy-path row per browser-facing function, reproducing the exact
 * Stripe frame names and field labels the helper selects on, plus the two
 * rows that prove the helper cannot pass by accident: a missing 3DS
 * challenge, and a live (non-test-mode) account. No provider run backs these
 * - that proof is the specs that call the helper on `:8889`.
 *
 * Run with `pnpm --dir plugins/woocommerce exec playwright test
 * --config=tests/e2e/envs/woopayments-native/unit.playwright.config.ts`.
 */

import { test, expect, type Page } from '@playwright/test';

import {
	completeThreeDSChallenge,
	expectSettledCardPayment,
	fillCardDetails,
	requireTestModeAccount,
	TEST_CARDS,
} from './woopayments';

/** A minimal `ApiClient` stub: only `.get()` is used by `requireTestModeAccount`. */
function fakeApiClient( routes: Record< string, unknown > ) {
	return {
		get: async ( path: string ) => {
			if ( ! ( path in routes ) ) {
				throw new Error( `Unstubbed route: ${ path }` );
			}
			return { data: routes[ path ] };
		},
	} as never;
}

const CARD_FIELDS_HTML = `
	<label for="number">Card number</label>
	<input id="number" />
	<label for="expiry">Expiration date</label>
	<input id="expiry" />
	<label for="cvc">Security code</label>
	<input id="cvc" />
`;

test( 'fillCardDetails enters and keeps a card in a Blocks Payment Element', async ( {
	page,
} ) => {
	await page.setContent(
		`<div id="wcpay-core-blocks-payment-element">
			<iframe name="__privateStripeFrame123" srcdoc="${ CARD_FIELDS_HTML.replace(
				/"/g,
				'&quot;'
			) }"></iframe>
		</div>`
	);

	await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );

	const frame = page.frameLocator(
		'#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]'
	);
	await expect(
		frame.getByRole( 'textbox', { name: 'Card number' } )
	).toHaveValue( TEST_CARDS.basic.number );
} );

// Answering hides the challenge body itself, the way the real Stripe
// challenge dismisses on an answer; `completeThreeDSChallenge` waits for
// exactly that, so a test double that never hides would time the case out
// rather than let a broken click pass silently.
const CHALLENGE_HTML =
	'<button onclick="document.body.hidden=true">Complete</button>' +
	'<button onclick="document.body.hidden=true">Fail</button>';

/**
 * Builds the outer Stripe frame and, optionally, its nested challenge
 * frame, through DOM APIs rather than hand-escaped `srcdoc` attribute
 * strings: a two-level nested `srcdoc` string is exactly the kind of
 * escaping this suite has been bitten by before, and assigning the
 * `.srcdoc` element property sidesteps it entirely.
 */
async function buildOuterFrame(
	page: Page,
	challengeHtml: string | null
): Promise< void > {
	await page.setContent( '<div id="host"></div>' );
	await page.evaluate(
		( { innerHtml } ) => {
			const outer = document.createElement( 'iframe' );
			outer.name = '__privateStripeFrame456';
			if ( innerHtml !== null ) {
				const inner = document.createElement( 'iframe' );
				inner.name = 'stripe-challenge-frame';
				inner.srcdoc = innerHtml;
				outer.srcdoc = inner.outerHTML;
			} else {
				outer.srcdoc = '<p>no challenge here</p>';
			}
			document.getElementById( 'host' )?.appendChild( outer );
		},
		{ innerHtml: challengeHtml }
	);
}

test( 'completeThreeDSChallenge answers a presented challenge', async ( {
	page,
} ) => {
	await buildOuterFrame( page, CHALLENGE_HTML );

	await expect(
		completeThreeDSChallenge( page, 'complete' )
	).resolves.toBeUndefined();
} );

test( 'completeThreeDSChallenge fails when no challenge is presented', async ( {
	page,
} ) => {
	// An outer Stripe frame with no inner challenge frame: the frictionless
	// path a permissive helper could silently accept.
	await buildOuterFrame( page, null );

	await expect(
		completeThreeDSChallenge( page, 'complete' )
	).rejects.toThrow();
} );

test( 'requireTestModeAccount passes for a confirmed test-mode account', async () => {
	const restApi = fakeApiClient( {
		'wc/v3/payments/accounts': {
			test_mode: true,
			account_id: 'acct_test123',
		},
		'wc/v3/payments/settings': { is_test_mode_enabled: true },
	} );

	await expect( requireTestModeAccount( restApi ) ).resolves.toBeUndefined();
} );

test( 'requireTestModeAccount refuses a live account', async () => {
	const restApi = fakeApiClient( {
		'wc/v3/payments/accounts': {
			test_mode: false,
			account_id: 'acct_live123',
		},
		'wc/v3/payments/settings': { is_test_mode_enabled: false },
	} );

	await expect( requireTestModeAccount( restApi ) ).rejects.toThrow(
		/test mode/
	);
} );

test( 'expectSettledCardPayment converges on one settled card payment graph', async () => {
	const restApi = fakeApiClient( {
		'wc/v3/orders/501': {
			total: '10.99',
			currency: 'USD',
			status: 'processing',
			meta_data: [
				{ key: '_intent_id', value: 'pi_exact' },
				{ key: '_charge_id', value: 'ch_exact' },
				{ key: '_payment_method_id', value: 'pm_exact' },
			],
		},
		'wc/v3/payments/payment_intents/pi_exact': {
			status: 'succeeded',
			amount: 1099,
			currency: 'usd',
			payment_method: 'pm_exact',
			charges: { data: [ { id: 'ch_exact' } ] },
		},
		'wc/v3/payments/charges/ch_exact': {
			status: 'succeeded',
			captured: true,
			amount: 1099,
			currency: 'usd',
			payment_intent: 'pi_exact',
			payment_method: 'pm_exact',
			payment_method_details: {
				type: 'card',
				card: { brand: 'visa', last4: '4242' },
			},
		},
		'wc/v3/payments/timeline/pi_exact': { data: [ { type: 'captured' } ] },
	} );

	const settled = await expectSettledCardPayment( restApi, 501, {
		amountMinor: 1099,
		currency: 'USD',
		card: { brand: 'visa', last4: '4242' },
	} );

	expect( settled.intentId ).toBe( 'pi_exact' );
	expect( settled.chargeId ).toBe( 'ch_exact' );
	expect( settled.occurrenceCount ).toBe( 1 );
	expect( settled.captureOccurrenceCount ).toBe( 1 );
} );
