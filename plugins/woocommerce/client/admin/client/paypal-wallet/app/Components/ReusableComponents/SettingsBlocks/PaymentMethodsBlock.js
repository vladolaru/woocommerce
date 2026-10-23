import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';

import { PaymentHooks } from '../../../data';
import SettingsBlock from '../SettingsBlock';
import PaymentMethodItemBlock from './PaymentMethodItemBlock';

// The Payments list owns the PayPal gateway's on/off state, so its toggle here only shows that state.
const LOCKED_METHOD_ID = 'ppcp-gateway';

const lockedToggleNote = () =>
	createInterpolateElement(
		__(
			'Turn PayPal Wallet on or off from the <a>Payments list</a>.',
			'woocommerce'
		),
		// eslint-disable-next-line jsx-a11y/anchor-has-content
		{ a: <a href={ window.ppcpSettings?.wcPaymentsTabUrl } /> }
	);

// TODO: This is not a reusable component, as it's connected to the Redux store.
const PaymentMethodsBlock = ( { paymentMethods = [], onTriggerModal } ) => {
	const { changePaymentSettings } = PaymentHooks.useStore();

	const handleSelect = ( methodId, isSelected ) =>
		changePaymentSettings( methodId, {
			enabled: isSelected,
		} );

	if ( ! paymentMethods.length ) {
		return null;
	}

	return (
		<SettingsBlock className="ppcp--grid ppcp-r-settings-block__payment-methods">
			{ paymentMethods
				// Remove empty/invalid payment method entries.
				.filter( ( m ) => m && m.id )
				.map( ( paymentMethod ) => {
					const isToggleLocked =
						paymentMethod.id === LOCKED_METHOD_ID;

					return (
						<PaymentMethodItemBlock
							key={ paymentMethod.id }
							paymentMethod={ paymentMethod }
							isSelected={ paymentMethod.enabled }
							isDisabled={ paymentMethod.isDisabled }
							isToggleLocked={ isToggleLocked }
							toggleNote={
								isToggleLocked ? lockedToggleNote() : null
							}
							disabledMessage={ paymentMethod.disabledMessage }
							onSelect={ ( checked ) =>
								handleSelect( paymentMethod.id, checked )
							}
							onTriggerModal={ () =>
								onTriggerModal?.( paymentMethod.id )
							}
							warningMessages={ paymentMethod.warningMessages }
							warningSeverity={ paymentMethod.warningSeverity }
						/>
					);
				} ) }
		</SettingsBlock>
	);
};

export default PaymentMethodsBlock;
