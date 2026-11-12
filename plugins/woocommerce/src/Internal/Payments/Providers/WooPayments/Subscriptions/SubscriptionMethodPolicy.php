<?php
/**
 * SubscriptionMethodPolicy class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Order;

/**
 * Answers the WooPayments subscription questions: which payment methods renew automatically, whether WooCommerce
 * Subscriptions is loaded, and whether the cart or an order holds a subscription.
 *
 * @since 11.0.0
 * @internal
 */
final class SubscriptionMethodPolicy {

	/**
	 * Tell whether the current cart contains a subscription or a subscription renewal.
	 *
	 * A renewal cart pays for an existing subscription, so every surface that forces card saving for subscription
	 * purchases treats it the same way. Neither counts unless WooCommerce Subscriptions' cart class is loaded and
	 * subscriptions support is available, as in client 11.1.0 `is_subscription_item_in_cart()`
	 * (trait-wc-payments-subscriptions-utilities.php:96-101).
	 *
	 * @return bool
	 */
	public static function cart_contains_subscription_or_renewal(): bool {
		if ( ! wc_get_container()->get( LegacyProxy::class )->call_function( 'class_exists', 'WC_Subscriptions_Cart' ) || ! self::is_subscriptions_available() ) {
			return false;
		}

		$cart_contains_subscription = array( 'WC_Subscriptions_Cart', 'cart_contains_subscription' );
		$contains_subscription      = is_callable( $cart_contains_subscription ) && (bool) call_user_func( $cart_contains_subscription );

		return $contains_subscription
			|| ( function_exists( 'wcs_cart_contains_renewal' ) && (bool) wcs_cart_contains_renewal() );
	}

	/**
	 * Tell whether an order pays for a subscription or a subscription renewal, so its payment method must be saved.
	 *
	 * `wcs_order_contains_subscription()` leaves out renewal orders, so renewals are checked too, as client 11.1.0's
	 * is_payment_recurring() does (trait-wc-payments-subscriptions-utilities.php:53-66).
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 *
	 * @since 11.2.0
	 */
	public static function is_recurring_order( WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order->get_id() ) ) {
			return true;
		}

		return function_exists( 'wcs_order_contains_renewal' ) && (bool) wcs_order_contains_renewal( $order->get_id() );
	}

	/**
	 * Tell whether WooCommerce Subscriptions is active in this request, the condition for the Stripe Billing module to load
	 * (client 11.1.0 `class-wc-payments-features.php:312`). The Stripe Billing migrator, the cutover guard and the card
	 * gateway ask the same question.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_subscriptions_active(): bool {
		return (bool) wc_get_container()->get( LegacyProxy::class )->call_function( 'class_exists', 'WC_Subscriptions' );
	}

	/**
	 * Tell whether WooCommerce Subscriptions 2.2.0 or later, or the Subscriptions core library, is loaded.
	 *
	 * The same check as the card gateway's is_subscriptions_enabled(), usable before any gateway exists.
	 *
	 * @return bool
	 */
	public static function is_subscriptions_available(): bool {
		if ( class_exists( 'WC_Subscriptions' ) ) {
			return isset( \WC_Subscriptions::$version ) && version_compare( (string) \WC_Subscriptions::$version, '2.2.0', '>=' );
		}

		return (bool) wc_get_container()->get( LegacyProxy::class )->call_function( 'class_exists', 'WC_Subscriptions_Core_Plugin' );
	}

	/**
	 * Tell whether WooCommerce Subscriptions considers this site a staging copy of the live store.
	 *
	 * Client 11.1.0 `WC_Payments_Subscriptions::is_duplicate_site()`: a staging copy must do nothing at Stripe.
	 *
	 * @return bool
	 */
	public static function is_duplicate_site(): bool {
		if ( class_exists( 'WC_Subscriptions' ) && version_compare( (string) \WC_Subscriptions::$version, '4.0.0', '<' ) ) {
			return (bool) \WC_Subscriptions::is_duplicate_site();
		}

		return class_exists( 'WCS_Staging' ) && (bool) \WCS_Staging::is_duplicate_site();
	}

	/**
	 * Get gateway IDs that support reusable subscription payment methods.
	 *
	 * @return array<int,string>
	 *
	 * @since 11.0.0
	 */
	public static function get_reusable_gateway_ids(): array {
		return array(
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay',
		);
	}

	/**
	 * Run a subscription payment method change with WooCommerce Subscriptions' change flag set and WooCommerce's
	 * payment-complete stock reduction turned off, then restore both.
	 *
	 * The gateway and the order-status callback run every change through it. The stock filter is client 11.1.0's
	 * with_stock_reduction_disabled() (class-wc-payment-gateway-wcpay.php:5483-5499), at PHP_INT_MAX - 1 and only if it
	 * is not already there.
	 *
	 * @param callable $callback Callback to run.
	 * @return mixed The callback's return value.
	 *
	 * @since 11.2.0
	 */
	public static function run_as_payment_method_change( callable $callback ) {
		$filter           = 'woocommerce_payment_complete_reduce_order_stock';
		$priority         = PHP_INT_MAX - 1;
		$already_filtered = false !== has_filter( $filter, '__return_false' );

		if ( ! $already_filtered ) {
			add_filter( $filter, '__return_false', $priority );
		}

		try {
			if ( ! class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
				return $callback();
			}

			$was_change = \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = true;

			try {
				return $callback();
			} finally {
				\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = $was_change;
			}
		} finally {
			if ( ! $already_filtered ) {
				remove_filter( $filter, '__return_false', $priority );
			}
		}
	}

	/**
	 * Tell whether an order only saves the card from an intent: a WooCommerce Subscriptions subscription reaches the gateway
	 * only to change its payment method, so no intent completes it, changes its status or notes a payment on it.
	 *
	 * Client 11.3.0 returns early for a subscription in update_order_status_from_intent()
	 * (includes/class-wc-payments-order-service.php:466-472), which its order-status callback, redirect return, webhook and
	 * duplicate-payment check reach, and skips the status update on a card change at checkout
	 * (includes/class-wc-payment-gateway-wcpay.php:2497-2500). Consulted by WooPaymentsFeeDetailsNoteController's two
	 * appliers (the order-status callback, the redirect return, the duplicate-payment check and the payment_intent.succeeded
	 * webhook), WooPaymentsCheckoutAjaxController, which treats such an order as a payment method change (gw:4641,
	 * 5962-5968), and WooPaymentsGateway, which refuses a payment for such an order outside a validated change
	 * (gw:1453-1467). At checkout a validated change's outcome is marked payment-method-only instead
	 * (WooPaymentsPaymentOperationService::mark_payment_method_change_outcome()).
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order an intent is applied to.
	 * @return bool
	 */
	public static function only_saves_the_card( WC_Order $order ): bool {
		return function_exists( 'wcs_is_subscription' ) && (bool) wcs_is_subscription( $order );
	}

	/**
	 * Tell whether a gateway supports reusable subscription payment methods.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public static function is_reusable_gateway_id( string $gateway_id ): bool {
		return in_array( $gateway_id, self::get_reusable_gateway_ids(), true );
	}
}
