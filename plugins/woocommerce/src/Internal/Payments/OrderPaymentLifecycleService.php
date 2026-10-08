<?php
/**
 * OrderPaymentLifecycleService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use WC_Order;

/**
 * Applies payment lifecycle events to WooCommerce orders: meta, status and a note added once, under the order payment lock.
 *
 * @since 11.0.0
 * @internal
 */
class OrderPaymentLifecycleService {

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private OrderPaymentLock $order_payment_lock;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService|null
	 */
	private ?WooPaymentsOrderNoteService $order_note_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentLock            $order_payment_lock  Order payment lock.
	 * @param WooPaymentsOrderNoteService $order_note_service  WooPayments order note service.
	 */
	final public function init( OrderPaymentLock $order_payment_lock, ?WooPaymentsOrderNoteService $order_note_service = null ): void {
		$this->order_payment_lock = $order_payment_lock;
		$this->order_note_service = $order_note_service;
	}

	/**
	 * Apply a payment lifecycle event to an order.
	 *
	 * An event with a payment reference runs under the order payment lock. When another operation holds the lock,
	 * the event is skipped with a warning and nothing is written; a caller that can deliver the event again, such as
	 * the webhook path, uses the return value to retry it instead of treating it as handled.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return bool False when the order payment lock refused the event, true otherwise.
	 */
	public function apply( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): bool {
		$payment_reference = $event->get_payment_reference();
		$lock_token        = null;

		if ( null !== $payment_reference ) {
			$lock_token = $this->order_payment_lock->claim( $order, $persistence_vocabulary, $payment_reference, 'payment status update' );
			if ( null === $lock_token ) {
				$this->log_skipped_locked_event( $order, $event, $payment_reference, $persistence_vocabulary );
				return false;
			}
		}

		try {
			$this->apply_under_lock( $order, $event, $persistence_vocabulary );
		} finally {
			if ( null !== $lock_token ) {
				$this->order_payment_lock->release( $order, $persistence_vocabulary, $lock_token );
			}
		}

		return true;
	}

	/**
	 * Get an order read again from its data store, past the post, meta and HPOS order caches.
	 *
	 * The one authoritative re-read for the payments code: another request (a webhook, a redirect return, a checkout of
	 * the same order) may have written the order since this request loaded it. The read goes into a clone, so changes
	 * the caller has not yet saved stay on the order it passed. A caller that writes on the strength of the result holds
	 * the order payment lock.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Order Freshly read order.
	 */
	public function get_fresh_order_from_data_store( WC_Order $order ): WC_Order {
		$fresh_order = clone $order;
		$this->reread_order_from_data_store( $fresh_order );

		return $fresh_order;
	}

	/**
	 * Read an order again from its data store into the same object, past the post, meta and HPOS order caches.
	 *
	 * The same read as get_fresh_order_from_data_store(), for a caller whose later code, and every other holder of the
	 * object, must see what the read returned. Changes not yet saved on the object are discarded.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order object, read again in place.
	 */
	public function reread_order_from_data_store( WC_Order $order ): void {
		$order_id = $order->get_id();
		clean_post_cache( $order_id );
		$order->delete_meta_cache();

		/**
		 * Order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $order->get_data_store();
		if ( is_callable( array( $data_store, 'clear_cached_data' ) ) ) {
			// Only the HPOS data store keeps its own order data cache.
			call_user_func( array( $data_store, 'clear_cached_data' ), array( $order_id ) );
		}
		$data_store->read( $order );
	}

	/**
	 * Log a warning when a lifecycle event is skipped because the order payment lock is contested.
	 *
	 * Webhook-triggered lifecycle events can arrive while a checkout or capture is mid-flight and
	 * already holds the order payment lock. The event is skipped, and the webhook path delivers it
	 * again later; the warning makes each skip observable.
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param PaymentLifecycleEvent                  $event               Lifecycle event being skipped.
	 * @param string                                 $payment_reference   Provider payment reference for the event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 */
	private function log_skipped_locked_event( WC_Order $order, PaymentLifecycleEvent $event, string $payment_reference, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		// Same line as every other lock refusal, so one search finds them all.
		$this->order_payment_lock->log_refusal(
			$order,
			$persistence_vocabulary,
			'payment status update',
			null,
			array(
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
	 * @param WC_Order                               $order               Order object.
	 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 */
	public function apply_under_lock( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		$completed_event_order = PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status() ? $this->get_fresh_order_from_data_store( $order ) : $order;

		if ( $this->should_skip_late_failure_event( $order, $event ) ) {
			return;
		}

		$note                        = $event->get_note();
		$completed_event_skip_reason = $this->get_completed_event_skip_reason( $completed_event_order, $event, $persistence_vocabulary );
		if ( null !== $completed_event_skip_reason ) {
			$this->synchronize_skipped_completed_event_order( $order, $completed_event_order, $completed_event_skip_reason, $persistence_vocabulary );
			$this->log_skipped_completed_event( $order, $event, $completed_event_skip_reason );
			return;
		}

		$this->apply_meta_changes( $order, $event );

		$should_add_note = null !== $note && '' !== $note && ! $this->should_skip_lifecycle_note( $order, $event );

		if ( $this->should_save_meta_before_status_transition( $event ) ) {
			$order->save_meta_data();
		}

		$status_transition_saved_order = $this->apply_status_transition( $order, $event );
		if ( ! $status_transition_saved_order ) {
			$order->save();
		}

		if ( $should_add_note ) {
			$this->get_order_note_service()->add_note_once(
				$order,
				(string) $note,
				$this->get_note_identity( $event, (string) $note ),
				$event->get_note_equivalents()
			);
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
	 * Tell whether lifecycle metadata needs to be saved before status-transition hooks run.
	 *
	 * Every status but started changes the order status, and callbacks on that change may load the order again, so the
	 * event's meta is saved first.
	 *
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool
	 */
	private function should_save_meta_before_status_transition( PaymentLifecycleEvent $event ): bool {
		return PaymentLifecycleEvent::STATUS_STARTED !== $event->get_status();
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
				$order->update_status( OrderStatus::ON_HOLD );
				return true;

			case PaymentLifecycleEvent::STATUS_FAILED:
			case PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED:
				if ( $event->should_preserve_order_status() ) {
					return false;
				}

				$order->update_status( OrderStatus::FAILED );
				return true;

			case PaymentLifecycleEvent::STATUS_CANCELED:
				$order->update_status( OrderStatus::CANCELLED );
				return true;

			case PaymentLifecycleEvent::STATUS_STARTED:
				if ( null !== $event->get_payment_reference() && '' !== (string) $event->get_payment_reference() && '' === (string) $order->get_transaction_id() ) {
					$order->set_transaction_id( (string) $event->get_payment_reference() );
				}
				return false;
		}

		return false;
	}

	/**
	 * Tell whether a late failure event should be ignored for an already-paid order.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool
	 */
	private function should_skip_late_failure_event( WC_Order $order, PaymentLifecycleEvent $event ): bool {
		if (
			! in_array(
				$event->get_status(),
				array(
					PaymentLifecycleEvent::STATUS_FAILED,
					PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
					PaymentLifecycleEvent::STATUS_CANCELED,
				),
				true
			)
		) {
			return false;
		}

		$fresh_order = $this->get_fresh_order_from_data_store( $order );

		return $order->has_status( wc_get_is_paid_statuses() )
			|| $fresh_order->has_status( wc_get_is_paid_statuses() );
	}

	/**
	 * Get the reason a completed lifecycle event must be ignored before it changes the order.
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return string|null Skip reason, or null when the event can be applied.
	 */
	private function get_completed_event_skip_reason( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): ?string {
		if ( PaymentLifecycleEvent::STATUS_COMPLETED !== $event->get_status() ) {
			return null;
		}

		if ( $this->has_open_dispute( $order, $persistence_vocabulary ) ) {
			return 'open_dispute';
		}

		$note = $event->get_note();
		if (
			null === $note
			|| '' === $note
			|| ! $this->get_order_note_service()->has_persisted_note(
				$order,
				$note,
				$this->get_note_identity( $event, $note ),
				$event->get_note_equivalents()
			)
		) {
			return null;
		}

		return 'success_note_exists';
	}

	/**
	 * Bring the persisted status and open disputes onto a caller's order object that a skipped completed event found stale.
	 *
	 * A caller whose object matches the persisted order keeps it untouched. A diverging object is read again in place,
	 * so a later save by the caller cannot write its stale status or dispute meta, and no status transition is recorded
	 * that the save would fire again (a second status email and note, a moved paid date). Its unsaved changes are discarded.
	 *
	 * @param WC_Order                               $order                       Caller-owned order object.
	 * @param WC_Order                               $completed_event_order       Freshly read order used by the completed-event guard.
	 * @param string                                 $completed_event_skip_reason Completed-event skip reason.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary      Provider persistence vocabulary.
	 */
	private function synchronize_skipped_completed_event_order( WC_Order $order, WC_Order $completed_event_order, string $completed_event_skip_reason, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		$is_stale = $order->get_status() !== $completed_event_order->get_status();

		if ( ! $is_stale && 'open_dispute' === $completed_event_skip_reason ) {
			$open_dispute_ids_meta_key = $persistence_vocabulary->get_open_dispute_ids_meta_key();
			$is_stale                  = $order->get_meta( $open_dispute_ids_meta_key, true ) !== $completed_event_order->get_meta( $open_dispute_ids_meta_key, true );
		}

		if ( $is_stale ) {
			$this->reread_order_from_data_store( $order );
		}
	}

	/**
	 * Tell whether the order has an open dispute under the provider's open dispute key.
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return bool
	 */
	private function has_open_dispute( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): bool {
		$open_dispute_ids_meta_key = $persistence_vocabulary->get_open_dispute_ids_meta_key();
		if ( '' === $open_dispute_ids_meta_key ) {
			return false;
		}

		$open_dispute_ids = $order->get_meta( $open_dispute_ids_meta_key, true );

		return is_array( $open_dispute_ids ) && ! empty( $open_dispute_ids );
	}

	/**
	 * Log debug context for a completed lifecycle event that was skipped before mutation.
	 *
	 * @param WC_Order              $order  Order object.
	 * @param PaymentLifecycleEvent $event  Lifecycle event.
	 * @param string                $reason Skip reason.
	 */
	private function log_skipped_completed_event( WC_Order $order, PaymentLifecycleEvent $event, string $reason ): void {
		$message = 'open_dispute' === $reason
			? 'Completed payment event skipped: an open dispute keeps the order on hold.'
			: 'Completed payment event skipped: the payment note is already on the order.';

		wc_get_logger()->debug(
			$message,
			array(
				'source'     => 'order-payments',
				'order_id'   => $order->get_id(),
				'event_type' => $event->get_status(),
				'reason'     => $reason,
			)
		);
	}

	/**
	 * Tell whether a lifecycle note should be skipped for an already-applied event.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool
	 */
	private function should_skip_lifecycle_note( WC_Order $order, PaymentLifecycleEvent $event ): bool {
		$payment_reference = (string) $event->get_payment_reference();
		$note_type         = $event->get_note_type();

		return PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status()
			&& in_array( $note_type, array( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ), true )
			&& '' !== $payment_reference
			&& $payment_reference === (string) $order->get_transaction_id()
			&& $order->has_status( array( OrderStatus::PROCESSING, OrderStatus::COMPLETED ) );
	}

	/**
	 * Get the stable private identity for a lifecycle note.
	 *
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return string
	 */
	private function get_note_identity( PaymentLifecycleEvent $event, string $note ): string {
		$note_key = $event->get_note_type() ?? $note;

		return 'payment_lifecycle:' . (string) $event->get_payment_reference() . '|' . $event->get_status() . '|' . $note_key;
	}

	/**
	 * Get the shared WooPayments order note service.
	 *
	 * @return WooPaymentsOrderNoteService
	 */
	private function get_order_note_service(): WooPaymentsOrderNoteService {
		if ( null === $this->order_note_service ) {
			$this->order_note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		}

		return $this->order_note_service;
	}
}
