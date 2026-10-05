<?php
/**
 * Extension doubles for the express checkout service tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * Defines stand-ins for the WooCommerce Deposits API that express checkout calls.
 *
 * WooCommerce Deposits is not installed in the test environment and its source is not available locally, so the double
 * mirrors the client 11.1.0 test helper (`tests/unit/helpers/class-wc-helper-deposit-product-manager.php`): settings
 * come from product meta, and products without that meta are not deposit products, so the double changes nothing
 * for later tests. An ID with no product reads as a product without Deposits settings instead of failing. It also
 * records the plan each `get_deposit_amount()` call asks for.
 *
 * Never define `WC_Deposits` here: other code reads its absence as WooCommerce Deposits being inactive.
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
}
