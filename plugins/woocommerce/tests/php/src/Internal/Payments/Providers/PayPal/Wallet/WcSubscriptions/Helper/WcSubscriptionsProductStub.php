<?php
/**
 * Stand-in for WooCommerce Subscriptions' product class, which core's test suite does not load.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch, SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName, Squiz.Classes.ValidClassName.NotCamelCaps, Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! class_exists( 'WC_Subscriptions_Product', false ) ) {
	/**
	 * Answers `is_subscription()` from a list of product IDs the test fills. Only this method exists: the wallet code
	 * reads nothing else from the class on the paths these tests run. `WC_Subscriptions` itself stays undefined, so
	 * `SubscriptionHelper::plugin_is_active()` is still false in the suite.
	 */
	class WC_Subscriptions_Product {

		/**
		 * IDs of the products that count as subscriptions.
		 *
		 * @var int[]
		 */
		public static array $subscription_product_ids = array();

		/**
		 * Whether the product is a subscription, as the test says.
		 *
		 * @param mixed $product A product or a product ID.
		 * @return bool
		 */
		public static function is_subscription( $product ): bool {
			$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;

			return in_array( $id, self::$subscription_product_ids, true );
		}
	}
}
// phpcs:enable
