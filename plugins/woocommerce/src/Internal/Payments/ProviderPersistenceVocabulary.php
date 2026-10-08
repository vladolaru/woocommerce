<?php
/**
 * ProviderPersistenceVocabulary interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Provider-specific persistence keys and lock vocabulary.
 *
 * This contract contains only identifiers needed by the generic runtime to
 * read and coordinate provider-owned persisted state. Outcome interpretation
 * belongs to ProviderOutcomeMetadataMapper instead.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderPersistenceVocabulary {

	/**
	 * Get the order payment lock key.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_order_lock_key( WC_Order $order ): string;

	/**
	 * Get the lock sentinel value.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_lock_sentinel(): string;

	/**
	 * Get the lock time-to-live in seconds.
	 *
	 * @return int
	 *
	 * @since 11.0.0
	 */
	public function get_lock_ttl_seconds(): int;

	/**
	 * Get the processed refund link meta key.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_processed_refund_link_meta_key(): string;

	/**
	 * Get preserved order/refund meta keys.
	 *
	 * @return string[]
	 *
	 * @since 11.0.0
	 */
	public function get_preserved_payment_meta_keys(): array;

	/**
	 * Get the order meta key holding the provider payment the order is bound to.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_payment_reference_meta_key(): string;

	/**
	 * Get the order meta key holding the idempotency key a charge keeps on the order while its outcome is unknown.
	 *
	 * A new value under this key since the order was loaded means another request attempted a charge. Return '' when the
	 * provider keeps no such key.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_charge_idempotency_key_meta_key(): string;

	/**
	 * Get the order meta key holding the provider's open dispute IDs.
	 *
	 * The meta value is an array of the IDs of the disputes still open on the order's payment, empty or absent when
	 * none is open. While it holds an ID, a completed payment event leaves the order status alone. Return '' when the
	 * provider records no disputes on the order.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_open_dispute_ids_meta_key(): string;
}
