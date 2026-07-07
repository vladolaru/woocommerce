<?php
/**
 * OrderPaymentLifecycleService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Applies neutral payment lifecycle effects to WooCommerce orders.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class OrderPaymentLifecycleService {

	/**
	 * Order payment store.
	 *
	 * @var OrderPaymentStore
	 */
	private OrderPaymentStore $order_payment_store;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentStore $order_payment_store Order payment store.
	 */
	final public function init( OrderPaymentStore $order_payment_store ): void {
		$this->order_payment_store = $order_payment_store;
	}

	/**
	 * Apply a payment lifecycle event to an order.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	public function apply( WC_Order $order, PaymentLifecycleEvent $event ): void {
		$payment_reference = $event->get_payment_reference();
		$locked_by_service = false;

		if ( null !== $payment_reference ) {
			if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $payment_reference ) ) {
				$this->log_skipped_locked_event( $order, $event, $payment_reference );
				return;
			}

			$locked_by_service = true;
		}

		try {
			$this->apply_unlocked( $order, $event );
		} finally {
			if ( $locked_by_service ) {
				$this->order_payment_store->unlock_order_payment( $order );
			}
		}
	}

	/**
	 * Log a warning when a lifecycle event is skipped because the order payment lock is contested.
	 *
	 * Webhook-triggered lifecycle events can arrive while a checkout or capture is mid-flight and
	 * already holds the order payment lock. Skipping is the correct behavior, but a silent drop
	 * leaves the order in a stale state with no diagnostic trail until the reliability service
	 * recovers it. Emit a structured warning so the skip is observable.
	 *
	 * @param WC_Order              $order             Order object.
	 * @param PaymentLifecycleEvent $event             Lifecycle event being skipped.
	 * @param string                $payment_reference Provider payment reference for the event.
	 */
	private function log_skipped_locked_event( WC_Order $order, PaymentLifecycleEvent $event, string $payment_reference ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->warning(
			'Native WooPayments lifecycle event skipped due to order payment lock contention.',
			array(
				'source'            => 'native-payments-webhook',
				'order_id'          => $order->get_id(),
				'payment_reference' => $payment_reference,
				'event_type'        => $event->get_status(),
				'reason'            => 'order_locked',
			)
		);
	}

	/**
	 * Apply a payment lifecycle event while the caller already owns the order payment lock.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event ): void {
		$this->apply_meta_changes( $order, $event );

		$note            = $event->get_note();
		$should_add_note = null !== $note && '' !== $note && ! $this->has_note_marker( $order, $event, $note ) && ! $this->should_skip_lifecycle_note( $order, $event, $note );
		if ( $should_add_note ) {
			$order->update_meta_data( $this->get_note_marker_key( $event, $note ), 'yes' );
		}

		$order->save_meta_data();

		$status_transition_saved_order = $this->apply_status_transition( $order, $event );
		if ( ! $status_transition_saved_order ) {
			$order->save();
		}

		if ( $should_add_note ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Apply meta updates and deletes.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	private function apply_meta_changes( WC_Order $order, PaymentLifecycleEvent $event ): void {
		foreach ( $event->get_meta_to_update() as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		foreach ( $event->get_meta_to_delete() as $key ) {
			$order->delete_meta_data( $key );
		}
	}

	/**
	 * Apply the WooCommerce order status transition for a lifecycle event.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool True when the transition saved the order.
	 */
	private function apply_status_transition( WC_Order $order, PaymentLifecycleEvent $event ): bool {
		switch ( $event->get_status() ) {
			case PaymentLifecycleEvent::STATUS_COMPLETED:
				return (bool) $order->payment_complete( (string) $event->get_payment_reference() );

			case PaymentLifecycleEvent::STATUS_AUTHORIZED:
				if ( null !== $event->get_payment_reference() && '' !== (string) $event->get_payment_reference() ) {
					$order->set_transaction_id( (string) $event->get_payment_reference() );
				}
				$order->update_status( 'on-hold' );
				return true;

			case PaymentLifecycleEvent::STATUS_FAILED:
			case PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED:
				$order->update_status( 'failed' );
				return true;

			case PaymentLifecycleEvent::STATUS_CANCELED:
				$order->update_status( 'cancelled' );
				return true;

			case PaymentLifecycleEvent::STATUS_STARTED:
				return false;
		}

		return false;
	}

	/**
	 * Tell whether the lifecycle note marker is already present.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return bool
	 */
	private function has_note_marker( WC_Order $order, PaymentLifecycleEvent $event, string $note ): bool {
		return 'yes' === $order->get_meta( $this->get_note_marker_key( $event, $note ), true );
	}

	/**
	 * Tell whether a lifecycle note should be skipped for an already-applied event.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return bool
	 */
	private function should_skip_lifecycle_note( WC_Order $order, PaymentLifecycleEvent $event, string $note ): bool {
		$payment_reference = (string) $event->get_payment_reference();
		$note_type         = $event->get_note_type();

		if ( null !== $note_type && $this->has_rendered_note( $order, $note ) ) {
			return true;
		}

		return PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status()
			&& PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE === $note_type
			&& '' !== $payment_reference
			&& $payment_reference === (string) $order->get_transaction_id()
			&& $order->has_status( array( 'processing', 'completed' ) );
	}

	/**
	 * Tell whether the order already has the rendered note content.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $note  Note content.
	 * @return bool
	 */
	private function has_rendered_note( WC_Order $order, string $note ): bool {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $order_note ) {
			if ( $note === (string) $order_note->content ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the internal idempotency marker key for a lifecycle note.
	 *
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return string
	 */
	private function get_note_marker_key( PaymentLifecycleEvent $event, string $note ): string {
		$note_key = $event->get_note_type() ?? $note;

		return '_wc_native_payments_note_' . md5( (string) $event->get_payment_reference() . '|' . $event->get_status() . '|' . $note_key );
	}
}
