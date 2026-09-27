/**
 * External dependencies
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';
import { downloadCSVFile } from '@woocommerce/csv-export';
import { getQuery } from '@woocommerce/navigation';
import type { ReactNode } from 'react';
import { MemoryRouter, useNavigate } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionsPage } from '../money-movement/transactions-page';
import {
	getWooPaymentsAuthorizations,
	getWooPaymentsAuthorizationsSummary,
	getWooPaymentsFraudOutcomeTransactionSearch,
	getWooPaymentsFraudOutcomeTransactions,
	getWooPaymentsFraudOutcomeTransactionsExport,
	getWooPaymentsFraudOutcomeTransactionsSummary,
	getWooPaymentsTransactions,
	getWooPaymentsTransactionsSummary,
} from '../money-movement/data';
import { formatAmount, formatDateTime } from '../money-movement/utils';
import { setMockUserPreferences } from './helpers/user-preferences';

// Client 11.1.0 references: client/transactions/index.tsx:57-61,93-102 (tab),
// client/transactions/blocked/index.tsx and blocked/columns.tsx (view),
// client/transactions/fraud-protection/autocompleter.tsx (search),
// client/data/transactions/resolvers.js:121-216 (requests).

const mockHistoryPush = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockSearch = jest.fn();

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => ( { push: mockHistoryPush } ),
	getQuery: jest.fn( () => ( {} ) ),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

jest.mock( '@woocommerce/csv-export', () => ( {
	...jest.requireActual( '@woocommerce/csv-export' ),
	downloadCSVFile: jest.fn(),
} ) );

jest.mock( '@woocommerce/components', () => ( {
	Search: ( props: {
		placeholder: string;
		onChange: ( values: Array< { key: string; label: string } > ) => void;
	} ) => {
		mockSearch( props );

		return (
			<button
				type="button"
				onClick={ () =>
					props.onChange( [
						{ key: 'Ada Lovelace', label: 'Ada Lovelace' },
						{ key: 'Order #1521', label: 'Order #1521' },
					] )
				}
			>
				{ `Mock search: ${ props.placeholder }` }
			</button>
		);
	},
} ) );

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	dispatch: jest.fn( () => ( {
		createErrorNotice: mockCreateErrorNotice,
		createSuccessNotice: jest.fn(),
	} ) ),
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
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: Array< {
			id: string;
			label?: ReactNode;
			header?: ReactNode;
			render?: ( props: {
				item: Record< string, unknown >;
			} ) => ReactNode;
		} >;
		header?: ReactNode;
		view?: { fields?: string[] };
	} ) => {
		const visibleFields = fields.filter(
			( field ) => ! view.fields || view.fields.includes( field.id )
		);

		return (
			<div>
				{ header }
				<div role="row">
					{ visibleFields.map( ( field ) => (
						<div key={ field.id } role="columnheader">
							{ field.header || field.label }
						</div>
					) ) }
				</div>
				{ data.map( ( item, index ) => (
					<div role="row" key={ index }>
						{ visibleFields.map( ( field ) => (
							<div key={ field.id } role="cell">
								{ field.render?.( { item } ) }
							</div>
						) ) }
					</div>
				) ) }
			</div>
		);
	},
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsAuthorizations: jest.fn(),
	getWooPaymentsAuthorizationsSummary: jest.fn(),
	getWooPaymentsFraudOutcomeTransactionSearch: jest.fn(),
	getWooPaymentsFraudOutcomeTransactions: jest.fn(),
	getWooPaymentsFraudOutcomeTransactionsExport: jest.fn(),
	getWooPaymentsFraudOutcomeTransactionsSummary: jest.fn(),
	getWooPaymentsTransactions: jest.fn(),
	getWooPaymentsTransactionsSummary: jest.fn(),
	getWooPaymentsTransactionSearch: jest.fn(),
} ) );

const mocked = < T extends ( ...args: never[] ) => unknown >( fn: T ) =>
	fn as unknown as jest.MockedFunction< T >;

const mockRecordEvent = mocked( recordEvent );
const mockDownloadCSVFile = mocked( downloadCSVFile );
const mockGetQuery = mocked( getQuery );
const mockGetFraudOutcomes = mocked( getWooPaymentsFraudOutcomeTransactions );
const mockGetFraudOutcomesSummary = mocked(
	getWooPaymentsFraudOutcomeTransactionsSummary
);
const mockGetFraudOutcomesExport = mocked(
	getWooPaymentsFraudOutcomeTransactionsExport
);
const mockGetFraudOutcomeSearch = mocked(
	getWooPaymentsFraudOutcomeTransactionSearch
);

// Rows as the native fraud-outcomes route returns them
// (WooPaymentsMoneyMovementOrderService::build_fraud_outcome_transactions_order_info).
const ADA = {
	order_id: 1520,
	payment_intent: { id: 'pi_blocked_ada', status: 'canceled' },
	amount: 5000,
	currency: 'usd',
	customer_name: 'Ada Lovelace',
	created: '2026-09-20T10:15:00Z',
	status: 'block',
	fraud_meta_box_type: 'block',
};
const GRACE = {
	order_id: 1521,
	payment_intent: { id: '', status: '' },
	amount: 1250,
	currency: 'usd',
	customer_name: 'Grace Hopper',
	created: '2026-09-21T08:05:00Z',
	status: 'block',
	fraud_meta_box_type: 'review_blocked',
};
const REVIEW_ROW = {
	order_id: 1522,
	payment_intent: { id: 'pi_review', status: 'requires_capture' },
	amount: 700,
	currency: 'usd',
	customer_name: 'Katherine Johnson',
	created: '2026-09-22T12:00:00Z',
	status: 'review',
	fraud_meta_box_type: 'review',
};

let navigateTo: ( path: string ) => void = () => undefined;

const NavigationProbe = () => {
	navigateTo = useNavigate();

	return null;
};

const renderAt = ( path: string ) =>
	render(
		<MemoryRouter initialEntries={ [ path ] }>
			<NavigationProbe />
			<WooPaymentsTransactionsPage />
		</MemoryRouter>
	);

const getPageViewPaths = () =>
	mockRecordEvent.mock.calls
		.filter( ( [ name ] ) => name === 'page_view' )
		.map( ( [ , properties ] ) => ( properties as { path: string } ).path );

describe( 'WooPayments Blocked transactions tab', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		setMockUserPreferences( {} );
		window.wcSettings = {
			...window.wcSettings,
			adminUrl: 'http://example.com/wp-admin/',
		};
		mockGetQuery.mockReturnValue( {} );
		mocked( getWooPaymentsTransactions ).mockResolvedValue( { data: [] } );
		mocked( getWooPaymentsTransactionsSummary ).mockResolvedValue( {} );
		mocked( getWooPaymentsAuthorizations ).mockResolvedValue( {
			data: [],
		} );
		mocked( getWooPaymentsAuthorizationsSummary ).mockResolvedValue( {
			count: 0,
		} );
		mockGetFraudOutcomes.mockResolvedValue( { data: [ ADA, GRACE ] } );
		mockGetFraudOutcomesSummary.mockResolvedValue( {
			count: 2,
			total: 6250,
			currencies: [ 'usd' ],
		} );
	} );

	it( 'lists blocked fraud outcomes in the four client columns with its summary and page view', async () => {
		renderAt( '/woopayments/transactions?view=blocked' );

		const adaLink = await screen.findByRole( 'link', {
			name: 'Ada Lovelace',
		} );

		expect(
			screen
				.getAllByRole( 'columnheader' )
				.map( ( header ) => header.textContent )
		).toEqual( [ 'Date / Time', 'Amount', 'Customer', 'Status' ] );

		const [ , adaRow, graceRow ] = screen.getAllByRole( 'row' );
		expect(
			within( adaRow )
				.getAllByRole( 'cell' )
				.map( ( cell ) => cell.textContent )
		).toEqual( [
			formatDateTime( ADA.created ),
			formatAmount( 5000, 'usd' ),
			'Ada Lovelace',
			'Payment blocked',
		] );
		expect(
			within( graceRow )
				.getAllByRole( 'cell' )
				.map( ( cell ) => cell.textContent )
		).toEqual( [
			formatDateTime( GRACE.created ),
			formatAmount( 1250, 'usd' ),
			'Grace Hopper',
			'Payment blocked',
		] );

		// Every cell but Status links to the details of payment_intent.id || order_id.
		expect( within( adaRow ).getAllByRole( 'link' ) ).toHaveLength( 3 );
		expect( adaLink ).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=pi_blocked_ada'
		);
		expect(
			within( graceRow ).getByRole( 'link', { name: 'Grace Hopper' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=1521'
		);

		expect( screen.getByText( '2 transactions(s)' ) ).toBeInTheDocument();
		expect(
			screen.getByText( `${ formatAmount( 6250, 'usd' ) } blocked` )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Blocked transactions' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Blocked' } )
		).toHaveAttribute( 'aria-current', 'page' );

		expect( mockGetFraudOutcomes ).toHaveBeenCalledWith( {
			status: 'block',
			page: 1,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
		} );
		expect( mockGetFraudOutcomesSummary ).toHaveBeenCalledWith( {
			status: 'block',
		} );
		expect( getWooPaymentsTransactions ).not.toHaveBeenCalled();
		expect( getPageViewPaths() ).toEqual( [
			'payments_transactions_blocked',
		] );
	} );

	// Client 11.1.0 `data/transactions/hooks.ts:375-404`: the summary selector
	// re-resolves only when `status` or `search` changes; the list re-resolves
	// on `paged`, `per_page`, `orderby`, `order` and `search` (`:334-373`).
	it( "hides the columns stored under the client's blocked list key", async () => {
		setMockUserPreferences( {
			wc_payments_transactions_blocked_hidden_columns: [ 'customer' ],
		} );
		renderAt( '/woopayments/transactions?view=blocked' );

		await screen.findByText( '2 transactions(s)' );
		expect(
			screen
				.getAllByRole( 'columnheader' )
				.map( ( header ) => header.textContent )
		).toEqual( [ 'Date / Time', 'Amount', 'Status' ] );
	} );

	it( 'refetches the summary only when the search changes, like the client', async () => {
		renderAt( '/woopayments/transactions?view=blocked' );
		await screen.findByRole( 'link', { name: 'Ada Lovelace' } );

		for ( const path of [
			'/woopayments/transactions?view=blocked&paged=2',
			'/woopayments/transactions?view=blocked&paged=2&sort=amount&direction=asc',
			'/woopayments/transactions?view=blocked&paged=2&sort=amount&direction=asc&pagesize=50',
		] ) {
			act( () => navigateTo( path ) );
			await screen.findByRole( 'link', { name: 'Ada Lovelace' } );
		}

		expect( mockGetFraudOutcomes ).toHaveBeenCalledTimes( 4 );
		expect( mockGetFraudOutcomes ).toHaveBeenLastCalledWith( {
			status: 'block',
			page: 2,
			pagesize: 50,
			sort: 'amount',
			direction: 'asc',
		} );
		expect( mockGetFraudOutcomesSummary ).toHaveBeenCalledTimes( 1 );

		act( () =>
			navigateTo(
				'/woopayments/transactions?view=blocked&paged=2&sort=amount&direction=asc&pagesize=50&search=Ada%20Lovelace'
			)
		);
		await screen.findByRole( 'link', { name: 'Ada Lovelace' } );

		await waitFor( () =>
			expect( mockGetFraudOutcomesSummary ).toHaveBeenCalledTimes( 2 )
		);
		expect( mockGetFraudOutcomesSummary ).toHaveBeenLastCalledWith( {
			status: 'block',
		} );
		expect( mockGetFraudOutcomes ).toHaveBeenCalledTimes( 5 );
	} );

	it( 'shows the total only for a single currency', async () => {
		mockGetFraudOutcomesSummary.mockResolvedValue( {
			count: 2,
			total: 6250,
			currencies: [ 'usd', 'eur' ],
		} );

		renderAt( '/woopayments/transactions?view=blocked' );

		expect(
			await screen.findByText( '2 transactions(s)' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( `${ formatAmount( 6250, 'usd' ) } blocked` )
		).not.toBeInTheDocument();
	} );

	it( 'treats the platform not-found error as an empty list without Export', async () => {
		const notFound = { code: 'wcpay_fraud_outcome_not_found' };
		mockGetFraudOutcomes.mockRejectedValue( notFound );
		mockGetFraudOutcomesSummary.mockRejectedValue( notFound );

		renderAt( '/woopayments/transactions?view=blocked' );

		expect(
			await screen.findByText( '0 transactions(s)' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Export' } )
		).not.toBeInTheDocument();
		// Client 11.1.0 renders an empty TableCard, whose empty text is
		// `@woocommerce/components` `table.tsx` "No data to display".
		expect( screen.getByText( 'No data to display' ) ).toBeInTheDocument();
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
	} );

	it( 'reports other load failures with the client notices', async () => {
		mockGetFraudOutcomes.mockRejectedValue( { code: 'wcpay_error' } );
		mockGetFraudOutcomesSummary.mockRejectedValue( {
			code: 'wcpay_error',
		} );

		renderAt( '/woopayments/transactions?view=blocked' );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error retrieving transactions.'
			)
		);
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Error retrieving on review transactions.'
		);
		// Client 11.1.0 `blocked/index.tsx:68-92` with `selectors.js:96-101`:
		// a failed summary resolves to `{}`, so the summary row is not shown.
		expect( screen.queryByText( /transactions\(s\)/ ) ).toBeNull();
	} );

	it( 'hides the summary while it reloads for a new search, like the client', async () => {
		renderAt( '/woopayments/transactions?view=blocked' );
		expect(
			await screen.findByText( '2 transactions(s)' )
		).toBeInTheDocument();

		mockGetFraudOutcomesSummary.mockReturnValue( new Promise( () => {} ) );
		act( () =>
			navigateTo(
				'/woopayments/transactions?view=blocked&search=Ada%20Lovelace'
			)
		);

		await waitFor( () =>
			expect( mockGetFraudOutcomesSummary ).toHaveBeenCalledTimes( 2 )
		);
		expect( screen.queryByText( /transactions\(s\)/ ) ).toBeNull();
	} );

	it( 'keeps the Blocked tab on the transactions and uncaptured views', async () => {
		const { unmount } = renderAt( '/woopayments/transactions' );

		const blockedTab = await screen.findByRole( 'link', {
			name: 'Blocked',
		} );
		expect( blockedTab ).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&view=blocked'
		);
		expect( blockedTab ).not.toHaveAttribute( 'aria-current' );
		expect( mockGetFraudOutcomes ).not.toHaveBeenCalled();
		expect( getPageViewPaths() ).toEqual( [ 'payments_transactions' ] );
		unmount();

		renderAt( '/woopayments/transactions?view=uncaptured' );

		expect(
			await screen.findByRole( 'link', { name: 'Blocked' } )
		).toBeInTheDocument();
	} );

	it( 'searches blocked transactions through the fraud-outcome autocompleter', async () => {
		renderAt(
			'/woopayments/transactions?view=blocked&search=Ada%20Lovelace&search=Order%20%231521'
		);

		await screen.findByRole( 'link', { name: 'Ada Lovelace' } );

		expect( mockGetFraudOutcomes ).toHaveBeenCalledWith( {
			status: 'block',
			page: 1,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
			'search[]': [ 'Ada Lovelace', 'Order #1521' ],
		} );

		const searchProps = mockSearch.mock.calls[
			mockSearch.mock.calls.length - 1
		][ 0 ] as {
			selected: Array< { key: string; label: string } >;
			autocompleter: {
				options: ( term: string ) => Promise< unknown[] >;
				getOptionCompletion: ( option: {
					key: string;
					label: string;
				} ) => unknown;
				getOptionLabel: (
					option: { key: string; label: string },
					query: string
				) => ReactNode;
			};
		};
		expect( searchProps ).toMatchObject( {
			inlineTags: true,
			showClearButton: true,
			placeholder: 'Search by order number or customer name',
			selected: [
				{ key: 'Ada Lovelace', label: 'Ada Lovelace' },
				{ key: 'Order #1521', label: 'Order #1521' },
			],
		} );

		mockGetFraudOutcomeSearch.mockResolvedValue( [
			{ key: 'customer-1520', label: 'Ada Lovelace' },
			{ key: 'customer-1521', label: 'Grace Hopper' },
		] );
		await expect(
			searchProps.autocompleter.options( 'ada' )
		).resolves.toEqual( [
			{ key: 'customer-1520', label: 'Ada Lovelace' },
		] );
		expect( mockGetFraudOutcomeSearch ).toHaveBeenCalledWith(
			'block',
			'ada'
		);
		// Client 11.1.0 `autocompleter.tsx:80-82`: completes to the label, not the key.
		expect(
			searchProps.autocompleter.getOptionCompletion( {
				key: 'customer-1520',
				label: 'Ada Lovelace',
			} )
		).toEqual( { key: 'Ada Lovelace', label: 'Ada Lovelace' } );
		// Client 11.1.0 `autocompleter.tsx:55-71`: the matched part is bold.
		const { container: optionLabel } = render(
			<>
				{ searchProps.autocompleter.getOptionLabel(
					{ key: 'customer-1520', label: 'Ada Lovelace' },
					'love'
				) }
			</>
		);
		expect(
			optionLabel.querySelector( '.woocommerce-search__result-name' )
		).toHaveAttribute( 'aria-label', 'Ada Lovelace' );
		expect(
			optionLabel.querySelector(
				'strong.components-form-token-field__suggestion-match'
			)
		).toHaveTextContent( /^Love$/ );
		expect( optionLabel ).toHaveTextContent( /^Ada Lovelace$/ );

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock search: Search by order number or customer name',
			} )
		);
		expect( mockHistoryPush ).toHaveBeenLastCalledWith(
			'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&paged=1&pagesize=25&search=Ada+Lovelace&search=Order+%231521&view=blocked'
		);
	} );

	it( 'exports the client CSV from the download route and records the event', async () => {
		mockGetQuery.mockReturnValue( {
			page: 'wc-settings',
			tab: 'checkout',
			path: '/woopayments/transactions',
			view: 'blocked',
		} );
		mockGetFraudOutcomesExport.mockResolvedValue( {
			data: [ ADA, GRACE, REVIEW_ROW ],
		} );

		renderAt( '/woopayments/transactions?view=blocked' );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Export' } )
		);

		await waitFor( () =>
			expect( mockDownloadCSVFile ).toHaveBeenCalledTimes( 1 )
		);
		expect( mockGetFraudOutcomesExport ).toHaveBeenCalledWith( {
			status: 'block',
			additional_status: 'review',
		} );

		const [ fileName, csv ] = mockDownloadCSVFile.mock.calls[ 0 ];
		expect( fileName ).toMatch(
			/^blocked-transactions_\d{4}-\d{2}-\d{2}_tab-blocked-page\.csv$/
		);
		expect( csv ).toBe(
			[
				'"Date / Time",Amount,Customer,Status',
				`"${ formatDateTime(
					ADA.created
				) }",5000,"Ada Lovelace",block`,
				`"${ formatDateTime(
					GRACE.created
				) }",1250,"Grace Hopper",block`,
				`"${ formatDateTime(
					REVIEW_ROW.created
				) }",700,"Katherine Johnson",review`,
			].join( '\n' )
		);
		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_fraud_outcome_transactions_download',
			{ exported_transactions: 2, total_transactions: 2 }
		);
	} );

	// Client 11.1.0 `blocked/index.tsx:128-139` and `resolvers.js:203-216`:
	// the export carries the list's search and sort order.
	it( 'exports with the search and sort order of the list', async () => {
		mockGetFraudOutcomesExport.mockResolvedValue( { data: [ ADA ] } );
		mockGetQuery.mockReturnValue( {
			page: 'wc-settings',
			tab: 'checkout',
			path: '/woopayments/transactions',
			pagesize: '50',
			sort: 'amount',
			direction: 'asc',
			view: 'blocked',
		} );

		renderAt(
			'/woopayments/transactions?view=blocked&search=Ada%20Lovelace&search=Order%20%231521&sort=amount&direction=asc'
		);

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Export' } )
		);

		await waitFor( () =>
			expect( mockDownloadCSVFile ).toHaveBeenCalledTimes( 1 )
		);
		expect( mockGetFraudOutcomesExport ).toHaveBeenCalledTimes( 1 );
		expect( mockGetFraudOutcomesExport.mock.calls[ 0 ][ 0 ] ).toEqual( {
			status: 'block',
			sort: 'amount',
			direction: 'asc',
			additional_status: 'review',
			'search[]': [ 'Ada Lovelace', 'Order #1521' ],
		} );
		// Client 11.1.0 `blocked/index.tsx:128-148`: its admin URL names these
		// `tab=blocked-page`, `per_page`, `orderby` and `order`.
		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 0 ] ).toMatch(
			/^blocked-transactions_\d{4}-\d{2}-\d{2}_tab-blocked-page_per-page-50_orderby-amount_order-asc\.csv$/
		);
	} );

	it( 'shows the client error notice when the export fails', async () => {
		mockGetFraudOutcomesExport.mockRejectedValue( new Error( 'boom' ) );

		renderAt( '/woopayments/transactions?view=blocked' );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Export' } )
		);

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'There was a problem generating your export.'
			)
		);
		expect( mockDownloadCSVFile ).not.toHaveBeenCalled();
		expect( mockRecordEvent ).not.toHaveBeenCalledWith(
			'wcpay_fraud_outcome_transactions_download',
			expect.anything()
		);
	} );
} );
