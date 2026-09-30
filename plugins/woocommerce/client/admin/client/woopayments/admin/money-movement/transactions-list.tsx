/**
 * External dependencies
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n } from '@wordpress/i18n';
import { getHistory } from '@woocommerce/navigation';
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
import { runWooPaymentsExport } from './export';
import {
	formatAmount,
	formatCount,
	formatExplicitCurrency,
	getErrorMessage,
	getResourceId,
} from './utils';
import { ExportButton, ListNotice, LiveStatusMessage } from './table';
import { usePersistedHiddenFields } from './view-preferences';
import {
	TRANSACTION_LIST_DEFAULT_HIDDEN_COLUMNS,
	getTransactionListFields,
	isWooPaymentsSubscriptionsActive,
	type WooPaymentsTransactionListRow,
} from './transactions-list-fields';
import { getSettingsPaymentsProviderAdminPath } from '../utils';
import { formatCurrencyName } from '../currency';
import {
	getListShowFilter,
	getQueryForShowFilter,
	withListShowFilter,
	WooPaymentsListFilters,
	type WooPaymentsListFilter,
	type WooPaymentsListShowFilter,
} from './list-filters';

const TRANSACTIONS_SHOW_FILTERS: WooPaymentsListShowFilter[] = [
	'all',
	'advanced',
];
const ALL_CURRENCIES = '---';

type TransactionsSummary = Record< string, unknown >;
type ExportMessage = {
	text: string;
	isError?: boolean;
};
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
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ exportMessage, setExportMessage ] =
		useState< ExportMessage | null >( null );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ workingView, setWorkingView ] = useState< WorkingView >( null );
	// Client 11.1.0 `transactions/filters/config.ts:103-127`: "Show" all or the advanced filters; payout details have none.
	const showFilter = depositId
		? 'all'
		: getListShowFilter( location.search, TRANSACTIONS_SHOW_FILTERS );
	const isAdvanced = showFilter === 'advanced';
	const fields = useMemo(
		() =>
			getTransactionListFields( {
				includeDeposit: ! depositId,
				includeSubscription: isWooPaymentsSubscriptionsActive(),
				includeFilters: isAdvanced,
			} ),
		[ depositId, isAdvanced ]
	);
	const fieldIds = useMemo(
		() => fields.map( ( field ) => field.id ),
		[ fields ]
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
	const query = useMemo(
		() => ( depositId ? { ...urlQuery, deposit_id: depositId } : urlQuery ),
		[ depositId, urlQuery ]
	);
	const queryView = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( urlQuery, {
				fields: visibleFields,
				titleField: 'type',
				showTitle: false,
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

			try {
				const [ response, nextSummary ] = await Promise.all( [
					getWooPaymentsTransactions( query ),
					getWooPaymentsTransactionsSummary( query ),
				] );

				if ( isCurrent() ) {
					setTransactions( response.data || [] );
					setTotalCount(
						response.total_count ??
							getSummaryCount( nextSummary ) ??
							response.data?.length ??
							0
					);
					setSummary( nextSummary );
					setErrorMessage( null );
				}
			} catch ( error ) {
				if ( isCurrent() ) {
					setErrorMessage(
						getErrorMessage(
							error,
							__(
								'Unable to load WooPayments transactions.',
								'woocommerce'
							)
						)
					);
				}
			} finally {
				if ( isCurrent() ) {
					setIsLoading( false );
				}
			}
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
		nextShowFilter = showFilter
	) =>
		getHistory().push(
			getSettingsPaymentsProviderAdminPath(
				withListShowFilter( buildRoute( nextQuery ), nextShowFilter )
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

	const handleExport = async () => {
		setIsExporting( true );
		setExportMessage( null );

		try {
			await runWooPaymentsExport( {
				requestExport: () =>
					requestWooPaymentsTransactionsExport( query ),
				getExportUrl: getWooPaymentsTransactionsExportUrl,
			} );
			setExportMessage( {
				text: __(
					'Your transactions export has started downloading.',
					'woocommerce'
				),
			} );
		} catch ( error ) {
			setExportMessage( {
				text: getErrorMessage(
					error,
					__(
						'Unable to export WooPayments transactions.',
						'woocommerce'
					)
				),
				isError: true,
			} );
		} finally {
			setIsExporting( false );
		}
	};

	const loadingMessage = __( 'Loading transactions…', 'woocommerce' );
	const emptyMessage = __( 'No transactions found.', 'woocommerce' );
	let liveStatusMessage: string = __( 'Transactions loaded.', 'woocommerce' );

	if ( errorMessage ) {
		liveStatusMessage = errorMessage;
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
		<div aria-busy={ isLoading }>
			{ ! depositId && (
				<WooPaymentsListFilters filters={ listFilters } />
			) }
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
				rows={ transactions }
				view={ view }
				onChangeView={ handleViewChange }
				total={ totalCount }
				isLoading={ isLoading }
				search={ false }
				searchLabel={ __( 'Search transactions', 'woocommerce' ) }
				title={ title }
				summary={ summaryItems }
				// Client 11.1.0 `transactions/list/index.tsx:173-222`: the `isNumeric` columns.
				numericFields={ [ 'customer_amount', 'amount', 'fees', 'net' ] }
				empty={ emptyMessage }
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
