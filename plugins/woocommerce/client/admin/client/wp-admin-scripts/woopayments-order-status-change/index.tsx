/**
 * Confirmation flow for order status changes on native WooPayments orders.
 *
 * Core's "Refunded" status only records a refund locally — `wc_order_fully_refunded()`
 * never asks the gateway for anything — so an order can read as refunded while
 * the customer keeps waiting for their money. This entry intercepts the order
 * edit screen's status dropdown and, for WooPayments orders, confirms the
 * intent before letting that happen: "Refunded" issues a real gateway refund,
 * and "Cancelled" on a still-refundable order asks whether a refund was meant
 * instead. On an open authorization, "Completed" and "Cancelled" confirm the
 * capture or cancel that the status change triggers.
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
import { dispatch } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { AuthorizationConfirmationModal } from './authorization-confirmation-modal';
import { CancelConfirmationModal } from './cancel-confirmation-modal';
import { getOrderStatusChangeDecision } from './strategies';
import { getOrderStatusField } from './order-status-field';
import { RefundConfirmationModal } from './refund-confirmation-modal';
import { WooPaymentsOrderDisputeNotice } from './order-dispute-notice';
import { WooPaymentsOrderTestModeNotice } from './order-test-mode-notice';
import type { WooPaymentsOrderStatusChangeConfig } from './types';

const CONTAINER_CLASS_NAME = 'woocommerce-woopayments-order-status-change';
const PAYMENT_DETAILS_CONTAINER_ID =
	'woocommerce-woopayments-order-payment-details';
let hasBoundEarlyFraudWarningRefundListener = false;

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
	tipTip?: () => void;
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
 * Open Core's existing inline refund panel from an early fraud warning link.
 */
function bindEarlyFraudWarningRefundListener(): void {
	if ( hasBoundEarlyFraudWarningRefundListener ) {
		return;
	}

	document.addEventListener( 'click', ( event ) => {
		if ( ! ( event.target instanceof Element ) ) {
			return;
		}

		const link = event.target.closest( '.wcpay-efw-refund-link' );
		if ( ! ( link instanceof HTMLAnchorElement ) ) {
			return;
		}

		const refundButton = document.querySelector( 'button.refund-items' );
		if (
			! ( refundButton instanceof HTMLButtonElement ) ||
			refundButton.disabled
		) {
			return;
		}

		event.preventDefault();
		refundButton.click();

		const refundPanel =
			document.querySelector( '.wc-order-refund-items' ) ??
			document.querySelector( '#woocommerce-order-items' );
		if ( refundPanel instanceof HTMLElement ) {
			refundPanel.scrollIntoView?.();
		}
	} );

	hasBoundEarlyFraudWarningRefundListener = true;
}

/**
 * Hide core's manual refund button, or explain it, each time the refund panel opens.
 *
 * Client 11.1.0 order/index.js:62-92. The listener sits on the order items box, which
 * both order screens (legacy and HPOS) render, because core replaces its content after item edits.
 *
 * @param disableManualRefunds Whether to hide the button rather than explain it.
 */
function bindManualRefundButton( disableManualRefunds: boolean ): void {
	const orderItems = document.getElementById( 'woocommerce-order-items' );
	if ( ! orderItems ) {
		return;
	}

	orderItems.addEventListener( 'click', ( event ) => {
		if (
			! ( event.target instanceof Element ) ||
			! event.target.closest( 'button.refund-items' )
		) {
			return;
		}

		document
			.querySelectorAll< HTMLElement >( '.do-manual-refund' )
			.forEach( ( manualRefundButton ) => {
				if ( disableManualRefunds ) {
					manualRefundButton.style.display = 'none';
					return;
				}

				// jQuery.tipTip builds the tooltip from the title attribute, so it is regenerated after setting it.
				manualRefundButton.setAttribute(
					'title',
					__(
						'Refunding manually requires reimbursing your customer offline via cash, check, etc. The refund amounts entered here will only be used to balance your analytics.',
						'woocommerce'
					)
				);
				getJQuery()?.( manualRefundButton ).tipTip?.();
			} );
	} );
}

/**
 * Create the mount point for the confirmation modals, next to the status dropdown.
 *
 * @param field The order status dropdown.
 *
 * @return The mount point.
 */
function createContainer( field: HTMLSelectElement ): HTMLDivElement {
	const container = document.createElement( 'div' );
	container.className = CONTAINER_CLASS_NAME;

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

	bindEarlyFraudWarningRefundListener();
	bindManualRefundButton( config.disable_manual_refunds ?? false );

	// Client 11.1.0 order/index.js:128-150: the test-mode notice, then the dispute notice (which also
	// locks refunds), in the mount point PHP prints after the payment info; nothing when it is missing.
	const paymentDetailsContainer = document.getElementById(
		PAYMENT_DETAILS_CONTAINER_ID
	);
	if ( paymentDetailsContainer && ( config.test_mode || config.charge_id ) ) {
		createRoot( paymentDetailsContainer ).render(
			<>
				{ config.test_mode && <WooPaymentsOrderTestModeNotice /> }
				{ config.charge_id && (
					<WooPaymentsOrderDisputeNotice
						chargeId={ config.charge_id }
						onDisableOrderRefund={ ( status ) =>
							disableOrderRefund( status, config )
						}
						shouldUseExplicitPrice={
							config.should_use_explicit_price === true
						}
					/>
				) }
			</>
		);
	}

	const field = getOrderStatusField();

	if ( ! field ) {
		return;
	}

	const root = createRoot( createContainer( field ) );

	const render = ( view: ReactNode ): void => {
		root.render( view );
	};

	const dismiss = (): void => {
		render( null );
	};

	// Client 11.1.0 order-status-change-strategies/index.tsx:204-213 and refund-confirm-modal/index.js:76:
	// an error in the admin notices store, which the WooCommerce admin shows as a snackbar.
	const showError = ( message: string ): void => {
		dismiss();
		void dispatch( noticesStore ).createErrorNotice( message );
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

			case 'authorization-confirmation':
				render(
					<AuthorizationConfirmationModal
						action={ decision.action }
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
