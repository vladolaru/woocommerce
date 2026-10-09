<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrdersCount;

/**
 * A held-orders count that answers the number it was built with.
 */
final class FixedHeldOrders implements HeldOrdersCount {

	/**
	 * The number of held orders.
	 *
	 * @var int
	 */
	private int $held;

	/**
	 * Constructor.
	 *
	 * @param int $held The number of held orders.
	 */
	public function __construct( int $held ) {
		$this->held = $held;
	}

	/**
	 * The number of held orders.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->held;
	}
}
