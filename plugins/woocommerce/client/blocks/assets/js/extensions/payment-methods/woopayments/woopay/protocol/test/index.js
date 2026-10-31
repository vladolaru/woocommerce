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

		// The members the classic and Blocks hosts call; each must be callable.
		const fn = expect.any( Function );
		expect( window.wc.woopayments.woopay ).toEqual(
			expect.objectContaining( {
				buildAjaxUrl: fn,
				claimWooPayRedirect: fn,
				deleteSkipWooPayCookie: fn,
				dispatchUserExistEvent: fn,
				getCachedPreferredCard: fn,
				getConnect: fn,
				getTargetElement: fn,
				getWooPayHostOrigin: fn,
				handleEmailInput: fn,
				initWooPay: fn,
				isPreviewing: fn,
				isSamePreferredCard: fn,
				isValidPreferredCard: fn,
				loadPhoneValidation: fn,
				normalizePhone: fn,
				openExpressOtpIframe: fn,
				setCachedPreferredCard: fn,
				shouldSkipWooPay: fn,
				validateEmail: fn,
			} )
		);
	} );
} );
