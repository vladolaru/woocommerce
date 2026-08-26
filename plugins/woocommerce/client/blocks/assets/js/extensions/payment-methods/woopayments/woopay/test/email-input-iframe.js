/**
 * Internal dependencies
 */
import {
	handleWooPayEmailInput,
	initWooPay,
	isPreviewing,
	shouldHandleWooPayEmailInput,
	validateEmail,
	shouldSkipWooPay,
	deleteSkipWooPayCookie,
} from '../email-input-iframe';

const WOOPAY_HOST = 'https://pay.woo.test';

const baseSettings = {
	isWooPayEnabled: true,
	isWooPayEmailInputEnabled: true,
	testMode: true,
	wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
	ajaxUrl: 'https://example.test/admin-ajax.php',
	platformTrackerNonce: 'tracks-nonce',
	isShopperTrackingEnabled: true,
	woopayHost: WOOPAY_HOST,
	wcpayVersionNumber: '10.8.0',
	woopayMerchantId: '123',
	woopaySignatureNonce: 'signature-nonce',
	woopaySessionNonce: 'session-nonce',
	initWooPayNonce: 'init-nonce',
	woopayIsCountryAvailable: true,
	isWooPayGlobalThemeSupportEnabled: true,
	woopayAppearance: { theme: 'stripe' },
	woopayFontRules: [ { cssSrc: 'https://fonts.wp.com/font.css' } ],
	woopayOtpIframeTitle: 'WooPay SMS code verification',
	woopayUnavailableMessage: 'WooPay is unavailable at this time.',
};

const flushPromises = () =>
	new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

describe( 'WooPay email input (blocks)', () => {
	const originalLocation = window.location;
	let fetchResponses;
	let windowListeners;
	let documentListeners;

	const renderCheckout = () => {
		document.body.innerHTML =
			'<div data-block-name="woocommerce/checkout">' +
			'<div id="contact-fields">' +
			'<div class="wc-block-components-text-input">' +
			'<input type="email" id="email" />' +
			'</div></div>' +
			'<button class="wc-block-components-checkout-place-order-button">Place order</button>' +
			'</div>';
		return document.getElementById( 'email' );
	};

	const getFetchCalls = ( prefix ) =>
		window.fetch.mock.calls.filter( ( [ url ] ) =>
			String( url ).startsWith( prefix )
		);

	const getAjaxCalls = ( endpoint ) =>
		getFetchCalls( `/?wc-ajax=wcpay_${ endpoint }` ).map(
			( [ , options ] ) => Object.fromEntries( options.body.entries() )
		);

	const getTrackedEventNames = () =>
		window.fetch.mock.calls
			.filter(
				( [ url, options ] ) =>
					url === baseSettings.ajaxUrl &&
					options?.body?.get( 'action' ) === 'platform_tracks'
			)
			.map( ( [ , options ] ) => options.body.get( 'tracksEventName' ) );

	const typeEmail = async ( input, email ) => {
		input.value = email;
		input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		await wait( 550 );
		await flushPromises();
	};

	const postWooPayMessage = ( data, origin = WOOPAY_HOST ) => {
		window.dispatchEvent(
			new window.MessageEvent( 'message', { data, origin } )
		);
	};

	const setup = async ( overrides = {} ) => {
		const input = renderCheckout();
		await handleWooPayEmailInput( '#email', {
			...baseSettings,
			...overrides,
		} );
		await flushPromises();
		return input;
	};

	beforeEach( () => {
		fetchResponses = {
			'/?wc-ajax=wcpay_get_woopay_signature': {
				body: { success: true, data: { signature: 'sig-1' } },
			},
			'/?wc-ajax=wcpay_init_woopay': {
				body: {
					result: 'success',
					url: `${ WOOPAY_HOST }/checkout/?session=1`,
				},
			},
			'/?wc-ajax=wcpay_get_woopay_session': {
				body: { data: { session: 'encrypted' } },
			},
			[ `${ WOOPAY_HOST }/wp-json/platform-checkout/v1/user/exists?` ]: {
				body: { 'user-exists': true },
			},
			[ baseSettings.ajaxUrl ]: { body: { success: true } },
		};
		window.fetch = jest.fn( ( url ) => {
			const key = Object.keys( fetchResponses ).find( ( prefix ) =>
				String( url ).startsWith( prefix )
			);
			const entry = key ? fetchResponses[ key ] : null;
			if ( entry?.reject ) {
				return Promise.reject( entry.reject );
			}
			return Promise.resolve( {
				ok: true,
				status: entry?.status ?? 200,
				json: () => Promise.resolve( entry?.body ?? {} ),
			} );
		} );
		window.scrollTo = jest.fn();
		document.cookie = 'tk_ai=anon-identity; path=/';
		windowListeners = [];
		documentListeners = [];
		const originalWindowAdd = window.addEventListener.bind( window );
		const originalDocumentAdd = document.addEventListener.bind( document );
		jest.spyOn( window, 'addEventListener' ).mockImplementation(
			( type, listener, options ) => {
				windowListeners.push( [ type, listener, options ] );
				originalWindowAdd( type, listener, options );
			}
		);
		jest.spyOn( document, 'addEventListener' ).mockImplementation(
			( type, listener, options ) => {
				documentListeners.push( [ type, listener, options ] );
				originalDocumentAdd( type, listener, options );
			}
		);
		delete window.location;
		window.location = {
			href: 'https://example.test/checkout/',
			search: '',
			pathname: '/checkout/',
		};
		window.history.replaceState = jest.fn();
	} );

	afterEach( () => {
		windowListeners.forEach( ( [ type, listener, options ] ) =>
			window.removeEventListener( type, listener, options )
		);
		documentListeners.forEach( ( [ type, listener, options ] ) =>
			document.removeEventListener( type, listener, options )
		);
		jest.restoreAllMocks();
		[ 'tk_ai', 'skip_woopay' ].forEach( ( name ) => {
			document.cookie = `${ name }=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;`;
		} );
		window.location = originalLocation;
		document.body.innerHTML = '';
		document.body.style.overflow = '';
	} );

	test( 'validates emails like the checkout form does', () => {
		expect( validateEmail( 'shopper@example.com' ) ).toBe( true );
		expect( validateEmail( 'shopper@example.c' ) ).toBe( false );
		expect( validateEmail( 'not-an-email' ) ).toBe( false );
	} );

	test( 'reads and clears the skip_woopay cookie', () => {
		expect( shouldSkipWooPay() ).toBe( false );
		document.cookie = 'skip_woopay=1; path=/';
		expect( shouldSkipWooPay() ).toBe( true );
		deleteSkipWooPayCookie();
		expect( shouldSkipWooPay() ).toBe( false );
	} );

	test( 'gates the flow like the plugin blocks entry point', () => {
		renderCheckout();
		expect( shouldHandleWooPayEmailInput( baseSettings ) ).toBe( true );
		expect(
			shouldHandleWooPayEmailInput( { ...baseSettings, isWooPayEnabled: false } )
		).toBe( false );
		expect(
			shouldHandleWooPayEmailInput( {
				...baseSettings,
				isWooPayEmailInputEnabled: false,
			} )
		).toBe( false );
		expect(
			shouldHandleWooPayEmailInput( { ...baseSettings, isPreview: true } )
		).toBe( false );

		window.location.search = '?customize_messenger_channel=preview-0';
		expect( isPreviewing( baseSettings ) ).toBe( true );
		expect( shouldHandleWooPayEmailInput( baseSettings ) ).toBe( false );
		window.location.search = '';

		document.body.innerHTML = '<div id="email"></div>';
		expect( shouldHandleWooPayEmailInput( baseSettings ) ).toBe( false );
	} );

	test( 'ignores WooPay messages from any other origin', async () => {
		const input = await setup();
		await typeEmail( input, 'shopper@example.com' );

		postWooPayMessage(
			{
				action: 'redirect_to_woopay_skip_session_init',
				redirectUrl: 'https://evil.example/phish',
			},
			'https://evil.example'
		);
		postWooPayMessage( { action: 'close_modal' }, 'https://evil.example' );
		postWooPayMessage(
			{
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'stolen',
			},
			'https://evil.example'
		);
		await flushPromises();

		expect( window.location.href ).toBe( 'https://example.test/checkout/' );
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).not.toBeNull();
		expect( getAjaxCalls( 'init_woopay' ) ).toHaveLength( 0 );
	} );

	test( 'trusts no sender when the WooPay host is unparsable', async () => {
		const input = await setup( { woopayHost: 'not a url' } );
		await typeEmail( input, 'shopper@example.com' );

		postWooPayMessage(
			{
				action: 'redirect_to_woopay_skip_session_init',
				redirectUrl: 'https://evil.example/phish',
			},
			''
		);

		expect( window.location.href ).toBe( 'https://example.test/checkout/' );
	} );

	test( 'does nothing on pay-for-order pages', async () => {
		const input = await setup( { isOrderPay: true } );

		await typeEmail( input, 'shopper@example.com' );

		expect( getAjaxCalls( 'get_woopay_signature' ) ).toHaveLength( 0 );
	} );

	test( 'waits for the email field the checkout block renders later', async () => {
		document.body.innerHTML =
			'<div data-block-name="woocommerce/checkout"></div>';
		const pending = handleWooPayEmailInput( '#email', baseSettings );
		document.querySelector(
			'[data-block-name="woocommerce/checkout"]'
		).innerHTML =
			'<div><div class="wc-block-components-text-input"><input type="email" id="email" /></div></div>';
		await pending;

		const input = document.getElementById( 'email' );
		await typeEmail( input, 'shopper@example.com' );

		expect( getAjaxCalls( 'get_woopay_signature' ) ).toHaveLength( 1 );
	} );

	test( 'looks the typed email up at WooPay and opens the OTP iframe for a registered shopper', async () => {
		const userCheckEvents = [];
		window.addEventListener( 'woopayUserCheck', ( event ) =>
			userCheckEvents.push( event.detail.isRegisteredUser )
		);
		const input = await setup();

		await typeEmail( input, 'shopper@example.com' );

		expect( getAjaxCalls( 'get_woopay_signature' ) ).toEqual( [
			{ _ajax_nonce: 'signature-nonce' },
		] );
		const [ userExistsUrl, userExistsOptions ] = getFetchCalls(
			`${ WOOPAY_HOST }/wp-json/platform-checkout/v1/user/exists?`
		)[ 0 ];
		expect( userExistsUrl ).toBe(
			`${ WOOPAY_HOST }/wp-json/platform-checkout/v1/user/exists?` +
				'email=shopper%40example.com&test_mode=true&wcpay_version=10.8.0' +
				'&blog_id=123&request_signature=sig-1'
		);
		expect( userExistsOptions.signal ).toBeInstanceOf( window.AbortSignal );

		const iframe = document.querySelector( '.woopay-otp-iframe' );
		const wrapper = document.querySelector( '.woopay-otp-iframe-wrapper' );
		expect( wrapper.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( wrapper.parentNode ).toBe( input.parentNode );
		expect( iframe.title ).toBe( 'WooPay SMS code verification' );
		const otpUrl = new URL( iframe.src );
		expect( otpUrl.origin + otpUrl.pathname ).toBe( `${ WOOPAY_HOST }/otp/` );
		expect( Object.fromEntries( otpUrl.searchParams ) ).toEqual( {
			email: 'shopper@example.com',
			testMode: 'true',
			needsHeader: 'false',
			wcpayVersion: '10.8.0',
			is_blocks: 'true',
			// The checkout permalink from wcSettings wins over the page URL.
			source_url: 'https://local/checkout/',
			viewport: '0x0',
			tracksUserIdentity: JSON.stringify( {
				_ut: 'anon',
				_ui: 'anon-identity',
			} ),
		} );
		expect( userCheckEvents ).toEqual( [ false, true ] );
		expect( getTrackedEventNames() ).toEqual( [
			'checkout_email_address_woopay_check',
		] );
	} );

	test( 'resolves the Tracks identity through the platform when no cookie is set', async () => {
		document.cookie = 'tk_ai=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
		fetchResponses[ baseSettings.ajaxUrl ] = {
			body: { success: true, data: { _ut: 'user', _ui: '42' } },
		};
		const input = await setup();

		await typeEmail( input, 'shopper@example.com' );

		const identityCall = window.fetch.mock.calls.find(
			( [ url, options ] ) =>
				url === baseSettings.ajaxUrl &&
				options?.body?.get( 'action' ) === 'get_identity'
		);
		expect( identityCall[ 1 ].body.get( 'tracksNonce' ) ).toBe(
			'tracks-nonce'
		);
		const otpUrl = new URL( document.querySelector( '.woopay-otp-iframe' ).src );
		expect( otpUrl.searchParams.get( 'tracksUserIdentity' ) ).toBe(
			JSON.stringify( { _ut: 'user', _ui: '42' } )
		);
	} );

	test( 'offers save-my-info instead of the OTP iframe for an unknown email', async () => {
		fetchResponses[
			`${ WOOPAY_HOST }/wp-json/platform-checkout/v1/user/exists?`
		] = { body: { 'user-exists': false } };
		window.woopayCheckout = { PRE_CHECK_SAVE_MY_INFO: true };
		const input = await setup();

		await typeEmail( input, 'new@example.com' );

		expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
		expect( getTrackedEventNames() ).toEqual( [
			'checkout_email_address_woopay_check',
			'checkout_woopay_save_my_info_offered',
			'checkout_save_my_info_click',
		] );
		delete window.woopayCheckout;
	} );

	test( 'sends the session data to the iframe on load when first-party auth is on', async () => {
		const input = await setup( { isWoopayFirstPartyAuthEnabled: true } );
		await typeEmail( input, 'shopper@example.com' );
		const iframe = document.querySelector( '.woopay-otp-iframe' );
		const postMessage = jest.fn();
		Object.defineProperty( iframe, 'contentWindow', {
			value: { postMessage },
		} );

		iframe.dispatchEvent( new window.Event( 'load' ) );
		await flushPromises();

		expect( getAjaxCalls( 'get_woopay_session' ) ).toEqual( [
			{
				_ajax_nonce: 'session-nonce',
				order_id: '',
				key: '',
				billing_email: '',
				appearance: JSON.stringify( { theme: 'stripe' } ),
			},
		] );
		expect( postMessage ).toHaveBeenCalledWith(
			{
				action: 'setSessionData',
				value: { data: { session: 'encrypted' } },
			},
			WOOPAY_HOST
		);
		expect( iframe.classList.contains( 'open' ) ).toBe( true );
		expect( document.body.style.overflow ).toBe( 'hidden' );
	} );

	test( 'hands the WooPay session back through init_woopay and redirects once', async () => {
		const input = await setup();
		await typeEmail( input, 'shopper@example.com' );

		postWooPayMessage(
			{
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'session-token',
			},
			'https://evil.example'
		);
		postWooPayMessage( {
			action: 'redirect_to_woopay',
			platformCheckoutUserSession: 'session-token',
		} );
		postWooPayMessage( {
			action: 'redirect_to_platform_checkout',
			platformCheckoutUserSession: 'session-token',
		} );
		await flushPromises();
		await flushPromises();

		expect( getAjaxCalls( 'init_woopay' ) ).toEqual( [
			{
				_wpnonce: 'init-nonce',
				appearance: JSON.stringify( { theme: 'stripe' } ),
				font_rules: JSON.stringify( [
					{ cssSrc: 'https://fonts.wp.com/font.css' },
				] ),
				email: 'shopper@example.com',
				user_session: 'session-token',
				order_id: '',
				key: '',
				billing_email: '',
			},
		] );
		expect( window.location ).toBe( `${ WOOPAY_HOST }/checkout/?session=1` );
	} );

	test( 'redirects straight away on redirect_to_woopay_skip_session_init', async () => {
		document.cookie = 'skip_woopay=1; path=/';
		await setup();

		postWooPayMessage( {
			action: 'redirect_to_woopay_skip_session_init',
			redirectUrl: `${ WOOPAY_HOST }/direct/`,
		} );

		expect( window.location ).toBe( `${ WOOPAY_HOST }/direct/` );
		expect( shouldSkipWooPay() ).toBe( false );
	} );

	test( 'shows the unavailable notice below the field and closes the iframe when init_woopay fails', async () => {
		fetchResponses[ '/?wc-ajax=wcpay_init_woopay' ] = {
			body: { result: 'error' },
		};
		const input = await setup();
		await typeEmail( input, 'shopper@example.com' );

		postWooPayMessage( {
			action: 'redirect_to_woopay',
			platformCheckoutUserSession: 'session-token',
		} );
		await flushPromises();
		await flushPromises();

		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).toBeNull();
		const notice = document.querySelector(
			'.wc-block-checkout__guest-checkout-notice'
		);
		expect( notice.textContent ).toBe( 'WooPay is unavailable at this time.' );
		expect( notice.parentNode ).toBe( input.parentNode.parentNode );
	} );

	test( 'closes the OTP iframe on close_modal, Escape and the place-order click', async () => {
		const input = await setup();
		await typeEmail( input, 'shopper@example.com' );
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).not.toBeNull();
		postWooPayMessage( { action: 'close_modal' } );
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).toBeNull();

		await typeEmail( input, 'shopper@example.com' );
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).not.toBeNull();
		document.dispatchEvent(
			new window.KeyboardEvent( 'keyup', { key: 'Escape' } )
		);
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).toBeNull();

		await typeEmail( input, 'shopper@example.com' );
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).not.toBeNull();
		document
			.querySelector( '.wc-block-components-checkout-place-order-button' )
			.click();
		expect( document.querySelector( '.woopay-otp-iframe-wrapper' ) ).toBeNull();
	} );

	test( 'surfaces the unavailable notice when WooPay cannot be reached', async () => {
		fetchResponses[
			`${ WOOPAY_HOST }/wp-json/platform-checkout/v1/user/exists?`
		] = { reject: new TypeError( 'Failed to fetch' ) };
		const input = await setup();

		await typeEmail( input, 'shopper@example.com' );

		expect(
			document.querySelector( '.wc-block-checkout__guest-checkout-notice' )
				.textContent
		).toBe( 'WooPay is unavailable at this time.' );
		expect( document.querySelector( '.wc-block-components-spinner' ) ).toBeNull();
	} );

	test( 'records a back-button return, sets the session skip cookie and cleans the URL', async () => {
		window.location.search = '?skip_woopay=true&foo=bar';
		const userCheckEvents = [];
		window.addEventListener( 'woopayUserCheck', ( event ) =>
			userCheckEvents.push( event.detail.isRegisteredUser )
		);

		await setup();

		expect( shouldSkipWooPay() ).toBe( true );
		expect( getTrackedEventNames() ).toEqual( [ 'woopay_skipped' ] );
		expect( window.history.replaceState ).toHaveBeenCalledWith(
			null,
			null,
			'/checkout/?foo=bar'
		);
		await wait( 2100 );
		expect( userCheckEvents ).toEqual( [ true ] );
	} );

	test( 'initWooPay keeps a single request in flight', async () => {
		const first = initWooPay( baseSettings, 'shopper@example.com', 'tok' );
		const second = initWooPay( baseSettings, 'shopper@example.com', 'tok' );

		expect( second ).toBeUndefined();
		await expect( first ).resolves.toEqual( {
			result: 'success',
			url: `${ WOOPAY_HOST }/checkout/?session=1`,
		} );
		expect( initWooPay( baseSettings, 'shopper@example.com', 'tok' ) ).toBeDefined();
	} );
} );
