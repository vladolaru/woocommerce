<?php
/**
 * WooPaymentsRedirectReturnController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Handles native WooPayments redirect returns.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsRedirectReturnController implements RegisterHooksInterface {

	/**
	 * Checkout query flag the client adds when the fetched intent belongs to another order (client 11.1.0 gw:118).
	 *
	 * @var string
	 */
	const ORDER_MISMATCH_QUERY_FLAG = 'upe_process_redirect_order_id_mismatched';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Shared intent confirmation owner.
	 *
	 * @var WooPaymentsCheckoutAjaxController
	 */
	private WooPaymentsCheckoutAjaxController $confirmation_owner;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Native WooPayments token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * Native WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * Payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Native WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * Initialize the controller.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter      $arbiter            Runtime owner arbiter.
	 * @param WooPaymentsCheckoutAjaxController $confirmation_owner Shared intent confirmation owner.
	 * @param WooPaymentsApiClient              $api_client         Native WooPayments API client.
	 * @param WooPaymentsTokenService           $token_service      Native WooPayments token service.
	 * @param WooPaymentsCustomerService        $customer_service   Native WooPayments customer service.
	 * @param OrderPaymentLifecycleService      $lifecycle_service  Payment lifecycle service.
	 * @param WooPaymentsOrderNoteService       $note_service       Native WooPayments order note service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsCheckoutAjaxController $confirmation_owner, WooPaymentsApiClient $api_client, WooPaymentsTokenService $token_service, WooPaymentsCustomerService $customer_service, OrderPaymentLifecycleService $lifecycle_service, WooPaymentsOrderNoteService $note_service ): void {
		$this->arbiter            = $arbiter;
		$this->confirmation_owner = $confirmation_owner;
		$this->api_client         = $api_client;
		$this->token_service      = $token_service;
		$this->customer_service   = $customer_service;
		$this->lifecycle_service  = $lifecycle_service;
		$this->note_service       = $note_service;
	}

	/**
	 * Register the redirect-return callback.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'wp', array( $this, 'handle_wp' ) ) ) {
			add_action( 'wp', array( $this, 'handle_wp' ) );
		}
	}

	/**
	 * Handle the wp hook for redirect returns.
	 *
	 * @internal
	 */
	public function handle_wp(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( is_payment_methods_page() ) {
			if ( $this->is_successful_setup_intent_return() ) {
				wc_add_notice( __( 'Payment method successfully added.', 'woocommerce' ) );
				$this->token_service->clear_cached_payment_methods_for_user( get_current_user_id() );
			}

			return;
		}

		if ( ! is_order_received_page() ) {
			return;
		}

		if ( OrderPaymentStore::GATEWAY_ID !== $this->get_query_string( 'wc_payment_method' ) ) {
			return;
		}

		// The order-received URL also accepts Create Account POSTs that retain redirect query arguments.
		$request_method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( ! is_string( $request_method ) || 'GET' !== strtoupper( $request_method ) ) {
			return;
		}

		$nonce = $this->get_query_string( '_wpnonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wcpay_process_redirect_order_nonce' ) ) {
			return;
		}

		$intent_id = $this->get_redirect_intent_id();
		$order_id  = absint( get_query_var( 'order-received' ) );
		$order_key = $this->get_query_string( 'key' );
		if ( '' === $intent_id || 0 >= $order_id || '' === $order_key ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return;
		}

		if ( ! $this->is_native_woopayments_order( $order ) || $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
			return;
		}

		// Authorized divergence: client 11.1.0 binds only positive totals (gw:2302-2306, pending woocommerce-payments#6575);
		// native stores every SetupIntent in `_intent_id` before the shopper is redirected (decided divergence, ledger V649).
		$is_payment_intent = 0.0 < (float) $order->get_total();
		if ( ! $this->order_matches_intent( $order, $intent_id ) ) {
			$this->log_intent_mismatch( $intent_id, $order );
			return;
		}

		try {
			$intent = $is_payment_intent
				? $this->api_client->get_payment_intention( $intent_id )
				: $this->api_client->get_setup_intention( $intent_id );
		} catch ( Throwable $exception ) {
			$this->log_fetch_error( $order, $intent_id, $exception );

			// Decided divergence (money hazard): client gw:2428-2444 fails the order read at request start, and its paid check
			// (os:2863) misses on-hold, so a webhook that authorized the payment during the fetch leaves a charge on a failed order.
			if ( ! $this->fail_order_unless_settled( $order, $intent_id, $exception->getMessage() ) ) {
				return;
			}

			$this->redirect_to_checkout( $this->get_shopper_message_for_fetch_error( $exception ) );
			return;
		}

		if ( ! $this->fetched_intent_matches_request( $intent, $intent_id ) ) {
			$this->log_error(
				sprintf( 'Native WooPayments redirect fetched an unexpected intent for requested intent %s.', $intent_id ),
				array(
					'order_id'  => $order->get_id(),
					'intent_id' => $intent_id,
				)
			);
			$this->redirect_to_checkout_after_order_mismatch();
			return;
		}

		$fresh_order = $this->reread_order_authoritatively( $order );

		if ( ! $this->is_native_woopayments_order( $fresh_order ) ) {
			return;
		}

		if ( ! $this->order_matches_intent( $fresh_order, $intent_id ) ) {
			$this->log_intent_mismatch( $intent_id, $fresh_order );
			return;
		}

		if ( $fresh_order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
			return;
		}

		if ( $is_payment_intent && ! $this->intent_matches_order( $intent, $fresh_order ) ) {
			$this->log_intent_mismatch( $intent_id, $fresh_order );
			$this->redirect_to_checkout_after_order_mismatch();
			return;
		}

		if ( ! $is_payment_intent && ! $this->setup_intent_customer_matches_order( $intent, $fresh_order ) ) {
			$this->log_intent_mismatch( $intent_id, $fresh_order );
			$this->redirect_to_checkout_after_order_mismatch();
			return;
		}

		try {
			$this->confirmation_owner->confirm_fetched_intent_for_order(
				$fresh_order,
				$intent,
				'yes' === $this->get_query_string( 'save_payment_method' ),
				true // The redirect return: it fails on `last_setup_error` and completes the order despite a token-save error.
			);

			if ( null !== WC()->cart ) {
				WC()->cart->empty_cart();
			}
		} catch ( Throwable $exception ) {
			$this->log_return_error( $order, $exception );

			// Client gw:2376-2382: of these outcomes only an intent error throws, and its catch fails the order with a note.
			if ( $this->intent_has_error( $intent, $is_payment_intent ) ) {
				if ( $is_payment_intent ) {
					// The PaymentIntent confirmation failed the order without a note; a SetupIntent's confirmation writes this note itself.
					$this->fail_order( $fresh_order, $intent_id, null );
				}
				$this->redirect_to_checkout( __( "We're not able to process this payment. Please try again later.", 'woocommerce' ) );
			}
		}
	}

	/**
	 * Tell whether a fetched intent carries the error the client's redirect return fails on (gw:2354, 2374).
	 *
	 * @param array<string,mixed> $intent            Fetched intent.
	 * @param bool                $is_payment_intent Whether the intent is a PaymentIntent.
	 * @return bool
	 */
	private function intent_has_error( array $intent, bool $is_payment_intent ): bool {
		return ! empty( $intent[ $is_payment_intent ? 'last_payment_error' : 'last_setup_error' ] );
	}

	/**
	 * Fail the order with the "UPE payment failed" note, as the client's catch does (gw:2435-2442, os:463-478, 2106-2127).
	 *
	 * @param WC_Order    $order             Order object.
	 * @param string      $intent_id         Requested intent ID.
	 * @param string|null $exception_message Message of the exception that ended the return, or null for an intent error.
	 */
	private function fail_order( WC_Order $order, string $intent_id, ?string $exception_message ): void {
		$event = $this->build_failure_event( $order, $intent_id, $exception_message );

		try {
			$this->lifecycle_service->apply( $order, $event, new WooPaymentsPersistenceProfile() );
		} catch ( Throwable $failure ) {
			$this->log_return_error( $order, $failure );
		}
	}

	/**
	 * Fail the order after a failed intent fetch, unless a fresh read under the order payment lock shows it is settled.
	 *
	 * Webhooks write the order status under the same lock, so an on-hold written just before this claims the lock is seen
	 * here (review 33 F1). The lifecycle's own late-failure check stays on paid statuses only: a Multibanco voucher expiry
	 * must still move an on-hold order to failed.
	 *
	 * When another holder has the lock, most likely a webhook writing this payment's status, the order is left to it and
	 * the shopper stays on order-received: no notice, no failure, and an always-on warning naming the holder's operation,
	 * lock value and age (review 35 F4). The client fails nothing there but sends the shopper to checkout (gw:2428-2456),
	 * where a resubmit creates a new order and can authorize the card again.
	 *
	 * @param WC_Order $order             Order object.
	 * @param string   $intent_id         Requested intent ID.
	 * @param string   $exception_message Message of the fetch failure.
	 * @return bool Whether the shopper goes back to checkout; false when the order is settled, bound to another intent or
	 *              locked by another holder.
	 */
	private function fail_order_unless_settled( WC_Order $order, string $intent_id, string $exception_message ): bool {
		$persistence_profile = new WooPaymentsPersistenceProfile();
		$order_payment_store = wc_get_container()->get( OrderPaymentStore::class );
		$lock_token          = $order_payment_store->claim_order_payment_lock_for_operation( $order, $persistence_profile, $intent_id, 'payment status update' );
		if ( null === $lock_token ) {
			$order_payment_store->log_order_payment_lock_refusal(
				$order,
				$persistence_profile,
				'redirect return failure',
				'native-payments-webhook',
				array(
					'payment_reference' => $intent_id,
					'event_type'        => PaymentLifecycleEvent::STATUS_FAILED,
					'reason'            => 'order_locked',
				)
			);
			return false;
		}

		try {
			$fresh_order = $this->reread_order_authoritatively( $order );
			if ( $fresh_order->has_status( array( 'processing', 'completed', 'on-hold' ) ) || ! $this->order_matches_intent( $fresh_order, $intent_id ) ) {
				return false;
			}

			$this->lifecycle_service->apply_unlocked( $fresh_order, $this->build_failure_event( $fresh_order, $intent_id, $exception_message ), $persistence_profile );
		} catch ( Throwable $failure ) {
			$this->log_return_error( $order, $failure );
		} finally {
			$order_payment_store->release_order_payment_lock( $order, $persistence_profile, $lock_token );
		}

		return true;
	}

	/**
	 * Build the failure event with the "UPE payment failed" note.
	 *
	 * @param WC_Order    $order             Order object.
	 * @param string      $intent_id         Requested intent ID.
	 * @param string|null $exception_message Message of the exception that ended the return, or null for an intent error.
	 * @return PaymentLifecycleEvent
	 */
	private function build_failure_event( WC_Order $order, string $intent_id, ?string $exception_message ): PaymentLifecycleEvent {
		$note_candidates = $this->note_service->format_redirect_payment_failed_note_candidates( $order, $intent_id, $exception_message );

		return new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_FAILED,
			$intent_id,
			array(),
			array(),
			$note_candidates[0],
			PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED,
			$note_candidates
		);
	}

	/**
	 * Get the shopper notice for a failed intent fetch (client get_filtered_error_message(), utils:769-830).
	 *
	 * @param Throwable $exception Fetch failure.
	 * @return string
	 */
	private function get_shopper_message_for_fetch_error( Throwable $exception ): string {
		if ( $exception instanceof WooPaymentsApiException ) {
			return WooPaymentsErrorMessages::get_shopper_message( $exception->get_error_type(), $exception->get_error_code(), $exception->get_decline_code(), $exception->getMessage() );
		}

		return __( "We're not able to process this payment. Please try again later.", 'woocommerce' );
	}

	/**
	 * Return the shopper to checkout for an intent that belongs to another order, leaving the order as it is (client gw:2430-2455).
	 */
	private function redirect_to_checkout_after_order_mismatch(): void {
		$this->redirect_to_checkout(
			__( "We're not able to process this payment due to the order ID mismatch. Please try again later.", 'woocommerce' ),
			true
		);
	}

	/**
	 * Add an error notice and redirect to checkout before core's template_redirect cart clearing (client gw:2448-2455).
	 *
	 * @param string $notice         Shopper error notice.
	 * @param bool   $order_mismatch Whether to add the order-mismatch query flag.
	 */
	private function redirect_to_checkout( string $notice, bool $order_mismatch = false ): void {
		wc_add_notice( $notice, 'error' );

		$redirect_url = wc_get_checkout_url();
		if ( $order_mismatch ) {
			$redirect_url = add_query_arg( self::ORDER_MISMATCH_QUERY_FLAG, 'yes', $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Log an error that ended a redirect return, as client 11.1.0 gw:2429 does with Logger::exception().
	 *
	 * @param WC_Order  $order     Order object.
	 * @param Throwable $exception Error.
	 */
	private function log_return_error( WC_Order $order, Throwable $exception ): void {
		wc_get_container()->get( WooPaymentsLogger::class )->log_throwable(
			'Error occurred during the redirect payment process. Exception: ' . $exception->getMessage(),
			$exception,
			array( 'order_id' => $order->get_id() )
		);
	}

	/**
	 * Log a failed intent fetch.
	 *
	 * A platform error follows the client's logging setting (gw:2429). Any other throwable is a code or
	 * environment fault, so it is always logged with its class (decided divergence, monitor ruling 2026-10-04).
	 *
	 * @param WC_Order  $order     Order object.
	 * @param string    $intent_id Requested intent ID.
	 * @param Throwable $exception Fetch failure.
	 */
	private function log_fetch_error( WC_Order $order, string $intent_id, Throwable $exception ): void {
		if ( $exception instanceof WooPaymentsApiException ) {
			$this->log_return_error( $order, $exception );
			return;
		}

		wc_get_logger()->error(
			sprintf(
				'Error fetching the intent for native WooPayments redirect return for order %1$d: %2$s: %3$s',
				$order->get_id(),
				get_class( $exception ),
				$exception->getMessage()
			),
			array(
				'source'    => WooPaymentsLogger::SOURCE,
				'order_id'  => $order->get_id(),
				'intent_id' => $intent_id,
			)
		);
	}

	/**
	 * Tell whether the request is a successful SetupIntent return.
	 *
	 * @return bool
	 */
	private function is_successful_setup_intent_return(): bool {
		return '' !== $this->get_query_string( 'setup_intent' )
			&& '' !== $this->get_query_string( 'setup_intent_client_secret' )
			&& 'succeeded' === $this->get_query_string( 'redirect_status' );
	}

	/**
	 * Reread an order after invalidating only its relevant persistence caches.
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Order
	 */
	private function reread_order_authoritatively( WC_Order $order ): WC_Order {
		$order_id = $order->get_id();
		clean_post_cache( $order_id );
		wp_cache_delete( WC_Order::generate_meta_cache_key( $order_id, 'orders' ), 'orders' );

		/**
		 * Active order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $order->get_data_store();
		if ( is_callable( array( $data_store, 'clear_cached_data' ) ) ) {
			call_user_func( array( $data_store, 'clear_cached_data' ), array( $order_id ) );
		}

		$fresh_order = clone $order;
		$data_store->read( $fresh_order );
		/**
		 * Freshly read order.
		 *
		 * @var WC_Order $fresh_order
		 */
		$fresh_order->read_meta_data( true );

		return $fresh_order;
	}

	/**
	 * Tell whether an order belongs to the native WooPayments gateway family.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_native_woopayments_order( WC_Order $order ): bool {
		$payment_method = (string) $order->get_payment_method();

		return OrderPaymentStore::GATEWAY_ID === $payment_method || str_starts_with( $payment_method, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}

	/**
	 * Tell whether the requested intent is the one the order stores.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Requested intent ID.
	 * @return bool
	 */
	private function order_matches_intent( WC_Order $order, string $intent_id ): bool {
		$current_intent_id = (string) $order->get_meta( '_intent_id', true );

		return '' !== $current_intent_id && hash_equals( $current_intent_id, $intent_id );
	}

	/**
	 * Tell whether a fetched intent has the requested identity.
	 *
	 * @param array<string,mixed> $intent    Fetched intent response.
	 * @param string              $intent_id Requested intent ID.
	 * @return bool
	 */
	private function fetched_intent_matches_request( array $intent, string $intent_id ): bool {
		$fetched_intent_id = $intent['id'] ?? null;

		return is_string( $fetched_intent_id ) && hash_equals( $intent_id, $fetched_intent_id );
	}

	/**
	 * Get the redirect intent ID only when its client-secret field is also present.
	 *
	 * @return string
	 */
	private function get_redirect_intent_id(): string {
		$payment_intent        = $this->get_query_string( 'payment_intent' );
		$payment_client_secret = $this->get_query_string( 'payment_intent_client_secret' );
		if ( '' !== $payment_intent && '' !== $payment_client_secret ) {
			return $payment_intent;
		}

		$setup_intent        = $this->get_query_string( 'setup_intent' );
		$setup_client_secret = $this->get_query_string( 'setup_intent_client_secret' );

		return '' !== $setup_intent && '' !== $setup_client_secret ? $setup_intent : '';
	}

	/**
	 * Verify that PaymentIntent metadata belongs to the order.
	 *
	 * @param array<string,mixed> $intent PaymentIntent response.
	 * @param WC_Order            $order  Order object.
	 * @return bool
	 */
	private function intent_matches_order( array $intent, WC_Order $order ): bool {
		$metadata          = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$metadata_order_id = $metadata['order_id'] ?? null;

		return is_numeric( $metadata_order_id ) && $order->get_id() === intval( $metadata_order_id );
	}

	/**
	 * Tell whether a SetupIntent's customer can be the order's customer.
	 *
	 * The order's customer is its `_stripe_customer_id`, else its user's stored customer. Like add_payment_method(),
	 * this rejects only a real mismatch between two known customer IDs.
	 *
	 * @param array<string,mixed> $intent SetupIntent response.
	 * @param WC_Order            $order  Order object.
	 * @return bool
	 */
	private function setup_intent_customer_matches_order( array $intent, WC_Order $order ): bool {
		$intent_customer = WooPaymentsIntentCodec::result_customer_id( $intent, '' );
		$order_customer  = (string) $order->get_meta( '_stripe_customer_id', true );
		if ( '' === $order_customer && 0 < $order->get_user_id() ) {
			$order_customer = (string) $this->customer_service->get_persisted_customer_id_by_user_id( $order->get_user_id() );
		}

		return '' === $intent_customer || '' === $order_customer || hash_equals( $order_customer, $intent_customer );
	}

	/**
	 * Log an intent/order mismatch.
	 *
	 * @param string   $intent_id Requested intent ID.
	 * @param WC_Order $order     Order object.
	 */
	private function log_intent_mismatch( string $intent_id, WC_Order $order ): void {
		$this->log_error(
			sprintf(
				'Native WooPayments redirect intent %1$s did not match order %2$d.',
				$intent_id,
				$order->get_id()
			),
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => $intent_id,
			)
		);
	}

	/**
	 * Read a sanitized redirect query value.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	private function get_query_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The dynamic value is unslashed and sanitized below; the dedicated nonce is verified before state changes.
		$value = $_GET[ $key ] ?? '';
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$sanitized_value = wc_clean( wp_unslash( (string) $value ) );

		return is_string( $sanitized_value ) ? $sanitized_value : '';
	}

	/**
	 * Log a redirect-return failure when debug logging is on, as the client's catch does (gw:2429).
	 *
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Log context.
	 */
	private function log_error( string $message, array $context = array() ): void {
		wc_get_container()->get( WooPaymentsLogger::class )->error( $message, $context );
	}
}
