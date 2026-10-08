<?php
/**
 * OrderPaymentStore class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Throwable;
use WC_Order;

/**
 * Holds the order payment lock, and logs lock refusals and payment method mismatches.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class OrderPaymentStore {

	/**
	 * Fixed prefix of the warning logged when the lock refuses an operation, so it can be found in logs.
	 *
	 * @var string
	 */
	private const LOCK_REFUSAL_LOG_PREFIX = 'order payment lock refused';

	/**
	 * Fixed prefix of the warning logged when an event applies to an order of another gateway.
	 *
	 * @var string
	 */
	private const PAYMENT_METHOD_MISMATCH_LOG_PREFIX = 'order payment method mismatch';

	/**
	 * Log source for lock refusals and payment method mismatches, matching the WooPayments plugin's log file.
	 *
	 * @var string
	 */
	private const LOCK_REFUSAL_LOG_SOURCE = 'woopayments';

	/**
	 * Lock held in the database rows of a transient.
	 *
	 * @var TransientRowLock
	 */
	private TransientRowLock $row_lock;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param TransientRowLock $row_lock Lock held in the database rows of a transient.
	 */
	final public function init( TransientRowLock $row_lock ): void {
		$this->row_lock = $row_lock;
	}

	/**
	 * Claim the order payment lock and record which operation holds it.
	 *
	 * Unlike WooPayments-compatible reference checks, native processing uses this as an order-wide
	 * claim: any active lock value blocks checkout, refund, capture, and cancel from starting.
	 * WooPayments 11.1.0 locks only intent-driven status updates; this stricter lock is owner-ratified
	 * money-path hardening (inbox N-270), and log_order_payment_lock_refusal() records each refusal
	 * the plugin would have allowed. Release the lock with release_order_payment_lock() and the returned token.
	 *
	 * The lock keeps the WooPayments-compatible value, the payment reference, so a plugin request still sees an
	 * intent it is processing as locked. A holder record names the operation, for the refusal log, and carries a
	 * token unique to this claim. Releasing needs that token, so an operation that ran past the lock TTL cannot
	 * release a lock a later claim with the same reference took over.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                      $order               Order being locked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference being processed.
	 * @param string                        $operation           Operation claiming the lock, such as 'refund' or 'capture'.
	 * @return string|null The claim token to release the lock with, or null when the lock is held.
	 */
	public function claim_order_payment_lock_for_operation( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference, string $operation ): ?string {
		$lock_key   = $persistence_profile->get_order_lock_key( $order );
		$holder_key = $this->get_lock_holder_key( $order, $persistence_profile );
		$value      = $this->get_lock_value( $persistence_profile, $payment_reference );
		$ttl        = $persistence_profile->get_lock_ttl_seconds();
		$token      = wp_generate_uuid4();
		$holder     = array(
			'operation'  => $operation,
			'lock_value' => $value,
			'token'      => $token,
			'claimed_at' => time(),
		);

		if ( $this->lock_lives_in_object_cache() ) {
			if ( ! wp_cache_add( $lock_key, $value, 'transient', $ttl ) ) {
				return null;
			}

			wp_cache_set( $holder_key, $holder, 'transient', $ttl );

			return $token;
		}

		return $this->row_lock->claim( $lock_key, maybe_serialize( $value ), $ttl, $holder_key, maybe_serialize( $holder ) ) ? $token : null;
	}

	/**
	 * Log a warning when the order payment lock refuses an operation WooPayments would have allowed.
	 *
	 * Writes one warning line with a fixed prefix, naming the order, the refused operation, the
	 * operation holding the lock and the lock's age. Logging is best-effort and never throws.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                      $order               Order whose lock refused the operation.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string                        $refused_operation   Operation the lock refused, such as 'refund'.
	 * @param string|null                   $source              Log source, when the caller logs to its own file.
	 * @param array<string,mixed>           $extra_context       Caller-specific context added to the line.
	 */
	public function log_order_payment_lock_refusal( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, string $refused_operation, ?string $source = null, array $extra_context = array() ): void {
		try {
			$lock_value       = $this->read_lock_transient( $persistence_profile->get_order_lock_key( $order ) );
			$holder           = $this->read_lock_transient( $this->get_lock_holder_key( $order, $persistence_profile ) );
			$holder_operation = null;
			$lock_age_seconds = null;

			// A holder record only describes the live lock when both carry the same lock value.
			if ( is_array( $holder ) && false !== $lock_value && isset( $holder['operation'], $holder['lock_value'], $holder['claimed_at'] ) && (string) $lock_value === $holder['lock_value'] ) {
				$holder_operation = (string) $holder['operation'];
				$lock_age_seconds = max( 0, time() - (int) $holder['claimed_at'] );
			}

			wc_get_logger()->warning(
				sprintf(
					'%1$s: order %2$d, refused %3$s, held by %4$s for %5$s',
					self::LOCK_REFUSAL_LOG_PREFIX,
					$order->get_id(),
					$refused_operation,
					$holder_operation ?? 'an unknown operation',
					null === $lock_age_seconds ? 'an unknown time' : $lock_age_seconds . 's'
				),
				array_merge(
					$extra_context,
					array(
						'source'            => $source ?? self::LOCK_REFUSAL_LOG_SOURCE,
						'order_id'          => $order->get_id(),
						'refused_operation' => $refused_operation,
						'holder_operation'  => $holder_operation,
						'lock_age_seconds'  => $lock_age_seconds,
						'lock_value'        => false === $lock_value ? null : (string) $lock_value,
					)
				)
			);
		} catch ( Throwable $exception ) {
			return;
		}
	}

	/**
	 * Log a warning when a provider event applies to an order whose payment method is another gateway.
	 *
	 * Same line shape as the lock refusal: a fixed prefix, the order, the applied operation and the order's payment
	 * method. Logging is best-effort and never throws.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order    $order             Order the event applies to.
	 * @param string      $applied_operation Operation being applied, such as the event type.
	 * @param string|null $source            Log source, when the caller logs to its own file.
	 */
	public function log_order_payment_method_mismatch( WC_Order $order, string $applied_operation, ?string $source = null ): void {
		try {
			$payment_method = (string) $order->get_payment_method();
			wc_get_logger()->warning(
				sprintf(
					'%1$s: order %2$d, applied %3$s, order payment method %4$s',
					self::PAYMENT_METHOD_MISMATCH_LOG_PREFIX,
					$order->get_id(),
					$applied_operation,
					'' === $payment_method ? 'none' : $payment_method
				),
				array(
					'source'            => $source ?? self::LOCK_REFUSAL_LOG_SOURCE,
					'order_id'          => $order->get_id(),
					'applied_operation' => $applied_operation,
					'payment_method'    => $payment_method,
				)
			);
		} catch ( Throwable $exception ) {
			return;
		}
	}

	/**
	 * Release the order payment lock, but only while the caller's claim still holds it.
	 *
	 * The counterpart of claim_order_payment_lock_for_operation(): an operation that ran past the lock TTL must not
	 * release a lock another claim has since taken over, even one with the same payment reference, so the holder
	 * record must still carry this claim's token.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                      $order               Order being unlocked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string                        $lock_token          Token the claim returned.
	 */
	public function release_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, string $lock_token ): void {
		global $wpdb;

		$lock_key   = $persistence_profile->get_order_lock_key( $order );
		$holder_key = $this->get_lock_holder_key( $order, $persistence_profile );

		if ( $this->lock_lives_in_object_cache() ) {
			// The object cache API has no compare-and-delete, so these checks and deletes are not atomic.
			$holder = wp_cache_get( $holder_key, 'transient' );
			if ( ! $this->is_holder_of_claim( $holder, $lock_token ) || wp_cache_get( $lock_key, 'transient' ) !== $holder['lock_value'] ) {
				return;
			}

			wp_cache_delete( $lock_key, 'transient' );
			wp_cache_delete( $holder_key, 'transient' );

			return;
		}

		$stored_holder = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $holder_key ) );
		$holder        = null === $stored_holder ? null : maybe_unserialize( $stored_holder );

		if ( ! $this->is_holder_of_claim( $holder, $lock_token ) ) {
			return;
		}

		$this->row_lock->release( $lock_key, maybe_serialize( $holder['lock_value'] ), $holder_key, $stored_holder );
	}

	/**
	 * Tell whether a holder record belongs to the claim that returned a token.
	 *
	 * @param mixed  $holder     Holder record as stored.
	 * @param string $lock_token Token the claim returned.
	 * @return bool
	 * @phpstan-assert-if-true array{lock_value:string,token:string} $holder
	 */
	private function is_holder_of_claim( $holder, string $lock_token ): bool {
		return is_array( $holder )
			&& isset( $holder['token'], $holder['lock_value'] )
			&& is_string( $holder['lock_value'] )
			&& '' !== $lock_token
			&& hash_equals( (string) $holder['token'], $lock_token );
	}

	/**
	 * Tell whether transients, and so the order payment lock, are stored in the object cache.
	 *
	 * Mirrors the storage choice set_transient() makes.
	 *
	 * @return bool
	 */
	private function lock_lives_in_object_cache(): bool {
		return wp_using_ext_object_cache() || wp_installing();
	}

	/**
	 * Read a lock transient without the expired-row deletion get_transient() does.
	 *
	 * WordPress get_transient() deletes an expired transient by name, so a reader could delete rows a
	 * takeover just wrote. Expired rows are left for the next claim to take over.
	 *
	 * @param string $key Transient key.
	 * @return mixed Stored value, or false when missing or expired.
	 */
	private function read_lock_transient( string $key ) {
		if ( $this->lock_lives_in_object_cache() ) {
			return get_transient( $key );
		}

		$stored = $this->row_lock->read( $key );

		return null === $stored ? false : maybe_unserialize( $stored );
	}

	/**
	 * Get the value stored in the order payment lock for a payment reference.
	 *
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference being processed.
	 * @return string
	 */
	private function get_lock_value( ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference ): string {
		return empty( $payment_reference ) ? $persistence_profile->get_lock_sentinel() : $payment_reference;
	}

	/**
	 * Get the transient key of the record naming the operation that holds the order payment lock.
	 *
	 * @param WC_Order                      $order               Order object.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return string
	 */
	private function get_lock_holder_key( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile ): string {
		return $persistence_profile->get_order_lock_key( $order ) . '_holder';
	}
}
