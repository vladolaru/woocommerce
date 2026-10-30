describe( 'wc-woopayments-common', () => {
	afterEach( () => {
		delete window.wc;
	} );

	it( 'publishes the shared helpers on window.wc.woopayments and keeps the other wc members', () => {
		const customPlaceOrderButton = {};
		window.wc = { customPlaceOrderButton };

		jest.isolateModules( () => {
			require( '../index' );
		} );

		expect( window.wc.customPlaceOrderButton ).toBe(
			customPlaceOrderButton
		);
		expect( Object.keys( window.wc.woopayments ).sort() ).toEqual( [
			'StripeWaitTimeoutError',
			'confirmWithStripe',
			'expressCheckout',
			'parseConfirmationHash',
			'tracks',
			'waitForStripe',
		] );
		expect( Object.keys( window.wc.woopayments.tracks ).sort() ).toEqual( [
			'getIdentity',
			'recordEvent',
		] );
		expect(
			Object.keys( window.wc.woopayments.expressCheckout ).sort()
		).toEqual( [
			'getBillingAddress',
			'getWalletBillingPhone',
			'normalizeAddress',
			'normalizePhone',
			'splitName',
			'transformPrice',
		] );
	} );
} );
