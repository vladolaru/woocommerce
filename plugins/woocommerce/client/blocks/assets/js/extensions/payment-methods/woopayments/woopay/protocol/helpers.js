// The WooPay helpers both checkouts share. Every value comes from an argument object the calling script builds from
// its own config, and flags are read by truthiness, because a classic page's wp_localize_script() config sends a
// false flag as ''.

/**
 * What a checkout script gives the WooPay requests, built from its own config. Each script keeps its own request
 * function, because the scripts encode the request bodies differently.
 *
 * @typedef {Object} WooPayRequestArgs
 * @property {string}   wcAjaxUrl            The store's wc-ajax URL, with the %%endpoint%% placeholder.
 * @property {Function} post                 Posts `( endpoint, data )` and resolves the parsed answer.
 * @property {string}   initWooPayNonce      Nonce of the init_woopay request.
 * @property {string}   woopaySessionNonce   Nonce of the get_woopay_session request.
 * @property {*}        orderId              Sent as order_id, as the script holds it.
 * @property {*}        key                  Sent as key, as the script holds it.
 * @property {*}        billingEmail         Sent as billing_email, as the script holds it.
 * @property {Function} getInitAppearance    Returns the appearance init_woopay sends.
 * @property {Function} getInitFontRules     Returns the font rules init_woopay sends.
 * @property {Function} getSessionAppearance Returns the appearance get_woopay_session sends.
 */

// One init_woopay request in flight per page: WooPay's <Login> re-renders and sends the redirect message twice.
let isInitRequesting = false;

/**
 * Build the URL of a WooPayments wc-ajax endpoint.
 *
 * @param {Object} args           The calling script's values.
 * @param {string} args.wcAjaxUrl The store's wc-ajax URL, with the %%endpoint%% placeholder.
 * @param {string} endpoint       Endpoint name without the wcpay_ prefix.
 * @return {string} The URL, or '' without a wc-ajax URL.
 */
export const buildAjaxUrl = ( args, endpoint ) =>
	args?.wcAjaxUrl
		? args.wcAjaxUrl.replace( '%%endpoint%%', `wcpay_${ endpoint }` )
		: '';

export const validateEmail = ( value ) => {
	/* Borrowed from WooCommerce checkout.js with a slight tweak to add `{2,}` to the end and make the TLD at least 2 characters. */

	const pattern = new RegExp(
		/^([a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+(\.[a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+)*|"((([ \t]*\r\n)?[ \t]+)?([\x01-\x08\x0b\x0c\x0e-\x1f\x7f\x21\x23-\x5b\x5d-\x7e\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|\\[\x01-\x09\x0b\x0c\x0d-\x7f\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))*(([ \t]*\r\n)?[ \t]+)?")@(([a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.)+([a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[0-9a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]){2,}\.?$/i
	);

	return pattern.test( value );
};

/**
 * The Customizer preview iframe (which carries customize_messenger_channel) or a post preview.
 *
 * @param {Object}  args           The calling script's values.
 * @param {boolean} args.isPreview Whether the page is a post preview.
 * @return {boolean} True while previewing.
 */
export const isPreviewing = ( args ) =>
	new URLSearchParams( window.location.search ).get(
		'customize_messenger_channel'
	) !== null || !! args?.isPreview;

/**
 * The origin WooPay messages must come from.
 *
 * @param {Object} args            The calling script's values.
 * @param {string} args.woopayHost The WooPay host URL.
 * @return {string} The origin, or '' when the host does not parse, so no sender is trusted.
 */
export const getWooPayHostOrigin = ( args ) => {
	try {
		return new window.URL( args?.woopayHost ).origin;
	} catch ( error ) {
		return '';
	}
};

/**
 * Whether the shopper opted out of WooPay for this session. The store only ever writes skip_woopay=1, so the exact pair
 * decides, and another cookie that holds the text, such as one storing WooPay's back-to-store URL, changes nothing.
 * Client 11.2.0 lets the first cookie holding the text decide (checkout/woopay/utils.js:72-88).
 *
 * @return {boolean} True when the skip_woopay=1 cookie is set.
 */
export const shouldSkipWooPay = () =>
	document.cookie
		.split( ';' )
		.some( ( cookie ) => cookie.trim() === 'skip_woopay=1' );

/**
 * Delete the skip_woopay cookie when the shopper opts back in to WooPay.
 */
export const deleteSkipWooPayCookie = () => {
	if ( shouldSkipWooPay() ) {
		document.cookie =
			'skip_woopay=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
	}
};

/**
 * Claim a WooPay redirect message for one listener's init_woopay request. The email input and the WooPay button
 * hear every message from WooPay and the email input's listener is first, so only the open dialog that sent it claims
 * it, on the event both receive. That dialog then gets the outcome: the notice, its closing and the redirect. A closed
 * iframe's window is null and a discarded frame's message can carry a null source, so the source alone would match it.
 *
 * @param {MessageEvent}      event  The WooPay redirect message.
 * @param {HTMLIFrameElement} iframe The iframe of the listener's dialog.
 * @return {boolean} True when this listener handles the message.
 */
export const claimWooPayRedirect = ( event, iframe ) => {
	if (
		event.wcWooPayInitClaimed ||
		! iframe.isConnected ||
		event.source !== iframe.contentWindow
	) {
		return false;
	}
	event.wcWooPayInitClaimed = true;
	return true;
};

/**
 * Tell the page whether the checkout email belongs to a WooPay user; the save-my-info offer listens for it.
 *
 * @param {boolean} userExist Whether the email belongs to a WooPay user.
 */
export const dispatchUserExistEvent = ( userExist ) => {
	window.dispatchEvent(
		new window.CustomEvent( 'woopayUserCheck', {
			detail: { isRegisteredUser: userExist },
		} )
	);
};

/**
 * Wait for an element the Checkout block renders after the script runs.
 *
 * @param {string} selector The element selector.
 * @return {Promise<Element|null>} The element, or null when it is missing and no Checkout block can render it.
 */
export const getTargetElement = ( selector ) => {
	if ( ! selector ) {
		return Promise.resolve( null );
	}

	return new Promise( ( resolve ) => {
		if ( document.querySelector( selector ) ) {
			return resolve( document.querySelector( selector ) );
		}

		const checkoutBlock = document.querySelector(
			'[data-block-name="woocommerce/checkout"]'
		);

		if ( ! checkoutBlock ) {
			return resolve( null );
		}

		const observer = new MutationObserver( ( mutationList, obs ) => {
			if ( document.querySelector( selector ) ) {
				resolve( document.querySelector( selector ) );
				obs.disconnect();
			}
		} );

		observer.observe( checkoutBlock, {
			childList: true,
			subtree: true,
		} );
	} );
};

/**
 * Send init_woopay with the WooPay user session the OTP iframe handed back.
 *
 * @param {WooPayRequestArgs} args        The calling script's request values.
 * @param {string}            userEmail   The shopper's email address.
 * @param {string}            userSession The WooPay user session token.
 * @return {Promise<Object>|undefined} The request, or undefined while one is already in flight.
 */
export const initWooPay = ( args, userEmail, userSession ) => {
	if ( isInitRequesting ) {
		return undefined;
	}

	isInitRequesting = true;

	return args
		.post( 'init_woopay', {
			_wpnonce: args.initWooPayNonce || '',
			appearance: args.getInitAppearance(),
			font_rules: args.getInitFontRules(),
			email: userEmail,
			user_session: userSession,
			order_id: args.orderId,
			key: args.key,
			billing_email: args.billingEmail,
		} )
		.finally( () => {
			isInitRequesting = false;
		} );
};

/**
 * On the OTP iframe's load, with first-party auth on, post the store's WooPay session to the iframe.
 *
 * @param {WooPayRequestArgs} args   The calling script's request values, with isWoopayFirstPartyAuthEnabled and woopayHost.
 * @param {HTMLIFrameElement} iframe The OTP iframe.
 */
export const sendSessionToOtpIframe = ( args, iframe ) => {
	if ( ! args.isWoopayFirstPartyAuthEnabled ) {
		return;
	}

	args.post( 'get_woopay_session', {
		_ajax_nonce: args.woopaySessionNonce || '',
		order_id: args.orderId,
		key: args.key,
		billing_email: args.billingEmail,
		appearance: args.getSessionAppearance(),
	} )
		.then( ( response ) => {
			if ( response?.data?.session ) {
				iframe.contentWindow.postMessage(
					{ action: 'setSessionData', value: response },
					args.woopayHost || ''
				);
			}
		} )
		.catch( () => {} );
};
