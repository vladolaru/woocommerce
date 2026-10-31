/**
 * External dependencies
 */
import { getPaymentMethodData } from '@woocommerce/settings';

/**
 * Internal dependencies
 */
import { expressCheckout } from '../common';

const getCheckoutParams = () =>
	getPaymentMethodData( 'woocommerce_payments', {} )?.expressCheckoutParams
		?.checkout || {};

/**
 * Convert a Store API amount to the minor unit Stripe bills the currency in, with this page's units.
 *
 * @param {number} price       Amount in the Store API minor unit.
 * @param {Object} priceObject Store API price object carrying `currency_minor_unit`.
 * @return {number} Amount in the unit Stripe expects.
 */
export const transformPrice = ( price, priceObject = {} ) => {
	const checkout = getCheckoutParams();

	return expressCheckout.transformPrice( price, priceObject, {
		stripeMinorUnit: checkout.stripe_minor_unit,
		currencyDecimals: checkout.currency_decimals,
	} );
};
