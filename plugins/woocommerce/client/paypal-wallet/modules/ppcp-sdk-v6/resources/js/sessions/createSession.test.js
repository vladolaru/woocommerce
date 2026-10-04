const mockApproveOrder = jest.fn();
jest.mock( '../endpointsAdapter', () => ( {
	approveOrder: ( ...args ) => mockApproveOrder( ...args ),
} ) );

const mockAddressChange = jest.fn();
const mockOptionsChange = jest.fn();
jest.mock( './shippingHandler', () => ( {
	handleShippingAddressChange: ( ...args ) => mockAddressChange( ...args ),
	handleShippingOptionsChange: ( ...args ) => mockOptionsChange( ...args ),
} ) );

jest.mock( '../utils/api', () => ( { hasJQuery: () => false } ) );
jest.mock( '../utils/errorHandler', () => ( {
	handleError: jest.fn(),
} ) );

import { createSession, SUPPORTED_METHODS } from './createSession';

// Captures the session config and requested factory so the built handlers can be inspected.
function fakeSdk() {
	const capture = {};
	const recordFactoryCall = ( factory ) => ( config ) => {
		capture.factory = factory;
		capture.config = config;
		return { session: true };
	};
	return {
		capture,
		createPayPalOneTimePaymentSession: recordFactoryCall(
			'createPayPalOneTimePaymentSession'
		),
		createVenmoOneTimePaymentSession: recordFactoryCall(
			'createVenmoOneTimePaymentSession'
		),
		createPayLaterOneTimePaymentSession: recordFactoryCall(
			'createPayLaterOneTimePaymentSession'
		),
		createPayPalGuestOneTimePaymentSession: recordFactoryCall(
			'createPayPalGuestOneTimePaymentSession'
		),
	};
}

beforeEach( () => {
	mockApproveOrder.mockReset();
	mockAddressChange.mockReset();
	mockOptionsChange.mockReset();
} );

describe( 'SUPPORTED_METHODS', () => {
	test( 'lists the methods a session factory exists for, driving the boot.js redraw check', () => {
		expect( SUPPORTED_METHODS ).toEqual( [
			'paypal',
			'venmo',
			'paylater',
		] );
	} );
} );

describe( 'createSession', () => {
	test( 'default onApprove routes to the classic approveOrder flow', async () => {
		const sdk = fakeSdk();
		const config = { shipping: {} };

		createSession( sdk, 'paypal', config, 'cart' );
		await sdk.capture.config.onApprove( { orderId: 'ORDER1' } );

		expect( mockApproveOrder ).toHaveBeenCalledWith(
			config,
			'cart',
			'paypal',
			'ORDER1'
		);
	} );

	test( 'a provided onApprove replaces the default', async () => {
		const sdk = fakeSdk();
		const onApprove = jest.fn();

		createSession( sdk, 'paypal', { shipping: {} }, 'checkout-block', {
			onApprove,
		} );
		await sdk.capture.config.onApprove( { orderId: 'ORDER2' } );

		expect( onApprove ).toHaveBeenCalledWith( { orderId: 'ORDER2' } );
		expect( mockApproveOrder ).not.toHaveBeenCalled();
	} );

	test( 'provided shipping handlers attach even when the classic condition is false', () => {
		const sdk = fakeSdk();
		const onShippingAddressChange = jest.fn();
		const onShippingOptionsChange = jest.fn();

		// shipping.in_context has no entry for this context, so the classic path would attach nothing.
		createSession( sdk, 'paypal', { shipping: {} }, 'checkout-block', {
			onShippingAddressChange,
			onShippingOptionsChange,
		} );

		expect( sdk.capture.config.onShippingAddressChange ).toBe(
			onShippingAddressChange
		);
		expect( sdk.capture.config.onShippingOptionsChange ).toBe(
			onShippingOptionsChange
		);
	} );

	test( 'no shipping handlers when neither an override nor the classic condition applies', () => {
		const sdk = fakeSdk();

		createSession( sdk, 'paypal', { shipping: {} }, 'cart' );

		expect( sdk.capture.config.onShippingAddressChange ).toBeUndefined();
		expect( sdk.capture.config.onShippingOptionsChange ).toBeUndefined();
	} );

	test( 'classic default attaches the fetch-based shipping handlers when the context collects shipping', async () => {
		const sdk = fakeSdk();
		const config = {
			shipping: { in_context: { cart: true } },
		};

		createSession( sdk, 'paypal', config, 'cart' );
		await sdk.capture.config.onShippingAddressChange( { any: true } );

		expect( mockAddressChange ).toHaveBeenCalledWith(
			{ any: true },
			config
		);
	} );

	test.each( [ 'checkout', 'pay-now' ] )(
		'no popup shipping handlers on %s even when the context collects shipping, because PayPal is given a fixed address there',
		( context ) => {
			const sdk = fakeSdk();
			const config = {
				shipping: { in_context: { [ context ]: true } },
			};

			createSession( sdk, 'paypal', config, context );

			expect(
				sdk.capture.config.onShippingAddressChange
			).toBeUndefined();
			expect(
				sdk.capture.config.onShippingOptionsChange
			).toBeUndefined();
		}
	);
} );
