import { __, sprintf } from '@wordpress/i18n';

import { ControlToggleButton } from '@ppcp-settings/Components/ReusableComponents/Controls';
import SettingsBlock from '@ppcp-settings/Components/ReusableComponents/SettingsBlock';
import Accordion from '@ppcp-settings/Components/ReusableComponents/AccordionSection';

import SimulationBlock from './SimulationBlock';
import ResubscribeBlock from './ResubscribeBlock';
import HooksListBlock from './HooksListBlock';
import { SettingsHooks } from '@ppcp-settings/data';

const Troubleshooting = () => {
	const { logging, setLogging } = SettingsHooks.useSettings();

	return (
		<Accordion
			className="ppcp--troubleshooting"
			title={ __( 'Troubleshooting', 'woocommerce' ) }
			description={ __(
				'Access tools to help debug and resolve issues.',
				'woocommerce'
			) }
		>
			<SettingsBlock>
				<ControlToggleButton
					label={ __( 'Logging', 'woocommerce' ) }
					description={ sprintf(
						__(
							'Log additional debugging information in the WooCommerce logs that can assist technical staff to determine issues. <a href="%s" target="_blank" rel="noopener noreferrer">View logs</a>.',
							'woocommerce'
						),
						'admin.php?page=wc-status&tab=logs'
					) }
					value={ logging }
					onChange={ setLogging }
				/>
			</SettingsBlock>

			<SettingsBlock
				title={ __( 'Webhooks', 'woocommerce' ) }
				description={ sprintf(
					__(
						'The following PayPal webhooks are subscribed. More information about the webhooks is available in the <a href="%s">Webhook Status documentation</a>.',
						'woocommerce'
					),
					'https://woocommerce.com/document/woocommerce-paypal-payments/#webhook-status'
				) }
			>
				<HooksListBlock />
				<ResubscribeBlock />
				<SimulationBlock />
			</SettingsBlock>
		</Accordion>
	);
};

export default Troubleshooting;
