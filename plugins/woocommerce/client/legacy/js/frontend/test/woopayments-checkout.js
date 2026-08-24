/**
 * @jest-environment jest-fixed-jsdom
 */

describe( 'WooPayments checkout', () => {
	let bodyEventHandlers;
	let checkoutFormState;
	let checkoutFormEventHandlers;
	let documentEventListeners;
	let orderPayFormEventHandlers;
	let orderPayFormState;
	let elementsMock;
	let mountPaymentElement;
	let paymentElementHandlers;
	let paymentElementOptions;
	let stripeMock;
	let stripeElementsOptions;
	let submitElements;
	let unmountPaymentElement;
	let updatePaymentElement;
	let windowEventHandlers;
	const originalFetch = window.fetch;
	const originalDocumentAddEventListener = document.addEventListener;

	async function flushPromises() {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

	function setPaymentIntentConfirmationHash( intentId = 'pi_native' ) {
		window.location.hash =
			'#wcpay-confirm-pi:123:' + intentId + '_secret_abc:nonce';
	}

	function mockConfirmationCallbackResponse( response ) {
		const fail = jest.fn();

		global.jQuery.post.mockImplementationOnce( () => ( {
			done: jest.fn( ( callback ) => {
				callback( response );
				return { fail };
			} ),
		} ) );
	}

	function mockConfirmationCallbackFailure() {
		const fail = jest.fn( ( callback ) => callback() );

		global.jQuery.post.mockImplementationOnce( () => ( {
			done: jest.fn( () => ( { fail } ) ),
		} ) );
	}

	function expectClassicCheckoutReleaseCount( count ) {
		expect(
			global.jQuery.checkoutFormResult.removeClass
		).toHaveBeenCalledTimes( count );
		expect(
			global.jQuery.checkoutFormResult.unblock
		).toHaveBeenCalledTimes( count );
	}

	function expectClassicCheckoutBlockCount( count ) {
		expect(
			global.jQuery.checkoutFormResult.addClass
		).toHaveBeenCalledTimes( count );
		expect( global.jQuery.checkoutFormResult.block ).toHaveBeenCalledTimes(
			count
		);
	}

	function expectClassicCheckoutUiState( isBlocked ) {
		expect( checkoutFormState ).toEqual( {
			processing: isBlocked,
			blocked: isBlocked,
		} );
	}

	function expectClassicCheckoutBlockedOnce() {
		expectClassicCheckoutBlockCount( 1 );
		expectClassicCheckoutReleaseCount( 0 );
		expectClassicCheckoutUiState( true );
	}

	function expectOrderPayBlockCount( count ) {
		expect(
			global.jQuery.orderPayFormResult.addClass
		).toHaveBeenCalledTimes( count );
		expect( global.jQuery.orderPayFormResult.block ).toHaveBeenCalledTimes(
			count
		);
	}

	function expectNoConfirmationResubmit() {
		expect( stripeMock.createPaymentMethod ).not.toHaveBeenCalled();
		expect(
			global.jQuery.checkoutFormResult.trigger
		).not.toHaveBeenCalledWith( 'submit' );
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

	function preparePaymentListWallets( selectedMethod = 'apple_pay' ) {
		const registrations = {};
		document.body.innerHTML =
			'<form class="checkout">' +
			'<ul class="payment_methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" ' +
			( selectedMethod === 'card' ? 'checked ' : '' ) +
			'/>' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_apple_pay">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_apple_pay" ' +
			( selectedMethod === 'apple_pay' ? 'checked ' : '' ) +
			'/>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_google_pay">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_google_pay" ' +
			( selectedMethod === 'google_pay' ? 'checked ' : '' ) +
			'/>' +
			'</li>' +
			'</ul>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		window.wcpay_core_checkout_config.isExpressCheckoutInPaymentMethodsEnabled =
			true;
		window.wcpay_core_checkout_config.paymentMethodTypes = [ 'card' ];
		window.wcpay_core_checkout_config.paymentListWalletsConfig = {};
		window.wcpay_core_checkout_config.paymentListWalletsConfig.apple_pay = {
			gatewayId: 'woocommerce_payments_apple_pay',
			id: 'apple_pay',
			isExpressCheckout: true,
			isReusable: true,
		};
		window.wcpay_core_checkout_config.paymentListWalletsConfig.google_pay = {
			gatewayId: 'woocommerce_payments_google_pay',
			id: 'google_pay',
			isExpressCheckout: true,
			isReusable: true,
		};
		window.wc = {
			customPlaceOrderButton: {
				__getForm: jest.fn(
					() => global.jQuery.checkoutFormResult
				),
				register: jest.fn( ( gateway, registration ) => {
					registrations[ gateway ] = registration;
				} ),
			},
		};

		return registrations;
	}

	function createJQueryMock() {
		const checkoutFormFields = {};
		const orderPayFormFields = {};
		checkoutFormState = {
			processing: false,
			blocked: false,
		};
		orderPayFormState = {
			processing: false,
			blocked: false,
		};
		const defaultResult = {
			length: 0,
			filter: jest.fn( () => defaultResult ),
			find: jest.fn( () => defaultResult ),
			on: jest.fn( () => defaultResult ),
			appendTo: jest.fn( () => defaultResult ),
			trigger: jest.fn( () => defaultResult ),
			val: jest.fn(),
		};
		const selectedGatewayResult = {
			length: 1,
			find: jest.fn( () => defaultResult ),
			on: jest.fn( () => selectedGatewayResult ),
			appendTo: jest.fn( () => selectedGatewayResult ),
			filter: jest.fn( () => selectedGatewayResult ),
			trigger: jest.fn( () => selectedGatewayResult ),
			val: jest.fn( () => 'woocommerce_payments' ),
		};
		const bodyResult = {
			length: 1,
			on: jest.fn( ( event, handler ) => {
				bodyEventHandlers[ event ] = handler;
				return bodyResult;
			} ),
			// The checkout script announces failures with
			// `$( document.body ).trigger( 'checkout_error', ... )`, so the
			// double has to accept a trigger as well as a subscription.
			trigger: jest.fn( () => bodyResult ),
		};
		const windowResult = {
			length: 1,
			on: jest.fn( ( event, handler ) => {
				windowEventHandlers[ event ] = handler;
				return windowResult;
			} ),
		};
		const checkoutFormResult = {
			length: 1,
			addClass: jest.fn( ( className ) => {
				if ( className === 'processing' ) {
					checkoutFormState.processing = true;
				}
				return checkoutFormResult;
			} ),
			block: jest.fn( () => {
				checkoutFormState.blocked = true;
				return checkoutFormResult;
			} ),
			filter: jest.fn( () => checkoutFormResult ),
			find: jest.fn( ( selector ) => {
				const match = selector.match( /input\[name="([^"]+)"\]/ );
				const name = match ? match[ 1 ] : null;
				return name && checkoutFormFields[ name ]
					? checkoutFormFields[ name ]
					: defaultResult;
			} ),
			on: jest.fn( ( event, handler ) => {
				event.split( ' ' ).forEach( ( eventName ) => {
					checkoutFormEventHandlers[ eventName ] = handler;
				} );
				return checkoutFormResult;
			} ),
			appendTo: jest.fn( () => checkoutFormResult ),
			removeClass: jest.fn( ( className ) => {
				if ( className === 'processing' ) {
					checkoutFormState.processing = false;
				}
				return checkoutFormResult;
			} ),
			trigger: jest.fn( () => checkoutFormResult ),
			unblock: jest.fn( () => {
				checkoutFormState.blocked = false;
				return checkoutFormResult;
			} ),
			val: jest.fn(),
		};
		const orderPayFormResult = {
			length: 1,
			addClass: jest.fn( ( className ) => {
				if ( className === 'processing' ) {
					orderPayFormState.processing = true;
				}
				return orderPayFormResult;
			} ),
			block: jest.fn( () => {
				orderPayFormState.blocked = true;
				return orderPayFormResult;
			} ),
			filter: jest.fn( () => orderPayFormResult ),
			find: jest.fn( ( selector ) => {
				const match = selector.match( /input\[name="([^"]+)"\]/ );
				const name = match ? match[ 1 ] : null;
				return name && orderPayFormFields[ name ]
					? orderPayFormFields[ name ]
					: defaultResult;
			} ),
			on: jest.fn( ( event, handler ) => {
				event.split( ' ' ).forEach( ( eventName ) => {
					orderPayFormEventHandlers[ eventName ] = handler;
				} );
				return orderPayFormResult;
			} ),
			appendTo: jest.fn( () => orderPayFormResult ),
			removeClass: jest.fn( ( className ) => {
				if ( className === 'processing' ) {
					orderPayFormState.processing = false;
				}
				return orderPayFormResult;
			} ),
			trigger: jest.fn( () => orderPayFormResult ),
			unblock: jest.fn( () => {
				orderPayFormState.blocked = false;
				return orderPayFormResult;
			} ),
			val: jest.fn(),
		};

		const jQueryMock = jest.fn( ( selectorOrCallback, attributes = {} ) => {
			if ( typeof selectorOrCallback === 'function' ) {
				selectorOrCallback();
				return defaultResult;
			}

			if ( selectorOrCallback === document.body ) {
				return bodyResult;
			}

			if ( selectorOrCallback === window ) {
				return windowResult;
			}

			if ( selectorOrCallback === 'form.checkout' ) {
				return checkoutFormResult;
			}

			if ( selectorOrCallback === 'form#order_review' ) {
				return orderPayFormResult;
			}

			if (
				selectorOrCallback &&
				selectorOrCallback.tagName === 'FORM'
			) {
				return selectorOrCallback.id === 'order_review'
					? orderPayFormResult
					: checkoutFormResult;
			}

			if (
				selectorOrCallback === '<input />' &&
				typeof attributes.name === 'string'
			) {
				const field = {
					length: 1,
					appendTo: jest.fn( ( form ) => {
						const fields =
							form === orderPayFormResult
								? orderPayFormFields
								: checkoutFormFields;
						fields[ attributes.name ] = field;
						return field;
					} ),
					filter: jest.fn( () => field ),
					find: jest.fn( () => defaultResult ),
					on: jest.fn( () => field ),
					trigger: jest.fn( () => field ),
					val: jest.fn( ( value ) => {
						if ( value !== undefined ) {
							field.value = value;
						}
						return field.value;
					} ),
					value: '',
				};

				return field;
			}

			if (
				typeof selectorOrCallback === 'string' &&
				selectorOrCallback.indexOf( 'input[name="payment_method"]' ) !==
					-1
			) {
				return selectedGatewayResult;
			}

			return defaultResult;
		} );
		jQueryMock.checkoutFormFields = checkoutFormFields;
		jQueryMock.orderPayFormFields = orderPayFormFields;
		jQueryMock.checkoutFormResult = checkoutFormResult;
		jQueryMock.orderPayFormResult = orderPayFormResult;
		jQueryMock.post = jest.fn( () => ( {
			done: jest.fn( ( callback ) => {
				callback( { status_code: 200 } );
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
		checkoutFormEventHandlers = {};
		documentEventListeners = [];
		jest.spyOn( document, 'addEventListener' ).mockImplementation(
			( type, listener, options ) => {
				documentEventListeners.push( [ type, listener, options ] );
				return originalDocumentAddEventListener.call(
					document,
					type,
					listener,
					options
				);
			}
		);
		orderPayFormEventHandlers = {};
		windowEventHandlers = {};
		submitElements = jest.fn( () => Promise.resolve( {} ) );
		mountPaymentElement = jest.fn();
		paymentElementHandlers = {};
		paymentElementOptions = null;
		stripeElementsOptions = null;
		unmountPaymentElement = jest.fn();
		updatePaymentElement = jest.fn();
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';

		const jQueryMock = createJQueryMock();
		global.jQuery = jQueryMock;
		global.$ = jQueryMock;
		window.jQuery = jQueryMock;
		window.$ = jQueryMock;
		window.wcpay_core_checkout_config = {
			accountId: 'acct_test',
			ajaxUrl: 'https://example.test/admin-ajax.php',
			cartTotal: '5000',
			currency: 'GBP',
			gatewayId: 'woocommerce_payments',
			isCoreNativeCheckoutAvailable: true,
			isCheckout: true,
			isShopperTrackingEnabled: true,
			locale: 'en-US',
			stylesCacheVersion: 'styles-v1',
			platformTrackerNonce: 'tracks-nonce',
			paymentMethodsConfig: {
				card: {
					isReusable: true,
					cardBrandIcons: [
						{
							id: 'visa',
							alt: 'Visa',
							src: 'https://example.test/visa.svg',
						},
						{
							id: 'mastercard',
							alt: 'Mastercard',
							src: 'https://example.test/mastercard.svg',
						},
						{
							id: 'amex',
							alt: 'American Express',
							src: 'https://example.test/amex.svg',
						},
						{
							id: 'discover',
							alt: 'Discover',
							src: 'https://example.test/discover.svg',
						},
						{
							id: 'jcb',
							alt: 'JCB',
							src: 'https://example.test/jcb.svg',
						},
						{
							id: 'unionpay',
							alt: 'Union Pay',
							src: 'https://example.test/unionpay.svg',
						},
					],
				},
				link: {
					isReusable: false,
				},
			},
			publishableKey: 'pk_test',
			woopayPhoneLabel: 'Mobile phone number',
			woopaySaveUserLabel:
				'Securely save my information for 1-click checkout',
		};
		window.fetch = jest.fn().mockResolvedValue( {
			json: jest.fn().mockResolvedValue( { success: true } ),
		} );
		stripeMock = {
			elements: jest.fn( ( options ) => {
				stripeElementsOptions = options;
				elementsMock = {
					submit: submitElements,
					create: jest.fn( ( type, options ) => {
						paymentElementOptions = options;
						return {
							mount: mountPaymentElement,
							unmount: unmountPaymentElement,
							update: updatePaymentElement,
							// Stripe payment elements emit `ready`,
							// `loaderror` and friends; the double has to
							// expose `on` or it is not the thing it stands in
							// for.
							on: ( event, handler ) => {
								paymentElementHandlers[ event ] = handler;
							},
						};
					} ),
				};
				return elementsMock;
			} ),
			createPaymentMethod: jest.fn( () =>
				Promise.resolve( {
					paymentMethod: { id: 'pm_native' },
				} )
			),
			confirmPayment: jest.fn( () =>
				Promise.resolve( {
					paymentIntent: { id: 'pi_native' },
				} )
			),
			confirmSetup: jest.fn( () =>
				Promise.resolve( {
					setupIntent: { id: 'seti_native' },
				} )
			),
			handleNextAction: jest.fn( () =>
				Promise.resolve( {
					paymentIntent: { id: 'pi_native' },
				} )
			),
		};
		window.Stripe = jest.fn( () => stripeMock );
		require( '../utils/woopayments-appearance' );
	} );

	afterEach( () => {
		jest.useRealTimers();
		documentEventListeners.forEach( ( [ type, listener, options ] ) => {
			document.removeEventListener( type, listener, options );
		} );
		jest.restoreAllMocks();
		delete global.jQuery;
		delete global.$;
		delete window.jQuery;
		delete window.$;
		delete window.wcpay_core_checkout_config;
		delete window.wcpay_core_checkout_config_woocommerce_payments_klarna;
		delete window.wcpay_core_checkout_config_woocommerce_payments_ideal;
		delete window.wcpayAppearance;
		delete window.wc;
		delete window.Stripe;
		delete window.navigator.clipboard;
		window.fetch = originalFetch;
		window.localStorage.clear();
		document.body.innerHTML = '';
		window.history.pushState( {}, '', '/' );
		window.location.hash = '';
	} );

	test( 'does not record place-order tracking when shopper tracking is disabled', () => {
		window.wcpay_core_checkout_config.isShopperTrackingEnabled = false;
		require( '../woopayments-checkout' );

		document.getElementById( 'place_order' ).dispatchEvent(
			new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} )
		);

		expect( getTrackingEvents() ).toEqual( [] );
	} );

	test( 'passes the checkout total to Stripe Elements as a number', () => {
		require( '../woopayments-checkout' );

		expect( stripeElementsOptions ).toMatchObject( {
			amount: 5000,
			currency: 'gbp',
			loader: 'never',
			mode: 'payment',
			paymentMethodCreation: 'manual',
			paymentMethodTypes: [ 'card', 'link' ],
		} );
	} );

	test( 'uses keyed split gateway config for classic Stripe Elements', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_klarna" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		window.wcpay_core_checkout_config = Object.assign(
			{},
			window.wcpay_core_checkout_config,
			{
				gatewayId: 'woocommerce_payments',
				paymentMethodTypes: [ 'card' ],
			}
		);
		window.wcpay_core_checkout_config_woocommerce_payments_klarna =
			Object.assign( {}, window.wcpay_core_checkout_config, {
				gatewayId: 'woocommerce_payments_klarna',
				paymentMethodTypes: [ 'klarna' ],
				paymentMethodsConfig: {
					klarna: {
						isReusable: false,
					},
				},
			} );

		require( '../woopayments-checkout' );

		expect( stripeElementsOptions ).toMatchObject( {
			paymentMethodTypes: [ 'klarna' ],
		} );
	} );

	test( 'uses theme-appropriate split gateway icons without changing card brands', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<div id="payment" style="background-color: rgb(0, 0, 0)">' +
			'<ul class="payment_methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments">' +
			'<input id="payment_method_woocommerce_payments" type="radio" ' +
			'name="payment_method" value="woocommerce_payments" />' +
			'<label for="payment_method_woocommerce_payments">' +
			'<img class="card-brand-icon" src="https://example.test/visa.svg" alt="Visa" /></label>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_klarna">' +
			'<input id="payment_method_woocommerce_payments_klarna" type="radio" ' +
			'name="payment_method" value="woocommerce_payments_klarna" checked />' +
			'<label for="payment_method_woocommerce_payments_klarna">' +
			'<img class="wcpay-payment-method-icon" ' +
			'src="https://example.test/klarna.svg" alt="Klarna" /></label>' +
			'<div class="payment_box" style="background-color: rgb(255, 255, 255)"></div>' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</li>' +
			'</ul>' +
			'</div>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		window.wcpay_core_checkout_config_woocommerce_payments_klarna =
			Object.assign( {}, window.wcpay_core_checkout_config, {
				gatewayId: 'woocommerce_payments_klarna',
				paymentMethodId: 'klarna',
				paymentMethodTypes: [ 'klarna' ],
				paymentMethodsConfig: {
					klarna: {
						icon: 'https://example.test/klarna.svg',
						darkIcon: 'https://example.test/klarna-dark.svg',
						isReusable: false,
					},
				},
			} );

		require( '../woopayments-checkout' );

		const payment = document.getElementById( 'payment' );
		const splitIcon = document.querySelector(
			'.payment_method_woocommerce_payments_klarna .wcpay-payment-method-icon'
		);
		const cardIcon = document.querySelector(
			'.payment_method_woocommerce_payments .card-brand-icon'
		);

		expect( splitIcon.getAttribute( 'src' ) ).toBe(
			'https://example.test/klarna-dark.svg'
		);
		expect( cardIcon.getAttribute( 'src' ) ).toBe(
			'https://example.test/visa.svg'
		);

		payment.style.backgroundColor = 'rgb(255, 255, 255)';
		bodyEventHandlers.updated_checkout();

		expect( splitIcon.getAttribute( 'src' ) ).toBe(
			'https://example.test/klarna.svg'
		);
		expect( cardIcon.getAttribute( 'src' ) ).toBe(
			'https://example.test/visa.svg'
		);
	} );

	test( 'registers payment-list wallets through the custom place-order button API', () => {
		preparePaymentListWallets();

		require( '../woopayments-checkout' );

		expect( window.wc.customPlaceOrderButton.register ).toHaveBeenCalledWith(
			'woocommerce_payments_apple_pay',
			expect.objectContaining( {
				cleanup: expect.any( Function ),
				render: expect.any( Function ),
			} )
		);
		expect( window.wc.customPlaceOrderButton.register ).toHaveBeenCalledWith(
			'woocommerce_payments_google_pay',
			expect.objectContaining( {
				cleanup: expect.any( Function ),
				render: expect.any( Function ),
			} )
		);
		expect( mountPaymentElement ).not.toHaveBeenCalled();
	} );

	test( 'keeps custom-button wallets out of the ordinary card Payment Element terms', () => {
		preparePaymentListWallets( 'card' );

		require( '../woopayments-checkout' );

		expect( paymentElementOptions.terms ).toEqual( {
			card: 'never',
		} );
	} );

	test( 'submits the selected payment-list wallet through its Express Checkout Element', async () => {
		const registrations = preparePaymentListWallets();
		const expressHandlers = {};
		const expressElement = {
			mount: jest.fn(),
			on: jest.fn( ( event, handler ) => {
				expressHandlers[ event ] = handler;
			} ),
			unmount: jest.fn(),
		};
		const walletElements = {
			create: jest.fn( () => expressElement ),
			submit: submitElements,
		};
		stripeMock.elements.mockReturnValue( walletElements );

		require( '../woopayments-checkout' );

		const container = document.createElement( 'div' );
		const checkoutApi = {
			submit: jest.fn(),
			validate: jest.fn().mockResolvedValue( { hasError: false } ),
		};
		await registrations.woocommerce_payments_apple_pay.render(
			container,
			checkoutApi
		);

		expect( walletElements.create ).toHaveBeenCalledWith(
			'expressCheckout',
			expect.objectContaining( {
				paymentMethods: expect.objectContaining( {
					applePay: 'always',
					googlePay: 'never',
				} ),
			} )
		);

		const clickEvent = { resolve: jest.fn() };
		await expressHandlers.click( clickEvent );
		expect( checkoutApi.validate ).toHaveBeenCalled();
		expect( clickEvent.resolve ).toHaveBeenCalled();

		await expressHandlers.confirm();
		expect( submitElements ).toHaveBeenCalled();
		expect( stripeMock.createPaymentMethod ).toHaveBeenCalledWith( {
			elements: walletElements,
		} );
		expect(
			global.jQuery.checkoutFormFields[ 'wcpay-payment-method' ].value
		).toBe( 'pm_native' );
		expect( checkoutApi.submit ).toHaveBeenCalled();
	} );

	test( 'submits payment-list wallet credentials through the active order-pay form', async () => {
		const registrations = preparePaymentListWallets();
		const expressHandlers = {};
		const walletElements = {
			create: jest.fn( () => ( {
				mount: jest.fn(),
				on: jest.fn( ( event, handler ) => {
					expressHandlers[ event ] = handler;
				} ),
				unmount: jest.fn(),
			} ) ),
			submit: submitElements,
		};
		stripeMock.elements.mockReturnValue( walletElements );
		document.body.innerHTML =
			'<form id="order_review">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_apple_pay" checked />' +
			'</form>';
		window.wcpay_core_checkout_config.isOrderPay = true;
		window.wc.customPlaceOrderButton.__getForm.mockReturnValue(
			global.jQuery.orderPayFormResult
		);

		require( '../woopayments-checkout' );

		await registrations.woocommerce_payments_apple_pay.render(
			document.createElement( 'div' ),
			{
				submit: jest.fn(),
				validate: jest.fn().mockResolvedValue( { hasError: false } ),
			}
		);
		await expressHandlers.confirm();

		expect(
			global.jQuery.orderPayFormFields[ 'wcpay-payment-method' ].value
		).toBe( 'pm_native' );
		expect(
			global.jQuery.checkoutFormFields[ 'wcpay-payment-method' ]
		).toBeUndefined();
	} );

	test( 'hides a payment-list wallet when its Express Checkout Element cannot initialize', () => {
		const registrations = preparePaymentListWallets();
		stripeMock.elements.mockImplementationOnce( () => {
			throw new Error( 'Express Checkout unavailable' );
		} );

		require( '../woopayments-checkout' );

		expect( () =>
			registrations.woocommerce_payments_apple_pay.render(
				document.createElement( 'div' ),
				{ submit: jest.fn(), validate: jest.fn() }
			)
		).not.toThrow();
		expect(
			document
				.querySelector(
					'input[value="woocommerce_payments_apple_pay"]'
				)
				.closest( 'li' ).style.display
		).toBe( 'none' );
		expect(
			document.querySelector( 'input[value="woocommerce_payments"]' )
				.checked
		).toBe( true );
	} );

	test( 'selects an available split method when a wallet is unavailable and card is disabled', () => {
		const registrations = preparePaymentListWallets();
		document.body.innerHTML =
			'<form class="checkout">' +
			'<ul class="payment_methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_apple_pay">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_apple_pay" checked />' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_klarna">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments_klarna" />' +
			'</li>' +
			'</ul>' +
			'</form>';
		stripeMock.elements.mockImplementationOnce( () => {
			throw new Error( 'Express Checkout unavailable' );
		} );

		require( '../woopayments-checkout' );

		registrations.woocommerce_payments_apple_pay.render(
			document.createElement( 'div' ),
			{ submit: jest.fn(), validate: jest.fn() }
		);

		expect(
			document.querySelector(
				'input[value="woocommerce_payments_klarna"]'
			).checked
		).toBe( true );
	} );

	test( 'mounts split gateway Stripe Elements into the selected gateway container', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<ul class="payment_methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments">' +
			'<input id="payment_method_woocommerce_payments" type="radio" name="payment_method" value="woocommerce_payments" />' +
			'<div class="payment_box payment_method_woocommerce_payments">' +
			'<div id="wcpay-core-payment-element" data-gateway-marker="card"></div>' +
			'</div>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_sepa_debit">' +
			'<input id="payment_method_woocommerce_payments_sepa_debit" type="radio" ' +
			'name="payment_method" value="woocommerce_payments_sepa_debit" checked />' +
			'<div class="payment_box payment_method_woocommerce_payments_sepa_debit">' +
			'<div id="wcpay-core-payment-element" data-gateway-marker="sepa"></div>' +
			'</div>' +
			'</li>' +
			'</ul>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		window.wcpay_core_checkout_config = Object.assign(
			{},
			window.wcpay_core_checkout_config,
			{
				gatewayId: 'woocommerce_payments',
				paymentMethodTypes: [ 'card' ],
			}
		);
		window.wcpay_core_checkout_config_woocommerce_payments_sepa_debit =
			Object.assign( {}, window.wcpay_core_checkout_config, {
				gatewayId: 'woocommerce_payments_sepa_debit',
				paymentMethodTypes: [ 'sepa_debit' ],
				paymentMethodsConfig: {
					sepa_debit: {
						isReusable: false,
					},
				},
			} );

		const selectedContainer = document
			.getElementById( 'payment_method_woocommerce_payments_sepa_debit' )
			.closest( 'li' )
			.querySelector( '#wcpay-core-payment-element' );

		require( '../woopayments-checkout' );

		expect( stripeElementsOptions ).toMatchObject( {
			paymentMethodTypes: [ 'sepa_debit' ],
		} );
		expect( mountPaymentElement ).toHaveBeenCalledWith( selectedContainer );
	} );

	test( 'does not mount a redirect gateway into another gateway payment element container', () => {
		// A redirect method such as Affirm or Alipay collects nothing on this
		// page, so it renders no payment-element container of its own. The card
		// gateway's container is still in the document, and handing that one to
		// the redirect gateway mounts an element the shopper never fills in and
		// makes the submit handler try to create a card payment method for a
		// redirect submission -- which returns early and never posts the
		// checkout at all, so Place order silently does nothing.
		document.body.innerHTML =
			'<form class="checkout">' +
			'<ul class="payment_methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments">' +
			'<input id="payment_method_woocommerce_payments" type="radio" name="payment_method" value="woocommerce_payments" />' +
			'<div class="payment_box payment_method_woocommerce_payments">' +
			'<div id="wcpay-core-payment-element" data-gateway-marker="card"></div>' +
			'</div>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_affirm">' +
			'<input id="payment_method_woocommerce_payments_affirm" type="radio" ' +
			'name="payment_method" value="woocommerce_payments_affirm" checked />' +
			'<div class="payment_box payment_method_woocommerce_payments_affirm"></div>' +
			'</li>' +
			'</ul>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		window.wcpay_core_checkout_config = Object.assign(
			{},
			window.wcpay_core_checkout_config,
			{
				gatewayId: 'woocommerce_payments',
				paymentMethodTypes: [ 'card' ],
			}
		);
		window.wcpay_core_checkout_config_woocommerce_payments_affirm =
			Object.assign( {}, window.wcpay_core_checkout_config, {
				gatewayId: 'woocommerce_payments_affirm',
				paymentMethodTypes: [ 'affirm' ],
				paymentMethodsConfig: {
					affirm: {
						isReusable: false,
					},
				},
			} );

		const cardContainer = document
			.getElementById( 'payment_method_woocommerce_payments' )
			.closest( 'li' )
			.querySelector( '#wcpay-core-payment-element' );

		require( '../woopayments-checkout' );

		expect( mountPaymentElement ).not.toHaveBeenCalledWith( cardContainer );
	} );

	test( 'remounts split gateway Stripe Elements when the shopper changes payment method', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<ul class="payment_methods">' +
			'<li class="wc_payment_method payment_method_woocommerce_payments">' +
			'<input id="payment_method_woocommerce_payments" type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div class="payment_box payment_method_woocommerce_payments">' +
			'<div id="wcpay-core-payment-element" data-gateway-marker="card"></div>' +
			'</div>' +
			'</li>' +
			'<li class="wc_payment_method payment_method_woocommerce_payments_sepa_debit">' +
			'<input id="payment_method_woocommerce_payments_sepa_debit" type="radio" ' +
			'name="payment_method" value="woocommerce_payments_sepa_debit" />' +
			'<div class="payment_box payment_method_woocommerce_payments_sepa_debit">' +
			'<div id="wcpay-core-payment-element" data-gateway-marker="sepa"></div>' +
			'</div>' +
			'</li>' +
			'</ul>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		window.wcpay_core_checkout_config_woocommerce_payments_sepa_debit =
			Object.assign( {}, window.wcpay_core_checkout_config, {
				gatewayId: 'woocommerce_payments_sepa_debit',
				paymentMethodTypes: [ 'sepa_debit' ],
				paymentMethodsConfig: {
					sepa_debit: {
						isReusable: false,
					},
				},
			} );
		const cardContainer = document
			.getElementById( 'payment_method_woocommerce_payments' )
			.closest( 'li' )
			.querySelector( '#wcpay-core-payment-element' );
		const selectedContainer = document
			.getElementById( 'payment_method_woocommerce_payments_sepa_debit' )
			.closest( 'li' )
			.querySelector( '#wcpay-core-payment-element' );

		require( '../woopayments-checkout' );
		document.getElementById(
			'payment_method_woocommerce_payments'
		).checked = false;
		document.getElementById(
			'payment_method_woocommerce_payments_sepa_debit'
		).checked = true;
		mountPaymentElement.mockClear();
		unmountPaymentElement.mockClear();
		bodyEventHandlers.payment_method_selected();

		expect( unmountPaymentElement ).toHaveBeenCalledTimes( 1 );
		expect( stripeElementsOptions ).toMatchObject( {
			paymentMethodTypes: [ 'sepa_debit' ],
		} );
		expect( mountPaymentElement ).toHaveBeenCalledWith( selectedContainer );
		expect( mountPaymentElement ).not.toHaveBeenCalledWith( cardContainer );
	} );

	test( 'initializes classic Stripe Elements with cached appearance and font rules', () => {
		const appearance = {
			theme: 'stripe',
			labels: 'floating',
			rules: {
				'.Input': {
					fontSize: '16px',
				},
			},
		};
		const originalStyleSheets = document.styleSheets;
		Object.defineProperty( document, 'styleSheets', {
			configurable: true,
			value: [
				{
					href: 'https://fonts.wp.com/inter.css',
				},
				{
					href: 'https://example.test/theme.css',
				},
			],
		} );
		window.localStorage.setItem(
			'wcpay_appearance_classic_checkout',
			JSON.stringify( {
				version: 'styles-v1',
				appearance,
			} )
		);

		try {
			require( '../woopayments-checkout' );

			expect( stripeElementsOptions ).toMatchObject( {
				appearance,
				fonts: [
					{
						cssSrc: 'https://fonts.wp.com/inter.css',
					},
				],
				loader: 'never',
			} );
		} finally {
			Object.defineProperty( document, 'styleSheets', {
				configurable: true,
				value: originalStyleSheets,
			} );
		}
	} );

	test( 'persists valid classic appearance to the shared WooPay shopper endpoint once', async () => {
		const appearance = {
			theme: 'stripe',
			rules: {
				'.Input': {
					fontSize: '16px',
				},
			},
		};
		window.wcpay_core_checkout_config.isWooPayGlobalThemeSupportEnabled = true;
		window.wcpay_core_checkout_config.woopaySessionNonce = 'session-nonce';
		window.wcpay_core_checkout_config.wcAjaxUrl = '/?wc-ajax=%%endpoint%%';
		window.localStorage.setItem(
			'wcpay_appearance_classic_checkout',
			JSON.stringify( {
				version: 'styles-v1',
				appearance,
			} )
		);

		require( '../woopayments-checkout' );
		bodyEventHandlers.payment_method_selected();
		await flushPromises();

		const persistenceRequests = window.fetch.mock.calls.filter(
			( [ url ] ) =>
				url === '/?wc-ajax=wcpay_shopper_set_woopay_appearance'
		);
		expect( persistenceRequests ).toHaveLength( 1 );
		expect( persistenceRequests[ 0 ][ 1 ].body.get( '_ajax_nonce' ) ).toBe(
			'session-nonce'
		);
		expect(
			persistenceRequests[ 0 ][ 1 ].body.get(
				'appearance[rules][.Input][fontSize]'
			)
		).toBe( '16px' );
		expect( persistenceRequests[ 0 ][ 1 ].body.get( 'font_rules' ) ).toBe(
			'[]'
		);
	} );

	test( 'omits computed alpha color values from generated classic Stripe Elements appearance rules', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<p class="form-row"><label for="billing_first_name">First name</label><input id="billing_first_name" type="text" /></p>' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<button id="place_order" type="button">Place order</button>' +
			'</form>';
		jest.spyOn( window, 'getComputedStyle' ).mockImplementation(
			( element ) => ( {
				getPropertyValue: ( property ) => {
					if ( element.id === 'billing_first_name' ) {
						return (
							{
								border: '1px solid color(srgb 0.168627 0.176471 0.184314 / 0.8)',
								'border-color':
									'color(srgb 0.168627 0.176471 0.184314 / 0.8)',
								'border-style': 'solid',
								'border-width': '1px',
								'box-shadow': 'rgb(43 45 47 / 0.8) 0px 1px 2px',
								color: 'rgb(43 45 47)',
								'font-size': '16px',
							}[ property ] || ''
						);
					}

					return (
						{
							'background-color': 'rgb(255, 255, 255)',
							color: 'rgb(43 45 47)',
							'font-size': '16px',
						}[ property ] || ''
					);
				},
			} )
		);

		require( '../woopayments-checkout' );

		expect(
			stripeElementsOptions.appearance.rules[ '.Input' ]
		).toMatchObject( {
			borderStyle: 'solid',
			borderWidth: '1px',
			color: 'rgb(43, 45, 47)',
			fontSize: '16px',
		} );
		expect(
			stripeElementsOptions.appearance.rules[ '.Input' ]
		).not.toHaveProperty( 'border' );
		expect(
			stripeElementsOptions.appearance.rules[ '.Input' ]
		).not.toHaveProperty( 'borderColor' );
		expect(
			stripeElementsOptions.appearance.rules[ '.Input' ]
		).not.toHaveProperty( 'boxShadow' );
	} );

	test( 'uses setup mode when the checkout total is zero', () => {
		window.wcpay_core_checkout_config.cartTotal = '0';

		require( '../woopayments-checkout' );

		expect( stripeElementsOptions ).toMatchObject( {
			currency: 'gbp',
			mode: 'setup',
			paymentMethodCreation: 'manual',
			paymentMethodTypes: [ 'card', 'link' ],
		} );
		expect( stripeElementsOptions ).not.toHaveProperty( 'amount' );
	} );

	test( 'passes card PaymentElement fields, wallets, and terms options', () => {
		require( '../woopayments-checkout' );

		expect( elementsMock.create ).toHaveBeenCalledWith(
			'payment',
			expect.objectContaining( {
				fields: {
					billingDetails: {
						name: 'never',
						email: 'never',
						phone: 'never',
						address: {
							country: 'never',
							line1: 'never',
							line2: 'never',
							city: 'never',
							state: 'never',
							postalCode: 'never',
						},
					},
				},
				wallets: {
					applePay: 'never',
					googlePay: 'never',
					link: 'auto',
				},
				terms: {
					card: 'never',
				},
			} )
		);
		expect( paymentElementOptions ).toMatchObject( {
			wallets: {
				link: 'auto',
			},
			terms: {
				card: 'never',
			},
		} );
	} );

	test( 'hides only enabled billing fields in the payment element like the reference client', () => {
		window.wcpay_core_checkout_config.enabledBillingFields = {
			billing_first_name: { required: true },
			billing_last_name: { required: true },
			billing_email: { required: true },
			billing_country: { required: true },
			billing_address_1: { required: true },
			billing_city: { required: true },
			billing_postcode: { required: true },
		};

		require( '../woopayments-checkout' );

		expect( elementsMock.create ).toHaveBeenCalledWith(
			'payment',
			expect.objectContaining( {
				fields: {
					billingDetails: {
						name: 'never',
						email: 'never',
						phone: 'auto',
						address: {
							country: 'never',
							line1: 'never',
							line2: 'auto',
							city: 'never',
							state: 'auto',
							postalCode: 'never',
						},
					},
				},
			} )
		);

		delete window.wcpay_core_checkout_config.enabledBillingFields;
	} );

	test( 'uses setup mode on the add-payment-method form', () => {
		document.body.innerHTML =
			'<form id="add_payment_method">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';
		window.wcpay_core_checkout_config.isCheckout = false;
		window.wcpay_core_checkout_config.customerData = {
			name: 'Ada Lovelace',
			email: 'ada@example.com',
			address: {
				country: 'US',
			},
		};

		require( '../woopayments-checkout' );

		expect( stripeElementsOptions ).toMatchObject( {
			currency: 'gbp',
			mode: 'setup',
			paymentMethodCreation: 'manual',
			paymentMethodTypes: [ 'card', 'link' ],
		} );
		expect( stripeElementsOptions ).not.toHaveProperty( 'amount' );
		expect( paymentElementOptions ).toMatchObject( {
			wallets: {
				link: 'never',
			},
		} );
		expect( paymentElementOptions ).not.toHaveProperty( 'fields' );
		expect( paymentElementOptions.defaultValues ).toEqual( {
			billingDetails: window.wcpay_core_checkout_config.customerData,
		} );
	} );

	test( 'updates reusable card terms when the save-payment checkbox changes', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<input id="wc-woocommerce_payments-new-payment-method" type="checkbox" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';

		require( '../woopayments-checkout' );

		document
			.getElementById( 'wc-woocommerce_payments-new-payment-method' )
			.dispatchEvent(
				new window.Event( 'change', {
					bubbles: true,
					cancelable: true,
				} )
			);

		expect( updatePaymentElement ).toHaveBeenCalledWith( {
			terms: {
				card: 'always',
			},
		} );
	} );

	test( 'handles PaymentIntent confirmation hashes through next actions', async () => {
		window.location.hash =
			'#wcpay-confirm-pi:123:pi_native_secret_abc:nonce';

		require( '../woopayments-checkout' );

		await flushPromises();

		expect( stripeMock.handleNextAction ).toHaveBeenCalledWith( {
			clientSecret: 'pi_native_secret_abc',
		} );
		expect( stripeMock.confirmPayment ).not.toHaveBeenCalled();
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				order_id: '123',
				_ajax_nonce: 'nonce',
				intent_id: 'pi_native',
				should_save_payment_method: 'false',
			} )
		);
	} );

	test( 'carries order-pay save intent through confirmation and consumes only its transient URL state', async () => {
		const replaceState = jest.spyOn( window.history, 'replaceState' );
		document.body.innerHTML =
			'<form id="order_review">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'</form>';
		window.history.pushState(
			{},
			'',
			'/checkout/order-pay/123/?pay_for_order=true&key=wc_order_test' +
				'&extension=kept&save_payment_method=yes' +
				'#wcpay-confirm-pi:123:pi_native_secret_abc:nonce'
		);

		require( '../woopayments-checkout' );

		expect( replaceState ).toHaveBeenCalledWith(
			'',
			document.title,
			'/checkout/order-pay/123/?pay_for_order=true&key=wc_order_test&extension=kept'
		);
		expect( window.location.hash ).toBe( '' );
		expect( window.location.search ).toBe(
			'?pay_for_order=true&key=wc_order_test&extension=kept'
		);

		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				should_save_payment_method: 'true',
			} )
		);
	} );

	test( 'snapshots checked save intent before pending confirmation work', async () => {
		let resolveConfirmation;
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<input id="wc-woocommerce_payments-new-payment-method" type="checkbox" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';
		stripeMock.handleNextAction.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveConfirmation = resolve;
				} )
		);
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );

		document.getElementById(
			'wc-woocommerce_payments-new-payment-method'
		).checked = false;
		resolveConfirmation( {
			paymentIntent: { id: 'pi_native' },
		} );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				should_save_payment_method: 'true',
			} )
		);
	} );

	test( 'blocks a fresh confirmation before Stripe work and keeps it blocked while pending', async () => {
		let resolveConfirmation;
		stripeMock.handleNextAction.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveConfirmation = resolve;
				} )
		);
		setPaymentIntentConfirmationHash( 'pi_pending' );

		require( '../woopayments-checkout' );

		expect( window.location.hash ).toBe( '' );
		expectClassicCheckoutBlockCount( 1 );
		expect(
			global.jQuery.checkoutFormResult.addClass
		).toHaveBeenCalledWith( 'processing' );
		expect( global.jQuery.checkoutFormResult.block ).toHaveBeenCalledWith( {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6,
			},
		} );
		expect(
			global.jQuery.checkoutFormResult.block.mock.invocationCallOrder[ 0 ]
		).toBeLessThan(
			stripeMock.handleNextAction.mock.invocationCallOrder[ 0 ]
		);
		expectClassicCheckoutUiState( true );
		expectClassicCheckoutReleaseCount( 0 );

		resolveConfirmation( {
			error: {
				message: 'Authentication failed.',
				payment_intent: {
					id: 'pi_pending',
					status: 'requires_payment_method',
				},
			},
		} );
		await flushPromises();

		expectClassicCheckoutBlockCount( 1 );
		expectClassicCheckoutReleaseCount( 1 );
		expectClassicCheckoutUiState( false );
	} );

	test( 'releases classic checkout after a retry-safe 3DS failure', async () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<div id="wcpay-core-payment-errors" hidden></div>'
			);
		stripeMock.handleNextAction.mockResolvedValueOnce( {
			error: {
				message:
					'We are unable to authenticate your payment method. Please choose a different payment method and try again.',
				payment_intent: {
					id: 'pi_failed_authentication',
					status: 'requires_payment_method',
				},
			},
		} );
		window.location.hash =
			'#wcpay-confirm-pi:123:pi_failed_authentication_secret_abc:nonce';

		require( '../woopayments-checkout' );
		await flushPromises();

		expect(
			document.getElementById( 'wcpay-core-payment-errors' ).textContent
		).toBe(
			'We are unable to authenticate your payment method. Please choose a different payment method and try again.'
		);
		expect(
			global.jQuery.checkoutFormResult.removeClass
		).toHaveBeenCalledTimes( 1 );
		expect(
			global.jQuery.checkoutFormResult.removeClass
		).toHaveBeenCalledWith( 'processing' );
		expect(
			global.jQuery.checkoutFormResult.unblock
		).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutBlockCount( 1 );
		expectClassicCheckoutUiState( false );
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				order_id: '123',
				_ajax_nonce: 'nonce',
				intent_id: 'pi_failed_authentication',
			} )
		);
		expect( stripeMock.createPaymentMethod ).not.toHaveBeenCalled();
		expect(
			global.jQuery.checkoutFormResult.trigger
		).not.toHaveBeenCalledWith( 'submit' );
	} );

	test( 'consumes each classic confirmation hash once', async () => {
		const replaceState = jest.spyOn( window.history, 'replaceState' );
		stripeMock.handleNextAction.mockResolvedValue( {
			error: {
				message: 'Authentication failed.',
				payment_intent: {
					id: 'pi_failed_authentication',
					status: 'requires_payment_method',
				},
			},
		} );
		window.location.hash =
			'#wcpay-confirm-pi:123:pi_failed_authentication_secret_abc:nonce';

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( replaceState ).toHaveBeenCalledWith( '', document.title, '/' );
		expect( window.location.hash ).toBe( '' );
		expect( stripeMock.handleNextAction ).toHaveBeenCalledTimes( 1 );

		windowEventHandlers.hashchange();
		await flushPromises();

		expect( stripeMock.handleNextAction ).toHaveBeenCalledTimes( 1 );
		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				intent_id: 'pi_failed_authentication',
			} )
		);
		expectClassicCheckoutReleaseCount( 1 );
		expectClassicCheckoutBlockCount( 1 );
		expectClassicCheckoutUiState( false );
		expectNoConfirmationResubmit();
	} );

	test( 'releases the active order-pay form after a retry-safe failure', async () => {
		document.body.innerHTML =
			'<form id="order_review">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-errors" hidden></div>' +
			'</form>';
		stripeMock.handleNextAction.mockResolvedValueOnce( {
			error: {
				message: 'Authentication failed.',
				payment_intent: {
					id: 'pi_order_pay_failed',
					status: 'requires_payment_method',
				},
			},
		} );
		setPaymentIntentConfirmationHash( 'pi_order_pay_failed' );

		require( '../woopayments-checkout' );
		await flushPromises();

		expect(
			global.jQuery.orderPayFormResult.removeClass
		).toHaveBeenCalledWith( 'processing' );
		expect(
			global.jQuery.orderPayFormResult.unblock
		).toHaveBeenCalledTimes( 1 );
		expectOrderPayBlockCount( 1 );
		expect( orderPayFormState ).toEqual( {
			processing: false,
			blocked: false,
		} );
		expectClassicCheckoutReleaseCount( 0 );
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				intent_id: 'pi_order_pay_failed',
			} )
		);
	} );

	test( 'keeps ambiguous 3DS failures non-reentrant', async () => {
		stripeMock.handleNextAction.mockResolvedValueOnce( {
			error: {
				message: 'Payment status is uncertain.',
				payment_intent: {
					id: 'pi_ambiguous',
					status: 'processing',
				},
			},
		} );
		window.location.hash =
			'#wcpay-confirm-pi:123:pi_ambiguous_secret_abc:nonce';

		require( '../woopayments-checkout' );
		await flushPromises();

		expect(
			global.jQuery.checkoutFormResult.removeClass
		).not.toHaveBeenCalled();
		expect(
			global.jQuery.checkoutFormResult.unblock
		).not.toHaveBeenCalled();
		expectClassicCheckoutBlockedOnce();
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				intent_id: 'pi_ambiguous',
			} )
		);
	} );

	test( 'releases classic checkout after a canceled PaymentIntent', async () => {
		stripeMock.handleNextAction.mockResolvedValueOnce( {
			error: {
				message: 'Authentication was canceled.',
				payment_intent: {
					id: 'pi_canceled',
					status: 'canceled',
				},
			},
		} );
		setPaymentIntentConfirmationHash( 'pi_canceled' );

		require( '../woopayments-checkout' );
		await flushPromises();

		expectClassicCheckoutReleaseCount( 1 );
		expectClassicCheckoutBlockCount( 1 );
		expectClassicCheckoutUiState( false );
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				intent_id: 'pi_canceled',
			} )
		);
		expectNoConfirmationResubmit();
	} );

	test.each( [
		[ 'succeeded', 'succeeded' ],
		[ 'missing', undefined ],
	] )(
		'keeps a %s PaymentIntent error non-reentrant',
		async ( label, status ) => {
			stripeMock.handleNextAction.mockResolvedValueOnce( {
				error: {
					message: 'Payment status is not retry-safe.',
					payment_intent: {
						id: 'pi_' + label,
						status,
					},
				},
			} );
			setPaymentIntentConfirmationHash( 'pi_' + label );

			require( '../woopayments-checkout' );
			await flushPromises();

			expectClassicCheckoutBlockedOnce();
			expect( global.jQuery.post ).toHaveBeenCalledWith(
				'https://example.test/admin-ajax.php',
				expect.objectContaining( {
					action: 'update_order_status',
					intent_id: 'pi_' + label,
				} )
			);
			expectNoConfirmationResubmit();
		}
	);

	test( 'keeps scalar Stripe errors non-reentrant', async () => {
		stripeMock.handleNextAction.mockResolvedValueOnce( {
			error: 'Authentication failed.',
		} );
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expectClassicCheckoutBlockedOnce();
		expect( global.jQuery.post ).not.toHaveBeenCalled();
		expectNoConfirmationResubmit();
	} );

	test.each( [
		[ 'absent', undefined ],
		[ 'null', null ],
		[ 'scalar', 'unexpected' ],
		[ 'missing intent', {} ],
		[ 'malformed intent', { paymentIntent: { id: '' } } ],
	] )(
		'keeps the %s Stripe confirmation result non-reentrant',
		async ( label, result ) => {
			stripeMock.handleNextAction.mockResolvedValueOnce( result );
			setPaymentIntentConfirmationHash();

			require( '../woopayments-checkout' );
			await flushPromises();

			expectClassicCheckoutBlockedOnce();
			expect( global.jQuery.post ).not.toHaveBeenCalled();
			expectNoConfirmationResubmit();
		}
	);

	test( 'keeps a rejected Stripe confirmation non-reentrant', async () => {
		stripeMock.handleNextAction.mockRejectedValueOnce(
			new Error( 'Stripe.js failed.' )
		);
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expectClassicCheckoutBlockedOnce();
		expect( global.jQuery.post ).not.toHaveBeenCalled();
		expectNoConfirmationResubmit();
	} );

	test( 'consumes the hash but stays blocked without a Stripe confirmation capability', async () => {
		delete stripeMock.handleNextAction;
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( window.location.hash ).toBe( '' );
		expectClassicCheckoutBlockedOnce();
		expect( global.jQuery.post ).not.toHaveBeenCalled();
		expectNoConfirmationResubmit();
	} );

	test( 'keeps malformed confirmation callback JSON non-reentrant', async () => {
		mockConfirmationCallbackResponse( '{' );
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutBlockedOnce();
		expectNoConfirmationResubmit();
	} );

	test( 'keeps confirmation callback application errors non-reentrant', async () => {
		mockConfirmationCallbackResponse( {
			error: { message: 'The order could not be updated.' },
		} );
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutBlockedOnce();
		expectNoConfirmationResubmit();
	} );

	test( 'keeps confirmation callback transport failures non-reentrant', async () => {
		mockConfirmationCallbackFailure();
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutBlockedOnce();
		expectNoConfirmationResubmit();
	} );

	test( 'keeps callback success without a return URL non-reentrant', async () => {
		mockConfirmationCallbackResponse( { status_code: 200 } );
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutBlockedOnce();
		expectNoConfirmationResubmit();
	} );

	test( 'keeps callback success with an invalid return URL non-reentrant', async () => {
		mockConfirmationCallbackResponse( {
			status_code: 200,
			return_url: 'http://[',
		} );
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutBlockedOnce();
		expect( window.location.hash ).toBe( '' );
		expectNoConfirmationResubmit();
	} );

	test.each( [
		[ 'JavaScript', 'javascript:void(0)' ],
		[ 'data', 'data:text/plain,order-received' ],
	] )(
		'keeps callback success with a %s return URL non-reentrant',
		async ( label, returnUrl ) => {
			mockConfirmationCallbackResponse( {
				status_code: 200,
				return_url: returnUrl,
			} );
			setPaymentIntentConfirmationHash();

			require( '../woopayments-checkout' );
			await flushPromises();

			expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
			expectClassicCheckoutBlockedOnce();
			expect( window.location.hash ).toBe( '' );
			expectNoConfirmationResubmit();
		}
	);

	test( 'releases once immediately before valid return navigation', async () => {
		let hashWhenReleased;
		global.jQuery.checkoutFormResult.unblock.mockImplementationOnce( () => {
			hashWhenReleased = window.location.hash;
			checkoutFormState.blocked = false;
			return global.jQuery.checkoutFormResult;
		} );
		mockConfirmationCallbackResponse( {
			status_code: 200,
			return_url: window.location.origin + '/#order-received',
		} );
		setPaymentIntentConfirmationHash();

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledTimes( 1 );
		expectClassicCheckoutReleaseCount( 1 );
		expectClassicCheckoutBlockCount( 1 );
		expectClassicCheckoutUiState( false );
		expect(
			global.jQuery.checkoutFormResult.removeClass
		).toHaveBeenCalledWith( 'processing' );
		expect( hashWhenReleased ).toBe( '' );
		expect( window.location.hash ).toBe( '#order-received' );
		expectNoConfirmationResubmit();
	} );

	test( 'preserves SetupIntent confirmation callbacks', async () => {
		window.location.hash =
			'#wcpay-confirm-si:123:seti_native_secret_abc:nonce:ctoken_native';

		require( '../woopayments-checkout' );
		await flushPromises();

		expect( stripeMock.confirmSetup ).toHaveBeenCalledWith( {
			clientSecret: 'seti_native_secret_abc',
			confirmParams: {
				confirmation_token: 'ctoken_native',
			},
			redirect: 'if_required',
		} );
		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				intent_id: 'seti_native',
			} )
		);
		expect( window.location.hash ).toBe( '' );
		expectClassicCheckoutBlockedOnce();
	} );

	test( 'marks confirmation callbacks as subscription payment-method changes on change-payment URLs', async () => {
		window.history.pushState(
			{},
			'',
			'/checkout/order-pay/123/?change_payment_method=123#wcpay-confirm-pi:123:pi_native_secret_abc:nonce'
		);

		require( '../woopayments-checkout' );

		await flushPromises();

		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'update_order_status',
				is_changing_payment: 'true',
			} )
		);
	} );

	test( 'lets the form submit for server validation when required billing fields are missing', async () => {
		window.wcpay_core_checkout_config.enabledBillingFields = {
			billing_email: { required: true },
			billing_country: { required: true },
		};
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<input type="email" id="billing_email" value="" />' +
					'<select id="billing_country"><option value="US" selected>US</option></select>'
			);

		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( true );

		await flushPromises();

		expect( submitElements ).not.toHaveBeenCalled();
		expect( stripeMock.createPaymentMethod ).not.toHaveBeenCalled();

		delete window.wcpay_core_checkout_config.enabledBillingFields;
	} );

	test( 'intercepts the submission when the enabled billing fields are filled', async () => {
		window.wcpay_core_checkout_config.enabledBillingFields = {
			billing_email: { required: true },
		};
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<input type="email" id="billing_email" value="shopper@example.test" />'
			);

		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect( stripeMock.createPaymentMethod ).toHaveBeenCalled();

		delete window.wcpay_core_checkout_config.enabledBillingFields;
	} );

	test( 'honors locale-specific requirements over the enabled-fields flag', async () => {
		window.wcpay_core_checkout_config.enabledBillingFields = {
			billing_country: { required: true },
			billing_postcode: { required: true },
		};
		window.wc_address_i18n_params = {
			locale: JSON.stringify( {
				AE: { postcode: { required: false } },
				default: { postcode: { required: true } },
			} ).replace( /"/g, '&quot;' ),
		};
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<select id="billing_country"><option value="AE" selected>AE</option></select>' +
					'<input type="text" id="billing_postcode" value="" />'
			);

		require( '../woopayments-checkout' );

		// The locale says the postcode is optional in AE, so the submission
		// is intercepted even though the field is empty.
		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		delete window.wcpay_core_checkout_config.enabledBillingFields;
		delete window.wc_address_i18n_params;
	} );

	test( 'strips a partial billing address for BNPL on the pay-for-order page', async () => {
		window.wcpay_core_checkout_config.isOrderPay = true;
		window.wcpay_core_checkout_config.paymentMethodTypes = [ 'affirm' ];
		document.body.innerHTML =
			'<form id="order_review">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<input type="text" id="billing_first_name" value="Ada" />' +
			'<input type="text" id="billing_last_name" value="Lovelace" />' +
			'<input type="email" id="billing_email" value="ada@example.test" />' +
			'<input type="text" id="billing_country" value="US" />' +
			'</form>';

		require( '../woopayments-checkout' );

		orderPayFormEventHandlers.submit.call(
			document.getElementById( 'order_review' )
		);
		await flushPromises();

		const request = stripeMock.createPaymentMethod.mock.calls[ 0 ][ 0 ];
		expect( request.params.billing_details.address ).toBeUndefined();
		expect( request.params.billing_details.name ).toBe( 'Ada Lovelace' );

		delete window.wcpay_core_checkout_config.isOrderPay;
		delete window.wcpay_core_checkout_config.paymentMethodTypes;
	} );

	test( 'submits the checkout with the error sentinel when payment method creation fails', async () => {
		stripeMock.createPaymentMethod.mockResolvedValueOnce( {
			error: {
				code: 'incomplete_number',
				decline_code: 'do_not_honor',
				message: 'Your card number is invalid.',
				type: 'validation_error',
			},
		} );

		require( '../woopayments-checkout' );
		checkoutFormEventHandlers.checkout_place_order_woocommerce_payments();
		await flushPromises();

		const fields = global.jQuery.checkoutFormFields;
		expect( fields[ 'wcpay-payment-method' ].value ).toBe(
			'woocommerce_payments_payment_method_error'
		);
		expect( fields[ 'wcpay-payment-method-error-code' ].value ).toBe(
			'incomplete_number'
		);
		expect(
			fields[ 'wcpay-payment-method-error-decline-code' ].value
		).toBe( 'do_not_honor' );
		expect( fields[ 'wcpay-payment-method-error-message' ].value ).toBe(
			'Your card number is invalid.'
		);
		expect( fields[ 'wcpay-payment-method-error-type' ].value ).toBe(
			'validation_error'
		);
		expect(
			global.jQuery.checkoutFormResult.trigger
		).toHaveBeenCalledWith( 'submit' );
	} );

	test( 'submits Stripe Elements before creating a checkout payment method', async () => {
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect( submitElements ).toHaveBeenCalled();
		expect( stripeMock.createPaymentMethod ).toHaveBeenCalledWith( {
			elements: elementsMock,
		} );
		expect( submitElements.mock.invocationCallOrder[ 0 ] ).toBeLessThan(
			stripeMock.createPaymentMethod.mock.invocationCallOrder[ 0 ]
		);
	} );

	test( 'refuses a checkout submission when the payment element failed to load', async () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'beforeend',
				'<div id="wcpay-core-payment-errors" hidden></div>'
			);

		require( '../woopayments-checkout' );

		paymentElementHandlers.loaderror( {
			error: { message: 'The payment form could not be loaded.' },
		} );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect( submitElements ).not.toHaveBeenCalled();
		expect( stripeMock.createPaymentMethod ).not.toHaveBeenCalled();
		expect(
			document.getElementById( 'wcpay-core-payment-errors' ).textContent
		).toBe( 'The payment form could not be loaded.' );
	} );

	test( 'intercepts the WooCommerce form checkout event before creating a payment method', async () => {
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments
		).toBeDefined();
		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect( stripeMock.createPaymentMethod ).toHaveBeenCalledWith( {
			elements: elementsMock,
		} );
		expect(
			global.jQuery.checkoutFormFields[ 'wcpay-payment-method' ].value
		).toBe( 'pm_native' );
	} );

	test( 'intercepts and resubmits the order-pay form with a created payment method', async () => {
		document.body.innerHTML =
			'<form id="order_review">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';
		window.wcpay_core_checkout_config.isOrderPay = true;

		require( '../woopayments-checkout' );

		expect( orderPayFormEventHandlers.submit ).toBeDefined();
		expect(
			orderPayFormEventHandlers.submit.call(
				document.getElementById( 'order_review' )
			)
		).toBe( false );

		await flushPromises();

		expect( stripeMock.createPaymentMethod ).toHaveBeenCalledWith( {
			elements: elementsMock,
		} );
		expect(
			global.jQuery.orderPayFormFields[ 'wcpay-payment-method' ].value
		).toBe( 'pm_native' );
		expect(
			global.jQuery.checkoutFormFields[ 'wcpay-payment-method' ]
		).toBeUndefined();
		expect( global.jQuery.orderPayFormResult.trigger ).toHaveBeenCalledWith(
			'submit'
		);
	} );

	test( 'passes checkout billing details when creating a payment method', async () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'afterbegin',
				'<input id="billing_first_name" value="Saved" />' +
					'<input id="billing_last_name" value="Target" />' +
					'<input id="billing_email" value="sc04-target@example.test" />' +
					'<input id="billing_phone" value="4155551234" />' +
					'<input id="billing_city" value="San Francisco" />' +
					'<input id="billing_country" value="US" />' +
					'<input id="billing_address_1" value="123 Main St" />' +
					'<input id="billing_address_2" value="Suite 4" />' +
					'<input id="billing_postcode" value=" 94103 " />' +
					'<input id="billing_state" value="CA" />'
			);
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect( stripeMock.createPaymentMethod ).toHaveBeenCalledWith( {
			elements: elementsMock,
			params: {
				billing_details: {
					name: 'Saved Target',
					email: 'sc04-target@example.test',
					phone: '4155551234',
					address: {
						city: 'San Francisco',
						country: 'US',
						line1: '123 Main St',
						line2: 'Suite 4',
						postal_code: '94103',
						state: 'CA',
					},
				},
			},
		} );
	} );

	test( 'passes empty billing state when checkout has no state value', async () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'afterbegin',
				'<input id="billing_first_name" value="Lpm" />' +
					'<input id="billing_last_name" value="Checkout" />' +
					'<input id="billing_email" value="lpm-sepa@example.test" />' +
					'<input id="billing_phone" value="+15555550123" />' +
					'<input id="billing_city" value="Amsterdam" />' +
					'<input id="billing_country" value="NL" />' +
					'<input id="billing_address_1" value="Damrak 1" />' +
					'<input id="billing_address_2" value="" />' +
					'<input id="billing_postcode" value="1012LG" />' +
					'<input id="billing_state" value="" />'
			);
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect(
			stripeMock.createPaymentMethod.mock.calls[ 0 ][ 0 ].params
				.billing_details.address.state
		).toBe( '' );
	} );

	test( 'omits billing details for fields the checkout form does not render', async () => {
		document
			.querySelector( 'form.checkout' )
			.insertAdjacentHTML(
				'afterbegin',
				'<input id="billing_email" value="trimmed@example.test" />' +
					'<input id="billing_country" value="US" />' +
					'<input id="billing_address_1" value="123 Main St" />'
			);
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		const billingDetails =
			stripeMock.createPaymentMethod.mock.calls[ 0 ][ 0 ].params
				.billing_details;
		expect( billingDetails.name ).toBeUndefined();
		expect( billingDetails.phone ).toBeUndefined();
		expect( billingDetails.email ).toBe( 'trimmed@example.test' );
		expect( billingDetails.address.city ).toBeUndefined();
		expect( billingDetails.address.line2 ).toBeUndefined();
		expect( billingDetails.address.postal_code ).toBeUndefined();
		expect( billingDetails.address.state ).toBeUndefined();
		expect( billingDetails.address.country ).toBe( 'US' );
		expect( billingDetails.address.line1 ).toBe( '123 Main St' );
	} );

	test( 'adds the fraud-prevention token before submitting a new-card classic checkout', async () => {
		window.wcpay_core_checkout_config.fraudPreventionToken =
			'fraud-token-123';
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( false );

		await flushPromises();

		expect(
			global.jQuery.checkoutFormFields[ 'wcpay-fraud-prevention-token' ]
				.value
		).toBe( 'fraud-token-123' );
	} );

	test( 'submits classic checkout without creating a payment method when a saved token is selected', async () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<input id="wc-woocommerce_payments-payment-token-new" ' +
			'name="wc-woocommerce_payments-payment-token" type="radio" value="new" />' +
			'<input id="wc-woocommerce_payments-payment-token-12" ' +
			'name="wc-woocommerce_payments-payment-token" type="radio" value="12" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( true );

		await flushPromises();

		expect( submitElements ).not.toHaveBeenCalled();
		expect( stripeMock.createPaymentMethod ).not.toHaveBeenCalled();
	} );

	test( 'adds the fraud-prevention token before submitting a saved-card classic checkout', async () => {
		window.wcpay_core_checkout_config.fraudPreventionToken =
			'fraud-token-123';
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<input id="wc-woocommerce_payments-payment-token-new" ' +
			'name="wc-woocommerce_payments-payment-token" type="radio" value="new" />' +
			'<input id="wc-woocommerce_payments-payment-token-12" ' +
			'name="wc-woocommerce_payments-payment-token" type="radio" value="12" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';
		require( '../woopayments-checkout' );

		expect(
			checkoutFormEventHandlers.checkout_place_order_woocommerce_payments()
		).toBe( true );

		expect(
			global.jQuery.checkoutFormFields[ 'wcpay-fraud-prevention-token' ]
				.value
		).toBe( 'fraud-token-123' );
		expect( submitElements ).not.toHaveBeenCalled();
		expect( stripeMock.createPaymentMethod ).not.toHaveBeenCalled();
	} );

	test( 'records a place-order event when the shopper clicks the classic checkout button', () => {
		require( '../woopayments-checkout' );

		document.getElementById( 'place_order' ).dispatchEvent(
			new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} )
		);

		expect( getTrackingEvents() ).toEqual(
			expect.arrayContaining( [
				{
					name: 'checkout_place_order_button_click',
					props: {},
				},
			] )
		);
	} );

	test( 'remounts the payment element after checkout updates replace the payment markup', () => {
		require( '../woopayments-checkout' );

		const initialContainer = document.getElementById(
			'wcpay-core-payment-element'
		);

		expect( mountPaymentElement ).toHaveBeenCalledWith( initialContainer );

		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';

		const replacementContainer = document.getElementById(
			'wcpay-core-payment-element'
		);

		bodyEventHandlers.updated_checkout();

		expect( unmountPaymentElement ).toHaveBeenCalledTimes( 1 );
		expect( mountPaymentElement ).toHaveBeenCalledWith(
			replacementContainer
		);
	} );

	test( 'toggles country-restricted split gateways when checkout billing country changes', () => {
		window.wcpay_core_checkout_config.paymentMethodsConfig.card.countries =
			[];
		window.wcpay_core_checkout_config_woocommerce_payments_ideal =
			Object.assign( {}, window.wcpay_core_checkout_config, {
				gatewayId: 'woocommerce_payments_ideal',
				paymentMethodId: 'ideal',
				paymentMethodTypes: [ 'ideal' ],
				paymentMethodsConfig: {
					ideal: {
						countries: [ 'BE' ],
						isReusable: false,
					},
				},
		} );
		document.body.innerHTML =
			'<form class="checkout">' +
			'<select id="billing_country" name="billing_country">' +
			'<option value="US" selected>US</option>' +
			'<option value="BE">BE</option>' +
			'</select>' +
			'<ul>' +
			'<li id="payment-card"><input type="radio" name="payment_method" value="woocommerce_payments" /></li>' +
			'<li id="payment-ideal"><input type="radio" name="payment_method" value="woocommerce_payments_ideal" checked />' +
			'<div id="wcpay-core-payment-element"></div></li>' +
			'</ul>' +
			'</form>';

		require( '../woopayments-checkout' );
		bodyEventHandlers.updated_checkout();

		expect( document.getElementById( 'payment-ideal' ).style.display ).toBe(
			'none'
		);
		expect(
			document.querySelector( 'input[value="woocommerce_payments"]' )
				.checked
		).toBe( true );

		document.getElementById( 'billing_country' ).value = 'BE';
		bodyEventHandlers.updated_checkout();

		expect( document.getElementById( 'payment-ideal' ).style.display ).toBe(
			''
		);
	} );

	function renderTestNumberButton() {
		document.body.innerHTML +=
			'<button type="button" class="js-woopayments-copy-test-number">' +
			'<i></i><span>4242 4242 4242 4242</span></button>';

		return document.querySelector( '.js-woopayments-copy-test-number' );
	}

	test( 'prevents the default action when copying the test card number', () => {
		const writeText = jest.fn();
		Object.defineProperty( window.navigator, 'clipboard', {
			value: {
				writeText,
			},
			configurable: true,
		} );
		const button = renderTestNumberButton();
		const event = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );

		require( '../woopayments-checkout' );

		button.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
	} );

	test( 'copies the test card number with the Clipboard API', () => {
		const writeText = jest.fn();
		Object.defineProperty( window.navigator, 'clipboard', {
			value: {
				writeText,
			},
			configurable: true,
		} );
		const button = renderTestNumberButton();

		require( '../woopayments-checkout' );

		button.click();

		expect( writeText ).toHaveBeenCalledWith( '4242 4242 4242 4242' );
	} );

	test( 'shows the test card number in a prompt when the Clipboard API is unavailable', () => {
		const prompt = jest
			.spyOn( window, 'prompt' )
			.mockImplementation( () => null );
		const button = renderTestNumberButton();

		require( '../woopayments-checkout' );

		button.click();

		expect( prompt ).toHaveBeenCalledWith(
			'Copy test card number:',
			'4242 4242 4242 4242'
		);
	} );

	test( 'shows and clears the copied state after copying the test card number', () => {
		jest.useFakeTimers();
		const writeText = jest.fn();
		Object.defineProperty( window.navigator, 'clipboard', {
			value: {
				writeText,
			},
			configurable: true,
		} );
		const button = renderTestNumberButton();

		require( '../woopayments-checkout' );

		button.click();

		expect( button.classList.contains( 'state--success' ) ).toBe( true );

		jest.advanceTimersByTime( 2000 );

		expect( button.classList.contains( 'state--success' ) ).toBe( false );
	} );

	test( 'preserves the test-mode badge while hydrating accessible card brand icons', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input id="payment_method_woocommerce_payments" type="radio" ' +
			'name="payment_method" value="woocommerce_payments" checked />' +
			'<label for="payment_method_woocommerce_payments">Card ' +
			'<span class="wcpay-core-card-brand-icons payment-methods--logos">' +
			'<span class="test-mode badge">Test Mode</span>' +
			'<img src="https://example.test/visa.svg" alt="Visa" />' +
			'</span></label>' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';

		require( '../woopayments-checkout' );

		const logos = document.querySelector(
			'[data-testid="payment-methods-logos"]'
		);
		const badges = document.querySelectorAll( '.test-mode.badge' );
		const badge = badges[ 0 ];
		const container = logos.parentElement;

		expect( logos ).not.toBeNull();
		expect( badges ).toHaveLength( 1 );
		expect( badge.textContent ).toBe( 'Test Mode' );
		expect( container.classList.contains( 'payment-methods--logos' ) ).toBe(
			true
		);
		expect( container.contains( badge ) ).toBe( true );
		expect( logos.contains( badge ) ).toBe( false );
		expect(
			Array.from( logos.querySelectorAll( 'img' ) ).map(
				( img ) => img.alt
			)
		).toEqual( [ 'Visa', 'Mastercard', 'American Express', 'Discover' ] );
		expect(
			logos.querySelector( '.payment-methods--logos-count' ).textContent
		).toBe( '+ 2' );
		expect( logos.getAttribute( 'aria-haspopup' ) ).toBe( 'dialog' );
		expect( logos.getAttribute( 'aria-label' ) ).toBe(
			'Show all supported credit card brands'
		);

		logos.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'Enter',
				bubbles: true,
				cancelable: true,
			} )
		);

		const popover = document.querySelector( '.logo-popover' );

		expect( popover ).not.toBeNull();
		expect( popover.getAttribute( 'aria-label' ) ).toBe(
			'Supported credit card brands'
		);
		expect( popover.getAttribute( 'tabindex' ) ).toBe( '-1' );
		expect(
			document.getElementById(
				popover.getAttribute( 'aria-describedby' )
			).textContent
		).toBe( 'JCB, Union Pay' );
		expect( document.activeElement ).toBe( popover );
		expect(
			Array.from( popover.querySelectorAll( 'img' ) ).map(
				( img ) => img.alt
			)
		).toEqual( [ 'JCB', 'Union Pay' ] );

		document.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'Escape',
				bubbles: true,
			} )
		);

		expect( document.querySelector( '.logo-popover' ) ).toBeNull();
		expect( document.activeElement ).toBe( logos );

		window.dispatchEvent( new window.Event( 'resize' ) );

		expect( document.querySelectorAll( '.test-mode.badge' ) ).toHaveLength(
			1
		);
		expect( document.querySelector( '.test-mode.badge' ) ).toBe( badge );
	} );

	test( 'cleans up card brand logo resize handlers when checkout fragments replace payment markup', () => {
		const addEventListener = jest.spyOn( window, 'addEventListener' );
		const removeEventListener = jest.spyOn( window, 'removeEventListener' );
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input id="payment_method_woocommerce_payments" type="radio" ' +
			'name="payment_method" value="woocommerce_payments" checked />' +
			'<label for="payment_method_woocommerce_payments">Card ' +
			'<span class="wcpay-core-card-brand-icons payment-methods--logos">' +
			'<img src="https://example.test/visa.svg" alt="Visa" />' +
			'</span></label>' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';

		require( '../woopayments-checkout' );

		const resizeHandlers = addEventListener.mock.calls
			.filter( ( [ eventName ] ) => eventName === 'resize' )
			.map( ( [ , handler ] ) => handler );

		expect( resizeHandlers ).toHaveLength( 1 );

		document.body.innerHTML =
			'<form class="checkout">' +
			'<input id="payment_method_woocommerce_payments" type="radio" ' +
			'name="payment_method" value="woocommerce_payments" checked />' +
			'<label for="payment_method_woocommerce_payments">Card ' +
			'<span class="wcpay-core-card-brand-icons payment-methods--logos">' +
			'<img src="https://example.test/visa.svg" alt="Visa" />' +
			'</span></label>' +
			'<div id="wcpay-core-payment-element"></div>' +
			'</form>';

		bodyEventHandlers.updated_checkout();

		expect( removeEventListener ).toHaveBeenCalledWith(
			'resize',
			resizeHandlers[ 0 ]
		);
		expect(
			addEventListener.mock.calls.filter(
				( [ eventName ] ) => eventName === 'resize'
			)
		).toHaveLength( 2 );
	} );

	test( 'does not render WooPay express markup from the card checkout bundle', () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input id="billing_email" value="shopper@example.com" />' +
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<div id="wcpay-woopay-button"><div class="woopay-express-button is-placeholder"></div></div>' +
			'</form>';

		require( '../woopayments-checkout' );

		expect(
			document.querySelector( '#wcpay-woopay-button button' )
		).toBeNull();
		expect(
			document.querySelector( '.woopay-express-button.is-placeholder' )
		).not.toBeNull();
	} );

	function createAddPaymentMethodForm() {
		const form = document.createElement( 'form' );
		form.id = 'add_payment_method';
		form.submit = jest.fn();
		form.innerHTML =
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<div id="wcpay-core-payment-errors" role="alert" hidden></div>' +
			'<button type="submit">Add payment method</button>';
		document.body.innerHTML = '';
		document.body.appendChild( form );
		window.wcpay_core_checkout_config.cartTotal = '0';
		window.wcpay_core_checkout_config.confirmationErrorMessage =
			'Unable to add payment method.';

		return form;
	}

	function mockSetupIntentHttpFailure( jqXHR ) {
		global.jQuery.post.mockReturnValueOnce( {
			done: jest.fn( () => ( {
				fail: jest.fn( ( callback ) => callback( jqXHR ) ),
			} ) ),
		} );
	}

	test( 'adds a setup intent field before submitting the add-payment-method form', async () => {
		const addPaymentMethodForm = document.createElement( 'form' );
		addPaymentMethodForm.id = 'add_payment_method';
		addPaymentMethodForm.submit = jest.fn();
		addPaymentMethodForm.innerHTML =
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>';
		document.body.innerHTML = '';
		document.body.appendChild( addPaymentMethodForm );
		window.wcpay_core_checkout_config.cartTotal = '0';
		window.wcpay_core_checkout_config.createSetupIntentNonce =
			'setup_nonce';
		window.wcpay_core_checkout_config.fraudPreventionToken =
			'fraud-token-123';
		window.wcpay_core_checkout_config.customerData = {
			name: 'Renewal Target',
			email: 'renewal-target@example.test',
			address: {
				line1: '123 Main St',
				city: 'San Francisco',
				state: 'CA',
				postal_code: '94103',
				country: 'US',
			},
		};
		global.jQuery.post.mockReturnValueOnce( {
			done: jest.fn( ( callback ) => {
				callback( {
					success: true,
					data: {
						id: 'seti_native',
						status: 'succeeded',
					},
				} );
				return {
					fail: jest.fn(),
				};
			} ),
		} );

		require( '../woopayments-checkout' );

		addPaymentMethodForm.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);

		await flushPromises();

		const setupIntentField = addPaymentMethodForm.querySelector(
			'input[name="wcpay-setup-intent"]'
		);

		expect( global.jQuery.post ).toHaveBeenCalledWith(
			'https://example.test/admin-ajax.php',
			expect.objectContaining( {
				action: 'create_setup_intent',
				_ajax_nonce: 'setup_nonce',
				'wcpay-payment-method': expect.any( String ),
			} )
		);
		expect( submitElements ).toHaveBeenCalled();
		expect( submitElements.mock.invocationCallOrder[ 0 ] ).toBeLessThan(
			stripeMock.createPaymentMethod.mock.invocationCallOrder[ 0 ]
		);
		expect( stripeMock.createPaymentMethod ).toHaveBeenCalledWith( {
			elements: elementsMock,
			params: {
				billing_details: window.wcpay_core_checkout_config.customerData,
			},
		} );
		expect( stripeMock.confirmSetup ).not.toHaveBeenCalled();
		expect( setupIntentField ).not.toBeNull();
		expect( setupIntentField.value ).toBe( 'seti_native' );
		expect(
			addPaymentMethodForm.querySelector(
				'input[name="wcpay-fraud-prevention-token"]'
			).value
		).toBe( 'fraud-token-123' );
		expect( addPaymentMethodForm.submit ).toHaveBeenCalled();
	} );

	test( 'preserves add-payment-method payment method errors', async () => {
		const addPaymentMethodForm = document.createElement( 'form' );
		addPaymentMethodForm.id = 'add_payment_method';
		addPaymentMethodForm.submit = jest.fn();
		addPaymentMethodForm.innerHTML =
			'<input type="radio" name="payment_method" value="woocommerce_payments" checked />' +
			'<div id="wcpay-core-payment-element"></div>' +
			'<div id="wcpay-core-payment-errors" hidden></div>';
		document.body.innerHTML = '';
		document.body.appendChild( addPaymentMethodForm );
		window.wcpay_core_checkout_config.cartTotal = '0';
		window.wcpay_core_checkout_config.confirmationErrorMessage =
			'Unable to add payment method.';
		stripeMock.createPaymentMethod.mockResolvedValueOnce( {
			error: {
				message: 'Your card number is incomplete.',
			},
		} );

		require( '../woopayments-checkout' );

		addPaymentMethodForm.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);

		await flushPromises();

		expect(
			document.getElementById( 'wcpay-core-payment-errors' ).textContent
		).toBe( 'Your card number is incomplete.' );
		expect( global.jQuery.post ).not.toHaveBeenCalled();
		expect( addPaymentMethodForm.submit ).not.toHaveBeenCalled();
	} );

	test( 'releases add-payment-method after a failed SetupIntent confirmation', async () => {
		const addPaymentMethodForm = createAddPaymentMethodForm();
		global.jQuery.post.mockReturnValueOnce( {
			done: jest.fn( ( callback ) => {
				callback( {
					success: true,
					data: {
						id: 'seti_failed_authentication',
						status: 'requires_action',
						client_secret: 'seti_failed_authentication_secret_abc',
					},
				} );
				return {
					fail: jest.fn(),
				};
			} ),
		} );
		stripeMock.confirmSetup.mockResolvedValueOnce( {
			error: {
				message:
					'We are unable to authenticate your payment method. Please choose a different payment method and try again.',
			},
		} );

		require( '../woopayments-checkout' );
		addPaymentMethodForm.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await flushPromises();

		expect( stripeMock.confirmSetup ).toHaveBeenCalledWith( {
			clientSecret: 'seti_failed_authentication_secret_abc',
			redirect: 'if_required',
		} );
		expect(
			document.getElementById( 'wcpay-core-payment-errors' ).textContent
		).toBe(
			'We are unable to authenticate your payment method. Please choose a different payment method and try again.'
		);
		expect( global.jQuery ).toHaveBeenCalledWith( addPaymentMethodForm );
		expect(
			global.jQuery.checkoutFormResult.removeClass
		).toHaveBeenCalledWith( 'processing' );
		expect(
			global.jQuery.checkoutFormResult.unblock
		).toHaveBeenCalledTimes( 1 );
		expect( addPaymentMethodForm.submit ).not.toHaveBeenCalled();
	} );

	test( 'preserves a safe setup-intent error from an HTTP failure', async () => {
		const addPaymentMethodForm = createAddPaymentMethodForm();
		const submitButton = addPaymentMethodForm.querySelector(
			'button[type="submit"]'
		);
		mockSetupIntentHttpFailure( {
			responseJSON: {
				success: false,
				data: {
					error: {
						message: 'Error: Your card was declined.',
					},
				},
			},
		} );

		require( '../woopayments-checkout' );
		addPaymentMethodForm.dispatchEvent(
			new window.Event( 'submit', {
				bubbles: true,
				cancelable: true,
			} )
		);
		await flushPromises();

		const errorElement = document.getElementById(
			'wcpay-core-payment-errors'
		);
		expect( errorElement.textContent ).toBe(
			'Error: Your card was declined.'
		);
		expect( errorElement.hidden ).toBe( false );
		expect( errorElement.getAttribute( 'role' ) ).toBe( 'alert' );
		expect(
			addPaymentMethodForm.querySelector(
				'input[name="wcpay-setup-intent"]'
			)
		).toBeNull();
		expect( addPaymentMethodForm.submit ).not.toHaveBeenCalled();
		expect( submitButton.disabled ).toBe( false );
		expect( submitButton.getAttribute( 'aria-disabled' ) ).not.toBe(
			'true'
		);
		expect( addPaymentMethodForm.querySelector( '.blockUI' ) ).toBeNull();
	} );

	test.each( [
		{ description: 'an absent request object', jqXHR: undefined },
		{
			description: 'a scalar response',
			jqXHR: { responseJSON: 'failure' },
		},
		{
			description: 'a missing data envelope',
			jqXHR: { responseJSON: { success: false } },
		},
		{
			description: 'a scalar error',
			jqXHR: {
				responseJSON: {
					success: false,
					data: { error: 'failure' },
				},
			},
		},
		{
			description: 'a non-string message',
			jqXHR: {
				responseJSON: {
					success: false,
					data: { error: { message: 500 } },
				},
			},
		},
		{
			description: 'an empty message',
			jqXHR: {
				responseJSON: {
					success: false,
					data: { error: { message: '' } },
				},
			},
		},
		{
			description: 'a whitespace-only message',
			jqXHR: {
				responseJSON: {
					success: false,
					data: { error: { message: ' \t ' } },
				},
			},
		},
	] )(
		'uses the generic setup-intent error for $description',
		async ( { jqXHR } ) => {
			const addPaymentMethodForm = createAddPaymentMethodForm();
			mockSetupIntentHttpFailure( jqXHR );

			require( '../woopayments-checkout' );
			addPaymentMethodForm.dispatchEvent(
				new window.Event( 'submit', {
					bubbles: true,
					cancelable: true,
				} )
			);
			await flushPromises();

			expect(
				document.getElementById( 'wcpay-core-payment-errors' )
					.textContent
			).toBe( 'Unable to add payment method.' );
			expect( addPaymentMethodForm.submit ).not.toHaveBeenCalled();
		}
	);
} );
