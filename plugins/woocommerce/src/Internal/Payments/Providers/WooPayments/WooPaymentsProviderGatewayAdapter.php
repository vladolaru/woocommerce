<?php
/**
 * WooPaymentsProviderGatewayAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use WC_Order;

/**
 * Normalizes WooPayments gateway operations to native payment outcomes.
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
	 * Native WooPayments API client.
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
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService|null
	 */
	private ?WooPaymentsOrderDataService $order_data_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime         $legacy_runtime    WooPayments legacy runtime.
	 * @param WooPaymentsApiClient             $api_client        Native WooPayments API client.
	 * @param WooPaymentsCustomerService       $customer_service  WooPayments customer service.
	 * @param WooPaymentsTokenService          $token_service     WooPayments token service.
	 * @param WooPaymentsAccountService        $account_service   WooPayments account service.
	 * @param WooPaymentsOrderDataService|null $order_data_service WooPayments order data service.
	 */
	final public function init( WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsApiClient $api_client, WooPaymentsCustomerService $customer_service, WooPaymentsTokenService $token_service, WooPaymentsAccountService $account_service, ?WooPaymentsOrderDataService $order_data_service = null ): void {
		$this->legacy_runtime     = $legacy_runtime;
		$this->api_client         = $api_client;
		$this->customer_service   = $customer_service;
		$this->token_service      = $token_service;
		$this->account_service    = $account_service;
		$this->order_data_service = $order_data_service;
	}

	/**
	 * Tell whether the legacy bridge can currently process operations.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$gateway = $this->get_legacy_gateway();
		if ( ! is_object( $gateway ) ) {
			return false;
		}

		if ( is_callable( array( $gateway, 'is_available' ) ) ) {
			return (bool) $gateway->is_available();
		}

		return true;
	}

	/**
	 * Charge an order through the active WooPayments gateway.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->get_api_client()->is_available() ) {
			try {
				return 0.0 < (float) $order->get_total()
					? $this->charge_via_native_transport( $context, $idempotency_key )
					: $this->setup_intent_via_native_transport( $context, $idempotency_key );
			} catch ( WooPaymentsApiException $exception ) {
				return $this->failed_transport_outcome( 'charge', $exception );
			}
		}

		$gateway = $this->get_legacy_gateway();
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
			$context->get_order(),
			$context->get_payment_method_id()
		);
	}

	/**
	 * Refund an order through the active WooPayments gateway.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->get_api_client()->is_available() ) {
			$charge_id = (string) $order->get_meta( '_charge_id', true );
			if ( '' !== $charge_id ) {
				$payment_data = $context->get_payment_data();
				$amount       = isset( $payment_data['amount'] ) ? (float) $payment_data['amount'] : 0.0;
				$reason       = isset( $payment_data['reason'] ) ? (string) $payment_data['reason'] : '';

				try {
					$result = $this->get_api_client()->refund_charge(
						$charge_id,
						$this->get_order_data_service()->prepare_amount( $amount, (string) $order->get_currency() ),
						$reason,
						'woocommerce_native',
						$idempotency_key
					);

					return WooPaymentsIntentCodec::outcome_from_refund_result( $result, $context );
				} catch ( WooPaymentsApiException $exception ) {
					return $this->failed_transport_outcome( 'refund', $exception );
				}
			}
		}

		$gateway = $this->get_legacy_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_refund' ) ) ) {
			return $this->unavailable_outcome( 'refund' );
		}

		$payment_data = $context->get_payment_data();
		$amount       = isset( $payment_data['amount'] ) ? (float) $payment_data['amount'] : 0.0;
		$reason       = isset( $payment_data['reason'] ) ? (string) $payment_data['reason'] : '';
		$result       = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context, $amount, $reason ) {
				return $gateway->process_refund( $context->get_order_id(), $amount, $reason );
			}
		);

		return WooPaymentsIntentCodec::outcome_from_legacy_refund_result( $result );
	}

	/**
	 * Capture an authorized payment through the active WooPayments gateway.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->get_api_client()->is_available() ) {
			$intent_id = $this->get_order_intent_id( $order );
			if ( '' !== $intent_id ) {
				$capture_amount = $context->get_amount() ?? (float) $order->get_total();

				try {
					$result = $this->get_api_client()->capture_intention(
						$intent_id,
						$this->get_order_data_service()->prepare_amount( $capture_amount, (string) $order->get_currency() ),
						array()
					);

					WooPaymentsOrderEffects::maybe_add_capture_fee_breakdown_note( $order, $result, $this->get_order_data_service() );

					return WooPaymentsIntentCodec::outcome_from_capture_result(
						$result,
						$context,
						$this->get_account_service()->get_mode(),
						$this->get_account_service()->get_account_default_currency(),
						$this->get_order_data_service()
					);
				} catch ( WooPaymentsApiException $exception ) {
					return $this->failed_transport_outcome( 'capture', $exception, $context );
				}
			}
		}

		$gateway = $this->get_legacy_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'capture_charge' ) ) ) {
			return $this->unavailable_outcome( 'capture' );
		}

		$result = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context ) {
				return $gateway->capture_charge( $context->get_order() );
			}
		);

		return WooPaymentsIntentCodec::outcome_from_capture_result(
			is_array( $result ) ? $result : array(),
			$context,
			$this->get_account_service()->get_mode(),
			$this->get_account_service()->get_account_default_currency(),
			$this->get_order_data_service()
		);
	}

	/**
	 * Cancel an authorized payment through the active WooPayments gateway.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$order = $context->get_order();
		if ( $this->get_api_client()->is_available() ) {
			$intent_id = $this->get_order_intent_id( $order );
			if ( '' !== $intent_id ) {
				try {
					$result = $this->get_api_client()->cancel_intention( $intent_id );

					return WooPaymentsIntentCodec::outcome_from_cancel_result( $result );
				} catch ( WooPaymentsApiException $exception ) {
					return $this->failed_transport_outcome( 'cancel', $exception );
				}
			}
		}

		$gateway = $this->get_legacy_gateway();
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'cancel_authorization' ) ) ) {
			return $this->unavailable_outcome( 'cancel' );
		}

		$result = $this->with_idempotency_key(
			$idempotency_key,
			static function () use ( $gateway, $context ) {
				return $gateway->cancel_authorization( $context->get_order() );
			}
		);

		return WooPaymentsIntentCodec::outcome_from_cancel_result( is_array( $result ) ? $result : array() );
	}

	/**
	 * Get the PaymentIntent id stored on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_order_intent_id( WC_Order $order ): string {
		$intent_id = (string) $order->get_transaction_id();
		if ( '' === $intent_id ) {
			$intent_id = (string) $order->get_meta( '_intent_id', true );
		}

		return $intent_id;
	}

	/**
	 * Run a legacy gateway operation with a scoped WooPayments API idempotency key.
	 *
	 * @param string   $idempotency_key Deterministic idempotency key.
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
	 * Get the active legacy WooPayments gateway.
	 *
	 * @return object|null
	 */
	private function get_legacy_gateway(): ?object {
		return $this->legacy_runtime->get_gateway();
	}

	/**
	 * Get the native API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function get_api_client(): WooPaymentsApiClient {
		return $this->api_client;
	}

	/**
	 * Get the WooPayments customer service.
	 *
	 * @return WooPaymentsCustomerService
	 */
	private function get_customer_service(): WooPaymentsCustomerService {
		return $this->customer_service;
	}

	/**
	 * Get the WooPayments token service.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function get_token_service(): WooPaymentsTokenService {
		return $this->token_service;
	}

	/**
	 * Get the WooPayments account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		return $this->account_service;
	}

	/**
	 * Get the WooPayments order data service.
	 *
	 * @return WooPaymentsOrderDataService
	 */
	private function get_order_data_service(): WooPaymentsOrderDataService {
		if ( null === $this->order_data_service ) {
			$this->order_data_service = wc_get_container()->get( WooPaymentsOrderDataService::class );
		}

		return $this->order_data_service;
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
		$context            = $this->with_saved_payment_token_method_type( $context );
		$order              = $context->get_order();
		$payment_credential = WooPaymentsOrderEffects::payment_credential_from_context( $context, $this->get_token_service() );

		if ( '' === $payment_credential ) {
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_ERROR_CODE => 'wcpay_missing_payment_credential' )
			);
		}

		$customer_id  = $this->get_customer_service()->get_or_create_customer_id_for_order( $order );
		$request_data = WooPaymentsIntentCodec::charge_request_data(
			$context,
			$payment_credential,
			$customer_id,
			$this->is_recurring_payment( $order ),
			$this->get_account_service(),
			$this->get_order_data_service()
		);

		try {
			$result = $this->get_api_client()->create_and_confirm_payment_intention( $request_data, $idempotency_key );
		} catch ( WooPaymentsApiException $exception ) {
			if ( ! $this->is_missing_customer_exception( $exception ) ) {
				throw $exception;
			}

			$customer_id              = $this->get_customer_service()->recreate_customer_for_order( $order );
			$request_data['customer'] = $customer_id;
			$result                   = $this->get_api_client()->create_and_confirm_payment_intention( $request_data, $idempotency_key );
		}

		WooPaymentsOrderEffects::apply_payment_method_display_details( $order, $result );

		return $this->normalize_native_charge_result( $result, $context, $customer_id );
	}

	/**
	 * Add saved-token payment method type to provider data when one can be resolved.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return PaymentContext
	 */
	private function with_saved_payment_token_method_type( PaymentContext $context ): PaymentContext {
		$payment_data  = $context->get_payment_data();
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';
		if ( '' === $payment_token || 'new' === $payment_token ) {
			return $context;
		}

		$provider_data       = $context->get_provider_data();
		$payment_method_type = ! empty( $provider_data['scheduled_subscription_payment'] )
			? $this->get_token_service()->resolve_payment_method_type_from_order_token_id( $payment_token, $context->get_order() )
			: $this->get_token_service()->resolve_payment_method_type_from_token_id( $payment_token, $context->get_order()->get_user_id() );

		if ( '' === $payment_method_type ) {
			return $context;
		}

		$provider_data[ WooPaymentsIntentCodec::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ] = $payment_method_type;

		return new PaymentContext(
			$context->get_order(),
			$context->get_gateway_id(),
			$context->get_payment_method_id(),
			$context->get_payment_data(),
			$provider_data,
			$context->get_amount()
		);
	}

	/**
	 * Create or confirm a zero-amount setup intent through the native WooPayments transport.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 * @throws WooPaymentsApiException When the provider request fails.
	 */
	private function setup_intent_via_native_transport( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		$context            = $this->with_saved_payment_token_method_type( $context );
		$order              = $context->get_order();
		$payment_credential = WooPaymentsOrderEffects::payment_credential_from_context( $context, $this->get_token_service() );

		if ( '' === $payment_credential ) {
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_ERROR_CODE => 'wcpay_missing_payment_credential' )
			);
		}

		$customer_id  = $this->get_customer_service()->get_or_create_customer_id_for_order( $order );
		$request_data = WooPaymentsIntentCodec::setup_intent_request_data(
			$context,
			$payment_credential,
			$customer_id,
			$this->is_recurring_payment( $order ),
			$this->get_account_service()
		);

		try {
			$result = WooPaymentsIntentCodec::is_confirmation_token( $payment_credential )
				? $this->get_api_client()->create_setup_intention( $request_data, $idempotency_key )
				: $this->get_api_client()->create_and_confirm_setup_intention( $request_data, $idempotency_key );
		} catch ( WooPaymentsApiException $exception ) {
			if ( ! $this->is_missing_customer_exception( $exception ) ) {
				throw $exception;
			}

			$customer_id              = $this->get_customer_service()->recreate_customer_for_order( $order );
			$request_data['customer'] = $customer_id;
			$result                   = WooPaymentsIntentCodec::is_confirmation_token( $payment_credential )
				? $this->get_api_client()->create_setup_intention( $request_data, $idempotency_key )
				: $this->get_api_client()->create_and_confirm_setup_intention( $request_data, $idempotency_key );
		}

		return $this->normalize_native_setup_intent_result( $result, $context, $customer_id );
	}

	/**
	 * Normalize a native PaymentIntent response to a neutral payment outcome.
	 *
	 * @param array<string,mixed> $result              Native PaymentIntent response.
	 * @param PaymentContext      $context             Payment context.
	 * @param string              $fallback_customer_id Customer ID used in the request.
	 * @return PaymentOutcome
	 */
	private function normalize_native_charge_result( array $result, PaymentContext $context, string $fallback_customer_id ): PaymentOutcome {
		$status            = isset( $result['status'] ) ? (string) $result['status'] : '';
		$charge            = WooPaymentsOrderEffects::latest_charge( $result );
		$payment_method_id = WooPaymentsIntentCodec::result_payment_method_id( $result );
		if ( '' === $payment_method_id && isset( $charge['payment_method'] ) ) {
			$payment_method_id = (string) $charge['payment_method'];
		}
		if ( '' === $payment_method_id ) {
			$payment_method_id = WooPaymentsOrderEffects::payment_credential_from_context( $context, $this->get_token_service() );
		}
		$customer_id = isset( $result['customer'] ) ? (string) $result['customer'] : $fallback_customer_id;

		if ( WooPaymentsIntentCodec::is_authorized_native_intent_status( $status ) ) {
			WooPaymentsOrderEffects::maybe_attach_saved_payment_token_to_order( $context, $payment_method_id, $customer_id, $this->get_token_service() );
			$token_save_failure = WooPaymentsOrderEffects::maybe_save_new_card_token_to_order(
				$context,
				$payment_method_id,
				$customer_id,
				$this->get_token_service(),
				$this->is_recurring_payment( $context->get_order() ),
				$this->legacy_runtime->get_logger()
			);
			if ( null !== $token_save_failure ) {
				return $token_save_failure;
			}
		}

		return WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$context->get_order(),
			array(
				'payment_credential'   => WooPaymentsOrderEffects::payment_credential_from_context( $context, $this->get_token_service() ),
				'fallback_customer_id' => $fallback_customer_id,
				'account_mode'         => $this->get_account_service()->get_mode(),
				'completed_meta'       => 'succeeded' === $status ? WooPaymentsOrderEffects::completed_charge_meta(
					$result,
					$charge,
					$context->get_order(),
					$this->get_account_service()->get_account_default_currency(),
					$this->get_order_data_service()
				) : array(),
			)
		);
	}

	/**
	 * Normalize a native SetupIntent response to a neutral payment outcome.
	 *
	 * @param array<string,mixed> $result              Native SetupIntent response.
	 * @param PaymentContext      $context             Payment context.
	 * @param string              $fallback_customer_id Customer ID used in the request.
	 * @return PaymentOutcome
	 */
	private function normalize_native_setup_intent_result( array $result, PaymentContext $context, string $fallback_customer_id ): PaymentOutcome {
		$status             = isset( $result['status'] ) ? (string) $result['status'] : '';
		$setup_intent_id    = isset( $result['id'] ) ? (string) $result['id'] : '';
		$payment_method_id  = WooPaymentsIntentCodec::result_payment_method_id( $result );
		$customer_id        = isset( $result['customer'] ) ? (string) $result['customer'] : $fallback_customer_id;
		$payment_credential = WooPaymentsOrderEffects::payment_credential_from_context( $context, $this->get_token_service() );
		$confirmation_token = WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) ? $payment_credential : '';
		$meta               = array(
			'_wcpay_intent_currency' => (string) $context->get_order()->get_currency(),
			'_wcpay_mode'            => $this->get_account_service()->get_mode(),
		);

		if ( '' === $payment_method_id && ! WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) ) {
			$payment_method_id = $payment_credential;
		}

		if ( 'succeeded' === $status ) {
			WooPaymentsOrderEffects::maybe_attach_saved_payment_token_to_order( $context, $payment_method_id, $customer_id, $this->get_token_service() );
			$token_save_failure = WooPaymentsOrderEffects::maybe_save_new_card_token_to_order(
				$context,
				$payment_method_id,
				$customer_id,
				$this->get_token_service(),
				$this->is_recurring_payment( $context->get_order() ),
				$this->legacy_runtime->get_logger()
			);
			if ( null !== $token_save_failure ) {
				return $token_save_failure;
			}
		}

		WooPaymentsOrderEffects::persist_setup_intent_details( $context->get_order(), $setup_intent_id, $payment_method_id, $customer_id, $meta );

		return WooPaymentsIntentCodec::outcome_from_intention(
			$result,
			$context->get_order(),
			array(
				'intent_type'          => 'si',
				'payment_credential'   => $payment_credential,
				'fallback_customer_id' => $fallback_customer_id,
				'account_mode'         => $this->get_account_service()->get_mode(),
				'confirmation_token'   => $confirmation_token,
			)
		);
	}

	/**
	 * Tell whether this payment must persist a token for a recurring order.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_recurring_payment( WC_Order $order ): bool {
		$is_recurring = false;
		if ( function_exists( 'wcs_order_contains_subscription' ) ) {
			$is_recurring = (bool) wcs_order_contains_subscription( $order->get_id() );
		}

		if ( ! $is_recurring && function_exists( 'wcs_order_contains_renewal' ) ) {
			$is_recurring = (bool) wcs_order_contains_renewal( $order->get_id() );
		}

		/**
		 * Filters whether a native WooPayments payment requires saved-token persistence.
		 *
		 * @since 11.0.0
		 *
		 * @param bool     $is_recurring Whether the order requires saved-token persistence.
		 * @param WC_Order $order        Order object.
		 */
		return (bool) apply_filters( 'woocommerce_native_woopayments_is_recurring_payment', $is_recurring, $order );
	}

	/**
	 * Tell whether a transport exception represents a missing customer.
	 *
	 * @param WooPaymentsApiException $exception Native transport exception.
	 * @return bool
	 */
	private function is_missing_customer_exception( WooPaymentsApiException $exception ): bool {
		return 'resource_missing' === $exception->get_error_code()
			&& false !== strpos( strtolower( $exception->getMessage() ), 'customer' );
	}

	/**
	 * Build a failed outcome for unavailable legacy gateway calls.
	 *
	 * @param string $operation Operation name.
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
	 * Build a failed outcome from a transport exception.
	 *
	 * @param string                  $operation Operation name.
	 * @param WooPaymentsApiException $exception Native transport exception.
	 * @param PaymentContext|null     $context   Payment context.
	 * @return PaymentOutcome
	 */
	private function failed_transport_outcome( string $operation, WooPaymentsApiException $exception, ?PaymentContext $context = null ): PaymentOutcome {
		$order               = null !== $context ? $context->get_order() : null;
		$provider_payment_id = $order instanceof WC_Order ? $this->get_order_intent_id( $order ) : '';

		return WooPaymentsIntentCodec::failed_transport_outcome(
			$operation,
			$exception,
			$order,
			$provider_payment_id
		);
	}
}
