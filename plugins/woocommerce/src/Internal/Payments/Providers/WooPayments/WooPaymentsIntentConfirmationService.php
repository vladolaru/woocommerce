<?php
/**
 * WooPaymentsIntentConfirmationService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Throwable;
use WC_Order;

/**
 * Confirms a fetched payment or setup intent onto its order: lifecycle, payment method details and the saved token.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsIntentConfirmationService {

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Fee details note controller, which applies the confirmed intent's payment lifecycle event.
	 *
	 * @var WooPaymentsFeeDetailsNoteController
	 */
	private WooPaymentsFeeDetailsNoteController $fee_details_note_controller;

	/**
	 * WooPayments token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier
	 */
	private WooPaymentsOrderEffectApplier $order_effect_applier;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param Client                              $api_client                  WooPayments API client.
	 * @param WooPaymentsFeeDetailsNoteController $fee_details_note_controller Fee details note controller.
	 * @param WooPaymentsTokenService             $token_service               WooPayments token service.
	 * @param WooPaymentsAccountService           $account_service             WooPayments account service.
	 * @param WooPaymentsOrderEffectApplier       $order_effect_applier        WooPayments order effect applier.
	 */
	final public function init(
		Client $api_client,
		WooPaymentsFeeDetailsNoteController $fee_details_note_controller,
		WooPaymentsTokenService $token_service,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderEffectApplier $order_effect_applier
	): void {
		$this->api_client                  = $api_client;
		$this->fee_details_note_controller = $fee_details_note_controller;
		$this->token_service               = $token_service;
		$this->account_service             = $account_service;
		$this->order_effect_applier        = $order_effect_applier;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The method throws its domain exception and propagates the platform's and downstream Throwables.
	/**
	 * Confirm an intent and apply its result to an order.
	 *
	 * @param WC_Order $order               Order being confirmed.
	 * @param string   $intent_id           PaymentIntent or SetupIntent ID.
	 * @param bool     $save_payment_method Whether to persist the payment method.
	 * @param bool     $is_payment_method_change Whether this confirms a subscription payment method change.
	 * @throws ApiException When intent retrieval fails.
	 * @throws WooPaymentsIntentConfirmationException When the intent's metadata does not name the order, the intent cannot be authorized or a required token cannot be saved.
	 * @throws Throwable When lifecycle, token, or payment-method effects fail.
	 *
	 * @since 11.2.0
	 */
	public function confirm_intent_for_order( WC_Order $order, string $intent_id, bool $save_payment_method, bool $is_payment_method_change = false ): void {
		// Client 11.1.0 gw:4217, 4238-4242: a payment method change confirms a SetupIntent whatever the subscription's total.
		$intent = $is_payment_method_change || 0.0 >= (float) $order->get_total()
			? $this->api_client->get_setup_intention( $intent_id )
			: $this->api_client->get_payment_intention( $intent_id );

		// Client 11.3.0 gw:4552-4575, 4648, 4670: the fetched intent must name the order before anything is written on it.
		if ( ! WooPaymentsIntentCodec::intent_belongs_to_order( $intent, $order ) ) {
			$this->add_intent_mismatch_note( $order, $intent_id );
			throw new WooPaymentsIntentConfirmationException( esc_html__( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}

		if ( ! $is_payment_method_change ) {
			$this->confirm_fetched_intent_for_order( $order, $intent, $save_payment_method, false, true );
			return;
		}

		// Client 11.3.0 gw:4708-4713: a card change records the intent's payment method on the order first.
		$payment_method_id = WooPaymentsIntentCodec::result_payment_method_id( $intent );
		if ( '' !== $payment_method_id ) {
			$order->read_meta_data( true );
			$order->update_meta_data( WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_ID_META_KEY, $payment_method_id );
			$order->save();
		}

		// Client 11.3.0 gw:4738, 4766-4772: the intent status is recorded after the card is saved, and before an intent that holds
		// no money is refused; a card that cannot be saved leaves the earlier status.
		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		if ( ! WooPaymentsIntentCodec::holds_money( $status ) ) {
			$this->save_intention_status( $order, $status );
		}

		$this->confirm_fetched_intent_for_order( $order, $intent, $save_payment_method, false, false );

		$this->save_intention_status( $order, $status );
	}

	/**
	 * Write the payment method's display details an intent that holds money carries: a $0 SetupIntent's card details when
	 * they could be applied, the charge's details otherwise.
	 *
	 * @param WC_Order                 $order                 Order being confirmed.
	 * @param array<string,mixed>      $intent                Platform intent response.
	 * @param array<string,mixed>|null $setup_payment_details A $0 SetupIntent's payment method details, or null for any other intent.
	 */
	private function apply_display_details( WC_Order $order, array $intent, ?array $setup_payment_details ): void {
		if ( ! WooPaymentsIntentCodec::holds_money( isset( $intent['status'] ) ? (string) $intent['status'] : '' ) ) {
			return;
		}

		if ( null !== $setup_payment_details && $this->order_effect_applier->apply_setup_intent_payment_method_display_details( $order, $setup_payment_details, $this->account_service->get_account_country() ) ) {
			return;
		}

		$this->apply_payment_method_display_details( $order, $intent );
	}

	/**
	 * Save the payment method the order-status callback's intent confirmed on the order, a PaymentIntent's or a SetupIntent's,
	 * whatever its attach and its event write (client 11.3.0 class-wc-payment-gateway-wcpay.php:4708-4715).
	 *
	 * @param WC_Order            $order  Order read under the order payment lock.
	 * @param array<string,mixed> $intent Intent response.
	 */
	private function save_intent_payment_method( WC_Order $order, array $intent ): void {
		$payment_method_id = WooPaymentsIntentCodec::result_payment_method_id( $intent );
		if ( '' === $payment_method_id ) {
			return;
		}

		$order->update_meta_data( WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_ID_META_KEY, $payment_method_id );
		$order->save_meta_data();
	}

	/**
	 * Save an intent's status on the order.
	 *
	 * @param WC_Order $order  Order the intent belongs to.
	 * @param string   $status Intent status.
	 */
	private function save_intention_status( WC_Order $order, string $status ): void {
		$order->update_meta_data( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, $status );
		$order->save();
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Tell whether an order's payment method is another gateway's, so a confirmation of a WooPayments intent leaves it.
	 *
	 * Classic order-pay saves the gateway the shopper picks on the order and leaves an abandoned WooPayments attempt stored
	 * on it; that attempt's confirmation must not record it over the other payment or move the order. A subscription is
	 * exempt: a card change may replace its payment method, which becomes WooPayments only after the confirmation. Client
	 * 11.3.0 has no such check (class-wc-payment-gateway-wcpay.php:2564-2628, :4611-4648).
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order Order read for the decision.
	 * @return bool
	 */
	public function is_order_of_another_payment_method( WC_Order $order ): bool {
		return ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) && ! SubscriptionMethodPolicy::only_saves_the_card( $order );
	}

	/**
	 * Tell whether an intent ID is the one the order stores, the test the order-status callback applies before its fetch
	 * and again under the order payment lock. An empty ID never is.
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order     Order read for the decision.
	 * @param string   $intent_id Intent ID the callback named.
	 * @return bool
	 */
	public function is_order_intent( WC_Order $order, string $intent_id ): bool {
		return '' !== $intent_id && (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) === $intent_id;
	}

	/**
	 * Log the one warning line of a request refused because its order's payment method is another gateway's.
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order       Order read for the decision.
	 * @param string   $intent_id   Intent ID the request named.
	 * @param string   $entry_point `order-status callback` or `redirect return`.
	 */
	public function log_refusal_of_another_payment_method( WC_Order $order, string $intent_id, string $entry_point ): void {
		// A redirect return names its intent before the stored check, and the REST API can set any payment method.
		$payment_method = WooPaymentsLogger::get_loggable_payment_method( (string) $order->get_payment_method() );
		$printed_intent = WooPaymentsLogger::get_loggable_provider_id( $intent_id );
		wc_get_logger()->warning(
			sprintf( '%1$s refused: order %2$d has payment method "%3$s", so payment %4$s was not applied', $entry_point, $order->get_id(), $payment_method, $printed_intent ),
			array(
				'source'         => WooPaymentsLogger::SOURCE,
				'order_id'       => $order->get_id(),
				'intent_id'      => $printed_intent,
				'payment_method' => $payment_method,
			)
		);
	}

	/**
	 * Tell whether a confirmed intent is the order's own payment, by the webhook's own test with the inputs the webhook
	 * uses for it: the intent's ID, its first charge, and the intent standing in for an empty transaction ID only for a
	 * succeeded payment intent on an order in a paid status (EventOrderResolver).
	 *
	 * @param WC_Order            $order  Order read under the order payment lock, or read fresh after a refused claim.
	 * @param array<string,mixed> $intent Confirmed intent.
	 * @return bool
	 */
	private function is_order_payment( WC_Order $order, array $intent ): bool {
		$intent_id = isset( $intent['id'] ) ? (string) $intent['id'] : '';
		$status    = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$resolver  = wc_get_container()->get( EventOrderResolver::class );

		return $resolver->is_own_payment(
			$order,
			$intent_id,
			WooPaymentsIntentCodec::first_charge_id( $intent ),
			$resolver->get_stand_in_intent_id(
				$intent_id,
				'succeeded' === $status && 0 === strpos( $intent_id, 'pi_' ),
				wc_get_container()->get( OrderPaymentLifecycleService::class )->has_paid_status( $order )
			)
		);
	}

	/**
	 * Log, whatever the logging setting, a confirmation that left a payment alone because it is not the order's own.
	 *
	 * The order's transaction ID is not printed: a merchant can type anything there.
	 *
	 * @param WC_Order            $order              Order the decision was made on.
	 * @param array<string,mixed> $intent             Confirmed intent.
	 * @param bool                $is_redirect_return Whether the redirect return confirmed it.
	 */
	private function log_payment_not_own( WC_Order $order, array $intent, bool $is_redirect_return ): void {
		$printed_intent = WooPaymentsLogger::get_loggable_provider_id( isset( $intent['id'] ) ? (string) $intent['id'] : '' );
		$entry_point    = $is_redirect_return ? 'redirect return' : 'order-status callback';
		wc_get_container()->get( WooPaymentsLogger::class )->log_always(
			sprintf( '%1$s left payment %2$s alone: it is not the own payment of order #%3$d (%4$s).', $entry_point, $printed_intent, $order->get_id(), $order->get_status() ),
			'warning',
			array(
				'order_id'     => $order->get_id(),
				'intent_id'    => $printed_intent,
				'order_status' => $order->get_status(),
				'entry_point'  => $entry_point,
				'reason'       => 'confirmation_left_payment_not_own',
			)
		);
	}

	/**
	 * Note on the order that the order-status callback ignored an intent ID that is not the order's payment.
	 *
	 * The wording is the client's for `intent_id_mismatch` and `empty_intent_id` (client 11.3.0 gw:4795-4811).
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order     Order the callback named.
	 * @param string   $intent_id Intent ID the callback received, or the translated "unknown".
	 */
	public function add_intent_mismatch_note( WC_Order $order, string $intent_id ): void {
		$order->add_order_note(
			sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					/* translators: %1: transaction ID of the payment or a translated string indicating an unknown ID. */
					__( 'A payment with ID <code>%1$s</code> was used in an attempt to pay for this order. This payment intent ID does not match any payments for this order, so it was ignored and the order was not updated.', 'woocommerce' ),
					array( 'code' => '<code>' )
				),
				$intent_id
			)
		);
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The method explicitly throws its domain exception and can propagate downstream Throwables.
	/**
	 * Confirm an already-fetched intent and apply its result to an order.
	 *
	 * @param WC_Order            $order                      Order being confirmed.
	 * @param array<string,mixed> $intent                     PaymentIntent or SetupIntent response.
	 * @param bool                $save_payment_method        Whether to persist the payment method.
	 * @param bool                $is_redirect_return         Whether this is the redirect return. It checks the intent's error before calling this (client gw:2377-2382),
	 *                                                        and here a token-save error does not stop it (gw:2389-2396) and a status that is not
	 *                                                        authorized does not throw (os:399-432 maps it without an exception).
	 * @param bool                $zero_amount_plain_note     Whether a $0 SetupIntent completes with the order-status callback's plain note (not on a payment method change).
	 * @throws WooPaymentsIntentConfirmationException When the order-status callback's intent cannot be authorized, its order's payment method is
	 *                                                another gateway's (the redirect return then ends without one), or a
	 *                                                required token cannot be saved.
	 * @throws Throwable When confirmation is rejected or lifecycle, token, or payment-method effects fail.
	 *
	 * @since 11.2.0
	 */
	public function confirm_fetched_intent_for_order( WC_Order $order, array $intent, bool $save_payment_method, bool $is_redirect_return = false, bool $zero_amount_plain_note = false ): void {
		$status                     = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$setup_payment_details      = null;
		$previous_payment_method_id = (string) $order->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_ID_META_KEY, true );

		if ( WooPaymentsIntentCodec::holds_money( $status ) ) {
			$token_save_result = $this->maybe_save_payment_method_for_order( $order, $intent, $save_payment_method );
			// The redirect return logs a token-save error and completes the order; only the order-status callback stops a recurring order (gw:2389-2396, 4309-4321).
			if ( null !== $token_save_result['error'] && ! $is_redirect_return ) {
				throw new WooPaymentsIntentConfirmationException(
					esc_html( (string) ( $token_save_result['error']['error']['message'] ?? '' ) ),
					(int) ( $token_save_result['error']['status_code'] ?? 409 )
				);
			}

			// A $0 SetupIntent's card details come from the platform, so they are read before the order payment lock is claimed.
			if ( 0.0 >= (float) $order->get_total() && 'succeeded' === $status && 0 === strpos( (string) ( $intent['id'] ?? '' ), 'seti_' ) && $token_save_result['token'] instanceof \WC_Payment_Token ) {
				$setup_payment_details = $token_save_result['payment_method_details'];
				if ( empty( $setup_payment_details ) ) {
					$setup_payment_details = $this->order_effect_applier->get_same_method_payment_method_details( $order, WooPaymentsIntentCodec::result_payment_method_id( $intent ), $previous_payment_method_id );
					if ( empty( $setup_payment_details ) ) {
						$setup_payment_details = $this->token_service->get_or_create_token_and_details_for_user(
							WooPaymentsIntentCodec::result_payment_method_id( $intent ),
							WooPaymentsCustomerService::get_payer_user_id( $order ),
							true
						)['payment_method_details'];
					}
				}
			}
		}

		// Under the order payment lock, on the order read under it: the intent is attached, the payment method's display
		// details written, and the event then built and the hold decided on that order as attached, as client 11.3.0
		// attaches, titles the order and then updates it (class-wc-payment-gateway-wcpay.php:2700, :2705-2707; :4663,
		// :4713-4715, :4758-4764, :4771; class-wc-payments-order-service.php:484-489). A started payment the order no longer
		// waits for is nothing to apply. Nothing is written on an order whose payment method became another gateway's, nor on
		// one whose payment this is not by the webhook's own test, read under the lock or, when another operation holds it,
		// read fresh. The account the build reads is settled before the claim.
		$attaches_intent = $is_redirect_return || ! $this->is_setup_intent_for_order( $intent, $order );
		$displayed       = false;
		$refusing_order  = null;
		list( $event )   = $this->build_lifecycle_event_from_intent( $intent, $order, $zero_amount_plain_note );
		$build           = function ( WC_Order $stored_order ) use ( $intent, $status, $is_redirect_return, $zero_amount_plain_note, $attaches_intent, $setup_payment_details, &$displayed, &$refusing_order ): array {
			if ( $this->is_order_of_another_payment_method( $stored_order ) ) {
				$refusing_order = $stored_order;
				return array( null, null );
			}
			// The callback's check before its fetch, and the return's after it, repeated: an intent that holds no money and is no
			// longer the order's, because a later attempt was stored meanwhile, changes nothing, so it cannot fail, cancel or
			// re-record that attempt.
			if ( ! WooPaymentsIntentCodec::holds_money( $status ) && ! $this->is_order_intent( $stored_order, (string) ( $intent['id'] ?? '' ) ) ) {
				$displayed = true;
				$this->log_payment_not_own( $stored_order, $intent, $is_redirect_return );
				return array( null, null );
			}
			// A payment the webhook would record as another charge on this order, such as one over a reference the merchant
			// typed on a paid order, is left to it: nothing is written, the display details included. Client 11.3.0 attaches
			// whatever the order records (class-wc-payment-gateway-wcpay.php:4663, :4771).
			if ( ! $this->is_order_payment( $stored_order, $intent ) ) {
				$displayed = true;
				$this->log_payment_not_own( $stored_order, $intent, $is_redirect_return );
				return array( null, null );
			}
			if ( $attaches_intent ) {
				$this->attach_intent_before_decision( $intent, $stored_order, $zero_amount_plain_note );
			}
			$this->apply_display_details( $stored_order, $intent, $setup_payment_details );
			$displayed = true;
			if ( $zero_amount_plain_note ) {
				$this->save_intent_payment_method( $stored_order, $intent );
			}

			list( $built_event, $hold, , $changes_nothing ) = $this->build_lifecycle_event_and_intent_meta( $intent, $stored_order, $zero_amount_plain_note );
			// The callback's failed intent goes through client 11.3.0's mark_payment_failed(), which changes nothing once the
			// failure is recorded (class-wc-payment-gateway-wcpay.php:4771; class-wc-payments-order-service.php:500-504, :545-559).
			$recorded_failure = ! $is_redirect_return && PaymentLifecycleEvent::STATUS_FAILED === $built_event->get_status()
				&& wc_get_container()->get( WooPaymentsOrderNoteService::class )->is_failure_recorded( $stored_order, $built_event );

			return array( $changes_nothing || $recorded_failure ? null : $built_event, $hold );
		};
		$result          = wc_get_container()->get( WooPaymentsCompositionDataSettler::class )->run_settled(
			fn(): string => $this->fee_details_note_controller->apply_and_schedule_fee_details_with_lock( $order, $event, $build, $intent )
		);
		if ( OrderPaymentLifecycleService::RESULT_REFUSED === $result ) {
			$stored_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
			if ( $this->is_order_of_another_payment_method( $stored_order ) ) {
				$refusing_order = $stored_order;
			} elseif ( ! $this->is_order_payment( $stored_order, $intent ) ) {
				$displayed = true;
				$this->log_payment_not_own( $stored_order, $intent, $is_redirect_return );
			} else {
				// Every write goes on the one order read after the refusal, so none adds a second row of a key it saved.
				$this->attach_charge_details_after_refusal( $stored_order, $event );
				$stored_intent_id = (string) $stored_order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true );
				if ( '' === $stored_intent_id || (string) ( $intent['id'] ?? '' ) === $stored_intent_id ) {
					$this->apply_display_details( $stored_order, $intent, $setup_payment_details );
				}
				$displayed = true;
			}
		}
		if ( null !== $refusing_order ) {
			$this->log_refusal_of_another_payment_method( $refusing_order, (string) ( $intent['id'] ?? '' ), $is_redirect_return ? 'redirect return' : 'order-status callback' );
			// The redirect return ends without an error, as its own payment method checks do.
			if ( $is_redirect_return ) {
				return;
			}
			throw new WooPaymentsIntentConfirmationException( esc_html__( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}
		if ( ! $displayed ) {
			// A subscription's card change takes no claim: no event is applied, and the display details are written on the caller's order.
			$this->apply_display_details( $order, $intent, $setup_payment_details );
		}

		if ( ! $is_redirect_return && ! WooPaymentsIntentCodec::holds_money( $status ) ) {
			throw new WooPaymentsIntentConfirmationException( esc_html__( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Save the charge an intent names on its order when the order payment lock refused the intent's lifecycle event.
	 *
	 * Client 11.1.0 stores the intent's charge before its order lock (class-wc-payments-order-service.php:1351-1418, called at
	 * class-wc-payment-gateway-wcpay.php:4236), and no other writer stores a declined charge, so the charge ID, its risk level
	 * and its payment method details are saved here. Only meta is saved; the holder of the lock writes the status and note.
	 * Like the client (:1353), nothing is saved once the stored intent status is succeeded; nor once the order names another intent.
	 *
	 * @param WC_Order              $stored_order Order being confirmed, read fresh after the refusal.
	 * @param PaymentLifecycleEvent $event        Refused lifecycle event.
	 */
	private function attach_charge_details_after_refusal( WC_Order $stored_order, PaymentLifecycleEvent $event ): void {
		if ( 'succeeded' === (string) $stored_order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true ) || (string) $stored_order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) !== (string) $event->get_payment_reference() ) {
			return;
		}

		$charge_meta = array_intersect_key( $event->get_meta_to_update(), array_flip( array( WooPaymentsPersistenceVocabulary::CHARGE_ID_META_KEY, WooPaymentsPersistenceVocabulary::CHARGE_RISK_LEVEL_META_KEY, WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_DETAILS_META_KEY ) ) );
		if ( empty( $charge_meta ) ) {
			return;
		}

		foreach ( $charge_meta as $key => $value ) {
			$stored_order->update_meta_data( $key, $value );
		}
		$stored_order->save_meta_data();
	}

	/**
	 * Apply the PaymentIntent an earlier checkout request attached to the order, once the duplicate-payment check found it holds the money.
	 *
	 * Unlike a confirmation, it saves no token and applies no display details, and it writes fee meta only for a payment
	 * that completes a stored authorization as a capture. An intent that carries no payment method or customer falls back
	 * to the ones stored on the order. Nothing is applied when the order read under the lock changed since the apply was
	 * asked to pay with another gateway, or stores an intent other than this one and the one it stored then: a newer
	 * attempt's.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order            $order  Order the intent pays.
	 * @param array<string,mixed> $intent PaymentIntent response.
	 */
	public function apply_attached_payment_intent( WC_Order $order, array $intent ): void {
		$known_method     = (string) $order->get_payment_method();
		$known_intent_ids = array( (string) ( $intent['id'] ?? '' ), (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ), '' );
		// The event is built again, and the hold decided, on the order read under the order payment lock it is applied under.
		list( $event ) = $this->build_attached_lifecycle_event( $order, $intent );
		wc_get_container()->get( WooPaymentsCompositionDataSettler::class )->run_settled(
			fn(): string => $this->fee_details_note_controller->apply_and_schedule_fee_details_with_lock(
				$order,
				$event,
				fn( WC_Order $stored_order ): array => self::is_still_attached_to( $stored_order, $known_method, $known_intent_ids )
					? $this->build_attached_lifecycle_event( $stored_order, $intent )
					: array( null, null ),
				$intent
			)
		);
	}

	/**
	 * Tell whether the order read under the lock still pays with the gateway it had, or a WooPayments one, and stores no
	 * intent other than the known ones.
	 *
	 * @param WC_Order $stored_order     Order read under the lock.
	 * @param string   $known_method     Payment method the order had when the apply was asked.
	 * @param string[] $known_intent_ids The applied intent, the intent the order stored then, and none.
	 * @return bool
	 */
	private static function is_still_attached_to( WC_Order $stored_order, string $known_method, array $known_intent_ids ): bool {
		$method = (string) $stored_order->get_payment_method();

		return ( $known_method === $method || WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $method ) ) && in_array( (string) $stored_order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ), $known_intent_ids, true );
	}

	/**
	 * Build the payment lifecycle event for a PaymentIntent an earlier checkout request attached to the order.
	 *
	 * @param WC_Order            $order  Order the intent pays.
	 * @param array<string,mixed> $intent PaymentIntent response.
	 * @return array{0:PaymentLifecycleEvent,1:array{notes:string[],log_line:string}|null} The event, and the amount-mismatch hold decided for the intent.
	 */
	private function build_attached_lifecycle_event( WC_Order $order, array $intent ): array {
		$provider_redirect_url = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent ) );
		$outcome               = $this->enrich_outcome_for_lifecycle(
			$order,
			$intent,
			new WooPaymentsIntentMappingContext(
				$order->get_checkout_order_received_url(),
				(string) $order->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_ID_META_KEY, true ),
				(string) $order->get_meta( WooPaymentsPersistenceVocabulary::STRIPE_CUSTOMER_ID_META_KEY, true ),
				'',
				'pi',
				$provider_redirect_url
			),
			WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false )->without_fee_meta()
		);

		return array( $this->lifecycle_event( $outcome, ( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome ) ), $this->get_amount_mismatch_hold( $outcome ) );
	}

	/**
	 * Build a payment lifecycle event from a platform intent response.
	 *
	 * The order-status callback, the redirect return and the authorization webhook build their event here, as client
	 * 11.3.0 sends all three through update_order_status_from_intent() (class-wc-payments-order-service.php:466-500); the
	 * terminal capture takes the intent's meta from it to attach the intent. It writes nothing: the caller applies the
	 * event under the order payment lock, on the order it read under it.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $intent                     Platform intent response.
	 * @param WC_Order            $order                      Order being updated.
	 * @param bool                $zero_amount_plain_note     Whether a completed $0 SetupIntent gets the order-status callback's plain note.
	 * @return array{0:PaymentLifecycleEvent,1:array{notes:string[],log_line:string}|null} The event, and the amount-mismatch hold decided for the intent.
	 */
	public function build_lifecycle_event_from_intent( array $intent, WC_Order $order, bool $zero_amount_plain_note = false ): array {
		list( $event, $hold ) = $this->build_lifecycle_event_and_intent_meta( $intent, $order, $zero_amount_plain_note );

		return array( $event, $hold );
	}

	/**
	 * Build a payment lifecycle event from a platform intent response, as build_lifecycle_event_from_intent() does, with the
	 * intent's meta the attach writes, and whether the outcome changes nothing (PaymentOutcome::DATA_NO_ORDER_CHANGE), as a
	 * started payment the order no longer waits for does; the confirmation then applies no event.
	 *
	 * @param array<string,mixed> $intent                 Platform intent response.
	 * @param WC_Order            $order                  Order being updated.
	 * @param bool                $zero_amount_plain_note Whether a completed $0 SetupIntent gets the order-status callback's plain note.
	 * @return array{0:PaymentLifecycleEvent,1:array{notes:string[],log_line:string}|null,2:array<string,string>,3:bool} The event, the hold, the
	 *                                                                                                               intent's meta, and whether the
	 *                                                                                                               outcome changes nothing.
	 */
	private function build_lifecycle_event_and_intent_meta( array $intent, WC_Order $order, bool $zero_amount_plain_note ): array {
		$intent_id             = isset( $intent['id'] ) ? (string) $intent['id'] : '';
		$is_setup              = $this->is_setup_intent_for_order( $intent, $order );
		$provider_redirect_url = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent ) );
		$provider_status       = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$intent                = WooPaymentsIntentCodec::intent_for_order_status_update( $intent, $is_setup );
		$plan                  = $is_setup
			? WooPaymentsOrderEffectPlan::for_setup_intent(
				$intent,
				false,
				array(
					// Plugin 11.1.0 stores the order currency for setup intents (class-wc-payments-order-service.php:1361).
					WooPaymentsPersistenceVocabulary::INTENT_CURRENCY_META_KEY => (string) $order->get_currency(),
				)
			)
			: WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false );
		$outcome               = $this->enrich_outcome_for_lifecycle(
			$order,
			$intent,
			new WooPaymentsIntentMappingContext(
				$order->get_checkout_order_received_url(),
				'',
				'',
				'',
				$is_setup ? 'si' : 'pi',
				$provider_redirect_url
			),
			$plan
		);

		$note_candidates = null;
		$note_type       = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE;
		if ( $zero_amount_plain_note && $is_setup && 0.0 >= (float) $order->get_total() && PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() ) {
			// Client update_order_status() completes a $0 order itself with a plain note; its order service then sees a paid order
			// and writes no success note, so no fee job either (gw:4248-4275, os:2747-2764).
			$note_candidates = $order->is_paid() ? array() : wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_zero_amount_setup_success_note_candidates( $order, $intent_id );
		} elseif ( ! $is_setup && 'requires_payment_method' === (string) ( $intent['status'] ?? '' ) && ! $order->has_status( OrderStatus::FAILED ) ) {
			// Client update_order_status_from_intent() fails the order with its own note (os:418-422 → mark_payment_failed(),
			// which adds nothing to an order already failed, os:469-472). The note shares the payment_intent.payment_failed
			// webhook note's identity, so whichever runs second adds none.
			$charge          = WooPaymentsIntentCodec::latest_charge( $intent );
			$note_candidates = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_order_status_update_payment_failed_note_candidates(
				$order,
				$intent_id,
				isset( $charge['id'] ) ? (string) $charge['id'] : '',
				isset( $intent['last_payment_error']['message'] ) && is_scalar( $intent['last_payment_error']['message'] ) ? (string) $intent['last_payment_error']['message'] : ''
			);
			$note_type       = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED;
		}

		$intent_meta = ( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome );
		if ( ! $is_setup && 'requires_payment_method' === ( $intent['status'] ?? '' ) ) {
			$intent_meta += WooPaymentsOrderEffects::failed_payment_method_details_meta( $intent );
		}
		if ( ( $intent['status'] ?? '' ) !== $provider_status ) {
			$intent_meta[ WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY ] = $provider_status;
		}

		$changes_nothing = true === ( $outcome->get_data()[ PaymentOutcome::DATA_NO_ORDER_CHANGE ] ?? false );

		return array( $this->lifecycle_event( $outcome, $intent_meta, $note_candidates, $note_type ), $this->get_amount_mismatch_hold( $outcome ), $intent_meta, $changes_nothing );
	}

	/**
	 * Tell whether a confirmed intent is a SetupIntent: the intent of a $0 order, or one whose ID says so.
	 *
	 * @param array<string,mixed> $intent Platform intent response.
	 * @param WC_Order            $order  Order being confirmed.
	 * @return bool
	 */
	private function is_setup_intent_for_order( array $intent, WC_Order $order ): bool {
		return 0.0 >= (float) $order->get_total() || 0 === strpos( isset( $intent['id'] ) ? (string) $intent['id'] : '', 'seti_' );
	}

	/**
	 * Attach an intent, whatever its status, to the order read under the order payment lock, before the payment lifecycle's
	 * event is built and decided on that order, so an order the lifecycle leaves as it is, one already paid, keeps the
	 * intent's record. The intent's meta is the meta the event built from it carries, a started payment's included when its
	 * own writes are skipped.
	 *
	 * Client 11.3.0's redirect return attaches a PaymentIntent or a SetupIntent, and its order-status callback a PaymentIntent,
	 * with the exchange rate, fee and net beside it, before updating the order from it, whatever the intent's status
	 * (class-wc-payment-gateway-wcpay.php:2700, :4663); the caller decides which intents by the path and the intent type.
	 *
	 * @param array<string,mixed> $intent                 Platform intent response.
	 * @param WC_Order            $order                  Order read under the order payment lock.
	 * @param bool                $zero_amount_plain_note Whether a completed $0 SetupIntent gets the order-status callback's plain note.
	 */
	private function attach_intent_before_decision( array $intent, WC_Order $order, bool $zero_amount_plain_note ): void {
		list( $event, , $intent_meta ) = $this->build_lifecycle_event_and_intent_meta( $intent, $order, $zero_amount_plain_note );
		$intent_id                     = (string) $event->get_payment_reference();
		if ( '' === $intent_id ) {
			return;
		}

		$this->order_effect_applier->attach_intent_info( $order, $intent_id, $intent_meta, WooPaymentsOrderEffectApplier::CONFIRMATION_META_KEYS );
	}

	/**
	 * Get the amount-mismatch hold an enriched outcome's effect plan carries.
	 *
	 * @param PaymentOutcome $outcome Enriched provider outcome.
	 * @return array{notes:string[],log_line:string}|null Null when the payment completes the order.
	 */
	private function get_amount_mismatch_hold( PaymentOutcome $outcome ): ?array {
		$plan = $outcome->get_effect_plan();

		return $plan instanceof WooPaymentsOrderEffectPlan ? $plan->get_amount_mismatch_hold() : null;
	}

	/**
	 * Map an intent to a provider outcome enriched with the order meta and note the lifecycle applies.
	 *
	 * @param WC_Order                        $order           Order being updated.
	 * @param array<string,mixed>             $intent          Intent response.
	 * @param WooPaymentsIntentMappingContext $mapping_context Intent mapping context.
	 * @param WooPaymentsOrderEffectPlan      $plan            Effect plan for the intent.
	 * @return PaymentOutcome
	 */
	private function enrich_outcome_for_lifecycle( WC_Order $order, array $intent, WooPaymentsIntentMappingContext $mapping_context, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention( $intent, $mapping_context );

		return $this->order_effect_applier->enrich_outcome_for_lifecycle(
			PaymentOperationContext::for_checkout( $order, (string) $order->get_payment_method(), $outcome->get_payment_method_id() ),
			$outcome,
			$plan
		);
	}

	/**
	 * Build the lifecycle event for an enriched provider outcome.
	 *
	 * @param PaymentOutcome        $outcome         Enriched provider outcome.
	 * @param array<string,string>  $meta            Order meta to update.
	 * @param array<int,mixed>|null $note_candidates A note and its other texts, replacing the outcome's note; null keeps the outcome's.
	 * @param string                $note_type       Note type of the replacing note.
	 * @return PaymentLifecycleEvent
	 */
	private function lifecycle_event( PaymentOutcome $outcome, array $meta, ?array $note_candidates = null, string $note_type = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE ): PaymentLifecycleEvent {
		$data             = $outcome->get_data();
		$note             = isset( $data[ PaymentOutcome::DATA_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE ]
			? $data[ PaymentOutcome::DATA_NOTE ]
			: null;
		$event_note_type  = isset( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE_TYPE ]
			? $data[ PaymentOutcome::DATA_NOTE_TYPE ]
			: null;
		$note_equivalents = isset( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) && is_array( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] )
			? $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ]
			: array();
		if ( null !== $note_candidates ) {
			$note_equivalents = $note_candidates;
			$note             = $note_equivalents[0] ?? null;
			$event_note_type  = null === $note ? null : $note_type;
		}

		return new PaymentLifecycleEvent(
			PaymentLifecycleEvent::status_for_outcome( $outcome ),
			'' === $outcome->get_provider_payment_id() ? null : $outcome->get_provider_payment_id(),
			$meta,
			array(),
			$note,
			$event_note_type,
			$note_equivalents,
			false,
			null === $note_candidates && PaymentLifecycleEvent::should_hold_order_for_outcome( $outcome )
		);
	}

	/**
	 * Apply charge payment-method display details before order completion.
	 *
	 * @param WC_Order            $order  Order being updated.
	 * @param array<string,mixed> $intent Platform intent response.
	 */
	private function apply_payment_method_display_details( WC_Order $order, array $intent ): void {
		$this->order_effect_applier->apply_payment_method_display_details( $order, $intent, $this->account_service->get_account_country() );
	}

	/**
	 * Persist a requested payment method as a WooCommerce token before completing the order.
	 *
	 * @param WC_Order            $order               Order being updated.
	 * @param array<string,mixed> $intent              Platform intent response.
	 * @param bool                $save_payment_method Whether the shopper asked to save the payment method; a recurring order saves it anyway.
	 * @return array{error:array<string,mixed>|null,token:\WC_Payment_Token|null,payment_method_details:array<string,mixed>} Token result and any blocking error response.
	 */
	private function maybe_save_payment_method_for_order( WC_Order $order, array $intent, bool $save_payment_method ): array {
		$is_recurring = SubscriptionMethodPolicy::is_recurring_order( $order );
		if ( ! $is_recurring && ! $save_payment_method ) {
			return array(
				'error'                  => null,
				'token'                  => null,
				'payment_method_details' => array(),
			);
		}

		$payment_method_id = WooPaymentsIntentCodec::result_payment_method_id( $intent );
		$user_id           = WooPaymentsCustomerService::get_payer_user_id( $order );
		if ( '' === $payment_method_id || 0 >= $user_id ) {
			return array(
				'error'                  => $is_recurring ? $this->recurring_token_save_error_response() : null,
				'token'                  => null,
				'payment_method_details' => array(),
			);
		}

		try {
			if ( 0.0 >= (float) $order->get_total() && 0 === strpos( (string) ( $intent['id'] ?? '' ), 'seti_' ) ) {
				$token_result           = $this->token_service->get_or_create_token_and_details_for_user( $payment_method_id, $user_id );
				$token                  = $token_result['token'];
				$payment_method_details = $token_result['payment_method_details'];
			} else {
				$token                  = $this->token_service->get_or_create_token_for_user( $payment_method_id, $user_id );
				$payment_method_details = array();
			}
			if ( $token instanceof \WC_Payment_Token ) {
				$this->token_service->attach_token_to_order( $order, $token );
				$this->token_service->sync_related_subscriptions_payment_token( $order, $token );

				return array(
					'error'                  => null,
					'token'                  => $token,
					'payment_method_details' => $payment_method_details,
				);
			}
		} catch ( Throwable $exception ) {
			$this->token_service->log_token_save_error( $order, $payment_method_id, $exception );
		}

		return array(
			'error'                  => $is_recurring ? $this->recurring_token_save_error_response() : null,
			'token'                  => null,
			'payment_method_details' => array(),
		);
	}

	/**
	 * Build the recurring token-save error response.
	 *
	 * @return array<string,mixed>
	 */
	private function recurring_token_save_error_response(): array {
		return array(
			'error'       => array(
				'message' => __( 'Unable to save payment method for subscription. Please try again or use a different payment method.', 'woocommerce' ),
			),
			'status_code' => 409,
		);
	}
}
