<?php
/**
 * WooPayments compatibility notice.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\IncorrectUsageNotice;

/**
 * Reports calls to WooPayments compatibility methods scheduled for removal in WooCommerce 12.0.0.
 *
 * @since 11.4.0
 * @internal
 */
trait WooPaymentsCompatibilityNotice {

	use IncorrectUsageNotice;

	/**
	 * Warn once for each compatibility method, including when a warning listener calls it again.
	 *
	 * @since 11.4.0
	 *
	 * @param string      $method      Qualified method name from __METHOD__.
	 * @param string|null $replacement Public replacement at the PHP call site, if available.
	 */
	private static function warn_compatibility_method( string $method, ?string $replacement = null ): void {
		$member  = $method . '()';
		$message = null === $replacement
			? sprintf( '%s is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.', $member )
			: sprintf( 'Use %s instead.', $replacement );

		self::warn_once( $method, $message );
	}
}
