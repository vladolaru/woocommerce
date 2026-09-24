/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsPayouts } from '../payouts';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
} from '../overview/data';
import type { WooPaymentsDeposit } from '../overview/types';

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
	}: {
		data?: WooPaymentsDeposit[];
		fields?: Array< {
			id: string;
			render?: ( props: { item: WooPaymentsDeposit } ) => ReactNode;
		} >;
	} ) => (
		<div role="table">
			{ data.map( ( item ) => (
				<div role="row" key={ item.id }>
					{ fields.map( ( field ) => (
						<div key={ field.id }>
							{ field.render?.( { item } ) }
						</div>
					) ) }
				</div>
			) ) }
		</div>
	),
} ) );

const mockGetDeposits = getWooPaymentsDeposits as jest.MockedFunction<
	typeof getWooPaymentsDeposits
>;
const mockGetDepositsSummary =
	getWooPaymentsDepositsSummary as jest.MockedFunction<
		typeof getWooPaymentsDepositsSummary
	>;

describe( 'WooPaymentsPayouts', () => {
	beforeEach( () => {
		mockGetDeposits.mockReset();
		mockGetDepositsSummary.mockReset();
		window.localStorage.clear();
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.com/wp-admin/' },
		} );
	} );

	it( 'renders the scoped payout row and total using the same paid-status query', async () => {
		mockGetDeposits.mockResolvedValue( {
			data: [
				{
					id: 'po_paid',
					date: '2026-07-20',
					type: 'standard',
					status: 'paid',
					amount: 2500,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetDepositsSummary.mockResolvedValue( {
			count: 1,
			total: 2500,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts?status_is=paid' ] }
			>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		const payoutLink = await screen.findByRole( 'link', {
			name: /view payout details for po_paid/,
		} );
		const row = payoutLink.closest( '[role="row"]' );
		if ( ! row ) {
			throw new Error( 'The paid payout has no row.' );
		}
		expect( within( row ).getByText( 'Paid' ) ).toBeInTheDocument();
		expect( within( row ).getByText( '$25.00' ) ).toBeInTheDocument();
		expect( screen.getByText( '1 payouts' ) ).toBeInTheDocument();
		expect(
			screen.getByText( '$25.00', { selector: 'span' } )
		).toBeInTheDocument();
		expect( screen.queryByText( 'po_pending' ) ).not.toBeInTheDocument();
		expect( mockGetDeposits ).toHaveBeenCalledWith(
			expect.objectContaining( { status_is: 'paid' } )
		);
		expect( mockGetDepositsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( { status_is: 'paid' } )
		);
	} );

	it( 'renders only the pending payout returned for a pending-status query', async () => {
		mockGetDeposits.mockResolvedValue( {
			data: [
				{
					id: 'po_pending',
					date: '2026-07-21',
					type: 'standard',
					status: 'pending',
					amount: 700,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetDepositsSummary.mockResolvedValue( {
			count: 1,
			total: 700,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts?status_is=pending' ] }
			>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		const payoutLink = await screen.findByRole( 'link', {
			name: /view payout details for po_pending/,
		} );
		const row = payoutLink.closest( '[role="row"]' );
		if ( ! row ) {
			throw new Error( 'The pending payout has no row.' );
		}
		expect( within( row ).getByText( 'Pending' ) ).toBeInTheDocument();
		expect( within( row ).getByText( '$7.00' ) ).toBeInTheDocument();
		expect( screen.getByText( '1 payouts' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'po_paid' ) ).not.toBeInTheDocument();
		expect( mockGetDeposits ).toHaveBeenCalledWith(
			expect.objectContaining( { status_is: 'pending' } )
		);
		expect( mockGetDepositsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( { status_is: 'pending' } )
		);
	} );
} );
