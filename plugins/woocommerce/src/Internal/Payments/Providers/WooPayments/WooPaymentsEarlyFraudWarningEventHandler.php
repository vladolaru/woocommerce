<?php
/**
 * WooPaymentsEarlyFraudWarningEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
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
	 * @var OrderPaymentStore|null
	 */
	private ?OrderPaymentStore $order_payment_store = null;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceProfile|null
	 */
	private ?WooPaymentsPersistenceProfile $persistence_profile = null;

	/**
	 * WooPayments note service.
	 *
	 * @var WooPaymentsOrderNoteService|null
	 */
	private ?WooPaymentsOrderNoteService $note_service = null;

	/**
	 * Initialize the handler dependencies.
	 *
	 * @internal
	 * @since 11.2.0
	 *
	 * @param OrderPaymentStore|null             $order_payment_store Optional order payment store.
	 * @param WooPaymentsPersistenceProfile|null $persistence_profile Optional WooPayments persistence profile.
	 * @param WooPaymentsOrderNoteService|null   $note_service Optional WooPayments note service.
	 */
	final public function init( ?OrderPaymentStore $order_payment_store = null, ?WooPaymentsPersistenceProfile $persistence_profile = null, ?WooPaymentsOrderNoteService $note_service = null ): void {
		$this->order_payment_store = $order_payment_store;
		$this->persistence_profile = $persistence_profile;
		$this->note_service        = $note_service;
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
	 */
	public function process( string $event_type, array $event_object ): void {
		if ( ! $this->is_supported_event( $event_type ) ) {
			return;
		}

		$warning = $this->get_warning_data( $event_object );
		$order   = $this->get_order_for_charge_id( $warning['charge'] );
		if ( ! $order instanceof WC_Order || ! $this->is_woopayments_order( $order ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not find a WooPayments order for early fraud warning charge ID: %s', $warning['charge'] ) ) );
		}

		$reference = 'early_fraud_warning_' . $warning['id'];
		if ( ! $this->get_order_payment_store()->claim_order_payment_lock( $order, $this->get_persistence_profile(), $reference ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not lock the WooPayments order for early fraud warning ID: %s', $warning['id'] ) ) );
		}

		try {
			$fresh_order = wc_get_order( $order->get_id() );
			if ( ! $fresh_order instanceof WC_Order || ! $this->is_woopayments_order( $fresh_order ) || $warning['charge'] !== (string) $fresh_order->get_meta( '_charge_id', true ) ) {
				throw new RuntimeException( esc_html( sprintf( 'WooPayments order did not match early fraud warning charge ID: %s', $warning['charge'] ) ) );
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
			$fresh_order->update_meta_data( WooPaymentsPersistenceProfile::EARLY_FRAUD_WARNING_META_KEY, $state );
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
			$this->get_order_payment_store()->unlock_order_payment( $order, $this->get_persistence_profile() );
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

		if ( ! is_string( $charge ) || '' === $charge || ! is_string( $warning_id ) || '' === $warning_id || ! is_bool( $actionable ) || ! is_int( $created ) || 0 > $created || ! is_string( $fraud_type ) ) {
			throw new InvalidArgumentException( 'WooPayments early fraud warning event is malformed.' );
		}

		return array(
			'charge'     => $charge,
			'id'         => $warning_id,
			'actionable' => $actionable,
			'created'    => $created,
			'fraud_type' => $fraud_type,
		);
	}

	/**
	 * Get the WooPayments order for an early fraud warning charge.
	 *
	 * @param string $charge_id Provider charge ID.
	 * @return WC_Order|null
	 */
	private function get_order_for_charge_id( string $charge_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_charge_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $charge_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! is_array( $orders ) ) {
			return null;
		}

		return isset( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * Get valid stored warning evidence, if any.
	 *
	 * @param WC_Order $order Order with potential early fraud warning evidence.
	 * @return array{efw_id:string,efw_actionable:bool,efw_type:string,created:int}|null
	 */
	private function get_existing_warning( WC_Order $order ): ?array {
		$warning = $order->get_meta( WooPaymentsPersistenceProfile::EARLY_FRAUD_WARNING_META_KEY, true );
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
	 * Tell whether an order belongs to WooPayments.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return bool
	 */
	private function is_woopayments_order( WC_Order $order ): bool {
		$payment_method = (string) $order->get_payment_method();

		return OrderPaymentStore::GATEWAY_ID === $payment_method || 0 === strpos( $payment_method, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}

	/**
	 * Get the order payment store.
	 *
	 * @return OrderPaymentStore
	 */
	private function get_order_payment_store(): OrderPaymentStore {
		if ( null === $this->order_payment_store ) {
			$this->order_payment_store = wc_get_container()->get( OrderPaymentStore::class );
		}

		return $this->order_payment_store;
	}

	/**
	 * Get the WooPayments persistence profile.
	 *
	 * @return WooPaymentsPersistenceProfile
	 */
	private function get_persistence_profile(): WooPaymentsPersistenceProfile {
		if ( null === $this->persistence_profile ) {
			$this->persistence_profile = wc_get_container()->get( WooPaymentsPersistenceProfile::class );
		}

		return $this->persistence_profile;
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
}
