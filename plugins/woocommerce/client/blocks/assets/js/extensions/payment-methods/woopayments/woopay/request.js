/**
 * Internal dependencies
 */
import { ensureBlocksWooPayAppearance } from '../upe-styles';
import { buildAjaxUrl } from './protocol';

/**
 * Post a WooPayments wc-ajax request the way the Blocks checkout sends its WooPay requests: a multipart body, with
 * object values as JSON and empty values left out.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} endpoint        Endpoint name without the wcpay_ prefix.
 * @param {Object} data            Request fields.
 * @return {Promise<Object>} The parsed answer.
 */
export const postWooPayAjax = async ( paymentSettings, endpoint, data ) => {
	const body = new window.FormData();
	Object.entries( data ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null ) {
			return;
		}
		body.append(
			key,
			typeof value === 'object' ? JSON.stringify( value ) : value
		);
	} );

	const response = await window.fetch(
		buildAjaxUrl( { wcAjaxUrl: paymentSettings.wcAjaxUrl }, endpoint ),
		{
			method: 'POST',
			credentials: 'same-origin',
			body,
		}
	);

	return response.json();
};

export const getWooPayAppearance = ( paymentSettings ) =>
	paymentSettings.isWooPayGlobalThemeSupportEnabled
		? ensureBlocksWooPayAppearance(
				paymentSettings.stylesCacheVersion,
				document,
				paymentSettings
			)
		: null;

/**
 * The values the WooPay OTP flows read, from a Blocks payment method's settings.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @return {Object} The WooPayRequestArgs and the OTP iframe values of the shared WooPay protocol.
 */
export const getWooPayRequestArgs = ( paymentSettings ) => ( {
	wcAjaxUrl: paymentSettings.wcAjaxUrl,
	post: ( endpoint, data ) =>
		postWooPayAjax( paymentSettings, endpoint, data ),
	initWooPayNonce: paymentSettings.initWooPayNonce,
	woopaySessionNonce: paymentSettings.woopaySessionNonce,
	orderId: paymentSettings.orderId || '',
	key: paymentSettings.key || '',
	billingEmail: paymentSettings.billing_email || '',
	getInitAppearance: () => getWooPayAppearance( paymentSettings ),
	getInitFontRules: () =>
		paymentSettings.isWooPayGlobalThemeSupportEnabled
			? paymentSettings.woopayFontRules
			: null,
	getSessionAppearance: () => getWooPayAppearance( paymentSettings ),
	woopayHost: paymentSettings.woopayHost,
	testMode: paymentSettings.testMode,
	wcpayVersionNumber: paymentSettings.wcpayVersionNumber,
	isWoopayFirstPartyAuthEnabled:
		paymentSettings.isWoopayFirstPartyAuthEnabled,
	otpIframeTitle: paymentSettings.woopayOtpIframeTitle,
	otpCloseLabel: paymentSettings.woopayOtpCloseLabel,
} );
