<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The WooPayments extension's class name and file shape are the compatibility contract.
/**
 * WC_Payments_Utils facade.
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsCompatibilityNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;

/**
 * Provides the WooPayments amount helper used by payment integrations.
 *
 * @since 11.4.0
 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
 */
class WC_Payments_Utils {
	// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps

	use WooPaymentsCompatibilityNotice;

	/**
	 * Round an amount to the integer units used by the extension.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @param float|string $amount   Amount in major currency units.
	 * @param scalar|null  $currency Currency code.
	 * @return int
	 */
	public static function prepare_amount( $amount, $currency = 'USD' ): int {
		self::warn_compatibility_method( __METHOD__ );

		$multiplier = WooPaymentsCurrencyUtils::is_zero_decimal_currency( (string) $currency ) ? 1 : 100;
		return (int) round( (float) $amount * $multiplier );
	}
}
