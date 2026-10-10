import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';

import { CommonHooks } from '../../../../../../data';
import { useToggleState } from '../../../../../../hooks/useToggleState';
import ConfirmationModal from '../../../../../ReusableComponents/ConfirmationModal';
import { useNavigation } from '../../../../../../hooks/useNavigation';

const DisconnectButton = () => {
	const { isOpen, setIsOpen } = useToggleState( 'disconnect-merchant' );
	const [ resetFlag, setResetFlag ] = useState( false );
	const { disconnectMerchant } = CommonHooks.useDisconnectMerchant();
	const { goToPluginSettings } = useNavigation();

	const handleOpen = useCallback( () => {
		setIsOpen( true );
	}, [ setIsOpen ] );

	const handleCancel = useCallback( () => {
		setIsOpen( false );
	}, [ setIsOpen ] );

	const handleConfirm = useCallback( async () => {
		await disconnectMerchant( resetFlag );
		goToPluginSettings();
	}, [ disconnectMerchant, resetFlag ] );

	// POC seam (PayPal Wallet in core): WooCommerce manages the connection of a store the platform serves, so there is
	// nothing to disconnect here; the disconnect route refuses it as well.
	if ( window.ppcpSettings?.collecting ) {
		return null;
	}

	const confirmationTitle = __( 'Disconnect from PayPal?', 'woocommerce' );

	return (
		<>
			<Button
				variant="tertiary"
				isDestructive={ true }
				onClick={ handleOpen }
			>
				{ __( 'Disconnect', 'woocommerce' ) }
			</Button>

			{ isOpen && (
				<ConfirmationModal
					className="ppcp--modal-disconnect"
					title={ confirmationTitle }
					description={ __(
						'Disconnecting your account will restart the connection wizard. Are you sure you want to disconnect from your PayPal account?',
						'woocommerce'
					) }
					toggle={ {
						className: 'ppcp--toggle-danger',
						checked: resetFlag,
						onChange: setResetFlag,
						label: __( 'Start over', 'woocommerce' ),
						help: resetFlag
							? __(
									'Attention: The plugin is reset to its initial state!',
									'woocommerce'
							  )
							: __(
									'Disconnect, but preserve all settings',
									'woocommerce'
							  ),
					} }
					confirmLabel={ __( 'Disconnect', 'woocommerce' ) }
					isDestructive={ resetFlag }
					onConfirm={ handleConfirm }
					onCancel={ handleCancel }
				/>
			) }
		</>
	);
};

export default DisconnectButton;
