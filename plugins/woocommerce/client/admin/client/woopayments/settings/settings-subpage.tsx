/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import clsx from 'clsx';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { BackButton } from '~/settings-payments/components/buttons/back-button';
import { getSettingsPaymentsProviderAdminPath } from '../admin/utils';

/**
 * A WooPayments settings subpage under core's offline payments header (settings-payments/index.tsx), with a back
 * arrow that returns to the WooPayments settings page through the Payments settings history.
 */
export const SettingsSubpage = ( {
	headingId,
	title,
	backPath,
	from,
	className,
	isBusy,
	children,
}: {
	headingId: string;
	title: string;
	/** The WooPayments settings route the back arrow returns to, with its query. */
	backPath: string;
	/** The screen the back arrow's Tracks event reports. */
	from: string;
	className?: string;
	isBusy?: boolean;
	children: ReactNode;
} ) => {
	useEffect( () => {
		window.scrollTo( 0, 0 );
	}, [] );

	return (
		<div className="settings-payments-offline__container">
			<div className="settings-payments-offline__header">
				<h1
					id={ headingId }
					className="components-truncate components-text woocommerce-layout__header-heading woocommerce-layout__header-left-align"
				>
					<BackButton
						href={ getSettingsPaymentsProviderAdminPath(
							backPath
						) }
						tooltipText={ __(
							'Return to WooPayments settings',
							'woocommerce'
						) }
						isRoute={ true }
						from={ from }
					>
						<span className="woocommerce-settings-payments-header__title">
							{ title }
						</span>
					</BackButton>
				</h1>
			</div>
			<section
				className={ clsx( 'woopayments-settings-page', className ) }
				aria-labelledby={ headingId }
				aria-busy={ isBusy || undefined }
			>
				{ children }
			</section>
		</div>
	);
};
