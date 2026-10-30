/**
 * Internal dependencies
 */
import {
	getBillingAddress,
	getWalletBillingPhone,
	normalizeAddress,
	splitName,
	transformPrice,
} from '../express-checkout';

describe( 'express checkout address helpers', () => {
	it( 'splits a wallet name into first and last name', () => {
		expect( splitName( '  Ada  King Lovelace ' ) ).toEqual( {
			first_name: 'Ada',
			last_name: 'King Lovelace',
		} );
		expect( splitName( undefined ) ).toEqual( {
			first_name: '',
			last_name: '',
		} );
	} );

	it( 'maps a Stripe address and fills empty fields from the fallback', () => {
		expect(
			normalizeAddress(
				{ line1: '1 Main St', line2: null, postal_code: '10001' },
				{ address_2: 'Apt 2', city: 'New York', country: 'US' }
			)
		).toEqual( {
			first_name: '',
			last_name: '',
			company: '',
			address_1: '1 Main St',
			address_2: 'Apt 2',
			city: 'New York',
			state: '',
			postcode: '10001',
			country: 'US',
			email: '',
			phone: '',
		} );
	} );

	it( 'normalizes the wallet phone, falling back to the payer phone', () => {
		expect(
			getWalletBillingPhone( {
				billingDetails: { phone: '+1 (555) 010-0000' },
			} )
		).toBe( '+15550100000' );
		expect( getWalletBillingPhone( { payerPhone: '555 0100' } ) ).toBe(
			'5550100'
		);
		expect( getWalletBillingPhone( {} ) ).toBe( '' );
	} );

	it( 'builds the billing address from the confirm event, with a placeholder last name', () => {
		expect(
			getBillingAddress(
				{
					billingDetails: {
						name: 'Ada',
						email: 'ada@example.test',
						phone: '555-0100',
						address: { line1: '1 Main St', country: 'GB' },
					},
				},
				{ city: 'London', email: 'held@example.test' }
			)
		).toMatchObject( {
			first_name: 'Ada',
			last_name: '-',
			email: 'ada@example.test',
			phone: '5550100',
			address_1: '1 Main St',
			city: 'London',
			country: 'GB',
		} );
	} );
} );

describe( 'transformPrice', () => {
	const units = { stripeMinorUnit: 2, currencyDecimals: 2 };

	it( 'keeps same-scale amounts untouched', () => {
		expect(
			transformPrice( 1234, { currency_minor_unit: 2 }, units )
		).toBe( 1234 );
	} );

	it( 'narrows to a zero-decimal Stripe currency with rounding', () => {
		const zeroDecimal = { stripeMinorUnit: 0, currencyDecimals: 2 };

		expect(
			transformPrice( 123400, { currency_minor_unit: 2 }, zeroDecimal )
		).toBe( 1234 );
		expect(
			transformPrice( 123450, { currency_minor_unit: 2 }, zeroDecimal )
		).toBe( 1235 );
	} );

	it( 'widens a zero-decimal store configuration to the Stripe unit', () => {
		expect(
			transformPrice( 1234, { currency_minor_unit: 0 }, units )
		).toBe( 123400 );
	} );

	it( 'reads the store decimals when the price object carries no minor unit', () => {
		expect(
			transformPrice(
				1234,
				{},
				{ stripeMinorUnit: 2, currencyDecimals: 0 }
			)
		).toBe( 123400 );
	} );
} );
