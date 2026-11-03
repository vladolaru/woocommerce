/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { recordEvent } from '@woocommerce/tracks';
// @ts-expect-error - Use the WordPress-bundled DataViews entry in wp-admin builds.
import { DataViews, type Field, type View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsReportsFees,
	getWooPaymentsReportsFeesExportUrl,
	getWooPaymentsReportsFeesSummary,
	requestWooPaymentsReportsFeesExport,
} from './data';
import {
	buildReportsFeesQueryFromView,
	parseReportsFeesViewFromSearch,
	serializeReportsFeesViewToSearch,
} from './query';
import { ReportState } from './report-state';
import {
	confirmWooPaymentsExport,
	useWooPaymentsExport,
} from '../money-movement/export';
import { useDataViewsReloadState } from '../money-movement/use-dataviews-reload-state';
import { WooPaymentsFilterPicker } from '../money-movement/list-filters';
import type { ReportsFee, ReportsFeesQuery, ReportsFeesSummary } from './types';
import {
	formatExplicitCurrency,
	getTransactionDetailsRoute,
} from '../money-movement/utils';
import {
	getSettingsPaymentsProviderRouteUrl,
	handleSettingsPaymentsProviderRouteClick,
} from '../utils';
import {
	type AsyncState,
	type DataViewsFilter,
	getDateFormat,
	getGlobalSettings,
	navigateReportsRoute,
	getLastFullCalendarMonthDateFilter,
	isBalanceDatePreset,
	getBalanceDateFilterForPreset,
	getBalancePeriodForDateFilter,
	getRangeDays,
	getBalanceDateFilterFromView,
	matchBalanceDatePreset,
} from './utils';

// Client 11.1.0 `class-wc-payments-admin.php:1073` sends the time_format option; native reads it through `@wordpress/date`.
const getTimeFormat = () => getDateSettings().formats.time;

const getDateTimeIso = ( value?: string | null ) => {
	if ( ! value ) {
		return null;
	}

	if ( value.includes( 'T' ) ) {
		return value.endsWith( 'Z' ) || /[+-]\d{2}:?\d{2}$/.test( value )
			? value
			: `${ value }Z`;
	}

	return `${ value.replace( ' ', 'T' ) }Z`;
};

const formatDateTimeFromString = (
	value?: string | null,
	includeTime = false
) => {
	const iso = getDateTimeIso( value );

	if ( ! iso ) {
		return '-';
	}

	const format = includeTime
		? `${ getDateFormat() } / ${ getTimeFormat() }`
		: getDateFormat();

	return dateI18n( format, iso );
};

const getAdminUrl = () => {
	const adminUrl = getGlobalSettings().wcSettings?.adminUrl || '';
	return adminUrl.endsWith( '/' ) || adminUrl === ''
		? adminUrl
		: `${ adminUrl }/`;
};

const getOrderUrl = ( orderId: ReportsFee[ 'order_id' ] ) =>
	`${ getAdminUrl() }admin.php?page=wc-orders&action=edit&id=${ encodeURIComponent(
		String( orderId ?? '' )
	) }`;

const getTransactionRoute = ( item: ReportsFee ) =>
	getTransactionDetailsRoute( {
		id: item.payment_id || item.transaction_id,
		transaction_id: item.transaction_id,
		type: item.type,
	} );

const getMethodLabel = ( value?: string ) => {
	if ( ! value ) {
		return '-';
	}

	if ( value === 'card' ) {
		return __( 'Card', 'woocommerce' );
	}

	return value
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( letter ) => letter.toUpperCase() );
};

const getTypeLabel = ( value?: string ) => {
	if ( ! value ) {
		return '-';
	}

	const labels: Record< string, string > = {
		charge: __( 'Charge', 'woocommerce' ),
		payment: __( 'Payment', 'woocommerce' ),
		refund: __( 'Refund', 'woocommerce' ),
		dispute: __( 'Dispute', 'woocommerce' ),
		fee_refund: __( 'Fee refund', 'woocommerce' ),
		network_costs: __( 'Network costs', 'woocommerce' ),
	};

	return labels[ value ] || getMethodLabel( value );
};

// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:26,208-233`: a search reaches the URL after typing pauses.
const FEES_SEARCH_URL_DEBOUNCE_MS = 500;

// The Fees params in a location search, normalized so equal views compare equal.
const getFeesUrlKey = ( search: string ) =>
	serializeReportsFeesViewToSearch(
		parseReportsFeesViewFromSearch( search )
	);

/**
 * The Fees params that change the URL at once: everything but the debounced search, and the page reset a search causes.
 *
 * Client 11.1.0 `reports/fees/use-fees-url-sync.ts:131-144,208-224`.
 *
 * @param search           Serialized Fees params.
 * @param dropSearchPaging Whether to ignore `paged` as well.
 */
const getImmediateFeesParams = (
	search: string,
	dropSearchPaging: boolean
) => {
	const params = new URLSearchParams( search );

	params.delete( 'search[]' );
	if ( dropSearchPaging ) {
		params.delete( 'paged' );
	}

	return params.toString();
};

const FeesReport = ( { now }: { now: Date } ) => {
	const location = useLocation();
	const navigate = useNavigate();
	// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:167-195`: sort, paging, search and filters come from the URL.
	const [ view, setView ] = useState< View >( () => ( {
		type: 'table',
		fields: [
			'date',
			'payment_method',
			'type',
			'order_id',
			'transaction_id',
			'transaction_currency',
			'amount',
			'fees',
		],
		...parseReportsFeesViewFromSearch( location.search ),
	} ) );
	const feesUrlKeyRef = useRef( getFeesUrlKey( location.search ) );
	const searchUrlTimerRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const [ rowsState, setRowsState ] = useState< AsyncState< ReportsFee[] > >(
		{
			isLoading: true,
		}
	);
	const [ summaryState, setSummaryState ] = useState<
		AsyncState< ReportsFeesSummary >
	>( {
		isLoading: true,
	} );
	const [ isExporting, setIsExporting ] = useState( false );
	const runExport = useWooPaymentsExport();
	const errorHeadingRef = useRef< HTMLHeadingElement >( null );
	const mountedRef = useRef( true );
	// Only the latest request may write: a slower answer for an earlier query must not replace the current one.
	const feesRequestIdRef = useRef( 0 );
	const query = useMemo(
		() => buildReportsFeesQueryFromView( view ),
		[ view ]
	);
	const serializedQuery = useMemo( () => JSON.stringify( query ), [ query ] );
	const rows = rowsState.data ?? [];
	const totalItems = summaryState.data?.count ?? rows.length;
	const hasError = Boolean( rowsState.error || summaryState.error );
	const isLoading = rowsState.isLoading || summaryState.isLoading;
	const hasFilters = Boolean( view.search || ( view.filters ?? [] ).length );
	const totalPages = Math.max(
		1,
		Math.ceil( totalItems / Number( view.perPage || 25 ) )
	);
	const isInitialLoading =
		isLoading && ! rowsState.data && ! summaryState.data;
	const activeDatePreset = useMemo( () => {
		const hasDateFilter = (
			view.filters as DataViewsFilter[] | undefined
		 )?.some( ( filter ) => filter.field === 'date' );

		return hasDateFilter
			? matchBalanceDatePreset(
					getBalanceDateFilterFromView(
						view,
						getLastFullCalendarMonthDateFilter( now ),
						now
					),
					now
				)
			: 'custom';
	}, [ now, view ] );

	useEffect(
		() => () => {
			mountedRef.current = false;
			if ( searchUrlTimerRef.current ) {
				clearTimeout( searchUrlTimerRef.current );
			}
		},
		[]
	);

	// Back, forward or a pasted link re-seeds the view; the page's own writes do not, so staged filter chips stay.
	useEffect( () => {
		const urlKey = getFeesUrlKey( location.search );

		if ( urlKey === feesUrlKeyRef.current ) {
			return;
		}

		feesUrlKeyRef.current = urlKey;
		setView( ( previousView: View ) => ( {
			...previousView,
			...parseReportsFeesViewFromSearch( location.search ),
		} ) );
	}, [ location.search ] );

	const applyView = ( nextView: View ) => {
		const currentSearch = serializeReportsFeesViewToSearch( view );
		const nextSearch = serializeReportsFeesViewToSearch( nextView );
		const hasSearchChange =
			new URLSearchParams( currentSearch ).get( 'search[]' ) !==
			new URLSearchParams( nextSearch ).get( 'search[]' );
		const isSearchPageReset =
			hasSearchChange &&
			new URLSearchParams( nextSearch ).get( 'paged' ) === '1';
		const writeUrl = () => {
			searchUrlTimerRef.current = null;
			feesUrlKeyRef.current = getFeesUrlKey( nextSearch );
			navigateReportsRoute(
				location.search,
				navigate,
				`report_tab=fees&${ nextSearch }`
			);
		};

		setView( nextView );

		if ( searchUrlTimerRef.current ) {
			clearTimeout( searchUrlTimerRef.current );
			searchUrlTimerRef.current = null;
		}

		if (
			getImmediateFeesParams( currentSearch, isSearchPageReset ) !==
			getImmediateFeesParams( nextSearch, isSearchPageReset )
		) {
			writeUrl();
		} else if ( hasSearchChange ) {
			searchUrlTimerRef.current = setTimeout(
				writeUrl,
				FEES_SEARCH_URL_DEBOUNCE_MS
			);
		}
	};

	const load = useCallback( async () => {
		const requestQuery = JSON.parse( serializedQuery ) as ReportsFeesQuery;
		const requestId = ++feesRequestIdRef.current;
		const isCurrent = () =>
			mountedRef.current && requestId === feesRequestIdRef.current;

		setRowsState( ( previous ) => ( {
			...previous,
			error: undefined,
			isLoading: true,
		} ) );
		setSummaryState( ( previous ) => ( {
			...previous,
			error: undefined,
			isLoading: true,
		} ) );

		try {
			const [ fees, summary ] = await Promise.all( [
				getWooPaymentsReportsFees( requestQuery ),
				getWooPaymentsReportsFeesSummary( requestQuery ),
			] );

			if ( ! isCurrent() ) {
				return;
			}

			setRowsState( {
				data: fees,
				isLoading: false,
			} );
			setSummaryState( {
				data: summary,
				isLoading: false,
			} );
			recordEvent( 'wcpay_reports_fees_load_success', {
				total_items: summary.count ?? fees.length,
				has_filters: Boolean(
					requestQuery.search ||
					requestQuery.date_after ||
					requestQuery.date_before ||
					requestQuery.date_between ||
					requestQuery.payment_method_type ||
					requestQuery.type
				),
			} );
			speak(
				sprintf(
					/* translators: %d: number of fees loaded into the report table. */
					__( '%d fees loaded.', 'woocommerce' ),
					summary.count ?? fees.length
				),
				'polite'
			);
		} catch ( error ) {
			if ( ! isCurrent() ) {
				return;
			}

			setRowsState( {
				error: error as Error,
				isLoading: false,
			} );
			setSummaryState( {
				error: error as Error,
				isLoading: false,
			} );
			recordEvent( 'wcpay_reports_fees_load_error', {
				has_filters: hasFilters,
			} );
			speak(
				__( 'Fees report could not be loaded.', 'woocommerce' ),
				'assertive'
			);
		}
	}, [ hasFilters, serializedQuery ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	useEffect( () => {
		if ( hasError ) {
			errorHeadingRef.current?.focus();
		}
	}, [ hasError ] );

	const fields = useMemo< Field< ReportsFee >[] >(
		() => [
			{
				id: 'date',
				label: __( 'Date', 'woocommerce' ),
				header: __( 'Date & time', 'woocommerce' ),
				type: 'date',
				enableSorting: true,
				filterBy: {
					operators: [ 'between', 'on', 'before', 'after' ],
				},
				getValue: ( { item }: { item: ReportsFee } ) => item.date || '',
				render: ( { item }: { item: ReportsFee } ) => (
					<span>{ formatDateTimeFromString( item.date, true ) }</span>
				),
			},
			{
				id: 'payment_method',
				label: __( 'Method', 'woocommerce' ),
				elements: ( summaryState.data?.sources || [] ).map(
					( source ) => ( {
						value: source,
						label: getMethodLabel( source ),
					} )
				),
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: ReportsFee } ) =>
					item.payment_method?.type || '',
				render: ( { item }: { item: ReportsFee } ) => (
					<span>{ getMethodLabel( item.payment_method?.type ) }</span>
				),
			},
			{
				id: 'type',
				label: __( 'Type', 'woocommerce' ),
				elements: (
					summaryState.data?.types || [ 'charge', 'refund' ]
				).map( ( type ) => ( {
					value: type,
					label: getTypeLabel( type ),
				} ) ),
				// Client 11.1.0 `reports/fees/fields.tsx:88`: one type at a time.
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: ReportsFee } ) => item.type || '',
				render: ( { item }: { item: ReportsFee } ) => (
					<span>{ getTypeLabel( item.type ) }</span>
				),
			},
			{
				id: 'order_id',
				label: __( 'Order ID', 'woocommerce' ),
				getValue: ( { item }: { item: ReportsFee } ) =>
					item.order_id ?? '',
				render: ( { item }: { item: ReportsFee } ) =>
					item.order_id ? (
						<a href={ getOrderUrl( item.order_id ) }>
							{ String( item.order_id ) }
						</a>
					) : (
						<span>-</span>
					),
			},
			{
				id: 'transaction_id',
				label: __( 'Transaction ID', 'woocommerce' ),
				enableHiding: false,
				getValue: ( { item }: { item: ReportsFee } ) =>
					item.transaction_id,
				render: ( { item }: { item: ReportsFee } ) => (
					<a
						href={ getSettingsPaymentsProviderRouteUrl(
							getTransactionRoute( item )
						) }
						onClick={ handleSettingsPaymentsProviderRouteClick(
							getTransactionRoute( item )
						) }
					>
						{ item.transaction_id }
					</a>
				),
			},
			{
				id: 'transaction_currency',
				label: __( 'Currency', 'woocommerce' ),
				getValue: ( { item }: { item: ReportsFee } ) =>
					( item.transaction_currency || '' ).toUpperCase(),
				render: ( { item }: { item: ReportsFee } ) => (
					<span>
						{ ( item.transaction_currency || '' ).toUpperCase() ||
							'-' }
					</span>
				),
			},
			{
				id: 'amount',
				label: __( 'Gross amount', 'woocommerce' ),
				type: 'integer',
				// Client 11.2.0 `reports/fees/fields.tsx:137-157`: sortable, not a filter.
				filterBy: false,
				getValue: ( { item }: { item: ReportsFee } ) =>
					item.amount ?? 0,
				render: ( { item }: { item: ReportsFee } ) => (
					<span>
						{ /* Client 11.1.0 `reports/fees/fields.tsx:145`. */ }
						{ formatExplicitCurrency(
							item.amount ?? 0,
							item.deposit_currency || item.transaction_currency
						) }
					</span>
				),
			},
			{
				id: 'fees',
				label: __( 'Fees total', 'woocommerce' ),
				type: 'integer',
				filterBy: false,
				getValue: ( { item }: { item: ReportsFee } ) => item.fees ?? 0,
				render: ( { item }: { item: ReportsFee } ) => (
					<span>
						{ /* Client 11.1.0 `reports/fees/fields.tsx:161`. */ }
						{ formatExplicitCurrency(
							item.fees ?? 0,
							item.deposit_currency || item.transaction_currency
						) }
					</span>
				),
			},
			{
				id: 'deposit_date',
				label: __( 'Settlement date', 'woocommerce' ),
				getValue: ( { item }: { item: ReportsFee } ) =>
					item.deposit_date || '',
				render: ( { item }: { item: ReportsFee } ) => (
					<span>
						{ formatDateTimeFromString( item.deposit_date ) }
					</span>
				),
			},
			{
				id: 'deposit_id',
				label: __( 'Payout ID', 'woocommerce' ),
				getValue: ( { item }: { item: ReportsFee } ) =>
					item.deposit_id || '',
			},
		],
		[ summaryState.data?.sources, summaryState.data?.types ]
	);

	const handleChangeView = ( nextView: View ) => {
		if ( nextView.search !== view.search ) {
			recordEvent( 'wcpay_reports_fees_search', {
				search_length: String( nextView.search || '' ).length,
			} );
		}
		// Client 11.1.0 `reports/fees/use-fees-view.ts:51-71,127-145`.
		( nextView.filters ?? [] ).forEach( ( filter: DataViewsFilter ) => {
			const previous = view.filters?.find(
				( item: DataViewsFilter ) => item.field === filter.field
			);
			if (
				filter.field !== 'date' &&
				JSON.stringify( previous?.value ) !==
					JSON.stringify( filter.value )
			) {
				recordEvent( 'wcpay_reports_fees_filter_change', {
					filter_field:
						filter.field === 'payment_method'
							? 'payment_method_type'
							: filter.field,
					had_previous_value: previous?.value !== undefined,
				} );
			}
		} );

		applyView( nextView );
	};

	const handlePresetChange = ( nextPreset: string | null ) => {
		if ( ! nextPreset || ! isBalanceDatePreset( nextPreset ) ) {
			return;
		}

		const dateFilter = getBalanceDateFilterForPreset( nextPreset, now );
		const period = getBalancePeriodForDateFilter( dateFilter, now );
		recordEvent( 'wcpay_reports_fees_date_filter_change', {
			preset: nextPreset,
			range_days: getRangeDays( period.date_start, period.date_end ),
			is_initial_apply: ! view.filters?.some(
				( filter: DataViewsFilter ) => filter.field === 'date'
			),
		} );

		applyView( {
			...view,
			page: 1,
			filters: [
				...( view.filters ?? [] ).filter(
					( filter: DataViewsFilter ) => filter.field !== 'date'
				),
				{
					field: 'date',
					operator: dateFilter.operator,
					value: dateFilter.value,
				},
			],
		} );
	};

	// Client 11.1.0 `reports/fees-export-button.tsx:118-200`: the request sends the user's email and
	// locale (added by the data layer) and the outcome is told by snackbars only.
	const handleExport = async () => {
		if ( ! confirmWooPaymentsExport( 'fees', totalItems, query ) ) {
			return;
		}

		setIsExporting( true );
		recordEvent( 'wcpay_csv_export_click', {
			row_type: 'fees',
			source: 'payments_reports',
			exported_row_count: totalItems,
		} );

		await runExport( {
			requestExport: () => requestWooPaymentsReportsFeesExport( query ),
			getExportUrl: getWooPaymentsReportsFeesExportUrl,
			onSuccess: () =>
				recordEvent( 'wcpay_reports_fees_export_success', {
					exported_row_count: totalItems,
				} ),
			onError: ( { reason } ) =>
				recordEvent( 'wcpay_reports_fees_export_error', {
					error_type: reason,
				} ),
		} );
		setIsExporting( false );
	};

	const feesListRef = useDataViewsReloadState< HTMLElement >( isLoading );

	if ( isInitialLoading ) {
		return (
			<div role="status" aria-live="polite" aria-busy="true">
				{ __( 'Loading fees report…', 'woocommerce' ) }
			</div>
		);
	}

	if ( hasError ) {
		return (
			<ReportState
				title={ __( 'Fees report unavailable', 'woocommerce' ) }
				description={ __(
					"We couldn't load your fees data. Try again in a few minutes.",
					'woocommerce'
				) }
				role="alert"
				headingRef={ errorHeadingRef }
				headingTabIndex={ -1 }
				action={
					<Button
						variant="secondary"
						onClick={ () => {
							recordEvent(
								'wcpay_reports_fees_reload_click',
								{}
							);
							void load();
						} }
					>
						{ __( 'Reload report', 'woocommerce' ) }
					</Button>
				}
			/>
		);
	}

	if ( rows.length === 0 && ! hasFilters ) {
		return (
			<ReportState
				title={ __( 'No fees yet', 'woocommerce' ) }
				description={ __(
					'Fees will appear here once you start receiving payments.',
					'woocommerce'
				) }
			/>
		);
	}

	return (
		<section
			ref={ feesListRef }
			className="woocommerce-woopayments-reports-fees"
		>
			<DataViews
				data={ rows }
				fields={ fields }
				view={ view }
				onChangeView={ handleChangeView }
				isLoading={ isLoading }
				search
				searchLabel={ __( 'Search fees', 'woocommerce' ) }
				header={
					<div className="woocommerce-woopayments-reports__toolbar">
						<h2>{ __( 'Fees', 'woocommerce' ) }</h2>
						<WooPaymentsFilterPicker
							filter={ {
								id: 'date-range',
								label: __( 'Date range', 'woocommerce' ),
								value: activeDatePreset,
								options: [
									{
										label: __(
											'Previous month',
											'woocommerce'
										),
										value: 'last_month',
									},
									{
										label: __(
											'Previous year',
											'woocommerce'
										),
										value: 'last_year',
									},
									{
										label: __(
											'Month to date',
											'woocommerce'
										),
										value: 'month_to_date',
									},
									{
										label: __(
											'Year to date',
											'woocommerce'
										),
										value: 'year_to_date',
									},
									{
										label: __( 'Custom', 'woocommerce' ),
										value: 'custom',
									},
								],
								onChange: handlePresetChange,
							} }
						/>
						<Button
							variant="primary"
							onClick={ handleExport }
							disabled={ isExporting }
							accessibleWhenDisabled
							aria-busy={ isExporting }
							isBusy={ isExporting }
						>
							{ __( 'Export', 'woocommerce' ) }
						</Button>
					</div>
				}
				paginationInfo={ {
					totalItems,
					totalPages,
				} }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item: ReportsFee ) => item.transaction_id }
			/>
			{ rows.length === 0 && hasFilters && (
				<ReportState
					title={ __( 'No fees to display', 'woocommerce' ) }
					description={ __(
						'Fees will appear here.',
						'woocommerce'
					) }
				/>
			) }
		</section>
	);
};

export { FeesReport };
