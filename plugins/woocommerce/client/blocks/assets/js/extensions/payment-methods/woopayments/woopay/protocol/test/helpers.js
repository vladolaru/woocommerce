/**
 * Internal dependencies
 */
import {
	buildAjaxUrl,
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	initWooPay,
	isPreviewing,
	sendSessionToOtpIframe,
	shouldSkipWooPay,
	validateEmail,
} from '../helpers';

const clearCookies = () => {
	document.cookie.split( ';' ).forEach( ( cookie ) => {
		const name = cookie.split( '=' )[ 0 ].trim();
		if ( name ) {
			document.cookie = `${ name }=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;`;
		}
	} );
};

describe( 'WooPay protocol helpers', () => {
	afterEach( clearCookies );

	test( 'builds wc-ajax URLs from the wc-ajax URL it is given, and none without one', () => {
		expect(
			buildAjaxUrl(
				{ wcAjaxUrl: '/?wc-ajax=%%endpoint%%' },
				'init_woopay'
			)
		).toBe( '/?wc-ajax=wcpay_init_woopay' );
		expect( buildAjaxUrl( {}, 'init_woopay' ) ).toBe( '' );
		expect( buildAjaxUrl( { wcAjaxUrl: '' }, 'init_woopay' ) ).toBe( '' );
	} );

	test.each( [
		[ true, true ],
		[ '1', true ],
		[ false, false ],
		// A classic page's localized config sends a false flag as ''.
		[ '', false ],
		[ undefined, false ],
	] )( 'reads isPreview %p by truthiness', ( isPreview, expected ) => {
		expect( isPreviewing( { isPreview } ) ).toBe( expected );
	} );

	test( 'validates emails like the checkout form does', () => {
		expect( validateEmail( 'shopper@example.com' ) ).toBe( true );
		expect( validateEmail( 'shopper@example.c' ) ).toBe( false );
		expect( validateEmail( 'not an email' ) ).toBe( false );
	} );

	test( 'lets the first listener with an open dialog claim a redirect message from that dialog', () => {
		const openDialog = document.body.appendChild(
			document.createElement( 'iframe' )
		);
		const otherDialog = document.body.appendChild(
			document.createElement( 'iframe' )
		);
		const closedDialog = document.createElement( 'iframe' );
		const event = { source: openDialog.contentWindow };

		expect( claimWooPayRedirect( event, closedDialog ) ).toBe( false );
		expect( claimWooPayRedirect( event, otherDialog ) ).toBe( false );
		expect( claimWooPayRedirect( event, openDialog ) ).toBe( true );
		expect( claimWooPayRedirect( event, openDialog ) ).toBe( false );
		expect( console ).toHaveWarned();

		openDialog.remove();
		otherDialog.remove();
	} );

	describe( 'the warning for a dropped redirect', () => {
		// The warning is once per page, so each case gets a fresh copy of the module.
		const loadClaim = () => {
			let claim;
			jest.isolateModules( () => {
				claim = require( '../helpers' ).claimWooPayRedirect;
			} );
			return claim;
		};

		test( 'warns once when an open dialog hears a redirect from another window', () => {
			const claim = loadClaim();
			const openDialog = document.body.appendChild(
				document.createElement( 'iframe' )
			);
			const event = {
				source: {},
				data: { action: 'redirect_to_woopay' },
			};

			expect( claim( event, openDialog ) ).toBe( false );
			expect( claim( { ...event }, openDialog ) ).toBe( false );

			expect( console ).toHaveWarnedWith(
				"WooPay redirect claim dropped redirect_to_woopay: the message did not come from the open dialog's iframe."
			);
			// eslint-disable-next-line no-console -- The matchers cannot count the calls.
			expect( console.warn ).toHaveBeenCalledTimes( 1 );

			openDialog.remove();
		} );

		// The email check hears the button's redirect with its own dialog closed, and a listener can hear an event another
		// has claimed. Both are normal on a page with the two listeners.
		test( 'stays silent for a closed dialog and for an event already claimed', () => {
			const claim = loadClaim();
			const openDialog = document.body.appendChild(
				document.createElement( 'iframe' )
			);
			const data = { action: 'redirect_to_woopay' };

			claim(
				{ source: openDialog.contentWindow, data },
				document.createElement( 'iframe' )
			);
			claim(
				{ source: {}, data, wcWooPayInitClaimed: true },
				openDialog
			);

			expect( console ).not.toHaveWarned();

			openDialog.remove();
		} );
	} );

	// A discarded frame's message can carry a null source, and a closed iframe's window is null too.
	test( 'lets no closed dialog claim a message with no source', () => {
		expect(
			claimWooPayRedirect(
				{ source: null },
				document.createElement( 'iframe' )
			)
		).toBe( false );
	} );

	describe( 'the skip_woopay cookie', () => {
		const expire = ( name ) => {
			document.cookie = `${ name }=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;`;
		};

		afterEach( () => {
			[ 'landing', 'skip_woopay', 'sskip_woopay' ].forEach( expire );
		} );

		test( 'reads and clears the cookie', () => {
			expect( shouldSkipWooPay() ).toBe( false );

			document.cookie = 'skip_woopay=1; path=/';
			expect( shouldSkipWooPay() ).toBe( true );

			deleteSkipWooPayCookie();
			expect( shouldSkipWooPay() ).toBe( false );
			expect( document.cookie ).not.toContain( 'skip_woopay=1' );
		} );

		// A cookie that stores the URL of WooPay's back-to-store link holds the text, and an older cookie comes first.
		test( 'finds a set cookie behind an earlier cookie that holds the text', () => {
			document.cookie =
				'landing=%2Fcheckout%3Fskip_woopay%3Dtrue; path=/';
			document.cookie = 'skip_woopay=1; path=/';

			expect( shouldSkipWooPay() ).toBe( true );

			deleteSkipWooPayCookie();
			expect( document.cookie ).not.toContain( 'skip_woopay=1' );
			expect( document.cookie ).toContain( 'landing=' );
		} );

		// Browsers keep a cookie set without "="; jsdom drops it, so the getter stands in for one.
		test( 'does not throw on a skip_woopay cookie without a value', () => {
			const cookie = jest
				.spyOn( document, 'cookie', 'get' )
				.mockReturnValue( 'skip_woopay' );

			expect( shouldSkipWooPay() ).toBe( false );
			expect( deleteSkipWooPayCookie ).not.toThrow();

			cookie.mockRestore();
		} );

		// Client 11.2.0's own cases (woopay/__tests__/utils.test.js:47-66).
		test.each( [ 'skip_woopay=10', 'sskip_woopay=1', 'skip_woopay=' ] )(
			'reads %s as no skip',
			( cookie ) => {
				document.cookie = `${ cookie }; path=/`;

				expect( shouldSkipWooPay() ).toBe( false );
			}
		);
	} );

	describe( 'initWooPay', () => {
		const getArgs = ( answer ) => ( {
			post: jest.fn( () => answer ),
			initWooPayNonce: 'init-nonce',
			orderId: '',
			key: undefined,
			billingEmail: 'billing@example.com',
			getInitAppearance: jest.fn( () => ( { theme: 'stripe' } ) ),
			getInitFontRules: jest.fn( () => null ),
		} );

		test( 'posts init_woopay with the values the calling script holds, in the plugin order', async () => {
			const args = getArgs( Promise.resolve( { result: 'success' } ) );

			await expect(
				initWooPay( args, 'shopper@example.com', 'session' )
			).resolves.toEqual( { result: 'success' } );

			expect( args.post ).toHaveBeenCalledTimes( 1 );
			const [ endpoint, data ] = args.post.mock.calls[ 0 ];
			expect( endpoint ).toBe( 'init_woopay' );
			expect( Object.keys( data ) ).toEqual( [
				'_wpnonce',
				'appearance',
				'font_rules',
				'email',
				'user_session',
				'order_id',
				'key',
				'billing_email',
			] );
			expect( data ).toEqual( {
				_wpnonce: 'init-nonce',
				appearance: { theme: 'stripe' },
				font_rules: null,
				email: 'shopper@example.com',
				user_session: 'session',
				order_id: '',
				key: undefined,
				billing_email: 'billing@example.com',
			} );
		} );

		test( 'keeps one request in flight and reads no appearance for the refused one', async () => {
			let resolve;
			const args = getArgs( new Promise( ( r ) => ( resolve = r ) ) );

			const first = initWooPay( args, 'a@example.com', 'one' );
			expect(
				initWooPay( args, 'a@example.com', 'two' )
			).toBeUndefined();
			expect( args.getInitAppearance ).toHaveBeenCalledTimes( 1 );

			resolve( {} );
			await first;

			expect(
				initWooPay(
					{ ...args, post: () => Promise.resolve( {} ) },
					'a@example.com',
					'three'
				)
			).toBeDefined();
		} );
	} );

	describe( 'sendSessionToOtpIframe', () => {
		const getIframe = () => ( {
			contentWindow: { postMessage: jest.fn() },
		} );

		test( 'posts the store session to the OTP iframe with first-party auth on', async () => {
			const iframe = getIframe();
			const answer = { data: { session: 'session' } };
			const post = jest.fn( () => Promise.resolve( answer ) );

			sendSessionToOtpIframe(
				{
					isWoopayFirstPartyAuthEnabled: '1',
					woopayHost: 'https://pay.woo.test',
					woopaySessionNonce: 'session-nonce',
					orderId: 12,
					key: 'wc_order_key',
					billingEmail: '',
					getSessionAppearance: () => null,
					post,
				},
				iframe
			);
			await Promise.resolve();
			await Promise.resolve();

			expect( post ).toHaveBeenCalledWith( 'get_woopay_session', {
				_ajax_nonce: 'session-nonce',
				order_id: 12,
				key: 'wc_order_key',
				billing_email: '',
				appearance: null,
			} );
			expect( iframe.contentWindow.postMessage ).toHaveBeenCalledWith(
				{ action: 'setSessionData', value: answer },
				'https://pay.woo.test'
			);
		} );

		test.each( [ false, '', undefined ] )(
			'posts nothing when first-party auth is %p',
			( isWoopayFirstPartyAuthEnabled ) => {
				const post = jest.fn();

				sendSessionToOtpIframe(
					{ isWoopayFirstPartyAuthEnabled, post },
					getIframe()
				);

				expect( post ).not.toHaveBeenCalled();
			}
		);
	} );
} );
