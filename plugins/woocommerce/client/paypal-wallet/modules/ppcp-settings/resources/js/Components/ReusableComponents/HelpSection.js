import { __ } from '@wordpress/i18n';
import { FeatureSettingsBlock } from './SettingsBlocks';
import { Content, ContentWrapper } from './Elements';
import SettingsCard from './SettingsCard';

const HelpSection = () => {
	return (
		<SettingsCard
			className="ppcp-r-tab-overview-help"
			title={ __( 'Help Center', 'woocommerce' ) }
			description={ __(
				'Access detailed guides and responsive support to streamline setup and enhance your experience.',
				'woocommerce'
			) }
			contentContainer={ false }
		>
			<ContentWrapper>
				<Content>
					<FeatureSettingsBlock
						title={ __(
							'Documentation',
							'woocommerce'
						) }
						description={ __(
							'Find detailed guides and resources to help you set up, manage, and optimize your PayPal integration.',
							'woocommerce'
						) }
						actionProps={ {
							buttons: [
								{
									type: 'tertiary',
									text: __(
										'View full documentation',
										'woocommerce'
									),
									url: 'https://woocommerce.com/document/woocommerce-paypal-payments/',
								},
							],
						} }
					/>
				</Content>

				<Content>
					<FeatureSettingsBlock
						title={ __( 'Support', 'woocommerce' ) }
						description={ __(
							'Need help? Access troubleshooting tips or contact our support team for personalized assistance.',
							'woocommerce'
						) }
						actionProps={ {
							buttons: [
								{
									type: 'tertiary',
									text: __(
										'View support options',
										'woocommerce'
									),
									url: 'https://woocommerce.com/document/woocommerce-paypal-payments/#get-help ',
								},
							],
						} }
					/>
				</Content>
			</ContentWrapper>
		</SettingsCard>
	);
};

export default HelpSection;
