/**
 * External dependencies
 */
import { getPaymentMethodData } from '@woocommerce/settings';

const getCheckoutParams = () =>
	getPaymentMethodData( 'woocommerce_payments', {} )?.expressCheckoutParams
		?.checkout || {};

const toFiniteNumber = ( value, fallback ) => {
	const number = Number( value );

	return Number.isFinite( number ) ? number : fallback;
};

/**
 * Convert a Store API amount to the minor unit Stripe bills the currency in.
 *
 * Rounds only when narrowing precision; widening and same-scale conversions are already integer-exact.
 *
 * @param {number} price       Amount in the Store API minor unit.
 * @param {Object} priceObject Store API price object carrying `currency_minor_unit`.
 * @return {number} Amount in the unit Stripe expects.
 */
export const transformPrice = ( price, priceObject = {} ) => {
	const checkout = getCheckoutParams();
	const sourceMinorUnit = toFiniteNumber(
		priceObject.currency_minor_unit ?? checkout.currency_decimals,
		2
	);
	const stripeMinorUnit = toFiniteNumber(
		checkout.stripe_minor_unit,
		sourceMinorUnit
	);
	const converted = price * 10 ** ( stripeMinorUnit - sourceMinorUnit );

	if ( ! Number.isFinite( converted ) ) {
		return 0;
	}

	return stripeMinorUnit < sourceMinorUnit
		? Math.round( converted )
		: converted;
};
