/**
 * External dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import { useLocation, useNavigate } from 'react-router-dom';

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
import { runWooPaymentsExport } from './money-movement/export';
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
	getListShowFilter,
	getQueryForShowFilter,
	withListShowFilter,
	WooPaymentsListFilters,
	type WooPaymentsListFilter,
	type WooPaymentsListShowFilter,
} from './money-movement/list-filters';
import { formatCurrencyName } from './currency';
import {
	ExportButton,
	ListNotice,
	LiveStatusMessage,
} from './money-movement/table';
import {
	formatCount,
	formatExplicitCurrency,
	getErrorMessage,
} from './money-movement/utils';
import { usePersistedHiddenFields } from './money-movement/view-preferences';
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
import { getSettingsPaymentsProviderRouteUrl } from './utils';
import { WooPaymentsTestModeNotice } from './test-mode-notice';
import { SpotlightPromotion } from '../promotions/spotlight';
import './style.scss';

type PayoutsSummary = WooPaymentsDepositsSummary;

// Client 11.1.0 `deposits/filters/config.js:52-73`: "Show" all payouts or the advanced filters.
const PAYOUTS_SHOW_FILTERS: WooPaymentsListShowFilter[] = [ 'all', 'advanced' ];
const ALL_CURRENCIES = '---';

// Client 11.1.0 `deposits/list/index.tsx:41-94`, in order; its info-button `details` column is the date link here.
const PAYOUT_FIELDS = [
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
type ExportMessage = {
	text: string;
	isError?: boolean;
};

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

const getPayoutsRequestQuery = (
	query: ReturnType< typeof parseMoneyMovementQuery >
): WooPaymentsDepositsQuery => ( {
	page: query.page,
	pagesize: query.pagesize,
	sort: query.sort,
	direction: query.direction,
	match: getFirstString( query.search ),
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
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ exportMessage, setExportMessage ] =
		useState< ExportMessage | null >( null );
	const [ isExporting, setIsExporting ] = useState( false );
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_payouts_hidden_columns',
		PAYOUT_FIELDS
	);
	const location = useLocation();
	const navigate = useNavigate();
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
	const view = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( query, {
				fields: visibleFields,
				titleField: 'date',
				showTitle: false,
			} ),
		[ query, visibleFields ]
	);
	const fields = useMemo(
		() => [
			{
				id: 'date',
				label: __( 'Date', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) => (
					<a
						href={ getSettingsPaymentsProviderRouteUrl(
							`/woopayments/payouts/details?id=${ encodeURIComponent(
								item.id
							) }`
						) }
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
				render: ( { item }: { item: WooPaymentsDeposit } ) =>
					PAYOUT_TYPE_LABELS[ item.type ] || '',
			},
			{
				id: 'amount',
				label: __( 'Amount', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) =>
					formatExplicitCurrency( item.amount, item.currency ),
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
					<StatusChip
						message={ getPayoutStatusLabel( item ) }
						type={ getPayoutStatusChipType( item ) }
					/>
				),
			},
			{
				id: 'bankAccount',
				label: __( 'Bank account', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) =>
					item.bankAccount || '',
			},
			{
				id: 'bankReferenceId',
				label: __( 'Bank reference ID', 'woocommerce' ),
				enableSorting: false,
				render: ( { item }: { item: WooPaymentsDeposit } ) =>
					item.bank_reference_key ?? __( 'N/A', 'woocommerce' ),
			},
		],
		[ isAdvanced ]
	);

	useEffect( () => {
		let isMounted = true;

		const loadPayouts = async () => {
			setIsLoading( true );

			try {
				const requestQuery = getPayoutsRequestQuery( query );
				const [ response, nextSummary ] = await Promise.all( [
					getWooPaymentsDeposits( requestQuery ),
					getWooPaymentsDepositsSummary( requestQuery ),
				] );

				if ( ! isMounted ) {
					return;
				}

				setPayouts( response.data || [] );
				setTotalCount( response.total_count || 0 );
				setSummary( nextSummary );
				setErrorMessage( null );
			} catch ( error ) {
				if ( isMounted ) {
					setErrorMessage(
						getErrorMessage(
							error,
							__(
								'Unable to load WooPayments payout history.',
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

		void loadPayouts();

		return () => {
			isMounted = false;
		};
	}, [ query ] );

	const navigateTo = (
		nextQuery: WooPaymentsMoneyMovementQuery,
		nextShowFilter = showFilter
	) =>
		navigate(
			withListShowFilter(
				buildMoneyMovementRoutePath(
					'/woopayments/payouts',
					nextQuery
				),
				nextShowFilter
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
	const handleExport = async () => {
		setIsExporting( true );
		setExportMessage( null );

		try {
			const requestQuery = getPayoutsRequestQuery( query );

			await runWooPaymentsExport( {
				requestExport: () =>
					requestWooPaymentsDepositsExport( requestQuery ),
				getExportUrl: getWooPaymentsDepositsExportUrl,
			} );
			setExportMessage( {
				text: __(
					'Your payouts export has started downloading.',
					'woocommerce'
				),
			} );
		} catch ( error ) {
			setExportMessage( {
				text: getErrorMessage(
					error,
					__( 'Unable to export WooPayments payouts.', 'woocommerce' )
				),
				isError: true,
			} );
		} finally {
			setIsExporting( false );
		}
	};
	let liveStatusMessage: string = __(
		'Payout history loaded.',
		'woocommerce'
	);

	if ( errorMessage ) {
		liveStatusMessage = errorMessage;
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
			<SpotlightPromotion />
			<WooPaymentsListFilters filters={ listFilters } />
			<section aria-busy={ isLoading }>
				<LiveStatusMessage isError={ !! errorMessage }>
					{ liveStatusMessage }
				</LiveStatusMessage>
				{ errorMessage && (
					<ListNotice isError isSpoken={ false }>
						{ errorMessage }
					</ListNotice>
				) }
				{ exportMessage && (
					<ListNotice isError={ !! exportMessage.isError }>
						{ exportMessage.text }
					</ListNotice>
				) }
				<WooPaymentsMoneyMovementDataViews
					fields={ fields }
					rows={ payouts }
					view={ view }
					onChangeView={ handleViewChange }
					total={ totalCount || payouts.length }
					isLoading={ isLoading }
					// Client 11.1.0 `deposits/list/index.tsx:291-315`: the payouts card has no search.
					search={ false }
					searchLabel={ __( 'Search payouts', 'woocommerce' ) }
					title={ __( 'Payout history', 'woocommerce' ) }
					summary={ summaryItems }
					// Client 11.1.0 `deposits/list/index.tsx:67-72`.
					numericFields={ [ 'amount' ] }
					empty={ __( 'No payouts found.', 'woocommerce' ) }
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
