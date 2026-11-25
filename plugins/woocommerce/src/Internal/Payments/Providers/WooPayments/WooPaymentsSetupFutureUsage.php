<?php
/**
 * WooPaymentsSetupFutureUsage class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * How the payment method may be used after checkout.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsSetupFutureUsage {

	/**
	 * Allow future payments while the customer is away.
	 *
	 * @var string
	 */
	public const OFF_SESSION = 'off_session';
}
