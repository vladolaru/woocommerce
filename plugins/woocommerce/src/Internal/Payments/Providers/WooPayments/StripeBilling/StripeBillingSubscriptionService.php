<?php
/**
 * StripeBillingSubscriptionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use WC_Coupon;
use WC_Order;
use WC_Order_Item;
use WC_Payment_Token;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Links WooCommerce Subscriptions subscriptions to their Stripe subscriptions and keeps them in step.
 *
 * Meta keys and payload shapes are client 11.1.0's (`includes/subscriptions/class-wc-payments-subscription-service.php`),
 * so a store can go back to the plugin.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingSubscriptionService {

	/**
	 * Subscription meta holding the Stripe subscription ID.
	 */
	public const SUBSCRIPTION_ID_META_KEY = '_wcpay_subscription_id';

	/**
	 * Subscription meta holding the Stripe subscription ID once the subscription is migrated off Stripe Billing.
	 *
	 * The migrator writes it as the `_migrated` prefix plus SUBSCRIPTION_ID_META_KEY.
	 */
	public const MIGRATED_SUBSCRIPTION_ID_META_KEY = '_migrated_wcpay_subscription_id';

	/**
	 * Subscription item meta holding the Stripe subscription item ID.
	 */
	public const SUBSCRIPTION_ITEM_ID_META_KEY = '_wcpay_subscription_item_id';

	/**
	 * Subscription meta holding the Stripe discount IDs.
	 */
	public const SUBSCRIPTION_DISCOUNT_IDS_META_KEY = '_wcpay_subscription_discount_ids';

	/**
	 * The only features a Stripe-billed subscription supports.
	 */
	private const SUPPORTED_FEATURES = array(
		'gateway_scheduled_payments',
		'multiple_subscriptions',
		'subscription_cancellation',
		'subscription_payment_method_change_admin',
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change',
		'subscription_reactivation',
		'subscription_suspension',
		'subscriptions',
	);

	/**
	 * Stripe Billing platform calls.
	 *
	 * @var StripeBillingApi
	 */
	private StripeBillingApi $api;

	/**
	 * Customer service, for the Stripe customer a subscription is billed to.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * Product service, for the Stripe products the subscription items are billed under.
	 *
	 * @var StripeBillingProductService
	 */
	private StripeBillingProductService $product_service;

	/**
	 * Invoice service, for the invoice IDs on subscriptions and orders.
	 *
	 * @var StripeBillingInvoiceService
	 */
	private StripeBillingInvoiceService $invoice_service;

	/**
	 * Module logger.
	 *
	 * @var StripeBillingLogger
	 */
	private StripeBillingLogger $logger;

	/**
	 * Features temporarily allowed, by subscription ID.
	 *
	 * @var array<int,array<string,bool>>
	 */
	private array $feature_support_exceptions = array();

	/**
	 * Whether the subscription being created comes from a customer switching to WooPayments, so it keeps its billing dates.
	 *
	 * @var bool
	 */
	private bool $is_creating_subscription_from_update_payment_method = false;

	/**
	 * Whether subscription status changes are kept from reaching Stripe, while a callback passed to `run_without_stripe_sync()` runs.
	 *
	 * @var bool
	 */
	private bool $is_stripe_sync_paused = false;

	/**
	 * Whether payment tokens added to a subscription are kept from reaching Stripe, while a callback passed to `run_without_payment_method_sync()` runs.
	 *
	 * @var bool
	 */
	private bool $is_payment_method_sync_paused = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingApi            $api              Stripe Billing platform calls.
	 * @param WooPaymentsCustomerService  $customer_service Customer service.
	 * @param StripeBillingProductService $product_service  Product service.
	 * @param StripeBillingInvoiceService $invoice_service  Invoice service.
	 * @param StripeBillingLogger         $logger           Module logger.
	 */
	final public function init( StripeBillingApi $api, WooPaymentsCustomerService $customer_service, StripeBillingProductService $product_service, StripeBillingInvoiceService $invoice_service, StripeBillingLogger $logger ): void {
		$this->api              = $api;
		$this->customer_service = $customer_service;
		$this->product_service  = $product_service;
		$this->invoice_service  = $invoice_service;
		$this->logger           = $logger;
	}

	/**
	 * Tell whether a subscription is billed by Stripe Billing: paid with WooPayments and linked to a Stripe subscription.
	 *
	 * Always false on a staging copy, so that it never acts at Stripe for the live store.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return bool
	 */
	public function is_wcpay_subscription( WC_Order $subscription ): bool {
		return ! WooPaymentsSubscriptionMethodPolicy::is_duplicate_site()
			&& WooPaymentsPersistenceProfile::GATEWAY_ID === $subscription->get_payment_method()
			&& (bool) $this->get_wcpay_subscription_id( $subscription );
	}

	/**
	 * Tell whether any subscription related to an order, of any relation, is billed by Stripe Billing.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_wcpay_subscription_order( WC_Order $order ): bool {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return false;
		}

		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
			if ( $subscription instanceof WC_Order && $this->is_wcpay_subscription( $subscription ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tag the recurring payments of an order whose subscription Stripe bills, which the platform charges the Stripe Billing fee for.
	 *
	 * Other subscription payments keep the regular context, whatever the toggle.
	 *
	 * @internal
	 *
	 * @param mixed $metadata Payment metadata sent to the platform.
	 * @param mixed $order    Order being paid.
	 * @return mixed
	 */
	public function set_stripe_billing_payment_context( $metadata, $order ) {
		if ( ! is_array( $metadata ) || ! $order instanceof WC_Order || 'regular_subscription' !== ( $metadata['payment_context'] ?? '' ) ) {
			return $metadata;
		}

		if ( $this->is_wcpay_subscription_order( $order ) ) {
			$metadata['payment_context'] = 'wcpay_subscription';
		}

		return $metadata;
	}

	/**
	 * Get the Stripe subscription ID of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 */
	public function get_wcpay_subscription_id( WC_Order $subscription ): string {
		return (string) $subscription->get_meta( self::SUBSCRIPTION_ID_META_KEY, true );
	}

	/**
	 * Get the subscription linked to a Stripe subscription.
	 *
	 * @param string $wcpay_subscription_id Stripe subscription ID.
	 * @return WC_Order|null The subscription, or null when no subscription is linked to it.
	 */
	public function get_subscription_from_wcpay_subscription_id( string $wcpay_subscription_id ): ?WC_Order {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return null;
		}

		$subscriptions = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => 1,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::SUBSCRIPTION_ID_META_KEY,
						'value' => $wcpay_subscription_id,
					),
				),
			)
		);
		$subscription  = is_array( $subscriptions ) ? reset( $subscriptions ) : false;

		return $subscription instanceof WC_Order ? $subscription : null;
	}

	/**
	 * Get the Stripe subscription of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<string,mixed>|null The Stripe subscription, or null when it has none or the platform cannot be read.
	 */
	public function get_wcpay_subscription( WC_Order $subscription ): ?array {
		$wcpay_subscription_id = $this->get_wcpay_subscription_id( $subscription );
		if ( ! $wcpay_subscription_id ) {
			return null;
		}

		try {
			return $this->api->get_subscription( $wcpay_subscription_id );
		} catch ( WooPaymentsApiException $exception ) {
			return null;
		}
	}

	/**
	 * Run a callback while subscription status changes are kept from reaching Stripe.
	 *
	 * Putting a subscription on hold then does not pause its Stripe subscription, and making it active again does not resume it.
	 * An invoice event uses it to record a renewal that Stripe already billed.
	 *
	 * @param callable $callback Callback.
	 */
	public function run_without_stripe_sync( callable $callback ): void {
		$was_paused                  = $this->is_stripe_sync_paused;
		$this->is_stripe_sync_paused = true;

		try {
			$callback();
		} finally {
			$this->is_stripe_sync_paused = $was_paused;
		}
	}

	/**
	 * Run a callback while payment tokens added to a subscription are kept from reaching Stripe.
	 *
	 * The migration off Stripe Billing uses it to set the token of a subscription whose Stripe subscription it has just cancelled.
	 *
	 * @param callable $callback Callback.
	 */
	public function run_without_payment_method_sync( callable $callback ): void {
		$was_paused                          = $this->is_payment_method_sync_paused;
		$this->is_payment_method_sync_paused = true;

		try {
			$callback();
		} finally {
			$this->is_payment_method_sync_paused = $was_paused;
		}
	}

	/**
	 * Tell whether at least one active subscription is billed by Stripe Billing; one row is read.
	 *
	 * @return bool
	 */
	public function has_active_stripe_billed_subscriptions(): bool {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return false;
		}

		$subscriptions = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => 1,
				'subscription_status'    => 'active',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::SUBSCRIPTION_ID_META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return is_countable( $subscriptions ) && count( $subscriptions ) > 0;
	}

	/**
	 * Count the subscriptions still billed by Stripe Billing, whatever their status or payment method.
	 *
	 * @return int
	 */
	public function get_stripe_billing_subscription_count(): int {
		return $this->count_subscriptions_with_meta( self::SUBSCRIPTION_ID_META_KEY );
	}

	/**
	 * Count the subscriptions migrated off Stripe Billing.
	 *
	 * @return int
	 */
	public function get_migrated_subscription_count(): int {
		return $this->count_subscriptions_with_meta( self::MIGRATED_SUBSCRIPTION_ID_META_KEY );
	}

	/**
	 * Count the subscriptions that have a meta key, with WooCommerce Subscriptions' order query.
	 *
	 * @param string $meta_key Meta key.
	 * @return int
	 */
	private function count_subscriptions_with_meta( string $meta_key ): int {
		if ( ! function_exists( 'wcs_get_orders_with_meta_query' ) ) {
			return 0;
		}

		$result = wcs_get_orders_with_meta_query(
			array(
				'status'     => 'any',
				'return'     => 'ids',
				'type'       => 'shop_subscription',
				'limit'      => -1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return is_countable( $result ) ? count( $result ) : 0;
	}

	/**
	 * Get the Stripe subscription item ID of a subscription item.
	 *
	 * @param WC_Order_Item $item Subscription item.
	 * @return string
	 */
	public function get_wcpay_subscription_item_id( WC_Order_Item $item ): string {
		return (string) $item->get_meta( self::SUBSCRIPTION_ITEM_ID_META_KEY, true );
	}

	/**
	 * Get the Stripe discount IDs of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,mixed>
	 */
	public function get_wcpay_discount_ids( WC_Order $subscription ): array {
		$discount_ids = $subscription->get_meta( self::SUBSCRIPTION_DISCOUNT_IDS_META_KEY, true );

		return is_array( $discount_ids ) ? $discount_ids : array();
	}

	/**
	 * Save the Stripe discount IDs of a subscription.
	 *
	 * @param WC_Order         $subscription Subscription.
	 * @param array<int,mixed> $discounts    Stripe discount IDs.
	 */
	public function set_wcpay_discount_ids( WC_Order $subscription, array $discounts ): void {
		$subscription->update_meta_data( self::SUBSCRIPTION_DISCOUNT_IDS_META_KEY, $discounts );
		$subscription->save();
	}

	/**
	 * Create the Stripe subscription of a subscription paid with WooPayments, refusing the checkout when that fails.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 * @throws \Exception With the message to show at checkout when the Stripe subscription cannot be created.
	 */
	public function create_subscription( $subscription ): void {
		// Another gateway, or a free subscription bought without payment details, is not billed at Stripe.
		if ( ! $subscription instanceof WC_Order || WooPaymentsPersistenceProfile::GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}

		$checkout_error_message = __( 'There was a problem creating your subscription. Please try again or contact us for assistance.', 'woocommerce' );
		$wcpay_customer_id      = $this->get_customer_id_for_subscription( $subscription );

		if ( '' === $wcpay_customer_id ) {
			$this->logger->log( 'There was a problem creating the WooPayments subscription. WooPayments customer ID missing.', 'error' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Checkout error notice text, escaped where the notice is printed.
			throw new \Exception( $checkout_error_message );
		}

		try {
			$subscription_data = $this->prepare_wcpay_subscription_data( $wcpay_customer_id, $subscription );
			$this->validate_subscription_data( $subscription_data );

			// The module only loads with WooCommerce Subscriptions active.
			$subscription_data['metadata']['subscription_source'] = 'woo_subscriptions';

			$response = $this->api->create_subscription( $subscription_data );

			$this->set_wcpay_subscription_id( $subscription, (string) $response['id'] );
			$this->set_wcpay_subscription_item_ids( $subscription, (array) $response['items']['data'] );

			if ( isset( $response['discounts'] ) ) {
				$this->set_wcpay_discount_ids( $subscription, (array) $response['discounts'] );
			}

			if ( ! empty( $response['latest_invoice'] ) ) {
				$this->invoice_service->set_subscription_invoice_id( $subscription, (string) $response['latest_invoice'] );
			}
		} catch ( \Exception $exception ) {
			$this->logger->log( sprintf( 'There was a problem creating the WooPayments subscription. %s', $exception->getMessage() ) );

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Checkout error notice text with its own markup, escaped where the notice is printed.
			if ( $exception instanceof StripeBillingException && StripeBillingException::AMOUNT_TOO_SMALL === $exception->get_error_code() ) {
				throw new \Exception(
					sprintf(
						/* translators: 1: currency formatted price string, such as $0.50, 2: opening strong tag, 3: closing strong tag. */
						__( 'There was a problem creating your subscription. %1$s doesn\'t meet the %2$sminimum recurring amount%3$s this payment method can process.', 'woocommerce' ),
						wc_price( $subscription->get_total() ),
						'<strong>',
						'</strong>'
					)
				);
			}

			if ( $exception instanceof StripeBillingException && StripeBillingException::CANNOT_COMBINE_CURRENCIES === $exception->get_error_code() ) {
				throw new \Exception(
					sprintf(
						/* translators: 1: and 2: currency codes, such as USD or EUR. */
						__( 'The subscription couldn\'t be created because it uses a different currency (%1$s) from your existing subscriptions (%2$s). Please ensure all subscriptions use the same currency.', 'woocommerce' ),
						$subscription->get_currency(),
						(string) ( $exception->get_data()['currency'] ?? '' )
					)
				);
			}

			throw new \Exception( $checkout_error_message );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Create the Stripe subscription of a subscription whose customer switches it to WooPayments, keeping its billing dates.
	 *
	 * @internal
	 *
	 * @param mixed $subscription       Subscription.
	 * @param mixed $new_payment_method New payment method ID.
	 * @throws \Exception With the message to show the customer when the Stripe subscription cannot be created.
	 */
	public function maybe_create_subscription_from_update_payment_method( $subscription, $new_payment_method ): void {
		if ( ! $subscription instanceof WC_Order || WooPaymentsPersistenceProfile::GATEWAY_ID !== $new_payment_method ) {
			return;
		}

		if ( (bool) $this->get_wcpay_subscription_id( $subscription ) ) {
			return;
		}

		$this->is_creating_subscription_from_update_payment_method = true;

		$this->create_subscription( $subscription );
	}

	/**
	 * Create the Stripe subscription of a manual subscription renewed by hand with a reusable payment method.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Renewal order ID.
	 * @throws \Exception When the Stripe subscription cannot be created.
	 */
	public function create_subscription_for_manual_renewal( $order_id ): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_renewal_order( absint( $order_id ) ) as $subscription ) {
			if ( ! $subscription instanceof WC_Order || $this->get_wcpay_subscription_id( $subscription ) || ! $this->is_manual( $subscription ) ) {
				continue;
			}

			// Only a reusable payment method leaves tokens on the subscription.
			if ( ! empty( $subscription->get_payment_tokens() ) ) {
				$this->create_subscription( $subscription );
			}
		}
	}

	/**
	 * Cancel the Stripe subscription of a subscription that was cancelled or expired.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 */
	public function cancel_subscription( $subscription ): void {
		if ( ! $subscription instanceof WC_Order ) {
			return;
		}

		$wcpay_subscription_id = $this->get_wcpay_subscription_id( $subscription );
		if ( ! $wcpay_subscription_id ) {
			return;
		}

		try {
			$this->api->cancel_subscription( $wcpay_subscription_id );
		} catch ( WooPaymentsApiException $exception ) {
			$this->logger->log( sprintf( 'There was a problem canceling the subscription on WooPayments server: %s.', $exception->getMessage() ) );
		}
	}

	/**
	 * Pause collection of the Stripe subscription of a subscription put on hold, and note it on the subscription.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 */
	public function handle_subscription_status_on_hold( $subscription ): void {
		// With WooCommerce Subscriptions, this also runs for subscriptions renewed with saved tokens.
		if ( $this->is_stripe_sync_paused || ! $subscription instanceof WC_Order || ! $this->is_wcpay_subscription( $subscription ) ) {
			return;
		}

		$this->suspend_subscription( $subscription );

		$subscription->add_order_note( __( 'Suspended WooPayments Subscription because subscription status changed to on-hold.', 'woocommerce' ) );

		// The stack shows where the status change came from, such as an admin action or custom code.
		$this->logger->log(
			sprintf(
				'Suspended WooPayments Subscription because subscription status changed to on-hold. WC ID: %d; WooPayments ID: %s; stack: %s',
				$subscription->get_id(),
				$this->get_wcpay_subscription_id( $subscription ),
				( new \Exception() )->getTraceAsString()
			)
		);
	}

	/**
	 * Pause collection of a Stripe subscription, voiding its invoices until it is reactivated.
	 *
	 * @param WC_Order $subscription Subscription.
	 */
	public function suspend_subscription( WC_Order $subscription ): void {
		if ( ! $this->is_wcpay_subscription( $subscription ) ) {
			$this->logger->log(
				sprintf(
					'Aborting WC_Payments_Subscription_Service::suspend_subscription; subscription is a tokenised (non WooPayments) subscription. WC ID: %d.',
					$subscription->get_id()
				)
			);
			return;
		}

		$this->update_subscription( $subscription, array( 'pause_collection' => array( 'behavior' => 'void' ) ) );
	}

	/**
	 * Resume a Stripe subscription whose subscription became active again: undo a pause or a pending cancellation.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 */
	public function reactivate_subscription( $subscription ): void {
		if ( $this->is_stripe_sync_paused || ! $subscription instanceof WC_Order ) {
			return;
		}

		$this->update_subscription(
			$subscription,
			array(
				'cancel_at_period_end' => 'false',
				'pause_collection'     => '',
			)
		);
	}

	/**
	 * Cancel a Stripe subscription at the end of its period, when its subscription is pending cancellation.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 */
	public function set_pending_cancel_for_subscription( $subscription ): void {
		if ( ! $subscription instanceof WC_Order ) {
			return;
		}

		$this->update_subscription( $subscription, array( 'cancel_at_period_end' => 'true' ) );
	}

	/**
	 * Make a token added to a Stripe-billed subscription the default payment method of its Stripe subscription.
	 *
	 * @internal
	 *
	 * @param mixed $subscription_id Order ID the token was added to.
	 * @param mixed $token_id        Token ID.
	 * @param mixed $token           Token.
	 */
	public function update_wcpay_subscription_payment_method( $subscription_id, $token_id, $token ): void {
		unset( $token_id );

		if ( $this->is_payment_method_sync_paused || ! function_exists( 'wcs_get_subscription' ) || ! $token instanceof WC_Payment_Token ) {
			return;
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Order || ! $this->is_wcpay_subscription( $subscription ) ) {
			return;
		}

		$wcpay_payment_method_id = $token->get_token();
		if ( $this->get_wcpay_subscription_id( $subscription ) && $wcpay_payment_method_id ) {
			$this->update_subscription( $subscription, array( 'default_payment_method' => $wcpay_payment_method_id ) );
		}
	}

	/**
	 * Charge the invoice a Stripe-billed subscription is waiting on, once the customer paid with a new payment method.
	 *
	 * When the invoice is paid, its failed renewal order is completed with the new token right away.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 * @param mixed $token        The new payment token.
	 * @throws WooPaymentsApiException When the invoice cannot be charged.
	 * @throws StripeBillingException When the platform refuses the charge for a reason the module handles.
	 */
	public function maybe_attempt_payment_for_subscription( $subscription, $token ): void {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! $subscription instanceof WC_Order || ! $token instanceof WC_Payment_Token || ! wcs_is_subscription( $subscription ) ) {
			return;
		}

		$wcpay_invoice_id = $this->invoice_service->get_pending_invoice_id( $subscription );
		if ( ! $wcpay_invoice_id || ! $this->is_wcpay_subscription( $subscription ) ) {
			return;
		}

		$response = $this->api->charge_invoice( $wcpay_invoice_id );
		if ( ! isset( $response['status'] ) || 'paid' !== $response['status'] ) {
			return;
		}

		$this->invoice_service->mark_pending_invoice_paid_for_subscription( $subscription );

		$order_id = $this->invoice_service->get_order_id_by_invoice_id( $wcpay_invoice_id );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( $order instanceof WC_Order && $order->needs_payment() && class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			// While this flag is set, WooCommerce Subscriptions does not activate the subscription when the order is paid.
			$is_change_payment_request = \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = false;

			// Without the new token on the order, WooCommerce Subscriptions copies the failing one back to the subscription.
			$order->add_payment_token( $token );
			$order->payment_complete();

			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = $is_change_payment_request;
			wc_add_notice( __( "We've successfully collected payment for your subscription using your new payment method.", 'woocommerce' ) );
		}
	}

	/**
	 * Limit what a Stripe-billed subscription supports: Stripe schedules its payments, so its amounts and dates cannot change here.
	 *
	 * Subscriptions that are not Stripe-billed keep changing amounts and dates, and never claim Stripe's scheduled payments.
	 *
	 * @internal
	 *
	 * @param mixed $supported    Whether the feature is supported.
	 * @param mixed $feature      Feature name.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public function prevent_wcpay_subscription_changes( $supported, $feature, $subscription ) {
		if ( ! $subscription instanceof WC_Order || ! is_string( $feature ) ) {
			return $supported;
		}

		$is_stripe_billing = $this->is_wcpay_subscription( $subscription );

		switch ( $feature ) {
			case 'subscription_amount_changes':
			case 'subscription_date_changes':
				$supported = ! $is_stripe_billing;
				break;
			case 'gateway_scheduled_payments':
				$supported = $is_stripe_billing;
				break;
		}

		if ( $is_stripe_billing ) {
			$supported = in_array( $feature, self::SUPPORTED_FEATURES, true ) || isset( $this->feature_support_exceptions[ $subscription->get_id() ][ $feature ] );
		}

		return $supported;
	}

	/**
	 * Remove the actions that create pending parent or renewal orders, or process a renewal, from a Stripe-billed subscription's edit screen.
	 *
	 * @internal
	 *
	 * @param mixed $actions Order actions.
	 * @return mixed
	 */
	public function prevent_wcpay_manual_renewal( $actions ) {
		global $theorder;

		if ( ! is_array( $actions ) || ! function_exists( 'wcs_is_subscription' ) || ! $theorder instanceof WC_Order ) {
			return $actions;
		}

		if ( wcs_is_subscription( $theorder ) && $this->is_wcpay_subscription( $theorder ) ) {
			unset(
				$actions['wcs_create_pending_parent'],
				$actions['wcs_create_pending_renewal'],
				$actions['wcs_process_renewal']
			);
		}

		return $actions;
	}

	/**
	 * Show the Stripe subscription ID on a Stripe-billed subscription's edit screen.
	 *
	 * @internal
	 *
	 * @param mixed $order Order or subscription being edited.
	 */
	public function show_wcpay_subscription_id( $order ): void {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! $order instanceof WC_Order || ! wcs_is_subscription( $order ) || ! $this->is_wcpay_subscription( $order ) ) {
			return;
		}

		$wcpay_subscription_id = $this->get_wcpay_subscription_id( $order );
		if ( ! $wcpay_subscription_id ) {
			return;
		}

		echo '<p><strong>' . sprintf(
			/* translators: %s: WooPayments */
			esc_html__( '%s Subscription ID', 'woocommerce' ),
			'WooPayments'
		) . ':</strong> ' . esc_html( $wcpay_subscription_id ) . '</p>';
	}

	/**
	 * Set a subscription's next payment date to the end of its Stripe subscription's current period.
	 *
	 * @param array<string,mixed> $wcpay_subscription Stripe subscription.
	 * @param WC_Order            $subscription       Subscription.
	 */
	public function update_dates_to_match_wcpay_subscription( array $wcpay_subscription, WC_Order $subscription ): void {
		// Stripe-billed subscriptions refuse date changes; allow this one.
		$this->feature_support_exceptions[ $subscription->get_id() ]['subscription_date_changes'] = true;

		$current_period_end = (int) $wcpay_subscription['current_period_end'];
		if ( is_callable( array( $subscription, 'update_dates' ) ) ) {
			$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $current_period_end ) ) );
		}

		$next_payment_time_difference = absint( $current_period_end - $this->get_time( $subscription, 'next_payment' ) );
		if ( $next_payment_time_difference > 0 && $next_payment_time_difference >= 12 * HOUR_IN_SECONDS ) {
			$subscription->add_order_note( __( 'The subscription\'s next payment date has been updated to match WooPayments server.', 'woocommerce' ) );
		}

		unset( $this->feature_support_exceptions[ $subscription->get_id() ]['subscription_date_changes'] );
	}

	/**
	 * Cancel the Stripe subscription of a subscription moved off WooPayments, keeping its ID under `_cancelled_wcpay_subscription_id`.
	 *
	 * @internal
	 *
	 * @param mixed $subscription       Subscription.
	 * @param mixed $new_payment_method New payment method ID.
	 */
	public function maybe_cancel_subscription( $subscription, $new_payment_method ): void {
		if ( ! $subscription instanceof WC_Order ) {
			return;
		}

		$wcpay_subscription_id = $this->get_wcpay_subscription_id( $subscription );
		if ( ! $wcpay_subscription_id || WooPaymentsPersistenceProfile::GATEWAY_ID === $new_payment_method ) {
			return;
		}

		$this->cancel_subscription( $subscription );

		$subscription->update_meta_data( '_cancelled' . self::SUBSCRIPTION_ID_META_KEY, $wcpay_subscription_id );
		$subscription->delete_meta_data( self::SUBSCRIPTION_ID_META_KEY );
		$subscription->save();
	}

	/**
	 * Get the Stripe discounts for a subscription's coupons: recurring coupons apply forever, others once.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_discount_item_data_for_subscription( WC_Order $subscription ): array {
		$data = array();

		foreach ( $subscription->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$code     = $item->get_code();
			$coupon   = new WC_Coupon( $code );
			$duration = in_array( $coupon->get_discount_type(), array( 'recurring_fee', 'recurring_percent' ), true ) ? 'forever' : 'once';
			$discount = $item->get_discount();

			if ( $discount ) {
				$data[] = array(
					'amount_off' => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $discount, $subscription->get_currency() ),
					'currency'   => $subscription->get_currency(),
					'duration'   => $duration,
					/* translators: %s: Coupon code. */
					'name'       => sprintf( __( 'Coupon - %s', 'woocommerce' ), $code ),
				);
			}
		}

		return $data;
	}

	/**
	 * Get the Stripe subscription items for a subscription: its products, then its fees, shipping and taxes.
	 *
	 * Creates the Stripe products that are missing.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,array<string,mixed>>
	 * @throws StripeBillingException When the product of a subscription item no longer exists.
	 */
	public function get_recurring_item_data_for_subscription( WC_Order $subscription ): array {
		$data           = array();
		$currency       = $subscription->get_currency();
		$billing_period = is_callable( array( $subscription, 'get_billing_period' ) ) ? (string) $subscription->get_billing_period() : '';
		$interval       = is_callable( array( $subscription, 'get_billing_interval' ) ) ? (int) $subscription->get_billing_interval() : 0;

		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is application data, not HTML output.
				throw new StripeBillingException(
					sprintf(
						/* translators: 1: subscription ID, 2: subscription item ID */
						__( 'Subscription #%1$d cannot be billed through Stripe Billing: the product of its item #%2$d no longer exists.', 'woocommerce' ),
						$subscription->get_id(),
						$item->get_id()
					),
					StripeBillingException::SUBSCRIPTION_PRODUCT_MISSING
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$data[] = array(
				'metadata'   => $this->get_item_metadata( $item ),
				'quantity'   => $item->get_quantity(),
				'price_data' => $this->format_item_price_data(
					$currency,
					$this->product_service->get_or_create_wcpay_product_id( $product ),
					(float) $item->get_subtotal() / $item->get_quantity(),
					$billing_period,
					$interval
				),
			);
		}

		$additional_items = array_merge( $subscription->get_fees(), $subscription->get_shipping_methods(), $subscription->get_taxes() );

		foreach ( $additional_items as $item ) {
			if ( $item instanceof \WC_Order_Item_Tax ) {
				$item_name   = $item->get_label();
				$unit_amount = (float) $item->get_tax_total() + (float) $item->get_shipping_tax_total();
			} else {
				$item_name   = $item->get_type();
				$unit_amount = (float) $item->get_total();
			}

			if ( $unit_amount ) {
				$data[] = array(
					'metadata'   => $this->get_item_metadata( $item ),
					'price_data' => $this->format_item_price_data( $currency, $this->product_service->get_wcpay_product_id_for_item( $item_name ), $unit_amount, $billing_period, $interval ),
				);
			}
		}

		return $data;
	}

	/**
	 * Build the data to create a Stripe subscription with.
	 *
	 * @param string   $wcpay_customer_id Stripe customer ID.
	 * @param WC_Order $subscription      Subscription.
	 * @return array<string,mixed>
	 * @throws StripeBillingException When the product of a subscription item no longer exists.
	 * @throws WooPaymentsApiException When a Stripe product cannot be created.
	 */
	private function prepare_wcpay_subscription_data( string $wcpay_customer_id, WC_Order $subscription ): array {
		$recurring_items = $this->get_recurring_item_data_for_subscription( $subscription );
		$one_time_items  = $this->get_one_time_item_data_for_subscription( $subscription );
		$discount_items  = $this->get_discount_item_data_for_subscription( $subscription );
		$data            = array(
			'customer' => $wcpay_customer_id,
			'items'    => $recurring_items,
		);

		if ( $this->has_delayed_payment( $subscription ) ) {
			$data['trial_end'] = max( $this->get_time( $subscription, 'trial_end' ), $this->get_time( $subscription, 'next_payment' ) );
		}

		if ( ! empty( $one_time_items ) ) {
			$data['add_invoice_items'] = $one_time_items;
		}

		if ( ! empty( $discount_items ) ) {
			$data['discounts'] = $discount_items;
		}

		if ( $this->is_creating_subscription_from_update_payment_method ) {
			$data['backdate_start_date']  = max( $this->get_time( $subscription, 'start' ), $this->get_time( $subscription, 'last_order_date_created' ), $this->get_time( $subscription, 'last_order_date_paid' ) );
			$data['billing_cycle_anchor'] = $this->get_time( $subscription, 'next_payment' );
		}

		/**
		 * Filters the data a Stripe Billing subscription is created with.
		 *
		 * @since 11.2.0
		 *
		 * @param array $data Subscription data: customer, items, and when they apply trial_end, add_invoice_items, discounts, backdate_start_date and billing_cycle_anchor.
		 */
		$filtered_data = apply_filters( 'wcpay_subscriptions_prepare_subscription_data', $data );
		if ( ! is_array( $filtered_data ) ) {
			$this->logger->log( sprintf( 'The wcpay_subscriptions_prepare_subscription_data filter returned %s instead of an array for subscription #%d; the unfiltered data was used.', gettype( $filtered_data ), $subscription->get_id() ), 'error' );
			return $data;
		}

		return $filtered_data;
	}

	/**
	 * Get the one-time charges of a subscription's first invoice: sign-up fees and one-time shipping.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,array<string,mixed>>
	 * @throws WooPaymentsApiException When a Stripe product cannot be created.
	 */
	private function get_one_time_item_data_for_subscription( WC_Order $subscription ): array {
		$data     = array();
		$currency = $subscription->get_currency();

		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return $data;
		}

		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product           = $item->get_product();
			$sign_up_fee       = (float) \WC_Subscriptions_Product::get_sign_up_fee( $product );
			$one_time_shipping = \WC_Subscriptions_Product::needs_one_time_shipping( $product );

			if ( $sign_up_fee ) {
				$data[] = array(
					'price_data' => $this->format_item_price_data( $currency, $this->product_service->get_wcpay_product_id_for_item( 'sign_up_fee' ), $sign_up_fee ),
				);
			}

			if ( $one_time_shipping ) {
				$wcpay_item_id = $this->product_service->get_wcpay_product_id_for_item( 'shipping' );
				$shipping      = 0.0;
				$parent        = wc_get_order( $subscription->get_parent_id() );

				if ( $parent instanceof WC_Order ) {
					foreach ( $parent->get_shipping_methods() as $shipping_method ) {
						$shipping += (float) $shipping_method->get_total();
					}
				}

				$data[] = array(
					'price_data' => $this->format_item_price_data( $currency, $wcpay_item_id, $shipping ),
				);
			}
		}

		return $data;
	}

	/**
	 * Refuse subscription data the platform cannot bill: a missing customer, items or price fields, or a cycle over one year.
	 *
	 * @param array<string,mixed> $subscription_data Subscription data.
	 * @throws \Exception When the data cannot be billed.
	 */
	private function validate_subscription_data( array $subscription_data ): void {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log-only messages, never printed.
		if ( empty( $subscription_data['customer'] ) ) {
			throw new \Exception( 'The "customer" arg is required to create the subscription.' );
		}

		if ( ! isset( $subscription_data['items'] ) ) {
			throw new \Exception( 'The "items" arg is required to create the subscription.' );
		}

		foreach ( (array) $subscription_data['items'] as $item_data ) {
			$errors = array();

			if ( ! isset( $item_data['price_data']['unit_amount_decimal'] ) ) {
				$errors[] = 'unit_amount_decimal';
			}

			foreach ( array( 'currency', 'product', 'recurring' ) as $required_key ) {
				if ( empty( $item_data['price_data'][ $required_key ] ) ) {
					$errors[] = $required_key;
				}
			}

			foreach ( array( 'interval', 'interval_count' ) as $required_key ) {
				if ( empty( $item_data['price_data']['recurring'][ $required_key ] ) ) {
					$errors[] = $required_key;
				}
			}

			if ( ! empty( $errors ) ) {
				$error_message = count( $errors ) > 1 ? 'The "%s" line item properties are required to create the subscription.' : 'The "%s" line item property is required to create the subscription.';
				throw new \Exception( sprintf( $error_message, implode( '", "', $errors ) ) );
			}

			$billing_period   = $item_data['price_data']['recurring']['interval'];
			$billing_interval = $item_data['price_data']['recurring']['interval_count'];

			if ( ! $this->product_service->is_valid_billing_cycle( $billing_period, $billing_interval ) ) {
				throw new \Exception( sprintf( 'The subscription billing period cannot be any longer than one year. A billing period of "every %s %s(s)" was given.', $billing_interval, $billing_period ) );
			}
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Get the Stripe customer of a subscription's user, creating it when needed; an empty string for a subscription without a user.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 * @throws WooPaymentsApiException When the customer cannot be created.
	 */
	private function get_customer_id_for_subscription( WC_Order $subscription ): string {
		if ( false === $subscription->get_user() ) {
			return '';
		}

		// The user's customer, as the client's get_customer_id_for_order() reads it (customer service :431-446).
		return $this->customer_service->get_or_create_customer_id_for_user( (int) $subscription->get_user_id() );
	}

	/**
	 * Tell whether a subscription's first payment is in the future: it has a trial, or a synchronised renewal date other than today.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return bool
	 */
	private function has_delayed_payment( WC_Order $subscription ): bool {
		$trial_end = $this->get_time( $subscription, 'trial_end' );
		$has_sync  = false;

		if ( ! class_exists( 'WC_Subscriptions_Synchroniser' ) ) {
			return $has_sync;
		}

		if ( \WC_Subscriptions_Synchroniser::is_syncing_enabled() && \WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $subscription ) ) {
			$has_sync = true;

			foreach ( $subscription->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$synced_payment_date = \WC_Subscriptions_Synchroniser::calculate_first_payment_date( $item->get_product(), 'timestamp' );

				// A subscription that starts today needs no trial to reach its payment date.
				if ( \WC_Subscriptions_Synchroniser::is_today( $synced_payment_date ) ) {
					$has_sync = false;
					break;
				}
			}
		}

		return $has_sync || $trial_end > time();
	}

	/**
	 * Save the Stripe subscription ID of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $value        Stripe subscription ID.
	 */
	private function set_wcpay_subscription_id( WC_Order $subscription, string $value ): void {
		$subscription->update_meta_data( self::SUBSCRIPTION_ID_META_KEY, $value );
		$subscription->save();
	}

	/**
	 * Save the Stripe subscription item IDs on the subscription items they bill, matched by the `wc_item_id` metadata sent.
	 *
	 * @param WC_Order         $subscription       Subscription.
	 * @param array<int,mixed> $subscription_items Stripe subscription items.
	 */
	private function set_wcpay_subscription_item_ids( WC_Order $subscription, array $subscription_items ): void {
		foreach ( $subscription_items as $item ) {
			$wcpay_subscription_item_id = (string) $item['id'];
			$subscription_item_id       = isset( $item['metadata']['wc_item_id'] ) ? absint( $item['metadata']['wc_item_id'] ) : 0;
			$subscription_item          = $subscription_item_id ? $subscription->get_item( $subscription_item_id ) : false;

			if ( $subscription_item instanceof WC_Order_Item ) {
				$subscription_item->update_meta_data( self::SUBSCRIPTION_ITEM_ID_META_KEY, $wcpay_subscription_item_id );
				$subscription_item->save();
			} else {
				$this->logger->log( sprintf( 'Unable to set subscription item ID meta for WooPayments subscription item %s.', $wcpay_subscription_item_id ) );
			}
		}
	}

	/**
	 * Update the Stripe subscription of a subscription, logging a platform failure instead of passing it on.
	 *
	 * @param WC_Order            $subscription Subscription.
	 * @param array<string,mixed> $data         Subscription data.
	 * @return array<string,mixed>|null The Stripe subscription, or null when there is none or the update failed.
	 */
	private function update_subscription( WC_Order $subscription, array $data ): ?array {
		$wcpay_subscription_id = $this->get_wcpay_subscription_id( $subscription );
		if ( ! $wcpay_subscription_id ) {
			return null;
		}

		try {
			return $this->api->update_subscription( $wcpay_subscription_id, $data );
		} catch ( WooPaymentsApiException $exception ) {
			$this->logger->log( sprintf( 'There was a problem updating the WooPayments subscription on server: %s', $exception->getMessage() ) );
			return null;
		}
	}

	/**
	 * Tell whether a subscription renews manually.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return bool
	 */
	private function is_manual( WC_Order $subscription ): bool {
		return is_callable( array( $subscription, 'is_manual' ) ) && (bool) $subscription->is_manual();
	}

	/**
	 * Get one of a subscription's dates as a timestamp, 0 when it has none.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $date_type    Date type, such as `trial_end` or `next_payment`.
	 * @return int
	 */
	private function get_time( WC_Order $subscription, string $date_type ): int {
		return is_callable( array( $subscription, 'get_time' ) ) ? (int) $subscription->get_time( $date_type ) : 0;
	}

	/**
	 * Build a Stripe price: the unit amount in the currency's smallest unit, with fractions kept, and the billing cycle when given.
	 *
	 * @param string $currency         Currency code.
	 * @param string $wcpay_product_id Stripe product ID.
	 * @param float  $unit_amount      Unit amount.
	 * @param string $interval         Billing period.
	 * @param int    $interval_count   Billing interval.
	 * @return array<string,mixed>
	 */
	private function format_item_price_data( string $currency, string $wcpay_product_id, float $unit_amount, string $interval = '', int $interval_count = 0 ): array {
		$data = array(
			'currency'            => $currency,
			'product'             => $wcpay_product_id,
			'unit_amount_decimal' => round( $unit_amount, wc_get_rounding_precision() ),
		);

		if ( ! WooPaymentsCurrencyUtils::is_zero_decimal_currency( $currency ) ) {
			$data['unit_amount_decimal'] *= 100;
		}

		if ( $interval && $interval_count ) {
			$data['recurring'] = array(
				'interval'       => $interval,
				'interval_count' => $interval_count,
			);
		}

		return $data;
	}

	/**
	 * Get the metadata that ties a Stripe subscription item to its WooCommerce item.
	 *
	 * @param WC_Order_Item $item Subscription item of any type.
	 * @return array<string,mixed>
	 */
	private function get_item_metadata( WC_Order_Item $item ): array {
		$metadata = array( 'wc_item_id' => $item->get_id() );

		if ( $item instanceof \WC_Order_Item_Tax ) {
			$metadata['wc_rate_id']  = $item->get_rate_id();
			$metadata['code']        = $item->get_rate_code();
			$metadata['rate']        = $item->get_rate_percent();
			$metadata['is_compound'] = wc_bool_to_string( $item->is_compound() );
		} elseif ( $item instanceof \WC_Order_Item_Shipping ) {
			$metadata['method'] = $item->get_name();
		} elseif ( $item instanceof \WC_Order_Item_Fee ) {
			$metadata['type'] = $item->get_name();
		}

		return $metadata;
	}
}
