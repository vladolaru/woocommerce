<?php
/**
 * Test function shadows for the WooPayments provider namespace.
 *
 * Defining sleep() inside the provider namespace lets the order received page's re-check run without its one-second
 * wait. PHP resolves an unqualified sleep() call in that namespace to this function before the global one, so
 * production code stays untouched, as core's Webflow client test does (Migrator/Platforms/Webflow/functions-mock.php).
 * PHPUnit loads every test file before the first test runs, so the shadow holds for the whole run: any later unqualified
 * sleep() in this namespace is silenced in every test too.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

if ( ! function_exists( __NAMESPACE__ . '\\sleep' ) ) {
	/**
	 * No-op replacement for the global sleep() within this namespace, so tests do not wait.
	 *
	 * @param int $seconds Number of seconds (ignored).
	 * @return int Always 0, matching a completed sleep.
	 */
	function sleep( $seconds ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing, WordPress.NamingConventions
		unset( $seconds );
		return 0;
	}
}
