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
import userEvent from '@testing-library/user-event';
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
		woopayOtpCloseLabel: 'Close',
		woopayExpressUnavailableMessage:
			'WooPay is unavailable at this time. Sorry for the inconvenience.',
		woopaySessionNonce: 'session-nonce',
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
// jsdom defines `contentWindow` as an accessor on HTMLIFrameElement.prototype; tests that stub it put jsdom's back.
const nativeContentWindow = Object.getOwnPropertyDescriptor(
	window.HTMLIFrameElement.prototype,
	'contentWindow'
);
let navigate;
let getComputedStyleSpy;

describe( 'wc-payment-method-woopayments-woopay', () => {
	afterEach( () => {
		Object.defineProperty(
			window.HTMLIFrameElement.prototype,
			'contentWindow',
			nativeContentWindow
		);
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
		getComputedStyleSpy?.mockRestore();
		getComputedStyleSpy = undefined;
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
		getComputedStyleSpy = jest
			.spyOn( window, 'getComputedStyle' )
			.mockImplementation( ( element ) => {
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
			} );
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

		expect( screen.queryByLabelText( 'Mobile phone number' ) ).toBeNull();
		expect(
			screen.queryByLabelText(
				'Securely save my information for 1-click checkout'
			)
		).toBeNull();
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
	} );

	it( 'renders the cached preferred WooPay card on the express button', () => {
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

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		const button = screen.getByRole( 'button', {
			name: 'WooPay with Visa ending in 4242',
		} );
		expect( button ).toHaveTextContent( '4242' );
		rectSpy.mockRestore();
	} );

	// Client 11.1.0 only shows the preferred card once the button measures at
	// least 220px wide; below that it falls back to the plain WooPay label
	// (woopay-express-checkout-button.js:32,387-392).
	it( 'hides the cached preferred WooPay card on a narrow express button', () => {
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

		registerWooPay();
		const expressRegistration =
			registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];

		render( createElement( expressRegistration.content.type ) );

		const button = screen.getByRole( 'button', { name: 'WooPay' } );
		expect( button ).not.toHaveTextContent( '4242' );
		rectSpy.mockRestore();
	} );

	it( 'clears the cached preferred WooPay card when Connect does not respond', async () => {
		jest.useFakeTimers();
		const rectSpy = jest
			.spyOn( window.HTMLElement.prototype, 'getBoundingClientRect' )
			.mockReturnValue( { width: 220 } );
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

		rectSpy.mockRestore();
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
	// runs (client/checkout/woopay/express-button/woopay-express-checkout-button.js:108-114,232-234,449-469).
	it( 'renders the client WooPay button wrapper and loading state', async () => {
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

		it( 'starts init_woopay only for a redirect no other WooPay bundle has claimed', async () => {
			const redirect = {
				action: 'redirect_to_platform_checkout',
				platformCheckoutUserSession: 'platform-session-1',
			};
			// The email input's listener, registered first from its own bundle.
			const emailInputBundle = ( e ) => {
				e.wcWooPayInitClaimed = true;
			};
			window.addEventListener( 'message', emailInputBundle );
			await openOtpIframe();

			await sendWooPayMessage( redirect );
			expect( getInitCalls() ).toHaveLength( 0 );

			window.removeEventListener( 'message', emailInputBundle );
			let claimed;
			const laterBundle = ( e ) => {
				claimed = e.wcWooPayInitClaimed;
			};
			window.addEventListener( 'message', laterBundle );
			await sendWooPayMessage( redirect );
			window.removeEventListener( 'message', laterBundle );

			expect( getInitCalls() ).toHaveLength( 1 );
			expect( claimed ).toBe( true );
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

		// Client 11.1.0 leaves the dialog unnamed, drops focus on close and lets Tab leave it
		// (express-button/express-checkout-iframe.js:42-43, :150-161); native names it after the iframe, gives
		// focus back to where it was, as the email-lookup iframe does, and keeps focus inside while it is open.
		describe( 'as a modal dialog', () => {
			const openFromFocusedButton = async () => {
				registerWooPay();
				const expressRegistration =
					registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];
				render( createElement( expressRegistration.content.type ) );
				const button = screen.getByRole( 'button', { name: 'WooPay' } );
				button.focus();
				fireEvent.click( button );
				await waitFor( () => {
					expect(
						document.querySelector( '.woopay-otp-iframe' )
					).not.toBeNull();
				} );

				return button;
			};

			it( 'is named after the iframe and takes focus', async () => {
				await openFromFocusedButton();

				const dialog = screen.getByRole( 'dialog', {
					name: 'WooPay SMS code verification',
				} );
				expect(
					dialog.querySelector( '.woopay-otp-iframe' )
				).toHaveFocus();
			} );

			// WooPay's OTP iframe posts `{ action: 'close_modal' }` from the WooPay origin (client
			// express-button/express-checkout-iframe.js:266).
			it.each( [
				[
					'the close_modal message',
					() => sendWooPayMessage( { action: 'close_modal' } ),
				],
				[
					'Escape',
					async () => fireEvent.keyUp( document, { key: 'Escape' } ),
				],
				[
					'a click on the backdrop',
					async () =>
						fireEvent.click(
							document.querySelector(
								'.woopay-otp-iframe-wrapper'
							)
						),
				],
			] )(
				'gives focus back to the WooPay button when closed by %s',
				async ( label, close ) => {
					const button = await openFromFocusedButton();

					await close();

					expect(
						document.querySelector( '.woopay-otp-iframe' )
					).toBeNull();
					expect( button ).toHaveFocus();
				}
			);

			// A browser takes focus away from a focused control that becomes disabled (the HTML "focus fixup rule"), and
			// the WooPay button is disabled while it loads, before the dialog opens (seen in Chromium: focusout from the
			// loading button, then focus on the iframe). jsdom keeps focus on a disabled control and cannot blur it, so the
			// test moves focus to another element of the page at that point instead.
			describe( 'when the loading button loses focus before the dialog opens', () => {
				it.each( [
					[
						'the close_modal message',
						() => sendWooPayMessage( { action: 'close_modal' } ),
					],
					[
						'Escape',
						async () =>
							fireEvent.keyUp( document, { key: 'Escape' } ),
					],
					[
						'the Close button',
						async () =>
							fireEvent.click(
								screen.getByRole( 'button', { name: 'Close' } )
							),
					],
				] )(
					'still gives focus back to the WooPay button when closed by %s',
					async ( label, close ) => {
						registerWooPay();
						const expressRegistration =
							registerExpressPaymentMethod.mock.calls[ 0 ][ 0 ];
						render(
							createElement( expressRegistration.content.type )
						);
						const button = screen.getByRole( 'button', {
							name: 'WooPay',
						} );
						button.focus();
						fireEvent.click( button );
						expect( button ).toBeDisabled();
						const elsewhere = document.createElement( 'input' );
						document.body.appendChild( elsewhere );
						elsewhere.focus();
						await waitFor( () => {
							expect(
								document.querySelector( '.woopay-otp-iframe' )
							).not.toBeNull();
						} );
						await waitFor( () => {
							expect( button ).not.toBeDisabled();
						} );

						await close();

						expect(
							document.querySelector( '.woopay-otp-iframe' )
						).toBeNull();
						expect( button ).toHaveFocus();
					}
				);
			} );

			// The iframe here never fires `load` and never posts a message: the state of an OTP page that failed to load,
			// where WooPay's own close control and Escape bridge do not exist.
			describe( 'when the iframe never loads', () => {
				// The browser's sequential navigation out of an iframe whose document has no control: the next element a
				// Tab reaches (tabIndex 0 or more, not disabled) in document order, or the first one on the page after
				// the last. jsdom has no cross-document Tab and user-event does not count an iframe as focusable.
				const tabOutOf = ( element ) => {
					const tabbable = Array.from(
						document.querySelectorAll( 'button, input, iframe' )
					).filter(
						( candidate ) =>
							candidate === element ||
							( candidate.tabIndex >= 0 && ! candidate.disabled )
					);
					const next =
						tabbable[ tabbable.indexOf( element ) + 1 ] ||
						tabbable[ 0 ];
					next.focus();
				};

				it( 'lets the shopper Tab to a Close button, and Tab past it comes back to the iframe', async () => {
					await openFromFocusedButton();
					const dialog = screen.getByRole( 'dialog' );
					const iframe = dialog.querySelector( '.woopay-otp-iframe' );

					tabOutOf( iframe );
					const close = screen.getByRole( 'button', {
						name: 'Close',
					} );
					expect( close ).toHaveFocus();
					expect( dialog ).toContainElement( close );
					// Reachable with Tab, right after the iframe.
					expect( close.tabIndex ).toBeGreaterThanOrEqual( 0 );
					expect( iframe.nextElementSibling ).toBe( close );

					tabOutOf( close );
					expect( iframe ).toHaveFocus();
				} );

				it.each( [
					[ 'Enter', '{Enter}' ],
					[ 'Space', ' ' ],
					[ 'Escape', '{Escape}' ],
				] )(
					'closes on %s from the Close button and gives focus back to the WooPay button',
					async ( label, keys ) => {
						const user = userEvent.setup();
						const button = await openFromFocusedButton();
						tabOutOf(
							document.querySelector( '.woopay-otp-iframe' )
						);
						expect(
							screen.getByRole( 'button', { name: 'Close' } )
						).toHaveFocus();

						await user.keyboard( keys );

						expect( screen.queryByRole( 'dialog' ) ).toBeNull();
						expect( button ).toHaveFocus();
					}
				);
			} );

			it( 'sends focus that leaves the dialog back to the iframe while it is open', async () => {
				const button = await openFromFocusedButton();
				const iframe = document.querySelector( '.woopay-otp-iframe' );
				const email = document.getElementById( 'email' );

				email.focus();
				expect( iframe ).toHaveFocus();

				await sendWooPayMessage( { action: 'close_modal' } );
				expect( button ).toHaveFocus();
				email.focus();
				expect( email ).toHaveFocus();
			} );
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
