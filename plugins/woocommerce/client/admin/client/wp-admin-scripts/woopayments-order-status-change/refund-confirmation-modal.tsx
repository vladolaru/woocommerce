/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { resetOrderStatus } from './order-status-field';

/**
 * Shape of `WC_AJAX::refund_line_items()`'s response. Both outcomes come back
 * as HTTP 200, so `success` — not the transport — is what decides.
 */
interface RefundLineItemsResponse {
	success?: boolean;
	data?: {
		error?: string;
		status?: string;
	};
}

interface RefundConfirmationModalProps {
	/** Status to restore if the merchant backs out or the refund fails. */
	previousStatus: string;
	/** Remaining refundable amount, sent to the refund AJAX. */
	refundAmount: number;
	/** `refundAmount` formatted in the order's currency, for display. */
	formattedRefundAmount: string;
	/** Amount already refunded, echoed back so the server can detect a stale view. */
	refundedAmount: number;
	/** Tear the modal down. */
	onClose: () => void;
	/** Surface a failure to the merchant once the modal is gone. */
	onError: ( message: string ) => void;
}

/**
 * Pull a human-readable message out of whatever `apiFetch` rejected with.
 *
 * `apiFetch` rejects with a plain `{ code, message }` object rather than an
 * `Error` for anything the server answered, so both shapes have to be covered.
 *
 * @param error    The rejection value.
 * @param fallback Message to use when the rejection carries none.
 *
 * @return A message safe to show to the merchant.
 */
function toErrorMessage( error: unknown, fallback: string ): string {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof ( error as { message?: unknown } ).message === 'string' &&
		( error as { message: string } ).message
	) {
		return ( error as { message: string } ).message;
	}

	return fallback;
}

/**
 * Confirms a full refund back to the customer's payment method.
 *
 * Core's own "Refunded" status only records a refund locally. Confirming here
 * posts to core's `woocommerce_refund_line_items` AJAX with `api_refund` set,
 * which is what actually sends the money back through the gateway.
 */
export function RefundConfirmationModal( {
	previousStatus,
	refundAmount,
	formattedRefundAmount,
	refundedAmount,
	onClose,
	onError,
}: RefundConfirmationModalProps ) {
	const [ isRefunding, setIsRefunding ] = useState( false );

	const genericErrorMessage = __(
		'Error processing refund. Please try again.',
		'woocommerce'
	);

	const handleCancel = () => {
		resetOrderStatus( previousStatus );
		onClose();
	};

	// A failed refund must not leave the dropdown claiming the order is
	// refunded; the merchant is handed the reason next to the field instead.
	const failWith = ( message: string ) => {
		resetOrderStatus( previousStatus );
		onClose();
		onError( message );
	};

	const handleConfirm = async () => {
		const metaBoxes = window.woocommerce_admin_meta_boxes;

		if (
			! metaBoxes?.ajax_url ||
			! metaBoxes?.post_id ||
			! metaBoxes?.order_item_nonce
		) {
			failWith( genericErrorMessage );
			return;
		}

		setIsRefunding( true );

		try {
			const response = await apiFetch< RefundLineItemsResponse >( {
				url: metaBoxes.ajax_url,
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'woocommerce_refund_line_items',
					order_id: String( metaBoxes.post_id ),
					security: metaBoxes.order_item_nonce,
					refund_amount: String( refundAmount ),
					refunded_amount: String( refundedAmount ),
					// `WC_AJAX::refund_line_items()` compares this against the
					// string 'true'. Anything else silently downgrades the
					// refund to a local-only record — the exact bug this entry
					// exists to prevent.
					api_refund: 'true',
				} ),
			} );

			if ( response?.success ) {
				// The refund rewrites totals, status and order notes all over
				// the screen; a reload is the only honest way to show it.
				window.location.reload();
				return;
			}

			failWith( response?.data?.error || genericErrorMessage );
		} catch ( error ) {
			failWith( toErrorMessage( error, genericErrorMessage ) );
		}
	};

	return (
		<Modal
			title={ __( 'Refund order in full', 'woocommerce' ) }
			className="woocommerce-woopayments-order-status-change__modal"
			isDismissible={ ! isRefunding }
			shouldCloseOnEsc={ ! isRefunding }
			shouldCloseOnClickOutside={ ! isRefunding }
			onRequestClose={ () => {
				// The refund is already on its way to the gateway; closing now
				// would only hide it.
				if ( isRefunding ) {
					return;
				}

				handleCancel();
			} }
		>
			<div aria-busy={ isRefunding }>
				<p>
					{ sprintf(
						/* translators: %s: WooPayments. */
						__(
							"Issue a full refund back to your customer's payment method using %s. This action cannot be undone. To issue a partial refund, cancel and use the Refund button in the order details below.",
							'woocommerce'
						),
						'WooPayments'
					) }
				</p>
				<div className="woocommerce-woopayments-order-status-change__modal-actions">
					<Button
						variant="tertiary"
						disabled={ isRefunding }
						accessibleWhenDisabled
						onClick={ handleCancel }
					>
						{ __( 'Cancel', 'woocommerce' ) }
					</Button>
					<Button
						variant="primary"
						isBusy={ isRefunding }
						disabled={ isRefunding }
						accessibleWhenDisabled
						onClick={ handleConfirm }
					>
						{ sprintf(
							/* translators: %s: Formatted refund amount, e.g. $25.00. */
							__( 'Refund %s', 'woocommerce' ),
							formattedRefundAmount
						) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
