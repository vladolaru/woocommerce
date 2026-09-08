<?php
/**
 * OrderPaymentLifecycleService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeEventHandler;
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
	 * @param OrderPaymentStore           $order_payment_store Order payment store.
	 * @param WooPaymentsOrderNoteService $order_note_service  WooPayments order note service.
	 */
	final public function init( OrderPaymentStore $order_payment_store, ?WooPaymentsOrderNoteService $order_note_service = null ): void {
		$this->order_payment_store = $order_payment_store;
		$this->order_note_service  = $order_note_service;
	}

	/**
	 * Apply a payment lifecycle event to an order.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                      $order               Order object.
	 * @param PaymentLifecycleEvent         $event               Lifecycle event.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 */
	public function apply( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabulary $persistence_profile ): void {
		$payment_reference = $event->get_payment_reference();
		$locked_by_service = false;

		if ( null !== $payment_reference ) {
			if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $persistence_profile, $payment_reference ) ) {
				$this->log_skipped_locked_event( $order, $event, $payment_reference );
				return;
			}

			$locked_by_service = true;
		}

		try {
			$this->apply_unlocked( $order, $event, $persistence_profile );
		} finally {
			if ( $locked_by_service ) {
				$this->order_payment_store->unlock_order_payment( $order, $persistence_profile );
			}
		}
	}

	/**
	 * Refresh an order directly from its data store while its payment lock is held.
	 *
	 * A provider webhook can resolve its order before a concurrent event persists a
	 * dispute hold. Reading the store after the lock is acquired ensures the
	 * completed-event guard evaluates the state that won serialization.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function refresh_order_from_data_store( WC_Order $order ): void {
		wp_cache_delete( $order->get_id(), 'posts' );

		/**
		 * Order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $order->get_data_store();
		$data_store->read( $order );
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
	 * @param WC_Order                      $order               Order object.
	 * @param PaymentLifecycleEvent         $event               Lifecycle event.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 */
	public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabulary $persistence_profile ): void {
		if ( PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status() ) {
			$this->refresh_order_from_data_store( $order );
		}

		if ( $this->should_skip_late_failure_event( $order, $event ) ) {
			return;
		}

		$note                        = $event->get_note();
		$completed_event_skip_reason = $this->get_completed_event_skip_reason( $order, $event, $persistence_profile );
		if ( null !== $completed_event_skip_reason ) {
			$this->log_skipped_completed_event( $order, $event, $completed_event_skip_reason );
			return;
		}

		$this->apply_meta_changes( $order, $event );

		$should_add_note = null !== $note && '' !== $note && ! $this->should_skip_lifecycle_note( $order, $event, $note, $persistence_profile );

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
				$event->get_note_equivalents(),
				array( $this->get_note_marker_key( $event, (string) $note ) )
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
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @return bool
	 */
	private function should_save_meta_before_status_transition( PaymentLifecycleEvent $event ): bool {
		return in_array(
			$event->get_status(),
			array(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				PaymentLifecycleEvent::STATUS_AUTHORIZED,
				PaymentLifecycleEvent::STATUS_FAILED,
				PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
				PaymentLifecycleEvent::STATUS_CANCELED,
			),
			true
		);
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
				if ( $event->should_preserve_order_status() ) {
					return false;
				}

				$order->update_status( 'failed' );
				return true;

			case PaymentLifecycleEvent::STATUS_CANCELED:
				$order->update_status( 'cancelled' );
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

		wp_cache_delete( $order->get_id(), 'posts' );

		$fresh_order = clone $order;
		/**
		 * Fresh order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $fresh_order->get_data_store();
		$data_store->read( $fresh_order );
		/**
		 * Freshly read order.
		 *
		 * @var WC_Order $fresh_order
		 */

		if ( function_exists( 'wc_get_is_paid_statuses' ) ) {
			return $order->has_status( wc_get_is_paid_statuses() )
				|| $fresh_order->has_status( wc_get_is_paid_statuses() );
		}

		return false;
	}

	/**
	 * Get the reason a completed lifecycle event must be ignored before it changes the order.
	 *
	 * @param WC_Order                      $order               Order object.
	 * @param PaymentLifecycleEvent         $event               Lifecycle event.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return string|null Skip reason, or null when the event can be applied.
	 */
	private function get_completed_event_skip_reason( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabulary $persistence_profile ): ?string {
		if ( PaymentLifecycleEvent::STATUS_COMPLETED !== $event->get_status() ) {
			return null;
		}

		if ( $this->has_open_woopayments_dispute( $order, $persistence_profile ) ) {
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
				$event->get_note_equivalents(),
				array( $this->get_note_marker_key( $event, $note ) )
			)
		) {
			return null;
		}

		return 'success_note_exists';
	}

	/**
	 * Tell whether the provider vocabulary marks this order as carrying an open WooPayments dispute.
	 *
	 * @param WC_Order                      $order               Order object.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return bool
	 */
	private function has_open_woopayments_dispute( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile ): bool {
		$open_dispute_ids_meta_key = WooPaymentsDisputeEventHandler::OPEN_DISPUTE_IDS_META_KEY;
		if ( ! in_array( $open_dispute_ids_meta_key, $persistence_profile->get_preserved_payment_meta_keys(), true ) ) {
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
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$message = 'open_dispute' === $reason
			? 'Native WooPayments completed lifecycle event skipped because an open WooPayments dispute keeps the order on hold.'
			: 'Native WooPayments completed lifecycle event skipped because an already persisted success note identifies a replay.';

		wc_get_logger()->debug(
			$message,
			array(
				'source'     => 'native-payments-webhook',
				'order_id'   => $order->get_id(),
				'event_type' => $event->get_status(),
				'reason'     => $reason,
			)
		);
	}

	/**
	 * Tell whether a lifecycle note should be skipped for an already-applied event.
	 *
	 * @param WC_Order                      $order               Order object.
	 * @param PaymentLifecycleEvent         $event               Lifecycle event.
	 * @param string                        $note                Note content.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return bool
	 */
	private function should_skip_lifecycle_note( WC_Order $order, PaymentLifecycleEvent $event, string $note, ProviderPersistenceVocabulary $persistence_profile ): bool {
		if ( $persistence_profile instanceof ProviderPersistenceProfile && $persistence_profile->should_skip_note( $order, $event, $note ) ) {
			return true;
		}

		$payment_reference = (string) $event->get_payment_reference();
		$note_type         = $event->get_note_type();

		return PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status()
			&& in_array( $note_type, array( PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE, PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS ), true )
			&& '' !== $payment_reference
			&& $payment_reference === (string) $order->get_transaction_id()
			&& $order->has_status( array( 'processing', 'completed' ) );
	}

	/**
	 * Get the legacy idempotency marker key for a lifecycle note.
	 *
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return string
	 */
	private function get_note_marker_key( PaymentLifecycleEvent $event, string $note ): string {
		$note_key = $event->get_note_type() ?? $note;

		return '_wc_native_payments_note_' . md5( (string) $event->get_payment_reference() . '|' . $event->get_status() . '|' . $note_key );
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
