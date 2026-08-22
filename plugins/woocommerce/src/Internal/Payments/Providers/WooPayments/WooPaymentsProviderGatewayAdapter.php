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
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime        $legacy_runtime     Legacy runtime.
	 * @param WooPaymentsApiClient            $api_client         Native API client.
	 * @param WooPaymentsCustomerService      $customer_service   Customer service.
	 * @param WooPaymentsIntentRequestBuilder $request_builder    Request builder.
	 * @param WooPaymentsAccountService       $account_service    Account service.
	 * @param WooPaymentsOrderDataService     $order_data_service Order data service.
	 * @param WooPaymentsOrderNoteService     $note_service       Order note service.
	 */
	final public function init(
		WooPaymentsLegacyRuntime $legacy_runtime,
		WooPaymentsApiClient $api_client,
		WooPaymentsCustomerService $customer_service,
		WooPaymentsIntentRequestBuilder $request_builder,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderDataService $order_data_service,
		WooPaymentsOrderNoteService $note_service
	): void {
		$this->legacy_runtime     = $legacy_runtime;
		$this->api_client         = $api_client;
		$this->customer_service   = $customer_service;
		$this->request_builder    = $request_builder;
		$this->account_service    = $account_service;
		$this->order_data_service = $order_data_service;
		$this->note_service       = $note_service;
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
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		if ( $this->api_client->is_available() ) {
			try {
				return 0.0 < (float) $context->get_order()->get_total()
					? $this->charge_via_native_transport( $context, $idempotency_key )
					: $this->setup_intent_via_native_transport( $context, $idempotency_key );
			} catch ( WooPaymentsApiException $exception ) {
				return $this->failed_charge_outcome( $context->get_order(), $exception );
			}
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
	 * @param string         $idempotency_key Deterministic idempotency key.
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
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$intent_id = $this->get_order_intent_id( $order );
			if ( '' !== $intent_id ) {
				try {
					$result  = $this->api_client->capture_intention(
						$intent_id,
						$this->order_data_service->prepare_amount( $context->get_amount() ?? (float) $order->get_total(), (string) $order->get_currency() ),
						$this->capture_metadata( $order ),
						$this->capture_level3_data( $order )
					);
					$outcome = WooPaymentsIntentCodec::outcome_from_native_capture_result( $result, $intent_id );

					return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_capture( $result ) );
				} catch ( WooPaymentsApiException $exception ) {
					$outcome = WooPaymentsIntentCodec::failed_transport_outcome( 'capture', $exception, $intent_id );
					$result  = array(
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

		$result = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context ) {
				return $gateway->capture_charge( $context->get_order() );
			}
		);
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
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->api_client->is_available() ) {
			$intent_id = $this->get_order_intent_id( $order );
			if ( '' !== $intent_id ) {
				try {
					$result  = $this->api_client->cancel_intention( $intent_id );
					$outcome = WooPaymentsIntentCodec::outcome_from_cancel_result( $result );

					return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_cancel( $result ) );
				} catch ( WooPaymentsApiException $exception ) {
					return WooPaymentsIntentCodec::failed_transport_outcome( 'cancel', $exception, $intent_id );
				}
			}
		}

		$gateway = $this->legacy_runtime->get_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'cancel_authorization' ) ) ) {
			return $this->unavailable_outcome( 'cancel' );
		}

		$result = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context ) {
				return $gateway->cancel_authorization( $context->get_order() );
			}
		);

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
	 * @param string         $idempotency_key Deterministic idempotency key.
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

		$customer_id  = $this->customer_service->get_or_create_customer_id_for_order( $order );
		$request_data = $this->request_builder->charge_request_data( $context, $payment_credential, $customer_id, $is_recurring );

		try {
			$result = $this->api_client->create_and_confirm_payment_intention( $request_data, $idempotency_key );
		} catch ( WooPaymentsApiException $exception ) {
			if ( ! $this->is_missing_customer_exception( $exception ) ) {
				throw $exception;
			}

			$customer_id              = $this->customer_service->recreate_customer_for_order( $order );
			$request_data['customer'] = $customer_id;
			$result                   = $this->api_client->create_and_confirm_payment_intention( $request_data, $idempotency_key );
		}

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$this->native_mapping_context( $result, $context, $payment_credential, $customer_id )
		);

		return $outcome->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $result, $is_recurring ) );
	}

	/**
	 * Build a failed charge outcome carrying the plugin's decline order effects.
	 *
	 * Mirrors the plugin's process_payment catch block: the order receives a
	 * payment-failed note with the raw diagnostics (and the card_declined
	 * seller message when the charge outcome carried one), and a card error
	 * marks the fraud meta box allow because fraud checks passed.
	 *
	 * @param WC_Order                $order     Order object.
	 * @param WooPaymentsApiException $exception Transport exception.
	 * @return PaymentOutcome
	 */
	private function failed_charge_outcome( WC_Order $order, WooPaymentsApiException $exception ): PaymentOutcome {
		$outcome = WooPaymentsIntentCodec::failed_transport_outcome( 'charge', $exception );
		$data    = $outcome->get_data();

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
		// The plugin withholds field-level guidance from blocked shoppers so a
		// card tester is not told which check tripped; redact to the generic copy.
		$data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] = WooPaymentsErrorMessages::get_generic_message();

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
	 * Reads the same cached ruleset the plugin consults; a missing or malformed
	 * cache means the rule is treated as inactive.
	 *
	 * @return bool
	 */
	private function is_avs_verification_fraud_rule_enabled(): bool {
		$ruleset = get_transient( 'wcpay_fraud_protection_settings' );
		if ( ! is_array( $ruleset ) ) {
			return false;
		}

		foreach ( $ruleset as $rule ) {
			if ( is_array( $rule ) && 'avs_verification' === ( $rule['key'] ?? null ) ) {
				return true;
			}
		}

		return false;
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
	 * Create or confirm a zero-amount setup intent through native transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
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

		$customer_id  = $this->customer_service->get_or_create_customer_id_for_order( $order );
		$request_data = $this->request_builder->setup_intent_request_data( $context, $payment_credential, $customer_id, $is_recurring );

		try {
			$result = $this->create_setup_intent( $request_data, $payment_credential, $idempotency_key );
		} catch ( WooPaymentsApiException $exception ) {
			if ( ! $this->is_missing_customer_exception( $exception ) ) {
				throw $exception;
			}

			$customer_id              = $this->customer_service->recreate_customer_for_order( $order );
			$request_data['customer'] = $customer_id;
			$result                   = $this->create_setup_intent( $request_data, $payment_credential, $idempotency_key );
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
				'_wcpay_intent_currency' => (string) $order->get_currency(),
				'_wcpay_mode'            => $this->account_service->get_mode(),
			)
		);

		return $outcome->with_effect_plan( $plan );
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
					$this->account_service->get_mode(),
					$settlement_meta
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
		$is_renewal      = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
		$is_subscription = $is_renewal || ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) );

		return WooPaymentsIntentRequestBuilder::metadata_from_order(
			$order,
			$is_subscription ? 'recurring' : 'single',
			$is_renewal ? 'renewal' : ( $is_subscription ? 'initial' : 'no' )
		);
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
