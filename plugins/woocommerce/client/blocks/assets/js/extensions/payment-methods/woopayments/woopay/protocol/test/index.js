describe( 'wc-woopayments-woopay-common', () => {
	afterEach( () => {
		delete window.wc;
	} );

	it( 'publishes the WooPay protocol on window.wc.woopayments.woopay and keeps the other wc members', () => {
		const customPlaceOrderButton = {};
		window.wc = { customPlaceOrderButton };

		jest.isolateModules( () => {
			require( '../index' );
		} );

		expect( window.wc.customPlaceOrderButton ).toBe(
			customPlaceOrderButton
		);

		expect( Object.keys( window.wc.woopayments.woopay ).sort() ).toEqual( [
			'buildAjaxUrl',
			'claimWooPayRedirect',
			'createConnect',
			'deleteSkipWooPayCookie',
			'deleteSkipWooPayCookieOnClick',
			'dispatchUserExistEvent',
			'getCachedPreferredCard',
			'getTargetElement',
			'getWooPayHostOrigin',
			'handleEmailInput',
			'initWooPay',
			'isPreviewing',
			'isSamePreferredCard',
			'isValidPreferredCard',
			'loadPhoneValidation',
			'normalizePhone',
			'openExpressOtpIframe',
			'setCachedPreferredCard',
			'shouldSkipWooPay',
			'validateEmail',
		] );
	} );
} );
