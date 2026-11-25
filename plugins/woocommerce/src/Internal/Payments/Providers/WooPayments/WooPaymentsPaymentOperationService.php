<?php
/**
 * WooPaymentsPaymentOperationService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use WC_Order;

/**
 * Sends WooPayments charges, zero-total setups, refunds, captures and cancels to the platform and maps each answer to a payment outcome.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsPaymentOperationService {

	/**
	 * Order meta key holding the charge idempotency key, kept until a definitive outcome.
	 *
	 * Only the store's own charge attempt clears the key, kept time and ambiguity record under its order payment lock.
	 * Clearing them anywhere else can remove the key another attempt still needs to avoid a second charge.
	 *
	 * @var string
	 * @since 11.2.0
	 */
	public const CHARGE_IDEMPOTENCY_KEY_META = '_wcpay_charge_idempotency_key';

	/**
	 * Order meta key holding the Unix time the charge idempotency key was kept, written in the save that keeps it.
	 *
	 * @since 11.4.0
	 */
	public const CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META = '_wcpay_charge_idempotency_key_kept_at';

	/**
	 * Order meta key recording the ambiguous charge failure that left the order's charge idempotency key unresolved.
	 *
	 * An array with the order ID, every customer a failed request under the key was sent with, the time of the first
	 * failure and whether the merchant was told the payment cannot be checked. It lives and dies with the unresolved key, and
	 * lets a refused resubmit look up what the failed requests did.
	 *
	 * @var string
	 * @since 11.2.0
	 */
	public const CHARGE_AMBIGUITY_META = '_wcpay_charge_ambiguity';

	/**
	 * Order meta key recording the refund request whose answer was ambiguous, with the key it was sent under.
	 *
	 * An array with the order and charge, the key, the amount and currency, the exact request, the local refund the
	 * failed call created, the first and latest failure times, and the refund a later lookup located. The order's next
	 * refund call reads the charge's refunds before sending anything; see WooPaymentsRefundAmbiguityService.
	 *
	 * @var string
	 * @since 11.2.0
	 */
	public const REFUND_AMBIGUITY_META = '_wcpay_refund_ambiguity';

	/**
	 * Provider data key set when a capture runs because the order status changed to completed.
	 */
	public const PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE = 'capture_on_status_change';

	/**
	 * Provider data key set when a cancel runs because the order status changed to cancelled.
	 *
	 * @since 11.4.0
	 */
	public const PROVIDER_DATA_CANCEL_ON_STATUS_CHANGE = 'cancel_on_status_change';

	/**
	 * Provider data key carrying the intent a status-change capture or cancel read from the platform, for an order whose
	 * stored intent status was not settled. The operation acts on this intent, after checking it against the order read
	 * under the operation's order payment lock.
	 *
	 * @since 11.2.0
	 */
	public const PROVIDER_DATA_READ_INTENT_ID = 'read_intent_id';

	/**
	 * Provider data key carrying the intent a status-change capture or cancel on the stored authorization acts on: the
	 * order's stored intent as the status change found it, checked again under the operation's order payment lock.
	 *
	 * @since 11.2.0
	 */
	public const PROVIDER_DATA_STORED_INTENT_ID = 'stored_intent_id';

	/**
	 * Outcome data key set when a status-change capture or cancel sent nothing because the order, read again under the
	 * operation's order payment lock, no longer matches what the status change acts on: one of the REFUSAL_* values.
	 *
	 * @since 11.2.0
	 */
	public const DATA_STATUS_CHANGE_REFUSAL = 'status_change_refusal';

	/**
	 * Refusal of a capture or cancel: the order no longer stores the intent the status change acts on, the one it read or
	 * the stored one. The operation is tried again, and the retry reads the order's intent again.
	 *
	 * @since 11.2.0
	 */
	public const REFUSAL_INTENT_CHANGED = 'intent_changed';

	/**
	 * Refusal of a capture or cancel: the order now stores a settled intent status. Nothing is left to capture or cancel.
	 *
	 * @since 11.2.0
	 */
	public const REFUSAL_INTENT_SETTLED = 'intent_settled';

	/**
	 * Refusal of a status-change capture only: the order no longer pays through WooPayments, so its capture is not sent and
	 * nothing is left to do. A cancel takes no money, so it is never refused for this.
	 *
	 * @since 11.4.0
	 */
	public const REFUSAL_PAYMENT_METHOD_CHANGED = 'payment_method_changed';

	/**
	 * Log reason of a capture or cancel on an order that stores requires_capture while its transaction ID and stored intent
	 * name two different intents.
	 */
	private const REASON_OPERATION_ON_AN_ORDER_NAMING_TWO_INTENTS = 'operation_on_an_order_naming_two_intents';

	/**
	 * Stored intent statuses that say a bank step was started: the shopper can still finish it in the browser, and Stripe
	 * takes the money before the store hears of it.
	 */
	private const STARTED_INTENT_STATUSES = array( WooPaymentsIntentStatus::REQUIRES_ACTION, WooPaymentsIntentStatus::REQUIRES_CONFIRMATION );

	/**
	 * Tier of a charge refused over an unread payment whose stored record shows money held or on its way, named in the log.
	 */
	private const UNREAD_PAYMENT_TIER_MONEY_HELD = 'money_held';

	/**
	 * Tier of a charge refused over an unread payment whose stored record shows a started bank step, named in the log.
	 */
	private const UNREAD_PAYMENT_TIER_STEP_STARTED = 'step_started';

	/**
	 * Outcome error code of a capture or cancel that was not sent because its intent is not the order's stored intent and
	 * the platform's record of it does not name the order.
	 *
	 * @since 11.4.0
	 */
	public const ERROR_OPERATION_TARGET_OF_ANOTHER_ORDER = 'wcpay_operation_target_of_another_order';

	/**
	 * Log reason of a capture or cancel that was not sent because its intent is not shown to be the order's own.
	 */
	private const REASON_OPERATION_TARGET_OF_ANOTHER_ORDER = 'operation_target_of_another_order';

	/**
	 * Intent statuses a payment intent never leaves, so an order storing one has no authorization to capture or cancel
	 * (client 11.3.0 class-wc-payments-order-service.php:89-92).
	 *
	 * @since 11.2.0
	 */
	public const SETTLED_INTENT_STATUSES = array( WooPaymentsIntentStatus::SUCCEEDED, WooPaymentsIntentStatus::CANCELED );

	/**
	 * Outcome data key marking a definitive charge failure.
	 *
	 * @var string
	 */
	private const DEFINITIVE_CHARGE_FAILURE_DATA_KEY = '_wcpay_definitive_charge_failure';

	/**
	 * Error code of an Interac in-person refund with no card reader refund to link, as client 11.1.0 answers it
	 * (class-wc-payment-gateway-wcpay.php:2962-2967): none of its amount is on the charge, or the order already records it.
	 * No refund request was made.
	 *
	 * @var string
	 */
	public const IN_PERSON_REFUND_NOT_POSSIBLE_CODE = 'wcpay_edit_order_refund_not_possible';

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * Intent request builder.
	 *
	 * @var WooPaymentsIntentRequestBuilder
	 */
	private WooPaymentsIntentRequestBuilder $request_builder;

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
	 * Fraud ruleset service.
	 *
	 * @var WooPaymentsFraudRulesetService
	 */
	private WooPaymentsFraudRulesetService $fraud_ruleset_service;

	/**
	 * Charge ambiguity service.
	 *
	 * @var WooPaymentsChargeAmbiguityService
	 */
	private WooPaymentsChargeAmbiguityService $ambiguity_service;

	/**
	 * Refund ambiguity service.
	 *
	 * @var WooPaymentsRefundAmbiguityService
	 */
	private WooPaymentsRefundAmbiguityService $refund_ambiguity_service;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Level 3 data service.
	 *
	 * @var WooPaymentsLevel3Service
	 */
	private WooPaymentsLevel3Service $level3_service;

	/**
	 * Initialize the class instance.
	 *
	 * The duplicate payment prevention service is read from the container where it is used, not taken here: the
	 * onboarding availability check resolves the provider, which builds this service.
	 *
	 * @internal
	 *
	 * @param Client                            $api_client               WooPayments API client.
	 * @param WooPaymentsCustomerService        $customer_service         Customer service.
	 * @param WooPaymentsIntentRequestBuilder   $request_builder          Request builder.
	 * @param WooPaymentsAccountService         $account_service          Account service.
	 * @param WooPaymentsOrderNoteService       $note_service             Order note service.
	 * @param WooPaymentsFraudRulesetService    $fraud_ruleset_service    Fraud ruleset service.
	 * @param WooPaymentsChargeAmbiguityService $ambiguity_service        Charge ambiguity service.
	 * @param WooPaymentsRefundAmbiguityService $refund_ambiguity_service Refund ambiguity service.
	 * @param WooPaymentsLogger                 $logger                   WooPayments logger.
	 * @param WooPaymentsLevel3Service          $level3_service           Level 3 data service.
	 */
	final public function init(
		Client $api_client,
		WooPaymentsCustomerService $customer_service,
		WooPaymentsIntentRequestBuilder $request_builder,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderNoteService $note_service,
		WooPaymentsFraudRulesetService $fraud_ruleset_service,
		WooPaymentsChargeAmbiguityService $ambiguity_service,
		WooPaymentsRefundAmbiguityService $refund_ambiguity_service,
		WooPaymentsLogger $logger,
		WooPaymentsLevel3Service $level3_service
	): void {
		$this->api_client            = $api_client;
		$this->customer_service      = $customer_service;
		$this->request_builder       = $request_builder;
		$this->account_service       = $account_service;
		$this->note_service          = $note_service;
		$this->fraud_ruleset_service = $fraud_ruleset_service;
		$this->ambiguity_service     = $ambiguity_service;

		$this->refund_ambiguity_service = $refund_ambiguity_service;
		$this->logger                   = $logger;
		$this->level3_service           = $level3_service;
	}

	/**
	 * Charge an order through the WooPayments platform API.
	 *
	 * A payment method change takes no payment, whatever the order total: it sets up the payment method, as the
	 * order-status callback confirms a change through a SetupIntent at any total (client 11.3.0
	 * class-wc-payment-gateway-wcpay.php:4643-4666).
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt. A positive-amount charge keeps its
	 *                                                 key on the order and sends the kept key on later attempts until a definitive
	 *                                                 outcome retires it, so a retry after an ambiguous failure replays the request.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		if ( $this->api_client->is_available() ) {
			try {
				$outcome = 0.0 < (float) $context->get_order()->get_total()
					&& empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] )
					? $this->charge_order_total( $context, $idempotency_key )
					: $this->set_up_payment_method( $context, $idempotency_key );
			} catch ( ApiException $exception ) {
				$outcome = $this->failed_charge_outcome( $context->get_order(), $exception );
			}

			return $this->normalize_unusable_scheduled_renewal_failure( $context, $outcome );
		}

		return $this->unavailable_outcome( PaymentProcessingService::OPERATION_CHARGE );
	}

	/**
	 * Mark the outcome of a subscription payment-method change as payment-method-only when the change took no payment.
	 *
	 * On a WooCommerce Subscriptions payment-method change the order is the subscription itself, and the change only saves
	 * the card: client 11.3.0 makes no completion call on it (includes/class-wc-payment-gateway-wcpay.php:2497-2500,
	 * :1998-2002), and client 11.1.0 notes a failed change and leaves the status alone (:1326-1328). The flag goes only
	 * on an outcome that took no payment: one that failed, one with a SetupIntent or zero-amount plan, or one with no
	 * external payment. An outcome with a PaymentIntent plan that did not fail is never marked; a change does not create
	 * one, so this is the backup check for that rule. The provider applies this to every charge outcome, including the
	 * one the payment processing service builds when the charge throws.
	 *
	 * @param PaymentOperationContext $context Charge context.
	 * @param PaymentOutcome          $outcome Charge outcome.
	 * @return PaymentOutcome
	 */
	public function mark_payment_method_change_outcome( PaymentOperationContext $context, PaymentOutcome $outcome ): PaymentOutcome {
		if (
			empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] )
			|| ! self::took_no_payment( $outcome )
		) {
			return $outcome;
		}

		$data = $outcome->get_data();
		$data[ PaymentOutcome::DATA_PAYMENT_METHOD_ONLY ] = true;
		$marked      = new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			$data
		);
		$effect_plan = $outcome->get_effect_plan();

		return null === $effect_plan ? $marked : $marked->with_effect_plan( $effect_plan );
	}

	/**
	 * Tell whether a charge outcome took no payment: it failed, it set up a payment method (a SetupIntent or zero-amount
	 * plan), or no external payment was needed.
	 *
	 * @param PaymentOutcome $outcome Charge outcome.
	 * @return bool
	 */
	private static function took_no_payment( PaymentOutcome $outcome ): bool {
		if ( in_array( $outcome->get_status(), array( PaymentOutcome::STATUS_FAILED, PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT ), true ) ) {
			return true;
		}

		$plan = $outcome->get_effect_plan();

		return $plan instanceof WooPaymentsOrderEffectPlan
			&& in_array( $plan->get_type(), array( WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT, WooPaymentsOrderEffectPlan::TYPE_ZERO_AMOUNT_WITHOUT_INTENT ), true );
	}

	/**
	 * Refund an order through the WooPayments platform API.
	 *
	 * An Interac in-person payment is not refunded online: the WooCommerce mobile app refunds it on the card reader and
	 * then records it here, so the call links that refund. Otherwise an earlier refund call of the order whose answer was
	 * ambiguous is settled first, from the charge's refunds, so a retry never refunds twice: this call may link the earlier
	 * refund, resend it under its kept key, or be refused with no request. An ambiguous answer to this call's own request
	 * keeps its key for the next call. Each request is kept on the order from before it is sent until its row is linked.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this refund call.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$charge_id = (string) $order->get_meta( WooPaymentsPersistenceVocabulary::CHARGE_ID_META_KEY, true );
			if ( '' !== $charge_id ) {
				$payment_data = $context->get_payment_data();
				$request      = array(
					'charge' => $charge_id,
					'amount' => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) ( $payment_data[ PaymentOperationContext::PAYMENT_DATA_AMOUNT ] ?? 0.0 ), (string) $order->get_currency() ),
					'reason' => (string) ( $payment_data[ PaymentOperationContext::PAYMENT_DATA_REASON ] ?? '' ),
					'source' => 'woocommerce_core',
				);

				if ( $this->is_interac_present_payment( $order ) ) {
					return $this->link_card_reader_refund( $order, $request );
				}

				$own_row_id = (int) ( $payment_data[ PaymentOperationContext::PAYMENT_DATA_REFUND_ID ] ?? 0 );
				$decision   = $this->refund_ambiguity_service->decide( $order, $request, $own_row_id );
				switch ( $decision['action'] ) {
					case WooPaymentsRefundAmbiguityService::ACTION_REFUSE:
						return self::refused_refund_outcome( $decision );
					case WooPaymentsRefundAmbiguityService::ACTION_LINK:
						$refund = $decision['refund'] ?? array();

						return WooPaymentsIntentCodec::outcome_from_refund_result( $refund )->with_effect_plan( WooPaymentsOrderEffectPlan::for_refund( $refund ) );
					case WooPaymentsRefundAmbiguityService::ACTION_RETRY_HELD_KEY:
						return $this->send_refund( $order, $decision['request'] ?? $request, $decision['key'] ?? '', true, $own_row_id );
					default:
						return $this->send_refund( $order, $request, $idempotency_key, false, $own_row_id );
				}
			}
		}

		return $this->unavailable_outcome( PaymentProcessingService::OPERATION_REFUND );
	}

	/**
	 * Tell whether the order was paid in person with an Interac card.
	 *
	 * The type is read from the payment method details saved on the order, else from the payment method, which client
	 * 11.1.0 fetches on every refund (class-wc-payment-gateway-wcpay.php:3031-3049). A failed fetch reads as another type,
	 * so the refund is sent and the platform answers it.
	 *
	 * @param WC_Order $order Order being refunded.
	 * @return bool
	 */
	private function is_interac_present_payment( WC_Order $order ): bool {
		$stored_details = $order->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_DETAILS_META_KEY, true );
		$details        = is_string( $stored_details ) ? json_decode( $stored_details, true ) : null;
		if ( ! is_array( $details ) || array() === $details ) {
			$payment_method_id = $order->get_meta( WooPaymentsPersistenceVocabulary::PAYMENT_METHOD_ID_META_KEY, true );
			if ( ! is_string( $payment_method_id ) || '' === $payment_method_id ) {
				return false;
			}

			try {
				$details = $this->api_client->get_payment_method( $payment_method_id );
			} catch ( ApiException $exception ) {
				$this->logger->log_throwable( 'Could not read the payment method type of a refunded WooPayments order.', $exception, array( 'order_id' => $order->get_id() ) );
				return false;
			}
		}

		return WooPaymentsPaymentMethodType::INTERAC_PRESENT === ( $details['type'] ?? null );
	}

	/**
	 * Link the refund the card reader made for an Interac in-person payment, with no refund request.
	 *
	 * As client 11.1.0 (class-wc-payment-gateway-wcpay.php:2944-2968), the oldest succeeded refund of the requested amount
	 * is linked, and with none the call is refused. A refund the order already records is skipped, so a second reader
	 * refund of the same amount is not linked to the first one's refund; when only such a refund matches, the refusal says
	 * it is already recorded, so the merchant is not sent back to the reader to refund it again.
	 *
	 * @param WC_Order                                                    $order   Order being refunded.
	 * @param array{charge:string,amount:int,reason:string,source:string} $request Refund request this call would send.
	 * @return PaymentOutcome
	 */
	private function link_card_reader_refund( WC_Order $order, array $request ): PaymentOutcome {
		try {
			$list = $this->api_client->list_charge_refunds( $request['charge'] );
		} catch ( ApiException $exception ) {
			return WooPaymentsIntentCodec::failed_transport_outcome( PaymentProcessingService::OPERATION_REFUND, $exception );
		}

		$recorded_refund_ids = array();
		foreach ( $order->get_refunds() as $wc_refund ) {
			$recorded_refund_ids[] = (string) $wc_refund->get_meta( WooPaymentsPersistenceVocabulary::PROCESSED_REFUND_LINK_META_KEY, true );
		}

		// Stripe lists a charge's refunds newest first.
		$refunds          = is_array( $list['data'] ?? null ) ? array_reverse( $list['data'] ) : array();
		$already_recorded = false;
		foreach ( $refunds as $refund ) {
			if ( ! is_array( $refund ) || WooPaymentsRefundStatus::SUCCEEDED !== ( $refund['status'] ?? null ) || ! isset( $refund['amount'] ) || $request['amount'] !== $refund['amount'] || ! is_string( $refund['id'] ?? null ) ) {
				continue;
			}

			if ( ! in_array( $refund['id'], $recorded_refund_ids, true ) ) {
				return WooPaymentsIntentCodec::outcome_from_refund_result( $refund )->with_effect_plan( WooPaymentsOrderEffectPlan::for_refund( $refund ) );
			}

			$already_recorded = true;
		}

		return self::refused_refund_outcome(
			array(
				'code'    => self::IN_PERSON_REFUND_NOT_POSSIBLE_CODE,
				'message' => $already_recorded
					? __( 'This refund is already recorded on the order.', 'woocommerce' )
					: __( 'You shall refund this payment in the same application where the payment was made.', 'woocommerce' ),
			)
		);
	}

	/**
	 * Send a refund request and answer the refund call with its result.
	 *
	 * @param WC_Order                                                    $order             Order being refunded.
	 * @param array{charge:string,amount:int,reason:string,source:string} $request           Refund request.
	 * @param string                                                      $key               Key to send it under.
	 * @param bool                                                        $is_held_key_retry Whether this resends an earlier ambiguous request under its kept key.
	 * @param int                                                         $own_row_id        The refund row this call links, 0 when unknown.
	 * @return PaymentOutcome
	 */
	private function send_refund( WC_Order $order, array $request, string $key, bool $is_held_key_retry, int $own_row_id ): PaymentOutcome {
		if ( ! $is_held_key_retry ) {
			$this->refund_ambiguity_service->record_sent_request( $order, $key, $request, $own_row_id );
		}

		try {
			$result = $this->api_client->refund_charge( $request['charge'], $request['amount'], $request['reason'], $request['source'], $key );
		} catch ( ApiException $exception ) {
			// A definitive answer clears this call's own request. After a held-key retry it leaves the record as it was: a 400
			// can be Stripe's refusal of a key still in use or the replay of a stored failure, neither of which settles it.
			if ( $exception->has_ambiguous_outcome() ) {
				$this->refund_ambiguity_service->record_ambiguous_answer( $order, $key, $request, $own_row_id );
			} elseif ( ! $is_held_key_retry ) {
				$this->refund_ambiguity_service->clear_sent_request( $order, $key );
			}

			return WooPaymentsIntentCodec::failed_transport_outcome( PaymentProcessingService::OPERATION_REFUND, $exception );
		}

		$outcome = WooPaymentsIntentCodec::outcome_from_refund_result( $result );
		if ( ! $is_held_key_retry && ! $outcome->is_successful() ) {
			$this->refund_ambiguity_service->clear_sent_request( $order, $key );
		}

		if ( $is_held_key_retry && $outcome->is_successful() ) {
			$refusal = $this->refund_ambiguity_service->settle_held_key_answer( $order, $result );
			if ( null !== $refusal ) {
				return self::refused_refund_outcome( $refusal );
			}
		}

		return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_refund( $result ) );
	}

	/**
	 * Build the failed outcome of a refund call refused with no platform request.
	 *
	 * @param array{code?:string,message?:string} $decision Refusal decision, with its code and merchant message.
	 * @return PaymentOutcome
	 */
	private static function refused_refund_outcome( array $decision ): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => $decision['code'] ?? '',
				PaymentOutcome::DATA_ERROR_MESSAGE => $decision['message'] ?? '',
			)
		);
	}

	/**
	 * Capture an authorized payment through the WooPayments platform API.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted for this call; not sent to the provider.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		// Each capture request carries its own key, as in client 11.1.0 (wc-payment-api/class-wc-payments-api-client.php:2690),
		// so a merchant's retry after a failed capture reaches the provider instead of replaying the stored failure.
		unset( $idempotency_key );

		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$refusal = $this->refuse_status_change_on_a_changed_order( $context, PaymentProcessingService::OPERATION_CAPTURE );
			if ( null !== $refusal ) {
				return $refusal;
			}

			$intent_id = $this->get_operation_intent_id( $context, PaymentProcessingService::OPERATION_CAPTURE );
			if ( '' !== $intent_id ) {
				$refusal = $this->refuse_an_intent_id_outside_the_form( $context, PaymentProcessingService::OPERATION_CAPTURE, $intent_id );
				if ( null !== $refusal ) {
					return $refusal;
				}

				$refusal = $this->refuse_a_target_of_another_order( $context, PaymentProcessingService::OPERATION_CAPTURE, $intent_id );
				if ( null !== $refusal ) {
					return $refusal;
				}

				$amount_to_capture = WooPaymentsCurrencyUtils::amount_to_minor_units( $context->get_amount() ?? (float) $order->get_total(), (string) $order->get_currency() );
				// Client 11.1.0 always captures the order total (`class-wc-payment-gateway-wcpay.php:3966, 3981`) and sends the
				// order's Level 3 data with it (:3984-3986), so it never meets a partial capture. The order's line items add up to
				// the order total only, so a partial capture here goes without Level 3 data.
				$is_order_total = WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), (string) $order->get_currency() ) === $amount_to_capture;
				try {
					$result  = $this->api_client->capture_intention(
						$intent_id,
						$amount_to_capture,
						$this->capture_metadata( $order ),
						$is_order_total ? $this->capture_level3_data( $order ) : array()
					);
					$outcome = WooPaymentsIntentCodec::outcome_from_capture_result( $result, $intent_id );
					$plan    = WooPaymentsOrderEffectPlan::for_capture( $result );
					if ( empty( $context->get_provider_data()[ self::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE ] ) ) {
						return $outcome->with_effect_plan( $plan );
					}

					// Client 11.3.0's status-change capture adds the capture note, schedules the Fee details job and stores
					// the intent status, with no fee meta and no payment completion: the merchant's change set the order
					// status (class-wc-payments-order-service.php:2381-2429). Its capture writer, which other captures
					// reach, completes the payment (:2193-2219, :2216).
					return self::leaving_order_status_when( PaymentOutcome::STATUS_COMPLETED, $outcome )->with_effect_plan( $plan->without_fee_meta() );
				} catch ( ApiException $exception ) {
					$outcome = WooPaymentsIntentCodec::failed_transport_outcome( PaymentProcessingService::OPERATION_CAPTURE, $exception, $intent_id );

					// The site may have missed the charge.expired webhook, so a failed capture
					// re-fetches the intent: a canceled intent means the authorization expired,
					// and the effect plan carries it so the expired note and failed status land.
					$expired_intent = $this->refetch_intent_after_failure( $intent_id );
					if ( null !== $expired_intent && WooPaymentsIntentStatus::CANCELED === (string) ( $expired_intent['status'] ?? '' ) ) {
						return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_capture_expired( $expired_intent ) );
					}

					$result = array(
						'id'         => $intent_id,
						'status'     => 'failed',
						'error_code' => $exception->get_error_code(),
						'message'    => $exception->getMessage(),
					);

					return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_capture( $result ) );
				}
			}
		}

		return $this->unavailable_outcome( PaymentProcessingService::OPERATION_CAPTURE );
	}

	/**
	 * Mark an outcome of the given status to leave the order status untouched; return any other outcome as it is.
	 *
	 * @param string         $status  Outcome status to mark: a completed capture or a canceled authorization.
	 * @param PaymentOutcome $outcome Capture or cancel outcome.
	 * @return PaymentOutcome
	 */
	private static function leaving_order_status_when( string $status, PaymentOutcome $outcome ): PaymentOutcome {
		if ( $status !== $outcome->get_status() ) {
			return $outcome;
		}

		return new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			array_merge( $outcome->get_data(), array( PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true ) )
		);
	}

	/**
	 * Cancel an authorized payment through the WooPayments platform API.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted for this call; not sent to the provider.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		// Each cancel request carries its own key, as for capture.
		unset( $idempotency_key );

		if ( $this->api_client->is_available() ) {
			$refusal = $this->refuse_status_change_on_a_changed_order( $context, PaymentProcessingService::OPERATION_CANCEL );
			if ( null !== $refusal ) {
				return $refusal;
			}

			$intent_id = $this->get_operation_intent_id( $context, PaymentProcessingService::OPERATION_CANCEL );
			if ( '' !== $intent_id ) {
				$refusal = $this->refuse_an_intent_id_outside_the_form( $context, PaymentProcessingService::OPERATION_CANCEL, $intent_id );
				if ( null !== $refusal ) {
					return $refusal;
				}

				$refusal = $this->refuse_a_target_of_another_order( $context, PaymentProcessingService::OPERATION_CANCEL, $intent_id );
				if ( null !== $refusal ) {
					return $refusal;
				}

				try {
					return $this->cancel_outcome( $context, $this->api_client->cancel_intention( $intent_id ) );
				} catch ( ApiException $exception ) {
					// The provider may have canceled the intent already (an expired authorization
					// whose charge.expired webhook was missed, or a cancel that completed despite
					// the transport error): a re-fetched canceled intent is a completed cancel,
					// as in client 11.1.0's cancel_authorization() (class-wc-payment-gateway-wcpay.php:4083-4102).
					$intent = $this->refetch_intent_after_failure( $intent_id );
					if ( null !== $intent && WooPaymentsIntentStatus::CANCELED === (string) ( $intent['status'] ?? '' ) ) {
						return $this->cancel_outcome( $context, $intent );
					}

					$outcome = WooPaymentsIntentCodec::failed_transport_outcome( PaymentProcessingService::OPERATION_CANCEL, $exception, $intent_id );

					// Not canceled: the plan carries the status the provider still reports so
					// the applier records it with the failure note, as client 11.1.0 does
					// (class-wc-payment-gateway-wcpay.php:4104-4110).
					return null !== $intent
						? $outcome->with_effect_plan( $this->cancel_plan( $context, $intent ) )
						: $outcome;
				}
			}
		}

		return $this->unavailable_outcome( PaymentProcessingService::OPERATION_CANCEL );
	}

	/**
	 * Build a cancel's outcome and effect plan from the provider's answer.
	 *
	 * A cancel the order's change to Cancelled ran leaves the order status as it is: client 11.3.0's status-change cancel
	 * sets no status (class-wc-payments-order-service.php:2327-2359), so a status an earlier callback on that change set
	 * stands, and the order is not moved to Cancelled a second time.
	 *
	 * @param PaymentOperationContext $context Cancel context.
	 * @param array<string,mixed>     $result  Provider cancellation response, or the intent read after a failed cancel.
	 * @return PaymentOutcome
	 */
	private function cancel_outcome( PaymentOperationContext $context, array $result ): PaymentOutcome {
		$outcome = WooPaymentsIntentCodec::outcome_from_cancel_result( $result );
		if ( ! empty( $context->get_provider_data()[ self::PROVIDER_DATA_CANCEL_ON_STATUS_CHANGE ] ) ) {
			$outcome = self::leaving_order_status_when( PaymentOutcome::STATUS_CANCELED, $outcome );
		}

		return $outcome->with_effect_plan( $this->cancel_plan( $context, $result ) );
	}

	/**
	 * Build a cancel's effect plan. A cancel the order's change to Cancelled ran writes no fraud meta, as client 11.3.0's
	 * status-change cancel writes none (class-wc-payments-order-service.php:2327-2369); the order screen's and the REST
	 * route's cancels reach its cancelled-authorization writer, which does (:480-481, :2009-2014).
	 *
	 * @param PaymentOperationContext $context Cancel context.
	 * @param array<string,mixed>     $result  Provider cancellation response, or the intent read after a failed cancel.
	 * @return WooPaymentsOrderEffectPlan
	 */
	private function cancel_plan( PaymentOperationContext $context, array $result ): WooPaymentsOrderEffectPlan {
		$plan = WooPaymentsOrderEffectPlan::for_cancel( $result );

		return empty( $context->get_provider_data()[ self::PROVIDER_DATA_CANCEL_ON_STATUS_CHANGE ] ) ? $plan : $plan->without_fraud_meta();
	}

	/**
	 * Charge an order's total through the WooPayments platform API.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt. A charge key already stored on
	 *                                                 the order is sent instead until a definitive outcome retires it.
	 * @return PaymentOutcome
	 * @throws ApiException When the provider request fails.
	 */
	private function charge_order_total( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		$context            = $this->request_builder->with_saved_payment_token_method_type( $context );
		$order              = $context->get_order();
		$payment_credential = $this->request_builder->payment_credential_from_context( $context );
		$is_recurring       = $this->is_recurring_payment( $context );

		if ( '' === $payment_credential ) {
			return $this->missing_payment_credential_outcome();
		}

		$this->assert_total_meets_cached_platform_minimum( $order );

		// Stated lock exception: a first charge may need to create its customer before it can send the payment.
		$customer_id      = $this->get_customer_id_for_context( $context );
		$woopay_intent_id = $this->get_woopay_intent_id( $context );

		if ( ! empty( $woopay_intent_id ) ) {
			$this->assert_valid_stripe_id( $woopay_intent_id );
			$result = $this->api_client->get_payment_intention( $woopay_intent_id );
			$this->assert_woopay_intent_belongs_to_order( $result, $order, true );
		} else {
			$request_data = $this->request_builder->charge_request_data( $context, $payment_credential, $customer_id, $is_recurring );
			$result       = $this->send_charge_request( $context, $request_data, $idempotency_key );
			if ( $result instanceof PaymentOutcome ) {
				return $result;
			}

			$customer_id = (string) ( $request_data['customer'] ?? $customer_id );
		}

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$this->build_mapping_context( $result, $context, $payment_credential, $customer_id )
		);

		$this->maybe_add_customer_notification_note( $order, $result );

		return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $result, $is_recurring ) );
	}

	/**
	 * Return the order's own kept charge key, or keep the candidate as that key and return it.
	 *
	 * The save that keeps the key also writes the mode of the attempt it starts, before the intent request, as client
	 * 11.3.0 writes it before any other value of an attempt (class-wc-payment-gateway-wcpay.php:1962-1964): a declined
	 * attempt keeps it, so the takeover cleanup reads it as the sign of a WooPayments attempt.
	 *
	 * @param WC_Order $order     Order being charged.
	 * @param string   $candidate Current checkout invocation key.
	 * @return string
	 *
	 * @since 11.2.0
	 */
	private function ensure_charge_idempotency_key( WC_Order $order, string $candidate ): string {
		$persisted_key = self::get_own_charge_idempotency_key( $order );
		if ( '' !== $persisted_key ) {
			return $persisted_key;
		}

		$order->delete_meta_data( self::CHARGE_AMBIGUITY_META );
		$order->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META, $candidate );
		$order->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META, (string) time() );
		$order->update_meta_data( WooPaymentsPersistenceVocabulary::MODE_META_KEY, $this->account_service->get_order_mode() );
		$order->save_meta_data();

		return $candidate;
	}

	/**
	 * Get the charge key checkout would reuse for this order.
	 *
	 * @since 11.4.0
	 * @param WC_Order $order Order being charged.
	 * @return string The order's own key, or an empty string.
	 */
	public static function get_own_charge_idempotency_key( WC_Order $order ): string {
		$persisted_key = (string) $order->get_meta( self::CHARGE_IDEMPOTENCY_KEY_META, true );
		$record        = $order->get_meta( self::CHARGE_AMBIGUITY_META, true );
		// A record naming another order means the key was copied from that order with it, so neither belongs here.
		$is_copied = is_array( $record ) && (int) ( $record['order_id'] ?? 0 ) !== $order->get_id();

		return $is_copied ? '' : $persisted_key;
	}

	/**
	 * Send a positive-amount charge under the order's kept key, or under the attempt key when none is kept.
	 *
	 * A charge that could pay again for the order's attached payment, which the duplicate check could not read, is refused
	 * before anything is written or sent; see get_unread_payment_refusal_tier(). A kept key refused because the new request differs from the earlier one (a new card after a timeout) settles what
	 * the earlier request did before anything is charged; see settle_earlier_charge(). A scheduled renewal is not looked
	 * up. Customer recovery requires a definitive missing-customer answer and a credential that can still pay.
	 * Checkout asks for a fresh credential when the platform has consumed the new card or the saved card lost its customer.
	 *
	 * @param PaymentOperationContext $context      Payment context.
	 * @param array<string,mixed>     $request_data Charge request; its customer is replaced when the platform does not find it.
	 * @param string                  $attempt_key  Key minted fresh for this payment attempt.
	 * @return array<string,mixed>|PaymentOutcome The PaymentIntent response, or the outcome to return instead.
	 * @throws ApiException When replacing a missing customer fails.
	 */
	private function send_charge_request( PaymentOperationContext $context, array &$request_data, string $attempt_key ) {
		$order        = $context->get_order();
		$refusal_tier = $this->get_unread_payment_refusal_tier( $context );
		if ( null !== $refusal_tier ) {
			$this->log_charge_refused_over_unread_payment( $order, $refusal_tier );

			return $this->unread_payment_refused_outcome();
		}

		$sent_key = $this->ensure_charge_idempotency_key( $order, $attempt_key );

		try {
			return $this->api_client->create_and_confirm_payment_intention( $request_data, $sent_key );
		} catch ( ApiException $exception ) {
			$record = $this->read_charge_ambiguity_record( $order );
			if ( $this->is_unresolved_key_refusal( $attempt_key, $sent_key, $exception ) ) {
				if ( ! self::is_scheduled_renewal( $context ) ) {
					return $this->settle_earlier_charge( $context, $sent_key, $this->get_lookup_record( $order, $record ) );
				}

				$this->log_unresolved_charge_key_refused( $order, $sent_key );
			}

			// While the record exists the recovery would charge under a key Stripe never saw, with no lookup of the earlier
			// request; the failure keeps the key and the record instead, so a later attempt reaches the lookup.
			if ( ! $this->is_missing_customer_exception( $exception ) || null !== $record ) {
				return $this->failed_charge_outcome( $order, $exception, true, (string) ( $request_data['customer'] ?? '' ), self::is_scheduled_renewal( $context ), (string) ( $request_data['payment_method'] ?? '' ) );
			}

			$missing_customer_id = (string) ( $request_data['customer'] ?? '' );
			$payment_method_id   = (string) ( $request_data['payment_method'] ?? '' );
			$saved_card          = 0 < $this->get_saved_payment_token_id( $context );
			$saved_card_unusable = $saved_card && null === $this->customer_service->get_other_user_customer_id( $order, $missing_customer_id );
			$scheduled_renewal   = self::is_scheduled_renewal( $context );
			if ( $scheduled_renewal && $saved_card_unusable ) {
				return $this->failed_charge_outcome( $order, $exception, true, $missing_customer_id, true, $payment_method_id );
			}

			// The platform shares a new card before it checks the customer. That share can only be used once.
			$new_platform_card = ! $saved_card && ! empty( $request_data['is_platform_payment_method'] );
			if ( ! $scheduled_renewal && ( $saved_card_unusable || $new_platform_card ) ) {
				return $this->customer_replaced_refusal_outcome( $order, $exception, $missing_customer_id, $payment_method_id, $saved_card_unusable );
			}
		}

		// Stated lock exception: the retried charge needs a customer that can use this credential.
		$customer_id              = $this->customer_service->replace_missing_customer_for_order( $order, $missing_customer_id );
		$request_data['customer'] = $customer_id;
		// A different body under the same key would be refused, so the retry gets its own key, kept on the order so that an
		// ambiguous failure of the retry replays the retry. The customer is kept with it before the retry, so a renewal
		// retried later sends the same body under that key; client 11.2.0 stores it only once the retry returns
		// (class-wc-payment-gateway-wcpay.php:5459-5463).
		$recovery_key = self::get_customer_recovery_idempotency_key( $sent_key );
		$order->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META, $recovery_key );
		$order->update_meta_data( WooPaymentsPersistenceVocabulary::STRIPE_CUSTOMER_ID_META_KEY, $customer_id );
		$order->save_meta_data();
		try {
			$result = $this->api_client->create_and_confirm_payment_intention( $request_data, $recovery_key );
		} catch ( ApiException $exception ) {
			return $this->failed_charge_outcome( $order, $exception, true, $customer_id, self::is_scheduled_renewal( $context ), (string) ( $request_data['payment_method'] ?? '' ) );
		}

		if ( 0 < $this->get_saved_payment_token_id( $context ) ) {
			$this->customer_service->replace_stale_subscription_customer_id( $order, (string) ( $request_data['payment_method'] ?? '' ), $missing_customer_id, $customer_id );
		}

		return $result;
	}

	/**
	 * Prepare the next checkout after a definitive refusal of a credential that cannot pay again.
	 *
	 * @param WC_Order     $order               Order being paid.
	 * @param ApiException $exception           Missing-customer answer to the original charge.
	 * @param string       $missing_customer_id Customer sent with that charge.
	 * @param string       $payment_method_id   Payment method sent with that charge.
	 * @param bool         $saved_card          Whether the shopper needs to choose another saved method or enter new details.
	 * @return PaymentOutcome
	 */
	private function customer_replaced_refusal_outcome( WC_Order $order, ApiException $exception, string $missing_customer_id, string $payment_method_id, bool $saved_card ): PaymentOutcome {
		try {
			// Stated lock exception: replace the missing customer for the next press and saved-card list, without resending.
			$this->customer_service->replace_missing_customer_for_order( $order, $missing_customer_id );
		} catch ( \Throwable $replacement_error ) {
			// The charge was definitively refused even if preparing the next checkout failed.
			$this->logger->log_throwable( 'Could not replace the missing checkout customer.', $replacement_error, array( 'order_id' => $order->get_id() ) );
		}

		$outcome = $this->failed_charge_outcome( $order, $exception, true, $missing_customer_id, false, $payment_method_id );
		$data    = $outcome->get_data();

		$data[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ]        = true;
		$data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] = $saved_card ? WooPaymentsErrorMessages::get_unavailable_saved_method_message() : WooPaymentsErrorMessages::get_place_order_again_message();

		return new PaymentOutcome( $outcome->get_status(), $outcome->get_provider_payment_id(), '', $payment_method_id, $missing_customer_id, $data );
	}

	/**
	 * Get the tier by which a charge is refused because it could pay again for the order's attached payment, which the
	 * duplicate check could not read.
	 *
	 * Decided on the order as read again under the order payment lock, only when the check's note names the payment still
	 * attached, made in this request's mode. A record of money held or on its way refuses on any failed read; a record of a
	 * started bank step refuses only when the read left the payment unknown, since a not-found is the platform's answer. A
	 * request with no note, such as a scheduled renewal, is not refused here.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return string|null The tier that refuses, or null when the charge goes on.
	 */
	private function get_unread_payment_refusal_tier( PaymentOperationContext $context ): ?string {
		$failed_read = $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_FAILED_INTENT_READ ] ?? null;
		if ( ! is_array( $failed_read ) || ! is_string( $failed_read['intent_id'] ?? null ) ) {
			return null;
		}

		$unknown = true === ( $failed_read['unknown'] ?? null );

		return $this->get_unread_payment_refusal_tier_for_order( $context->get_order(), $failed_read['intent_id'], $unknown );
	}

	/**
	 * Get the tier by which a charge is refused over an order's attached payment that could not be read, as
	 * get_unread_payment_refusal_tier() decides it for a checkout.
	 *
	 * @since 11.4.0
	 *
	 * @param WC_Order $order            Order as read under the order payment lock.
	 * @param string   $failed_intent_id The intent whose read failed.
	 * @param bool     $unknown          Whether the failed read left the payment unknown.
	 * @return string|null The tier that refuses, or null when the charge goes on.
	 */
	public function get_unread_payment_refusal_tier_for_order( WC_Order $order, string $failed_intent_id, bool $unknown ): ?string {
		if ( $failed_intent_id !== (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) || ! $this->is_payment_of_this_mode( $order ) ) {
			return null;
		}

		$stored_status = (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true );
		if ( WooPaymentsIntentCodec::holds_money( $stored_status ) ) {
			return self::UNREAD_PAYMENT_TIER_MONEY_HELD;
		}

		return in_array( $stored_status, self::STARTED_INTENT_STATUSES, true ) && $unknown
			? self::UNREAD_PAYMENT_TIER_STEP_STARTED
			: null;
	}

	/**
	 * Log, whatever the logging setting, that a charge was refused over the order's attached payment, which could not be
	 * read, so support can see why the checkout was refused. It names the order, the intent and the tier, nothing else.
	 *
	 * @param WC_Order $order Order being charged.
	 * @param string   $tier  The tier that refused.
	 */
	private function log_charge_refused_over_unread_payment( WC_Order $order, string $tier ): void {
		$intent_id = WooPaymentsLogger::get_loggable_provider_id( (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) );
		$this->logger->log_always(
			sprintf(
				'The charge for order #%1$d was not sent: its attached payment, intent %2$s, could not be read, and the order stores %3$s.',
				$order->get_id(),
				$intent_id,
				self::UNREAD_PAYMENT_TIER_MONEY_HELD === $tier ? 'money held or on its way' : 'a started bank step with an unknown outcome'
			),
			'warning',
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => $intent_id,
				'tier'      => $tier,
			)
		);
	}

	/**
	 * Tell whether the order's stored payment was made in the mode the store charges in now. No stored mode counts as this
	 * mode, so a record without one keeps its refusal; exactly "test" is test, and any other value is live.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return bool
	 */
	private function is_payment_of_this_mode( WC_Order $order ): bool {
		$stored_mode = (string) $order->get_meta( WooPaymentsPersistenceVocabulary::MODE_META_KEY, true );

		return '' === $stored_mode || ( WooPaymentsOrderMode::TEST === $stored_mode ) === ( WooPaymentsOrderMode::TEST === $this->account_service->get_order_mode() );
	}

	/**
	 * Build the refusal of a charge over the order's attached payment that could not be read.
	 *
	 * Shaped like the refusal for an earlier request that could not be looked up: nothing is applied to the order, so its
	 * status and the stored record the refusal stands on stay for the next request; no note is added and the
	 * failed-transaction limiter is not bumped. The shopper gets the duplicate check's notice.
	 *
	 * @return PaymentOutcome
	 */
	private function unread_payment_refused_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => WooPaymentsDuplicatePaymentPreventionService::ERROR_CHECK_FAILED,
				WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => __( "We're not able to process this payment. Please try again later.", 'woocommerce' ),
				PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
				PaymentOutcome::DATA_NO_ORDER_CHANGE       => true,
			)
		);
	}

	/**
	 * Tell whether Stripe refused the order's unresolved charge key because the new request differs.
	 *
	 * All three must hold: a kept key was sent instead of the attempt's own; Stripe answered with an idempotency error,
	 * which it raises only when it holds a finished result for the key; and the answer is not ambiguous (a 409 or a server
	 * error means the earlier request may still be running, so its intent may not be listable yet). The caller leaves a
	 * scheduled renewal out of the lookup, since a renewal retry resends the same body and Stripe replays the stored result.
	 *
	 * @param string       $attempt_key Key minted fresh for this payment attempt.
	 * @param string       $sent_key    Key the request was sent with.
	 * @param ApiException $exception   Provider request failure.
	 * @return bool
	 */
	private function is_unresolved_key_refusal( string $attempt_key, string $sent_key, ApiException $exception ): bool {
		return $attempt_key !== $sent_key
			&& $this->is_idempotency_key_conflict( $exception )
			&& ! $exception->has_ambiguous_outcome();
	}

	/**
	 * Tell whether the payment is a scheduled subscription renewal.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return bool
	 */
	private static function is_scheduled_renewal( PaymentOperationContext $context ): bool {
		return true === ( $context->get_provider_data()[ WooPaymentsProviderDataKey::SCHEDULED_SUBSCRIPTION_PAYMENT ] ?? false );
	}

	/**
	 * Get the lookup for a refused kept key: the record's customers, and a window from when the key was kept (the order's
	 * creation for a key kept before that time was saved) to now. Writes a record when the order has none, so the
	 * cannot-check note is added once.
	 *
	 * @param WC_Order                                                                                                      $order  Order being charged.
	 * @param array{order_id:int,customers:array<int,string>,failed_at:int,last_failed_at:int,cannot_check_noted:bool}|null $record The order's record, if any.
	 * @return array{customers:array<int,string>,failed_at:int,last_failed_at:int}
	 */
	private function get_lookup_record( WC_Order $order, ?array $record ): array {
		$kept_at = (int) $order->get_meta( self::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META, true );
		$created = $order->get_date_created();
		$lookup  = array(
			'customers'      => array_values( array_filter( $record['customers'] ?? array(), static fn( string $customer ): bool => '' !== $customer ) ),
			'failed_at'      => 0 < $kept_at ? $kept_at : ( null === $created ? 0 : $created->getTimestamp() ),
			'last_failed_at' => time(),
		);
		if ( null === $record ) {
			$order->update_meta_data( self::CHARGE_AMBIGUITY_META, array( 'order_id' => $order->get_id() ) + $lookup + array( 'cannot_check_noted' => false ) );
			$order->save_meta_data();
		}

		return $lookup;
	}

	/**
	 * Read the ambiguity record when it names this order.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array{order_id:int,customers:array<int,string>,failed_at:int,last_failed_at:int,cannot_check_noted:bool}|null
	 */
	private function read_charge_ambiguity_record( WC_Order $order ): ?array {
		$record = $order->get_meta( self::CHARGE_AMBIGUITY_META, true );
		if ( ! is_array( $record ) || (int) ( $record['order_id'] ?? 0 ) !== $order->get_id() ) {
			return null;
		}

		$customers = $record['customers'] ?? array();

		return array(
			'order_id'           => $order->get_id(),
			'customers'          => array_values( array_filter( is_array( $customers ) ? $customers : array(), 'is_string' ) ),
			'failed_at'          => (int) ( $record['failed_at'] ?? 0 ),
			'last_failed_at'     => (int) ( $record['last_failed_at'] ?? 0 ),
			'cannot_check_noted' => ! empty( $record['cannot_check_noted'] ),
		);
	}

	/**
	 * Record an ambiguous charge failure under the order's kept key.
	 *
	 * A later ambiguous answer under the same key merges into the order's record instead of replacing it: the earlier
	 * request may have taken the money under the first customer, so both stay in view, and a merchant already told the
	 * payment cannot be checked is not told again. The latest failure time moves on. A request sent with no customer is
	 * recorded as an empty entry.
	 *
	 * @param WC_Order $order       Order being charged.
	 * @param string   $customer_id Customer the request was sent with.
	 */
	private function record_charge_ambiguity( WC_Order $order, string $customer_id ): void {
		$now    = time();
		$record = $this->read_charge_ambiguity_record( $order ) ?? array(
			'order_id'           => $order->get_id(),
			'customers'          => array(),
			'failed_at'          => $now,
			'cannot_check_noted' => false,
		);

		$record['last_failed_at'] = max( $record['last_failed_at'] ?? 0, $now );
		if ( ! in_array( $customer_id, $record['customers'], true ) ) {
			$record['customers'][] = $customer_id;
		}

		$order->update_meta_data( self::CHARGE_AMBIGUITY_META, $record );
		$order->save_meta_data();
	}

	/**
	 * Settle what the earlier request under the kept key did, then answer this payment attempt.
	 *
	 * A found payment pays the order without charging the new payment method, or refuses the attempt when it is disputed.
	 * A lookup that found none retires the key and refuses the attempt without a charge. A lookup that failed or cannot
	 * check refuses it and keeps the key; one that cannot check also leaves the merchant one note.
	 *
	 * @param PaymentOperationContext                                             $context      Payment context.
	 * @param string                                                              $sent_key     Kept key Stripe refused.
	 * @param array{customers:array<int,string>,failed_at:int,last_failed_at:int} $record       The lookup from get_lookup_record().
	 * @return PaymentOutcome
	 */
	private function settle_earlier_charge( PaymentOperationContext $context, string $sent_key, array $record ): PaymentOutcome {
		$order  = $context->get_order();
		$lookup = $this->ambiguity_service->find_order_intents( $order, $record['customers'], $record['failed_at'], $record['last_failed_at'] );
		if ( WooPaymentsChargeAmbiguityService::LOOKUP_CANNOT_CHECK === $lookup['status'] ) {
			$this->ambiguity_service->log_lookup_cannot_check( $order, $sent_key );
			$this->add_charge_cannot_be_checked_note_once( $order );

			return $this->charge_lookup_failed_outcome();
		}

		if ( WooPaymentsChargeAmbiguityService::LOOKUP_DONE !== $lookup['status'] ) {
			$this->ambiguity_service->log_lookup_failed( $order, $sent_key );

			return $this->charge_lookup_failed_outcome();
		}

		$intents = $lookup['intents'];

		$paid_intent = WooPaymentsChargeAmbiguityService::find_intent_with_money( $intents );
		if ( null !== $paid_intent && WooPaymentsIntentCodec::is_disputed( $paid_intent ) ) {
			$intent_id = (string) ( $paid_intent['id'] ?? '' );
			$this->ambiguity_service->log_earlier_payment_disputed( $order, $sent_key, $intent_id );
			wc_get_container()->get( WooPaymentsDuplicatePaymentPreventionService::class )->add_disputed_intent_note_once( $order, $intent_id, true );

			return $this->earlier_payment_disputed_outcome();
		}

		if ( null !== $paid_intent ) {
			$this->ambiguity_service->log_earlier_payment_found( $order, $sent_key, (string) ( $paid_intent['id'] ?? '' ) );

			return $this->earlier_payment_outcome( $order, $paid_intent, $record['customers'][0] ?? '' );
		}

		$this->ambiguity_service->log_no_earlier_payment( $order, $sent_key, count( $intents ) );
		if ( array() !== $intents ) {
			$order->add_order_note( __( 'An earlier payment attempt for this order did not go through.', 'woocommerce' ) );
		}

		$this->retire_charge_idempotency_key( $order );

		return $this->earlier_payment_not_taken_outcome();
	}

	/**
	 * Build the outcome for an order the earlier request under the kept key paid.
	 *
	 * The intent is the earlier request's late response, so it is mapped from its own payment method and customer, never
	 * the new one, and no token is saved or attached for this attempt's payment method, which was not charged. The shopper
	 * goes to the order-received page with the "We prevented multiple payments" notice. An intent for another amount than
	 * the order total, or in another currency, fails the order with the duplicate-payment amount mismatch and keeps the
	 * key, as money moved.
	 *
	 * @param WC_Order            $order       Order being charged.
	 * @param array<string,mixed> $intent      The order's PaymentIntent that took the payment.
	 * @param string              $customer_id Customer the first request under the key was sent with, used when the
	 *                                         intent names none.
	 * @return PaymentOutcome
	 */
	private function earlier_payment_outcome( WC_Order $order, array $intent, string $customer_id ): PaymentOutcome {
		$amount_error = wc_get_container()->get( WooPaymentsDuplicatePaymentPreventionService::class )->get_amount_mismatch_error( $intent, $order, true );
		if ( $amount_error instanceof \WP_Error ) {
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				(string) ( $intent['id'] ?? '' ),
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => (string) $amount_error->get_error_code(),
					PaymentOutcome::DATA_ERROR_MESSAGE => $amount_error->get_error_message(),
					WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => $amount_error->get_error_message(),
					PaymentOutcome::DATA_NOTE          => $amount_error->get_error_message(),
				)
			);
		}

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			new WooPaymentsIntentMappingContext( $order->get_checkout_order_received_url(), '', $customer_id )
		);
		$data    = $outcome->get_data();

		$data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = add_query_arg(
			WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_SUCCESSFUL_INTENT,
			'yes',
			$order->get_checkout_order_received_url()
		);

		$order->add_order_note( __( "The earlier payment attempt for this order went through, so WooPayments did not take payment from the customer's new payment method.", 'woocommerce' ) );

		$outcome = new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			$data
		);

		return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false )->without_token_effects() );
	}

	/**
	 * Tell the merchant, once per ambiguity record, that the earlier payment attempt cannot be checked.
	 *
	 * Every attempt on the order is refused until a list can be read, so the merchant needs to check the payment in
	 * WooPayments. The record remembers the note, so a shopper retrying does not add one per attempt.
	 *
	 * @param WC_Order $order Order being charged.
	 */
	private function add_charge_cannot_be_checked_note_once( WC_Order $order ): void {
		$record = $this->read_charge_ambiguity_record( $order );
		if ( null === $record || $record['cannot_check_noted'] ) {
			return;
		}

		$order->add_order_note( __( "The earlier payment attempt for this order could not be checked, so WooPayments did not take payment from the customer's new payment method. Please check for this payment in WooPayments before the customer tries again.", 'woocommerce' ) );
		$record['cannot_check_noted'] = true;
		$order->update_meta_data( self::CHARGE_AMBIGUITY_META, $record );
		$order->save_meta_data();
	}

	/**
	 * Build the refusal for an attempt whose earlier request's payment is disputed.
	 *
	 * As the attached-intent guard refuses a disputed payment: the shopper is told the payment is under review, nothing is
	 * charged, and the order keeps its status, the key and the record, so every later attempt is refused the same way.
	 *
	 * @return PaymentOutcome
	 */
	private function earlier_payment_disputed_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => WooPaymentsDuplicatePaymentPreventionService::ERROR_DISPUTED_INTENT,
				WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => __( "This order's payment is under review. Please contact the store.", 'woocommerce' ),
				PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
			)
		);
	}

	/**
	 * Build the refusal for an attempt whose earlier request took no money.
	 *
	 * The key is already retired, so the next attempt charges under a fresh key. This attempt sends nothing more: its
	 * payment method went out with the refused request, and the platform does not accept it twice. The order keeps its
	 * status and the shopper is asked to place the order again.
	 *
	 * @return PaymentOutcome
	 */
	private function earlier_payment_not_taken_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => 'wcpay_earlier_charge_not_paid',
				WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY => __( 'Your payment did not go through. Please place your order again.', 'woocommerce' ),
				PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
			)
		);
	}

	/**
	 * Build the refusal for an attempt whose earlier request could not be looked up.
	 *
	 * The order keeps its status, the key and the record, and the shopper gets the generic retry notice; the failure is not
	 * definitive and not a decline, so the key stays and the failed-transaction limiter is not bumped. The refusal could not
	 * tell what the earlier request did, so it applies nothing to the order: the stored payment state stays for the next
	 * request to read.
	 *
	 * @return PaymentOutcome
	 */
	private function charge_lookup_failed_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => 'wcpay_charge_lookup_failed',
				PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
				PaymentOutcome::DATA_NO_ORDER_CHANGE       => true,
			)
		);
	}

	/**
	 * Tell whether the provider refused an idempotency key because the request differs from the one first sent with it.
	 *
	 * @param ApiException $exception Provider request failure.
	 * @return bool
	 */
	public static function is_idempotency_key_conflict( ApiException $exception ): bool {
		return 'idempotency_error' === $exception->get_error_type() || 'idempotency_error' === $exception->get_error_code();
	}

	/**
	 * Tell whether a definitive failure under an unresolved charge key leaves the earlier request unsettled.
	 *
	 * Only a card error proves that Stripe ran this request fresh, so that nothing was stored under the key and the earlier
	 * request took nothing. The platform's own refusals never reach Stripe: they come as WordPress REST errors with no
	 * Stripe error object, so they have no error type, while Stripe's answers always have one. Stripe answers a 429 or a
	 * parameter-validation 400 before its idempotency layer and stores neither (https://docs.stripe.com/error-low-level).
	 * A scheduled renewal stays out of the lookup, so there only a platform refusal keeps the key.
	 *
	 * @param WC_Order     $order                Order being charged.
	 * @param ApiException $exception            Definitive request failure.
	 * @param bool         $is_scheduled_renewal Whether the payment is a scheduled subscription renewal.
	 * @return bool
	 */
	private function is_unsettling_answer_under_unresolved_key( WC_Order $order, ApiException $exception, bool $is_scheduled_renewal ): bool {
		if ( null === $this->read_charge_ambiguity_record( $order ) ) {
			return false;
		}

		$error_type = $exception->get_error_type();

		return '' === $error_type || ( ! $is_scheduled_renewal && 'card_error' !== $error_type );
	}

	/**
	 * Warn that a scheduled renewal's unresolved charge key could not replay the earlier request, which is not looked up.
	 *
	 * The new attempt sent a different body, so it failed instead of replaying. A scheduled renewal is not looked up, so
	 * the refusal is a definitive failure: failed_charge_outcome() retires the key and the next attempt charges under a
	 * fresh one, as every client attempt does (`class-wc-payments-api-client.php:2690`). Any other payment runs
	 * settle_earlier_charge() instead. An ambiguous answer (a 409 or a server error) keeps the key, so it gets no warning.
	 * Written whatever the logging setting, since support needs it to reconcile the order if the earlier request charged.
	 *
	 * @param WC_Order $order           Order being charged.
	 * @param string   $idempotency_key Kept charge key that was refused.
	 */
	private function log_unresolved_charge_key_refused( WC_Order $order, string $idempotency_key ): void {
		$this->logger->log_always(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one. The payment is a scheduled renewal, which is not looked up, so the key is retired and the next payment attempt for this order charges under a fresh key, with no protection against a charge the earlier request may have made.',
				$idempotency_key,
				$order->get_id()
			),
			'warning',
			array(
				'order_id'        => $order->get_id(),
				'idempotency_key' => $idempotency_key,
			)
		);
	}

	/**
	 * Derive the idempotency key for the retry after a missing customer was recreated.
	 *
	 * The retry sends another customer, and a provider refuses a reused key with a different body.
	 *
	 * @param string $idempotency_key Key of the request that reported the missing customer.
	 * @return string
	 */
	private static function get_customer_recovery_idempotency_key( string $idempotency_key ): string {
		return $idempotency_key . ':customer-recovery';
	}

	/**
	 * Retire the order's charge idempotency key after a definitive outcome.
	 *
	 * Covers a PaymentIntent response; a definitive dispatch failure was already retired by failed_charge_outcome().
	 *
	 * @param WC_Order       $order   Order that was charged.
	 * @param PaymentOutcome $outcome Provider outcome applied by the lifecycle.
	 * @return void
	 *
	 * @since 11.2.0
	 */
	public function finalize_charge_idempotency_key( WC_Order $order, PaymentOutcome $outcome ): void {
		$plan = $outcome->get_effect_plan();
		$data = $outcome->get_data();
		if ( ! ( $plan instanceof WooPaymentsOrderEffectPlan && WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT === $plan->get_type() ) && empty( $data[ self::DEFINITIVE_CHARGE_FAILURE_DATA_KEY ] ) ) {
			return;
		}

		$this->retire_charge_idempotency_key( $order );
	}

	/**
	 * Delete the order's charge idempotency key and its ambiguity record, so the next attempt sends a fresh key.
	 *
	 * @param WC_Order $order Order that was charged.
	 */
	private function retire_charge_idempotency_key( WC_Order $order ): void {
		$order->delete_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META );
		$order->delete_meta_data( self::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META );
		$order->delete_meta_data( self::CHARGE_AMBIGUITY_META );
		$order->save_meta_data();
	}

	/**
	 * Add the pre-debit approval note when the intent reports a pending customer notification.
	 *
	 * India RBI mandate flows confirm off-session charges only after the cardholder
	 * approves a notification from their issuing bank; the intent's processing block
	 * then names the approval deadline. The note is the merchant's only durable
	 * explanation of why the order sits unpaid.
	 *
	 * @param WC_Order            $order  Order being charged.
	 * @param array<string,mixed> $result Provider PaymentIntent response.
	 */
	private function maybe_add_customer_notification_note( WC_Order $order, array $result ): void {
		$processing         = isset( $result['processing'] ) && is_array( $result['processing'] ) ? $result['processing'] : array();
		$approval_requested = $processing['card']['customer_notification']['approval_requested'] ?? false;
		$completes_at       = $processing['card']['customer_notification']['completes_at'] ?? null;
		if ( ! $approval_requested || ! is_numeric( $completes_at ) ) {
			return;
		}

		$attempt_date = wp_date( get_option( 'date_format', 'F j, Y' ), (int) $completes_at, wp_timezone() );
		$attempt_time = wp_date( get_option( 'time_format', 'g:i a' ), (int) $completes_at, wp_timezone() );

		$order->add_order_note(
			sprintf(
				/* translators: 1) date in date_format or 'F j, Y'; 2) time in time_format or 'g:i a' */
				__( 'The customer must authorize this payment via a notification sent to them by the bank which issued their card. The authorization must be completed before %1$s at %2$s, when the charge will be attempted.', 'woocommerce' ),
				$attempt_date,
				$attempt_time
			)
		);
	}

	/**
	 * Build the failed outcome of a charge, with the decline effects client 11.1.0's process_payment() catch gives the order
	 * (class-wc-payment-gateway-wcpay.php:1355-1400): a payment-failed note with the raw diagnostics and, when the charge
	 * outcome carried one, the card_declined seller message (:1365-1367); and, on a card error, the fraud meta box set to
	 * allow, since fraud checks passed (:1369-1373).
	 *
	 * A PaymentIntent dispatch failure decides the order's kept charge key. An ambiguous one keeps it and records the
	 * failure for a later lookup, merged into the order's record when one exists. A definitive one retires both, except
	 * while this order has a record: then a platform refusal keeps both, and so does any Stripe answer other than a card
	 * error unless the payment is a scheduled renewal, since such an answer says nothing about
	 * the earlier request under the key.
	 *
	 * A declined request's payment method and customer stay on the outcome, so the failed order keeps them, as client 11.1.0
	 * writes them before the charge (class-wc-payment-gateway-wcpay.php:1672-1677); Sift order tracking reads the payment method.
	 * A SetupIntent request's are passed by failed_setup_intent_outcome().
	 *
	 * @param WC_Order     $order                       Order object.
	 * @param ApiException $exception                   Transport exception.
	 * @param bool         $is_payment_intent_dispatch Whether the exception came from PaymentIntent dispatch.
	 * @param string       $customer_id                 Customer the PaymentIntent request was sent with.
	 * @param bool         $is_scheduled_renewal        Whether the payment is a scheduled subscription renewal.
	 * @param string       $payment_method_id           Payment method the PaymentIntent request was sent with, if any.
	 * @return PaymentOutcome
	 */
	private function failed_charge_outcome( WC_Order $order, ApiException $exception, bool $is_payment_intent_dispatch = false, string $customer_id = '', bool $is_scheduled_renewal = false, string $payment_method_id = '' ): PaymentOutcome {
		$outcome = WooPaymentsIntentCodec::failed_transport_outcome( PaymentProcessingService::OPERATION_CHARGE, $exception );
		$data    = $outcome->get_data();
		if ( $is_payment_intent_dispatch && $exception->has_ambiguous_outcome() ) {
			$this->record_charge_ambiguity( $order, $customer_id );
		} elseif ( $is_payment_intent_dispatch && ! $this->is_unsettling_answer_under_unresolved_key( $order, $exception, $is_scheduled_renewal ) ) {
			$data[ self::DEFINITIVE_CHARGE_FAILURE_DATA_KEY ] = true;
			// Retired now rather than only after the lifecycle: a local failure applying this outcome must not leave the
			// key for the next attempt, which would get the stored failure back.
			$this->retire_charge_idempotency_key( $order );
		}

		if ( $this->is_blocked_by_fraud_rules( $exception ) ) {
			return $this->fraud_blocked_charge_outcome( $order, $exception, $outcome, $data );
		}

		$note_candidates = $this->note_service->format_checkout_payment_failed_note_candidates(
			$order,
			$exception->getMessage(),
			$exception->get_merchant_message(),
			$exception->get_error_type(),
			$exception->get_error_code()
		);
		if ( array() !== $note_candidates ) {
			// Client 11.3.0 adds this note on every decline, with no check for one already on the order
			// (class-wc-payment-gateway-wcpay.php:1690-1691); its text names no payment, so it is added for each occurrence.
			$data[ PaymentOutcome::DATA_NOTE ]                = $note_candidates[0];
			$data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ]    = $note_candidates;
			$data[ PaymentOutcome::DATA_NOTE_PER_OCCURRENCE ] = true;
		}

		if ( 'card_error' === $exception->get_error_type() ) {
			$meta = isset( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) && is_array( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) ? $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] : array();

			$intent = $exception->get_error_data()['payment_intent'] ?? null;
			if ( is_array( $intent ) ) {
				$meta += WooPaymentsOrderEffects::failed_payment_method_details_meta( $intent );
			}
			$meta[ WooPaymentsPersistenceVocabulary::FRAUD_META_BOX_TYPE_META_KEY ] = WooPaymentsFraudMetaBoxType::ALLOW;
			$data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ]             = $meta;
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$outcome->get_provider_payment_id(),
			'',
			$payment_method_id,
			$customer_id,
			$data
		);
	}

	/**
	 * Build the failed outcome of a SetupIntent request, with the request's payment method and customer, as client 11.1.0
	 * writes both before the request (class-wc-payment-gateway-wcpay.php:1672-1677); Sift order tracking reads the payment method.
	 *
	 * The outcome's meta also carries the attempt's mode, which the lifecycle's failure save writes, since a card save keeps
	 * no charge key to ride on: client 11.3.0 writes the mode before the request (class-wc-payment-gateway-wcpay.php:1962-1964),
	 * so the takeover cleanup removes these values when another gateway pays the order.
	 *
	 * A subscription whose payment method is being changed keeps its own: the declined card never becomes its payment
	 * method, and its mode is left as it is.
	 *
	 * @param PaymentOperationContext $context      Payment context.
	 * @param ApiException            $exception    Transport exception.
	 * @param array<string,mixed>     $request_data SetupIntent request that was sent.
	 * @return PaymentOutcome
	 */
	private function failed_setup_intent_outcome( PaymentOperationContext $context, ApiException $exception, array $request_data ): PaymentOutcome {
		$is_change = ! empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] );

		$outcome = $this->failed_charge_outcome(
			$context->get_order(),
			$exception,
			false,
			$is_change ? '' : (string) ( $request_data['customer'] ?? '' ),
			false,
			$is_change ? '' : (string) ( $request_data['payment_method'] ?? '' )
		);
		if ( $is_change ) {
			return $outcome;
		}

		$data = $outcome->get_data();
		$meta = isset( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) && is_array( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) ? $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] : array();

		$meta[ WooPaymentsPersistenceVocabulary::MODE_META_KEY ]    = $this->account_service->get_order_mode();
		$data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] = $meta;

		return new PaymentOutcome( $outcome->get_status(), $outcome->get_provider_payment_id(), $outcome->get_redirect_url(), $outcome->get_payment_method_id(), $outcome->get_customer_id(), $data );
	}

	/**
	 * Replace raw diagnostics for a permanently unusable scheduled renewal payment method.
	 *
	 * @param PaymentOperationContext $context Charge context.
	 * @param PaymentOutcome          $outcome Charge outcome.
	 * @return PaymentOutcome
	 */
	private function normalize_unusable_scheduled_renewal_failure( PaymentOperationContext $context, PaymentOutcome $outcome ): PaymentOutcome {
		$provider_data = $context->get_provider_data();
		$data          = $outcome->get_data();
		$error_code    = isset( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) && is_scalar( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) ? (string) $data[ PaymentOutcome::DATA_ERROR_CODE ] : '';
		$error_message = isset( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] ) && is_scalar( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] ) ? (string) $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] : '';

		if (
			true !== ( $provider_data[ WooPaymentsProviderDataKey::SCHEDULED_SUBSCRIPTION_PAYMENT ] ?? false ) ||
			PaymentOutcome::STATUS_FAILED !== $outcome->get_status() ||
			! $this->is_unusable_saved_payment_method_failure( $error_code, $error_message )
		) {
			return $outcome;
		}

		$display_name                                  = isset( $provider_data[ WooPaymentsProviderDataKey::SAVED_PAYMENT_METHOD_DISPLAY_NAME ] ) && is_scalar( $provider_data[ WooPaymentsProviderDataKey::SAVED_PAYMENT_METHOD_DISPLAY_NAME ] ) ? (string) $provider_data[ WooPaymentsProviderDataKey::SAVED_PAYMENT_METHOD_DISPLAY_NAME ] : '';
		$note_candidates                               = $this->note_service->format_unusable_saved_payment_method_note_candidates( $context->get_order(), $display_name );
		$data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
		$data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
		$data[ PaymentOutcome::DATA_NOTE_TYPE ]        = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED;

		$normalized  = new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			$data
		);
		$effect_plan = $outcome->get_effect_plan();

		return null === $effect_plan ? $normalized : $normalized->with_effect_plan( $effect_plan );
	}

	/**
	 * Tell whether an error identifies a permanently unusable saved payment method.
	 *
	 * @param string $error_code    Provider error code.
	 * @param string $error_message Provider error message.
	 * @return bool
	 */
	private function is_unusable_saved_payment_method_failure( string $error_code, string $error_message ): bool {
		if ( 'payment_method_no_longer_available' === $error_code ) {
			return true;
		}

		foreach ( array( 'must save this PaymentMethod to a customer', 'No such PaymentMethod', 'detached from a Customer', 'may not be used again' ) as $phrase ) {
			if ( false !== stripos( $error_message, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the failed outcome for a charge blocked by fraud rules.
	 *
	 * Gives the order client 11.1.0's mark_order_blocked_for_fraud() effects (class-wc-payment-gateway-wcpay.php:1340-1353):
	 * the order keeps its status (the merchant decides whether to cancel), records the block state and the fired ruleset
	 * results, and gets the blocked-payment note.
	 *
	 * @param WC_Order            $order     Order object.
	 * @param ApiException        $exception Transport exception.
	 * @param PaymentOutcome      $outcome   Failed transport outcome.
	 * @param array<string,mixed> $data      Outcome data.
	 * @return PaymentOutcome
	 */
	private function fraud_blocked_charge_outcome( WC_Order $order, ApiException $exception, PaymentOutcome $outcome, array $data ): PaymentOutcome {
		$ruleset_results = array();
		if ( 'wcpay_blocked_by_fraud_rule' === $exception->get_error_code() ) {
			$error_data      = $exception->get_error_data();
			$ruleset_results = isset( $error_data['ruleset_results'] ) && is_array( $error_data['ruleset_results'] ) ? $error_data['ruleset_results'] : array();
		} else {
			// AVS blocks surface as a Stripe card error rather than a rule engine
			// outcome, so no ruleset results accompany them; the fired rule is known.
			$ruleset_results = array( 'avs_verification' => WooPaymentsFraudOutcome::BLOCK );
		}

		$meta = isset( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) && is_array( $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] ) ? $data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] : array();

		$meta[ WooPaymentsPersistenceVocabulary::FRAUD_OUTCOME_STATUS_META_KEY ] = WooPaymentsFraudOutcome::BLOCK;
		$meta[ WooPaymentsPersistenceVocabulary::FRAUD_META_BOX_TYPE_META_KEY ]  = WooPaymentsFraudMetaBoxType::BLOCK;
		$meta[ WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY ]     = WooPaymentsIntentStatus::CANCELED;
		if ( array() !== $ruleset_results ) {
			$meta[ WooPaymentsPersistenceVocabulary::FRAUD_RULESET_RESULTS_META_KEY ] = (string) wp_json_encode( $ruleset_results );
		}
		$data[ WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY ] = $meta;
		$data[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ]         = true;
		// Client 11.1.0 gw:1425 passes the fraud flag, so the postal-code hint is withheld and the platform message shows.
		// Its Blocks checkout path drops the flag (gw:1519-1531); this code passes it there too.
		$data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] = WooPaymentsErrorMessages::get_shopper_message(
			$exception->get_error_type(),
			$exception->get_error_code(),
			$exception->get_decline_code(),
			$exception->getMessage(),
			true
		);

		$note_candidates = $this->note_service->format_fraud_blocked_note_candidates( $order, $exception->get_payment_intent_id(), $ruleset_results );
		if ( array() !== $note_candidates ) {
			$data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
			$data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$outcome->get_provider_payment_id(),
			'',
			'',
			'',
			$data
		);
	}

	/**
	 * Tell whether a charge failure was blocked by fraud rules.
	 *
	 * @param ApiException $exception Transport exception.
	 * @return bool
	 */
	private function is_blocked_by_fraud_rules( ApiException $exception ): bool {
		if ( 'wcpay_blocked_by_fraud_rule' === $exception->get_error_code() ) {
			return true;
		}

		// The AVS mismatch is part of the advanced fraud protection, so an
		// incorrect_zip card error counts as a block while that rule is active.
		return 'card_error' === $exception->get_error_type()
			&& 'incorrect_zip' === $exception->get_error_code()
			&& $this->is_avs_verification_fraud_rule_enabled();
	}

	/**
	 * Tell whether the advanced fraud protection AVS verification rule is active.
	 *
	 * Asks the fraud ruleset service, which fetches the ruleset from the platform
	 * when none is cached: a cold cache must not turn an AVS block into an
	 * ordinary decline.
	 *
	 * @return bool
	 */
	private function is_avs_verification_fraud_rule_enabled(): bool {
		return $this->fraud_ruleset_service->is_fraud_rule_active( 'avs_verification' );
	}

	/**
	 * Fail a sub-minimum charge before the API call using the cached platform floor.
	 *
	 * As client 11.1.0's check before the request (class-wc-payment-gateway-wcpay.php:1785-1787): once the platform has
	 * reported a per-currency minimum, later attempts below it fail locally with the same shopper message instead of
	 * burning another provider round trip.
	 *
	 * @param WC_Order $order Order object.
	 * @throws ApiException When the order total is below the cached platform minimum.
	 */
	private function assert_total_meets_cached_platform_minimum( WC_Order $order ): void {
		$currency       = (string) $order->get_currency();
		$minimum_amount = WooPaymentsCurrencyUtils::get_cached_minimum_amount( $currency );
		if ( null === $minimum_amount ) {
			return;
		}

		$converted_amount = WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), $currency );
		if ( $minimum_amount <= $converted_amount ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is rendered store copy, consumed as structured application data.
		throw new ApiException(
			WooPaymentsErrorMessages::get_amount_too_small_message( $minimum_amount, $currency ),
			'amount_too_small',
			400,
			'',
			'',
			array(
				'minimum_amount' => $minimum_amount,
				'currency'       => $currency,
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Tell whether a $0 order saves a new payment method, the only case client 11.1.0 creates a SetupIntent for.
	 *
	 * Client 11.1.0 saves when the shopper did not pick a saved token and the payment is recurring or the shopper asked to
	 * save (class-payment-information.php:429-431, trait-wc-payment-gateway-wcpay-subscriptions.php:382-394).
	 *
	 * @param PaymentOperationContext $context      Payment context.
	 * @param bool                    $is_recurring Whether the payment is recurring.
	 * @return bool
	 */
	private function zero_amount_saves_payment_method( PaymentOperationContext $context, bool $is_recurring ): bool {
		if ( 0 < $this->get_saved_payment_token_id( $context ) ) {
			return false;
		}

		$payment_data  = $context->get_payment_data();
		$provider_data = $context->get_provider_data();

		// Client 11.1.0 counts a payment method change request as recurring (trait-wc-payments-subscriptions-utilities.php:53-66).
		return $is_recurring
			|| ! empty( $provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] )
			|| ! empty( $payment_data[ PaymentOperationContext::PAYMENT_DATA_SAVE_PAYMENT_METHOD ] );
	}

	/**
	 * Get the saved token the shopper picked, or 0 for a new payment method.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return int
	 */
	private function get_saved_payment_token_id( PaymentOperationContext $context ): int {
		$payment_data = $context->get_payment_data();
		$token        = isset( $payment_data[ PaymentOperationContext::PAYMENT_DATA_PAYMENT_TOKEN ] ) && is_scalar( $payment_data[ PaymentOperationContext::PAYMENT_DATA_PAYMENT_TOKEN ] ) ? (string) $payment_data[ PaymentOperationContext::PAYMENT_DATA_PAYMENT_TOKEN ] : '';

		return 'new' === $token ? 0 : absint( $token );
	}

	/**
	 * Set up a payment method without a charge, for a $0 order or a payment method change: create or confirm a setup intent
	 * through the WooPayments platform API, or confirm without an intent.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt.
	 * @return PaymentOutcome
	 * @throws ApiException When creating or replacing the customer, or reading a WooPay intent, fails.
	 */
	private function set_up_payment_method( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		$context            = $this->request_builder->with_saved_payment_token_method_type( $context );
		$order              = $context->get_order();
		$payment_credential = $this->request_builder->payment_credential_from_context( $context );
		$is_recurring       = $this->is_recurring_payment( $context );

		if ( '' === $payment_credential ) {
			return $this->missing_payment_credential_outcome();
		}

		$customer_id      = $this->get_customer_id_for_context( $context );
		$woopay_intent_id = $this->get_woopay_intent_id( $context );

		if ( empty( $woopay_intent_id ) && ! WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) && ! $this->zero_amount_saves_payment_method( $context, $is_recurring ) ) {
			// Client 11.1.0 confirms a $0 order without an intent unless it saves a new payment method (gw:1688).
			$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, '', '', $payment_credential, $customer_id );

			return $outcome->with_effect_plan(
				WooPaymentsOrderEffectPlan::for_zero_amount_without_intent(
					array( WooPaymentsPersistenceVocabulary::MODE_META_KEY => $this->account_service->get_order_mode() ),
					$this->get_saved_payment_token_id( $context )
				)
			);
		}

		if ( ! empty( $woopay_intent_id ) ) {
			$this->assert_valid_stripe_id( $woopay_intent_id );
			$result = $this->api_client->get_setup_intention( $woopay_intent_id );
			$this->assert_woopay_intent_belongs_to_order( $result, $order, false );
		} else {
			$request_data = $this->request_builder->setup_intent_request_data( $context, $payment_credential, $customer_id, $is_recurring );

			try {
				$result = $this->create_setup_intent( $request_data, $payment_credential, $idempotency_key );
			} catch ( ApiException $exception ) {
				if ( ! $this->is_missing_customer_exception( $exception ) ) {
					return $this->failed_setup_intent_outcome( $context, $exception, $request_data );
				}

				// Stated lock exception: the retried SetupIntent needs an existing customer.
				$customer_id              = $this->customer_service->replace_missing_customer_for_order( $order, (string) ( $request_data['customer'] ?? '' ) );
				$request_data['customer'] = $customer_id;
				// SetupIntent keys are not kept on the order, so only the key changes for the different body.
				try {
					$result = $this->create_setup_intent( $request_data, $payment_credential, self::get_customer_recovery_idempotency_key( $idempotency_key ) );
				} catch ( ApiException $retry_exception ) {
					return $this->failed_setup_intent_outcome( $context, $retry_exception, $request_data );
				}
			}
		}

		$confirmation_token = WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) ? $payment_credential : '';
		$outcome            = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$this->build_mapping_context( $result, $context, $payment_credential, $customer_id, 'si', $confirmation_token )
		);
		$plan               = WooPaymentsOrderEffectPlan::for_setup_intent(
			$result,
			$is_recurring,
			array(
				// Client 11.1.0 stores the order currency for setup intents (class-wc-payments-order-service.php:1361).
				WooPaymentsPersistenceVocabulary::INTENT_CURRENCY_META_KEY => (string) $order->get_currency(),
				WooPaymentsPersistenceVocabulary::MODE_META_KEY            => $this->account_service->get_order_mode(),
			)
		);

		return $outcome->with_effect_plan( $plan );
	}

	/**
	 * Get the intent WooPay already confirmed for this order, when the checkout came from WooPay.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return string
	 */
	private function get_woopay_intent_id( PaymentOperationContext $context ): string {
		$intent_id = $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID ] ?? '';

		return is_string( $intent_id ) ? $intent_id : '';
	}

	/**
	 * Refuse a malformed Stripe id before any request, as client 11.1.0's Request::validate_stripe_id() does
	 * (core/server/class-request.php:669).
	 *
	 * @param string $id Stripe object id.
	 * @return void
	 * @throws ApiException When the id is not a Stripe id.
	 */
	private function assert_valid_stripe_id( string $id ): void {
		if ( preg_match( Client::ROUTE_STRIPE_ID_PATTERN, $id ) ) {
			return;
		}

		throw new ApiException(
			esc_html(
				sprintf(
					/* translators: %s: a Stripe object id. */
					__( '%s is not a valid Stripe identifier', 'woocommerce' ),
					$id
				)
			),
			'wcpay_core_invalid_request_parameter_stripe_id'
		);
	}

	/**
	 * Refuse a WooPay intent whose metadata names another order, as client 11.1.0 does with Order_ID_Mismatch_Exception
	 * (class-wc-payment-gateway-wcpay.php:1806, :1939).
	 *
	 * @param array<string,mixed> $intent            Intent WooPay confirmed.
	 * @param WC_Order            $order             Order being paid.
	 * @param bool                $is_payment_intent Whether the intent is a PaymentIntent rather than a SetupIntent.
	 * @return void
	 * @throws ApiException When the intent was confirmed for another order.
	 */
	private function assert_woopay_intent_belongs_to_order( array $intent, WC_Order $order, bool $is_payment_intent ): void {
		$metadata             = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$intent_order_id_raw  = $metadata['order_id'] ?? '';
		$intent_meta_order_id = is_numeric( $intent_order_id_raw ) ? intval( $intent_order_id_raw ) : 0;
		if ( $intent_meta_order_id === $order->get_id() ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is structured data, worded and escaped as client 11.1.0's.
		if ( $is_payment_intent ) {
			throw new ApiException(
				sprintf(
					/* translators: %1$s: order id recorded on the WooPay intent, %2$s: order id being paid. We do not need to translate WooPayMeta. */
					esc_html( __( 'We\'re not able to process this payment. Please try again later. WooPayMeta: intent_meta_order_id: %1$s, order_id: %2$s', 'woocommerce' ) ),
					esc_attr( (string) $intent_meta_order_id ),
					esc_attr( (string) $order->get_id() )
				),
				'order_id_mismatch'
			);
		}

		throw new ApiException( __( 'We\'re not able to process this payment. Please try again later.', 'woocommerce' ), 'order_id_mismatch' );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Resolve the customer a charge or setup request is sent with.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return string
	 */
	private function get_customer_id_for_context( PaymentOperationContext $context ): string {
		if ( ! empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] ) ) {
			return $this->customer_service->get_or_create_customer_id_for_subscription_payment_method_change( $context->get_order() );
		}

		// A scheduled renewal charges the customer on the renewal order, with no refresh and nothing stored on the user, as
		// client 11.1.0 does (includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:421-424).
		$order_customer_id = self::is_scheduled_renewal( $context ) ? (string) $context->get_order()->get_meta( WooPaymentsPersistenceVocabulary::STRIPE_CUSTOMER_ID_META_KEY, true ) : '';
		if ( '' !== $order_customer_id ) {
			return $order_customer_id;
		}

		return $this->customer_service->get_or_create_customer_id_for_order( $context->get_order() );
	}

	/**
	 * Tell whether checkout context requires recurring credential persistence.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return bool
	 */
	private function is_recurring_payment( PaymentOperationContext $context ): bool {
		$provider_data = $context->get_provider_data();

		return ! empty( $provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT ] )
			|| SubscriptionMethodPolicy::is_recurring_order( $context->get_order() );
	}

	/**
	 * Dispatch setup-intent transport using the correct confirmation-token path.
	 *
	 * @param array<string,mixed> $request_data       Request data.
	 * @param string              $payment_credential Payment credential.
	 * @param string              $idempotency_key    Idempotency key.
	 * @return array<string,mixed>
	 * @throws ApiException When the provider request fails.
	 */
	private function create_setup_intent( array $request_data, string $payment_credential, string $idempotency_key ): array {
		return WooPaymentsIntentCodec::is_confirmation_token( $payment_credential )
			? $this->api_client->create_setup_intention( $request_data, $idempotency_key )
			: $this->api_client->create_and_confirm_setup_intention( $request_data, $idempotency_key );
	}

	/**
	 * Collect the order facts the intent codec needs to map the platform's answer.
	 *
	 * @param array<string,mixed>     $result               Provider intent response.
	 * @param PaymentOperationContext $context              Payment context.
	 * @param string                  $payment_credential   Submitted credential.
	 * @param string                  $fallback_customer_id Customer ID used for the request.
	 * @param string                  $intent_type          Provider intent type.
	 * @param string                  $confirmation_token   Confirmation token.
	 * @return WooPaymentsIntentMappingContext
	 */
	private function build_mapping_context(
		array $result,
		PaymentOperationContext $context,
		string $payment_credential,
		string $fallback_customer_id,
		string $intent_type = 'pi',
		string $confirmation_token = ''
	): WooPaymentsIntentMappingContext {
		$order                    = $context->get_order();
		$customer_action_redirect = '';
		$provider_redirect_url    = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $result ) );
		if ( WooPaymentsIntentCodec::requires_confirmation_redirect( $result, $provider_redirect_url ) ) {
			$customer_action_redirect = WooPaymentsIntentCodec::confirmation_redirect_for(
				$order->get_id(),
				isset( $result['client_secret'] ) ? (string) $result['client_secret'] : '',
				wp_create_nonce( 'wcpay_update_order_status_nonce_' . $order->get_id() ),
				$intent_type,
				$confirmation_token
			);
		}

		return new WooPaymentsIntentMappingContext(
			$order->get_checkout_order_received_url(),
			$payment_credential,
			$fallback_customer_id,
			$customer_action_redirect,
			$intent_type,
			$provider_redirect_url
		);
	}

	/**
	 * Tell why a status-change capture or cancel must not act on its intent, given the order read under its order payment
	 * lock.
	 *
	 * The operation is refused when the order no longer stores the intent it acts on, or now stores a settled intent
	 * status. Any other stored status is older than the platform's read, which the operation acts on, as client 11.3.0
	 * acts on its read (class-wc-payments-order-service.php:2327-2350, :2381-2418).
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order     Order read again under the order payment lock.
	 * @param string   $intent_id Intent the status change acts on.
	 * @return string One of the REFUSAL_* values, or an empty string when the operation may act on the intent.
	 */
	public static function get_status_change_refusal( WC_Order $order, string $intent_id ): string {
		if ( (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) !== $intent_id ) {
			return self::REFUSAL_INTENT_CHANGED;
		}

		return in_array( (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true ), self::SETTLED_INTENT_STATUSES, true ) ? self::REFUSAL_INTENT_SETTLED : '';
	}

	/**
	 * Refuse a status-change capture or cancel, before any platform request, when the order read under the operation's
	 * order payment lock no longer matches the intent the status change acts on: the one it read, else the stored one.
	 * A capture is also refused when that order no longer pays through WooPayments, so no money is taken for an order
	 * another payment method paid; a cancel takes no money, so it goes ahead.
	 *
	 * The refusal carries the intent status the order stores, so the failed operation's meta write leaves it as it is.
	 * The order payment lifecycle is read from the container where it is used, as the duplicate payment prevention
	 * service is (see init()).
	 *
	 * @param PaymentOperationContext $context   Capture or cancel context.
	 * @param string                  $operation `capture` or `cancel`.
	 * @return PaymentOutcome|null The refusal, or null when the operation goes on.
	 */
	private function refuse_status_change_on_a_changed_order( PaymentOperationContext $context, string $operation ): ?PaymentOutcome {
		$intent_id = $this->get_read_intent_id( $context );
		if ( '' === $intent_id ) {
			$stored_intent_id = $context->get_provider_data()[ self::PROVIDER_DATA_STORED_INTENT_ID ] ?? '';
			$intent_id        = is_string( $stored_intent_id ) ? $stored_intent_id : '';
		}
		if ( '' === $intent_id ) {
			return null;
		}

		$order       = $context->get_order();
		$fresh_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$refusal     = PaymentProcessingService::OPERATION_CAPTURE === $operation && ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $fresh_order->get_payment_method() )
			? self::REFUSAL_PAYMENT_METHOD_CHANGED
			: self::get_status_change_refusal( $fresh_order, $intent_id );
		if ( '' === $refusal ) {
			return null;
		}

		$reasons = array(
			self::REFUSAL_PAYMENT_METHOD_CHANGED => 'the order no longer pays through WooPayments',
			self::REFUSAL_INTENT_CHANGED         => 'the order stores another intent than the one the status change acts on',
			self::REFUSAL_INTENT_SETTLED         => 'the order stores a settled intent status',
		);
		$this->logger->log_always(
			sprintf(
				'The %1$s of order #%2$d on its status change was not sent: %3$s.',
				$operation,
				$order->get_id(),
				$reasons[ $refusal ]
			),
			'warning',
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => $intent_id,
				'refusal'   => $refusal,
			)
		);

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				self::DATA_STATUS_CHANGE_REFUSAL => $refusal,
				WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY => (string) $fresh_order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true ) ),
			)
		);
	}

	/**
	 * Refuse a capture or cancel whose intent ID is outside the intent form, before any request, as client 11.3.0's
	 * Capture_Intention and Cancel_Intention do (core/server/request/class-capture-intention.php:42-45,
	 * class-cancel-intention.php:40-43). The refusal keeps the intent status the order stores, as the status-change
	 * refusal does, and its line prints no value.
	 *
	 * @param PaymentOperationContext $context   Capture or cancel context.
	 * @param string                  $operation `capture` or `cancel`.
	 * @param string                  $intent_id The intent the operation acts on.
	 * @return PaymentOutcome|null The refusal, or null when the operation goes on.
	 */
	private function refuse_an_intent_id_outside_the_form( PaymentOperationContext $context, string $operation, string $intent_id ): ?PaymentOutcome {
		if ( 1 === preg_match( Client::ROUTE_STRIPE_ID_PATTERN, $intent_id ) ) {
			return null;
		}

		$order = $context->get_order();
		$this->logger->log_always(
			sprintf( 'The %1$s of order #%2$d was not sent: its intent ID is outside the intent form.', $operation, $order->get_id() ),
			'warning',
			array(
				'order_id'  => $order->get_id(),
				'operation' => $operation,
			)
		);
		$fresh_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE => 'wcpay_core_invalid_request_parameter_stripe_id',
				'operation'                     => $operation,
				WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY => (string) $fresh_order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true ) ),
			)
		);
	}

	/**
	 * Refuse a capture or cancel, before it is sent, when its intent is not the order's stored intent and the platform's
	 * record of that intent does not name the order by its ID, or cannot be read.
	 *
	 * The order screen's actions act on the transaction ID, which a merchant can type. Client 11.3.0 sends them with no
	 * read (class-wc-payment-gateway-wcpay.php:4333-4350, :4436-4448), so a transaction ID that names another order's
	 * authorization captures or releases that authorization. An intent equal to the stored intent, which the store wrote,
	 * is sent with no read. The refusal sends nothing, keeps the stored values and intent status, and carries a note and
	 * an error message; there is no fallback to the stored intent.
	 *
	 * @param PaymentOperationContext $context   Capture or cancel context.
	 * @param string                  $operation `capture` or `cancel`.
	 * @param string                  $intent_id The intent the operation acts on.
	 * @return PaymentOutcome|null The refusal, or null when the operation goes on.
	 */
	private function refuse_a_target_of_another_order( PaymentOperationContext $context, string $operation, string $intent_id ): ?PaymentOutcome {
		$order = $context->get_order();
		if ( (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) === $intent_id ) {
			return null;
		}

		$owner_order_id = null;
		try {
			$intent = $this->api_client->get_payment_intention( $intent_id );
			if ( WooPaymentsIntentCodec::intent_order_id_matches_order( $intent, $order ) ) {
				return null;
			}

			$metadata       = is_array( $intent['metadata'] ?? null ) ? $intent['metadata'] : array();
			$raw_order_id   = $metadata['order_id'] ?? '';
			$owner_order_id = is_numeric( $raw_order_id ) && 0 < (int) $raw_order_id ? (int) $raw_order_id : 0;
		} catch ( ApiException $exception ) {
			unset( $exception );
		}

		$loggable_intent_id = WooPaymentsLogger::get_loggable_provider_id( $intent_id );
		if ( null === $owner_order_id ) {
			$message = sprintf( 'The %1$s of order #%2$d was not sent: intent %3$s, which is not the stored intent, could not be read.', $operation, $order->get_id(), $loggable_intent_id );
		} elseif ( 0 === $owner_order_id ) {
			$message = sprintf( 'The %1$s of order #%2$d was not sent: intent %3$s, which is not the stored intent, names no order.', $operation, $order->get_id(), $loggable_intent_id );
		} else {
			$message = sprintf( 'The %1$s of order #%2$d was not sent: intent %3$s, which is not the stored intent, belongs to order #%4$d.', $operation, $order->get_id(), $loggable_intent_id, $owner_order_id );
		}
		$this->logger->log_always(
			$message,
			'warning',
			array(
				'order_id'        => $order->get_id(),
				'intent_id'       => $loggable_intent_id,
				'intent_order_id' => 0 < (int) $owner_order_id ? $owner_order_id : 'none',
				'operation'       => $operation,
				'reason'          => self::REASON_OPERATION_TARGET_OF_ANOTHER_ORDER,
			)
		);

		$refusal     = $this->note_service->format_operation_target_refusal( $order, $operation, $intent_id, $owner_order_id );
		$fresh_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => self::ERROR_OPERATION_TARGET_OF_ANOTHER_ORDER,
				PaymentOutcome::DATA_ERROR_MESSAGE => $refusal['message'],
				'operation'                        => $operation,
				PaymentOutcome::DATA_NOTE          => $refusal['note'],
				WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY => (string) $fresh_order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true ) ),
			)
		);
	}

	/**
	 * Get the intent a status-change capture or cancel read from the platform, if any.
	 *
	 * @param PaymentOperationContext $context Capture or cancel context.
	 * @return string
	 */
	private function get_read_intent_id( PaymentOperationContext $context ): string {
		$read_intent_id = $context->get_provider_data()[ self::PROVIDER_DATA_READ_INTENT_ID ] ?? '';

		return is_string( $read_intent_id ) ? $read_intent_id : '';
	}

	/**
	 * Get the intent a capture or cancel acts on: the one its status change read, else the order's stored intent its
	 * status change found, else the one the order retains, its transaction ID first, for the order screen's actions.
	 *
	 * A status change acts on the stored intent, as client 11.3.0 does (class-wc-payments-order-service.php:2330,
	 * :2386); its order screen actions act on the transaction ID (class-wc-payment-gateway-wcpay.php:4341, :4443).
	 *
	 * @param PaymentOperationContext $context   Capture or cancel context.
	 * @param string                  $operation 'capture' or 'cancel', named in the log.
	 * @return string
	 */
	private function get_operation_intent_id( PaymentOperationContext $context, string $operation ): string {
		$read_intent_id = $this->get_read_intent_id( $context );
		if ( '' !== $read_intent_id ) {
			return $read_intent_id;
		}

		$stored_intent_id = $context->get_provider_data()[ self::PROVIDER_DATA_STORED_INTENT_ID ] ?? '';
		$intent_id        = is_string( $stored_intent_id ) && '' !== $stored_intent_id ? $stored_intent_id : $this->get_order_intent_id( $context->get_order() );
		$this->log_operation_on_an_order_naming_two_intents( $context->get_order(), $operation, $intent_id );

		return $intent_id;
	}

	/**
	 * Log a capture or cancel that no platform read targeted, on an order that stores requires_capture while its transaction
	 * ID and stored intent name two different intents, whatever the logging setting: one of the two is not the authorization
	 * the operation acts on. A value that is not a WooPayments intent is printed as fixed text.
	 *
	 * @param WC_Order $order     Order the operation acts on.
	 * @param string   $operation 'capture' or 'cancel'.
	 * @param string   $intent_id The intent the operation acts on.
	 */
	private function log_operation_on_an_order_naming_two_intents( WC_Order $order, string $operation, string $intent_id ): void {
		$transaction_id   = (string) $order->get_transaction_id();
		$stored_intent_id = (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true );
		if ( '' === $transaction_id || '' === $stored_intent_id || $transaction_id === $stored_intent_id || WooPaymentsIntentStatus::REQUIRES_CAPTURE !== (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENTION_STATUS_META_KEY, true ) ) {
			return;
		}

		$target = WooPaymentsLogger::get_loggable_provider_id( $intent_id );
		$this->logger->log_always(
			sprintf(
				'The %1$s of order #%2$d acts on intent %3$s, while the order stores requires_capture with transaction ID %4$s and stored intent %5$s.',
				$operation,
				$order->get_id(),
				$target,
				WooPaymentsLogger::get_loggable_provider_id( $transaction_id ),
				WooPaymentsLogger::get_loggable_provider_id( $stored_intent_id )
			),
			'warning',
			array(
				'order_id'        => $order->get_id(),
				'intent_id'       => $target,
				'other_intent_id' => WooPaymentsLogger::get_loggable_provider_id( $intent_id === $transaction_id ? $stored_intent_id : $transaction_id ),
				'operation'       => $operation,
				'reason'          => self::REASON_OPERATION_ON_AN_ORDER_NAMING_TWO_INTENTS,
			)
		);
	}

	/**
	 * Get the provider intent ID retained on an order.
	 *
	 * @param WC_Order $order Order being processed.
	 * @return string
	 */
	private function get_order_intent_id( WC_Order $order ): string {
		$intent_id = (string) $order->get_transaction_id();

		return '' === $intent_id ? (string) $order->get_meta( WooPaymentsPersistenceVocabulary::INTENT_ID_META_KEY, true ) : $intent_id;
	}

	/**
	 * Tell whether an API exception reports the customer itself as missing: `resource_missing`, with the customer as the
	 * object the message names after "No such", as client 11.3.0 is_missing_customer_error()
	 * (class-wc-payment-gateway-wcpay.php:5918-5931).
	 *
	 * @param ApiException $exception API exception.
	 * @return bool
	 */
	private function is_missing_customer_exception( ApiException $exception ): bool {
		return 'resource_missing' === $exception->get_error_code()
			&& 1 === preg_match( '/No such (\w+)/', $exception->getMessage(), $missing )
			&& 'customer' === strtolower( $missing[1] );
	}

	/**
	 * Build the missing-payment-credential outcome.
	 *
	 * @return PaymentOutcome
	 */
	private function missing_payment_credential_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array( PaymentOutcome::DATA_ERROR_CODE => 'wcpay_missing_payment_credential' )
		);
	}

	/**
	 * Build an unavailable-gateway outcome.
	 *
	 * @param string $operation Provider operation.
	 * @return PaymentOutcome
	 */
	private function unavailable_outcome( string $operation ): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE => 'wcpay_gateway_unavailable',
				'operation'                     => $operation,
			)
		);
	}

	/**
	 * Build the metadata re-sent on capture.
	 *
	 * Client 11.1.0 sends the full order metadata again on every capture (class-wc-payment-gateway-wcpay.php:3977-3982), so
	 * the platform's copy reflects the order at capture time, not at authorization time.
	 *
	 * @param \WC_Order $order Order being captured.
	 * @return array<string,mixed>
	 */
	private function capture_metadata( \WC_Order $order ): array {
		return WooPaymentsIntentRequestBuilder::capture_metadata_from_order( $order );
	}

	/**
	 * Re-fetch an intent after a failed capture or cancel to learn its real state.
	 *
	 * Fetch errors are swallowed on purpose: the original capture or cancel error is the actionable one, as in
	 * client 11.1.0's capture_charge() and cancel_authorization() (class-wc-payment-gateway-wcpay.php:4022-4029, :4092-4098).
	 *
	 * @param string $intent_id Intent ID.
	 * @return array<string,mixed>|null The intent, or null when it cannot be fetched.
	 */
	private function refetch_intent_after_failure( string $intent_id ): ?array {
		try {
			return $this->api_client->get_payment_intention( $intent_id );
		} catch ( ApiException $fetch_exception ) {
			return null;
		}
	}

	/**
	 * Build the Level 3 data sent on capture.
	 *
	 * Amazon Pay captures skip Level 3, as in client 11.1.0 (class-wc-payment-gateway-wcpay.php:3984).
	 *
	 * @param \WC_Order $order Order being captured.
	 * @return array<string,mixed>
	 */
	private function capture_level3_data( \WC_Order $order ): array {
		if ( false !== strpos( (string) $order->get_payment_method(), WooPaymentsPaymentMethodType::AMAZON_PAY ) ) {
			return array();
		}

		return $this->level3_service->get_data_from_order( $order );
	}
}
