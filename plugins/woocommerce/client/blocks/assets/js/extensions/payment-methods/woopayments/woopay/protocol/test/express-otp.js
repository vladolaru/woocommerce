/**
 * Internal dependencies
 */
import { openExpressOtpIframe } from '../express-otp';

const WOOPAY_HOST = 'https://pay.woo.test';

const getArgs = ( overrides = {} ) => ( {
	post: jest.fn( () => Promise.resolve( {} ) ),
	initWooPayNonce: 'init-nonce',
	woopaySessionNonce: 'session-nonce',
	orderId: undefined,
	key: undefined,
	billingEmail: undefined,
	getInitAppearance: () => null,
	getInitFontRules: () => null,
	getSessionAppearance: () => null,
	woopayHost: WOOPAY_HOST,
	wcpayVersionNumber: '10.8.0',
	testMode: '1',
	isWoopayFirstPartyAuthEnabled: '',
	otpIframeTitle: 'WooPay SMS code verification',
	otpCloseLabel: 'Close',
	unavailableMessage: 'WooPay is unavailable at this time.',
	showError: jest.fn(),
	navigate: jest.fn(),
	...overrides,
} );

const sendRedirect = () =>
	window.dispatchEvent(
		new window.MessageEvent( 'message', {
			origin: WOOPAY_HOST,
			data: {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'session',
			},
		} )
	);

const flushPromises = async () => {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
};

describe( 'WooPay button OTP dialog (shared)', () => {
	afterEach( () => {
		document.querySelector( '.woopay-otp-iframe-close' )?.click();
		document.body.innerHTML = '';
	} );

	const getOtpUrl = () =>
		new URL( document.querySelector( '.woopay-otp-iframe' ).src );

	test.each( [
		[ '1', 'true' ],
		[ '', 'false' ],
	] )( 'reads testMode %p by truthiness', ( testMode, expected ) => {
		openExpressOtpIframe(
			getArgs( { testMode } ),
			'cart',
			'shopper@example.com',
			'identity'
		);

		expect( getOtpUrl().searchParams.get( 'testMode' ) ).toBe( expected );
		expect( getOtpUrl().searchParams.get( 'email' ) ).toBe(
			'shopper@example.com'
		);
		expect( getOtpUrl().searchParams.get( 'express_context' ) ).toBe(
			'cart'
		);
	} );

	test.each( [
		[ '1', true ],
		[ '', false ],
	] )(
		'reads isWoopayFirstPartyAuthEnabled %p by truthiness when the iframe loads',
		( isWoopayFirstPartyAuthEnabled, posts ) => {
			const args = getArgs( { isWoopayFirstPartyAuthEnabled } );
			openExpressOtpIframe( args, 'cart', '' );

			document
				.querySelector( '.woopay-otp-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );

			expect( args.post.mock.calls.length > 0 ).toBe( posts );
		}
	);

	test( 'sends the shopper to the URL the calling script makes of the WooPay answer', async () => {
		const args = getArgs( {
			post: jest.fn( () =>
				Promise.resolve( {
					result: 'success',
					url: `${ WOOPAY_HOST }/woopay/?platform_checkout_key=k`,
				} )
			),
			getRedirectUrl: ( url ) => `${ url }&pay_for_order=true`,
		} );
		openExpressOtpIframe( args, 'pay_for_order', '' );

		sendRedirect();
		await flushPromises();

		expect( args.navigate ).toHaveBeenCalledWith(
			`${ WOOPAY_HOST }/woopay/?platform_checkout_key=k&pay_for_order=true`
		);
	} );

	test.each( [
		[ true, 1 ],
		[ false, 0 ],
	] )(
		'with reportFailureWhenClosed %p, a failed init_woopay after the shopper closed the dialog shows %p errors',
		async ( reportFailureWhenClosed, errors ) => {
			let reject;
			const args = getArgs( {
				reportFailureWhenClosed,
				post: jest.fn(
					() => new Promise( ( resolve, r ) => ( reject = r ) )
				),
			} );
			openExpressOtpIframe( args, 'cart', '' );

			sendRedirect();
			document.querySelector( '.woopay-otp-iframe-close' ).click();
			reject( new Error( 'failed' ) );
			await flushPromises();

			expect( args.showError ).toHaveBeenCalledTimes( errors );
		}
	);

	test( 'gives focus back to the control the calling script names', () => {
		document.body.innerHTML =
			'<button id="opener">WooPay</button><button id="replacement">WooPay</button>';
		document.getElementById( 'opener' ).focus();
		const getFocusTarget = jest.fn( () =>
			document.getElementById( 'replacement' )
		);
		openExpressOtpIframe( getArgs( { getFocusTarget } ), 'checkout', '' );

		document.querySelector( '.woopay-otp-iframe-close' ).click();

		expect( getFocusTarget ).toHaveBeenCalledWith(
			document.getElementById( 'opener' )
		);
		expect( document.activeElement.id ).toBe( 'replacement' );
	} );
} );
