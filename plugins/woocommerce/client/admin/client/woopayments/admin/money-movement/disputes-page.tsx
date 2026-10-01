/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

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
	WooPaymentsMoneyMovementQuery,
	WooPaymentsPaymentOrder,
} from './types';
import {
	ACTIONABLE_DISPUTE_STATUSES,
	isDisputeActionable,
} from './dispute-evidence-fields';
import { DISPUTE_STATUS_LABELS } from './dispute-utils';
import {
	buildMoneyMovementRoutePath,
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	normalizeDateFiltersForApi,
	parseMoneyMovementQuery,
} from './query';
import {
	getListMatch,
	getListMatchFilter,
	getListShowFilter,
	getQueryForShowFilter,
	withListShowFilter,
	WooPaymentsListFilters,
	type WooPaymentsListFilter,
	type WooPaymentsListMatch,
	type WooPaymentsListShowFilter,
} from './list-filters';
import { WooPaymentsMoneyMovementDataViews } from './dataviews';
import { runWooPaymentsExport } from './export';
import {
	formatCount,
	formatDisputeReasonLabel,
	formatExplicitCurrency,
	formatLabel,
	formatSiteDateTime,
	getDisputeId,
	getErrorMessage,
	getTransactionDetailsRoute,
} from './utils';
import {
	ClickableCell,
	DETAILS_FIELD_BASE,
	DetailsLink,
	OrderLink,
	PaymentSource,
} from './transactions-list-fields';
import {
	ExportButton,
	ListNotice,
	LiveStatusMessage,
	reportListLoadError,
} from './table';
import { usePersistedHiddenFields } from './view-preferences';
import {
	getSettingsPaymentsProviderRouteUrl,
	navigateToSettingsPaymentsProviderRoute,
} from '../utils';
import { formatCurrencyName } from '../currency';
import {
	StatusChip,
	type StatusChipType,
} from '../overview/components/status-chip';
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

// Client 11.1.0 `disputes/index.tsx:50-148`, in order.
const DISPUTE_FIELDS = [
	'details',
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

// The "Show" choices. Client 11.1.0 `disputes/filters/config.ts:78-99`.
const DISPUTES_SHOW_FILTERS: WooPaymentsListShowFilter[] = [
	'awaiting_response',
	'all',
	'advanced',
];
const ALL_CURRENCIES = '---';

/**
 * The disputes API query: day boundaries for the date filters, and "Needs
 * response" as a search for the two statuses awaiting a response.
 * Client 11.1.0 `data/disputes/resolvers.js:25-41` `formatQueryFilters()`.
 *
 * @param query      The list query.
 * @param showFilter The "Show" choice.
 */
export const getDisputesApiQuery = (
	query: WooPaymentsMoneyMovementQuery,
	showFilter: WooPaymentsListShowFilter,
	match: WooPaymentsListMatch = 'all'
): WooPaymentsMoneyMovementQuery => {
	const apiQuery = normalizeDateFiltersForApi( query );

	if ( showFilter === 'awaiting_response' ) {
		apiQuery.search = [ ...ACTIONABLE_DISPUTE_STATUSES ];
	}

	if ( showFilter === 'advanced' && match === 'any' ) {
		apiQuery.match = 'any';
	}

	return apiQuery;
};

const buildDisputesRoute = (
	query: WooPaymentsMoneyMovementQuery,
	showFilter: WooPaymentsListShowFilter,
	match: WooPaymentsListMatch = 'all'
) =>
	withListShowFilter(
		buildMoneyMovementRoutePath( '/woopayments/disputes', query ),
		showFilter,
		match
	);

// Client 11.1.0 `components/dispute-status-chip`: colours per status, red for any awaiting a response.
const DISPUTE_STATUS_CHIP_TYPES: Record< string, StatusChipType > = {
	warning_needs_response: 'error',
	warning_under_review: 'primary',
	warning_closed: 'info',
	needs_response: 'error',
	under_review: 'primary',
	charge_refunded: 'info',
	won: 'success',
	lost: 'info',
};

const DISPUTE_STATUS_FILTER_ELEMENTS = Object.entries(
	DISPUTE_STATUS_LABELS
).map( ( [ value, label ] ) => ( { value, label } ) );

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
			<StatusChip
				type="error"
				message={
					diffHours <= 24
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
						  )
				}
			/>
		);
	}

	return formatSiteDateTime( dueBy.toISOString() );
};

// Client 11.1.0 `disputes/index.tsx:217-238` `onClickDisputeRow`: the Tracks event of a row click.
const recordDisputeRowClick = ( dispute: WooPaymentsDisputeListRow ) =>
	recordEvent( 'wcpay_disputes_row_action_click', {
		dispute_id: getDisputeId( dispute ),
		dispute_status: dispute.status,
		dispute_reason: dispute.reason,
	} );

/**
 * A disputes list cell that opens the payment details.
 * Client 11.1.0 `disputes/index.tsx:212-238`: every `clickable()` cell and its row click event.
 *
 * @param props          The component props.
 * @param props.item     The dispute row.
 * @param props.children The cell content.
 */
const DisputeCell = ( {
	item,
	children,
}: {
	item: WooPaymentsDisputeListRow;
	children?: ReactNode;
} ) => (
	<ClickableCell
		href={ getSettingsPaymentsProviderRouteUrl(
			getTransactionDetailsRoute( item )
		) }
		onClick={ () => recordDisputeRowClick( item ) }
	>
		{ children }
	</ClickableCell>
);

type ExportMessage = {
	text: string;
	isError?: boolean;
};

const getSummaryCount = ( summary: DisputesSummary ) => {
	const count = summary.total_count || summary.count;

	return typeof count === 'number' ? count : undefined;
};

export const WooPaymentsDisputesPage = () => {
	const [ disputes, setDisputes ] = useState< WooPaymentsDisputeListRow[] >(
		[]
	);
	const [ totalCount, setTotalCount ] = useState( 0 );
	const [ summary, setSummary ] = useState< DisputesSummary >( {} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasLoadError, setHasLoadError ] = useState( false );
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
	const showFilter = getListShowFilter(
		location.search,
		DISPUTES_SHOW_FILTERS
	);
	const isAdvanced = showFilter === 'advanced';
	const match = getListMatch( location.search );
	const apiQuery = useMemo(
		() => getDisputesApiQuery( query, showFilter, match ),
		[ query, showFilter, match ]
	);
	const view = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( query, {
				fields: visibleFields,
				dateField: 'created',
			} ),
		[ query, visibleFields ]
	);
	const fields = useMemo(
		() => [
			{
				...DETAILS_FIELD_BASE,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DetailsLink
						href={ getSettingsPaymentsProviderRouteUrl(
							getTransactionDetailsRoute( item )
						) }
						label={ sprintf(
							/* translators: 1: dispute reason, 2: dispute ID. */
							__(
								'See details for %1$s dispute %2$s',
								'woocommerce'
							),
							formatDisputeReasonLabel( item.reason ),
							getDisputeId( item )
						) }
					/>
				),
			},
			{
				id: 'amount',
				label: __( 'Amount', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ formatExplicitCurrency( item.amount, item.currency ) }
					</DisputeCell>
				),
			},
			{
				id: 'currency',
				label: __( 'Currency', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ item.currency || '' }
					</DisputeCell>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				// Client 11.1.0 `disputes/filters/config.ts:176-217`: "Is" and "Is not" one status.
				elements: DISPUTE_STATUS_FILTER_ELEMENTS,
				filterBy: isAdvanced
					? {
							operators: [ 'is', 'isNot' ] as const,
							isPrimary: true,
					  }
					: ( false as const ),
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						<StatusChip
							message={
								DISPUTE_STATUS_LABELS[ item.status || '' ] ||
								formatLabel( item.status )
							}
							type={
								DISPUTE_STATUS_CHIP_TYPES[
									item.status || ''
								] || 'info'
							}
						/>
					</DisputeCell>
				),
			},
			{
				id: 'reason',
				label: __( 'Reason', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ formatDisputeReasonLabel( item.reason ) }
					</DisputeCell>
				),
			},
			{
				id: 'source',
				label: __( 'Source', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				// Client 11.1.0 `disputes/index.tsx:280-290`: the card brand logo.
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ item.source ? (
							<PaymentSource source={ item.source } />
						) : (
							''
						) }
					</DisputeCell>
				),
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
						<DisputeCell item={ item }>
							{ item.customer_name || '' }
						</DisputeCell>
					),
			},
			{
				id: 'customerEmail',
				label: __( 'Email', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ item.customer_email || '' }
					</DisputeCell>
				),
			},
			{
				id: 'customerCountry',
				label: __( 'Country', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ item.customer_country || '' }
					</DisputeCell>
				),
			},
			{
				id: 'created',
				label: __( 'Disputed on', 'woocommerce' ),
				type: 'date' as const,
				// Client 11.1.0 `disputes/filters/config.ts:130-175`: before, after or between dates.
				filterBy: isAdvanced
					? {
							operators: [
								'before',
								'after',
								'between',
							] as const,
							isPrimary: true,
					  }
					: ( false as const ),
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ formatSiteDateTime( item.created || item.date ) }
					</DisputeCell>
				),
			},
			{
				id: 'due_by',
				label: __( 'Respond by', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDisputeListRow } ) => (
					<DisputeCell item={ item }>
						{ getDisputeRespondBy( item ) }
					</DisputeCell>
				),
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
									'See details for %1$s dispute %2$s',
									'woocommerce'
								),
								reasonLabel,
								id
						  );

					// Client 11.1.0 `disputes/index.tsx:324-338`: a secondary "Respond" while a response
					// is due and a tertiary "See details" otherwise.
					return (
						<Button
							variant={ isActionable ? 'secondary' : 'tertiary' }
							href={ rowHref }
							aria-label={ ariaLabel }
							onClick={ () => recordDisputeRowClick( item ) }
						>
							{ isActionable
								? __( 'Respond', 'woocommerce' )
								: __( 'See details', 'woocommerce' ) }
						</Button>
					);
				},
			},
		],
		[ isAdvanced ]
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

			// Client 11.1.0 `data/disputes/resolvers.js:79-118`: the list and its summary load
			// apart, and each failure raises its own notice.
			const [ listResult, summaryResult ] = await Promise.allSettled( [
				getWooPaymentsDisputes( apiQuery ),
				getWooPaymentsDisputesSummary( apiQuery ),
			] );

			if ( ! isMounted ) {
				return;
			}

			if ( listResult.status === 'rejected' ) {
				reportListLoadError(
					__( 'Error retrieving disputes.', 'woocommerce' )
				);
			}

			if ( summaryResult.status === 'rejected' ) {
				reportListLoadError(
					__(
						'Error retrieving the summary of disputes.',
						'woocommerce'
					)
				);
			}

			const response =
				listResult.status === 'fulfilled'
					? listResult.value
					: undefined;

			setDisputes( response?.data || [] );
			setTotalCount( response?.total_count || 0 );
			setSummary(
				summaryResult.status === 'fulfilled' ? summaryResult.value : {}
			);
			setHasLoadError( listResult.status === 'rejected' );
			setIsLoading( false );
		};

		void loadDisputes();

		return () => {
			isMounted = false;
		};
	}, [ apiQuery ] );

	const handleViewChange = ( nextView: WooPaymentsMoneyMovementDataView ) => {
		saveFields( nextView.fields );

		// DataViews adds a filter without a value first; wait for the value.
		if (
			nextView.filters?.some( ( filter ) => filter.value === undefined )
		) {
			return;
		}

		navigateToSettingsPaymentsProviderRoute(
			buildDisputesRoute(
				dataViewsViewToMoneyMovementQuery( nextView, query, 'created' ),
				showFilter,
				match
			)
		);
	};
	// Client 11.1.0 FilterPicker `update()`: another "Show" choice keeps the page, sort, search
	// and currency, and anything but "Advanced filters" drops the advanced filters.
	const handleShowFilterChange = ( value: string ) => {
		const nextFilter = getListShowFilter(
			`filter=${ value }`,
			DISPUTES_SHOW_FILTERS
		);

		navigateToSettingsPaymentsProviderRoute(
			buildDisputesRoute(
				getQueryForShowFilter( query, nextFilter ),
				nextFilter
			)
		);
	};
	const handleCurrencyChange = ( value: string ) => {
		const nextQuery = { ...query };

		if ( value === ALL_CURRENCIES ) {
			delete nextQuery.store_currency_is;
		} else {
			nextQuery.store_currency_is = value;
		}

		navigateToSettingsPaymentsProviderRoute(
			buildDisputesRoute( nextQuery, showFilter, match )
		);
	};
	const handleExport = async () => {
		setIsExporting( true );
		setExportMessage( null );

		try {
			await runWooPaymentsExport( {
				requestExport: () =>
					requestWooPaymentsDisputesExport( apiQuery ),
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

	if ( hasLoadError ) {
		// The error notice announces itself.
		liveStatusMessage = '';
	} else if ( isLoading ) {
		liveStatusMessage = __( 'Loading disputes…', 'woocommerce' );
	} else if ( disputes.length === 0 ) {
		liveStatusMessage = __( 'No disputes found.', 'woocommerce' );
	}

	const summaryCount = getSummaryCount( summary );
	// Client 11.1.0 `disputes/index.tsx:497-517`: the footer summary is the count, once loaded.
	const summaryItems: Array< { label: string; value: string } > =
		! isLoading && summaryCount !== undefined
			? [
					{
						label: _n(
							'dispute',
							'disputes',
							summaryCount,
							'woocommerce'
						),
						value: formatCount( summaryCount ),
					},
			  ]
			: [];
	const currencyFilter =
		typeof query.store_currency_is === 'string'
			? query.store_currency_is
			: undefined;
	// Client 11.1.0 `disputes/index.tsx:519-523` and `disputes/filters/index.tsx:22-40`:
	// the currency select shows when the summary lists more than one currency.
	let storeCurrencies: string[] = [];

	if ( Array.isArray( summary.currencies ) ) {
		storeCurrencies = summary.currencies.map( String );
	} else if ( currencyFilter ) {
		storeCurrencies = [ currencyFilter ];
	}

	const listFilters: WooPaymentsListFilter[] = [
		{
			id: 'show',
			label: __( 'Show', 'woocommerce' ),
			value: showFilter,
			options: [
				{
					label: __( 'Needs response', 'woocommerce' ),
					value: 'awaiting_response',
				},
				{ label: __( 'All disputes', 'woocommerce' ), value: 'all' },
				{
					label: __( 'Advanced filters', 'woocommerce' ),
					value: 'advanced',
				},
			],
			onChange: handleShowFilterChange,
		},
	];

	if ( isAdvanced ) {
		listFilters.push(
			getListMatchFilter(
				__( 'Disputes match', 'woocommerce' ),
				match,
				( value ) =>
					navigateToSettingsPaymentsProviderRoute(
						buildDisputesRoute(
							query,
							showFilter,
							value === 'any' ? 'any' : 'all'
						)
					)
			)
		);
	}

	if ( storeCurrencies.length > 1 ) {
		listFilters.unshift( {
			id: 'currency',
			label: __( 'Dispute currency', 'woocommerce' ),
			value: currencyFilter || ALL_CURRENCIES,
			options: [
				{
					label: __( 'All currencies', 'woocommerce' ),
					value: ALL_CURRENCIES,
				},
				...storeCurrencies.map( ( currency ) => ( {
					label: formatCurrencyName( currency ),
					value: currency,
				} ) ),
			],
			onChange: handleCurrencyChange,
		} );
	}

	return (
		<div className="woocommerce-woopayments-money-movement">
			{ /* Client 11.1.0 disputes/index.tsx:451. */ }
			<WooPaymentsTestModeNotice currentPage="disputes" />
			<SpotlightPromotion />
			<WooPaymentsListFilters filters={ listFilters } />
			<section aria-busy={ isLoading }>
				<LiveStatusMessage>{ liveStatusMessage }</LiveStatusMessage>
				{ exportMessage && (
					<ListNotice isError={ !! exportMessage.isError }>
						{ exportMessage.text }
					</ListNotice>
				) }
				<WooPaymentsMoneyMovementDataViews
					fields={ fields }
					rows={ disputes }
					view={ view }
					onChangeView={ handleViewChange }
					total={ totalCount || disputes.length }
					isLoading={ isLoading }
					// Client 11.1.0 `disputes/index.tsx:527-551`: the disputes card has no search.
					search={ false }
					searchLabel={ __( 'Search disputes', 'woocommerce' ) }
					title={ __( 'Disputes', 'woocommerce' ) }
					summary={ summaryItems }
					// Client 11.1.0 `disputes/index.tsx:139-146`: the action column is the numeric one.
					numericFields={ [ 'action' ] }
					getItemId={ getDisputeId }
					toolbarActions={
						// Client 11.1.0 `disputes/index.tsx:370, :539-548`: Export only with rows.
						disputes.length > 0 && (
							<ExportButton
								onClick={ handleExport }
								isBusy={ isExporting }
								disabled={ isLoading || isExporting }
							/>
						)
					}
				/>
			</section>
		</div>
	);
};
