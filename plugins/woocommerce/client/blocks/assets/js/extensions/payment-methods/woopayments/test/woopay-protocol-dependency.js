/**
 * External dependencies
 */
import { screen, waitFor } from '@testing-library/react';

/*
 * The card script lists the WooPay protocol entry only when its data turns the WooPay email check on
 * (src/Blocks/Payments/Integrations/WooPayments.php); without it the build's external for ./woopay/protocol is
 * undefined. The protocol stands in here as a module that records every member the card reads, and holds nothing
 * unless a test loads it.
 */
const mockProtocolReads = [];
const mockProtocol = { members: null };
jest.mock(
	'../woopay/protocol',
	() =>
		new Proxy(
			{},
			{
				get: ( target, member ) => {
					mockProtocolReads.push( member );
					return mockProtocol.members
						? mockProtocol.members[ member ]
						: undefined;
				},
			}
		)
);

let mockPaymentMethodData = {};
jest.mock( '@woocommerce/settings', () => ( {
	getPaymentMethodData: jest.fn( ( name, defaultValue ) =>
		name === 'woocommerce_payments' ? mockPaymentMethodData : defaultValue
	),
	getSetting: jest.fn( ( setting, defaultValue ) => {
		if ( setting === 'paymentMethodData' ) {
			return { woocommerce_payments: mockPaymentMethodData };
		}
		if ( setting === 'storePages' ) {
			return {
				checkout: { permalink: 'https://example.test/checkout/' },
			};
		}
		return defaultValue;
	} ),
} ) );

jest.mock( '@woocommerce/blocks-registry', () => ( {
	registerPaymentMethod: jest.fn(),
	registerExpressPaymentMethod: jest.fn(),
} ) );

jest.mock( '@fingerprintjs/fingerprintjs', () => ( {
	__esModule: true,
	default: {
		load: jest.fn().mockResolvedValue( {
			get: () => Promise.resolve( { visitorId: 'device_fp_123' } ),
		} ),
	},
} ) );

// The bundle loads StoreNotice from the wc-blocks-components script at runtime; the tests render a plain stand-in.
jest.mock( '@woocommerce/blocks-components', () => ( {
	StoreNotice: ( { children } ) => <div role="alert">{ children }</div>,
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( ( callback ) =>
		callback( () => ( {
			getActivePaymentMethod: () => 'woocommerce_payments',
			getValidationError: () => undefined,
			getPaymentMethodData: () => ( {
				payment_method: 'woocommerce_payments',
				token: '',
			} ),
		} ) )
	),
	select: jest.fn( () => ( { getNotices: () => [] } ) ),
	dispatch: jest.fn( () => ( {
		setValidationErrors: jest.fn(),
		clearValidationError: jest.fn(),
		removeNotice: jest.fn(),
	} ) ),
} ) );

// Blocks payment method data of the card, as WooPaymentsCheckoutAssets::get_blocks_payment_method_data() builds it.
const cardData = {
	title: 'Card',
	supports: [ 'products' ],
	gatewayId: 'woocommerce_payments',
	publishableKey: 'pk_test_123',
	accountId: 'acct_123',
	cartTotal: 1000,
	stylesCacheVersion: 'styles-v1',
	currency: 'USD',
	isCoreNativeCheckoutAvailable: true,
	wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
	paymentMethodTypes: [ 'card' ],
	paymentMethodsConfig: { card: { title: 'Card', isReusable: true } },
};

// The WooPay keys WooPay\SessionService adds when WooPay is on.
const wooPayData = {
	isWooPayEnabled: true,
	forceNetworkSavedCards: true,
	woopaySessionNonce: 'session-nonce',
	initWooPayNonce: 'init-nonce',
	woopayHost: 'https://pay.woo.test',
	PRE_CHECK_SAVE_MY_INFO: true,
	woopayPhoneValidationScriptUrl:
		'https://example.test/wc-woopayments-phone-validation.js',
};

// The card script wires the email check when it loads, so each test loads it afresh with its data, together with the
// React copy that renders it; the pure entry leaves the cleanup to afterEach().
let mockCleanup = () => {};
const loadCard = ( paymentMethodData ) => {
	mockPaymentMethodData = paymentMethodData;
	let card;
	jest.isolateModules( () => {
		const testingLibrary = require( '@testing-library/react/pure' );
		mockCleanup = testingLibrary.cleanup;
		card = {
			registerWooPayments: require( '../index' ).default,
			render: testingLibrary.render,
			fireEvent: testingLibrary.fireEvent,
			createElement: require( '@wordpress/element' ).createElement,
		};
	} );
	return card;
};

const renderCard = ( { registerWooPayments, render, createElement } ) => {
	// The checkout's payment block, where the save-user section renders.
	document.body.innerHTML = `
		<div class="wc-block-checkout">
			<div class="wp-block-woocommerce-checkout-payment-block"></div>
		</div>
		<input id="billing-phone" value="" />
	`;
	const { content } = registerWooPayments();
	return render(
		createElement( content.type, {
			...content.props,
			eventRegistration: {
				onPaymentSetup: jest.fn(),
				onCheckoutSuccess: jest.fn(),
			},
			emitResponse: {
				responseTypes: { SUCCESS: 'success', ERROR: 'error' },
				noticeContexts: { PAYMENTS: 'payments' },
			},
		} )
	);
};

describe( 'wc-payment-method-woopayments and the WooPay protocol entry', () => {
	const originalFetch = window.fetch;

	beforeEach( () => {
		mockProtocolReads.length = 0;
		mockProtocol.members = null;
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: jest.fn().mockResolvedValue( { success: true } ),
		} );
	} );

	afterEach( () => {
		mockCleanup();
		mockCleanup = () => {};
		delete window.wcWooPaymentsPhoneValidation;
		window.fetch = originalFetch;
		document.body.innerHTML = '';
		document.head.innerHTML = '';
		jest.clearAllMocks();
	} );

	it( 'registers and renders the card with WooPay off without reading the WooPay protocol', async () => {
		// A WooPay-off store's data: the email input flag does not follow the WooPay setting.
		renderCard(
			loadCard( {
				...cardData,
				isWooPayEnabled: false,
				isWooPayEmailInputEnabled: true,
			} )
		);

		await waitFor( () => {
			expect(
				document.querySelector( '#wcpay-core-blocks-payment-element' )
			).not.toBeNull();
		} );
		expect(
			screen.queryByLabelText( 'Mobile phone number' )
		).not.toBeInTheDocument();
		expect( mockProtocolReads ).toEqual( [] );
	} );

	it( 'runs the WooPay save-user section with the email check off without reading the WooPay protocol', async () => {
		const card = loadCard( {
			...cardData,
			...wooPayData,
			isWooPayEmailInputEnabled: false,
		} );
		renderCard( card );

		const phoneField = await screen.findByLabelText(
			'Mobile phone number'
		);
		card.fireEvent.change( phoneField, {
			target: { value: '2015550123' },
		} );

		expect(
			document.querySelector(
				'input[name="woopay_user_phone_field[full]"]'
			)
		).toHaveValue( '+12015550123' );
		// The pre-checked section loads the phone validation script; once it loads, the valid number is stored.
		const script = document.querySelector(
			'script[src="https://example.test/wc-woopayments-phone-validation.js"]'
		);
		expect( script ).not.toBeNull();
		window.wcWooPaymentsPhoneValidation = {
			validatePhoneNumber: () => true,
		};
		card.fireEvent.load( script );
		await waitFor( () => {
			expect( window.fetch ).toHaveBeenCalledWith(
				'/?wc-ajax=wcpay_set_woopay_phone_number',
				expect.objectContaining( { method: 'POST' } )
			);
		} );
		expect( mockProtocolReads ).toEqual( [] );
	} );

	it( 'wires the WooPay email check through the protocol when WooPay and its email input are on', async () => {
		document.body.innerHTML =
			'<div data-block-name="woocommerce/checkout"><input type="email" id="email" /></div>';
		const handleEmailInput = jest.fn();
		mockProtocol.members = {
			...jest.requireActual( '../woopay/protocol' ),
			handleEmailInput,
		};

		loadCard( {
			...cardData,
			...wooPayData,
			isWooPayEmailInputEnabled: true,
		} );

		await waitFor( () => {
			expect( handleEmailInput ).toHaveBeenCalledWith(
				expect.objectContaining( {
					isBlocksCheckout: true,
					wcAjaxUrl: '/?wc-ajax=%%endpoint%%',
				} ),
				document.querySelector( '#email' ),
				undefined
			);
		} );
		expect( mockProtocolReads ).toEqual(
			expect.arrayContaining( [
				'isPreviewing',
				'getTargetElement',
				'handleEmailInput',
			] )
		);
	} );
} );
