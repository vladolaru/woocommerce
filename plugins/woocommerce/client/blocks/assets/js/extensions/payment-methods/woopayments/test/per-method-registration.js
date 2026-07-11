/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';

/**
 * Internal dependencies
 */
import registerWooPayments, { getWooPaymentsPaymentMethod } from '../index';

jest.mock( '@woocommerce/blocks-registry', () => ( {
	registerPaymentMethod: jest.fn(),
	registerExpressPaymentMethod: jest.fn(),
} ) );

jest.mock( '@woocommerce/settings', () => {
	const paymentMethodData = {
		woocommerce_payments: {
			title: 'Card',
			supports: [ 'products' ],
			gatewayId: 'woocommerce_payments',
			publishableKey: 'pk_test_123',
			accountId: 'acct_123',
			cartTotal: 1000,
			stylesCacheVersion: 'styles-v1',
			currency: 'USD',
			isCoreNativeCheckoutAvailable: true,
			paymentMethodTypes: [ 'card' ],
			paymentMethodsConfig: {
				card: {
					title: 'Card',
					isReusable: true,
				},
			},
		},
		woocommerce_payments_klarna: {
			title: 'Klarna',
			supports: [ 'products' ],
			gatewayId: 'woocommerce_payments_klarna',
			publishableKey: 'pk_test_123',
			accountId: 'acct_123',
			cartTotal: 1000,
			stylesCacheVersion: 'styles-v1',
			currency: 'USD',
			isCoreNativeCheckoutAvailable: true,
			paymentMethodTypes: [ 'klarna' ],
			paymentMethodsConfig: {
				klarna: {
					title: 'Klarna',
					isReusable: false,
					isBnpl: true,
					countries: [ 'BE' ],
				},
			},
		},
	};

	return {
		getPaymentMethodData: jest.fn(
			( paymentMethodId, defaultValue ) =>
				paymentMethodData[ paymentMethodId ] ?? defaultValue
		),
		getSetting: jest.fn( ( setting, defaultValue ) =>
			setting === 'paymentMethodData' ? paymentMethodData : defaultValue
		),
	};
} );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( ( callback ) =>
		callback( () => ( {
			getPaymentMethodData: () => ( {
				payment_method: 'woocommerce_payments_klarna',
				token: '',
			} ),
		} ) )
	),
} ) );

describe( 'wc-payment-method-woopayments per-method registration', () => {
	afterEach( () => {
		delete window.Stripe;
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	it( 'registers one Blocks payment method per active WooPayments gateway ID', () => {
		registerPaymentMethod.mockClear();

		registerWooPayments();

		expect(
			registerPaymentMethod.mock.calls.map(
				( [ paymentMethod ] ) => paymentMethod.name
			)
		).toEqual( [ 'woocommerce_payments', 'woocommerce_payments_klarna' ] );
	} );

	it( 'hides a split method when the Store API excludes its gateway ID', () => {
		registerPaymentMethod.mockClear();

		registerWooPayments();

		const klarnaPaymentMethod = registerPaymentMethod.mock.calls
			.map( ( [ paymentMethod ] ) => paymentMethod )
			.find(
				( paymentMethod ) =>
					paymentMethod.name === 'woocommerce_payments_klarna'
			);

		expect(
			klarnaPaymentMethod.canMakePayment( {
				paymentMethods: [ 'woocommerce_payments' ],
				billingAddress: { country: 'BE' },
			} )
		).toBe( false );
		expect(
			klarnaPaymentMethod.canMakePayment( {
				paymentMethods: [
					'woocommerce_payments',
					'woocommerce_payments_klarna',
				],
				billingAddress: { country: 'BE' },
			} )
		).toBe( true );
	} );

	it( 'hides a country-restricted method for an unsupported billing country', () => {
		registerPaymentMethod.mockClear();
		registerWooPayments();

		const klarnaPaymentMethod = registerPaymentMethod.mock.calls
			.map( ( [ paymentMethod ] ) => paymentMethod )
			.find(
				( paymentMethod ) =>
					paymentMethod.name === 'woocommerce_payments_klarna'
			);
		const paymentMethods = [
			'woocommerce_payments',
			'woocommerce_payments_klarna',
		];

		expect(
			klarnaPaymentMethod.canMakePayment( {
				paymentMethods,
				billingAddress: { country: 'US' },
			} )
		).toBe( false );
		expect(
			klarnaPaymentMethod.canMakePayment( {
				paymentMethods,
				billingAddress: { country: 'BE' },
			} )
		).toBe( true );
	} );

	it( 'keeps a registered method visible in the editor preview', () => {
		const paymentMethod = getWooPaymentsPaymentMethod( {
			gatewayId: 'woocommerce_payments_klarna',
			isCheckout: false,
			isCoreNativeCheckoutAvailable: true,
			paymentMethodsConfig: {
				klarna: {
					title: 'Klarna',
				},
			},
		} );

		expect(
			paymentMethod.canMakePayment( {
				paymentMethods: [ 'cod', 'bacs', 'cheque' ],
			} )
		).toBe( true );
	} );

	it( 'initializes split gateway Elements with the configured Stripe payment method type', async () => {
		const elements = jest.fn( () => ( {
			create: jest.fn( () => ( {
				mount: jest.fn(),
			} ) ),
		} ) );
		window.Stripe = jest.fn( () => ( {
			elements,
			createPaymentMethod: jest.fn().mockResolvedValue( {} ),
		} ) );

		registerPaymentMethod.mockClear();

		registerWooPayments();

		const klarnaPaymentMethod = registerPaymentMethod.mock.calls
			.map( ( [ paymentMethod ] ) => paymentMethod )
			.find(
				( paymentMethod ) =>
					paymentMethod.name === 'woocommerce_payments_klarna'
			);
		const content = klarnaPaymentMethod.content;

		render(
			createElement( content.type, {
				...content.props,
				eventRegistration: {
					onPaymentSetup: jest.fn(),
					onCheckoutSuccess: jest.fn(),
				},
				emitResponse: {
					responseTypes: {
						SUCCESS: 'success',
						ERROR: 'error',
					},
					noticeContexts: {
						PAYMENTS: 'payments',
					},
				},
			} )
		);

		await waitFor( () => {
			expect( elements ).toHaveBeenCalled();
		} );

		expect( elements ).toHaveBeenCalledWith(
			expect.objectContaining( {
				paymentMethodTypes: [ 'klarna' ],
			} )
		);
	} );
} );
