describe( 'fraudnet.js _injectConfig', () => {
	beforeAll( () => {
		global.FraudNetConfig = {
			f: 'fraudnet-session-id',
			s: 'store-id',
			sandbox: '1',
		};
		require( './fraudnet.js' );
		window.dispatchEvent( new Event( 'load' ) );
	} );

	it( 'injects the FraudNet configuration and loads the beacon script on load', () => {
		const config = document.querySelector( '#fconfig' );

		expect( config ).not.toBeNull();
		expect( JSON.parse( config.text ) ).toEqual( {
			f: 'fraudnet-session-id',
			s: 'store-id',
			sandbox: true,
		} );
		expect(
			document.querySelector(
				'script[src="https://c.paypal.com/da/r/fb.js"]'
			)
		).not.toBeNull();
	} );
} );
