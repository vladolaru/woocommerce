describe( 'WooPayments frontend Tracks queue', () => {
	const loadScript = () => {
		jest.isolateModules( () => {
			require( '../woopayments-frontend-tracks' );
		} );
	};

	let originalFetch;

	beforeEach( () => {
		originalFetch = window.fetch;
		window.fetch = jest.fn().mockResolvedValue( {} );
		window.wc_woopayments_frontend_tracks_params = {
			tracksUrl: 'https://example.test/wp-json/wc/v3/payments/tracks',
			restNonce: 'rest-nonce',
			nonce: 'tracks-nonce',
			events: [
				{
					event: 'product_page_view',
					properties: {
						theme_type: 'short_code',
						record_event_data: {
							is_admin_event: false,
							track_on_all_stores: true,
						},
					},
				},
				{ event: 'pay_for_order_page_view', properties: {} },
			],
		};
	} );

	afterEach( () => {
		// Restore rather than delete: other suites' setup (msw) owns fetch.
		window.fetch = originalFetch;
		delete window.wc_woopayments_frontend_tracks_params;
	} );

	// The client 11.1.0 frontend-tracks body, posted to the shopper Tracks REST route with the REST nonce header.
	it( 'posts each queued event once to the shopper Tracks route, with the client 11.1.0 body', () => {
		loadScript();
		loadScript();

		expect( window.fetch ).toHaveBeenCalledTimes( 2 );
		const sent = window.fetch.mock.calls.map( ( [ url, init ] ) => [
			url,
			init.method,
			init.headers,
			Object.fromEntries( init.body.entries() ),
		] );
		expect( sent ).toEqual( [
			[
				'https://example.test/wp-json/wc/v3/payments/tracks',
				'POST',
				{ 'X-WP-Nonce': 'rest-nonce' },
				{
					tracksNonce: 'tracks-nonce',
					tracksEventName: 'product_page_view',
					tracksEventProp:
						'{"theme_type":"short_code","record_event_data":{"is_admin_event":false,"track_on_all_stores":true}}',
				},
			],
			[
				'https://example.test/wp-json/wc/v3/payments/tracks',
				'POST',
				{ 'X-WP-Nonce': 'rest-nonce' },
				{
					tracksNonce: 'tracks-nonce',
					tracksEventName: 'pay_for_order_page_view',
					tracksEventProp: '{}',
				},
			],
		] );
		expect( window.wc_woopayments_frontend_tracks_params.events ).toEqual(
			[]
		);
	} );

	it( 'sends no REST nonce header when the page has none', () => {
		delete window.wc_woopayments_frontend_tracks_params.restNonce;

		loadScript();

		expect( window.fetch.mock.calls[ 0 ][ 1 ].headers ).toEqual( {} );
	} );

	it( 'sends nothing when the queue is empty', () => {
		window.wc_woopayments_frontend_tracks_params.events = [];

		loadScript();

		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	describe( 'cart Proceed to checkout clicks', () => {
		const listeners = [];
		let originalAddEventListener;

		const sentEvents = () =>
			window.fetch.mock.calls.map( ( [ , init ] ) => {
				const body = Object.fromEntries( init.body.entries() );
				return [ body.tracksEventName, body.tracksEventProp ];
			} );

		const click = ( selector ) => {
			window.fetch.mockClear();
			document.querySelector( selector ).click();
			return sentEvents();
		};

		beforeEach( () => {
			// Track the listeners the script adds so each test removes its own.
			originalAddEventListener = document.addEventListener;
			document.addEventListener = function ( ...args ) {
				listeners.push( args );
				return originalAddEventListener.apply( this, args );
			};
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
			document.addEventListener = originalAddEventListener;
			listeners
				.splice( 0 )
				.forEach( ( args ) =>
					document.removeEventListener( ...args )
				);
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
