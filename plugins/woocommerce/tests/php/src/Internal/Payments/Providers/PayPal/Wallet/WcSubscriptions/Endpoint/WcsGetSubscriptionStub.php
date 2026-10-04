<?php
/**
 * Stand-in for WooCommerce Subscriptions' `wcs_get_subscription()`, which core's test suite does not load.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Endpoint
 */

declare( strict_types = 1 );

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * Returns the subscription a test registered under the ID, or false. A test fills
	 * `$GLOBALS['wallet_test_wcs_subscriptions']` (ID => order) and empties it afterwards.
	 *
	 * @param mixed $the_subscription A subscription ID.
	 * @return \WC_Order|false
	 */
	function wcs_get_subscription( $the_subscription ) {
		return $GLOBALS['wallet_test_wcs_subscriptions'][ (int) $the_subscription ] ?? false;
	}
}
// phpcs:enable
