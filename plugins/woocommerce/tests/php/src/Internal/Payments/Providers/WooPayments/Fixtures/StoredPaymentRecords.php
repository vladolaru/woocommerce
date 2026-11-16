<?php
/**
 * Stored payment records of WooPayments orders, one method per shape.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Order;

/**
 * Builds saved orders whose stored payment values (status, transaction ID, `_intent_id`, `_intention_status`,
 * `_charge_id`) have a shape a store can hold, each method naming the code that writes it.
 *
 * Every order is a USD 10.99 order, a WooPayments one unless its shape says otherwise. An empty ID argument leaves that
 * value unwritten. A shape is added with the first test that starts from it.
 */
final class StoredPaymentRecords {

	/**
	 * Total of every order, in USD.
	 */
	private const TOTAL = '10.99';

	/**
	 * An ordinary payment: the transaction ID and the stored intent name the payment, which succeeded, with its charge.
	 *
	 * Written in one save by the checkout's attach (WooPaymentsOrderEffectApplier::attach_intent_info()) and, in the
	 * WooPayments extension, by attach_intent_info_to_order() (client 11.3.0 class-wc-payments-order-service.php:1875-1884).
	 * On a Pending order it is the same record after a merchant moved the paid order back to Pending, which writes none of
	 * these values.
	 *
	 * @param string $status     Order status; a paid status gets a paid date.
	 * @param string $intent_id  Payment intent, also the transaction ID.
	 * @param string $charge_id  Its charge.
	 * @param string $risk_level The charge's risk level, or '' for none.
	 * @return WC_Order
	 */
	public static function payment( string $status = OrderStatus::PROCESSING, string $intent_id = 'pi_A', string $charge_id = 'ch_A', string $risk_level = '' ): WC_Order {
		return self::create_order(
			$status,
			$intent_id,
			array(
				'_intent_id'         => $intent_id,
				'_intention_status'  => 'succeeded',
				'_charge_id'         => $charge_id,
				'_charge_risk_level' => $risk_level,
			)
		);
	}

	/**
	 * A paid order with no transaction ID: the stored intent names the payment, which succeeded, with its charge.
	 *
	 * Written by a merchant who clears the Transaction ID field on the order screen and saves (WC_Meta_Box_Order_Data::save());
	 * or by a capture that kept the merchant's status before such captures wrote the transaction ID. In the WooPayments
	 * extension by its status-change capture, which writes no transaction ID (client 11.3.0
	 * class-wc-payments-order-service.php:2381-2418), and by its amount-mismatch hold (:2133-2135) once the merchant
	 * completes the order.
	 *
	 * @param string $status    Order status; a paid status gets a paid date.
	 * @param string $intent_id The payment's intent, the stored intent.
	 * @param string $charge_id Its charge, or '' for none.
	 * @return WC_Order
	 */
	public static function paid_without_transaction_id( string $status = OrderStatus::PROCESSING, string $intent_id = 'pi_A', string $charge_id = 'ch_A' ): WC_Order {
		return self::create_order(
			$status,
			'',
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => 'succeeded',
				'_charge_id'        => $charge_id,
			)
		);
	}

	/**
	 * An ordinary authorization: an On hold order whose transaction ID and stored intent name the authorized payment, which
	 * waits for its capture.
	 *
	 * Written by the attach, then the lifecycle's hold, which sets the transaction ID (OrderPaymentLifecycleService
	 * put_order_on_hold()); in the WooPayments extension by the same attach, then mark_payment_authorized() (client 11.3.0
	 * class-wc-payments-order-service.php:2146-2160).
	 *
	 * @param string $intent_id Payment intent, also the transaction ID.
	 * @param string $charge_id Its uncaptured charge.
	 * @return WC_Order
	 */
	public static function authorization( string $intent_id = 'pi_A', string $charge_id = 'ch_A' ): WC_Order {
		return self::create_order(
			OrderStatus::ON_HOLD,
			$intent_id,
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => 'requires_capture',
				'_charge_id'        => $charge_id,
			)
		);
	}

	/**
	 * An authorization whose order has no transaction ID: the stored intent names the attempt, with its status and charge.
	 *
	 * Written by a merchant who clears the Transaction ID field on the order screen and saves (WC_Meta_Box_Order_Data::save()),
	 * or by a REST update that sends an empty transaction_id. Also written by the amount-mismatch refusal, which stores the
	 * refused intent with requires_payment_method and no transaction ID (WooPaymentsOutcomeMetadataMapper::get_outcome_meta()),
	 * when the merchant completes the order before that intent's authorization is recorded.
	 *
	 * @param string $intent_id     The attempt's intent, the stored intent.
	 * @param string $stored_status The status stored for it.
	 * @param string $order_status  Order status; a paid status gets a paid date.
	 * @param string $charge_id     Its charge, or '' for none.
	 * @return WC_Order
	 */
	public static function authorization_without_transaction_id( string $intent_id = 'pi_A', string $stored_status = 'requires_capture', string $order_status = OrderStatus::ON_HOLD, string $charge_id = 'ch_A' ): WC_Order {
		return self::create_order(
			$order_status,
			'',
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => $stored_status,
				'_charge_id'        => $charge_id,
			)
		);
	}

	/**
	 * An authorization whose transaction ID names another attempt: the stored intent names the authorized attempt, with its
	 * status and charge, and the transaction ID an earlier attempt or a typed value.
	 *
	 * Written by the amount-mismatch refusal over an order whose transaction ID an earlier started attempt had set
	 * (OrderPaymentLifecycleService fills an empty transaction ID on a started event), when the merchant completes the order
	 * before the refused intent's authorization is recorded; or by a merchant who types another value into the Transaction ID
	 * field and saves.
	 *
	 * @param string $transaction_id The earlier attempt's intent or the typed value.
	 * @param string $intent_id      The authorized attempt's intent, the stored intent.
	 * @param string $stored_status  The status stored for it.
	 * @param string $order_status   Order status; a paid status gets a paid date.
	 * @param string $charge_id      Its charge, or '' for none.
	 * @return WC_Order
	 */
	public static function authorization_with_another_attempts_transaction_id( string $transaction_id = 'pi_earlier', string $intent_id = 'pi_A', string $stored_status = 'requires_capture', string $order_status = OrderStatus::ON_HOLD, string $charge_id = 'ch_A' ): WC_Order {
		return self::create_order(
			$order_status,
			$transaction_id,
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => $stored_status,
				'_charge_id'        => $charge_id,
			)
		);
	}

	/**
	 * An authorization's requires_capture status stored beside another attempt's intent and charge: the transaction ID
	 * names the authorized payment, the stored intent another attempt.
	 *
	 * Written when a succeeded payment's meta is saved before its decision (PaymentIntentEventHandler
	 * write_succeeded_payment_intent_meta()) and the run stops there; or when a failed capture or cancel of another intent
	 * stores the default requires_capture (WooPaymentsOutcomeMetadataMapper::get_failed_capture_or_cancel_outcome_meta()).
	 * In the WooPayments extension, when a declined attempt writes its intent over the authorization (client 11.3.0
	 * class-wc-payment-gateway-wcpay.php:1610-1614), or when its succeeded webhook stops after writing the intent
	 * (class-wc-payments-webhook-processing-service.php:642-701).
	 *
	 * @param string $status         Order status; a paid status gets a paid date.
	 * @param string $transaction_id The authorized payment's intent.
	 * @param string $intent_id      The other attempt's intent, the stored intent.
	 * @param string $charge_id      The other attempt's charge.
	 * @return WC_Order
	 */
	public static function authorization_status_beside_another_intent( string $status = OrderStatus::ON_HOLD, string $transaction_id = 'pi_A', string $intent_id = 'pi_B', string $charge_id = 'ch_B' ): WC_Order {
		return self::create_order(
			$status,
			$transaction_id,
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => 'requires_capture',
				'_charge_id'        => $charge_id,
			)
		);
	}

	/**
	 * An earlier declined attempt that stores its charge: a Pending order whose transaction ID and stored intent name the
	 * declined attempt, with its declined charge.
	 *
	 * Written by the attach of a declined attempt that has a charge, with that charge's risk level
	 * (WooPaymentsOrderEffectApplier::attach_intent_info()); in the WooPayments extension by attach_intent_info_to_order(),
	 * which sets the intent's latest charge and its risk level (client 11.3.0 class-wc-payments-order-service.php:1829-1834,
	 * :1873-1884).
	 *
	 * @param string $risk_level The charge's risk level, or '' for none.
	 * @param string $intent_id  The declined attempt's intent, also the transaction ID.
	 * @param string $charge_id  Its declined charge.
	 * @return WC_Order
	 */
	public static function declined_attempt_with_charge( string $risk_level = '', string $intent_id = 'pi_earlier', string $charge_id = 'ch_earlier' ): WC_Order {
		return self::create_order(
			OrderStatus::PENDING,
			$intent_id,
			array(
				'_intent_id'         => $intent_id,
				'_intention_status'  => 'requires_payment_method',
				'_charge_id'         => $charge_id,
				'_charge_risk_level' => $risk_level,
			)
		);
	}

	/**
	 * A setup intent as the stored record: a Pending order with a total above zero whose transaction ID and stored intent
	 * name a card setup that succeeded, with no charge.
	 *
	 * The confirmation paths attach a $0 checkout's setup intent with its succeeded status
	 * (WooPaymentsIntentConfirmationService, the setup intent's attach); the order then stores no payment once a merchant
	 * raises its total and moves it back to Pending.
	 *
	 * @param string $intent_id Setup intent, also the transaction ID.
	 * @return WC_Order
	 */
	public static function setup_intent_record( string $intent_id = 'seti_S' ): WC_Order {
		return self::create_order(
			OrderStatus::PENDING,
			$intent_id,
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => 'succeeded',
			)
		);
	}


	/**
	 * A payment beside a later declined retry: the transaction ID names the payment and its charge, the stored intent the
	 * declined retry, with the declined status.
	 *
	 * A declined retry's failed outcome writes its intent and the default requires_payment_method status
	 * (WooPaymentsOutcomeMetadataMapper::get_outcome_meta()) and leaves the transaction ID on the payment; in the
	 * WooPayments extension a declined request writes the intent only (client 11.3.0
	 * class-wc-payment-gateway-wcpay.php:1610-1614).
	 *
	 * @param string $transaction_id The payment's intent.
	 * @param string $intent_id      The declined retry's intent, the stored intent.
	 * @param string $charge_id      The payment's charge.
	 * @return WC_Order
	 */
	public static function payment_beside_a_declined_retry( string $transaction_id = 'pi_A', string $intent_id = 'pi_B', string $charge_id = 'ch_A' ): WC_Order {
		return self::create_order(
			OrderStatus::PROCESSING,
			$transaction_id,
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => 'requires_payment_method',
				'_charge_id'        => $charge_id,
			)
		);
	}

	/**
	 * A WooPayments payment kept on an order whose payment method is another gateway's: the transaction ID and the stored
	 * intent name the payment, which succeeded, with its charge, while the order's payment method names, for example,
	 * bank transfer.
	 *
	 * Written by the checkout's attach (WooPaymentsOrderEffectApplier::attach_intent_info()), then a change of the order's
	 * payment method that writes none of these values, such as a merchant's edit on the order screen. In the WooPayments
	 * extension by attach_intent_info_to_order() (client 11.3.0 class-wc-payments-order-service.php:1875-1884), then its
	 * cleanup for another gateway, which keeps a payment that went through (same file, :1284-1288).
	 *
	 * @param string $status         Order status; a paid status gets a paid date.
	 * @param string $payment_method The other gateway's ID.
	 * @param string $intent_id      Payment intent, also the transaction ID.
	 * @param string $charge_id      Its charge.
	 * @return WC_Order
	 */
	public static function payment_kept_on_an_order_of_another_method( string $status = OrderStatus::PROCESSING, string $payment_method = 'bacs', string $intent_id = 'pi_A', string $charge_id = 'ch_A' ): WC_Order {
		return self::create_order(
			$status,
			$intent_id,
			array(
				'_intent_id'        => $intent_id,
				'_intention_status' => 'succeeded',
				'_charge_id'        => $charge_id,
			),
			$payment_method
		);
	}

	/**
	 * An earlier WooPayments attempt's record on an order whose checkout another payment method has just taken: the
	 * attempt's mode, payment method, customer, stored intent and status, and its charge and transaction ID when it wrote
	 * them, while the order's payment method names, for example, bank transfer.
	 *
	 * Written by the attempt: the charge key's save writes the mode at the attempt's start
	 * (WooPaymentsPaymentOperationService::ensure_charge_idempotency_key()); a declined request's failed outcome writes the
	 * intent, the default requires_payment_method status, the payment method and the customer
	 * (WooPaymentsOutcomeMetadataMapper::get_outcome_meta()); a started or paid attempt's attach writes the transaction ID
	 * and the charge (WooPaymentsOrderEffectApplier::attach_intent_info()). Then the other gateway's checkout sets its
	 * payment method on the reused order before the checkout hooks fire (the Store API's CheckoutTrait::update_order_from_request(),
	 * WC_Checkout::create_order(), WC_Form_Handler::pay_action()). In the WooPayments extension the same values come from
	 * process_payment_for_order() (client 11.3.0 class-wc-payment-gateway-wcpay.php:1962-1964 and its setters).
	 *
	 * @param string $stored_status  The status stored for the attempt.
	 * @param string $intent_id      The attempt's intent, the stored intent, or '' for none.
	 * @param string $transaction_id The transaction ID, or '' for none.
	 * @param string $charge_id      The stored charge, or '' for none.
	 * @param string $order_status   Order status; a paid status gets a paid date.
	 * @param string $payment_method The other gateway's ID.
	 * @param string $mode           The stored mode, or '' for none.
	 * @return WC_Order
	 */
	public static function attempt_before_a_takeover( string $stored_status = 'requires_payment_method', string $intent_id = 'pi_A', string $transaction_id = '', string $charge_id = '', string $order_status = OrderStatus::PENDING, string $payment_method = 'bacs', string $mode = 'test' ): WC_Order {
		return self::create_order(
			$order_status,
			$transaction_id,
			array(
				'_intent_id'          => $intent_id,
				'_intention_status'   => $stored_status,
				'_charge_id'          => $charge_id,
				'_payment_method_id'  => 'pm_A',
				'_stripe_customer_id' => 'cus_A',
				'_wcpay_mode'         => $mode,
			),
			$payment_method
		);
	}

	/**
	 * An order after a takeover cleanup: the abandoned intents listed and the takeover time stored, the five removed values
	 * gone, and what stayed or came later: the kept charge, a transaction ID the shutdown step did not clear, or a later
	 * attempt's stored intent and mode.
	 *
	 * Written by the cleanup (WooPaymentsEarlierAttemptCleanup; client 11.3.0 class-wc-payments-order-service.php:1304-1322),
	 * then, for a later attempt, by its own writers: a raised decline's failed outcome stores its intent and the charge key's
	 * save its mode (WooPaymentsOutcomeMetadataMapper, WooPaymentsPaymentOperationService); the in-person route stores its
	 * intent and transaction ID before the capture and the mode after it (WooPaymentsTerminalPaymentsRestController).
	 *
	 * @param string[] $superseded_intent_ids The listed intents.
	 * @param int      $taken_over_at         The takeover time, as a Unix time.
	 * @param string   $order_status          Order status; a paid status gets a paid date.
	 * @param string   $transaction_id        The transaction ID, or '' for none.
	 * @param string   $charge_id             The stored charge, or '' for none.
	 * @param string   $payment_method        The order's payment method.
	 * @param string   $stored_intent         A later attempt's stored intent, or '' for none.
	 * @param string   $mode                  A later attempt's stored mode, or '' for none.
	 * @return WC_Order
	 */
	public static function after_a_takeover_cleanup( array $superseded_intent_ids, int $taken_over_at, string $order_status = OrderStatus::PENDING, string $transaction_id = '', string $charge_id = '', string $payment_method = 'bacs', string $stored_intent = '', string $mode = '' ): WC_Order {
		$order = self::create_order(
			$order_status,
			$transaction_id,
			array(
				'_charge_id'  => $charge_id,
				'_intent_id'  => $stored_intent,
				'_wcpay_mode' => $mode,
				WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY => (string) $taken_over_at,
			),
			$payment_method
		);
		if ( array() !== $superseded_intent_ids ) {
			$order->update_meta_data( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, $superseded_intent_ids );
			$order->save();
		}

		return $order;
	}

	/**
	 * Create and save an order with the given status, transaction ID, stored values and payment method.
	 *
	 * @param string               $status         Order status; a paid status gets a paid date.
	 * @param string               $transaction_id Transaction ID.
	 * @param array<string,string> $meta           Stored values; an empty one is not written.
	 * @param string               $payment_method Payment method; WooPayments unless a shape names another.
	 * @return WC_Order
	 */
	private static function create_order( string $status, string $transaction_id, array $meta, string $payment_method = WooPaymentsPersistenceVocabulary::GATEWAY_ID ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( $payment_method );
		$order->set_currency( 'USD' );
		$order->set_total( self::TOTAL );
		$order->set_status( $status );
		if ( in_array( $status, wc_get_is_paid_statuses(), true ) ) {
			$order->set_date_paid( time() );
		}
		$order->set_transaction_id( $transaction_id );
		foreach ( $meta as $key => $value ) {
			if ( '' !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}
		$order->save();

		return $order;
	}
}
