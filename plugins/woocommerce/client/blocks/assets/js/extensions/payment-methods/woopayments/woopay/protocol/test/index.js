// Loads the entry as the page does: once, in a fresh module registry.
const loadEntry = () =>
	jest.isolateModules( () => {
		require( '../index' );
	} );

describe( 'wc-woopayments-woopay-common', () => {
	afterEach( () => {
		delete window.wc;
	} );

	it( 'publishes the WooPay protocol on window.wc.woopayments.woopay and keeps the other wc members', () => {
		const customPlaceOrderButton = {};
		window.wc = { customPlaceOrderButton };

		loadEntry();

		expect( window.wc.customPlaceOrderButton ).toBe(
			customPlaceOrderButton
		);

		// The members the classic and Blocks hosts call; each must be callable.
		const fn = expect.any( Function );
		expect( window.wc.woopayments.woopay ).toEqual(
			expect.objectContaining( {
				buildAjaxUrl: fn,
				claimWooPayRedirect: fn,
				clearWooPayReturned: fn,
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
				wasReturnedByWooPay: fn,
			} )
		);
	} );

	// The script loads in the footer, so its marker read runs before the checkout's DOM ready hash readers.
	it( 'takes the WooPay return marker off the URL as it loads', () => {
		window.history.replaceState(
			null,
			'',
			'/checkout/#wc-woopay-returned'
		);

		try {
			loadEntry();

			expect( window.location.hash ).toBe( '' );
			expect( window.wc.woopayments.woopay.wasReturnedByWooPay() ).toBe(
				true
			);
		} finally {
			window.sessionStorage.clear();
			window.history.replaceState( null, '', '/' );
		}
	} );
} );
