/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import { useLocation, useNavigate } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsDisputes,
	getWooPaymentsDisputesExportUrl,
	getWooPaymentsDisputesSummary,
	requestWooPaymentsDisputesExport,
} from './data';
import type {
	WooPaymentsDispute,
	WooPaymentsMoneyMovementDataView,
	WooPaymentsPaymentOrder,
} from './types';
import { isDisputeActionable } from './dispute-evidence-fields';
import {
	buildMoneyMovementRoutePath,
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	parseMoneyMovementQuery,
} from './query';
import { WooPaymentsMoneyMovementDataViews } from './dataviews';
import { runWooPaymentsExport } from './export';
import {
	formatAmount,
	formatDateTime,
	formatExplicitCurrency,
	formatDisputeReasonLabel,
	formatLabel,
	getDisputeId,
	getErrorMessage,
	getTransactionDetailsRoute,
	getTransactionSourceLabel,
} from './utils';
import { OrderLink } from './transactions-list-fields';
import { LiveStatusMessage, StatusMessage } from './table';
import { usePersistedHiddenFields } from './view-preferences';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';
import { SpotlightPromotion } from '../../promotions/spotlight';
import '../style.scss';

type DisputesSummary = Record< string, unknown >;

/**
 * A disputes list row: the platform's cached dispute plus the order context
 * the REST controller adds (`WooPaymentsMoneyMovementOrderService`).
 */
type WooPaymentsDisputeListRow = WooPaymentsDispute & {
	source?: string | null;
	customer_country?: string | null;
	due_by?: string | null;
	order?: WooPaymentsPaymentOrder | null;
};

// Client 11.1.0 `disputes/index.tsx:50-148`, in order; its info-button `details` column is the row link here.
const DISPUTE_FIELDS = [
	'amount',
	'currency',
	'status',
	'reason',
	'source',
	'order',
	'customerName',
	'customerEmail',
	'customerCountry',
	'created',
	'due_by',
	'action',
];
// The platform sorts on `due_by`; the client's column key is `dueBy`.
const DISPUTE_COLUMN_KEYS = { due_by: 'dueBy' };
// The client's columns with `visible: false`.
const DISPUTE_DEFAULT_HIDDEN_COLUMNS = [
	'currency',
	'customerEmail',
	'customerCountry',
	'created',
];
const HOUR_IN_MS = 60 * 60 * 1000;

// The cache stores UTC `Y-m-d H:i:s` strings, as the client's `moment.utc()` reads them.
const parseUtcDate = ( value: string ) =>
	new Date(
		/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test( value )
			? `${ value.replace( ' ', 'T' ) }Z`
			: value
	);

/**
 * The client's Respond by cell: nothing unless a response is due, a countdown
 * inside 72 hours, and the due date otherwise.
 * Client 11.1.0 `disputes/index.tsx:158-192` `smartDueDate()`.
 *
 * @param dispute The dispute row.
 * @param now     The current time in milliseconds.
 */
export const getDisputeRespondBy = (
	dispute: WooPaymentsDisputeListRow,
	now = Date.now()
) => {
	if ( ! dispute.due_by || ! isDisputeActionable( dispute ) ) {
		return '';
	}

	const dueBy = parseUtcDate( dispute.due_by );
	const diff = dueBy.getTime() - now;
	const diffHours = Math.trunc( diff / HOUR_IN_MS );

	if ( Number.isNaN( diff ) || diffHours <= 0 ) {
		return '';
	}

	const diffDays = Math.trunc( diff / ( 24 * HOUR_IN_MS ) );

	if ( diffHours <= 72 ) {
		return (
			<span className="woocommerce-woopayments-money-movement__chip is-alert">
				{ diffHours <= 24
					? __( 'Last day today', 'woocommerce' )
					: sprintf(
							/* translators: %d: number of days left to respond to the dispute. */
							_n(
								'%d day left',
								'%d days left',
								diffDays,
								'woocommerce'
							),
							diffDays
					  ) }
			</span>
		);
	}

	return formatDateTime( dueBy.toISOString() );
};

type ExportMessage = {
	text: string;
	isError?: boolean;
};

const getSummaryCount = ( summary: DisputesSummary ) => {
	const count = summary.total_count || summary.count;

	return typeof count === 'number' ? count : undefined;
};

const getSummaryTotal = ( summary: DisputesSummary ) => {
	const total = summary.total || summary.gross;

	return typeof total === 'number' ? total : undefined;
};

const getSummaryCurrency = ( summary: DisputesSummary ) =>
	typeof summary.currency === 'string' ? summary.currency : undefined;

export const WooPaymentsDisputesPage = () => {
	const [ disputes, setDisputes ] = useState< WooPaymentsDisputeListRow[] >(
		[]
	);
	const [ totalCount, setTotalCount ] = useState( 0 );
	const [ summary, setSummary ] = useState< DisputesSummary >( {} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ exportMessage, setExportMessage ] =
		useState< ExportMessage | null >( null );
	const [ isExporting, setIsExporting ] = useState( false );
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_disputes_hidden_columns',
		DISPUTE_FIELDS,
		DISPUTE_COLUMN_KEYS,
		DISPUTE_DEFAULT_HIDDEN_COLUMNS
	);
	const location = useLocation();
	const navigate = useNavigate();
	const query = useMemo(
		() =>
			parseMoneyMovementQuery( location.search, {
				page: 1,
				pagesize: 25,
				sort: 'created',
				direction: 'desc',
			} ),
		[ location.search ]
	);
	const view = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( query, {
				fields: visibleFields,
				titleField: 'reason',
				showTitle: false,
			} ),
		[ query, visibleFields ]
	);
	const fields = useMemo(
		() => [
			{
				id: 'amount',
				label: __( 'Amount', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					formatExplicitCurrency( item.amount, item.currency ),
			},
			{
				id: 'currency',
				label: __( 'Currency', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					item.currency || '-',
			},
			{
				id: 'status',
				label: __( 'Status', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					formatLabel( item.status ),
			},
			{
				id: 'reason',
				label: __( 'Reason', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					formatDisputeReasonLabel( item.reason ),
			},
			{
				id: 'source',
				label: __( 'Source', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					item.source
						? getTransactionSourceLabel( item.source )
						: '-',
			},
			{
				id: 'order',
				label: __( 'Order #', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<OrderLink order={ item.order } />
				),
			},
			{
				id: 'customerName',
				label: __( 'Customer', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					// The client links an empty name too; an empty link has no accessible name.
					item.order?.customer_url && item.customer_name ? (
						<a href={ item.order.customer_url }>
							{ item.customer_name }
						</a>
					) : (
						item.customer_name || '-'
					),
			},
			{
				id: 'customerEmail',
				label: __( 'Email', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					item.customer_email || '-',
			},
			{
				id: 'customerCountry',
				label: __( 'Country', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					item.customer_country || '-',
			},
			{
				id: 'created',
				label: __( 'Disputed on', 'woocommerce' ),
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					formatDateTime( item.created || item.date ),
			},
			{
				id: 'due_by',
				label: __( 'Respond by', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) =>
					getDisputeRespondBy( item ),
			},
			{
				id: 'action',
				label: __( 'Action', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => {
					const id = getDisputeId( item );
					const isActionable = isDisputeActionable( item );
					const reasonLabel = formatDisputeReasonLabel( item.reason );
					const rowHref = getSettingsPaymentsProviderRouteUrl(
						getTransactionDetailsRoute( item )
					);
					const ariaLabel = isActionable
						? sprintf(
								/* translators: 1: dispute reason, 2: dispute ID. */
								__(
									'Respond now to %1$s dispute %2$s from transaction details',
									'woocommerce'
								),
								reasonLabel.toLowerCase(),
								id
						  )
						: sprintf(
								/* translators: 1: dispute reason, 2: dispute ID. */
								__(
									'View transaction details for %1$s dispute %2$s',
									'woocommerce'
								),
								reasonLabel,
								id
						  );
					const action = isActionable
						? 'respond_from_transaction_details'
						: 'view_transaction';

					return (
						<a
							href={ rowHref }
							aria-label={ ariaLabel }
							onClick={ () =>
								recordEvent(
									'wcpay_disputes_row_action_click',
									{
										action,
										dispute_id: id,
									}
								)
							}
						>
							{ isActionable
								? __( 'Respond now', 'woocommerce' )
								: __( 'See details', 'woocommerce' ) }
						</a>
					);
				},
			},
		],
		[]
	);

	useEffect( () => {
		recordEvent( 'page_view', {
			path: 'payments_disputes',
		} );
	}, [] );

	useEffect( () => {
		let isMounted = true;

		const loadDisputes = async () => {
			setIsLoading( true );

			try {
				const [ response, nextSummary ] = await Promise.all( [
					getWooPaymentsDisputes( query ),
					getWooPaymentsDisputesSummary( query ),
				] );

				if ( isMounted ) {
					setDisputes( response.data || [] );
					setTotalCount( response.total_count || 0 );
					setSummary( nextSummary );
					setErrorMessage( null );
				}
			} catch ( error ) {
				if ( isMounted ) {
					setErrorMessage(
						getErrorMessage(
							error,
							__(
								'Unable to load WooPayments disputes.',
								'woocommerce'
							)
						)
					);
				}
			} finally {
				if ( isMounted ) {
					setIsLoading( false );
				}
			}
		};

		void loadDisputes();

		return () => {
			isMounted = false;
		};
	}, [ query ] );

	const handleViewChange = ( nextView: WooPaymentsMoneyMovementDataView ) => {
		saveFields( nextView.fields );
		navigate(
			buildMoneyMovementRoutePath(
				'/woopayments/disputes',
				dataViewsViewToMoneyMovementQuery( nextView, query )
			)
		);
	};
	const handleExport = async () => {
		setIsExporting( true );
		setExportMessage( null );

		try {
			await runWooPaymentsExport( {
				requestExport: () => requestWooPaymentsDisputesExport( query ),
				getExportUrl: getWooPaymentsDisputesExportUrl,
			} );
			setExportMessage( {
				text: __(
					'Your disputes export has started downloading.',
					'woocommerce'
				),
			} );
		} catch ( error ) {
			setExportMessage( {
				text: getErrorMessage(
					error,
					__(
						'Unable to export WooPayments disputes.',
						'woocommerce'
					)
				),
				isError: true,
			} );
		} finally {
			setIsExporting( false );
		}
	};
	let liveStatusMessage: string = __( 'Disputes loaded.', 'woocommerce' );

	if ( errorMessage ) {
		liveStatusMessage = errorMessage;
	} else if ( isLoading ) {
		liveStatusMessage = __( 'Loading disputes…', 'woocommerce' );
	} else if ( disputes.length === 0 ) {
		liveStatusMessage = __( 'No disputes found.', 'woocommerce' );
	}

	const summaryCount = getSummaryCount( summary ) ?? totalCount;
	const summaryTotal = getSummaryTotal( summary );
	const summaryCurrency = getSummaryCurrency( summary );

	return (
		<div className="woocommerce-woopayments-money-movement">
			{ /* Client 11.1.0 disputes/index.tsx:451. */ }
			<WooPaymentsTestModeNotice currentPage="disputes" />
			<SpotlightPromotion />
			<section aria-busy={ isLoading }>
				<h2>{ __( 'Disputes', 'woocommerce' ) }</h2>
				<LiveStatusMessage isError={ !! errorMessage }>
					{ liveStatusMessage }
				</LiveStatusMessage>
				{ isLoading && (
					<StatusMessage>
						{ __( 'Loading disputes…', 'woocommerce' ) }
					</StatusMessage>
				) }
				{ errorMessage && (
					<StatusMessage isError>{ errorMessage }</StatusMessage>
				) }
				<div className="woocommerce-woopayments-money-movement__summary">
					<span>
						{ sprintf(
							/* translators: %d: disputes count. */
							__( '%d disputes', 'woocommerce' ),
							summaryCount
						) }
					</span>
					{ typeof summaryTotal === 'number' && (
						<span>
							{ formatAmount( summaryTotal, summaryCurrency ) }
						</span>
					) }
				</div>
				{ exportMessage && (
					<StatusMessage isLive isError={ !! exportMessage.isError }>
						{ exportMessage.text }
					</StatusMessage>
				) }
				<WooPaymentsMoneyMovementDataViews
					fields={ fields }
					rows={ disputes }
					view={ view }
					onChangeView={ handleViewChange }
					total={ totalCount || disputes.length }
					isLoading={ isLoading }
					searchLabel={ __( 'Search disputes', 'woocommerce' ) }
					empty={ __( 'No disputes found.', 'woocommerce' ) }
					getItemId={ getDisputeId }
					toolbarActions={
						<Button
							variant="secondary"
							onClick={ handleExport }
							isBusy={ isExporting }
							disabled={ isExporting }
						>
							{ __( 'Download disputes', 'woocommerce' ) }
						</Button>
					}
				/>
			</section>
		</div>
	);
};
