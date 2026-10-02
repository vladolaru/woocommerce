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
	 * Global holding, per renewal order ID, the subscription IDs `wcs_get_subscriptions_for_renewal_order()` returns; the gateway tests read it too.
	 */
	public const RENEWAL_SUBSCRIPTIONS = 'wcpay_test_renewal_subscription_ids';

	/**
	 * Global that makes `WCS_Staging::is_duplicate_site()` report a staging copy when true.
	 */
	public const DUPLICATE_SITE = 'wcpay_test_duplicate_site';

	/**
	 * Define the doubles that are not defined yet, and load registered subscriptions as `SubscriptionDouble` until the test ends.
	 */
	public static function load(): void {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public product helper in the global namespace.
			eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { $product_id = $product instanceof WC_Product ? $product->get_id() : absint( $product ); return in_array( $product_id, $GLOBALS["' . self::SUBSCRIPTION_PRODUCT_IDS . '"] ?? array(), true ); } public static function get_sign_up_fee( $product ) { return $product instanceof WC_Product ? (float) $product->get_meta( "_subscription_sign_up_fee" ) : 0; } public static function needs_one_time_shipping( $product ) { return $product instanceof WC_Product && "yes" === $product->get_meta( "_subscription_one_time_shipping" ); } } }' );
		}

		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the other native tests define, reading the same registry.
			eval( 'namespace { function wcs_get_subscriptions_for_order( $order_id, $args = array() ) { $order_id = is_object( $order_id ) && method_exists( $order_id, "get_id" ) ? $order_id->get_id() : absint( $order_id ); $order_types = $args["order_type"] ?? array( "parent", "switch" ); $order_types = is_array( $order_types ) ? $order_types : array( $order_types ); $relationships = $GLOBALS["' . self::ORDER_SUBSCRIPTIONS . '"][ $order_id ] ?? array(); $order_types = in_array( "any", $order_types, true ) ? array_keys( $relationships ) : $order_types; $ids = array(); foreach ( $order_types as $order_type ) { $ids = array_merge( $ids, $relationships[ $order_type ] ?? array() ); } return array_values( array_filter( array_map( "wc_get_order", array_unique( array_map( "absint", $ids ) ) ) ) ); } }' );
		}

		if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the gateway tests define, reading the same registry.
			eval( 'namespace { function wcs_get_subscriptions_for_renewal_order( $order_id ) { $ids = $GLOBALS["' . self::RENEWAL_SUBSCRIPTIONS . '"][ $order_id ] ?? array(); return array_map( "wc_get_order", $ids ); } }' );
		}

		if ( ! function_exists( 'wcs_is_subscription' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the other native tests define, reading the same registry.
			eval( 'namespace { function wcs_is_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : $subscription_id; return in_array( absint( $subscription_id ), $GLOBALS["' . self::SUBSCRIPTION_IDS . '"] ?? array(), true ); } }' );
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public subscription lookup.
			eval( 'namespace { function wcs_get_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : absint( $subscription_id ); return in_array( $subscription_id, $GLOBALS["' . self::SUBSCRIPTION_IDS . '"] ?? array(), true ) ? wc_get_order( $subscription_id ) : false; } }' );
		}

		if ( ! class_exists( 'WC_Subscriptions_Synchroniser' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; renewal synchronisation stays off, its default.
			eval( 'namespace { class WC_Subscriptions_Synchroniser { public static function is_syncing_enabled() { return false; } } }' );
		}

		if ( ! class_exists( 'WCS_Staging' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its staging check, off unless a test turns it on.
			eval( 'namespace { class WCS_Staging { public static function is_duplicate_site() { return ! empty( $GLOBALS["' . self::DUPLICATE_SITE . '"] ); } } }' );
		}

		// The test's hook snapshot removes this filter when the test ends.
		add_filter( 'woocommerce_order_class', array( self::class, 'get_subscription_order_class' ), 10, 3 );
	}

	/**
	 * Define the payment method change handler, with the request flag the Stripe Billing module saves and restores.
	 *
	 * The other members match the doubles the gateway and checkout tests define, so whichever is defined first serves all of them.
	 */
	public static function load_change_payment_gateway(): void {
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need a process-local stand-in.
		eval(
			<<<'PHP'
			namespace {
			class WC_Subscriptions_Change_Payment_Gateway {
				public static $is_request_to_change_payment = false;
				public static $updated_payment_methods = array();
				public static $updated_all_payment_methods = array();
				public static $will_update_all_payment_methods = true;

				public static function reset() {
					self::$is_request_to_change_payment = false;
					self::$updated_payment_methods = array();
					self::$updated_all_payment_methods = array();
					self::$will_update_all_payment_methods = true;
				}

				public static function update_payment_method( $order, $gateway_id ) {
					self::$updated_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
				}

				public static function will_subscription_update_all_payment_methods( $order ) {
					unset( $order );
					return self::$will_update_all_payment_methods;
				}

				public static function update_all_payment_methods_from_subscription( $order, $gateway_id ) {
					self::$updated_all_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
					return true;
				}
			}
			}
			PHP
		);
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
