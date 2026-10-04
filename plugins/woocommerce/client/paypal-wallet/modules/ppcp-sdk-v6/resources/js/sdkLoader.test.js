const mockPostJson = jest.fn();
jest.mock( './utils/api', () => ( {
	postJson: ( ...args ) => mockPostJson( ...args ),
} ) );

const mockLoadScript = jest.fn();
jest.mock( './utils/scriptLoaders', () => ( {
	loadScript: ( ...args ) => mockLoadScript( ...args ),
} ) );

// loadSdkV6() memoizes on window-level state (shared across webpack bundles),
// which survives jest.resetModules(), so each test also needs its own window keys.
let loadSdkV6;

function baseConfig( overrides = {} ) {
	return {
		sdk_url: 'https://example.test/sdk.js',
		ajax: { client_token: { endpoint: '/token', nonce: 'n' } },
		locale: 'en_US',
		...overrides,
	};
}

beforeEach( () => {
	jest.resetModules();
	mockPostJson.mockReset();
	mockLoadScript.mockReset();
	mockLoadScript.mockResolvedValue( undefined );
	mockPostJson.mockResolvedValue( { client_token: 'TOKEN' } );
	delete window.__ppcpV6InstancePromise;
	delete window.__ppcpV6ScriptPromises;
	delete window.__ppcpV6ClientMetadataId;
	( { loadSdkV6 } = require( './sdkLoader' ) );
	window.paypal = { createInstance: jest.fn().mockResolvedValue( {} ) };
} );

afterEach( () => {
	delete window.paypal;
} );

describe( 'loadSdkV6', () => {
	test.each( [
		[ 'no optional components enabled', {} ],
		[
			'a leftover fastlane flag in the config',
			{ fastlane: { enabled: true } },
		],
	] )(
		'requests only the PayPal and Venmo components with %s',
		async ( label, overrides ) => {
			await loadSdkV6( baseConfig( overrides ), 'checkout' );

			expect( window.paypal.createInstance ).toHaveBeenCalledWith(
				expect.objectContaining( {
					components: [ 'paypal-payments', 'venmo-payments' ],
				} )
			);
		}
	);

	test( 'requests paypal-messages only when config.messages.enabled is true', async () => {
		await loadSdkV6(
			baseConfig( { messages: { enabled: true } } ),
			'product'
		);

		expect( window.paypal.createInstance ).toHaveBeenCalledWith(
			expect.objectContaining( {
				components: expect.arrayContaining( [ 'paypal-messages' ] ),
			} )
		);
	} );

	test( 'omits paypal-messages when config.messages.enabled is false', async () => {
		await loadSdkV6(
			baseConfig( { messages: { enabled: false } } ),
			'product'
		);

		expect( window.paypal.createInstance ).toHaveBeenCalledWith(
			expect.objectContaining( {
				components: expect.not.arrayContaining( [ 'paypal-messages' ] ),
			} )
		);
	} );

	test( 'shares one instance across concurrent callers: one createInstance call and one client-token fetch', async () => {
		const config = baseConfig();

		const first = loadSdkV6( config, 'cart' );
		const second = loadSdkV6( config, 'checkout' );

		const [ firstSdk, secondSdk ] = await Promise.all( [ first, second ] );

		expect( firstSdk ).toBe( secondSdk );
		expect( window.paypal.createInstance ).toHaveBeenCalledTimes( 1 );
		expect( mockPostJson ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'uses the page type of the first caller for the shared instance', async () => {
		const config = baseConfig();

		const first = loadSdkV6( config, 'cart' );
		const second = loadSdkV6( config, 'checkout' );
		await Promise.all( [ first, second ] );

		expect( window.paypal.createInstance ).toHaveBeenCalledWith(
			expect.objectContaining( { pageType: 'cart' } )
		);
	} );

	test( 'passes a stable clientMetadataId across two loadSdkV6 calls on the same page', async () => {
		await loadSdkV6( baseConfig(), 'checkout' );
		const firstId =
			window.paypal.createInstance.mock.calls[ 0 ][ 0 ].clientMetadataId;

		// Resetting only the instance cache forces a second createInstance call
		// while leaving the page-level metadata id cache intact.
		delete window.__ppcpV6InstancePromise;
		window.paypal.createInstance.mockClear();

		await loadSdkV6( baseConfig(), 'checkout' );
		const secondId =
			window.paypal.createInstance.mock.calls[ 0 ][ 0 ].clientMetadataId;

		expect( firstId ).toEqual( expect.any( String ) );
		expect( secondId ).toBe( firstId );
	} );
} );
