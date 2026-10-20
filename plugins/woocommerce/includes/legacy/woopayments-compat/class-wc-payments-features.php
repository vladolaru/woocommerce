<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The WooPayments extension's class name and file shape are the compatibility contract.
/**
 * WC_Payments_Features facade.
 */

declare( strict_types = 1 );

/**
 * Stands in for the WooPayments extension's WC_Payments_Features class, so the feature check extensions make keeps working.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.2.0
 * @deprecated 11.2.0 Use the WooPayments gateway's capabilities. Scheduled for removal in WooCommerce 12.0.0.
 */
class WC_Payments_Features {
	// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps

	/**
	 * Report that the extension's bundled subscriptions feature is off, as it always is while the built-in WooPayments owns payments.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.2.0
	 * @deprecated 11.2.0 Use the WooPayments gateway's supports() method.
	 *
	 * @return bool
	 */
	public static function is_wcpay_subscriptions_enabled() {
		_deprecated_function( 'WC_Payments_Features::is_wcpay_subscriptions_enabled', '11.2.0', 'the built-in WooPayments gateway supports() method' );

		return false;
	}
}
