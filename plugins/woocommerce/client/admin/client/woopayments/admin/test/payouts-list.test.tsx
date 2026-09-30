/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen } from '@testing-library/react';
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
import { setMockUserPreferences } from './helpers/user-preferences';

type MockField = {
	id: string;
	label?: ReactNode;
	enableHiding?: boolean;
	enableSorting?: boolean;
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
};

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	// The payouts page notices' requests; left pending, so no notice shows.
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
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

let mockFields: MockField[] = [];

// Renders each cell under its field id, so a test reads a cell by column.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: MockField[];
		view?: { fields?: string[] };
	} ) => {
		mockFields = fields;
		const visible = fields.filter( ( field ) =>
			( view.fields || [] ).includes( field.id )
		);

		return (
			<div
				data-testid="payouts-dataviews"
				data-visible-fields={ ( view.fields || [] ).join( ',' ) }
			>
				{ data.map( ( item ) => (
					<div
						role="row"
						key={ String( item.id ) }
						data-payout-id={ String( item.id ) }
					>
						{ visible.map( ( field ) => (
							<div
								role="cell"
								key={ field.id }
								data-field={ field.id }
							>
								{ field.render?.( { item } ) }
							</div>
						) ) }
					</div>
				) ) }
			</div>
		);
	},
} ) );

const mockGetDeposits = getWooPaymentsDeposits as jest.MockedFunction<
	typeof getWooPaymentsDeposits
>;
const mockGetSummary = getWooPaymentsDepositsSummary as jest.MockedFunction<
	typeof getWooPaymentsDepositsSummary
>;

// Recorded native :8889 list rows; see the file's `_meta`.
const RECORDED = JSON.parse(
	fs.readFileSync(
		path.join( __dirname, 'fixtures/recorded-payouts-list.json' ),
		'utf8'
	)
).response.data as Array< Record< string, unknown > >;

// Client 11.1.0 `deposits/list/index.tsx:41-94`, in order, without the info-button column.
const CLIENT_COLUMNS = [
	[ 'date', 'Date' ],
	[ 'type', 'Type' ],
	[ 'amount', 'Amount' ],
	[ 'status', 'Status' ],
	[ 'bankAccount', 'Bank account' ],
	[ 'bankReferenceId', 'Bank reference ID' ],
];

const getCell = ( payoutId: unknown, field: string ) =>
	(
		document.querySelector(
			`[data-payout-id="${ String( payoutId ) }"]`
		) as HTMLElement
	 ).querySelector( `[data-field="${ field }"]` ) as HTMLElement;

describe( 'WooPayments payouts list columns', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
		window.wcSettings = { adminUrl: 'https://example.com/wp-admin/' };
		mockGetDeposits.mockReset();
		mockGetSummary.mockReset();
		mockGetDeposits.mockResolvedValue( {
			data: RECORDED as never,
			total_count: 2,
		} );
		mockGetSummary.mockResolvedValue( { count: 2 } );
	} );

	it( "defines the client's columns, all shown, with its required and sortable flags", async () => {
		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		await screen.findByText( 'Payout history loaded.' );
		expect(
			mockFields.map( ( field ) => [ field.id, field.label ] )
		).toEqual( CLIENT_COLUMNS );
		// Client `required: true`: Date, Type, Amount and Status.
		expect(
			mockFields
				.filter( ( field ) => field.enableHiding === false )
				.map( ( field ) => field.id )
		).toEqual( [ 'date', 'type', 'amount', 'status' ] );
		// Client `isSortable`: Date and Amount.
		expect(
			mockFields
				.filter( ( field ) => field.enableSorting !== false )
				.map( ( field ) => field.id )
		).toEqual( [ 'date', 'amount' ] );
		// No client column is `visible: false`.
		expect( screen.getByTestId( 'payouts-dataviews' ) ).toHaveAttribute(
			'data-visible-fields',
			CLIENT_COLUMNS.map( ( [ id ] ) => id ).join( ',' )
		);
	} );

	it( 'renders the type, bank account and bank reference from recorded platform rows', async () => {
		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		await screen.findByText( 'Payout history loaded.' );
		const [ latest ] = RECORDED;
		expect( getCell( latest.id, 'type' ) ).toHaveTextContent( 'Payout' );
		expect( getCell( latest.id, 'amount' ) ).toHaveTextContent( '$59.62' );
		expect( getCell( latest.id, 'bankAccount' ) ).toHaveTextContent(
			'STRIPE TEST BANK •••• 6789 (USD)'
		);
		expect( getCell( latest.id, 'bankReferenceId' ) ).toHaveTextContent(
			'7UF6L35gB5by3c28997Ds6ch6t65Lg5Y660T3gl1k'
		);
	} );
} );
