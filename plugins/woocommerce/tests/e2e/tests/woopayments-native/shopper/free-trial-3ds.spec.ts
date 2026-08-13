import type { Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
	type SavedCardIdentity,
} from '../../../fixtures/woopayments-native';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
	readProviderCustomerId,
	submitClassicCardAuthentication,
	type PreparedClassicCardCheckout,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { PlaywrightClassicCardCheckoutBrowser } from '../../../utils/woopayments-native/drivers/classic-card-checkout';
import {
	withClassicCheckoutPage,
	type ClassicCheckoutTarget,
} from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	readShopperSessionCurrency,
	setShopperSessionCurrency,
} from '../../../utils/woopayments-native/drivers/redirect-methods';
import {
	deleteExactSavedCards,
	findSavedCardProviderCustomerId,
	getProviderPaymentMethodIds,
	getSavedCardEvidence,
	readSavedCardProviderCustomerId,
	type SavedCardToken,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import {
	createSubscriptionProduct,
	deleteSubscriptionProducts,
	emptyShopperCart,
	processMerchantRenewal,
	readCustomerSubscriptionIds,
	readOrderRecord,
	readPendingRenewalActions,
	readProviderSetupIntent,
	readStoreBaseline,
	readSubscriptionEvidence,
	removeRunOrders,
	removeRunSubscription,
	SUBSCRIPTION_GATEWAY,
	type StoreBaselineEvidence,
	type SubscriptionEvidence,
} from '../../../utils/woopayments-native/drivers/subscriptions';
import { getProviderEvidence } from '../../../utils/woopayments-native/provider-evidence';
import type { PaymentEvidence } from '../../../utils/woopayments-native/record-evidence';
import { ResourceQuarantineRequiredError } from '../../../utils/woopayments-native/resource-locks';
import { THREE_DS_OTP_CARD } from '../../../utils/woopayments-native/test-cards';

/**
 * Product-first counterpart to the current WooPayments client free-trial
 * journey. It keeps the client's shopper-visible product, cart and Classic
 * checkout contract, then joins the challenged SetupIntent to the exact token,
 * subscription, parent order and later merchant renewal at both the store and
 * provider layers.
 *
 * The client computes its expected renewal date when the module loads, across
 * local and UTC date constructors. This journey instead treats the store's
 * rendered cart date as authoritative and requires checkout to repeat the
 * exact value. The subscription record separately proves that its GMT trial
 * schedule is exactly 14 days, so a timezone or midnight boundary cannot make
 * a wrong duration pass.
 */

const CONTRACT =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-free-trial.spec.ts:77::Shopper: Subscriptions - Purchase Free Trial › Shopper should be able to purchase a free trial';

const CAPABILITIES = [
	'subscription-lifecycle',
	'subscription-lifecycle-product',
	'subscription-lifecycle-signup',
	'classic-checkout-page',
	'subscription-lifecycle-cleanup',
	'saved-card-cleanup',
	'subscription-lifecycle-renewal',
	'card-authentication',
];

const RECURRING_PRICE = '9.99';
const RECURRING_MINOR = 999;
const CURRENCY = 'USD';
const TRIAL_DAYS = 14;
const DAY_MS = 24 * 60 * 60 * 1000;
const PHASE_TIMEOUT_MS = 120_000;
const FREE_TRIAL_DISPLAY =
	/14[\s-]?days?.*free trial|free trial.*14[\s-]?days?/i;
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

interface RunBaseline {
	store: StoreBaselineEvidence;
	tokens: SavedCardToken[];
	providerCustomerId: string | undefined;
	providerAttachments: string[];
	subscriptionIds: number[];
	pendingRenewalActions: Awaited<
		ReturnType< typeof readPendingRenewalActions >
	>;
	highestOrderId: number;
	shopperCurrency: string;
}

interface RunScope {
	baseline: RunBaseline;
	classicCheckout: ClassicCheckoutTarget;
	productIds: number[];
}

function requireCapabilities( session: ProviderWriteSession ): void {
	for ( const capability of CAPABILITIES ) {
		session.requireApprovedProviderFixture( capability );
	}
}

function tokenIdentities(
	tokens: readonly SavedCardToken[]
): Array< [ number, string, boolean ] > {
	return tokens
		.map(
			( token ) =>
				[ token.tokenId, token.paymentMethodId, token.isDefault ] as [
					number,
					string,
					boolean
				]
		)
		.toSorted( ( left, right ) => left[ 0 ] - right[ 0 ] );
}

function actionIdentities(
	actions: RunBaseline[ 'pendingRenewalActions' ]
): Array< [ number, string, string, number, number ] > {
	return actions
		.map(
			( action ) =>
				[
					action.actionId,
					action.hook,
					action.status,
					action.subscriptionId,
					action.scheduledTimestamp,
				] as [ number, string, string, number, number ]
		)
		.toSorted( ( left, right ) => left[ 0 ] - right[ 0 ] );
}

function newCards(
	before: readonly SavedCardToken[],
	after: readonly SavedCardToken[]
): SavedCardIdentity[] {
	return after
		.filter(
			( token ) =>
				! before.some(
					( existing ) => existing.tokenId === token.tokenId
				)
		)
		.map( ( token ) => ( {
			tokenId: token.tokenId,
			paymentMethodId: token.paymentMethodId,
		} ) );
}

async function readBaseline(
	session: ProviderWriteSession,
	shopperCurrency: string
): Promise< RunBaseline > {
	const cards = await getSavedCardEvidence( session );
	const providerCustomerId = await findSavedCardProviderCustomerId( session );
	return {
		store: await readStoreBaseline( session ),
		tokens: cards.tokens,
		providerCustomerId,
		providerAttachments: providerCustomerId
			? await getProviderPaymentMethodIds( session, providerCustomerId )
			: [],
		subscriptionIds: await readCustomerSubscriptionIds( session ),
		pendingRenewalActions: await readPendingRenewalActions( session ),
		highestOrderId: await readHighestOrderId( session ),
		shopperCurrency,
	};
}

async function releaseRun(
	session: ProviderWriteSession,
	page: Page,
	scope: RunScope
): Promise< void > {
	const { baseline } = scope;
	const subscriptionIds = (
		await readCustomerSubscriptionIds( session )
	 ).filter( ( id ) => ! baseline.subscriptionIds.includes( id ) );

	for ( const subscriptionId of subscriptionIds.toReversed() ) {
		await removeRunSubscription( session, subscriptionId );
	}

	const orders = await readOrderDeltaAfter(
		session,
		baseline.highestOrderId
	);
	await removeRunOrders( session, orders.newOrderIds.toReversed() );

	const cards = await getSavedCardEvidence( session );
	const createdCards = newCards( baseline.tokens, cards.tokens );
	if ( createdCards.length > 0 ) {
		const cleanupProviderCustomerId =
			baseline.providerCustomerId ??
			( await findSavedCardProviderCustomerId( session ) );
		await deleteExactSavedCards(
			session,
			page,
			createdCards,
			cleanupProviderCustomerId
		);
	}

	await deleteSubscriptionProducts( session, scope.productIds.toReversed() );
	await emptyShopperCart( session, page );

	expect(
		await readStoreBaseline( session ),
		'the free-trial journey must leave gateway, currency and subscription settings byte-identical'
	).toEqual( baseline.store );
	expect(
		tokenIdentities( ( await getSavedCardEvidence( session ) ).tokens ),
		'the free-trial journey must restore the exact local token baseline'
	).toEqual( tokenIdentities( baseline.tokens ) );
	expect(
		await readCustomerSubscriptionIds( session ),
		'the free-trial journey must leave the exact subscription baseline'
	).toEqual( baseline.subscriptionIds );
	expect(
		actionIdentities( await readPendingRenewalActions( session ) ),
		'the free-trial journey must restore the exact pending-renewal baseline'
	).toEqual( actionIdentities( baseline.pendingRenewalActions ) );
	expect(
		( await readOrderDeltaAfter( session, baseline.highestOrderId ) )
			.newOrderIds,
		'the free-trial journey must remove every order it created'
	).toEqual( [] );

	const providerCustomerId =
		baseline.providerCustomerId ??
		( await findSavedCardProviderCustomerId( session ) );
	if ( providerCustomerId ) {
		expect(
			(
				await getProviderPaymentMethodIds( session, providerCustomerId )
			 ).toSorted(),
			'the free-trial journey must restore the exact provider attachment baseline'
		).toEqual( baseline.providerAttachments.toSorted() );
	}
}

async function withFreeTrialRun(
	session: ProviderWriteSession,
	page: Page,
	body: ( scope: RunScope ) => Promise< void >
): Promise< void > {
	await session.withProviderWriteLocks(
		{ recordEvent: 'shopper-subscription-free-trial-3ds' },
		async () => {
			const shopperCurrency = await readShopperSessionCurrency( page );
			const baseline = await readBaseline( session, shopperCurrency );
			let primaryError: unknown;

			try {
				expect(
					baseline.store.storeCurrency,
					'the run-owned 9.99 product requires the standing USD store currency'
				).toBe( CURRENCY );
				await emptyShopperCart( session, page );
				if (
					( await readShopperSessionCurrency( page ) ) !== CURRENCY
				) {
					await setShopperSessionCurrency( page, CURRENCY );
				}

				await withClassicCheckoutPage(
					session,
					session.runId,
					async ( checkoutScope ) => {
						const scope: RunScope = {
							baseline,
							classicCheckout: checkoutScope.classicCheckout,
							productIds: [],
						};
						let scenarioError: unknown;
						try {
							await body( scope );
						} catch ( error ) {
							scenarioError = error;
						}

						try {
							await releaseRun( session, page, scope );
						} catch ( cleanupError ) {
							throw new ResourceQuarantineRequiredError(
								'The free-trial authentication journey could not prove cleanup, so recurring billing may still be armed.',
								'cleanup-failed',
								scenarioError === undefined
									? cleanupError
									: new AggregateError(
											[ scenarioError, cleanupError ],
											'The free-trial journey and its cleanup both failed.',
											{ cause: cleanupError }
									  )
							);
						}

						if ( scenarioError !== undefined ) {
							throw scenarioError;
						}
					}
				);
			} catch ( error ) {
				primaryError = error;
			}

			try {
				if (
					( await readShopperSessionCurrency( page ) ) !==
					baseline.shopperCurrency
				) {
					await setShopperSessionCurrency(
						page,
						baseline.shopperCurrency
					);
				}
				expect(
					await readShopperSessionCurrency( page ),
					'the shopper session currency must be restored exactly'
				).toBe( baseline.shopperCurrency );
			} catch ( cleanupError ) {
				throw new ResourceQuarantineRequiredError(
					'The free-trial authentication journey could not restore the shopper session currency.',
					'cleanup-failed',
					primaryError === undefined
						? cleanupError
						: new AggregateError(
								[ primaryError, cleanupError ],
								'The free-trial journey and shopper-currency restoration both failed.',
								{ cause: cleanupError }
						  )
				);
			}

			if ( primaryError !== undefined ) {
				throw primaryError;
			}
		}
	);
}

async function waitForSingleSubscription(
	session: ProviderWriteSession,
	baselineIds: readonly number[]
): Promise< SubscriptionEvidence > {
	await expect
		.poll(
			async () =>
				(
					await readCustomerSubscriptionIds( session )
				 ).filter( ( id ) => ! baselineIds.includes( id ) ).length,
			{
				message: 'one signup must create exactly one subscription',
				timeout: PHASE_TIMEOUT_MS,
			}
		)
		.toBe( 1 );

	const ids = ( await readCustomerSubscriptionIds( session ) ).filter(
		( id ) => ! baselineIds.includes( id )
	);
	await expect
		.poll(
			async () => {
				const subscription = await readSubscriptionEvidence(
					session,
					ids[ 0 ]
				);
				return (
					subscription.status === 'active' &&
					subscription.activeTokenId > 0
				);
			},
			{
				message:
					'the free-trial signup must converge on one active tokenized subscription',
				timeout: PHASE_TIMEOUT_MS,
			}
		)
		.toBe( true );

	return readSubscriptionEvidence( session, ids[ 0 ] );
}

async function waitForRenewalPayment(
	session: ProviderWriteSession,
	orderId: number
): Promise< Omit< PaymentEvidence, 'runId' | 'orderKey' > > {
	let payment: Omit< PaymentEvidence, 'runId' | 'orderKey' > | undefined;
	await expect
		.poll(
			async () => {
				try {
					const order = await readOrderRecord( session, orderId );
					const intentId = order.meta._intent_id ?? '';
					const chargeId = order.meta._charge_id ?? '';
					const paymentMethodId = order.meta._payment_method_id ?? '';
					const amountMinor = Math.round(
						Number( order.total ) * 100
					);
					if (
						! /^pi_/.test( intentId ) ||
						! /^ch_/.test( chargeId ) ||
						! /^pm_/.test( paymentMethodId ) ||
						! Number.isSafeInteger( amountMinor )
					) {
						return '';
					}

					const provider = await getProviderEvidence(
						session.adminApi,
						{
							intentId,
							chargeId,
							paymentMethodId,
							amountMinor,
							currency: order.currency,
						}
					);
					payment = {
						orderId,
						intentId,
						chargeId,
						paymentMethodId,
						amountMinor,
						currency: order.currency,
						orderStatus: order.status,
						...provider,
					};

					return provider.providerStatus;
				} catch {
					return '';
				}
			},
			{
				message:
					'the renewal order must expose its provider PaymentIntent',
				timeout: PHASE_TIMEOUT_MS,
			}
		)
		.toBe( 'succeeded' );

	if ( ! payment ) {
		throw new Error( 'The renewal payment did not converge.' );
	}
	return payment;
}

test.describe( 'WooPayments native product-first free-trial authentication', () => {
	test.describe.configure( { mode: 'serial', timeout: 900_000 } );

	test(
		'a shopper sees a 14-day free trial on the product, cart, and classic checkout, sees the same timezone-safe first-renewal date and a zero initial total in cart and checkout, completes one required SetupIntent challenge, and one journaled USD 9.99 renewal succeeds on that exact reusable credential',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT },
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withFreeTrialRun( pilotRuntime, page, async ( scope ) => {
				const product = await createSubscriptionProduct( pilotRuntime, {
					slug: 'product-first-free-trial-3ds',
					price: RECURRING_PRICE,
					trialLength: TRIAL_DAYS,
					trialPeriod: 'day',
				} );
				scope.productIds.push( product.id );

				const browser = new PlaywrightClassicCardCheckoutBrowser(
					page,
					pilotRuntime.baseURL,
					scope.classicCheckout.pageId
				);
				await browser.preflightClassicPage(
					scope.classicCheckout.path,
					scope.classicCheckout.pageId
				);

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
				await pilotRuntime.performWrite( () => addToCart.click() );

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

				await browser.openClassicCheckout( scope.classicCheckout.path );
				await browser.fillBillingDetails( pilotRuntime.runId );
				const orderReview = page.locator( '#order_review' );
				await expect(
					orderReview.getByText( FREE_TRIAL_DISPLAY )
				).toBeVisible();
				const checkoutRenewal = orderReview.locator(
					'.first-payment-date'
				);
				await expect( checkoutRenewal ).toContainText(
					cartRenewalDate
				);
				await expect(
					orderReview.getByRole( 'row', {
						name: 'Total $0.00',
						exact: true,
					} )
				).toBeVisible();

				await browser.selectWooPaymentsCard();
				await browser.fillTestCard( THREE_DS_OTP_CARD );
				await browser.prepareSubmission();
				const prepared: PreparedClassicCardCheckout = {
					browser,
					runId: pilotRuntime.runId,
					orderTotal: '0.00',
					card: THREE_DS_OTP_CARD,
					savePaymentMethod: false,
				};
				const authentication = await submitClassicCardAuthentication(
					pilotRuntime,
					prepared,
					page,
					{
						response: 'complete',
						journal: 'subscription-free-trial-setup-challenge',
					}
				);

				expect( authentication.checkoutRequestCount ).toBe( 1 );
				expect( authentication.checkoutResponseCount ).toBe( 1 );
				expect( authentication.dispatch.intentType ).toBe( 'si' );
				expect( authentication.dispatch.intentId ).toMatch( /^seti_/ );
				expect( authentication.pendingIntent.id ).toBe(
					authentication.dispatch.intentId
				);
				expect( authentication.pendingIntent.status ).toBe(
					'requires_action'
				);
				expect( authentication.pendingIntent.nextActionType ).toBe(
					'use_stripe_sdk'
				);
				expect( authentication.pendingIntent.chargeCount ).toBe( 0 );
				expect( authentication.challenge ).toEqual( {
					expectation: 'challenge',
					authenticationSurfacePresented: true,
					challengePresented: true,
					response: 'complete',
					challengeDismissed: true,
				} );
				expect( authentication.settled ).toBe( true );
				expect( authentication.receipt?.orderId ).toBe(
					authentication.dispatch.orderId
				);

				const parent = await readOrderRecord(
					pilotRuntime,
					authentication.dispatch.orderId
				);
				expect( parent.total ).toBe( '0.00' );
				expect( parent.currency ).toBe( CURRENCY );
				expect( parent.paymentMethod ).toBe( SUBSCRIPTION_GATEWAY );
				expect( parent.meta._intent_id ).toBe(
					authentication.dispatch.intentId
				);
				expect( parent.meta._charge_id ).toBeUndefined();
				expect( parent.lineItems ).toHaveLength( 1 );
				expect( parent.lineItems[ 0 ].productId ).toBe( product.id );
				expect( Number( parent.lineItems[ 0 ].total ) ).toBe( 0 );

				const orderDelta = await readOrderDeltaAfter(
					pilotRuntime,
					scope.baseline.highestOrderId
				);
				expect(
					orderDelta.newOrderIds,
					'one challenged signup must create exactly one zero-total parent order'
				).toEqual( [ authentication.dispatch.orderId ] );

				await expect
					.poll(
						async () =>
							newCards(
								scope.baseline.tokens,
								(
									await getSavedCardEvidence( pilotRuntime )
								 ).tokens
							).length,
						{
							message:
								'the challenged SetupIntent must create exactly one Woo token',
							timeout: PHASE_TIMEOUT_MS,
						}
					)
					.toBe( 1 );
				const tokensAfterSignup = await getSavedCardEvidence(
					pilotRuntime
				);
				const createdCards = newCards(
					scope.baseline.tokens,
					tokensAfterSignup.tokens
				);
				const createdCard = createdCards[ 0 ];

				const subscription = await waitForSingleSubscription(
					pilotRuntime,
					scope.baseline.subscriptionIds
				);
				expect( subscription.parentId ).toBe( parent.id );
				expect( subscription.relatedOrders.parent ).toEqual( [
					parent.id,
				] );
				expect( subscription.relatedOrders.renewal ).toEqual( [] );
				expect( subscription.status ).toBe( 'active' );
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
					userId: subscription.customerId,
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

				const setupIntent = await readProviderSetupIntent(
					pilotRuntime,
					authentication.dispatch.intentId
				);
				expect( setupIntent.id ).toBe(
					authentication.dispatch.intentId
				);
				expect( setupIntent.status ).toBe( 'succeeded' );
				expect( setupIntent.usage ).toBe( 'off_session' );
				expect( setupIntent.paymentMethodId ).toBe(
					createdCard.paymentMethodId
				);
				expect( authentication.dispatch.paymentMethodId ).toBe(
					createdCard.paymentMethodId
				);
				expect( parent.meta._payment_method_id ).toBe(
					createdCard.paymentMethodId
				);

				const providerCustomerId =
					await readSavedCardProviderCustomerId( pilotRuntime );
				expect( setupIntent.customerId ).toBe( providerCustomerId );
				expect( providerCustomerId ).toBe(
					scope.baseline.providerCustomerId ?? providerCustomerId
				);
				const attachments = await getProviderPaymentMethodIds(
					pilotRuntime,
					providerCustomerId
				);
				expect(
					attachments.filter(
						( id ) => id === createdCard.paymentMethodId
					)
				).toHaveLength( 1 );
				expect( attachments.toSorted() ).toEqual(
					[
						...scope.baseline.providerAttachments,
						createdCard.paymentMethodId,
					].toSorted()
				);

				await processMerchantRenewal(
					pilotRuntime,
					page,
					subscription.id,
					'subscription-free-trial-merchant-renewal'
				);
				await expect
					.poll(
						async () => {
							const current = await readSubscriptionEvidence(
								pilotRuntime,
								subscription.id
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
					pilotRuntime,
					subscription.id
				);
				const renewalOrderId = renewed.relatedOrders.renewal[ 0 ];
				expect( renewed.paymentCount ).toBe(
					subscription.paymentCount + 1
				);
				expect( renewed.paymentTokenIds ).toEqual(
					subscription.paymentTokenIds
				);
				expect(
					await readCustomerSubscriptionIds( pilotRuntime )
				).toEqual( [
					...scope.baseline.subscriptionIds,
					subscription.id,
				] );
				expect(
					tokenIdentities(
						( await getSavedCardEvidence( pilotRuntime ) ).tokens
					),
					'the renewal must create no duplicate Woo token'
				).toEqual( tokenIdentities( tokensAfterSignup.tokens ) );

				const finalOrders = await readOrderDeltaAfter(
					pilotRuntime,
					scope.baseline.highestOrderId
				);
				expect(
					finalOrders.newOrderIds,
					'the complete journey must create one parent and one renewal order only'
				).toEqual(
					[ parent.id, renewalOrderId ].toSorted(
						( left, right ) => left - right
					)
				);

				const renewal = await readOrderRecord(
					pilotRuntime,
					renewalOrderId
				);
				expect( renewal.currency ).toBe( CURRENCY );
				expect( renewal.paymentMethod ).toBe( SUBSCRIPTION_GATEWAY );
				expect( renewal.meta._subscription_renewal ).toBe(
					String( subscription.id )
				);
				expect( Math.round( Number( renewal.total ) * 100 ) ).toBe(
					RECURRING_MINOR
				);
				expect( renewal.lineItems ).toHaveLength( 1 );
				expect( renewal.lineItems[ 0 ].productId ).toBe( product.id );

				const payment = await waitForRenewalPayment(
					pilotRuntime,
					renewalOrderId
				);
				expect( payment.orderId ).toBe( renewalOrderId );
				expect( payment.intentId ).toMatch( /^pi_/ );
				expect( payment.amountMinor ).toBe( RECURRING_MINOR );
				expect( payment.currency ).toBe( CURRENCY );
				expect( payment.paymentMethodId ).toBe(
					createdCard.paymentMethodId
				);
				expect( payment.providerStatus ).toBe( 'succeeded' );
				expect( payment.chargeStatus ).toBe( 'succeeded' );
				expect( payment.chargeCaptured ).toBe( true );
				expect( payment.occurrenceCount ).toBe( 1 );
				expect( payment.captureOccurrenceCount ).toBe( 1 );
				expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
				expect(
					await readProviderCustomerId(
						pilotRuntime,
						payment.intentId
					)
				).toBe( providerCustomerId );
			} );
		}
	);
} );
