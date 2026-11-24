<?php
/**
 * WooPayments compatibility notice.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Utilities;

/**
 * Reports calls to WooPayments compatibility methods scheduled for removal in WooCommerce 12.0.0.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsCompatibilityNotice {

	/**
	 * Methods already reported in this request.
	 *
	 * @var array<string,true>
	 */
	private static array $warned = array();

	/**
	 * Warn once for each compatibility method, including when a warning listener calls it again.
	 *
	 * @since 11.4.0
	 *
	 * @param string      $method      Qualified method name from __METHOD__.
	 * @param string|null $replacement Public replacement at the PHP call site, if available.
	 */
	public static function warn( string $method, ?string $replacement = null ): void {
		if ( isset( self::$warned[ $method ] ) ) {
			return;
		}
		self::$warned[ $method ] = true;

		$member  = $method . '()';
		$message = null === $replacement
			? sprintf( '%s is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.', $member )
			: sprintf( 'Use %s instead.', $replacement );

		wc_doing_it_wrong( $member, $message, WC()->version );
	}
}
