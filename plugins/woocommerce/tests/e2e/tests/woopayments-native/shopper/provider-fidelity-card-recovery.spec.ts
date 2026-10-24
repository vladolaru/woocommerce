import type { Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { random } from '../../../utils/helpers';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	fillCardDetails,
	getCharge,
	getPaymentIntent,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `card-decline-vocabulary` family (T.4 Batch P1 rewrite): a generic
 * decline followed by one valid-card retry in the same checkout document,
 * on both the Classic and Blocks surfaces.
 *
 * The Blocks case is trimmed to the audit's minimal oracle (its first
 * attempt's own HTTP status and message, one read of the failed intent, and
 * the retry paying the same draft order); the Classic case restores the
 * client's shortcode-checkout retry contract (row 113) that T.1 batch 2
 * removed. Timeline ordering, cart-line identity, control focus and frame
 * markers - the fuller graph `card-recovery.test.ts` used to cover - are not
 * asserted here; that unit test went with the driver it tested.
 */

const CONTRACT_CLASSIC =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:205::Shopper › Checkout › Retry after failure without page refresh › should successfully complete order after retrying with a valid card without refreshing the page';
const CONTRACT_BLOCKS =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:123::WooCommerce Blocks › Checkout failures › should successfully complete order after retrying with a valid card without refreshing the page';
const CONTRACT_BLOCKS_MESSAGE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card was declined.';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:card-decline-recovery',
];

const GENERIC_DECLINE_MESSAGE = 'Error: Your card was declined.';
const FAILED_STATUS = 'requires_payment_method';
const SUCCEEDED_STATUS = 'succeeded';
const UNPAID_ORDER_STATUSES = [ 'pending', 'failed' ];
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const PRICE = '10.01';
const AMOUNT_MINOR = 1001;
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const CONVERGENCE_TIMEOUT_MS = 45_000;
const RECEIPT_TIMEOUT_MS = 60_000;
const NOTICE_TIMEOUT_MS = 30_000;
const POLL_INTERVAL_MS = 500;

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments card-recovery ${ random() }`,
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

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const value = entries.find( ( entry ) => entry.key === key )?.value;
	return typeof value === 'string' ? value : '';
}

function amountMinorFromTotal( total: string ): number {
	const match = /^(\d+)\.(\d{2})$/.exec( total );
	if ( ! match ) {
		throw new Error(
			`Card recovery requires a two-decimal order total, received ${ total }.`
		);
	}
	return Number( match[ 1 ] ) * 100 + Number( match[ 2 ] );
}

interface OrderPayment {
	orderId: number;
	orderStatus: string;
	amountMinor: number;
	currency: string;
	intentId: string;
	chargeId: string;
}

async function readOrderPayment(
	restApi: ApiClient,
	orderId: number
): Promise< OrderPayment > {
	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as Record< string, unknown >;
	return {
		orderId,
		orderStatus: String( order.status ),
		amountMinor: amountMinorFromTotal( String( order.total ) ),
		currency: String( order.currency ).toUpperCase(),
		intentId: orderMeta( order, '_intent_id' ),
		chargeId: orderMeta( order, '_charge_id' ),
	};
}

/** Polls an order until its intent reaches the expected terminal status. */
async function waitForIntentStatus(
	restApi: ApiClient,
	orderId: number,
	expectedStatus: typeof FAILED_STATUS | typeof SUCCEEDED_STATUS,
	timeoutMs: number
): Promise< { payment: OrderPayment; intent: Record< string, unknown > } > {
	const deadline = Date.now() + timeoutMs;
	for (;;) {
		const payment = await readOrderPayment( restApi, orderId );
		if ( payment.intentId ) {
			const intent = await getPaymentIntent( restApi, payment.intentId );
			if ( intent.status === expectedStatus ) {
				return { payment, intent };
			}
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`Order ${ orderId } never reached intent status ${ expectedStatus } within ${ timeoutMs }ms.`
			);
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, POLL_INTERVAL_MS )
		);
	}
}

/** How many of an intent's own charge occurrences are captured or succeeded. */
function countSettledCharges( intent: Record< string, unknown > ): number {
	const chargesData =
		( intent.charges as { data?: Array< Record< string, unknown > > } )
			?.data ?? [];
	return chargesData.filter(
		( charge ) => charge.status === 'succeeded' || charge.captured === true
	).length;
}

/**
 * Counts only the order notes that report the payment failure itself
 * (native's "A payment of ... failed to complete" note), not WooCommerce
 * core's own status-change note or this environment's unrelated failed-email
 * notices (this local store has no working mail transport, so every
 * WooCommerce email attempt also leaves its own note).
 */
async function readPaymentFailureNoteCount(
	restApi: ApiClient,
	orderId: number
): Promise< number > {
	const notes = (
		await restApi.get( `${ ORDERS_ROUTE }/${ orderId }/notes` )
	).data as Array< { note?: unknown } >;
	if ( ! Array.isArray( notes ) ) {
		return 0;
	}
	return notes.filter(
		( entry ) =>
			typeof entry.note === 'string' &&
			entry.note.includes( 'failed to complete' )
	).length;
}

function expectFailedGraph(
	payment: OrderPayment,
	intent: Record< string, unknown >
): void {
	expect( UNPAID_ORDER_STATUSES ).toContain( payment.orderStatus );
	expect( payment.amountMinor ).toBe( AMOUNT_MINOR );
	expect( payment.currency ).toBe( 'USD' );
	expect( payment.chargeId ).toBe( '' );
	expect( intent.amount ).toBe( AMOUNT_MINOR );
	expect( String( intent.currency ).toLowerCase() ).toBe( 'usd' );
	expect(
		intent.amount_received,
		'a failed attempt must have received nothing'
	).toBe( 0 );
	expect(
		countSettledCharges( intent ),
		'a failed attempt must carry no succeeded or captured charge'
	).toBe( 0 );
	const lastError = intent.last_payment_error as
		| { code?: unknown; decline_code?: unknown }
		| undefined;
	expect( lastError?.code ).toBe( 'card_declined' );
	expect( lastError?.decline_code ).toBe( 'generic_decline' );
}

/**
 * Reads the retry's own charge and proves it is the one captured, succeeded
 * occurrence on the retry intent, then proves that across the failed
 * attempt and the retry together exactly one charge is captured - the
 * claim's cardinality clause, not only the retry's own shape.
 */
async function expectRetrySettledOnce(
	restApi: ApiClient,
	failed: { intent: Record< string, unknown > },
	retry: { payment: OrderPayment; intent: Record< string, unknown > }
): Promise< void > {
	expect(
		retry.payment.chargeId,
		'the retry must record a charge id on the order'
	).not.toBe( '' );
	const charge = await getCharge( restApi, retry.payment.chargeId );
	expect( charge.status ).toBe( 'succeeded' );
	expect( charge.captured ).toBe( true );
	expect( charge.payment_intent ).toBe( retry.intent.id );
	expect(
		countSettledCharges( retry.intent ),
		'the retry intent must carry exactly one captured charge'
	).toBe( 1 );
	expect(
		countSettledCharges( failed.intent ) +
			countSettledCharges( retry.intent ),
		'exactly one charge must be captured across the failed attempt and the retry together'
	).toBe( 1 );
}

function isStoreCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		return (
			new URL( request.url() ).pathname.replace( /\/+$/, '' ) ===
			'/wp-json/wc/store/v1/checkout'
		);
	} catch {
		return false;
	}
}

test.describe( 'WooPayments native card decline recovery', () => {
	// Serial: both cases spend real provider budget on the same store, and a
	// failure must halt the case after it rather than run a second decline
	// against a store whose state is no longer described.
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		'A Classic generic decline followed by one valid-card retry in the same checkout document creates one successful payment effect and no duplicate paid order or charge',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_CLASSIC },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const product = await createRunProduct( restApi );
			try {
				const baselineOrderId = await readHighestOrderId( restApi );

				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', { name: 'Add to cart', exact: true } )
					.click();
				await page.goto( 'classic-checkout/' );
				await page
					.getByRole( 'textbox', { name: 'First name' } )
					.fill( 'E2E' );
				await page
					.getByRole( 'textbox', { name: 'Last name' } )
					.fill( 'WooPayments' );
				await page
					.getByRole( 'textbox', { name: 'Street address' } )
					.fill( '123 Test Street' );
				await page
					.getByRole( 'textbox', { name: 'Town / City' } )
					.fill( 'San Francisco' );
				await page
					.getByRole( 'textbox', { name: 'ZIP Code' } )
					.fill( '94107' );
				await page
					.getByRole( 'textbox', { name: 'Phone' } )
					.fill( '5555550100' );
				await page
					.getByRole( 'textbox', { name: 'Email address' } )
					.fill( `woopayments-${ random() }@example.com` );
				await page
					.locator(
						'input[name="payment_method"][value="woocommerce_payments"]'
					)
					.check();
				await fillCardDetails(
					page,
					TEST_CARDS.genericDecline,
					'classic'
				);

				// R7: a marker written into this exact document, which only
				// a real navigation (not a same-URL AJAX re-render) can
				// clear - URL equality cannot tell "stayed on this page"
				// from "reloaded this same page".
				const checkoutUrl = page.url();
				const documentMarker = await page.evaluate( () => {
					const marker = crypto.randomUUID();
					(
						window as unknown as Record< string, unknown >
					 ).__e2eCheckoutDocument = marker;
					return marker;
				} );
				await page
					.getByRole( 'button', { name: /place order/i } )
					.click();
				// The classic checkout's own top-level rejection notice, an
				// announced alert region, carrying the client's exact
				// decline copy - not the per-payment-method Stripe error
				// regions, which share the `woocommerce-error` class but
				// stay `hidden` when the rejection never reaches Stripe.js
				// at all.
				const declineNotice = page
					.getByRole( 'alert' )
					.filter( { hasText: GENERIC_DECLINE_MESSAGE } );
				await expect(
					declineNotice,
					'the declined attempt must be reported without navigating away'
				).toBeVisible( { timeout: NOTICE_TIMEOUT_MS } );
				expect(
					( await declineNotice.innerText() ).trim(),
					'the shopper must see the client-cited decline copy exactly'
				).toBe( GENERIC_DECLINE_MESSAGE );
				expect(
					page.url(),
					'a declined submission must stay on the same checkout document'
				).toBe( checkoutUrl );

				const failedDelta = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					failedDelta,
					'the failed attempt must create exactly one order'
				).toHaveLength( 1 );
				const failedOrderId = failedDelta[ 0 ].id;
				const failed = await waitForIntentStatus(
					restApi,
					failedOrderId,
					FAILED_STATUS,
					CONVERGENCE_TIMEOUT_MS
				);
				expectFailedGraph( failed.payment, failed.intent );
				expect(
					await readPaymentFailureNoteCount( restApi, failedOrderId ),
					'the failed attempt must leave at most one payment-failure note (F15 does not apply to a plain decline)'
				).toBeLessThanOrEqual( 1 );

				// Retry, in the same document, no page reload: the marker
				// written before the failed attempt must still be there.
				const markerBeforeRetry = await page.evaluate(
					() =>
						( window as unknown as Record< string, unknown > )
							.__e2eCheckoutDocument
				);
				expect(
					markerBeforeRetry,
					'the retry must happen in the same document as the failed attempt, not a reload'
				).toBe( documentMarker );
				await fillCardDetails( page, TEST_CARDS.basic, 'classic' );
				await page
					.getByRole( 'button', { name: /place order/i } )
					.click();
				await page.waitForURL( /\/order-received\/[1-9]\d*/, {
					timeout: RECEIPT_TIMEOUT_MS,
				} );
				const retryOrderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);

				const retry = await waitForIntentStatus(
					restApi,
					retryOrderId,
					SUCCEEDED_STATUS,
					CONVERGENCE_TIMEOUT_MS
				);
				expect( retry.payment.amountMinor ).toBe( AMOUNT_MINOR );
				expect( retry.payment.currency ).toBe( 'USD' );
				expect( PAID_ORDER_STATUSES ).toContain(
					retry.payment.orderStatus
				);
				expect(
					retry.intent.id,
					'the retry must settle on a distinct intent from the failed attempt'
				).not.toBe( failed.intent.id );
				await expectRetrySettledOnce( restApi, failed, retry );

				const wholeDelta = await readNewOrders(
					restApi,
					baselineOrderId
				);
				const paidOrders = wholeDelta.filter( ( order ) =>
					PAID_ORDER_STATUSES.includes( order.status )
				);
				expect(
					paidOrders.map( ( order ) => order.id ),
					'the retry is the only submission that may have paid'
				).toEqual( [ retryOrderId ] );
			} finally {
				await deleteProduct( restApi, product.id );
			}
		}
	);

	test(
		'A Blocks generic decline followed by one valid-card retry in the same checkout document pays the same draft order exactly once',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_BLOCKS },
				{
					type: 'woopayments-contract',
					description: CONTRACT_BLOCKS_MESSAGE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const product = await createRunProduct( restApi );
			try {
				const baselineOrderId = await readHighestOrderId( restApi );

				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', { name: 'Add to cart', exact: true } )
					.click();
				await page.goto( 'checkout/' );
				await page
					.getByRole( 'textbox', { name: 'Email address' } )
					.fill( `woopayments-${ random() }@example.com` );
				await fillBillingCheckoutBlocks( page, {
					country: 'US',
					firstName: 'E2E',
					lastName: 'WooPayments',
					address: '123 Test Street',
					city: 'San Francisco',
					state: 'CA',
					zip: '94107',
					phone: '5555550100',
				} );
				await page
					.getByRole( 'group', { name: 'Payment options' } )
					.getByRole( 'radio', { name: /Card/i } )
					.check();
				await fillCardDetails(
					page,
					TEST_CARDS.genericDecline,
					'blocks'
				);

				const checkoutResponsePromise = page.waitForResponse(
					( response: Response ) =>
						isStoreCheckoutRequest( response.request() ),
					{ timeout: RECEIPT_TIMEOUT_MS }
				);
				await page
					.getByRole( 'button', { name: /place order/i } )
					.click();
				const checkoutResponse = await checkoutResponsePromise;
				expect( checkoutResponse.status() ).toBe( 400 );
				const body = ( await checkoutResponse.json() ) as {
					message?: unknown;
				};
				expect( body.message ).toBe( GENERIC_DECLINE_MESSAGE );

				const notices = page.locator(
					'[role="alert"]:visible:not(.wc-block-components-notice-banner), .wc-block-components-notice-banner__list > li:visible'
				);
				await expect(
					notices.filter( { hasText: GENERIC_DECLINE_MESSAGE } )
				).toBeVisible( { timeout: NOTICE_TIMEOUT_MS } );
				await expect(
					page.locator( '#a11y-speak-assertive' )
				).toContainText( GENERIC_DECLINE_MESSAGE, {
					timeout: NOTICE_TIMEOUT_MS,
				} );

				const failedDelta = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					failedDelta,
					'the failed attempt must create exactly one draft order'
				).toHaveLength( 1 );
				const draftOrderId = failedDelta[ 0 ].id;
				const failed = await waitForIntentStatus(
					restApi,
					draftOrderId,
					FAILED_STATUS,
					CONVERGENCE_TIMEOUT_MS
				);
				expectFailedGraph( failed.payment, failed.intent );

				// Retry in the same document; the Blocks checkout keeps the
				// same draft order rather than creating a second one.
				await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );
				await page
					.getByRole( 'button', { name: /place order/i } )
					.click();
				await page.waitForURL( /\/order-received\/[1-9]\d*/, {
					timeout: RECEIPT_TIMEOUT_MS,
				} );
				const retryOrderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);
				expect(
					retryOrderId,
					'the retry must pay the same draft order'
				).toBe( draftOrderId );

				const retry = await waitForIntentStatus(
					restApi,
					retryOrderId,
					SUCCEEDED_STATUS,
					CONVERGENCE_TIMEOUT_MS
				);
				expect( retry.payment.amountMinor ).toBe( AMOUNT_MINOR );
				expect( retry.payment.currency ).toBe( 'USD' );
				expect( PAID_ORDER_STATUSES ).toContain(
					retry.payment.orderStatus
				);
				expect(
					retry.intent.id,
					'the retry must settle on a distinct intent from the failed attempt'
				).not.toBe( failed.intent.id );
				await expectRetrySettledOnce( restApi, failed, retry );

				const wholeDelta = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					wholeDelta.map( ( order ) => order.id ),
					'no second order may exist beyond the one draft order'
				).toEqual( [ draftOrderId ] );
				const paidOrders = wholeDelta.filter( ( order ) =>
					PAID_ORDER_STATUSES.includes( order.status )
				);
				expect( paidOrders.map( ( order ) => order.id ) ).toEqual( [
					draftOrderId,
				] );
			} finally {
				await deleteProduct( restApi, product.id );
			}
		}
	);
} );
