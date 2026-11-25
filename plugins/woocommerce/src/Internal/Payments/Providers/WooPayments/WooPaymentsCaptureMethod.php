<?php
/**
 * WooPaymentsCaptureMethod class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Capture methods sent to the platform for a payment intent.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsCaptureMethod {

	/**
	 * Capture the payment automatically.
	 *
	 * @var string
	 */
	public const AUTOMATIC = 'automatic';

	/**
	 * Authorize the payment for a later capture.
	 *
	 * @var string
	 */
	public const MANUAL = 'manual';
}
