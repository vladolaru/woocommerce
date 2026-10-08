<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use WC_Order;

/**
 * Sets, clears and reads an order payment lock directly in its transient rows, as another request holding the lock would.
 */
trait OrderPaymentLockTestTrait {

	/**
	 * Hold the order payment lock without a claim: the lock row holds the payment reference, or the provider's sentinel when there is none.
	 *
	 * @param WC_Order                      $order             Order to lock.
	 * @param ProviderPersistenceVocabulary $vocabulary        Provider persistence vocabulary.
	 * @param string|null                   $payment_reference Payment reference the lock holds.
	 */
	private function hold_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $vocabulary, ?string $payment_reference = null ): void {
		set_transient(
			$vocabulary->get_order_lock_key( $order ),
			empty( $payment_reference ) ? $vocabulary->get_lock_sentinel() : $payment_reference,
			$vocabulary->get_lock_ttl_seconds()
		);
	}

	/**
	 * Clear the order payment lock and its holder record, whoever holds it.
	 *
	 * @param WC_Order                      $order      Order to unlock.
	 * @param ProviderPersistenceVocabulary $vocabulary Provider persistence vocabulary.
	 */
	private function clear_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $vocabulary ): void {
		delete_transient( $vocabulary->get_order_lock_key( $order ) );
		delete_transient( $vocabulary->get_order_lock_key( $order ) . '_holder' );
	}

	/**
	 * Read the order payment lock value without deleting an expired lock.
	 *
	 * @param WC_Order                      $order      Order to read.
	 * @param ProviderPersistenceVocabulary $vocabulary Provider persistence vocabulary.
	 * @return mixed The lock value, or false when there is no live lock.
	 */
	private function get_order_payment_lock_value( WC_Order $order, ProviderPersistenceVocabulary $vocabulary ) {
		$key = $vocabulary->get_order_lock_key( $order );

		if ( wp_using_ext_object_cache() || wp_installing() ) {
			return get_transient( $key );
		}

		$stored = wc_get_container()->get( TransientRowLock::class )->read( $key );

		return null === $stored ? false : maybe_unserialize( $stored );
	}

	/**
	 * Tell whether the order payment lock blocks a payment reference: it holds the sentinel or that reference.
	 *
	 * @param WC_Order                      $order             Order to check.
	 * @param ProviderPersistenceVocabulary $vocabulary        Provider persistence vocabulary.
	 * @param string                        $payment_reference Payment reference to check.
	 * @return bool
	 */
	private function is_order_payment_lock_held_for( WC_Order $order, ProviderPersistenceVocabulary $vocabulary, string $payment_reference ): bool {
		$value = $this->get_order_payment_lock_value( $order, $vocabulary );

		return $vocabulary->get_lock_sentinel() === $value || $payment_reference === $value;
	}
}
