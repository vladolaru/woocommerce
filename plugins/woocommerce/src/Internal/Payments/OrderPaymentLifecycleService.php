<?php
/**
 * OrderPaymentLifecycleService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Enums\OrderStatus;
use InvalidArgumentException;
use WC_Order;

/**
 * Applies payment lifecycle events to WooCommerce orders: meta, status and a note added once, under the order payment lock.
 *
 * @since 11.0.0
 * @internal
 */
class OrderPaymentLifecycleService {

	/**
	 * Result of an event the order payment lock refused: nothing was written.
	 *
	 * @since 11.2.0
	 */
	public const RESULT_REFUSED = 'refused';

	/**
	 * Result of an applied event whose note this call first added to the order.
	 *
	 * @since 11.2.0
	 */
	public const RESULT_NOTE_ADDED = 'note_added';

	/**
	 * Result of an applied event that added no note: the order already had it, the event was skipped, or it has no note.
	 *
	 * @since 11.2.0
	 */
	public const RESULT_NOTE_NOT_ADDED = 'note_not_added';

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private OrderPaymentLock $order_payment_lock;

	/**
	 * Order payment notes.
	 *
	 * @var OrderPaymentNotes
	 */
	private OrderPaymentNotes $order_payment_notes;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentLock  $order_payment_lock  Order payment lock.
	 * @param OrderPaymentNotes $order_payment_notes Order payment notes.
	 */
	final public function init( OrderPaymentLock $order_payment_lock, OrderPaymentNotes $order_payment_notes ): void {
		$this->order_payment_lock  = $order_payment_lock;
		$this->order_payment_notes = $order_payment_notes;
	}

	/**
	 * Apply a payment lifecycle event to an order.
	 *
	 * An event with a payment reference runs under the order payment lock. When another operation holds the lock,
	 * the event is skipped with a warning and nothing is written; a caller that can deliver the event again uses the
	 * return value to retry it. The result also tells whether this call first added the event's note, decided under
	 * the lock.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return string self::RESULT_REFUSED when the order payment lock refused the event, otherwise the result of
	 *                apply_under_lock().
	 */
	public function apply( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): string {
		return $this->apply_with_claim( $order, $event, $persistence_vocabulary, null );
	}

	/**
	 * Apply a payment lifecycle event built again from the order read under the order payment lock.
	 *
	 * The lock is claimed, and a refusal handled, as apply() does, for the payment reference of the event passed. Under the
	 * claim, the order is read again from its data store once, the callable builds the event to apply from that read, and
	 * the built event is applied as apply_under_lock() does.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param PaymentLifecycleEvent                  $event                  Lifecycle event that gives the payment reference, and the
	 *                                                                       event refused when another operation holds the lock.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param callable                               $rebuild                Gets the order read under the claim and returns the
	 *                                                                       PaymentLifecycleEvent to apply, for the same payment reference.
	 * @return string self::RESULT_REFUSED when the order payment lock refused the event, otherwise the result of
	 *                apply_under_lock() for the built event.
	 * @throws InvalidArgumentException When the built event is not for the payment reference the lock was claimed for.
	 */
	public function apply_rebuilt_under_claim( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary, callable $rebuild ): string {
		return $this->apply_with_claim( $order, $event, $persistence_vocabulary, $rebuild );
	}

	/**
	 * Claim the order payment lock for an event's payment reference, apply the event, or the one the callable builds from
	 * the order read under the claim, and release the lock.
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param PaymentLifecycleEvent                  $event                  Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param callable|null                          $rebuild                Builds the event to apply from the order read under the claim.
	 * @return string
	 * @throws InvalidArgumentException When the built event is not for the claimed payment reference.
	 */
	private function apply_with_claim( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary, ?callable $rebuild ): string {
		$payment_reference = $event->get_payment_reference();
		$lock_token        = null;

		if ( null !== $payment_reference ) {
			$lock_token = $this->order_payment_lock->claim( $order, $persistence_vocabulary, $payment_reference, 'payment status update' );
			if ( null === $lock_token ) {
				$this->log_skipped_locked_event( $order, $event, $payment_reference, $persistence_vocabulary );
				return self::RESULT_REFUSED;
			}
		}

		try {
			if ( null === $rebuild ) {
				return $this->apply_under_lock( $order, $event, $persistence_vocabulary );
			}

			$fresh_order   = $this->get_fresh_order_from_data_store( $order );
			$rebuilt_event = $rebuild( $fresh_order );
			if ( ! $rebuilt_event instanceof PaymentLifecycleEvent || $rebuilt_event->get_payment_reference() !== $payment_reference ) {
				throw new InvalidArgumentException( esc_html( 'The rebuilt payment lifecycle event must be for the payment reference the order payment lock was claimed for.' ) );
			}

			return $this->apply_event_under_lock( $order, $rebuilt_event, $persistence_vocabulary, $fresh_order );
		} finally {
			if ( null !== $lock_token ) {
				$this->order_payment_lock->release( $order, $persistence_vocabulary, $lock_token );
			}
		}
	}

	/**
	 * Get an order read again from its data store, past the post, meta and HPOS order caches.
	 *
	 * The one authoritative re-read for the payments code: another request (a webhook, a redirect return, a checkout of
	 * the same order) may have written the order since this request loaded it. The read goes into a clone, so changes
	 * the caller has not yet saved stay on the order it passed. The clone still carries a status transition the caller
	 * recorded, so saving it fires that transition. A caller that writes on the strength of the result holds the order
	 * payment lock.
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
	 * object, must see what the read returned. Changes not yet saved on the object are discarded, except a status
	 * transition and staged save callbacks the caller recorded: the data store read does not clear them.
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
	 * A lifecycle event can arrive while a checkout, refund or capture holds the order payment lock; it is skipped, and
	 * the caller decides whether to deliver it again. The warning makes each skip observable.
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
	 * @return string self::RESULT_NOTE_ADDED when this call first added the event's note, self::RESULT_NOTE_NOT_ADDED
	 *                otherwise.
	 */
	public function apply_under_lock( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): string {
		return $this->apply_event_under_lock( $order, $event, $persistence_vocabulary, null );
	}

	/**
	 * Apply a payment lifecycle event under the order payment lock, with the order already read again under it, if any.
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param PaymentLifecycleEvent                  $event                  Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param WC_Order|null                          $fresh_order            Order read again under the lock, or null to read it here.
	 * @return string
	 */
	private function apply_event_under_lock( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary, ?WC_Order $fresh_order ): string {
		$decision_order = in_array( $event->get_status(), array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::STATUS_AUTHORIZED ), true ) ? ( $fresh_order ?? $this->get_fresh_order_from_data_store( $order ) ) : $order;

		if ( $this->should_skip_late_failure_event( $order, $event, $fresh_order ) ) {
			$this->record_skipped_late_release( $order, $event, $persistence_vocabulary );
			return self::RESULT_NOTE_NOT_ADDED;
		}

		$note        = $event->get_note();
		$skip_reason = $this->get_completed_event_skip_reason( $order, $decision_order, $event, $persistence_vocabulary ) ?? $this->get_authorized_event_skip_reason( $decision_order, $event, $persistence_vocabulary );
		if ( null !== $skip_reason ) {
			$this->synchronize_skipped_event_order( $order, $decision_order, $skip_reason, $persistence_vocabulary );
			$this->log_skipped_event( $order, $event, $skip_reason );
			return self::RESULT_NOTE_NOT_ADDED;
		}

		$this->apply_meta_changes( $order, $event->get_meta_to_update(), $event->get_meta_to_delete() );

		$should_add_note = null !== $note && '' !== $note && ! $this->should_skip_lifecycle_note( $order, $event );

		if ( $this->should_save_meta_before_status_transition( $event ) ) {
			$order->save_meta_data();
		}

		$status_transition_saved_order = $this->apply_status_transition( $order, $event );
		if ( ! $status_transition_saved_order ) {
			$order->save();
		}

		return $should_add_note && $this->add_lifecycle_note( $order, $event, $persistence_vocabulary )
			? self::RESULT_NOTE_ADDED
			: self::RESULT_NOTE_NOT_ADDED;
	}

	/**
	 * Write an outcome's order meta and save the order, with no lifecycle event, while the caller already owns the order
	 * payment lock.
	 *
	 * For an outcome that took no payment: the meta is normalised and written as a lifecycle event's is, and the order's
	 * status, transaction ID and notes are left as they are.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order            $order          Order object.
	 * @param array<string,mixed> $meta_to_update Order meta to update.
	 * @param array<int,mixed>    $meta_to_delete Order meta keys to delete.
	 */
	public function apply_meta_under_lock( WC_Order $order, array $meta_to_update, array $meta_to_delete ): void {
		$this->apply_meta_changes(
			$order,
			PaymentLifecycleEvent::normalize_meta_to_update( $meta_to_update ),
			PaymentLifecycleEvent::normalize_meta_to_delete( $meta_to_delete )
		);
		$order->save();
	}

	/**
	 * Apply meta updates and deletes.
	 *
	 * @param WC_Order             $order          Order object.
	 * @param array<string,string> $meta_to_update Normalized order meta to update.
	 * @param string[]             $meta_to_delete Normalized order meta keys to delete.
	 */
	private function apply_meta_changes( WC_Order $order, array $meta_to_update, array $meta_to_delete ): void {
		foreach ( $meta_to_update as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		foreach ( $meta_to_delete as $key ) {
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
				if ( $event->should_hold_order() ) {
					$this->put_order_on_hold( $order, $event );
					return true;
				}

				return (bool) $order->payment_complete( (string) $event->get_payment_reference() );

			case PaymentLifecycleEvent::STATUS_AUTHORIZED:
				$this->put_order_on_hold( $order, $event );
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
				if ( null !== $event->get_payment_reference() && '' !== $event->get_payment_reference() && '' === (string) $order->get_transaction_id() ) {
					$order->set_transaction_id( $event->get_payment_reference() );
				}
				return false;
		}

		return false;
	}

	/**
	 * Put the order on hold with the event's payment reference as its transaction ID: an authorized payment, or a completed
	 * one the event holds.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 */
	private function put_order_on_hold( WC_Order $order, PaymentLifecycleEvent $event ): void {
		if ( null !== $event->get_payment_reference() && '' !== $event->get_payment_reference() ) {
			$order->set_transaction_id( $event->get_payment_reference() );
		}
		$order->update_status( OrderStatus::ON_HOLD );
	}

	/**
	 * Tell whether a late failure event should be ignored for an already-paid order.
	 *
	 * @param WC_Order              $order       Order object.
	 * @param PaymentLifecycleEvent $event       Lifecycle event.
	 * @param WC_Order|null         $fresh_order Order read again under the lock, or null to read it here.
	 * @return bool
	 */
	private function should_skip_late_failure_event( WC_Order $order, PaymentLifecycleEvent $event, ?WC_Order $fresh_order = null ): bool {
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

		$fresh_order = $fresh_order ?? $this->get_fresh_order_from_data_store( $order );

		return $order->has_status( wc_get_is_paid_statuses() )
			|| $fresh_order->has_status( wc_get_is_paid_statuses() );
	}

	/**
	 * Record a cancel or capture-expired event skipped on a paid order: one order note and one warning, nothing else.
	 *
	 * The provider confirmed the authorization was released or expired uncaptured, yet the order stays paid, so the note
	 * and the log line name the event and the amount, the order total the authorization was for. A redelivered event logs
	 * again but adds no second note. Client 11.1.0 skips these events silently (class-wc-payments-order-service.php:2747-2754).
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param PaymentLifecycleEvent                  $event                  Skipped lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 */
	private function record_skipped_late_release( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		$status = $event->get_status();
		if ( ! in_array( $status, array( PaymentLifecycleEvent::STATUS_CANCELED, PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED ), true ) ) {
			return;
		}

		$is_cancel         = PaymentLifecycleEvent::STATUS_CANCELED === $status;
		$amount            = (string) $order->get_total();
		$currency          = (string) $order->get_currency();
		$payment_reference = (string) $event->get_payment_reference();

		wc_get_logger()->warning(
			sprintf(
				'A %1$s payment event for order #%2$d was skipped because the order is already paid, so its status was not changed. The authorization of %3$s %4$s %5$s.',
				$status,
				$order->get_id(),
				$amount,
				$currency,
				$is_cancel ? 'was released' : 'expired and was never captured'
			),
			array(
				'source'            => 'order-payments',
				'order_id'          => $order->get_id(),
				'event_type'        => $status,
				'payment_reference' => $payment_reference,
				'amount'            => $amount,
				'currency'          => $currency,
			)
		);

		$identity = '' === $payment_reference ? '' : sprintf( 'skipped_late_release:%s:%s', $status, $payment_reference );
		if ( '' !== $identity && 0 < $this->order_payment_notes->find_by_identity( $order, $identity, $persistence_vocabulary ) ) {
			return;
		}

		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $currency ) );
		$note             = $is_cancel
			? sprintf(
				/* translators: %s: authorized amount. */
				__( 'The payment authorization of %s was canceled, so that amount was released to the customer. The order is already paid, so its status was not changed.', 'woocommerce' ),
				$formatted_amount
			)
			: sprintf(
				/* translators: %s: authorized amount. */
				__( 'The payment authorization of %s expired before it was captured, so that amount was never collected. The order is already paid, so its status was not changed.', 'woocommerce' ),
				$formatted_amount
			);

		$this->order_payment_notes->add( $order, $note, $identity, $persistence_vocabulary );
	}

	/**
	 * Get the reason a completed lifecycle event must be ignored before it changes the order.
	 *
	 * A completed event whose note, or the note of one of its applied alternatives, is already on the order was applied
	 * before, so it changes nothing, an open dispute keeps the order on hold, and a hold never lands on a paid order: the
	 * caller's order or its fresh read. An event that only reports a payment's status leaves an order its fresh read finds
	 * in a paid status as it is.
	 *
	 * @param WC_Order                               $caller_order        Caller-owned order object.
	 * @param WC_Order                               $order               Freshly read order.
	 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return string|null Skip reason, or null when the event can be applied.
	 */
	private function get_completed_event_skip_reason( WC_Order $caller_order, WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): ?string {
		if ( PaymentLifecycleEvent::STATUS_COMPLETED !== $event->get_status() ) {
			return null;
		}

		if ( $this->has_open_dispute( $order, $persistence_vocabulary ) ) {
			return 'open_dispute';
		}

		if ( $event->should_hold_order() && ( $caller_order->has_status( wc_get_is_paid_statuses() ) || $order->has_status( wc_get_is_paid_statuses() ) ) ) {
			return 'order_paid';
		}

		$note = $event->get_note();
		if ( null !== $note && '' !== $note && 0 < $this->find_lifecycle_note( $order, $event, $persistence_vocabulary ) ) {
			return 'success_note_exists';
		}

		foreach ( $event->get_applied_alternatives() as $alternative ) {
			if ( 0 < $this->find_lifecycle_note( $order, $alternative, $persistence_vocabulary ) ) {
				return 'applied_note_exists';
			}
		}

		if ( $event->should_leave_paid_order() && $order->has_status( wc_get_is_paid_statuses() ) ) {
			return 'order_paid';
		}

		return null;
	}

	/**
	 * Get the reason an authorized lifecycle event must be ignored before it changes the order.
	 *
	 * An authorized event whose note is already on the order was applied before, so it changes nothing, status and meta
	 * included; an authorization's own note type also changes nothing on an order already on hold. Client 11.3.0's
	 * on-hold writers check the same before any write (class-wc-payments-order-service.php:2146-2151, :2171-2175, :2251-2256).
	 *
	 * @param WC_Order                               $order                  Order read again under the order payment lock.
	 * @param PaymentLifecycleEvent                  $event                  Lifecycle event.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return string|null Skip reason, or null when the event can be applied.
	 */
	private function get_authorized_event_skip_reason( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): ?string {
		if ( PaymentLifecycleEvent::STATUS_AUTHORIZED !== $event->get_status() ) {
			return null;
		}

		$note = $event->get_note();
		if ( null !== $note && '' !== $note && 0 < $this->find_lifecycle_note( $order, $event, $persistence_vocabulary ) ) {
			return 'authorization_note_exists';
		}

		if ( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED === $event->get_note_type() && $order->has_status( OrderStatus::ON_HOLD ) ) {
			return 'order_on_hold';
		}

		return null;
	}

	/**
	 * Bring the persisted status and open disputes onto a caller's order object that a skipped event found stale.
	 *
	 * A caller whose object matches the persisted order keeps it untouched. A diverging object is read again in place,
	 * so a later save by the caller cannot write its stale status or dispute meta. This method records no status
	 * transition of its own, so that save sends no second status email or note and moves no paid date. Its unsaved
	 * changes are discarded, but a status change the caller made itself and has not saved still fires on that save.
	 * No caller of the lifecycle holds one.
	 *
	 * @param WC_Order                               $order                  Caller-owned order object.
	 * @param WC_Order                               $decision_order         Freshly read order the skip was decided on.
	 * @param string                                 $skip_reason            Skip reason.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 */
	private function synchronize_skipped_event_order( WC_Order $order, WC_Order $decision_order, string $skip_reason, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		$is_stale = $order->get_status() !== $decision_order->get_status();

		if ( ! $is_stale && 'open_dispute' === $skip_reason ) {
			$open_dispute_ids_meta_key = $persistence_vocabulary->get_open_dispute_ids_meta_key();
			$is_stale                  = $order->get_meta( $open_dispute_ids_meta_key, true ) !== $decision_order->get_meta( $open_dispute_ids_meta_key, true );
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
	 * Log debug context for a completed or authorized lifecycle event that was skipped before mutation.
	 *
	 * @param WC_Order              $order  Order object.
	 * @param PaymentLifecycleEvent $event  Lifecycle event.
	 * @param string                $reason Skip reason.
	 */
	private function log_skipped_event( WC_Order $order, PaymentLifecycleEvent $event, string $reason ): void {
		$messages = array(
			'open_dispute'              => 'Completed payment event skipped: an open dispute keeps the order on hold.',
			'order_paid'                => 'Completed payment event skipped: the order is already paid, so the event does not change it.',
			'applied_note_exists'       => 'Completed payment event skipped: a note that counts as this payment already applied is on the order.',
			'authorization_note_exists' => 'Authorized payment event skipped: its note is already on the order.',
			'order_on_hold'             => 'Authorized payment event skipped: the order is already on hold.',
		);
		$message  = $messages[ $reason ] ?? 'Completed payment event skipped: the payment note is already on the order.';

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
	 * An order already paid by the event's payment reference gets no second completion note, even when the note it has
	 * reads differently.
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
	 * Find the event's note on the order, by its identity or else by its text, and give a note found by its text the
	 * identity, so the next delivery finds it whatever its text reads by then.
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param PaymentLifecycleEvent                  $event                  Lifecycle event with a note.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return int The note's comment ID, or 0 when the order does not have the note.
	 */
	private function find_lifecycle_note( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): int {
		$identity = $event->get_note_identity();
		$note_id  = $this->order_payment_notes->find_by_identity( $order, $identity, $persistence_vocabulary );
		if ( 0 < $note_id ) {
			return $note_id;
		}

		$note_id = $this->order_payment_notes->find_by_content( $order, (string) $event->get_note(), $event->get_note_equivalents() );
		if ( 0 < $note_id ) {
			$this->order_payment_notes->record_identity( $note_id, $identity, $persistence_vocabulary );
		}

		return $note_id;
	}

	/**
	 * Add the event's note to the order unless the order already has it.
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param PaymentLifecycleEvent                  $event                  Lifecycle event with a note.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return bool Whether the note was added.
	 */
	private function add_lifecycle_note( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): bool {
		if ( 0 < $this->find_lifecycle_note( $order, $event, $persistence_vocabulary ) ) {
			return false;
		}

		return 0 < $this->order_payment_notes->add( $order, (string) $event->get_note(), $event->get_note_identity(), $persistence_vocabulary );
	}
}
