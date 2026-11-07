/**
 * Core's mobile phone validation (packages/js/components/src/phone-number-input/validation.ts), aliased by
 * bin/webpack-configs.js (getPaymentsConfig) and tests/js/jest.config.js for the WooPay save-user phone check.
 */
declare module 'woocommerce-phone-number-validation' {
	export function validatePhoneNumber(
		number: string,
		countryAlpha2?: string
	): boolean;
}
