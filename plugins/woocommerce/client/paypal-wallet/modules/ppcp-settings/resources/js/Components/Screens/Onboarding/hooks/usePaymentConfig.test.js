/* global describe, test, expect, jest */
import { renderHook } from '@testing-library/react';
import { usePaymentConfig } from './usePaymentConfig';

jest.mock( '@ppcp-settings/data/index', () => ( {
	initStores: jest.fn(),
} ) );

const EXPECTED_PAYMENT_METHODS = [
	[ 'US', [ 'PayWithPayPal', 'PayLater', 'Venmo' ], [] ],
	[ 'GB', [ 'PayWithPayPal', 'PayInThree' ], [] ],
	[ 'AU', [ 'PayWithPayPal', 'PayLater' ], [] ],
	[ 'MX', [ 'PayWithPayPal', 'PayLater' ], [] ],
];

describe( 'usePaymentConfig hook', () => {
	describe( 'Payment Methods for countries', () => {
		test.each( EXPECTED_PAYMENT_METHODS )(
			'Country %s should have valid methods',
			( country, includedMethods, optionalMethods ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, false )
				);

				expect( result.current.includedMethods ).toHaveLength(
					includedMethods.length
				);
				expect(
					result.current.includedMethods.map(
						( method ) => method.name
					)
				).toEqual( includedMethods );

				expect(
					result.current.optionalMethods.map(
						( method ) => method.name
					)
				).toEqual( optionalMethods );
			}
		);
		test.each( [ 'US', 'GB', 'AU' ] )(
			'Country %s should offer no optional methods when card payments are unavailable',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, false, false )
				);

				expect( result.current.optionalMethods ).toEqual( [] );
			}
		);

		test.each( [ 'US', 'GB', 'AU' ] )(
			'Country %s should contain only OwnBrand methods when ownBrandOnly is true',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, true )
				);

				expect( result.current.optionalMethods ).toEqual( [] );
			}
		);
	} );

	describe( 'Local payment methods', () => {
		test.each( [ 'US', 'GB', 'AU', 'MX' ] )(
			'Country %s should offer no local payment method tile or icon',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, false )
				);
				const methodNames = result.current.optionalMethods.map(
					( method ) => method.name
				);

				expect( methodNames ).not.toContain( 'APMs' );
				[ 'blik', 'ideal', 'bancontact', 'oxxo' ].forEach( ( icon ) =>
					expect( result.current.icons ).not.toContain( icon )
				);
			}
		);
	} );

	describe( 'Digital wallets', () => {
		test.each( [ 'US', 'GB', 'AU', 'MX' ] )(
			'Country %s should offer no digital wallet method or icon',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, false )
				);
				const methodNames = result.current.optionalMethods.map(
					( method ) => method.name
				);

				expect( methodNames ).not.toContain( 'DigitalWallets' );
				expect( result.current.icons ).not.toContain( 'apple-pay' );
				expect( result.current.icons ).not.toContain( 'google-pay' );
			}
		);
	} );
} );
