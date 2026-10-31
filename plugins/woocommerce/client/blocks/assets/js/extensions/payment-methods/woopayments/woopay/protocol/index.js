/**
 * Internal dependencies
 */
import {
	buildAjaxUrl,
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	deleteSkipWooPayCookieOnClick,
	dispatchUserExistEvent,
	getTargetElement,
	getWooPayHostOrigin,
	initWooPay,
	isPreviewing,
	shouldSkipWooPay,
	validateEmail,
} from './helpers';
import {
	getCachedPreferredCard,
	getConnect,
	isSamePreferredCard,
	isValidPreferredCard,
	setCachedPreferredCard,
} from './connect';
import { handleEmailInput } from './email-input';
import { openExpressOtpIframe } from './express-otp';
import { loadPhoneValidation, normalizePhone } from './phone-validation';

// The WooPay protocol both checkouts speak with hosted WooPay, built once as the wc-woopayments-woopay-common script,
// which bundles the Tracks identity it needs. Classic scripts list that handle and read window.wc.woopayments.woopay;
// Blocks scripts import this module, which their build maps to the same global. Internal to WooPayments, not an
// extension API.
export {
	buildAjaxUrl,
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	deleteSkipWooPayCookieOnClick,
	dispatchUserExistEvent,
	getCachedPreferredCard,
	getConnect,
	getTargetElement,
	getWooPayHostOrigin,
	handleEmailInput,
	initWooPay,
	isPreviewing,
	isSamePreferredCard,
	isValidPreferredCard,
	loadPhoneValidation,
	normalizePhone,
	openExpressOtpIframe,
	setCachedPreferredCard,
	shouldSkipWooPay,
	validateEmail,
};

window.wc = window.wc || {};
window.wc.woopayments = window.wc.woopayments || {};
window.wc.woopayments.woopay = {
	buildAjaxUrl,
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	deleteSkipWooPayCookieOnClick,
	dispatchUserExistEvent,
	getCachedPreferredCard,
	getConnect,
	getTargetElement,
	getWooPayHostOrigin,
	handleEmailInput,
	initWooPay,
	isPreviewing,
	isSamePreferredCard,
	isValidPreferredCard,
	loadPhoneValidation,
	normalizePhone,
	openExpressOtpIframe,
	setCachedPreferredCard,
	shouldSkipWooPay,
	validateEmail,
};
