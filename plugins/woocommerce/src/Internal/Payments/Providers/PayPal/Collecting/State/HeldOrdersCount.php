<?php
/**
 * HeldOrdersCount interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

/**
 * Counts the orders whose payment is held for the collecting payee and not yet released to the merchant.
 *
 * The collecting state keeps its option while this count is above zero, so the held orders can still be settled.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
interface HeldOrdersCount {

	/**
	 * The number of held orders.
	 *
	 * @since 11.3.0
	 *
	 * @return int
	 */
	public function count(): int;
}
