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
 * The classes that give an element core's Payments status badge look, for a trigger that cannot be a `StatusChip`.
 *
 * @param type The chip type.
 */
export const getStatusChipClassName = ( type: StatusChipType ) =>
	`woocommerce-status-badge woocommerce-status-badge--${ type }`;

/**
 * A status chip on core's Payments status badge look, standing in for client 11.1.0 `components/chip`.
 * Core's `Pill` forwards only `className`, so an `id` or `aria-describedby` goes on a span around the message.
 */
export const StatusChip = ( {
	message,
	type = 'primary',
	className,
	id,
	'aria-describedby': describedBy,
}: {
	message: string;
	type?: StatusChipType;
	className?: string;
	id?: string;
	'aria-describedby'?: string;
} ) => (
	<Pill className={ clsx( getStatusChipClassName( type ), className ) }>
		{ id || describedBy ? (
			<span id={ id } aria-describedby={ describedBy }>
				{ message }
			</span>
		) : (
			message
		) }
	</Pill>
);
