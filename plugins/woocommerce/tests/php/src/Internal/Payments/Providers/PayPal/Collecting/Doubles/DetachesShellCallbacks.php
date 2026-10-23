<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

/**
 * Detaches the callbacks the shell's own instances attached when WooCommerce loaded for the test run, so a test's
 * instance is the only one that answers a hook. WP_UnitTestCase puts the hooks back after each test.
 */
trait DetachesShellCallbacks {

	/**
	 * Remove every callback on a hook that is a method of an object of a class, other than the test's own object.
	 *
	 * @param string      $hook           The hook.
	 * @param string      $callback_class The class of the callbacks' objects.
	 * @param object|null $keep           The object whose callbacks stay.
	 */
	private function detach_shell_callbacks( string $hook, string $callback_class, ?object $keep = null ): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof $callback_class && $function[0] !== $keep ) {
					remove_filter( $hook, $function, $priority );
				}
			}
		}
	}
}
