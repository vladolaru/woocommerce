/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { WooPaymentsDeposit } from './overview/types';
import { formatPayoutStatus } from './overview/utils';
import type { StatusChipType } from './overview/components/status-chip';

/**
 * Client 11.1.0 `deposits/strings.ts:24-32`. `deducted` is a paid withdrawal.
 */
export const payoutStatusLabels: Record< string, string > = {
	paid: __( 'Completed (paid)', 'woocommerce' ),
	deducted: __( 'Completed (deducted)', 'woocommerce' ),
	pending: __( 'Pending', 'woocommerce' ),
	in_transit: __( 'In transit', 'woocommerce' ),
	canceled: __( 'Canceled', 'woocommerce' ),
	failed: __( 'Failed', 'woocommerce' ),
};

/**
 * The client's payout status label, shared by the payouts list, payout details and the overview card.
 * Client 11.1.0 `components/deposit-status-chip/index.tsx:30-35`.
 *
 * @param payout The payout.
 */
export const getPayoutStatusLabel = (
	payout: Pick< WooPaymentsDeposit, 'type' | 'status' >
) => {
	const status =
		payout.type === 'withdrawal' && payout.status === 'paid'
			? 'deducted'
			: payout.status;

	return payoutStatusLabels[ status ] || formatPayoutStatus( payout.status );
};

/**
 * The payout status chip colour. Client 11.1.0 `components/deposit-status-chip/index.tsx:18-24`.
 *
 * @param payout The payout.
 */
export const getPayoutStatusChipType = (
	payout: Pick< WooPaymentsDeposit, 'status' >
): StatusChipType =>
	(
		( {
			pending: 'warning',
			in_transit: 'primary',
			paid: 'success',
			failed: 'error',
			canceled: 'info',
		} ) as Record< string, StatusChipType >
	 )[ payout.status ] || 'primary';

/**
 * The payouts list status filter options, client 11.1.0 `deposits/filters/config.js:12-23`:
 * the status labels without the display-only `deducted`, with `paid` shown as "Completed".
 */
export const PAYOUT_STATUS_FILTER_ELEMENTS = Object.entries(
	payoutStatusLabels
)
	.filter( ( [ status ] ) => status !== 'deducted' )
	.map( ( [ status, label ] ) => ( {
		value: status,
		label: status === 'paid' ? __( 'Completed', 'woocommerce' ) : label,
	} ) );
