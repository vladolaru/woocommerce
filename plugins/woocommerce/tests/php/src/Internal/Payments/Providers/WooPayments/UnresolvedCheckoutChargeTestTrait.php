<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use WC_Order;

/**
 * The charge metadata left by two unanswered checkout requests under the same key.
 */
trait UnresolvedCheckoutChargeTestTrait {

	/**
	 * Save the unresolved charge and return the values a later event must preserve.
	 *
	 * @param WC_Order $order Order with an unanswered checkout charge.
	 * @return array
	 */
	private function store_unresolved_checkout_charge( WC_Order $order ): array {
		$expected = array(
			WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META         => 'key_timed_out',
			WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_KEPT_AT_META => '1700000000',
			WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META               => array(
				'order_id'           => $order->get_id(),
				'customers'          => array( 'cus_first', 'cus_second' ),
				'failed_at'          => 1700000010,
				'cannot_check_noted' => false,
				'last_failed_at'     => 1700000020,
			),
		);
		foreach ( $expected as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $expected;
	}

	/**
	 * Check every stored value after reloading the order.
	 *
	 * @param array    $expected Values saved before the event.
	 * @param WC_Order $order    Reloaded order.
	 */
	private function assert_unresolved_checkout_charge( array $expected, WC_Order $order ): void {
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $order->get_meta( $key, true, 'edit' ), $key );
		}
	}
}
