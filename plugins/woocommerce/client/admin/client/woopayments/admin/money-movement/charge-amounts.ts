/**
 * Internal dependencies
 */
import { getChargeDisputes } from './dispute-utils';
import type { WooPaymentsTransaction } from './types';

export interface WooPaymentsChargeAmounts {
	currency: string;
	amount: number;
	fee: number;
	net: number;
	refunded: number;
}

const toNumber = ( value: unknown ) =>
	typeof value === 'number' && Number.isFinite( value ) ? value : 0;

const sumOf = < T >( items: T[] | undefined, pick: ( item: T ) => unknown ) =>
	( items || [] ).reduce(
		( total, item ) => total + toNumber( pick( item ) ),
		0
	);

// An unexpanded id or a balance transaction without a currency can't be read, so the charge's own fields stand in.
const getBalanceTransaction = ( charge: WooPaymentsTransaction ) =>
	charge.balance_transaction &&
	typeof charge.balance_transaction === 'object' &&
	typeof charge.balance_transaction.currency === 'string' &&
	charge.balance_transaction.currency.trim()
		? charge.balance_transaction
		: undefined;

/**
 * Whether the fee breakdown envelope can be trusted: its fee currency must match the balance transaction's.
 * Client 11.1.0 `utils/charge/index.ts:176-195` `canUseFeeBreakdownData()`.
 *
 * @param charge The charge.
 */
export const canUseFeeBreakdownData = ( charge: WooPaymentsTransaction ) => {
	const feeTotal = charge.fee_breakdown_v1?.totals?.fee;
	if ( ! feeTotal ) {
		return false;
	}

	const chargeCurrency = getBalanceTransaction( charge )?.currency;
	if ( ! chargeCurrency ) {
		return true;
	}

	return feeTotal.currency.toLowerCase() === chargeCurrency.toLowerCase();
};

/**
 * Whether the summary reads its amounts from the fee breakdown envelope.
 * Client 11.1.0 `payment-details/summary/index.tsx:333-336`.
 *
 * @param charge The charge.
 */
export const isUsingFeeBreakdownEnvelope = ( charge: WooPaymentsTransaction ) =>
	canUseFeeBreakdownData( charge ) &&
	!! charge.fee_breakdown_v1?.totals?.net &&
	!! charge.fee_breakdown_v1?.totals?.gross;

/**
 * The charge's amount, fee, refunded and net amounts in the settlement currency, in minor units.
 * Client 11.1.0 `utils/charge/index.ts:201-280` `getChargeAmounts()`: the envelope is the charge's current state; without it,
 * the balance transaction less its refunds and dispute adjustments.
 *
 * @param charge The charge.
 */
export const getChargeAmounts = (
	charge: WooPaymentsTransaction
): WooPaymentsChargeAmounts => {
	const totals = charge.fee_breakdown_v1?.totals;
	if ( isUsingFeeBreakdownEnvelope( charge ) && totals?.fee ) {
		const gross = toNumber( totals.gross?.amount );
		const net = toNumber( totals.net?.amount );
		const totalFee =
			totals.fee_plus_tax?.amount ??
			toNumber( totals.fee.amount ) + toNumber( totals.tax?.amount );

		return {
			currency: totals.fee.currency.toLowerCase(),
			amount: gross,
			fee: totalFee,
			net,
			refunded: gross - net - totalFee,
		};
	}

	const balanceTransaction = getBalanceTransaction( charge );
	const balance = balanceTransaction
		? {
				currency: String( balanceTransaction.currency ),
				amount: toNumber( balanceTransaction.amount ),
				fee: toNumber( balanceTransaction.fee ),
				refunded: 0,
				net: 0,
		  }
		: {
				currency: charge.currency ?? '',
				amount: toNumber( charge.amount ),
				fee: toNumber( charge.application_fee_amount ),
				refunded: 0,
				net: 0,
		  };

	if ( toNumber( charge.amount_refunded ) > 0 ) {
		balance.refunded -= sumOf(
			charge.refunds?.data,
			( refund ) => refund.balance_transaction?.amount
		);
	}

	if ( charge.disputed === true ) {
		const disputeBalanceTransactions = getChargeDisputes( charge ).flatMap(
			( dispute ) => dispute.balance_transactions ?? []
		);
		balance.fee += sumOf(
			disputeBalanceTransactions,
			( transaction ) => transaction.fee
		);
		balance.refunded -= sumOf(
			disputeBalanceTransactions,
			( transaction ) => transaction.amount
		);
	}

	balance.net = balance.amount - balance.fee - balance.refunded;

	return balance;
};

/**
 * The fee before dispute fees, for the summary's fee breakdown.
 * Client 11.1.0 `payment-details/summary/index.tsx:386-418`.
 *
 * @param charge          The charge.
 * @param disputeFeeTotal The summed effective dispute fees.
 */
export const getTransactionFeeBeforeDisputes = (
	charge: WooPaymentsTransaction,
	disputeFeeTotal: number
) => {
	const totals = charge.fee_breakdown_v1?.totals;
	if ( isUsingFeeBreakdownEnvelope( charge ) && totals?.fee ) {
		return {
			fee:
				( totals.fee_plus_tax?.amount ??
					toNumber( totals.fee.amount ) +
						toNumber( totals.tax?.amount ) ) - disputeFeeTotal,
			currency: totals.fee.currency.toLowerCase(),
		};
	}

	const balanceTransaction = getBalanceTransaction( charge );
	if ( balanceTransaction ) {
		return {
			fee: toNumber( balanceTransaction.fee ),
			currency: String( balanceTransaction.currency ),
		};
	}

	return {
		fee: toNumber( charge.application_fee_amount ),
		currency: charge.currency ?? '',
	};
};
