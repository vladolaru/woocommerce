import type {
	APIRequestContext,
	APIResponse,
	BrowserContext,
	Page,
} from '@playwright/test';

import {
	expect,
	ResourceQuarantineRequiredError,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	ALIPAY,
	driveClassicRedirectCheckout,
	readReturnUrlFacts,
	readShopperCartState,
	setShopperSessionCurrency,
	withEnabledPaymentMethod,
	withForeignCurrency,
	type RedirectHandoffObservation,
	type ClassicRedirectFollowMode,
	type RedirectIntentRequest,
	type RedirectMethod,
} from '../../../utils/woopayments-native/drivers/redirect-methods';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

/**
 * The `redirect-method-provider-outcome` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * This family kept one browser smoke, `A1` (Alipay on the classic shortcode
 * checkout), which still asserts its own full request shape unchanged
 * (`expectRequestedRedirect`). Every other case the family used to
 * browser-test (affirm, afterpay_clearpay, bancontact, klarna, and the
 * protection-on twins) was deleted, and its assertions moved to PHPUnit and
 * Jest, cited at each case's `woopayments-contract` annotation: the exact
 * method/amount/currency/return-URL request shape now lives in
 * `WooPaymentsProviderGatewayAdapterTest::test_charge_sends_split_redirect_method_request`
 * (which also independently re-proves `A1`'s alipay shape at a lower layer),
 * the redirect-return settlement in
 * `WooPaymentsRedirectReturnControllerTest::test_handle_wp_confirms_redirect_method_return`,
 * the `<method>_handle_redirect` confirmation-hash mapping in
 * `WooPaymentsIntentCodecTest::test_outcome_from_intention_maps_method_handle_redirect_to_confirmation_hash`,
 * and card-testing-protection admission/refusal on split gateways in
 * `NativeWooPaymentsGatewayTest`.
 *
 * What `A1` still proves that no lower layer can: the client-side
 * `alipay_handle_redirect` handoff (no server path performs it,
 * `class-wc-payment-gateway-wcpay.php:2106-2114` at WooPayments 11.1.0) and
 * the real Stripe round trip back into
 * `WooPaymentsRedirectReturnController::handle_wp`.
 */

/** Grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:redirect-method-provider-outcome';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

/** The journey capability every case in this family needs. */
const CAPABILITY_FAMILY = 'redirect-method-provider-outcome';
/** The capability for mutating the enabled-payment-method set. */
const CAPABILITY_METHOD = 'redirect-method-provider-outcome-method';
/** The capability for mutating the enabled-currency configuration. */
const CAPABILITY_CURRENCY = 'redirect-method-provider-outcome-currency';
const CAPABILITY_PRODUCT = 'product/payment';
const CAPABILITY_CLASSIC_PAGE = 'classic-checkout-page';

const CLASSIC_CAPABILITIES = [
	CAPABILITY_FAMILY,
	CAPABILITY_PRODUCT,
	CAPABILITY_CLASSIC_PAGE,
	CAPABILITY_METHOD,
];
const CLASSIC_CURRENCY_CAPABILITIES = [
	...CLASSIC_CAPABILITIES,
	CAPABILITY_CURRENCY,
];

const CONTRACT_A1 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/alipay-checkout-purchase.spec.ts:60::Alipay Checkout › checkout on shortcode checkout page';

const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

/** The claim's convergence rule for `A1`-`A4` and the twins: every 2 seconds. */
const POLL_INTERVAL_MS = 2_000;
const SETTLE_BUDGET_MS = 90_000;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

/**
 * Asserts the whole capability set before any provider interval opens, so an
 * incomplete approval costs nothing rather than a paid run.
 */
function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: readonly string[]
): void {
	for ( const capability of capabilities ) {
		session.requireApprovedProviderFixture( capability );
	}
}

async function readJson< Result >(
	response: APIResponse,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

async function readStoreDefaultCurrency(
	restApi: APIRequestContext
): Promise< string > {
	const setting = await readJson< { value?: unknown } >(
		await restApi.get(
			'/wp-json/wc/v3/settings/general/woocommerce_currency'
		),
		'store currency setting read'
	);
	if ( typeof setting.value !== 'string' || ! setting.value.trim() ) {
		throw new Error( 'The store exposed no configured default currency.' );
	}
	return setting.value.toUpperCase();
}

async function readCardTestingProtectionEligibility(
	restApi: APIRequestContext
): Promise< unknown > {
	const account = await readJson< {
		card_testing_protection_eligible?: unknown;
	} >(
		await restApi.get( '/wp-json/wc/v3/payments/accounts' ),
		'WooPayments account read'
	);
	return account.card_testing_protection_eligible;
}

/**
 * The protection-off cases run against a store that is genuinely unprotected,
 * asserted rather than assumed.
 *
 * The residual risk two of these rows record is precisely "a leaked enabled CTP
 * state can make this nominal false case fail or misclassify behavior", so a
 * protection-off case that ran under protection would be the falsifier, not the
 * fixture.
 */
async function expectProtectionOff(
	session: ProviderWriteSession
): Promise< void > {
	expect(
		await readCardTestingProtectionEligibility( session.adminApi ),
		'this is a protection-off case and must not run against a protected store'
	).toBe( false );
}

interface OrderFacts {
	status: string;
	orderKey: string;
	total: string;
	currency: string;
	intentId: string;
	chargeId: string;
}

async function readOrderFacts(
	restApi: APIRequestContext,
	orderId: number
): Promise< OrderFacts > {
	const order = await readJson< Record< string, unknown > >(
		await restApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
		`WooCommerce order ${ orderId }`
	);
	const meta = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const read = ( key: string ): string => {
		const entry = meta.find( ( item ) => item.key === key );
		return typeof entry?.value === 'string' ? entry.value : '';
	};

	return {
		status: String( order.status ?? '' ),
		orderKey: String( order.order_key ?? '' ),
		total: String( order.total ?? '' ),
		currency: String( order.currency ?? '' ).toUpperCase(),
		intentId: read( '_intent_id' ),
		chargeId: read( '_charge_id' ),
	};
}

/**
 * The claim's convergence rule: poll the exact intent and charge every two
 * seconds for at most ninety, and pass only after two consecutive reads return
 * the same terminal identities, amounts, currencies and statuses.
 */
async function waitForSettledRedirect(
	session: ProviderWriteSession,
	orderId: number,
	intentId: string
): Promise< PaymentEvidence > {
	const deadline = Date.now() + SETTLE_BUDGET_MS;
	let previous: PaymentEvidence | undefined;
	let lastError: unknown;

	for (;;) {
		let current: PaymentEvidence | undefined;
		try {
			current = await getPaymentEvidence( session.adminApi, orderId );
		} catch ( error ) {
			lastError = error;
			current = undefined;
		}

		if ( current && current.providerStatus === 'succeeded' ) {
			if ( current.intentId !== intentId ) {
				throw new ResourceQuarantineRequiredError(
					`Order ${ orderId } settled on intent ${ current.intentId }, not the intent ${ intentId } the handoff created.`,
					'uncertain-provider-write'
				);
			}
			if (
				previous &&
				JSON.stringify( previous ) === JSON.stringify( current )
			) {
				return current;
			}
			previous = current;
		} else {
			previous = undefined;
		}

		if ( Date.now() >= deadline ) {
			throw new ResourceQuarantineRequiredError(
				`Intent ${ intentId } for order ${ orderId } never produced two identical settled reads within ${ SETTLE_BUDGET_MS }ms.`,
				'uncertain-provider-write',
				lastError
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/**
 * Asserts what the store handed the shopper to reach the provider with.
 *
 * There are two shapes, and which one applies is decided by the provider, not by
 * this store. When the intent's next action is the generic `redirect_to_url`,
 * both runtimes answer Place order with the hosted URL itself and the browser
 * navigates to it — so the store's answer must *be* the redirect the intent
 * names, and that identity is asserted.
 *
 * When the provider models the method explicitly it emits its own next-action
 * key — Alipay gives `alipay_handle_redirect` — and neither runtime recognises
 * it. `WooPaymentsIntentCodec::raw_next_action_redirect_url()` returns `''` for
 * any type but `redirect_to_url`, so `requires_confirmation_redirect()` holds
 * and the adapter substitutes the `#wcpay-confirm-pi:` hash instead; the
 * WooPayments client plugin has the identical branch at
 * `class-wc-payment-gateway-wcpay.php:2093-2107`, with `*_handle_redirect`
 * falling into the same `else`. The provider's own script then performs the
 * handoff client-side. Native is at parity, so this case asserts the parity
 * rather than a server-side handoff neither runtime does.
 *
 * The hash branch carries a tripwire. It requires the answer to be the
 * confirmation hash for this exact order and *not* the hosted URL, so if either
 * runtime ever starts handing back the provider redirect for these methods this
 * case fails and says to restore the identity assertion above rather than
 * quietly keeping the weaker one. The hash also carries the intent's client
 * secret, which is a credential for this one payment: its segment count is
 * checked, its value never leaves this function.
 */
function expectStoreHandoff(
	observation: RedirectHandoffObservation,
	request: RedirectIntentRequest,
	handed: URL,
	hosted: URL,
	expected: { storeOrigin: string }
): void {
	if ( request.nextActionType === 'redirect_to_url' ) {
		expect(
			`${ handed.origin }${ handed.pathname }`,
			'the store must hand the shopper the exact redirect the intent names'
		).toBe( `${ hosted.origin }${ hosted.pathname }` );
		return;
	}

	const segments = handed.hash.split( ':' );
	expect(
		segments[ 0 ],
		`neither runtime reads ${ request.nextActionType } as a redirect, so the store must hand back the local confirmation hash and let the provider script do the handoff`
	).toBe( '#wcpay-confirm-pi' );
	expect(
		Number( segments[ 1 ] ),
		'the confirmation hash must name the order this submission created'
	).toBe( observation.orderId );
	expect(
		segments.length,
		'the confirmation hash must carry its order, client secret and nonce'
	).toBeGreaterThanOrEqual( 4 );
	expect(
		`${ handed.origin }${ handed.pathname }`,
		'the store answered with an off-store redirect for a method whose next action neither runtime reads: the parity scoping below is stale, so restore the exact-redirect assertion above'
	).toBe( `${ expected.storeOrigin }/` );
}

/**
 * Asserts the return URL half of the request, which also has two shapes.
 *
 * Under the generic `redirect_to_url` next action the provider echoes the
 * merchant return URL native supplied, so it is read directly: this store's
 * origin, this order, this order key, the WooPayments gateway marker, and the
 * redirect-return nonce native signs it with. That is what makes this run's
 * handoff this run's, and the nonce's value never leaves `readReturnUrlFacts`.
 *
 * Under a `*_handle_redirect` next action the provider does not echo it. It
 * interposes its own return hop — an Alipay intent carries
 * `https://pm-redirects.stripe.com/return/<account>/<nonce>` — and the merchant
 * URL appears nowhere on the intent, not even as a top-level `return_url`
 * (the platform's PaymentIntent passthrough has no such field; its key set was
 * read on 2026-08-14 to be sure). So for these methods the request-side
 * assertion is what is actually observable — the hop is the provider's own,
 * over HTTPS, and off this store — and the run-identifying half is carried by
 * `expectReturnedToStore`, which asserts the *landed* URL's origin, order ID,
 * order key and gateway marker. That is the stronger evidence anyway: it proves
 * the shopper came back where native asked, rather than that a field said so.
 *
 * The tripwire is the off-store requirement. If the provider ever starts
 * echoing the merchant URL for these methods, this fails and says to restore
 * the direct read above rather than keep the weaker one.
 */
function expectRequestedReturnUrl(
	observation: RedirectHandoffObservation,
	request: RedirectIntentRequest,
	expected: { storeOrigin: string; orderKey: string }
): void {
	if ( request.nextActionType !== 'redirect_to_url' ) {
		const hop = new URL( request.returnUrl );
		expect(
			hop.protocol,
			'the provider return hop must be over HTTPS'
		).toBe( 'https:' );
		expect(
			hop.origin,
			`the provider echoed a return URL on this store for ${ request.nextActionType }: it no longer interposes its own hop, so restore the direct return-URL assertion`
		).not.toBe( expected.storeOrigin );
		return;
	}

	const returnUrl = readReturnUrlFacts( request.returnUrl );
	expect(
		returnUrl.origin,
		'the provider must have been given a return URL on this store'
	).toBe( expected.storeOrigin );
	expect(
		returnUrl.orderId,
		'the return URL must name the order this submission created'
	).toBe( observation.orderId );
	expect( returnUrl.orderKey ).toBe( expected.orderKey );
	expect( returnUrl.paymentMethod ).toBe( WOOPAYMENTS_GATEWAY );
	expect(
		returnUrl.noncePresent,
		'the return URL must carry the redirect-return nonce native signs it with'
	).toBe( true );
}

/**
 * The request half of the claim: the exact method, minor amount, currency and
 * run return URL the provider actually received, read from the intent while it
 * still awaits the redirect.
 */
function expectRequestedRedirect(
	observation: RedirectHandoffObservation,
	method: RedirectMethod,
	expected: { storeOrigin: string; orderKey: string }
): void {
	// Deliberately not asserted here: how many times the client submitted, and
	// how many answers came back. Both are transport facts, and on the Blocks
	// surface neither is stable -- though not for the reason this comment used
	// to give.
	//
	// The client does not resubmit. In roughly a third of runs a second request
	// reaches `/wc/store/v1/checkout` a few seconds after the order is placed,
	// and it is `updateDraftOrder` from `data/checkout/push-changes.ts`: a
	// `PUT ...?__experimental_calc_totals=true` that `@wordpress/api-fetch`'s
	// `httpV1Middleware` tunnels as a POST carrying `X-HTTP-Method-Override`, so
	// a counter keyed on method and path cannot tell a draft-order sync from a
	// second order placement. Measured by wrapping `window.fetch` and recording
	// a stack per checkout request: the two requests are 5.9 seconds apart, the
	// first at checkout status `processing` and the second at `after_processing`
	// with no error, the second's stack running through `updateDraftOrder`. It
	// is intermittent because `push-changes` only pushes when the checkout data
	// it watches actually changed. A submission whose answer arrives after the
	// navigation is never observed at all.
	//
	// What the claim forbids is a second *transaction*, and that is already proven
	// twice from the store, without a browser in the loop: `readSubmittedOrder`
	// refuses to continue unless the submission produced exactly one new order,
	// and `expectSingleRunOrder` re-reads the same delta after settlement and
	// requires it to be this order. A resubmission that native absorbs -- one
	// order, one intent, one charge -- is not a duplicate and must not fail here;
	// a resubmission that creates a second order fails both of those checks.

	const { request } = observation;
	expect(
		request.paymentMethodTypes,
		`the provider must have been asked for exactly ${ method.id }`
	).toEqual( [ method.id ] );
	expect(
		request.amountMinor,
		`the provider must have been asked for ${ method.amountMinor } minor units`
	).toBe( method.amountMinor );
	expect( request.currency ).toBe( method.currency.toLowerCase() );
	expect(
		request.status,
		'the intent must await the provider redirect at this point'
	).toBe( 'requires_action' );
	// The provider does not name every redirect action the same way. Methods it
	// models explicitly get their own key — Alipay produces
	// `alipay_handle_redirect` — carrying the same hosted URL and return URL as
	// the generic `redirect_to_url`. Accepting the method's own name is
	// *stricter* than accepting only the generic one: it requires the action to
	// belong to the method this case drives, so an intent that somehow awaited a
	// different method's redirect would fail here rather than pass.
	expect(
		request.nextActionType,
		`the intent must await ${ method.id }'s own provider redirect`
	).toMatch(
		new RegExp( `^(redirect_to_url|${ method.id }_handle_redirect)$` )
	);
	expect(
		request.chargeCount,
		'no charge may exist before the shopper authorizes'
	).toBe( 0 );

	// Exactly one HTTPS provider redirect, off this store, and the one the
	// store handed the shopper. The query string is native's sanitization
	// boundary rather than this claim's subject, so the identity compared is
	// the origin and path — which is what names the provider's own object.
	const hosted = new URL( request.providerRedirectUrl );
	expect( hosted.protocol, 'the provider handoff must be over HTTPS' ).toBe(
		'https:'
	);
	expect(
		hosted.origin,
		'the handoff must leave this store for the provider'
	).not.toBe( expected.storeOrigin );

	// Asserted only for a case that stops at the handoff. A case that follows
	// the redirect proves the same thing far more strongly a moment later:
	// `expectReturnedToStore` requires the shopper to have landed back on this
	// run's own order-received URL, which cannot happen unless the store handed
	// over a working provider redirect. Comparing the answer field as well adds
	// nothing there, and reading it costs a dependency on a response body that
	// the navigation itself can destroy.
	if ( observation.storeRedirectUrl !== undefined ) {
		let handed: URL;
		try {
			handed = new URL(
				observation.storeRedirectUrl,
				expected.storeOrigin
			);
		} catch {
			throw new Error(
				`the store answered Place order with a redirect this case cannot resolve against ${
					expected.storeOrigin
				}: ${ JSON.stringify( observation.storeRedirectUrl ) }`
			);
		}
		expectStoreHandoff( observation, request, handed, hosted, expected );
	}

	expectRequestedReturnUrl( observation, request, expected );
}

/**
 * The exact provider graph one followed redirect must leave: the same intent,
 * one captured charge, one capture, one paid order, and nothing else.
 */
function expectSettledRedirectGraph(
	paid: PaymentEvidence,
	observation: RedirectHandoffObservation,
	method: RedirectMethod,
	runId: string
): void {
	expect(
		paid.intentId,
		'the settled intent must be the intent the handoff created'
	).toBe( observation.request.id );
	expect( paid.orderId ).toBe( observation.orderId );
	expect( paid.runId ).toBe( runId );
	expect( paid.amountMinor ).toBe( method.amountMinor );
	expect( paid.currency ).toBe( method.currency );
	expect( paid.providerStatus ).toBe( 'succeeded' );
	expect( paid.chargeStatus ).toBe( 'succeeded' );
	expect( paid.chargeCaptured ).toBe( true );
	expect(
		paid.occurrenceCount,
		'one handoff must leave exactly one charge on the intent'
	).toBe( 1 );
	expect( paid.captureOccurrenceCount ).toBe( 1 );
	expect( PAID_ORDER_STATUSES ).toContain( paid.orderStatus );
}

/**
 * The provider returned to this store, on this run's own order.
 */
function expectReturnedToStore(
	observation: RedirectHandoffObservation,
	expected: { storeOrigin: string; orderKey: string }
): void {
	const landed = readReturnUrlFacts(
		observation.landedUrl ??
			( () => {
				throw new Error(
					'A followed redirect must record where it landed.'
				);
			} )()
	);
	expect( landed.origin ).toBe( expected.storeOrigin );
	expect(
		landed.orderId,
		'the provider must return to this run own order'
	).toBe( observation.orderId );
	expect( landed.orderKey ).toBe( expected.orderKey );
	expect( landed.paymentMethod ).toBe( WOOPAYMENTS_GATEWAY );
}

/**
 * One handoff created exactly one order, and no second transaction appeared
 * behind it.
 */
async function expectSingleRunOrder(
	session: ProviderWriteSession,
	baselineOrderId: number,
	orderId: number
): Promise< void > {
	const delta = await readOrderDeltaAfter( session, baselineOrderId );
	expect(
		delta.newOrderIds,
		'one submission must create exactly one order'
	).toEqual( [ orderId ] );
}

/**
 * Restores the shopper half of the store after a case.
 *
 * A guest shopper's cart and currency selection live entirely in the
 * WooCommerce session cookie, so dropping it is the restoration; the cold read
 * afterwards is what proves it happened rather than assuming it. The store-side
 * currency and enabled-method configuration is restored by the snapshot
 * wrappers inside the case, which run first.
 *
 * Stated rather than glossed: these journeys are driven as a guest, so no
 * WooCommerce customer record is put into a foreign currency in the first place.
 * The ledger rows record the client suite leaving *its* customer in EUR; what
 * this proves is the stronger nearby fact that the run's currency selection does
 * not outlive the run at all, on a fresh session read against the store's own
 * configured default.
 *
 * The verification runs on a context this function owns when the case's own has
 * gone. A protection-on twin registers the test's context with the card-testing
 * controller, and that controller closes the context it was given when its scope
 * ends (`card-testing-protection.ts`, `registeredContext.close()`). Clearing
 * cookies on it afterwards fails with `Target page, context or browser has been
 * closed`, which is what made `A2p`, `A3p` and `A4p` unable to pass at all
 * rather than unable to pass reliably. Two cleanup layers both owned the
 * context; the inner one is right to close it, because closing is a *stronger*
 * session reset than clearing cookies. What the outer layer must not lose is its
 * cold read, so when the page is gone it opens a throwaway context of its own to
 * take that read, and closes it again.
 */
async function withRestoredShopperSession< Result >(
	session: ProviderWriteSession,
	page: Page,
	callback: () => Promise< Result >
): Promise< Result > {
	const defaultCurrency = await readStoreDefaultCurrency( session.adminApi );
	// Captured before the callback: once the context is closed the browser
	// handle is the only way back to a usable page.
	const browser = page.context().browser();

	let scenarioError: unknown;
	let result: Result | undefined;
	try {
		result = await callback();
	} catch ( error ) {
		scenarioError = error;
	}

	let restorationError: unknown;
	let disposableContext: BrowserContext | undefined;
	try {
		let readPage = page;
		if ( page.isClosed() ) {
			if ( ! browser ) {
				throw new Error(
					'the case closed its browser context and no browser handle is available to take the cold read from'
				);
			}
			disposableContext = await browser.newContext( {
				baseURL: session.baseURL,
			} );
			readPage = await disposableContext.newPage();
		} else {
			await page.context().clearCookies();
		}
		await readPage.goto( 'shop/' );
		const cart = await readShopperCartState( readPage );
		if ( cart.itemsCount !== 0 ) {
			restorationError = new ResourceQuarantineRequiredError(
				`The run shopper cart was not emptied: a fresh session still holds ${ cart.itemsCount } item(s).`,
				'restoration-failed'
			);
		} else if ( cart.currency !== defaultCurrency ) {
			restorationError = new ResourceQuarantineRequiredError(
				`A fresh shopper session quotes ${ cart.currency } rather than the store's configured ${ defaultCurrency }, so this run's currency selection outlived it.`,
				'restoration-failed'
			);
		}
	} catch ( error ) {
		// The cause is passed for the chain and named in the message as well.
		// A restoration failure aborts the family, so the one line a reader
		// gets has to say what actually went wrong; `cause` alone is not always
		// rendered by the reporter, and "restoring failed" on its own sends
		// them to the trace for a string the run already had.
		restorationError = new ResourceQuarantineRequiredError(
			`Restoring the run shopper session failed: ${
				error instanceof Error ? error.message : String( error )
			}`,
			'restoration-failed',
			error
		);
	} finally {
		// Only ever the context this function opened; the case's own is not
		// this function's to close.
		await disposableContext?.close();
	}

	if ( scenarioError !== undefined ) {
		throw scenarioError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
	return result as Result;
}

/**
 * Runs `callback` with the method enabled and, for a case denominated in a
 * currency the store does not default to, that currency enabled at a pinned
 * manual rate — both snapshotted and byte-restored with verified cold reads.
 */
async function withRedirectMethodStoreState< Result >(
	session: ProviderWriteSession,
	method: RedirectMethod,
	defaultCurrency: string,
	callback: () => Promise< Result >
): Promise< Result > {
	const enable = () =>
		withEnabledPaymentMethod(
			session,
			method,
			CAPABILITY_METHOD,
			callback
		);

	if ( method.currency === defaultCurrency ) {
		return enable();
	}
	return withForeignCurrency(
		session,
		method.currency,
		CAPABILITY_CURRENCY,
		enable
	);
}

interface ClassicCaseOptions {
	method: RedirectMethod;
	recordEvent: string;
	journal: string;
	/** Follow the provider's hosted page once, or stop at the handoff. */
	follow: ClassicRedirectFollowMode;
}

interface ClassicCaseResult {
	observation: RedirectHandoffObservation;
	orderKey: string;
	storeOrigin: string;
	baselineOrderId: number;
}

/**
 * Drives one protection-off classic redirect case inside its own owned provider
 * interval, with the store state it needs snapshotted before and restored
 * afterwards.
 *
 * `assertCase` runs inside that same interval, holding the account and store
 * locks. Convergence and the "exactly one order" reading both scan store state
 * that only this run may be writing, so doing them after the locks were
 * released would be asserting about a store somebody else could already own.
 */
async function runProtectionOffClassicCase< Result >(
	session: ProviderWriteSession,
	page: Page,
	options: ClassicCaseOptions,
	assertCase: ( result: ClassicCaseResult ) => Promise< Result >
): Promise< Result > {
	const { method } = options;
	const defaultCurrency = await readStoreDefaultCurrency( session.adminApi );
	requireCapabilities(
		session,
		method.currency === defaultCurrency
			? CLASSIC_CAPABILITIES
			: CLASSIC_CURRENCY_CAPABILITIES
	);
	await session.assertCurrentRuntimeReady( 'native' );

	return withRestoredShopperSession( session, page, () =>
		session.withProviderWriteLocks(
			{
				featureSetting: `payment-method-${ method.id }`,
				recordEvent: options.recordEvent,
			},
			async () => {
				await expectProtectionOff( session );

				return withRedirectMethodStoreState(
					session,
					method,
					defaultCurrency,
					() =>
						withClassicCheckoutPage(
							session,
							session.runId,
							async ( scope ) => {
								const currencyQuery =
									method.currency === defaultCurrency
										? undefined
										: method.currency;
								if ( currencyQuery ) {
									// Proved before anything is bought: a
									// storefront still quoting the store default
									// would price this order in the wrong
									// currency and the case would be about
									// something else.
									await setShopperSessionCurrency(
										page,
										currencyQuery
									);
								}

								const baselineOrderId =
									await readHighestOrderId( session );
								const product =
									await session.createOwnedProduct(
										method.price
									);
								const observation =
									await driveClassicRedirectCheckout(
										session,
										page,
										{
											method,
											product,
											runId: session.runId,
											checkout: scope.classicCheckout,
											journal: options.journal,
											follow: options.follow,
											currencyQuery,
											requireRequestEvidence: true,
										}
									);

								return assertCase( {
									observation,
									orderKey: (
										await readOrderFacts(
											session.adminApi,
											observation.orderId
										)
									).orderKey,
									storeOrigin: new URL( session.baseURL )
										.origin,
									baselineOrderId,
								} );
							}
						)
				);
			}
		)
	);
}

/**
 * Asserts a followed protection-off case end to end and records the settled
 * shape its protection-on twin has to reproduce.
 */
async function expectFollowedRedirectCase(
	session: ProviderWriteSession,
	method: RedirectMethod,
	result: ClassicCaseResult
): Promise< PaymentEvidence > {
	const { observation, orderKey, storeOrigin, baselineOrderId } = result;
	expectRequestedRedirect( observation, method, { storeOrigin, orderKey } );
	expectReturnedToStore( observation, { storeOrigin, orderKey } );

	const paid = await waitForSettledRedirect(
		session,
		observation.orderId,
		observation.request.id
	);
	expectSettledRedirectGraph( paid, observation, method, session.runId );
	await expectSingleRunOrder( session, baselineOrderId, observation.orderId );

	return paid;
}

/** What a followed protection-off case hands its test to restate. */
interface FollowedCaseOutcome {
	result: ClassicCaseResult;
	paid: PaymentEvidence;
}

/**
 * The whole of a followed protection-off case: drive it, assert it inside its
 * own locked interval, and hand back what the test restates.
 */
async function runFollowedRedirectCase(
	session: ProviderWriteSession,
	page: Page,
	options: ClassicCaseOptions & { follow: true }
): Promise< FollowedCaseOutcome > {
	return runProtectionOffClassicCase(
		session,
		page,
		options,
		async ( result ) => ( {
			result,
			paid: await expectFollowedRedirectCase(
				session,
				options.method,
				result
			),
		} )
	);
}

test.describe( 'WooPayments native redirect-method provider outcome fidelity', () => {
	// Serial on purpose. The classic case provisions and removes the shared
	// `classic-checkout` page.
	test.describe.configure( { mode: 'serial', timeout: 600_000 } );

	test(
		'One Alipay checkout sends the provider method alipay for 1200 usd with this run order-received return URL, and the single redirect settles that same PaymentIntent to succeeded with one captured charge',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_A1 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			const { paid } = await runFollowedRedirectCase(
				pilotRuntime,
				page,
				{
					method: ALIPAY,
					recordEvent: 'redirect-alipay-classic',
					journal: 'redirect-alipay-classic',
					follow: true,
				}
			);

			// `runFollowedRedirectCase` already asserted the full request shape
			// (method, amount, currency, return URL) above via
			// `expectRequestedRedirect`, unchanged. That same shape is now also
			// proven independently at a lower layer:
			// `WooPaymentsProviderGatewayAdapterTest::test_charge_sends_split_redirect_method_request`.
			// What only this browser case proves is the client-side
			// `alipay_handle_redirect` handoff into Stripe's hosted page and the
			// real return through `WooPaymentsRedirectReturnController::handle_wp`.
			expect( paid.chargeCaptured ).toBe( true );
		}
	);
} );
