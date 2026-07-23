import {
	expect,
	test,
	type APIRequestContext,
	type APIResponse,
} from '@playwright/test';

import { getPaymentEvidence } from './record-evidence';
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
		payment_intent: 'pi_e2e',
		payment_method: 'pm_e2e',
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
		occurrenceCount: 1,
	} );
	expect( calls ).toEqual( [
		'/wp-json/wc/v3/orders/42',
		'/wp-json/wc/v3/payments/payment_intents/pi_e2e',
		'/wp-json/wc/v3/payments/charges/ch_e2e',
	] );
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
	expect( calls ).toHaveLength( 3 );
} );

test( 'fails at the caller deadline without an extra sleep', async () => {
	const restApi = mockRestApi(
		{
			'/wp-json/wc/v3/orders/42': [ { body: order() } ],
			'/wp-json/wc/v3/payments/payment_intents/pi_e2e': [
				{ body: intent( { status: 'processing' } ) },
			],
			'/wp-json/wc/v3/payments/charges/ch_e2e': [ { body: charge() } ],
		},
		[]
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
} );
