/**
 * Internal dependencies
 */
import { getIdentity, recordEvent } from '../tracks';

const trackingArgs = {
	isShopperTrackingEnabled: true,
	tracksUrl: 'https://example.test/wp-json/wc/v3/payments/tracks',
	platformTrackerNonce: 'tracks-nonce',
	tracksRestNonce: 'rest-nonce',
};

describe( 'tracks.recordEvent', () => {
	const originalFetch = window.fetch;

	beforeEach( () => {
		window.fetch = jest.fn().mockResolvedValue( {} );
	} );

	afterEach( () => {
		window.fetch = originalFetch;
	} );

	it( 'posts the event to the shopper Tracks route with the REST nonce', () => {
		recordEvent( trackingArgs, 'checkout_place_order_button_click', {
			source: 'checkout',
		} );

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( trackingArgs.tracksUrl );
		expect( options ).toMatchObject( {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': 'rest-nonce' },
		} );
		expect( options.body.get( 'tracksNonce' ) ).toBe( 'tracks-nonce' );
		expect( options.body.get( 'tracksEventName' ) ).toBe(
			'checkout_place_order_button_click'
		);
		expect( options.body.get( 'tracksEventProp' ) ).toBe(
			'{"source":"checkout"}'
		);
	} );

	// A classic page's wp_localize_script() config sends the store's false as ''.
	it.each( [ false, '', undefined ] )(
		'sends no event when shopper tracking is %p',
		( isShopperTrackingEnabled ) => {
			recordEvent(
				{ ...trackingArgs, isShopperTrackingEnabled },
				'checkout_place_order_button_click'
			);

			expect( window.fetch ).not.toHaveBeenCalled();
		}
	);

	it( 'sends no event without the route or the nonce', () => {
		recordEvent( { ...trackingArgs, tracksUrl: '' }, 'event' );
		recordEvent( { ...trackingArgs, platformTrackerNonce: '' }, 'event' );

		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'sends no X-WP-Nonce header when the REST nonce is empty', () => {
		recordEvent( { ...trackingArgs, tracksRestNonce: '' }, 'event' );

		expect( window.fetch.mock.calls[ 0 ][ 1 ].headers ).toEqual( {} );
	} );
} );

describe( 'tracks.getIdentity', () => {
	const originalFetch = window.fetch;

	afterEach( () => {
		window.fetch = originalFetch;
		document.cookie = 'tk_ai=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
	} );

	it( 'returns the anonymous identity from the tk_ai cookie without a request', async () => {
		window.fetch = jest.fn();
		document.cookie = 'tk_ai=anon-id';

		await expect(
			getIdentity( {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				platformTrackerNonce: 'n',
			} )
		).resolves.toBe( JSON.stringify( { _ut: 'anon', _ui: 'anon-id' } ) );
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'asks the get_identity bridge and resolves undefined on a failure', async () => {
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: () =>
				Promise.resolve( {
					success: true,
					data: { _ut: 'wpcom:user_id', _ui: '42' },
				} ),
		} );

		await expect(
			getIdentity( {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				platformTrackerNonce: 'n',
			} )
		).resolves.toBe(
			JSON.stringify( { _ut: 'wpcom:user_id', _ui: '42' } )
		);
		expect( window.fetch.mock.calls[ 0 ][ 1 ].body.get( 'action' ) ).toBe(
			'get_identity'
		);

		window.fetch = jest.fn().mockRejectedValue( new Error( 'offline' ) );
		await expect(
			getIdentity( {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				platformTrackerNonce: 'n',
			} )
		).resolves.toBeUndefined();
	} );
} );
