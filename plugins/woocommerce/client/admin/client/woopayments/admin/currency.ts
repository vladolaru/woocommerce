/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	formatWooPaymentsAmount,
	normalizeCurrencyCode,
} from './overview/utils';
import { getWooPaymentsSettingsBootstrap } from '../settings/bootstrap';

const CURRENCY_NAMES: Record< string, string > = {
	aud: __( 'Australian dollar', 'woocommerce' ),
	cad: __( 'Canadian dollar', 'woocommerce' ),
	chf: __( 'Swiss franc', 'woocommerce' ),
	dkk: __( 'Danish krone', 'woocommerce' ),
	eur: __( 'Euro', 'woocommerce' ),
	gbp: __( 'Pound sterling', 'woocommerce' ),
	nok: __( 'Norwegian krone', 'woocommerce' ),
	nzd: __( 'New Zealand dollar', 'woocommerce' ),
	sek: __( 'Swedish krona', 'woocommerce' ),
	usd: __( 'United States (US) dollar', 'woocommerce' ),
};

/**
 * The currency's name, or its upper-case code when the client has no name for it.
 * Client 11.1.0 `includes/multi-currency/client/utils/currency/index.js:8-29` `formatCurrencyName()`.
 *
 * @param currencyCode Currency code.
 */
export const formatCurrencyName = ( currencyCode: string ) =>
	CURRENCY_NAMES[ currencyCode.toLowerCase() ] || currencyCode.toUpperCase();

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
