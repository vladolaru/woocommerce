/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsDisputeDetailsRedirect } from '../money-movement/dispute-details';
import { getWooPaymentsDispute } from '../money-movement/data';
import type { WooPaymentsDispute } from '../money-movement/types';
import { getSettingsPaymentsProviderAdminPath } from '../utils';
import { shellHistory } from './helpers/settings-shell-history';

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsDispute: jest.fn(),
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () =>
		jest.requireActual( './helpers/settings-shell-history' ).shellHistory,
} ) );

const mockCreateInfoNotice = jest.fn();

// Records the redirect's notices; other stores keep the real dispatch.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: ( store: string ) =>
			store === 'core/notices'
				? {
						createInfoNotice: (
							message: string,
							options: unknown
						) => mockCreateInfoNotice( message, options ),
				  }
				: actual.dispatch( store ),
	};
} );

const mockGetDispute = getWooPaymentsDispute as jest.MockedFunction<
	typeof getWooPaymentsDispute
>;
const mockAssign = jest.fn();
const originalLocation = window.location;
const FALLBACK_NOTICE =
	"We couldn't open that dispute directly. Find it in your disputes list below.";

const renderRedirect = ( search = '?id=dp_test' ) =>
	render(
		<MemoryRouter
			initialEntries={ [ `/woopayments/disputes/details${ search }` ] }
		>
			<WooPaymentsDisputeDetailsRedirect />
		</MemoryRouter>
	);

// Client 11.1.0 `disputes/redirect-to-transaction-details/index.tsx:60-111`: while the dispute loads, a spinner with
// "One moment please" and "Redirecting…"; then an in-app `getHistory().replace()`, so Back skips this route; a fall
// back to the disputes list explains itself in a snackbar.
describe( 'WooPaymentsDisputeDetailsRedirect', () => {
	beforeAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, assign: mockAssign },
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

	const expectReplacedWith = async ( route: string ) => {
		await waitFor( () =>
			expect( shellHistory.replace ).toHaveBeenCalledWith(
				getSettingsPaymentsProviderAdminPath( route )
			)
		);
		expect( shellHistory.push ).not.toHaveBeenCalled();
		expect( mockAssign ).not.toHaveBeenCalled();
	};

	it( 'shows the client spinner while the dispute loads', () => {
		mockGetDispute.mockReturnValue( new Promise( () => {} ) );

		renderRedirect();

		expect( screen.getByText( 'One moment please' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Redirecting…' ) ).toBeInTheDocument();
		expect( shellHistory.replace ).not.toHaveBeenCalled();
	} );

	it( 'falls back to the disputes list, with a notice, when the dispute request fails', async () => {
		mockGetDispute.mockRejectedValue( new Error( 'Dispute unavailable.' ) );

		renderRedirect();

		await expectReplacedWith( '/woopayments/disputes' );
		expect( mockCreateInfoNotice ).toHaveBeenCalledWith( FALLBACK_NOTICE, {
			type: 'snackbar',
		} );
	} );

	it( 'falls back to the disputes list, with a notice, when the dispute has no transaction reference', async () => {
		mockGetDispute.mockResolvedValue( { id: 'dp_test' } );

		renderRedirect();

		await expectReplacedWith( '/woopayments/disputes' );
		expect( mockCreateInfoNotice ).toHaveBeenCalledWith( FALLBACK_NOTICE, {
			type: 'snackbar',
		} );
	} );

	it( 'replaces the route with the disputes list when the route has no resource identifier', async () => {
		renderRedirect( '' );

		await expectReplacedWith( '/woopayments/disputes' );
		expect( mockGetDispute ).not.toHaveBeenCalled();
	} );

	it( 'replaces the route with the transaction details of a resolved dispute', async () => {
		mockGetDispute.mockResolvedValue( {
			id: 'dp_test',
			payment_intent: 'pi_test',
			charge: { balance_transaction: 'txn_test' },
		} );

		renderRedirect();

		await expectReplacedWith(
			'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test'
		);
		expect( mockCreateInfoNotice ).not.toHaveBeenCalled();
	} );

	it( 'replaces the route with transaction details when a resolved dispute has a charge reference', async () => {
		mockGetDispute.mockResolvedValue( {
			id: 'dp_test',
			charge_id: 'ch_test',
		} );

		renderRedirect();

		await expectReplacedWith(
			'/woopayments/transactions/details?id=ch_test'
		);
		expect( mockCreateInfoNotice ).not.toHaveBeenCalled();
	} );

	it( 'replaces direct legacy charge links without fetching a dispute', async () => {
		renderRedirect( '?charge_id=ch_direct' );

		await expectReplacedWith(
			'/woopayments/transactions/details?id=ch_direct'
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

		expect( shellHistory.replace ).not.toHaveBeenCalled();
		expect( mockAssign ).not.toHaveBeenCalled();
	} );
} );
