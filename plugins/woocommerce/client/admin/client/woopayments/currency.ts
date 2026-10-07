const ZERO_DECIMAL_PROVIDER_CURRENCY_CODES = new Set( [
	'BIF',
	'CLP',
	'DJF',
	'GNF',
	'JPY',
	'KMF',
	'KRW',
	'MGA',
	'PYG',
	'RWF',
	'VND',
	'VUV',
	'XAF',
	'XOF',
	'XPF',
] );

export const isWooPaymentsZeroDecimalProviderCurrency = ( currency: string ) =>
	ZERO_DECIMAL_PROVIDER_CURRENCY_CODES.has( currency.toUpperCase() );

export const getWooPaymentsAmountFromMinorUnits = (
	amount: number,
	currency: string
) =>
	isWooPaymentsZeroDecimalProviderCurrency( currency )
		? amount
		: amount / 100;
