/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsDisputeDetailsRedirect } from '../money-movement/dispute-details';
import { getWooPaymentsDispute } from '../money-movement/data';
import type { WooPaymentsDispute } from '../money-movement/types';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsDispute: jest.fn(),
} ) );

const mockGetDispute = getWooPaymentsDispute as jest.MockedFunction<
	typeof getWooPaymentsDispute
>;
const mockAssign = jest.fn();
const originalLocation = window.location;

const renderRedirect = ( search = '?id=dp_test' ) =>
	render(
		<MemoryRouter
			initialEntries={ [ `/woopayments/disputes/details${ search }` ] }
		>
			<WooPaymentsDisputeDetailsRedirect />
		</MemoryRouter>
	);

describe( 'WooPaymentsDisputeDetailsRedirect', () => {
	beforeAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { assign: mockAssign },
		} );
	} );

	afterAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: originalLocation,
		} );
	} );

	beforeEach( () => {
		jest.clearAllMocks();
		window.wcSettings = {
			...window.wcSettings,
			adminUrl: 'https://example.com/wp-admin',
		};
	} );

	it( 'redirects to the disputes list when the dispute request fails', async () => {
		mockGetDispute.mockRejectedValue( new Error( 'Dispute unavailable.' ) );

		renderRedirect();

		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderRouteUrl( '/woopayments/disputes' )
			)
		);
	} );

	it( 'redirects to the disputes list when the dispute has no transaction reference', async () => {
		mockGetDispute.mockResolvedValue( { id: 'dp_test' } );

		renderRedirect();

		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderRouteUrl( '/woopayments/disputes' )
			)
		);
	} );

	it( 'redirects to the disputes list when the route has no resource identifier', async () => {
		renderRedirect( '' );

		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderRouteUrl( '/woopayments/disputes' )
			)
		);
		expect( mockGetDispute ).not.toHaveBeenCalled();
	} );

	it( 'preserves transaction details for a resolved dispute', async () => {
		mockGetDispute.mockResolvedValue( {
			id: 'dp_test',
			payment_intent: 'pi_test',
			charge: { balance_transaction: { id: 'txn_test' } },
		} );

		renderRedirect();

		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderRouteUrl(
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test'
				)
			)
		);
	} );

	it( 'preserves transaction details when a resolved dispute has a charge reference', async () => {
		mockGetDispute.mockResolvedValue( {
			id: 'dp_test',
			charge_id: 'ch_test',
		} );

		renderRedirect();

		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderRouteUrl(
					'/woopayments/transactions/details?id=ch_test'
				)
			)
		);
	} );

	it( 'preserves direct legacy charge links without fetching a dispute', async () => {
		renderRedirect( '?charge_id=ch_direct' );

		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderRouteUrl(
					'/woopayments/transactions/details?id=ch_direct'
				)
			)
		);
		expect( mockGetDispute ).not.toHaveBeenCalled();
	} );

	it( 'does not navigate when the dispute resolves after unmount', async () => {
		let resolveDispute:
			| ( ( dispute: WooPaymentsDispute ) => void )
			| undefined;
		const disputePromise = new Promise< WooPaymentsDispute >(
			( resolve ) => {
				resolveDispute = resolve;
			}
		);
		mockGetDispute.mockReturnValue( disputePromise );
		const mountedRedirect = renderRedirect();
		mountedRedirect.unmount();

		if ( ! resolveDispute ) {
			throw new Error(
				'The dispute promise did not expose its resolver.'
			);
		}

		await act( async () => {
			resolveDispute( { id: 'dp_test', charge_id: 'ch_test' } );
			await disputePromise;
		} );

		expect( mockAssign ).not.toHaveBeenCalled();
	} );
} );
