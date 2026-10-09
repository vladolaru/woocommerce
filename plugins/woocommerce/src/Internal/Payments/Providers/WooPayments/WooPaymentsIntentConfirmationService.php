<?php
/**
 * WooPaymentsIntentConfirmationService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
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
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

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
	 * Intent request builder, which owns the recurring payment rule.
	 *
	 * @var WooPaymentsIntentRequestBuilder
	 */
	private WooPaymentsIntentRequestBuilder $request_builder;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient                $api_client                  WooPayments API client.
	 * @param WooPaymentsFeeDetailsNoteController $fee_details_note_controller Fee details note controller.
	 * @param WooPaymentsTokenService             $token_service               WooPayments token service.
	 * @param WooPaymentsAccountService           $account_service             WooPayments account service.
	 * @param WooPaymentsOrderEffectApplier       $order_effect_applier        WooPayments order effect applier.
	 * @param WooPaymentsIntentRequestBuilder     $request_builder             Intent request builder.
	 */
	final public function init(
		WooPaymentsApiClient $api_client,
		WooPaymentsFeeDetailsNoteController $fee_details_note_controller,
		WooPaymentsTokenService $token_service,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderEffectApplier $order_effect_applier,
		WooPaymentsIntentRequestBuilder $request_builder
	): void {
		$this->api_client                  = $api_client;
		$this->fee_details_note_controller = $fee_details_note_controller;
		$this->token_service               = $token_service;
		$this->account_service             = $account_service;
		$this->order_effect_applier        = $order_effect_applier;
		$this->request_builder             = $request_builder;
	}

	/**
	 * Confirm an intent and apply its result to an order.
	 *
	 * @param WC_Order $order               Order being confirmed.
	 * @param string   $intent_id           PaymentIntent or SetupIntent ID.
	 * @param bool     $save_payment_method Whether to persist the payment method.
	 * @param bool     $is_payment_method_change Whether this confirms a subscription payment method change.
	 * @throws WooPaymentsApiException When intent retrieval fails.
	 * @throws WooPaymentsIntentConfirmationException When the intent cannot be authorized or a required token cannot be saved.
	 * @throws Throwable When lifecycle, token, or payment-method effects fail.
	 *
	 * @since 11.2.0
	 */
	public function confirm_intent_for_order( WC_Order $order, string $intent_id, bool $save_payment_method, bool $is_payment_method_change = false ): void {
		$intent = 0.0 >= (float) $order->get_total()
			? $this->api_client->get_setup_intention( $intent_id )
			: $this->api_client->get_payment_intention( $intent_id );

		if ( ! $is_payment_method_change ) {
			$this->confirm_fetched_intent_for_order( $order, $intent, $save_payment_method, false, true );
			return;
		}

		// Client 11.1.0 gw:4337-4345: a payment method change does not take stock for the renewal it completes.
		WooPaymentsSubscriptionMethodPolicy::run_without_stock_reduction(
			function () use ( $order, $intent, $save_payment_method ): void {
				$this->confirm_fetched_intent_for_order( $order, $intent, $save_payment_method, false, false );
			}
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
	 * @throws WooPaymentsIntentConfirmationException When the order-status callback's intent cannot be authorized or a required token cannot be saved.
	 * @throws Throwable When confirmation is rejected or lifecycle, token, or payment-method effects fail.
	 *
	 * @since 11.2.0
	 */
	public function confirm_fetched_intent_for_order( WC_Order $order, array $intent, bool $save_payment_method, bool $is_redirect_return = false, bool $zero_amount_plain_note = false ): void {
		$status                                        = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$should_apply_display_details_before_lifecycle = false;
		$payment_method_details                        = array();
		$previous_payment_method_id                    = (string) $order->get_meta( '_payment_method_id', true );

		if ( WooPaymentsIntentCodec::holds_money( $status ) ) {
			$token_save_result      = $this->maybe_save_payment_method_for_order(
				$order,
				$intent,
				array( 'should_save_payment_method' => $save_payment_method ? 'true' : 'false' )
			);
			$payment_method_details = $token_save_result['payment_method_details'];
			// The redirect return logs a token-save error and completes the order; only the order-status callback stops a recurring order (gw:2389-2396, 4309-4321).
			if ( null !== $token_save_result['error'] && ! $is_redirect_return ) {
				throw new WooPaymentsIntentConfirmationException(
					esc_html( (string) ( $token_save_result['error']['error']['message'] ?? '' ) ),
					(int) ( $token_save_result['error']['status_code'] ?? 409 )
				);
			}

			$should_apply_display_details_before_lifecycle = 0.0 < (float) $order->get_total()
				&& $token_save_result['token'] instanceof \WC_Payment_Token_CC
				&& $this->has_full_charge_card_identity( $intent );
			if ( $should_apply_display_details_before_lifecycle ) {
				$this->apply_payment_method_display_details( $order, $intent );
			} elseif ( 0.0 >= (float) $order->get_total() && 'succeeded' === $status && 0 === strpos( (string) ( $intent['id'] ?? '' ), 'seti_' ) && $token_save_result['token'] instanceof \WC_Payment_Token ) {
				if ( empty( $payment_method_details ) ) {
					$payment_method_details = $this->order_effect_applier->get_same_method_payment_method_details( $order, WooPaymentsIntentCodec::result_payment_method_id( $intent ), $previous_payment_method_id );
					if ( empty( $payment_method_details ) ) {
						$payment_method_details = $this->token_service->resolve_token_and_payment_method_details_for_user(
							WooPaymentsIntentCodec::result_payment_method_id( $intent ),
							$this->get_token_user_id( $order ),
							true
						)['payment_method_details'];
					}
				}
				$should_apply_display_details_before_lifecycle = $this->order_effect_applier->apply_setup_intent_payment_method_display_details( $order, $payment_method_details, $this->account_service->get_account_country() );
			}
		}

		$event = $this->build_lifecycle_event_from_intent( $intent, $order, $zero_amount_plain_note );
		$this->fee_details_note_controller->apply_and_schedule_fee_details_with_lock( $order, $event );
		if ( WooPaymentsIntentCodec::holds_money( $status ) && ! $should_apply_display_details_before_lifecycle ) {
			$this->apply_payment_method_display_details( $order, $intent );
		}

		if ( ! $is_redirect_return && ! WooPaymentsIntentCodec::holds_money( $status ) ) {
			throw new WooPaymentsIntentConfirmationException( esc_html__( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Apply the PaymentIntent an earlier checkout request attached to the order, once the duplicate-payment check found it holds the money.
	 *
	 * Unlike a confirmation, it saves no token, applies no display details and writes no fee meta. An intent that carries
	 * no payment method or customer falls back to the ones stored on the order.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order            $order  Order the intent pays.
	 * @param array<string,mixed> $intent PaymentIntent response.
	 */
	public function apply_attached_payment_intent( WC_Order $order, array $intent ): void {
		$provider_redirect_url = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent ) );
		$outcome               = $this->enrich_outcome_for_lifecycle(
			$order,
			$intent,
			WooPaymentsIntentMappingContext::for_native(
				$order->get_id(),
				$order->get_checkout_order_received_url(),
				(string) $order->get_meta( '_payment_method_id', true ),
				(string) $order->get_meta( '_stripe_customer_id', true ),
				'',
				'pi',
				$provider_redirect_url
			),
			WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false )->without_fee_meta()
		);

		$this->fee_details_note_controller->apply_and_schedule_fee_details_with_lock(
			$order,
			$this->lifecycle_event( $outcome, ( new WooPaymentsPersistenceVocabulary() )->get_outcome_meta( $outcome ) )
		);
	}

	/**
	 * Build a payment lifecycle event from a native intent response.
	 *
	 * @param array<string,mixed> $intent                     Native intent response.
	 * @param WC_Order            $order                      Order being updated.
	 * @param bool                $zero_amount_plain_note     Whether a completed $0 SetupIntent gets the order-status callback's plain note.
	 * @return PaymentLifecycleEvent
	 */
	private function build_lifecycle_event_from_intent( array $intent, WC_Order $order, bool $zero_amount_plain_note = false ): PaymentLifecycleEvent {
		$intent_id             = isset( $intent['id'] ) ? (string) $intent['id'] : '';
		$is_setup              = 0.0 >= (float) $order->get_total() || 0 === strpos( $intent_id, 'seti_' );
		$provider_redirect_url = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent ) );
		$provider_status       = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$intent                = $this->get_intent_for_status_mapping( $intent, $is_setup );
		$plan                  = $is_setup
			? WooPaymentsOrderEffectPlan::for_setup_intent(
				$intent,
				false,
				array(
					// Plugin 11.1.0 stores the order currency for setup intents (class-wc-payments-order-service.php:1361).
					'_wcpay_intent_currency' => (string) $order->get_currency(),
					'_wcpay_mode'            => $this->account_service->get_order_mode(),
				)
			)
			: WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false );
		$outcome               = $this->enrich_outcome_for_lifecycle(
			$order,
			$intent,
			WooPaymentsIntentMappingContext::for_native(
				$order->get_id(),
				$order->get_checkout_order_received_url(),
				'',
				'',
				'',
				$is_setup ? 'si' : 'pi',
				$provider_redirect_url
			),
			$plan
		);

		$plain_note_equivalents = null;
		if ( $zero_amount_plain_note && $is_setup && 0.0 >= (float) $order->get_total() && PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() ) {
			// Client update_order_status() completes a $0 order itself with a plain note; its order service then sees a paid order
			// and writes no success note, so no fee job either (gw:4248-4275, os:2747-2764).
			$plain_note_equivalents = $order->is_paid() ? array() : wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_zero_amount_setup_success_note_candidates( $order, $intent_id );
		}

		$meta = ( new WooPaymentsPersistenceVocabulary() )->get_outcome_meta( $outcome );
		if ( ( $intent['status'] ?? '' ) !== $provider_status ) {
			$meta['_intention_status'] = $provider_status;
		}

		return $this->lifecycle_event( $outcome, $meta, $plain_note_equivalents );
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
	 * @param PaymentOutcome        $outcome                Enriched provider outcome.
	 * @param array<string,string>  $meta                   Order meta to update.
	 * @param array<int,mixed>|null $plain_note_equivalents A $0 order's plain completion note and its other texts, replacing the outcome's note; null keeps the outcome's.
	 * @return PaymentLifecycleEvent
	 */
	private function lifecycle_event( PaymentOutcome $outcome, array $meta, ?array $plain_note_equivalents = null ): PaymentLifecycleEvent {
		$data             = $outcome->get_data();
		$note             = isset( $data[ PaymentOutcome::DATA_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE ]
			? $data[ PaymentOutcome::DATA_NOTE ]
			: null;
		$note_type        = isset( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE_TYPE ]
			? $data[ PaymentOutcome::DATA_NOTE_TYPE ]
			: null;
		$note_equivalents = isset( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) && is_array( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] )
			? $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ]
			: array();
		if ( null !== $plain_note_equivalents ) {
			$note_equivalents = $plain_note_equivalents;
			$note             = $note_equivalents[0] ?? null;
			$note_type        = null === $note ? null : PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_COMPLETE;
		}

		return new PaymentLifecycleEvent(
			PaymentLifecycleEvent::status_for_outcome( $outcome ),
			'' === $outcome->get_provider_payment_id() ? null : $outcome->get_provider_payment_id(),
			$meta,
			array(),
			$note,
			$note_type,
			$note_equivalents
		);
	}

	/**
	 * Map `requires_action` and `requires_payment_method` by whether the intent carries an error.
	 *
	 * The plugin's order service fails the order when either status has an error and marks the payment
	 * started otherwise (`update_order_status_from_intent()`). It reads only `last_payment_error`, so a
	 * SetupIntent is always started here. The redirect return checks both errors itself before confirming
	 * (`gw:2377-2382`). The caller keeps the provider's real status in `_intention_status`.
	 *
	 * @param array<string,mixed> $intent   Native intent response.
	 * @param bool                $is_setup Whether the intent is a SetupIntent.
	 * @return array<string,mixed>
	 */
	private function get_intent_for_status_mapping( array $intent, bool $is_setup ): array {
		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		if ( ! in_array( $status, array( 'requires_action', 'requires_payment_method' ), true ) ) {
			return $intent;
		}

		$error            = $is_setup ? null : ( $intent['last_payment_error'] ?? null );
		$intent['status'] = empty( $error ) ? 'requires_action' : 'requires_payment_method';

		return $intent;
	}

	/**
	 * Apply charge payment-method display details before order completion.
	 *
	 * @param WC_Order            $order  Order being updated.
	 * @param array<string,mixed> $intent Native intent response.
	 */
	private function apply_payment_method_display_details( WC_Order $order, array $intent ): void {
		$this->order_effect_applier->apply_payment_method_display_details( $order, $intent, $this->account_service->get_account_country() );
	}

	/**
	 * Tell whether a PaymentIntent contains the full non-Link card identity required before lifecycle hooks run.
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @return bool
	 */
	private function has_full_charge_card_identity( array $intent ): bool {
		$intent_id = isset( $intent['id'] ) && is_scalar( $intent['id'] ) ? (string) $intent['id'] : '';
		if ( 0 !== strpos( $intent_id, 'pi_' ) ) {
			return false;
		}

		$charge  = WooPaymentsIntentCodec::latest_charge( $intent );
		$details = $charge['payment_method_details'] ?? null;
		if ( ! is_array( $details ) || ! isset( $details['type'] ) || ! is_scalar( $details['type'] ) || 'card' !== (string) $details['type'] || ! isset( $details['card'] ) || ! is_array( $details['card'] ) ) {
			return false;
		}

		$card        = $details['card'];
		$wallet_type = isset( $card['wallet']['type'] ) && is_scalar( $card['wallet']['type'] ) ? sanitize_key( (string) $card['wallet']['type'] ) : '';
		if ( 'link' === $wallet_type || ! $this->has_nonempty_scalar_card_field( $card, 'last4' ) || ! $this->has_nonempty_scalar_card_field( $card, 'brand' ) || ! $this->has_nonempty_scalar_card_field( $card, 'funding' ) ) {
			return false;
		}

		$networks  = isset( $card['networks'] ) && is_array( $card['networks'] ) ? $card['networks'] : array();
		$available = isset( $networks['available'] ) && is_array( $networks['available'] ) ? $networks['available'] : array();
		$network   = $card['display_brand'] ?? $card['network'] ?? $networks['preferred'] ?? $available[0] ?? null;

		return is_scalar( $network ) && '' !== trim( (string) $network );
	}

	/**
	 * Tell whether a card field has a nonempty scalar value.
	 *
	 * @param array<string,mixed> $card Card details.
	 * @param string              $field Card field name.
	 * @return bool
	 */
	private function has_nonempty_scalar_card_field( array $card, string $field ): bool {
		return isset( $card[ $field ] ) && is_scalar( $card[ $field ] ) && '' !== trim( (string) $card[ $field ] );
	}

	/**
	 * Persist a requested payment method as a WooCommerce token before completing the order.
	 *
	 * @param WC_Order            $order   Order being updated.
	 * @param array<string,mixed> $intent  Native intent response.
	 * @param array<string,mixed> $request Request data.
	 * @return array{error:array<string,mixed>|null,token:\WC_Payment_Token|null,payment_method_details:array<string,mixed>} Token result and any blocking error response.
	 */
	private function maybe_save_payment_method_for_order( WC_Order $order, array $intent, array $request ): array {
		$is_recurring               = $this->request_builder->is_recurring_payment( $order );
		$should_save_payment_method = $is_recurring || $this->should_save_payment_method( $request ) || $this->is_subscription_change_payment_request( $request );
		if ( ! $should_save_payment_method ) {
			return array(
				'error'                  => null,
				'token'                  => null,
				'payment_method_details' => array(),
			);
		}

		$payment_method_id = WooPaymentsIntentCodec::result_payment_method_id( $intent );
		$user_id           = $this->get_token_user_id( $order );
		if ( '' === $payment_method_id || 0 >= $user_id ) {
			return array(
				'error'                  => $is_recurring ? $this->recurring_token_save_error_response() : null,
				'token'                  => null,
				'payment_method_details' => array(),
			);
		}

		try {
			if ( 0.0 >= (float) $order->get_total() && 0 === strpos( (string) ( $intent['id'] ?? '' ), 'seti_' ) ) {
				$token_result           = $this->token_service->resolve_token_and_payment_method_details_for_user( $payment_method_id, $user_id );
				$token                  = $token_result['token'];
				$payment_method_details = $token_result['payment_method_details'];
			} else {
				$token                  = $this->token_service->get_or_create_token_for_user( $payment_method_id, $user_id );
				$payment_method_details = array();
			}
			if ( $token instanceof \WC_Payment_Token ) {
				$this->token_service->attach_token_to_order( $order, $token );
				$this->token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $this->get_result_customer_id( $intent ) );

				return array(
					'error'                  => null,
					'token'                  => $token,
					'payment_method_details' => $payment_method_details,
				);
			}
		} catch ( Throwable $exception ) {
			$this->token_service->log_token_save_error( $order, $payment_method_id, $exception );

			return array(
				'error'                  => $is_recurring ? $this->recurring_token_save_error_response() : null,
				'token'                  => null,
				'payment_method_details' => array(),
			);
		}

		return array(
			'error'                  => $is_recurring ? $this->recurring_token_save_error_response() : null,
			'token'                  => null,
			'payment_method_details' => array(),
		);
	}

	/**
	 * Tell whether the customer requested payment-method saving.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return bool
	 */
	private function should_save_payment_method( array $request ): bool {
		return 'true' === strtolower( $this->get_request_string( $request, 'should_save_payment_method' ) );
	}

	/**
	 * Tell whether the current callback is completing a WC Subscriptions payment-method change.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return bool
	 */
	private function is_subscription_change_payment_request( array $request ): bool {
		return 'true' === strtolower( $this->get_request_string( $request, 'is_changing_payment' ) );
	}

	/**
	 * Get the user ID that should own a saved payment token.
	 *
	 * @param WC_Order $order Order object.
	 * @return int
	 */
	private function get_token_user_id( WC_Order $order ): int {
		$user_id = $order->get_user_id();

		return 0 < $user_id ? $user_id : get_current_user_id();
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

	/**
	 * Get the customer ID from an intent response.
	 *
	 * @param array<string,mixed> $intent Native intent response.
	 * @return string
	 */
	private function get_result_customer_id( array $intent ): string {
		if ( isset( $intent['customer'] ) && is_string( $intent['customer'] ) ) {
			return $intent['customer'];
		}

		if ( isset( $intent['customer'] ) && is_array( $intent['customer'] ) && isset( $intent['customer']['id'] ) ) {
			return (string) $intent['customer']['id'];
		}

		return '';
	}

	/**
	 * Read a sanitized request string.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @param string              $key     Request key.
	 * @return string
	 */
	private function get_request_string( array $request, string $key ): string {
		$value = $request[ $key ] ?? '';

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}
}
