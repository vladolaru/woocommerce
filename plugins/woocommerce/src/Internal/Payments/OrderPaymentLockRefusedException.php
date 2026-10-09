<?php
/**
 * OrderPaymentLockRefusedException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use RuntimeException;

/**
 * Thrown when a delivered provider event cannot run because another operation holds the order payment lock.
 * OrderPaymentLifecycleService::apply() reports a refused event in its result; a provider's event handling throws
 * this, so its delivery can tell a lock refusal from other failures and retry the event.
 *
 * It is thrown before the refused operation writes anything, so a later delivery cannot duplicate a write. Whether
 * the event is delivered again also depends on whether it is safe to apply late, after a newer event about the same
 * payment; the provider's retry policy decides that per event type. The holder's lock expires after its TTL.
 *
 * @since 11.2.0
 * @internal
 */
final class OrderPaymentLockRefusedException extends RuntimeException {

	/**
	 * ID of the order whose lock refused the operation.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Constructor.
	 *
	 * @param int    $order_id  ID of the order whose lock refused the operation.
	 * @param string $operation Refused operation, such as 'refund webhook'.
	 */
	public function __construct( int $order_id, string $operation ) {
		parent::__construct( sprintf( 'The order payment lock of order %1$d is held, so the %2$s was refused.', $order_id, $operation ) );
		$this->order_id = $order_id;
	}

	/**
	 * Get the ID of the order whose lock refused the operation.
	 *
	 * @return int
	 */
	public function get_order_id(): int {
		return $this->order_id;
	}
}
