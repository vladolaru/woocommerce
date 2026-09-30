<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin;

use ReflectionClass;
use ReflectionMethod;

/**
 * Shared assertions for deprecated backward-compatibility stubs: every public method is covered,
 * and a call adds no hooks and writes no options or transients.
 */
trait DeprecatedStubAssertionsTrait {

	/**
	 * Assert the public methods declared on the stub class are exactly the covered ones.
	 *
	 * @param string   $class_name      Stub class name.
	 * @param string[] $covered_methods Method names the test covers.
	 */
	private function assert_covers_every_public_method( string $class_name, array $covered_methods ): void {
		$declared = array();
		foreach ( ( new ReflectionClass( $class_name ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $class_name === $method->getDeclaringClass()->getName() ) {
				$declared[] = $method->getName();
			}
		}
		sort( $declared );
		sort( $covered_methods );

		$this->assertSame( $covered_methods, $declared, "Every public method of {$class_name} must be covered by the stub test." );
	}

	/**
	 * Run the call and assert it added no hooks and wrote no options or transients.
	 *
	 * @param callable $call The call to run.
	 * @return mixed The call's return value.
	 */
	private function call_without_side_effects( callable $call ) {
		$writes   = array();
		$recorder = static function ( $name ) use ( &$writes ) {
			$writes[] = $name;
		};
		foreach ( array( 'added_option', 'updated_option', 'deleted_option', 'setted_transient', 'deleted_transient' ) as $write_hook ) {
			add_action( $write_hook, $recorder );
		}

		$hooks_before = $this->hook_callback_snapshot();
		$result       = $call();

		$this->assertSame( $hooks_before, $this->hook_callback_snapshot(), 'A deprecated stub must not add or remove hook callbacks.' );
		$this->assertSame( array(), $writes, 'A deprecated stub must not write options or transients.' );

		return $result;
	}

	/**
	 * Registered hook callback IDs, keyed by hook and priority.
	 *
	 * @return array<string,array<int,array<int,string>>>
	 */
	private function hook_callback_snapshot(): array {
		global $wp_filter;

		$snapshot = array();
		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				if ( array() !== $callbacks ) {
					$snapshot[ $hook_name ][ $priority ] = array_keys( $callbacks );
				}
			}
		}

		return $snapshot;
	}
}
