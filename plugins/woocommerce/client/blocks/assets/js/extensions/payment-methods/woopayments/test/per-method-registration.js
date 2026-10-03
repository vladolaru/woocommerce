/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';

/**
 * Internal dependencies
 */
import registerWooPayments, { getWooPaymentsPaymentMethod } from '../index';
import { getCachedAppearance } from '../upe-styles';

jest.mock( '@woocommerce/blocks-registry', () => ( {
	registerPaymentMethod: jest.fn(),
	registerExpressPaymentMethod: jest.fn(),
} ) );

jest.mock( '../upe-styles', () => ( {
	...jest.requireActual( '../upe-styles' ),
	getCachedAppearance: jest.fn(),
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
					icon: 'https://example.com/klarna.svg',
					darkIcon: 'https://example.com/klarna-dark.svg',
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
	beforeEach( () => {
		getCachedAppearance.mockReturnValue( null );
	} );

	afterEach( () => {
		delete window.Stripe;
		document.body.innerHTML = '';
		jest.restoreAllMocks();
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

	it( 'renders definition branding and follows the cached checkout appearance', () => {
		getCachedAppearance.mockReturnValue( { theme: 'stripe' } );
		const addEventListener = jest.spyOn( window, 'addEventListener' );
		const removeEventListener = jest.spyOn( window, 'removeEventListener' );
		const PaymentMethodLabel = ( { text, icon } ) => (
			<span>
				{ text }
				{ icon }
			</span>
		);
		const paymentMethod = getWooPaymentsPaymentMethod( {
			gatewayId: 'woocommerce_payments_klarna',
			stylesCacheVersion: 'styles-v1',
			paymentMethodsConfig: {
				klarna: {
					title: 'Klarna',
					icon: 'https://example.com/klarna.svg',
					darkIcon: 'https://example.com/klarna-dark.svg',
				},
			},
		} );
		const { container, unmount } = render(
			createElement( paymentMethod.label.type, {
				...paymentMethod.label.props,
				components: { PaymentMethodLabel },
			} )
		);
		const icon = container.querySelector( 'img' );

		expect( icon ).toHaveClass( 'wcpay-payment-method-icon' );
		expect( icon ).toHaveAttribute(
			'src',
			'https://example.com/klarna.svg'
		);
		expect( icon ).toHaveAttribute( 'alt', 'Klarna' );

		const appearanceListener = addEventListener.mock.calls.find(
			( [ eventName ] ) => eventName === 'wcpay-appearance-cached'
		)?.[ 1 ];
		getCachedAppearance.mockReturnValue( { theme: 'night' } );
		act( () => {
			window.dispatchEvent( new Event( 'wcpay-appearance-cached' ) );
		} );

		expect( container.querySelector( 'img' ) ).toBe( icon );
		expect( icon ).toHaveAttribute(
			'src',
			'https://example.com/klarna-dark.svg'
		);

		unmount();
		expect( appearanceListener ).toEqual( expect.any( Function ) );
		expect( removeEventListener ).toHaveBeenCalledWith(
			'wcpay-appearance-cached',
			appearanceListener
		);

		const labelOnlyMethod = getWooPaymentsPaymentMethod( {
			gatewayId: 'woocommerce_payments_klarna',
			paymentMethodsConfig: { klarna: { title: 'Klarna' } },
		} );
		const labelOnly = render(
			createElement( labelOnlyMethod.label.type, {
				...labelOnlyMethod.label.props,
				components: { PaymentMethodLabel },
			} )
		);

		expect( labelOnly.queryByRole( 'img' ) ).not.toBeInTheDocument();
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
