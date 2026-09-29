/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import { speak } from '@wordpress/a11y';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsPayouts } from '../payouts';
import { WooPaymentsPayoutDetailsPage } from '../payout-details';
import {
	getWooPaymentsDeposit,
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
	getWooPaymentsDepositsExportUrl,
	requestWooPaymentsDepositsExport,
} from '../overview/data';
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
import {
	getTestModeNoticeText,
	mockAccountMode,
} from './helpers/test-mode-account';

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposit: jest.fn(),
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	getWooPaymentsDepositsExportUrl: jest.fn(),
	requestWooPaymentsDepositsExport: jest.fn(),
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsTransactions: jest.fn(),
	getWooPaymentsTransactionsSummary: jest.fn(),
	requestWooPaymentsTransactionsExport: jest.fn(),
	getWooPaymentsTransactionsExportUrl: jest.fn(),
} ) );

const mockHistoryPush = jest.fn();
let mockNavigate: ( ( to: string ) => void ) | null = null;

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () => ( { push: mockHistoryPush } ),
} ) );

// Follows the list's settings-shell history pushes inside the memory router.
const RouterBridge = () => {
	const location = useLocation();
	const navigate = useNavigate();

	mockNavigate = ( to ) => navigate( to );

	return (
		<output data-testid="payout-details-route">
			{ `${ location.pathname }${ location.search }` }
		</output>
	);
};

jest.mock( '@wordpress/a11y', () => ( {
	speak: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => <div>Spotlight promotion</div>,
} ) );

const mockDataViews = jest.fn(
	( {
		data = [],
		fields = [],
		header,
		onChangeView,
		searchLabel,
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: Array< {
			id: string;
			render?: ( props: {
				item: Record< string, unknown >;
			} ) => ReactNode;
		} >;
		header?: ReactNode;
		onChangeView?: ( view: Record< string, unknown > ) => void;
		searchLabel?: string;
		view?: { search?: string };
	} ) => (
		<div data-testid="money-movement-dataviews">
			{ searchLabel && (
				<input
					type="search"
					aria-label={ searchLabel }
					value={ view.search || '' }
					readOnly
				/>
			) }
			<button
				type="button"
				onClick={ () =>
					onChangeView?.( {
						...view,
						page: 2,
						perPage: 10,
						search: 'Ada',
						sort: {
							field: 'amount',
							direction: 'asc',
						},
						fields: [ 'date', 'amount' ],
						layout: {
							table: {
								density: 'compact',
							},
						},
					} )
				}
			>
				Mock change DataViews columns
			</button>
			{ header }
			{ data.map( ( item ) => (
				<div key={ String( item.id || item.transaction_id ) }>
					{ fields.map( ( field ) => (
						<div key={ field.id }>
							{ field.render
								? field.render( { item } )
								: String( item[ field.id ] || '' ) }
						</div>
					) ) }
				</div>
			) ) }
		</div>
	)
);

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( props: Parameters< typeof mockDataViews >[ 0 ] ) =>
		mockDataViews( props ),
} ) );

const mockGetDeposit = getWooPaymentsDeposit as jest.MockedFunction<
	typeof getWooPaymentsDeposit
>;
const mockGetDeposits = getWooPaymentsDeposits as jest.MockedFunction<
	typeof getWooPaymentsDeposits
>;
const mockGetDepositsSummary =
	getWooPaymentsDepositsSummary as jest.MockedFunction<
		typeof getWooPaymentsDepositsSummary
	>;
const mockRequestDepositsExport =
	requestWooPaymentsDepositsExport as jest.MockedFunction<
		typeof requestWooPaymentsDepositsExport
	>;
const mockGetDepositsExportUrl =
	getWooPaymentsDepositsExportUrl as jest.MockedFunction<
		typeof getWooPaymentsDepositsExportUrl
	>;
const mockGetTransactions = getWooPaymentsTransactions as jest.MockedFunction<
	typeof getWooPaymentsTransactions
>;
const mockGetTransactionsSummary =
	getWooPaymentsTransactionsSummary as jest.MockedFunction<
		typeof getWooPaymentsTransactionsSummary
	>;
const mockRequestTransactionsExport =
	requestWooPaymentsTransactionsExport as jest.MockedFunction<
		typeof requestWooPaymentsTransactionsExport
	>;
const mockGetTransactionsExportUrl =
	getWooPaymentsTransactionsExportUrl as jest.MockedFunction<
		typeof getWooPaymentsTransactionsExportUrl
	>;
const mockSpeak = speak as jest.MockedFunction< typeof speak >;

describe( 'WooPayments payout details admin surface', () => {
	let anchorClickSpy: jest.SpyInstance;
	let originalClipboard: Clipboard | undefined;

	beforeEach( () => {
		anchorClickSpy = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation();
		originalClipboard = navigator.clipboard;
		setMockUserPreferences( {} );
		mockAccountMode( false );
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
		};
		mockGetDeposit.mockReset();
		mockGetDeposits.mockReset();
		mockGetDepositsSummary.mockReset();
		mockRequestDepositsExport.mockReset();
		mockGetDepositsExportUrl.mockReset();
		mockGetTransactions.mockReset();
		mockGetTransactionsSummary.mockReset();
		mockDataViews.mockClear();
		mockSpeak.mockReset();
		mockRequestTransactionsExport.mockReset();
		mockGetTransactionsExportUrl.mockReset();
		mockHistoryPush.mockReset();
		mockHistoryPush.mockImplementation( ( to: string ) => {
			// The settings shell's admin path carries the route in `path` and its query beside it.
			const url = new URL( to, 'http://example.com/wp-admin/' );
			const route = url.searchParams.get( 'path' ) || '';
			[ 'page', 'tab', 'path' ].forEach( ( key ) =>
				url.searchParams.delete( key )
			);
			mockNavigate?.( `${ route }?${ url.searchParams.toString() }` );
		} );
	} );

	afterEach( () => {
		anchorClickSpy.mockRestore();
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: originalClipboard,
		} );
	} );

	it( 'links payout history rows to native payout details', async () => {
		mockGetDeposits.mockResolvedValue( {
			total_count: 1,
			data: [
				{
					id: 'po_test',
					date: '2026-06-18',
					type: 'deposit',
					amount: 12500,
					status: 'paid',
					currency: 'usd',
				},
			],
		} );
		mockGetDepositsSummary.mockResolvedValue( {
			count: 1,
			total: 12500,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		expect( screen.getByText( 'Spotlight promotion' ) ).toBeInTheDocument();

		const detailsLink = await screen.findByRole( 'link', {
			name: 'Jun 18, 2026 - view payout details for po_test',
		} );
		expect( detailsLink ).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts%2Fdetails&id=po_test'
		);
	} );

	it( 'uses URL query state for payout list and summary requests', async () => {
		mockGetDeposits.mockResolvedValue( {
			total_count: 1,
			data: [
				{
					id: 'po_test',
					date: '2026-06-18',
					type: 'deposit',
					amount: 12500,
					status: 'paid',
					currency: 'usd',
				},
			],
		} );
		mockGetDepositsSummary.mockResolvedValue( {
			count: 1,
			total: 12500,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/payouts?page=4&pagesize=50&sort=amount&direction=asc&search=po_test&status_is=paid&store_currency_is=usd',
				] }
			>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'Jun 18, 2026 - view payout details for po_test',
			} )
		).toBeInTheDocument();
		expect( mockGetDeposits ).toHaveBeenCalledWith(
			expect.objectContaining( {
				page: 4,
				pagesize: 50,
				sort: 'amount',
				direction: 'asc',
				match: 'po_test',
				status_is: 'paid',
				store_currency_is: 'usd',
			} )
		);
		expect( mockGetDepositsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( {
				status_is: 'paid',
				store_currency_is: 'usd',
			} )
		);
	} );

	it( 'offers payout exports with the active query', async () => {
		mockGetDeposits.mockResolvedValue( {
			total_count: 0,
			data: [],
		} );
		mockGetDepositsSummary.mockResolvedValue( {
			count: 0,
			total: 0,
			currency: 'usd',
		} );
		mockRequestDepositsExport.mockResolvedValue( {
			export_id: 'export_test',
		} );
		mockGetDepositsExportUrl.mockResolvedValue( {
			download_url: 'https://example.com/payouts.csv',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/payouts?status_is=paid&store_currency_is=usd',
				] }
			>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		await screen.findByRole( 'status' );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Download payouts' } )
			);
		} );
		expect(
			await screen.findByText(
				'Your payouts export has started downloading.'
			)
		).toHaveAttribute( 'role', 'status' );

		expect( mockRequestDepositsExport ).toHaveBeenCalledWith(
			expect.objectContaining( {
				status_is: 'paid',
				store_currency_is: 'usd',
			} )
		);
		expect( mockGetDepositsExportUrl ).toHaveBeenCalledWith(
			'export_test'
		);
	} );

	it( 'loads and renders payout detail data', async () => {
		mockGetDeposit.mockResolvedValue( {
			id: 'po_test',
			date: '2026-06-18',
			type: 'deposit',
			amount: 12500,
			status: 'paid',
			bankAccount: 'STRIPE TEST BANK **** 6789',
			bank_reference_key: 'REF123',
			currency: 'usd',
			automatic: true,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 3,
			total: 14000,
			fees: 1500,
			net: 12500,
			currency: 'usd',
		} );
		mockGetTransactions.mockResolvedValue( {
			total_count: 1,
			data: [
				{
					transaction_id: 'txn_payout',
					payment_intent_id: 'pi_payout',
					type: 'charge',
					amount: 12500,
					currency: 'usd',
					date: '2026-06-18',
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading payout details…'
		);
		expect(
			screen.getByRole( 'heading', { name: 'Payout details' } )
		).toBeInTheDocument();
		expect(
			await screen.findByText( 'STRIPE TEST BANK **** 6789' )
		).toBeInTheDocument();
		expect( mockGetDeposit ).toHaveBeenCalledWith( 'po_test' );
		expect( mockGetTransactionsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( {
				deposit_id: 'po_test',
			} )
		);
		expect( mockGetTransactions ).toHaveBeenCalledWith(
			expect.objectContaining( {
				deposit_id: 'po_test',
			} )
		);
		expect(
			await screen.findByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_payout',
			} )
		).toBeInTheDocument();
		expect( screen.getByText( 'REF123' ) ).toBeInTheDocument();
		// The payout amount and the transaction's amount.
		expect( screen.getAllByText( '$125.00' ) ).toHaveLength( 2 );
		// The transactions list summary, like the client's TableCard summary.
		expect( screen.getByText( '3 transactions' ) ).toBeInTheDocument();
		expect( screen.getByText( '$140.00 total' ) ).toBeInTheDocument();
		expect( screen.getByText( '$15.00 fees' ) ).toBeInTheDocument();
		expect( screen.getByText( '$125.00 net' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Payout details loaded.' ) ).toHaveAttribute(
			'role',
			'status'
		);
	} );

	it( 'lists the payout through the transactions list, scoped to the payout, with its export', async () => {
		mockGetDeposit.mockResolvedValue( {
			id: 'po_test',
			date: '2026-06-18',
			type: 'deposit',
			amount: 12500,
			status: 'paid',
			bankAccount: 'STRIPE TEST BANK **** 6789',
			bank_reference_key: 'REF123',
			currency: 'usd',
			automatic: true,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 3,
			total: 14000,
			fees: 1500,
			net: 12500,
			currency: 'usd',
		} );
		mockGetTransactions.mockResolvedValue( {
			total_count: 3,
			data: [
				{
					transaction_id: 'txn_payout',
					payment_intent_id: 'pi_payout',
					type: 'charge',
					amount: 12500,
					currency: 'usd',
					date: '2026-06-18',
				},
			],
		} );
		mockRequestTransactionsExport.mockResolvedValue( {
			export_id: 'export_payout',
		} );
		mockGetTransactionsExportUrl.mockResolvedValue( {
			download_url: 'https://example.com/payout.csv',
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
				<RouterBridge />
			</MemoryRouter>
		);

		await screen.findByText( 'Transactions loaded.' );
		// Client 11.1.0 `transactions/list/index.tsx:311`: every transactions column but the payout ones.
		expect( mockDataViews ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				searchLabel: 'Search transactions',
				paginationInfo: {
					totalItems: 3,
					totalPages: 1,
				},
			} )
		);
		const fieldIds = mockDataViews.mock.lastCall?.[ 0 ].fields?.map(
			( field ) => field.id
		);
		expect( fieldIds ).toEqual(
			expect.arrayContaining( [ 'order', 'channel', 'net', 'source' ] )
		);
		expect( fieldIds?.some( ( id ) => id.startsWith( 'deposit' ) ) ).toBe(
			false
		);
		expect( mockGetTransactions ).toHaveBeenLastCalledWith( {
			deposit_id: 'po_test',
			page: 1,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
		} );
		// The payout scope is not a filter the merchant can remove.
		expect( mockDataViews.mock.lastCall?.[ 0 ].view ).toEqual(
			expect.objectContaining( { filters: [] } )
		);

		// F-T60-8: the list's own export, scoped to the payout.
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Download transactions' } )
			);
		} );
		expect( mockRequestTransactionsExport ).toHaveBeenCalledWith(
			expect.objectContaining( { deposit_id: 'po_test' } )
		);
		expect( mockGetTransactionsExportUrl ).toHaveBeenCalledWith(
			'export_payout'
		);

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock change DataViews columns',
			} )
		);

		// Paging, sorting and search stay in the payout's URL.
		await waitFor( () =>
			expect( mockGetTransactions ).toHaveBeenLastCalledWith( {
				deposit_id: 'po_test',
				page: 2,
				pagesize: 10,
				search: 'Ada',
				sort: 'amount',
				direction: 'asc',
			} )
		);
		expect(
			screen.getByTestId( 'payout-details-route' )
		).toHaveTextContent(
			'/woopayments/payouts/details?paged=2&pagesize=10&sort=amount&direction=asc&search=Ada&id=po_test'
		);
		// The client's payout details reuse the transactions list and its key.
		expect( mockUpdateUserPreferences ).toHaveBeenCalledWith( {
			wc_payments_transactions_hidden_columns: expect.arrayContaining( [
				'type',
				'net',
				'deposit_id',
				'deposit_status',
			] ),
		} );
	} );

	it( 'copies the bank reference ID to the clipboard and announces the result', async () => {
		const writeText = jest.fn().mockResolvedValue( undefined );

		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: {
				writeText,
			},
		} );
		mockGetDeposit.mockResolvedValue( {
			id: 'po_test',
			date: '2026-06-18',
			type: 'deposit',
			amount: 12500,
			status: 'paid',
			bankAccount: 'STRIPE TEST BANK **** 6789',
			bank_reference_key: 'REF123',
			currency: 'usd',
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 0,
			total: 0,
			fees: 0,
			net: 0,
			currency: 'usd',
		} );
		mockGetTransactions.mockResolvedValue( {
			total_count: 0,
			data: [],
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByText( 'REF123' ) ).toBeInTheDocument();

		const copyButton = screen.getByRole( 'button', {
			name: 'Copy bank reference ID to clipboard',
		} );
		await act( async () => {
			await userEvent.click( copyButton );
			await userEvent.click( copyButton );
		} );

		expect( writeText ).toHaveBeenCalledTimes( 2 );
		expect( writeText ).toHaveBeenCalledWith( 'REF123' );
		// The copy result is announced through exactly one channel: speak().
		// speak() re-announces even the identical message on the second click,
		// which a deduped aria-live region would not.
		expect( mockSpeak ).toHaveBeenCalledTimes( 2 );
		expect( mockSpeak ).toHaveBeenCalledWith(
			'Bank reference ID copied.',
			'polite'
		);
		// The shared status live region must not duplicate the announcement.
		await waitFor( () =>
			expect(
				screen.getByText( 'Payout details loaded.' )
			).toHaveAttribute( 'role', 'status' )
		);
		screen
			.getAllByRole( 'status' )
			.forEach( ( region ) =>
				expect( region ).not.toHaveTextContent(
					'Bank reference ID copied.'
				)
			);
		expect(
			screen.queryByText( 'Bank reference ID copied.' )
		).not.toBeInTheDocument();
	} );

	it( 'links to all transactions for a normal payout', async () => {
		mockGetDeposit.mockResolvedValue( {
			id: 'po_test',
			date: '2026-06-18',
			type: 'deposit',
			amount: 12500,
			status: 'paid',
			bankAccount: 'STRIPE TEST BANK **** 6789',
			bank_reference_key: 'REF123',
			currency: 'usd',
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 1,
			total: 12500,
			fees: 0,
			net: 12500,
			currency: 'usd',
		} );
		mockGetTransactions.mockResolvedValue( {
			total_count: 0,
			data: [],
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		const allTransactionsLink = await screen.findByRole( 'link', {
			name: 'View all transactions in this payout',
		} );

		expect( allTransactionsLink ).toHaveAttribute(
			'href',
			expect.stringContaining(
				'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions'
			)
		);
		expect( allTransactionsLink ).toHaveAttribute(
			'href',
			expect.stringContaining( 'deposit_id=po_test' )
		);
	} );

	it( 'does not render embedded transaction history for instant payouts', async () => {
		mockGetDeposit.mockResolvedValue( {
			id: 'po_test',
			date: '2026-06-18',
			type: 'deposit',
			amount: 12500,
			status: 'paid',
			bankAccount: 'STRIPE TEST BANK **** 6789',
			bank_reference_key: 'REF123',
			currency: 'usd',
			automatic: false,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 1,
			total: 12500,
			fees: 0,
			net: 12500,
			currency: 'usd',
		} );
		mockGetTransactions.mockResolvedValue( {
			total_count: 1,
			data: [
				{
					transaction_id: 'txn_payout',
					type: 'charge',
					amount: 12500,
					currency: 'usd',
					date: '2026-06-18',
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText(
				( _, element ) =>
					element?.textContent ===
					"We're unable to show transaction history on instant payouts. Learn more"
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Learn more' } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/payouts/instant-payouts/#request-an-instant-payout'
		);
		// Client 11.1.0 `deposits/details/index.tsx:330-353`: no list, so no list or summary request.
		expect( mockGetTransactions ).not.toHaveBeenCalled();
		expect( mockGetTransactionsSummary ).not.toHaveBeenCalled();
		expect(
			screen.queryByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_payout',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', {
				name: 'View all transactions in this payout',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'uses withdrawal wording for withdrawal details', async () => {
		mockGetDeposit.mockResolvedValue( {
			id: 'po_test',
			date: '2026-06-18',
			type: 'withdrawal',
			amount: -12500,
			status: 'paid',
			bankAccount: 'STRIPE TEST BANK **** 6789',
			bank_reference_key: 'REF123',
			currency: 'usd',
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 1,
			total: -12500,
			fees: 0,
			net: -12500,
			currency: 'usd',
		} );
		mockGetTransactions.mockResolvedValue( {
			total_count: 0,
			data: [],
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Withdrawal details' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Withdrawal ID' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: 'View all transactions in this withdrawal',
			} )
		).toBeInTheDocument();
	} );

	it( 'announces payout detail errors', async () => {
		mockGetDeposit.mockRejectedValue( new Error( 'Payout unavailable.' ) );
		mockGetTransactionsSummary.mockResolvedValue( {} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Payout unavailable.'
		);
	} );

	it( 'requires a payout ID before loading details', async () => {
		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts/details' ] }>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'A payout ID is required.'
		);
		expect( mockGetDeposit ).not.toHaveBeenCalled();
		expect( mockGetTransactionsSummary ).not.toHaveBeenCalled();
	} );

	// Client 11.1.0 deposits/details/index.tsx:317.
	it.each( [ true, false ] )(
		'shows the payout details test-mode notice only in test mode (test mode: %s)',
		async ( testMode ) => {
			mockAccountMode( testMode );
			mockGetDeposit.mockResolvedValue( {
				id: 'po_test',
				date: '2026-06-18',
				type: 'deposit',
				amount: 12500,
				status: 'paid',
				currency: 'usd',
				automatic: true,
			} );
			mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );
			mockGetTransactions.mockResolvedValue( {
				total_count: 0,
				data: [],
			} );

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/payouts/details?id=po_test',
					] }
				>
					<WooPaymentsPayoutDetailsPage />
				</MemoryRouter>
			);

			expect( await getTestModeNoticeText() ).toBe(
				testMode
					? 'WooPayments was in test mode when these payouts were created. To view live payouts, disable test mode in WooPayments settings.'
					: null
			);
		}
	);
} );
