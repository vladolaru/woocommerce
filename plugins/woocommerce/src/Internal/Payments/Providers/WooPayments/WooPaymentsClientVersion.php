<?php
/**
 * WooPaymentsClientVersion class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;

/**
 * The WooPayments version this store reports to the platform.
 *
 * The platform decides payment-method availability, account-status mapping and response shapes from the version each
 * request reports, and calls it the client version. Change it only after implementing here every platform behaviour
 * that differs between the two versions. The user agent appends `-native-woocommerce/<WooCommerce version>`, which
 * the platform's version parser ignores, so support can tell WooCommerce's built-in WooPayments apart from the
 * WooPayments extension.
 *
 * @since 11.0.0
 * @internal
 */
final class WooPaymentsClientVersion {

	/**
	 * The WooPayments version whose platform responses this code implements.
	 *
	 * @var string
	 */
	public const VERSION = '11.1.0';

	/**
	 * Build the identity string reported to the platform.
	 *
	 * Used as the transport `User-Agent` header and inside mandate customer-acceptance records.
	 * The suffix must start with `-` right after the version, or the platform's parser rejects
	 * the whole string and every client-version gate fails.
	 *
	 * @return string
	 */
	public static function get_user_agent(): string {
		return 'WooCommerce Payments/' . self::get_reported_version();
	}

	/**
	 * The version reported to the platform, as the status report's Version row shows it.
	 *
	 * @return string
	 */
	public static function get_reported_version(): string {
		return self::VERSION . '-native-woocommerce/' . self::get_woocommerce_version();
	}

	/**
	 * The running WooCommerce version, without the `-dev` tag of a trunk build.
	 *
	 * Beta and release candidate tags stay: they name published builds. Only characters valid in a
	 * version and in a header value are kept, since `WC_VERSION` can be defined outside core.
	 *
	 * @return string
	 */
	private static function get_woocommerce_version(): string {
		$version = Constants::get_constant( 'WC_VERSION' );
		$version = preg_replace( '/-dev$/', '', is_string( $version ) ? $version : '' );

		return (string) preg_replace( '/[^0-9A-Za-z.+-]/', '', (string) $version );
	}
}
