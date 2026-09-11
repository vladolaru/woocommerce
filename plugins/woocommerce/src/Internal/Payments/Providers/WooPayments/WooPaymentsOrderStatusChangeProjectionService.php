<?php
/**
 * WooPaymentsOrderStatusChangeProjectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Order;

/**
 * Projects the order-edit status-change confirmation config consumed by the browser.
 *
 * Moving a WooPayments order to Refunded from the order-edit status dropdown must open a confirmation
 * modal that refunds at the provider, rather than letting core's `wc_order_fully_refunded()` record a
 * local-only refund that never returns money to the customer. This service is the single place that
 * answers "should that confirmation be offered for this order, and with which numbers".
 *
 * It is a pure projection: it reads the order and returns data. It registers no hooks, enqueues no
 * assets, and reads no request state.
 *
 * Authorization-related confirmations - the cancel-authorization and capture-authorization modals the
 * WooPayments extension also raises from this dropdown - are deliberately outside this contract. They
 * depend on the open-authorization signal and its lifecycle, which the manual-capture work owns, and
 * are expected to arrive with that work rather than be half-answered here.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderStatusChangeProjectionService {

	/**
	 * Prefix used by the order-edit status dropdown option values.
	 *
	 * @var string
	 */
	private const ORDER_STATUS_PREFIX = 'wc-';

	/**
	 * The legacy proxy, used for mockable calls to global functions.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy $legacy_proxy The legacy proxy.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Tell whether the status-change confirmation should be offered for an order.
	 *
	 * True only for orders paid through the native WooPayments gateway, including the per-payment-method
	 * gateways whose IDs carry the `woocommerce_payments_` prefix.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return bool True when the confirmation flow applies to this order.
	 *
	 * @since 11.0.0
	 */
	public function should_offer_confirmation( WC_Order $order ): bool {
		$payment_method = (string) $order->get_payment_method();

		return OrderPaymentStore::GATEWAY_ID === $payment_method
			|| str_starts_with( $payment_method, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}

	/**
	 * Project the status-change confirmation config for an order.
	 *
	 * Keys, and what the browser may rely on:
	 *
	 * - `order_status`            (string) The order's current status as the dropdown spells it, i.e.
	 *                             carrying the `wc-` prefix. This is the value to restore into the
	 *                             dropdown when the merchant dismisses the modal.
	 * - `can_refund`              (bool)   Whether the gateway can refund this order at the provider,
	 *                             per the gateway's own predicate. It does not consider how much is
	 *                             left to refund - `refund_amount` answers that separately, so the
	 *                             browser can tell "not refundable" apart from "nothing left".
	 * - `refund_amount`           (float)  Amount still refundable, in the order's currency.
	 * - `formatted_refund_amount` (string) `refund_amount` formatted in the ORDER's currency (not the
	 *                             store's), as plain display text - markup stripped and HTML entities
	 *                             decoded - ready to place in modal copy as text, not as HTML.
	 * - `refunded_amount`         (float)  Amount already refunded, in the order's currency.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return array<string,mixed> The status-change confirmation config.
	 * @phpstan-return array{order_status: string, can_refund: bool, refund_amount: float, formatted_refund_amount: string, refunded_amount: float}
	 *
	 * @since 11.0.0
	 */
	public function get_config( WC_Order $order ): array {
		$refund_amount = (float) $order->get_remaining_refund_amount();

		return array(
			'order_status'            => $this->get_dropdown_order_status( $order ),
			'can_refund'              => $this->can_refund_at_provider( $order ),
			'refund_amount'           => $refund_amount,
			'formatted_refund_amount' => $this->format_in_order_currency( $order, $refund_amount ),
			'refunded_amount'         => (float) $order->get_total_refunded(),
		);
	}

	/**
	 * Get the order status as the order-edit dropdown spells it.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return string
	 */
	private function get_dropdown_order_status( WC_Order $order ): string {
		$status = (string) $order->get_status();

		return str_starts_with( $status, self::ORDER_STATUS_PREFIX ) ? $status : self::ORDER_STATUS_PREFIX . $status;
	}

	/**
	 * Tell whether the order's gateway can refund it at the provider.
	 *
	 * Delegates to the gateway resolved for the order, which is how core itself decides whether to offer
	 * an automatic refund. The native gateway answers this only when it supports refunds and the order
	 * carries a provider charge, so an order without one never gets a modal promising a real refund.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return bool
	 */
	private function can_refund_at_provider( WC_Order $order ): bool {
		$gateway = $this->legacy_proxy->call_function( 'wc_get_payment_gateway_by_order', $order );

		return $gateway instanceof NativeWooPaymentsGateway && $gateway->can_refund_order( $order );
	}

	/**
	 * Format an amount in the order's currency, as display text.
	 *
	 * Prices come out of wc_price() wrapped in markup and with HTML-encoded currency symbols, so the
	 * entities are decoded here: the browser renders this as text, and an undecoded `&#36;25.00` would
	 * otherwise reach the merchant verbatim.
	 *
	 * @param WC_Order $order  Order being edited.
	 * @param float    $amount Amount to format.
	 * @return string
	 */
	private function format_in_order_currency( WC_Order $order, float $amount ): string {
		return html_entity_decode(
			wp_strip_all_tags( (string) wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}
}
