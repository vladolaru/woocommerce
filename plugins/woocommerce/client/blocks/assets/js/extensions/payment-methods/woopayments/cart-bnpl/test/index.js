/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';
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
	let destroyMessagingElement;
	let elements;
	let mountMessagingElement;
	let updateMessagingElement;

	beforeEach( () => {
		mockSelect.mockReset();

		mountMessagingElement = jest.fn();
		destroyMessagingElement = jest.fn();
		updateMessagingElement = jest.fn();
		createElement = jest.fn( () => ( {
			mount: mountMessagingElement,
			destroy: destroyMessagingElement,
			update: updateMessagingElement,
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

	// Client 11.2.0 builds its Stripe instance once for the page (cart/blocks/product-details.js:28-37, :79) and React
	// Stripe.js keeps the element, updating its options in place (:109-125). A new cart object with the same total, as
	// the optimistic update and the server answer can give, changes nothing.
	it( 'keeps one Stripe instance and one element, updating the amount in place, when the cart changes', async () => {
		const { rerender } = render(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '12345' } } }
				context="woocommerce/cart"
			/>
		);
		await waitFor( () => {
			expect( mountMessagingElement ).toHaveBeenCalledTimes( 1 );
		} );

		rerender(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '24690' } } }
				context="woocommerce/cart"
			/>
		);
		rerender(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '24690' } } }
				context="woocommerce/cart"
			/>
		);
		rerender(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '37035' } } }
				context="woocommerce/cart"
			/>
		);

		expect( window.Stripe ).toHaveBeenCalledTimes( 1 );
		expect( createElement ).toHaveBeenCalledTimes( 1 );
		expect( mountMessagingElement ).toHaveBeenCalledTimes( 1 );
		expect( destroyMessagingElement ).not.toHaveBeenCalled();
		expect( updateMessagingElement.mock.calls ).toEqual( [
			[ { amount: 24690 } ],
			[ { amount: 37035 } ],
		] );
	} );

	// Client 11.2.0 waits for Stripe.js through useStripeAsync( api ) (cart/blocks/product-details.js:79,
	// hooks/use-stripe-async.js): a deferred Stripe.js still gets the message, built once with the latest total.
	describe( 'with Stripe.js loading after the cart renders', () => {
		let loadedStripe;

		beforeEach( () => {
			jest.useFakeTimers();
			loadedStripe = window.Stripe;
			delete window.Stripe;
		} );

		afterEach( () => {
			jest.useRealTimers();
		} );

		const renderCart = ( totalPrice ) => (
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: totalPrice } } }
				context="woocommerce/cart"
			/>
		);

		it( 'creates one element with the latest total once Stripe.js loads, then updates it', async () => {
			const { rerender } = render( renderCart( '12345' ) );
			rerender( renderCart( '24690' ) );
			expect( createElement ).not.toHaveBeenCalled();

			window.Stripe = loadedStripe;
			await act( async () => {
				await jest.advanceTimersByTimeAsync( 100 );
			} );
			rerender( renderCart( '37035' ) );

			expect( createElement ).toHaveBeenCalledTimes( 1 );
			expect( createElement ).toHaveBeenCalledWith(
				'paymentMethodMessaging',
				expect.objectContaining( { amount: 24690 } )
			);
			expect( mountMessagingElement ).toHaveBeenCalledTimes( 1 );
			expect( updateMessagingElement.mock.calls ).toEqual( [
				[ { amount: 37035 } ],
			] );
			expect( destroyMessagingElement ).not.toHaveBeenCalled();
		} );

		it( 'creates nothing and stops waiting when the cart unmounts before Stripe.js loads', async () => {
			const { unmount } = render( renderCart( '12345' ) );
			unmount();
			expect( jest.getTimerCount() ).toBe( 0 );

			window.Stripe = loadedStripe;
			await act( async () => {
				await jest.advanceTimersByTimeAsync( 100 );
			} );

			expect( createElement ).not.toHaveBeenCalled();
			expect( jest.getTimerCount() ).toBe( 0 );
		} );
	} );

	it( 'builds a new Stripe instance when the account or locale changes', async () => {
		const { rerender } = render(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '12345' } } }
				context="woocommerce/cart"
			/>
		);
		await waitFor( () => {
			expect( mountMessagingElement ).toHaveBeenCalledTimes( 1 );
		} );

		setMessagingConfig( { accountId: 'acct_other', locale: 'fr' } );
		rerender(
			<BNPLCartMessaging
				cart={ { cartTotals: { total_price: '24690' } } }
				context="woocommerce/cart"
			/>
		);
		await waitFor( () => {
			expect( mountMessagingElement ).toHaveBeenCalledTimes( 2 );
		} );

		expect( window.Stripe ).toHaveBeenCalledTimes( 2 );
		expect( window.Stripe ).toHaveBeenLastCalledWith( 'pk_test_123', {
			locale: 'fr',
			stripeAccount: 'acct_other',
		} );
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
