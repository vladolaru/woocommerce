/**
 * Thin DOM helpers for the order edit screen's status dropdown.
 *
 * Kept apart from both the decision logic (which must stay DOM-free) and the
 * modal components (which should not each re-derive how to find the field).
 */

export const ORDER_STATUS_FIELD_ID = 'order_status';

/**
 * Get the order status dropdown, if this screen has one.
 *
 * @return The `#order_status` select, or `null` when it is not on the page.
 */
export function getOrderStatusField(): HTMLSelectElement | null {
	return document.querySelector< HTMLSelectElement >(
		`#${ ORDER_STATUS_FIELD_ID }`
	);
}

/**
 * Put the dropdown back to the order's persisted status.
 *
 * Without this, dismissing a confirmation leaves the dropdown showing a status
 * the order does not have — and the merchant can save that lie by hitting
 * Update. The `change` event is dispatched natively so that jQuery listeners
 * (including selectWoo's, which repaints the enhanced select) pick it up.
 *
 * @param previousStatus The status to restore, `wc-` prefixed.
 */
export function resetOrderStatus( previousStatus: string ): void {
	const field = getOrderStatusField();

	if ( ! field ) {
		return;
	}

	field.value = previousStatus;
	field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

/**
 * Submit the order edit form, letting the status change save normally.
 */
export function submitOrderForm(): void {
	const form = getOrderStatusField()?.closest( 'form' );
	if ( ! form ) {
		return;
	}

	// requestSubmit() rather than submit(): the raw DOM submit() bypasses
	// submit handlers, and the order screen binds real behaviour to them —
	// the unsaved-changes guard and the saving overlay among them. Confirming
	// here has to save the order the same way pressing Update does, including
	// whatever handlers an extension has added.
	if ( typeof form.requestSubmit === 'function' ) {
		form.requestSubmit();
		return;
	}

	form.submit();
}
