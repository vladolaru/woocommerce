<?php
/**
 * WordPress database stub for the standalone secretless provider fixture test.
 *
 * @package woopayments-native-ci-fixture
 */

declare( strict_types = 1 );

/**
 * Stands in for `$wpdb` in the standalone harness. The fixture issues only MySQL named-lock queries directly, so the
 * stub grants each lock and records which ones are still held.
 */
final class Fixture_Test_Wpdb {
	/**
	 * Named locks taken and not yet released.
	 *
	 * @var array<string,true>
	 */
	public array $held_locks = array();

	/**
	 * Quotes each placeholder argument into the query.
	 *
	 * @param string $query Query with %s placeholders.
	 * @param mixed  ...$args Placeholder values.
	 */
	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * Grants a GET_LOCK query.
	 *
	 * @param string $query Prepared query.
	 */
	public function get_var( string $query ): string {
		if ( 1 === preg_match( "/GET_LOCK\\( '([^']+)'/", $query, $match ) ) {
			$this->held_locks[ $match[1] ] = true;
			return '1';
		}
		return '0';
	}

	/**
	 * Releases a RELEASE_LOCK query.
	 *
	 * @param string $query Prepared query.
	 */
	public function query( string $query ): int {
		if ( 1 === preg_match( "/RELEASE_LOCK\\( '([^']+)'/", $query, $match ) ) {
			unset( $this->held_locks[ $match[1] ] );
		}
		return 1;
	}
}
