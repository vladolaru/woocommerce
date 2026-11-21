/**
 * Internal dependencies
 */
import { openExpressOtpIframe } from '../express-otp';
import {
	dispatchFromWindow,
	getIframeWindow,
	postFromWooPay,
} from './utils/post-from-woopay';

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

const REDIRECT = {
	action: 'redirect_to_woopay',
	platformCheckoutUserSession: 'session',
};

const sendRedirect = () => postFromWooPay( REDIRECT, WOOPAY_HOST );

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
		[ 'open', 1 ],
		[ 'closed', 0 ],
	] )(
		'with the dialog %s, a failed init_woopay shows %p errors',
		async ( dialog, errors ) => {
			let reject;
			const args = getArgs( {
				post: jest.fn(
					() => new Promise( ( resolve, r ) => ( reject = r ) )
				),
			} );
			openExpressOtpIframe( args, 'cart', '' );

			sendRedirect();
			if ( dialog === 'closed' ) {
				document.querySelector( '.woopay-otp-iframe-close' ).click();
			}
			reject( new Error( 'failed' ) );
			await flushPromises();

			expect( args.showError ).toHaveBeenCalledTimes( errors );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
		}
	);

	test.each( [
		[ 'a rejection', ( settle ) => settle.reject( new Error( 'failed' ) ) ],
		[
			'a failed answer',
			( settle ) => settle.resolve( { result: 'failure' } ),
		],
	] )(
		'leaves the new dialog alone on %s of a request from a dialog the shopper closed',
		async ( name, finish ) => {
			const settle = {};
			const args = getArgs( {
				post: jest.fn(
					() =>
						new Promise( ( resolve, reject ) => {
							settle.resolve = resolve;
							settle.reject = reject;
						} )
				),
			} );
			openExpressOtpIframe( args, 'cart', '' );

			sendRedirect();
			document.querySelector( '.woopay-otp-iframe-close' ).click();
			openExpressOtpIframe( args, 'cart', '' );
			const reopened = document.querySelector( '.woopay-otp-iframe' );

			finish( settle );
			await flushPromises();

			expect( args.showError ).not.toHaveBeenCalled();
			expect( args.navigate ).not.toHaveBeenCalled();
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBe(
				reopened
			);

			document.querySelector( '.woopay-otp-iframe-close' ).click();
		}
	);

	// The shopper asked for WooPay again, and dialog B's own redirect can lose to the in-flight guard, so A's success is
	// the one that takes them there. Client 11.2.0 reads the page here too.
	test( 'follows a success from a closed dialog while another dialog is open', async () => {
		const url = `${ WOOPAY_HOST }/woopay/?platform_checkout_key=k`;
		let resolve;
		const args = getArgs( {
			post: jest.fn( () => new Promise( ( r ) => ( resolve = r ) ) ),
		} );
		openExpressOtpIframe( args, 'cart', '' );

		sendRedirect();
		document.querySelector( '.woopay-otp-iframe-close' ).click();
		openExpressOtpIframe( args, 'cart', '' );

		resolve( { result: 'success', url } );
		await flushPromises();

		expect( args.navigate ).toHaveBeenCalledWith( url );
		expect( args.showError ).not.toHaveBeenCalled();
	} );

	test( 'follows no success once every dialog is closed', async () => {
		let resolve;
		const args = getArgs( {
			post: jest.fn( () => new Promise( ( r ) => ( resolve = r ) ) ),
		} );
		openExpressOtpIframe( args, 'cart', '' );

		sendRedirect();
		document.querySelector( '.woopay-otp-iframe-close' ).click();

		resolve( {
			result: 'success',
			url: `${ WOOPAY_HOST }/woopay/?platform_checkout_key=k`,
		} );
		await flushPromises();

		expect( args.navigate ).not.toHaveBeenCalled();
	} );

	// Dialog A's redirect, already on its way when the shopper closed A and opened B, must not start B's request with
	// A's session.
	test( 'claims no redirect from a dialog the shopper closed', async () => {
		const url = `${ WOOPAY_HOST }/woopay/?platform_checkout_key=k`;
		const args = getArgs( {
			post: jest.fn( () =>
				Promise.resolve( { result: 'success', url } )
			),
		} );
		openExpressOtpIframe( args, 'cart', '' );
		const closedDialog = getIframeWindow(
			document.querySelector( '.woopay-otp-iframe' )
		);
		document.querySelector( '.woopay-otp-iframe-close' ).click();
		openExpressOtpIframe( args, 'cart', '' );

		dispatchFromWindow( closedDialog, REDIRECT, WOOPAY_HOST );
		await flushPromises();
		expect( args.post ).not.toHaveBeenCalled();
		expect( args.navigate ).not.toHaveBeenCalled();
		expect( console ).toHaveWarned();

		sendRedirect();
		await flushPromises();
		expect( args.post ).toHaveBeenCalledTimes( 1 );
		expect( args.navigate ).toHaveBeenCalledWith( url );
	} );

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
