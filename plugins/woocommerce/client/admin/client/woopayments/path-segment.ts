/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

export class InvalidPathSegmentError extends Error {
	name = 'InvalidPathSegmentError';

	code = 'wcpay_invalid_path_segment';
}

/**
 * Encode an ID for use as one REST API path segment.
 *
 * As client 11.3.0 `client/utils/path-segment.ts:25-35`: a string or number is encoded, and anything else, an empty
 * ID and the dot segments `.` and `..` are refused before any request is built.
 *
 * @param  id The ID to encode.
 *
 * @throws {InvalidPathSegmentError} When the ID cannot stand as one path segment.
 */
export const encodePathSegment = ( id: unknown ): string => {
	if (
		( typeof id !== 'string' && typeof id !== 'number' ) ||
		[ '', '.', '..' ].includes( String( id ) )
	) {
		throw new InvalidPathSegmentError(
			__( 'Invalid identifier.', 'woocommerce' )
		);
	}

	return encodeURIComponent( id );
};
