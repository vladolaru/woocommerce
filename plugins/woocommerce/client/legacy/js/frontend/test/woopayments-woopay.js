/**
 * @jest-environment jest-fixed-jsdom
 */

// The page prints wc-woopayments-woopay-common before this script; it sets window.wc.woopayments.woopay.
function loadWooPayProtocol() {
	jest.isolateModules( () => {
		require( '../../../../blocks/assets/js/extensions/payment-methods/woopayments/woopay/protocol' );
	} );
}

beforeEach( loadWooPayProtocol );

// Stand-in for the wp-i18n script the page loads (@wordpress/i18n __, _n and sprintf). It returns the English source
// strings and fills only the placeholders these scripts use: %s, %d and positional %1$s; escapes, flags and widths
// are not modelled.
function createI18nStub() {
	return {
		__: ( text ) => text,
		_n: ( single, plural, number ) => ( number === 1 ? single : plural ),
		sprintf: ( format, ...args ) => {
			let next = 0;
			return format.replace( /%(?:(\d+)\$)?[sd]/g, ( match, position ) =>
				String( position ? args[ Number( position ) - 1 ] : args[ next++ ] )
			);
		},
	};
}

// Marks strings translated in the woocommerce domain, so a test can tell the wp-i18n path from an English literal.
function createMarkingI18nStub() {
	const stub = createI18nStub();
	const mark = ( text, domain ) =>
		domain === 'woocommerce' ? '[fr] ' + text : text;
	return Object.assign( {}, stub, {
		__: ( text, domain ) => mark( text, domain ),
		_n: ( single, plural, number, domain ) =>
			mark( number === 1 ? single : plural, domain ),
	} );
}

// The fields a browser submits with the classic checkout form: named, enabled, and checked when a checkbox or radio
// (HTML form submission's successful controls).
function getPostedCheckoutFields() {
	const fields = {};
	Array.from( document.querySelector( 'form.checkout' ).elements ).forEach(
		( element ) => {
			if (
				! element.name ||
				element.disabled ||
				( [ 'checkbox', 'radio' ].includes( element.type ) &&
					! element.checked )
			) {
				return;
			}
			fields[ element.name ] = element.value;
		}
	);
	return fields;
}

describe( 'WooPayments WooPay checkout', () => {
	// jsdom defines `contentWindow` as an accessor on HTMLIFrameElement.prototype; tests that stub it put jsdom's back.
	const nativeContentWindow = Object.getOwnPropertyDescriptor(
		window.HTMLIFrameElement.prototype,
		'contentWindow'
	);

	afterEach( () => {
		delete window.wcWooPaymentsPhoneValidation;
		Object.defineProperty(
			window.HTMLIFrameElement.prototype,
			'contentWindow',
			nativeContentWindow
		);
	} );

	let bodyEventHandlers;
	const originalFetch = window.fetch;

	async function flushPromises() {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

	async function flushMicrotasks() {
		for ( let index = 0; index < 10; index++ ) {
			await Promise.resolve();
		}
	}

	// The Connect iframe gets its src once the Tracks identity resolves, and only a load after that counts.
	async function loadConnectIframe() {
		const iframe = document.getElementById( 'woopay-connect-iframe' );
		for ( let index = 0; index < 50 && ! iframe.getAttribute( 'src' ); index++ ) {
			await Promise.resolve();
		}
		iframe.dispatchEvent( new window.Event( 'load' ) );
	}

	const TRACKS_URL = 'https://example.test/wp-json/wc/v3/payments/tracks';

	function getTrackingRequests() {
		return window.fetch.mock.calls.filter( ( [ url ] ) => url === TRACKS_URL );
	}

	function getTrackingEvents() {
		return getTrackingRequests()
			.map( ( [ , options ] ) => ( {
				name: options.body.get( 'tracksEventName' ),
				props: JSON.parse( options.body.get( 'tracksEventProp' ) ),
			} ) );
	}

	function createJQueryMock() {
		const defaultResult = {
			length: 0,
			on: jest.fn( () => defaultResult ),
		};
		const bodyResult = {
			length: 1,
			on: jest.fn( ( event, handler ) => {
				bodyEventHandlers[ event ] = handler;
				return bodyResult;
			} ),
		};
		const jQueryMock = jest.fn( ( selectorOrCallback ) => {
			if ( typeof selectorOrCallback === 'function' ) {
				selectorOrCallback();
				return defaultResult;
			}

			if ( selectorOrCallback === document.body ) {
				return bodyResult;
			}

			return defaultResult;
		} );
		jQueryMock.post = jest.fn( () => ( {
			done: jest.fn( ( callback ) => {
				callback( {
					result: 'success',
				} );
				return {
					fail: jest.fn(),
				};
			} ),
		} ) );

		return jQueryMock;
	}

	beforeEach( () => {
		jest.resetModules();
		window.wp = Object.assign( {}, window.wp, { i18n: createI18nStub() } );
		bodyEventHandlers = {};
		if ( document.body.wooPayDirectCheckoutHandler ) {
			document.body.removeEventListener(
				'click',
				document.body.wooPayDirectCheckoutHandler
			);
		}
		delete document.body.wooPayDirectCheckoutHandler;
		delete document.body.wooPayDirectCheckoutAttached;
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input id="billing_email" value="shopper@example.com" />' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-woopay-button"><div class="woopay-express-button is-placeholder"></div></div>' +
			'<p class="form-row place-order"></p>' +
			'</form>';

		const jQueryMock = createJQueryMock();
		global.jQuery = jQueryMock;
		global.$ = jQueryMock;
		window.jQuery = jQueryMock;
		window.$ = jQueryMock;
		window.wcpay_core_woopay_config = {
			ajaxUrl: 'https://example.test/admin-ajax.php',
			tracksUrl: TRACKS_URL,
			tracksRestNonce: 'rest-nonce',
			forceNetworkSavedCards: true,
			initWooPayNonce: 'init-nonce',
			isWooPayEnabled: true,
			isShopperTrackingEnabled: true,
			platformTrackerNonce: 'tracks-nonce',
			shouldShowWooPayButton: true,
			wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
			woopayButton: {
				type: 'default',
				theme: 'dark',
				height: '48',
				radius: '4',
				size: 'default',
				context: 'checkout',
			},
			woopayHost: 'https://pay.woo.test',
			woopaySessionNonce: 'session-nonce',
			woopayPhoneLabel: 'Mobile phone number',
			woopaySaveUserLabel:
				'Securely save my information for 1-click checkout',
			PRE_CHECK_SAVE_MY_INFO: true,
		};
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( { success: true } ),
		} );
	} );

		afterEach( () => {
			// Close any OTP iframe left open so its window listeners go away.
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: { action: 'close_modal' },
				} )
			);
			jest.useRealTimers();
			delete global.jQuery;
			delete global.$;
			delete window.jQuery;
			delete window.$;
			delete window.wcpay_core_woopay_config;
			window.localStorage.clear();
			window.fetch = originalFetch;
			document.body.innerHTML = '';
		} );

	test( 'renders a branded WooPay express button and opens the OTP iframe on click', async () => {
		require( '../woopayments-woopay' );

		const button = document.querySelector( '#wcpay-woopay-button button' );
		expect( button ).not.toBeNull();
		expect( button.classList.contains( 'woopay-express-button' ) ).toBe(
			true
		);
		expect( button.getAttribute( 'aria-label' ) ).toBe( 'WooPay' );
		expect( button.getAttribute( 'data-theme' ) ).toBe( 'dark' );
		expect( button.getAttribute( 'data-size' ) ).toBe( 'medium' );
		expect( button.querySelector( '.button-content' ) ).not.toBeNull();
		expect( button.querySelector( 'svg' ) ).not.toBeNull();
		expect(
			document.querySelector( '.woopay-express-button.is-placeholder' )
		).toBeNull();

		button.click();
		await flushPromises();

		// Client 11.1.0 has no stored-session shortcut: with first-party auth
		// off the click always opens the OTP iframe
		// (woopay-express-checkout-button.js:164-201).
		expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
		expect( global.jQuery.post ).not.toHaveBeenCalledWith(
			'/?wc-ajax=wcpay_init_woopay',
			expect.anything()
		);
	} );

	// The button finds the cookie by its exact pair, so an earlier cookie whose value names skip_woopay (an attribution
	// cookie holding WooPay's back-to-store URL) does not hide it.
	test( 'clears the skip_woopay cookie on a WooPay button click', async () => {
		document.cookie = 'landing=%2Fcheckout%3Fskip_woopay%3Dtrue; path=/';
		document.cookie = 'skip_woopay=1; path=/';
		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( document.cookie ).not.toContain( 'skip_woopay=1' );
		document.cookie = 'landing=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
	} );

	test( 'records WooPay express load and click events', async () => {
		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( getTrackingEvents() ).toEqual(
			expect.arrayContaining( [
				{
					name: 'woopay_button_load',
					props: { source: 'checkout' },
				},
				{
					name: 'woopay_button_click',
					props: { source: 'checkout' },
				},
			] )
		);
		getTrackingRequests().forEach( ( [ , options ] ) => {
			expect( options ).toEqual(
				expect.objectContaining( {
					method: 'POST',
					headers: { 'X-WP-Nonce': 'rest-nonce' },
				} )
			);
			expect( options.body.get( 'tracksNonce' ) ).toBe( 'tracks-nonce' );
			expect( options.body.has( 'action' ) ).toBe( false );
		} );
	} );

	// wp_localize_script() turns the server's false into ''.
	test.each( [ false, '' ] )( 'does not record WooPay tracking when shopper tracking is %p', async ( trackingEnabled ) => {
		window.wcpay_core_woopay_config.isShopperTrackingEnabled = trackingEnabled;
		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( getTrackingEvents() ).toEqual( [] );
	} );

	test( 'adds the selected product to the cart before product-page WooPay init', async () => {
		document.body.innerHTML =
			'<form class="cart">' +
			'<input type="hidden" name="product_id" value="123" />' +
			'<input type="number" name="quantity" value="2" />' +
			'<input type="text" name="addon-message" value="Gift" />' +
			'<button type="submit" class="single_add_to_cart_button" name="add-to-cart" value="123">Add to cart</button>' +
			'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
			'</form>';
		window.wcpay_core_woopay_config.addToCartNonce = 'add-to-cart-nonce';
		window.wcpay_core_woopay_config.woopayButton.context = 'product';

		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenNthCalledWith(
			1,
			'/?wc-ajax=wcpay_add_to_cart',
			expect.objectContaining( {
				security: 'add-to-cart-nonce',
				product_id: '123',
				quantity: '2',
				'addon-message': 'Gift',
			} )
		);
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
	} );

	test.each( [
		[
			'submits the product form when an extension rejects the add-to-cart',
			// The store's wc-ajax add_to_cart answer for a failed woocommerce_add_to_cart_validation (HTTP 400).
			{ status: 400, responseJSON: { error: true, submit: true } },
			true,
		],
		[
			'keeps the generic error for another add-to-cart failure',
			// The store's answer for an unknown product (HTTP 404).
			{
				status: 404,
				responseJSON: {
					error: {
						code: 'invalid_product_id',
						message: 'Invalid product ID.',
					},
				},
			},
			false,
		],
		[
			'reads the JSON body when jQuery left it unparsed',
			{
				status: 400,
				responseText: JSON.stringify( { error: true, submit: true } ),
			},
			true,
		],
	] )( '%s', async ( name, jqXHR, submits ) => {
		// Client 11.1.0 woopay-express-checkout-button.js:186-196 submits the product form on res.submit so the extension's
		// notice shows. jQuery hands .fail() the jqXHR, whose responseJSON holds a JSON body
		// (https://api.jquery.com/jQuery.ajax/#jqXHR).
		document.body.innerHTML =
			'<form class="cart">' +
			'<input type="hidden" name="product_id" value="123" />' +
			'<button type="submit" class="single_add_to_cart_button" name="add-to-cart" value="123">Add to cart</button>' +
			'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
			'<div class="wcpay-core-payment-errors" hidden></div>' +
			'</form>';
		window.wcpay_core_woopay_config.addToCartNonce = 'add-to-cart-nonce';
		window.wcpay_core_woopay_config.confirmationErrorMessage =
			'There was a problem processing the payment. Please try again.';
		window.wcpay_core_woopay_config.woopayButton.context = 'product';
		const submit = jest
			.spyOn( window.HTMLFormElement.prototype, 'submit' )
			.mockImplementation( () => {} );
		global.jQuery.post = jest.fn( () => {
			const request = {
				done: jest.fn( () => request ),
				fail: jest.fn( ( callback ) => {
					callback( jqXHR );
					return request;
				} ),
			};
			return request;
		} );

		try {
			require( '../woopayments-woopay' );
			document.querySelector( '#wcpay-woopay-button button' ).click();
			await flushPromises();

			expect( submit ).toHaveBeenCalledTimes( submits ? 1 : 0 );
			expect(
				document.querySelector( '.wcpay-core-payment-errors' )
					.textContent
			).toBe(
				submits
					? ''
					: 'There was a problem processing the payment. Please try again.'
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
		} finally {
			submit.mockRestore();
		}
	} );

	test( 'adds a classic variable product when its button has no value', async () => {
		document.body.innerHTML =
			'<form class="variations_form cart">' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<select name="attribute_pa_color"><option value="blue" selected>Blue</option></select>' +
			'<input type="number" name="quantity" value="2" />' +
			'<button type="submit" class="single_add_to_cart_button" name="add-to-cart">Add to cart</button>' +
			'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
			'</form>';
		window.wcpay_core_woopay_config.addToCartNonce = 'add-to-cart-nonce';
		window.wcpay_core_woopay_config.woopayButton.context = 'product';

		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenNthCalledWith(
			1,
			'/?wc-ajax=wcpay_add_to_cart',
			expect.objectContaining( {
				security: 'add-to-cart-nonce',
				product_id: '257',
				variation_id: '263',
				attribute_pa_color: 'blue',
				quantity: '2',
			} )
		);
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
	} );

		test( 'sends first-party WooPay session data through WooPay Connect before redirecting', async () => {
			const postMessage = jest.fn();
			Object.defineProperty( window.HTMLIFrameElement.prototype, 'contentWindow', {
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			} );
			window.wcpay_core_woopay_config.isWoopayFirstPartyAuthEnabled = true;
			window.wcpay_core_woopay_config.woopayHost = 'https://pay.woo.test';
			global.jQuery.post = jest.fn( ( url, data ) => ( {
				done: jest.fn( ( callback ) => {
					callback(
						url === '/?wc-ajax=wcpay_get_woopay_session'
							? {
									blog_id: '12345',
									data: {
										session: 'session',
										iv: 'iv',
										hash: 'hash',
									},
							  }
								: {
										result: 'success',
								  }
						);

					return {
						fail: jest.fn(),
					};
				} ),
				data,
			} ) );

			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );

			document.querySelector( '#wcpay-woopay-button a' ).click();
			await flushPromises();
			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();

			expect( global.jQuery.post ).toHaveBeenCalledWith(
				'/?wc-ajax=wcpay_get_woopay_session',
				expect.objectContaining( {
					_ajax_nonce: 'session-nonce',
				} )
			);
			expect( postMessage ).toHaveBeenCalledWith(
				{
					action: 'setPreemptiveSessionData',
					value: expect.objectContaining( {
						blog_id: '12345',
					} ),
				},
				'https://pay.woo.test'
			);
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: {
						action: 'set_preemptive_session_data_success',
						value: {
							redirect_url: 'https://pay.woo.test/checkout/session',
						},
					},
				} )
			);
			await flushPromises();

			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/checkout/session'
			);

		} );

		// Client 11.2.0 woopay/connect/woopay-connect-iframe.js:22-39 adds tracksUserIdentity only when
		// getTracksIdentity() resolves one. Hosted WooPay's connect page posts it to its sessions route, which declares
		// it a string (ConnectPage.php:129, SessionController.php:139-143), so a missing identity rejects the session.
		test.each( [
			{
				name: 'carries the tk_ai identity',
				cookie: 'tk_ai=fp1-anon-id; path=/',
				expectedIdentity: JSON.stringify( {
					_ut: 'anon',
					_ui: 'fp1-anon-id',
				} ),
			},
			{
				name: 'leaves the identity out when none resolves',
				cookie: null,
				expectedIdentity: null,
			},
		] )(
			'builds the WooPay Connect iframe URL that $name',
			async ( { cookie, expectedIdentity } ) => {
				if ( cookie ) {
					document.cookie = cookie;
				}
				window.fetch = jest.fn().mockResolvedValue( {
					ok: true,
					json: jest.fn().mockResolvedValue( { success: false } ),
				} );

				try {
					require( '../woopayments-woopay' );
					await flushPromises();

					const src = document
						.getElementById( 'woopay-connect-iframe' )
						.getAttribute( 'src' );
					expect( src ).toEqual(
						expect.stringContaining( 'https://pay.woo.test/connect/?' )
					);
					expect(
						new window.URL( src ).searchParams.get( 'tracksUserIdentity' )
					).toBe( expectedIdentity );
					expect(
						window.fetch.mock.calls.some(
							( [ url, options ] ) =>
								url === 'https://example.test/admin-ajax.php' &&
								options.body.get( 'action' ) === 'get_identity'
						)
					).toBe( ! cookie );
				} finally {
					document.cookie =
						'tk_ai=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC';
				}
			}
		);

		// Browsers fire load for the about:blank document an iframe holds before its src is set; messages sent then are
		// lost.
		test( 'messages WooPay Connect only after the connect page loads', async () => {
			const postMessage = jest.fn();
			Object.defineProperty( window.HTMLIFrameElement.prototype, 'contentWindow', {
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			} );

			require( '../woopayments-woopay' );

			const connectIframe = document.getElementById( 'woopay-connect-iframe' );
			expect( connectIframe.getAttribute( 'src' ) ).toBeNull();
			connectIframe.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();
			expect( connectIframe.getAttribute( 'src' ) ).not.toBeNull();
			expect( postMessage ).not.toHaveBeenCalled();

			connectIframe.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'getPreferredPaymentMethod' },
				'https://pay.woo.test'
			);
		} );

		test( 'falls back to the WooPay OTP flow when first-party Connect rejects the session', async () => {
			const postMessage = jest.fn();
			Object.defineProperty( window.HTMLIFrameElement.prototype, 'contentWindow', {
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			} );
			window.wcpay_core_woopay_config.isWoopayFirstPartyAuthEnabled = true;
			window.wcpay_core_woopay_config.woopayHost = 'https://pay.woo.test';
			global.jQuery.post = jest.fn( ( url ) => ( {
				done: jest.fn( ( callback ) => {
					callback(
						url === '/?wc-ajax=wcpay_get_woopay_session'
							? {
									blog_id: '12345',
									data: {
										session: 'session',
										iv: 'iv',
										hash: 'hash',
									},
							  }
								: {
										result: 'success',
								  }
						);

					return {
						fail: jest.fn(),
					};
				} ),
			} ) );

			require( '../woopayments-woopay' );

			document.querySelector( '#wcpay-woopay-button a' ).click();
			await flushPromises();
			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: {
						action: 'set_preemptive_session_data_error',
					},
				} )
			);
			await flushPromises();

			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();

		} );

		// Client 11.2.0 woopay-express-checkout-button.js:254-337 (onClickFirstPartyAuthFlow): an answer without a
		// session, the 503 included (its fetch reads the JSON error body), shows "Something went wrong. Please try
		// again." and sends the next click to the OTP flow; a request with no answer, or a Connect that does not answer
		// (session-connect.js:152-161 resolves null), shows the message and keeps the first-party flow; hosted WooPay
		// refusing the session opens the OTP flow at once and for the next click.
		describe( 'first-party session failures', () => {
			const sessionError = 'Something went wrong. Please try again.';
			const validSession = {
				blog_id: '12345',
				data: { session: 'session', iv: 'iv', hash: 'hash' },
			};
			let postMessage;

			function mockSessionRequest( answer ) {
				global.jQuery.post = jest.fn( ( url ) => {
					const isSession = url === '/?wc-ajax=wcpay_get_woopay_session';

					return {
						done: jest.fn( ( callback ) => {
							if ( ! isSession ) {
								callback( { result: 'success' } );
							} else if ( 'done' in answer ) {
								callback( answer.done );
							}
							return { fail: jest.fn() };
						} ),
						fail: jest.fn( ( callback ) => {
							if ( isSession && 'fail' in answer ) {
								callback( answer.fail );
							}
						} ),
					};
				} );
			}

			const getSessionPosts = () =>
				global.jQuery.post.mock.calls.filter(
					( [ url ] ) => url === '/?wc-ajax=wcpay_get_woopay_session'
				);
			const getNoticeMessages = () =>
				window.fetch.mock.calls
					.filter(
						( [ url, options ] ) =>
							url === 'https://example.test/admin-ajax.php' &&
							options.body.get( 'action' ) ===
								'woopay_express_checkout_button_show_error_notice'
					)
					.map( ( [ , options ] ) => options.body.get( 'message' ) );
			const getPreemptiveSessionPosts = () =>
				postMessage.mock.calls.filter(
					( [ message ] ) => message.action === 'setPreemptiveSessionData'
				);
			const clickWooPay = () =>
				document.querySelector( '#wcpay-woopay-button a' ).click();
			const sendWooPayMessage = ( data ) =>
				window.dispatchEvent(
					new window.MessageEvent( 'message', {
						origin: 'https://pay.woo.test',
						data,
					} )
				);

			beforeEach( () => {
				postMessage = jest.fn();
				Object.defineProperty( window.HTMLIFrameElement.prototype, 'contentWindow', {
					configurable: true,
					get() {
						return { postMessage };
					},
				} );
				window.wcpay_core_woopay_config.isWoopayFirstPartyAuthEnabled = true;
			} );

			test.each( [
				[
					'the 503 answer without a blog ID',
					{
						fail: {
							status: 503,
							responseJSON: {
								success: false,
								data: 'Could not determine the blog ID.',
							},
						},
					},
				],
				[
					'an answer without the IV and hash',
					{ done: { blog_id: '12345', data: { session: 'session' } } },
				],
			] )(
				'shows the error and opens the OTP flow on the next click after %s',
				async ( _, answer ) => {
					mockSessionRequest( answer );
					require( '../woopayments-woopay' );

					clickWooPay();
					await flushPromises();
					expect( getNoticeMessages() ).toEqual( [ sessionError ] );
					expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
					expect( getPreemptiveSessionPosts() ).toEqual( [] );

					clickWooPay();
					await flushPromises();
					expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
					expect( getSessionPosts() ).toHaveLength( 1 );
				}
			);

			test( 'shows the error and keeps the first-party flow when the session request gets no answer', async () => {
				mockSessionRequest( { fail: { status: 0 } } );
				require( '../woopayments-woopay' );

				clickWooPay();
				await flushPromises();
				expect( getNoticeMessages() ).toEqual( [ sessionError ] );
				expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();

				clickWooPay();
				await flushPromises();
				expect( getSessionPosts() ).toHaveLength( 2 );
				expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			} );

			test( 'opens the OTP flow at once and on the next click when hosted WooPay refuses the session', async () => {
				mockSessionRequest( { done: validSession } );
				require( '../woopayments-woopay' );

				clickWooPay();
				await flushPromises();
				await loadConnectIframe();
				await flushPromises();
				expect( getPreemptiveSessionPosts() ).toHaveLength( 1 );
				sendWooPayMessage( { action: 'set_preemptive_session_data_error' } );
				await flushPromises();
				expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
				sendWooPayMessage( { action: 'close_modal' } );
				await flushPromises();

				clickWooPay();
				await flushPromises();
				expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
				expect( getSessionPosts() ).toHaveLength( 1 );
				expect( getNoticeMessages() ).toEqual( [] );
			} );

			test( 'shows the error and keeps the first-party flow when WooPay Connect does not answer', async () => {
				jest.useFakeTimers();
				mockSessionRequest( { done: validSession } );
				require( '../woopayments-woopay' );

				clickWooPay();
				await flushMicrotasks();
				await loadConnectIframe();
				await flushMicrotasks();
				expect( getPreemptiveSessionPosts() ).toHaveLength( 1 );
				await jest.advanceTimersByTimeAsync( 5000 );
				expect( getNoticeMessages() ).toEqual( [ sessionError ] );
				expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();

				clickWooPay();
				await flushMicrotasks();
				expect( getSessionPosts() ).toHaveLength( 2 );
				// The retry waits for Connect too; let it time out so nothing is left pending for later tests.
				await jest.advanceTimersByTimeAsync( 5000 );
				expect( getNoticeMessages() ).toEqual( [ sessionError, sessionError ] );
				expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			} );
			// A Connect iframe the Blocks script created that never loads: the timeout settles the request (client 11.2.0
			// connect/woopay-connect.js:170-195 starts it before the wait), and the expired request never posts when the
			// connect page loads after a retry has started.
			test.each( [
				[ 'loads late', false ],
				[ 'is removed before it loads', true ],
			] )(
				'never posts an expired session request when the Blocks script’s Connect iframe %s',
				async ( _, removeIframe ) => {
					let connectPageLoaded;
					const blocksIframe = document.createElement( 'iframe' );
					blocksIframe.id = 'woopay-connect-iframe';
					blocksIframe.wooPayConnectPostMessage = new Promise( function ( resolve ) {
						connectPageLoaded = resolve;
					} );
					document.body.appendChild( blocksIframe );
					jest.useFakeTimers();
					mockSessionRequest( { done: validSession } );
					require( '../woopayments-woopay' );

					clickWooPay();
					await flushMicrotasks();
					if ( removeIframe ) {
						blocksIframe.remove();
					}
					await jest.advanceTimersByTimeAsync( 5000 );
					expect( getNoticeMessages() ).toEqual( [ sessionError ] );
					expect( getPreemptiveSessionPosts() ).toEqual( [] );

					clickWooPay();
					await flushMicrotasks();
					expect( getSessionPosts() ).toHaveLength( 2 );
					connectPageLoaded( function ( message ) {
						blocksIframe.contentWindow.postMessage( message, 'https://pay.woo.test' );
					} );
					if ( removeIframe ) {
						await loadConnectIframe();
					}
					await flushMicrotasks();
					expect( getPreemptiveSessionPosts() ).toHaveLength( 1 );

					// Let the retry time out too, so nothing is left pending for later tests.
					await jest.advanceTimersByTimeAsync( 5000 );
					expect( getNoticeMessages() ).toEqual( [ sessionError, sessionError ] );
					expect( getPreemptiveSessionPosts() ).toHaveLength( 1 );
				}
			);
		} );

		test( 'translates the preferred-card accessible name and the brand', () => {
			const rectSpy = jest
				.spyOn( window.HTMLElement.prototype, 'getBoundingClientRect' )
				.mockReturnValue( { width: 220 } );
			window.wp.i18n = createMarkingI18nStub();
			window.localStorage.setItem(
				'woopay_preferred_card',
				JSON.stringify( {
					brand: 'unionpay',
					last4: '4242',
				} )
			);

			require( '../woopayments-woopay' );

			// Client 11.1.0 woopay-express-checkout-button.js:40, :437-441.
			expect(
				document
					.querySelector( '#wcpay-woopay-button button' )
					.getAttribute( 'aria-label' )
			).toBe( '[fr] WooPay with [fr] UnionPay ending in 4242' );
			rectSpy.mockRestore();
		} );

		test( 'renders the WooPay button again after a classic cart refresh replaces it', () => {
			require( '../woopayments-woopay' );
			expect(
				document.querySelector( '#wcpay-woopay-button button' )
			).not.toBeNull();

			// WooCommerce's cart.js replaces the cart markup, the button container included, on updated_cart_totals.
			document.getElementById( 'wcpay-woopay-button' ).innerHTML =
				'<div class="woopay-express-button is-placeholder"></div>';
			bodyEventHandlers.updated_cart_totals();

			expect(
				document.querySelector( '#wcpay-woopay-button button' )
			).not.toBeNull();
		} );

		test( 'renders the cached preferred WooPay card on the express button', () => {
			const rectSpy = jest
				.spyOn( window.HTMLElement.prototype, 'getBoundingClientRect' )
				.mockReturnValue( { width: 220 } );
			window.localStorage.setItem(
				'woopay_preferred_card',
				JSON.stringify( {
					brand: 'visa',
					last4: '4242',
				} )
			);

			require( '../woopayments-woopay' );

			const button = document.querySelector( '#wcpay-woopay-button button' );
			expect( button.getAttribute( 'aria-label' ) ).toBe(
				'WooPay with Visa ending in 4242'
			);
			expect( button.textContent ).toContain( '4242' );
			rectSpy.mockRestore();
		} );

		// Client 11.1.0 only shows the preferred card once the button measures
		// at least 220px wide; below that it falls back to the plain WooPay
		// label (woopay-express-checkout-button.js:32,387-392).
		test( 'hides the cached preferred WooPay card on a narrow express button', () => {
			const rectSpy = jest
				.spyOn( window.HTMLElement.prototype, 'getBoundingClientRect' )
				.mockReturnValue( { width: 219 } );
			window.localStorage.setItem(
				'woopay_preferred_card',
				JSON.stringify( {
					brand: 'visa',
					last4: '4242',
				} )
			);

			require( '../woopayments-woopay' );

			const button = document.querySelector( '#wcpay-woopay-button button' );
			expect( button.getAttribute( 'aria-label' ) ).toBe( 'WooPay' );
			expect( button.textContent ).not.toContain( '4242' );
			rectSpy.mockRestore();
		} );

		test( 'clears the cached preferred WooPay card when Connect does not respond', async () => {
			jest.useFakeTimers();
			const rectSpy = jest
				.spyOn( window.HTMLElement.prototype, 'getBoundingClientRect' )
				.mockReturnValue( { width: 220 } );
			const postMessage = jest.fn();
			Object.defineProperty( window.HTMLIFrameElement.prototype, 'contentWindow', {
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			} );
			window.wcpay_core_woopay_config.woopayHost = 'https://pay.woo.test';
			window.localStorage.setItem(
				'woopay_preferred_card',
				JSON.stringify( {
					brand: 'visa',
					last4: '4242',
				} )
			);

			require( '../woopayments-woopay' );

			expect(
				document
					.querySelector( '#wcpay-woopay-button button' )
					.getAttribute( 'aria-label' )
			).toBe( 'WooPay with Visa ending in 4242' );

			await loadConnectIframe();
			await Promise.resolve();
			jest.advanceTimersByTime( 5000 );
			// The shared Connect client settles the request, then this script stores and renders its answer.
			await Promise.resolve();
			await Promise.resolve();

			expect( postMessage ).toHaveBeenCalledWith(
				{
					action: 'getPreferredPaymentMethod',
				},
				'https://pay.woo.test'
			);
			expect( window.localStorage.getItem( 'woopay_preferred_card' ) ).toBeNull();
			expect(
				document
					.querySelector( '#wcpay-woopay-button button' )
					.getAttribute( 'aria-label' )
			).toBe( 'WooPay' );

			rectSpy.mockRestore();
		} );

		// The Blocks button's preferred-card call, on a Blocks cart with direct checkout on.
		test( 'sends one preferred-card request for this script and the Blocks button', async () => {
			const postMessage = jest.fn();
			Object.defineProperty( window.HTMLIFrameElement.prototype, 'contentWindow', {
				configurable: true,
				get() {
					return { postMessage };
				},
			} );
			window.wcpay_core_woopay_config.woopayHost = 'https://pay.woo.test';
			const blocksCard = window.wc.woopayments.woopay
				.getConnect( { woopayHost: 'https://pay.woo.test' } )
				.fetchPreferredCard();

			require( '../woopayments-woopay' );
			await loadConnectIframe();
			await flushPromises();
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: {
						action: 'get_preferred_payment_method_success',
						value: { brand: 'visa', last4: '4242' },
					},
				} )
			);
			await flushPromises();

			await expect( blocksCard ).resolves.toEqual( {
				brand: 'visa',
				last4: '4242',
			} );
			expect( window.localStorage.getItem( 'woopay_preferred_card' ) ).toContain( '4242' );
			expect(
				postMessage.mock.calls.filter(
					( [ message ] ) => message.action === 'getPreferredPaymentMethod'
				)
			).toHaveLength( 1 );
		} );

		test( 'does not add disabled product forms to the cart before product-page WooPay init', async () => {
				document.body.innerHTML =
					'<form class="cart">' +
					'<input type="hidden" name="product_id" value="123" />' +
					'<button type="submit" class="single_add_to_cart_button disabled ' +
					'wc-variation-selection-needed" name="add-to-cart" value="123">Add to cart</button>' +
					'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
					'<div class="wcpay-core-payment-errors" hidden></div>' +
					'</form>';
			window.wcpay_core_woopay_config.addToCartNonce = 'add-to-cart-nonce';
			window.wcpay_core_woopay_config.confirmationErrorMessage =
				'Choose product options before using WooPay.';
			window.wcpay_core_woopay_config.woopayButton.context = 'product';

			require( '../woopayments-woopay' );

			document.querySelector( '#wcpay-woopay-button button' ).click();
			await flushPromises();

			expect( global.jQuery.post ).not.toHaveBeenCalledWith(
				'/?wc-ajax=wcpay_add_to_cart',
				expect.anything()
			);
			expect(
				document.querySelector( '.wcpay-core-payment-errors' ).textContent
			).toBe( 'Choose product options before using WooPay.' );
		} );

		test( 'renders WooPay save-my-info fields and posts the full number with the order', async () => {
			// Localized by WooPay\SessionController::get_classic_woopay_config(), the checkout page permalink.
			window.wcpay_core_woopay_config.woopaySourceUrl =
				'https://example.test/checkout/';
			window.history.pushState( {}, '', '/checkout/?utm_source=a-long-campaign' );
			// Stand-in for the validation script (phone-validation.js, core's validatePhoneNumber) the section loads on
			// opt-in; core tests the rules themselves.
			window.wcWooPaymentsPhoneValidation = {
				validatePhoneNumber: ( number ) => number === '+12015550123',
			};
			require( '../woopayments-woopay' );

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		const phoneField = document.querySelector(
			'#woopay_user_phone_field_full'
		);

		expect( saveCheckbox ).not.toBeNull();
		expect( saveCheckbox.checked ).toBe( true );
		expect( phoneField ).not.toBeNull();
		expect( saveCheckbox.closest( 'label' ).textContent ).toContain(
			'Securely save my information for 1-click checkout'
		);
		expect(
			document.querySelector(
				'label[for="woopay_user_phone_field_full"]'
			).textContent
		).toBe( 'Mobile phone number' );
		expect(
			document.querySelector( 'input[name="woopay_source_url"]' )
		).not.toBeNull();
		expect(
			document.querySelector( 'input[name="woopay_viewport"]' )
		).not.toBeNull();

		phoneField.value = '+12015550123';
		phoneField.dispatchEvent(
			new window.Event( 'blur', { bubbles: true, cancelable: true } )
		);
		await flushPromises();

		// Client 11.1.0 checkout-page-save-user.js:330-360: the classic opt-in travels with the checkout form.
		expect( getPostedCheckoutFields() ).toMatchObject( {
			save_user_in_woopay: 'true',
			woopay_is_blocks: 'false',
			woopay_source_url: 'https://example.test/checkout/',
			'woopay_user_phone_field[full]': '+12015550123',
		} );
		expect( getSaveUserPosts() ).toEqual( [] );
		window.history.pushState( {}, '', '/' );
		expect( getTrackingEvents() ).toContainEqual( {
			name: 'checkout_woopay_save_my_info_mobile_enter',
			props: {},
		} );
	} );

	// Client 11.2.0 checkout-page-save-user.js:62-63, :364-365: width x height of the document element.
	test( 'fills the WooPay viewport field with the document element size', () => {
		const root = document.documentElement;
		Object.defineProperty( root, 'clientWidth', { configurable: true, value: 1451 } );
		Object.defineProperty( root, 'clientHeight', { configurable: true, value: 993 } );
		const innerWidth = window.innerWidth;
		window.innerWidth = 500;

		try {
			require( '../woopayments-woopay' );

			expect(
				document.querySelector( 'input[name="woopay_viewport"]' ).value
			).toBe( '1451x993' );
		} finally {
			delete root.clientWidth;
			delete root.clientHeight;
			window.innerWidth = innerWidth;
		}
	} );

	test( 'adds a valid IAPI variation with its current named form fields before WooPay init', async () => {
		document.body.innerHTML =
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'<input type="hidden" name="attribute_size" value="large" />' +
			'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
			'</form>';
		window.wcpay_core_woopay_config.addToCartNonce = 'add-to-cart-nonce';
		window.wcpay_core_woopay_config.woopayButton.context = 'product';

		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenNthCalledWith(
			1,
			'/?wc-ajax=wcpay_add_to_cart',
			expect.objectContaining( {
				security: 'add-to-cart-nonce',
				product_id: '257',
				variation_id: '263',
				attribute_pa_color: 'blue',
				attribute_size: 'large',
			} )
		);
	} );

	test( 'leaves the Blocks WooPay button wrapper to the Blocks renderer', () => {
		// The Blocks express button renders its own #wcpay-woopay-button (client
		// 11.1.0 woopay-express-checkout-button.js:469); this script can load on
		// a Blocks cart for direct checkout and must not replace that button.
		document.body.innerHTML =
			'<div id="wcpay-woopay-button" class="wcpay-core-woopay-express">' +
			'<button type="button" class="woopay-express-button" data-width-type="wide">WooPay</button>' +
			'</div>';
		const blocksButton = document.querySelector(
			'#wcpay-woopay-button button'
		);

		require( '../woopayments-woopay' );

		const buttons = document.querySelectorAll(
			'#wcpay-woopay-button button'
		);
		expect( buttons ).toHaveLength( 1 );
		expect( buttons[ 0 ] ).toBe( blocksButton );
	} );

	test( 'shows a failed checkout WooPay session request as the WooPay notice, not in a payment box', async () => {
		// Client 11.2.0 woopay-express-checkout-button.js:324-337 shows a failed session request through the express
		// button's showErrorMessage() (express-button/utils.js:12-50), a store notice, never a payment method's box.
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input id="billing_email" value="shopper@example.com" />' +
			'<div id="wcpay-woopay-button"><div class="woopay-express-button is-placeholder"></div></div>' +
			'<ul class="wc_payment_methods payment_methods methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_klarna">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_klarna" />' +
			'<div class="wcpay-core-payment-errors">Stale Klarna error</div>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div class="wcpay-core-payment-errors" hidden></div>' +
			'</li>' +
			'</ul>' +
			'</form>';
		window.wcpay_core_woopay_config.confirmationErrorMessage =
			'WooPay is unavailable right now.';
		window.wcpay_core_woopay_config.isWoopayFirstPartyAuthEnabled = true;
		global.jQuery.post.mockImplementation( () => ( {
			done: jest.fn( () => ( {
				fail: jest.fn(),
			} ) ),
			fail: jest.fn( ( callback ) => callback() ),
		} ) );

		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button a' ).click();
		await flushPromises();

		const [ klarnaBox, cardBox ] = document.querySelectorAll(
			'.wcpay-core-payment-errors'
		);
		expect(
			window.fetch.mock.calls.some(
				( [ url, options ] ) =>
					url === 'https://example.test/admin-ajax.php' &&
					options.body.get( 'message' ) ===
						'Something went wrong. Please try again.'
			)
		).toBe( true );
		expect( cardBox.textContent ).toBe( '' );
		expect( cardBox.hidden ).toBe( true );
		expect( klarnaBox.textContent ).toBe( 'Stale Klarna error' );
	} );

	test( 'does not add an invalid IAPI product form before WooPay init', async () => {
		document.body.innerHTML =
			'<form class="wp-block-add-to-cart-with-options is-invalid">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="" />' +
			'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
			'<div class="wcpay-core-payment-errors" hidden></div>' +
			'</form>';
		window.wcpay_core_woopay_config.addToCartNonce = 'add-to-cart-nonce';
		window.wcpay_core_woopay_config.confirmationErrorMessage =
			'Choose product options before using WooPay.';
		window.wcpay_core_woopay_config.woopayButton.context = 'product';

		require( '../woopayments-woopay' );

		document.querySelector( '#wcpay-woopay-button button' ).click();
		await flushPromises();

		expect( global.jQuery.post ).not.toHaveBeenCalledWith(
			'/?wc-ajax=wcpay_add_to_cart',
			expect.anything()
		);
		expect(
			document.querySelector( '.wcpay-core-payment-errors' ).textContent
		).toBe( 'Choose product options before using WooPay.' );
	} );

	test( 'shows the WooPay terms and privacy agreement under save my info and records link clicks', () => {
		// Client 11.1.0 client/components/woopay/save-user/agreement.js,
		// rendered in the checked save-details form (checkout-page-save-user.js:402-403).
		window.wcpay_core_woopay_config.woopayAgreementText =
			"By continuing, you agree to WooPay's <termsOfService/> and <privacyPolicy/>.";
		window.wcpay_core_woopay_config.woopayTermsOfServiceLabel =
			'Terms of Service';
		window.wcpay_core_woopay_config.woopayPrivacyPolicyLabel =
			'Privacy Policy';

		require( '../woopayments-woopay' );

		const agreement = document.querySelector(
			'#wcpay-woopay-save-user .tos'
		);
		expect( agreement ).not.toBeNull();
		expect( agreement.textContent ).toBe(
			"By continuing, you agree to WooPay's Terms of Service and Privacy Policy."
		);
		expect( agreement.closest( '[hidden]' ) ).toBeNull();

		const [ termsLink, privacyLink ] = agreement.querySelectorAll( 'a' );
		expect( termsLink.textContent ).toBe( 'Terms of Service' );
		expect( termsLink.getAttribute( 'href' ) ).toBe(
			'https://wordpress.com/tos/'
		);
		expect( privacyLink.textContent ).toBe( 'Privacy Policy' );
		expect( privacyLink.getAttribute( 'href' ) ).toBe(
			'https://automattic.com/privacy/'
		);
		[ termsLink, privacyLink ].forEach( ( link ) => {
			expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
			expect( link.getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
		} );

		termsLink.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true, cancelable: true } )
		);
		privacyLink.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true, cancelable: true } )
		);

		expect( getTrackingEvents() ).toEqual(
			expect.arrayContaining( [
				{ name: 'checkout_save_my_info_tos_click', props: {} },
				{ name: 'checkout_save_my_info_privacy_policy_click', props: {} },
			] )
		);

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);
		expect( agreement.closest( '[hidden]' ) ).not.toBeNull();
	} );

	test( 'shows the WooPay additional-information line under save my info', () => {
		// Client 11.1.0 client/components/woopay/save-user/additional-information.js,
		// rendered directly before the agreement (checkout-page-save-user.js:402-403).
		window.wcpay_core_woopay_config.woopayAdditionalInfoText =
			"Next time you buy here and on other Woo-powered stores, we'll send you a code to securely purchase with WooPay.";
		window.wcpay_core_woopay_config.woopayAgreementText =
			"By continuing, you agree to WooPay's <termsOfService/> and <privacyPolicy/>.";
		window.wcpay_core_woopay_config.woopayTermsOfServiceLabel =
			'Terms of Service';
		window.wcpay_core_woopay_config.woopayPrivacyPolicyLabel =
			'Privacy Policy';

		require( '../woopayments-woopay' );

		const additionalInfo = document.querySelector(
			'#wcpay-woopay-save-user .additional-information'
		);
		expect( additionalInfo ).not.toBeNull();
		expect( additionalInfo.textContent ).toBe(
			"Next time you buy here and on other Woo-powered stores, we'll send you a code to securely purchase with WooPay."
		);
		expect( additionalInfo.closest( '[hidden]' ) ).toBeNull();

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);
		expect( additionalInfo.closest( '[hidden]' ) ).not.toBeNull();
	} );

	const getSaveUserContainer = () =>
		document.getElementById( 'wcpay-woopay-save-user' );

	const getSaveUserPosts = () =>
		global.jQuery.post.mock.calls
			.filter( ( [ url ] ) => url.includes( 'set_woopay_phone_number' ) )
			.map( ( [ , data ] ) => data );

	const getPostedSaveUserFields = () =>
		Object.keys( getPostedCheckoutFields() ).filter( ( key ) =>
			key.includes( 'woopay' )
		);

	test( 'hides WooPay save my info and posts none of its fields while a saved card is chosen', () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<input type="radio" id="wc-woocommerce_payments-payment-token-12" ' +
					'name="wc-woocommerce_payments-payment-token" value="12" checked />' +
					'<input type="radio" id="wc-woocommerce_payments-payment-token-new" ' +
					'name="wc-woocommerce_payments-payment-token" value="new" />'
			);

		require( '../woopayments-woopay' );

		expect( getSaveUserContainer().hidden ).toBe( true );
		expect( getPostedSaveUserFields() ).toEqual( [] );

		const newCard = document.getElementById(
			'wc-woocommerce_payments-payment-token-new'
		);
		newCard.checked = true;
		newCard.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( getSaveUserContainer().hidden ).toBe( false );
	} );

	test( 'hides WooPay save my info for another payment method and posts none of its fields', () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<input type="radio" name="payment_method" value="woocommerce_payments_klarna" />'
			);

		require( '../woopayments-woopay' );
		expect( getPostedSaveUserFields() ).toContain( 'save_user_in_woopay' );

		const klarna = document.querySelector(
			'input[value="woocommerce_payments_klarna"]'
		);
		klarna.checked = true;
		klarna.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( getSaveUserContainer().hidden ).toBe( true );
		expect( getPostedSaveUserFields() ).toEqual( [] );
		// Client 11.1.0 checkout-page-save-user.js:158-160: the classic checkout sends no session request.
		expect( getSaveUserPosts() ).toEqual( [] );
	} );

	test( 'hides WooPay save my info once the email check finds a WooPay user', () => {
		require( '../woopayments-woopay' );

		// Dispatched by the classic WooPay email check (woopayments-checkout.js) for a known WooPay user.
		window.dispatchEvent(
			new window.CustomEvent( 'woopayUserCheck', {
				detail: { isRegisteredUser: true },
			} )
		);

		expect( getSaveUserContainer().hidden ).toBe( true );
	} );

	// Client 11.2.0 checkout/woopay/index.js:23-26: a Blocks checkout on the page owns save my info, so the classic
	// section stays out even when the page content also renders the classic form.
	test.each( [
		[
			'leaves save my info to the Blocks checkout when one is on the page',
			'<div class="wp-block-woocommerce-checkout wc-block-checkout"></div>',
			false,
		],
		[
			'renders save my info in the classic form beside a Cart block',
			'<div class="wp-block-woocommerce-cart wc-block-cart"></div>',
			true,
		],
	] )( '%s', ( name, blockMarkup, rendersClassic ) => {
		document.body.insertAdjacentHTML( 'afterbegin', blockMarkup );

		require( '../woopayments-woopay' );
		bodyEventHandlers.updated_checkout();

		expect( document.querySelectorAll( '#wcpay-woopay-save-user' ) ).toHaveLength(
			rendersClassic ? 1 : 0
		);
		expect(
			document.querySelector( 'input[name="save_user_in_woopay"]' ) !== null
		).toBe( rendersClassic );
	} );

	test( 'posts no WooPay opt-in once the shopper unchecks save my info', () => {
		require( '../woopayments-woopay' );
		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);

		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true } )
		);

		expect( getPostedSaveUserFields() ).not.toContain( 'save_user_in_woopay' );
		expect( getSaveUserPosts() ).toEqual( [] );
	} );

	test( 'shows the WooPay phone error for an invalid number without stopping the order', () => {
		// Stand-in for the validation script (phone-validation.js, core's validatePhoneNumber).
		window.wcWooPaymentsPhoneValidation = {
			validatePhoneNumber: ( number ) => number === '+12015550123',
		};
		require( '../woopayments-woopay' );
		const phoneField = document.getElementById( 'woopay_user_phone_field_full' );
		const error = document.getElementById(
			'validate-error-invalid-woopay-phone-number'
		);

		phoneField.value = '123';
		phoneField.dispatchEvent( new window.Event( 'blur', { bubbles: true } ) );

		// Client 11.1.0 checkout-page-save-user.js:389-398 shows the message under the field.
		expect( error.hidden ).toBe( false );
		expect(
			getSaveUserPosts().some(
				( data ) => data.woopay_user_phone_field.full === '+1123'
			)
		).toBe( false );

		phoneField.value = '(201) 555-0123';
		phoneField.dispatchEvent( new window.Event( 'blur', { bubbles: true } ) );

		expect( error.hidden ).toBe( true );
		expect(
			document.querySelector( 'input[name="woopay_user_phone_field[full]"]' )
				.value
		).toBe( '+12015550123' );
	} );

	test( 'shows the WooPay phone error when the order is placed and lets the order go on', () => {
		// Stand-in for the validation script (phone-validation.js, core's validatePhoneNumber).
		window.wcWooPaymentsPhoneValidation = {
			validatePhoneNumber: ( number ) => number === '+12015550123',
		};
		require( '../woopayments-woopay' );
		document.getElementById( 'woopay_user_phone_field_full' ).value = '123';
		const placeOrder = global.jQuery(
			document.querySelector( 'form.checkout' )
		).on.mock.calls.find( ( [ event ] ) => event === 'checkout_place_order' )[ 1 ];

		// Client 11.1.0 checkout-page-save-user.js:176-181 shows the message and does not cancel the submission.
		expect( placeOrder() ).not.toBe( false );
		expect(
			document.getElementById( 'validate-error-invalid-woopay-phone-number' )
				.hidden
		).toBe( false );
	} );

	test( 'keeps the posted WooPay number current as the shopper types', () => {
		require( '../woopayments-woopay' );
		const phoneField = document.getElementById( 'woopay_user_phone_field_full' );

		phoneField.value = '(201) 555-0123';
		phoneField.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect(
			getPostedCheckoutFields()[ 'woopay_user_phone_field[full]' ]
		).toBe( '+12015550123' );
	} );

	test( 'loads the phone validation script only when the shopper opts in', () => {
		window.wcpay_core_woopay_config.PRE_CHECK_SAVE_MY_INFO = false;
		window.wcpay_core_woopay_config.woopayPhoneValidationScriptUrl =
			'https://example.test/wc-woopayments-phone-validation.js';
		const getScript = () =>
			document.querySelector(
				'script[src="https://example.test/wc-woopayments-phone-validation.js"]'
			);

		require( '../woopayments-woopay' );
		expect( getScript() ).toBeNull();

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		saveCheckbox.checked = true;
		saveCheckbox.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( getScript() ).not.toBeNull();
	} );

	test( 'shows the WooPay phone field only while save my info is checked', () => {
		// Client 11.1.0 checkout-page-save-user.js renders the phone field
		// inside the save-details form, which exists only while checked.
		window.wcpay_core_woopay_config.PRE_CHECK_SAVE_MY_INFO = false;

		require( '../woopayments-woopay' );

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		const phoneField = document.querySelector(
			'#woopay_user_phone_field_full'
		);
		expect( saveCheckbox.checked ).toBe( false );
		expect( phoneField.closest( '[hidden]' ) ).not.toBeNull();

		saveCheckbox.checked = true;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);
		expect( phoneField.closest( '[hidden]' ) ).toBeNull();

		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);
		expect( phoneField.closest( '[hidden]' ) ).not.toBeNull();
	} );

	test( 'keeps a translated agreement string as text, never markup', () => {
		window.wcpay_core_woopay_config.woopayAgreementText =
			'<img src=x onerror=alert(1)> <termsOfService/> <privacyPolicy/>';
		window.wcpay_core_woopay_config.woopayTermsOfServiceLabel =
			'<b>Terms</b>';
		window.wcpay_core_woopay_config.woopayPrivacyPolicyLabel = 'Privacy';

		require( '../woopayments-woopay' );

		const agreement = document.querySelector(
			'#wcpay-woopay-save-user .tos'
		);
		expect( agreement.querySelector( 'img' ) ).toBeNull();
		expect( agreement.querySelector( 'b' ) ).toBeNull();
		expect( agreement.querySelectorAll( 'a' ) ).toHaveLength( 2 );
		expect( agreement.textContent ).toBe(
			'<img src=x onerror=alert(1)> <b>Terms</b> Privacy'
		);
	} );

	test( 'records WooPay save-info checkbox events and leaves the offer to the email check', () => {
		require( '../woopayments-woopay' );

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);

		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);

		// The offer and the pre-checked state are recorded by the WooPay email check in woopayments-checkout.js, as in
		// client 11.1.0 email-input-iframe.js:411-427, not when the fields render.
		expect(
			getTrackingEvents().filter( ( event ) =>
				event.name.includes( 'save_my_info' )
			)
		).toEqual( [
			{
				name: 'checkout_save_my_info_click',
				props: { status: 'unchecked' },
			},
		] );
	} );

	describe( 'direct checkout', () => {
		const encryptedIdentity = {
			data: 'encrypted-identity',
			iv: 'identity-iv',
			hash: 'identity-hash',
		};
		const storeSession = {
			blog_id: '12345',
			data: {
				session: 'store-session',
				iv: 'store-iv',
				hash: 'store-hash',
			},
		};

		function configureDirectCheckout( markup ) {
			document.body.innerHTML = markup;
			Object.assign( window.wcpay_core_woopay_config, {
				isWooPayDirectCheckoutEnabled: true,
				shouldShowWooPayButton: false,
				forceNetworkSavedCards: false,
				woopayHost: 'https://pay.woo.test',
				woopayMinimumSessionData: storeSession,
			} );
		}

		// WooPay Connect iframe: WooPayments 11.1.0 connect/woopay-connect.js:71-81 and :144-151 post each request
		// ( { action } ) to the iframe's contentWindow.postMessage; this stub records them.
		function installConnect() {
			const postMessage = jest.fn();
			Object.defineProperty(
				window.HTMLIFrameElement.prototype,
				'contentWindow',
				{
					configurable: true,
					get: () => ( { postMessage } ),
				}
			);

			return postMessage;
		}

		// WooPay Connect answers: WooPayments 11.1.0 connect/woopay-connect.js:36-48 accepts message events from the
		// woopayHost origin and hands event.data, shaped { action, value }, to callbackFn (session-connect.js:184-216,
		// user-connect.js:72-86).
		function emitConnectMessage( action, value, origin = 'https://pay.woo.test' ) {
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin,
					data: { action, value },
				} )
			);
		}

		async function initializeDirectCheckout( loggedIn ) {
			await loadConnectIframe();
			await flushPromises();
			emitConnectMessage( 'get_is_user_logged_in_success', loggedIn );
			await flushPromises();
		}

		// The cart's direct checkout reaches WooPay through the same Connect iframe, whose connect page posts the store
		// session to hosted WooPay's sessions route with the identity from its URL (client 11.2.0
		// woopay/connect/woopay-connect-iframe.js:22-39, ConnectPage.php:129, SessionController.php:139-143).
		test( 'opens the direct checkout Connect iframe with the Tracks identity', async () => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			document.cookie = 'tk_ai=fp1-anon-id; path=/';

			try {
				require( '../woopayments-woopay' );
				// The whole handshake, so no login answer is left pending for the next test's module.
				await initializeDirectCheckout( true );

				const src = document
					.getElementById( 'woopay-connect-iframe' )
					.getAttribute( 'src' );
				expect(
					new window.URL( src ).searchParams.get( 'tracksUserIdentity' )
				).toBe( JSON.stringify( { _ut: 'anon', _ui: 'fp1-anon-id' } ) );
				expect( postMessage ).toHaveBeenCalledWith(
					{ action: 'getIsUserLoggedIn' },
					'https://pay.woo.test'
				);
			} finally {
				document.cookie =
					'tk_ai=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC';
			}
		} );


		test( 'logged-in cart handoff sends encrypted identity and store session without payment dispatch', async () => {
			// Oracle: WooPayments 11.1.0 direct-checkout/woopay-direct-checkout.js:20,98-108,138-172,433-441.
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			global.jQuery.post = jest.fn( () => ( {
				done: jest.fn( ( callback ) => {
					callback( storeSession );
					return { fail: jest.fn() };
				} ),
			} ) );
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );

			await initializeDirectCheckout( true );
			document.querySelector( '.checkout-button' ).click();
			await flushPromises();
			emitConnectMessage( 'get_encrypted_data_success', encryptedIdentity );
			await flushPromises();

			expect( global.jQuery.post ).toHaveBeenCalledWith(
				'/?wc-ajax=wcpay_get_woopay_session',
				{
					_ajax_nonce: 'session-nonce',
					encrypted_data: encryptedIdentity,
				}
			);
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'setRedirectSessionData', value: storeSession },
				'https://pay.woo.test'
			);

			emitConnectMessage( 'set_redirect_session_data_success', {
				redirect_url:
					'https://pay.woo.test/woopay/?platform_checkout_key=checkout-key',
			} );
			await flushPromises();

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?platform_checkout_key=checkout-key'
			);
			expect(
				global.jQuery.post.mock.calls.some(
					( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
				)
			).toBe( false );
		} );

		test.each( [
			[ 'an empty identity answer', [], true ],
			[ 'no identity answer', null, false ],
		] )( 'logged-in cart handoff posts %s and still opens hosted WooPay', async ( name, answer, sendsIdentity ) => {
			// Hosted WooPay seals the identity only for a verified email, so an unverified shopper's connect page answers
			// []. Client 11.2.0 posts whatever it answered, leaving the field out when the answer is falsy
			// (direct-checkout/woopay-direct-checkout.js:434-442); the store then starts a guest WooPay session
			// (class-woopay-session.php:540).
			configureDirectCheckout(
				'<div class="wp-block-woocommerce-proceed-to-checkout-block"><a href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			global.jQuery.post = jest.fn( () => ( {
				done: jest.fn( ( callback ) => {
					callback( storeSession );
					return { fail: jest.fn() };
				} ),
			} ) );
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );

			await initializeDirectCheckout( true );
			document.querySelector( 'a' ).click();
			await flushPromises();
			emitConnectMessage( 'get_encrypted_data_success', answer );
			await flushPromises();

			const sessionPosts = global.jQuery.post.mock.calls.filter(
				( [ url ] ) => url === '/?wc-ajax=wcpay_get_woopay_session'
			);
			expect( sessionPosts ).toHaveLength( 1 );
			expect( sessionPosts[ 0 ][ 1 ] ).toStrictEqual(
				sendsIdentity
					? { _ajax_nonce: 'session-nonce', encrypted_data: answer }
					: { _ajax_nonce: 'session-nonce' }
			);
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'setRedirectSessionData', value: storeSession },
				'https://pay.woo.test'
			);

			emitConnectMessage( 'set_redirect_session_data_success', {
				redirect_url:
					'https://pay.woo.test/woopay/?platform_checkout_key=checkout-key',
			} );
			await flushPromises();

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?platform_checkout_key=checkout-key'
			);
		} );

		test.each( [
			[
				'classic cart',
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>',
			],
			[
				'Cart Block',
				'<div class="wp-block-woocommerce-proceed-to-checkout-block"><a href="https://store.test/checkout/">Checkout</a></div>',
			],
			[
				'iAPI mini-cart',
				'<a class="wp-block-woocommerce-mini-cart-checkout-button-block" href="https://store.test/checkout/">Checkout</a>',
			],
			[
				'legacy mini-cart',
				'<div class="widget_shopping_cart"><a class="button checkout" href="https://store.test/checkout/">Checkout</a></div>',
			],
		] )( 'intercepts the %s selector once after cart updates', async ( name, markup ) => {
			// Oracle: WooPayments 11.1.0 direct-checkout/woopay-direct-checkout.js:19-29 and direct-checkout/index.js:255-283.
			configureDirectCheckout( markup );
			installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( false );
			bodyEventHandlers.updated_cart_totals();
			bodyEventHandlers.updated_cart_totals();

			document.querySelector( 'a' ).click();
			await flushPromises();
			emitConnectMessage( 'get_is_woopay_reachable_success', false );
			await flushPromises();

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://store.test/checkout/'
			);
		} );

		test( 'runs direct checkout from the light mini-cart config without asking Connect for a preferred card', async () => {
			// Client 11.1.0 gives a mini-cart page only the common config (class-wc-payments.php:1797-1835) and fetches the
			// preferred card only from its express-button bundle (express-button/index.js:115-120).
			// Connect messages: WooPayments 11.1.0 session-connect.js:168-177 sends { action: 'isWooPayReachable' } and :204-205
			// reads the answer { action: 'get_is_woopay_reachable_success', value }; user-connect.js:56-61 sends
			// { action: 'getPreferredPaymentMethod' }, which this page must not send.
			document.body.innerHTML =
				'<div class="widget_shopping_cart"><a class="button checkout" href="https://store.test/checkout/">Checkout</a></div>';
			window.wcpay_core_woopay_config = {
				wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
				woopayHost: 'https://pay.woo.test',
				testMode: true,
				woopaySessionNonce: 'session-nonce',
				woopayMerchantId: '12345',
				isWooPayDirectCheckoutEnabled: true,
				platformTrackerNonce: 'tracks-nonce',
				ajaxUrl: 'https://example.test/admin-ajax.php',
				woopayMinimumSessionData: storeSession,
			};
			const postMessage = installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( false );

			document.querySelector( 'a' ).click();
			await flushPromises();
			emitConnectMessage( 'get_is_woopay_reachable_success', true );
			await flushPromises();

			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?checkout_redirect=1' +
					'&blog_id=12345&session=store-session' +
					'&iv=store-iv&hash=store-hash'
			);
			expect( postMessage ).not.toHaveBeenCalledWith(
				{ action: 'getPreferredPaymentMethod' },
				expect.anything()
			);
		} );

		test( 'not-logged-in flow probes reachability before using the minimum session', async () => {
			// Oracle: WooPayments 11.1.0 session-connect.js:168-177 and direct-checkout/woopay-direct-checkout.js:200-228,398-412.
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( false );

			document.querySelector( 'a' ).click();
			await flushPromises();
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'isWooPayReachable' },
				'https://pay.woo.test'
			);
			emitConnectMessage( 'get_is_woopay_reachable_success', true );
			await flushPromises();

			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?checkout_redirect=1' +
					'&blog_id=12345&session=store-session' +
					'&iv=store-iv&hash=store-hash'
			);
			expect( postMessage ).not.toHaveBeenCalledWith(
				{ action: 'getEncryptedData' },
				expect.anything()
			);
		} );

		test.each( [
			[ 'failed store AJAX', 'ajax' ],
			[ 'malformed store session', 'session' ],
			[ 'redirect on another origin', 'origin' ],
			[ 'redirect without a platform checkout key', 'key' ],
		] )( 'falls back once for %s', async ( name, failure ) => {
			// Oracle: WooPayments 11.1.0 direct-checkout/woopay-direct-checkout.js:148-172,184-191,369-423,433-441,474-487.
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			installConnect();
			const request = {
				done: jest.fn( ( callback ) => {
					if ( failure !== 'ajax' ) {
						callback( failure === 'session' ? { blog_id: '12345' } : storeSession );
					}
					return request;
				} ),
				fail: jest.fn( ( callback ) => {
					if ( failure === 'ajax' ) {
						callback();
					}
					return request;
				} ),
			};
			global.jQuery.post = jest.fn( () => request );
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( true );

			const link = document.querySelector( 'a' );
			link.click();
			await flushPromises();
			emitConnectMessage( 'get_encrypted_data_success', encryptedIdentity );
			await flushPromises();

			if ( [ 'origin', 'key' ].includes( failure ) ) {
				emitConnectMessage( 'set_redirect_session_data_success', {
					redirect_url:
						failure === 'origin'
							? 'https://attacker.test/?platform_checkout_key=key'
							: 'https://pay.woo.test/woopay/',
				} );
				await flushPromises();
			}

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://store.test/checkout/'
			);
			expect( link.hasAttribute( 'aria-disabled' ) ).toBe( false );
		} );

		test.each( [
			[ 'unreachable Connect', true ],
			[ 'Connect timeout', false ],
		] )( 'falls back once when %s', async ( name, responds ) => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( false );
			jest.useFakeTimers();
			document.querySelector( 'a' ).click();
			await Promise.resolve();
			if ( responds ) {
				emitConnectMessage( 'get_is_woopay_reachable_success', false );
			} else {
				jest.advanceTimersByTime( 5000 );
			}
			await flushMicrotasks();

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://store.test/checkout/'
			);
		} );

		test( 'ignores untrusted encrypted identity messages and never logs encrypted data', async () => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			installConnect();
			const consoleSpy = jest.spyOn( console, 'warn' ).mockImplementation();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( true );
			jest.useFakeTimers();
			document.querySelector( 'a' ).click();
			await Promise.resolve();
			emitConnectMessage(
				'get_encrypted_data_success',
				encryptedIdentity,
				'https://attacker.test'
			);
			jest.advanceTimersByTime( 5000 );
			await flushMicrotasks();

			expect( navigate ).toHaveBeenCalledWith(
				'https://store.test/checkout/'
			);
			expect( consoleSpy ).not.toHaveBeenCalled();
			consoleSpy.mockRestore();
		} );

		test( 'leaves the cart checkout link to the browser when direct checkout is off', async () => {
			// A hash href keeps jsdom from attempting a real navigation once the click is left to the browser.
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="#checkout">Checkout</a></div>'
			);
			window.wcpay_core_woopay_config.isWooPayDirectCheckoutEnabled = false;
			const postMessage = installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );

			// The same arming sequence as the enabled flow: Connect ready, a logged-in answer, then a cart refresh. With the
			// flag off nothing creates the Connect iframe, so it is loaded only if it exists.
			const connectIframe = document.getElementById( 'woopay-connect-iframe' );
			if ( connectIframe ) {
				connectIframe.dispatchEvent( new window.Event( 'load' ) );
			}
			await flushPromises();
			emitConnectMessage( 'get_is_user_logged_in_success', true );
			await flushPromises();
			bodyEventHandlers.updated_cart_totals();

			const event = new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} );
			document.querySelector( '.checkout-button' ).dispatchEvent( event );
			await flushPromises();

			expect( event.defaultPrevented ).toBe( false );
			expect( postMessage ).not.toHaveBeenCalledWith(
				{ action: 'getEncryptedData' },
				expect.anything()
			);
			expect( postMessage ).not.toHaveBeenCalledWith(
				{ action: 'isWooPayReachable' },
				expect.anything()
			);
			expect( global.jQuery.post ).not.toHaveBeenCalled();
			expect( navigate ).not.toHaveBeenCalled();
		} );

		test( 'leaves a proceed-to-checkout control without a link to the browser', async () => {
			configureDirectCheckout(
				'<div class="wp-block-woocommerce-proceed-to-checkout-block">Checkout</div>'
			);
			installConnect();
			require( '../woopayments-woopay' );
			await initializeDirectCheckout( false );
			const event = new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} );
			document.body.firstElementChild.dispatchEvent( event );
			expect( event.defaultPrevented ).toBe( false );
		} );

		test( 'intercepts a legacy mini-cart link inserted after initialization without touching other controls', async () => {
			// Oracle: WooPayments 11.1.0 direct-checkout/index.js:71-136 observes mini-cart controls injected after load.
			configureDirectCheckout(
				'<a class="ordinary-link" href="#ordinary">Keep browsing</a>' +
					'<button class="checkout-submit" type="button">Place order</button>'
			);
			const postMessage = installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( false );

			const ordinaryClick = new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} );
			const submitClick = new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} );
			document.querySelector( '.ordinary-link' ).dispatchEvent( ordinaryClick );
			document.querySelector( '.checkout-submit' ).dispatchEvent( submitClick );

			document.body.insertAdjacentHTML(
				'beforeend',
				'<div class="widget_shopping_cart"><a class="button checkout" href="https://store.test/checkout/">Checkout</a></div>'
			);
			document.querySelector( '.widget_shopping_cart .checkout' ).click();
			await flushPromises();
			emitConnectMessage( 'get_is_woopay_reachable_success', false );
			await flushPromises();

			expect( ordinaryClick.defaultPrevented ).toBe( false );
			expect( submitClick.defaultPrevented ).toBe( false );
			// getIsUserLoggedIn, then isWooPayReachable; no preferred-card query without a WooPay button.
			expect( postMessage ).toHaveBeenCalledTimes( 2 );
			expect( postMessage ).toHaveBeenLastCalledWith(
				{ action: 'isWooPayReachable' },
				'https://pay.woo.test'
			);
			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://store.test/checkout/'
			);
		} );

		test( 'uses resolved logged-in state when cart updates while login is pending', async () => {
			// Oracle: WooPayments 11.1.0 direct-checkout/index.js:26-51 resolves login before choosing the click branch.
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			global.jQuery.post = jest.fn( () => ( {
				done: jest.fn( ( callback ) => {
					callback( storeSession );
					return { fail: jest.fn() };
				} ),
			} ) );
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );

			await loadConnectIframe();
			await flushPromises();
			bodyEventHandlers.updated_cart_totals();
			emitConnectMessage( 'get_is_user_logged_in_success', true );
			await flushPromises();

			document.querySelector( '.checkout-button' ).click();
			await flushPromises();
			emitConnectMessage( 'get_encrypted_data_success', encryptedIdentity );
			await flushPromises();
			emitConnectMessage( 'set_redirect_session_data_success', {
				redirect_url:
					'https://pay.woo.test/woopay/?platform_checkout_key=race-key',
			} );
			await flushPromises();

			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'getEncryptedData' },
				'https://pay.woo.test'
			);
			expect( postMessage ).not.toHaveBeenCalledWith(
				{ action: 'isWooPayReachable' },
				expect.anything()
			);
			expect( global.jQuery.post ).toHaveBeenCalledWith(
				'/?wc-ajax=wcpay_get_woopay_session',
				expect.objectContaining( {
					encrypted_data: encryptedIdentity,
				} )
			);
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?platform_checkout_key=race-key'
			);
		} );

		// Client 11.2.0 keeps one injection state for the connect iframe in window.WooPayConnect
		// (connect/connect-utils.js:1-30): a Connect that finds the iframe still injecting waits for its load
		// (connect/woopay-connect.js:67-115, woopay-connect-iframe.js:51-69). Here the iframe carries that wait, as
		// wooPayConnectPostMessage, for both WooPay scripts: on a Blocks cart the Blocks script creates the iframe.
		test( 'asks WooPay for the login state through the Blocks script’s Connect iframe only once its connect page loads', async () => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			let connectPageLoaded;
			const blocksIframe = document.createElement( 'iframe' );
			blocksIframe.id = 'woopay-connect-iframe';
			blocksIframe.wooPayConnectPostMessage = new Promise( function ( resolve ) {
				connectPageLoaded = resolve;
			} );
			document.body.appendChild( blocksIframe );

			require( '../woopayments-woopay' );
			await flushPromises();
			expect( postMessage ).not.toHaveBeenCalled();
			expect( document.querySelectorAll( '#woopay-connect-iframe' ) ).toHaveLength( 1 );

			connectPageLoaded( function ( message ) {
				blocksIframe.contentWindow.postMessage( message, 'https://pay.woo.test' );
			} );
			await flushPromises();
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'getIsUserLoggedIn' },
				'https://pay.woo.test'
			);
			emitConnectMessage( 'get_is_user_logged_in_success', true );
			await flushPromises();
			expect( document.body.wooPayDirectCheckoutAttached ).toBe( true );
		} );

		test( 'gives the Blocks script the wait for the connect page of the Connect iframe it creates', async () => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			let sender = null;

			require( '../woopayments-woopay' );
			const connectIframe = document.getElementById( 'woopay-connect-iframe' );
			connectIframe.wooPayConnectPostMessage.then( function ( postToConnect ) {
				sender = postToConnect;
			} );
			await flushPromises();
			expect( connectIframe.getAttribute( 'src' ) ).not.toBeNull();
			expect( sender ).toBeNull();

			// The whole handshake, so no login answer is left pending for the next test's module.
			await initializeDirectCheckout( true );
			expect( sender ).toEqual( expect.any( Function ) );
			sender( { action: 'getPreferredPaymentMethod' } );
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'getPreferredPaymentMethod' },
				'https://pay.woo.test'
			);
		} );

		// Two direct checkout links can each start a request under one Connect callback name before the first deadline
		// (each link guards only itself). Each request settles on its own timer, as in client 11.2.0
		// (connect/woopay-connect.js:170-195): the first expires at its deadline and never posts; the second waits for
		// its own answer or deadline.
		test( 'settles each of two overlapping direct checkout requests on its own deadline', async () => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout">' +
					'<a class="checkout-button" href="https://store.test/checkout/?from=cart">Checkout</a></div>' +
					'<div class="widget_shopping_cart">' +
					'<a class="button checkout" href="https://store.test/checkout/?from=mini-cart">Checkout</a></div>'
			);
			const postMessage = installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( true );
			const getEncryptedDataPosts = () =>
				postMessage.mock.calls.filter( ( [ message ] ) => message.action === 'getEncryptedData' );

			document.getElementById( 'woopay-connect-iframe' ).remove();
			jest.useFakeTimers();
			const cartLink = document.querySelector( '.checkout-button' );
			const miniCartLink = document.querySelector( '.widget_shopping_cart .checkout' );
			cartLink.click();
			await jest.advanceTimersByTimeAsync( 1000 );
			miniCartLink.click();
			await jest.advanceTimersByTimeAsync( 4000 );

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith( 'https://store.test/checkout/?from=cart' );
			expect( miniCartLink.getAttribute( 'aria-disabled' ) ).toBe( 'true' );

			await loadConnectIframe();
			await flushMicrotasks();
			expect( getEncryptedDataPosts() ).toHaveLength( 1 );

			// The second request is still waiting: its own answer (no identity here) settles it before its deadline, and
			// the store checkout opens for that link.
			emitConnectMessage( 'get_encrypted_data_success', null );
			await flushMicrotasks();
			expect( navigate ).toHaveBeenCalledTimes( 2 );
			expect( navigate ).toHaveBeenLastCalledWith( 'https://store.test/checkout/?from=mini-cart' );

			await jest.advanceTimersByTimeAsync( 5000 );
			expect( navigate ).toHaveBeenCalledTimes( 2 );
			expect( getEncryptedDataPosts() ).toHaveLength( 1 );
		} );

		test( 'falls back when a replacement Connect iframe never loads and ignores its late load', async () => {
			configureDirectCheckout(
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="https://store.test/checkout/">Checkout</a></div>'
			);
			const postMessage = installConnect();
			const { __test__ } = require( '../woopayments-woopay' );
			const navigate = jest.fn();
			__test__.setNavigate( navigate );
			await initializeDirectCheckout( true );

			document.getElementById( 'woopay-connect-iframe' ).remove();
			jest.useFakeTimers();
			const link = document.querySelector( '.checkout-button' );
			link.click();
			await Promise.resolve();
			const replacementIframe = document.getElementById(
				'woopay-connect-iframe'
			);
			const callsBeforeLateLoad = postMessage.mock.calls.length;
			jest.advanceTimersByTime( 5000 );
			await flushMicrotasks();

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://store.test/checkout/'
			);
			expect( link.hasAttribute( 'aria-disabled' ) ).toBe( false );

			replacementIframe.dispatchEvent( new window.Event( 'load' ) );
			await flushMicrotasks();
			expect( postMessage ).toHaveBeenCalledTimes( callsBeforeLateLoad );
			expect( navigate ).toHaveBeenCalledTimes( 1 );
		} );
	} );
	// Client 11.1.0: with first-party auth off, the classic express button
	// (emailSelector #billing_email, client/checkout/woopay/express-button/index.js:59)
	// calls `expressCheckoutIframe( api, context, emailSelector )`
	// (woopay-express-checkout-button.js:164-201), which opens the platform
	// `/otp/` iframe (express-checkout-iframe.js:40-205) and, on the OTP
	// result, posts init_woopay once with the platform user session
	// (express-checkout-iframe.js:213-262, init-woopay.js:15-61).
	describe( 'express OTP iframe', () => {
		const expectedOtpUrl =
			'https://pay.woo.test/otp/?testMode=true&needsHeader=false&wcpayVersion=11.1.0' +
			'&email=shopper%40example.com&is_blocks=false&is_express=true&express_context=checkout' +
			'&source_url=http%3A%2F%2Flocalhost%2F&viewport=0x0' +
			'&tracksUserIdentity=%7B%22_ut%22%3A%22anon%22%2C%22_ui%22%3A%22tk-anon-1%22%7D';
		let postMessage;
		let navigate;

		function getInitCalls() {
			return window.fetch.mock.calls.filter(
				( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
			);
		}

		function sendWooPayMessage( data, origin ) {
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: origin || 'https://pay.woo.test',
					data: data,
				} )
			);
		}

		async function openOtpIframe() {
			const { __test__ } = require( '../woopayments-woopay' );
			__test__.setNavigate( navigate );
			document.querySelector( '#wcpay-woopay-button button' ).click();
			await flushPromises();

			return document.querySelector( '.woopay-otp-iframe' );
		}

		beforeEach( () => {
			postMessage = jest.fn();
			navigate = jest.fn();
			Object.defineProperty(
				window.HTMLIFrameElement.prototype,
				'contentWindow',
				{
					configurable: true,
					get: () => ( { postMessage } ),
				}
			);
			Object.assign( window.wcpay_core_woopay_config, {
				// wp_localize_script serves booleans as '1' and ''; the client
				// sends its JSON boolean, so the query must read testMode=true.
				testMode: '1',
				wcpayVersionNumber: '11.1.0',
				woopayOtpIframeTitle: 'WooPay SMS code verification',
				woopayOtpCloseLabel: 'Close',
				woopayExpressUnavailableMessage:
					'WooPay is unavailable at this time. Sorry for the inconvenience.',
				woopayButtonNonce: 'button-nonce',
			} );
			// Earlier cases navigate to #fragments; source_url is the page URL.
			window.history.replaceState( null, '', '/' );
			document.cookie = 'tk_ai=tk-anon-1; path=/';
			document.body.innerHTML =
				'<div class="woocommerce-notices-wrapper"></div>' +
				document.body.innerHTML;
			document.querySelector( '.woocommerce-notices-wrapper' ).scrollIntoView =
				jest.fn();
		} );

		afterEach( () => {
			sendWooPayMessage( { action: 'close_modal' } );
			document.cookie =
				'tk_ai=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC';
		} );

		test( 'opens the platform OTP iframe with the client query instead of the direct-checkout redirect', async () => {
			const iframe = await openOtpIframe();

			expect( iframe ).not.toBeNull();
			expect( iframe.getAttribute( 'src' ) ).toBe( expectedOtpUrl );
			expect( iframe.title ).toBe( 'WooPay SMS code verification' );
			expect( iframe.classList.contains( 'intrinsic-ignore' ) ).toBe( true );
			const wrapper = iframe.parentElement;
			expect(
				wrapper.classList.contains( 'woopay-otp-iframe-wrapper' )
			).toBe( true );
			expect( wrapper.getAttribute( 'role' ) ).toBe( 'dialog' );
			expect( wrapper.getAttribute( 'aria-modal' ) ).toBe( 'true' );
			expect( wrapper.parentElement ).toBe( document.body );
			expect( navigate ).not.toHaveBeenCalled();
			expect( getInitCalls() ).toHaveLength( 0 );
			expect(
				global.jQuery.post.mock.calls.some( ( [ url ] ) =>
					/init_woopay|get_woopay_minimum_session_data/.test( url )
				)
			).toBe( false );
		} );

		test( 'posts init_woopay once with the OTP user session and follows the returned URL', async () => {
			let resolveInit;
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? new Promise( ( resolve ) => {
							resolveInit = resolve;
					  } )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			sendWooPayMessage(
				{
					action: 'redirect_to_woopay',
					platformCheckoutUserSession: 'forged-session',
				},
				'https://attacker.test'
			);
			expect( getInitCalls() ).toHaveLength( 0 );

			sendWooPayMessage( {
				action: 'otp_email_submitted',
				userEmail: 'otp@example.com',
			} );
			sendWooPayMessage( {
				action: 'redirect_to_platform_checkout',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );

			expect( getInitCalls() ).toHaveLength( 1 );
			const body = getInitCalls()[ 0 ][ 1 ].body;
			expect( body.get( '_wpnonce' ) ).toBe( 'init-nonce' );
			expect( body.get( 'email' ) ).toBe( 'otp@example.com' );
			expect( body.get( 'user_session' ) ).toBe( 'platform-session-1' );

			resolveInit( {
				json: () =>
					Promise.resolve( {
						result: 'success',
						url: 'https://pay.woo.test/woopay/?platform_checkout_key=abc',
					} ),
			} );
			await flushPromises();

			expect( navigate ).toHaveBeenCalledTimes( 1 );
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?platform_checkout_key=abc'
			);
		} );

		test( 'starts init_woopay only for a redirect no other WooPay script has claimed', async () => {
			const redirect = {
				action: 'redirect_to_platform_checkout',
				platformCheckoutUserSession: 'platform-session-1',
			};
			// The email input's listener, registered first from its own script.
			function emailInputScript( e ) {
				e.wcWooPayInitClaimed = true;
			}
			window.addEventListener( 'message', emailInputScript );
			await openOtpIframe();

			sendWooPayMessage( redirect );
			expect( getInitCalls() ).toHaveLength( 0 );

			window.removeEventListener( 'message', emailInputScript );
			let claimed;
			function laterScript( e ) {
				claimed = e.wcWooPayInitClaimed;
			}
			window.addEventListener( 'message', laterScript );
			sendWooPayMessage( redirect );
			window.removeEventListener( 'message', laterScript );

			expect( getInitCalls() ).toHaveLength( 1 );
			expect( claimed ).toBe( true );
		} );

		test( 'shows the WooPay unavailable notice and closes the iframe when init_woopay fails', async () => {
			window.fetch = jest.fn( ( url, options ) => {
				let data = {};
				if ( url === '/?wc-ajax=wcpay_init_woopay' ) {
					data = { result: 'failure' };
				} else if (
					options.body.get( 'action' ) ===
					'woopay_express_checkout_button_show_error_notice'
				) {
					data = {
						success: true,
						data: {
							notice: '<ul class="woocommerce-error"><li>WooPay unavailable</li></ul>',
						},
					};
				}

				return Promise.resolve( { json: () => Promise.resolve( data ) } );
			} );
			await openOtpIframe();

			sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			await flushPromises();

			const noticeCall = window.fetch.mock.calls.find(
				( [ url, options ] ) =>
					url === 'https://example.test/admin-ajax.php' &&
					options.body.get( 'action' ) ===
						'woopay_express_checkout_button_show_error_notice'
			);
			expect( noticeCall ).toBeDefined();
			expect( noticeCall[ 1 ].body.get( '_ajax_nonce' ) ).toBe(
				'button-nonce'
			);
			expect( noticeCall[ 1 ].body.get( 'context' ) ).toBe( 'checkout' );
			expect( noticeCall[ 1 ].body.get( 'message' ) ).toBe(
				'WooPay is unavailable at this time. Sorry for the inconvenience.'
			);
			expect(
				document.querySelector(
					'.woocommerce-notices-wrapper .woocommerce-error'
				).textContent
			).toBe( 'WooPay unavailable' );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( navigate ).not.toHaveBeenCalled();
		} );

		test( 'follows the skip-session-init redirect, sizes the iframe and closes on close_modal', async () => {
			const iframe = await openOtpIframe();

			sendWooPayMessage( { action: 'iframe_height', height: 500 } );
			expect( iframe.style.height ).toBe( '500px' );
			expect( iframe.style.top ).toBe(
				Math.floor( window.innerHeight / 2 - 250 ) + 'px'
			);

			iframe.dispatchEvent( new window.Event( 'load' ) );
			expect( iframe.classList.contains( 'open' ) ).toBe( true );
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'setHeader', value: false },
				'https://pay.woo.test'
			);
			expect( document.body.style.overflow ).toBe( 'hidden' );

			sendWooPayMessage( {
				action: 'redirect_to_woopay_skip_session_init',
				redirectUrl: 'https://pay.woo.test/woopay/?skip=1',
			} );
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?skip=1'
			);

			sendWooPayMessage( { action: 'close_modal' } );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( document.body.style.overflow ).toBe( '' );
		} );

		// Client express-checkout-iframe.js:244-248: a result that arrives
		// after the shopper closed the iframe does nothing.
		test( 'ignores an init_woopay result that arrives after the iframe closed', async () => {
			let resolveInit;
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? new Promise( ( resolve ) => {
							resolveInit = resolve;
					  } )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			expect( getInitCalls() ).toHaveLength( 1 );
			sendWooPayMessage( { action: 'close_modal' } );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();

			resolveInit( {
				json: () =>
					Promise.resolve( {
						result: 'success',
						url: 'https://pay.woo.test/woopay/?platform_checkout_key=abc',
					} ),
			} );
			await flushPromises();

			expect( navigate ).not.toHaveBeenCalled();
			expect(
				window.fetch.mock.calls.some(
					( [ , options ] ) =>
						options &&
						options.body instanceof window.URLSearchParams &&
						options.body.get( 'action' ) ===
							'woopay_express_checkout_button_show_error_notice'
				)
			).toBe( false );
		} );

		// The Blocks WooPay button shows the error in this case; each checkout keeps its own behaviour.
		test( 'shows nothing when init_woopay cannot be reached after the iframe closed', async () => {
			let rejectInit;
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? new Promise( ( resolve, reject ) => {
							rejectInit = reject;
					  } )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			sendWooPayMessage( { action: 'close_modal' } );

			rejectInit( new TypeError( 'Failed to fetch' ) );
			await flushPromises();

			expect(
				window.fetch.mock.calls.some(
					( [ , options ] ) =>
						options &&
						options.body instanceof window.URLSearchParams &&
						options.body.get( 'action' ) ===
							'woopay_express_checkout_button_show_error_notice'
				)
			).toBe( false );
		} );

		// Recorded decision C11: the client has no .catch, so a network
		// failure leaves the iframe open; native shows the client's
		// unavailable notice and closes it, as for a failed result.
		test( 'shows the unavailable notice and closes the iframe when init_woopay cannot be reached', async () => {
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? Promise.reject( new TypeError( 'Failed to fetch' ) )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			await flushPromises();

			const noticeCall = window.fetch.mock.calls.find(
				( [ url, options ] ) =>
					url === 'https://example.test/admin-ajax.php' &&
					options.body.get( 'action' ) ===
						'woopay_express_checkout_button_show_error_notice'
			);
			expect( noticeCall ).toBeDefined();
			expect( noticeCall[ 1 ].body.get( 'message' ) ).toBe(
				'WooPay is unavailable at this time. Sorry for the inconvenience.'
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( navigate ).not.toHaveBeenCalled();
		} );

		// Recorded decision C12: the client throws in new URL() on an
		// unparsable host; native trusts no message at all.
		test( 'trusts no message when the WooPay host cannot be parsed', async () => {
			window.wcpay_core_woopay_config.woopayHost = 'not a url';
			await openOtpIframe();

			[ 'https://attacker.test', 'null' ].forEach( ( origin ) => {
				sendWooPayMessage(
					{
						action: 'redirect_to_woopay',
						platformCheckoutUserSession: 'forged-session',
					},
					origin
				);
				sendWooPayMessage(
					{
						action: 'redirect_to_woopay_skip_session_init',
						redirectUrl: 'https://attacker.test/phish',
					},
					origin
				);
				sendWooPayMessage( { action: 'close_modal' }, origin );
			} );
			await flushPromises();

			expect( getInitCalls() ).toHaveLength( 0 );
			expect( navigate ).not.toHaveBeenCalled();
			expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();

			document.dispatchEvent(
				new window.KeyboardEvent( 'keyup', { key: 'Escape' } )
			);
		} );

		// Client express-checkout-iframe.js:298-302.
		test( 'closes the iframe on Escape and keeps it open on other keys', async () => {
			const iframe = await openOtpIframe();
			iframe.dispatchEvent( new window.Event( 'load' ) );
			expect( document.body.style.overflow ).toBe( 'hidden' );

			document.dispatchEvent(
				new window.KeyboardEvent( 'keyup', { key: 'Enter' } )
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();

			document.dispatchEvent(
				new window.KeyboardEvent( 'keyup', { key: 'Escape' } )
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect(
				document.querySelector( '.woopay-otp-iframe-wrapper' )
			).toBeNull();
			expect( document.body.style.overflow ).toBe( '' );
		} );

		// Client 11.1.0 leaves the dialog unnamed, drops focus on close and lets Tab leave it
		// (express-button/express-checkout-iframe.js:42-43, :150-161); native names it after the iframe, gives
		// focus back to where it was, as the Blocks email-lookup iframe does, and keeps focus inside while it is open.
		describe( 'as a modal dialog', () => {
			async function openFromFocusedButton() {
				const { __test__ } = require( '../woopayments-woopay' );
				__test__.setNavigate( navigate );
				const button = document.querySelector( '#wcpay-woopay-button button' );
				button.focus();
				button.click();
				await flushPromises();

				return button;
			}

			test( 'is named after the iframe and takes focus', async () => {
				await openFromFocusedButton();

				const dialog = document.querySelector( '[role="dialog"]' );
				expect( dialog.getAttribute( 'aria-label' ) ).toBe(
					'WooPay SMS code verification'
				);
				expect( document.activeElement ).toBe(
					dialog.querySelector( '.woopay-otp-iframe' )
				);
			} );

			// WooPay's OTP iframe posts `{ action: 'close_modal' }` from the WooPay origin (client
			// express-button/express-checkout-iframe.js:266).
			test.each( [
				[
					'the close_modal message',
					() => sendWooPayMessage( { action: 'close_modal' } ),
				],
				[
					'Escape',
					() =>
						document.dispatchEvent(
							new window.KeyboardEvent( 'keyup', { key: 'Escape' } )
						),
				],
				[
					'a click on the backdrop',
					() =>
						document.querySelector( '.woopay-otp-iframe-wrapper' ).click(),
				],
			] )(
				'gives focus back to the WooPay button when closed by %s',
				async ( label, close ) => {
					const button = await openFromFocusedButton();

					close();

					expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
					expect( document.activeElement ).toBe( button );
				}
			);

			// The same re-render while the dialog is closed (the checkout's first update_checkout can answer right after the
			// shopper pressed the button): focus on the old button moves to the new one, and focus elsewhere stays.
			test.each( [
				[ 'the WooPay button', '#wcpay-woopay-button button', true ],
				[ 'the email field', '#billing_email', false ],
			] )(
				'keeps focus where it was when updated_checkout renders the WooPay button again while %s has focus',
				( label, selector, movesToNewButton ) => {
					const { __test__ } = require( '../woopayments-woopay' );
					__test__.setNavigate( navigate );
					const focused = document.querySelector( selector );
					focused.focus();

					bodyEventHandlers.updated_checkout();

					const replacement = document.querySelector(
						'#wcpay-woopay-button button'
					);
					expect( focused.isConnected ).toBe( ! movesToNewButton );
					expect( document.activeElement ).toBe(
						movesToNewButton ? replacement : focused
					);
				}
			);

			// updated_checkout renders the WooPay button again (renderWooPayExpressButton() empties its container), so the
			// button that opened the dialog can be gone by the time the dialog closes.
			test.each( [
				[
					// The classic suite has no user-event: Enter or Space on a focused button makes the browser click it.
					'its Close button',
					() => document.querySelector( '.woopay-otp-iframe-close' ).click(),
				],
				[
					'the close_modal message',
					() => sendWooPayMessage( { action: 'close_modal' } ),
				],
			] )(
				'gives focus to the WooPay button updated_checkout put in place while it was open, when closed by %s',
				async ( label, close ) => {
					const opener = await openFromFocusedButton();

					bodyEventHandlers.updated_checkout();
					const replacement = document.querySelector(
						'#wcpay-woopay-button button'
					);
					expect( replacement ).not.toBe( opener );
					expect( opener.isConnected ).toBe( false );

					close();

					expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
					expect( document.activeElement ).toBe( replacement );
				}
			);

			// The iframe here never fires `load` and never posts a message: the state of an OTP page that failed to load,
			// where WooPay's own close control and Escape bridge do not exist.
			describe( 'when the iframe never loads', () => {
				// The browser's sequential navigation out of an iframe whose document has no control: the next element a
				// Tab reaches (tabIndex 0 or more, not disabled) in document order, or the first one on the page after the
				// last. jsdom has no cross-document Tab.
				function tabOutOf( element ) {
					const tabbable = Array.from(
						document.querySelectorAll( 'button, input, iframe' )
					).filter(
						( candidate ) =>
							candidate === element ||
							( candidate.tabIndex >= 0 && ! candidate.disabled )
					);
					const next =
						tabbable[ tabbable.indexOf( element ) + 1 ] || tabbable[ 0 ];
					next.focus();
				}

				function getCloseButton() {
					return Array.from(
						document.querySelectorAll( '[role="dialog"] button' )
					).find( ( button ) => button.textContent === 'Close' );
				}

				test( 'lets the shopper Tab to a Close button, and Tab past it comes back to the iframe', async () => {
					await openFromFocusedButton();
					const iframe = document.querySelector( '.woopay-otp-iframe' );

					tabOutOf( iframe );
					const close = getCloseButton();
					expect( close ).toBeDefined();
					expect( close.type ).toBe( 'button' );
					expect( document.activeElement ).toBe( close );
					// Reachable with Tab, right after the iframe.
					expect( close.tabIndex ).toBeGreaterThanOrEqual( 0 );
					expect( iframe.nextElementSibling ).toBe( close );

					tabOutOf( close );
					expect( document.activeElement ).toBe( iframe );
				} );

				// The classic suite has no user-event: Enter or Space on a focused button makes the browser click it.
				test.each( [
					[ 'activated', ( close ) => close.click() ],
					[
						'given Escape',
						( close ) =>
							close.dispatchEvent(
								new window.KeyboardEvent( 'keyup', {
									key: 'Escape',
									bubbles: true,
								} )
							),
					],
				] )(
					'closes when the Close button is %s and gives focus back to the WooPay button',
					async ( label, press ) => {
						const button = await openFromFocusedButton();
						tabOutOf( document.querySelector( '.woopay-otp-iframe' ) );
						const close = getCloseButton();
						expect( document.activeElement ).toBe( close );

						press( close );

						expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
						expect( document.activeElement ).toBe( button );
					}
				);
			} );

			test( 'sends focus that leaves the dialog back to the iframe while it is open', async () => {
				const button = await openFromFocusedButton();
				const iframe = document.querySelector( '.woopay-otp-iframe' );
				const email = document.getElementById( 'billing_email' );

				email.focus();
				expect( document.activeElement ).toBe( iframe );

				sendWooPayMessage( { action: 'close_modal' } );
				expect( document.activeElement ).toBe( button );
				email.focus();
				expect( document.activeElement ).toBe( email );
			} );
		} );

		// Client express-checkout-iframe.js:291-296: Safari restores the
		// page from the back-forward cache with the iframe still open.
		test( 'closes the iframe when the page is restored from the back-forward cache', async () => {
			await openOtpIframe();

			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', { persisted: false } )
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();

			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', { persisted: true } )
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
		} );
	} );

	// Client 11.1.0 pays an order from its pay page by posting the order, its key and the billing email with every WooPay
	// session request (express-checkout-iframe.js:117-125, init-woopay.js:54-63, woopay-express-checkout-button.js:298-304)
	// and appending them to the WooPay URL it navigates to (woopay/utils.js:48-63).
	describe( 'order-pay', () => {
		const payForOrderParams =
			'pay_for_order=true&order_id=5707&key=wc_order_abc&billing_email=owner%40example.com';
		let navigate;
		let postMessage;

		function sendWooPayMessage( data ) {
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: data,
				} )
			);
		}

		function getAjaxCalls( endpoint ) {
			return window.fetch.mock.calls.filter(
				( [ url ] ) => url === '/?wc-ajax=wcpay_' + endpoint
			);
		}

		beforeEach( () => {
			navigate = jest.fn();
			postMessage = jest.fn();
			Object.defineProperty(
				window.HTMLIFrameElement.prototype,
				'contentWindow',
				{
					configurable: true,
					get: () => ( { postMessage } ),
				}
			);
			// The order-pay form has no billing email field.
			document.body.innerHTML =
				'<form id="order_review">' +
				'<div id="wcpay-woopay-button"><div class="woopay-express-button is-placeholder"></div></div>' +
				'</form>';
			// wp_localize_script serves top-level scalars as strings.
			Object.assign( window.wcpay_core_woopay_config, {
				pay_for_order: 'true',
				order_id: '5707',
				key: 'wc_order_abc',
				billing_email: 'owner@example.com',
				woopaySessionEmail: 'session@example.com',
				testMode: '1',
				wcpayVersionNumber: '11.1.0',
			} );
			window.wcpay_core_woopay_config.woopayButton.context =
				'pay_for_order';
			window.history.replaceState( null, '', '/' );
		} );

		afterEach( () => {
			sendWooPayMessage( { action: 'close_modal' } );
		} );

		async function openOtpIframe() {
			const { __test__ } = require( '../woopayments-woopay' );
			__test__.setNavigate( navigate );
			document.querySelector( '#wcpay-woopay-button button' ).click();
			await flushPromises();

			return document.querySelector( '.woopay-otp-iframe' );
		}

		test( 'opens the OTP iframe with the billing email the page gives the visitor', async () => {
			const iframe = await openOtpIframe();
			const query = new window.URL( iframe.getAttribute( 'src' ) )
				.searchParams;

			expect( query.get( 'email' ) ).toBe( 'owner@example.com' );
			expect( query.get( 'express_context' ) ).toBe( 'pay_for_order' );
		} );

		test( 'starts WooPay for the order and sends WooPay to that order', async () => {
			// The init_woopay answer is WooPay's init response passed through: { result, url }
			// (client class-woopay-session.php:696-716, read at express-checkout-iframe.js:249-252).
			window.fetch = jest.fn( ( url ) =>
				Promise.resolve( {
					json: () =>
						Promise.resolve(
							url === '/?wc-ajax=wcpay_init_woopay'
								? {
										result: 'success',
										url: 'https://pay.woo.test/woopay/?platform_checkout_key=abc',
								  }
								: {}
						),
				} )
			);
			await openOtpIframe();

			sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			await flushPromises();

			const body = getAjaxCalls( 'init_woopay' )[ 0 ][ 1 ].body;
			expect( body.get( 'order_id' ) ).toBe( '5707' );
			expect( body.get( 'key' ) ).toBe( 'wc_order_abc' );
			expect( body.get( 'billing_email' ) ).toBe( 'owner@example.com' );
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?platform_checkout_key=abc&' +
					payForOrderParams
			);
		} );

		test( 'adds the order to the skip-session-init redirect', async () => {
			await openOtpIframe();

			// Client express-checkout-iframe.js:225-230: { action, redirectUrl }.
			sendWooPayMessage( {
				action: 'redirect_to_woopay_skip_session_init',
				redirectUrl: 'https://pay.woo.test/woopay/?skip=1',
			} );

			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?skip=1&' + payForOrderParams
			);
		} );

		test( 'sends the order with the first-party session and to the WooPay redirect', async () => {
			window.wcpay_core_woopay_config.isWoopayFirstPartyAuthEnabled = true;
			// get_woopay_session answers with the encrypted session: { blog_id, data: { session, iv, hash } }
			// (client includes/woopay/class-woopay-utilities.php:308-335, encrypt_and_sign_data(); read at
			// woopay-express-checkout-button.js:306-308).
			global.jQuery.post = jest.fn( () => ( {
				done: jest.fn( ( callback ) => {
					callback( {
						blog_id: '12345',
						data: { session: 'session', iv: 'iv', hash: 'hash' },
					} );

					return { fail: jest.fn() };
				} ),
			} ) );
			const { __test__ } = require( '../woopayments-woopay' );
			__test__.setNavigate( navigate );

			document.querySelector( '#wcpay-woopay-button a' ).click();
			await flushPromises();
			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();
			// WooPay Connect answers { action, value } (client connect/session-connect.js:207-208); the value's
			// redirect_url is what the client follows (woopay-express-checkout-button.js:321).
			sendWooPayMessage( {
				action: 'set_preemptive_session_data_success',
				value: { redirect_url: 'https://pay.woo.test/checkout/session' },
			} );
			await flushPromises();

			expect( global.jQuery.post ).toHaveBeenCalledWith(
				'/?wc-ajax=wcpay_get_woopay_session',
				expect.objectContaining( {
					order_id: '5707',
					key: 'wc_order_abc',
					billing_email: 'owner@example.com',
				} )
			);
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/checkout/session?' + payForOrderParams
			);
		} );

		test( 'sends the order with the OTP iframe session after the first-party session falls back', async () => {
			window.wcpay_core_woopay_config.isWoopayFirstPartyAuthEnabled = true;
			// get_woopay_session answers { blog_id, data: { session, iv, hash } } (client
			// includes/woopay/class-woopay-utilities.php:308-335, encrypt_and_sign_data()).
			global.jQuery.post = jest.fn( () => ( {
				done: jest.fn( ( callback ) => {
					callback( {
						blog_id: '12345',
						data: { session: 'session', iv: 'iv', hash: 'hash' },
					} );

					return { fail: jest.fn() };
				} ),
			} ) );
			const { __test__ } = require( '../woopayments-woopay' );
			__test__.setNavigate( navigate );

			document.querySelector( '#wcpay-woopay-button a' ).click();
			await flushPromises();
			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();
			// WooPay Connect refuses the session with { action } alone (client connect/session-connect.js:209-213);
			// the client then opens the OTP iframe (woopay-express-checkout-button.js:312-319).
			sendWooPayMessage( { action: 'set_preemptive_session_data_error' } );
			await flushPromises();
			// The iframe's own get_woopay_session request gets the encrypted session too:
			// { blog_id, data: { session, iv, hash } } (client includes/woopay/class-woopay-utilities.php:308-335,
			// encrypt_and_sign_data()).
			const otpSession = {
				blog_id: '12345',
				data: { session: 'otp-session', iv: 'otp-iv', hash: 'otp-hash' },
			};
			window.fetch = jest.fn( ( url ) =>
				Promise.resolve( {
					json: () =>
						Promise.resolve(
							url === '/?wc-ajax=wcpay_get_woopay_session'
								? otpSession
								: { success: true }
						),
				} )
			);
			const otpIframe = document.querySelector( '.woopay-otp-iframe' );
			const otpPostMessage = jest.fn();
			Object.defineProperty( otpIframe, 'contentWindow', {
				configurable: true,
				value: { postMessage: otpPostMessage },
			} );
			otpIframe.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();

			// Client express-checkout-iframe.js:117-125 posts the order with the iframe's session request.
			const calls = getAjaxCalls( 'get_woopay_session' );
			expect( calls ).toHaveLength( 1 );
			const body = calls[ 0 ][ 1 ].body;
			expect( body.get( 'order_id' ) ).toBe( '5707' );
			expect( body.get( 'key' ) ).toBe( 'wc_order_abc' );
			expect( body.get( 'billing_email' ) ).toBe( 'owner@example.com' );
			// Client express-checkout-iframe.js:125-132 hands the session to the OTP iframe, for the WooPay host only.
			expect(
				otpPostMessage.mock.calls.filter(
					( [ message ] ) => message.action === 'setSessionData'
				)
			).toEqual( [
				[
					{ action: 'setSessionData', value: otpSession },
					'https://pay.woo.test',
				],
			] );
			expect( navigate ).not.toHaveBeenCalled();
		} );

		test( 'leaves the WooPay URL alone when the page carries no order key', async () => {
			delete window.wcpay_core_woopay_config.key;
			await openOtpIframe();

			sendWooPayMessage( {
				action: 'redirect_to_woopay_skip_session_init',
				redirectUrl: 'https://pay.woo.test/woopay/?skip=1',
			} );

			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?skip=1'
			);
		} );
	} );

	// The footer Tracks script records this click on every cart (woopayments-frontend-tracks.js), so WooPay must not record it too.
	test( 'leaves the cart Proceed to checkout click to the footer Tracks script', () => {
		document.body.innerHTML =
			'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="#checkout">Checkout</a></div>';
		window.wcpay_core_woopay_config.woopayButton.context = 'cart';
		window.wcpay_core_woopay_config.shouldShowWooPayButton = false;
		require( '../woopayments-woopay' );

		document.querySelector( '.checkout-button' ).click();

		expect( getTrackingEvents() ).toEqual( [] );
	} );
} );
