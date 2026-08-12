import type { APIRequestContext, APIResponse, Page } from '@playwright/test';

import {
	expect,
	ResourceQuarantineRequiredError,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	withCapturedCardTestingProtectionState,
	type CardTestingTokenDigest,
} from '../../../utils/woopayments-native/drivers/card-testing-protection';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	AFFIRM,
	AFTERPAY,
	ALIPAY,
	BANCONTACT,
	driveBlocksRedirectCheckout,
	driveClassicRedirectCheckout,
	KLARNA,
	readRedirectIntentRequest,
	readReturnUrlFacts,
	readShopperCartState,
	setShopperSessionCurrency,
	submitTokenlessRedirectCheckout,
	withEnabledPaymentMethod,
	withForeignCurrency,
	type RedirectHandoffObservation,
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
 * The claim these nine cases exist to establish: for each listed redirect
 * method, native sends the real provider the correct method, amount, currency
 * and return URL, and the provider reaches that case's fixed redirect or
 * terminal state with exactly one correlated order and intent graph and no
 * second transaction. Card-testing protection changes only whether native
 * admits the submission — a token-bearing one settles to the same graph, a
 * tokenless one never reaches the provider at all.
 *
 * Every test title is the contract sentence the ledger records, and every case
 * here is one row of the claim's fixed run contract.
 *
 * What each case adds over the client suite it replaces is *the request*. The
 * client rows watch the shopper land on order-received, which a store can reach
 * having asked the provider for the wrong method, the wrong amount, or a return
 * URL belonging to somebody else. The observation these cases turn on is the
 * PaymentIntent read in `requires_action`, in the window between the checkout
 * response and the shopper's authorization: that is the only moment
 * `next_action.redirect_to_url` exists, and it carries both the hosted URL the
 * shopper is about to follow and the return URL native supplied. Once the
 * intent succeeds the provider drops that object, so nothing read afterwards
 * can say what was asked for.
 *
 * Surface boundary. `A1` drives Alipay on the classic shortcode checkout and
 * `A1b` drives the same proposition on the native Blocks checkout — a different
 * payment-method registration, a different submission route, and a different
 * response shape. Neither is evidence for the other, and the claim leaves the
 * Blocks wiring of the other four methods outside.
 *
 * Cost note: one full pass creates six settled redirect charges (Alipay
 * classic, Alipay Blocks, Affirm, Cash App Afterpay, Bancontact, and the three
 * protection-on twins' token-bearing submissions), one Klarna authorization
 * request that is deliberately never authorized, and three tokenless
 * submissions that must produce nothing at all. Nothing here is retried:
 * `--retries=0` is mandatory and there is no second handoff anywhere.
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
const CAPABILITY_PROTECTION = 'card-testing-protection-setting';

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
const BLOCKS_CAPABILITIES = [
	CAPABILITY_FAMILY,
	CAPABILITY_PRODUCT,
	CAPABILITY_METHOD,
];
const PROTECTION_CAPABILITIES = [
	...CLASSIC_CAPABILITIES,
	CAPABILITY_PROTECTION,
];
const PROTECTION_CURRENCY_CAPABILITIES = [
	...PROTECTION_CAPABILITIES,
	CAPABILITY_CURRENCY,
];

const CONTRACT_A1 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/alipay-checkout-purchase.spec.ts:60::Alipay Checkout › checkout on shortcode checkout page';
const CONTRACT_A1B =
	'default::chromium::tests/e2e/specs/wcpay/shopper/alipay-checkout-purchase.spec.ts:84::Alipay Checkout › checkout on block-based checkout page › completes payment successfully';
const CONTRACT_AFFIRM_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-bnpls-checkout.spec.ts:98::BNPL checkout › Carding protection false › Checkout with Affirm';
const CONTRACT_AFFIRM_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-bnpls-checkout.spec.ts:98::BNPL checkout › Carding protection true › Checkout with Affirm';
const CONTRACT_AFTERPAY_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-bnpls-checkout.spec.ts:98::BNPL checkout › Carding protection false › Checkout with Cash App Afterpay';
const CONTRACT_AFTERPAY_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-bnpls-checkout.spec.ts:98::BNPL checkout › Carding protection true › Checkout with Cash App Afterpay';
const CONTRACT_BANCONTACT_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-with-upe-methods.spec.ts:125::Local payment method checkout with card testing › Card testing protection enabled: false › should successfully place order with Bancontact';
const CONTRACT_BANCONTACT_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-with-upe-methods.spec.ts:125::Local payment method checkout with card testing › Card testing protection enabled: true › should successfully place order with Bancontact';

const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
/** An order the store made but never took money for. */
const UNPAID_ORDER_STATUSES = [ 'pending', 'failed', 'on-hold' ];
/** Native's own copy when card-testing protection turns a submission away. */
const CARD_TESTING_REJECTION_TEXT =
	"We're not able to process this payment. Please refresh the page and try again.";

/** The claim's convergence rule for `A1`-`A4` and the twins: every 2 seconds. */
const POLL_INTERVAL_MS = 2_000;
const SETTLE_BUDGET_MS = 90_000;
/** `A5` requires the exact `requires_action` redirect response within 30s. */
const HANDOFF_BUDGET_MS = 30_000;
/** The claim's empty provider-object interval after a tokenless rejection. */
const EMPTY_INTERVAL_MS = 10_000;
/** How long `A5`'s intent must stay unauthorized before the case passes. */
const UNAUTHORIZED_HOLD_MS = 10_000;

/**
 * The half of a settled redirect graph that a protection-on twin has to
 * reproduce. Order and intent identities are deliberately absent: the twin
 * makes its own payment, and requiring the same one would be requiring the
 * impossible rather than the contract.
 */
interface SettledRedirectShape {
	paymentMethodTypes: string[];
	amountMinor: number;
	currency: string;
	providerStatus: string;
	chargeStatus: string;
	chargeCaptured: boolean;
	occurrenceCount: number;
	captureOccurrenceCount: number;
}

/**
 * What each protection-off case recorded, keyed by method, for its twin to
 * compare against. The twins run immediately after their own protection-off
 * case in this serial file, so a missing entry means the family was run out of
 * order rather than that the comparison is optional.
 */
const protectionOffShapes = new Map< string, SettledRedirectShape >();

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
 * The request half of the claim: the exact method, minor amount, currency and
 * run return URL the provider actually received, read from the intent while it
 * still awaits the redirect.
 */
function expectRequestedRedirect(
	observation: RedirectHandoffObservation,
	method: RedirectMethod,
	expected: { storeOrigin: string; orderKey: string }
): void {
	expect(
		observation.checkoutRequestCount,
		'one Place order activation must ask the store exactly once'
	).toBe( 1 );
	expect( observation.checkoutResponseCount ).toBe( 1 );

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
	// Resolved against the store, because what the store hands back is not
	// required to be absolute, and reported raw on failure — a bare `new URL()`
	// here throws `Invalid URL` and says nothing about what the store actually
	// answered with.
	let handed: URL;
	try {
		handed = new URL( observation.storeRedirectUrl, expected.storeOrigin );
	} catch {
		throw new Error(
			`the store answered Place order with a redirect this case cannot resolve against ${
				expected.storeOrigin
			}: ${ JSON.stringify( observation.storeRedirectUrl ) }`
		);
	}
	expect( hosted.protocol, 'the provider handoff must be over HTTPS' ).toBe(
		'https:'
	);
	expect(
		hosted.origin,
		'the handoff must leave this store for the provider'
	).not.toBe( expected.storeOrigin );
	expect(
		`${ handed.origin }${ handed.pathname }`,
		'the store must hand the shopper the exact redirect the intent names'
	).toBe( `${ hosted.origin }${ hosted.pathname }` );

	// The return URL, which is what makes this run's handoff this run's. The
	// nonce it also carries is a credential for this one payment; only its
	// presence is read.
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

function settledShape(
	paid: PaymentEvidence,
	observation: RedirectHandoffObservation
): SettledRedirectShape {
	return {
		paymentMethodTypes: observation.request.paymentMethodTypes,
		amountMinor: paid.amountMinor,
		currency: paid.currency,
		providerStatus: paid.providerStatus,
		chargeStatus: paid.chargeStatus,
		chargeCaptured: paid.chargeCaptured,
		occurrenceCount: paid.occurrenceCount,
		captureOccurrenceCount: paid.captureOccurrenceCount,
	};
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
 */
async function withRestoredShopperSession< Result >(
	session: ProviderWriteSession,
	page: Page,
	callback: () => Promise< Result >
): Promise< Result > {
	const defaultCurrency = await readStoreDefaultCurrency( session.adminApi );

	let scenarioError: unknown;
	let result: Result | undefined;
	try {
		result = await callback();
	} catch ( error ) {
		scenarioError = error;
	}

	let restorationError: unknown;
	try {
		await page.context().clearCookies();
		await page.goto( 'shop/' );
		const cart = await readShopperCartState( page );
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
		restorationError = new ResourceQuarantineRequiredError(
			'Restoring the run shopper session failed.',
			'restoration-failed',
			error
		);
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
	follow: boolean;
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
	protectionOffShapes.set( method.id, settledShape( paid, observation ) );

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

function requireProtectionOffShape(
	method: RedirectMethod
): SettledRedirectShape {
	const shape = protectionOffShapes.get( method.id );
	if ( ! shape ) {
		throw new Error(
			`This twin compares against the ${ method.id } protection-off graph; run the family in order.`
		);
	}
	return shape;
}

/**
 * Waits out an interval in which the run must gain no provider object at all.
 *
 * The claim words this as an empty provider-object interval for the run's
 * customer. A redirect submission that native refuses creates no intent, so
 * there is no provider customer to name and nothing to poll at the provider;
 * what the interval watches instead is the store's own order stream, which is
 * where any payment context would have to appear. The store is held under this
 * run's exclusive locks, so an order in the interval belongs to this run.
 */
async function expectEmptyProviderInterval(
	session: ProviderWriteSession,
	baselineOrderId: number
): Promise< number[] > {
	const deadline = Date.now() + EMPTY_INTERVAL_MS;
	let newOrderIds: number[] = [];

	for (;;) {
		const delta = await readOrderDeltaAfter( session, baselineOrderId );
		expect(
			delta.paidOrderIds,
			'a refused submission must leave no paid order'
		).toEqual( [] );
		expect(
			delta.providerLinkedOrderIds,
			'a refused submission must create no payment context'
		).toEqual( [] );
		newOrderIds = delta.newOrderIds;

		if ( Date.now() >= deadline ) {
			return newOrderIds;
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/** What a protection-on twin observed, for the case to restate. */
interface ProtectionTwinResult {
	admittedShape: SettledRedirectShape;
	protectionOffShape: SettledRedirectShape;
	tokenlessRejectionMessages: string[];
	tokenlessOrderIds: number[];
}

/**
 * Holds the intent of an unfollowed handoff at its unauthorized state for a
 * bounded interval, so an authorization arriving a moment after the read cannot
 * slip between the read and the assertion.
 */
async function expectIntentStaysUnauthorized(
	session: ProviderWriteSession,
	intentId: string
): Promise< void > {
	const deadline = Date.now() + UNAUTHORIZED_HOLD_MS;
	for (;;) {
		const intent = await readRedirectIntentRequest(
			session,
			intentId,
			false
		);
		expect(
			intent.status,
			'an unauthorized handoff must stay awaiting customer action'
		).toBe( 'requires_action' );
		expect(
			intent.chargeCount,
			'an unauthorized handoff must carry no charge and no capture'
		).toBe( 0 );
		if ( Date.now() >= deadline ) {
			return;
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/**
 * Drives one protection-on twin: one token-bearing submission that must settle
 * to its protection-off case's graph, and one tokenless submission that must
 * produce nothing at the provider.
 *
 * Forced-eligibility boundary, carried here deliberately and stated rather than
 * glossed. The target account reports `card_testing_protection_eligible: false`,
 * so this run supplies that premise itself: the controller asserts eligibility
 * into the local account cache, byte-restores it, and verifies the restore.
 * What follows therefore establishes that native enforces protection at its own
 * boundary and that a token-bearing redirect submission still settles correctly
 * at the provider. It establishes nothing about whether the provider grants this
 * account the capability; provisioning the flag stays outside this claim.
 */
async function runProtectionOnTwin(
	session: ProviderWriteSession,
	page: Page,
	options: { method: RedirectMethod; journal: string }
): Promise< ProtectionTwinResult > {
	const { method } = options;
	const defaultCurrency = await readStoreDefaultCurrency( session.adminApi );
	requireCapabilities(
		session,
		method.currency === defaultCurrency
			? PROTECTION_CAPABILITIES
			: PROTECTION_CURRENCY_CAPABILITIES
	);
	await session.assertCurrentRuntimeReady( 'native' );
	const offShape = requireProtectionOffShape( method );
	const storeOrigin = new URL( session.baseURL ).origin;

	return withRestoredShopperSession( session, page, () =>
		withCapturedCardTestingProtectionState(
			session,
			session.runId,
			async ( scope ) => {
				await scope.registerFreshContext( page );
				expect(
					await readCardTestingProtectionEligibility(
						session.adminApi
					),
					'the forced premise must be in effect before either submission'
				).toBe( true );

				return withRedirectMethodStoreState(
					session,
					method,
					defaultCurrency,
					async () => {
						const currencyQuery =
							method.currency === defaultCurrency
								? undefined
								: method.currency;
						if ( currencyQuery ) {
							await setShopperSessionCurrency(
								page,
								currencyQuery
							);
						}

						const admittedBaselineOrderId =
							await readHighestOrderId( session );
						const product = await session.createOwnedProduct(
							method.price
						);

						// Half one: a token-bearing submission settles exactly
						// as the protection-off case does.
						let sessionToken: CardTestingTokenDigest | undefined;
						const admitted = await driveClassicRedirectCheckout(
							session,
							page,
							{
								method,
								product,
								runId: session.runId,
								checkout: scope.classicCheckout,
								journal: options.journal,
								follow: true,
								currencyQuery,
								requireRequestEvidence: true,
								beforeSubmit: async () => {
									sessionToken =
										await scope.captureGuestSessionToken(
											page
										);
								},
							}
						);

						expect(
							sessionToken,
							'the run must have recorded the shopper session token before it submitted'
						).toBeDefined();
						expect(
							admitted.submittedFraudPreventionToken,
							'the admitted submission must carry the exact session token'
						).toEqual( {
							presence: 'present',
							digest: sessionToken,
						} );

						const orderKey = (
							await readOrderFacts(
								session.adminApi,
								admitted.orderId
							)
						 ).orderKey;
						expectRequestedRedirect( admitted, method, {
							storeOrigin,
							orderKey,
						} );
						expectReturnedToStore( admitted, {
							storeOrigin,
							orderKey,
						} );

						const paid = await waitForSettledRedirect(
							session,
							admitted.orderId,
							admitted.request.id
						);
						expectSettledRedirectGraph(
							paid,
							admitted,
							method,
							session.runId
						);
						const admittedShape = settledShape( paid, admitted );
						expect(
							admittedShape,
							'a token-bearing submission under protection must reach the same graph as its protection-off case'
						).toEqual( offShape );
						await expectSingleRunOrder(
							session,
							admittedBaselineOrderId,
							admitted.orderId
						);

						// Half two: the same store, the same session, one
						// submission with the session token absent. Without it a
						// run where protection silently failed to engage would
						// pass exactly like the one above.
						const baselineOrderId = await readHighestOrderId(
							session
						);
						const rejection = await submitTokenlessRedirectCheckout(
							session,
							page,
							{
								method,
								product,
								runId: session.runId,
								checkout: scope.classicCheckout,
								journal: `${ options.journal }-tokenless`,
								currencyQuery,
							}
						);

						expect( rejection.checkoutRequestCount ).toBe( 1 );
						expect( rejection.checkoutResponseCount ).toBe( 1 );
						expect(
							rejection.fraudPreventionToken.presence,
							'the tokenless submission must actually have carried no usable token'
						).not.toBe( 'present' );
						expect(
							rejection.result,
							'native must refuse the submission'
						).toBe( 'failure' );
						expect( rejection.messages ).toContain(
							CARD_TESTING_REJECTION_TEXT
						);
						expect(
							rejection.alertCount,
							'the shopper must be told, once, through an assertive notice'
						).toBe( 1 );
						expect( rejection.noticeMessages ).toEqual( [
							CARD_TESTING_REJECTION_TEXT,
						] );
						expect( rejection.url ).not.toContain(
							'order-received'
						);

						// No payment context was created. WooCommerce still
						// makes an order before calling the gateway, so an
						// unpaid order in this window is expected; what must not
						// exist is an order carrying an intent, a charge, or a
						// payment. The boundary stated rather than glossed: the
						// browser tokenizes with the provider before it submits,
						// so a PaymentMethod object does come into existence. It
						// is created by the shopper's browser against the
						// provider's public API, attached to no customer and
						// charged nothing, and it is not what native creates.
						const leftoverOrderIds =
							await expectEmptyProviderInterval(
								session,
								baselineOrderId
							);

						// The refusal answers with no order ID, so the order
						// WooCommerce had already made cannot be tagged at
						// dispatch. Tag it now: it holds no payment, but the
						// history it leaves should still say which run made it.
						for ( const orderId of leftoverOrderIds ) {
							await session.setOrderRunId(
								orderId,
								session.runId
							);
							expect(
								UNPAID_ORDER_STATUSES,
								'a refused submission must leave the order it made unpaid'
							).toContain(
								(
									await readOrderFacts(
										session.adminApi,
										orderId
									)
								 ).status
							);
						}

						// And the admitted payment did not gain a second charge
						// behind it while the refusal was being proven.
						const recheck = await getPaymentEvidence(
							session.adminApi,
							admitted.orderId
						);
						expect( recheck.chargeId ).toBe( paid.chargeId );
						expect( recheck.occurrenceCount ).toBe( 1 );
						expect( recheck.captureOccurrenceCount ).toBe( 1 );

						return {
							admittedShape,
							protectionOffShape: offShape,
							tokenlessRejectionMessages:
								rejection.noticeMessages,
							tokenlessOrderIds: leftoverOrderIds,
						};
					}
				);
			}
		)
	);
}

test.describe( 'WooPayments native redirect-method provider outcome fidelity', () => {
	// Serial on purpose. The classic cases provision and remove the shared
	// `classic-checkout` page and must never overlap; each protection-on twin
	// compares against the graph its protection-off case recorded a moment
	// earlier; and a failure must halt the cases after it rather than spend more
	// provider budget on a store whose state is no longer described.
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
			const { result, paid } = await runFollowedRedirectCase(
				pilotRuntime,
				page,
				{
					method: ALIPAY,
					recordEvent: 'redirect-alipay-classic',
					journal: 'redirect-alipay-classic',
					follow: true,
				}
			);

			// The case's own contract sentence, restated where the ledger row
			// is annotated: the provider was asked for alipay at 1200 usd, and
			// that same intent settled with one captured charge.
			expect( result.observation.request.paymentMethodTypes ).toEqual( [
				ALIPAY.id,
			] );
			expect( result.observation.request.amountMinor ).toBe(
				ALIPAY.amountMinor
			);
			expect( result.observation.request.currency ).toBe( 'usd' );
			expect( paid.chargeCaptured ).toBe( true );
		}
	);

	test(
		'One Alipay checkout on the native Blocks surface sends the provider method alipay for 1200 usd with this run order-received return URL, and the single redirect settles that same PaymentIntent to succeeded with one captured charge',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_A1B },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, BLOCKS_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );
			const defaultCurrency = await readStoreDefaultCurrency(
				pilotRuntime.adminApi
			);
			const storeOrigin = new URL( pilotRuntime.baseURL ).origin;

			const blocks = await withRestoredShopperSession(
				pilotRuntime,
				page,
				() =>
					pilotRuntime.withProviderWriteLocks(
						{
							featureSetting: `payment-method-${ ALIPAY.id }`,
							recordEvent: 'redirect-alipay-blocks',
						},
						async () => {
							await expectProtectionOff( pilotRuntime );

							return withRedirectMethodStoreState(
								pilotRuntime,
								ALIPAY,
								defaultCurrency,
								async () => {
									const baselineOrderId =
										await readHighestOrderId(
											pilotRuntime
										);
									const product =
										await pilotRuntime.createOwnedProduct(
											ALIPAY.price
										);
									// Unconditional by contract: the method being
									// absent from the Blocks payment options fails
									// this case rather than skipping it, because a
									// skip is exactly the residual this row records.
									const observation =
										await driveBlocksRedirectCheckout(
											pilotRuntime,
											page,
											{
												method: ALIPAY,
												product,
												runId: pilotRuntime.runId,
												journal:
													'redirect-alipay-blocks',
												follow: true,
												requireRequestEvidence: true,
											}
										);
									const orderKey = (
										await readOrderFacts(
											pilotRuntime.adminApi,
											observation.orderId
										)
									 ).orderKey;

									expectRequestedRedirect(
										observation,
										ALIPAY,
										{
											storeOrigin,
											orderKey,
										}
									);
									expectReturnedToStore( observation, {
										storeOrigin,
										orderKey,
									} );

									const paid = await waitForSettledRedirect(
										pilotRuntime,
										observation.orderId,
										observation.request.id
									);
									expectSettledRedirectGraph(
										paid,
										observation,
										ALIPAY,
										pilotRuntime.runId
									);
									await expectSingleRunOrder(
										pilotRuntime,
										baselineOrderId,
										observation.orderId
									);

									return { observation, paid };
								}
							);
						}
					)
			);

			// The row's own contract sentence, restated where it is annotated.
			// The client row it replaces executed conditionally and stopped at
			// order-received; this one drove the Blocks surface unconditionally
			// and reads the transaction state the residual calls missing.
			expect( blocks.observation.request.paymentMethodTypes ).toEqual( [
				ALIPAY.id,
			] );
			expect( blocks.observation.request.amountMinor ).toBe(
				ALIPAY.amountMinor
			);
			expect( blocks.observation.request.currency ).toBe( 'usd' );
			expect( blocks.paid.chargeCaptured ).toBe( true );
		}
	);

	test(
		'One Affirm checkout sends the provider method affirm for 10000 usd with this run order-received return URL, and the single redirect settles that same PaymentIntent to succeeded with one captured charge',
		{ tag: FAMILY_TAGS },
		async ( { page, pilotRuntime } ) => {
			const { result, paid } = await runFollowedRedirectCase(
				pilotRuntime,
				page,
				{
					method: AFFIRM,
					recordEvent: 'redirect-affirm-classic',
					journal: 'redirect-affirm-classic',
					follow: true,
				}
			);

			// The case's own contract sentence, restated: the provider was
			// asked for affirm at 10000 usd, and that same intent settled with
			// one captured charge.
			expect( result.observation.request.paymentMethodTypes ).toEqual( [
				AFFIRM.id,
			] );
			expect( result.observation.request.amountMinor ).toBe(
				AFFIRM.amountMinor
			);
			expect( result.observation.request.currency ).toBe( 'usd' );
			expect( paid.chargeCaptured ).toBe( true );
		}
	);

	test(
		'Card-testing protection admits one token-bearing Affirm checkout to the same affirm 10000 usd settled graph and creates no provider object for a tokenless one',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_AFFIRM_PROTECTION_FALSE,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_AFFIRM_PROTECTION_TRUE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			// Two submissions, a hosted authorization, a real rejection and a
			// ten-second empty interval, so this case is given more room than a
			// single handoff needs.
			test.setTimeout( 900_000 );
			const twin = await runProtectionOnTwin( pilotRuntime, page, {
				method: AFFIRM,
				journal: 'redirect-affirm-protected',
			} );

			// The two rows' own contract sentences, restated where they are
			// annotated. Protection changed only admission: the token-bearing
			// submission reached the protection-off graph, and the tokenless one
			// was refused with native's own copy and left no order carrying a
			// payment context behind it.
			expect( twin.admittedShape ).toEqual( twin.protectionOffShape );
			expect( twin.tokenlessRejectionMessages ).toEqual( [
				CARD_TESTING_REJECTION_TEXT,
			] );
		}
	);

	test(
		'One Cash App Afterpay checkout sends the provider method afterpay_clearpay for 10000 usd with this run order-received return URL, and the single handoff settles that same PaymentIntent to succeeded with one captured charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_AFTERPAY_PROTECTION_FALSE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			const { result, paid } = await runFollowedRedirectCase(
				pilotRuntime,
				page,
				{
					method: AFTERPAY,
					recordEvent: 'redirect-afterpay-classic',
					journal: 'redirect-afterpay-classic',
					follow: true,
				}
			);

			// The case's own contract sentence, restated where the ledger row
			// is annotated: the provider was asked for afterpay_clearpay at
			// 10000 usd, and that same intent settled with one captured charge.
			// This replaces the client row's order-received oracle, which could
			// pass without any of it being true.
			expect( result.observation.request.paymentMethodTypes ).toEqual( [
				AFTERPAY.id,
			] );
			expect( result.observation.request.amountMinor ).toBe(
				AFTERPAY.amountMinor
			);
			expect( result.observation.request.currency ).toBe( 'usd' );
			expect( paid.chargeCaptured ).toBe( true );
		}
	);

	test(
		'Card-testing protection admits one token-bearing Cash App Afterpay checkout to the same afterpay_clearpay 10000 usd settled graph and creates no provider object for a tokenless one',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_AFTERPAY_PROTECTION_TRUE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			test.setTimeout( 900_000 );
			const twin = await runProtectionOnTwin( pilotRuntime, page, {
				method: AFTERPAY,
				journal: 'redirect-afterpay-protected',
			} );

			// The row's own contract sentence, restated where it is annotated:
			// token presence is no longer the oracle, because the tokenless
			// submission had to be refused and produce nothing while the
			// token-bearing one reached the protection-off graph.
			expect( twin.admittedShape ).toEqual( twin.protectionOffShape );
			expect( twin.tokenlessRejectionMessages ).toEqual( [
				CARD_TESTING_REJECTION_TEXT,
			] );
		}
	);

	test(
		'One Bancontact checkout sends the provider method bancontact for 1234 eur with this run order-received return URL, and the single redirect settles that same PaymentIntent to succeeded with one captured charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_BANCONTACT_PROTECTION_FALSE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			const { result, order } = await runProtectionOffClassicCase(
				pilotRuntime,
				page,
				{
					method: BANCONTACT,
					recordEvent: 'redirect-bancontact-classic',
					journal: 'redirect-bancontact-classic',
					follow: true,
				},
				async ( caseResult ) => {
					const paid = await expectFollowedRedirectCase(
						pilotRuntime,
						BANCONTACT,
						caseResult
					);
					return {
						result: caseResult,
						order: await readOrderFacts(
							pilotRuntime.adminApi,
							paid.orderId
						),
					};
				}
			);

			// The row's own contract sentence, restated where it is annotated.
			// The residual it records is that the original never asserted the
			// charged currency or amount at all, so both are read back off the
			// stored order and matched to what the provider was asked for.
			expect( result.observation.request.paymentMethodTypes ).toEqual( [
				BANCONTACT.id,
			] );
			expect( result.observation.request.amountMinor ).toBe(
				BANCONTACT.amountMinor
			);
			expect( result.observation.request.currency ).toBe( 'eur' );
			expect( order.currency ).toBe( BANCONTACT.currency );
			expect( order.total ).toBe( BANCONTACT.price );
		}
	);

	test(
		'Card-testing protection admits one token-bearing Bancontact checkout to the same bancontact 1234 eur settled graph and creates no provider object for a tokenless one',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_BANCONTACT_PROTECTION_TRUE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			test.setTimeout( 900_000 );
			const twin = await runProtectionOnTwin( pilotRuntime, page, {
				method: BANCONTACT,
				journal: 'redirect-bancontact-protected',
			} );

			// The row's own contract sentence, restated where it is annotated:
			// enforcement is proven behaviorally by the tokenless submission
			// rather than inferred from token presence, and the token-bearing
			// one still settled the same EUR graph.
			expect( twin.admittedShape ).toEqual( twin.protectionOffShape );
			expect( twin.tokenlessRejectionMessages ).toEqual( [
				CARD_TESTING_REJECTION_TEXT,
			] );
		}
	);

	test(
		'One Klarna checkout sends the provider method klarna for 10000 usd with this run order-received return URL and returns requires_action with exactly one HTTPS provider redirect, no charge, and no capture',
		{ tag: FAMILY_TAGS },
		async ( { page, pilotRuntime } ) => {
			await runProtectionOffClassicCase(
				pilotRuntime,
				page,
				{
					method: KLARNA,
					recordEvent: 'redirect-klarna-classic',
					journal: 'redirect-klarna-handoff',
					// The contract stops before hosted authorization, and the
					// driver refuses every cross-origin navigation while it does
					// so, which is what makes "stops before" a fact rather than
					// an intention.
					follow: false,
				},
				async ( result ) => {
					expectRequestedRedirect( result.observation, KLARNA, {
						storeOrigin: result.storeOrigin,
						orderKey: result.orderKey,
					} );
					expect(
						result.observation.handoffElapsedMs,
						'the requires_action redirect response must arrive within the fixed 30-second window'
					).toBeLessThanOrEqual( HANDOFF_BUDGET_MS );
					expect(
						result.observation.paid,
						'this case must not follow the handoff, so it must produce no settled payment'
					).toBeUndefined();
					expect( result.observation.landedUrl ).toBeUndefined();
					await expectSingleRunOrder(
						pilotRuntime,
						result.baselineOrderId,
						result.observation.orderId
					);

					// Zero charge and zero capture, held rather than sampled:
					// an authorization that arrived a moment later would
					// otherwise slip between the read and the assertion.
					await expectIntentStaysUnauthorized(
						pilotRuntime,
						result.observation.request.id
					);

					const order = await readOrderFacts(
						pilotRuntime.adminApi,
						result.observation.orderId
					);
					expect(
						UNPAID_ORDER_STATUSES,
						'an unauthorized handoff must leave the order unpaid'
					).toContain( order.status );
					expect(
						order.chargeId,
						'an unpaid order must carry no charge'
					).toBe( '' );
					expect( order.intentId ).toBe(
						result.observation.request.id
					);
				}
			);
		}
	);
} );
