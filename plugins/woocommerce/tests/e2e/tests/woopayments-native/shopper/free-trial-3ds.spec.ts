import type { Page, Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { getFakeUser } from '../../../utils/data';
import { admin } from '../../../test-data/data';
import { random } from '../../../utils/helpers';
import { logIn } from '../../../utils/login';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	completeThreeDSChallenge,
	expectSettledCardPayment,
	fillCardDetails,
	getPaymentIntent,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * Product-first free-trial journey (T.4 Batch P4b rewrite): the client's
 * shopper-visible product, cart and Classic checkout contract, joined to the
 * challenged SetupIntent, the exact token, subscription and parent order, and
 * a later merchant renewal, at both the store and provider layers.
 *
 * The client computes its expected renewal date at module load, across local
 * and UTC date constructors. This journey instead treats the store's own
 * rendered cart date as authoritative and requires checkout to repeat it
 * exactly, and separately proves the subscription's GMT trial schedule is
 * exactly 14 days, so a timezone or midnight boundary cannot make a wrong
 * duration pass.
 *
 * D5 preflight note: the currency-session force/restore the old harness drove
 * around this case (`wcpay_currency` residue) is a T.4 D5 gate-level
 * preflight now, not spec code; the run assumes the store's own USD baseline
 * and asserts it once instead of forcing it.
 */

const CONTRACT =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-free-trial.spec.ts:77::Shopper: Subscriptions - Purchase Free Trial › Shopper should be able to purchase a free trial';

const FAMILY_TAGS = [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ];

const RECURRING_PRICE = '9.99';
const RECURRING_MINOR = 999;
const CURRENCY = 'USD';
const TRIAL_DAYS = 14;
const DAY_MS = 24 * 60 * 60 * 1000;
const PHASE_TIMEOUT_MS = 120_000;
const FREE_TRIAL_DISPLAY =
	/14[\s-]?days?.*free trial|free trial.*14[\s-]?days?/i;
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

const CUSTOMERS_ROUTE = 'wc/v3/customers';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const SUBSCRIPTIONS_ROUTE = 'wc/v3/subscriptions';
const SAVED_CARD_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/saved-card-evidence';
const SUBSCRIPTION_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/subscription-evidence';
const SUBSCRIPTION_GATEWAY = 'woocommerce_payments';
const CONFIRMATION_HASH_PATTERN =
	/^#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/;

interface SavedToken {
	tokenId: number;
	paymentMethodId: string;
}

async function getSavedCardEvidence(
	restApi: ApiClient,
	customerUsername: string
): Promise< { tokens: SavedToken[]; providerCustomerId: string } > {
	const data = (
		await restApi.get( SAVED_CARD_EVIDENCE_ROUTE, {
			customer_username: customerUsername,
		} )
	).data as {
		tokens?: Array< { token_id: number; payment_method_id: string } >;
		provider_customer_id?: string;
	};
	return {
		tokens: ( data.tokens ?? [] ).map( ( token ) => ( {
			tokenId: token.token_id,
			paymentMethodId: token.payment_method_id,
		} ) ),
		providerCustomerId: data.provider_customer_id ?? '',
	};
}

/** Reads the provider payment-method ids currently attached to a provider customer. */
async function getProviderPaymentMethodIds(
	restApi: ApiClient,
	providerCustomerId: string
): Promise< string[] > {
	const methods = (
		await restApi.get(
			`wc/v3/payments/customers/${ encodeURIComponent(
				providerCustomerId
			) }/payment_methods`
		)
	).data as Array< { id: string } >;
	return methods.map( ( method ) => method.id );
}

async function readCustomerSubscriptionIds(
	restApi: ApiClient,
	customerUsername: string
): Promise< number[] > {
	const payload = (
		await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
			customer_username: customerUsername,
		} )
	).data as { customer_subscription_ids?: number[] };
	return payload.customer_subscription_ids ?? [];
}

interface SubscriptionTokenEvidence {
	tokenId: number;
	exists: boolean;
	paymentMethodId: string;
	gatewayId: string;
	userId: number;
	isDefault: boolean;
}

interface SubscriptionEvidence {
	id: number;
	status: string;
	parentId: number;
	currency: string;
	total: string;
	paymentMethod: string;
	requiresManualRenewal: boolean;
	paymentCount: number;
	startGmt: string;
	trialEndGmt: string;
	trialEndDisplay: string;
	nextPaymentGmt: string;
	paymentTokenIds: number[];
	activeTokenId: number;
	paymentTokens: SubscriptionTokenEvidence[];
	relatedOrders: { parent: number[]; renewal: number[] };
}

async function readSubscriptionEvidence(
	restApi: ApiClient,
	subscriptionId: number
): Promise< SubscriptionEvidence > {
	const payload = (
		await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
			subscription_id: String( subscriptionId ),
		} )
	).data as { subscription?: Record< string, unknown > };
	const record = payload.subscription ?? {};
	const related =
		( record.related_orders as Record< string, unknown > ) ?? {};
	return {
		id: Number( record.id ),
		status: String( record.status ?? '' ),
		parentId: Number( record.parent_id ?? 0 ),
		currency: String( record.currency ?? '' ),
		total: String( record.total ?? '' ),
		paymentMethod: String( record.payment_method ?? '' ),
		requiresManualRenewal: record.requires_manual_renewal === true,
		paymentCount: Number( record.payment_count ?? 0 ),
		startGmt: String( record.start_gmt ?? '' ),
		trialEndGmt: String( record.trial_end_gmt ?? '' ),
		trialEndDisplay: String( record.trial_end_display ?? '' ),
		nextPaymentGmt: String( record.next_payment_gmt ?? '' ),
		paymentTokenIds: ( ( record.payment_token_ids as number[] ) ?? [] ).map(
			Number
		),
		activeTokenId: Number( record.active_token_id ?? 0 ),
		paymentTokens: (
			( record.payment_tokens as Array< Record< string, unknown > > ) ??
			[]
		).map( ( token ) => ( {
			tokenId: Number( token.token_id ),
			exists: token.exists === true,
			paymentMethodId: String( token.payment_method_id ?? '' ),
			gatewayId: String( token.gateway_id ?? '' ),
			userId: Number( token.user_id ?? 0 ),
			isDefault: token.is_default === true,
		} ) ),
		relatedOrders: {
			parent: ( ( related.parent as number[] ) ?? [] ).map( Number ),
			renewal: ( ( related.renewal as number[] ) ?? [] ).map( Number ),
		},
	};
}

interface SetupIntentEvidence {
	id: string;
	status: string;
	usage: string;
	paymentMethodId: string;
	customerId: string;
	nextActionType: string;
}

async function readProviderSetupIntent(
	restApi: ApiClient,
	setupIntentId: string
): Promise< SetupIntentEvidence > {
	const payload = (
		await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
			setup_intent_id: setupIntentId,
		} )
	).data as { setup_intent?: Record< string, unknown > };
	const intent = payload.setup_intent ?? {};
	return {
		id: String( intent.id ?? '' ),
		status: String( intent.status ?? '' ),
		usage: String( intent.usage ?? '' ),
		paymentMethodId: String( intent.payment_method_id ?? '' ),
		customerId: String( intent.customer_id ?? '' ),
		nextActionType: String( intent.next_action_type ?? '' ),
	};
}

/** Whether `error` is the provider's transient "another request holds this object" answer. */
function isLockTimeout( error: unknown ): boolean {
	const status =
		typeof error === 'object' && error !== null && 'response' in error
			? ( error as { response?: { status?: unknown } } ).response?.status
			: undefined;
	return status === 429;
}

async function pollProviderSetupIntent(
	restApi: ApiClient,
	setupIntentId: string,
	expectedStatus: string,
	timeoutMs = PHASE_TIMEOUT_MS
): Promise< SetupIntentEvidence > {
	const deadline = Date.now() + timeoutMs;
	for (;;) {
		try {
			const intent = await readProviderSetupIntent(
				restApi,
				setupIntentId
			);
			if ( intent.status === expectedStatus || Date.now() >= deadline ) {
				return intent;
			}
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
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
		.map( ( order ) => order.id );
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const value = entries.find( ( entry ) => entry.key === key )?.value;
	return typeof value === 'string' ? value : '';
}

function isClassicCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		return (
			new URL( request.url() ).searchParams.get( 'wc-ajax' ) ===
			'checkout'
		);
	} catch {
		return false;
	}
}

function parseConfirmationHash(
	redirect: string,
	orderId: number
): { intentType: 'pi' | 'si'; intentId: string } {
	const match = redirect.match( CONFIRMATION_HASH_PATTERN );
	if ( ! match ) {
		throw new Error(
			`Checkout response for order ${ orderId } carries no customer-action confirmation hash: ${ redirect }`
		);
	}
	const [ , intentType, hashOrderId, clientSecret ] = match;
	if ( Number( hashOrderId ) !== orderId ) {
		throw new Error(
			`Confirmation hash names order ${ hashOrderId }, not the dispatched order ${ orderId }.`
		);
	}
	const intentId = clientSecret.split( '_secret_' )[ 0 ];
	if ( ! intentId || intentId === clientSecret ) {
		throw new Error(
			'Confirmation hash carries no readable intent identity.'
		);
	}
	return { intentType: intentType as 'pi' | 'si', intentId };
}

async function fillClassicBilling(
	page: Page,
	email: string
): Promise< void > {
	await page.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await page
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await page
		.getByRole( 'textbox', { name: 'Street address' } )
		.fill( '123 Test Street' );
	await page
		.getByRole( 'textbox', { name: 'Town / City' } )
		.fill( 'San Francisco' );
	await page.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94107' );
	await page.getByRole( 'textbox', { name: 'Phone' } ).fill( '5555550100' );
	await page.getByRole( 'textbox', { name: 'Email address' } ).fill( email );
	await page
		.locator(
			`input[name="payment_method"][value="${ SUBSCRIPTION_GATEWAY }"]`
		)
		.check();
}

interface FreeTrialChallengeDispatch {
	orderId: number;
	intentType: 'pi' | 'si';
	intentId: string;
	/**
	 * Detaches the request/response listeners and reports how many checkout
	 * exchanges happened over the whole submission interval. Call once the
	 * interval has closed - at the receipt - so a second submission fired
	 * while the challenge is open is still counted (R1/R5 shape).
	 */
	stop: () => { requestCount: number; responseCount: number };
}

async function submitClassicChallengeCheckout(
	page: Page
): Promise< FreeTrialChallengeDispatch > {
	let requestCount = 0;
	let responseCount = 0;
	let stopped = false;
	const onRequest = ( request: Request ): void => {
		if ( isClassicCheckoutRequest( request ) ) {
			requestCount += 1;
		}
	};
	const onResponse = ( response: Response ): void => {
		if ( isClassicCheckoutRequest( response.request() ) ) {
			responseCount += 1;
		}
	};
	page.on( 'request', onRequest );
	page.on( 'response', onResponse );
	const stop = (): { requestCount: number; responseCount: number } => {
		if ( ! stopped ) {
			stopped = true;
			page.off( 'request', onRequest );
			page.off( 'response', onResponse );
		}
		return { requestCount, responseCount };
	};

	try {
		const responsePromise = page.waitForResponse(
			( candidate ) => isClassicCheckoutRequest( candidate.request() ),
			{ timeout: 60_000 }
		);
		await page.getByRole( 'button', { name: /place order/i } ).click();
		const response = await responsePromise;
		const body = ( await response.json() ) as {
			result?: unknown;
			order_id?: unknown;
			redirect?: unknown;
		};
		if ( body.result !== 'success' ) {
			throw new Error(
				`Classic checkout did not accept the free-trial submission: ${ JSON.stringify(
					body
				) }`
			);
		}
		const orderId = Number( body.order_id );
		const { intentType, intentId } = parseConfirmationHash(
			String( body.redirect ?? '' ),
			orderId
		);
		return { orderId, intentType, intentId, stop };
	} catch ( error ) {
		stop();
		throw error;
	}
}

/** Proves the receipt's `key` query param names the exact settled order. */
async function expectReceiptOrderKey(
	restApi: ApiClient,
	page: Page,
	orderId: number
): Promise< void > {
	const key = new URL( page.url() ).searchParams.get( 'key' );
	expect( key, 'the receipt must carry an order key' ).toBeTruthy();
	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as Record< string, unknown >;
	expect( key ).toBe( order.order_key );
}

/**
 * Runs the merchant's own "Process renewal" order action on one subscription,
 * which dispatches `woocommerce_scheduled_subscription_payment` directly
 * rather than through Action Scheduler - WooCommerce Subscriptions never
 * schedules a past-dated action, so a real cron-driven renewal is not what
 * this case drives.
 */
async function processMerchantRenewal(
	page: Page,
	subscriptionId: number
): Promise< void > {
	await page.goto( 'wp-login.php' );
	await logIn( page, admin.username, admin.password );
	await page.goto(
		`wp-admin/admin.php?page=wc-orders--shop_subscription&action=edit&id=${ subscriptionId }`
	);

	const orderActionsBox = page.locator( '#woocommerce-order-actions' );
	const actions = orderActionsBox.locator( 'select[name="wc_order_action"]' );
	await expect( actions ).toHaveCount( 1 );
	await expect(
		actions.locator( 'option[value="wcs_process_renewal"]' )
	).toHaveCount( 1 );
	await actions.selectOption( 'wcs_process_renewal' );

	const apply = orderActionsBox.getByRole( 'button', {
		name: 'Update',
		exact: true,
	} );
	await expect( apply ).toHaveCount( 1 );

	// The click dispatches a synchronous `window.confirm()`, which blocks the
	// page until it is answered. Awaiting the click before the dialog is
	// consumed deadlocks: the click's own actionability wait never resolves
	// while the dialog is up, and nothing dismisses the dialog until the
	// click has returned. Racing them with `Promise.all` lets the dialog
	// handler answer it while the click is still in flight. The handler
	// answers the dialog *before* asserting anything about it: an `expect`
	// that throws first would leave the dialog open until the 900s test
	// timeout instead of failing promptly.
	const confirmation = page
		.waitForEvent( 'dialog', { timeout: 60_000 } )
		.then( async ( dialog ) => {
			const type = dialog.type();
			const message = dialog.message();
			await dialog.accept();
			expect( type ).toBe( 'confirm' );
			expect( message ).toMatch( /process a renewal/i );
		} );
	await Promise.all( [ apply.click(), confirmation ] );
	await page.waitForLoadState( 'domcontentloaded' );
}

test.describe( 'WooPayments native product-first free-trial authentication', () => {
	test.describe.configure( { mode: 'serial', timeout: 900_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		'a shopper sees a 14-day free trial on the product, cart, and classic checkout, sees the same timezone-safe first-renewal date and a zero initial total in cart and checkout, completes one required SetupIntent challenge, and one journaled USD 9.99 renewal succeeds on that exact reusable credential',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const storeCurrency = (
				await restApi.get(
					'wc/v3/settings/general/woocommerce_currency'
				)
			).data as { value?: unknown };
			expect(
				storeCurrency.value,
				'the run-owned 9.99 product requires the standing USD store currency'
			).toBe( CURRENCY );

			const customer = getFakeUser( 'customer' );
			const createdCustomer = (
				await restApi.post( CUSTOMERS_ROUTE, customer )
			).data as { id: number };

			let productId: number | undefined;
			let subscriptionId: number | undefined;
			let parentOrderId: number | undefined;
			let renewalOrderId: number | undefined;
			let caseError: unknown;
			const baselineSubscriptionIds = await readCustomerSubscriptionIds(
				restApi,
				customer.username
			);

			try {
				await page.goto( 'wp-login.php' );
				await logIn(
					page,
					customer.username,
					customer.password,
					false
				);
				await page.goto( 'my-account/' );
				await expect(
					page.getByText(
						new RegExp( `Hello ${ customer.first_name }` )
					)
				).toBeVisible();

				const highestOrderId = await readHighestOrderId( restApi );
				const before = await getSavedCardEvidence(
					restApi,
					customer.username
				);
				const knownTokenIds = new Set(
					before.tokens.map( ( token ) => token.tokenId )
				);

				const product = (
					await restApi.post( PRODUCTS_ROUTE, {
						name: `WooPayments free-trial 3ds ${ random() }`,
						type: 'subscription',
						virtual: true,
						regular_price: RECURRING_PRICE,
						status: 'publish',
						meta_data: [
							{
								key: '_subscription_price',
								value: RECURRING_PRICE,
							},
							{ key: '_subscription_period', value: 'month' },
							{
								key: '_subscription_period_interval',
								value: '1',
							},
							{ key: '_subscription_length', value: '0' },
							{ key: '_subscription_sign_up_fee', value: '0' },
							{
								key: '_subscription_trial_length',
								value: String( TRIAL_DAYS ),
							},
							{
								key: '_subscription_trial_period',
								value: 'day',
							},
						],
					} )
				).data as { id: number; name: string; type: string };
				productId = product.id;
				expect( product.type ).toBe( 'subscription' );

				await page.goto( `?post_type=product&p=${ product.id }` );
				await expect(
					page.getByRole( 'heading', {
						name: product.name,
						exact: true,
					} )
				).toBeVisible();
				await expect(
					page.locator( '.product' ).getByText( FREE_TRIAL_DISPLAY )
				).toBeVisible();
				const addToCart = page.getByRole( 'button', {
					name: 'Add to cart',
					exact: true,
				} );
				await expect( addToCart ).toBeEnabled();
				await addToCart.click();

				await page.goto( 'cart/' );
				await expect(
					page.getByText( FREE_TRIAL_DISPLAY ).first()
				).toBeVisible();
				const cartRenewal = page.getByText( /^Starting:\s+\S/ ).first();
				await expect( cartRenewal ).toBeVisible();
				const cartRenewalText = ( await cartRenewal.innerText() )
					.replace( /\s+/g, ' ' )
					.trim();
				const cartRenewalDate = cartRenewalText.replace(
					/^Starting:\s+/,
					''
				);
				expect( cartRenewalDate ).not.toBe( cartRenewalText );
				await expect(
					page.getByText( /Total due today\s*\$0\.00/ ).first()
				).toBeVisible();

				await page.goto( 'classic-checkout/' );
				await fillClassicBilling(
					page,
					`woopayments-${ random() }@example.com`
				);
				const orderReview = page.locator( '#order_review' );
				await expect(
					orderReview.getByText( FREE_TRIAL_DISPLAY )
				).toBeVisible();
				await expect(
					orderReview.locator( '.first-payment-date' )
				).toContainText( cartRenewalDate );
				// The store renders the order-review total with a trailing
				// currency code ("Total $0.00 USD"); allow that suffix but
				// pin everything else, so a non-zero total (`$9.99`/`$10.00`)
				// still fails.
				await expect(
					orderReview.getByRole( 'row', {
						name: /^Total\s+\$0\.00(?:\s+USD)?$/,
					} )
				).toBeVisible();

				await fillCardDetails( page, TEST_CARDS.threeDSOtp, 'classic' );

				const dispatch = await submitClassicChallengeCheckout( page );
				expect( dispatch.intentType ).toBe( 'si' );
				expect( dispatch.intentId ).toMatch( /^seti_/ );
				parentOrderId = dispatch.orderId;

				const pendingSetupIntent = await readProviderSetupIntent(
					restApi,
					dispatch.intentId
				);
				expect( pendingSetupIntent.id ).toBe( dispatch.intentId );
				expect( pendingSetupIntent.status ).toBe( 'requires_action' );
				expect( pendingSetupIntent.nextActionType ).toBe(
					'use_stripe_sdk'
				);

				await completeThreeDSChallenge( page, 'complete' );
				await page.waitForURL( /\/order-received\/[1-9]\d*/, {
					timeout: 60_000,
				} );
				await expect(
					page.getByRole( 'heading', { name: 'Order received' } )
				).toBeVisible();
				await expectReceiptOrderKey( restApi, page, dispatch.orderId );
				const { requestCount, responseCount } = dispatch.stop();
				expect(
					requestCount,
					'one Place order activation must ask the store exactly once through the whole interval, challenge included'
				).toBe( 1 );
				expect( responseCount ).toBe( 1 );

				const parent = (
					await restApi.get(
						`${ ORDERS_ROUTE }/${ dispatch.orderId }`
					)
				).data as Record< string, unknown >;
				expect( parent.total ).toBe( '0.00' );
				expect( parent.currency ).toBe( CURRENCY );
				expect( parent.payment_method ).toBe( SUBSCRIPTION_GATEWAY );
				expect( orderMeta( parent, '_intent_id' ) ).toBe(
					dispatch.intentId
				);
				expect( orderMeta( parent, '_charge_id' ) ).toBe( '' );
				const parentLineItems = parent.line_items as Array< {
					product_id: number;
					total: string;
				} >;
				expect( parentLineItems ).toHaveLength( 1 );
				expect( parentLineItems[ 0 ].product_id ).toBe( product.id );
				expect( Number( parentLineItems[ 0 ].total ) ).toBe( 0 );

				expect(
					await readNewOrderIds( restApi, highestOrderId ),
					'one challenged signup must create exactly one zero-total parent order'
				).toEqual( [ dispatch.orderId ] );

				await expect
					.poll(
						async () => {
							const after = await getSavedCardEvidence(
								restApi,
								customer.username
							);
							return after.tokens.filter(
								( token ) =>
									! knownTokenIds.has( token.tokenId )
							).length;
						},
						{
							message:
								'the challenged SetupIntent must create exactly one Woo token',
							timeout: PHASE_TIMEOUT_MS,
						}
					)
					.toBe( 1 );
				const tokensAfterSignup = await getSavedCardEvidence(
					restApi,
					customer.username
				);
				const createdCard = tokensAfterSignup.tokens.find(
					( token ) => ! knownTokenIds.has( token.tokenId )
				) as SavedToken;

				await expect
					.poll(
						async () =>
							(
								await readCustomerSubscriptionIds(
									restApi,
									customer.username
								)
							).filter(
								( id ) =>
									! baselineSubscriptionIds.includes( id )
							).length,
						{
							message:
								'one signup must create exactly one subscription',
							timeout: PHASE_TIMEOUT_MS,
						}
					)
					.toBe( 1 );
				subscriptionId = (
					await readCustomerSubscriptionIds(
						restApi,
						customer.username
					)
				).find( ( id ) => ! baselineSubscriptionIds.includes( id ) );
				if ( subscriptionId === undefined ) {
					throw new Error(
						'The free-trial signup subscription did not converge.'
					);
				}

				await expect
					.poll(
						async () => {
							const evidence = await readSubscriptionEvidence(
								restApi,
								subscriptionId as number
							);
							return (
								evidence.status === 'active' &&
								evidence.activeTokenId > 0
							);
						},
						{
							message:
								'the free-trial signup must converge on one active tokenized subscription',
							timeout: PHASE_TIMEOUT_MS,
						}
					)
					.toBe( true );
				const subscription = await readSubscriptionEvidence(
					restApi,
					subscriptionId
				);
				expect( subscription.parentId ).toBe( parent.id );
				expect( subscription.relatedOrders.parent ).toEqual( [
					parent.id,
				] );
				expect( subscription.relatedOrders.renewal ).toEqual( [] );
				expect( subscription.currency ).toBe( CURRENCY );
				expect( subscription.paymentMethod ).toBe(
					SUBSCRIPTION_GATEWAY
				);
				expect( subscription.requiresManualRenewal ).toBe( false );
				expect( subscription.paymentCount ).toBe( 1 );
				expect( Math.round( Number( subscription.total ) * 100 ) ).toBe(
					RECURRING_MINOR
				);
				expect( subscription.paymentTokenIds ).toEqual( [
					createdCard.tokenId,
				] );
				expect( subscription.activeTokenId ).toBe(
					createdCard.tokenId
				);
				expect( subscription.paymentTokens ).toHaveLength( 1 );
				expect( subscription.paymentTokens[ 0 ] ).toMatchObject( {
					tokenId: createdCard.tokenId,
					paymentMethodId: createdCard.paymentMethodId,
					gatewayId: SUBSCRIPTION_GATEWAY,
					userId: createdCustomer.id,
					exists: true,
				} );
				expect( subscription.nextPaymentGmt ).toBe(
					subscription.trialEndGmt
				);
				expect( subscription.trialEndDisplay ).toBe( cartRenewalDate );
				const startTime = Date.parse(
					`${ subscription.startGmt.replace( ' ', 'T' ) }Z`
				);
				const trialEndTime = Date.parse(
					`${ subscription.trialEndGmt.replace( ' ', 'T' ) }Z`
				);
				expect( Number.isFinite( startTime ) ).toBe( true );
				expect( Number.isFinite( trialEndTime ) ).toBe( true );
				expect( trialEndTime - startTime ).toBe( TRIAL_DAYS * DAY_MS );

				const setupIntent = await pollProviderSetupIntent(
					restApi,
					dispatch.intentId,
					'succeeded'
				);
				expect( setupIntent.paymentMethodId ).toBe(
					createdCard.paymentMethodId
				);
				expect( setupIntent.usage ).toBe( 'off_session' );
				expect( orderMeta( parent, '_payment_method_id' ) ).toBe(
					createdCard.paymentMethodId
				);

				const providerCustomerId = setupIntent.customerId;
				const storeProviderCustomer = await getSavedCardEvidence(
					restApi,
					customer.username
				);
				expect(
					storeProviderCustomer.providerCustomerId,
					"the succeeded SetupIntent must name the shopper's own provider customer"
				).toBe( providerCustomerId );
				expect(
					await getProviderPaymentMethodIds(
						restApi,
						providerCustomerId
					),
					'a fresh customer must attach exactly the one signup method, no more'
				).toEqual( [ createdCard.paymentMethodId ] );

				await processMerchantRenewal( page, subscriptionId );
				await expect
					.poll(
						async () => {
							const current = await readSubscriptionEvidence(
								restApi,
								subscriptionId as number
							);
							return {
								renewalOrders:
									current.relatedOrders.renewal.length,
								paymentCount: current.paymentCount,
							};
						},
						{
							message:
								'one merchant gesture must create exactly one completed renewal',
							timeout: PHASE_TIMEOUT_MS,
						}
					)
					.toEqual( {
						renewalOrders: 1,
						paymentCount: subscription.paymentCount + 1,
					} );

				const renewed = await readSubscriptionEvidence(
					restApi,
					subscriptionId
				);
				renewalOrderId = renewed.relatedOrders.renewal[ 0 ];
				expect( renewed.paymentCount ).toBe(
					subscription.paymentCount + 1
				);
				expect( renewed.paymentTokenIds ).toEqual(
					subscription.paymentTokenIds
				);
				expect(
					await readCustomerSubscriptionIds(
						restApi,
						customer.username
					)
				).toEqual( [ ...baselineSubscriptionIds, subscriptionId ] );
				expect(
					( await getSavedCardEvidence( restApi, customer.username ) )
						.tokens,
					'the renewal must create no duplicate Woo token'
				).toEqual( tokensAfterSignup.tokens );

				expect(
					(
						await readNewOrderIds( restApi, highestOrderId )
					).toSorted( ( left, right ) => left - right ),
					'the complete journey must create one parent and one renewal order only'
				).toEqual(
					[ parent.id as number, renewalOrderId ].toSorted(
						( left, right ) => left - right
					)
				);

				const renewal = (
					await restApi.get( `${ ORDERS_ROUTE }/${ renewalOrderId }` )
				).data as Record< string, unknown >;
				expect( renewal.currency ).toBe( CURRENCY );
				expect( renewal.payment_method ).toBe( SUBSCRIPTION_GATEWAY );
				expect( orderMeta( renewal, '_subscription_renewal' ) ).toBe(
					String( subscriptionId )
				);
				expect( Math.round( Number( renewal.total ) * 100 ) ).toBe(
					RECURRING_MINOR
				);
				const renewalLineItems = renewal.line_items as Array< {
					product_id: number;
				} >;
				expect( renewalLineItems ).toHaveLength( 1 );
				expect( renewalLineItems[ 0 ].product_id ).toBe( product.id );

				const payment = await expectSettledCardPayment(
					restApi,
					renewalOrderId,
					{ amountMinor: RECURRING_MINOR, currency: CURRENCY }
				);
				expect( payment.paymentMethodId ).toBe(
					createdCard.paymentMethodId
				);
				expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
				const renewalIntent = await getPaymentIntent(
					restApi,
					payment.intentId
				);
				expect( String( renewalIntent.customer ?? '' ) ).toBe(
					providerCustomerId
				);
			} catch ( error ) {
				caseError = error;
			} finally {
				// Each step runs independently so one failure cannot mask
				// another or the case's own error (P3 `provider-fidelity-subscriptions`
				// pattern): a cleanup failure only replaces `caseError` when
				// the case itself passed, and every step still runs even
				// after an earlier one fails.
				const noteCleanupFailure = ( error: unknown ): void => {
					if ( caseError === undefined ) {
						caseError = error;
					} else {
						console.error(
							'WooPayments free-trial cleanup failed after the primary case failure:',
							error
						);
					}
				};

				if ( subscriptionId !== undefined ) {
					try {
						await restApi.put(
							`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
							{ transition_status: 'cancelled' }
						);
					} catch ( error ) {
						noteCleanupFailure( error );
					}
					try {
						await restApi.delete(
							`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
							{ force: true }
						);
					} catch ( error ) {
						noteCleanupFailure( error );
					}
				}
				for ( const orderId of [ renewalOrderId, parentOrderId ] ) {
					if ( orderId === undefined ) {
						continue;
					}
					try {
						await restApi.delete(
							`${ ORDERS_ROUTE }/${ orderId }`,
							{
								force: true,
							}
						);
					} catch ( error ) {
						noteCleanupFailure( error );
					}
				}
				if ( productId !== undefined ) {
					try {
						await restApi.delete(
							`${ PRODUCTS_ROUTE }/${ productId }`,
							{ force: true }
						);
					} catch ( error ) {
						noteCleanupFailure( error );
					}
				}

				// Cleanup is proved, not assumed: the cancelled/deleted
				// subscription must actually be gone from the shopper's list.
				// A failure here must not skip the customer delete below -
				// that would leave a live shopper whose card is attached and
				// whose subscription is armed.
				try {
					expect(
						await readCustomerSubscriptionIds(
							restApi,
							customer.username
						),
						'the free-trial journey must leave no subscription on the run-owned shopper'
					).toEqual( baselineSubscriptionIds );
				} catch ( error ) {
					noteCleanupFailure( error );
				}

				// Deleting the customer removes its saved tokens locally and
				// detaches them at the provider (T.4 D5), so this leaves no
				// live credential even when an earlier cleanup step failed.
				// Always runs, whatever the checks above concluded.
				try {
					await restApi.delete(
						`${ CUSTOMERS_ROUTE }/${ createdCustomer.id }`,
						{ force: true }
					);
				} catch ( error ) {
					noteCleanupFailure( error );
				}
			}
			if ( caseError !== undefined ) {
				throw caseError;
			}
		}
	);
} );
