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
import {
	getExpressCheckoutFeatureFlags,
	isExpressCheckoutInPaymentMethodsListSupported,
} from './settings-utils';
import { asSettingsRecord } from '../settings-values';
import type { BooleanSetting } from '../sections/utils';
import {
	useExpressCheckoutInPaymentMethodsEnabledSettings,
	useGetAvailablePaymentMethodIds,
	useGetSettings,
	usePaymentRequestEnabledSettings,
	usePaymentRequestLocations,
} from '../data/hooks';

export const PaymentRequestSettings = () => {
	const settings = asSettingsRecord( useGetSettings() );
	const featureFlags = getExpressCheckoutFeatureFlags( settings );
	const availablePaymentMethodIds =
		useGetAvailablePaymentMethodIds() as string[];
	const isAmazonPayAvailable =
		Array.isArray( availablePaymentMethodIds ) &&
		availablePaymentMethodIds.includes( 'amazon_pay' );
	const supportsPaymentMethodsListMode =
		isExpressCheckoutInPaymentMethodsListSupported( settings );
	const [ isPaymentRequestEnabled, setIsPaymentRequestEnabled ] =
		usePaymentRequestEnabledSettings() as BooleanSetting;
	const [
		isExpressCheckoutInPaymentMethodsEnabled,
		setIsExpressCheckoutInPaymentMethodsEnabled,
	] = useExpressCheckoutInPaymentMethodsEnabledSettings() as BooleanSetting;
	const [ paymentRequestLocations, updatePaymentRequestLocation ] =
		usePaymentRequestLocations() as [
			string[],
			( location: ExpressCheckoutLocation, value: boolean ) => void,
		];
	const isPaymentMethodsListMode =
		supportsPaymentMethodsListMode &&
		isExpressCheckoutInPaymentMethodsEnabled;
	const paymentMethodsListModeHelp =
		featureFlags.amazonPay && isAmazonPayAvailable
			? __(
					'Apple Pay, Google Pay, and Amazon Pay will appear as options in the payment methods list instead of as separate express checkout buttons.',
					'woocommerce'
				)
			: __(
					'Apple Pay and Google Pay will appear as options in the payment methods list instead of as separate express checkout buttons.',
					'woocommerce'
				);

	return (
		<>
			<ExpressCheckoutEnableSection
				methodId="payment_request"
				title={ __( 'Apple Pay / Google Pay', 'woocommerce' ) }
				description={ __(
					'Allow your customers to collect payments via Apple Pay and Google Pay.',
					'woocommerce'
				) }
			>
				<CheckboxControl
					checked={ isPaymentRequestEnabled }
					label={ __(
						'Enable Apple Pay / Google Pay as express payment buttons',
						'woocommerce'
					) }
					help={ __(
						'Show express payment buttons on store pages for faster purchases. Customers with Apple Pay or Google Pay enabled will be able to pay with their preferred wallet.',
						'woocommerce'
					) }
					onChange={ ( value ) =>
						setIsPaymentRequestEnabled( Boolean( value ) )
					}
					__nextHasNoMarginBottom
				/>
				{ supportsPaymentMethodsListMode && (
					<ExpressCheckoutInPaymentMethodsListCheckbox
						checked={ isExpressCheckoutInPaymentMethodsEnabled }
						help={ paymentMethodsListModeHelp }
						onChange={ setIsExpressCheckoutInPaymentMethodsEnabled }
					/>
				) }
				<ExpressCheckoutLocationCheckboxes
					enabledLocations={ paymentRequestLocations }
					isMethodEnabled={ isPaymentRequestEnabled }
					isPaymentMethodsListMode={ isPaymentMethodsListMode }
					onChange={ updatePaymentRequestLocation }
				/>
			</ExpressCheckoutEnableSection>
			<ExpressCheckoutGeneralSection
				description={ __(
					'Configure the display of Apple Pay and Google Pay buttons on your store.',
					'woocommerce'
				) }
			>
				<ExpressCheckoutAppearanceSettings currentMethod="payment_request" />
			</ExpressCheckoutGeneralSection>
		</>
	);
};
