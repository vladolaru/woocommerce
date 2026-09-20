import { expect, test } from '@playwright/test';

import {
	validateHistoricalPayForOrderRecovery,
	type HistoricalPayForOrderEvidence,
	type HistoricalPayForOrderFixture,
} from './historical-pay-for-order';

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
			targetProtection: true,
			token: { length: 16, sha256: 'd'.repeat( 64 ) },
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
			chargeId: 'ch_succeeded',
			captureCount: 1,
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
				{ resource: 'payment-method', id: 'pm_succeeded' },
				{ resource: 'charge', id: 'ch_succeeded' },
			],
		},
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

test( 'rejects a different order, customer, money, key, or product line after recovery', () => {
	const recovered = evidence();
	recovered.order = {
		...recovered.order,
		id: 126,
		keySha256: 'e'.repeat( 64 ),
		customerId: 74,
		totalMinor: 1002,
		productLines: [ { productId: 92, quantity: 1 } ],
	};
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'same failed order' );
} );

test( 'rejects reused decline intent and PaymentMethod identities', () => {
	const recovered = evidence();
	recovered.nativeSuccess = {
		...recovered.nativeSuccess,
		intentId: 'pi_declined',
		paymentMethodId: 'pm_declined',
	};
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'distinct decline and success' );
} );

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

test( 'rejects a native success without exactly one charge and capture', () => {
	const recovered = evidence();
	recovered.nativeSuccess = {
		...recovered.nativeSuccess,
		captureCount: 0,
	} as never;
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'one native capture' );
} );

test( 'accepts false protection only with no token and rejects target drift', () => {
	const disabled = {
		...FIXTURE,
		protectionTarget: false as const,
	};
	const recovered = evidence();
	recovered.protection = { targetProtection: false, token: null };
	expect( validateHistoricalPayForOrderRecovery( disabled, recovered ) ).toBe(
		recovered
	);
	recovered.protection = {
		targetProtection: true,
		token: { length: 16, sha256: 'd'.repeat( 64 ) },
	};
	expect( () =>
		validateHistoricalPayForOrderRecovery( disabled, recovered )
	).toThrow( 'protection target' );
} );

test( 'rejects a usable token in the disabled protection branch', () => {
	const disabled = { ...FIXTURE, protectionTarget: false as const };
	const recovered = evidence();
	recovered.protection = {
		targetProtection: false,
		token: { length: 16, sha256: 'd'.repeat( 64 ) },
	} as never;
	expect( () =>
		validateHistoricalPayForOrderRecovery( disabled, recovered )
	).toThrow( 'no card-testing protection token' );
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
		'extra successful and orphaned intents',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cardinality = {
				...recovered.cardinality,
				paidIntentIds: [ 'pi_succeeded', 'pi_extra' ],
				orphanIntentIds: [ 'pi_orphan' ],
			};
		},
		'extra successful or orphaned',
	],
	[
		'wrong stock, note, email, and listener deltas',
		( recovered: HistoricalPayForOrderEvidence ) => {
			recovered.cardinality = {
				...recovered.cardinality,
				stockReductionDelta: 2,
				paidNoteDelta: 0,
				customerEmailDelta: 2,
				listenerSideEffectCount: 2,
			};
		},
		'side-effect delta',
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
] as const ) {
	test( `rejects ${ name }`, () => {
	const recovered = evidence();
	mutate( recovered );
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( expected );
	} );
}
