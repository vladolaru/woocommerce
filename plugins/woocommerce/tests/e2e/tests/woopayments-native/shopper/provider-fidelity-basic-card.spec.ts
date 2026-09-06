import { createHash } from 'node:crypto';

import type { APIResponse, Page, Request, Response } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	ProviderSubmissionNotStartedError,
	ResourceQuarantineRequiredError,
	submitBlocksCheckout,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { withActiveBlockTheme } from '../../../utils/woopayments-native/drivers/block-theme';
import {
	withCapturedCardTestingProtectionState,
	type CardTestingProtectionScope,
} from '../../../utils/woopayments-native/drivers/card-testing-protection';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
	readProviderCustomerPaymentMethodIds,
	submitClassicTokenlessCheckout,
	type PreparedClassicCardCheckout,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import {
	CLASSIC_CHECKOUT_PATH,
	completeClassicCardCheckout,
	PlaywrightClassicCardCheckoutBrowser,
	type ClassicCardCheckoutEvidence,
} from '../../../utils/woopayments-native/drivers/classic-card-checkout';
import { fillBlocksCheckoutAddress } from '../../../utils/woopayments-native/drivers/checkout';
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import { decodeEscapedHtml } from '../../../utils/woopayments-native/store-api-text';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `basic-card-charge` provider-fidelity family.
 *
 * `FIDELITY-CLAIMS.md` states the claim these five cases exist to establish:
 * one shopper checkout submission through native with provider test card
 * `4242424242424242` produces exactly one PaymentIntent and one captured charge
 * at the provider for USD 10.99, correlated to one run-owned Woo order that
 * reaches `processing` or `completed`, and creates no second intent, charge,
 * capture, order, or reusable payment method — and card-testing protection
 * changes only whether native admits the submission at all.
 *
 * **Cardinality is the contract, not a by-product.** The claim's falsifier names
 * "a duplicate object created by a single submission" specifically, so every
 * case counts what it caused on both sides: Store API checkout requests and
 * responses at the surface, new orders against a baseline order ID at the store,
 * and charge and capture occurrences on the exact intent at the provider. A
 * graph that merely *contains* the right objects would satisfy a weaker suite
 * and prove nothing here.
 *
 * **Surfaces.** `B1`, `B1c`, `B1f` and `B1pf` drive the native Blocks checkout,
 * which is the surface the ledger row `B1` discharges names, and which under a
 * block theme is the Site Editor checkout surface. `B1p` drives the Classic
 * shortcode checkout, because the row it discharges is the Classic
 * `shopper-checkout-purchase.spec.ts` protection-on case and because the
 * card-testing-protection controller provisions exactly that page. No case here
 * is evidence for a surface it does not drive.
 *
 * **The forced-eligibility boundary, carried deliberately.** The target account
 * reports `card_testing_protection_eligible: false`, so `B1p` and `B1pf` supply
 * that premise themselves through the existing byte-restoring controller. What
 * they establish is that native enforces protection at its own boundary and that
 * a token-bearing submission still settles correctly at the provider. They
 * establish nothing about whether the provider grants this account the
 * capability; provisioning the flag stays outside this claim.
 *
 * **What the block-theme cases add.** The standing store already runs a block
 * theme, so an FSE checkout surface is not something `B1f` has to go and find —
 * `B1` meets one too. `B1f` and `B1pf` add a *second*, supported block theme
 * that the store does not normally run, activated and restored by the shared
 * driver, and they prove the same money graph under it. That is stated here
 * rather than left implied, because "repeat it on the FSE surface" would
 * otherwise read as though `B1` were not already on one.
 */

/* -------------------------------------------------------------------------
 * The ledger rows these cases carry
 * ---------------------------------------------------------------------- */

const CONTRACT_B1 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-purchase.spec.ts:31::WooCommerce Blocks › Successful purchase › using a basic card';
const CONTRACT_B1C =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-cart-coupon.spec.ts:67::Checkout with free coupon & after modifying cart on Checkout page › Remove free coupon, then checkout';
const CONTRACT_B1P =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection true › using a basic card';
const CONTRACT_B1F =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-site-editor.spec.ts:91::Successful purchase, site builder theme › card prevention: false › basic card';
const CONTRACT_B1PF =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-site-editor.spec.ts:91::Successful purchase, site builder theme › card prevention: true › basic card';

/** The grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:basic-card-charge';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

/* -------------------------------------------------------------------------
 * Fixed contract values
 * ---------------------------------------------------------------------- */

/**
 * The basic card, written out here for the reason `test-cards.ts` records:
 * that module carries the cards whose *behaviour* the provider selects, and
 * `4242` selects nothing. It is the same triple the Blocks and Classic
 * checkout drivers fill inline.
 */
const BASIC_CARD: ProviderTestCard = {
	number: '4242424242424242',
	expiry: '0245',
	securityCode: '424',
};

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

/**
 * The supported block theme the FSE cases activate.
 *
 * `plugins/woocommerce/.wp-env.json` installs it alongside the store's own
 * theme, and it is the theme the two Site Editor ledger rows name. The driver
 * refuses to run if the store is already on it, so the case cannot quietly
 * become a no-op.
 */
const FSE_THEME = 'twentytwentyfour';

/**
 * WooCommerce's own `wp_is_block_theme()` body class. Asserted on the rendered
 * checkout rather than trusting the theme header, because it is the predicate
 * the storefront itself branched on when it chose how to render the page.
 */
const BLOCK_THEME_BODY_CLASS = 'woocommerce-uses-block-theme';

/** Native's copy when card-testing protection turns a submission away. */
const CARD_TESTING_REJECTION_TEXT =
	"We're not able to process this payment. Please refresh the page and try again.";

/** The claim's convergence rule: poll every 2 seconds, for at most 60. */
const POLL_INTERVAL_MS = 2_000;
const SETTLEMENT_BUDGET_MS = 60_000;
/** The empty provider-object interval a refused submission must be followed by. */
const EMPTY_INTERVAL_MS = 10_000;
const CHECKOUT_RESPONSE_TIMEOUT_MS = 60_000;
const RECEIPT_TIMEOUT_MS = 60_000;

/**
 * WooCommerce's snackbar notice area, and the budget for emptying it.
 *
 * The settle budget exceeds the block's own `SNACKBAR_TIMEOUT` of 10 seconds so
 * that a notice this suite could not dismiss still has its own timer as a way
 * out; the per-click budget is short because a click that cannot land is a
 * notice that has already gone.
 */
const SNACKBAR_LIST_SELECTOR = '.wc-block-components-notice-snackbar-list';
const SNACKBAR_SELECTOR = '.wc-block-components-notice-snackbar';
const SNACKBAR_DISMISS_TIMEOUT_MS = 2_000;
const SNACKBAR_SETTLE_TIMEOUT_MS = 15_000;

const STORE_CART_API = '/wp-json/wc/store/v1/cart';
const STORE_CHECKOUT_PATH = '/wp-json/wc/store/v1/checkout';
const COUPONS_API = '/wp-json/wc/v3/coupons';

/* -------------------------------------------------------------------------
 * Capabilities
 * ---------------------------------------------------------------------- */

/** The family's own umbrella capability, as the sibling families declare theirs. */
const CAPABILITY_FAMILY = 'basic-card-charge';
const CAPABILITY_PRODUCT = 'product/payment';
const CAPABILITY_CARD = 'basic-card';
const CAPABILITY_CARD_ENTRY = 'basic-card-entry';
const CAPABILITY_CLASSIC_PAGE = 'classic-checkout-page';
const CAPABILITY_PROTECTION = 'card-testing-protection-setting';
const CAPABILITY_BLOCK_THEME = 'block-theme-activation';
const CAPABILITY_COUPON = 'basic-card-charge-coupon';

const B1_CAPABILITIES = [
	CAPABILITY_FAMILY,
	CAPABILITY_PRODUCT,
	CAPABILITY_CARD,
	CAPABILITY_CARD_ENTRY,
];
const B1C_CAPABILITIES = [ ...B1_CAPABILITIES, CAPABILITY_COUPON ];
const B1P_CAPABILITIES = [
	...B1_CAPABILITIES,
	CAPABILITY_CLASSIC_PAGE,
	CAPABILITY_PROTECTION,
];
const B1F_CAPABILITIES = [ ...B1_CAPABILITIES, CAPABILITY_BLOCK_THEME ];
const B1PF_CAPABILITIES = [ ...B1P_CAPABILITIES, CAPABILITY_BLOCK_THEME ];

/* -------------------------------------------------------------------------
 * Small shared utilities
 * ---------------------------------------------------------------------- */

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

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

function requireObject(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		throw new Error(
			`Basic-card evidence requires one ${ label } object.`
		);
	}
	return value as Record< string, unknown >;
}

interface TokenDigest {
	length: number;
	sha256: string;
}

/**
 * The same public-safe shape the protection controller reports a session token
 * as. The length is measured rather than assumed: an emptied token digests to a
 * perfectly valid hash, and only the length says it was empty.
 */
function digestToken( token: string ): TokenDigest {
	return {
		length: token.length,
		sha256: createHash( 'sha256' ).update( token ).digest( 'hex' ),
	};
}

function quarantine(
	message: string,
	reasonCode: ConstructorParameters<
		typeof ResourceQuarantineRequiredError
	>[ 1 ],
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		message,
		reasonCode,
		primaryError
	);
}

/** Whether a request is the Store API checkout POST, in either permalink shape. */
function isStoreCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const url = new URL( request.url() );
		const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
		return (
			url.pathname.replace( /\/+$/, '' ) === STORE_CHECKOUT_PATH ||
			restRoute.replace( /\/+$/, '' ) === '/wc/store/v1/checkout'
		);
	} catch {
		return false;
	}
}

/* -------------------------------------------------------------------------
 * Observing one Blocks submission
 * ---------------------------------------------------------------------- */

interface StoreCheckoutRequestBody {
	payment_method?: unknown;
	payment_data?: Array< { key?: unknown; value?: unknown } >;
}

interface BlocksCheckoutExchange {
	requestCount: number;
	responseCount: number;
	/** The fraud-prevention token the submission actually carried, if any. */
	submittedToken: string | undefined;
	savePaymentMethodRequested: boolean;
	gateway: unknown;
}

/**
 * Counts every Store API checkout exchange for an interval and reads what the
 * one submission carried.
 *
 * Registered before the gesture and read after it: the claim's falsifier is a
 * duplicate created by a single submission, and a count taken from the response
 * alone cannot see a second request that raced it.
 */
function observeBlocksCheckoutExchanges(
	page: Page
): () => BlocksCheckoutExchange {
	let requestCount = 0;
	let responseCount = 0;
	let submittedToken: string | undefined;
	let savePaymentMethodRequested = false;
	let gateway: unknown;

	const onRequest = ( request: Request ): void => {
		if ( ! isStoreCheckoutRequest( request ) ) {
			return;
		}
		requestCount += 1;
		let body: StoreCheckoutRequestBody | null = null;
		try {
			body = request.postDataJSON() as StoreCheckoutRequestBody | null;
		} catch {
			// A checkout body this run cannot decode is still a checkout
			// request, and the count above is what the cardinality clause
			// needs; the fields below simply stay unread.
		}
		gateway = body?.payment_method;
		const entries = Array.isArray( body?.payment_data )
			? body?.payment_data ?? []
			: [];
		for ( const entry of entries ) {
			if (
				entry.key === 'wcpay-fraud-prevention-token' &&
				typeof entry.value === 'string' &&
				entry.value !== ''
			) {
				submittedToken = entry.value;
			}
			if ( entry.key === 'wc-woocommerce_payments-new-payment-method' ) {
				savePaymentMethodRequested = entry.value === true;
			}
		}
	};
	const onResponse = ( response: Response ): void => {
		if ( isStoreCheckoutRequest( response.request() ) ) {
			responseCount += 1;
		}
	};

	page.on( 'request', onRequest );
	page.on( 'response', onResponse );

	return () => {
		page.off( 'request', onRequest );
		page.off( 'response', onResponse );
		return {
			requestCount,
			responseCount,
			submittedToken,
			savePaymentMethodRequested,
			gateway,
		};
	};
}

interface CartTotals {
	totalMinor: string;
	currency: string;
	couponCodes: string[];
	itemCount: number;
}

/**
 * The cart as the store itself projects it for this shopper session, which is
 * the authoritative total: a rendered price would carry locale formatting and a
 * currency symbol this claim says nothing about.
 */
async function readCartTotals( page: Page ): Promise< CartTotals > {
	const cart = requireObject(
		await readJson(
			await page.request.get( STORE_CART_API ),
			'Store API cart read'
		),
		'cart'
	);
	const totals = requireObject( cart.totals, 'cart totals' );
	const coupons = Array.isArray( cart.coupons )
		? ( cart.coupons as Array< Record< string, unknown > > )
		: [];

	return {
		totalMinor: String( totals.total_price ),
		currency: String( totals.currency_code ).toUpperCase(),
		couponCodes: coupons.map( ( coupon ) => String( coupon.code ) ),
		itemCount: Number( cart.items_count ),
	};
}

/* -------------------------------------------------------------------------
 * Driving the native Blocks checkout
 * ---------------------------------------------------------------------- */

/**
 * Puts the run-owned product in a guest cart and opens the Blocks checkout with
 * an address on it, stopping short of the payment method.
 *
 * The Blocks address helper is the one `drivers/checkout.ts` exports; choosing
 * the payment method and entering the card are left here because three of the
 * four Blocks cases have something to do between the address and the card — a
 * coupon, a theme, or a session token.
 */
async function openBlocksCheckoutWithProduct(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct
): Promise< void > {
	await session.assertCanWrite();
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );

	// The claim's `B1` input fixes the cart, not only the product: quantity one
	// of the one run-owned product and nothing else. A cart carrying a stray
	// item would still settle a payment, at an amount this claim never named.
	const cart = await readCartTotals( page );
	expect(
		cart.itemCount,
		'the cart must contain quantity one of the run-owned product and nothing else'
	).toBe( 1 );
	expect(
		cart.totalMinor,
		'the cart must be payable at exactly the run-owned amount'
	).toBe( String( AMOUNT_MINOR ) );
	expect( cart.currency ).toBe( CURRENCY );

	await fillBlocksCheckoutAddress( page, session.runId );
}

async function selectBlocksCardAndFill(
	session: ProviderWriteSession,
	page: Page
): Promise< void > {
	session.requireApprovedProviderFixture( CAPABILITY_CARD );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();

	session.requireApprovedProviderFixture( CAPABILITY_CARD_ENTRY );
	const frame = page.frameLocator(
		getBlocksCardFrameSelector( session.runtime )
	);
	await enterProviderCardTriple( frame, BASIC_CARD, 'Blocks checkout' );
	await page.getByRole( 'button', { name: /place order/i } ).focus();
}

interface BlocksPurchase {
	orderId: number;
	exchange: BlocksCheckoutExchange;
}

/**
 * Activates Place order exactly once and waits for the receipt.
 *
 * The whole interval is inside one provider submission journal, so a run that
 * dies between the gesture and a proven outcome leaves an unresolved attempt
 * rather than a silent charge.
 */
async function placeBlocksOrder(
	session: ProviderWriteSession,
	page: Page,
	journal: string
): Promise< BlocksPurchase > {
	const readExchange = observeBlocksCheckoutExchanges( page );
	try {
		return await session.withProviderSubmissionJournal(
			journal,
			async () => {
				try {
					await submitBlocksCheckout( page, async ( button ) => {
						await session.performWrite( () => button.click() );
						return 'dispatched';
					} );
					await page.waitForURL(
						/\/order-received\/[1-9]\d*\/?(?:\?.*)?$/,
						{ timeout: RECEIPT_TIMEOUT_MS }
					);
				} catch ( error ) {
					// A submission the driver proved it never dispatched is the
					// one failure that must stay unwrapped: the journal closes
					// its own attempt for it, and no provider resource is
					// quarantined for a run that never wrote.
					if ( error instanceof ProviderSubmissionNotStartedError ) {
						throw error;
					}
					throw quarantine(
						'A Blocks basic-card submission has no proven outcome.',
						'uncertain-provider-write',
						error
					);
				}

				const orderId = session.getOrderIdFromUrl( page.url() );
				await session.setOrderRunId( orderId, session.runId );
				return { orderId, exchange: readExchange() };
			}
		);
	} finally {
		// Idempotent: the happy path already detached the listeners, and a
		// failure must not leave them attached to a reused page.
		readExchange();
	}
}

interface BlocksRejection {
	status: number;
	code: unknown;
	message: unknown;
	exchange: BlocksCheckoutExchange;
	url: string;
}

/**
 * Activates Place order exactly once with the session token removed and reports
 * how native turned the submission away.
 */
async function submitTokenlessBlocksOrder(
	session: ProviderWriteSession,
	page: Page,
	journal: string
): Promise< BlocksRejection > {
	const readExchange = observeBlocksCheckoutExchanges( page );
	try {
		return await session.withProviderSubmissionJournal(
			journal,
			async () => {
				const checkoutResponse = page.waitForResponse(
					( response ) =>
						isStoreCheckoutRequest( response.request() ),
					{ timeout: CHECKOUT_RESPONSE_TIMEOUT_MS }
				);
				await submitBlocksCheckout( page, async ( button ) => {
					await session.performWrite( () => button.click() );
					return 'dispatched';
				} );

				let response;
				try {
					response = await checkoutResponse;
				} catch ( error ) {
					throw quarantine(
						'A tokenless Blocks submission produced no checkout response, so its outcome is unknown.',
						'uncertain-provider-write',
						error
					);
				}
				const body = requireObject(
					await response.json(),
					'Store API checkout response'
				);

				return {
					status: response.status(),
					code: body.code,
					message: body.message,
					exchange: readExchange(),
					url: page.url(),
				};
			}
		);
	} finally {
		readExchange();
	}
}

/* -------------------------------------------------------------------------
 * The Blocks fraud-prevention token, read and removed the way the page reads it
 * ---------------------------------------------------------------------- */

/**
 * Reads the token native's Blocks payment method would submit right now.
 *
 * Mirrors `getFraudPreventionToken()` in
 * `client/blocks/assets/js/extensions/payment-methods/woopayments/index.js`
 * exactly, including its nullish-coalescing order: the window global wins when
 * it is defined at all, even as an empty string, and the payment-method data is
 * the fallback. Reading it any other way would let a submission carry a token
 * this run believed was gone.
 */
async function readBlocksFraudPreventionToken( page: Page ): Promise< string > {
	return page.evaluate( () => {
		const browserWindow = window as Window & {
			wcpayFraudPreventionToken?: unknown;
			wcSettings?: {
				paymentMethodData?: Record< string, unknown >;
			};
		};
		const settings = browserWindow.wcSettings?.paymentMethodData
			?.woocommerce_payments as
			| { fraudPreventionToken?: unknown }
			| undefined;
		const value =
			browserWindow.wcpayFraudPreventionToken ??
			settings?.fraudPreventionToken ??
			'';
		return typeof value === 'string' ? value : '';
	} );
}

/**
 * Removes the token from both places the Blocks payment method reads it.
 *
 * This is the card-testing shape stated as a shopper-side fact: a client that
 * submits the checkout without the session token native handed it. The caller
 * must re-read the effective token afterwards and refuse to submit while one
 * remains — a submission that still carried a valid token would settle a real
 * payment instead of proving a rejection.
 */
async function clearBlocksFraudPreventionToken( page: Page ): Promise< void > {
	await page.evaluate( () => {
		const browserWindow = window as Window & {
			wcpayFraudPreventionToken?: unknown;
			wcSettings?: {
				paymentMethodData?: Record< string, unknown >;
			};
		};
		browserWindow.wcpayFraudPreventionToken = '';
		const settings = browserWindow.wcSettings?.paymentMethodData
			?.woocommerce_payments as
			| { fraudPreventionToken?: unknown }
			| undefined;
		if ( settings ) {
			settings.fraudPreventionToken = '';
		}
	} );
}

/* -------------------------------------------------------------------------
 * Reading the money graph
 * ---------------------------------------------------------------------- */

interface ProviderIntentFacts {
	id: string;
	customerId: string;
	nextAction: unknown;
	setupFutureUsage: unknown;
}

async function readProviderIntentFacts(
	session: ProviderWriteSession,
	intentId: string
): Promise< ProviderIntentFacts > {
	const intent = requireObject(
		await readJson(
			await session.adminApi.get(
				`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
					intentId
				) }`
			),
			`provider intent ${ intentId }`
		),
		'provider intent'
	);
	const customer = intent.customer;
	const customerId =
		typeof customer === 'object' && customer !== null && 'id' in customer
			? ( customer as { id?: unknown } ).id
			: customer;

	return {
		id: String( intent.id ),
		customerId: typeof customerId === 'string' ? customerId : '',
		nextAction: intent.next_action,
		setupFutureUsage: intent.setup_future_usage,
	};
}

interface OrderRecord {
	status: string;
	total: string;
	currency: string;
	discountTotal: string;
	couponCodes: string[];
	customerId: unknown;
}

async function readOrderRecord(
	session: ProviderWriteSession,
	orderId: number
): Promise< OrderRecord > {
	const order = requireObject(
		await readJson(
			await session.adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
			`WooCommerce order ${ orderId }`
		),
		'order'
	);
	const couponLines = Array.isArray( order.coupon_lines )
		? ( order.coupon_lines as Array< Record< string, unknown > > )
		: [];

	return {
		status: String( order.status ),
		total: String( order.total ),
		currency: String( order.currency ).toUpperCase(),
		discountTotal: String( order.discount_total ),
		couponCodes: couponLines.map( ( line ) => String( line.code ) ),
		customerId: order.customer_id,
	};
}

/**
 * Polls the exact order, PaymentIntent and charge until two consecutive reads
 * two seconds apart return the same terminal identities, amounts, currencies
 * and statuses — the claim's convergence rule, literally.
 *
 * A payment that never converges is one this run cannot account for, so the
 * budget expiring quarantines rather than merely failing.
 */
async function convergeSettledPayment(
	session: ProviderWriteSession,
	orderId: number
): Promise< PaymentEvidence > {
	const deadline = Date.now() + SETTLEMENT_BUDGET_MS;
	let previous = '';
	let lastError: unknown;

	for (;;) {
		let current: PaymentEvidence | undefined;
		try {
			current = await getPaymentEvidence( session.adminApi, orderId );
		} catch ( error ) {
			lastError = error;
		}

		if ( current && current.providerStatus === 'succeeded' ) {
			const serialized = JSON.stringify( current );
			if ( serialized === previous ) {
				return current;
			}
			previous = serialized;
		}

		if ( Date.now() >= deadline ) {
			throw quarantine(
				`Order ${ orderId } never reached a stable settled provider payment within ${ SETTLEMENT_BUDGET_MS }ms.`,
				'uncertain-provider-write',
				lastError
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/**
 * The exact provider graph one USD 10.99 basic-card submission must leave: one
 * intent, one captured charge, one capture, one paid order, and nothing else.
 */
function expectSingleSettledGraph(
	payment: PaymentEvidence,
	expected: { orderId: number; runId: string }
): void {
	expect( payment.orderId ).toBe( expected.orderId );
	expect( payment.runId ).toBe( expected.runId );
	expect( payment.intentId ).toMatch( /^pi_/ );
	expect( payment.chargeId ).toMatch( /^ch_|^py_/ );
	expect( payment.paymentMethodId ).toMatch( /^pm_/ );
	expect( payment.amountMinor ).toBe( AMOUNT_MINOR );
	expect( payment.currency ).toBe( CURRENCY );
	expect( payment.providerStatus ).toBe( 'succeeded' );
	expect( payment.chargeStatus ).toBe( 'succeeded' );
	expect( payment.chargeCaptured ).toBe( true );
	expect(
		payment.occurrenceCount,
		'one submission must leave exactly one charge on the intent'
	).toBe( 1 );
	expect(
		payment.captureOccurrenceCount,
		'one submission must leave exactly one capture on the intent'
	).toBe( 1 );
	expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
}

/**
 * The negative half of the claim's cardinality clause: this purchase asked for
 * no future use of the card, raised no challenge, and left the provider
 * customer holding nothing reusable.
 */
async function expectNoReusableCredential(
	session: ProviderWriteSession,
	payment: PaymentEvidence
): Promise< string > {
	const intent = await readProviderIntentFacts( session, payment.intentId );
	expect( intent.id ).toBe( payment.intentId );
	expect(
		intent.nextAction ?? null,
		'a basic-card purchase must raise no challenge'
	).toBeNull();
	expect(
		intent.setupFutureUsage ?? null,
		'a purchase that saves nothing must set up no future usage'
	).toBeNull();
	expect(
		intent.customerId,
		'the settled intent must name the provider customer it was drawn on'
	).not.toBe( '' );
	expect(
		await readProviderCustomerPaymentMethodIds(
			session,
			intent.customerId
		),
		'a purchase that saves nothing must attach no reusable payment method'
	).toEqual( [] );

	return intent.customerId;
}

/**
 * Requires the run's provider customer to stay empty, and the admitted payment
 * to stay exactly as it settled, for a whole interval.
 *
 * This is the claim's empty-provider-object interval for a refused submission.
 * The refused submission itself has no provider customer — it never reached the
 * provider — so the customer enumerated here is the one the admitted half drew
 * on, which is the same shopper session and the only provider identity this run
 * owns.
 */
async function expectEmptyProviderInterval(
	session: ProviderWriteSession,
	providerCustomerId: string,
	admitted: PaymentEvidence,
	durationMs: number
): Promise< void > {
	const deadline = Date.now() + durationMs;
	for (;;) {
		expect(
			await readProviderCustomerPaymentMethodIds(
				session,
				providerCustomerId
			),
			'a refused submission must attach nothing to the run provider customer'
		).toEqual( [] );
		expect(
			await getPaymentEvidence( session.adminApi, admitted.orderId ),
			'a refused submission must not add to the admitted payment'
		).toEqual( admitted );

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			return;
		}
		await delay( Math.min( POLL_INTERVAL_MS, remaining ) );
	}
}

/**
 * Everything a refused submission must have left behind at the store: no paid
 * order, no order carrying provider identifiers, and every order it did create
 * attributed to this run so a later reader can tell it from someone else's.
 */
async function expectRefusedSubmissionLeftNoPayment(
	session: ProviderWriteSession,
	baselineOrderId: number
): Promise< void > {
	const delta = await readOrderDeltaAfter( session, baselineOrderId );
	expect(
		delta.paidOrderIds,
		'a refused submission must leave no paid order'
	).toEqual( [] );
	expect(
		delta.providerLinkedOrderIds,
		'a refused submission must create no payment context'
	).toEqual( [] );

	// WooCommerce makes an order before it calls the gateway, so an unpaid
	// order in this window is expected. The refusal answers with no order ID,
	// so it cannot be tagged at dispatch; tag it now.
	for ( const leftoverOrderId of delta.newOrderIds ) {
		await session.setOrderRunId( leftoverOrderId, session.runId );
	}
}

/* -------------------------------------------------------------------------
 * The run-owned free coupon
 * ---------------------------------------------------------------------- */

interface RunCoupon {
	id: number;
	code: string;
}

/**
 * Creates the one full-discount coupon `B1c` applies and removes.
 *
 * A hundred-percent coupon is what makes the case's question sharp: while it is
 * applied the checkout has nothing to charge, so a removal that did not restore
 * the payable state would produce no provider object at all rather than a wrong
 * one, and a removal that only half-restored would charge the wrong amount.
 */
async function createRunCoupon(
	session: ProviderWriteSession
): Promise< RunCoupon > {
	const code = `wc-e2e-free-${ session.runId }`.toLowerCase();
	const response = await session.performWrite( () =>
		session.adminApi.post( COUPONS_API, {
			data: {
				code,
				discount_type: 'percent',
				amount: '100',
				individual_use: true,
				meta_data: [
					{
						key: '_e2e_woopayments_run_id',
						value: session.runId,
					},
				],
			},
		} )
	);
	const coupon = requireObject(
		await readJson( response, 'run-owned coupon creation' ),
		'coupon'
	);
	if ( typeof coupon.id !== 'number' || coupon.id <= 0 ) {
		throw new Error( 'Run-owned coupon creation returned no numeric ID.' );
	}

	return { id: coupon.id, code: String( coupon.code ) };
}

/**
 * Removes the run-owned coupon and proves it is gone. A coupon left behind is a
 * store-wide discount the next run could apply by accident, so a deletion that
 * cannot be proven quarantines.
 */
async function deleteRunCoupon(
	session: ProviderWriteSession,
	coupon: RunCoupon
): Promise< void > {
	try {
		const deleted = await session.performWrite( () =>
			session.adminApi.delete(
				`${ COUPONS_API }/${ coupon.id }?force=true`,
				{ failOnStatusCode: false }
			)
		);
		if ( ! deleted.ok() ) {
			throw new Error(
				`Coupon deletion answered HTTP ${ deleted.status() }.`
			);
		}
		const reread = await session.adminApi.get(
			`${ COUPONS_API }/${ coupon.id }`,
			{ failOnStatusCode: false }
		);
		if ( reread.status() !== 404 ) {
			throw new Error(
				`Coupon ${ coupon.code } is still readable after deletion.`
			);
		}
	} catch ( error ) {
		throw quarantine(
			`The run-owned coupon ${ coupon.code } could not be removed.`,
			'cleanup-failed',
			error
		);
	}
}

/**
 * Wait out, or dismiss, every snackbar notice the checkout is showing.
 *
 * WooCommerce announces an applied and a removed coupon in a snackbar, and
 * `.wc-block-components-notice-snackbar-list` is `position: fixed` at the
 * bottom-left of the viewport with `pointer-events: all` on each notice — the
 * corner the Place order button occupies. Each notice clears itself after
 * `SNACKBAR_TIMEOUT` (10 seconds), so a case that touches a coupon and then
 * places the order is racing that timer: the click lands on the notice and
 * Playwright reports an intercepted click, which is a statement about a toast
 * rather than about the payment the case exists to prove.
 *
 * The dismissal pass is what makes this fast; the assertion after it is what
 * makes it true. A click is allowed to fail because a notice may time itself
 * out between the count and the click — that is the outcome being asked for —
 * but the surface still has to end up quiet.
 */
async function quietCheckoutSnackbars( page: Page ): Promise< void > {
	const snackbars = page.locator(
		`${ SNACKBAR_LIST_SELECTOR } ${ SNACKBAR_SELECTOR }`
	);
	for ( let pending = await snackbars.count(); pending > 0; pending -= 1 ) {
		await snackbars
			.first()
			.getByRole( 'button', { name: 'Dismiss this notice' } )
			.click( { timeout: SNACKBAR_DISMISS_TIMEOUT_MS } )
			.catch( () => undefined );
	}
	await expect(
		snackbars,
		'a snackbar notice still covers the checkout controls'
	).toHaveCount( 0, { timeout: SNACKBAR_SETTLE_TIMEOUT_MS } );
}

async function applyCouponOnBlocksCheckout(
	page: Page,
	code: string
): Promise< void > {
	const codeField = page.getByLabel( 'Enter code' );
	if ( ! ( await codeField.isVisible() ) ) {
		await page.getByRole( 'button', { name: 'Add coupons' } ).click();
	}
	await codeField.fill( code );
	await page.getByRole( 'button', { name: 'Apply', exact: true } ).click();
	await expect(
		page.getByLabel( `Remove coupon "${ code }"` ),
		'the applied coupon must be offered back to the shopper as removable'
	).toBeVisible();
	await quietCheckoutSnackbars( page );
}

async function removeCouponOnBlocksCheckout(
	page: Page,
	code: string
): Promise< void > {
	const removeControl = page.getByLabel( `Remove coupon "${ code }"` );
	await removeControl.click();
	await expect(
		removeControl,
		'a removed coupon must stop being offered'
	).toBeHidden();
	await quietCheckoutSnackbars( page );
}

/* -------------------------------------------------------------------------
 * The Classic surface, for the protection-on twin
 * ---------------------------------------------------------------------- */

/**
 * Prepares one Classic basic-card submission, stopping just before Place order.
 *
 * This deliberately does not reuse `prepareClassicCardCheckout`: that helper
 * requires the `card-authentication` fixture capability because every other
 * caller answers a challenge, and this family's card raises none. Requiring a
 * capability a case cannot exercise would make the approval say something
 * untrue. The steps are the Classic browser's own public ones, in the order
 * `completeClassicCardCheckout` drives them.
 */
async function prepareClassicBasicCardCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	pageId: number
): Promise< PreparedClassicCardCheckout > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( CAPABILITY_CARD );
	session.requireApprovedProviderFixture( CAPABILITY_CARD_ENTRY );

	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		pageId
	);
	await browser.preflightClassicPage( CLASSIC_CHECKOUT_PATH, pageId );
	let addActivations = 0;
	await browser.addProductOnce( product.id, async ( write ) => {
		addActivations += 1;
		expect(
			addActivations,
			'Add to cart must be activated exactly once'
		).toBe( 1 );
		return session.performWrite( write );
	} );
	await browser.openClassicCheckout( CLASSIC_CHECKOUT_PATH );
	await browser.fillBillingDetails( session.runId );
	await browser.selectWooPaymentsCard();
	await browser.fillTestCard( BASIC_CARD );
	await browser.prepareSubmission();

	return {
		browser,
		runId: session.runId,
		orderTotal: product.amount,
		card: BASIC_CARD,
		savePaymentMethod: false,
	};
}

/**
 * The admitted Classic submission: one gateway, nothing saved, and three
 * independently captured tokens that have to be the same one.
 *
 * `authoritativeSession` comes from the store's own session row through the
 * protection controller's WP-CLI read, `exposed` from the page, and `submitted`
 * from the wire. The driver already refuses to proceed when they disagree; they
 * are restated here because "a token-bearing submission" is the contract's
 * premise, and a premise the case does not observe is a premise it is
 * assuming.
 */
function expectAdmittedClassicSubmission(
	evidence: ClassicCardCheckoutEvidence
): void {
	expect( evidence.request.gateway ).toBe( 'woocommerce_payments' );
	expect(
		evidence.request.savePaymentMethod,
		'a purchase that saves nothing must not ask to save'
	).toBe( false );

	const sessionToken = evidence.tokens.authoritativeSession;
	expect( sessionToken ).toHaveLength( 16 );
	expect( sessionToken.sha256 ).toMatch( /^[a-f0-9]{64}$/ );
	expect(
		evidence.tokens.exposed,
		'the token the page exposed must be the token the session holds'
	).toEqual( sessionToken );
	expect(
		evidence.tokens.submitted,
		'the admitted submission must carry the exact session token'
	).toEqual( sessionToken );
	expect( evidence.response.orderId ).toBe( evidence.receipt.orderId );
	expect( evidence.response.orderKey ).toBe( evidence.receipt.orderKey );
}

/* -------------------------------------------------------------------------
 * The cases
 * ---------------------------------------------------------------------- */

test.describe( 'WooPayments native basic card charge fidelity', () => {
	// Serial on purpose. Two cases provision the shared `classic-checkout`
	// page or the store's active theme and must never overlap, and a failure
	// must halt the cases after it rather than spend more provider budget on a
	// store whose state is no longer described.
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test(
		'One Blocks checkout submission with the basic card settles exactly one run-owned order, one succeeded 1099 usd PaymentIntent, and one captured charge, and creates no second order, intent, charge, capture, challenge, or reusable credential',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_B1 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, B1_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'basic-card-charge-b1' },
				async () => {
					const baselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const product =
						await pilotRuntime.createOwnedProduct( PRICE );

					await openBlocksCheckoutWithProduct(
						pilotRuntime,
						page,
						product
					);
					await selectBlocksCardAndFill( pilotRuntime, page );
					const purchase = await placeBlocksOrder(
						pilotRuntime,
						page,
						'basic-card-blocks-checkout'
					);

					// One gesture, one exchange, one gateway, nothing saved.
					expect(
						purchase.exchange.requestCount,
						'one Place order activation must ask the Store API exactly once'
					).toBe( 1 );
					expect( purchase.exchange.responseCount ).toBe( 1 );
					expect( purchase.exchange.gateway ).toBe(
						'woocommerce_payments'
					);
					expect( purchase.exchange.savePaymentMethodRequested ).toBe(
						false
					);

					const payment = await convergeSettledPayment(
						pilotRuntime,
						purchase.orderId
					);
					expectSingleSettledGraph( payment, {
						orderId: purchase.orderId,
						runId: pilotRuntime.runId,
					} );
					await expectNoReusableCredential( pilotRuntime, payment );

					// The card the provider actually charged, not the card the
					// browser was told to type.
					expect(
						await readProviderCardEvidence(
							pilotRuntime.adminApi,
							payment
						)
					).toEqual( { type: 'card', brand: 'visa', last4: '4242' } );

					// Exactly one order, and the cart the purchase emptied
					// stays empty: no second order, no unowned delta.
					const delta = await readOrderDeltaAfter(
						pilotRuntime,
						baselineOrderId
					);
					expect(
						delta.newOrderIds,
						'one submission must create exactly one order'
					).toEqual( [ purchase.orderId ] );
					expect( delta.paidOrderIds ).toEqual( [
						purchase.orderId,
					] );
					expect( ( await readCartTotals( page ) ).itemCount ).toBe(
						0
					);

					const order = await readOrderRecord(
						pilotRuntime,
						purchase.orderId
					);
					expect( order.total ).toBe( PRICE );
					expect( order.currency ).toBe( CURRENCY );
					expect( order.couponCodes ).toEqual( [] );
				}
			);
		}
	);

	test(
		'A Blocks checkout that applies and then removes a free coupon before its single submission settles a USD 10.99 order carrying no coupon line and no discount, and the same one-intent, one-captured-charge 1099 usd provider graph',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_B1C },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, B1C_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'basic-card-charge-b1c' },
				async () => {
					const baselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const product =
						await pilotRuntime.createOwnedProduct( PRICE );
					const coupon = await createRunCoupon( pilotRuntime );
					let scenarioFailure: unknown;

					try {
						await openBlocksCheckoutWithProduct(
							pilotRuntime,
							page,
							product
						);

						// Applied: the checkout has nothing left to charge.
						await applyCouponOnBlocksCheckout( page, coupon.code );
						const discounted = await readCartTotals( page );
						expect(
							discounted.couponCodes,
							'the applied coupon must be the only one on the cart'
						).toEqual( [ coupon.code ] );
						expect(
							discounted.totalMinor,
							'a full-discount coupon must leave nothing payable'
						).toBe( '0' );

						// Removed: the payable state must come back exactly.
						await removeCouponOnBlocksCheckout( page, coupon.code );
						const restored = await readCartTotals( page );
						expect(
							restored.couponCodes,
							'the removed coupon must be gone from the cart'
						).toEqual( [] );
						expect(
							restored.totalMinor,
							'removing the coupon must restore the exact payable total'
						).toBe( String( AMOUNT_MINOR ) );
						expect( restored.currency ).toBe( CURRENCY );

						await selectBlocksCardAndFill( pilotRuntime, page );
						const purchase = await placeBlocksOrder(
							pilotRuntime,
							page,
							'basic-card-blocks-coupon-checkout'
						);

						expect(
							purchase.exchange.requestCount,
							'one Place order activation must ask the Store API exactly once'
						).toBe( 1 );
						expect( purchase.exchange.responseCount ).toBe( 1 );

						// The local order the removal produced: the coupon is
						// excluded from the final total, not merely invisible.
						const order = await readOrderRecord(
							pilotRuntime,
							purchase.orderId
						);
						expect(
							order.total,
							'the order total after removal must be exactly USD 10.99'
						).toBe( PRICE );
						expect( order.currency ).toBe( CURRENCY );
						expect(
							order.couponCodes,
							'the removed coupon must leave no line on the order'
						).toEqual( [] );
						expect(
							order.discountTotal,
							'the removed coupon must discount nothing'
						).toBe( '0.00' );

						// And the same graph `B1` proved reached the provider.
						const payment = await convergeSettledPayment(
							pilotRuntime,
							purchase.orderId
						);
						expectSingleSettledGraph( payment, {
							orderId: purchase.orderId,
							runId: pilotRuntime.runId,
						} );
						await expectNoReusableCredential(
							pilotRuntime,
							payment
						);

						expect(
							(
								await readOrderDeltaAfter(
									pilotRuntime,
									baselineOrderId
								)
							).newOrderIds,
							'one submission must create exactly one order'
						).toEqual( [ purchase.orderId ] );
					} catch ( error ) {
						scenarioFailure = error;
					}

					await deleteRunCoupon( pilotRuntime, coupon );
					if ( scenarioFailure !== undefined ) {
						throw scenarioFailure;
					}
				}
			);
		}
	);

	test(
		'Card-testing protection admits one token-bearing classic basic-card submission to the same 1099 usd one-intent, one-captured-charge graph, and a tokenless submission on the same session creates no payment context and no provider object at all',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_B1P },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, B1P_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			// FORCED PREMISE, see the file header: the account reports
			// `card_testing_protection_eligible: false`, so the controller
			// asserts eligibility into the local account cache, byte-restores
			// it, and verifies the restore. This proves native's enforcement,
			// not the provider's grant of the capability.
			await withCapturedCardTestingProtectionState(
				pilotRuntime,
				pilotRuntime.runId,
				async ( scope: CardTestingProtectionScope ) => {
					await scope.registerFreshContext( page );
					const baselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const product =
						await pilotRuntime.createOwnedProduct( PRICE );

					// Half one: a token-bearing submission settles exactly as
					// the protection-off graph does. The Classic driver reads
					// the session token itself and refuses to proceed unless
					// the exposed, session and submitted tokens agree.
					const admitted = await completeClassicCardCheckout(
						pilotRuntime,
						page,
						product,
						pilotRuntime.runId,
						scope
					);
					expectAdmittedClassicSubmission( admitted );

					const payment = await convergeSettledPayment(
						pilotRuntime,
						admitted.orderId
					);
					expectSingleSettledGraph( payment, {
						orderId: admitted.orderId,
						runId: pilotRuntime.runId,
					} );
					const providerCustomerId = await expectNoReusableCredential(
						pilotRuntime,
						payment
					);

					// Half two: the same store, the same session, one
					// submission with the session token absent. Without this a
					// run where protection silently failed to engage would pass
					// exactly like the one above.
					const tokenlessBaselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const prepared = await prepareClassicBasicCardCheckout(
						pilotRuntime,
						page,
						product,
						scope.classicCheckout.pageId
					);
					const rejection = await submitClassicTokenlessCheckout(
						pilotRuntime,
						prepared,
						{ journal: 'basic-card-classic-tokenless' }
					);

					expect( rejection.checkoutRequestCount ).toBe( 1 );
					expect( rejection.checkoutResponseCount ).toBe( 1 );
					expect( rejection.request.gateway ).toBe(
						'woocommerce_payments'
					);
					expect(
						rejection.request.fraudPreventionToken,
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
						rejection.notice.alertCount,
						'the shopper must be told, once, through an assertive notice'
					).toBe( 1 );
					expect( rejection.notice.messages ).toEqual( [
						CARD_TESTING_REJECTION_TEXT,
					] );
					expect( rejection.url ).not.toContain( 'order-received' );
					expect( rejection.recovery.onClassicCheckout ).toBe( true );
					expect( rejection.recovery.placeOrderEnabled ).toBe( true );

					// No payment context, and nothing appears at the provider
					// during the interval that follows.
					//
					// Boundary, stated rather than glossed: the browser
					// tokenizes the card with the provider before it submits,
					// so a PaymentMethod object does come into existence. It is
					// created by the shopper's browser against the provider's
					// public API, attached to no customer and charged nothing,
					// and it is not what native creates. What must not exist is
					// what Core's own rejection test names: no payment context
					// — no intent, no charge, no paid order.
					await expectRefusedSubmissionLeftNoPayment(
						pilotRuntime,
						tokenlessBaselineOrderId
					);
					await expectEmptyProviderInterval(
						pilotRuntime,
						providerCustomerId,
						payment,
						EMPTY_INTERVAL_MS
					);

					// Across both halves, exactly one paid order exists.
					expect(
						(
							await readOrderDeltaAfter(
								pilotRuntime,
								baselineOrderId
							)
						).paidOrderIds,
						'the admitted submission is the only one that may have paid'
					).toEqual( [ admitted.orderId ] );
				}
			);
		}
	);

	test(
		'The same Blocks basic-card submission under a deliberately activated supported block theme settles the same one-order, one-intent, one-captured-charge 1099 usd graph, and the store is returned to its own theme',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_B1F },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, B1F_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{
					featureSetting: 'active-theme',
					recordEvent: 'basic-card-charge-b1f',
				},
				async () => {
					await withActiveBlockTheme(
						pilotRuntime,
						pilotRuntime.runId,
						FSE_THEME,
						async ( theme ) => {
							expect(
								theme.baseline.stylesheet,
								'the case must activate a theme the store does not already run'
							).not.toBe( FSE_THEME );
							expect( theme.active.stylesheet ).toBe( FSE_THEME );

							const baselineOrderId =
								await readHighestOrderId( pilotRuntime );
							const product =
								await pilotRuntime.createOwnedProduct( PRICE );

							await openBlocksCheckoutWithProduct(
								pilotRuntime,
								page,
								product
							);

							// The surface really is the Site Editor one:
							// WooCommerce prints `wp_is_block_theme()` onto the
							// body, and the theme that decided it is named
							// there too.
							await expect(
								page.locator(
									`body.${ BLOCK_THEME_BODY_CLASS }.wp-theme-${ FSE_THEME }`
								),
								'the checkout must render under the activated block theme'
							).toHaveCount( 1 );

							await selectBlocksCardAndFill( pilotRuntime, page );
							const purchase = await placeBlocksOrder(
								pilotRuntime,
								page,
								'basic-card-blocks-fse-checkout'
							);

							expect(
								purchase.exchange.requestCount,
								'one Place order activation must ask the Store API exactly once'
							).toBe( 1 );
							expect( purchase.exchange.responseCount ).toBe( 1 );
							expect( purchase.exchange.gateway ).toBe(
								'woocommerce_payments'
							);

							const payment = await convergeSettledPayment(
								pilotRuntime,
								purchase.orderId
							);
							expectSingleSettledGraph( payment, {
								orderId: purchase.orderId,
								runId: pilotRuntime.runId,
							} );
							await expectNoReusableCredential(
								pilotRuntime,
								payment
							);

							expect(
								(
									await readOrderDeltaAfter(
										pilotRuntime,
										baselineOrderId
									)
								).newOrderIds,
								'one submission must create exactly one order'
							).toEqual( [ purchase.orderId ] );
						}
					);
				}
			);
		}
	);

	test(
		'Under a deliberately activated supported block theme, card-testing protection admits one token-bearing Blocks basic-card submission to the same 1099 usd one-intent, one-captured-charge graph, and a tokenless submission on the same session creates no payment context and no provider object at all',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_B1PF },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, B1PF_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			// Same forced-eligibility premise as the Classic twin, and the same
			// boundary: this establishes native's enforcement on this surface,
			// not the provider's grant of the capability. The protection
			// controller owns the lock scope, so the theme scope nests inside
			// it rather than acquiring locks of its own.
			await withCapturedCardTestingProtectionState(
				pilotRuntime,
				pilotRuntime.runId,
				async ( scope: CardTestingProtectionScope ) => {
					await scope.registerFreshContext( page );

					await withActiveBlockTheme(
						pilotRuntime,
						pilotRuntime.runId,
						FSE_THEME,
						async ( theme ) => {
							expect( theme.active.stylesheet ).toBe( FSE_THEME );

							const baselineOrderId =
								await readHighestOrderId( pilotRuntime );
							const product =
								await pilotRuntime.createOwnedProduct( PRICE );

							await openBlocksCheckoutWithProduct(
								pilotRuntime,
								page,
								product
							);
							await expect(
								page.locator(
									`body.${ BLOCK_THEME_BODY_CLASS }.wp-theme-${ FSE_THEME }`
								),
								'the checkout must render under the activated block theme'
							).toHaveCount( 1 );

							// The token the session holds, read from the store
							// rather than from the page, and the token the page
							// would submit. They must be the same one.
							const sessionToken =
								await scope.captureGuestSessionToken( page );
							expect(
								digestToken(
									await readBlocksFraudPreventionToken( page )
								),
								'the token the page exposes must be the token the session holds'
							).toEqual( sessionToken );

							await selectBlocksCardAndFill( pilotRuntime, page );
							const purchase = await placeBlocksOrder(
								pilotRuntime,
								page,
								'basic-card-blocks-fse-protected-checkout'
							);

							expect(
								purchase.exchange.requestCount,
								'one Place order activation must ask the Store API exactly once'
							).toBe( 1 );
							expect( purchase.exchange.responseCount ).toBe( 1 );
							expect(
								purchase.exchange.savePaymentMethodRequested
							).toBe( false );
							expect(
								purchase.exchange.submittedToken,
								'the admitted submission must have carried a session token'
							).not.toBeUndefined();
							expect(
								digestToken(
									purchase.exchange.submittedToken ?? ''
								),
								'the admitted submission must carry the exact session token'
							).toEqual( sessionToken );

							const payment = await convergeSettledPayment(
								pilotRuntime,
								purchase.orderId
							);
							expectSingleSettledGraph( payment, {
								orderId: purchase.orderId,
								runId: pilotRuntime.runId,
							} );
							const providerCustomerId =
								await expectNoReusableCredential(
									pilotRuntime,
									payment
								);

							// Half two: the same store, the same session, one
							// submission with the session token removed from
							// both places native's Blocks payment method reads
							// it.
							const tokenlessBaselineOrderId =
								await readHighestOrderId( pilotRuntime );
							await openBlocksCheckoutWithProduct(
								pilotRuntime,
								page,
								product
							);
							await selectBlocksCardAndFill( pilotRuntime, page );
							await clearBlocksFraudPreventionToken( page );
							expect(
								await readBlocksFraudPreventionToken( page ),
								'a submission that still exposed a token would settle a payment instead of proving a rejection'
							).toBe( '' );

							const rejection = await submitTokenlessBlocksOrder(
								pilotRuntime,
								page,
								'basic-card-blocks-fse-tokenless'
							);

							expect(
								rejection.exchange.requestCount,
								'one Place order activation must ask the Store API exactly once'
							).toBe( 1 );
							expect(
								rejection.exchange.submittedToken,
								'the tokenless submission must actually have carried no token'
							).toBeUndefined();
							expect( rejection.status ).toBe( 400 );
							// Core codes this event twice and the outer coding
							// wins. `StoreApi\Legacy::process_legacy_payment()`
							// turns native's queued notice into a
							// `RouteException` coded
							// `woocommerce_rest_payment_error`, and
							// `CheckoutTrait::process_payment()` re-wraps that as
							// `woocommerce_rest_checkout_process_payment_error`
							// while preserving the message. Both are core's own
							// coding of the same refusal, so the code is asserted
							// as a set and the message carries the
							// discrimination — the same reading the declines
							// family arrived at.
							expect( [
								'woocommerce_rest_payment_error',
								'woocommerce_rest_checkout_process_payment_error',
							] ).toContain( rejection.code );
							expect(
								decodeEscapedHtml( String( rejection.message ) )
							).toBe( CARD_TESTING_REJECTION_TEXT );
							expect( rejection.url ).not.toContain(
								'order-received'
							);
							// Blocks announces through WordPress's shared
							// assertive region rather than an alert role on the
							// notice, so losing the announcement fails here
							// rather than passing because the text is on screen
							// somewhere.
							await expect(
								page
									.getByText( CARD_TESTING_REJECTION_TEXT )
									.first()
							).toBeVisible();
							await expect(
								page.locator( '#a11y-speak-assertive' )
							).toHaveText( CARD_TESTING_REJECTION_TEXT );

							await expectRefusedSubmissionLeftNoPayment(
								pilotRuntime,
								tokenlessBaselineOrderId
							);
							await expectEmptyProviderInterval(
								pilotRuntime,
								providerCustomerId,
								payment,
								EMPTY_INTERVAL_MS
							);

							expect(
								(
									await readOrderDeltaAfter(
										pilotRuntime,
										baselineOrderId
									)
								).paidOrderIds,
								'the admitted submission is the only one that may have paid'
							).toEqual( [ purchase.orderId ] );
						}
					);
				}
			);
		}
	);
} );
