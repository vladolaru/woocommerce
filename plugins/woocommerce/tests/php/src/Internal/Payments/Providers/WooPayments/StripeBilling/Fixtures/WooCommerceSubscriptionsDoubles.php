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
	 * Global that makes `wcs_create_renewal_order()` fail with a `WP_Error` when true.
	 */
	public const RENEWAL_ORDER_ERROR = 'wcpay_test_renewal_order_error';

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
			eval( 'namespace { class WCS_Staging { public static function is_duplicate_site() { return ! empty( $GLOBALS["' . self::DUPLICATE_SITE . '"] ); } public static function get_site_url_from_source( $source = "current_wp_site" ) { return "subscriptions_install" === $source ? "https://live.rec-t63.test" : "https://staging.rec-t63.test"; } } }' );
		}

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its subscription query.
			eval( 'namespace { function wcs_get_subscriptions( $args ) { return \\' . self::class . '::get_subscriptions( $args ); } }' );
		}

		if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its renewal order creation.
			eval( 'namespace { function wcs_create_renewal_order( $subscription ) { return \\' . self::class . '::create_renewal_order( $subscription ); } }' );
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
	 * Make paying a renewal order activate its subscriptions that are not active, as WooCommerce Subscriptions does.
	 *
	 * The test's hook snapshot removes the callback when the test ends.
	 */
	public static function activate_subscriptions_on_renewal_payment(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'maybe_activate_renewal_subscriptions' ), 10, 3 );
	}

	/**
	 * Activate the subscriptions of a renewal order that moved from unpaid to paid, noting it on each.
	 *
	 * @param mixed $order_id   Order ID.
	 * @param mixed $old_status Previous status.
	 * @param mixed $new_status New status.
	 */
	public static function maybe_activate_renewal_subscriptions( $order_id, $old_status, $new_status ): void {
		if ( ! in_array( $old_status, array( 'pending', 'on-hold', 'failed' ), true ) || ! in_array( $new_status, wc_get_is_paid_statuses(), true ) ) {
			return;
		}

		foreach ( $GLOBALS[ self::RENEWAL_SUBSCRIPTIONS ][ absint( $order_id ) ] ?? array() as $subscription_id ) {
			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof SubscriptionDouble && ! $subscription->has_status( 'active' ) ) {
				$subscription->add_order_note( 'Payment status marked complete.' );
				$subscription->update_status( 'active' );
			}
		}
	}

	/**
	 * Find registered subscriptions whose meta matches every clause of `meta_query`, as `wcs_get_subscriptions()` does.
	 *
	 * @param array<string,mixed> $args Query arguments: `meta_query` and `subscriptions_per_page`.
	 * @return array<int,SubscriptionDouble> Subscriptions by ID.
	 */
	public static function get_subscriptions( array $args ): array {
		$subscriptions = array();
		foreach ( $GLOBALS[ self::SUBSCRIPTION_IDS ] ?? array() as $subscription_id ) {
			$subscription = wc_get_order( $subscription_id );
			if ( ! $subscription instanceof SubscriptionDouble ) {
				continue;
			}

			foreach ( $args['meta_query'] ?? array() as $clause ) {
				if ( (string) $subscription->get_meta( $clause['key'], true ) !== (string) $clause['value'] ) {
					continue 2;
				}
			}

			$subscriptions[ $subscription->get_id() ] = $subscription;
		}

		$per_page = (int) ( $args['subscriptions_per_page'] ?? -1 );

		return $per_page > 0 ? array_slice( $subscriptions, 0, $per_page, true ) : $subscriptions;
	}

	/**
	 * Create a pending renewal order with the subscription's customer, billing address, currency, line items and total, as `wcs_create_renewal_order()` does.
	 *
	 * @param \WC_Order $subscription Subscription.
	 * @return \WC_Order|\WP_Error
	 */
	public static function create_renewal_order( \WC_Order $subscription ) {
		if ( ! empty( $GLOBALS[ self::RENEWAL_ORDER_ERROR ] ) ) {
			return new \WP_Error( 'renewal_order_error', 'Renewal order could not be created.' );
		}

		$order = new \WC_Order();
		$order->set_customer_id( $subscription->get_customer_id() );
		$order->set_currency( $subscription->get_currency() );
		$order->set_address( $subscription->get_address( 'billing' ), 'billing' );
		foreach ( $subscription->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$copy = new \WC_Order_Item_Product();
				$copy->set_product_id( $item->get_product_id() );
				$copy->set_quantity( $item->get_quantity() );
				$copy->set_subtotal( $item->get_subtotal() );
				$copy->set_total( $item->get_total() );
				$order->add_item( $copy );
			}
		}
		$order->set_total( $subscription->get_total() );
		$order->update_meta_data( '_subscription_renewal', $subscription->get_id() );
		$order->save();

		$GLOBALS[ self::RENEWAL_SUBSCRIPTIONS ][ $order->get_id() ][]          = $subscription->get_id();
		$GLOBALS[ self::ORDER_SUBSCRIPTIONS ][ $order->get_id() ]['renewal'][] = $subscription->get_id();

		return $order;
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
