/**
 * External dependencies
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n } from '@wordpress/i18n';
import { getHistory } from '@woocommerce/navigation';
import { recordEvent } from '@woocommerce/tracks';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsTransactions,
	getWooPaymentsTransactionsExportUrl,
	getWooPaymentsTransactionsSummary,
	requestWooPaymentsTransactionsExport,
} from './data';
import type {
	WooPaymentsMoneyMovementDataView,
	WooPaymentsMoneyMovementQuery,
} from './types';
import {
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	parseMoneyMovementQuery,
} from './query';
import { WooPaymentsMoneyMovementDataViews } from './dataviews';
import { WooPaymentsTransactionSearch } from './transaction-search';
import { confirmWooPaymentsExport, useWooPaymentsExport } from './export';
import {
	formatAmount,
	formatCount,
	formatExplicitCurrency,
	getResourceId,
} from './utils';
import { ExportButton, LiveStatusMessage, reportListLoadError } from './table';
import { usePersistedHiddenFields } from './view-preferences';
import {
	TRANSACTION_LIST_DEFAULT_HIDDEN_COLUMNS,
	getTransactionListFields,
	getTransactionListFilterFields,
	isWooPaymentsSubscriptionsActive,
	type WooPaymentsTransactionListRow,
} from './transactions-list-fields';
import { getSettingsPaymentsProviderAdminPath } from '../utils';
import { formatCurrencyName } from '../currency';
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

const TRANSACTIONS_SHOW_FILTERS: WooPaymentsListShowFilter[] = [
	'all',
	'advanced',
];
const ALL_CURRENCIES = '---';

type TransactionsSummary = Record< string, unknown >;
type WorkingView = {
	key: string;
	view: WooPaymentsMoneyMovementDataView;
} | null;

export type WooPaymentsTransactionsListProps = {
	/** Scopes the list to one payout, like the client's `TransactionsList depositId`. */
	depositId?: string;
	/** Builds the settings-shell route for a changed list query. */
	buildRoute: ( query: WooPaymentsMoneyMovementQuery ) => string;
	/** The card title, like the client's `TransactionsList` "Transactions". */
	title?: string;
};

const getSummaryNumber = ( summary: TransactionsSummary, key: string ) =>
	typeof summary[ key ] === 'number'
		? ( summary[ key ] as number )
		: undefined;

const getSummaryCount = ( summary: TransactionsSummary ) =>
	getSummaryNumber( summary, 'total_count' ) ??
	getSummaryNumber( summary, 'count' );

/**
 * The settled transactions list, shared by the Transactions page and payout
 * details like the client's `TransactionsList` (client 11.1.0
 * `transactions/list/index.tsx`, reused by `deposits/details/index.tsx:356`).
 *
 * @param props The list props.
 */
export const WooPaymentsTransactionsList = (
	props: WooPaymentsTransactionsListProps
) => {
	const { depositId, buildRoute, title } = props;
	const location = useLocation();
	const [ transactions, setTransactions ] = useState<
		WooPaymentsTransactionListRow[]
	>( [] );
	const [ totalCount, setTotalCount ] = useState( 0 );
	const [ summary, setSummary ] = useState< TransactionsSummary >( {} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasLoadError, setHasLoadError ] = useState( false );
	const [ isExporting, setIsExporting ] = useState( false );
	const runExport = useWooPaymentsExport();
	const [ workingView, setWorkingView ] = useState< WorkingView >( null );
	// Client 11.1.0 `transactions/filters/config.ts:103-127`: "Show" all or the advanced filters; payout details have none.
	const showFilter = depositId
		? 'all'
		: getListShowFilter( location.search, TRANSACTIONS_SHOW_FILTERS );
	const isAdvanced = showFilter === 'advanced';
	const columns = useMemo(
		() =>
			getTransactionListFields( {
				includeDeposit: ! depositId,
				includeSubscription: isWooPaymentsSubscriptionsActive(),
				includeFilters: isAdvanced,
			} ),
		[ depositId, isAdvanced ]
	);
	const fieldIds = useMemo(
		() => columns.map( ( field ) => field.id ),
		[ columns ]
	);
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_transactions_hidden_columns',
		fieldIds,
		undefined,
		TRANSACTION_LIST_DEFAULT_HIDDEN_COLUMNS
	);
	const canonicalViewKey = location.search;
	const urlQuery = useMemo(
		() =>
			parseMoneyMovementQuery( location.search, {
				page: 1,
				pagesize: 25,
				sort: 'date',
				direction: 'desc',
			} ),
		[ location.search ]
	);
	// The payout scope is a request parameter, not a DataViews filter the merchant can change.
	// Client 11.1.0 `data/transactions/resolvers.js:29`: "match any" goes to the list, summary and export.
	const match = isAdvanced ? getListMatch( location.search ) : 'all';
	const query = useMemo( () => {
		const scopedQuery = depositId
			? { ...urlQuery, deposit_id: depositId }
			: urlQuery;

		return match === 'any' ? { ...scopedQuery, match } : scopedQuery;
	}, [ depositId, urlQuery, match ] );
	const queryView = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( urlQuery, {
				fields: visibleFields,
			} ),
		[ urlQuery, visibleFields ]
	);
	const view =
		workingView?.key === canonicalViewKey ? workingView.view : queryView;

	useEffect( () => {
		setWorkingView( ( currentView ) =>
			currentView?.key === canonicalViewKey ? currentView : null
		);
	}, [ canonicalViewKey ] );

	const loadTransactions = useCallback(
		async ( isCurrent: () => boolean ) => {
			setIsLoading( true );

			// Client 11.1.0 `data/transactions/resolvers.js:64-113`: the list and its summary load
			// apart; a failed list raises a notice and shows no rows, a failed summary no footer.
			const [ listResult, summaryResult ] = await Promise.allSettled( [
				getWooPaymentsTransactions( query ),
				getWooPaymentsTransactionsSummary( query ),
			] );

			if ( ! isCurrent() ) {
				return;
			}

			const response =
				listResult.status === 'fulfilled'
					? listResult.value
					: undefined;
			const nextSummary =
				summaryResult.status === 'fulfilled' ? summaryResult.value : {};

			if ( listResult.status === 'rejected' ) {
				reportListLoadError(
					__( 'Error retrieving transactions.', 'woocommerce' )
				);
			}

			setTransactions( response?.data || [] );
			setTotalCount(
				response?.total_count ??
					getSummaryCount( nextSummary ) ??
					response?.data?.length ??
					0
			);
			setSummary( nextSummary );
			setHasLoadError( listResult.status === 'rejected' );
			setIsLoading( false );
		},
		[ query ]
	);

	useEffect( () => {
		let isMounted = true;

		void loadTransactions( () => isMounted );

		return () => {
			isMounted = false;
		};
	}, [ loadTransactions ] );

	const pushRoute = (
		nextQuery: WooPaymentsMoneyMovementQuery,
		nextShowFilter = showFilter,
		nextMatch: WooPaymentsListMatch = match
	) =>
		getHistory().push(
			getSettingsPaymentsProviderAdminPath(
				withListShowFilter(
					buildRoute( nextQuery ),
					nextShowFilter,
					nextMatch
				)
			)
		);
	const handleViewChange = ( nextView: WooPaymentsMoneyMovementDataView ) => {
		saveFields( nextView.fields );
		setWorkingView( {
			key: canonicalViewKey,
			view: nextView,
		} );

		if (
			nextView.filters?.some( ( filter ) => filter.value === undefined )
		) {
			return;
		}

		pushRoute( dataViewsViewToMoneyMovementQuery( nextView, query ) );
	};
	const handleSearchChange = ( search: string ) => {
		handleViewChange( {
			...view,
			page: 1,
			search: search || undefined,
		} );
	};

	// Client 11.1.0 `transactions/list/index.tsx:595-701`: the outcome is told by snackbars only.
	const handleExport = async () => {
		// Client 11.1.0 `transactions/list/index.tsx:596-600` records the click before asking, with its wc-admin path as source.
		recordEvent( 'wcpay_csv_export_click', {
			row_type: 'transactions',
			source: depositId
				? '/payments/payouts/details'
				: '/payments/transactions',
			exported_row_count: summary.count,
		} );

		if (
			! confirmWooPaymentsExport(
				'transactions',
				getSummaryCount( summary ) ?? 0,
				query
			)
		) {
			return;
		}

		setIsExporting( true );
		await runExport( {
			requestExport: () => requestWooPaymentsTransactionsExport( query ),
			getExportUrl: getWooPaymentsTransactionsExportUrl,
		} );
		setIsExporting( false );
	};

	const loadingMessage = __( 'Loading transactions…', 'woocommerce' );
	const emptyMessage = __( 'No transactions found.', 'woocommerce' );
	let liveStatusMessage: string = __( 'Transactions loaded.', 'woocommerce' );

	if ( hasLoadError ) {
		// The error notice announces itself.
		liveStatusMessage = '';
	} else if ( isLoading ) {
		liveStatusMessage = loadingMessage;
	} else if ( transactions.length === 0 ) {
		liveStatusMessage = emptyMessage;
	}

	const summaryCount = getSummaryCount( summary ) ?? totalCount;
	const summaryCurrency =
		typeof summary.currency === 'string' ? summary.currency : undefined;
	const storeCurrencies = Array.isArray( summary.store_currencies )
		? summary.store_currencies
		: [];
	// Client 11.1.0 `transactions/list/index.tsx:711-768`: amounts only for one currency.
	const showSummaryAmounts =
		summaryCount > 0 &&
		( storeCurrencies.length < 2 || !! query.store_currency_is );
	const summaryTotal = getSummaryNumber( summary, 'total' );
	// Client 11.1.0 `transactions/list/index.tsx:716-768`: the footer summary, once loaded.
	const summaryItems: Array< { label: string; value: string } > =
		! isLoading && summaryTotal !== undefined
			? [
					{
						label: _n(
							'transaction',
							'transactions',
							summaryCount,
							'woocommerce'
						),
						value: formatCount( summaryCount ),
					},
			  ]
			: [];

	if ( summaryItems.length && showSummaryAmounts ) {
		summaryItems.push(
			{
				label: __( 'total', 'woocommerce' ),
				value: formatExplicitCurrency( summaryTotal, summaryCurrency ),
			},
			{
				label: __( 'fees', 'woocommerce' ),
				value: formatAmount(
					getSummaryNumber( summary, 'fees' ) ?? 0,
					summaryCurrency
				),
			},
			{
				label: __( 'net', 'woocommerce' ),
				value: formatExplicitCurrency(
					getSummaryNumber( summary, 'net' ) ?? 0,
					summaryCurrency
				),
			}
		);
	}
	// Client 11.1.0 `transactions/list/index.tsx:775-776`: the summary lists the currencies and
	// payment methods to filter by.
	const customerCurrencies = Array.isArray( summary.customer_currencies )
		? summary.customer_currencies.map( String )
		: [];
	const sources = Array.isArray( summary.sources )
		? summary.sources.map( String )
		: [];
	const fields = isAdvanced
		? [
				...columns,
				...getTransactionListFilterFields( {
					customerCurrencies,
					sources,
				} ),
		  ]
		: columns;
	const searchValue = Array.isArray( query.search )
		? query.search[ 0 ] || ''
		: query.search || '';

	const currencyFilter =
		typeof query.store_currency_is === 'string'
			? query.store_currency_is
			: undefined;
	// Client 11.1.0 `transactions/list/index.tsx:770-773`: the currencies to choose from.
	const currencyChoices =
		storeCurrencies.length || ! currencyFilter
			? storeCurrencies.map( String )
			: [ currencyFilter ];
	const listFilters: WooPaymentsListFilter[] = [
		{
			id: 'show',
			label: __( 'Show', 'woocommerce' ),
			value: showFilter,
			options: [
				{
					label: __( 'All transactions', 'woocommerce' ),
					value: 'all',
				},
				{
					label: __( 'Advanced filters', 'woocommerce' ),
					value: 'advanced',
				},
			],
			onChange: ( value ) => {
				const nextFilter = getListShowFilter(
					`filter=${ value }`,
					TRANSACTIONS_SHOW_FILTERS
				);

				pushRoute(
					getQueryForShowFilter( urlQuery, nextFilter ),
					nextFilter
				);
			},
		},
	];

	if ( isAdvanced ) {
		listFilters.push(
			getListMatchFilter(
				__( 'Transactions match', 'woocommerce' ),
				match,
				( value ) =>
					pushRoute(
						urlQuery,
						showFilter,
						value === 'any' ? 'any' : 'all'
					)
			)
		);
	}

	// Client 11.1.0 `transactions/filters/index.tsx`: the currency select shows for more than one currency.
	if ( currencyChoices.length > 1 ) {
		listFilters.unshift( {
			id: 'currency',
			label: __( 'Deposit currency', 'woocommerce' ),
			value: currencyFilter || ALL_CURRENCIES,
			options: [
				{
					label: __( 'All currencies', 'woocommerce' ),
					value: ALL_CURRENCIES,
				},
				...currencyChoices.map( ( currency ) => ( {
					label: formatCurrencyName( currency ),
					value: currency,
				} ) ),
			],
			onChange: ( value ) => {
				const nextQuery = { ...urlQuery };

				if ( value === ALL_CURRENCIES ) {
					delete nextQuery.store_currency_is;
				} else {
					nextQuery.store_currency_is = value;
				}

				pushRoute( nextQuery );
			},
		} );
	}

	return (
		<div
			className="woocommerce-woopayments-money-movement__list"
			aria-busy={ isLoading }
		>
			{ ! depositId && (
				<WooPaymentsListFilters filters={ listFilters } />
			) }
			<LiveStatusMessage>{ liveStatusMessage }</LiveStatusMessage>
			<WooPaymentsMoneyMovementDataViews
				fields={ fields }
				rows={ transactions }
				view={ view }
				onChangeView={ handleViewChange }
				total={ totalCount }
				isLoading={ isLoading }
				title={ title }
				summary={ summaryItems }
				// Client 11.1.0 `transactions/list/index.tsx:173-222`: the `isNumeric` columns.
				numericFields={ [ 'customer_amount', 'amount', 'fees', 'net' ] }
				getItemId={ getResourceId }
				toolbarActions={
					<>
						<WooPaymentsTransactionSearch
							value={ searchValue }
							onChange={ handleSearchChange }
						/>
						{ /* Client 11.1.0 `transactions/list/index.tsx`: Export only with rows. */ }
						{ transactions.length > 0 && (
							<ExportButton
								onClick={ handleExport }
								isBusy={ isExporting }
								disabled={ isLoading || isExporting }
							/>
						) }
					</>
				}
			/>
		</div>
	);
};
