/**
 * External dependencies
 */
import CurrencyFactory from '@woocommerce/currency';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../settings/bootstrap';

/**
 * Money formatting ported from client 11.1.0 `includes/multi-currency/client/utils/currency/index.js`, fed by the same
 * localized data: the store country, the zero-decimal currencies and each country's currency format.
 */

type CurrencyFormat = {
	code: string;
	symbol: string;
	symbolPosition: string;
	thousandSeparator: string;
	decimalSeparator: string;
	precision: number;
	defaultLocale?: {
		symbolPosition?: string;
		thousandSeparator?: string;
		decimalSeparator?: string;
	};
};

const getCurrencyData = (): Record< string, CurrencyFormat > => {
	const data = getWooPaymentsSettingsBootstrap().currencyData;
	return data && typeof data === 'object'
		? ( data as Record< string, CurrencyFormat > )
		: {};
};

const getStoreCountry = () => {
	const country = getWooPaymentsSettingsBootstrap().storeCountry;
	return typeof country === 'string' && country ? country : 'US';
};

const findCurrency = ( code: string ) =>
	Object.values( getCurrencyData() ).find(
		( currency ) => currency.code === code
	);

/**
 * Whether the currency has no minor unit (client `isZeroDecimalCurrency()`).
 *
 * @param currencyCode Currency code.
 */
export const isZeroDecimalCurrency = ( currencyCode: string ) => {
	const list = getWooPaymentsSettingsBootstrap().zeroDecimalCurrencies;
	return Array.isArray( list ) && list.includes( currencyCode.toLowerCase() );
};

/**
 * The currency's format, with the separators and symbol position of the base currency or, without one, the store
 * country's currency (client `getCurrency()`, which copies those onto the shared entry; this copies onto a clone).
 *
 * @param currencyCode     Currency code.
 * @param baseCurrencyCode Currency whose separators and symbol position are used.
 */
export const getCurrency = (
	currencyCode: string,
	baseCurrencyCode: string | null = null
) => {
	const found = findCurrency( currencyCode.toUpperCase() );
	if ( ! found ) {
		return null;
	}

	const currencyData = getCurrencyData();
	const currency = { ...found };
	const storeCurrency = currencyData[ getStoreCountry() ];
	if (
		( baseCurrencyCode !== null &&
			baseCurrencyCode.toUpperCase() !== currencyCode.toUpperCase() ) ||
		storeCurrency
	) {
		const baseCurrency = baseCurrencyCode
			? findCurrency( baseCurrencyCode.toUpperCase() )
			: storeCurrency;
		if ( baseCurrency ) {
			currency.decimalSeparator = baseCurrency.decimalSeparator;
			currency.thousandSeparator = baseCurrency.thousandSeparator;
			currency.symbolPosition = baseCurrency.symbolPosition;
		}
	}

	return CurrencyFactory( currency as never );
};

/**
 * The currency's format from its own default locale, ignoring the store country (client `getCurrencyByLocale()`).
 *
 * @param currencyCode Currency code.
 */
export const getCurrencyByLocale = ( currencyCode: string ) => {
	const code = currencyCode.toUpperCase();
	const storeCurrency = getCurrencyData()[ getStoreCountry() ];
	if ( storeCurrency?.code === code ) {
		return CurrencyFactory( storeCurrency as never );
	}

	const found = findCurrency( code );
	if ( ! found ) {
		return null;
	}

	const currency = { ...found };
	const { defaultLocale = {} } = currency;
	if (
		defaultLocale.hasOwnProperty( 'decimalSeparator' ) &&
		defaultLocale.hasOwnProperty( 'thousandSeparator' ) &&
		defaultLocale.hasOwnProperty( 'symbolPosition' )
	) {
		currency.decimalSeparator = defaultLocale.decimalSeparator ?? '';
		currency.thousandSeparator = defaultLocale.thousandSeparator ?? '';
		currency.symbolPosition = defaultLocale.symbolPosition ?? '';
	}

	return CurrencyFactory( currency as never );
};

const htmlDecode = ( input: string ) =>
	new DOMParser().parseFromString( input, 'text/html' ).documentElement
		.textContent ?? '';

const composeFallbackCurrency = (
	amount: number,
	currencyCode: string,
	isZeroDecimal: boolean
) => {
	try {
		return amount.toLocaleString( undefined, {
			style: 'currency',
			currency: currencyCode,
			currencyDisplay: 'narrowSymbol',
		} );
	} catch {
		return isZeroDecimal
			? `${ currencyCode.toUpperCase() } ${ Math.trunc( amount ) }`
			: `${ currencyCode.toUpperCase() } ${ amount.toFixed( 2 ) }`;
	}
};

/**
 * Format an amount in minor units in its currency (client `formatCurrency()`).
 *
 * @param amount              Amount in the currency's minor unit.
 * @param currencyCode        Currency code.
 * @param baseCurrencyCode    Currency whose separators and symbol position are used.
 * @param useLocaleFormatting Whether to use the currency's own default locale instead of the store country's.
 */
export const formatCurrency = (
	amount: number,
	currencyCode = 'USD',
	baseCurrencyCode: string | null = null,
	useLocaleFormatting = false
) => {
	const isZeroDecimal = isZeroDecimalCurrency( currencyCode );
	const value = isZeroDecimal ? amount : amount / 100;

	const isNegative = value < 0;
	const positiveAmount = isNegative ? -1 * value : value;
	const prefix = isNegative ? '-' : '';
	const currency = useLocaleFormatting
		? getCurrencyByLocale( currencyCode )
		: getCurrency( currencyCode, baseCurrencyCode );

	if ( currency === null ) {
		return (
			prefix +
			composeFallbackCurrency(
				positiveAmount,
				currencyCode,
				isZeroDecimal
			)
		);
	}

	try {
		return prefix + htmlDecode( currency.formatAmount( positiveAmount ) );
	} catch {
		return (
			prefix +
			htmlDecode(
				composeFallbackCurrency(
					positiveAmount,
					currencyCode,
					isZeroDecimal
				)
			)
		);
	}
};

/**
 * Trim the currency symbol from a formatted amount (client `removeCurrencySymbol()`).
 *
 * @param formatted Formatted amount.
 */
export const removeCurrencySymbol = ( formatted: string ) =>
	formatted.replace( /[^0-9,.' ]/g, '' ).trim();

/**
 * Append the currency code when the formatted amount lacks it (client `appendCurrencyCode()`).
 *
 * @param formatted    Formatted amount.
 * @param currencyCode Upper-case currency code.
 */
export const appendCurrencyCode = (
	formatted: string,
	currencyCode: string
) =>
	formatted.indexOf( currencyCode ) === -1
		? `${ formatted } ${ currencyCode }`
		: formatted;

/**
 * Trim trailing zeroes from each space-separated chunk (client `trimEndingZeroes()`).
 *
 * @param formatted Formatted amount.
 */
export const trimEndingZeroes = ( formatted = '' ) =>
	formatted
		.split( ' ' )
		.map( ( chunk ) =>
			chunk.endsWith( '0' ) ? chunk.replace( /0+$/, '' ) : chunk
		)
		.join( ' ' );

/**
 * Whether two different currencies share a symbol in the store's currency data (client `hasSameSymbol()`).
 *
 * @param currencyCode1 Currency code.
 * @param currencyCode2 Currency code.
 */
export const hasSameSymbol = (
	currencyCode1: string,
	currencyCode2: string
) => {
	const first = currencyCode1.toUpperCase();
	const second = currencyCode2.toUpperCase();
	if ( first === second ) {
		return false;
	}

	const currency1 = findCurrency( first );
	const currency2 = findCurrency( second );
	if ( ! currency1 || ! currency2 ) {
		return false;
	}

	return currency1.symbol === currency2.symbol;
};

/**
 * Format an amount like formatCurrency(), with the currency code appended when Multi-Currency needs explicit prices
 * (client `formatExplicitCurrency()`).
 *
 * @param amount           Amount in the currency's minor unit.
 * @param currencyCode     Currency code.
 * @param skipSymbol       Whether to trim off the currency symbol.
 * @param baseCurrencyCode Currency whose separators and symbol position are used.
 * @param isExplicit       Whether to add the code; defaults to the localized flag.
 */
export const formatExplicitCurrency = (
	amount: number,
	currencyCode = 'USD',
	skipSymbol = false,
	baseCurrencyCode: string | null = null,
	isExplicit = getWooPaymentsSettingsBootstrap().shouldUseExplicitPrice ===
		true
) => {
	let formatted = formatCurrency( amount, currencyCode, baseCurrencyCode );
	if ( ! isExplicit ) {
		return formatted;
	}
	if ( skipSymbol ) {
		formatted = removeCurrencySymbol( formatted );
	}
	return appendCurrencyCode( formatted, currencyCode.toUpperCase() );
};

type FxSide = { currency?: string; amount?: number };

// Client `formatExchangeRate()`: the rate in the target currency's format, five or six decimals, trailing zeroes trimmed.
const formatExchangeRate = ( from: FxSide, to: FxSide, rate?: number ) => {
	const fromCurrency = from.currency as string;
	const toCurrency = to.currency as string;
	let exchangeRate =
		typeof to.amount === 'number' &&
		typeof from.amount === 'number' &&
		from.amount !== 0
			? Math.abs( to.amount / from.amount )
			: 0;
	if ( typeof rate === 'number' ) {
		exchangeRate = rate;
	}
	if ( isZeroDecimalCurrency( toCurrency ) ) {
		exchangeRate *= 100;
	}
	if ( isZeroDecimalCurrency( fromCurrency ) ) {
		exchangeRate /= 100;
	}

	const exchangeCurrency = CurrencyFactory( {
		...( findCurrency( toCurrency.toUpperCase() ) ?? {} ),
		precision: exchangeRate < 1 ? 6 : 5,
	} as never );

	return appendCurrencyCode(
		trimEndingZeroes(
			removeCurrencySymbol(
				exchangeCurrency.formatAmount( exchangeRate )
			)
		),
		toCurrency.toUpperCase()
	);
};

/**
 * Format a currency conversion line such as `€1.00 → 1.13467 USD: $12.47` (client `formatFX()`).
 *
 * @param from             Source currency and amount in minor units.
 * @param to               Target currency and amount in minor units.
 * @param rate             Exchange rate, when known.
 * @param baseCurrencyCode Currency whose separators and symbol position are used.
 */
export const formatFX = (
	from: FxSide,
	to: FxSide,
	rate?: number,
	baseCurrencyCode: string | null = null
) => {
	if ( ! from.currency || ! to.currency ) {
		return undefined;
	}

	const fromAmount = isZeroDecimalCurrency( from.currency ) ? 1 : 100;
	return `${ formatExplicitCurrency(
		fromAmount,
		from.currency,
		true,
		baseCurrencyCode
	) } → ${ formatExchangeRate( from, to, rate ) }: ${ formatExplicitCurrency(
		Math.abs( to.amount ?? 0 ),
		to.currency,
		false,
		baseCurrencyCode
	) }`;
};
