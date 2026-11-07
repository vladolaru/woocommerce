/**
 * External dependencies
 */
import { CheckboxControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export type ExpressCheckoutLocation = 'product' | 'cart' | 'checkout';

type LocationCheckboxesProps = {
	enabledLocations: string[];
	isMethodEnabled: boolean;
	isPaymentMethodsListMode?: boolean;
	onChange: ( location: ExpressCheckoutLocation, value: boolean ) => void;
};

export const ExpressCheckoutInlineNotice = ( {
	children,
	status = 'warning',
}: {
	children: ReactNode;
	status?: 'info' | 'warning' | 'error' | 'success';
} ) => (
	<Notice
		className="woopayments-express-checkout-settings__notice"
		status={ status }
		isDismissible={ false }
	>
		{ children }
	</Notice>
);

export const ExpressCheckoutLocationCheckboxes = ( {
	enabledLocations,
	isMethodEnabled,
	isPaymentMethodsListMode = false,
	onChange,
}: LocationCheckboxesProps ) => {
	const locations: Array< [ ExpressCheckoutLocation, string ] > = [
		[ 'product', __( 'Show on product page', 'woocommerce' ) ],
		[ 'cart', __( 'Show on cart page', 'woocommerce' ) ],
		[ 'checkout', __( 'Show on checkout page', 'woocommerce' ) ],
	];

	return (
		<ul className="woopayments-express-checkout-settings__locations">
			{ locations.map( ( [ location, label ] ) => {
				const checked = isPaymentMethodsListMode
					? location === 'checkout'
					: isMethodEnabled && enabledLocations.includes( location );

				return (
					<li key={ location }>
						<CheckboxControl
							checked={ checked }
							disabled={
								isPaymentMethodsListMode || ! isMethodEnabled
							}
							label={ label }
							onChange={ ( value ) =>
								onChange( location, Boolean( value ) )
							}
							__nextHasNoMarginBottom
						/>
					</li>
				);
			} ) }
		</ul>
	);
};

// Client 11.1.0 payment-request-button-preview.js:64-74 (PreviewRequirementsNotice).
export const ExpressCheckoutPreviewFallback = () => (
	<ExpressCheckoutInlineNotice status="info">
		{ __(
			"To preview the express checkout buttons, ensure your store uses HTTPS on a publicly available domain, and you're viewing this page in a Safari or Chrome browser. Your device must be configured to use Apple Pay or Google Pay.",
			'woocommerce'
		) }
	</ExpressCheckoutInlineNotice>
);
