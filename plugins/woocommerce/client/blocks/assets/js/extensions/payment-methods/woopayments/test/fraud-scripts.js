/**
 * Internal dependencies
 */
import enqueueFraudScripts from '../fraud-scripts';

describe( 'WooPayments fraud-scripts loader', () => {
	beforeEach( () => {
		delete window.__wooPaymentsFraudScriptsEnqueued;
		delete window._sift;
		document.body.innerHTML = '';
	} );

	it( 'pushes the Sift account, identity and pageview commands and injects the beacon', () => {
		enqueueFraudScripts( {
			sift: {
				beacon_key: 'beacon_123',
				user_id: 'cus_456',
				session_id: 'st_abc_789',
			},
		} );

		expect( window._sift ).toEqual( [
			[ '_setAccount', 'beacon_123' ],
			[ '_setUserId', 'cus_456' ],
			[ '_setSessionId', 'st_abc_789' ],
			[ '_trackPageview' ],
		] );
		expect(
			document.querySelectorAll( '[src="https://cdn.sift.com/s.js"]' )
		).toHaveLength( 1 );
	} );

	it( 'runs only once even when called from more than one bundle', () => {
		const config = {
			sift: { beacon_key: 'beacon_123', user_id: '', session_id: null },
		};

		enqueueFraudScripts( config );
		enqueueFraudScripts( config );

		expect( window._sift ).toHaveLength( 4 );
		expect(
			document.querySelectorAll( '[src="https://cdn.sift.com/s.js"]' )
		).toHaveLength( 1 );
	} );

	it( 'skips unknown services and services with falsy configs', () => {
		enqueueFraudScripts( {
			sift: null,
			unknown_service: { anything: true },
		} );

		expect( window._sift ).toBeUndefined();
		expect( document.body.innerHTML ).toBe( '' );
	} );

	it( 'does not consume the one-shot guard when no config is provided', () => {
		enqueueFraudScripts( undefined );

		expect( window.__wooPaymentsFraudScriptsEnqueued ).toBeUndefined();

		enqueueFraudScripts( {
			sift: { beacon_key: 'beacon_123', user_id: '', session_id: '' },
		} );

		expect( window._sift ).toHaveLength( 4 );
	} );

	it( 'does not inject Stripe.js twice when it is already on the page', () => {
		const existing = document.createElement( 'script' );
		existing.src = 'https://js.stripe.com/v3/';
		document.body.appendChild( existing );

		enqueueFraudScripts( { stripe: {} } );

		expect(
			document.querySelectorAll( '[src^="https://js.stripe.com/v3"]' )
		).toHaveLength( 1 );
	} );
} );
