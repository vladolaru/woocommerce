import { expect, test, type BrowserContext } from '@playwright/test';

import {
	collectHistoricalPayForOrderRecovery,
	clearHistoricalWooCommerceSessionCookie,
	composeHistoricalPayForOrderEvidence,
	validateHistoricalOrderPayRoute,
	validateHistoricalOrderPayTotal,
	validateHistoricalPayForOrderRecovery,
	type HistoricalPayForOrderBrowserEvidence,
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
		paymentMethodId: 'pm_declined',
		chargeIds: [ 'ch_declined' ],
		captureCount: 0,
		cardLast4: '0002',
	},
	baseline: {
		orderIds: [ 125 ],
		stockQuantity: 1,
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
			occurrenceCount: 1,
			captureOccurrenceCount: 1,
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
				chargeIds: [ 'ch_declined', 'ch_succeeded' ],
				customerIds: [ 73 ],
				productIds: [ 91 ],
			},
		},
	};
}

function browserEvidence(): HistoricalPayForOrderBrowserEvidence {
	const full = evidence();
	return {
		fixtureChecksumSha256: full.fixtureChecksumSha256,
		protection: full.protection,
		order: {
			id: full.order.id,
			keySha256: full.order.keySha256,
			customerId: full.order.customerId,
			currency: full.order.currency,
			totalMinor: full.order.totalMinor,
			status: full.order.status,
			noteCount: full.order.noteCount,
		},
		nativeSuccess: {
			intentId: full.nativeSuccess.intentId,
			paymentMethodId: full.nativeSuccess.paymentMethodId,
			chargeId: full.nativeSuccess.charges[ 0 ].id,
			chargeStatus: full.nativeSuccess.charges[ 0 ].status,
			chargeCaptured: full.nativeSuccess.charges[ 0 ].captured,
			occurrenceCount: full.nativeSuccess.occurrenceCount,
			captureOccurrenceCount: full.nativeSuccess.captureOccurrenceCount,
		},
		cleanup: full.cleanup,
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
		amountReceived: null,
		errorCode: null,
		declineCode: null,
		chargeIds: [ 'ch_declined' ],
		chargeStatuses: [ 'failed' ],
		capturedCharges: 0,
		failureNoteCount: 1,
		setupFutureUsage: null,
		providerCustomerId: '',
		providerAttachedPaymentMethodIds: [],
		orderCustomerId: 73,
		localTokenIds: [],
	};
}

type HistoricalCookie = {
	name: string;
	value: string;
	domain: string;
	path: string;
};

function cookie( name: string, value: string ): HistoricalCookie {
	return { name, value, domain: 'store.invalid', path: '/' };
}

function historicalCookieContext(
	initialCookies: HistoricalCookie[],
	options: { retainClearedCookie?: boolean; clearError?: Error } = {}
) {
	let currentCookies = [ ...initialCookies ];
	return {
		async cookies() {
			return currentCookies as Awaited<
				ReturnType< BrowserContext[ 'cookies' ] >
			>;
		},
		async clearCookies(
			filter: Parameters< BrowserContext[ 'clearCookies' ] >[ 0 ]
		) {
			if ( options.clearError ) {
				throw options.clearError;
			}
			if ( options.retainClearedCookie ) {
				return;
			}
			currentCookies = currentCookies.filter(
				( currentCookie ) =>
					currentCookie.name !== filter.name ||
					currentCookie.domain !== filter.domain ||
					currentCookie.path !== filter.path
			);
		},
		names() {
			return currentCookies.map(
				( currentCookie ) => currentCookie.name
			);
		},
	};
}

function recoveryDependencies(
	decline: FailedPaymentEvidence,
	stages: string[] = [],
	requestCount = 1
) {
	return {
		preCutover: {
			withTransitionProviderLock: async < Result >(
				callback: () => Promise< Result >
			) => {
				stages.push( 'transition-enter' );
				try {
					return await callback();
				} finally {
					stages.push( 'transition-exit' );
				}
			},
			readFailedFixture: async () => {
				stages.push( 'failed-fixture' );
				return FIXTURE;
			},
			readFailedPayment: async () => {
				stages.push( 'failed-payment' );
				return decline;
			},
			cutOver: async () => {
				stages.push( 'cutover' );
				return { runtimeOwner: 'native' as const };
			},
		},
		postCutover: {
			withCardTestingProtectionLock: async < Result >(
				callback: () => Promise< Result >
			) => {
				stages.push( 'protection-enter' );
				try {
					return await callback();
				} finally {
					stages.push( 'protection-exit' );
				}
			},
			captureProtection: async () => {
				stages.push( 'protection' );
				return {
					eligible: true as const,
					token: { length: 16 as const, sha256: 'd'.repeat( 64 ) },
					accountEnabled: true as const,
				};
			},
			observeRenderedProtection: async () => {
				stages.push( 'rendered' );
				return { tokenSha256: 'd'.repeat( 64 ) };
			},
			submitPayForOrder: async () => {
				stages.push( 'pay-for-order' );
				return {
					requestCount,
					submittedProtection: { tokenSha256: 'd'.repeat( 64 ) },
				};
			},
			waitForListenerQuiescence: async () => {
				stages.push( 'listener-quiescent' );
			},
			readColdRecoveryEvidence: async () => {
				stages.push( 'cold-read' );
				return browserEvidence();
			},
		},
	};
}

test( 'clears only the one plugin-era WooCommerce session cookie', async () => {
	const context = historicalCookieContext( [
		cookie( 'wordpress_logged_in_fixture', 'auth' ),
		cookie( 'wp_woocommerce_session_fixture', 'plugin-session' ),
		cookie( 'unrelated', 'value' ),
	] );

	await clearHistoricalWooCommerceSessionCookie(
		context,
		'http://store.invalid'
	);

	expect( context.names() ).toEqual( [
		'wordpress_logged_in_fixture',
		'unrelated',
	] );
} );

test( 'rejects a context without a plugin-era WooCommerce session cookie', async () => {
	const context = historicalCookieContext( [
		cookie( 'wordpress_logged_in_fixture', 'auth' ),
	] );

	await expect(
		clearHistoricalWooCommerceSessionCookie(
			context,
			'http://store.invalid'
		)
	).rejects.toThrow( 'exactly one plugin-era WooCommerce session cookie' );
} );

test( 'rejects an ambiguous context with multiple WooCommerce session cookies', async () => {
	const context = historicalCookieContext( [
		cookie( 'wp_woocommerce_session_first', 'first' ),
		cookie( 'wp_woocommerce_session_second', 'second' ),
	] );

	await expect(
		clearHistoricalWooCommerceSessionCookie(
			context,
			'http://store.invalid'
		)
	).rejects.toThrow( 'exactly one plugin-era WooCommerce session cookie' );
} );

test( 'rejects a WooCommerce session cookie that survives deletion', async () => {
	const context = historicalCookieContext(
		[ cookie( 'wp_woocommerce_session_fixture', 'plugin-session' ) ],
		{ retainClearedCookie: true }
	);

	await expect(
		clearHistoricalWooCommerceSessionCookie(
			context,
			'http://store.invalid'
		)
	).rejects.toThrow( 'plugin-era WooCommerce session cookie to be absent' );
} );

test( 'propagates a WooCommerce session cookie deletion failure', async () => {
	const context = historicalCookieContext(
		[ cookie( 'wp_woocommerce_session_fixture', 'plugin-session' ) ],
		{ clearError: new Error( 'clear failed' ) }
	);

	await expect(
		clearHistoricalWooCommerceSessionCookie(
			context,
			'http://store.invalid'
		)
	).rejects.toThrow( 'clear failed' );
} );

test( 'rejects recorded failed evidence with a status different from the immutable failed order', async () => {
	await expect(
		collectHistoricalPayForOrderRecovery(
			recoveryDependencies( {
				...failedPaymentEvidence(),
				orderStatus: 'pending',
			} )
		)
	).rejects.toThrow( 'immutable failed-order status' );
} );

test( 'rejects recorded failed evidence with a total different from the immutable failed order', async () => {
	await expect(
		collectHistoricalPayForOrderRecovery(
			recoveryDependencies( {
				...failedPaymentEvidence(),
				orderTotal: '9.99',
			} )
		)
	).rejects.toThrow( 'immutable failed-order total' );
} );

test( 'accepts a terminal decline with one failed uncaptured charge and omitted amount received', async () => {
	const fixture = {
		...FIXTURE,
		clientDecline: {
			...FIXTURE.clientDecline,
			chargeIds: [ 'ch_declined' ],
		},
	} as unknown as HistoricalPayForOrderFixture;
	const dependencies = recoveryDependencies( {
		...failedPaymentEvidence(),
		amountReceived: null,
		chargeIds: [ 'ch_declined' ],
		chargeStatuses: [ 'failed' ],
	} );
	dependencies.preCutover.readFailedFixture = async () => fixture;

	await expect(
		collectHistoricalPayForOrderRecovery( dependencies )
	).resolves.toEqual( browserEvidence() );
} );

test( 'accepts a terminal decline with a numeric zero amount received', async () => {
	await expect(
		collectHistoricalPayForOrderRecovery(
			recoveryDependencies( {
				...failedPaymentEvidence(),
				amountReceived: 0,
			} )
		)
	).resolves.toEqual( browserEvidence() );
} );

const terminalDeclineFailures: Array<
	[ string, Partial< FailedPaymentEvidence > ]
> = [
	[ 'a succeeded charge', { chargeStatuses: [ 'succeeded' ] } ],
	[ 'a captured charge', { capturedCharges: 1 } ],
	[ 'a positive received amount', { amountReceived: 1001 } ],
	[ 'a changed charge identity', { chargeIds: [ 'ch_other' ] } ],
	[ 'a missing charge status', { chargeStatuses: [] } ],
];

for ( const [ name, override ] of terminalDeclineFailures ) {
	test( `rejects a terminal decline with ${ name }`, async () => {
		await expect(
			collectHistoricalPayForOrderRecovery(
				recoveryDependencies( {
					...failedPaymentEvidence(),
					...override,
				} )
			)
		).rejects.toThrow(
			'one failed uncaptured charge and no money movement'
		);
	} );
}

for ( const route of [
	'http://store.invalid/store/checkout/order-pay/125/?key=order-key&pay_for_order=true',
	'http://store.invalid/store/checkout/order-pay/126/?key=order-key&pay_for_order=true',
	'http://store.invalid/store/checkout/order-pay/125/?key=wrong&pay_for_order=true',
	'http://store.invalid/store/checkout/order-pay/125/?key=order-key&key=order-key&pay_for_order=true',
	'http://store.invalid/store/checkout/order-pay/125/?key=order-key&pay_for_order=true&pay_for_order=true',
] ) {
	test( `validates rendered order-pay route ${ route }`, () => {
		const validate = () =>
			validateHistoricalOrderPayRoute( route, {
				paymentUrl:
					'http://store.invalid/store/checkout/order-pay/125/?key=order-key&pay_for_order=true',
				orderId: 125,
				orderKey: 'order-key',
			} );
		if (
			route.includes( '126' ) ||
			route.includes( 'wrong' ) ||
			route.includes( '&key=' ) ||
			( route.match( /pay_for_order/g )?.length ?? 0 ) > 1
		) {
			expect( validate ).toThrow( 'exact rendered order-pay route' );
		} else {
			expect( validate ).not.toThrow();
		}
	} );
}

test( 'rejects a generic-decline fixture that is not the exact 1001 USD provider tuple', async () => {
	const fixture = {
		...FIXTURE,
		order: { ...FIXTURE.order, totalMinor: 1099 },
	};
	const dependencies = recoveryDependencies( {
		...failedPaymentEvidence(),
		orderTotal: '10.99',
	} );
	dependencies.preCutover.readFailedFixture = async () => fixture;
	await expect(
		collectHistoricalPayForOrderRecovery( dependencies )
	).rejects.toThrow( 'exact 1001' );
} );

test( 'rejects a rendered Total row that is not the exact historical order total', () => {
	expect( () => validateHistoricalOrderPayTotal( '$10.99' ) ).toThrow(
		'exact Total: $10.01 cell'
	);
	expect( () => validateHistoricalOrderPayTotal( '$10.01' ) ).not.toThrow();
} );

test( 'accepts exactly one immutable 11.1.0 failed order paid in place after native cutover', () => {
	expect(
		validateHistoricalPayForOrderRecovery( FIXTURE, evidence() )
	).toEqual( evidence() );
} );

test( 'requires Task 4 receipt facts before composing final historical evidence', () => {
	const full = evidence();
	const {
		stockReductionDelta: _stockReductionDelta,
		paidNoteDelta: _paidNoteDelta,
		customerEmailDelta: _customerEmailDelta,
		listenerSideEffectCount: _listenerSideEffectCount,
		...graph
	} = full.cardinality;
	expect(
		composeHistoricalPayForOrderEvidence(
			FIXTURE,
			{
				fixtureChecksumSha256: full.fixtureChecksumSha256,
				protection: full.protection,
				order: {
					id: full.order.id,
					keySha256: full.order.keySha256,
					customerId: full.order.customerId,
					currency: full.order.currency,
					totalMinor: full.order.totalMinor,
					status: full.order.status,
					noteCount: full.order.noteCount,
				},
				nativeSuccess: {
					intentId: full.nativeSuccess.intentId,
					paymentMethodId: full.nativeSuccess.paymentMethodId,
					chargeId: full.nativeSuccess.charges[ 0 ].id,
					chargeStatus: full.nativeSuccess.charges[ 0 ].status,
					chargeCaptured: full.nativeSuccess.charges[ 0 ].captured,
					occurrenceCount: full.nativeSuccess.occurrenceCount,
					captureOccurrenceCount:
						full.nativeSuccess.captureOccurrenceCount,
				},
				cleanup: full.cleanup,
			},
			{
				graph,
				listener: {
					quiescent: full.listener.quiescent,
					sideEffects: [ 'order-paid' ],
				},
				journals: full.journals,
				order: {
					paymentMethod: full.order.paymentMethod,
					productLines: full.order.productLines,
				},
				nativeSuccess: {
					intentStatus: full.nativeSuccess.intentStatus,
					cardLast4: full.nativeSuccess.cardLast4,
				},
				stock: { before: 1, after: 0 },
				notes: {
					before: FIXTURE.baseline.noteCount,
					after: full.order.noteCount,
				},
				emails: {
					before: FIXTURE.baseline.emailCount,
					after: full.order.emailCount,
				},
			}
		)
	).toEqual( full );
} );

test( 'rejects a Task 4 receipt without the observed managed-stock transition', () => {
	const full = evidence();
	const {
		stockReductionDelta: _stockReductionDelta,
		paidNoteDelta: _paidNoteDelta,
		customerEmailDelta: _customerEmailDelta,
		listenerSideEffectCount: _listenerSideEffectCount,
		...graph
	} = full.cardinality;
	expect( () =>
		composeHistoricalPayForOrderEvidence( FIXTURE, browserEvidence(), {
			graph,
			listener: {
				quiescent: full.listener.quiescent,
				sideEffects: [ 'order-paid' ],
			},
			journals: full.journals,
			order: {
				paymentMethod: full.order.paymentMethod,
				productLines: full.order.productLines,
			},
			nativeSuccess: {
				intentStatus: full.nativeSuccess.intentStatus,
				cardLast4: full.nativeSuccess.cardLast4,
			},
			stock: { before: 1, after: 1 },
			notes: {
				before: FIXTURE.baseline.noteCount,
				after: full.order.noteCount,
			},
			emails: {
				before: FIXTURE.baseline.emailCount,
				after: full.order.emailCount,
			},
		} )
	).toThrow( 'managed-stock transition from 1 to 0' );
} );

test( 'rejects a Task 4 receipt missing observed email counts', () => {
	const full = evidence();
	const {
		stockReductionDelta: _stockReductionDelta,
		paidNoteDelta: _paidNoteDelta,
		customerEmailDelta: _customerEmailDelta,
		listenerSideEffectCount: _listenerSideEffectCount,
		...graph
	} = full.cardinality;
	const receipt = {
		graph,
		listener: {
			quiescent: full.listener.quiescent,
			sideEffects: [ 'order-paid' ],
		},
		journals: full.journals,
		order: {
			paymentMethod: full.order.paymentMethod,
			productLines: full.order.productLines,
		},
		nativeSuccess: {
			intentStatus: full.nativeSuccess.intentStatus,
			cardLast4: full.nativeSuccess.cardLast4,
		},
		stock: { before: 1, after: 0 },
		notes: {
			before: FIXTURE.baseline.noteCount,
			after: full.order.noteCount,
		},
	} as unknown;
	expect( () =>
		composeHistoricalPayForOrderEvidence(
			FIXTURE,
			browserEvidence(),
			receipt as Parameters<
				typeof composeHistoricalPayForOrderEvidence
			>[ 2 ]
		)
	).toThrow( 'requires observed stock, note, and email counts' );
} );

test( 'rejects a pre-teardown cleanup receipt while retaining the exact cleanup manifest', () => {
	const recovered = evidence() as unknown as HistoricalPayForOrderEvidence & {
		cleanup: HistoricalPayForOrderEvidence[ 'cleanup' ] & {
			cleaned: unknown[];
		};
	};
	recovered.cleanup.cleaned = [];
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'pre-teardown cleanup receipt' );
} );

test( 'rejects a cleanup manifest that omits the declined charge', () => {
	const recovered = evidence();
	recovered.cleanup.manifest.chargeIds = [ 'ch_succeeded' ];
	expect( () =>
		validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
	).toThrow( 'exact manifest of run-owned cleanup resources' );
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
	[
		'order ID',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.order = { ...recovered.order, id: 126 } ),
	],
	[
		'order key',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.order = {
				...recovered.order,
				keySha256: 'e'.repeat( 64 ),
			} ),
	],
	[
		'order customer',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.order = { ...recovered.order, customerId: 74 } ),
	],
	[
		'order currency',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.order = {
				...recovered.order,
				currency: 'EUR',
			} as never ),
	],
	[
		'order total',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.order = { ...recovered.order, totalMinor: 1002 } ),
	],
	[
		'order product line',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.order = {
				...recovered.order,
				productLines: [ { productId: 92, quantity: 1 } ],
			} ),
	],
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
	[
		'intent',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.nativeSuccess = {
				...recovered.nativeSuccess,
				intentId: 'pi_declined',
			} ),
	],
	[
		'PaymentMethod',
		( recovered: HistoricalPayForOrderEvidence ) =>
			( recovered.nativeSuccess = {
				...recovered.nativeSuccess,
				paymentMethodId: 'pm_declined',
			} ),
	],
] as const ) {
	test( `rejects a reused decline ${ name } identity`, () => {
		const recovered = evidence();
		mutate( recovered );
		expect( () =>
			validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
		).toThrow( 'distinct decline and success' );
	} );
}

test( 'rejects a client decline with an extra charge identity', () => {
	const declinedWithExtraCharge = {
		...FIXTURE,
		clientDecline: {
			...FIXTURE.clientDecline,
			chargeIds: [ 'ch_declined', 'ch_extra' ],
		},
	} as unknown as HistoricalPayForOrderFixture;
	expect( () =>
		validateHistoricalPayForOrderRecovery(
			declinedWithExtraCharge,
			evidence()
		)
	).toThrow( 'one failed charge and no capture' );
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
	).toThrow( 'one failed charge and no capture' );
} );

test( 'rejects a native success without exactly one charge and capture', () => {
	const recovered = evidence();
	recovered.nativeSuccess = {
		...recovered.nativeSuccess,
		charges: [
			{ id: 'ch_succeeded', status: 'succeeded', captured: false },
		],
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
	expect(
		validateHistoricalPayForOrderRecovery( disabled, recovered )
	).toEqual( recovered );
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
	].map(
		( [ sideEffectName, field ] ) =>
			[
				`${ sideEffectName } delta`,
				( recovered: HistoricalPayForOrderEvidence ) => {
					recovered.cardinality = {
						...recovered.cardinality,
						[ field ]: 0,
					};
				},
				'side-effect delta',
			] as const
	),
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
] as const ) {
	test( `rejects ${ name }`, () => {
		const recovered = evidence();
		mutate( recovered );
		expect( () =>
			validateHistoricalPayForOrderRecovery( FIXTURE, recovered )
		).toThrow( expected );
	} );
}

test( 'releases the transition lock after one cutover before entering the protection stage', async () => {
	const stages: string[] = [];
	const result = await collectHistoricalPayForOrderRecovery(
		recoveryDependencies( failedPaymentEvidence(), stages )
	);
	expect( result.order.id ).toBe( 125 );
	expect( stages ).toEqual( [
		'transition-enter',
		'failed-fixture',
		'failed-payment',
		'cutover',
		'transition-exit',
		'protection-enter',
		'protection',
		'rendered',
		'pay-for-order',
		'listener-quiescent',
		'cold-read',
		'protection-exit',
	] );
} );

test( 'does not turn runner-only cardinality, listener, or journal claims into browser evidence', async () => {
	const result = await collectHistoricalPayForOrderRecovery(
		recoveryDependencies( failedPaymentEvidence() )
	);
	expect( result ).not.toHaveProperty( 'cardinality' );
	expect( result ).not.toHaveProperty( 'listener' );
	expect( result ).not.toHaveProperty( 'journals' );
} );

test( 'leaves the transition stage on a pre-cutover failure without entering the protection stage', async () => {
	const stages: string[] = [];
	await expect(
		collectHistoricalPayForOrderRecovery(
			recoveryDependencies(
				{ ...failedPaymentEvidence(), orderStatus: 'pending' },
				stages
			)
		)
	).rejects.toThrow( 'immutable failed-order status' );
	expect( stages ).toEqual( [
		'transition-enter',
		'failed-fixture',
		'failed-payment',
		'transition-exit',
	] );
} );

test( 'does not enter the protection stage when cutover does not report the native runtime', async () => {
	const stages: string[] = [];
	const dependencies = recoveryDependencies(
		failedPaymentEvidence(),
		stages
	);
	await expect(
		collectHistoricalPayForOrderRecovery( {
			...dependencies,
			preCutover: {
				...dependencies.preCutover,
				cutOver: async () => JSON.parse( '{"runtimeOwner":"plugin"}' ),
			},
		} )
	).rejects.toThrow( 'native runtime to complete cutover' );
	expect( stages ).toEqual( [
		'transition-enter',
		'failed-fixture',
		'failed-payment',
		'transition-exit',
	] );
} );

for ( const requestCount of [ 0, 2 ] ) {
	test( `rejects ${ requestCount } post-cutover pay-for-order requests before cold reads`, async () => {
		let coldRead = false;
		const dependencies = recoveryDependencies(
			failedPaymentEvidence(),
			[],
			requestCount
		);
		await expect(
			collectHistoricalPayForOrderRecovery( {
				...dependencies,
				postCutover: {
					...dependencies.postCutover,
					readColdRecoveryEvidence: async () => {
						coldRead = true;
						return browserEvidence();
					},
				},
			} )
		).rejects.toThrow( 'exactly one pay-for-order request' );
		expect( coldRead ).toBe( false );
	} );
}
