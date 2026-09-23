/**
 * @jest-environment jest-fixed-jsdom
 */

describe( 'WooPayments WooPay checkout', () => {
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

	function getTrackingEvents() {
		return window.fetch.mock.calls
			.filter(
				( [ url, options ] ) =>
					url === 'https://example.test/admin-ajax.php' &&
					options.body.get( 'action' ) === 'platform_tracks'
			)
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
			woopayUserSession: 'qwerty123',
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

	test( 'renders a branded WooPay express button and initializes WooPay on click', async () => {
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

		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'/?wc-ajax=wcpay_init_woopay',
			expect.objectContaining( {
				_wpnonce: 'init-nonce',
				email: 'shopper@example.com',
				user_session: 'qwerty123',
			} )
		);
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
	} );

	test( 'does not record WooPay tracking when shopper tracking is disabled', async () => {
		window.wcpay_core_woopay_config.isShopperTrackingEnabled = false;
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
			expect( global.jQuery.post ).toHaveBeenNthCalledWith(
				2,
				'/?wc-ajax=wcpay_init_woopay',
				expect.objectContaining( {
				_wpnonce: 'init-nonce',
				user_session: 'qwerty123',
				} )
			);
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
		expect( global.jQuery.post ).toHaveBeenNthCalledWith(
			2,
			'/?wc-ajax=wcpay_init_woopay',
			expect.objectContaining( {
				_wpnonce: 'init-nonce',
				user_session: 'qwerty123',
			} )
		);
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

			delete window.HTMLIFrameElement.prototype.contentWindow;
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
				global.jQuery.post.mock.calls.some(
					( [ url ] ) => url === '/?wc-ajax=wcpay_init_woopay'
				)
			).toBe( true );

			delete window.HTMLIFrameElement.prototype.contentWindow;
		} );

		test( 'renders the cached preferred WooPay card on the express button', () => {
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
		} );

		test( 'clears the cached preferred WooPay card when Connect does not respond', async () => {
			jest.useFakeTimers();
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

			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
			await Promise.resolve();
			jest.advanceTimersByTime( 5000 );
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

			delete window.HTMLIFrameElement.prototype.contentWindow;
		} );

		test( 'does not add disabled product forms to the cart before product-page WooPay init', async () => {
				document.body.innerHTML =
					'<form class="cart">' +
					'<input type="hidden" name="product_id" value="123" />' +
					'<button type="submit" class="single_add_to_cart_button disabled ' +
					'wc-variation-selection-needed" name="add-to-cart" value="123">Add to cart</button>' +
					'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
					'<div id="wcpay-core-payment-errors" hidden></div>' +
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
				document.getElementById( 'wcpay-core-payment-errors' ).textContent
			).toBe( 'Choose product options before using WooPay.' );
		} );

		test( 'renders WooPay save-my-info fields and persists phone data on blur', async () => {
			require( '../woopayments-woopay' );

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		const phoneField = document.querySelector(
			'input[name="woopay_user_phone_field[full]"]'
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

		phoneField.value = '+15555550123';
		phoneField.dispatchEvent(
			new window.Event( 'blur', { bubbles: true, cancelable: true } )
		);
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'/?wc-ajax=wcpay_set_woopay_phone_number',
			expect.objectContaining( {
				_wpnonce: 'session-nonce',
				save_user_in_woopay: 'true',
				woopay_is_blocks: 'false',
				woopay_user_phone_field: {
					full: '+15555550123',
				},
			} )
		);
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

	test( 'does not add an invalid IAPI product form before WooPay init', async () => {
		document.body.innerHTML =
			'<form class="wp-block-add-to-cart-with-options is-invalid">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="" />' +
			'<div id="wcpay-woopay-button" data-product_page="1"><div class="woopay-express-button is-placeholder"></div></div>' +
			'<div id="wcpay-core-payment-errors" hidden></div>' +
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
			document.getElementById( 'wcpay-core-payment-errors' ).textContent
		).toBe( 'Choose product options before using WooPay.' );
	} );

	test( 'records WooPay save-info offer and checkbox events', () => {
		require( '../woopayments-woopay' );

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);

		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);

		expect( getTrackingEvents() ).toEqual(
			expect.arrayContaining( [
				{
					name: 'checkout_woopay_save_my_info_offered',
					props: {},
				},
				{
					name: 'checkout_save_my_info_click',
					props: { status: 'checked' },
				},
				{
					name: 'checkout_save_my_info_click',
					props: { status: 'unchecked' },
				},
			] )
		);
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

		function emitConnectMessage( action, value, origin = 'https://pay.woo.test' ) {
			window.dispatchEvent(
				new window.MessageEvent( 'message', {
					origin,
					data: { action, value },
				} )
			);
		}

		async function initializeDirectCheckout( loggedIn ) {
			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
			await flushPromises();
			emitConnectMessage( 'get_is_user_logged_in_success', loggedIn );
			await flushPromises();
		}

		afterEach( () => {
			delete window.HTMLIFrameElement.prototype.contentWindow;
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
			[ 'malformed encrypted identity', 'identity' ],
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
			emitConnectMessage(
				'get_encrypted_data_success',
				failure === 'identity' ? { data: 'only-data' } : encryptedIdentity
			);
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

		test.each( [
			[ 'disabled direct flag', '<a href="#checkout">Checkout</a>' ],
			[ 'missing checkout href', '<div class="wp-block-woocommerce-proceed-to-checkout-block">Checkout</div>' ],
		] )( 'preserves browser behavior for %s', async ( name, markup ) => {
			configureDirectCheckout( markup );
			if ( name === 'disabled direct flag' ) {
				window.wcpay_core_woopay_config.isWooPayDirectCheckoutEnabled = false;
			}
			installConnect();
			require( '../woopayments-woopay' );
			if ( name === 'missing checkout href' ) {
				await initializeDirectCheckout( false );
			}
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
			expect( postMessage ).toHaveBeenCalledTimes( 3 );
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

			document
				.getElementById( 'woopay-connect-iframe' )
				.dispatchEvent( new window.Event( 'load' ) );
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
} );
