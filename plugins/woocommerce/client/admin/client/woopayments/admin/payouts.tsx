/**
 * External dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import type { ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import '../../settings-payments/settings-payments-body.scss';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsExportUrl,
	getWooPaymentsDepositsSummary,
	requestWooPaymentsDepositsExport,
} from './overview/data';
import { WooPaymentsMoneyMovementDataViews } from './money-movement/dataviews';
import {
	confirmWooPaymentsExport,
	runWooPaymentsExport,
} from './money-movement/export';
import {
	buildMoneyMovementRoutePath,
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	parseMoneyMovementQuery,
} from './money-movement/query';
import type {
	WooPaymentsMoneyMovementDataView,
	WooPaymentsMoneyMovementQuery,
} from './money-movement/types';
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
} from './money-movement/list-filters';
import { formatCurrencyName } from './currency';
import {
	ExportButton,
	LiveStatusMessage,
	reportListLoadError,
} from './money-movement/table';
import { formatCount, formatExplicitCurrency } from './money-movement/utils';
import { usePersistedHiddenFields } from './money-movement/view-preferences';
import {
	ClickableCell,
	DETAILS_FIELD_BASE,
	DetailsLink,
} from './money-movement/transactions-list-fields';
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsQuery,
	WooPaymentsDepositsSummary,
} from './overview/types';
import { formatPayoutSiteDate } from './overview/utils';
import {
	getPayoutStatusChipType,
	getPayoutStatusLabel,
	PAYOUT_STATUS_FILTER_ELEMENTS,
} from './payout-status';
import { StatusChip } from './overview/components/status-chip';
import {
	getSettingsPaymentsProviderRouteUrl,
	navigateToSettingsPaymentsProviderRoute,
} from './utils';
import { WooPaymentsTestModeNotice } from './test-mode-notice';
import { WooPaymentsPayoutsNotices } from './payouts-notices';
import { SpotlightPromotion } from '../promotions/spotlight';
import './style.scss';

type PayoutsSummary = WooPaymentsDepositsSummary;

// Client 11.1.0 `deposits/filters/config.js:52-73`: "Show" all payouts or the advanced filters.
const PAYOUTS_SHOW_FILTERS: WooPaymentsListShowFilter[] = [ 'all', 'advanced' ];
const ALL_CURRENCIES = '---';

// Client 11.1.0 `deposits/list/index.tsx:41-94`, in order.
const PAYOUT_FIELDS = [
	'details',
	'date',
	'type',
	'amount',
	'status',
	'bankAccount',
	'bankReferenceId',
];

// Client 11.1.0 `deposits/strings.ts` `displayType`.
const PAYOUT_TYPE_LABELS: Record< string, string > = {
	deposit: __( 'Payout', 'woocommerce' ),
	withdrawal: __( 'Withdrawal', 'woocommerce' ),
};
const getPayoutDetailsUrl = ( payout: WooPaymentsDeposit ) =>
	getSettingsPaymentsProviderRouteUrl(
		`/woopayments/payouts/details?id=${ encodeURIComponent( payout.id ) }`
	);

/**
 * A payouts list cell that opens the payout details.
 * Client 11.1.0 `deposits/list/index.tsx:107-115`: every `clickable()` cell and its row click event.
 *
 * @param props          The component props.
 * @param props.item     The payout row.
 * @param props.children The cell content.
 */
const PayoutCell = ( {
	item,
	children,
}: {
	item: WooPaymentsDeposit;
	children?: ReactNode;
} ) => (
	<ClickableCell
		href={ getPayoutDetailsUrl( item ) }
		onClick={ () => recordEvent( 'wcpay_deposits_row_click' ) }
	>
		{ children }
	</ClickableCell>
);

const getSummaryCount = ( summary: PayoutsSummary ) => {
	const count = summary.count;

	return typeof count === 'number' ? count : undefined;
};

const getSummaryTotal = ( summary: PayoutsSummary ) =>
	typeof summary.total === 'number' ? summary.total : undefined;

const getSummaryCurrency = ( summary: PayoutsSummary ) =>
	typeof summary.currency === 'string' ? summary.currency : undefined;

const getFirstString = ( value: unknown ) => {
	if ( Array.isArray( value ) ) {
		return value.find( ( item ) => typeof item === 'string' && item );
	}

	return typeof value === 'string' && value ? value : undefined;
};

// Client 11.1.0 `data/deposits/resolvers.js:76-89`: `match` is the advanced filters' "all or any".
const getPayoutsRequestQuery = (
	query: ReturnType< typeof parseMoneyMovementQuery >,
	match: WooPaymentsListMatch
): WooPaymentsDepositsQuery => ( {
	page: query.page,
	pagesize: query.pagesize,
	sort: query.sort,
	direction: query.direction,
	match: match === 'any' ? match : undefined,
	store_currency_is: getFirstString( query.store_currency_is ),
	status_is: getFirstString( query.status_is ),
	status_is_not: getFirstString( query.status_is_not ),
	date_after: getFirstString( query.date_after ),
	date_before: getFirstString( query.date_before ),
	date_between: getFirstString( query.date_between ),
} );

export const WooPaymentsPayouts = () => {
	const [ payouts, setPayouts ] = useState< WooPaymentsDeposit[] >( [] );
	const [ totalCount, setTotalCount ] = useState( 0 );
	const [ summary, setSummary ] = useState< PayoutsSummary >( {} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasLoadError, setHasLoadError ] = useState( false );
	const [ isExporting, setIsExporting ] = useState( false );
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_payouts_hidden_columns',
		PAYOUT_FIELDS
	);
	const location = useLocation();
	const query = useMemo(
		() =>
			parseMoneyMovementQuery( location.search, {
				page: 1,
				pagesize: 25,
				sort: 'date',
				direction: 'desc',
			} ),
		[ location.search ]
	);
	const showFilter = getListShowFilter(
		location.search,
		PAYOUTS_SHOW_FILTERS
	);
	const isAdvanced = showFilter === 'advanced';
	const match = isAdvanced ? getListMatch( location.search ) : 'all';
	const view = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( query, {
				fields: visibleFields,
			} ),
		[ query, visibleFields ]
	);
	const fields = useMemo(
		() => [
			{
				...DETAILS_FIELD_BASE,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<DetailsLink
						href={ getPayoutDetailsUrl( item ) }
						label={ sprintf(
							/* translators: %s: payout ID. */
							__( 'See details for payout %s', 'woocommerce' ),
							item.id
						) }
					/>
				),
			},
			{
				id: 'date',
				label: __( 'Date', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<a
						href={ getPayoutDetailsUrl( item ) }
						// Client 11.1.0 `deposits/list/index.tsx:116-121`.
						onClick={ () =>
							recordEvent( 'wcpay_deposits_row_click' )
						}
					>
						{ formatPayoutSiteDate( item ) }
						<span className="screen-reader-text">
							{ sprintf(
								/* translators: %s: payout ID. */
								__(
									'- view payout details for %s',
									'woocommerce'
								),
								item.id
							) }
						</span>
					</a>
				),
			},
			{
				id: 'type',
				label: __( 'Type', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<PayoutCell item={ item }>
						{ PAYOUT_TYPE_LABELS[ item.type ] || '' }
					</PayoutCell>
				),
			},
			{
				id: 'amount',
				label: __( 'Amount', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<PayoutCell item={ item }>
						{ formatExplicitCurrency( item.amount, item.currency ) }
					</PayoutCell>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'woocommerce' ),
				enableHiding: false,
				enableSorting: false,
				// Client 11.1.0 `deposits/filters/config.js:143-181`: "Is" and "Is not" one status.
				elements: PAYOUT_STATUS_FILTER_ELEMENTS,
				filterBy: isAdvanced
					? {
							operators: [ 'is', 'isNot' ] as const,
							isPrimary: true,
					  }
					: ( false as const ),
				// Client 11.1.0 `deposits/list/index.tsx:131` renders `DepositStatusChip`.
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<PayoutCell item={ item }>
						<StatusChip
							message={ getPayoutStatusLabel( item ) }
							type={ getPayoutStatusChipType( item ) }
						/>
					</PayoutCell>
				),
			},
			{
				id: 'bankAccount',
				label: __( 'Bank account', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<PayoutCell item={ item }>
						{ item.bankAccount || '' }
					</PayoutCell>
				),
			},
			{
				id: 'bankReferenceId',
				label: __( 'Bank reference ID', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<PayoutCell item={ item }>
						{ item.bank_reference_key ??
							__( 'N/A', 'woocommerce' ) }
					</PayoutCell>
				),
			},
		],
		[ isAdvanced ]
	);

	useEffect( () => {
		let isMounted = true;

		const loadPayouts = async () => {
			setIsLoading( true );

			// Client 11.1.0 `data/deposits/resolvers.js:117-155`: the list and its summary load
			// apart; a failed list raises a notice, a failed summary leaves the footer out.
			const requestQuery = getPayoutsRequestQuery( query, match );
			const [ listResult, summaryResult ] = await Promise.allSettled( [
				getWooPaymentsDeposits( requestQuery ),
				getWooPaymentsDepositsSummary( requestQuery ),
			] );

			if ( ! isMounted ) {
				return;
			}

			if ( listResult.status === 'rejected' ) {
				reportListLoadError(
					__( 'Error retrieving payouts.', 'woocommerce' )
				);
			}

			const response =
				listResult.status === 'fulfilled'
					? listResult.value
					: undefined;

			setPayouts( response?.data || [] );
			setTotalCount( response?.total_count || 0 );
			setSummary(
				summaryResult.status === 'fulfilled' ? summaryResult.value : {}
			);
			setHasLoadError( listResult.status === 'rejected' );
			setIsLoading( false );
		};

		void loadPayouts();

		return () => {
			isMounted = false;
		};
	}, [ query, match ] );

	const navigateTo = (
		nextQuery: WooPaymentsMoneyMovementQuery,
		nextShowFilter = showFilter,
		nextMatch: WooPaymentsListMatch = match
	) =>
		navigateToSettingsPaymentsProviderRoute(
			withListShowFilter(
				buildMoneyMovementRoutePath(
					'/woopayments/payouts',
					nextQuery
				),
				nextShowFilter,
				nextMatch
			)
		);
	const handleViewChange = ( nextView: WooPaymentsMoneyMovementDataView ) => {
		saveFields( nextView.fields );

		// DataViews adds a filter without a value first; wait for the value.
		if (
			nextView.filters?.some( ( filter ) => filter.value === undefined )
		) {
			return;
		}

		navigateTo( dataViewsViewToMoneyMovementQuery( nextView, query ) );
	};
	// Client 11.1.0 `deposits/list/index.tsx:215-287`: the outcome is told by snackbars only.
	const handleExport = async () => {
		if (
			! confirmWooPaymentsExport(
				'payouts',
				getSummaryCount( summary ) ?? 0,
				query
			)
		) {
			return;
		}

		const requestQuery = getPayoutsRequestQuery( query, match );

		setIsExporting( true );
		await runWooPaymentsExport( {
			requestExport: () =>
				requestWooPaymentsDepositsExport( requestQuery ),
			getExportUrl: getWooPaymentsDepositsExportUrl,
		} );
		setIsExporting( false );
	};
	let liveStatusMessage: string = __(
		'Payout history loaded.',
		'woocommerce'
	);

	if ( hasLoadError ) {
		// The error notice announces itself.
		liveStatusMessage = '';
	} else if ( isLoading ) {
		liveStatusMessage = __( 'Loading payouts…', 'woocommerce' );
	} else if ( payouts.length === 0 ) {
		liveStatusMessage = __( 'No payouts found.', 'woocommerce' );
	}

	const summaryCount = getSummaryCount( summary );
	const summaryTotal = getSummaryTotal( summary );
	// Client 11.1.0 `deposits/list/index.tsx:172-205`: the footer summary once loaded, with the
	// total for one currency or a currency filter.
	const summaryItems: Array< { label: string; value: string } > =
		! isLoading && summaryCount !== undefined && summaryTotal !== undefined
			? [
					{
						label: _n(
							'payout',
							'payouts',
							summaryCount,
							'woocommerce'
						),
						value: formatCount( summaryCount ),
					},
			  ]
			: [];

	if (
		summaryItems.length &&
		( ( summary.store_currencies || [] ).length < 2 ||
			typeof query.store_currency_is === 'string' )
	) {
		summaryItems.push( {
			label: __( 'total', 'woocommerce' ),
			value: formatExplicitCurrency(
				summaryTotal,
				getSummaryCurrency( summary )
			),
		} );
	}

	const currencyFilter =
		typeof query.store_currency_is === 'string'
			? query.store_currency_is
			: undefined;
	let storeCurrencies: string[] = [];

	// Client 11.1.0 `deposits/list/index.tsx:207-209`: the currencies to choose from.
	if ( Array.isArray( summary.store_currencies ) ) {
		storeCurrencies = summary.store_currencies.map( String );
	} else if ( currencyFilter ) {
		storeCurrencies = [ currencyFilter ];
	}

	const listFilters: WooPaymentsListFilter[] = [
		{
			id: 'show',
			label: __( 'Show', 'woocommerce' ),
			value: showFilter,
			options: [
				{ label: __( 'All payouts', 'woocommerce' ), value: 'all' },
				{
					label: __( 'Advanced filters', 'woocommerce' ),
					value: 'advanced',
				},
			],
			onChange: ( value ) => {
				const nextFilter = getListShowFilter(
					`filter=${ value }`,
					PAYOUTS_SHOW_FILTERS
				);

				navigateTo(
					getQueryForShowFilter( query, nextFilter ),
					nextFilter
				);
			},
		},
	];

	if ( isAdvanced ) {
		listFilters.push(
			getListMatchFilter(
				__( 'Payouts match', 'woocommerce' ),
				match,
				( value ) =>
					navigateTo(
						query,
						showFilter,
						value === 'any' ? 'any' : 'all'
					)
			)
		);
	}

	// Client 11.1.0 `deposits/filters/index.js`: the currency select shows for more than one currency.
	if ( storeCurrencies.length > 1 ) {
		listFilters.unshift( {
			id: 'currency',
			label: __( 'Payout currency', 'woocommerce' ),
			value: currencyFilter || ALL_CURRENCIES,
			options: [
				{ label: __( 'All', 'woocommerce' ), value: ALL_CURRENCIES },
				...storeCurrencies.map( ( currency ) => ( {
					label: formatCurrencyName( currency ),
					value: currency,
				} ) ),
			],
			onChange: ( value ) => {
				const nextQuery = { ...query };

				if ( value === ALL_CURRENCIES ) {
					delete nextQuery.store_currency_is;
				} else {
					nextQuery.store_currency_is = value;
				}

				navigateTo( nextQuery );
			},
		} );
	}

	return (
		<div className="woocommerce-woopayments-payouts">
			{ /* Client 11.1.0 deposits/index.tsx:153. */ }
			<WooPaymentsTestModeNotice currentPage="deposits" />
			{ /* Client 11.1.0 deposits/index.tsx:154-155. */ }
			<WooPaymentsPayoutsNotices />
			<SpotlightPromotion />
			<WooPaymentsListFilters filters={ listFilters } />
			<section aria-busy={ isLoading }>
				<LiveStatusMessage>{ liveStatusMessage }</LiveStatusMessage>
				<WooPaymentsMoneyMovementDataViews
					fields={ fields }
					rows={ payouts }
					view={ view }
					onChangeView={ handleViewChange }
					// Client 11.1.0 `deposits/list/index.tsx:113`: the pager counts what the summary counts.
					total={ summaryCount ?? ( totalCount || payouts.length ) }
					isLoading={ isLoading }
					// Client 11.1.0 `deposits/list/index.tsx:291-315`: the payouts card has no search.
					search={ false }
					searchLabel={ __( 'Search payouts', 'woocommerce' ) }
					title={ __( 'Payout history', 'woocommerce' ) }
					summary={ summaryItems }
					// Client 11.1.0 `deposits/list/index.tsx:67-72`.
					numericFields={ [ 'amount' ] }
					getItemId={ ( payout ) => payout.id }
					toolbarActions={
						// Client 11.1.0 `deposits/list/index.tsx:215, :303-312`: Export only with rows.
						payouts.length > 0 && (
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

export default WooPaymentsPayouts;
