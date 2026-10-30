/**
 * Internal dependencies
 */
import { getIdentity, recordEvent } from './tracks';
import { StripeWaitTimeoutError, waitForStripe } from '../wait-for-stripe';
import { confirmWithStripe, parseConfirmationHash } from './confirmation';
import {
	getBillingAddress,
	getWalletBillingPhone,
	normalizeAddress,
	normalizePhone,
	splitName,
	transformPrice,
} from './express-checkout';

// The helpers the WooPayments card, express and WooPay scripts share on both checkouts, built once as the
// wc-woopayments-common script. Classic scripts list that handle and read window.wc.woopayments; Blocks scripts
// import this module, which their build maps to the same global. Internal to WooPayments, not an extension API.
export const tracks = { recordEvent, getIdentity };

export const expressCheckout = {
	getBillingAddress,
	normalizeAddress,
	splitName,
	normalizePhone,
	getWalletBillingPhone,
	transformPrice,
};

export {
	waitForStripe,
	StripeWaitTimeoutError,
	parseConfirmationHash,
	confirmWithStripe,
};

window.wc = window.wc || {};
window.wc.woopayments = {
	tracks,
	waitForStripe,
	StripeWaitTimeoutError,
	parseConfirmationHash,
	confirmWithStripe,
	expressCheckout,
};
