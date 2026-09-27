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
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '.woopay-otp-iframe' ) ).not.toBeNull();
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
				document.querySelector( '.woopay-otp-iframe' )
			).not.toBeNull();

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
		expect( blocksButton.getAttribute( 'data-width-type' ) ).toBe( 'wide' );
	} );

	test( 'shows a checkout WooPay error in the selected gateway box only', async () => {
		// Source: client 11.1.0 client/checkout/utils/show-error-checkout.js:4-55
		// shows the failure where the shopper sees it, so the inline box must
		// be the selected gateway's (P4b review M8). Placement is brief O7.
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
		expect( cardBox.textContent ).toBe( 'WooPay is unavailable right now.' );
		expect( cardBox.hidden ).toBe( false );
		expect( klarnaBox.textContent ).toBe( '' );
		expect( klarnaBox.hidden ).toBe( true );
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
		// The client's agreement.js wraps the copy in `<div className="tos">`.
		expect( agreement.tagName ).toBe( 'DIV' );
		expect( agreement.className ).toBe( 'tos' );
		expect( agreement.textContent ).toBe(
			"By continuing, you agree to WooPay's Terms of Service and Privacy Policy."
		);
		expect( agreement.hidden ).toBe( false );

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
		expect( agreement.hidden ).toBe( true );
	} );

	test( 'shows the WooPay additional-information line above the agreement under save my info', () => {
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
		expect( additionalInfo.hidden ).toBe( false );

		const phoneField = document.querySelector(
			'input[name="woopay_user_phone_field[full]"]'
		);
		const agreement = document.querySelector(
			'#wcpay-woopay-save-user .tos'
		);
		// querySelectorAll returns matches in document order, so this also
		// proves the additional-information line sits between the phone
		// field and the agreement, matching the client's placement.
		const container = document.getElementById( 'wcpay-woopay-save-user' );
		const orderedNodes = container.querySelectorAll(
			'input[name="woopay_user_phone_field[full]"], .additional-information, .tos'
		);
		expect( Array.from( orderedNodes ) ).toEqual( [
			phoneField,
			additionalInfo,
			agreement,
		] );

		const saveCheckbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		saveCheckbox.checked = false;
		saveCheckbox.dispatchEvent(
			new window.Event( 'change', { bubbles: true, cancelable: true } )
		);
		expect( additionalInfo.hidden ).toBe( true );
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
			delete window.HTMLIFrameElement.prototype.contentWindow;
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
} );
