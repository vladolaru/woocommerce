<?php
/**
 * WooPaymentsRedirectReturnController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
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
	 * Initialize the controller.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter      $arbiter            Runtime owner arbiter.
	 * @param WooPaymentsCheckoutAjaxController $confirmation_owner Shared intent confirmation owner.
	 * @param WooPaymentsApiClient              $api_client         Native WooPayments API client.
	 * @param WooPaymentsTokenService           $token_service      Native WooPayments token service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsCheckoutAjaxController $confirmation_owner, WooPaymentsApiClient $api_client, WooPaymentsTokenService $token_service ): void {
		$this->arbiter            = $arbiter;
		$this->confirmation_owner = $confirmation_owner;
		$this->api_client         = $api_client;
		$this->token_service      = $token_service;
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

		$is_payment_intent = 0.0 < (float) $order->get_total();
		if ( $is_payment_intent && ! $this->order_matches_intent( $order, $intent_id ) ) {
			$this->log_intent_mismatch( $intent_id, $order );
			return;
		}

		try {
			$intent = $is_payment_intent
				? $this->api_client->get_payment_intention( $intent_id )
				: $this->api_client->get_setup_intention( $intent_id );

			if ( ! $this->fetched_intent_matches_request( $intent, $intent_id ) ) {
				$this->log_error( sprintf( 'Native WooPayments redirect fetched an unexpected intent for requested intent %s.', $intent_id ) );
				return;
			}

			$fresh_order = $this->reread_order_authoritatively( $order );

			if ( ! $this->is_native_woopayments_order( $fresh_order ) ) {
				return;
			}

			if ( $is_payment_intent && ! $this->order_matches_intent( $fresh_order, $intent_id ) ) {
				$this->log_intent_mismatch( $intent_id, $fresh_order );
				return;
			}

			if ( $fresh_order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
				return;
			}

			if ( $is_payment_intent && ! $this->intent_matches_order( $intent, $fresh_order ) ) {
				$this->log_intent_mismatch( $intent_id, $fresh_order );
				return;
			}

			$this->confirmation_owner->confirm_fetched_intent_for_order(
				$fresh_order,
				$intent,
				'yes' === $this->get_query_string( 'save_payment_method' )
			);

			if ( null !== WC()->cart ) {
				WC()->cart->empty_cart();
			}
		} catch ( Throwable $exception ) {
			$this->log_error(
				sprintf(
					'Error completing native WooPayments redirect return for order %1$d: %2$s',
					$order->get_id(),
					$exception->getMessage()
				)
			);
		}
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
	 * Tell whether the requested intent is still current for a positive-total order.
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
	 * Log a redirect-return failure.
	 *
	 * @param string $message Log message.
	 */
	private function log_error( string $message ): void {
		wc_get_logger()->error( $message, array( 'source' => 'payment-info' ) );
	}
}
