/**
 * External dependencies
 */
import { registerCheckoutFilters } from '@woocommerce/blocks-checkout';

/**
 * Internal dependencies
 */
import { registerMultiCurrencyTotalValue } from '../multi-currency-totals';

jest.mock( '@woocommerce/blocks-checkout', () => ( {
	registerCheckoutFilters: jest.fn(),
} ) );

describe( 'registerMultiCurrencyTotalValue', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'adds the cart currency code after the total while Multi-Currency runs', () => {
		registerMultiCurrencyTotalValue( { isMultiCurrencyEnabled: true } );

		expect( registerCheckoutFilters ).toHaveBeenCalledWith(
			'woocommerce-payments',
			expect.any( Object )
		);
		const { totalValue } = registerCheckoutFilters.mock.calls[ 0 ][ 1 ];
		// Store API cart totals carry currency_code (StoreApi CartSchema totals).
		expect(
			totalValue(
				'<price/>',
				{},
				{
					cart: { cartTotals: { currency_code: 'CAD' } },
				}
			)
		).toBe( '<price/> CAD' );
		expect( totalValue( '<price/>', {}, { cart: {} } ) ).toBe( '<price/>' );
	} );

	it( 'leaves the totals alone without Multi-Currency', () => {
		registerMultiCurrencyTotalValue( {} );

		expect( registerCheckoutFilters ).not.toHaveBeenCalled();
	} );
} );
