/**
 * External dependencies
 */
import { useNavigate } from 'react-router-dom';
import { getHistory, getNewPath } from '@woocommerce/navigation';
import { getSettings as getDateSettings } from '@wordpress/date';
// @ts-expect-error - Use the WordPress-bundled DataViews entry in wp-admin builds.
import { type View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import type { ReportsBalanceQuery } from './types';

type AsyncState< T > = {
	data?: T;
	isLoading: boolean;
	error?: Error;
};

type BalanceDateFilterValue =
	| {
			operator: 'between';
			value: [ string, string ];
	  }
	| {
			operator: 'on' | 'before' | 'after';
			value: string;
	  };

type BalanceDatePreset =
	'last_month' | 'last_year' | 'month_to_date' | 'year_to_date' | 'custom';

type DataViewsFilter = {
	field: string;
	operator: string;
	value?: unknown;
};

type GlobalSettings = typeof globalThis & {
	wcSettings?: {
		adminUrl?: string;
		dateFormat?: string;
		admin?: {
			woopaymentsSettings?: {
				accountDefaultCurrency?: unknown;
				balanceReportIdentity?: {
					businessName?: string;
					accountId?: string;
				};
			};
		};
	};
};

const getGlobalSettings = () => globalThis as GlobalSettings;

// Client 11.1.0 `class-wc-payments-admin.php:1072` sends wc_date_format(); here it is wcSettings.dateFormat (AssetDataRegistry).
const getDateFormat = () =>
	getGlobalSettings().wcSettings?.dateFormat ||
	getDateSettings().formats.date;

const isSettingsPaymentsSearch = ( search: string ) => {
	const params = new URLSearchParams( search );

	return (
		params.get( 'page' ) === 'wc-settings' &&
		params.get( 'tab' ) === 'checkout'
	);
};

const buildReportsRoute = ( search = '' ) =>
	search ? `/woopayments/reports?${ search }` : '/woopayments/reports';

const buildSettingsReportsRoute = ( search = '' ) => {
	const adminPath = getNewPath(
		{ tab: 'checkout' },
		'/woopayments/reports',
		{},
		'wc-settings'
	);

	return search ? `${ adminPath }&${ search }` : adminPath;
};

const navigateReportsRoute = (
	currentSearch: string,
	navigate: ReturnType< typeof useNavigate >,
	search = ''
) => {
	if ( isSettingsPaymentsSearch( currentSearch ) ) {
		getHistory().push( buildSettingsReportsRoute( search ) );
		return;
	}

	navigate( buildReportsRoute( search ) );
};

const isYmd = ( value: unknown ): value is string =>
	typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( value );

const parseYmd = ( ymd: string ): [ number, number, number ] =>
	ymd.split( '-' ).map( Number ) as [ number, number, number ];

const toYmdUTC = ( date: Date ): string =>
	[
		date.getUTCFullYear(),
		String( date.getUTCMonth() + 1 ).padStart( 2, '0' ),
		String( date.getUTCDate() ).padStart( 2, '0' ),
	].join( '-' );

const toStartOfDayUTC = ( ymd: string ): string => {
	const [ year, month, day ] = parseYmd( ymd );
	return new Date(
		Date.UTC( year, month - 1, day, 0, 0, 0, 0 )
	).toISOString();
};

const toEndOfDayUTC = ( ymd: string ): string => {
	const [ year, month, day ] = parseYmd( ymd );
	return new Date(
		Date.UTC( year, month - 1, day, 23, 59, 59, 999 )
	).toISOString();
};

const getLatestCompleteDayYmd = ( now: Date ): string =>
	toYmdUTC(
		new Date(
			Date.UTC(
				now.getUTCFullYear(),
				now.getUTCMonth(),
				now.getUTCDate() - 1
			)
		)
	);

const minYmd = ( first: string, second: string ): string =>
	first <= second ? first : second;

const sortYmdRange = ( start: string, end: string ): [ string, string ] =>
	start <= end ? [ start, end ] : [ end, start ];

const capYmdAtLatestCompleteDay = ( ymd: string, now: Date ): string =>
	minYmd( ymd, getLatestCompleteDayYmd( now ) );

const getLastFullCalendarMonthDateFilter = (
	now: Date
): BalanceDateFilterValue => {
	const start = new Date(
		Date.UTC( now.getUTCFullYear(), now.getUTCMonth() - 1, 1 )
	);
	const end = new Date(
		Date.UTC( now.getUTCFullYear(), now.getUTCMonth(), 0 )
	);

	return {
		operator: 'between',
		value: [ toYmdUTC( start ), toYmdUTC( end ) ],
	};
};

const getLastFullCalendarYearDateFilter = (
	now: Date
): BalanceDateFilterValue => {
	const previousYear = now.getUTCFullYear() - 1;

	return {
		operator: 'between',
		value: [
			toYmdUTC( new Date( Date.UTC( previousYear, 0, 1 ) ) ),
			toYmdUTC( new Date( Date.UTC( previousYear, 11, 31 ) ) ),
		],
	};
};

const getMonthToDateFilter = ( now: Date ): BalanceDateFilterValue => ( {
	operator: 'between',
	value: [
		toYmdUTC(
			new Date( Date.UTC( now.getUTCFullYear(), now.getUTCMonth(), 1 ) )
		),
		getLatestCompleteDayYmd( now ),
	],
} );

const getYearToDateFilter = ( now: Date ): BalanceDateFilterValue => ( {
	operator: 'between',
	value: [
		toYmdUTC( new Date( Date.UTC( now.getUTCFullYear(), 0, 1 ) ) ),
		getLatestCompleteDayYmd( now ),
	],
} );

const isBalanceDatePreset = ( value: string ): value is BalanceDatePreset =>
	[ 'last_month', 'last_year', 'month_to_date', 'year_to_date' ].includes(
		value
	);

const getBalanceDateFilterForPreset = (
	preset: BalanceDatePreset,
	now: Date
): BalanceDateFilterValue => {
	if ( preset === 'month_to_date' ) {
		return getMonthToDateFilter( now );
	}

	if ( preset === 'year_to_date' ) {
		return getYearToDateFilter( now );
	}

	if ( preset === 'last_year' ) {
		return getLastFullCalendarYearDateFilter( now );
	}

	return getLastFullCalendarMonthDateFilter( now );
};

const normalizeBalanceDateFilter = (
	value: BalanceDateFilterValue,
	now: Date
): BalanceDateFilterValue => {
	if ( value.operator === 'between' ) {
		const [ start, end ] = sortYmdRange(
			capYmdAtLatestCompleteDay( value.value[ 0 ], now ),
			capYmdAtLatestCompleteDay( value.value[ 1 ], now )
		);

		return {
			operator: 'between',
			value: [ start, end ],
		};
	}

	return {
		operator: value.operator,
		value: capYmdAtLatestCompleteDay( value.value, now ),
	};
};

const getMonthStartYmd = ( ymd: string ): string => {
	const [ year, month ] = parseYmd( ymd );
	return toYmdUTC( new Date( Date.UTC( year, month - 1, 1 ) ) );
};

const getMonthEndYmd = ( ymd: string ): string => {
	const [ year, month ] = parseYmd( ymd );
	return toYmdUTC( new Date( Date.UTC( year, month, 0 ) ) );
};

const getBalancePeriodForDateFilter = (
	value: BalanceDateFilterValue,
	now: Date
): Required< Pick< ReportsBalanceQuery, 'date_start' | 'date_end' > > => {
	const normalized = normalizeBalanceDateFilter( value, now );

	if ( normalized.operator === 'between' ) {
		return {
			date_start: toStartOfDayUTC( normalized.value[ 0 ] ),
			date_end: toEndOfDayUTC( normalized.value[ 1 ] ),
		};
	}

	if ( normalized.operator === 'on' ) {
		return {
			date_start: toStartOfDayUTC( normalized.value ),
			date_end: toEndOfDayUTC( normalized.value ),
		};
	}

	if ( normalized.operator === 'before' ) {
		return {
			date_start: toStartOfDayUTC( getMonthStartYmd( normalized.value ) ),
			date_end: toEndOfDayUTC( normalized.value ),
		};
	}

	return {
		date_start: toStartOfDayUTC( normalized.value ),
		date_end: toEndOfDayUTC(
			minYmd(
				getMonthEndYmd( normalized.value ),
				getLatestCompleteDayYmd( now )
			)
		),
	};
};

const getRangeDays = ( start?: string, end?: string ): number | null => {
	if ( ! start || ! end ) {
		return null;
	}

	return Math.round(
		( new Date( end ).getTime() - new Date( start ).getTime() ) / 86400000
	);
};

const getBalanceDateFilterFromView = (
	view: View,
	fallback: BalanceDateFilterValue,
	now: Date
): BalanceDateFilterValue => {
	const dateFilter = ( view.filters as DataViewsFilter[] | undefined )?.find(
		( filter ) => filter.field === 'date'
	);

	if ( ! dateFilter ) {
		return getLastFullCalendarMonthDateFilter( now );
	}

	if (
		dateFilter.operator === 'between' &&
		Array.isArray( dateFilter.value ) &&
		isYmd( dateFilter.value[ 0 ] ) &&
		isYmd( dateFilter.value[ 1 ] )
	) {
		return normalizeBalanceDateFilter(
			{
				operator: 'between',
				value: [ dateFilter.value[ 0 ], dateFilter.value[ 1 ] ],
			},
			now
		);
	}

	if (
		( dateFilter.operator === 'on' ||
			dateFilter.operator === 'before' ||
			dateFilter.operator === 'after' ) &&
		isYmd( dateFilter.value )
	) {
		return normalizeBalanceDateFilter(
			{
				operator: dateFilter.operator,
				value: dateFilter.value,
			},
			now
		);
	}

	return fallback;
};

const matchBalanceDatePreset = (
	value: BalanceDateFilterValue,
	now: Date
): BalanceDatePreset => {
	if ( value.operator !== 'between' ) {
		return 'custom';
	}

	const lastMonth = getLastFullCalendarMonthDateFilter( now );

	if (
		value.value[ 0 ] === lastMonth.value[ 0 ] &&
		value.value[ 1 ] === lastMonth.value[ 1 ]
	) {
		return 'last_month';
	}

	const lastYear = getLastFullCalendarYearDateFilter( now );

	if (
		value.value[ 0 ] === lastYear.value[ 0 ] &&
		value.value[ 1 ] === lastYear.value[ 1 ]
	) {
		return 'last_year';
	}

	const monthToDate = getMonthToDateFilter( now );

	if (
		value.value[ 0 ] === monthToDate.value[ 0 ] &&
		value.value[ 1 ] === monthToDate.value[ 1 ]
	) {
		return 'month_to_date';
	}

	const yearToDate = getYearToDateFilter( now );

	if (
		value.value[ 0 ] === yearToDate.value[ 0 ] &&
		value.value[ 1 ] === yearToDate.value[ 1 ]
	) {
		return 'year_to_date';
	}

	return 'custom';
};

export type { AsyncState, BalanceDateFilterValue, DataViewsFilter };

export {
	getGlobalSettings,
	getDateFormat,
	isSettingsPaymentsSearch,
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
};
