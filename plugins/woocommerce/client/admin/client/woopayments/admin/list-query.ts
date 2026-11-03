/**
 * URL query rules the WooPayments list pages (documents, money movement, reports) share.
 */

/**
 * Whether a value is a sort direction the list endpoints accept.
 *
 * @param value The value read from the URL or a view.
 */
export const isSortDirection = ( value: unknown ): value is 'asc' | 'desc' =>
	value === 'asc' || value === 'desc';

/**
 * A positive integer from a number or a numeric string, or the fallback.
 *
 * @param value    The value read from the URL or a view.
 * @param fallback The value to use when it is not a positive integer.
 */
export const normalizePositiveInteger = (
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

/**
 * The first non-empty string of a value or a list of values.
 *
 * @param value The value read from the URL or a view.
 */
export const getFirstString = ( value: unknown ): string | undefined => {
	if ( Array.isArray( value ) ) {
		return value.find( ( item ) => typeof item === 'string' && item );
	}

	return typeof value === 'string' && value ? value : undefined;
};

/**
 * A DataViews filter value as query text: a list of non-empty strings, one string, or undefined when it is empty.
 *
 * @param value The filter value.
 */
export const getFilterQueryValue = ( value: unknown ) => {
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
