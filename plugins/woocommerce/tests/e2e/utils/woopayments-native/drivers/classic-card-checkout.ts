import { createHash } from 'node:crypto';

import type { Locator, Page, Request, Response } from '@playwright/test';

import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { ProviderTestCard } from '../test-cards';
import { enterProviderCardTriple } from './card-entry';
import type { CardTestingProtectionScope } from './card-testing-protection';

export const CLASSIC_CHECKOUT_PATH = 'classic-checkout/';
const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const TOKEN_LENGTH = 16;
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const SUBMISSION_TIMEOUT_MS = 60_000;
const CHECKOUT_SETTLE_TIMEOUT_MS = 15_000;
const OVERLAY_SETTLE_TIMEOUT_MS = 5_000;
const BLOCKS_CHECKOUT_MARKERS =
	'[data-block-name="woocommerce/checkout"], .wp-block-woocommerce-checkout, .wc-block-checkout';
const CLASSIC_CHECKOUT_FORM = 'form.checkout.woocommerce-checkout';
const CLASSIC_GATEWAY_BOX = '#payment .payment_method_woocommerce_payments';
/**
 * The card frame, addressed across both runtimes.
 *
 * The client plugin mounts its payment element in `.wcpay-upe-element`; native
 * mounts it in `#wcpay-core-payment-element`, printed by
 * `WooPaymentsCheckoutBridge::render_payment_fields()`. The class the client
 * uses appears nowhere in native's markup - only in the shared stylesheet - so
 * a client-only selector cannot find a native card field, and the one authorized
 * run that would have exposed that failed earlier, in the billing step. Both
 * containers are matched here because only one of them exists on a given page,
 * which leaves the exactly-one-frame check below binding rather than permissive.
 */
const CLASSIC_CARD_FRAME =
	`${ CLASSIC_GATEWAY_BOX } .wcpay-upe-element iframe, ` +
	`${ CLASSIC_GATEWAY_BOX } #wcpay-core-payment-element iframe[name^="__privateStripeFrame"]`;
// Native's own shopper-facing error region on the Classic surface, printed
// hidden with `role="alert"` and filled by the classic checkout script. It is
// the announcement channel of this surface: unlike Blocks, nothing here routes
// through `wp.a11y.speak`, so `#a11y-speak-assertive` stays empty.
const CLASSIC_PAYMENT_ERROR = '#wcpay-core-payment-errors';
// Where WooCommerce's own checkout script prepends a rejected submission's
// notices, wrapped in an element it gives `role="alert"`.
const CLASSIC_NOTICE_GROUP = `${ CLASSIC_CHECKOUT_FORM } .woocommerce-NoticeGroup-checkout`;
// `WC_Payment_Gateway::save_payment_method_checkbox()`; native reuses it
// verbatim outside subscription checkouts.
const SAVE_CONTROL_LABEL = 'Save to account';

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
	/**
	 * Whether `wcpay-fingerprint` carries a FingerprintJS device visitor id
	 * (32 lowercase hex), the value the WooPayments extension posts. A
	 * provider/card fingerprint or any other shape is not that.
	 */
	deviceFingerprint: boolean;
}

/**
 * What a submission that is expected to be turned away carried.
 */
export interface ClassicRejectedRequestEvidence {
	gateway: ClassicCheckoutRequestEvidence[ 'gateway' ];
	fraudPreventionToken: 'absent' | 'empty' | 'present';
	savePaymentMethod: boolean;
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

/**
 * Everything the store was asked and answered during one submission interval,
 * without any claim about how the interval ended. A journey that has to answer
 * a customer-action challenge between the checkout response and the receipt
 * cannot use the receipt as its end marker, so the two are separated.
 */
export interface RawClassicSubmissionDispatch {
	requests: RawClassicCheckoutRequest[];
	responses: RawClassicCheckoutResponse[];
}

/**
 * Native's Classic payment error region, read as evidence rather than asserted
 * in place, so a caller can state what it expected and fail with the whole
 * observation attached.
 */
export interface ClassicPaymentErrorNotice {
	/** Whether the region carries the assertive role a screen reader reads. */
	role: string | null;
	visible: boolean;
	text: string;
}

/**
 * WooCommerce's own rejected-submission notices, as the shopper receives them.
 */
export interface ClassicCheckoutRejectionNotice {
	alertCount: number;
	messages: string[];
}

/**
 * Whether the shopper can still act after a rejected or failed submission.
 */
export interface ClassicCheckoutRecoveryState {
	onClassicCheckout: boolean;
	placeOrderEnabled: boolean;
	blockingOverlayCount: number;
	paymentMethodChoiceCount: number;
}

/**
 * The fraud-prevention token the Classic script would submit right now, read
 * through the same fallback chain the script uses and reduced to a digest so
 * the token itself never reaches a report.
 */
export type EffectiveFraudPreventionToken =
	| { present: true; digest: PublicTokenDigest }
	| { present: false };

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
		deviceFingerprint: /^[a-f0-9]{32}$/.test(
			values[ 'wcpay-fingerprint' ] ?? ''
		),
	};
}

/**
 * Reads only what a rejected submission needs: which gateway it named and
 * whether it carried a usable session token.
 *
 * The exact-contract normalizer above refuses a request with no token field at
 * all, which is correct for a payment that is meant to settle and wrong for one
 * that is meant to be turned away for exactly that reason.
 */
export function normalizeClassicRejectedRequest(
	request: Pick< Request, 'method' | 'url' | 'postData' >
): ClassicRejectedRequestEvidence {
	if ( ! isClassicCheckoutRequest( request ) ) {
		fail( 'request does not match the exact checkout endpoint.' );
	}
	const body = request.postData();
	if ( typeof body !== 'string' ) {
		fail( 'request has no URL-encoded body.' );
	}
	const parameters = new URLSearchParams( body );
	const token = readAtMostOne( parameters, 'wcpay-fraud-prevention-token' );
	const saveValue = readAtMostOne(
		parameters,
		'wc-woocommerce_payments-new-payment-method'
	);

	let fraudPreventionToken: ClassicRejectedRequestEvidence[ 'fraudPreventionToken' ];
	if ( token === undefined ) {
		fraudPreventionToken = 'absent';
	} else if ( token === '' ) {
		fraudPreventionToken = 'empty';
	} else {
		fraudPreventionToken = 'present';
	}

	return {
		gateway: normalizeGateway(
			readAtMostOne( parameters, 'payment_method' )
		),
		fraudPreventionToken,
		savePaymentMethod: saveValue === 'true',
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
		// With WooPay enabled the store relocates the billing email into a second
		// `.woocommerce-billing-fields` block ("Contact information") above the
		// billing details, on the plugin and on native alike; the billing form
		// proper is the block that carries the name fields.
		const billing = this.page.locator(
			'.woocommerce-billing-fields:has(#billing_first_name)'
		);
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
		// The email field sits in the billing block by default and in the WooPay
		// "Contact information" block when WooPay relocates it; address it by id.
		await this.page
			.locator( '#billing_email' )
			.fill( `woopayments-${ runId }@example.com` );
		await this.waitForCheckoutSettled();
	}

	/**
	 * Waits out WooCommerce's `update_order_review` cycle.
	 *
	 * Filling the billing address schedules WooCommerce's debounced
	 * `update_checkout`, which blocks the order review behind a jQuery blockUI
	 * overlay, replaces the payment box, and remounts native's Stripe payment
	 * element. Submitting inside that window strands the submission in silence:
	 * native's Classic handler returns `false` and waits on `elements.submit()`,
	 * which never settles while the element is being remounted, so the store is
	 * never asked, no notice appears, and the page simply stops changing.
	 *
	 * Rather than guess at a quiet period, this asks for one more update and
	 * waits for WooCommerce's own `updated_checkout`. The request it triggers
	 * cancels and supersedes whatever the field changes had already scheduled,
	 * so what follows runs against a settled page - which is what every other
	 * Classic journey has been relying on the incidental delay of its next step
	 * to provide.
	 */
	public async waitForCheckoutSettled(): Promise< void > {
		const settled = await this.page.evaluate( ( budget ) => {
			const jq = (
				window as unknown as {
					jQuery?: ( target: unknown ) => {
						one: ( event: string, handler: () => void ) => void;
						off: ( event: string, handler: () => void ) => void;
						trigger: ( event: string ) => void;
					};
				}
			 ).jQuery;
			if ( ! jq ) {
				return false;
			}
			return new Promise< boolean >( ( resolve ) => {
				const body = jq( document.body );
				let timer = 0;
				const onUpdated = () => {
					window.clearTimeout( timer );
					resolve( true );
				};
				timer = window.setTimeout( () => {
					body.off( 'updated_checkout', onUpdated );
					resolve( false );
				}, budget );
				body.one( 'updated_checkout', onUpdated );
				body.trigger( 'update_checkout' );
			} );
		}, CHECKOUT_SETTLE_TIMEOUT_MS );
		if ( ! settled ) {
			fail( 'order review never finished updating.' );
		}

		// The unblock fades the overlay out, and a fading overlay still
		// intercepts pointer events, so the last click before it clears lands
		// on the overlay rather than on Place order.
		const overlay = this.page.locator(
			`${ CLASSIC_CHECKOUT_FORM } .blockUI`
		);
		const deadline = Date.now() + CHECKOUT_SETTLE_TIMEOUT_MS;
		while ( ( await overlay.count() ) > 0 ) {
			const remaining = deadline - Date.now();
			if ( remaining <= 0 ) {
				fail(
					'order review stayed blocked after it finished updating.'
				);
			}
			try {
				await overlay
					.first()
					.waitFor( { state: 'detached', timeout: remaining } );
			} catch {
				fail(
					'order review stayed blocked after it finished updating.'
				);
			}
		}
	}

	public async selectWooPaymentsCard(): Promise< void > {
		// Located by name rather than by role: when a store offers a single
		// payment method core renders its radio hidden, because there is no
		// choice to make, and a hidden input has no accessibility role. The
		// contract is still that the shopper pays by the WooPayments Card
		// method, so that is what gets asserted either way.
		const card = this.page.locator(
			`input[name="payment_method"][value="${ WOOPAYMENTS_GATEWAY }"]`
		);
		if ( ( await card.count() ) !== 1 ) {
			fail( 'requires exactly one enabled WooPayments Card gateway.' );
		}

		const label = this.page.locator(
			`label[for="payment_method_${ WOOPAYMENTS_GATEWAY }"]`
		);
		if ( ! ( await label.isVisible() ) ) {
			fail( 'the Card gateway is not offered to the shopper.' );
		}
		if ( ! /^Card\b/i.test( ( await label.innerText() ).trim() ) ) {
			fail( 'the offered gateway is not labelled as Card.' );
		}

		if ( await card.isVisible() ) {
			await card.check();
			return;
		}

		// Sole method: core hides the control and pre-selects it. Requiring a
		// click here would demand a control core deliberately does not render.
		const paymentMethods = this.page.locator(
			'input[name="payment_method"]'
		);
		if ( ( await paymentMethods.count() ) !== 1 ) {
			fail(
				'hides Card even though the shopper has another gateway choice.'
			);
		}
		if ( ! ( await card.isChecked() ) ) {
			fail(
				'the sole Card gateway is hidden but not selected, so no payment method is chosen.'
			);
		}
	}

	public async fillBasicCard(): Promise< void > {
		// The basic card stays written out here rather than imported from
		// `test-cards.ts`, which records why: that module carries the cards
		// whose behaviour the provider selects, and `4242` selects nothing.
		await this.fillTestCard( {
			number: '4242424242424242',
			expiry: '0245',
			securityCode: '424',
		} );
	}

	/**
	 * Fills the Classic payment element with a named provider test card.
	 */
	public async fillTestCard( card: ProviderTestCard ): Promise< void > {
		const iframe = this.page.locator( CLASSIC_CARD_FRAME );
		await iframe.waitFor( { state: 'visible' } );
		if ( ( await iframe.count() ) !== 1 ) {
			fail( 'requires exactly one native Classic Stripe frame.' );
		}
		const frame = this.page.frameLocator( CLASSIC_CARD_FRAME );
		await enterProviderCardTriple( frame, card, 'Classic checkout' );
	}

	/**
	 * Sets the Classic save-to-account control and proves the state it ended in,
	 * so a control that silently refused the click fails here rather than
	 * producing a run that quietly saved nothing.
	 */
	public async setSavePaymentMethod( save: boolean ): Promise< void > {
		const control = await requireOneEnabled(
			this.page.getByRole( 'checkbox', {
				name: SAVE_CONTROL_LABEL,
				exact: true,
			} ),
			'save-to-account control'
		);
		if ( save ) {
			await control.check();
		} else {
			await control.uncheck();
		}
		if ( ( await control.isChecked() ) !== save ) {
			fail( 'save-to-account control did not take the requested state.' );
		}
	}

	/**
	 * Waits for native's Classic payment error region to carry a message.
	 *
	 * Resolves true when the region becomes visible and false when it does not
	 * within the budget, so a caller can report what it saw instead of dying on
	 * a raw timeout after a submission that may have reached the provider.
	 */
	public async waitForPaymentErrorNotice(
		timeoutMs: number
	): Promise< boolean > {
		try {
			await this.page
				.locator( CLASSIC_PAYMENT_ERROR )
				.waitFor( { state: 'visible', timeout: timeoutMs } );
			return true;
		} catch {
			return false;
		}
	}

	/**
	 * Waits for WooCommerce's own rejected-submission notice group.
	 */
	public async waitForCheckoutRejectionNotice(
		timeoutMs: number
	): Promise< boolean > {
		try {
			await this.page
				.locator( `${ CLASSIC_NOTICE_GROUP } [role="alert"]` )
				.waitFor( { state: 'visible', timeout: timeoutMs } );
			return true;
		} catch {
			return false;
		}
	}

	public async readPaymentErrorNotice(): Promise< ClassicPaymentErrorNotice > {
		const notice = this.page.locator( CLASSIC_PAYMENT_ERROR );
		if ( ( await notice.count() ) !== 1 ) {
			fail( 'requires exactly one native Classic payment error region.' );
		}
		return {
			role: await notice.getAttribute( 'role' ),
			visible: await notice.isVisible(),
			text: ( await notice.innerText() ).trim(),
		};
	}

	public async readCheckoutRejectionNotice(): Promise< ClassicCheckoutRejectionNotice > {
		const alerts = this.page.locator(
			`${ CLASSIC_NOTICE_GROUP } [role="alert"]`
		);
		const alertCount = await alerts.count();
		if ( alertCount !== 1 ) {
			return { alertCount, messages: [] };
		}
		// Core prints checkout errors through one of two templates, and which
		// one a store gets is a theme decision rather than anything this
		// contract is about. `notices/error.php` is a `<ul role="alert">` of
		// `<li>` messages; `block-notices/error.php` - what block themes get,
		// including the Twenty Twenty-Five store these run against - is a
		// banner that lists its messages only when there are several and
		// otherwise carries the single message as its own content. So read the
		// list when there is one and the region's text when there is not.
		const alert = alerts.first();
		const items = alert.getByRole( 'listitem' );
		const messages =
			( await items.count() ) > 0
				? await items.allInnerTexts()
				: [ await alert.innerText() ];
		return {
			alertCount,
			messages: messages.map( ( message ) =>
				message.replace( /\s+/g, ' ' ).trim()
			),
		};
	}

	/**
	 * Counts the checkout's blocking overlays once they have stopped changing.
	 *
	 * jQuery blockUI fades its overlay out rather than removing it, so a count
	 * taken the instant a rejection notice appears sees an animation rather
	 * than the state the shopper is left in. This waits for the overlay to go
	 * and reports zero, or gives up and reports what is still there.
	 */
	private async countSettledBlockingOverlays(): Promise< number > {
		const overlay = this.page.locator(
			`${ CLASSIC_CHECKOUT_FORM } .blockUI`
		);
		const deadline = Date.now() + OVERLAY_SETTLE_TIMEOUT_MS;
		for (;;) {
			const count = await overlay.count();
			if ( count === 0 ) {
				return 0;
			}
			const remaining = deadline - Date.now();
			if ( remaining <= 0 ) {
				return count;
			}
			try {
				await overlay
					.first()
					.waitFor( { state: 'detached', timeout: remaining } );
			} catch {
				return overlay.count();
			}
		}
	}

	public async readCheckoutRecoveryState(): Promise< ClassicCheckoutRecoveryState > {
		let onClassicCheckout: boolean;
		try {
			onClassicCheckout =
				new URL( this.page.url() ).pathname.replace( /\/+$/, '' ) ===
				new URL( CLASSIC_CHECKOUT_PATH, this.baseURL ).pathname.replace(
					/\/+$/,
					''
				);
		} catch {
			onClassicCheckout = false;
		}
		const placeOrder = this.page.getByRole( 'button', {
			name: 'Place order',
			exact: true,
		} );
		return {
			onClassicCheckout,
			placeOrderEnabled:
				( await placeOrder.count() ) === 1 &&
				( await placeOrder.isEnabled() ),
			// jQuery blockUI's overlay, which the Classic checkout leaves in
			// place while a submission is in flight and then fades out over
			// several hundred milliseconds. The contract is that the shopper is
			// not left behind an overlay, so the fade is given its moment and
			// the settled state is what gets reported; an overlay that outlasts
			// the budget is still counted, and still fails the caller.
			blockingOverlayCount: await this.countSettledBlockingOverlays(),
			// Radios inside the payment list, addressed by markup rather than
			// by role: core renders one per method and hides it when a store
			// offers a single method, because there is no choice to make, and
			// a hidden input carries no accessibility role. Counting roles
			// would therefore report "nothing left to pay with" on exactly the
			// stores where paying is the only option. Saved-method radios
			// count too, which is why callers assert that something remains
			// rather than taking a gateway census.
			paymentMethodChoiceCount: await this.page
				.locator( '#payment .wc_payment_methods input[type="radio"]' )
				.count(),
		};
	}

	/**
	 * Reads the token the Classic script would submit, through the same
	 * fallback chain it uses: the gateway's rendered configuration first, then
	 * the legacy window global.
	 */
	public async captureEffectiveFraudPreventionTokenDigest(): Promise< EffectiveFraudPreventionToken > {
		const digest = await this.page.evaluate( async () => {
			const readToken = (): string => {
				const browserWindow = window as unknown as Record<
					string,
					unknown
				>;
				for ( const key of Object.keys( browserWindow ) ) {
					if ( ! key.startsWith( 'wcpay_core_checkout_config' ) ) {
						continue;
					}
					const config = browserWindow[ key ];
					const token =
						typeof config === 'object' && config !== null
							? ( config as { fraudPreventionToken?: unknown } )
									.fraudPreventionToken
							: undefined;
					if ( typeof token === 'string' && token !== '' ) {
						return token;
					}
				}
				const legacy = browserWindow.wcpayFraudPreventionToken;
				return typeof legacy === 'string' ? legacy : '';
			};

			const value = readToken();
			if ( value === '' ) {
				return null;
			}
			const hash = await crypto.subtle.digest(
				'SHA-256',
				new TextEncoder().encode( value )
			);
			return {
				length: value.length,
				sha256: Array.from( new Uint8Array( hash ) )
					.map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
					.join( '' ),
			};
		} );

		if ( digest === null ) {
			return { present: false };
		}
		assertPublicTokenDigest( digest, 'effective Classic' );
		return { present: true, digest };
	}

	/**
	 * Removes the fraud-prevention token from every place the Classic script
	 * reads it, so the next submission carries none.
	 *
	 * This is the card-testing shape stated as a shopper-side fact: a client
	 * that submits the checkout without the session token native handed it.
	 * Callers must re-read the effective token afterwards and refuse to submit
	 * while one remains - a submission that still carries a valid token would
	 * settle a real payment instead of proving a rejection.
	 */
	public async clearFraudPreventionToken(): Promise< void > {
		await this.page.evaluate( () => {
			const browserWindow = window as unknown as Record<
				string,
				unknown
			>;
			for ( const key of Object.keys( browserWindow ) ) {
				if ( ! key.startsWith( 'wcpay_core_checkout_config' ) ) {
					continue;
				}
				const config = browserWindow[ key ];
				if ( typeof config === 'object' && config !== null ) {
					(
						config as { fraudPreventionToken?: unknown }
					 ).fraudPreventionToken = '';
				}
			}
			browserWindow.wcpayFraudPreventionToken = '';
		} );
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
		const observation = await this.observeSubmissionInterval(
			activateOnce,
			async () => {
				await this.waitForClassicReceipt();
			}
		);

		return {
			requests: observation.dispatch.requests,
			responses: observation.dispatch.responses,
			receiptUrl: observation.url,
		};
	}

	/**
	 * Waits for the receipt the Classic checkout redirects to, and returns the
	 * order it names.
	 */
	public async waitForClassicReceipt(
		timeoutMs: number = SUBMISSION_TIMEOUT_MS
	): Promise< ClassicOrderReceipt > {
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
			{ timeout: timeoutMs }
		);

		return parseClassicOrderReceivedUrl( this.page.url(), this.baseURL );
	}

	/**
	 * Activates Place order exactly once and observes every checkout request
	 * and response for the whole interval the caller needs, not only up to the
	 * receipt.
	 *
	 * A payment that requires customer action does not end at the checkout
	 * response: native answers it with a confirmation hash, the shopper answers
	 * a challenge, and only then does a receipt or a failure appear. `settle`
	 * owns that middle, and the observation stays open across it so a second
	 * submission dispatched at any point in the interval is still counted.
	 */
	public async observeSubmissionInterval< Result >(
		activateOnce: ( activate: () => Promise< void > ) => Promise< void >,
		settle: ( dispatch: RawClassicSubmissionDispatch ) => Promise< Result >
	): Promise< {
		dispatch: RawClassicSubmissionDispatch;
		result: Result;
		url: string;
	} > {
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
		const decoded: RawClassicCheckoutResponse[] = [];
		// Bodies are read once each, in arrival order, so reading the dispatch
		// twice - before and after the customer-action interval - cannot
		// double-count or re-consume a response.
		const decodeNewResponses = async (): Promise< void > => {
			while ( decoded.length < responses.length ) {
				const response = responses[ decoded.length ];
				// The body may be gone by the time it is read. A checkout that
				// succeeds navigates to the receipt, and the browser discards
				// the body of a request whose page it has left — so reading it
				// races the very success it is meant to record, and success is
				// the case that loses. An unreadable body is recorded as
				// undefined rather than thrown, because the outcome is proven
				// from the provider record and the order, and the status line
				// below is retained either way.
				const body = await response
					.json()
					.catch( () => undefined as unknown );
				decoded.push( {
					requestId:
						requestIds.get( response.request() ) ??
						'unmatched-classic-response',
					status: response.status(),
					body,
				} );
			}
		};

		try {
			await activateOnce( () => button.click() );
			await responseSignal;
			await decodeNewResponses();
			const result = await settle( {
				requests: [ ...requests ],
				responses: [ ...decoded ],
			} );
			await decodeNewResponses();

			return {
				dispatch: { requests, responses: decoded },
				result,
				url: this.page.url(),
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
		! evidence.deviceFingerprint
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
