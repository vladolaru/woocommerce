/**
 * Finds the v6 config, whichever way this page received it.
 *
 * @package
 */

/**
 * The wc_ppcp_sdk_v6 payload for the current page.
 *
 * Two delivery routes, because SdkV6Manager::enqueue() skips block pages: the
 * classic pages get the localized global, and the block pages get the same
 * payload through V6PaymentMethod::get_payment_method_data(), which WooCommerce
 * exposes under wcSettings. Consumers outside this module must not pick one, or
 * they silently see no v6 on the other kind of page.
 *
 * @return {?Object} The config, or null when v6 does not run here.
 */
export function sdkV6Config() {
	if ( window.wc_ppcp_sdk_v6 ) {
		return window.wc_ppcp_sdk_v6;
	}

	const paymentMethodData =
		window.wc?.wcSettings?.getSetting?.( 'paymentMethodData' ) || {};

	return paymentMethodData[ 'ppcp-sdk-v6' ] || null;
}
