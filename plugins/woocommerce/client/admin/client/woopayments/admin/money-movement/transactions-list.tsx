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
import { ExportButton, LiveStatusMessage, StatusMessage } from './table';
import { usePersistedHiddenFields } from './view-preferences';
import {
	TRANSACTION_LIST_DEFAULT_HIDDEN_COLUMNS,
	getTransactionListFields,
	isWooPaymentsSubscriptionsActive,
	type WooPaymentsTransactionListRow,
} from './transactions-list-fields';
import { getSettingsPaymentsProviderAdminPath } from '../utils';

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
	const fields = useMemo(
		() =>
			getTransactionListFields( {
				includeDeposit: ! depositId,
				includeSubscription: isWooPaymentsSubscriptionsActive(),
				includeFilters: ! depositId,
			} ),
		[ depositId ]
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

		getHistory().push(
			getSettingsPaymentsProviderAdminPath(
				buildRoute(
					dataViewsViewToMoneyMovementQuery( nextView, query )
				)
			)
		);
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

	return (
		<div aria-busy={ isLoading }>
			<LiveStatusMessage isError={ !! errorMessage }>
				{ liveStatusMessage }
			</LiveStatusMessage>
			{ isLoading && <StatusMessage>{ loadingMessage }</StatusMessage> }
			{ errorMessage && (
				<StatusMessage isError>{ errorMessage }</StatusMessage>
			) }
			{ exportMessage && (
				<StatusMessage isLive isError={ !! exportMessage.isError }>
					{ exportMessage.text }
				</StatusMessage>
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
