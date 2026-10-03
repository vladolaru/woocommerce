import type { Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { random } from '../../../utils/helpers';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	expectSettledCardPayment,
	getPaymentIntent,
	requireTestModeAccount,
} from '../../../utils/woopayments';

/**
 * The `redirect-method-provider-outcome` provider-fidelity family (T.4 Batch
 * P3 rewrite).
 *
 * This family kept one browser smoke, `A1` (Alipay on the classic shortcode
 * checkout). Every other case the family used to browser-test (affirm,
 * afterpay_clearpay, bancontact, klarna, and the protection-on twins) was
 * deleted in T.1, and its assertions moved to PHPUnit and Jest: the exact
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

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:redirect-method-provider-outcome',
];

const CONTRACT_A1 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/alipay-checkout-purchase.spec.ts:60::Alipay Checkout › checkout on shortcode checkout page';

const ALIPAY = {
	id: 'alipay',
	gatewayId: 'woocommerce_payments_alipay',
	label: /alipay/i,
	price: '12.00',
	amountMinor: 1200,
	currency: 'USD',
};

const PAYMENTS_SETTINGS_ROUTE = 'wc/v3/payments/settings';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const RECEIPT_TIMEOUT_MS = 90_000;
const HOSTED_PAGE_TIMEOUT_MS = 90_000;
const ORDER_INTENT_TIMEOUT_MS = 60_000;
const POLL_INTERVAL_MS = 500;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function readEnabledPaymentMethodIds(
	restApi: ApiClient
): Promise< string[] > {
	const settings = ( await restApi.get( PAYMENTS_SETTINGS_ROUTE ) ).data as {
		enabled_payment_method_ids?: unknown;
	};
	if ( ! Array.isArray( settings.enabled_payment_method_ids ) ) {
		throw new Error( 'Payments settings exposed no enabled-method list.' );
	}
	return settings.enabled_payment_method_ids as string[];
}

async function writeEnabledPaymentMethodIds(
	restApi: ApiClient,
	ids: string[]
): Promise< void > {
	await restApi.post( PAYMENTS_SETTINGS_ROUTE, {
		enabled_payment_method_ids: ids,
	} );
	const echoed = await readEnabledPaymentMethodIds( restApi );
	if ( echoed.toSorted().join( ',' ) !== ids.toSorted().join( ',' ) ) {
		throw new Error(
			`enabled-payment-method write did not take effect; requested ${ ids.join(
				', '
			) } but the store reports ${ echoed.join( ', ' ) }.`
		);
	}
}

async function readCardTestingProtectionEligible(
	restApi: ApiClient
): Promise< unknown > {
	return ( await restApi.get( 'wc/v3/payments/accounts' ) ).data
		.card_testing_protection_eligible;
}

async function createRunProduct( restApi: ApiClient ): Promise< number > {
	const created = (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments alipay redirect ${ random() }`,
			type: 'simple',
			virtual: true,
			regular_price: ALIPAY.price,
			status: 'publish',
		} )
	).data as { id: number };
	return created.id;
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

async function readOrderKey(
	restApi: ApiClient,
	orderId: number
): Promise< string > {
	return (
		( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) ).data as {
			order_key: string;
		}
	 ).order_key;
}

async function readNewOrderIds(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< number[] > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number } >;
	return orders
		.filter( ( order ) => order.id > baselineOrderId )
		.map( ( order ) => order.id )
		.toSorted( ( a, b ) => a - b );
}

async function readOrderIntentId(
	restApi: ApiClient,
	orderId: number
): Promise< string > {
	const deadline = Date.now() + ORDER_INTENT_TIMEOUT_MS;
	for (;;) {
		const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
			.data as {
			meta_data?: Array< { key?: unknown; value?: unknown } >;
		};
		const entry = ( order.meta_data ?? [] ).find(
			( item ) => item.key === '_intent_id'
		);
		if ( typeof entry?.value === 'string' && entry.value.trim() ) {
			return entry.value;
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`Order ${ orderId } never carried the PaymentIntent native created for its redirect handoff.`
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

interface RedirectIntentRequest {
	paymentMethodTypes: string[];
	amountMinor: number;
	currency: string;
	status: string;
	nextActionType: string;
	providerRedirectUrl: string;
	returnUrl: string;
	chargeCount: number;
}

function chargeCount( intent: Record< string, unknown > ): number {
	const charges = intent.charges as { data?: unknown[] } | undefined;
	return Array.isArray( charges?.data ) ? charges.data.length : 0;
}

/**
 * Reads the intent's account of the redirect request. The provider does not
 * use one next-action shape for every redirect method: `redirect_to_url` is
 * the generic one, but a method it models explicitly gets its own key -
 * Alipay produces `alipay_handle_redirect` - carrying the same `url` and
 * `return_url` pair under it.
 */
function readRedirectIntentRequest(
	intent: Record< string, unknown >
): RedirectIntentRequest {
	const nextAction =
		( intent.next_action as Record< string, unknown > | undefined ) ?? {};
	const redirectActionKey = Object.keys( nextAction ).find(
		( key ) =>
			( key === 'redirect_to_url' ||
				key.endsWith( '_handle_redirect' ) ) &&
			typeof nextAction[ key ] === 'object' &&
			nextAction[ key ] !== null
	);
	const redirect = redirectActionKey
		? ( nextAction[ redirectActionKey ] as Record< string, unknown > )
		: {};
	return {
		paymentMethodTypes: Array.isArray( intent.payment_method_types )
			? ( intent.payment_method_types as string[] )
			: [],
		amountMinor:
			typeof intent.amount === 'number' ? intent.amount : Number.NaN,
		currency:
			typeof intent.currency === 'string'
				? intent.currency.toLowerCase()
				: '',
		status: String( intent.status ?? '' ),
		nextActionType:
			typeof nextAction.type === 'string' ? nextAction.type : '',
		providerRedirectUrl:
			typeof redirect.url === 'string' ? redirect.url : '',
		returnUrl:
			typeof redirect.return_url === 'string' ? redirect.return_url : '',
		chargeCount: chargeCount( intent ),
	};
}

interface ReturnUrlFacts {
	origin: string;
	orderId: number;
	orderKey: string;
	paymentMethod: string;
	noncePresent: boolean;
}

function readReturnUrlFacts( value: string ): ReturnUrlFacts {
	const parsed = new URL( value );
	const match = /\/order-received\/([1-9]\d*)\/?$/.exec( parsed.pathname );
	if ( ! match ) {
		throw new Error(
			`return URL does not name an order-received page: ${ parsed.pathname }`
		);
	}
	return {
		origin: parsed.origin,
		orderId: Number( match[ 1 ] ),
		orderKey: parsed.searchParams.get( 'key' ) ?? '',
		paymentMethod: parsed.searchParams.get( 'wc_payment_method' ) ?? '',
		noncePresent: ( parsed.searchParams.get( '_wpnonce' ) ?? '' ) !== '',
	};
}

function isClassicCheckoutRequest( request: Request, origin: string ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const url = new URL( request.url() );
		return (
			url.origin === origin &&
			url.searchParams.getAll( 'wc-ajax' ).join( ',' ) === 'checkout'
		);
	} catch {
		return false;
	}
}

/**
 * Asserts the store's own answer to the checkout submission - the property
 * DISPOSITION row 115 says only this browser case proves, because a hash
 * answer never navigates the document, so the body stays readable here in a
 * way it cannot once native's hosted-redirect handoff takes over the page.
 *
 * For a method the provider models explicitly (`alipay_handle_redirect`),
 * neither runtime performs the handoff server-side: the store answers with
 * its own `#wcpay-confirm-pi:<order>:<secret>:<nonce>` hash and the
 * provider's own script performs the redirect. For the generic
 * `redirect_to_url` action, the store answers with the hosted URL itself.
 */
function expectStoreHandoff(
	redirectAnswer: string,
	nextActionType: string,
	orderId: number,
	hostedUrl: string,
	storeOrigin: string
): void {
	const handed = new URL( redirectAnswer, storeOrigin );
	if ( nextActionType === 'redirect_to_url' ) {
		const hosted = new URL( hostedUrl );
		expect(
			`${ handed.origin }${ handed.pathname }`,
			'the store must hand the shopper the exact redirect the intent names'
		).toBe( `${ hosted.origin }${ hosted.pathname }` );
		return;
	}
	const segments = handed.hash.split( ':' );
	expect(
		segments[ 0 ],
		`neither runtime reads ${ nextActionType } as a redirect, so the store must hand back the local confirmation hash`
	).toBe( '#wcpay-confirm-pi' );
	expect(
		Number( segments[ 1 ] ),
		'the confirmation hash must name the order this submission created'
	).toBe( orderId );
	expect(
		segments.length,
		'the confirmation hash must carry its order, client secret and nonce'
	).toBeGreaterThanOrEqual( 4 );
	expect(
		`${ handed.origin }${ handed.pathname }`,
		'the store answered with an off-store redirect for a method whose next action neither runtime reads'
	).toBe( `${ storeOrigin }/` );
}

test.describe( 'WooPayments native redirect-method provider outcome fidelity', () => {
	test.describe.configure( { timeout: 300_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		'One Alipay checkout sends the provider method alipay for 1200 usd with this run order-received return URL, and the single redirect settles that same PaymentIntent to succeeded with one captured charge',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_A1 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi, baseURL } ) => {
			// This is a protection-off case, and residual risk in this family
			// says a leaked protection-on state can misclassify it. Assert the
			// real precondition rather than assume it.
			expect(
				await readCardTestingProtectionEligible( restApi ),
				'this is a protection-off case and must not run against a protected store'
			).toBe( false );

			const originalMethods =
				await readEnabledPaymentMethodIds( restApi );
			const alreadyEnabled = originalMethods.includes( ALIPAY.id );
			if ( ! alreadyEnabled ) {
				await writeEnabledPaymentMethodIds( restApi, [
					...originalMethods,
					ALIPAY.id,
				] );
			}

			let productId: number | undefined;
			let primaryError: unknown;
			try {
				const storeOrigin = new URL( baseURL! ).origin;
				const baselineOrderId = await readHighestOrderId( restApi );
				productId = await createRunProduct( restApi );
				{
					await page.goto( `?post_type=product&p=${ productId }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
						.click();
					await page.goto( 'classic-checkout/' );

					const billing = page.locator(
						'.woocommerce-billing-fields:has(#billing_first_name)'
					);
					await expect( billing ).toBeVisible();
					await billing.getByLabel( /^First name/i ).fill( 'E2E' );
					await billing
						.getByLabel( /^Last name/i )
						.fill( 'WooPayments' );
					await billing
						.locator( '#billing_country' )
						.selectOption( 'US' );
					await billing
						.getByLabel( /^Street address/i )
						.first()
						.fill( '123 Test Street' );
					await billing
						.getByLabel( /^(?:Town \/ City|City)/i )
						.fill( 'San Francisco' );
					await billing
						.locator( '#billing_state' )
						.selectOption( 'CA' );
					await billing
						.getByLabel( /^(?:ZIP Code|Postcode)/i )
						.fill( '94107' );
					await billing.getByLabel( /^Phone/i ).fill( '5555550100' );
					await page
						.locator( '#billing_email' )
						.fill( `woopayments-${ random() }@example.com` );

					const methodRadio = page.locator(
						`input[name="payment_method"][value="${ ALIPAY.gatewayId }"]`
					);
					await expect( methodRadio ).toHaveCount( 1 );
					await methodRadio.check();
					await expect(
						page.locator(
							`label[for="payment_method_${ ALIPAY.gatewayId }"]`
						)
					).toHaveText( ALIPAY.label );

					let checkoutRequestCount = 0;
					const checkoutResponses: Array<
						Promise< { status: number; redirect: string } >
					> = [];
					const onRequest = ( request: Request ): void => {
						if (
							isClassicCheckoutRequest( request, storeOrigin )
						) {
							checkoutRequestCount += 1;
						}
					};
					const onResponse = ( response: Response ): void => {
						if (
							! isClassicCheckoutRequest(
								response.request(),
								storeOrigin
							)
						) {
							return;
						}
						// Read the body the moment the response arrives: a hash
						// answer never navigates the document, but a
						// `redirect_to_url` answer does, and a body read after
						// that navigation can no longer be fetched.
						checkoutResponses.push(
							response
								.json()
								.then( ( body: { redirect?: unknown } ) => ( {
									status: response.status(),
									redirect:
										typeof body.redirect === 'string'
											? body.redirect
											: '',
								} ) )
						);
					};
					page.on( 'request', onRequest );
					page.on( 'response', onResponse );
					try {
						await page
							.getByRole( 'button', { name: /place order/i } )
							.click();
						// The classic checkout hands the shopper straight to the
						// provider's hosted test page; wait for its own control
						// rather than a store-side URL, since the browser is
						// about to leave this store.
						await page
							.getByText( 'Authorize Test Payment' )
							.first()
							.waitFor( {
								state: 'visible',
								timeout: HOSTED_PAGE_TIMEOUT_MS,
							} );
					} finally {
						page.off( 'request', onRequest );
						page.off( 'response', onResponse );
					}
					expect(
						checkoutRequestCount,
						'one Place order activation must ask the store exactly once'
					).toBe( 1 );
					const checkoutExchanges =
						await Promise.all( checkoutResponses );
					expect(
						checkoutExchanges,
						'one Place order activation must receive exactly one checkout response'
					).toHaveLength( 1 );
					const [ checkoutExchange ] = checkoutExchanges;
					expect( checkoutExchange.status ).toBeGreaterThanOrEqual(
						200
					);
					expect( checkoutExchange.status ).toBeLessThan( 300 );
					expect(
						checkoutExchange.redirect,
						'the checkout response must carry a readable redirect answer'
					).not.toBe( '' );

					const newOrderIds = await readNewOrderIds(
						restApi,
						baselineOrderId
					);
					expect(
						newOrderIds,
						'one submission must create exactly one order'
					).toHaveLength( 1 );
					const [ orderId ] = newOrderIds;
					const orderKey = await readOrderKey( restApi, orderId );

					const intentId = await readOrderIntentId(
						restApi,
						orderId
					);
					// Read while the intent still awaits the redirect - the
					// only window in which its next-action still names the
					// request the provider received.
					const intent = await getPaymentIntent( restApi, intentId );
					const request = readRedirectIntentRequest( intent );

					expect(
						request.paymentMethodTypes,
						'the provider must have been asked for exactly alipay'
					).toEqual( [ ALIPAY.id ] );
					expect( request.amountMinor ).toBe( ALIPAY.amountMinor );
					expect( request.currency ).toBe( 'usd' );
					expect(
						request.status,
						'the intent must await the provider redirect at this point'
					).toBe( 'requires_action' );
					expect(
						request.nextActionType,
						"the intent must await alipay's own provider redirect"
					).toMatch( /^(redirect_to_url|alipay_handle_redirect)$/ );
					expect(
						request.chargeCount,
						'no charge may exist before the shopper authorizes'
					).toBe( 0 );

					const hosted = new URL( request.providerRedirectUrl );
					expect( hosted.protocol ).toBe( 'https:' );
					expect(
						hosted.origin,
						'the handoff must leave this store for the provider'
					).not.toBe( storeOrigin );

					// The store's own answer to the submission: DISPOSITION row
					// 115 says only this browser case proves this handoff.
					expectStoreHandoff(
						checkoutExchange.redirect,
						request.nextActionType,
						orderId,
						request.providerRedirectUrl,
						storeOrigin
					);

					// Neither runtime reads `alipay_handle_redirect` as a
					// return-URL echo: the provider interposes its own hop
					// rather than the merchant URL. Assert the hop is real,
					// off-store and HTTPS; the run-identifying half is proven
					// by the landed URL below.
					if ( request.nextActionType !== 'redirect_to_url' ) {
						const hop = new URL( request.returnUrl );
						expect( hop.protocol ).toBe( 'https:' );
						expect( hop.origin ).not.toBe( storeOrigin );
					} else {
						const returnUrl = readReturnUrlFacts(
							request.returnUrl
						);
						expect( returnUrl.origin ).toBe( storeOrigin );
						expect( returnUrl.orderId ).toBe( orderId );
						expect( returnUrl.orderKey ).toBe( orderKey );
						expect( returnUrl.paymentMethod ).toBe(
							'woocommerce_payments'
						);
						expect( returnUrl.noncePresent ).toBe( true );
					}

					// The single provider interaction this run may make: click
					// the hosted authorization once and come back to the store.
					await page
						.getByText( 'Authorize Test Payment' )
						.first()
						.click();
					await page.waitForURL( /\/order-received\/[1-9]\d*/, {
						timeout: RECEIPT_TIMEOUT_MS,
					} );
					await expect(
						page.getByText(
							/^(Your order has been received|Order received)$/i
						)
					).toBeVisible();

					const landed = readReturnUrlFacts( page.url() );
					expect( landed.origin ).toBe( storeOrigin );
					expect(
						landed.orderId,
						'the provider must return to this run own order'
					).toBe( orderId );
					expect( landed.orderKey ).toBe( orderKey );
					expect( landed.paymentMethod ).toBe(
						'woocommerce_payments'
					);

					const payment = await expectSettledCardPayment(
						restApi,
						orderId,
						{ amountMinor: ALIPAY.amountMinor, currency: 'USD' }
					);
					expect( payment.intentId ).toBe( intentId );
					expect( PAID_ORDER_STATUSES ).toContain(
						payment.orderStatus
					);

					expect(
						await readNewOrderIds( restApi, baselineOrderId ),
						'the settled redirect must not have created a second order'
					).toEqual( [ orderId ] );
				}
			} catch ( error ) {
				primaryError = error;
			}

			// Each cleanup step runs independently, in its own `try`, so a
			// failure in one (the product delete, say) cannot mask another
			// (the method-list restore) or the case's own error above.
			const cleanupFailures: string[] = [];
			if ( productId !== undefined ) {
				try {
					await restApi.delete(
						`${ PRODUCTS_ROUTE }/${ productId }`,
						{
							force: true,
						}
					);
				} catch ( error ) {
					cleanupFailures.push(
						`product delete: ${
							error instanceof Error ? error.message : error
						}`
					);
				}
			}
			try {
				if ( ! alreadyEnabled ) {
					await restApi.post( PAYMENTS_SETTINGS_ROUTE, {
						enabled_payment_method_ids: originalMethods,
					} );
					const restored =
						await readEnabledPaymentMethodIds( restApi );
					// Order-sensitive, as the original driver restored it: a
					// same-set-different-order result is not a byte-for-byte
					// restore.
					if (
						restored.join( ',' ) !== originalMethods.join( ',' )
					) {
						throw new Error(
							`enabled-payment-method list was not restored in its original order; expected ${ originalMethods.join(
								', '
							) } but the store reports ${ restored.join(
								', '
							) }.`
						);
					}
				}
			} catch ( error ) {
				cleanupFailures.push(
					`enabled-payment-method restore: ${
						error instanceof Error ? error.message : error
					}`
				);
			}

			if ( primaryError !== undefined ) {
				throw primaryError;
			}
			if ( cleanupFailures.length > 0 ) {
				throw new Error(
					`Cleanup failed for: ${ cleanupFailures.join( '; ' ) }`
				);
			}
		}
	);
} );
