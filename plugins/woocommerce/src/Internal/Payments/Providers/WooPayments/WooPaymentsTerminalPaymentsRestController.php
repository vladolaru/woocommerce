<?php
/**
 * WooPaymentsTerminalPaymentsRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Payment_Token;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the in-person payment routes the WooCommerce mobile apps call: the card reader connection token, and for an order
 * its terminal payment intent, the preparation and capture of that payment, and its WooPayments customer. The extension
 * serves them from includes/admin/class-wc-rest-payments-orders-controller.php and
 * class-wc-rest-payments-connection-tokens-controller.php.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsTerminalPaymentsRestController extends RestApiControllerBase {

	private const LOCK_CLAIM_ATTEMPTS = 4;

	private const LOCK_RETRY_WAIT_MICROSECONDS = 500000;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

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
	 * @param WooPaymentsApiClient        $api_client         Native WooPayments API client.
	 * @param WooPaymentsAccountService   $account_service    WooPayments account service.
	 * @param WooPaymentsCustomerService  $customer_service   WooPayments customer service.
	 * @param WooPaymentsOrderDataService $order_data_service WooPayments order data service.
	 * @param WooPaymentsOrderNoteService $note_service       WooPayments order note service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service, WooPaymentsCustomerService $customer_service, WooPaymentsOrderDataService $order_data_service, WooPaymentsOrderNoteService $note_service ): void {
		$this->api_client         = $api_client;
		$this->account_service    = $account_service;
		$this->customer_service   = $customer_service;
		$this->order_data_service = $order_data_service;
		$this->note_service       = $note_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-terminal-payments';
	}

	/**
	 * Register the in-person payment routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->route_namespace,
			'/payments/connection_tokens',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_connection_token' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/orders/(?P<order_id>\w+)/capture_terminal_payment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'capture_terminal_payment' ),
				'permission_callback' => array( $this, 'has_permission' ),
				'args'                => array(
					'payment_intent_id' => array(
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/orders/(?P<order_id>\w+)/prepare_terminal_payment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'prepare_terminal_payment' ),
				'permission_callback' => array( $this, 'has_permission' ),
				'args'                => array(
					'order_id'          => array(
						'required' => true,
						'type'     => 'integer',
					),
					'payment_intent_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/orders/(?P<order_id>\w+)/create_terminal_intent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_terminal_intent' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);

		register_rest_route(
			$this->route_namespace,
			'/payments/orders/(?P<order_id>\d+)/create_customer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_customer' ),
				'permission_callback' => array( $this, 'has_permission' ),
			)
		);
	}

	/**
	 * Check route permissions.
	 *
	 * @return bool
	 */
	public function has_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Create a terminal connection token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_connection_token( WP_REST_Request $request ) {
		unset( $request );

		try {
			$data              = $this->api_client->create_terminal_connection_token();
			$data['test_mode'] = $this->account_service->is_test_mode_enabled();

			return new WP_REST_Response( $data );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error_without_status();
		}
	}

	/**
	 * Create or update the WooPayments customer for an order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_customer( WP_REST_Request $request ) {
		$order = $this->get_order_from_request( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		/**
		 * Filters order statuses that cannot create or update WooPayments customers through the mobile REST API.
		 *
		 * @since 11.0.0
		 *
		 * @param string[] $statuses Disallowed order statuses.
		 */
		$disallowed_statuses = apply_filters(
			'wcpay_create_customer_disallowed_order_statuses',
			array(
				'completed',
				'cancelled',
				'refunded',
				'failed',
			)
		);

		if ( $order->has_status( is_array( $disallowed_statuses ) ? $disallowed_statuses : array() ) ) {
			return new WP_Error( 'wcpay_invalid_order_status', __( 'Invalid order status', 'woocommerce' ), array( 'status' => 400 ) );
		}

		try {
			// Client 11.1.0 reads the order's customer, then its user's, and updates either at once
			// (includes/admin/class-wc-rest-payments-orders-controller.php:480-492).
			$customer_id = (string) $order->get_meta( '_stripe_customer_id', true );
			if ( '' === $customer_id && 0 < $order->get_customer_id() ) {
				$customer_id = (string) $this->customer_service->migrate_and_get_customer_id( $order->get_customer_id() );
			}
			if ( '' !== $customer_id ) {
				$customer_id = $this->customer_service->update_customer_for_order( $customer_id, $order );
			} else {
				$customer_id = $this->customer_service->get_or_create_customer_id_for_order_user( $order );
			}

			$order->update_meta_data( '_stripe_customer_id', $customer_id );
			$order->save();

			return new WP_REST_Response( array( 'id' => $customer_id ) );
		} catch ( Throwable $exception ) {
			// The client logs before its generic answer (class-wc-rest-payments-orders-controller.php:502-503).
			$this->get_logger()->log_throwable( 'Failed to create or update the customer from the order via the REST API.', $exception, array( 'order_id' => $order->get_id() ) );

			return $this->server_error();
		}
	}

	/**
	 * Create a terminal payment intent for an order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_terminal_intent( WP_REST_Request $request ) {
		$order = $this->get_order_from_request( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		try {
			// App metadata is forwarded verbatim like the plugin: the apps reconcile
			// on exact metadata names, and it only ever travels to the provider API.
			$currency        = strtolower( $order->get_currency() );
			$raw_metadata    = $request->get_param( 'metadata' );
			$metadata        = is_array( $raw_metadata ) ? $raw_metadata : array();
			$payment_methods = $this->get_terminal_intent_payment_methods( $request );
			$capture_method  = $this->get_terminal_intent_capture_method( $request );
			$request_data    = array(
				'amount'               => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), $currency ),
				'currency'             => $currency,
				'description'          => WooPaymentsIntentRequestBuilder::intent_description( (string) $order->get_order_number() ),
				'metadata'             => array_merge(
					$metadata,
					array(
						'order_id'     => (string) $order->get_id(),
						'order_number' => $order->get_order_number(),
					)
				),
				'payment_method_types' => $payment_methods,
				'capture_method'       => $capture_method,
			);
			$customer_id     = $request->get_param( 'customer_id' );

			if ( is_string( $customer_id ) && '' !== $customer_id ) {
				$request_data['customer'] = $customer_id;
			}

			$intent = $this->api_client->create_terminal_payment_intention( $request_data );

			return new WP_REST_Response( array( 'id' => $intent['id'] ?? null ) );
		} catch ( Throwable $exception ) {
			// Platform failures included, the client logs and answers its generic error (class-wc-rest-payments-orders-controller.php:544-547).
			$this->get_logger()->log_throwable( 'Failed to create a terminal payment intent via the REST API.', $exception, array( 'order_id' => $order->get_id() ) );

			return $this->server_error();
		}
	}

	/**
	 * Prepare a terminal payment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_terminal_payment( WP_REST_Request $request ) {
		$order = $this->get_order_from_request( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		$intent_id = $this->get_request_string( $request, 'payment_intent_id' );
		if ( ! $this->is_valid_provider_id( $intent_id ) ) {
			return new WP_Error( 'wcpay_invalid_payment_intent_id', __( 'Invalid payment intent ID.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		if ( 0 < $order->get_total_refunded() ) {
			return new WP_Error( 'wcpay_refunded_order_unpreparable', __( 'Terminal payments cannot be prepared for partially or fully refunded orders.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		try {
			return new WP_REST_Response( $this->api_client->prepare_terminal_payment( $intent_id, $order->get_id() ) );
		} catch ( WooPaymentsApiException $exception ) {
			// The client's fallback code when the platform sends none (class-wc-rest-payments-orders-controller.php:346-353).
			return new WP_Error(
				'' !== $exception->get_error_code() ? $exception->get_error_code() : 'wcpay_prepare_terminal_payment_failed',
				$exception->getMessage(),
				array( 'status' => 0 !== $exception->get_http_code() ? $exception->get_http_code() : 500 )
			);
		} catch ( Throwable $exception ) {
			// The client logs before its generic answer (class-wc-rest-payments-orders-controller.php:355-356).
			$this->get_logger()->log_throwable( 'Failed to prepare a terminal payment via the REST API.', $exception, array( 'order_id' => $order->get_id() ) );

			return $this->server_error();
		}
	}

	/**
	 * Capture a terminal payment.
	 *
	 * The capture and the order writes run under the order payment lock, which client 11.1.0 does not take here. A refusal
	 * charges nothing and answers wcpay_capture_error, which the Android app retries on the same intent; it reads
	 * wcpay_payment_uncapturable as already captured and would report a sale.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function capture_terminal_payment( WP_REST_Request $request ) {
		$order = $this->get_order_from_request( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		$intent_id = $this->get_request_string( $request, 'payment_intent_id' );
		if ( '' === $intent_id ) {
			// The client's intent read refuses an empty ID inside its generic catch (class-wc-rest-payments-orders-controller.php:197-199, :289-292).
			return $this->server_error();
		}

		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary         = new WooPaymentsPersistenceVocabulary();
		$lock_token         = $this->claim_terminal_capture_lock( $order_payment_lock, $order, $vocabulary, $intent_id );
		if ( null === $lock_token ) {
			$order_payment_lock->log_refusal( $order, $vocabulary, 'terminal capture' );

			return new WP_Error( 'wcpay_capture_error', __( 'The payment is still being processed. Try again.', 'woocommerce' ), array( 'status' => 409 ) );
		}

		try {
			// Another request may have captured or refunded the payment while this one waited for the lock.
			$this->get_lifecycle_service()->reread_order_from_data_store( $order );

			return $this->capture_terminal_payment_under_lock( $order, $intent_id );
		} finally {
			$order_payment_lock->release( $order, $vocabulary, $lock_token );
		}
	}

	/**
	 * Claim the order payment lock for a terminal capture, waiting briefly for the current holder.
	 *
	 * This is a lock wait, not a timing fix for a race: the usual holder is the webhook applying an event for the same
	 * intent, which keeps the lock well under a second, and the merchant is waiting at the counter. Four attempts
	 * 500 ms apart, all before the platform capture starts.
	 *
	 * @param OrderPaymentLock                 $order_payment_lock Order payment store.
	 * @param WC_Order                         $order              Order.
	 * @param WooPaymentsPersistenceVocabulary $vocabulary         Persistence profile.
	 * @param string                           $intent_id          Intent ID, the lock value.
	 * @return string|null Lock token, or null when the lock stayed held.
	 */
	private function claim_terminal_capture_lock( OrderPaymentLock $order_payment_lock, WC_Order $order, WooPaymentsPersistenceVocabulary $vocabulary, string $intent_id ): ?string {
		for ( $attempt = 1; ; ++$attempt ) {
			$lock_token = $order_payment_lock->claim( $order, $vocabulary, $intent_id, 'terminal capture' );
			if ( null !== $lock_token || self::LOCK_CLAIM_ATTEMPTS === $attempt ) {
				return $lock_token;
			}

			$this->wait_before_lock_retry();
		}
	}

	/**
	 * Wait before claiming the order payment lock again.
	 *
	 * Overridable so tests need not pay the wait.
	 */
	protected function wait_before_lock_retry(): void {
		usleep( self::LOCK_RETRY_WAIT_MICROSECONDS );
	}

	/**
	 * Capture a terminal payment while holding the order payment lock.
	 *
	 * @param WC_Order $order     Order, read again under the lock.
	 * @param string   $intent_id Intent ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function capture_terminal_payment_under_lock( WC_Order $order, string $intent_id ) {
		if ( 0 < $order->get_total_refunded() ) {
			return new WP_Error( 'wcpay_refunded_order_uncapturable', __( 'Payment cannot be captured for partially or fully refunded orders.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		$uncapturable_error = $this->get_uncapturable_order_error( $order, $intent_id );
		if ( $uncapturable_error instanceof WP_Error ) {
			return $uncapturable_error;
		}

		$payment_captured = false;
		try {
			$intent = $this->api_client->get_payment_intention( $intent_id );
			if ( ! $this->intent_matches_order( $intent, $order ) ) {
				$this->get_logger()->error( 'Payment capture rejected due to failed validation: order id on intent is incorrect or missing.', array( 'order_id' => $order->get_id() ) );

				return new WP_Error( 'wcpay_intent_order_mismatch', __( 'The payment cannot be captured', 'woocommerce' ), array( 'status' => 409 ) );
			}

			$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
			// The client's is_authorized() statuses (Intent_Status::AUTHORIZED_STATUSES, class-intent-status.php:35-39).
			if ( ! WooPaymentsIntentCodec::holds_money( $status ) ) {
				return new WP_Error( 'wcpay_payment_uncapturable', __( 'The payment cannot be captured', 'woocommerce' ), array( 'status' => 409 ) );
			}
			// A reader that captured the payment itself (Interac) has charged the card before any write below.
			$payment_captured = 'succeeded' === $status;

			// The client attaches the in-person method and channel before any status change, so the order emails and core's POS email checks see them (class-wc-rest-payments-orders-controller.php:209-215).
			$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
			$order->set_payment_method_title( __( 'WooCommerce In-Person Payments', 'woocommerce' ) );
			$ipp_channel = $this->get_ipp_channel_from_intent( $intent );
			if ( '' !== $ipp_channel ) {
				$order->update_meta_data( '_wcpay_ipp_channel', $ipp_channel );
			}
			$order->save();

			if ( 'succeeded' !== $status ) {
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_merchant_captured_auth' );
				// The client first records the authorization: on hold, with the authorized note (class-wc-rest-payments-orders-controller.php:217, class-wc-payments-order-service.php:1612-1628).
				$this->apply_terminal_lifecycle_event( $order, $intent, $intent_id, PaymentLifecycleEvent::STATUS_AUTHORIZED );
			}
			try {
				$result = 'succeeded' === $status
					? $intent
					: $this->api_client->capture_intention(
						$intent_id,
						WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), (string) $order->get_currency() ),
						// Order-derived metadata first; the intent's own metadata wins, mirroring the plugin's mobile-app priority.
						array_merge( WooPaymentsIntentRequestBuilder::capture_metadata_from_order( $order ), $this->get_intent_metadata( $intent ) )
					);
			} catch ( WooPaymentsApiException $capture_exception ) {
				// Only a failed capture call marks the order; pre-check failures must not.
				$this->reconcile_failed_terminal_capture( $order, $intent_id, $this->get_capture_failure_note_message( $capture_exception ) );

				return $this->get_terminal_capture_error( $this->get_capture_exception_result( $capture_exception ) );
			}

			if ( 'succeeded' !== (string) ( $result['status'] ?? '' ) ) {
				$this->record_failed_terminal_capture( $order, $intent_id, isset( $result['message'] ) ? (string) $result['message'] : '' );

				return $this->get_terminal_capture_error( $result );
			}
			$payment_captured = true;

			// The capture note, or the payment note for an intent the reader already captured (Interac), completes the payment as on the client (class-wc-payments-order-service.php:1582, :1659-1685); writing it schedules the Fee details job.
			$this->apply_terminal_lifecycle_event( $order, $result, $intent_id, PaymentLifecycleEvent::STATUS_COMPLETED, 'succeeded' === $status );
			$this->save_generated_card_for_subscriptions( $order, $intent );
			$this->mark_terminal_payment_completed( $order, $result, $intent_id );

			return new WP_REST_Response(
				array(
					'status' => isset( $result['status'] ) ? (string) $result['status'] : '',
					'id'     => isset( $result['id'] ) ? (string) $result['id'] : $intent_id,
				)
			);
		} catch ( Throwable $exception ) {
			// A platform error before the capture call, such as the intent read, lands here too, as on the client.
			$context = array(
				'order_id'  => $order->get_id(),
				'intent_id' => $intent_id,
			);
			if ( $payment_captured ) {
				// The card is charged and the payment_intent.succeeded webhook still records it, but this request's own writes (receipt URL, fraud box) are lost.
				$this->get_logger()->log_throwable_always( 'Terminal payment captured, but recording it on the order failed.', $exception, $context );
			} else {
				// The client logs before its generic answer (class-wc-rest-payments-orders-controller.php:289-290).
				$this->get_logger()->log_throwable( 'Failed to capture a terminal payment via the REST API.', $exception, $context );
			}

			return $this->server_error();
		}
	}

	/**
	 * Get an order from a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WC_Order|WP_Error
	 */
	private function get_order_from_request( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wcpay_missing_order', __( 'Order not found', 'woocommerce' ), array( 'status' => 404 ) );
		}

		return $order;
	}

	/**
	 * Get terminal intent payment methods from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return string[]
	 * @throws \UnexpectedValueException When the request contains unsupported payment methods.
	 */
	private function get_terminal_intent_payment_methods( WP_REST_Request $request ): array {
		$payment_methods = $request->get_param( 'payment_methods' );
		if ( null === $payment_methods ) {
			return array( 'card_present' );
		}

		if ( ! is_array( $payment_methods ) ) {
			throw new \UnexpectedValueException( 'Invalid terminal payment methods.' );
		}

		$allowed = array( 'card_present', 'interac_present' );
		foreach ( $payment_methods as $payment_method ) {
			if ( ! in_array( $payment_method, $allowed, true ) ) {
				throw new \UnexpectedValueException( 'Unsupported terminal payment method.' );
			}
		}

		return array_values( array_map( 'strval', $payment_methods ) );
	}

	/**
	 * Get terminal intent capture method from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return string
	 * @throws \UnexpectedValueException When the request contains an unsupported capture method.
	 */
	private function get_terminal_intent_capture_method( WP_REST_Request $request ): string {
		$capture_method = $request->get_param( 'capture_method' );
		if ( null === $capture_method ) {
			return 'manual';
		}

		if ( ! is_string( $capture_method ) || ! in_array( $capture_method, array( 'manual', 'automatic' ), true ) ) {
			throw new \UnexpectedValueException( 'Unsupported terminal capture method.' );
		}

		return $capture_method;
	}

	/**
	 * Get an uncapturable order error when terminal capture must not proceed.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent ID.
	 * @return WP_Error|null
	 */
	private function get_uncapturable_order_error( WC_Order $order, string $intent_id ): ?WP_Error {
		$stored_status = (string) $order->get_meta( '_intention_status', true );
		$stored_intent = (string) $order->get_meta( '_intent_id', true );

		if ( in_array( $stored_status, array( 'succeeded', 'canceled', 'processing' ), true ) ) {
			return new WP_Error( 'wcpay_payment_uncapturable', __( 'The payment cannot be captured for completed or processed orders.', 'woocommerce' ), array( 'status' => 409 ) );
		}

		if ( '' !== $stored_intent && $stored_intent !== $intent_id ) {
			return new WP_Error( 'wcpay_payment_uncapturable', __( 'The payment cannot be captured for completed or processed orders.', 'woocommerce' ), array( 'status' => 409 ) );
		}

		return null;
	}

	/**
	 * Tell whether an intent belongs to an order.
	 *
	 * @param array<string,mixed> $intent Intent.
	 * @param WC_Order            $order  Order.
	 * @return bool
	 */
	private function intent_matches_order( array $intent, WC_Order $order ): bool {
		$metadata = $this->get_intent_metadata( $intent );
		$order_id = $metadata['order_id'] ?? '';

		return is_numeric( $order_id ) && $order->get_id() === (int) $order_id;
	}

	/**
	 * Build a terminal capture error response from a failed capture result.
	 *
	 * @param array<string,mixed> $result Capture result.
	 * @return WP_Error
	 */
	private function get_terminal_capture_error( array $result ): WP_Error {
		$error_type    = isset( $result['error_code'] ) ? (string) $result['error_code'] : null;
		$extra_details = isset( $result['extra_details'] ) && is_array( $result['extra_details'] ) ? $result['extra_details'] : array();
		$error_code    = 'wcpay_capture_error';
		$http_code     = isset( $result['http_code'] ) ? (int) $result['http_code'] : 502;
		$message       = sprintf(
			/* translators: %s: the error message. */
			__( 'Payment capture failed to complete with the following message: %s', 'woocommerce' ),
			isset( $result['message'] ) ? (string) $result['message'] : __( 'Unknown error', 'woocommerce' )
		);

		if ( 'amount_too_small' === $error_type && ! empty( $extra_details ) ) {
			$error_code      = 'wcpay_capture_error_amount_too_small';
			$encoded_details = wp_json_encode( $extra_details );
			$message         = false === $encoded_details ? '' : esc_html( $encoded_details );
		}

		return new WP_Error( $error_code, $message, array( 'status' => $http_code ) );
	}

	/**
	 * Build the capture result the client's capture_charge() returns for a failed capture call.
	 *
	 * Client 11.1.0 keeps the exception's HTTP status and code, escapes its message and, for amount_too_small, adds the
	 * minimum amount the mobile app parses to prompt for a higher amount (class-wc-payment-gateway-wcpay.php:3991-4061).
	 *
	 * @param WooPaymentsApiException $exception Capture exception.
	 * @return array<string,mixed>
	 */
	private function get_capture_exception_result( WooPaymentsApiException $exception ): array {
		$result     = array(
			'error_code' => $exception->get_error_code(),
			'message'    => esc_html( $exception->getMessage() ),
			// The client keeps the platform's status, 0 included, and answers 502 only without a message (class-wc-payment-gateway-wcpay.php:3994, :4043-4048).
			'http_code'  => '' !== $exception->getMessage() ? $exception->get_http_code() : 502,
		);
		$error_data = $exception->get_error_data();
		if ( 'amount_too_small' === $exception->get_error_code() && isset( $error_data['minimum_amount'], $error_data['currency'] ) && is_numeric( $error_data['minimum_amount'] ) && is_string( $error_data['currency'] ) ) {
			$result['extra_details'] = array(
				'minimum_amount'          => (int) $error_data['minimum_amount'],
				'minimum_amount_currency' => strtoupper( $error_data['currency'] ),
			);
		}

		return $result;
	}

	/**
	 * Get intent metadata.
	 *
	 * @param array<string,mixed> $intent Intent.
	 * @return array<string,mixed>
	 */
	private function get_intent_metadata( array $intent ): array {
		return isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
	}

	/**
	 * Record a terminal payment step through the payment lifecycle with the client's note for it.
	 *
	 * @param WC_Order            $order              Order.
	 * @param array<string,mixed> $intent             Intent or capture response.
	 * @param string              $intent_id          Intent ID.
	 * @param string              $status             Lifecycle status, authorized or completed.
	 * @param bool                $already_captured   Whether the reader captured the payment itself, so completion is a payment rather than a capture.
	 */
	private function apply_terminal_lifecycle_event( WC_Order $order, array $intent, string $intent_id, string $status, bool $already_captured = false ): void {
		$charge                 = WooPaymentsIntentCodec::latest_charge( $intent );
		$charge_id              = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		$balance_transaction_id = WooPaymentsIntentCodec::balance_transaction_id( $charge['balance_transaction'] ?? null );

		$meta = array();
		if ( PaymentLifecycleEvent::STATUS_AUTHORIZED === $status ) {
			$note_type  = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED;
			$candidates = $this->note_service->format_payment_authorized_note_candidates( $order, $intent_id, $charge_id );
		} elseif ( $already_captured ) {
			$note_type  = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS;
			$candidates = $this->note_service->format_payment_success_note_candidates( $order, $intent_id, $charge_id, $balance_transaction_id );
		} else {
			$note_type  = PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS;
			$candidates = $this->note_service->format_capture_success_note_candidates( $order, $intent_id, $charge_id, $balance_transaction_id );
			// The client's capture attaches the exchange rate and the fee the capture response carries (class-wc-payment-gateway-wcpay.php:4038-4041, class-wc-payments-order-service.php:1681).
			$meta = array_merge(
				$this->order_data_service->get_settlement_exchange_rate_order_meta( $order, $charge, $this->account_service->get_account_default_currency() ),
				WooPaymentsOrderEffects::attached_fee_meta( $charge )
			);
		}

		// The terminal capture holds the order payment lock.
		wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class )->apply_and_schedule_fee_details(
			$order,
			new PaymentLifecycleEvent( $status, $intent_id, $meta, array(), $candidates[0], $note_type, $candidates )
		);
	}

	/**
	 * Get the payment lifecycle service.
	 *
	 * @return OrderPaymentLifecycleService
	 */
	private function get_lifecycle_service(): OrderPaymentLifecycleService {
		return wc_get_container()->get( OrderPaymentLifecycleService::class );
	}

	/**
	 * Persist terminal payment state and complete the order.
	 *
	 * @param WC_Order            $order     Order.
	 * @param array<string,mixed> $intent    Captured or already-succeeded intent.
	 * @param string              $intent_id Intent ID.
	 */
	private function mark_terminal_payment_completed( WC_Order $order, array $intent, string $intent_id ): void {
		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$charge = WooPaymentsIntentCodec::latest_charge( $intent );

		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_payment_method_title( __( 'WooCommerce In-Person Payments', 'woocommerce' ) );
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_intention_status', $status );
		$order->update_meta_data( '_wcpay_mode', $this->account_service->get_order_mode() );
		$order->update_meta_data( 'receipt_url', get_rest_url( null, $this->route_namespace . '/payments/readers/receipts/' . $intent_id ) );
		$order->update_meta_data( '_wcpay_fraud_meta_box_type', 'terminal_payment' );

		$ipp_channel = $this->get_ipp_channel_from_intent( $intent );
		if ( '' !== $ipp_channel ) {
			$order->update_meta_data( '_wcpay_ipp_channel', $ipp_channel );
		}

		if ( isset( $intent['currency'] ) ) {
			// Plugin 11.1.0 stores the intent model's uppercased currency (class-wc-rest-payments-orders-controller.php:215, class-wc-payments-api-payment-intention.php:93).
			$order->update_meta_data( '_wcpay_intent_currency', strtoupper( (string) $intent['currency'] ) );
		}

		$payment_method_id = $this->get_result_payment_method_id( $intent, $charge );
		if ( '' !== $payment_method_id ) {
			$order->update_meta_data( '_payment_method_id', $payment_method_id );
		}

		if ( isset( $charge['id'] ) ) {
			$order->update_meta_data( '_charge_id', (string) $charge['id'] );
		}

		if ( isset( $charge['balance_transaction']['id'] ) ) {
			$order->update_meta_data( '_wcpay_payment_transaction_id', (string) $charge['balance_transaction']['id'] );
		}

		$this->apply_payment_method_details( $order, $charge );

		/**
		 * Filters the order status applied after a successful terminal payment.
		 *
		 * @since 11.0.0
		 *
		 * @param string $order_status Order status.
		 */
		$order_status = apply_filters( 'wcpay_terminal_payment_completed_order_status', 'completed' );
		if ( 'payment_complete' === $order_status ) {
			$order->payment_complete( $intent_id );
		} elseif ( is_string( $order_status ) && '' !== $order_status ) {
			$order->update_status( $order_status );
		}

		$order->save();
	}

	/**
	 * Save the card the reader generated for the order's subscriptions, so they renew automatically.
	 *
	 * Mirrors client 11.1.0 capture_terminal_payment() (class-wc-rest-payments-orders-controller.php:256-280): the
	 * generated card becomes the customer's token on the order and its subscriptions, which switch to WooPayments and
	 * leave manual renewal unless the store requires it. A wallet payment has no generated card and changes nothing.
	 * A card that cannot be saved for a registered customer fails the request as the client's uncaught fetch error does.
	 *
	 * @param WC_Order            $order  Order.
	 * @param array<string,mixed> $intent Intent read before the capture.
	 * @throws RuntimeException When the generated card cannot be saved for a registered customer.
	 */
	private function save_generated_card_for_subscriptions( WC_Order $order, array $intent ): void {
		$generated_card = WooPaymentsIntentCodec::latest_charge( $intent )['payment_method_details']['card_present']['generated_card'] ?? null;
		if ( ! is_string( $generated_card ) || '' === $generated_card ) {
			return;
		}

		if ( ! function_exists( 'wcs_order_contains_subscription' ) || ! function_exists( 'wcs_get_subscriptions_for_order' ) || ! function_exists( 'wcs_is_manual_renewal_required' ) || ! wcs_order_contains_subscription( $order->get_id() ) ) {
			return;
		}

		$token_service = wc_get_container()->get( WooPaymentsTokenService::class );
		$token         = $token_service->get_or_create_token_for_user( $generated_card, $order->get_customer_id() );
		if ( ! $token instanceof WC_Payment_Token ) {
			// A guest order has no customer to save the card for; anything else means the payment method could not be read.
			if ( 0 < $order->get_customer_id() ) {
				throw new RuntimeException( 'The card the reader generated could not be saved for the subscriptions.' );
			}

			return;
		}

		$token_service->attach_token_to_order( $order, $token );
		foreach ( wcs_get_subscriptions_for_order( $order ) as $subscription ) {
			$active_token = $token_service->get_active_token_for_order( $subscription );
			if ( ! $active_token instanceof WC_Payment_Token || $active_token->get_id() !== $token->get_id() ) {
				$subscription->add_payment_token( $token );
			}

			$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
			if ( ! wcs_is_manual_renewal_required() ) {
				$subscription->set_requires_manual_renewal( false );
			}
			$subscription->save();
		}
	}

	/**
	 * Get the IPP channel from a PaymentIntent, restricted to the channels the plugin recognizes.
	 *
	 * @param array<string,mixed> $intent Intent.
	 * @return string Validated channel, or an empty string.
	 */
	private function get_ipp_channel_from_intent( array $intent ): string {
		$metadata         = $this->get_intent_metadata( $intent );
		$ipp_channel      = $metadata['ipp_channel'] ?? '';
		$allowed_channels = array( 'mobile_pos', 'mobile_store_management' );

		return is_string( $ipp_channel ) && in_array( $ipp_channel, $allowed_channels, true ) ? $ipp_channel : '';
	}

	/**
	 * Build the failure-note message for a failed capture call.
	 *
	 * An amount-too-small decline carries the provider minimum; the plugin
	 * appends the formatted minimum to the message before noting the order.
	 *
	 * @param WooPaymentsApiException $exception Capture exception.
	 * @return string
	 */
	private function get_capture_failure_note_message( WooPaymentsApiException $exception ): string {
		return WooPaymentsIntentCodec::with_minimum_capture_amount(
			$exception->getMessage(),
			WooPaymentsIntentCodec::amount_too_small_details( $exception->get_error_code(), $exception )
		);
	}

	/**
	 * Reconcile a terminal capture that failed with an API error.
	 *
	 * Mirrors the plugin's capture_charge: re-fetch the intent to catch an
	 * authorization that already expired (the site may have missed the
	 * charge.expired webhook). A canceled intent fails the order with the
	 * expired note; anything else records the plain capture failure.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent ID.
	 * @param string   $message   Capture error message.
	 */
	private function reconcile_failed_terminal_capture( WC_Order $order, string $intent_id, string $message ): void {
		try {
			$intent = $this->api_client->get_payment_intention( $intent_id );
		} catch ( WooPaymentsApiException $fetch_exception ) {
			$this->record_failed_terminal_capture( $order, $intent_id, $message );

			return;
		}

		if ( 'canceled' === (string) ( $intent['status'] ?? '' ) ) {
			$this->record_expired_terminal_capture( $order, $intent_id );

			return;
		}

		$this->record_failed_terminal_capture( $order, $intent_id, $message );
	}

	/**
	 * Record a failed terminal capture: failure note, still-capturable authorization.
	 *
	 * The order keeps its status, matching the plugin's mark_payment_capture_failed.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent ID.
	 * @param string   $message   Capture error message.
	 */
	private function record_failed_terminal_capture( WC_Order $order, string $intent_id, string $message ): void {
		$charge_id       = (string) $order->get_meta( '_charge_id', true );
		$note_candidates = $this->note_service->format_capture_failed_note_candidates( $order, $intent_id, $charge_id, $message );

		$this->note_service->add_note_once(
			$order,
			$note_candidates[0],
			'',
			$note_candidates,
			static function () use ( $order ): void {
				if ( 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ) ) {
					$order->update_meta_data( '_wcpay_fraud_meta_box_type', 'review_failed' );
				}

				$order->update_meta_data( '_intention_status', 'requires_capture' );
				$order->save();
			}
		);
	}

	/**
	 * Record an expired-authorization terminal capture: failed order, expired note.
	 *
	 * Matches the plugin's mark_payment_capture_expired and the charge.expired webhook.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $intent_id Intent ID.
	 */
	private function record_expired_terminal_capture( WC_Order $order, string $intent_id ): void {
		$charge_id       = (string) $order->get_meta( '_charge_id', true );
		$note_candidates = $this->note_service->format_capture_expired_note_candidates( $intent_id, $charge_id );

		$this->note_service->add_note_once(
			$order,
			$note_candidates[0],
			'',
			$note_candidates,
			static function () use ( $order ): void {
				if ( 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ) ) {
					$order->update_meta_data( '_wcpay_fraud_meta_box_type', 'review_expired' );
				}

				$order->update_meta_data( '_intention_status', 'canceled' );
				$order->update_status( 'failed' );
			}
		);
	}

	/**
	 * Apply payment method details from a charge to order meta.
	 *
	 * @param WC_Order            $order  Order.
	 * @param array<string,mixed> $charge Charge.
	 */
	private function apply_payment_method_details( WC_Order $order, array $charge ): void {
		$payment_method_details = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] ) ? $charge['payment_method_details'] : array();
		if ( empty( $payment_method_details ) ) {
			return;
		}

		$encoded_payment_method_details = wp_json_encode( $payment_method_details );
		if ( false !== $encoded_payment_method_details ) {
			$order->update_meta_data( '_wcpay_payment_method_details', $encoded_payment_method_details );
			$order->update_meta_data( '_wcpay_raw_payment_method_details', $encoded_payment_method_details );
		}

		$card_details = array();
		if ( isset( $payment_method_details['card'] ) && is_array( $payment_method_details['card'] ) ) {
			$card_details = $payment_method_details['card'];
		} elseif ( isset( $payment_method_details['card_present'] ) && is_array( $payment_method_details['card_present'] ) ) {
			$card_details = $payment_method_details['card_present'];
		}

		if ( isset( $card_details['last4'] ) ) {
			$order->update_meta_data( 'last4', (string) $card_details['last4'] );
		}

		if ( isset( $card_details['brand'] ) ) {
			$order->update_meta_data( '_card_brand', (string) $card_details['brand'] );
		}
	}

	/**
	 * Get the payment method ID from an intent or charge.
	 *
	 * @param array<string,mixed> $intent Intent.
	 * @param array<string,mixed> $charge Charge.
	 * @return string
	 */
	private function get_result_payment_method_id( array $intent, array $charge ): string {
		if ( isset( $intent['payment_method'] ) && is_string( $intent['payment_method'] ) ) {
			return $intent['payment_method'];
		}

		if ( isset( $intent['payment_method'] ) && is_array( $intent['payment_method'] ) && isset( $intent['payment_method']['id'] ) ) {
			return (string) $intent['payment_method']['id'];
		}

		if ( isset( $charge['payment_method'] ) ) {
			return (string) $charge['payment_method'];
		}

		return '';
	}

	/**
	 * Get a string parameter from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @param string          $key     Parameter key.
	 * @return string
	 */
	private function get_request_string( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Tell whether a provider ID is safe for route interpolation.
	 *
	 * @param string $id Provider ID.
	 * @return bool
	 */
	private function is_valid_provider_id( string $id ): bool {
		return '' !== $id && (bool) preg_match( '/^[^\/\\\\%]+$/', $id );
	}

	/**
	 * Get the WooPayments logger.
	 *
	 * @return WooPaymentsLogger
	 */
	private function get_logger(): WooPaymentsLogger {
		return wc_get_container()->get( WooPaymentsLogger::class );
	}

	/**
	 * Build a generic server error.
	 *
	 * @return WP_Error
	 */
	private function server_error(): WP_Error {
		return new WP_Error( 'wcpay_server_error', __( 'Unexpected server error', 'woocommerce' ), array( 'status' => 500 ) );
	}
}
