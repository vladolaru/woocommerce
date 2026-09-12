/**
 * @jest-environment jest-fixed-jsdom
 */

describe( 'WooPayments express checkout', () => {
	let bodyEventHandlers;
	let elements;
	let expressElement;
	let expressHandlers;
	let originalFetch;
	let stripe;

	async function flushPromises() {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

	async function flushMicrotasks() {
		for ( let index = 0; index < 10; index++ ) {
			await Promise.resolve();
		}
	}

	function createDeferred() {
		let resolve;
		let reject;
		const promise = new Promise( ( promiseResolve, promiseReject ) => {
			resolve = promiseResolve;
			reject = promiseReject;
		} );

		return { promise, resolve, reject };
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

		return jQueryMock;
	}

	function getBaseConfig() {
		return {
			ajax_url: 'https://example.test/admin-ajax.php',
			enabled_methods: [ 'payment_request' ],
			button_context: 'checkout',
			has_block: false,
			store_name: 'Test Store',
			nonce: {
				store_api_nonce: 'store-api-nonce',
				tokenized_cart_nonce: 'cart-nonce',
				tokenized_cart_session_nonce: 'cart-session-nonce',
				platform_tracker: 'tracks-nonce',
			},
			checkout: {
				currency_code: 'usd',
				needs_payer_phone: true,
				needs_shipping: true,
				allowed_shipping_countries: [ 'US' ],
			},
			button: {
				type: 'buy',
				theme: 'dark',
				height: '48',
				radius: '4',
				context: 'checkout',
			},
			stripe: {
				publishableKey: 'pk_test_123',
				accountId: 'acct_123',
				locale: 'en',
			},
			flags: {
				isEceUsingConfirmationTokens: true,
			},
			payment_method_types: [ 'card' ],
		};
	}

	function getCartResponse() {
		return {
			needs_shipping: true,
			totals: {
				total_price: '5000',
				total_refund: '0',
				currency_code: 'USD',
			},
			billing_address: {
				email: 'shopper@example.test',
				first_name: 'Ada',
				last_name: 'Lovelace',
				address_1: '1 Test Street',
				city: 'San Francisco',
				state: 'CA',
				postcode: '94107',
				country: 'US',
			},
			shipping_address: {
				first_name: 'Ada',
				last_name: 'Lovelace',
				address_1: '1 Test Street',
				city: 'San Francisco',
				state: 'CA',
				postcode: '94107',
				country: 'US',
			},
		};
	}

	function getResolvedProductCart( methods ) {
		return {
			needs_shipping: true,
			totals: {
				total_price: '4200',
				total_refund: '0',
				currency_code: 'EUR',
			},
			extensions: {
				wcpay: {
					express_checkout_methods: methods,
				},
				subscriptions: [ { interval: 'month' } ],
			},
			items: [],
		};
	}

	function setProductPage( options ) {
		options = options || {};
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			( options.iapi
				? '<form class="wp-block-add-to-cart-with-options">' +
					'<input type="hidden" name="add-to-cart" value="257" />' +
					'<input type="hidden" name="product_id" value="257" />' +
					'<input type="hidden" name="variation_id" value="263" />' +
					'<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">' +
					'<input type="hidden" name="attribute_pa_color" value="blue" />' +
					'</div>' +
					'</form>'
				: '<form class="cart">' +
					'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
					'</form>' ) +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.enabled_methods =
			options.localizedMethods || [ 'payment_request' ];
		window.wcpayExpressCheckoutParams.payment_method_types =
			options.localizedTypes || [ 'card' ];
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: options.iapi ? 'variable' : 'simple',
		};
	}

	function setIapiColor( color ) {
		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="' +
			color +
			'" />';
	}

	function getOrderPayResponse() {
		var cartResponse = getCartResponse();

		return Object.assign( {}, cartResponse, {
			needs_shipping: false,
			billing_address: Object.assign( {}, cartResponse.billing_address, {
				email: 'order@example.test',
			} ),
		} );
	}

	function getStoreApiResponse( body, headers ) {
		headers = headers || {};

		return {
			headers: {
				get: jest.fn( ( name ) =>
					Object.prototype.hasOwnProperty.call( headers, name )
						? headers[ name ]
						: null
				),
			},
			json: jest.fn().mockResolvedValue( body ),
		};
	}

	function getPlatformTracksRequests() {
		return (
			( window.fetch && window.fetch.mock && window.fetch.mock.calls ) ||
			[]
		).filter(
			( [ , options ] ) =>
				options &&
				options.body &&
				options.body.get &&
				options.body.get( 'action' ) === 'platform_tracks'
		);
	}

	beforeEach( () => {
		jest.resetModules();
		bodyEventHandlers = {};
		expressHandlers = {};
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';

		const jQueryMock = createJQueryMock();
		global.jQuery = jQueryMock;
		global.$ = jQueryMock;
		window.jQuery = jQueryMock;
		window.$ = jQueryMock;
		window.wp = {
			apiFetch: jest.fn().mockResolvedValue( getCartResponse() ),
		};
		window.wcpayExpressCheckoutParams = getBaseConfig();
		originalFetch = window.fetch;
		expressElement = {
			mount: jest.fn(),
			on: jest.fn( ( eventName, handler ) => {
				expressHandlers[ eventName ] = handler;
			} ),
		};
		elements = {
			create: jest.fn( () => expressElement ),
			submit: jest.fn().mockResolvedValue( {} ),
			update: jest.fn().mockResolvedValue( {} ),
		};
		stripe = {
			elements: jest.fn( () => elements ),
			createConfirmationToken: jest.fn().mockResolvedValue( {
				confirmationToken: {
					id: 'ctoken_123',
				},
			} ),
		};
		window.Stripe = jest.fn( () => stripe );
		window.fetch = jest.fn().mockResolvedValue( {} );
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete global.jQuery;
		delete global.$;
		delete window.jQuery;
		delete window.$;
		delete window.wp;
		delete window.Stripe;
		delete window.wcpayFraudPreventionToken;
		delete window.wcpayAsyncCurrency;
		window.fetch = originalFetch;
		delete window.wcpayExpressCheckoutParams;
		document.body.innerHTML = '';
	} );

	test( 'mounts Stripe ECE after checkout totals update with tokenized cart headers', async () => {
		require( '../woopayments-express-checkout' );

		expect( bodyEventHandlers.updated_checkout ).toBeDefined();
		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'GET',
				path: '/wc/store/v1/cart?currency=USD',
				headers: expect.objectContaining( {
					Nonce: 'store-api-nonce',
					'X-WooPayments-Tokenized-Cart-Nonce': 'cart-nonce',
				} ),
			} )
		);
		expect(
			window.wp.apiFetch.mock.calls[ 0 ][ 0 ].headers
		).not.toHaveProperty( 'X-WooPayments-Tokenized-Cart-Session-Nonce' );
		expect( window.Stripe ).toHaveBeenCalledWith( 'pk_test_123', {
			locale: 'en',
			stripeAccount: 'acct_123',
			betas: [ 'card_country_event_beta_1' ],
		} );
		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				mode: 'payment',
				amount: 5000,
				currency: 'usd',
				loader: 'never',
				paymentMethodTypes: [ 'card' ],
			} )
		);
		expect( elements.create ).toHaveBeenCalledWith(
			'expressCheckout',
			expect.objectContaining( {
				buttonHeight: 48,
				buttonTheme: {
					applePay: 'black',
					googlePay: 'black',
				},
				buttonType: {
					applePay: 'buy',
					googlePay: 'buy',
				},
				paymentMethods: expect.objectContaining( {
					applePay: 'always',
					googlePay: 'always',
					klarna: 'never',
				} ),
			} )
		);
		expect( expressElement.mount ).toHaveBeenCalledWith(
			'#wcpay-express-checkout-element'
		);
	} );

	test( 'records only available Apple Pay load tracking events', async () => {
		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		expressHandlers.ready( {
			availablePaymentMethods: {
				applePay: true,
				googlePay: false,
			},
		} );

		const requests = getPlatformTracksRequests();

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ][ 0 ] ).toBe(
			'https://example.test/admin-ajax.php'
		);
		expect( requests[ 0 ][ 1 ].body.get( 'tracksNonce' ) ).toBe(
			'tracks-nonce'
		);
		expect( requests[ 0 ][ 1 ].body.get( 'tracksEventName' ) ).toBe(
			'applepay_button_load'
		);
		expect(
			JSON.parse( requests[ 0 ][ 1 ].body.get( 'tracksEventProp' ) )
		).toEqual( {
			source: 'checkout',
		} );
	} );

	test( 'records only the selected Google Pay click tracking event', async () => {
		const resolveClick = jest.fn();

		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		await expressHandlers.click( {
			expressPaymentType: 'google_pay',
			resolve: resolveClick,
		} );

		const requests = getPlatformTracksRequests();

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ][ 1 ].body.get( 'tracksEventName' ) ).toBe(
			'gpay_button_click'
		);
		expect(
			JSON.parse( requests[ 0 ][ 1 ].body.get( 'tracksEventProp' ) )
		).toEqual( {
			source: 'checkout',
		} );
		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( {
				emailRequired: true,
			} )
		);
	} );

	test( 'does not record express checkout tracking when shopper tracking is disabled', async () => {
		window.wcpayExpressCheckoutParams.is_shopper_tracking_enabled = false;

		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		expressHandlers.ready( {
			availablePaymentMethods: {
				applePay: true,
				googlePay: true,
			},
		} );
		await expressHandlers.click( {
			expressPaymentType: 'apple_pay',
			resolve: jest.fn(),
		} );

		expect( getPlatformTracksRequests() ).toHaveLength( 0 );
	} );

	test( 'mounts Stripe ECE with Amazon Pay when server config enables it', async () => {
		window.wcpayExpressCheckoutParams.enabled_methods = [
			'payment_request',
			'amazon_pay',
		];
		window.wcpayExpressCheckoutParams.payment_method_types = [
			'card',
			'amazon_pay',
		];

		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				paymentMethodTypes: [ 'card', 'amazon_pay' ],
			} )
		);
		expect( elements.create ).toHaveBeenCalledWith(
			'expressCheckout',
			expect.objectContaining( {
				paymentMethods: expect.objectContaining( {
					applePay: 'always',
					googlePay: 'always',
					amazonPay: 'auto',
				} ),
			} )
		);
	} );

	test( 'mounts Stripe ECE when only Amazon Pay is enabled', async () => {
		window.wcpayExpressCheckoutParams.enabled_methods = [ 'amazon_pay' ];
		window.wcpayExpressCheckoutParams.payment_method_types = [
			'amazon_pay',
		];

		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				paymentMethodTypes: [ 'amazon_pay' ],
			} )
		);
		expect( elements.create ).toHaveBeenCalledWith(
			'expressCheckout',
			expect.objectContaining( {
				paymentMethods: expect.objectContaining( {
					applePay: 'never',
					googlePay: 'never',
					amazonPay: 'auto',
				} ),
			} )
		);
	} );

	test( 'does not mount Amazon Pay when Stripe method types exclude it', async () => {
		window.wcpayExpressCheckoutParams.enabled_methods = [
			'payment_request',
			'amazon_pay',
		];
		window.wcpayExpressCheckoutParams.payment_method_types = [ 'card' ];

		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		expect( elements.create ).toHaveBeenCalledWith(
			'expressCheckout',
			expect.objectContaining( {
				paymentMethods: expect.objectContaining( {
					applePay: 'always',
					googlePay: 'always',
					amazonPay: 'never',
				} ),
			} )
		);
	} );

	test( 'places the classic checkout order with a confirmation token', async () => {
		window.wcpayFraudPreventionToken = 'fraud-token-123';
		window.wp.apiFetch
			.mockResolvedValueOnce( getCartResponse() )
			.mockResolvedValueOnce( {
				payment_result: {
					payment_status: 'success',
				},
			} );
		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		await expressHandlers.confirm( {
			billingDetails: {
				email: 'shopper@example.test',
				name: 'Ada Lovelace',
			},
		} );

		expect( stripe.createConfirmationToken ).toHaveBeenCalledWith( {
			elements,
		} );
		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/checkout?currency=USD',
				headers: expect.objectContaining( {
					Nonce: 'store-api-nonce',
					'X-WooPayments-Tokenized-Cart': true,
					'X-WooPayments-Tokenized-Cart-Nonce': 'cart-nonce',
				} ),
				data: expect.objectContaining( {
					payment_method: 'woocommerce_payments',
					payment_data: expect.arrayContaining( [
						{
							key: 'wcpay-confirmation-token',
							value: 'ctoken_123',
						},
						{
							key: 'wcpay-express-payment-method-types',
							value: JSON.stringify( [ 'card' ] ),
						},
						{
							key: 'wcpay-express-checkout-context',
							value: 'checkout',
						},
						{
							key: 'wcpay-fraud-prevention-token',
							value: 'fraud-token-123',
						},
					] ),
				} ),
			} )
		);
	} );

	test( 'places the classic Amazon Pay checkout order with express payment method types', async () => {
		window.wcpayExpressCheckoutParams.enabled_methods = [
			'payment_request',
			'amazon_pay',
		];
		window.wcpayExpressCheckoutParams.payment_method_types = [
			'card',
			'amazon_pay',
		];
		window.wp.apiFetch
			.mockResolvedValueOnce( getCartResponse() )
			.mockResolvedValueOnce( {
				payment_result: {
					payment_status: 'success',
				},
			} );
		require( '../woopayments-express-checkout' );

		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		await expressHandlers.confirm( {
			billingDetails: {
				email: 'shopper@example.test',
				name: 'Ada Lovelace',
			},
		} );

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				data: expect.objectContaining( {
					payment_data: expect.arrayContaining( [
						{
							key: 'wcpay-express-payment-method-types',
							value: JSON.stringify( [ 'card', 'amazon_pay' ] ),
						},
						{
							key: 'wcpay-express-checkout-context',
							value: 'checkout',
						},
						{
							key: 'wcpay-fraud-prevention-token',
							value: '',
						},
					] ),
				} ),
			} )
		);
	} );

	test( 'places the classic pay-for-order payment through the Store API order endpoint', async () => {
		const resolveClick = jest.fn();
		window.wcpayExpressCheckoutParams.button_context = 'pay_for_order';
		window.wcpayExpressCheckoutParams.has_block = true;
		window.wcpayExpressCheckoutParams.order_id = 123;
		window.wcpayExpressCheckoutParams.pay_for_order = 'true';
		window.wcpayExpressCheckoutParams.key = 'wc_order_key_123';
		window.wcpayExpressCheckoutParams.billing_email = 'order@example.test';
		window.wp.apiFetch
			.mockResolvedValueOnce( getOrderPayResponse() )
			.mockResolvedValueOnce( {
				payment_result: {
					payment_status: 'success',
				},
			} );
		require( '../woopayments-express-checkout' );

		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'GET',
				path: expect.stringContaining( '/wc/store/v1/order/123' ),
			} )
		);
		expect( window.wp.apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
			'key=wc_order_key_123'
		);
		expect( window.wp.apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
			'billing_email=order%40example.test'
		);

		expressHandlers.click( { resolve: resolveClick } );
		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( {
				shippingAddressRequired: false,
			} )
		);

		await expressHandlers.confirm( {
			billingDetails: {
				email: 'changed@example.test',
				name: 'Changed Shopper',
			},
		} );

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/checkout/123',
				data: expect.objectContaining( {
					key: 'wc_order_key_123',
					billing_email: 'order@example.test',
					billing_address: expect.objectContaining( {
						email: 'order@example.test',
					} ),
					payment_method: 'woocommerce_payments',
					payment_data: expect.arrayContaining( [
						{
							key: 'wcpay-confirmation-token',
							value: 'ctoken_123',
						},
						{
							key: 'wcpay-express-payment-method-types',
							value: JSON.stringify( [ 'card' ] ),
						},
						{
							key: 'wcpay-express-checkout-context',
							value: 'pay_for_order',
						},
					] ),
				} ),
			} )
		);
	} );

	test( 'mounts product page ECE from server product data without reading the current cart', async () => {
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};

		require( '../woopayments-express-checkout' );
		await flushPromises();

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				mode: 'payment',
				amount: 2500,
				currency: 'usd',
				loader: 'never',
				paymentMethodTypes: [ 'card' ],
			} )
		);
		expect( expressElement.mount ).toHaveBeenCalledWith(
			'#wcpay-express-checkout-element'
		);
	} );

	test( 'waits for resolved product currency before previewing and mounts the fresh cart once', async () => {
		const currency = createDeferred();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wcpayAsyncCurrency = { ready: currency.promise };
		window.wp.apiFetch.mockResolvedValue(
			getResolvedProductCart( [ 'payment_request' ] )
		);

		require( '../woopayments-express-checkout' );
		await flushPromises();

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( stripe.elements ).not.toHaveBeenCalled();

		currency.resolve( 'EUR' );
		await flushPromises();
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/add-item?currency=EUR',
				data: { id: 123, quantity: 1, variation: [] },
			} )
		);
		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				amount: 4200,
				currency: 'eur',
				paymentMethodTypes: [ 'card' ],
				setupFutureUsage: 'off_session',
			} )
		);
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'uses localized product data when currency readiness reaches the six-second watchdog', async () => {
		const currency = createDeferred();
		jest.useFakeTimers();
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wcpayAsyncCurrency = { ready: currency.promise };

		require( '../woopayments-express-checkout' );
		jest.advanceTimersByTime( 6000 );
		await Promise.resolve();
		await Promise.resolve();

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 2500, currency: 'usd' } )
		);
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
	} );

	test.each( [
		[ 'rejects', true ],
		[ 'has no usable methods', false ],
	] )( 'keeps product ECE hidden when the changed-currency preview %s', async ( scenario, rejects ) => {
		void scenario;
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wcpayAsyncCurrency = { ready: Promise.resolve( 'eur' ) };
		window.wp.apiFetch.mockImplementation( () =>
			rejects
				? Promise.reject( new Error( 'Preview failed' ) )
				: Promise.resolve( getResolvedProductCart( [] ) )
		);

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( stripe.elements ).not.toHaveBeenCalled();
		expect( expressElement.mount ).not.toHaveBeenCalled();
		expect(
			document
				.getElementById( 'wcpay-express-checkout-element' )
				.classList.contains( 'is-ready' )
		).toBe( false );
	} );

	test( 'coalesces a pre-resolution IAPI selection into one current-currency preview', async () => {
		const currency = createDeferred();
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'</div>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};
		window.wcpayAsyncCurrency = { ready: currency.promise };
		window.wp.apiFetch.mockResolvedValue(
			getResolvedProductCart( [ 'payment_request' ] )
		);

		require( '../woopayments-express-checkout' );
		await Promise.resolve();
		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( stripe.elements ).not.toHaveBeenCalled();

		currency.resolve( 'EUR' );
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wc/store/v1/cart/add-item?currency=EUR',
				data: {
					id: 257,
					quantity: 1,
					variation: [
						{ attribute: 'attribute_pa_color', value: 'red' },
					],
				},
			} )
		);
		expect( stripe.elements ).toHaveBeenCalledTimes( 1 );
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
	} );

	test.each( [
		[ 'after the debounce', true ],
		[ 'before the debounce', false ],
	] )( 'keeps the final IAPI owner when readiness settles %s during an A-to-B-to-A change', async ( scenario, settleAfterDebounce ) => {
		const currency = createDeferred();
		const finalSelection = {
			id: 257,
			quantity: 1,
			variation: [ { attribute: 'attribute_pa_color', value: 'blue' } ],
		};
		void scenario;
		jest.useFakeTimers();
		setProductPage( { iapi: true } );
		window.wcpayAsyncCurrency = { ready: currency.promise };
		window.wp.apiFetch.mockResolvedValue(
			getResolvedProductCart( [ 'payment_request' ] )
		);

		require( '../woopayments-express-checkout' );
		await Promise.resolve();
		const selector = document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		);
		selector.innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();

		if ( settleAfterDebounce ) {
			jest.advanceTimersByTime( 250 );
		}

		currency.resolve( 'EUR' );
		selector.innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="blue" />';
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wc/store/v1/cart/add-item?currency=EUR',
				data: finalSelection,
			} )
		);
		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				amount: 4200,
				currency: 'eur',
				paymentMethodTypes: [ 'card' ],
				setupFutureUsage: 'off_session',
			} )
		);
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
	} );

	test.each( [
		[ 'valid product methods', [ 'payment_request' ], {}, [ 'card' ], true ],
		[
			'valid localized Amazon Pay',
			[ 'amazon_pay' ],
			{
				localizedMethods: [ 'amazon_pay' ],
				localizedTypes: [ 'amazon_pay' ],
			},
			[ 'amazon_pay' ],
			true,
		],
		[ 'an empty list', [], {}, null, false ],
		[ 'an absent list', 'absent', {}, null, false ],
		[ 'a malformed list', 'payment_request', {}, null, false ],
		[
			'duplicate methods',
			[ 'payment_request', 'payment_request' ],
			{},
			[ 'card' ],
			true,
		],
		[
			'mixed unknown methods',
			[ 'unknown', 'payment_request', 'unknown' ],
			{},
			[ 'card' ],
			true,
		],
		[
			'a checkout-only method',
			[ 'payment_request', 'amazon_pay' ],
			{},
			[ 'card' ],
			true,
		],
	] )(
		'uses only known localized product methods when the changed-currency preview returns %s',
		async ( scenario, methods, options, expectedTypes, mounts ) => {
			const cartData = getResolvedProductCart( methods );
			void scenario;
			setProductPage( options );
			window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
			if ( methods === 'absent' ) {
				delete cartData.extensions.wcpay.express_checkout_methods;
			}
			window.wp.apiFetch.mockResolvedValue( cartData );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
			if ( mounts ) {
				expect( stripe.elements ).toHaveBeenCalledWith(
					expect.objectContaining( {
						paymentMethodTypes: expectedTypes,
					} )
				);
				expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
				expect( elements.create ).toHaveBeenCalledWith(
					'expressCheckout',
					expect.objectContaining( {
						paymentMethods: expect.objectContaining( {
							amazonPay:
								expectedTypes.indexOf( 'amazon_pay' ) !== -1
									? 'auto'
									: 'never',
						} ),
					} )
				);
			} else {
				expect( stripe.elements ).not.toHaveBeenCalled();
				expect( expressElement.mount ).not.toHaveBeenCalled();
			}
		}
	);

	test( 'keeps the mounted product method identity when a later IAPI preview changes methods', async () => {
		const firstCart = getResolvedProductCart( [ 'payment_request' ] );
		const nextCart = getResolvedProductCart( [ 'amazon_pay' ] );
		const resolveClick = jest.fn();
		jest.useFakeTimers();
		setProductPage( { iapi: true } );
		nextCart.totals.total_price = '5000';
		nextCart.extensions.subscriptions = [];
		window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
		window.wp.apiFetch
			.mockResolvedValueOnce( firstCart )
			.mockResolvedValueOnce( nextCart )
			.mockResolvedValueOnce( nextCart )
			.mockResolvedValueOnce( { payment_result: {} } );

		require( '../woopayments-express-checkout' );
		await flushMicrotasks();
		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		expect( elements.update ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 5000 } )
		);
		expect( stripe.elements ).toHaveBeenCalledTimes( 1 );
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
		expect( elements.create ).toHaveBeenCalledWith(
			'expressCheckout',
			expect.objectContaining( {
				paymentMethods: expect.objectContaining( { amazonPay: 'never' } ),
			} )
		);

		await expressHandlers.click( { resolve: resolveClick } );
		await Promise.resolve();
		await expressHandlers.confirm( {
			billingDetails: {
				email: 'shopper@example.test',
				name: 'Ada Lovelace',
			},
		} );

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				data: expect.objectContaining( {
					payment_data: expect.arrayContaining( [
						{
							key: 'wcpay-express-payment-method-types',
							value: JSON.stringify( [ 'card' ] ),
						},
					] ),
				} ),
			} )
		);
	} );

	test( 'replaces a pending initial IAPI preview when its live selection changes before the response', async () => {
		const bluePreview = createDeferred();
		const redCart = getResolvedProductCart( [ 'payment_request' ] );
		const resolveClick = jest.fn();
		setProductPage( { iapi: true } );
		redCart.needs_shipping = false;
		redCart.totals.total_price = '5000';
		window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
		window.wp.apiFetch
			.mockReturnValueOnce( bluePreview.promise )
			.mockResolvedValueOnce( redCart )
			.mockResolvedValueOnce( redCart );

		require( '../woopayments-express-checkout' );
		await flushMicrotasks();
		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				data: {
					id: 257,
					quantity: 1,
					variation: [
						{ attribute: 'attribute_pa_color', value: 'blue' },
					],
				},
			} )
		);

		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await flushMicrotasks();
		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );

		bluePreview.resolve( getResolvedProductCart( [ 'payment_request' ] ) );
		await flushMicrotasks();
		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				data: {
					id: 257,
					quantity: 1,
					variation: [
						{ attribute: 'attribute_pa_color', value: 'red' },
					],
				},
			} )
		);
		await flushMicrotasks();

		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				amount: 5000,
				setupFutureUsage: 'off_session',
			} )
		);
		expect( stripe.elements ).not.toHaveBeenCalledWith(
			expect.objectContaining( { amount: 4200 } )
		);
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );

		await expressHandlers.click( { resolve: resolveClick } );
		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( { shippingAddressRequired: false } )
		);
	} );

	test( 'replaces a rejected pending initial IAPI preview when its live selection changes', async () => {
		const bluePreview = createDeferred();
		const redCart = getResolvedProductCart( [ 'payment_request' ] );
		setProductPage( { iapi: true } );
		redCart.totals.total_price = '5000';
		window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
		window.wp.apiFetch
			.mockReturnValueOnce( bluePreview.promise )
			.mockResolvedValueOnce( redCart );

		require( '../woopayments-express-checkout' );
		await flushMicrotasks();
		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await flushMicrotasks();
		bluePreview.reject( new Error( 'Blue preview rejected' ) );
		await flushMicrotasks();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				data: expect.objectContaining( {
					variation: [
						{ attribute: 'attribute_pa_color', value: 'red' },
					],
				} ),
			} )
		);
		expect( stripe.elements ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 5000 } )
		);
		expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'does not replace or mount a pending initial IAPI preview when the final selection is invalid', async () => {
		const bluePreview = createDeferred();
		setProductPage( { iapi: true } );
		window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
		window.wp.apiFetch.mockReturnValueOnce( bluePreview.promise );

		require( '../woopayments-express-checkout' );
		await flushMicrotasks();
		document
			.querySelector( 'form.wp-block-add-to-cart-with-options' )
			.classList.add( 'is-invalid' );
		bluePreview.resolve( getResolvedProductCart( [ 'payment_request' ] ) );
		await flushMicrotasks();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( stripe.elements ).not.toHaveBeenCalled();
		expect( expressElement.mount ).not.toHaveBeenCalled();
	} );

	test.each( [
		[ 'before the debounce', true ],
		[ 'after the debounce', false ],
	] )(
		'updates accepted A from pending B when B bounces through C back to B %s',
		async ( scenario, settleBeforeDebounce ) => {
			const pendingB = createDeferred();
			const bCart = getResolvedProductCart( [ 'amazon_pay' ] );
			const resolveClick = jest.fn();
			void scenario;
			jest.useFakeTimers();
			setProductPage( { iapi: true } );
			bCart.needs_shipping = false;
			bCart.totals.total_price = '5000';
			window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
			window.wp.apiFetch
				.mockResolvedValueOnce(
					getResolvedProductCart( [ 'payment_request' ] )
				)
				.mockReturnValueOnce( pendingB.promise );

			require( '../woopayments-express-checkout' );
			await flushMicrotasks();
			setIapiColor( 'red' );
			await flushMicrotasks();
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();
			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );

			setIapiColor( 'green' );
			await flushMicrotasks();
			setIapiColor( 'red' );
			await flushMicrotasks();
			if ( settleBeforeDebounce ) {
				pendingB.resolve( bCart );
				await flushMicrotasks();
				jest.advanceTimersByTime( 250 );
			} else {
				jest.advanceTimersByTime( 250 );
				await flushMicrotasks();
				pendingB.resolve( bCart );
			}
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 5000 } )
			);
			expect( elements.update ).not.toHaveBeenCalledWith(
				expect.objectContaining( { amount: 4200 } )
			);
			expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
			expect( elements.create ).toHaveBeenCalledWith(
				'expressCheckout',
				expect.objectContaining( {
					paymentMethods: expect.objectContaining( {
						amazonPay: 'never',
					} ),
				} )
			);
			await expressHandlers.click( { resolve: resolveClick } );
			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( { shippingAddressRequired: false } )
			);
		}
	);

	test.each( [
		[ 'before the debounce', true ],
		[ 'after the debounce', false ],
	] )(
		'updates accepted A from stable C once when pending B settles %s',
		async ( scenario, settleBeforeDebounce ) => {
			const pendingB = createDeferred();
			const cCart = getResolvedProductCart( [ 'amazon_pay' ] );
			void scenario;
			jest.useFakeTimers();
			setProductPage( { iapi: true } );
			cCart.totals.total_price = '6000';
			window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
			window.wp.apiFetch
				.mockResolvedValueOnce(
					getResolvedProductCart( [ 'payment_request' ] )
				)
				.mockReturnValueOnce( pendingB.promise )
				.mockResolvedValueOnce( cCart );

			require( '../woopayments-express-checkout' );
			await flushMicrotasks();
			setIapiColor( 'red' );
			await flushMicrotasks();
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();
			setIapiColor( 'green' );
			await flushMicrotasks();
			if ( settleBeforeDebounce ) {
				pendingB.resolve( getResolvedProductCart( [ 'payment_request' ] ) );
				await flushMicrotasks();
				jest.advanceTimersByTime( 250 );
			} else {
				jest.advanceTimersByTime( 250 );
				await flushMicrotasks();
				pendingB.resolve( getResolvedProductCart( [ 'payment_request' ] ) );
			}
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 3 );
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					data: expect.objectContaining( {
						variation: [
							{ attribute: 'attribute_pa_color', value: 'green' },
						],
					} ),
				} )
			);
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 6000 } )
			);
			expect( elements.update ).not.toHaveBeenCalledWith(
				expect.objectContaining( { amount: 4200 } )
			);
		}
	);

	test( 'does not preview accepted A when the form bounces through C back to A', async () => {
		jest.useFakeTimers();
		setProductPage( { iapi: true } );
		window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
		window.wp.apiFetch.mockResolvedValue(
			getResolvedProductCart( [ 'payment_request' ] )
		);

		require( '../woopayments-express-checkout' );
		await flushMicrotasks();
		setIapiColor( 'green' );
		setIapiColor( 'blue' );
		await flushMicrotasks();
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( elements.update ).not.toHaveBeenCalled();
	} );

	test.each( [
		[ 'after current preview mounts', false ],
		[ 'before current preview mounts', true ],
	] )(
		'installs observation from repeated initialization when stale startup settles %s',
		async ( scenario, settleStartupFirst ) => {
			const startupPreview = createDeferred();
			const currentPreview = createDeferred();
			const bCart = getResolvedProductCart( [ 'payment_request' ] );
			void scenario;
			jest.useFakeTimers();
			setProductPage( { iapi: true } );
			bCart.totals.total_price = '5000';
			window.wcpayAsyncCurrency = { ready: Promise.resolve( 'EUR' ) };
			window.wp.apiFetch
				.mockReturnValueOnce( startupPreview.promise )
				.mockReturnValueOnce( currentPreview.promise )
				.mockResolvedValueOnce( bCart );

			require( '../woopayments-express-checkout' );
			await flushMicrotasks();
			bodyEventHandlers.updated_cart_totals();
			await flushMicrotasks();

			if ( settleStartupFirst ) {
				startupPreview.resolve(
					getResolvedProductCart( [ 'payment_request' ] )
				);
				await flushMicrotasks();
				setIapiColor( 'red' );
				await flushMicrotasks();
				currentPreview.resolve(
					getResolvedProductCart( [ 'payment_request' ] )
				);
			} else {
				currentPreview.resolve(
					getResolvedProductCart( [ 'payment_request' ] )
				);
				await flushMicrotasks();
				setIapiColor( 'red' );
				await flushMicrotasks();
				startupPreview.resolve(
					getResolvedProductCart( [ 'payment_request' ] )
				);
			}
			await flushMicrotasks();
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 3 );
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					data: expect.objectContaining( {
						variation: [
							{ attribute: 'attribute_pa_color', value: 'red' },
						],
					} ),
				} )
			);
			expect( stripe.elements ).toHaveBeenCalledTimes( 1 );
			if ( settleStartupFirst ) {
				expect( stripe.elements ).toHaveBeenCalledWith(
					expect.objectContaining( { amount: 5000 } )
				);
			} else {
				expect( elements.update ).toHaveBeenCalledWith(
					expect.objectContaining( { amount: 5000 } )
				);
			}
		}
	);

	test( 'adds the selected product to a separate tokenized cart before resolving product page click', async () => {
		const resolveClick = jest.fn();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<input type="number" name="quantity" class="qty" value="1.5" />' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch.mockResolvedValue( {
			needs_shipping: false,
			totals: {
				total_price: '3000',
				total_refund: '0',
				currency_code: 'USD',
			},
		} );

		require( '../woopayments-express-checkout' );
		await flushPromises();

		await expressHandlers.click( { resolve: resolveClick } );
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/add-item?currency=USD',
				parse: false,
				headers: expect.objectContaining( {
					Nonce: 'store-api-nonce',
					'X-WooPayments-Tokenized-Cart-Nonce': 'cart-nonce',
					'X-WooPayments-Tokenized-Cart-Session-Nonce':
						'cart-session-nonce',
					'X-WooPayments-Tokenized-Cart-Session': '',
				} ),
				data: expect.objectContaining( {
					id: 123,
					quantity: 1.5,
					variation: [],
				} ),
			} )
		);
		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( {
				shippingAddressRequired: false,
			} )
		);
	} );

	test( 'adds an IAPI variation with its parent product ID and current form attributes', async () => {
		const resolveClick = jest.fn();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<input name="attribute_pa_color" value="outside-form" />' +
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'<input type="hidden" name="attribute_size" value="large" />' +
			'<input type="hidden" name="attribute_empty" value="" />' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};
		window.wp.apiFetch.mockResolvedValue( {
			needs_shipping: false,
			totals: {
				total_price: '3000',
				total_refund: '0',
				currency_code: 'USD',
			},
		} );

		require( '../woopayments-express-checkout' );
		await flushPromises();

		await expressHandlers.click( { resolve: resolveClick } );
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/add-item?currency=USD',
				data: {
					id: 257,
					quantity: 1,
					variation: [
						{ attribute: 'attribute_pa_color', value: 'blue' },
						{ attribute: 'attribute_size', value: 'large' },
					],
				},
			} )
		);
		expect( resolveClick ).toHaveBeenCalled();
	} );

	test( 'does not resolve an IAPI product click or add an invalid form to the cart', async () => {
		const resolveClick = jest.fn();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="wp-block-add-to-cart-with-options is-invalid">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="" />' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};

		require( '../woopayments-express-checkout' );
		await flushPromises();

		await expressHandlers.click( { resolve: resolveClick } );

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( resolveClick ).not.toHaveBeenCalled();
	} );

	test( 'refreshes the IAPI product preview once after its selected attributes are replaced', async () => {
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'</div>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};
		window.wp.apiFetch.mockResolvedValue( {
			needs_shipping: false,
			totals: {
				total_price: '4000',
				total_refund: '0',
				currency_code: 'USD',
			},
		} );

		require( '../woopayments-express-checkout' );
		await Promise.resolve();

		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await Promise.resolve();
		await Promise.resolve();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/add-item?currency=USD',
				headers: expect.objectContaining( {
					'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
				} ),
				data: {
					id: 257,
					quantity: 1,
					variation: [
						{ attribute: 'attribute_pa_color', value: 'red' },
					],
				},
			} )
		);
		expect( elements.update ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 4000 } )
		);
	} );

	test( 'does not retain an ephemeral IAPI preview session for a later product click', async () => {
		const resolveClick = jest.fn();
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'</div>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: false,
						totals: {
							total_price: '4000',
							total_refund: '0',
							currency_code: 'USD',
						},
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'ephemeral-preview-session',
					}
				)
			)
			.mockResolvedValueOnce(
				getStoreApiResponse( {
					needs_shipping: false,
					totals: {
						total_price: '4000',
						total_refund: '0',
						currency_code: 'USD',
					},
				} )
			);

		require( '../woopayments-express-checkout' );
		await Promise.resolve();

		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await Promise.resolve();
		await Promise.resolve();

		await expressHandlers.click( { resolve: resolveClick } );
		await Promise.resolve();
		await Promise.resolve();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( window.wp.apiFetch.mock.calls[ 1 ][ 0 ].headers ).toMatchObject(
			{
				'X-WooPayments-Tokenized-Cart-Session': '',
			}
		);
	} );

	test( 'does not preview an IAPI selection after its express checkout click starts', async () => {
		const resolveClick = jest.fn();
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'</div>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};
		window.wp.apiFetch.mockResolvedValue(
			getStoreApiResponse( {
				needs_shipping: false,
				totals: {
					total_price: '4000',
					total_refund: '0',
					currency_code: 'USD',
				},
			} )
		);

		require( '../woopayments-express-checkout' );
		await Promise.resolve();

		document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		).innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();

		await expressHandlers.click( { resolve: resolveClick } );
		jest.advanceTimersByTime( 250 );
		await Promise.resolve();
		await Promise.resolve();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( window.wp.apiFetch.mock.calls[ 0 ][ 0 ].headers ).not.toHaveProperty(
			'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart'
		);
		expect( resolveClick ).toHaveBeenCalled();
	} );

	test( 'ignores a stale IAPI preview response after a newer selection refreshes the amount', async () => {
		const firstPreview = createDeferred();
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="wp-block-add-to-cart-with-options">' +
			'<input type="hidden" name="add-to-cart" value="257" />' +
			'<input type="hidden" name="product_id" value="257" />' +
			'<input type="hidden" name="variation_id" value="263" />' +
			'<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">' +
			'<input type="hidden" name="attribute_pa_color" value="blue" />' +
			'</div>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Variable Widget', amount: 2500 } ],
			total: { label: 'Variable Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'variable',
		};
		window.wp.apiFetch
			.mockReturnValueOnce( firstPreview.promise )
			.mockResolvedValueOnce( {
				needs_shipping: false,
				totals: {
					total_price: '5000',
					total_refund: '0',
					currency_code: 'USD',
				},
			} );

		require( '../woopayments-express-checkout' );
		await Promise.resolve();

		const selector = document.querySelector(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		);
		selector.innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="red" />';
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await Promise.resolve();

		selector.innerHTML =
			'<input type="hidden" name="attribute_pa_color" value="green" />';
		await Promise.resolve();
		jest.advanceTimersByTime( 250 );
		await Promise.resolve();
		await Promise.resolve();

		firstPreview.resolve(
			getStoreApiResponse( {
				needs_shipping: false,
				totals: {
					total_price: '4000',
					total_refund: '0',
					currency_code: 'USD',
				},
			} )
		);
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();

		expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( elements.update ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 5000 } )
		);
		expect( elements.update ).not.toHaveBeenCalledWith(
			expect.objectContaining( { amount: 4000 } )
		);
	} );

	test( 'resolves product page click before the isolated cart request completes', async () => {
		const resolveClick = jest.fn();
		const addToCart = createDeferred();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch.mockReturnValue( addToCart.promise );

		require( '../woopayments-express-checkout' );
		await flushPromises();

		expressHandlers.click( { resolve: resolveClick } );
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/add-item?currency=USD',
			} )
		);
		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( {
				shippingAddressRequired: false,
			} )
		);

		addToCart.resolve(
			getStoreApiResponse(
				{
					needs_shipping: false,
					totals: {
						total_price: '3000',
						total_refund: '0',
						currency_code: 'USD',
					},
				},
				{
					'X-WooPayments-Tokenized-Cart-Session':
						'cart-session-token',
				}
			)
		);
		await flushPromises();

		expect( elements.update ).toHaveBeenCalledWith( {
			amount: 3000,
			setupFutureUsage: null,
		} );
	} );

	test( 'carries the product tokenized cart session into checkout', async () => {
		const resolveClick = jest.fn();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<input type="number" name="quantity" class="qty" value="1" />' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: false,
						totals: {
							total_price: '3000',
							total_refund: '0',
							currency_code: 'USD',
						},
					},
					{
						Nonce: 'store-api-nonce-2',
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			)
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						payment_result: {
							payment_status: 'success',
						},
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			);

		require( '../woopayments-express-checkout' );
		await flushPromises();

		await expressHandlers.click( { resolve: resolveClick } );
		await flushPromises();
		await expressHandlers.confirm( {
			billingDetails: {
				email: 'shopper@example.test',
				name: 'Ada Lovelace',
			},
		} );

		expect( elements.update ).toHaveBeenCalledWith( {
			amount: 3000,
			setupFutureUsage: null,
		} );
		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/checkout?currency=USD',
				parse: false,
				data: expect.objectContaining( {
					billing_address: expect.objectContaining( {
						first_name: 'Ada',
						last_name: 'Lovelace',
					} ),
				} ),
				headers: expect.objectContaining( {
					Nonce: 'store-api-nonce-2',
					'X-WooPayments-Tokenized-Cart': true,
					'X-WooPayments-Tokenized-Cart-Session-Nonce':
						'cart-session-nonce',
					'X-WooPayments-Tokenized-Cart-Session':
						'cart-session-token',
				} ),
			} )
		);
	} );

	test( 'deletes the isolated product cart session on cancel', async () => {
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: false,
						totals: {
							total_price: '2500',
							total_refund: '0',
							currency_code: 'USD',
						},
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			)
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						items: [],
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			);

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await expressHandlers.click( { resolve: jest.fn() } );
		await flushPromises();

		expressHandlers.cancel();
		await flushPromises();
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'GET',
				path: '/wc/store/v1/cart?currency=USD',
				parse: false,
				headers: expect.objectContaining( {
					'X-WooPayments-Tokenized-Cart-Session':
						'cart-session-token',
					'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
				} ),
			} )
		);
	} );

	test( 'deletes the isolated product cart session when product amount update fails', async () => {
		const resolveClick = jest.fn();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: false,
						totals: {
							total_price: '3000',
							total_refund: '0',
							currency_code: 'USD',
						},
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			)
			.mockResolvedValueOnce( getStoreApiResponse( { items: [] }, {} ) );
		elements.update.mockRejectedValue( new Error( 'update failed' ) );

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await expressHandlers.click( { resolve: resolveClick } );
		await flushPromises();
		await flushPromises();

		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( {
				emailRequired: true,
			} )
		);
		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'GET',
				path: '/wc/store/v1/cart?currency=USD',
				headers: expect.objectContaining( {
					'X-WooPayments-Tokenized-Cart-Session':
						'cart-session-token',
					'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
				} ),
			} )
		);
	} );

	test( 'deletes the isolated product cart session when product checkout fails after add-item', async () => {
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: false,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: false,
						totals: {
							total_price: '2500',
							total_refund: '0',
							currency_code: 'USD',
						},
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			)
			.mockRejectedValueOnce( new Error( 'checkout failed' ) )
			.mockResolvedValueOnce( getStoreApiResponse( { items: [] }, {} ) );

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await expressHandlers.click( { resolve: jest.fn() } );
		await flushPromises();
		await expressHandlers.confirm( {
			billingDetails: {
				email: 'shopper@example.test',
				name: 'Ada Lovelace',
			},
		} );
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'GET',
				path: '/wc/store/v1/cart?currency=USD',
				headers: expect.objectContaining( {
					'X-WooPayments-Tokenized-Cart-Session':
						'cart-session-token',
					'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
				} ),
			} )
		);
	} );

	test( 'updates the isolated product cart when the shipping address changes', async () => {
		const resolveShipping = jest.fn();
		const update = createDeferred();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: true,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: true,
						totals: {
							total_price: '2500',
							total_refund: '0',
							currency_code: 'USD',
						},
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			)
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: true,
						totals: {
							total_price: '3000',
							total_refund: '0',
							currency_code: 'USD',
						},
						items: [
							{
								name: 'Express Widget',
								quantity: 1,
								totals: {
									line_subtotal: '2500',
									line_subtotal_tax: '0',
								},
							},
						],
						shipping_rates: [
							{
								shipping_rates: [
									{
										rate_id: 'flat_rate:1',
										name: 'Flat rate',
										price: '500',
										taxes: '0',
										currency_minor_unit: 2,
										selected: true,
										meta_data: [],
									},
								],
							},
						],
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			);
		elements.update.mockReturnValue( update.promise );

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await expressHandlers.click( { resolve: jest.fn() } );
		await flushPromises();
		const shippingPromise = expressHandlers.shippingaddresschange( {
			address: {
				recipient: 'Ada Lovelace',
				addressLine: [ '1 Test Street', 'Unit 2' ],
				city: 'San Francisco',
				state: 'CA',
				postal_code: '94107',
				country: 'US',
			},
			resolve: resolveShipping,
			reject: jest.fn(),
		} );
		await flushPromises();

		expect( resolveShipping ).not.toHaveBeenCalled();
		update.resolve();
		await shippingPromise;
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/update-customer?currency=USD',
				data: expect.objectContaining( {
					shipping_address: expect.objectContaining( {
						first_name: 'Ada',
						last_name: 'Lovelace',
						address_1: '1 Test Street',
						address_2: 'Unit 2',
						city: 'San Francisco',
						state: 'CA',
						postcode: '94107',
						country: 'US',
					} ),
				} ),
			} )
		);
		expect( resolveShipping ).toHaveBeenCalledWith(
			expect.objectContaining( {
				shippingRates: [
					expect.objectContaining( {
						id: 'flat_rate:1',
						displayName: 'Flat rate',
						amount: 500,
					} ),
				],
			} )
		);
	} );

	test( 'selects the product cart shipping rate before confirmation', async () => {
		const resolveRate = jest.fn();
		const update = createDeferred();
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<form class="cart">' +
			'<button type="submit" name="add-to-cart" value="123">Add to cart</button>' +
			'</form>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		window.wcpayExpressCheckoutParams.button_context = 'product';
		window.wcpayExpressCheckoutParams.product = {
			displayItems: [ { label: 'Express Widget', amount: 2500 } ],
			total: { label: 'Express Widget', amount: 2500, pending: true },
			needs_shipping: true,
			currency: 'usd',
			country_code: 'US',
			product_type: 'simple',
		};
		window.wp.apiFetch
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: true,
						totals: {
							total_price: '3000',
							total_refund: '0',
							currency_code: 'USD',
						},
						items: [],
						shipping_rates: [],
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			)
			.mockResolvedValueOnce(
				getStoreApiResponse(
					{
						needs_shipping: true,
						totals: {
							total_price: '3500',
							total_refund: '0',
							currency_code: 'USD',
						},
						items: [
							{
								name: 'Express Widget',
								quantity: 1,
								totals: {
									line_subtotal: '2500',
									line_subtotal_tax: '0',
								},
							},
						],
					},
					{
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					}
				)
			);
		window.wp.hooks = {
			applyFilters: jest.fn( ( hookName, defaultValue ) =>
				hookName === 'wcpay.express-checkout.shipping-package-id'
					? 2
					: defaultValue
			),
		};
		elements.update
			.mockResolvedValueOnce( {} )
			.mockReturnValue( update.promise );

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await expressHandlers.click( { resolve: jest.fn() } );
		await flushPromises();
		const shippingRatePromise = expressHandlers.shippingratechange( {
			shippingRate: {
				id: 'local_pickup:2',
			},
			resolve: resolveRate,
			reject: jest.fn(),
		} );
		await flushPromises();

		expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'POST',
				path: '/wc/store/v1/cart/select-shipping-rate?currency=USD',
				data: {
					package_id: 2,
					rate_id: 'local_pickup:2',
				},
			} )
		);
		expect( window.wp.hooks.applyFilters ).toHaveBeenCalledWith(
			'wcpay.express-checkout.shipping-package-id',
			0,
			expect.any( Object ),
			'local_pickup:2'
		);
		expect( resolveRate ).not.toHaveBeenCalled();
		update.resolve();
		await shippingRatePromise;
		await flushPromises();
		expect( resolveRate ).toHaveBeenCalledWith(
			expect.objectContaining( {
				lineItems: expect.any( Array ),
			} )
		);
	} );

	test( 'does not initialize classic ECE on block checkout surfaces', async () => {
		window.wcpayExpressCheckoutParams.has_block = true;

		require( '../woopayments-express-checkout' );
		await flushPromises();

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( window.Stripe ).not.toHaveBeenCalled();
	} );
} );
