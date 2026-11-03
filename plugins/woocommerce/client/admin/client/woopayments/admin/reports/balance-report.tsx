/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { dateI18n } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	type ReactNode,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { recordEvent } from '@woocommerce/tracks';
import {
	downloadCSVFile,
	generateCSVDataFromTable,
} from '@woocommerce/csv-export';
// @ts-expect-error - Use the WordPress-bundled DataViews entry in wp-admin builds.
import { DataViews, type Field, type View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { getWooPaymentsReportsBalanceSummary } from './data';
import { ReportState } from './report-state';
import { useDataViewsReloadState } from '../money-movement/use-dataviews-reload-state';
import { ReportsDatePresets } from './date-presets';
import type {
	ReportsBalanceQuery,
	ReportsBalanceSummary,
	ReportsBalanceSummaryRow,
} from './types';
import { formatExplicitCurrency } from '../money-movement/utils';
import { getWooPaymentsAmountFromMinorUnits } from '../../currency';
import {
	type AsyncState,
	type BalanceDateFilterValue,
	getDateFormat,
	getGlobalSettings,
	navigateReportsRoute,
	isYmd,
	getLastFullCalendarMonthDateFilter,
	isBalanceDatePreset,
	getBalanceDateFilterForPreset,
	normalizeBalanceDateFilter,
	getBalancePeriodForDateFilter,
	getRangeDays,
	getBalanceDateFilterFromView,
	matchBalanceDatePreset,
} from './utils';

type BalanceRow = {
	id: string;
	label: string;
	amount: number;
	count?: number;
	// Client 11.2.0 `reports/balance/rows.ts:36-49`: the indent level; top-level rows have none.
	depth?: number;
	alwaysVisible?: boolean;
};

type BalancePeriod = { start: string; end: string };

// Client 11.2.0 `reports/balance/utils.ts:72-77`: the site date format, read in UTC.
const formatUtcDate = ( value: string ) =>
	sprintf(
		/* translators: %s: date rendered in the site's date format. */
		__( '%s UTC', 'woocommerce' ),
		dateI18n( getDateFormat(), value, 'UTC' )
	);

/**
 * Format a balance summary amount with an explicit leading `+` on inflows.
 *
 * Client 11.1.0 `reports/balance/format.ts:106-114`: outflows already carry `-`, and zero stays unsigned.
 *
 * @param amount       Amount in minor units.
 * @param currencyCode Currency code.
 */
const formatBalanceAmount = ( amount: number, currencyCode: string ) => {
	const formatted = formatExplicitCurrency( amount, currencyCode );

	return amount > 0 ? `+${ formatted }` : formatted;
};

const getBalanceReportIdentity = () => {
	const identity =
		getGlobalSettings().wcSettings?.admin?.woopaymentsSettings
			?.balanceReportIdentity;

	return {
		businessName:
			typeof identity?.businessName === 'string'
				? identity.businessName.trim()
				: '',
		accountId:
			typeof identity?.accountId === 'string'
				? identity.accountId.trim()
				: '',
	};
};

const getBalanceQuery = (
	now: Date,
	currency: string,
	dateFilter: BalanceDateFilterValue
): ReportsBalanceQuery => ( {
	...getBalancePeriodForDateFilter( dateFilter, now ),
	currency,
} );

const getBalanceDateFilterFromSearch = (
	search: string,
	now: Date
): BalanceDateFilterValue => {
	const params = new URLSearchParams( search );
	const between = [
		...params.getAll( 'date_between[]' ),
		...params.getAll( 'date_between' ),
	].filter( isYmd );

	if ( between.length >= 2 ) {
		return normalizeBalanceDateFilter(
			{
				operator: 'between',
				value: [ between[ 0 ], between[ 1 ] ],
			},
			now
		);
	}

	const dateStart = params.get( 'date_start' )?.slice( 0, 10 );
	const dateEnd = params.get( 'date_end' )?.slice( 0, 10 );
	if ( isYmd( dateStart ) && isYmd( dateEnd ) ) {
		return normalizeBalanceDateFilter(
			{
				operator: 'between',
				value: [ dateStart, dateEnd ],
			},
			now
		);
	}

	const dateBefore = params.get( 'date_before' );
	if ( isYmd( dateBefore ) ) {
		return normalizeBalanceDateFilter(
			{
				operator: 'before',
				value: dateBefore,
			},
			now
		);
	}

	const dateAfter = params.get( 'date_after' );
	if ( isYmd( dateAfter ) ) {
		return normalizeBalanceDateFilter(
			{
				operator: 'after',
				value: dateAfter,
			},
			now
		);
	}

	return getLastFullCalendarMonthDateFilter( now );
};

const serializeBalanceDateFilterToSearch = (
	value: BalanceDateFilterValue
): string => {
	const params = new URLSearchParams();

	if ( value.operator === 'between' ) {
		params.append( 'date_between[]', value.value[ 0 ] );
		params.append( 'date_between[]', value.value[ 1 ] );
		return params.toString();
	}

	if ( value.operator === 'on' ) {
		params.append( 'date_between[]', value.value );
		params.append( 'date_between[]', value.value );
		return params.toString();
	}

	params.set(
		value.operator === 'before' ? 'date_before' : 'date_after',
		value.value
	);
	return params.toString();
};

// Client 11.2.0 `reports/balance/index.tsx:227-230`: the summary's own period, else the requested one.
const getBalancePeriod = (
	summary: ReportsBalanceSummary,
	query: ReportsBalanceQuery
): BalancePeriod => ( {
	start: summary.period?.start ?? query.date_start ?? '',
	end: summary.period?.end ?? query.date_end ?? '',
} );

const getBalanceAmount = (
	row: ReportsBalanceSummaryRow | undefined
): number => row?.amount ?? 0;

/**
 * The Balance rows, in the client's order, with its indent levels, its counted rows and its dated anchor labels.
 *
 * Client 11.2.0 `reports/balance/rows.ts:110-232` and `reports/balance/utils.ts:79-100`.
 *
 * @param summary Balance summary.
 * @param period  The period the anchor labels name: the summary's own, else the requested one.
 */
const getBalanceRows = (
	summary: ReportsBalanceSummary,
	period: BalancePeriod
): BalanceRow[] =>
	[
		{
			id: 'starting_balance',
			label: sprintf(
				/* translators: %s: period start date. */
				__( 'Starting balance - %s', 'woocommerce' ),
				formatUtcDate( period.start )
			),
			amount: getBalanceAmount( summary.starting_balance ),
			alwaysVisible: true,
		},
		{
			id: 'total_charges_captured',
			label: __( 'Total charges captured', 'woocommerce' ),
			amount: getBalanceAmount( summary.total_charges_captured ),
			count: summary.total_charges_captured?.count,
			alwaysVisible: true,
		},
		{
			id: 'fees',
			label: __( 'Fees', 'woocommerce' ),
			amount: getBalanceAmount( summary.fees ),
			depth: 1,
			alwaysVisible: true,
		},
		{
			id: 'charge_fees',
			label: __( 'Charge fees', 'woocommerce' ),
			amount: getBalanceAmount( summary.charge_fees ),
			depth: 2,
		},
		{
			id: 'dispute_fees',
			label: __( 'Dispute fees', 'woocommerce' ),
			amount: getBalanceAmount( summary.dispute_fees ),
			depth: 2,
		},
		{
			id: 'fee_refunds',
			label: __( 'Fee refunds', 'woocommerce' ),
			amount: getBalanceAmount( summary.fee_refunds ),
			depth: 2,
		},
		{
			id: 'refunds',
			label: __( 'Refunds', 'woocommerce' ),
			amount: getBalanceAmount( summary.refunds ),
			count: summary.refunds?.count,
			depth: 1,
		},
		{
			id: 'refund_failure',
			label: __( 'Refund failures', 'woocommerce' ),
			amount: getBalanceAmount( summary.refund_failure ),
			count: summary.refund_failure?.count,
			depth: 1,
		},
		{
			id: 'disputes',
			label: __( 'Disputes', 'woocommerce' ),
			amount: getBalanceAmount( summary.disputes ),
			count: summary.disputes?.count,
			depth: 1,
		},
		{
			id: 'financing_payout',
			label: __( 'Financing payout', 'woocommerce' ),
			amount: getBalanceAmount( summary.financing_payout ),
			count: summary.financing_payout?.count,
			depth: 1,
		},
		{
			id: 'financing_paydown',
			label: __( 'Financing paydown', 'woocommerce' ),
			amount: getBalanceAmount( summary.financing_paydown ),
			count: summary.financing_paydown?.count,
			depth: 1,
		},
		{
			id: 'payout_fees',
			label: __( 'Payout fees', 'woocommerce' ),
			amount: getBalanceAmount( summary.payout_fees ),
			depth: 1,
		},
		{
			id: 'reader_fees',
			label: __( 'Reader costs', 'woocommerce' ),
			amount: getBalanceAmount( summary.reader_fees ),
			depth: 1,
		},
		{
			id: 'network_costs',
			label: __( 'Network costs', 'woocommerce' ),
			amount: getBalanceAmount( summary.network_costs ),
			count: summary.network_costs?.count,
			depth: 1,
		},
		{
			id: 'other_adjustments',
			label: __( 'Other adjustments', 'woocommerce' ),
			amount: getBalanceAmount( summary.other_adjustments ),
			count: summary.other_adjustments?.count,
			depth: 1,
		},
		{
			id: 'net_balance_change_in_the_period',
			label: __( 'Net balance change in the period', 'woocommerce' ),
			amount: getBalanceAmount(
				summary.net_balance_change_in_the_period
			),
			alwaysVisible: true,
		},
		{
			id: 'payouts',
			label: __( 'Payouts', 'woocommerce' ),
			amount: getBalanceAmount( summary.payouts ),
			count: summary.payouts?.count,
			depth: 1,
			alwaysVisible: true,
		},
		{
			id: 'ending_balance',
			label: sprintf(
				/* translators: %s: period end date. */
				__( 'Ending balance - %s', 'woocommerce' ),
				formatUtcDate( period.end )
			),
			amount: getBalanceAmount( summary.ending_balance ),
			alwaysVisible: true,
		},
	].filter(
		( row ) =>
			row.alwaysVisible ||
			row.amount !== 0 ||
			Number( row.count ?? 0 ) > 0
	);

const hasBalanceActivity = ( rows: BalanceRow[] ) =>
	rows.some(
		( row ) =>
			row.id !== 'starting_balance' &&
			row.id !== 'ending_balance' &&
			( row.amount !== 0 || Number( row.count ?? 0 ) > 0 )
	);

/**
 * The amount a row shows, on screen and in the CSV: Payouts always leave the balance, so a non-zero amount shows as negative.
 *
 * Client 11.2.0 `reports/balance/rows.ts:80-89,222` (`displayNegative`).
 *
 * @param row Balance row.
 */
const getDisplayedBalanceAmount = ( row: BalanceRow ) =>
	row.id === 'payouts' && row.amount !== 0
		? -Math.abs( row.amount )
		: row.amount;

const getBalanceCsv = (
	rows: BalanceRow[],
	summary: ReportsBalanceSummary,
	currency: string
) => {
	const identity = getBalanceReportIdentity();
	const periodStart = summary.period?.start?.slice( 0, 10 ) || '';
	const periodEnd = summary.period?.end?.slice( 0, 10 ) || '';

	return generateCSVDataFromTable(
		[
			{ key: 'business_name', label: 'business_name' },
			{
				key: 'woopayments_account_id',
				label: 'woopayments_account_id',
			},
			{ key: 'row_key', label: 'row_key' },
			{ key: 'label', label: 'label' },
			{ key: 'amount', label: 'amount' },
			{ key: 'count', label: 'count' },
			{ key: 'currency', label: 'currency' },
			{ key: 'period_start', label: 'period_start' },
			{ key: 'period_end', label: 'period_end' },
		],
		rows.map( ( row ) => [
			{ value: identity.businessName, display: identity.businessName },
			{ value: identity.accountId, display: identity.accountId },
			{ value: row.id, display: row.id },
			{ value: row.label, display: row.label },
			{
				value: getWooPaymentsAmountFromMinorUnits(
					getDisplayedBalanceAmount( row ),
					currency
				),
				display: '',
			},
			{ value: row.count ?? '', display: String( row.count ?? '' ) },
			{ value: currency.toLowerCase(), display: currency.toLowerCase() },
			{ value: periodStart, display: periodStart },
			{ value: periodEnd, display: periodEnd },
		] )
	);
};

const getBalanceCsvFileName = ( summary: ReportsBalanceSummary ) => {
	const periodStart = summary.period?.start?.slice( 0, 10 ) || 'export';
	const periodEnd = summary.period?.end?.slice( 0, 10 ) || 'export';

	// Client 11.2.0 `reports/balance/format.ts:22-27`.
	return `wcpay-balance-${ periodStart }_${ periodEnd }.csv`;
};

const BalanceReport = ( { now }: { now: Date } ) => {
	const location = useLocation();
	const navigate = useNavigate();
	// Client 11.1.0 `reports/balance/index.tsx:177`: the account's default currency, which the server preloads.
	const configuredCurrency =
		getGlobalSettings().wcSettings?.admin?.woopaymentsSettings
			?.accountDefaultCurrency;
	// The preload passes through settings filters, so check the value before it reaches the query.
	const currency =
		typeof configuredCurrency === 'string' &&
		/^[a-z]{3}$/i.test( configuredCurrency )
			? configuredCurrency
			: 'usd';
	const dateFilter = useMemo(
		() => getBalanceDateFilterFromSearch( location.search, now ),
		[ location.search, now ]
	);
	const query = useMemo(
		() => getBalanceQuery( now, currency, dateFilter ),
		[ currency, dateFilter, now ]
	);
	const [ state, setState ] = useState< AsyncState< ReportsBalanceSummary > >(
		{
			isLoading: true,
		}
	);
	const errorHeadingRef = useRef< HTMLHeadingElement >( null );
	const [ exportStatus, setExportStatus ] = useState( '' );
	const mountedRef = useRef( true );
	// Only the latest request may write: a slower answer for an earlier query must not replace the current one.
	const requestIdRef = useRef( 0 );

	useEffect(
		() => () => {
			mountedRef.current = false;
		},
		[]
	);

	const load = useCallback( async () => {
		const requestId = ++requestIdRef.current;
		const isCurrent = () =>
			mountedRef.current && requestId === requestIdRef.current;

		setState( ( previous ) => ( {
			...previous,
			isLoading: true,
		} ) );

		try {
			const summary = await getWooPaymentsReportsBalanceSummary( query );
			const rows = getBalanceRows(
				summary,
				getBalancePeriod( summary, query )
			);
			const rowsHaveActivity = hasBalanceActivity( rows );

			if ( ! isCurrent() ) {
				return;
			}

			setState( {
				data: summary,
				error: undefined,
				isLoading: false,
			} );
			recordEvent( 'wcpay_reports_balance_load_success', {
				currency: ( summary.currency || currency ).toLowerCase(),
				has_activity: rowsHaveActivity,
				visible_row_count: rows.length,
			} );
			if ( rowsHaveActivity ) {
				speak(
					sprintf(
						/* translators: %d: number of balance rows loaded into the report. */
						__( '%d balance report rows loaded.', 'woocommerce' ),
						rows.length
					),
					'polite'
				);
			}
		} catch ( error ) {
			if ( ! isCurrent() ) {
				return;
			}

			setState( {
				error: error as Error,
				isLoading: false,
			} );
			recordEvent( 'wcpay_reports_balance_load_error', {
				error_type: 'request',
			} );
			speak(
				__( 'Balance report could not be loaded.', 'woocommerce' ),
				'assertive'
			);
		}
	}, [ currency, query ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	useEffect( () => {
		if ( state.error ) {
			errorHeadingRef.current?.focus();
		}
	}, [ state.error ] );

	const summary = state.data || {};
	const rows = getBalanceRows( summary, getBalancePeriod( summary, query ) );
	const hasActivity = hasBalanceActivity( rows );
	const tableRows = state.error ? [] : rows;
	const reportCurrency = summary.currency || currency;
	const isInitialLoading = state.isLoading && ! state.data && ! state.error;
	const fields = useMemo< Field< BalanceRow >[] >(
		() => [
			{
				id: 'date',
				label: __( 'Date', 'woocommerce' ),
				type: 'date',
				enableHiding: false,
				enableSorting: false,
				filterBy: {
					operators: [ 'between', 'before', 'after', 'on' ],
				},
				getValue: () => query.date_start || '',
			},
			{
				id: 'label',
				label: __( 'Description', 'woocommerce' ),
				// Client 11.2.0 `reports/balance/balance-dataview.tsx:186-191`: plain header text, no sort or hide menu.
				enableHiding: false,
				enableSorting: false,
				getValue: ( { item }: { item: BalanceRow } ) => item.label,
				// Client 11.2.0 `reports/balance/balance-dataview.tsx:193-223`: the indent level and a decorative count badge,
				// with the count spelled out for screen readers.
				render: ( { item }: { item: BalanceRow } ) => (
					<span
						className={ `woocommerce-woopayments-reports-balance__label woocommerce-woopayments-reports-balance__label--depth-${
							item.depth ?? 0
						}` }
					>
						<span>{ item.label }</span>
						{ typeof item.count === 'number' && (
							<>
								<span
									aria-hidden="true"
									className="woocommerce-woopayments-reports-balance__count"
								>
									{ item.count }
								</span>
								<span className="screen-reader-text">
									{ sprintf(
										/* translators: %d: number of ledger entries included in this Balance row. */
										_n(
											'%d item',
											'%d items',
											item.count,
											'woocommerce'
										),
										item.count
									) }
								</span>
							</>
						) }
					</span>
				),
			},
			{
				id: 'amount',
				label: __( 'Amount', 'woocommerce' ),
				type: 'integer',
				enableHiding: false,
				enableSorting: false,
				// Client 11.2.0 `reports/balance/balance-dataview.tsx:225-239`: the period is the only Balance filter.
				filterBy: false,
				getValue: ( { item }: { item: BalanceRow } ) =>
					getDisplayedBalanceAmount( item ),
				render: ( { item }: { item: BalanceRow } ) => (
					<span
						className={ `woocommerce-woopayments-reports-balance__amount woocommerce-woopayments-reports-balance__amount--depth-${
							item.depth ?? 0
						}` }
					>
						{ formatBalanceAmount(
							getDisplayedBalanceAmount( item ),
							reportCurrency
						) }
					</span>
				),
			},
		],
		[ query.date_start, reportCurrency ]
	);
	const view = useMemo< View >(
		() => ( {
			type: 'table',
			page: 1,
			perPage: tableRows.length || 1,
			fields: [ 'label', 'amount' ],
			filters: [
				{
					field: 'date',
					operator: dateFilter.operator,
					value: dateFilter.value,
				},
			],
			layout: {
				enableMoving: false,
			},
		} ),
		[ dateFilter.operator, dateFilter.value, tableRows.length ]
	);
	const activeDatePreset = matchBalanceDatePreset( dateFilter, now );

	const changeDateFilter = ( nextDateFilter: BalanceDateFilterValue ) => {
		if (
			JSON.stringify( nextDateFilter ) === JSON.stringify( dateFilter )
		) {
			return;
		}

		const nextPeriod = getBalancePeriodForDateFilter( nextDateFilter, now );
		recordEvent( 'wcpay_reports_balance_date_filter_change', {
			preset: matchBalanceDatePreset( nextDateFilter, now ),
			range_days: getRangeDays(
				nextPeriod.date_start,
				nextPeriod.date_end
			),
			is_initial_apply: false,
		} );

		const search = serializeBalanceDateFilterToSearch( nextDateFilter );
		navigateReportsRoute( location.search, navigate, search );
	};

	const handleChangeView = ( nextView: View ) => {
		changeDateFilter(
			getBalanceDateFilterFromView( nextView, dateFilter, now )
		);
	};

	const handlePresetChange = ( nextPreset: string | null ) => {
		if ( ! nextPreset || ! isBalanceDatePreset( nextPreset ) ) {
			return;
		}

		changeDateFilter( getBalanceDateFilterForPreset( nextPreset, now ) );
	};

	const balanceListRef = useDataViewsReloadState< HTMLElement >(
		state.isLoading
	);
	const filtersOpenedRef = useRef( false );

	// Client 11.2.0 `reports/balance/balance-dataview.tsx:47-67,152-158`: the date chip shows from the start, once per mount,
	// since the date presets live in its popover. DataViews has no prop for it, so this clicks its own toggle.
	useEffect( () => {
		if ( filtersOpenedRef.current || ! balanceListRef.current ) {
			return;
		}

		balanceListRef.current
			.querySelector< HTMLButtonElement >(
				'.dataviews-filters__visibility-toggle[aria-pressed="false"]:not([aria-haspopup])'
			)
			?.click();
		filtersOpenedRef.current = true;
	}, [ balanceListRef, isInitialLoading ] );

	if ( isInitialLoading ) {
		return (
			<div role="status" aria-live="polite" aria-busy="true">
				{ __( 'Loading balance report…', 'woocommerce' ) }
			</div>
		);
	}

	let balanceState: ReactNode = null;

	if ( state.error ) {
		balanceState = (
			<ReportState
				title={ __( 'Balance report unavailable', 'woocommerce' ) }
				description={ __(
					"We couldn't load your balance report. Try again in a few minutes.",
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
								'wcpay_reports_balance_reload_click',
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
	} else if ( ! hasActivity ) {
		balanceState = (
			<ReportState
				title={ __( 'No balance activity', 'woocommerce' ) }
				description={ __(
					'No balance activity was found for the selected period. Summary rows are shown with zero amounts.',
					'woocommerce'
				) }
			/>
		);
	}

	return (
		<section
			ref={ balanceListRef }
			className="woocommerce-woopayments-reports-balance"
		>
			<div className="woocommerce-woopayments-reports__toolbar">
				<h2>{ __( 'Balance summary', 'woocommerce' ) }</h2>
				<div className="woocommerce-woopayments-reports__actions">
					<Button
						variant="secondary"
						onClick={ () => {
							recordEvent( 'wcpay_reports_balance_print_click', {
								visible_row_count: rows.length,
							} );
							window.print();
						} }
					>
						{ __( 'Print', 'woocommerce' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => {
							const payload = { visible_row_count: rows.length };
							// Client 11.1.0 `reports/balance/actions.tsx:131-147`.
							recordEvent(
								'wcpay_reports_balance_export_click',
								payload
							);
							try {
								downloadCSVFile(
									getBalanceCsvFileName( summary ),
									getBalanceCsv(
										rows,
										summary,
										reportCurrency
									)
								);
								recordEvent(
									'wcpay_reports_balance_export_success',
									payload
								);
							} catch ( exportError ) {
								recordEvent(
									'wcpay_reports_balance_export_error',
									{
										...payload,
										error_message:
											exportError instanceof Error
												? exportError.message.slice(
														0,
														200
													)
												: 'unknown',
									}
								);
								throw exportError;
							}
							setExportStatus(
								__(
									'Balance report export started.',
									'woocommerce'
								)
							);
						} }
					>
						{ __( 'Export', 'woocommerce' ) }
					</Button>
				</div>
			</div>
			<div
				role="status"
				aria-live="polite"
				aria-atomic="true"
				aria-label={ __( 'Balance export status', 'woocommerce' ) }
			>
				{ exportStatus }
			</div>
			<ReportsDatePresets
				selectedPreset={ activeDatePreset }
				onPresetChange={ handlePresetChange }
			/>
			<DataViews
				data={ tableRows }
				fields={ fields }
				view={ view }
				onChangeView={ handleChangeView }
				isLoading={ state.isLoading }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: tableRows.length,
					totalPages: 1,
				} }
				getItemId={ ( item: BalanceRow ) => item.id }
			>
				{ /* Client 11.2.0 `reports/balance/balance-dataview.tsx:326-365`: only the date filter and the rows, with no
				   search box, view options or pagination. */ }
				<div className="dataviews__view-actions">
					<DataViews.FiltersToggle />
				</div>
				<DataViews.FiltersToggled className="dataviews-filters__container" />
				<DataViews.Layout />
			</DataViews>
			{ balanceState }
		</section>
	);
};

export { BalanceReport };
