<?php
/**
 * WooPaymentsAmountMismatchHold class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a succeeded payment puts its order on hold because the payment's amount or currency differs from the
 * order total, and gives the hold's note.
 *
 * Client 11.3.0 update_order_status_from_intent() (class-wc-payments-order-service.php:483-491) completes a succeeded
 * PaymentIntent only when it is for the order's total and currency, and otherwise holds the order with a note
 * (:2095-2139). Every place that turns a succeeded PaymentIntent into a completion asks this class.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsAmountMismatchHold {

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * Order payment notes.
	 *
	 * @var OrderPaymentNotes
	 */
	private OrderPaymentNotes $order_payment_notes;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Log lines of the holds this request decided while the order did not have the hold note yet, by order and intent ID,
	 * written once the payment lifecycle has added the note.
	 *
	 * @var array<string,array{line:string,notes:string[]}>
	 */
	private array $undelivered_hold_logs = array();

	/**
	 * Initialize dependencies.
	 *
	 * @internal
	 *
	 * @param WooPaymentsOrderNoteService $note_service        WooPayments order note service.
	 * @param OrderPaymentNotes           $order_payment_notes Order payment notes.
	 * @param WooPaymentsLogger           $logger              WooPayments logger.
	 */
	final public function init( WooPaymentsOrderNoteService $note_service, OrderPaymentNotes $order_payment_notes, WooPaymentsLogger $logger ): void {
		$this->note_service        = $note_service;
		$this->order_payment_notes = $order_payment_notes;
		$this->logger              = $logger;
	}

	/**
	 * Decide whether a succeeded PaymentIntent holds its order because its amount or currency differs from the order total.
	 *
	 * Returns the hold's note wordings when the order is held; null when the payment completes it: a PaymentIntent for the
	 * order's total and currency, any intent that has not succeeded, a SetupIntent (client :486), an order whose stored
	 * intent status is `requires_capture`, whose capture completes it (:484-485), and a Stripe Billing invoice's intent,
	 * which its invoice settles. The client's error line (:2117-2131) is written by log_applied_hold(), once the payment
	 * lifecycle has added the hold note.
	 *
	 * @param WC_Order            $order  Order the intent pays.
	 * @param array<string,mixed> $intent Intent as the platform returns it.
	 * @return string[]|null The hold note's wordings, WooCommerce's first; null when the payment completes the order.
	 */
	public function decide_hold( WC_Order $order, array $intent ): ?array {
		$intent_id = isset( $intent['id'] ) && is_scalar( $intent['id'] ) ? (string) $intent['id'] : '';
		if (
			'succeeded' !== ( $intent['status'] ?? '' )
			|| 'setup_intent' === ( $intent['object'] ?? '' )
			|| 0 === strpos( $intent_id, 'seti_' )
			|| 'requires_capture' === (string) $order->get_meta( '_intention_status', true )
			|| WooPaymentsIntentCodec::is_invoice_intent( $intent )
			|| WooPaymentsCurrencyUtils::intent_matches_order_total( $intent, $order )
		) {
			return null;
		}

		$charge           = WooPaymentsIntentCodec::latest_charge( $intent );
		$charged_amount   = is_numeric( $intent['amount'] ?? null ) ? (int) $intent['amount'] : 0;
		$charged_currency = isset( $intent['currency'] ) && is_string( $intent['currency'] ) ? $intent['currency'] : '';
		$note_candidates  = $this->note_service->format_amount_mismatch_hold_note_candidates(
			$order,
			$intent_id,
			isset( $charge['id'] ) && is_scalar( $charge['id'] ) ? (string) $charge['id'] : '',
			$charged_amount,
			$charged_currency
		);

		$key = $order->get_id() . '|' . $intent_id;
		unset( $this->undelivered_hold_logs[ $key ] );
		if ( 0 === $this->order_payment_notes->find_by_content( $order, $note_candidates[0], $note_candidates ) ) {
			$this->undelivered_hold_logs[ $key ] = array(
				'line'  => sprintf(
					'Order %1$d put on hold: intent %2$s succeeded for %3$d %4$s, but the order total is %5$s %6$s.',
					$order->get_id(),
					$intent_id,
					$charged_amount,
					$charged_currency,
					$order->get_total(),
					$order->get_currency()
				),
				'notes' => $note_candidates,
			);
		}

		return $note_candidates;
	}

	/**
	 * Write the client's error line for a hold this request decided, once the payment lifecycle has added the hold note:
	 * nothing when the hold was not applied, such as on a paid order or when another operation held the order payment lock,
	 * and nothing on a later delivery of a hold already noted.
	 *
	 * @param WC_Order $order     Order the hold was decided for.
	 * @param string   $intent_id PaymentIntent ID.
	 */
	public function log_applied_hold( WC_Order $order, string $intent_id ): void {
		$key = $order->get_id() . '|' . $intent_id;
		if ( ! isset( $this->undelivered_hold_logs[ $key ] ) ) {
			return;
		}

		$hold = $this->undelivered_hold_logs[ $key ];
		if ( 0 === $this->order_payment_notes->find_by_content( $order, $hold['notes'][0], $hold['notes'] ) ) {
			return;
		}

		unset( $this->undelivered_hold_logs[ $key ] );
		$this->logger->error( $hold['line'] );
	}
}
