<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The plugin-owned class name and file shape are the compatibility contract.
/**
 * Legacy WC_Payments_Features facade.
 */

declare( strict_types = 1 );

/**
 * Compatibility facade for the retired standalone WooPayments feature flags.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 Use native WooPayments gateway capabilities. Scheduled for removal in WooCommerce 12.0.0.
 */
class WC_Payments_Features {
	// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps

	/**
	 * Report that the retired WooPayments Subscriptions feature is disabled.
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Use the native WooPayments gateway supports() method.
	 *
	 * @return bool
	 */
	public static function is_wcpay_subscriptions_enabled() {
		_deprecated_function( 'WC_Payments_Features::is_wcpay_subscriptions_enabled', '11.0.0', 'the native WooPayments gateway supports() method' );

		return false;
	}
}
