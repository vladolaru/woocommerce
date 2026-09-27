/**
 * External dependencies
 */
import { act, renderHook, waitFor } from '@testing-library/react';
import { dispatch } from '@wordpress/data';
import { userStore } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { usePersistedHiddenFields } from '../money-movement/view-preferences';

// Stubbed at the network layer so any copy of api-fetch core-data uses is seen.
const mockFetch = jest.fn();
const AUTHORIZATION_FIELDS = [ 'authorized_date', 'order', 'risk', 'amount' ];
const CLIENT_COLUMN_KEYS = { authorized_date: 'created', risk: 'risk_level' };

// Mirrors what withCurrentUserHydration() does with wcSettings.currentUserData.
const hydrateCurrentUser = ( woocommerceMeta: Record< string, string > ) => {
	const store = dispatch( userStore ) as unknown as Record<
		string,
		( ...args: unknown[] ) => void
	>;

	store.startResolution( 'getCurrentUser', [] );
	store.receiveCurrentUser( { id: 7, woocommerce_meta: woocommerceMeta } );
	store.finishResolution( 'getCurrentUser', [] );
};

const renderUncapturedHook = () =>
	renderHook( () =>
		usePersistedHiddenFields(
			'wc_payments_transactions_uncaptured_hidden_columns',
			AUTHORIZATION_FIELDS,
			CLIENT_COLUMN_KEYS
		)
	);

describe( 'WooPayments list hidden columns', () => {
	let getItemSpy: jest.SpyInstance;
	let setItemSpy: jest.SpyInstance;

	beforeEach( () => {
		mockFetch.mockReset();
		mockFetch.mockImplementation(
			( url: string, options: { body?: string } ) =>
				Promise.resolve( {
					ok: true,
					status: 200,
					headers: new Headers(),
					json: () =>
						Promise.resolve( {
							id: 7,
							...JSON.parse( String( options.body || '{}' ) ),
						} ),
				} )
		);
		globalThis.fetch = mockFetch;
		getItemSpy = jest.spyOn( Storage.prototype, 'getItem' );
		setItemSpy = jest.spyOn( Storage.prototype, 'setItem' );
	} );

	afterEach( () => {
		getItemSpy.mockRestore();
		setItemSpy.mockRestore();
	} );

	it( "restores the client's stored hidden columns on load without a request or a write", () => {
		hydrateCurrentUser( {
			wc_payments_transactions_uncaptured_hidden_columns: JSON.stringify(
				[ 'risk_level', 'customer_email' ]
			),
		} );

		const { result } = renderUncapturedHook();

		expect( result.current.visibleFields ).toEqual( [
			'authorized_date',
			'order',
			'amount',
		] );
		expect( mockFetch ).not.toHaveBeenCalled();
		expect( getItemSpy ).not.toHaveBeenCalled();
		expect( setItemSpy ).not.toHaveBeenCalled();
	} );

	it( 'shows the default fields when no preference is stored', () => {
		hydrateCurrentUser( {
			wc_payments_transactions_uncaptured_hidden_columns: '',
		} );

		expect( renderUncapturedHook().result.current.visibleFields ).toEqual(
			AUTHORIZATION_FIELDS
		);
	} );

	it( "saves hidden columns to user meta under the client's key and column names", async () => {
		hydrateCurrentUser( {
			wc_payments_transactions_uncaptured_hidden_columns: JSON.stringify(
				[ 'customer_email' ]
			),
		} );
		const { result } = renderUncapturedHook();

		await act( async () => {
			result.current.saveFields( [ 'order', 'amount' ] );
		} );

		// Core's saveUser() also loads entity config and re-reads the user, as it does for the client.
		const writes = () =>
			mockFetch.mock.calls.filter(
				( [ , request ] ) => request?.method === 'POST'
			);
		await waitFor( () => expect( writes() ).toHaveLength( 1 ) );
		const [ url, request ] = writes()[ 0 ];
		expect( url ).toContain( '/wp/v2/users/7' );
		expect( JSON.parse( request.body ) ).toEqual( {
			id: 7,
			woocommerce_meta: {
				wc_payments_transactions_uncaptured_hidden_columns:
					JSON.stringify( [
						'customer_email',
						'created',
						'risk_level',
					] ),
			},
		} );
		expect( result.current.visibleFields ).toEqual( [ 'order', 'amount' ] );
		expect( getItemSpy ).not.toHaveBeenCalled();
		expect( setItemSpy ).not.toHaveBeenCalled();
	} );

	it( 'does not write when a view change leaves the hidden columns as they are', async () => {
		hydrateCurrentUser( {
			wc_payments_transactions_uncaptured_hidden_columns: JSON.stringify(
				[ 'risk_level', 'customer_email' ]
			),
		} );
		const { result } = renderUncapturedHook();

		await act( async () => {
			result.current.saveFields( [
				'amount',
				'order',
				'authorized_date',
			] );
		} );

		expect( mockFetch ).not.toHaveBeenCalled();
	} );
} );
