/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { WooPaymentsOverviewPage } from '../overview/page';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsDisputeReadiness,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';
import { createRecordedOverviewShell } from './helpers/overview-shell';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock(
	'../overview/components/stripe-notifications-banner',
	() => () => null
);

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsDisputeReadiness: jest.fn(),
	getWooPaymentsOverviewDisputes: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
	getWooPaymentsRecentDeposits: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// Client 11.1.0 `components/account-details/index.tsx:45-52,96-97`: the server sends null when the platform's details are missing or invalid.
describe( 'WooPayments Overview account details without platform details', () => {
	beforeEach( () => {
		( useSelect as jest.Mock ).mockReturnValue( {
			isError: false,
			isLoading: false,
			notes: [],
		} );
		( getWooPaymentsDepositsOverview as jest.Mock ).mockReturnValue(
			new Promise( () => {} )
		);
		( getWooPaymentsRecentDeposits as jest.Mock ).mockResolvedValue( {
			data: [],
		} );
		( getWooPaymentsDisputeReadiness as jest.Mock ).mockResolvedValue( {
			overview: { enabled: false },
		} );
	} );

	it( 'shows the client error card instead of dropping the card', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell( {}, { account_details: null } )
		);

		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Account details' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Error loading account details.' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Payouts:' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the error card for rejected accounts too, which keep the card', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell(
				{ status: 'rejected.other' },
				{ account_details: null }
			)
		);

		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByText( 'Error loading account details.' )
		).toBeInTheDocument();
	} );

	it( 'renders the platform details, not the error, when they are present', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell()
		);

		render( <WooPaymentsOverviewPage /> );

		expect( await screen.findByText( 'Payouts:' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Error loading account details.' )
		).not.toBeInTheDocument();
	} );
} );
