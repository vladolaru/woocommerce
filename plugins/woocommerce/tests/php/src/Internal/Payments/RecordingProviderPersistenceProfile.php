<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use WC_Order;

/**
 * Test persistence profile for recording providers.
 */
class RecordingProviderPersistenceProfile implements ProviderPersistenceProfile {

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Constructor.
	 *
	 * @param string $provider_id Provider ID.
	 */
	public function __construct( string $provider_id ) {
		$this->provider_id = $provider_id;
	}

	/**
	 * Get the provider gateway ID.
	 *
	 * @return string
	 */
	public function get_gateway_id(): string {
		return $this->provider_id;
	}

	/**
	 * Get the provider gateway ID prefix.
	 *
	 * @return string
	 */
	public function get_gateway_id_prefix(): string {
		return $this->provider_id . '_';
	}

	/**
	 * Get the order payment lock key.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function get_order_lock_key( WC_Order $order ): string {
		return 'recording_provider_processing_' . $order->get_id();
	}

	/**
	 * Get the lock sentinel value.
	 *
	 * @return string
	 */
	public function get_lock_sentinel(): string {
		return '-1';
	}

	/**
	 * Get the lock time-to-live in seconds.
	 *
	 * @return int
	 */
	public function get_lock_ttl_seconds(): int {
		return 300;
	}

	/**
	 * Get the processed refund link meta key.
	 *
	 * @return string
	 */
	public function get_processed_refund_link_meta_key(): string {
		return '_recording_provider_refund_id';
	}

	/**
	 * Get preserved order/refund meta keys.
	 *
	 * @return string[]
	 */
	public function get_preserved_payment_meta_keys(): array {
		return array();
	}

	/**
	 * Map a neutral outcome to provider order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		return array();
	}

	/**
	 * Tell whether a provider-written duplicate order note should be skipped.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return bool
	 */
	public function should_skip_note( WC_Order $order, PaymentLifecycleEvent $event, string $note ): bool {
		return false;
	}
}
