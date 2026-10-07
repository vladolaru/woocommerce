import { renderHook } from '@testing-library/react';
import { usePaymentConfig } from './usePaymentConfig';

jest.mock( '../../../../data/index', () => ( {
	initStores: jest.fn(),
} ) );

const EXPECTED_PAYMENT_METHODS = [
	[ 'US', [ 'PayWithPayPal', 'PayLater', 'Venmo' ] ],
	[ 'GB', [ 'PayWithPayPal', 'PayInThree' ] ],
	[ 'AU', [ 'PayWithPayPal', 'PayLater' ] ],
	[ 'MX', [ 'PayWithPayPal', 'PayLater' ] ],
];

describe( 'usePaymentConfig hook', () => {
	describe( 'Payment Methods for countries', () => {
		test.each( EXPECTED_PAYMENT_METHODS )(
			'Country %s should have valid methods',
			( country, includedMethods ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country )
				);

				expect(
					result.current.includedMethods.map(
						( method ) => method.name
					)
				).toEqual( includedMethods );
			}
		);
	} );

	describe( 'Payment icons', () => {
		test.each( [
			[ 'US', [ 'paypal', 'venmo' ] ],
			[ 'GB', [ 'paypal' ] ],
			[ 'MX', [ 'paypal' ] ],
		] )(
			'Country %s should show only the wallet icons, without card icons',
			( country, icons ) => {
				const { result } = renderHook( () =>
					usePaymentConfig( country )
				);

				expect( result.current.icons ).toEqual( icons );
			}
		);
	} );
} );
