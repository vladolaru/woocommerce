import type { Page, Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { getFakeUser } from '../../../utils/data';
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
	type ProviderTestCard,
} from '../../../utils/woopayments';

/**
 * Proves the My Account SetupIntent challenge native drives for a saved-card
 * add, and that the exact resulting token settles a real Classic PaymentIntent
 * challenge later (T.4 Batch P4b rewrite).
 *
 * The other five cases the old harness spec drove for this family moved to
 * PHPUnit in T.3 (see `FIDELITY-CLAIMS.md`'s `3ds-authentication` exclusion
 * notes): the 20-second cooldown refusal, paying with an already-saved token
 * creating no second token, deleting a token detaching its provider method,
 * and a Classic/Blocks checkout that saves a card creating exactly one token.
 * These two remain because they are the only cases that drive a real, hosted
 * SetupIntent challenge to a provable terminal state - one failed, one
 * completed and then spent.
 */

const CONTRACT_FAILED_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-payment-methods-add-fail.spec.ts:71::Payment Methods › when attempting to add a declined-3ds card › it should not add the card';
const CONTRACT_AUTHENTICATED_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:146::Shopper can save and delete cards › Testing card: 3ds › should add the 3ds card as a new payment method';
const CONTRACT_AUTHENTICATED_PURCHASE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:196::Shopper can save and delete cards › Testing card: 3ds › should be able to purchase with the saved 3ds card';
// WooPayments 11.1.0 on :8082 is the oracle for the plugin-era saved-token behavior this contract preserves.
const CONTRACT_HISTORICAL_AUTHENTICATED_PURCHASE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:93::Saved cards › When using a 3ds card added on checkout › should process a payment with the saved card';

const FAMILY_TAGS = [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ];

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';
const SETUP_AUTHENTICATION_FAILURE_CODE = 'setup_intent_authentication_failure';

const CUSTOMERS_ROUTE = 'wc/v3/customers';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const SAVED_CARD_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/saved-card-evidence';
const SUBSCRIPTION_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/subscription-evidence';
const ADD_FORM = '#add_payment_method';
// `:visible`-scoped: WooCommerce can render more than one WooPayments-family
// gateway box on a page (Card, Klarna, …), each printing this same static id
// in its own fields template, and only one of them is ever visible.
const ADD_ERROR = '#wcpay-core-payment-errors:visible';
const ADD_SUCCESS_NOTICE = 'Payment method successfully added.';
const CONFIRMATION_HASH_PATTERN =
	/^#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/;

interface SavedToken {
	tokenId: number;
	paymentMethodId: string;
	isDefault: boolean;
}

/**
 * Reads the shopper's local tokens plus the store's own mirror of the
 * provider customer it resolved for them (empty when the shopper has never
 * reached the provider). The route sends both in its unnamed-evidence branch
 * (`woopayments-native-runtime.php:222-301`).
 */
async function getSavedCardEvidence(
	restApi: ApiClient,
	customerUsername: string
): Promise< { tokens: SavedToken[]; providerCustomerId: string } > {
	const data = (
		await restApi.get( SAVED_CARD_EVIDENCE_ROUTE, {
			customer_username: customerUsername,
		} )
	).data as {
		tokens?: Array< {
			token_id: number;
			payment_method_id: string;
			is_default: boolean;
		} >;
		provider_customer_id?: string;
	};
	return {
		tokens: ( data.tokens ?? [] ).map( ( token ) => ( {
			tokenId: token.token_id,
			paymentMethodId: token.payment_method_id,
			isDefault: token.is_default === true,
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

interface SetupIntentEvidence {
	id: string;
	status: string;
	usage: string;
	paymentMethodId: string;
	customerId: string;
	lastSetupErrorType: string;
	lastSetupErrorCode: string;
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
	const lastSetupError =
		( intent.last_setup_error as { type?: unknown; code?: unknown } ) ?? {};
	return {
		id: String( intent.id ?? '' ),
		status: String( intent.status ?? '' ),
		usage: String( intent.usage ?? '' ),
		paymentMethodId: String( intent.payment_method_id ?? '' ),
		customerId: String( intent.customer_id ?? '' ),
		lastSetupErrorType: String( lastSetupError.type ?? '' ),
		lastSetupErrorCode: String( lastSetupError.code ?? '' ),
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

/**
 * Polls one SetupIntent until it reaches `expectedStatus`, tolerating the
 * provider's transient 429 lock-timeout. Throws at the deadline rather than
 * returning whatever was last read, so a SetupIntent stuck short of the
 * expected status fails the case instead of passing it (R4).
 */
async function pollProviderSetupIntent(
	restApi: ApiClient,
	setupIntentId: string,
	expectedStatus: string,
	timeoutMs = 60_000
): Promise< SetupIntentEvidence > {
	const deadline = Date.now() + timeoutMs;
	let last: SetupIntentEvidence | undefined;
	for (;;) {
		try {
			last = await readProviderSetupIntent( restApi, setupIntentId );
			if ( last.status === expectedStatus ) {
				return last;
			}
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`SetupIntent ${ setupIntentId } did not reach ${ expectedStatus } within ${ timeoutMs }ms; it last read ${
					last?.status ?? 'nothing'
				}.`
			);
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
	}
}

interface SavedVisaDisplayCopy {
	method: string;
	expires: string;
	classicAccessibleName: string;
}

/** Matches the saved-card copy WooCommerce renders for a Visa test fixture. */
function getSavedVisaDisplayCopy(
	card: ProviderTestCard
): SavedVisaDisplayCopy {
	const method = `Visa ending in ${ card.number.slice( -4 ) }`;
	const expires = `${ card.expiry.slice( 0, 2 ) }/${ card.expiry.slice(
		2
	) }`;
	return {
		method,
		expires,
		classicAccessibleName: `${ method } (expires ${ expires })`,
	};
}

function isCreateSetupIntentRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		if (
			! new URL( request.url() ).pathname.endsWith(
				'/wp-admin/admin-ajax.php'
			)
		) {
			return false;
		}
	} catch {
		return false;
	}
	return (
		new URLSearchParams( request.postData() ?? '' ).get( 'action' ) ===
		'create_setup_intent'
	);
}

function isAddPaymentMethodSubmission( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		return new URL( request.url() ).pathname
			.replace( /\/+$/, '' )
			.endsWith( '/add-payment-method' );
	} catch {
		return false;
	}
}

async function fillOptionalBillingFields( page: Page ): Promise< void > {
	const frame = page.frameLocator(
		`${ ADD_FORM } #wcpay-core-payment-element iframe[name^="__privateStripeFrame"]`
	);
	const country = frame.getByRole( 'combobox', { name: /country/i } );
	if ( ( await country.count() ) > 0 && ( await country.isVisible() ) ) {
		await country.selectOption( 'US' );
	}
	const postcode = frame.getByRole( 'textbox', {
		name: /^(?:ZIP|Postal code)/i,
	} );
	if ( ( await postcode.count() ) > 0 && ( await postcode.isVisible() ) ) {
		await postcode.fill( '94107' );
	}
}

interface SetupIntentExchange {
	httpStatus: number;
	setupIntentId: string;
	setupIntentStatus: string;
	errorMessage: string;
}

function readSetupIntentExchange(
	status: number,
	body: unknown
): SetupIntentExchange {
	const payload =
		typeof body === 'object' && body !== null
			? ( body as { data?: unknown } ).data
			: undefined;
	const data =
		typeof payload === 'object' && payload !== null
			? ( payload as { id?: unknown; status?: unknown; error?: unknown } )
			: {};
	const error =
		typeof data.error === 'object' && data.error !== null
			? ( data.error as { message?: unknown } )
			: {};
	return {
		httpStatus: status,
		setupIntentId: typeof data.id === 'string' ? data.id : '',
		setupIntentStatus: typeof data.status === 'string' ? data.status : '',
		errorMessage: typeof error.message === 'string' ? error.message : '',
	};
}

async function openAndFillSavedMethodForm(
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	await page.goto( 'my-account/payment-methods/' );
	await page.getByRole( 'link', { name: /add payment method/i } ).click();
	await expect( page.locator( ADD_FORM ) ).toBeVisible();

	const gateway = page.locator(
		`${ ADD_FORM } input[name="payment_method"]`
	);
	if ( await gateway.isVisible() ) {
		await gateway.check();
	}

	await fillCardDetails( page, card, 'my-account' );
	await fillOptionalBillingFields( page );
}

async function readPaymentError(
	page: Page
): Promise< { visible: boolean; role: string; text: string } > {
	const error = page.locator( ADD_ERROR );
	if ( ( await error.count() ) !== 1 ) {
		return { visible: false, role: '', text: '' };
	}
	return {
		visible: await error.isVisible(),
		role: ( await error.getAttribute( 'role' ) ) ?? '',
		text: ( ( await error.textContent() ) ?? '' ).trim(),
	};
}

/**
 * Submits one My Account saved-method add and answers its mandatory
 * challenge, reading the pre-challenge SetupIntent exchange and every
 * add-payment-method form submission it triggered.
 */
async function submitSavedMethodAuthentication(
	page: Page,
	response: 'complete' | 'fail'
): Promise< {
	exchange: SetupIntentExchange;
	submittedSetupIntentIds: string[];
	setupIntentRequestCount: number;
} > {
	let setupIntentRequestCount = 0;
	const submittedSetupIntentIds: string[] = [];
	const responsePromise = new Promise< SetupIntentExchange >(
		( resolve, reject ) => {
			const timer = setTimeout(
				() =>
					reject( new Error( 'No SetupIntent response observed.' ) ),
				45_000
			);
			const onResponse = ( candidate: Response ): void => {
				if ( ! isCreateSetupIntentRequest( candidate.request() ) ) {
					return;
				}
				page.off( 'response', onResponse );
				clearTimeout( timer );
				candidate
					.json()
					.then( ( body ) =>
						resolve(
							readSetupIntentExchange( candidate.status(), body )
						)
					)
					.catch( () =>
						resolve(
							readSetupIntentExchange(
								candidate.status(),
								undefined
							)
						)
					);
			};
			page.on( 'response', onResponse );
		}
	);
	const onRequest = ( request: Request ): void => {
		if ( isCreateSetupIntentRequest( request ) ) {
			setupIntentRequestCount += 1;
		}
		if ( isAddPaymentMethodSubmission( request ) ) {
			submittedSetupIntentIds.push(
				new URLSearchParams( request.postData() ?? '' ).get(
					'wcpay-setup-intent'
				) ?? ''
			);
		}
	};
	page.on( 'request', onRequest );
	try {
		await page
			.getByRole( 'button', {
				name: 'Add payment method',
				exact: true,
			} )
			.click();
		const exchange = await responsePromise;
		await completeThreeDSChallenge( page, response );
		if ( response === 'complete' ) {
			await expect(
				page.getByText( ADD_SUCCESS_NOTICE, { exact: true } )
			).toBeVisible( { timeout: 45_000 } );
		} else {
			await expect( page.locator( ADD_ERROR ) ).toBeVisible( {
				timeout: 45_000,
			} );
		}
		return { exchange, submittedSetupIntentIds, setupIntentRequestCount };
	} finally {
		page.off( 'request', onRequest );
	}
}

async function readOrderDeltaAfter(
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

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
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
): { intentId: string } {
	const match = redirect.match( CONFIRMATION_HASH_PATTERN );
	if ( ! match ) {
		throw new Error(
			`Checkout response for order ${ orderId } carries no customer-action confirmation hash: ${ redirect }`
		);
	}
	const [ , , hashOrderId, clientSecret ] = match;
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
	return { intentId };
}

interface SavedTokenChallengeDispatch {
	orderId: number;
	intentId: string;
	gateway: string | null;
	saveRequested: boolean;
	/**
	 * Detaches the request/response listeners and reports how many checkout
	 * exchanges happened over the whole submission interval. Call once the
	 * interval has closed - at the receipt - so a second submission fired
	 * while the challenge is open is still counted (R1/R3 shape).
	 */
	stop: () => { requestCount: number; responseCount: number };
}

/** Selects the exact saved token on Classic checkout, submits, and reads the challenge dispatch. */
async function submitClassicCheckoutWithSavedToken(
	page: Page,
	display: SavedVisaDisplayCopy
): Promise< SavedTokenChallengeDispatch > {
	const token = page.getByRole( 'radio', {
		name: display.classicAccessibleName,
		exact: true,
	} );
	await expect(
		token,
		'requires exactly one radio for the saved card'
	).toHaveCount( 1 );
	await token.check();
	await expect( token ).toBeChecked();

	let requestCount = 0;
	let responseCount = 0;
	let gateway: string | null = null;
	let saveRequested = false;
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
				`Classic checkout did not accept the saved-token submission: ${ JSON.stringify(
					body
				) }`
			);
		}
		const orderId = Number( body.order_id );
		const { intentId } = parseConfirmationHash(
			String( body.redirect ?? '' ),
			orderId
		);
		return { orderId, intentId, gateway, saveRequested, stop };
	} catch ( error ) {
		stop();
		throw error;
	}
}

test.describe( 'WooPayments native saved-method authentication', () => {
	test.describe.configure( { mode: 'serial', timeout: 300_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
	} );

	test(
		'a failed My Account 3DS challenge leaves the exact SetupIntent unauthenticated, saves no token or attachment, and returns an announced usable add-method form',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_FAILED_ADD,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const shopper = getFakeUser( 'customer' );
			const created = ( await restApi.post( CUSTOMERS_ROUTE, shopper ) )
				.data as { id: number };
			try {
				const highestOrderId = await readHighestOrderId( restApi );

				await page.goto( 'wp-login.php' );
				await logIn( page, shopper.username, shopper.password, false );
				await page.goto( 'my-account/' );
				await expect(
					page.getByText(
						new RegExp( `Hello ${ shopper.first_name }` )
					)
				).toBeVisible();

				const before = await getSavedCardEvidence(
					restApi,
					shopper.username
				);
				expect(
					before.tokens,
					'a fresh shopper must hold no local credential'
				).toEqual( [] );

				await openAndFillSavedMethodForm(
					page,
					TEST_CARDS.declinedAfter3DS
				);
				const observation = await submitSavedMethodAuthentication(
					page,
					'fail'
				);

				expect( observation.setupIntentRequestCount ).toBe( 1 );
				expect( observation.exchange.httpStatus ).toBe( 200 );
				expect( observation.exchange.setupIntentId ).toMatch(
					/^seti_/
				);
				expect( observation.exchange.setupIntentStatus ).toBe(
					'requires_action'
				);
				expect( observation.exchange.errorMessage ).toBe( '' );
				expect(
					observation.submittedSetupIntentIds,
					'a failed authentication must never submit a SetupIntent for local storage'
				).toEqual( [] );

				const setupIntent = await pollProviderSetupIntent(
					restApi,
					observation.exchange.setupIntentId,
					'requires_payment_method'
				);
				expect( setupIntent.id ).toBe(
					observation.exchange.setupIntentId
				);
				expect( setupIntent.usage ).toBe( 'off_session' );
				expect( setupIntent.lastSetupErrorType ).toBe(
					'invalid_request_error'
				);
				expect( setupIntent.lastSetupErrorCode ).toBe(
					SETUP_AUTHENTICATION_FAILURE_CODE
				);

				const after = await getSavedCardEvidence(
					restApi,
					shopper.username
				);
				expect( after.tokens ).toEqual( [] );
				expect(
					after.providerCustomerId,
					'the exact failed SetupIntent must name the shopper customer native persisted'
				).toBe( setupIntent.customerId );
				expect(
					await getProviderPaymentMethodIds(
						restApi,
						after.providerCustomerId
					),
					'a failed challenge must attach no payment method - the title\'s "or attachment"'
				).toEqual( [] );

				const paymentError = await readPaymentError( page );
				expect( paymentError.visible ).toBe( true );
				expect( paymentError.role ).toBe( 'alert' );
				expect( paymentError.text ).toBe( AUTHENTICATION_FAILURE_TEXT );
				await expect(
					page.getByText( ADD_SUCCESS_NOTICE, { exact: true } )
				).toBeHidden();
				await expect( page.locator( ADD_FORM ) ).toBeVisible();
				await expect(
					page.getByRole( 'button', {
						name: 'Add payment method',
						exact: true,
					} )
				).toBeEnabled();

				const orders = await readOrderDeltaAfter(
					restApi,
					highestOrderId
				);
				expect(
					orders,
					'a SetupIntent failure must create no Woo order'
				).toEqual( [] );
			} finally {
				await restApi.delete( `${ CUSTOMERS_ROUTE }/${ created.id }`, {
					force: true,
				} );
			}
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
				{
					type: 'woopayments-contract',
					description: CONTRACT_HISTORICAL_AUTHENTICATED_PURCHASE,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const shopper = getFakeUser( 'customer' );
			const created = ( await restApi.post( CUSTOMERS_ROUTE, shopper ) )
				.data as { id: number };
			let productId: number | undefined;
			try {
				const highestOrderId = await readHighestOrderId( restApi );

				await page.goto( 'wp-login.php' );
				await logIn( page, shopper.username, shopper.password, false );
				await page.goto( 'my-account/' );
				await expect(
					page.getByText(
						new RegExp( `Hello ${ shopper.first_name }` )
					)
				).toBeVisible();

				const before = await getSavedCardEvidence(
					restApi,
					shopper.username
				);
				expect( before.tokens ).toEqual( [] );

				await openAndFillSavedMethodForm(
					page,
					TEST_CARDS.threeDSChallenge
				);
				const observation = await submitSavedMethodAuthentication(
					page,
					'complete'
				);
				expect( observation.setupIntentRequestCount ).toBe( 1 );
				expect( observation.exchange.httpStatus ).toBe( 200 );
				expect( observation.exchange.setupIntentStatus ).toBe(
					'requires_action'
				);
				expect(
					observation.submittedSetupIntentIds,
					'a completed challenge must submit that exact SetupIntent, once'
				).toEqual( [ observation.exchange.setupIntentId ] );

				const display = getSavedVisaDisplayCopy(
					TEST_CARDS.threeDSChallenge
				);
				await expect(
					page.getByRole( 'cell', {
						name: display.method,
						exact: true,
					} )
				).toBeVisible();
				await expect(
					page.getByRole( 'cell', {
						name: display.expires,
						exact: true,
					} )
				).toBeVisible();

				const after = await getSavedCardEvidence(
					restApi,
					shopper.username
				);
				const createdTokens = after.tokens.filter(
					( token ) =>
						! before.tokens.some(
							( existing ) => existing.tokenId === token.tokenId
						)
				);
				expect(
					createdTokens,
					'a succeeded SetupIntent must create exactly one local token'
				).toHaveLength( 1 );
				const [ card ] = createdTokens;
				expect(
					after.tokens,
					'the one token this save created must be the account default'
				).toEqual( [ { ...card, isDefault: true } ] );

				const setupIntent = await pollProviderSetupIntent(
					restApi,
					observation.exchange.setupIntentId,
					'succeeded'
				);
				expect( setupIntent.paymentMethodId ).toBe(
					card.paymentMethodId
				);
				expect( setupIntent.usage ).toBe( 'off_session' );
				expect( setupIntent.lastSetupErrorType ).toBe( '' );
				expect( setupIntent.lastSetupErrorCode ).toBe( '' );
				expect(
					setupIntent.customerId,
					"the succeeded SetupIntent must name the shopper's own provider customer"
				).toBe( after.providerCustomerId );
				expect(
					await getProviderPaymentMethodIds(
						restApi,
						after.providerCustomerId
					),
					'the provider must hold exactly one attachment of the saved method'
				).toEqual( [ card.paymentMethodId ] );

				const savedOrders = await readOrderDeltaAfter(
					restApi,
					highestOrderId
				);
				expect(
					savedOrders,
					'a succeeded SetupIntent saves a credential but creates no order'
				).toEqual( [] );

				// The add form's own login/product-creation is over; the
				// Classic PaymentIntent challenge that spends the saved
				// token comes next.
				const product = (
					await restApi.post( PRODUCTS_ROUTE, {
						name: `WooPayments saved-method-3ds ${ random() }`,
						type: 'simple',
						virtual: true,
						regular_price: PRICE,
						status: 'publish',
					} )
				).data as { id: number };
				productId = product.id;

				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', { name: 'Add to cart', exact: true } )
					.click();
				await page.goto( 'classic-checkout/' );

				const dispatch = await submitClassicCheckoutWithSavedToken(
					page,
					display
				);
				expect( dispatch.gateway ).toBe( 'woocommerce_payments' );
				expect(
					dispatch.saveRequested,
					'paying with an existing token must not request a new save'
				).toBe( false );
				const pending = await ( async () => {
					const deadline = Date.now() + 10_000;
					for (;;) {
						try {
							return (
								await restApi.get(
									`wc/v3/payments/payment_intents/${ encodeURIComponent(
										dispatch.intentId
									) }`
								)
							).data as Record< string, unknown >;
						} catch ( error ) {
							if (
								! isLockTimeout( error ) ||
								Date.now() >= deadline
							) {
								throw error;
							}
							await new Promise( ( resolve ) =>
								setTimeout( resolve, 500 )
							);
						}
					}
				} )();
				expect( pending.status ).toBe( 'requires_action' );
				expect( pending.payment_method ).toBe( card.paymentMethodId );
				expect(
					chargeCountOf( pending ),
					'no charge may exist before the challenge is answered'
				).toBe( 0 );
				expect( pending.amount ).toBe( AMOUNT_MINOR );
				expect( String( pending.currency ) ).toBe(
					CURRENCY.toLowerCase()
				);

				await completeThreeDSChallenge( page, 'complete' );
				await page.waitForURL( /\/order-received\/[1-9]\d*/, {
					timeout: 60_000,
				} );
				await expect(
					page.getByRole( 'heading', { name: 'Order received' } )
				).toBeVisible();
				expect(
					Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] ),
					'the receipt must belong to the dispatched order'
				).toBe( dispatch.orderId );
				const { requestCount, responseCount } = dispatch.stop();
				expect(
					requestCount,
					'one Place order activation must ask the store exactly once through the whole interval, challenge included'
				).toBe( 1 );
				expect( responseCount ).toBe( 1 );

				const payment = await expectSettledCardPayment(
					restApi,
					dispatch.orderId,
					{ amountMinor: AMOUNT_MINOR, currency: CURRENCY }
				);
				expect( payment.intentId ).toBe( dispatch.intentId );
				expect( payment.paymentMethodId ).toBe( card.paymentMethodId );
				expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );

				const settledIntent = await getPaymentIntent(
					restApi,
					payment.intentId
				);
				expect(
					String( settledIntent.customer ?? '' ),
					"the settled PaymentIntent must belong to the shopper's own provider customer"
				).toBe( setupIntent.customerId );
				expect(
					await readOrderDeltaAfter( restApi, highestOrderId ),
					'the whole journey (save, then purchase) must create exactly the one purchase order'
				).toEqual( [ dispatch.orderId ] );

				// The paid checkout must leave the exact saved credential
				// reusable, with no duplicate token created.
				const reusable = await getSavedCardEvidence(
					restApi,
					shopper.username
				);
				expect( reusable.tokens ).toEqual( after.tokens );
				expect(
					await getProviderPaymentMethodIds(
						restApi,
						after.providerCustomerId
					),
					'the purchase must leave the exact one attachment, no more'
				).toEqual( [ card.paymentMethodId ] );
			} finally {
				if ( productId !== undefined ) {
					await restApi.delete(
						`${ PRODUCTS_ROUTE }/${ productId }`,
						{
							force: true,
						}
					);
				}
				await restApi.delete( `${ CUSTOMERS_ROUTE }/${ created.id }`, {
					force: true,
				} );
			}
		}
	);
} );
