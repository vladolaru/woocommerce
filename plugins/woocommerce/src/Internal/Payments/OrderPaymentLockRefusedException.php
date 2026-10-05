<?php
/**
 * OrderPaymentLockRefusedException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use RuntimeException;

/**
 * Thrown when a delivered provider event cannot run because another operation holds the order payment lock.
 *
 * It is thrown before the refused operation writes anything, so the event can be delivered again safely, whatever
 * its type. That is why a retry policy that runs some event types only once, because a re-run after a write could
 * duplicate a refund, still retries this refusal. The holder's lock expires after its TTL, so a later attempt
 * applies the event.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
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
