<?php
/**
 * Multi-Currency developer notices.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

/**
 * Reports incorrect usage once per method and request.
 *
 * @internal
 * @since 11.4.0
 */
trait IncorrectUsageNotice {

	/**
	 * Methods already reported in this request.
	 *
	 * @var array<string,true>
	 */
	private static array $warned_compatibility_methods = array();

	/**
	 * Mark the method before notifying listeners, which may call it again.
	 *
	 * @param string $method  Qualified method name.
	 * @param string $message Developer notice.
	 */
	private static function warn_once( string $method, string $message ): void {
		if ( isset( self::$warned_compatibility_methods[ $method ] ) ) {
			return;
		}
		self::$warned_compatibility_methods[ $method ] = true;
		_doing_it_wrong( esc_html( $method . '()' ), esc_html( $message ), '11.4.0' );
	}
}
