/**
 * External dependencies
 */
import { Button, ExternalLink, Modal } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { resetOrderStatus, submitOrderForm } from './order-status-field';

const DOCS_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/authorize-and-capture/';

interface AuthorizationConfirmationModalProps {
	/** Whether the new status captures or cancels the authorization. */
	action: 'capture' | 'cancel';
	/** Status to restore if the merchant backs out. */
	previousStatus: string;
	/** Tear the modal down. */
	onClose: () => void;
}

/**
 * Get the copy for each action, ported from client 11.1.0
 * `client/order/order-status-change-strategies/index.tsx:60-196`.
 *
 * @param action Whether the new status captures or cancels the authorization.
 */
function getCopy( action: 'capture' | 'cancel' ) {
	return action === 'capture'
		? {
				title: __( 'Capture payment', 'woocommerce' ),
				confirm: __(
					'Complete order and capture payment',
					'woocommerce'
				),
				newOrderStatus: __( 'completed', 'woocommerce' ),
				actionText: __( 'capture the payment', 'woocommerce' ),
				actionAnchor: 'capturing-authorized-payments',
		  }
		: {
				title: __( 'Cancel payment', 'woocommerce' ),
				confirm: __( 'Cancel order and payment', 'woocommerce' ),
				newOrderStatus: __( 'cancelled', 'woocommerce' ),
				actionText: __( 'cancel the payment', 'woocommerce' ),
				actionAnchor: 'cancelling-authorizations',
		  };
}

/**
 * Confirms that a status change should also capture or cancel an open authorization.
 *
 * Confirming only saves the order; the status change itself triggers the capture or cancel.
 */
export function AuthorizationConfirmationModal( {
	action,
	previousStatus,
	onClose,
}: AuthorizationConfirmationModalProps ) {
	const copy = getCopy( action );

	const handleCancel = () => {
		resetOrderStatus( previousStatus );
		onClose();
	};

	const handleConfirm = () => {
		onClose();
		submitOrderForm();
	};

	return (
		<Modal
			title={ copy.title }
			className="woocommerce-woopayments-order-status-change__modal"
			onRequestClose={ handleCancel }
		>
			<p>
				{ createInterpolateElement(
					__(
						'This order has been <authorizedNotCaptured /> yet. Changing the status to <newOrderStatus /> will also <authorizationAction />. Do you want to continue?',
						'woocommerce'
					),
					{
						authorizedNotCaptured: (
							<ExternalLink
								href={ `${ DOCS_URL }#authorize-vs-capture` }
							>
								{ __(
									'authorized but payment has not been captured',
									'woocommerce'
								) }
							</ExternalLink>
						),
						newOrderStatus: <b>{ copy.newOrderStatus }</b>,
						authorizationAction: (
							<ExternalLink
								href={ `${ DOCS_URL }#${ copy.actionAnchor }` }
							>
								{ copy.actionText }
							</ExternalLink>
						),
					}
				) }
			</p>
			<div className="woocommerce-woopayments-order-status-change__modal-actions">
				<Button variant="secondary" onClick={ handleCancel }>
					{ __( 'Cancel', 'woocommerce' ) }
				</Button>
				<Button variant="primary" onClick={ handleConfirm }>
					{ copy.confirm }
				</Button>
			</div>
		</Modal>
	);
}
