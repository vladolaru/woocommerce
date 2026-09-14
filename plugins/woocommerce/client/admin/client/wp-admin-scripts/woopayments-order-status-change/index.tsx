/**
 * Confirmation flow for order status changes on native WooPayments orders.
 *
 * Core's "Refunded" status only records a refund locally — `wc_order_fully_refunded()`
 * never asks the gateway for anything — so an order can read as refunded while
 * the customer keeps waiting for their money. This entry intercepts the order
 * edit screen's status dropdown and, for WooPayments orders, confirms the
 * intent before letting that happen: "Refunded" issues a real gateway refund,
 * and "Cancelled" on a still-refundable order asks whether a refund was meant
 * instead.
 *
 * Everything here is DOM and React wiring. The rules live in `./strategies`,
 * which is pure and separately tested.
 *
 * The entry no-ops unless PHP localized `window.woocommerceWooPaymentsOrderStatusChange`,
 * which it only does on the order edit screen for native WooPayments orders.
 */

/**
 * External dependencies
 */
import { Notice } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { CancelConfirmationModal } from './cancel-confirmation-modal';
import { getOrderStatusChangeDecision } from './strategies';
import { getOrderStatusField } from './order-status-field';
import { RefundConfirmationModal } from './refund-confirmation-modal';
import { WooPaymentsOrderDisputeNotice } from './order-dispute-notice';
import type { WooPaymentsOrderStatusChangeConfig } from './types';

const CONTAINER_CLASS_NAME = 'woocommerce-woopayments-order-status-change';
const DISPUTE_CONTAINER_CLASS_NAME = `${ CONTAINER_CLASS_NAME }__dispute-notice`;

/**
 * The sliver of jQuery this entry needs.
 *
 * The status dropdown is a `wc-enhanced-select`, and selectWoo announces
 * selections with jQuery's `.trigger( 'change' )` — which never reaches a
 * listener registered with `addEventListener`. Binding through jQuery is
 * therefore the only way to hear the merchant's choice. Typed locally rather
 * than declared on `Window`, so the rest of the codebase's view of `jQuery`
 * is untouched.
 */
type JQueryLike = ( target: Element ) => {
	on: ( eventName: string, handler: () => void ) => void;
};

/**
 * Get jQuery, if WordPress has put it on the page.
 *
 * @return jQuery, or `undefined` outside a WordPress admin page.
 */
function getJQuery(): JQueryLike | undefined {
	return ( window as unknown as { jQuery?: JQueryLike } ).jQuery;
}

/**
 * Listen for status selections, through jQuery when it is available.
 *
 * @param field   The order status dropdown.
 * @param handler Called after every selection.
 */
function onStatusChange( field: HTMLSelectElement, handler: () => void ): void {
	const jQuery = getJQuery();

	if ( jQuery ) {
		jQuery( field ).on( 'change', handler );
		return;
	}

	field.addEventListener( 'change', handler );
}

/**
 * Create the mount point for this entry's UI.
 *
 * Placed next to the status dropdown so that the error notice lands where the
 * merchant is already looking. Modals portal themselves to `<body>` regardless.
 *
 * @param field The order status dropdown.
 *
 * @return The mount point.
 */
function createContainer(
	field: HTMLSelectElement,
	className = CONTAINER_CLASS_NAME
): HTMLDivElement {
	const container = document.createElement( 'div' );
	container.className = className;

	const anchor = field.closest( '.form-field' ) ?? field;
	anchor.parentNode?.insertBefore( container, anchor.nextSibling );

	return container;
}

const getRefundLockMessage = ( status: string ) => {
	if ( status === 'needs_response' ) {
		return __(
			'Refunds and order editing are disabled during disputes.',
			'woocommerce'
		);
	}

	if ( status === 'under_review' ) {
		return __(
			'Refunds and order editing are disabled during an active dispute.',
			'woocommerce'
		);
	}

	if ( status === 'lost' ) {
		return __(
			'Refunds and order editing have been disabled as a result of a lost dispute.',
			'woocommerce'
		);
	}

	if ( status === 'charge_refunded' ) {
		return __(
			'Refunds and order editing have been disabled because the payment was refunded to resolve a dispute.',
			'woocommerce'
		);
	}

	return __(
		'Refunds and order editing are disabled while this payment has a dispute.',
		'woocommerce'
	);
};

const disableOrderRefund = (
	status: string,
	config: WooPaymentsOrderStatusChangeConfig
) => {
	config.can_refund = false;

	const refundButton = document.querySelector( 'button.refund-items' );
	if ( ! ( refundButton instanceof HTMLButtonElement ) ) {
		return;
	}

	const message = getRefundLockMessage( status );
	refundButton.disabled = true;
	refundButton.setAttribute( 'aria-disabled', 'true' );

	const helpTip = refundButton.parentElement?.querySelector(
		'.woocommerce-help-tip'
	);
	if ( helpTip instanceof HTMLElement ) {
		helpTip.title = message;
		helpTip.setAttribute( 'aria-label', message );
	}
};

/**
 * Wire the status dropdown up to the confirmation flow.
 */
function initialize(): void {
	const config = window.woocommerceWooPaymentsOrderStatusChange;

	if ( ! config ) {
		return;
	}

	const field = getOrderStatusField();

	if ( ! field ) {
		return;
	}

	const root = createRoot( createContainer( field ) );
	if ( config.charge_id ) {
		createRoot(
			createContainer( field, DISPUTE_CONTAINER_CLASS_NAME )
		).render(
			<WooPaymentsOrderDisputeNotice
				chargeId={ config.charge_id }
				onDisableOrderRefund={ ( status ) =>
					disableOrderRefund( status, config )
				}
			/>
		);
	}

	const render = ( view: ReactNode ): void => {
		root.render( view );
	};

	const dismiss = (): void => {
		render( null );
	};

	// `Notice` speaks its own content assertively for the error status, so the
	// failure is announced as well as shown.
	const showError = ( message: string ): void => {
		render(
			<Notice status="error" onRemove={ dismiss }>
				{ message }
			</Notice>
		);
	};

	onStatusChange( field, () => {
		const decision = getOrderStatusChangeDecision( field.value, config );

		switch ( decision.type ) {
			case 'error':
				showError( decision.message );
				break;

			case 'refund-confirmation':
				render(
					<RefundConfirmationModal
						previousStatus={ decision.previousStatus }
						refundAmount={ decision.refundAmount }
						formattedRefundAmount={ decision.formattedRefundAmount }
						refundedAmount={ decision.refundedAmount }
						onClose={ dismiss }
						onError={ showError }
					/>
				);
				break;

			case 'cancel-confirmation':
				render(
					<CancelConfirmationModal
						previousStatus={ decision.previousStatus }
						onClose={ dismiss }
					/>
				);
				break;

			default:
				// Nothing to confirm — including on the `change` this entry
				// dispatches itself when it restores the previous status.
				dismiss();
		}
	} );
}

domReady( initialize );
