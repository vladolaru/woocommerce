/**
 * Internal dependencies
 */
import { isZeroDecimalCurrency } from './admin/currency-format';

// The server's list, preloaded as `woopaymentsSettings.zeroDecimalCurrencies`, like every other admin money path.
export const isWooPaymentsZeroDecimalProviderCurrency = isZeroDecimalCurrency;

export const getWooPaymentsAmountFromMinorUnits = (
	amount: number,
	currency: string
) =>
	isWooPaymentsZeroDecimalProviderCurrency( currency )
		? amount
		: amount / 100;
