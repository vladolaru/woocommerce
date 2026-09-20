import type { APIResponse, Page, Request } from '@playwright/test';

import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { waitForPaymentState } from '../provider-evidence';
import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import { getPaymentEvidence, type PaymentEvidence } from '../record-evidence';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { ProviderTestCard } from '../test-cards';
import { readHighestOrderId } from './classic-card-authentication';
import {
	CLASSIC_CHECKOUT_PATH,
	PlaywrightClassicCardCheckoutBrowser,
	type ClassicCheckoutRecoveryState,
	type ClassicCheckoutRejectionNotice,
} from './classic-card-checkout';
import type { ClassicCheckoutTarget } from './classic-checkout-page';
import { enterProviderCardTriple } from './card-entry';
import {
	fillBlocksCheckoutAddress,
	getBlocksCardFrameSelector,
	submitBlocksCheckout,
} from './checkout';
import {
	convergeFailedPayment,
	readOrderIdStatusDelta,
	readPaymentReuseEvidence,
	type FailedPaymentEvidence,
	type PaymentReuseEvidence,
} from './failed-payment-evidence';
import { readShopperCartState } from './redirect-methods';

const FAILED_STATUS = 'requires_payment_method';
const SUCCEEDED_STATUS = 'succeeded';
const UNPAID_ORDER_STATUSES = [ 'pending', 'failed' ];
const SUCCESSFUL_ORDER_STATUSES = [ 'processing', 'completed' ];
const RESPONSE_TIMEOUT_MS = 60_000;
const NOTICE_TIMEOUT_MS = 30_000;
const CONVERGENCE_TIMEOUT_MS = 45_000;
const POLL_INTERVAL_MS = 500;
const CLASSIC_CARD_FRAME =
	'#payment .payment_method_woocommerce_payments .wcpay-upe-element iframe, ' +
	'#payment .payment_method_woocommerce_payments #wcpay-core-payment-element iframe[name^="__privateStripeFrame"]';
const ASSERTIVE_ANNOUNCEMENT = '#a11y-speak-assertive';

const GENERIC_DECLINE_CARD: ProviderTestCard = {
	number: '4000000000000002',
	expiry: '0245',
	securityCode: '424',
};
const PROCESSING_ERROR_CARD: ProviderTestCard = {
	number: '4000000000000119',
	expiry: '0245',
	securityCode: '424',
};
const BASIC_CARD: ProviderTestCard = {
	number: '4242424242424242',
	expiry: '0245',
	securityCode: '424',
};

const GENERIC_DECLINE_MESSAGE = 'Error: Your card was declined.';
const PROCESSING_ERROR_MESSAGE =
	'Error: An error occurred while processing your card. Try again in a little bit.';

export type RecoveryTimelineEvent =
	| 'failed-request'
	| 'failed-terminal'
	| 'retry-request'
	| 'retry-terminal';

export interface CardRecoveryAttemptObservation {
	documentMarker: string;
	checkoutRequestCount: number;
	orderId: number;
	orderStatus: string;
	orderAmountMinor: number;
	orderCurrency: string;
	paymentMethodId: string;
	intentId: string;
	intentAmount?: unknown;
	intentCurrency?: unknown;
	amountReceived?: unknown;
	errorCode?: unknown;
	declineCode?: unknown;
	chargeIds: string[];
	chargeIdMeta: string;
	failureNoteCount: number | null;
	terminalStatus: string;
	capturedChargeCount: number;
	chargeStatuses: string[];
	setupFutureUsage: unknown;
	providerCustomerId: string;
	providerAttachedPaymentMethodIds: string[];
	orderCustomerId: number;
	localTokenIds: number[];
}

export interface CardRecoveryCartLine {
	key: string;
	productId: number;
	variation: CardRecoveryCartVariation[];
	quantity: number;
}

export interface CardRecoveryCartVariation {
	rawAttribute: string;
	attribute: string;
	value: string;
}

export interface CardRecoveryCartObservation {
	before: CardRecoveryCartLine[];
	beforeRetry: CardRecoveryCartLine[];
}

export interface ClassicCardFrameIdentity {
	marker: string;
	name: string;
}

export interface ClassicCardFrameObservation {
	first: ClassicCardFrameIdentity;
	retry: ClassicCardFrameIdentity;
	relationship: 'reused' | 'replaced';
}

export interface CardRecoveryShopperFeedback {
	visible: string[];
	announced: string[];
}

export interface CardRecoveryControls {
	paymentEnabled: boolean;
	paymentFocusable: boolean;
	placeOrderEnabled: boolean;
	placeOrderFocusable: boolean;
}

export interface CardRecoveryContinuity {
	sameDocument: boolean;
	sameCart: boolean;
	sameAddress: boolean;
	sameSession: boolean;
}

interface RecoveryObservationBase {
	surface: 'classic' | 'blocks-processing-error' | 'blocks-decline';
	timeline: RecoveryTimelineEvent[];
	orderIds: number[];
	paidOrderIds: number[];
	shopperFeedback: CardRecoveryShopperFeedback;
	controls: CardRecoveryControls;
	continuity: CardRecoveryContinuity;
	cart: CardRecoveryCartObservation;
	unresolvedJournalCount: number;
}

export interface ClassicDeclineRecoveryObservation
	extends RecoveryObservationBase {
	surface: 'classic';
	failedAttempt: CardRecoveryAttemptObservation;
	successfulRetry: CardRecoveryAttemptObservation;
	cardFrame: ClassicCardFrameObservation;
}

export interface BlocksProcessingErrorRecoveryObservation
	extends RecoveryObservationBase {
	surface: 'blocks-processing-error';
	failedAttempt: CardRecoveryAttemptObservation;
	draftOrderId: number;
}

export interface BlocksDeclineRecoveryObservation
	extends RecoveryObservationBase {
	surface: 'blocks-decline';
	failedAttempt: CardRecoveryAttemptObservation;
	successfulRetry: CardRecoveryAttemptObservation;
	draftOrderId: number;
}

interface ClassicRecoveryInput {
	session: ProviderWriteSession;
	page: Page;
	product: OwnedProduct;
	checkout: Pick< ClassicCheckoutTarget, 'pageId' >;
}

interface BlocksRecoveryInput {
	session: ProviderWriteSession;
	page: Page;
	product: OwnedProduct;
}

export interface CardRecoveryDependencies {
	executeClassicDeclineRecovery: (
		input: ClassicRecoveryInput
	) => Promise< ClassicDeclineRecoveryObservation >;
	executeBlocksProcessingErrorRecovery: (
		input: BlocksRecoveryInput
	) => Promise< BlocksProcessingErrorRecoveryObservation >;
	executeBlocksDeclineRecovery: (
		input: BlocksRecoveryInput
	) => Promise< BlocksDeclineRecoveryObservation >;
}

interface BrowserSnapshot {
	documentMarker: string;
	cartSummary: string;
	cartLines: CardRecoveryCartLine[];
	address: string;
	session: string;
}

function fail( message: string ): never {
	throw new Error( `Card recovery ${ message }` );
}

function quarantine( message: string, cause?: unknown ): never {
	throw new ResourceQuarantineRequiredError(
		`WooPayments card recovery ${ message }`,
		'uncertain-provider-write',
		cause instanceof Error ? cause : undefined
	);
}

function sameValues( left: unknown, right: unknown ): boolean {
	return JSON.stringify( left ) === JSON.stringify( right );
}

function validateJournal( observation: RecoveryObservationBase ): void {
	if ( observation.unresolvedJournalCount !== 0 ) {
		fail( 'requires no unresolved provider-write journal.' );
	}
}

function validateAttempt(
	attempt: CardRecoveryAttemptObservation,
	status: typeof FAILED_STATUS | typeof SUCCEEDED_STATUS,
	label: string
): void {
	if ( attempt.checkoutRequestCount !== 1 ) {
		fail( `${ label } requires exactly one checkout request.` );
	}
	if ( ! Number.isSafeInteger( attempt.orderId ) || attempt.orderId <= 0 ) {
		fail( `${ label } requires one positive order ID.` );
	}
	if ( ! attempt.paymentMethodId || ! attempt.intentId ) {
		fail( `${ label } requires exact PaymentMethod and intent IDs.` );
	}
	if ( attempt.terminalStatus !== status ) {
		fail( `${ label } must be terminal in ${ status }.` );
	}
	if (
		status === FAILED_STATUS &&
		( attempt.capturedChargeCount !== 0 ||
			attempt.chargeStatuses.includes( 'succeeded' ) )
	) {
		fail( `${ label } requires no successful or captured charge.` );
	}
	if (
		attempt.setupFutureUsage !== null ||
		attempt.providerAttachedPaymentMethodIds.length !== 0 ||
		attempt.localTokenIds.length !== 0
	) {
		fail( `${ label } requires no reusable token or provider attachment.` );
	}
	if (
		! Number.isSafeInteger( attempt.orderCustomerId ) ||
		attempt.orderCustomerId < 0
	) {
		fail( `${ label } requires an exact order customer ID.` );
	}
}

export function validateGenericDeclineFailedAttempt(
	attempt: CardRecoveryAttemptObservation
): CardRecoveryAttemptObservation {
	validateAttempt( attempt, FAILED_STATUS, 'failed attempt' );
	validateFailedGraph(
		attempt,
		1001,
		'card_declined',
		'generic_decline',
		'failed attempt exact 1001 usd generic-decline graph'
	);
	return attempt;
}

function validateFailedGraph(
	attempt: CardRecoveryAttemptObservation,
	expectedAmount: number,
	expectedErrorCode: string,
	expectedDeclineCode: string,
	label: string
): void {
	if (
		! UNPAID_ORDER_STATUSES.includes( attempt.orderStatus ) ||
		attempt.orderAmountMinor !== expectedAmount ||
		attempt.orderCurrency !== 'USD' ||
		attempt.intentAmount !== expectedAmount ||
		attempt.intentCurrency !== 'usd' ||
		attempt.amountReceived !== 0 ||
		attempt.errorCode !== expectedErrorCode ||
		attempt.declineCode !== expectedDeclineCode
	) {
		fail( `${ label } requires the exact failed payment graph.` );
	}
	if ( attempt.chargeIdMeta !== '' ) {
		fail( `${ label } requires no local charge identity.` );
	}
	if (
		typeof attempt.failureNoteCount !== 'number' ||
		! Number.isSafeInteger( attempt.failureNoteCount ) ||
		attempt.failureNoteCount < 0 ||
		attempt.failureNoteCount > 1
	) {
		fail( `${ label } requires at most one local failure effect.` );
	}
}

function validateSuccessfulGraph(
	attempt: CardRecoveryAttemptObservation
): void {
	if (
		! SUCCESSFUL_ORDER_STATUSES.includes( attempt.orderStatus ) ||
		attempt.orderAmountMinor !== 1001 ||
		attempt.orderCurrency !== 'USD'
	) {
		fail( 'successful retry requires the exact 1001 USD paid graph.' );
	}
}

function validateOrderGraph( observation: RecoveryObservationBase ): void {
	if (
		new Set( observation.orderIds ).size !== observation.orderIds.length
	) {
		fail( 'requires distinct observed order IDs.' );
	}
	if (
		observation.paidOrderIds.some(
			( orderId ) => ! observation.orderIds.includes( orderId )
		)
	) {
		fail( 'paid order must belong to the observed order set.' );
	}
}

function validateFeedback( observation: RecoveryObservationBase ): void {
	const expected =
		observation.surface === 'blocks-processing-error'
			? PROCESSING_ERROR_MESSAGE
			: GENERIC_DECLINE_MESSAGE;
	if (
		! sameValues( observation.shopperFeedback.visible, [ expected ] ) ||
		! sameValues( observation.shopperFeedback.announced, [ expected ] )
	) {
		fail( 'requires exact visible and announced feedback.' );
	}
}

function validCartLines( lines: CardRecoveryCartLine[] ): boolean {
	return (
		lines.length > 0 &&
		lines.every(
			( line ) =>
				line.key !== '' &&
				Number.isSafeInteger( line.productId ) &&
				line.productId > 0 &&
				line.variation.every(
					( attribute ) =>
						attribute.rawAttribute !== '' &&
						attribute.attribute !== '' &&
						attribute.value !== ''
				) &&
				Number.isSafeInteger( line.quantity ) &&
				line.quantity > 0
		)
	);
}

function validateCart( observation: RecoveryObservationBase ): void {
	if (
		! validCartLines( observation.cart.before ) ||
		! validCartLines( observation.cart.beforeRetry ) ||
		! sameValues( observation.cart.before, observation.cart.beforeRetry )
	) {
		fail( 'requires exact cart line identity and quantity continuity.' );
	}
}

function validateContinuity( observation: RecoveryObservationBase ): void {
	validateFeedback( observation );
	validateCart( observation );
	if (
		Object.values( observation.continuity ).some( ( value ) => ! value )
	) {
		fail( 'requires the same document, cart, address, and session.' );
	}
	if ( Object.values( observation.controls ).some( ( value ) => ! value ) ) {
		fail(
			'requires enabled and focusable payment and Place order controls.'
		);
	}
}

function validateRetryGraph(
	observation:
		| ClassicDeclineRecoveryObservation
		| BlocksDeclineRecoveryObservation
): void {
	validateJournal( observation );
	validateOrderGraph( observation );
	validateAttempt(
		observation.failedAttempt,
		FAILED_STATUS,
		'failed attempt'
	);
	validateAttempt(
		observation.successfulRetry,
		SUCCEEDED_STATUS,
		'successful retry'
	);
	validateFailedGraph(
		observation.failedAttempt,
		1001,
		'card_declined',
		'generic_decline',
		'failed attempt exact 1001 usd generic-decline graph'
	);
	validateSuccessfulGraph( observation.successfulRetry );
	if (
		! sameValues( observation.timeline, [
			'failed-request',
			'failed-terminal',
			'retry-request',
			'retry-terminal',
		] )
	) {
		fail(
			'requires the failed intent converges before the retry request.'
		);
	}
	if (
		observation.failedAttempt.paymentMethodId ===
		observation.successfulRetry.paymentMethodId
	) {
		fail( 'requires distinct PaymentMethod IDs for the two attempts.' );
	}
	if (
		observation.failedAttempt.intentId ===
		observation.successfulRetry.intentId
	) {
		fail( 'requires distinct intent IDs for the two attempts.' );
	}
	const captured =
		observation.failedAttempt.capturedChargeCount +
		observation.successfulRetry.capturedChargeCount;
	if ( captured !== 1 ) {
		fail( 'requires exactly one captured charge across both attempts.' );
	}
	if (
		observation.successfulRetry.chargeIds.length !== 1 ||
		! sameValues( observation.successfulRetry.chargeStatuses, [
			'succeeded',
		] )
	) {
		fail( 'requires one succeeded retry charge.' );
	}
	if ( observation.paidOrderIds.length !== 1 ) {
		fail( 'requires exactly one paid order.' );
	}
	if (
		observation.paidOrderIds[ 0 ] !== observation.successfulRetry.orderId
	) {
		fail( 'requires the successful retry to own the paid order.' );
	}
	if (
		observation.failedAttempt.capturedChargeCount !== 0 ||
		observation.failedAttempt.chargeStatuses.includes( 'succeeded' )
	) {
		fail( 'requires no successful or captured failed-attempt charge.' );
	}
	validateContinuity( observation );
}

export function validateClassicDeclineRecovery(
	observation: ClassicDeclineRecoveryObservation
): ClassicDeclineRecoveryObservation {
	validateRetryGraph( observation );
	const attemptOrderIds = [
		...new Set( [
			observation.failedAttempt.orderId,
			observation.successfulRetry.orderId,
		] ),
	].toSorted( ( left, right ) => left - right );
	if (
		observation.failedAttempt.documentMarker !==
			observation.successfulRetry.documentMarker ||
		! sameValues(
			[ ...observation.orderIds ].toSorted(
				( left, right ) => left - right
			),
			attemptOrderIds
		)
	) {
		fail(
			'classic recovery requires one document and only its one or two attempt orders.'
		);
	}
	const { first, retry, relationship } = observation.cardFrame;
	const observedRelationship =
		first.marker === retry.marker ? 'reused' : 'replaced';
	if (
		! first.marker ||
		! first.name ||
		! retry.marker ||
		! retry.name ||
		relationship !== observedRelationship ||
		( relationship === 'reused' && first.name !== retry.name )
	) {
		fail(
			'classic recovery requires exact first and retry card-frame observations with a consistent relationship.'
		);
	}
	return observation;
}

export function validateBlocksProcessingErrorRecovery(
	observation: BlocksProcessingErrorRecoveryObservation
): BlocksProcessingErrorRecoveryObservation {
	validateJournal( observation );
	validateOrderGraph( observation );
	validateAttempt(
		observation.failedAttempt,
		FAILED_STATUS,
		'failed attempt'
	);
	validateFailedGraph(
		observation.failedAttempt,
		1005,
		'processing_error',
		'processing_error',
		'failed attempt exact 1005 usd processing-error graph'
	);
	if (
		! sameValues( observation.timeline, [
			'failed-request',
			'failed-terminal',
		] )
	) {
		fail( 'processing-error recovery forbids an automatic retry.' );
	}
	if ( observation.failedAttempt.orderId !== observation.draftOrderId ) {
		fail( 'processing-error recovery requires the failed draft order.' );
	}
	if (
		observation.failedAttempt.capturedChargeCount !== 0 ||
		observation.failedAttempt.chargeStatuses.includes( 'succeeded' ) ||
		observation.paidOrderIds.length !== 0
	) {
		fail(
			'processing-error recovery requires no successful or captured charge and no paid order.'
		);
	}
	if ( ! sameValues( observation.orderIds, [ observation.draftOrderId ] ) ) {
		fail(
			'processing-error recovery requires exactly the one failed draft order.'
		);
	}
	validateContinuity( observation );
	return observation;
}

export function validateBlocksDeclineRecovery(
	observation: BlocksDeclineRecoveryObservation
): BlocksDeclineRecoveryObservation {
	validateRetryGraph( observation );
	if (
		observation.failedAttempt.documentMarker !==
			observation.successfulRetry.documentMarker ||
		observation.failedAttempt.orderId !== observation.draftOrderId ||
		observation.successfulRetry.orderId !== observation.draftOrderId ||
		! sameValues( observation.orderIds, [ observation.draftOrderId ] ) ||
		! sameValues( observation.paidOrderIds, [ observation.draftOrderId ] )
	) {
		fail(
			'Blocks recovery requires the retry to pay the same draft order.'
		);
	}
	return observation;
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

function recordValue(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		throw new Error( `Card recovery requires one ${ label } object.` );
	}
	return value as Record< string, unknown >;
}

function exactString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || value === '' ) {
		throw new Error( `Card recovery requires a non-empty ${ label }.` );
	}
	return value;
}

function nonNegativeInteger( value: unknown, label: string ): number {
	if ( ! Number.isSafeInteger( value ) || Number( value ) < 0 ) {
		throw new Error(
			`Card recovery requires a non-negative integer ${ label }.`
		);
	}
	return Number( value );
}

export async function readCartLines(
	page: Pick< Page, 'request' >
): Promise< CardRecoveryCartLine[] > {
	const cart = recordValue(
		await readJson(
			await page.request.get( '/wp-json/wc/store/v1/cart' ),
			'Store API cart'
		),
		'Store API cart'
	);
	if ( ! Array.isArray( cart.items ) ) {
		throw new Error(
			'Card recovery requires a Store API cart item array.'
		);
	}
	return cart.items
		.map( ( value, index ) => {
			const item = recordValue( value, `cart item ${ index + 1 }` );
			if ( ! Array.isArray( item.variation ) ) {
				throw new Error(
					`Card recovery requires a Store API variation array for cart item ${
						index + 1
					}.`
				);
			}
			return {
				key: exactString( item.key, `cart item ${ index + 1 } key` ),
				productId: nonNegativeInteger(
					item.id,
					`cart item ${ index + 1 } product ID`
				),
				variation: item.variation
					.map( ( variation, variationIndex ) => {
						const attribute = recordValue(
							variation,
							`cart item ${ index + 1 } variation ${
								variationIndex + 1
							}`
						);
						return {
							rawAttribute: exactString(
								attribute.raw_attribute,
								`cart item ${ index + 1 } variation ${
									variationIndex + 1
								} raw attribute`
							),
							attribute: exactString(
								attribute.attribute,
								`cart item ${ index + 1 } variation ${
									variationIndex + 1
								} attribute`
							),
							value: exactString(
								attribute.value,
								`cart item ${ index + 1 } variation ${
									variationIndex + 1
								} value`
							),
						};
					} )
					.toSorted( ( left, right ) =>
						left.rawAttribute.localeCompare( right.rawAttribute )
					),
				quantity: nonNegativeInteger(
					item.quantity,
					`cart item ${ index + 1 } quantity`
				),
			};
		} )
		.toSorted( ( left, right ) => left.key.localeCompare( right.key ) );
}

async function readClassicCardFrameIdentity(
	page: Page
): Promise< ClassicCardFrameIdentity > {
	const visible = page
		.locator( CLASSIC_CARD_FRAME )
		.filter( { visible: true } );
	const visibleCount = await visible.count();
	if ( visibleCount !== 1 ) {
		throw new Error(
			`Card recovery requires exactly one visible Classic card frame, found ${ visibleCount }.`
		);
	}
	return visible.evaluate( ( element ) => {
		const frame = element as HTMLIFrameElement & {
			dataset: DOMStringMap & { wooRecoveryFrameMarker?: string };
		};
		frame.dataset.wooRecoveryFrameMarker ??= crypto.randomUUID();
		return {
			marker: frame.dataset.wooRecoveryFrameMarker,
			name: frame.name,
		};
	} );
}

async function waitForSuccessfulEvidence(
	session: ProviderWriteSession,
	orderId: number
): Promise< PaymentEvidence > {
	const deadline = Date.now() + CONVERGENCE_TIMEOUT_MS;
	let initial: PaymentEvidence | undefined;
	for (;;) {
		try {
			initial = await getPaymentEvidence( session.adminApi, orderId );
			break;
		} catch ( error ) {
			if ( Date.now() >= deadline ) {
				throw error;
			}
			await new Promise( ( resolve ) =>
				setTimeout( resolve, POLL_INTERVAL_MS )
			);
		}
	}
	return waitForPaymentState(
		session.adminApi,
		initial,
		SUCCEEDED_STATUS,
		deadline
	);
}

async function documentMarker( page: Page ): Promise< string > {
	return page.evaluate( () => {
		const recoveryWindow = window as Window & {
			wooPaymentsRecoveryDocumentMarker?: string;
		};
		recoveryWindow.wooPaymentsRecoveryDocumentMarker ??=
			crypto.randomUUID();
		return recoveryWindow.wooPaymentsRecoveryDocumentMarker;
	} );
}

async function browserSnapshot( page: Page ): Promise< BrowserSnapshot > {
	const cartSummary = await readShopperCartState( page );
	const cartLines = await readCartLines( page );
	const address = await page
		.locator(
			'form.checkout input, form.checkout select, .wc-block-checkout input, .wc-block-checkout select'
		)
		.evaluateAll( ( controls ) =>
			controls
				.map( ( control ) => {
					const field = control as
						| HTMLInputElement
						| HTMLSelectElement;
					return [ field.name || field.id, field.value ];
				} )
				.filter( ( [ name ] ) =>
					/billing|shipping|email|phone/i.test( name )
				)
				.toSorted( ( left, right ) =>
					left[ 0 ].localeCompare( right[ 0 ] )
				)
		);
	const cookies = await page.context().cookies();
	const sessionCookie = cookies.find( ( cookie ) =>
		cookie.name.startsWith( 'wp_woocommerce_session_' )
	);
	return {
		documentMarker: await documentMarker( page ),
		cartSummary: JSON.stringify( cartSummary ),
		cartLines,
		address: JSON.stringify( address ),
		session: sessionCookie?.value ?? '',
	};
}

function continuity(
	before: BrowserSnapshot,
	after: BrowserSnapshot
): CardRecoveryContinuity {
	return {
		sameDocument: before.documentMarker === after.documentMarker,
		sameCart:
			before.cartSummary === after.cartSummary &&
			sameValues( before.cartLines, after.cartLines ),
		sameAddress: before.address === after.address,
		sameSession: before.session !== '' && before.session === after.session,
	};
}

async function focusable( locator: ReturnType< Page[ 'locator' ] > ) {
	if (
		( await locator.count() ) !== 1 ||
		! ( await locator.isVisible() ) ||
		! ( await locator.isEnabled() )
	) {
		return { enabled: false, focusable: false };
	}
	await locator.focus();
	return {
		enabled: true,
		focusable: await locator.evaluate(
			( element ) => element.ownerDocument.activeElement === element
		),
	};
}

async function readControls(
	page: Page,
	cardFrameSelector: string
): Promise< CardRecoveryControls > {
	const payment = await focusable(
		page
			.frameLocator( cardFrameSelector )
			.locator( '[name="number"], [name="cardnumber"]' )
			.first()
	);
	const placeOrder = await focusable(
		page.getByRole( 'button', { name: /place order/i } )
	);
	return {
		paymentEnabled: payment.enabled,
		paymentFocusable: payment.focusable,
		placeOrderEnabled: placeOrder.enabled,
		placeOrderFocusable: placeOrder.focusable,
	};
}

export async function runReconciledProviderAttempt< Result, Evidence >(
	session: Pick< ProviderWriteSession, 'withProviderSubmissionJournal' >,
	description: string,
	dispatch: () => Promise< Result >,
	reconcile: ( result: Result ) => Promise< Evidence >
): Promise< Evidence > {
	return session.withProviderSubmissionJournal( description, async () =>
		reconcile( await dispatch() )
	);
}

export async function runFailedCheckoutAttemptBeforeContinuation<
	Attempt,
	Result,
>(
	session: Pick< ProviderWriteSession, 'withProviderSubmissionJournal' >,
	description: string,
	label: string,
	observeAttempt: () => Promise< {
		result: Attempt;
		checkoutRequestCount: number;
	} >,
	continueAfterFailure: ( attempt: {
		result: Attempt;
		checkoutRequestCount: number;
	} ) => Promise< Result >
): Promise< Result > {
	const attempt = await session.withProviderSubmissionJournal(
		description,
		async () => {
			const observed = await observeAttempt();
			if ( observed.checkoutRequestCount !== 1 ) {
				quarantine(
					`${ label } observed ${ observed.checkoutRequestCount } checkout requests before failed outcome convergence.`
				);
			}
			return observed;
		}
	);
	return continueAfterFailure( attempt );
}

export function throwProviderAttemptFailure(
	error: unknown,
	observation: { clickAttempted: boolean; checkoutRequestCount: number },
	label: string
): never {
	if (
		! observation.clickAttempted &&
		observation.checkoutRequestCount === 0
	) {
		throw new ProviderSubmissionNotStartedError(
			`${ label } did not dispatch.`,
			{ cause: error }
		);
	}
	quarantine(
		`${ label } outcome is ambiguous after the click was attempted.`,
		error
	);
}

function oneNewOrder( orderIds: number[], label: string ): number {
	if ( orderIds.length !== 1 ) {
		fail(
			`${ label } requires exactly one new order, received ${ orderIds.length }.`
		);
	}
	return orderIds[ 0 ];
}

function successfulAttempt(
	evidence: PaymentEvidence,
	reuse: PaymentReuseEvidence,
	document: string,
	checkoutRequestCount: number
): CardRecoveryAttemptObservation {
	return {
		documentMarker: document,
		checkoutRequestCount,
		orderId: evidence.orderId,
		orderStatus: evidence.orderStatus,
		orderAmountMinor: evidence.amountMinor,
		orderCurrency: evidence.currency,
		paymentMethodId: evidence.paymentMethodId,
		intentId: evidence.intentId,
		chargeIds: [ evidence.chargeId ],
		chargeIdMeta: evidence.chargeId,
		failureNoteCount: null,
		terminalStatus: evidence.providerStatus,
		capturedChargeCount: evidence.captureOccurrenceCount,
		chargeStatuses: [ evidence.chargeStatus ],
		...reuse,
	};
}

function orderAmountMinor( total: string ): number {
	const match = /^(\d+)\.(\d{2})$/.exec( total );
	if ( ! match ) {
		throw new Error(
			`Card recovery requires a two-decimal order total, received ${ total }.`
		);
	}
	const amount = Number( match[ 1 ] ) * 100 + Number( match[ 2 ] );
	if ( ! Number.isSafeInteger( amount ) ) {
		throw new Error(
			'Card recovery order total is outside the safe range.'
		);
	}
	return amount;
}

export function failedAttempt(
	evidence: FailedPaymentEvidence,
	orderId: number,
	document: string,
	checkoutRequestCount: number
): CardRecoveryAttemptObservation {
	return {
		documentMarker: document,
		checkoutRequestCount,
		orderId,
		orderStatus: evidence.orderStatus,
		orderAmountMinor: orderAmountMinor( evidence.orderTotal ),
		orderCurrency: evidence.orderCurrency,
		paymentMethodId: evidence.paymentMethodId,
		intentId: evidence.intentId,
		intentAmount: evidence.intentAmount,
		intentCurrency: evidence.intentCurrency,
		amountReceived: evidence.amountReceived,
		errorCode: evidence.errorCode,
		declineCode: evidence.declineCode,
		chargeIds: evidence.chargeIds,
		chargeIdMeta: evidence.chargeIdMeta,
		failureNoteCount: evidence.failureNoteCount,
		terminalStatus: String( evidence.intentStatus ),
		capturedChargeCount: evidence.capturedCharges,
		chargeStatuses: evidence.chargeStatuses,
		setupFutureUsage: evidence.setupFutureUsage,
		providerCustomerId: evidence.providerCustomerId,
		providerAttachedPaymentMethodIds:
			evidence.providerAttachedPaymentMethodIds,
		orderCustomerId: evidence.orderCustomerId,
		localTokenIds: evidence.localTokenIds,
	};
}

async function prepareClassic(
	input: ClassicRecoveryInput,
	card: ProviderTestCard,
	initial: boolean
): Promise< PlaywrightClassicCardCheckoutBrowser > {
	const { session, page, product, checkout } = input;
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		checkout.pageId
	);
	if ( initial ) {
		await browser.preflightClassicPage(
			CLASSIC_CHECKOUT_PATH,
			checkout.pageId
		);
		await browser.addProductOnce( product.id, ( write ) =>
			session.performWrite( write )
		);
		await browser.openClassicCheckout( CLASSIC_CHECKOUT_PATH );
		await browser.fillBillingDetails( session.runId );
	}
	await browser.selectWooPaymentsCard();
	await browser.fillTestCard( card );
	await browser.prepareSubmission();
	return browser;
}

export async function submitClassicFailure< Reconciliation >(
	session: ProviderWriteSession,
	browser: PlaywrightClassicCardCheckoutBrowser,
	timeline: RecoveryTimelineEvent[],
	reconcile: () => Promise< Reconciliation >
): Promise< {
	result: {
		notice: ClassicCheckoutRejectionNotice;
		recovery: ClassicCheckoutRecoveryState;
		reconciliation: Reconciliation;
	};
	checkoutRequestCount: number;
} > {
	let clickAttempted = false;
	try {
		const observed = await browser.observeSubmissionInterval(
			async ( activate ) => {
				clickAttempted = true;
				timeline.push( 'failed-request' );
				await session.performWrite( activate );
			},
			async () => {
				if (
					! ( await browser.waitForCheckoutRejectionNotice(
						NOTICE_TIMEOUT_MS
					) )
				) {
					quarantine(
						'Classic decline dispatched without a rejection notice.'
					);
				}
				return {
					notice: await browser.readCheckoutRejectionNotice(),
					recovery: await browser.readCheckoutRecoveryState(),
					reconciliation: await reconcile(),
				};
			}
		);
		if ( observed.dispatch.responses.length !== 1 ) {
			quarantine(
				`Classic decline observed ${ observed.dispatch.responses.length } checkout responses.`
			);
		}
		return {
			result: observed.result,
			checkoutRequestCount: observed.dispatch.requests.length,
		};
	} catch ( error ) {
		if ( error instanceof ResourceQuarantineRequiredError ) {
			throw error;
		}
		throwProviderAttemptFailure(
			error,
			{ clickAttempted, checkoutRequestCount: 0 },
			'Classic decline'
		);
	}
}

async function submitClassicSuccess(
	session: ProviderWriteSession,
	browser: PlaywrightClassicCardCheckoutBrowser,
	timeline: RecoveryTimelineEvent[]
): Promise< { requestCount: number; orderId: number } > {
	let clickAttempted = false;
	try {
		const observed = await browser.observeSubmissionInterval(
			async ( activate ) => {
				clickAttempted = true;
				timeline.push( 'retry-request' );
				await session.performWrite( activate );
			},
			() => browser.waitForClassicReceipt( RESPONSE_TIMEOUT_MS )
		);
		if (
			observed.dispatch.requests.length !== 1 ||
			observed.dispatch.responses.length !== 1
		) {
			quarantine(
				`Classic retry observed ${ observed.dispatch.requests.length } request(s) and ${ observed.dispatch.responses.length } response(s).`
			);
		}
		return {
			requestCount: observed.dispatch.requests.length,
			orderId: observed.result.orderId,
		};
	} catch ( error ) {
		if ( error instanceof ResourceQuarantineRequiredError ) {
			throw error;
		}
		throwProviderAttemptFailure(
			error,
			{ clickAttempted, checkoutRequestCount: 0 },
			'Classic retry'
		);
	}
}

async function fillBlocksCard(
	session: ProviderWriteSession,
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	await enterProviderCardTriple(
		page.frameLocator( getBlocksCardFrameSelector( session.runtime ) ),
		card,
		'Blocks checkout recovery'
	);
	await page.getByRole( 'button', { name: /place order/i } ).focus();
}

async function setupBlocksCheckout(
	input: BlocksRecoveryInput,
	card: ProviderTestCard
): Promise< void > {
	const { session, page, product } = input;
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutAddress( page, session.runId );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
	await fillBlocksCard( session, page, card );
}

function isStoreCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const url = new URL( request.url() );
		return (
			url.pathname.replace( /\/+$/, '' ) ===
				'/wp-json/wc/store/v1/checkout' ||
			( url.searchParams.get( 'rest_route' ) ?? '' ).replace(
				/\/+$/,
				''
			) === '/wc/store/v1/checkout'
		);
	} catch {
		return false;
	}
}

export async function countStoreCheckoutRequestsUntil< Result >(
	page: Pick< Page, 'on' | 'off' >,
	work: () => Promise< Result >
): Promise< { result: Result; checkoutRequestCount: number } > {
	let checkoutRequestCount = 0;
	const onRequest = ( request: Request ) => {
		if ( isStoreCheckoutRequest( request ) ) {
			checkoutRequestCount += 1;
		}
	};
	page.on( 'request', onRequest );
	try {
		return {
			result: await work(),
			checkoutRequestCount,
		};
	} finally {
		page.off( 'request', onRequest );
	}
}

export async function runJournaledCheckoutAttempt< Result >(
	session: Pick< ProviderWriteSession, 'withProviderSubmissionJournal' >,
	page: Pick< Page, 'on' | 'off' >,
	description: string,
	label: string,
	work: () => Promise< Result >
): Promise< { result: Result; checkoutRequestCount: number } > {
	return session.withProviderSubmissionJournal( description, async () => {
		const observed = await countStoreCheckoutRequestsUntil( page, work );
		if ( observed.checkoutRequestCount !== 1 ) {
			quarantine(
				`${ label } observed ${ observed.checkoutRequestCount } checkout requests before outcome convergence.`
			);
		}
		return observed;
	} );
}

async function submitBlocksAttempt(
	session: ProviderWriteSession,
	page: Page,
	timeline: RecoveryTimelineEvent[],
	event: 'failed-request' | 'retry-request',
	label: string,
	waitForReceipt: boolean
): Promise< { orderId?: number } > {
	let clickAttempted = false;
	const checkoutResponse = page.waitForResponse(
		( response ) => isStoreCheckoutRequest( response.request() ),
		{ timeout: RESPONSE_TIMEOUT_MS }
	);
	try {
		timeline.push( event );
		await submitBlocksCheckout( page, async ( button ) => {
			clickAttempted = true;
			await session.performWrite( () => button.click() );
			return 'dispatched';
		} );
		await checkoutResponse;
		if ( ! waitForReceipt ) {
			return {};
		}
		await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/, {
			timeout: RESPONSE_TIMEOUT_MS,
		} );
		return { orderId: session.getOrderIdFromUrl( page.url() ) };
	} catch ( error ) {
		void checkoutResponse.catch( () => undefined );
		if ( error instanceof ResourceQuarantineRequiredError ) {
			throw error;
		}
		throwProviderAttemptFailure(
			error,
			{ clickAttempted, checkoutRequestCount: 0 },
			label
		);
	}
}

export async function blocksFeedback(
	page: Page,
	expected: string
): Promise< CardRecoveryShopperFeedback > {
	const notices = page.locator(
		'[role="alert"]:visible:not(.wc-block-components-notice-banner), .wc-block-components-notice-banner__list > li:visible'
	);
	await notices
		.filter( { hasText: expected } )
		.first()
		.waitFor( { state: 'visible', timeout: NOTICE_TIMEOUT_MS } );
	await page.waitForFunction(
		( { selector, message } ) =>
			( document.querySelector( selector )?.textContent ?? '' ).includes(
				message
			),
		{ selector: ASSERTIVE_ANNOUNCEMENT, message: expected },
		{ timeout: NOTICE_TIMEOUT_MS }
	);
	return {
		visible: ( await notices.allInnerTexts() ).map( ( message ) =>
			message.replace( /\s+/g, ' ' ).trim()
		),
		announced: [
			(
				( await page.locator( ASSERTIVE_ANNOUNCEMENT ).innerText() ) ??
				''
			)
				.replace( /\s+/g, ' ' )
				.trim(),
		],
	};
}

async function executeClassicDeclineRecovery(
	input: ClassicRecoveryInput
): Promise< ClassicDeclineRecoveryObservation > {
	const { session, page } = input;
	await session.assertCanWrite();
	for ( const capability of [
		'product/payment',
		'card-decline-checkout',
		'classic-checkout-page',
		'basic-card',
		'basic-card-entry',
	] ) {
		session.requireApprovedProviderFixture( capability );
	}
	const baseline = await readHighestOrderId( session );
	const timeline: RecoveryTimelineEvent[] = [];
	const firstBrowser = await prepareClassic(
		input,
		GENERIC_DECLINE_CARD,
		true
	);
	const before = await browserSnapshot( page );
	const firstFrame = await readClassicCardFrameIdentity( page );
	return runFailedCheckoutAttemptBeforeContinuation(
		session,
		'card-recovery-classic-decline',
		'Classic failed attempt',
		() =>
			submitClassicFailure( session, firstBrowser, timeline, async () => {
				const failedDelta = await readOrderIdStatusDelta(
					session,
					baseline
				);
				const orderId = oneNewOrder(
					failedDelta.newOrderIds,
					'Classic failed attempt'
				);
				await session.setOrderRunId( orderId, session.runId );
				const evidence = await convergeFailedPayment(
					session,
					orderId
				);
				timeline.push( 'failed-terminal' );
				return { evidence, orderId };
			} ),
		async ( firstAttempt ) => {
			const afterFailure = await browserSnapshot( page );
			const controls = await readControls( page, CLASSIC_CARD_FRAME );
			const secondBrowser = await prepareClassic(
				input,
				BASIC_CARD,
				false
			);
			const retryDocument = await documentMarker( page );
			const retryFrame = await readClassicCardFrameIdentity( page );
			const retryAttempt = await runReconciledProviderAttempt(
				session,
				'card-recovery-classic-retry',
				() => submitClassicSuccess( session, secondBrowser, timeline ),
				async ( submission ) => {
					await session.setOrderRunId(
						submission.orderId,
						session.runId
					);
					const evidence = await waitForSuccessfulEvidence(
						session,
						submission.orderId
					);
					const reuse = await readPaymentReuseEvidence(
						session,
						submission.orderId,
						evidence.intentId
					);
					timeline.push( 'retry-terminal' );
					const orders = await readOrderIdStatusDelta(
						session,
						baseline
					);
					return { submission, evidence, reuse, orders };
				}
			);
			const failure = firstAttempt.result;
			return {
				surface: 'classic',
				failedAttempt: failedAttempt(
					failure.reconciliation.evidence,
					failure.reconciliation.orderId,
					before.documentMarker,
					firstAttempt.checkoutRequestCount
				),
				successfulRetry: successfulAttempt(
					retryAttempt.evidence,
					retryAttempt.reuse,
					retryDocument,
					retryAttempt.submission.requestCount
				),
				timeline,
				orderIds: retryAttempt.orders.newOrderIds,
				paidOrderIds: retryAttempt.orders.paidOrderIds,
				shopperFeedback: {
					visible: failure.notice.messages,
					announced: failure.notice.messages,
				},
				controls,
				continuity: continuity( before, afterFailure ),
				cart: {
					before: before.cartLines,
					beforeRetry: afterFailure.cartLines,
				},
				cardFrame: {
					first: firstFrame,
					retry: retryFrame,
					relationship:
						firstFrame.marker === retryFrame.marker
							? 'reused'
							: 'replaced',
				},
				unresolvedJournalCount: 0,
			};
		}
	);
}

async function executeBlocksFailure(
	input: BlocksRecoveryInput,
	card: ProviderTestCard,
	message: string,
	retry: boolean
): Promise<
	BlocksProcessingErrorRecoveryObservation | BlocksDeclineRecoveryObservation
> {
	const { session, page } = input;
	await session.assertCanWrite();
	for ( const capability of [
		'product/payment',
		'card-decline-checkout',
		...( retry ? [ 'basic-card', 'basic-card-entry' ] : [] ),
	] ) {
		session.requireApprovedProviderFixture( capability );
	}
	const baseline = await readHighestOrderId( session );
	const timeline: RecoveryTimelineEvent[] = [];
	await setupBlocksCheckout( input, card );
	const before = await browserSnapshot( page );
	return runFailedCheckoutAttemptBeforeContinuation(
		session,
		retry
			? 'card-recovery-blocks-decline'
			: 'card-recovery-blocks-processing-error',
		'Blocks failed attempt',
		() =>
			countStoreCheckoutRequestsUntil( page, async () => {
				const submission = await submitBlocksAttempt(
					session,
					page,
					timeline,
					'failed-request',
					'Blocks failed attempt',
					false
				);
				const failedDelta = await readOrderIdStatusDelta(
					session,
					baseline
				);
				const orderId = oneNewOrder(
					failedDelta.newOrderIds,
					'Blocks failed attempt'
				);
				await session.setOrderRunId( orderId, session.runId );
				const evidence = await convergeFailedPayment(
					session,
					orderId
				);
				timeline.push( 'failed-terminal' );
				const orders = await readOrderIdStatusDelta(
					session,
					baseline
				);
				return { submission, evidence, orderId, orders };
			} ),
		async ( firstAttempt ) => {
			const draftOrderId = firstAttempt.result.orderId;
			const feedback = await blocksFeedback( page, message );
			const afterFailure = await browserSnapshot( page );
			const controls = await readControls(
				page,
				getBlocksCardFrameSelector( session.runtime )
			);
			const shared = {
				failedAttempt: failedAttempt(
					firstAttempt.result.evidence,
					draftOrderId,
					before.documentMarker,
					firstAttempt.checkoutRequestCount
				),
				draftOrderId,
				shopperFeedback: feedback,
				controls,
				continuity: continuity( before, afterFailure ),
				cart: {
					before: before.cartLines,
					beforeRetry: afterFailure.cartLines,
				},
			};
			if ( ! retry ) {
				return {
					surface: 'blocks-processing-error',
					...shared,
					timeline,
					orderIds: firstAttempt.result.orders.newOrderIds,
					paidOrderIds: firstAttempt.result.orders.paidOrderIds,
					unresolvedJournalCount: 0,
				};
			}

			await fillBlocksCard( session, page, BASIC_CARD );
			const retryDocument = await documentMarker( page );
			const retryAttempt = await runJournaledCheckoutAttempt(
				session,
				page,
				'card-recovery-blocks-retry',
				'Blocks retry',
				async () => {
					const submission = await submitBlocksAttempt(
						session,
						page,
						timeline,
						'retry-request',
						'Blocks retry',
						true
					);
					if ( submission.orderId === undefined ) {
						quarantine( 'Blocks retry reached no receipt order.' );
					}
					await session.setOrderRunId(
						submission.orderId,
						session.runId
					);
					const evidence = await waitForSuccessfulEvidence(
						session,
						submission.orderId
					);
					const reuse = await readPaymentReuseEvidence(
						session,
						submission.orderId,
						evidence.intentId
					);
					timeline.push( 'retry-terminal' );
					const orders = await readOrderIdStatusDelta(
						session,
						baseline
					);
					return { submission, evidence, reuse, orders };
				}
			);
			return {
				surface: 'blocks-decline',
				...shared,
				successfulRetry: successfulAttempt(
					retryAttempt.result.evidence,
					retryAttempt.result.reuse,
					retryDocument,
					retryAttempt.checkoutRequestCount
				),
				timeline,
				orderIds: retryAttempt.result.orders.newOrderIds,
				paidOrderIds: retryAttempt.result.orders.paidOrderIds,
				unresolvedJournalCount: 0,
			};
		}
	);
}

const LIVE_DEPENDENCIES: CardRecoveryDependencies = {
	executeClassicDeclineRecovery,
	executeBlocksProcessingErrorRecovery: ( input ) =>
		executeBlocksFailure(
			input,
			PROCESSING_ERROR_CARD,
			PROCESSING_ERROR_MESSAGE,
			false
		) as Promise< BlocksProcessingErrorRecoveryObservation >,
	executeBlocksDeclineRecovery: ( input ) =>
		executeBlocksFailure(
			input,
			GENERIC_DECLINE_CARD,
			GENERIC_DECLINE_MESSAGE,
			true
		) as Promise< BlocksDeclineRecoveryObservation >,
};

export async function runClassicDeclineRecovery(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	checkout: Pick< ClassicCheckoutTarget, 'pageId' >,
	dependencies: CardRecoveryDependencies = LIVE_DEPENDENCIES
): Promise< ClassicDeclineRecoveryObservation > {
	await session.assertCanWrite();
	return validateClassicDeclineRecovery(
		await dependencies.executeClassicDeclineRecovery( {
			session,
			page,
			product,
			checkout,
		} )
	);
}

export async function runBlocksProcessingErrorRecovery(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	dependencies: CardRecoveryDependencies = LIVE_DEPENDENCIES
): Promise< BlocksProcessingErrorRecoveryObservation > {
	await session.assertCanWrite();
	return validateBlocksProcessingErrorRecovery(
		await dependencies.executeBlocksProcessingErrorRecovery( {
			session,
			page,
			product,
		} )
	);
}

export async function runBlocksDeclineRecovery(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	dependencies: CardRecoveryDependencies = LIVE_DEPENDENCIES
): Promise< BlocksDeclineRecoveryObservation > {
	await session.assertCanWrite();
	return validateBlocksDeclineRecovery(
		await dependencies.executeBlocksDeclineRecovery( {
			session,
			page,
			product,
		} )
	);
}
