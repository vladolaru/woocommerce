<?php
/**
 * WooPaymentsOtherChargeRecorder class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use WC_Order;

/**
 * Records an event on a WooPayments charge that does not pay the order: one note, the charge ID when the order has none,
 * and one warning line.
 *
 * Nothing else on the order changes: no status, payment, refund or dispute data. The order's `_intent_id` is never
 * written, because it names the intent that pays the order. The caller holds the order payment lock.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsOtherChargeRecorder {

	/**
	 * Log source of the warning line.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'woopayments';

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * Initialize the recorder.
	 *
	 * @internal
	 *
	 * @param WooPaymentsOrderNoteService $note_service WooPayments order note service.
	 */
	final public function init( WooPaymentsOrderNoteService $note_service ): void {
		$this->note_service = $note_service;
	}

	/**
	 * Record an event on a charge that does not pay the order.
	 *
	 * A redelivery of the same event adds no second note: the note's identity is the event type, the event object's ID
	 * and its status.
	 *
	 * @param WC_Order                                                                                                  $order      Order the event resolved to, read under the order payment lock.
	 * @param string                                                                                                    $event_type Provider event type.
	 * @param array{object_id:string,status?:string,intent_id?:string,charge_id?:string,amount?:float,currency?:string} $facts      The event object's ID and status, its intent and charge IDs, and its amount and currency when it moves money.
	 */
	public function record( WC_Order $order, string $event_type, array $facts ): void {
		$charge_id = (string) ( $facts['charge_id'] ?? '' );
		if ( '' !== $charge_id && '' === (string) $order->get_meta( '_charge_id', true ) ) {
			// The transactions and disputes lists find a charge's order by `_charge_id`.
			$order->update_meta_data( '_charge_id', $charge_id );
			$order->save();
		}

		$this->note_service->add_note_once(
			$order,
			$this->note_service->format_other_charge_note( $event_type, $facts ),
			implode( '|', array( 'other_charge', $event_type, (string) $facts['object_id'], (string) ( $facts['status'] ?? '' ) ) )
		);

		$payment_method = (string) $order->get_payment_method();
		wc_get_logger()->warning(
			sprintf( 'other charge recorded: order %d, %s, charge %s, order payment method %s', $order->get_id(), $event_type, $charge_id, $payment_method ),
			array(
				'source'         => self::LOG_SOURCE,
				'order_id'       => $order->get_id(),
				'event_type'     => $event_type,
				'charge_id'      => $charge_id,
				'payment_method' => $payment_method,
			)
		);
	}
}
