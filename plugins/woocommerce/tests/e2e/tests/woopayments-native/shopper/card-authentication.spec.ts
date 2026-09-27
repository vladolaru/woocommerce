import type { Page, Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { getFakeUser } from '../../../utils/data';
import { random } from '../../../utils/helpers';
import { logIn } from '../../../utils/login';
import {
	completeThreeDSChallenge,
	expectSettledCardPayment,
	fillCardDetails,
	getPaymentIntent,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * Proves the provider's 3D Secure challenge is real and that native carries a
 * completed one through to a paid order (T.4 Batch P4a rewrite).
 *
 * This spec is the reason `utils/woopayments.ts`'s `completeThreeDSChallenge`
 * fails when no challenge is presented: the extension suite's twelve
 * authentication contracts rest on a helper that returns silently when no
 * challenge appears, so eight of their recorded residual risks say some
 * version of "a frictionless or skipped challenge can false-pass". Here the
 * challenge is asserted, answered, and its effect on the order is checked; a
 * card that stops triggering authentication fails the run rather than
 * quietly passing it.
 *
 * The excluded-family decision still stands: this is journey coverage, not a
 * thin fidelity check, and it must not be sold as one.
 */

const CONTRACT_SUCCESS =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-purchase.spec.ts:42::WooCommerce Blocks › Successful purchase › using a 3DS card';
const CONTRACT_DECLINE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card has been declined.';
const CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection false › using a 3DS card',
	CONTRACT_SUCCESS,
];

const FAMILY_TAGS = [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ];

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_STATUSES = [ 'processing', 'completed' ];
const UNPAID_STATUSES = [ 'pending', 'failed' ];
const RECEIPT_TIMEOUT_MS = 30_000;
const CHECKOUT_RESPONSE_TIMEOUT_MS = 60_000;
const STORE_CHECKOUT_PATH = '/wp-json/wc/store/v1/checkout';
const AUTHENTICATION_FRAME =
	'body > div > iframe[name^="__privateStripeFrame"]';
// Native's own copy for payment_intent_authentication_failure.
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';
const CARD_DECLINED_TEXT = 'Your card has been declined.';
const AUTHENTICATION_FAILED_STATUS = 'requires_payment_method';
const INTENT_POLL_TIMEOUT_MS = 60_000;
const INTENT_POLL_INTERVAL_MS = 500;

const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments card-authentication ${ random() }`,
			type: 'simple',
			virtual: true,
			regular_price: PRICE,
			status: 'publish',
		} )
	).data as { id: number };
}

async function deleteProduct(
	restApi: ApiClient,
	productId: number
): Promise< void > {
	await restApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
		force: true,
	} );
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

async function readNewOrders(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< Array< { id: number; status: string } > > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number; status: string } >;
	return orders.filter( ( order ) => order.id > baselineOrderId );
}

/** Whether `error` is the provider's transient "another request holds this object" answer. */
function isLockTimeout( error: unknown ): boolean {
	const status =
		typeof error === 'object' && error !== null && 'response' in error
			? ( error as { response?: { status?: unknown } } ).response?.status
			: undefined;
	return status === 429;
}

function chargeCount( intent: Record< string, unknown > ): number {
	const charges = intent.charges;
	if (
		typeof charges === 'object' &&
		charges !== null &&
		'data' in charges &&
		Array.isArray( ( charges as { data: unknown[] } ).data )
	) {
		return ( charges as { data: unknown[] } ).data.length;
	}
	return 0;
}

interface FailedAuthenticationIntent {
	/** The raw store-mirrored PaymentIntent from the last successful read. */
	raw: Record< string, unknown >;
	id: string;
	status: string;
	lastPaymentErrorCode: unknown;
	chargeCount: number;
}

/**
 * Polls the store's own mirror of the PaymentIntent until it reaches its
 * terminal unauthenticated state, tolerating the provider's transient 429
 * `lock_timeout` on every read, including the first. Returns whatever was
 * last read even past the deadline, so a stuck intent fails on a clear
 * status diff rather than a generic timeout, and returns the raw intent so a
 * caller needing more of its fields does not have to read it again
 * untolerated.
 */
async function pollFailedAuthenticationIntent(
	restApi: ApiClient,
	intentId: string
): Promise< FailedAuthenticationIntent > {
	const deadline = Date.now() + INTENT_POLL_TIMEOUT_MS;
	let intent: Record< string, unknown > | undefined;
	for (;;) {
		try {
			intent = await getPaymentIntent( restApi, intentId );
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}
		if (
			intent?.status === AUTHENTICATION_FAILED_STATUS ||
			Date.now() >= deadline
		) {
			break;
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, INTENT_POLL_INTERVAL_MS )
		);
	}
	if ( ! intent ) {
		throw new Error(
			`Intent ${ intentId } never returned a readable state within ${ INTENT_POLL_TIMEOUT_MS }ms.`
		);
	}

	const lastPaymentError = intent.last_payment_error;
	return {
		raw: intent,
		id: String( intent.id ?? '' ),
		status: String( intent.status ),
		lastPaymentErrorCode:
			typeof lastPaymentError === 'object' && lastPaymentError !== null
				? ( lastPaymentError as { code?: unknown } ).code ?? null
				: null,
		chargeCount: chargeCount( intent ),
	};
}

/**
 * Selects the WooPayments card option, which a returning shopper's checkout
 * may already have selected.
 */
async function selectCardPaymentOption( page: Page ): Promise< void > {
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
}

/**
 * Blocks checkout address and payment-method selection.
 */
async function fillBlocksCheckoutDetails(
	page: Page,
	email: string
): Promise< void > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const address = ( await shipping.isVisible() ) ? shipping : billing;
	const country = address.getByRole( 'combobox', {
		name: 'Country/Region',
	} );

	// A shopper who has checked out before arrives with the address already
	// resolved: the group still renders, collapsed behind an Edit button, with
	// no editable country field. Gate on the field the fill actually needs
	// rather than on the group, which is present either way. Supplying an
	// address is a precondition for reaching payment, not something this spec
	// asserts, so there is nothing to prove by re-entering one.
	if ( ! ( await country.isVisible() ) ) {
		await selectCardPaymentOption( page );
		return;
	}

	await page.getByRole( 'textbox', { name: 'Email address' } ).fill( email );
	await address
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( 'US' );
	await address.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await address
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await address
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( '123 Test Street' );
	await address
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( 'San Francisco' );
	await address
		.getByRole( 'combobox', { name: 'State', exact: true } )
		.selectOption( 'CA' );
	await address.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94107' );
	await address
		.getByRole( 'textbox', { name: 'Phone (optional)' } )
		.fill( '5555550100' );
	await selectCardPaymentOption( page );
}

interface OrderSnapshot {
	status: string;
	total: string;
	currency: string;
	paymentMethod: string;
	orderKey: string;
	intentId: string;
	chargeId: string;
}

async function readOrderSnapshot(
	restApi: ApiClient,
	orderId: number
): Promise< OrderSnapshot > {
	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as {
		status?: unknown;
		total?: unknown;
		currency?: unknown;
		payment_method?: unknown;
		order_key?: unknown;
		meta_data?: unknown;
	};
	if (
		typeof order.status !== 'string' ||
		typeof order.total !== 'string' ||
		typeof order.currency !== 'string' ||
		typeof order.payment_method !== 'string'
	) {
		throw new Error( `Order ${ orderId } carried no status.` );
	}
	const meta = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const metaValue = ( key: string ): string => {
		const entry = meta.find( ( item ) => item.key === key );
		return typeof entry?.value === 'string' ? entry.value : '';
	};

	return {
		status: order.status,
		total: order.total,
		currency: order.currency.toUpperCase(),
		paymentMethod: order.payment_method,
		orderKey: String( order.order_key ?? '' ),
		intentId: metaValue( '_intent_id' ),
		chargeId: metaValue( '_charge_id' ),
	};
}

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

interface BlocksPaymentIntentConfirmation {
	orderId: number;
	intentId: string;
}

/**
 * Reads the durable order and PaymentIntent identities from the customer-action
 * fragment nested in a Blocks checkout response. The client secret and nonce
 * are deliberately discarded; the browser already owns the confirmation.
 */
function findBlocksPaymentIntentConfirmation(
	value: unknown
): BlocksPaymentIntentConfirmation | undefined {
	if ( typeof value === 'string' ) {
		const match = value.match(
			/#wcpay-confirm-pi:([^:]+):([^:]+):([^:]+)(?::.*)?$/
		);
		if ( ! match ) {
			return undefined;
		}

		const orderId = Number( decodeURIComponent( match[ 1 ] ) );
		const clientSecret = decodeURIComponent( match[ 2 ] );
		const intentId = clientSecret.split( '_secret_' )[ 0 ];
		if (
			! Number.isSafeInteger( orderId ) ||
			orderId <= 0 ||
			! intentId ||
			intentId === clientSecret
		) {
			throw new Error(
				'The Blocks checkout confirmation carried no usable order and PaymentIntent identity.'
			);
		}
		return { orderId, intentId };
	}

	if ( typeof value !== 'object' || value === null ) {
		return undefined;
	}
	for ( const child of Object.values( value ) ) {
		const confirmation = findBlocksPaymentIntentConfirmation( child );
		if ( confirmation ) {
			return confirmation;
		}
	}
	return undefined;
}

interface BlocksAuthenticationDispatch {
	orderId: number;
	intentId: string;
	orderKey: string;
	responseStatus: number;
	checkoutRequestCount: number;
	checkoutResponseCount: number;
	orderStatusUpdates: Array< { orderId: string; intentId: string } >;
}

interface ChallengeCheckoutOutcome {
	dispatch: BlocksAuthenticationDispatch;
	reachedReceipt: boolean;
	url: string;
}

/**
 * Drives one Blocks checkout with the challenge card and answers the
 * challenge the way the caller asks.
 *
 * `completeThreeDSChallenge` (`utils/woopayments.ts`) fails when no challenge
 * is presented and does not return until the challenge surface is answered
 * and dismissed, so reaching the line after it already proves the challenge
 * was presented, ready, answered as instructed, and closed - the properties
 * the retired `CardAuthenticationEvidence` object used to carry explicitly.
 */
async function checkoutWithChallenge(
	page: Page,
	product: { id: number },
	options: {
		card: { number: string; expiry: string; cvc: string };
		response: 'complete' | 'fail';
		expected: 'receipt' | 'error';
		errorText?: string;
		email: string;
	}
): Promise< ChallengeCheckoutOutcome > {
	// Same navigation and add-to-cart shape a plain card purchase uses, so this
	// journey differs from one only in the card and the challenge that follows.
	await page.goto( `?post_type=product&p=${ product.id }` );
	await page
		.getByRole( 'button', { name: 'Add to cart', exact: true } )
		.click();
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutDetails( page, options.email );
	await fillCardDetails( page, options.card, 'blocks' );
	await page.getByRole( 'button', { name: /place order/i } ).focus();

	let checkoutRequestCount = 0;
	let checkoutResponseCount = 0;
	const orderStatusUpdates: Array< {
		orderId: string;
		intentId: string;
	} > = [];
	const observeRequest = ( request: Request ): void => {
		if ( isStoreCheckoutRequest( request ) ) {
			checkoutRequestCount += 1;
			return;
		}
		if ( request.method() !== 'POST' ) {
			return;
		}
		const body = new URLSearchParams( request.postData() ?? '' );
		if ( body.get( 'action' ) === 'update_order_status' ) {
			orderStatusUpdates.push( {
				orderId: body.get( 'order_id' ) ?? '',
				intentId: body.get( 'intent_id' ) ?? '',
			} );
		}
	};
	const observeResponse = ( response: Response ): void => {
		if ( isStoreCheckoutRequest( response.request() ) ) {
			checkoutResponseCount += 1;
		}
	};
	page.on( 'request', observeRequest );
	page.on( 'response', observeResponse );

	try {
		const checkoutResponsePromise = page.waitForResponse(
			( response ) => isStoreCheckoutRequest( response.request() ),
			{ timeout: CHECKOUT_RESPONSE_TIMEOUT_MS }
		);
		await page.getByRole( 'button', { name: /place order/i } ).click();

		let checkoutResponse: Response;
		try {
			checkoutResponse = await checkoutResponsePromise;
		} catch ( error ) {
			throw new Error(
				`The Blocks 3DS submission dispatched ${ checkoutRequestCount } checkout request(s) but produced no response within ${ CHECKOUT_RESPONSE_TIMEOUT_MS }ms.`,
				{ cause: error }
			);
		}

		const body = ( await checkoutResponse.json() ) as {
			order_id?: unknown;
			order_key?: unknown;
		};
		if (
			! Number.isSafeInteger( body.order_id ) ||
			Number( body.order_id ) <= 0 ||
			typeof body.order_key !== 'string' ||
			! body.order_key
		) {
			throw new Error(
				'The Blocks checkout response carried no exact order identity.'
			);
		}
		const orderId = body.order_id as number;
		const orderKey = body.order_key;
		const confirmation = findBlocksPaymentIntentConfirmation( body );
		if ( ! confirmation ) {
			throw new Error(
				'The Blocks checkout response carried no PaymentIntent confirmation.'
			);
		}
		if ( confirmation.orderId !== orderId ) {
			throw new Error(
				'The Blocks checkout response and confirmation named different orders.'
			);
		}

		await completeThreeDSChallenge( page, options.response );

		if ( options.expected === 'receipt' ) {
			await page.waitForURL( /order-received/, {
				timeout: RECEIPT_TIMEOUT_MS,
			} );
		} else {
			if ( ! options.errorText ) {
				throw new Error(
					'An expected Blocks 3DS error requires exact shopper copy.'
				);
			}
			await page
				.getByText( options.errorText, { exact: true } )
				.first()
				.waitFor( { state: 'visible', timeout: RECEIPT_TIMEOUT_MS } );
		}

		if ( checkoutRequestCount !== 1 || checkoutResponseCount !== 1 ) {
			throw new Error(
				`The Blocks 3DS interval observed ${ checkoutRequestCount } checkout request(s) and ${ checkoutResponseCount } response(s); exactly one of each is required.`
			);
		}

		return {
			dispatch: {
				...confirmation,
				orderKey,
				responseStatus: checkoutResponse.status(),
				checkoutRequestCount,
				checkoutResponseCount,
				orderStatusUpdates,
			},
			reachedReceipt: page.url().includes( 'order-received' ),
			url: page.url(),
		};
	} finally {
		page.off( 'request', observeRequest );
		page.off( 'response', observeResponse );
	}
}

let customer: ReturnType< typeof getFakeUser >;
let customerId: number;

/**
 * Logs in as the run's customer and confirms the login actually took (the P3
 * `saved-token-lifecycle` precedent). `logIn( ..., false )` does not assert
 * success on its own; a silent failure would leave the browser a guest, and
 * `fillBlocksCheckoutDetails` fills a guest address without complaint, so the
 * case would still pass on the wrong journey.
 */
async function logInAsCustomer( page: Page ): Promise< void > {
	await page.goto( 'wp-login.php' );
	await logIn( page, customer.username, customer.password, false );
	await page.goto( 'my-account/' );
	await expect(
		page.getByText( new RegExp( `Hello ${ customer.first_name }` ) )
	).toBeVisible();
}

test.describe( 'WooPayments native card authentication', () => {
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		customer = getFakeUser( 'customer' );
		const created = ( await restApi.post( 'wc/v3/customers', customer ) )
			.data as { id: number };
		customerId = created.id;
	} );

	test.afterAll( async ( { restApi } ) => {
		if ( customerId ) {
			await restApi.delete( `wc/v3/customers/${ customerId }`, {
				force: true,
			} );
		}
	} );

	test(
		'a completed Blocks 3DS challenge settles one exact USD 10.99 order, PaymentIntent, and captured charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_SUCCESS,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			test.info().annotations.push(
				...CONTRACT_IDS.map( ( contractId ) => ( {
					type: 'contract',
					description: contractId,
				} ) )
			);

			const baselineOrderId = await readHighestOrderId( restApi );
			const product = await createRunProduct( restApi );
			try {
				await logInAsCustomer( page );

				const { dispatch, reachedReceipt, url } =
					await checkoutWithChallenge( page, product, {
						card: TEST_CARDS.threeDSChallenge,
						response: 'complete',
						expected: 'receipt',
						email: customer.email,
					} );

				// A completed challenge must produce a paid order, not just a
				// dismissed dialog.
				expect(
					reachedReceipt,
					`a completed challenge must reach the receipt; stopped at ${ url }`
				).toBe( true );
				await expect(
					page.getByRole( 'heading', { name: 'Order received' } )
				).toBeVisible();
				expect(
					Number( /order-received\/(\d+)/.exec( url )?.[ 1 ] )
				).toBe( dispatch.orderId );
				const receiptKey = new URL( url ).searchParams.get( 'key' );
				expect( receiptKey ).toBe( dispatch.orderKey );
				expect( dispatch.responseStatus ).toBe( 200 );
				expect( dispatch.checkoutRequestCount ).toBe( 1 );
				expect( dispatch.checkoutResponseCount ).toBe( 1 );
				expect( dispatch.orderStatusUpdates ).toEqual( [
					{
						orderId: String( dispatch.orderId ),
						intentId: dispatch.intentId,
					},
				] );

				const payment = await expectSettledCardPayment(
					restApi,
					dispatch.orderId,
					{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
				);
				expect( payment.intentId ).toBe( dispatch.intentId );
				expect( PAID_STATUSES ).toContain( payment.orderStatus );

				const order = await readOrderSnapshot(
					restApi,
					dispatch.orderId
				);
				expect( order.paymentMethod ).toBe( 'woocommerce_payments' );
				expect( order.orderKey ).toBe( dispatch.orderKey );

				const newOrders = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					newOrders.map( ( order2 ) => order2.id ),
					'the challenge must create exactly one order'
				).toEqual( [ dispatch.orderId ] );
				expect(
					newOrders
						.filter( ( order2 ) =>
							PAID_STATUSES.includes( order2.status )
						)
						.map( ( order2 ) => order2.id )
				).toEqual( [ dispatch.orderId ] );
			} finally {
				await deleteProduct( restApi, product.id );
			}
		}
	);

	test(
		'a completed Blocks 3DS challenge that is declined leaves the same PaymentIntent unpaid and restores Place order',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DECLINE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const baselineOrderId = await readHighestOrderId( restApi );
			const product = await createRunProduct( restApi );
			try {
				await logInAsCustomer( page );

				const { dispatch, reachedReceipt, url } =
					await checkoutWithChallenge( page, product, {
						card: TEST_CARDS.declinedAfter3DS,
						response: 'complete',
						expected: 'error',
						errorText: CARD_DECLINED_TEXT,
						email: customer.email,
					} );

				expect( dispatch.responseStatus ).toBe( 200 );
				expect( dispatch.checkoutRequestCount ).toBe( 1 );
				expect( dispatch.checkoutResponseCount ).toBe( 1 );
				// F-3DS-1: the `update_order_status` call after a failed next action
				// (client 11.1.0 api:244-281) is owned by the Blocks Jest test
				// "returns the Stripe error in the payments notice context when a Blocks
				// next action fails" and the failed order status by
				// WooPaymentsCheckoutAjaxControllerTest::test_update_order_status_fails_order_for_post_authentication_decline.

				const intent = await pollFailedAuthenticationIntent(
					restApi,
					dispatch.intentId
				);
				expect( intent.id ).toBe( dispatch.intentId );
				expect( intent.status ).toBe( 'requires_payment_method' );
				expect( intent.lastPaymentErrorCode ).toBe( 'card_declined' );
				expect(
					intent.chargeCount,
					'the provider records one failed charge attempt for this post-auth decline'
				).toBe( 1 );

				const providerIntent = intent.raw as {
					id?: unknown;
					amount?: unknown;
					amount_received?: unknown;
					currency?: unknown;
					charges?: { data?: unknown };
				};
				expect( providerIntent.id ).toBe( dispatch.intentId );
				expect( providerIntent.amount ).toBe( AMOUNT_MINOR );
				expect( providerIntent.currency ).toBe( 'usd' );
				expect( [ 0, null ] ).toContain(
					( providerIntent.amount_received as number | null ) ?? null
				);
				expect( providerIntent.charges?.data ).toEqual( [
					expect.objectContaining( {
						status: 'failed',
						paid: false,
						captured: false,
						amount_captured: 0,
						failure_code: 'card_declined',
					} ),
				] );

				const order = await readOrderSnapshot(
					restApi,
					dispatch.orderId
				);
				expect( UNPAID_STATUSES ).toContain( order.status );
				expect( order.total ).toBe( PRICE );
				expect( order.currency ).toBe( CURRENCY );
				expect( order.paymentMethod ).toBe( 'woocommerce_payments' );
				expect( order.orderKey ).toBe( dispatch.orderKey );
				expect( order.intentId ).toBe( dispatch.intentId );
				expect( order.chargeId ).toBe( '' );

				const newOrders = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect( newOrders.map( ( order2 ) => order2.id ) ).toEqual( [
					dispatch.orderId,
				] );
				expect(
					newOrders.filter( ( order2 ) =>
						PAID_STATUSES.includes( order2.status )
					)
				).toEqual( [] );

				expect( reachedReceipt ).toBe( false );
				expect( url ).not.toContain( 'order-received' );
				await expect( page ).toHaveURL( /\/checkout\/?(?:\?.*)?$/ );
				await expect(
					page
						.getByText( CARD_DECLINED_TEXT, { exact: true } )
						.first()
				).toBeVisible();
				await expect(
					page.locator( '#a11y-speak-assertive' )
				).toHaveText( CARD_DECLINED_TEXT );

				const placeOrder = page.getByRole( 'button', {
					name: /place order/i,
				} );
				await expect( placeOrder ).toBeVisible();
				await expect( placeOrder ).toBeEnabled();
				await expect( placeOrder ).not.toHaveClass(
					/wc-block-components-checkout-place-order-button--loading/
				);
				await expect(
					placeOrder.locator( '.wc-block-components-spinner' )
				).toHaveCount( 0 );
				await expect(
					page.locator( AUTHENTICATION_FRAME )
				).toBeHidden();
				await expect(
					page.getByRole( 'heading', { name: 'Order received' } )
				).toHaveCount( 0 );
			} finally {
				await deleteProduct( restApi, product.id );
			}
		}
	);

	test( `leaves the order unpaid when the challenge is failed ${ tags.WOOPAYMENTS_PROVIDER }`, async ( {
		page,
		restApi,
	} ) => {
		const baselineOrderId = await readHighestOrderId( restApi );
		const product = await createRunProduct( restApi );
		try {
			await logInAsCustomer( page );

			const { dispatch, reachedReceipt, url } =
				await checkoutWithChallenge( page, product, {
					card: TEST_CARDS.threeDSChallenge,
					response: 'fail',
					expected: 'error',
					errorText: AUTHENTICATION_FAILURE_TEXT,
					email: customer.email,
				} );

			// The negative control for the test above. Without it, a
			// checkout that pays regardless of the challenge answer would
			// satisfy the positive case and prove nothing.
			expect(
				reachedReceipt,
				'a failed challenge must not reach the receipt'
			).toBe( false );
			expect( url ).not.toContain( 'order-received' );
			// The shopper is told, and the wording is native's own
			// `payment_intent_authentication_failure` copy, so this
			// asserts the failure was mapped rather than that some error
			// appeared.
			await expect(
				page.getByText( AUTHENTICATION_FAILURE_TEXT ).first(),
				'a failed challenge must tell the shopper'
			).toBeVisible();

			// And is announced, not merely displayed. The notice carries
			// no alert role of its own, which is easy to misread as
			// silence; WordPress announces through a shared off-screen
			// region instead, and an error notice is assertive. Assert
			// the region a screen reader actually reads, so losing the
			// announcement fails here rather than passing because the
			// text is still on screen somewhere.
			await expect(
				page.locator( '#a11y-speak-assertive' ),
				'a failed challenge must be announced, not only shown'
			).toHaveText( AUTHENTICATION_FAILURE_TEXT );

			// T.4 Batch P4a addition, owner FYI: this title's "leaves the
			// order unpaid" claim was previously unasserted at HEAD too - the
			// case only checked the shopper-visible failure copy. Closed here
			// from REC-3DS-3a's recorded values (FIDELITY-CLAIMS.md :709):
			// the failed challenge leaves a chargeless intent stuck at
			// `requires_payment_method` with the provider's own
			// `payment_intent_authentication_failure` error, and the order it
			// belongs to gets no charge id and no paid status. F-3DS-1 (the
			// `update_order_status` call and the failed order status) is owned
			// by the Jest and PHPUnit tests named in the declined-challenge case above.
			const intent = await pollFailedAuthenticationIntent(
				restApi,
				dispatch.intentId
			);
			expect( intent.id ).toBe( dispatch.intentId );
			expect( intent.status ).toBe( AUTHENTICATION_FAILED_STATUS );
			expect( intent.lastPaymentErrorCode ).toBe(
				'payment_intent_authentication_failure'
			);
			expect(
				intent.chargeCount,
				'a failed challenge must leave the intent with no charge'
			).toBe( 0 );
			const providerIntent = intent.raw as {
				amount_received?: unknown;
			};
			expect( [ 0, null ] ).toContain(
				( providerIntent.amount_received as number | null ) ?? null
			);

			const order = await readOrderSnapshot( restApi, dispatch.orderId );
			expect( UNPAID_STATUSES ).toContain( order.status );
			expect( order.intentId ).toBe( dispatch.intentId );
			expect( order.chargeId ).toBe( '' );

			const newOrders = await readNewOrders( restApi, baselineOrderId );
			expect(
				newOrders.filter( ( order2 ) =>
					PAID_STATUSES.includes( order2.status )
				),
				'a failed challenge must leave no paid order'
			).toEqual( [] );

			const placeOrder = page.getByRole( 'button', {
				name: /place order/i,
			} );
			await expect( placeOrder ).toBeEnabled();
			await expect( placeOrder ).not.toHaveClass(
				/wc-block-components-checkout-place-order-button--loading/
			);
		} finally {
			await deleteProduct( restApi, product.id );
		}
	} );
} );
