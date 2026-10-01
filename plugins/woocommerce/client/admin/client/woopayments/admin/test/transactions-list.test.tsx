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
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { summaryItem } from './helpers/table-summary';
import { WooPaymentsTransactionsList } from '../money-movement/transactions-list';
import {
	getRiskLevelLabel,
	getTransactionChannelLabel,
	getTransactionListFields,
} from '../money-movement/transactions-list-fields';
import { buildMoneyMovementRoutePath } from '../money-movement/query';
import {
	getWooPaymentsTransactions,
	getWooPaymentsTransactionsExportUrl,
	getWooPaymentsTransactionsSummary,
	requestWooPaymentsTransactionsExport,
} from '../money-movement/data';
import {
	mockUpdateUserPreferences,
	setMockUserPreferences,
} from './helpers/user-preferences';

type MockField = {
	id: string;
	label?: ReactNode;
	header?: ReactNode;
	enableHiding?: boolean;
	enableSorting?: boolean;
	filterBy?: false | { operators: string[] };
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
	filterBy?: false | Record< string, unknown >;
	elements?: Array< { value: string; label: string } >;
};

type MockViewFilter = { field: string; operator: string; value?: unknown };
type MockView = { filters?: MockViewFilter[]; [ key: string ]: unknown };

let mockLastFields: MockField[] = [];
let mockLastView: MockView = {};
let mockOnChangeView: ( view: MockView ) => void = () => undefined;

// The export raises snackbars; other stores keep the real dispatch.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: ( store: string ) =>
			store === 'core/notices'
				? {
						createSuccessNotice: jest.fn(),
						createErrorNotice: jest.fn(),
				  }
				: actual.dispatch( store ),
	};
} );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

const mockHistoryPush = jest.fn();

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => ( { push: mockHistoryPush } ),
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsTransactions: jest.fn(),
	getWooPaymentsTransactionsSummary: jest.fn(),
	requestWooPaymentsTransactionsExport: jest.fn(),
	getWooPaymentsTransactionsExportUrl: jest.fn(),
} ) );

jest.mock( '../money-movement/transaction-search', () => ( {
	WooPaymentsTransactionSearch: () => null,
} ) );

// Renders each cell under its field id, so a test reads a cell by column.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
		header,
		onChangeView,
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: MockField[];
		header?: ReactNode;
		onChangeView?: ( view: Record< string, unknown > ) => void;
		view?: { fields?: string[]; [ key: string ]: unknown };
	} ) => {
		const visible = fields.filter( ( field ) =>
			( view.fields || [] ).includes( field.id )
		);
		mockLastFields = fields;
		mockLastView = view;
		mockOnChangeView = onChangeView || ( () => undefined );

		return (
			<div
				data-testid="transactions-dataviews"
				data-visible-fields={ ( view.fields || [] ).join( ',' ) }
				data-filterable-fields={ fields
					.filter( ( field ) => field.filterBy )
					.map( ( field ) => field.id )
					.join( ',' ) }
			>
				{ header }
				<button
					type="button"
					onClick={ () => onChangeView?.( { ...view, page: 2 } ) }
				>
					Mock next page
				</button>
				<div role="row">
					{ visible.map( ( field ) => (
						<div role="columnheader" key={ field.id }>
							{ field.header || field.label }
						</div>
					) ) }
				</div>
				{ data.map( ( item ) => (
					<div
						role="row"
						key={ String( item.transaction_id ) }
						data-transaction-id={ String( item.transaction_id ) }
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

const mockGetTransactions = getWooPaymentsTransactions as jest.MockedFunction<
	typeof getWooPaymentsTransactions
>;
const mockGetSummary = getWooPaymentsTransactionsSummary as jest.MockedFunction<
	typeof getWooPaymentsTransactionsSummary
>;
const mockRequestExport =
	requestWooPaymentsTransactionsExport as jest.MockedFunction<
		typeof requestWooPaymentsTransactionsExport
	>;
const mockGetExportUrl =
	getWooPaymentsTransactionsExportUrl as jest.MockedFunction<
		typeof getWooPaymentsTransactionsExportUrl
	>;

// Recorded native :8889 list rows (sanitized); see the file's `_meta`.
const RECORDED = JSON.parse(
	fs.readFileSync(
		path.join( __dirname, 'fixtures/recorded-transactions-list.json' ),
		'utf8'
	)
).response.data as Array< Record< string, unknown > >;
const [ SUBSCRIPTION_CHARGE, EUR_PAYMENT, EUR_REFUND ] = RECORDED;

// Client 11.1.0 `transactions/list/index.tsx:136-291`, in order.
const CLIENT_COLUMNS = [
	[ 'transaction_id', 'Transaction ID' ],
	[ 'date', 'Date' ],
	[ 'type', 'Type' ],
	[ 'channel', 'Sales channel' ],
	[ 'customer_currency', 'Paid currency' ],
	[ 'customer_amount', 'Amount paid' ],
	[ 'currency', 'Payout currency' ],
	[ 'amount', 'Amount' ],
	[ 'fees', 'Fees' ],
	[ 'net', 'Net' ],
	[ 'order', 'Order #' ],
	[ 'subscriptions', 'Subscription #' ],
	[ 'source', 'Payment method' ],
	[ 'customer_name', 'Customer' ],
	[ 'customer_email', 'Email' ],
	[ 'customer_country', 'Country' ],
	[ 'risk_level', 'Risk level' ],
	[ 'deposit_id', 'Payout ID' ],
	[ 'deposit', 'Payout date' ],
	[ 'deposit_status', 'Payout status' ],
];
// The client's `required: true` columns.
const REQUIRED = [ 'date', 'type', 'channel', 'net', 'order' ];
// The client's columns without `visible: false`.
const DEFAULT_VISIBLE = [
	'date',
	'type',
	'channel',
	'amount',
	'fees',
	'net',
	'order',
	'subscriptions',
	'source',
	'customer_name',
	'deposit',
];
// The client's `isSortable` columns.
const SORTABLE = [
	'date',
	'customer_currency',
	'customer_amount',
	'currency',
	'amount',
	'fees',
	'net',
];

const setSubscriptionsActive = ( isSubscriptionsActive: boolean ) => {
	window.wcSettings = {
		adminUrl: 'http://example.com/wp-admin/',
		admin: { woopaymentsSettings: { isSubscriptionsActive } },
	} as typeof window.wcSettings;
};

const renderList = ( depositId?: string, search = '' ) =>
	render(
		<MemoryRouter
			initialEntries={ [ `/woopayments/transactions${ search }` ] }
		>
			<WooPaymentsTransactionsList
				depositId={ depositId }
				buildRoute={ ( query ) =>
					buildMoneyMovementRoutePath(
						'/woopayments/transactions',
						query
					)
				}
			/>
		</MemoryRouter>
	);

const getCell = ( transactionId: unknown, field: string ) => {
	const row = document.querySelector(
		`[data-transaction-id="${ String( transactionId ) }"]`
	) as HTMLElement;

	return row.querySelector( `[data-field="${ field }"]` ) as HTMLElement;
};

describe( 'WooPayments transactions list columns', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
		setSubscriptionsActive( true );
		mockHistoryPush.mockReset();
		mockGetTransactions.mockReset();
		mockGetSummary.mockReset();
		mockRequestExport.mockReset();
		mockGetExportUrl.mockReset();
		mockGetTransactions.mockResolvedValue( {
			data: RECORDED as never,
			total_count: 3,
		} );
		mockGetSummary.mockResolvedValue( {
			count: 3,
			currency: 'usd',
			total: 1838,
			fees: 166,
			net: 1672,
			store_currencies: [ 'usd' ],
		} );
	} );

	it( "defines the client's columns with its labels, required and sortable flags", () => {
		const fields = getTransactionListFields( {
			includeDeposit: true,
			includeSubscription: true,
			includeFilters: true,
		} ) as MockField[];

		expect( fields.map( ( field ) => [ field.id, field.label ] ) ).toEqual(
			CLIENT_COLUMNS
		);
		expect(
			fields
				.filter( ( field ) => field.enableHiding === false )
				.map( ( field ) => field.id )
		).toEqual( REQUIRED );
		expect(
			fields
				.filter( ( field ) => field.enableSorting !== false )
				.map( ( field ) => field.id )
		).toEqual( SORTABLE );
	} );

	it( "shows the client's default columns until a preference is stored", async () => {
		renderList();

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.getByTestId( 'transactions-dataviews' )
		).toHaveAttribute( 'data-visible-fields', DEFAULT_VISIBLE.join( ',' ) );
		// Paging with no stored preference keeps user meta untouched.
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock next page' } )
		);
		expect( mockUpdateUserPreferences ).not.toHaveBeenCalled();
	} );

	it( 'follows the stored client list, showing every column it does not hide', async () => {
		setMockUserPreferences( {
			wc_payments_transactions_hidden_columns: [
				'transaction_id',
				'customer_currency',
				'customer_amount',
				'currency',
				'customer_email',
				'customer_country',
				'deposit_id',
				'deposit_status',
				'fees',
			],
		} );
		renderList();

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.getByTestId( 'transactions-dataviews' )
		).toHaveAttribute(
			'data-visible-fields',
			'date,type,channel,amount,net,order,subscriptions,source,customer_name,risk_level,deposit'
		);
	} );

	it( 'offers Subscription # only while WooCommerce Subscriptions is active', async () => {
		setSubscriptionsActive( false );
		const { unmount } = renderList();

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.queryByRole( 'columnheader', { name: 'Subscription #' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByTestId( 'transactions-dataviews' ).dataset.visibleFields
		).not.toContain( 'subscriptions' );
		unmount();

		setSubscriptionsActive( true );
		renderList();

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.getByRole( 'columnheader', { name: 'Subscription #' } )
		).toBeInTheDocument();
	} );

	it( 'renders every column from recorded platform rows', async () => {
		setMockUserPreferences( {
			wc_payments_transactions_hidden_columns: [],
		} );
		renderList();
		await screen.findByText( 'Transactions loaded.' );

		const charge = SUBSCRIPTION_CHARGE.transaction_id;
		const order = SUBSCRIPTION_CHARGE.order as {
			url: string;
			customer_url: string;
			subscriptions: Array< { url: string } >;
		};
		expect( getCell( charge, 'transaction_id' ) ).toHaveTextContent(
			'txn_3UI4HnBzWlxcwgpP0JarqoHY'
		);
		expect( getCell( charge, 'type' ) ).toHaveTextContent( 'Charge' );
		expect( getCell( charge, 'channel' ) ).toHaveTextContent(
			'Online store'
		);
		expect( getCell( charge, 'fees' ) ).toHaveTextContent( '-$0.88' );
		expect( getCell( charge, 'net' ) ).toHaveTextContent( '$19.10' );
		expect(
			within( getCell( charge, 'order' ) ).getByRole( 'link', {
				name: '4520',
			} )
		).toHaveAttribute( 'href', order.url );
		expect(
			within( getCell( charge, 'subscriptions' ) ).getByRole( 'link', {
				name: '3175',
			} )
		).toHaveAttribute( 'href', order.subscriptions[ 0 ].url );
		// Client 11.1.0 `transactions/list/index.tsx:473-497`: the brand logo, then the last four.
		expect(
			within( getCell( charge, 'source' ) ).getByRole( 'img', {
				name: 'Visa',
			} )
		).toBeInTheDocument();
		expect( getCell( charge, 'source' ) ).toHaveTextContent(
			/^•••• 4242$/
		);
		expect(
			within( getCell( charge, 'customer_name' ) ).getByRole( 'link', {
				name: 'Test Shopper',
			} )
		).toHaveAttribute( 'href', order.customer_url );
		expect(
			within( getCell( charge, 'customer_email' ) ).getByRole( 'link', {
				name: 'shopper-arqohy@example.com',
			} )
		).toHaveAttribute( 'href', order.customer_url );
		expect( getCell( charge, 'customer_country' ) ).toHaveTextContent(
			'US'
		);
		expect( getCell( charge, 'risk_level' ) ).toHaveTextContent( 'Normal' );
		expect( getCell( charge, 'deposit_id' ) ).toHaveTextContent(
			'po_1UIINjBzWlxcwgpPhN8rvYGM'
		);
		expect(
			within( getCell( charge, 'deposit' ) ).getByRole( 'link' )
		).toHaveAttribute(
			'href',
			expect.stringContaining(
				'path=%2Fwoopayments%2Fpayouts%2Fdetails&id=po_1UIINjBzWlxcwgpPhN8rvYGM'
			)
		);
		expect( getCell( charge, 'deposit_status' ) ).toHaveTextContent(
			'Completed (paid)'
		);
		expect(
			within( getCell( charge, 'amount' ) ).queryByRole( 'img' )
		).not.toBeInTheDocument();

		const payment = EUR_PAYMENT.transaction_id;
		expect( getCell( payment, 'customer_currency' ) ).toHaveTextContent(
			'EUR'
		);
		expect( getCell( payment, 'customer_amount' ) ).toHaveTextContent(
			'€10.99'
		);
		expect( getCell( payment, 'currency' ) ).toHaveTextContent( 'USD' );
		expect( getCell( payment, 'amount' ) ).toHaveTextContent( '$12.47' );
		expect(
			within( getCell( payment, 'amount' ) ).getByRole( 'img', {
				name: 'Converted from €10.99',
			} )
		).toBeInTheDocument();
		expect( getCell( payment, 'customer_country' ) ).toHaveTextContent(
			'BE'
		);
		expect( getCell( payment, 'customer_name' ) ).toHaveTextContent(
			'Test Shopper'
		);
		expect(
			within( getCell( payment, 'customer_name' ) ).queryByRole( 'link' )
		).not.toBeInTheDocument();
		expect( getCell( payment, 'deposit' ) ).toHaveTextContent(
			'Future payout'
		);
		expect( getCell( payment, 'deposit_status' ) ).toHaveTextContent( '' );

		const refund = EUR_REFUND.transaction_id;
		expect( getCell( refund, 'type' ) ).toHaveTextContent( 'Refund' );
		expect( getCell( refund, 'order' ) ).toHaveTextContent( 'N/A' );
		expect( getCell( refund, 'subscriptions' ) ).toHaveTextContent( '' );
		expect(
			within( getCell( refund, 'amount' ) ).getByRole( 'img', {
				name: 'Converted from €12.34',
			} )
		).toBeInTheDocument();
		expect( getCell( refund, 'amount' ) ).toHaveTextContent( '-$14.07' );
		expect( getCell( refund, 'deposit_status' ) ).toHaveTextContent(
			'Completed (paid)'
		);
	} );

	it( 'opens the payment details from every clickable cell, like the client', async () => {
		setMockUserPreferences( {
			wc_payments_transactions_hidden_columns: [],
		} );
		renderList();
		await screen.findByText( 'Transactions loaded.' );

		const charge = SUBSCRIPTION_CHARGE.transaction_id;
		const detailsHref = within( getCell( charge, 'type' ) )
			.getByRole( 'link' )
			.getAttribute( 'href' );
		expect( detailsHref ).toContain(
			'path=%2Fwoopayments%2Ftransactions%2Fdetails'
		);

		// Client 11.1.0 `transactions/list/index.tsx:327-339, :445-548`: the `clickable()` cells,
		// out of the tab order like `components/clickable-cell`.
		[
			'transaction_id',
			'date',
			'channel',
			'customer_currency',
			'customer_amount',
			'currency',
			'amount',
			'fees',
			'net',
			'source',
			'customer_country',
			'risk_level',
		].forEach( ( field ) => {
			const link = within( getCell( charge, field ) ).getByRole( 'link' );

			expect( link ).toHaveAttribute( 'href', detailsHref );
			expect( link ).toHaveAttribute( 'tabindex', '-1' );
		} );
		// The order, subscription and customer cells keep their own links.
		expect(
			within( getCell( charge, 'order' ) ).getByRole( 'link' )
		).not.toHaveAttribute( 'href', detailsHref );
		expect(
			within( getCell( charge, 'customer_name' ) ).getByRole( 'link' )
		).not.toHaveAttribute( 'href', detailsHref );
	} );

	it( 'opens no details from loan disbursement and network cost rows, like the client', () => {
		const fields = getTransactionListFields( {
			includeDeposit: true,
			includeSubscription: false,
			includeFilters: false,
		} ) as MockField[];
		const renderCell = ( id: string, item: Record< string, unknown > ) =>
			render(
				<>
					{ fields
						.find( ( field ) => field.id === id )
						?.render?.( { item } ) }
				</>
			);

		[
			{ type: 'financing_payout', charge_id: '' },
			{ type: 'network_costs', charge_id: '' },
			{ type: 'financing_paydown', charge_id: '' },
		].forEach( ( row ) => {
			const item = {
				...SUBSCRIPTION_CHARGE,
				...row,
				transaction_id: `txn_${ row.type }`,
			};
			const { container, unmount } = renderCell( 'amount', item );

			expect( container.querySelector( 'a' ) ).toBeNull();
			unmount();

			const type = renderCell( 'type', item );
			expect( type.container.querySelector( 'a' ) ).toBeNull();
			type.unmount();
		} );
	} );

	it( "maps the client's channel and risk values", () => {
		// Client 11.1.0 `utils/charge/index.ts:294-303`; the store records no in-person sale.
		expect( getTransactionChannelLabel( 'in_person' ) ).toBe( 'In-person' );
		expect( getTransactionChannelLabel( 'in_person_pos' ) ).toBe(
			'In-person (POS)'
		);
		expect( getTransactionChannelLabel( null ) ).toBe( 'Online store' );
		// Client 11.1.0 `components/risk-level/index.tsx:28-31`.
		expect( getRiskLevelLabel( 1 ) ).toBe( 'Elevated' );
		expect( getRiskLevelLabel( 2 ) ).toBe( 'Highest' );
		expect( getRiskLevelLabel( 7 ) ).toBe( 'N/A' );
	} );

	it( "shows the client's single-currency summary", async () => {
		renderList();

		expect(
			await screen.findByText( summaryItem( '3 transactions' ) )
		).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '$18.38 total' ) )
		).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '$1.66 fees' ) )
		).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '$16.72 net' ) )
		).toBeInTheDocument();
	} );

	it( 'leaves amounts out of the summary across store currencies', async () => {
		mockGetSummary.mockResolvedValue( {
			count: 3,
			currency: 'usd',
			total: 1838,
			store_currencies: [ 'usd', 'eur' ],
		} );
		renderList();

		await screen.findByText( summaryItem( '3 transactions' ) );
		expect( screen.queryByText( /total$/ ) ).not.toBeInTheDocument();
	} );

	it( 'scopes the list, its summary and its export to a payout and drops the payout columns', async () => {
		mockRequestExport.mockResolvedValue( { export_id: 'export_payout' } );
		mockGetExportUrl.mockResolvedValue( {
			download_url: 'https://example.com/payout.csv',
		} );
		const clickSpy = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation();
		renderList( 'po_1UIINjBzWlxcwgpPhN8rvYGM' );

		await screen.findByText( 'Transactions loaded.' );
		const scope = expect.objectContaining( {
			deposit_id: 'po_1UIINjBzWlxcwgpPhN8rvYGM',
		} );
		expect( mockGetTransactions ).toHaveBeenCalledWith( scope );
		expect( mockGetSummary ).toHaveBeenCalledWith( scope );
		expect(
			screen.getByTestId( 'transactions-dataviews' ).dataset.visibleFields
		).toBe(
			DEFAULT_VISIBLE.filter( ( field ) => field !== 'deposit' ).join(
				','
			)
		);

		const exportButton = await screen.findByRole( 'button', {
			name: 'Export',
		} );
		await act( async () => {
			await userEvent.click( exportButton );
		} );
		expect( mockRequestExport ).toHaveBeenCalledWith( scope );
		clickSpy.mockRestore();
	} );

	it( 'offers no filters and no payout columns inside a payout', () => {
		const fields = getTransactionListFields( {
			includeDeposit: false,
			includeSubscription: false,
			includeFilters: false,
		} ) as MockField[];

		expect(
			fields.filter( ( field ) =>
				[ 'deposit_id', 'deposit', 'deposit_status' ].includes(
					field.id
				)
			)
		).toEqual( [] );
		expect(
			fields.filter( ( field ) => field.filterBy !== false )
		).toEqual( [] );
	} );
} );

// Client 11.1.0 `transactions/filters/config.ts:134-553`, in the add menu's order; sentence case.
const ADVANCED_FILTER_LABELS = [
	'Date',
	'Type',
	'Customer country',
	'Customer currency',
	'Device type',
	'Payment method',
	'Risk level',
	'Sales channel',
];

const getFilterLabels = () =>
	mockLastFields
		.filter( ( field ) => field.filterBy )
		.map( ( field ) => String( field.label ) );

const getFilterField = ( label: string ) =>
	mockLastFields.find(
		( field ) => field.filterBy && field.label === label
	) as MockField & { filterBy: { operators: string[] } };

describe( 'WooPayments transactions list Show and currency filters', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
		setSubscriptionsActive( false );
		mockHistoryPush.mockReset();
		mockGetTransactions.mockReset();
		mockGetSummary.mockReset();
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetSummary.mockResolvedValue( {
			count: 0,
			total: 0,
			store_currencies: [ 'usd', 'eur' ],
		} );
	} );

	it( "offers the client's Show choices and the advanced filters only under Advanced filters", async () => {
		const { unmount } = renderList();
		await screen.findByText( 'No transactions found.' );

		const show = screen.getByLabelText( 'Show' );
		expect( show ).toHaveValue( 'all' );
		expect(
			within( show )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'All transactions', 'Advanced filters' ] );
		expect(
			screen.getByTestId( 'transactions-dataviews' )
		).toHaveAttribute( 'data-filterable-fields', '' );

		fireEvent.change( show, { target: { value: 'advanced' } } );
		expect( mockHistoryPush ).toHaveBeenLastCalledWith(
			expect.stringMatching( /&filter=advanced$/ )
		);
		unmount();

		renderList(
			undefined,
			'?filter=advanced&type_is=refund&customer_country_is=BE'
		);
		await screen.findByText( 'No transactions found.' );
		expect( getFilterLabels() ).toEqual( ADVANCED_FILTER_LABELS );

		// Leaving Advanced filters drops them, as the client's FilterPicker does.
		fireEvent.change( screen.getByLabelText( 'Show' ), {
			target: { value: 'all' },
		} );
		expect( mockHistoryPush ).toHaveBeenLastCalledWith(
			expect.not.stringMatching( /type_is|customer_country_is|filter=/ )
		);
	} );

	it( 'passes "match any" from the advanced filters to the list, summary and export', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [ { transaction_id: 'txn_match', type: 'charge' } ],
			total_count: 1,
		} as never );
		mockRequestExport.mockReset();
		mockRequestExport.mockRejectedValue( new Error( 'Stop here.' ) );
		renderList( undefined, '?filter=advanced&type_is=refund&match=any' );
		await screen.findByText( 'Transactions loaded.' );

		expect( screen.getByLabelText( 'Transactions match' ) ).toHaveValue(
			'any'
		);
		expect( mockGetTransactions ).toHaveBeenLastCalledWith(
			expect.objectContaining( { match: 'any', type_is: 'refund' } )
		);
		expect( mockGetSummary ).toHaveBeenLastCalledWith(
			expect.objectContaining( { match: 'any' } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );
		await waitFor( () =>
			expect( mockRequestExport ).toHaveBeenCalledWith(
				expect.objectContaining( { match: 'any' } )
			)
		);

		// Choosing "all" again drops the argument from the route.
		fireEvent.change( screen.getByLabelText( 'Transactions match' ), {
			target: { value: 'all' },
		} );
		expect( mockHistoryPush ).toHaveBeenLastCalledWith(
			expect.not.stringContaining( 'match=' )
		);
	} );

	it( "offers the client's advanced filters with its choices", async () => {
		window.wcSettings = {
			...window.wcSettings,
			countries: { BE: 'Belgium', US: 'United States (US)' },
		} as typeof window.wcSettings;
		mockGetSummary.mockResolvedValue( {
			count: 0,
			total: 0,
			store_currencies: [ 'usd' ],
			// Client 11.1.0 `transactions/list/index.tsx:775-776`: the summary lists these choices.
			customer_currencies: [ 'eur', 'usd' ],
			sources: [ 'visa', 'bancontact' ],
		} );
		renderList( undefined, '?filter=advanced' );
		await screen.findByText( 'No transactions found.' );

		expect( getFilterLabels() ).toEqual( ADVANCED_FILTER_LABELS );
		const values = ( label: string ) =>
			getFilterField( label ).elements?.map( ( { value } ) => value );
		const labels = ( label: string ) =>
			getFilterField( label ).elements?.map(
				( element ) => element.label
			);

		expect( values( 'Customer country' ) ).toEqual( [ 'BE', 'US' ] );
		expect( values( 'Customer currency' ) ).toEqual( [ 'eur', 'usd' ] );
		expect( values( 'Payment method' ) ).toEqual( [
			'visa',
			'bancontact',
		] );
		expect( labels( 'Payment method' ) ).toEqual( [
			'Visa',
			'Bancontact',
		] );
		// Client 11.1.0 `transactions/strings.ts:29-46`.
		expect( getFilterField( 'Device type' ).elements ).toEqual( [
			{ value: 'android', label: 'Android' },
			{ value: 'ios', label: 'iPhone' },
		] );
		expect( getFilterField( 'Risk level' ).elements ).toEqual( [
			{ value: '0', label: 'Normal' },
			{ value: '1', label: 'Elevated' },
			{ value: '2', label: 'Highest' },
		] );
		expect( values( 'Sales channel' ) ).toEqual( [
			'online',
			'in_person',
			'in_person_pos',
		] );
		// Every select filter matches "Is" or "Is not", as the client's rules do.
		ADVANCED_FILTER_LABELS.slice( 1 ).forEach( ( label ) =>
			expect( getFilterField( label ).filterBy.operators ).toEqual( [
				'is',
				'isNot',
			] )
		);
	} );

	it( 'honors the advanced filters in the URL for the list, summary and export', async () => {
		const args = {
			type_is_not: 'refund',
			customer_country_is: 'BE',
			customer_currency_is: 'eur',
			source_device_is_not: 'ios',
			source_is: 'bancontact',
			risk_level_is: '1',
			channel_is: 'online',
		};
		mockGetTransactions.mockResolvedValue( {
			data: [ { transaction_id: 'txn_filtered', type: 'charge' } ],
			total_count: 1,
		} as never );
		mockRequestExport.mockReset();
		mockRequestExport.mockRejectedValue( new Error( 'Stop here.' ) );
		renderList(
			undefined,
			`?filter=advanced&${ new URLSearchParams( args ).toString() }`
		);
		await screen.findByText( 'Transactions loaded.' );

		expect( mockGetTransactions ).toHaveBeenLastCalledWith(
			expect.objectContaining( args )
		);
		expect( mockGetSummary ).toHaveBeenLastCalledWith(
			expect.objectContaining( args )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );
		await waitFor( () =>
			expect( mockRequestExport ).toHaveBeenCalledWith(
				expect.objectContaining( args )
			)
		);

		// The chips show them, as the client's AdvancedFilters reads them from the URL.
		const byId = Object.fromEntries(
			mockLastFields.map( ( field ) => [ field.id, field.label ] )
		);
		expect(
			( mockLastView.filters || [] ).map( ( filter ) => [
				byId[ filter.field ],
				filter.operator,
				filter.value,
			] )
		).toEqual(
			expect.arrayContaining( [
				[ 'Type', 'isNot', 'refund' ],
				[ 'Customer country', 'is', 'BE' ],
				[ 'Customer currency', 'is', 'eur' ],
				[ 'Device type', 'isNot', 'ios' ],
				[ 'Payment method', 'is', 'bancontact' ],
				[ 'Risk level', 'is', '1' ],
				[ 'Sales channel', 'is', 'online' ],
			] )
		);
	} );

	it( "writes a chosen advanced filter to the route under the client's argument", async () => {
		renderList( undefined, '?filter=advanced' );
		await screen.findByText( 'No transactions found.' );

		act( () =>
			mockOnChangeView( {
				...mockLastView,
				filters: [
					{
						field: getFilterField( 'Customer country' ).id,
						operator: 'isNot',
						value: 'US',
					},
					{
						field: getFilterField( 'Device type' ).id,
						operator: 'is',
						value: 'android',
					},
				],
			} )
		);

		expect( mockHistoryPush ).toHaveBeenLastCalledWith(
			expect.stringMatching(
				/customer_country_is_not=US.*source_device_is=android|source_device_is=android.*customer_country_is_not=US/
			)
		);
	} );

	it( 'offers the Loan filter only when the account has loans', async () => {
		window.wcSettings = {
			...window.wcSettings,
			admin: {
				woopaymentsSettings: {
					accountLoans: {
						loans: [ 'flxln_active|active', 'flxln_paid|paid' ],
					},
				},
			},
		} as typeof window.wcSettings;
		renderList( undefined, '?filter=advanced&loan_id_is=flxln_paid' );
		await screen.findByText( 'No transactions found.' );

		// Client 11.1.0 `transactions/filters/config.ts:555-598`.
		expect( getFilterField( 'Loan' ).elements ).toEqual( [
			{ value: 'flxln_active', label: 'ID: flxln_active | In Progress' },
			{ value: 'flxln_paid', label: 'ID: flxln_paid | Paid in Full' },
		] );
		expect( getFilterField( 'Loan' ).filterBy.operators ).toEqual( [
			'is',
		] );
		expect( mockGetTransactions ).toHaveBeenLastCalledWith(
			expect.objectContaining( { loan_id_is: 'flxln_paid' } )
		);
	} );

	it( 'shows the Deposit currency select for more than one store currency', async () => {
		renderList();
		await screen.findByText( 'No transactions found.' );

		fireEvent.change( screen.getByLabelText( 'Deposit currency' ), {
			target: { value: 'eur' },
		} );
		expect( mockHistoryPush ).toHaveBeenLastCalledWith(
			expect.stringContaining( 'store_currency_is=eur' )
		);
	} );

	it( 'has no filters on a payout’s transactions', async () => {
		renderList( 'po_test' );
		await screen.findByText( 'No transactions found.' );

		expect( screen.queryByLabelText( 'Show' ) ).not.toBeInTheDocument();
	} );
} );
