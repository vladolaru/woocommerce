<?php
/**
 * WooPaymentsRefundStatus class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Refund states returned by the platform.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsRefundStatus {

	/**
	 * The platform reports the refund as canceled.
	 *
	 * @var string
	 */
	public const CANCELED = 'canceled';

	/**
	 * The platform reports the refund as failed.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The platform reports the refund as succeeded.
	 *
	 * @var string
	 */
	public const SUCCEEDED = 'succeeded';
}
