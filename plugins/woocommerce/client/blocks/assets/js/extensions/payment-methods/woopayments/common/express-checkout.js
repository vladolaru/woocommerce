/**
 * Split a wallet's single name into WooCommerce's first and last name.
 *
 * @param {string} name Full name.
 * @return {{first_name: string, last_name: string}} The first word, and the rest.
 */
export const splitName = ( name ) => {
	const parts = ( name || '' ).trim().split( /\s+/ ).filter( Boolean );

	return {
		first_name: parts.shift() || '',
		last_name: parts.join( ' ' ),
	};
};

/**
 * A wallet's name and Stripe address as a Store API address, with only the wallet's fields: one the wallet leaves out,
 * as Apple Pay does with the street before the shopper authorizes, stays empty (client 11.2.0
 * express-checkout/transformers/stripe-to-wc.js:10-23).
 *
 * @param {string} name    The wallet's full name.
 * @param {Object} address Stripe address (line1, line2, postal_code, organization).
 * @return {Object} Store API address without email or phone.
 */
export const getWalletAddress = ( name, address ) => {
	const source = address || {};

	return {
		...splitName( name ),
		company: source.organization || '',
		address_1: source.line1 || '',
		address_2: source.line2 || '',
		city: source.city || '',
		state: source.state || '',
		postcode: source.postal_code || '',
		country: source.country || '',
	};
};

/**
 * Strip the spaces, dashes and parentheses wallets put in phone numbers.
 *
 * @param {string} phone Phone number.
 * @return {string} Digits and the leading plus.
 */
export const normalizePhone = ( phone ) =>
	( phone || '' ).replace( /[() -]/g, '' );

/**
 * The payer's phone from an Express Checkout Element confirm event.
 *
 * @param {Object} event Express Checkout Element event.
 * @return {string} Normalized phone, or ''.
 */
export const getWalletBillingPhone = ( event ) =>
	normalizePhone( event?.billingDetails?.phone || event?.payerPhone );

/**
 * The billing address of an Express Checkout Element confirm event: the wallet's billing details alone (client 11.2.0
 * express-checkout/transformers/stripe-to-wc.js:41-63).
 *
 * @param {Object} event Express Checkout Element confirm event.
 * @return {Object} WooCommerce billing address.
 */
export const getBillingAddress = ( event ) => {
	const billingDetails = event?.billingDetails;
	const address = getWalletAddress(
		billingDetails?.name,
		billingDetails?.address
	);

	return {
		...address,
		// Some wallets provide a single name; checkout requires a last name.
		last_name: address.last_name || '-',
		email: billingDetails?.email || '',
		phone: getWalletBillingPhone( event ),
	};
};

const toFiniteNumber = ( value, fallback ) => {
	const number = Number( value );

	return Number.isFinite( number ) ? number : fallback;
};

/**
 * Convert a Store API amount to the minor unit Stripe bills the currency in.
 *
 * Rounds only when narrowing precision; widening and same-scale conversions are already integer-exact.
 *
 * @param {number} price                  Amount in the Store API minor unit.
 * @param {Object} priceObject            Store API price object carrying `currency_minor_unit`.
 * @param {Object} units                  Units from the calling script's express checkout config.
 * @param {number} units.stripeMinorUnit  Minor unit Stripe bills the currency in.
 * @param {number} units.currencyDecimals Store's price decimals.
 * @return {number} Amount in the unit Stripe expects.
 */
export const transformPrice = ( price, priceObject, units ) => {
	const sourceMinorUnit = toFiniteNumber(
		priceObject?.currency_minor_unit ?? units?.currencyDecimals,
		2
	);
	const stripeMinorUnit = toFiniteNumber(
		units?.stripeMinorUnit,
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
