/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { BNPLCartMessaging, renderBNPLCartMessaging } from '..';

const mockSelect = jest.fn();
const mockAppearance = { variables: { colorText: '#111111' } };
const mockFontRules = [ { cssSrc: 'https://example.test/font.css' } ];

jest.mock( '@wordpress/plugins', () => ( {
	registerPlugin: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => ( {
	select: ( ...args ) => mockSelect( ...args ),
} ) );

jest.mock( '@woocommerce/blocks-checkout', () => {
	const MockExperimentalOrderMeta = ( { children } ) => (
		<div data-testid="order-meta-fill">{ children }</div>
	);

	return { ExperimentalOrderMeta: MockExperimentalOrderMeta };
} );

jest.mock( '../../upe-styles', () => ( {
	getCachedAppearance: jest.fn( () => mockAppearance ),
	getAppearance: jest.fn( () => mockAppearance ),
	setCachedAppearance: jest.fn(),
	dispatchAppearanceEvent: jest.fn(),
	getFontRulesFromPage: jest.fn( () => mockFontRules ),
} ) );

const setMessagingConfig = ( overrides = {} ) => {
	window.wcpayStripeSiteMessaging = {
		accountId: 'acct_test',
		country: 'US',
		currencyCode: 'USD',
		locale: 'en',
		paymentMethods: [ 'affirm', 'klarna' ],
		publishableKey: 'pk_test_123',
		shouldInitializePMME: true,
		stylesCacheVersion: 'styles-v1',
		...overrides,
	};
};

describe( 'WooPayments cart block BNPL messaging', () => {
	let createElement;
	let elements;
	let mountMessagingElement;

	beforeEach( () => {
		mockSelect.mockReset();

		mountMessagingElement = jest.fn();
		createElement = jest.fn( () => ( {
			mount: mountMessagingElement,
			destroy: jest.fn(),
		} ) );
		elements = jest.fn( () => ( {
			create: createElement,
		} ) );

		window.Stripe = jest.fn( () => ( { elements } ) );
		window.wcSettings = { currency: { precision: 2 } };
		setMessagingConfig();
		mockSelect.mockReturnValue( undefined );
	} );

	afterEach( () => {
		delete window.Stripe;
		delete window.wcpayStripeSiteMessaging;
		delete window.wcSettings;
		document.body.innerHTML = '';
	} );

	it( 'registers the cart block BNPL messaging plugin', () => {
		expect( registerPlugin ).toHaveBeenCalledWith(
			'bnpl-site-messaging',
			expect.objectContaining( {
				render: renderBNPLCartMessaging,
				scope: 'woocommerce-checkout',
			} )
		);
	} );

	it( 'does not render the order meta fill in the editor', () => {
		mockSelect.mockReturnValue( {} );

		expect( renderBNPLCartMessaging() ).toBeNull();
	} );

	it( 'mounts a Stripe payment method messaging element for the cart context', async () => {
		const { container } = render(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '12345' } } }
				context="woocommerce/cart"
			/>
		);

		await waitFor( () => {
			expect( mountMessagingElement ).toHaveBeenCalled();
		} );

		expect( window.Stripe ).toHaveBeenCalledWith( 'pk_test_123', {
			locale: 'en',
			stripeAccount: 'acct_test',
		} );
		expect( elements ).toHaveBeenCalledWith( {
			appearance: mockAppearance,
			fonts: mockFontRules,
		} );
		expect( createElement ).toHaveBeenCalledWith(
			'paymentMethodMessaging',
			{
				amount: 12345,
				countryCode: 'US',
				currency: 'USD',
				paymentMethodTypes: [ 'affirm', 'klarna' ],
			}
		);
		expect(
			container.querySelector( '.wc-block-components-bnpl-wrapper' )
		).not.toBeNull();
	} );

	it( 'renders nothing outside the cart context', () => {
		const { container } = render(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '12345' } } }
				context="woocommerce/checkout"
			/>
		);

		expect( container ).toBeEmptyDOMElement();
		expect( window.Stripe ).not.toHaveBeenCalled();
	} );

	it( 'renders nothing when PMME should not initialize', () => {
		setMessagingConfig( { shouldInitializePMME: false } );

		const { container } = render(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '12345' } } }
				context="woocommerce/cart"
			/>
		);

		expect( container ).toBeEmptyDOMElement();
		expect( window.Stripe ).not.toHaveBeenCalled();
	} );
} );
