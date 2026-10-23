<?php
/**
 * Stub file for the WooCommerce Subscriptions API the PayPal wallet code calls.
 *
 * Analysis-only: it is listed in `scanFiles` in phpstan.neon so PHPStan can resolve these names, and it is never
 * loaded at runtime. WooCommerce Subscriptions is a separate plugin that is not installed when core is analysed. The
 * wallet code only reaches these classes and functions after checking that the plugin is active.
 *
 * It declares only the classes, methods and functions the wallet tree uses. The signatures follow WooCommerce
 * Subscriptions 9.0.1, read from the plugin source (includes/core/class-wc-subscription.php,
 * class-wc-subscriptions-product.php, class-wc-subscriptions-synchroniser.php, wcs-functions.php,
 * wcs-order-functions.php and wcs-renewal-functions.php). Where the plugin documents no return type, the type below
 * is the one its code returns.
 *
 * @package WooCommerce\Stubs
 */

/**
 * A subscription: an order of the `shop_subscription` type.
 */
class WC_Subscription extends WC_Order {

	/**
	 * Sets the subscription's dates.
	 *
	 * @param array  $dates    Dates keyed `date_created`, `trial_end`, `next_payment`, `last_order_date_created` or `end`.
	 * @param string $timezone Timezone of the dates.
	 * @return bool
	 */
	public function update_dates( $dates, $timezone = 'gmt' ): bool {
		return false;
	}

	/**
	 * Reactivates the subscription after a related order was paid.
	 *
	 * @param WC_Order $last_order The paid order.
	 */
	public function payment_complete_for_order( $last_order ) {
	}

	/**
	 * Parent order of the subscription.
	 *
	 * @return WC_Order|false
	 */
	public function get_parent() {
		return false;
	}
}

/**
 * Subscription settings on a product.
 */
class WC_Subscriptions_Product {

	/**
	 * Whether the product is a subscription product.
	 *
	 * @param int|WC_Product $product Product or product ID.
	 * @return bool
	 */
	public static function is_subscription( $product ) {
		return false;
	}

	/**
	 * Length of the free trial, in trial periods.
	 *
	 * @param mixed $product Product or product ID.
	 * @return int
	 */
	public static function get_trial_length( $product ) {
		return 0;
	}

	/**
	 * Sign-up fee of the product.
	 *
	 * @param mixed $product Product or product ID.
	 * @return int|string
	 */
	public static function get_sign_up_fee( $product ) {
		return 0;
	}

	/**
	 * End of the free trial, or 0 when there is no trial.
	 *
	 * @param int|WC_Product $product    Product or product ID.
	 * @param mixed          $from_date  MySQL date to count from, UTC.
	 * @return string|int
	 */
	public static function get_trial_expiration_date( $product, $from_date = '' ) {
		return 0;
	}

	/**
	 * Date of the first renewal payment, or 0 when there is none.
	 *
	 * @param int|WC_Product $product   Product or product ID.
	 * @param mixed          $from_date MySQL date to count from.
	 * @param string         $timezone  `site` or `gmt`.
	 * @return string|int
	 */
	public static function get_first_renewal_payment_date( $product, $from_date = '', $timezone = 'gmt' ) {
		return 0;
	}

	/**
	 * Date the subscription ends, or 0 when it never does.
	 *
	 * @param int|WC_Product $product   Product or product ID.
	 * @param mixed          $from_date MySQL date to count from.
	 * @return string|int
	 */
	public static function get_expiration_date( $product, $from_date = '' ) {
		return 0;
	}

	/**
	 * Billing period: day, week, month or year.
	 *
	 * @param mixed $product Product or product ID.
	 * @return string
	 */
	public static function get_period( $product ) {
		return '';
	}

	/**
	 * Billing interval.
	 *
	 * @param mixed $product Product or product ID.
	 * @return int
	 */
	public static function get_interval( $product ) {
		return 1;
	}
}

/**
 * Renewal synchronisation of subscription products.
 */
class WC_Subscriptions_Synchroniser {

	/**
	 * Whether a UTC timestamp falls on today in the site's time.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return bool
	 */
	public static function is_today( $timestamp ) {
		return false;
	}

	/**
	 * Whether the first payment is synced to a fixed day.
	 *
	 * @param mixed $product Product or product ID.
	 * @return bool
	 */
	public static function is_product_synced( $product ) {
		return false;
	}

	/**
	 * Whether the product asks for an upfront payment.
	 *
	 * @param mixed  $product_or_context Product or price context.
	 * @param string $from_date          MySQL date to count from.
	 * @return bool
	 */
	public static function is_payment_upfront( $product_or_context, $from_date = '' ) {
		return false;
	}

	/**
	 * First payment date of a synced product, or 0 when it is not synced.
	 *
	 * @param mixed  $product_or_context Product or price context.
	 * @param string $type               `mysql` or `timestamp`.
	 * @param string $from_date          MySQL date to count from, UTC.
	 * @return string|int
	 * @phpstan-return ($type is 'timestamp' ? int : string|int)
	 */
	public static function calculate_first_payment_date( $product_or_context, $type = 'mysql', $from_date = '' ) {
		return 0;
	}
}

/**
 * Subscriptions matching the given arguments, keyed by ID.
 *
 * @param array $args Query arguments.
 * @return WC_Subscription[]
 */
function wcs_get_subscriptions( $args ) {
	return array();
}

/**
 * Whether the value is a subscription or a subscription ID.
 *
 * @param mixed $subscription Subscription or ID.
 * @return bool
 */
function wcs_is_subscription( $subscription ) {
	return false;
}

/**
 * Creates a subscription.
 *
 * @param array $args Subscription arguments.
 * @return WC_Subscription|WP_Error
 */
function wcs_create_subscription( $args = array() ) {
	return new WP_Error();
}

/**
 * A subscription by ID.
 *
 * @param mixed $the_subscription Post, order or ID.
 * @return WC_Subscription|false
 */
function wcs_get_subscription( $the_subscription ) {
	return false;
}

/**
 * Whether the cart holds a renewal.
 *
 * @return bool|array The cart item with the renewal, else false.
 */
function wcs_cart_contains_renewal() {
	return false;
}

/**
 * Whether the order holds a subscription of the given order types.
 *
 * @param mixed        $order      Order or order ID.
 * @param array|string $order_type `parent`, `renewal`, `resubscribe` or `switch`.
 * @return bool
 */
function wcs_order_contains_subscription( $order, $order_type = array( 'parent', 'resubscribe', 'switch' ) ) {
	return false;
}

/**
 * Whether the order is a renewal order.
 *
 * @param WC_Order|int $order Order or order ID.
 * @return bool
 */
function wcs_order_contains_renewal( $order ) {
	return false;
}

/**
 * Subscriptions a renewal order relates to, keyed by ID.
 *
 * @param WC_Order|int $order Order or order ID.
 * @return WC_Subscription[]
 */
function wcs_get_subscriptions_for_renewal_order( $order ) {
	return array();
}

/**
 * Subscriptions related to an order, keyed by ID.
 *
 * @param WC_Order|int $order Order or order ID.
 * @param array        $args  Filters on the result.
 * @return WC_Subscription[]
 */
function wcs_get_subscriptions_for_order( $order, $args = array() ) {
	return array();
}
