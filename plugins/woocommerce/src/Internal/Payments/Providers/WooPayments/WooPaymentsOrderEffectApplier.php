<?php
/**
 * WooPaymentsOrderEffectApplier class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\LinkToken;
use Throwable;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Tokens;

/**
 * Applies a plan to the order: writes token, payment method title and setup intent changes, and adds the plan's meta
 * and note candidates to the outcome, which the payment lifecycle writes.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsOrderEffectApplier {

	/**
	 * Order meta of a payment's intent that client 11.3.0's attach_intent_info_to_order() writes, with the transaction ID
	 * (class-wc-payments-order-service.php:1818-1845, :1875-1884).
	 */
	private const INTENT_INFO_META_KEYS = array(
		'_intent_id',
		'_payment_method_id',
		'_charge_id',
		'_intention_status',
		'_stripe_customer_id',
		'_wcpay_intent_currency',
		'_wcpay_payment_transaction_id',
		'_charge_risk_level',
		'_wcpay_payment_method_details',
	);

	/**
	 * Order meta client 11.3.0's checkout charge also writes beside the intent's, whether or not it then updates the order:
	 * the order mode before the charge, the settlement exchange rate, and the fee and net (class-wc-payment-gateway-wcpay.php:1964,
	 * :2445, :2501; class-wc-payments-order-service.php:2275-2300).
	 */
	public const CHARGE_META_KEYS = array(
		'_wcpay_mode',
		'_wcpay_multi_currency_stripe_exchange_rate',
		'_wcpay_transaction_fee',
		'_wcpay_net',
	);

	/**
	 * Order meta client 11.3.0's redirect return and order-status callback also write beside the intent's: the settlement
	 * exchange rate, and the fee and net (class-wc-payment-gateway-wcpay.php:2701, :2708, :4662, :4664).
	 */
	public const CONFIRMATION_META_KEYS = array(
		'_wcpay_multi_currency_stripe_exchange_rate',
		'_wcpay_transaction_fee',
		'_wcpay_net',
	);

	/**
	 * WooPayments token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * WooPayments payment method registry.
	 *
	 * @var PaymentMethodRegistry
	 */
	private PaymentMethodRegistry $payment_method_registry;

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $action_scheduler;

	/**
	 * Order payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Initialize the effect applier.
	 *
	 * @internal
	 *
	 * @param WooPaymentsTokenService           $token_service      WooPayments token service.
	 * @param WooPaymentsOrderDataService       $order_data_service WooPayments order data service.
	 * @param WooPaymentsAccountService         $account_service    WooPayments account service.
	 * @param WooPaymentsOrderNoteService       $note_service       WooPayments order note service.
	 * @param PaymentMethodRegistry             $payment_method_registry Payment method registry.
	 * @param WooPaymentsActionSchedulerService $action_scheduler  Action Scheduler service.
	 * @param OrderPaymentLifecycleService      $lifecycle_service Order payment lifecycle service.
	 */
	final public function init(
		WooPaymentsTokenService $token_service,
		WooPaymentsOrderDataService $order_data_service,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderNoteService $note_service,
		PaymentMethodRegistry $payment_method_registry,
		WooPaymentsActionSchedulerService $action_scheduler,
		OrderPaymentLifecycleService $lifecycle_service
	): void {
		$this->token_service           = $token_service;
		$this->order_data_service      = $order_data_service;
		$this->account_service         = $account_service;
		$this->note_service            = $note_service;
		$this->payment_method_registry = $payment_method_registry;
		$this->action_scheduler        = $action_scheduler;
		$this->lifecycle_service       = $lifecycle_service;
	}

	/**
	 * Write what a checkout charge writes whatever the order's payment decision, before the order is read for that decision:
	 * the token and its subscriptions, a SetupIntent's payment method details, and the payment method title and card meta.
	 *
	 * Client 11.3.0 saves the token before it attaches the intent (class-wc-payment-gateway-wcpay.php:2335-2377), and writes
	 * the title and card meta before it updates the order from the intent (:2462-2495). A $0 order confirmed without an
	 * intent gets all its writes here, as it gets no payment decision. The returned outcome is the charge's, or the refusal
	 * of a payment method change whose card could not be saved.
	 *
	 * @param PaymentOperationContext    $context Payment context.
	 * @param PaymentOutcome             $outcome Charge outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    The charge's effect plan.
	 * @return PaymentOutcome
	 */
	public function apply_charge_writes( PaymentOperationContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		switch ( $plan->get_type() ) {
			case WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT:
				if ( $plan->should_apply_token_effects() ) {
					// The platform took the payment, so a token that cannot be saved is logged and the order completes without it (client 11.1.0 gw:2036-2067).
					$this->apply_token_effects( $context, $outcome, $plan->is_recurring() );
				}

				$display_effects = $this->compose_payment_method_display_details( $context->get_order(), $plan->get_provider_result() );
				if ( ! empty( $display_effects ) ) {
					$this->apply_composed_payment_method_display_details( $context->get_order(), $display_effects );
				} else {
					$this->apply_generic_payment_method_title( $context->get_order() );
				}

				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT:
				$payment_method_details     = array();
				$previous_payment_method_id = (string) $context->get_order()->get_meta( '_payment_method_id', true );
				if ( $plan->should_apply_token_effects() ) {
					// As for a PaymentIntent, a token that cannot be saved is logged and the order completes without it (client 11.1.0 gw:2036-2067).
					// A subscription payment method change takes no money, so it is refused instead and the subscription keeps its
					// payment method and status, as the change confirmed after 3D Secure is (gw:4308-4321).
					$payment_method_details = $this->apply_token_effects( $context, $outcome, $plan->is_recurring() );
					if ( null === $payment_method_details ) {
						if ( ! empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] ) ) {
							return $this->payment_method_change_token_save_failed_outcome();
						}
						$payment_method_details = array();
					}
				}
				$provider_result = $plan->get_provider_result();
				if ( PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() && 'succeeded' === (string) ( $provider_result['status'] ?? '' ) && 0 === strpos( (string) ( $provider_result['id'] ?? '' ), 'seti_' ) ) {
					if ( empty( $payment_method_details ) && '' !== $outcome->get_payment_method_id() ) {
						$payment_method_details = $this->get_same_method_payment_method_details( $context->get_order(), $outcome->get_payment_method_id(), $previous_payment_method_id );
						if ( empty( $payment_method_details ) ) {
							$payment_method_details = $this->token_service->get_or_create_token_and_details_for_user(
								$outcome->get_payment_method_id(),
								$context->get_order()->get_user_id(),
								true
							)['payment_method_details'];
						}
					}
					$this->apply_setup_intent_payment_method_display_details( $context->get_order(), $payment_method_details );
				}

				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_ZERO_AMOUNT_WITHOUT_INTENT:
				$this->apply_zero_amount_without_intent( $context->get_order(), $outcome, $plan );
				return $outcome;
		}

		return $outcome;
	}

	/**
	 * Apply a typed WooPayments effect plan.
	 *
	 * For a checkout charge's PaymentIntent or SetupIntent, it composes the outcome the lifecycle applies on the outcome
	 * attach_charge_intent() returned, on the order as attached (client 11.3.0 class-wc-payment-gateway-wcpay.php:2444,
	 * then :2499).
	 *
	 * @param PaymentOperationContext    $context Payment context.
	 * @param PaymentOutcome             $outcome Provider outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    WooPayments effect plan.
	 * @return PaymentOutcome
	 */
	public function apply( PaymentOperationContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		switch ( $plan->get_type() ) {
			case WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT:
				$outcome = $this->compose_payment_intent_outcome( $context, $outcome, $plan, $this->get_outcome_meta_data( $outcome ), self::is_merchant_initiated( $context ) );

				return $outcome->is_payment_method_only() ? $outcome : $this->dispatch_returned_payment_intent( $context, $outcome, $plan );

			case WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT:
				$order           = $context->get_order();
				$provider_result = $plan->get_provider_result();
				$setup_intent_id = (string) ( $provider_result['id'] ?? '' );
				// An outcome that only saved the payment method completes nothing and starts no payment, so it gets no success or
				// started writes (client 11.3.0 gw:2497-2500; class-wc-payments-order-service.php:469).
				if ( $outcome->is_payment_method_only() || '' === $setup_intent_id ) {
					return $outcome;
				}

				if ( PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() && 'succeeded' === (string) ( $provider_result['status'] ?? '' ) && 0 === strpos( $setup_intent_id, 'seti_' ) ) {
					// Client 11.1.0's process_payment() also ends in update_order_status_from_intent() (gw:2204), so the success note is written here too.
					return $this->merge_effect_data_into_outcome( $outcome, $this->compose_setup_intent_note_data( $order, $outcome, $provider_result ), $plan );
				}

				if ( PaymentLifecycleEvent::STATUS_STARTED === PaymentLifecycleEvent::status_for_outcome( $outcome ) ) {
					$effect_data = $this->compose_waiting_effect_data( $order, $provider_result, $this->get_outcome_meta_data( $outcome ), true, self::is_merchant_initiated( $context ) );
					if ( $plan->keeps_earlier_record() && true !== ( $effect_data[ PaymentOutcome::DATA_NO_ORDER_CHANGE ] ?? false ) ) {
						return $this->apply_returned_intent_waiting( $order, $outcome, $plan, false );
					}

					return $this->merge_effect_data_into_outcome( $outcome, $effect_data, $plan );
				}

				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_ZERO_AMOUNT_WITHOUT_INTENT:
				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_CAPTURE:
				$outcome = $this->merge_effect_data_into_outcome(
					$outcome,
					$this->compose_capture_effect_data( $context->get_order(), $outcome, $plan->get_provider_result(), $plan->writes_fee_meta() ),
					$plan
				);
				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_CAPTURE_EXPIRED:
				return $this->merge_effect_data_into_outcome(
					$outcome,
					$this->compose_capture_expired_effect_data( $context->get_order(), $outcome, $plan->get_provider_result() ),
					$plan
				);

			case WooPaymentsOrderEffectPlan::TYPE_CANCEL:
				return $this->merge_effect_data_into_outcome(
					$outcome,
					$this->compose_cancel_effect_data( $context->get_order(), $outcome, $plan->get_provider_result() ),
					$plan
				);

			case WooPaymentsOrderEffectPlan::TYPE_REFUND:
				if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
					$result          = $plan->get_provider_result();
					$provider_status = isset( $result['status'] ) ? (string) $result['status'] : (string) ( $outcome->get_data()['refund_status'] ?? '' );
					$failure_reason  = isset( $result['failure_reason'] ) ? (string) $result['failure_reason'] : (string) ( $outcome->get_data()['refund_failure_reason'] ?? '' );

					return $this->merge_effect_data_into_outcome(
						$outcome,
						array( PaymentOutcome::DATA_ERROR_MESSAGE => $this->note_service->format_refund_failure_message( $provider_status, $failure_reason ) ),
						$plan
					);
				}

				$payment_data    = $context->get_payment_data();
				$result          = $plan->get_provider_result();
				$refund_id       = isset( $result['id'] ) ? (string) $result['id'] : $outcome->get_provider_payment_id();
				$is_pending      = WooPaymentsIntentCodec::is_pending_refund_status( (string) ( $result['status'] ?? '' ) );
				$note_candidates = $this->note_service->format_created_refund_note_candidates(
					$context->get_order(),
					(float) ( $payment_data['amount'] ?? 0.0 ),
					(string) $context->get_order()->get_currency(),
					$refund_id,
					(string) ( $payment_data['reason'] ?? '' ),
					$is_pending
				);
				$note_identity   = sprintf( 'refund:%s:%s', $refund_id, $is_pending ? 'created_pending' : 'created_successful' );

				return $this->merge_effect_data_into_outcome(
					$outcome,
					WooPaymentsOrderEffects::compose_refund_effect_data(
						$result,
						$note_candidates[0],
						$note_identity,
						$note_candidates
					),
					$plan
				);
		}

		return $outcome;
	}

	/**
	 * Enrich an outcome for lifecycle application without persisting order effects.
	 *
	 * @param PaymentOperationContext    $context Payment context.
	 * @param PaymentOutcome             $outcome Provider outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    WooPayments effect plan.
	 * @return PaymentOutcome
	 */
	public function enrich_outcome_for_lifecycle( PaymentOperationContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		switch ( $plan->get_type() ) {
			case WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT:
				$captures = WooPaymentsOrderEffects::captures_the_authorization( $context->get_order(), $plan->get_provider_result() );
				$meta     = $this->compose_payment_intent_meta( $context->get_order(), $plan->get_provider_result(), $plan->writes_fee_meta(), $captures );

				return $this->compose_payment_intent_outcome( $context, $outcome, $plan, $meta, false );

			case WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT:
				$outcome = $this->with_setup_meta( $outcome, $plan );

				return $this->merge_effect_data_into_outcome(
					$outcome,
					$this->compose_setup_intent_note_data( $context->get_order(), $outcome, $plan->get_provider_result() ),
					$plan
				);
		}

		return $outcome;
	}

	/**
	 * Pick the writer for a PaymentIntent the checkout's charge returned, as client 11.3.0's status update does, through the
	 * classifier the confirmation uses (WooPaymentsIntentCodec::intent_for_order_status_update(); class-wc-payments-order-service.php:500-508):
	 * a `requires_action` or `requires_payment_method` intent with an error gets the failed writer; one without an error gets
	 * the on-hold writer for an offline method, read from the intent's payment method options as the client reads it
	 * (class-wc-payments-api-abstract-intention.php:201-203), and the started writer otherwise. The outcome keeps the intent's
	 * own status, so the checkout's response is unchanged; a scheduled renewal that needs authentication is left to its own
	 * path, which fails it (class-wc-payment-gateway-wcpay.php:2208-2222).
	 *
	 * @param PaymentOperationContext    $context Payment context, its order read under the checkout's order payment lock.
	 * @param PaymentOutcome             $outcome PaymentIntent outcome, composed by its own status.
	 * @param WooPaymentsOrderEffectPlan $plan    PaymentIntent effect plan.
	 * @return PaymentOutcome
	 */
	private function dispatch_returned_payment_intent( PaymentOperationContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		$result = $plan->get_provider_result();
		$status = (string) ( $result['status'] ?? '' );
		if ( ! in_array( $status, array( 'requires_action', 'requires_payment_method' ), true ) ) {
			return PaymentOutcome::STATUS_FAILED === $outcome->get_status() ? $this->compose_returned_payment_intent_failure( $context->get_order(), $outcome, $plan ) : $outcome;
		}
		if ( 'requires_action' === $status && self::is_merchant_initiated( $context ) ) {
			return $outcome;
		}

		$classified = (string) ( WooPaymentsIntentCodec::intent_for_order_status_update( $result, false )['status'] ?? '' );
		if ( 'requires_payment_method' === $classified ) {
			return $this->compose_returned_payment_intent_failure( $context->get_order(), $outcome, $plan );
		}

		return $this->apply_returned_intent_waiting( $context->get_order(), $outcome, $plan, self::is_offline_payment_method_intent( $result ) );
	}

	/**
	 * Tell whether an intent is for an offline payment method, by the first of its payment method options, as client 11.3.0
	 * reads the method for its on-hold writer (class-wc-payments-api-abstract-intention.php:201-203; Payment_Method::OFFLINE_PAYMENT_METHODS).
	 *
	 * @param array<string,mixed> $intent Provider intent response.
	 * @return bool
	 */
	private static function is_offline_payment_method_intent( array $intent ): bool {
		$options = isset( $intent['payment_method_options'] ) && is_array( $intent['payment_method_options'] ) ? array_keys( $intent['payment_method_options'] ) : array();

		return isset( $options[0] ) && in_array( (string) $options[0], WooPaymentsDuplicatePaymentPreventionService::OFFLINE_PAYMENT_METHODS, true );
	}

	/**
	 * Write a waiting intent with no error as client 11.3.0's on-hold writer does for an offline method (the started
	 * note, the fraud box, On hold, the intent's own status, unless the note is on the order; class-wc-payments-order-service.php:505-506,
	 * :2171-2183), or as its started writer does otherwise (the same writes without the status change, on a Pending order
	 * without the note; :507-508, :2229-2241).
	 *
	 * The outcome keeps the intent's own status for the checkout's response. When its event is not the one the client picks
	 * (a `requires_payment_method` intent, whose outcome is failed, or an offline `requires_action` intent the outcome does
	 * not already hold as a voucher), or the attach kept an earlier payment's record, that event is applied here, on the
	 * order read under the checkout's order payment lock, and the outcome changes nothing more. Only the client's writes are
	 * made, so the intent's record and the transaction ID stay with the attach (:1818-1822, :1875-1876).
	 * The on-hold event names no payment, so it writes no transaction ID, and it leaves a paid order as it is, as the client
	 * returns on one before any writer (class-wc-payments-order-service.php:475, :3398-3410). It has no note type either, so
	 * its note is found by its text, which names the intent, as the client's check is (:2172-2175).
	 *
	 * @param WC_Order                   $order   Order read under the checkout's order payment lock, the intent attached.
	 * @param PaymentOutcome             $outcome PaymentIntent or SetupIntent outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    The intent's effect plan.
	 * @param bool                       $offline Whether the intent is for an offline payment method.
	 * @return PaymentOutcome
	 */
	private function apply_returned_intent_waiting( WC_Order $order, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan, bool $offline ): PaymentOutcome {
		$event_status   = $offline ? PaymentLifecycleEvent::STATUS_AUTHORIZED : PaymentLifecycleEvent::STATUS_STARTED;
		$outcome_status = PaymentLifecycleEvent::status_for_outcome( $outcome );
		if ( ( $event_status === $outcome_status && ! $plan->keeps_earlier_record() ) || PaymentLifecycleEvent::STATUS_AUTHORIZED === $outcome_status ) {
			return $outcome;
		}

		$effect_data = $this->compose_started_effect_data( $order, $plan->get_provider_result(), $this->get_outcome_meta_data( $outcome ), ! $offline, false );
		if ( true !== ( $effect_data[ PaymentOutcome::DATA_NO_ORDER_CHANGE ] ?? false ) ) {
			$intent_id = $outcome->get_provider_payment_id();
			$this->lifecycle_service->apply_under_lock(
				$order,
				new PaymentLifecycleEvent(
					$event_status,
					$offline || '' === $intent_id ? null : $intent_id,
					array_intersect_key( $effect_data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ], array_flip( array( '_intention_status', '_wcpay_fraud_meta_box_type' ) ) ),
					array(),
					$effect_data[ PaymentOutcome::DATA_NOTE ],
					$offline ? null : $effect_data[ PaymentOutcome::DATA_NOTE_TYPE ],
					$effect_data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ],
					false,
					false,
					array(),
					$offline
				),
				new WooPaymentsPersistenceVocabulary()
			);
		}

		return $this->merge_effect_data_into_outcome( $outcome, array( PaymentOutcome::DATA_NO_ORDER_CHANGE => true ), $plan );
	}

	/**
	 * Compose the failure of a PaymentIntent the checkout's charge returned, as client 11.3.0's mark_payment_failed() writes it:
	 * its failure note, after the attach, unless the failure is already recorded on the order, when nothing more is written.
	 *
	 * The client does not raise a returned intent that is not authorized; its status update sends it to mark_payment_failed()
	 * (class-wc-payment-gateway-wcpay.php:2321-2324, :2444, :2499; class-wc-payments-order-service.php:500-504, :550-554).
	 * A `requires_action` intent with an error keeps its outcome for the checkout's response, so its failure is applied here,
	 * on the order read under the checkout's order payment lock, and the outcome changes nothing more. That event carries the
	 * intent's own status, which the client's failed writer stores apart from the attach (:559, :3443-3448), so an order the
	 * attach left with an earlier payment's record still gets it. A failed outcome over such a record keeps only that status of
	 * the intent's meta, so the earlier payment's record and transaction ID stay.
	 *
	 * @param WC_Order                   $order   Order read under the checkout's order payment lock, the intent attached.
	 * @param PaymentOutcome             $outcome Failed PaymentIntent outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    PaymentIntent effect plan.
	 * @return PaymentOutcome
	 */
	private function compose_returned_payment_intent_failure( WC_Order $order, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		$result          = $plan->get_provider_result();
		$charge          = WooPaymentsIntentCodec::latest_charge( $result );
		$intent_id       = $outcome->get_provider_payment_id();
		$message         = $result['last_payment_error']['message'] ?? '';
		$note_candidates = $this->note_service->format_order_status_update_payment_failed_note_candidates(
			$order,
			$intent_id,
			isset( $charge['id'] ) ? (string) $charge['id'] : '',
			is_scalar( $message ) ? (string) $message : ''
		);
		$failure         = new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_FAILED, '' === $intent_id ? null : $intent_id, array(), array(), $note_candidates[0], PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED, $note_candidates );
		if ( $this->note_service->is_failure_recorded( $order, $failure ) ) {
			return $this->merge_effect_data_into_outcome( $outcome, array( PaymentOutcome::DATA_NO_ORDER_CHANGE => true ), $plan );
		}
		if ( PaymentOutcome::STATUS_FAILED !== $outcome->get_status() ) {
			$this->lifecycle_service->apply_under_lock(
				$order,
				new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_FAILED, '' === $intent_id ? null : $intent_id, array( '_intention_status' => (string) ( $result['status'] ?? '' ) ), array(), $note_candidates[0], PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED, $note_candidates ),
				new WooPaymentsPersistenceVocabulary()
			);

			return $this->merge_effect_data_into_outcome( $outcome, array( PaymentOutcome::DATA_NO_ORDER_CHANGE => true ), $plan );
		}

		$effect_data = array(
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED,
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
		if ( $plan->keeps_earlier_record() ) {
			// The attach kept an earlier payment's record, so the failure writes only the intent status beside its note; the
			// outcome's payment method and customer are written as the client writes them before the charge
			// (class-wc-payment-gateway-wcpay.php:1969-1973).
			$effect_data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] = array_intersect_key( $this->get_outcome_meta_data( $outcome ), array_flip( array( '_intention_status' ) ) );
		}

		return $this->merge_effect_data_into_outcome( $outcome, $effect_data, $plan );
	}

	/**
	 * Compose a PaymentIntent outcome's hold, note and started writes on the order, from the intent's meta.
	 *
	 * The hold is decided on the order as it is now, so a caller that attaches the intent first decides it on the order as
	 * attached (client 11.3.0 class-wc-payment-gateway-wcpay.php:2444, then class-wc-payments-order-service.php:484-489).
	 *
	 * @param PaymentOperationContext    $context              Payment context.
	 * @param PaymentOutcome             $outcome              Provider outcome.
	 * @param WooPaymentsOrderEffectPlan $plan                 PaymentIntent effect plan.
	 * @param array<string,string>       $meta                 The intent's order meta (compose_payment_intent_meta()).
	 * @param bool                       $is_merchant_initiated Whether the charge is a scheduled renewal's.
	 * @return PaymentOutcome
	 */
	private function compose_payment_intent_outcome( PaymentOperationContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan, array $meta, bool $is_merchant_initiated ): PaymentOutcome {
		$result     = $plan->get_provider_result();
		$hold       = 'succeeded' === (string) ( $result['status'] ?? '' ) ? wc_get_container()->get( WooPaymentsAmountMismatchHold::class )->decide_hold( $context->get_order(), $result ) : null;
		$is_started = PaymentLifecycleEvent::STATUS_STARTED === PaymentLifecycleEvent::status_for_outcome( $outcome );

		return $this->merge_effect_data_into_outcome(
			$outcome,
			$this->compose_payment_intent_effect_data( $context->get_order(), $result, $meta, $hold, $is_started, $is_merchant_initiated ),
			null === $hold ? $plan : $plan->with_amount_mismatch_hold( $hold )
		);
	}

	/**
	 * Add a SetupIntent plan's setup meta, the order currency and mode, to the outcome's order meta.
	 *
	 * @param PaymentOutcome             $outcome SetupIntent outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    SetupIntent effect plan.
	 * @return PaymentOutcome
	 */
	private function with_setup_meta( PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		return $this->merge_effect_data_into_outcome(
			$outcome,
			array( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array_merge( $this->get_outcome_meta_data( $outcome ), $plan->get_setup_meta() ) ),
			$plan
		);
	}

	/**
	 * Get the order meta an outcome's effects composed so far.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,mixed>
	 */
	private function get_outcome_meta_data( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();

		return isset( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) && is_array( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] )
			? $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ]
			: array();
	}

	/**
	 * Attach a checkout charge's intent to the order read under the checkout's order payment lock, through
	 * attach_intent_info(), before its outcome is composed (apply()); and return the outcome with the intent's order meta,
	 * a PaymentIntent's composed on that order and a SetupIntent's setup meta.
	 *
	 * Client 11.3.0 attaches every intent its charge returned, a SetupIntent's included, whatever its status, before it
	 * updates the order from it; an intent that is not authorized is marked failed for that update, not raised
	 * (class-wc-payment-gateway-wcpay.php:2321-2324, :2443-2444). A failure raised with no returned intent attaches nothing,
	 * as its exception skips that attach. The attach writes the intent's record even once the stored intent status is
	 * succeeded when overrides_attach_guard() says so. When it keeps an earlier payment's record, the plan says so, and the
	 * started event writes only what the client's started writer writes (apply_returned_intent_waiting()).
	 *
	 * @param PaymentOperationContext $context Payment context, its order read under the checkout's order payment lock.
	 * @param PaymentOutcome          $outcome Charge outcome.
	 * @return PaymentOutcome
	 */
	public function attach_charge_intent( PaymentOperationContext $context, PaymentOutcome $outcome ): PaymentOutcome {
		$plan            = $outcome->get_effect_plan();
		$returned_intent = $plan instanceof WooPaymentsOrderEffectPlan && in_array( $plan->get_type(), array( WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT, WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT ), true );
		if ( $plan instanceof WooPaymentsOrderEffectPlan && WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT === $plan->get_type() ) {
			$outcome = $this->merge_effect_data_into_outcome(
				$outcome,
				array( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => $this->compose_payment_intent_meta( $context->get_order(), $plan->get_provider_result(), $plan->writes_fee_meta(), false ) ),
				$plan
			);
		} elseif ( $plan instanceof WooPaymentsOrderEffectPlan && WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT === $plan->get_type() ) {
			$outcome = $this->with_setup_meta( $outcome, $plan );
		}

		$intent_id = $outcome->get_provider_payment_id();
		// An outcome with no returned intent is attached only when it is a payment, an authorization or a started payment.
		if ( '' === $intent_id || ( ! $returned_intent && ! in_array( PaymentLifecycleEvent::status_for_outcome( $outcome ), array( PaymentLifecycleEvent::STATUS_COMPLETED, PaymentLifecycleEvent::STATUS_AUTHORIZED, PaymentLifecycleEvent::STATUS_STARTED ), true ) ) ) {
			return $outcome;
		}

		$recorded = $this->attach_intent_info(
			$context->get_order(),
			$intent_id,
			( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome ),
			self::CHARGE_META_KEYS,
			self::overrides_attach_guard( $context )
		);

		return ! $recorded && $plan instanceof WooPaymentsOrderEffectPlan ? $outcome->with_effect_plan( $plan->with_earlier_record_kept() ) : $outcome;
	}

	/**
	 * Tell whether a checkout charge's attach writes the intent's record past its guard: on a subscription payment method
	 * change, or with a subscription or a renewal in the cart, as client 11.3.0 does (class-wc-payment-gateway-wcpay.php:2443).
	 * No cart is loaded in a scheduled renewal, so there the cart reads as holding neither.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return bool
	 */
	private static function overrides_attach_guard( PaymentOperationContext $context ): bool {
		return ! empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] )
			|| SubscriptionMethodPolicy::cart_contains_subscription_or_renewal();
	}

	/**
	 * Tell whether a charge is a scheduled subscription renewal's, which the merchant initiates with the customer absent.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return bool
	 */
	private static function is_merchant_initiated( PaymentOperationContext $context ): bool {
		return true === ( $context->get_provider_data()['scheduled_subscription_payment'] ?? false );
	}

	/**
	 * Compose the effects of an intent that is neither paid, authorized, failed nor canceled, by its own status, as client
	 * 11.3.0's switch does whatever the redirect form (class-wc-payments-order-service.php:479-514): requires_action is a
	 * started payment (compose_started_effect_data()); requires_confirmation has no branch there, so it changes nothing.
	 *
	 * @param WC_Order            $order                 Order the outcome is composed on, read under the caller's order payment lock.
	 * @param array<string,mixed> $result                Provider intent response.
	 * @param array<string,mixed> $meta                  The intent's order meta.
	 * @param bool                $is_started            Whether the outcome is a started payment; only a started payment's writes are skipped.
	 * @param bool                $is_merchant_initiated Whether the charge is a scheduled renewal's.
	 * @return array<string,mixed>
	 */
	private function compose_waiting_effect_data( WC_Order $order, array $result, array $meta, bool $is_started, bool $is_merchant_initiated ): array {
		switch ( (string) ( $result['status'] ?? '' ) ) {
			case 'requires_action':
				return $this->compose_started_effect_data( $order, $result, $meta, $is_started, $is_merchant_initiated );
			case 'requires_confirmation':
				return array( PaymentOutcome::DATA_NO_ORDER_CHANGE => true );
		}

		return array();
	}

	/**
	 * Attach a payment's intent to the order before its status is decided: the transaction ID and the intent's meta, so the
	 * order keeps the payment's record whatever the decision. Once the stored intent status is succeeded, the transaction ID
	 * and the intent's meta are left as they are, and only the caller's other fields are written.
	 *
	 * Client 11.3.0 does this in attach_intent_info_to_order() (class-wc-payments-order-service.php:1818-1884), called by its
	 * checkout, redirect return, order-status callback and terminal capture before they update the order from the intent
	 * (class-wc-payment-gateway-wcpay.php:2444, :2700, :4663; class-wc-rest-payments-orders-controller.php:215). Its guard
	 * covers that function only (:1820); the mode, exchange rate, fee and net are written beside it, unguarded
	 * (class-wc-payment-gateway-wcpay.php:1964, :2445, :2501, :2701, :2708, :4662, :4664).
	 *
	 * @param WC_Order             $order                   Order read under the order payment lock the caller holds.
	 * @param string               $intent_id               Intent ID, which becomes the transaction ID.
	 * @param array<string,string> $meta                    The payment's order meta; only its intent info, and the keys below, are written.
	 * @param string[]             $beside_keys             Meta the caller's client counterpart writes beside the intent's:
	 *                                                      CHARGE_META_KEYS or CONFIRMATION_META_KEYS, or none for the terminal capture.
	 * @param bool                 $allow_update_on_success Whether the intent's record is written also once the stored intent status
	 *                                                      is succeeded, as a checkout charge's is when overrides_attach_guard() says so.
	 * @return bool Whether the intent's record and the transaction ID were written.
	 */
	public function attach_intent_info( WC_Order $order, string $intent_id, array $meta, array $beside_keys = array(), bool $allow_update_on_success = false ): bool {
		$keys    = $beside_keys;
		$records = $allow_update_on_success || 'succeeded' !== (string) $order->get_meta( '_intention_status', true );
		if ( $records ) {
			$order->set_transaction_id( $intent_id );
			$keys = array_merge( self::INTENT_INFO_META_KEYS, $beside_keys );
		}

		$this->lifecycle_service->apply_meta_under_lock( $order, array_intersect_key( $meta, array_flip( $keys ) ), array() );

		return $records;
	}

	/**
	 * Compose the success, started, failed or cancelled note for a SetupIntent outcome, as client 11.1.0 writes it.
	 *
	 * Client 11.1.0: mark_payment_completed() for a succeeded SetupIntent (os:404-406, 1565-1600), mark_payment_started() (os:418-427, 2202-2218) on the order-status callback, the redirect
	 * return's mark_payment_failed() with "UPE payment failed: ..." (gw:2376-2382, 2428-2446; os:2106-2130), and
	 * mark_payment_capture_cancelled() for a canceled SetupIntent on either path (os:400-402, 1533-1554, 2315-2330).
	 *
	 * @param WC_Order            $order           Order object.
	 * @param PaymentOutcome      $outcome         SetupIntent outcome, with its order meta.
	 * @param array<string,mixed> $provider_result SetupIntent response.
	 * @return array<string,mixed>
	 */
	private function compose_setup_intent_note_data( WC_Order $order, PaymentOutcome $outcome, array $provider_result ): array {
		$intent_id = (string) ( $provider_result['id'] ?? '' );
		if ( '' === $intent_id ) {
			return array();
		}

		if ( PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() ) {
			// Client mark_payment_completed() (os:1565-1600) writes the success note for a succeeded SetupIntent too, with no transaction link.
			$note_candidates = $this->note_service->format_payment_success_note_candidates( $order, $intent_id, '' );
			$note_type       = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS;
		} elseif ( PaymentLifecycleEvent::STATUS_STARTED === PaymentLifecycleEvent::status_for_outcome( $outcome ) ) {
			return $this->compose_waiting_effect_data( $order, $provider_result, $this->get_outcome_meta_data( $outcome ), true, false );
		} elseif ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
			$note_candidates = $this->note_service->format_redirect_payment_failed_note_candidates( $order, $intent_id );
			$note_type       = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED;
		} elseif ( PaymentOutcome::STATUS_CANCELED === $outcome->get_status() ) {
			$note_candidates = $this->note_service->format_capture_cancelled_note_candidates( $intent_id, '' );
			$note_type       = PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_CANCELED;
		} else {
			return array();
		}

		return array(
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_TYPE        => $note_type,
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
	}

	/**
	 * Compose a PaymentIntent's order meta: the intent's record, the payment's meta and the payment method's display meta.
	 *
	 * A completion as a capture (WooPaymentsOrderEffects::captures_the_authorization()) marks a fraud review as allowed only
	 * when the order's fraud outcome is review, and writes the captured charge's fee, as client 11.3.0's capture writer does
	 * (class-wc-payments-order-service.php:2207-2215).
	 *
	 * @param WC_Order            $order            Order being charged.
	 * @param array<string,mixed> $result           Provider PaymentIntent response.
	 * @param bool                $include_fee_meta Whether to write the fee meta.
	 * @param bool                $captures         Whether the payment completes the order as a capture.
	 * @return array<string,string>
	 */
	private function compose_payment_intent_meta( WC_Order $order, array $result, bool $include_fee_meta, bool $captures ): array {
		$status          = (string) ( $result['status'] ?? '' );
		$charge          = WooPaymentsIntentCodec::latest_charge( $result );
		$settlement_meta = ! empty( $charge ) && WooPaymentsIntentCodec::holds_money( $status )
			? $this->order_data_service->get_settlement_exchange_rate_order_meta( $order, $charge, $this->account_service->get_account_default_currency() )
			: array();
		$display_effects = $this->compose_payment_method_display_details( $order, $result );
		$meta            = WooPaymentsOrderEffects::payment_intent_meta(
			$result,
			(string) $order->get_currency(),
			$this->account_service->get_order_mode(),
			$settlement_meta,
			( ! $captures && $order->has_status( OrderStatus::ON_HOLD ) ) || 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ),
			$include_fee_meta || $captures
		);

		return empty( $display_effects ) ? $meta : array_merge( $meta, $display_effects['meta'] );
	}

	/**
	 * Compose a PaymentIntent's hold, note and started writes after the provider outcome is retained.
	 *
	 * @param WC_Order                                   $order                 Order being charged.
	 * @param array<string,mixed>                        $result                Provider PaymentIntent response.
	 * @param array<string,string>                       $meta                  The intent's order meta (compose_payment_intent_meta()).
	 * @param array{notes:string[],log_line:string}|null $hold                  The hold decided for the intent, or null when it completes the order.
	 * @param bool                                       $is_started            Whether the outcome is a started payment, whose own writes compose_started_effect_data() decides.
	 * @param bool                                       $is_merchant_initiated Whether the charge is a scheduled renewal's.
	 * @return array<string,mixed>
	 */
	private function compose_payment_intent_effect_data( WC_Order $order, array $result, array $meta, ?array $hold, bool $is_started, bool $is_merchant_initiated ): array {
		$status     = (string) ( $result['status'] ?? '' );
		$charge     = WooPaymentsIntentCodec::latest_charge( $result );
		$order_mode = $this->account_service->get_order_mode();

		$effect_data = array( WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => $meta );
		$intent_id   = isset( $result['id'] ) ? (string) $result['id'] : '';
		$charge_id   = isset( $charge['id'] ) ? (string) $charge['id'] : '';

		if ( null !== $hold ) {
			$effect_data[ PaymentOutcome::DATA_HOLD_ORDER ]       = true;
			$effect_data[ PaymentOutcome::DATA_NOTE ]             = $hold['notes'][0];
			$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ]        = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_HELD;
			$effect_data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $hold['notes'];
		} elseif ( 'succeeded' === $status && WooPaymentsOrderEffects::captures_the_authorization( $order, $result ) ) {
			// No intent was attached since the authorization, so the payment completes the order as a capture (client 11.3.0
			// class-wc-payments-order-service.php:484-485, :2193-2219), as the payment_intent.succeeded webhook does.
			$note_candidates                                      = $this->note_service->format_capture_success_note_candidates(
				$order,
				$intent_id,
				$charge_id,
				WooPaymentsIntentCodec::balance_transaction_id( $charge['balance_transaction'] ?? null )
			);
			$effect_data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
			$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ]        = PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS;
			$effect_data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
		} elseif ( 'succeeded' === $status ) {
			$note_candidates                                      = $this->note_service->format_payment_success_note_candidates(
				$order,
				$intent_id,
				$charge_id,
				WooPaymentsIntentCodec::balance_transaction_id( $charge['balance_transaction'] ?? null ),
				$order_mode
			);
			$effect_data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
			$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ]        = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS;
			$effect_data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
		} elseif ( in_array( $status, array( 'requires_capture', 'processing' ), true ) && '' !== $intent_id ) {
			$metadata      = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();
			$fraud_outcome = isset( $metadata['fraud_outcome'] ) && is_string( $metadata['fraud_outcome'] ) ? $metadata['fraud_outcome'] : '';
			if ( 'review' === $fraud_outcome ) {
				$note_candidates                                      = $this->note_service->format_fraud_held_for_review_note_candidates( $order, $intent_id, $charge_id, WooPaymentsOrderEffects::get_fraud_ruleset_results( $result ) );
				$effect_data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
				$effect_data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
			} else {
				$note_candidates                                      = $this->note_service->format_payment_authorized_note_candidates( $order, $intent_id, $charge_id );
				$effect_data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
				$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ]        = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED;
				$effect_data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
			}
		} elseif ( '' !== $intent_id ) {
			$effect_data = array_merge( $effect_data, $this->compose_waiting_effect_data( $order, $result, $meta, $is_started, $is_merchant_initiated ) );
		}

		return $effect_data;
	}

	/**
	 * Compose a started payment's own writes, for a PaymentIntent or a SetupIntent that waits on the shopper: the started
	 * note, the fraud box and the intent status, on a Pending order without that note.
	 *
	 * Client 11.3.0's started writer returns on any other order, before its fraud box, note and intent status writes
	 * (class-wc-payments-order-service.php:2229-2241); the outcome then changes nothing (PaymentOutcome::DATA_NO_ORDER_CHANGE). A scheduled
	 * renewal's started payment is skipped too: the client's renewal fails the order first (class-wc-payment-gateway-wcpay.php:2208-2222),
	 * so its started writer then meets an order that is not Pending (:2231-2233).
	 *
	 * @param WC_Order            $order                 Order the started outcome is composed on, read under the caller's order payment lock.
	 * @param array<string,mixed> $result                Provider intent response.
	 * @param array<string,mixed> $meta                  The intent's order meta.
	 * @param bool                $is_started            Whether the outcome is a started payment; only a started payment's writes are skipped.
	 * @param bool                $is_merchant_initiated Whether the charge is a scheduled renewal's.
	 * @return array<string,mixed>
	 */
	private function compose_started_effect_data( WC_Order $order, array $result, array $meta, bool $is_started, bool $is_merchant_initiated ): array {
		$note_candidates = $this->note_service->format_payment_started_note_candidates( $order, (string) ( $result['id'] ?? '' ) );
		if ( $is_started && (
			$is_merchant_initiated
			|| ! $order->has_status( OrderStatus::PENDING )
			|| 0 < wc_get_container()->get( OrderPaymentNotes::class )->find_by_content( $order, (string) $note_candidates[0], $note_candidates )
		) ) {
			return array( PaymentOutcome::DATA_NO_ORDER_CHANGE => true );
		}

		$started_meta = WooPaymentsOrderEffects::started_payment_meta( $result, (string) $order->get_currency() );

		return array(
			WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array_merge(
				$meta,
				array(
					'_intention_status'          => $started_meta['_intention_status'],
					'_wcpay_fraud_meta_box_type' => $started_meta['_wcpay_fraud_meta_box_type'],
				)
			),
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_STARTED,
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
	}

	/**
	 * Compose capture metadata and notes after the provider outcome is retained.
	 *
	 * @param WC_Order            $order            Order being captured.
	 * @param PaymentOutcome      $outcome          Provider capture outcome.
	 * @param array<string,mixed> $result           Provider capture response.
	 * @param bool                $include_fee_meta Whether to write the fee meta.
	 * @return array<string,mixed>
	 */
	private function compose_capture_effect_data( WC_Order $order, PaymentOutcome $outcome, array $result, bool $include_fee_meta ): array {
		$charge    = WooPaymentsIntentCodec::latest_charge( $result );
		$intent_id = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) ( $result['id'] ?? '' );
		$charge_id = isset( $charge['id'] ) ? (string) $charge['id'] : (string) $order->get_meta( '_charge_id', true );

		if ( PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() && 'succeeded' === (string) ( $result['status'] ?? '' ) ) {
			$settlement_meta = empty( $charge )
				? array()
				: $this->order_data_service->get_settlement_exchange_rate_order_meta( $order, $charge, $this->account_service->get_account_default_currency() );

			$note_candidates = $this->note_service->format_capture_success_note_candidates(
				$order,
				$intent_id,
				$charge_id,
				WooPaymentsIntentCodec::balance_transaction_id( $charge['balance_transaction'] ?? null )
			);

			return array(
				WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => WooPaymentsOrderEffects::completed_capture_meta(
					$result,
					(string) $order->get_currency(),
					$this->account_service->get_order_mode(),
					$settlement_meta,
					'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ),
					$include_fee_meta
				),
				PaymentOutcome::DATA_NOTE             => $note_candidates[0],
				PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS,
				PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
			);
		}

		if ( PaymentOutcome::STATUS_AUTHORIZED === $outcome->get_status() ) {
			return array();
		}

		$data    = $outcome->get_data();
		$message = isset( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] )
			? (string) $data[ PaymentOutcome::DATA_ERROR_MESSAGE ]
			: (string) ( $result['message'] ?? '' );
		// As client 11.1.0's mark_payment_capture_failed() (class-wc-payments-order-service.php:496-504): the note names the
		// minimum for amount_too_small, a payment held for review is marked review_failed, and the note has no type, so a
		// failure with another message gets its own note.
		$message         = WooPaymentsIntentCodec::with_minimum_capture_amount( $message, is_array( $data['extra_details'] ?? null ) ? $data['extra_details'] : array() );
		$note_candidates = $this->note_service->format_capture_failed_note_candidates( $order, $intent_id, $charge_id, $message );
		$meta            = WooPaymentsOrderEffects::failed_capture_meta();
		if ( 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ) ) {
			$meta['_wcpay_fraud_meta_box_type'] = 'review_failed';
		}

		return array(
			WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => $meta,
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
	}

	/**
	 * Compose the expired-authorization capture effects.
	 *
	 * Same effects the charge.expired webhook applies: expired note, canceled
	 * intention status, review-expired stamp. Reached only through an
	 * explicitly declared expired plan (the operation service's re-fetch after a failed capture).
	 *
	 * @param WC_Order            $order   Order whose authorization expired.
	 * @param PaymentOutcome      $outcome Provider capture outcome.
	 * @param array<string,mixed> $result  Re-fetched canceled intent.
	 * @return array<string,mixed>
	 */
	private function compose_capture_expired_effect_data( WC_Order $order, PaymentOutcome $outcome, array $result ): array {
		$charge          = WooPaymentsIntentCodec::latest_charge( $result );
		$intent_id       = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) ( $result['id'] ?? '' );
		$charge_id       = isset( $charge['id'] ) ? (string) $charge['id'] : (string) $order->get_meta( '_charge_id', true );
		$note_candidates = $this->note_service->format_capture_expired_note_candidates( $intent_id, $charge_id );
		$meta            = array( '_intention_status' => 'canceled' );
		if ( '' !== $charge_id ) {
			$meta['_charge_id'] = $charge_id;
		}
		if ( 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ) ) {
			$meta['_wcpay_fraud_meta_box_type'] = 'review_expired';
		}

		return array(
			WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => $meta,
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED,
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
	}

	/**
	 * Compose authorization cancellation metadata cleanup and note.
	 *
	 * @param WC_Order            $order   Order whose authorization is being canceled.
	 * @param PaymentOutcome      $outcome Provider cancellation outcome.
	 * @param array<string,mixed> $result  Provider cancellation response.
	 * @return array<string,mixed>
	 */
	private function compose_cancel_effect_data( WC_Order $order, PaymentOutcome $outcome, array $result ): array {
		$provider_status = (string) ( $result['status'] ?? '' );
		if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() && '' !== $provider_status && 'canceled' !== $provider_status ) {
			// The cancel failed but the intent was re-read: record the status the
			// provider still reports and the failure note, as client 11.1.0's
			// cancel_authorization() does (class-wc-payment-gateway-wcpay.php:4069), so the admin actions reflect reality.
			$message         = isset( $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] )
				? (string) $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ]
				: (string) ( $result['message'] ?? '' );
			$note_candidates = $this->note_service->format_cancel_failed_note_candidates( $message );

			return array(
				WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array( '_intention_status' => $provider_status ),
				PaymentOutcome::DATA_NOTE             => $note_candidates[0],
				PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
			);
		}

		if ( PaymentOutcome::STATUS_CANCELED !== $outcome->get_status() || 'canceled' !== $provider_status ) {
			return array();
		}

		$charge          = WooPaymentsIntentCodec::latest_charge( $result );
		$intent_id       = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) ( $result['id'] ?? '' );
		$charge_id       = isset( $charge['id'] ) ? (string) $charge['id'] : (string) $order->get_meta( '_charge_id', true );
		$note_candidates = $this->note_service->format_capture_cancelled_note_candidates( $intent_id, $charge_id );

		return array(
			PaymentOutcome::DATA_META_TO_DELETE   => array( '_wcpay_transaction_fee', '_wcpay_net' ),
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_CANCELED,
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
	}

	/**
	 * Apply composed payment-method display details to an order.
	 *
	 * @param WC_Order            $order           Order being updated.
	 * @param array<string,mixed> $result          Provider PaymentIntent response.
	 * @param string              $account_country Connected account country override.
	 */
	public function apply_payment_method_display_details( WC_Order $order, array $result, string $account_country = '' ): void {
		$effects = $this->compose_payment_method_display_details( $order, $result );
		$this->apply_composed_payment_method_display_details( $order, $effects, $account_country );
	}

	/**
	 * Apply payment-method display details confirmed by a SetupIntent.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order            $order           Order being updated.
	 * @param array<string,mixed> $payment_method  Provider payment method response.
	 * @param string              $account_country Connected account country override.
	 * @return bool Whether details were safely applied.
	 */
	public function apply_setup_intent_payment_method_display_details( WC_Order $order, array $payment_method, string $account_country = '' ): bool {
		$effects = WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details(
			$payment_method,
			''
		);
		if ( empty( $effects ) ) {
			$this->clear_setup_intent_card_identity( $order );
			$type  = isset( $payment_method['type'] ) && is_scalar( $payment_method['type'] ) ? (string) $payment_method['type'] : '';
			$title = $this->non_card_payment_method_title( $type );
			if ( '' !== $title ) {
				$order->set_payment_method_title( $title );
				$order->save();
				$this->sync_payment_method_to_subscriptions( $order );

				return false;
			}
			$this->apply_generic_setup_intent_card_title( $order, $account_country );

			return false;
		}

		$this->clear_setup_intent_card_identity( $order );
		$this->apply_composed_payment_method_display_details( $order, $effects, $account_country );

		return true;
	}

	/**
	 * Remove display metadata that belongs to a previous card payment method.
	 *
	 * @param WC_Order $order Order being updated.
	 * @return void
	 */
	private function clear_setup_intent_card_identity( WC_Order $order ): void {
		$order->delete_meta_data( 'last4' );
		$order->delete_meta_data( '_card_brand' );
		$order->delete_meta_data( '_wcpay_payment_method_details' );
		$order->delete_meta_data( '_wcpay_raw_payment_method_details' );
		$order->delete_meta_data( '_wcpay_express_checkout_payment_method' );
	}

	/**
	 * Get trusted normalized details retained for a replay of the same payment method.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order being updated.
	 * @param string   $payment_method_id Current provider payment method ID.
	 * @param string   $previous_payment_method_id Provider payment method ID before this attempt.
	 * @return array<string,mixed>
	 */
	public function get_same_method_payment_method_details( WC_Order $order, string $payment_method_id, string $previous_payment_method_id ): array {
		if ( '' === $payment_method_id || $payment_method_id !== $previous_payment_method_id ) {
			return array();
		}

		$details = json_decode( (string) $order->get_meta( '_wcpay_payment_method_details', true ), true );
		$effects = is_array( $details ) ? WooPaymentsOrderEffects::compose_setup_intent_payment_method_display_details( $details ) : array();
		if ( empty( $effects ) || 'card' !== $effects['payment_method_type'] || ! isset( $effects['meta']['last4'], $effects['meta']['_card_brand'] ) ) {
			return array();
		}

		return $details;
	}

	/**
	 * Set the order's payment method title to the generic card title for the account country, else the billing country,
	 * and copy it to the order's subscriptions.
	 *
	 * @param WC_Order $order           Order being updated.
	 * @param string   $account_country Connected account country override.
	 * @return void
	 */
	private function apply_generic_setup_intent_card_title( WC_Order $order, string $account_country ): void {
		$display_country = strtoupper( trim( '' !== $account_country ? $account_country : $this->account_service->get_account_country() ) );
		if ( '' === $display_country ) {
			$display_country = strtoupper( trim( (string) $order->get_billing_country() ) );
		}

		$title = $this->registered_payment_method_title( 'card', $display_country );
		$order->set_payment_method_title( '' !== $title ? $title : __( 'Card', 'woocommerce' ) );
		$order->save();
		$this->sync_payment_method_to_subscriptions( $order );
	}

	/**
	 * Compose payment-method display effects for an order without writing them.
	 *
	 * @param WC_Order            $order  Order being projected.
	 * @param array<string,mixed> $result Provider PaymentIntent response.
	 * @return array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string}|array{}
	 */
	private function compose_payment_method_display_details( WC_Order $order, array $result ): array {
		return WooPaymentsOrderEffects::compose_payment_method_display_details(
			$result,
			(string) $order->get_meta( '_wcpay_express_checkout_payment_method', true )
		);
	}

	/**
	 * Apply already-composed payment-method display effects to an order.
	 *
	 * @param WC_Order                                                                                                                                                             $order           Order being updated.
	 * @param array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string}|array{} $effects Composed display effects.
	 * @param string                                                                                                                                                               $account_country Connected account country override.
	 */
	private function apply_composed_payment_method_display_details( WC_Order $order, array $effects, string $account_country = '' ): void {
		if ( empty( $effects ) ) {
			return;
		}

		foreach ( $effects['meta'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		// A charge type with no registered payment method, such as an in-person card_present charge, names no gateway, so
		// the order keeps its method and title, as client 11.1.0 set_payment_method_title_for_order() returns early for it
		// (includes/class-wc-payment-gateway-wcpay.php:2694-2697).
		if ( null === $this->payment_method_registry->get( $effects['payment_method_type'] ) ) {
			$order->save();

			return;
		}
		if ( '' !== $effects['payment_method_id'] ) {
			$order->set_payment_method( $effects['payment_method_id'] );
		}
		$order->set_payment_method_title( $this->resolve_payment_method_title( $order, $effects, $account_country ) );
		$order->save();
		$this->sync_payment_method_to_subscriptions( $order );
	}

	/**
	 * Merge provider-specific effect data into a retained transport outcome.
	 *
	 * @param PaymentOutcome             $outcome     Provider transport outcome.
	 * @param array<string,mixed>        $effect_data Composed local effect data.
	 * @param WooPaymentsOrderEffectPlan $plan        Applied effect plan.
	 * @return PaymentOutcome
	 */
	private function merge_effect_data_into_outcome( PaymentOutcome $outcome, array $effect_data, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		$merged_outcome = new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			array_merge( $outcome->get_data(), $effect_data )
		);

		return $merged_outcome->with_effect_plan( $plan );
	}

	/**
	 * Resolve a localized title only when applying the real display effect.
	 *
	 * @param WC_Order                                                                                                                                                     $order           Order being updated.
	 * @param array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string} $effects         Payment method display values computed for the order, not yet written.
	 * @param string                                                                                                                                                       $account_country Connected account country override.
	 * @return string
	 */
	private function resolve_payment_method_title( WC_Order $order, array $effects, string $account_country ): string {
		$display_country = strtoupper( trim( '' !== $account_country ? $account_country : $this->account_service->get_account_country() ) );
		if ( '' === $display_country ) {
			$display_country = strtoupper( trim( (string) $order->get_billing_country() ) );
		}

		$express_type = $effects['express_checkout_type'];
		if ( '' !== $express_type ) {
			// No country: client 11.2.0 titles express payments with get_title() (class-wc-payment-gateway-wcpay.php:2730-2732),
			// so a GB account's Link wallet payment is "Link", not its checkout name "Onelink".
			$title = $this->registered_payment_method_title( $express_type, '' );
			if ( '' === $title ) {
				$title = $this->non_card_payment_method_title( $express_type );
			}
			if ( '' === $title ) {
				$title = __( 'Payment Request', 'woocommerce' );
			}

			/**
			 * Filters the WooPayments suffix included in stored express-checkout titles.
			 *
			 * @since 11.0.0
			 *
			 * @param string $suffix Express-checkout payment-method title suffix.
			 */
			$suffix = apply_filters( 'wcpay_payment_request_payment_method_title_suffix', 'WooPayments' );
			// An empty return removes the suffix, as in client 11.1.0 (class-wc-payment-gateway-wcpay.php:2735-2740), so
			// '__return_false' drops it. Unlike client 11.1.0, '0' and an empty array remove it too, where it appends a
			// bare "0" or "Array"; any other non-scalar keeps the default, where it prints " (Array)" for an
			// array, uses a stringable object's string, and throws on an object that has none.
			if ( empty( $suffix ) ) {
				$suffix = '';
			} elseif ( is_scalar( $suffix ) ) {
				$suffix = (string) $suffix;
			} else {
				$suffix = 'WooPayments';
			}

			return '' === $suffix ? $title : $title . ' (' . $suffix . ')';
		}

		$details = $effects['payment_method_details'];
		if ( empty( $details ) ) {
			// With no charge details yet, a card falls back to its definition's title ("Card"), not a card title built from nothing.
			$details = array( 'type' => $effects['payment_method_type'] );
		}

		return $this->payment_method_title( $details, $display_country );
	}

	/**
	 * Get a localized payment method title.
	 *
	 * @param array<string,mixed> $details         Payment method details.
	 * @param string              $account_country Connected account country.
	 * @return string
	 */
	private function payment_method_title( array $details, string $account_country ): string {
		$wallet_type = $details['card']['wallet']['type'] ?? null;
		$type        = isset( $details['type'] ) && is_scalar( $details['type'] ) ? (string) $details['type'] : '';

		switch ( $wallet_type ) {
			case 'link':
				return __( 'Link', 'woocommerce' );
			case 'apple_pay':
				return __( 'Apple Pay', 'woocommerce' );
			case 'google_pay':
				return __( 'Google Pay', 'woocommerce' );
		}

		if ( 'card' === $type && isset( $details['card'] ) && is_array( $details['card'] ) ) {
			return $this->card_payment_method_title( $details['card'] );
		}

		$title = $this->registered_payment_method_title( $type, $account_country );
		if ( '' === $title ) {
			$title = $this->non_card_payment_method_title( $type );
		}

		return '' === $title ? __( 'Credit / Debit Cards', 'woocommerce' ) : $title;
	}

	/**
	 * Get a localized card title.
	 *
	 * @param array<string,mixed> $card_details Card details.
	 * @return string
	 */
	private function card_payment_method_title( array $card_details ): string {
		$funding_types = array(
			'credit'  => __( 'credit', 'woocommerce' ),
			'debit'   => __( 'debit', 'woocommerce' ),
			'prepaid' => __( 'prepaid', 'woocommerce' ),
			'unknown' => __( 'unknown', 'woocommerce' ),
		);
		$networks      = isset( $card_details['networks'] ) && is_array( $card_details['networks'] ) ? $card_details['networks'] : array();
		$available     = isset( $networks['available'] ) && is_array( $networks['available'] ) ? $networks['available'] : array();
		$card_network  = $card_details['display_brand'] ?? $card_details['network'] ?? $networks['preferred'] ?? $available[0] ?? 'card';
		$card_network  = is_string( $card_network ) ? $card_network : '';
		if ( '' === $card_network ) {
			return __( 'Credit / Debit Cards', 'woocommerce' );
		}

		$funding = isset( $card_details['funding'], $funding_types[ (string) $card_details['funding'] ] )
			? $funding_types[ (string) $card_details['funding'] ]
			: $funding_types['unknown'];

		return sprintf(
			/* translators: %1$s: card brand, %2$s: card funding type. */
			__( '%1$s %2$s card', 'woocommerce' ),
			ucwords( str_replace( '_', ' ', $card_network ) ),
			$funding
		);
	}

	/**
	 * Get a localized non-card fallback title.
	 *
	 * @param string $type Stripe payment method type.
	 * @return string
	 */
	private function non_card_payment_method_title( string $type ): string {
		$titles = array(
			'affirm'            => __( 'Affirm', 'woocommerce' ),
			'afterpay_clearpay' => __( 'Afterpay', 'woocommerce' ),
			'alipay'            => __( 'Alipay', 'woocommerce' ),
			'amazon_pay'        => __( 'Amazon Pay', 'woocommerce' ),
			'au_becs_debit'     => __( 'BECS Direct Debit', 'woocommerce' ),
			'bancontact'        => __( 'Bancontact', 'woocommerce' ),
			'eps'               => __( 'EPS', 'woocommerce' ),
			'grabpay'           => __( 'GrabPay', 'woocommerce' ),
			'ideal'             => __( 'iDEAL', 'woocommerce' ),
			'klarna'            => __( 'Klarna', 'woocommerce' ),
			'link'              => __( 'Link', 'woocommerce' ),
			'multibanco'        => __( 'Multibanco', 'woocommerce' ),
			'p24'               => __( 'Przelewy24', 'woocommerce' ),
			'sepa_debit'        => __( 'SEPA Direct Debit', 'woocommerce' ),
			'wechat_pay'        => __( 'WeChat Pay', 'woocommerce' ),
		);

		return $titles[ $type ] ?? '';
	}

	/**
	 * Get a payment method title from the registry.
	 *
	 * @param string $type            Stripe payment method type.
	 * @param string $account_country Connected account country.
	 * @return string
	 */
	private function registered_payment_method_title( string $type, string $account_country ): string {
		if ( '' === $type ) {
			return '';
		}

		$definition = $this->payment_method_registry->get( $type );
		if ( null === $definition ) {
			return '';
		}

		return $definition->get_title( $account_country );
	}

	/**
	 * Title the order "WooPayments" when the payment carries no card or method details to name it.
	 *
	 * @param WC_Order $order Order being processed.
	 */
	private function apply_generic_payment_method_title( WC_Order $order ): void {
		$title = __( 'WooPayments', 'woocommerce' );
		if ( $title === $order->get_payment_method_title() ) {
			return;
		}

		$order->set_payment_method_title( $title );
		$order->save();
	}

	/**
	 * Propagate final payment identity to subscriptions created from the parent order.
	 *
	 * @param WC_Order $order Parent order with finalized payment identity.
	 */
	private function sync_payment_method_to_subscriptions( WC_Order $order ): void {
		$payment_method       = $order->get_payment_method();
		$payment_method_title = $order->get_payment_method_title();

		foreach ( $this->token_service->get_related_subscriptions_for_order( $order ) as $subscription ) {
			if ( ! $subscription instanceof WC_Order ) {
				continue;
			}
			if ( $payment_method === $subscription->get_payment_method() && $payment_method_title === $subscription->get_payment_method_title() ) {
				continue;
			}

			$subscription->set_payment_method( $payment_method );
			$subscription->set_payment_method_title( $payment_method_title );
			$subscription->save();
		}
	}

	/**
	 * Apply saved or newly created token effects; a token that cannot be saved or attached is left out, and a failure is logged.
	 *
	 * The token belongs to the payer (WooPaymentsCustomerService::get_payer_user_id()), as client 11.1.0 attaches and saves
	 * it for that user (includes/class-wc-payment-gateway-wcpay.php:2061, :2071-2072).
	 *
	 * @param PaymentOperationContext $context      Payment context.
	 * @param PaymentOutcome          $outcome      Provider outcome.
	 * @param bool                    $is_recurring Whether recurring token persistence is required.
	 * @return array<string,mixed>|null Payment method details read while saving a SetupIntent's token, empty otherwise; null
	 *                                  when a token was to be saved or attached and was not.
	 */
	private function apply_token_effects( PaymentOperationContext $context, PaymentOutcome $outcome, bool $is_recurring ): ?array {
		$payment_data      = $context->get_payment_data();
		$payment_method_id = $outcome->get_payment_method_id();
		$customer_id       = $outcome->get_customer_id();
		$order             = $context->get_order();
		$payer_user_id     = WooPaymentsCustomerService::get_payer_user_id( $order );

		try {
			if ( WooPaymentsTokenService::is_using_saved_payment_token( $payment_data ) ) {
				$payment_token_id = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';
				$token            = $this->token_service->get_valid_token_from_token_id( $payment_token_id, $payer_user_id );
				return $token instanceof WC_Payment_Token && $this->attach_and_sync_token( $order, $token, $payment_method_id, $customer_id ) ? array() : null;
			}

			if ( empty( $payment_data['save_payment_method'] ) && ! $is_recurring ) {
				return array();
			}

			if ( '' === $payment_method_id || 0 >= $payer_user_id ) {
				return null;
			}

			if ( 0 === strpos( $outcome->get_provider_payment_id(), 'seti_' ) ) {
				$token_result           = $this->token_service->get_or_create_token_and_details_for_user( $payment_method_id, $payer_user_id );
				$token                  = $token_result['token'];
				$payment_method_details = $token_result['payment_method_details'];
			} else {
				$token                  = $this->token_service->get_or_create_token_for_user( $payment_method_id, $payer_user_id );
				$payment_method_details = array();
			}
			if ( $token instanceof WC_Payment_Token && $this->attach_and_sync_token( $order, $token, $payment_method_id, $customer_id ) ) {
				return $payment_method_details;
			}
		} catch ( Throwable $exception ) {
			$this->token_service->log_token_save_error( $order, $payment_method_id, $exception );
		}

		return null;
	}

	/**
	 * Build the refusal of a payment method change whose new payment method could not be saved.
	 *
	 * Nothing was charged. The outcome carries no provider reference or meta and is payment-method-only, so the
	 * subscription keeps its payment method and status, and the shopper sees why.
	 *
	 * @return PaymentOutcome
	 */
	private function payment_method_change_token_save_failed_outcome(): PaymentOutcome {
		$message = __( 'Unable to save payment method for subscription. Please try again or use a different payment method.', 'woocommerce' );

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE          => 'wcpay_recurring_token_save_failed',
				PaymentOutcome::DATA_ERROR_MESSAGE       => $message,
				WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => $message,
				PaymentOutcome::DATA_PAYMENT_METHOD_ONLY => true,
			)
		);
	}

	/**
	 * Attach a token to an order and synchronize related subscriptions.
	 *
	 * @param WC_Order         $order             Order being updated.
	 * @param WC_Payment_Token $token             WooCommerce token.
	 * @param string           $payment_method_id Provider payment method ID.
	 * @param string           $customer_id       Provider customer ID.
	 * @return bool Whether the token was attached and synchronized.
	 */
	private function attach_and_sync_token( WC_Order $order, WC_Payment_Token $token, string $payment_method_id, string $customer_id ): bool {
		if ( ! $this->token_service->attach_token_to_order( $order, $token ) ) {
			return false;
		}

		$this->token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $customer_id );

		return true;
	}

	/**
	 * Persist the payment method, customer and mode of a $0 order confirmed without an intent.
	 *
	 * @param WC_Order             $order   Order being updated.
	 * @param PaymentOutcome       $outcome Outcome without a provider reference.
	 * @param array<string,string> $meta    Setup metadata.
	 */
	private function persist_zero_amount_details( WC_Order $order, PaymentOutcome $outcome, array $meta ): void {
		if ( '' !== $outcome->get_payment_method_id() ) {
			$order->update_meta_data( '_payment_method_id', $outcome->get_payment_method_id() );
		}
		if ( '' !== $outcome->get_customer_id() ) {
			$order->update_meta_data( '_stripe_customer_id', $outcome->get_customer_id() );
		}
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
	}

	/**
	 * Write what client 11.1.0 writes when it confirms a $0 order without an intent (gw:1673-1771).
	 *
	 * The payment method, customer and mode; the card title and details from the payment method; a saved token on the order
	 * and its subscriptions with the job that pushes the billing details to it. No intent, transaction id or note.
	 *
	 * @param WC_Order                   $order   Order being confirmed.
	 * @param PaymentOutcome             $outcome Completed outcome without a provider reference.
	 * @param WooPaymentsOrderEffectPlan $plan    Zero-amount plan.
	 * @return void
	 */
	private function apply_zero_amount_without_intent( WC_Order $order, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): void {
		$payment_method_id = $outcome->get_payment_method_id();
		$this->persist_zero_amount_details( $order, $outcome, $plan->get_setup_meta() );

		$token_id = (int) ( $plan->get_provider_result()['token_id'] ?? 0 );
		$token    = 0 < $token_id ? WC_Payment_Tokens::get( $token_id ) : null;
		// The client skips the payment method fetch for Link tokens (gw:1693-1696).
		$details = $token instanceof LinkToken ? array() : $this->token_service->get_payment_method_details_for_display( $payment_method_id );
		$this->apply_setup_intent_payment_method_display_details( $order, $details );

		if ( ! $token instanceof WC_Payment_Token ) {
			return;
		}

		$this->token_service->attach_token_to_order( $order, $token );
		$this->token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $outcome->get_customer_id() );
		$this->action_scheduler->schedule_job(
			WooPaymentsTokenService::UPDATE_SAVED_PAYMENT_METHOD_ACTION,
			array(
				'payment_method' => $payment_method_id,
				'order_id'       => $order->get_id(),
				'is_test_mode'   => $this->account_service->is_test_mode_enabled(),
			)
		);
	}
}
