<?php
/**
 * WooCommerce Subscriptions doubles for the Stripe Billing module tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * Defines stand-ins for the parts of WooCommerce Subscriptions' public API the Stripe Billing module calls.
 *
 * WooCommerce Subscriptions is not installed in the test environment. PHPUnit includes every file under `tests/php`
 * when it builds the suite, so the doubles are only defined when a test calls `load()`. Each double answers as if
 * nothing were a subscription until a test registers it, so doubles left defined change nothing for later tests.
 *
 * Never define `WC_Subscriptions` or `WC_Subscriptions_Core_Plugin` here: other tests rely on their absence to mean that
 * WooCommerce Subscriptions is inactive.
 */
final class WooCommerceSubscriptionsDoubles {

	/**
	 * Global holding the IDs of products that `WC_Subscriptions_Product::is_subscription()` reports as subscriptions.
	 */
	public const SUBSCRIPTION_PRODUCT_IDS = 'wcpay_test_subscription_product_ids';

	/**
	 * Global holding the IDs of the orders loaded as `SubscriptionDouble`; other native tests read it in their `wcs_is_subscription()` double.
	 */
	public const SUBSCRIPTION_IDS = 'wcpay_test_subscription_ids';

	/**
	 * Global holding, per order ID, the subscription IDs `wcs_get_subscriptions_for_order()` returns for each relation (`parent`, `renewal`, ...).
	 */
	public const ORDER_SUBSCRIPTIONS = 'wcpay_test_order_subscription_relationships';

	/**
	 * Define the doubles that are not defined yet, and load registered subscriptions as `SubscriptionDouble` until the test ends.
	 */
	public static function load(): void {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public product helper in the global namespace.
			eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { $product_id = $product instanceof WC_Product ? $product->get_id() : absint( $product ); return in_array( $product_id, $GLOBALS["' . self::SUBSCRIPTION_PRODUCT_IDS . '"] ?? array(), true ); } } }' );
		}

		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the other native tests define, reading the same registry.
			eval( 'namespace { function wcs_get_subscriptions_for_order( $order_id, $args = array() ) { $order_id = is_object( $order_id ) && method_exists( $order_id, "get_id" ) ? $order_id->get_id() : absint( $order_id ); $order_types = $args["order_type"] ?? array( "parent", "switch" ); $order_types = is_array( $order_types ) ? $order_types : array( $order_types ); $relationships = $GLOBALS["' . self::ORDER_SUBSCRIPTIONS . '"][ $order_id ] ?? array(); $ids = array(); foreach ( $order_types as $order_type ) { $ids = array_merge( $ids, $relationships[ $order_type ] ?? array() ); } return array_values( array_filter( array_map( "wc_get_order", array_unique( array_map( "absint", $ids ) ) ) ) ); } }' );
		}

		// The test's hook snapshot removes this filter when the test ends.
		add_filter( 'woocommerce_order_class', array( self::class, 'get_subscription_order_class' ), 10, 3 );
	}

	/**
	 * Load the orders registered as subscriptions as `SubscriptionDouble`.
	 *
	 * @param mixed $class_name Order class name.
	 * @param mixed $order_type Order type.
	 * @param mixed $order_id   Order ID.
	 * @return mixed
	 */
	public static function get_subscription_order_class( $class_name, $order_type, $order_id ) {
		unset( $order_type );

		return in_array( absint( $order_id ), $GLOBALS[ self::SUBSCRIPTION_IDS ] ?? array(), true ) ? SubscriptionDouble::class : $class_name;
	}
}
