<?php
/**
 * EventOrderResolver class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Order;

/**
 * Finds the order a WooPayments webhook event's charge or payment intent belongs to, and tells whether the event is the
 * order's own payment. It also writes the warning line for a refund or dispute applied as the payment of an order whose
 * payment method is not a WooPayments one.
 *
 * When the event object carries an order key in its metadata, an order with another key is never returned. This keeps an
 * event meant for another site of a multisite network, whose order IDs can collide with this site's, off this site's orders.
 *
 * @since 11.2.0
 * @internal
 */
class EventOrderResolver {

	/**
	 * Reason code of the line written when a refund or dispute is applied as the payment of an order whose payment method
	 * is not a WooPayments one.
	 */
	private const REASON_OWN_PAYMENT_EVENT_ON_ANOTHER_PAYMENT_METHOD = 'own_payment_event_on_another_payment_method';

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
	 * Find the order whose `_intent_id` is the payment intent a charge or refund event object names.
	 *
	 * Only the object's own `payment_intent` field is read, as an ID or an expanded object; an object without one finds
	 * no order.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string,mixed> $event_object Charge or refund object.
	 * @return WC_Order|null
	 */
	public function find_order_by_event_intent( array $event_object ): ?WC_Order {
		$order = $this->find_order_by_payment_meta( '_intent_id', $this->get_payment_intent_field( $event_object ) );

		return $order instanceof WC_Order && $this->does_order_key_match_event_object( $order, $event_object ) ? $order : null;
	}

	/**
	 * Find the order a charge's metadata names, for a charge no order holds as its `_charge_id`.
	 *
	 * Such a charge is not yet an order's own payment, such as a second charge on an order already paid. The caller records
	 * its event while the order read under the lock still does not hold the charge; once it does, the ownership rule
	 * decides. The metadata's order key must match the order's.
	 *
	 * @param array<string,mixed> $charge Charge object.
	 * @return WC_Order|null
	 */
	public function find_order_from_charge_metadata( array $charge ): ?WC_Order {
		return $this->find_order_from_metadata( $charge );
	}

	/**
	 * Get the ID of the order a charge's metadata names when no order has that ID any more.
	 *
	 * The ID was written from an existing order when the payment was made, so one that loads nothing now is an order that
	 * was deleted, and no later delivery of the event can find it. An order that exists, whatever its key, is not deleted.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $charge Charge object.
	 * @return int Deleted order's ID, or 0 when the metadata names no order ID or names an order that exists.
	 */
	public function find_deleted_order_id_from_charge_metadata( array $charge ): int {
		$order_id = $this->get_metadata_order_id( $charge );

		return $order_id > 0 && ! wc_get_order( $order_id ) ? $order_id : 0;
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
		$intent_id = $this->get_payment_intent_field( $event_object );

		return '' !== $intent_id ? $intent_id : (string) $order->get_meta( '_intent_id', true );
	}

	/**
	 * Read an event object's `payment_intent` field, an ID or an expanded object.
	 *
	 * @param array<string,mixed> $event_object Charge, refund, dispute or early fraud warning object.
	 * @return string Payment intent ID, or an empty string when the field names none.
	 */
	private function get_payment_intent_field( array $event_object ): string {
		$payment_intent = $event_object['payment_intent'] ?? null;
		if ( is_array( $payment_intent ) ) {
			$payment_intent = $payment_intent['id'] ?? null;
		}

		return is_string( $payment_intent ) ? $payment_intent : '';
	}

	/**
	 * Tell whether an event's payment intent or charge is the payment of the order it resolved to.
	 *
	 * A paid order's own payment is the one its transaction ID names, by intent ID or charge ID. When that transaction ID
	 * is empty, the order's stored WooPayments payment stands in for it (is_stored_woopayments_payment()). An order not yet
	 * paid is waiting for its payment: the intent it recorded in `_intent_id`, whatever its payment method, or any
	 * WooPayments payment while WooPayments is its payment method. An empty ID matches nothing. Any other event is on
	 * another charge.
	 *
	 * @param WC_Order $order              Order read under the order payment lock.
	 * @param string   $intent_id          The event's payment intent ID.
	 * @param string   $charge_id          The event's charge ID.
	 * @param string   $stand_in_intent_id The event's own payment intent, read from the event and never from the order, or an
	 *                                     empty string when the event may not stand in for an empty transaction ID.
	 * @return bool
	 */
	public function is_own_payment( WC_Order $order, string $intent_id, string $charge_id, string $stand_in_intent_id ): bool {
		if ( $this->is_paid_for_ownership( $order ) ) {
			$transaction_id = (string) $order->get_transaction_id();
			if ( '' === $transaction_id ) {
				return $this->is_stored_woopayments_payment( $order, $stand_in_intent_id, $charge_id );
			}

			return $transaction_id === $intent_id || $transaction_id === $charge_id;
		}

		if ( '' !== $intent_id && $intent_id === (string) $order->get_meta( '_intent_id', true ) ) {
			return true;
		}

		return WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() );
	}

	/**
	 * Tell whether an order counts as paid when an event's ownership is decided: it has a paid status, by
	 * WC_Order::is_paid() and its filter, or a paid date. A paid order's own payment is the one its transaction ID names
	 * (is_own_payment()). The payment lifecycle's paid test is another one (OrderPaymentLifecycleService::has_paid_status()).
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order Order read under the order payment lock.
	 * @return bool
	 */
	public function is_paid_for_ownership( WC_Order $order ): bool {
		return $order->is_paid() || null !== $order->get_date_paid();
	}

	/**
	 * Get the intent that may stand in for a paid order's empty transaction ID in is_own_payment(), from what the caller
	 * read about the payment and the order.
	 *
	 * Only a payment's success, and only on an order in a paid status by the payment lifecycle's own test
	 * (OrderPaymentLifecycleService::has_paid_status(), passed in by the caller), where the lifecycle leaves the order as
	 * it is. On a paid date with a status that is not paid, a success would decide the order's status. A failure, an
	 * authorization, a setup intent or an expiry is never evidence that the stored intent paid the order.
	 *
	 * @since 11.4.0
	 *
	 * @param string $intent_id             The payment's intent ID.
	 * @param bool   $is_payment_success    Whether the caller reports the payment intent's success.
	 * @param bool   $order_has_paid_status Whether the order read under the lock has a paid status by the lifecycle's test.
	 * @return string The intent ID, or an empty string when the payment may not stand in.
	 */
	public function get_stand_in_intent_id( string $intent_id, bool $is_payment_success, bool $order_has_paid_status ): string {
		return $is_payment_success && $order_has_paid_status ? $intent_id : '';
	}

	/**
	 * Tell whether a charge, refund, dispute or early fraud warning event is the payment of the order it resolved to, as
	 * is_own_payment() does, with the event's intent read here.
	 *
	 * A set transaction ID is compared with get_event_intent_id(), which falls back to the order's `_intent_id` when the
	 * event names no intent. An empty one is stood in for only by the event's own `payment_intent` field, so that fallback
	 * never counts as the event's own intent.
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order            $order        Order read under the order payment lock.
	 * @param array<string,mixed> $event_object Charge, refund, dispute or early fraud warning object.
	 * @param string              $charge_id    The event's charge ID.
	 * @return bool
	 */
	public function is_own_payment_for_event( WC_Order $order, array $event_object, string $charge_id ): bool {
		return $this->is_own_payment( $order, $this->get_event_intent_id( $event_object, $order ), $charge_id, $this->get_payment_intent_field( $event_object ) );
	}

	/**
	 * Tell whether an event's intent belongs to a WooPayments attempt the order abandoned when another payment method took
	 * it over (WooPaymentsEarlierAttemptCleanup), as client 11.3.0's is_intent_from_superseded_attempt() does
	 * (class-wc-payments-order-service.php:1366-1380).
	 *
	 * The intent the order stores at this moment is never superseded: the cleanup removed the stored intent, so one stored
	 * since is an attempt the order recorded after the takeover, such as an in-person payment. Otherwise the cleanup's list
	 * names the abandoned intents. An older attempt the order no longer named at the takeover is told apart by its creation
	 * time against the takeover time, while no WooPayments attempt has written the mode back; that compares the platform's
	 * clock with the store's, so an attempt started within their difference can be missed.
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order             Order read under the order payment lock.
	 * @param string   $intent_id         The event's payment intent ID.
	 * @param int      $intent_created_at When the intent was created, as a Unix time; 0 when unknown, which leaves the list only.
	 * @return bool
	 */
	public function is_superseded_attempt( WC_Order $order, string $intent_id, int $intent_created_at ): bool {
		if ( '' === $intent_id || $intent_id === (string) $order->get_meta( '_intent_id', true ) ) {
			return false;
		}

		$superseded_intent_ids = $order->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true );
		if ( is_array( $superseded_intent_ids ) && in_array( $intent_id, $superseded_intent_ids, true ) ) {
			return true;
		}

		if ( '' !== (string) $order->get_meta( '_wcpay_mode', true ) ) {
			return false;
		}

		$taken_over_at = (int) $order->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY, true );

		return 0 < $taken_over_at && 0 < $intent_created_at && $intent_created_at < $taken_over_at;
	}

	/**
	 * Log a refund or dispute event applied as the order's own payment, when the order's payment method is not a
	 * WooPayments one.
	 *
	 * Such an order names a WooPayments payment while its payment method names another gateway, for example after a
	 * merchant changed the payment method or another payment method checked the order out. The line is written whatever
	 * the logging setting, so the case can be counted; nothing else changes.
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order      Order the event changed, read under the order payment lock.
	 * @param string   $event_type Provider event type.
	 * @param string   $charge_id  The event's charge ID.
	 */
	public function log_own_event_on_another_payment_method( WC_Order $order, string $event_type, string $charge_id ): void {
		$payment_method = (string) $order->get_payment_method();
		if ( WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $payment_method ) ) {
			return;
		}

		$printed_payment_method = WooPaymentsLogger::get_loggable_payment_method( $payment_method );
		wc_get_container()->get( WooPaymentsLogger::class )->log_always(
			sprintf( 'own payment event applied on an order of another payment method: order %1$d, %2$s, charge %3$s, order payment method %4$s', $order->get_id(), $event_type, $charge_id, $printed_payment_method ),
			'warning',
			array(
				'order_id'       => $order->get_id(),
				'event_type'     => $event_type,
				'charge_id'      => $charge_id,
				'payment_method' => $printed_payment_method,
				'reason'         => self::REASON_OWN_PAYMENT_EVENT_ON_ANOTHER_PAYMENT_METHOD,
			)
		);
	}

	/**
	 * Tell whether a paid order's stored WooPayments payment is the event's, standing in for an empty transaction ID.
	 *
	 * The order's payment method is a WooPayments gateway, the event's own intent is its `_intent_id`, and its `_charge_id`,
	 * when it stores one, is the event's charge. An empty transaction ID on a paid order means the payment is unknown, not
	 * that there is none: an earlier capture that kept the merchant's status, the WooPayments extension's amount hold
	 * completed by the merchant, or a field cleared by hand. No amount and no stored status are compared.
	 *
	 * @param WC_Order $order              Paid order whose transaction ID is empty.
	 * @param string   $stand_in_intent_id The event's own payment intent, or an empty string when it may not stand in.
	 * @param string   $charge_id          The event's charge ID.
	 * @return bool
	 */
	private function is_stored_woopayments_payment( WC_Order $order, string $stand_in_intent_id, string $charge_id ): bool {
		if ( ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) {
			return false;
		}

		if ( '' === $stand_in_intent_id || $stand_in_intent_id !== (string) $order->get_meta( '_intent_id', true ) ) {
			return false;
		}

		$stored_charge_id = (string) $order->get_meta( '_charge_id', true );

		return '' === $stored_charge_id || $stored_charge_id === $charge_id;
	}

	/**
	 * Find the order named by an event object's metadata, or by its first charge's metadata.
	 *
	 * @param array<string,mixed> $event_object Event object.
	 * @return WC_Order|null
	 */
	private function find_order_from_metadata( array $event_object ): ?WC_Order {
		$order_id = $this->get_metadata_order_id( $event_object );
		if ( 0 === $order_id ) {
			// This includes Stripe Billing intents, which carry an invoice: their invoice event records them (client 11.1.0 webhook processing service :990-993).
			return null;
		}

		$order_key = isset( $event_object['metadata']['order_key'] ) ? (string) $event_object['metadata']['order_key'] : '';
		$order     = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( '' !== $order_key && $order_key !== $order->get_order_key() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Get the ID of the order an event object's metadata names, or its first charge's metadata names.
	 *
	 * @param array<string,mixed> $event_object Event object.
	 * @return int Order ID, or 0 when the metadata names none.
	 */
	private function get_metadata_order_id( array $event_object ): int {
		$metadata = $event_object['metadata'] ?? null;
		if ( ! is_array( $metadata ) ) {
			return 0;
		}

		$order_id = isset( $metadata['order_id'] ) ? absint( $metadata['order_id'] ) : 0;
		if ( 0 === $order_id ) {
			$order_id = absint( $event_object['charges']['data'][0]['metadata']['order_id'] ?? 0 );
		}

		return $order_id;
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
	 * The meta query compares with the table collation, so the found order counts only when its stored value is exactly
	 * the value, as core's download lookup requires an exact match.
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

		$order = $orders[0] ?? null;

		return $order instanceof WC_Order && (string) $order->get_meta( $meta_key, true ) === $meta_value ? $order : null;
	}
}
