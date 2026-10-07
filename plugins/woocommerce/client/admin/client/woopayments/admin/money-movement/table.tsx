/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';
import type { ReactNode } from 'react';

export const StatusMessage = ( {
	isError = false,
	children,
}: {
	isError?: boolean;
	children: ReactNode;
} ) => (
	<p
		className={
			isError
				? 'woocommerce-woopayments-money-movement__status is-error'
				: 'woocommerce-woopayments-money-movement__status'
		}
	>
		{ children }
	</p>
);

/**
 * The page's spoken status. The polite status region stays mounted and keeps its role, so screen readers
 * announce each change of its text; an error goes to a separate alert, mounted with the error, rather than
 * flipping the status region's role in the same render its text changes.
 *
 * @param props           The component props.
 * @param props.isError   Whether the message is an error.
 * @param props.className The class of the message; screen-reader-only by default.
 * @param props.children  The message.
 */
export const LiveStatusMessage = ( {
	isError = false,
	className = 'screen-reader-text',
	children,
}: {
	isError?: boolean;
	className?: string;
	children: ReactNode;
} ) => (
	<>
		<p
			className={ isError ? 'screen-reader-text' : className }
			role="status"
			aria-live="polite"
			aria-atomic="true"
		>
			{ isError ? null : children }
		</p>
		{ isError && (
			<p className={ className } role="alert">
				{ children }
			</p>
		) }
	</>
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
		// The button disables itself once pressed; keeping it focusable leaves keyboard focus on it, not on the page.
		accessibleWhenDisabled
	>
		{ __( 'Export', 'woocommerce' ) }
	</Button>
);
