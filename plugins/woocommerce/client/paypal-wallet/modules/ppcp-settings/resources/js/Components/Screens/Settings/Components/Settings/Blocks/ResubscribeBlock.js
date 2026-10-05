import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';

import { STORE_NAME } from '@ppcp-settings/data/common';
import { ControlButton } from '@ppcp-settings/Components/ReusableComponents/Controls';
import SettingsBlock from '@ppcp-settings/Components/ReusableComponents/SettingsBlock';
import useNotices from '@ppcp-settings/hooks/useNotices';

const ResubscribeBlock = () => {
	const { createSuccessNotice, createErrorNotice } = useNotices();
	const [ resubscribing, setResubscribing ] = useState( false );

	const { resubscribeWebhooks } = useDispatch( STORE_NAME );

	const startResubscribingWebhooks = async () => {
		setResubscribing( true );
		try {
			await resubscribeWebhooks();
		} catch {
			setResubscribing( false );
			createErrorNotice(
				__(
					'Operation failed. Check WooCommerce logs for more details.',
					'woocommerce'
				)
			);
			return;
		}

		setResubscribing( false );
		createSuccessNotice(
			__( 'Webhooks were successfully re-subscribed.', 'woocommerce' )
		);
	};

	return (
		<SettingsBlock
			title={ __( 'Resubscribe webhooks', 'woocommerce' ) }
			description={ __(
				'Click to remove the current webhook subscription and subscribe again, for example, if the website domain or URL structure changed.',
				'woocommerce'
			) }
			horizontalLayout={ true }
			className="ppcp--webhook-resubscribe"
		>
			<ControlButton
				type={ 'secondary' }
				isBusy={ resubscribing }
				onClick={ () => startResubscribingWebhooks() }
				buttonLabel={ __( 'Resubscribe webhooks', 'woocommerce' ) }
			/>
		</SettingsBlock>
	);
};

export default ResubscribeBlock;
