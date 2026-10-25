<?php
/**
 * MultiCurrencyStateInvalidator class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Counts invalidations of the request's Multi-Currency state; a state builder sharing it rebuilds once the count changes.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyStateInvalidator {

	/**
	 * Current invalidation generation.
	 *
	 * @var int
	 */
	private int $generation = 0;

	/**
	 * Get the current invalidation generation.
	 *
	 * @return int
	 */
	public function get_generation(): int {
		return $this->generation;
	}

	/**
	 * Invalidate every state builder sharing this coordinator.
	 */
	public function invalidate(): void {
		++$this->generation;
	}
}
