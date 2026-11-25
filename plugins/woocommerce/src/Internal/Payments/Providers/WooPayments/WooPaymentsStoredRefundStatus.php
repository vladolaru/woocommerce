<?php
/**
 * WooPaymentsStoredRefundStatus class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Refund states recorded on WooCommerce refunds.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsStoredRefundStatus {

	/**
	 * The refund is recorded as failed locally.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The refund is recorded as pending locally.
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * The refund is recorded as successful locally.
	 *
	 * @var string
	 */
	public const SUCCESSFUL = 'successful';
}
