/**
 * External dependencies
 */
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ExpressCheckoutAppearanceSettings } from './appearance-settings';
import {
	ExpressCheckoutEnableSection,
	ExpressCheckoutGeneralSection,
	ExpressCheckoutInPaymentMethodsListCheckbox,
	ExpressCheckoutLocationCheckboxes,
	type ExpressCheckoutLocation,
} from './components';
import { isExpressCheckoutInPaymentMethodsListSupported } from './settings-utils';
import { asSettingsRecord } from '../settings-values';
import type { BooleanSetting } from '../sections/utils';
import {
	useAmazonPayEnabledSettings,
	useAmazonPayLocations,
	useExpressCheckoutInPaymentMethodsEnabledSettings,
	useGetSettings,
} from '../data/hooks';

export const AmazonPaySettings = () => {
	const settings = asSettingsRecord( useGetSettings() );
	const supportsPaymentMethodsListMode =
		isExpressCheckoutInPaymentMethodsListSupported( settings );
	const [ isAmazonPayEnabled, setIsAmazonPayEnabled ] =
		useAmazonPayEnabledSettings() as BooleanSetting;
	const [ amazonPayLocations, updateAmazonPayLocation ] =
		useAmazonPayLocations() as [
			string[],
			( location: ExpressCheckoutLocation, value: boolean ) => void,
		];
	const [
		isExpressCheckoutInPaymentMethodsEnabled,
		setIsExpressCheckoutInPaymentMethodsEnabled,
	] = useExpressCheckoutInPaymentMethodsEnabledSettings() as BooleanSetting;
	const isPaymentMethodsListMode =
		supportsPaymentMethodsListMode &&
		isExpressCheckoutInPaymentMethodsEnabled;

	return (
		<>
			<ExpressCheckoutEnableSection
				methodId="amazon_pay"
				title={ __( 'Amazon Pay', 'woocommerce' ) }
				description={ __(
					'Allow your customers to collect payments via Amazon Pay.',
					'woocommerce'
				) }
			>
				<CheckboxControl
					checked={ isAmazonPayEnabled }
					label={ __(
						'Enable Amazon Pay as an express payment button',
						'woocommerce'
					) }
					help={ __(
						'Show Amazon Pay buttons on store pages for faster purchases. Customers with Amazon accounts can use their stored payment information.',
						'woocommerce'
					) }
					onChange={ ( value ) =>
						setIsAmazonPayEnabled( Boolean( value ) )
					}
					__nextHasNoMarginBottom
				/>
				{ supportsPaymentMethodsListMode && (
					<ExpressCheckoutInPaymentMethodsListCheckbox
						checked={ isExpressCheckoutInPaymentMethodsEnabled }
						help={ __(
							'Apple Pay, Google Pay, and Amazon Pay will appear as options in the payment methods list instead of as separate express checkout buttons.',
							'woocommerce'
						) }
						onChange={ setIsExpressCheckoutInPaymentMethodsEnabled }
					/>
				) }
				<ExpressCheckoutLocationCheckboxes
					enabledLocations={ amazonPayLocations }
					isMethodEnabled={ isAmazonPayEnabled }
					isPaymentMethodsListMode={ isPaymentMethodsListMode }
					onChange={ updateAmazonPayLocation }
				/>
			</ExpressCheckoutEnableSection>
			<ExpressCheckoutGeneralSection
				description={ __(
					'Configure the display of Amazon Pay buttons on your store.',
					'woocommerce'
				) }
			>
				<ExpressCheckoutAppearanceSettings
					currentMethod="amazon_pay"
					includeCta={ false }
				/>
			</ExpressCheckoutGeneralSection>
		</>
	);
};
