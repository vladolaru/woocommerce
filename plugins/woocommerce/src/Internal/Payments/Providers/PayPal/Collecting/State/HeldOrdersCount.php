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

	/**
	 * The number of held orders that record a payee other than the given one, compared in lowercase.
	 *
	 * A held order that records no payee, because it was held before the payee was recorded, is not counted: it is taken
	 * to belong to whichever payee the store collects for. An implementation that queries the orders must keep that: join
	 * the payee meta rather than test for its absence.
	 *
	 * @since 11.3.0
	 *
	 * @param string $payee_email The payee.
	 *
	 * @return int
	 */
	public function count_for_other_payee( string $payee_email ): int;
}
