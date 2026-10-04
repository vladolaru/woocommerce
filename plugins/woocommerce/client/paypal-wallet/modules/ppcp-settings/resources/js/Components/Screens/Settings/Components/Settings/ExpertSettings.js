import { __ } from '@wordpress/i18n';
import SettingsCard from '@ppcp-settings/Components/ReusableComponents/SettingsCard';
import {
	Content,
	ContentWrapper,
} from '@ppcp-settings/Components/ReusableComponents/Elements';
import Troubleshooting from './Blocks/Troubleshooting';
import PaypalSettings from './Blocks/PaypalSettings';
import BlueprintExportImport from './Blocks/BlueprintExportImport';
import data from '../../../../../utils/data';

const ExpertSettings = ( { hasContactModule } ) => {
	const { blueprint } = data();

	return (
		<SettingsCard
			icon="icon-settings-expert.svg"
			className="ppcp-r-settings-card ppcp-r-settings-card--expert-settings"
			title={ __( 'Expert Settings', 'woocommerce' ) }
			description={ __(
				'Fine-tune your PayPal experience with advanced options.',
				'woocommerce'
			) }
			actionProps={ {
				key: 'payNowExperience',
			} }
			contentContainer={ false }
		>
			<ContentWrapper>
				<Content>
					<Troubleshooting />
				</Content>

				<Content>
					<PaypalSettings hasContactModule={ hasContactModule } />
				</Content>

				{ blueprint?.isActive && (
					<Content>
						<BlueprintExportImport />
					</Content>
				) }
			</ContentWrapper>
		</SettingsCard>
	);
};

export default ExpertSettings;
