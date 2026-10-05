/**
 * @jest-environment jest-fixed-jsdom
 */

describe( 'WooPayments express checkout', () => {
	// The Express Checkout Element `ready` event in a browser with no wallet: `availablePaymentMethods` is an object
	// of booleans, one per button (link, applePay, googlePay, paypal, amazonPay, klarna), deprecated but still sent
	// (https://docs.stripe.com/js/element/events/on_ready).
	const READY_WITHOUT_A_WALLET = {
		elementType: 'expressCheckout',
		availablePaymentMethods: {
			link: false,
			applePay: false,
			googlePay: false,
			paypal: false,
			amazonPay: false,
			klarna: false,
		},
	};

	let bodyEventHandlers;
	let containerJQuery;
	let delegatedQuantityHandlers;
	let elements;
	let expressElement;
	let expressHandlers;
	let originalFetch;
	let stripe;
	let triggeredFieldEvents;

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

	// `$( '.quantity' ).on( events, selector, handler )` as jQuery delegates it: a native event, or a jQuery
	// `.trigger()`, on a matching field inside a wrapper calls the handler with the field as `this`.
	function createQuantityJQuery() {
		const wrappers = Array.from( document.querySelectorAll( '.quantity' ) );
		const result = {
			length: wrappers.length,
			on: jest.fn( ( events, selector, handler ) => {
				wrappers.forEach( ( wrapper ) => {
					events.split( ' ' ).forEach( ( type ) => {
						delegatedQuantityHandlers.push( {
							wrapper,
							type,
							selector,
							handler,
						} );
						wrapper.addEventListener( type, ( event ) => {
							const field = event.target.closest( selector );

							if ( field && wrapper.contains( field ) ) {
								handler.call( field, event );
							}
						} );
					} );
				} );
				return result;
			} ),
		};

		return result;
	}

	// jQuery's `$( field ).trigger( type )` for an event the element has no native method for (`input`, `change`):
	// only jQuery handlers run, no DOM event is dispatched.
	function triggerThroughJQuery( field, type ) {
		delegatedQuantityHandlers.forEach( ( entry ) => {
			if (
				entry.type === type &&
				entry.wrapper.contains( field ) &&
				field.matches( entry.selector )
			) {
				entry.handler.call( field, { type, target: field } );
			}
		} );
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

			if ( selectorOrCallback === '#wcpay-express-checkout-element' ) {
				return containerJQuery;
			}

			if ( selectorOrCallback === '.quantity' ) {
				return createQuantityJQuery();
			}

			// `$( field ).trigger( type )`: records which jQuery events the script fires on a form field, chainable as
			// in jQuery.
			if ( selectorOrCallback instanceof window.Element ) {
				const fieldResult = {
					trigger: jest.fn( ( type ) => {
						triggeredFieldEvents.push( [
							selectorOrCallback.name,
							type,
						] );
						return fieldResult;
					} ),
				};
				return fieldResult;
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

	// Store API cart response (docs/apis/store-api/resources-endpoints/cart.md, "Cart Response";
	// src/StoreApi/Schemas/V1/CartSchema.php), read through `wp.apiFetch` from /wc/store/v1/cart. Reduced to what these
	// tests drive: items, coupons, fees, shipping_rates, needs_payment, payment_requirements, has_calculated_shipping,
	// items_count, items_weight, cross_sells, errors, payment_methods and extensions are left out, and totals keep only
	// total_price and currency_code (the other totals and the currency format fields are left out). Addresses omit
	// company, address_2 and phone.
	function getCartResponse() {
		return {
			needs_shipping: true,
			totals: {
				total_price: '5000',
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

	// Store API order (GET /wc/store/v1/order/{id}, docs/apis/store-api/resources-endpoints/order.md); unlike the cart,
	// its totals carry `total_refund` (src/StoreApi/Schemas/V1/OrderSchema.php).
	function getOrderPayResponse() {
		var cartResponse = getCartResponse();

		return Object.assign( {}, cartResponse, {
			needs_shipping: false,
			totals: Object.assign( {}, cartResponse.totals, {
				total_refund: '0',
			} ),
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
		delegatedQuantityHandlers = [];
		triggeredFieldEvents = [];
		expressHandlers = {};
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';

		containerJQuery = {
			length: 1,
			block: jest.fn(),
			unblock: jest.fn(),
			data: jest.fn(),
		};
		window.alert = jest.fn();
		const jQueryMock = createJQueryMock();
		// jQuery BlockUI's page-level `$.blockUI( options )` / `$.unblockUI()`, loaded by WooCommerce's `woocommerce`
		// script (`wc-jquery-blockui`, includes/class-wc-frontend-scripts.php); both return nothing useful.
		jQueryMock.blockUI = jest.fn();
		jQueryMock.unblockUI = jest.fn();
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
			// `elements.submit()` resolves `{ selectedPaymentMethod }` on success and `{ error }` when the wallet details
			// fail validation (https://docs.stripe.com/js/elements/submit); the script reads only `error`, so the success
			// answer is reduced to `{}`.
			submit: jest.fn().mockResolvedValue( {} ),
			// The pages load https://js.stripe.com/v3/, whose `elements.update()` returns nothing: "Starting in
			// Stripe.js dahlia, this method returns a Promise" (https://docs.stripe.com/js/elements_object/update),
			// and a wrapper around `window.Stripe` on a local store recorded `undefined` as its return value. Tests
			// that model dahlia's promise set it explicitly.
			update: jest.fn(),
		};
		stripe = {
			elements: jest.fn( () => elements ),
			// `stripe.createConfirmationToken()` resolves `{ confirmationToken }` or `{ error }`
			// (https://docs.stripe.com/js/confirmation_tokens/create_confirmation_token); the ConfirmationToken object
			// (https://docs.stripe.com/api/confirmation_tokens/object) is reduced to its `id`, the only field the script sends.
			createConfirmationToken: jest.fn().mockResolvedValue( {
				confirmationToken: {
					id: 'ctoken_123',
				},
			} ),
		};
		window.Stripe = jest.fn( () => stripe );
		window.fetch = jest.fn().mockResolvedValue( {} );
		// jsdom does not implement scrolling.
		window.HTMLElement.prototype.scrollIntoView = jest.fn();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.HTMLElement.prototype.scrollIntoView;
		delete global.jQuery;
		delete global.$;
		delete window.jQuery;
		delete window.$;
		delete window.wp;
		delete window.Stripe;
		delete window.wcpayFraudPreventionToken;
		delete window.wcpayAsyncCurrency;
		delete window.wc_add_to_cart_variation_params;
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

	// The server renders the separator visible next to a WooPay button and hidden otherwise (client 11.1.0
	// class-wc-payments-express-checkout-button-display-handler.php:115, :131); the client script changes it only when
	// the wallet reports `ready` with a method (shortcode-buttons-express/index.js:448-460) or a cart is not eligible.
	test( 'keeps the separator of a WooPay button in a browser without a wallet', async () => {
		document.body.innerHTML =
			'<div class="woocommerce-notices-wrapper"></div>' +
			'<div class="wcpay-express-checkout-wrapper">' +
			'<div id="wcpay-woopay-button"></div>' +
			'<div id="wcpay-express-checkout-element"></div>' +
			'<p id="wcpay-express-checkout-button-separator">OR</p>' +
			'</div>';
		require( '../woopayments-express-checkout' );
		await bodyEventHandlers.updated_checkout();
		await flushPromises();
		expressHandlers.ready( READY_WITHOUT_A_WALLET );

		expect(
			document.getElementById( 'wcpay-express-checkout-button-separator' )
				.hidden
		).toBe( false );
	} );

	// Client 11.1.0 shows the wallet and its separator only when `ready` reports at least one available method
	// (shortcode-buttons-express/index.js:451-459, a count of the `true` values).
	test( 'keeps the wallet hidden when the ready event reports no available method', async () => {
		require( '../woopayments-express-checkout' );
		await bodyEventHandlers.updated_checkout();
		await flushPromises();
		document.getElementById(
			'wcpay-express-checkout-button-separator'
		).hidden = true;

		expressHandlers.ready( READY_WITHOUT_A_WALLET );

		expect(
			document
				.getElementById( 'wcpay-express-checkout-element' )
				.classList.contains( 'is-ready' )
		).toBe( false );
		expect(
			document.getElementById( 'wcpay-express-checkout-button-separator' )
				.hidden
		).toBe( true );
	} );

	// Store API cart shape: docs/apis/store-api/resources-endpoints/cart.md ("Cart Response": needs_shipping,
	// shipping_rates[].shipping_rates[] with rate_id, price, selected; totals.total_price).
	function getCartWithShippingRate( total ) {
		return Object.assign( getCartResponse(), {
			totals: {
				total_price: String( total ),
				currency_code: 'USD',
			},
			items: [],
			shipping_rates: [
				{
					shipping_rates: [
						{
							rate_id: 'flat_rate:1',
							name: 'Flat rate',
							price: '500',
							taxes: '0',
							selected: true,
							meta_data: [],
						},
					],
				},
			],
		} );
	}

	// Event payloads as Stripe documents them: `shippingaddresschange` carries name, address, resolve and reject
	// (https://docs.stripe.com/js/elements_object/express_checkout_element_shippingaddresschange_event), and
	// `shippingratechange` carries shippingRate, resolve and reject (https://docs.stripe.com/js.md, "Handle
	// shippingratechange event"). The client
	// awaits `elements.update()` in both handlers (event-handlers.js:122, :169), which accepts v3's `undefined`.
	test( 'accepts a wallet address change on classic checkout when Stripe\'s update returns nothing', async () => {
		const resolveShipping = jest.fn();
		const rejectShipping = jest.fn();
		window.wp.apiFetch
			.mockResolvedValueOnce( getCartResponse() )
			.mockResolvedValueOnce( getCartWithShippingRate( 5500 ) );
		require( '../woopayments-express-checkout' );
		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		await expressHandlers.shippingaddresschange( {
			name: 'Ada Lovelace',
			address: {
				city: 'San Francisco',
				state: 'CA',
				postal_code: '94107',
				country: 'US',
			},
			resolve: resolveShipping,
			reject: rejectShipping,
		} );

		expect( elements.update ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 5500 } )
		);
		expect( rejectShipping ).not.toHaveBeenCalled();
		expect( resolveShipping ).toHaveBeenCalledWith(
			expect.objectContaining( {
				shippingRates: [
					expect.objectContaining( { id: 'flat_rate:1', amount: 500 } ),
				],
			} )
		);
	} );

	test( 'accepts a wallet shipping rate change on classic checkout when Stripe\'s update returns nothing', async () => {
		const resolveRate = jest.fn();
		const rejectRate = jest.fn();
		window.wp.apiFetch
			.mockResolvedValueOnce( getCartResponse() )
			.mockResolvedValueOnce( getCartWithShippingRate( 5500 ) );
		require( '../woopayments-express-checkout' );
		await bodyEventHandlers.updated_checkout();
		await flushPromises();

		await expressHandlers.shippingratechange( {
			shippingRate: { id: 'flat_rate:1', amount: 500, displayName: 'Flat rate' },
			resolve: resolveRate,
			reject: rejectRate,
		} );

		expect( elements.update ).toHaveBeenCalledWith(
			expect.objectContaining( { amount: 5500 } )
		);
		expect( rejectRate ).not.toHaveBeenCalled();
		expect( resolveRate ).toHaveBeenCalledWith(
			expect.objectContaining( { lineItems: expect.any( Array ) } )
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

	// The Store API checkout response for a payment that needs a next action:
	// esc_url_raw() empties the bare hash in redirect_url (PaymentResult), and
	// the raw gateway result stays in payment_details (StoreApi\Legacy::process_legacy_payment(),
	// src/StoreApi/Legacy.php:79-81).
	function getConfirmationCheckoutResponse( orderId ) {
		return {
			order_id: orderId,
			status: 'pending',
			payment_result: {
				payment_status: 'success',
				payment_details: [
					{ key: 'result', value: 'success' },
					{
						key: 'redirect',
						value:
							'#wcpay-confirm-pi:' +
							orderId +
							':pi_3ds_secret_abc:nonce-3ds',
					},
					{ key: 'payment_method', value: 'pm_3ds_card' },
				],
				redirect_url: '',
			},
		};
	}

	function mockOrderStatusUpdate( returnUrl ) {
		window.fetch = jest.fn( ( url, options ) =>
			Promise.resolve(
				options &&
					options.body &&
					options.body.get &&
					options.body.get( 'action' ) === 'update_order_status'
					? { json: () => Promise.resolve( { return_url: returnUrl } ) }
					: {}
			)
		);
	}

	function getOrderStatusUpdateBody() {
		const call = window.fetch.mock.calls.find(
			( [ , options ] ) =>
				options &&
				options.body &&
				options.body.get &&
				options.body.get( 'action' ) === 'update_order_status'
		);

		return call ? call[ 1 ].body : null;
	}

	test.each( [
		[ 'classic checkout', 'checkout', 77 ],
		[ 'pay-for-order', 'pay_for_order', 123 ],
	] )(
		'confirms a %s wallet payment that needs 3DS from the Store API payment details',
		async ( surface, context, orderId ) => {
			const navigate = jest.fn();
			stripe.handleNextAction = jest.fn().mockResolvedValue( {
				paymentIntent: { id: 'pi_3ds', status: 'succeeded' },
			} );
			mockOrderStatusUpdate(
				'https://example.test/checkout/order-received/' + orderId + '/'
			);
			if ( context === 'pay_for_order' ) {
				window.wcpayExpressCheckoutParams.button_context =
					'pay_for_order';
				window.wcpayExpressCheckoutParams.order_id = orderId;
				window.wcpayExpressCheckoutParams.pay_for_order = 'true';
				window.wcpayExpressCheckoutParams.key = 'wc_order_key_123';
				window.wcpayExpressCheckoutParams.billing_email =
					'order@example.test';
				window.wp.apiFetch
					.mockResolvedValueOnce( getOrderPayResponse() )
					.mockResolvedValueOnce(
						getConfirmationCheckoutResponse( orderId )
					);
			} else {
				window.wp.apiFetch
					.mockResolvedValueOnce( getCartResponse() )
					.mockResolvedValueOnce(
						getConfirmationCheckoutResponse( orderId )
					);
			}
			require( '../woopayments-express-checkout' ).__test__.setNavigate(
				navigate
			);

			if ( context === 'checkout' ) {
				await bodyEventHandlers.updated_checkout();
			}
			await flushPromises();

			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );

			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					method: 'POST',
					path:
						context === 'pay_for_order'
							? '/wc/store/v1/checkout/' + orderId
							: '/wc/store/v1/checkout?currency=USD',
				} )
			);
			expect( stripe.handleNextAction ).toHaveBeenCalledWith( {
				clientSecret: 'pi_3ds_secret_abc',
			} );
			const body = getOrderStatusUpdateBody();
			expect( body.get( 'order_id' ) ).toBe( String( orderId ) );
			expect( body.get( '_ajax_nonce' ) ).toBe( 'nonce-3ds' );
			expect( body.get( 'intent_id' ) ).toBe( 'pi_3ds' );
			expect( navigate ).toHaveBeenCalledWith(
				'https://example.test/checkout/order-received/' + orderId + '/'
			);
			expect(
				document.querySelector( '.woocommerce-notices-wrapper' )
					.textContent
			).toBe( '' );
		}
	);

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
			.mockResolvedValueOnce( {
				payment_result: { payment_status: 'success', payment_details: [] },
			} );

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

		// The client keeps the click's add-item answer out of Elements (shortcode-buttons-express/index.js:321).
		expect( elements.update ).not.toHaveBeenCalled();
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

	// Client 11.1.0 getOnClickOptions() (shortcode-buttons-express/index.js:110-127) opens the first sheet from the
	// server product data, with the pending rate only when an address is needed (:348-385), and the click leaves
	// Elements alone (:321). Expected payload as recorded on a local client 11.1.0 store for a $15 variation:
	// `[Medium 1500, Shipping 0]`, shipping required, amount unchanged. Click event: event.resolve(payload)
	// (https://docs.stripe.com/js.md, "expressCheckoutElement.on('click', handler)").
	test( 'opens the first sheet of a shippable product from the server product data', async () => {
		const resolveClick = jest.fn();
		setProductPage();
		window.wcpayExpressCheckoutParams.product.needs_shipping = true;
		window.wcpayExpressCheckoutParams.product.displayItems = [
			{ label: 'Express Widget', amount: 2500 },
			{ label: 'Shipping', amount: 0, pending: true },
		];
		// Store API add-item answer for the tokenized cart, with the default address's shipping (cart.md "Add Item").
		window.wp.apiFetch.mockResolvedValue(
			getStoreApiResponse(
				{
					needs_shipping: true,
					totals: {
						total_price: '4500',
						currency_code: 'USD',
					},
					items: [],
				},
				{ 'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token' }
			)
		);

		require( '../woopayments-express-checkout' );
		await flushPromises();
		await expressHandlers.click( { resolve: resolveClick } );
		await flushPromises();

		expect( resolveClick ).toHaveBeenCalledWith(
			expect.objectContaining( {
				shippingAddressRequired: true,
				shippingRates: [ { id: 'pending', displayName: 'Pending', amount: 0 } ],
				lineItems: [
					{ name: 'Express Widget', amount: 2500 },
					{ name: 'Shipping', amount: 0 },
				],
			} )
		);
		expect( elements.update ).not.toHaveBeenCalled();
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
		// Stripe.js dahlia: `elements.update()` returns a promise (https://docs.stripe.com/js/elements_object/update).
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

	// Client 11.1.0 event-handlers.js:130-132 and :173-175: a failed address or rate change only rejects it; the sheet
	// stays open on the same product cart, which is emptied on cancel (index.js:432-443).
	describe( 'a failed wallet shipping change on a product page', () => {
		// Store API cart with the product and one rate (cart.md "Cart Response"), as the tokenized cart answers.
		function getProductCartWithRate() {
			return getStoreApiResponse(
				Object.assign( getCartWithShippingRate( 3000 ), {
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
				} ),
				{ 'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token' }
			);
		}

		// With `parse: false` @wordpress/api-fetch rejects with the fetch Response for a non-2xx status
		// (api-fetch src/utils/response.js:72-74).
		const failedStoreApiResponse = { status: 500, ok: false };

		const address = {
			city: 'San Francisco',
			state: 'CA',
			postal_code: '94107',
			country: 'US',
		};

		async function openProductSheet() {
			setProductPage();
			window.wcpayExpressCheckoutParams.product.needs_shipping = true;
			require( '../woopayments-express-checkout' );
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );
			await flushPromises();
		}

		function getEphemeralCartRequests() {
			return window.wp.apiFetch.mock.calls.filter(
				( [ options ] ) =>
					options.headers &&
					options.headers[
						'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart'
					]
			);
		}

		// `shippingaddresschange`: name, address, resolve, reject
		// (https://docs.stripe.com/js/elements_object/express_checkout_element_shippingaddresschange_event).
		test( 'keeps the sheet on its product cart after an address change fails', async () => {
			const rejectFirst = jest.fn();
			const resolveSecond = jest.fn();
			window.wp.apiFetch
				.mockResolvedValueOnce( getProductCartWithRate() )
				.mockRejectedValueOnce( failedStoreApiResponse )
				.mockResolvedValueOnce( getProductCartWithRate() );
			await openProductSheet();

			await expressHandlers.shippingaddresschange( {
				name: 'Ada Lovelace',
				address,
				resolve: jest.fn(),
				reject: rejectFirst,
			} );
			await expressHandlers.shippingaddresschange( {
				name: 'Ada Lovelace',
				address,
				resolve: resolveSecond,
				reject: jest.fn(),
			} );

			expect( rejectFirst ).toHaveBeenCalled();
			expect( getEphemeralCartRequests() ).toHaveLength( 0 );
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/update-customer?currency=USD',
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					} ),
				} )
			);
			expect( resolveSecond ).toHaveBeenCalled();
		} );

		// `shippingratechange`: shippingRate, resolve, reject (https://docs.stripe.com/js.md, "Handle shippingratechange event").
		test( 'keeps the sheet on its product cart after a rate change fails', async () => {
			const rejectFirst = jest.fn();
			window.wp.apiFetch
				.mockResolvedValueOnce( getProductCartWithRate() )
				.mockRejectedValueOnce( failedStoreApiResponse )
				.mockResolvedValueOnce( getProductCartWithRate() );
			await openProductSheet();

			await expressHandlers.shippingratechange( {
				shippingRate: { id: 'flat_rate:1', amount: 500, displayName: 'Flat rate' },
				resolve: jest.fn(),
				reject: rejectFirst,
			} );
			await expressHandlers.shippingratechange( {
				shippingRate: { id: 'flat_rate:1', amount: 500, displayName: 'Flat rate' },
				resolve: jest.fn(),
				reject: jest.fn(),
			} );

			expect( rejectFirst ).toHaveBeenCalled();
			expect( getEphemeralCartRequests() ).toHaveLength( 0 );
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/select-shipping-rate?currency=USD',
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Session':
							'cart-session-token',
					} ),
				} )
			);
		} );
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
		// Stripe.js dahlia: `elements.update()` returns a promise (https://docs.stripe.com/js/elements_object/update).
		elements.update.mockReturnValue( update.promise );

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

	/**
	 * A minimal stand-in for `wp.hooks`: filters run in priority order, then in the order they were added.
	 *
	 * @return {Object} Hooks API with addFilter, applyFilters, removeFilter.
	 */
	function createWpHooks() {
		const filters = {};

		return {
			addFilter( hookName, namespace, callback, priority = 10 ) {
				filters[ hookName ] = ( filters[ hookName ] || [] )
					.concat( [ { namespace, callback, priority } ] )
					.sort( ( a, b ) => a.priority - b.priority );
			},
			removeFilter( hookName, namespace ) {
				filters[ hookName ] = ( filters[ hookName ] || [] ).filter(
					( filter ) => filter.namespace !== namespace
				);
			},
			applyFilters( hookName, value, ...args ) {
				return ( filters[ hookName ] || [] ).reduce(
					( filtered, filter ) => filter.callback( filtered, ...args ),
					value
				);
			},
		};
	}

	describe( 'extension compatibility on product pages', () => {
		function getBundleCart() {
			return {
				needs_shipping: false,
				totals: {
					total_price: '4500',
					total_tax: '0',
					total_shipping: '0',
					currency_code: 'USD',
					currency_minor_unit: 2,
				},
				items: [
					{
						key: 'bundle-key',
						name: 'Gift bundle',
						quantity: 1,
						totals: {
							line_subtotal: '4500',
							line_subtotal_tax: '0',
							currency_minor_unit: 2,
						},
						extensions: {
							bundles: {
								bundled_items: [ 'child-key' ],
							},
						},
					},
					{
						key: 'child-key',
						name: 'T-Shirt',
						quantity: 1,
						totals: {
							line_subtotal: '2000',
							line_subtotal_tax: '0',
							currency_minor_unit: 2,
						},
						extensions: {
							bundles: {
								bundled_by: 'bundle-key',
							},
						},
					},
				],
				extensions: {},
			};
		}

		beforeEach( () => {
			window.wp.hooks = createWpHooks();
		} );

		test( 'prices a bundle from an ephemeral cart of the selected product, not the server price', async () => {
			setProductPage();
			window.wcpayExpressCheckoutParams.product.product_type = 'bundle';
			window.wp.apiFetch.mockResolvedValue( getBundleCart() );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'POST',
					path: '/wc/store/v1/cart/add-item?currency=USD',
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
					} ),
					data: { id: 123, quantity: 1, variation: [] },
				} )
			);
			expect( stripe.elements ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 4500, currency: 'usd' } )
			);
		} );

		test( 'prices an ephemeral cart when the server sent no product data', async () => {
			setProductPage();
			window.wcpayExpressCheckoutParams.product = [];
			window.wp.apiFetch.mockResolvedValue( getBundleCart() );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await flushPromises();

			expect( stripe.elements ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 4500 } )
			);
			expect( expressElement.mount ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'leaves items bundled by another item out of the wallet line items', async () => {
			const resolveClick = jest.fn();
			setProductPage();
			window.wcpayExpressCheckoutParams.product.product_type = 'bundle';
			window.wp.apiFetch.mockResolvedValue( getBundleCart() );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await flushPromises();
			await expressHandlers.click( { resolve: resolveClick } );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					lineItems: [ { amount: 4500, name: 'Gift bundle' } ],
				} )
			);
		} );

		test( 'sends the shopper\'s WooCommerce Deposits choice with the product', async () => {
			setProductPage();
			document.querySelector( 'form.cart' ).insertAdjacentHTML(
				'afterbegin',
				'<input type="radio" name="wc_deposit_option" value="yes" />' +
					'<input type="radio" name="wc_deposit_option" value="no" checked />' +
					'<input type="radio" name="wc_deposit_payment_plan" value="7" checked />'
			);
			window.wp.apiFetch.mockResolvedValue( getBundleCart() );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					data: {
						id: 123,
						quantity: 1,
						variation: [],
						wc_deposit_option: 'no',
						wc_deposit_payment_plan: '7',
					},
				} )
			);
		} );

		test( 're-prices the wallet when the shopper changes the WooCommerce Deposits choice', async () => {
			setProductPage();
			document.querySelector( 'form.cart' ).insertAdjacentHTML(
				'afterbegin',
				'<input type="radio" name="wc_deposit_option" value="yes" checked />' +
					'<input type="radio" name="wc_deposit_option" value="no" />'
			);
			window.wp.apiFetch.mockResolvedValue( getBundleCart() );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			expect( stripe.elements ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 2500 } )
			);

			const fullPayment = document.querySelector(
				'input[name=wc_deposit_option][value=no]'
			);
			fullPayment.checked = true;
			fullPayment.dispatchEvent(
				new window.Event( 'change', { bubbles: true } )
			);
			await flushPromises();
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
					} ),
					data: expect.objectContaining( { wc_deposit_option: 'no' } ),
				} )
			);
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 4500 } )
			);
		} );
	} );

	// Client 11.1.0 shortcode-buttons-express/compatibility/wc-product-page.js and the update-button-data action
	// (shortcode-buttons-express/index.js:597-674) it fires.
	describe( 'classic product form changes re-price the wallet', () => {
		function setClassicProductForm( options ) {
			options = options || {};
			document.body.innerHTML =
				// An error an earlier wallet attempt left (setError() marks every error it adds).
				'<div class="woocommerce-notices-wrapper">' +
				'<ul class="woocommerce-error" role="alert" data-woopayments-wallet-error><li>Earlier wallet error</li></ul>' +
				'</div>' +
				( options.variable
					? '<form class="variations_form cart">' +
						'<table class="variations"><tbody><tr><td class="value">' +
						'<select name="attribute_pa_size" data-attribute_name="attribute_pa_size">' +
						'<option value="">Choose an option</option>' +
						'<option value="small"' +
						( options.size === 'small' ? ' selected' : '' ) +
						'>Small</option>' +
						'<option value="large"' +
						( options.size === 'large' ? ' selected' : '' ) +
						'>Large</option>' +
						'</select></td></tr></tbody></table>' +
						'<div class="single_variation_wrap"><div class="woocommerce-variation-add-to-cart">' +
						'<div class="quantity"><input type="number" name="quantity" class="input-text qty text" value="1" /></div>' +
						'<button type="submit" class="single_add_to_cart_button button alt ' +
						( options.buttonClasses || '' ) +
						'">Add to cart</button>' +
						'<input type="hidden" name="add-to-cart" value="123" />' +
						'<input type="hidden" name="product_id" value="123" />' +
						'<input type="hidden" name="variation_id" class="variation_id" value="' +
						( options.variationId || '' ) +
						'" />' +
						'</div></div></form>'
					: '<form class="cart">' +
						'<div class="quantity"><input type="number" name="quantity" class="input-text qty text" value="1" /></div>' +
						'<button type="submit" name="add-to-cart" value="123" class="single_add_to_cart_button">' +
						'Add to cart</button>' +
						'</form>' ) +
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
				product_type: options.variable ? 'variable' : 'simple',
			};
		}

		// A product with nothing to ship: no shipping address event ever corrects the sheet. Store API cart shape:
		// docs/apis/store-api/resources-endpoints/cart.md ("Cart Response": items[].quantity, items[].totals, totals).
		function getVirtualCart( total, quantity ) {
			return {
				needs_shipping: false,
				totals: {
					total_price: String( total ),
					total_tax: '0',
					total_shipping: '0',
					currency_code: 'USD',
					currency_minor_unit: 2,
				},
				items: [
					{
						key: 'item-key',
						name: 'Express Widget',
						quantity,
						totals: {
							line_subtotal: String( total ),
							line_subtotal_tax: '0',
							currency_minor_unit: 2,
						},
					},
				],
			};
		}

		async function mountReadyWallet() {
			require( '../woopayments-express-checkout' );
			await flushMicrotasks();
			expressHandlers.ready( {
				availablePaymentMethods: { applePay: true },
			} );
		}

		function typeQuantity( value ) {
			const input = document.querySelector( '.quantity .qty' );
			input.value = value;
			input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		}

		test( 'blocks the button on quantity input and re-prices it 250ms after the last input', async () => {
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			typeQuantity( '2' );
			expect( containerJQuery.block ).toHaveBeenCalledWith( {
				message: null,
			} );
			jest.advanceTimersByTime( 200 );
			typeQuantity( '3' );
			jest.advanceTimersByTime( 249 );
			expect( window.wp.apiFetch ).not.toHaveBeenCalled();

			jest.advanceTimersByTime( 1 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'POST',
					path: '/wc/store/v1/cart/add-item?currency=USD',
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
					} ),
					data: { id: 123, quantity: 3, variation: [] },
				} )
			);
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 7500 } )
			);
			expect( containerJQuery.unblock ).toHaveBeenCalled();
			expect(
				document.querySelector( '.woocommerce-error' )
			).toBeNull();
		} );

		// Client 11.1.0 shortcode-buttons-express/index.js:323-342: when the product cannot be added after a click, the
		// wallet is unmounted and hidden with its separator; the next form change runs update-button-data, which mounts a
		// new one because Elements is gone (index.js:648-650).
		test( 'takes the wallet away when the product cannot be added on a click, and mounts a new one on the next change', async () => {
			jest.useFakeTimers();
			setClassicProductForm();
			// Stripe.js `element.unmount()` detaches the Element and returns nothing
			// (https://docs.stripe.com/js/element/other_methods/unmount).
			expressElement.unmount = jest.fn();
			window.wp.apiFetch
				// With `parse: false` @wordpress/api-fetch rejects with the fetch Response itself for a non-2xx status
				// (api-fetch src/utils/response.js:72-74, parseAndThrowError).
				.mockRejectedValueOnce( { status: 500, ok: false } )
				.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();
			const container = document.getElementById(
				'wcpay-express-checkout-element'
			);
			const separator = document.getElementById(
				'wcpay-express-checkout-button-separator'
			);
			expect( container.classList.contains( 'is-ready' ) ).toBe( true );

			// Express Checkout Element `click` event: expressPaymentType and resolve (https://docs.stripe.com/js.md,
			// "expressCheckoutElement.on('click', handler)").
			await expressHandlers.click( {
				expressPaymentType: 'apple_pay',
				resolve: jest.fn(),
			} );
			await flushMicrotasks();

			expect( expressElement.unmount ).toHaveBeenCalledTimes( 1 );
			expect( container.classList.contains( 'is-ready' ) ).toBe( false );
			expect( container.style.display ).toBe( 'none' );
			expect( separator.hidden ).toBe( true );
			expect( stripe.elements ).toHaveBeenCalledTimes( 1 );

			typeQuantity( '3' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( stripe.elements ).toHaveBeenCalledTimes( 2 );
			expect( expressElement.mount ).toHaveBeenCalledTimes( 2 );
			// The new wallet shows only once it reports a payment method.
			expect( container.style.display ).toBe( 'none' );

			expressHandlers.ready( {
				availablePaymentMethods: { applePay: true },
			} );

			expect( container.classList.contains( 'is-ready' ) ).toBe( true );
			expect( container.style.display ).toBe( '' );
		} );

		test( 'opens the sheet of a product with nothing to ship at the re-priced quantity', async () => {
			const resolveClick = jest.fn();
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			typeQuantity( '3' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();
			await expressHandlers.click( { resolve: resolveClick } );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					shippingAddressRequired: false,
					lineItems: [
						{ amount: 7500, name: 'Express Widget (x3)' },
					],
				} )
			);
		} );

		// The click adds the form's current selection, so the newest re-price must win whatever order the answers
		// arrive in. The client keeps the last answer to arrive (shortcode-buttons-express/index.js:614-618).
		// The server prices the product page at one unit (WooPaymentsExpressCheckoutService::get_product_data(); client
		// 11.1.0 likewise, button-helper.php:759-, and its first open uses that payload as is, index.js:87-92, :110-127,
		// :504-512). Native prices the form's quantity before the first open when the field starts above one (a
		// minimum quantity, a failed add-to-cart, a browser form restore). Store API add-item answer: cart.md
		// "Add Item" (items[].quantity, totals.total_price).
		test.each( [
			[ 'without', undefined ],
			// `window.wcpayAsyncCurrency.ready` resolves with the lower-case currency code the page settles on
			// (multi-currency-async-renderer.js); here the localized one.
			[ 'after the multi-currency check of', () => ( { ready: Promise.resolve( 'usd' ) } ) ],
		] )( 'opens the first sheet at the quantity the form starts with, %s the page currency', async ( label, asyncCurrency ) => {
			const resolveClick = jest.fn();
			setClassicProductForm();
			document.querySelector( '.quantity .qty' ).setAttribute( 'value', '3' );
			if ( asyncCurrency ) {
				window.wcpayAsyncCurrency = asyncCurrency();
			}
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );

			await mountReadyWallet();
			await flushPromises();
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					data: expect.objectContaining( { id: 123, quantity: 3 } ),
				} )
			);
			expect( stripe.elements ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 7500 } )
			);

			await expressHandlers.click( { resolve: resolveClick } );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					lineItems: [ { name: 'Express Widget (x3)', amount: 7500 } ],
				} )
			);
		} );

		test( 'mounts from the server data while the variation form cannot add the product yet', async () => {
			setClassicProductForm( { variable: true, buttonClasses: 'disabled' } );
			document.querySelector( '.quantity .qty' ).setAttribute( 'value', '3' );

			await mountReadyWallet();
			await flushMicrotasks();

			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
			expect( stripe.elements ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 2500 } )
			);
		} );

		test( 're-prices a quantity changed back to one after the form was restored at three', async () => {
			jest.useFakeTimers();
			setClassicProductForm();
			// A browser form restore changes the value, not the `value` attribute (defaultValue stays 1).
			document.querySelector( '.quantity .qty' ).value = '3';
			window.wp.apiFetch
				.mockResolvedValueOnce( getVirtualCart( 7500, 3 ) )
				.mockResolvedValueOnce( getVirtualCart( 2500, 1 ) );
			await mountReadyWallet();
			await flushMicrotasks();

			typeQuantity( '1' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					data: expect.objectContaining( { quantity: 1 } ),
				} )
			);
		} );

		test( 'keeps the newest quantity when an older re-price answers last', async () => {
			const resolveClick = jest.fn();
			const twoUnits = createDeferred();
			const threeUnits = createDeferred();
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch
				.mockReturnValueOnce( twoUnits.promise )
				.mockReturnValueOnce( threeUnits.promise )
				.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			typeQuantity( '2' );
			jest.advanceTimersByTime( 250 );
			typeQuantity( '3' );
			jest.advanceTimersByTime( 250 );
			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );

			threeUnits.resolve( getVirtualCart( 7500, 3 ) );
			await flushMicrotasks();
			twoUnits.resolve( getVirtualCart( 5000, 2 ) );
			await flushMicrotasks();

			expect( elements.update ).toHaveBeenLastCalledWith(
				expect.objectContaining( { amount: 7500 } )
			);

			await expressHandlers.click( { resolve: resolveClick } );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					lineItems: [
						{ amount: 7500, name: 'Express Widget (x3)' },
					],
				} )
			);
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					data: { id: 123, quantity: 3, variation: [] },
				} )
			);
		} );

		test( 'keeps the wallet shown when an older re-price fails after the newest one priced it', async () => {
			const twoUnits = createDeferred();
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch
				.mockReturnValueOnce( twoUnits.promise )
				.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			typeQuantity( '2' );
			jest.advanceTimersByTime( 250 );
			typeQuantity( '3' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();
			twoUnits.reject( new Error( 'Out of stock' ) );
			await flushMicrotasks();

			expect(
				document
					.getElementById( 'wcpay-express-checkout-element' )
					.classList.contains( 'is-ready' )
			).toBe( true );
			expect( elements.update ).toHaveBeenLastCalledWith(
				expect.objectContaining( { amount: 7500 } )
			);
		} );

		// Native departure: the client listens to `input` only (compatibility/wc-product-page.js:66-83), while
		// WooCommerce's quantity steppers dispatch only `change` (blocks add-to-cart-form/frontend.ts:74-78).
		test( 're-prices the wallet when WooCommerce\'s quantity stepper changes the quantity', async () => {
			const resolveClick = jest.fn();
			const input = () => document.querySelector( '.quantity .qty' );
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			input().value = '3';
			input().dispatchEvent(
				new window.Event( 'change', { bubbles: true } )
			);
			expect( containerJQuery.block ).toHaveBeenCalledWith( {
				message: null,
			} );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					data: { id: 123, quantity: 3, variation: [] },
				} )
			);

			await expressHandlers.click( { resolve: resolveClick } );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					lineItems: [
						{ amount: 7500, name: 'Express Widget (x3)' },
					],
				} )
			);
		} );

		// The client catches a theme's jQuery-triggered `input` through jQuery delegation (wc-product-page.js:75-82).
		test( 're-prices the wallet when a theme changes the quantity through jQuery', async () => {
			const input = () => document.querySelector( '.quantity .qty' );
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			input().value = '3';
			triggerThroughJQuery( input(), 'input' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					data: { id: 123, quantity: 3, variation: [] },
				} )
			);
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 7500 } )
			);
		} );

		test( 'prices a typed quantity once when the field then reports the committed change', async () => {
			const input = () => document.querySelector( '.quantity .qty' );
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			await mountReadyWallet();

			typeQuantity( '3' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();
			input().dispatchEvent(
				new window.Event( 'change', { bubbles: true } )
			);
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		} );

		// WooCommerce's variation form re-sets the quantity and triggers `change` on every found variation, including
		// its own init for a default variation (add-to-cart-variation.js:420-427).
		test( 'does not re-price when the variation form re-sets the same quantity', async () => {
			jest.useFakeTimers();
			setClassicProductForm( {
				variable: true,
				size: 'large',
				variationId: '125',
			} );
			await mountReadyWallet();

			triggerThroughJQuery(
				document.querySelector( '.quantity .qty' ),
				'change'
			);
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
			expect( containerJQuery.block ).not.toHaveBeenCalled();
		} );

		test( 're-prices the wallet for a newly chosen classic variation', async () => {
			setClassicProductForm( {
				variable: true,
				size: 'large',
				variationId: '125',
			} );
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 3000, 1 ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			expect( containerJQuery.block ).toHaveBeenCalled();
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					data: {
						id: 125,
						quantity: 1,
						variation: [
							{ attribute: 'attribute_pa_size', value: 'large' },
						],
					},
				} )
			);
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 3000 } )
			);
			expect( containerJQuery.unblock ).toHaveBeenCalled();
		} );

		// Client 11.1.0 removes every `.woocommerce-error` on a product form change (shortcode-buttons-express/index.js:613);
		// native removes only the wallet's own, as its setError() does.
		test( 'keeps the errors the wallet did not add when the product changes', async () => {
			setClassicProductForm( {
				variable: true,
				size: 'large',
				variationId: '125',
			} );
			document
				.querySelector( 'form.variations_form' )
				.insertAdjacentHTML(
					'afterbegin',
					'<ul class="woocommerce-error"><li>Extension error</li></ul>'
				);
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 3000, 1 ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			expect(
				Array.from(
					document.querySelectorAll( '.woocommerce-error' ),
					( error ) => error.textContent
				)
			).toEqual( [ 'Extension error' ] );
		} );

		// Stripe.js v3 `elements.update()` returns nothing (default mock above); the client awaits it
		// (shortcode-buttons-express/index.js:653) and shows the wallet for an eligible cart (:666-668).
		test( 'keeps the wallet shown after a successful re-price', async () => {
			const container = () =>
				document.getElementById( 'wcpay-express-checkout-element' );
			setClassicProductForm( {
				variable: true,
				size: 'large',
				variationId: '125',
			} );
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 3000, 1 ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 3000 } )
			);
			expect( container().classList.contains( 'is-ready' ) ).toBe( true );
			expect(
				document.getElementById(
					'wcpay-express-checkout-button-separator'
				).hidden
			).toBe( false );
		} );

		// Client 11.1.0 cancel (shortcode-buttons-express/index.js:432-446, event-handlers.js:320-326) empties the
		// ephemeral cart and unblocks the page; the button stays as it was (recorded on a local client 11.1.0 store:
		// `is-ready`, opacity 1 after cancel). The Express Checkout Element `cancel` event fires when the payment
		// interface is dismissed, with no payload the handler reads
		// (https://docs.stripe.com/js/elements_object/express_checkout_element_cancel_event).
		test( 'keeps the wallet shown after the shopper cancels the sheet', async () => {
			const container = () =>
				document.getElementById( 'wcpay-express-checkout-element' );
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue(
				getStoreApiResponse( getVirtualCart( 2500, 1 ), {
					'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token',
				} )
			);
			await mountReadyWallet();

			await expressHandlers.click( { resolve: jest.fn() } );
			await flushMicrotasks();
			expressHandlers.cancel();
			await flushMicrotasks();

			expect( container().classList.contains( 'is-ready' ) ).toBe( true );
			expect( container().style.display ).not.toBe( 'none' );
		} );

		// The client empties the cart only once its add-to-cart promise settled (index.js:438-442), so a product added
		// after the cancel is not left in the tokenized cart.
		test( 'empties the product cart only after the click\'s add-item answered', async () => {
			const addItem = createDeferred();
			setClassicProductForm();
			window.wp.apiFetch
				.mockReturnValueOnce( addItem.promise )
				.mockResolvedValue( getStoreApiResponse( { items: [] }, {} ) );
			await mountReadyWallet();

			await expressHandlers.click( { resolve: jest.fn() } );
			expressHandlers.cancel();
			await flushMicrotasks();
			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );

			addItem.resolve(
				getStoreApiResponse( getVirtualCart( 2500, 1 ), {
					'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token',
				} )
			);
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					method: 'GET',
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token',
						'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
					} ),
				} )
			);
		} );

		// The client's second open repeats the first (report F10: same payload, shipping required); its click and
		// cancel never touch the button's cart data (index.js:57, :321, :432-446).
		test( 'opens the sheet again after a cancel with the payload of the first open', async () => {
			const firstOpen = jest.fn();
			const secondOpen = jest.fn();
			// Store API cart for the re-priced selection (cart.md "Cart Response").
			const repricedCart = Object.assign( getVirtualCart( 3000, 1 ), {
				needs_shipping: true,
				shipping_rates: [
					{
						shipping_rates: [
							{
								rate_id: 'flat_rate:1',
								name: 'Flat rate',
								price: '0',
								taxes: '0',
								selected: true,
								meta_data: [],
							},
						],
					},
				],
			} );
			setClassicProductForm( {
				variable: true,
				size: 'large',
				variationId: '125',
			} );
			window.wp.apiFetch
				.mockResolvedValueOnce( repricedCart )
				.mockResolvedValue(
					getStoreApiResponse(
						Object.assign( getVirtualCart( 5000, 1 ), {
							needs_shipping: true,
						} ),
						{ 'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token' }
					)
				);
			await mountReadyWallet();
			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			await expressHandlers.click( { resolve: firstOpen } );
			await flushMicrotasks();
			expressHandlers.cancel();
			await flushMicrotasks();
			await expressHandlers.click( { resolve: secondOpen } );

			expect( firstOpen ).toHaveBeenCalledWith(
				expect.objectContaining( {
					shippingAddressRequired: true,
					lineItems: [ { amount: 3000, name: 'Express Widget' } ],
				} )
			);
			expect( secondOpen.mock.calls[ 0 ][ 0 ] ).toEqual(
				firstOpen.mock.calls[ 0 ][ 0 ]
			);
		} );

		// A shopper who cancels and opens the sheet again before the first sheet's cart was emptied: the second sheet
		// owns its own tokenized cart session, and the late answers of the first sheet's requests must not take it
		// away (a request without the session header lands on a new, empty tokenized cart on the server). Store API
		// answers: add-item and cart as in cart.md ("Add Item", "Cart Response"); the tokenized session token comes
		// back in the `X-WooPayments-Tokenized-Cart-Session` response header.
		describe( 'a second sheet opened while the first one\'s cart is being emptied', () => {
			let pending;

			function routeStoreApi() {
				pending = { addItem: [], emptyCart: [] };
				window.wp.apiFetch.mockImplementation( ( options ) => {
					const deferred = createDeferred();
					if ( options.path.indexOf( '/wc/store/v1/cart/add-item' ) === 0 ) {
						pending.addItem.push( deferred );
						return deferred.promise;
					}
					if (
						options.headers[
							'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart'
						]
					) {
						pending.emptyCart.push( deferred );
						return deferred.promise;
					}
					return Promise.resolve(
						getStoreApiResponse(
							Object.assign( getVirtualCart( 2500, 1 ), {
								needs_shipping: true,
								shipping_rates: [
									{
										shipping_rates: [
											{
												rate_id: 'flat_rate:1',
												name: 'Flat rate',
												price: '0',
												taxes: '0',
												selected: true,
												meta_data: [],
											},
										],
									},
								],
							} ),
							{}
						)
					);
				} );
			}

			function answerWithSession( deferred, session ) {
				deferred.resolve(
					getStoreApiResponse( getVirtualCart( 2500, 1 ), {
						'X-WooPayments-Tokenized-Cart-Session': session,
					} )
				);
			}

			async function changeAddress() {
				await expressHandlers.shippingaddresschange( {
					name: 'Ada Lovelace',
					address: {
						city: 'San Francisco',
						state: 'CA',
						postal_code: '94107',
						country: 'US',
					},
					resolve: jest.fn(),
					reject: jest.fn(),
				} );

				return window.wp.apiFetch.mock.calls
					.map( ( [ options ] ) => options )
					.filter(
						( options ) =>
							options.path.indexOf(
								'/wc/store/v1/cart/update-customer'
							) === 0
					)
					.pop();
			}

			test( 'keeps the second sheet\'s cart when the first empty answers last', async () => {
				const firstOpen = jest.fn();
				const secondOpen = jest.fn();
				setClassicProductForm();
				window.wcpayExpressCheckoutParams.product.needs_shipping = true;
				routeStoreApi();
				await mountReadyWallet();

				await expressHandlers.click( { resolve: firstOpen } );
				answerWithSession( pending.addItem[ 0 ], 'session-one' );
				await flushMicrotasks();
				expressHandlers.cancel();
				await flushMicrotasks();
				expect( pending.emptyCart ).toHaveLength( 1 );

				await expressHandlers.click( { resolve: secondOpen } );
				answerWithSession( pending.addItem[ 1 ], 'session-two' );
				await flushMicrotasks();
				answerWithSession( pending.emptyCart[ 0 ], 'session-one' );
				await flushMicrotasks();

				expect( secondOpen.mock.calls[ 0 ][ 0 ] ).toEqual(
					firstOpen.mock.calls[ 0 ][ 0 ]
				);
				expect( ( await changeAddress() ).headers ).toEqual(
					expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Session': 'session-two',
					} )
				);
			} );

			test( 'does not empty the second sheet\'s cart when the first add-item answers last', async () => {
				setClassicProductForm();
				window.wcpayExpressCheckoutParams.product.needs_shipping = true;
				routeStoreApi();
				await mountReadyWallet();

				await expressHandlers.click( { resolve: jest.fn() } );
				expressHandlers.cancel();
				await expressHandlers.click( { resolve: jest.fn() } );
				answerWithSession( pending.addItem[ 1 ], 'session-two' );
				await flushMicrotasks();
				answerWithSession( pending.addItem[ 0 ], 'session-one' );
				await flushMicrotasks();

				expect( pending.emptyCart ).toHaveLength( 0 );
				expect( ( await changeAddress() ).headers ).toEqual(
					expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart-Session': 'session-two',
					} )
				);
			} );
		} );

		test( 'keeps the button unblocked and unpriced while the classic variation is cleared', async () => {
			const resolveClick = jest.fn();
			setClassicProductForm( {
				variable: true,
				buttonClasses: 'disabled wc-variation-selection-needed',
			} );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
			expect( containerJQuery.block ).not.toHaveBeenCalled();
			expect( containerJQuery.unblock ).toHaveBeenCalled();
			expect(
				document
					.getElementById( 'wcpay-express-checkout-element' )
					.classList.contains( 'is-ready' )
			).toBe( true );

			await expressHandlers.click( { resolve: resolveClick } );

			expect( window.alert ).toHaveBeenCalledWith(
				'Please select your product options before proceeding.'
			);
			expect( resolveClick ).not.toHaveBeenCalled();
			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		} );

		test( 'tells the shopper an unavailable classic variation cannot be bought', async () => {
			const resolveClick = jest.fn();
			window.wc_add_to_cart_variation_params = {
				i18n_unavailable_text: 'Sorry, this product is unavailable.',
			};
			setClassicProductForm( {
				variable: true,
				size: 'small',
				variationId: '124',
				buttonClasses: 'disabled wc-variation-is-unavailable',
			} );
			await mountReadyWallet();

			await expressHandlers.click( { resolve: resolveClick } );

			expect( window.alert ).toHaveBeenCalledWith(
				'Sorry, this product is unavailable.'
			);
			expect( resolveClick ).not.toHaveBeenCalled();
			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		} );

		test( 'hides the wallet when the re-priced cart is not eligible', async () => {
			setClassicProductForm( {
				variable: true,
				size: 'small',
				variationId: '124',
			} );
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 0, 1 ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			expect(
				document
					.getElementById( 'wcpay-express-checkout-element' )
					.classList.contains( 'is-ready' )
			).toBe( false );
			expect(
				document.getElementById(
					'wcpay-express-checkout-button-separator'
				).hidden
			).toBe( true );
		} );

		test( 'shows the wallet again when a later re-priced cart is eligible', async () => {
			const container = () =>
				document.getElementById( 'wcpay-express-checkout-element' );
			setClassicProductForm( {
				variable: true,
				size: 'small',
				variationId: '124',
			} );
			window.wp.apiFetch
				.mockResolvedValueOnce( getVirtualCart( 0, 1 ) )
				.mockResolvedValueOnce( getVirtualCart( 3000, 1 ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();
			expect( container().classList.contains( 'is-ready' ) ).toBe( false );

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			expect( container().classList.contains( 'is-ready' ) ).toBe( true );
			expect(
				document.getElementById(
					'wcpay-express-checkout-button-separator'
				).hidden
			).toBe( false );
		} );

		test( 'leaves no empty button area after a re-price in a browser without a wallet', async () => {
			jest.useFakeTimers();
			setClassicProductForm();
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 7500, 3 ) );
			require( '../woopayments-express-checkout' );
			await flushMicrotasks();
			expressHandlers.ready( READY_WITHOUT_A_WALLET );

			typeQuantity( '3' );
			jest.advanceTimersByTime( 250 );
			await flushMicrotasks();

			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 7500 } )
			);
			// Product pages get no separator from the server (it renders only on checkout).
			expect(
				document
					.getElementById( 'wcpay-express-checkout-element' )
					.classList.contains( 'is-ready' )
			).toBe( false );
		} );

		// Native departure: the client skips this re-price, because WooCommerce enables the add-to-cart button only
		// after `woocommerce_variation_has_changed` (add-to-cart-variation.js onChange, show_variation 300ms later).
		test( 'prices a variation chosen on a cleared classic form once WooCommerce enables the button', async () => {
			const resolveClick = jest.fn();
			setClassicProductForm( {
				variable: true,
				buttonClasses: 'disabled wc-variation-selection-needed',
			} );
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 3000, 1 ) );
			await mountReadyWallet();

			document.querySelector( '.variations select' ).value = 'large';
			document.querySelector( 'input[name="variation_id"]' ).value = '125';
			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();
			expect( window.wp.apiFetch ).not.toHaveBeenCalled();

			document
				.querySelector( '.single_add_to_cart_button' )
				.classList.remove( 'disabled', 'wc-variation-selection-needed' );
			bodyEventHandlers.show_variation();
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( window.wp.apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/add-item?currency=USD',
					data: {
						id: 125,
						quantity: 1,
						variation: [
							{ attribute: 'attribute_pa_size', value: 'large' },
						],
					},
				} )
			);
			expect( elements.update ).toHaveBeenCalledWith(
				expect.objectContaining( { amount: 3000 } )
			);

			await expressHandlers.click( { resolve: resolveClick } );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					lineItems: [ { amount: 3000, name: 'Express Widget' } ],
				} )
			);
		} );

		test( 'prices a switch between chosen classic variations once', async () => {
			setClassicProductForm( {
				variable: true,
				size: 'large',
				variationId: '125',
			} );
			window.wp.apiFetch.mockResolvedValue( getVirtualCart( 3000, 1 ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();
			bodyEventHandlers.show_variation();
			await flushMicrotasks();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		} );

		// Client 11.1.0 button-ui.js:39-45: hiding removes `is-ready` and sets `display: none` (jQuery `.hide()`), so a
		// hidden wallet takes no space and no click (recorded on a local client 11.1.0 store: `display: none`, height
		// 0); showing reverses both. The first
		// mount happens in the stylesheet's initial state, without `display: none`.
		test( 'takes a hidden wallet out of the layout and puts it back when shown', async () => {
			const container = () =>
				document.getElementById( 'wcpay-express-checkout-element' );
			setClassicProductForm( {
				variable: true,
				size: 'small',
				variationId: '124',
			} );
			window.wp.apiFetch
				.mockRejectedValueOnce( new Error( 'Out of stock' ) )
				.mockResolvedValueOnce( getVirtualCart( 3000, 1 ) );
			require( '../woopayments-express-checkout' );
			await flushMicrotasks();
			expect( container().style.display ).toBe( '' );
			expressHandlers.ready( {
				availablePaymentMethods: { applePay: true },
			} );

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();
			expect( container().style.display ).toBe( 'none' );
			expect( container().classList.contains( 'is-ready' ) ).toBe( false );

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();
			expect( container().style.display ).toBe( '' );
			expect( container().classList.contains( 'is-ready' ) ).toBe( true );
		} );

		test( 'hides the wallet when the re-priced cart cannot be fetched', async () => {
			setClassicProductForm( {
				variable: true,
				size: 'small',
				variationId: '124',
			} );
			window.wp.apiFetch.mockRejectedValue( new Error( 'Out of stock' ) );
			await mountReadyWallet();

			bodyEventHandlers.woocommerce_variation_has_changed();
			await flushMicrotasks();

			expect(
				document
					.getElementById( 'wcpay-express-checkout-element' )
					.classList.contains( 'is-ready' )
			).toBe( false );
		} );
	} );

	describe( 'a WooCommerce Subscriptions free trial with nothing to pay today', () => {
		function getFreeTrialCart( overrides = {} ) {
			return Object.assign(
				{
					needs_shipping: false,
					items: [
						{
							name: 'Premium Plan',
							quantity: 1,
							totals: {
								line_subtotal: '0',
								line_subtotal_tax: '0',
								line_total: '0',
								currency_minor_unit: 2,
							},
							extensions: {
								subscriptions: {
									billing_period: 'month',
									billing_interval: 1,
									trial_length: 14,
									sign_up_fees: '0',
								},
							},
						},
					],
					totals: {
						total_price: '0',
						total_items: '0',
						total_tax: '0',
						total_shipping: '0',
						total_shipping_tax: '0',
						currency_code: 'USD',
						currency_minor_unit: 2,
						tax_lines: [],
					},
					shipping_rates: [],
					extensions: {
						subscriptions: [
							{
								billing_period: 'month',
								billing_interval: 1,
								next_payment_date: '2026-03-19',
								totals: {
									total_price: '1999',
									total_items: '1999',
									total_tax: '0',
									total_shipping: '0',
									total_shipping_tax: '0',
									currency_minor_unit: 2,
									currency_prefix: '$',
									currency_suffix: '',
									currency_decimal_separator: '.',
									currency_thousand_separator: ',',
									tax_lines: [],
								},
							},
						],
					},
				},
				overrides
			);
		}

		beforeEach( () => {
			window.wp.hooks = createWpHooks();
		} );

		test( 'mounts the wallet for the recurring total and shows it in the line items', async () => {
			const resolveClick = jest.fn();
			window.wp.apiFetch.mockResolvedValue( getFreeTrialCart() );

			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: resolveClick } );

			expect( stripe.elements ).toHaveBeenCalledWith(
				expect.objectContaining( {
					mode: 'payment',
					amount: 1999,
					setupFutureUsage: 'off_session',
				} )
			);
			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					lineItems: [
						{
							name: 'Premium Plan (recurring) - Recurring total: $19.99 / month on 2026-03-19',
							amount: 1999,
						},
					],
				} )
			);
		} );

		test( 'lets an extension make a $0 cart eligible through the is-cart-eligible filter', async () => {
			window.wp.apiFetch.mockResolvedValue(
				getFreeTrialCart( {
					items: [],
					extensions: {},
				} )
			);
			window.wp.hooks.addFilter(
				'wcpay.express-checkout.is-cart-eligible',
				'test/extension',
				() => true
			);

			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();

			expect( expressElement.mount ).toHaveBeenCalledWith(
				'#wcpay-express-checkout-element'
			);
		} );

		test( 'offers the shipping rates the subscription holds for a physical free trial', async () => {
			const resolveClick = jest.fn();
			const cart = getFreeTrialCart( { needs_shipping: true } );
			cart.extensions.subscriptions[ 0 ].shipping_rates = [
				{
					package_id: 'sub_month_0',
					shipping_rates: [
						{
							rate_id: 'flat_rate:3',
							name: 'Subscription shipping',
							price: '500',
							taxes: '0',
							selected: true,
							currency_minor_unit: 2,
							meta_data: [],
						},
					],
				},
			];
			window.wp.apiFetch.mockResolvedValue( cart );

			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: resolveClick } );
			await expressHandlers.shippingratechange( {
				shippingRate: { id: 'flat_rate:3' },
				resolve: jest.fn(),
				reject: jest.fn(),
			} );

			expect( resolveClick ).toHaveBeenCalledWith(
				expect.objectContaining( {
					shippingRates: [
						{
							id: 'flat_rate:3',
							displayName: 'Subscription shipping',
							amount: 500,
							deliveryEstimate: '',
						},
					],
				} )
			);
			expect( window.wp.apiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					path: '/wc/store/v1/cart/select-shipping-rate?currency=USD',
					data: { package_id: 'sub_month_0', rate_id: 'flat_rate:3' },
				} )
			);
		} );
	} );

	// Client 11.1.0 event-handlers.js:290-326: the whole page is blocked on every wallet click (onClickHandler), and
	// unblocked on abort (onAbortPaymentHandler: failed add-to-cart, currency drift, failed confirm) and on cancel
	// (onCancelHandler); a completed payment blocks it again before leaving (onCompletePaymentHandler). Event shapes
	// (https://docs.stripe.com/js.md): `click` carries expressPaymentType and resolve ("expressCheckoutElement.on('click',
	// handler)"); `cancel` fires when the payment interface is dismissed, with no payload the handler reads
	// (https://docs.stripe.com/js/elements_object/express_checkout_element_cancel_event).
	describe( 'the page is locked while the wallet sheet is open', () => {
		const PAGE_LOCK_OPTIONS = {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6,
			},
		};

		async function mountCheckoutWallet() {
			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
		}

		test( 'locks the page when the shopper opens the sheet on a product page and unlocks it on cancel', async () => {
			const resolveClick = jest.fn();
			setProductPage();
			// Store API add-item answer for the tokenized cart (docs/apis/store-api/resources-endpoints/cart.md, "Add Item").
			window.wp.apiFetch.mockResolvedValue(
				getStoreApiResponse(
					{
						needs_shipping: false,
						totals: {
							total_price: '2500',
							currency_code: 'USD',
						},
						items: [],
					},
					{ 'X-WooPayments-Tokenized-Cart-Session': 'cart-session-token' }
				)
			);

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await expressHandlers.click( {
				expressPaymentType: 'apple_pay',
				resolve: resolveClick,
			} );

			expect( window.jQuery.blockUI ).toHaveBeenCalledTimes( 1 );
			expect( window.jQuery.blockUI ).toHaveBeenCalledWith( PAGE_LOCK_OPTIONS );
			expect(
				window.jQuery.blockUI.mock.invocationCallOrder[ 0 ]
			).toBeLessThan( resolveClick.mock.invocationCallOrder[ 0 ] );
			expect( window.jQuery.unblockUI ).not.toHaveBeenCalled();

			expressHandlers.cancel();
			await flushPromises();

			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'locks the page when the shopper opens the sheet on classic checkout', async () => {
			await mountCheckoutWallet();

			await expressHandlers.click( {
				expressPaymentType: 'google_pay',
				resolve: jest.fn(),
			} );

			expect( window.jQuery.blockUI ).toHaveBeenCalledWith( PAGE_LOCK_OPTIONS );
		} );

		test( 'does not lock the page when the click asks the shopper to log in', async () => {
			window.wcpayExpressCheckoutParams.login_confirmation = {
				message: 'Log in to pay with **Apple Pay**',
				redirect_url: 'https://example.test/my-account/',
			};
			const confirmDialog = jest
				.spyOn( window, 'confirm' )
				.mockImplementation( () => false );
			await mountCheckoutWallet();

			await expressHandlers.click( {
				expressPaymentType: 'apple_pay',
				resolve: jest.fn(),
			} );

			expect( confirmDialog ).toHaveBeenCalled();
			expect( window.jQuery.blockUI ).not.toHaveBeenCalled();
			confirmDialog.mockRestore();
		} );

		test( 'unlocks the page when the wallet payment fails', async () => {
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				// Store API checkout error as Checkout::get_response() sends it (src/StoreApi/Routes/V1/Checkout.php:170-178)
				// through AbstractRoute::error_to_response() (AbstractRoute.php:135-157): code, message, data.status. A
				// payment failure is woocommerce_rest_checkout_process_payment_error, status 400 (CheckoutTrait.php:135).
				.mockRejectedValueOnce( {
					code: 'woocommerce_rest_checkout_process_payment_error',
					message: 'Your card was declined.',
					data: { status: 400 },
				} );
			await mountCheckoutWallet();
			await expressHandlers.click( { resolve: jest.fn() } );

			// Express Checkout Element `confirm` event with billingDetails (https://docs.stripe.com/js.md,
			// "expressCheckoutElement.on('confirm', handler)").
			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );

			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );
			expect(
				document.querySelector( '.woocommerce-notices-wrapper' ).textContent
			).toBe( 'Your card was declined.' );
		} );

		test( 'unlocks the page when the product cannot be added to the sheet\'s cart', async () => {
			setProductPage();
			// With `parse: false` @wordpress/api-fetch rejects with the fetch Response itself for a non-2xx status
			// (api-fetch src/utils/response.js:72-74, parseAndThrowError).
			window.wp.apiFetch
				.mockRejectedValueOnce( { status: 500, ok: false } )
				.mockResolvedValue( getStoreApiResponse( { items: [] }, {} ) );

			require( '../woopayments-express-checkout' );
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );
			await flushPromises();

			expect( window.jQuery.blockUI ).toHaveBeenCalledTimes( 1 );
			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );
		} );

		// The Store API cart answers in the currency the chosen address maps to (cart.md "Cart Response",
		// totals.currency_code); the sheet stays in the currency the Element booted with.
		function getCartInCurrency( currency ) {
			return Object.assign( getCartResponse(), {
				totals: {
					total_price: '5000',
					currency_code: currency,
				},
				shipping_rates: [],
			} );
		}

		test( 'unlocks the page when the address chosen in the sheet needs another currency', async () => {
			const rejectShipping = jest.fn();
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				.mockResolvedValueOnce( getCartInCurrency( 'EUR' ) );
			await mountCheckoutWallet();
			await expressHandlers.click( { resolve: jest.fn() } );

			// `shippingaddresschange`: name, address, resolve, reject
			// (https://docs.stripe.com/js/elements_object/express_checkout_element_shippingaddresschange_event).
			await expressHandlers.shippingaddresschange( {
				name: 'Ada Lovelace',
				address: {
					city: 'Berlin',
					state: '',
					postal_code: '10115',
					country: 'DE',
				},
				resolve: jest.fn(),
				reject: rejectShipping,
			} );

			expect( rejectShipping ).toHaveBeenCalled();
			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'unlocks the page when the rate chosen in the sheet needs another currency', async () => {
			const rejectRate = jest.fn();
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				.mockResolvedValueOnce( getCartInCurrency( 'EUR' ) );
			await mountCheckoutWallet();
			await expressHandlers.click( { resolve: jest.fn() } );

			// `shippingratechange`: shippingRate, resolve, reject (https://docs.stripe.com/js.md, "Handle shippingratechange event").
			await expressHandlers.shippingratechange( {
				shippingRate: { id: 'flat_rate:1', amount: 500, displayName: 'Flat rate' },
				resolve: jest.fn(),
				reject: rejectRate,
			} );

			expect( rejectRate ).toHaveBeenCalled();
			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );
		} );

		test.each( [
			[
				'straight to the order page',
				// Store API checkout success: payment_result.redirect_url (src/StoreApi/Schemas/V1/CheckoutSchema.php:187-191).
				() => ( {
					payment_result: {
						payment_status: 'success',
						redirect_url: 'https://example.test/checkout/order-received/77/',
						payment_details: [],
					},
				} ),
			],
			[ 'after confirming a 3DS payment', () => getConfirmationCheckoutResponse( 77 ) ],
		] )(
			'locks the page again before leaving %s',
			async ( path, getCheckoutResponse ) => {
				const navigate = jest.fn();
				// stripe.handleNextAction() resolves with `{ paymentIntent }` or `{ error }` (https://docs.stripe.com/js.md,
				// "stripe.handleNextAction(options)").
				stripe.handleNextAction = jest.fn().mockResolvedValue( {
					paymentIntent: { id: 'pi_3ds', status: 'succeeded' },
				} );
				mockOrderStatusUpdate( 'https://example.test/checkout/order-received/77/' );
				window.wp.apiFetch
					.mockResolvedValueOnce( getCartResponse() )
					.mockResolvedValueOnce( getCheckoutResponse() );
				require( '../woopayments-express-checkout' ).__test__.setNavigate(
					navigate
				);
				await bodyEventHandlers.updated_checkout();
				await flushPromises();
				await expressHandlers.click( { resolve: jest.fn() } );

				await expressHandlers.confirm( {
					billingDetails: {
						email: 'shopper@example.test',
						name: 'Ada Lovelace',
					},
				} );

				expect( navigate ).toHaveBeenCalledWith(
					'https://example.test/checkout/order-received/77/'
				);
				expect( window.jQuery.blockUI ).toHaveBeenCalledTimes( 2 );
				expect(
					window.jQuery.blockUI.mock.invocationCallOrder[ 1 ]
				).toBeLessThan( navigate.mock.invocationCallOrder[ 0 ] );
			}
		);

		// The back-forward cache restores the page as it was left, overlay included, when the shopper goes Back from the
		// order page (`pageshow` with `persisted`, https://developer.mozilla.org/docs/Web/API/PageTransitionEvent).
		test( 'unlocks the page when it comes back from the back-forward cache after leaving for the order', async () => {
			const navigate = jest.fn();
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				// Store API checkout success: payment_result.redirect_url (src/StoreApi/Schemas/V1/CheckoutSchema.php:187-191).
				.mockResolvedValueOnce( {
					payment_result: {
						payment_status: 'success',
						redirect_url: 'https://example.test/checkout/order-received/77/',
						payment_details: [],
					},
				} );
			require( '../woopayments-express-checkout' ).__test__.setNavigate(
				navigate
			);
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );
			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );
			expect( navigate ).toHaveBeenCalled();
			expect( window.jQuery.unblockUI ).not.toHaveBeenCalled();

			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', { persisted: false } )
			);
			expect( window.jQuery.unblockUI ).not.toHaveBeenCalled();

			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', { persisted: true } )
			);
			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );

			// Only the lock the payment put up is released: a later restore leaves other page locks alone.
			window.dispatchEvent(
				new window.PageTransitionEvent( 'pageshow', { persisted: true } )
			);
			expect( window.jQuery.unblockUI ).toHaveBeenCalledTimes( 1 );
		} );

		// Register row 238 (kept): without jQuery BlockUI on the page the lock and unlock do nothing, and the sheet
		// still opens.
		test( 'opens and cancels the sheet when jQuery BlockUI is not loaded', async () => {
			const resolveClick = jest.fn();
			delete window.jQuery.blockUI;
			delete window.jQuery.unblockUI;
			await mountCheckoutWallet();

			await expressHandlers.click( { resolve: resolveClick } );
			expressHandlers.cancel();
			await flushPromises();

			expect( resolveClick ).toHaveBeenCalled();
			expect(
				document.querySelector( '.woocommerce-notices-wrapper' ).textContent
			).toBe( '' );
		} );
	} );

	// Client 11.1.0 onCancelHandler() (event-handlers.js:320-326) puts the last address chosen in the sheet
	// (`lastSelectedAddress`, set at the start of every address change, :93) into the page form through
	// updateShippingAddressUI() (utils/shipping-fields.js:116-127): the shipping calculator on the classic cart, the
	// billing fields on classic checkout, nothing for CA and GB (redacted postcodes). The address is the
	// `shippingaddresschange` event's partial address (city, state, postal_code, country:
	// https://docs.stripe.com/js/elements_object/express_checkout_element_shippingaddresschange_event).
	describe( 'the address chosen in the sheet reaches the page form on cancel', () => {
		const berlin = {
			city: 'Berlin',
			state: 'BE',
			postal_code: '10115',
			country: 'DE',
		};

		function setClassicCheckoutForm() {
			document.body.innerHTML =
				'<div class="woocommerce-notices-wrapper"></div>' +
				'<form class="checkout woocommerce-checkout">' +
				'<div class="wcpay-express-checkout-wrapper">' +
				'<div id="wcpay-express-checkout-element"></div>' +
				'<p id="wcpay-express-checkout-button-separator">OR</p>' +
				'</div>' +
				'<select name="billing_country">' +
				'<option value="US" selected>United States (US)</option>' +
				'<option value="DE">Germany</option>' +
				'</select>' +
				'<input type="text" name="billing_state" value="CA" />' +
				'<input type="text" name="billing_city" value="San Francisco" />' +
				'<input type="text" name="billing_postcode" value="94107" />' +
				'</form>';
		}

		function getField( name ) {
			return document.querySelector( '[name="' + name + '"]' );
		}

		async function changeAddressInCheckoutSheet( address, cartResponse ) {
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				.mockImplementationOnce( cartResponse );
			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );
			await expressHandlers.shippingaddresschange( {
				name: 'Ada Lovelace',
				address,
				resolve: jest.fn(),
				reject: jest.fn(),
			} );
		}

		test( 'fills the classic checkout billing fields with the sheet\'s last address', async () => {
			setClassicCheckoutForm();
			await changeAddressInCheckoutSheet( berlin, () =>
				Promise.resolve( getCartWithShippingRate( 5500 ) )
			);

			expect( getField( 'billing_country' ).value ).toBe( 'US' );

			expressHandlers.cancel();

			expect( getField( 'billing_country' ).value ).toBe( 'DE' );
			expect( getField( 'billing_state' ).value ).toBe( 'BE' );
			expect( getField( 'billing_city' ).value ).toBe( 'Berlin' );
			expect( getField( 'billing_postcode' ).value ).toBe( '10115' );
			expect( triggeredFieldEvents ).toEqual( [
				[ 'billing_country', 'change' ],
				[ 'billing_country', 'close' ],
				[ 'billing_state', 'change' ],
				[ 'billing_city', 'change' ],
				[ 'billing_postcode', 'change' ],
			] );
		} );

		test( 'chooses a country option by its name', async () => {
			setClassicCheckoutForm();
			await changeAddressInCheckoutSheet(
				{ city: 'Berlin', state: '', postal_code: '10115', country: 'germany' },
				() => Promise.resolve( getCartWithShippingRate( 5500 ) )
			);

			expressHandlers.cancel();

			expect( getField( 'billing_country' ).value ).toBe( 'DE' );
			// An empty part of the address leaves its field as it was.
			expect( getField( 'billing_state' ).value ).toBe( 'CA' );
		} );

		test( 'fills the fields with an address whose change the store could not price', async () => {
			setClassicCheckoutForm();
			// Store API error for update-customer (docs/apis/store-api/resources-endpoints/cart.md error responses:
			// code, message, data.status).
			await changeAddressInCheckoutSheet( berlin, () =>
				Promise.reject( {
					code: 'woocommerce_rest_cart_error',
					message: 'Error',
					data: { status: 400 },
				} )
			);

			expressHandlers.cancel();

			expect( getField( 'billing_city' ).value ).toBe( 'Berlin' );
		} );

		test( 'leaves the checkout fields alone for a United Kingdom address', async () => {
			setClassicCheckoutForm();
			await changeAddressInCheckoutSheet(
				{ city: 'London', state: '', postal_code: 'SW1A', country: 'GB' },
				() => Promise.resolve( getCartWithShippingRate( 5500 ) )
			);

			expressHandlers.cancel();

			expect( getField( 'billing_city' ).value ).toBe( 'San Francisco' );
			expect( triggeredFieldEvents ).toEqual( [] );
		} );

		test( 'fills the fields once per address change', async () => {
			setClassicCheckoutForm();
			await changeAddressInCheckoutSheet( berlin, () =>
				Promise.resolve( getCartWithShippingRate( 5500 ) )
			);
			expressHandlers.cancel();
			getField( 'billing_city' ).value = 'Potsdam';

			await expressHandlers.click( { resolve: jest.fn() } );
			expressHandlers.cancel();

			expect( getField( 'billing_city' ).value ).toBe( 'Potsdam' );
		} );

		test( 'fills the classic cart shipping calculator and recalculates', async () => {
			const recalculate = jest.fn( ( event ) => event.preventDefault() );
			window.wcpayExpressCheckoutParams.button_context = 'cart';
			document.body.innerHTML =
				'<div class="woocommerce-notices-wrapper"></div>' +
				'<form class="woocommerce-shipping-calculator">' +
				'<select name="calc_shipping_country">' +
				'<option value="US" selected>United States (US)</option>' +
				'<option value="DE">Germany</option>' +
				'</select>' +
				'<input type="text" name="calc_shipping_state" value="CA" />' +
				'<input type="text" name="calc_shipping_city" value="" />' +
				'<input type="text" name="calc_shipping_postcode" value="94107" />' +
				'<button type="submit" name="calc_shipping" value="1">Update</button>' +
				'</form>' +
				'<div class="wcpay-express-checkout-wrapper">' +
				'<div id="wcpay-express-checkout-element"></div>' +
				'</div>';
			document
				.querySelector( 'form.woocommerce-shipping-calculator' )
				.addEventListener( 'submit', recalculate );
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				.mockResolvedValueOnce( getCartWithShippingRate( 5500 ) );
			require( '../woopayments-express-checkout' );
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );
			await expressHandlers.shippingaddresschange( {
				name: 'Ada Lovelace',
				address: berlin,
				resolve: jest.fn(),
				reject: jest.fn(),
			} );

			expressHandlers.cancel();

			expect( getField( 'calc_shipping_country' ).value ).toBe( 'DE' );
			expect( getField( 'calc_shipping_state' ).value ).toBe( 'BE' );
			expect( getField( 'calc_shipping_city' ).value ).toBe( 'Berlin' );
			expect( getField( 'calc_shipping_postcode' ).value ).toBe( '10115' );
			expect( recalculate ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	// Client 11.1.0 abortPayment() (shortcode-buttons-express/index.js:186-206) appends the new error to the first
	// notices wrapper and scrolls to it; native uses core's error notice markup (templates/notices/error.php:
	// `<ul class="woocommerce-error" role="alert"><li>`) so screen readers announce it. The client first removes every
	// `.woocommerce-error` on the page (index.js:189); native removes only its own earlier wallet errors.
	describe( 'a wallet error replaces earlier wallet errors and is announced', () => {
		function setCheckoutWithEarlierErrors() {
			document.body.innerHTML =
				'<div class="woocommerce-notices-wrapper">' +
				'<ul class="woocommerce-error" role="alert"><li>Earlier error</li></ul>' +
				'</div>' +
				'<form class="checkout"><div class="woocommerce-NoticeGroup">' +
				'<ul class="woocommerce-error"><li>Checkout error</li></ul>' +
				'</div>' +
				'<div class="extension-field"><ul class="woocommerce-error"><li>Extension error</li></ul></div>' +
				'</form>' +
				'<div class="woocommerce-notices-wrapper"></div>' +
				'<div class="wcpay-express-checkout-wrapper">' +
				'<div id="wcpay-express-checkout-element"></div>' +
				'</div>';
		}

		function getErrorTexts() {
			return Array.from(
				document.querySelectorAll( '.woocommerce-error' ),
				( error ) => error.textContent
			);
		}

		// A wallet click and confirm (https://docs.stripe.com/js.md, "expressCheckoutElement.on('click' / 'confirm',
		// handler)") whose checkout fails with a Store API payment error (src/StoreApi/Utilities/CheckoutTrait.php:135,
		// status 400; shape from AbstractRoute::error_to_response(): code, message, data.status).
		async function failWalletPayment( message ) {
			window.wp.apiFetch.mockRejectedValueOnce( {
				code: 'woocommerce_rest_checkout_process_payment_error',
				message,
				data: { status: 400 },
			} );
			await expressHandlers.click( { resolve: jest.fn() } );
			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );
		}

		test( 'shows a failed payment in the first notices wrapper, announced and scrolled into view', async () => {
			setCheckoutWithEarlierErrors();
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				// Store API checkout error as Checkout::get_response() sends it (src/StoreApi/Routes/V1/Checkout.php:170-178)
				// through AbstractRoute::error_to_response() (AbstractRoute.php:135-157): code, message, data.status. A
				// payment failure is woocommerce_rest_checkout_process_payment_error, status 400 (CheckoutTrait.php:135).
				.mockRejectedValueOnce( {
					code: 'woocommerce_rest_checkout_process_payment_error',
					message: 'Your card was declined.',
					data: { status: 400 },
				} );
			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );

			// Express Checkout Element `confirm` event with billingDetails (https://docs.stripe.com/js.md,
			// "expressCheckoutElement.on('confirm', handler)").
			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );

			const error = document.querySelector(
				'.woocommerce-notices-wrapper'
			).lastElementChild;
			expect( error.className ).toBe( 'woocommerce-error' );
			expect( error.getAttribute( 'role' ) ).toBe( 'alert' );
			expect( error.tagName ).toBe( 'UL' );
			expect( error.querySelector( 'li' ).textContent ).toBe(
				'Your card was declined.'
			);
			expect( error.scrollIntoView ).toHaveBeenCalledTimes( 1 );
			expect( error.scrollIntoView.mock.contexts[ 0 ] ).toBe( error );
		} );

		// The other errors on the page are WooCommerce's or an extension's: a wallet failure does not resolve them.
		test( 'keeps the errors it did not add, and a second wallet error replaces the first', async () => {
			setCheckoutWithEarlierErrors();
			window.wp.apiFetch.mockResolvedValueOnce( getCartResponse() );
			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();

			await failWalletPayment( 'Your card was declined.' );
			expect( getErrorTexts() ).toEqual( [
				'Earlier error',
				'Your card was declined.',
				'Checkout error',
				'Extension error',
			] );

			await failWalletPayment( 'Your card has insufficient funds.' );
			expect( getErrorTexts() ).toEqual( [
				'Earlier error',
				'Your card has insufficient funds.',
				'Checkout error',
				'Extension error',
			] );
		} );

		// Client 11.1.0 getErrorMessageFromNotice() (express-checkout/utils/error-messages.ts:7-13) shows the text of a
		// notice, not its markup.
		test.each( [
			[
				'a Store API error with notice markup',
				() =>
					// Store API checkout error with an unescaped message: Checkout::get_response() sends any other
					// \Exception's message as is under woocommerce_rest_unknown_server_error, status 500
					// (src/StoreApi/Routes/V1/Checkout.php:176-177; shape from AbstractRoute::error_to_response()).
					Promise.reject( {
						code: 'woocommerce_rest_unknown_server_error',
						message:
							'\n\t<strong>Error:</strong> Your card was declined. <a href="https://example.test/help">Get help</a>\n',
						data: { status: 500 },
					} ),
				'Error: Your card was declined. Get help',
			],
			[
				'a failed payment result with markup in errorMessage',
				() =>
					// Store API checkout response: payment_result.payment_status and payment_details[] of { key, value }
					// (src/StoreApi/Schemas/V1/CheckoutSchema.php:166-186), the gateway result merged in by
					// StoreApi\Legacy::process_legacy_payment() (src/StoreApi/Legacy.php:79-81). The client gateway sets
					// errorMessage (client 11.1.0 includes/class-wc-payment-gateway-wcpay.php:1526).
					Promise.resolve( {
						payment_result: {
							payment_status: 'failure',
							payment_details: [
								{
									key: 'errorMessage',
									value: 'Card declined &amp; <em>not</em> charged.<img src="x" alt="">',
								},
							],
							redirect_url: '',
						},
					} ),
				'Card declined & not charged.',
			],
			[
				'an escaped message whose text has angle brackets',
				() =>
					// Store API payment error: CheckoutTrait::process_payment() escapes the message with esc_html()
					// (src/StoreApi/Utilities/CheckoutTrait.php:135); shape from AbstractRoute::error_to_response().
					Promise.reject( {
						code: 'woocommerce_rest_checkout_process_payment_error',
						message: 'Card &lt;b&gt;4242&lt;/b&gt; was declined.',
						data: { status: 400 },
					} ),
				'Card <b>4242</b> was declined.',
			],
			[
				'a message that is only markup (the generic error)',
				() =>
					// Store API checkout error with an unescaped message (src/StoreApi/Routes/V1/Checkout.php:176-177).
					Promise.reject( {
						code: 'woocommerce_rest_unknown_server_error',
						message: '<img src="https://example.test/x.png" alt=""><br>',
						data: { status: 500 },
					} ),
				'Unable to process this payment, please try again.',
			],
		] )( 'shows the text of %s, never its markup', async ( label, checkoutAnswer, expected ) => {
			setCheckoutWithEarlierErrors();
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				.mockImplementationOnce( checkoutAnswer );
			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );

			// Express Checkout Element `confirm` event (https://docs.stripe.com/js.md, "expressCheckoutElement.on('confirm', handler)").
			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );
			await flushPromises();

			const error = document.querySelector(
				'.woocommerce-notices-wrapper'
			).lastElementChild;
			expect( error.textContent ).toBe( expected );
			expect( error.querySelector( 'strong, a, em, img, b' ) ).toBeNull();
		} );

		test( 'keeps every earlier error and adds none when the page has no notices wrapper', async () => {
			setCheckoutWithEarlierErrors();
			document
				.querySelectorAll( '.woocommerce-notices-wrapper' )
				.forEach( ( wrapper ) => wrapper.remove() );
			// A wallet error an earlier attempt left, moved out of the wrappers by a customized page: with nowhere to show the
			// new error, it must stay too.
			document
				.querySelector( 'form.checkout' )
				.insertAdjacentHTML(
					'afterbegin',
					'<ul class="woocommerce-error" role="alert" data-woopayments-wallet-error><li>Earlier wallet error</li></ul>'
				);
			window.wp.apiFetch
				.mockResolvedValueOnce( getCartResponse() )
				// Store API payment error (src/StoreApi/Utilities/CheckoutTrait.php:135, status 400; shape from
				// AbstractRoute::error_to_response(): code, message, data.status).
				.mockRejectedValueOnce( {
					code: 'woocommerce_rest_checkout_process_payment_error',
					message: 'Your card was declined.',
					data: { status: 400 },
				} );
			require( '../woopayments-express-checkout' );
			await bodyEventHandlers.updated_checkout();
			await flushPromises();
			await expressHandlers.click( { resolve: jest.fn() } );

			// Express Checkout Element `confirm` event (https://docs.stripe.com/js.md, "expressCheckoutElement.on('confirm', handler)").
			await expressHandlers.confirm( {
				billingDetails: {
					email: 'shopper@example.test',
					name: 'Ada Lovelace',
				},
			} );

			expect( getErrorTexts() ).toEqual( [
				'Earlier wallet error',
				'Checkout error',
				'Extension error',
			] );
		} );
	} );

	test( 'does not initialize classic ECE on block checkout surfaces', async () => {
		window.wcpayExpressCheckoutParams.has_block = true;

		require( '../woopayments-express-checkout' );
		await flushPromises();

		expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		expect( window.Stripe ).not.toHaveBeenCalled();
	} );
} );
