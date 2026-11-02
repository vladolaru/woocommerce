/**
 * @jest-environment jest-fixed-jsdom
 */

const { server, http, HttpResponse } = require( './msw-setup' );

describe( 'WooPayments BNPL payment method messaging', () => {
	let eventHandlers;
	let createElement;
	let mountElement;
	let unmountElement;
	let updateElement;
	let stripeElements;
	let ajaxRequests;
	let ajaxResponses;

	const productMarkup =
		'<p class="price">$50.00</p>' +
		'<div id="payment-method-message"></div>' +
		'<div class="quantity"><input type="number" value="1" /></div>';

	async function flushPromises() {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

	// Waits, on zero-delay ticks rather than a fixed delay, until the page reaches the state a test expects after an
	// AJAX round trip. Fake timers have to be advanced to tick, so tests that fake them say so.
	async function waitUntil( isDone, { fakeTimers = false } = {} ) {
		for ( let tick = 0; tick < 50 && ! isDone(); tick++ ) {
			if ( fakeTimers ) {
				await jest.advanceTimersByTimeAsync( 0 );
			} else {
				await flushPromises();
			}
		}
	}

	function getMessageContainer() {
		return document.getElementById( 'payment-method-message' );
	}

	function trigger( selector, event, ...args ) {
		return eventHandlers[ selector ][ event ]( ...args );
	}

	// Minimal stand-in for the jQuery calls the script makes (api.jquery.com): $( fn ) runs fn on DOM ready; .on() binds
	// a handler only when the selector matches, recorded per selector so a test can fire it; .val() reads the first
	// match; .before() inserts HTML before each match; .hide() and .show() toggle inline display; .slideUp() and
	// .slideDown() end in the same states as with jQuery.fx.off, which jumps animations to their final state.
	function createJQueryStub() {
		function wrap( key, elements ) {
			const each = ( callback ) => {
				elements.forEach( callback );
				return collection;
			};
			const hide = () =>
				each( ( element ) => {
					element.style.display = 'none';
				} );
			const show = () =>
				each( ( element ) => {
					element.style.display = '';
				} );
			const collection = {
				on: ( event, handler ) => {
					if ( elements.length ) {
						eventHandlers[ key ] = Object.assign(
							{},
							eventHandlers[ key ],
							{ [ event ]: handler }
						);
					}
					return collection;
				},
				val: () => ( elements.length ? elements[ 0 ].value : undefined ),
				before: ( html ) =>
					each( ( element ) =>
						element.insertAdjacentHTML( 'beforebegin', html )
					),
				hide,
				show,
				slideUp: hide,
				slideDown: show,
				addClass: ( className ) =>
					each( ( element ) => element.classList.add( className ) ),
				remove: () => each( ( element ) => element.remove() ),
			};
			return collection;
		}

		function $( target ) {
			if ( typeof target === 'function' ) {
				target( $ );
				return wrap( 'ready', [] );
			}
			if ( target === document.body ) {
				return wrap( 'body', [ document.body ] );
			}
			return wrap(
				target,
				Array.from( document.querySelectorAll( target ) )
			);
		}

		return $;
	}

	function setMessagingConfig( overrides = {} ) {
		window.wcpayStripeSiteMessaging = Object.assign( {
			accountId: 'acct_test',
			cartTotal: 0,
			country: 'US',
			currencyCode: 'USD',
			isCart: false,
			isCartBlock: false,
			locale: 'en',
			nonce: {
				get_cart_total: 'cart-nonce',
				is_bnpl_available: 'bnpl-nonce',
			},
			paymentMethods: [ 'affirm', 'klarna' ],
			productId: 'base_product',
			productVariations: {
				base_product: {
					amount: 5000,
					currency: 'USD',
				},
			},
			publishableKey: 'pk_test',
			shouldInitializePMME: true,
			shouldShowPMME: true,
			stylesCacheVersion: '1',
			wcAjaxUrl: 'https://example.test/?wc-ajax=%%endpoint%%',
		}, overrides );
	}

	beforeEach( () => {
		jest.resetModules();
		eventHandlers = {};
		document.body.innerHTML = productMarkup;
		window.jQuery = createJQueryStub();
		global.jQuery = window.jQuery;
		ajaxRequests = [];
		// Response shapes from client 11.1.0 includes/class-wc-payments.php:1917-1953: wcpay_get_cart_total answers
		// wp_send_json( [ 'total' => cents ] ), wcpay_check_bnpl_availability answers wp_send_json_success(
		// [ 'is_available' => bool ] ). A function entry builds a raw response instead.
		ajaxResponses = {
			wcpay_check_bnpl_availability: {
				success: true,
				data: { is_available: true },
			},
			wcpay_get_cart_total: { total: 0 },
		};
		server.use(
			http.post(
				'https://example.test/',
				async ( { request } ) => {
					const endpoint = new URL( request.url ).searchParams.get(
						'wc-ajax'
					);
					ajaxRequests.push( {
						endpoint,
						formData: await request.formData(),
						method: request.method,
						url: request.url,
					} );
					const response = ajaxResponses[ endpoint ];
					return typeof response === 'function'
						? response()
						: HttpResponse.json( response );
				}
			)
		);
		mountElement = jest.fn();
		unmountElement = jest.fn();
		updateElement = jest.fn();
		// Stripe.js elements.create( 'paymentMethodMessaging', options ) returns an element with mount(), unmount(), on()
		// and update() (https://docs.stripe.com/js/element/mount, https://docs.stripe.com/js/element/unmount)
		// (https://docs.stripe.com/js/elements_object/create_payment_method_messaging_element; client 11.1.0
		// client/product-details/bnpl-site-messaging/index.js:84-94 creates and mounts it).
		createElement = jest.fn( () => ( {
			mount: mountElement,
			unmount: unmountElement,
			on: jest.fn(),
			update: updateElement,
		} ) );
		stripeElements = jest.fn( () => ( {
			create: createElement,
		} ) );
		// Stripe.js global: Stripe( publishableKey, options ) returns a client whose elements( options ) builds elements
		// (https://docs.stripe.com/js/initializing, https://docs.stripe.com/js/elements_object/create).
		window.Stripe = jest.fn( () => ( {
			elements: stripeElements,
		} ) );
		require( '../utils/woopayments-appearance' );
		setMessagingConfig();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete global.jQuery;
		delete window.jQuery;
		delete window.Stripe;
		delete window.wc.woopayments.appearance;
		delete window.wcpayStripeSiteMessaging;
	} );

	test( 'mounts the product-page Stripe payment method messaging element', async () => {
		require( '../woopayments-payment-method-messaging' );
		// Stripe.js is already loaded, so the element mounts in the ready callback, before any other event.
		expect( mountElement ).toHaveBeenCalled();
		await flushPromises();

		expect( window.Stripe ).toHaveBeenCalledWith( 'pk_test', {
			locale: 'en',
			stripeAccount: 'acct_test',
		} );
		expect( createElement ).toHaveBeenCalledWith(
			'paymentMethodMessaging',
			{
				amount: 5000,
				countryCode: 'US',
				currency: 'USD',
				paymentMethodTypes: [ 'affirm', 'klarna' ],
			}
		);
		expect( mountElement ).toHaveBeenCalledWith(
			'#payment-method-message'
		);
	} );

	// Client 11.2.0 product-details/bnpl-site-messaging/index.js:66-73: with nothing cached, the appearance is computed
	// from the page with the location's selectors (checkout/upe-styles/index.js:91-133), announced and cached. The page
	// has a classic cart quantity first and a product form quantity after it, each in its own colour, so each location
	// shows which one it read.
	describe( 'appearance on a cache miss', () => {
		const pageMarkup =
			'<style>' +
			'body { background-color: rgb(20, 20, 20); }' +
			'#cart-quantity { color: rgb(200, 10, 10); font-size: 13px; }' +
			'#product-quantity { color: rgb(10, 200, 10); font-size: 17px; }' +
			'</style>' +
			'<div class="cart"><div class="quantity" id="cart-quantity"></div></div>' +
			'<div class="product"><form class="cart"><div class="quantity" id="product-quantity"></div></form></div>' +
			'<div class="cart_totals"><div id="payment-method-message"></div></div>';

		// Earlier tests compute and cache an appearance for the default markup.
		beforeEach( () => window.localStorage.clear() );

		test.each( [
			[ 'bnpl_product_page', false, 'rgb(10, 200, 10)', '17px' ],
			[ 'bnpl_classic_cart', true, 'rgb(200, 10, 10)', '13px' ],
		] )(
			'computes, announces and caches the %s appearance',
			async ( location, isCart, colorText, fontSizeBase ) => {
				const announced = jest.fn();
				document.addEventListener( 'wcpay_elements_appearance', announced );
				document.body.innerHTML = pageMarkup;
				setMessagingConfig( { isCart, cartTotal: 2000 } );

				try {
					require( '../woopayments-payment-method-messaging' );
					await flushPromises();
				} finally {
					document.removeEventListener(
						'wcpay_elements_appearance',
						announced
					);
				}

				const appearance = stripeElements.mock.calls[ 0 ][ 0 ].appearance;
				expect( appearance ).toMatchObject( {
					theme: 'night',
					variables: {
						colorBackground: 'rgb(20, 20, 20)',
						colorText,
						fontSizeBase,
					},
					rules: { '.Text': { color: colorText } },
				} );
				expect( announced.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
					appearance,
					elementsLocation: location,
				} );
				expect(
					JSON.parse(
						window.localStorage.getItem( 'wcpay_appearance_' + location )
					)
				).toEqual( { version: '1', appearance } );
			}
		);

		test( 'uses the cached appearance and computes nothing', async () => {
			const cached = {
				variables: { colorText: 'rgb(1, 2, 3)' },
				rules: { '.Input': { color: 'rgb(1, 2, 3)' } },
			};
			window.localStorage.setItem(
				'wcpay_appearance_bnpl_product_page',
				JSON.stringify( { version: '1', appearance: cached } )
			);
			const announced = jest.fn();
			document.addEventListener( 'wcpay_elements_appearance', announced );
			document.body.innerHTML = pageMarkup;

			try {
				require( '../woopayments-payment-method-messaging' );
				await flushPromises();
			} finally {
				document.removeEventListener(
					'wcpay_elements_appearance',
					announced
				);
				window.localStorage.clear();
			}

			expect( stripeElements.mock.calls[ 0 ][ 0 ].appearance ).toEqual(
				cached
			);
			expect( announced ).not.toHaveBeenCalled();
		} );
	} );

	test( 'mounts the messaging element once Stripe.js loads after the messaging script', async () => {
		jest.useFakeTimers();
		const loadedStripe = window.Stripe;
		delete window.Stripe;

		require( '../woopayments-payment-method-messaging' );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mountElement ).not.toHaveBeenCalled();

		window.Stripe = loadedStripe;
		await jest.advanceTimersByTimeAsync( 100 );

		expect( mountElement ).toHaveBeenCalledWith(
			'#payment-method-message'
		);
	} );

	test( 'mounts nothing and leaves the message in place when Stripe.js never loads', async () => {
		jest.useFakeTimers();
		delete window.Stripe;

		require( '../woopayments-payment-method-messaging' );
		// Client 11.1.0 client/checkout/api/index.js:57-71 gives up after 600 seconds.
		jest.advanceTimersByTime( 601 * 1000 );
		await jest.advanceTimersByTimeAsync( 0 );

		expect( jest.getTimerCount() ).toBe( 0 );
		expect( mountElement ).not.toHaveBeenCalled();
		expect( eventHandlers[ '.quantity input[type=number]' ] ).toBeUndefined();
		expect( getMessageContainer().style.display ).toBe( '' );
	} );

	test( 'hides the message and never loads Stripe when the store turns messaging off', async () => {
		setMessagingConfig( { shouldInitializePMME: false } );

		require( '../woopayments-payment-method-messaging' );
		await flushPromises();

		expect( getMessageContainer().style.display ).toBe( 'none' );
		expect( window.Stripe ).not.toHaveBeenCalled();
		expect( mountElement ).not.toHaveBeenCalled();
	} );

	test( 'updates product messaging and checks availability when quantity changes', async () => {
		require( '../woopayments-payment-method-messaging' );
		await flushPromises();

		trigger( '.quantity input[type=number]', 'change', {
			target: { value: '2' },
		} );
		await waitUntil( () => ajaxRequests.length === 1 );

		expect( updateElement ).toHaveBeenCalledWith( {
			amount: 10000,
			currency: 'USD',
		} );
		expect( ajaxRequests ).toHaveLength( 1 );
		const [ availabilityRequest ] = ajaxRequests;
		expect( availabilityRequest.url ).toBe(
			'https://example.test/?wc-ajax=wcpay_check_bnpl_availability'
		);
		expect( availabilityRequest.method ).toBe( 'POST' );
		const { formData } = availabilityRequest;
		expect( formData.get( 'security' ) ).toBe( 'bnpl-nonce' );
		expect( formData.get( 'price' ) ).toBe( '10000' );
		expect( formData.get( 'currency' ) ).toBe( 'USD' );
		expect( formData.get( 'country' ) ).toBe( 'US' );
	} );

	test.each( [
		[
			'hides the message when BNPL is unavailable',
			true,
			{ success: true, data: { is_available: false } },
			'none',
		],
		[
			// check_ajax_referer() answers a stale nonce with wp_die( '-1' ) and status 403; -1 parses as JSON.
			'hides the message when the availability check is refused',
			true,
			() => new HttpResponse( '-1', { status: 403 } ),
			'none',
		],
		[
			'shows a hidden message when BNPL becomes available',
			false,
			{ success: true, data: { is_available: true } },
			'',
		],
	] )( '%s after a quantity change', async ( name, shouldShowPMME, response, display ) => {
		setMessagingConfig( { shouldShowPMME } );
		ajaxResponses.wcpay_check_bnpl_availability = response;
		require( '../woopayments-payment-method-messaging' );
		await flushPromises();
		// Client 11.1.0 bnpl-site-messaging/index.js:45-48 hides the product message the server would not show.
		expect( getMessageContainer().style.display ).toBe(
			shouldShowPMME ? '' : 'none'
		);

		trigger( '.quantity input[type=number]', 'change', {
			target: { value: '3' },
		} );
		await waitUntil( () => getMessageContainer().style.display === display );

		expect( ajaxRequests[ 0 ].formData.get( 'price' ) ).toBe( '15000' );
		expect( getMessageContainer().style.display ).toBe( display );
	} );

	describe( 'variable product', () => {
		beforeEach( () => {
			// WooCommerce variable add-to-cart form (templates/single-product/add-to-cart/variable.php): the attribute
			// selects sit in table.variations, a.reset_variations clears them, and .single_variation_wrap carries the
			// variation_id input and receives the show_variation event from add-to-cart-variation.js.
			document.body.innerHTML =
				'<p class="price">$50.00 - $80.00</p>' +
				'<div id="payment-method-message"></div>' +
				'<table class="variations"><tr><td><select name="attribute_size">' +
				'<option value="">Choose an option</option><option value="large">Large</option>' +
				'</select></td></tr></table>' +
				'<a class="reset_variations" href="#">Clear</a>' +
				'<div class="single_variation_wrap">' +
				'<div class="quantity"><input type="number" value="2" /></div>' +
				'<input type="hidden" name="variation_id" value="" />' +
				'</div>';
			setMessagingConfig( {
				productVariations: {
					base_product: { amount: 5000, currency: 'USD' },
					101: { amount: 7000, currency: 'USD' },
				},
			} );
		} );

		test( 'shows the chosen variation price times the quantity', async () => {
			require( '../woopayments-payment-method-messaging' );
			await flushPromises();

			// add-to-cart-variation.js triggers show_variation with ( event, variation ).
			trigger( '.single_variation_wrap', 'show_variation', {}, {
				variation_id: 101,
			} );
			expect( updateElement ).toHaveBeenCalledTimes( 1 );
			expect( updateElement ).toHaveBeenCalledWith( {
				amount: 14000,
				currency: 'USD',
			} );

			trigger( '.single_variation_wrap', 'show_variation', {}, {
				variation_id: 999,
			} );
			expect( updateElement ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'uses the selected variation price when the quantity changes', async () => {
			require( '../woopayments-payment-method-messaging' );
			await flushPromises();
			document.querySelector( 'input[name="variation_id"]' ).value = '101';

			trigger( '.quantity input[type=number]', 'change', {
				target: { value: '3' },
			} );
			await waitUntil( () => ajaxRequests.length === 1 );

			expect( updateElement ).toHaveBeenCalledWith( {
				amount: 21000,
				currency: 'USD',
			} );
			expect( ajaxRequests[ 0 ].formData.get( 'price' ) ).toBe( '21000' );
		} );

		test( 'returns to the base price when the shopper clears the variation', async () => {
			require( '../woopayments-payment-method-messaging' );
			await flushPromises();
			trigger( '.single_variation_wrap', 'show_variation', {}, {
				variation_id: 101,
			} );
			updateElement.mockClear();

			const select = document.querySelector( 'select' );
			select.value = 'large';
			trigger( '.variations', 'change', { target: select } );
			expect( updateElement ).not.toHaveBeenCalled();

			select.value = '';
			trigger( '.variations', 'change', { target: select } );
			expect( updateElement ).toHaveBeenLastCalledWith( {
				amount: 10000,
				currency: 'USD',
			} );

			updateElement.mockClear();
			trigger( '.reset_variations', 'click', {} );
			expect( updateElement ).toHaveBeenCalledWith( {
				amount: 10000,
				currency: 'USD',
			} );
		} );
	} );

	// Classic cart totals (templates/cart/cart-totals.php): div.cart_totals holds table.shop_table. Core replaces the
	// whole div.cart_totals on Update cart (cart.js update_cart_totals_div()), message container included.
	const cartTotalsMarkup =
		'<div class="cart_totals"><table class="shop_table"></table>' +
		'<div id="payment-method-message"></div></div>';

	const replaceCartTotals = () => {
		document.querySelector( '.cart_totals' ).outerHTML = cartTotalsMarkup;
	};

	// Client 11.2.0 builds a new Stripe instance and element on every Update cart (product-details/index.js:174-190,
	// bnpl-site-messaging/index.js:51-87); the one element moves into the new totals instead, with the new amount.
	test( 'moves the one classic cart message into the new totals on each cart update', async () => {
		document.body.innerHTML = cartTotalsMarkup;
		setMessagingConfig( { isCart: true, cartTotal: 2000 } );
		jest.useFakeTimers();

		require( '../woopayments-payment-method-messaging' );
		expect( createElement ).toHaveBeenLastCalledWith(
			'paymentMethodMessaging',
			expect.objectContaining( { amount: 2000 } )
		);

		for ( const total of [ 4500, 6000 ] ) {
			ajaxResponses.wcpay_get_cart_total = { total };
			replaceCartTotals();
			trigger( 'body', 'updated_cart_totals' );
			const container = getMessageContainer();
			expect(
				container.previousElementSibling.classList.contains(
					'pmme-loading'
				)
			).toBe( true );
			expect( container.style.display ).toBe( 'none' );

			await waitUntil(
				() =>
					updateElement.mock.calls.some(
						( [ options ] ) => options.amount === total
					),
				{ fakeTimers: true }
			);
			// The new element gets a second to render before the placeholder makes way.
			expect( container.style.display ).toBe( 'none' );
			await jest.advanceTimersByTimeAsync( 1000 );

			expect( document.querySelector( '.pmme-loading' ) ).toBeNull();
			expect( container.style.display ).toBe( '' );
			expect( container.classList.contains( 'pmme-updated' ) ).toBe(
				true
			);
		}

		expect( window.Stripe ).toHaveBeenCalledTimes( 1 );
		expect( createElement ).toHaveBeenCalledTimes( 1 );
		expect( updateElement ).toHaveBeenLastCalledWith( {
			amount: 6000,
			currency: 'USD',
		} );
		expect( unmountElement ).toHaveBeenCalledTimes( 2 );
		expect( mountElement ).toHaveBeenCalledTimes( 3 );
		expect( mountElement ).toHaveBeenLastCalledWith(
			'#payment-method-message'
		);
		expect( ajaxRequests.map( ( { endpoint } ) => endpoint ) ).toEqual( [
			'wcpay_get_cart_total',
			'wcpay_get_cart_total',
		] );
		expect( ajaxRequests[ 0 ].formData.get( 'security' ) ).toBe(
			'cart-nonce'
		);
	} );

	test( 'clears the loader when the cart total cannot be read', async () => {
		document.body.innerHTML = cartTotalsMarkup;
		setMessagingConfig( { isCart: true, cartTotal: 2000 } );
		// A failed wc-ajax request: a server error page instead of the JSON total.
		ajaxResponses.wcpay_get_cart_total = () =>
			new HttpResponse( 'Internal Server Error', { status: 500 } );

		require( '../woopayments-payment-method-messaging' );
		replaceCartTotals();
		trigger( 'body', 'updated_cart_totals' );
		expect( document.querySelector( '.pmme-loading' ) ).not.toBeNull();

		await waitUntil( () => ! document.querySelector( '.pmme-loading' ) );

		expect( document.querySelector( '.pmme-loading' ) ).toBeNull();
		expect( unmountElement ).not.toHaveBeenCalled();
	} );

	test( 'keeps the one element built on the first cart update when the page loaded without a message container', async () => {
		document.body.innerHTML =
			'<div class="cart_totals"><table class="shop_table"></table></div>';
		setMessagingConfig( { isCart: true, cartTotal: 2000 } );
		jest.useFakeTimers();

		require( '../woopayments-payment-method-messaging' );
		expect( createElement ).not.toHaveBeenCalled();

		// Each update ends in a new element (Stripe called) or an update of the kept one.
		const remounts = () =>
			window.Stripe.mock.calls.length + updateElement.mock.calls.length;
		for ( const [ index, total ] of [ 4500, 6000 ].entries() ) {
			ajaxResponses.wcpay_get_cart_total = { total };
			replaceCartTotals();
			trigger( 'body', 'updated_cart_totals' );
			await waitUntil( () => remounts() === index + 1, {
				fakeTimers: true,
			} );
			await jest.advanceTimersByTimeAsync( 1000 );
		}

		expect( window.Stripe ).toHaveBeenCalledTimes( 1 );
		expect( createElement ).toHaveBeenCalledTimes( 1 );
		expect( createElement ).toHaveBeenCalledWith(
			'paymentMethodMessaging',
			expect.objectContaining( { amount: 4500 } )
		);
		expect( updateElement ).toHaveBeenLastCalledWith( {
			amount: 6000,
			currency: 'USD',
		} );
	} );

	// The space the classic cart message takes is kept on .cart-collaterals for the stylesheet: its height, the Stripe
	// frame's height and the loader margin, as client 11.2.0 measures them (product-details/bnpl-site-messaging/
	// index.js:144-197), written again each time the frame grows.
	describe( 'the classic cart message space', () => {
		const OriginalResizeObserver = window.ResizeObserver;
		let resizeCallbacks;
		let observed;
		let readyHandlers;

		// Stand-in for ResizeObserver (https://developer.mozilla.org/docs/Web/API/ResizeObserver), which jsdom lacks: the
		// test reports a size change to every observer.
		const reportResize = () =>
			resizeCallbacks.forEach( ( callback ) =>
				callback( observed.map( ( target ) => ( { target } ) ) )
			);

		const setSizes = ( messageHeight, frameHeight ) => {
			getMessageContainer().style.height = messageHeight;
			document.querySelector( '.__PrivateStripeElement' ).style.height =
				frameHeight;
		};

		const getPinned = () => {
			const collaterals = document.querySelector( '.cart-collaterals' );

			return [
				collaterals.style.getPropertyValue( '--wc-bnpl-height' ),
				collaterals.style.getPropertyValue(
					'--wc-bnpl-container-height'
				),
				collaterals.style.getPropertyValue( '--wc-bnpl-loader-margin' ),
				getMessageContainer().style.getPropertyValue(
					'--wc-bnpl-margin-bottom'
				),
			];
		};

		beforeEach( () => {
			resizeCallbacks = [];
			observed = [];
			window.ResizeObserver = jest.fn( ( callback ) => {
				resizeCallbacks.push( callback );
				return {
					observe: ( target ) => observed.push( target ),
					disconnect: () => observed.splice( 0 ),
				};
			} );
			// Classic cart (templates/cart/cart.php, cart-totals.php): div.cart-collaterals holds div.cart_totals; Stripe
			// mounts its frame in a div.__PrivateStripeElement inside the message.
			document.body.innerHTML =
				'<div class="cart-collaterals"><div class="cart_totals">' +
				'<table class="shop_table" style="margin-bottom: 16px"></table>' +
				'<div id="payment-method-message">' +
				'<div class="__PrivateStripeElement"></div>' +
				'</div></div></div>';
			setMessagingConfig( { isCart: true, cartTotal: 2000 } );
			readyHandlers = [];
			createElement.mockImplementation( () => ( {
				mount: mountElement,
				on: jest.fn( ( event, handler ) => {
					if ( event === 'ready' ) {
						readyHandlers.push( handler );
					}
				} ),
				update: updateElement,
			} ) );
			jest.useFakeTimers();
			require( '../woopayments-payment-method-messaging' );
		} );

		afterEach( () => {
			window.ResizeObserver = OriginalResizeObserver;
		} );

		const becomeReady = async () => {
			readyHandlers.forEach( ( handler ) => handler() );
			reportResize();
			await jest.advanceTimersByTimeAsync( 2000 );
		};

		test( 'keeps the space of the classic cart message for the next cart update', async () => {
			setSizes( '40px', '52px' );

			await becomeReady();

			expect( getPinned() ).toEqual( [ '56px', '40px', '18px', '-4px' ] );
		} );

		// Below the fold, Stripe's frame is a 9px placeholder until the shopper scrolls it into view, where it grows to
		// its rendered height (measured on a store: 9px, then 26.7px).
		test( 'measures the message again once Stripe sizes its frame below the fold', async () => {
			setSizes( '11px', '9px' );
			await becomeReady();
			expect( getPinned() ).toEqual( [ '27px', '-3px', '18px', '-4px' ] );

			setSizes( '28.7px', '26.7px' );
			reportResize();
			await jest.advanceTimersByTimeAsync( 2000 );

			expect( getPinned() ).toEqual( [
				'44.7px',
				'14.7px',
				'18px',
				'-4px',
			] );
		} );

		// Each Update cart mounts a new message, whose frame replaces the last one.
		test( 'watches only the current Stripe frame after cart updates', async () => {
			setSizes( '40px', '52px' );
			await becomeReady();

			for ( let update = 0; update < 2; update++ ) {
				document
					.querySelector( '.__PrivateStripeElement' )
					.replaceWith(
						Object.assign( document.createElement( 'div' ), {
							className: '__PrivateStripeElement',
						} )
					);
				await becomeReady();
			}

			expect( observed ).toEqual( [
				document.querySelector( '.__PrivateStripeElement' ),
			] );
		} );
	} );

	test( 'does not initialize the classic script for cart block messaging', async () => {
		setMessagingConfig( { isCartBlock: true } );

		require( '../woopayments-payment-method-messaging' );
		await flushPromises();

		expect( window.Stripe ).not.toHaveBeenCalled();
	} );
} );
