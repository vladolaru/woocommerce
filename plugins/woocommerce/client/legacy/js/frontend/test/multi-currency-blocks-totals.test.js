describe( 'multi-currency-blocks-totals', () => {
	afterEach( () => {
		delete window.wc;
		jest.resetModules();
	} );

	test( 'registers a totals filter that adds the cart currency code', () => {
		const registerCheckoutFilters = jest.fn();
		window.wc = { blocksCheckout: { registerCheckoutFilters } };

		const { getTotalValue } = require( '../multi-currency-blocks-totals' );

		expect( registerCheckoutFilters ).toHaveBeenCalledWith( 'woocommerce-payments', {
			totalValue: getTotalValue,
		} );
		// Store API cart totals carry currency_code (StoreApi CartSchema totals).
		expect(
			getTotalValue( '<price/>', {}, { cart: { cartTotals: { currency_code: 'CAD' } } } )
		).toBe( '<price/> CAD' );
		expect( getTotalValue( '<price/>', {}, { cart: {} } ) ).toBe( '<price/>' );
	} );

	test( 'does nothing without the checkout filter registry', () => {
		expect( () => require( '../multi-currency-blocks-totals' ) ).not.toThrow();
	} );
} );
