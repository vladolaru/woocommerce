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

	beforeEach( () => {
		apiFetch = jest.fn();
		window.wp = { apiFetch };
		global.jQuery = jest.fn( () => ( { on: jest.fn() } ) );
	} );

	afterEach( () => {
		delete window.wcpayExpressCheckoutParams;
		delete window.wp;
		delete global.jQuery;
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
					path: '/wc/store/v1/cart/update-customer',
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
					path: '/wc/store/v1/cart/select-shipping-rate',
					data: { package_id: 0, rate_id: 'flat_rate:1' },
				} )
			);
			expect( elementsMock.update ).toHaveBeenCalledWith( {
				amount: 1500,
			} );
			expect( event.resolve ).toHaveBeenCalledWith( {
				lineItems: expect.any( Array ),
			} );
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
