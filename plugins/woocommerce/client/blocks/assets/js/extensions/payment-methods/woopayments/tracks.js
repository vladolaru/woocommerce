/**
 * Record a WooPayments shopper event through the platform_tracks bridge.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} eventName       Event name without the wcpay_ prefix.
 * @param {Object} eventProperties Event properties.
 */
export const recordWooPaymentsUserEvent = (
	paymentSettings,
	eventName,
	eventProperties = {}
) => {
	if (
		! eventName ||
		paymentSettings?.isShopperTrackingEnabled === false ||
		paymentSettings?.is_shopper_tracking_enabled === false ||
		! window.fetch
	) {
		return;
	}

	const ajaxUrl = paymentSettings?.ajaxUrl || paymentSettings?.ajax_url;
	const nonce =
		paymentSettings?.platformTrackerNonce ||
		paymentSettings?.platform_tracker_nonce;

	if ( ! ajaxUrl || ! nonce ) {
		return;
	}

	const body = new window.FormData();
	body.append( 'tracksNonce', nonce );
	body.append( 'action', 'platform_tracks' );
	body.append( 'tracksEventName', eventName );
	body.append( 'tracksEventProp', JSON.stringify( eventProperties ) );

	window
		.fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} )
		.catch( () => {} );
};

const getTracksIdentityCookieValue = () => {
	const nameEq = 'tk_ai=';
	const cookie = document.cookie
		.split( ';' )
		.map( ( entry ) => entry.trim() )
		.find( ( entry ) => entry.indexOf( nameEq ) === 0 );

	return cookie ? cookie.substring( nameEq.length ) : undefined;
};

/**
 * Resolve the stringified Tracks identity WooPay stitches its own events
 * to — from the tk_ai cookie when present, otherwise through the
 * platform's get_identity bridge. Never rejects.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @return {Promise<string|undefined>} The identity, or undefined when unavailable.
 */
export const getTracksIdentity = async ( paymentSettings ) => {
	const cookieIdentity = getTracksIdentityCookieValue();
	if ( cookieIdentity ) {
		return JSON.stringify( { _ut: 'anon', _ui: cookieIdentity } );
	}

	const ajaxUrl = paymentSettings?.ajaxUrl || paymentSettings?.ajax_url;
	const nonce =
		paymentSettings?.platformTrackerNonce ||
		paymentSettings?.platform_tracker_nonce;

	if ( ! ajaxUrl || ! nonce || ! window.fetch ) {
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
	} catch ( error ) {
		return undefined;
	}
};
