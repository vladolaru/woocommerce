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

// The helpers the WooPayments Blocks card, express and WooPay scripts share, built once in their shared chunk
// (wc-payment-method-woopayments-common). The classic scripts keep their own copies.
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
