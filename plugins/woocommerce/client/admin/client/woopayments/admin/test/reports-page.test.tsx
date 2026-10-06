/**
 * External dependencies
 */
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
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';
import { speak } from '@wordpress/a11y';
import { recordEvent } from '@woocommerce/tracks';
import { downloadCSVFile } from '@woocommerce/csv-export';

/**
 * Internal dependencies
 */
import { WooPaymentsReportsPage } from '../reports/page';
import {
	getWooPaymentsReportsBalanceSummary,
	getWooPaymentsReportsFees,
	getWooPaymentsReportsFeesExportUrl,
	getWooPaymentsReportsFeesSummary,
	requestWooPaymentsReportsFeesExport,
} from '../reports/data';
import { formatAmount as formatMoneyMovementAmount } from '../money-movement/utils';
import {
	chooseFilter,
	getFilterPicker,
	getFilterPickerChoices,
} from './helpers/filter-picker';

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

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/a11y', () => ( {
	speak: jest.fn(),
} ) );

jest.mock( '@wordpress/date', () => ( {
	dateI18n: jest.fn( ( format, date ) => `${ format }|${ date }` ),
} ) );

jest.mock( '@woocommerce/csv-export', () => ( {
	...jest.requireActual( '@woocommerce/csv-export' ),
	downloadCSVFile: jest.fn(),
} ) );

jest.mock( '../reports/data', () => ( {
	getWooPaymentsReportsBalanceSummary: jest.fn(),
	getWooPaymentsReportsFees: jest.fn(),
	getWooPaymentsReportsFeesSummary: jest.fn(),
	requestWooPaymentsReportsFeesExport: jest.fn(),
	getWooPaymentsReportsFeesExportUrl: jest.fn(),
} ) );

const mockDataViews = jest.fn(
	( {
		data = [],
		fields = [],
		header,
		isLoading,
		onChangeView,
		search,
		searchLabel,
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: Array< {
			id: string;
			label: string;
			header?: string;
			filterBy?:
				| false
				| {
						operators: string[];
				  };
			render?: ( props: {
				item: Record< string, unknown >;
			} ) => ReactNode;
		} >;
		header?: ReactNode;
		isLoading?: boolean;
		onChangeView?: ( view: Record< string, unknown > ) => void;
		search?: boolean;
		searchLabel?: string;
		view?: Record< string, unknown >;
	} ) => {
		const viewFields = Array.isArray( view.fields )
			? ( view.fields as string[] )
			: fields.map( ( field ) => field.id );
		const visibleFields = fields.filter( ( field ) =>
			viewFields.includes( field.id )
		);

		return (
			<div data-testid="reports-dataviews" aria-busy={ isLoading }>
				{ header }
				{ fields.some(
					( field ) => field.id === 'date' && field.filterBy
				) &&
					onChangeView && (
						<button
							type="button"
							onClick={ () =>
								onChangeView( {
									...view,
									filters: [
										{
											field: 'date',
											operator: 'between',
											value: [
												'2026-04-01',
												'2026-04-30',
											],
										},
									],
								} )
							}
						>
							Apply April date filter
						</button>
					) }
				{ search && onChangeView && (
					<button
						type="button"
						onClick={ () =>
							onChangeView( {
								...view,
								page: 3,
								perPage: 50,
								search: 'txn_456',
								filters: [
									{
										field: 'date',
										operator: 'between',
										value: [ '2026-04-01', '2026-04-30' ],
									},
									{
										field: 'type',
										operator: 'is',
										value: 'charge',
									},
								],
							} )
						}
					>
						Apply detailed fees view
					</button>
				) }
				{ search && searchLabel && (
					<input
						type="search"
						aria-label={ searchLabel }
						value={ String( view.search || '' ) }
						onChange={ ( event ) =>
							onChangeView?.( {
								...view,
								search: event.currentTarget.value,
							} )
						}
					/>
				) }
				{ visibleFields.map( ( field ) => (
					<div key={ field.id }>{ field.header || field.label }</div>
				) ) }
				{ data.map( ( item, index ) => (
					<div
						key={ String(
							item.id || item.transaction_id || index
						) }
					>
						{ visibleFields.map( ( field ) => (
							<div key={ field.id }>
								{ field.render
									? field.render( { item } )
									: String( item[ field.id ] || '' ) }
							</div>
						) ) }
					</div>
				) ) }
			</div>
		);
	}
);

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( props: Record< string, unknown > ) =>
		mockDataViews( props as Parameters< typeof mockDataViews >[ 0 ] ),
} ) );

const mockGetBalanceSummary =
	getWooPaymentsReportsBalanceSummary as jest.MockedFunction<
		typeof getWooPaymentsReportsBalanceSummary
	>;
const mockGetFees = getWooPaymentsReportsFees as jest.MockedFunction<
	typeof getWooPaymentsReportsFees
>;
const mockGetFeesSummary =
	getWooPaymentsReportsFeesSummary as jest.MockedFunction<
		typeof getWooPaymentsReportsFeesSummary
	>;
const mockRequestFeesExport =
	requestWooPaymentsReportsFeesExport as jest.MockedFunction<
		typeof requestWooPaymentsReportsFeesExport
	>;
const mockGetFeesExportUrl =
	getWooPaymentsReportsFeesExportUrl as jest.MockedFunction<
		typeof getWooPaymentsReportsFeesExportUrl
	>;
const mockDownloadCSVFile = jest.mocked( downloadCSVFile );

const LocationProbe = () => {
	const location = useLocation();
	return (
		<div data-testid="location">
			{ location.pathname }
			{ location.search }
		</div>
	);
};

const BackButton = () => {
	const navigate = useNavigate();
	return (
		<button type="button" onClick={ () => navigate( -1 ) }>
			Browser back
		</button>
	);
};

const renderReportsPage = ( initialEntries = [ '/woopayments/reports' ] ) =>
	render(
		<MemoryRouter initialEntries={ initialEntries }>
			<WooPaymentsReportsPage
				now={ new Date( '2026-06-19T12:00:00Z' ) }
			/>
			<LocationProbe />
			<BackButton />
		</MemoryRouter>
	);

const balanceSummary = {
	currency: 'usd',
	period: {
		start: '2026-06-01T00:00:00Z',
		end: '2026-06-19T23:59:59Z',
	},
	starting_balance: {
		amount: 1000,
	},
	total_charges_captured: {
		amount: 162672,
		count: 8,
	},
	fees: {
		amount: -6064,
	},
	charge_fees: {
		amount: -5958,
	},
	payout_fees: {
		amount: -100,
	},
	reader_fees: {
		amount: -150,
	},
	dispute_fees: {
		amount: -1500,
	},
	fee_refunds: {
		amount: 1644,
	},
	refunds: {
		amount: -21500,
		count: 3,
	},
	refund_failure: {
		amount: -2000,
		count: 1,
	},
	disputes: {
		amount: -4000,
		count: 1,
	},
	financing_payout: {
		amount: 5000,
		count: 1,
	},
	financing_paydown: {
		amount: -500,
		count: 1,
	},
	network_costs: {
		amount: -250,
		count: 1,
	},
	other_adjustments: {
		amount: 750,
		count: 1,
	},
	net_balance_change_in_the_period: {
		amount: 132008,
	},
	payouts: {
		amount: 1102608,
		count: 2,
	},
	ending_balance: {
		amount: 877,
	},
};

const feeRow = {
	transaction_id: 'txn_123',
	date: '2026-06-18 10:11:12',
	payment_method: {
		type: 'card',
	},
	type: 'charge',
	transaction_currency: 'usd',
	amount: 2500,
	deposit_currency: 'usd',
	fees: -120,
	order_id: 99,
	deposit_date: '2026-06-19',
	deposit_id: 'po_123',
};

const waitForNextTick = () =>
	new Promise< void >( ( resolve ) => {
		window.setTimeout( resolve, 0 );
	} );

describe( 'WooPaymentsReportsPage', () => {
	// Client 11.1.0 `formatDateValue()` takes the merchant's day; pin the browser at +03:00 so the UTC boundaries are fixed.
	let timezoneSpy: jest.SpyInstance;
	beforeEach( () => {
		timezoneSpy = jest
			.spyOn( Date.prototype, 'getTimezoneOffset' )
			.mockReturnValue( -180 );
	} );
	afterEach( () => {
		timezoneSpy.mockRestore();
	} );

	let printSpy: jest.SpyInstance;

	beforeEach( () => {
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
			dateFormat: 'F j, Y',
			locale: {
				userLocale: 'en_US',
			},
			admin: {
				woopaymentsSettings: {
					balanceReportIdentity: {
						businessName: 'Native Merchant LLC',
						accountId: 'acct_native_123',
					},
				},
			},
		};
		(
			window as typeof window & {
				wcpaySettings?: Record< string, unknown >;
			}
		 ).wcpaySettings = {
			accountDefaultCurrency: 'USD',
			currentUserEmail: 'merchant@example.com',
			dateFormat: 'F j, Y',
			timeFormat: 'g:i a',
		};
		printSpy = jest.spyOn( window, 'print' ).mockImplementation();
		mockDataViews.mockClear();
		jest.mocked( recordEvent ).mockClear();
		jest.mocked( speak ).mockClear();
		mockGetBalanceSummary.mockReset();
		mockGetFees.mockReset();
		mockGetFeesSummary.mockReset();
		mockRequestFeesExport.mockReset();
		mockGetFeesExportUrl.mockReset();
		mockDownloadCSVFile.mockReset();
		mockGetBalanceSummary.mockImplementation( async () => {
			await waitForNextTick();
			return balanceSummary;
		} );
		mockGetFees.mockImplementation( async () => {
			await waitForNextTick();
			return [ feeRow ];
		} );
		mockGetFeesSummary.mockImplementation( async () => {
			await waitForNextTick();
			return {
				count: 1,
				sources: [ 'card' ],
				types: [ 'charge' ],
			};
		} );
		mockRequestFeesExport.mockResolvedValue( {
			export_id: 'export_123',
		} );
		mockGetFeesExportUrl.mockResolvedValue( {
			status: 'success',
			download_url: 'https://example.com/fees.csv',
		} );
	} );

	afterEach( () => {
		printSpy.mockRestore();
	} );

	it( 'renders the Reports shell, Balance tab, DataViews, and page view tracking', async () => {
		renderReportsPage();

		expect(
			screen.getByRole( 'heading', {
				name: 'Reports',
				hidden: true,
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'View your reconciliation reports.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'Balance' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByRole( 'tab', { name: 'Fees' } ) ).toHaveAttribute(
			'aria-selected',
			'false'
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading balance report…'
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Balance summary' } )
		).toBeInTheDocument();
		const dataViews = screen.getByTestId( 'reports-dataviews' );
		expect( dataViews ).toBeInTheDocument();
		for ( const label of [
			'Starting balance',
			'Total charges captured',
			'Fees',
			'Charge fees',
			'Payout fees',
			'Reader costs',
			'Dispute fees',
			'Fee refunds',
			'Refunds',
			'Refund failures',
			'Disputes',
			'Financing payout',
			'Financing paydown',
			'Network costs',
			'Other adjustments',
			'Net balance change in the period',
			'Payouts',
			'Ending balance',
		] ) {
			expect(
				within( dataViews ).getByText( label )
			).toBeInTheDocument();
		}
		expect(
			within( dataViews ).queryByText( 'Charges' )
		).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Date range' ) ).toBeInTheDocument();
		expect( mockGetBalanceSummary ).toHaveBeenCalledWith( {
			date_start: '2026-05-01T00:00:00.000Z',
			date_end: '2026-05-31T23:59:59.999Z',
			currency: 'USD',
		} );
		expect(
			screen.getByRole( 'button', { name: 'Print' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Export' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'status', { name: 'Balance export status' } )
		).toBeEmptyDOMElement();
		expect( recordEvent ).toHaveBeenCalledWith( 'page_view', {
			path: 'payments_reports',
			tab: 'balance',
		} );
		expect( speak ).toHaveBeenCalledWith(
			'18 balance report rows loaded.',
			'polite'
		);
	} );

	it( 'defaults the Balance DataViews Date filter to Between', async () => {
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		expect(
			mockDataViews.mock.calls
				.at( -1 )?.[ 0 ]
				.fields?.find( ( field ) => field.id === 'date' )?.filterBy
		).toEqual( {
			operators: [ 'between', 'before', 'after', 'on' ],
		} );
	} );

	it( 'formats Balance amounts with the standard admin helper and prints the same visible table', async () => {
		mockGetBalanceSummary.mockResolvedValueOnce( {
			...balanceSummary,
			currency: 'cad',
		} );

		renderReportsPage();

		const dataViews = await screen.findByTestId( 'reports-dataviews' );
		expect(
			within( dataViews ).getByText(
				`+${ formatMoneyMovementAmount( 162672, 'cad' ) }`
			)
		).toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Print' } )
		);

		expect( printSpy ).toHaveBeenCalledTimes( 1 );
	} );

	// Client 11.1.0 reports/balance/format.ts:106-114 and __tests__/format.test.ts:37-85: inflows carry an
	// explicit `+`, outflows keep their `-`.
	it( 'signs Balance inflows with a leading plus and keeps outflows negative', async () => {
		renderReportsPage();

		const dataViews = await screen.findByTestId( 'reports-dataviews' );
		expect(
			within( dataViews ).getByText( '+$1,626.72' )
		).toBeInTheDocument();
		expect(
			within( dataViews ).getByText( '+$10.00' )
		).toBeInTheDocument();
		expect(
			within( dataViews ).getByText( '-$60.64' )
		).toBeInTheDocument();
		expect(
			within( dataViews ).queryByText( '$1,626.72' )
		).not.toBeInTheDocument();
	} );

	it( 'downloads the loaded Balance summary with business and account identity CSV columns', async () => {
		mockGetBalanceSummary.mockResolvedValueOnce( {
			...balanceSummary,
			starting_balance: {
				amount: 0,
			},
		} );
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Export' } )
		);

		const csv = mockDownloadCSVFile.mock.calls[ 0 ][ 1 ];

		expect( mockDownloadCSVFile ).toHaveBeenCalledWith(
			'balance-report-2026-06-01-to-2026-06-19.csv',
			expect.any( String )
		);
		expect( csv ).toContain(
			'"Native Merchant LLC",acct_native_123,starting_balance,"Starting balance",0,,usd,2026-06-01,2026-06-19'
		);
		expect( csv ).toContain(
			'total_charges_captured,"Total charges captured",1626.72,8,usd'
		);
		expect( csv ).toContain( 'fees,Fees,-60.64,,usd' );
		expect( csv ).toContain( 'reader_fees,"Reader costs",-1.5,,usd' );
		expect( csv ).toContain( 'payouts,Payouts,-11026.08,2,usd' );
		expect( csv ).not.toContain( '$' );
		expect( csv ).not.toContain( ' USD' );
	} );

	it( 'keeps mixed-case zero-decimal Balance CSV amounts in provider units', async () => {
		mockGetBalanceSummary.mockResolvedValueOnce( {
			...balanceSummary,
			currency: 'jPy',
		} );
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Export' } )
		);

		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 1 ] ).toContain(
			'total_charges_captured,"Total charges captured",162672,8,jpy'
		);
		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 1 ] ).toContain(
			'payouts,Payouts,-1102608,2,jpy'
		);
	} );

	it( 'converts mixed-case UGX Balance CSV amounts from provider minor units', async () => {
		mockGetBalanceSummary.mockResolvedValueOnce( {
			...balanceSummary,
			currency: 'uGx',
			total_charges_captured: {
				amount: 12345,
				count: 8,
			},
		} );
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Export' } )
		);

		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 1 ] ).toContain(
			'total_charges_captured,"Total charges captured",123.45,8,ugx'
		);
		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 1 ] ).toContain(
			'payouts,Payouts,-11026.08,2,ugx'
		);
	} );

	it( 'projects already-negative Payouts CSV amounts as negative', async () => {
		mockGetBalanceSummary.mockResolvedValueOnce( {
			...balanceSummary,
			payouts: {
				amount: -1102608,
				count: 2,
			},
		} );
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Export' } )
		);

		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 1 ] ).toContain(
			'payouts,Payouts,-11026.08,2,usd'
		);
	} );

	it( 'keeps zero Payouts CSV amounts numeric and unsigned', async () => {
		mockGetBalanceSummary.mockResolvedValueOnce( {
			...balanceSummary,
			payouts: {
				amount: 0,
				count: 2,
			},
		} );
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Export' } )
		);

		expect( mockDownloadCSVFile.mock.calls[ 0 ][ 1 ] ).toContain(
			'payouts,Payouts,0,2,usd'
		);
	} );

	it( 'keeps the reference Balance anchor rows visible when activity rows are zero', async () => {
		mockGetBalanceSummary.mockImplementationOnce( async () => {
			await waitForNextTick();
			return {
				currency: 'usd',
				starting_balance: {
					amount: 0,
				},
				total_charges_captured: {
					amount: 0,
					count: 0,
				},
				fees: {
					amount: 0,
				},
				net_balance_change_in_the_period: {
					amount: 0,
				},
				payouts: {
					amount: 0,
					count: 0,
				},
				ending_balance: {
					amount: 0,
				},
			};
		} );

		renderReportsPage();

		expect(
			await screen.findByRole( 'heading', {
				name: 'No balance activity',
			} )
		).toBeInTheDocument();
		const dataViews = screen.getByTestId( 'reports-dataviews' );

		for ( const label of [
			'Starting balance',
			'Total charges captured',
			'Fees',
			'Net balance change in the period',
			'Payouts',
			'Ending balance',
		] ) {
			expect(
				within( dataViews ).getByText( label )
			).toBeInTheDocument();
		}

		for ( const label of [
			'Reader costs',
			'Charge fees',
			'Payout fees',
			'Refund failures',
			'Network costs',
		] ) {
			expect(
				within( dataViews ).queryByText( label )
			).not.toBeInTheDocument();
		}
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'lets merchants change the Balance period through the Date range selector', async () => {
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click( getFilterPicker( 'Date range' ) );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Year to date' } )
		);

		expect( getFilterPicker( 'Date range' ) ).toHaveFocus();

		await waitFor( () =>
			expect( mockGetBalanceSummary ).toHaveBeenLastCalledWith( {
				date_start: '2026-01-01T00:00:00.000Z',
				date_end: '2026-06-18T23:59:59.999Z',
				currency: 'USD',
			} )
		);
		expect( screen.getByTestId( 'location' ) ).toHaveTextContent(
			'date_between%5B%5D=2026-01-01'
		);
	} );

	it( 'keeps the Fees rows of the current query when an earlier request answers last', async () => {
		let resolveEarlier: ( rows: ( typeof feeRow )[] ) => void = () => {};
		renderReportsPage( [ '/woopayments/reports?report_tab=fees' ] );
		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply detailed fees view' } )
		);

		// The Previous month request hangs; the Previous year request answers first.
		mockGetFees
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveEarlier = resolve;
					} )
			)
			.mockImplementation( async () => [
				{ ...feeRow, transaction_id: 'txn_new' },
			] );
		chooseFilter( 'Date range', 'Previous month' );
		chooseFilter( 'Date range', 'Previous year' );
		await screen.findByRole( 'link', { name: 'txn_new' } );

		await act( async () => {
			resolveEarlier( [ { ...feeRow, transaction_id: 'txn_old' } ] );
			await waitForNextTick();
		} );

		expect(
			screen.getByRole( 'link', { name: 'txn_new' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'txn_old' } )
		).not.toBeInTheDocument();
	} );

	it( 'keeps the Fees rows of the current query when an earlier request fails last', async () => {
		let rejectEarlier: ( error: Error ) => void = () => {};
		renderReportsPage( [ '/woopayments/reports?report_tab=fees' ] );
		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply detailed fees view' } )
		);

		mockGetFees
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve, reject ) => {
						rejectEarlier = reject;
					} )
			)
			.mockImplementation( async () => [
				{ ...feeRow, transaction_id: 'txn_new' },
			] );
		chooseFilter( 'Date range', 'Previous month' );
		chooseFilter( 'Date range', 'Previous year' );
		await screen.findByRole( 'link', { name: 'txn_new' } );

		await act( async () => {
			rejectEarlier( new Error( 'Earlier request failed.' ) );
			await waitForNextTick();
		} );

		expect(
			screen.getByRole( 'link', { name: 'txn_new' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Fees report could not be loaded.' )
		).not.toBeInTheDocument();
	} );

	it( 'keeps the Balance totals of the current query when an earlier request answers last', async () => {
		let resolveEarlier: (
			summary: typeof balanceSummary
		) => void = () => {};
		renderReportsPage();
		await waitFor( () =>
			expect( mockGetBalanceSummary ).toHaveBeenCalledTimes( 1 )
		);
		await screen.findByLabelText( 'Date range', { selector: 'button' } );

		mockGetBalanceSummary
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveEarlier = resolve;
					} )
			)
			.mockImplementation( async () => ( {
				...balanceSummary,
				ending_balance: { amount: 222222 },
			} ) );
		chooseFilter( 'Date range', 'Previous year' );
		chooseFilter( 'Date range', 'Previous month' );
		await screen.findByText( /2,222\.22/ );

		await act( async () => {
			resolveEarlier( {
				...balanceSummary,
				ending_balance: { amount: 111111 },
			} );
			await waitForNextTick();
		} );

		expect( screen.getByText( /2,222\.22/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /1,111\.11/ ) ).not.toBeInTheDocument();
	} );

	it( 'applies previous-period presets to Balance and Fees using the stable report clock', async () => {
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		expect( getFilterPickerChoices( 'Date range' ) ).toEqual( [
			'Previous month',
			'Previous year',
			'Month to date',
			'Year to date',
			'Custom',
		] );

		chooseFilter( 'Date range', 'Previous month' );

		await waitFor( () =>
			expect( mockGetBalanceSummary ).toHaveBeenLastCalledWith( {
				date_start: '2026-05-01T00:00:00.000Z',
				date_end: '2026-05-31T23:59:59.999Z',
				currency: 'USD',
			} )
		);

		chooseFilter( 'Date range', 'Previous year' );

		await waitFor( () =>
			expect( mockGetBalanceSummary ).toHaveBeenLastCalledWith( {
				date_start: '2025-01-01T00:00:00.000Z',
				date_end: '2025-12-31T23:59:59.999Z',
				currency: 'USD',
			} )
		);

		await userEvent.click( screen.getByRole( 'tab', { name: 'Fees' } ) );
		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply detailed fees view' } )
		);
		expect( getFilterPickerChoices( 'Date range' ) ).toEqual( [
			'Previous month',
			'Previous year',
			'Month to date',
			'Year to date',
			'Custom',
		] );

		chooseFilter( 'Date range', 'Previous month' );

		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					page: 1,
					per_page: 50,
					sort: 'date',
					direction: 'desc',
					search: [ 'txn_456' ],
					type: [ 'charge' ],
					date_between: [
						'2026-04-30 21:00:00',
						'2026-05-31 20:59:59',
					],
				} )
			)
		);

		chooseFilter( 'Date range', 'Previous year' );

		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					date_between: [
						'2024-12-31 21:00:00',
						'2025-12-31 20:59:59',
					],
				} )
			)
		);
	} );

	it( 'lets merchants change the Balance period through the DataViews date filter', async () => {
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Apply April date filter',
			} )
		);

		await waitFor( () =>
			expect( mockGetBalanceSummary ).toHaveBeenLastCalledWith( {
				date_start: '2026-04-01T00:00:00.000Z',
				date_end: '2026-04-30T23:59:59.999Z',
				currency: 'USD',
			} )
		);
		expect( screen.getByTestId( 'location' ) ).toHaveTextContent(
			'date_between%5B%5D=2026-04-01'
		);
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_reports_balance_date_filter_change',
			{
				preset: 'custom',
				range_days: 30,
				is_initial_apply: false,
			}
		);
	} );

	it( 'syncs the active tab to the URL and tracks tab changes', async () => {
		renderReportsPage();

		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		await userEvent.click( screen.getByRole( 'tab', { name: 'Fees' } ) );

		expect( screen.getByTestId( 'location' ) ).toHaveTextContent(
			'/woopayments/reports?report_tab=fees'
		);
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_reports_tab_change',
			{
				from_tab: 'balance',
				to_tab: 'fees',
			}
		);
		expect(
			await screen.findByRole( 'searchbox', { name: 'Search fees' } )
		).toBeInTheDocument();
		expect( mockGetFees ).toHaveBeenCalledWith(
			expect.objectContaining( {
				page: 1,
				per_page: 25,
				sort: 'date',
				direction: 'desc',
				user_timezone: expect.stringMatching( /^[+-]\d{2}:\d{2}$/ ),
			} )
		);
	} );

	it( 'supports keyboard navigation between Reports tabs', async () => {
		renderReportsPage();

		const balanceTab = screen.getByRole( 'tab', { name: 'Balance' } );
		await screen.findByRole( 'heading', { name: 'Balance summary' } );
		balanceTab.focus();
		fireEvent.keyDown( balanceTab, { key: 'ArrowRight' } );

		expect( screen.getByTestId( 'location' ) ).toHaveTextContent(
			'/woopayments/reports?report_tab=fees'
		);
		expect( screen.getByRole( 'tab', { name: 'Fees' } ) ).toHaveFocus();
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_reports_tab_change',
			{
				from_tab: 'balance',
				to_tab: 'fees',
			}
		);
	} );

	it( 'renders Balance error and empty states with accessible reload controls', async () => {
		mockGetBalanceSummary.mockImplementationOnce( async () => {
			await waitForNextTick();
			throw new Error( 'Balance failed' );
		} );
		renderReportsPage();

		expect(
			await screen.findByRole( 'heading', {
				name: 'Balance report unavailable',
			} )
		).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			"We couldn't load your balance report."
		);
		expect(
			screen.getByRole( 'button', { name: 'Reload report' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Apply April date filter' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Date range' ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith(
			'Balance report could not be loaded.',
			'assertive'
		);

		mockGetBalanceSummary.mockImplementationOnce( async () => {
			await waitForNextTick();
			return {
				currency: 'usd',
				starting_balance: {
					amount: 0,
				},
				ending_balance: {
					amount: 0,
				},
			};
		} );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Reload report' } )
		);

		expect(
			await screen.findByRole( 'heading', {
				name: 'No balance activity',
			} )
		).toBeInTheDocument();
		expect(
			screen
				.getByRole( 'heading', { name: 'No balance activity' } )
				.closest( '[role="status"]' )
		).toHaveTextContent(
			'No balance activity was found for the selected period. Summary rows are shown with zero amounts.'
		);
		expect(
			screen.getByRole( 'button', { name: 'Apply April date filter' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Date range' ) ).toBeInTheDocument();
	} );

	it( 'renders Fees states, search, DataViews fields, and export flow', async () => {
		const clickSpy = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation();

		renderReportsPage( [ '/woopayments/reports?tab=fees' ] );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading fees report…'
		);
		expect(
			await screen.findByRole( 'searchbox', { name: 'Search fees' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Date & time' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Method' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Gross amount' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Fees total' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'F j, Y / g:i a|2026-06-18T10:11:12Z' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'txn_123' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=txn_123&transaction_type=charge'
		);
		expect( screen.getByRole( 'link', { name: '99' } ) ).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=99'
		);
		expect( screen.getByText( '$25.00' ) ).toBeInTheDocument();
		expect( screen.getByText( '-$1.20' ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( '1 fees loaded.', 'polite' );

		fireEvent.change(
			screen.getByRole( 'searchbox', { name: 'Search fees' } ),
			{
				target: {
					value: 'txn_456',
				},
			}
		);

		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					search: [ 'txn_456' ],
					user_timezone: expect.stringMatching( /^[+-]\d{2}:\d{2}$/ ),
				} )
			)
		);
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_reports_fees_search',
			{
				search_length: 7,
			}
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Export' } )
		);

		expect( mockRequestFeesExport ).toHaveBeenCalledWith(
			expect.objectContaining( {
				search: [ 'txn_456' ],
				user_timezone: expect.stringMatching( /^[+-]\d{2}:\d{2}$/ ),
			} )
		);
		// Client 11.1.0 `hooks/use-report-export.ts:91-95`: the first check runs a second after the request.
		await waitFor( () => expect( clickSpy ).toHaveBeenCalledTimes( 1 ), {
			timeout: 2000,
		} );
		expect( mockGetFeesExportUrl ).toHaveBeenCalledWith( 'export_123' );
		expect( recordEvent ).toHaveBeenCalledWith( 'wcpay_csv_export_click', {
			row_type: 'fees',
			source: 'payments_reports',
			exported_row_count: 1,
		} );
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_reports_fees_export_success',
			{ exported_row_count: 1 }
		);
		clickSpy.mockRestore();
	} );

	it( 'exposes the Fees date filter through DataViews', async () => {
		renderReportsPage( [ '/woopayments/reports?tab=fees' ] );

		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Apply April date filter',
			} )
		);

		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					date_between: [
						'2026-03-31 21:00:00',
						'2026-04-30 20:59:59',
					],
					user_timezone: expect.stringMatching( /^[+-]\d{2}:\d{2}$/ ),
				} )
			)
		);
		expect( mockGetFeesSummary ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				date_between: [ '2026-03-31 21:00:00', '2026-04-30 20:59:59' ],
				user_timezone: expect.stringMatching( /^[+-]\d{2}:\d{2}$/ ),
			} )
		);
	} );

	it( "loads the Fees sort, paging and filters from the client's URL params", async () => {
		// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:167-195`.
		renderReportsPage( [
			'/woopayments/reports?report_tab=fees&orderby=amount&order=asc&paged=2&per_page=50&search%5B%5D=txn_9&date_after=2026-04-01&payment_method_type=card&type=charge',
		] );

		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenCalledWith(
				expect.objectContaining( {
					page: 2,
					per_page: 50,
					sort: 'amount',
					direction: 'asc',
					search: [ 'txn_9' ],
					date_after: '2026-03-31 21:00:00',
					payment_method_type: 'card',
					type: [ 'charge' ],
				} )
			)
		);
		expect( mockGetFees ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'writes Fees view changes to the URL with the client param names', async () => {
		renderReportsPage( [ '/woopayments/reports?report_tab=fees' ] );

		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply detailed fees view' } )
		);

		// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:122-129`.
		expect( screen.getByTestId( 'location' ) ).toHaveTextContent(
			'/woopayments/reports?report_tab=fees&orderby=date&order=desc&paged=3&per_page=50&search%5B%5D=txn_456&date_between%5B%5D=2026-04-01&date_between%5B%5D=2026-04-30&type=charge'
		);
	} );

	it( 'writes a Fees search to the URL only after typing pauses', async () => {
		renderReportsPage( [ '/woopayments/reports?report_tab=fees' ] );

		fireEvent.change(
			await screen.findByRole( 'searchbox', { name: 'Search fees' } ),
			{ target: { value: 'txn_456' } }
		);

		// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:26,226-230`: the search write waits 500ms.
		expect( screen.getByTestId( 'location' ) ).not.toHaveTextContent(
			'txn_456'
		);
		await waitFor(
			() =>
				expect( screen.getByTestId( 'location' ) ).toHaveTextContent(
					'search%5B%5D=txn_456'
				),
			{ timeout: 2000 }
		);
	} );

	it( 'restores the previous Fees view on browser back', async () => {
		renderReportsPage( [
			'/woopayments/reports?report_tab=fees&orderby=amount&order=asc',
		] );

		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Apply detailed fees view' } )
		);
		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenLastCalledWith(
				expect.objectContaining( { page: 3, search: [ 'txn_456' ] } )
			)
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Browser back' } )
		);

		// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:160-166,193-195`: popstate re-derives the view from the URL.
		await waitFor( () =>
			expect( mockGetFees ).toHaveBeenLastCalledWith(
				expect.not.objectContaining( { search: expect.anything() } )
			)
		);
		expect( mockGetFees ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				page: 1,
				sort: 'amount',
				direction: 'asc',
			} )
		);
	} );

	it( 'uses the Fees default column order', async () => {
		renderReportsPage( [ '/woopayments/reports?tab=fees' ] );

		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		expect( mockDataViews.mock.calls.at( -1 )?.[ 0 ].view?.fields ).toEqual(
			[
				'date',
				'payment_method',
				'type',
				'order_id',
				'transaction_id',
				'transaction_currency',
				'amount',
				'fees',
			]
		);
	} );

	it( 'defaults the Fees DataViews Date filter to Between', async () => {
		renderReportsPage( [ '/woopayments/reports?tab=fees' ] );

		await screen.findByRole( 'searchbox', { name: 'Search fees' } );
		expect(
			mockDataViews.mock.calls
				.at( -1 )?.[ 0 ]
				.fields?.find( ( field ) => field.id === 'date' )?.filterBy
		).toEqual( {
			operators: [ 'between', 'on', 'before', 'after' ],
		} );
	} );

	it( 'renders Fees error and empty states', async () => {
		mockGetFees.mockImplementationOnce( async () => {
			await waitForNextTick();
			throw new Error( 'Fees failed' );
		} );
		renderReportsPage( [ '/woopayments/reports?tab=fees' ] );

		expect(
			await screen.findByRole( 'heading', {
				name: 'Fees report unavailable',
			} )
		).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			"We couldn't load your fees data."
		);
		expect( speak ).toHaveBeenCalledWith(
			'Fees report could not be loaded.',
			'assertive'
		);

		mockGetFees.mockImplementationOnce( async () => {
			await waitForNextTick();
			return [];
		} );
		mockGetFeesSummary.mockImplementationOnce( async () => {
			await waitForNextTick();
			return {
				count: 0,
				sources: [],
				types: [],
			};
		} );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Reload report' } )
		);

		expect(
			await screen.findByRole( 'heading', { name: 'No fees yet' } )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Fees will appear here once you start receiving payments.'
		);
	} );
} );
