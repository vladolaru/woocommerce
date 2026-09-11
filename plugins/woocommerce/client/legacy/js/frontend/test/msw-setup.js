const { setupServer } = require( 'msw/node' );
const { http, HttpResponse } = require( 'msw' );

const server = setupServer();

beforeAll( () => {
	server.listen( { onUnhandledRequest: 'bypass' } );
} );

afterEach( () => {
	server.resetHandlers();
} );

afterAll( () => {
	server.close();
} );

if ( expect.getState().testPath.endsWith( 'msw-setup.js' ) ) {
	test( 'configures the local MSW server lifecycle', () => {
		expect( server ).toBeDefined();
	} );
}

module.exports = { server, http, HttpResponse };
