import type { Page, Request, Response } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	ProviderSubmissionNotStartedError,
	ResourceQuarantineRequiredError,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	completeCardAuthentication,
	PlaywrightCardAuthenticationBrowser,
	type CardAuthenticationEvidence,
} from '../../../utils/woopayments-native/drivers/card-authentication';
import { findBlocksPaymentIntentConfirmation } from '../../../utils/woopayments-native/drivers/blocks-card-authentication';
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import {
	readFailedAuthenticationIntent,
	readHighestOrderId,
	readOrderDeltaAfter,
	readSettledClassicPayment,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { submitBlocksCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	THREE_DS_2_CARD,
	THREE_DS_DECLINED_CARD,
	type ProviderTestCard,
} from '../../../utils/woopayments-native/test-cards';

/**
 * Proves the provider's 3D Secure challenge is real and that native carries a
 * completed one through to a paid order.
 *
 * This spec is the reason `drivers/card-authentication.ts` exists. The
 * extension suite's twelve authentication contracts rest on a helper that
 * returns silently when no challenge appears, so eight of their recorded
 * residual risks say some version of "a frictionless or skipped challenge can
 * false-pass". Here the challenge is asserted, answered, and its effect on the
 * order is checked; a card that stops triggering authentication fails the run
 * rather than quietly passing it.
 *
 * The excluded-family decision still stands: this is journey coverage, not a
 * thin fidelity check, and it must not be sold as one.
 */

const CONTRACT_SUCCESS =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-purchase.spec.ts:42::WooCommerce Blocks › Successful purchase › using a 3DS card';
const CONTRACT_DECLINE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card has been declined.';
const CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection false › using a 3DS card',
	CONTRACT_SUCCESS,
];

const PROVIDER_CAPABILITY = 'card-authentication';
const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_STATUSES = [ 'processing', 'completed' ];
const UNPAID_STATUSES = [ 'pending', 'failed' ];
const RECEIPT_TIMEOUT_MS = 30_000;
const CHECKOUT_RESPONSE_TIMEOUT_MS = 60_000;
const STORE_CHECKOUT_PATH = '/wp-json/wc/store/v1/checkout';
const AUTHENTICATION_FRAME =
	'body > div > iframe[name^="__privateStripeFrame"]';
// Native's own copy for payment_intent_authentication_failure.
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';
const CARD_DECLINED_TEXT = 'Your card has been declined.';

/**
 * Selects the WooPayments card option, which a returning shopper's checkout
 * may already have selected.
 */
async function selectCardPaymentOption( page: Page ): Promise< void > {
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
}

/**
 * Blocks checkout address and payment-method selection.
 *
 * The card checkout driver has an equivalent, but it is private and lives in a
 * file inside closed contracts' source bundles, where adding an export would
 * invalidate their evidence hashes. Kept in step with that helper by hand until
 * one of those closures is re-attested for its own reason.
 */
async function fillBlocksCheckoutDetails(
	page: Page,
	runId: string
): Promise< void > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const address = ( await shipping.isVisible() ) ? shipping : billing;
	const country = address.getByRole( 'combobox', {
		name: 'Country/Region',
	} );

	// A shopper who has checked out before arrives with the address already
	// resolved: the group still renders, collapsed behind an Edit button, with
	// no editable country field. Gate on the field the fill actually needs
	// rather than on the group, which is present either way. Supplying an
	// address is a precondition for reaching payment, not something this spec
	// asserts, so there is nothing to prove by re-entering one.
	if ( ! ( await country.isVisible() ) ) {
		await selectCardPaymentOption( page );
		return;
	}

	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ runId }@example.com` );
	await address
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( 'US' );
	await address.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await address
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await address
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( '123 Test Street' );
	await address
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( 'San Francisco' );
	await address
		.getByRole( 'combobox', { name: 'State', exact: true } )
		.selectOption( 'CA' );
	await address.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94107' );
	await address
		.getByRole( 'textbox', { name: 'Phone (optional)' } )
		.fill( '5555550100' );
	await selectCardPaymentOption( page );
}

async function fillThreeDsCard(
	session: ProviderWriteSession,
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	const frame = page.frameLocator(
		getBlocksCardFrameSelector( session.runtime )
	);

	await enterProviderCardTriple( frame, card, 'Blocks checkout' );
	await page.getByRole( 'button', { name: /place order/i } ).focus();
}

interface OrderSnapshot {
	status: string;
	total: string;
	currency: string;
	paymentMethod: string;
	runId: string;
	intentId: string;
	chargeId: string;
}

async function readOrderSnapshot(
	session: ProviderWriteSession,
	orderId: number
): Promise< OrderSnapshot > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/orders/${ orderId }`
	);

	if ( ! response.ok() ) {
		throw new Error(
			`Could not read order ${ orderId }: HTTP ${ response.status() } ${ await response.text() }`
		);
	}

	const order = ( await response.json() ) as {
		id?: unknown;
		status?: unknown;
		total?: unknown;
		currency?: unknown;
		payment_method?: unknown;
		meta_data?: unknown;
	};
	if (
		order.id !== orderId ||
		typeof order.status !== 'string' ||
		typeof order.total !== 'string' ||
		typeof order.currency !== 'string' ||
		typeof order.payment_method !== 'string'
	) {
		throw new Error( `Order ${ orderId } carried no status.` );
	}
	const meta = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const metaValue = ( key: string ): string => {
		const entry = meta.find( ( item ) => item.key === key );
		return typeof entry?.value === 'string' ? entry.value : '';
	};

	return {
		status: order.status,
		total: order.total,
		currency: order.currency.toUpperCase(),
		paymentMethod: order.payment_method,
		runId: metaValue( '_e2e_woopayments_run_id' ),
		intentId: metaValue( '_intent_id' ),
		chargeId: metaValue( '_charge_id' ),
	};
}

function isStoreCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const url = new URL( request.url() );
		const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
		return (
			url.pathname.replace( /\/+$/, '' ) === STORE_CHECKOUT_PATH ||
			restRoute.replace( /\/+$/, '' ) === '/wc/store/v1/checkout'
		);
	} catch {
		return false;
	}
}

interface BlocksAuthenticationDispatch {
	orderId: number;
	intentId: string;
	orderKey: string;
	responseStatus: number;
	checkoutRequestCount: number;
	checkoutResponseCount: number;
	orderStatusUpdates: Array< { orderId: string; intentId: string } >;
}

interface ChallengeCheckoutOutcome {
	evidence: CardAuthenticationEvidence;
	dispatch: BlocksAuthenticationDispatch;
	reachedReceipt: boolean;
	url: string;
}

/**
 * Drives one blocks checkout with the challenge card and answers the
 * challenge the way the caller asks.
 */
async function checkoutWithChallenge(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	options: {
		card: ProviderTestCard;
		response: 'complete' | 'fail';
		expected: 'receipt' | 'error';
		errorText?: string;
		journal: string;
	}
): Promise< ChallengeCheckoutOutcome > {
	await session.logInAsCustomer( page );

	// Same navigation and add-to-cart shape the card checkout driver uses, so
	// this journey differs from a plain card purchase only in the card and the
	// challenge that follows.
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutDetails( page, session.runId );

	await fillThreeDsCard( session, page, options.card );

	let checkoutRequestCount = 0;
	let checkoutResponseCount = 0;
	const orderStatusUpdates: Array< {
		orderId: string;
		intentId: string;
	} > = [];
	const observeRequest = ( request: Request ): void => {
		if ( isStoreCheckoutRequest( request ) ) {
			checkoutRequestCount += 1;
			return;
		}
		if ( request.method() !== 'POST' ) {
			return;
		}
		const body = new URLSearchParams( request.postData() ?? '' );
		if ( body.get( 'action' ) === 'update_order_status' ) {
			orderStatusUpdates.push( {
				orderId: body.get( 'order_id' ) ?? '',
				intentId: body.get( 'intent_id' ) ?? '',
			} );
		}
	};
	const observeResponse = ( response: Response ): void => {
		if ( isStoreCheckoutRequest( response.request() ) ) {
			checkoutResponseCount += 1;
		}
	};
	page.on( 'request', observeRequest );
	page.on( 'response', observeResponse );

	try {
		return await session.withProviderSubmissionJournal(
			options.journal,
			async () => {
				const checkoutResponsePromise = page.waitForResponse(
					( response ) =>
						isStoreCheckoutRequest( response.request() ),
					{ timeout: CHECKOUT_RESPONSE_TIMEOUT_MS }
				);
				await submitBlocksCheckout( page, async ( button ) => {
					await session.performWrite( () => button.click() );
					return 'dispatched';
				} );

				let checkoutResponse: Response;
				try {
					checkoutResponse = await checkoutResponsePromise;
				} catch ( error ) {
					if ( checkoutRequestCount === 0 ) {
						throw new ProviderSubmissionNotStartedError(
							'The Blocks 3DS submission never dispatched a checkout request.',
							{ cause: error }
						);
					}
					throw new ResourceQuarantineRequiredError(
						`The Blocks 3DS submission dispatched ${ checkoutRequestCount } checkout request(s) but produced no response.`,
						'uncertain-provider-write',
						error
					);
				}

				const body = ( await checkoutResponse.json() ) as {
					order_id?: unknown;
					order_key?: unknown;
				};
				if (
					! Number.isSafeInteger( body.order_id ) ||
					Number( body.order_id ) <= 0 ||
					typeof body.order_key !== 'string' ||
					! body.order_key
				) {
					throw new Error(
						'The Blocks checkout response carried no exact order identity.'
					);
				}
				const orderId = body.order_id as number;
				const orderKey = body.order_key;
				const confirmation =
					findBlocksPaymentIntentConfirmation( body );
				if ( ! confirmation ) {
					throw new Error(
						'The Blocks checkout response carried no PaymentIntent confirmation.'
					);
				}
				if ( confirmation.orderId !== orderId ) {
					throw new Error(
						'The Blocks checkout response and confirmation named different orders.'
					);
				}

				// Attribute the order before answering the challenge. Once Complete
				// is activated, both settlement and a provider decline are real
				// outcomes that must remain traceable to this run.
				await session.setOrderRunId(
					confirmation.orderId,
					session.runId
				);

				const evidence = await completeCardAuthentication( {
					expectation: 'challenge',
					response: options.response,
					browser: new PlaywrightCardAuthenticationBrowser( page ),
				} );

				if ( options.expected === 'receipt' ) {
					await page.waitForURL( /order-received/, {
						timeout: RECEIPT_TIMEOUT_MS,
					} );
				} else {
					if ( ! options.errorText ) {
						throw new Error(
							'An expected Blocks 3DS error requires exact shopper copy.'
						);
					}
					await page
						.getByText( options.errorText, { exact: true } )
						.first()
						.waitFor( {
							state: 'visible',
							timeout: RECEIPT_TIMEOUT_MS,
						} );
				}

				if (
					checkoutRequestCount !== 1 ||
					checkoutResponseCount !== 1
				) {
					throw new ResourceQuarantineRequiredError(
						`The Blocks 3DS interval observed ${ checkoutRequestCount } checkout request(s) and ${ checkoutResponseCount } response(s); exactly one of each is required.`,
						'uncertain-provider-write'
					);
				}

				return {
					evidence,
					dispatch: {
						...confirmation,
						orderKey,
						responseStatus: checkoutResponse.status(),
						checkoutRequestCount,
						checkoutResponseCount,
						orderStatusUpdates,
					},
					reachedReceipt: page.url().includes( 'order-received' ),
					url: page.url(),
				};
			}
		);
	} finally {
		page.off( 'request', observeRequest );
		page.off( 'response', observeResponse );
	}
}

test.describe( 'WooPayments native card authentication', () => {
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test(
		'a completed Blocks 3DS challenge settles one exact USD 10.99 order, PaymentIntent, and captured charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_SUCCESS,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			test.info().annotations.push(
				...CONTRACT_IDS.map( ( contractId ) => ( {
					type: 'contract',
					description: contractId,
				} ) )
			);

			pilotRuntime.requireApprovedProviderFixture( PROVIDER_CAPABILITY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-card-authentication' },
				async () => {
					const baselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const product =
						await pilotRuntime.createOwnedProduct( PRICE );

					const { evidence, dispatch, reachedReceipt, url } =
						await checkoutWithChallenge(
							pilotRuntime,
							page,
							product,
							{
								card: THREE_DS_2_CARD,
								response: 'complete',
								expected: 'receipt',
								journal: '3ds-checkout-complete',
							}
						);

					// The challenge happened, was answered, and went away.
					expect( evidence.authenticationSurfacePresented ).toBe(
						true
					);
					expect( evidence.challengePresented ).toBe( true );
					expect( evidence.response ).toBe( 'complete' );
					expect( evidence.challengeDismissed ).toBe( true );

					// A completed challenge must produce a paid order, not just a
					// dismissed dialog.
					expect(
						reachedReceipt,
						`a completed challenge must reach the receipt; stopped at ${ url }`
					).toBe( true );
					expect( pilotRuntime.getOrderIdFromUrl( url ) ).toBe(
						dispatch.orderId
					);
					await expect(
						page.getByRole( 'heading', { name: 'Order received' } )
					).toBeVisible();
					const receiptKey = new URL( url ).searchParams.get( 'key' );
					expect( receiptKey ).toBe( dispatch.orderKey );
					expect( dispatch.responseStatus ).toBe( 200 );
					expect( dispatch.checkoutRequestCount ).toBe( 1 );
					expect( dispatch.checkoutResponseCount ).toBe( 1 );
					expect( dispatch.orderStatusUpdates ).toEqual( [
						{
							orderId: String( dispatch.orderId ),
							intentId: dispatch.intentId,
						},
					] );

					const payment = await readSettledClassicPayment(
						pilotRuntime,
						dispatch.orderId,
						dispatch.intentId
					);
					expect( payment.runId ).toBe( pilotRuntime.runId );
					expect( payment.orderId ).toBe( dispatch.orderId );
					expect( payment.orderKey ).toBe( dispatch.orderKey );
					expect( payment.intentId ).toBe( dispatch.intentId );
					expect( payment.amountMinor ).toBe( AMOUNT_MINOR );
					expect( payment.currency ).toBe( CURRENCY );
					expect( PAID_STATUSES ).toContain( payment.orderStatus );
					expect( payment.providerStatus ).toBe( 'succeeded' );
					expect( payment.chargeStatus ).toBe( 'succeeded' );
					expect( payment.chargeCaptured ).toBe( true );
					expect( payment.occurrenceCount ).toBe( 1 );
					expect( payment.captureOccurrenceCount ).toBe( 1 );
					const order = await readOrderSnapshot(
						pilotRuntime,
						dispatch.orderId
					);
					expect( order.paymentMethod ).toBe(
						'woocommerce_payments'
					);

					const orders = await readOrderDeltaAfter(
						pilotRuntime,
						baselineOrderId
					);
					expect( orders.newOrderIds ).toEqual( [
						dispatch.orderId,
					] );
					expect( orders.paidOrderIds ).toEqual( [
						dispatch.orderId,
					] );
				}
			);
		}
	);

	test(
		'a completed Blocks 3DS challenge that is declined leaves the same PaymentIntent unpaid and restores Place order',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DECLINE,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { page, pilotRuntime } ) => {
			pilotRuntime.requireApprovedProviderFixture( PROVIDER_CAPABILITY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-card-authentication-declined' },
				async () => {
					const baselineOrderId =
						await readHighestOrderId( pilotRuntime );
					const product =
						await pilotRuntime.createOwnedProduct( PRICE );
					const { evidence, dispatch, reachedReceipt, url } =
						await checkoutWithChallenge(
							pilotRuntime,
							page,
							product,
							{
								card: THREE_DS_DECLINED_CARD,
								response: 'complete',
								expected: 'error',
								errorText: CARD_DECLINED_TEXT,
								journal: '3ds-checkout-declined',
							}
						);

					expect( evidence.authenticationSurfacePresented ).toBe(
						true
					);
					expect( evidence.challengePresented ).toBe( true );
					expect( evidence.response ).toBe( 'complete' );
					expect( evidence.challengeDismissed ).toBe( true );
					expect( dispatch.responseStatus ).toBe( 200 );
					expect( dispatch.checkoutRequestCount ).toBe( 1 );
					expect( dispatch.checkoutResponseCount ).toBe( 1 );
					expect(
						dispatch.orderStatusUpdates,
						'a provider decline after authentication must not ask native to complete the order'
					).toEqual( [] );

					const intent = await readFailedAuthenticationIntent(
						pilotRuntime,
						dispatch.intentId
					);
					expect( intent.id ).toBe( dispatch.intentId );
					expect( intent.status ).toBe( 'requires_payment_method' );
					expect( intent.lastPaymentErrorCode ).toBe(
						'card_declined'
					);
					expect(
						intent.chargeCount,
						'the provider records one failed charge attempt for this post-auth decline'
					).toBe( 1 );

					const providerResponse = await pilotRuntime.adminApi.get(
						`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
							dispatch.intentId
						) }`
					);
					expect( providerResponse.ok() ).toBe( true );
					const providerIntent =
						( await providerResponse.json() ) as {
							id?: unknown;
							amount?: unknown;
							amount_received?: unknown;
							currency?: unknown;
							charges?: {
								data?: Array< {
									status?: unknown;
									paid?: unknown;
									captured?: unknown;
									amount_captured?: unknown;
									failure_code?: unknown;
								} >;
							};
						};
					expect( providerIntent.id ).toBe( dispatch.intentId );
					expect( providerIntent.amount ).toBe( AMOUNT_MINOR );
					expect( providerIntent.currency ).toBe( 'usd' );
					expect( [ 0, null ] ).toContain(
						providerIntent.amount_received ?? null
					);
					expect( providerIntent.charges?.data ).toEqual( [
						expect.objectContaining( {
							status: 'failed',
							paid: false,
							captured: false,
							amount_captured: 0,
							failure_code: 'card_declined',
						} ),
					] );

					const order = await readOrderSnapshot(
						pilotRuntime,
						dispatch.orderId
					);
					expect( UNPAID_STATUSES ).toContain( order.status );
					expect( order.total ).toBe( PRICE );
					expect( order.currency ).toBe( CURRENCY );
					expect( order.paymentMethod ).toBe(
						'woocommerce_payments'
					);
					expect( order.runId ).toBe( pilotRuntime.runId );
					expect( order.intentId ).toBe( dispatch.intentId );
					expect( order.chargeId ).toBe( '' );

					const orders = await readOrderDeltaAfter(
						pilotRuntime,
						baselineOrderId
					);
					expect( orders.newOrderIds ).toEqual( [
						dispatch.orderId,
					] );
					expect( orders.paidOrderIds ).toEqual( [] );

					expect( reachedReceipt ).toBe( false );
					expect( url ).not.toContain( 'order-received' );
					await expect( page ).toHaveURL( /\/checkout\/?(?:\?.*)?$/ );
					await expect(
						page
							.getByText( CARD_DECLINED_TEXT, { exact: true } )
							.first()
					).toBeVisible();
					await expect(
						page.locator( '#a11y-speak-assertive' )
					).toHaveText( CARD_DECLINED_TEXT );

					const placeOrder = page.getByRole( 'button', {
						name: /place order/i,
					} );
					await expect( placeOrder ).toBeVisible();
					await expect( placeOrder ).toBeEnabled();
					await expect( placeOrder ).not.toHaveClass(
						/wc-block-components-checkout-place-order-button--loading/
					);
					await expect(
						placeOrder.locator( '.wc-block-components-spinner' )
					).toHaveCount( 0 );
					await expect(
						page.locator( AUTHENTICATION_FRAME )
					).toBeHidden();
					await expect(
						page.getByRole( 'heading', { name: 'Order received' } )
					).toHaveCount( 0 );
				}
			);
		}
	);

	test( `leaves the order unpaid when the challenge is failed ${ tags.WOOPAYMENTS_PROVIDER }`, async ( {
		page,
		pilotRuntime,
	} ) => {
		pilotRuntime.requireApprovedProviderFixture( PROVIDER_CAPABILITY );
		await pilotRuntime.assertCurrentRuntimeReady( 'native' );

		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'shopper-card-authentication-failed' },
			async () => {
				const product = await pilotRuntime.createOwnedProduct( PRICE );

				const { evidence, reachedReceipt } =
					await checkoutWithChallenge( pilotRuntime, page, product, {
						card: THREE_DS_2_CARD,
						response: 'fail',
						expected: 'error',
						errorText: AUTHENTICATION_FAILURE_TEXT,
						journal: '3ds-checkout-fail',
					} );

				expect( evidence.challengePresented ).toBe( true );
				expect( evidence.response ).toBe( 'fail' );

				// The negative control for the test above. Without it, a
				// checkout that pays regardless of the challenge answer would
				// satisfy the positive case and prove nothing.
				expect(
					reachedReceipt,
					'a failed challenge must not reach the receipt'
				).toBe( false );
				// The shopper is told, and the wording is native's own
				// `payment_intent_authentication_failure` copy, so this
				// asserts the failure was mapped rather than that some error
				// appeared.
				await expect(
					page.getByText( AUTHENTICATION_FAILURE_TEXT ).first(),
					'a failed challenge must tell the shopper'
				).toBeVisible();

				// And is announced, not merely displayed. The notice carries
				// no alert role of its own, which is easy to misread as
				// silence; WordPress announces through a shared off-screen
				// region instead, and an error notice is assertive. Assert
				// the region a screen reader actually reads, so losing the
				// announcement fails here rather than passing because the
				// text is still on screen somewhere.
				await expect(
					page.locator( '#a11y-speak-assertive' ),
					'a failed challenge must be announced, not only shown'
				).toHaveText( AUTHENTICATION_FAILURE_TEXT );
			}
		);
	} );
} );
