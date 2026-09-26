<?php
/**
 * WooPaymentsFrontendAssets class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;

/**
 * Registers frontend assets shared by native WooPayments surfaces.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsFrontendAssets {

	public const APPEARANCE_SCRIPT_HANDLE = 'wc-woopayments-appearance';

	/**
	 * Register the shared Stripe Elements appearance utility.
	 */
	public static function register_appearance_script(): void {
		if ( wp_script_is( self::APPEARANCE_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
		wp_register_script(
			self::APPEARANCE_SCRIPT_HANDLE,
			WC()->plugin_url() . '/assets/js/frontend/utils/woopayments-appearance' . $suffix . '.js',
			array(),
			WC_VERSION,
			true
		);
	}
}
