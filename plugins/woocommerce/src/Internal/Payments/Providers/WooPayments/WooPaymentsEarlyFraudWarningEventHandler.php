<?php
/**
 * WooPaymentsEarlyFraudWarningEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsOtherChargeRecorder;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;

/**
 * Stores WooPayments early fraud warning evidence on the matching order.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsEarlyFraudWarningEventHandler {

	/**
	 * Order payment store used to serialize writes.
	 *
	 * @var OrderPaymentLock|null
	 */
	private ?OrderPaymentLock $order_payment_lock = null;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceVocabulary|null
	 */
	private ?WooPaymentsPersistenceVocabulary $persistence_vocabulary = null;

	/**
	 * WooPayments note service.
	 *
	 * @var WooPaymentsOrderNoteService|null
	 */
	private ?WooPaymentsOrderNoteService $note_service = null;

	/**
	 * Webhook event order resolver.
	 *
	 * @var WooPaymentsEventOrderResolver|null
	 */
	private ?WooPaymentsEventOrderResolver $event_order_resolver = null;

	/**
	 * Recorder of events on a charge that does not pay the order.
	 *
	 * @var WooPaymentsOtherChargeRecorder|null
	 */
	private ?WooPaymentsOtherChargeRecorder $other_charge_recorder = null;

	/**
	 * Initialize the handler dependencies.
	 *
	 * @internal
	 * @since 11.2.0
	 *
	 * @param OrderPaymentLock|null                 $order_payment_lock Optional order payment store.
	 * @param WooPaymentsPersistenceVocabulary|null $persistence_vocabulary Optional WooPayments persistence profile.
	 * @param WooPaymentsOrderNoteService|null      $note_service Optional WooPayments note service.
	 * @param WooPaymentsEventOrderResolver|null    $event_order_resolver Optional webhook event order resolver.
	 * @param WooPaymentsOtherChargeRecorder|null   $other_charge_recorder Optional recorder of events on another charge.
	 */
	final public function init( ?OrderPaymentLock $order_payment_lock = null, ?WooPaymentsPersistenceVocabulary $persistence_vocabulary = null, ?WooPaymentsOrderNoteService $note_service = null, ?WooPaymentsEventOrderResolver $event_order_resolver = null, ?WooPaymentsOtherChargeRecorder $other_charge_recorder = null ): void {
		$this->order_payment_lock     = $order_payment_lock;
		$this->persistence_vocabulary = $persistence_vocabulary;
		$this->note_service           = $note_service;
		$this->event_order_resolver   = $event_order_resolver;
		$this->other_charge_recorder  = $other_charge_recorder;
	}

	/**
	 * Tell whether an event is an early fraud warning event.
	 *
	 * @since 11.2.0
	 *
	 * @param string $event_type Provider event type.
	 * @return bool
	 */
	public function is_supported_event( string $event_type ): bool {
		return in_array(
			$event_type,
			array(
				'radar.early_fraud_warning.created',
				'radar.early_fraud_warning.updated',
			),
			true
		);
	}

	/**
	 * Process an early fraud warning event.
	 *
	 * @since 11.2.0
	 *
	 * @param string              $event_type   Provider event type.
	 * @param array<string,mixed> $event_object Provider early fraud warning object.
	 * @throws InvalidArgumentException|RuntimeException When the provider object is malformed or the matching order cannot be updated safely.
	 * @throws OrderPaymentLockRefusedException When another operation holds the order payment lock; nothing has been written.
	 */
	public function process( string $event_type, array $event_object ): void {
		if ( ! $this->is_supported_event( $event_type ) ) {
			return;
		}

		$warning = $this->get_warning_data( $event_object );
		$order   = $this->get_event_order_resolver()->find_order_by_charge_id( $warning['charge'] );
		if ( ! $order instanceof WC_Order ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not find a WooPayments order for early fraud warning charge ID: %s', $warning['charge'] ) ) );
		}

		$lock_token = $this->get_order_payment_lock()->claim( $order, $this->get_persistence_vocabulary(), 'early_fraud_warning_' . $warning['id'], 'early fraud warning webhook' );
		if ( null === $lock_token ) {
			$this->get_order_payment_lock()->log_refusal( $order, $this->get_persistence_vocabulary(), 'early fraud warning webhook' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is built in the exception from an order ID and a fixed operation name, not HTML output.
			throw new OrderPaymentLockRefusedException( $order->get_id(), 'early fraud warning webhook' );
		}

		try {
			// Read the order past the request's caches: another request may have paid it or moved its charge since the lookup.
			$fresh_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
			if ( $warning['charge'] !== (string) $fresh_order->get_meta( '_charge_id', true ) ) {
				throw new RuntimeException( esc_html( sprintf( 'WooPayments order did not match early fraud warning charge ID: %s', $warning['charge'] ) ) );
			}

			// The warning is state of the order's own payment. One on another charge is recorded when it is raised; its
			// updates find no stored warning, as an update without a created warning finds none on the order's own charge.
			$resolver  = $this->get_event_order_resolver();
			$intent_id = $resolver->get_event_intent_id( $event_object, $fresh_order );
			if ( ! $resolver->is_own_payment( $fresh_order, $intent_id, $warning['charge'] ) ) {
				if ( 'radar.early_fraud_warning.created' === $event_type ) {
					$this->get_other_charge_recorder()->record(
						$fresh_order,
						$event_type,
						array(
							'object_id'  => $warning['id'],
							'status'     => $warning['actionable'] ? 'actionable' : 'not_actionable',
							'intent_id'  => $intent_id,
							'charge_id'  => $warning['charge'],
							'warning_id' => $warning['id'],
						)
					);
				}
				return;
			}

			if ( 'radar.early_fraud_warning.updated' === $event_type && ! $this->get_existing_warning( $fresh_order ) ) {
				return;
			}

			$state = array(
				'efw_id'         => $warning['id'],
				'efw_actionable' => $warning['actionable'],
				'efw_type'       => $warning['fraud_type'],
				'created'        => $warning['created'],
			);
			$fresh_order->update_meta_data( WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_META_KEY, $state );
			$fresh_order->save();
			$persisted_order   = wc_get_order( $fresh_order->get_id() );
			$persisted_warning = $persisted_order instanceof WC_Order ? $this->get_existing_warning( $persisted_order ) : null;
			if ( ! $persisted_order instanceof WC_Order || $state !== $persisted_warning ) {
				throw new RuntimeException( esc_html( sprintf( 'Could not persist early fraud warning ID: %s', $warning['id'] ) ) );
			}

			$note_candidates = $this->get_note_service()->format_early_fraud_warning_note_candidates( $warning['charge'], $warning['actionable'], $warning['fraud_type'] );
			$note_identity   = $this->get_note_identity( $warning['id'], $warning['actionable'], $note_candidates[0] );
			$note_added      = $this->get_note_service()->add_note_once(
				$persisted_order,
				$note_candidates[0],
				$note_identity,
				$note_candidates
			);
			if ( ! $note_added && ! $this->get_note_service()->has_persisted_note( $persisted_order, $note_candidates[0], $note_identity, $note_candidates ) ) {
				throw new RuntimeException( esc_html( sprintf( 'Could not persist early fraud warning note for ID: %s', $warning['id'] ) ) );
			}
		} finally {
			$this->get_order_payment_lock()->release( $order, $this->get_persistence_vocabulary(), $lock_token );
		}
	}

	/**
	 * Validate and normalize an early fraud warning object before querying orders.
	 *
	 * @param array<string,mixed> $event_object Provider early fraud warning object.
	 * @return array{charge:string,id:string,actionable:bool,created:int,fraud_type:string}
	 * @throws InvalidArgumentException When a required value is invalid.
	 */
	private function get_warning_data( array $event_object ): array {
		$charge     = $event_object['charge'] ?? null;
		$warning_id = $event_object['id'] ?? null;
		$actionable = $event_object['actionable'] ?? null;
		$created    = $event_object['created'] ?? null;
		$fraud_type = $event_object['fraud_type'] ?? '';

		// The client requires charge, id, actionable and created (class-wc-payments-webhook-processing-service.php:867-874)
		// and casts what it stores (:900); a scalar of another type is cast the same way. Stricter than the client: a
		// created time that is not numeric, or is negative, is refused rather than cast to a meaningless timestamp.
		if ( ! is_string( $charge ) || '' === $charge || ! is_string( $warning_id ) || '' === $warning_id || ! is_scalar( $actionable ) || ! is_numeric( $created ) || 0 > (int) $created || ! is_scalar( $fraud_type ) ) {
			throw new InvalidArgumentException( 'WooPayments early fraud warning event is malformed.' );
		}

		return array(
			'charge'     => $charge,
			'id'         => $warning_id,
			'actionable' => (bool) $actionable,
			'created'    => (int) $created,
			'fraud_type' => (string) $fraud_type,
		);
	}

	/**
	 * Get valid stored warning evidence, if any.
	 *
	 * @param WC_Order $order Order with potential early fraud warning evidence.
	 * @return array{efw_id:string,efw_actionable:bool,efw_type:string,created:int}|null
	 */
	private function get_existing_warning( WC_Order $order ): ?array {
		$warning = $order->get_meta( WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_META_KEY, true );
		if (
			! is_array( $warning )
			|| array() !== array_diff( array( 'efw_id', 'efw_actionable', 'efw_type', 'created' ), array_keys( $warning ) )
			|| array() !== array_diff( array_keys( $warning ), array( 'efw_id', 'efw_actionable', 'efw_type', 'created' ) )
			|| ! is_string( $warning['efw_id'] )
			|| ! is_bool( $warning['efw_actionable'] )
			|| ! is_string( $warning['efw_type'] )
			|| ! is_int( $warning['created'] )
			|| 0 > $warning['created']
		) {
			return null;
		}

		return $warning;
	}

	/**
	 * Build a content-sensitive identity for an early fraud warning note.
	 *
	 * @param string $warning_id Warning ID.
	 * @param bool   $actionable Warning actionability.
	 * @param string $core_note  Core catalog rendering.
	 * @return string
	 */
	private function get_note_identity( string $warning_id, bool $actionable, string $core_note ): string {
		return implode( '|', array( 'early_fraud_warning', $warning_id, $actionable ? 'actionable' : 'resolved', hash( 'sha256', $core_note ) ) );
	}

	/**
	 * Get the order payment store.
	 *
	 * @return OrderPaymentLock
	 */
	private function get_order_payment_lock(): OrderPaymentLock {
		if ( null === $this->order_payment_lock ) {
			$this->order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		}

		return $this->order_payment_lock;
	}

	/**
	 * Get the WooPayments persistence profile.
	 *
	 * @return WooPaymentsPersistenceVocabulary
	 */
	private function get_persistence_vocabulary(): WooPaymentsPersistenceVocabulary {
		if ( null === $this->persistence_vocabulary ) {
			$this->persistence_vocabulary = wc_get_container()->get( WooPaymentsPersistenceVocabulary::class );
		}

		return $this->persistence_vocabulary;
	}

	/**
	 * Get the WooPayments note service.
	 *
	 * @return WooPaymentsOrderNoteService
	 */
	private function get_note_service(): WooPaymentsOrderNoteService {
		if ( null === $this->note_service ) {
			$this->note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		}

		return $this->note_service;
	}

	/**
	 * Get the webhook event order resolver.
	 *
	 * @return WooPaymentsEventOrderResolver
	 */
	private function get_event_order_resolver(): WooPaymentsEventOrderResolver {
		if ( null === $this->event_order_resolver ) {
			$this->event_order_resolver = wc_get_container()->get( WooPaymentsEventOrderResolver::class );
		}

		return $this->event_order_resolver;
	}

	/**
	 * Get the recorder of events on a charge that does not pay the order.
	 *
	 * @return WooPaymentsOtherChargeRecorder
	 */
	private function get_other_charge_recorder(): WooPaymentsOtherChargeRecorder {
		if ( null === $this->other_charge_recorder ) {
			$this->other_charge_recorder = wc_get_container()->get( WooPaymentsOtherChargeRecorder::class );
		}

		return $this->other_charge_recorder;
	}
}
