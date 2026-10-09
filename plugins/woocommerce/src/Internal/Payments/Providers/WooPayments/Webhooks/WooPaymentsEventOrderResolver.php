<?php
/**
 * WooPaymentsEventOrderResolver class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Order;

/**
 * Finds the order a WooPayments webhook event's charge or payment intent belongs to, and tells whether the event is the
 * order's own payment.
 *
 * When the event object carries an order key in its metadata, an order with another key is never returned. This keeps an
 * event meant for another site of a multisite network, whose order IDs can collide with this site's, off this site's orders.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsEventOrderResolver {

	/**
	 * Find the order whose `_charge_id` is the charge ID.
	 *
	 * When orders share the charge ID, the one created last is found, as client 11.1.0 finds it with the `wc_get_orders()`
	 * defaults (class-wc-payments-db.php:24-31, :87-104).
	 *
	 * @param string              $charge_id    Charge ID.
	 * @param array<string,mixed> $event_object Event object whose metadata order key must match, or an empty array for no key check.
	 * @return WC_Order|null
	 */
	public function find_order_by_charge_id( string $charge_id, array $event_object = array() ): ?WC_Order {
		$order = $this->find_order_by_payment_meta( '_charge_id', $charge_id );

		return $order instanceof WC_Order && $this->does_order_key_match_event_object( $order, $event_object ) ? $order : null;
	}

	/**
	 * Find the order a payment intent event belongs to.
	 *
	 * The order whose `_intent_id` is the intent's ID comes first, when its key matches the event's; otherwise the order
	 * the intent's metadata names, or its first charge's metadata names.
	 *
	 * @param array<string,mixed> $payment_intent Payment intent object.
	 * @return WC_Order|null
	 */
	public function find_order_for_intent_event( array $payment_intent ): ?WC_Order {
		$intent_id = isset( $payment_intent['id'] ) ? (string) $payment_intent['id'] : '';
		$order     = $this->find_order_by_payment_meta( '_intent_id', $intent_id );
		if ( $order instanceof WC_Order && $this->does_order_key_match_event_object( $order, $payment_intent ) ) {
			return $order;
		}

		return $this->find_order_from_metadata( $payment_intent );
	}

	/**
	 * Get the payment intent a charge, refund, dispute or early fraud warning event belongs to.
	 *
	 * The event's `payment_intent` field comes first, as an ID or an expanded object; without one, the order's
	 * `_intent_id` stands in, as client 11.1.0 does for `charge.expired` (class-wc-payments-webhook-processing-service.php:394).
	 *
	 * @param array<string,mixed> $event_object Charge, refund, dispute or early fraud warning object.
	 * @param WC_Order            $order        Order the event resolved to.
	 * @return string Payment intent ID, or an empty string when neither names one.
	 */
	public function get_event_intent_id( array $event_object, WC_Order $order ): string {
		$payment_intent = $event_object['payment_intent'] ?? null;
		if ( is_array( $payment_intent ) ) {
			$payment_intent = $payment_intent['id'] ?? null;
		}
		if ( is_string( $payment_intent ) && '' !== $payment_intent ) {
			return $payment_intent;
		}

		return (string) $order->get_meta( '_intent_id', true );
	}

	/**
	 * Tell whether an event's payment intent or charge is the payment of the order it resolved to.
	 *
	 * A paid order's own payment is the one its transaction ID names, by intent ID or charge ID. An order not yet paid is
	 * waiting for its payment: the intent it recorded in `_intent_id`, whatever its payment method, or any WooPayments
	 * payment while WooPayments is its payment method. An empty ID matches nothing. Any other event is on another charge.
	 *
	 * @param WC_Order $order     Order read under the order payment lock.
	 * @param string   $intent_id The event's payment intent ID.
	 * @param string   $charge_id The event's charge ID.
	 * @return bool
	 */
	public function is_own_payment( WC_Order $order, string $intent_id, string $charge_id ): bool {
		if ( $order->is_paid() || null !== $order->get_date_paid() ) {
			$transaction_id = (string) $order->get_transaction_id();

			return '' !== $transaction_id && ( $transaction_id === $intent_id || $transaction_id === $charge_id );
		}

		if ( '' !== $intent_id && $intent_id === (string) $order->get_meta( '_intent_id', true ) ) {
			return true;
		}

		return WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() );
	}

	/**
	 * Find the order named by an event object's metadata, or by its first charge's metadata.
	 *
	 * @param array<string,mixed> $event_object Event object.
	 * @return WC_Order|null
	 */
	private function find_order_from_metadata( array $event_object ): ?WC_Order {
		$metadata = $event_object['metadata'] ?? null;
		if ( ! is_array( $metadata ) ) {
			return null;
		}

		$order_id  = isset( $metadata['order_id'] ) ? absint( $metadata['order_id'] ) : 0;
		$order_key = isset( $metadata['order_key'] ) ? (string) $metadata['order_key'] : '';
		if ( 0 === $order_id ) {
			$order_id = absint( $event_object['charges']['data'][0]['metadata']['order_id'] ?? 0 );
		}
		if ( 0 === $order_id ) {
			// This includes Stripe Billing intents, which carry an invoice: their invoice event records them (client 11.1.0 webhook processing service :990-993).
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( '' !== $order_key && $order_key !== $order->get_order_key() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Tell whether a found order matches the event's metadata order key, when the event carries one.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param array<string,mixed> $event_object Event object.
	 * @return bool
	 */
	private function does_order_key_match_event_object( WC_Order $order, array $event_object ): bool {
		$order_key = $event_object['metadata']['order_key'] ?? null;

		return ! is_string( $order_key ) || '' === $order_key || $order_key === $order->get_order_key();
	}

	/**
	 * Find the first order whose payment meta key has the value.
	 *
	 * @param string $meta_key   Payment meta key.
	 * @param string $meta_value Payment meta value; an empty value finds no order.
	 * @return WC_Order|null
	 */
	private function find_order_by_payment_meta( string $meta_key, string $meta_value ): ?WC_Order {
		if ( '' === $meta_value ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! is_array( $orders ) ) {
			return null;
		}

		return isset( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}
}
