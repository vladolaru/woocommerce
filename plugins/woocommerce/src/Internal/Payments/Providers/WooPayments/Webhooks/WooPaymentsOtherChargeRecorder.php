<?php
/**
 * WooPaymentsOtherChargeRecorder class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use RuntimeException;
use WC_Order;

/**
 * Records an event on a WooPayments charge that does not pay the order: one note and one warning line, and for a
 * succeeded payment intent the charge ID when the order has none.
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
	 * and its status. When the note cannot be saved, nothing is logged and the event fails, so it can be delivered again.
	 *
	 * @param WC_Order                                                                                                                                                                                              $order      Order the event resolved to, read under the order payment lock.
	 * @param string                                                                                                                                                                                                $event_type Provider event type.
	 * @param array{object_id:string,status?:string,intent_id?:string,charge_id?:string,refund_id?:string,dispute_id?:string,warning_id?:string,message?:string,dispute_url?:string,amount?:float,currency?:string} $facts      The event object's ID and status, its intent, charge, refund, dispute and warning IDs, what it says happened, the dispute details URL, and its amount and currency when it moves money.
	 * @throws RuntimeException When the note cannot be saved.
	 */
	public function record( WC_Order $order, string $event_type, array $facts ): void {
		$charge_id = (string) ( $facts['charge_id'] ?? '' );
		if ( 'payment_intent.succeeded' === $event_type && '' !== $charge_id && '' === (string) $order->get_meta( '_charge_id', true ) ) {
			// The transactions and disputes lists find a charge's order by `_charge_id`. Only a charge that took money is
			// linked: a later event on it then finds this order, and is still recorded because it is not the order's payment.
			$order->update_meta_data( '_charge_id', $charge_id );
			$order->save();
		}

		$note     = $this->note_service->format_other_charge_note( $event_type, $facts );
		$identity = implode( '|', array( 'other_charge', $event_type, (string) $facts['object_id'], (string) ( $facts['status'] ?? '' ) ) );
		if ( ! $this->note_service->add_note_once( $order, $note, $identity ) && ! $this->note_service->has_persisted_note( $order, $note, $identity ) ) {
			// The note is the record: an event whose note was not saved fails, so it is not marked processed.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is built from an event type, an object ID and an order ID, not HTML output.
			throw new RuntimeException( sprintf( 'Could not save the other-charge note for %1$s %2$s on order %3$d.', $event_type, (string) $facts['object_id'], $order->get_id() ) );
		}

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
