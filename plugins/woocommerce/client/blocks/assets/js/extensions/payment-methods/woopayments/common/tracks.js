// Every value comes from an argument object the calling script builds from its own config. Flags are read by
// truthiness, because a classic page's wp_localize_script() config sends a false flag as ''.

/**
 * Record a WooPayments shopper event through the shopper Tracks REST route.
 *
 * @param {Object}  args                          Tracking values from the calling script's config.
 * @param {boolean} args.isShopperTrackingEnabled Whether the store records shopper events.
 * @param {string}  args.tracksUrl                Shopper Tracks REST route.
 * @param {string}  args.platformTrackerNonce     Nonce the Tracks route checks.
 * @param {string}  args.tracksRestNonce          REST nonce that keeps a logged-in shopper's session.
 * @param {string}  eventName                     Event name without the wcpay_ prefix.
 * @param {Object}  eventProperties               Event properties.
 */
export const recordEvent = ( args, eventName, eventProperties ) => {
	if (
		! eventName ||
		! args?.isShopperTrackingEnabled ||
		! args.tracksUrl ||
		! args.platformTrackerNonce ||
		! window.fetch ||
		! window.FormData
	) {
		return;
	}

	const body = new window.FormData();
	body.append( 'tracksNonce', args.platformTrackerNonce );
	body.append( 'tracksEventName', eventName );
	body.append( 'tracksEventProp', JSON.stringify( eventProperties || {} ) );

	window
		.fetch( args.tracksUrl, {
			method: 'POST',
			credentials: 'same-origin',
			// The REST nonce keeps a logged-in shopper's session, which the Tracks nonce is tied to.
			headers: args.tracksRestNonce
				? { 'X-WP-Nonce': args.tracksRestNonce }
				: {},
			body,
		} )
		.catch( () => {} );
};

const getIdentityCookieValue = () => {
	const nameEq = 'tk_ai=';
	const cookie = document.cookie
		.split( ';' )
		.map( ( entry ) => entry.trim() )
		.find( ( entry ) => entry.indexOf( nameEq ) === 0 );

	return cookie ? cookie.substring( nameEq.length ) : undefined;
};

/**
 * Resolve the stringified Tracks identity WooPay stitches its own events to: from the tk_ai cookie when present,
 * otherwise through the platform's get_identity bridge. Never rejects.
 *
 * @param {Object} args                      Values from the calling script's config.
 * @param {string} args.ajaxUrl              admin-ajax.php URL.
 * @param {string} args.platformTrackerNonce Nonce the get_identity action checks.
 * @return {Promise<string|undefined>} The identity, or undefined when unavailable.
 */
export const getIdentity = async ( args ) => {
	const cookieIdentity = getIdentityCookieValue();
	if ( cookieIdentity ) {
		return JSON.stringify( { _ut: 'anon', _ui: cookieIdentity } );
	}

	const ajaxUrl = args?.ajaxUrl;
	const nonce = args?.platformTrackerNonce;

	if ( ! ajaxUrl || ! nonce || ! window.fetch || ! window.FormData ) {
		return undefined;
	}

	const body = new window.FormData();
	body.append( 'tracksNonce', nonce );
	body.append( 'action', 'get_identity' );

	try {
		const response = await window.fetch( ajaxUrl, {
			method: 'POST',
			body,
		} );
		if ( ! response.ok ) {
			return undefined;
		}

		const data = await response.json();
		if ( data?.success && data.data?._ui && data.data?._ut ) {
			return JSON.stringify( data.data );
		}

		return undefined;
	} catch {
		return undefined;
	}
};
