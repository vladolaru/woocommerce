/**
 * Internal dependencies
 */
import { recordWooPaymentsUserEvent, getTracksIdentity } from '../tracks';
import {
	buildAjaxUrl,
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	getTargetElement,
	handleEmailInput,
	initWooPay as initWooPayRequest,
	isPreviewing,
	shouldSkipWooPay,
	validateEmail,
} from './protocol';
import { getWooPayRequestArgs } from './request';

/*
 * The WooPay email check on the Blocks checkout: the shared WooPay protocol (./protocol) with the Blocks checkout's
 * fields, notices and requests.
 */

let navigate = ( url ) => {
	window.location.href = url;
};

export {
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	getTargetElement,
	isPreviewing,
	shouldSkipWooPay,
	validateEmail,
};

/**
 * Fire the init_woopay request.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} userEmail       The shopper's email address.
 * @param {string} userSession     The WooPay user session token.
 * @return {Promise<Object>|undefined} The request, or undefined when one is already in flight.
 */
export const initWooPay = ( paymentSettings, userEmail, userSession ) =>
	initWooPayRequest(
		getWooPayRequestArgs( paymentSettings ),
		userEmail,
		userSession
	);

/**
 * Whether the protocol entry has loaded the members the email check calls. The card script loads without it when its
 * payment data turns the check off, and its build then reads an empty object here.
 *
 * @return {boolean} True when every member is there.
 */
const hasEmailCheckProtocol = () =>
	typeof isPreviewing === 'function' &&
	typeof getTargetElement === 'function' &&
	typeof handleEmailInput === 'function' &&
	typeof buildAjaxUrl === 'function';

/**
 * Whether the checkout should wire the WooPay email-input flow — the plugin's
 * blocks entry gate: WooPay on, email input on, not previewing, checkout block present.
 * Without the protocol the check does not wire, and the card works without it.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @return {boolean} True when the flow should be wired.
 */
export const shouldHandleWooPayEmailInput = ( paymentSettings ) =>
	!! paymentSettings?.isWooPayEnabled &&
	!! paymentSettings?.isWooPayEmailInputEnabled &&
	hasEmailCheckProtocol() &&
	! isPreviewing( { isPreview: paymentSettings?.isPreview } ) &&
	!! document.querySelector( '[data-block-name="woocommerce/checkout"]' );

/**
 * Wire the WooPay email-input flow to the checkout email field.
 *
 * @param {string} field           The email field selector.
 * @param {Object} paymentSettings Payment method settings.
 */
export const handleWooPayEmailInput = async ( field, paymentSettings ) => {
	// Pay-for-order pages carry their own WooPay entry point.
	if ( paymentSettings.isOrderPay || ! hasEmailCheckProtocol() ) {
		return;
	}

	const woopayEmailInput = await getTargetElement( field );
	const tracksUserId = await getTracksIdentity( paymentSettings );

	if ( ! woopayEmailInput ) {
		return;
	}

	handleEmailInput(
		{
			...getWooPayRequestArgs( paymentSettings ),
			woopayMerchantId: paymentSettings.woopayMerchantId,
			woopaySignatureNonce: paymentSettings.woopaySignatureNonce,
			woopayIsCountryAvailable: paymentSettings.woopayIsCountryAvailable,
			isBlocksCheckout: true,
			unavailableMessage: paymentSettings.woopayUnavailableMessage,
			recordEvent: ( eventName, eventProperties ) =>
				recordWooPaymentsUserEvent(
					paymentSettings,
					eventName,
					eventProperties
				),
			navigate: ( url ) => navigate( url ),
			// Placing the order cancels the lookup and closes the iframe.
			onPlaceOrder: ( callback ) =>
				getTargetElement(
					'button.wc-block-components-checkout-place-order-button'
				).then( ( formSubmitButton ) => {
					formSubmitButton?.addEventListener( 'click', callback );
				} ),
			// Client 11.1.0 reads window.woopayCheckout; native carries the flag in the payment settings.
			isSaveMyInfoPreChecked: () =>
				paymentSettings.PRE_CHECK_SAVE_MY_INFO,
			// The Blocks email field wrapper is the text-input component; the notice goes below the whole field.
			getNoticeNode: ( parentDiv ) => parentDiv.parentNode || parentDiv,
			// Focus goes back to the email field on every close that takes it from the dialog, the failure closes
			// included; client 11.1.0 gives it back only on the closes that ask for it (email-input-iframe.js:234-243).
			refocusFieldWhenDialogHadFocus: true,
		},
		woopayEmailInput,
		tracksUserId
	);
};

export const __test__ = {
	setNavigate: ( callback ) => {
		navigate = callback;
	},
};
