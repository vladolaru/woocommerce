<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Order;

/**
 * Exercise a provider callback within the same live claim as its production caller.
 */
trait WithOrderPaymentClaim {
	/**
	 * Run the callback while this request owns the order.
	 *
	 * @param WC_Order $order     Held order.
	 * @param string   $operation Holder operation.
	 * @param callable $callback  Work under the claim.
	 */
	private function with_order_payment_claim( WC_Order $order, string $operation, callable $callback ): void {
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $lock->claim( $order, $vocabulary, null, $operation );
		$this->assertNotNull( $token );
		try {
			$callback();
		} finally {
			$lock->release( $order, $vocabulary, $token );
		}
	}
}
