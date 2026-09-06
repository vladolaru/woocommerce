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
	submitNativeAddPaymentMethod,
	type SavedCardToken,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import {
	changeSubscriptionPaymentMethod,
	createSubscriptionProduct,
	delay,
	deleteSubscriptionProducts,
	dispatchWpCronUntilActionRan,
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
	RENEWAL_ACTION_HOOK,
	seedDueRenewalAction,
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

const CONTRACT_S1_PURCHASE =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-sign-up-fee.spec.ts:32::Subscriptions › Purchase subscription with signup fee › should be able to purchase a subscription with signup fee';
const CONTRACT_S1_CHARGE =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-sign-up-fee.spec.ts:43::Subscriptions › Purchase subscription with signup fee › should have a charge for subscription cost with fee & an active subscription';
const CONTRACT_S1_MY_ACCOUNT =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-renew-subscription.spec.ts:34::Subscriptions › Renew a subscription in my account › should be able to purchase a subscription';
const CONTRACT_S2_PURCHASE =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-no-signup-fee.spec.ts:37::Shopper Subscriptions Purchase No Signup Fee › It should be able to purchase a subscription without a signup fee';
const CONTRACT_S2_CHARGE =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-no-signup-fee.spec.ts:71::Shopper Subscriptions Purchase No Signup Fee › It should have a charge for subscription cost without fee & an active subscription';
const CONTRACT_S3_SETUP_INTENT =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-free-trial.spec.ts:166::Shopper: Subscriptions - Purchase Free Trial › Merchant should be able to create an order with "Setup Intent"';
const CONTRACT_S4_NEW_METHOD =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-manage-payments.spec.ts:67::Shopper › Subscriptions › Manage Payment Methods › should change a default payment method to a new one';
const CONTRACT_S5_SAVED_METHOD =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-manage-payments.spec.ts:85::Shopper › Subscriptions › Manage Payment Methods › should set a payment method to an already saved card';
const CONTRACT_S6_MERCHANT =
	'default::chromium::tests/e2e/specs/subscriptions/merchant/merchant-subscriptions-renew.spec.ts:61::Subscriptions › Renew a subscription as a merchant › should be able to renew a subscription in my account';
const CONTRACT_S6_ACTION_SCHEDULER =
	'default::chromium::tests/e2e/specs/subscriptions/merchant/merchant-subscriptions-renew-action-scheduler.spec.ts:64::Subscriptions › Renew a subscription via Action Scheduler › should renew a subscription with action scheduler';
const CONTRACT_S7_MULTIPLE =
	'default::chromium::tests/e2e/specs/subscriptions/shopper/shopper-subscriptions-purchase-multiple-subscriptions.spec.ts:53::Subscriptions › Purchase multiple subscriptions › should be able to purchase multiple subscriptions';

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
/** The shopper's change-payment submission. */
const CAPABILITY_METHOD_CHANGE = 'subscription-lifecycle-method-change';
/** Renewal dispatch, merchant or scheduled. */
const CAPABILITY_RENEWAL = 'subscription-lifecycle-renewal';
/** Removing every run-owned subscription, order, action and credential. */
const CAPABILITY_CLEANUP = 'subscription-lifecycle-cleanup';
/** Reused from the saved-token family for the My Account card save in `S5`. */
const CAPABILITY_SAVED_CARD_ADD = 'saved-card-add';
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
const REPLACEMENT_CARD: ProviderTestCard = {
	number: '5555555555554444',
	expiry: '0345',
	securityCode: '444',
};

const RECURRING_PRICE = '9.99';
const RECURRING_MINOR = 999;
const SIGNUP_FEE = '1.99';
const SIGNUP_FEE_TOTAL_MINOR = 1198;
const MULTIPLE_TOTAL_MINOR = 2197;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const ACTIVE_SUBSCRIPTION_STATUS = 'active';
const TRIAL_LENGTH_DAYS = 14;

/** The claim's convergence rule: poll every 3 seconds. */
const POLL_INTERVAL_MS = 3_000;
/** The claim's per-phase budget. */
const PHASE_TIMEOUT_MS = 120_000;

/** How far back `S6` moves the seeded subscription's start date. */
const SEED_START_SECONDS_AGO = 2 * 24 * 60 * 60;
/**
 * How far ahead `S6` seeds the next payment. WooCommerce Subscriptions only
 * schedules an Action Scheduler action for a future date - a past-dated seed
 * cancels the pending action and schedules nothing - so the seed lands just
 * ahead of the clock and the convergence poll waits for it to fall due.
 */
const SEED_DUE_IN_SECONDS = 45;
/**
 * The window that separates the seeded renewal action from the natural
 * schedule: the seed lands within a minute, the month-out renewal thirty
 * days away, so any renewal action scheduled inside this horizon is the
 * seeded one.
 */
const SEED_HORIZON_MS = 10 * 60 * 1000;

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
		'one signup-fee subscription checkout creates one USD 11.98 parent order whose single product line carries the fee and matches a single 1198 usd succeeded intent and captured charge, one active subscription, one token and provider-customer graph, and zero renewal orders',
		{
			annotation: contracts(
				CONTRACT_S1_PURCHASE,
				CONTRACT_S1_CHARGE,
				CONTRACT_S1_MY_ACCOUNT
			),
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, BASE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withSubscriptionRun(
				pilotRuntime,
				page,
				'shopper-subscription-signup-fee',
				async ( scope ) => {
					const product = await createSubscriptionProduct(
						pilotRuntime,
						{
							slug: 's1',
							price: RECURRING_PRICE,
							signUpFee: SIGNUP_FEE,
						}
					);
					scope.ledger.productIds.push( product.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ product ],
							card: PRIMARY_CARD,
							journal: 'subscription-signup-fee',
						}
					);

					// The provider side: exactly one 1198 usd intent, one
					// charge, one capture, drawn on the exact new credential.
					const payment = await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect( payment.orderId ).toBe( outcome.parentOrderId );
					expect( payment.orderKey ).toBe( outcome.parentOrderKey );
					expect( payment.runId ).toBe( pilotRuntime.runId );
					expectSingleCapturedGraph( payment, {
						amountMinor: SIGNUP_FEE_TOTAL_MINOR,
						paymentMethodId:
							outcome.createdTokens[ 0 ].paymentMethodId,
					} );
					expect(
						await readProviderCustomerId(
							pilotRuntime,
							payment.intentId
						),
						'the intent must be drawn on this shopper provider customer'
					).toBe( outcome.providerCustomerId );

					// The record side: the parent order's line composition, at
					// the record level rather than as rendered money.
					// Subscriptions charges a signup fee by raising the
					// product's own price for the initial payment
					// (`WC_Subscriptions_Cart::set_subscription_prices_for_calculation`),
					// so the fee arrives inside the product line and no
					// WooCommerce fee line is ever created. The discriminating
					// assertion is therefore the line total itself: 11.98 here,
					// against the 9.99 S2 proves for the same product without a
					// fee.
					const parent = await readOrderRecord(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect( parent.paymentMethod ).toBe( SUBSCRIPTION_GATEWAY );
					expect( parent.currency ).toBe( CURRENCY );
					expect( parent.lineItems ).toHaveLength( 1 );
					expect( parent.lineItems[ 0 ].productId ).toBe(
						product.id
					);
					expect( parent.lineItems[ 0 ].quantity ).toBe( 1 );
					expect(
						Math.round(
							Number( parent.lineItems[ 0 ].total ) * 100
						),
						'the signup fee must arrive inside the product line, making that line the proven provider total'
					).toBe( SIGNUP_FEE_TOTAL_MINOR );
					expect(
						parent.feeLines,
						'a signup fee is not a WooCommerce fee line'
					).toEqual( [] );

					// The subscription renews on the recurring price only, and
					// no renewal has happened yet. Re-read after settlement so
					// the payment count is the terminal one rather than a
					// figure taken while the parent order was still being paid.
					const settled = await convergedSubscription(
						pilotRuntime,
						outcome.subscription.id,
						( evidence ) => evidence.paymentCount === 1,
						'the signup must count exactly one payment and no more'
					);
					expect( Number( settled.total ) ).toBeCloseTo(
						Number( RECURRING_PRICE ),
						2
					);
					expect(
						settled.feeLines,
						'a signup fee is charged once on the parent order, never carried into the recurring schedule'
					).toEqual( [] );
					expect(
						settled.relatedOrders.renewal,
						'a signup must not renew immediately'
					).toEqual( [] );
					expect(
						settled.scheduledActions.filter(
							( action ) =>
								action.hook === RENEWAL_ACTION_HOOK &&
								action.status === 'pending'
						),
						'one signup must arm exactly one pending renewal action'
					).toHaveLength( 1 );
				}
			);
		}
	);

	test(
		'one no-signup-fee subscription checkout creates one USD 9.99 parent order whose single product line carries the recurring price alone, a single 999 usd succeeded intent and captured charge, one active subscription, and one token and provider-customer graph',
		{
			annotation: contracts( CONTRACT_S2_PURCHASE, CONTRACT_S2_CHARGE ),
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, BASE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withSubscriptionRun(
				pilotRuntime,
				page,
				'shopper-subscription-no-signup-fee',
				async ( scope ) => {
					const product = await createSubscriptionProduct(
						pilotRuntime,
						{ slug: 's2', price: RECURRING_PRICE }
					);
					scope.ledger.productIds.push( product.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ product ],
							card: PRIMARY_CARD,
							journal: 'subscription-no-signup-fee',
						}
					);

					const payment = await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect( payment.orderId ).toBe( outcome.parentOrderId );
					expect( payment.orderKey ).toBe( outcome.parentOrderKey );
					expectSingleCapturedGraph( payment, {
						amountMinor: RECURRING_MINOR,
						paymentMethodId:
							outcome.createdTokens[ 0 ].paymentMethodId,
					} );
					expect(
						await readProviderCustomerId(
							pilotRuntime,
							payment.intentId
						)
					).toBe( outcome.providerCustomerId );

					// The zero-fee assertion the fixed contract asks for is a
					// record-level one, and it has to be the line total. A
					// signup fee would arrive inside this very line rather than
					// beside it (see S1), so 9.99 here — not an absent fee line,
					// which is absent either way — is what proves no fee was
					// charged.
					const parent = await readOrderRecord(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect( parent.lineItems ).toHaveLength( 1 );
					expect( parent.lineItems[ 0 ].productId ).toBe(
						product.id
					);
					expect(
						Math.round(
							Number( parent.lineItems[ 0 ].total ) * 100
						),
						'a no-signup-fee product must put exactly the recurring price on its line'
					).toBe( RECURRING_MINOR );
					expect( parent.feeLines ).toEqual( [] );
					expect(
						Math.round( Number( parent.total ) * 100 ),
						'the parent total must be exactly the recurring price'
					).toBe( RECURRING_MINOR );
					expect( outcome.subscription.feeLines ).toEqual( [] );
				}
			);
		}
	);

	test(
		'one free-trial signup creates one zero-total order carrying a single succeeded provider SetupIntent and one reusable credential with no PaymentIntent or charge, and one separately journaled renewal then charges 999 usd on that exact credential',
		{
			annotation: contracts( CONTRACT_S3_SETUP_INTENT ),
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
				'shopper-subscription-free-trial',
				async ( scope ) => {
					const product = await createSubscriptionProduct(
						pilotRuntime,
						{
							slug: 's3',
							price: RECURRING_PRICE,
							trialLength: TRIAL_LENGTH_DAYS,
							trialPeriod: 'day',
						}
					);
					scope.ledger.productIds.push( product.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ product ],
							card: PRIMARY_CARD,
							journal: 'subscription-free-trial-setup',
						}
					);

					// A trial signup takes no money: the parent order is zero
					// total and carries a SetupIntent, never a charge.
					const parent = await readOrderRecord(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect(
						Math.round( Number( parent.total ) * 100 ),
						'a free-trial signup must create a zero-total order'
					).toBe( 0 );
					expect(
						parent.meta._charge_id,
						'a free-trial signup must create no charge'
					).toBeUndefined();
					const setupIntentId = parent.meta._intent_id;
					expect(
						setupIntentId,
						'the zero-total order must record the exact SetupIntent native created'
					).toMatch( /^seti_/ );

					// Read back from the provider, so "a SetupIntent exists"
					// cannot be satisfied by a stale or detached identifier.
					const setupIntent = await readProviderSetupIntent(
						pilotRuntime,
						setupIntentId
					);
					expect( setupIntent.id ).toBe( setupIntentId );
					expect(
						setupIntent.status,
						'the free-trial SetupIntent must have succeeded at the provider'
					).toBe( 'succeeded' );
					expect(
						setupIntent.usage,
						'the credential must be reusable off session'
					).toBe( 'off_session' );
					expect(
						setupIntent.paymentMethodId,
						'the SetupIntent must have produced the exact credential the store stored'
					).toBe( outcome.createdTokens[ 0 ].paymentMethodId );
					expect( setupIntent.customerId ).toBe(
						outcome.providerCustomerId
					);
					expect(
						await getProviderPaymentMethodIds(
							pilotRuntime,
							outcome.providerCustomerId
						),
						'the SetupIntent credential must be attached to this shopper at the provider'
					).toContain( outcome.createdTokens[ 0 ].paymentMethodId );

					expect(
						outcome.subscription.trialEndGmt,
						'a free-trial subscription must carry a trial end date'
					).not.toBe( '' );
					expect(
						Math.round(
							Number( outcome.subscription.total ) * 100
						),
						'the subscription must still renew at the recurring price'
					).toBe( RECURRING_MINOR );

					// The half the client suite never reaches: the credential
					// the SetupIntent produced actually funds a renewal.
					await processMerchantRenewal(
						pilotRuntime,
						page,
						outcome.subscription.id,
						'subscription-free-trial-renewal'
					);
					const { renewalOrderId } = await expectOneNewRenewal(
						pilotRuntime,
						outcome.subscription.id,
						outcome.subscription,
						scope
					);

					const renewalPayment = await convergedPayment(
						pilotRuntime,
						renewalOrderId
					);
					expectSingleCapturedGraph( renewalPayment, {
						amountMinor: RECURRING_MINOR,
						paymentMethodId:
							outcome.createdTokens[ 0 ].paymentMethodId,
					} );
					expect(
						renewalPayment.intentId,
						'the renewal must create its own PaymentIntent, not reuse the SetupIntent'
					).toMatch( /^pi_/ );
				}
			);
		}
	);

	test(
		'changing a subscription to a newly entered card moves its recurring token to that exact new provider method, leaves the original token stored and unused, and one off-session renewal then charges 999 usd on the new method alone',
		{
			annotation: contracts( CONTRACT_S4_NEW_METHOD ),
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, [
				...BASE_CAPABILITIES,
				CAPABILITY_METHOD_CHANGE,
				CAPABILITY_RENEWAL,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withSubscriptionRun(
				pilotRuntime,
				page,
				'shopper-subscription-change-new-method',
				async ( scope ) => {
					const product = await createSubscriptionProduct(
						pilotRuntime,
						{ slug: 's4', price: RECURRING_PRICE }
					);
					scope.ledger.productIds.push( product.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ product ],
							card: PRIMARY_CARD,
							journal: 'subscription-change-seed',
						}
					);
					const originalToken = outcome.createdTokens[ 0 ];
					await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);

					const tokensBefore =
						await getSavedCardEvidence( pilotRuntime );
					const change = await changeSubscriptionPaymentMethod(
						pilotRuntime,
						page,
						{
							subscriptionId: outcome.subscription.id,
							selection: {
								kind: 'new-card',
								card: REPLACEMENT_CARD,
							},
							journal: 'subscription-change-new-method',
						}
					);
					expect(
						change.submissionCount,
						'one change gesture must submit the change form exactly once'
					).toBe( 1 );
					expect(
						change.errorText,
						'the change must not report a payment error'
					).toBe( '' );

					const tokensAfter =
						await getSavedCardEvidence( pilotRuntime );
					const newTokens = tokensAfter.tokens.filter(
						( token ) =>
							! tokensBefore.tokens.some(
								( existing ) =>
									existing.tokenId === token.tokenId
							)
					);
					expect(
						newTokens,
						'one change to a new card must create exactly one new credential'
					).toHaveLength( 1 );
					const replacement = {
						tokenId: newTokens[ 0 ].tokenId,
						paymentMethodId: newTokens[ 0 ].paymentMethodId,
					};
					scope.ledger.cards.push( replacement );

					// The identity assertion: the recurring token is now the
					// exact new method, and it is not the old one.
					const changed = await convergedSubscription(
						pilotRuntime,
						outcome.subscription.id,
						( evidence ) =>
							evidence.activeTokenId === replacement.tokenId,
						'the change must move the recurring token to the exact new credential'
					);
					expect( changed.activeTokenId ).toBe( replacement.tokenId );
					expect( changed.activeTokenId ).not.toBe(
						originalToken.tokenId
					);
					expect(
						changed.paymentTokens[
							changed.paymentTokens.length - 1
						].paymentMethodId,
						'the recurring credential must resolve to the exact new provider method'
					).toBe( replacement.paymentMethodId );
					expect(
						changed.paymentMethod,
						'the subscription must stay on the native WooPayments gateway'
					).toBe( SUBSCRIPTION_GATEWAY );
					expect(
						changed.id,
						'a payment-method change must not replace the subscription'
					).toBe( outcome.subscription.id );
					expect( changed.parentId ).toBe( outcome.parentOrderId );
					expect(
						changed.relatedOrders.renewal,
						'a payment-method change must create no renewal order'
					).toEqual( [] );
					expect(
						changed.customerId,
						'a payment-method change must not move the subscription to another customer'
					).toBe( outcome.subscription.customerId );

					// The old credential is still stored, just no longer the
					// recurring one: a change must not silently delete it.
					expect(
						tokensAfter.tokens.map( ( token ) => token.tokenId ),
						'a change must leave the previous credential stored'
					).toContain( originalToken.tokenId );

					await processMerchantRenewal(
						pilotRuntime,
						page,
						outcome.subscription.id,
						'subscription-change-new-method-renewal'
					);
					const { renewalOrderId } = await expectOneNewRenewal(
						pilotRuntime,
						outcome.subscription.id,
						changed,
						scope
					);
					const renewalPayment = await convergedPayment(
						pilotRuntime,
						renewalOrderId
					);
					expectSingleCapturedGraph( renewalPayment, {
						amountMinor: RECURRING_MINOR,
						paymentMethodId: replacement.paymentMethodId,
					} );
					expect(
						renewalPayment.paymentMethodId,
						'the renewal must not draw on the replaced credential'
					).not.toBe( originalToken.paymentMethodId );
				}
			);
		}
	);

	test(
		'selecting an already-saved card for a subscription restores that exact original token as the recurring method rather than the seeded one, and one off-session renewal then charges 999 usd on it alone',
		{
			annotation: contracts( CONTRACT_S5_SAVED_METHOD ),
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, [
				...BASE_CAPABILITIES,
				CAPABILITY_METHOD_CHANGE,
				CAPABILITY_RENEWAL,
				CAPABILITY_SAVED_CARD_ADD,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withSubscriptionRun(
				pilotRuntime,
				page,
				'shopper-subscription-change-saved-method',
				async ( scope ) => {
					const product = await createSubscriptionProduct(
						pilotRuntime,
						{ slug: 's5', price: RECURRING_PRICE }
					);
					scope.ledger.productIds.push( product.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ product ],
							card: PRIMARY_CARD,
							journal: 'subscription-saved-method-seed',
						}
					);
					const primaryToken = outcome.createdTokens[ 0 ];
					await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);

					// The second saved card, created through My Account so this
					// case seeds a stored credential rather than entering one
					// on the change surface - which is what S4 already covers.
					const addition = await submitNativeAddPaymentMethod(
						pilotRuntime,
						page,
						{
							card: REPLACEMENT_CARD,
							journal: 'subscription-saved-method-second-card',
							expectation: 'accepted',
							logInAsCustomer: true,
						}
					);
					expect(
						addition.createdCards,
						'one card save must create exactly one credential'
					).toHaveLength( 1 );
					const replacementToken = addition.createdCards[ 0 ];
					scope.ledger.cards.push( replacementToken );

					// Seed the subscription onto the saved replacement, exactly
					// as the fixed contract states, so the case under test is a
					// genuine change back rather than a no-op that would pass
					// against a store that ignored it.
					const seedChange = await changeSubscriptionPaymentMethod(
						pilotRuntime,
						page,
						{
							subscriptionId: outcome.subscription.id,
							selection: {
								kind: 'saved-token',
								tokenId: replacementToken.tokenId,
							},
							journal: 'subscription-saved-method-seed-change',
						}
					);
					expect( seedChange.submissionCount ).toBe( 1 );
					expect( seedChange.errorText ).toBe( '' );
					const seeded = await convergedSubscription(
						pilotRuntime,
						outcome.subscription.id,
						( evidence ) =>
							evidence.activeTokenId === replacementToken.tokenId,
						'the seeding change must move the recurring token to the saved replacement'
					);
					expect( seeded.activeTokenId ).toBe(
						replacementToken.tokenId
					);

					// The contract: select the already-saved original once.
					const restore = await changeSubscriptionPaymentMethod(
						pilotRuntime,
						page,
						{
							subscriptionId: outcome.subscription.id,
							selection: {
								kind: 'saved-token',
								tokenId: primaryToken.tokenId,
							},
							journal: 'subscription-saved-method-select',
						}
					);
					expect(
						restore.submissionCount,
						'one selection must submit the change form exactly once'
					).toBe( 1 );
					expect( restore.errorText ).toBe( '' );

					const restored = await convergedSubscription(
						pilotRuntime,
						outcome.subscription.id,
						( evidence ) =>
							evidence.activeTokenId === primaryToken.tokenId,
						'selecting the saved original must make it the recurring token again'
					);
					expect(
						restored.activeTokenId,
						'the recurring token must be the exact original saved token'
					).toBe( primaryToken.tokenId );
					expect(
						restored.activeTokenId,
						'the recurring token must differ from the seeded replacement'
					).not.toBe( replacementToken.tokenId );
					expect(
						restored.paymentTokens[
							restored.paymentTokens.length - 1
						].paymentMethodId
					).toBe( primaryToken.paymentMethodId );
					expect( restored.id ).toBe( outcome.subscription.id );
					expect( restored.parentId ).toBe( outcome.parentOrderId );
					expect(
						restored.relatedOrders.renewal,
						'selecting a saved card must create no renewal order'
					).toEqual( [] );

					// Selecting a stored card must not mint another one on
					// either side.
					expect(
						(
							await getSavedCardEvidence( pilotRuntime )
						).tokens.map( ( token ) => token.tokenId ),
						'selecting a stored card must not create a third credential'
					).toEqual(
						expect.arrayContaining( [
							primaryToken.tokenId,
							replacementToken.tokenId,
						] )
					);
					expect(
						await getProviderPaymentMethodIds(
							pilotRuntime,
							outcome.providerCustomerId
						),
						'selecting a stored card must attach nothing new at the provider'
					).toEqual(
						expect.arrayContaining( [
							primaryToken.paymentMethodId,
							replacementToken.paymentMethodId,
						] )
					);

					await processMerchantRenewal(
						pilotRuntime,
						page,
						outcome.subscription.id,
						'subscription-saved-method-renewal'
					);
					const { renewalOrderId } = await expectOneNewRenewal(
						pilotRuntime,
						outcome.subscription.id,
						restored,
						scope
					);
					const renewalPayment = await convergedPayment(
						pilotRuntime,
						renewalOrderId
					);
					expectSingleCapturedGraph( renewalPayment, {
						amountMinor: RECURRING_MINOR,
						paymentMethodId: primaryToken.paymentMethodId,
					} );
					expect(
						renewalPayment.paymentMethodId,
						'the renewal must not draw on the seeded replacement'
					).not.toBe( replacementToken.paymentMethodId );
				}
			);
		}
	);

	test(
		'one merchant renewal and one schedule-driven Action Scheduler renewal each produce exactly one distinct 999 usd renewal order, intent and captured charge on the same saved credential, advance the subscription once each, and leave no duplicate action, order, token or subscription',
		{
			annotation: contracts(
				CONTRACT_S6_MERCHANT,
				CONTRACT_S6_ACTION_SCHEDULER
			),
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
					await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);

					/* -- Half one: the merchant's own Process renewal action -- */
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

					/* -- Half two: a seeded action, run by the queue runner -- */
					await seedDueRenewalAction(
						pilotRuntime,
						outcome.subscription.id,
						{
							startSecondsAgo: SEED_START_SECONDS_AGO,
							dueInSeconds: SEED_DUE_IN_SECONDS,
						}
					);

					// The seeded action is observed while still armed: pending
					// and scheduled inside the seed horizon, unambiguously
					// distinct from the natural month-out schedule. Waiting for
					// it to be pending AND overdue is not observable: the queue
					// runner claims a due action within seconds - store traffic,
					// the convergence poll's own reads included, dispatches
					// Action Scheduler's async runner - so due-and-pending can
					// vanish inside one poll interval.
					const seeded = await convergedSubscription(
						pilotRuntime,
						outcome.subscription.id,
						( evidence ) =>
							evidence.scheduledActions.some(
								( action ) =>
									action.hook === RENEWAL_ACTION_HOOK &&
									action.status === 'pending' &&
									action.scheduledTimestamp * 1000 <
										Date.now() + SEED_HORIZON_MS
							),
						'the seeding must arm one near-horizon renewal action'
					);
					const seededPending = seeded.scheduledActions.filter(
						( action ) =>
							action.hook === RENEWAL_ACTION_HOOK &&
							action.status === 'pending' &&
							action.scheduledTimestamp * 1000 <
								Date.now() + SEED_HORIZON_MS
					);
					expect(
						seededPending,
						'seeding must arm exactly one renewal action, not a duplicate'
					).toHaveLength( 1 );

					// Provider safety: the queue runner takes the whole due
					// queue. Refuse unless no other subscription comes due
					// inside the seed horizon, so a foreign fixture on the
					// standing store stops the case instead of being charged
					// by it.
					const now = Date.now();
					const foreignDue = (
						await readPendingRenewalActions( pilotRuntime )
					).filter(
						( action ) =>
							action.scheduledTimestamp * 1000 <=
								now + SEED_HORIZON_MS &&
							action.subscriptionId !== outcome.subscription.id
					);
					expect(
						foreignDue.map( ( action ) => action.subscriptionId ),
						'the queue must not be run while another subscription is due for renewal'
					).toEqual( [] );

					// The wp-cron loopback keeps the queue moving on a dormant
					// store; on a lively one the async runner may have taken
					// the action already. Either way the assertion is the same:
					// the queue runner - not an admin gesture - completes the
					// exact seeded action.
					const ranAction = await dispatchWpCronUntilActionRan(
						pilotRuntime,
						outcome.subscription.id,
						seededPending[ 0 ].actionId,
						{
							pollIntervalMs: POLL_INTERVAL_MS,
							timeoutMs: PHASE_TIMEOUT_MS,
						}
					);
					expect(
						ranAction.status,
						'the queue runner must complete the seeded renewal action'
					).toBe( 'complete' );

					const scheduledRenewal = await expectOneNewRenewal(
						pilotRuntime,
						outcome.subscription.id,
						merchantRenewal.subscription,
						scope
					);
					expect(
						scheduledRenewal.renewalOrderId,
						'the scheduled renewal must be a distinct order from the merchant renewal'
					).not.toBe( merchantRenewal.renewalOrderId );

					const scheduledPayment = await convergedPayment(
						pilotRuntime,
						scheduledRenewal.renewalOrderId
					);
					expectSingleCapturedGraph( scheduledPayment, {
						amountMinor: RECURRING_MINOR,
						paymentMethodId: token.paymentMethodId,
					} );
					expect(
						scheduledPayment.intentId,
						'the scheduled renewal must have its own PaymentIntent'
					).not.toBe( merchantPayment.intentId );
					expect(
						scheduledPayment.chargeId,
						'the scheduled renewal must have its own charge'
					).not.toBe( merchantPayment.chargeId );

					/* -- Cardinality across the whole case -- */
					const finalState = scheduledRenewal.subscription;
					expect(
						finalState.relatedOrders.renewal.toSorted(),
						'two renewals must leave exactly two renewal orders'
					).toEqual(
						[
							merchantRenewal.renewalOrderId,
							scheduledRenewal.renewalOrderId,
						].toSorted()
					);
					expect(
						finalState.paymentCount,
						'two renewals must advance the subscription exactly twice'
					).toBe( outcome.subscription.paymentCount + 2 );
					expect(
						finalState.paymentTokenIds,
						'renewals must not add or replace the recurring credential'
					).toEqual( [ token.tokenId ] );
					expect(
						finalState.id,
						'renewals must not replace the subscription'
					).toBe( outcome.subscription.id );
					expect(
						finalState.parentId,
						'renewals must not reparent the subscription'
					).toBe( outcome.parentOrderId );
					expect(
						finalState.scheduledActions.filter(
							( action ) =>
								action.actionId === seededPending[ 0 ].actionId
						),
						'the seeded action must exist exactly once after it ran'
					).toHaveLength( 1 );
					expect(
						finalState.scheduledActions.filter(
							( action ) =>
								action.hook === RENEWAL_ACTION_HOOK &&
								action.status === 'pending'
						),
						'exactly one renewal action may remain armed after two renewals'
					).toHaveLength( 1 );
					expect(
						await readCustomerSubscriptionIds( pilotRuntime ),
						'renewals must not create a second subscription'
					).toEqual(
						[
							...scope.baseline.subscriptionIds,
							outcome.subscription.id,
						].toSorted( ( left, right ) => left - right )
					);
				}
			);
		}
	);

	test(
		'one basket of two same-schedule USD 9.99 monthly subscription products creates one USD 21.97 parent order, one active subscription whose two line items are the exact purchased product IDs, one token and provider-customer graph, a single 2197 usd succeeded intent and captured charge, and zero renewal orders',
		{
			annotation: contracts( CONTRACT_S7_MULTIPLE ),
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, BASE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await withSubscriptionRun(
				pilotRuntime,
				page,
				'shopper-subscription-multiple',
				async ( scope ) => {
					const withFee = await createSubscriptionProduct(
						pilotRuntime,
						{
							slug: 's7-fee',
							price: RECURRING_PRICE,
							signUpFee: SIGNUP_FEE,
						}
					);
					scope.ledger.productIds.push( withFee.id );
					const withoutFee = await createSubscriptionProduct(
						pilotRuntime,
						{ slug: 's7-plain', price: RECURRING_PRICE }
					);
					scope.ledger.productIds.push( withoutFee.id );

					const outcome = await signUpOnce(
						pilotRuntime,
						page,
						scope,
						{
							products: [ withFee, withoutFee ],
							card: PRIMARY_CARD,
							journal: 'subscription-multiple-signup',
						}
					);

					const payment = await convergedPayment(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect( payment.orderId ).toBe( outcome.parentOrderId );
					expectSingleCapturedGraph( payment, {
						amountMinor: MULTIPLE_TOTAL_MINOR,
						paymentMethodId:
							outcome.createdTokens[ 0 ].paymentMethodId,
					} );
					expect(
						await readProviderCustomerId(
							pilotRuntime,
							payment.intentId
						)
					).toBe( outcome.providerCustomerId );

					// Line items are addressed by product ID, never by DOM or
					// array order: the client suite's recorded residual is that
					// order-sensitive iteration can misattribute items.
					const parent = await readOrderRecord(
						pilotRuntime,
						outcome.parentOrderId
					);
					expect( parent.lineItems ).toHaveLength( 2 );
					expect(
						parent.lineItems
							.map( ( item ) => item.productId )
							.toSorted( ( left, right ) => left - right ),
						'the parent order must carry exactly the two purchased products'
					).toEqual(
						[ withFee.id, withoutFee.id ].toSorted(
							( left, right ) => left - right
						)
					);
					// With the fee inside its product's line rather than on an
					// anonymous fee line, the basket is stronger evidence than
					// the contract originally asked for: the fee is
					// attributable to the exact product that carries it.
					for ( const item of parent.lineItems ) {
						expect( item.quantity ).toBe( 1 );
						expect(
							Math.round( Number( item.total ) * 100 ),
							`line total for product ${ item.productId }`
						).toBe(
							item.productId === withFee.id
								? SIGNUP_FEE_TOTAL_MINOR
								: RECURRING_MINOR
						);
					}
					expect(
						parent.feeLines,
						'a signup fee is not a WooCommerce fee line'
					).toEqual( [] );
					expect(
						Math.round( Number( parent.total ) * 100 ),
						'the parent total must be the proven provider total'
					).toBe( MULTIPLE_TOTAL_MINOR );

					// One subscription, both lines, asserted by product ID.
					expect(
						outcome.subscription.lineItems
							.map( ( item ) => item.productId )
							.toSorted( ( left, right ) => left - right ),
						'both same-schedule products must live in one subscription'
					).toEqual(
						[ withFee.id, withoutFee.id ].toSorted(
							( left, right ) => left - right
						)
					);
					expect(
						Math.round(
							Number( outcome.subscription.total ) * 100
						),
						'the subscription must renew at the sum of the recurring prices only'
					).toBe( RECURRING_MINOR * 2 );
					expect(
						outcome.subscription.relatedOrders.renewal,
						'one basket submission must create no renewal order'
					).toEqual( [] );
				}
			);
		}
	);
} );
