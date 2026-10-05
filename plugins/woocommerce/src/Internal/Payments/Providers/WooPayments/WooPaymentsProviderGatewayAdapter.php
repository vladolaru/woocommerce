<?php
/**
 * WooPaymentsProviderGatewayAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use WC_Order;

/**
 * Arbitrates WooPayments gateway transport and delegates provider mapping.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsProviderGatewayAdapter {

	/**
	 * Order meta key holding the native charge idempotency key.
	 *
	 * @var string
	 * @since 11.2.0
	 */
	public const CHARGE_IDEMPOTENCY_KEY_META = '_wcpay_charge_idempotency_key';

	/**
	 * Order meta key recording the ambiguous charge failure that kept the order's charge idempotency key.
	 *
	 * An array with the order ID, every customer a failed request under the key was sent with, the time of the first
	 * failure and whether the merchant was told the payment cannot be checked. It lives and dies with the kept key, and
	 * lets a refused resubmit look up what the failed requests did.
	 *
	 * @var string
	 * @since 11.2.0
	 */
	public const CHARGE_AMBIGUITY_META = '_wcpay_charge_ambiguity';

	/**
	 * Provider data key set when a capture runs because the order status changed to completed.
	 */
	public const PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE = 'capture_on_status_change';

	/**
	 * Outcome data key marking a definitive native charge failure.
	 *
	 * @var string
	 */
	private const DEFINITIVE_CHARGE_FAILURE_DATA_KEY = '_wcpay_definitive_charge_failure';

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * Native API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

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
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * WooPayments settings service.
	 *
	 * @var WooPaymentsSettingsService
	 */
	private WooPaymentsSettingsService $settings_service;

	/**
	 * Charge ambiguity service.
	 *
	 * @var WooPaymentsChargeAmbiguityService
	 */
	private WooPaymentsChargeAmbiguityService $ambiguity_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime          $legacy_runtime     Legacy runtime.
	 * @param WooPaymentsApiClient              $api_client         Native API client.
	 * @param WooPaymentsCustomerService        $customer_service   Customer service.
	 * @param WooPaymentsIntentRequestBuilder   $request_builder    Request builder.
	 * @param WooPaymentsAccountService         $account_service    Account service.
	 * @param WooPaymentsOrderDataService       $order_data_service Order data service.
	 * @param WooPaymentsOrderNoteService       $note_service       Order note service.
	 * @param WooPaymentsSettingsService        $settings_service   Settings service.
	 * @param WooPaymentsChargeAmbiguityService $ambiguity_service  Charge ambiguity service.
	 */
	final public function init(
		WooPaymentsLegacyRuntime $legacy_runtime,
		WooPaymentsApiClient $api_client,
		WooPaymentsCustomerService $customer_service,
		WooPaymentsIntentRequestBuilder $request_builder,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderDataService $order_data_service,
		WooPaymentsOrderNoteService $note_service,
		WooPaymentsSettingsService $settings_service,
		WooPaymentsChargeAmbiguityService $ambiguity_service
	): void {
		$this->legacy_runtime     = $legacy_runtime;
		$this->api_client         = $api_client;
		$this->customer_service   = $customer_service;
		$this->request_builder    = $request_builder;
		$this->account_service    = $account_service;
		$this->order_data_service = $order_data_service;
		$this->note_service       = $note_service;
		$this->settings_service   = $settings_service;
		$this->ambiguity_service  = $ambiguity_service;
	}

	/**
	 * Tell whether the legacy bridge can currently process operations.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$gateway = $this->legacy_runtime->get_gateway();
		if ( ! is_object( $gateway ) ) {
			return false;
		}

		return is_callable( array( $gateway, 'is_available' ) ) ? (bool) $gateway->is_available() : true;
	}

	/**
	 * Charge an order through the active WooPayments transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Key minted fresh for this payment attempt. A positive-amount charge keeps its
	 *                                        key on the order and sends the kept key on later attempts until a definitive
	 *                                        outcome retires it, so a retry after an ambiguous failure replays the request.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		if ( $this->api_client->is_available() ) {
			try {
				$outcome = 0.0 < (float) $context->get_order()->get_total()
					? $this->charge_via_native_transport( $context, $idempotency_key )
					: $this->setup_intent_via_native_transport( $context, $idempotency_key );
			} catch ( WooPaymentsApiException $exception ) {
				$outcome = $this->failed_charge_outcome( $context->get_order(), $exception );
			}

			return $this->normalize_unusable_scheduled_renewal_failure( $context, $outcome );
		}

		$gateway = $this->legacy_runtime->get_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_payment' ) ) ) {
			return $this->unavailable_outcome( 'charge' );
		}

		$result = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context ) {
				return $gateway->process_payment( $context->get_order_id() );
			}
		);

		return WooPaymentsIntentCodec::outcome_from_legacy_result(
			is_array( $result ) ? $result : null,
			$this->legacy_mapping_context( $context->get_order() ),
			$context->get_payment_method_id()
		);
	}

	/**
	 * Refund an order through the active WooPayments transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Key minted fresh for this refund call.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$charge_id = (string) $order->get_meta( '_charge_id', true );
			if ( '' !== $charge_id ) {
				$payment_data = $context->get_payment_data();

				try {
					$result  = $this->api_client->refund_charge(
						$charge_id,
						$this->order_data_service->prepare_amount( (float) ( $payment_data['amount'] ?? 0.0 ), (string) $order->get_currency() ),
						(string) ( $payment_data['reason'] ?? '' ),
						'woocommerce_native',
						$idempotency_key
					);
					$outcome = WooPaymentsIntentCodec::outcome_from_refund_result( $result );

					return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_refund( $result ) );
				} catch ( WooPaymentsApiException $exception ) {
					return WooPaymentsIntentCodec::failed_transport_outcome( 'refund', $exception );
				}
			}
		}

		$gateway = $this->legacy_runtime->get_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_refund' ) ) ) {
			return $this->unavailable_outcome( 'refund' );
		}

		$payment_data = $context->get_payment_data();
		$result       = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context, $payment_data ) {
				return $gateway->process_refund(
					$context->get_order_id(),
					(float) ( $payment_data['amount'] ?? 0.0 ),
					(string) ( $payment_data['reason'] ?? '' )
				);
			}
		);

		return WooPaymentsIntentCodec::outcome_from_legacy_refund_result( $result );
	}

	/**
	 * Capture an authorized payment through the active WooPayments transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Operation lock key; not sent to the provider.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		// Like the client, each capture request carries its own key, so a merchant's retry after a
		// failed capture reaches the provider instead of replaying the stored failure.
		unset( $idempotency_key );

		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$intent_id = $this->get_order_intent_id( $order );
			if ( '' !== $intent_id ) {
				$amount_to_capture = $this->order_data_service->prepare_amount( $context->get_amount() ?? (float) $order->get_total(), (string) $order->get_currency() );
				// Client 11.1.0 always captures the order total (`class-wc-payment-gateway-wcpay.php:3966, 3981`) and sends the
				// order's Level 3 data with it (:3984-3986), so it never meets a partial capture. The order's line items add up to
				// the order total only, so a native partial capture goes without Level 3 data (native decision, audit L2, N-315).
				$is_order_total = $this->order_data_service->prepare_amount( (float) $order->get_total(), (string) $order->get_currency() ) === $amount_to_capture;
				try {
					$result  = $this->api_client->capture_intention(
						$intent_id,
						$amount_to_capture,
						$this->capture_metadata( $order ),
						$is_order_total ? $this->capture_level3_data( $order ) : array()
					);
					$outcome = WooPaymentsIntentCodec::outcome_from_native_capture_result( $result, $intent_id );
					$plan    = WooPaymentsOrderEffectPlan::for_capture( $result );

					return $outcome->with_effect_plan( empty( $context->get_provider_data()[ self::PROVIDER_DATA_CAPTURE_ON_STATUS_CHANGE ] ) ? $plan : $plan->without_fee_meta() );
				} catch ( WooPaymentsApiException $exception ) {
					$outcome = WooPaymentsIntentCodec::failed_transport_outcome( 'capture', $exception, $intent_id );

					// The site may have missed the charge.expired webhook, so a failed capture
					// re-fetches the intent: a canceled intent means the authorization expired,
					// and the effect plan carries it so the expired note and failed status land.
					$expired_intent = $this->refetch_intent_after_failure( $intent_id );
					if ( null !== $expired_intent && 'canceled' === (string) ( $expired_intent['status'] ?? '' ) ) {
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

		$gateway = $this->legacy_runtime->get_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'capture_charge' ) ) ) {
			return $this->unavailable_outcome( 'capture' );
		}

		$result = $gateway->capture_charge( $context->get_order() );
		$result = is_array( $result ) ? $result : array();
		$order  = $this->reload_order( $order );

		return WooPaymentsIntentCodec::outcome_from_capture_result(
			$result,
			$this->get_order_intent_id( $order ),
			$this->legacy_capture_effect_data( $result, $order )
		);
	}

	/**
	 * Cancel an authorized payment through the active WooPayments transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Operation lock key; not sent to the provider.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		// Each cancel request carries its own key, as for capture.
		unset( $idempotency_key );

		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$intent_id = $this->get_order_intent_id( $order );
			if ( '' !== $intent_id ) {
				try {
					$result  = $this->api_client->cancel_intention( $intent_id );
					$outcome = WooPaymentsIntentCodec::outcome_from_cancel_result( $result );

					return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_cancel( $result ) );
				} catch ( WooPaymentsApiException $exception ) {
					// The provider may have canceled the intent already (an expired authorization
					// whose charge.expired webhook was missed, or a cancel that completed despite
					// the transport error): a re-fetched canceled intent is a completed cancel,
					// as in the plugin's cancel_authorization().
					$intent = $this->refetch_intent_after_failure( $intent_id );
					if ( null !== $intent && 'canceled' === (string) ( $intent['status'] ?? '' ) ) {
						return WooPaymentsIntentCodec::outcome_from_cancel_result( $intent )
							->with_effect_plan( WooPaymentsOrderEffectPlan::for_cancel( $intent ) );
					}

					$outcome = WooPaymentsIntentCodec::failed_transport_outcome( 'cancel', $exception, $intent_id );

					// Not canceled: the plan carries the status the provider still reports so
					// the applier records it with the failure note, as the plugin does.
					return null !== $intent
						? $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_cancel( $intent ) )
						: $outcome;
				}
			}
		}

		$gateway = $this->legacy_runtime->get_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'cancel_authorization' ) ) ) {
			return $this->unavailable_outcome( 'cancel' );
		}

		$result  = $gateway->cancel_authorization( $context->get_order() );
		$result  = is_array( $result ) ? $result : array();
		$outcome = WooPaymentsIntentCodec::outcome_from_cancel_result( $result );

		return PaymentOutcome::STATUS_CANCELED === $outcome->get_status()
			? $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_cancel( $result ) )
			: $outcome;
	}

	/**
	 * Charge an order through the native WooPayments transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Key minted fresh for this payment attempt. A charge key already stored on
	 *                                        the order is sent instead until a definitive outcome retires it.
	 * @return PaymentOutcome
	 * @throws WooPaymentsApiException When the provider request fails.
	 */
	private function charge_via_native_transport( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$context            = $this->request_builder->with_saved_payment_token_method_type( $context );
		$order              = $context->get_order();
		$payment_credential = $this->request_builder->payment_credential_from_context( $context );
		$is_recurring       = $this->is_recurring_payment( $context );

		if ( '' === $payment_credential ) {
			return $this->missing_payment_credential_outcome();
		}

		$this->assert_total_meets_cached_platform_minimum( $order );

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
			$this->native_mapping_context( $result, $context, $payment_credential, $customer_id )
		);

		$this->maybe_add_customer_notification_note( $order, $result );

		return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $result, $is_recurring ) );
	}

	/**
	 * Resolve the durable idempotency key for a native charge.
	 *
	 * @param WC_Order $order     Order being charged.
	 * @param string   $candidate Current checkout invocation key.
	 * @return string
	 *
	 * @since 11.2.0
	 */
	private function resolve_charge_idempotency_key( WC_Order $order, string $candidate ): string {
		$persisted_key = (string) $order->get_meta( self::CHARGE_IDEMPOTENCY_KEY_META, true );
		$record        = $order->get_meta( self::CHARGE_AMBIGUITY_META, true );
		// A record naming another order means the key was copied from that order with it, so neither belongs here.
		$is_copied = is_array( $record ) && (int) ( $record['order_id'] ?? 0 ) !== $order->get_id();
		if ( '' !== $persisted_key && ! $is_copied ) {
			return $persisted_key;
		}

		$order->delete_meta_data( self::CHARGE_AMBIGUITY_META );
		$order->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META, $candidate );
		$order->save_meta_data();

		return $candidate;
	}

	/**
	 * Send a positive-amount charge under the order's kept key, or under the attempt key when none is kept.
	 *
	 * A kept key refused because the new request differs from the earlier one (a new card after a timeout) settles what
	 * the earlier request did before anything is charged; see settle_earlier_charge(). A missing customer is recreated and
	 * the charge retried, except while the order's ambiguity record exists.
	 *
	 * @param PaymentContext      $context      Payment context.
	 * @param array<string,mixed> $request_data Charge request; its customer is replaced when a missing customer is recreated.
	 * @param string              $attempt_key  Key minted fresh for this payment attempt.
	 * @return array<string,mixed>|PaymentOutcome The PaymentIntent response, or the outcome to return instead.
	 * @throws WooPaymentsApiException When recreating a missing customer fails.
	 */
	private function send_charge_request( PaymentContext $context, array &$request_data, string $attempt_key ) {
		$order    = $context->get_order();
		$sent_key = $this->resolve_charge_idempotency_key( $order, $attempt_key );

		try {
			return $this->api_client->create_and_confirm_payment_intention( $request_data, $sent_key );
		} catch ( WooPaymentsApiException $exception ) {
			if ( $this->is_kept_key_refusal_after_ambiguity( $order, $attempt_key, $sent_key, $exception, $context ) ) {
				return $this->settle_earlier_charge( $context, $request_data, $attempt_key, $sent_key );
			}

			if ( $attempt_key !== $sent_key && $this->is_idempotency_key_conflict( $exception ) && ! $this->api_client->is_ambiguous_request_failure( $exception ) ) {
				$this->log_kept_charge_key_refused( $order, $sent_key );
			}

			// While the record exists the recovery would charge under a key Stripe never saw, with no lookup of the earlier
			// request; the failure keeps the key and the record instead, so a later attempt reaches the lookup.
			if ( ! $this->is_missing_customer_exception( $exception ) || null !== $this->get_charge_ambiguity_record( $order ) ) {
				return $this->failed_charge_outcome( $order, $exception, true, (string) ( $request_data['customer'] ?? '' ), self::is_scheduled_renewal( $context ) );
			}
		}

		$customer_id              = $this->customer_service->recreate_customer_for_order( $order );
		$request_data['customer'] = $customer_id;
		// A different body under the same key would be refused, so the retry gets its own key, kept on the order so that an
		// ambiguous failure of the retry replays the retry.
		$recovery_key = self::get_customer_recovery_idempotency_key( $sent_key );
		$order->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META, $recovery_key );
		$order->save_meta_data();
		try {
			return $this->api_client->create_and_confirm_payment_intention( $request_data, $recovery_key );
		} catch ( WooPaymentsApiException $exception ) {
			return $this->failed_charge_outcome( $order, $exception, true, $customer_id, self::is_scheduled_renewal( $context ) );
		}
	}

	/**
	 * Tell whether Stripe refused the order's kept charge key after an ambiguous failure because the new request differs.
	 *
	 * All four must hold: a kept key was sent instead of the attempt's own; Stripe answered with an idempotency error, which
	 * it raises only when it holds a finished result for the key, and the answer is not ambiguous (a 409 or a server error
	 * means the earlier request may still be running, so its intent may not be listable yet); this order's ambiguity
	 * record exists; the payment is not a scheduled renewal (a renewal retry resends the same body, so Stripe replays the
	 * stored result instead).
	 *
	 * @param WC_Order                $order       Order being charged.
	 * @param string                  $attempt_key Key minted fresh for this payment attempt.
	 * @param string                  $sent_key    Key the request was sent with.
	 * @param WooPaymentsApiException $exception   Provider request failure.
	 * @param PaymentContext          $context     Payment context.
	 * @return bool
	 */
	private function is_kept_key_refusal_after_ambiguity( WC_Order $order, string $attempt_key, string $sent_key, WooPaymentsApiException $exception, PaymentContext $context ): bool {
		return $attempt_key !== $sent_key
			&& $this->is_idempotency_key_conflict( $exception )
			&& ! $this->api_client->is_ambiguous_request_failure( $exception )
			&& null !== $this->get_charge_ambiguity_record( $order )
			&& ! self::is_scheduled_renewal( $context );
	}

	/**
	 * Tell whether the payment is a scheduled subscription renewal.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return bool
	 */
	private static function is_scheduled_renewal( PaymentContext $context ): bool {
		return true === ( $context->get_provider_data()['scheduled_subscription_payment'] ?? false );
	}

	/**
	 * Get this order's record of the ambiguous charge failures that kept its charge key.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array{customers:array<int,string>,failed_at:int,last_failed_at:int}|null Null when the order has no record
	 *                                                                                  of its own, or the record names no
	 *                                                                                  customer to look up.
	 */
	private function get_charge_ambiguity_record( WC_Order $order ): ?array {
		$record = $this->read_charge_ambiguity_record( $order );
		if ( null === $record || array() === array_filter( $record['customers'], static fn( string $customer ): bool => '' !== $customer ) ) {
			return null;
		}

		return array(
			'customers'      => $record['customers'],
			'failed_at'      => $record['failed_at'],
			'last_failed_at' => $record['last_failed_at'],
		);
	}

	/**
	 * Read the ambiguity record when it names this order.
	 *
	 * A record written with a single `customer` is read as a list of one, and one written without `last_failed_at` as
	 * failing last at its first failure.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array{order_id:int,customers:array<int,string>,failed_at:int,last_failed_at:int,cannot_check_noted:bool}|null
	 */
	private function read_charge_ambiguity_record( WC_Order $order ): ?array {
		$record = $order->get_meta( self::CHARGE_AMBIGUITY_META, true );
		if ( ! is_array( $record ) || (int) ( $record['order_id'] ?? 0 ) !== $order->get_id() ) {
			return null;
		}

		$customers = $record['customers'] ?? array( $record['customer'] ?? '' );
		$failed_at = (int) ( $record['failed_at'] ?? 0 );

		return array(
			'order_id'           => $order->get_id(),
			'customers'          => array_values( array_filter( is_array( $customers ) ? $customers : array(), 'is_string' ) ),
			'failed_at'          => $failed_at,
			'last_failed_at'     => max( $failed_at, (int) ( $record['last_failed_at'] ?? 0 ) ),
			'cannot_check_noted' => ! empty( $record['cannot_check_noted'] ),
		);
	}

	/**
	 * Record an ambiguous charge failure under the order's kept key.
	 *
	 * A later ambiguous answer under the same key merges into the order's record instead of replacing it: the earlier
	 * request may have taken the money under the first customer, inside the window from the first failure, so both stay
	 * in view, and a merchant already told the payment cannot be checked is not told again. The latest failure time moves
	 * on, since the later request may be the one Stripe ran under the key, when the earlier one never reached it. A
	 * request sent with no customer is recorded as an empty entry.
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

		$record['last_failed_at'] = $now;
		if ( ! in_array( $customer_id, $record['customers'], true ) ) {
			$record['customers'][] = $customer_id;
		}

		$order->update_meta_data( self::CHARGE_AMBIGUITY_META, $record );
		$order->save_meta_data();
	}

	/**
	 * Settle what the earlier request under the kept key did, then answer this payment attempt.
	 *
	 * Runs under the checkout's order payment lock. The earlier request finished at Stripe, and its only money call creates
	 * a PaymentIntent carrying the order's id and key, so the order's intents decide: one that took the payment pays the
	 * order and the new payment method is not charged; none that did (or none at all) proves no money moved, so the key is
	 * retired and the new payment method is charged now; a failed lookup refuses this attempt and keeps the key and the
	 * record, so the next attempt looks again. When neither the customer's nor the account's intents can be listed, the
	 * attempt is refused the same way and the merchant gets one note asking them to check the payment.
	 *
	 * @param PaymentContext      $context      Payment context.
	 * @param array<string,mixed> $request_data Charge request.
	 * @param string              $attempt_key  Key minted fresh for this payment attempt.
	 * @param string              $sent_key     Kept key Stripe refused.
	 * @return array<string,mixed>|PaymentOutcome The new charge's PaymentIntent response, or the outcome to return instead.
	 * @throws WooPaymentsApiException When recreating a missing customer for the new charge fails.
	 */
	private function settle_earlier_charge( PaymentContext $context, array &$request_data, string $attempt_key, string $sent_key ) {
		$order  = $context->get_order();
		$record = $this->get_charge_ambiguity_record( $order ) ?? array(
			'customers'      => array( '' ),
			'failed_at'      => 0,
			'last_failed_at' => time(),
		);
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
		if ( null !== $paid_intent ) {
			$this->ambiguity_service->log_earlier_payment_found( $order, $sent_key, (string) ( $paid_intent['id'] ?? '' ) );

			return $this->earlier_payment_outcome( $order, $paid_intent, $record['customers'][0] );
		}

		$this->ambiguity_service->log_charging_after_no_earlier_payment( $order, $sent_key, count( $intents ) );
		if ( array() !== $intents ) {
			$order->add_order_note( __( 'An earlier payment attempt for this order did not go through.', 'woocommerce' ) );
		}

		$this->retire_charge_idempotency_key( $order );

		return $this->send_charge_request( $context, $request_data, $attempt_key );
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
					PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE => $amount_error->get_error_message(),
					PaymentOutcome::DATA_NOTE          => $amount_error->get_error_message(),
				)
			);
		}

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			WooPaymentsIntentMappingContext::for_native( $order->get_id(), $order->get_checkout_order_received_url(), '', $customer_id )
		);
		$data    = $outcome->get_data();

		$data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = add_query_arg(
			WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_SUCCESSFUL_INTENT,
			'yes',
			$order->get_checkout_order_received_url()
		);

		$order->add_order_note( __( "The earlier payment attempt for this order went through, so the customer's new payment was not taken.", 'woocommerce' ) );

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

		$order->add_order_note( __( "The earlier payment attempt for this order could not be checked, so the customer's new payment was not taken. Please check for this payment in WooPayments before the customer tries again.", 'woocommerce' ) );
		$record['cannot_check_noted'] = true;
		$order->update_meta_data( self::CHARGE_AMBIGUITY_META, $record );
		$order->save_meta_data();
	}

	/**
	 * Build the refusal for an attempt whose earlier request could not be looked up.
	 *
	 * The order keeps its status, the key and the record, and the shopper gets the generic retry notice; the failure is not
	 * definitive and not a decline, so the key stays and the failed-transaction limiter is not bumped.
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
			)
		);
	}

	/**
	 * Tell whether the provider refused an idempotency key because the request differs from the one first sent with it.
	 *
	 * @param WooPaymentsApiException $exception Provider request failure.
	 * @return bool
	 */
	private function is_idempotency_key_conflict( WooPaymentsApiException $exception ): bool {
		return 'idempotency_error' === $exception->get_error_type() || 'idempotency_error' === $exception->get_error_code();
	}

	/**
	 * Tell whether a definitive failure under a key kept after an ambiguous failure leaves the earlier request unsettled.
	 *
	 * Only a card error proves that Stripe ran this request fresh, so that nothing was stored under the key and the earlier
	 * request took nothing. The platform's own refusals never reach Stripe: they come as WordPress REST errors with no
	 * Stripe error object (wpcom `wcpay/core/exceptions/class-rest-exception.php:54-64`), so they have no error type,
	 * while Stripe's answers always have one (`wcpay/class-base-controller.php:476-490`). Stripe answers a 429 or a
	 * parameter-validation 400 before its idempotency layer and stores neither (https://docs.stripe.com/error-low-level).
	 * A scheduled renewal stays out of the lookup, so there only a platform refusal keeps the key.
	 *
	 * @param WC_Order                $order                Order being charged.
	 * @param WooPaymentsApiException $exception            Definitive request failure.
	 * @param bool                    $is_scheduled_renewal Whether the payment is a scheduled subscription renewal.
	 * @return bool
	 */
	private function is_unsettling_answer_under_kept_ambiguous_key( WC_Order $order, WooPaymentsApiException $exception, bool $is_scheduled_renewal ): bool {
		if ( null === $this->get_charge_ambiguity_record( $order ) ) {
			return false;
		}

		$error_type = $exception->get_error_type();

		return '' === $error_type || ( ! $is_scheduled_renewal && 'card_error' !== $error_type );
	}

	/**
	 * Warn that a kept charge key could not replay the earlier request and no lookup settles what that request did.
	 *
	 * The new attempt sent a different body (a new card, for example), so it failed instead of replaying. With no record
	 * of an ambiguous failure under the key (a request cut off mid-send), a record that names no customer to look up, or
	 * for a scheduled renewal, the refusal is a definitive failure: failed_charge_outcome() retires the key and the next
	 * attempt charges under a fresh one, as every client attempt does (`class-wc-payments-api-client.php:2690`). With a
	 * record, settle_earlier_charge() runs instead. An ambiguous answer (a 409 or a server error) keeps the key, so it
	 * gets no warning.
	 * Written whatever the logging setting, since support needs it to reconcile the order if the earlier request charged.
	 *
	 * @param WC_Order $order           Order being charged.
	 * @param string   $idempotency_key Kept charge key that was refused.
	 */
	private function log_kept_charge_key_refused( WC_Order $order, string $idempotency_key ): void {
		wc_get_container()->get( WooPaymentsLogger::class )->log_always(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one. The order has no record of an ambiguous failure under this key, the record names no customer to look up, or the payment is a scheduled renewal, so nothing looks up what the earlier request did: the key is retired and the next payment attempt for this order charges under a fresh key, with no protection against a charge the earlier request may have made.',
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
	 * Retire a native charge idempotency key after a definitive lifecycle outcome.
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
	 * Build a failed charge outcome carrying the plugin's decline order effects.
	 *
	 * Mirrors the plugin's process_payment catch block: the order receives a
	 * payment-failed note with the raw diagnostics (and the card_declined
	 * seller message when the charge outcome carried one), and a card error
	 * marks the fraud meta box allow because fraud checks passed.
	 *
	 * A PaymentIntent dispatch failure decides the order's kept charge key. An ambiguous one keeps it and records the
	 * failure for a later lookup, merged into the order's record when one exists. A definitive one retires both, except
	 * that while this order's record exists only a card error does: any other refusal (the platform's own, a Stripe rate
	 * limit, a validation error) says nothing about the earlier request under the key, so both stay for the next attempt's
	 * lookup.
	 *
	 * @param WC_Order                $order                       Order object.
	 * @param WooPaymentsApiException $exception                   Transport exception.
	 * @param bool                    $is_payment_intent_dispatch Whether the exception came from PaymentIntent dispatch.
	 * @param string                  $customer_id                 Customer the PaymentIntent request was sent with.
	 * @param bool                    $is_scheduled_renewal        Whether the payment is a scheduled subscription renewal.
	 * @return PaymentOutcome
	 */
	private function failed_charge_outcome( WC_Order $order, WooPaymentsApiException $exception, bool $is_payment_intent_dispatch = false, string $customer_id = '', bool $is_scheduled_renewal = false ): PaymentOutcome {
		$outcome = WooPaymentsIntentCodec::failed_transport_outcome( 'charge', $exception );
		$data    = $outcome->get_data();
		if ( $is_payment_intent_dispatch && $this->api_client->is_ambiguous_request_failure( $exception ) ) {
			$this->record_charge_ambiguity( $order, $customer_id );
		} elseif ( $is_payment_intent_dispatch && ! $this->is_unsettling_answer_under_kept_ambiguous_key( $order, $exception, $is_scheduled_renewal ) ) {
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
			$data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
			$data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;
		}

		if ( 'card_error' === $exception->get_error_type() ) {
			$meta = isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] ) ? $data[ PaymentOutcome::DATA_META ] : array();

			$meta['_wcpay_fraud_meta_box_type'] = 'allow';
			$data[ PaymentOutcome::DATA_META ]  = $meta;
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
	 * Replace raw diagnostics for a permanently unusable scheduled renewal payment method.
	 *
	 * @param PaymentContext $context Charge context.
	 * @param PaymentOutcome $outcome Charge outcome.
	 * @return PaymentOutcome
	 */
	private function normalize_unusable_scheduled_renewal_failure( PaymentContext $context, PaymentOutcome $outcome ): PaymentOutcome {
		$provider_data = $context->get_provider_data();
		$data          = $outcome->get_data();
		$error_code    = isset( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) && is_scalar( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) ? (string) $data[ PaymentOutcome::DATA_ERROR_CODE ] : '';
		$error_message = isset( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] ) && is_scalar( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] ) ? (string) $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] : '';

		if (
			true !== ( $provider_data['scheduled_subscription_payment'] ?? false ) ||
			PaymentOutcome::STATUS_FAILED !== $outcome->get_status() ||
			! $this->is_unusable_saved_payment_method_failure( $error_code, $error_message )
		) {
			return $outcome;
		}

		$display_name                                  = isset( $provider_data['saved_payment_method_display_name'] ) && is_scalar( $provider_data['saved_payment_method_display_name'] ) ? (string) $provider_data['saved_payment_method_display_name'] : '';
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
	 * Mirrors the plugin's mark_order_blocked_for_fraud effects: the order keeps
	 * its status (the merchant decides whether to cancel), records the block
	 * state and the fired ruleset results, and gets the blocked-payment note.
	 *
	 * @param WC_Order                $order     Order object.
	 * @param WooPaymentsApiException $exception Transport exception.
	 * @param PaymentOutcome          $outcome   Failed transport outcome.
	 * @param array<string,mixed>     $data      Outcome data.
	 * @return PaymentOutcome
	 */
	private function fraud_blocked_charge_outcome( WC_Order $order, WooPaymentsApiException $exception, PaymentOutcome $outcome, array $data ): PaymentOutcome {
		$ruleset_results = array();
		if ( 'wcpay_blocked_by_fraud_rule' === $exception->get_error_code() ) {
			$error_data      = $exception->get_error_data();
			$ruleset_results = isset( $error_data['ruleset_results'] ) && is_array( $error_data['ruleset_results'] ) ? $error_data['ruleset_results'] : array();
		} else {
			// AVS blocks surface as a Stripe card error rather than a rule engine
			// outcome, so no ruleset results accompany them; the fired rule is known.
			$ruleset_results = array( 'avs_verification' => 'block' );
		}

		$meta = isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] ) ? $data[ PaymentOutcome::DATA_META ] : array();

		$meta['_wcpay_fraud_outcome_status'] = 'block';
		$meta['_wcpay_fraud_meta_box_type']  = 'block';
		$meta['_intention_status']           = 'canceled';
		if ( array() !== $ruleset_results ) {
			$meta['_wcpay_fraud_ruleset_results'] = (string) wp_json_encode( $ruleset_results );
		}
		$data[ PaymentOutcome::DATA_META ]                  = $meta;
		$data[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] = true;
		// Client 11.1.0 gw:1426 passes the fraud flag, so the postal-code hint is withheld and the platform message shows.
		// Authorized divergence: verification-ledger.md V432 decision C23 (client Blocks gw:1519-1531 drops the flag; not ported).
		$data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] = WooPaymentsErrorMessages::get_shopper_message(
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
	 * @param WooPaymentsApiException $exception Transport exception.
	 * @return bool
	 */
	private function is_blocked_by_fraud_rules( WooPaymentsApiException $exception ): bool {
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
	 * Delegates to the settings service, which refreshes the cached ruleset from
	 * the platform when the local cache is missing — a cold cache must not turn
	 * an AVS block into an ordinary decline.
	 *
	 * @return bool
	 */
	private function is_avs_verification_fraud_rule_enabled(): bool {
		return $this->settings_service->is_fraud_rule_active( 'avs_verification' );
	}

	/**
	 * Fail a sub-minimum charge before the API call using the cached platform floor.
	 *
	 * Mirrors the plugin's pre-flight check: once the platform has reported a
	 * per-currency minimum, later attempts below it fail locally with the same
	 * shopper message instead of burning another provider round trip.
	 *
	 * @param WC_Order $order Order object.
	 * @throws WooPaymentsApiException When the order total is below the cached platform minimum.
	 */
	private function assert_total_meets_cached_platform_minimum( WC_Order $order ): void {
		$currency       = (string) $order->get_currency();
		$minimum_amount = WooPaymentsCurrencyUtils::get_cached_minimum_amount( $currency );
		if ( null === $minimum_amount ) {
			return;
		}

		$converted_amount = $this->order_data_service->prepare_amount( (float) $order->get_total(), $currency );
		if ( $minimum_amount <= $converted_amount ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is rendered store copy, consumed as structured application data.
		throw new WooPaymentsApiException(
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
	 * The client saves when the shopper did not pick a saved token and the payment is recurring or the shopper asked to
	 * save (`Payment_Information::should_save_payment_method_to_store()`, subtrait:382-394).
	 *
	 * @param PaymentContext $context      Payment context.
	 * @param bool           $is_recurring Whether the payment is recurring.
	 * @return bool
	 */
	private function zero_amount_saves_payment_method( PaymentContext $context, bool $is_recurring ): bool {
		if ( 0 < $this->get_saved_payment_token_id( $context ) ) {
			return false;
		}

		$payment_data  = $context->get_payment_data();
		$provider_data = $context->get_provider_data();

		// The client counts a payment method change request as recurring (trait-wc-payments-subscriptions-utilities.php:53-66).
		return $is_recurring
			|| ! empty( $provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] )
			|| ! empty( $payment_data['save_payment_method'] );
	}

	/**
	 * Get the saved token the shopper picked, or 0 for a new payment method.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return int
	 */
	private function get_saved_payment_token_id( PaymentContext $context ): int {
		$payment_data = $context->get_payment_data();
		$token        = isset( $payment_data['payment_token'] ) && is_scalar( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		return 'new' === $token ? 0 : absint( $token );
	}

	/**
	 * Create or confirm a zero-amount setup intent through native transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Key minted fresh for this payment attempt.
	 * @return PaymentOutcome
	 * @throws WooPaymentsApiException When the provider request fails.
	 */
	private function setup_intent_via_native_transport( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
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
					array( '_wcpay_mode' => $this->account_service->get_order_mode() ),
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
			} catch ( WooPaymentsApiException $exception ) {
				if ( ! $this->is_missing_customer_exception( $exception ) ) {
					throw $exception;
				}

				$customer_id              = $this->customer_service->recreate_customer_for_order( $order );
				$request_data['customer'] = $customer_id;
				// SetupIntent keys are not kept on the order, so only the key changes for the different body.
				$result = $this->create_setup_intent( $request_data, $payment_credential, self::get_customer_recovery_idempotency_key( $idempotency_key ) );
			}
		}

		$confirmation_token = WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) ? $payment_credential : '';
		$outcome            = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$this->native_mapping_context( $result, $context, $payment_credential, $customer_id, 'si', $confirmation_token )
		);
		$plan               = WooPaymentsOrderEffectPlan::for_setup_intent(
			$result,
			$is_recurring,
			array(
				// Plugin 11.1.0 stores the order currency for setup intents (class-wc-payments-order-service.php:1361).
				'_wcpay_intent_currency' => (string) $order->get_currency(),
				'_wcpay_mode'            => $this->account_service->get_order_mode(),
			)
		);

		return $outcome->with_effect_plan( $plan );
	}

	/**
	 * Get the intent WooPay already confirmed for this order, when the checkout came from WooPay.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return string
	 */
	private function get_woopay_intent_id( PaymentContext $context ): string {
		$intent_id = $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID ] ?? '';

		return is_string( $intent_id ) ? $intent_id : '';
	}

	/**
	 * Refuse a malformed Stripe id before any request, as the plugin's Request::validate_stripe_id() does.
	 *
	 * @param string $id Stripe object id.
	 * @return void
	 * @throws WooPaymentsApiException When the id is not a Stripe id.
	 */
	private function assert_valid_stripe_id( string $id ): void {
		if ( preg_match( '/^[a-z]+_\w{1,250}$/', $id ) ) {
			return;
		}

		throw new WooPaymentsApiException(
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
	 * Refuse a WooPay intent whose metadata names another order, as the plugin's Order_ID_Mismatch_Exception does.
	 *
	 * @param array<string,mixed> $intent            Intent WooPay confirmed.
	 * @param WC_Order            $order             Order being paid.
	 * @param bool                $is_payment_intent Whether the intent is a PaymentIntent rather than a SetupIntent.
	 * @return void
	 * @throws WooPaymentsApiException When the intent was confirmed for another order.
	 */
	private function assert_woopay_intent_belongs_to_order( array $intent, WC_Order $order, bool $is_payment_intent ): void {
		$metadata             = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$intent_order_id_raw  = $metadata['order_id'] ?? '';
		$intent_meta_order_id = is_numeric( $intent_order_id_raw ) ? intval( $intent_order_id_raw ) : 0;
		if ( $intent_meta_order_id === $order->get_id() ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is structured data, worded and escaped exactly as the plugin's.
		if ( $is_payment_intent ) {
			throw new WooPaymentsApiException(
				sprintf(
					/* translators: %1$s: order id recorded on the WooPay intent, %2$s: order id being paid. We do not need to translate WooPayMeta. */
					esc_html( __( 'We\'re not able to process this payment. Please try again later. WooPayMeta: intent_meta_order_id: %1$s, order_id: %2$s', 'woocommerce' ) ),
					esc_attr( (string) $intent_meta_order_id ),
					esc_attr( (string) $order->get_id() )
				),
				'order_id_mismatch'
			);
		}

		throw new WooPaymentsApiException( __( 'We\'re not able to process this payment. Please try again later.', 'woocommerce' ), 'order_id_mismatch' );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Resolve the customer required for a native provider request.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return string
	 */
	private function get_customer_id_for_context( PaymentContext $context ): string {
		if ( ! empty( $context->get_provider_data()[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] ) ) {
			return $this->customer_service->get_or_create_customer_id_for_subscription_payment_method_change( $context->get_order() );
		}

		return $this->customer_service->get_or_create_customer_id_for_order( $context->get_order() );
	}

	/**
	 * Tell whether checkout context requires recurring credential persistence.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return bool
	 */
	private function is_recurring_payment( PaymentContext $context ): bool {
		$provider_data = $context->get_provider_data();

		return ! empty( $provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT ] )
			|| $this->request_builder->is_recurring_payment( $context->get_order() );
	}

	/**
	 * Dispatch setup-intent transport using the correct confirmation-token path.
	 *
	 * @param array<string,mixed> $request_data       Request data.
	 * @param string              $payment_credential Payment credential.
	 * @param string              $idempotency_key    Idempotency key.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the provider request fails.
	 */
	private function create_setup_intent( array $request_data, string $payment_credential, string $idempotency_key ): array {
		return WooPaymentsIntentCodec::is_confirmation_token( $payment_credential )
			? $this->api_client->create_setup_intention( $request_data, $idempotency_key )
			: $this->api_client->create_and_confirm_setup_intention( $request_data, $idempotency_key );
	}

	/**
	 * Snapshot runtime facts needed by the pure native codec.
	 *
	 * @param array<string,mixed> $result               Provider intent response.
	 * @param PaymentContext      $context              Payment context.
	 * @param string              $payment_credential   Submitted credential.
	 * @param string              $fallback_customer_id Customer ID used for the request.
	 * @param string              $intent_type          Provider intent type.
	 * @param string              $confirmation_token   Confirmation token.
	 * @return WooPaymentsIntentMappingContext
	 */
	private function native_mapping_context(
		array $result,
		PaymentContext $context,
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
				wp_create_nonce( 'wcpay_update_order_status_nonce' ),
				$intent_type,
				$confirmation_token
			);
		}

		return WooPaymentsIntentMappingContext::for_native(
			$order->get_id(),
			$order->get_checkout_order_received_url(),
			$payment_credential,
			$fallback_customer_id,
			$customer_action_redirect,
			$intent_type,
			$provider_redirect_url
		);
	}

	/**
	 * Snapshot persisted facts after a legacy gateway operation.
	 *
	 * @param WC_Order $order Original order object.
	 * @return WooPaymentsIntentMappingContext
	 */
	private function legacy_mapping_context( WC_Order $order ): WooPaymentsIntentMappingContext {
		$order = $this->reload_order( $order );

		return WooPaymentsIntentMappingContext::for_legacy(
			$order->get_id(),
			$order->get_checkout_order_received_url(),
			(float) $order->get_total(),
			(string) $order->get_meta( '_intent_id', true ),
			(string) $order->get_meta( '_payment_method_id', true ),
			(string) $order->get_meta( '_intention_status', true )
		);
	}

	/**
	 * Compose legacy capture compatibility data after the bridge has completed.
	 *
	 * @param array<string,mixed> $result Legacy capture response.
	 * @param WC_Order            $order  Refreshed order snapshot.
	 * @return array<string,mixed>
	 */
	private function legacy_capture_effect_data( array $result, WC_Order $order ): array {
		$status    = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$intent_id = isset( $result['id'] ) ? (string) $result['id'] : $this->get_order_intent_id( $order );
		$charge    = WooPaymentsOrderEffects::latest_charge( $result );
		$charge_id = isset( $charge['id'] ) ? (string) $charge['id'] : (string) $order->get_meta( '_charge_id', true );

		if ( 'succeeded' === $status ) {
			$settlement_meta = empty( $charge )
				? array()
				: $this->order_data_service->get_settlement_exchange_rate_order_meta( $order, $charge, $this->account_service->get_account_default_currency() );

			$note_candidates = $this->note_service->format_capture_success_note_candidates(
				$order,
				$intent_id,
				$charge_id,
				WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null )
			);

			return array(
				PaymentOutcome::DATA_META             => WooPaymentsOrderEffects::completed_capture_meta(
					$result,
					(string) $order->get_currency(),
					$this->account_service->get_order_mode(),
					$settlement_meta,
					'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true )
				),
				PaymentOutcome::DATA_NOTE             => $note_candidates[0],
				PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS,
				PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
			);
		}

		$note_candidates = $this->note_service->format_capture_failed_note_candidates(
			$order,
			$intent_id,
			$charge_id,
			isset( $result['message'] ) ? (string) $result['message'] : ''
		);

		return array(
			PaymentOutcome::DATA_META             => WooPaymentsOrderEffects::failed_capture_meta(),
			PaymentOutcome::DATA_NOTE             => $note_candidates[0],
			PaymentOutcome::DATA_NOTE_TYPE        => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_FAILED,
			PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
		);
	}

	/**
	 * Run a legacy gateway operation with a scoped API idempotency key.
	 *
	 * @param string   $idempotency_key Idempotency key.
	 * @param callable $operation       Operation callback.
	 * @return mixed
	 */
	private function with_idempotency_key( string $idempotency_key, callable $operation ) {
		$idempotency_filter = static function ( $params, $api = '', $method = '' ) use ( $idempotency_key ) {
			unset( $api );

			if ( '' === $idempotency_key || ! is_array( $params ) || in_array( strtoupper( (string) $method ), array( 'GET', 'DELETE' ), true ) ) {
				return $params;
			}

			$params['idempotency_key'] = $idempotency_key;

			return $params;
		};

		add_filter( 'wcpay_api_request_params', $idempotency_filter, 10, 3 );

		try {
			return $operation();
		} finally {
			remove_filter( 'wcpay_api_request_params', $idempotency_filter, 10 );
		}
	}

	/**
	 * Get the provider intent ID retained on an order.
	 *
	 * @param WC_Order $order Order being processed.
	 * @return string
	 */
	private function get_order_intent_id( WC_Order $order ): string {
		$intent_id = (string) $order->get_transaction_id();

		return '' === $intent_id ? (string) $order->get_meta( '_intent_id', true ) : $intent_id;
	}

	/**
	 * Reload an order after a legacy gateway operation.
	 *
	 * @param WC_Order $order Original order object.
	 * @return WC_Order
	 */
	private function reload_order( WC_Order $order ): WC_Order {
		$fresh_order = wc_get_order( $order->get_id() );

		return $fresh_order instanceof WC_Order ? $fresh_order : $order;
	}

	/**
	 * Tell whether an API exception reports a missing customer.
	 *
	 * @param WooPaymentsApiException $exception API exception.
	 * @return bool
	 */
	private function is_missing_customer_exception( WooPaymentsApiException $exception ): bool {
		return 'resource_missing' === $exception->get_error_code()
			&& false !== strpos( strtolower( $exception->getMessage() ), 'customer' );
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
	 * The plugin re-sends the full order-derived metadata on every capture so
	 * the platform's copy reflects the order at capture time, not at
	 * authorization time.
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
	 * Fetch errors are swallowed on purpose: the original capture/cancel error is the
	 * actionable one, matching the plugin's capture_charge and cancel_authorization.
	 *
	 * @param string $intent_id Intent ID.
	 * @return array<string,mixed>|null The intent, or null when it cannot be fetched.
	 */
	private function refetch_intent_after_failure( string $intent_id ): ?array {
		try {
			return $this->api_client->get_payment_intention( $intent_id );
		} catch ( WooPaymentsApiException $fetch_exception ) {
			return null;
		}
	}

	/**
	 * Build the Level 3 data sent on capture.
	 *
	 * Amazon Pay captures skip Level 3, matching the plugin.
	 *
	 * @param \WC_Order $order Order being captured.
	 * @return array<string,mixed>
	 */
	private function capture_level3_data( \WC_Order $order ): array {
		if ( false !== strpos( (string) $order->get_payment_method(), 'amazon_pay' ) ) {
			return array();
		}

		return wc_get_container()->get( WooPaymentsLevel3Service::class )->get_data_from_order( $order );
	}
}
