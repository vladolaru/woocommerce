import { expect, test } from '@playwright/test';

import type { APIRequestContext } from '@playwright/test';

import { ResourceQuarantineRequiredError } from '../resource-locks';
import { confirmOffSessionAuthentication } from './off-session-authentication';

const PINNED_STATUS = 'requires_action';
const AMOUNT_MINOR = 2099;
const CURRENCY = 'usd';

interface RecordedRequest {
	url: string;
	form: Record< string, unknown >;
}

function response( body: unknown, status = 200 ) {
	return {
		ok: () => status >= 200 && status < 300,
		status: () => status,
		json: async () => body,
		text: async () => JSON.stringify( body ),
	};
}

function providerApi( body: unknown, status = 200 ) {
	const requests: RecordedRequest[] = [];
	const api = {
		post: async (
			url: string,
			options: { form: Record< string, unknown > }
		) => {
			requests.push( { url, form: options.form } );
			return response( body, status );
		},
	} as unknown as APIRequestContext;

	return { api, requests };
}

function intent( overrides: Record< string, unknown > = {} ) {
	return {
		id: 'pi_exact',
		status: PINNED_STATUS,
		amount: AMOUNT_MINOR,
		currency: CURRENCY,
		next_action: { type: 'use_stripe_sdk' },
		...overrides,
	};
}

function confirm( body: unknown, status = 200 ) {
	const { api, requests } = providerApi( body, status );
	return {
		requests,
		result: confirmOffSessionAuthentication( {
			api,
			amountMinor: AMOUNT_MINOR,
			currency: CURRENCY,
			expectedStatus: PINNED_STATUS,
		} ),
	};
}

test( 'a refused off-session intent yields evidence of the refusal', async () => {
	const { result, requests } = confirm( intent() );

	await expect( result ).resolves.toEqual( {
		intentId: 'pi_exact',
		status: PINNED_STATUS,
		nextActionType: 'use_stripe_sdk',
		amountMinor: AMOUNT_MINOR,
		currency: CURRENCY,
		settled: false,
		chargeId: null,
	} );
	expect( requests ).toHaveLength( 1 );
	expect( requests[ 0 ].form ).toMatchObject( {
		payment_method: 'pm_card_authenticationRequired',
		confirm: 'true',
		off_session: 'true',
		amount: AMOUNT_MINOR,
		currency: CURRENCY,
	} );
} );

test( 'an intent that settles fails instead of passing', async () => {
	const { result } = confirm( intent( { status: 'succeeded' } ) );

	await expect( result ).rejects.toThrow( /but the intent settled/ );
} );

test( 'an intent with no next action fails', async () => {
	const { result } = confirm( intent( { next_action: null } ) );

	await expect( result ).rejects.toThrow( /carried no next action/ );
} );

test( 'a next action of another type fails', async () => {
	const { result } = confirm( {
		...intent(),
		next_action: { type: 'redirect_to_url' },
	} );

	await expect( result ).rejects.toThrow( /returned redirect_to_url/ );
} );

test( 'a status other than the pinned one fails rather than being accepted', async () => {
	const { result } = confirm( intent( { status: 'requires_confirmation' } ) );

	await expect( result ).rejects.toThrow(
		/expected the pinned status requires_action, but the provider returned requires_confirmation/
	);
} );

test( 'a charge on a refused intent fails', async () => {
	const { result } = confirm( intent( { latest_charge: 'ch_unexpected' } ) );

	await expect( result ).rejects.toThrow( /reported one/ );
} );

test( 'an amount or currency that differs from the request fails', async () => {
	await expect( confirm( intent( { amount: 100 } ) ).result ).rejects.toThrow(
		/but the intent carried 100 usd/
	);
	await expect(
		confirm( intent( { currency: 'eur' } ) ).result
	).rejects.toThrow( /but the intent carried 2099 eur/ );
} );

test( 'a provider error is surfaced with its status and body', async () => {
	const { result } = confirm( { error: { message: 'nope' } }, 402 );

	await expect( result ).rejects.toThrow( /HTTP 402/ );
} );

test( 'expecting a settled status is rejected before any provider call', async () => {
	const { api, requests } = providerApi( intent() );

	await expect(
		confirmOffSessionAuthentication( {
			api,
			amountMinor: AMOUNT_MINOR,
			currency: CURRENCY,
			expectedStatus: 'succeeded',
		} )
	).rejects.toThrow( /cannot expect a settled intent/ );
	expect( requests ).toHaveLength( 0 );
} );

test( 'a non-positive or fractional amount is rejected before any provider call', async () => {
	const { api, requests } = providerApi( intent() );

	for ( const amountMinor of [ 0, -1, 10.5 ] ) {
		await expect(
			confirmOffSessionAuthentication( {
				api,
				amountMinor,
				currency: CURRENCY,
				expectedStatus: PINNED_STATUS,
			} )
		).rejects.toThrow( /positive integer minor-unit amount/ );
	}
	expect( requests ).toHaveLength( 0 );
} );

test( 'post-request anomalies quarantine rather than fail plainly', async () => {
	// The confirmation request is itself a provider write, so every outcome
	// observed after it leaves state that must be accounted for.
	const cases: Array< [ string, unknown, number ] > = [
		[ 'a settled intent', intent( { status: 'succeeded' } ), 200 ],
		[ 'an unexpected charge', intent( { latest_charge: 'ch_x' } ), 200 ],
		[
			'a mismatched status',
			intent( { status: 'requires_capture' } ),
			200,
		],
		[ 'a missing next action', intent( { next_action: null } ), 200 ],
		[ 'a provider error response', { error: { message: 'no' } }, 402 ],
	];

	for ( const [ label, body, status ] of cases ) {
		const error = await confirm( body, status ).result.catch(
			( thrown: unknown ) => thrown
		);

		expect( error, label ).toBeInstanceOf(
			ResourceQuarantineRequiredError
		);
		expect(
			( error as ResourceQuarantineRequiredError ).reasonCode,
			label
		).toBe( 'uncertain-provider-write' );
	}
} );

test( 'pre-request rejections stay plain errors', async () => {
	const { api } = providerApi( intent() );

	for ( const bad of [
		{ amountMinor: 0, expectedStatus: PINNED_STATUS },
		{ amountMinor: AMOUNT_MINOR, expectedStatus: 'succeeded' },
	] ) {
		const error = await confirmOffSessionAuthentication( {
			api,
			currency: CURRENCY,
			...bad,
		} ).catch( ( thrown: unknown ) => thrown );

		expect( error ).toBeInstanceOf( Error );
		expect( error ).not.toBeInstanceOf( ResourceQuarantineRequiredError );
	}
} );

test( 'currency casing from the caller does not fail a matching intent', async () => {
	const { api } = providerApi( intent() );

	const evidence = await confirmOffSessionAuthentication( {
		api,
		amountMinor: AMOUNT_MINOR,
		currency: 'USD',
		expectedStatus: PINNED_STATUS,
	} );

	// Evidence records what the provider returned, not what the caller typed.
	expect( evidence.currency ).toBe( 'usd' );
} );
