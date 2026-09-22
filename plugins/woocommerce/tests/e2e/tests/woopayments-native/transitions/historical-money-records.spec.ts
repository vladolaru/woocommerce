import { createHash } from 'node:crypto';

import {
	expect,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	withCapturedCardTestingProtectionState,
	type CardTestingProtectionScope,
} from '../../../utils/woopayments-native/drivers/card-testing-protection';
import {
	PlaywrightClassicCardCheckoutBrowser,
} from '../../../utils/woopayments-native/drivers/classic-card-checkout';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	convergeFailedPayment,
	readFailedPaymentEvidence,
	readOrderIdStatusDelta,
} from '../../../utils/woopayments-native/drivers/failed-payment-evidence';
import { readHighestOrderId } from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import {
	collectHistoricalPayForOrderRecovery,
	clearHistoricalWooCommerceSessionCookie,
	validateHistoricalOrderPayRoute,
	validateHistoricalOrderPayTotal,
	type HistoricalPayForOrderBrowserEvidence,
} from '../../../utils/woopayments-native/drivers/historical-pay-for-order';
import { waitForPaymentState } from '../../../utils/woopayments-native/provider-evidence';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';
import { softCutOverEphemeralStore } from '../../../utils/woopayments-native/drivers/store-transition';

const CONTRACT_DISABLED =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-pay-for-order.spec.ts:35::Shopper › Pay for Order › should be able to pay for a failed order with card testing protection false';
const CONTRACT_ENABLED =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-pay-for-order.spec.ts:35::Shopper › Pay for Order › should be able to pay for a failed order with card testing protection true';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	tags.WOOPAYMENTS_TRANSITION,
	tags.WOOPAYMENTS_PR,
];
const GENERIC_DECLINE_CARD = {
	number: '4000000000000002',
	expiry: '0345',
	securityCode: '525',
} as const;
const BASIC_CARD = {
	number: '4242424242424242',
	expiry: '0345',
	securityCode: '525',
} as const;

interface JourneyState {
	product?: OwnedProduct;
	orderId?: number;
	orderKey?: string;
	paymentUrl?: string;
	declinedIntentId?: string;
	declinedPaymentMethodId?: string;
	declinedChargeIds?: string[];
	customerId?: number;
	allocation?: {
		runId: string;
		storeId: string;
		blogId: number;
		accountId: string;
	};
	scope?: CardTestingProtectionScope;
	browser?: PlaywrightClassicCardCheckoutBrowser;
	successOrderId?: number;
}

function submittedProtection( body: string | null ) {
	const tokens = new URLSearchParams( body ?? '' ).getAll(
		'wcpay-fraud-prevention-token'
	);
	if ( tokens.length > 1 ) {
		throw new Error( 'Historical pay-for-order observed multiple CTP wire values.' );
	}
	if ( tokens.length === 0 ) {
		return { field: 'absent' as const };
	}
	if ( tokens[ 0 ] === '' ) {
		return { field: 'empty' as const };
	}
	return { tokenSha256: sha256( tokens[ 0 ] ) };
}

function sha256( value: string ): string {
	return createHash( 'sha256' ).update( value ).digest( 'hex' );
}

function requireValue< Value >( value: Value | undefined, label: string ): Value {
	if ( value === undefined ) {
		throw new Error( `Historical pay-for-order requires ${ label }.` );
	}
	return value;
}

async function readOrder(
	session: ProviderWriteSession,
	orderId: number
): Promise< Record< string, unknown > > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/orders/${ orderId }`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Historical pay-for-order order read failed: HTTP ${ response.status() }.`
		);
	}
	const order = await response.json();
	if ( typeof order !== 'object' || order === null || Array.isArray( order ) ) {
		throw new Error( 'Historical pay-for-order requires an order object.' );
	}
	return order as Record< string, unknown >;
}

async function readOrderNotes(
	session: ProviderWriteSession,
	orderId: number
): Promise< unknown[] > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/orders/${ orderId }/notes?context=edit&per_page=100`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Historical pay-for-order note read failed: HTTP ${ response.status() }.`
		);
	}
	const notes = await response.json();
	if ( ! Array.isArray( notes ) ) {
		throw new Error( 'Historical pay-for-order requires an order-note array.' );
	}
	return notes;
}

async function readProduct(
	session: ProviderWriteSession,
	productId: number
): Promise< Record< string, unknown > > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/products/${ productId }`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Historical pay-for-order product read failed: HTTP ${ response.status() }.`
		);
	}
	const product = await response.json();
	if ( typeof product !== 'object' || product === null || Array.isArray( product ) ) {
		throw new Error( 'Historical pay-for-order requires a product object.' );
	}
	return product as Record< string, unknown >;
}

function orderTotalMinor( order: Record< string, unknown > ): number {
	const total = String( order.total );
	if ( ! /^\d+\.\d{2}$/.test( total ) ) {
		throw new Error( `Historical pay-for-order requires a USD total, received ${ total }.` );
	}
	return Number( total.replace( '.', '' ) );
}

function failedOrderFields(
	order: Record< string, unknown >,
	productId: number,
	stockQuantity: number
) {
	const stockReduced = stockQuantity === 0;
	const currency = String( order.currency );
	const status = String( order.status );
	const paymentMethod = String( order.payment_method );
	const lines = order.line_items;
	if (
		currency !== 'USD' ||
		status !== 'failed' ||
		paymentMethod !== 'woocommerce_payments' ||
		stockQuantity !== 1 ||
		! Array.isArray( lines ) ||
		lines.length !== 1 ||
		typeof lines[ 0 ] !== 'object' ||
		lines[ 0 ] === null ||
		Number( ( lines[ 0 ] as Record< string, unknown > ).product_id ) !== productId ||
		Number( ( lines[ 0 ] as Record< string, unknown > ).quantity ) !== 1
	) {
		throw new Error( 'Historical pay-for-order requires the observed 10.01 USD failed-order tuple.' );
	}
	return {
		currency: 'USD' as const,
		status: 'failed' as const,
		paymentMethod: 'woocommerce_payments' as const,
		productLines: [ { productId, quantity: 1 } ] as const,
		stockReduced,
	};
}

function observedRecoveryCurrency( currency: string ): 'USD' {
	if ( currency !== 'USD' ) {
		throw new Error( 'Historical pay-for-order requires the observed USD recovery currency.' );
	}
	return currency;
}

function observedRecoveryStatus( status: string ): 'processing' | 'completed' {
	if ( status !== 'processing' && status !== 'completed' ) {
		throw new Error( 'Historical pay-for-order requires an observed paid order status.' );
	}
	return status;
}

async function runHistoricalPayForOrder(
	session: ProviderWriteSession,
	page: import( '@playwright/test' ).Page,
	protectionTarget: boolean
): Promise< HistoricalPayForOrderBrowserEvidence > {
	const state: JourneyState = {};

	return collectHistoricalPayForOrderRecovery( {
		preCutover: {
			withTransitionProviderLock: async ( callback ) =>
				session.withProviderWriteLocks(
					{ recordEvent: 'historical-pay-for-order-pre-cutover' },
					async () => {
						session.requireApprovedProviderFixture(
							`historical-pay-for-order-ctp-${ protectionTarget ? 'true' : 'false' }`
						);
						const allocation = session.requireEphemeralTransitionAllocation();
						state.allocation = {
							runId: allocation.run_id,
							storeId: allocation.store_id,
							blogId: allocation.wpcom_blog_id,
							accountId: allocation.account_id,
						};
						await session.assertCurrentRuntimeReady( 'transition' );
						await withClassicCheckoutPage( session, session.runId, async ( checkout ) => {
							const baselineOrderId = await readHighestOrderId( session );
							await session.logInAsCustomer( page );
							state.product = await session.createOwnedProduct( '10.01', { managedStockQuantity: 1 } );
							const browser = new PlaywrightClassicCardCheckoutBrowser(
								page,
								session.baseURL,
								checkout.classicCheckout.pageId
							);
							await browser.preflightClassicPage(
								checkout.classicCheckout.path,
								checkout.classicCheckout.pageId
							);
							await browser.addProductOnce(
								requireValue( state.product, 'a run-owned product' ).id,
								( write ) => session.performWrite( write )
							);
							await browser.openClassicCheckout( checkout.classicCheckout.path );
							await browser.fillBillingDetails( session.runId );
							await browser.selectWooPaymentsCard();
							await browser.fillTestCard( GENERIC_DECLINE_CARD );
							await browser.prepareSubmission();
							await session.withProviderSubmissionJournal(
								'client-decline',
								async () => {
									const observed = await browser.observeSubmissionInterval(
										( activate ) => session.performWrite( activate ),
										async () => {
											if ( ! ( await browser.waitForCheckoutRejectionNotice( 30_000 ) ) ) {
												throw new Error( 'Historical pay-for-order requires the bounded decline notice.' );
											}
											return readOrderIdStatusDelta(
												session,
												baselineOrderId
											);
										}
									);
									if ( observed.dispatch.requests.length !== 1 ) {
										throw new Error( 'Historical pay-for-order decline must dispatch once.' );
									}
									if ( observed.result.newOrderIds.length !== 1 ) {
										throw new Error( 'Historical pay-for-order decline must create one order.' );
									}
									state.orderId = observed.result.newOrderIds[ 0 ];
									await session.setOrderRunId( state.orderId, session.runId );
									await convergeFailedPayment( session, state.orderId );
								}
							);
						} );
						await clearHistoricalWooCommerceSessionCookie(
							page.context(),
							session.baseURL
						);
						return callback();
					}
				),
			readFailedFixture: async () => {
				const orderId = requireValue( state.orderId, 'the failed order ID' );
				const product = requireValue( state.product, 'the run-owned product' );
				const order = await readOrder( session, orderId );
				const orderKey = String( order.order_key );
				const paymentUrl = String( order.payment_url );
				const customerId = Number( order.customer_id );
				const notes = await readOrderNotes( session, orderId );
				const productRecord = await readProduct( session, product.id );
				const stockQuantity = Number( productRecord.stock_quantity );
				if ( ! orderKey || ! paymentUrl || ! Number.isSafeInteger( customerId ) || customerId <= 0 ) {
					throw new Error( 'Historical pay-for-order requires a live customer pay link.' );
				}
				state.orderKey = orderKey;
				state.paymentUrl = paymentUrl;
				state.customerId = customerId;
				const failedPayment = await convergeFailedPayment( session, orderId );
				state.declinedIntentId = failedPayment.intentId;
				state.declinedPaymentMethodId = failedPayment.paymentMethodId;
				state.declinedChargeIds = [ ...failedPayment.chargeIds ];
				const observedOrder = failedOrderFields( order, product.id, stockQuantity );
				const fixture = {
					schemaVersion: 1 as const,
					source: { pluginVersion: '11.1.0', sourceCommit: 'f85392666c9b543cd24dbbf903e0dbe4cb2c5cee' },
					allocation: requireValue( state.allocation, 'the validated transition allocation' ),
					protectionTarget: protectionTarget as false | true,
					customerId,
					productId: product.id,
					order: { id: orderId, keySha256: sha256( orderKey ), customerId, ...observedOrder, totalMinor: orderTotalMinor( order ), noteCount: notes.length, emailCount: 0 },
					myAccountPayLink: { orderId, orderKeySha256: sha256( orderKey ), customerId, pathSha256: sha256( new URL( paymentUrl ).pathname ) },
					clientDecline: {
						intentId: failedPayment.intentId,
						intentStatus: 'requires_payment_method' as const,
						paymentMethodId: failedPayment.paymentMethodId,
						chargeIds: [ ...failedPayment.chargeIds ],
						captureCount: failedPayment.capturedCharges,
						cardLast4: '0002' as const,
					},
					baseline: { orderIds: [ orderId ] as const, stockQuantity, noteCount: notes.length, emailCount: 0 },
				};
				return { ...fixture, checksumSha256: sha256( JSON.stringify( fixture ) ) };
			},
			readFailedPayment: () => readFailedPaymentEvidence( session, requireValue( state.orderId, 'the failed order ID' ) ),
			cutOver: async () => {
				await softCutOverEphemeralStore( session, page );
				return { runtimeOwner: 'native' };
			},
		},
		postCutover: {
			withCardTestingProtectionLock: ( callback ) =>
				withCapturedCardTestingProtectionState(
					session,
					session.runId,
					async ( scope ) => {
						state.scope = scope;
						return callback();
					},
					{ targetProtection: protectionTarget }
				),
			captureProtection: async () => {
				const scope = requireValue( state.scope, 'the card-testing protection scope' );
				await scope.registerFreshContext( page );
				await page.goto( requireValue( state.paymentUrl, 'the immutable order pay link' ) );
				validateHistoricalOrderPayRoute( page.url(), {
					paymentUrl: requireValue( state.paymentUrl, 'the immutable order pay link' ),
					orderId: requireValue( state.orderId, 'the immutable order ID' ),
					orderKey: requireValue( state.orderKey, 'the immutable order key' ),
				} );
				await expect( page ).toHaveTitle( /Pay for order/i );
				const totalRow = page.locator( '#order_review tfoot tr' ).filter( {
					has: page.getByRole( 'rowheader', { name: /^Total:/i } ),
				} );
				const totalCell = totalRow.locator( 'td.product-total' );
				await expect( totalCell ).toHaveText( '$10.01' );
				validateHistoricalOrderPayTotal( await totalCell.innerText() );
				return scope.captureAuthenticatedSessionProtection(
					page,
					requireValue( state.customerId, 'the immutable order customer ID' )
				);
			},
			observeRenderedProtection: async () => {
				const scope = requireValue( state.scope, 'the card-testing protection scope' );
				const browser = new PlaywrightClassicCardCheckoutBrowser( page, session.baseURL, scope.classicCheckout.pageId );
				state.browser = browser;
				await browser.selectWooPaymentsCard();
				const token = await browser.captureEffectiveFraudPreventionTokenDigest();
				return token.present ? { tokenSha256: token.digest.sha256 } : { field: 'absent' as const };
			},
			submitPayForOrder: async () => {
				const browser = requireValue( state.browser, 'the rendered order-pay form' );
				await browser.fillTestCard( BASIC_CARD );
				await browser.prepareSubmission();
				const observed = await session.withProviderSubmissionJournal(
					'native-pay-for-order',
					() => browser.observeSubmissionInterval(
						( activate ) => session.performWrite( activate ),
						() => browser.waitForClassicReceipt()
					)
				);
				expect( observed.result.orderId ).toBe( requireValue( state.orderId, 'the immutable order ID' ) );
				expect( observed.result.orderKey ).toBe( requireValue( state.orderKey, 'the immutable order key' ) );
				state.successOrderId = requireValue( state.orderId, 'the immutable order ID' );
				return { requestCount: observed.dispatch.requests.length, submittedProtection: submittedProtection( observed.dispatch.requests[ 0 ]?.body ?? null ) };
			},
			waitForListenerQuiescence: async () => {
				const evidence = await getPaymentEvidence( session.adminApi, requireValue( state.successOrderId, 'the recovered order ID' ) );
				await waitForPaymentState( session.adminApi, evidence, 'succeeded', 45_000 );
			},
			readColdRecoveryEvidence: async () => {
				const orderId = requireValue( state.orderId, 'the immutable order ID' );
				const product = requireValue( state.product, 'the run-owned product' );
				const order = await readOrder( session, orderId );
				const evidence = await getPaymentEvidence( session.adminApi, requireValue( state.successOrderId, 'the recovered order ID' ) );
				const notes = await readOrderNotes( session, orderId );
				const customerId = Number( order.customer_id );
				const orderKey = String( order.order_key );
				const declinedIntentId = requireValue( state.declinedIntentId, 'the declined PaymentIntent ID' );
				const declinedPaymentMethodId = requireValue( state.declinedPaymentMethodId, 'the declined PaymentMethod ID' );
				const declinedChargeIds = requireValue(
					state.declinedChargeIds,
					'the declined charge IDs'
				);
				if ( ! Number.isSafeInteger( customerId ) || customerId <= 0 || ! orderKey ) {
					throw new Error( 'Historical pay-for-order requires a cold customer/order read.' );
				}
				return {
					fixtureChecksumSha256: '', protection: { eligible: false, token: null, accountEnabled: false, renderedField: 'absent', submittedTokenSha256: null },
					order: { id: orderId, keySha256: sha256( orderKey ), customerId, currency: observedRecoveryCurrency( evidence.currency ), totalMinor: evidence.amountMinor, status: observedRecoveryStatus( evidence.orderStatus ), noteCount: notes.length },
					nativeSuccess: { intentId: evidence.intentId, paymentMethodId: evidence.paymentMethodId, chargeId: evidence.chargeId, chargeStatus: evidence.chargeStatus, chargeCaptured: evidence.chargeCaptured, occurrenceCount: evidence.occurrenceCount, captureOccurrenceCount: evidence.captureOccurrenceCount },
					cleanup: {
						manifest: {
							orderIds: [ orderId ],
							intentIds: [ declinedIntentId, evidence.intentId ],
							paymentMethodIds: [
								declinedPaymentMethodId,
								evidence.paymentMethodId,
							],
							chargeIds: [
								...declinedChargeIds,
								evidence.chargeId,
							],
							customerIds: [ customerId ],
							productIds: [ product.id ],
						},
					},
				};
			},
		},
	} );
}

for ( const [ title, contractId, protectionTarget ] of [
	[ 'plugin-origin failed order pays in place after cutover with card-testing protection disabled', CONTRACT_DISABLED, false ],
	[ 'plugin-origin failed order pays in place after cutover with card-testing protection enabled', CONTRACT_ENABLED, true ],
] as const ) {
	test( title, { annotation: [ { type: 'woopayments-contract', description: contractId } ], tag: FAMILY_TAGS }, async ( { page, pilotRuntime } ) => {
		const evidence = await runHistoricalPayForOrder( pilotRuntime, page, protectionTarget );
		expect( evidence.order.status ).toMatch( /^(processing|completed)$/ );
	} );
}
