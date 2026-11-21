/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import clsx from 'clsx';
import type { ReactNode } from 'react';

/**
 * Returns `text` one render late, so a live region that shows it is on the page, empty, before its first
 * text arrives. Screen readers do not announce text that is mounted together with its region.
 *
 * @param text The status text.
 */
export const useDeferredStatusText = ( text: string ) => {
	const [ deferredText, setDeferredText ] = useState( '' );

	useEffect( () => {
		setDeferredText( text );
	}, [ text ] );

	return deferredText;
};

export const SettingsBusyState = ( {
	children,
	isBusy,
}: {
	children: ReactNode;
	isBusy: boolean;
} ) => (
	<div
		className={ clsx( 'woopayments-settings-busy-state', {
			'is-busy': isBusy,
		} ) }
	>
		<div
			className="woopayments-settings-busy-state__status screen-reader-text"
			role="status"
			aria-live="polite"
		>
			{ isBusy ? __( 'Saving…', 'woocommerce' ) : '' }
		</div>
		<div
			className="woopayments-settings-busy-state__content"
			aria-busy={ isBusy }
		>
			{ children }
		</div>
	</div>
);
