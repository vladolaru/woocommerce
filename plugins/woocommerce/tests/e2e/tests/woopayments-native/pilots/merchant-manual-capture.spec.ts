import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { random } from '../../../utils/helpers';
import { logIn } from '../../../utils/login';
import {
	fillCardDetails,
	getCharge,
	getPaymentIntent,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `manual-authorization-capture` provider-fidelity family (T.4 Batch P2
 * rewrite), as fixed in `FIDELITY-CLAIMS.md`. It has exactly one case, `C1`,
 * and this spec is it.
 *
 * The claim: a USD 10.99 checkout under manual capture leaves exactly one
 * uncaptured authorization at the real provider, and one merchant capture
 * action captures that same intent and charge, for that amount and
 * currency, exactly once. What falsifies it is an authorization captured
 * without the merchant action, captured twice, or captured against a
 * different intent, charge, amount or currency, so the case turns on the
 * graph being exactly one authorization and exactly one capture, on the same
 * identities.
 */

const CONTRACT =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-manual-capture.spec.ts:39::Order › Manual Capture › should create an "On hold" order then capture the charge';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	tags.WOOPAYMENTS_PR,
	'@fidelity:manual-authorization-capture',
];

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const CONVERGENCE_INTERVAL_MS = 2_000;
const CONVERGENCE_BUDGET_MS = 60_000;
const RECEIPT_TIMEOUT_MS = 60_000;

const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const SETTINGS_ROUTE = 'wc/v3/payments/settings';

interface PaymentGraph {
	orderId: number;
	orderKey: string;
	intentId: string;
	chargeId: string;
	paymentMethodId: string;
	amountMinor: number;
	currency: string;
	orderStatus: string;
	providerStatus: string;
	chargeStatus: string;
	chargeCaptured: boolean;
	occurrenceCount: number;
	captureOccurrenceCount: number;
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	return String(
		entries.find( ( entry ) => entry.key === key )?.value ?? ''
	);
}

function idOf( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( typeof value === 'object' && value !== null && 'id' in value ) {
		return String( ( value as { id: unknown } ).id );
	}
	return '';
}

function amountMinorFromTotal( total: string ): number {
	const match = /^(\d+)\.(\d{2})$/.exec( total );
	if ( ! match ) {
		throw new Error(
			`Expected a two-decimal order total, received ${ total }.`
		);
	}
	return Number( match[ 1 ] ) * 100 + Number( match[ 2 ] );
}

/** Everything the claim fixes about one terminal state, in one string. */
function signature( graph: PaymentGraph ): string {
	return JSON.stringify( graph );
}

/**
 * Read the exact order, PaymentIntent and charge, and derive the
 * provider-recorded facts the claim turns on: order/intent/charge amount and
 * currency, a non-empty payment method shared by all three, the intent's
 * sole charge equal to `_charge_id`, and how many `captured` events the
 * timeline carries (no default: an absent timeline is a read failure, not
 * zero captures).
 */
async function readPaymentGraph(
	restApi: ApiClient,
	orderId: number
): Promise< PaymentGraph > {
	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as Record< string, unknown >;
	const intentId = orderMeta( order, '_intent_id' );
	const chargeId = orderMeta( order, '_charge_id' );
	const paymentMethodId = orderMeta( order, '_payment_method_id' );
	if ( ! intentId || ! chargeId || ! paymentMethodId ) {
		throw new Error(
			`Order ${ orderId } carries no provider intent/charge/payment method yet.`
		);
	}

	const intent = await getPaymentIntent( restApi, intentId );
	const charge = await getCharge( restApi, chargeId );
	const timeline = (
		await restApi.get(
			`wc/v3/payments/timeline/${ encodeURIComponent( intentId ) }`
		)
	).data as { data?: Array< { type?: unknown } > };
	if ( ! Array.isArray( timeline.data ) ) {
		throw new Error(
			`Intent ${ intentId } carries no timeline collection to count captures from.`
		);
	}

	const chargesData = ( intent.charges as { data?: unknown[] } | undefined )
		?.data;
	if ( ! Array.isArray( chargesData ) ) {
		throw new Error(
			`Intent ${ intentId } carries no charges collection to count occurrences from.`
		);
	}
	const soleChargeId =
		chargesData.length === 1 ? idOf( chargesData[ 0 ] ) : '';

	const amountMinor = amountMinorFromTotal( String( order.total ) );
	const currency = String( order.currency ).toUpperCase();

	// One grouped comparison: the diff on a failure names the exact field
	// that diverged from the order's own recorded facts.
	expect( {
		occurrenceCount: chargesData.length,
		soleChargeId,
		intentAmount: intent.amount,
		intentCurrency: String( intent.currency ).toUpperCase(),
		intentPaymentMethodId: idOf( intent.payment_method ),
		chargeAmount: charge.amount,
		chargeCurrency: String( charge.currency ).toUpperCase(),
		chargePaymentIntent: charge.payment_intent,
		chargePaymentMethodId: idOf( charge.payment_method ),
	} ).toEqual( {
		occurrenceCount: 1,
		soleChargeId: chargeId,
		intentAmount: amountMinor,
		intentCurrency: currency,
		intentPaymentMethodId: paymentMethodId,
		chargeAmount: amountMinor,
		chargeCurrency: currency,
		chargePaymentIntent: intentId,
		chargePaymentMethodId: paymentMethodId,
	} );

	return {
		orderId,
		orderKey: String( order.order_key ),
		intentId,
		chargeId,
		paymentMethodId,
		amountMinor,
		currency,
		orderStatus: String( order.status ),
		providerStatus: String( intent.status ),
		chargeStatus: String( charge.status ),
		chargeCaptured: charge.captured === true,
		occurrenceCount: chargesData.length,
		captureOccurrenceCount: timeline.data.filter(
			( event ) => event.type === 'captured'
		).length,
	};
}

/**
 * Poll the exact order, intent and charge until the listed terminal state is
 * read twice identically, exactly as the claim's Convergence row fixes it.
 */
async function convergeOnPaymentState(
	restApi: ApiClient,
	orderId: number,
	expected: { providerStatus: string; orderStatus: string }
): Promise< PaymentGraph > {
	const deadline = Date.now() + CONVERGENCE_BUDGET_MS;
	let previous = '';

	for (;;) {
		let graph: PaymentGraph | undefined;
		try {
			graph = await readPaymentGraph( restApi, orderId );
		} catch {
			graph = undefined;
		}
		if (
			graph &&
			graph.providerStatus === expected.providerStatus &&
			graph.orderStatus === expected.orderStatus
		) {
			const current = signature( graph );
			if ( current === previous ) {
				return graph;
			}
			previous = current;
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`Order ${ orderId } did not reach two stable ${ expected.providerStatus } / ${ expected.orderStatus } reads within ${ CONVERGENCE_BUDGET_MS }ms.`
			);
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, CONVERGENCE_INTERVAL_MS )
		);
	}
}

/** Throws unless the store reports a real boolean, so an absent field cannot silently become a no-op restore. */
async function getManualCaptureSetting(
	restApi: ApiClient
): Promise< boolean > {
	const value = (
		( await restApi.get( SETTINGS_ROUTE ) ).data as {
			is_manual_capture_enabled?: unknown;
		}
	 ).is_manual_capture_enabled;
	if ( typeof value !== 'boolean' ) {
		throw new Error(
			`WooPayments settings must report is_manual_capture_enabled as a boolean, received ${ typeof value }.`
		);
	}
	return value;
}

/** Writes the setting and reads it back, so a silently ignored write cannot pass. */
async function setManualCaptureSetting(
	restApi: ApiClient,
	value: boolean
): Promise< void > {
	await restApi.post( SETTINGS_ROUTE, {
		is_manual_capture_enabled: value,
	} );
	expect(
		await getManualCaptureSetting( restApi ),
		`the manual capture setting write must be readable back as ${ value }`
	).toBe( value );
}

async function createOwnedProduct( restApi: ApiClient ): Promise< number > {
	return (
		(
			await restApi.post( PRODUCTS_ROUTE, {
				name: `WooPayments manual capture ${ random() }`,
				type: 'simple',
				virtual: true,
				regular_price: PRICE,
				status: 'publish',
			} )
		).data as { id: number }
	 ).id;
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
): Promise< Array< { id: number } > > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number } >;
	return orders.filter( ( order ) => order.id > baselineOrderId );
}

async function completeCardCheckout(
	restApi: ApiClient,
	page: Page,
	productId: number
): Promise< number > {
	const baselineOrderId = await readHighestOrderId( restApi );
	await page.goto( `?post_type=product&p=${ productId }` );
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
	await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );
	await page.getByRole( 'button', { name: /place order/i } ).click();
	await page.waitForURL( /\/order-received\/[1-9]\d*/, {
		timeout: RECEIPT_TIMEOUT_MS,
	} );
	await expect(
		page.getByText( /^(Your order has been received|Order received)$/i )
	).toBeVisible();
	const orderId = Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );
	expect(
		( await readNewOrders( restApi, baselineOrderId ) ).map(
			( order ) => order.id
		),
		'one checkout submission must create exactly one order'
	).toEqual( [ orderId ] );
	return orderId;
}

async function readOrderNotes(
	restApi: ApiClient,
	orderId: number
): Promise< string[] > {
	const notes = (
		await restApi.get(
			`${ ORDERS_ROUTE }/${ orderId }/notes?context=edit&per_page=100`
		)
	).data as Array< { note?: unknown } >;
	return notes
		.map( ( entry ) => entry.note )
		.filter( ( note ): note is string => typeof note === 'string' );
}

function countMatchingNotes(
	notes: readonly string[],
	verb: string,
	intentId: string
): number {
	return notes.filter(
		( note ) =>
			note.startsWith( 'A payment of ' ) &&
			note.includes(
				` was <strong>${ verb }</strong> using WooPayments (`
			) &&
			note.endsWith( `>${ intentId }</a>).` )
	).length;
}

/**
 * Open the exact order from the orders list and dispatch its "Capture
 * charge" action, then wait for the provider capture to converge.
 */
async function captureExactOrder(
	page: Page,
	restApi: ApiClient,
	authorized: PaymentGraph
): Promise< PaymentGraph > {
	await page.goto( 'wp-admin/admin.php?page=wc-orders' );
	await page
		.getByRole( 'searchbox', { name: /search orders/i } )
		.fill( authorized.orderId.toString() );
	await page
		.getByRole( 'link', {
			name: new RegExp( authorized.orderId.toString() ),
		} )
		.click();
	await page.locator( 'select[name="wc_order_action"]' ).selectOption( {
		label: 'Capture charge',
		value: 'capture_charge',
	} );
	await page
		.locator( '#actions' )
		.getByRole( 'button', { name: /^Apply\b/ } )
		.click();

	const captured = await convergeOnPaymentState(
		restApi,
		authorized.orderId,
		{
			providerStatus: 'succeeded',
			orderStatus: 'processing',
		}
	);
	expect( captured.intentId ).toBe( authorized.intentId );
	expect( captured.chargeId ).toBe( authorized.chargeId );
	expect( captured.orderKey ).toBe( authorized.orderKey );
	expect( captured.paymentMethodId ).toBe( authorized.paymentMethodId );
	expect( captured.chargeCaptured ).toBe( true );
	expect( captured.chargeStatus ).toBe( 'succeeded' );
	expect( captured.occurrenceCount ).toBe( 1 );
	expect( captured.captureOccurrenceCount ).toBe( 1 );
	return captured;
}

test.beforeAll( async ( { restApi } ) => {
	await requireTestModeAccount( restApi );
} );

test(
	'merchant manually captures one exact authorization and restores capture mode',
	{
		annotation: [ { type: 'woopayments-contract', description: CONTRACT } ],
		tag: FAMILY_TAGS,
	},
	async ( { page, restApi } ) => {
		test.setTimeout( 300_000 );

		const originalManualCapture = await getManualCaptureSetting( restApi );
		let productId = 0;
		let caseError: unknown;
		try {
			await setManualCaptureSetting( restApi, true );
			productId = await createOwnedProduct( restApi );
			const orderId = await completeCardCheckout(
				restApi,
				page,
				productId
			);

			// Authorization: one uncaptured `1099 usd` charge on one
			// `requires_capture` intent, held on an on-hold order, read
			// twice identically before anything acts on it.
			const authorized = await convergeOnPaymentState( restApi, orderId, {
				providerStatus: 'requires_capture',
				orderStatus: 'on-hold',
			} );
			expect( authorized.amountMinor ).toBe( AMOUNT_MINOR );
			expect( authorized.currency ).toBe( CURRENCY );
			expect( authorized.chargeCaptured ).toBe( false );
			expect(
				authorized.occurrenceCount,
				'one authorization must leave exactly one charge occurrence'
			).toBe( 1 );
			expect(
				authorized.captureOccurrenceCount,
				'no capture has happened yet'
			).toBe( 0 );

			const authorizationNotes = countMatchingNotes(
				await readOrderNotes( restApi, orderId ),
				'authorized',
				authorized.intentId
			);
			expect(
				authorizationNotes,
				'one authorization must journal exactly one authorization note'
			).toBe( 1 );

			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password );
			const captured = await captureExactOrder(
				page,
				restApi,
				authorized
			);

			const captureNotes = countMatchingNotes(
				await readOrderNotes( restApi, orderId ),
				'successfully captured',
				captured.intentId
			);
			expect(
				captureNotes,
				'one capture must journal exactly one capture note'
			).toBe( 1 );

			await expect(
				page
					.getByRole( 'link', {
						name: captured.intentId,
						exact: true,
					} )
					.first()
			).toBeVisible();
			await expect(
				page.getByText( /successfully captured.*WooPayments/i )
			).toBeVisible();
			await expect( page.locator( '#order_status' ) ).toHaveValue(
				'wc-processing'
			);

			// A final, independent read proves no second capture happened.
			const finalRead = await readPaymentGraph( restApi, orderId );
			expect( finalRead.intentId ).toBe( authorized.intentId );
			expect( finalRead.chargeId ).toBe( authorized.chargeId );
			expect(
				finalRead.captureOccurrenceCount,
				'the authorization must be captured exactly once'
			).toBe( 1 );
			expect( finalRead.occurrenceCount ).toBe( 1 );
			expect(
				countMatchingNotes(
					await readOrderNotes( restApi, orderId ),
					'successfully captured',
					finalRead.intentId
				)
			).toBe( 1 );
		} catch ( error ) {
			caseError = error;
		} finally {
			try {
				await setManualCaptureSetting( restApi, originalManualCapture );
			} catch ( restoreError ) {
				if ( caseError === undefined ) {
					caseError = restoreError;
				} else {
					console.error(
						'WooPayments manual-capture restore failed after the primary case failure:',
						restoreError
					);
				}
			}
			if ( productId ) {
				await restApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
					force: true,
				} );
			}
		}
		if ( caseError !== undefined ) {
			throw caseError;
		}
	}
);
