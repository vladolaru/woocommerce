/**
 * Tests for the classic (shortcode) WooPayments express checkout script.
 */

const loadModule = ( params ) => {
	let exported;

	jest.isolateModules( () => {
		window.wcpayExpressCheckoutParams = params;
		exported = require( '../woopayments-express-checkout' ).__test__;
	} );

	return exported;
};

const baseParams = ( overrides = {} ) => ( {
	button_context: 'checkout',
	checkout: {
		currency_code: 'usd',
		currency_decimals: 2,
		stripe_minor_unit: 2,
		display_prices_with_tax: false,
		needs_shipping: true,
		allowed_shipping_countries: [ 'US' ],
	},
	nonce: {
		store_api_nonce: 'store-api-nonce',
		tokenized_cart_nonce: 'tokenized-cart-nonce',
		tokenized_cart_session_nonce: 'tokenized-cart-session-nonce',
	},
	...overrides,
} );

const cartResponse = ( overrides = {} ) => ( {
	items: [
		{
			name: 'Beanie',
			quantity: 1,
			variation: [],
			totals: {
				line_subtotal: '1000',
				line_subtotal_tax: '100',
				currency_minor_unit: 2,
			},
			prices: { price: '1000', currency_minor_unit: 2 },
		},
	],
	totals: {
		total_price: '1500',
		total_refund: '0',
		total_shipping: '500',
		total_shipping_tax: '0',
		total_discount: '0',
		total_fees: '0',
		total_tax: '0',
		currency_code: 'USD',
		currency_minor_unit: 2,
	},
	needs_shipping: true,
	shipping_rates: [
		{
			shipping_rates: [
				{
					rate_id: 'flat_rate:1',
					name: 'Flat rate',
					price: '500',
					taxes: '50',
					selected: false,
					currency_minor_unit: 2,
					meta_data: [],
				},
				{
					rate_id: 'local_pickup:2',
					name: 'Local pickup',
					price: '0',
					taxes: '0',
					selected: true,
					currency_minor_unit: 2,
					meta_data: [
						{ key: 'pickup_address', value: '123 Main St' },
						{ key: 'pickup_details', value: 'Back door' },
					],
				},
			],
		},
	],
	shipping_address: { country: 'US' },
	billing_address: { country: 'US' },
	...overrides,
} );

describe( 'woopayments-express-checkout', () => {
	let apiFetch;
	const originalLocation = window.location;

	beforeEach( () => {
		apiFetch = jest.fn();
		window.wp = { apiFetch };
		global.jQuery = jest.fn( () => ( { on: jest.fn() } ) );
		delete window.location;
		window.location = { href: 'http://shop.test/cart/', hash: '' };
	} );

	afterEach( () => {
		window.location = originalLocation;
		delete window.wcpayExpressCheckoutParams;
		delete window.wp;
		delete global.jQuery;
		delete window.Stripe;
		delete window.fetch;
	} );

	describe( 'transformPrice', () => {
		it( 'keeps same-scale amounts untouched', () => {
			const { transformPrice } = loadModule( baseParams() );

			expect(
				transformPrice( 1234, { currency_minor_unit: 2 } )
			).toBe( 1234 );
		} );

		it( 'narrows WC minor units to a zero-decimal Stripe currency with rounding', () => {
			const params = baseParams();
			params.checkout.stripe_minor_unit = 0;
			const { transformPrice } = loadModule( params );

			expect(
				transformPrice( 123400, { currency_minor_unit: 2 } )
			).toBe( 1234 );
			expect(
				transformPrice( 123450, { currency_minor_unit: 2 } )
			).toBe( 1235 );
		} );

		it( 'widens a zero-decimal WC configuration to two-decimal Stripe minor units', () => {
			const { transformPrice } = loadModule( baseParams() );

			expect(
				transformPrice( 1234, { currency_minor_unit: 0 } )
			).toBe( 123400 );
		} );
	} );

	describe( 'getShippingRates', () => {
		it( 'sorts the selected rate first and converts amounts', () => {
			const { getShippingRates } = loadModule( baseParams() );

			const rates = getShippingRates( cartResponse() );

			expect( rates.map( ( rate ) => rate.id ) ).toEqual( [
				'local_pickup:2',
				'flat_rate:1',
			] );
			expect( rates[ 1 ].amount ).toBe( 500 );
			expect( rates[ 0 ].deliveryEstimate ).toBe(
				'123 Main St - Back door'
			);
		} );

		it( 'includes taxes in rate amounts when prices are displayed with tax', () => {
			const params = baseParams();
			params.checkout.display_prices_with_tax = true;
			const { getShippingRates } = loadModule( params );

			const rates = getShippingRates( cartResponse() );

			expect(
				rates.find( ( rate ) => rate.id === 'flat_rate:1' ).amount
			).toBe( 550 );
		} );

		it( 'caps the number of rates at 9', () => {
			const { getShippingRates } = loadModule( baseParams() );
			const manyRates = Array.from( { length: 12 }, ( _, index ) => ( {
				rate_id: `flat_rate:${ index }`,
				name: `Rate ${ index }`,
				price: '100',
				taxes: '0',
				selected: index === 11,
				currency_minor_unit: 2,
				meta_data: [],
			} ) );

			const rates = getShippingRates(
				cartResponse( {
					shipping_rates: [ { shipping_rates: manyRates } ],
				} )
			);

			expect( rates ).toHaveLength( 9 );
			expect( rates[ 0 ].id ).toBe( 'flat_rate:11' );
		} );
	} );

	describe( 'getDisplayItems', () => {
		it( 'builds line items with quantity and variation details plus a shipping line', () => {
			const { getDisplayItems } = loadModule( baseParams() );
			const cartData = cartResponse();
			cartData.items[ 0 ].quantity = 2;
			cartData.items[ 0 ].variation = [
				{ attribute: 'Color', value: 'Blue' },
			];

			const items = getDisplayItems( cartData );

			expect( items[ 0 ].name ).toBe( 'Beanie (x2) - Color: Blue' );
			expect( items[ 0 ].amount ).toBe( 1000 );
			expect( items[ 1 ] ).toEqual( {
				amount: 500,
				name: 'Shipping',
			} );
		} );

		it( 'returns no line items when they add up to more than the total', () => {
			const { getDisplayItems } = loadModule( baseParams() );
			const cartData = cartResponse( {
				totals: {
					total_price: '900',
					total_refund: '0',
					total_shipping: '500',
					total_discount: '0',
					total_fees: '0',
					total_tax: '0',
					currency_minor_unit: 2,
				},
			} );

			expect( getDisplayItems( cartData ) ).toEqual( [] );
		} );

		it( 'adds a tax line only when prices are displayed without tax', () => {
			const { getDisplayItems } = loadModule( baseParams() );
			const cartData = cartResponse( {
				totals: {
					total_price: '1700',
					total_refund: '0',
					total_shipping: '500',
					total_discount: '0',
					total_fees: '0',
					total_tax: '100',
					currency_minor_unit: 2,
				},
			} );

			const taxItem = getDisplayItems( cartData ).find(
				( item ) => item.name === 'Tax'
			);

			expect( taxItem ).toEqual( { amount: 100, name: 'Tax' } );
		} );
	} );

	describe( 'handleShippingAddressChange', () => {
		const makeResponseEvent = () => ( {
			name: 'Jane Q Shopper',
			address: {
				line1: '123 Main St',
				city: 'San Francisco',
				state: 'CA',
				postal_code: '94110',
				country: 'US',
			},
			resolve: jest.fn(),
			reject: jest.fn(),
		} );

		it( 'posts the wallet address to update-customer on non-product contexts and resolves with rates', async () => {
			const testables = loadModule( baseParams() );
			const elementsMock = { update: jest.fn( () => Promise.resolve() ) };
			testables.setState( { elements: elementsMock } );
			apiFetch.mockResolvedValue( cartResponse() );

			const event = makeResponseEvent();
			await testables.handleShippingAddressChange( event );

			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'POST',
					path: '/wc/store/v1/cart/update-customer?currency=USD',
					data: {
						shipping_address: expect.objectContaining( {
							first_name: 'Jane',
							last_name: 'Q Shopper',
							address_1: '123 Main St',
							city: 'San Francisco',
							state: 'CA',
							postcode: '94110',
							country: 'US',
						} ),
					},
					headers: expect.objectContaining( {
						'X-WooPayments-Tokenized-Cart': true,
					} ),
				} )
			);
			expect( elementsMock.update ).toHaveBeenCalledWith( {
				amount: 1500,
				setupFutureUsage: null,
			} );
			expect( event.resolve ).toHaveBeenCalledWith( {
				shippingRates: expect.arrayContaining( [
					expect.objectContaining( { id: 'local_pickup:2' } ),
				] ),
				lineItems: expect.any( Array ),
			} );
			expect( event.reject ).not.toHaveBeenCalled();
		} );

		it( 'rejects the event when the cart returns no shipping rates', async () => {
			const testables = loadModule( baseParams() );
			testables.setState( {
				elements: { update: jest.fn( () => Promise.resolve() ) },
			} );
			apiFetch.mockResolvedValue(
				cartResponse( { shipping_rates: [] } )
			);

			const event = makeResponseEvent();
			await testables.handleShippingAddressChange( event );

			expect( event.reject ).toHaveBeenCalled();
			expect( event.resolve ).not.toHaveBeenCalled();
		} );

		it( 'rejects the event when the request fails', async () => {
			const testables = loadModule( baseParams() );
			apiFetch.mockRejectedValue( new Error( 'nope' ) );

			const event = makeResponseEvent();
			await testables.handleShippingAddressChange( event );

			expect( event.reject ).toHaveBeenCalled();
		} );
	} );

	describe( 'handleShippingRateChange', () => {
		it( 'posts the selected rate and resolves with updated line items', async () => {
			const testables = loadModule( baseParams() );
			const elementsMock = { update: jest.fn( () => Promise.resolve() ) };
			testables.setState( { elements: elementsMock } );
			apiFetch.mockResolvedValue( cartResponse() );

			const event = {
				shippingRate: { id: 'flat_rate:1' },
				resolve: jest.fn(),
				reject: jest.fn(),
			};
			await testables.handleShippingRateChange( event );

			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'POST',
					path:
						'/wc/store/v1/cart/select-shipping-rate?currency=USD',
					data: { package_id: 0, rate_id: 'flat_rate:1' },
				} )
			);
			expect( elementsMock.update ).toHaveBeenCalledWith( {
				amount: 1500,
				setupFutureUsage: null,
			} );
			expect( event.resolve ).toHaveBeenCalledWith( {
				lineItems: expect.any( Array ),
			} );
		} );
	} );

	describe( 'setupFutureUsage', () => {
		it( 'is off_session at creation for carts with a subscription schedule', () => {
			const { getStripeElementsOptions } = loadModule( baseParams() );
			const cartData = cartResponse( {
				extensions: {
					subscriptions: [ { billing_period: 'month' } ],
				},
			} );

			expect( getStripeElementsOptions( cartData ).setupFutureUsage ).toBe(
				'off_session'
			);
		} );

		it( 'is off_session when a cart item carries a subscription schedule', () => {
			const { getSetupFutureUsageForCart } = loadModule( baseParams() );
			const cartData = cartResponse();
			cartData.items[ 0 ].extensions = {
				subscriptions: {
					billing_period: 'month',
					billing_interval: 1,
				},
			};

			expect( getSetupFutureUsageForCart( cartData ) ).toBe(
				'off_session'
			);
		} );

		it( 'is omitted at creation for non-subscription carts', () => {
			const { getStripeElementsOptions } = loadModule( baseParams() );

			expect( getStripeElementsOptions( cartResponse() ) ).not.toHaveProperty(
				'setupFutureUsage'
			);
		} );

		it( 'falls back to the localized has_subscription flag for the product payload shape', () => {
			const { getStripeElementsOptions } = loadModule(
				baseParams( { has_subscription: true } )
			);
			const productShapedData = {
				total: { amount: 1500 },
				currency: 'usd',
			};

			expect(
				getStripeElementsOptions( productShapedData ).setupFutureUsage
			).toBe( 'off_session' );
		} );
	} );

	describe( 'redirectToOrder', () => {
		const stripeParams = {
			stripe: { publishableKey: 'pk_test_123', accountId: 'acct_1' },
			ajax_url: 'http://shop.test/wp-admin/admin-ajax.php',
		};
		const confirmationHash =
			'#wcpay-confirm-pi:77:pi_123_secret_456:nonce-abc';

		it( 'navigates directly when the redirect URL carries no confirmation hash', async () => {
			const { redirectToOrder } = loadModule( baseParams() );

			await redirectToOrder( {
				payment_result: {
					redirect_url: 'http://shop.test/thank-you/',
				},
			} );

			expect( window.location.href ).toBe(
				'http://shop.test/thank-you/'
			);
		} );

		it( 'confirms the intent and navigates to the authenticated return URL', async () => {
			const handleNextAction = jest.fn( () =>
				Promise.resolve( { paymentIntent: { id: 'pi_123' } } )
			);
			window.Stripe = jest.fn( () => ( { handleNextAction } ) );
			window.fetch = jest.fn( () =>
				Promise.resolve( {
					json: () =>
						Promise.resolve( {
							return_url: 'http://shop.test/order-received/77/',
						} ),
				} )
			);
			const { redirectToOrder } = loadModule(
				baseParams( stripeParams )
			);

			await redirectToOrder( {
				payment_result: { redirect_url: confirmationHash },
			} );

			expect( handleNextAction ).toHaveBeenCalledWith( {
				clientSecret: 'pi_123_secret_456',
			} );
			const fetchBody = window.fetch.mock.calls[ 0 ][ 1 ].body;
			expect( fetchBody.get( 'action' ) ).toBe( 'update_order_status' );
			expect( fetchBody.get( 'order_id' ) ).toBe( '77' );
			expect( fetchBody.get( '_ajax_nonce' ) ).toBe( 'nonce-abc' );
			expect( fetchBody.get( 'intent_id' ) ).toBe( 'pi_123' );
			expect( window.location.href ).toBe(
				'http://shop.test/order-received/77/'
			);
		} );

		it( 'rejects with the Stripe error message when the authentication fails', async () => {
			window.Stripe = jest.fn( () => ( {
				handleNextAction: jest.fn( () =>
					Promise.resolve( {
						error: { message: 'Authentication failed.' },
					} )
				),
			} ) );
			window.fetch = jest.fn();
			const { redirectToOrder } = loadModule(
				baseParams( stripeParams )
			);

			await expect(
				redirectToOrder( {
					payment_result: { redirect_url: confirmationHash },
				} )
			).rejects.toThrow( 'Authentication failed.' );
			expect( window.fetch ).not.toHaveBeenCalled();
			expect( window.location.href ).toBe( 'http://shop.test/cart/' );
		} );

		it( 'rejects when the order-status update reports an error', async () => {
			window.Stripe = jest.fn( () => ( {
				handleNextAction: jest.fn( () =>
					Promise.resolve( { paymentIntent: { id: 'pi_123' } } )
				),
			} ) );
			window.fetch = jest.fn( () =>
				Promise.resolve( {
					json: () =>
						Promise.resolve( {
							error: { message: 'Order update failed.' },
						} ),
				} )
			);
			const { redirectToOrder } = loadModule(
				baseParams( stripeParams )
			);

			await expect(
				redirectToOrder( {
					payment_result: { redirect_url: confirmationHash },
				} )
			).rejects.toThrow( 'Order update failed.' );
			expect( window.location.href ).toBe( 'http://shop.test/cart/' );
		} );
	} );

	describe( 'currency pinning and drift', () => {
		it( 'pins the localized currency on tokenized-cart Store API requests', async () => {
			const testables = loadModule( baseParams() );
			testables.setState( {
				elements: { update: jest.fn( () => Promise.resolve() ) },
			} );
			apiFetch.mockResolvedValue( cartResponse() );

			await testables.handleShippingRateChange( {
				shippingRate: { id: 'flat_rate:1' },
				resolve: jest.fn(),
				reject: jest.fn(),
			} );

			expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
				'/wc/store/v1/cart/select-shipping-rate?currency=USD'
			);
		} );

		it( 'sends the element boot currency with order placement', async () => {
			const testables = loadModule( baseParams() );
			testables.setState( { elementCurrency: 'usd' } );
			apiFetch.mockResolvedValue( { payment_result: {} } );

			await testables.placeOrder( 'ctoken_123', {
				billingDetails: { name: 'Jane Q Shopper' },
			} );

			expect( apiFetch.mock.calls[ 0 ][ 0 ].headers ).toEqual(
				expect.objectContaining( {
					'X-WooPayments-Payment-Currency': 'usd',
				} )
			);
		} );

		it( 'remembers the element currency when building creation options', () => {
			const testables = loadModule( baseParams() );

			testables.getStripeElementsOptions( cartResponse() );
			expect( testables.getElementCurrency() ).toBe( 'usd' );
		} );

		it( 'rejects a wallet address whose cart currency drifted from the element', async () => {
			const testables = loadModule( baseParams() );
			testables.setState( {
				elementCurrency: 'usd',
				elements: { update: jest.fn( () => Promise.resolve() ) },
			} );
			apiFetch.mockResolvedValue(
				cartResponse( {
					totals: {
						total_price: '1500',
						currency_code: 'EUR',
						currency_minor_unit: 2,
					},
				} )
			);

			const event = {
				name: 'Jane Q Shopper',
				address: { country: 'FR' },
				resolve: jest.fn(),
				reject: jest.fn(),
			};
			await testables.handleShippingAddressChange( event );

			expect( event.reject ).toHaveBeenCalled();
			expect( event.resolve ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'placeOrder', () => {
		it( 'refreshes the shipping address from the wallet confirm event', async () => {
			const testables = loadModule( baseParams() );
			testables.setState( {
				cachedCartData: cartResponse( {
					shipping_address: { country: 'DE', city: 'Stale' },
				} ),
			} );
			apiFetch.mockResolvedValue( { payment_result: {} } );

			await testables.placeOrder( 'ctoken_123', {
				billingDetails: {
					name: 'Jane Q Shopper',
					email: 'jane@example.com',
					address: { country: 'US' },
				},
				shippingAddress: {
					name: 'Jane Q Shopper',
					address: {
						line1: '123 Main St',
						city: 'San Francisco',
						state: 'CA',
						postal_code: '94110',
						country: 'US',
					},
				},
			} );

			const requestData = apiFetch.mock.calls[ 0 ][ 0 ].data;
			expect( requestData.shipping_address ).toEqual(
				expect.objectContaining( {
					city: 'San Francisco',
					country: 'US',
				} )
			);
		} );

		it( 'does not flag express payments as platform-created payment methods', async () => {
			const testables = loadModule( baseParams() );
			apiFetch.mockResolvedValue( { payment_result: {} } );

			await testables.placeOrder( 'ctoken_123', {
				billingDetails: { name: 'Jane Q Shopper' },
			} );

			const paymentData = apiFetch.mock.calls[ 0 ][ 0 ].data.payment_data;
			expect(
				paymentData.map( ( entry ) => entry.key )
			).not.toContain( 'wcpay-is-platform-payment-method' );
		} );

		it( 'falls back to the cached cart shipping address without a wallet address', async () => {
			const testables = loadModule( baseParams() );
			testables.setState( {
				cachedCartData: cartResponse( {
					shipping_address: { country: 'DE', city: 'Berlin' },
				} ),
			} );
			apiFetch.mockResolvedValue( { payment_result: {} } );

			await testables.placeOrder( 'ctoken_123', {
				billingDetails: { name: 'Jane Q Shopper' },
			} );

			const requestData = apiFetch.mock.calls[ 0 ][ 0 ].data;
			expect( requestData.shipping_address ).toEqual( {
				country: 'DE',
				city: 'Berlin',
			} );
		} );
	} );
} );
