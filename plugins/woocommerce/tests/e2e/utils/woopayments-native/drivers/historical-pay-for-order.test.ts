import { expect, test } from '@playwright/test';

import {
	collectHistoricalPayForOrderRecovery,
	validateHistoricalPayForOrderRecovery,
	type HistoricalPayForOrderEvidence,
	type HistoricalPayForOrderFixture,
} from './historical-pay-for-order';
import type { FailedPaymentEvidence } from './failed-payment-evidence';

const FIXTURE: HistoricalPayForOrderFixture = {
	schemaVersion: 1,
	source: {
		pluginVersion: '11.1.0',
		sourceCommit: 'f85392666c9b543cd24dbbf903e0dbe4cb2c5cee',
	},
	allocation: {
		runId: 'historical-pay-order-unit',
		storeId: 'store-unit',
		blogId: 17,
		accountId: 'acct_unit',
	},
	protectionTarget: true,
	customerId: 73,
	productId: 91,
	order: {
		id: 125,
		keySha256: 'a'.repeat( 64 ),
		customerId: 73,
		currency: 'USD',
		totalMinor: 1001,
		status: 'failed',
		paymentMethod: 'woocommerce_payments',
		productLines: [ { productId: 91, quantity: 1 } ],
		stockReduced: false,
		noteCount: 1,
		emailCount: 0,
	},
	myAccountPayLink: {
		orderId: 125,
		orderKeySha256: 'a'.repeat( 64 ),
		customerId: 73,
		pathSha256: 'b'.repeat( 64 ),
	},
	clientDecline: {
		intentId: 'pi_declined',
		intentStatus: 'requires_payment_method',
		errorCode: 'card_declined',
		declineCode: 'generic_decline',
		paymentMethodId: 'pm_declined',
		chargeIds: [],
		captureCount: 0,
		cardLast4: '0002',
	},
	baseline: {
		orderIds: [ 125 ],
		stockQuantity: 4,
		noteCount: 1,
		emailCount: 0,
	},
	checksumSha256: 'c'.repeat( 64 ),
};

function evidence(): HistoricalPayForOrderEvidence {
	return {
		fixtureChecksumSha256: 'c'.repeat( 64 ),
		protection: {
			eligible: true,
			token: { length: 16, sha256: 'd'.repeat( 64 ) },
			accountEnabled: true,
			renderedTokenSha256: 'd'.repeat( 64 ),
			submittedTokenSha256: 'd'.repeat( 64 ),
		},
		order: {
			id: 125,
			keySha256: 'a'.repeat( 64 ),
			customerId: 73,
			currency: 'USD',
			totalMinor: 1001,
			status: 'processing',
			paymentMethod: 'woocommerce_payments',
			productLines: [ { productId: 91, quantity: 1 } ],
			stockReduced: true,
			noteCount: 2,
			emailCount: 1,
		},
		nativeSuccess: {
			intentId: 'pi_succeeded',
			intentStatus: 'succeeded',
			paymentMethodId: 'pm_succeeded',
			charges: [
				{ id: 'ch_succeeded', status: 'succeeded', captured: true },
			],
			cardLast4: '4242',
		},
		cardinality: {
			orderIds: [ 125 ],
			intentIds: [ 'pi_declined', 'pi_succeeded' ],
			paidIntentIds: [ 'pi_succeeded' ],
			orphanIntentIds: [],
			stockReductionDelta: 1,
			paidNoteDelta: 1,
			customerEmailDelta: 1,
			listenerSideEffectCount: 1,
		},
		listener: { quiescent: true, sideEffectCount: 1 },
		journals: [
			{ submission: 'client-decline', resolved: true },
			{ submission: 'native-pay-for-order', resolved: true },
		],
		cleanup: {
			manifest: {
				orderIds: [ 125 ],
				intentIds: [ 'pi_declined', 'pi_succeeded' ],
				paymentMethodIds: [ 'pm_declined', 'pm_succeeded' ],
				chargeIds: [ 'ch_succeeded' ],
				customerIds: [ 73 ],
				productIds: [ 91 ],
			},
			cleaned: [
				{ resource: 'order', id: '125' },
				{ resource: 'intent', id: 'pi_declined' },
				{ resource: 'intent', id: 'pi_succeeded' },
				{ resource: 'payment-method', id: 'pm_declined' },
				{ resource: 'payment-method', id: 'pm_succeeded' },
				{ resource: 'charge', id: 'ch_succeeded' },
				{ resource: 'customer', id: '73' },
				{ resource: 'product', id: '91' },
			],
		},
	};
}

function failedPaymentEvidence(): FailedPaymentEvidence {
	return {
		orderStatus: 'failed',
		orderTotal: '10.01',
		orderCurrency: 'USD',
		intentIdMeta: 'pi_declined',
		chargeIdMeta: '',
		intentionStatusMeta: 'requires_payment_method',
		intentId: 'pi_declined',
		intentStatus: 'requires_payment_method',
		paymentMethodId: 'pm_declined',
		intentAmount: 1001,
		intentCurrency: 'usd',
		amountReceived: 0,
		errorCode: 'card_declined',
		declineCode: 'generic_decline',
		chargeIds: [],
		chargeStatuses: [],
		capturedCharges: 0,
		failureNoteCount: 1,
		setupFutureUsage: null,
		providerCustomerId: '',
		providerAttachedPaymentMethodIds: [],
		orderCustomerId: 73,
		localTokenIds: [],
	};
}

test( 'accepts exactly one immutable 11.1.0 failed order paid in place after native cutover', () => {
	expect( validateHistoricalPayForOrderRecovery( FIXTURE, evidence() ) ).toEqual(
		evidence()
	);
} );

test( 'rejects an immutable source with the wrong plugin version', () => {
	expect( () =>
		validateHistoricalPayForOrderRecovery(
			{
				...FIXTURE,
				source: { ...FIXTURE.source, pluginVersion: '11.1.1' },
			},
			evidence()
		)
	).toThrow( 'immutable 11.1.0 source' );
} );

test( 'rejects an immutable source with the wrong source commit', () => {
	expect( () =>
		validateHistoricalPayForOrderRecovery(
			{
				...FIXTURE,
				source: { ...FIXTURE.source, sourceCommit: 'f'.repeat( 40 ) },
			},
			evidence()
		)
	).toThrow( 'immutable 11.1.0 source' );
} );

for ( const [ name, mutate ] of [
	[ 'order ID', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.order = { ...recovered.order, id: 126 } ) ],
	[ 'order key', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.order = { ...recovered.order, keySha256: 'e'.repeat( 64 ) } ) ],
	[ 'order customer', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.order = { ...recovered.order, customerId: 74 } ) ],
	[ 'order currency', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.order = { ...recovered.order, currency: 'EUR' } as never ) ],
	[ 'order total', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.order = { ...recovered.order, totalMinor: 1002 } ) ],
	[ 'order product line', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.order = { ...recovered.order, productLines: [ { productId: 92, quantity: 1 } ] } ) ],
] as const ) {
	test( `rejects a changed ${ name } after recovery`, () => {
	const recovered = evidence();
	mutate( recovered );
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'same failed order' );
	} );
}

test( 'rejects a cast recovered order status outside processing and completed', () => {
	const recovered = evidence();
	recovered.order = { ...recovered.order, status: 'failed' } as never;
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'same failed order' );
} );

for ( const [ name, mutate ] of [
	[ 'intent', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.nativeSuccess = { ...recovered.nativeSuccess, intentId: 'pi_declined' } ) ],
	[ 'PaymentMethod', ( recovered: HistoricalPayForOrderEvidence ) => ( recovered.nativeSuccess = { ...recovered.nativeSuccess, paymentMethodId: 'pm_declined' } ) ],
] as const ) {
	test( `rejects a reused decline ${ name } identity`, () => {
		const recovered = evidence();
		mutate( recovered );
		expect( () =>
			validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
		).toThrow( 'distinct decline and success' );
	} );
}

test( 'rejects a client decline that created a charge or capture', () => {
	const declinedWithCharge = {
		...FIXTURE,
		clientDecline: {
			...FIXTURE.clientDecline,
			chargeIds: [ 'ch_declined' ],
		},
	} as unknown as HistoricalPayForOrderFixture;
	expect( () =>
		validateHistoricalPayForOrderRecovery( declinedWithCharge, evidence() )
	).toThrow( 'no charge or capture' );
} );

test( 'rejects a client decline that captured a charge', () => {
	const capturedDecline = {
		...FIXTURE,
		clientDecline: {
			...FIXTURE.clientDecline,
			captureCount: 1,
		},
	} as unknown as HistoricalPayForOrderFixture;
	expect( () =>
		validateHistoricalPayForOrderRecovery( capturedDecline, evidence() )
	).toThrow( 'no charge or capture' );
} );

test( 'rejects a native success without exactly one charge and capture', () => {
	const recovered = evidence();
	recovered.nativeSuccess = {
		...recovered.nativeSuccess,
		charges: [ { id: 'ch_succeeded', status: 'succeeded', captured: false } ],
	} as never;
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'exactly one succeeded captured' );
} );

test( 'rejects an extra successful charge', () => {
	const recovered = evidence();
	recovered.nativeSuccess = {
		...recovered.nativeSuccess,
		charges: [
			...recovered.nativeSuccess.charges,
			{ id: 'ch_extra', status: 'succeeded', captured: true },
		],
	};
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'exactly one succeeded captured' );
} );

test( 'accepts false protection only with no token and rejects target drift', () => {
	const disabled = {
		...FIXTURE,
		protectionTarget: false as const,
	};
	const recovered = evidence();
	recovered.protection = {
		eligible: false,
		token: null,
		accountEnabled: false,
		renderedField: 'absent',
		submittedTokenSha256: null,
	};
	expect( validateHistoricalPayForOrderRecovery( disabled, recovered ) ).toEqual(
		recovered
	);
	recovered.protection = {
		eligible: true,
		token: { length: 16, sha256: 'd'.repeat( 64 ) },
		accountEnabled: true,
		renderedTokenSha256: 'd'.repeat( 64 ),
		submittedTokenSha256: 'd'.repeat( 64 ),
	};
	expect( () =>
		validateHistoricalPayForOrderRecovery( disabled, recovered )
	).toThrow( 'protection target' );
} );

test( 'rejects a usable token in the disabled protection branch', () => {
	const disabled = { ...FIXTURE, protectionTarget: false as const };
	const recovered = evidence();
	recovered.protection = {
		eligible: false,
		token: { length: 16, sha256: 'd'.repeat( 64 ) },
		accountEnabled: false,
		renderedField: 'empty',
		submittedTokenSha256: null,
	} as never;
	expect( () =>
		validateHistoricalPayForOrderRecovery( disabled, recovered )
	).toThrow( 'exact public fields' );
} );

for ( const [ name, mutate, expected ] of [
	[
		'duplicate order',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cardinality = {
				...recovered.cardinality,
				orderIds: [ 125, 126 ],
			};
		},
		'exactly one order',
	],
	[
		'extra successful intent',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cardinality = {
				...recovered.cardinality,
				paidIntentIds: [ 'pi_succeeded', 'pi_extra' ],
			};
		},
		'extra successful or orphaned',
	],
	[
		'orphaned intent',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cardinality = {
				...recovered.cardinality,
				orphanIntentIds: [ 'pi_orphan' ],
			};
		},
		'extra successful or orphaned',
	],
	...[
		[ 'stock', 'stockReductionDelta' ],
		[ 'paid note', 'paidNoteDelta' ],
		[ 'customer email', 'customerEmailDelta' ],
		[ 'listener side effect', 'listenerSideEffectCount' ],
	].map( ( [ sideEffectName, field ] ) => [
		`${ sideEffectName } delta`,
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cardinality = {
				...recovered.cardinality,
				[ field ]: 0,
			};
		},
		'side-effect delta',
	] as const ),
	[
		'third journal',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.journals = [
				...recovered.journals,
				{ submission: 'native-pay-for-order', resolved: true },
			];
		},
		'resolved payment submission journals',
	],
	[
		'replayed journal',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.journals = [
				{ submission: 'client-decline', resolved: true },
				{ submission: 'client-decline', resolved: true },
			];
		},
		'resolved payment submission journals',
	],
	[
		'listener activity before quiescence',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.listener = { quiescent: false, sideEffectCount: 1 };
		},
		'listener quiescence',
	],
	[
		'unresolved second journal',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.journals[ 1 ].resolved = false;
		},
		'resolved payment submission journals',
	],
	[
		'unowned cleanup resource',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cleanup = {
				...recovered.cleanup,
				cleaned: [
					...recovered.cleanup.cleaned,
					{ resource: 'order', id: '999' },
				],
			};
		},
		'manifest-owned resources only',
	],
	[
		'empty cleanup',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cleanup = { ...recovered.cleanup, cleaned: [] };
		},
		'complete cleanup',
	],
	[
		'partial cleanup',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cleanup = {
				...recovered.cleanup,
				cleaned: recovered.cleanup.cleaned.slice( 1 ),
			};
		},
		'complete cleanup',
	],
] as const ) {
	test( `rejects ${ name }`, () => {
	const recovered = evidence();
	mutate( recovered );
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( expected );
	} );
}

test( 'collects failed-order evidence before cutover and cold reads only after listener quiescence', async () => {
	const stages: string[] = [];
	const result = await collectHistoricalPayForOrderRecovery( {
		readFailedFixture: async () => {
			stages.push( 'failed-fixture' );
			return FIXTURE;
		},
		readFailedPayment: async () => {
			stages.push( 'failed-payment' );
			return failedPaymentEvidence();
		},
		captureProtection: async () => {
			stages.push( 'protection' );
			return {
				eligible: true,
				token: { length: 16, sha256: 'd'.repeat( 64 ) },
				accountEnabled: true,
			};
		},
		cutOver: async () => {
			stages.push( 'cutover' );
		},
		observeRenderedProtection: async () => {
			stages.push( 'rendered' );
			return { tokenSha256: 'd'.repeat( 64 ) };
		},
		submitPayForOrder: async () => {
			stages.push( 'pay-for-order' );
			return { requestCount: 1, submittedProtection: { tokenSha256: 'd'.repeat( 64 ) } };
		},
		waitForListenerQuiescence: async () => {
			stages.push( 'listener-quiescent' );
		},
		readColdRecoveryEvidence: async () => {
			stages.push( 'cold-read' );
			return evidence();
		},
	} );
	expect( result.order.id ).toBe( 125 );
	expect( stages ).toEqual( [
		'failed-fixture',
		'failed-payment',
		'cutover',
		'protection',
		'rendered',
		'pay-for-order',
		'listener-quiescent',
		'cold-read',
	] );
} );

for ( const requestCount of [ 0, 2 ] ) {
	test( `rejects ${ requestCount } post-cutover pay-for-order requests before cold reads`, async () => {
		let coldRead = false;
		await expect(
			collectHistoricalPayForOrderRecovery( {
				readFailedFixture: async () => FIXTURE,
				readFailedPayment: async () => failedPaymentEvidence(),
				captureProtection: async () => ( { eligible: true, token: { length: 16, sha256: 'd'.repeat( 64 ) }, accountEnabled: true } ),
				cutOver: async () => {},
				observeRenderedProtection: async () => ( { tokenSha256: 'd'.repeat( 64 ) } ),
				submitPayForOrder: async () => ( { requestCount, submittedProtection: { tokenSha256: 'd'.repeat( 64 ) } } ),
				waitForListenerQuiescence: async () => {},
				readColdRecoveryEvidence: async () => {
					coldRead = true;
					return evidence();
				},
			} )
		).rejects.toThrow( 'exactly one pay-for-order request' );
		expect( coldRead ).toBe( false );
	} );
}
