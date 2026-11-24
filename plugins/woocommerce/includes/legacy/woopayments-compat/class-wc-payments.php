<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The WooPayments extension's class name and file shape are the compatibility contract.
/**
 * WC_Payments facade.
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsCompatibilityNotice;
use WCPay\Core\Mode;

/**
 * Stands in for the WooPayments extension's WC_Payments class, so extensions that detect WooPayments by it keep working.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.2.0
 * @deprecated 11.2.0 Use the WooPayments gateway via WC()->payment_gateways(). Scheduled for removal in WooCommerce 12.0.0.
 */
class WC_Payments {
	// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps

	use WooPaymentsCompatibilityNotice;

	/**
	 * Return the shared WooPayments mode for this request.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return Mode
	 */
	public static function mode() {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		static $mode = null;
		if ( null === $mode ) {
			$mode = new Mode();
		}
		return $mode;
	}

	/**
	 * Return the built-in WooPayments gateway from the container.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.2.0
	 * @deprecated 11.4.0 Use WC()->payment_gateways()->payment_gateways()['woocommerce_payments'] ?? null instead.
	 *
	 * @return Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway
	 */
	public static function get_gateway() {
		self::warn_compatibility_method( __METHOD__, "WC()->payment_gateways()->payment_gateways()['woocommerce_payments'] ?? null" );

		return wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway::class );
	}

	/**
	 * Do nothing: the built-in WooPayments has no secondary gateway classes to hide on the settings page.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.2.0
	 * @deprecated 11.4.0 No public replacement.
	 *
	 * @return void
	 */
	public static function hide_gateways_on_settings_page() {
		self::warn_compatibility_method( __METHOD__ );
	}
}
