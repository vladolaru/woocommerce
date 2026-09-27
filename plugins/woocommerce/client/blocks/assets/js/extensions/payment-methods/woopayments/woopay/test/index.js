/* global globalThis */
/**
 * External dependencies
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { createElement } from '@wordpress/element';
import { registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { dispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import registerWooPay, { __test__ } from '../index';

jest.mock( '@woocommerce/blocks-registry', () => ( {
	registerExpressPaymentMethod: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	dispatch: jest.fn(),
} ) );

jest.mock( '@woocommerce/settings', () => {
	globalThis.__wooPayPaymentMethodSettings = {
		gatewayId: 'woocommerce_payments',
		forceNetworkSavedCards: true,
		initWooPayNonce: 'init-nonce',
		isCoreNativeCheckoutAvailable: true,
		isWoopayFirstPartyAuthEnabled: false,
		isWooPayEnabled: true,
		shouldShowWooPayButton: true,
		supports: [ 'products', 'subscriptions' ],
		platformTrackerNonce: 'tracks-nonce',
		isShopperTrackingEnabled: true,
		ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
		wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
		woopayButton: {
			type: 'default',
			theme: 'dark',
			height: '48',
			radius: '4',
			size: 'default',
			context: 'checkout',
		},
		woopayAppearance: {
			theme: 'stripe',
			labels: 'floating',
		},
		woopayFontRules: [
			{
				cssSrc: 'https://fonts.wp.com/font.css',
				family: 'Inter',
			},
		],
		woopayHost: 'https://pay.woo.test',
		testMode: true,
		wcpayVersionNumber: '11.1.0',
		woopayOtpIframeTitle: 'WooPay SMS code verification',
		woopayExpressUnavailableMessage:
			'WooPay is unavailable at this time. Sorry for the inconvenience.',
		woopaySessionNonce: 'session-nonce',
		woopayPhoneLabel: 'WooPay phone number',
		woopaySaveUserLabel: 'Save to WooPay',
		PRE_CHECK_SAVE_MY_INFO: true,
	};

	return {
		getPaymentMethodData: jest.fn(
			() => globalThis.__wooPayPaymentMethodSettings
		),
	};
} );

const getMockPaymentMethodSettings = () =>
	globalThis.__wooPayPaymentMethodSettings;

const originalFetch = window.fetch;
let navigate;

describe( 'wc-payment-method-woopayments-woopay', () => {
	afterEach( () => {
		// Close any OTP iframe left open so its window listeners go away.
		window.dispatchEvent(
			new window.MessageEvent( 'message', {
				origin: 'https://pay.woo.test',
				data: { action: 'close_modal' },
			} )
		);
		jest.useRealTimers();
		window.fetch = originalFetch;
		window.localStorage.clear();
		document.body.innerHTML = '';
		Object.assign( getMockPaymentMethodSettings(), {
			isWoopayFirstPartyAuthEnabled: false,
			isWooPayGlobalThemeSupportEnabled: false,
			shouldShowWooPayButton: true,
			stylesCacheVersion: undefined,
			woopayHost: 'https://pay.woo.test',
			woopayAppearance: {
				theme: 'stripe',
				labels: 'floating',
			},
			woopayButton: {
				...getMockPaymentMethodSettings().woopayButton,
				context: 'checkout',
			},
		} );
		jest.clearAllMocks();
	} );

	beforeEach( () => {
		navigate = jest.fn();
		__test__.setNavigate( navigate );
	} );

	it( 'registers a branded WooPay express button and opens the OTP iframe on click', async () => {
		document.body.innerHTML =
			'<input id="email" value="shopper@example.com" />';
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( {
				result: 'success',
				url: 'https://pay.woo.test/session',
			} ),
		} );

		registerWooPay();

		expect( registerExpressPaymentMethod ).toHaveBeenCalledWith(
			expect.objectContaining( {
				name: 'woopay',
				ariaLabel: 'WooPay',
				supports: {
					features: [ 'products', 'subscriptions' ],
				},
			} )
		);

		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		const button = screen.getByRole( 'button', {
			name: 'WooPay',
		} );
		expect( button ).toHaveClass( 'woopay-express-button' );
		expect( button ).toHaveAttribute( 'data-theme', 'dark' );
		expect( button ).toHaveAttribute( 'data-size', 'medium' );
		expect( button.querySelector( '.button-content' ) ).not.toBeNull();
		expect( button.querySelector( 'svg' ) ).not.toBeNull();

		fireEvent.click( button );

		// Client 11.1.0 has no stored-session shortcut: with first-party auth
		// off the click always opens the OTP iframe
		// (woopay-express-checkout-button.js:164-201).
		await waitFor( () => {
			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();
		} );
		expect(
			window.fetch.mock.calls.some(
				( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
			)
		).toBe( false );
	} );

	// Client 11.1.0 gates the identical registration call the same way:
	// `if ( getUPEConfig( 'isWooPayEnabled' ) ) { … if ( getUPEConfig( 'shouldShowWooPayButton' ) ) { registerExpressPaymentMethod(...) } }`
	// (client/checkout/blocks/index.js:123-134).
	it( 'does not register the WooPay express method when shouldShowWooPayButton is false', () => {
		getMockPaymentMethodSettings().shouldShowWooPayButton = false;

		registerWooPay();

		expect( registerExpressPaymentMethod ).not.toHaveBeenCalled();
	} );

	it( 'enriches the first express payload while another gateway is selected', async () => {
		const paymentSettings = getMockPaymentMethodSettings();
		Object.assign( paymentSettings, {
			isWooPayGlobalThemeSupportEnabled: true,
			stylesCacheVersion: 'appearance-extractor-v4',
			woopayAppearance: null,
		} );
		document.body.innerHTML =
			'<form class="wc-block-checkout wc-block-checkout__form">' +
			'<input type="radio" name="payment-method" checked value="other-gateway" />' +
			'<div class="wc-block-checkout__contact-fields">' +
			'<p>Checkout <a href="#checkout">link</a></p>' +
			'<div class="wc-block-components-text-input">' +
			'<input type="email" id="email" value="shopper@example.com" />' +
			'<label for="email">Email</label>' +
			'</div></div>' +
			'<div id="payment-method"></div></form>' +
			'<footer><a href="#footer">Footer link</a></footer>';
		expect(
			document.querySelector( '#wcpay-core-blocks-payment-element' )
		).toBeNull();
		jest.spyOn( window, 'getComputedStyle' ).mockImplementation(
			( element ) => {
				const isCheckoutLink = element.matches(
					'.wc-block-checkout a'
				);
				const isFooterLink = element.matches( 'footer a' );
				let color = 'rgb(10, 20, 30)';

				if ( isCheckoutLink ) {
					color = 'rgb(1, 2, 3)';
				} else if ( isFooterLink ) {
					color = 'rgb(4, 5, 6)';
				}

				const style = {
					color,
					'font-family': isCheckoutLink
						? 'Checkout Font'
						: 'Base Font',
					'font-size': '16px',
					'font-weight': '400',
					'font-style': 'normal',
					'text-decoration': 'none',
					'letter-spacing': 'normal',
					'line-height': 'normal',
					'box-shadow': 'none',
				};

				return {
					backgroundColor: 'rgb(255, 255, 255)',
					getPropertyValue: ( property ) => style[ property ] || '',
				};
			}
		);
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( {
				result: 'success',
				url: 'https://pay.woo.test/session',
			} ),
		} );

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];
		render( createElement( expressRegistration.content.type ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'WooPay' } ) );
		await waitFor( () => {
			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();
		} );
		await act( async () => {
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: {
						action: 'redirect_to_woopay',
						platformCheckoutUserSession: 'platform-session-1',
					},
				} )
			);
		} );

		await waitFor( () => {
			const initRequest = window.fetch.mock.calls.find(
				( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
			);
			const appearance = JSON.parse(
				initRequest[ 1 ].body.get( 'appearance' )
			);
			expect( appearance.rules[ '.Link' ] ).toEqual(
				expect.objectContaining( {
					color: 'rgb(1, 2, 3)',
					fontFamily: 'Checkout Font',
				} )
			);
			expect( appearance.rules[ '.Footer-link' ] ).toEqual( {
				color: 'rgb(4, 5, 6)',
			} );
		} );
	} );

	it( 'records WooPay express load and click events', async () => {
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( {
				result: 'success',
				url: 'https://pay.woo.test/session',
			} ),
		} );

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		await waitFor( () => {
			expect(
				window.fetch.mock.calls.some(
					( [ url, options ] ) =>
						url ===
							'https://example.test/wp-admin/admin-ajax.php' &&
						options.body.get( 'tracksEventName' ) ===
							'woopay_button_load'
				)
			).toBe( true );
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'WooPay' } ) );

		await waitFor( () => {
			const events = window.fetch.mock.calls
				.filter(
					( [ url, options ] ) =>
						url ===
							'https://example.test/wp-admin/admin-ajax.php' &&
						options.body.get( 'action' ) === 'platform_tracks'
				)
				.map( ( [ , options ] ) => ( {
					name: options.body.get( 'tracksEventName' ),
					props: JSON.parse( options.body.get( 'tracksEventProp' ) ),
				} ) );

			expect( events ).toEqual(
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
		} );
	} );

	it( 'does not render card save-user controls in the express button surface', () => {
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( {
				result: 'success',
			} ),
		} );

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		expect( screen.queryByLabelText( 'WooPay phone number' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Save to WooPay' ) ).toBeNull();
		expect(
			document.querySelector( '.wcpay-core-woopay-save-user' )
		).toBeNull();
		expect(
			window.fetch.mock.calls.some(
				( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
			)
		).toBe( false );
	} );

	it( 'sends first-party WooPay session data through WooPay Connect before redirecting', async () => {
		const postMessage = jest.fn();
		Object.defineProperty(
			window.HTMLIFrameElement.prototype,
			'contentWindow',
			{
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			}
		);
		getMockPaymentMethodSettings().isWoopayFirstPartyAuthEnabled = true;
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( {
				blog_id: '12345',
				data: {
					session: 'session',
					iv: 'iv',
					hash: 'hash',
				},
			} ),
		} );

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		fireEvent.click( screen.getByRole( 'link', { name: 'WooPay' } ) );
		await waitFor( () => {
			expect(
				window.fetch.mock.calls.some(
					( [ url ] ) => url === '/?wc-ajax=wcpay_get_woopay_session'
				)
			).toBe( true );
		} );
		await waitFor( () => {
			expect(
				document.getElementById( 'woopay-connect-iframe' )
			).not.toBeNull();
		} );
		document
			.getElementById( 'woopay-connect-iframe' )
			.dispatchEvent( new window.Event( 'load' ) );

		await waitFor( () => {
			expect( postMessage ).toHaveBeenCalledWith(
				{
					action: 'setPreemptiveSessionData',
					value: expect.objectContaining( {
						blog_id: '12345',
					} ),
				},
				'https://pay.woo.test'
			);
		} );
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
		await waitFor( () => {
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/checkout/session'
			);
		} );

		delete window.HTMLIFrameElement.prototype.contentWindow;
	} );

	it( 'falls back to the WooPay OTP flow when first-party Connect rejects the session', async () => {
		const postMessage = jest.fn();
		Object.defineProperty(
			window.HTMLIFrameElement.prototype,
			'contentWindow',
			{
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			}
		);
		getMockPaymentMethodSettings().isWoopayFirstPartyAuthEnabled = true;
		window.fetch = jest.fn().mockImplementation( ( url ) =>
			Promise.resolve( {
				json: jest.fn().mockResolvedValue(
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
				),
			} )
		);

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		fireEvent.click( screen.getByRole( 'link', { name: 'WooPay' } ) );
		await waitFor( () => {
			expect(
				window.fetch.mock.calls.some(
					( [ url ] ) => url === '/?wc-ajax=wcpay_get_woopay_session'
				)
			).toBe( true );
		} );
		await waitFor( () => {
			expect(
				document.getElementById( 'woopay-connect-iframe' )
			).not.toBeNull();
		} );
		document
			.getElementById( 'woopay-connect-iframe' )
			.dispatchEvent( new window.Event( 'load' ) );
		await waitFor( () => {
			expect( postMessage ).toHaveBeenCalledWith(
				expect.objectContaining( {
					action: 'setPreemptiveSessionData',
				} ),
				'https://pay.woo.test'
			);
		} );
		window.dispatchEvent(
			new window.MessageEvent( 'message', {
				origin: 'https://pay.woo.test',
				data: {
					action: 'set_preemptive_session_data_error',
				},
			} )
		);

		await waitFor( () => {
			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();
		} );

		delete window.HTMLIFrameElement.prototype.contentWindow;
	} );

	it( 'renders the cached preferred WooPay card on the express button', () => {
		window.localStorage.setItem(
			'woopay_preferred_card',
			JSON.stringify( {
				brand: 'visa',
				last4: '4242',
			} )
		);

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		const button = screen.getByRole( 'button', {
			name: 'WooPay with Visa ending in 4242',
		} );
		expect( button ).toHaveTextContent( '4242' );
	} );

	it( 'clears the cached preferred WooPay card when Connect does not respond', async () => {
		jest.useFakeTimers();
		const postMessage = jest.fn();
		Object.defineProperty(
			window.HTMLIFrameElement.prototype,
			'contentWindow',
			{
				configurable: true,
				get() {
					return {
						postMessage,
					};
				},
			}
		);
		window.localStorage.setItem(
			'woopay_preferred_card',
			JSON.stringify( {
				brand: 'visa',
				last4: '4242',
			} )
		);

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		expect(
			screen.getByRole( 'button', {
				name: 'WooPay with Visa ending in 4242',
			} )
		).toHaveTextContent( '4242' );
		await act( async () => {
			await Promise.resolve();
		} );
		document
			.getElementById( 'woopay-connect-iframe' )
			.dispatchEvent( new window.Event( 'load' ) );
		await Promise.resolve();

		await act( async () => {
			jest.advanceTimersByTime( 5000 );
			await Promise.resolve();
		} );

		expect( postMessage ).toHaveBeenCalledWith(
			{
				action: 'getPreferredPaymentMethod',
			},
			'https://pay.woo.test'
		);
		expect(
			window.localStorage.getItem( 'woopay_preferred_card' )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'WooPay' } )
		).toBeVisible();

		delete window.HTMLIFrameElement.prototype.contentWindow;
	} );

	it( 'does not initialize WooPay from disabled product forms', async () => {
		document.body.innerHTML =
			'<form class="cart">' +
			'<input type="hidden" name="product_id" value="123" />' +
			'<button type="submit" class="single_add_to_cart_button disabled wc-variation-selection-needed" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>';
		getMockPaymentMethodSettings().woopayButton = {
			...getMockPaymentMethodSettings().woopayButton,
			context: 'product',
		};
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( {
				result: 'success',
				url: 'https://pay.woo.test/session',
			} ),
		} );

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		await waitFor( () => {
			expect(
				window.fetch.mock.calls.some(
					( [ url, options ] ) =>
						url ===
							'https://example.test/wp-admin/admin-ajax.php' &&
						options.body.get( 'tracksEventName' ) ===
							'woopay_button_load'
				)
			).toBe( true );
		} );
		window.fetch.mockClear();

		fireEvent.click( screen.getByRole( 'button', { name: 'WooPay' } ) );
		await act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );

		expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
		expect(
			window.fetch.mock.calls.some(
				( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
			)
		).toBe( false );
	} );

	// Client 11.1.0 wraps the button in `#wcpay-woopay-button`, swaps its content
	// for a spinner and adds `is-loading` while the first-party session request
	// runs, and sets `data-width-type` from the width measured on mount: wide
	// above 140px
	// (client/checkout/woopay/express-button/woopay-express-checkout-button.js:108-114,232-234,375-381,449-469).
	it( 'renders the client WooPay button wrapper, loading state and width type', async () => {
		let resolveSession;
		getMockPaymentMethodSettings().isWoopayFirstPartyAuthEnabled = true;
		window.fetch = jest.fn( ( url ) =>
			url === '/?wc-ajax=wcpay_get_woopay_session'
				? new Promise( ( resolve ) => {
						resolveSession = resolve;
				  } )
				: Promise.resolve( { json: () => Promise.resolve( {} ) } )
		);

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];
		const { container } = render(
			createElement( expressRegistration.content.type )
		);

		const wrapper = container.firstChild;
		const button = screen.getByRole( 'link', { name: 'WooPay' } );
		expect( wrapper ).toHaveAttribute( 'id', 'wcpay-woopay-button' );
		expect( wrapper ).toContainElement( button );
		expect( button ).toHaveAttribute( 'data-width-type', 'narrow' );
		expect( button ).not.toHaveClass( 'is-loading' );

		fireEvent.click( button );

		await waitFor( () => {
			expect( button ).toHaveClass( 'woopay-express-button is-loading' );
		} );
		expect(
			button.querySelector( '.wc-block-components-spinner' )
		).not.toBeNull();
		expect( button.querySelector( '.button-content' ) ).toBeNull();

		await act( async () => {
			resolveSession( { json: () => Promise.resolve( {} ) } );
		} );

		await waitFor( () => {
			expect( button ).not.toHaveClass( 'is-loading' );
		} );
		expect(
			button.querySelector( '.wc-block-components-spinner' )
		).toBeNull();
		expect( button.querySelector( '.button-content' ) ).not.toBeNull();
	} );

	it.each( [
		[ 141, 'wide' ],
		[ 140, 'narrow' ],
	] )(
		'sets the WooPay button width type from a %ipx measured width',
		( width, widthType ) => {
			const rectSpy = jest
				.spyOn( window.HTMLElement.prototype, 'getBoundingClientRect' )
				.mockReturnValue( { width } );

			registerWooPay();
			const expressRegistration =
				registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];
			render( createElement( expressRegistration.content.type ) );

			expect(
				screen.getByRole( 'button', { name: 'WooPay' } )
			).toHaveAttribute( 'data-width-type', widthType );
			rectSpy.mockRestore();
		}
	);
	// Client 11.1.0: with first-party auth off, the express button calls
	// `expressCheckoutIframe( api, context, emailSelector )`
	// (client/checkout/woopay/express-button/woopay-express-checkout-button.js:164-201),
	// which opens the platform `/otp/` iframe (express-checkout-iframe.js:40-205)
	// and, on the OTP result, posts init_woopay once with the platform user
	// session (express-checkout-iframe.js:213-262, init-woopay.js:15-61).
	describe( 'express OTP iframe', () => {
		const expectedOtpUrl =
			'https://pay.woo.test/otp/?testMode=true&needsHeader=false&wcpayVersion=11.1.0' +
			'&email=shopper%40example.com&is_blocks=true&is_express=true&express_context=checkout' +
			'&source_url=http%3A%2F%2Flocalhost%2F&viewport=0x0' +
			'&tracksUserIdentity=%7B%22_ut%22%3A%22anon%22%2C%22_ui%22%3A%22tk-anon-1%22%7D';
		let postMessage;
		let createNotice;

		const getInitCalls = () =>
			window.fetch.mock.calls.filter(
				( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
			);

		const sendWooPayMessage = ( data, origin = 'https://pay.woo.test' ) =>
			act( async () => {
				window.dispatchEvent(
					new window.MessageEvent( 'message', { origin, data } )
				);
			} );

		const openOtpIframe = async () => {
			registerWooPay();
			const expressRegistration =
				registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];
			render( createElement( expressRegistration.content.type ) );
			fireEvent.click( screen.getByRole( 'button', { name: 'WooPay' } ) );
			await waitFor( () => {
				expect(
					document.querySelector( '.woopay-otp-iframe' )
				).not.toBeNull();
			} );

			return document.querySelector( '.woopay-otp-iframe' );
		};

		beforeEach( () => {
			postMessage = jest.fn();
			createNotice = jest.fn();
			dispatch.mockReturnValue( { createNotice } );
			Object.defineProperty(
				window.HTMLIFrameElement.prototype,
				'contentWindow',
				{
					configurable: true,
					get() {
						return { postMessage };
					},
				}
			);
			window.wcSettings = { wcBlocksConfig: {} };
			document.cookie = 'tk_ai=tk-anon-1; path=/';
			document.body.innerHTML =
				'<input id="email" value="shopper@example.com" />';
			window.fetch = jest.fn().mockResolvedValue( {
				json: jest.fn().mockResolvedValue( {} ),
			} );
		} );

		afterEach( () => {
			// Close any iframe left open so its window listeners go away.
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin: 'https://pay.woo.test',
					data: { action: 'close_modal' },
				} )
			);
			delete window.wcSettings;
			document.cookie =
				'tk_ai=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC';
			delete window.HTMLIFrameElement.prototype.contentWindow;
		} );

		it( 'opens the platform OTP iframe with the client query instead of the direct-checkout redirect', async () => {
			const iframe = await openOtpIframe();

			expect( iframe.getAttribute( 'src' ) ).toBe( expectedOtpUrl );
			expect( iframe.title ).toBe( 'WooPay SMS code verification' );
			expect( iframe ).toHaveClass( 'intrinsic-ignore' );
			const wrapper = iframe.parentElement;
			expect( wrapper ).toHaveClass( 'woopay-otp-iframe-wrapper' );
			expect( wrapper ).toHaveAttribute( 'role', 'dialog' );
			expect( wrapper ).toHaveAttribute( 'aria-modal', 'true' );
			expect( wrapper.parentElement ).toBe( document.body );
			expect( navigate ).not.toHaveBeenCalled();
			expect( getInitCalls() ).toHaveLength( 0 );
			expect(
				window.fetch.mock.calls.some( ( [ url ] ) =>
					String( url ).includes( 'get_woopay_minimum_session_data' )
				)
			).toBe( false );
		} );

		it( 'posts init_woopay once with the OTP user session and follows the returned URL', async () => {
			let resolveInit;
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? new Promise( ( resolve ) => {
							resolveInit = resolve;
					  } )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			await sendWooPayMessage(
				{
					action: 'redirect_to_woopay',
					platformCheckoutUserSession: 'forged-session',
				},
				'https://attacker.test'
			);
			expect( getInitCalls() ).toHaveLength( 0 );

			await sendWooPayMessage( {
				action: 'otp_email_submitted',
				userEmail: 'otp@example.com',
			} );
			await sendWooPayMessage( {
				action: 'redirect_to_platform_checkout',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			await sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );

			expect( getInitCalls() ).toHaveLength( 1 );
			const body = getInitCalls()[ 0 ][ 1 ].body;
			expect( body.get( '_wpnonce' ) ).toBe( 'init-nonce' );
			expect( body.get( 'email' ) ).toBe( 'otp@example.com' );
			expect( body.get( 'user_session' ) ).toBe( 'platform-session-1' );

			await act( async () => {
				resolveInit( {
					json: () =>
						Promise.resolve( {
							result: 'success',
							url: 'https://pay.woo.test/woopay/?platform_checkout_key=abc',
						} ),
				} );
			} );

			await waitFor( () => {
				expect( navigate ).toHaveBeenCalledWith(
					'https://pay.woo.test/woopay/?platform_checkout_key=abc'
				);
			} );
			expect( navigate ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'shows the WooPay unavailable notice and closes the iframe when init_woopay fails', async () => {
			window.fetch = jest.fn( ( url ) =>
				Promise.resolve( {
					json: () =>
						Promise.resolve(
							url === '/?wc-ajax=wcpay_init_woopay'
								? { result: 'failure' }
								: {}
						),
				} )
			);
			await openOtpIframe();

			await sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );

			await waitFor( () => {
				expect( createNotice ).toHaveBeenCalledWith(
					'error',
					'WooPay is unavailable at this time. Sorry for the inconvenience.',
					{ context: 'wc/checkout' }
				);
			} );
			expect( dispatch ).toHaveBeenCalledWith( 'core/notices' );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( navigate ).not.toHaveBeenCalled();
		} );

		it( 'follows the skip-session-init redirect, sizes the iframe and closes on close_modal', async () => {
			const iframe = await openOtpIframe();

			await sendWooPayMessage( { action: 'iframe_height', height: 500 } );
			expect( iframe.style.height ).toBe( '500px' );
			expect( iframe.style.top ).toBe(
				`${ Math.floor( window.innerHeight / 2 - 250 ) }px`
			);

			iframe.dispatchEvent( new window.Event( 'load' ) );
			expect( iframe ).toHaveClass( 'open' );
			expect( postMessage ).toHaveBeenCalledWith(
				{ action: 'setHeader', value: false },
				'https://pay.woo.test'
			);
			expect( document.body.style.overflow ).toBe( 'hidden' );

			await sendWooPayMessage( {
				action: 'redirect_to_woopay_skip_session_init',
				redirectUrl: 'https://pay.woo.test/woopay/?skip=1',
			} );
			expect( navigate ).toHaveBeenCalledWith(
				'https://pay.woo.test/woopay/?skip=1'
			);

			await sendWooPayMessage( { action: 'close_modal' } );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( document.body.style.overflow ).toBe( '' );
		} );

		// Client express-checkout-iframe.js:244-248: a result that arrives
		// after the shopper closed the iframe does nothing.
		it( 'ignores an init_woopay result that arrives after the iframe closed', async () => {
			let resolveInit;
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? new Promise( ( resolve ) => {
							resolveInit = resolve;
					  } )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			await sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );
			expect( getInitCalls() ).toHaveLength( 1 );
			await sendWooPayMessage( { action: 'close_modal' } );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();

			await act( async () => {
				resolveInit( {
					json: () =>
						Promise.resolve( {
							result: 'success',
							url: 'https://pay.woo.test/woopay/?platform_checkout_key=abc',
						} ),
				} );
			} );
			await act( async () => {
				await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
			} );

			expect( navigate ).not.toHaveBeenCalled();
			expect( createNotice ).not.toHaveBeenCalled();
		} );

		// Recorded decision C11: the client has no .catch, so a network
		// failure leaves the iframe open; native shows the client's
		// unavailable notice and closes it, as for a failed result.
		it( 'shows the unavailable notice and closes the iframe when init_woopay cannot be reached', async () => {
			window.fetch = jest.fn( ( url ) =>
				url === '/?wc-ajax=wcpay_init_woopay'
					? Promise.reject( new TypeError( 'Failed to fetch' ) )
					: Promise.resolve( { json: () => Promise.resolve( {} ) } )
			);
			await openOtpIframe();

			await sendWooPayMessage( {
				action: 'redirect_to_woopay',
				platformCheckoutUserSession: 'platform-session-1',
			} );

			await waitFor( () => {
				expect( createNotice ).toHaveBeenCalledWith(
					'error',
					'WooPay is unavailable at this time. Sorry for the inconvenience.',
					{ context: 'wc/checkout' }
				);
			} );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect( navigate ).not.toHaveBeenCalled();
		} );

		// Recorded decision C12: the client throws in new URL() on an
		// unparsable host; native trusts no message at all.
		it( 'trusts no message when the WooPay host cannot be parsed', async () => {
			getMockPaymentMethodSettings().woopayHost = 'not a url';
			await openOtpIframe();

			for ( const origin of [ 'https://attacker.test', 'null' ] ) {
				await sendWooPayMessage(
					{
						action: 'redirect_to_woopay',
						platformCheckoutUserSession: 'forged-session',
					},
					origin
				);
				await sendWooPayMessage(
					{
						action: 'redirect_to_woopay_skip_session_init',
						redirectUrl: 'https://attacker.test/phish',
					},
					origin
				);
				await sendWooPayMessage( { action: 'close_modal' }, origin );
			}

			expect( getInitCalls() ).toHaveLength( 0 );
			expect( navigate ).not.toHaveBeenCalled();
			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();

			fireEvent.keyUp( document, { key: 'Escape' } );
		} );

		// Client express-checkout-iframe.js:298-302.
		it( 'closes the iframe on Escape and keeps it open on other keys', async () => {
			await openOtpIframe();
			const iframe = document.querySelector( '.woopay-otp-iframe' );
			iframe.dispatchEvent( new window.Event( 'load' ) );
			expect( document.body.style.overflow ).toBe( 'hidden' );

			fireEvent.keyUp( document, { key: 'Enter' } );
			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();

			fireEvent.keyUp( document, { key: 'Escape' } );
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
			expect(
				document.querySelector( '.woopay-otp-iframe-wrapper' )
			).toBeNull();
			expect( document.body.style.overflow ).toBe( '' );
		} );

		// Client express-checkout-iframe.js:291-296: Safari restores the
		// page from the back-forward cache with the iframe still open.
		it( 'closes the iframe when the page is restored from the back-forward cache', async () => {
			await openOtpIframe();

			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', {
					persisted: false,
				} )
			);
			expect(
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();

			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', {
					persisted: true,
				} )
			);
			expect( document.querySelector( '.woopay-otp-iframe' ) ).toBeNull();
		} );
	} );
} );
