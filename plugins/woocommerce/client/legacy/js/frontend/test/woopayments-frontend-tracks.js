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
			ajaxUrl: '/wp-admin/admin-ajax.php',
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

	it( 'posts each queued event once to the platform_tracks action, like client 11.1.0 frontend-tracks', () => {
		loadScript();
		loadScript();

		expect( window.fetch ).toHaveBeenCalledTimes( 2 );
		const sent = window.fetch.mock.calls.map( ( [ url, init ] ) => [
			url,
			init.method,
			Object.fromEntries( init.body.entries() ),
		] );
		expect( sent ).toEqual( [
			[
				'/wp-admin/admin-ajax.php',
				'POST',
				{
					tracksNonce: 'tracks-nonce',
					action: 'platform_tracks',
					tracksEventName: 'product_page_view',
					tracksEventProp:
						'{"theme_type":"short_code","record_event_data":{"is_admin_event":false,"track_on_all_stores":true}}',
				},
			],
			[
				'/wp-admin/admin-ajax.php',
				'POST',
				{
					tracksNonce: 'tracks-nonce',
					action: 'platform_tracks',
					tracksEventName: 'pay_for_order_page_view',
					tracksEventProp: '{}',
				},
			],
		] );
		expect( window.wc_woopayments_frontend_tracks_params.events ).toEqual(
			[]
		);
	} );

	it( 'sends nothing when the queue is empty', () => {
		window.wc_woopayments_frontend_tracks_params.events = [];

		loadScript();

		expect( window.fetch ).not.toHaveBeenCalled();
	} );
} );
