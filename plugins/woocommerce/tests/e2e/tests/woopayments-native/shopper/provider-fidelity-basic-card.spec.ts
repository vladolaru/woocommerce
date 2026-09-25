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
import {
	readHighestOrderId,
	readOrderDeltaAfter,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { fillBlocksCheckoutAddress } from '../../../utils/woopayments-native/drivers/checkout';
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `basic-card-charge` provider-fidelity family.
 *
 * `FIDELITY-CLAIMS.md` now keeps one browser smoke here, `B1`: one shopper
 * Blocks checkout submission through native with provider test card
 * `4242424242424242` produces exactly one PaymentIntent and one captured
 * charge at the provider for USD 10.99, correlated to one run-owned Woo order
 * that reaches `processing` or `completed`, and creates no second intent,
 * charge, capture, or order.
 *
 * The other four cases this family used to run in a browser (`B1c`'s coupon
 * apply/remove, `B1p`'s Classic card-testing protection, and the `B1f`/`B1pf`
 * block-theme repeats) moved to PHPUnit and Jest, cited at each moved
 * assertion's `woopayments-contract` annotation elsewhere in the ledger: the
 * request shape without a save request is
 * `WooPaymentsProviderGatewayAdapterTest::test_single_card_checkout_without_save_matches_11_1_request_shape`,
 * the Payment Element's setup/payment mode switch and the card-testing
 * fraud-prevention token exposure are Jest cases next to the client scripts
 * they cover, and card-testing admission/refusal for split gateways is
 * `NativeWooPaymentsGatewayTest`.
 *
 * **Cardinality is the contract, not a by-product.** The claim's falsifier names
 * "a duplicate object created by a single submission" specifically, so `B1`
 * counts what it caused on both sides: Store API checkout requests and
 * responses at the surface, new orders against a baseline order ID at the store,
 * and charge and capture occurrences on the exact intent at the provider. A
 * graph that merely *contains* the right objects would satisfy a weaker suite
 * and prove nothing here.
 */

/* -------------------------------------------------------------------------
 * The ledger rows these cases carry
 * ---------------------------------------------------------------------- */

const CONTRACT_B1 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-purchase.spec.ts:31::WooCommerce Blocks › Successful purchase › using a basic card';

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

/** The claim's convergence rule: poll every 2 seconds, for at most 60. */
const POLL_INTERVAL_MS = 2_000;
const SETTLEMENT_BUDGET_MS = 60_000;
const RECEIPT_TIMEOUT_MS = 60_000;

const STORE_CART_API = '/wp-json/wc/store/v1/cart';
const STORE_CHECKOUT_PATH = '/wp-json/wc/store/v1/checkout';

/* -------------------------------------------------------------------------
 * Capabilities
 * ---------------------------------------------------------------------- */

/** The family's own umbrella capability, as the sibling families declare theirs. */
const CAPABILITY_FAMILY = 'basic-card-charge';
const CAPABILITY_PRODUCT = 'product/payment';
const CAPABILITY_CARD = 'basic-card';
const CAPABILITY_CARD_ENTRY = 'basic-card-entry';

const B1_CAPABILITIES = [
	CAPABILITY_FAMILY,
	CAPABILITY_PRODUCT,
	CAPABILITY_CARD,
	CAPABILITY_CARD_ENTRY,
];

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

/* -------------------------------------------------------------------------
 * Reading the money graph
 * ---------------------------------------------------------------------- */

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

/* -------------------------------------------------------------------------
 * The cases
 * ---------------------------------------------------------------------- */

test.describe( 'WooPayments native basic card charge fidelity', () => {
	test.describe.configure( { timeout: 420_000 } );

	test(
		'One Blocks checkout submission with the basic card settles exactly one run-owned order, one succeeded 1099 usd PaymentIntent, and one captured charge, and creates no second order, intent, charge, capture, or challenge',
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

					// The card the provider actually charged, not the card the
					// browser was told to type. Whether the purchase saved a
					// reusable credential is PHPUnit's now:
					// `WooPaymentsProviderGatewayAdapterTest::test_single_card_checkout_without_save_matches_11_1_request_shape`.
					expect(
						await readProviderCardEvidence(
							pilotRuntime.adminApi,
							payment
						)
					).toEqual( { type: 'card', brand: 'visa', last4: '4242' } );

					// Exactly one order. Cart-emptying and the order's total
					// and coupon lines are WooCommerce core cart behavior, not
					// this claim's subject.
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
				}
			);
		}
	);
} );
