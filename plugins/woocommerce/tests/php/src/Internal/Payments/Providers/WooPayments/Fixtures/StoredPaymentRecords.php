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
 * Every order is a USD 10.99 WooPayments order. An empty ID argument leaves that value unwritten. A shape is added with
 * the first test that starts from it.
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
	 * Create and save a WooPayments order with the given status, transaction ID and stored values.
	 *
	 * @param string               $status         Order status; a paid status gets a paid date.
	 * @param string               $transaction_id Transaction ID.
	 * @param array<string,string> $meta           Stored values; an empty one is not written.
	 * @return WC_Order
	 */
	private static function create_order( string $status, string $transaction_id, array $meta ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
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
