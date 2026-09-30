/**
 * External dependencies
 */
import { Pill } from '@woocommerce/components';
import clsx from 'clsx';

/**
 * Internal dependencies
 */
import '../../../../settings-payments/components/status-badge/status-badge.scss';
import './status-chip.scss';

export type StatusChipType =
	| 'success'
	| 'warning'
	| 'error'
	| 'primary'
	| 'info';

/**
 * Maps a platform status colour to a chip type, as client 11.1.0 `components/account-details/utils.ts` `getChipTypeFromColor()` does.
 *
 * @param color The platform `background_color`.
 */
export const getStatusChipTypeFromColor = (
	color?: unknown
): StatusChipType => {
	switch ( color ) {
		case 'green':
			return 'success';
		case 'yellow':
			return 'warning';
		case 'red':
			return 'error';
		case 'gray':
			return 'info';
		default:
			return 'primary';
	}
};

/**
 * A status chip on core's Payments status badge look, standing in for client 11.1.0 `components/chip`.
 */
export const StatusChip = ( {
	message,
	type = 'primary',
}: {
	message: string;
	type?: StatusChipType;
} ) => (
	<Pill
		className={ clsx(
			'woocommerce-status-badge',
			`woocommerce-status-badge--${ type }`
		) }
	>
		{ message }
	</Pill>
);
