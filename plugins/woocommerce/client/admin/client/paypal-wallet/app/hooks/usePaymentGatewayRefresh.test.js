import { renderHook, waitFor, act } from '@testing-library/react';
import { select, dispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

import usePaymentGatewayRefresh from './usePaymentGatewayRefresh';

/*
 * The served store's fix for D1 relies on this verbatim hook: it resets and reloads the payment methods only while the
 * onboarding data reads `gatewaysRefreshed` as false. The real onboarding and payment stores are used; only the REST
 * calls are answered here.
 */
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../data', () => {
	const onboarding = jest.requireActual( '../data/onboarding' );
	const payment = jest.requireActual( '../data/payment' );
	// The payment store's to-do sync reads the to-dos store.
	const todos = jest.requireActual( '../data/todos' );
	onboarding.initStore();
	todos.initStore();
	payment.initStore();

	return { OnboardingHooks: onboarding.hooks };
} );

const ONBOARDING_STORE = 'wc/paypal/onboarding';
const PAYMENT_STORE = 'wc/paypal/payment';
const PAYMENT_PATH = '/wc/v3/wc_paypal/payment';

let onboardingData;

const answerRest = () => {
	apiFetch.mockImplementation( ( { path, method = 'GET' } ) => {
		if ( path.endsWith( '/onboarding' ) ) {
			return Promise.resolve( { success: true, data: onboardingData } );
		}
		if ( path === PAYMENT_PATH && method === 'GET' ) {
			// The stored state: Pay with Crypto off.
			return Promise.resolve( {
				success: true,
				data: { 'ppcp-pwc': { id: 'ppcp-pwc', enabled: false } },
			} );
		}
		return Promise.resolve( { success: true, data: {} } );
	} );
};

const paymentGets = () =>
	apiFetch.mock.calls.filter(
		( [ { path, method = 'GET' } ] ) =>
			path === PAYMENT_PATH && method === 'GET'
	);

const pwcEnabled = () =>
	select( PAYMENT_STORE ).persistentData()[ 'ppcp-pwc' ]?.enabled;

/**
 * Load the onboarding data, then turn Pay with Crypto on in the payment store, as the merchant's unsaved toggle does.
 *
 * @param {boolean} gatewaysRefreshed The onboarding route's answer.
 */
const setUp = async ( gatewaysRefreshed ) => {
	onboardingData = {
		completed: true,
		step: 0,
		products: [],
		gatewaysSynced: true,
		gatewaysRefreshed,
	};
	answerRest();
	await dispatch( ONBOARDING_STORE ).invalidateResolutionForStore();
	await act( async () => {
		await select( ONBOARDING_STORE ).persistentData();
	} );
	await waitFor( () =>
		expect( select( ONBOARDING_STORE ).transientData().isReady ).toBe(
			true
		)
	);
	dispatch( PAYMENT_STORE ).hydrate( {
		data: { 'ppcp-pwc': { id: 'ppcp-pwc', enabled: false } },
	} );
	dispatch( PAYMENT_STORE ).changePaymentSettings( 'ppcp-pwc', {
		enabled: true,
	} );
	apiFetch.mockClear();
};

describe( 'usePaymentGatewayRefresh()', () => {
	test( 'leaves the payment store alone when the onboarding data reads the refresh as done', async () => {
		await setUp( true );
		const reset = jest.spyOn( dispatch( PAYMENT_STORE ), 'reset' );

		const { result } = renderHook( () => usePaymentGatewayRefresh() );
		// Let any effect and request settle.
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 200 ) )
		);

		expect( result.current.gatewaysRefreshed ).toBe( true );
		expect( reset ).not.toHaveBeenCalled();
		expect( paymentGets() ).toHaveLength( 0 );
		expect( pwcEnabled() ).toBe( true );
	} );

	test( 'resets and reloads the payment store, losing the unsaved toggle, when the refresh reads as not done', async () => {
		await setUp( false );

		const { result } = renderHook( () => usePaymentGatewayRefresh() );
		await waitFor( () =>
			expect( result.current.refreshCompleted ).toBe( true )
		);
		await waitFor( () => expect( paymentGets().length ).toBe( 2 ) );
		await waitFor( () => expect( pwcEnabled() ).toBe( false ) );
	} );
} );
