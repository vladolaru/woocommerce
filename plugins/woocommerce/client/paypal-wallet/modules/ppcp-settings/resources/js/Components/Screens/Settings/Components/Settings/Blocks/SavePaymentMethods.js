import { __ } from '@wordpress/i18n';

import SettingsBlock from '@ppcp-settings/Components/ReusableComponents/SettingsBlock';
import { ControlToggleButton } from '@ppcp-settings/Components/ReusableComponents/Controls';
import { SettingsHooks } from '@ppcp-settings/data';
import { useMerchantInfo } from '@ppcp-settings/data/common/hooks';

const SavePaymentMethods = ( { ownBrandOnly } ) => {
	const {
		savePaypalAndVenmo,
		setSavePaypalAndVenmo,
		saveCardDetails,
		setSaveCardDetails,
	} = SettingsHooks.useSettings();

	const { features } = useMerchantInfo();

	if ( ! features.save_paypal_and_venmo.enabled ) {
		return null;
	}

	return (
		<SettingsBlock
			title={ __(
				'Save payment methods',
				'woocommerce'
			) }
			description={ __(
				"Securely store customers' payment methods for future payments and subscriptions, simplifying checkout and enabling recurring transactions.",
				'woocommerce'
			) }
			className="ppcp--save-payment-methods"
		>
			<ControlToggleButton
				id="ppcp-save-paypal-and-venmo"
				label={ __(
					'Save PayPal and Venmo',
					'woocommerce'
				) }
				description={ __(
					"Securely store your customers' PayPal accounts for a seamless checkout experience.",
					'woocommerce'
				) }
				value={
					features.save_paypal_and_venmo.enabled
						? savePaypalAndVenmo
						: false
				}
				onChange={ setSavePaypalAndVenmo }
				disabled={ ! features.save_paypal_and_venmo.enabled }
			/>

			<ControlToggleButton
				id="ppcp-save-card-details"
				label={ __(
					'Save Credit and Debit Cards',
					'woocommerce'
				) }
				description={ __(
					"Securely store your customer's credit card.",
					'woocommerce'
				) }
				disabled={
					ownBrandOnly || ! features.save_paypal_and_venmo.enabled
				}
				onChange={ setSaveCardDetails }
				value={ saveCardDetails }
			/>
		</SettingsBlock>
	);
};

export default SavePaymentMethods;
