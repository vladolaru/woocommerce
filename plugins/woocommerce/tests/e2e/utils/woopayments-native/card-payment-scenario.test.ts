import {
	expect,
	test,
	type APIRequestContext,
	type Page,
} from '@playwright/test';

import {
	defineCardPaymentScenario,
	type CardPaymentScenarioDefinition,
	type CardPaymentScenarioEvidence,
	validateCardPaymentEvidence,
	publicCardPaymentEvidence,
	runCardPaymentScenario,
} from '../../tests/woopayments-native/scenarios/card-payment';
import type { ProviderWriteSession } from '../../fixtures/woopayments-native';

const deviceFingerprint = '0123456789abcdef0123456789abcdef';
const paymentData = [
	{
		key: 'wc-woocommerce_payments-new-payment-method',
		value: false,
	},
	{ key: 'wcpay-fraud-prevention-token', value: '' },
	{ key: 'wcpay-payment-method-error-code', value: '' },
	{ key: 'wcpay-payment-method-error-message', value: '' },
	{ key: 'wcpay-fingerprint', value: deviceFingerprint },
	{ key: 'wcpay-is-platform-payment-method', value: 'true' },
];

type AccessibleSummaryWithPaymentValue =
	CardPaymentScenarioEvidence[ 'accessibleSummary' ] & {
		paymentValue: string;
	};

const accessibleSummary = (
	evidence: CardPaymentScenarioEvidence
): AccessibleSummaryWithPaymentValue =>
	evidence.accessibleSummary as AccessibleSummaryWithPaymentValue;

const exactEvidence = (): CardPaymentScenarioEvidence => ( {
	runId: 'woopayments-run-exact',
	account: {
		cardTestingProtectionEligible: false,
	},
	browser: {
		renderedFraudPreventionToken: '',
		legacyFraudPreventionToken: undefined,
	},
	checkoutRequests: [
		{
			requestId: 'checkout-1',
			paymentMethod: 'woocommerce_payments',
			paymentData: structuredClone( paymentData ),
		},
	],
	checkoutResponses: [
		{
			requestId: 'checkout-1',
			status: 200,
			orderId: 42,
			orderKey: 'wc_order_exact',
		},
	],
	adapterOrderId: 42,
	orderReceived: {
		orderId: 42,
		orderKey: 'wc_order_exact',
	},
	order: {
		id: 42,
		orderKey: 'wc_order_exact',
		paymentMethod: 'woocommerce_payments',
		customerId: 0,
	},
	payment: {
		runId: 'woopayments-run-exact',
		orderId: 42,
		orderKey: 'wc_order_exact',
		intentId: 'pi_exact',
		chargeId: 'ch_exact',
		paymentMethodId: 'pm_exact',
		amountMinor: 1099,
		currency: 'USD',
		orderStatus: 'processing',
		providerStatus: 'succeeded',
		chargeStatus: 'succeeded',
		chargeCaptured: true,
		occurrenceCount: 1,
		captureOccurrenceCount: 1,
	},
	providerIntent: {
		id: 'pi_exact',
		paymentMethodId: 'pm_exact',
		customerId: 'cus_exact',
		nextAction: null,
		setupFutureUsage: null,
	},
	providerCustomerPaymentMethods: [],
	accessibleSummary: {
		statusVisible: true,
		summaryCount: 1,
		text: 'Order number: 42 Total: $10.99 Payment method:',
		paymentValue: 'Visa credit card ending in 4242',
	} as AccessibleSummaryWithPaymentValue,
} );

const strictTrueDefinition = {
	contractId:
		'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection true › using a basic card',
	title: 'Successful purchase › Carding protection true › using a basic card',
	protection: true,
	card: 'basic-card',
	price: '10.99',
	checkout: {
		kind: 'classic',
		path: 'classic-checkout/',
	},
} as const;

test( 'projects payment attachments without order or provider identifiers', () => {
	const projection = publicCardPaymentEvidence( exactEvidence().payment );
	expect( projection ).toEqual( {
		bindings: {
			runIdPresent: true,
			orderIdPresent: true,
			orderKeyPresent: true,
			intentIdPresent: true,
			chargeIdPresent: true,
			paymentMethodIdPresent: true,
		},
		amountMinor: 1099,
		currency: 'USD',
		orderStatus: 'processing',
		providerStatus: 'succeeded',
		chargeStatus: 'succeeded',
		chargeCaptured: true,
		occurrenceCount: 1,
		captureOccurrenceCount: 1,
	} );
	const serialized = JSON.stringify( projection );
	for ( const hidden of [
		'woopayments-run-exact',
		'wc_order_exact',
		'pi_exact',
		'ch_exact',
		'pm_exact',
	] ) {
		expect( serialized ).not.toContain( hidden );
	}
} );

test( 'the reusable payment scenario rejects unsupported account eligibility before a checkout can submit', async () => {
	await expect(
		runCardPaymentScenario(
			strictTrueDefinition,
			{
				withState: ( _session, _runId, callback ) =>
					callback( undefined ),
				completeCheckout: async () => {
					throw new Error( 'Checkout must not be reached.' );
				},
			},
			{
				adminApi: {
					get: async () => ( {
						ok: () => true,
						json: async () => ( {
							card_testing_protection_eligible: false,
						} ),
					} ),
				} as unknown as APIRequestContext,
				page: {} as Page,
				pilotRuntime: {} as ProviderWriteSession,
				runId: 'unit-reusable-card',
			}
		)
	).rejects.toThrow( /eligibility|eligible/ );
} );

const exactDigest = {
	length: 16,
	sha256: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
} as const;

const replacePaymentData = (
	evidence: CardPaymentScenarioEvidence,
	key: string,
	value: unknown
): void => {
	evidence.checkoutRequests[ 0 ].paymentData =
		evidence.checkoutRequests[ 0 ].paymentData.map( ( entry ) =>
			entry.key === key ? { ...entry, value } : { ...entry }
		);
};

const exactTrueEvidence = (): CardPaymentScenarioEvidence => {
	const evidence = exactEvidence();
	evidence.account.cardTestingProtectionEligible = true;
	replacePaymentData(
		evidence,
		'wcpay-fraud-prevention-token',
		structuredClone( exactDigest )
	);
	replacePaymentData( evidence, 'wcpay-fingerprint', 'present' );
	Object.assign( evidence, {
		adapterOrderKey: 'wc_order_exact',
		protectionTokens: {
			authoritativeSession: structuredClone( exactDigest ),
			exposed: structuredClone( exactDigest ),
			submitted: structuredClone( exactDigest ),
		},
		providerCard: {
			type: 'card',
			brand: 'visa',
			last4: '4242',
		},
	} );
	return evidence;
};

test.describe( 'card payment evidence validator', () => {
	test( 'accepts one exact strict-true protected card payment graph', () => {
		expect( () =>
			validateCardPaymentEvidence(
				exactTrueEvidence(),
				strictTrueDefinition
			)
		).not.toThrow();
	} );

	test( 'accepts one exact strict-false card payment graph', () => {
		expect( () =>
			validateCardPaymentEvidence( exactEvidence() )
		).not.toThrow();
	} );

	test( 'freezes an exact supported scenario definition', () => {
		const definition = defineCardPaymentScenario( strictTrueDefinition );

		expect( definition ).toEqual( strictTrueDefinition );
		expect( Object.isFrozen( definition ) ).toBe( true );
		expect( Object.isFrozen( definition.checkout ) ).toBe( true );
	} );

	test( 'rejects a non-object scenario definition without inspecting it', () => {
		expect( () =>
			defineCardPaymentScenario(
				null as unknown as typeof strictTrueDefinition
			)
		).toThrow( /scenario definition/i );
	} );

	for ( const invalidDefinition of [
		{
			name: 'contract ID',
			value: { ...strictTrueDefinition, contractId: 'wrong-contract' },
		},
		{
			name: 'title',
			value: { ...strictTrueDefinition, title: 'wrong title' },
		},
		{
			name: 'protection Boolean',
			value: { ...strictTrueDefinition, protection: false },
		},
		{
			name: 'card fixture',
			value: { ...strictTrueDefinition, card: 'saved-card' },
		},
		{
			name: 'price',
			value: { ...strictTrueDefinition, price: '11.00' },
		},
		{
			name: 'checkout kind',
			value: {
				...strictTrueDefinition,
				checkout: { kind: 'blocks', path: 'classic-checkout/' },
			},
		},
		{
			name: 'checkout path',
			value: {
				...strictTrueDefinition,
				checkout: { kind: 'classic', path: 'checkout/' },
			},
		},
		{
			name: 'additional axis',
			value: { ...strictTrueDefinition, retries: 1 },
		},
	] ) {
		test( `rejects an unsupported scenario ${ invalidDefinition.name }`, () => {
			expect( () =>
				defineCardPaymentScenario(
					invalidDefinition.value as unknown as CardPaymentScenarioDefinition
				)
			).toThrow( /scenario definition/i );
		} );
	}

	for ( const invalidEligibility of [ false, 0, 'true', null, undefined ] ) {
		test( `rejects strict-true eligibility ${ String(
			invalidEligibility
		) }`, () => {
			const evidence = exactTrueEvidence();
			evidence.account.cardTestingProtectionEligible = invalidEligibility;

			expect( () =>
				validateCardPaymentEvidence( evidence, strictTrueDefinition )
			).toThrow( /strict Boolean true/i );
		} );
	}

	for ( const invalidToken of [
		{
			name: 'authoritative session length',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				if ( evidence.protectionTokens ) {
					evidence.protectionTokens.authoritativeSession.length = 15;
				}
			},
			error: /authoritative session token.*16-character/i,
		},
		{
			name: 'Classic exposure digest',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				if ( evidence.protectionTokens ) {
					evidence.protectionTokens.exposed.sha256 = 'not-a-digest';
				}
			},
			error: /Classic exposure token.*16-character/i,
		},
		{
			name: 'submitted evidence equality',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				if ( evidence.protectionTokens ) {
					evidence.protectionTokens.submitted.sha256 = 'f'.repeat(
						64
					);
				}
			},
			error: /digests must be exactly equal/i,
		},
		{
			name: 'sole submitted field equality',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				replacePaymentData( evidence, 'wcpay-fraud-prevention-token', {
					length: 16,
					sha256: 'e'.repeat( 64 ),
				} );
			},
			error: /digests must be exactly equal/i,
		},
	] ) {
		test( `rejects invalid ${ invalidToken.name } evidence`, () => {
			const evidence = exactTrueEvidence();
			invalidToken.mutate( evidence );

			expect( () =>
				validateCardPaymentEvidence( evidence, strictTrueDefinition )
			).toThrow( invalidToken.error );
		} );
	}

	test( 'requires the Classic adapter order key in strict-true evidence', () => {
		const evidence = exactTrueEvidence();
		delete evidence.adapterOrderKey;

		expect( () =>
			validateCardPaymentEvidence( evidence, strictTrueDefinition )
		).toThrow( /adapter order key/i );
	} );

	for ( const invalidCard of [
		{ type: 'bank_account', brand: 'visa', last4: '4242' },
		{ type: 'card', brand: 'mastercard', last4: '4242' },
		{ type: 'card', brand: 'visa', last4: '4444' },
		undefined,
	] ) {
		test( `rejects invalid provider card evidence ${ JSON.stringify(
			invalidCard
		) }`, () => {
			const evidence = exactTrueEvidence();
			evidence.providerCard = invalidCard;

			expect( () =>
				validateCardPaymentEvidence( evidence, strictTrueDefinition )
			).toThrow( /card, Visa, and last4 4242/i );
		} );
	}

	test( 'accepts Blocks summary labels and accessible Visa alt text with last4', () => {
		const evidence = exactEvidence();
		evidence.accessibleSummary.text = 'Order #: 42 Total: $10.99 Payment:';
		accessibleSummary( evidence ).paymentValue = 'Visa Card ending in 4242';

		expect( () => validateCardPaymentEvidence( evidence ) ).not.toThrow();
	} );

	test( 'rejects eligibility values other than the strict Boolean false', () => {
		for ( const value of [ true, 0, 'false', null, undefined ] ) {
			const evidence = exactEvidence();
			evidence.account.cardTestingProtectionEligible = value;

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				/strict Boolean false/i
			);
		}
	} );

	test( 'rejects nonempty checkout observer failures', () => {
		const evidence = exactEvidence();
		evidence.observerFailures = [ 'checkout-response-capture' ];

		expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
			/checkout observation.*cleanly/i
		);
	} );

	test( 'rejects a non-2xx checkout response', () => {
		const evidence = exactEvidence();
		evidence.checkoutResponses[ 0 ].status = 500;

		expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
			/checkout response.*successful/i
		);
	} );

	for ( const cardinality of [
		{
			name: 'zero checkout requests',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutRequests = [];
			},
			error: /exactly one checkout request/i,
		},
		{
			name: 'duplicate checkout requests',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutRequests.push( {
					...evidence.checkoutRequests[ 0 ],
					requestId: 'checkout-2',
					paymentData: structuredClone( paymentData ),
				} );
			},
			error: /exactly one checkout request/i,
		},
		{
			name: 'zero checkout responses',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutResponses = [];
			},
			error: /exactly one checkout response/i,
		},
		{
			name: 'duplicate checkout responses',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutResponses.push( {
					...evidence.checkoutResponses[ 0 ],
					requestId: 'checkout-2',
				} );
			},
			error: /exactly one checkout response/i,
		},
	] ) {
		test( `rejects ${ cardinality.name }`, () => {
			const evidence = exactEvidence();
			cardinality.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				cardinality.error
			);
		} );
	}

	test( 'rejects duplicate checkout payment-data keys', () => {
		const evidence = exactEvidence();
		evidence.checkoutRequests[ 0 ].paymentData.push( {
			key: 'wcpay-fingerprint',
			value: '',
		} );

		expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
			/unique payment-data keys/i
		);
	} );

	for ( const mismatch of [
		{
			name: 'request and response ownership',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutResponses[ 0 ].requestId = 'checkout-other';
			},
			error: /belong to the checkout request/i,
		},
		{
			name: 'adapter order ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.adapterOrderId = 43;
			},
			error: /order ID correlation/i,
		},
		{
			name: 'checkout response order ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutResponses[ 0 ].orderId = 43;
			},
			error: /order ID correlation/i,
		},
		{
			name: 'order-received order ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.orderReceived.orderId = 43;
			},
			error: /order ID correlation/i,
		},
		{
			name: 'REST order ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.order.id = 43;
			},
			error: /order ID correlation/i,
		},
		{
			name: 'payment evidence order ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.orderId = 43;
			},
			error: /order ID correlation/i,
		},
		{
			name: 'order-received order key',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.orderReceived.orderKey = 'wc_order_other';
			},
			error: /order key correlation/i,
		},
		{
			name: 'REST order key',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.order.orderKey = 'wc_order_other';
			},
			error: /order key correlation/i,
		},
		{
			name: 'payment evidence order key',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.orderKey = 'wc_order_other';
			},
			error: /order key correlation/i,
		},
	] ) {
		test( `rejects a ${ mismatch.name } mismatch`, () => {
			const evidence = exactEvidence();
			mismatch.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				mismatch.error
			);
		} );
	}

	for ( const gateway of [
		{
			name: 'checkout request',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.checkoutRequests[ 0 ].paymentMethod = 'cod';
			},
		},
		{
			name: 'WooCommerce order',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.order.paymentMethod = 'cod';
			},
		},
	] ) {
		test( `rejects the wrong ${ gateway.name } gateway`, () => {
			const evidence = exactEvidence();
			gateway.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				/WooPayments gateway/i
			);
		} );
	}

	test( 'rejects a true save flag', () => {
		const evidence = exactEvidence();
		replacePaymentData(
			evidence,
			'wc-woocommerce_payments-new-payment-method',
			true
		);

		expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
			/save flag.*Boolean false/i
		);
	} );

	for ( const field of [
		'wcpay-fraud-prevention-token',
		'wcpay-payment-method-error-code',
		'wcpay-payment-method-error-message',
	] ) {
		test( `rejects a nonempty ${ field } field`, () => {
			const evidence = exactEvidence();
			replacePaymentData( evidence, field, 'unexpected-value' );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				new RegExp( `${ field }.*empty`, 'i' )
			);
		} );
	}

	test( 'accepts an empty best-effort device fingerprint', () => {
		const evidence = exactEvidence();
		replacePaymentData( evidence, 'wcpay-fingerprint', '' );

		expect( () => validateCardPaymentEvidence( evidence ) ).not.toThrow();
	} );

	for ( const fingerprint of [
		'provider_fingerprint',
		deviceFingerprint.toUpperCase(),
		deviceFingerprint.slice( 1 ),
		`${ deviceFingerprint }0`,
		null,
	] ) {
		test( `rejects non-device fingerprint evidence ${ String(
			fingerprint
		) }`, () => {
			const evidence = exactEvidence();
			replacePaymentData( evidence, 'wcpay-fingerprint', fingerprint );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				/device fingerprint/i
			);
		} );
	}

	test( 'rejects invalid public-safe platform markers', () => {
		for ( const value of [ '', 'platform', true, false, 1 ] ) {
			const evidence = exactEvidence();
			replacePaymentData(
				evidence,
				'wcpay-is-platform-payment-method',
				value
			);

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				/platform marker.*string Boolean/i
			);
		}
	} );

	for ( const browserToken of [
		{
			name: 'absent rendered fraud token setting',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.browser.renderedFraudPreventionToken = undefined;
			},
			error: /rendered fraud prevention token.*explicitly empty/i,
		},
		{
			name: 'rendered fraud token',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.browser.renderedFraudPreventionToken = 'present';
			},
			error: /rendered fraud prevention token.*empty/i,
		},
		{
			name: 'legacy fraud token',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.browser.legacyFraudPreventionToken = '';
			},
			error: /legacy fraud prevention token.*undefined/i,
		},
	] ) {
		test( `rejects ${ browserToken.name } presence`, () => {
			const evidence = exactEvidence();
			browserToken.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				browserToken.error
			);
		} );
	}

	test( 'rejects a non-guest WooCommerce order', () => {
		const evidence = exactEvidence();
		evidence.order.customerId = 7;

		expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
			/guest customer ID 0/i
		);
	} );

	for ( const providerState of [
		{
			name: 'next action',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.providerIntent.nextAction = {
					type: 'redirect_to_url',
				};
			},
			error: /must not require a next action/i,
		},
		{
			name: 'setup-future-usage state',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.providerIntent.setupFutureUsage = 'off_session';
			},
			error: /must not set up future usage/i,
		},
	] ) {
		test( `rejects provider ${ providerState.name }`, () => {
			const evidence = exactEvidence();
			providerState.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				providerState.error
			);
		} );
	}

	test( 'rejects nonempty provider customer payment methods', () => {
		const evidence = exactEvidence();
		evidence.providerCustomerPaymentMethods = [ { id: 'pm_unexpected' } ];

		expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
			/provider customer payment-method collection.*empty/i
		);
	} );

	for ( const paymentMismatch of [
		{
			name: 'run ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.runId = 'woopayments-run-other';
			},
			error: /run ID correlation/i,
		},
		{
			name: 'missing intent ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.intentId = '';
			},
			error: /non-empty intent ID/i,
		},
		{
			name: 'missing charge ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.chargeId = '';
			},
			error: /non-empty charge ID/i,
		},
		{
			name: 'missing payment-method ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.paymentMethodId = '';
			},
			error: /non-empty payment-method ID/i,
		},
		{
			name: 'amount',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.amountMinor = 1098;
			},
			error: /amount.*1099 USD/i,
		},
		{
			name: 'currency',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.currency = 'EUR';
			},
			error: /amount.*1099 USD/i,
		},
		{
			name: 'merchant order status',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.orderStatus = 'pending';
			},
			error: /processing or completed/i,
		},
		{
			name: 'provider intent status',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.providerStatus = 'requires_action';
			},
			error: /intent status.*succeeded/i,
		},
		{
			name: 'provider charge status',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.chargeStatus = 'pending';
			},
			error: /charge status.*succeeded/i,
		},
		{
			name: 'capture state',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.chargeCaptured = false;
			},
			error: /charge.*captured/i,
		},
		{
			name: 'charge occurrence count',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.occurrenceCount = 2;
			},
			error: /exactly one charge occurrence/i,
		},
		{
			name: 'capture occurrence count',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.payment.captureOccurrenceCount = 2;
			},
			error: /exactly one capture occurrence/i,
		},
		{
			name: 'provider intent ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.providerIntent.id = 'pi_other';
			},
			error: /provider intent ID correlation/i,
		},
		{
			name: 'provider payment-method ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.providerIntent.paymentMethodId = 'pm_other';
			},
			error: /provider payment-method ID correlation/i,
		},
		{
			name: 'missing provider customer ID',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.providerIntent.customerId = '';
			},
			error: /non-empty provider customer ID/i,
		},
	] ) {
		test( `rejects a card payment ${ paymentMismatch.name } mismatch`, () => {
			const evidence = exactEvidence();
			paymentMismatch.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				paymentMismatch.error
			);
		} );
	}

	for ( const summaryGap of [
		{
			name: 'missing visible status',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.accessibleSummary.statusVisible = false;
			},
			error: /visible order-received status/i,
		},
		{
			name: 'missing summary',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.accessibleSummary.summaryCount = 0;
			},
			error: /exactly one semantic order summary/i,
		},
		{
			name: 'duplicate summary',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.accessibleSummary.summaryCount = 2;
			},
			error: /exactly one semantic order summary/i,
		},
		{
			name: 'missing order number',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.accessibleSummary.text =
					'Total: $10.99 Payment method:';
			},
			error: /exact order number/i,
		},
		{
			name: 'missing total',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				evidence.accessibleSummary.text =
					'Order number: 42 Payment method:';
			},
			error: /exact USD 10.99 total/i,
		},
		{
			name: 'missing card semantics',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				accessibleSummary( evidence ).paymentValue = 'ending in 4242';
			},
			error: /card or Visa semantics/i,
		},
		{
			name: 'missing basic-card last4',
			mutate: ( evidence: CardPaymentScenarioEvidence ) => {
				accessibleSummary( evidence ).paymentValue = 'Visa credit card';
			},
			error: /last4 4242/i,
		},
	] ) {
		test( `rejects accessible summary evidence with ${ summaryGap.name }`, () => {
			const evidence = exactEvidence();
			summaryGap.mutate( evidence );

			expect( () => validateCardPaymentEvidence( evidence ) ).toThrow(
				summaryGap.error
			);
		} );
	}
} );
