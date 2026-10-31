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
 * Build a WooCommerce address from a Stripe or WooCommerce one, filling each empty field from the fallback.
 *
 * @param {Object} address  Stripe address (line1, postal_code) or WooCommerce address.
 * @param {Object} fallback WooCommerce address the calling script already holds.
 * @return {Object} WooCommerce address.
 */
export const normalizeAddress = ( address, fallback ) => {
	const source = address || {};
	const known = fallback || {};

	return {
		first_name: source.first_name || known.first_name || '',
		last_name: source.last_name || known.last_name || '',
		company: source.company || known.company || '',
		address_1: source.address_1 || source.line1 || known.address_1 || '',
		address_2: source.address_2 || source.line2 || known.address_2 || '',
		city: source.city || known.city || '',
		state: source.state || known.state || '',
		postcode: source.postcode || source.postal_code || known.postcode || '',
		country: source.country || known.country || '',
		email: source.email || known.email || '',
		phone: source.phone || known.phone || '',
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
 * The billing address of an Express Checkout Element confirm event.
 *
 * @param {Object} event           Express Checkout Element confirm event.
 * @param {Object} fallbackAddress Billing address the calling script holds, for the fields the wallet leaves empty.
 * @return {Object} WooCommerce billing address.
 */
export const getBillingAddress = ( event, fallbackAddress ) => {
	const billingDetails = event?.billingDetails;
	const nameParts = splitName( billingDetails?.name );

	// Some wallets provide a single name; checkout requires a last name.
	nameParts.last_name = nameParts.last_name || '-';

	return normalizeAddress(
		{
			...nameParts,
			email: billingDetails?.email || '',
			phone: getWalletBillingPhone( event ),
			...( billingDetails?.address || {} ),
		},
		fallbackAddress
	);
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
