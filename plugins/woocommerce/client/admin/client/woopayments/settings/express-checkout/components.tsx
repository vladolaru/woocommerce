/**
 * External dependencies
 */
import { CheckboxControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { SettingsSection } from '../settings-shell';
import { ExpressCheckoutMethodIcons } from './method-icons';
import type { ExpressCheckoutMethod } from './settings-utils';

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

/**
 * The method subpage's first section: the method's icons and description above its enable controls.
 *
 * @param props             The component props.
 * @param props.methodId    The express checkout method.
 * @param props.title       The section's hidden title.
 * @param props.description The translated description shown under the icons.
 * @param props.children    The section's controls.
 */
export const ExpressCheckoutEnableSection = ( {
	methodId,
	title,
	description,
	children,
}: {
	methodId: ExpressCheckoutMethod;
	title: string;
	description: string;
	children: ReactNode;
} ) => (
	<SettingsSection
		className="woopayments-express-checkout-settings__enable"
		title={ title }
		hideTitle
		description={
			<>
				<ExpressCheckoutMethodIcons methodId={ methodId } />
				<p>{ description }</p>
			</>
		}
	>
		{ children }
	</SettingsSection>
);

/**
 * The method subpage's "Settings" section, which holds the button appearance controls.
 *
 * @param props             The component props.
 * @param props.description The translated description of the section.
 * @param props.children    The section's controls.
 */
export const ExpressCheckoutGeneralSection = ( {
	description,
	children,
}: {
	description: string;
	children: ReactNode;
} ) => (
	<SettingsSection
		className="woopayments-express-checkout-settings__general"
		title={ __( 'Settings', 'woocommerce' ) }
		description={ <p>{ description }</p> }
	>
		{ children }
	</SettingsSection>
);

export const ExpressCheckoutInPaymentMethodsListCheckbox = ( {
	checked,
	help,
	onChange,
}: {
	checked: boolean;
	help: string;
	onChange: ( value: boolean ) => void;
} ) => (
	<CheckboxControl
		checked={ checked }
		label={ __(
			'Enable express checkout methods as options in the payment methods list',
			'woocommerce'
		) }
		help={ help }
		onChange={ ( value ) => onChange( Boolean( value ) ) }
		__nextHasNoMarginBottom
	/>
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
