/* global describe, test, expect, jest, beforeEach, afterEach */
import '@testing-library/jest-dom';

jest.mock(
	'@ppcp-button/Helper/CheckoutMethodState',
	() => ( {
		getCurrentPaymentMethod: jest.fn(),
		ORDER_BUTTON_SELECTOR: '#place_order',
		PaymentMethods: {
			PAYPAL: 'ppcp-gateway',
		},
	} )
);

jest.mock(
	'@ppcp-button/Helper/PayPalScriptLoading',
	() => ( {
		loadPayPalScript: jest.fn(),
	} )
);

jest.mock( '@ppcp-button/ErrorHandler', () => {
	return jest.fn().mockImplementation( () => ( {
		message: jest.fn(),
		clear: jest.fn(),
	} ) );
} );

jest.mock( './configuration', () => ( {
	buttonConfiguration: jest.fn( () => ( {
		createVaultSetupToken: jest.fn(),
		onApprove: jest.fn(),
		onError: jest.fn(),
	} ) ),
} ) );

jest.mock( '@ppcp-button/Helper/Hiding', () => ( {
	setVisible: jest.fn(),
	setVisibleByClass: jest.fn(),
} ) );

import {
	handlePaymentMethodChange,
	setupPaymentMethodListeners,
	initializeScript,
} from './add-payment-method';

import { getCurrentPaymentMethod } from '@ppcp-button/Helper/CheckoutMethodState';
import { loadPayPalScript } from '@ppcp-button/Helper/PayPalScriptLoading';
import ErrorHandler from '@ppcp-button/ErrorHandler';
import {
	setVisible,
	setVisibleByClass,
} from '@ppcp-button/Helper/Hiding';

describe( 'add-payment-method', () => {
	let mockConfig;

	beforeEach( () => {
		jest.clearAllMocks();

		mockConfig = {
			is_subscription_change_payment_page: false,
			client_id: 'test-client-id',
			merchant_id: 'test-merchant-id',
			id_token: 'test-id-token',
			labels: {
				error: {
					generic: 'Generic error message',
				},
			},
			error_message: 'Payment processing failed',
			user: {
				is_logged: true,
			},
		};

		document.body.innerHTML = '';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	describe( 'handlePaymentMethodChange', () => {
		test( 'should show PayPal button and hide order button when PayPal is selected', () => {
			getCurrentPaymentMethod.mockReturnValue( 'ppcp-gateway' );
			document.body.innerHTML =
				'<div id="ppc-button-ppcp-gateway-save-payment-method"></div>';

			handlePaymentMethodChange( mockConfig );

			expect( setVisibleByClass ).toHaveBeenCalledTimes( 1 );
			expect( setVisible ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'should always show order button on subscription change page when PayPal button missing', () => {
			getCurrentPaymentMethod.mockReturnValue( 'ppcp-other-gateway' );
			const config = {
				...mockConfig,
				is_subscription_change_payment_page: true,
			};

			handlePaymentMethodChange( config );

			expect( setVisibleByClass ).toHaveBeenCalledTimes( 1 );
			expect( setVisible ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'setupPaymentMethodListeners', () => {
		test( 'should call handlePaymentMethodChange immediately on setup', () => {
			getCurrentPaymentMethod.mockReturnValue( 'ppcp-gateway' );
			setupPaymentMethodListeners( mockConfig );

			expect( setVisibleByClass ).toHaveBeenCalled();
		} );
	} );

	describe( 'initializeScript - PayPal button', () => {
		test( 'should load PayPal script with correct configuration', async () => {
			const mockPaypal = {
				Buttons: jest.fn().mockReturnValue( {
					render: jest.fn().mockResolvedValue( undefined ),
				} ),
			};

			loadPayPalScript.mockResolvedValue( mockPaypal );

			document.body.innerHTML = `
			<div class="woocommerce-notices-wrapper"></div>
		`;

			await initializeScript( mockConfig );

			expect( loadPayPalScript ).toHaveBeenCalledWith(
				'ppcp-add-payment-method',
				{
					url_params: {
						'client-id': 'test-client-id',
						'merchant-id': 'test-merchant-id',
						components: 'buttons',
					},
					script_attributes: {},
					save_payment_methods: {
						id_token: 'test-id-token',
					},
					user: {
						is_logged: true,
					},
				}
			);
		} );
	} );

	describe( 'error handling', () => {
		test( 'should display error message when PayPal script loading fails', async () => {
			const mockErrorHandler = {
				message: jest.fn(),
				clear: jest.fn(),
			};

			ErrorHandler.mockImplementation( () => mockErrorHandler );

			document.body.innerHTML = `
			<div class="woocommerce-notices-wrapper"></div>
		`;

			loadPayPalScript.mockRejectedValue(
				new Error( 'Script loading failed' )
			);

			// Suppress expected console.error
			jest.spyOn( console, 'error' ).mockImplementation( () => {} );

			await initializeScript( mockConfig );

			expect( mockErrorHandler.message ).toHaveBeenCalledWith(
				'Generic error message'
			);

			console.error.mockRestore();
		} );
	} );
} );
