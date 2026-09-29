/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	WooPaymentsDisputesPage,
	getDisputeRespondBy,
} from '../money-movement/disputes-page';
import {
	getWooPaymentsDisputes,
	getWooPaymentsDisputesSummary,
} from '../money-movement/data';
import { setMockUserPreferences } from './helpers/user-preferences';

type MockField = {
	id: string;
	label?: ReactNode;
	enableHiding?: boolean;
	enableSorting?: boolean;
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
};

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsDisputes: jest.fn(),
	getWooPaymentsDisputesSummary: jest.fn(),
	getWooPaymentsDisputesExportUrl: jest.fn(),
	requestWooPaymentsDisputesExport: jest.fn(),
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
				data-testid="disputes-dataviews"
				data-visible-fields={ ( view.fields || [] ).join( ',' ) }
			>
				<div role="row">
					{ visible.map( ( field ) => (
						<div role="columnheader" key={ field.id }>
							{ field.label }
						</div>
					) ) }
				</div>
				{ data.map( ( item ) => (
					<div
						role="row"
						key={ String( item.dispute_id ) }
						data-dispute-id={ String( item.dispute_id ) }
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

const mockGetDisputes = getWooPaymentsDisputes as jest.MockedFunction<
	typeof getWooPaymentsDisputes
>;
const mockGetSummary = getWooPaymentsDisputesSummary as jest.MockedFunction<
	typeof getWooPaymentsDisputesSummary
>;

// Recorded native :8889 list rows (sanitized); see the file's `_meta`.
const RECORDED = JSON.parse(
	fs.readFileSync(
		path.join( __dirname, 'fixtures/recorded-disputes-list.json' ),
		'utf8'
	)
).response.data as Array< Record< string, unknown > >;
const [ DUE, PAST_DUE, WON ] = RECORDED;

// Client 11.1.0 `disputes/index.tsx:50-148`, in order, without the info-button column.
const CLIENT_COLUMNS = [
	[ 'amount', 'Amount' ],
	[ 'currency', 'Currency' ],
	[ 'status', 'Status' ],
	[ 'reason', 'Reason' ],
	[ 'source', 'Source' ],
	[ 'order', 'Order #' ],
	[ 'customerName', 'Customer' ],
	[ 'customerEmail', 'Email' ],
	[ 'customerCountry', 'Country' ],
	[ 'created', 'Disputed on' ],
	[ 'due_by', 'Respond by' ],
	[ 'action', 'Action' ],
];
// The client's `required: true` columns.
const REQUIRED = [
	'amount',
	'currency',
	'status',
	'reason',
	'source',
	'order',
	'due_by',
	'action',
];
// The client's `isSortable` columns.
const SORTABLE = [ 'amount', 'created', 'due_by' ];

const renderPage = () =>
	render(
		<MemoryRouter initialEntries={ [ '/woopayments/disputes' ] }>
			<WooPaymentsDisputesPage />
		</MemoryRouter>
	);

const getCell = ( disputeId: unknown, field: string ) =>
	(
		document.querySelector(
			`[data-dispute-id="${ String( disputeId ) }"]`
		) as HTMLElement
	 ).querySelector( `[data-field="${ field }"]` ) as HTMLElement;

describe( 'WooPayments disputes list columns', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
		window.wcSettings = { adminUrl: 'http://example.com/wp-admin/' };
		mockGetDisputes.mockReset();
		mockGetSummary.mockReset();
		mockGetDisputes.mockResolvedValue( {
			data: RECORDED as never,
			total_count: 3,
		} );
		mockGetSummary.mockResolvedValue( { count: 3 } );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( "defines the client's columns with its labels, required and sortable flags", async () => {
		renderPage();
		await screen.findByText( 'Disputes loaded.' );

		expect(
			mockFields.map( ( field ) => [ field.id, field.label ] )
		).toEqual( CLIENT_COLUMNS );
		expect(
			mockFields
				.filter( ( field ) => field.enableHiding === false )
				.map( ( field ) => field.id )
		).toEqual( REQUIRED );
		expect(
			mockFields
				.filter( ( field ) => field.enableSorting !== false )
				.map( ( field ) => field.id )
		).toEqual( SORTABLE );
	} );

	it( "hides Currency, Email, Country and Disputed on until a preference is stored, like the client's defaults", async () => {
		renderPage();

		await screen.findByText( 'Disputes loaded.' );
		expect( screen.getByTestId( 'disputes-dataviews' ) ).toHaveAttribute(
			'data-visible-fields',
			'amount,status,reason,source,order,customerName,due_by,action'
		);
	} );

	it( 'renders every column from recorded platform rows', async () => {
		jest.useFakeTimers( {
			now: new Date( '2026-09-29T15:00:00Z' ),
			doNotFake: [ 'setTimeout', 'queueMicrotask', 'nextTick' ],
		} );
		setMockUserPreferences( { wc_payments_disputes_hidden_columns: [] } );
		renderPage();
		await screen.findByText( 'Disputes loaded.' );

		const due = DUE.dispute_id;
		expect( getCell( due, 'amount' ) ).toHaveTextContent( '$50.00' );
		expect( getCell( due, 'currency' ) ).toHaveTextContent( 'usd' );
		expect( getCell( due, 'status' ) ).toHaveTextContent(
			'Needs response'
		);
		expect( getCell( due, 'reason' ) ).toHaveTextContent(
			'Transaction unauthorized'
		);
		expect( getCell( due, 'source' ) ).toHaveTextContent( 'Visa' );
		expect(
			within( getCell( due, 'order' ) ).getByRole( 'link', {
				name: '5506',
			} )
		).toHaveAttribute( 'href', ( DUE.order as { url: string } ).url );
		expect( getCell( due, 'customerName' ) ).toHaveTextContent(
			'Test Shopper'
		);
		expect( getCell( due, 'customerEmail' ) ).toHaveTextContent(
			'shopper-dispute@example.com'
		);
		expect( getCell( due, 'customerCountry' ) ).toHaveTextContent( 'US' );
		expect( getCell( due, 'created' ) ).not.toHaveTextContent( /^-?$/ );
		// Over 72 hours before 2026-10-07 23:59:59 UTC: the due date itself.
		expect( getCell( due, 'due_by' ) ).toHaveTextContent( /2026/ );

		const pastDue = PAST_DUE.dispute_id;
		expect(
			within( getCell( pastDue, 'customerName' ) ).queryByRole( 'link' )
		).not.toBeInTheDocument();
		expect( getCell( pastDue, 'customerCountry' ) ).toHaveTextContent(
			'-'
		);
		expect( getCell( pastDue, 'due_by' ) ).toHaveTextContent( /^$/ );

		const won = WON.dispute_id;
		expect( getCell( won, 'order' ) ).toHaveTextContent( '–' );
		expect( getCell( won, 'status' ) ).toHaveTextContent( 'Won' );
		expect( getCell( won, 'due_by' ) ).toHaveTextContent( /^$/ );
	} );

	it( "counts down the client's last 72 hours to respond", () => {
		const dueAt = Date.parse( '2026-10-07T23:59:59Z' );
		const respondBy = ( hoursLeft: number, dispute = DUE ) => {
			const { container } = render(
				<>
					{ getDisputeRespondBy(
						dispute,
						dueAt - hoursLeft * 3600000
					) }
				</>
			);
			const text = container.textContent;
			container.remove();
			return text;
		};

		expect( respondBy( 60 ) ).toBe( '2 days left' );
		expect( respondBy( 30 ) ).toBe( '1 day left' );
		expect( respondBy( 20 ) ).toBe( 'Last day today' );
		expect( respondBy( -1 ) ).toBe( '' );
		expect( respondBy( 20, { ...DUE, status: 'won' } ) ).toBe( '' );
		expect( respondBy( 80 ) ).toMatch( /2026/ );
	} );
} );
