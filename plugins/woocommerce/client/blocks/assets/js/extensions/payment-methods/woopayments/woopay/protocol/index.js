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
	createConnect,
	getCachedPreferredCard,
	isSamePreferredCard,
	isValidPreferredCard,
	setCachedPreferredCard,
} from './connect';
import { handleEmailInput } from './email-input';
import { openExpressOtpIframe } from './express-otp';
import { loadPhoneValidation, normalizePhone } from './phone-validation';

// The WooPay protocol both checkouts speak with hosted WooPay, built once as the wc-woopayments-woopay-common script,
// which loads after wc-woopayments-common. Classic scripts list that handle and read window.wc.woopayments.woopay;
// Blocks scripts import this module, which their build maps to the same global. Internal to WooPayments, not an
// extension API.
export {
	buildAjaxUrl,
	claimWooPayRedirect,
	createConnect,
	deleteSkipWooPayCookie,
	deleteSkipWooPayCookieOnClick,
	dispatchUserExistEvent,
	getCachedPreferredCard,
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
	createConnect,
	deleteSkipWooPayCookie,
	deleteSkipWooPayCookieOnClick,
	dispatchUserExistEvent,
	getCachedPreferredCard,
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
