<?php
/**
 * WooPaymentsCheckoutAjaxController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Native WooPayments checkout AJAX callbacks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCheckoutAjaxController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

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
	 * Fraud prevention service.
	 *
	 * @var WooPaymentsFraudPreventionService
	 */
	private WooPaymentsFraudPreventionService $fraud_prevention_service;

	/**
	 * Order lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

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
	 * WooPayments payment method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier|null
	 */
	private ?WooPaymentsOrderEffectApplier $order_effect_applier = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter          $arbiter                 Runtime owner arbiter.
	 * @param WooPaymentsApiClient                  $api_client              Native WooPayments API client.
	 * @param WooPaymentsCustomerService            $customer_service        WooPayments customer service.
	 * @param OrderPaymentLifecycleService          $lifecycle_service       Order lifecycle service.
	 * @param WooPaymentsTokenService               $token_service           WooPayments token service.
	 * @param WooPaymentsAccountService             $account_service         WooPayments account service.
	 * @param WooPaymentsOrderDataService|null      $order_data_service      Legacy compatibility dependency; no longer used.
	 * @param WooPaymentsPaymentMethodRegistry|null $payment_method_registry Optional payment method registry.
	 * @param WooPaymentsOrderEffectApplier|null    $order_effect_applier    Optional order effect applier.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		WooPaymentsApiClient $api_client,
		WooPaymentsCustomerService $customer_service,
		OrderPaymentLifecycleService $lifecycle_service,
		WooPaymentsTokenService $token_service,
		WooPaymentsAccountService $account_service,
		?WooPaymentsOrderDataService $order_data_service = null,
		?WooPaymentsPaymentMethodRegistry $payment_method_registry = null,
		?WooPaymentsOrderEffectApplier $order_effect_applier = null
	): void {
		$this->arbiter                 = $arbiter;
		$this->api_client              = $api_client;
		$this->customer_service        = $customer_service;
		$this->lifecycle_service       = $lifecycle_service;
		$this->token_service           = $token_service;
		$this->account_service         = $account_service;
		$this->payment_method_registry = $payment_method_registry ?? new WooPaymentsPaymentMethodRegistry();
		$this->order_effect_applier    = $order_effect_applier;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'wp_ajax_update_order_status', array( $this, 'handle_update_order_status' ) ) ) {
			add_action( 'wp_ajax_update_order_status', array( $this, 'handle_update_order_status' ) );
		}

		if ( false === has_action( 'wp_ajax_nopriv_update_order_status', array( $this, 'handle_update_order_status' ) ) ) {
			add_action( 'wp_ajax_nopriv_update_order_status', array( $this, 'handle_update_order_status' ) );
		}

		if ( false === has_action( 'wp_ajax_create_setup_intent', array( $this, 'handle_create_setup_intent' ) ) ) {
			add_action( 'wp_ajax_create_setup_intent', array( $this, 'handle_create_setup_intent' ) );
		}
	}

	/**
	 * Handle the WooPayments-compatible order-status update action.
	 */
	public function handle_update_order_status(): void {
		$response    = $this->get_update_order_status_response( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status_code = $this->extract_status_code( $response );

		wp_send_json( $response, $status_code );
	}

	/**
	 * Handle the WooPayments-compatible setup-intent creation action.
	 */
	public function handle_create_setup_intent(): void {
		$response    = $this->get_create_setup_intent_response( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status_code = $this->extract_status_code( $response );
		$data        = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		if ( ! empty( $response['success'] ) ) {
			wp_send_json_success( $data, $status_code );
		}

		wp_send_json_error( $data, $status_code );
	}

	/**
	 * Build the WooPayments-compatible order-status response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_update_order_status_response( array $request ): array {
		if ( ! $this->can_handle_callbacks() ) {
			return $this->error_response( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}

		if ( ! $this->is_nonce_valid( $request, 'wcpay_update_order_status_nonce' ) ) {
			return $this->error_response( __( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce' ), 403 );
		}

		$order_id = absint( $request['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return $this->error_response( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 404 );
		}

		$intent_id = $this->get_request_string( $request, 'intent_id' );
		if ( '' === $intent_id || $intent_id !== (string) $order->get_meta( '_intent_id', true ) ) {
			$order->add_order_note( __( 'WooPayments intent verification failed after customer authentication.', 'woocommerce' ) );
			return $this->error_response( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}

		if ( ! $this->current_user_can_act_on_order( $order ) ) {
			return $this->error_response( __( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce' ), 403 );
		}

		try {
			$is_subscription_payment_method_change = $this->is_subscription_change_payment_request( $request );
			$this->confirm_intent_for_order(
				$order,
				$intent_id,
				$this->should_save_payment_method( $request ) || $is_subscription_payment_method_change
			);

			if ( $is_subscription_payment_method_change ) {
				$this->maybe_update_subscription_payment_method( $order );
			}

			return array(
				'return_url'  => $is_subscription_payment_method_change ? $order->get_view_order_url() : $this->get_return_url( $order ),
				'status_code' => 200,
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $this->error_response( $exception->getMessage(), 502 );
		} catch ( WooPaymentsIntentConfirmationException $exception ) {
			return $this->error_response( $exception->getMessage(), $exception->getCode() );
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Error completing native WooPayments authenticated payment: ' . $exception->getMessage(),
				array( 'source' => 'payment-info' )
			);

			return $this->error_response( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 500 );
		}
	}

	/**
	 * Confirm an intent and apply its result to an order.
	 *
	 * @param WC_Order $order               Order being confirmed.
	 * @param string   $intent_id           PaymentIntent or SetupIntent ID.
	 * @param bool     $save_payment_method Whether to persist the payment method.
	 * @throws WooPaymentsApiException When intent retrieval fails.
	 * @throws WooPaymentsIntentConfirmationException When the intent cannot be authorized or a required token cannot be saved.
	 * @throws Throwable When lifecycle, token, or payment-method effects fail.
	 *
	 * @since 11.0.0
	 */
	public function confirm_intent_for_order( WC_Order $order, string $intent_id, bool $save_payment_method ): void {
		$intent = 0.0 >= (float) $order->get_total()
			? $this->api_client->get_setup_intention( $intent_id )
			: $this->api_client->get_payment_intention( $intent_id );

		$this->confirm_fetched_intent_for_order( $order, $intent, $save_payment_method );
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- The method explicitly throws its domain exception and can propagate downstream Throwables.
	/**
	 * Confirm an already-fetched intent and apply its result to an order.
	 *
	 * @param WC_Order            $order               Order being confirmed.
	 * @param array<string,mixed> $intent              PaymentIntent or SetupIntent response.
	 * @param bool                $save_payment_method Whether to persist the payment method.
	 * @throws WooPaymentsIntentConfirmationException When the intent cannot be authorized or a required token cannot be saved.
	 * @throws Throwable When confirmation is rejected or lifecycle, token, or payment-method effects fail.
	 *
	 * @since 11.0.0
	 */
	public function confirm_fetched_intent_for_order( WC_Order $order, array $intent, bool $save_payment_method ): void {
		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';

		if ( $this->is_authorized_intent_status( $status ) ) {
			$token_save_error = $this->maybe_save_payment_method_for_order(
				$order,
				$intent,
				array( 'should_save_payment_method' => $save_payment_method ? 'true' : 'false' )
			);
			if ( null !== $token_save_error ) {
				throw new WooPaymentsIntentConfirmationException(
					esc_html( (string) ( $token_save_error['error']['message'] ?? '' ) ),
					(int) ( $token_save_error['status_code'] ?? 409 )
				);
			}
		}

		$event = $this->build_lifecycle_event_from_intent( $intent, $order );
		$this->lifecycle_service->apply( $order, $event, new WooPaymentsPersistenceProfile() );
		if ( $this->is_authorized_intent_status( $status ) ) {
			$this->apply_payment_method_display_details( $order, $intent );
		}

		if ( ! $this->is_authorized_intent_status( $status ) ) {
			throw new WooPaymentsIntentConfirmationException( esc_html__( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Build the WooPayments-compatible setup-intent creation response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_create_setup_intent_response( array $request ): array {
		if ( ! $this->can_handle_callbacks() ) {
			return $this->json_error_response( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ), 409 );
		}

		if ( ! is_user_logged_in() ) {
			return $this->json_error_response( __( "We're not able to add this payment method. Please log in and try again.", 'woocommerce' ), 401 );
		}

		if ( ! $this->is_nonce_valid( $request, 'wcpay_create_setup_intent_nonce' ) ) {
			return $this->json_error_response( __( "We're not able to add this payment method. Please refresh the page and try again.", 'woocommerce' ), 403 );
		}

		// The card-testing prevention check must run BEFORE the SetupIntent is
		// created: a fraud-failed attempt that has already created and
		// confirmed a SetupIntent leaves the payment method attached to the
		// customer server-side with nothing to detach it. Same ordering as the
		// plugin's create_setup_intent_ajax: nonce, fraud check, rate limit.
		$fraud_prevention_service = $this->get_fraud_prevention_service();
		if (
			$fraud_prevention_service->has_session()
			&& $fraud_prevention_service->is_enabled()
			&& ! $fraud_prevention_service->verify_token( $this->get_request_string( $request, WooPaymentsFraudPreventionService::TOKEN_NAME ) )
		) {
			return $this->json_error_response( __( "We're not able to add this payment method. Please refresh the page and try again.", 'woocommerce' ), 400 );
		}

		$user_id        = get_current_user_id();
		$rate_limit_key = 'add_payment_method_' . $user_id;
		if ( \WC_Rate_Limiter::retried_too_soon( $rate_limit_key ) ) {
			return $this->json_error_response( __( 'You cannot add a new payment method so soon after the previous one. Please try again later.', 'woocommerce' ), 429 );
		}

		$payment_method_id = $this->get_request_string( $request, 'wcpay-payment-method' );
		if ( '' === $payment_method_id ) {
			return $this->json_error_response( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ), 400 );
		}

		try {
			$result = $this->api_client->create_and_confirm_setup_intention(
				array(
					'customer'             => $this->customer_service->get_or_create_customer_id_for_user( $user_id ),
					'payment_method'       => $payment_method_id,
					'payment_method_types' => array( $this->get_setup_intent_payment_method_type( $request ) ),
				),
				'add_payment_method_' . $user_id . '_' . md5( $payment_method_id )
			);

			return array(
				'success'     => true,
				'data'        => array(
					'id'            => isset( $result['id'] ) ? (string) $result['id'] : '',
					'status'        => isset( $result['status'] ) ? (string) $result['status'] : '',
					'client_secret' => isset( $result['client_secret'] ) ? (string) $result['client_secret'] : '',
				),
				'status_code' => 200,
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $this->json_error_response(
				WooPaymentsErrorMessages::get_shopper_message(
					$exception->get_error_type(),
					$exception->get_error_code(),
					$exception->get_decline_code()
				),
				502
			);
		} catch ( Throwable $exception ) {
			return $this->json_error_response( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ), 500 );
		}
	}

	/**
	 * Tell whether native callbacks can be handled.
	 *
	 * @return bool
	 */
	private function can_handle_callbacks(): bool {
		return $this->arbiter->should_native_register() && $this->api_client->is_available();
	}

	/**
	 * Verify an AJAX nonce from request data.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @param string              $action  Nonce action.
	 * @return bool
	 */
	private function is_nonce_valid( array $request, string $action ): bool {
		$nonce = $this->get_request_string( $request, '_ajax_nonce' );

		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Tell whether the current user is allowed to act on the given order.
	 *
	 * Defense-in-depth for the guest-accessible (`nopriv`) order-status callback: the nonce is the
	 * primary guard, but an authenticated caller must never be able to complete an order that belongs
	 * to a different customer. This mirrors core's `pay_for_order` meta-capability, which grants access
	 * when the caller owns the order or when the order has no owner (guest checkout), and otherwise
	 * defers to the user's capabilities (e.g. shop managers).
	 *
	 * @param WC_Order $order Order being updated.
	 * @return bool
	 */
	private function current_user_can_act_on_order( WC_Order $order ): bool {
		return (bool) current_user_can( 'pay_for_order', $order->get_id() );
	}

	/**
	 * Build a payment lifecycle event from a native intent response.
	 *
	 * @param array<string,mixed> $intent Native intent response.
	 * @param WC_Order            $order  Order being updated.
	 * @return PaymentLifecycleEvent
	 */
	private function build_lifecycle_event_from_intent( array $intent, WC_Order $order ): PaymentLifecycleEvent {
		$intent_id             = isset( $intent['id'] ) ? (string) $intent['id'] : '';
		$is_setup              = 0.0 >= (float) $order->get_total() || 0 === strpos( $intent_id, 'seti_' );
		$provider_redirect_url = esc_url_raw( WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent ) );
		$outcome               = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			WooPaymentsIntentMappingContext::for_native(
				$order->get_id(),
				$order->get_checkout_order_received_url(),
				'',
				'',
				'',
				$is_setup ? 'si' : 'pi',
				$provider_redirect_url
			)
		);
		$plan                  = $is_setup
			? WooPaymentsOrderEffectPlan::for_setup_intent(
				$intent,
				false,
				array(
					'_wcpay_intent_currency' => (string) $order->get_currency(),
					'_wcpay_mode'            => $this->account_service->get_mode(),
				)
			)
			: WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false );
		$outcome               = $this->get_order_effect_applier()->enrich_outcome_for_lifecycle(
			PaymentContext::for_checkout( $order, (string) $order->get_payment_method(), $outcome->get_payment_method_id() ),
			$outcome,
			$plan
		);

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
		$profile          = new WooPaymentsPersistenceProfile();

		return new PaymentLifecycleEvent(
			$this->get_lifecycle_status( $outcome ),
			'' === $outcome->get_provider_payment_id() ? null : $outcome->get_provider_payment_id(),
			$profile->get_outcome_meta( $outcome ),
			array(),
			$note,
			$note_type,
			$note_equivalents
		);
	}

	/**
	 * Map a provider outcome status to a lifecycle status.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string
	 */
	private function get_lifecycle_status( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return PaymentLifecycleEvent::STATUS_COMPLETED;
			case PaymentOutcome::STATUS_AUTHORIZED:
				return PaymentLifecycleEvent::STATUS_AUTHORIZED;
			case PaymentOutcome::STATUS_FAILED:
				return PaymentLifecycleEvent::STATUS_FAILED;
			case PaymentOutcome::STATUS_CANCELED:
				return PaymentLifecycleEvent::STATUS_CANCELED;
			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return PaymentLifecycleEvent::STATUS_STARTED;
		}

		return PaymentLifecycleEvent::STATUS_FAILED;
	}

	/**
	 * Apply charge payment-method display details before order completion.
	 *
	 * @param WC_Order            $order  Order being updated.
	 * @param array<string,mixed> $intent Native intent response.
	 */
	private function apply_payment_method_display_details( WC_Order $order, array $intent ): void {
		$this->get_order_effect_applier()->apply_payment_method_display_details( $order, $intent, $this->account_service->get_account_country() );
	}

	/**
	 * Get the WooPayments order effect applier.
	 *
	 * @return WooPaymentsOrderEffectApplier
	 */
	private function get_order_effect_applier(): WooPaymentsOrderEffectApplier {
		if ( null === $this->order_effect_applier ) {
			$this->order_effect_applier = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
		}

		return $this->order_effect_applier;
	}

	/**
	 * Tell whether an intent status should be treated as authorized.
	 *
	 * @param string $status Intent status.
	 * @return bool
	 */
	private function is_authorized_intent_status( string $status ): bool {
		return in_array( $status, array( 'succeeded', 'requires_capture', 'processing' ), true );
	}

	/**
	 * Persist a requested payment method as a WooCommerce token before completing the order.
	 *
	 * @param WC_Order            $order   Order being updated.
	 * @param array<string,mixed> $intent  Native intent response.
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>|null Error response when token saving must block checkout.
	 */
	private function maybe_save_payment_method_for_order( WC_Order $order, array $intent, array $request ): ?array {
		$is_recurring               = $this->is_recurring_payment( $order );
		$should_save_payment_method = $is_recurring || $this->should_save_payment_method( $request ) || $this->is_subscription_change_payment_request( $request );
		if ( ! $should_save_payment_method ) {
			return null;
		}

		$payment_method_id = $this->get_result_payment_method_id( $intent );
		$user_id           = $this->get_token_user_id( $order );
		if ( '' === $payment_method_id || 0 >= $user_id ) {
			return $is_recurring ? $this->recurring_token_save_error_response() : null;
		}

		try {
			$token = $this->token_service->get_or_create_token_for_user( $payment_method_id, $user_id );
			if ( $token instanceof \WC_Payment_Token ) {
				$this->token_service->attach_token_to_order( $order, $token );
				$this->token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $this->get_result_customer_id( $intent ) );

				return null;
			}
		} catch ( Throwable $exception ) {
			$this->log_token_save_error( $payment_method_id, $exception );

			return $is_recurring ? $this->recurring_token_save_error_response() : null;
		}

		return $is_recurring ? $this->recurring_token_save_error_response() : null;
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
	 * Update WC Subscriptions after a successful native WooPayments payment-method change.
	 *
	 * @param WC_Order $order Subscription order.
	 * @return void
	 */
	private function maybe_update_subscription_payment_method( WC_Order $order ): void {
		if ( ! class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			return;
		}

		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $order, OrderPaymentStore::GATEWAY_ID );

		$will_update_all_callback = array( 'WC_Subscriptions_Change_Payment_Gateway', 'will_subscription_update_all_payment_methods' );
		$update_all_callback      = array( 'WC_Subscriptions_Change_Payment_Gateway', 'update_all_payment_methods_from_subscription' );

		if ( is_callable( $will_update_all_callback ) && is_callable( $update_all_callback ) && (bool) call_user_func( $will_update_all_callback, $order ) ) {
			call_user_func( $update_all_callback, $order, OrderPaymentStore::GATEWAY_ID );
		}
	}

	/**
	 * Tell whether this payment must be saved for a recurring order.
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
		 * Filters whether a native WooPayments callback requires saved-token persistence.
		 *
		 * @since 11.0.0
		 *
		 * @param bool     $is_recurring Whether the order requires saved-token persistence.
		 * @param WC_Order $order        Order object.
		 */
		return (bool) apply_filters( 'woocommerce_woopayments_is_recurring_payment', $is_recurring, $order );
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
	 * Log a token-save error.
	 *
	 * @param string    $payment_method_id Provider payment method ID.
	 * @param Throwable $exception         Exception thrown while saving the token.
	 */
	private function log_token_save_error( string $payment_method_id, Throwable $exception ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			sprintf(
				'Failed to save native WooPayments payment method %1$s: %2$s',
				$payment_method_id,
				$exception->getMessage()
			),
			array( 'source' => 'payment-info' )
		);
	}

	/**
	 * Build the recurring token-save error response.
	 *
	 * @return array<string,mixed>
	 */
	private function recurring_token_save_error_response(): array {
		return $this->error_response( __( 'Unable to save payment method for subscription. Please try again or use a different payment method.', 'woocommerce' ), 409 );
	}

	/**
	 * Get the payment method ID from an intent response.
	 *
	 * @param array<string,mixed> $intent Native intent response.
	 * @return string
	 */
	private function get_result_payment_method_id( array $intent ): string {
		if ( isset( $intent['payment_method'] ) && is_string( $intent['payment_method'] ) ) {
			return $intent['payment_method'];
		}

		if ( isset( $intent['payment_method'] ) && is_array( $intent['payment_method'] ) && isset( $intent['payment_method']['id'] ) ) {
			return (string) $intent['payment_method']['id'];
		}

		return '';
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
	 * Get the return URL for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_return_url( WC_Order $order ): string {
		$gateway = new NativeWooPaymentsGateway();

		return $gateway->get_return_url( $order );
	}

	/**
	 * Build a bare WooPayments order-status error response.
	 *
	 * @param string $message     Error message.
	 * @param int    $status_code HTTP status code.
	 * @return array<string,mixed>
	 */
	private function error_response( string $message, int $status_code ): array {
		return array(
			'error'       => array(
				'message' => $message,
			),
			'status_code' => $status_code,
		);
	}

	/**
	 * Build a WP JSON error response payload.
	 *
	 * @param string $message     Error message.
	 * @param int    $status_code HTTP status code.
	 * @return array<string,mixed>
	 */
	private function json_error_response( string $message, int $status_code ): array {
		return array(
			'success'     => false,
			'data'        => array(
				'error' => array(
					'message' => $message,
				),
			),
			'status_code' => $status_code,
		);
	}

	/**
	 * Extract and remove the internal status code from a response.
	 *
	 * @param array<string,mixed> $response Response payload.
	 * @return int
	 */
	private function extract_status_code( array &$response ): int {
		$status_code = isset( $response['status_code'] ) ? (int) $response['status_code'] : 200;
		unset( $response['status_code'] );

		return $status_code;
	}

	/**
	 * Get the Stripe SetupIntent payment method type for a request.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return string
	 */
	private function get_setup_intent_payment_method_type( array $request ): string {
		$payment_method_id = $this->get_payment_method_id_from_request_gateway( $request );
		$definition        = $this->payment_method_registry->get( $payment_method_id );

		return null === $definition ? 'card' : $definition->get_stripe_payment_method_type();
	}

	/**
	 * Get the native payment method ID from a submitted gateway ID.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return string
	 */
	private function get_payment_method_id_from_request_gateway( array $request ): string {
		$gateway_id = strtolower( $this->get_request_string( $request, 'payment_method' ) );

		if ( '' === $gateway_id || OrderPaymentStore::GATEWAY_ID === $gateway_id ) {
			return 'card';
		}

		$gateway_prefix = OrderPaymentStore::GATEWAY_ID . '_';
		if ( str_starts_with( $gateway_id, $gateway_prefix ) ) {
			return (string) substr( $gateway_id, strlen( $gateway_prefix ) );
		}

		return $gateway_id;
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

	/**
	 * Get the fraud prevention service.
	 *
	 * @return WooPaymentsFraudPreventionService
	 */
	private function get_fraud_prevention_service(): WooPaymentsFraudPreventionService {
		if ( ! isset( $this->fraud_prevention_service ) ) {
			$this->fraud_prevention_service = wc_get_container()->get( WooPaymentsFraudPreventionService::class );
		}

		return $this->fraud_prevention_service;
	}
}
