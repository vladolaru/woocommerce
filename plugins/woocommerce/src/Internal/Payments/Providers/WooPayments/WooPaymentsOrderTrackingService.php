<?php
/**
 * WooPaymentsOrderTrackingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Tokens;

/**
 * Sends WooPayments card orders to the platform for Sift order tracking, through Action Scheduler, when they are
 * created or updated while Sift is enabled.
 *
 * Its hooks and order meta keep the WooPayments extension's names, so jobs it queued and orders it tracked carry over
 * after the switch.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsOrderTrackingService implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook that tracks a new order.
	 *
	 * @var string
	 */
	const TRACK_NEW_ORDER_ACTION = 'wcpay_track_new_order';

	/**
	 * Action Scheduler hook that tracks an order update.
	 *
	 * @var string
	 */
	const TRACK_UPDATE_ORDER_ACTION = 'wcpay_track_update_order';

	/**
	 * Order meta marking that the order's creation was tracked.
	 *
	 * @var string
	 */
	const NEW_ORDER_TRACKING_COMPLETE_META_KEY = '_new_order_tracking_complete';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * WooPayments API client.
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
	 * WooPayments fraud service.
	 *
	 * @var WooPaymentsFraudService
	 */
	private WooPaymentsFraudService $fraud_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter         $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsActionSchedulerService $scheduler       Action Scheduler service.
	 * @param WooPaymentsApiClient              $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService         $account_service WooPayments account service.
	 * @param WooPaymentsFraudService           $fraud_service   WooPayments fraud service.
	 */
	final public function init(
		WooPaymentsRuntimeArbiter $arbiter,
		WooPaymentsActionSchedulerService $scheduler,
		WooPaymentsApiClient $api_client,
		WooPaymentsAccountService $account_service,
		WooPaymentsFraudService $fraud_service
	): void {
		$this->arbiter         = $arbiter;
		$this->scheduler       = $scheduler;
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
		$this->fraud_service   = $fraud_service;
	}

	/**
	 * Register the order tracking handlers while the built-in WooPayments owns payments.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_action( 'woocommerce_update_order', array( $this, 'handle_woocommerce_update_order' ), 10, 2 );
		add_action( self::TRACK_NEW_ORDER_ACTION, array( $this, 'handle_wcpay_track_new_order' ), 10, 1 );
		add_action( self::TRACK_UPDATE_ORDER_ACTION, array( $this, 'handle_wcpay_track_update_order' ), 10, 1 );

		if ( class_exists( 'WC_Subscriptions_Data_Copier' ) ) {
			add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'handle_wc_subscriptions_renewal_order_data' ), 10, 3 );
		} else {
			add_filter( 'wcs_renewal_order_meta_query', array( $this, 'handle_wcs_renewal_order_meta_query' ), 10, 3 );
		}
	}

	/**
	 * Bring a subscription order's payment method and customer IDs up to date before it is tracked.
	 *
	 * Client 11.1.0 `maybe_schedule_subscription_order_tracking()` (trait-wc-payment-gateway-wcpay-subscriptions.php:1108-1160),
	 * run first on every order update: the order's latest token wins; without one, an order with no payment method ID takes
	 * its parent's, or is left alone; a missing customer ID comes from the parent. This code runs it for the card gateway's
	 * orders only, where client 11.1.0 writes these WooPayments IDs onto any gateway's order.
	 *
	 * @param WC_Order $order Order being updated.
	 */
	private function maybe_repair_subscription_order_tracking_meta( WC_Order $order ): void {
		if ( ! WooPaymentsSubscriptionMethodPolicy::is_subscriptions_available() ) {
			return;
		}

		$save_meta         = false;
		$token_ids         = $order->get_payment_tokens();
		$token_id          = end( $token_ids );
		$token             = $token_id ? WC_Payment_Tokens::get( $token_id ) : null;
		$payment_method_id = $this->get_order_meta_string( $order, '_payment_method_id' );

		if ( ! $token instanceof WC_Payment_Token ) {
			if ( '' === $payment_method_id ) {
				$parent                   = $order->get_parent_id() ? wc_get_order( $order->get_parent_id() ) : false;
				$parent_payment_method_id = $parent instanceof WC_Order ? $this->get_order_meta_string( $parent, '_payment_method_id' ) : '';
				if ( '' === $parent_payment_method_id ) {
					return;
				}
				$order->update_meta_data( '_payment_method_id', $parent_payment_method_id );
				$save_meta = true;
			}
		} elseif ( $payment_method_id !== $token->get_token() ) {
			$order->update_meta_data( '_payment_method_id', $token->get_token() );
			$save_meta = true;
		}

		if ( '' === $this->get_order_meta_string( $order, '_stripe_customer_id' ) ) {
			$parent             = $order->get_parent_id() ? wc_get_order( $order->get_parent_id() ) : false;
			$parent_customer_id = $parent instanceof WC_Order ? $this->get_order_meta_string( $parent, '_stripe_customer_id' ) : '';
			if ( '' !== $parent_customer_id ) {
				$order->update_meta_data( '_stripe_customer_id', $parent_customer_id );
				$save_meta = true;
			}
		}

		if ( $save_meta ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Handle the woocommerce_update_order hook.
	 *
	 * @internal
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object.
	 */
	public function handle_woocommerce_update_order( $order_id, $order = null ): void {
		if ( doing_action( self::TRACK_NEW_ORDER_ACTION ) || doing_action( self::TRACK_UPDATE_ORDER_ACTION ) ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Client 11.1.0 tracks Sift orders for the main card gateway only, an exact gateway-ID match, never the split
		// sub-gateways (class-wc-payment-gateway-wcpay.php:4505), so Sift trains on the same orders here.
		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$this->maybe_repair_subscription_order_tracking_meta( $order );

		if ( ! $this->is_sift_tracking_enabled() ) {
			return;
		}

		if ( '' === $this->get_order_meta_string( $order, '_payment_method_id' ) ) {
			return;
		}

		$this->scheduler->schedule_job(
			'yes' === $this->get_order_meta_string( $order, self::NEW_ORDER_TRACKING_COMPLETE_META_KEY )
				? self::TRACK_UPDATE_ORDER_ACTION
				: self::TRACK_NEW_ORDER_ACTION,
			array(
				'order_id' => (int) $order->get_id(),
			),
			// Five seconds out, as client 11.1.0 schedules it (class-wc-payment-gateway-wcpay.php:4513-4524).
			time() + 5
		);
	}

	/**
	 * Handle the wcpay_track_new_order action.
	 *
	 * @internal
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_wcpay_track_new_order( $order_id ): void {
		$this->track_order( (int) $order_id, false );
	}

	/**
	 * Handle the wcpay_track_update_order action.
	 *
	 * @internal
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_wcpay_track_update_order( $order_id ): void {
		$this->track_order( (int) $order_id, true );
	}

	/**
	 * Handle the wc_subscriptions_renewal_order_data filter.
	 *
	 * @internal
	 *
	 * @param mixed $order_data Renewal order data.
	 * @param mixed $to_order   Renewal order.
	 * @param mixed $from_order Source order.
	 * @return mixed
	 */
	public function handle_wc_subscriptions_renewal_order_data( $order_data, $to_order = null, $from_order = null ) {
		unset( $to_order, $from_order );
		if ( ! is_array( $order_data ) ) {
			return $order_data;
		}

		unset( $order_data[ self::NEW_ORDER_TRACKING_COMPLETE_META_KEY ] );

		return $order_data;
	}

	/**
	 * Handle the wcs_renewal_order_meta_query filter.
	 *
	 * @internal
	 *
	 * @param mixed $order_meta_query Renewal order metadata SQL query.
	 * @param mixed $to_order         Renewal order.
	 * @param mixed $from_order       Source order.
	 * @return mixed
	 */
	public function handle_wcs_renewal_order_meta_query( $order_meta_query, $to_order = null, $from_order = null ) {
		unset( $to_order, $from_order );
		if ( ! is_string( $order_meta_query ) ) {
			return $order_meta_query;
		}

		$order_meta_query .= sprintf( " AND `meta_key` NOT IN ('%s')", self::NEW_ORDER_TRACKING_COMPLETE_META_KEY );

		return $order_meta_query;
	}

	/**
	 * Track an order through the WooPayments API.
	 *
	 * @param int  $order_id  Order ID.
	 * @param bool $is_update Whether this is an update event.
	 * @return bool True when tracking succeeded.
	 */
	private function track_order( int $order_id, bool $is_update ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$payment_method_id = $this->get_order_meta_string( $order, '_payment_method_id' );
		if ( '' === $payment_method_id ) {
			return false;
		}

		$order_mode = $this->get_order_meta_string( $order, '_wcpay_mode' );
		if ( '' !== $order_mode && ! $this->is_order_mode_compatible( $order_mode ) ) {
			return false;
		}

		$response = $this->api_client->track_order(
			$this->get_order_tracking_data( $order, $payment_method_id, $order_mode ),
			$is_update
		);
		$success  = 'success' === ( $response['result'] ?? null );

		if ( $success && ! $is_update ) {
			$order->update_meta_data( self::NEW_ORDER_TRACKING_COMPLETE_META_KEY, 'yes' );
			$order->save_meta_data();
		}

		return $success;
	}

	/**
	 * Build the order tracking payload: the order's data with its WooPayments payment method, customer and mode.
	 *
	 * @param WC_Order $order             Order object.
	 * @param string   $payment_method_id Provider payment method ID.
	 * @param string   $order_mode        Persisted WooPayments mode.
	 * @return array<string,mixed>
	 */
	private function get_order_tracking_data( WC_Order $order, string $payment_method_id, string $order_mode ): array {
		return array_merge(
			$order->get_data(),
			array(
				'_payment_method_id'  => $payment_method_id,
				'_stripe_customer_id' => $this->get_order_meta_string( $order, '_stripe_customer_id' ),
				'_wcpay_mode'         => $order_mode,
			)
		);
	}

	/**
	 * Tell whether the order mode matches the current WooPayments mode.
	 *
	 * Client 11.1.0 compares the stored value with `Order_Mode::TEST` or `Order_Mode::PRODUCTION` (class-wc-payments-action-scheduler-service.php:173-179).
	 *
	 * @param string $order_mode Persisted order mode.
	 * @return bool
	 */
	private function is_order_mode_compatible( string $order_mode ): bool {
		$current_mode = $this->account_service->is_test_mode_enabled() ? WooPaymentsOrderMode::TEST : WooPaymentsOrderMode::PRODUCTION;

		return $current_mode === $order_mode;
	}

	/**
	 * Tell whether Sift order tracking is enabled.
	 *
	 * A null Sift config means a `wcpay_prepare_fraud_config` callback turned Sift off; client 11.1.0 checks it the same
	 * way (class-wc-payment-gateway-wcpay.php:4495).
	 *
	 * @return bool
	 */
	private function is_sift_tracking_enabled(): bool {
		$config = $this->fraud_service->get_fraud_services_config();

		return isset( $config['sift'] );
	}

	/**
	 * Read scalar order meta as a string.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $key   Meta key.
	 * @return string
	 */
	private function get_order_meta_string( WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}
}
