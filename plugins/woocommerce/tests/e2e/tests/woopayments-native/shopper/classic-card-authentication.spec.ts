import type { Page, Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { getFakeUser } from '../../../utils/data';
import { wpEvalJson } from '../../../utils/cli';
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
 * Proves native's Classic checkout carries a real 3D Secure challenge through
 * to the exact provider records it claims, and turns away what it must (T.4
 * Batch P4b rewrite).
 *
 * The shipped Blocks journey (`shopper/card-authentication.spec.ts`) does not
 * cover these four contracts. Native's Classic surface is a different
 * integration: the gateway's payment form renders it, `woopayments-checkout.js`
 * drives it, the confirmation arrives as a hash the page consumes after the
 * checkout response rather than inside it, and failures land in native's own
 * `role="alert"` region instead of the Blocks notice and `wp.a11y`.
 *
 * Every settling case reads the PaymentIntent in its customer-action state
 * before the challenge is answered (from the checkout response's confirmation
 * hash) and again after, through `expectSettledCardPayment`, so the challenge
 * and the settled charge are provably the same payment. A frictionless or
 * absent challenge fails the run rather than passing it: `completeThreeDSChallenge`
 * throws when no challenge is presented.
 *
 * Card choice. The client fixtures split these rows across `3ds`
 * (`4000002760003184`) and `3ds2` (`4000000000003220`). The 2026-08-10 decision
 * settled that split: neither implementation distinguishes the protocols, so
 * one contract per journey is driven by the 3DS2 card
 * (`TEST_CARDS.threeDSChallenge`). The forced-failure row keeps the client's
 * own `declined-3ds` number (`TEST_CARDS.declinedAfter3DS`), because that
 * row's fixture is the card, not the protocol.
 */

const CONTRACT_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection false › using a 3DS card';
const CONTRACT_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection true › using a 3DS card';
const CONTRACT_AUTHENTICATION_FAILURE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:175::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to invalid 3DS card';
const CONTRACT_SAVE_ON_CHECKOUT =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:68::Saved cards › When using a 3ds card added on checkout › should save the card';

const FAMILY_TAGS = [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ];

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
// What "paid" means for the no-payment-context claim: a status a failed
// intent can never leave an order in. Wider than PAID_ORDER_STATUSES on
// purpose - an order a dispute or a manual capture later moves to on-hold or
// refunded is still evidence of a payment context the refusal must not have
// created.
const NO_PAYMENT_CONTEXT_PAID_STATUSES = [
	'processing',
	'completed',
	'on-hold',
	'refunded',
];
// An order the store made but never took money for. The Classic script does
// not report a failed challenge back to the server, so native leaves the
// order as it last saw it; both values are unpaid and neither is a payment.
const UNPAID_ORDER_STATUSES = [ 'pending', 'failed' ];

/**
 * The provider's message for `payment_intent_authentication_failure`.
 *
 * On this surface the displayed string comes from the provider error that
 * `stripe.handleNextAction` rejects with, which native passes to its error
 * region unchanged - it is not native's server-side mapping being exercised,
 * although the two strings are identical (`WooPaymentsErrorMessages` maps the
 * same code to the same sentence). The test binds the text to the exact
 * intent's `last_payment_error.code`, so it proves more than the string alone.
 */
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';
const AUTHENTICATION_FAILURE_CODE = 'payment_intent_authentication_failure';
/** Native's own copy when card-testing protection turns a submission away. */
const CARD_TESTING_REJECTION_TEXT =
	"We're not able to process this payment. Please refresh the page and try again.";

const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const SAVED_CARD_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/saved-card-evidence';
const CLASSIC_CHECKOUT_FORM = 'form.checkout.woocommerce-checkout';
// Native builds this in `WooPaymentsIntentCodec::confirmation_redirect_for()`;
// `woopayments-checkout.js` parses it with the same shape.
const CONFIRMATION_HASH_PATTERN =
	/^#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/;

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments classic-3ds ${ random() }`,
			type: 'simple',
			virtual: true,
			regular_price: PRICE,
			status: 'publish',
		} )
	).data as { id: number };
}

async function deleteProduct(
	restApi: ApiClient,
	productId: number
): Promise< void > {
	await restApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
		force: true,
	} );
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

async function readNewOrders(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< Array< Record< string, unknown > > > {
	return (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data.filter(
		( order: { id: number } ) => order.id > baselineOrderId
	) as Array< Record< string, unknown > >;
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const value = entries.find( ( entry ) => entry.key === key )?.value;
	return typeof value === 'string' ? value : '';
}

/** Whether `error` is the provider's transient "another request holds this object" answer. */
function isLockTimeout( error: unknown ): boolean {
	const status =
		typeof error === 'object' && error !== null && 'response' in error
			? ( error as { response?: { status?: unknown } } ).response?.status
			: undefined;
	return status === 429;
}

/** Counts the charges an intent carries, across both shapes the payment-details controller passes through. */
function chargeCountOf( intent: Record< string, unknown > ): number {
	const charges = intent.charges as { data?: unknown[] } | undefined;
	if ( charges && Array.isArray( charges.data ) ) {
		return charges.data.length;
	}
	if ( typeof intent.latest_charge === 'string' && intent.latest_charge ) {
		return 1;
	}
	return 0;
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

/** Reads the confirmation hash native answers a customer-action submission with. */
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
		.locator( 'input[name="payment_method"][value="woocommerce_payments"]' )
		.check();
}

interface SubmissionIntervalCounts {
	requestCount: number;
	responseCount: number;
}

interface ClassicChallengeDispatch {
	orderId: number;
	intentId: string;
	intentType: 'pi' | 'si';
	gateway: string | null;
	saveRequested: boolean;
	fraudToken: string | null;
	/**
	 * Detaches the request/response listeners and reports how many checkout
	 * exchanges happened over the whole submission interval. Call this once
	 * the interval has actually closed - at the receipt, or at the failure
	 * notice - never right after the first response, so a second submission
	 * fired while the challenge is open (the regression the whole-interval
	 * observer exists to catch) is still counted.
	 */
	stop: () => SubmissionIntervalCounts;
}

/**
 * Activates Place order exactly once and reads the checkout response's
 * customer-action confirmation hash, before the challenge is answered. The
 * request/response listeners stay attached until the caller calls
 * `dispatch.stop()`, so they keep counting through the challenge.
 */
async function submitClassicChallengeCheckout(
	page: Page
): Promise< ClassicChallengeDispatch > {
	let requestCount = 0;
	let responseCount = 0;
	let gateway: string | null = null;
	let saveRequested = false;
	let fraudToken: string | null = null;
	let stopped = false;
	const onRequest = ( request: Request ): void => {
		if ( ! isClassicCheckoutRequest( request ) ) {
			return;
		}
		requestCount += 1;
		const params = new URLSearchParams( request.postData() ?? '' );
		gateway = params.get( 'payment_method' );
		saveRequested =
			params.get( 'wc-woocommerce_payments-new-payment-method' ) ===
			'true';
		fraudToken = params.get( 'wcpay-fraud-prevention-token' );
	};
	const onResponse = ( response: Response ): void => {
		if ( isClassicCheckoutRequest( response.request() ) ) {
			responseCount += 1;
		}
	};
	page.on( 'request', onRequest );
	page.on( 'response', onResponse );
	const stop = (): SubmissionIntervalCounts => {
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
				`Classic checkout did not accept the submission: ${ JSON.stringify(
					body
				) }`
			);
		}
		const orderId = Number( body.order_id );
		const { intentType, intentId } = parseConfirmationHash(
			String( body.redirect ?? '' ),
			orderId
		);
		return {
			orderId,
			intentId,
			intentType,
			gateway,
			saveRequested,
			fraudToken,
			stop,
		};
	} catch ( error ) {
		stop();
		throw error;
	}
}

/**
 * The half of every settling journey that is identical across the three of
 * them: the intent awaited customer action, with no charge, before the
 * challenge was answered. Does not touch the submission-interval counts -
 * the interval is still open at this point, so the caller asserts those
 * itself once it calls `dispatch.stop()`.
 */
async function expectPendingChallenge(
	restApi: ApiClient,
	dispatch: ClassicChallengeDispatch
): Promise< void > {
	expect( dispatch.gateway ).toBe( 'woocommerce_payments' );

	const intent = await getPaymentIntent( restApi, dispatch.intentId );
	expect( intent.id ).toBe( dispatch.intentId );
	expect(
		intent.status,
		'the challenge must be answered against an intent awaiting customer action'
	).toBe( 'requires_action' );
	expect(
		chargeCountOf( intent ),
		'no charge may exist before the challenge is answered'
	).toBe( 0 );
}

/** Asserts the whole submission interval asked the store exactly once. */
function expectSingleSubmissionInterval(
	dispatch: Pick< ClassicChallengeDispatch, 'stop' >
): void {
	const { requestCount, responseCount } = dispatch.stop();
	expect(
		requestCount,
		'one Place order activation must ask the store exactly once through the whole interval, challenge included'
	).toBe( 1 );
	expect( responseCount ).toBe( 1 );
}

async function pollFailedIntent(
	restApi: ApiClient,
	intentId: string
): Promise< Record< string, unknown > > {
	const deadline = Date.now() + 60_000;
	for (;;) {
		try {
			const intent = await getPaymentIntent( restApi, intentId );
			if (
				intent.status === 'requires_payment_method' ||
				Date.now() >= deadline
			) {
				return intent;
			}
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
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

async function countSettledBlockingOverlays(
	page: Page,
	timeoutMs = 5_000
): Promise< number > {
	const overlay = page.locator( `${ CLASSIC_CHECKOUT_FORM } .blockUI` );
	const deadline = Date.now() + timeoutMs;
	for (;;) {
		const count = await overlay.count();
		if ( count === 0 || Date.now() >= deadline ) {
			return count;
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 200 ) );
	}
}

interface CardTestingProtectionFlag {
	existed: boolean;
	value: unknown;
}

/** Reads the raw stored flag without changing it, for an exact post-restore comparison. */
async function readCardTestingProtectionFlag(): Promise< CardTestingProtectionFlag > {
	return wpEvalJson< CardTestingProtectionFlag >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to read.' );
		}
		$existed = array_key_exists( 'card_testing_protection_eligible', $account['data'] );
		return array(
			'existed' => $existed,
			'value' => $existed ? $account['data']['card_testing_protection_eligible'] : null,
		);
	` );
}

/** Forces (or restores) the account's card-testing-protection eligibility, byte-restorable. */
async function setCardTestingProtectionEligible(
	eligible: boolean
): Promise< CardTestingProtectionFlag > {
	return wpEvalJson< CardTestingProtectionFlag >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to force.' );
		}
		$existed = array_key_exists( 'card_testing_protection_eligible', $account['data'] );
		$original = $existed ? $account['data']['card_testing_protection_eligible'] : null;
		$account['data']['card_testing_protection_eligible'] = ${
			eligible ? 'true' : 'false'
		};
		update_option( 'wcpay_account_data', $account );
		return array( 'existed' => $existed, 'value' => $original );
	` );
}

/** Restores the flag to exactly the shape `setCardTestingProtectionEligible` read: present or absent. */
async function restoreCardTestingProtectionEligible(
	original: CardTestingProtectionFlag
): Promise< void > {
	await wpEvalJson< unknown >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to restore.' );
		}
		if ( ${ original.existed ? 'true' : 'false' } ) {
			$account['data']['card_testing_protection_eligible'] = json_decode( '${ JSON.stringify(
				original.value ?? null
			) }' );
		} else {
			unset( $account['data']['card_testing_protection_eligible'] );
		}
		update_option( 'wcpay_account_data', $account );
		return true;
	` );
}

async function readCardTestingProtectionEligibility(
	restApi: ApiClient
): Promise< unknown > {
	return ( await restApi.get( 'wc/v3/payments/accounts' ) ).data
		.card_testing_protection_eligible;
}

async function getSavedCardEvidence(
	restApi: ApiClient,
	customerUsername: string
): Promise< {
	tokens: Array< { tokenId: number; paymentMethodId: string } >;
} > {
	const data = (
		await restApi.get( SAVED_CARD_EVIDENCE_ROUTE, {
			customer_username: customerUsername,
		} )
	).data as {
		tokens?: Array< { token_id: number; payment_method_id: string } >;
	};
	return {
		tokens: ( data.tokens ?? [] ).map( ( token ) => ( {
			tokenId: token.token_id,
			paymentMethodId: token.payment_method_id,
		} ) ),
	};
}

test.describe( 'WooPayments native Classic checkout card authentication', () => {
	// Serial on purpose. Each case provisions its own product and, in the
	// save-to-account case, its own shopper; a failure must not spend more
	// provider budget on an unclear store.
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		'a classic-checkout 3DS challenge completed by the shopper settles one exact order, PaymentIntent, and captured charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_PROTECTION_FALSE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			// Card-testing protection is left exactly as the store has it.
			// This contract is the protection-off half, and the account
			// reports the eligibility flag false, so nothing is forced and
			// nothing is restored.
			expect(
				await readCardTestingProtectionEligibility( restApi ),
				'this contract is the protection-off half and must not run against a protected store'
			).toBe( false );

			const product = await createRunProduct( restApi );
			try {
				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', { name: 'Add to cart', exact: true } )
					.click();
				await page.goto( 'classic-checkout/' );
				await fillClassicBilling(
					page,
					`woopayments-${ random() }@example.com`
				);
				await fillCardDetails(
					page,
					TEST_CARDS.threeDSChallenge,
					'classic'
				);

				const dispatch = await submitClassicChallengeCheckout( page );
				await expectPendingChallenge( restApi, dispatch );
				expect(
					dispatch.saveRequested,
					'a purchase that saves nothing must not ask to save'
				).toBe( false );

				await completeThreeDSChallenge( page, 'complete' );
				await page.waitForURL( /\/order-received\/[1-9]\d*/, {
					timeout: 60_000,
				} );
				await expect(
					page.getByRole( 'heading', { name: 'Order received' } )
				).toBeVisible();
				expect(
					Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] )
				).toBe( dispatch.orderId );
				await expectReceiptOrderKey( restApi, page, dispatch.orderId );
				expectSingleSubmissionInterval( dispatch );

				const payment = await expectSettledCardPayment(
					restApi,
					dispatch.orderId,
					{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
				);
				expect( payment.intentId ).toBe( dispatch.intentId );
				expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
			} finally {
				await deleteProduct( restApi, product.id );
			}
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
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			let original: CardTestingProtectionFlag | undefined;
			let caseError: unknown;
			try {
				// Forced-eligibility boundary, carried here deliberately. The
				// target account reports `card_testing_protection_eligible:
				// false`, so this run supplies that premise itself, by
				// writing the store's own cached mirror directly and
				// byte-restoring it. What follows therefore establishes that
				// native enforces protection at its own boundary and still
				// settles an authenticated card - not that the provider
				// grants this account the capability.
				original = await setCardTestingProtectionEligible( true );
				expect(
					await readCardTestingProtectionEligibility( restApi ),
					'the forced premise must be in effect before either submission'
				).toBe( true );

				const product = await createRunProduct( restApi );
				try {
					// Half one: a token-bearing submission settles exactly as
					// the protection-off case does.
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
						.click();
					await page.goto( 'classic-checkout/' );
					await fillClassicBilling(
						page,
						`woopayments-${ random() }@example.com`
					);
					await fillCardDetails(
						page,
						TEST_CARDS.threeDSChallenge,
						'classic'
					);

					const exposedToken = await page.evaluate(
						() =>
							( window as unknown as Record< string, unknown > )
								.wcpayFraudPreventionToken
					);
					expect(
						typeof exposedToken === 'string' && exposedToken !== '',
						'a token-bearing submission requires a real exposed fraud-prevention token'
					).toBe( true );

					const admitted =
						await submitClassicChallengeCheckout( page );
					await expectPendingChallenge( restApi, admitted );
					expect(
						admitted.fraudToken,
						'the admitted submission must carry the exact exposed token'
					).toBe( exposedToken );

					await completeThreeDSChallenge( page, 'complete' );
					await page.waitForURL( /\/order-received\/[1-9]\d*/, {
						timeout: 60_000,
					} );
					await expect(
						page.getByRole( 'heading', { name: 'Order received' } )
					).toBeVisible();
					await expectReceiptOrderKey(
						restApi,
						page,
						admitted.orderId
					);
					expectSingleSubmissionInterval( admitted );

					const payment = await expectSettledCardPayment(
						restApi,
						admitted.orderId,
						{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
					);
					expect( payment.intentId ).toBe( admitted.intentId );
					expect( PAID_ORDER_STATUSES ).toContain(
						payment.orderStatus
					);

					// Half two: the same store, the same session, one
					// submission with the fraud-prevention token stripped
					// from the wire before it reaches native.
					const tokenlessBaselineOrderId =
						await readHighestOrderId( restApi );
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
						.click();
					await page.goto( 'classic-checkout/' );
					await fillClassicBilling(
						page,
						`woopayments-${ random() }@example.com`
					);
					await fillCardDetails(
						page,
						TEST_CARDS.threeDSChallenge,
						'classic'
					);

					let refusalRequestCount = 0;
					let refusalGateway: unknown;
					const rejectionRoute = '**/*wc-ajax=checkout*';
					await page.route( rejectionRoute, async ( route ) => {
						const request = route.request();
						const params = new URLSearchParams(
							request.postData() ?? ''
						);
						refusalRequestCount += 1;
						refusalGateway = params.get( 'payment_method' );
						params.delete( 'wcpay-fraud-prevention-token' );
						await route.continue( {
							postData: params.toString(),
						} );
					} );
					try {
						await page
							.getByRole( 'button', { name: /place order/i } )
							.click();
						const alerts = page.getByRole( 'alert' );
						await expect(
							alerts.filter( {
								hasText: CARD_TESTING_REJECTION_TEXT,
							} )
						).toBeVisible( { timeout: 30_000 } );
						await expect(
							alerts,
							'the shopper must be told, once, through an assertive notice'
						).toHaveCount( 1 );
						expect(
							( await alerts.first().innerText() )
								.replace( /\s+/g, ' ' )
								.trim()
						).toBe( CARD_TESTING_REJECTION_TEXT );
					} finally {
						await page.unroute( rejectionRoute );
					}
					expect( page.url() ).not.toContain( 'order-received' );
					expect(
						refusalRequestCount,
						'the refused half must dispatch exactly one checkout submission'
					).toBe( 1 );
					expect( refusalGateway ).toBe( 'woocommerce_payments' );
					await expect(
						page.getByRole( 'button', { name: /place order/i } )
					).toBeEnabled();
					expect( await countSettledBlockingOverlays( page ) ).toBe(
						0
					);

					// No payment context was created, and the admitted
					// payment did not gain a second charge behind it.
					const tokenlessDelta = await readNewOrders(
						restApi,
						tokenlessBaselineOrderId
					);
					for ( const order of tokenlessDelta ) {
						expect(
							orderMeta( order, '_intent_id' ),
							'a refused submission must create no order carrying a provider intent id'
						).toBe( '' );
						expect(
							orderMeta( order, '_charge_id' ),
							'a refused submission must create no order carrying a provider charge id'
						).toBe( '' );
						expect(
							orderMeta( order, '_payment_method_id' ),
							'a refused submission must create no order carrying a provider payment method id'
						).toBe( '' );
					}
					expect(
						tokenlessDelta.filter( ( order ) =>
							NO_PAYMENT_CONTEXT_PAID_STATUSES.includes(
								String( order.status )
							)
						),
						'a refused submission must leave no paid order'
					).toEqual( [] );

					const recheck = await expectSettledCardPayment(
						restApi,
						admitted.orderId,
						{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
					);
					expect( recheck ).toEqual( payment );
				} finally {
					await deleteProduct( restApi, product.id );
				}
			} catch ( error ) {
				caseError = error;
			} finally {
				if ( original !== undefined ) {
					try {
						await restoreCardTestingProtectionEligible( original );
						expect( await readCardTestingProtectionFlag() ).toEqual(
							original
						);
					} catch ( restoreError ) {
						if ( caseError === undefined ) {
							caseError = restoreError;
						} else {
							console.error(
								'WooPayments card-testing-protection restore failed after the primary case failure:',
								restoreError
							);
						}
					}
				}
			}
			if ( caseError !== undefined ) {
				throw caseError;
			}
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
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const product = await createRunProduct( restApi );
			try {
				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', { name: 'Add to cart', exact: true } )
					.click();
				await page.goto( 'classic-checkout/' );
				await fillClassicBilling(
					page,
					`woopayments-${ random() }@example.com`
				);
				await fillCardDetails(
					page,
					TEST_CARDS.declinedAfter3DS,
					'classic'
				);

				const dispatch = await submitClassicChallengeCheckout( page );
				await expectPendingChallenge( restApi, dispatch );

				await completeThreeDSChallenge( page, 'fail' );

				// The negative control. Without it, a checkout that paid
				// regardless of the answer would satisfy every other
				// assertion here.
				expect( page.url() ).not.toContain( 'order-received' );

				// The shopper is told through the region native prints for
				// this surface. It carries the assertive role itself: unlike
				// Blocks, nothing here routes through `wp.a11y.speak`, so
				// asserting `#a11y-speak-assertive` instead would be
				// asserting the wrong oracle.
				// Every WooPayments gateway box (Card, Klarna, …) prints its
				// own error box, so read the one inside the Card gateway.
				const paymentError = page.locator(
					'.payment_method_woocommerce_payments .wcpay-core-payment-errors'
				);
				await expect( paymentError ).toBeVisible( {
					timeout: 30_000,
				} );
				await expect( paymentError ).toHaveAttribute( 'role', 'alert' );
				expect( ( await paymentError.innerText() ).trim() ).toBe(
					AUTHENTICATION_FAILURE_TEXT
				);
				// The interval is closed now: the failure notice is this
				// surface's terminal state for a failed challenge, the same
				// role the receipt plays for a settled one.
				expectSingleSubmissionInterval( dispatch );
				await expect(
					page
						.locator( '#a11y-speak-assertive' )
						.filter( { hasText: AUTHENTICATION_FAILURE_TEXT } ),
					'the Classic surface announces through the notice role, not the Blocks speak region'
				).toHaveCount( 0 );

				// And can recover without reloading.
				await expect(
					page.getByRole( 'button', { name: /place order/i } )
				).toBeEnabled();
				expect( await countSettledBlockingOverlays( page ) ).toBe( 0 );
				expect(
					await page
						.locator(
							'#payment .wc_payment_methods input[type="radio"]'
						)
						.count()
				).toBeGreaterThan( 0 );

				// The displayed failure is joined to the provider's own
				// account of it, on the exact intent the challenge was
				// answered against.
				const intent = await pollFailedIntent(
					restApi,
					dispatch.intentId
				);
				expect( intent.id ).toBe( dispatch.intentId );
				expect( intent.status ).toBe( 'requires_payment_method' );
				expect(
					( intent.last_payment_error as { code?: unknown } | null )
						?.code
				).toBe( AUTHENTICATION_FAILURE_CODE );
				expect(
					chargeCountOf( intent ),
					'an unauthenticated intent must carry no charge'
				).toBe( 0 );

				const order = (
					await restApi.get(
						`${ ORDERS_ROUTE }/${ dispatch.orderId }`
					)
				).data as Record< string, unknown >;
				expect(
					UNPAID_ORDER_STATUSES,
					'the order must remain unpaid'
				).toContain( order.status );
				expect(
					orderMeta( order, '_charge_id' ),
					'an unpaid order must carry no charge'
				).toBe( '' );
				// Native writes the intent reference on some outcomes and
				// not others; either is honest for an unpaid order, and
				// referencing a different intent is not.
				expect(
					[ '', dispatch.intentId ],
					'the order may only reference the intent this submission created'
				).toContain( orderMeta( order, '_intent_id' ) );
			} finally {
				await deleteProduct( restApi, product.id );
			}
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
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const customer = getFakeUser( 'customer' );
			const created = (
				await restApi.post( 'wc/v3/customers', customer )
			).data as { id: number };
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

				const before = await getSavedCardEvidence(
					restApi,
					customer.username
				);
				const knownTokenIds = new Set(
					before.tokens.map( ( token ) => token.tokenId )
				);

				const product = await createRunProduct( restApi );
				try {
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
						.click();
					await page.goto( 'classic-checkout/' );
					await fillClassicBilling(
						page,
						`woopayments-${ random() }@example.com`
					);
					await fillCardDetails(
						page,
						TEST_CARDS.threeDSChallenge,
						'classic'
					);
					const save = page.getByRole( 'checkbox', {
						name: 'Save to account',
						exact: true,
					} );
					await save.check();
					await expect( save ).toBeChecked();

					const dispatch =
						await submitClassicChallengeCheckout( page );
					await expectPendingChallenge( restApi, dispatch );
					expect(
						dispatch.saveRequested,
						'the ticked control must reach the store as a save request'
					).toBe( true );

					await completeThreeDSChallenge( page, 'complete' );
					await page.waitForURL( /\/order-received\/[1-9]\d*/, {
						timeout: 60_000,
					} );
					await expect(
						page.getByRole( 'heading', { name: 'Order received' } )
					).toBeVisible();
					await expectReceiptOrderKey(
						restApi,
						page,
						dispatch.orderId
					);
					expectSingleSubmissionInterval( dispatch );

					const payment = await expectSettledCardPayment(
						restApi,
						dispatch.orderId,
						{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
					);
					expect( payment.intentId ).toBe( dispatch.intentId );
					expect( PAID_ORDER_STATUSES ).toContain(
						payment.orderStatus
					);

					const after = await getSavedCardEvidence(
						restApi,
						customer.username
					);
					const createdTokens = after.tokens.filter(
						( token ) => ! knownTokenIds.has( token.tokenId )
					);
					expect(
						createdTokens,
						'one authenticated save must create exactly one local token'
					).toHaveLength( 1 );
					expect(
						createdTokens[ 0 ].paymentMethodId,
						'the saved token must be the payment method this payment used'
					).toBe( payment.paymentMethodId );
					for ( const token of before.tokens ) {
						expect(
							after.tokens.find(
								( candidate ) =>
									candidate.tokenId === token.tokenId
							)?.paymentMethodId,
							'an unrelated token must not be remapped'
						).toBe( token.paymentMethodId );
					}

					const intent = await getPaymentIntent(
						restApi,
						payment.intentId
					);
					const providerCustomerId = String( intent.customer ?? '' );
					expect( providerCustomerId ).not.toBe( '' );
					const attached = (
						await restApi.get(
							`wc/v3/payments/customers/${ encodeURIComponent(
								providerCustomerId
							) }/payment_methods`
						)
					).data as Array< { id: string } >;
					expect(
						attached.filter(
							( method ) => method.id === payment.paymentMethodId
						),
						'the provider must hold exactly one attachment of that method'
					).toHaveLength( 1 );
				} finally {
					await deleteProduct( restApi, product.id );
				}
			} finally {
				// Deleting the customer deletes its saved tokens locally
				// (`wc_delete_user_data`) and native detaches them at the
				// provider (`WooPaymentsTokenServiceTest::test_detaches_native_card_payment_methods_when_token_is_deleted`),
				// so this leaves no live credential without a separate
				// My Account delete step.
				await restApi.delete( `wc/v3/customers/${ created.id }`, {
					force: true,
				} );
			}
		}
	);
} );
