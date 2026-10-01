/**
 * External dependencies
 */
import { Button, Flex, Modal } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { HorizontalRule } from '@wordpress/primitives';

/**
 * Internal dependencies
 */
import { resetOrderStatus, submitOrderForm } from './order-status-field';
import { TextLink } from '../../woopayments/settings/text-link';

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
						// A plain in-sentence link, as in client 11.1.0 order-status-change-strategies/index.tsx:270-278.
						docsLink: (
							<TextLink href={ REFUNDS_DOC_URL }>
								{ __( 'how to issue refunds', 'woocommerce' ) }
							</TextLink>
						),
					}
				) }
			</p>
			<HorizontalRule className="woocommerce-woopayments-order-status-change__modal-separator" />
			<Flex
				className="woocommerce-woopayments-order-status-change__modal-actions"
				justify="flex-end"
				gap={ 4 }
			>
				<Button variant="secondary" onClick={ handleCancel }>
					{ __( 'Do nothing', 'woocommerce' ) }
				</Button>
				<Button variant="primary" onClick={ handleConfirm }>
					{ __( 'Cancel order', 'woocommerce' ) }
				</Button>
			</Flex>
		</Modal>
	);
}
