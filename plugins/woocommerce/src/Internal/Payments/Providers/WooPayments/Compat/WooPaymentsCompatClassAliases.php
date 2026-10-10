<?php
/**
 * WooPaymentsCompatClassAliases class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;

/**
 * Registers two WooPayments extension class names as aliases of the classes that answer for them.
 *
 * Each alias is declared on first use, where the class is about to reach a hook or WooCommerce Subscriptions, and only
 * when no loaded or autoloadable class already has the name. Removed in WooCommerce 12.0.0, together with its call sites
 * (see README.md).
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsCompatClassAliases {

	/**
	 * The extension class name each native class declares.
	 */
	private const ALIASES = array(
		WooPaymentsPaymentType::class                    => 'WCPay\Constants\Payment_Type',
		WooPaymentsFailedAuthenticationRetryEmail::class => 'WC_Payments_Email_Failed_Authentication_Retry',
	);

	/**
	 * Declare the extension class name of a class, unless a class with that name is loaded or an autoloader can load it.
	 *
	 * The check autoloads, so an active WooPayments extension's own class keeps the name.
	 *
	 * @param class-string $class_name Class whose extension name is declared.
	 */
	public static function register( string $class_name ): void {
		$alias = self::ALIASES[ $class_name ] ?? null;

		if ( null !== $alias && ! class_exists( $alias, true ) ) {
			class_alias( $class_name, $alias );
		}
	}
}
