/**
 * Shared types for the WooPayments order status change confirmation entry.
 */

/**
 * Server-provided context about the order being edited.
 *
 * Localized as `window.woocommerceWooPaymentsOrderStatusChange` on the order
 * edit screen, and only for orders paid through native WooPayments. Keys stay
 * snake_case because they cross the PHP boundary verbatim.
 */
export interface WooPaymentsOrderStatusChangeConfig {
	/**
	 * The persisted order status, `wc-` prefixed so it can be compared against
	 * — and written back into — the `#order_status` dropdown's option values.
	 */
	order_status: string;
	/** Whether the payment behind this order can still be refunded. */
	can_refund: boolean;
	/** Remaining refundable amount, in the order's currency. */
	refund_amount: number;
	/** `refund_amount` already formatted in the order's currency, for display. */
	formatted_refund_amount: string;
	/** Amount already refunded, echoed back to the refund AJAX for its optimistic-lock check. */
	refunded_amount: number;
}

/**
 * What should happen in response to a status selection.
 *
 * Produced by `getOrderStatusChangeDecision()` — see `./strategies`. The
 * discriminant lets the entry point map a decision onto a UI without knowing
 * any of the rules that produced it.
 */
export type OrderStatusChangeDecision =
	/** Let the selection stand; nothing to confirm. */
	| { type: 'none' }
	/** The selection cannot be honoured. Tell the merchant why. */
	| { type: 'error'; message: string }
	/** Ask the merchant to confirm a full refund through the gateway. */
	| {
			type: 'refund-confirmation';
			previousStatus: string;
			refundAmount: number;
			formattedRefundAmount: string;
			refundedAmount: number;
	  }
	/** Ask the merchant whether they meant to refund rather than cancel. */
	| {
			type: 'cancel-confirmation';
			previousStatus: string;
	  };

declare global {
	interface Window {
		woocommerceWooPaymentsOrderStatusChange?: WooPaymentsOrderStatusChangeConfig;
		woocommerce_admin_meta_boxes?: {
			ajax_url: string;
			post_id: number | string;
			order_item_nonce: string;
		};
	}
}
