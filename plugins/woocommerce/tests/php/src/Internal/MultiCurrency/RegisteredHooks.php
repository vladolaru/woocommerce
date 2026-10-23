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
	 * @throws \LogicException When a method sits on one hook at two priorities, which the map could not show.
	 */
	public static function of( object $instance ): array {
		global $wp_filter;

		$registered = array();
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $instance === $callback['function'][0] ) {
						$method = $callback['function'][1];
						if ( isset( $registered[ $hook ][ $method ] ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test failure message, not output.
							throw new \LogicException( sprintf( '%1$s is on %2$s at priorities %3$d and %4$d.', $method, $hook, $registered[ $hook ][ $method ][0], $priority ) );
						}
						$registered[ $hook ][ $method ] = array( $priority, $callback['accepted_args'] );
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
