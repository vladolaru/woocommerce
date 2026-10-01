/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { summaryItem } from './helpers/table-summary';
import { WooPaymentsPayouts } from '../payouts';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsOverview,
	getWooPaymentsDepositsSummary,
	getWooPaymentsOverviewShell,
} from '../overview/data';
import type { WooPaymentsDeposit } from '../overview/types';
import {
	mockUpdateUserPreferences,
	setMockUserPreferences,
} from './helpers/user-preferences';
import {
	getTestModeNoticeText,
	mockAccountMode,
} from './helpers/test-mode-account';

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	// The payouts page notices' requests; left pending, so no notice shows.
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
		header,
		view,
		onChangeView,
	}: {
		header?: ReactNode;
		data?: WooPaymentsDeposit[];
		fields?: Array< {
			id: string;
			render?: ( props: { item: WooPaymentsDeposit } ) => ReactNode;
		} >;
		view: { fields?: string[] };
		onChangeView: ( view: { fields?: string[] } ) => void;
	} ) => (
		<div role="table" data-visible-fields={ view.fields?.join( ',' ) }>
			{ header }
			<button
				type="button"
				onClick={ () =>
					// The info column cannot be hidden.
					onChangeView( { ...view, fields: [ 'details', 'date' ] } )
				}
			>
				Mock show only Date
			</button>
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
		setMockUserPreferences( {} );
		mockAccountMode( false );
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
		expect(
			within( row ).getByText( 'Completed (paid)' )
		).toBeInTheDocument();
		expect( within( row ).getByText( '$25.00' ) ).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '1 payout' ) )
		).toBeInTheDocument();
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

	it( "keeps hidden payout columns in the client's user meta key", async () => {
		const getItemSpy = jest.spyOn( Storage.prototype, 'getItem' );
		const setItemSpy = jest.spyOn( Storage.prototype, 'setItem' );
		setMockUserPreferences( {
			wc_payments_payouts_hidden_columns: [ 'status', 'bankAccount' ],
		} );
		mockGetDeposits.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetDepositsSummary.mockResolvedValue( { count: 0 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		expect( await screen.findByRole( 'table' ) ).toHaveAttribute(
			'data-visible-fields',
			'details,date,type,amount,bankReferenceId'
		);
		expect( mockUpdateUserPreferences ).not.toHaveBeenCalled();

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Mock show only Date' } )
			);
		} );

		expect( mockUpdateUserPreferences ).toHaveBeenCalledWith( {
			wc_payments_payouts_hidden_columns: [
				'type',
				'amount',
				'status',
				'bankAccount',
				'bankReferenceId',
			],
		} );
		expect( screen.getByRole( 'table' ) ).toHaveAttribute(
			'data-visible-fields',
			'details,date'
		);
		expect( getItemSpy ).not.toHaveBeenCalled();
		expect( setItemSpy ).not.toHaveBeenCalled();
		getItemSpy.mockRestore();
		setItemSpy.mockRestore();
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
		expect(
			screen.getByText( summaryItem( '1 payout' ) )
		).toBeInTheDocument();
		expect( screen.queryByText( 'po_paid' ) ).not.toBeInTheDocument();
		expect( mockGetDeposits ).toHaveBeenCalledWith(
			expect.objectContaining( { status_is: 'pending' } )
		);
		expect( mockGetDepositsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( { status_is: 'pending' } )
		);
	} );

	// Client 11.1.0 deposits/index.tsx:153.
	it.each( [ true, false ] )(
		'shows the payouts test-mode notice only in test mode (test mode: %s)',
		async ( testMode ) => {
			mockAccountMode( testMode );
			mockGetDeposits.mockResolvedValue( { data: [], total_count: 0 } );
			mockGetDepositsSummary.mockResolvedValue( { count: 0 } );

			render(
				<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
					<WooPaymentsPayouts />
				</MemoryRouter>
			);

			expect( await getTestModeNoticeText() ).toBe(
				testMode
					? 'Viewing test payouts. To view live payouts, disable test mode in WooPayments settings.'
					: null
			);
		}
	);
	// Client 11.1.0 `deposits/index.tsx:61-97,154`: the schedule notice sits above the payouts list.
	it( 'shows the payout schedule notice from the recorded account data', async () => {
		const readRecordedResponse = ( file: string ) =>
			JSON.parse(
				fs.readFileSync(
					path.join( __dirname, 'fixtures', file ),
					'utf8'
				)
			).response;
		( getWooPaymentsDepositsOverview as jest.Mock ).mockResolvedValue(
			readRecordedResponse( 'recorded-deposits-overview-all.json' )
		);
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			readRecordedResponse( 'recorded-overview-shell.json' )
		);
		mockGetDeposits.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetDepositsSummary.mockResolvedValue( { count: 0 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		expect(
			( await screen.findByText( 'every day' ) ).closest(
				'.components-notice'
			)
		).toHaveTextContent(
			'Available funds are automatically dispatched every day.'
		);
	} );
} );
