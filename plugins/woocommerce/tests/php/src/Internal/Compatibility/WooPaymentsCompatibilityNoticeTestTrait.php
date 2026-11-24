<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Compatibility;

/**
 * Gives in-process tests a fresh request's set of WooPayments compatibility warnings.
 */
trait WooPaymentsCompatibilityNoticeTestTrait {

	/**
	 * Clear the methods already reported by other tests in the same PHP process.
	 *
	 * @param class-string ...$classes Facades whose methods the test exercises.
	 */
	private function reset_compatibility_notices( string ...$classes ): void {
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class, false ) ) {
				continue;
			}
			$warned = new \ReflectionProperty( $class, 'warned_compatibility_methods' );
			if ( PHP_VERSION_ID < 80100 ) {
				$warned->setAccessible( true );
			}
			$warned->setValue( null, array() );
		}
	}
}
