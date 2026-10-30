describe( 'wc-woopayments-woopay-common', () => {
	afterEach( () => {
		delete window.wc;
	} );

	it( 'publishes the WooPay protocol on window.wc.woopayments.woopay and keeps the shared helpers', () => {
		jest.isolateModules( () => {
			// The page prints wc-woopayments-common first.
			require( '../../../common' );
			const tracks = window.wc.woopayments.tracks;

			require( '../index' );

			expect( window.wc.woopayments.tracks ).toBe( tracks );
		} );

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
