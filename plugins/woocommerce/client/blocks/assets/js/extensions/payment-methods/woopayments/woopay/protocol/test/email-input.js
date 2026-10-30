/**
 * Internal dependencies
 */
import { handleEmailInput } from '../email-input';

const WOOPAY_HOST = 'https://pay.woo.test';

// What a classic checkout's localized config sends: true flags as '1', false flags as ''.
const getArgs = ( overrides = {} ) => ( {
	wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
	post: jest.fn( ( endpoint ) => {
		if ( endpoint === 'get_woopay_signature' ) {
			return Promise.resolve( {
				success: true,
				data: { signature: 'sig' },
			} );
		}
		return Promise.resolve( {} );
	} ),
	initWooPayNonce: 'init-nonce',
	woopaySessionNonce: 'session-nonce',
	orderId: '',
	key: '',
	billingEmail: '',
	getInitAppearance: () => null,
	getInitFontRules: () => null,
	getSessionAppearance: () => null,
	woopayHost: WOOPAY_HOST,
	woopayMerchantId: '123',
	woopaySignatureNonce: 'signature-nonce',
	wcpayVersionNumber: '10.8.0',
	testMode: '1',
	isWoopayFirstPartyAuthEnabled: '1',
	woopayIsCountryAvailable: '1',
	isBlocksCheckout: false,
	otpIframeTitle: 'WooPay SMS code verification',
	otpCloseLabel: 'Close',
	unavailableMessage: 'WooPay is unavailable at this time.',
	recordEvent: jest.fn(),
	navigate: jest.fn(),
	onPlaceOrder: jest.fn(),
	isSaveMyInfoPreChecked: () => '1',
	...overrides,
} );

const flushPromises = () => jest.advanceTimersByTimeAsync( 0 );

describe( 'WooPay email check (shared)', () => {
	let input;
	let windowListeners;
	let addEventListenerSpy;

	const renderField = () => {
		document.body.innerHTML =
			'<form name="checkout"><p class="form-row"><input type="email" id="billing_email" /></p></form>';
		return document.getElementById( 'billing_email' );
	};

	const userExists = ( exists ) =>
		jest.fn( () =>
			Promise.resolve( {
				status: 200,
				json: () => Promise.resolve( { 'user-exists': exists } ),
			} )
		);

	const typeEmail = async ( email ) => {
		input.value = email;
		input.dispatchEvent( new window.Event( 'input' ) );
		await jest.advanceTimersByTimeAsync( 500 );
		await flushPromises();
	};

	const getOtpUrl = () =>
		new URL( document.querySelector( '.woopay-otp-iframe' ).src );

	beforeEach( () => {
		jest.useFakeTimers();
		windowListeners = [];
		const addEventListener = window.addEventListener.bind( window );
		addEventListenerSpy = jest
			.spyOn( window, 'addEventListener' )
			.mockImplementation( ( type, listener, options ) => {
				windowListeners.push( [ type, listener, options ] );
				addEventListener( type, listener, options );
			} );
		// jsdom has no layout: the popover scrolls the page into view.
		window.scrollTo = jest.fn();
		input = renderField();
		window.fetch = userExists( true );
	} );

	afterEach( () => {
		windowListeners.forEach( ( [ type, listener, options ] ) =>
			window.removeEventListener( type, listener, options )
		);
		addEventListenerSpy.mockRestore();
		jest.useRealTimers();
		document.body.innerHTML = '';
	} );

	test.each( [
		[ '1', 'true' ],
		[ '', 'false' ],
	] )(
		'reads testMode %p by truthiness in the lookup and the OTP iframe',
		async ( testMode, expected ) => {
			handleEmailInput( getArgs( { testMode } ), input, 'identity' );

			await typeEmail( 'shopper@example.com' );

			const lookup = new URL( window.fetch.mock.calls[ 0 ][ 0 ] );
			expect( lookup.origin + lookup.pathname ).toBe(
				`${ WOOPAY_HOST }/wp-json/platform-checkout/v1/user/exists`
			);
			expect( lookup.searchParams.get( 'test_mode' ) ).toBe( expected );
			expect( getOtpUrl().searchParams.get( 'testMode' ) ).toBe(
				expected
			);
			expect( getOtpUrl().searchParams.get( 'is_blocks' ) ).toBe(
				'false'
			);
			expect( getOtpUrl().searchParams.get( 'tracksUserIdentity' ) ).toBe(
				'identity'
			);
		}
	);

	test.each( [
		[ '1', true ],
		[ '', false ],
	] )(
		'reads isWoopayFirstPartyAuthEnabled %p by truthiness when the OTP iframe loads',
		async ( isWoopayFirstPartyAuthEnabled, posts ) => {
			const args = getArgs( { isWoopayFirstPartyAuthEnabled } );
			handleEmailInput( args, input );

			await typeEmail( 'shopper@example.com' );
			document
				.querySelector( '.woopay-otp-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );

			expect(
				args.post.mock.calls.some(
					( [ endpoint ] ) => endpoint === 'get_woopay_session'
				)
			).toBe( posts );
		}
	);

	test.each( [
		[ '1', true ],
		[ '', false ],
	] )(
		'reads the pre-checked save-my-info flag %p by truthiness',
		async ( preChecked, records ) => {
			window.fetch = userExists( false );
			const args = getArgs( {
				isSaveMyInfoPreChecked: () => preChecked,
			} );
			handleEmailInput( args, input );

			await typeEmail( 'shopper@example.com' );

			expect( args.recordEvent ).toHaveBeenCalledWith(
				'checkout_woopay_save_my_info_offered'
			);
			expect(
				args.recordEvent.mock.calls.some(
					( [ name ] ) => name === 'checkout_save_my_info_click'
				)
			).toBe( records );
		}
	);

	test.each( [
		[ '1', true ],
		[ '', false ],
	] )(
		'reads woopayIsCountryAvailable %p by truthiness before showing a connection error',
		async ( woopayIsCountryAvailable, shows ) => {
			window.fetch = jest.fn( () =>
				Promise.reject( new TypeError( 'Failed to fetch' ) )
			);
			handleEmailInput( getArgs( { woopayIsCountryAvailable } ), input );

			await typeEmail( 'shopper@example.com' );

			expect( !! document.querySelector( '[role="alert"]' ) ).toBe(
				shows
			);
		}
	);

	test( 'puts the notice where the calling script says', async () => {
		window.fetch = jest.fn( () =>
			Promise.reject( new TypeError( 'Failed to fetch' ) )
		);
		handleEmailInput(
			getArgs( { getNoticeNode: ( parentDiv ) => parentDiv.parentNode } ),
			input
		);

		await typeEmail( 'shopper@example.com' );

		expect(
			document.querySelector( 'form > [role="alert"]' ).textContent
		).toBe( 'WooPay is unavailable at this time.' );
	} );

	test.each( [
		[ true, true ],
		[ false, false ],
	] )(
		'with refocusFieldWhenDialogHadFocus %p, a failed init_woopay gives focus back to the field: %p',
		async ( refocusFieldWhenDialogHadFocus, refocuses ) => {
			const args = getArgs( {
				refocusFieldWhenDialogHadFocus,
				post: jest.fn( ( endpoint ) =>
					endpoint === 'init_woopay'
						? Promise.reject( new Error( 'failed' ) )
						: Promise.resolve( {
								success: true,
								data: { signature: 'sig' },
							} )
				),
			} );
			handleEmailInput( args, input );
			await typeEmail( 'shopper@example.com' );
			document.querySelector( '.woopay-otp-iframe-close' ).focus();

			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: WOOPAY_HOST,
					data: {
						action: 'redirect_to_woopay',
						platformCheckoutUserSession: 'session',
					},
				} )
			);
			await flushPromises();

			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( document.activeElement === input ).toBe( refocuses );
		}
	);

	test( 'cancels the lookup through the calling script when the order is placed', async () => {
		const args = getArgs();
		handleEmailInput( args, input );

		expect( args.onPlaceOrder ).toHaveBeenCalledTimes( 1 );
		await typeEmail( 'shopper@example.com' );
		expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();

		args.onPlaceOrder.mock.calls[ 0 ][ 0 ]();

		expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
	} );
} );
