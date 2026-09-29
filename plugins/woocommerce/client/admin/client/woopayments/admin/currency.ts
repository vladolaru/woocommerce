/**
 * Internal dependencies
 */
import {
	formatWooPaymentsAmount,
	normalizeCurrencyCode,
} from './overview/utils';
import { getWooPaymentsSettingsBootstrap } from '../settings/bootstrap';

export const formatAmount = ( amount?: number, currency?: string ) =>
	typeof amount === 'number'
		? formatWooPaymentsAmount( amount, currency )
		: '-';

/**
 * Whether admin amounts carry an explicit currency code, preloaded like the client's `wcpaySettings.shouldUseExplicitPrice`.
 */
export const shouldUseExplicitPrice = () =>
	getWooPaymentsSettingsBootstrap().shouldUseExplicitPrice === true;

/**
 * Client 11.1.0 `multi-currency/client/utils/currency/index.js:232-244` `formatExplicitCurrency()`: the amount with its
 * currency code appended when Multi-Currency needs it, and optionally without the currency symbol.
 *
 * @param amount     Amount in the currency's minor unit.
 * @param currency   Currency code.
 * @param skipSymbol Whether to trim off the currency symbol, like the client's `removeCurrencySymbol()`.
 * @param isExplicit Whether to add the code; defaults to the preloaded flag, for screens that localize it themselves.
 */
export const formatExplicitCurrency = (
	amount?: number,
	currency?: string | null,
	skipSymbol = false,
	isExplicit = shouldUseExplicitPrice()
) => {
	const formatted = formatAmount( amount, currency ?? undefined );

	if ( typeof amount !== 'number' || ! isExplicit ) {
		return formatted;
	}

	const currencyCode = normalizeCurrencyCode( currency );
	const value = skipSymbol
		? formatted.replace( /[^0-9,.' ]/g, '' ).trim()
		: formatted;

	return value.includes( currencyCode )
		? value
		: `${ value } ${ currencyCode }`;
};
