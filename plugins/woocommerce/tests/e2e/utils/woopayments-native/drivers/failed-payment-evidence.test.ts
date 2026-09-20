import { expect, test } from '@playwright/test';

import {
	convergeFailedPayment,
	readFailedPaymentEvidence,
	readOrderIdStatusDelta,
	readPaymentReuseEvidence,
	type FailedPaymentEvidence,
} from './failed-payment-evidence';

function response( body: unknown ) {
	return {
		ok: () => true,
		status: () => 200,
		text: async () => JSON.stringify( body ),
		json: async () => body,
	};
}

function failedEvidence(
	overrides: Partial< FailedPaymentEvidence > = {}
): FailedPaymentEvidence {
	return {
		orderStatus: 'failed',
		orderTotal: '10.01',
		orderCurrency: 'USD',
		intentIdMeta: 'pi_failed',
		chargeIdMeta: '',
		intentionStatusMeta: 'requires_payment_method',
		intentId: 'pi_failed',
		paymentMethodId: 'pm_failed',
		intentStatus: 'requires_payment_method',
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
		orderCustomerId: 0,
		localTokenIds: [],
		...overrides,
	};
}

test( 'failed-order discovery converges on order identity and status without provider-link metadata', async () => {
	const replies = [
		[
			{
				id: 71,
				status: 'failed',
				meta_data: [],
			},
		],
		[
			{
				id: 71,
				status: 'failed',
				meta_data: [ { key: '_intent_id', value: 'pi_arrived_later' } ],
			},
		],
	];
	const session = {
		adminApi: {
			get: async () => response( replies.shift() ),
		},
	};
	const delays: number[] = [];

	const delta = await readOrderIdStatusDelta( session as never, 70, {
		delay: async ( milliseconds ) => {
			delays.push( milliseconds );
		},
	} );

	expect( delta ).toEqual( {
		orders: [ { id: 71, status: 'failed' } ],
		newOrderIds: [ 71 ],
		paidOrderIds: [],
	} );
	expect( delays ).toEqual( [ 2_000 ] );
} );

test( 'failed evidence follows a string latest_charge relationship', async () => {
	const calls: string[] = [];
	const replies = new Map< string, unknown >( [
		[
			'/wp-json/wc/v3/orders/71',
			{
				id: 71,
				status: 'failed',
				total: '10.01',
				currency: 'USD',
				meta_data: [
					{ key: '_intent_id', value: 'pi_failed' },
					{
						key: '_intention_status',
						value: 'requires_payment_method',
					},
				],
			},
		],
		[ '/wp-json/wc/v3/orders/71/notes?context=edit&per_page=100', [] ],
		[
			'/wp-json/wc/v3/payments/payment_intents/pi_failed',
			{
				id: 'pi_failed',
				status: 'requires_payment_method',
				amount: 1001,
				currency: 'usd',
				amount_received: 0,
				setup_future_usage: null,
				customer: null,
				payment_method: 'pm_failed',
				latest_charge: 'ch_failed',
				last_payment_error: {
					code: 'card_declined',
					decline_code: 'generic_decline',
					payment_method: { id: 'pm_failed' },
				},
			},
		],
		[
			'/wp-json/wc/v3/payments/charges/ch_failed',
			{ id: 'ch_failed', status: 'failed', captured: false },
		],
	] );
	const session = {
		adminApi: {
			get: async ( path: string ) => {
				calls.push( path );
				expect(
					replies.has( path ),
					`Unexpected read: ${ path }`
				).toBe( true );
				return response( replies.get( path ) );
			},
		},
	};

	const evidence = await readFailedPaymentEvidence( session as never, 71 );

	expect( evidence.chargeIds ).toEqual( [ 'ch_failed' ] );
	expect( evidence.chargeStatuses ).toEqual( [ 'failed' ] );
	expect( evidence.capturedCharges ).toBe( 0 );
	expect( calls ).toContain( '/wp-json/wc/v3/payments/charges/ch_failed' );
} );

test( 'failed evidence rejects a provider intent whose ID differs from the order metadata', async () => {
	const replies = new Map< string, unknown >( [
		[
			'/wp-json/wc/v3/orders/71',
			{
				id: 71,
				status: 'failed',
				total: '10.01',
				currency: 'USD',
				meta_data: [ { key: '_intent_id', value: 'pi_expected' } ],
			},
		],
		[ '/wp-json/wc/v3/orders/71/notes?context=edit&per_page=100', [] ],
		[
			'/wp-json/wc/v3/payments/payment_intents/pi_expected',
			{
				id: 'pi_unrelated',
				status: 'requires_payment_method',
				amount: 1001,
				currency: 'usd',
				amount_received: 0,
				setup_future_usage: null,
				customer: null,
				payment_method: 'pm_failed',
				last_payment_error: {
					code: 'card_declined',
					decline_code: 'generic_decline',
					payment_method: { id: 'pm_failed' },
				},
			},
		],
	] );
	const session = {
		adminApi: {
			get: async ( path: string ) => response( replies.get( path ) ),
		},
	};

	await expect(
		readFailedPaymentEvidence( session as never, 71 )
	).rejects.toThrow(
		'Failed payment evidence intent mismatch: expected pi_expected, received pi_unrelated.'
	);
} );

test( 'failed convergence rejects evidence whose provider intent differs from the order metadata', async () => {
	const evidence = failedEvidence( {
		intentIdMeta: 'pi_expected',
		intentId: 'pi_unrelated',
	} );

	await expect(
		convergeFailedPayment( {} as never, 71, {
			read: async () => evidence,
			delay: async () => {},
			now: () => 1,
		} )
	).rejects.toThrow(
		'Failed payment evidence intent mismatch: expected pi_expected, received pi_unrelated.'
	);
} );

test( 'failed convergence requires the established four-second unchanged quiet window', async () => {
	const evidence = failedEvidence();
	const delays: number[] = [];
	let reads = 0;

	const converged = await convergeFailedPayment( {} as never, 71, {
		read: async () => {
			reads += 1;
			return evidence;
		},
		delay: async ( milliseconds ) => {
			delays.push( milliseconds );
		},
		now: () => 1,
	} );

	expect( converged ).toEqual( evidence );
	expect( reads ).toBe( 3 );
	expect( delays ).toEqual( [ 2_000, 4_000 ] );
} );

test( 'payment reuse evidence reads setup intent, provider attachment, and local tokens exactly', async () => {
	const calls: string[] = [];
	const replies = new Map< string, unknown >( [
		[ '/wp-json/wc/v3/orders/71', { customer_id: 9 } ],
		[ '/wp-json/wc/v3/customers/9', { username: 'recovery-shopper' } ],
		[
			'/wp-json/wc/v3/payments/payment_intents/pi_succeeded',
			{
				id: 'pi_succeeded',
				setup_future_usage: 'off_session',
				customer: { id: 'cus_recovery' },
			},
		],
		[
			'/wp-json/wc-native-payments-e2e/v1/saved-card-evidence?customer_username=recovery-shopper',
			{ tokens: [ { token_id: 44 } ] },
		],
		[
			'/wp-json/wc/v3/payments/customers/cus_recovery/payment_methods',
			[ { id: 'pm_succeeded' } ],
		],
	] );
	const session = {
		adminApi: {
			get: async ( path: string ) => {
				calls.push( path );
				expect(
					replies.has( path ),
					`Unexpected read: ${ path }`
				).toBe( true );
				return response( replies.get( path ) );
			},
		},
	};

	await expect(
		readPaymentReuseEvidence( session as never, 71, 'pi_succeeded' )
	).resolves.toEqual( {
		orderCustomerId: 9,
		setupFutureUsage: 'off_session',
		providerCustomerId: 'cus_recovery',
		providerAttachedPaymentMethodIds: [ 'pm_succeeded' ],
		localTokenIds: [ 44 ],
	} );
	expect( calls ).toContain(
		'/wp-json/wc-native-payments-e2e/v1/saved-card-evidence?customer_username=recovery-shopper'
	);
} );
