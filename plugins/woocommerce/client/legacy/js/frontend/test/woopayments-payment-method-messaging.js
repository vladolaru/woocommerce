/**
 * @jest-environment jest-fixed-jsdom
 */

describe( 'WooPayments BNPL payment method messaging', () => {
	let bodyHandlers;
	let createElement;
	let mountElement;
	let updateElement;
	let quantityHandlers;
	let stripeElements;

	async function flushPromises() {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

	function createJQueryMock() {
		const messageResult = {
			addClass: jest.fn( () => messageResult ),
			before: jest.fn( () => messageResult ),
			hide: jest.fn( () => messageResult ),
			show: jest.fn( () => messageResult ),
			slideDown: jest.fn( () => messageResult ),
			slideUp: jest.fn( () => messageResult ),
		};
		const quantityResult = {
			on: jest.fn( ( event, handler ) => {
				quantityHandlers[ event ] = handler;
				return quantityResult;
			} ),
			val: jest.fn( () => '1' ),
		};
		const bodyResult = {
			on: jest.fn( ( event, handler ) => {
				bodyHandlers[ event ] = handler;
				return bodyResult;
			} ),
		};
		const noopResult = {
			on: jest.fn( () => noopResult ),
			val: jest.fn(),
		};

		return jest.fn( ( selectorOrCallback ) => {
			if ( typeof selectorOrCallback === 'function' ) {
				selectorOrCallback( window.jQuery );
				return noopResult;
			}

			if ( selectorOrCallback === document.body ) {
				return bodyResult;
			}

			if ( selectorOrCallback === '#payment-method-message' ) {
				return messageResult;
			}

			if ( selectorOrCallback === '.quantity input[type=number]' ) {
				return quantityResult;
			}

			return noopResult;
		} );
	}

	function setMessagingConfig( overrides = {} ) {
		window.wcpayStripeSiteMessaging = Object.assign( {
			accountId: 'acct_test',
			cartTotal: 0,
			country: 'US',
			currencyCode: 'USD',
			isCart: false,
			isCartBlock: false,
			locale: 'en',
			nonce: {
				get_cart_total: 'cart-nonce',
				is_bnpl_available: 'bnpl-nonce',
			},
			paymentMethods: [ 'affirm', 'klarna' ],
			productId: 'base_product',
			productVariations: {
				base_product: {
					amount: 5000,
					currency: 'USD',
				},
			},
			publishableKey: 'pk_test',
			shouldInitializePMME: true,
			shouldShowPMME: true,
			stylesCacheVersion: '1',
			wcAjaxUrl: 'https://example.test/?wc-ajax=%%endpoint%%',
		}, overrides );
	}

	beforeEach( () => {
		jest.resetModules();
		bodyHandlers = {};
		quantityHandlers = {};
		document.body.innerHTML = '<div id="payment-method-message"></div>';
		window.jQuery = createJQueryMock();
		global.jQuery = window.jQuery;
		window.fetch = jest.fn( () =>
			Promise.resolve( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: { is_available: true },
					} ),
			} )
		);
		mountElement = jest.fn();
		updateElement = jest.fn();
		createElement = jest.fn( () => ( {
			mount: mountElement,
			on: jest.fn(),
			update: updateElement,
		} ) );
		stripeElements = jest.fn( () => ( {
			create: createElement,
		} ) );
		window.Stripe = jest.fn( () => ( {
			elements: stripeElements,
		} ) );
		require( '../utils/woopayments-appearance' );
		setMessagingConfig();
	} );

	afterEach( () => {
		delete global.jQuery;
		delete window.jQuery;
		delete window.Stripe;
		delete window.fetch;
		delete window.wcpayAppearance;
		delete window.wcpayStripeSiteMessaging;
	} );

	test( 'mounts the product-page Stripe payment method messaging element', async () => {
		require( '../woopayments-payment-method-messaging' );
		await flushPromises();

		expect( window.Stripe ).toHaveBeenCalledWith( 'pk_test', {
			locale: 'en',
			stripeAccount: 'acct_test',
		} );
		expect( createElement ).toHaveBeenCalledWith(
			'paymentMethodMessaging',
			{
				amount: 5000,
				countryCode: 'US',
				currency: 'USD',
				paymentMethodTypes: [ 'affirm', 'klarna' ],
			}
		);
		expect( mountElement ).toHaveBeenCalledWith(
			'#payment-method-message'
		);
	} );

	test( 'updates product messaging and checks availability when quantity changes', async () => {
		require( '../woopayments-payment-method-messaging' );
		await flushPromises();

		await quantityHandlers.change( { target: { value: '2' } } );
		await flushPromises();

		expect( updateElement ).toHaveBeenCalledWith( {
			amount: 10000,
			currency: 'USD',
		} );
		expect( window.fetch ).toHaveBeenCalledWith(
			'https://example.test/?wc-ajax=wcpay_check_bnpl_availability',
			expect.objectContaining( {
				method: 'POST',
			} )
		);
		const formData = window.fetch.mock.calls[ 0 ][ 1 ].body;
		expect( formData.get( 'price' ) ).toBe( '10000' );
		expect( formData.get( 'currency' ) ).toBe( 'USD' );
		expect( formData.get( 'country' ) ).toBe( 'US' );
	} );

	test( 'does not initialize the classic script for cart block messaging', async () => {
		setMessagingConfig( { isCartBlock: true } );

		require( '../woopayments-payment-method-messaging' );
		await flushPromises();

		expect( window.Stripe ).not.toHaveBeenCalled();
	} );
} );
