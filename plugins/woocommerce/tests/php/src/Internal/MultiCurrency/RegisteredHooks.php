<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

/**
 * Reads the hook callbacks an object has registered, for registration assertions at the hook boundary.
 */
final class RegisteredHooks {

	/**
	 * Every callback the object has on a WordPress hook, by hook name then method, as array( priority, accepted args ).
	 *
	 * @param object $instance The object whose methods are hooked.
	 * @return array<string,array<string,int[]>> Sorted by hook name, then method.
	 */
	public static function of( object $instance ): array {
		global $wp_filter;

		$registered = array();
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $instance === $callback['function'][0] ) {
						$registered[ $hook ][ $callback['function'][1] ] = array( $priority, $callback['accepted_args'] );
					}
				}
			}
		}

		ksort( $registered );
		foreach ( $registered as $hook => $methods ) {
			ksort( $methods );
			$registered[ $hook ] = $methods;
		}

		return $registered;
	}
}
