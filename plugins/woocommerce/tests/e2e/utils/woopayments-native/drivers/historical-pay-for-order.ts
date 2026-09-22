import type { BrowserContext } from '@playwright/test';

import {
	composeCardTestingProtectionEvidence,
	validateCardTestingProtectionEvidence,
	type CardTestingProtectionEvidence,
	type CardTestingProtectionRenderedObservation,
	type CardTestingProtectionSessionEvidence,
	type CardTestingProtectionSubmittedObservation,
} from './card-testing-protection';
import type { FailedPaymentEvidence } from './failed-payment-evidence';

const IMMUTABLE_PLUGIN_VERSION = '11.1.0';
const IMMUTABLE_SOURCE_COMMIT = 'f85392666c9b543cd24dbbf903e0dbe4cb2c5cee';
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const WOOCOMMERCE_SESSION_COOKIE_PREFIX = 'wp_woocommerce_session_';

export async function clearHistoricalWooCommerceSessionCookie(
	context: Pick< BrowserContext, 'cookies' | 'clearCookies' >,
	baseURL: string
): Promise< void > {
	const sessionCookies = ( await context.cookies( baseURL ) ).filter(
		( cookie ) =>
			cookie.name.startsWith( WOOCOMMERCE_SESSION_COOKIE_PREFIX )
	);
	if ( sessionCookies.length !== 1 ) {
		throw new Error(
			'Historical pay-for-order requires exactly one plugin-era WooCommerce session cookie.'
		);
	}
	const [ sessionCookie ] = sessionCookies;
	await context.clearCookies( {
		name: sessionCookie.name,
		domain: sessionCookie.domain,
		path: sessionCookie.path,
	} );
	const remainingSessionCookies = ( await context.cookies( baseURL ) ).filter(
		( cookie ) =>
			cookie.name.startsWith( WOOCOMMERCE_SESSION_COOKIE_PREFIX )
	);
	if ( remainingSessionCookies.length !== 0 ) {
		throw new Error(
			'Historical pay-for-order requires the plugin-era WooCommerce session cookie to be absent before native capture.'
		);
	}
}

export interface HistoricalPayForOrderFixture {
	schemaVersion: 1;
	source: {
		pluginVersion: string;
		sourceCommit: string;
	};
	allocation: {
		runId: string;
		storeId: string;
		blogId: number;
		accountId: string;
	};
	protectionTarget: false | true;
	customerId: number;
	productId: number;
	order: {
		id: number;
		keySha256: string;
		customerId: number;
		currency: 'USD';
		totalMinor: number;
		status: 'failed';
		paymentMethod: 'woocommerce_payments';
		productLines: readonly [ { productId: number; quantity: 1 } ];
		stockReduced: boolean;
		noteCount: number;
		emailCount: number;
	};
	myAccountPayLink: {
		orderId: number;
		orderKeySha256: string;
		customerId: number;
		pathSha256: string;
	};
	clientDecline: {
		intentId: string;
		intentStatus: 'requires_payment_method';
		paymentMethodId: string;
		chargeIds: readonly string[];
		captureCount: number;
		cardLast4: '0002';
	};
	baseline: {
		orderIds: readonly [ number ];
		stockQuantity: number;
		noteCount: number;
		emailCount: number;
	};
	checksumSha256: string;
}

export interface HistoricalPayForOrderEvidence {
	fixtureChecksumSha256: string;
	protection: CardTestingProtectionEvidence;
	order: {
		id: number;
		keySha256: string;
		customerId: number;
		currency: 'USD';
		totalMinor: number;
		status: 'processing' | 'completed';
		paymentMethod: 'woocommerce_payments';
		productLines: readonly [ { productId: number; quantity: 1 } ];
		stockReduced: boolean;
		noteCount: number;
		emailCount: number;
	};
	nativeSuccess: {
		intentId: string;
		intentStatus: 'succeeded';
		paymentMethodId: string;
		charges: readonly {
			id: string;
			status: string;
			captured: boolean;
		}[];
		occurrenceCount: number;
		captureOccurrenceCount: number;
		cardLast4: '4242';
	};
	cardinality: {
		orderIds: readonly number[];
		intentIds: readonly string[];
		paidIntentIds: readonly string[];
		orphanIntentIds: readonly string[];
		stockReductionDelta: number;
		paidNoteDelta: number;
		customerEmailDelta: number;
		listenerSideEffectCount: number;
	};
	listener: {
		quiescent: boolean;
		sideEffectCount: number;
	};
	journals: readonly {
		submission: 'client-decline' | 'native-pay-for-order';
		resolved: boolean;
	}[];
	cleanup: {
		manifest: {
			orderIds: readonly number[];
			intentIds: readonly string[];
			paymentMethodIds: readonly string[];
			chargeIds: readonly string[];
			customerIds: readonly number[];
			productIds: readonly number[];
		};
	};
}

/** Browser-stage evidence; runner artifacts later add cardinality, listener, and journal receipts. */
export interface HistoricalPayForOrderBrowserEvidence {
	fixtureChecksumSha256: string;
	protection: CardTestingProtectionEvidence;
	order: {
		id: number;
		keySha256: string;
		customerId: number;
		currency: 'USD';
		totalMinor: number;
		status: 'processing' | 'completed';
		noteCount: number;
	};
	nativeSuccess: {
		intentId: string;
		paymentMethodId: string;
		chargeId: string;
		chargeStatus: string;
		chargeCaptured: boolean;
		occurrenceCount: number;
		captureOccurrenceCount: number;
	};
	cleanup: HistoricalPayForOrderEvidence[ 'cleanup' ];
}

export interface HistoricalPayForOrderRunnerReceipt {
	graph: Pick< HistoricalPayForOrderEvidence[ 'cardinality' ], 'orderIds' | 'intentIds' | 'paidIntentIds' | 'orphanIntentIds' >;
	listener: {
		quiescent: boolean;
		sideEffects: readonly string[];
	};
	journals: HistoricalPayForOrderEvidence[ 'journals' ];
	order: Pick< HistoricalPayForOrderEvidence[ 'order' ], 'paymentMethod' | 'productLines' >;
	nativeSuccess: Pick< HistoricalPayForOrderEvidence[ 'nativeSuccess' ], 'intentStatus' | 'cardLast4' >;
	stock: {
		before: number;
		after: number;
	};
	notes: {
		before: number;
		after: number;
	};
	emails: {
		before: number;
		after: number;
	};
}

/** Adds runner receipts to browser observations before the strict final validator runs. */
export function composeHistoricalPayForOrderEvidence(
	fixture: HistoricalPayForOrderFixture,
	browser: HistoricalPayForOrderBrowserEvidence,
	receipt: HistoricalPayForOrderRunnerReceipt
): HistoricalPayForOrderEvidence {
	if (
		! receipt.stock ||
		! receipt.notes ||
		! receipt.emails ||
		! Number.isSafeInteger( receipt.stock.before ) ||
		! Number.isSafeInteger( receipt.stock.after ) ||
		! Number.isSafeInteger( receipt.notes.before ) ||
		! Number.isSafeInteger( receipt.notes.after ) ||
		! Number.isSafeInteger( receipt.emails.before ) ||
		! Number.isSafeInteger( receipt.emails.after )
	) {
		fail( 'Task 4 receipt requires observed stock, note, and email counts.' );
	}
	if ( receipt.stock.before !== 1 || receipt.stock.after !== 0 ) {
		fail( 'Task 4 receipt requires the observed managed-stock transition from 1 to 0.' );
	}
	if (
		receipt.stock.before !== fixture.baseline.stockQuantity ||
		receipt.notes.before !== fixture.baseline.noteCount ||
		receipt.emails.before !== fixture.baseline.emailCount
	) {
		fail( 'Task 4 receipt does not match the immutable observed baseline.' );
	}
	return validateHistoricalPayForOrderRecovery( fixture, {
		fixtureChecksumSha256: browser.fixtureChecksumSha256,
		protection: browser.protection,
		order: {
			id: browser.order.id,
			keySha256: browser.order.keySha256,
			customerId: browser.order.customerId,
			currency: browser.order.currency,
			totalMinor: browser.order.totalMinor,
			status: browser.order.status,
			paymentMethod: receipt.order.paymentMethod,
			productLines: receipt.order.productLines,
			stockReduced: receipt.stock.after < receipt.stock.before,
			noteCount: browser.order.noteCount,
			emailCount: receipt.emails.after,
		},
		nativeSuccess: {
			intentId: browser.nativeSuccess.intentId,
			paymentMethodId: browser.nativeSuccess.paymentMethodId,
			intentStatus: receipt.nativeSuccess.intentStatus,
			cardLast4: receipt.nativeSuccess.cardLast4,
			charges: [ { id: browser.nativeSuccess.chargeId, status: browser.nativeSuccess.chargeStatus, captured: browser.nativeSuccess.chargeCaptured } ],
			occurrenceCount: browser.nativeSuccess.occurrenceCount,
			captureOccurrenceCount: browser.nativeSuccess.captureOccurrenceCount,
		},
		cardinality: {
			...receipt.graph,
			stockReductionDelta: receipt.stock.before - receipt.stock.after,
			paidNoteDelta: receipt.notes.after - receipt.notes.before,
			customerEmailDelta: receipt.emails.after - receipt.emails.before,
			listenerSideEffectCount: receipt.listener.sideEffects.length,
		},
		listener: {
			quiescent: receipt.listener.quiescent,
			sideEffectCount: receipt.listener.sideEffects.length,
		},
		journals: receipt.journals,
		cleanup: browser.cleanup,
	} );
}

export function validateHistoricalOrderPayTotal( total: string ): void {
	if ( total !== '$10.01' ) {
		fail( 'Historical pay-for-order requires the exact Total: $10.01 cell.' );
	}
}

export function validateHistoricalOrderPayRoute(
	route: string,
	expected: { paymentUrl: string; orderId: number; orderKey: string }
): void {
	const actual = new URL( route );
	const paymentUrl = new URL( expected.paymentUrl );
	const expectedPath = paymentUrl.pathname;
	if (
		! expectedPath.endsWith( `/order-pay/${ expected.orderId }/` ) ||
		actual.origin !== paymentUrl.origin ||
		actual.pathname !== expectedPath ||
		actual.searchParams.getAll( 'key' ).length !== 1 ||
		actual.searchParams.get( 'key' ) !== expected.orderKey ||
		actual.searchParams.getAll( 'pay_for_order' ).length !== 1 ||
		! [ 'true', '1' ].includes( actual.searchParams.get( 'pay_for_order' ) ?? '' )
	) {
		throw new Error( 'Historical pay-for-order requires the exact rendered order-pay route.' );
	}
}

interface HistoricalPayForOrderPreCutoverDependencies {
	withTransitionProviderLock: < Result >(
		callback: () => Promise< Result >
	) => Promise< Result >;
	readFailedFixture: () => Promise< HistoricalPayForOrderFixture >;
	readFailedPayment: () => Promise< FailedPaymentEvidence >;
	cutOver: () => Promise< { runtimeOwner: 'native' } >;
}

interface HistoricalPayForOrderPostCutoverDependencies {
	withCardTestingProtectionLock: < Result >(
		callback: () => Promise< Result >
	) => Promise< Result >;
	captureProtection: () => Promise< CardTestingProtectionSessionEvidence >;
	observeRenderedProtection: () => Promise< CardTestingProtectionRenderedObservation >;
	submitPayForOrder: () => Promise< {
		requestCount: number;
		submittedProtection: CardTestingProtectionSubmittedObservation;
	} >;
	waitForListenerQuiescence: () => Promise< void >;
	readColdRecoveryEvidence: () => Promise< HistoricalPayForOrderBrowserEvidence >;
}

export interface HistoricalPayForOrderRecoveryDependencies {
	preCutover: HistoricalPayForOrderPreCutoverDependencies;
	postCutover: HistoricalPayForOrderPostCutoverDependencies;
}

function validateRecordedClientDecline(
	fixture: HistoricalPayForOrderFixture,
	decline: FailedPaymentEvidence
): void {
	const expectedTotal = `${ Math.floor( fixture.order.totalMinor / 100 ) }.${ String(
		fixture.order.totalMinor % 100
	).padStart( 2, '0' ) }`;
	if ( decline.orderStatus !== fixture.order.status ) {
		fail( 'requires the immutable failed-order status.' );
	}
	if ( decline.orderTotal !== expectedTotal ) {
		fail( 'requires the immutable failed-order total.' );
	}
	if (
		decline.intentId !== fixture.clientDecline.intentId ||
		decline.intentStatus !== fixture.clientDecline.intentStatus ||
		decline.paymentMethodId !== fixture.clientDecline.paymentMethodId ||
		JSON.stringify( decline.chargeIds ) !==
			JSON.stringify( fixture.clientDecline.chargeIds ) ||
		decline.chargeStatuses.length !== decline.chargeIds.length ||
		decline.chargeStatuses.some( ( status ) => status !== 'failed' ) ||
		decline.capturedCharges !== 0 ||
		decline.chargeIdMeta !== '' ||
		( decline.amountReceived !== null && decline.amountReceived !== 0 ) ||
		decline.failureNoteCount < 1
	) {
		fail(
			'requires a human-visible terminal decline with one failed uncaptured charge and no money movement.'
		);
	}
	if ( decline.orderCustomerId !== fixture.customerId ) {
		fail( 'requires the failed order customer linkage.' );
	}
}

function fail( message: string ): never {
	throw new Error( `Historical pay-for-order ${ message }` );
}

function isPositiveInteger( value: unknown ): value is number {
	return Number.isSafeInteger( value ) && Number( value ) > 0;
}

function hasValue( value: unknown ): value is string {
	return typeof value === 'string' && value !== '';
}

function sameValues( left: unknown, right: unknown ): boolean {
	return JSON.stringify( left ) === JSON.stringify( right );
}

function validateImmutableFixture( fixture: HistoricalPayForOrderFixture ): void {
	if (
		fixture.schemaVersion !== 1 ||
		fixture.source.pluginVersion !== IMMUTABLE_PLUGIN_VERSION ||
		fixture.source.sourceCommit !== IMMUTABLE_SOURCE_COMMIT
	) {
		fail( 'requires the exact immutable 11.1.0 source.' );
	}
	if (
		! hasValue( fixture.allocation.runId ) ||
		! hasValue( fixture.allocation.storeId ) ||
		! hasValue( fixture.allocation.accountId ) ||
		! isPositiveInteger( fixture.allocation.blogId ) ||
		! isPositiveInteger( fixture.customerId ) ||
		! isPositiveInteger( fixture.productId ) ||
		! SHA256_PATTERN.test( fixture.checksumSha256 )
	) {
		fail( 'requires exact allocated fixture identity.' );
	}
	if ( fixture.order.totalMinor !== 1001 ) {
		fail( 'requires the exact 1001 USD failed-order total.' );
	}
	if (
		! isPositiveInteger( fixture.order.id ) ||
		fixture.order.customerId !== fixture.customerId ||
		fixture.order.currency !== 'USD' ||
		! isPositiveInteger( fixture.order.totalMinor ) ||
		fixture.order.status !== 'failed' ||
		fixture.order.paymentMethod !== 'woocommerce_payments' ||
		! SHA256_PATTERN.test( fixture.order.keySha256 ) ||
		! sameValues( fixture.order.productLines, [
			{ productId: fixture.productId, quantity: 1 },
		] ) ||
		fixture.order.stockReduced ||
		! isPositiveInteger( fixture.order.noteCount ) ||
		fixture.order.emailCount !== 0 ||
		! sameValues( fixture.baseline.orderIds, [ fixture.order.id ] ) ||
		fixture.baseline.stockQuantity < 1 ||
		fixture.baseline.noteCount !== fixture.order.noteCount ||
		fixture.baseline.emailCount !== fixture.order.emailCount
	) {
		fail( 'requires one exact failed-order identity before cutover.' );
	}
	if (
		fixture.myAccountPayLink.orderId !== fixture.order.id ||
		fixture.myAccountPayLink.orderKeySha256 !== fixture.order.keySha256 ||
		fixture.myAccountPayLink.customerId !== fixture.customerId ||
		! SHA256_PATTERN.test( fixture.myAccountPayLink.pathSha256 )
	) {
		fail( 'requires the failed order pay link identity.' );
	}
	if (
		! hasValue( fixture.clientDecline.intentId ) ||
		! hasValue( fixture.clientDecline.paymentMethodId ) ||
		fixture.clientDecline.intentStatus !== 'requires_payment_method' ||
		fixture.clientDecline.chargeIds.length !== 1 ||
		! hasValue( fixture.clientDecline.chargeIds[ 0 ] ) ||
		fixture.clientDecline.captureCount !== 0 ||
		fixture.clientDecline.cardLast4 !== '0002'
	) {
		fail(
			'requires the exact declined client payment with one failed charge and no capture.'
		);
	}
}

function validateProtection(
	target: boolean,
	protection: CardTestingProtectionEvidence
): void {
	if ( protection.eligible !== target ) {
		fail( 'requires the exact card-testing protection target.' );
	}
	if ( ! target && protection.token !== null ) {
		fail( 'requires no card-testing protection token when disabled.' );
	}
	if (
		target &&
		( protection.token === null ||
			protection.token.length !== 16 ||
			! SHA256_PATTERN.test( protection.token.sha256 ) )
	) {
		fail( 'requires one 16-character card-testing protection token digest when enabled.' );
	}
}

function validateRecoveredOrder(
	fixture: HistoricalPayForOrderFixture,
	evidence: Pick< HistoricalPayForOrderEvidence, 'order' >
): void {
	const { order } = evidence;
	if (
		( order.status !== 'processing' && order.status !== 'completed' ) ||
		order.id !== fixture.order.id ||
		order.keySha256 !== fixture.order.keySha256 ||
		order.customerId !== fixture.customerId ||
		order.currency !== fixture.order.currency ||
		order.totalMinor !== fixture.order.totalMinor ||
		order.paymentMethod !== fixture.order.paymentMethod ||
		! sameValues( order.productLines, fixture.order.productLines ) ||
		! order.stockReduced ||
		order.noteCount !== fixture.order.noteCount + 1 ||
		order.emailCount !== fixture.order.emailCount + 1
	) {
		fail( 'requires the same failed order key, customer, currency, total, and line after recovery.' );
	}
}

function validateNativeSuccess(
	fixture: HistoricalPayForOrderFixture,
	nativeSuccess: HistoricalPayForOrderEvidence[ 'nativeSuccess' ]
): void {
	const { clientDecline } = fixture;
	if (
		nativeSuccess.intentStatus !== 'succeeded' ||
		nativeSuccess.cardLast4 !== '4242' ||
		! hasValue( nativeSuccess.intentId ) ||
		! hasValue( nativeSuccess.paymentMethodId ) ||
		nativeSuccess.occurrenceCount !== 1 ||
		nativeSuccess.captureOccurrenceCount !== 1 ||
		nativeSuccess.intentId === clientDecline.intentId ||
		nativeSuccess.paymentMethodId === clientDecline.paymentMethodId
	) {
		fail( 'requires distinct decline and success intent and PaymentMethod identities.' );
	}
	if (
		nativeSuccess.charges.length !== 1 ||
		nativeSuccess.charges[ 0 ].status !== 'succeeded' ||
		nativeSuccess.charges[ 0 ].captured !== true ||
		! hasValue( nativeSuccess.charges[ 0 ].id )
	) {
		fail( 'requires exactly one succeeded captured native charge.' );
	}
	if (
		nativeSuccess.intentId === clientDecline.intentId ||
		nativeSuccess.paymentMethodId === clientDecline.paymentMethodId
	) {
		fail( 'requires distinct decline and success intent and PaymentMethod identities.' );
	}
}

function validatePaymentIdentities(
	fixture: HistoricalPayForOrderFixture,
	evidence: HistoricalPayForOrderEvidence
): void {
	const { clientDecline } = fixture;
	const { nativeSuccess } = evidence;
	validateNativeSuccess( fixture, nativeSuccess );
	if (
		! sameValues( evidence.cardinality.intentIds, [
			clientDecline.intentId,
			nativeSuccess.intentId,
		] ) ||
		! sameValues( evidence.cardinality.paidIntentIds, [
			nativeSuccess.intentId,
		] ) ||
		evidence.cardinality.orphanIntentIds.length !== 0
	) {
		fail( 'forbids an extra successful or orphaned payment intent.' );
	}
}

function validateCardinalities(
	fixture: HistoricalPayForOrderFixture,
	evidence: HistoricalPayForOrderEvidence
): void {
	if ( ! sameValues( evidence.cardinality.orderIds, [ fixture.order.id ] ) ) {
		fail( 'requires exactly one order after recovery.' );
	}
	if (
		evidence.cardinality.stockReductionDelta !== 1 ||
		evidence.cardinality.paidNoteDelta !== 1 ||
		evidence.cardinality.customerEmailDelta !== 1 ||
		evidence.cardinality.listenerSideEffectCount !== 1
	) {
		fail( 'requires each stock, note, email, and listener side-effect delta exactly once.' );
	}
	if (
		! evidence.listener.quiescent ||
		evidence.listener.sideEffectCount !== 1 ||
		evidence.listener.sideEffectCount !==
			evidence.cardinality.listenerSideEffectCount
	) {
		fail( 'requires one listener side effect after listener quiescence.' );
	}
	if (
		evidence.journals.length !== 2 ||
		! sameValues(
			evidence.journals.map( ( journal ) => journal.submission ).toSorted(),
			[ 'client-decline', 'native-pay-for-order' ]
		) ||
		evidence.journals.some( ( journal ) => ! journal.resolved )
	) {
		fail( 'requires at most two resolved payment submission journals.' );
	}
}

function validateCleanup(
	fixture: HistoricalPayForOrderFixture,
	evidence: Pick< HistoricalPayForOrderEvidence, 'cleanup' | 'nativeSuccess' >
): void {
	const expected = {
		orderIds: [ fixture.order.id ],
		intentIds: [ fixture.clientDecline.intentId, evidence.nativeSuccess.intentId ],
		paymentMethodIds: [
			fixture.clientDecline.paymentMethodId,
			evidence.nativeSuccess.paymentMethodId,
		],
		chargeIds: [
			...fixture.clientDecline.chargeIds,
			evidence.nativeSuccess.charges[ 0 ].id,
		],
		customerIds: [ fixture.customerId ],
		productIds: [ fixture.productId ],
	};
	if ( ! sameValues( evidence.cleanup.manifest, expected ) ) {
		fail( 'requires an exact manifest of run-owned cleanup resources.' );
	}
	if ( Object.keys( evidence.cleanup ).length !== 1 ) {
		fail( 'rejects a pre-teardown cleanup receipt.' );
	}
}

/**
 * Validates that the plugin-origin declined order was paid once in place after native cutover.
 *
 * The browser scenario gathers the cold reads through the failure-recovery and card-testing-protection helpers; this oracle only accepts their complete, immutable evidence.
 *
 * @param fixture Immutable client-era failed-order evidence.
 * @param evidence Native recovery and cleanup evidence gathered after listener quiescence.
 * @return The accepted recovery evidence.
 */
export function validateHistoricalPayForOrderRecovery(
	fixture: HistoricalPayForOrderFixture,
	evidence: HistoricalPayForOrderEvidence
): HistoricalPayForOrderEvidence {
	evidence = {
		...evidence,
		protection: validateCardTestingProtectionEvidence( evidence.protection ),
	};
	validateImmutableFixture( fixture );
	if ( evidence.fixtureChecksumSha256 !== fixture.checksumSha256 ) {
		fail( 'requires the immutable fixture checksum.' );
	}
	validateProtection( fixture.protectionTarget, evidence.protection );
	validateRecoveredOrder( fixture, evidence );
	validatePaymentIdentities( fixture, evidence );
	validateCardinalities( fixture, evidence );
	validateCleanup( fixture, evidence );
	return evidence;
}

/**
 * Collects the ordered, provider-free evidence boundary for a historical pay-for-order recovery.
 *
 * @param dependencies Failure-recovery, protection, cutover, submission, listener, and cold-read adapters.
 * @return Validated recovery evidence.
 */
export async function collectHistoricalPayForOrderRecovery(
	dependencies: HistoricalPayForOrderRecoveryDependencies
): Promise< HistoricalPayForOrderBrowserEvidence > {
	const fixture = await dependencies.preCutover.withTransitionProviderLock(
		async () => {
			const recordedFixture =
				await dependencies.preCutover.readFailedFixture();
			validateImmutableFixture( recordedFixture );
			validateRecordedClientDecline(
				recordedFixture,
				await dependencies.preCutover.readFailedPayment()
			);
			const cutover = await dependencies.preCutover.cutOver();
			if ( cutover.runtimeOwner !== 'native' ) {
				fail( 'requires the native runtime to complete cutover.' );
			}
			return recordedFixture;
		}
	);

	return dependencies.postCutover.withCardTestingProtectionLock( async () => {
		const protectionSession =
			await dependencies.postCutover.captureProtection();
		const rendered =
			await dependencies.postCutover.observeRenderedProtection();
		const submission = await dependencies.postCutover.submitPayForOrder();
		if ( submission.requestCount !== 1 ) {
			fail( 'requires exactly one pay-for-order request after cutover.' );
		}
		const protection = composeCardTestingProtectionEvidence(
			protectionSession,
			rendered,
			submission.submittedProtection
		);
		validateProtection( fixture.protectionTarget, protection );
		await dependencies.postCutover.waitForListenerQuiescence();
		const cold = await dependencies.postCutover.readColdRecoveryEvidence();
		const evidence: HistoricalPayForOrderBrowserEvidence = {
			fixtureChecksumSha256: fixture.checksumSha256,
			protection,
			order: cold.order,
			nativeSuccess: cold.nativeSuccess,
			cleanup: cold.cleanup,
		};
		validateImmutableFixture( fixture );
		validateProtection( fixture.protectionTarget, evidence.protection );
		if (
			evidence.order.id !== fixture.order.id ||
			evidence.order.keySha256 !== fixture.order.keySha256 ||
			evidence.order.customerId !== fixture.customerId ||
			evidence.order.currency !== fixture.order.currency ||
			evidence.order.totalMinor !== fixture.order.totalMinor ||
			! [ 'processing', 'completed' ].includes( evidence.order.status ) ||
			evidence.nativeSuccess.intentId === fixture.clientDecline.intentId ||
			evidence.nativeSuccess.paymentMethodId === fixture.clientDecline.paymentMethodId ||
			evidence.nativeSuccess.occurrenceCount !== 1 ||
			evidence.nativeSuccess.captureOccurrenceCount !== 1
		) {
			fail( 'requires observed browser recovery identity and provider occurrence evidence.' );
		}
		return evidence;
	} );
}
