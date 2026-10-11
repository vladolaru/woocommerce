<?php
/**
 * WooPaymentsCheckoutAjaxController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

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
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

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
	 * Intent confirmation service.
	 *
	 * @var WooPaymentsIntentConfirmationService
	 */
	private WooPaymentsIntentConfirmationService $intent_confirmation_service;

	/**
	 * WooPayments payment method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter             $arbiter                     Runtime owner arbiter.
	 * @param WooPaymentsApiClient                  $api_client                  Native WooPayments API client.
	 * @param WooPaymentsCustomerService            $customer_service            WooPayments customer service.
	 * @param WooPaymentsIntentConfirmationService  $intent_confirmation_service Intent confirmation service.
	 * @param WooPaymentsPaymentMethodRegistry|null $payment_method_registry     Optional payment method registry.
	 */
	final public function init(
		WooPaymentsRuntimeArbiter $arbiter,
		WooPaymentsApiClient $api_client,
		WooPaymentsCustomerService $customer_service,
		WooPaymentsIntentConfirmationService $intent_confirmation_service,
		?WooPaymentsPaymentMethodRegistry $payment_method_registry = null
	): void {
		$this->arbiter                     = $arbiter;
		$this->api_client                  = $api_client;
		$this->customer_service            = $customer_service;
		$this->intent_confirmation_service = $intent_confirmation_service;
		$this->payment_method_registry     = $payment_method_registry ?? new WooPaymentsPaymentMethodRegistry();
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
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
			$order->add_order_note(
				sprintf(
					WooPaymentsHtmlUtils::escape_interpolated_html(
						/* translators: %1: transaction ID of the payment or a translated string indicating an unknown ID. */
						__( 'A payment with ID <code>%1$s</code> was used in an attempt to pay for this order. This payment intent ID does not match any payments for this order, so it was ignored and the order was not updated.', 'woocommerce' ),
						array( 'code' => '<code>' )
					),
					/* translators: This will be used to indicate an unknown value for an ID. */
					isset( $request['intent_id'] ) ? $intent_id : __( 'unknown', 'woocommerce' )
				)
			);
			return $this->error_response( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 409 );
		}

		if ( ! $this->current_user_can_act_on_order( $order ) ) {
			return $this->error_response( __( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce' ), 403 );
		}

		try {
			$is_subscription_payment_method_change = $this->is_subscription_change_payment_request( $request );
			$this->intent_confirmation_service->confirm_intent_for_order(
				$order,
				$intent_id,
				$this->should_save_payment_method( $request ) || $is_subscription_payment_method_change,
				$is_subscription_payment_method_change
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
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable(
				'Error completing native WooPayments authenticated payment: ' . $exception->getMessage(),
				$exception,
				array(
					'order_id'  => $order->get_id(),
					'intent_id' => $intent_id,
				)
			);

			return $this->error_response( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ), 500 );
		}
	}

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
			return $this->json_error_response( __( "We're not able to add this payment method. Please refresh the page and try again.", 'woocommerce' ), 400 );
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
			return $this->json_error_response( __( 'You cannot add a new payment method so soon after the previous one. Please try again later.', 'woocommerce' ), 400 );
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
					'metadata'             => WooPaymentsIntentRequestBuilder::fingerprint_metadata_from_value(
						$this->get_request_string( $request, 'wcpay-fingerprint' )
					),
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
					$exception->get_decline_code(),
					$exception->getMessage()
				),
				// Same status mapping as the plugin: 402 and a missing status answer 400.
				in_array( $exception->get_http_code(), array( 0, 402 ), true ) ? 400 : $exception->get_http_code()
			);
		} catch ( Throwable $exception ) {
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Error creating native WooPayments setup intent: ' . $exception->getMessage(), $exception );

			return $this->json_error_response( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ), 400 );
		}
	}

	/**
	 * Tell whether native callbacks can be handled.
	 *
	 * @return bool
	 */
	private function can_handle_callbacks(): bool {
		return $this->arbiter->is_builtin_owner() && $this->api_client->is_available();
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
	 * to a different customer. This uses core's `pay_for_order` meta-capability, which passes only when
	 * the caller owns the order or the order has no owner (guest checkout). Other users, shop managers
	 * and single-site administrators included, are refused for other customers' orders; multisite
	 * super admins pass every capability check, so they pass this one too.
	 *
	 * @param WC_Order $order Order being updated.
	 * @return bool
	 */
	private function current_user_can_act_on_order( WC_Order $order ): bool {
		return (bool) current_user_can( 'pay_for_order', $order->get_id() );
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

		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID );

		$will_update_all_callback = array( 'WC_Subscriptions_Change_Payment_Gateway', 'will_subscription_update_all_payment_methods' );
		$update_all_callback      = array( 'WC_Subscriptions_Change_Payment_Gateway', 'update_all_payment_methods_from_subscription' );

		if ( is_callable( $will_update_all_callback ) && is_callable( $update_all_callback ) && (bool) call_user_func( $will_update_all_callback, $order ) ) {
			call_user_func( $update_all_callback, $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		}
	}

	/**
	 * Get the return URL for an order from the card gateway WooCommerce registers, as client 11.1.0 does (gw:4353).
	 *
	 * The gateway is read from the container when a response needs it, so a request that never confirms an intent does not build it.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_return_url( WC_Order $order ): string {
		return wc_get_container()->get( WooPaymentsGateway::class )->get_return_url( $order );
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

		if ( '' === $gateway_id || WooPaymentsPersistenceVocabulary::GATEWAY_ID === $gateway_id ) {
			return 'card';
		}

		$gateway_prefix = WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_';
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
