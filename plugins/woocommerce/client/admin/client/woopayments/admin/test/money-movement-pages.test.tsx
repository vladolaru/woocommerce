/**
 * External dependencies
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsDisputesPage } from '../money-movement/disputes-page';
import { WooPaymentsPaymentSummarySection } from '../money-movement/transaction-detail-sections';
import { WooPaymentsTransactionDetailsPage } from '../money-movement/transaction-details-page';
import { WooPaymentsTransactionsPage } from '../money-movement/transactions-page';
import {
	getWooPaymentsDisputes,
	getWooPaymentsDisputesSummary,
	getWooPaymentsCharge,
	getWooPaymentsPaymentIntent,
	getWooPaymentsReaderChargeSummary,
	getWooPaymentsTransaction,
	getWooPaymentsTimeline,
	getWooPaymentsTransactions,
	getWooPaymentsTransactionsSummary,
	getWooPaymentsTransactionsExportUrl,
	getWooPaymentsAuthorizations,
	getWooPaymentsAuthorization,
	getWooPaymentsAuthorizationsSummary,
	getWooPaymentsDisputesExportUrl,
	closeWooPaymentsDispute,
	captureWooPaymentsAuthorization,
	cancelWooPaymentsAuthorization,
	requestWooPaymentsDisputesExport,
	requestWooPaymentsTransactionsExport,
	refundWooPaymentsCharge,
} from '../money-movement/data';
import { getWooPaymentsAccountSettings } from '../../settings/api';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockHistoryPush = jest.fn();
let mockHistoryNavigate: ( ( to: string ) => void ) | null = null;

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => ( {
		push: mockHistoryPush,
	} ),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: jest.fn( ( storeName ) => {
			if ( storeName === 'core/notices' ) {
				return {
					createSuccessNotice: mockCreateSuccessNotice,
					createErrorNotice: mockCreateErrorNotice,
				};
			}

			return actual.dispatch( storeName );
		} ),
	};
} );

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
		header,
		onChangeView,
		paginationInfo,
		search = true,
		searchLabel,
		view = {},
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: Array< {
			id: string;
			label?: ReactNode;
			header?: ReactNode;
			type?: string;
			filterBy?: false | { operators: string[] };
			elements?: Array< { value: string; label: ReactNode } >;
			render?: ( props: {
				item: Record< string, unknown >;
			} ) => ReactNode;
		} >;
		header?: ReactNode;
		onChangeView?: ( view: Record< string, unknown > ) => void;
		paginationInfo?: { totalItems: number; totalPages: number };
		search?: boolean;
		searchLabel?: string;
		view?: {
			search?: string;
			fields?: string[];
			filters?: Array< {
				field: string;
				operator: string;
				value?: unknown;
			} >;
			[ key: string ]: unknown;
		};
	} ) => {
		const visibleFields = fields.filter(
			( field ) => ! view.fields || view.fields.includes( field.id )
		);
		const discoverableFilterFields = fields
			.filter(
				( field ) =>
					field.filterBy !== false &&
					( !! field.filterBy ||
						field.type === 'date' ||
						field.type === 'integer' )
			)
			.map( ( field ) => field.id );

		return (
			<div
				data-testid="money-movement-dataviews"
				data-total-items={ paginationInfo?.totalItems }
				data-total-pages={ paginationInfo?.totalPages }
				data-visible-fields={ view.fields?.join( ',' ) }
				data-view-filters={ JSON.stringify( view.filters || [] ) }
				data-discoverable-filter-fields={ discoverableFilterFields.join(
					','
				) }
			>
				{ search && searchLabel && (
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
							search: 'Order #1520',
						} )
					}
				>
					Mock change transaction page and search
				</button>
				<button
					type="button"
					onClick={ () =>
						onChangeView?.( {
							...view,
							fields: [ 'type', 'amount' ],
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
				<button
					type="button"
					onClick={ () =>
						onChangeView?.( {
							...view,
							filters: [
								...( view.filters || [] ),
								{
									field: 'type',
									operator: 'is',
								},
							],
						} )
					}
				>
					Mock add Type filter
				</button>
				<button
					type="button"
					onClick={ () =>
						onChangeView?.( {
							...view,
							filters: ( view.filters || [] ).map( ( filter ) =>
								filter.field === 'type'
									? { ...filter, value: 'refund' }
									: filter
							),
						} )
					}
				>
					Mock choose Refund
				</button>
				<button
					type="button"
					onClick={ () =>
						onChangeView?.( {
							...view,
							filters: ( view.filters || [] ).map( ( filter ) =>
								filter.field === 'date'
									? {
											...filter,
											operator: 'between',
											value: undefined,
									  }
									: filter
							),
						} )
					}
				>
					Mock change Date to between
				</button>
				<button
					type="button"
					onClick={ () =>
						onChangeView?.( {
							...view,
							filters: ( view.filters || [] ).map( ( filter ) =>
								filter.field === 'date'
									? {
											...filter,
											value: [
												'2026-07-13',
												'2026-07-20',
											],
									  }
									: filter
							),
						} )
					}
				>
					Mock complete Date range
				</button>
				<button
					type="button"
					onClick={ () =>
						onChangeView?.( {
							...view,
							filters: [],
						} )
					}
				>
					Mock clear DataViews filters
				</button>
				{ header }
				<div role="row">
					{ visibleFields.map( ( field ) => (
						<div
							key={ field.id }
							role="columnheader"
							data-field-type={ field.type }
							data-filter-disabled={ field.filterBy === false }
							data-filter-operators={
								field.filterBy
									? field.filterBy.operators.join( ',' )
									: undefined
							}
							data-filter-elements={ field.elements
								?.map(
									( element ) =>
										`${ element.value }:${ String(
											element.label
										) }`
								)
								.join( '|' ) }
						>
							{ field.header || field.label }
						</div>
					) ) }
				</div>
				{ data.map( ( item ) => (
					<div
						role="row"
						key={ String(
							item.id ||
								item.transaction_id ||
								item.payment_intent_id ||
								item.order_id
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
	},
} ) );

jest.mock(
	'../money-movement/transaction-search',
	() => ( {
		WooPaymentsTransactionSearch: ( {
			value,
			onChange,
		}: {
			value: string;
			onChange: ( value: string ) => void;
		} ) => (
			<>
				<input
					type="search"
					aria-label="Search transactions"
					value={ value }
					readOnly
				/>
				<button
					type="button"
					onClick={ () => onChange( 'MA05 Searchable' ) }
				>
					Mock apply transaction search
				</button>
				<button type="button" onClick={ () => onChange( '' ) }>
					Mock clear transaction search
				</button>
			</>
		),
	} ),
	{ virtual: true }
);

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => <div>Spotlight promotion</div>,
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsDisputes: jest.fn(),
	getWooPaymentsCharge: jest.fn(),
	getWooPaymentsPaymentIntent: jest.fn(),
	getWooPaymentsReaderChargeSummary: jest.fn(),
	getWooPaymentsTransaction: jest.fn(),
	getWooPaymentsTimeline: jest.fn(),
	getWooPaymentsTransactions: jest.fn(),
	getWooPaymentsTransactionsSummary: jest.fn(),
	getWooPaymentsAuthorizations: jest.fn(),
	getWooPaymentsAuthorization: jest.fn(),
	getWooPaymentsAuthorizationsSummary: jest.fn(),
	captureWooPaymentsAuthorization: jest.fn(),
	cancelWooPaymentsAuthorization: jest.fn(),
	closeWooPaymentsDispute: jest.fn(),
	getWooPaymentsDisputesSummary: jest.fn(),
	requestWooPaymentsTransactionsExport: jest.fn(),
	getWooPaymentsTransactionsExportUrl: jest.fn(),
	requestWooPaymentsDisputesExport: jest.fn(),
	getWooPaymentsDisputesExportUrl: jest.fn(),
	refundWooPaymentsCharge: jest.fn(),
} ) );

jest.mock( '../../settings/api', () => ( {
	getWooPaymentsAccountSettings: jest.fn(),
} ) );

const mockGetTransactions = getWooPaymentsTransactions as jest.MockedFunction<
	typeof getWooPaymentsTransactions
>;
const mockGetDisputes = getWooPaymentsDisputes as jest.MockedFunction<
	typeof getWooPaymentsDisputes
>;
const mockGetCharge = getWooPaymentsCharge as jest.MockedFunction<
	typeof getWooPaymentsCharge
>;
const mockGetPaymentIntent = getWooPaymentsPaymentIntent as jest.MockedFunction<
	typeof getWooPaymentsPaymentIntent
>;
const mockGetReaderChargeSummary =
	getWooPaymentsReaderChargeSummary as jest.MockedFunction<
		typeof getWooPaymentsReaderChargeSummary
	>;
const mockGetTransaction = getWooPaymentsTransaction as jest.MockedFunction<
	typeof getWooPaymentsTransaction
>;
const mockGetTimeline = getWooPaymentsTimeline as jest.MockedFunction<
	typeof getWooPaymentsTimeline
>;
const mockGetTransactionsSummary =
	getWooPaymentsTransactionsSummary as jest.MockedFunction<
		typeof getWooPaymentsTransactionsSummary
	>;
const mockGetAuthorizations =
	getWooPaymentsAuthorizations as jest.MockedFunction<
		typeof getWooPaymentsAuthorizations
	>;
const mockGetAuthorization = getWooPaymentsAuthorization as jest.MockedFunction<
	typeof getWooPaymentsAuthorization
>;
const mockGetAuthorizationsSummary =
	getWooPaymentsAuthorizationsSummary as jest.MockedFunction<
		typeof getWooPaymentsAuthorizationsSummary
	>;
const mockCaptureAuthorization =
	captureWooPaymentsAuthorization as jest.MockedFunction<
		typeof captureWooPaymentsAuthorization
	>;
const mockCancelAuthorization =
	cancelWooPaymentsAuthorization as jest.MockedFunction<
		typeof cancelWooPaymentsAuthorization
	>;
const mockRefundCharge = refundWooPaymentsCharge as jest.MockedFunction<
	typeof refundWooPaymentsCharge
>;
const mockCloseDispute = closeWooPaymentsDispute as jest.MockedFunction<
	typeof closeWooPaymentsDispute
>;
const mockGetDisputesSummary =
	getWooPaymentsDisputesSummary as jest.MockedFunction<
		typeof getWooPaymentsDisputesSummary
	>;
const mockRequestTransactionsExport =
	requestWooPaymentsTransactionsExport as jest.MockedFunction<
		typeof requestWooPaymentsTransactionsExport
	>;
const mockGetTransactionsExportUrl =
	getWooPaymentsTransactionsExportUrl as jest.MockedFunction<
		typeof getWooPaymentsTransactionsExportUrl
	>;
const mockRequestDisputesExport =
	requestWooPaymentsDisputesExport as jest.MockedFunction<
		typeof requestWooPaymentsDisputesExport
	>;
const mockGetDisputesExportUrl =
	getWooPaymentsDisputesExportUrl as jest.MockedFunction<
		typeof getWooPaymentsDisputesExportUrl
	>;
const mockGetAccountSettings =
	getWooPaymentsAccountSettings as jest.MockedFunction<
		typeof getWooPaymentsAccountSettings
	>;

const RouteChangeButton = ( { to }: { to: string } ) => {
	const navigate = useNavigate();

	return (
		<button type="button" onClick={ () => navigate( to ) }>
			Load another transaction
		</button>
	);
};

const MoneyMovementRouterBridge = () => {
	const location = useLocation();
	const navigate = useNavigate();

	mockHistoryNavigate = ( to ) => navigate( to );

	return (
		<>
			<output data-testid="money-movement-route">
				{ `${ location.pathname }${ location.search }` }
			</output>
			<button type="button" onClick={ () => navigate( -1 ) }>
				Mock router back
			</button>
			<button
				type="button"
				onClick={ () =>
					navigate( '/woopayments/transactions?view=uncaptured' )
				}
			>
				Mock open uncaptured transactions
			</button>
		</>
	);
};

const getDetailValue = ( container: HTMLElement, label: string ) => {
	const term = within( container ).getByText( label, {
		selector: 'dt',
	} );
	const row = term.closest( 'div' );

	if ( ! row ) {
		throw new Error( `Unable to find detail row for ${ label }` );
	}

	const value = row.querySelector( 'dd' );

	if ( ! value ) {
		throw new Error( `Unable to find detail value for ${ label }` );
	}

	return value as HTMLElement;
};

describe( 'WooPayments money movement pages', () => {
	let anchorClickSpy: jest.SpyInstance;

	beforeEach( () => {
		anchorClickSpy = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation();
		window.localStorage.clear();
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
			countries: {
				US: 'United States',
			},
		};
		mockGetTransactions.mockReset();
		mockGetDisputes.mockReset();
		mockGetCharge.mockReset();
		mockGetPaymentIntent.mockReset();
		mockGetReaderChargeSummary.mockReset();
		mockGetTransaction.mockReset();
		mockGetTimeline.mockReset();
		mockGetTransactionsSummary.mockReset();
		mockGetAuthorizations.mockReset();
		mockGetAuthorization.mockReset();
		mockGetAuthorizationsSummary.mockReset();
		mockCaptureAuthorization.mockReset();
		mockCancelAuthorization.mockReset();
		mockRefundCharge.mockReset();
		mockCloseDispute.mockReset();
		mockGetDisputesSummary.mockReset();
		mockRequestTransactionsExport.mockReset();
		mockGetTransactionsExportUrl.mockReset();
		mockRequestDisputesExport.mockReset();
		mockGetDisputesExportUrl.mockReset();
		mockGetAccountSettings.mockReset();
		mockGetAccountSettings.mockResolvedValue( {
			account: {
				id: 'acct_live',
				mode: 'live',
				default_currency: 'usd',
				connected: true,
				working: true,
				can_process_payments: true,
				test_mode: false,
				test_drive: false,
				sandbox: false,
				live: true,
			},
			urls: {},
		} );
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
		mockHistoryPush.mockReset();
		mockHistoryPush.mockImplementation( ( to: string ) =>
			mockHistoryNavigate?.( to )
		);
	} );

	it.each( [
		[
			'partially refunded',
			{
				status: 'succeeded',
				amount: 5000,
				amount_refunded: 1000,
				refunded: false,
				currency: 'usd',
			},
			'Partial refund',
		],
		[
			'fully refunded',
			{
				status: 'succeeded',
				amount: 5000,
				amount_refunded: 5000,
				refunded: true,
				currency: 'usd',
			},
			'Refunded',
		],
		[
			'disputed and partially refunded',
			{
				status: 'succeeded',
				amount: 5000,
				amount_refunded: 1000,
				refunded: false,
				currency: 'usd',
				dispute: {
					status: 'needs_response',
				},
			},
			'Disputed: Response needed',
		],
	] )(
		'derives the %s payment summary status with oracle precedence',
		( _state, transaction, expectedStatus ) => {
			render(
				<WooPaymentsPaymentSummarySection transaction={ transaction } />
			);

			const summary = screen
				.getByRole( 'heading', { name: 'Summary' } )
				.closest( 'section' ) as HTMLElement;
			expect(
				within( summary ).getByText( expectedStatus )
			).toBeInTheDocument();
			expect(
				within( summary ).queryByText( 'Succeeded' )
			).not.toBeInTheDocument();
		}
	);

	it( 'keeps readable text for unsupported historical card brands', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					currency: 'usd',
					payment_method_details: {
						type: 'card',
						card: { brand: 'custom_brand', last4: '4242' },
					},
				} }
			/>
		);

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Custom brand ending in 4242' )
		).toBeInTheDocument();
		expect( summary.querySelector( 'img' ) ).toBeNull();
	} );

	it( 'prefers each usable balance field and currency over conflicting flat settlement values', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					currency: 'eur',
					fee: 999,
					net: 4999,
					balance_transaction: {
						id: 'txn_fx',
						amount: 5532,
						fee: 180,
						currency: 'usd',
					},
				} }
			/>
		);

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Converted amount: $55.32 USD' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -$1.80 USD' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: €49.99' )
		).toBeInTheDocument();
	} );

	it( 'resolves settlement fields independently and keeps refunds in charge currency', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'partially_refunded',
					amount: 5000,
					amount_refunded: 1000,
					currency: 'eur',
					fee: 125,
					net: 4999,
					balance_transaction: {
						id: 'txn_fx_net',
						amount: 5532,
						net: 5352,
						currency: 'usd',
					},
				} }
			/>
		);

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Converted amount: $55.32 USD' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Refunded: -€10.00' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -€1.25' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: $53.52 USD' )
		).toBeInTheDocument();
	} );

	it( 'keeps same-currency settlement output unchanged', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					currency: 'usd',
					fee: 180,
					net: 4820,
					balance_transaction: {
						id: 'txn_usd',
						amount: 5000,
						fee: 180,
						net: 4820,
						currency: 'usd',
					},
				} }
			/>
		);

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( summary ).queryByText( /Converted amount:/ )
		).not.toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -$1.80' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: $48.20' )
		).toBeInTheDocument();
	} );

	it.each( [
		[ 'an id', 'txn_legacy' ],
		[ 'no balance transaction', undefined ],
		[
			'an object without currency',
			{ id: 'txn_incomplete', amount: 5532, fee: 180, net: 5352 },
		],
	] )( 'uses flat charge-currency fallbacks for %s', ( _case, balance ) => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					currency: 'eur',
					fee: 180,
					net: 4820,
					balance_transaction: balance,
				} }
			/>
		);

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( summary ).queryByText( /Converted amount:/ )
		).not.toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -€1.80' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: €48.20' )
		).toBeInTheDocument();
	} );

	afterEach( () => {
		mockHistoryNavigate = null;
		anchorClickSpy.mockRestore();
		jest.useRealTimers();
	} );

	it( 'announces loaded transactions and gives row links clear purpose', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [
				{
					id: 'txn_test',
					type: 'charge',
					date: '2026-06-18',
					amount: 5000,
					currency: 'usd',
				},
			],
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 1,
			total: 5000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect( screen.getByText( 'Spotlight promotion' ) ).toBeInTheDocument();

		expect(
			await screen.findByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_test',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Transactions loaded.' )
		).toBeInTheDocument();
	} );

	it( 'projects the global uncaptured count on the ordinary transactions view', async () => {
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );
		mockGetAuthorizationsSummary.mockResolvedValue( { count: 26 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( '0 transactions' )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'link', { name: 'Uncaptured (26)' } )
		).toBeInTheDocument();
		expect( mockGetAuthorizationsSummary ).toHaveBeenCalledWith( {} );
	} );

	it( 'keeps transactions usable when the uncaptured count fails', async () => {
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );
		mockGetAuthorizationsSummary.mockRejectedValue(
			new Error( 'Authorization summary unavailable.' )
		);

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( '0 transactions' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Uncaptured (…)' } )
		).toBeInTheDocument();
	} );

	it.each( [
		[
			'summary count when the list omits its total',
			{ data: [] },
			{ count: 641 },
			'641',
			'26',
		],
		[
			'explicit zero from the list before a non-zero summary',
			{
				data: [
					{
						id: 'txn_explicit_zero',
						type: 'charge',
						date: '2026-07-20',
						amount: 2500,
						currency: 'usd',
					},
				],
				total_count: 0,
			},
			{ count: 641 },
			'0',
			'0',
		],
	] )(
		'passes %s through the settled pagination contract',
		async ( _label, response, summary, totalItems, totalPages ) => {
			mockGetTransactions.mockResolvedValue( response as never );
			mockGetTransactionsSummary.mockResolvedValue( summary );

			render(
				<MemoryRouter
					initialEntries={ [ '/woopayments/transactions' ] }
				>
					<WooPaymentsTransactionsPage />
				</MemoryRouter>
			);

			await screen.findByText( '641 transactions' );
			expect(
				screen.getByTestId( 'money-movement-dataviews' )
			).toHaveAttribute( 'data-total-items', totalItems );
			expect(
				screen.getByTestId( 'money-movement-dataviews' )
			).toHaveAttribute( 'data-total-pages', totalPages );
		}
	);

	it( 'uses the complete settled defaults without replacing stored field preferences', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [
				{
					id: 'txn_preference_boundary',
					type: 'charge',
					date: '2026-07-20',
					amount: 2500,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetTransactionsSummary.mockResolvedValue( { count: 1 } );

		const firstRender = render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.getByTestId( 'money-movement-dataviews' )
		).toHaveAttribute(
			'data-visible-fields',
			'date,type,amount,fees,net,source,customer'
		);

		firstRender.unmount();
		window.localStorage.setItem(
			'woocommerce_woopayments_money_movement_view_transactions',
			JSON.stringify( {
				fields: [ 'date', 'type', 'customer', 'amount' ],
			} )
		);

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.getByTestId( 'money-movement-dataviews' )
		).toHaveAttribute( 'data-visible-fields', 'date,type,customer,amount' );
	} );

	it( 'renders the settled field schema from normalized ordinary and exceptional rows', async () => {
		const toLocaleStringSpy = jest
			.spyOn( Date.prototype, 'toLocaleString' )
			.mockReturnValue( 'Jul 20, 2026, 10:30 AM' );
		window.localStorage.setItem(
			'woocommerce_woopayments_money_movement_view_transactions',
			JSON.stringify( {
				fields: [
					'date',
					'type',
					'amount',
					'fees',
					'net',
					'source',
					'customer',
				],
			} )
		);

		mockGetTransactions.mockResolvedValue( {
			data: [
				{
					id: 'txn_card',
					type: 'charge',
					date: '2026-07-20T10:30:00',
					amount: 2500,
					fees: 103,
					net: 2397,
					currency: 'usd',
					source: 'visa',
					source_identifier: '4242',
				},
				{
					id: 'txn_reader_fee',
					type: 'charge',
					metadata: { charge_type: 'card_reader_fee' },
					date: '2026-07-20T10:30:00',
					amount: -50,
					fees: 0,
					net: -50,
					currency: 'usd',
					source: 'visa',
					source_identifier: '4242',
				},
				{
					id: 'txn_giropay',
					type: 'charge',
					currency: 'eur',
					source: 'giropay',
					source_identifier: 'DE89370400440532013000',
				},
				{
					id: 'txn_p24',
					type: 'charge',
					currency: 'eur',
					source: 'p24',
					source_identifier: 'ing',
				},
				{
					id: 'txn_unknown',
					type: 'charge',
					currency: 'usd',
					source: 'custom_method',
					source_identifier: 'bank-42',
				},
				{
					id: 'txn_afterpay',
					type: 'charge',
					currency: 'usd',
					source: 'afterpay_clearpay',
				},
			] as never,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			count: 6,
			total: 2450,
			currency: 'usd',
		} );

		try {
			render(
				<MemoryRouter
					initialEntries={ [ '/woopayments/transactions' ] }
				>
					<WooPaymentsTransactionsPage />
				</MemoryRouter>
			);

			const cardLink = await screen.findByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_card',
			} );
			const cardRow = cardLink.closest( '[role="row"]' ) as HTMLElement;
			const readerLink = screen.getByRole( 'link', {
				name: 'View transaction details for Reader fee transaction txn_reader_fee',
			} );
			const readerRow = readerLink.closest(
				'[role="row"]'
			) as HTMLElement;

			const dateHeader = screen.getByRole( 'columnheader', {
				name: 'Date / time',
			} );
			expect( dateHeader ).toHaveAttribute( 'data-field-type', 'date' );
			expect( dateHeader ).toHaveAttribute(
				'data-filter-operators',
				'before,after,between'
			);
			const typeHeader = screen.getByRole( 'columnheader', {
				name: 'Type',
			} );
			expect( typeHeader ).toHaveAttribute(
				'data-filter-operators',
				'is'
			);
			expect(
				screen.getByTestId( 'money-movement-dataviews' )
			).toHaveAttribute( 'data-discoverable-filter-fields', 'date,type' );
			[ 'Amount', 'Fees', 'Net' ].forEach( ( name ) => {
				expect(
					screen.getByRole( 'columnheader', { name } )
				).toHaveAttribute( 'data-filter-disabled', 'true' );
			} );
			expect( typeHeader ).toHaveAttribute(
				'data-filter-elements',
				'charge:Charge|payment:Payment|payment_failure_refund:Payment failure refund|payment_refund:Payment refund|refund:Refund|refund_failure:Refund failure|dispute:Dispute|dispute_reversal:Dispute reversal|card_reader_fee:Reader fee|financing_payout:Loan disbursement|financing_paydown:Loan repayment|fee_refund:Fee refund|network_costs:Network costs'
			);

			expect(
				within( cardRow ).getByText( 'Jul 20, 2026, 10:30 AM' )
			).toBeInTheDocument();
			expect(
				within( cardRow ).getByText( '$25.00' )
			).toBeInTheDocument();
			expect(
				within( cardRow ).getByText( '-$1.03' )
			).toBeInTheDocument();
			expect(
				within( cardRow ).getByText( '$23.97' )
			).toBeInTheDocument();
			expect(
				within( cardRow ).getByText( 'Visa •••• 4242' )
			).toBeInTheDocument();

			expect(
				within( readerRow ).getByText( '$0.00' )
			).toBeInTheDocument();
			expect( within( readerRow ).getAllByText( '-$0.50' ) ).toHaveLength(
				2
			);
			expect(
				within( readerRow ).queryByText( /Visa/ )
			).not.toBeInTheDocument();
			expect( within( readerRow ).getAllByText( '-' ) ).toHaveLength( 2 );

			expect(
				screen.getByText( 'Giropay DE89370400440532013000' )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Przelewy24 (P24) ING' )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Custom method bank-42' )
			).toBeInTheDocument();
			expect( screen.getByText( 'Afterpay' ) ).toBeInTheDocument();
		} finally {
			toLocaleStringSpy.mockRestore();
		}
	} );

	it( 'retains an incomplete Type filter until Refund commits, then clears and follows router history', async () => {
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<MoneyMovementRouterBridge />
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( '0 transactions' );
		const initialRequestCount = mockGetTransactions.mock.calls.length;
		const dataViews = screen.getByTestId( 'money-movement-dataviews' );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock add Type filter' } )
		);
		expect( dataViews ).toHaveAttribute(
			'data-view-filters',
			'[{"field":"type","operator":"is"}]'
		);
		expect( mockHistoryPush ).not.toHaveBeenCalled();
		expect( mockGetTransactions ).toHaveBeenCalledTimes(
			initialRequestCount
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock choose Refund' } )
		);
		await waitFor( () => {
			expect( mockHistoryPush ).toHaveBeenCalledTimes( 1 );
			expect(
				screen.getByTestId( 'money-movement-route' )
			).toHaveTextContent( 'type_is=refund' );
			expect( mockGetTransactions ).toHaveBeenLastCalledWith(
				expect.objectContaining( { type_is: 'refund' } )
			);
		} );

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock clear DataViews filters',
			} )
		);
		await waitFor( () => {
			expect( mockHistoryPush ).toHaveBeenCalledTimes( 2 );
			expect(
				screen.getByTestId( 'money-movement-route' )
			).not.toHaveTextContent( 'type_is' );
			expect( mockGetTransactions ).toHaveBeenLastCalledWith(
				expect.not.objectContaining( { type_is: expect.anything() } )
			);
		} );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock router back' } )
		);
		await waitFor( () => {
			expect(
				screen.getByTestId( 'money-movement-route' )
			).toHaveTextContent( 'type_is=refund' );
			expect( dataViews ).toHaveAttribute(
				'data-view-filters',
				'[{"field":"type","operator":"is","value":"refund"}]'
			);
		} );
	} );

	it( 'keeps the applied Date URL while a between range is incomplete and commits the complete range once', async () => {
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?date_after=2026-07-13',
				] }
			>
				<MoneyMovementRouterBridge />
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( '0 transactions' );
		mockHistoryPush.mockClear();
		const initialRequestCount = mockGetTransactions.mock.calls.length;

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock change Date to between',
			} )
		);
		expect(
			screen.getByTestId( 'money-movement-route' )
		).toHaveTextContent( 'date_after=2026-07-13' );
		expect(
			screen.getByTestId( 'money-movement-dataviews' )
		).toHaveAttribute(
			'data-view-filters',
			'[{"field":"date","operator":"between"}]'
		);
		expect( mockHistoryPush ).not.toHaveBeenCalled();
		expect( mockGetTransactions ).toHaveBeenCalledTimes(
			initialRequestCount
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock complete Date range' } )
		);
		await waitFor( () => {
			expect( mockHistoryPush ).toHaveBeenCalledTimes( 1 );
			expect(
				screen.getByTestId( 'money-movement-route' )
			).toHaveTextContent(
				'date_between=2026-07-13&date_between=2026-07-20'
			);
			expect( mockGetTransactions ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					date_between: [ '2026-07-13', '2026-07-20' ],
				} )
			);
		} );
	} );

	it( 'drops transaction filter drafts when routing to uncaptured state without crossing preferences or query contracts', async () => {
		window.localStorage.setItem(
			'woocommerce_woopayments_money_movement_view_transactions',
			JSON.stringify( { fields: [ 'date', 'type' ] } )
		);
		window.localStorage.setItem(
			'woocommerce_woopayments_money_movement_view_authorizations',
			JSON.stringify( { fields: [ 'order', 'amount' ] } )
		);
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0 } );
		mockGetAuthorizations.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetAuthorizationsSummary.mockResolvedValue( { count: 0 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<MoneyMovementRouterBridge />
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( '0 transactions' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock add Type filter' } )
		);
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock open uncaptured transactions',
			} )
		);

		await screen.findByText( '0 uncaptured transactions' );
		await waitFor( () => {
			expect(
				screen.getByTestId( 'money-movement-dataviews' )
			).toHaveAttribute( 'data-view-filters', '[]' );
			expect(
				screen.getByTestId( 'money-movement-dataviews' )
			).toHaveAttribute( 'data-visible-fields', 'order,amount' );
		} );
		expect( mockGetAuthorizations ).toHaveBeenLastCalledWith(
			expect.not.objectContaining( { type_is: expect.anything() } )
		);
		expect(
			JSON.parse(
				window.localStorage.getItem(
					'woocommerce_woopayments_money_movement_view_transactions'
				) || '{}'
			)
		).toEqual( expect.objectContaining( { fields: [ 'date', 'type' ] } ) );
		expect(
			JSON.parse(
				window.localStorage.getItem(
					'woocommerce_woopayments_money_movement_view_authorizations'
				) || '{}'
			)
		).toEqual(
			expect.objectContaining( { fields: [ 'order', 'amount' ] } )
		);
	} );

	it( 'builds transaction list links with payment ids and transaction context', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [
				{
					transaction_id: 'txn_test',
					payment_intent_id: 'pi_test',
					charge_id: 'ch_test',
					type: 'charge',
					date: '2026-06-18',
					amount: 5000,
					currency: 'usd',
				},
			],
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 1,
			total: 5000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_test',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=pi_test&transaction_id=txn_test&transaction_type=charge'
		);
	} );

	it( 'announces empty transaction results from a stable status region', async () => {
		mockGetTransactions.mockResolvedValue( { data: [] } );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 0,
			total: 0,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findAllByText( 'No transactions found.' )
		).not.toHaveLength( 0 );
	} );

	it( 'uses URL query state for the transactions request and summary', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [
				{
					transaction_id: 'txn_loan',
					payment_intent_id: 'pi_loan',
					type: 'charge',
					date: '2026-06-18',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 42,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 42,
			total: 5000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?page=2&pagesize=50&sort=amount&direction=asc&loan_id_is=loan_test',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_loan',
			} )
		).toBeInTheDocument();
		expect( mockGetTransactions ).toHaveBeenCalledWith(
			expect.objectContaining( {
				page: 2,
				pagesize: 50,
				sort: 'amount',
				direction: 'asc',
				loan_id_is: 'loan_test',
			} )
		);
		expect( mockGetTransactionsSummary ).toHaveBeenCalledWith(
			expect.objectContaining( {
				loan_id_is: 'loan_test',
			} )
		);
	} );

	it( 'keeps transaction pagination and search inside the settings shell', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [],
			total_count: 50,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 50,
			total: 0,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( '50 transactions' );
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock change transaction page and search',
			} )
		);

		expect( mockHistoryPush ).toHaveBeenCalledTimes( 1 );
		const route = new URL(
			mockHistoryPush.mock.calls[ 0 ][ 0 ],
			'https://example.com/wp-admin/'
		);
		expect( route.searchParams.getAll( 'page' ) ).toEqual( [
			'wc-settings',
		] );
		expect( route.searchParams.get( 'path' ) ).toBe(
			'/woopayments/transactions'
		);
		expect( route.searchParams.get( 'paged' ) ).toBe( '2' );
		expect( route.searchParams.get( 'search' ) ).toBe( 'Order #1520' );
	} );

	it( 'routes transaction autocomplete changes through the settings shell', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [],
			total_count: 0,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 0,
			total: 0,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByRole( 'searchbox', {
			name: 'Search transactions',
		} );
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock apply transaction search',
			} )
		);

		expect( mockHistoryPush ).toHaveBeenCalledTimes( 1 );
		const route = new URL(
			mockHistoryPush.mock.calls[ 0 ][ 0 ],
			'https://example.com/wp-admin/'
		);
		expect( route.searchParams.getAll( 'page' ) ).toEqual( [
			'wc-settings',
		] );
		expect( route.searchParams.get( 'paged' ) ).toBe( '1' );
		expect( route.searchParams.get( 'search' ) ).toBe( 'MA05 Searchable' );
	} );

	it( 'offers searchable transaction exports with the active query', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [],
			total_count: 0,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 0,
			total: 0,
			currency: 'usd',
		} );
		mockRequestTransactionsExport.mockResolvedValue( {
			export_id: 'export_test',
		} );
		mockGetTransactionsExportUrl.mockResolvedValue( {
			download_url: 'https://example.com/transactions.csv',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?search=Ada&store_currency_is=usd',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'searchbox', {
				name: 'Search transactions',
			} )
		).toHaveValue( 'Ada' );

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Download transactions' } )
			);
		} );
		expect(
			await screen.findByText(
				'Your transactions export has started downloading.'
			)
		).toHaveAttribute( 'role', 'status' );

		expect( mockRequestTransactionsExport ).toHaveBeenCalledWith(
			expect.objectContaining( {
				search: 'Ada',
				store_currency_is: 'usd',
			} )
		);
		expect( mockGetTransactionsExportUrl ).toHaveBeenCalledWith(
			'export_test'
		);
	} );

	it( 'persists transaction DataViews preferences without changing the REST query', async () => {
		mockGetTransactions.mockResolvedValue( {
			data: [
				{
					transaction_id: 'txn_test',
					payment_intent_id: 'pi_test',
					type: 'charge',
					date: '2026-06-18',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 1,
			total: 5000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [ '/woopayments/transactions?search=Ada' ] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'View transaction details for Charge transaction txn_test',
			} )
		).toBeInTheDocument();

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'Mock change DataViews columns',
				} )
			);
		} );

		expect(
			window.localStorage.getItem(
				'woocommerce_woopayments_money_movement_view_transactions'
			)
		).toContain( '"fields":["type","amount"]' );
		expect( mockGetTransactions ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				search: 'Ada',
			} )
		);
		expect( mockGetTransactions ).not.toHaveBeenLastCalledWith(
			expect.objectContaining( {
				fields: expect.anything(),
				layout: expect.anything(),
			} )
		);
	} );

	it( 'renders uncaptured authorizations in a separate transactions tab', async () => {
		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					payment_intent_id: 'pi_auth',
					charge_id: 'ch_auth',
					order_id: 123,
					created: '2026-06-12T10:30:00Z',
					risk_level: 1,
					amount: 5000,
					currency: 'usd',
					customer_name: 'Ada Lovelace',
					customer_email: 'ada@example.com',
					customer_country: 'US',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 1,
			total: 5000,
			currency: 'usd',
			all_currencies: [ 'usd' ],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			screen.getByRole( 'link', { name: 'Transactions' } )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'link', { name: 'Uncaptured (1)' } )
		).toHaveAttribute( 'aria-current', 'page' );

		expect(
			await screen.findByRole( 'columnheader', {
				name: 'Authorized date',
			} )
		).toBeInTheDocument();
		[
			'Capture by',
			'Order',
			'Risk',
			'Amount',
			'Customer',
			'Actions',
		].forEach( ( label ) => {
			expect(
				screen.getByRole( 'columnheader', { name: label } )
			).toBeInTheDocument();
		} );
		expect(
			screen.getByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Cancel authorization for order #123',
			} )
		).toBeInTheDocument();
		expect( screen.getByText( 'Ada Lovelace' ) ).toBeInTheDocument();
	} );

	it( 'links uncaptured orders to payment details', async () => {
		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					payment_intent_id: 'pi_auth',
					order_id: 123,
					created: '2026-06-12T10:30:00Z',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( { count: 1 } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?view=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'View payment details for order #123',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=pi_auth'
		);
	} );

	it( 'keeps incomplete authorization orders as text', async () => {
		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					order_id: 124,
					created: '2026-06-12T10:30:00Z',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( { count: 1 } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?view=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect( await screen.findByText( '#124' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', {
				name: 'View payment details for order #124',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'uses sanitized uncaptured query state and separate DataViews preferences', async () => {
		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					payment_intent_id: 'pi_auth',
					order_id: 123,
					created: '2026-06-12T10:30:00Z',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 1,
			total: 5000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured&search=Ada&loan_id_is=loan_test',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'searchbox', {
				name: 'Search uncaptured transactions',
			} )
		).toHaveValue( 'Ada' );

		expect( mockGetAuthorizations ).toHaveBeenCalledWith(
			expect.objectContaining( {
				search: 'Ada',
			} )
		);
		expect( mockGetAuthorizations ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				loan_id_is: 'loan_test',
			} )
		);

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'Mock change DataViews columns',
				} )
			);
		} );

		expect(
			window.localStorage.getItem(
				'woocommerce_woopayments_money_movement_view_authorizations'
			)
		).toContain( '"fields":["type","amount"]' );
		expect(
			window.localStorage.getItem(
				'woocommerce_woopayments_money_movement_view_transactions'
			)
		).toBeNull();
	} );

	it( 'keeps authorization capture pending and dispatches a success notice', async () => {
		let resolveCapture: ( value: unknown ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve ) => {
			resolveCapture = resolve;
		} );

		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					payment_intent_id: 'pi_auth',
					order_id: 123,
					created: '2026-06-12T10:30:00Z',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 1,
			total: 5000,
			currency: 'usd',
		} );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		const captureButton = await screen.findByRole( 'button', {
			name: 'Capture authorization for order #123',
		} );
		await act( async () => {
			await userEvent.click( captureButton );
		} );

		expect(
			await screen.findByRole( 'button', {
				name: 'Capturing authorization for order #123',
			} )
		).toBeDisabled();

		await act( async () => {
			resolveCapture( {
				id: 'pi_auth',
				status: 'succeeded',
			} );
			await capturePromise;
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );

		await waitFor( () =>
			expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
				'Payment for order #123 captured successfully.'
			)
		);
		expect( mockCaptureAuthorization ).toHaveBeenCalledWith(
			123,
			'pi_auth'
		);
	} );

	it( 'reloads uncaptured summary data after a successful authorization action', async () => {
		mockGetAuthorizations
			.mockResolvedValueOnce( {
				data: [
					{
						payment_intent_id: 'pi_auth',
						order_id: 123,
						created: '2026-06-12T10:30:00Z',
						amount: 5000,
						currency: 'usd',
					},
				],
				total_count: 1,
			} )
			.mockResolvedValueOnce( {
				data: [],
				total_count: 0,
			} );
		mockGetAuthorizationsSummary
			.mockResolvedValueOnce( {
				count: 1,
				total: 5000,
				currency: 'usd',
			} )
			.mockResolvedValueOnce( {
				count: 1,
				total: 5000,
				currency: 'usd',
			} )
			.mockResolvedValueOnce( {
				count: 0,
				total: 0,
				currency: 'usd',
			} )
			.mockResolvedValueOnce( {
				count: 0,
				total: 0,
				currency: 'usd',
			} );
		mockCaptureAuthorization.mockResolvedValueOnce( {
			id: 'pi_auth',
			status: 'succeeded',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( '1 uncaptured transactions' )
		).toBeInTheDocument();
		expect( screen.getAllByText( '$50.00' ) ).not.toHaveLength( 0 );

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'Capture authorization for order #123',
				} )
			);
		} );

		await waitFor( () => {
			expect( mockGetAuthorizations ).toHaveBeenCalledTimes( 2 );
			expect( mockGetAuthorizationsSummary ).toHaveBeenCalledTimes( 4 );
		} );
		expect(
			await screen.findByText( '0 uncaptured transactions' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Uncaptured (0)' } )
		).toBeInTheDocument();
		expect( screen.getByText( '$0.00' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'keeps the newest uncaptured count when an older request resolves last', async () => {
		let resolveInitialCount: ( value: {
			count: number;
			total: number;
			currency: string;
		} ) => void = () => undefined;
		let resolveRefreshedCount: ( value: {
			count: number;
			total: number;
			currency: string;
		} ) => void = () => undefined;
		const initialCountPromise = new Promise< {
			count: number;
			total: number;
			currency: string;
		} >( ( resolve ) => {
			resolveInitialCount = resolve;
		} );
		const refreshedCountPromise = new Promise< {
			count: number;
			total: number;
			currency: string;
		} >( ( resolve ) => {
			resolveRefreshedCount = resolve;
		} );

		mockGetAuthorizations
			.mockResolvedValueOnce( {
				data: [
					{
						payment_intent_id: 'pi_auth',
						order_id: 123,
						created: '2026-06-12T10:30:00Z',
						amount: 5000,
						currency: 'usd',
					},
				],
				total_count: 1,
			} )
			.mockResolvedValueOnce( { data: [], total_count: 0 } );
		mockGetAuthorizationsSummary
			.mockResolvedValueOnce( {
				count: 1,
				total: 5000,
				currency: 'usd',
			} )
			.mockReturnValueOnce( initialCountPromise )
			.mockResolvedValueOnce( {
				count: 0,
				total: 0,
				currency: 'usd',
			} )
			.mockReturnValueOnce( refreshedCountPromise );
		mockCaptureAuthorization.mockResolvedValueOnce( {
			id: 'pi_auth',
			status: 'succeeded',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		const captureButton = await screen.findByRole( 'button', {
			name: 'Capture authorization for order #123',
		} );
		await act( async () => {
			await userEvent.click( captureButton );
		} );
		await waitFor( () =>
			expect( mockGetAuthorizationsSummary ).toHaveBeenCalledTimes( 4 )
		);

		await act( async () => {
			resolveRefreshedCount( {
				count: 0,
				total: 0,
				currency: 'usd',
			} );
			await refreshedCountPromise;
		} );
		expect(
			await screen.findByRole( 'link', { name: 'Uncaptured (0)' } )
		).toBeInTheDocument();

		await act( async () => {
			resolveInitialCount( {
				count: 1,
				total: 5000,
				currency: 'usd',
			} );
			await initialCountPromise;
		} );
		expect(
			screen.getByRole( 'link', { name: 'Uncaptured (0)' } )
		).toBeInTheDocument();
	} );

	it( 'shows an unavailable uncaptured count when the newest refresh fails', async () => {
		mockGetAuthorizations
			.mockResolvedValueOnce( {
				data: [
					{
						payment_intent_id: 'pi_auth',
						order_id: 123,
						created: '2026-06-12T10:30:00Z',
						amount: 5000,
						currency: 'usd',
					},
				],
				total_count: 1,
			} )
			.mockResolvedValueOnce( { data: [], total_count: 0 } );
		mockGetAuthorizationsSummary
			.mockResolvedValueOnce( {
				count: 1,
				total: 5000,
				currency: 'usd',
			} )
			.mockResolvedValueOnce( {
				count: 1,
				total: 5000,
				currency: 'usd',
			} )
			.mockResolvedValueOnce( {
				count: 0,
				total: 0,
				currency: 'usd',
			} )
			.mockRejectedValueOnce(
				new Error( 'Global authorization count unavailable.' )
			);
		mockCaptureAuthorization.mockResolvedValueOnce( {
			id: 'pi_auth',
			status: 'succeeded',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', { name: 'Uncaptured (1)' } )
		).toBeInTheDocument();
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'Capture authorization for order #123',
				} )
			);
		} );

		expect(
			await screen.findByText( '0 uncaptured transactions' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Uncaptured (…)' } )
		).toBeInTheDocument();
	} );

	it( 'skips the global count refresh when capture completes after unmount', async () => {
		let resolveCapture: ( value: unknown ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve ) => {
			resolveCapture = resolve;
		} );

		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					payment_intent_id: 'pi_auth',
					order_id: 123,
					created: '2026-06-12T10:30:00Z',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 1,
			total: 5000,
			currency: 'usd',
		} );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		const mountedPage = render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', { name: 'Uncaptured (1)' } )
		).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		);
		mountedPage.unmount();

		await act( async () => {
			resolveCapture( { id: 'pi_auth', status: 'succeeded' } );
			await capturePromise;
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );

		expect( mockGetAuthorizationsSummary ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'dispatches an error notice when canceling an authorization fails', async () => {
		mockGetAuthorizations.mockResolvedValue( {
			data: [
				{
					payment_intent_id: 'pi_auth',
					order_id: 123,
					created: '2026-06-12T10:30:00Z',
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 1,
			total: 5000,
			currency: 'usd',
		} );
		mockCancelAuthorization.mockRejectedValueOnce(
			new Error( 'Authorization already canceled.' )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?tab=uncaptured',
				] }
			>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		const cancelButton = await screen.findByRole( 'button', {
			name: 'Cancel authorization for order #123',
		} );

		await act( async () => {
			await userEvent.click( cancelButton );
		} );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Unable to cancel authorization for order #123. Authorization already canceled.'
			)
		);
		expect( mockCancelAuthorization ).toHaveBeenCalledWith(
			123,
			'pi_auth'
		);
	} );

	it( 'announces loaded disputes and routes actionable rows to transaction details', async () => {
		mockGetDisputes.mockResolvedValue( {
			data: [
				{
					id: 'dp_test',
					charge_id: 'ch_test',
					reason: 'fraudulent',
					status: 'needs_response',
					date: '2026-06-18',
					amount: 5000,
					currency: 'usd',
				},
				{
					id: 'dp_closed',
					charge_id: 'ch_closed',
					reason: 'fraudulent',
					status: 'won',
					date: '2026-06-18',
					amount: 5000,
					currency: 'usd',
				},
			],
		} );
		mockGetDisputesSummary.mockResolvedValue( {
			total_count: 2,
			total: 10000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/disputes' ] }>
				<WooPaymentsDisputesPage />
			</MemoryRouter>
		);

		expect( screen.getByText( 'Spotlight promotion' ) ).toBeInTheDocument();

		const challengeLink = await screen.findByRole( 'link', {
			name: 'Respond now to transaction unauthorized dispute dp_test from transaction details',
		} );
		expect( challengeLink ).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=ch_test'
		);
		expect(
			screen.getByRole( 'link', {
				name: 'View transaction details for Transaction unauthorized dispute dp_closed',
			} )
		).toBeInTheDocument();
		expect(
			screen.getAllByText( 'Transaction unauthorized' )
		).toHaveLength( 2 );
		expect( screen.getByText( 'Disputes loaded.' ) ).toBeInTheDocument();
	} );

	it( 'uses URL query state for disputes and exposes reference-style response actions', async () => {
		mockGetDisputes.mockResolvedValue( {
			data: [
				{
					id: 'dp_test',
					charge_id: 'ch_test',
					reason: 'fraudulent',
					status: 'needs_response',
					date: '2026-06-18',
					evidence_due_by: 1781913600,
					amount: 5000,
					currency: 'usd',
				},
			],
			total_count: 1,
		} );
		mockGetDisputesSummary.mockResolvedValue( {
			total_count: 1,
			total: 5000,
			currency: 'usd',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/disputes?page=3&pagesize=10&status_is=needs_response&store_currency_is=usd',
				] }
			>
				<WooPaymentsDisputesPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', {
				name: 'Respond now to transaction unauthorized dispute dp_test from transaction details',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=ch_test'
		);
		expect( mockGetDisputes ).toHaveBeenCalledWith(
			expect.objectContaining( {
				page: 3,
				pagesize: 10,
				status_is: 'needs_response',
				store_currency_is: 'usd',
			} )
		);
		expect( mockGetDisputesSummary ).toHaveBeenCalledWith(
			expect.objectContaining( {
				status_is: 'needs_response',
				store_currency_is: 'usd',
			} )
		);
	} );

	it( 'offers dispute exports with the active query', async () => {
		mockGetDisputes.mockResolvedValue( {
			data: [],
			total_count: 0,
		} );
		mockGetDisputesSummary.mockResolvedValue( {
			total_count: 0,
			total: 0,
			currency: 'usd',
		} );
		mockRequestDisputesExport.mockResolvedValue( {
			export_id: 'export_test',
		} );
		mockGetDisputesExportUrl.mockResolvedValue( {
			download_url: 'https://example.com/disputes.csv',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/disputes?status_is=needs_response',
				] }
			>
				<WooPaymentsDisputesPage />
			</MemoryRouter>
		);

		expect(
			await screen.findAllByText( 'No disputes found.' )
		).not.toHaveLength( 0 );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Download disputes' } )
			);
		} );
		expect(
			await screen.findByText(
				'Your disputes export has started downloading.'
			)
		).toHaveAttribute( 'role', 'status' );

		expect( mockRequestDisputesExport ).toHaveBeenCalledWith(
			expect.objectContaining( {
				status_is: 'needs_response',
			} )
		);
		expect( mockGetDisputesExportUrl ).toHaveBeenCalledWith(
			'export_test'
		);
	} );

	it( 'announces transaction detail loading from a stable status region', () => {
		mockGetTransaction.mockImplementation( () => new Promise( () => {} ) );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading transaction details…'
		);
	} );

	it( 'announces transaction detail errors from a stable alert region', async () => {
		mockGetTransaction.mockRejectedValue( new Error( 'Provider failed' ) );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Provider failed'
		);
	} );

	it( 'renders card reader fee details from the reader charge summary route', async () => {
		mockGetReaderChargeSummary.mockResolvedValue( {
			data: [
				{
					reader_id: 'tmr_reader_1',
					status: 'active',
					transactions: 3,
					fee: {
						amount: 1234,
						currency: 'usd',
					},
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=ch_reader_fee_123&transaction_id=txn_reader_fee_123&transaction_type=card_reader_fee',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Card readers' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Transaction details loaded.' )
		).not.toBeInTheDocument();
		expect( mockGetReaderChargeSummary ).toHaveBeenCalledWith(
			'txn_reader_fee_123',
			expect.objectContaining( {
				signal: expect.any( Object ),
			} )
		);
		expect( await screen.findByText( 'tmr_reader_1' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: 'Reader id' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: 'Status' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: 'Transactions' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: 'Fee' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect( screen.getByText( '3' ) ).toBeInTheDocument();
		expect( screen.getByText( '$12.34' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Download' } )
		).toBeInTheDocument();
		expect( mockGetCharge ).not.toHaveBeenCalled();
		expect( mockGetPaymentIntent ).not.toHaveBeenCalled();
		expect( mockGetTransaction ).not.toHaveBeenCalled();
	} );

	it( 'shows reader details errors from the card reader fee route', async () => {
		mockGetReaderChargeSummary.mockRejectedValue(
			new Error( 'Reader provider failed.' )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=ch_reader_fee_123&transaction_id=txn_reader_fee_123&transaction_type=card_reader_fee',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Readers details not loaded'
		);
		expect( mockGetReaderChargeSummary ).toHaveBeenCalledWith(
			'txn_reader_fee_123',
			expect.objectContaining( {
				signal: expect.any( Object ),
			} )
		);
	} );

	it( 'shows an empty state for card reader fee routes without rows', async () => {
		mockGetReaderChargeSummary.mockResolvedValue( {
			data: [],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=ch_reader_fee_123&transaction_id=txn_reader_fee_123&transaction_type=card_reader_fee',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await screen.findByRole( 'heading', { name: 'Card readers' } );
		expect(
			screen.getAllByText( 'No reader details found.' )
		).toHaveLength( 2 );
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'times out stalled reader fee summary requests', async () => {
		jest.useFakeTimers();
		mockGetReaderChargeSummary.mockImplementation(
			( _transactionId, options ) =>
				new Promise( ( _resolve, reject ) => {
					options?.signal?.addEventListener( 'abort', () => {
						const error = new Error( 'Aborted' );
						error.name = 'AbortError';
						reject( error );
					} );
				} )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=ch_reader_fee_123&transaction_id=txn_reader_fee_123&transaction_type=card_reader_fee',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await screen.findByRole( 'heading', { name: 'Card readers' } );
		expect( screen.getAllByText( 'Loading reader details…' ) ).toHaveLength(
			2
		);

		await act( async () => {
			jest.advanceTimersByTime( 15000 );
		} );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Readers details not loaded. The request timed out.'
		);
	} );

	it( 'renders charge gross in shopper currency and settlement amounts in balance currency', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_fx',
			status: 'succeeded',
			amount: 5000,
			currency: 'eur',
			charge: {
				id: 'ch_fx',
				payment_intent: 'pi_fx',
				type: 'charge',
				amount: 5000,
				currency: 'eur',
				balance_transaction: {
					id: 'txn_fx',
					amount: 5532,
					fee: 180,
					net: 5352,
					currency: 'usd',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_fx&transaction_id=txn_fx',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Payment details' } )
		).toBeInTheDocument();

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect( within( summary ).getByText( '€50.00' ) ).toBeInTheDocument();
		expect( within( summary ).getByText( 'EUR' ) ).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Converted amount: $55.32 USD' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -$1.80 USD' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: $53.52 USD' )
		).toBeInTheDocument();
	} );

	it( 'omits settlement amounts when the balance currency is missing', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_incomplete_balance',
			status: 'succeeded',
			amount: 5000,
			currency: 'eur',
			charge: {
				id: 'ch_incomplete_balance',
				payment_intent: 'pi_incomplete_balance',
				type: 'charge',
				amount: 5000,
				currency: 'eur',
				balance_transaction: {
					id: 'txn_incomplete_balance',
					amount: 5532,
					fee: 180,
					net: 5352,
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_incomplete_balance&transaction_id=txn_incomplete_balance',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Payment details' } )
		).toBeInTheDocument();

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect( within( summary ).getByText( '€50.00' ) ).toBeInTheDocument();
		expect( within( summary ).getByText( 'EUR' ) ).toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Converted amount:/ )
		).not.toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Fees:/ )
		).not.toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Net:/ )
		).not.toBeInTheDocument();
	} );

	it( 'loads payment intent details when the route id is a payment intent', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			metadata: {
				ipp_channel: 'online',
			},
			charge: {
				id: 'ch_test',
				payment_method: 'pm_card_visa',
				balance_transaction: {
					id: 'txn_test',
					fee: 180,
					net: 4820,
					currency: 'usd',
				},
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				amount_refunded: 1000,
				billing_details: {
					email: 'ada@example.com',
					formatted_address: '1 Main Street<br/>New York, NY 10001',
					name: 'Ada Lovelace',
				},
				payment_method_details: {
					type: 'card',
					card: {
						brand: 'visa',
						checks: {
							address_line1_check: 'pass',
							address_postal_code_check: 'fail',
							cvc_check: 'pass',
						},
						country: 'US',
						exp_month: 12,
						exp_year: 2030,
						funding: 'credit',
						last4: '4242',
						network: 'visa',
					},
				},
				outcome: {
					risk_level: 'normal',
				},
				order: {
					id: 123,
					number: '123',
					url: 'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123',
					customer_url:
						'http://example.com/wp-admin/admin.php?page=wc-admin&path=/customers&filter=single_customer&customers=99',
					customer_name: 'Ada Lovelace',
					customer_email: 'ada@example.com',
					subscriptions: [
						{
							id: 456,
							number: '456',
							url: 'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=456',
						},
					],
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( {
			data: [
				{
					type: 'captured',
					message: 'Payment captured.',
					created: 1781712060,
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Payment details' } )
		).toBeInTheDocument();

		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect( summary ).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Partial refund' )
		).toBeInTheDocument();
		expect(
			within( summary ).queryByText( 'Succeeded' )
		).not.toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Sales channel' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Online store' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByRole( 'link', { name: 'Ada Lovelace' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-admin&path=/customers&filter=single_customer&customers=99'
		);
		expect(
			within( summary ).getByRole( 'link', { name: 'Order #123' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123'
		);
		expect(
			within( summary ).getByRole( 'link', {
				name: 'Subscription #456',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=456'
		);
		const visaLogo = within( summary ).getByRole( 'img', { name: 'Visa' } );
		expect( visaLogo.getAttribute( 'src' ) ).toContain(
			'images/payment-methods-cards/visa.svg'
		);
		expect( within( summary ).getByText( '•••• 4242' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);
		expect( within( summary ).getByText( 'ending in 4242' ) ).toHaveClass(
			'screen-reader-text'
		);
		expect( within( summary ).getByText( 'Normal' ) ).toBeInTheDocument();
		expect(
			within( summary ).getAllByText( '$50.00' ).length
		).toBeGreaterThan( 0 );
		expect(
			within( summary ).getByText( 'Refunded: -$10.00' )
		).toBeInTheDocument();
		expect( within( summary ).getByText( '$1.80' ) ).toBeInTheDocument();
		expect( within( summary ).getByText( '$48.20' ) ).toBeInTheDocument();

		const paymentMethod = screen
			.getByRole( 'heading', { name: 'Payment method' } )
			.closest( 'section' ) as HTMLElement;
		expect( paymentMethod ).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( '•••• 4242' )
		).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( '12 / 2030' )
		).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( 'Visa credit card' )
		).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( 'pm_card_visa' )
		).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( 'Ada Lovelace' )
		).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( 'ada@example.com' )
		).toBeInTheDocument();
		expect( getDetailValue( paymentMethod, 'Address' ) ).toHaveTextContent(
			'1 Main Street'
		);
		expect( getDetailValue( paymentMethod, 'Address' ) ).toHaveTextContent(
			'New York, NY 10001'
		);
		expect( getDetailValue( paymentMethod, 'Origin' ) ).toHaveTextContent(
			'United States'
		);
		expect(
			getDetailValue( paymentMethod, 'CVC check' )
		).toHaveTextContent( 'Passed' );
		expect(
			getDetailValue( paymentMethod, 'Street check' )
		).toHaveTextContent( 'Passed' );
		expect(
			getDetailValue( paymentMethod, 'Postal code check' )
		).toHaveTextContent( 'Failed' );

		expect(
			screen.getByRole( 'heading', { name: 'Identifiers' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'pi_test' ) ).toBeInTheDocument();
		expect( screen.getByText( 'ch_test' ) ).toBeInTheDocument();
		expect( screen.getByText( 'txn_test' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Payment captured.' ) ).toBeInTheDocument();
		expect( mockGetPaymentIntent ).toHaveBeenCalledWith( 'pi_test' );
		expect( mockGetTimeline ).toHaveBeenCalledWith( 'pi_test' );
		expect( mockGetTransaction ).not.toHaveBeenCalled();
	} );

	it( 'exposes the unavailable placeholder through an announceable role', async () => {
		// A card charge missing its number, expiry, owner and origin renders
		// the Dash placeholder in several rows.
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_missing',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_missing',
				balance_transaction: {
					id: 'txn_missing',
					fee: 0,
					net: 5000,
					currency: 'usd',
				},
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_method_details: {
					type: 'card',
					card: {
						brand: 'visa',
					},
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_missing&transaction_id=txn_missing',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const paymentMethod = (
			await screen.findByRole( 'heading', { name: 'Payment method' } )
		 ).closest( 'section' ) as HTMLElement;

		// The placeholder is discoverable by role with an accessible name,
		// which a bare aria-labelled <span> would not expose.
		const placeholders = within( paymentMethod ).getAllByRole( 'img', {
			name: 'Unavailable',
		} );
		expect( placeholders.length ).toBeGreaterThan( 0 );
		placeholders.forEach( ( placeholder ) => {
			expect( placeholder ).toHaveAccessibleName( 'Unavailable' );
		} );
	} );

	it( 'derives in-person sales channels from card-present payment methods and merged intent metadata', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_card_present',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			metadata: {
				ipp_channel: 'mobile_pos',
			},
			charge: {
				id: 'ch_card_present',
				payment_intent: 'pi_card_present',
				balance_transaction: 'txn_card_present',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_method_details: {
					type: 'card_present',
					card_present: {
						brand: 'visa',
						last4: '4242',
					},
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_card_present&transaction_id=txn_card_present',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const summary = (
			await screen.findByRole( 'heading', { name: 'Summary' } )
		 ).closest( 'section' ) as HTMLElement;

		expect( getDetailValue( summary, 'Sales channel' ) ).toHaveTextContent(
			'In-person (POS)'
		);
		const paymentMethod = getDetailValue( summary, 'Payment method' );
		expect(
			within( paymentMethod ).getByRole( 'img', { name: 'Visa' } )
		).toBeInTheDocument();
		expect(
			within( paymentMethod ).getByText( '•••• 4242' )
		).toHaveAttribute( 'aria-hidden', 'true' );
	} );

	it( 'renders generic payment method details for non-card payment methods', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_link',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_link',
				payment_intent: 'pi_link',
				balance_transaction: 'txn_link',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_method: 'pm_link',
				billing_details: {
					email: 'ada@example.com',
					formatted_address: '1 Main Street<br/>New York, NY 10001',
					name: 'Ada Lovelace',
				},
				payment_method_details: {
					type: 'link',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_link&transaction_id=txn_link',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const paymentMethod = (
			await screen.findByRole( 'heading', {
				name: 'Payment method',
			} )
		 ).closest( 'section' ) as HTMLElement;

		expect( getDetailValue( paymentMethod, 'Type' ) ).toHaveTextContent(
			'Link'
		);
		expect( getDetailValue( paymentMethod, 'ID' ) ).toHaveTextContent(
			'pm_link'
		);
		expect( getDetailValue( paymentMethod, 'Owner' ) ).toHaveTextContent(
			'Ada Lovelace'
		);
		expect(
			getDetailValue( paymentMethod, 'Owner email' )
		).toHaveTextContent( 'ada@example.com' );
		expect( getDetailValue( paymentMethod, 'Address' ) ).toHaveTextContent(
			'1 Main Street'
		);
		expect( getDetailValue( paymentMethod, 'Address' ) ).toHaveTextContent(
			'New York, NY 10001'
		);
		expect(
			within( paymentMethod ).queryByText( 'Number' )
		).not.toBeInTheDocument();
		expect(
			within( paymentMethod ).queryByText( 'CVC check' )
		).not.toBeInTheDocument();
	} );

	it( 'renders method-specific details for supported non-card payment methods', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_ideal',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_ideal',
				payment_intent: 'pi_ideal',
				balance_transaction: 'txn_ideal',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_method: 'pm_ideal',
				billing_details: {
					email: 'ada@example.test',
					formatted_address: '123 Canal St<br/>Amsterdam',
					name: 'Ada Buyer',
				},
				payment_method_details: {
					type: 'ideal',
					ideal: {
						bank: 'ING',
						bic: 'INGBNL2A',
						iban_last4: '6789',
						verified_name: 'Ada Buyer',
					},
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_ideal&transaction_id=txn_ideal',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const paymentMethod = (
			await screen.findByRole( 'heading', {
				name: 'Payment method',
			} )
		 ).closest( 'section' ) as HTMLElement;

		expect(
			getDetailValue( paymentMethod, 'Bank name' )
		).toHaveTextContent( 'ING' );
		expect( getDetailValue( paymentMethod, 'BIC' ) ).toHaveTextContent(
			'INGBNL2A'
		);
		expect( getDetailValue( paymentMethod, 'IBAN' ) ).toHaveTextContent(
			'6789'
		);
		expect(
			getDetailValue( paymentMethod, 'Verified name' )
		).toHaveTextContent( 'Ada Buyer' );
		expect( getDetailValue( paymentMethod, 'Address' ) ).toHaveTextContent(
			'123 Canal St'
		);
	} );

	it( 'opens the full refund modal from transaction details and reloads after a successful refund', async () => {
		const refundablePaymentIntent = {
			id: 'pi_refund',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_refund',
				payment_intent: 'pi_refund',
				balance_transaction: 'txn_refund',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
				order: {
					id: 123,
					number: '123',
					url: 'http://example.com/wp-admin/post.php?post=123&action=edit',
				},
			},
		};
		mockGetPaymentIntent
			.mockResolvedValueOnce( refundablePaymentIntent )
			.mockResolvedValueOnce( {
				...refundablePaymentIntent,
				charge: {
					...refundablePaymentIntent.charge,
					amount_refunded: 5000,
					refunded: true,
				},
			} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockResolvedValue( {
			id: 555,
			order_id: 123,
			amount: '50.00',
			reason: '',
			status: 'completed',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_refund&transaction_id=txn_refund',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const refundActionsButton = await screen.findByRole( 'button', {
			name: 'Transaction actions',
		} );
		await act( async () => {
			await userEvent.click( refundActionsButton );
		} );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			);
		} );

		const dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );
		expect(
			within( dialog ).getByText( ( _content, element ) => {
				return (
					element?.tagName.toLowerCase() === 'p' &&
					element.textContent ===
						'This will issue a full refund of $50.00 to the customer.'
				);
			} )
		).toBeInTheDocument();
		expect(
			within( dialog ).getByRole( 'link', { name: 'Go to the order' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/post.php?post=123&action=edit'
		);
		await act( async () => {
			await userEvent.click( within( dialog ).getByLabelText( 'Other' ) );
		} );
		await act( async () => {
			await userEvent.click(
				within( dialog ).getByRole( 'button', {
					name: 'Refund transaction',
				} )
			);
		} );

		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledWith( {
				chargeId: 'ch_refund',
				amount: 5000,
				reason: null,
				orderId: 123,
			} )
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Refunded payment #pi_refund.'
		);
		expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 2 );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Refund transaction' } )
			).not.toBeInTheDocument()
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'Payment details' } )
			).toHaveFocus()
		);
	} );

	it( 'keeps partial refund navigation available when a full refund is no longer available', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_partial_refund',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_partial_refund',
				payment_intent: 'pi_partial_refund',
				balance_transaction: 'txn_partial_refund',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 1000,
				refunded: false,
				order: {
					id: 123,
					number: '123',
					url: 'http://example.com/wp-admin/post.php?post=123&action=edit',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_partial_refund&transaction_id=txn_partial_refund',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const refundActionsButton = await screen.findByRole( 'button', {
			name: 'Transaction actions',
		} );
		await act( async () => {
			await userEvent.click( refundActionsButton );
		} );

		expect(
			screen.queryByRole( 'menuitem', { name: 'Refund in full' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'menuitem', { name: 'Partial refund' } )
		).toBeInTheDocument();
	} );

	it( 'does not offer transaction detail refunds when the charge is not order-backed', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_no_order',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_no_order',
				payment_intent: 'pi_no_order',
				balance_transaction: 'txn_no_order',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_no_order&transaction_id=txn_no_order',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Payment details' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Transaction actions' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByText(
				'This payment is not linked to a WooCommerce order.'
			)
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Transaction details loaded. This payment is not linked to a WooCommerce order.'
		);
	} );

	it( 'shows a payment detail test-mode notice for connected test accounts', async () => {
		mockGetAccountSettings.mockResolvedValue( {
			account: {
				id: 'acct_test',
				mode: 'test',
				default_currency: 'usd',
				connected: true,
				working: true,
				can_process_payments: true,
				test_mode: true,
				test_drive: true,
				sandbox: false,
				live: false,
			},
			urls: {},
		} );
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test_mode',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_test_mode',
				payment_intent: 'pi_test_mode',
				balance_transaction: 'txn_test_mode',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test_mode&transaction_id=txn_test_mode',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const notice = (
			await screen.findByText( 'Viewing test payments.' )
		 ).closest( '.components-notice' ) as HTMLElement;
		expect( notice ).toBeInTheDocument();
		expect(
			within( notice ).getByText(
				/Your WooPayments account is currently in test mode./
			)
		).toBeInTheDocument();
		expect(
			within( notice ).getByRole( 'link', {
				name: 'WooPayments settings',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings'
		);
	} );

	it( 'derives refund order ids from native order URLs when the detail payload omits the order id', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_order_url',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_order_url',
				payment_intent: 'pi_order_url',
				balance_transaction: 'txn_order_url',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
				order: {
					number: '123',
					url: 'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_order_url&transaction_id=txn_order_url',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const refundActionsButton = await screen.findByRole( 'button', {
			name: 'Transaction actions',
		} );
		await act( async () => {
			await userEvent.click( refundActionsButton );
		} );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			);
		} );
		await act( async () => {
			await userEvent.click(
				await screen.findByLabelText( 'Requested by customer' )
			);
		} );
		const refundButton = await screen.findByRole( 'button', {
			name: 'Refund transaction',
		} );
		await act( async () => {
			await userEvent.click( refundButton );
		} );

		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledWith(
				expect.objectContaining( {
					orderId: 123,
					reason: 'requested_by_customer',
				} )
			)
		);
	} );

	it( 'warns when a full refund will close an open inquiry', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_inquiry_refund',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_inquiry_refund',
				payment_intent: 'pi_inquiry_refund',
				balance_transaction: 'txn_inquiry_refund',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
				order: {
					id: 123,
					number: '123',
					url: 'http://example.com/wp-admin/post.php?post=123&action=edit',
				},
				dispute: {
					id: 'du_inquiry',
					status: 'warning_needs_response',
					reason: 'fraudulent',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_inquiry_refund&transaction_id=txn_inquiry_refund',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const refundActionsButton = await screen.findByRole( 'button', {
			name: 'Transaction actions',
		} );
		await act( async () => {
			await userEvent.click( refundActionsButton );
		} );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			);
		} );

		expect(
			await screen.findByText(
				'Issuing a refund will close the inquiry, returning the amount in question back to the cardholder. No additional fees apply.'
			)
		).toBeInTheDocument();
	} );

	it( 'keeps the refund modal open and dispatches an error notice when refunding fails', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_refund_error',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_refund_error',
				payment_intent: 'pi_refund_error',
				balance_transaction: 'txn_refund_error',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
				order: {
					id: 123,
					number: '123',
					url: 'http://example.com/wp-admin/post.php?post=123&action=edit',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockRejectedValue( new Error( 'Gateway failed.' ) );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_refund_error&transaction_id=txn_refund_error',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const refundActionsButton = await screen.findByRole( 'button', {
			name: 'Transaction actions',
		} );
		await act( async () => {
			await userEvent.click( refundActionsButton );
		} );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			);
		} );
		const refundButton = await screen.findByRole( 'button', {
			name: 'Refund transaction',
		} );
		await act( async () => {
			await userEvent.click( refundButton );
		} );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'There has been an error refunding the payment #pi_refund_error. Please try again later. Gateway failed.'
			)
		);
		expect(
			screen.getByRole( 'dialog', { name: 'Refund transaction' } )
		).toBeInTheDocument();
		expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'captures an uncaptured authorization from transaction details and reloads the detail data', async () => {
		mockGetPaymentIntent
			.mockResolvedValueOnce( {
				id: 'pi_auth',
				status: 'requires_capture',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_auth',
					balance_transaction: 'txn_auth',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					payment_intent: 'pi_auth',
					status: 'succeeded',
					captured: false,
					amount_refunded: 0,
					order: {
						id: 123,
						number: '123',
					},
				},
			} )
			.mockResolvedValueOnce( {
				id: 'pi_auth',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_auth',
					balance_transaction: 'txn_auth',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					payment_intent: 'pi_auth',
					captured: true,
					amount_refunded: 0,
					order: {
						id: 123,
						number: '123',
					},
				},
			} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_auth',
			order_id: 123,
			captured: false,
			created: '2026-06-12T10:30:00Z',
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockResolvedValue( {
			id: 'pi_auth',
			status: 'succeeded',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const captureButton = await screen.findByRole( 'button', {
			name: 'Capture authorization for order #123',
		} );
		const summary = screen
			.getByRole( 'heading', { name: 'Summary' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Authorized' )
		).toBeInTheDocument();
		expect(
			within( summary ).queryByText( 'Succeeded' )
		).not.toBeInTheDocument();

		await act( async () => {
			await userEvent.click( captureButton );
		} );

		await waitFor( () =>
			expect( mockCaptureAuthorization ).toHaveBeenCalledWith(
				123,
				'pi_auth'
			)
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Payment for order #123 captured successfully.'
		);
		expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 2 );
		expect( mockGetAuthorization ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.queryByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		).not.toBeInTheDocument();
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'Payment details' } )
			).toHaveFocus()
		);
	} );

	it( 'keeps transaction detail capture pending while the authorization request is running', async () => {
		let resolveCapture: ( value: unknown ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve ) => {
			resolveCapture = resolve;
		} );

		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_auth',
			status: 'requires_capture',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_auth',
				balance_transaction: 'txn_auth',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_auth',
				captured: false,
				amount_refunded: 0,
				order: {
					id: 123,
					number: '123',
				},
			},
		} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_auth',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		);

		const pendingCaptureButton = await screen.findByRole( 'button', {
			name: 'Capturing authorization for order #123',
		} );
		expect( pendingCaptureButton ).not.toBeDisabled();
		expect( pendingCaptureButton ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		expect( pendingCaptureButton ).toHaveFocus();

		await act( async () => {
			resolveCapture( {
				id: 'pi_auth',
				status: 'succeeded',
			} );
			await capturePromise;
		} );
	} );

	it( 'does not overwrite a newer transaction detail route after a pending authorization action completes', async () => {
		let resolveCapture: ( value: unknown ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve ) => {
			resolveCapture = resolve;
		} );

		mockGetPaymentIntent
			.mockResolvedValueOnce( {
				id: 'pi_auth',
				status: 'requires_capture',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_auth',
					balance_transaction: 'txn_auth',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					payment_intent: 'pi_auth',
					captured: false,
					amount_refunded: 0,
					order: {
						id: 123,
						number: '123',
					},
				},
			} )
			.mockResolvedValueOnce( {
				id: 'pi_other',
				status: 'succeeded',
				amount: 9900,
				currency: 'usd',
				created: 1781712100,
				charge: {
					id: 'ch_other',
					balance_transaction: 'txn_other',
					type: 'charge',
					amount: 9900,
					currency: 'usd',
					created: 1781712100,
					payment_intent: 'pi_other',
					captured: true,
					order: {
						id: 456,
						number: '456',
					},
				},
			} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_auth',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
				] }
			>
				<RouteChangeButton to="/woopayments/transactions/details?id=pi_other&transaction_id=txn_other" />
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Load another transaction' } )
		);

		expect( await screen.findByText( 'pi_other' ) ).toBeInTheDocument();

		await act( async () => {
			resolveCapture( {
				id: 'pi_auth',
				status: 'succeeded',
			} );
			await capturePromise;
			await Promise.resolve();
		} );

		expect( screen.getByText( 'pi_other' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'pi_auth' ) ).not.toBeInTheDocument();
		expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'surfaces authorization action failures after navigating to another detail route', async () => {
		let rejectCapture: ( error: Error ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve, reject ) => {
			rejectCapture = reject;
		} );

		mockGetPaymentIntent
			.mockResolvedValueOnce( {
				id: 'pi_auth',
				status: 'requires_capture',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_auth',
					balance_transaction: 'txn_auth',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					payment_intent: 'pi_auth',
					captured: false,
					amount_refunded: 0,
					order: {
						id: 123,
						number: '123',
					},
				},
			} )
			.mockResolvedValueOnce( {
				id: 'pi_other',
				status: 'succeeded',
				amount: 9900,
				currency: 'usd',
				created: 1781712100,
				charge: {
					id: 'ch_other',
					balance_transaction: 'txn_other',
					type: 'charge',
					amount: 9900,
					currency: 'usd',
					created: 1781712100,
					payment_intent: 'pi_other',
					captured: true,
					order: {
						id: 456,
						number: '456',
					},
				},
			} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_auth',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
				] }
			>
				<RouteChangeButton to="/woopayments/transactions/details?id=pi_other&transaction_id=txn_other" />
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Load another transaction' } )
		);
		expect( await screen.findByText( 'pi_other' ) ).toBeInTheDocument();

		await act( async () => {
			rejectCapture( new Error( 'Authorization already captured.' ) );
			await capturePromise.catch( () => undefined );
		} );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Unable to capture authorization for order #123. Authorization already captured.'
			)
		);
		expect( screen.getByText( 'pi_other' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'pi_auth' ) ).not.toBeInTheDocument();
	} );

	it( 'does not steal focus after authorization action when focus moved while pending', async () => {
		let resolveCapture: ( value: unknown ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve ) => {
			resolveCapture = resolve;
		} );

		mockGetPaymentIntent
			.mockResolvedValueOnce( {
				id: 'pi_auth',
				status: 'requires_capture',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_auth',
					balance_transaction: 'txn_auth',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					payment_intent: 'pi_auth',
					captured: false,
					amount_refunded: 0,
					order: {
						id: 123,
						number: '123',
					},
				},
			} )
			.mockResolvedValueOnce( {
				id: 'pi_auth',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_auth',
					balance_transaction: 'txn_auth',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					payment_intent: 'pi_auth',
					captured: true,
					amount_refunded: 0,
					order: {
						id: 123,
						number: '123',
					},
				},
			} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_auth',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		render(
			<>
				<button type="button">Outside focus target</button>
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			</>
		);

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		);
		const outsideFocusTarget = screen.getByRole( 'button', {
			name: 'Outside focus target',
		} );
		outsideFocusTarget.focus();
		expect( outsideFocusTarget ).toHaveFocus();

		await act( async () => {
			resolveCapture( {
				id: 'pi_auth',
				status: 'succeeded',
			} );
			await capturePromise;
		} );

		await waitFor( () =>
			expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
				'Payment for order #123 captured successfully.'
			)
		);
		expect( outsideFocusTarget ).toHaveFocus();
	} );

	it( 'surfaces transaction detail authorization action failures', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_auth',
			status: 'requires_capture',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_auth',
				balance_transaction: 'txn_auth',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_auth',
				captured: false,
				amount_refunded: 0,
				order: {
					id: 123,
					number: '123',
				},
			},
		} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_auth',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockRejectedValue(
			new Error( 'Authorization already captured.' )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const captureButton = await screen.findByRole( 'button', {
			name: 'Capture authorization for order #123',
		} );

		await act( async () => {
			await userEvent.click( captureButton );
			await Promise.resolve();
		} );

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', {
					name: 'Capture authorization for order #123',
				} )
			).toBeEnabled()
		);
		expect(
			screen.getByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		).toHaveFocus();

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Unable to capture authorization for order #123. Authorization already captured.'
			)
		);
	} );

	it( 'surfaces authorization detail load failures for otherwise capturable transactions', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_auth',
			status: 'requires_capture',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_auth',
				balance_transaction: 'txn_auth',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_auth',
				captured: false,
				amount_refunded: 0,
				order: {
					id: 123,
					number: '123',
				},
			},
		} );
		mockGetAuthorization.mockRejectedValue( new Error( 'Auth API down.' ) );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( 'Auth API down.', {
				selector: '.woocommerce-woopayments-money-movement__status',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Capture authorization for order #123',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'approves a fraud-review transaction from transaction details', async () => {
		let resolveCapture: ( value: unknown ) => void = () => undefined;
		const capturePromise = new Promise( ( resolve ) => {
			resolveCapture = resolve;
		} );

		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_review',
			status: 'requires_capture',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_review',
				balance_transaction: 'txn_review',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_review',
				captured: false,
				amount_refunded: 0,
				order: {
					id: 123,
					number: '123',
					fraud_meta_box_type: 'review',
				},
			},
		} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_review',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCaptureAuthorization.mockReturnValueOnce(
			capturePromise as Promise< never >
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_review&transaction_id=txn_review',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'button', { name: 'Block transaction' } )
		).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Approve transaction' } )
		);

		await waitFor( () =>
			expect( mockCaptureAuthorization ).toHaveBeenCalledWith(
				123,
				'pi_review'
			)
		);
		const pendingApproveButton = await screen.findByRole( 'button', {
			name: 'Approving transaction for order #123',
		} );
		expect( pendingApproveButton ).not.toBeDisabled();
		expect( pendingApproveButton ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		expect( pendingApproveButton ).toHaveFocus();

		await act( async () => {
			resolveCapture( {
				id: 'pi_review',
				status: 'succeeded',
			} );
			await capturePromise;
		} );

		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Payment for order #123 captured successfully.'
		);
	} );

	it( 'blocks a fraud-review transaction from transaction details', async () => {
		let resolveCancel: ( value: unknown ) => void = () => undefined;
		const cancelPromise = new Promise( ( resolve ) => {
			resolveCancel = resolve;
		} );

		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_review',
			status: 'requires_capture',
			charge: {
				id: 'ch_review',
				balance_transaction: 'txn_review',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_review',
				captured: false,
				amount_refunded: 0,
				order: {
					id: 123,
					number: '123',
					fraud_meta_box_type: 'review',
				},
			},
		} );
		mockGetAuthorization.mockResolvedValue( {
			payment_intent_id: 'pi_review',
			order_id: 123,
			captured: false,
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCancelAuthorization.mockReturnValueOnce(
			cancelPromise as Promise< never >
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_review&transaction_id=txn_review',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Block transaction' } )
		);

		await waitFor( () =>
			expect( mockCancelAuthorization ).toHaveBeenCalledWith(
				123,
				'pi_review'
			)
		);
		const pendingBlockButton = await screen.findByRole( 'button', {
			name: 'Blocking transaction for order #123',
		} );
		expect( pendingBlockButton ).not.toBeDisabled();
		expect( pendingBlockButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( pendingBlockButton ).toHaveFocus();

		await act( async () => {
			resolveCancel( {
				id: 'pi_review',
				status: 'canceled',
			} );
			await cancelPromise;
		} );

		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Payment for order #123 canceled successfully.'
		);
	} );

	it( 'renders awaiting-response dispute decisions from transaction details', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_test',
				balance_transaction: {
					id: 'txn_test',
					fee: 180,
					net: 4820,
				},
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				billing_details: {
					email: 'ada@example.com',
					name: 'Ada Lovelace',
				},
				payment_method_details: {
					type: 'card',
					card: {
						brand: 'visa',
						last4: '4242',
					},
				},
				dispute: {
					id: 'dp_test',
					status: 'needs_response',
					reason: 'fraudulent',
					evidence_details: {
						due_by: 1781913600,
					},
					amount: 5000,
					currency: 'usd',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Dispute details' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Response needed' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Challenge dispute' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fchallenge&id=dp_test'
		);
		expect(
			screen.getByRole( 'button', { name: 'Accept dispute' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: 'Learn more about responding to disputes',
			} )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#responding'
		);
	} );

	it( 'accepts a dispute from the transaction detail decision layer', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status: 'needs_response',
					reason: 'fraudulent',
					amount: 5000,
					currency: 'usd',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCloseDispute.mockResolvedValue( {
			id: 'dp_test',
			status: 'lost',
			reason: 'fraudulent',
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const acceptButton = await screen.findByRole( 'button', {
			name: 'Accept dispute',
		} );

		await act( async () => {
			await userEvent.click( acceptButton );
		} );
		expect(
			screen.getByRole( 'heading', { name: 'Accept the dispute?' } )
		).toBeInTheDocument();
		const acceptDialog = screen.getByRole( 'dialog' );

		await act( async () => {
			await userEvent.click(
				within( acceptDialog ).getByRole( 'button', {
					name: 'Accept dispute',
				} )
			);
		} );

		await waitFor( () =>
			expect( mockCloseDispute ).toHaveBeenCalledWith( 'dp_test' )
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Dispute accepted.'
		);
		expect( await screen.findByText( 'Lost' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Dispute details' } )
		).toHaveFocus();
	} );

	it( 'does not steal focus after accepting a dispute when the modal was dismissed while pending', async () => {
		let resolveCloseDispute: ( value: {
			id: string;
			status: string;
			reason: string;
		} ) => void = () => {};
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status: 'needs_response',
					reason: 'fraudulent',
					amount: 5000,
					currency: 'usd',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCloseDispute.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveCloseDispute = resolve;
			} )
		);

		render(
			<>
				<button type="button">Outside focus target</button>
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			</>
		);

		const acceptButton = await screen.findByRole( 'button', {
			name: 'Accept dispute',
		} );

		await act( async () => {
			await userEvent.click( acceptButton );
		} );
		const acceptDialog = screen.getByRole( 'dialog' );
		await act( async () => {
			await userEvent.click(
				within( acceptDialog ).getByRole( 'button', {
					name: 'Accept dispute',
				} )
			);
		} );
		await waitFor( () =>
			expect( mockCloseDispute ).toHaveBeenCalledWith( 'dp_test' )
		);

		await act( async () => {
			await userEvent.click(
				within( acceptDialog ).getByRole( 'button', {
					name: 'Cancel',
				} )
			);
		} );
		const outsideFocusTarget = screen.getByRole( 'button', {
			name: 'Outside focus target',
		} );
		outsideFocusTarget.focus();
		expect( outsideFocusTarget ).toHaveFocus();

		await act( async () => {
			resolveCloseDispute( {
				id: 'dp_test',
				status: 'lost',
				reason: 'fraudulent',
			} );
			await Promise.resolve();
		} );

		expect( await screen.findByText( 'Lost' ) ).toBeInTheDocument();
		expect( outsideFocusTarget ).toHaveFocus();
	} );

	it( 'restores focus after accepting a dispute when the pending modal dismiss leaves focus unstable', async () => {
		let resolveCloseDispute: ( value: {
			id: string;
			status: string;
			reason: string;
		} ) => void = () => {};
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status: 'needs_response',
					reason: 'fraudulent',
					amount: 5000,
					currency: 'usd',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCloseDispute.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveCloseDispute = resolve;
			} )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const acceptButton = await screen.findByRole( 'button', {
			name: 'Accept dispute',
		} );

		await act( async () => {
			await userEvent.click( acceptButton );
		} );
		const acceptDialog = screen.getByRole( 'dialog' );
		await act( async () => {
			await userEvent.click(
				within( acceptDialog ).getByRole( 'button', {
					name: 'Accept dispute',
				} )
			);
		} );
		await waitFor( () =>
			expect( mockCloseDispute ).toHaveBeenCalledWith( 'dp_test' )
		);

		await act( async () => {
			await userEvent.click(
				within( acceptDialog ).getByRole( 'button', {
					name: 'Cancel',
				} )
			);
		} );

		await act( async () => {
			resolveCloseDispute( {
				id: 'dp_test',
				status: 'lost',
				reason: 'fraudulent',
			} );
			await Promise.resolve();
		} );

		expect( await screen.findByText( 'Lost' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Dispute details' } )
		).toHaveFocus();
	} );

	it( 'surfaces dispute accept failures from transaction details', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status: 'needs_response',
					reason: 'fraudulent',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockCloseDispute.mockRejectedValue( new Error( 'Close failed' ) );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const acceptButton = await screen.findByRole( 'button', {
			name: 'Accept dispute',
		} );

		await act( async () => {
			await userEvent.click( acceptButton );
		} );
		const acceptDialog = screen.getByRole( 'dialog' );
		await act( async () => {
			await userEvent.click(
				within( acceptDialog ).getByRole( 'button', {
					name: 'Accept dispute',
				} )
			);
		} );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Close failed'
			)
		);
	} );

	it( 'shows inquiry refund guidance without issuing a refund inline', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status: 'warning_needs_response',
					reason: 'product_not_received',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByRole( 'link', { name: 'Submit evidence' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Issue refund' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByRole( 'button', { name: 'Issue refund' } )
		).toHaveAccessibleDescription(
			'Issue the refund from the full refund flow before responding to this inquiry.'
		);
	} );

	it( 'renders resolved dispute guidance and submitted evidence links', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status: 'under_review',
					reason: 'fraudulent',
					metadata: {
						__evidence_submitted_at: '1781712200',
					},
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText(
				"The customer's bank is reviewing your submitted evidence. This process can take more than 60 days."
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'View submitted evidence' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fchallenge&id=dp_test'
		);
	} );

	it.each( [
		[
			'won',
			'You won this dispute. The disputed amount and dispute fee have been returned to your account.',
		],
		[
			'lost',
			'This dispute was lost. The disputed amount and dispute fee have been deducted from your account.',
		],
	] )( 'renders %s dispute outcome guidance', async ( status, message ) => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_test',
				dispute: {
					id: 'dp_test',
					status,
					reason: 'fraudulent',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByText( message ) ).toBeInTheDocument();
	} );

	it( 'renders reference-shaped timeline event details with datetime values', async () => {
		const eventDatetime = 1781712200;
		const expectedEventDate = new Date(
			eventDatetime * 1000
		).toLocaleDateString( undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
		} );

		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: { id: 'txn_test' },
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
			},
		} );
		mockGetTimeline.mockResolvedValue( {
			data: [
				{
					type: 'captured',
					datetime: eventDatetime,
					amount: 5000,
					currency: 'usd',
					fee: 180,
					net: 4820,
				},
				{
					type: 'partial_refund',
					datetime: eventDatetime,
					amount: 1000,
					currency: 'usd',
					reason: 'requested_by_customer',
					acquirer_reference_number: 'arn_refund_123',
				},
				{
					type: 'dispute.created',
					datetime: eventDatetime,
					amount: 1500,
					currency: 'usd',
				},
				{
					type: 'fraud_outcome_manual_approve',
					datetime: eventDatetime,
					user: {
						username: 'admin',
					},
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( 'Payment status changed to Paid.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'A payment of $50.00 was successfully charged.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Fee: $1.80' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Net: $48.20' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'A payment of $10.00 was successfully refunded.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Reason: Requested by customer' )
		).toBeInTheDocument();
		expect( screen.getByText( 'ARN: arn_refund_123' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'A dispute was opened for $15.00.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Payment was approved by admin' )
		).toBeInTheDocument();
		expect(
			screen.getAllByText( expectedEventDate ).length
		).toBeGreaterThan( 0 );
		expect( mockGetTimeline ).toHaveBeenCalledWith( 'pi_test' );
	} );

	it( 'announces timeline errors through the stable transaction detail status region', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: { id: 'txn_test' },
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
			},
		} );
		mockGetTimeline.mockRejectedValue(
			new Error( 'Timeline provider failed.' )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test&transaction_id=txn_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const statusRegion = screen.getByRole( 'status' );
		expect( statusRegion ).toHaveTextContent(
			'Loading transaction details…'
		);
		expect( await screen.findByText( 'pi_test' ) ).toBeInTheDocument();
		expect( screen.getByText( 'txn_test' ) ).toBeInTheDocument();
		await waitFor( () =>
			expect( statusRegion ).toHaveTextContent(
				'Timeline provider failed.'
			)
		);
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );

	it( 'loads charge details when the route id is a charge fallback', async () => {
		mockGetCharge.mockResolvedValue( {
			id: 'ch_test',
			payment_intent: 'pi_test',
			balance_transaction: 'txn_test',
			type: 'charge',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
		} );
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			charge: {
				id: 'ch_test',
				balance_transaction: 'txn_test',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
			},
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=ch_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect( await screen.findByText( 'txn_test' ) ).toBeInTheDocument();
		expect( mockGetCharge ).toHaveBeenCalledWith( 'ch_test' );
		expect( mockGetPaymentIntent ).toHaveBeenCalledWith( 'pi_test' );
		expect( mockGetTransaction ).not.toHaveBeenCalled();
	} );
} );
