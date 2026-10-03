<?php
/**
 * MultiCurrencyStateInvalidator class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Coordinates request-local invalidation across native multi-currency state builders.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
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
