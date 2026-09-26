/**
 * Decision logic for WooPayments order status changes.
 *
 * Deliberately free of DOM and React: given the status a merchant just picked
 * and the server-provided order context, it answers *what should happen* and
 * nothing else. Every rule in this file is therefore directly testable, and the
 * entry point stays a thin translation from decision to UI.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	OrderStatusChangeDecision,
	WooPaymentsOrderStatusChangeConfig,
} from './types';

/**
 * Status values as they appear in the `#order_status` dropdown, i.e. `wc-` prefixed.
 */
export const ORDER_STATUS_CANCELLED = 'wc-cancelled';
export const ORDER_STATUS_REFUNDED = 'wc-refunded';

/**
 * Decide what should happen when the merchant picks "Refunded".
 *
 * Core's own handling of this status creates a local-only refund record: the
 * order is marked refunded but no money leaves the account. So the selection is
 * only allowed to proceed behind a confirmation that issues a real gateway
 * refund.
 *
 * @param config Server-provided order context.
 *
 * @return The decision for a "Refunded" selection.
 */
function decideRefunded(
	config: WooPaymentsOrderStatusChangeConfig
): OrderStatusChangeDecision {
	// Already refunded: re-picking the current status is a no-op, not a request
	// to refund a second time.
	if ( config.order_status === ORDER_STATUS_REFUNDED ) {
		return { type: 'none' };
	}

	if ( ! config.can_refund ) {
		return {
			type: 'error',
			message: __( 'Order cannot be refunded', 'woocommerce' ),
		};
	}

	if ( config.refund_amount <= 0 ) {
		return {
			type: 'error',
			message: __( 'Invalid refund amount', 'woocommerce' ),
		};
	}

	return {
		type: 'refund-confirmation',
		previousStatus: config.order_status,
		refundAmount: config.refund_amount,
		formattedRefundAmount: config.formatted_refund_amount,
		refundedAmount: config.refunded_amount,
	};
}

/**
 * Decide what should happen when the merchant picks "Cancelled".
 *
 * Cancelling an order that still holds refundable money is usually a mistake
 * for a refund: it closes the order without returning anything to the customer.
 * When there is money left to return, ask which one they meant.
 *
 * @param config Server-provided order context.
 *
 * @return The decision for a "Cancelled" selection.
 */
function decideCancelled(
	config: WooPaymentsOrderStatusChangeConfig
): OrderStatusChangeDecision {
	if ( config.order_status === ORDER_STATUS_CANCELLED ) {
		return { type: 'none' };
	}

	if ( config.can_refund && config.refund_amount > 0 ) {
		return {
			type: 'cancel-confirmation',
			previousStatus: config.order_status,
		};
	}

	// Nothing refundable is at stake, so cancelling is unambiguous.
	return { type: 'none' };
}

/**
 * Decide what should happen when an order's status selection changes.
 *
 * @param newOrderStatus The status the merchant just selected, `wc-` prefixed.
 * @param config         Server-provided order context.
 *
 * @return A discriminated decision describing the UI the entry point should show.
 */
export function getOrderStatusChangeDecision(
	newOrderStatus: string,
	config: WooPaymentsOrderStatusChangeConfig
): OrderStatusChangeDecision {
	switch ( newOrderStatus ) {
		case ORDER_STATUS_REFUNDED:
			return decideRefunded( config );
		case ORDER_STATUS_CANCELLED:
			return decideCancelled( config );
		default:
			// Every other status is core's business; WooPayments has nothing to
			// confirm.
			return { type: 'none' };
	}
}
