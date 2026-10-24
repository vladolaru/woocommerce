import type { Page, Request } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { random } from '../../../utils/helpers';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	expectSettledCardPayment,
	fillCardDetails,
	getPaymentIntent,
	requireTestModeAccount,
	TEST_CARDS,
	type SettledCardPayment,
} from '../../../utils/woopayments';

/**
 * Client contract row 121 (T.4 D9): "Successful purchase › Carding
 * protection false › using a basic card". The client's own case at this
 * source line runs the shortcode/classic checkout; the pilot originally
 * proved only the Blocks surface (a documented surface caveat). This file
 * now runs both, in the `checkout.spec.ts:32-35` two-row pattern: the Blocks
 * instance keeps its title and DISPOSITION row, the Classic instance is new
 * (T.4 addition, closing the caveat) and gets its own suffix and row.
 *
 * `scenarios/card-payment.ts`'s scenario-registration machinery folds into
 * this file; the shared assertions below replace its generic evidence
 * validator.
 */

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	tags.WOOPAYMENTS_PR,
];

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const RECEIPT_TIMEOUT_MS = 60_000;
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments card-payment ${ random() }`,
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

/**
 * The claim's fixed format and reachability facts on top of what
 * `expectSettledCardPayment` already proved (the amounts, currency, linkage,
 * payment method and cardinality against the order's own recorded facts).
 */
function expectSettledGraph(
	payment: SettledCardPayment,
	orderId: number
): void {
	expect( payment.orderId ).toBe( orderId );
	expect( payment.intentId ).toMatch( /^pi_/ );
	expect( payment.chargeId ).toMatch( /^ch_|^py_/ );
	expect( payment.paymentMethodId ).toMatch( /^pm_/ );
	expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
}

/** Confirms the settled intent asked for nothing reusable and raised no challenge. */
async function expectNoSaveNoChallenge(
	restApi: ApiClient,
	payment: SettledCardPayment
): Promise< void > {
	const intent = await getPaymentIntent( restApi, payment.intentId );
	expect( intent.id ).toBe( payment.intentId );
	expect( intent.next_action ?? null ).toBeNull();
	expect( intent.setup_future_usage ?? null ).toBeNull();
	const customerId = String( intent.customer ?? '' );
	expect( customerId ).not.toBe( '' );
	const attachedMethods = (
		await restApi.get(
			`wc/v3/payments/customers/${ encodeURIComponent(
				customerId
			) }/payment_methods`
		)
	).data;
	expect( attachedMethods ).toEqual( [] );
}

async function expectOrderIsGuestPaidWithWooPayments(
	restApi: ApiClient,
	orderId: number
): Promise< void > {
	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as Record< string, unknown >;
	expect( order.payment_method ).toBe( 'woocommerce_payments' );
	expect( order.customer_id ).toBe( 0 );
}

/**
 * Reads the order-received page's semantic order summary.
 *
 * Both surfaces render the same Order Confirmation block template on this
 * store, so one reader covers Blocks and Classic; the status text still
 * differs by surface, so both phrasings are accepted.
 */
async function readAccessibleSummary(
	page: Page
): Promise< { visible: boolean; text: string; paymentValue: string } > {
	const statusVisible = await page
		.getByText(
			/^(Your order has been received|Order received|Thank you\. Your order has been received\.)$/i
		)
		.first()
		.isVisible();
	const summary = page
		.getByRole( 'list' )
		.filter( { hasText: /(?:Order number|Order #):/i } )
		.filter( { hasText: /Total:/i } )
		.filter( { hasText: /(?:Payment method|Payment):/i } )
		.first();
	const text = ( await summary.innerText() ).replace( /\s+/g, ' ' ).trim();
	const paymentRow = summary
		.getByRole( 'listitem' )
		.filter( { hasText: /^\s*(?:Payment method|Payment):/i } )
		.first();
	// The rendered value is often only a masked number beside a brand icon
	// (no "Visa"/"card" text at all), so the icon's own accessible name is
	// part of what the summary actually conveys.
	const imageAlts = await paymentRow
		.locator( 'img[alt]' )
		.evaluateAll( ( images ) =>
			images.map( ( image ) => image.getAttribute( 'alt' ) ?? '' )
		);
	const paymentValue = [ await paymentRow.innerText(), ...imageAlts ]
		.join( ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
	return { visible: statusVisible, text, paymentValue };
}

function expectAccessibleSummary(
	summary: {
		visible: boolean;
		text: string;
		paymentValue: string;
	},
	orderId: number
): void {
	expect( summary.visible ).toBe( true );
	expect( summary.text ).toMatch(
		new RegExp(
			`(?:Order number:|Order #:)\\s*#?${ orderId }(?:\\D|$)`,
			'i'
		)
	);
	expect( summary.text ).toMatch(
		/(?:US\$|\$)\s*10\.99\b|\b10\.99\s*USD\b/i
	);
	expect( summary.paymentValue ).toMatch( /\b(?:card|visa)\b/i );
	expect( summary.paymentValue ).toMatch( /\b4242\b/ );
}

test.describe( 'Client contract row 121: basic-card purchase, protection off', () => {
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		// The client contract's `woopayments-contract` annotation moved to
		// the classic instance below (D9): the client's own case at this
		// source line is the shortcode/classic checkout, and the
		// contract-map validator requires exactly one owning test per
		// closed ledger contract. This Blocks instance stays a live browser
		// case in its own right (DISPOSITION row 145) without citing that
		// contract a second time.
		'Successful purchase › Carding protection false › using a basic card',
		{ tag: FAMILY_TAGS },
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
				await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );

				let checkoutRequestCount = 0;
				let checkoutResponseCount = 0;
				let submittedGateway: unknown;
				let saveRequested: unknown;
				let tokenField: unknown;
				const isCheckoutRequest = ( request: Request ): boolean => {
					if ( request.method() !== 'POST' ) {
						return false;
					}
					try {
						const url = new URL( request.url() );
						return (
							url.pathname.replace( /\/+$/, '' ) ===
							'/wp-json/wc/store/v1/checkout'
						);
					} catch {
						return false;
					}
				};
				const onRequest = ( request: Request ): void => {
					if ( ! isCheckoutRequest( request ) ) {
						return;
					}
					checkoutRequestCount += 1;
					try {
						const body = request.postDataJSON() as {
							payment_method?: unknown;
							payment_data?: Array< {
								key?: unknown;
								value?: unknown;
							} >;
						} | null;
						submittedGateway = body?.payment_method;
						const entries = body?.payment_data ?? [];
						saveRequested = entries.find(
							( entry ) =>
								entry.key ===
								'wc-woocommerce_payments-new-payment-method'
						)?.value;
						tokenField = entries.find(
							( entry ) =>
								entry.key === 'wcpay-fraud-prevention-token'
						)?.value;
					} catch {
						// The count above is what the cardinality clause needs.
					}
				};
				const onResponse = ( response: {
					request: () => Request;
				} ): void => {
					if ( isCheckoutRequest( response.request() ) ) {
						checkoutResponseCount += 1;
					}
				};
				page.on( 'request', onRequest );
				page.on( 'response', onResponse );
				try {
					await page
						.getByRole( 'button', { name: /place order/i } )
						.click();
					await page.waitForURL( /\/order-received\/[1-9]\d*/, {
						timeout: RECEIPT_TIMEOUT_MS,
					} );
				} finally {
					page.off( 'request', onRequest );
					page.off( 'response', onResponse );
				}

				expect(
					checkoutRequestCount,
					'one Place order activation must ask the Store API exactly once'
				).toBe( 1 );
				expect( checkoutResponseCount ).toBe( 1 );
				expect( submittedGateway ).toBe( 'woocommerce_payments' );
				expect( saveRequested ).toBe( false );
				expect(
					tokenField,
					'protection off must carry a present, empty fraud-prevention token field'
				).toBe( '' );

				const orderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);
				await expectOrderIsGuestPaidWithWooPayments( restApi, orderId );
				const payment = await expectSettledCardPayment(
					restApi,
					orderId,
					{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
				);
				expectSettledGraph( payment, orderId );
				await expectNoSaveNoChallenge( restApi, payment );
				expectAccessibleSummary(
					await readAccessibleSummary( page ),
					orderId
				);
				const newOrders = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					newOrders.map( ( order ) => order.id ),
					'one submission must create exactly one order'
				).toEqual( [ orderId ] );
			} finally {
				await deleteProduct( restApi, product.id );
			}
		}
	);

	test(
		'Successful purchase › Carding protection false › using a basic card (classic checkout)',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_ID },
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
				await fillCardDetails( page, TEST_CARDS.basic, 'classic' );

				let checkoutRequestCount = 0;
				let submittedGateway: unknown;
				let saveRequested: unknown;
				let tokenField: unknown;
				const onRequest = ( request: Request ): void => {
					if ( request.method() !== 'POST' ) {
						return;
					}
					const params = new URLSearchParams(
						request.postData() ?? ''
					);
					if ( ! params.has( 'payment_method' ) ) {
						return;
					}
					checkoutRequestCount += 1;
					submittedGateway = params.get( 'payment_method' );
					// The checkbox posts the string `"true"` when checked and
					// is absent from the body entirely when it is not; `'1'`
					// is never a value this field carries, so comparing
					// against it could never fail.
					saveRequested = params.has(
						'wc-woocommerce_payments-new-payment-method'
					)
						? params.get(
								'wc-woocommerce_payments-new-payment-method'
						  ) === 'true'
						: false;
					tokenField =
						params.get( 'wcpay-fraud-prevention-token' ) ?? '';
				};
				page.on( 'request', onRequest );
				try {
					await page
						.getByRole( 'button', { name: /place order/i } )
						.click();
					await page.waitForURL( /\/order-received\/[1-9]\d*/, {
						timeout: RECEIPT_TIMEOUT_MS,
					} );
				} finally {
					page.off( 'request', onRequest );
				}

				expect(
					checkoutRequestCount,
					'one Place order activation must dispatch the classic checkout form exactly once'
				).toBe( 1 );
				expect( submittedGateway ).toBe( 'woocommerce_payments' );
				expect( saveRequested ).toBe( false );
				expect(
					tokenField ?? '',
					'protection off must not carry a fraud-prevention token'
				).toBe( '' );

				const orderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);
				await expectOrderIsGuestPaidWithWooPayments( restApi, orderId );
				const payment = await expectSettledCardPayment(
					restApi,
					orderId,
					{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
				);
				expectSettledGraph( payment, orderId );
				await expectNoSaveNoChallenge( restApi, payment );
				expectAccessibleSummary(
					await readAccessibleSummary( page ),
					orderId
				);
				const newOrders = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					newOrders.map( ( order ) => order.id ),
					'one submission must create exactly one order'
				).toEqual( [ orderId ] );
			} finally {
				await deleteProduct( restApi, product.id );
			}
		}
	);
} );
