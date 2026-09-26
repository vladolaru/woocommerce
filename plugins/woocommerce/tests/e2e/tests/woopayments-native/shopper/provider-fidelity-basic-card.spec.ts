import type { Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpEvalJson } from '../../../utils/cli';
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
 * The `basic-card-charge` provider-fidelity family (T.4 Batch P1 rewrite).
 *
 * `B1`: one guest Blocks checkout submission with the basic card produces
 * exactly one succeeded 1099 usd PaymentIntent and one captured charge,
 * correlated to one new Woo order that reaches `processing` or `completed`.
 *
 * `B1p` (T.4 D8 addition, restored on shared helpers): the same graph on the
 * native Classic shortcode checkout with card-testing protection forced on,
 * plus its refusal half - a tokenless submission on the same session creates
 * no payment context and no provider object at all. `B1c`/`B1f`/`B1pf` stay
 * moved below the browser per the T.1 batch 1 narrowing recorded in
 * `FIDELITY-CLAIMS.md`.
 *
 * **Cardinality is the contract, not a by-product.** Both cases count what a
 * single submission caused on both sides: one Store API/classic checkout
 * exchange, one new order at the store, and one charge/capture occurrence on
 * the exact intent at the provider.
 */

const CONTRACT_B1 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-purchase.spec.ts:31::WooCommerce Blocks › Successful purchase › using a basic card';
const CONTRACT_B1P =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection true › using a basic card';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:basic-card-charge',
];

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const RECEIPT_TIMEOUT_MS = 60_000;
const CARD = { brand: 'visa', last4: '4242' };

const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

/** Native's copy when card-testing protection turns a submission away. */
const CARD_TESTING_REJECTION_TEXT =
	"We're not able to process this payment. Please refresh the page and try again.";

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments basic-card ${ random() }`,
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

/**
 * The claim's fixed format and reachability facts on top of what
 * `expectSettledCardPayment` already proved (the amounts, currency, linkage,
 * payment method and cardinality against the order's own recorded facts).
 */
function expectSingleSettledGraph(
	payment: SettledCardPayment,
	orderId: number
): void {
	expect( payment.orderId ).toBe( orderId );
	expect( payment.intentId ).toMatch( /^pi_/ );
	expect( payment.chargeId ).toMatch( /^ch_|^py_/ );
	expect( payment.paymentMethodId ).toMatch( /^pm_/ );
	expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
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

/**
 * Forces (or restores) the account's card-testing-protection eligibility by
 * writing the store's own cached mirror of the account flag directly,
 * through `wpEvalJson` against the run's own wp-env config
 * (`E2E_WP_ENV_CONFIG`, unrelated to the frozen `WP_ENV_HOME`-hardcoding
 * driver this replaces). Returns the prior value, for an exact restore.
 */
async function setCardTestingProtectionEligible(
	eligible: boolean
): Promise< unknown > {
	return wpEvalJson< unknown >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to force.' );
		}
		$original = $account['data']['card_testing_protection_eligible'] ?? null;
		$account['data']['card_testing_protection_eligible'] = ${
			eligible ? 'true' : 'false'
		};
		update_option( 'wcpay_account_data', $account );
		return $original;
	` );
}

async function restoreCardTestingProtectionEligible(
	original: unknown
): Promise< void > {
	await wpEvalJson< unknown >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to restore.' );
		}
		$account['data']['card_testing_protection_eligible'] = json_decode( '${ JSON.stringify(
			original ?? null
		) }' );
		update_option( 'wcpay_account_data', $account );
		return true;
	` );
}

test.describe( 'WooPayments native basic card charge fidelity', () => {
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		'One Blocks checkout submission with the basic card settles exactly one run-owned order, one succeeded 1099 usd PaymentIntent, and one captured charge, and creates no second order, intent, charge, capture, or challenge',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_B1 },
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

				// The claim's input fixes the cart, not only the product.
				const cart = await (
					await page.request.get( '/wp-json/wc/store/v1/cart' )
				).json();
				expect( cart.items_count ).toBe( 1 );
				expect( cart.totals.total_price ).toBe(
					String( AMOUNT_MINOR )
				);
				expect(
					String( cart.totals.currency_code ).toUpperCase()
				).toBe( CURRENCY );

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
				const onRequest = ( request: Request ): void => {
					if ( ! isStoreCheckoutRequest( request ) ) {
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
						saveRequested = ( body?.payment_data ?? [] ).find(
							( entry ) =>
								entry.key ===
								'wc-woocommerce_payments-new-payment-method'
						)?.value;
					} catch {
						// The count above is what the cardinality clause needs.
					}
				};
				const onResponse = ( response: Response ): void => {
					if ( isStoreCheckoutRequest( response.request() ) ) {
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

				const orderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);
				const payment = await expectSettledCardPayment(
					restApi,
					orderId,
					{
						amountMinor: AMOUNT_MINOR,
						currency: CURRENCY,
						card: CARD,
					}
				);
				expectSingleSettledGraph( payment, orderId );

				const newOrders = await readNewOrders(
					restApi,
					baselineOrderId
				);
				expect(
					newOrders.map( ( order ) => order.id ),
					'one submission must create exactly one order'
				).toEqual( [ orderId ] );
				expect(
					newOrders
						.filter( ( order ) =>
							PAID_ORDER_STATUSES.includes( order.status )
						)
						.map( ( order ) => order.id )
				).toEqual( [ orderId ] );
			} finally {
				await deleteProduct( restApi, product.id );
			}
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
		async ( { page, restApi } ) => {
			const original = await setCardTestingProtectionEligible( true );
			try {
				const baselineOrderId = await readHighestOrderId( restApi );
				const product = await createRunProduct( restApi );
				try {
					// Half one: a token-bearing submission settles exactly as
					// B1's graph does.
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
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

					const exposedToken = await page.evaluate(
						() =>
							( window as unknown as Record< string, unknown > )
								.wcpayFraudPreventionToken
					);
					expect(
						typeof exposedToken === 'string' && exposedToken !== '',
						'a token-bearing submission requires a real exposed fraud-prevention token'
					).toBe( true );

					let admittedRequestCount = 0;
					let submittedToken: unknown;
					let admittedGateway: unknown;
					let admittedSaveRequested: unknown;
					const captureAdmittedRequest = ( request: Request ) => {
						if ( request.method() !== 'POST' ) {
							return;
						}
						const params = new URLSearchParams(
							request.postData() ?? ''
						);
						if ( ! params.has( 'payment_method' ) ) {
							return;
						}
						admittedRequestCount += 1;
						admittedGateway = params.get( 'payment_method' );
						admittedSaveRequested = params.has(
							'wc-woocommerce_payments-new-payment-method'
						)
							? params.get(
									'wc-woocommerce_payments-new-payment-method'
							  ) === 'true'
							: false;
						submittedToken = params.get(
							'wcpay-fraud-prevention-token'
						);
					};
					page.on( 'request', captureAdmittedRequest );
					try {
						await page
							.getByRole( 'button', { name: /place order/i } )
							.click();
						await page.waitForURL( /\/order-received\/[1-9]\d*/, {
							timeout: RECEIPT_TIMEOUT_MS,
						} );
					} finally {
						page.off( 'request', captureAdmittedRequest );
					}
					expect(
						admittedRequestCount,
						'the admitted half must dispatch exactly one checkout submission'
					).toBe( 1 );
					expect( admittedGateway ).toBe( 'woocommerce_payments' );
					expect(
						admittedSaveRequested,
						'the admitted submission must not request a saved payment method'
					).toBe( false );
					expect(
						submittedToken,
						'the admitted submission must carry the exact exposed token'
					).toBe( exposedToken );

					const admittedOrderId = Number(
						/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
					);
					const payment = await expectSettledCardPayment(
						restApi,
						admittedOrderId,
						{
							amountMinor: AMOUNT_MINOR,
							currency: CURRENCY,
							card: CARD,
						}
					);
					expectSingleSettledGraph( payment, admittedOrderId );

					const intent = await getPaymentIntent(
						restApi,
						payment.intentId
					);
					expect(
						intent.next_action ?? null,
						'a basic-card purchase must raise no challenge'
					).toBeNull();
					expect(
						intent.setup_future_usage ?? null,
						'a purchase that saves nothing must set up no future usage'
					).toBeNull();
					const providerCustomerId = String( intent.customer ?? '' );
					expect( providerCustomerId ).not.toBe( '' );
					const attachedMethods = (
						await restApi.get(
							`wc/v3/payments/customers/${ encodeURIComponent(
								providerCustomerId
							) }/payment_methods`
						)
					).data;
					expect(
						attachedMethods,
						'a purchase that saves nothing must attach no reusable payment method'
					).toEqual( [] );

					// Half two: the same store, the same session, one
					// submission with the fraud-prevention token stripped
					// from the wire before it reaches native.
					const tokenlessBaselineOrderId =
						await readHighestOrderId( restApi );
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
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

					let refusalRequestCount = 0;
					let refusalGateway: unknown;
					const rejectionRoute = '**/*wc-ajax=checkout*';
					await page.route( rejectionRoute, async ( route ) => {
						const request = route.request();
						const params = new URLSearchParams(
							request.postData() ?? ''
						);
						refusalRequestCount += 1;
						refusalGateway = params.get( 'payment_method' );
						params.delete( 'wcpay-fraud-prevention-token' );
						await route.continue( { postData: params.toString() } );
					} );
					try {
						await page
							.getByRole( 'button', { name: /place order/i } )
							.click();
						// The classic checkout's own top-level rejection
						// notice, an announced alert region - not the
						// per-payment-method Stripe error regions (which
						// share the `woocommerce-error` class but stay
						// `hidden` here, since the rejection never reaches
						// Stripe.js at all).
						const notice = page
							.getByRole( 'alert' )
							.filter( { hasText: CARD_TESTING_REJECTION_TEXT } );
						await expect( notice ).toBeVisible( {
							timeout: RECEIPT_TIMEOUT_MS,
						} );
					} finally {
						await page.unroute( rejectionRoute );
					}
					expect( page.url() ).not.toContain( 'order-received' );
					expect(
						refusalRequestCount,
						'the refused half must dispatch exactly one checkout submission'
					).toBe( 1 );
					expect( refusalGateway ).toBe( 'woocommerce_payments' );

					// No payment context: no order created since the refused
					// half's own baseline carries a provider intent or
					// charge, no paid order exists, and the run-owned
					// provider customer stays exactly as the admitted half
					// left it.
					const tokenlessDelta = await readNewOrders(
						restApi,
						tokenlessBaselineOrderId
					);
					for ( const order of tokenlessDelta ) {
						const orderRecord = (
							await restApi.get(
								`${ ORDERS_ROUTE }/${ order.id }`
							)
						).data as Record< string, unknown >;
						expect(
							orderMeta( orderRecord, '_intent_id' ),
							'a refused submission must create no order carrying a provider intent id'
						).toBe( '' );
						expect(
							orderMeta( orderRecord, '_charge_id' ),
							'a refused submission must create no order carrying a provider charge id'
						).toBe( '' );
					}
					expect(
						tokenlessDelta.filter( ( order ) =>
							PAID_ORDER_STATUSES.includes( order.status )
						),
						'a refused submission must leave no paid order'
					).toEqual( [] );
					const attachedMethodsAfterRefusal = (
						await restApi.get(
							`wc/v3/payments/customers/${ encodeURIComponent(
								providerCustomerId
							) }/payment_methods`
						)
					).data;
					expect(
						attachedMethodsAfterRefusal,
						'a refused submission must attach nothing to the run provider customer'
					).toEqual( [] );
					const paymentAfterRefusal = await expectSettledCardPayment(
						restApi,
						admittedOrderId,
						{
							amountMinor: AMOUNT_MINOR,
							currency: CURRENCY,
							card: CARD,
						}
					);
					expect(
						paymentAfterRefusal,
						'a refused submission must not change the admitted payment'
					).toEqual( payment );

					// Across both halves, exactly one paid order exists.
					const wholeDelta = await readNewOrders(
						restApi,
						baselineOrderId
					);
					expect(
						wholeDelta
							.filter( ( order ) =>
								PAID_ORDER_STATUSES.includes( order.status )
							)
							.map( ( order ) => order.id ),
						'the admitted submission is the only one that may have paid'
					).toEqual( [ admittedOrderId ] );
				} finally {
					await deleteProduct( restApi, product.id );
				}
			} finally {
				await restoreCardTestingProtectionEligible( original );
			}
		}
	);
} );
