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

const REFUNDS_DOC_URL =
	'https://woocommerce.com/document/woopayments/managing-money/#refunds';

interface CancelConfirmationModalProps {
	/** Status to restore if the merchant backs out. */
	previousStatus: string;
	/** Tear the modal down. */
	onClose: () => void;
}

/**
 * Asks whether the merchant meant to refund rather than cancel.
 *
 * Cancelling an order that still holds refundable money closes it without
 * returning anything to the customer — an easy mistake to make when "refund"
 * is what was actually intended.
 */
export function CancelConfirmationModal( {
	previousStatus,
	onClose,
}: CancelConfirmationModalProps ) {
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
			title={ __( 'Cancel order', 'woocommerce' ) }
			className="woocommerce-woopayments-order-status-change__modal"
			onRequestClose={ handleCancel }
		>
			<p>
				{ createInterpolateElement(
					__(
						'Are you trying to issue a refund for this order? If so, click <doNothing /> and see our documentation on <docsLink />. If you want to mark this order as cancelled without issuing a refund, click <cancelOrder />.',
						'woocommerce'
					),
					{
						doNothing: (
							<strong>
								{ __( 'Do nothing', 'woocommerce' ) }
							</strong>
						),
						cancelOrder: (
							<strong>
								{ __( 'Cancel order', 'woocommerce' ) }
							</strong>
						),
						// `ExternalLink` carries the "(opens in a new tab)"
						// announcement, so the jump out of wp-admin is not a
						// surprise for screen reader users.
						docsLink: (
							<ExternalLink href={ REFUNDS_DOC_URL }>
								{ __( 'how to issue refunds', 'woocommerce' ) }
							</ExternalLink>
						),
					}
				) }
			</p>
			<div className="woocommerce-woopayments-order-status-change__modal-actions">
				<Button variant="tertiary" onClick={ handleCancel }>
					{ __( 'Do nothing', 'woocommerce' ) }
				</Button>
				<Button variant="primary" onClick={ handleConfirm }>
					{ __( 'Cancel order', 'woocommerce' ) }
				</Button>
			</div>
		</Modal>
	);
}
