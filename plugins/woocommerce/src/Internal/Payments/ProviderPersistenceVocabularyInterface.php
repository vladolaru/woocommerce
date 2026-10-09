<?php
/**
 * ProviderPersistenceVocabularyInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Names the order meta keys and lock settings the payments runtime reads and writes for a provider.
 * The order lock's transient key, sentinel and lifetime come from the provider, so the lock is the same row any other
 * code of that provider checks.
 *
 * Each provider keeps its payment state under its own names, so the runtime reads and writes it through this vocabulary
 * instead of fixed keys; turning an outcome into meta belongs to ProviderOutcomeMetadataMapperInterface.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderPersistenceVocabularyInterface {

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
	 * The provider stores a charge's idempotency key here before it sends the charge and deletes it once the outcome is
	 * definitive, so a key still here belongs to a charge whose outcome is unknown; the runtime only reads it, and refuses
	 * a charge when it changed since the order was loaded. Return '' when the provider stores no such key.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_charge_idempotency_key_meta_key(): string;

	/**
	 * Get the order meta key holding the provider's open dispute IDs.
	 *
	 * An array of open dispute IDs is the one shape the runtime reads, so a provider keeps its open disputes under this
	 * key in that shape, whatever else it stores; it is empty or absent when none is open. While it holds an ID, a
	 * completed payment event leaves the order status alone. Return '' when the provider records no disputes on the order.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_open_dispute_ids_meta_key(): string;
}
