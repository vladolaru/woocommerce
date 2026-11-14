const { setupServer } = require( 'msw/node' );
const { http, HttpResponse } = require( 'msw' );

const server = setupServer();

beforeAll( () => {
	server.listen( { onUnhandledRequest: 'error' } );
} );

afterEach( () => {
	server.resetHandlers();
} );

afterAll( () => {
	server.close();
} );

module.exports = { server, http, HttpResponse };
