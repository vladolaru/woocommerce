/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { isStoreApiRequest } from './store-api-nonce';

let cartToken = '';

const appendCartTokenHeader = ( request ) => {
	const headers = request.headers || {};
	request.headers = {
		...headers,
		'Cart-Token': cartToken,
	};
	return request;
};

/**
 * Middleware to add the 'Cart-Token' header to API requests.
 *
 * @param {Object}   options      Fetch options.
 * @param {Object}   options.url  The URL of the request.
 * @param {Object}   options.path The path of the request.
 * @param {Function} next         The next middleware or fetchHandler to call.
 * @return {*} The evaluated result of the remaining middleware chain.
 */
const setCartTokenMiddleware = (
	options: { url?: string; path?: string },
	next: ( options: { url?: string; path?: string } ) => Promise< unknown >
): Promise< unknown > => {
	cartToken =
		new URLSearchParams( window.location.search ).get( 'session' ) || '';

	if ( isStoreApiRequest( options ) ) {
		appendCartTokenHeader( options );

		// Add nonce to sub-requests
		if ( Array.isArray( options?.data?.requests ) ) {
			options.data.requests = options.data.requests.map(
				appendCartTokenHeader
			);
		}
	}

	return next( options );
};

apiFetch.use( setCartTokenMiddleware );
