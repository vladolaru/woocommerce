import '@ppcp-test/helpers/silenceConsole';

const mockGetProducts = jest.fn();

jest.mock(
	'@ppcp-button/ActionHandler/SingleProductActionHandler',
	() =>
		jest.fn().mockImplementation( () => ( {
			getProducts: mockGetProducts,
		} ) ),
	{ virtual: true }
);

const mockPayerData = jest.fn( () => null );

jest.mock(
	'@ppcp-button/Helper/PayerData',
	() => ( {
		payerData: () => mockPayerData(),
	} ),
	{ virtual: true }
);

const mockCartPayerData = jest.fn( () => null );

jest.mock(
	'@ppcp-button/Helper/CartPayerData',
	() => ( {
		cartPayerData: () => mockCartPayerData(),
	} ),
	{ virtual: true }
);

jest.mock( './utils/api', () => ( {
	postJson: jest.fn(),
	postStoreApi: jest.fn(),
} ) );

import {
	createOrder,
	approveOrder,
	fetchCart,
	fetchCartTotal,
	simulateCart,
	updateCustomerAddress,
	selectShippingRate,
	navigation,
} from './endpointsAdapter';
import { postJson, postStoreApi } from './utils/api';

const config = {
	ajax: {
		change_cart: { endpoint: '/cc', nonce: 'n-cc' },
		create_order: { endpoint: '/co', nonce: 'n-co' },
		approve_order: { endpoint: '/ao', nonce: 'n-ao' },
		simulate_cart: { endpoint: '/sc', nonce: 'n-sc' },
		wc_store_api: {
			cart: '/wp-json/wc/store/v1/cart',
			update_customer: '/wp-json/wc/store/v1/cart/update-customer',
			select_shipping_rate:
				'/wp-json/wc/store/v1/cart/select-shipping-rate',
			nonce: 'store-nonce',
		},
	},
	urls: { checkout: '/checkout/' },
};

afterEach( () => {
	postJson.mockReset();
	postStoreApi.mockReset();
	mockGetProducts.mockReset();
	document.body.innerHTML = '';
} );

describe( 'createOrder', () => {
	test( 'product context adds the product to the cart first and forwards purchase units', async () => {
		document.body.innerHTML =
			'<form class="wc-block-add-to-cart-with-options">' +
			'<input name="add-to-cart" value="1006" /></form>';
		mockGetProducts.mockReturnValue( [
			{ data: () => ( { id: 1006, quantity: 1, variations: [] } ) },
		] );
		const purchaseUnits = [ { reference_id: 'default' } ];
		postJson
			.mockResolvedValueOnce( purchaseUnits )
			.mockResolvedValueOnce( { id: 'PAYPAL1' } );

		const result = await createOrder( config, 'product', 'paypal' );

		expect( result ).toEqual( { orderId: 'PAYPAL1' } );
		expect( postJson ).toHaveBeenNthCalledWith(
			1,
			config.ajax.change_cart,
			{
				products: [ { id: 1006, quantity: 1, variations: [] } ],
			}
		);
		expect( postJson ).toHaveBeenNthCalledWith(
			2,
			config.ajax.create_order,
			{
				context: 'product',
				purchase_units: purchaseUnits,
				payment_method: 'ppcp-gateway',
				funding_source: 'paypal',
				save_order_in_session: 1,
			}
		);
	} );

	test( 'cart context skips the change-cart step', async () => {
		postJson.mockResolvedValueOnce( { id: 'PAYPAL2' } );

		await createOrder( config, 'cart', 'venmo' );

		expect( postJson ).toHaveBeenCalledTimes( 1 );
		expect( postJson ).toHaveBeenCalledWith( config.ajax.create_order, {
			context: 'cart',
			purchase_units: [],
			payment_method: 'ppcp-gateway',
			funding_source: 'venmo',
			save_order_in_session: 1,
		} );
	} );

	test( 'checkout context serializes the form for early validation and sends the payer', async () => {
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input name="billing_email" value="a@b.com" />' +
			'<input type="checkbox" id="createaccount" name="createaccount" checked /></form>';
		mockPayerData.mockReturnValueOnce( {
			email_address: 'a@b.com',
		} );
		postJson.mockResolvedValueOnce( { id: 'PAYPAL3' } );

		await createOrder( config, 'checkout', 'paypal' );

		expect( postJson ).toHaveBeenCalledWith( config.ajax.create_order, {
			context: 'checkout',
			purchase_units: [],
			payment_method: 'ppcp-gateway',
			funding_source: 'paypal',
			save_order_in_session: 1,
			form_encoded: 'billing_email=a%40b.com&createaccount=on',
			createaccount: true,
			payer: { email_address: 'a@b.com' },
		} );
	} );

	test( 'product context fails clearly without a product form', async () => {
		await expect(
			createOrder( config, 'product', 'paypal' )
		).rejects.toThrow( 'Product form not found.' );
		expect( postJson ).not.toHaveBeenCalled();
	} );

	test( 'pay-now context identifies the existing WC order to build from', async () => {
		postJson.mockResolvedValueOnce( { id: 'PAYPAL4' } );

		await createOrder(
			{ ...config, pay_now: { order_id: 123, order_key: 'wc_abc' } },
			'pay-now',
			'paypal'
		);

		expect( postJson ).toHaveBeenCalledWith( config.ajax.create_order, {
			context: 'pay-now',
			purchase_units: [],
			payment_method: 'ppcp-gateway',
			funding_source: 'paypal',
			save_order_in_session: 1,
			order_id: 123,
			order_key: 'wc_abc',
		} );
	} );

	test( 'checkout-block context sends the payer from the cart store, without form_encoded since there is no classic form', async () => {
		mockCartPayerData.mockReturnValueOnce( {
			email_address: 'a@b.com',
		} );
		postJson.mockResolvedValueOnce( { id: 'PAYPAL7' } );

		await createOrder( config, 'checkout-block', 'paypal' );

		expect( postJson ).toHaveBeenCalledWith( config.ajax.create_order, {
			context: 'checkout-block',
			purchase_units: [],
			payment_method: 'ppcp-gateway',
			funding_source: 'paypal',
			save_order_in_session: 1,
			payer: { email_address: 'a@b.com' },
		} );
	} );

	test( 'checkout-block context sends no payer when the cart store has no email', async () => {
		mockCartPayerData.mockReturnValueOnce( null );
		postJson.mockResolvedValueOnce( { id: 'PAYPAL8' } );

		await createOrder( config, 'checkout-block', 'paypal' );

		expect( postJson ).toHaveBeenCalledWith(
			config.ajax.create_order,
			expect.not.objectContaining( { payer: expect.anything() } )
		);
	} );

	test( 'a context with no billing form sends no payer', async () => {
		postJson.mockResolvedValueOnce( { id: 'PAYPAL9' } );

		await createOrder( config, 'cart', 'paypal' );

		expect( postJson ).toHaveBeenCalledWith(
			config.ajax.create_order,
			expect.not.objectContaining( { payer: expect.anything() } )
		);
	} );
} );

describe( 'simulateCart', () => {
	test( 'posts the viewed product and returns the simulated total, without calling change_cart', async () => {
		document.body.innerHTML =
			'<form class="wc-block-add-to-cart-with-options">' +
			'<input name="add-to-cart" value="1006" /></form>';
		mockGetProducts.mockReturnValue( [
			{ data: () => ( { id: 1006, quantity: 1, variations: [] } ) },
		] );
		postJson.mockResolvedValueOnce( {
			total: '110.00',
			currency_code: 'USD',
		} );

		const result = await simulateCart( config );

		expect( result ).toEqual( { total: '110.00', currency_code: 'USD' } );
		expect( postJson ).toHaveBeenCalledTimes( 1 );
		expect( postJson ).toHaveBeenCalledWith( config.ajax.simulate_cart, {
			products: [ { id: 1006, quantity: 1, variations: [] } ],
		} );
		expect( postJson ).not.toHaveBeenCalledWith(
			config.ajax.change_cart,
			expect.anything()
		);
	} );

	test( 'fails clearly without a product form, leaving the real cart untouched', async () => {
		await expect( simulateCart( config ) ).rejects.toThrow(
			'Product form not found.'
		);
		expect( postJson ).not.toHaveBeenCalled();
	} );
} );

describe( 'approveOrder', () => {
	let navigationAssignSpy;

	beforeEach( () => {
		navigationAssignSpy = jest
			.spyOn( navigation, 'assign' )
			.mockImplementation( () => {} );
	} );

	afterEach( () => {
		navigationAssignSpy.mockRestore();
	} );

	test( 'product context requests should_create_wc_order and continues on checkout without order_received_url', async () => {
		postJson.mockResolvedValueOnce( {} );

		await approveOrder( config, 'product', 'paypal', 'ORDER1' );

		expect( postJson ).toHaveBeenCalledWith( config.ajax.approve_order, {
			order_id: 'ORDER1',
			funding_source: 'paypal',
			should_create_wc_order: true,
		} );
		expect( navigation.assign.mock.calls[ 0 ][ 0 ] ).toContain(
			'/checkout/'
		);
		// Cache-busted so a cached checkout cannot drop the buyer back
		// into the express flow with an order already approved.
		expect( navigation.assign.mock.calls[ 0 ][ 0 ] ).toContain(
			'ppcp-continuation-redirect='
		);
	} );

	test( 'redirects to order_received_url when the server creates the WC order (Pay Now)', async () => {
		postJson.mockResolvedValueOnce( {
			order_received_url: '/checkout/order-received/123/?key=wc_abc',
		} );

		await approveOrder( config, 'product', 'paypal', 'ORDER1' );

		expect( navigation.assign ).toHaveBeenCalledWith(
			'/checkout/order-received/123/?key=wc_abc'
		);
	} );

	test( 'falls back to the continuation approval when WC order creation fails', async () => {
		postJson
			.mockRejectedValueOnce(
				new Error( 'No shipping method has been selected.' )
			)
			.mockResolvedValueOnce( {} );

		await approveOrder( config, 'product', 'paypal', 'ORDER1' );

		expect( postJson ).toHaveBeenCalledTimes( 2 );
		expect( postJson ).toHaveBeenNthCalledWith(
			2,
			config.ajax.approve_order,
			{
				order_id: 'ORDER1',
				funding_source: 'paypal',
				should_create_wc_order: false,
			}
		);
		expect( navigation.assign.mock.calls[ 0 ][ 0 ] ).toContain(
			'/checkout/'
		);
		// Cache-busted so a cached checkout cannot drop the buyer back
		// into the express flow with an order already approved.
		expect( navigation.assign.mock.calls[ 0 ][ 0 ] ).toContain(
			'ppcp-continuation-redirect='
		);
	} );

	test( 'does not request a WC order for Venmo when vaulting is enabled', async () => {
		postJson.mockResolvedValueOnce( {} );

		await approveOrder(
			{ ...config, vaulting_enabled: true },
			'product',
			'venmo',
			'ORDER1'
		);

		expect( postJson ).toHaveBeenCalledWith( config.ajax.approve_order, {
			order_id: 'ORDER1',
			funding_source: 'venmo',
			should_create_wc_order: false,
		} );
		expect( navigation.assign.mock.calls[ 0 ][ 0 ] ).toContain(
			'/checkout/'
		);
		// Cache-busted so a cached checkout cannot drop the buyer back
		// into the express flow with an order already approved.
		expect( navigation.assign.mock.calls[ 0 ][ 0 ] ).toContain(
			'ppcp-continuation-redirect='
		);
	} );

	test( 'checkout context pins the PayPal gateway radio and submits the form', async () => {
		postJson.mockResolvedValueOnce( {} );
		document.body.innerHTML =
			'<form class="checkout">' +
			'<input type="radio" id="payment_method_ppcp-gateway" /></form>';
		const trigger = jest.fn();
		global.jQuery = jest.fn( ( selector ) =>
			typeof selector === 'string' ? { length: 1, trigger } : { trigger }
		);

		await approveOrder( config, 'checkout', 'paypal', 'ORDER2' );

		expect(
			document.querySelector( '#payment_method_ppcp-gateway' ).checked
		).toBe( true );
		expect( trigger ).toHaveBeenCalledWith( 'submit' );

		delete global.jQuery;
	} );

	test(
		'checkout context still switches an unrelated radio to PayPal ' +
			'on the express path, leaving the buyer with the express gateway',
		async () => {
			postJson.mockResolvedValueOnce( {} );
			document.body.innerHTML =
				'<form class="checkout">' +
				'<input type="radio" id="payment_method_ppcp-gateway" />' +
				'<input type="radio" id="payment_method_bacs" checked /></form>';
			const radioTrigger = jest.fn();
			const formTrigger = jest.fn();
			global.jQuery = jest.fn( ( selector ) =>
				typeof selector === 'string'
					? { length: 1, trigger: formTrigger }
					: { trigger: radioTrigger }
			);

			await approveOrder( config, 'checkout', 'paypal', 'ORDER2b' );

			expect(
				document.querySelector( '#payment_method_ppcp-gateway' ).checked
			).toBe( true );
			expect( radioTrigger ).toHaveBeenCalledWith( 'change' );
			expect( formTrigger ).toHaveBeenCalledWith( 'submit' );

			delete global.jQuery;
		}
	);

	describe( 'classic checkout never creates the WC order here', () => {
		test( 'sends should_create_wc_order false, then submits the form', async () => {
			postJson.mockResolvedValueOnce( {} );
			document.body.innerHTML = '<form class="checkout"></form>';
			const trigger = jest.fn();
			global.jQuery = jest.fn( ( selector ) =>
				typeof selector === 'string'
					? { length: 1, trigger }
					: { trigger }
			);

			await approveOrder(
				config,
				'checkout',
				'paypal',
				'ORDER-CHECKOUT'
			);

			expect( postJson ).toHaveBeenCalledTimes( 1 );
			expect( postJson ).toHaveBeenCalledWith(
				config.ajax.approve_order,
				{
					order_id: 'ORDER-CHECKOUT',
					funding_source: 'paypal',
					should_create_wc_order: false,
				}
			);
			expect( trigger ).toHaveBeenCalledWith( 'submit' );

			delete global.jQuery;
		} );

		test( 'rejects on a failed approve request without retrying, so the form is never submitted', async () => {
			const error = new Error( 'No shipping method has been selected.' );
			postJson.mockRejectedValueOnce( error );
			document.body.innerHTML = '<form class="checkout"></form>';
			const trigger = jest.fn();
			global.jQuery = jest.fn( ( selector ) =>
				typeof selector === 'string'
					? { length: 1, trigger }
					: { trigger }
			);

			await expect(
				approveOrder(
					config,
					'checkout',
					'paypal',
					'ORDER-CHECKOUT-FAIL'
				)
			).rejects.toThrow( error );

			expect( postJson ).toHaveBeenCalledTimes( 1 );
			expect( trigger ).not.toHaveBeenCalled();

			delete global.jQuery;
		} );
	} );

	describe( 'pay-now context', () => {
		test( 'approves the order in the session and submits the pay-order form, without creating a WC order', async () => {
			postJson.mockResolvedValueOnce( {} );
			document.body.innerHTML =
				'<form id="order_review">' +
				'<input type="radio" id="payment_method_ppcp-gateway" /></form>';
			const trigger = jest.fn();
			global.jQuery = jest.fn( ( selector ) =>
				typeof selector === 'string'
					? { length: 1, trigger }
					: { trigger }
			);

			await approveOrder( config, 'pay-now', 'paypal', 'ORDER3' );

			expect( postJson ).toHaveBeenCalledTimes( 1 );
			expect( postJson ).toHaveBeenCalledWith(
				config.ajax.approve_order,
				{
					order_id: 'ORDER3',
					funding_source: 'paypal',
					should_create_wc_order: false,
				}
			);
			expect(
				document.querySelector( '#payment_method_ppcp-gateway' ).checked
			).toBe( true );
			expect( trigger ).toHaveBeenCalledWith( 'submit' );

			delete global.jQuery;
		} );

		test( 'throws instead of falling through to the classic continuation when the pay-order form is missing', async () => {
			postJson.mockResolvedValueOnce( {} );
			document.body.innerHTML = '';
			global.jQuery = jest.fn( () => ( { length: 0 } ) );

			await expect(
				approveOrder( config, 'pay-now', 'paypal', 'ORDER3' )
			).rejects.toThrow( 'Order form not found.' );

			delete global.jQuery;
		} );
	} );
} );

describe( 'fetchCartTotal', () => {
	afterEach( () => {
		global.fetch = undefined;
	} );

	test( 'converts Store API minor units to a decimal string', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			json: async () => ( {
				totals: { total_price: '11000', currency_minor_unit: 2 },
			} ),
		} );

		await expect( fetchCartTotal( config ) ).resolves.toBe( '110.00' );
	} );

	test( 'returns an empty string on failure', async () => {
		global.fetch = jest.fn().mockRejectedValue( new Error( 'down' ) );

		await expect( fetchCartTotal( config ) ).resolves.toBe( '' );
	} );
} );

describe( 'fetchCart', () => {
	afterEach( () => {
		global.fetch = undefined;
	} );

	test( 'returns the parsed Store API cart', async () => {
		const cart = { totals: { total_price: '1000' } };
		global.fetch = jest.fn().mockResolvedValue( {
			json: async () => cart,
		} );

		await expect( fetchCart( config ) ).resolves.toEqual( cart );
		expect( global.fetch ).toHaveBeenCalledWith(
			config.ajax.wc_store_api.cart,
			{
				credentials: 'same-origin',
			}
		);
	} );

	test( 'returns null when the request fails', async () => {
		global.fetch = jest.fn().mockRejectedValue( new Error( 'down' ) );

		await expect( fetchCart( config ) ).resolves.toBeNull();
	} );
} );

describe( 'updateCustomerAddress', () => {
	test( 'posts the address as shipping only to the Store API and returns the recalculated cart', async () => {
		const cart = { totals: { total_price: '1100' } };
		postStoreApi.mockResolvedValueOnce( cart );

		const address = { country: 'US', state: 'CA' };
		const result = await updateCustomerAddress( config, address );

		expect( result ).toEqual( cart );
		expect( postStoreApi ).toHaveBeenCalledWith(
			config.ajax.wc_store_api,
			config.ajax.wc_store_api.update_customer,
			{ shipping_address: address }
		);
	} );

	test( 'never sends a billing_address', async () => {
		postStoreApi.mockResolvedValueOnce( {} );

		await updateCustomerAddress( config, { country: 'US' } );

		expect( postStoreApi ).toHaveBeenCalledWith(
			config.ajax.wc_store_api,
			config.ajax.wc_store_api.update_customer,
			expect.not.objectContaining( {
				billing_address: expect.anything(),
			} )
		);
	} );
} );

describe( 'selectShippingRate', () => {
	test( 'posts the rate id to the Store API and returns the recalculated cart', async () => {
		const cart = { totals: { total_price: '1150' } };
		postStoreApi.mockResolvedValueOnce( cart );

		const result = await selectShippingRate( config, 'flat_rate:1' );

		expect( result ).toEqual( cart );
		expect( postStoreApi ).toHaveBeenCalledWith(
			config.ajax.wc_store_api,
			config.ajax.wc_store_api.select_shipping_rate,
			{ rate_id: 'flat_rate:1' }
		);
	} );
} );
