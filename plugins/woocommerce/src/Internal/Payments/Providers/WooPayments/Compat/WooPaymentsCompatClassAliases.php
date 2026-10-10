<?php
/**
 * WooPaymentsCompatClassAliases class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;

/**
 * Registers the WooPayments extension's class names as aliases of the classes that answer for them.
 *
 * Each alias is declared on first use, where the class is about to reach a hook or WooCommerce Subscriptions,
 * and never when the name is already declared. Removed in WooCommerce 12.0.0, together with its call sites (see README.md).
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsCompatClassAliases {

	/**
	 * Extension class names each class declares, with the class each name aliases, in declaration order.
	 *
	 * A class also declares the names of its listed parent classes, first.
	 */
	private const ALIASES = array(
		WooPaymentsPaymentType::class                    => array(
			'WCPay\Constants\Payment_Type' => WooPaymentsPaymentType::class,
		),
		WooPaymentsFailedAuthenticationRetryEmail::class => array(
			'WC_Payments_Email_Failed_Authentication_Retry' => WooPaymentsFailedAuthenticationRetryEmail::class,
		),
	);

	/**
	 * Extension class names checked with autoloading before they are declared, so a class an autoloader can load is
	 * left to it; every other name is checked without autoloading.
	 */
	private const AUTOLOADED_NAMES = array(
		'WCPay\Constants\Payment_Type',
		'WC_Payments_Email_Failed_Authentication_Retry',
	);

	/**
	 * Declare the extension class names of a class and of its listed parent classes, parents first.
	 *
	 * @param class-string $class_name Class whose extension names are declared.
	 */
	public static function register( string $class_name ): void {
		$parents = class_parents( $class_name );
		$classes = false === $parents ? array() : array_reverse( array_values( $parents ) );
		array_push( $classes, $class_name );

		foreach ( $classes as $class ) {
			foreach ( self::ALIASES[ $class ] ?? array() as $alias => $original ) {
				if ( ! class_exists( $alias, in_array( $alias, self::AUTOLOADED_NAMES, true ) ) ) {
					class_alias( $original, $alias );
				}
			}
		}
	}
}
