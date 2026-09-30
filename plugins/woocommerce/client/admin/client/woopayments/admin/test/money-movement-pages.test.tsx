/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';
import { dateI18n, getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { setSiteDateFormats } from './helpers/site-date-formats';
import { summaryItem } from './helpers/table-summary';
import { WooPaymentsDisputesPage } from '../money-movement/disputes-page';
import { WooPaymentsPaymentSummarySection } from '../money-movement/transaction-detail-sections';
import { WooPaymentsTransactionTimeline } from '../money-movement/transaction-timeline';
import { WooPaymentsTransactionDetailsPage } from '../money-movement/transaction-details-page';
import { WooPaymentsTransactionsPage } from '../money-movement/transactions-page';
import type { WooPaymentsTimelineEvent } from '../money-movement/types';
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
import {
	getTestModeNoticeText,
	mockAccountMode,
} from './helpers/test-mode-account';
import {
	mockUpdateUserPreferences,
	setMockUserPreferences,
} from './helpers/user-preferences';

// `ExternalLink` adds "(opens in a new tab)" to the accessible name; match the visible label.
const linkNamed = ( label: string ) => ( accessibleName: string ) =>
	accessibleName.startsWith( label );

// Headlines of core `Timeline` items, newest first.
const getTimelineHeadlines = () =>
	Array.from(
		document.querySelectorAll(
			'.woocommerce-timeline-item__headline > span'
		)
	).map( ( headline ) => headline.textContent );

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockHistoryPush = jest.fn();
const mockRecordEvent = recordEvent as jest.MockedFunction<
	typeof recordEvent
>;
let mockHistoryNavigate: ( ( to: string ) => void ) | null = null;

beforeAll( setSiteDateFormats );

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () => ( {
		push: mockHistoryPush,
	} ),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
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

jest.mock( '../money-movement/transaction-search', () => ( {
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
} ) );

// Covered by blocked-transactions.test.tsx; its Search import needs the real navigation module.
jest.mock( '../money-movement/blocked-transactions', () => ( {
	WooPaymentsBlockedTransactions: () => null,
} ) );

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
	let storageSpies: jest.SpyInstance[];

	beforeEach( () => {
		anchorClickSpy = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation();
		storageSpies = [
			jest.spyOn( Storage.prototype, 'getItem' ),
			jest.spyOn( Storage.prototype, 'setItem' ),
		];
		setMockUserPreferences( {} );
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
		mockAccountMode( false );
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
		mockRecordEvent.mockReset();
		mockHistoryPush.mockReset();
		mockHistoryPush.mockImplementation(
			( to: string ) => mockHistoryNavigate?.( to )
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
		// Source: client 11.1.0 utils/charge/index.ts:168-169 and payment-status-chip/mappings.ts:45-52;
		// charge fields from the recorded Fixtures/rec-t3-manual-capture.json entries.
		[
			'captured',
			{
				status: 'succeeded',
				amount: 5000,
				amount_refunded: 0,
				refunded: false,
				captured: true,
				currency: 'usd',
			},
			'Paid',
		],
		[
			'uncaptured',
			{
				status: 'succeeded',
				amount: 5000,
				amount_refunded: 0,
				refunded: false,
				captured: false,
				currency: 'usd',
			},
			'Payment authorized',
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
		// Source: client 11.1.0 utils/charge/index.ts:52-71, 146-151 and payment-status-chip/mappings.ts:57-64;
		// the issuer_declined outcome type is recorded in Fixtures/rec-1-intention-declines.json.
		[
			'failed',
			{
				status: 'failed',
				amount: 5000,
				captured: false,
				currency: 'usd',
				outcome: { type: 'issuer_declined' },
			},
			'Payment failed',
		],
		[
			'failed and blocked by Radar',
			{
				status: 'failed',
				amount: 5000,
				captured: false,
				currency: 'usd',
				outcome: { type: 'blocked' },
			},
			'Payment blocked',
		],
	] )(
		'derives the %s payment summary status with oracle precedence',
		( _state, transaction, expectedStatus ) => {
			render(
				<WooPaymentsPaymentSummarySection transaction={ transaction } />
			);

			const summary = screen.getByRole( 'region', {
				name: 'Summary',
			} ) as HTMLElement;
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Custom brand ending in 4242' )
		).toBeInTheDocument();
		expect( summary.querySelector( 'img' ) ).toBeNull();
	} );

	it.each( [
		[ 'a zero amount', { amount: 0 }, { id: 'du_incomplete' }, {} ],
		[
			'a missing amount',
			{ amount: undefined },
			{ id: 'du_incomplete' },
			{ amount: undefined },
		],
		[
			'a nonfinite amount',
			{ amount: Number.POSITIVE_INFINITY },
			{ id: 'du_incomplete' },
			{},
		],
		[ 'a missing charge ID', { id: '' }, { id: 'du_incomplete' }, {} ],
		[ 'a missing inquiry ID', {}, {}, {} ],
	] )(
		'keeps inquiries with %s unavailable from the primary refund action',
		async (
			_label,
			chargeOverrides,
			disputeOverrides,
			intentOverrides
		) => {
			mockGetPaymentIntent.mockResolvedValue( {
				id: 'pi_incomplete_inquiry',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				...intentOverrides,
				charge: {
					id: 'ch_incomplete_inquiry',
					payment_intent: 'pi_incomplete_inquiry',
					balance_transaction: 'txn_incomplete_inquiry',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					captured: true,
					amount_refunded: 0,
					refunded: false,
					order: { id: 123, number: '123' },
					...chargeOverrides,
					dispute: {
						status: 'warning_needs_response',
						reason: 'fraudulent',
						...disputeOverrides,
					},
				},
			} );
			mockGetTimeline.mockResolvedValue( { data: [] } );

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_incomplete_inquiry&transaction_id=txn_incomplete_inquiry',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			);

			const issueRefundButton = await screen.findByRole( 'button', {
				name: 'Issue refund',
			} );
			expect( issueRefundButton ).toHaveAttribute(
				'aria-disabled',
				'true'
			);
			expect( issueRefundButton ).toHaveAccessibleDescription(
				'A full refund is not available for this transaction.'
			);
			await userEvent.click( issueRefundButton );
			expect(
				screen.queryByRole( 'dialog', { name: 'Refund transaction' } )
			).not.toBeInTheDocument();
			expect( mockRecordEvent ).not.toHaveBeenCalled();
			expect( mockRefundCharge ).not.toHaveBeenCalled();
		}
	);

	// Client 11.1.0 `payment-details/summary/index.tsx:870-878` passes `onIssueRefund` to every dispute
	// pane regardless of the order, and `dispute-awaiting-response-details.tsx:429-431` opens the refund
	// modal for an inquiry; `data/payment-intents/actions.ts:52-62` then sends no order id.
	it( 'offers the inquiry refund when the charge has no order ID', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_incomplete_inquiry',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_incomplete_inquiry',
				payment_intent: 'pi_incomplete_inquiry',
				balance_transaction: 'txn_incomplete_inquiry',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				captured: true,
				amount_refunded: 0,
				refunded: false,
				order: {},
				dispute: {
					id: 'du_incomplete',
					status: 'warning_needs_response',
					reason: 'fraudulent',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockResolvedValue( { id: 're_incomplete_inquiry' } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_incomplete_inquiry&transaction_id=txn_incomplete_inquiry',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const issueRefundButton = await screen.findByRole( 'button', {
			name: 'Issue refund',
		} );
		expect( issueRefundButton ).not.toHaveAttribute(
			'aria-disabled',
			'true'
		);
		await userEvent.click( issueRefundButton );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );
		expect(
			within( dialog ).getByText(
				'Issuing a refund will close the inquiry, returning the amount in question back to the cardholder. No additional fees apply.'
			)
		).toBeInTheDocument();
		await userEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Refund transaction',
			} )
		);

		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledWith( {
				chargeId: 'ch_incomplete_inquiry',
				amount: 5000,
				reason: null,
				orderId: undefined,
			} )
		);
	} );

	it( 'keeps an inquiry without a payment intent unavailable from the primary refund action', async () => {
		mockGetCharge.mockResolvedValue( {
			id: 'ch_missing_payment_intent',
			balance_transaction: 'txn_missing_payment_intent',
			type: 'charge',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			captured: true,
			amount_refunded: 0,
			refunded: false,
			order: { id: 123, number: '123' },
			dispute: {
				id: 'du_missing_payment_intent',
				status: 'warning_needs_response',
				reason: 'fraudulent',
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=ch_missing_payment_intent&transaction_id=txn_missing_payment_intent',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const issueRefundButton = await screen.findByRole( 'button', {
			name: 'Issue refund',
		} );
		expect( issueRefundButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( issueRefundButton ).toHaveAccessibleDescription(
			'A full refund is not available for this transaction.'
		);
		await userEvent.click( issueRefundButton );
		expect(
			screen.queryByRole( 'dialog', { name: 'Refund transaction' } )
		).not.toBeInTheDocument();
		expect( mockRecordEvent ).not.toHaveBeenCalled();
		expect( mockRefundCharge ).not.toHaveBeenCalled();
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( '$55.32 USD', {
				selector:
					'.woocommerce-woopayments-payment-summary__settlement-currency',
			} )
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( '$55.32 USD', {
				selector:
					'.woocommerce-woopayments-payment-summary__settlement-currency',
			} )
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

	it( 'labels a zero-fee dispute withdrawal as deducted', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					amount_refunded: 2000,
					currency: 'usd',
					dispute: {
						status: 'needs_response',
						balance_transactions: [ { amount: -2000, fee: 0 } ],
					},
				} }
			/>
		);

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Deducted: -$20.00' )
		).toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Refunded:/ )
		).not.toBeInTheDocument();
	} );

	it( 'keeps an inquiry customer refund labelled as refunded', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					amount_refunded: 1000,
					currency: 'usd',
					dispute: {
						status: 'warning_needs_response',
					},
				} }
			/>
		);

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Refunded: -$10.00' )
		).toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Deducted:/ )
		).not.toBeInTheDocument();
	} );

	it( 'keeps a reversed dispute customer refund labelled as refunded', () => {
		render(
			<WooPaymentsPaymentSummarySection
				transaction={ {
					status: 'succeeded',
					amount: 5000,
					amount_refunded: 1000,
					currency: 'usd',
					dispute: {
						status: 'won',
						balance_transactions: [
							{ amount: -2000, fee: 1500 },
							{ amount: 2000, fee: -1500 },
						],
					},
				} }
			/>
		);

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Refunded: -$10.00' )
		).toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Deducted:/ )
		).not.toBeInTheDocument();
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			summary.querySelector(
				'.woocommerce-woopayments-payment-summary__settlement-currency'
			)
		).toBeNull();
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			summary.querySelector(
				'.woocommerce-woopayments-payment-summary__settlement-currency'
			)
		).toBeNull();
		expect(
			within( summary ).getByText( 'Fees: -€1.80' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: €48.20' )
		).toBeInTheDocument();
	} );

	afterEach( () => {
		// Hidden columns live in user meta only, never in browser storage.
		const storageCalls = storageSpies.reduce(
			( total, spy ) => total + spy.mock.calls.length,
			0
		);
		storageSpies.forEach( ( spy ) => spy.mockRestore() );
		mockHistoryNavigate = null;
		anchorClickSpy.mockRestore();
		jest.useRealTimers();
		// Throw only after cleanup so one failure cannot leak into the next test.
		if ( storageCalls > 0 ) {
			throw new Error(
				`Hidden columns touched browser storage ${ storageCalls } time(s).`
			);
		}
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
		mockGetTransactionsSummary.mockResolvedValue( { count: 0, total: 0 } );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 26,
			total: 26000,
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( summaryItem( '0 transactions' ) )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'tab', { name: 'Uncaptured (26)' } )
		).toBeInTheDocument();
		expect( mockGetAuthorizationsSummary ).toHaveBeenCalledWith( {} );
	} );

	it.each( [
		[ 'hides', false, { count: 0, total: 0 }, false ],
		[ 'shows', true, { count: 0, total: 0 }, true ],
		[ 'shows', false, { count: 1, total: 1000 }, true ],
	] )(
		// Client 11.1.0 `transactions/index.tsx:63-72`.
		'%s the Uncaptured tab with manual capture %p and summary %p',
		async ( _verb, isManualCaptureEnabled, authorizations, isShown ) => {
			window.wcSettings = {
				...window.wcSettings,
				admin: { woopaymentsSettings: { isManualCaptureEnabled } },
			} as typeof window.wcSettings;
			mockGetTransactions.mockResolvedValue( {
				data: [],
				total_count: 0,
			} );
			mockGetTransactionsSummary.mockResolvedValue( {
				count: 0,
				total: 0,
			} );
			mockGetAuthorizationsSummary.mockResolvedValue( authorizations );

			render(
				<MemoryRouter
					initialEntries={ [ '/woopayments/transactions' ] }
				>
					<WooPaymentsTransactionsPage />
				</MemoryRouter>
			);

			await screen.findByText( summaryItem( '0 transactions' ) );
			await waitFor( () =>
				expect( mockGetAuthorizationsSummary ).toHaveBeenCalled()
			);
			expect(
				screen
					.getAllByRole( 'tab' )
					.map(
						( tab ) => tab.textContent?.replace( / \(.*\)$/, '' )
					)
			).toEqual(
				isShown
					? [ 'Transactions', 'Uncaptured', 'Blocked' ]
					: [ 'Transactions', 'Blocked' ]
			);
		}
	);

	it( 'keeps transactions usable when the uncaptured count fails', async () => {
		window.wcSettings = {
			...window.wcSettings,
			admin: { woopaymentsSettings: { isManualCaptureEnabled: true } },
		} as typeof window.wcSettings;
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0, total: 0 } );
		mockGetAuthorizationsSummary.mockRejectedValue(
			new Error( 'Authorization summary unavailable.' )
		);

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( summaryItem( '0 transactions' ) )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'Uncaptured (…)' } )
		).toBeInTheDocument();
	} );

	it.each( [
		[
			'summary count when the list omits its total',
			{ data: [] },
			{ count: 641, total: 0 },
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
			{ count: 641, total: 0 },
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

			await screen.findByText( summaryItem( '641 transactions' ) );
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
			'date,type,channel,amount,fees,net,order,source,customer_name,deposit'
		);

		firstRender.unmount();
		setMockUserPreferences( {
			wc_payments_transactions_hidden_columns: [
				'transaction_id',
				'channel',
				'customer_currency',
				'customer_amount',
				'currency',
				'fees',
				'net',
				'order',
				'source',
				'customer_email',
				'customer_country',
				'risk_level',
				'deposit_id',
				'deposit',
				'deposit_status',
			],
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( 'Transactions loaded.' );
		expect(
			screen.getByTestId( 'money-movement-dataviews' )
		).toHaveAttribute(
			'data-visible-fields',
			'date,type,amount,customer_name'
		);
	} );

	it( 'renders the settled field schema from normalized ordinary and exceptional rows', async () => {
		const toLocaleStringSpy = jest
			.spyOn( Date.prototype, 'toLocaleString' )
			.mockReturnValue( 'Jul 20, 2026, 10:30 AM' );
		// Only the columns this schema case covers; transactions-list.test.tsx covers the rest.
		setMockUserPreferences( {
			wc_payments_transactions_hidden_columns: [
				'transaction_id',
				'channel',
				'customer_currency',
				'customer_amount',
				'currency',
				'order',
				'customer_email',
				'customer_country',
				'risk_level',
				'deposit_id',
				'deposit',
				'deposit_status',
			],
		} );

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
				// Client 11.1.0 offers the Date and Type filters under Show: Advanced filters.
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions?filter=advanced',
					] }
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

			// Client 11.1.0 `formatDateTimeFromString( date, { includeTime: true } )`: site formats, read as UTC.
			expect(
				within( cardRow ).getByText( 'July 20, 2026 / 10:30 am' )
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
			// Client 11.1.0 `transactions/list/index.tsx:473-497`: the brand logo, then the last four.
			expect(
				within( cardRow ).getByRole( 'img', { name: 'Visa' } )
			).toBeInTheDocument();
			expect(
				within( cardRow ).getByText( '•••• 4242' )
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
			// Client 11.1.0 `transactions/list/index.tsx:509-514`: no customer on reader fees.
			expect( within( readerRow ).getAllByText( '-' ) ).toHaveLength( 1 );
			expect(
				within( readerRow ).getByText( 'N/A' )
			).toBeInTheDocument();

			expect(
				screen.getByRole( 'img', { name: 'Giropay' } )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'DE89370400440532013000' )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'img', { name: 'Przelewy24 (P24)' } )
			).toBeInTheDocument();
			expect( screen.getByText( 'ING' ) ).toBeInTheDocument();
			// No logo for an unknown method: the text stays.
			expect(
				screen.getByText( 'Custom method bank-42' )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'img', { name: 'Afterpay' } )
			).toBeInTheDocument();
		} finally {
			toLocaleStringSpy.mockRestore();
		}
	} );

	it( 'retains an incomplete Type filter until Refund commits, then clears and follows router history', async () => {
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0, total: 0 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<MoneyMovementRouterBridge />
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( summaryItem( '0 transactions' ) );
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
		mockGetTransactionsSummary.mockResolvedValue( { count: 0, total: 0 } );

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

		await screen.findByText( summaryItem( '0 transactions' ) );
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
		setMockUserPreferences( {
			wc_payments_transactions_hidden_columns: [
				'amount',
				'fees',
				'net',
				'source',
				'customer_name',
			],
			// Client 11.1.0 `transactions/uncaptured/index.tsx:43-108` column keys.
			wc_payments_transactions_uncaptured_hidden_columns: [
				'created',
				'capture_by',
				'risk_level',
				'customer_email',
				'customer_country',
				'action',
			],
		} );
		mockGetTransactions.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetTransactionsSummary.mockResolvedValue( { count: 0, total: 0 } );
		mockGetAuthorizations.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetAuthorizationsSummary.mockResolvedValue( {
			count: 0,
			total: 0,
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/transactions' ] }>
				<MoneyMovementRouterBridge />
				<WooPaymentsTransactionsPage />
			</MemoryRouter>
		);

		await screen.findByText( summaryItem( '0 transactions' ) );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Mock add Type filter' } )
		);
		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Mock open uncaptured transactions',
			} )
		);

		await screen.findByText( summaryItem( '0 authorization(s)' ) );
		await waitFor( () => {
			expect(
				screen.getByTestId( 'money-movement-dataviews' )
			).toHaveAttribute( 'data-view-filters', '[]' );
		} );
		expect(
			screen.getByTestId( 'money-movement-dataviews' )
		).toHaveAttribute( 'data-visible-fields', 'order,amount' );
		expect( mockGetAuthorizations ).toHaveBeenLastCalledWith(
			expect.not.objectContaining( { type_is: expect.anything() } )
		);
		expect( mockUpdateUserPreferences ).not.toHaveBeenCalled();
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
		expect(
			screen.getByText( summaryItem( '42 transactions' ) )
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

		await screen.findByText( summaryItem( '50 transactions' ) );
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
		// Client 11.1.0 offers Export only when the list has rows.
		mockGetTransactions.mockResolvedValue( {
			data: [ { transaction_id: 'txn_test', type: 'charge' } ],
			total_count: 1,
		} as never );
		mockGetTransactionsSummary.mockResolvedValue( {
			total_count: 1,
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
					'Your transactions export has started downloading.',
					{
						selector: '.components-notice__content',
					}
				)
			).closest( '.components-notice' )
		).toHaveClass( 'is-success' );

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

		expect( mockUpdateUserPreferences ).toHaveBeenCalledTimes( 1 );
		expect( mockUpdateUserPreferences ).toHaveBeenCalledWith( {
			wc_payments_transactions_hidden_columns: [
				'transaction_id',
				'date',
				'channel',
				'customer_currency',
				'customer_amount',
				'currency',
				'fees',
				'net',
				'order',
				'source',
				'customer_name',
				'customer_email',
				'customer_country',
				'risk_level',
				'deposit_id',
				'deposit',
				'deposit_status',
			],
		} );
		expect(
			screen.getByTestId( 'money-movement-dataviews' )
		).toHaveAttribute( 'data-visible-fields', 'type,amount' );
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
			screen.getByRole( 'tab', { name: 'Transactions' } )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'tab', { name: 'Uncaptured (1)' } )
		).toHaveAttribute( 'aria-selected', 'true' );

		// Client 11.1.0 `transactions/uncaptured/index.tsx:43-108`: Email and
		// Country are `visible: false` until the merchant shows them.
		expect(
			await screen.findByRole( 'columnheader', {
				name: 'Authorized on',
			} )
		).toBeInTheDocument();
		[ 'Capture by', 'Order', 'Risk level', 'Amount', 'Action' ].forEach(
			( label ) => {
				expect(
					screen.getByRole( 'columnheader', { name: label } )
				).toBeInTheDocument();
			}
		);
		[ 'Email', 'Country', 'Customer' ].forEach( ( label ) => {
			expect(
				screen.queryByRole( 'columnheader', { name: label } )
			).not.toBeInTheDocument();
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
		// Client 11.1.0 `transactions/uncaptured/index.tsx:166` and
		// `components/risk-level/index.tsx:18-30`.
		expect( screen.getByText( '#123 Ada Lovelace' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Elevated' ) ).toBeInTheDocument();
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

		// The client's uncaptured card has no search box; a `search` in the URL still applies.
		await screen.findByText( 'Uncaptured transactions loaded.' );
		expect(
			screen.queryByRole( 'searchbox', {
				name: 'Search uncaptured transactions',
			} )
		).not.toBeInTheDocument();

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

		expect( mockUpdateUserPreferences ).toHaveBeenCalledTimes( 1 );
		expect( mockUpdateUserPreferences ).toHaveBeenCalledWith( {
			// Client 11.1.0 `transactions/uncaptured/index.tsx:43-108` column keys.
			wc_payments_transactions_uncaptured_hidden_columns: [
				'created',
				'capture_by',
				'order',
				'risk_level',
				'customer_email',
				'customer_country',
				'action',
			],
		} );
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
			await screen.findByText( summaryItem( '1 authorization(s)' ) )
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
			await screen.findByText( summaryItem( '0 authorization(s)' ) )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'Uncaptured (0)' } )
		).toBeInTheDocument();
		// Client 11.1.0 `transactions/uncaptured/index.tsx:231-245`: no total without authorizations.
		expect(
			screen.queryByText( summaryItem( '$0.00 total' ) )
		).not.toBeInTheDocument();
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
			await screen.findByRole( 'tab', { name: 'Uncaptured (0)' } )
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
			screen.getByRole( 'tab', { name: 'Uncaptured (0)' } )
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
			await screen.findByRole( 'tab', { name: 'Uncaptured (1)' } )
		).toBeInTheDocument();
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', {
					name: 'Capture authorization for order #123',
				} )
			);
		} );

		expect(
			await screen.findByText( summaryItem( '0 authorization(s)' ) )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'Uncaptured (…)' } )
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
			await screen.findByRole( 'tab', { name: 'Uncaptured (1)' } )
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

	it( "restores hidden dispute columns from the client's user meta key", async () => {
		setMockUserPreferences( {
			wc_payments_disputes_hidden_columns: [ 'created', 'customerEmail' ],
		} );
		mockGetDisputes.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetDisputesSummary.mockResolvedValue( { total_count: 0 } );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/disputes' ] }>
				<WooPaymentsDisputesPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByTestId( 'money-movement-dataviews' )
		).toHaveAttribute(
			'data-visible-fields',
			// Client 11.1.0: a stored list shows every column it does not hide.
			'amount,currency,status,reason,source,order,customerName,customerCountry,due_by,action'
		);
		expect( mockUpdateUserPreferences ).not.toHaveBeenCalled();
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
			],
			total_count: 1,
		} );
		mockGetDisputesSummary.mockResolvedValue( {
			total_count: 1,
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
					'Your disputes export has started downloading.',
					{
						selector: '.components-notice__content',
					}
				)
			).closest( '.components-notice' )
		).toHaveClass( 'is-success' );

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

	/** Oracle: WooPayments 11.1.0 client/payment-details/summary/index.tsx plus charge and balance_transaction fields from the recorded M2 platform response. */
	it( 'renders charge gross in shopper currency and settlement amounts in balance currency', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_fx',
			status: 'succeeded',
			amount: 1234,
			currency: 'eur',
			charges: {
				data: [
					{
						id: 'ch_fx',
						payment_intent: 'pi_fx',
						type: 'charge',
						status: 'succeeded',
						amount: 1234,
						currency: 'eur',
						captured: true,
						balance_transaction: {
							id: 'txn_fx',
							amount: 1415,
							fee: 86,
							net: 1329,
							currency: 'usd',
							exchange_rate: 1.14667,
						},
					},
				],
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect( within( summary ).getByText( '€12.34' ) ).toBeInTheDocument();
		expect( within( summary ).getByText( 'EUR' ) ).toBeInTheDocument();
		expect(
			within( summary ).getByText( '$14.15 USD', {
				selector:
					'.woocommerce-woopayments-payment-summary__settlement-currency',
			} )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -$0.86 USD' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: $13.29 USD' )
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect( within( summary ).getByText( '€50.00' ) ).toBeInTheDocument();
		expect( within( summary ).getByText( 'EUR' ) ).toBeInTheDocument();
		expect(
			summary.querySelector(
				'.woocommerce-woopayments-payment-summary__settlement-currency'
			)
		).toBeNull();
		expect(
			within( summary ).queryByText( /Fees:/ )
		).not.toBeInTheDocument();
		expect(
			within( summary ).queryByText( /Net:/ )
		).not.toBeInTheDocument();
	} );

	it( 'loads payment intent details when the route id is a payment intent', async () => {
		window.wcSettings = {
			...window.wcSettings,
			admin: { woopaymentsSettings: { isSubscriptionsActive: true } },
		} as unknown as typeof window.wcSettings;
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

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
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
		// Client 11.1.0 components/customer-link: the transactions list searched for "name (email)".
		expect(
			within( summary ).getByRole( 'link', { name: 'Ada Lovelace' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining(
				'path=%2Fwoopayments%2Ftransactions&search=Ada+Lovelace+%28ada%40example.com%29'
			)
		);
		// Client 11.1.0 components/order-link: the bare order and subscription numbers.
		expect(
			within( summary ).getByRole( 'link', { name: '123' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123'
		);
		expect(
			within( summary ).getByRole( 'link', {
				name: '456',
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
		// Client 11.1.0 summary/index.tsx:529-651: fees and net once, in the line under the amount.
		expect(
			within( summary ).getByText( 'Fees: -$1.80' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: $48.20' )
		).toBeInTheDocument();
		expect(
			within( summary ).queryByText( 'Fee' )
		).not.toBeInTheDocument();
		expect(
			within( summary ).queryByText( 'Net amount' )
		).not.toBeInTheDocument();

		const paymentMethod = screen
			.getByRole( 'heading', { name: 'Payment method' } )
			.closest( '.components-card' ) as HTMLElement;
		expect( paymentMethod ).toBeInTheDocument();
		// Client 11.1.0 payment-method/card/index.js:107-215: two columns.
		expect(
			Array.from( paymentMethod.querySelectorAll( 'dl' ) ).map(
				( column ) =>
					Array.from( column.querySelectorAll( 'dt' ) ).map(
						( term ) => term.textContent
					)
			)
		).toEqual( [
			[ 'Number', 'Expires', 'Type', 'ID' ],
			[
				'Owner',
				'Owner email',
				'Address',
				'Origin',
				'CVC check',
				'Street check',
				'Postal code check',
			],
		] );
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

		// Client 11.1.0 summary/index.tsx:765-797: Payment ID and Charge ID in the summary card, no separate card.
		expect(
			screen.queryByRole( 'heading', { name: 'Identifiers' } )
		).not.toBeInTheDocument();
		expect( within( summary ).getByText( 'pi_test' ) ).toBeInTheDocument();
		expect( within( summary ).getByText( 'ch_test' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'txn_test' ) ).not.toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Paid.'
		);
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
		).closest( '.components-card' ) as HTMLElement;

		// Client 11.1.0 payment-method/card/check.js: a check the card did not run reads "Not checked".
		expect(
			getDetailValue( paymentMethod, 'CVC check' )
		).toHaveTextContent( /^Not checked$/ );

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

		const summary = ( await screen.findByRole( 'region', {
			name: 'Summary',
		} ) ) as HTMLElement;

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

	it( 'renders the base details the client shows for other supported payment methods', async () => {
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
					type: 'affirm',
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
		).closest( '.components-card' ) as HTMLElement;

		// Client 11.1.0 payment-method/base-payment-method-details: ID, then the owner column; no Type row.
		expect(
			Array.from( paymentMethod.querySelectorAll( 'dl' ) ).map(
				( column ) =>
					Array.from( column.querySelectorAll( 'dt' ) ).map(
						( term ) => term.textContent
					)
			)
		).toEqual( [ [ 'ID' ], [ 'Owner', 'Owner email', 'Address' ] ] );
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

	it( 'shows no payment method card for a type the client does not list', async () => {
		// Client 11.1.0 payment-method/index.js:61-71: unrecognized types render nothing.
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
				payment_method_details: { type: 'link' },
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

		expect(
			await screen.findByRole( 'region', { name: 'Summary' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', { name: 'Payment method' } )
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
		).closest( '.components-card' ) as HTMLElement;

		// Client 11.1.0 payment-method/ideal/index.js:83-140.
		expect(
			Array.from( paymentMethod.querySelectorAll( 'dl' ) ).map(
				( column ) =>
					Array.from( column.querySelectorAll( 'dt' ) ).map(
						( term ) => term.textContent
					)
			)
		).toEqual( [
			[ 'ID', 'Bank name', 'BIC', 'IBAN' ],
			[ 'Verified name', 'Owner', 'Owner email', 'Address' ],
		] );
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

	// Client 11.1.0 `payment-details/summary/index.tsx:370-384,781-848,897-903` and
	// `missing-order-notice/index.tsx:25-66`: a captured charge with no order keeps the refund menu
	// (full refund only, since a partial refund needs an order number) and shows the missing-order notice.
	it( 'offers the full refund and the missing-order notice when the charge has no order', async () => {
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
			screen.getByText(
				'This transaction is not connected to order. Investigate this purchase and refund the transaction as needed.',
				{ selector: 'section p' }
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Refund' } )
		).toBeVisible();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Transaction details loaded. This transaction is not connected to order.'
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Transaction actions' } )
		);
		expect(
			screen.getByRole( 'menuitem', { name: 'Refund in full' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'menuitem', { name: 'Partial refund' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows a payment detail test-mode notice for connected test accounts', async () => {
		mockAccountMode( true );
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

		await screen.findByRole( 'heading', { name: 'Payment details' } );
		// Client 11.1.0 payment-details/payment-details/index.tsx:75 (details view of `payments`).
		expect( await getTestModeNoticeText() ).toBe(
			'WooPayments was in test mode when this order was placed. To view live orders, disable test mode in WooPayments settings.'
		);
		const notice = document.querySelector(
			'.woocommerce-woopayments-test-mode-notice'
		) as HTMLElement;
		expect(
			within( notice ).getByRole( 'link', {
				name: 'WooPayments settings',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings'
		);
	} );

	// Client 11.1.0 payment-details/payment-details/index.tsx:54: the notice is on the page before the payment
	// loads and on the load-error view.
	const renderPaymentDetailsFor = ( id: string ) =>
		render(
			<MemoryRouter
				initialEntries={ [
					`/woopayments/transactions/details?id=${ id }`,
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);
	const pendingRequest = () => new Promise< never >( () => {} );

	it( 'shows the payment detail test-mode notice while the payment loads', () => {
		mockAccountMode( true );
		mockGetPaymentIntent.mockImplementation( pendingRequest );
		mockGetTimeline.mockImplementation( pendingRequest );

		renderPaymentDetailsFor( 'pi_test_mode_pending' );

		expect(
			document.querySelector(
				'.woocommerce-woopayments-test-mode-notice'
			)
		).toHaveTextContent(
			'WooPayments was in test mode when this order was placed. To view live orders, disable test mode in WooPayments settings.'
		);
	} );

	it( 'shows the payment detail test-mode notice when the payment fails to load', async () => {
		mockAccountMode( true );
		mockGetPaymentIntent.mockRejectedValue(
			new Error( 'Provider failed' )
		);
		mockGetTimeline.mockImplementation( pendingRequest );

		renderPaymentDetailsFor( 'pi_test_mode_error' );

		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toBeInTheDocument()
		);
		expect(
			document.querySelector(
				'.woocommerce-woopayments-test-mode-notice'
			)
		).toHaveTextContent(
			'WooPayments was in test mode when this order was placed. To view live orders, disable test mode in WooPayments settings.'
		);
	} );

	// Client 11.1.0 payment-details/payment-details/index.tsx:67 passes getBankName( charge ) to the timeline
	// (utils/charge/index.ts:339-363), which names the bank in the lost-dispute line.
	it( "names the customer's bank on a lost dispute in the payment timeline", async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_lost_dispute',
			status: 'succeeded',
			amount: 10000,
			currency: 'usd',
			created: 1586055370,
			charge: {
				id: 'ch_lost_dispute',
				payment_intent: 'pi_lost_dispute',
				type: 'charge',
				amount: 10000,
				currency: 'usd',
				created: 1586055370,
				captured: true,
				amount_refunded: 0,
				refunded: false,
				payment_method_details: {
					type: 'card',
					card: { issuer: 'Example Issuing Bank' },
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( {
			data: [
				{
					amount: 10000,
					currency: 'USD',
					balance_currency: 'USD',
					datetime: 1586055370,
					fee: 1500,
					type: 'dispute_lost',
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_lost_dispute',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText( 'Example Issuing Bank' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Example Issuing Bank' ).closest( 'li, div' )
		).toHaveTextContent(
			"Dispute lost. Your customer's bank, Example Issuing Bank, reviewed the evidence and decided in the customer's favor."
		);
	} );

	it( 'waits for a non-payment transaction to load before showing the payment test-mode notice', () => {
		mockAccountMode( true );
		mockGetTransaction.mockImplementation( pendingRequest );
		mockGetTimeline.mockImplementation( pendingRequest );

		renderPaymentDetailsFor( 'txn_test_mode_pending' );

		expect(
			document.querySelector(
				'.woocommerce-woopayments-test-mode-notice'
			)
		).toBeNull();
	} );

	// Client 11.1.0 transactions/index.tsx:105, above every tab.
	it.each( [
		[ true, '/woopayments/transactions' ],
		[ false, '/woopayments/transactions' ],
		[ true, '/woopayments/transactions?view=blocked' ],
		[ false, '/woopayments/transactions?view=blocked' ],
	] )(
		'shows the transactions test-mode notice only in test mode (test mode: %s, %s)',
		async ( testMode, route ) => {
			mockAccountMode( testMode );
			mockGetTransactions.mockResolvedValue( {
				data: [],
				total_count: 0,
			} );
			mockGetTransactionsSummary.mockResolvedValue( {
				count: 0,
				total: 0,
			} );

			render(
				<MemoryRouter initialEntries={ [ route ] }>
					<WooPaymentsTransactionsPage />
				</MemoryRouter>
			);

			expect( await getTestModeNoticeText() ).toBe(
				testMode
					? 'Viewing test transactions. To view live transactions, disable test mode in WooPayments settings.'
					: null
			);
		}
	);

	// Client 11.1.0 disputes/index.tsx:451.
	it.each( [ true, false ] )(
		'shows the disputes test-mode notice only in test mode (test mode: %s)',
		async ( testMode ) => {
			mockAccountMode( testMode );
			mockGetDisputes.mockResolvedValue( { data: [], total_count: 0 } );
			mockGetDisputesSummary.mockResolvedValue( { total_count: 0 } );

			render(
				<MemoryRouter initialEntries={ [ '/woopayments/disputes' ] }>
					<WooPaymentsDisputesPage />
				</MemoryRouter>
			);

			expect( await getTestModeNoticeText() ).toBe(
				testMode
					? 'Viewing test disputes. To view live disputes, disable test mode in WooPayments settings.'
					: null
			);
		}
	);

	// Client 11.1.0 payment-details/readers/index.js:37,137, including the error view.
	it.each( [
		[ true, 'rows' ],
		[ false, 'rows' ],
		[ true, 'error' ],
		[ false, 'error' ],
	] )(
		'shows the card reader details test-mode notice only in test mode (test mode: %s, %s)',
		async ( testMode, outcome ) => {
			mockAccountMode( testMode );
			if ( outcome === 'error' ) {
				mockGetReaderChargeSummary.mockRejectedValue(
					new Error( 'Reader provider failed.' )
				);
			} else {
				mockGetReaderChargeSummary.mockResolvedValue( { data: [] } );
			}

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=ch_reader_fee_123&transaction_id=txn_reader_fee_123&transaction_type=card_reader_fee',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			);

			expect( await getTestModeNoticeText() ).toBe(
				testMode
					? 'WooPayments was in test mode when this order was placed. To view live orders, disable test mode in WooPayments settings.'
					: null
			);
		}
	);

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

	it( 'opens the eligible inquiry refund modal from the primary action', async () => {
		let resolveRefund!: (
			value: Awaited< ReturnType< typeof refundWooPaymentsCharge > >
		) => void;
		let refundPromise!: ReturnType< typeof refundWooPaymentsCharge >;
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_primary_inquiry_refund',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_primary_inquiry_refund',
				payment_intent: 'pi_primary_inquiry_refund',
				balance_transaction: 'txn_primary_inquiry_refund',
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
				},
				dispute: {
					dispute_id: 'du_primary_inquiry',
					status: 'warning_needs_response',
					reason: 'fraudulent',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockImplementation(
			() =>
				( refundPromise = new Promise<
					Awaited< ReturnType< typeof refundWooPaymentsCharge > >
				>( ( resolve ) => {
					resolveRefund = resolve;
				} ) )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_primary_inquiry_refund&transaction_id=txn_primary_inquiry_refund',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const issueRefundButton = await screen.findByRole( 'button', {
			name: 'Issue refund',
		} );
		expect( issueRefundButton ).not.toHaveAttribute(
			'aria-disabled',
			'true'
		);

		await userEvent.click( issueRefundButton );

		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_dispute_inquiry_refund_modal_view',
			{
				dispute_id: 'du_primary_inquiry',
				dispute_status: 'warning_needs_response',
				dispute_reason: 'fraudulent',
				on_page: 'transaction_details',
			}
		);
		expect( mockRecordEvent ).not.toHaveBeenCalledWith(
			'payments_transactions_details_refund_modal_open',
			expect.anything()
		);
		let dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );
		expect(
			within( dialog ).getByText(
				'Issuing a refund will close the inquiry, returning the amount in question back to the cardholder. No additional fees apply.'
			)
		).toBeInTheDocument();
		expect( within( dialog ).getByText( '$50.00' ) ).toBeInTheDocument();
		expect(
			within( dialog ).queryByRole( 'link', {
				name: 'Go to the order',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByText(
				'A full refund is not available for this transaction.'
			)
		).not.toBeInTheDocument();
		mockRecordEvent.mockClear();
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		await waitFor( () => expect( issueRefundButton ).toHaveFocus() );

		await userEvent.click( issueRefundButton );
		dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );
		await userEvent.keyboard( '{Escape}' );
		await waitFor( () => expect( issueRefundButton ).toHaveFocus() );

		mockRecordEvent.mockClear();
		await userEvent.click( issueRefundButton );
		dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );

		await userEvent.click(
			within( dialog ).getByLabelText( 'Requested by customer' )
		);
		await userEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Refund transaction',
			} )
		);

		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledWith( {
				chargeId: 'ch_primary_inquiry_refund',
				amount: 5000,
				reason: 'requested_by_customer',
				orderId: 123,
			} )
		);
		expect(
			within( dialog ).getByRole( 'button', {
				name: 'Refunding transaction',
			} )
		).toHaveAttribute( 'aria-disabled', 'true' );
		await userEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Refunding transaction',
			} )
		);
		expect( mockRefundCharge ).toHaveBeenCalledTimes( 1 );
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		await userEvent.keyboard( '{Escape}' );
		expect( dialog ).toBeInTheDocument();
		expect( mockRefundCharge ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			resolveRefund( {
				id: 555,
				order_id: 123,
				amount: '50.00',
				reason: 'requested_by_customer',
				status: 'completed',
			} );
			await refundPromise;
			await Promise.resolve();
		} );
		expect(
			mockRecordEvent.mock.calls.filter(
				( [ event ] ) =>
					event === 'wcpay_dispute_inquiry_refund_modal_view'
			)
		).toHaveLength( 1 );
		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'payments_transactions_details_refund_full',
			{ payment_intent_id: 'pi_primary_inquiry_refund' }
		);
		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_dispute_inquiry_refund_click',
			{
				dispute_id: 'du_primary_inquiry',
				dispute_status: 'warning_needs_response',
				dispute_reason: 'fraudulent',
				on_page: 'transaction_details',
			}
		);
		expect(
			mockRecordEvent.mock.calls.filter(
				( [ event ] ) => event === 'wcpay_dispute_inquiry_refund_click'
			)
		).toHaveLength( 1 );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Refunded payment #pi_primary_inquiry_refund.'
		);
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

	it( 'retains the initiating inquiry when several dispute panes can issue a refund', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_multiple_inquiries',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			charge: {
				id: 'ch_multiple_inquiries',
				payment_intent: 'pi_multiple_inquiries',
				captured: true,
				amount: 5000,
				currency: 'usd',
				amount_refunded: 0,
				refunded: false,
				order: { id: 123, number: '123' },
				dispute: {
					id: 'dp_older_inquiry',
					status: 'warning_needs_response',
					reason: 'fraudulent',
					created: 1000,
				},
				disputes: [
					{
						id: 'dp_newer_inquiry',
						status: 'warning_needs_response',
						reason: 'duplicate',
						created: 2000,
					},
					{
						id: 'dp_older_inquiry',
						status: 'warning_needs_response',
						reason: 'fraudulent',
						created: 1000,
					},
				],
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockReturnValue( new Promise( () => undefined ) );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_multiple_inquiries',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const issueRefundButtons = await screen.findAllByRole( 'button', {
			name: 'Issue refund',
		} );
		expect( issueRefundButtons ).toHaveLength( 2 );
		await userEvent.click( issueRefundButtons[ 0 ] );

		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_dispute_inquiry_refund_modal_view',
			{
				dispute_id: 'dp_older_inquiry',
				dispute_status: 'warning_needs_response',
				dispute_reason: 'fraudulent',
				on_page: 'transaction_details',
			}
		);

		const dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );
		await userEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Refund transaction',
			} )
		);

		expect( mockRecordEvent ).toHaveBeenCalledWith(
			'wcpay_dispute_inquiry_refund_click',
			{
				dispute_id: 'dp_older_inquiry',
				dispute_status: 'warning_needs_response',
				dispute_reason: 'fraudulent',
				on_page: 'transaction_details',
			}
		);
	} );

	it( 'keeps an inquiry refund reason selected and retries after a refund failure', async () => {
		let rejectInitialRefund!: ( error: Error ) => void;
		let resolveRetryRefund!: (
			value: Awaited< ReturnType< typeof refundWooPaymentsCharge > >
		) => void;
		let initialRefundPromise!: ReturnType< typeof refundWooPaymentsCharge >;
		let retryRefundPromise!: ReturnType< typeof refundWooPaymentsCharge >;
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
				dispute: {
					id: 'du_refund_error',
					status: 'warning_needs_response',
					reason: 'fraudulent',
				},
			},
		} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge
			.mockImplementationOnce(
				() =>
					( initialRefundPromise = new Promise<
						Awaited< ReturnType< typeof refundWooPaymentsCharge > >
					>( ( _resolve, reject ) => {
						rejectInitialRefund = reject;
					} ) )
			)
			.mockImplementationOnce(
				() =>
					( retryRefundPromise = new Promise<
						Awaited< ReturnType< typeof refundWooPaymentsCharge > >
					>( ( resolve ) => {
						resolveRetryRefund = resolve;
					} ) )
			);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_refund_error&transaction_id=txn_refund_error',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const issueRefundButton = await screen.findByRole( 'button', {
			name: 'Issue refund',
		} );
		await userEvent.click( issueRefundButton );
		const refundButton = await screen.findByRole( 'button', {
			name: 'Refund transaction',
		} );
		await userEvent.click(
			screen.getByLabelText( 'Requested by customer' )
		);
		await userEvent.click( refundButton );
		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledTimes( 1 )
		);
		await act( async () => {
			rejectInitialRefund( new Error( 'Gateway failed.' ) );
			await initialRefundPromise.catch( () => undefined );
			await Promise.resolve();
		} );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'There has been an error refunding the payment #pi_refund_error. Please try again later. Gateway failed.'
			)
		);
		expect(
			screen.getByRole( 'dialog', { name: 'Refund transaction' } )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Requested by customer' )
		).toBeChecked();
		expect( refundButton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 1 );
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		await userEvent.click( refundButton );
		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledTimes( 2 )
		);
		expect( mockRefundCharge ).toHaveBeenLastCalledWith( {
			chargeId: 'ch_refund_error',
			amount: 5000,
			reason: 'requested_by_customer',
			orderId: 123,
		} );
		await act( async () => {
			resolveRetryRefund( {
				id: 556,
				order_id: 123,
				amount: '50.00',
				reason: 'requested_by_customer',
				status: 'completed',
			} );
			await retryRefundPromise;
			await Promise.resolve();
		} );
		await waitFor( () =>
			expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
				'Refunded payment #pi_refund_error.'
			)
		);
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

	it( 'does not apply a held inquiry refund after the detail route changes', async () => {
		let resolveRefund!: (
			value: Awaited< ReturnType< typeof refundWooPaymentsCharge > >
		) => void;
		mockGetPaymentIntent
			.mockResolvedValueOnce( {
				id: 'pi_held_inquiry_refund',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_held_inquiry_refund',
					payment_intent: 'pi_held_inquiry_refund',
					balance_transaction: 'txn_held_inquiry_refund',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					captured: true,
					amount_refunded: 0,
					refunded: false,
					order: { id: 123, number: '123' },
					dispute: {
						id: 'du_held_inquiry_refund',
						status: 'warning_needs_response',
						reason: 'fraudulent',
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
					payment_intent: 'pi_other',
					balance_transaction: 'txn_other',
					type: 'charge',
					amount: 9900,
					currency: 'usd',
					created: 1781712100,
					captured: true,
					amount_refunded: 0,
					refunded: false,
					order: { id: 456, number: '456' },
				},
			} );
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockImplementation(
			() =>
				new Promise<
					Awaited< ReturnType< typeof refundWooPaymentsCharge > >
				>( ( resolve ) => {
					resolveRefund = resolve;
				} )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_held_inquiry_refund&transaction_id=txn_held_inquiry_refund',
				] }
			>
				<RouteChangeButton to="/woopayments/transactions/details?id=pi_other&transaction_id=txn_other" />
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Issue refund' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Refund transaction',
		} );
		await userEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Refund transaction',
			} )
		);
		await waitFor( () =>
			expect( mockRefundCharge ).toHaveBeenCalledTimes( 1 )
		);

		const routeChangeButton = screen.getByText(
			'Load another transaction'
		);
		await userEvent.click( routeChangeButton );
		expect( await screen.findByText( 'pi_other' ) ).toBeInTheDocument();

		await act( async () => {
			resolveRefund( {
				id: 557,
				order_id: 123,
				amount: '50.00',
				reason: null,
				status: 'completed',
			} );
			await Promise.resolve();
		} );

		expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 2 );
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
		expect(
			screen.queryByRole( 'dialog', { name: 'Refund transaction' } )
		).not.toBeInTheDocument();
		expect( routeChangeButton ).toHaveFocus();
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
		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		// Source: client 11.1.0 payment-status-chip/mappings.ts:49-52 ("Payment authorized").
		expect(
			within( summary ).getByText( 'Payment authorized' )
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

		const pane = (
			await screen.findByRole( 'heading', { name: 'Dispute details' } )
		).closest( '[role="group"]' ) as HTMLElement;
		// Client 11.1.0 dispute-details/dispute-notice.tsx: the urgent notice with the claim and deadline.
		expect(
			within( pane )
				.getByText(
					'The cardholder claims this is an unauthorized transaction.'
				)
				.closest( '.components-notice' )
		).toHaveTextContent(
			"The cardholder claims this is an unauthorized transaction. If you believe this is incorrect, you have until 12:00 AM on June 20, 2026 to challenge the dispute with your customer's bank. If you accept the dispute, you will forfeit the funds and pay the dispute fee."
		);
		// Client 11.1.0 dispute-details/dispute-summary-row.tsx.
		expect(
			Array.from( pane.querySelectorAll( 'dt' ) ).map(
				( term ) => term.textContent
			)
		).toEqual( [
			'Dispute Amount',
			'Disputed On',
			'Reason',
			'Respond By',
		] );
		expect( getDetailValue( pane, 'Dispute Amount' ) ).toHaveTextContent(
			'$50.00'
		);
		expect( getDetailValue( pane, 'Reason' ) ).toHaveTextContent(
			'Transaction unauthorized'
		);
		expect(
			within( getDetailValue( pane, 'Reason' ) ).getByRole( 'button', {
				name: 'Learn more',
			} )
		).toBeInTheDocument();
		expect( getDetailValue( pane, 'Respond By' ) ).toHaveTextContent(
			/^June 20, 2026 12:00 AM \(/
		);
		// The pane sits inside the summary card, as the client's DisputePane does.
		expect(
			within(
				screen.getByRole( 'region', { name: 'Summary' } )
			).getByRole( 'heading', { name: 'Dispute details' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Challenge dispute' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fchallenge&id=dp_test'
		);
		// Client 11.1.0 dispute-awaiting-response-details.tsx:420-438: a tertiary Accept dispute.
		expect(
			screen.getByRole( 'button', { name: 'Accept dispute' } )
		).toHaveClass( 'is-tertiary' );
		expect(
			screen.getByRole( 'link', {
				name: linkNamed( 'Learn more about responding to disputes' ),
			} )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#responding'
		);
	} );

	it( 'renders every charge dispute oldest-first with shared ordinals and refund admission', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_multiple_disputes',
			status: 'succeeded',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_multiple_disputes',
				payment_intent: 'pi_multiple_disputes',
				balance_transaction: {
					id: 'txn_multiple_disputes',
					amount: 5000,
					currency: 'usd',
					fee: 180,
					net: 4820,
				},
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
					id: 'dp_newer',
					status: 'won',
					reason: 'duplicate',
					created: 2000,
					amount: 2000,
					currency: 'usd',
				},
				disputes: [
					{
						id: 'dp_newer',
						status: 'won',
						reason: 'duplicate',
						created: 2000,
						amount: 2000,
						currency: 'usd',
						balance_transactions: [ { amount: -2000, fee: 1500 } ],
					},
					{
						id: 'dp_older',
						status: 'needs_response',
						reason: 'fraudulent',
						created: 1000,
						amount: 3000,
						currency: 'usd',
						balance_transactions: [ { amount: -3000, fee: 1500 } ],
					},
				],
			},
		} );
		mockGetTimeline.mockResolvedValue( {
			data: [
				{
					type: 'dispute_in_review',
					dispute_id: 'dp_older',
					created: 1000,
				},
				{
					type: 'captured',
					amount: 5000,
					currency: 'usd',
					created: 900,
				},
			],
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_multiple_disputes&transaction_id=txn_multiple_disputes',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		// Client 11.1.0 summary/index.tsx:108-145, 993-1003: one pane per dispute inside the summary card,
		// oldest first, each labelled "Dispute N of M"; the awaiting one has the details, the won one its footer.
		const disputeHeadings = await screen.findAllByRole( 'heading', {
			name: 'Dispute details',
		} );
		expect( disputeHeadings ).toHaveLength( 1 );
		const panes = Array.from(
			screen
				.getByRole( 'region', { name: 'Summary' } )
				.querySelectorAll( '.woocommerce-woopayments-dispute-pane' )
		) as HTMLElement[];
		expect(
			panes.map(
				( pane ) =>
					pane.querySelector(
						'.woocommerce-woopayments-money-movement__dispute-label'
					)?.textContent
			)
		).toEqual( [ 'Dispute 1 of 2', 'Dispute 2 of 2' ] );
		expect( within( panes[ 0 ] ).getByRole( 'group' ) ).toHaveAttribute(
			'aria-labelledby',
			disputeHeadings[ 0 ].id
		);
		expect( panes[ 0 ] ).toHaveTextContent( '$30.00' );
		expect( panes[ 1 ] ).toHaveTextContent(
			"Good news — you've won this dispute!"
		);

		const summary = screen.getByRole( 'region', {
			name: 'Summary',
		} ) as HTMLElement;
		expect(
			within( summary ).getByText( 'Disputed: Response needed' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Deducted: -$50.00' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Fees: -$31.80' )
		).toBeInTheDocument();
		expect(
			within( summary ).getByText( 'Net: -$31.80' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Transaction actions' } )
		).not.toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Challenge evidence submitted. · Dispute 1 of 2'
		);
		expect(
			screen.getByText( 'A payment of $50.00 was successfully charged.' )
		).toBeInTheDocument();
	} );

	it( 'names the dispute fee in the accept confirmation when one was charged', async () => {
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
					effective_fee: { amount: 1500, currency: 'usd' },
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

		const acceptButton = await screen.findByRole( 'button', {
			name: 'Accept dispute',
		} );

		await act( async () => {
			await userEvent.click( acceptButton );
		} );

		// Client 11.1.0 dispute-awaiting-response-details.tsx:146 (fee via formatExplicitCurrency; single-currency store, so no code).
		expect( screen.getByRole( 'dialog' ) ).toHaveTextContent(
			'Accepting the dispute marks it as Lost. The disputed amount and the $15.00 dispute fee will not be returned to you.'
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
		// Client 11.1.0 dispute-awaiting-response-details.tsx:153 (no fee charged).
		expect( acceptDialog ).toHaveTextContent(
			'Accepting the dispute marks it as Lost. The disputed amount will not be returned to you.'
		);
		expect( acceptDialog ).toHaveTextContent(
			'This action is final and cannot be undone.'
		);

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
		// Client 11.1.0 dispute-resolution-footer.tsx: the pane turns into the lost footer, which takes focus.
		const lostOutcome = await screen.findByText(
			/^This dispute was lost on/
		);
		expect( lostOutcome.closest( '[tabindex="-1"]' ) ).toHaveFocus();
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

		expect(
			await screen.findByText( /^This dispute was lost on/ )
		).toBeInTheDocument();
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

		// Client 11.1.0 dispute-resolution-footer.tsx: the pane turns into the lost footer, which takes focus.
		const lostOutcome = await screen.findByText(
			/^This dispute was lost on/
		);
		expect( lostOutcome.closest( '[tabindex="-1"]' ) ).toHaveFocus();
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

	it.each( [
		[
			'uncaptured',
			{ captured: false, amount_refunded: 0, refunded: false },
		],
		[
			'partially refunded',
			{ captured: true, amount_refunded: 1000, refunded: false },
		],
		[
			'fully refunded',
			{ captured: true, amount_refunded: 5000, refunded: true },
		],
	] )(
		'keeps %s inquiries unavailable from the primary refund action',
		async ( _state, refundState ) => {
			mockGetPaymentIntent.mockResolvedValue( {
				id: 'pi_ineligible_inquiry',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_ineligible_inquiry',
					payment_intent: 'pi_ineligible_inquiry',
					balance_transaction: 'txn_ineligible_inquiry',
					type: 'charge',
					amount: 5000,
					currency: 'usd',
					created: 1781712000,
					...refundState,
					order: {
						id: 123,
						number: '123',
						url: 'http://example.com/wp-admin/post.php?post=123&action=edit',
					},
					dispute: {
						id: 'du_ineligible_inquiry',
						status: 'warning_needs_response',
						reason: 'fraudulent',
					},
				},
			} );
			mockGetTimeline.mockResolvedValue( { data: [] } );

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_ineligible_inquiry&transaction_id=txn_ineligible_inquiry',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			);

			const issueRefundButton = await screen.findByRole( 'button', {
				name: 'Issue refund',
			} );
			expect( issueRefundButton ).toHaveAttribute(
				'aria-disabled',
				'true'
			);
			expect( issueRefundButton ).toHaveAccessibleDescription(
				'A full refund is not available for this transaction.'
			);
			await userEvent.click( issueRefundButton );
			expect(
				screen.queryByRole( 'dialog', { name: 'Refund transaction' } )
			).not.toBeInTheDocument();
			expect( mockRefundCharge ).not.toHaveBeenCalled();
		}
	);

	it( 'keeps inquiry refunds unavailable when the transaction cannot be fully refunded', async () => {
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
			'A full refund is not available for this transaction.'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Issue refund' } )
		);
		expect( mockRefundCharge ).not.toHaveBeenCalled();
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

		// Client 11.1.0 dispute-resolution-footer.tsx:38-98 (no bank name on this charge).
		expect(
			(
				await screen.findByRole( 'link', {
					name: linkNamed(
						'Learn more about monitoring dispute status.'
					),
				} )
			).parentElement
		).toHaveTextContent(
			/^The customer's bank is currently reviewing the evidence you submitted on .+\. This process can sometimes take more than 60 days — we'll let you know once a decision has been made\. Learn more about monitoring dispute status\./
		);
		expect(
			screen.getByRole( 'link', { name: 'View submitted evidence' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fchallenge&id=dp_test'
		);
	} );

	it.each( [
		// Client 11.1.0 dispute-resolution-footer.tsx:128-216, with its '-' for a missing closed date.
		[
			'won',
			"Good news — you've won this dispute! The customer's bank reached this decision on -. Your account has been credited with the disputed amount and fee. Learn more about preventing disputes.",
			{},
		],
	] )(
		'renders %s dispute outcome guidance',
		async ( status, message, disputeDetails ) => {
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
						...disputeDetails,
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
				(
					await screen.findByRole( 'link', {
						name: linkNamed(
							'Learn more about preventing disputes.'
						),
					} )
				).parentElement
			).toHaveTextContent( message );
			expect(
				screen.getByRole( 'link', { name: 'View dispute details' } )
			).toBeInTheDocument();
		}
	);

	it.each( [
		[
			'an explicit null annotation',
			{
				effective_fee: null,
				balance_transactions: [
					{
						fee: 1500,
						reporting_category: 'dispute',
					},
				],
			},
		],
		[
			'a legacy reversal',
			{
				balance_transactions: [
					{
						fee: 1500,
						reporting_category: 'dispute',
					},
					{
						fee: -1500,
						reporting_category: 'dispute_reversal',
					},
				],
			},
		],
	] )(
		'omits a lost dispute fee for %s',
		async ( _label, disputeDetails ) => {
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
						status: 'lost',
						reason: 'fraudulent',
						...disputeDetails,
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

			// Source: client 11.1.0 dispute-resolution-footer.tsx:240-249 and :316-327.
			expect(
				await screen.findByText(
					/^This dispute was lost on - due to non-response\. The disputed amount has been returned to your customer\./
				)
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'link', {
					name: linkNamed( 'Learn more about disputed amounts.' ),
				} )
			).toBeInTheDocument();
			expect(
				screen.queryByText( /fee has been deducted/ )
			).not.toBeInTheDocument();
		}
	);

	/**
	 * Load one REC-5b R-d recorded `GET .../wcpay/disputes/{id}` response body by
	 * pair key, unchanged, to render as the charge's dispute.
	 */
	function loadRec5bDispute( pair: string ) {
		const fixture = JSON.parse(
			fs.readFileSync(
				path.resolve(
					__dirname,
					'../../../../../../tests/php/src/Internal/Payments/Providers/WooPayments/Fixtures/rec-5b-disputes.json'
				),
				'utf8'
			)
		);
		return fixture.entries.find(
			( candidate: { pair: string } ) => candidate.pair === pair
		).response.body;
	}

	const renderRecordedDispute = ( dispute: Record< string, unknown > ) => {
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
				payment_method_details: (
					dispute.charge as { payment_method_details?: unknown }
				 )?.payment_method_details,
				dispute,
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
	};

	// Client 11.1.0 `formatDateTimeFromTimestamp()`: the site date format (`F j, Y` in these tests), read as UTC.
	const formatRecordedDate = ( timestamp: string ) =>
		dateI18n(
			'F j, Y',
			new Date( Number( timestamp ) * 1000 ).toISOString(),
			undefined
		);

	/**
	 * Client 11.1.0 dispute notice claim (`disputes/strings.ts:158`,
	 * `dispute-notice.tsx:34-39`) for the recorded REC-5b needs-response
	 * fraudulent dispute, and the fallback for a reason without a claim.
	 */
	it.each( [
		[
			'fraudulent',
			'The cardholder claims this is an unauthorized transaction.',
		],
		[ 'general', 'The cardholder claims this is an unauthorized charge.' ],
	] )(
		'shows the client claim sentence for a needs-response %s dispute',
		async ( reason, claim ) => {
			renderRecordedDispute( {
				...loadRec5bDispute( 'accept_pre_read' ),
				reason,
			} );

			expect( await screen.findByText( claim ) ).toBeInTheDocument();
		}
	);

	/**
	 * Client 11.1.0 lost footer (`dispute-resolution-footer.tsx:218-330`) for
	 * the recorded REC-5b lost disputes: accepted by the merchant, and lost
	 * after evidence with the card issuer as the bank name.
	 */
	it.each( [
		[
			'accept_read_after_close',
			( closedAt: string ) =>
				`You accepted this dispute on ${ closedAt }. The $15.00 fee has been deducted from your account, and the disputed amount has been returned to your customer. Learn more about dispute fees.`,
		],
		[
			'lose_read_after_close',
			( closedAt: string ) =>
				`Unfortunately, you've lost this dispute. The customer's bank, Stripe Test (multi-country), reached this decision on ${ closedAt }. The $15.00 fee has been deducted from your account, and the disputed amount has been returned to your customer. Learn more about dispute fees.`,
		],
	] )(
		'renders the client lost footer for the recorded %s dispute',
		async ( pair, expected ) => {
			const dispute = loadRec5bDispute( pair );
			renderRecordedDispute( dispute );

			const footer = (
				await screen.findByRole( 'link', {
					name: linkNamed( 'Learn more about dispute fees.' ),
				} )
			).parentElement as HTMLElement;
			expect( footer ).toHaveTextContent(
				expected(
					formatRecordedDate( dispute.metadata.__dispute_closed_at )
				)
			);
		}
	);

	/**
	 * Client 11.1.0 lost footer names the BNPL provider, not just the card
	 * issuer, for a charge made through it (`utils/charge/index.ts:339-363`).
	 * Built from the recorded `lose_read_after_close` dispute with the
	 * charge's payment method swapped for a BNPL type; every other field
	 * (reason, metadata, effective_fee) stays as recorded.
	 */
	it.each( [
		[ 'afterpay_clearpay', 'Afterpay / Clearpay' ],
		[ 'klarna', 'Klarna' ],
	] )(
		"renders the client's %s label in the lost footer, not just the card issuer",
		async ( paymentMethodType, bankLabel ) => {
			const recorded = loadRec5bDispute( 'lose_read_after_close' );
			const dispute = {
				...recorded,
				charge: {
					...recorded.charge,
					payment_method_details: { type: paymentMethodType },
				},
			};
			renderRecordedDispute( dispute );

			const footer = (
				await screen.findByRole( 'link', {
					name: linkNamed( 'Learn more about dispute fees.' ),
				} )
			).parentElement as HTMLElement;
			expect( footer ).toHaveTextContent(
				`Unfortunately, you've lost this dispute. The customer's bank, ${ bankLabel }, reached this decision on ${ formatRecordedDate(
					dispute.metadata.__dispute_closed_at
				) }.`
			);
		}
	);

	/**
	 * Client 11.1.0 resolution footers (`dispute-resolution-footer.tsx:38-98`,
	 * `:128-216`, `:363-403`, `:433-461`) and action labels (`:206-209`,
	 * `:350-353`, `:112-116`) for the recorded `lose_read_after_close` dispute
	 * moved to each state. The closed date is one day after the evidence date
	 * so each sentence proves which date it reads (format per C17).
	 */
	it.each( [
		[
			'under_review',
			{},
			'Learn more about monitoring dispute status.',
			( submitted: string ) =>
				`The customer's bank, Stripe Test (multi-country), is currently reviewing the evidence you submitted on ${ submitted }. This process can sometimes take more than 60 days — we'll let you know once a decision has been made. Learn more about monitoring dispute status.`,
			'View submitted evidence',
		],
		[
			'under_review',
			{ reason: 'noncompliant' },
			'Learn more about monitoring dispute status.',
			( submitted: string ) =>
				`Visa is currently reviewing the evidence you submitted on ${ submitted }. This process can sometimes take more than 60 days — we'll let you know once a decision has been made. Learn more about monitoring dispute status.`,
			'View submitted evidence',
		],
		[
			'won',
			{},
			'Learn more about preventing disputes.',
			( _submitted: string, closed: string ) =>
				`Good news — you've won this dispute! The customer's bank, Stripe Test (multi-country), reached this decision on ${ closed }. Your account has been credited with the disputed amount and fee. Learn more about preventing disputes.`,
			'View dispute details',
		],
		[
			'warning_under_review',
			{},
			'Learn more.',
			( submitted: string ) =>
				`You submitted evidence for this inquiry on ${ submitted }. Stripe Test (multi-country) is reviewing the case, which can take 120 days or more. You will be alerted when they make their final decision. Learn more.`,
			'View submitted evidence',
		],
		[
			'warning_closed',
			{},
			'Learn more about preventing disputes.',
			( _submitted: string, closed: string ) =>
				`This inquiry was closed on ${ closed }. Learn more about preventing disputes.`,
			'View submitted evidence',
		],
		[
			'lost',
			{},
			'Learn more about dispute fees.',
			( _submitted: string, closed: string ) =>
				`Unfortunately, you've lost this dispute. The customer's bank, Stripe Test (multi-country), reached this decision on ${ closed }.`,
			'View dispute details',
		],
	] )(
		'renders the client %s resolution footer for the recorded dispute',
		async ( status, overrides, docLabel, expected, actionLabel ) => {
			const recorded = loadRec5bDispute( 'lose_read_after_close' );
			const closedAt = String(
				Number( recorded.metadata.__evidence_submitted_at ) + 86400
			);
			renderRecordedDispute( {
				...recorded,
				...overrides,
				status,
				metadata: {
					...recorded.metadata,
					__dispute_closed_at: closedAt,
				},
			} );

			const footer = (
				await screen.findByRole( 'link', {
					name: linkNamed( docLabel ),
				} )
			).parentElement as HTMLElement;
			expect( footer ).toHaveTextContent(
				expected(
					formatRecordedDate(
						recorded.metadata.__evidence_submitted_at
					),
					formatRecordedDate( closedAt )
				)
			);
			expect(
				screen.getByRole( 'link', { name: actionLabel } )
			).toBeInTheDocument();
		}
	);

	/**
	 * The recorded `lose_read_after_close` dispute's __dispute_closed_at and
	 * __evidence_submitted_at fall on the same calendar day, so a mutation
	 * that reads the evidence date instead of the closed date would survive
	 * the fixed-fixture test above. This variant moves __dispute_closed_at
	 * one day later so the two dates disagree; per C17 the assertion checks
	 * which date is chosen (the closed date), not native's date format.
	 */
	it( 'chooses the closed date, not the evidence date, for the lost footer', async () => {
		const recorded = loadRec5bDispute( 'lose_read_after_close' );
		const closedAt = String(
			Number( recorded.metadata.__dispute_closed_at ) + 86400
		);
		const dispute = {
			...recorded,
			metadata: {
				...recorded.metadata,
				__dispute_closed_at: closedAt,
			},
		};
		renderRecordedDispute( dispute );

		const footer = (
			await screen.findByRole( 'link', {
				name: linkNamed( 'Learn more about dispute fees.' ),
			} )
		).parentElement as HTMLElement;
		expect( footer ).toHaveTextContent( formatRecordedDate( closedAt ) );
		expect( footer ).not.toHaveTextContent(
			formatRecordedDate( dispute.metadata.__evidence_submitted_at )
		);
	} );

	describe( 'WooPaymentsTransactionTimeline early fraud warnings', () => {
		it( 'renders an actionable warning with the known reason and refund action', async () => {
			const onRefund = jest.fn();

			render(
				<WooPaymentsTransactionTimeline
					events={ [
						{
							type: 'early_fraud_warning',
							datetime: 1781712200,
							efw_actionable: true,
							efw_type: 'made_with_stolen_card',
						},
					] }
					onRefund={ onRefund }
					refundDialogId="refund-dialog"
					isRefundDialogOpen={ false }
				/>
			);

			expect( getTimelineHeadlines() ).toContain(
				'Payment status changed to Early fraud warning.'
			);
			expect(
				screen.getByText( 'Payment received an early fraud warning' )
			).toBeInTheDocument();
			expect( getTimelineHeadlines() ).toHaveLength( 2 );
			expect(
				screen.getByText(
					'The card issuer flagged this payment as likely fraudulent.'
				)
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Reported reason: Made with stolen card' )
			).toBeInTheDocument();
			const refundButton = screen.getByRole( 'button', {
				name: 'Refund this payment',
			} );
			expect( refundButton.closest( 'li' ) ).toHaveTextContent(
				'Refunding this payment now can prevent a dispute. Refund this payment'
			);
			expect( refundButton ).toHaveAttribute( 'aria-haspopup', 'dialog' );
			expect( refundButton ).toHaveAttribute( 'aria-expanded', 'false' );
			expect( refundButton ).not.toHaveAttribute( 'aria-controls' );

			await userEvent.click( refundButton );

			expect( onRefund ).toHaveBeenCalledTimes( 1 );
			expect( onRefund ).toHaveBeenCalledWith( refundButton );
		} );

		it.each( [
			[ 'card_never_received', 'Card never received' ],
			[ 'fraudulent_card_application', 'Fraudulent card application' ],
			[ 'made_with_counterfeit_card', 'Made with counterfeit card' ],
			[ 'made_with_lost_card', 'Made with lost card' ],
			[ 'made_with_stolen_card', 'Made with stolen card' ],
			[ 'misc', 'Other' ],
			[ 'unauthorized_use_of_card', 'Unauthorized use of card' ],
		] )( 'maps the %s provider reason', ( providerReason, label ) => {
			render(
				<WooPaymentsTransactionTimeline
					events={ [
						{
							type: 'early_fraud_warning',
							efw_actionable: true,
							efw_type: providerReason,
						},
					] }
				/>
			);

			expect(
				screen.getByText( `Reported reason: ${ label }` )
			).toBeInTheDocument();
		} );

		it.each( [
			'future_card_pattern',
			'constructor',
			'toString',
			'__proto__',
		] )( 'omits the unknown %s provider reason', ( providerReason ) => {
			render(
				<WooPaymentsTransactionTimeline
					events={ [
						{
							type: 'early_fraud_warning',
							efw_actionable: true,
							efw_type: providerReason,
						},
					] }
					onRefund={ jest.fn() }
					refundDialogId="refund-dialog"
					isRefundDialogOpen={ false }
				/>
			);

			expect(
				screen.getByText( 'Payment received an early fraud warning' )
			).toBeInTheDocument();
			expect(
				screen.queryByText( providerReason )
			).not.toBeInTheDocument();
			expect(
				screen.queryByText( /Reported reason:/ )
			).not.toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Refund this payment' } )
			).toBeInTheDocument();
		} );

		it( 'keeps prevention guidance as plain text without a refund callback', () => {
			render(
				<WooPaymentsTransactionTimeline
					events={ [
						{
							type: 'early_fraud_warning',
							efw_actionable: true,
						},
					] }
				/>
			);

			expect(
				screen.getByText(
					'Refunding this payment now can prevent a dispute.'
				)
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: 'Refund this payment' } )
			).not.toBeInTheDocument();
		} );

		it.each( [
			[ 'false', false ],
			[ 'string true', 'true' ],
			[ 'numeric one', 1 ],
			[ 'an object', { value: true } ],
		] )(
			'renders the resolved state for %s actionable data',
			( _label, actionable ) => {
				render(
					<WooPaymentsTransactionTimeline
						events={ [
							{
								type: 'early_fraud_warning',
								efw_actionable: actionable,
								efw_type: 'card_never_received',
							} as WooPaymentsTimelineEvent,
						] }
						onRefund={ jest.fn() }
						refundDialogId="refund-dialog"
						isRefundDialogOpen={ false }
					/>
				);

				expect( getTimelineHeadlines() ).toContain(
					'Payment status changed to Early fraud warning resolved.'
				);
				expect(
					screen.getByText(
						'This early fraud warning is no longer actionable.'
					)
				).toBeInTheDocument();
				expect( getTimelineHeadlines() ).toHaveLength( 2 );
				expect(
					screen.getByText(
						'The payment was refunded or disputed, so no further action is needed to avoid a dispute.'
					)
				).toBeInTheDocument();
				expect(
					screen.getByText( 'Reported reason: Card never received' )
				).toBeInTheDocument();
				expect(
					screen.queryByRole( 'button', {
						name: 'Refund this payment',
					} )
				).not.toBeInTheDocument();
			}
		);
	} );

	describe( 'transaction details early fraud warning refund flow', () => {
		it( 'opens the eligible refund dialog and restores focus to the timeline action', async () => {
			mockGetPaymentIntent.mockResolvedValue( {
				id: 'pi_efw_refund',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_efw_refund',
					payment_intent: 'pi_efw_refund',
					balance_transaction: 'txn_efw_refund',
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
			mockGetTimeline.mockResolvedValue( {
				data: [
					{
						type: 'early_fraud_warning',
						datetime: 1781712200,
						efw_actionable: true,
						efw_type: 'made_with_stolen_card',
					},
				],
			} );

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_efw_refund&transaction_id=txn_efw_refund',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			);

			const refundButton = await screen.findByRole( 'button', {
				name: 'Refund this payment',
			} );
			expect( refundButton ).toHaveAttribute( 'aria-expanded', 'false' );
			expect( refundButton ).not.toHaveAttribute( 'aria-controls' );

			await userEvent.click( refundButton );

			const dialog = await screen.findByRole( 'dialog', {
				name: 'Refund transaction',
			} );
			expect( dialog ).toHaveAttribute(
				'id',
				'woocommerce-woopayments-refund-dialog'
			);
			expect( refundButton ).toHaveAttribute( 'aria-expanded', 'true' );
			expect( refundButton ).toHaveAttribute(
				'aria-controls',
				dialog.id
			);
			expect( mockRecordEvent ).toHaveBeenCalledWith(
				'payments_transactions_details_refund_modal_open',
				{ payment_intent_id: 'pi_efw_refund' }
			);

			await userEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Cancel' } )
			);

			await waitFor( () =>
				expect(
					screen.queryByRole( 'dialog', {
						name: 'Refund transaction',
					} )
				).not.toBeInTheDocument()
			);
			await waitFor( () => expect( refundButton ).toHaveFocus() );
			expect( refundButton ).toHaveAttribute( 'aria-expanded', 'false' );
			expect( refundButton ).not.toHaveAttribute( 'aria-controls' );
		} );

		it( 'keeps warning guidance but omits the refund action when ineligible', async () => {
			mockGetPaymentIntent.mockResolvedValue( {
				id: 'pi_efw_partial_refund',
				status: 'succeeded',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				charge: {
					id: 'ch_efw_partial_refund',
					payment_intent: 'pi_efw_partial_refund',
					balance_transaction: 'txn_efw_partial_refund',
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
			mockGetTimeline.mockResolvedValue( {
				data: [
					{
						type: 'early_fraud_warning',
						datetime: 1781712200,
						efw_actionable: true,
						efw_type: 'made_with_stolen_card',
					},
				],
			} );

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_efw_partial_refund&transaction_id=txn_efw_partial_refund',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			);

			expect(
				await screen.findByText(
					'Payment received an early fraud warning'
				)
			).toBeInTheDocument();
			expect(
				screen.getByText(
					'Refunding this payment now can prevent a dispute.'
				)
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: 'Refund this payment' } )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'dialog', { name: 'Refund transaction' } )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'renders reference-shaped timeline event details with datetime values', async () => {
		const eventDatetime = 1781712200;
		// 2026-06-17 16:03:20 UTC in the default UTC site timezone and `F j, Y` date format.
		const expectedEventDate = 'June 17, 2026';

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
					acquirer_reference_number_status: 'available',
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
			await screen.findByText(
				'A payment of $50.00 was successfully charged.'
			)
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Paid.'
		);
		expect(
			screen.getByText( 'A payment of $50.00 was successfully charged.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Fee: $1.80' ) ).toBeInTheDocument();
		// Source: client 11.1.0 map-events.js composeNetString ("Net payout: %s").
		expect( screen.getByText( 'Net payout: $48.20' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'A payment of $10.00 was successfully refunded.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Reason: Requested by customer' )
		).toBeInTheDocument();
		// Source: client 11.1.0 map-events.js getRefundTrackingDetails.
		expect(
			screen.getByText( 'Acquirer Reference Number (ARN) arn_refund_123' )
		).toBeInTheDocument();
		// Client 11.1.0 map-events.js:1397-1398: `dispute.created` is not a timeline type, so no line.
		expect(
			screen.queryByText( /A dispute was opened/ )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Payment was approved by admin' )
		).toBeInTheDocument();
		// One day group titled with the site date format (timeline/index.js:46, `timezone="site"`).
		expect(
			Array.from(
				document.querySelectorAll(
					'.woocommerce-timeline-group__title'
				)
			).map( ( title ) => title.textContent )
		).toEqual( [ expectedEventDate ] );
		expect( mockGetTimeline ).toHaveBeenCalledWith( 'pi_test' );
	} );

	/**
	 * Load one REC-5a R-b recorded `GET .../wcpay/timeline/{intent}` response body
	 * by pair key, unchanged (including `transaction_details`, `fee_rates`, and
	 * `fee_breakdown_v1`), ready to hand straight to `mockGetTimeline`.
	 */
	function loadRec5aTimelineEntry( pair: string ) {
		const fixture = JSON.parse(
			fs.readFileSync(
				path.resolve(
					__dirname,
					'../../../../../../tests/php/src/Internal/Payments/Providers/WooPayments/Fixtures/rec-5a-timelines.json'
				),
				'utf8'
			)
		);
		const entry = fixture.entries.find(
			( candidate: { pair: string } ) => candidate.pair === pair
		);
		if ( ! entry ) {
			throw new Error(
				`REC-5a R-b fixture has no entry for pair '${ pair }'.`
			);
		}
		return entry.response.body;
	}

	/**
	 * REC-5a R-b (`data/rec-5a-refunds.md`, `data/t1-provider-family-audit.md`
	 * §4 Batch 5): the exact, unmodified timeline body the platform returned
	 * for a USD 10.99 refund with a free-text merchant reason. Only the
	 * lines that match client 11.1.0's own rendering (`map-events.js`) are
	 * asserted: the refund status change, payout and main items with the
	 * ARN and reason lines (`map-events.js:937-974`, `:86-139`, `:485-496`),
	 * and the Paid/Authorized/Started status lines.
	 *
	 * The captured fee line is left out (R1): the client composes a fee
	 * string from `fee_breakdown_v1`/`fee_rates` via `composeFeeString()`.
	 */
	it( 'renders the recorded USD full-refund timeline, including the free-text reason', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_3UJZZBBzWlxcwgpP0CDlkka0',
			charge: {
				id: 'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
				balance_transaction: { id: 'txn_3UJZZBBzWlxcwgpP070cVw1A' },
				type: 'charge',
				amount: 1099,
				currency: 'usd',
				created: 1790344413,
			},
		} );
		mockGetTimeline.mockResolvedValue(
			loadRec5aTimelineEntry( 'usd_full_refund_free_text_reason' )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_3UJZZBBzWlxcwgpP0CDlkka0&transaction_id=txn_3UJZZBBzWlxcwgpP070cVw1A',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText(
				'A payment of $10.99 was successfully refunded.'
			)
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Refunded.'
		);
		expect(
			screen.getByText( '$10.99 will be deducted from a future payout.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Acquirer Reference Number (ARN) 8884226196159867'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( /→/ ) ).not.toBeInTheDocument();
		expect(
			screen.getByText(
				'Reason: REC-5a free-text reason: customer returned the item unopened'
			)
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Paid.'
		);
		expect(
			screen.getByText( 'A payment of $10.99 was successfully charged.' )
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Authorized.'
		);
		expect(
			screen.getByText(
				'A payment of $10.99 was successfully authorized.'
			)
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Started.'
		);
	} );

	/**
	 * REC-5a R-b, the EUR row. The refund renders in the charge's own
	 * currency, and the recorded enum reason ("requested_by_customer") is
	 * readable through `formatLabel()`, contradicting the deleted
	 * `R1v`/`R3v` cases' premise that free-text reasons alone made the
	 * reason unreadable there. The payout item and the FX line use the
	 * recorded store amount (`map-events.js:943-949`, `:433-459`); the
	 * exchange rate is 1407 / 1234 at the client's five-digit precision.
	 */
	it( 'renders the recorded EUR full-refund timeline in the charge’s own currency', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_3UJWs2BzWlxcwgpP10KzkjsT',
			charge: {
				id: 'ch_3UJWs2BzWlxcwgpP1y9vRrWr',
				balance_transaction: { id: 'txn_3UJWs2BzWlxcwgpP1MLoqLbF' },
				type: 'charge',
				amount: 1234,
				currency: 'eur',
				created: 1790334050,
			},
		} );
		mockGetTimeline.mockResolvedValue(
			loadRec5aTimelineEntry( 'eur_full_refund' )
		);

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_3UJWs2BzWlxcwgpP10KzkjsT&transaction_id=txn_3UJWs2BzWlxcwgpP1MLoqLbF',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		expect(
			await screen.findByText(
				'A payment of €12.34 was successfully refunded.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Reason: Requested by customer' )
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Refunded.'
		);
		expect(
			screen.getByText( '$14.07 will be deducted from a future payout.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( '€1.00 → 1.14019 USD: $14.07' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Acquirer Reference Number (ARN) 7028278089905733'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'A payment of €12.34 was successfully charged.' )
		).toBeInTheDocument();
	} );

	/**
	 * Variants of the recorded REC-5a USD refund event for the branches the
	 * recording did not hit (client 11.1.0 `map-events.js`): a pending ARN is
	 * hidden (`:485-496`), a paid-out partial refund links its payout
	 * (`:86-118`), and a free-text reason shows as entered (`:463-483`).
	 */
	it( 'renders recorded refund variants for pending ARN, payout link and free-text reason', () => {
		const [ recorded ] = loadRec5aTimelineEntry(
			'usd_full_refund_free_text_reason'
		).data.filter( ( event: { type: string } ) =>
			event.type.endsWith( 'refund' )
		);

		render(
			<WooPaymentsTransactionTimeline
				events={ [
					{
						...recorded,
						type: 'partial_refund',
						acquirer_reference_number_status: 'pending',
						reason: 'store_credit issued instead',
						deposit: { id: 'po_rec5a', arrival_date: 1790344415 },
					},
				] }
			/>
		);

		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Partial refund.'
		);
		expect(
			screen.queryByText( /Acquirer Reference Number/ )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Reason: store_credit issued instead' )
		).toBeInTheDocument();
		const payoutLink = screen.getByRole( 'link', {
			name: /payout$/,
		} );
		expect( payoutLink.closest( 'span' ) ).toHaveTextContent(
			/^\$10\.99 was deducted from your .+ payout\.$/
		);
		expect( payoutLink.getAttribute( 'href' ) ).toContain(
			'path=%2Fwoopayments%2Fpayouts%2Fdetails&id=po_rec5a'
		);
	} );

	it( 'qualifies only known multi-dispute timeline events', () => {
		// Source: client 11.1.0 map-events.js:793-821 withDisputeQualifier.
		const inReview = ( disputeId: string ) => ( {
			type: 'dispute_in_review',
			dispute_id: disputeId,
			datetime: 1781712200,
		} );
		const { rerender } = render(
			<WooPaymentsTransactionTimeline
				events={ [
					inReview( 'dp_first' ),
					inReview( 'dp_second' ),
					inReview( 'dp_unknown' ),
				] }
				disputeOrder={ {
					orderById: { dp_first: 1, dp_second: 2 },
					orderedDisputes: [],
					total: 2,
				} }
			/>
		);

		expect( getTimelineHeadlines() ).toEqual( [
			'Payment status changed to Disputed: In review. · Dispute 1 of 2',
			'Challenge evidence submitted. · Dispute 1 of 2',
			'Payment status changed to Disputed: In review. · Dispute 2 of 2',
			'Challenge evidence submitted. · Dispute 2 of 2',
			'Payment status changed to Disputed: In review.',
			'Challenge evidence submitted.',
		] );

		rerender(
			<WooPaymentsTransactionTimeline
				events={ [ inReview( 'dp_first' ) ] }
				disputeOrder={ {
					orderById: { dp_first: 1 },
					orderedDisputes: [],
					total: 1,
				} }
			/>
		);
		expect( getTimelineHeadlines() ).toEqual( [
			'Payment status changed to Disputed: In review.',
			'Challenge evidence submitted.',
		] );

		rerender(
			<WooPaymentsTransactionTimeline
				events={ [ inReview( 'dp_first' ) ] }
			/>
		);
		expect( screen.queryByText( /· Dispute/ ) ).not.toBeInTheDocument();
	} );

	it( 'qualifies every provider dispute timeline event for known disputes', () => {
		// Source: client 11.1.0 map-events.js:1020-1275 withDisputeQualifier (status and main lines only).
		render(
			<WooPaymentsTransactionTimeline
				events={ [
					{
						type: 'dispute_needs_response',
						dispute_id: 'dp_first',
						reason: 'fraudulent',
						amount: null,
					},
					{ type: 'dispute_in_review', dispute_id: 'dp_second' },
					{ type: 'dispute_won', dispute_id: 'dp_first' },
					{ type: 'dispute_lost', dispute_id: 'dp_second' },
					{
						type: 'dispute_warning_closed',
						dispute_id: 'dp_first',
					},
					{
						type: 'dispute_charge_refunded',
						dispute_id: 'dp_second',
					},
					{
						type: 'dispute_needs_response',
						dispute_id: 'dp_unknown',
						reason: 'fraudulent',
						amount: null,
					},
					{ type: 'captured', amount: 4000, currency: 'usd' },
				].map( ( event ) => ( { datetime: 1781712200, ...event } ) ) }
				disputeOrder={ {
					orderById: { dp_first: 1, dp_second: 2 },
					orderedDisputes: [],
					total: 2,
				} }
			/>
		);

		expect( getTimelineHeadlines() ).toEqual( [
			'Payment status changed to Disputed: Needs response. · Dispute 1 of 2',
			'No funds have been withdrawn yet.',
			'Payment disputed as Transaction unauthorized. · Dispute 1 of 2',
			'Payment status changed to Disputed: In review. · Dispute 2 of 2',
			'Challenge evidence submitted. · Dispute 2 of 2',
			'Payment status changed to Disputed: Won. · Dispute 1 of 2',
			'Dispute won! The bank ruled in your favor. · Dispute 1 of 2',
			'Payment status changed to Disputed: Lost. · Dispute 2 of 2',
			"Dispute lost. Your customer's bank reviewed the evidence and decided in the customer's favor. · Dispute 2 of 2",
			'Dispute inquiry closed. The bank chose not to pursue this dispute. · Dispute 1 of 2',
			'The disputed charge has been refunded. · Dispute 2 of 2',
			'Payment status changed to Disputed: Needs response.',
			'No funds have been withdrawn yet.',
			'Payment disputed as Transaction unauthorized.',
			'Payment status changed to Paid.',
			'A payment of $40.00 was successfully charged.',
		] );
	} );

	it( 'leaves dispute payout lines unqualified when events include amounts', () => {
		// Source: client 11.1.0 map-events.test.js "leaves the deposit line unqualified".
		render(
			<WooPaymentsTransactionTimeline
				events={ [
					{
						type: 'dispute_needs_response',
						dispute_id: 'dp_first',
						reason: 'fraudulent',
						amount: 1000,
						fee: 1500,
						currency: 'usd',
					},
					{
						type: 'dispute_won',
						dispute_id: 'dp_second',
						amount: 2000,
						fee: -1500,
						currency: 'usd',
					},
				] }
				disputeOrder={ {
					orderById: { dp_first: 1, dp_second: 2 },
					orderedDisputes: [],
					total: 2,
				} }
			/>
		);

		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Disputed: Needs response. · Dispute 1 of 2'
		);
		expect(
			screen.getByText( '$25.00 will be deducted from a future payout.' )
		).toBeInTheDocument();
		expect( getTimelineHeadlines() ).toContain(
			'Payment status changed to Disputed: Won. · Dispute 2 of 2'
		);
		expect(
			screen.getByText( '$35.00 will be added to a future payout.' )
		).toBeInTheDocument();
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
		expect( screen.getByText( 'ch_test' ) ).toBeInTheDocument();
		// Client 11.1.0 timeline/index.js:39-45: the card keeps its title and says the timeline failed.
		expect(
			screen.getByText( 'Error while loading timeline' )
		).toBeInTheDocument();
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

		expect( await screen.findByText( 'ch_test' ) ).toBeInTheDocument();
		expect( mockGetCharge ).toHaveBeenCalledWith( 'ch_test' );
		expect( mockGetPaymentIntent ).toHaveBeenCalledWith( 'pi_test' );
		expect( mockGetTransaction ).not.toHaveBeenCalled();
	} );

	it( 'uses the WordPress site timezone and locale for timeline dates', () => {
		// Client 11.1.0 timeline/index.js:46: `<Timeline items={ items } timezone="site" />`.
		const originalSettings = getSettings();
		const originalResolvedOptions =
			Intl.DateTimeFormat.prototype.resolvedOptions;
		const months = [ ...originalSettings.l10n.months ];
		months[ 5 ] = 'SiteJune';
		const browserZone = 'America/Los_Angeles';
		const browserZoneSpy = jest
			.spyOn( Intl.DateTimeFormat.prototype, 'resolvedOptions' )
			.mockImplementation( function () {
				return {
					...originalResolvedOptions.call( this ),
					timeZone: browserZone,
				};
			} );

		try {
			setSettings( {
				...originalSettings,
				timezone: {
					...originalSettings.timezone,
					string: 'Europe/Bucharest',
				},
				l10n: {
					...originalSettings.l10n,
					locale: 'row34-site-locale',
					months,
				},
			} );

			// 2026-05-31 22:30 UTC is May 31 in the browser zone and June 1 01:30 on the site.
			render(
				<WooPaymentsTransactionTimeline
					events={ [
						{ type: 'started', datetime: 1780266600 },
						{ type: 'started', datetime: 1780290000 },
					] }
				/>
			);

			expect(
				Array.from(
					document.querySelectorAll(
						'.woocommerce-timeline-group__title'
					)
				).map( ( title ) => title.textContent )
			).toEqual( [ 'SiteJune 1, 2026' ] );
			expect(
				Array.from(
					document.querySelectorAll(
						'.woocommerce-timeline-item__timestamp'
					)
				).map( ( time ) => time.textContent )
			).toEqual( [ '8:00am', '1:30am' ] );
		} finally {
			browserZoneSpy.mockRestore();
			setSettings( originalSettings );
		}
	} );

	it( 'uses a fixed site offset and preserves timeline date precedence', () => {
		const originalSettings = getSettings();

		try {
			setSettings( {
				...originalSettings,
				timezone: {
					...originalSettings.timezone,
					string: '',
					offset: 3,
				},
			} );
			render(
				<WooPaymentsTransactionTimeline
					events={ [
						{ type: 'started', created: 1780266600 },
						{ type: 'started', datetime: 1780266600, created: 1 },
					] }
				/>
			);
			expect(
				Array.from(
					document.querySelectorAll(
						'.woocommerce-timeline-group__title'
					)
				).map( ( title ) => title.textContent )
			).toEqual( [ 'June 1, 2026' ] );
		} finally {
			setSettings( originalSettings );
		}
	} );

	it( 'keeps lines whose event dates are missing or invalid', () => {
		// Client 11.1.0 map-events.js builds every date as `new Date( event.datetime * 1000 )`.
		render(
			<WooPaymentsTransactionTimeline
				events={ [
					{ type: 'started', datetime: 'invalid' },
					{ type: 'started' },
				] }
			/>
		);

		expect( getTimelineHeadlines() ).toEqual( [
			'Payment status changed to Started.',
			'Payment status changed to Started.',
		] );
	} );
} );
