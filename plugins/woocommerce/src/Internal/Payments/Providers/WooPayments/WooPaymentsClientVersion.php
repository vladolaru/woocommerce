<?php
/**
 * WooPaymentsClientVersion class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

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
	public const VERSION = '10.8.0';

	/**
	 * Build the client identity string reported to the platform.
	 *
	 * Used as the transport `User-Agent` header and inside mandate customer-acceptance
	 * records; must keep the `WooCommerce Payments/<version>` shape the platform's
	 * client-version parser expects.
	 *
	 * @return string
	 */
	public static function get_user_agent(): string {
		return 'WooCommerce Payments/' . self::VERSION;
	}
}
