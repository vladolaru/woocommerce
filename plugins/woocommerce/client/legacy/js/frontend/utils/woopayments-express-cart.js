( function () {
	'use strict';

	// All express buttons and product previews on the page share these values.
	const refreshed = {};
	const cartHeader = 'X-WooPayments-Tokenized-Cart-Nonce';
	const sessionHeader = 'X-WooPayments-Tokenized-Cart-Session-Nonce';

	const nonceFields = {
		Nonce: 'store_api_nonce',
		[ cartHeader ]: 'tokenized_cart_nonce',
		[ sessionHeader ]: 'tokenized_cart_session_nonce',
	};

	function getNonceHeaders( initial, includeSession ) {
		const headers = {};
		for ( const name of Object.keys( nonceFields ) ) {
			const value = refreshed[ name ] || initial[ nonceFields[ name ] ];
			if ( value && ( name !== sessionHeader || includeSession ) ) {
				headers[ name ] = value;
			}
		}
		return headers;
	}

	function rememberNonces( apiFetch, headers ) {
		if (
			! headers ||
			typeof headers.get !== 'function' ||
			! headers.get( 'Nonce' )
		) {
			return;
		}
		for ( const name of Object.keys( nonceFields ) ) {
			const value = headers.get( name );
			if ( value ) {
				refreshed[ name ] = value;
			}
		}
		if ( typeof apiFetch.setNonce === 'function' ) {
			apiFetch.setNonce( headers );
		}
	}

	function requestExpressCart(
		apiFetch,
		options,
		nonces,
		includeSession,
		onSession
	) {
		async function send( canRetry ) {
			const headers = getNonceHeaders( nonces || {}, includeSession );
			let response;
			try {
				response = await apiFetch(
					Object.assign( {}, options, {
						parse: false,
						headers: Object.assign( headers, options.headers ),
					} )
				);
			} catch ( error ) {
				rememberNonces( apiFetch, error && error.headers );
				if ( ! error || typeof error.clone !== 'function' ) {
					throw error;
				}
				const body = await error
					.clone()
					.json()
					.catch( function () {
						throw error;
					} );
				// Only the Store API nonce refusal precedes checkout processing.
				if (
					canRetry &&
					error.status === 403 &&
					body &&
					body.code === 'woocommerce_rest_invalid_nonce'
				) {
					return send( false );
				}
				// Product requests already exposed raw errors. Other callers read the REST error message.
				throw options.parse === false ? error : body;
			}

			rememberNonces( apiFetch, response && response.headers );
			if (
				onSession &&
				response &&
				response.headers &&
				typeof response.headers.get === 'function'
			) {
				const session = response.headers.get(
					'X-WooPayments-Tokenized-Cart-Session'
				);
				if ( session != null ) {
					onSession( session );
				}
			}
			return response && typeof response.json === 'function'
				? response.json()
				: response;
		}
		return send( true );
	}

	window.wc = window.wc || {};
	window.wc.woopayments = window.wc.woopayments || {};
	window.wc.woopayments.requestExpressCart = requestExpressCart;
} )();
