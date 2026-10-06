/**
 * Internal dependencies
 */
import { formatLocalDateBoundaryForApi } from '../money-movement/query';
import type {
	ReportsBalanceQuery,
	ReportsFeesQuery,
	ReportsFeesView,
	ReportsFeesViewFilter,
	ReportsSortDirection,
} from './types';

const FEES_LIST_QUERY_PARAM_ORDER = [
	'page',
	'per_page',
	'sort',
	'direction',
	'date_before',
	'date_after',
	'date_between',
	'payment_method_type',
	'type',
	'search',
	'user_timezone',
] as const;

const FEES_SUMMARY_QUERY_PARAM_ORDER = [
	'date_before',
	'date_after',
	'date_between',
	'payment_method_type',
	'type',
	'search',
	'user_timezone',
] as const;

const FEES_EXPORT_QUERY_PARAM_ORDER = [
	...FEES_SUMMARY_QUERY_PARAM_ORDER,
	'user_email',
	'locale',
] as const;

const SORT_FIELD_BY_COLUMN_ID: Record< string, string > = {
	payment_method: 'source',
	transaction_currency: 'customer_currency',
	deposit_date: 'available_on',
};

const isSortDirection = ( value: unknown ): value is ReportsSortDirection =>
	value === 'asc' || value === 'desc';

const normalizePositiveInteger = (
	value: unknown,
	fallback: number
): number => {
	let parsed = Number.NaN;

	if ( typeof value === 'number' ) {
		parsed = value;
	} else if ( typeof value === 'string' ) {
		parsed = Number( value );
	}

	return Number.isInteger( parsed ) && parsed > 0 ? parsed : fallback;
};

const getUserTimeZone = () => {
	const offset = -new Date().getTimezoneOffset();
	const sign = offset >= 0 ? '+' : '-';
	const absoluteOffset = Math.abs( offset );
	const hours = String( Math.floor( absoluteOffset / 60 ) ).padStart(
		2,
		'0'
	);
	const minutes = String( absoluteOffset % 60 ).padStart( 2, '0' );

	return `${ sign }${ hours }:${ minutes }`;
};

const normalizeStringArray = ( value: unknown ): string[] | undefined => {
	if ( Array.isArray( value ) ) {
		const values = value
			.map( ( item ) => String( item ) )
			.filter( ( item ) => item !== '' );

		return values.length ? values : undefined;
	}

	if ( value === undefined || value === null || value === '' ) {
		return undefined;
	}

	return [ String( value ) ];
};

const getSingleString = ( value: unknown ): string | undefined => {
	if ( Array.isArray( value ) ) {
		return value.find( ( item ) => typeof item === 'string' && item );
	}

	return typeof value === 'string' && value ? value : undefined;
};

const isDateOnly = ( value: string ) => /^\d{4}-\d{2}-\d{2}$/.test( value );

// Client 11.1.0 `data/reports/resolvers.js:36-43`: every date goes through `formatDateValue()`, the start of the day
// for "after" and a range start, the end of the day for "before" and a range end, in the merchant's time zone.
const toDayStart = ( value: string ) =>
	isDateOnly( value ) ? formatLocalDateBoundaryForApi( value, false ) : value;

const toDayEnd = ( value: string ) =>
	isDateOnly( value ) ? formatLocalDateBoundaryForApi( value, true ) : value;

const getDateFilterQuery = (
	value: unknown,
	operator?: string
): Pick< ReportsFeesQuery, 'date_before' | 'date_after' | 'date_between' > => {
	const dateRange = normalizeStringArray( value );

	if ( Array.isArray( dateRange ) && dateRange.length >= 2 ) {
		return {
			date_between: [
				toDayStart( dateRange[ 0 ] ),
				toDayEnd( dateRange[ 1 ] ),
			],
		};
	}

	const date = getSingleString( value );

	if ( ! date ) {
		return {};
	}

	if ( operator && operator.startsWith( 'before' ) ) {
		return { date_before: toDayEnd( date ) };
	}

	if ( operator && operator.startsWith( 'after' ) ) {
		return { date_after: toDayStart( date ) };
	}

	return {
		date_between: [ toDayStart( date ), toDayEnd( date ) ],
	};
};

const addParam = ( params: URLSearchParams, key: string, value: unknown ) => {
	if ( value === undefined || value === null || value === '' ) {
		return;
	}

	if ( Array.isArray( value ) ) {
		value.forEach( ( item ) => addParam( params, `${ key }[]`, item ) );
		return;
	}

	params.append( key, String( value ) );
};

const serializeReportsQuery = (
	query: ReportsFeesQuery,
	paramOrder: readonly string[]
) => {
	const params = new URLSearchParams();

	paramOrder.forEach( ( key ) => {
		addParam( params, key, query[ key as keyof ReportsFeesQuery ] );
	} );

	return params.toString();
};

export const serializeReportsBalanceQuery = (
	query: ReportsBalanceQuery
): string => {
	const params = new URLSearchParams();

	addParam( params, 'date_start', query.date_start );
	addParam( params, 'date_end', query.date_end );
	addParam( params, 'currency', query.currency?.toLowerCase() );

	return params.toString();
};

export const buildReportsFeesQueryFromView = (
	view: Partial< ReportsFeesView >
): ReportsFeesQuery => {
	const query: ReportsFeesQuery = {
		page: normalizePositiveInteger( view.page, 1 ),
		per_page: normalizePositiveInteger( view.perPage, 25 ),
		sort:
			SORT_FIELD_BY_COLUMN_ID[ String( view.sort?.field || '' ) ] ||
			view.sort?.field ||
			'date',
		direction: isSortDirection( view.sort?.direction )
			? view.sort.direction
			: 'desc',
		user_timezone: getUserTimeZone(),
	};

	view.filters?.forEach( ( filter ) => {
		if ( filter.field === 'date' ) {
			Object.assign(
				query,
				getDateFilterQuery( filter.value, filter.operator )
			);
		}

		if ( filter.field === 'payment_method' ) {
			query.payment_method_type = getSingleString( filter.value );
		}

		if ( filter.field === 'type' ) {
			query.type = normalizeStringArray( filter.value );
		}
	} );

	const search = getSingleString( view.search );

	if ( search ) {
		query.search = [ search ];
	}

	return query;
};

export const serializeReportsFeesListQuery = (
	query: ReportsFeesQuery = {}
): string => serializeReportsQuery( query, FEES_LIST_QUERY_PARAM_ORDER );

export const serializeReportsFeesSummaryQuery = (
	query: ReportsFeesQuery = {}
): string => serializeReportsQuery( query, FEES_SUMMARY_QUERY_PARAM_ORDER );

export const serializeReportsFeesExportQuery = (
	query: ReportsFeesQuery = {}
): string => serializeReportsQuery( query, FEES_EXPORT_QUERY_PARAM_ORDER );

export type ReportsFeesUrlView = {
	page: number;
	perPage: number;
	sort: {
		field: string;
		direction: ReportsSortDirection;
	};
	search: string;
	filters: ReportsFeesViewFilter[];
};

const isYmd = ( value: unknown ): value is string =>
	typeof value === 'string' && isDateOnly( value );

/**
 * Every value of a URL param, in its plain, `key[]` and indexed `key[0]` spellings.
 *
 * @param params URL params.
 * @param key    Param name.
 */
const getUrlValues = ( params: URLSearchParams, key: string ): string[] => {
	const values: string[] = [];

	params.forEach( ( value, name ) => {
		if (
			value !== '' &&
			( name === key ||
				( name.startsWith( `${ key }[` ) &&
					/^\[\d*\]$/.test( name.slice( key.length ) ) ) )
		) {
			values.push( value );
		}
	} );

	return values;
};

// Client 11.1.0 `reports/fees/use-fees-url-sync.ts:33-44`: one value, without commas.
const getSingleUrlValue = ( value: unknown ): string | undefined => {
	if ( typeof value !== 'string' ) {
		return undefined;
	}

	const trimmed = value.trim();

	return trimmed === '' || trimmed.includes( ',' ) ? undefined : trimmed;
};

const getFeesDateFilterFromParams = (
	params: URLSearchParams
): ReportsFeesViewFilter | undefined => {
	const between = getUrlValues( params, 'date_between' );

	if (
		between.length === 2 &&
		isYmd( between[ 0 ] ) &&
		isYmd( between[ 1 ] )
	) {
		return between[ 0 ] === between[ 1 ]
			? { field: 'date', operator: 'on', value: between[ 0 ] }
			: {
					field: 'date',
					operator: 'between',
					value: [ between[ 0 ], between[ 1 ] ],
			  };
	}

	const before = params.get( 'date_before' );

	if ( isYmd( before ) ) {
		return { field: 'date', operator: 'before', value: before };
	}

	const after = params.get( 'date_after' );

	if ( isYmd( after ) ) {
		return { field: 'date', operator: 'after', value: after };
	}

	return undefined;
};

/**
 * Read the Fees view from the URL, as client 11.1.0 does in `reports/fees/use-fees-url-sync.ts:58-89,167-195`.
 *
 * @param search The location search string.
 */
export const parseReportsFeesViewFromSearch = (
	search: string
): ReportsFeesUrlView => {
	const params = new URLSearchParams( search );
	const order = params.get( 'order' );
	const filters: ReportsFeesViewFilter[] = [];
	const dateFilter = getFeesDateFilterFromParams( params );
	const paymentMethod = getUrlValues( params, 'payment_method_type' )[ 0 ];
	const types = getUrlValues( params, 'type' );

	if ( dateFilter ) {
		filters.push( dateFilter );
	}

	if ( paymentMethod ) {
		filters.push( {
			field: 'payment_method',
			operator: 'is',
			value: paymentMethod,
		} );
	}

	if ( getSingleUrlValue( types[ 0 ] ) ) {
		filters.push( {
			field: 'type',
			operator: 'is',
			value: getSingleUrlValue( types[ 0 ] ),
		} );
	}

	return {
		page: normalizePositiveInteger( params.get( 'paged' ), 1 ),
		perPage: normalizePositiveInteger( params.get( 'per_page' ), 25 ),
		sort: {
			field: params.get( 'orderby' ) || 'date',
			direction: isSortDirection( order ) ? order : 'desc',
		},
		search: getUrlValues( params, 'search' )[ 0 ]?.split( ',' )[ 0 ] || '',
		filters,
	};
};

const addFeesDateFilterParams = (
	params: URLSearchParams,
	filter: ReportsFeesViewFilter
) => {
	const { operator, value } = filter;

	if ( operator === 'between' ) {
		if ( Array.isArray( value ) && value.length === 2 ) {
			if ( isYmd( value[ 0 ] ) && isYmd( value[ 1 ] ) ) {
				addParam( params, 'date_between', value );
			}
		}
		return;
	}

	if ( ! isYmd( value ) ) {
		return;
	}

	if ( operator === 'on' ) {
		addParam( params, 'date_between', [ value, value ] );
	} else if ( operator === 'before' ) {
		addParam( params, 'date_before', value );
	} else if ( operator === 'after' ) {
		addParam( params, 'date_after', value );
	}
};

/**
 * Write the Fees view to URL params with client 11.1.0's names (`reports/fees/use-fees-url-sync.ts:97-129`):
 * `orderby`, `order`, `paged`, `per_page`, `search`, the date params, `payment_method_type` and `type`.
 *
 * @param view The DataViews view.
 */
export const serializeReportsFeesViewToSearch = (
	view: Partial< ReportsFeesView >
): string => {
	const params = new URLSearchParams();
	const search = getSingleString( view.search );

	addParam( params, 'orderby', view.sort?.field );
	addParam(
		params,
		'order',
		isSortDirection( view.sort?.direction ) ? view.sort?.direction : ''
	);
	addParam( params, 'paged', normalizePositiveInteger( view.page, 1 ) );
	addParam(
		params,
		'per_page',
		normalizePositiveInteger( view.perPage, 25 )
	);
	addParam( params, 'search', search ? [ search ] : undefined );

	view.filters?.forEach( ( filter ) => {
		if ( filter.field === 'date' ) {
			addFeesDateFilterParams( params, filter );
		}

		if ( filter.field === 'payment_method' ) {
			addParam(
				params,
				'payment_method_type',
				getSingleString( filter.value )
			);
		}

		if ( filter.field === 'type' ) {
			addParam( params, 'type', getSingleUrlValue( filter.value ) );
		}
	} );

	return params.toString();
};
