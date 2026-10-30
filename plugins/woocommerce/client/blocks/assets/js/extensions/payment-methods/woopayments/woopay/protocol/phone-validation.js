let phoneValidationPromise = null;

/**
 * Load core's mobile phone validation, built once as its own script (phone-validation.js) and loaded only when the
 * shopper opts in to save their details to WooPay.
 *
 * @param {string} url The validation script URL (WooPaymentsFrontendAssets::get_phone_validation_script_url()).
 * @return {Promise<Object|null>} `window.wcWooPaymentsPhoneValidation`, or null when it cannot load.
 */
export const loadPhoneValidation = ( url ) => {
	if ( window.wcWooPaymentsPhoneValidation ) {
		return Promise.resolve( window.wcWooPaymentsPhoneValidation );
	}
	if ( ! url ) {
		return Promise.resolve( null );
	}
	if ( ! phoneValidationPromise ) {
		phoneValidationPromise = new Promise( ( resolve ) => {
			const script = document.createElement( 'script' );
			script.src = url;
			script.async = true;
			script.onload = () =>
				resolve( window.wcWooPaymentsPhoneValidation || null );
			script.onerror = () => {
				phoneValidationPromise = null;
				resolve( null );
			};
			document.head.appendChild( script );
		} );
	}
	return phoneValidationPromise;
};

/**
 * The number WooPay receives: digits and +, with a number that has no country code read as US (WooPay is US-only),
 * as client 11.1.0 checkout-page-save-user.js:93-111 does.
 *
 * @param {string} phone The number the shopper typed.
 * @return {string} The normalized number.
 */
export const normalizePhone = ( phone ) => {
	const value = String( phone || '' ).replace( /[^\d+]*/g, '' );
	return value.startsWith( '+' ) ? value : '+1' + value;
};
