<?php
/**
 * WooPaymentsSubscriptionMethodPolicy class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Defines which native WooPayments methods support automatic subscription renewals.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsSubscriptionMethodPolicy {

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
	 * Run a callback with WooCommerce's payment-complete stock reduction turned off.
	 *
	 * A subscription payment method change can complete the subscription's failed renewal, and that must not take stock
	 * again. Client 11.1.0 `with_stock_reduction_disabled()` (class-wc-payment-gateway-wcpay.php:5483-5499): the filter
	 * goes on at PHP_INT_MAX - 1 so a later filter cannot turn reduction back on, and only if it is not already there.
	 *
	 * @param callable $callback Callback to run.
	 * @return mixed The callback's return value.
	 *
	 * @since 11.2.0
	 */
	public static function run_without_stock_reduction( callable $callback ) {
		$filter           = 'woocommerce_payment_complete_reduce_order_stock';
		$priority         = PHP_INT_MAX - 1;
		$already_filtered = false !== has_filter( $filter, '__return_false' );

		if ( ! $already_filtered ) {
			add_filter( $filter, '__return_false', $priority );
		}

		try {
			return $callback();
		} finally {
			if ( ! $already_filtered ) {
				remove_filter( $filter, '__return_false', $priority );
			}
		}
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
