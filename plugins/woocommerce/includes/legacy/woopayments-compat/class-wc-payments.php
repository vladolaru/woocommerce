<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The WooPayments extension's class name and file shape are the compatibility contract.
/**
 * WC_Payments facade.
 */

declare( strict_types = 1 );

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

	/**
	 * Return the built-in WooPayments gateway from the container.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.2.0
	 * @deprecated 11.2.0 Use the WooPayments gateway via WC()->payment_gateways().
	 *
	 * @return Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway
	 */
	public static function get_gateway() {
		_deprecated_function( 'WC_Payments::get_gateway', '11.2.0', 'the native WooPayments gateway via WC()->payment_gateways()' );

		return wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway::class );
	}

	/**
	 * Do nothing: the built-in WooPayments has no secondary gateway classes to hide on the settings page.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.2.0
	 * @deprecated 11.2.0 Use the WooPayments gateway collection via WC()->payment_gateways().
	 *
	 * @return void
	 */
	public static function hide_gateways_on_settings_page() {
		_deprecated_function( 'WC_Payments::hide_gateways_on_settings_page', '11.2.0', 'the native WooPayments gateway collection via WC()->payment_gateways()' );
	}
}
