jest.mock( '@ppcp-button/Helper/UpdateCart', () =>
	jest.fn().mockImplementation( () => ( {} ) )
);

const mockConfiguration = jest.fn( () => ( { standard: true } ) );
const mockGetProducts = jest.fn( () => [] );
jest.mock( '@ppcp-button/ActionHandler/SingleProductActionHandler', () =>
	jest.fn().mockImplementation( () => ( {
		configuration: mockConfiguration,
		getProducts: mockGetProducts,
	} ) )
);

let simulateCartData = null;
const mockSimulate = jest.fn( ( onResolve ) => onResolve( simulateCartData ) );
jest.mock( '@ppcp-button/Helper/SimulateCart', () =>
	jest.fn().mockImplementation( () => ( {
		simulate: ( ...args ) => mockSimulate( ...args ),
	} ) )
);

jest.mock( '@ppcp-button/Helper/BootstrapHelper', () => ( {
	__esModule: true,
	default: { updateScriptData: jest.fn(), handleButtonStatus: jest.fn() },
} ) );

import SingleProductBootstrap from './SingleProductBootstrap';

/**
 * Builds a SingleProductBootstrap instance without running its constructor's
 * DOM/timer side effects (MutationObserver, throttle, debounce, ...), since
 * render() itself doesn't depend on any of them.
 *
 * @param {Object} overrides - Properties to set on the instance (gateway, variations, form, ...).
 * @return {SingleProductBootstrap} The bootstrap instance.
 */
function buildBootstrap( overrides = {} ) {
	const instance = Object.create( SingleProductBootstrap.prototype );
	instance.gateway = {
		ajax: {
			change_cart: { endpoint: '/cc', nonce: 'n' },
			simulate_cart: { endpoint: '/sim', nonce: 'n' },
		},
		url_params: {},
		button: { wrapper: '#ppc-button-ppcp-gateway' },
		vaultingEnabled: true,
		manualRenewalEnabled: '0',
		productType: 'subscription',
		simulate_cart: { enabled: true },
		single_product_buttons_enabled: '1',
	};
	instance.renderer = { render: jest.fn() };
	instance.errorHandler = {};
	instance.variations = jest.fn( () => null );
	instance.form = jest.fn( () => null );

	Object.assign( instance, overrides );

	return instance;
}

beforeEach( () => {
	jest.clearAllMocks();
	simulateCartData = null;
	document.body.innerHTML =
		'<div id="ppc-button-ppcp-gateway"></div><form class="cart"></form>';
	global.PayPalCommerceGateway = {
		client_id: 'client-1',
		currency: 'USD',
	};
	global.jQuery = jest.fn( () => ( { trigger: jest.fn() } ) );
} );

afterEach( () => {
	delete global.PayPalCommerceGateway;
	delete global.jQuery;
	document.body.innerHTML = '';
} );

describe( 'SingleProductBootstrap render', () => {
	test( 'renders the standard button for a regular product', () => {
		const instance = buildBootstrap();

		instance.render();

		expect( instance.renderer.render ).toHaveBeenCalledWith( {
			standard: true,
		} );
	} );

	test( 'renders nothing for a subscription product when vaulting and manual renewals are both off', () => {
		const instance = buildBootstrap( {
			gateway: {
				ajax: { change_cart: { endpoint: '/cc', nonce: 'n' } },
				url_params: {},
				button: { wrapper: '#ppc-button-ppcp-gateway' },
				vaultingEnabled: false,
				manualRenewalEnabled: '0',
				productType: 'subscription',
			},
		} );

		instance.render();

		expect( instance.renderer.render ).not.toHaveBeenCalled();
	} );

	test( 'renders the standard button for a subscription when manual renewals are enabled and vaulting is disabled', () => {
		const instance = buildBootstrap( {
			gateway: {
				ajax: { change_cart: { endpoint: '/cc', nonce: 'n' } },
				url_params: {},
				button: { wrapper: '#ppc-button-ppcp-gateway' },
				vaultingEnabled: false,
				manualRenewalEnabled: '1',
				productType: 'subscription',
			},
		} );

		instance.render();

		expect( instance.renderer.render ).toHaveBeenCalledWith( {
			standard: true,
		} );
	} );
} );

describe( 'SingleProductBootstrap simulateCart', () => {
	// PHP only sets url_params['enable-funding']/['disable-funding'] when there is
	// something to enable/disable for the current context, so both are commonly absent
	// (undefined) rather than an empty string. strAddWord()/strRemoveWord() call
	// .split() on their first argument, which previously crashed here whenever the
	// simulated cart response toggled a funding source's eligibility.
	test( 'does not throw when url_params has no enable-funding/disable-funding keys', () => {
		simulateCartData = {
			total: '10.00',
			button: { is_disabled: false },
			messages: { is_hidden: false },
			funding: { venmo: { enabled: false } },
		};
		const instance = buildBootstrap();

		expect( () => instance.simulateCart() ).not.toThrow();
		expect( instance.gateway.url_params[ 'disable-funding' ] ).toBe(
			'venmo'
		);
	} );

	test( 'adds and removes funding sources from the existing lists', () => {
		simulateCartData = {
			total: '10.00',
			button: { is_disabled: false },
			messages: { is_hidden: false },
			funding: { venmo: { enabled: true } },
		};
		const instance = buildBootstrap();
		instance.gateway.url_params = { 'disable-funding': 'venmo,card' };

		instance.simulateCart();

		expect( instance.gateway.url_params[ 'disable-funding' ] ).toBe(
			'card'
		);
		expect( instance.gateway.url_params[ 'enable-funding' ] ).toBe(
			'venmo'
		);
	} );
} );
