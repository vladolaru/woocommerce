<?php
/**
 * WooPaymentsClientVersion class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;

/**
 * The WooPayments client version the native runtime declares to the platform.
 *
 * The platform gates behavior on the client version every request reports: payment-method
 * availability (`minimum_client_version`), account-status mapping, response shapes, and the
 * client version recorded against the account for fraud signals and support tooling. The
 * standalone plugin reports its live release number; the native runtime instead declares the
 * plugin version whose platform behavior it was verified against, so the platform serves the
 * exact response contract the native port implements.
 *
 * The user agent carries two parts. `WooCommerce Payments/<VERSION>` is the pinned platform-behavior
 * version: the platform's parser reads only this version, and every gate compares against it. The
 * suffix `-native-woocommerce/<WooCommerce version>` is the real runtime identity: the parser ignores
 * any `-` suffix after the version, so it tells native stores and their WooCommerce release apart
 * without changing what the platform serves. Client 11.1.0 sends its plugin version alone.
 *
 * Bump policy: this constant must NOT track WooCommerce releases automatically. Before
 * bumping, review every platform-side gate between the current value and the target version
 * (`is_client_version_at_least()` call sites and `minimum_client_version` entries) and port
 * the behavior each gate unlocks. Bumping without that review makes the platform serve
 * responses the native runtime does not understand; never bumping makes new payment methods
 * and response improvements silently unavailable to native stores. Each bump is a deliberate,
 * reviewed change with its own tracking issue.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsClientVersion {

	/**
	 * The WooPayments plugin version whose platform behavior the native runtime implements.
	 *
	 * @var string
	 */
	public const VERSION = '11.1.0';

	/**
	 * Build the client identity string reported to the platform.
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
