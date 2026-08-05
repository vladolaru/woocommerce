import { expect, test } from '@playwright/test';

import { readProviderCardEvidence } from './provider-card-evidence';

function response( body: unknown, status = 200 ) {
	return {
		ok: () => status >= 200 && status < 300,
		status: () => status,
		json: async () => body,
	};
}

function providerApi( body: unknown, status = 200 ) {
	const requests: string[] = [];
	return {
		api: {
			get: async ( url: string ) => {
				requests.push( url );
				return response( body, status );
			},
		},
		requests,
	};
}

const identity = {
	chargeId: 'ch_exact',
	paymentMethodId: 'pm_exact',
};

function charge( overrides: Record< string, unknown > = {} ) {
	return {
		id: identity.chargeId,
		payment_method: identity.paymentMethodId,
		payment_method_details: {
			type: 'card',
			card: {
				brand: 'visa',
				last4: '4242',
			},
		},
		...overrides,
	};
}

test( 'reads only exact public-safe card evidence from the proved charge', async () => {
	const { api, requests } = providerApi( charge() );

	await expect(
		readProviderCardEvidence( api as never, identity )
	).resolves.toEqual( {
		type: 'card',
		brand: 'visa',
		last4: '4242',
	} );
	expect( requests ).toEqual( [
		'/wp-json/wc/v3/payments/charges/ch_exact',
	] );
} );

for ( const providerFailure of [
	{
		name: 'provider request rejection',
		api: {
			get: async () => {
				throw new Error(
					`request /charges/${ identity.chargeId } failed for ${ identity.paymentMethodId } and billing private@example.com`
				);
			},
		},
	},
	{
		name: 'provider response JSON rejection',
		api: {
			get: async () => ( {
				ok: () => true,
				json: async () => {
					throw new Error(
						`raw response ${ identity.chargeId } ${ identity.paymentMethodId } private@example.com`
					);
				},
			} ),
		},
	},
] ) {
	test( `replaces ${ providerFailure.name } with a value-free error`, async () => {
		let failure: Error | undefined;
		try {
			await readProviderCardEvidence(
				providerFailure.api as never,
				identity
			);
		} catch ( error ) {
			failure = error as Error;
		}

		expect( failure ).toBeInstanceOf( Error );
		expect( failure ).not.toHaveProperty( 'cause' );
		const publicFailure = [
			failure?.message,
			failure?.stack,
			JSON.stringify( failure ),
		].join( '\n' );
		for ( const privateValue of [
			identity.chargeId,
			identity.paymentMethodId,
			'/charges/',
			'raw response',
			'private@example.com',
		] ) {
			expect( publicFailure ).not.toContain( privateValue );
		}
	} );
}

for ( const invalidCase of [
	{
		name: 'non-positive response',
		body: charge(),
		status: 503,
	},
	{
		name: 'malformed response',
		body: [],
	},
	{
		name: 'mismatched charge',
		body: charge( { id: 'ch_other' } ),
	},
	{
		name: 'mismatched payment method',
		body: charge( { payment_method: 'pm_other' } ),
	},
	{
		name: 'duplicate payment-method relationship',
		body: charge( {
			payment_method: [
				identity.paymentMethodId,
				identity.paymentMethodId,
			],
		} ),
	},
	{
		name: 'non-card method',
		body: charge( {
			payment_method_details: {
				type: 'link',
				card: { brand: 'visa', last4: '4242' },
			},
		} ),
	},
	{
		name: 'non-Visa card',
		body: charge( {
			payment_method_details: {
				type: 'card',
				card: { brand: 'mastercard', last4: '4242' },
			},
		} ),
	},
	{
		name: 'wrong last4',
		body: charge( {
			payment_method_details: {
				type: 'card',
				card: { brand: 'visa', last4: '4444' },
			},
		} ),
	},
	{
		name: 'multiple card detail records',
		body: charge( {
			payment_method_details: {
				type: 'card',
				card: [
					{ brand: 'visa', last4: '4242' },
					{ brand: 'visa', last4: '4242' },
				],
			},
		} ),
	},
] ) {
	test( `rejects ${ invalidCase.name } without exposing provider IDs`, async () => {
		const { api } = providerApi(
			invalidCase.body,
			invalidCase.status ?? 200
		);

		let failure: Error | undefined;
		try {
			await readProviderCardEvidence( api as never, identity );
		} catch ( error ) {
			failure = error as Error;
		}

		expect( failure ).toBeInstanceOf( Error );
		expect( failure?.message ).not.toContain( identity.chargeId );
		expect( failure?.message ).not.toContain( identity.paymentMethodId );
	} );
}

for ( const invalidIdentity of [
	{
		name: 'missing charge ID',
		chargeId: '',
		paymentMethodId: identity.paymentMethodId,
	},
	{
		name: 'missing payment-method ID',
		chargeId: identity.chargeId,
		paymentMethodId: '',
	},
] ) {
	test( `rejects ${ invalidIdentity.name } before a provider read`, async () => {
		const { api, requests } = providerApi( charge() );

		await expect(
			readProviderCardEvidence( api as never, invalidIdentity )
		).rejects.toThrow( /proved provider identity/i );
		expect( requests ).toEqual( [] );
	} );
}
