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
	 * Define the doubles that are not defined yet.
	 */
	public static function load(): void {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public product helper in the global namespace.
			eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { $product_id = $product instanceof WC_Product ? $product->get_id() : absint( $product ); return in_array( $product_id, $GLOBALS["' . self::SUBSCRIPTION_PRODUCT_IDS . '"] ?? array(), true ); } } }' );
		}
	}
}
