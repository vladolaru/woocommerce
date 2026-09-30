<?php
/**
 * WooPaymentsOrderMode class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Values stored in the `_wcpay_mode` order meta: the mode a WooPayments payment was processed in.
 *
 * Mirrors plugin 11.1.0 `WCPay\Constants\Order_Mode` (includes/constants/class-order-mode.php). These are
 * order values, not the account mode: a live account writes `prod`, never `live`.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsOrderMode {

	/**
	 * The payment was processed in test mode.
	 *
	 * @var string
	 */
	public const TEST = 'test';

	/**
	 * The payment was processed in live mode.
	 *
	 * @var string
	 */
	public const PRODUCTION = 'prod';
}
