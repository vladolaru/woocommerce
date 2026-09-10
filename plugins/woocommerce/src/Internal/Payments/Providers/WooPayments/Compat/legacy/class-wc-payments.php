<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The plugin-owned class name and file shape are the compatibility contract.
/**
 * Legacy WC_Payments facade.
 */

declare( strict_types = 1 );

/**
 * Compatibility facade for extensions that detect WooPayments through its plugin bootstrap class.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 Use the native WooPayments gateway via WC()->payment_gateways(). Scheduled for removal in WooCommerce 12.0.0.
 */
class WC_Payments {
	// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps

	/**
	 * Return the container-owned native WooPayments gateway.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use the native WooPayments gateway via WC()->payment_gateways().
	 *
	 * @return Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway
	 */
	public static function get_gateway() {
		_deprecated_function( 'WC_Payments::get_gateway', '11.0.0', 'the native WooPayments gateway via WC()->payment_gateways()' );

		return wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway::class );
	}

	/**
	 * Keep the legacy settings-page gateway-hiding call safe.
	 *
	 * The native runtime exposes no secondary plugin gateway classes to remove.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use the native WooPayments gateway collection via WC()->payment_gateways().
	 *
	 * @return void
	 */
	public static function hide_gateways_on_settings_page() {
		_deprecated_function( 'WC_Payments::hide_gateways_on_settings_page', '11.0.0', 'the native WooPayments gateway collection via WC()->payment_gateways()' );
	}
}
