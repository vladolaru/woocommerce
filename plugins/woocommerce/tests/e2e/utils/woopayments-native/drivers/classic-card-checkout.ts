import { createHash } from 'node:crypto';

import type { Locator, Page, Request, Response } from '@playwright/test';

import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { CardTestingProtectionScope } from './card-testing-protection';

const CLASSIC_CHECKOUT_PATH = 'classic-checkout/';
const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const TOKEN_LENGTH = 16;
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const SUBMISSION_TIMEOUT_MS = 60_000;
const BLOCKS_CHECKOUT_MARKERS =
	'[data-block-name="woocommerce/checkout"], .wp-block-woocommerce-checkout, .wc-block-checkout';
const CLASSIC_CHECKOUT_FORM = 'form.checkout.woocommerce-checkout';
const CLASSIC_CARD_FRAME =
	'#payment .payment_method_woocommerce_payments .wcpay-upe-element iframe';

const ALLOWLISTED_REQUEST_FIELDS = [
	'payment_method',
	'wc-woocommerce_payments-new-payment-method',
	'wcpay-fraud-prevention-token',
	'wcpay-payment-method-error-code',
	'wcpay-payment-method-error-message',
	'wcpay-is-platform-payment-method',
	'wcpay-fingerprint',
] as const;

export interface PublicTokenDigest {
	length: number;
	sha256: string;
}

export interface ClassicCheckoutRequestEvidence {
	gateway: 'woocommerce_payments' | 'other' | 'absent';
	savePaymentMethod: boolean;
	fraudPreventionToken: PublicTokenDigest;
	paymentMethodErrorCodePresent: boolean;
	paymentMethodErrorMessagePresent: boolean;
	platformPaymentMethod: 'true' | 'false' | 'invalid';
	fingerprintPresent: boolean;
}

export interface ClassicCheckoutResponseEvidence {
	status: number;
	orderId: number;
	orderKey: string;
}

export interface ClassicOrderReceipt {
	orderId: number;
	orderKey: string;
}

export interface ClassicCardCheckoutEvidence {
	runId: string;
	orderId: number;
	orderKey: string;
	tokens: {
		exposed: PublicTokenDigest;
		authoritativeSession: PublicTokenDigest;
		submitted: PublicTokenDigest;
	};
	request: ClassicCheckoutRequestEvidence;
	response: ClassicCheckoutResponseEvidence;
	receipt: ClassicOrderReceipt;
}

export interface RawClassicCheckoutRequest {
	requestId: string;
	method: string;
	url: string;
	body: string | null;
}

export interface RawClassicCheckoutResponse {
	requestId: string;
	status: number;
	body: unknown;
}

export interface RawClassicSubmissionObservation {
	requests: RawClassicCheckoutRequest[];
	responses: RawClassicCheckoutResponse[];
	receiptUrl: string;
}

type PerformWrite = < Result >(
	write: () => Promise< Result >
) => Promise< Result >;

export interface ClassicCardCheckoutBrowser {
	preflightClassicPage(
		path: typeof CLASSIC_CHECKOUT_PATH,
		pageId: number
	): Promise< void >;
	addProductOnce(
		productId: number,
		performWrite: PerformWrite
	): Promise< void >;
	openClassicCheckout( path: typeof CLASSIC_CHECKOUT_PATH ): Promise< void >;
	fillBillingDetails( runId: string ): Promise< void >;
	selectWooPaymentsCard(): Promise< void >;
	fillBasicCard(): Promise< void >;
	captureExposedTokenDigest(): Promise< PublicTokenDigest >;
	prepareSubmission(): Promise< void >;
	observeSubmission(
		activateOnce: ( activate: () => Promise< void > ) => Promise< void >
	): Promise< RawClassicSubmissionObservation >;
}

interface CompleteClassicCardCheckoutOptions {
	browser?: ClassicCardCheckoutBrowser;
}

function fail( message: string ): never {
	throw new Error( `Classic card checkout ${ message }` );
}

function quarantine(): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		'WooPayments Classic checkout submission has no proven outcome.',
		'uncertain-provider-write'
	);
}

function assertPublicTokenDigest(
	digest: PublicTokenDigest,
	label: string
): void {
	if (
		digest.length !== TOKEN_LENGTH ||
		typeof digest.sha256 !== 'string' ||
		! SHA256_PATTERN.test( digest.sha256 )
	) {
		fail( `${ label } token evidence is invalid.` );
	}
}

function assertSameTokenDigest(
	left: PublicTokenDigest,
	right: PublicTokenDigest
): void {
	if ( left.length !== right.length || left.sha256 !== right.sha256 ) {
		fail( 'token evidence mismatch.' );
	}
}

function digestToken( token: string ): PublicTokenDigest {
	return {
		length: token.length,
		sha256: createHash( 'sha256' ).update( token ).digest( 'hex' ),
	};
}

function normalizeGateway(
	value: string | undefined
): ClassicCheckoutRequestEvidence[ 'gateway' ] {
	if ( value === undefined ) {
		return 'absent';
	}
	return value === WOOPAYMENTS_GATEWAY ? WOOPAYMENTS_GATEWAY : 'other';
}

function normalizePlatformPaymentMethod(
	value: string | undefined
): ClassicCheckoutRequestEvidence[ 'platformPaymentMethod' ] {
	if ( value === undefined ) {
		return 'false';
	}
	return value === 'true' || value === 'false' ? value : 'invalid';
}

function readAtMostOne(
	parameters: URLSearchParams,
	key: ( typeof ALLOWLISTED_REQUEST_FIELDS )[ number ]
): string | undefined {
	const values = parameters.getAll( key );
	if ( values.length > 1 ) {
		fail( `request has a duplicate allowlisted field (${ key }).` );
	}
	return values[ 0 ];
}

export function isClassicCheckoutRequest(
	request: Pick< Request, 'method' | 'url' >,
	baseURL?: string
): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const requestUrl = new URL( request.url() );
		if ( baseURL && requestUrl.origin !== new URL( baseURL ).origin ) {
			return false;
		}
		const values = requestUrl.searchParams.getAll( 'wc-ajax' );
		return values.length === 1 && values[ 0 ] === 'checkout';
	} catch {
		return false;
	}
}

export function normalizeClassicCheckoutRequest(
	request: Pick< Request, 'method' | 'url' | 'postData' >
): ClassicCheckoutRequestEvidence {
	if ( ! isClassicCheckoutRequest( request ) ) {
		fail( 'request does not match the exact checkout endpoint.' );
	}
	const body = request.postData();
	if ( typeof body !== 'string' ) {
		fail( 'request has no URL-encoded body.' );
	}
	const parameters = new URLSearchParams( body );
	const values = Object.fromEntries(
		ALLOWLISTED_REQUEST_FIELDS.map( ( key ) => [
			key,
			readAtMostOne( parameters, key ),
		] )
	);

	const saveValue = values[ 'wc-woocommerce_payments-new-payment-method' ];
	if ( saveValue !== undefined && saveValue !== 'true' ) {
		fail( 'request has an invalid save-payment-method flag.' );
	}
	const token = values[ 'wcpay-fraud-prevention-token' ];
	if ( token === undefined ) {
		fail( 'request has no fraud-prevention token.' );
	}
	return {
		gateway: normalizeGateway( values.payment_method ),
		savePaymentMethod: saveValue === 'true',
		fraudPreventionToken: digestToken( token ),
		paymentMethodErrorCodePresent:
			( values[ 'wcpay-payment-method-error-code' ] ?? '' ) !== '',
		paymentMethodErrorMessagePresent:
			( values[ 'wcpay-payment-method-error-message' ] ?? '' ) !== '',
		platformPaymentMethod: normalizePlatformPaymentMethod(
			values[ 'wcpay-is-platform-payment-method' ]
		),
		fingerprintPresent: ( values[ 'wcpay-fingerprint' ] ?? '' ) !== '',
	};
}

export function parseClassicOrderReceivedUrl(
	url: string,
	baseURL: string
): ClassicOrderReceipt {
	let parsed: URL;
	let store: URL;
	try {
		parsed = new URL( url, baseURL );
		store = new URL( baseURL );
	} catch {
		fail( 'response contains an invalid order-received URL.' );
	}
	const match = parsed.pathname.match( /\/order-received\/([1-9]\d*)\/?$/ );
	const orderKeys = parsed.searchParams.getAll( 'key' );
	if (
		parsed.origin !== store.origin ||
		! match ||
		orderKeys.length !== 1 ||
		! orderKeys[ 0 ].trim()
	) {
		fail( 'response contains an invalid order-received URL.' );
	}
	return {
		orderId: Number( match[ 1 ] ),
		orderKey: orderKeys[ 0 ],
	};
}

export function normalizeClassicCheckoutResponse(
	status: number,
	body: unknown,
	baseURL: string
): ClassicCheckoutResponseEvidence {
	if (
		status < 200 ||
		status >= 300 ||
		typeof body !== 'object' ||
		body === null ||
		Array.isArray( body )
	) {
		fail( 'response is not a successful response object.' );
	}
	const response = body as Record< string, unknown >;
	if (
		response.result !== 'success' ||
		! Number.isSafeInteger( response.order_id ) ||
		Number( response.order_id ) <= 0 ||
		typeof response.redirect !== 'string' ||
		! response.redirect.trim()
	) {
		fail( 'response is missing exact success order evidence.' );
	}
	const redirect = parseClassicOrderReceivedUrl( response.redirect, baseURL );
	if ( redirect.orderId !== response.order_id ) {
		fail( 'response order ID does not match its redirect.' );
	}
	return {
		status,
		orderId: response.order_id as number,
		orderKey: redirect.orderKey,
	};
}

async function requireOneEnabled(
	locator: Locator,
	label: string
): Promise< Locator > {
	if (
		( await locator.count() ) !== 1 ||
		! ( await locator.isVisible() ) ||
		! ( await locator.isEnabled() )
	) {
		fail( `requires exactly one enabled semantic ${ label }.` );
	}
	return locator;
}

export class PlaywrightClassicCardCheckoutBrowser
	implements ClassicCardCheckoutBrowser
{
	private readonly page: Page;
	private readonly baseURL: string;
	private readonly pageId: number;
	private placeOrderButton?: Locator;
	private submissionObservationAttempted = false;

	public constructor( page: Page, baseURL: string, pageId: number ) {
		this.page = page;
		this.baseURL = baseURL;
		this.pageId = pageId;
	}

	private assertExactPath( path: typeof CLASSIC_CHECKOUT_PATH ): void {
		const expected = new URL( path, this.baseURL ).pathname.replace(
			/\/+$/,
			''
		);
		let actual: string;
		try {
			actual = new URL( this.page.url() ).pathname.replace( /\/+$/, '' );
		} catch {
			fail( 'did not reach the exact Classic checkout path.' );
		}
		if ( actual !== expected ) {
			fail( 'did not reach the exact Classic checkout path.' );
		}
	}

	private async assertNoBlocksMarkup(): Promise< void > {
		if (
			( await this.page.locator( BLOCKS_CHECKOUT_MARKERS ).count() ) > 0
		) {
			fail( 'preflight found Blocks markup.' );
		}
	}

	public async preflightClassicPage(
		path: typeof CLASSIC_CHECKOUT_PATH,
		pageId: number
	): Promise< void > {
		if ( pageId !== this.pageId ) {
			fail( 'preflight page identity is not exact.' );
		}
		await this.page.goto( path );
		this.assertExactPath( path );
		await this.assertNoBlocksMarkup();
		const pageMarker = this.page.locator( `body.page-id-${ this.pageId }` );
		if (
			( await pageMarker.count() ) !== 1 ||
			! ( await pageMarker.isVisible() )
		) {
			fail( 'preflight could not prove the marker-bound Classic page.' );
		}
	}

	public async addProductOnce(
		productId: number,
		performWrite: PerformWrite
	): Promise< void > {
		await this.page.goto( `?post_type=product&p=${ productId }` );
		const button = await requireOneEnabled(
			this.page.getByRole( 'button', {
				name: 'Add to cart',
				exact: true,
			} ),
			'Add to cart button'
		);
		await performWrite( () => button.click() );
	}

	public async openClassicCheckout(
		path: typeof CLASSIC_CHECKOUT_PATH
	): Promise< void > {
		await this.page.goto( path );
		this.assertExactPath( path );
		await this.assertNoBlocksMarkup();
		const form = this.page.locator( CLASSIC_CHECKOUT_FORM );
		if ( ( await form.count() ) !== 1 || ! ( await form.isVisible() ) ) {
			fail( 'could not prove exactly one Classic checkout form.' );
		}
	}

	public async fillBillingDetails( runId: string ): Promise< void > {
		const billing = this.page.locator( '.woocommerce-billing-fields' );
		if (
			( await billing.count() ) !== 1 ||
			! ( await billing.isVisible() )
		) {
			fail( 'requires exactly one visible billing form.' );
		}
		await billing.getByLabel( /^First name/i ).fill( 'E2E' );
		await billing.getByLabel( /^Last name/i ).fill( 'WooPayments' );
		// Select2 enhances country and state into a second labelled control, so
		// the accessible name matches two elements. Address the underlying
		// select by id, as the rest of the Core classic-checkout suite does.
		await billing.locator( '#billing_country' ).selectOption( 'US' );
		await billing
			.getByLabel( /^Street address/i )
			.first()
			.fill( '123 Test Street' );
		await billing
			.getByLabel( /^(?:Town \/ City|City)/i )
			.fill( 'San Francisco' );
		await billing.locator( '#billing_state' ).selectOption( 'CA' );
		await billing.getByLabel( /^(?:ZIP Code|Postcode)/i ).fill( '94107' );
		await billing.getByLabel( /^Phone/i ).fill( '5555550100' );
		await billing
			.getByLabel( /^Email address/i )
			.fill( `woopayments-${ runId }@example.com` );
	}

	public async selectWooPaymentsCard(): Promise< void > {
		const card = await requireOneEnabled(
			this.page.getByRole( 'radio', { name: /^Card\b/i } ),
			'Card gateway'
		);
		if ( ( await card.inputValue() ) !== WOOPAYMENTS_GATEWAY ) {
			fail( 'semantic Card gateway is not WooPayments.' );
		}
		await card.check();
	}

	public async fillBasicCard(): Promise< void > {
		const iframe = this.page.locator( CLASSIC_CARD_FRAME );
		await iframe.waitFor( { state: 'visible' } );
		if ( ( await iframe.count() ) !== 1 ) {
			fail( 'requires exactly one native Classic Stripe frame.' );
		}
		const frame = this.page.frameLocator( CLASSIC_CARD_FRAME );
		await frame
			.getByRole( 'textbox', { name: 'Card number' } )
			.fill( '4242424242424242' );
		await frame
			.getByRole( 'textbox', { name: /Expiration date/i } )
			.fill( '0245' );
		await frame
			.getByRole( 'textbox', { name: 'Security code' } )
			.fill( '424' );
	}

	public async captureExposedTokenDigest(): Promise< PublicTokenDigest > {
		const digest = await this.page.evaluate( async () => {
			const value = (
				window as Window & { wcpayFraudPreventionToken?: unknown }
			 ).wcpayFraudPreventionToken;
			if ( typeof value !== 'string' || value.length !== 16 ) {
				throw new Error(
					'Classic fraud-prevention token exposure is invalid.'
				);
			}
			const bytes = new TextEncoder().encode( value );
			const hash = await crypto.subtle.digest( 'SHA-256', bytes );
			return {
				length: value.length,
				sha256: Array.from( new Uint8Array( hash ) )
					.map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
					.join( '' ),
			};
		} );
		assertPublicTokenDigest( digest, 'exposed Classic' );
		return digest;
	}

	public async prepareSubmission(): Promise< void > {
		if ( this.placeOrderButton || this.submissionObservationAttempted ) {
			fail( 'Place order preparation may run only once.' );
		}
		this.placeOrderButton = await requireOneEnabled(
			this.page.getByRole( 'button', {
				name: 'Place order',
				exact: true,
			} ),
			'Place order button'
		);
	}

	public async observeSubmission(
		activateOnce: ( activate: () => Promise< void > ) => Promise< void >
	): Promise< RawClassicSubmissionObservation > {
		if ( ! this.placeOrderButton || this.submissionObservationAttempted ) {
			fail( 'requires one prepared Place order submission.' );
		}
		this.submissionObservationAttempted = true;
		const button = this.placeOrderButton;
		this.placeOrderButton = undefined;
		const requests: RawClassicCheckoutRequest[] = [];
		const responses: Response[] = [];
		const requestIds = new WeakMap< Request, string >();
		let resolveResponseSignal: () => void;
		let rejectResponseSignal: ( error: Error ) => void;
		const responseSignal = new Promise< void >( ( resolve, reject ) => {
			resolveResponseSignal = resolve;
			rejectResponseSignal = reject;
		} );
		void responseSignal.catch( () => undefined );
		const onRequest = ( request: Request ) => {
			if ( ! isClassicCheckoutRequest( request, this.baseURL ) ) {
				return;
			}
			const requestId = `classic-checkout-${ requests.length + 1 }`;
			requestIds.set( request, requestId );
			requests.push( {
				requestId,
				method: request.method(),
				url: request.url(),
				body: request.postData(),
			} );
		};
		const onResponse = ( response: Response ) => {
			if (
				isClassicCheckoutRequest( response.request(), this.baseURL )
			) {
				responses.push( response );
				resolveResponseSignal();
			}
		};
		this.page.on( 'request', onRequest );
		this.page.on( 'response', onResponse );
		const responseTimer = setTimeout( () => {
			rejectResponseSignal(
				new Error( 'Classic checkout response observation timed out.' )
			);
		}, SUBMISSION_TIMEOUT_MS );
		const clearResponseTimer = () => clearTimeout( responseTimer );

		try {
			await activateOnce( () => button.click() );
			await responseSignal;
			await this.page.waitForURL(
				( candidate ) => {
					try {
						parseClassicOrderReceivedUrl(
							candidate.href,
							this.baseURL
						);
						return true;
					} catch {
						return false;
					}
				},
				{ timeout: SUBMISSION_TIMEOUT_MS }
			);

			return {
				requests,
				responses: await Promise.all(
					responses.map( async ( response ) => ( {
						requestId:
							requestIds.get( response.request() ) ??
							'unmatched-classic-response',
						status: response.status(),
						body: await response.json(),
					} ) )
				),
				receiptUrl: this.page.url(),
			};
		} finally {
			clearResponseTimer();
			this.page.off( 'request', onRequest );
			this.page.off( 'response', onResponse );
		}
	}
}

function requireRunOwnedProduct( product: OwnedProduct, runId: string ): void {
	if (
		! Number.isSafeInteger( product.id ) ||
		product.id <= 0 ||
		product.amount !== '10.99' ||
		product.name !== `WooPayments native E2E ${ runId }`
	) {
		fail( 'requires the exact run-owned USD 10.99 product.' );
	}
}

function requireExpectedRequestEvidence(
	evidence: ClassicCheckoutRequestEvidence
): void {
	if (
		evidence.gateway !== WOOPAYMENTS_GATEWAY ||
		evidence.savePaymentMethod !== false ||
		evidence.paymentMethodErrorCodePresent ||
		evidence.paymentMethodErrorMessagePresent ||
		evidence.platformPaymentMethod === 'invalid' ||
		evidence.fingerprintPresent
	) {
		fail(
			'submitted request evidence is not the exact basic-card contract.'
		);
	}
}

function requireExactScope( scope: CardTestingProtectionScope ): void {
	if (
		scope.classicCheckout.path !== CLASSIC_CHECKOUT_PATH ||
		scope.classicCheckout.slug !== 'classic-checkout' ||
		! Number.isSafeInteger( scope.classicCheckout.pageId ) ||
		scope.classicCheckout.pageId <= 0
	) {
		fail( 'requires the exact marker-bound Classic checkout scope.' );
	}
}

function normalizeObservedRequest(
	request: RawClassicCheckoutRequest
): ClassicCheckoutRequestEvidence {
	return normalizeClassicCheckoutRequest( {
		method: () => request.method,
		url: () => request.url,
		postData: () => request.body,
	} );
}

export async function completeClassicCardCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	runId: string,
	scope: CardTestingProtectionScope,
	options: CompleteClassicCardCheckoutOptions = {}
): Promise< ClassicCardCheckoutEvidence > {
	if (
		typeof runId !== 'string' ||
		! runId.trim() ||
		runId !== session.runId
	) {
		fail( 'requires the exact active run ID.' );
	}
	requireRunOwnedProduct( product, runId );
	requireExactScope( scope );
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'basic-card' );
	session.requireApprovedProviderFixture( 'basic-card-entry' );
	const browser =
		options.browser ??
		new PlaywrightClassicCardCheckoutBrowser(
			page,
			session.baseURL,
			scope.classicCheckout.pageId
		);

	await browser.preflightClassicPage(
		scope.classicCheckout.path,
		scope.classicCheckout.pageId
	);
	let addActivationCount = 0;
	await browser.addProductOnce( product.id, async ( write ) => {
		addActivationCount += 1;
		if ( addActivationCount !== 1 ) {
			fail( 'must activate Add to cart exactly once.' );
		}
		return session.performWrite( write );
	} );
	if ( addActivationCount !== 1 ) {
		fail( 'must activate Add to cart exactly once.' );
	}

	await browser.openClassicCheckout( scope.classicCheckout.path );
	await browser.fillBillingDetails( runId );
	await browser.selectWooPaymentsCard();
	await browser.fillBasicCard();
	const exposed = await browser.captureExposedTokenDigest();
	const authoritativeSession = await scope.captureGuestSessionToken( page );
	assertPublicTokenDigest( exposed, 'exposed Classic' );
	assertPublicTokenDigest( authoritativeSession, 'authoritative session' );
	assertSameTokenDigest( exposed, authoritativeSession );
	await browser.prepareSubmission();

	return session.withProviderSubmissionJournal(
		'basic-card-classic-checkout',
		async () => {
			try {
				let placeOrderActivationCount = 0;
				const observation = await browser.observeSubmission(
					async ( activate ) => {
						placeOrderActivationCount += 1;
						if ( placeOrderActivationCount !== 1 ) {
							throw quarantine();
						}
						await session.performWrite( activate );
					}
				);
				if (
					placeOrderActivationCount !== 1 ||
					observation.requests.length !== 1 ||
					observation.responses.length !== 1
				) {
					throw quarantine();
				}
				const rawRequest = observation.requests[ 0 ];
				const rawResponse = observation.responses[ 0 ];
				if ( rawResponse.requestId !== rawRequest.requestId ) {
					throw quarantine();
				}
				const request = normalizeObservedRequest( rawRequest );
				requireExpectedRequestEvidence( request );
				assertPublicTokenDigest(
					request.fraudPreventionToken,
					'submitted'
				);
				assertSameTokenDigest(
					authoritativeSession,
					request.fraudPreventionToken
				);
				const response = normalizeClassicCheckoutResponse(
					rawResponse.status,
					rawResponse.body,
					session.baseURL
				);
				const receipt = parseClassicOrderReceivedUrl(
					observation.receiptUrl,
					session.baseURL
				);
				if (
					response.orderId !== receipt.orderId ||
					response.orderKey !== receipt.orderKey
				) {
					throw quarantine();
				}
				await session.setOrderRunId( response.orderId, runId );
				return {
					runId,
					orderId: response.orderId,
					orderKey: response.orderKey,
					tokens: {
						exposed,
						authoritativeSession,
						submitted: request.fraudPreventionToken,
					},
					request,
					response,
					receipt,
				};
			} catch {
				throw quarantine();
			}
		}
	);
}
