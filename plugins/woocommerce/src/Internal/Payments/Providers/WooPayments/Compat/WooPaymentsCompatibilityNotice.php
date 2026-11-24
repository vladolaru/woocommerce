<?php
/**
 * WooPayments compatibility notice.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

/**
 * Reports calls to WooPayments compatibility methods scheduled for removal in WooCommerce 12.0.0.
 *
 * @since 11.4.0
 * @internal
 */
trait WooPaymentsCompatibilityNotice {

	/**
	 * Methods already reported in this request.
	 *
	 * @var array<string,true>
	 */
	private static array $warned_compatibility_methods = array();

	/**
	 * Warn once for each compatibility method, including when a warning listener calls it again.
	 *
	 * @since 11.4.0
	 *
	 * @param string      $method      Qualified method name from __METHOD__.
	 * @param string|null $replacement Public replacement at the PHP call site, if available.
	 */
	private static function warn_compatibility_method( string $method, ?string $replacement = null ): void {
		if ( isset( self::$warned_compatibility_methods[ $method ] ) ) {
			return;
		}
		self::$warned_compatibility_methods[ $method ] = true;

		$member  = $method . '()';
		$message = null === $replacement
			? sprintf( '%s is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.', $member )
			: sprintf( 'Use %s instead.', $replacement );

		_doing_it_wrong( esc_html( $member ), esc_html( $message ), esc_html( WC()->version ) );
	}
}
