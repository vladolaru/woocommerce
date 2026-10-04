/* global describe, test, expect, jest */
import { renderHook } from '@testing-library/react';
import { usePaymentConfig } from './usePaymentConfig';

jest.mock( '@ppcp-settings/data/index', () => ( {
	initStores: jest.fn(),
} ) );

const EXPECTED_PAYMENT_METHODS = [
	[
		'US',
		[ 'PayWithPayPal', 'PayLater', 'Venmo', 'Crypto' ],
		[ 'APMs', 'Fastlane' ],
	],
	[ 'GB', [ 'PayWithPayPal', 'PayInThree' ], [ 'APMs', 'Fastlane' ] ],
	[ 'AU', [ 'PayWithPayPal', 'PayLater' ], [ 'APMs', 'Fastlane' ] ],
	[ 'MX', [ 'PayWithPayPal', 'PayLater' ], [ 'APMs', 'Fastlane' ] ],
];

describe( 'usePaymentConfig hook', () => {
	describe( 'Payment Methods for countries', () => {
		test.each( EXPECTED_PAYMENT_METHODS )(
			'Country %s should have valid methods',
			( country, includedMethods, optionalMethods ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, true, false )
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
			'Country %s should contain Fastlane method if hasFastlane is true',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, true, false )
				);
				const methodNames = result.current.optionalMethods.map(
					( method ) => method.name
				);
				expect( methodNames ).toContain( 'Fastlane' );
			}
		);

		test.each( [ 'US', 'GB', 'AU' ] )(
			'Country %s should NOT contain Fastlane method if hasFastlane is false',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, false, false )
				);
				const methodNames = result.current.optionalMethods.map(
					( method ) => method.name
				);
				expect( methodNames ).not.toContain( 'Fastlane' );
			}
		);

		test.each( [ 'US', 'GB', 'AU' ] )(
			'Country %s should offer no optional methods when card payments and Fastlane are unavailable',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, false, false, false )
				);

				expect( result.current.optionalMethods ).toEqual( [] );
			}
		);

		test.each( [ 'US', 'GB', 'AU' ] )(
			'Country %s should contain only OwnBrand methods when ownBrandOnly is true',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, true, true )
				);

				expect(
					result.current.optionalMethods.map(
						( method ) => method.name
					)
				).toEqual( [ 'APMs' ] );
			}
		);

		test( 'Country MX should not contain APMs when canUseCardPayments is false', () => {
			const { result } = renderHook( () =>
				usePaymentConfig( 'MX', false, false, false )
			);
			const methodNames = result.current.optionalMethods.map(
				( method ) => method.name
			);
			expect( methodNames ).not.toContain( 'APMs' );
		} );
	} );

	describe( 'Digital wallets', () => {
		test.each( [ 'US', 'GB', 'AU', 'MX' ] )(
			'Country %s should offer no digital wallet method or icon',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, true, false )
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
