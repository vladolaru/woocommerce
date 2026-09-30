/**
 * External dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';
import type { HTMLAttributes, ReactNode } from 'react';

const getLiveStatusAttributes = ( {
	isError,
	isLive,
}: {
	isError: boolean;
	isLive: boolean;
} ): Pick<
	HTMLAttributes< HTMLParagraphElement >,
	'aria-atomic' | 'aria-live' | 'role'
> => {
	if ( ! isLive ) {
		return {};
	}

	return {
		role: isError ? 'alert' : 'status',
		'aria-live': isError ? 'assertive' : 'polite',
		'aria-atomic': true,
	};
};

export const StatusMessage = ( {
	isError = false,
	isLive = false,
	children,
}: {
	isError?: boolean;
	isLive?: boolean;
	children: ReactNode;
} ) => (
	<p
		{ ...getLiveStatusAttributes( { isError, isLive } ) }
		className={
			isError
				? 'woocommerce-woopayments-money-movement__status is-error'
				: 'woocommerce-woopayments-money-movement__status'
		}
	>
		{ children }
	</p>
);

export const LiveStatusMessage = ( {
	isError = false,
	children,
}: {
	isError?: boolean;
	children: ReactNode;
} ) => (
	<p
		className="screen-reader-text"
		role={ isError ? 'alert' : 'status' }
		aria-live={ isError ? 'assertive' : 'polite' }
		aria-atomic="true"
	>
		{ children }
	</p>
);

/**
 * A list's load error or export result on core's `Notice` (monitor ruling N-243).
 *
 * @param props          The notice props.
 * @param props.isError  Whether it reports a failure.
 * @param props.isSpoken Whether the notice announces itself; off where a live region already does.
 * @param props.children The message.
 */
export const ListNotice = ( {
	isError = false,
	isSpoken = true,
	children,
}: {
	isError?: boolean;
	isSpoken?: boolean;
	children: ReactNode;
} ) => (
	<Notice
		status={ isError ? 'error' : 'success' }
		isDismissible={ false }
		{ ...( isSpoken ? {} : { spokenMessage: '' } ) }
	>
		{ children }
	</Notice>
);

/**
 * A list's export button. Client 11.1.0 `components/download-button/index.tsx`.
 *
 * @param props          The button props.
 * @param props.onClick  Starts the export.
 * @param props.isBusy   Whether an export is running.
 * @param props.disabled Whether the button is disabled.
 */
export const ExportButton = ( {
	onClick,
	isBusy = false,
	disabled = false,
}: {
	onClick: () => void;
	isBusy?: boolean;
	disabled?: boolean;
} ) => (
	<Button
		__next40pxDefaultSize
		icon={ download }
		onClick={ onClick }
		isBusy={ isBusy }
		disabled={ disabled }
	>
		{ __( 'Export', 'woocommerce' ) }
	</Button>
);

export const EmptyState = ( { children }: { children: ReactNode } ) => (
	<div className="woocommerce-woopayments-money-movement__empty">
		{ children }
	</div>
);
