/**
 * Internal dependencies
 */
import type {
	WooPaymentsMoneyMovementDataView,
	WooPaymentsMoneyMovementDataViewFilter,
	WooPaymentsMoneyMovementDataViewFilterOperator,
	WooPaymentsMoneyMovementQuery,
	WooPaymentsMoneyMovementQueryFilterParam,
	WooPaymentsMoneyMovementRouteLocation,
	WooPaymentsMoneyMovementSortDirection,
} from './types';

export const DEFAULT_MONEY_MOVEMENT_QUERY: Required<
	Pick< WooPaymentsMoneyMovementQuery, 'page' | 'pagesize' >
> = {
	page: 1,
	pagesize: 25,
};

export const MONEY_MOVEMENT_FILTER_PARAMS = [
	'loan_id_is',
	'deposit_id',
	'store_currency_is',
	'type_is',
	// Client 11.1.0 `data/transactions/resolvers.js:27-57`: the transactions advanced filters.
	'type_is_not',
	'customer_country_is',
	'customer_country_is_not',
	'customer_currency_is',
	'customer_currency_is_not',
	'source_device_is',
	'source_device_is_not',
	'source_is',
	'source_is_not',
	'channel_is',
	'channel_is_not',
	'risk_level_is',
	'risk_level_is_not',
	'status_is',
	'status_is_not',
	'date_after',
	'date_before',
	'date_between',
] as const satisfies readonly WooPaymentsMoneyMovementQueryFilterParam[];

const QUERY_PARAM_ORDER = [
	'page',
	'pagesize',
	'sort',
	'direction',
	'search',
	...MONEY_MOVEMENT_FILTER_PARAMS,
] as const;

// Client 11.1.0 TableCard lists keep the sort in `orderby`/`order`; the API takes `sort`/`direction`.
const SORT_URL_PARAMS = {
	sort: 'orderby',
	direction: 'order',
} as const;

// Client 11.1.0 `data/authorizations/hooks.ts:31-37`: the uncaptured list sends only paging and sorting, which keeps
// its store-wide summary (count and total) in step with the rows.
const AUTHORIZATION_QUERY_PARAM_ORDER = [
	'page',
	'pagesize',
	'sort',
	'direction',
] as const;

const FILTER_FIELD_ALIASES: Record<
	string,
	WooPaymentsMoneyMovementQueryFilterParam
> = {
	loan_id: 'loan_id_is',
	loan_id_is: 'loan_id_is',
	deposit: 'deposit_id',
	deposit_id: 'deposit_id',
	currency: 'store_currency_is',
	store_currency: 'store_currency_is',
	store_currency_is: 'store_currency_is',
	type: 'type_is',
	type_is: 'type_is',
	status: 'status_is',
	status_is: 'status_is',
	status_is_not: 'status_is_not',
	customer_country_is: 'customer_country_is',
	customer_currency_is: 'customer_currency_is',
	source_device_is: 'source_device_is',
	source_is: 'source_is',
	channel_is: 'channel_is',
	risk_level_is: 'risk_level_is',
	date_after: 'date_after',
	date_before: 'date_before',
	date_between: 'date_between',
};

const FILTER_PARAM_TO_FIELD: Partial<
	Record< WooPaymentsMoneyMovementQueryFilterParam, string >
> = {
	store_currency_is: 'currency',
	type_is: 'type',
	type_is_not: 'type',
	status_is: 'status',
	status_is_not: 'status',
	loan_id_is: 'loan_id_is',
	customer_country_is_not: 'customer_country_is',
	customer_currency_is_not: 'customer_currency_is',
	source_device_is_not: 'source_device_is',
	source_is_not: 'source_is',
	channel_is_not: 'channel_is',
	risk_level_is_not: 'risk_level_is',
	date_after: 'date',
	date_before: 'date',
	date_between: 'date',
};

// Filter fields whose "Is not" goes to the client's `<name>_is_not` argument.
const IS_NOT_FILTER_PARAMS: Record<
	string,
	WooPaymentsMoneyMovementQueryFilterParam
> = {
	status: 'status_is_not',
	status_is: 'status_is_not',
	type: 'type_is_not',
	customer_country_is: 'customer_country_is_not',
	customer_currency_is: 'customer_currency_is_not',
	source_device_is: 'source_device_is_not',
	source_is: 'source_is_not',
	channel_is: 'channel_is_not',
	risk_level_is: 'risk_level_is_not',
};

const DATE_FILTER_OPERATOR_BY_PARAM: Partial<
	Record<
		WooPaymentsMoneyMovementQueryFilterParam,
		WooPaymentsMoneyMovementDataViewFilterOperator
	>
> = {
	date_after: 'after',
	date_before: 'before',
	date_between: 'between',
};

const isDateFilterParam = (
	param: WooPaymentsMoneyMovementQueryFilterParam
): param is 'date_after' | 'date_before' | 'date_between' =>
	param === 'date_after' ||
	param === 'date_before' ||
	param === 'date_between';

/**
 * The merchant's calendar day, `YYYY-MM-DD`, for a date filter value: a bare day as is, or the browser-local day of a
 * date-time, as client 11.1.0 `formatDateValue()` takes the day of any date Moment parses (`client/utils/index.js:244-255`).
 *
 * @param value The filter value.
 */
export const normalizeLocalCalendarDate = (
	value: unknown
): string | undefined => {
	if ( typeof value !== 'string' ) {
		return undefined;
	}

	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value );

	if ( ! match ) {
		const dateTime = new Date( value );
		if (
			! /\d{2}:\d{2}/.test( value ) ||
			Number.isNaN( dateTime.getTime() )
		) {
			return undefined;
		}
		const pad = ( part: number ) => String( part ).padStart( 2, '0' );

		return `${ dateTime.getFullYear() }-${ pad(
			dateTime.getMonth() + 1
		) }-${ pad( dateTime.getDate() ) }`;
	}

	const year = Number( match[ 1 ] );
	const month = Number( match[ 2 ] );
	const day = Number( match[ 3 ] );
	const date = new Date( 0 );

	date.setUTCHours( 0, 0, 0, 0 );
	date.setUTCFullYear( year, month - 1, day );

	return date.getUTCFullYear() === year &&
		date.getUTCMonth() === month - 1 &&
		date.getUTCDate() === day
		? value
		: undefined;
};

const normalizeDateFilterValue = (
	param: 'date_after' | 'date_before' | 'date_between',
	value: unknown
): string | string[] | undefined => {
	if ( param === 'date_between' ) {
		if ( ! Array.isArray( value ) || value.length !== 2 ) {
			return undefined;
		}

		const dates = value.map( normalizeLocalCalendarDate );

		return dates.every( ( date ): date is string => date !== undefined )
			? dates.sort()
			: undefined;
	}

	if ( Array.isArray( value ) ) {
		return value.length === 1
			? normalizeLocalCalendarDate( value[ 0 ] )
			: undefined;
	}

	return normalizeLocalCalendarDate( value );
};

const formatUtcSqlDate = ( date: Date ) => {
	const pad = ( value: number ) => String( value ).padStart( 2, '0' );

	return `${ date.getUTCFullYear() }-${ pad(
		date.getUTCMonth() + 1
	) }-${ pad( date.getUTCDate() ) } ${ pad( date.getUTCHours() ) }:${ pad(
		date.getUTCMinutes()
	) }:${ pad( date.getUTCSeconds() ) }`;
};

/**
 * The start or end of a `YYYY-MM-DD` day in the merchant's time zone, as a UTC `Y-m-d H:i:s` string, like client
 * 11.1.0 `formatDateValue()` (`client/utils/index.js:244-255`).
 *
 * @param value      The day.
 * @param upperBound Whether to take the end of the day.
 */
export const formatLocalDateBoundaryForApi = (
	value: string,
	upperBound: boolean
) => {
	const [ year, month, day ] = value.split( '-' ).map( Number );
	const hours = upperBound ? 23 : 0;
	const minutes = upperBound ? 59 : 0;
	const seconds = upperBound ? 59 : 0;
	const localBoundary = new Date(
		year,
		month - 1,
		day,
		hours,
		minutes,
		seconds
	);
	const utcTimestamp =
		Date.UTC( year, month - 1, day, hours, minutes, seconds ) +
		localBoundary.getTimezoneOffset() * 60 * 1000;

	return formatUtcSqlDate( new Date( utcTimestamp ) );
};

const getUserTimezone = () => {
	const offset = -new Date().getTimezoneOffset();
	const sign = offset >= 0 ? '+' : '-';
	const absoluteOffset = Math.abs( offset );

	return `${ sign }${ String( Math.floor( absoluteOffset / 60 ) ).padStart(
		2,
		'0'
	) }:${ String( absoluteOffset % 60 ).padStart( 2, '0' ) }`;
};

/**
 * Turns the list's local calendar-day date filters into the platform's UTC
 * `Y-m-d H:i:s` day boundaries, like the client's `formatDateValue()`.
 *
 * @param query The list query.
 */
export const normalizeDateFiltersForApi = (
	query: WooPaymentsMoneyMovementQuery
): WooPaymentsMoneyMovementQuery => {
	const normalizedQuery = { ...query };
	const dateAfter = normalizeDateFilterValue(
		'date_after',
		query.date_after
	);
	const dateBefore = normalizeDateFilterValue(
		'date_before',
		query.date_before
	);
	const dateBetween = normalizeDateFilterValue(
		'date_between',
		query.date_between
	);

	delete normalizedQuery.date_after;
	delete normalizedQuery.date_before;
	delete normalizedQuery.date_between;

	if ( typeof dateAfter === 'string' ) {
		normalizedQuery.date_after = formatLocalDateBoundaryForApi(
			dateAfter,
			false
		);
	}

	if ( typeof dateBefore === 'string' ) {
		normalizedQuery.date_before = formatLocalDateBoundaryForApi(
			dateBefore,
			true
		);
	}

	if ( Array.isArray( dateBetween ) ) {
		normalizedQuery.date_between = [
			formatLocalDateBoundaryForApi( dateBetween[ 0 ], false ),
			formatLocalDateBoundaryForApi( dateBetween[ 1 ], true ),
		];
	}

	return normalizedQuery;
};

const normalizeSettledTransactionsApiQuery = (
	query: WooPaymentsMoneyMovementQuery
): WooPaymentsMoneyMovementQuery => ( {
	...normalizeDateFiltersForApi( query ),
	user_timezone: getUserTimezone(),
} );

export const buildSettledTransactionsApiPath = (
	path: string,
	query: WooPaymentsMoneyMovementQuery = {}
) => {
	const params = new URLSearchParams();

	Object.entries( normalizeSettledTransactionsApiQuery( query ) ).forEach(
		( [ key, value ] ) => {
			if ( value === undefined || value === null || value === '' ) {
				return;
			}

			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) =>
					params.append( `${ key }[]`, String( item ) )
				);
				return;
			}

			params.append( key, String( value ) );
		}
	);

	const queryString = params.toString();

	return queryString ? `${ path }?${ queryString }` : path;
};

const isSortDirection = (
	value: unknown
): value is WooPaymentsMoneyMovementSortDirection =>
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

const getSearchString = (
	locationOrSearch: WooPaymentsMoneyMovementRouteLocation | string
) => {
	if ( typeof locationOrSearch !== 'string' ) {
		return locationOrSearch.search || '';
	}

	if ( locationOrSearch.startsWith( '?' ) ) {
		return locationOrSearch;
	}

	const queryIndex = locationOrSearch.indexOf( '?' );

	return queryIndex === -1
		? locationOrSearch
		: locationOrSearch.slice( queryIndex + 1 );
};

const getSearchParams = (
	locationOrSearch: WooPaymentsMoneyMovementRouteLocation | string
) =>
	new URLSearchParams(
		getSearchString( locationOrSearch ).replace( /^\?/, '' )
	);

const getQueryValue = (
	params: URLSearchParams,
	key: string
): string | string[] | undefined => {
	const values = params.getAll( key ).filter( ( value ) => value !== '' );

	if ( values.length === 0 ) {
		return undefined;
	}

	return values.length === 1 ? values[ 0 ] : values;
};

const getFirstString = ( value: unknown ): string | undefined => {
	if ( Array.isArray( value ) ) {
		return value.find( ( item ) => typeof item === 'string' && item );
	}

	return typeof value === 'string' && value ? value : undefined;
};

const addParam = ( params: URLSearchParams, key: string, value: unknown ) => {
	if ( value === undefined || value === null || value === '' ) {
		return;
	}

	if ( Array.isArray( value ) ) {
		value.forEach( ( item ) => addParam( params, key, item ) );
		return;
	}

	params.append( key, String( value ) );
};

const getFilterOperator = (
	param: WooPaymentsMoneyMovementQueryFilterParam,
	value: string | string[]
): WooPaymentsMoneyMovementDataViewFilterOperator => {
	const dateOperator = DATE_FILTER_OPERATOR_BY_PARAM[ param ];

	if ( dateOperator ) {
		return dateOperator;
	}

	if ( param.endsWith( '_is_not' ) ) {
		return Array.isArray( value ) ? 'isNone' : 'isNot';
	}

	return Array.isArray( value ) ? 'isAny' : 'is';
};

const getFilterParamForDataViewFilter = (
	filter: WooPaymentsMoneyMovementDataViewFilter,
	dateField = 'date'
): WooPaymentsMoneyMovementQueryFilterParam | undefined => {
	if ( filter.field === dateField ) {
		if ( filter.operator === 'after' ) {
			return 'date_after';
		}

		if ( filter.operator === 'before' ) {
			return 'date_before';
		}

		if ( filter.operator === 'between' ) {
			return 'date_between';
		}

		return undefined;
	}

	if (
		IS_NOT_FILTER_PARAMS[ filter.field ] &&
		[ 'isNot', 'isNone', 'isNotAll' ].includes( filter.operator )
	) {
		return IS_NOT_FILTER_PARAMS[ filter.field ];
	}

	return FILTER_FIELD_ALIASES[ filter.field ];
};

const getFilterQueryValue = ( value: unknown ) => {
	if ( Array.isArray( value ) ) {
		const values = value
			.map( ( item ) => String( item ) )
			.filter( ( item ) => item !== '' );

		return values.length ? values : undefined;
	}

	if ( value === undefined || value === null || value === '' ) {
		return undefined;
	}

	return String( value );
};

export const parseMoneyMovementQuery = (
	locationOrSearch: WooPaymentsMoneyMovementRouteLocation | string,
	defaults: WooPaymentsMoneyMovementQuery = {}
): WooPaymentsMoneyMovementQuery => {
	const params = getSearchParams( locationOrSearch );
	const page = normalizePositiveInteger(
		params.get( 'paged' ),
		normalizePositiveInteger(
			params.get( 'page' ),
			normalizePositiveInteger(
				defaults.page,
				DEFAULT_MONEY_MOVEMENT_QUERY.page
			)
		)
	);
	const pagesize = normalizePositiveInteger(
		params.get( 'pagesize' ) ||
			params.get( 'perPage' ) ||
			params.get( 'per_page' ),
		normalizePositiveInteger(
			defaults.pagesize,
			DEFAULT_MONEY_MOVEMENT_QUERY.pagesize
		)
	);
	const direction =
		( isSortDirection( params.get( SORT_URL_PARAMS.direction ) )
			? ( params.get(
					SORT_URL_PARAMS.direction
			  ) as WooPaymentsMoneyMovementSortDirection )
			: undefined ) ||
		( isSortDirection( defaults.direction )
			? defaults.direction
			: undefined );
	const query: WooPaymentsMoneyMovementQuery = {
		page,
		pagesize,
	};
	const sort = params.get( SORT_URL_PARAMS.sort ) || defaults.sort;
	const search = getQueryValue( params, 'search' ) || defaults.search;

	if ( sort ) {
		query.sort = sort;
	}

	if ( direction ) {
		query.direction = direction;
	}

	if ( search ) {
		query.search = search;
	}

	MONEY_MOVEMENT_FILTER_PARAMS.forEach( ( param ) => {
		const value = getQueryValue( params, param );

		if ( value ) {
			query[ param ] = value;
		}
	} );

	return query;
};

export const serializeMoneyMovementQuery = (
	query: WooPaymentsMoneyMovementQuery
): string => {
	const params = new URLSearchParams();

	QUERY_PARAM_ORDER.forEach( ( key ) => {
		addParam(
			params,
			key === 'sort' || key === 'direction'
				? SORT_URL_PARAMS[ key ]
				: key,
			query[ key ]
		);
	} );

	return params.toString();
};

export const sanitizeWooPaymentsAuthorizationsQuery = (
	query: WooPaymentsMoneyMovementQuery
): WooPaymentsMoneyMovementQuery => {
	const sanitized: Record< string, WooPaymentsMoneyMovementQuery[ string ] > =
		{};

	AUTHORIZATION_QUERY_PARAM_ORDER.forEach( ( key ) => {
		const value = query[ key ];

		if ( value === undefined || value === null || value === '' ) {
			return;
		}

		sanitized[ key ] = value;
	} );

	return sanitized;
};

export const serializeWooPaymentsAuthorizationsQuery = (
	query: WooPaymentsMoneyMovementQuery
): string => {
	const sanitized = sanitizeWooPaymentsAuthorizationsQuery( query );
	const params = new URLSearchParams();

	// Client 11.1.0 `data/authorizations/resolvers.ts:38-41`: "Capture by" is derived from `created`, so the API sorts
	// by `created` while the URL keeps `capture_by`.
	if ( sanitized.sort === 'capture_by' ) {
		sanitized.sort = 'created';
	}

	AUTHORIZATION_QUERY_PARAM_ORDER.forEach( ( key ) => {
		const value = sanitized[ key ];
		// This is an API query: PHP keeps only the last of repeated bare keys.
		addParam( params, Array.isArray( value ) ? `${ key }[]` : key, value );
	} );

	return params.toString();
};

export const buildMoneyMovementRoutePath = (
	pathname: string,
	query: WooPaymentsMoneyMovementQuery
): string => {
	const queryString = serializeMoneyMovementQuery( query );

	return queryString ? `${ pathname }?${ queryString }` : pathname;
};

export const moneyMovementQueryToDataViewsView = (
	query: WooPaymentsMoneyMovementQuery,
	options: {
		fields?: string[];
		titleField?: string;
		showTitle?: boolean;
		layout?: Record< string, unknown >;
		/** The field the date filters belong to, when it is not `date`. */
		dateField?: string;
	} = {}
): WooPaymentsMoneyMovementDataView => {
	const normalizedQuery: WooPaymentsMoneyMovementQuery = {
		...DEFAULT_MONEY_MOVEMENT_QUERY,
		...query,
	};
	const filters = MONEY_MOVEMENT_FILTER_PARAMS.reduce<
		WooPaymentsMoneyMovementDataViewFilter[]
	>( ( result, param ) => {
		const rawValue = normalizedQuery[ param ];
		const value = isDateFilterParam( param )
			? normalizeDateFilterValue( param, rawValue )
			: rawValue;

		if (
			typeof value === 'string' ||
			( Array.isArray( value ) && value.length > 0 )
		) {
			result.push( {
				field:
					isDateFilterParam( param ) && options.dateField
						? options.dateField
						: FILTER_PARAM_TO_FIELD[ param ] || param,
				operator: getFilterOperator( param, value ),
				value,
			} );
		}

		return result;
	}, [] );
	const view: WooPaymentsMoneyMovementDataView = {
		type: 'table',
		page: normalizePositiveInteger(
			normalizedQuery.page,
			DEFAULT_MONEY_MOVEMENT_QUERY.page
		),
		perPage: normalizePositiveInteger(
			normalizedQuery.pagesize,
			DEFAULT_MONEY_MOVEMENT_QUERY.pagesize
		),
		search: getFirstString( normalizedQuery.search ) || '',
		filters,
		fields: options.fields || [],
		layout: options.layout || {},
	};
	const direction = isSortDirection( normalizedQuery.direction )
		? normalizedQuery.direction
		: undefined;

	if ( normalizedQuery.sort && direction ) {
		view.sort = {
			field: normalizedQuery.sort,
			direction,
		};
	}

	if ( options.titleField ) {
		view.titleField = options.titleField;
	}

	if ( options.showTitle !== undefined ) {
		view.showTitle = options.showTitle;
	}

	return view;
};

export const dataViewsViewToMoneyMovementQuery = (
	view: WooPaymentsMoneyMovementDataView,
	currentQuery: WooPaymentsMoneyMovementQuery = {},
	dateField = 'date'
): WooPaymentsMoneyMovementQuery => {
	const query: WooPaymentsMoneyMovementQuery = {
		...currentQuery,
		page: normalizePositiveInteger(
			view.page,
			DEFAULT_MONEY_MOVEMENT_QUERY.page
		),
		pagesize: normalizePositiveInteger(
			view.perPage,
			DEFAULT_MONEY_MOVEMENT_QUERY.pagesize
		),
	};

	if ( view.search ) {
		query.search = view.search;
	} else {
		delete query.search;
	}

	if ( view.sort ) {
		query.sort = view.sort.field;
		query.direction = view.sort.direction;
	} else {
		delete query.sort;
		delete query.direction;
	}

	MONEY_MOVEMENT_FILTER_PARAMS.forEach( ( param ) => {
		delete query[ param ];
	} );

	view.filters?.forEach( ( filter ) => {
		const param = getFilterParamForDataViewFilter( filter, dateField );
		let value: string | string[] | undefined;

		if ( param ) {
			value = isDateFilterParam( param )
				? normalizeDateFilterValue( param, filter.value )
				: getFilterQueryValue( filter.value );
		}

		if ( ! param || value === undefined ) {
			return;
		}

		query[ param ] = value;
	} );

	return query;
};
