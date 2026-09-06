import type {
	APIRequestContext,
	APIResponse,
	Page,
	Request,
	Response,
	Route,
} from '@playwright/test';

import {
	expect,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

const CHECKOUT_ROUTE = '**/wp-json/wc/store/v1/checkout*';
const CHECKOUT_PATH = '/wp-json/wc/store/v1/checkout';
const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::';
const FALSE_TITLE =
	'Successful purchase › Carding protection false › using a basic card';
const TRUE_TITLE =
	'Successful purchase › Carding protection true › using a basic card';

type CardPaymentScenarioAxes = Readonly< {
	contractId: string;
	title: string;
	card: 'basic-card';
	price: '10.99';
} >;

export type CardPaymentScenarioDefinition = CardPaymentScenarioAxes &
	(
		| Readonly< {
				protection: false;
				checkout: Readonly< {
					kind: 'blocks';
					path: 'checkout/';
				} >;
		  } >
		| Readonly< {
				protection: true;
				checkout: Readonly< {
					kind: 'classic';
					path: 'classic-checkout/';
				} >;
		  } >
	);

export function defineCardPaymentScenario(
	definition: CardPaymentScenarioDefinition
): CardPaymentScenarioDefinition {
	if (
		typeof definition !== 'object' ||
		definition === null ||
		Array.isArray( definition )
	) {
		throw new Error( 'Unsupported card payment scenario definition.' );
	}
	const record = definition as unknown as Record< string, unknown >;
	const checkout = record.checkout as Record< string, unknown >;
	const title = record.protection === true ? TRUE_TITLE : FALSE_TITLE;
	const kind = record.protection === true ? 'classic' : 'blocks';
	const path = record.protection === true ? 'classic-checkout/' : 'checkout/';
	if (
		Object.keys( record ).toSorted().join( ',' ) !==
			'card,checkout,contractId,price,protection,title' ||
		( record.protection !== false && record.protection !== true ) ||
		record.contractId !== `${ CONTRACT_PREFIX }${ title }` ||
		record.title !== title ||
		record.card !== 'basic-card' ||
		record.price !== '10.99' ||
		typeof checkout !== 'object' ||
		checkout === null ||
		Array.isArray( checkout ) ||
		Object.keys( checkout ).toSorted().join( ',' ) !== 'kind,path' ||
		checkout.kind !== kind ||
		checkout.path !== path
	) {
		throw new Error( 'Unsupported card payment scenario definition.' );
	}

	return Object.freeze( {
		contractId: record.contractId,
		title: record.title,
		protection: record.protection,
		card: 'basic-card',
		price: '10.99',
		checkout: Object.freeze( { kind, path } ),
	} ) as CardPaymentScenarioDefinition;
}

interface CardPaymentDataEntry {
	key: unknown;
	value: unknown;
}

interface CardPaymentCheckoutRequest {
	requestId: string;
	paymentMethod: unknown;
	paymentData: CardPaymentDataEntry[];
}

interface CardPaymentCheckoutResponse {
	requestId: string;
	status: number;
	orderId: unknown;
	orderKey: unknown;
}

export interface CardPaymentTokenDigest {
	length: number;
	sha256: string;
}

interface ProviderCardEvidence {
	type: unknown;
	brand: unknown;
	last4: unknown;
}

export interface CardPaymentProviderCardEvidence {
	type: 'card';
	brand: 'visa';
	last4: '4242';
}

export interface CardPaymentScenarioEvidence {
	runId: string;
	account: {
		cardTestingProtectionEligible: unknown;
	};
	browser: {
		renderedFraudPreventionToken: unknown;
		legacyFraudPreventionToken: unknown;
	};
	protectionTokens?: {
		authoritativeSession: CardPaymentTokenDigest;
		exposed: CardPaymentTokenDigest;
		submitted: CardPaymentTokenDigest;
	};
	checkoutRequests: CardPaymentCheckoutRequest[];
	checkoutResponses: CardPaymentCheckoutResponse[];
	observerFailures?: string[];
	adapterOrderId: number;
	adapterOrderKey?: unknown;
	orderReceived: {
		orderId: number;
		orderKey: string;
	};
	order: {
		id: unknown;
		orderKey: unknown;
		paymentMethod: unknown;
		customerId: unknown;
	};
	payment: PaymentEvidence;
	providerIntent: {
		id: unknown;
		paymentMethodId: unknown;
		customerId: unknown;
		nextAction: unknown;
		setupFutureUsage: unknown;
	};
	providerCustomerPaymentMethods: unknown;
	providerCard?: ProviderCardEvidence;
	accessibleSummary: {
		statusVisible: boolean;
		summaryCount: number;
		text: string;
		paymentValue: string;
	};
}

interface ClassicCardCheckoutResultEvidence {
	runId: string;
	orderId: number;
	orderKey: string;
	tokens: {
		exposed: CardPaymentTokenDigest;
		authoritativeSession: CardPaymentTokenDigest;
		submitted: CardPaymentTokenDigest;
	};
	request: {
		gateway: 'woocommerce_payments' | 'other' | 'absent';
		savePaymentMethod: boolean;
		fraudPreventionToken: CardPaymentTokenDigest;
		paymentMethodErrorCodePresent: boolean;
		paymentMethodErrorMessagePresent: boolean;
		platformPaymentMethod: 'true' | 'false' | 'invalid';
		fingerprintPresent: boolean;
	};
	response: {
		status: number;
		orderId: number;
		orderKey: string;
	};
	receipt: {
		orderId: number;
		orderKey: string;
	};
}

export type CardPaymentCheckoutResult =
	| Readonly< { kind: 'blocks'; orderId: number } >
	| Readonly< {
			kind: 'classic';
			evidence: ClassicCardCheckoutResultEvidence;
	  } >;

export interface CardPaymentRuntimeAdapter< Scope = undefined > {
	withState: < Result >(
		session: ProviderWriteSession,
		runId: string,
		callback: ( scope: Scope ) => Promise< Result >
	) => Promise< Result >;
	completeCheckout: (
		session: ProviderWriteSession,
		page: Page,
		product: OwnedProduct,
		runId: string,
		definition: CardPaymentScenarioDefinition,
		scope: Scope
	) => Promise< CardPaymentCheckoutResult >;
	readCardEvidence?: (
		session: ProviderWriteSession,
		payment: PaymentEvidence
	) => Promise< CardPaymentProviderCardEvidence >;
}

interface AccountResponse {
	card_testing_protection_eligible?: unknown;
}

interface OrderResponse {
	id?: unknown;
	order_key?: unknown;
	payment_method?: unknown;
	customer_id?: unknown;
}

interface ProviderIntentResponse {
	id?: unknown;
	payment_method?: unknown;
	customer?: unknown;
	next_action?: unknown;
	setup_future_usage?: unknown;
}

interface StoreCheckoutResponse {
	order_id?: unknown;
	order_key?: unknown;
}

interface CheckoutObservation {
	requests: CardPaymentCheckoutRequest[];
	responses: CardPaymentCheckoutResponse[];
	observerFailures: string[];
	browser: CardPaymentScenarioEvidence[ 'browser' ];
	responseTasks: Promise< void >[];
	requestIds: WeakMap< Request, string >;
}

function fail( message: string ): never {
	throw new Error( `Card payment evidence mismatch: ${ message }` );
}

function requireNonEmptyString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `requires a non-empty ${ label }.` );
	}
	return value;
}

function requirePositiveInteger( value: unknown, label: string ): number {
	if ( ! Number.isSafeInteger( value ) || Number( value ) <= 0 ) {
		fail( `requires a positive ${ label }.` );
	}
	return value as number;
}

function requireStrictEligibility( value: unknown, expected: boolean ): void {
	if ( value !== expected ) {
		fail(
			`card-testing protection eligibility must be the strict Boolean ${ String(
				expected
			) }.`
		);
	}
}

function requireTokenDigest(
	value: unknown,
	label: string
): asserts value is { length: 16; sha256: string } {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value ) ||
		! ( 'length' in value ) ||
		value.length !== 16 ||
		! ( 'sha256' in value ) ||
		typeof value.sha256 !== 'string' ||
		! /^[a-f0-9]{64}$/.test( value.sha256 )
	) {
		fail( `${ label } must be an exact 16-character token digest.` );
	}
}

function requireStrictTrueProtection(
	evidence: CardPaymentScenarioEvidence,
	data: Map< string, unknown >
): void {
	const tokens = evidence.protectionTokens;
	if ( ! tokens ) {
		fail( 'strict-true protection requires token evidence.' );
	}
	const submittedField = data.get( 'wcpay-fraud-prevention-token' );
	requireTokenDigest(
		tokens.authoritativeSession,
		'authoritative session token'
	);
	requireTokenDigest( tokens.exposed, 'Classic exposure token' );
	requireTokenDigest( tokens.submitted, 'submitted token' );
	requireTokenDigest( submittedField, 'sole submitted field token' );
	if (
		new Set( [
			tokens.authoritativeSession.sha256,
			tokens.exposed.sha256,
			tokens.submitted.sha256,
			submittedField.sha256,
		] ).size !== 1
	) {
		fail( 'strict-true protection token digests must be exactly equal.' );
	}
	if (
		evidence.providerCard?.type !== 'card' ||
		evidence.providerCard.brand !== 'visa' ||
		evidence.providerCard.last4 !== '4242'
	) {
		fail( 'provider card evidence must be card, Visa, and last4 4242.' );
	}
}

function paymentDataMap(
	entries: CardPaymentDataEntry[]
): Map< string, unknown > {
	const values = new Map< string, unknown >();
	for ( const entry of entries ) {
		const key = requireNonEmptyString( entry.key, 'payment-data key' );
		if ( values.has( key ) ) {
			fail( 'checkout payment data must have unique payment-data keys.' );
		}
		values.set( key, entry.value );
	}
	return values;
}

function requireEmptyPaymentField(
	data: Map< string, unknown >,
	key: string
): void {
	if ( data.get( key ) !== '' ) {
		fail( `${ key } must be empty.` );
	}
}

function normalizeSummaryText( text: string ): string {
	return text.replace( /\s+/g, ' ' ).trim();
}

export function validateCardPaymentEvidence(
	evidence: CardPaymentScenarioEvidence,
	definition?: Pick< CardPaymentScenarioDefinition, 'protection' >
): void {
	const expectedProtection = definition?.protection ?? false;
	requireStrictEligibility(
		evidence.account.cardTestingProtectionEligible,
		expectedProtection
	);
	if ( evidence.observerFailures?.length ) {
		fail( 'checkout observation did not complete cleanly.' );
	}
	if ( evidence.checkoutRequests.length !== 1 ) {
		fail(
			`requires exactly one checkout request; observed ${ evidence.checkoutRequests.length }.`
		);
	}
	if ( evidence.checkoutResponses.length !== 1 ) {
		fail(
			`requires exactly one checkout response; observed ${ evidence.checkoutResponses.length }.`
		);
	}

	const request = evidence.checkoutRequests[ 0 ];
	const response = evidence.checkoutResponses[ 0 ];
	if ( request.requestId !== response.requestId ) {
		fail( 'the checkout response must belong to the checkout request.' );
	}
	if ( response.status < 200 || response.status >= 300 ) {
		fail( 'the checkout response must be successful.' );
	}
	if ( request.paymentMethod !== WOOPAYMENTS_GATEWAY ) {
		fail( 'the checkout request must use the WooPayments gateway.' );
	}
	const data = paymentDataMap( request.paymentData );
	if ( data.get( 'wc-woocommerce_payments-new-payment-method' ) !== false ) {
		fail( 'the save flag must be the Boolean false.' );
	}
	for ( const emptyField of [
		'wcpay-payment-method-error-code',
		'wcpay-payment-method-error-message',
		'wcpay-fingerprint',
	] ) {
		requireEmptyPaymentField( data, emptyField );
	}
	if (
		! [ 'true', 'false' ].includes(
			data.get( 'wcpay-is-platform-payment-method' ) as string
		)
	) {
		fail( 'the platform marker must be a public-safe string Boolean.' );
	}
	if ( expectedProtection ) {
		requireStrictTrueProtection( evidence, data );
	} else {
		requireEmptyPaymentField( data, 'wcpay-fraud-prevention-token' );
		if ( evidence.browser.renderedFraudPreventionToken !== '' ) {
			fail(
				'the rendered fraud prevention token setting must be explicitly empty.'
			);
		}
		if ( evidence.browser.legacyFraudPreventionToken !== undefined ) {
			fail( 'the legacy fraud prevention token must be undefined.' );
		}
	}

	const responseOrderId = requirePositiveInteger(
		response.orderId,
		'checkout response order ID'
	);
	const receivedOrderId = requirePositiveInteger(
		evidence.orderReceived.orderId,
		'order-received order ID'
	);
	const orderId = requirePositiveInteger(
		evidence.order.id,
		'WooCommerce order ID'
	);
	const paymentOrderId = requirePositiveInteger(
		evidence.payment.orderId,
		'payment evidence order ID'
	);
	if (
		new Set( [
			responseOrderId,
			evidence.adapterOrderId,
			receivedOrderId,
			orderId,
			paymentOrderId,
		] ).size !== 1
	) {
		fail( 'order ID correlation is incomplete.' );
	}

	const responseOrderKey = requireNonEmptyString(
		response.orderKey,
		'checkout response order key'
	);
	const receivedOrderKey = requireNonEmptyString(
		evidence.orderReceived.orderKey,
		'order-received order key'
	);
	const orderKey = requireNonEmptyString(
		evidence.order.orderKey,
		'WooCommerce order key'
	);
	const paymentOrderKey = requireNonEmptyString(
		evidence.payment.orderKey,
		'payment evidence order key'
	);
	const correlatedOrderKeys = [
		responseOrderKey,
		receivedOrderKey,
		orderKey,
		paymentOrderKey,
	];
	if ( expectedProtection && evidence.adapterOrderKey === undefined ) {
		fail( 'strict-true protection requires an adapter order key.' );
	}
	if ( evidence.adapterOrderKey !== undefined ) {
		correlatedOrderKeys.push(
			requireNonEmptyString(
				evidence.adapterOrderKey,
				'adapter order key'
			)
		);
	}
	if ( new Set( correlatedOrderKeys ).size !== 1 ) {
		fail( 'order key correlation is incomplete.' );
	}
	if ( evidence.order.paymentMethod !== WOOPAYMENTS_GATEWAY ) {
		fail( 'the WooCommerce order must use the WooPayments gateway.' );
	}
	if ( evidence.order.customerId !== 0 ) {
		fail( 'the WooCommerce order must retain guest customer ID 0.' );
	}

	const runId = requireNonEmptyString( evidence.runId, 'run ID' );
	if ( evidence.payment.runId !== runId ) {
		fail( 'run ID correlation is incomplete.' );
	}
	const intentId = requireNonEmptyString(
		evidence.payment.intentId,
		'intent ID'
	);
	requireNonEmptyString( evidence.payment.chargeId, 'charge ID' );
	const paymentMethodId = requireNonEmptyString(
		evidence.payment.paymentMethodId,
		'payment-method ID'
	);
	if (
		evidence.payment.amountMinor !== 1099 ||
		evidence.payment.currency !== 'USD'
	) {
		fail( 'the payment amount must be exactly 1099 USD.' );
	}
	if (
		! [ 'processing', 'completed' ].includes( evidence.payment.orderStatus )
	) {
		fail( 'the merchant order status must be processing or completed.' );
	}
	if ( evidence.payment.providerStatus !== 'succeeded' ) {
		fail( 'the provider intent status must be succeeded.' );
	}
	if ( evidence.payment.chargeStatus !== 'succeeded' ) {
		fail( 'the provider charge status must be succeeded.' );
	}
	if ( evidence.payment.chargeCaptured !== true ) {
		fail( 'the provider charge must be captured.' );
	}
	if ( evidence.payment.occurrenceCount !== 1 ) {
		fail( 'requires exactly one charge occurrence.' );
	}
	if ( evidence.payment.captureOccurrenceCount !== 1 ) {
		fail( 'requires exactly one capture occurrence.' );
	}

	if ( evidence.providerIntent.id !== intentId ) {
		fail( 'provider intent ID correlation is incomplete.' );
	}
	if ( evidence.providerIntent.paymentMethodId !== paymentMethodId ) {
		fail( 'provider payment-method ID correlation is incomplete.' );
	}
	requireNonEmptyString(
		evidence.providerIntent.customerId,
		'provider customer ID'
	);
	if (
		evidence.providerIntent.nextAction !== null &&
		evidence.providerIntent.nextAction !== undefined
	) {
		fail( 'the provider intent must not require a next action.' );
	}
	if (
		evidence.providerIntent.setupFutureUsage !== null &&
		evidence.providerIntent.setupFutureUsage !== undefined
	) {
		fail( 'the provider intent must not set up future usage.' );
	}
	if (
		! Array.isArray( evidence.providerCustomerPaymentMethods ) ||
		evidence.providerCustomerPaymentMethods.length !== 0
	) {
		fail(
			'the provider customer payment-method collection must be empty.'
		);
	}

	if ( evidence.accessibleSummary.statusVisible !== true ) {
		fail( 'requires a visible order-received status.' );
	}
	if ( evidence.accessibleSummary.summaryCount !== 1 ) {
		fail( 'requires exactly one semantic order summary.' );
	}
	const summary = normalizeSummaryText( evidence.accessibleSummary.text );
	if (
		! new RegExp(
			`(?:Order number:|Order #:)\\s*#?${ orderId }(?:\\D|$)`,
			'i'
		).test( summary )
	) {
		fail(
			'the semantic order summary must expose the exact order number.'
		);
	}
	if ( ! /(?:US\$|\$)\s*10\.99\b|\b10\.99\s*USD\b/i.test( summary ) ) {
		fail(
			'the semantic order summary must expose the exact USD 10.99 total.'
		);
	}
	if ( ! /(?:Payment method|Payment):/i.test( summary ) ) {
		fail( 'the semantic order summary must expose a payment row.' );
	}
	const paymentValue = normalizeSummaryText(
		evidence.accessibleSummary.paymentValue
	);
	if ( ! /\b(?:card|visa)\b/i.test( paymentValue ) ) {
		fail(
			'the semantic payment value must expose card or Visa semantics.'
		);
	}
	if ( ! /\b4242\b/.test( paymentValue ) ) {
		fail( 'the semantic payment value must expose basic-card last4 4242.' );
	}
}

function isCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		return (
			new URL( request.url() ).pathname.replace( /\/+$/, '' ) ===
			CHECKOUT_PATH
		);
	} catch {
		return false;
	}
}

function normalizePaymentData( value: unknown ): CardPaymentDataEntry[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return value.map( ( entry ) =>
		typeof entry === 'object' && entry !== null
			? {
					key: 'key' in entry ? entry.key : undefined,
					value: 'value' in entry ? entry.value : undefined,
			  }
			: { key: undefined, value: undefined }
	);
}

function normalizeCheckoutRequest(
	requestId: string,
	body: unknown
): CardPaymentCheckoutRequest {
	const record =
		typeof body === 'object' && body !== null
			? ( body as Record< string, unknown > )
			: {};
	return {
		requestId,
		paymentMethod: record.payment_method,
		paymentData: normalizePaymentData( record.payment_data ),
	};
}

async function readJson(
	response: APIResponse,
	resource: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`Unable to read ${ resource }: HTTP ${ response.status() }.`
		);
	}
	return response.json();
}

async function readDispatchBrowserEvidence(
	page: Page
): Promise< CardPaymentScenarioEvidence[ 'browser' ] > {
	return page.evaluate( () => {
		type WooPaymentsBrowserWindow = Window & {
			wcSettings?: {
				paymentMethodData?: {
					woocommerce_payments?: {
						fraudPreventionToken?: unknown;
					};
				};
			};
			wcpayFraudPreventionToken?: unknown;
		};
		const browserWindow = window as WooPaymentsBrowserWindow;
		return {
			renderedFraudPreventionToken:
				browserWindow.wcSettings?.paymentMethodData
					?.woocommerce_payments?.fraudPreventionToken,
			legacyFraudPreventionToken: browserWindow.wcpayFraudPreventionToken,
		};
	} );
}

function newCheckoutObservation(): CheckoutObservation {
	return {
		requests: [],
		responses: [],
		observerFailures: [],
		browser: {
			renderedFraudPreventionToken: undefined,
			legacyFraudPreventionToken: undefined,
		},
		responseTasks: [],
		requestIds: new WeakMap< Request, string >(),
	};
}

async function captureCheckoutResponse(
	response: Response,
	observation: CheckoutObservation
): Promise< void > {
	const request = response.request();
	if ( ! isCheckoutRequest( request ) ) {
		return;
	}
	const body = ( await response.json() ) as StoreCheckoutResponse;
	observation.responses.push( {
		requestId:
			observation.requestIds.get( request ) ??
			'unmatched-checkout-response',
		status: response.status(),
		orderId: body?.order_id,
		orderKey: body?.order_key,
	} );
}

async function captureOrderReceived(
	page: Page
): Promise< CardPaymentScenarioEvidence[ 'orderReceived' ] > {
	const url = new URL( page.url() );
	const orderMatch = url.pathname.match( /\/order-received\/([1-9]\d*)\/?$/ );
	return {
		orderId: orderMatch ? Number( orderMatch[ 1 ] ) : 0,
		orderKey: url.searchParams.get( 'key' ) ?? '',
	};
}

async function captureAccessibleSummary(
	page: Page
): Promise< CardPaymentScenarioEvidence[ 'accessibleSummary' ] > {
	const statusName = /^(Your order has been received|Order received)$/i;
	const statusHeading = page.getByRole( 'heading', { name: statusName } );
	const statusVisible = ( await statusHeading.first().isVisible() )
		? true
		: await page.getByText( statusName ).first().isVisible();
	const summaries = page
		.getByRole( 'list' )
		.filter( { hasText: /(?:Order number|Order #):/i } )
		.filter( { hasText: /Total:/i } )
		.filter( { hasText: /(?:Payment method|Payment):/i } );
	const summaryCount = await summaries.count();
	let paymentValue = '';
	if ( summaryCount === 1 ) {
		const paymentRows = summaries
			.first()
			.getByRole( 'listitem' )
			.filter( { hasText: /^\s*(?:Payment method|Payment):/i } );
		if ( ( await paymentRows.count() ) === 1 ) {
			const value = paymentRows
				.first()
				.locator(
					'.wc-block-order-confirmation-summary-list-item__value, strong'
				);
			if ( ( await value.count() ) === 1 ) {
				const imageAlts = await value
					.locator( 'img[alt]' )
					.evaluateAll( ( images ) =>
						images.map(
							( image ) => image.getAttribute( 'alt' ) ?? ''
						)
					);
				paymentValue = normalizeSummaryText(
					[ await value.innerText(), ...imageAlts ].join( ' ' )
				);
			}
		}
	}
	return {
		statusVisible,
		summaryCount,
		text:
			summaryCount === 1
				? normalizeSummaryText( await summaries.first().innerText() )
				: '',
		paymentValue,
	};
}

interface CompletedCheckoutEvidence {
	orderId: number;
	orderKey?: string;
	orderReceived: CardPaymentScenarioEvidence[ 'orderReceived' ];
	protectionTokens?: CardPaymentScenarioEvidence[ 'protectionTokens' ];
}

function classicPaymentData(
	evidence: ClassicCardCheckoutResultEvidence
): CardPaymentDataEntry[] {
	return [
		{
			key: 'wc-woocommerce_payments-new-payment-method',
			value: evidence.request.savePaymentMethod,
		},
		{
			key: 'wcpay-fraud-prevention-token',
			value: evidence.request.fraudPreventionToken,
		},
		{
			key: 'wcpay-payment-method-error-code',
			value: evidence.request.paymentMethodErrorCodePresent
				? 'present'
				: '',
		},
		{
			key: 'wcpay-payment-method-error-message',
			value: evidence.request.paymentMethodErrorMessagePresent
				? 'present'
				: '',
		},
		{
			key: 'wcpay-fingerprint',
			value: evidence.request.fingerprintPresent ? 'present' : '',
		},
		{
			key: 'wcpay-is-platform-payment-method',
			value: evidence.request.platformPaymentMethod,
		},
	];
}

async function normalizeCompletedCheckout(
	definition: CardPaymentScenarioDefinition,
	result: CardPaymentCheckoutResult,
	runId: string,
	page: Page,
	observation: CheckoutObservation
): Promise< CompletedCheckoutEvidence > {
	if ( definition.checkout.kind === 'blocks' ) {
		if ( result.kind !== 'blocks' ) {
			fail( 'the adapter returned the wrong checkout surface.' );
		}
		return {
			orderId: result.orderId,
			orderReceived: await captureOrderReceived( page ),
		};
	}
	if ( result.kind !== 'classic' ) {
		fail( 'the adapter returned the wrong checkout surface.' );
	}
	const classic = result.evidence;
	if ( classic.runId !== runId ) {
		fail( 'adapter run ID correlation is incomplete.' );
	}
	const requestId = 'classic-checkout-1';
	observation.requests.push( {
		requestId,
		paymentMethod:
			classic.request.gateway === 'absent'
				? undefined
				: classic.request.gateway,
		paymentData: classicPaymentData( classic ),
	} );
	observation.responses.push( {
		requestId,
		status: classic.response.status,
		orderId: classic.response.orderId,
		orderKey: classic.response.orderKey,
	} );
	return {
		orderId: classic.orderId,
		orderKey: classic.orderKey,
		orderReceived: classic.receipt,
		protectionTokens: classic.tokens,
	};
}

async function readScenarioEvidence< Scope >(
	session: ProviderWriteSession,
	definition: CardPaymentScenarioDefinition,
	adapter: CardPaymentRuntimeAdapter< Scope >,
	checkout: CompletedCheckoutEvidence,
	runId: string,
	page: Page,
	account: CardPaymentScenarioEvidence[ 'account' ],
	observation: CheckoutObservation
): Promise< CardPaymentScenarioEvidence > {
	const { adminApi } = session;
	const payment = await getPaymentEvidence( adminApi, checkout.orderId );
	const order = ( await readJson(
		await adminApi.get( `/wp-json/wc/v3/orders/${ checkout.orderId }` ),
		'WooCommerce order'
	) ) as OrderResponse;
	const intent = ( await readJson(
		await adminApi.get(
			`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
				payment.intentId
			) }`
		),
		'provider payment intent'
	) ) as ProviderIntentResponse;
	const providerCustomerPaymentMethods =
		typeof intent.customer === 'string' && intent.customer.trim()
			? await readJson(
					await adminApi.get(
						`/wp-json/wc/v3/payments/customers/${ encodeURIComponent(
							intent.customer
						) }/payment_methods`
					),
					'provider customer payment methods'
			  )
			: undefined;
	const providerCard = definition.protection
		? await adapter.readCardEvidence?.( session, payment )
		: undefined;

	return {
		runId,
		account,
		browser: observation.browser,
		protectionTokens: checkout.protectionTokens,
		checkoutRequests: observation.requests,
		checkoutResponses: observation.responses,
		observerFailures: observation.observerFailures,
		adapterOrderId: checkout.orderId,
		adapterOrderKey: checkout.orderKey,
		orderReceived: checkout.orderReceived,
		order: {
			id: order.id,
			orderKey: order.order_key,
			paymentMethod: order.payment_method,
			customerId: order.customer_id,
		},
		payment,
		providerIntent: {
			id: intent.id,
			paymentMethodId: intent.payment_method,
			customerId: intent.customer,
			nextAction: intent.next_action,
			setupFutureUsage: intent.setup_future_usage,
		},
		providerCustomerPaymentMethods,
		providerCard,
		accessibleSummary: await captureAccessibleSummary( page ),
	};
}

export function registerCardPaymentScenario< Scope >(
	definitionInput: CardPaymentScenarioDefinition,
	adapter: CardPaymentRuntimeAdapter< Scope >
): void {
	const definition = defineCardPaymentScenario( definitionInput );
	const details = {
		annotation: [
			{
				type: 'woopayments-contract',
				description: definition.contractId,
			},
		],
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_PR,
		],
	};
	const runScenario = async ( {
		adminApi,
		page,
		pilotRuntime,
		runId,
	}: {
		adminApi: APIRequestContext;
		page: Page;
		pilotRuntime: ProviderWriteSession;
		runId: string;
	} ): Promise< void > => {
		await adapter.withState( pilotRuntime, runId, async ( scope ) => {
			const accountBody = ( await readJson(
				await adminApi.get( '/wp-json/wc/v3/payments/accounts' ),
				'WooPayments account'
			) ) as AccountResponse;
			const account = {
				cardTestingProtectionEligible:
					accountBody.card_testing_protection_eligible,
			};
			requireStrictEligibility(
				account.cardTestingProtectionEligible,
				definition.protection
			);
			const product = await pilotRuntime.createOwnedProduct(
				definition.price
			);
			const observation = newCheckoutObservation();

			const routeHandler = async ( route: Route ): Promise< void > => {
				const request = route.request();
				try {
					if ( isCheckoutRequest( request ) ) {
						const requestId = `checkout-${
							observation.requests.length + 1
						}`;
						observation.requestIds.set( request, requestId );
						observation.requests.push(
							normalizeCheckoutRequest(
								requestId,
								request.postDataJSON()
							)
						);
						observation.browser =
							await readDispatchBrowserEvidence( page );
					}
				} catch {
					observation.observerFailures.push(
						'checkout-request-capture'
					);
				} finally {
					await route.continue();
				}
			};
			const responseHandler = ( response: Response ): void => {
				if ( ! isCheckoutRequest( response.request() ) ) {
					return;
				}
				const task = captureCheckoutResponse(
					response,
					observation
				).catch( () => {
					observation.observerFailures.push(
						'checkout-response-capture'
					);
				} );
				observation.responseTasks.push( task );
			};

			const observeBlocks = definition.checkout.kind === 'blocks';
			if ( observeBlocks ) {
				await page.route( CHECKOUT_ROUTE, routeHandler );
				page.on( 'response', responseHandler );
			}
			let result: CardPaymentCheckoutResult;
			try {
				result = await adapter.completeCheckout(
					pilotRuntime,
					page,
					product,
					runId,
					definition,
					scope
				);
				if ( observeBlocks ) {
					await Promise.all( observation.responseTasks );
				}
			} finally {
				await Promise.allSettled( observation.responseTasks );
				if ( observeBlocks ) {
					page.off( 'response', responseHandler );
					await page.unroute( CHECKOUT_ROUTE, routeHandler );
				}
			}
			const checkout = await normalizeCompletedCheckout(
				definition,
				result,
				runId,
				page,
				observation
			);

			const evidence = await readScenarioEvidence(
				pilotRuntime,
				definition,
				adapter,
				checkout,
				runId,
				page,
				account,
				observation
			);
			expect( () =>
				validateCardPaymentEvidence( evidence, definition )
			).not.toThrow();
		} );
	};

	if ( definition.protection ) {
		test(
			'Successful purchase › Carding protection true › using a basic card',
			details,
			runScenario
		);
	} else {
		test(
			'Successful purchase › Carding protection false › using a basic card',
			details,
			runScenario
		);
	}
}
