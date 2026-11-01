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
	keepingScrollPosition,
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

test( 'fillCardDetails clicks each card field before typing into it', async ( {
	page,
} ) => {
	const trackedFields =
		CARD_FIELDS_HTML +
		`<script>
			document.querySelectorAll( 'input' ).forEach( ( input ) => {
				input.addEventListener( 'click', () =>
					parent.fieldLog.push( 'click ' + input.id )
				);
				// The first digit key, not the select-all shortcut: fill()
				// sends input events but no key events.
				let typed = false;
				input.addEventListener( 'keydown', ( event ) => {
					if ( ! typed && /^\\d$/.test( event.key ) ) {
						typed = true;
						parent.fieldLog.push( 'key ' + input.id );
					}
				} );
			} );
		</script>`;
	await page.setContent(
		`<script>window.fieldLog = [];</script>
		<div id="wcpay-core-blocks-payment-element">
			<iframe name="__privateStripeFrame123" srcdoc="${ trackedFields.replace(
				/"/g,
				'&quot;'
			) }"></iframe>
		</div>`
	);

	await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );

	expect(
		await page.evaluate(
			() => ( window as unknown as { fieldLog: string[] } ).fieldLog
		)
	).toEqual( [
		'click number',
		'key number',
		'click expiry',
		'key expiry',
		'click cvc',
		'key cvc',
	] );
} );

test( 'fillCardDetails replaces a card the fields already hold', async ( {
	page,
} ) => {
	const heldCard = TEST_CARDS.basic;
	const populatedFields = CARD_FIELDS_HTML.replace(
		'<input id="number" />',
		`<input id="number" value="${ heldCard.number }" />`
	)
		.replace(
			'<input id="expiry" />',
			`<input id="expiry" value="${ heldCard.expiry }" />`
		)
		.replace(
			'<input id="cvc" />',
			`<input id="cvc" value="${ heldCard.cvc }" />`
		);
	await page.setContent(
		`<div id="wcpay-core-blocks-payment-element">
			<iframe name="__privateStripeFrame123" srcdoc="${ populatedFields.replace(
				/"/g,
				'&quot;'
			) }"></iframe>
		</div>`
	);

	await fillCardDetails( page, TEST_CARDS.genericDecline, 'blocks' );

	const frame = page.frameLocator(
		'#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]'
	);
	await expect(
		frame.getByRole( 'textbox', { name: 'Card number' } )
	).toHaveValue( TEST_CARDS.genericDecline.number );
	await expect(
		frame.getByRole( 'textbox', { name: 'Security code' } )
	).toHaveValue( TEST_CARDS.genericDecline.cvc );
} );

test( 'fillCardDetails leaves the page scrolled where the entry found it', async ( {
	page,
} ) => {
	await page.setContent(
		`<div style="height: 3000px"></div>
		<div id="wcpay-core-blocks-payment-element">
			<iframe name="__privateStripeFrame123" srcdoc="${ CARD_FIELDS_HTML.replace(
				/"/g,
				'&quot;'
			) }"></iframe>
		</div>
		<div style="height: 3000px"></div>`
	);
	await page.evaluate( () => window.scrollTo( 0, 100 ) );

	await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );

	expect( await page.evaluate( () => window.scrollY ) ).toBe( 100 );
} );

test( 'keepingScrollPosition scrolls back when the entry throws and keeps its error', async ( {
	page,
} ) => {
	await page.setContent( '<div style="height: 6000px"></div>' );
	await page.evaluate( () => window.scrollTo( 0, 100 ) );

	await expect(
		keepingScrollPosition( page, async () => {
			await page.evaluate( () => window.scrollTo( 0, 2000 ) );
			throw new Error( 'the entry failed' );
		} )
	).rejects.toThrow( 'the entry failed' );

	expect( await page.evaluate( () => window.scrollY ) ).toBe( 100 );
} );

/** A page that reads its scroll position once, then fails every scroll-back. */
function pageWhoseScrollBackRejects( error: Error ): Page {
	let calls = 0;
	return {
		evaluate: async () => {
			calls += 1;
			if ( calls === 1 ) {
				return 0;
			}
			throw error;
		},
	} as unknown as Page;
}

test( 'keepingScrollPosition reports a failed scroll-back after an entry that succeeded', async () => {
	const restoreError = new Error( 'the scroll-back failed' );

	await expect(
		keepingScrollPosition( pageWhoseScrollBackRejects( restoreError ), () =>
			Promise.resolve()
		)
	).rejects.toBe( restoreError );
} );

test( 'keepingScrollPosition keeps the entry error when the scroll-back fails too', async () => {
	const entryError = new Error( 'the entry failed' );

	await expect(
		keepingScrollPosition(
			pageWhoseScrollBackRejects( new Error( 'the scroll-back failed' ) ),
			() => Promise.reject( entryError )
		)
	).rejects.toBe( entryError );
} );

test( 'fillCardDetails fails at once when the click leaves another element focused', async ( {
	page,
} ) => {
	// The field refuses focus, as a click that a layer above it swallows would leave it.
	await page.setContent(
		`<div id="wcpay-core-blocks-payment-element">
			<iframe name="__privateStripeFrame123" srcdoc="${ (
				CARD_FIELDS_HTML +
				"<script>document.getElementById( 'number' ).addEventListener( 'focus', ( e ) => e.target.blur() );</script>"
			).replace( /"/g, '&quot;' ) }"></iframe>
		</div>`
	);

	await expect(
		fillCardDetails( page, TEST_CARDS.basic, 'blocks' )
	).rejects.toThrow( /did not focus/ );
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

// The provider fields used below come from the recorded PaymentIntent and Charge responses in
// `tests/php/src/Internal/Payments/Providers/WooPayments/Fixtures/platform-manual-capture.json`.
const SETTLED_INTENT = {
	status: 'succeeded',
	amount: 1099,
	currency: 'usd',
	payment_method: 'pm_exact',
	charges: { data: [ { id: 'ch_exact' } ] },
};
const SETTLED_CHARGE = {
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
};

/**
 * The routes of one settled card payment for order 501, with single order,
 * intent or charge fields replaced.
 */
function settledCardPaymentRoutes( {
	order = {},
	intent = {},
	charge = {},
}: {
	order?: Record< string, unknown >;
	intent?: Record< string, unknown >;
	charge?: Record< string, unknown >;
} = {} ): Record< string, unknown > {
	return {
		'wc/v3/orders/501': {
			total: '10.99',
			currency: 'USD',
			status: 'processing',
			meta_data: [
				{ key: '_intent_id', value: 'pi_exact' },
				{ key: '_charge_id', value: 'ch_exact' },
				{ key: '_payment_method_id', value: 'pm_exact' },
			],
			...order,
		},
		'wc/v3/payments/payment_intents/pi_exact': {
			...SETTLED_INTENT,
			...intent,
		},
		'wc/v3/payments/charges/ch_exact': { ...SETTLED_CHARGE, ...charge },
		// The client reads the timeline's `data` list (`client/data/timeline/resolvers.js:19`) and its
		// `captured` events (`client/payment-details/timeline/map-events.js:899`).
		'wc/v3/payments/timeline/pi_exact': { data: [ { type: 'captured' } ] },
	};
}

const SETTLED_CARD_PAYMENT = {
	amountMinor: 1099,
	currency: 'USD',
	card: { brand: 'visa', last4: '4242' },
};

test( 'expectSettledCardPayment converges on one settled card payment graph', async () => {
	const restApi = fakeApiClient( settledCardPaymentRoutes() );

	const settled = await expectSettledCardPayment(
		restApi,
		501,
		SETTLED_CARD_PAYMENT
	);

	expect( settled.intentId ).toBe( 'pi_exact' );
	expect( settled.chargeId ).toBe( 'ch_exact' );
	expect( settled.occurrenceCount ).toBe( 1 );
	expect( settled.captureOccurrenceCount ).toBe( 1 );
} );

// Each case breaks one fact of a settled payment.
for ( const { name, intent, charge } of [
	{
		name: 'an intent still awaiting capture',
		intent: { status: 'requires_capture' },
	},
	{ name: 'a charge that was not captured', charge: { captured: false } },
	{ name: 'a charge still pending', charge: { status: 'pending' } },
] ) {
	test( `expectSettledCardPayment never settles on ${ name }`, async () => {
		const restApi = fakeApiClient(
			settledCardPaymentRoutes( { intent, charge } )
		);

		await expect(
			expectSettledCardPayment( restApi, 501, SETTLED_CARD_PAYMENT, {
				budgetMs: 50,
				intervalMs: 10,
			} )
		).rejects.toThrow(
			'Order 501 never reached a stable settled card payment within 50ms.'
		);
	} );
}

// A 9.99 amount where everything else says 10.99.
for ( const { name, order, intent, charge, mismatch } of [
	{
		name: 'order',
		order: { total: '9.99' },
		mismatch: /"orderAmountMinor": 999/,
	},
	{
		name: 'intent',
		intent: { amount: 999 },
		mismatch: /"intentAmount": 999/,
	},
	{
		name: 'charge',
		charge: { amount: 999 },
		mismatch: /"chargeAmount": 999/,
	},
] ) {
	test( `expectSettledCardPayment refuses a settled payment whose ${ name } has the wrong amount`, async () => {
		const restApi = fakeApiClient(
			settledCardPaymentRoutes( { order, intent, charge } )
		);

		await expect(
			expectSettledCardPayment( restApi, 501, SETTLED_CARD_PAYMENT, {
				budgetMs: 5_000,
				intervalMs: 10,
			} )
		).rejects.toThrow( mismatch );
	} );
}
