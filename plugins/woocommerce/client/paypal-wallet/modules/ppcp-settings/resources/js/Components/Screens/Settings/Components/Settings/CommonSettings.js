import { __ } from '@wordpress/i18n';

import SettingsCard from '@ppcp-settings/Components/ReusableComponents/SettingsCard';
import OrderIntent from './Blocks/OrderIntent';
import SavePaymentMethods from './Blocks/SavePaymentMethods';
import InvoicePrefix from './Blocks/InvoicePrefix';
import PayNowExperience from './Blocks/PayNowExperience';
import StayUpdated from './Blocks/StayUpdated';

const CommonSettings = () => {
	return (
		<SettingsCard
			icon="icon-settings-common.svg"
			title={ __( 'Common settings', 'woocommerce' ) }
			className="ppcp-r-settings-card ppcp-r-settings-card--common-settings"
			description={ __(
				'Customize key features to tailor your PayPal experience.',
				'woocommerce'
			) }
		>
			<InvoicePrefix />
			<OrderIntent />
			<SavePaymentMethods />
			<PayNowExperience />
			<StayUpdated />
		</SettingsCard>
	);
};

export default CommonSettings;
