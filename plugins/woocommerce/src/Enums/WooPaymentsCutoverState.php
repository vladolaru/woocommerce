<?php
/**
 * WooPaymentsCutoverState enum class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Enums;

/**
 * Cutover reconciliation states persisted while WooPayments moves into core.
 *
 * @since 11.2.0
 */
final class WooPaymentsCutoverState {

	/** The job is waiting for its first action. */
	public const PENDING = 'pending';

	/** The job currently owns the reconciliation lease. */
	public const RUNNING = 'running';

	/** The job is waiting for a scheduled retry. */
	public const DEFERRED = 'deferred';

	/** The store completed its cutover. */
	public const DONE = 'done';

	/** The store is excluded from native cutover. */
	public const EXCLUDED = 'excluded';
}
