<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrdersCount;

/**
 * A held-orders count that answers the numbers it was built with.
 */
final class FixedHeldOrders implements HeldOrdersCount {

	/**
	 * The number of held orders.
	 *
	 * @var int
	 */
	private int $held;

	/**
	 * The number of held orders that record another payee.
	 *
	 * @var int
	 */
	private int $for_other_payee;

	/**
	 * The payee the last count_for_other_payee() call was asked about, or null when it was not asked.
	 *
	 * @var string|null
	 */
	public ?string $asked_payee = null;

	/**
	 * Constructor.
	 *
	 * @param int $held            The number of held orders.
	 * @param int $for_other_payee The number of held orders that record a payee other than the one asked about.
	 */
	public function __construct( int $held, int $for_other_payee = 0 ) {
		$this->held            = $held;
		$this->for_other_payee = $for_other_payee;
	}

	/**
	 * The number of held orders.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->held;
	}

	/**
	 * The number of held orders that record a payee other than the one given.
	 *
	 * @param string $payee_email The payee.
	 *
	 * @return int
	 */
	public function count_for_other_payee( string $payee_email ): int {
		$this->asked_payee = $payee_email;

		return $this->for_other_payee;
	}
}
