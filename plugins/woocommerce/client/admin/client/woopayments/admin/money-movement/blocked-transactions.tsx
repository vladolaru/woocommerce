/**
 * External dependencies
 */
import { dispatch } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { Search } from '@woocommerce/components';
import { getHistory, getQuery } from '@woocommerce/navigation';
import { recordEvent } from '@woocommerce/tracks';
import {
	downloadCSVFile,
	generateCSVDataFromTable,
	generateCSVFileName,
} from '@woocommerce/csv-export';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsFraudOutcomeTransactionSearch,
	getWooPaymentsFraudOutcomeTransactions,
	getWooPaymentsFraudOutcomeTransactionsExport,
	getWooPaymentsFraudOutcomeTransactionsSummary,
} from './data';
import type {
	WooPaymentsMoneyMovementDataView,
	WooPaymentsMoneyMovementQuery,
} from './types';
import {
	buildMoneyMovementRoutePath,
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	parseMoneyMovementQuery,
} from './query';
import { WooPaymentsMoneyMovementDataViews } from './dataviews';
import { ExportButton } from './table';
import {
	formatDateTime,
	formatExplicitCurrency,
	getTransactionDetailsRoute,
} from './utils';
import { usePersistedHiddenFields } from './view-preferences';
import {
	getSettingsPaymentsProviderAdminPath,
	getSettingsPaymentsProviderRouteUrl,
} from '../utils';

// Client 11.1.0 `data/transactions/hooks.ts:104-116`.
type FraudOutcomeTransaction = {
	amount: number;
	created: string;
	currency: string;
	customer_name: string;
	order_id: number;
	payment_intent: { id: string };
	status: string;
	id?: string;
};
type FraudOutcomesSummary = {
	count?: number;
	total?: number;
	currencies?: string[];
};
type SearchValue = { key: string; label: string };

// Client 11.1.0 TableCard query names for the native list params.
const CLIENT_QUERY_KEYS: Record< string, string > = {
	pagesize: 'per_page',
	sort: 'orderby',
	direction: 'order',
};
const NOT_FOUND = 'wcpay_fraud_outcome_not_found';

// Client 11.1.0 `transactions/blocked/columns.tsx:28-60`: Date / Time and Amount sort.
const COLUMNS = [
	{ key: 'created', label: __( 'Date / Time', 'woocommerce' ), sort: true },
	{ key: 'amount', label: __( 'Amount', 'woocommerce' ), sort: true },
	{ key: 'customer', label: __( 'Customer', 'woocommerce' ), sort: false },
	{ key: 'status', label: __( 'Status', 'woocommerce' ), sort: false },
];
const COLUMN_KEYS = COLUMNS.map( ( { key } ) => key );

// Client 11.1.0 `transactions/blocked/columns.tsx:62-101`: each cell's CSV value.
const getCsvValue = ( item: FraudOutcomeTransaction, key: string ) =>
	( {
		created: formatDateTime( item.created ),
		amount: item.amount,
		customer: item.customer_name,
		status: item.status,
	} )[ key ] ?? '';

const toArray = ( value: WooPaymentsMoneyMovementQuery[ string ] ) =>
	value === undefined
		? undefined
		: ( [] as unknown[] ).concat( value ).map( String );

const getNotices = () =>
	dispatch( 'core/notices' ) as unknown as {
		createErrorNotice: ( message: string ) => void;
	};

// Client 11.1.0 `transactions/fraud-protection/autocompleter.tsx:22-83`.
const blockedSearchCompleter = {
	name: 'transactions',
	className: 'woocommerce-search__transactions-result',
	async options( term = '' ) {
		const options = await getWooPaymentsFraudOutcomeTransactionSearch(
			'block',
			term
		);

		return term
			? options.filter( ( { label } ) =>
					label
						.toLocaleLowerCase()
						.includes( term.toLocaleLowerCase() )
			  )
			: options;
	},
	isDebounced: true,
	getOptionIdentifier: ( option: SearchValue ) => option.label,
	getOptionKeywords: ( option: SearchValue ) => [ option.label ],
	// Client 11.1.0 `utils/compute-suggestion-match.ts`: bold the first match.
	getOptionLabel: ( option: SearchValue, query = '' ) => {
		const label = decodeEntities( option.label );
		const start = query
			? label.toLocaleLowerCase().indexOf( query.toLocaleLowerCase() )
			: -1;
		const end = start + query.length;

		return (
			<span
				className="woocommerce-search__result-name"
				aria-label={ option.label }
			>
				{ start < 0 ? label : label.substring( 0, start ) }
				<strong className="components-form-token-field__suggestion-match">
					{ start < 0 ? '' : label.substring( start, end ) }
				</strong>
				{ start < 0 ? '' : label.substring( end ) }
			</span>
		);
	},
	getOptionCompletion: ( option: SearchValue ) => ( {
		key: option.label,
		label: option.label,
	} ),
};

/**
 * The Blocked transactions view, ported from client 11.1.0 `transactions/blocked/index.tsx`.
 */
export const WooPaymentsBlockedTransactions = () => {
	const location = useLocation();
	const query = useMemo(
		() => parseMoneyMovementQuery( location.search ),
		[ location.search ]
	);
	const search = toArray( query.search );
	const [ rows, setRows ] = useState< FraudOutcomeTransaction[] >( [] );
	const [ summary, setSummary ] = useState< FraudOutcomesSummary | null >(
		null
	);
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isDownloading, setIsDownloading ] = useState( false );
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_transactions_blocked_hidden_columns',
		COLUMN_KEYS
	);
	const title = __( 'Blocked transactions', 'woocommerce' );
	const view = moneyMovementQueryToDataViewsView(
		{ sort: 'created', direction: 'desc', ...query },
		// Without `showTitle: false` DataViews adds a second Date / Time column.
		{ fields: visibleFields, titleField: 'created', showTitle: false }
	);
	const columnsToDisplay = COLUMNS.filter(
		( { key } ) => view.fields?.includes( key )
	);
	const totalRows = summary?.count || 0;

	useEffect( () => {
		recordEvent( 'page_view', { path: 'payments_transactions_blocked' } );
	}, [] );

	// Client 11.1.0 `data/transactions/resolvers.js:121-151` and `hooks.ts:334-373`.
	useEffect( () => {
		let isCurrent = true;
		setIsLoading( true );
		void getWooPaymentsFraudOutcomeTransactions( {
			status: 'block',
			page: query.page,
			pagesize: query.pagesize,
			sort: query.sort || 'date',
			direction: query.direction || 'desc',
			'search[]': toArray( query.search ),
		} )
			.then( ( response ) => response.data || [] )
			.catch( ( error: { code?: string } ) => {
				if ( isCurrent && error?.code !== NOT_FOUND ) {
					getNotices().createErrorNotice(
						__( 'Error retrieving transactions.', 'woocommerce' )
					);
				}
				return [];
			} )
			.then( ( data ) => {
				if ( isCurrent ) {
					setRows( data as unknown as FraudOutcomeTransaction[] );
					setIsLoading( false );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ query ] );

	// Client 11.1.0 `resolvers.js:159-199` and `hooks.ts:375-404`: the summary
	// is keyed on the search only, so paging and sorting do not refetch it.
	// It stays hidden while loading and after a failure other than not-found.
	const searchKey = JSON.stringify( search ?? [] );
	useEffect( () => {
		let isCurrent = true;
		setSummary( null );
		void getWooPaymentsFraudOutcomeTransactionsSummary( {
			status: 'block',
		} )
			.then( ( result ) => result || { count: 0, total: 0 } )
			.catch( ( error: { code?: string } ) => {
				if ( error?.code === NOT_FOUND ) {
					return { count: 0, total: 0 };
				}
				if ( isCurrent ) {
					getNotices().createErrorNotice(
						__(
							'Error retrieving on review transactions.',
							'woocommerce'
						)
					);
				}
				return null;
			} )
			.then( ( result ) => isCurrent && setSummary( result ) );

		return () => {
			isCurrent = false;
		};
	}, [ searchKey ] );

	const pushQuery = ( nextQuery: WooPaymentsMoneyMovementQuery ) => {
		const route = buildMoneyMovementRoutePath(
			'/woopayments/transactions',
			nextQuery
		);
		getHistory().push(
			getSettingsPaymentsProviderAdminPath(
				`${ route }${ route.includes( '?' ) ? '&' : '?' }view=blocked`
			)
		);
	};
	const handleViewChange = ( nextView: WooPaymentsMoneyMovementDataView ) => {
		saveFields( nextView.fields );
		pushQuery( {
			...dataViewsViewToMoneyMovementQuery( nextView, query ),
			search: query.search,
		} );
	};
	const handleSearchChange = ( values: SearchValue[] ) =>
		pushQuery( {
			...query,
			search: values.length
				? [ ...new Set( values.map( ( v ) => v.key || v.label ) ) ]
				: undefined,
		} );

	const onDownload = async () => {
		setIsDownloading( true );
		// Client 11.1.0 `blocked/index.tsx:128-130, :148`: the file name carries
		// the admin query minus page and path, under the client's URL names.
		const {
			page,
			path,
			tab,
			view: nativeView,
			...listQuery
		} = getQuery() as Record< string, string >;
		const params: Record< string, string > = { tab: 'blocked-page' };
		Object.entries( listQuery ).forEach( ( [ key, value ] ) => {
			params[ CLIENT_QUERY_KEYS[ key ] ?? key ] = value;
		} );

		try {
			const { data = [] } =
				await getWooPaymentsFraudOutcomeTransactionsExport( {
					status: 'block',
					sort: query.sort,
					direction: query.direction,
					additional_status: 'review',
					'search[]': search,
				} );

			downloadCSVFile(
				generateCSVFileName( title, params ),
				generateCSVDataFromTable(
					columnsToDisplay,
					( data as unknown as FraudOutcomeTransaction[] ).map(
						( item ) =>
							columnsToDisplay.map( ( { key } ) => ( {
								display: '',
								value: getCsvValue( item, key ),
							} ) )
					)
				)
			);
			recordEvent( 'wcpay_fraud_outcome_transactions_download', {
				exported_transactions: rows.length,
				total_transactions: summary?.count,
			} );
		} catch {
			getNotices().createErrorNotice(
				__(
					'There was a problem generating your export.',
					'woocommerce'
				)
			);
		}

		setIsDownloading( false );
	};

	const fields = COLUMNS.map( ( { key, label, sort } ) => ( {
		id: key,
		label,
		enableHiding: key !== 'created',
		enableSorting: sort,
		render: ( { item }: { item: FraudOutcomeTransaction } ) =>
			key === 'status' ? (
				<span className="woocommerce-woopayments-money-movement__status-chip">
					{ __( 'Payment blocked', 'woocommerce' ) }
				</span>
			) : (
				<a
					href={ getSettingsPaymentsProviderRouteUrl(
						getTransactionDetailsRoute( {
							id:
								item.payment_intent?.id ||
								String( item.order_id ),
						} )
					) }
				>
					{ key === 'amount'
						? formatExplicitCurrency( item.amount, item.currency )
						: getCsvValue( item, key ) }
				</a>
			),
	} ) );

	// Client 11.1.0 `transactions/blocked/index.tsx:67-94`: the footer summary, once loaded.
	const summaryItems: Array< { label: string; value: string } > = summary
		? [
				{
					label: __( 'transactions(s)', 'woocommerce' ),
					value: String( totalRows ),
				},
		  ]
		: [];

	if ( summary && totalRows > 0 && summary.currencies?.length === 1 ) {
		summaryItems.push( {
			label: __( 'blocked', 'woocommerce' ),
			value: formatExplicitCurrency(
				summary.total,
				summary.currencies[ 0 ]
			),
		} );
	}

	return (
		<>
			<WooPaymentsMoneyMovementDataViews
				fields={ fields }
				rows={ rows }
				view={ view }
				onChangeView={ handleViewChange }
				total={ totalRows }
				isLoading={ isLoading }
				search={ false }
				searchLabel={ title }
				title={ title }
				summary={ summaryItems }
				// Client 11.1.0 uses TableCard, whose empty text this is.
				empty={ __( 'No data to display', 'woocommerce' ) }
				getItemId={ ( item ) =>
					item.payment_intent?.id || String( item.order_id )
				}
				toolbarActions={
					<>
						<Search
							inlineTags
							onChange={ handleSearchChange }
							placeholder={ __(
								'Search by order number or customer name',
								'woocommerce'
							) }
							selected={ search?.map( ( value ) => ( {
								key: value,
								label: value,
							} ) ) }
							showClearButton
							type="custom"
							autocompleter={ blockedSearchCompleter }
						/>
						{ rows.length > 0 && (
							<ExportButton
								onClick={ onDownload }
								isBusy={ isDownloading }
								disabled={ isLoading || isDownloading }
							/>
						) }
					</>
				}
			/>
		</>
	);
};
