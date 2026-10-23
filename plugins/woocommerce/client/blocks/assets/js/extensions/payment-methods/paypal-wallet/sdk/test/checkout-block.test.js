const mockRegisterExpressPaymentMethod = jest.fn();
const mockRegisterPaymentMethod = jest.fn();
const mockRegisterCheckoutFilters = jest.fn();
jest.mock( '@woocommerce/blocks-registry', () => ( {
	registerExpressPaymentMethod: ( ...args ) =>
		mockRegisterExpressPaymentMethod( ...args ),
	registerPaymentMethod: ( ...args ) => mockRegisterPaymentMethod( ...args ),
} ) );

const mockLoadSdkV6 = jest.fn();
jest.mock( '../sdkLoader', () => ( {
	loadSdkV6: ( ...args ) => mockLoadSdkV6( ...args ),
} ) );
const mockCheckEligibility = jest.fn();
jest.mock( '../eligibility', () => ( {
	checkEligibility: ( ...args ) => mockCheckEligibility( ...args ),
} ) );
jest.mock( '../utils/errorHandler', () => ( { setErrorLabels: jest.fn() } ) );
jest.mock( '../blocks/V6ExpressComponent', () => ( {
	V6ExpressComponent: () => null,
} ) );
jest.mock( '../blocks/V6ContinuationComponent', () => ( {
	V6ContinuationComponent: () => null,
} ) );
jest.mock( '../blocks/V6EditorPreview', () => ( {
	V6EditorPreview: () => null,
} ) );
jest.mock( '../../blocks/Components/paypal-saved-token', () => ( {
	PayPalSavedToken: () => null,
} ) );
jest.mock( '../messages/renderer', () => ( {
	initMessages: jest.fn( () => Promise.resolve() ),
	updateMessagesAmount: jest.fn(),
} ) );
jest.mock( '../messages/cartTotalWatcher', () => ( {
	watchBlockCartTotal: jest.fn(),
} ) );

/**
 * The wc_ppcp_sdk_v6 config shape checkout-block.js reads for a normal
 * (non-continuation) checkout.
 */
const baseConfig = ( overrides = {} ) => ( {
	id: 'ppcp-gateway',
	page_context: 'checkout',
	buttons_enabled: true,
	supported_features: [ 'products', 'subscriptions' ],
	pay_later_button: { checkout: true },
	venmo_button: { checkout: true },
	...overrides,
} );

/**
 * Sets the wcSettings global the module reads at import time, then imports it
 * fresh so its module-scope registration runs against this test's config.
 *
 * @param {Object} config - The 'ppcp-sdk-v6' payment method data.
 */
function loadCheckoutBlock( config ) {
	window.wc = {
		wcSettings: {
			getSetting: ( key ) =>
				key === 'paymentMethodData'
					? { 'ppcp-sdk-v6': config }
					: undefined,
		},
		blocksCheckout: {
			registerCheckoutFilters: ( ...args ) =>
				mockRegisterCheckoutFilters( ...args ),
		},
	};
	jest.isolateModules( () => {
		require( '../checkout-block.js' );
	} );
}

/**
 * The registerExpressPaymentMethod call for one registered name.
 *
 * @param {string} name - The registration name to find.
 * @return {Object} The args object passed to registerExpressPaymentMethod.
 */
function expressCallFor( name ) {
	const call = mockRegisterExpressPaymentMethod.mock.calls.find(
		( [ args ] ) => args.name === name
	);
	return call[ 0 ];
}

/**
 * The registerPaymentMethod calls for one registered name.
 *
 * @param {string} name - The registration name to find.
 * @return {Object[]} The args objects passed to registerPaymentMethod.
 */
function regularCallsFor( name ) {
	return mockRegisterPaymentMethod.mock.calls
		.filter( ( [ args ] ) => args.name === name )
		.map( ( [ args ] ) => args );
}

/**
 * The single registerPaymentMethod call for one registered name.
 *
 * @param {string} name - The registration name to find.
 * @return {Object|undefined} The args object passed to registerPaymentMethod,
 *                            or undefined when no such call was made.
 */
function regularCallFor( name ) {
	return regularCallsFor( name )[ 0 ];
}

beforeEach( () => {
	jest.clearAllMocks();
} );

afterEach( () => {
	delete window.wc;
	delete window.wp;
} );

describe( 'checkout-block', () => {
	describe( 'express method registration', () => {
		// The SDK can load on a block page for another surface, such as the
		// mini-cart, while the page's own button location is off.
		test( 'registers no express method when the page location is off', () => {
			loadCheckoutBlock( baseConfig( { buttons_enabled: false } ) );

			expect( mockRegisterExpressPaymentMethod ).not.toHaveBeenCalled();
		} );

		test( 'registers no Venmo express method when the merchant does not offer Venmo here', () => {
			loadCheckoutBlock(
				baseConfig( { venmo_button: { checkout: false } } )
			);

			const names = mockRegisterExpressPaymentMethod.mock.calls.map(
				( [ args ] ) => args.name
			);
			expect( names ).toEqual( [
				'ppcp-gateway-paypal',
				'ppcp-gateway-paylater',
			] );
		} );

		test( 'still registers the regular PayPal row when the page location is off', () => {
			loadCheckoutBlock(
				baseConfig( {
					buttons_enabled: false,
					place_order_enabled: true,
				} )
			);

			expect( regularCallFor( 'ppcp-gateway' ) ).toBeDefined();
		} );

		test.each( [
			[ 'ppcp-gateway-paypal', [ 'products', 'subscriptions' ] ],
			[ 'ppcp-gateway-venmo', [ 'products', 'subscriptions' ] ],
			[ 'ppcp-gateway-paylater', [ 'products', 'subscriptions' ] ],
		] )(
			'%s declares ppcp_continuation alongside its own supported features',
			( name, ownFeatures ) => {
				loadCheckoutBlock( baseConfig() );

				const { supports } = expressCallFor( name );

				expect( supports.features ).toEqual( [
					...ownFeatures,
					'ppcp_continuation',
				] );
			}
		);

		/**
		 * Regression test: WooCommerce Blocks withdraws any payment method whose
		 * supports.features misses a cart requirement. The plugin's Store API
		 * requirement flips to ['ppcp_continuation'] the moment the buyer
		 * approves in the express popup, i.e. mid-flow for the very method that
		 * is submitting the checkout. Without the feature here that method is
		 * withdrawn along with the rest, activePaymentMethod clears, and the
		 * checkout POST goes out with no payment_method.
		 */
		test( 'ppcp_continuation is present even when the gateway declares no supported_features of its own', () => {
			loadCheckoutBlock(
				baseConfig( { supported_features: undefined } )
			);

			const { supports } = expressCallFor( 'ppcp-gateway-paypal' );

			expect( supports.features ).toEqual( [
				'products',
				'ppcp_continuation',
			] );
		} );

		test( 'ppcp-gateway-paypal processes through the gateway id the server supplied', () => {
			loadCheckoutBlock( baseConfig( { id: 'ppcp-gateway-custom' } ) );

			expect( expressCallFor( 'ppcp-gateway-paypal' ).gatewayId ).toBe(
				'ppcp-gateway-custom'
			);
		} );
	} );

	describe( 'canMakePayment on a subscription cart (cart_needs_vaulting)', () => {
		const eligibleForEveryMethod = {
			paypal: true,
			venmo: true,
			paylater: true,
		};

		beforeEach( () => {
			mockLoadSdkV6.mockResolvedValue( {} );
			mockCheckEligibility.mockResolvedValue( eligibleForEveryMethod );
		} );

		test( 'a cart that became $0 after page load is a free trial even though the server said it was not', async () => {
			loadCheckoutBlock(
				baseConfig( {
					cart_needs_vaulting: true,
					is_free_trial_cart: false,
				} )
			);
			const cartTotals = { total_price: '0', currency_minor_unit: 2 };

			const results = await Promise.all(
				[
					'ppcp-gateway-paypal',
					'ppcp-gateway-venmo',
					'ppcp-gateway-paylater',
				].map( ( name ) =>
					expressCallFor( name ).canMakePayment( { cartTotals } )
				)
			);

			expect( results ).toEqual( [ true, false, false ] );
		} );

		test( 'a cart that rose above $0 after page load is not a free trial even though the server said it was', async () => {
			loadCheckoutBlock(
				baseConfig( {
					cart_needs_vaulting: true,
					is_free_trial_cart: true,
				} )
			);
			const cartTotals = {
				total_price: '4900',
				currency_minor_unit: 2,
			};

			const results = await Promise.all(
				[
					'ppcp-gateway-paypal',
					'ppcp-gateway-venmo',
					'ppcp-gateway-paylater',
				].map( ( name ) =>
					expressCallFor( name ).canMakePayment( { cartTotals } )
				)
			);

			expect( results ).toEqual( [ true, true, true ] );
		} );

		test( 'a non-subscription cart at $0 is unaffected: eligibility alone decides', async () => {
			loadCheckoutBlock(
				baseConfig( {
					cart_needs_vaulting: false,
					is_free_trial_cart: false,
				} )
			);
			const cartTotals = { total_price: '0', currency_minor_unit: 2 };

			const results = await Promise.all(
				[
					'ppcp-gateway-paypal',
					'ppcp-gateway-venmo',
					'ppcp-gateway-paylater',
				].map( ( name ) =>
					expressCallFor( name ).canMakePayment( { cartTotals } )
				)
			);

			expect( results ).toEqual( [ true, true, true ] );
		} );
	} );

	describe( 'regular ppcp-gateway registration', () => {
		describe( 'when the place-order row is enabled', () => {
			test( 'registers ppcp-gateway as a regular payment method even with no vault component', () => {
				loadCheckoutBlock(
					baseConfig( {
						place_order_enabled: true,
					} )
				);

				expect( regularCallFor( 'ppcp-gateway' ) ).toBeDefined();
			} );

			test( 'does not show saved cards when the vault component is not eligible', () => {
				loadCheckoutBlock(
					baseConfig( {
						place_order_enabled: true,
					} )
				);

				expect(
					regularCallFor( 'ppcp-gateway' ).supports.showSavedCards
				).toBe( false );
			} );

			test.each( [
				{
					name: 'a subscription cart is allowed even at a $0 live total',
					hasSubscriptions: true,
					totalPrice: '0',
					expected: true,
				},
				{
					name: 'a non-subscription cart at a $0 live total is not allowed',
					hasSubscriptions: false,
					totalPrice: '0',
					expected: false,
				},
				{
					name: 'a non-subscription cart with a positive live total is allowed',
					hasSubscriptions: false,
					totalPrice: '4900',
					expected: true,
				},
				{
					name: 'a free-trial subscription cart at a $0 live total is allowed: the gateway completes it via a server-side vault-approval redirect',
					hasSubscriptions: true,
					totalPrice: '0',
					cartNeedsVaulting: true,
					expected: true,
				},
			] )(
				'$name',
				( {
					hasSubscriptions,
					totalPrice,
					cartNeedsVaulting,
					expected,
				} ) => {
					loadCheckoutBlock(
						baseConfig( {
							place_order_enabled: true,
							has_subscriptions: hasSubscriptions,
							cart_needs_vaulting: cartNeedsVaulting,
							amount: '10.00',
						} )
					);

					const { canMakePayment } = regularCallFor( 'ppcp-gateway' );

					expect(
						canMakePayment( {
							cartTotals: {
								total_price: totalPrice,
								currency_minor_unit: 2,
							},
						} )
					).toBe( expected );
				}
			);
		} );

		describe( 'when only the saved-PayPal vault row is eligible', () => {
			test( 'registers ppcp-gateway with a savedTokenComponent, no placeOrderButtonLabel, and unconditional canMakePayment', () => {
				loadCheckoutBlock(
					baseConfig( {
						vault_component: { is_eligible: true },
					} )
				);

				const call = regularCallFor( 'ppcp-gateway' );

				expect( call.supports.showSavedCards ).toBe( true );
				expect( call.savedTokenComponent ).toBeDefined();
				expect( call ).not.toHaveProperty( 'placeOrderButtonLabel' );
				expect(
					call.canMakePayment( {
						cartTotals: {
							total_price: '0',
							currency_minor_unit: 2,
						},
					} )
				).toBe( true );
			} );
		} );

		test( 'registers the regular row under the gateway id the server supplied, not the literal ppcp-gateway', () => {
			loadCheckoutBlock(
				baseConfig( {
					id: 'ppcp-gateway-custom',
					place_order_enabled: true,
				} )
			);

			expect( regularCallFor( 'ppcp-gateway' ) ).toBeUndefined();
			expect( regularCallFor( 'ppcp-gateway-custom' ) ).toBeDefined();
		} );

		test( 'does not register ppcp-gateway when neither the place-order row nor vault eligibility apply', () => {
			loadCheckoutBlock( baseConfig() );

			expect( regularCallFor( 'ppcp-gateway' ) ).toBeUndefined();
		} );

		test( 'continuation mode registers ppcp-gateway once, keeping the continuation shape', () => {
			loadCheckoutBlock(
				baseConfig( {
					continuation: { funding_source: 'paypal' },
					place_order_enabled: true,
					vault_component: { is_eligible: true },
				} )
			);

			const calls = regularCallsFor( 'ppcp-gateway' );

			expect( calls ).toHaveLength( 1 );
			expect( calls[ 0 ].supports.features ).toContain(
				'ppcp_continuation'
			);
		} );
	} );

	/**
	 * The place order button is WooCommerce's, so nothing here renames it. An
	 * override only arrives when a merchant filters one in, and then it has to
	 * reach both the registration property and the Checkout Actions filter.
	 */
	describe( 'place order button label', () => {
		/**
		 * The filters registered for one namespace.
		 *
		 * @param {string} namespace - The registerCheckoutFilters namespace.
		 * @return {Object|undefined} The filters object, or undefined when none registered.
		 */
		const filtersFor = ( namespace ) =>
			mockRegisterCheckoutFilters.mock.calls.find(
				( [ ns ] ) => ns === namespace
			)?.[ 1 ];

		test( 'leaves the label alone when no override is filtered in', () => {
			loadCheckoutBlock( baseConfig( { place_order_enabled: true } ) );

			expect( regularCallFor( 'ppcp-gateway' ) ).not.toHaveProperty(
				'placeOrderButtonLabel'
			);
			expect( filtersFor( 'ppcp-gateway' ) ).toBeUndefined();
		} );

		test( 'applies a filtered override to both the registration and the checkout filter', () => {
			loadCheckoutBlock(
				baseConfig( {
					place_order_enabled: true,
					placeOrderButtonLabel: 'Complete order',
				} )
			);

			expect(
				regularCallFor( 'ppcp-gateway' ).placeOrderButtonLabel
			).toBe( 'Complete order' );

			const payment = { getActivePaymentMethod: () => 'ppcp-gateway' };
			window.wp = { data: { select: () => payment } };

			expect(
				filtersFor( 'ppcp-gateway' ).placeOrderButtonLabel(
					'Place order'
				)
			).toBe( 'Complete order' );

			delete window.wp;
		} );

		test( 'leaves the label alone in continuation mode', () => {
			loadCheckoutBlock(
				baseConfig( {
					continuation: { funding_source: 'venmo' },
					place_order_enabled: true,
					placeOrderButtonLabel: 'Complete order',
				} )
			);

			expect( regularCallFor( 'ppcp-gateway' ) ).not.toHaveProperty(
				'placeOrderButtonLabel'
			);
			expect( filtersFor( 'ppcp-gateway' ) ).toBeUndefined();
		} );
	} );

	describe( 'PayPal express canMakePayment()', () => {
		const cartTotals = { total_price: '0', currency_minor_unit: 2 };

		test( 'resolves to true on a free-trial cart without checking eligibility', async () => {
			loadCheckoutBlock(
				baseConfig( {
					is_free_trial_cart: true,
					cart_needs_vaulting: true,
				} )
			);

			const { canMakePayment } = expressCallFor( 'ppcp-gateway-paypal' );

			await expect( canMakePayment( { cartTotals } ) ).resolves.toBe(
				true
			);
			expect( mockLoadSdkV6 ).not.toHaveBeenCalled();
			expect( mockCheckEligibility ).not.toHaveBeenCalled();
		} );

		test.each( [
			[ { paypal: true }, true ],
			[ { paypal: false }, false ],
		] )(
			'on a non-free-trial cart, eligibility %s resolves to %s',
			async ( eligibility, expected ) => {
				mockLoadSdkV6.mockResolvedValue( {} );
				mockCheckEligibility.mockResolvedValue( eligibility );
				loadCheckoutBlock( baseConfig() );

				const { canMakePayment } = expressCallFor(
					'ppcp-gateway-paypal'
				);

				await expect( canMakePayment( { cartTotals } ) ).resolves.toBe(
					expected
				);
				expect( mockCheckEligibility ).toHaveBeenCalled();
			}
		);
	} );
} );
