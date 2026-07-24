import {
	expect,
	test,
	type APIRequestContext,
	type APIResponse,
} from '@playwright/test';

import {
	getCaptureOrderNoteEvidence,
	getPaymentEvidence,
} from './record-evidence';
import { waitForPaymentState } from './provider-evidence';

interface ResponseDefinition {
	status?: number;
	body: unknown;
}

function response( definition: ResponseDefinition ): APIResponse {
	const status = definition.status ?? 200;
	return {
		ok: () => status >= 200 && status < 300,
		status: () => status,
		json: async () => definition.body,
	} as APIResponse;
}

function mockRestApi(
	responses: Record< string, ResponseDefinition[] >,
	calls: string[]
): APIRequestContext {
	return {
		get: async ( url: string ) => {
			calls.push( url );
			const definitions = responses[ url ];
			if ( ! definitions?.length ) {
				throw new Error( `Unexpected request: ${ url }` );
			}
			const definition =
				definitions.length > 1 ? definitions.shift() : definitions[ 0 ];
			if ( ! definition ) {
				throw new Error(
					`No response definition remains for ${ url }.`
				);
			}
			return response( definition );
		},
	} as APIRequestContext;
}

function order( overrides: Record< string, unknown > = {} ) {
	return {
		id: 42,
		order_key: 'wc_order_e2e',
		total: '10.99',
		currency: 'USD',
		status: 'processing',
		meta_data: [
			{ key: '_e2e_woopayments_run_id', value: 'run-evidence' },
			{ key: '_intent_id', value: 'pi_e2e' },
			{ key: '_charge_id', value: 'ch_e2e' },
			{ key: '_payment_method_id', value: 'pm_e2e' },
		],
		...overrides,
	};
}

function intent( overrides: Record< string, unknown > = {} ) {
	return {
		id: 'pi_e2e',
		amount: 1099,
		currency: 'usd',
		status: 'succeeded',
		payment_method: 'pm_e2e',
		charges: { data: [ { id: 'ch_e2e' } ] },
		...overrides,
	};
}

function charge( overrides: Record< string, unknown > = {} ) {
	return {
		id: 'ch_e2e',
		amount: 1099,
		currency: 'usd',
		status: 'succeeded',
		captured: true,
		payment_intent: 'pi_e2e',
		payment_method: 'pm_e2e',
		...overrides,
	};
}

function timeline( overrides: Record< string, unknown > = {} ) {
	return {
		data: [
			{
				id: 'evt_capture_e2e',
				type: 'captured',
				message: 'Payment captured.',
			},
		],
		...overrides,
	};
}

test( 'joins the exact order, intent, and charge into payment evidence', async () => {
	const calls: string[] = [];
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent() },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		calls
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).resolves.toEqual( {
		runId: 'run-evidence',
		orderId: 42,
		orderKey: 'wc_order_e2e',
		intentId: 'pi_e2e',
		chargeId: 'ch_e2e',
		paymentMethodId: 'pm_e2e',
		amountMinor: 1099,
		currency: 'USD',
		orderStatus: 'processing',
		providerStatus: 'succeeded',
		chargeStatus: 'succeeded',
		chargeCaptured: true,
		occurrenceCount: 1,
		captureOccurrenceCount: 1,
	} );
	expect( calls ).toEqual( [
		'/wp-json/wc/v3/orders/42',
		'/wp-json/wc/v3/payments/payment_intents/pi_e2e',
		'/wp-json/wc/v3/payments/charges/ch_e2e',
		'/wp-json/wc/v3/payments/timeline/pi_e2e',
	] );
} );

test( 'joins the exact plugin intent charge and charge route into one occurrence', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{
					body: intent( {
						charges: undefined,
						charge: { id: 'ch_e2e' },
					} ),
				},
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).resolves.toMatchObject( {
		chargeId: 'ch_e2e',
		occurrenceCount: 1,
	} );
} );

test( 'rejects a plugin intent whose exact charge relationship changed', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{
					body: intent( {
						charges: undefined,
						charge: { id: 'ch_other' },
					} ),
				},
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).rejects.toThrow(
		/charge relationship mismatch/i
	);
} );

test( 'rejects empty durable provider IDs before provider lookup', async () => {
	const calls: string[] = [];
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [
				{
					body: order( {
						meta_data: [
							{
								key: '_e2e_woopayments_run_id',
								value: 'run-evidence',
							},
							{ key: '_intent_id', value: '' },
							{ key: '_charge_id', value: 'ch_e2e' },
							{ key: '_payment_method_id', value: 'pm_e2e' },
						],
					} ),
				},
			],
		},
		calls
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).rejects.toThrow(
		/intent ID/i
	);
	expect( calls ).toEqual( [ '/wp-json/wc/v3/orders/42' ] );
} );

test( 'rejects amount or currency mismatches', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent( { amount: 1199 } ) },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [
				{ body: charge( { currency: 'eur' } ) },
			],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).rejects.toThrow(
		/amount mismatch/i
	);
} );

test( 'rejects provider currency mismatches independently of amount', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent( { currency: 'eur' } ) },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [
				{ body: charge( { currency: 'eur' } ) },
			],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).rejects.toThrow(
		/currency mismatch/i
	);
} );

test( 'rejects payment intents with occurrence count other than one', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{
					body: intent( {
						charges: {
							data: [ { id: 'ch_e2e' }, { id: 'ch_duplicate' } ],
						},
					} ),
				},
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).rejects.toThrow(
		/occurrence count/i
	);
} );

test( 'returns immediately when the named provider state already succeeded', async () => {
	const calls: string[] = [];
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent() },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		calls
	);
	const startedAt = Date.now();

	const evidence = await waitForPaymentState(
		restApi,
		{ orderId: 42, intentId: 'pi_e2e' },
		'succeeded',
		Date.now() + 2_000
	);

	expect( evidence.providerStatus ).toBe( 'succeeded' );
	expect( Date.now() - startedAt ).toBeLessThan( 450 );
	expect( calls ).toHaveLength( 4 );
} );

test( 'fails at the caller deadline without an extra sleep', async () => {
	const calls: string[] = [];
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent( { status: 'processing' } ) },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		calls
	);
	const startedAt = Date.now();

	await expect(
		waitForPaymentState(
			restApi,
			{ orderId: 42, intentId: 'pi_e2e' },
			'succeeded',
			Date.now()
		)
	).rejects.toThrow( /deadline/i );
	expect( Date.now() - startedAt ).toBeLessThan( 450 );
	expect( calls ).toEqual( [] );
} );

test( 'threads the shrinking deadline through every sequential evidence request', async () => {
	const originalNow = Date.now;
	let now = 10_000;
	const deadline = now + 100;
	const calls: Array< { timeout: number | undefined; url: string } > = [];
	const bodies: Record< string, unknown > = {
		'/wp-json/wc/v3/orders/42': order(),
		'/wp-json/wc/v3/payments/payment_intents/pi_e2e': intent( {
			status: 'processing',
		} ),
		'/wp-json/wc/v3/payments/charges/ch_e2e': charge(),
		'/wp-json/wc/v3/payments/timeline/pi_e2e': timeline(),
	};
	const restApi = {
		get: async (
			url: string,
			options?: { timeout?: number }
		): Promise< APIResponse > => {
			calls.push( { url, timeout: options?.timeout } );
			now += 40;
			return response( { body: bodies[ url ] } );
		},
	} as APIRequestContext;
	Date.now = () => now;

	try {
		await expect(
			waitForPaymentState(
				restApi,
				{ orderId: 42, intentId: 'pi_e2e' },
				'succeeded',
				deadline
			)
		).rejects.toThrow( /deadline/i );
		expect( calls ).toEqual( [
			{
				url: '/wp-json/wc/v3/orders/42',
				timeout: 100,
			},
			{
				url: '/wp-json/wc/v3/payments/payment_intents/pi_e2e',
				timeout: 60,
			},
			{
				url: '/wp-json/wc/v3/payments/charges/ch_e2e',
				timeout: 20,
			},
		] );
	} finally {
		Date.now = originalNow;
	}
} );

test( 'reports charge capture state and a distinct capture-event count', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [
				{ body: order( { status: 'on-hold' } ) },
			],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent( { status: 'requires_capture' } ) },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [
				{
					body: charge( {
						status: 'succeeded',
						captured: false,
					} ),
				},
			],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [
				{ body: timeline( { data: [] } ) },
			],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).resolves.toMatchObject( {
		providerStatus: 'requires_capture',
		chargeStatus: 'succeeded',
		chargeCaptured: false,
		occurrenceCount: 1,
		captureOccurrenceCount: 0,
	} );
} );

test( 'rejects malformed charge capture state', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent() },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [
				{ body: charge( { captured: 'yes' } ) },
			],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [ { body: timeline() } ],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).rejects.toThrow(
		/captured.*boolean/i
	);
} );

test( 'counts capture events independently from charge occurrences', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent() },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
			'/wp-json/wc/v3/payments/timeline/pi_e2e': [
				{
					body: timeline( {
						data: [
							{ id: 'evt_capture_1', type: 'captured' },
							{ id: 'evt_capture_2', type: 'captured' },
						],
					} ),
				},
			],
		},
		[]
	);

	await expect( getPaymentEvidence( restApi, 42 ) ).resolves.toMatchObject( {
		occurrenceCount: 1,
		captureOccurrenceCount: 2,
	} );
} );

test( 'proves exactly one Core capture-success note for the same intent', async () => {
	const calls: string[] = [];
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42/notes?context=edit&per_page=100': [
				{
					body: [
						{
							id: 91,
							note: 'A payment of <span>$10.99</span> USD was <strong>successfully captured</strong> using WooPayments (<a href="https://example.test/transaction/pi_e2e">pi_e2e</a>).',
						},
						{
							id: 90,
							note: 'Payment status changed from On hold to Processing.',
						},
					],
				},
			],
		},
		calls
	);

	await expect(
		getCaptureOrderNoteEvidence( restApi, {
			orderId: 42,
			intentId: 'pi_e2e',
		} )
	).resolves.toEqual( {
		captureNoteCount: 1,
		captureNote:
			'A payment of <span>$10.99</span> USD was <strong>successfully captured</strong> using WooPayments (<a href="https://example.test/transaction/pi_e2e">pi_e2e</a>).',
	} );
	expect( calls ).toEqual( [
		'/wp-json/wc/v3/orders/42/notes?context=edit&per_page=100',
	] );
} );

test( 'does not accept a loose status message in place of the exact capture note', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42/notes?context=edit&per_page=100': [
				{
					body: [
						{ id: 92, note: 'Payment captured.' },
						{
							id: 91,
							note: 'Order status changed to Processing.',
						},
					],
				},
			],
		},
		[]
	);

	await expect(
		getCaptureOrderNoteEvidence( restApi, {
			orderId: 42,
			intentId: 'pi_e2e',
		} )
	).resolves.toEqual( {
		captureNoteCount: 0,
		captureNote: '',
	} );
} );

test( 'exposes duplicate exact capture notes instead of collapsing them', async () => {
	const exactNote =
		'A payment of $10.99 USD was <strong>successfully captured</strong> using WooPayments (<a href="https://example.test/pi_e2e">pi_e2e</a>).';
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42/notes?context=edit&per_page=100': [
				{
					body: [
						{ id: 92, note: exactNote },
						{ id: 91, note: exactNote },
					],
				},
			],
		},
		[]
	);

	await expect(
		getCaptureOrderNoteEvidence( restApi, {
			orderId: 42,
			intentId: 'pi_e2e',
		} )
	).resolves.toEqual( {
		captureNoteCount: 2,
		captureNote: '',
	} );
} );
