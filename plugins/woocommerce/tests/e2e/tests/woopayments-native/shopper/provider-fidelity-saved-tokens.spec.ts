import type { Page } from '@playwright/test';

import {
	expect,
	ResourceQuarantineRequiredError,
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
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	deleteExactSavedCards,
	findSavedCardProviderCustomerId,
	getProviderPaymentMethodIds,
	getSavedCardEvidence,
	payWithExactSavedCardOnClassicCheckout,
	readSavedCardProviderCustomerId,
	saveCardAtBlocksCheckout,
	saveCardAtClassicCheckout,
	submitNativeAddPaymentMethod,
	waitForAddPaymentMethodCooldown,
	type CheckoutSaveObservation,
	type NativeAddPaymentMethodObservation,
	type SavedCardToken,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import { waitForPaymentState } from '../../../utils/woopayments-native/provider-evidence';
import {
	getOrderPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `saved-token-lifecycle` provider-fidelity family.
 *
 * `FIDELITY-CLAIMS.md` states the claim these six cases exist to establish: a
 * `4242` SetupIntent at the real provider yields exactly one
 * payment-method-to-token relationship, that single token funds a USD 10.99
 * purchase with nothing else drawn on, deleting it returns local tokens,
 * provider attachments and defaults to their recorded baseline, a second add
 * inside WooCommerce's 20-second cooldown is rejected natively while creating
 * nothing at the provider, and the same one-to-one relationship holds when the
 * credential is minted by a purchase instead of by a SetupIntent.
 *
 * What each case adds over the client suite it replaces is identity. The client
 * tests read a card label and an expiry off My Account, which stale tokens can
 * also satisfy; every assertion here names an exact SetupIntent or PaymentIntent,
 * an exact provider payment method, an exact local token ID and an exact
 * provider customer, and asserts cardinality on both sides - the claim's
 * falsifier is "a second attachment or token appearing at either side", so a set
 * that merely contains the right member proves nothing.
 *
 * The cases run serially, and `T1`, `T2` and `T3` share one card on purpose: the
 * contract binds the reuse and deletion cases to the token the create case made,
 * and a re-created token would make "the exact `T1` token funded this purchase"
 * unprovable. A failure also stops the cases after it rather than spending more
 * provider budget on a store whose state is no longer described.
 *
 * Surface boundary, which is the whole reason this family has six cases rather
 * than four. `T1`, `T1b` and `T3` drive the My Account saved-methods surface
 * (`WC_Form_Handler::add_payment_method_action` plus
 * `NativeWooPaymentsGateway::add_payment_method`, which reads a SetupIntent).
 * `T2` spends the resulting token on the Classic checkout. `T4` and `T5` mint a
 * credential the other way, from the PaymentIntent of a purchase, through
 * `WooPaymentsOrderEffectApplier` - `T4` on the Classic surface and `T5` on
 * Blocks, which is a third native path with its own payment element, submission
 * route and save control. None of these three surfaces is evidence for another,
 * and no case here may be read as covering a surface it does not drive.
 */

const FAMILY_TAG = '@fidelity:saved-token-lifecycle';

const CONTRACT_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:146::Shopper can save and delete cards › Testing card: basic › should add the basic card as a new payment method';
const CONTRACT_COOLDOWN =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:105::Shopper can save and delete cards › prevents adding another card for 20 seconds after a card is added';
const CONTRACT_PURCHASE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:196::Shopper can save and delete cards › Testing card: basic › should be able to purchase with the saved basic card';
const CONTRACT_DELETE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:249::Shopper can save and delete cards › Testing card: basic › should be able to delete basic card';
const CONTRACT_CLASSIC_CHECKOUT_SAVE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:68::Saved cards › When using a basic card added on checkout › should save the card';
const CONTRACT_CHECKOUT_DELETE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:109::Saved cards › When using a basic card added on checkout › should delete the card';
const CONTRACT_BLOCKS_CHECKOUT_SAVE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-saved-card-checkout-and-usage.spec.ts:46::WooCommerce Blocks › Saved cards › should be able to save basic card on Blocks checkout';

/**
 * The two cards the client fixtures use for this journey. They stay written out
 * here for the reason `utils/woopayments-native/test-cards.ts` records: that
 * module carries cards whose *behaviour* the provider selects, and neither of
 * these selects anything - both are ordinary reusable Visas. The second card
 * exists so the rejected cooldown attempt cannot be confused with the first,
 * on the surface as well as in the records.
 */
const BASIC_CARD: ProviderTestCard = {
	number: '4242424242424242',
	expiry: '0245',
	securityCode: '424',
};
const SECOND_CARD: ProviderTestCard = {
	number: '4111111111111111',
	expiry: '1145',
	securityCode: '123',
};

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

/**
 * Native's own copy when its setup-intent bridge refuses an add inside
 * WooCommerce's rate-limit window
 * (`WooPaymentsCheckoutAjaxController::get_create_setup_intent_response`). The
 * refusal happens before the provider is asked for a SetupIntent, which is why
 * the interval that follows must stay empty at the provider.
 */
const COOLDOWN_REJECTION_TEXT =
	'You cannot add a new payment method so soon after the previous one. Please try again later.';

/** The claim's convergence rule: poll every 2 seconds. */
const POLL_INTERVAL_MS = 2_000;
/** Two consecutive reads, 2 seconds apart, must agree. */
const CONVERGENCE_WINDOW_MS = 2_000;
/** The claim requires at least a 10-second empty provider interval. */
const EMPTY_INTERVAL_MS = 10_000;
const SETTLEMENT_TIMEOUT_MS = 60_000;

/*
 * Every case preflights the capabilities it can reach, including the ones its
 * failure path reaches: a cleanup that discovers it was never approved leaves
 * the credential it was meant to remove.
 */
const ADD_CAPABILITIES = [ 'saved-card-add', 'saved-card-cleanup' ];
const PURCHASE_CAPABILITIES = [
	'product/payment',
	'classic-checkout-page',
	'saved-card-classic',
	'saved-card-cleanup',
];
const DELETE_CAPABILITIES = [ 'saved-card-cleanup' ];
const CLASSIC_SAVE_CAPABILITIES = [
	'product/payment',
	'classic-checkout-page',
	'basic-card',
	'basic-card-entry',
	'saved-card-cleanup',
];
const BLOCKS_SAVE_CAPABILITIES = [
	'product/payment',
	'basic-card',
	'basic-card-entry',
	'saved-card-cleanup',
];

/**
 * What the shopper's saved-method state looked like before this run touched it.
 *
 * `providerCustomerId` is absent when the store could disclose none at the time
 * of the reading - a shopper who has never reached the provider has no customer
 * to name, and a store whose evidence route predates the unnamed disclosure
 * cannot name one before the shopper holds a default token. The create case
 * says what each of those two states allows it to assert.
 */
interface SavedTokenBaseline {
	tokens: SavedCardToken[];
	providerCustomerId: string | undefined;
	providerAttachments: string[] | undefined;
}

let runCard: SavedCardIdentity | undefined;
let runProviderCustomerId: string | undefined;
let runBaseline: SavedTokenBaseline | undefined;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

/**
 * Asserts the whole capability set before any provider interval opens, so an
 * incomplete approval costs nothing rather than a paid run.
 */
function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: readonly string[]
): void {
	for ( const capability of capabilities ) {
		session.requireApprovedProviderFixture( capability );
	}
}

async function readBaseline(
	session: ProviderWriteSession
): Promise< SavedTokenBaseline > {
	const evidence = await getSavedCardEvidence( session );
	const providerCustomerId = await findSavedCardProviderCustomerId( session );
	if ( ! providerCustomerId ) {
		return {
			tokens: evidence.tokens,
			providerCustomerId: undefined,
			providerAttachments: undefined,
		};
	}

	return {
		tokens: evidence.tokens,
		providerCustomerId,
		providerAttachments: await getProviderPaymentMethodIds(
			session,
			providerCustomerId
		),
	};
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

/**
 * Requires the provider's attachment set for one customer to stay exactly what
 * it is for a whole interval, reading it every two seconds.
 *
 * Used both as the claim's two-read convergence check and as its empty-interval
 * check after a rejection: an attachment that appears one poll after the store
 * answered is the falsifier this family exists to catch.
 */
async function expectStableProviderAttachments(
	session: ProviderWriteSession,
	providerCustomerId: string,
	expected: readonly string[],
	durationMs: number,
	reason: string
): Promise< void > {
	const wanted = [ ...expected ].toSorted();
	const deadline = Date.now() + durationMs;
	for (;;) {
		const observed = (
			await getProviderPaymentMethodIds( session, providerCustomerId )
		 ).toSorted();
		expect( observed, reason ).toEqual( wanted );
		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			return;
		}
		await delay( Math.min( POLL_INTERVAL_MS, remaining ) );
	}
}

async function expectStableLocalTokens(
	session: ProviderWriteSession,
	expected: readonly SavedCardToken[],
	reason: string
): Promise< void > {
	const observed = await getSavedCardEvidence( session );
	expect( tokenIdentities( observed.tokens ), reason ).toEqual(
		tokenIdentities( expected )
	);
}

/**
 * The provider customer this run works against, proved to be the same one the
 * baseline named whenever the baseline could name one.
 */
async function resolveRunProviderCustomer(
	session: ProviderWriteSession,
	baseline: SavedTokenBaseline
): Promise< string > {
	const providerCustomerId = await readSavedCardProviderCustomerId( session );
	if ( baseline.providerCustomerId !== undefined ) {
		expect(
			providerCustomerId,
			'the run must work against the same provider customer the baseline named'
		).toBe( baseline.providerCustomerId );
	}
	return providerCustomerId;
}

/**
 * The attachment set an added method must produce.
 *
 * When the baseline was readable the expectation is exactly the baseline plus
 * the one new method. When it was not - no provider customer could be named
 * before the first card existed - the expectation is that the one new method is
 * the only attachment there is: a shopper whose provider customer holds a card
 * that no local token stores is carrying an unowned attachment, which this
 * claim's cleanup rule treats as a failure rather than as background.
 */
function expectedAttachmentsAfterAdd(
	baseline: SavedTokenBaseline,
	addedPaymentMethodIds: readonly string[]
): string[] {
	return [
		...( baseline.providerAttachments ?? [] ),
		...addedPaymentMethodIds,
	];
}

/**
 * One `create_setup_intent` exchange that succeeded, and the single form
 * submission that carried its exact ID to `add_payment_method()`.
 *
 * This is the SetupIntent-to-token join the claim rests on: native reads the
 * submitted SetupIntent from the provider and creates the token from that
 * intent's payment method, so a token whose method is not the method this
 * intent settled cannot exist without one of these assertions failing.
 */
function expectAcceptedSetupIntent(
	observation: NativeAddPaymentMethodObservation
): string {
	expect(
		observation.setupIntentExchanges,
		'one add must ask the setup-intent bridge exactly once'
	).toHaveLength( 1 );
	const [ exchange ] = observation.setupIntentExchanges;
	expect( exchange.httpStatus ).toBe( 200 );
	expect( exchange.errorMessage ).toBe( '' );
	expect(
		exchange.setupIntentId,
		'the bridge must name the exact SetupIntent it created'
	).toMatch( /^seti_/ );
	expect(
		exchange.setupIntentStatus,
		'a 4242 SetupIntent must succeed at the provider'
	).toBe( 'succeeded' );

	expect(
		observation.formSubmissionCount,
		'one add gesture must submit the add-payment-method form exactly once'
	).toBe( 1 );
	expect(
		observation.submittedSetupIntentIds,
		'the submission must carry that exact SetupIntent and no other'
	).toEqual( [ exchange.setupIntentId ] );
	expect( observation.successNoticeVisible ).toBe( true );
	expect(
		observation.createdCards,
		'one succeeded SetupIntent must create exactly one local token'
	).toHaveLength( 1 );

	return exchange.setupIntentId;
}

/**
 * Every token that existed before the add still maps to the same provider
 * method afterwards.
 */
function expectUnrelatedTokensPreserved(
	observation: NativeAddPaymentMethodObservation
): void {
	for ( const token of observation.tokensBefore ) {
		expect(
			observation.tokensAfter.find(
				( candidate ) => candidate.tokenId === token.tokenId
			)?.paymentMethodId,
			'an unrelated stored token must not be remapped'
		).toBe( token.paymentMethodId );
	}
	expect(
		observation.tokensAfter,
		'an add must not remove a stored token'
	).toHaveLength(
		observation.tokensBefore.length + observation.createdCards.length
	);
}

/**
 * One save-carrying checkout submission created exactly one local token, and
 * disturbed no other.
 */
function expectSavedAtCheckout(
	observation: CheckoutSaveObservation
): SavedCardIdentity {
	expect(
		observation.checkoutRequestCount,
		'one Place order activation must ask the store exactly once'
	).toBe( 1 );
	expect(
		observation.createdCards,
		'one save-carrying checkout must create exactly one local token'
	).toHaveLength( 1 );
	for ( const token of observation.tokensBefore ) {
		expect(
			observation.tokensAfter.find(
				( candidate ) => candidate.tokenId === token.tokenId
			)?.paymentMethodId,
			'an unrelated stored token must not be remapped'
		).toBe( token.paymentMethodId );
	}
	expect(
		observation.tokensAfter,
		'a save-carrying checkout must not remove a stored token'
	).toHaveLength( observation.tokensBefore.length + 1 );

	return observation.createdCards[ 0 ];
}

/**
 * The provider graph one USD 10.99 native purchase must leave: one intent, one
 * captured charge, one capture, one paid order, and nothing else.
 */
function expectSettledPurchaseGraph(
	payment: PaymentEvidence,
	runId: string
): void {
	expect( payment.runId ).toBe( runId );
	expect( payment.amountMinor ).toBe( AMOUNT_MINOR );
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
}

/**
 * Removes whatever a save-at-checkout case created before rethrowing its
 * failure. Unlike the shared card, these are always the case's own, so the
 * caller passes them in rather than reading run state.
 */
async function releaseCheckoutCardsAfterFailure(
	session: ProviderWriteSession,
	page: Page,
	cards: readonly SavedCardIdentity[],
	failure: unknown
): Promise< never > {
	if ( cards.length === 0 ) {
		throw failure;
	}

	try {
		const providerCustomerId = await findSavedCardProviderCustomerId(
			session
		);
		if ( ! providerCustomerId ) {
			throw new Error(
				'No provider customer could be named, so detachment cannot be proven.'
			);
		}
		await deleteExactSavedCards( session, page, cards, providerCustomerId );
	} catch ( cleanupFailure ) {
		throw new ResourceQuarantineRequiredError(
			'A save-at-checkout case failed and the removal of the card it created failed too.',
			'cleanup-failed',
			new AggregateError(
				[
					failure,
					cleanupFailure instanceof ResourceQuarantineRequiredError
						? cleanupFailure.primaryError ?? cleanupFailure
						: cleanupFailure,
				],
				'A save-at-checkout case failed and the removal of the card it created failed too.',
				{ cause: cleanupFailure }
			)
		);
	}
	throw failure;
}

function requireRunCard(): SavedCardIdentity {
	if ( ! runCard ) {
		throw new Error(
			'This case reuses the token the create case saved; run the family in order.'
		);
	}
	return runCard;
}

function requireRunProviderCustomer(): string {
	if ( ! runProviderCustomerId ) {
		throw new Error(
			'This case needs the provider customer the create case resolved; run the family in order.'
		);
	}
	return runProviderCustomerId;
}

function requireRunBaseline(): SavedTokenBaseline {
	if ( ! runBaseline ) {
		throw new Error(
			'This case compares against the baseline the create case recorded; run the family in order.'
		);
	}
	return runBaseline;
}

/**
 * Removes the run's shared card after a case failed, so a failure cannot leave
 * a live credential on the standing shopper, and reports both failures when the
 * removal fails too.
 */
async function releaseRunCardAfterFailure(
	session: ProviderWriteSession,
	page: Page,
	failure: unknown
): Promise< never > {
	const card = runCard;
	const providerCustomerId = runProviderCustomerId;
	if ( ! card || ! providerCustomerId ) {
		throw failure;
	}
	runCard = undefined;

	try {
		await deleteExactSavedCards(
			session,
			page,
			[ card ],
			providerCustomerId
		);
	} catch ( cleanupFailure ) {
		throw new ResourceQuarantineRequiredError(
			'A saved-token case failed and the removal of the card it shared failed too.',
			'cleanup-failed',
			new AggregateError(
				[
					failure,
					cleanupFailure instanceof ResourceQuarantineRequiredError
						? cleanupFailure.primaryError ?? cleanupFailure
						: cleanupFailure,
				],
				'A saved-token case failed and the removal of the card it shared failed too.',
				{ cause: cleanupFailure }
			)
		);
	}
	throw failure;
}

/**
 * Waits for one order to carry a settled provider payment and returns the whole
 * joined record.
 */
async function readSettledPayment(
	session: ProviderWriteSession,
	orderId: number
): Promise< PaymentEvidence > {
	const deadline = Date.now() + SETTLEMENT_TIMEOUT_MS;
	let lastError: unknown;
	for (;;) {
		try {
			const order = await getOrderPaymentEvidence(
				session.adminApi,
				orderId
			);
			return await waitForPaymentState(
				session.adminApi,
				{ orderId, intentId: order.intentId },
				'succeeded',
				deadline
			);
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

test.describe( 'WooPayments native saved-token lifecycle fidelity', () => {
	// Serial on purpose: the cases share the one card the contract binds them
	// to, and a failure must stop the family rather than drive more provider
	// writes against a store whose state is no longer described.
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test(
		'one My Account card save creates exactly one succeeded SetupIntent, one provider attachment, and one Woo token that stores that exact method',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_ADD,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, ADD_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-create' },
				async () => {
					const baseline = await readBaseline( pilotRuntime );
					runBaseline = baseline;
					const highestOrderId = await readHighestOrderId(
						pilotRuntime
					);

					const observation = await submitNativeAddPaymentMethod(
						pilotRuntime,
						page,
						{
							card: BASIC_CARD,
							journal: 'saved-token-add',
							expectation: 'accepted',
							logInAsCustomer: true,
						}
					);
					// Recorded before the assertions so a failing assertion is
					// not the reason a live credential survives the run.
					runCard = observation.createdCards[ 0 ];

					try {
						expectAcceptedSetupIntent( observation );
						expectUnrelatedTokensPreserved( observation );
						const created = requireRunCard();

						runProviderCustomerId =
							await resolveRunProviderCustomer(
								pilotRuntime,
								baseline
							);
						const attached = await getProviderPaymentMethodIds(
							pilotRuntime,
							runProviderCustomerId
						);
						expect(
							attached.filter(
								( id ) => id === created.paymentMethodId
							),
							'the provider must hold exactly one attachment of the saved method'
						).toHaveLength( 1 );
						expect(
							attached.toSorted(),
							'the save must add exactly one attachment and remove none'
						).toEqual(
							expectedAttachmentsAfterAdd( baseline, [
								created.paymentMethodId,
							] ).toSorted()
						);

						// Convergence: the same terminal identities twice, two
						// seconds apart, on both sides of the relationship.
						await expectStableProviderAttachments(
							pilotRuntime,
							runProviderCustomerId,
							attached,
							CONVERGENCE_WINDOW_MS,
							'the attachment set must be terminal, not still settling'
						);
						await expectStableLocalTokens(
							pilotRuntime,
							observation.tokensAfter,
							'the local token set must be terminal, not still settling'
						);

						// A SetupIntent is not a payment: this save must have
						// created no order, and with no order there is no
						// PaymentIntent, charge or capture for it to carry.
						const orders = await readOrderDeltaAfter(
							pilotRuntime,
							highestOrderId
						);
						expect(
							orders.newOrderIds,
							'saving a card must create no order'
						).toEqual( [] );
					} catch ( error ) {
						await releaseRunCardAfterFailure(
							pilotRuntime,
							page,
							error
						);
					}
				}
			);
		}
	);

	test(
		'a second My Account add inside the 20-second cooldown is rejected natively and attaches nothing at the provider, and a normal add after it attaches and deletes cleanly',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_COOLDOWN,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			// This case waits out a real 20-second rate limit and a 10-second
			// empty provider interval on top of three add journeys, so it is
			// given more room than the rest of the family.
			test.setTimeout( 420_000 );
			requireCapabilities( pilotRuntime, ADD_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-cooldown' },
				async () => {
					const sharedCard = requireRunCard();
					const providerCustomerId = requireRunProviderCustomer();
					const before = await getSavedCardEvidence( pilotRuntime );
					const attachmentsBefore = await getProviderPaymentMethodIds(
						pilotRuntime,
						providerCustomerId
					);
					const highestOrderId = await readHighestOrderId(
						pilotRuntime
					);
					const created: SavedCardIdentity[] = [];

					try {
						// The cooldown is anchored on a real save, exactly as
						// the contract states, and this case makes its own
						// rather than borrowing the create case's: Playwright
						// fixture teardown and setup between two tests can
						// consume most of a 20-second window, and a window that
						// quietly expired would turn this contract into a
						// second attached card.
						const anchor = await submitNativeAddPaymentMethod(
							pilotRuntime,
							page,
							{
								card: BASIC_CARD,
								journal: 'saved-token-cooldown-anchor',
								expectation: 'accepted',
								logInAsCustomer: true,
							}
						);
						created.push( ...anchor.createdCards );
						expectAcceptedSetupIntent( anchor );

						const anchoredTokens = anchor.tokensAfter;
						const anchoredAttachments =
							await getProviderPaymentMethodIds(
								pilotRuntime,
								providerCustomerId
							);
						expect(
							anchoredAttachments.toSorted(),
							'the anchoring save must add exactly its own method'
						).toEqual(
							[
								...attachmentsBefore,
								anchor.createdCards[ 0 ].paymentMethodId,
							].toSorted()
						);

						// The contract: one more add attempt, inside the
						// window. The driver refuses to click at all unless
						// WooCommerce still reports the shopper rate-limited,
						// so a passed window costs no provider write.
						const blocked = await submitNativeAddPaymentMethod(
							pilotRuntime,
							page,
							{
								card: SECOND_CARD,
								journal: 'saved-token-cooldown-blocked',
								expectation: 'rejected',
							}
						);
						created.push( ...blocked.createdCards );

						expect(
							blocked.paymentError.visible,
							'the shopper must be told the add was refused'
						).toBe( true );
						expect(
							blocked.paymentError.role,
							'the refusal must be announced, not only drawn'
						).toBe( 'alert' );
						expect( blocked.paymentError.text ).toBe(
							COOLDOWN_REJECTION_TEXT
						);
						expect(
							blocked.setupIntentExchanges,
							'the blocked attempt must reach the setup-intent bridge exactly once'
						).toHaveLength( 1 );
						expect(
							blocked.setupIntentExchanges[ 0 ].httpStatus,
							'native must refuse with its rate-limit status'
						).toBe( 429 );
						expect(
							blocked.setupIntentExchanges[ 0 ].setupIntentId,
							'a refused attempt must produce no SetupIntent'
						).toBe( '' );
						expect(
							blocked.setupIntentExchanges[ 0 ].errorMessage
						).toBe( COOLDOWN_REJECTION_TEXT );
						expect(
							blocked.formSubmissionCount,
							'a refused attempt must never submit the add form'
						).toBe( 0 );
						expect(
							blocked.createdCards,
							'a refused attempt must create no local token'
						).toEqual( [] );

						// The claim's convergence rule for a rejection: the
						// provider attachment set for this customer stays empty
						// of new members for at least ten seconds. The browser
						// creates an unattached provider payment method before
						// native answers, which is outside the customer's
						// attachment graph and outside this claim; what must
						// not exist is a second attachment, method-to-token
						// relationship or order.
						await expectStableProviderAttachments(
							pilotRuntime,
							providerCustomerId,
							anchoredAttachments,
							EMPTY_INTERVAL_MS,
							'a refused add must attach nothing at the provider'
						);
						await expectStableLocalTokens(
							pilotRuntime,
							anchoredTokens,
							'a refused add must store no token'
						);

						// And a normal add still works once the window closes.
						await waitForAddPaymentMethodCooldown( pilotRuntime );
						const recovered = await submitNativeAddPaymentMethod(
							pilotRuntime,
							page,
							{
								card: BASIC_CARD,
								journal: 'saved-token-cooldown-recovery',
								expectation: 'accepted',
							}
						);
						created.push( ...recovered.createdCards );
						expectAcceptedSetupIntent( recovered );
						expectUnrelatedTokensPreserved( recovered );
						const recoveredAttachments =
							await getProviderPaymentMethodIds(
								pilotRuntime,
								providerCustomerId
							);
						expect(
							recoveredAttachments.toSorted(),
							'the post-cooldown save must add exactly its own method'
						).toEqual(
							[
								...anchoredAttachments,
								recovered.createdCards[ 0 ].paymentMethodId,
							].toSorted()
						);

						const orders = await readOrderDeltaAfter(
							pilotRuntime,
							highestOrderId
						);
						expect(
							orders.newOrderIds,
							'neither a refused nor an accepted add may create an order'
						).toEqual( [] );

						// Both cards this case created are deleted and proven
						// gone, which is the rest of its contract; the card the
						// family shares is deliberately untouched.
						await deleteExactSavedCards(
							pilotRuntime,
							page,
							created,
							providerCustomerId
						);
						created.length = 0;
						await delay( CONVERGENCE_WINDOW_MS );
						await expectStableLocalTokens(
							pilotRuntime,
							before.tokens,
							'the cooldown case must leave the local token set exactly as it found it'
						);
						await expectStableProviderAttachments(
							pilotRuntime,
							providerCustomerId,
							attachmentsBefore,
							CONVERGENCE_WINDOW_MS,
							'the cooldown case must leave the provider attachment set exactly as it found it'
						);
						expect(
							(
								await getSavedCardEvidence( pilotRuntime )
							 ).tokens.map( ( token ) => token.tokenId ),
							'the token the family shares must survive this case untouched'
						).toContain( sharedCard.tokenId );
					} catch ( error ) {
						// Anything this case created and did not yet delete
						// goes first, then the shared card, so a failed cooldown
						// case cannot leave live credentials behind.
						try {
							await deleteExactSavedCards(
								pilotRuntime,
								page,
								created,
								providerCustomerId
							);
						} catch ( cleanupFailure ) {
							throw new ResourceQuarantineRequiredError(
								'The cooldown case failed and the removal of the cards it created failed too.',
								'cleanup-failed',
								new AggregateError(
									[ error, cleanupFailure ],
									'The cooldown case failed and the removal of the cards it created failed too.',
									{ cause: cleanupFailure }
								)
							);
						}
						await releaseRunCardAfterFailure(
							pilotRuntime,
							page,
							error
						);
					}
				}
			);
		}
	);

	test(
		'a classic checkout paid with the exact saved token draws on that token alone and settles one USD 10.99 intent, charge, and capture',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_PURCHASE,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, PURCHASE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-purchase' },
				async () => {
					const card = requireRunCard();
					const providerCustomerId = requireRunProviderCustomer();
					const baseline = requireRunBaseline();
					const before = await getSavedCardEvidence( pilotRuntime );
					const attachmentsBefore = await getProviderPaymentMethodIds(
						pilotRuntime,
						providerCustomerId
					);

					try {
						await withClassicCheckoutPage(
							pilotRuntime,
							pilotRuntime.runId,
							async ( scope ) => {
								await pilotRuntime.logInAsCustomer( page );
								const product =
									await pilotRuntime.createOwnedProduct(
										PRICE
									);
								const purchase =
									await payWithExactSavedCardOnClassicCheckout(
										pilotRuntime,
										page,
										{
											card,
											product,
											checkout: scope.classicCheckout,
											runId: pilotRuntime.runId,
											journal:
												'saved-token-classic-purchase',
										}
									);

								expect(
									purchase.checkoutRequestCount,
									'one Place order activation must ask the store exactly once'
								).toBe( 1 );
								expect( purchase.checkoutResponseCount ).toBe(
									1
								);
								expect( purchase.selectedTokenId ).toBe(
									card.tokenId
								);

								const payment = await readSettledPayment(
									pilotRuntime,
									purchase.orderId
								);
								expect( payment.orderId ).toBe(
									purchase.orderId
								);
								expect( payment.orderKey ).toBe(
									purchase.orderKey
								);
								expectSettledPurchaseGraph(
									payment,
									pilotRuntime.runId
								);

								// The point of the case: the exact stored token
								// funded it, and nothing else could have.
								expect(
									payment.paymentMethodId,
									'the purchase must be funded by the exact saved method'
								).toBe( card.paymentMethodId );
								expect(
									baseline.tokens.map(
										( token ) => token.paymentMethodId
									),
									'no pre-existing stored credential may fund this purchase'
								).not.toContain( payment.paymentMethodId );
								expect(
									await readProviderCustomerId(
										pilotRuntime,
										payment.intentId
									),
									'the intent must be drawn on this run shopper provider customer'
								).toBe( providerCustomerId );

								// Convergence, then cardinality on both sides:
								// spending a stored token creates no second
								// token and no second attachment.
								await delay( CONVERGENCE_WINDOW_MS );
								const confirmed = await readSettledPayment(
									pilotRuntime,
									purchase.orderId
								);
								expect(
									confirmed,
									'two consecutive reads must return the same terminal payment'
								).toEqual( payment );
								await expectStableLocalTokens(
									pilotRuntime,
									before.tokens,
									'paying with a stored token must create no second token'
								);
								await expectStableProviderAttachments(
									pilotRuntime,
									providerCustomerId,
									attachmentsBefore,
									CONVERGENCE_WINDOW_MS,
									'paying with a stored token must create no second attachment'
								);
							}
						);
					} catch ( error ) {
						await releaseRunCardAfterFailure(
							pilotRuntime,
							page,
							error
						);
					}
				}
			);
		}
	);

	test(
		'deleting the saved token removes that exact Woo token, detaches its provider method, and leaves every baseline token and default unchanged',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DELETE,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, DELETE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-delete' },
				async () => {
					const card = requireRunCard();
					const providerCustomerId = requireRunProviderCustomer();
					const baseline = requireRunBaseline();
					const highestOrderId = await readHighestOrderId(
						pilotRuntime
					);

					// The deletion is the contract, so its own proof - the
					// token gone locally and the method detached at the
					// provider - is inside this driver rather than in a
					// cleanup step after it.
					await deleteExactSavedCards(
						pilotRuntime,
						page,
						[ card ],
						providerCustomerId
					);
					runCard = undefined;

					// The claim asks for two absent reads two seconds apart.
					await delay( CONVERGENCE_WINDOW_MS );
					const after = await getSavedCardEvidence( pilotRuntime );
					expect(
						after.tokens.map( ( token ) => token.tokenId ),
						'the deleted local token must stay absent'
					).not.toContain( card.tokenId );
					expect(
						after.tokens.map( ( token ) => token.paymentMethodId ),
						'no token may still store the detached method'
					).not.toContain( card.paymentMethodId );
					expect(
						await getProviderPaymentMethodIds(
							pilotRuntime,
							providerCustomerId
						),
						'the provider must stay detached from the deleted method'
					).not.toContain( card.paymentMethodId );

					// Restoration, read back rather than assumed: every token
					// the shopper had before this run, with the same provider
					// method and the same default flag, and no attachment this
					// run did not find.
					//
					// One half of the claim's "defaults" is out of reach and
					// deliberately not claimed. When the shopper held no token
					// at all, WooCommerce makes the first one default
					// (`WC_Payment_Token_Data_Store::create()`), and native
					// mirrors that to the provider customer's
					// `invoice_settings.default_payment_method`
					// (`WooPaymentsTokenService::handle_woocommerce_payment_token_set_default`).
					// The store exposes no route that reads a provider
					// customer back, only its attached methods, so this case
					// proves the local default and the detachment and says
					// nothing about the remote default field.
					await expectStableLocalTokens(
						pilotRuntime,
						baseline.tokens,
						'deletion must return the local token set and its default to the recorded baseline'
					);
					await expectStableProviderAttachments(
						pilotRuntime,
						providerCustomerId,
						baseline.providerAttachments ?? [],
						CONVERGENCE_WINDOW_MS,
						'deletion must return the provider attachment set to the recorded baseline'
					);

					const orders = await readOrderDeltaAfter(
						pilotRuntime,
						highestOrderId
					);
					expect(
						orders.newOrderIds,
						'deleting a token must create no order'
					).toEqual( [] );
				}
			);
		}
	);

	test(
		'a classic checkout that saves the card leaves one paid USD 10.99 graph and exactly one provider attachment stored by exactly one new token, and deletes that token cleanly',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_CLASSIC_CHECKOUT_SAVE,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_CHECKOUT_DELETE,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, CLASSIC_SAVE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-classic-save' },
				async () => {
					// Read fresh rather than reusing the create case's
					// baseline: this case is about a different native path and
					// must stand on its own if it is ever run alone.
					const baseline = await readBaseline( pilotRuntime );
					const created: SavedCardIdentity[] = [];

					try {
						await withClassicCheckoutPage(
							pilotRuntime,
							pilotRuntime.runId,
							async ( scope ) => {
								await pilotRuntime.logInAsCustomer( page );
								const product =
									await pilotRuntime.createOwnedProduct(
										PRICE
									);
								const saved = await saveCardAtClassicCheckout(
									pilotRuntime,
									page,
									{
										card: BASIC_CARD,
										product,
										checkout: scope.classicCheckout,
										runId: pilotRuntime.runId,
										journal: 'saved-token-classic-save',
									}
								);
								created.push( ...saved.createdCards );

								const card = expectSavedAtCheckout( saved );
								const payment = await readSettledPayment(
									pilotRuntime,
									saved.orderId
								);
								expect( payment.orderId ).toBe( saved.orderId );
								expect( payment.orderKey ).toBe(
									saved.orderKey
								);
								expectSettledPurchaseGraph(
									payment,
									pilotRuntime.runId
								);
								// The saved credential is the one the payment
								// used, not merely one that appeared alongside
								// it.
								expect(
									card.paymentMethodId,
									'the stored token must hold the method this payment used'
								).toBe( payment.paymentMethodId );

								const providerCustomerId =
									await resolveRunProviderCustomer(
										pilotRuntime,
										baseline
									);
								expect(
									await readProviderCustomerId(
										pilotRuntime,
										payment.intentId
									),
									'the intent must be drawn on this run shopper provider customer'
								).toBe( providerCustomerId );
								const attached =
									await getProviderPaymentMethodIds(
										pilotRuntime,
										providerCustomerId
									);
								expect(
									attached.filter(
										( id ) => id === card.paymentMethodId
									),
									'the provider must hold exactly one attachment of the saved method'
								).toHaveLength( 1 );
								expect(
									attached.toSorted(),
									'one save-carrying purchase must add exactly one attachment and remove none'
								).toEqual(
									expectedAttachmentsAfterAdd( baseline, [
										card.paymentMethodId,
									] ).toSorted()
								);

								// The deletion half, which is this case's
								// second contract: a checkout-origin token
								// deletes as cleanly as a My Account one.
								await deleteExactSavedCards(
									pilotRuntime,
									page,
									[ card ],
									providerCustomerId
								);
								created.length = 0;
								await delay( CONVERGENCE_WINDOW_MS );
								const after = await getSavedCardEvidence(
									pilotRuntime
								);
								expect(
									after.tokens.map(
										( token ) => token.tokenId
									),
									'the deleted checkout-origin token must stay absent'
								).not.toContain( card.tokenId );
								expect(
									after.tokens.map(
										( token ) => token.paymentMethodId
									),
									'no token may still store the detached method'
								).not.toContain( card.paymentMethodId );
								await expectStableLocalTokens(
									pilotRuntime,
									baseline.tokens,
									'deletion must return the local token set and its default to the recorded baseline'
								);
								await expectStableProviderAttachments(
									pilotRuntime,
									providerCustomerId,
									baseline.providerAttachments ?? [],
									CONVERGENCE_WINDOW_MS,
									'deletion must return the provider attachment set to the recorded baseline'
								);
							}
						);
					} catch ( error ) {
						await releaseCheckoutCardsAfterFailure(
							pilotRuntime,
							page,
							created,
							error
						);
					}
				}
			);
		}
	);

	test(
		'a Blocks checkout that saves the card leaves one paid USD 10.99 graph and exactly one provider attachment stored by exactly one new token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_BLOCKS_CHECKOUT_SAVE,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, BLOCKS_SAVE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-blocks-save' },
				async () => {
					const baseline = await readBaseline( pilotRuntime );
					const created: SavedCardIdentity[] = [];
					let scenarioFailure: unknown;

					try {
						await pilotRuntime.logInAsCustomer( page );
						const product = await pilotRuntime.createOwnedProduct(
							PRICE
						);
						const saved = await saveCardAtBlocksCheckout(
							pilotRuntime,
							page,
							{
								card: BASIC_CARD,
								product,
								runId: pilotRuntime.runId,
								journal: 'saved-token-blocks-save',
							}
						);
						created.push( ...saved.createdCards );

						const card = expectSavedAtCheckout( saved );
						const payment = await readSettledPayment(
							pilotRuntime,
							saved.orderId
						);
						expect( payment.orderId ).toBe( saved.orderId );
						expectSettledPurchaseGraph(
							payment,
							pilotRuntime.runId
						);
						expect(
							card.paymentMethodId,
							'the stored token must hold the method this payment used'
						).toBe( payment.paymentMethodId );

						const providerCustomerId =
							await resolveRunProviderCustomer(
								pilotRuntime,
								baseline
							);
						expect(
							await readProviderCustomerId(
								pilotRuntime,
								payment.intentId
							),
							'the intent must be drawn on this run shopper provider customer'
						).toBe( providerCustomerId );
						const attached = await getProviderPaymentMethodIds(
							pilotRuntime,
							providerCustomerId
						);
						expect(
							attached.filter(
								( id ) => id === card.paymentMethodId
							),
							'the provider must hold exactly one attachment of the saved method'
						).toHaveLength( 1 );
						expect(
							attached.toSorted(),
							'one save-carrying purchase must add exactly one attachment and remove none'
						).toEqual(
							expectedAttachmentsAfterAdd( baseline, [
								card.paymentMethodId,
							] ).toSorted()
						);
					} catch ( error ) {
						scenarioFailure = error;
					}

					// Cleanup, not a contract: the checkout-origin deletion
					// contract belongs to the Classic case above. This only
					// removes what this case created and proves the provider
					// let go of it.
					try {
						await deleteExactSavedCards(
							pilotRuntime,
							page,
							created,
							baseline.providerCustomerId ??
								( await findSavedCardProviderCustomerId(
									pilotRuntime
								) )
						);
					} catch ( cleanupFailure ) {
						throw new ResourceQuarantineRequiredError(
							'The Blocks save case could not remove the card it created.',
							'cleanup-failed',
							scenarioFailure === undefined
								? cleanupFailure
								: new AggregateError(
										[ scenarioFailure, cleanupFailure ],
										'The Blocks save case failed and the removal of the card it created failed too.',
										{ cause: cleanupFailure }
								  )
						);
					}

					if ( scenarioFailure !== undefined ) {
						throw scenarioFailure;
					}
				}
			);
		}
	);
} );
