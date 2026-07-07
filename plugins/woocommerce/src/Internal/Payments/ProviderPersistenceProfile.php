<?php
/**
 * ProviderPersistenceProfile interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Provider-specific persistence vocabulary used by the native payments runtime.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderPersistenceProfile {

	/**
	 * Get the provider gateway ID.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_gateway_id(): string;

	/**
	 * Get the provider gateway ID prefix.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_gateway_id_prefix(): string;

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
	 * Map a neutral outcome to provider order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array;

	/**
	 * Tell whether a provider-written duplicate order note should be skipped.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function should_skip_note( WC_Order $order, PaymentLifecycleEvent $event, string $note ): bool;
}
