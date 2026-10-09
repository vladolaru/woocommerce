<?php
/**
 * NoHeldOrders class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

/**
 * The default held-orders count: there are none.
 *
 * Stands in until the order-meta query replaces it.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class NoHeldOrders implements HeldOrdersCount {

	/**
	 * The number of held orders.
	 *
	 * @since 11.3.0
	 *
	 * @return int
	 */
	public function count(): int {
		return 0;
	}
}
