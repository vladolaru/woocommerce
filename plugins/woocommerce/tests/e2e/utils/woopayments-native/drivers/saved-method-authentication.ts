import {
	expect,
	type APIResponse,
	type Locator,
	type Page,
	type Request,
} from '@playwright/test';

import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { ProviderTestCard } from '../test-cards';
import {
	completeCardAuthentication,
	PlaywrightCardAuthenticationBrowser,
	type CardAuthenticationEvidence,
	type ChallengeResponse,
} from './card-authentication';
import { CARD_EXPIRY_FIELD_NAME, enterProviderCardEntry } from './card-entry';
import { PlaywrightClassicCardCheckoutBrowser } from './classic-card-checkout';
import type { PreparedClassicCardCheckout } from './classic-card-authentication';
import type { ClassicCheckoutTarget } from './classic-checkout-page';
import {
	getProviderPaymentMethodIds,
	type SavedCardIdentity,
} from './saved-cards';
import {
	delay,
	readProviderSetupIntent,
	type SetupIntentEvidence,
} from './subscriptions';

const CUSTOMERS_ROUTE = '/wp-json/wc/v3/customers';
const SAVED_CARD_EVIDENCE_ROUTE =
	'/wp-json/wc-native-payments-e2e/v1/saved-card-evidence';
const SUBSCRIPTION_EVIDENCE_ROUTE =
	'/wp-json/wc-native-payments-e2e/v1/subscription-evidence';
const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const NATIVE_ADD_FORM = 'form#add_payment_method';
const NATIVE_ADD_CARD_FRAME =
	'#wcpay-core-payment-element iframe[name^="__privateStripeFrame"]';
const NATIVE_ADD_ERROR = '#wcpay-core-payment-errors';
const ADD_SUCCESS_NOTICE = 'Payment method successfully added.';
const ADD_OUTCOME_TIMEOUT_MS = 45_000;
const PROVIDER_STATE_TIMEOUT_MS = 60_000;
const POLL_INTERVAL_MS = 500;

function fail( message: string ): never {
	throw new Error( `Saved-method authentication ${ message }` );
}

function quarantine(
	message: string,
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments saved-method authentication ${ message }`,
		'uncertain-provider-write',
		primaryError
	);
}

function requireObject(
	value: unknown,
	description: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( `requires exactly one ${ description } object.` );
	}
	return value as Record< string, unknown >;
}

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		fail(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

/**
 * Evidence from native's `create_setup_intent` exchange.
 *
 * Stripe.js owns the challenge payload. This journey keeps only the durable
 * SetupIntent identity and state it needs to prove client-compatible behavior.
 */
export interface SavedMethodSetupIntentExchange {
	httpStatus: number;
	setupIntentId: string;
	setupIntentStatus: string;
	errorMessage: string;
}

export function parseSavedMethodSetupIntentExchange(
	status: number,
	body: unknown
): SavedMethodSetupIntentExchange {
	const payload =
		typeof body === 'object' && body !== null
			? ( body as { data?: unknown } ).data
			: undefined;
	const data =
		typeof payload === 'object' && payload !== null
			? ( payload as {
					id?: unknown;
					status?: unknown;
					error?: unknown;
			  } )
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

export interface SavedVisaDisplayCopy {
	method: string;
	expires: string;
	classicAccessibleName: string;
}

/** Match the saved-card copy WooCommerce renders for this Visa fixture. */
export function getSavedVisaDisplayCopy(
	card: ProviderTestCard
): SavedVisaDisplayCopy {
	if (
		! /^\d{12,19}$/.test( card.number ) ||
		! /^\d{4}$/.test( card.expiry )
	) {
		fail( 'requires a complete Visa number and MMYY expiry for display.' );
	}
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

export interface RunOwnedSavedMethodShopper {
	id: number;
	username: string;
	password: string;
	email: string;
}

export interface RunOwnedSavedMethodToken extends SavedCardIdentity {
	isDefault: boolean;
}

export interface RunOwnedSavedMethodState {
	creationReady: boolean;
	providerCustomerId: string;
	tokens: RunOwnedSavedMethodToken[];
	attachedPaymentMethodIds: string[];
}

export interface SavedMethodSetupIntentEvidence extends SetupIntentEvidence {
	lastSetupErrorType: string;
	lastSetupErrorCode: string;
}

export interface SavedMethodFormRecovery {
	formVisible: boolean;
	addButtonEnabled: boolean;
	blockingOverlayCount: number;
}

export interface SavedMethodAuthenticationObservation {
	setupIntentExchanges: SavedMethodSetupIntentExchange[];
	setupPaymentMethodIds: string[];
	submittedSetupIntentIds: string[];
	challenge: CardAuthenticationEvidence;
	tokensBefore: RunOwnedSavedMethodToken[];
	tokensAfter: RunOwnedSavedMethodToken[];
	createdCards: SavedCardIdentity[];
	paymentError: { visible: boolean; role: string; text: string };
	successNoticeVisible: boolean;
	recovery: SavedMethodFormRecovery;
}

export interface SubmitSavedMethodAuthenticationOptions {
	shopper: RunOwnedSavedMethodShopper;
	card: ProviderTestCard;
	response: ChallengeResponse;
	journal: string;
}

export interface PreparedExactSavedMethodAuthentication {
	prepared: PreparedClassicCardCheckout;
	selectedTokenId: number;
	selectedAccessibleName: string;
}

export interface PrepareExactSavedMethodAuthenticationOptions {
	shopper: RunOwnedSavedMethodShopper;
	card: SavedCardIdentity;
	cardFixture: ProviderTestCard;
	product: OwnedProduct;
	checkout: ClassicCheckoutTarget;
	runId: string;
}

/** Create one local shopper whose entire saved-method state belongs to a run. */
export async function createRunOwnedSavedMethodShopper(
	session: ProviderWriteSession,
	purpose: string
): Promise< RunOwnedSavedMethodShopper > {
	await session.assertCanWrite();
	const runSlice = session.runId
		.replace( /^woopayments-/, '' )
		.replace( /[^a-zA-Z0-9]/g, '' )
		.slice( 0, 10 );
	const purposeSlice = purpose
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' )
		.slice( 0, 12 );
	const username = `wcsm-${ runSlice }-${ purposeSlice }`.slice( 0, 60 );
	const password = `woopayments-e2e-${ runSlice }-${ purposeSlice }`;
	const email = `${ username }@example.com`;
	const created = requireObject(
		await readJson(
			await session.performWrite( () =>
				session.adminApi.post( CUSTOMERS_ROUTE, {
					data: {
						email,
						username,
						password,
						first_name: 'E2E',
						last_name: 'WooPayments',
					},
				} )
			),
			`run-owned shopper ${ username } creation`
		),
		'created customer'
	);
	if ( ! Number.isSafeInteger( created.id ) || Number( created.id ) <= 0 ) {
		fail(
			`run-owned shopper ${ username } creation returned no usable ID.`
		);
	}

	return {
		id: created.id as number,
		username,
		password,
		email,
	};
}

/** Sign the browser in and prove it is the run-owned shopper's session. */
export async function logInAsRunOwnedSavedMethodShopper(
	page: Page,
	shopper: RunOwnedSavedMethodShopper
): Promise< void > {
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await page
		.getByLabel( 'Username or Email Address' )
		.fill( shopper.username );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( shopper.password );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.goto( 'my-account/edit-account/' );
	await expect(
		page.getByRole( 'textbox', { name: /Email address/i } )
	).toHaveValue( shopper.email );
}

/** Read the local-token and provider-attachment state for an exact shopper. */
export async function readRunOwnedSavedMethodState(
	session: ProviderWriteSession,
	shopper: Pick< RunOwnedSavedMethodShopper, 'username' >
): Promise< RunOwnedSavedMethodState > {
	const evidence = requireObject(
		await readJson(
			await session.adminApi.get(
				`${ SAVED_CARD_EVIDENCE_ROUTE }?customer_username=${ encodeURIComponent(
					shopper.username
				) }`
			),
			`saved-method evidence for ${ shopper.username }`
		),
		'saved-method evidence'
	);
	if ( typeof evidence.creation_ready !== 'boolean' ) {
		fail( `evidence for ${ shopper.username } has no cooldown state.` );
	}
	if ( ! Array.isArray( evidence.tokens ) ) {
		fail( `evidence for ${ shopper.username } has no token collection.` );
	}
	const tokenIds = new Set< number >();
	const paymentMethodIds = new Set< string >();
	const tokens = evidence.tokens.map( ( value, index ) => {
		const token = requireObject(
			value,
			`saved-method token ${ index + 1 }`
		);
		if (
			! Number.isSafeInteger( token.token_id ) ||
			Number( token.token_id ) <= 0 ||
			typeof token.payment_method_id !== 'string' ||
			token.payment_method_id === '' ||
			typeof token.is_default !== 'boolean'
		) {
			fail(
				`saved-method token ${ index + 1 } for ${
					shopper.username
				} has no exact identity.`
			);
		}
		const tokenId = token.token_id as number;
		const paymentMethodId = token.payment_method_id as string;
		if (
			tokenIds.has( tokenId ) ||
			paymentMethodIds.has( paymentMethodId )
		) {
			fail(
				`evidence for ${ shopper.username } contains a duplicate token.`
			);
		}
		tokenIds.add( tokenId );
		paymentMethodIds.add( paymentMethodId );
		return {
			tokenId,
			paymentMethodId,
			isDefault: token.is_default as boolean,
		};
	} );

	const providerCustomerId =
		typeof evidence.provider_customer_id === 'string'
			? evidence.provider_customer_id
			: '';
	if ( providerCustomerId === '' ) {
		return {
			creationReady: evidence.creation_ready,
			providerCustomerId,
			tokens,
			attachedPaymentMethodIds: [],
		};
	}

	return {
		creationReady: evidence.creation_ready,
		providerCustomerId,
		tokens,
		attachedPaymentMethodIds: await getProviderPaymentMethodIds(
			session,
			providerCustomerId
		),
	};
}

async function readSetupIntentLastError(
	session: ProviderWriteSession,
	setupIntentId: string
): Promise<
	Pick<
		SavedMethodSetupIntentEvidence,
		'lastSetupErrorType' | 'lastSetupErrorCode'
	>
> {
	const value = await readJson(
		await session.adminApi.get(
			`${ SUBSCRIPTION_EVIDENCE_ROUTE }?setup_intent_id=${ encodeURIComponent(
				setupIntentId
			) }`
		),
		`provider SetupIntent ${ setupIntentId } error evidence`
	);
	const payload = requireObject( value, 'subscription evidence' );
	const intent = requireObject(
		payload.setup_intent,
		'SetupIntent evidence'
	);
	if ( intent.id !== setupIntentId ) {
		fail(
			`provider returned SetupIntent ${ String(
				intent.id
			) } for ${ setupIntentId } error evidence.`
		);
	}
	const lastSetupError =
		typeof intent.last_setup_error === 'object' &&
		intent.last_setup_error !== null &&
		! Array.isArray( intent.last_setup_error )
			? ( intent.last_setup_error as Record< string, unknown > )
			: {};
	return {
		lastSetupErrorType:
			typeof lastSetupError.type === 'string' ? lastSetupError.type : '',
		lastSetupErrorCode:
			typeof lastSetupError.code === 'string' ? lastSetupError.code : '',
	};
}

/** Poll one exact SetupIntent until it reaches the required terminal state. */
export async function waitForSavedMethodSetupIntent(
	session: ProviderWriteSession,
	setupIntentId: string,
	expectedStatus: string
): Promise< SavedMethodSetupIntentEvidence > {
	if ( ! /^seti_[A-Za-z0-9_]+$/.test( setupIntentId ) ) {
		fail( 'requires an exact SetupIntent ID.' );
	}
	const deadline = Date.now() + PROVIDER_STATE_TIMEOUT_MS;
	let evidence: SetupIntentEvidence;
	for (;;) {
		evidence = await readProviderSetupIntent( session, setupIntentId );
		if ( evidence.id !== setupIntentId ) {
			fail(
				`provider returned SetupIntent ${ evidence.id } for ${ setupIntentId }.`
			);
		}
		if ( evidence.status === expectedStatus ) {
			return {
				...evidence,
				...( await readSetupIntentLastError( session, setupIntentId ) ),
			};
		}
		if ( Date.now() >= deadline ) {
			throw quarantine(
				`SetupIntent ${ setupIntentId } did not reach ${ expectedStatus }; it remained ${ evidence.status }.`
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
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

async function openAndFillSavedMethodForm(
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	await page.goto( 'my-account/payment-methods/' );
	const add = page.getByRole( 'link', { name: /add payment method/i } );
	await expect( add ).toHaveCount( 1 );
	await add.click();
	await expect( page.locator( NATIVE_ADD_FORM ) ).toBeVisible();

	const gateway = page.locator(
		`${ NATIVE_ADD_FORM } input[name="payment_method"]`
	);
	await expect( gateway ).toHaveCount( 1 );
	await expect( gateway ).toHaveValue( WOOPAYMENTS_GATEWAY );
	await expect( gateway ).toBeChecked();

	const iframe = page.locator( NATIVE_ADD_CARD_FRAME );
	await iframe.waitFor( { state: 'visible' } );
	await expect( iframe ).toHaveCount( 1 );
	const frame = page.frameLocator( NATIVE_ADD_CARD_FRAME );
	await enterProviderCardEntry(
		[
			{
				label: 'card number',
				locator: frame.getByRole( 'textbox', { name: 'Card number' } ),
				value: card.number,
				kind: 'digits',
			},
			{
				label: 'expiry',
				locator: frame.getByRole( 'textbox', {
					name: CARD_EXPIRY_FIELD_NAME,
				} ),
				value: card.expiry,
				kind: 'digits',
			},
			{
				label: 'security code',
				locator: frame.getByRole( 'textbox', {
					name: 'Security code',
				} ),
				value: card.securityCode,
				kind: 'digits',
			},
			{
				label: 'billing country',
				locator: frame.getByRole( 'combobox', { name: /country/i } ),
				value: 'US',
				kind: 'option',
				optional: true,
			},
			{
				label: 'billing postcode',
				locator: frame.getByRole( 'textbox', {
					name: /^(?:ZIP|Postal code)/i,
				} ),
				value: '94107',
				kind: 'digits',
				optional: true,
			},
		],
		'My Account add-payment-method',
		fail
	);
}

async function readPaymentError(
	page: Page
): Promise< SavedMethodAuthenticationObservation[ 'paymentError' ] > {
	const error = page.locator( NATIVE_ADD_ERROR );
	if ( ( await error.count() ) !== 1 ) {
		return { visible: false, role: '', text: '' };
	}
	return {
		visible: await error.isVisible(),
		role: ( await error.getAttribute( 'role' ) ) ?? '',
		text: ( ( await error.textContent() ) ?? '' ).trim(),
	};
}

async function readFormRecovery(
	page: Page
): Promise< SavedMethodFormRecovery > {
	const form = page.locator( NATIVE_ADD_FORM );
	const submit = page.getByRole( 'button', {
		name: 'Add payment method',
		exact: true,
	} );
	return {
		formVisible: ( await form.count() ) === 1 && ( await form.isVisible() ),
		addButtonEnabled:
			( await submit.count() ) === 1 && ( await submit.isEnabled() ),
		blockingOverlayCount: await form.locator( '.blockUI:visible' ).count(),
	};
}

/**
 * Submit one My Account saved-method add and answer its mandatory challenge.
 *
 * The SetupIntent response is observed before the challenge is answered. That
 * gives the caller an exact pre-challenge ID, which it can then require the
 * provider's terminal read to match.
 */
export async function submitSavedMethodAuthentication(
	session: ProviderWriteSession,
	page: Page,
	options: SubmitSavedMethodAuthenticationOptions
): Promise< SavedMethodAuthenticationObservation > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'saved-card-add' );
	session.requireApprovedProviderFixture( 'card-authentication' );
	const before = await readRunOwnedSavedMethodState(
		session,
		options.shopper
	);
	if ( ! before.creationReady ) {
		fail(
			'requires a shopper whose add-payment-method cooldown is ready.'
		);
	}

	await logInAsRunOwnedSavedMethodShopper( page, options.shopper );
	await openAndFillSavedMethodForm( page, options.card );

	const setupPaymentMethodIds: string[] = [];
	const submittedSetupIntentIds: string[] = [];
	const onRequest = ( request: Request ): void => {
		if ( isCreateSetupIntentRequest( request ) ) {
			setupPaymentMethodIds.push(
				new URLSearchParams( request.postData() ?? '' ).get(
					'wcpay-payment-method'
				) ?? ''
			);
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
		return await session.withProviderSubmissionJournal(
			options.journal,
			async () => {
				let submissionAttempted = false;
				try {
					const responsePromise = page.waitForResponse(
						( response ) =>
							isCreateSetupIntentRequest( response.request() ),
						{ timeout: ADD_OUTCOME_TIMEOUT_MS }
					);
					const submit = page.getByRole( 'button', {
						name: 'Add payment method',
						exact: true,
					} );
					await session.performWrite( () => {
						submissionAttempted = true;
						return submit.click();
					} );

					let response;
					try {
						response = await responsePromise;
					} catch ( error ) {
						if ( setupPaymentMethodIds.length === 0 ) {
							throw new ProviderSubmissionNotStartedError(
								'the saved-method add never dispatched a SetupIntent request.',
								{ cause: error }
							);
						}
						throw quarantine(
							'the SetupIntent request received no observable response.',
							error
						);
					}
					const setupIntentExchanges = [
						parseSavedMethodSetupIntentExchange(
							response.status(),
							await response.json()
						),
					];
					const challenge = await completeCardAuthentication( {
						expectation: 'challenge',
						response: options.response,
						browser: new PlaywrightCardAuthenticationBrowser(
							page
						),
					} );

					if ( options.response === 'complete' ) {
						await expect(
							page.getByText( ADD_SUCCESS_NOTICE, {
								exact: true,
							} )
						).toBeVisible( { timeout: ADD_OUTCOME_TIMEOUT_MS } );
					} else {
						await expect(
							page.locator( NATIVE_ADD_ERROR )
						).toBeVisible( {
							timeout: ADD_OUTCOME_TIMEOUT_MS,
						} );
					}

					if (
						setupPaymentMethodIds.length !== 1 ||
						setupIntentExchanges.length !== 1
					) {
						throw quarantine(
							`observed ${ setupPaymentMethodIds.length } SetupIntent request(s) and ${ setupIntentExchanges.length } response exchange(s); exactly one of each is required.`
						);
					}
					if (
						options.response === 'complete' &&
						submittedSetupIntentIds.length !== 1
					) {
						throw quarantine(
							`completed one challenge but observed ${ submittedSetupIntentIds.length } add-form submission(s).`
						);
					}
					if (
						options.response === 'fail' &&
						submittedSetupIntentIds.length !== 0
					) {
						throw quarantine(
							'a failed challenge still submitted the add-payment-method form.'
						);
					}

					const after = await readRunOwnedSavedMethodState(
						session,
						options.shopper
					);
					const knownTokenIds = new Set(
						before.tokens.map( ( token ) => token.tokenId )
					);
					return {
						setupIntentExchanges,
						setupPaymentMethodIds,
						submittedSetupIntentIds,
						challenge,
						tokensBefore: before.tokens,
						tokensAfter: after.tokens,
						createdCards: after.tokens
							.filter(
								( token ) =>
									! knownTokenIds.has( token.tokenId )
							)
							.map( ( token ) => ( {
								tokenId: token.tokenId,
								paymentMethodId: token.paymentMethodId,
							} ) ),
						paymentError: await readPaymentError( page ),
						successNoticeVisible: await page
							.getByText( ADD_SUCCESS_NOTICE, { exact: true } )
							.isVisible(),
						recovery: await readFormRecovery( page ),
					};
				} catch ( error ) {
					if ( error instanceof ProviderSubmissionNotStartedError ) {
						throw error;
					}
					if ( ! submissionAttempted ) {
						throw new ProviderSubmissionNotStartedError(
							'the saved-method authentication gesture was never dispatched.',
							{ cause: error }
						);
					}
					if ( error instanceof ResourceQuarantineRequiredError ) {
						throw error;
					}
					throw quarantine(
						'the submitted saved-method authentication has no proven outcome.',
						error
					);
				}
			}
		);
	} finally {
		page.off( 'request', onRequest );
	}
}

/**
 * Prepare Classic checkout with one exact stored token selected.
 *
 * `submitClassicCardAuthentication()` owns the one-shot submission and
 * challenge. This adapter only establishes the product, shopper and exact
 * token that that existing driver will submit.
 */
export async function prepareExactSavedMethodAuthentication(
	session: ProviderWriteSession,
	page: Page,
	options: PrepareExactSavedMethodAuthenticationOptions
): Promise< PreparedExactSavedMethodAuthentication > {
	if ( ! options.runId || options.runId !== session.runId ) {
		fail( 'Classic preparation requires the exact active run ID.' );
	}
	if (
		options.product.name !== `WooPayments native E2E ${ options.runId }` ||
		options.product.amount !== '10.99'
	) {
		fail( 'Classic preparation requires the run-owned USD 10.99 product.' );
	}
	if (
		! Number.isSafeInteger( options.checkout.pageId ) ||
		options.checkout.pageId <= 0 ||
		options.checkout.slug !== 'classic-checkout' ||
		options.checkout.path !== 'classic-checkout/'
	) {
		fail( 'Classic preparation requires the exact marker-bound page.' );
	}
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'saved-card-classic' );
	session.requireApprovedProviderFixture( 'card-authentication' );
	await logInAsRunOwnedSavedMethodShopper( page, options.shopper );

	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		options.checkout.pageId
	);
	await browser.preflightClassicPage(
		options.checkout.path,
		options.checkout.pageId
	);
	let addActivationCount = 0;
	await browser.addProductOnce( options.product.id, async ( write ) => {
		addActivationCount += 1;
		if ( addActivationCount !== 1 ) {
			fail( 'must activate Add to cart exactly once.' );
		}
		return session.performWrite( write );
	} );
	await browser.openClassicCheckout( options.checkout.path );
	await browser.fillBillingDetails( options.runId );
	await browser.selectWooPaymentsCard();

	const display = getSavedVisaDisplayCopy( options.cardFixture );
	const token = page.getByRole( 'radio', {
		name: display.classicAccessibleName,
		exact: true,
	} );
	await expect(
		token,
		`Classic checkout must expose exactly one radio named "${ display.classicAccessibleName }"`
	).toHaveCount( 1 );
	await expect( token ).toHaveValue( options.card.tokenId.toString() );
	await token.check();
	await expect( token ).toBeChecked();
	const newMethod = page.getByRole( 'radio', {
		name: 'Use a new payment method',
		exact: true,
	} );
	if (
		( await newMethod.count() ) === 1 &&
		( await newMethod.isChecked() )
	) {
		fail(
			'left the new-method entry selected instead of the exact token.'
		);
	}
	await browser.prepareSubmission();

	return {
		selectedTokenId: options.card.tokenId,
		selectedAccessibleName: display.classicAccessibleName,
		prepared: {
			browser,
			runId: options.runId,
			orderTotal: options.product.amount,
			card: options.cardFixture,
			savePaymentMethod: false,
		},
	};
}

async function findExactDeleteAction(
	page: Page,
	session: ProviderWriteSession,
	tokenId: number
): Promise< Locator > {
	const candidates = await page
		.getByRole( 'link', { name: 'Delete', exact: true } )
		.all();
	const matching: Locator[] = [];
	for ( const candidate of candidates ) {
		const href = await candidate.getAttribute( 'href' );
		if ( ! href ) {
			continue;
		}
		const url = new URL( href, session.baseURL );
		if (
			url.pathname.endsWith( `/delete-payment-method/${ tokenId }/` ) &&
			url.searchParams.has( '_wpnonce' )
		) {
			matching.push( candidate );
		}
	}
	if ( matching.length !== 1 ) {
		fail(
			`delete action is not uniquely bound to local token ${ tokenId }.`
		);
	}
	return matching[ 0 ];
}

/** Delete every credential belonging to a fresh run-owned shopper. */
export async function deleteRunOwnedSavedMethods(
	session: ProviderWriteSession,
	page: Page,
	shopper: RunOwnedSavedMethodShopper
): Promise< void > {
	try {
		await session.assertCanWrite();
		session.requireApprovedProviderFixture( 'saved-card-cleanup' );
		const before = await readRunOwnedSavedMethodState( session, shopper );
		if ( before.tokens.length === 0 ) {
			return;
		}
		if ( ! before.providerCustomerId ) {
			fail( 'cannot prove provider detachment without a customer ID.' );
		}
		await logInAsRunOwnedSavedMethodShopper( page, shopper );
		await session.withProviderSubmissionJournal(
			`run-owned-saved-method-delete-${ shopper.id }`,
			async () => {
				for ( const card of before.tokens.toReversed() ) {
					await page.goto( 'my-account/payment-methods/' );
					let submissionAttempted = false;
					try {
						const action = await findExactDeleteAction(
							page,
							session,
							card.tokenId
						);
						await session.performWrite( () => {
							submissionAttempted = true;
							return action.click();
						} );
						await expect(
							page.getByText( 'Payment method deleted.', {
								exact: true,
							} )
						).toBeVisible();
					} catch ( error ) {
						if ( ! submissionAttempted ) {
							throw new ProviderSubmissionNotStartedError(
								`local token ${ card.tokenId } deletion was never dispatched.`,
								{ cause: error }
							);
						}
						throw quarantine(
							`local token ${ card.tokenId } deletion could not be proven.`,
							error
						);
					}
				}
			}
		);

		const after = await readRunOwnedSavedMethodState( session, shopper );
		if ( after.tokens.length !== 0 ) {
			fail( 'cleanup left a local token on the run-owned shopper.' );
		}
		for ( const token of before.tokens ) {
			if (
				after.attachedPaymentMethodIds.includes( token.paymentMethodId )
			) {
				fail(
					`cleanup left provider method ${ token.paymentMethodId } attached.`
				);
			}
		}
	} catch ( error ) {
		if ( error instanceof ResourceQuarantineRequiredError ) {
			throw error;
		}
		throw new ResourceQuarantineRequiredError(
			`Run-owned saved-method cleanup failed for ${ shopper.username }.`,
			'cleanup-failed',
			error
		);
	}
}

/** Remove the local run-owned shopper and prove it no longer exists. */
export async function deleteRunOwnedSavedMethodShopper(
	session: ProviderWriteSession,
	shopper: RunOwnedSavedMethodShopper
): Promise< void > {
	const response = await session.performWrite( () =>
		session.adminApi.delete( `${ CUSTOMERS_ROUTE }/${ shopper.id }`, {
			params: { force: true, reassign: 0 },
			failOnStatusCode: false,
		} )
	);
	if ( ! response.ok() ) {
		fail(
			`could not delete shopper ${
				shopper.username
			}: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	const readBack = await session.adminApi.get(
		`${ CUSTOMERS_ROUTE }/${ shopper.id }`,
		{ failOnStatusCode: false }
	);
	if ( readBack.status() !== 404 ) {
		fail(
			`shopper ${
				shopper.username
			} still exists after deletion: HTTP ${ readBack.status() }.`
		);
	}
}
