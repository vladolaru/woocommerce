import type { Request } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	expectSettledCardPayment,
	fillCardDetails,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `subscription-provider-lifecycle` provider-fidelity family (T.4 Batch
 * P3 rewrite): the retained merchant-renewal browser smoke.
 *
 * `FIDELITY-CLAIMS.md`: one signup and one merchant-triggered renewal must
 * preserve the exact order, subscription, token and provider relationships
 * across the real provider boundary, with no duplicate subscription, renewal
 * order, intent or charge. The six other cases this family used to run moved
 * to PHPUnit and Jest in T.1/T.3 (see the class docblock references below);
 * nothing here is evidence for the change-payment-method surface, the
 * scheduled (cron) renewal path, or the Blocks subscription checkout.
 *
 * Every case in this family drives the Classic checkout, because the
 * change-payment surface WooCommerce Subscriptions renders is the Classic
 * order-pay form whatever the store's checkout page is.
 */

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:subscription-provider-lifecycle',
];
const CONTRACT_S6_MERCHANT =
	'default::chromium::tests/e2e/specs/subscriptions/merchant/merchant-subscriptions-renew.spec.ts:61::Subscriptions › Renew a subscription as a merchant › should be able to renew a subscription in my account';

const SUBSCRIPTION_GATEWAY = 'woocommerce_payments';
const SUBSCRIPTION_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/subscription-evidence';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const SUBSCRIPTIONS_ROUTE = 'wc/v3/subscriptions';
const PROCESS_RENEWAL_ACTION = 'wcs_process_renewal';
const RECURRING_PRICE = '9.99';
const RECURRING_MINOR = 999;
const CARD = { brand: 'visa', last4: '4242' };
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const RECEIPT_TIMEOUT_MS = 90_000;
const CONVERGENCE_TIMEOUT_MS = 120_000;
const POLL_INTERVAL_MS = 3_000;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

interface SubscriptionEvidence {
	id: number;
	status: string;
	parentId: number;
	customerId: number;
	currency: string;
	paymentMethod: string;
	billingPeriod: string;
	billingInterval: string;
	requiresManualRenewal: boolean;
	paymentCount: number;
	paymentTokenIds: number[];
	activeTokenId: number;
	paymentTokens: Array< {
		tokenId: number;
		paymentMethodId: string;
		gatewayId: string;
		userId: number;
	} >;
	relatedOrders: { parent: number[]; renewal: number[] };
}

function parseSubscriptionEvidence(
	raw: Record< string, unknown >
): SubscriptionEvidence {
	const related = raw.related_orders as Record< string, unknown[] >;
	return {
		id: raw.id as number,
		status: raw.status as string,
		parentId: raw.parent_id as number,
		customerId: raw.customer_id as number,
		currency: raw.currency as string,
		paymentMethod: raw.payment_method as string,
		billingPeriod: raw.billing_period as string,
		billingInterval: String( raw.billing_interval ),
		requiresManualRenewal: raw.requires_manual_renewal === true,
		paymentCount: raw.payment_count as number,
		paymentTokenIds: ( raw.payment_token_ids as number[] ) ?? [],
		activeTokenId: raw.active_token_id as number,
		paymentTokens: (
			( raw.payment_tokens as Array< Record< string, unknown > > ) ?? []
		).map( ( token ) => ( {
			tokenId: token.token_id as number,
			paymentMethodId: token.payment_method_id as string,
			gatewayId: token.gateway_id as string,
			userId: token.user_id as number,
		} ) ),
		relatedOrders: {
			parent: ( related.parent as number[] ) ?? [],
			renewal: ( related.renewal as number[] ) ?? [],
		},
	};
}

async function readSubscriptionEvidence(
	restApi: ApiClient,
	subscriptionId: number
): Promise< SubscriptionEvidence > {
	const data = (
		await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
			subscription_id: String( subscriptionId ),
		} )
	).data as { subscription: Record< string, unknown > };
	return parseSubscriptionEvidence( data.subscription );
}

/**
 * True once the subscription is verifiably gone: either the evidence route
 * no longer finds it (404), or it reports a terminal `cancelled`/`trash`
 * status. A run-owned subscription left active would still be armed
 * recurring billing, which the old case's cleanup proof refused to accept
 * (R9).
 */
async function verifySubscriptionRemoved(
	restApi: ApiClient,
	subscriptionId: number
): Promise< void > {
	try {
		const data = (
			await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
				subscription_id: String( subscriptionId ),
			} )
		).data as { subscription?: { status?: string } };
		const status = data.subscription?.status;
		if ( status !== 'cancelled' && status !== 'trash' ) {
			throw new Error(
				`subscription ${ subscriptionId } still reports status ${ String(
					status
				) } after cancel and delete.`
			);
		}
	} catch ( error ) {
		const status = (
			error as { response?: { status?: number } } | undefined
		 )?.response?.status;
		if ( status === 404 ) {
			return;
		}
		throw error instanceof Error ? error : new Error( String( error ) );
	}
}

async function readCustomerSubscriptionIds(
	restApi: ApiClient,
	customerUsername: string
): Promise< number[] > {
	const data = (
		await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
			customer_username: customerUsername,
		} )
	).data as { customer_subscription_ids: number[] };
	return data.customer_subscription_ids ?? [];
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
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

/** Polls one subscription until `isTerminal` holds, then requires one more identical read. */
async function convergedSubscription(
	restApi: ApiClient,
	subscriptionId: number,
	isTerminal: ( evidence: SubscriptionEvidence ) => boolean,
	reason: string
): Promise< SubscriptionEvidence > {
	const deadline = Date.now() + CONVERGENCE_TIMEOUT_MS;
	for (;;) {
		const evidence = await readSubscriptionEvidence(
			restApi,
			subscriptionId
		);
		if ( isTerminal( evidence ) ) {
			await delay( POLL_INTERVAL_MS );
			const confirmed = await readSubscriptionEvidence(
				restApi,
				subscriptionId
			);
			if ( JSON.stringify( confirmed ) === JSON.stringify( evidence ) ) {
				return confirmed;
			}
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`Subscription ${ subscriptionId } never converged: ${ reason }.`
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

const SAVED_CARD_EVIDENCE_API = 'wc-native-payments-e2e/v1/saved-card-evidence';
const PAYMENT_METHODS_ROUTE = 'wc/v3/payments/customers';

interface SavedCardToken {
	tokenId: number;
	paymentMethodId: string;
}

/** The customer's own saved-token set, the same route `ST` uses. */
async function readCustomerTokens(
	restApi: ApiClient,
	customerUsername: string
): Promise< SavedCardToken[] > {
	const data = (
		await restApi.get( SAVED_CARD_EVIDENCE_API, {
			customer_username: customerUsername,
		} )
	).data as {
		tokens?: Array< { token_id: number; payment_method_id: string } >;
	};
	return ( data.tokens ?? [] ).map( ( token ) => ( {
		tokenId: token.token_id,
		paymentMethodId: token.payment_method_id,
	} ) );
}

async function readProviderCustomerId(
	restApi: ApiClient,
	customerUsername: string
): Promise< string > {
	const data = (
		await restApi.get( SAVED_CARD_EVIDENCE_API, {
			customer_username: customerUsername,
		} )
	).data as { provider_customer_id?: string };
	if ( ! data.provider_customer_id ) {
		throw new Error(
			`the store disclosed no provider customer for ${ customerUsername }.`
		);
	}
	return data.provider_customer_id;
}

async function readProviderAttachments(
	restApi: ApiClient,
	providerCustomerId: string
): Promise< string[] > {
	const methods = (
		await restApi.get(
			`${ PAYMENT_METHODS_ROUTE }/${ encodeURIComponent(
				providerCustomerId
			) }/payment_methods`
		)
	).data as Array< { id: string } >;
	return methods.map( ( method ) => method.id );
}

/**
 * No duplicate credential (R7): exactly one Woo token for this customer,
 * tied to the subscription's own recurring token, and exactly one provider
 * attachment of that token's method. Runs after signup and again after the
 * renewal, since the title promises "no duplicate ... credential" across
 * both.
 */
async function expectSingleCredential(
	restApi: ApiClient,
	customerUsername: string,
	expectedTokenId: number,
	expectedPaymentMethodId: string,
	providerCustomerId: string,
	reason: string
): Promise< void > {
	const tokens = await readCustomerTokens( restApi, customerUsername );
	expect(
		tokens,
		`${ reason }: the customer must hold exactly one saved token`
	).toHaveLength( 1 );
	expect( tokens[ 0 ].tokenId ).toBe( expectedTokenId );
	expect( tokens[ 0 ].paymentMethodId ).toBe( expectedPaymentMethodId );
	expect(
		await readProviderAttachments( restApi, providerCustomerId ),
		`${ reason }: the provider must hold exactly the one attached method`
	).toEqual( [ expectedPaymentMethodId ] );
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

let customer: ReturnType< typeof getFakeUser >;
let customerId: number;

test.describe( 'WooPayments native subscription provider lifecycle fidelity', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
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
		"one merchant renewal produces exactly one 999 usd renewal order, intent and captured charge on the signup token's exact payment method, distinct from the parent order's own intent, and advances the subscription exactly once with no duplicate order or credential",
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_S6_MERCHANT,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi, baseURL, browser } ) => {
			await page.goto( 'wp-login.php' );
			await logIn( page, customer.username, customer.password, false );
			await page.goto( 'my-account/' );
			await expect(
				page.getByText( new RegExp( `Hello ${ customer.first_name }` ) )
			).toBeVisible();

			expect(
				await readCustomerSubscriptionIds( restApi, customer.username ),
				'a fresh customer must start with no subscriptions'
			).toEqual( [] );

			const product = (
				await restApi.post( PRODUCTS_ROUTE, {
					name: `WooPayments native subscription E2E ${ customer.username }`,
					type: 'subscription',
					virtual: true,
					regular_price: RECURRING_PRICE,
					status: 'publish',
					meta_data: [
						{ key: '_subscription_price', value: RECURRING_PRICE },
						{ key: '_subscription_period', value: 'month' },
						{ key: '_subscription_period_interval', value: '1' },
						{ key: '_subscription_length', value: '0' },
						{ key: '_subscription_sign_up_fee', value: '0' },
						{ key: '_subscription_trial_length', value: '0' },
						{ key: '_subscription_trial_period', value: 'day' },
					],
				} )
			).data as { id: number };

			let subscriptionId: number | undefined;
			let parentOrderId: number | undefined;
			let renewalOrderId: number | undefined;
			let primaryError: unknown;

			try {
				const storeOrigin = new URL( baseURL! ).origin;
				const baselineOrderId = await readHighestOrderId( restApi );

				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', {
						name: /^(Sign up now|Add to cart)$/i,
					} )
					.click();
				await page.goto( 'classic-checkout/' );

				const billing = page.locator(
					'.woocommerce-billing-fields:has(#billing_first_name)'
				);
				await expect( billing ).toBeVisible();
				await billing.getByLabel( /^First name/i ).fill( 'E2E' );
				await billing.getByLabel( /^Last name/i ).fill( 'WooPayments' );
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
				await billing.locator( '#billing_state' ).selectOption( 'CA' );
				await billing
					.getByLabel( /^(?:ZIP Code|Postcode)/i )
					.fill( '94107' );
				await billing.getByLabel( /^Phone/i ).fill( '5555550100' );
				await page.locator( '#billing_email' ).fill( customer.email );

				await page
					.locator(
						`input[name="payment_method"][value="${ SUBSCRIPTION_GATEWAY }"]`
					)
					.check();
				await fillCardDetails( page, TEST_CARDS.basic, 'classic' );

				let checkoutRequestCount = 0;
				const onRequest = ( request: Request ): void => {
					if ( isClassicCheckoutRequest( request, storeOrigin ) ) {
						checkoutRequestCount += 1;
					}
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
					'one Place order activation must ask the store exactly once'
				).toBe( 1 );

				parentOrderId = Number(
					/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
				);
				expect(
					await readNewOrderIds( restApi, baselineOrderId ),
					'one signup must create exactly one order'
				).toEqual( [ parentOrderId ] );

				const subscriptionIds = await readCustomerSubscriptionIds(
					restApi,
					customer.username
				);
				expect(
					subscriptionIds,
					'one signup must create exactly one subscription'
				).toHaveLength( 1 );
				subscriptionId = subscriptionIds[ 0 ];

				const signedUp = await convergedSubscription(
					restApi,
					subscriptionId,
					( evidence ) =>
						evidence.status === 'active' &&
						evidence.activeTokenId !== 0,
					'the signup must produce one active subscription carrying a recurring token'
				);
				expect( signedUp.parentId ).toBe( parentOrderId );
				expect( signedUp.relatedOrders.parent ).toEqual( [
					parentOrderId,
				] );
				expect(
					signedUp.relatedOrders.renewal,
					'a signup must create no renewal order'
				).toEqual( [] );
				expect( signedUp.paymentMethod ).toBe( SUBSCRIPTION_GATEWAY );
				expect( signedUp.requiresManualRenewal ).toBe( false );
				expect( signedUp.currency ).toBe( 'USD' );
				expect( signedUp.billingPeriod ).toBe( 'month' );
				expect( signedUp.billingInterval ).toBe( '1' );
				expect(
					signedUp.paymentTokenIds,
					'a signup must leave exactly one recurring credential on the subscription'
				).toHaveLength( 1 );
				const token = signedUp.paymentTokens[ 0 ];
				expect( token.gatewayId ).toBe( SUBSCRIPTION_GATEWAY );
				expect(
					token.userId,
					'the recurring credential must belong to the shopper who bought the subscription'
				).toBe( signedUp.customerId );
				expect(
					token.tokenId,
					"the subscription's active token must be the same row the payment-tokens list names"
				).toBe( signedUp.paymentTokenIds[ 0 ] );
				expect(
					signedUp.customerId,
					'the subscription must belong to the customer this run created'
				).toBe( customerId );

				const providerCustomerId = await readProviderCustomerId(
					restApi,
					customer.username
				);
				await expectSingleCredential(
					restApi,
					customer.username,
					signedUp.paymentTokenIds[ 0 ],
					token.paymentMethodId,
					providerCustomerId,
					'after signup'
				);

				const parentPayment = await expectSettledCardPayment(
					restApi,
					parentOrderId,
					{
						amountMinor: RECURRING_MINOR,
						currency: 'USD',
						card: CARD,
					}
				);
				expect( parentPayment.paymentMethodId ).toBe(
					token.paymentMethodId
				);

				// The merchant-renewal half: WooCommerce Subscriptions' own
				// "Process renewal" order action, on its own admin session so
				// it never shares cookies with the shopper session above.
				const adminContext = await browser.newContext( {
					baseURL,
					storageState: { cookies: [], origins: [] },
				} );
				try {
					const adminPage = await adminContext.newPage();
					await adminPage.goto( 'wp-login.php' );
					await logIn( adminPage, admin.username, admin.password );
					await adminPage.goto(
						`wp-admin/admin.php?page=wc-orders--shop_subscription&action=edit&id=${ subscriptionId }`
					);
					const actionsBox = adminPage.locator(
						'#woocommerce-order-actions'
					);
					await actionsBox
						.locator( 'select[name="wc_order_action"]' )
						.selectOption( PROCESS_RENEWAL_ACTION );
					// The context must not close until the exact renewal POST
					// has actually reached the store: closing right after the
					// dialog is accepted can abort the form submission before
					// the browser ever sends it.
					const renewalRequest = adminPage.waitForResponse(
						( response ) => {
							if ( response.request().method() !== 'POST' ) {
								return false;
							}
							const url = new URL( response.url() );
							if (
								url.searchParams.get( 'page' ) !==
									'wc-orders--shop_subscription' ||
								url.searchParams.get( 'id' ) !==
									String( subscriptionId )
							) {
								return false;
							}
							const body = new URLSearchParams(
								response.request().postData() ?? ''
							);
							return (
								body.get( 'wc_order_action' ) ===
								PROCESS_RENEWAL_ACTION
							);
						},
						{ timeout: 60_000 }
					);
					const renewalResults = await Promise.all( [
						adminPage
							.waitForEvent( 'dialog', { timeout: 60_000 } )
							.then( async ( dialog ) => {
								expect( dialog.type() ).toBe( 'confirm' );
								expect( dialog.message() ).toMatch(
									/process a renewal/i
								);
								await dialog.accept();
							} ),
						renewalRequest,
						actionsBox
							.getByRole( 'button', {
								name: 'Update',
								exact: true,
							} )
							.click(),
					] );
					const renewalResponse = renewalResults[ 1 ];
					expect(
						renewalResponse.status(),
						'the renewal POST must reach the store successfully'
					).toBeLessThan( 400 );
				} finally {
					await adminContext.close();
				}

				const renewed = await convergedSubscription(
					restApi,
					subscriptionId,
					( evidence ) => evidence.relatedOrders.renewal.length === 1,
					'the merchant renewal must produce exactly one new renewal order'
				);
				expect(
					renewed.relatedOrders.renewal,
					'one renewal must create exactly one renewal order'
				).toHaveLength( 1 );
				renewalOrderId = renewed.relatedOrders.renewal[ 0 ];
				expect(
					renewed.paymentCount,
					'one renewal must advance the subscription exactly once'
				).toBe( signedUp.paymentCount + 1 );
				expect(
					renewed.paymentTokenIds,
					'a renewal must not add or replace a recurring credential'
				).toEqual( signedUp.paymentTokenIds );
				await expectSingleCredential(
					restApi,
					customer.username,
					signedUp.paymentTokenIds[ 0 ],
					token.paymentMethodId,
					providerCustomerId,
					'after the merchant renewal'
				);

				const renewalPayment = await expectSettledCardPayment(
					restApi,
					renewalOrderId,
					{
						amountMinor: RECURRING_MINOR,
						currency: 'USD',
						card: CARD,
					}
				);
				expect(
					renewalPayment.paymentMethodId,
					"the renewal must charge the signup token's exact provider method"
				).toBe( token.paymentMethodId );
				expect(
					renewalPayment.intentId,
					"the renewal must have its own PaymentIntent, not the parent order's"
				).not.toBe( parentPayment.intentId );
				expect( PAID_ORDER_STATUSES ).toContain(
					renewalPayment.orderStatus
				);

				const renewalOrder = (
					await restApi.get( `${ ORDERS_ROUTE }/${ renewalOrderId }` )
				).data as { customer_id: number; payment_method: string };
				expect(
					renewalOrder.customer_id,
					'the renewal order must belong to the subscription customer'
				).toBe( signedUp.customerId );
				expect( renewalOrder.payment_method ).toBe(
					SUBSCRIPTION_GATEWAY
				);
			} catch ( error ) {
				primaryError = error;
			}

			// Every cleanup step runs independently, in its own `try`, so one
			// failure (an order that resists deletion, say) cannot mask
			// another or the case's own error above. A run-owned subscription
			// left active is armed recurring billing, so its removal is
			// verified, not assumed.
			const cleanupFailures: string[] = [];
			if ( subscriptionId ) {
				try {
					await restApi.put(
						`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
						{ transition_status: 'cancelled' }
					);
					await restApi.delete(
						`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
						{ force: true }
					);
					await verifySubscriptionRemoved( restApi, subscriptionId );
				} catch ( error ) {
					cleanupFailures.push(
						`subscription cancel/delete: ${
							error instanceof Error ? error.message : error
						}`
					);
				}
			}
			if ( renewalOrderId ) {
				try {
					await restApi.delete(
						`${ ORDERS_ROUTE }/${ renewalOrderId }`,
						{ force: true }
					);
				} catch ( error ) {
					cleanupFailures.push(
						`renewal order delete: ${
							error instanceof Error ? error.message : error
						}`
					);
				}
			}
			if ( parentOrderId ) {
				try {
					await restApi.delete(
						`${ ORDERS_ROUTE }/${ parentOrderId }`,
						{ force: true }
					);
				} catch ( error ) {
					cleanupFailures.push(
						`parent order delete: ${
							error instanceof Error ? error.message : error
						}`
					);
				}
			}
			try {
				await restApi.delete( `${ PRODUCTS_ROUTE }/${ product.id }`, {
					force: true,
				} );
			} catch ( error ) {
				cleanupFailures.push(
					`product delete: ${
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
