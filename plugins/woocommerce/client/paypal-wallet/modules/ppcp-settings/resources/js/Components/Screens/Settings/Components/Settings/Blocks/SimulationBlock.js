import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ControlButton } from '@ppcp-settings/Components/ReusableComponents/Controls';
import { CommonHooks } from '@ppcp-settings/data';
import SettingsBlock from '@ppcp-settings/Components/ReusableComponents/SettingsBlock';
import useNotices from '@ppcp-settings/hooks/useNotices';

const sleep = ( ms ) => {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
};

const SimulationBlock = () => {
	const {
		createSuccessNotice,
		createInfoNotice,
		createErrorNotice,
		removeNotice,
	} = useNotices();
	const { startWebhookSimulation, checkWebhookSimulationState } =
		CommonHooks.useWebhooks();
	const [ simulating, setSimulating ] = useState( false );
	const startSimulation = async ( maxRetries ) => {
		const webhookInfoNoticeId = 'paypal-webhook-simulation-info-notice';
		const triggerWebhookInfoNotice = () => {
			createInfoNotice(
				__( 'Waiting for the webhook to arrive…', 'woocommerce' ),
				{
					id: webhookInfoNoticeId,
				}
			);
		};

		const stopSimulation = () => {
			removeNotice( webhookInfoNoticeId );
			setSimulating( false );
		};

		setSimulating( true );

		triggerWebhookInfoNotice();

		try {
			await startWebhookSimulation();
		} catch {
			setSimulating( false );
			createErrorNotice(
				__(
					'Operation failed. Check WooCommerce logs for more details.',
					'woocommerce'
				)
			);
			return;
		}

		for ( let i = 0; i < maxRetries; i++ ) {
			await sleep( 2000 );

			const simulationStateResponse = await checkWebhookSimulationState();
			try {
				if ( ! simulationStateResponse.success ) {
					continue;
				}

				if ( simulationStateResponse?.data?.state === 'received' ) {
					createSuccessNotice(
						__(
							'The webhook was received successfully.',
							'woocommerce'
						)
					);
					stopSimulation();
					return;
				}
				removeNotice( webhookInfoNoticeId );
				triggerWebhookInfoNotice();
			} catch {
				// Ignore a failed check and poll again on the next pass.
			}
		}
		stopSimulation();
		createErrorNotice(
			__(
				'Looks like the webhook cannot be received. Check that your website is accessible from the internet.',
				'woocommerce'
			)
		);
	};

	return (
		<SettingsBlock
			title={ __( 'Test webhooks', 'woocommerce' ) }
			description={ __(
				'Send a test-webhook from PayPal to confirm that webhooks are being received and processed correctly.',
				'woocommerce'
			) }
			horizontalLayout={ true }
			className="ppcp--webhook-simulation"
		>
			<ControlButton
				type={ 'secondary' }
				isBusy={ simulating }
				onClick={ () => startSimulation( 30 ) }
				buttonLabel={ __( 'Simulate webhooks', 'woocommerce' ) }
			/>
		</SettingsBlock>
	);
};
export default SimulationBlock;
