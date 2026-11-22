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
 * Convert a Store API amount to the minor unit Stripe bills the currency in.
 *
 * @param {number} price       Amount in the Store API minor unit.
 * @param {Object} priceObject Store API price object with the currency code and source precision.
 * @return {number} Amount in the unit Stripe expects.
 */
export const transformPrice = ( price, priceObject = {} ) => {
	const checkout = getCheckoutParams();

	const currency = priceObject?.currency_code?.toLowerCase();
	let stripeMinorUnit = checkout.stripe_minor_unit;
	if ( currency && Array.isArray( checkout.zero_decimal_currencies ) ) {
		stripeMinorUnit = checkout.zero_decimal_currencies.includes( currency )
			? 0
			: 2;
	}

	return expressCheckout.transformPrice( price, priceObject, {
		stripeMinorUnit,
		currencyDecimals: checkout.currency_decimals,
	} );
};
