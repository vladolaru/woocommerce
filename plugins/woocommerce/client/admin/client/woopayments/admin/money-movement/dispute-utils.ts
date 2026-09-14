/**
 * Shared dispute selection and ordering for WooPayments charge surfaces.
 */

/**
 * Internal dependencies
 */
import type { WooPaymentsDispute, WooPaymentsTransaction } from './types';
import { getDisputeId } from './utils';

export type WooPaymentsDisputeSource = Pick<
	WooPaymentsTransaction,
	'dispute' | 'disputes'
>;

export interface WooPaymentsDisputeOrder {
	orderById: Record< string, number >;
	orderedDisputes: WooPaymentsDispute[];
	total: number;
}

export interface WooPaymentsDisputeBalanceAdjustments {
	fee: number;
	refunded: number;
}

const isDispute = ( value: unknown ): value is WooPaymentsDispute =>
	!! value && typeof value === 'object' && ! Array.isArray( value );

const getCreatedValue = ( dispute: WooPaymentsDispute ) => {
	const created = Number( dispute.created ?? 0 );

	return Number.isFinite( created ) ? created : 0;
};

/**
 * Get every dispute supplied for a charge-like response.
 *
 * A non-empty plural response is authoritative. The singular field remains a
 * compatibility fallback for older or partially expanded provider payloads.
 */
export const getChargeDisputes = (
	source: WooPaymentsDisputeSource
): WooPaymentsDispute[] => {
	if ( Array.isArray( source.disputes ) && source.disputes.length ) {
		return source.disputes.filter( isDispute );
	}

	return isDispute( source.dispute ) ? [ source.dispute ] : [];
};

/**
 * Order disputes oldest-first and assign stable one-based ordinals by ID.
 */
export const getDisputeOrdinals = (
	source: WooPaymentsDisputeSource
): WooPaymentsDisputeOrder => {
	const orderedDisputes = getChargeDisputes( source ).reduce<
		WooPaymentsDispute[]
	>( ( ordered, dispute ) => {
		const insertionIndex = ordered.findIndex(
			( candidate ) =>
				getCreatedValue( candidate ) > getCreatedValue( dispute )
		);

		if ( insertionIndex === -1 ) {
			return [ ...ordered, dispute ];
		}

		return [
			...ordered.slice( 0, insertionIndex ),
			dispute,
			...ordered.slice( insertionIndex ),
		];
	}, [] );
	const identityCounts = new Map< string, number >();
	const orderById: Record< string, number > = {};

	orderedDisputes.forEach( ( dispute ) => {
		const disputeId = getDisputeId( dispute );
		if ( disputeId ) {
			identityCounts.set(
				disputeId,
				( identityCounts.get( disputeId ) || 0 ) + 1
			);
		}
	} );

	orderedDisputes.forEach( ( dispute, index ) => {
		const disputeId = getDisputeId( dispute );
		if ( disputeId && identityCounts.get( disputeId ) === 1 ) {
			orderById[ disputeId ] = index + 1;
		}
	} );

	return {
		orderById,
		orderedDisputes,
		total: orderedDisputes.length,
	};
};

/**
 * Sum finite legacy balance adjustments across every dispute on a charge.
 */
export const getDisputeBalanceAdjustments = (
	source: WooPaymentsDisputeSource
): WooPaymentsDisputeBalanceAdjustments =>
	getChargeDisputes( source ).reduce(
		( totals, dispute ) => {
			( dispute.balance_transactions || [] ).forEach( ( transaction ) => {
				const fee = transaction.fee;
				const amount = transaction.amount;

				if ( typeof fee === 'number' && Number.isFinite( fee ) ) {
					totals.fee += fee;
				}

				if ( typeof amount === 'number' && Number.isFinite( amount ) ) {
					totals.refunded -= amount;
				}
			} );

			return totals;
		},
		{ fee: 0, refunded: 0 }
	);

export const isDisputeInquiry = ( dispute: WooPaymentsDispute ) =>
	typeof dispute.status === 'string' &&
	dispute.status.startsWith( 'warning' );

export const isDisputeAwaitingResponse = ( dispute: WooPaymentsDispute ) =>
	dispute.status === 'needs_response' ||
	dispute.status === 'warning_needs_response';

export const isDisputeRefundable = ( dispute: WooPaymentsDispute ) =>
	isDisputeInquiry( dispute ) || dispute.status === 'won';

export const getPrimaryDispute = ( source: WooPaymentsDisputeSource ) => {
	const disputes = getChargeDisputes( source );

	return (
		disputes.find( ( dispute ) => isDisputeAwaitingResponse( dispute ) ) ||
		disputes[ 0 ]
	);
};
