/**
 * External dependencies
 */
import { dispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { getTracksIdentity } from '../tracks';
import { getTargetElement, openExpressOtpIframe } from './protocol';
import { getWooPayRequestArgs } from './request';

/*
 * The WooPay button's OTP flow on Blocks pages: the shared WooPay protocol (./protocol) with the Blocks notices and
 * requests.
 */

/**
 * Show an express-button error the way the plugin does: a Blocks notice on
 * the cart and checkout blocks, a server-rendered WooCommerce notice elsewhere.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} context         The button context.
 * @param {string} message         The error message.
 */
export const showErrorMessage = ( paymentSettings, context, message ) => {
	if ( window.wcSettings?.wcBlocksConfig && context !== 'product' ) {
		dispatch( 'core/notices' )?.createNotice( 'error', message, {
			context: `wc/${ context }`,
		} );
		return;
	}

	window
		.fetch( paymentSettings.ajaxUrl, {
			method: 'POST',
			body: new URLSearchParams( {
				action: 'woopay_express_checkout_button_show_error_notice',
				_ajax_nonce: paymentSettings.woopayButtonNonce || '',
				context,
				message,
			} ),
		} )
		.then( ( response ) => response.json() )
		.then( ( response ) => {
			const noticesWrapper = document.querySelector(
				'.woocommerce-notices-wrapper'
			);
			if ( ! response?.success || ! noticesWrapper ) {
				return;
			}

			const wrapper = document.createElement( 'div' );
			wrapper.innerHTML = response.data.notice;
			noticesWrapper.insertBefore( wrapper, null );
			noticesWrapper.scrollIntoView( {
				behavior: 'smooth',
				block: 'center',
			} );
		} )
		.catch( () => {} );
};

/**
 * Open the WooPay OTP iframe for the express button.
 *
 * @param {Object}                paymentSettings Payment method settings.
 * @param {string}                context         The button context (checkout, cart, product).
 * @param {string}                emailSelector   Selector of the email field to prefill from.
 * @param {Function}              navigate        Navigates the page to a URL.
 * @param {Element|Function|null} opener          The control that gets focus back on close, or a function that returns
 *                                                it when the dialog closes. The express button passes one: Blocks may
 *                                                replace it while the dialog is open, and the shopper may move focus
 *                                                away while it loads.
 */
export const expressCheckoutIframe = async (
	paymentSettings,
	context,
	emailSelector,
	navigate,
	opener = null
) => {
	const tracksUserId = await getTracksIdentity( paymentSettings );
	const emailInput = await getTargetElement( emailSelector );

	openExpressOtpIframe(
		{
			...getWooPayRequestArgs( paymentSettings ),
			unavailableMessage: paymentSettings.woopayExpressUnavailableMessage,
			showError: ( message ) =>
				showErrorMessage( paymentSettings, context, message ),
			navigate,
			// The Checkout block swaps its express buttons for a skeleton while it recalculates, which puts a new
			// button in place, so the opener is read when the dialog closes.
			getFocusTarget: ( previouslyFocused ) =>
				( typeof opener === 'function' ? opener() : opener ) ||
				previouslyFocused,
		},
		context,
		emailInput?.value || paymentSettings.woopaySessionEmail,
		tracksUserId
	);
};
