import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
	readProviderCustomerId,
	readSettledClassicPayment,
	submitClassicCardAuthentication,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	createRunOwnedSavedMethodShopper,
	deleteRunOwnedSavedMethods,
	deleteRunOwnedSavedMethodShopper,
	getSavedVisaDisplayCopy,
	prepareExactSavedMethodAuthentication,
	readRunOwnedSavedMethodState,
	submitSavedMethodAuthentication,
	waitForSavedMethodSetupIntent,
	type RunOwnedSavedMethodShopper,
} from '../../../utils/woopayments-native/drivers/saved-method-authentication';
import {
	THREE_DS_2_CARD,
	THREE_DS_DECLINED_CARD,
} from '../../../utils/woopayments-native/test-cards';

const CONTRACT_FAILED_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-payment-methods-add-fail.spec.ts:71::Payment Methods › when attempting to add a declined-3ds card › it should not add the card';
const CONTRACT_AUTHENTICATED_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:146::Shopper can save and delete cards › Testing card: 3ds › should add the 3ds card as a new payment method';
const CONTRACT_AUTHENTICATED_PURCHASE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:196::Shopper can save and delete cards › Testing card: 3ds › should be able to purchase with the saved 3ds card';

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';
const SETUP_AUTHENTICATION_FAILURE_CODE = 'setup_intent_authentication_failure';

const CAPABILITIES = [
	'saved-card-add',
	'saved-card-classic',
	'saved-card-cleanup',
	'card-authentication',
	'classic-checkout-page',
	'product/payment',
];

function requireCapabilities( session: ProviderWriteSession ): void {
	for ( const capability of CAPABILITIES ) {
		session.requireApprovedProviderFixture( capability );
	}
}

/**
 * Always remove the local credential and shopper, without replacing the
 * assertion that made the case fail.
 */
async function finishRunOwnedShopper(
	session: ProviderWriteSession,
	page: Parameters< typeof deleteRunOwnedSavedMethods >[ 1 ],
	shopper: RunOwnedSavedMethodShopper,
	primaryError?: unknown
): Promise< void > {
	const cleanupErrors: unknown[] = [];
	try {
		await deleteRunOwnedSavedMethods( session, page, shopper );
	} catch ( error ) {
		cleanupErrors.push( error );
	}
	try {
		await deleteRunOwnedSavedMethodShopper( session, shopper );
	} catch ( error ) {
		cleanupErrors.push( error );
	}

	if ( primaryError !== undefined ) {
		if ( cleanupErrors.length > 0 ) {
			console.error(
				'Run-owned saved-method cleanup also failed:',
				...cleanupErrors
			);
		}
		throw primaryError;
	}
	if ( cleanupErrors.length > 0 ) {
		throw new AggregateError(
			cleanupErrors,
			'Run-owned saved-method cleanup failed.'
		);
	}
}

function expectMandatoryChallenge(
	challenge: Awaited<
		ReturnType< typeof submitSavedMethodAuthentication >
	>[ 'challenge' ],
	response: 'complete' | 'fail'
): void {
	expect( challenge.authenticationSurfacePresented ).toBe( true );
	expect(
		challenge.challengePresented,
		'a frictionless or absent challenge cannot satisfy this contract'
	).toBe( true );
	expect( challenge.response ).toBe( response );
	expect( challenge.challengeDismissed ).toBe( true );
}

test.describe( 'WooPayments native saved-method authentication', () => {
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test(
		'a failed My Account 3DS challenge leaves the exact SetupIntent unauthenticated, saves no token or attachment, and returns an announced usable add-method form',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_FAILED_ADD,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-method-3ds-failure' },
				async () => {
					const highestOrderId = await readHighestOrderId(
						pilotRuntime
					);
					const shopper = await createRunOwnedSavedMethodShopper(
						pilotRuntime,
						'failed'
					);
					let primaryError: unknown;

					try {
						const before = await readRunOwnedSavedMethodState(
							pilotRuntime,
							shopper
						);
						expect(
							before.tokens,
							'a fresh shopper must hold no local credential'
						).toEqual( [] );
						expect(
							before.providerCustomerId,
							'a fresh shopper must not yet be known to the provider'
						).toBe( '' );
						expect( before.attachedPaymentMethodIds ).toEqual( [] );

						const observation =
							await submitSavedMethodAuthentication(
								pilotRuntime,
								page,
								{
									shopper,
									card: THREE_DS_DECLINED_CARD,
									response: 'fail',
									journal:
										'saved-method-3ds-authentication-failed',
								}
							);

						expect( observation.setupIntentExchanges ).toHaveLength(
							1
						);
						const [ exchange ] = observation.setupIntentExchanges;
						expect( exchange.httpStatus ).toBe( 200 );
						expect( exchange.setupIntentId ).toMatch( /^seti_/ );
						expect( exchange.setupIntentStatus ).toBe(
							'requires_action'
						);
						expect( exchange.errorMessage ).toBe( '' );
						expect(
							observation.setupPaymentMethodIds
						).toHaveLength( 1 );
						expect(
							observation.setupPaymentMethodIds[ 0 ]
						).toMatch( /^pm_/ );
						expectMandatoryChallenge(
							observation.challenge,
							'fail'
						);
						expect(
							observation.submittedSetupIntentIds,
							'a failed authentication must never submit a SetupIntent for local storage'
						).toEqual( [] );

						const setupIntent = await waitForSavedMethodSetupIntent(
							pilotRuntime,
							exchange.setupIntentId,
							'requires_payment_method'
						);
						expect( setupIntent.id ).toBe( exchange.setupIntentId );
						expect( setupIntent.usage ).toBe( 'off_session' );
						expect( setupIntent.lastSetupErrorType ).toBe(
							'invalid_request_error'
						);
						expect( setupIntent.lastSetupErrorCode ).toBe(
							SETUP_AUTHENTICATION_FAILURE_CODE
						);

						const after = await readRunOwnedSavedMethodState(
							pilotRuntime,
							shopper
						);
						expect( observation.tokensBefore ).toEqual( [] );
						expect( observation.tokensAfter ).toEqual( [] );
						expect( observation.createdCards ).toEqual( [] );
						expect( after.tokens ).toEqual( [] );
						expect(
							after.providerCustomerId,
							'the exact failed SetupIntent must name the shopper customer native persisted'
						).toBe( setupIntent.customerId );
						expect(
							after.attachedPaymentMethodIds,
							'a failed challenge must attach no payment method'
						).toEqual( [] );

						const orders = await readOrderDeltaAfter(
							pilotRuntime,
							highestOrderId
						);
						expect(
							orders.newOrderIds,
							'a SetupIntent failure must create no Woo order or provider-linked order'
						).toEqual( [] );
						expect( orders.paidOrderIds ).toEqual( [] );
						expect( orders.providerLinkedOrderIds ).toEqual( [] );

						expect( observation.paymentError.visible ).toBe( true );
						expect( observation.paymentError.role ).toBe( 'alert' );
						expect( observation.paymentError.text ).toBe(
							AUTHENTICATION_FAILURE_TEXT
						);
						expect( observation.successNoticeVisible ).toBe(
							false
						);
						expect( observation.recovery ).toEqual( {
							formVisible: true,
							addButtonEnabled: true,
							blockingOverlayCount: 0,
						} );
					} catch ( error ) {
						primaryError = error;
					}

					await finishRunOwnedShopper(
						pilotRuntime,
						page,
						shopper,
						primaryError
					);
				}
			);
		}
	);

	test(
		'one My Account 3DS token completes required SetupIntent and Classic PaymentIntent challenges and settles one exact USD 10.99 payment',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_AUTHENTICATED_ADD,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_AUTHENTICATED_PURCHASE,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-method-3ds-purchase' },
				async () => {
					const highestOrderId = await readHighestOrderId(
						pilotRuntime
					);
					const shopper = await createRunOwnedSavedMethodShopper(
						pilotRuntime,
						'complete'
					);
					let primaryError: unknown;

					try {
						const before = await readRunOwnedSavedMethodState(
							pilotRuntime,
							shopper
						);
						expect( before.tokens ).toEqual( [] );
						expect( before.providerCustomerId ).toBe( '' );
						expect( before.attachedPaymentMethodIds ).toEqual( [] );

						const saved = await submitSavedMethodAuthentication(
							pilotRuntime,
							page,
							{
								shopper,
								card: THREE_DS_2_CARD,
								response: 'complete',
								journal:
									'saved-method-3ds-authentication-complete',
							}
						);
						expect( saved.setupIntentExchanges ).toHaveLength( 1 );
						const [ exchange ] = saved.setupIntentExchanges;
						expect( exchange.httpStatus ).toBe( 200 );
						expect( exchange.setupIntentStatus ).toBe(
							'requires_action'
						);
						expectMandatoryChallenge( saved.challenge, 'complete' );
						expect( saved.submittedSetupIntentIds ).toEqual( [
							exchange.setupIntentId,
						] );
						expect( saved.successNoticeVisible ).toBe( true );
						const savedVisa =
							getSavedVisaDisplayCopy( THREE_DS_2_CARD );
						await expect(
							page.getByRole( 'cell', {
								name: savedVisa.method,
								exact: true,
							} )
						).toBeVisible();
						await expect(
							page.getByRole( 'cell', {
								name: savedVisa.expires,
								exact: true,
							} )
						).toBeVisible();
						expect( saved.createdCards ).toHaveLength( 1 );
						const [ card ] = saved.createdCards;

						const setupIntent = await waitForSavedMethodSetupIntent(
							pilotRuntime,
							exchange.setupIntentId,
							'succeeded'
						);
						const savedState = await readRunOwnedSavedMethodState(
							pilotRuntime,
							shopper
						);
						expect( saved.setupPaymentMethodIds ).toEqual( [
							card.paymentMethodId,
						] );
						expect( setupIntent.paymentMethodId ).toBe(
							card.paymentMethodId
						);
						expect( setupIntent.usage ).toBe( 'off_session' );
						expect( setupIntent.lastSetupErrorType ).toBe( '' );
						expect( setupIntent.lastSetupErrorCode ).toBe( '' );
						expect( setupIntent.customerId ).toBe(
							savedState.providerCustomerId
						);
						expect( savedState.tokens ).toEqual( [
							{
								...card,
								isDefault: true,
							},
						] );
						expect( savedState.attachedPaymentMethodIds ).toEqual( [
							card.paymentMethodId,
						] );

						const saveOrders = await readOrderDeltaAfter(
							pilotRuntime,
							highestOrderId
						);
						expect(
							saveOrders.newOrderIds,
							'a succeeded SetupIntent saves a credential but creates no order'
						).toEqual( [] );

						await withClassicCheckoutPage(
							pilotRuntime,
							pilotRuntime.runId,
							async ( scope ) => {
								const product =
									await pilotRuntime.createOwnedProduct(
										PRICE
									);
								const selected =
									await prepareExactSavedMethodAuthentication(
										pilotRuntime,
										page,
										{
											shopper,
											card,
											cardFixture: THREE_DS_2_CARD,
											product,
											checkout: scope.classicCheckout,
											runId: pilotRuntime.runId,
										}
									);
								expect( selected.selectedTokenId ).toBe(
									card.tokenId
								);
								expect( selected.selectedAccessibleName ).toBe(
									savedVisa.classicAccessibleName
								);

								const outcome =
									await submitClassicCardAuthentication(
										pilotRuntime,
										selected.prepared,
										page,
										{
											response: 'complete',
											journal:
												'classic-saved-method-3ds-complete',
										}
									);
								expect( outcome.checkoutRequestCount ).toBe(
									1
								);
								expect( outcome.checkoutResponseCount ).toBe(
									1
								);
								expect( outcome.dispatch.request.gateway ).toBe(
									'woocommerce_payments'
								);
								expect(
									outcome.dispatch.request.savePaymentMethod
								).toBe( false );
								expect( outcome.dispatch.paymentMethodId ).toBe(
									card.paymentMethodId
								);
								expect( outcome.pendingIntent.id ).toBe(
									outcome.dispatch.intentId
								);
								expect( outcome.pendingIntent.status ).toBe(
									'requires_action'
								);
								expect(
									outcome.pendingIntent.chargeCount
								).toBe( 0 );
								expect( outcome.pendingIntent.amount ).toBe(
									AMOUNT_MINOR
								);
								expect( outcome.pendingIntent.currency ).toBe(
									CURRENCY.toLowerCase()
								);
								expectMandatoryChallenge(
									outcome.challenge,
									'complete'
								);
								expect( outcome.settled ).toBe( true );
								expect( outcome.receipt?.orderId ).toBe(
									outcome.dispatch.orderId
								);

								const payment = await readSettledClassicPayment(
									pilotRuntime,
									outcome.dispatch.orderId,
									outcome.dispatch.intentId
								);
								expect( payment.runId ).toBe(
									pilotRuntime.runId
								);
								expect( payment.orderId ).toBe(
									outcome.dispatch.orderId
								);
								expect( payment.intentId ).toBe(
									outcome.dispatch.intentId
								);
								expect( payment.paymentMethodId ).toBe(
									card.paymentMethodId
								);
								expect(
									await readProviderCustomerId(
										pilotRuntime,
										payment.intentId
									)
								).toBe( setupIntent.customerId );
								expect( payment.amountMinor ).toBe(
									AMOUNT_MINOR
								);
								expect( payment.currency ).toBe( CURRENCY );
								expect( payment.providerStatus ).toBe(
									'succeeded'
								);
								expect( payment.chargeStatus ).toBe(
									'succeeded'
								);
								expect( payment.chargeCaptured ).toBe( true );
								expect( payment.occurrenceCount ).toBe( 1 );
								expect( payment.captureOccurrenceCount ).toBe(
									1
								);
								expect( PAID_ORDER_STATUSES ).toContain(
									payment.orderStatus
								);

								const orders = await readOrderDeltaAfter(
									pilotRuntime,
									highestOrderId
								);
								expect( orders.newOrderIds ).toEqual( [
									payment.orderId,
								] );
								expect( orders.paidOrderIds ).toEqual( [
									payment.orderId,
								] );
								expect( orders.providerLinkedOrderIds ).toEqual(
									[ payment.orderId ]
								);

								const reusable =
									await readRunOwnedSavedMethodState(
										pilotRuntime,
										shopper
									);
								expect(
									reusable.tokens,
									'the paid checkout must leave the exact saved credential reusable'
								).toEqual( savedState.tokens );
								expect(
									reusable.attachedPaymentMethodIds
								).toEqual( [ card.paymentMethodId ] );
							}
						);
					} catch ( error ) {
						primaryError = error;
					}

					await finishRunOwnedShopper(
						pilotRuntime,
						page,
						shopper,
						primaryError
					);
				}
			);
		}
	);
} );
