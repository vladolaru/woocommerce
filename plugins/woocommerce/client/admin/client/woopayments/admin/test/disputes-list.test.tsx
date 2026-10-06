/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	WooPaymentsDisputesPage,
	getDisputeRespondBy,
	getDisputesApiQuery,
} from '../money-movement/disputes-page';
import {
	getWooPaymentsDisputes,
	getWooPaymentsDisputesSummary,
	requestWooPaymentsDisputesExport,
} from '../money-movement/data';
import { setMockUserPreferences } from './helpers/user-preferences';
import {
	SettingsShellHistoryBridge,
	shellHistory,
} from './helpers/settings-shell-history';

type MockField = {
	id: string;
	label?: ReactNode;
	enableHiding?: boolean;
	enableSorting?: boolean;
	filterBy?: false | { operators: string[]; isPrimary?: boolean };
	elements?: Array< { value: string; label: string } >;
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
};

// The settings shell's history: pages move between routes through admin.php URLs.
jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () =>
		jest.requireActual( './helpers/settings-shell-history' ).shellHistory,
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

const mockCreateErrorNotice = jest.fn();

// Records the list's notices; other stores keep the real dispatch.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: ( store: string ) =>
			store === 'core/notices'
				? {
						createErrorNotice: ( message: string ) =>
							mockCreateErrorNotice( message ),
						createSuccessNotice: jest.fn(),
				  }
				: actual.dispatch( store ),
	};
} );

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
let mockView: Record< string, unknown > = {};
let mockOnChangeView: ( view: Record< string, unknown > ) => void = () =>
	undefined;

// Renders each cell under its field id, so a test reads a cell by column.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
		header,
		search,
		view = {},
		onChangeView,
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: MockField[];
		header?: ReactNode;
		search?: boolean;
		view?: { fields?: string[] };
		onChangeView: ( view: Record< string, unknown > ) => void;
	} ) => {
		mockFields = fields;
		mockView = view;
		mockOnChangeView = onChangeView;
		const visible = fields.filter( ( field ) =>
			( view.fields || [] ).includes( field.id )
		);

		return (
			<div
				data-testid="disputes-dataviews"
				data-visible-fields={ ( view.fields || [] ).join( ',' ) }
				data-search={ String( search ) }
			>
				{ header }
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

// Client 11.1.0 `disputes/index.tsx:50-148`, in order; the info-button column is named for menus.
const CLIENT_COLUMNS = [
	[ 'details', 'Details' ],
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
	'details',
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

const renderPage = ( entry = '/woopayments/disputes' ) =>
	render(
		<MemoryRouter initialEntries={ [ entry ] }>
			<SettingsShellHistoryBridge />
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
		mockGetSummary.mockResolvedValue( { count: 3, total: 15000 } );
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
			'details,amount,status,reason,source,order,customerName,due_by,action'
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
		// Client 11.1.0 `components/dispute-status-chip`: "Response needed".
		expect( getCell( due, 'status' ) ).toHaveTextContent(
			/^Response needed$/
		);
		expect( getCell( due, 'reason' ) ).toHaveTextContent(
			'Transaction unauthorized'
		);
		// Client 11.1.0 `disputes/index.tsx:280-290`: the card brand logo.
		expect(
			within( getCell( due, 'source' ) ).getByRole( 'img', {
				name: 'Visa',
			} )
		).toBeInTheDocument();
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
		// Client 11.1.0 `disputes/index.tsx:300-322`: an unknown customer detail is left empty.
		expect( getCell( pastDue, 'customerCountry' ) ).toHaveTextContent(
			/^$/
		);
		expect( getCell( pastDue, 'due_by' ) ).toHaveTextContent( /^$/ );

		const won = WON.dispute_id;
		// Client 11.1.0 `components/order-link`: an en dash without an order, and nothing else.
		expect( getCell( won, 'order' ) ).toHaveTextContent( /^–$/ );
		expect( getCell( won, 'customerName' ) ).toHaveTextContent( /^$/ );
		expect( getCell( won, 'customerEmail' ) ).toHaveTextContent( /^$/ );
		expect( getCell( won, 'customerCountry' ) ).toHaveTextContent( /^$/ );
		expect( getCell( won, 'status' ) ).toHaveTextContent( 'Won' );
		expect( getCell( won, 'due_by' ) ).toHaveTextContent( /^$/ );
	} );

	it( 'opens the payment details from the info link and every clickable cell, like the client', async () => {
		setMockUserPreferences( { wc_payments_disputes_hidden_columns: [] } );
		renderPage();
		await screen.findByText( 'Disputes loaded.' );

		const due = DUE.dispute_id;
		// Client 11.1.0 `components/details-link`: the row's first cell, an info icon link.
		const info = within( getCell( due, 'details' ) ).getByRole( 'link', {
			name: `See details for Transaction unauthorized dispute ${ due }`,
		} );
		expect( info ).toHaveAttribute(
			'href',
			expect.stringContaining(
				`path=%2Fwoopayments%2Ftransactions%2Fdetails&id=${ DUE.charge_id }`
			)
		);
		const detailsHref = info.getAttribute( 'href' );

		// Client 11.1.0 `disputes/index.tsx:255-323`: the `clickable()` cells, out of the tab order.
		[
			'amount',
			'currency',
			'status',
			'reason',
			'source',
			'customerEmail',
			'customerCountry',
			'created',
			'due_by',
		].forEach( ( field ) => {
			const link = within( getCell( due, field ) ).getByRole( 'link' );

			expect( link ).toHaveAttribute( 'href', detailsHref );
			expect( link ).toHaveAttribute( 'tabindex', '-1' );
		} );
		// A cell with nothing to show has nothing to click.
		expect(
			within( getCell( WON.dispute_id, 'customerEmail' ) ).queryByRole(
				'link'
			)
		).not.toBeInTheDocument();

		fireEvent.click(
			within( getCell( due, 'amount' ) ).getByRole( 'link' )
		);
		expect( recordEvent ).toHaveBeenLastCalledWith(
			'wcpay_disputes_row_action_click',
			{
				dispute_id: due,
				dispute_status: DUE.status,
				dispute_reason: DUE.reason,
			}
		);
	} );

	it( "offers the client's Respond and See details buttons and records its row click", async () => {
		renderPage();
		await screen.findByText( 'Disputes loaded.' );

		// Client 11.1.0 `disputes/index.tsx:324-338`: "Respond" while a response is due,
		// "See details" otherwise, both opening the payment details.
		const respond = within( getCell( DUE.dispute_id, 'action' ) ).getByRole(
			'link'
		);
		expect( respond ).toHaveTextContent( /^Respond$/ );
		expect( respond ).toHaveAttribute(
			'href',
			expect.stringContaining( `id=${ DUE.charge_id }` )
		);
		const details = within( getCell( WON.dispute_id, 'action' ) ).getByRole(
			'link'
		);
		expect( details ).toHaveTextContent( /^See details$/ );

		// Client 11.1.0 `disputes/index.tsx:217-238` `onClickDisputeRow`.
		fireEvent.click( respond );
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_disputes_row_action_click',
			{
				dispute_id: DUE.dispute_id,
				dispute_status: DUE.status,
				dispute_reason: DUE.reason,
			}
		);
	} );

	it( "shows the client's card toolbar: the title and Export only with rows, and no search", async () => {
		renderPage();
		await screen.findByText( 'Disputes loaded.' );

		expect(
			screen.getByRole( 'heading', { name: 'Disputes' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Export' } )
		).toBeInTheDocument();
		expect( screen.getByTestId( 'disputes-dataviews' ) ).toHaveAttribute(
			'data-search',
			'false'
		);
		// Client 11.1.0 `disputes/index.tsx:497-517`: the footer shows the count only.
		expect(
			Array.from(
				document.querySelectorAll( '.woocommerce-table__summary-item' )
			).map( ( item ) => item.textContent )
		).toEqual( [ '3disputes' ] );
	} );

	it( 'shows no second loading line, and no raw server message after a failure', async () => {
		mockGetDisputes.mockRejectedValue(
			new Error( 'Platform unavailable.' )
		);
		renderPage();

		// While loading, only the screen reader region names the state; DataViews shows its spinner.
		expect( screen.getAllByText( 'Loading disputes…' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'Loading disputes…' ) ).toHaveClass(
			'screen-reader-text'
		);

		// Client 11.1.0 `data/disputes/resolvers.js:91-96`: the failure is the client's snackbar
		// (see `money-movement-lists-dataviews.test.tsx`), never the server's own words.
		await waitFor( () =>
			expect(
				screen.queryByText( 'Loading disputes…' )
			).not.toBeInTheDocument()
		);
		expect(
			screen.queryByText( 'Platform unavailable.' )
		).not.toBeInTheDocument();
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Error retrieving disputes.'
		);
	} );

	it( 'hides Export when there are no disputes, like the client', async () => {
		mockGetDisputes.mockResolvedValue( { data: [], total_count: 0 } );
		renderPage();
		await screen.findByText( 'No disputes found.' );

		expect(
			screen.queryByRole( 'button', { name: 'Export' } )
		).not.toBeInTheDocument();
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

describe( 'WooPayments disputes Show and currency filters', () => {
	const AWAITING = [ 'needs_response', 'warning_needs_response' ];
	const lastQuery = ( mock: jest.Mock ) =>
		mock.mock.calls[ mock.mock.calls.length - 1 ][ 0 ];

	beforeEach( () => {
		setMockUserPreferences( {} );
		window.wcSettings = { adminUrl: 'http://example.com/wp-admin/' };
		mockGetDisputes.mockReset();
		mockGetSummary.mockReset();
		mockGetDisputes.mockResolvedValue( {
			data: RECORDED as never,
			total_count: 3,
		} );
		mockGetSummary.mockResolvedValue( { count: 3, currencies: [ 'usd' ] } );
	} );

	it( "turns filter=awaiting_response into the client's search for the two needs-response statuses", () => {
		expect(
			getDisputesApiQuery(
				{ page: 1, search: 'Ada' },
				'awaiting_response'
			)
		).toEqual( { page: 1, search: AWAITING } );
		expect(
			getDisputesApiQuery( { page: 1, search: 'Ada' }, 'all' )
		).toEqual( { page: 1, search: 'Ada' } );
	} );

	// Client 11.1.0 `formatDateValue()`: "before" is the end of the merchant's day, sent as UTC.
	it.each( [
		[ -180, '2026-09-30 20:59:59' ],
		[ 300, '2026-10-01 04:59:59' ],
	] )(
		'sends date_before as the end of the merchant day at offset %i',
		( offset, expected ) => {
			const timezoneSpy = jest
				.spyOn( Date.prototype, 'getTimezoneOffset' )
				.mockReturnValue( offset );

			try {
				expect(
					getDisputesApiQuery(
						{ date_before: '2026-09-30' },
						'advanced'
					).date_before
				).toBe( expected );
			} finally {
				timezoneSpy.mockRestore();
			}
		}
	);

	// Client 11.1.0 `disputes/index.tsx:52-59` and `data/disputes/resolvers.js:83`: the URL's
	// `orderby=dueBy` / `order` sort the platform's `due_by`.
	it( "reads and writes the client's orderby=dueBy and order URL params", async () => {
		renderPage( '/woopayments/disputes?orderby=dueBy&order=asc' );
		await screen.findByText( 'Disputes loaded.' );

		expect( lastQuery( mockGetDisputes ) ).toMatchObject( {
			sort: 'due_by',
			direction: 'asc',
		} );

		act( () =>
			mockOnChangeView( {
				...mockView,
				sort: { field: 'due_by', direction: 'desc' },
			} )
		);

		expect( shellHistory.push ).toHaveBeenLastCalledWith(
			expect.stringMatching( /&orderby=dueBy&order=desc(&|$)/ )
		);
	} );

	it( 'requests only the disputes awaiting a response when the badge link opens the list', async () => {
		renderPage( '/woopayments/disputes?filter=awaiting_response' );
		await screen.findByText( 'Disputes loaded.' );

		expect( lastQuery( mockGetDisputes ).search ).toEqual( AWAITING );
		expect( lastQuery( mockGetSummary ).search ).toEqual( AWAITING );
		expect( screen.getByLabelText( 'Show' ) ).toHaveValue(
			'awaiting_response'
		);
	} );

	it( "offers the client's Show choices and requests all disputes by default", async () => {
		renderPage();
		await screen.findByText( 'Disputes loaded.' );

		const show = screen.getByLabelText( 'Show' );
		expect( show ).toHaveValue( 'all' );
		expect(
			within( show )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'Needs response', 'All disputes', 'Advanced filters' ] );
		expect( lastQuery( mockGetDisputes ).search ).toBeUndefined();
		expect(
			screen.queryByLabelText( 'Dispute currency' )
		).not.toBeInTheDocument();
	} );

	it( 'drops the advanced filters when Show leaves Advanced filters', async () => {
		renderPage(
			'/woopayments/disputes?status_is=won&search=Ada&filter=advanced'
		);
		await screen.findByText( 'Disputes loaded.' );
		expect( lastQuery( mockGetDisputes ) ).toMatchObject( {
			status_is: 'won',
			search: 'Ada',
		} );

		fireEvent.change( screen.getByLabelText( 'Show' ), {
			target: { value: 'awaiting_response' },
		} );

		await waitFor( () =>
			expect( lastQuery( mockGetDisputes ).search ).toEqual( AWAITING )
		);
		expect( lastQuery( mockGetDisputes ).status_is ).toBeUndefined();
		// Client 11.1.0 FilterPicker `update()` goes through `getHistory()` with an admin.php URL, so the
		// address bar stays on the admin page, under a subdirectory install too.
		expect( shellHistory.push ).toHaveBeenLastCalledWith(
			expect.stringMatching(
				/^admin\.php\?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes&.*filter=awaiting_response/
			)
		);
	} );

	it( 'adds the Status and Disputed on filters only for Advanced filters', async () => {
		const filterable = () =>
			mockFields
				.filter( ( field ) => field.filterBy )
				.map( ( field ) => [ field.id, field.filterBy ] );

		renderPage();
		await screen.findByText( 'Disputes loaded.' );
		expect( filterable() ).toEqual( [] );

		fireEvent.change( screen.getByLabelText( 'Show' ), {
			target: { value: 'advanced' },
		} );

		await waitFor( () =>
			expect( filterable() ).toEqual( [
				[ 'status', { operators: [ 'is', 'isNot' ], isPrimary: true } ],
				[
					'created',
					{
						operators: [ 'before', 'after', 'between' ],
						isPrimary: true,
					},
				],
			] )
		);
		expect(
			mockFields
				.find( ( field ) => field.id === 'status' )
				?.elements?.map( ( element ) => element.label )
		).toEqual( [
			'Inquiry: Response needed',
			'Inquiry: Under review',
			'Inquiry: Closed',
			'Response needed',
			'Under review',
			'Charge refunded',
			'Won',
			'Lost',
		] );
	} );

	it( 'offers the match select only under Advanced filters', async () => {
		const { unmount } = renderPage();
		await screen.findByText( 'Disputes loaded.' );
		expect(
			screen.queryByLabelText( 'Disputes match' )
		).not.toBeInTheDocument();
		unmount();

		renderPage( '/woopayments/disputes?filter=advanced' );
		await screen.findByText( 'Disputes loaded.' );
		const match = screen.getByLabelText( 'Disputes match' );
		expect( match ).toHaveValue( 'all' );
		expect( lastQuery( mockGetDisputes ) ).not.toHaveProperty( 'match' );

		fireEvent.change( match, { target: { value: 'any' } } );
		await waitFor( () =>
			expect( lastQuery( mockGetDisputes ).match ).toBe( 'any' )
		);
	} );

	it( 'passes "match any" from the advanced filters to the list, summary and export', async () => {
		const mockRequestExport =
			requestWooPaymentsDisputesExport as jest.MockedFunction<
				typeof requestWooPaymentsDisputesExport
			>;
		mockRequestExport.mockReset();
		mockRequestExport.mockRejectedValue( new Error( 'Stop here.' ) );
		renderPage(
			'/woopayments/disputes?filter=advanced&status_is=won&status_is=lost&match=any'
		);
		await screen.findByText( 'Disputes loaded.' );

		expect( lastQuery( mockGetDisputes ) ).toMatchObject( {
			match: 'any',
			status_is: [ 'won', 'lost' ],
		} );
		expect( lastQuery( mockGetSummary ).match ).toBe( 'any' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );
		await waitFor( () =>
			expect( mockRequestExport ).toHaveBeenCalledWith(
				expect.objectContaining( { match: 'any' } )
			)
		);
	} );

	it( 'shows the currency select for more than one currency and filters by the chosen one', async () => {
		mockGetSummary.mockResolvedValue( {
			count: 3,
			currencies: [ 'usd', 'eur' ],
		} );
		renderPage( '/woopayments/disputes?filter=awaiting_response' );
		await screen.findByText( 'Disputes loaded.' );

		const currency = screen.getByLabelText( 'Dispute currency' );
		expect(
			within( currency )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'All currencies', 'United States (US) dollar', 'Euro' ] );

		fireEvent.change( currency, { target: { value: 'eur' } } );

		await waitFor( () =>
			expect( lastQuery( mockGetDisputes ).store_currency_is ).toBe(
				'eur'
			)
		);
		expect( lastQuery( mockGetDisputes ).search ).toEqual( AWAITING );
	} );
} );
