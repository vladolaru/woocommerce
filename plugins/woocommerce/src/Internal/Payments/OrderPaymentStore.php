<?php
/**
 * OrderPaymentStore class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use WC_Order;
use WC_Order_Refund;
use WC_Abstract_Order;

/**
 * HPOS-safe order payment projection and WooPayments-compatible payment locks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class OrderPaymentStore {

	/**
	 * Preserved WooPayments gateway ID.
	 *
	 * @deprecated 11.0.0 Use the provider persistence profile.
	 *
	 * @var string
	 */
	const GATEWAY_ID = WooPaymentsPersistenceProfile::GATEWAY_ID;

	/**
	 * Preserved WooPayments split-UPE gateway ID prefix.
	 *
	 * @deprecated 11.0.0 Use the provider persistence profile.
	 *
	 * @var string
	 */
	const GATEWAY_ID_PREFIX = WooPaymentsPersistenceProfile::GATEWAY_ID_PREFIX;

	/**
	 * WooPayments-compatible order processing lock transient prefix.
	 *
	 * @deprecated 11.0.0 Use the provider persistence profile.
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT_PREFIX = WooPaymentsPersistenceProfile::LOCK_TRANSIENT_PREFIX;

	/**
	 * WooPayments-compatible sentinel used when the order is locked without a payment reference.
	 *
	 * @deprecated 11.0.0 Use the provider persistence profile.
	 *
	 * @var string
	 */
	const LOCK_SENTINEL = WooPaymentsPersistenceProfile::LOCK_SENTINEL;

	/**
	 * WooPayments lock time-to-live, in seconds.
	 *
	 * @deprecated 11.0.0 Use the provider persistence profile.
	 *
	 * @var int
	 */
	const LOCK_TTL_SECONDS = WooPaymentsPersistenceProfile::LOCK_TTL_SECONDS;

	/**
	 * Get the preserved provider order/refund payment meta keys.
	 *
	 * @since 11.0.0
	 *
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @return string[]
	 */
	public static function get_payment_meta_keys( ProviderPersistenceProfile $persistence_profile ): array {
		return $persistence_profile->get_preserved_payment_meta_keys();
	}

	/**
	 * Read a stable, HPOS-safe projection of an order's payment surface.
	 *
	 * The returned structure is intentionally limited to persisted payment state. A1 shadow mode
	 * compares this read projection without writing back to the order.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                   $order               Order to project.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @return array<string,mixed>
	 */
	public function read_payment_surface( WC_Order $order, ProviderPersistenceProfile $persistence_profile ): array {
		return array(
			'order_id'       => (int) $order->get_id(),
			'status'         => (string) $order->get_status(),
			'payment_method' => (string) $order->get_payment_method(),
			'transaction_id' => (string) $order->get_transaction_id(),
			'currency'       => (string) $order->get_currency(),
			'total'          => (string) $order->get_total(),
			'meta'           => $this->read_payment_meta( $order, $persistence_profile ),
			'refunds'        => $this->read_refund_surfaces( $order, $persistence_profile ),
		);
	}

	/**
	 * Tell whether an order is locked for payment processing.
	 *
	 * This preserves the WooPayments lock semantics exactly: a sentinel lock blocks all references,
	 * while a reference-specific lock blocks only that same reference.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                   $order               Order being checked.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @param string|null                $payment_reference   Payment reference currently being processed.
	 * @return bool True when processing is locked.
	 */
	public function is_order_payment_locked( WC_Order $order, ProviderPersistenceProfile $persistence_profile, ?string $payment_reference = null ): bool {
		$processing = get_transient( $persistence_profile->get_order_lock_key( $order ) );

		return $persistence_profile->get_lock_sentinel() === $processing
			|| ( null !== $payment_reference && $processing === $payment_reference );
	}

	/**
	 * Atomically claim the order payment lock for a money-moving operation.
	 *
	 * Unlike WooPayments-compatible reference checks, native processing uses this as an order-wide
	 * claim: any active lock value blocks checkout, refund, capture, and cancel from starting.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                   $order               Order being locked.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @param string|null                $payment_reference   Payment reference being processed.
	 * @return bool True when the lock was claimed.
	 */
	public function claim_order_payment_lock( WC_Order $order, ProviderPersistenceProfile $persistence_profile, ?string $payment_reference = null ): bool {
		$lock_key = $persistence_profile->get_order_lock_key( $order );
		$value    = empty( $payment_reference ) ? $persistence_profile->get_lock_sentinel() : $payment_reference;

		if ( false !== get_transient( $lock_key ) ) {
			return false;
		}

		if (
			( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() )
			|| ( function_exists( 'wp_installing' ) && wp_installing() )
		) {
			return wp_cache_add( $lock_key, $value, 'transient', $persistence_profile->get_lock_ttl_seconds() );
		}

		$timeout_option = '_transient_timeout_' . $lock_key;
		$value_option   = '_transient_' . $lock_key;
		$expiration     = time() + $persistence_profile->get_lock_ttl_seconds();

		$timeout_added = add_option( $timeout_option, $expiration, '', false );
		$value_added   = add_option( $value_option, $value, '', false );

		if ( ! $value_added ) {
			if ( $timeout_added ) {
				delete_option( $timeout_option );
			}

			return false;
		}

		if ( ! $timeout_added ) {
			update_option( $timeout_option, $expiration, false );
		}

		return true;
	}

	/**
	 * Lock an order for payment processing.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                   $order               Order being locked.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @param string|null                $payment_reference   Payment reference being processed.
	 */
	public function lock_order_payment( WC_Order $order, ProviderPersistenceProfile $persistence_profile, ?string $payment_reference = null ): void {
		set_transient(
			$persistence_profile->get_order_lock_key( $order ),
			empty( $payment_reference ) ? $persistence_profile->get_lock_sentinel() : $payment_reference,
			$persistence_profile->get_lock_ttl_seconds()
		);
	}

	/**
	 * Unlock an order for payment processing.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                   $order               Order being unlocked.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 */
	public function unlock_order_payment( WC_Order $order, ProviderPersistenceProfile $persistence_profile ): void {
		delete_transient( $persistence_profile->get_order_lock_key( $order ) );
	}

	/**
	 * Read preserved payment meta from an order or refund object.
	 *
	 * @param WC_Abstract_Order          $order               Order or refund object.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @return array<string,string>
	 */
	private function read_payment_meta( WC_Abstract_Order $order, ProviderPersistenceProfile $persistence_profile ): array {
		$payment_meta = array();
		$allowed_keys = array_fill_keys( $persistence_profile->get_preserved_payment_meta_keys(), true );

		foreach ( $order->get_meta_data() as $meta ) {
			$meta_data = $meta->get_data();
			$key       = (string) ( $meta_data['key'] ?? '' );

			if ( ! isset( $allowed_keys[ $key ] ) ) {
				continue;
			}

			$payment_meta[ $key ] = $this->normalize_meta_value( $meta_data['value'] ?? null );
		}

		ksort( $payment_meta );

		return $payment_meta;
	}

	/**
	 * Read stable refund projections for an order.
	 *
	 * @param WC_Order                   $order               Order object.
	 * @param ProviderPersistenceProfile $persistence_profile Provider persistence profile.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_refund_surfaces( WC_Order $order, ProviderPersistenceProfile $persistence_profile ): array {
		$refunds = array();

		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			$refunds[] = array(
				'refund_id' => (int) $refund->get_id(),
				'amount'    => (string) $refund->get_amount(),
				'currency'  => (string) $refund->get_currency(),
				'reason'    => (string) $refund->get_reason(),
				'meta'      => $this->read_payment_meta( $refund, $persistence_profile ),
			);
		}

		usort(
			$refunds,
			static function ( array $left, array $right ): int {
				return $left['refund_id'] <=> $right['refund_id'];
			}
		);

		return $refunds;
	}

	/**
	 * Normalize meta values for stable machine-readable comparisons.
	 *
	 * @param mixed $value Meta value.
	 * @return string Normalized scalar value.
	 */
	private function normalize_meta_value( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );
		return false === $encoded ? '' : $encoded;
	}
}
