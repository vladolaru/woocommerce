import { __ } from '@wordpress/i18n';
import classNames from 'classnames';

import SettingsCard from '../../../../ReusableComponents/SettingsCard';
import { CommonHooks } from '../../../../../data';
import SettingsBlock from '../../../../ReusableComponents/SettingsBlock';
import { ControlStaticValue } from '../../../../ReusableComponents/Controls';
import { CardActions } from '../../../../ReusableComponents/Elements';
import DisconnectButton from './Parts/DisconnectButton';
import ConnectionStatusBadge from './Parts/ConnectionStatusBadge';

const ConnectionDescription = () => {
	return (
		<>
			{ __( 'Your PayPal account connection details.', 'woocommerce' ) }
			<CardActions isDimmed={ true }>
				<DisconnectButton />
			</CardActions>
		</>
	);
};

const ConnectionStatus = () => {
	const merchant = CommonHooks.useMerchant();
	const className = classNames( 'ppcp-connection-details ppcp--value-list', {
		'ppcp--type-business': merchant.isBusinessSeller,
		'ppcp--type-casual': merchant.isCasualSeller,
	} );

	return (
		<SettingsCard
			className={ className }
			title={ __( 'Connection status', 'woocommerce' ) }
			description={ <ConnectionDescription /> }
		>
			<SettingsBlock className="ppcp--pull-right">
				<ControlStaticValue
					value={
						<ConnectionStatusBadge
							isActive={ merchant.isConnected }
							isSandbox={ merchant.isSandbox }
							isBusinessSeller={ merchant.isBusinessSeller }
						/>
					}
				/>
			</SettingsBlock>
			<SettingsBlock
				title={ __( 'Merchant ID', 'woocommerce' ) }
				className="ppcp--no-gap"
			>
				<ControlStaticValue value={ merchant.id } showCopy={ true } />
			</SettingsBlock>
			<SettingsBlock title={ __( 'Email address', 'woocommerce' ) }>
				<ControlStaticValue
					value={ merchant.email }
					showCopy={ true }
				/>
			</SettingsBlock>
			<SettingsBlock title={ __( 'Client ID', 'woocommerce' ) }>
				<ControlStaticValue
					value={ merchant.clientId }
					showCopy={ true }
				/>
			</SettingsBlock>
		</SettingsCard>
	);
};

export default ConnectionStatus;
