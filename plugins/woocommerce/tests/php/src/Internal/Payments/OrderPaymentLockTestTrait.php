<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use WC_Order;

/**
 * Sets, clears and reads an order payment lock directly in its transient rows, as another request holding the lock would.
 *
 * Lock rows as client 11.1.0 `includes/class-wc-payments-utils.php:996-1026` writes them (transient name, sentinel, reference check, five-minute lifetime, unlock); the `_holder` record is the built-in runtime's own addition.
 */
trait OrderPaymentLockTestTrait {

	/**
	 * Hold the order payment lock without a claim, as another runtime holding it in the rows would: the lock row holds the
	 * payment reference, or the provider's sentinel when there is none.
	 *
	 * @param WC_Order                               $order             Order to lock.
	 * @param ProviderPersistenceVocabularyInterface $vocabulary        Provider persistence vocabulary.
	 * @param string|null                            $payment_reference Payment reference the lock holds.
	 */
	private function hold_order_payment_lock( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, ?string $payment_reference = null ): void {
		global $wpdb;

		$key  = $vocabulary->get_order_lock_key( $order );
		$rows = array(
			'_transient_timeout_' . $key => (string) ( time() + $vocabulary->get_lock_ttl_seconds() ),
			'_transient_' . $key         => empty( $payment_reference ) ? $vocabulary->get_lock_sentinel() : $payment_reference,
		);
		foreach ( $rows as $name => $value ) {
			$wpdb->replace(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $value,
					'autoload'     => 'off',
				)
			);
		}
		$this->forget_order_payment_lock_options( array_keys( $rows ) );
	}

	/**
	 * Clear the order payment lock and its holder record, whoever holds it.
	 *
	 * @param WC_Order                               $order      Order to unlock.
	 * @param ProviderPersistenceVocabularyInterface $vocabulary Provider persistence vocabulary.
	 */
	private function clear_order_payment_lock( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary ): void {
		global $wpdb;

		$key   = $vocabulary->get_order_lock_key( $order );
		$names = array( '_transient_' . $key, '_transient_timeout_' . $key, '_transient_' . $key . '_holder', '_transient_timeout_' . $key . '_holder' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s)", $names[0], $names[1], $names[2], $names[3] ) );
		$this->forget_order_payment_lock_options( $names );
	}

	/**
	 * Drop lock rows written or deleted with SQL from WordPress's option caches, so a later get_option() sees the database.
	 *
	 * @param string[] $names Option names.
	 */
	private function forget_order_payment_lock_options( array $names ): void {
		wp_cache_delete_multiple( $names, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Read the order payment lock value without deleting an expired lock.
	 *
	 * @param WC_Order                               $order      Order to read.
	 * @param ProviderPersistenceVocabularyInterface $vocabulary Provider persistence vocabulary.
	 * @return mixed The lock value, or false when there is no live lock.
	 */
	private function get_order_payment_lock_value( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary ) {
		$key    = $vocabulary->get_order_lock_key( $order );
		$stored = wc_get_container()->get( TransientRowLock::class )->read( $key );

		return null === $stored ? false : maybe_unserialize( $stored );
	}

	/**
	 * Tell whether the order payment lock blocks a payment reference: it holds the sentinel or that reference.
	 *
	 * Same check as client 11.1.0 `includes/class-wc-payments-utils.php:996-1002`.
	 *
	 * @param WC_Order                               $order             Order to check.
	 * @param ProviderPersistenceVocabularyInterface $vocabulary        Provider persistence vocabulary.
	 * @param string                                 $payment_reference Payment reference to check.
	 * @return bool
	 */
	private function is_order_payment_lock_held_for( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, string $payment_reference ): bool {
		$value = $this->get_order_payment_lock_value( $order, $vocabulary );

		return $vocabulary->get_lock_sentinel() === $value || $payment_reference === $value;
	}
}
