/**
 * Internal dependencies
 */
import { isZeroDecimalCurrency } from './admin/currency-format';

export const getWooPaymentsAmountFromMinorUnits = (
	amount: number,
	currency: string
) => ( isZeroDecimalCurrency( currency ) ? amount : amount / 100 );
