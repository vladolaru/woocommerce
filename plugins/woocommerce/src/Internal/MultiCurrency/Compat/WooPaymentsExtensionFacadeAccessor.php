<?php
/**
 * The two legacy accessors share one object per request.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Compat;

use WCPay\MultiCurrency\MultiCurrency;

/**
 * Supplies the Multi-Currency compatibility object.
 *
 * @internal
 * @since 11.4.0
 */
final class WooPaymentsExtensionFacadeAccessor {

	use WooPaymentsCompatibilityNotice;

	/**
	 * Get the request's legacy object.
	 */
	public static function get(): MultiCurrency {
		static $instance;
		if ( null === $instance ) {
			$instance = new MultiCurrency();
		}
		return $instance;
	}

	/**
	 * Report the global accessor independently of a direct singleton call.
	 */
	public static function from_function(): MultiCurrency {
		self::warn_compatibility_method( 'WC_Payments_Multi_Currency' );
		return self::get();
	}
}
