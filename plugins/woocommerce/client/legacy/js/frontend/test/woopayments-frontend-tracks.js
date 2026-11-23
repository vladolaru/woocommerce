describe( 'WooPayments frontend Tracks queue', () => {
	const loadScript = () => {
		jest.isolateModules( () => {
			require( '../woopayments-frontend-tracks' );
		} );
	};

	let originalFetch;
	let listeners;

	beforeEach( () => {
		listeners = [ window, document ].map( ( target ) => [
			target, jest.spyOn( target, 'addEventListener' ),
		] );
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		originalFetch = window.fetch;
		window.fetch = jest.fn().mockResolvedValue( {} );
		window.wc_woopayments_frontend_tracks_params = {
			tracksUrl: 'https://example.test/wp-json/wc/v3/payments/tracks',
			restNonce: 'rest-nonce',
			nonce: 'tracks-nonce',
			allowedEvents: [
				'product_page_view', 'pay_for_order_page_view',
				'proceed_to_checkout_button_click', 'checkout_place_order_button_click', 'gpay_button_load', 'woopay_button_load',
			],
			events: [
				{
					event: 'product_page_view',
					properties: {
						theme_type: 'short_code',
					},
				},
				{ event: 'pay_for_order_page_view', properties: {} },
			],
		};
	} );

	afterEach( () => {
		listeners.forEach( ( [ target, spy ] ) => {
			spy.mock.calls.forEach( ( args ) => target.removeEventListener( ...args ) );
			spy.mockRestore();
		} );
		jest.useRealTimers();
		delete window.wcWooPaymentsTracks;
		delete window.navigator.sendBeacon;
		// Restore rather than delete: other suites' setup (msw) owns fetch.
		window.fetch = originalFetch;
		delete window.wc_woopayments_frontend_tracks_params;
	} );

	const sender = require( '../../../../blocks/assets/js/extensions/payment-methods/woopayments/common/tracks' );
	const add = ( name = 'gpay_button_load' ) =>
		sender.recordEvent( {
			isShopperTrackingEnabled: true,
			tracksUrl: '/tracks',
			platformTrackerNonce: 'tracks-nonce',
		}, name );
	const sizes = () => window.fetch.mock.calls.map( ( [ , options ] ) =>
		JSON.parse( options.body.get( 'tracksEvents' ) ).length );
	const hide = () => window.dispatchEvent( new Event( 'pagehide' ) );

	it( 'coalesces footer and checkout events after two idle seconds', () => {
		loadScript();
		jest.advanceTimersByTime( 1500 );
		add();
		jest.advanceTimersByTime( 1999 );
		expect( window.fetch ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 1 );
		expect( sizes() ).toEqual( [ 3 ] );
	} );

	it( 'flushes at five seconds while events keep arriving', () => {
		loadScript();
		for ( let i = 0; i < 4; i++ ) {
			jest.advanceTimersByTime( 1000 );
			add();
		}
		jest.advanceTimersByTime( 999 );
		expect( window.fetch ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 1 );
		expect( sizes() ).toEqual( [ 6 ] );
	} );

	it( 'flushes at twenty and sends the remainder on Place order', () => {
		loadScript();
		for ( let i = 0; i < 18; i++ ) {
			add();
		}
		expect( sizes() ).toEqual( [ 20 ] );
		add();
		add( 'checkout_place_order_button_click' );
		expect( sizes() ).toEqual( [ 20, 2 ] );
	} );

	it( 'flushes the eligible events on Place order with WooPay off', () => {
		window.wc_woopayments_frontend_tracks_params.allowedEvents = [ 'product_page_view', 'pay_for_order_page_view', 'gpay_button_load' ];
		loadScript();
		add( 'woopay_button_load' );
		add();
		add( 'checkout_place_order_button_click' );
		expect( sizes() ).toEqual( [ 3 ] );
	} );

	it.each( [ true, false ] )( 'uses one authenticated hide delivery when beacon accepts %p', async ( accepted ) => {
		window.navigator.sendBeacon = jest.fn().mockReturnValue( accepted );
		window.fetch.mockRejectedValueOnce( new Error( 'offline' ) );
		loadScript();
		hide();
		hide();
		expect( window.navigator.sendBeacon ).toHaveBeenCalledTimes( 1 );
		const [ url, body ] = window.navigator.sendBeacon.mock.calls[ 0 ];
		expect( body.get( 'tracksNonce' ) ).toBe( 'tracks-nonce' );
		expect( url ).toBe( window.wc_woopayments_frontend_tracks_params.tracksUrl );
		expect( body.get( '_wpnonce' ) ).toBe( 'rest-nonce' );
		expect( JSON.parse( body.get( 'tracksEvents' ) ) ).toHaveLength( 2 );
		expect( sizes() ).toEqual( accepted ? [] : [ 2 ] );
		if ( ! accepted ) {
			expect( window.fetch.mock.calls[ 0 ][ 1 ].keepalive ).toBe( true );
			await Promise.resolve();
		}
		add();
		jest.advanceTimersByTime( 2000 );
		expect( sizes() ).toEqual( accepted ? [ 1 ] : [ 2, 1 ] );
	} );

	it( 'flushes pending events when another checkout method handles Place order', () => {
		loadScript();
		document.body.innerHTML = '<form class="checkout"><button type="button" id="place_order">Place order</button></form>';
		try {
			document.getElementById( 'place_order' ).click();
			expect( sizes() ).toEqual( [ 2 ] );
		} finally {
			document.body.innerHTML = '';
		}
	} );

	it( 'adopts earlier checkout entries and flushes all three event groups when hidden', () => {
		add( 'woopay_button_load' );
		add();
		loadScript();
		expect( window.fetch ).not.toHaveBeenCalled();
		const state = jest.spyOn( document, 'visibilityState', 'get' ).mockReturnValue( 'hidden' );
		try {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
			expect( sizes() ).toEqual( [ 4 ] );
			hide();
			expect( sizes() ).toEqual( [ 4 ] );
			expect( JSON.parse( window.fetch.mock.calls[ 0 ][ 1 ].body.get( 'tracksEvents' ) ).map( ( item ) => item.event ) ).toEqual( [
				'woopay_button_load', 'gpay_button_load', 'product_page_view', 'pay_for_order_page_view',
			] );
		} finally {
			state.mockRestore();
		}
	} );

	it( 'posts the footer events once as one authenticated batch', () => {
		const { events, tracksUrl } = window.wc_woopayments_frontend_tracks_params;
		loadScript();
		loadScript();
		jest.advanceTimersByTime( 2000 );
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( tracksUrl );
		expect( options ).toMatchObject( {
			method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': 'rest-nonce' },
		} );
		expect( options.body.get( 'tracksNonce' ) ).toBe( 'tracks-nonce' );
		expect( JSON.parse( options.body.get( 'tracksEvents' ) ) ).toEqual( events );
	} );

	it( 'sends no REST nonce header when the page has none', () => {
		delete window.wc_woopayments_frontend_tracks_params.restNonce;

		loadScript();

		jest.advanceTimersByTime( 2000 );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].headers ).toEqual( {} );
	} );

	it( 'sends nothing when the queue is empty', () => {
		window.wc_woopayments_frontend_tracks_params.events = [];

		loadScript();
		hide();
		jest.runOnlyPendingTimers();

		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	describe( 'cart Proceed to checkout clicks', () => {
		const sentEvents = () =>
			window.fetch.mock.calls.flatMap( ( [ , init ] ) =>
				JSON.parse( init.body.get( 'tracksEvents' ) ).map( ( item ) =>
					[ item.event, JSON.stringify( item.properties ) ]
				)
			);

		const click = ( selector ) => {
			window.fetch.mockClear();
			document.querySelector( selector ).click();
			jest.advanceTimersByTime( 2000 );
			return sentEvents();
		};

		beforeEach( () => {
			window.wc_woopayments_frontend_tracks_params.events = [];
			document.body.innerHTML =
				'<div class="wp-block-woocommerce-proceed-to-checkout-block">' +
				'<a class="wc-block-cart__submit-button" href="#checkout"><span>Proceed to Checkout</span></a>' +
				'</div>' +
				'<div class="wc-proceed-to-checkout"><a class="checkout-button" href="#checkout">Proceed to checkout</a></div>' +
				'<a class="wc-block-mini-cart__footer-checkout" href="#checkout">Go to checkout</a>' +
				'<a class="other-link" href="#elsewhere">Elsewhere</a>';
		} );

		afterEach( () => {
			document.body.innerHTML = '';
			document.cookie = 'skip_woopay=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
		} );

		it( 'records a Blocks, classic or mini-cart Proceed to checkout click like client 11.1.0 cart/index.js', () => {
			window.wc_woopayments_frontend_tracks_params.proceedToCheckout = {
				woopayDirectCheckout: false,
			};
			loadScript();

			const expected = [
				[
					'proceed_to_checkout_button_click',
					'{"woopay_direct_checkout":false}',
				],
			];
			expect(
				click( '.wc-block-cart__submit-button span' )
			).toEqual( expected );
			expect( click( '.checkout-button' ) ).toEqual( expected );
			expect(
				click( '.wc-block-mini-cart__footer-checkout' )
			).toEqual( expected );
			expect( click( '.other-link' ) ).toEqual( [] );
		} );

		it( 'reports direct checkout unless the shopper skipped WooPay', () => {
			window.wc_woopayments_frontend_tracks_params.proceedToCheckout = {
				woopayDirectCheckout: true,
			};
			loadScript();

			expect( click( '.checkout-button' ) ).toEqual( [
				[
					'proceed_to_checkout_button_click',
					'{"woopay_direct_checkout":true}',
				],
			] );

			document.cookie = 'skip_woopay=1';
			expect( click( '.checkout-button' ) ).toEqual( [
				[
					'proceed_to_checkout_button_click',
					'{"woopay_direct_checkout":false}',
				],
			] );
		} );

		it( 'records nothing when the cart did not ask for click tracking', () => {
			loadScript();

			expect( click( '.checkout-button' ) ).toEqual( [] );
		} );
	} );
} );
