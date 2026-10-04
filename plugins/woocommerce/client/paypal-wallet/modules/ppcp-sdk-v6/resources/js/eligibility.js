/**
 * Checks payment method eligibility via the v6 SDK.
 *
 * @package
 */

import { FundingSources } from './utils/fundingSources';

/**
 * Checks which payment methods are eligible.
 *
 * @param {Object} sdkInstance           - The PayPal SDK v6 instance.
 * @param {Object} options               - Eligibility options.
 * @param {string} options.currencyCode  - ISO 4217 currency code.
 * @param {string} [options.countryCode] - ISO 3166-1 alpha-2 country code.
 * @param {string} [options.amount]      - Transaction amount, affects Pay Later thresholds.
 * @return {Promise<Object>} Eligibility keyed by method, plus payLaterDetails.
 */
export async function checkEligibility(
	sdkInstance,
	{ currencyCode, countryCode, amount }
) {
	const eligibilityParams = { currencyCode };
	if ( countryCode ) {
		eligibilityParams.countryCode = countryCode;
	}
	if ( amount ) {
		eligibilityParams.amount = amount;
	}

	const methods = await sdkInstance.findEligibleMethods( eligibilityParams );

	const result = {
		[ FundingSources.PAYPAL ]: methods.isEligible( FundingSources.PAYPAL ),
		[ FundingSources.VENMO ]: methods.isEligible( FundingSources.VENMO ),
		[ FundingSources.PAYLATER ]: methods.isEligible(
			FundingSources.PAYLATER
		),
		payLaterDetails: null,
	};

	if ( result[ FundingSources.PAYLATER ] ) {
		try {
			result.payLaterDetails = methods.getDetails(
				FundingSources.PAYLATER
			);
		} catch {
			// getDetails may not be available for all regions.
		}
	}

	return result;
}

/**
 * Checks eligibility for the "save without purchase" (vault) flow used on the
 * My Account › Add Payment Method page. Queries the dedicated vault payment
 * flow; amount-independent.
 *
 * @param {Object} sdkInstance          - The PayPal SDK v6 instance.
 * @param {Object} options              - Eligibility options.
 * @param {string} options.currencyCode - ISO 4217 currency code.
 * @return {Promise<Object>} Eligibility keyed by method (paypal).
 */
export async function checkVaultEligibility( sdkInstance, { currencyCode } ) {
	const methods = await sdkInstance.findEligibleMethods( {
		currencyCode,
		paymentFlow: 'VAULT_WITHOUT_PAYMENT',
	} );

	return {
		paypal: methods.isEligible( 'paypal' ),
	};
}
