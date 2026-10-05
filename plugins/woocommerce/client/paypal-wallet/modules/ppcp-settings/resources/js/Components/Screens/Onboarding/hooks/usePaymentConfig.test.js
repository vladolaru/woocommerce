import { renderHook } from '@testing-library/react';
import { usePaymentConfig } from './usePaymentConfig';

jest.mock( '@ppcp-settings/data/index', () => ( {
	initStores: jest.fn(),
} ) );

const EXPECTED_PAYMENT_METHODS = [
	[ 'US', [ 'PayWithPayPal', 'PayLater', 'Venmo' ] ],
	[ 'GB', [ 'PayWithPayPal', 'PayInThree' ] ],
	[ 'AU', [ 'PayWithPayPal', 'PayLater' ] ],
	[ 'MX', [ 'PayWithPayPal', 'PayLater' ] ],
];

const CARD_ICONS = [ 'visa', 'mastercard', 'amex', 'discover' ];

describe( 'usePaymentConfig hook', () => {
	describe( 'Payment Methods for countries', () => {
		test.each( EXPECTED_PAYMENT_METHODS )(
			'Country %s should have valid methods',
			( country, includedMethods ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, false )
				);

				expect(
					result.current.includedMethods.map(
						( method ) => method.name
					)
				).toEqual( includedMethods );
			}
		);

		test.each( [ 'US', 'GB', 'AU' ] )(
			'Country %s should list the same methods whether or not card payments are available',
			( country ) => {
				const names = ( canUseCardPayments ) =>
					renderHook( () =>
						usePaymentConfig( country, canUseCardPayments, false )
					).result.current.includedMethods.map(
						( method ) => method.name
					);

				expect( names( false ) ).toEqual( names( true ) );
			}
		);
	} );

	describe( 'Payment icons', () => {
		test( 'US should show PayPal, Venmo and the card icons', () => {
			const { result } = renderHook( () =>
				usePaymentConfig( 'US', true, false )
			);

			expect( result.current.icons ).toEqual( [
				'paypal',
				'venmo',
				...CARD_ICONS,
			] );
		} );

		test( 'GB should show PayPal and the card icons, without Venmo', () => {
			const { result } = renderHook( () =>
				usePaymentConfig( 'GB', true, false )
			);

			expect( result.current.icons ).toEqual( [
				'paypal',
				...CARD_ICONS,
			] );
		} );

		test.each( [ 'US', 'GB' ] )(
			'Country %s should show only the own-brand icons when ownBrandOnly is true',
			( country ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country, true, true )
				);

				expect( result.current.icons ).toEqual(
					country === 'US' ? [ 'paypal', 'venmo' ] : [ 'paypal' ]
				);
			}
		);
	} );
} );
