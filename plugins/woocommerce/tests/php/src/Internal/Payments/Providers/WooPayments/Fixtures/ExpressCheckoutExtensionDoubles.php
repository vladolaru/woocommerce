<?php
/**
 * Extension doubles for the express checkout service tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * Defines stand-ins for the WooCommerce Deposits and WooCommerce Pre-Orders APIs that express checkout calls.
 *
 * Neither extension is installed in the test environment. Settings come from product meta, and products without that
 * meta are neither deposit nor pre-order products, so the doubles change nothing for later tests.
 *
 * - Deposits: its source is not available locally, so the double mirrors the client 11.1.0 test helper
 *   (`tests/unit/helpers/class-wc-helper-deposit-product-manager.php`), except that an ID with no product reads as a
 *   product without Deposits settings instead of failing. It also records the plan each `get_deposit_amount()` call
 *   asks for.
 * - Pre-Orders: the cart methods and `product_is_charged_upon_release()` copy WooCommerce Pre-Orders 2.3.5
 *   (`class-wc-pre-orders-cart.php:265-335`, `class-wc-pre-orders-product.php:439-450`);
 *   `product_can_be_pre_ordered()` keeps only its `_wc_pre_orders_enabled` meta check.
 *
 * Never define `WC_Deposits` or `WC_Pre_Orders` here: other code reads their absence as the extension being inactive.
 */
final class ExpressCheckoutExtensionDoubles {

	/**
	 * Global holding the plan IDs passed to `WC_Deposits_Product_Manager::get_deposit_amount()`.
	 */
	public const DEPOSIT_AMOUNT_PLAN_IDS = 'wc_test_deposit_amount_plan_ids';

	/**
	 * Define `WC_Deposits_Product_Manager` and `WC_Deposits_Plans_Manager`.
	 */
	public static function load_deposits(): void {
		if ( ! class_exists( 'WC_Deposits_Product_Manager' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Deposits is optional; tests need its product manager in the global namespace.
			eval( 'namespace { class WC_Deposits_Product_Manager { public static function get_product( $product ) { $product = is_object( $product ) ? $product : wc_get_product( $product ); return $product instanceof WC_Product ? $product : new WC_Product(); } public static function get_deposit_type( $product ) { return self::get_product( $product )->get_meta( "_wc_deposit_type" ); } public static function get_deposit_selected_type( $product ) { return self::get_product( $product )->get_meta( "_wc_deposit_selected_type" ); } public static function deposits_enabled( $product ) { $setting = self::get_product( $product )->get_meta( "_wc_deposit_enabled" ); return "optional" === $setting || "forced" === $setting; } public static function get_deposit_amount( $product, $plan_id = 0, $context = "display", $product_price = null ) { $GLOBALS["' . self::DEPOSIT_AMOUNT_PLAN_IDS . '"][] = $plan_id; $product = self::get_product( $product ); $amount = $product->get_meta( "_wc_deposit_amount" ); if ( ! $amount ) { $amount = get_option( "wc_deposits_default_amount" ); } if ( ! $amount ) { return false; } if ( "percent" === self::get_deposit_type( $product ) ) { $product_price = null === $product_price ? $product->get_price() : $product_price; $amount = ( $product_price / 100 ) * $amount; } return wc_format_decimal( $amount ); } } }' );
		}

		if ( ! class_exists( 'WC_Deposits_Plans_Manager' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Deposits is optional; tests need its plans manager in the global namespace.
			eval( 'namespace { class WC_Deposits_Plans_Manager { public static function get_plan_ids_for_product( $product_id ) { $product = WC_Deposits_Product_Manager::get_product( $product_id ); $map = array_map( "absint", array_filter( (array) $product->get_meta( "_wc_deposit_payment_plans" ) ) ); if ( count( $map ) <= 0 ) { $map = self::get_default_plan_ids(); } return $map; } public static function get_default_plan_ids() { return array_map( "absint", array_filter( (array) get_option( "wc_deposits_default_plans", array() ) ) ); } } }' );
		}
	}

	/**
	 * Define `WC_Pre_Orders_Product` and `WC_Pre_Orders_Cart`.
	 */
	public static function load_pre_orders(): void {
		if ( ! class_exists( 'WC_Pre_Orders_Product' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Pre-Orders is optional; tests need its product helper in the global namespace.
			eval( 'namespace { class WC_Pre_Orders_Product { public static function product_can_be_pre_ordered( $product ) { $product = is_object( $product ) ? $product : wc_get_product( $product ); if ( ! is_object( $product ) ) { return false; } $product_id = $product->is_type( "variation" ) ? $product->get_parent_id() : $product->get_id(); return "yes" === get_post_meta( $product_id, "_wc_pre_orders_enabled", true ); } public static function product_is_charged_upon_release( $product ) { if ( ! is_object( $product ) ) { $product = wc_get_product( $product ); if ( ! is_object( $product ) ) { return false; } } return "upon_release" === get_post_meta( $product->is_type( "variation" ) ? $product->get_parent_id() : $product->get_id(), "_wc_pre_orders_when_to_charge", true ); } } }' );
		}

		if ( ! class_exists( 'WC_Pre_Orders_Cart' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Pre-Orders is optional; tests need its cart helper in the global namespace.
			eval( 'namespace { class WC_Pre_Orders_Cart { public static function cart_contains_pre_order() { foreach ( WC()->cart->cart_contents as $cart_item ) { $product_id = ! empty( $cart_item["variation_id"] ) ? $cart_item["variation_id"] : $cart_item["product_id"]; if ( WC_Pre_Orders_Product::product_can_be_pre_ordered( $product_id ) ) { return true; } } return false; } public static function get_pre_order_product() { if ( ! self::cart_contains_pre_order() ) { return null; } foreach ( WC()->cart->cart_contents as $cart_item ) { $product_id = ! empty( $cart_item["variation_id"] ) ? $cart_item["variation_id"] : $cart_item["product_id"]; if ( WC_Pre_Orders_Product::product_can_be_pre_ordered( $product_id ) ) { return wc_get_product( $product_id ); } } return null; } } }' );
		}
	}
}
