describe( 'multi-currency-blocks-totals', () => {
	afterEach( () => {
		delete window.wc;
		jest.resetModules();
	} );

	test( 'registers a totals filter that adds the cart currency code', () => {
		const registerCheckoutFilters = jest.fn();
		// The wc-blocks-checkout script exposes the filter registry as window.wc.blocksCheckout (client/blocks/bin/webpack-helpers.js:27
		// maps @woocommerce/blocks-checkout to [ 'wc', 'blocksCheckout' ]).
		window.wc = { blocksCheckout: { registerCheckoutFilters } };

		const { getTotalValue } = require( '../multi-currency-blocks-totals' );

		expect( registerCheckoutFilters ).toHaveBeenCalledWith( 'woocommerce-payments', {
			totalValue: getTotalValue,
		} );
		// The filter receives { cart } with the Store API cart's totals as cartTotals (client 11.1.0
		// client/checkout/blocks/index.js:159-173 reads the same path; StoreApi CartSchema totals carry currency_code).
		expect(
			getTotalValue( '<price/>', {}, { cart: { cartTotals: { currency_code: 'CAD' } } } )
		).toBe( '<price/> CAD' );
		expect( getTotalValue( '<price/>', {}, { cart: {} } ) ).toBe( '<price/>' );
	} );

	test( 'does nothing without the checkout filter registry', () => {
		expect( () => require( '../multi-currency-blocks-totals' ) ).not.toThrow();
	} );
} );
