import type { APIRequestContext, APIResponse } from '@playwright/test';

import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { withCapturedCardTestingProtectionState } from '../../../utils/woopayments-native/drivers/card-testing-protection';
import {
	prepareClassicCardCheckout,
	readFailedAuthenticationIntent,
	readHighestOrderId,
	readOrderDeltaAfter,
	readProviderCustomerId,
	readProviderCustomerPaymentMethodIds,
	readSettledClassicPayment,
	submitClassicCardAuthentication,
	submitClassicTokenlessCheckout,
	type ClassicCardAuthenticationOutcome,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	deleteExactSavedCards,
	getSavedCardEvidence,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import type { PaymentEvidence } from '../../../utils/woopayments-native/record-evidence';
import {
	THREE_DS_2_CARD,
	THREE_DS_DECLINED_CARD,
} from '../../../utils/woopayments-native/test-cards';

/**
 * Proves native's Classic checkout carries a real 3D Secure challenge through
 * to the exact provider records it claims, and turns away what it must.
 *
 * The shipped Blocks journey (`shopper/card-authentication.spec.ts`) does not
 * cover these four contracts. Native's Classic surface is a different
 * integration: `WooPaymentsCheckoutBridge` renders it, `woopayments-checkout.js`
 * drives it, the confirmation arrives as a hash the page consumes after the
 * checkout response rather than inside it, and failures land in native's own
 * `role="alert"` region instead of the Blocks notice and `wp.a11y`. No decision
 * authorizes treating one surface as evidence for the other, and one of these
 * rows names "one-shot Classic payment/challenge drivers" as its unlock
 * condition, so the surface is driven directly here.
 *
 * What each test adds over the client suite it replaces is the correlation the
 * ledger records as missing: the PaymentIntent is read in its customer-action
 * state before the challenge is answered, and again after, so the challenge and
 * the settled charge are provably the same payment. A frictionless or absent
 * challenge fails the run rather than passing it.
 *
 * Card choice. The client fixtures split these rows across `3ds`
 * (`4000002760003184`) and `3ds2` (`4000000000003220`). The 2026-08-10 decision
 * settled that split: neither implementation distinguishes the protocols, so
 * one contract per journey driven by the 3DS2 card. `THREE_DS_2_CARD` is
 * therefore used for the three settling journeys. The forced-failure row keeps
 * the client's own `declined-3ds` number, which `THREE_DS_DECLINED_CARD`
 * carries, because that row's fixture is the card, not the protocol.
 */

const CONTRACT_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection false › using a 3DS card';
const CONTRACT_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection true › using a 3DS card';
const CONTRACT_AUTHENTICATION_FAILURE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:175::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to invalid 3DS card';
const CONTRACT_SAVE_ON_CHECKOUT =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:68::Saved cards › When using a 3ds card added on checkout › should save the card';

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
// An order the store made but never took money for. The Classic script does not
// report a failed challenge back to the server, so native leaves the order as it
// last saw it; both values are unpaid and neither is a payment.
const UNPAID_ORDER_STATUSES = [ 'pending', 'failed' ];

/**
 * The provider's message for `payment_intent_authentication_failure`.
 *
 * On this surface the displayed string comes from the provider error that
 * `stripe.handleNextAction` rejects with, which native passes to its error
 * region unchanged - it is not native's server-side mapping being exercised,
 * although the two strings are identical (`WooPaymentsErrorMessages` maps the
 * same code to the same sentence). Asserting the text alone would therefore
 * prove only that some provider error arrived; the test binds it to the exact
 * intent's `last_payment_error.code`, which is what the row's residual risk
 * asks for.
 */
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';
const AUTHENTICATION_FAILURE_CODE = 'payment_intent_authentication_failure';
/** Native's own copy when card-testing protection turns a submission away. */
const CARD_TESTING_REJECTION_TEXT =
	"We're not able to process this payment. Please refresh the page and try again.";

const CAPABILITIES = [
	'product/payment',
	'classic-checkout-page',
	'card-authentication',
];
const PROTECTION_CAPABILITIES = [
	...CAPABILITIES,
	'card-testing-protection-setting',
];
const SAVE_CAPABILITIES = [ ...CAPABILITIES, 'saved-card-cleanup' ];

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

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

interface OrderSnapshot {
	status: unknown;
	intentId: string;
	chargeId: string;
}

async function readOrderSnapshot(
	adminApi: APIRequestContext,
	orderId: number
): Promise< OrderSnapshot > {
	const order = ( await readJson(
		await adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
		`WooCommerce order ${ orderId }`
	) ) as { status?: unknown; meta_data?: unknown };
	const meta = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const read = ( key: string ): string => {
		const entry = meta.find( ( item ) => item.key === key );
		return typeof entry?.value === 'string' ? entry.value : '';
	};

	return {
		status: order.status,
		intentId: read( '_intent_id' ),
		chargeId: read( '_charge_id' ),
	};
}

async function readCardTestingProtectionEligibility(
	adminApi: APIRequestContext
): Promise< unknown > {
	const account = ( await readJson(
		await adminApi.get( '/wp-json/wc/v3/payments/accounts' ),
		'WooPayments account'
	) ) as { card_testing_protection_eligible?: unknown };
	return account.card_testing_protection_eligible;
}

/**
 * The half of every settling journey that is identical across the three of
 * them: a challenge was presented, answered, and dismissed, and the submission
 * asked the store exactly once.
 */
function expectAnsweredChallenge(
	outcome: ClassicCardAuthenticationOutcome
): void {
	expect(
		outcome.challenge.authenticationSurfacePresented,
		'the provider must open its authentication surface'
	).toBe( true );
	expect(
		outcome.challenge.challengePresented,
		'a challenge must be presented; a frictionless payment proves nothing here'
	).toBe( true );
	expect( outcome.challenge.response ).toBe( 'complete' );
	expect( outcome.challenge.challengeDismissed ).toBe( true );

	expect(
		outcome.checkoutRequestCount,
		'one Place order activation must ask the store exactly once'
	).toBe( 1 );
	expect( outcome.checkoutResponseCount ).toBe( 1 );
	expect( outcome.dispatch.request.gateway ).toBe( 'woocommerce_payments' );

	// The intent as it stood before the challenge was answered. This is the
	// state a skipped challenge cannot produce, and it names the intent the
	// settled graph below must turn out to be.
	expect( outcome.pendingIntent.id ).toBe( outcome.dispatch.intentId );
	expect(
		outcome.pendingIntent.status,
		'the challenge must be answered against an intent awaiting customer action'
	).toBe( 'requires_action' );
	expect(
		typeof outcome.pendingIntent.nextActionType,
		'the awaiting intent must name the action it needs'
	).toBe( 'string' );
	expect(
		outcome.pendingIntent.chargeCount,
		'no charge may exist before the challenge is answered'
	).toBe( 0 );

	expect(
		outcome.settled,
		`a completed challenge must reach the receipt; the page stopped at ${ outcome.url }`
	).toBe( true );
	expect( outcome.receipt?.orderId ).toBe( outcome.dispatch.orderId );
}

/**
 * The exact provider graph one authenticated USD 10.99 checkout must leave:
 * one intent, one captured charge, one capture, and nothing else.
 */
function expectSettledGraph(
	payment: PaymentEvidence,
	outcome: ClassicCardAuthenticationOutcome,
	runId: string
): void {
	expect(
		payment.intentId,
		'the settled intent must be the intent the challenge was answered against'
	).toBe( outcome.dispatch.intentId );
	expect( payment.orderId ).toBe( outcome.dispatch.orderId );
	expect( payment.orderKey ).toBe( outcome.receipt?.orderKey );
	expect( payment.runId ).toBe( runId );
	expect( payment.paymentMethodId ).not.toBe( '' );
	// The store echoes the payment method back on the checkout response. It must
	// be the one that settled; the empty alternative is admitted because whether
	// native populates that field on a customer-action result has never been
	// observed on a real run, and a paid run must not fail over a field that
	// carries no part of this contract. The intent-to-charge-to-order binding
	// below is what the row's residual risk is about, and it is unconditional.
	expect(
		[ payment.paymentMethodId, '' ],
		'the payment method the store reported at dispatch must be the one that settled'
	).toContain( outcome.dispatch.paymentMethodId );

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

test.describe( 'WooPayments native Classic checkout card authentication', () => {
	// Serial on purpose. Each case provisions the Classic checkout page and
	// removes it again, and the protection case refuses to run while a page it
	// does not own holds that slug; a failure must also halt the cases after it
	// rather than spend more provider budget on an unclear store.
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test(
		'a classic-checkout 3DS challenge completed by the shopper settles one exact order, PaymentIntent, and captured charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_PROTECTION_FALSE,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-classic-3ds-purchase' },
				async () => {
					// Card-testing protection is left exactly as the store has
					// it. This contract is the protection-off half, and the
					// account reports the eligibility flag false, so nothing is
					// forced and nothing is restored.
					expect(
						await readCardTestingProtectionEligibility(
							pilotRuntime.adminApi
						),
						'this contract is the protection-off half and must not run against a protected store'
					).toBe( false );

					await withClassicCheckoutPage(
						pilotRuntime,
						pilotRuntime.runId,
						async ( scope ) => {
							const product =
								await pilotRuntime.createOwnedProduct( PRICE );
							const prepared = await prepareClassicCardCheckout(
								pilotRuntime,
								page,
								{
									product,
									runId: pilotRuntime.runId,
									checkout: scope.classicCheckout,
									card: THREE_DS_2_CARD,
								}
							);

							const outcome =
								await submitClassicCardAuthentication(
									pilotRuntime,
									prepared,
									page,
									{
										response: 'complete',
										journal: 'classic-3ds-checkout',
									}
								);

							expectAnsweredChallenge( outcome );
							expect(
								outcome.dispatch.request.savePaymentMethod,
								'a purchase that saves nothing must not ask to save'
							).toBe( false );

							const payment = await readSettledClassicPayment(
								pilotRuntime,
								outcome.dispatch.orderId,
								outcome.dispatch.intentId
							);
							expectSettledGraph(
								payment,
								outcome,
								pilotRuntime.runId
							);
						}
					);
				}
			);
		}
	);

	test(
		'card-testing protection admits one token-bearing classic 3DS checkout to the same settled graph and creates no payment context for a tokenless one',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_PROTECTION_TRUE,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, PROTECTION_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			// Forced-eligibility boundary, carried here deliberately. The
			// target account reports `card_testing_protection_eligible: false`,
			// so this run supplies that premise itself: the controller asserts
			// eligibility into the local account cache, byte-restores it, and
			// verifies the restore. What follows therefore establishes that
			// native enforces protection at its own boundary and still settles
			// an authenticated card - not that the provider grants this account
			// the capability. Provisioning the flag stays outside this claim.
			await withCapturedCardTestingProtectionState(
				pilotRuntime,
				pilotRuntime.runId,
				async ( scope ) => {
					await scope.registerFreshContext( page );

					expect(
						await readCardTestingProtectionEligibility(
							pilotRuntime.adminApi
						),
						'the forced premise must be in effect before either submission'
					).toBe( true );

					const product =
						await pilotRuntime.createOwnedProduct( PRICE );
					const prepared = await prepareClassicCardCheckout(
						pilotRuntime,
						page,
						{
							product,
							runId: pilotRuntime.runId,
							checkout: scope.classicCheckout,
							card: THREE_DS_2_CARD,
							captureExposedToken: true,
						}
					);
					const sessionToken =
						await scope.captureGuestSessionToken( page );
					expect(
						prepared.exposedTokenDigest,
						'the token the page exposes must be the token the session holds'
					).toEqual( sessionToken );

					// Half one: a token-bearing submission settles exactly as
					// the protection-off case does.
					const outcome = await submitClassicCardAuthentication(
						pilotRuntime,
						prepared,
						page,
						{
							response: 'complete',
							journal: 'classic-3ds-checkout-protected',
						}
					);

					expectAnsweredChallenge( outcome );
					expect(
						outcome.dispatch.request.fraudPreventionToken,
						'the admitted submission must carry the exact session token'
					).toEqual( sessionToken );

					const payment = await readSettledClassicPayment(
						pilotRuntime,
						outcome.dispatch.orderId,
						outcome.dispatch.intentId
					);
					expectSettledGraph( payment, outcome, pilotRuntime.runId );

					// Half two: the same store, the same session, one
					// submission with the session token absent. Without this a
					// run where protection silently failed to engage would pass
					// exactly like the one above.
					const baselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const tokenless = await prepareClassicCardCheckout(
						pilotRuntime,
						page,
						{
							product,
							runId: pilotRuntime.runId,
							checkout: scope.classicCheckout,
							card: THREE_DS_2_CARD,
						}
					);
					const rejection = await submitClassicTokenlessCheckout(
						pilotRuntime,
						tokenless,
						{ journal: 'classic-tokenless-submission' }
					);

					expect( rejection.checkoutRequestCount ).toBe( 1 );
					expect( rejection.checkoutResponseCount ).toBe( 1 );
					expect( rejection.request.gateway ).toBe(
						'woocommerce_payments'
					);
					expect(
						rejection.request.fraudPreventionToken,
						'the tokenless submission must actually have carried no usable token'
					).not.toBe( 'present' );
					expect(
						rejection.result,
						'native must refuse the submission'
					).toBe( 'failure' );
					expect( rejection.messages ).toContain(
						CARD_TESTING_REJECTION_TEXT
					);
					expect(
						rejection.notice.alertCount,
						'the shopper must be told, once, through an assertive notice'
					).toBe( 1 );
					expect( rejection.notice.messages ).toEqual( [
						CARD_TESTING_REJECTION_TEXT,
					] );
					expect( rejection.url ).not.toContain( 'order-received' );
					expect( rejection.recovery.onClassicCheckout ).toBe( true );
					expect( rejection.recovery.placeOrderEnabled ).toBe( true );
					expect( rejection.recovery.blockingOverlayCount ).toBe( 0 );

					// No payment context was created, and the admitted payment
					// did not gain a second charge behind it. WooCommerce still
					// makes an order before calling the gateway, so an unpaid
					// order in this window is expected; what must not exist is
					// an order carrying an intent, a charge, or a payment.
					//
					// Boundary, stated rather than glossed: the browser
					// tokenizes the card with the provider before it submits,
					// on this surface and on Blocks alike, so a PaymentMethod
					// object does come into existence. It is created by the
					// shopper's browser against the provider's public API, it
					// is attached to no customer and charged nothing, and it is
					// not what native creates. The claim these assertions carry
					// is the one Core's own rejection test names: no payment
					// context - no intent, no charge, no paid order.
					const delta = await readOrderDeltaAfter(
						pilotRuntime,
						baselineOrderId
					);
					expect(
						delta.paidOrderIds,
						'a refused submission must leave no paid order'
					).toEqual( [] );
					expect(
						delta.providerLinkedOrderIds,
						'a refused submission must create no payment context'
					).toEqual( [] );

					// The refusal answers with no order ID, so the order
					// WooCommerce had already made cannot be tagged at
					// dispatch. Tag it now: it holds no payment, but the
					// history it leaves should still say which run made it.
					for ( const leftoverOrderId of delta.newOrderIds ) {
						await pilotRuntime.setOrderRunId(
							leftoverOrderId,
							pilotRuntime.runId
						);
					}

					const recheck = await readSettledClassicPayment(
						pilotRuntime,
						outcome.dispatch.orderId,
						outcome.dispatch.intentId
					);
					expect( recheck.chargeId ).toBe( payment.chargeId );
					expect( recheck.occurrenceCount ).toBe( 1 );
					expect( recheck.captureOccurrenceCount ).toBe( 1 );
				}
			);
		}
	);

	test(
		'a failed classic-checkout 3DS challenge leaves the PaymentIntent unauthenticated, creates no paid order, and announces the failure through the classic payment notice',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_AUTHENTICATION_FAILURE,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-classic-3ds-failure' },
				async () => {
					await withClassicCheckoutPage(
						pilotRuntime,
						pilotRuntime.runId,
						async ( scope ) => {
							const product =
								await pilotRuntime.createOwnedProduct( PRICE );
							const prepared = await prepareClassicCardCheckout(
								pilotRuntime,
								page,
								{
									product,
									runId: pilotRuntime.runId,
									checkout: scope.classicCheckout,
									card: THREE_DS_DECLINED_CARD,
								}
							);

							const outcome =
								await submitClassicCardAuthentication(
									pilotRuntime,
									prepared,
									page,
									{
										response: 'fail',
										journal: 'classic-3ds-checkout-failed',
									}
								);

							expect(
								outcome.challenge.challengePresented,
								'the challenge must be presented before it can be failed'
							).toBe( true );
							expect( outcome.challenge.response ).toBe( 'fail' );
							expect( outcome.pendingIntent.status ).toBe(
								'requires_action'
							);
							expect( outcome.checkoutRequestCount ).toBe( 1 );

							// The negative control. Without it, a checkout that
							// paid regardless of the answer would satisfy every
							// other assertion here.
							expect(
								outcome.settled,
								'a failed challenge must not reach a receipt'
							).toBe( false );
							expect( outcome.url ).not.toContain(
								'order-received'
							);

							// The shopper is told, through the region native
							// prints for this surface. It carries the assertive
							// role itself, which is how this surface announces:
							// unlike Blocks, nothing here goes through
							// `wp.a11y.speak`, so asserting that region instead
							// would be asserting the wrong oracle.
							expect(
								outcome.paymentError?.visible,
								'a failed challenge must tell the shopper'
							).toBe( true );
							expect( outcome.paymentError?.role ).toBe(
								'alert'
							);
							expect( outcome.paymentError?.text ).toBe(
								AUTHENTICATION_FAILURE_TEXT
							);
							await expect(
								page
									.locator( '#a11y-speak-assertive' )
									.filter( {
										hasText: AUTHENTICATION_FAILURE_TEXT,
									} ),
								'the Classic surface announces through the notice role, not the Blocks speak region'
							).toHaveCount( 0 );

							// And can recover without reloading.
							expect( outcome.recovery?.onClassicCheckout ).toBe(
								true
							);
							expect( outcome.recovery?.placeOrderEnabled ).toBe(
								true
							);
							expect(
								outcome.recovery?.blockingOverlayCount
							).toBe( 0 );
							expect(
								outcome.recovery?.paymentMethodChoiceCount
							).toBeGreaterThan( 0 );

							// The displayed failure is joined to the provider's
							// own account of it, on the exact intent the
							// challenge was answered against.
							const intent = await readFailedAuthenticationIntent(
								pilotRuntime,
								outcome.dispatch.intentId
							);
							expect( intent.id ).toBe(
								outcome.dispatch.intentId
							);
							expect( intent.status ).toBe(
								'requires_payment_method'
							);
							expect( intent.lastPaymentErrorCode ).toBe(
								AUTHENTICATION_FAILURE_CODE
							);
							expect(
								intent.chargeCount,
								'an unauthenticated intent must carry no charge'
							).toBe( 0 );

							const order = await readOrderSnapshot(
								pilotRuntime.adminApi,
								outcome.dispatch.orderId
							);
							expect(
								UNPAID_ORDER_STATUSES,
								'the order must remain unpaid'
							).toContain( order.status );
							expect(
								order.chargeId,
								'an unpaid order must carry no charge'
							).toBe( '' );
							// Native writes the intent reference on some
							// outcomes and not others; either is honest for an
							// unpaid order, and referencing a different intent
							// is not.
							expect(
								[ '', outcome.dispatch.intentId ],
								'the order may only reference the intent this submission created'
							).toContain( order.intentId );
						}
					);
				}
			);
		}
	);

	test(
		'a classic-checkout 3DS challenge completed with save-to-account creates exactly one Woo token and one provider payment method bound to the shopper',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_SAVE_ON_CHECKOUT,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, SAVE_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-classic-3ds-save' },
				async () => {
					await withClassicCheckoutPage(
						pilotRuntime,
						pilotRuntime.runId,
						async ( scope ) => {
							// The shopper's tokens before the run, so the one
							// this journey creates is identified by difference
							// rather than by a card label that stale tokens
							// could also carry.
							const before =
								await getSavedCardEvidence( pilotRuntime );
							const knownTokenIds = new Set(
								before.tokens.map( ( token ) => token.tokenId )
							);

							const product =
								await pilotRuntime.createOwnedProduct( PRICE );
							const prepared = await prepareClassicCardCheckout(
								pilotRuntime,
								page,
								{
									product,
									runId: pilotRuntime.runId,
									checkout: scope.classicCheckout,
									card: THREE_DS_2_CARD,
									savePaymentMethod: true,
									logInAsCustomer: true,
								}
							);

							const outcome =
								await submitClassicCardAuthentication(
									pilotRuntime,
									prepared,
									page,
									{
										response: 'complete',
										journal: 'classic-3ds-checkout-save',
									}
								);

							expectAnsweredChallenge( outcome );
							expect(
								outcome.dispatch.request.savePaymentMethod,
								'the ticked control must reach the store as a save request'
							).toBe( true );

							const payment = await readSettledClassicPayment(
								pilotRuntime,
								outcome.dispatch.orderId,
								outcome.dispatch.intentId
							);
							expectSettledGraph(
								payment,
								outcome,
								pilotRuntime.runId
							);

							const after =
								await getSavedCardEvidence( pilotRuntime );
							const created = after.tokens.filter(
								( token ) =>
									! knownTokenIds.has( token.tokenId )
							);
							// Read before the assertions so cleanup can run
							// whatever they conclude: a token this run created
							// must not survive the run that created it, and an
							// assertion that fails first must not be the reason
							// it does.
							const providerCustomerId =
								await readProviderCustomerId(
									pilotRuntime,
									payment.intentId
								);

							try {
								expect(
									created,
									'one authenticated save must create exactly one local token'
								).toHaveLength( 1 );
								expect(
									created[ 0 ].paymentMethodId,
									'the saved token must be the payment method this payment used'
								).toBe( payment.paymentMethodId );
								for ( const token of before.tokens ) {
									expect(
										after.tokens.find(
											( candidate ) =>
												candidate.tokenId ===
												token.tokenId
										)?.paymentMethodId,
										'an unrelated token must not be remapped'
									).toBe( token.paymentMethodId );
								}

								const attached =
									await readProviderCustomerPaymentMethodIds(
										pilotRuntime,
										providerCustomerId
									);
								expect(
									attached.filter(
										( id ) => id === payment.paymentMethodId
									),
									'the provider must hold exactly one attachment of that method'
								).toHaveLength( 1 );
							} finally {
								// Cleanup only. The separate 3DS-deletion row
								// keeps its own contract; this removes what
								// this run created and proves the provider
								// detached it. An empty list is a no-op, so a
								// run that created nothing deletes nothing.
								await deleteExactSavedCards(
									pilotRuntime,
									page,
									created.map( ( token ) => ( {
										tokenId: token.tokenId,
										paymentMethodId: token.paymentMethodId,
									} ) ),
									providerCustomerId
								);
							}
						}
					);
				}
			);
		}
	);
} );
