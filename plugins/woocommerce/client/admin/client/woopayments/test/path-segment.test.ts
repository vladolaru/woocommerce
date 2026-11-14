/**
 * Internal dependencies
 */
import { encodePathSegment, InvalidPathSegmentError } from '../path-segment';

// Client 11.3.0 `client/utils/path-segment.ts:25-35`.
describe( 'encodePathSegment', () => {
	it.each( [
		[ 'txn_123', 'txn_123' ],
		[ 'po 1', 'po%201' ],
		[ 'a/b', 'a%2Fb' ],
		[ 42, '42' ],
	] )( 'encodes %p as one path segment', ( id, expected ) => {
		expect( encodePathSegment( id ) ).toBe( expected );
	} );

	it.each( [ [ '' ], [ '.' ], [ '..' ], [ undefined ], [ null ], [ {} ] ] )(
		'refuses %p with the dedicated error',
		( id ) => {
			let error: unknown;
			try {
				encodePathSegment( id );
			} catch ( caught ) {
				error = caught;
			}

			expect( error ).toBeInstanceOf( InvalidPathSegmentError );
			expect( error ).toMatchObject( {
				code: 'wcpay_invalid_path_segment',
				message: 'Invalid identifier.',
			} );
		}
	);
} );
