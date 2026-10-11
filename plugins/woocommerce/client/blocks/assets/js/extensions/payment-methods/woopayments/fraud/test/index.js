const mockGetSetting = jest.fn();
jest.mock( '@woocommerce/settings', () => ( {
	getSetting: ( ...args ) => mockGetSetting( ...args ),
} ) );

const SIFT_CONFIG = {
	beacon_key: 'beacon_123',
	user_id: 'cus_456',
	session_id: 'st_abc_789',
};

const loadEntry = () => {
	let entry;
	jest.isolateModules( () => {
		entry = require( '../index' );
	} );
	return entry;
};

describe( 'WooPayments Blocks fraud-scripts entry', () => {
	beforeEach( () => {
		delete window.__wooPaymentsFraudScriptsEnqueued;
		delete window._sift;
		document.body.innerHTML = '';
		mockGetSetting.mockReset();
	} );

	it( 'loads Sift and Stripe.js from the card gateway config once the page loads', () => {
		mockGetSetting.mockReturnValue( {
			woocommerce_payments: {
				fraudServices: { stripe: [], sift: SIFT_CONFIG },
			},
		} );

		loadEntry();

		expect(
			document.querySelector( '[src="https://cdn.sift.com/s.js"]' )
		).toBeNull();

		window.dispatchEvent( new Event( 'load' ) );

		expect( window._sift ).toContainEqual( [
			'_setAccount',
			'beacon_123',
		] );
		expect(
			document.querySelectorAll( '[src="https://cdn.sift.com/s.js"]' )
		).toHaveLength( 1 );
		expect(
			document.querySelectorAll( '[src^="https://js.stripe.com/v3"]' )
		).toHaveLength( 1 );
	} );

	it( 'reads another WooPayments gateway when the card gateway is absent', () => {
		mockGetSetting.mockReturnValue( {
			cod: { fraudServices: { sift: { beacon_key: 'not_ours' } } },
			woocommerce_payments_bancontact: {
				fraudServices: { sift: SIFT_CONFIG },
			},
		} );

		const { getFraudServicesConfig } = loadEntry();

		expect( getFraudServicesConfig() ).toEqual( { sift: SIFT_CONFIG } );
	} );

	it( 'returns nothing when no WooPayments gateway carries a config', () => {
		mockGetSetting.mockReturnValue( {
			cod: { fraudServices: { sift: SIFT_CONFIG } },
		} );

		const { getFraudServicesConfig } = loadEntry();

		expect( getFraudServicesConfig() ).toBeUndefined();
	} );
} );
