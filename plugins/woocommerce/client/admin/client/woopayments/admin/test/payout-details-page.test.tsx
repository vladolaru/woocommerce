/**
 * External dependencies
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import { speak } from '@wordpress/a11y';
import {
	getSettings as getDateSettings,
	setSettings as setDateSettings,
} from '@wordpress/date';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { setSiteDateFormats } from './helpers/site-date-formats';
import { summaryItem } from './helpers/table-summary';
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

// Client 11.1.0 `deposits/list/index.tsx:133`: the payout date in the site date format.
beforeAll( setSiteDateFormats );

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
	// The payouts page notices' requests; left pending, so no notice shows.
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
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

const mockCreateErrorNotice = jest.fn();

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: jest.fn( ( storeName ) =>
			storeName === 'core/notices'
				? { createErrorNotice: mockCreateErrorNotice }
				: actual.dispatch( storeName )
		),
	};
} );

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
			name: 'June 18, 2026 - view payout details for po_test',
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
					'/woopayments/payouts?page=4&pagesize=50&sort=amount&direction=asc&status_is=paid&store_currency_is=usd&filter=advanced&match=any',
				] }
			>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'June 18, 2026 - view payout details for po_test',
			} )
		).toBeInTheDocument();
		expect( mockGetDeposits ).toHaveBeenCalledWith(
			expect.objectContaining( {
				page: 4,
				pagesize: 50,
				sort: 'amount',
				direction: 'asc',
				// Client 11.1.0 `data/deposits/resolvers.js:78`: the advanced filters' "match any".
				match: 'any',
				status_is: 'paid',
				store_currency_is: 'usd',
			} )
		);
		expect( mockGetDepositsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( {
				match: 'any',
				status_is: 'paid',
				store_currency_is: 'usd',
			} )
		);
	} );

	it( 'offers payout exports with the active query', async () => {
		// Client 11.1.0 offers Export only when the list has rows.
		mockGetDeposits.mockResolvedValue( {
			total_count: 1,
			data: [ { id: 'po_test', type: 'deposit', status: 'paid' } ],
		} as never );
		mockGetDepositsSummary.mockResolvedValue( {
			count: 1,
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
					'/woopayments/payouts?status_is=paid&store_currency_is=usd&filter=advanced&match=any',
				] }
			>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		await screen.findByRole( 'status' );
		const exportButton = await screen.findByRole( 'button', {
			name: 'Export',
		} );
		await act( async () => {
			await userEvent.click( exportButton );
		} );
		// Monitor ruling N-243: a core success notice.
		expect(
			(
				await screen.findByText(
					'Your payouts export has started downloading.',
					{
						selector: '.components-notice__content',
					}
				)
			).closest( '.components-notice' )
		).toHaveClass( 'is-success' );

		expect( mockRequestDepositsExport ).toHaveBeenCalledWith(
			expect.objectContaining( {
				match: 'any',
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
		// Client 11.1.0 deposits/details/index.tsx:245-305: the details card is titled "Payout details"
		// and lists the bank account and reference in one row, without the payout ID.
		const detailsCard = (
			await screen.findByRole( 'heading', { name: 'Payout details' } )
		).closest( '.components-card' ) as HTMLElement;
		expect(
			Array.from( detailsCard.querySelectorAll( 'dt' ) ).map(
				( term ) => term.textContent
			)
		).toEqual( [ 'Bank account', 'Bank reference ID' ] );
		expect(
			within( detailsCard ).getByText( 'STRIPE TEST BANK **** 6789' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'po_test' ) ).not.toBeInTheDocument();
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
		// The overview amount, the row and the summary's net.
		expect( screen.getAllByText( '$125.00' ) ).toHaveLength( 3 );
		// The transactions list summary, like the client's TableCard summary.
		expect(
			screen.getByText( summaryItem( '3 transactions' ) )
		).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '$140.00 total' ) )
		).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '$15.00 fees' ) )
		).toBeInTheDocument();
		expect(
			screen.getByText( summaryItem( '$125.00 net' ) )
		).toBeInTheDocument();
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
		// Client 11.1.0 `deposits/details/index.tsx:356`: the list's own card, not a card inside another.
		const listCard = document.querySelector(
			'.woocommerce-woopayments-money-movement-dataviews'
		);
		expect( listCard ).not.toBeNull();
		expect(
			listCard?.parentElement?.closest(
				'.woocommerce-woopayments-overview-card'
			)
		).toBeNull();
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
		const exportButton = await screen.findByRole( 'button', {
			name: 'Export',
		} );
		await act( async () => {
			await userEvent.click( exportButton );
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

	it( 'adds no navigation links the client does not have', async () => {
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

		// Client 11.1.0 deposits/details/index.tsx: no back link and no link to all the payout's transactions.
		expect(
			await screen.findByRole( 'heading', { name: 'Payout details' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Back to payout history' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', {
				name: 'View all transactions in this payout',
			} )
		).not.toBeInTheDocument();
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
					element?.classList.contains( 'components-card__body' ) &&
					!! element.textContent?.startsWith(
						"We're unable to show transaction history on instant payouts. Learn more"
					)
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: ( accessibleName: string ) =>
					accessibleName.startsWith( 'Learn more' ),
			} )
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
		// Client 11.1.0 transactions/list/index.tsx:590: the list keeps its "Transactions" title.
		expect( await screen.findByText( 'Transactions' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Withdrawal transactions' )
		).not.toBeInTheDocument();
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

	// Client 11.1.0 deposits/details/index.tsx:319-323: the core summary placeholder while loading.
	it( 'shows the summary placeholder while the payout loads', async () => {
		mockGetDeposit.mockReturnValue( new Promise( () => {} ) );

		const { container } = render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		const placeholder = container.querySelector(
			'.woocommerce-summary.is-placeholder'
		);
		expect( placeholder ).not.toBeNull();
		expect(
			placeholder?.querySelectorAll( '.woocommerce-summary__item' )
		).toHaveLength( 2 );
		expect(
			screen.queryByText( 'Loading payout details…', {
				selector: '.woocommerce-woopayments-money-movement__status',
			} )
		).not.toBeInTheDocument();
	} );

	// Client 11.1.0 deposits/details/index.tsx:135-144 and data/deposits/resolvers.js:44-51.
	it( 'shows the client not-found notice and snackbar when the payout fails to load', async () => {
		mockCreateErrorNotice.mockClear();
		mockGetDeposit.mockRejectedValue( new Error( 'Payout unavailable.' ) );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/payouts/details?id=po_test' ] }
			>
				<WooPaymentsPayoutDetailsPage />
			</MemoryRouter>
		);

		expect(
			(
				await screen.findByText(
					'The deposit you are looking for cannot be found.'
				)
			).closest( '.components-notice' )
		).toHaveClass( 'is-error' );
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Error retrieving payout.'
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

	// Client 11.1.0 `deposits/details/index.tsx:104-243` and `deposits/strings.ts`.
	describe( 'payout overview', () => {
		// Client 11.1.0 `deposits/details/__tests__/index.test.tsx` fixture.
		const clientDeposit = {
			id: 'po_mock',
			date: '2020-01-02 17:46:02',
			type: 'deposit',
			amount: 2000,
			status: 'paid',
			bankAccount: 'MOCK BANK •••• 1234 (USD)',
			automatic: true,
			fee: 30,
			fee_percentage: 1.5,
			currency: 'USD',
		};
		let originalDateSettings: ReturnType< typeof getDateSettings >;

		const renderPayout = async (
			deposit: Parameters< typeof mockGetDeposit.mockResolvedValue >[ 0 ]
		) => {
			mockGetDeposit.mockResolvedValue( deposit );
			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/payouts/details?id=po_mock',
					] }
				>
					<WooPaymentsPayoutDetailsPage />
				</MemoryRouter>
			);
			await screen.findByText( 'MOCK BANK •••• 1234 (USD)' );
		};

		const getOverviewItem = ( label: string ) =>
			screen.getByText( label ).closest( 'li' );

		beforeEach( () => {
			originalDateSettings = getDateSettings();
			// The client test's `wcpaySettings.dateFormat`.
			setDateSettings( {
				...originalDateSettings,
				formats: { ...originalDateSettings.formats, date: 'M j, Y' },
			} );
			mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );
			mockGetTransactions.mockResolvedValue( {
				total_count: 0,
				data: [],
			} );
		} );

		afterEach( () => {
			setDateSettings( originalDateSettings );
		} );

		it( 'renders an automatic payout with its date, status and amount', async () => {
			await renderPayout( clientDeposit );

			expect(
				getOverviewItem( 'Payout date: Jan 2, 2020' )
			).toHaveTextContent( 'Completed (paid)' );
			// Client 11.1.0 deposits/details/index.tsx:48-66: core's OrderStatus dot, not a chip.
			expect(
				getOverviewItem( 'Payout date: Jan 2, 2020' )?.querySelector(
					'.woocommerce-order-status .woocommerce-order-status__indicator.is-paid'
				)
			).not.toBeNull();
			expect( screen.getByText( '$20.00' ) ).toBeInTheDocument();
			expect( screen.queryByText( 'Payout amount' ) ).toBeNull();
			expect( screen.queryByText( /service fee/ ) ).toBeNull();
		} );

		it( 'renders an automatic withdrawal as deducted', async () => {
			await renderPayout( {
				...clientDeposit,
				type: 'withdrawal',
				amount: -2000,
			} );

			expect(
				getOverviewItem( 'Withdrawal date: Jan 2, 2020' )
			).toHaveTextContent( 'Completed (deducted)' );
			expect(
				screen.queryByText( 'Completed (paid)' )
			).not.toBeInTheDocument();
		} );

		it.each( [
			[ 'pending', 'Pending' ],
			[ 'in_transit', 'In transit' ],
			[ 'canceled', 'Canceled' ],
			[ 'failed', 'Failed' ],
		] )(
			'labels the %s status like the client',
			async ( status, label ) => {
				await renderPayout( { ...clientDeposit, status } );

				expect(
					getOverviewItem( 'Payout date: Jan 2, 2020' )
				).toHaveTextContent( label );
			}
		);

		it( 'breaks down an instant payout into amount, service fee and net amount', async () => {
			await renderPayout( { ...clientDeposit, automatic: false } );

			expect(
				screen.getByRole( 'list', { name: 'Payout overview' } )
			).toBeInTheDocument();
			expect(
				getOverviewItem( 'Instant payout date: Jan 2, 2020' )
			).toHaveTextContent( 'Completed (paid)' );
			expect( getOverviewItem( 'Payout amount' ) ).toHaveTextContent(
				'$20.30'
			);
			const fee = getOverviewItem( '1.5% service fee' );
			expect( fee ).toHaveTextContent( '$0.30' );
			expect( fee?.lastElementChild ).toHaveClass(
				'woocommerce-woopayments-payout-overview__value--fee'
			);
			const net = getOverviewItem( 'Net payout amount' );
			expect( net ).toHaveTextContent( '$20.00' );
			expect( net?.lastElementChild ).toHaveClass(
				'woocommerce-woopayments-payout-overview__value--net'
			);
		} );

		it( 'does not mark a zero service fee', async () => {
			await renderPayout( {
				...clientDeposit,
				automatic: false,
				fee: 0,
				fee_percentage: 0,
			} );

			const fee = getOverviewItem( '0% service fee' );
			expect( fee ).toHaveTextContent( '$0.00' );
			expect( fee?.lastElementChild ).not.toHaveClass(
				'woocommerce-woopayments-payout-overview__value--fee'
			);
		} );

		it( 'uses withdrawal wording for an instant withdrawal breakdown', async () => {
			await renderPayout( {
				...clientDeposit,
				type: 'withdrawal',
				automatic: false,
			} );

			expect(
				screen.getByRole( 'list', { name: 'Withdrawal overview' } )
			).toBeInTheDocument();
			expect(
				getOverviewItem( 'Withdrawal date: Jan 2, 2020' )
			).toHaveTextContent( 'Completed (deducted)' );
			expect( getOverviewItem( 'Withdrawal amount' ) ).toHaveTextContent(
				'$20.30'
			);
			expect(
				getOverviewItem( 'Net withdrawal amount' )
			).toHaveTextContent( '$20.00' );
		} );

		// Client 11.1.0 `deposits/strings.ts:37-147`.
		it.each( [
			[
				'insufficient_funds',
				'Your account has insufficient funds to cover your negative balance.',
			],
			[
				'bank_account_restricted',
				'The bank account has restrictions on either the type or number of transfers allowed. This normally indicates that the bank account is a savings or other non-checking account.',
			],
			[
				'debit_not_authorized',
				'Debit transactions are not approved on your bank account. Bank accounts need to be set up for both credit and debit transfers.',
			],
			[
				'invalid_card',
				'The card used was invalid. This usually means the card number is invalid or the account has been closed.',
			],
			[
				'declined',
				'The bank has declined this transfer. Please contact the bank for more information.',
			],
			[
				'invalid_transaction',
				'The transfer was refused by the issuing bank because this type of payment is not permitted for this card. Please contact the issuing bank for clarification.',
			],
			[
				'refer_to_card_issuer',
				'The transfer was refused by the card issuer. Please contact the issuing bank for clarification.',
			],
			[
				'unsupported_card',
				'The bank no longer supports transfers to this card.',
			],
			[
				'lost_or_stolen_card',
				'The card used has been reported lost or stolen. Please contact the issuing bank for clarification.',
			],
			[
				'invalid_issuer',
				'The issuer specified by the card number does not exist. Please verify card details.',
			],
			[
				'expired_card',
				'The card used has expired. Please switch to a different card or payment method. Contact the issuing bank for clarification.',
			],
			[
				'could_not_process',
				'The bank or the payment processor could not process this transfer.',
			],
			[
				'invalid_account_number',
				'The bank account details on file are probably incorrect. While the routing number appears correct, the account number is invalid.',
			],
			[
				'incorrect_account_holder_name',
				'The bank account holder name on file appears to be incorrect.',
			],
			[ 'account_closed', 'The bank account has been closed.' ],
			[
				'no_account',
				'The bank account details on file are probably incorrect. No bank account could be located with those details.',
			],
			[
				'exceeds_amount_limit',
				'The card issuer has declined the transaction as it will exceed the card limit. Please switch to a different card or payment method. Contact the issuing bank for clarification.',
			],
			[ 'account_frozen', 'The bank account has been frozen.' ],
			[
				'issuer_unavailable',
				'The issuing bank is currently unavailable. Our system will automatically try again on your next payout date, or you can switch to a different payout method.',
			],
			[
				'invalid_currency',
				'The bank was unable to process this transfer because of its currency. This is probably because the bank account cannot accept payments in that currency.',
			],
			[
				'incorrect_account_type',
				'The bank account type is incorrect. This value can only be checking or savings in most countries. In Japan, it can only be futsu or toza.',
			],
			[
				'incorrect_account_holder_details',
				'The bank could not process this transfer. Please check that the entered bank account details match the corresponding account bank statement exactly.',
			],
			[
				'bank_ownership_changed',
				'The destination bank account is no longer valid because its branch has changed ownership.',
			],
			[
				'exceeds_count_limit',
				'The selected card has exceeded its card usage frequency limit. Please switch to a different card or payment method. Contact the issuing bank for clarification.',
			],
			[
				'incorrect_account_holder_address',
				'Your bank notified us that the bank account holder address on file is incorrect.',
			],
			[
				'incorrect_account_holder_tax_id',
				'Your bank notified us that the bank account holder tax ID on file is incorrect.',
			],
			[
				'invalid_account_number_length',
				'Your bank notified us that the bank account number is too long.',
			],
		] )(
			'maps the %s failure code to the client message',
			async ( failureCode, message ) => {
				await renderPayout( {
					...clientDeposit,
					status: 'failed',
					failure_code: failureCode,
					failure_message: 'Raw Stripe failure message',
				} );

				expect( screen.getByText( 'Failure reason:' ) ).toBeVisible();
				expect( screen.getByText( message ) ).toBeInTheDocument();
				expect(
					screen.queryByText( 'Raw Stripe failure message' )
				).not.toBeInTheDocument();
			}
		);

		it.each( [
			[ 'an unmapped failure code', 'unknown_failure_code' ],
			[ 'no failure code', undefined ],
		] )(
			'falls back to the raw failure message for %s',
			async ( _case, failureCode ) => {
				await renderPayout( {
					...clientDeposit,
					status: 'failed',
					failure_code: failureCode,
					failure_message:
						'Failure error message originally captured from the Stripe Payout object',
				} );

				expect(
					screen.getByText(
						'Failure error message originally captured from the Stripe Payout object'
					)
				).toBeInTheDocument();
			}
		);

		it( 'shows Unknown when a failed payout has no failure code or message', async () => {
			await renderPayout( { ...clientDeposit, status: 'failed' } );

			expect(
				screen.getByText( 'Failure reason:' ).parentElement
			).toHaveTextContent( 'Failure reason: Unknown' );
			// Client 11.1.0 deposits/details/index.tsx:228-243: an error notice.
			expect(
				screen
					.getByText( 'Failure reason:' )
					.closest( '.components-notice' )
			).toHaveClass( 'is-error' );
		} );

		it( 'shows the failure reason only for failed payouts', async () => {
			await renderPayout( {
				...clientDeposit,
				failure_code: 'insufficient_funds',
				failure_message: 'Raw Stripe failure message',
			} );

			expect( screen.queryByText( 'Failure reason:' ) ).toBeNull();
		} );
	} );
} );
