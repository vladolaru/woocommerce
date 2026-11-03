/**
 * Internal dependencies
 */
import { formatAmount, formatExplicitCurrency } from '../currency';
import { formatCurrency, formatFX } from '../currency-format';

// Cases and expectations from client 11.1.0 `includes/multi-currency/client/utils/currency/test/index.js`, with its
// localized data moved to the native bootstrap (`connect.country` is `storeCountry` here).
const locale = (
	symbolPosition: string,
	thousandSeparator: string,
	decimalSeparator: string
) => ( { symbolPosition, thousandSeparator, decimalSeparator } );

const currencyData = {
	US: {
		code: 'USD',
		symbol: '$',
		...locale( 'left', ',', '.' ),
		precision: 2,
		defaultLocale: locale( 'left', ',', '.' ),
	},
	JP: {
		code: 'JPY',
		symbol: '¥',
		...locale( 'left', ',', '.' ),
		precision: 0,
		defaultLocale: locale( 'left', ',', '.' ),
	},
	FR: {
		code: 'EUR',
		symbol: '€',
		...locale( 'right_space', ' ', ',' ),
		precision: 2,
		defaultLocale: locale( 'right_space', ',', '.' ),
	},
	GB: {
		code: 'GBP',
		symbol: '£',
		...locale( 'left', ',', '.' ),
		precision: 2,
		defaultLocale: locale( 'left', ',', '.' ),
	},
	IN: {
		code: 'INR',
		symbol: '₹',
		...locale( 'left', ',', '.' ),
		precision: 2,
		defaultLocale: locale( 'left', ',', '.' ),
	},
	NC: {
		code: 'XPF',
		symbol: 'XPF',
		...locale( 'right_space', ' ', '.' ),
		precision: 0,
		defaultLocale: locale( 'left_space', ',', '.' ),
	},
	RU: {
		code: 'RUB',
		symbol: '₽',
		...locale( 'right_space', ' ', ',' ),
		precision: 2,
		defaultLocale: locale( 'right_space', ' ', ',' ),
	},
};

const setStoreCountry = ( storeCountry: string ) => {
	( window as unknown as { wcSettings: unknown } ).wcSettings = {
		admin: {
			woopaymentsSettings: {
				shouldUseExplicitPrice: true,
				zeroDecimalCurrencies: [ 'vnd', 'jpy', 'xpf' ],
				storeCountry,
				currencyData: JSON.parse( JSON.stringify( currencyData ) ),
			},
		},
	};
};

describe( 'WooPayments currency formatting, as the client formats', () => {
	beforeEach( () => setStoreCountry( 'US' ) );

	afterEach( () => {
		delete ( window as unknown as { wcSettings?: unknown } ).wcSettings;
	} );

	it( 'formats the admin amounts through the client formatter, zero-decimal currencies included', () => {
		// Exit run 2026-10-03: native printed "JP¥15 JPY" and "US$10.00" where the client printed "¥1,500" and "$10.00".
		expect( formatAmount( 150000 / 100, 'jpy' ) ).toEqual( '¥1,500' );
		expect( formatAmount( 1000, 'usd' ) ).toEqual( '$10.00' );
		expect( formatExplicitCurrency( 1, 'jpy', true ) ).toEqual( '1 JPY' );
	} );

	it( 'formats a supported currency', () => {
		expect( formatCurrency( 1000, 'USD' ) ).toEqual( '$10.00' );
	} );

	it( 'formats a currency without data and a zero-decimal currency', () => {
		expect( formatCurrency( 1000, 'AUD' ) ).toEqual( '$10.00' );
		expect( formatCurrency( 1000, 'JPY' ) ).toEqual( '¥1,000' );
	} );

	it.each`
		source                                   | target                                 | expected
		${ { currency: 'EUR', amount: 1242 } }   | ${ { currency: 'USD', amount: 1484 } } | ${ '1.00 EUR → 1.19485 USD: $14.84 USD' }
		${ { currency: 'CHF', amount: 1500 } }   | ${ { currency: 'USD', amount: 1675 } } | ${ '1.00 CHF → 1.11667 USD: $16.75 USD' }
		${ { currency: 'GBP', amount: 1800 } }   | ${ { currency: 'USD', amount: 2439 } } | ${ '1.00 GBP → 1.355 USD: $24.39 USD' }
		${ { currency: 'INR', amount: 131392 } } | ${ { currency: 'USD', amount: 1779 } } | ${ '1.00 INR → 0.01354 USD: $17.79 USD' }
		${ { currency: 'RUB', amount: 136746 } } | ${ { currency: 'USD', amount: 1777 } } | ${ '1.00 RUB → 0.012995 USD: $17.77 USD' }
		${ { currency: 'JPY', amount: 1894 } }   | ${ { currency: 'USD', amount: 1786 } } | ${ '1 JPY → 0.00943 USD: $17.86 USD' }
	`(
		'formats the FX line $source.currency -> $target.currency',
		( { source, target, expected } ) => {
			expect( formatFX( source, target ) ).toBe( expected );
		}
	);

	it( 'uses the base currency format instead of the store country', () => {
		expect( formatCurrency( 100000, 'USD', 'EUR' ) ).toEqual(
			'1 000,00 $'
		);
	} );

	it( 'uses the store country currency format without a base currency', () => {
		setStoreCountry( 'IN' );

		expect( formatCurrency( 100000, 'EUR' ) ).toEqual( '€1,000.00' );
	} );

	it( 'uses the store country currency when locale formatting is asked and it matches', () => {
		expect( formatCurrency( 100000, 'USD', null, true ) ).toEqual(
			'$1,000.00'
		);

		setStoreCountry( 'NC' );

		expect( formatCurrency( 100000, 'XPF', null, true ) ).toEqual(
			'100 000 XPF'
		);
	} );

	it( 'uses the default locale format when locale formatting is asked', () => {
		expect( formatCurrency( 100000, 'EUR', null, true ) ).toEqual(
			'1,000.00 €'
		);
		expect( formatCurrency( 100000, 'XPF', null, true ) ).toEqual(
			'XPF 100,000'
		);
	} );
} );
