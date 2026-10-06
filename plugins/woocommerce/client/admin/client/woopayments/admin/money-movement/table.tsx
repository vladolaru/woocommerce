/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
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
 * Reports a list read that failed, with the client's copy for that read, as the client's data
 * resolvers do (for example client 11.1.0 `data/transactions/resolvers.js:76-81`).
 *
 * @param message The client's message, such as "Error retrieving transactions.".
 */
export const reportListLoadError = ( message: string ) =>
	(
		dispatch( 'core/notices' ) as unknown as {
			createErrorNotice: ( text: string ) => void;
		}
	 ).createErrorNotice( message );

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
