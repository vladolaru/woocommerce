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
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import {
	withClassicCheckoutPage,
	type ClassicCheckoutTarget,
} from '../../../utils/woopayments-native/drivers/classic-checkout-page';
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
	delay,
	deleteSubscriptionProducts,
	emptyShopperCart,
	processMerchantRenewal,
	readCustomerSubscriptionIds,
	readOrderRecord,
	readPendingRenewalActions,
	readStoreBaseline,
	readSubscriptionEvidence,
	removeRunOrders,
	removeRunSubscription,
	signUpForSubscriptions,
	SUBSCRIPTION_GATEWAY,
	type OwnedSubscriptionProduct,
	type StoreBaselineEvidence,
	type SubscriptionEvidence,
} from '../../../utils/woopayments-native/drivers/subscriptions';
import { waitForPaymentState } from '../../../utils/woopayments-native/provider-evidence';
import {
	getOrderPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import { ResourceQuarantineRequiredError } from '../../../utils/woopayments-native/resource-locks';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `subscription-provider-lifecycle` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * The claim these seven cases exist to establish: each listed signup,
 * payment-method change and renewal preserves the exact order, subscription,
 * customer, token and provider relationships across the real provider boundary,
 * and produces the one payment outcome listed for that case with no duplicate
 * subscription, renewal order, intent or charge.
 *
 * What separates these from the client suite's originals is where the recurring
 * credential is read. Every client test reads a rendered card label off My
 * Account, and eight of their recorded residual risks say some version of "the
 * displayed last four can be right while the renewal token linkage is wrong".
 * A subscription's recurring credential lives in the `_payment_tokens` order
 * meta, which every WooCommerce order data store lists as an internal meta key
 * and therefore strips out of the REST `meta_data` a subscription carries, so
 * there is no public read for it at all. The E2E runtime mu-plugin adds one
 * authenticated read-only route that discloses the exact token IDs, the exact
 * provider payment methods behind them, the related-order graph by relation,
 * and the Action Scheduler actions the subscription owns. Every assertion below
 * names exact IDs on both sides and asserts cardinality, because the claim's
 * falsifier is a duplicate, and a set that merely contains the right member
 * proves nothing about duplicates.
 *
 * Surface boundary. Every case drives the Classic checkout, because the
 * change-payment surface WooCommerce Subscriptions renders is the Classic
 * order-pay form whatever the store's checkout page is; keeping one surface
 * makes `S1`-`S7` comparable to one another. Nothing here is evidence for the
 * Blocks subscription checkout, which native serves through a different
 * `supports` list.
 *
 * Renewal dispatch. `S6` runs both halves the contract names and keeps them
 * apart on purpose. The merchant half is WooCommerce Subscriptions' own
 * "Process renewal" order action, which fires
 * `woocommerce_scheduled_subscription_payment` directly. The scheduled half
 * seeds a due Action Scheduler renewal by moving the subscription's own
 * schedule into the past and then dispatches WP-Cron, so
 * `action_scheduler_run_queue` and the queue runner - not an admin button -
 * hand the action to native. When natural cron would fire is not asserted; the
 * claim excludes cron timing semantics.
 *
 * Provider safety. Dispatching WP-Cron runs the whole due queue, so `S6`
 * refuses to dispatch unless every pending renewal action due at that moment
 * belongs to this run's subscription. A standing store carrying someone else's
 * due fixture stops the case rather than charging it.
 *
 * Cost note. One full pass creates eight subscription products, six
 * subscriptions, six parent orders, five renewal orders, one SetupIntent from a
 * zero-total signup, one SetupIntent from a new-method change, one SetupIntent
 * from a My Account card save, and one payment intent and captured charge per
 * paid order or renewal. Nothing is retried: `--retries=0` is mandatory, and no
 * case re-submits a gesture that reached the provider.
 */

/** Grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:subscription-provider-lifecycle';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

/* ------------------------------------------------------------------------ *
 * Ledger rows
 * ------------------------------------------------------------------------ */

const CONTRACT_S6_MERCHANT =
	'default::chromium::tests/e2e/specs/subscriptions/merchant/merchant-subscriptions-renew.spec.ts:61::Subscriptions › Renew a subscription as a merchant › should be able to renew a subscription in my account';

function contracts( ...ids: string[] ) {
	return ids.map( ( description ) => ( {
		type: 'woopayments-contract',
		description,
	} ) );
}

/* ------------------------------------------------------------------------ *
 * Capabilities
 * ------------------------------------------------------------------------ */

/** The journey capability every case in this family needs. */
const CAPABILITY_FAMILY = 'subscription-lifecycle';
/** Creating the run-owned subscription product fixtures. */
const CAPABILITY_PRODUCT = 'subscription-lifecycle-product';
/** The signup checkout that reaches the provider. */
const CAPABILITY_SIGNUP = 'subscription-lifecycle-signup';
/** Renewal dispatch, merchant or scheduled. */
const CAPABILITY_RENEWAL = 'subscription-lifecycle-renewal';
/** Removing every run-owned subscription, order, action and credential. */
const CAPABILITY_CLEANUP = 'subscription-lifecycle-cleanup';
/** Reused from the saved-token family: it owns the token-removal gesture. */
const CAPABILITY_SAVED_CARD_CLEANUP = 'saved-card-cleanup';
/** Reused: this family provisions the same marker-bound Classic page. */
const CAPABILITY_CLASSIC_PAGE = 'classic-checkout-page';

const BASE_CAPABILITIES = [
	CAPABILITY_FAMILY,
	CAPABILITY_PRODUCT,
	CAPABILITY_SIGNUP,
	CAPABILITY_CLASSIC_PAGE,
	CAPABILITY_CLEANUP,
	CAPABILITY_SAVED_CARD_CLEANUP,
];

/* ------------------------------------------------------------------------ *
 * Fixed inputs
 * ------------------------------------------------------------------------ */

/**
 * The two cards the fixed run contract names. Both are ordinary reusable cards
 * that select no provider behaviour, which is why they stay written out here
 * rather than in `test-cards.ts`; that module carries only cards whose outcome
 * the provider chooses.
 */
const PRIMARY_CARD: ProviderTestCard = {
	number: '4242424242424242',
	expiry: '0245',
	securityCode: '424',
};

const RECURRING_PRICE = '9.99';
const RECURRING_MINOR = 999;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const ACTIVE_SUBSCRIPTION_STATUS = 'active';

/** The claim's convergence rule: poll every 3 seconds. */
const POLL_INTERVAL_MS = 3_000;
/** The claim's per-phase budget. */
const PHASE_TIMEOUT_MS = 120_000;

/* ------------------------------------------------------------------------ *
 * Shared assertions
 * ------------------------------------------------------------------------ */

function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: readonly string[]
): void {
	for ( const capability of capabilities ) {
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
					boolean,
				]
		)
		.toSorted( ( left, right ) => left[ 0 ] - right[ 0 ] );
}

/**
 * Reads one subscription twice, `POLL_INTERVAL_MS` apart, and returns it only
 * when both reads are byte-identical and the caller's terminal predicate holds.
 *
 * This is the claim's convergence rule. Without it a renewal order created one
 * poll after the assertion would never be seen, which is exactly the duplicate
 * the family exists to catch.
 */
async function convergedSubscription(
	session: ProviderWriteSession,
	subscriptionId: number,
	isTerminal: ( evidence: SubscriptionEvidence ) => boolean,
	reason: string
): Promise< SubscriptionEvidence > {
	const deadline = Date.now() + PHASE_TIMEOUT_MS;
	let previous = await readSubscriptionEvidence( session, subscriptionId );

	for (;;) {
		await delay( POLL_INTERVAL_MS );
		const current = await readSubscriptionEvidence(
			session,
			subscriptionId
		);
		if (
			isTerminal( current ) &&
			JSON.stringify( current ) === JSON.stringify( previous )
		) {
			return current;
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`Subscription ${ subscriptionId } never converged: ${ reason }. Last read: ${ JSON.stringify(
					current
				) }`
			);
		}
		previous = current;
	}
}

/**
 * Waits for one order to carry a settled provider payment, then requires a
 * second identical terminal read.
 */
async function convergedPayment(
	session: ProviderWriteSession,
	orderId: number
): Promise< PaymentEvidence > {
	const deadline = Date.now() + PHASE_TIMEOUT_MS;
	let lastError: unknown;

	for (;;) {
		try {
			const order = await getOrderPaymentEvidence(
				session.adminApi,
				orderId
			);
			const settled = await waitForPaymentState(
				session.adminApi,
				{ orderId, intentId: order.intentId },
				'succeeded',
				deadline
			);
			await delay( POLL_INTERVAL_MS );
			const confirmed = await waitForPaymentState(
				session.adminApi,
				{ orderId, intentId: order.intentId },
				'succeeded',
				deadline
			);
			expect(
				confirmed,
				'two consecutive reads must return the same terminal payment'
			).toEqual( settled );
			return settled;
		} catch ( error ) {
			lastError = error;
			if ( Date.now() >= deadline ) {
				throw new Error(
					`Order ${ orderId } never carried a settled provider payment.`,
					{ cause: lastError }
				);
			}
			await delay( POLL_INTERVAL_MS );
		}
	}
}

/**
 * The one-occurrence payment outcome every paid case in this family names: one
 * intent, one charge, one capture, for the exact amount, on the exact method.
 */
function expectSingleCapturedGraph(
	payment: PaymentEvidence,
	expected: { amountMinor: number; paymentMethodId?: string }
): void {
	expect( payment.amountMinor ).toBe( expected.amountMinor );
	expect( payment.currency ).toBe( CURRENCY );
	expect( payment.providerStatus ).toBe( 'succeeded' );
	expect( payment.chargeStatus ).toBe( 'succeeded' );
	expect( payment.chargeCaptured ).toBe( true );
	expect(
		payment.occurrenceCount,
		'one submission must leave exactly one charge on the intent'
	).toBe( 1 );
	expect( payment.captureOccurrenceCount ).toBe( 1 );
	expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
	if ( expected.paymentMethodId !== undefined ) {
		expect(
			payment.paymentMethodId,
			'the payment must be drawn on the exact expected provider method'
		).toBe( expected.paymentMethodId );
	}
}

/* ------------------------------------------------------------------------ *
 * Run scope: baselines, ledger, cleanup, restoration proof
 * ------------------------------------------------------------------------ */

interface RunBaseline {
	store: StoreBaselineEvidence;
	tokens: SavedCardToken[];
	providerCustomerId: string | undefined;
	providerAttachments: string[];
	subscriptionIds: number[];
	highestOrderId: number;
}

interface RunLedger {
	productIds: number[];
	subscriptionIds: number[];
	orderIds: number[];
	cards: SavedCardIdentity[];
}

interface RunScope {
	baseline: RunBaseline;
	ledger: RunLedger;
	classicCheckout: ClassicCheckoutTarget;
}

async function readBaseline(
	session: ProviderWriteSession
): Promise< RunBaseline > {
	const evidence = await getSavedCardEvidence( session );
	const providerCustomerId = await findSavedCardProviderCustomerId( session );
	return {
		store: await readStoreBaseline( session ),
		tokens: evidence.tokens,
		providerCustomerId,
		providerAttachments: providerCustomerId
			? await getProviderPaymentMethodIds( session, providerCustomerId )
			: [],
		subscriptionIds: await readCustomerSubscriptionIds( session ),
		highestOrderId: await readHighestOrderId( session ),
	};
}

/**
 * Removes everything the run created and proves the store is back where it
 * started.
 *
 * Order matters. Subscriptions go first, because cancelling one is what
 * unschedules the Action Scheduler actions it owns, and a run-owned
 * subscription left active on this account is armed recurring billing rather
 * than untidy data. Orders follow, then the credentials the run created - which
 * also detaches them at the provider - and finally the product fixtures.
 */
async function releaseRun(
	session: ProviderWriteSession,
	page: Page,
	scope: RunScope
): Promise< void > {
	const { baseline, ledger } = scope;
	const runSubscriptionIds = [ ...ledger.subscriptionIds ];

	for ( const subscriptionId of ledger.subscriptionIds.toReversed() ) {
		await removeRunSubscription( session, subscriptionId );
	}
	ledger.subscriptionIds.length = 0;

	await removeRunOrders( session, ledger.orderIds.toReversed() );
	ledger.orderIds.length = 0;

	if ( ledger.cards.length > 0 ) {
		await deleteExactSavedCards(
			session,
			page,
			ledger.cards,
			baseline.providerCustomerId
		);
		ledger.cards.length = 0;
	}

	await deleteSubscriptionProducts( session, ledger.productIds.toReversed() );
	ledger.productIds.length = 0;

	await emptyShopperCart( session, page );

	// Restoration is a proof, not an action: this family writes none of the
	// configuration below, so the same reads have to answer identically.
	const store = await readStoreBaseline( session );
	expect(
		store,
		'the run must leave the raw gateway, currency and subscription settings byte-identical'
	).toEqual( baseline.store );

	const tokensAfter = await getSavedCardEvidence( session );
	expect(
		tokenIdentities( tokensAfter.tokens ),
		'the run must return the local token set and its default to the recorded baseline'
	).toEqual( tokenIdentities( baseline.tokens ) );

	// When the baseline could name a provider customer, the attachment set has
	// to be exactly what it was. When it could not - the shopper held no
	// credential, so the store had nothing to disclose - the requirement is
	// stronger rather than weaker: the customer this run brought into existence
	// must end it holding nothing.
	const providerCustomerId =
		baseline.providerCustomerId ??
		( await findSavedCardProviderCustomerId( session ) );
	if ( providerCustomerId ) {
		expect(
			(
				await getProviderPaymentMethodIds( session, providerCustomerId )
			).toSorted(),
			'the run must return the provider attachment set to the recorded baseline'
		).toEqual( baseline.providerAttachments.toSorted() );
	}

	expect(
		await readCustomerSubscriptionIds( session ),
		'the run must leave no subscription behind and remove none it did not create'
	).toEqual( baseline.subscriptionIds );

	// Scoped to the subscriptions this run created. The standing store carries
	// other people's fixtures with their own armed renewals, and removing or
	// asserting anything about those would be exactly the unowned mutation the
	// cleanup rule forbids.
	const pending = await readPendingRenewalActions( session );
	expect(
		pending
			.filter( ( action ) =>
				runSubscriptionIds.includes( action.subscriptionId )
			)
			.map( ( action ) => action.actionId ),
		'the run must leave no renewal action armed on any subscription it created'
	).toEqual( [] );
}

/**
 * Opens one case: provider write locks, recorded baselines, a marker-bound
 * Classic checkout page, and a ledger that cleanup consumes whatever happens.
 *
 * A cleanup failure is a quarantine, not a test failure: a run-owned
 * subscription that survives can bill the test account again on its own.
 */
async function withSubscriptionRun(
	session: ProviderWriteSession,
	page: Page,
	recordEvent: string,
	body: ( scope: RunScope ) => Promise< void >
): Promise< void > {
	await session.withProviderWriteLocks( { recordEvent }, async () => {
		const baseline = await readBaseline( session );
		const ledger: RunLedger = {
			productIds: [],
			subscriptionIds: [],
			orderIds: [],
			cards: [],
		};

		await withClassicCheckoutPage(
			session,
			session.runId,
			async ( checkoutScope ) => {
				const scope: RunScope = {
					baseline,
					ledger,
					classicCheckout: checkoutScope.classicCheckout,
				};

				let primaryError: unknown;
				try {
					await body( scope );
				} catch ( error ) {
					primaryError = error;
				}

				try {
					await releaseRun( session, page, scope );
				} catch ( cleanupFailure ) {
					throw new ResourceQuarantineRequiredError(
						'A subscription case could not be cleaned up, so run-owned recurring billing may still be armed.',
						'cleanup-failed',
						primaryError === undefined
							? cleanupFailure
							: new AggregateError(
									[ primaryError, cleanupFailure ],
									'A subscription case failed and its cleanup failed too.',
									{ cause: cleanupFailure }
							  )
					);
				}

				if ( primaryError !== undefined ) {
					throw primaryError;
				}
			}
		);
	} );
}

/* ------------------------------------------------------------------------ *
 * Signup helper shared by S1, S2, S3, S4, S5 and S7
 * ------------------------------------------------------------------------ */

interface SignupOutcome {
	subscription: SubscriptionEvidence;
	parentOrderId: number;
	parentOrderKey: string;
	createdTokens: SavedCardIdentity[];
	providerCustomerId: string;
}

/**
 * Drives one signup and establishes the identity facts every case rests on: one
 * new subscription, one new parent order, one new local token, one new provider
 * attachment, and the exact relationships between them.
 */
async function signUpOnce(
	session: ProviderWriteSession,
	page: Page,
	scope: RunScope,
	options: {
		products: readonly OwnedSubscriptionProduct[];
		card: ProviderTestCard;
		journal: string;
	}
): Promise< SignupOutcome > {
	const subscriptionsBefore = await readCustomerSubscriptionIds( session );
	const tokensBefore = await getSavedCardEvidence( session );
	const highestOrderId = await readHighestOrderId( session );
	const attachmentsBefore = scope.baseline.providerCustomerId
		? await getProviderPaymentMethodIds(
				session,
				scope.baseline.providerCustomerId
		  )
		: undefined;

	const signup = await signUpForSubscriptions( session, page, {
		products: options.products,
		checkout: scope.classicCheckout,
		card: options.card,
		journal: options.journal,
	} );
	scope.ledger.orderIds.push( signup.orderId );

	expect(
		signup.checkoutRequestCount,
		'one Place order activation must ask the store exactly once'
	).toBe( 1 );
	expect( signup.checkoutResponseCount ).toBe( 1 );

	const subscriptionsAfter = await readCustomerSubscriptionIds( session );
	const newSubscriptionIds = subscriptionsAfter.filter(
		( id ) => ! subscriptionsBefore.includes( id )
	);
	expect(
		newSubscriptionIds,
		'one signup must create exactly one subscription'
	).toHaveLength( 1 );
	scope.ledger.subscriptionIds.push( newSubscriptionIds[ 0 ] );

	const tokensAfter = await getSavedCardEvidence( session );
	const createdTokens = tokensAfter.tokens
		.filter(
			( token ) =>
				! tokensBefore.tokens.some(
					( existing ) => existing.tokenId === token.tokenId
				)
		)
		.map( ( token ) => ( {
			tokenId: token.tokenId,
			paymentMethodId: token.paymentMethodId,
		} ) );
	expect(
		createdTokens,
		'one signup must create exactly one reusable credential'
	).toHaveLength( 1 );
	scope.ledger.cards.push( ...createdTokens );

	const providerCustomerId = await readSavedCardProviderCustomerId( session );
	if ( scope.baseline.providerCustomerId !== undefined ) {
		expect(
			providerCustomerId,
			'the run must work against the same provider customer the baseline named'
		).toBe( scope.baseline.providerCustomerId );
	}
	const attachmentsAfter = await getProviderPaymentMethodIds(
		session,
		providerCustomerId
	);
	expect(
		attachmentsAfter.filter(
			( id ) => id === createdTokens[ 0 ].paymentMethodId
		),
		'the provider must hold exactly one attachment of the new credential'
	).toHaveLength( 1 );
	if ( attachmentsBefore !== undefined ) {
		expect(
			attachmentsAfter.toSorted(),
			'one signup must attach exactly one new method and detach none'
		).toEqual(
			[
				...attachmentsBefore,
				createdTokens[ 0 ].paymentMethodId,
			].toSorted()
		);
	} else {
		// The shopper held no credential at all before this run, so the store
		// could not name a provider customer to read attachments from. The one
		// method this signup created must then be the only attachment there is:
		// anything else is an unowned credential on the account.
		expect(
			attachmentsAfter,
			'a shopper with no prior credential must end the signup with exactly the one it created'
		).toEqual( [ createdTokens[ 0 ].paymentMethodId ] );
	}

	const orders = await readOrderDeltaAfter( session, highestOrderId );
	expect(
		orders.newOrderIds,
		'one signup must create exactly one order'
	).toEqual( [ signup.orderId ] );

	const subscription = await convergedSubscription(
		session,
		newSubscriptionIds[ 0 ],
		( evidence ) =>
			evidence.status === ACTIVE_SUBSCRIPTION_STATUS &&
			evidence.activeTokenId !== 0,
		'the signup must produce one active subscription carrying a recurring token'
	);

	expect(
		subscription.parentId,
		'the subscription must be carried forward from the exact order the signup created'
	).toBe( signup.orderId );
	expect( subscription.relatedOrders.parent ).toEqual( [ signup.orderId ] );
	expect(
		subscription.relatedOrders.renewal,
		'a signup must create no renewal order'
	).toEqual( [] );
	expect( subscription.paymentMethod ).toBe( SUBSCRIPTION_GATEWAY );
	expect( subscription.requiresManualRenewal ).toBe( false );
	expect( subscription.currency ).toBe( CURRENCY );
	expect( subscription.billingPeriod ).toBe( 'month' );
	expect( subscription.billingInterval ).toBe( '1' );
	expect(
		subscription.paymentTokenIds,
		'a signup must leave exactly one recurring credential on the subscription'
	).toEqual( [ createdTokens[ 0 ].tokenId ] );
	expect( subscription.paymentTokens[ 0 ].paymentMethodId ).toBe(
		createdTokens[ 0 ].paymentMethodId
	);
	expect( subscription.paymentTokens[ 0 ].gatewayId ).toBe(
		SUBSCRIPTION_GATEWAY
	);
	expect(
		subscription.paymentTokens[ 0 ].userId,
		'the recurring credential must belong to the shopper who bought the subscription'
	).toBe( subscription.customerId );

	return {
		subscription,
		parentOrderId: signup.orderId,
		parentOrderKey: signup.orderKey,
		createdTokens,
		providerCustomerId,
	};
}

/**
 * Runs one renewal and returns the single renewal order it produced.
 */
async function expectOneNewRenewal(
	session: ProviderWriteSession,
	subscriptionId: number,
	before: SubscriptionEvidence,
	scope: RunScope
): Promise< { renewalOrderId: number; subscription: SubscriptionEvidence } > {
	const subscription = await convergedSubscription(
		session,
		subscriptionId,
		( evidence ) =>
			evidence.relatedOrders.renewal.length ===
			before.relatedOrders.renewal.length + 1,
		'the renewal must produce exactly one new renewal order'
	);

	const created = subscription.relatedOrders.renewal.filter(
		( id ) => ! before.relatedOrders.renewal.includes( id )
	);
	expect(
		created,
		'one renewal must create exactly one renewal order'
	).toHaveLength( 1 );
	scope.ledger.orderIds.push( created[ 0 ] );
	// WooCommerce Subscriptions builds the renewal order itself, so it carries
	// no run ID; this is the point where the run learns the order exists and
	// takes ownership of it, so it is the point that stamps it. Without the
	// stamp the provider-evidence reader refuses the order as unowned.
	await session.setOrderRunId( created[ 0 ], session.runId );

	expect(
		subscription.paymentCount,
		'one renewal must advance the subscription exactly once'
	).toBe( before.paymentCount + 1 );
	expect(
		subscription.paymentTokenIds,
		'a renewal must not add or replace a recurring credential'
	).toEqual( before.paymentTokenIds );

	return { renewalOrderId: created[ 0 ], subscription };
}

/* ------------------------------------------------------------------------ *
 * Cases
 * ------------------------------------------------------------------------ */

test.describe( 'WooPayments native subscription provider lifecycle fidelity', () => {
	// Serial on purpose: every case drives real recurring billing on one shared
	// provider account and one shared shopper, and a failure must stop the
	// family rather than spend more provider budget against a store whose state
	// is no longer described.
	test.describe.configure( { mode: 'serial', timeout: 900_000 } );

	test(
		"one merchant renewal produces exactly one 999 usd renewal order, intent and captured charge on the signup token's exact payment method, distinct from the parent order's own intent, and advances the subscription exactly once with no duplicate order or credential",
		{
			annotation: contracts( CONTRACT_S6_MERCHANT ),
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, [
				...BASE_CAPABILITIES,
				CAPABILITY_RENEWAL,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withSubscriptionRun(
				pilotRuntime,
				page,
				'subscription-renewal-ownership',
				async ( scope ) => {
					const product = await createSubscriptionProduct(
						pilotRuntime,
						{ slug: 's6', price: RECURRING_PRICE }
					);
					scope.ledger.productIds.push( product.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ product ],
							card: PRIMARY_CARD,
							journal: 'subscription-renewal-seed',
						}
					);
					const token = outcome.createdTokens[ 0 ];
					const parentPayment = await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);

					await processMerchantRenewal(
						pilotRuntime,
						page,
						outcome.subscription.id,
						'subscription-merchant-renewal'
					);
					const merchantRenewal = await expectOneNewRenewal(
						pilotRuntime,
						outcome.subscription.id,
						outcome.subscription,
						scope
					);
					const merchantPayment = await convergedPayment(
						pilotRuntime,
						merchantRenewal.renewalOrderId
					);
					expectSingleCapturedGraph( merchantPayment, {
						amountMinor: RECURRING_MINOR,
						paymentMethodId: token.paymentMethodId,
					} );
					expect(
						merchantPayment.intentId,
						"the renewal must have its own PaymentIntent, not the parent order's"
					).not.toBe( parentPayment.intentId );
					const merchantRenewalOrder = await readOrderRecord(
						pilotRuntime,
						merchantRenewal.renewalOrderId
					);
					expect(
						merchantRenewalOrder.customerId,
						'the renewal order must belong to the subscription customer'
					).toBe( outcome.subscription.customerId );
					expect( merchantRenewalOrder.paymentMethod ).toBe(
						SUBSCRIPTION_GATEWAY
					);
				}
			);
		}
	);
} );
