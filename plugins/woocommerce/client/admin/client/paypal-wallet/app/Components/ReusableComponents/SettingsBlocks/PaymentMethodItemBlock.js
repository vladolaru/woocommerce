import { __, sprintf } from '@wordpress/i18n';
import { FormToggle, Icon, Button } from '@wordpress/components';
import { cog } from '@wordpress/icons';

import WarningMessages from '../../Screens/Settings/Components/Payment/WarningMessages';
import SettingsBlock from '../SettingsBlock';
import PaymentMethodIcon from '../PaymentMethodIcon';

const PaymentMethodItemBlock = ( {
	paymentMethod,
	onTriggerModal,
	onSelect,
	isSelected,
	isDisabled,
	isToggleLocked = false,
	toggleNote = null,
	disabledMessage,
	warningMessages,
	warningSeverity,
} ) => {
	const hasWarning =
		warningMessages && Object.keys( warningMessages ).length > 0;

	// Determine class names based on states
	const methodItemClasses = [
		'ppcp--method-item',
		isDisabled ? 'ppcp--method-item--disabled' : '',
		hasWarning && ! isDisabled ? 'ppcp--method-item--warning' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<SettingsBlock
			id={ paymentMethod.id }
			className={ methodItemClasses }
			separatorAndGap={ false }
			aria-disabled={ isDisabled ? 'true' : 'false' }
		>
			{ isDisabled && (
				<div
					className="ppcp--method-disabled-overlay"
					role="alert"
					aria-live="polite"
				>
					<p className="ppcp--method-disabled-message" tabIndex="0">
						{ disabledMessage }
					</p>
				</div>
			) }
			<div className="ppcp--method-inner">
				<div className="ppcp--method-title-wrapper">
					{ paymentMethod?.icon && (
						<PaymentMethodIcon
							icons={ [ paymentMethod.icon ] }
							type={ paymentMethod.icon }
						/>
					) }
					<span className="ppcp--method-title">
						{ paymentMethod.itemTitle }
					</span>
				</div>
				<p className="ppcp--method-description">
					{ paymentMethod.itemDescription }
				</p>
				<div className="ppcp--method-footer">
					<div className="ppcp--method-toggle-wrapper">
						<FormToggle
							checked={ isSelected }
							onChange={ ( event ) =>
								onSelect( event.target.checked )
							}
							disabled={ isDisabled || isToggleLocked }
							aria-label={ sprintf(
								/* translators: %s: payment method name, such as Venmo. */
								__( 'Enable %s', 'woocommerce' ),
								paymentMethod.itemTitle
							) }
						/>
						{ hasWarning && ! isDisabled && isSelected && (
							<WarningMessages
								warningMessages={ warningMessages }
								severity={ warningSeverity }
							/>
						) }
					</div>
					{ paymentMethod?.fields && onTriggerModal && (
						<Button
							className="ppcp--method-settings"
							disabled={ isDisabled }
							onClick={ onTriggerModal }
							aria-label={ sprintf(
								/* translators: %s: payment method name, such as PayPal. */
								__( 'Configure %s settings', 'woocommerce' ),
								paymentMethod.itemTitle
							) }
						>
							<Icon icon={ cog } />
						</Button>
					) }
				</div>
				{ toggleNote && (
					<p className="ppcp--method-toggle-note">{ toggleNote }</p>
				) }
			</div>
		</SettingsBlock>
	);
};

export default PaymentMethodItemBlock;
