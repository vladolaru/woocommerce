<?php
/**
 * OrderPaymentLock class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Holds the order payment lock, and logs lock refusals.
 *
 * @since 11.0.0
 * @internal
 */
class OrderPaymentLock {

	/**
	 * Fixed prefix of the warning logged when the lock refuses an operation, so it can be found in logs.
	 *
	 * @var string
	 */
	private const LOCK_REFUSAL_LOG_PREFIX = 'order payment lock refused';

	/**
	 * Log source for lock refusals when the caller names none.
	 *
	 * @var string
	 */
	private const LOCK_REFUSAL_LOG_SOURCE = 'order-payments';

	/**
	 * Fixed prefix of the warning logged when a claim takes over an expired lock, so it can be found in logs.
	 *
	 * @var string
	 */
	private const LOCK_TAKEOVER_LOG_PREFIX = 'order payment lock taken over';

	/**
	 * Lock held in the database rows of a transient.
	 *
	 * @var TransientRowLock
	 */
	private TransientRowLock $row_lock;

	/**
	 * Claims this request took and has not released, by token, with whether a money request's result is still unsaved.
	 *
	 * @var array<string,array{order:WC_Order,vocabulary:ProviderPersistenceVocabularyInterface,money_request_unsaved:bool}>
	 */
	private array $held_claims = array();

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
	 * Any held lock blocks checkout, refund, capture and cancel from starting; callers log every refusal through
	 * log_refusal(). Release the lock with release() and the returned token.
	 * Client 11.1.0 locks only intent-driven status updates (class-wc-payments-order-service.php:2756-2761).
	 *
	 * The lock value names what the claim processes, or the provider's sentinel when it names nothing; the runtime only
	 * logs it, and other code of the provider may compare it. A holder record names the operation, for the refusal log,
	 * and carries a token unique to this claim. Releasing needs that token, so an operation that ran past the lock TTL
	 * cannot release a lock a later claim took over, even one with the same lock value. A claim that takes over an expired
	 * lock logs one warning naming the former holder, whose request never released it; one this request never releases is
	 * released when it ends (release_held_claims()).
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                               $order               Order being locked.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param string|null                            $lock_value          What this claim processes, stored as the lock's value: a payment
	 *                                                                    reference, an operation key or a provider event key; the provider's
	 *                                                                    sentinel when null.
	 * @param string                                 $operation           Operation claiming the lock, such as 'refund' or 'capture'.
	 * @return string|null The claim token to release the lock with, or null when the lock is held.
	 */
	public function claim( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary, ?string $lock_value, string $operation ): ?string {
		$lock_key   = $persistence_vocabulary->get_order_lock_key( $order );
		$holder_key = $this->get_lock_holder_key( $order, $persistence_vocabulary );
		$value      = $this->get_lock_value( $persistence_vocabulary, $lock_value );
		$ttl        = $persistence_vocabulary->get_lock_ttl_seconds();
		$token      = wp_generate_uuid4();
		$holder     = array(
			'operation'  => $operation,
			'lock_value' => $value,
			'token'      => $token,
			'claimed_at' => time(),
		);

		if ( $this->lock_lives_in_object_cache() ) {
			$former_holder = wp_cache_get( $holder_key, 'transient' );
			if ( ! wp_cache_add( $lock_key, $value, 'transient', $ttl ) ) {
				return null;
			}

			// The cache drops an expired lock but keeps its holder record for a day, so the next claim can name the former
			// holder; a release deletes both, so a record left here belongs to a holder that never released.
			wp_cache_set( $holder_key, $holder, 'transient', DAY_IN_SECONDS );
			if ( false !== $former_holder ) {
				$this->log_takeover( $order, $operation, null, $former_holder );
			}
			$this->hold_until_shutdown( $token, $order, $persistence_vocabulary );

			return $token;
		}

		$taken_over = null;
		$claimed    = $this->row_lock->claim( $lock_key, maybe_serialize( $value ), $ttl, $holder_key, maybe_serialize( $holder ), $taken_over );
		if ( null !== $taken_over ) {
			$this->log_takeover( $order, $operation, $taken_over['value'], null === $taken_over['holder'] ? null : maybe_unserialize( $taken_over['holder'] ) );
		}

		if ( ! $claimed ) {
			return null;
		}
		$this->hold_until_shutdown( $token, $order, $persistence_vocabulary );

		return $token;
	}

	/**
	 * Mark whether a held claim has sent a money-moving request whose result is not saved yet, so release_held_claims()
	 * keeps its lock. A token this request does not hold is ignored.
	 *
	 * @since 11.4.0
	 *
	 * @param string $lock_token Token the claim returned.
	 * @param bool   $unsaved    True once the request is sent, false once its result is saved.
	 */
	public function mark_money_request_unsaved( string $lock_token, bool $unsaved ): void {
		if ( isset( $this->held_claims[ $lock_token ] ) ) {
			$this->held_claims[ $lock_token ]['money_request_unsaved'] = $unsaved;
		}
	}

	/**
	 * Release, when the request ends, every claim it took and did not release: an exit, a fatal error or a timeout skips
	 * the caller's `finally`. A claim that sent a money-moving request and has not saved its result keeps its lock to its
	 * expiry, since nothing else reliably stops a repeat from moving the money twice.
	 *
	 * @internal
	 */
	public function release_held_claims(): void {
		foreach ( $this->held_claims as $lock_token => $claim ) {
			if ( ! $claim['money_request_unsaved'] ) {
				$this->release( $claim['order'], $claim['vocabulary'], (string) $lock_token );
			}
		}
	}

	/**
	 * Keep a claim for release_held_claims(), hooked at the last `shutdown` priority: before WordPress closes a persistent
	 * object cache, and after any earlier `shutdown` callback that claims.
	 *
	 * @param string                                 $lock_token             Token the claim returned.
	 * @param WC_Order                               $order                  Order the claim locks.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 */
	private function hold_until_shutdown( string $lock_token, WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		$this->held_claims[ $lock_token ] = array(
			'order'                 => $order,
			'vocabulary'            => $persistence_vocabulary,
			'money_request_unsaved' => false,
		);
		add_action( 'shutdown', array( $this, 'release_held_claims' ), PHP_INT_MAX );
	}

	/**
	 * Log a warning when a claim takes over an expired lock, naming the former holder's operation and its age.
	 *
	 * @param WC_Order    $order         Order whose lock was taken over.
	 * @param string      $operation     Operation of the claim that took the lock over.
	 * @param string|null $former_value  Former lock value as stored, or null on the object cache, where it is gone.
	 * @param mixed       $former_holder Former holder record.
	 */
	private function log_takeover( WC_Order $order, string $operation, ?string $former_value, $former_holder ): void {
		// On the database the record describes the former lock only when both carry the same lock value, as in log_refusal().
		$describes        = is_array( $former_holder ) && isset( $former_holder['operation'], $former_holder['lock_value'], $former_holder['claimed_at'] ) && ( null === $former_value || (string) maybe_unserialize( $former_value ) === $former_holder['lock_value'] );
		$former_operation = $describes ? (string) $former_holder['operation'] : null;
		$former_age       = $describes ? max( 0, time() - (int) $former_holder['claimed_at'] ) : null;

		wc_get_logger()->warning(
			sprintf(
				'%1$s: order %2$d, by %3$s, from %4$s claimed %5$s ago',
				self::LOCK_TAKEOVER_LOG_PREFIX,
				$order->get_id(),
				$operation,
				$former_operation ?? 'an unknown operation',
				null === $former_age ? 'an unknown time' : $former_age . 's'
			),
			array(
				'source'             => self::LOCK_REFUSAL_LOG_SOURCE,
				'order_id'           => $order->get_id(),
				'operation'          => $operation,
				'former_operation'   => $former_operation,
				'former_age_seconds' => $former_age,
				'former_lock_value'  => $former_value ?? ( $describes ? (string) $former_holder['lock_value'] : null ),
			)
		);
	}

	/**
	 * Log a warning when the order payment lock refuses an operation.
	 *
	 * Writes one line with a fixed prefix, naming the order, the refused operation, the operation holding the lock and
	 * the lock's age. It is a warning, unless the refused work will be retried and its holder is under a minute old, a
	 * live one: that routine refusal is a notice, so a warning means that nothing retries the work or the holder may be dead.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                               $order               Order whose lock refused the operation.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param string                                 $refused_operation   Operation the lock refused, such as 'refund'.
	 * @param string|null                            $source              Log source, when the caller logs to its own file.
	 * @param array<string,mixed>                    $extra_context       Caller-specific context added to the line.
	 * @param bool                                   $retry_queued        Whether the refused work is retried later.
	 * @return int|null When the live holder claimed the lock, or null when its holder record does not describe the lock.
	 */
	public function log_refusal( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary, string $refused_operation, ?string $source = null, array $extra_context = array(), bool $retry_queued = false ): ?int {
		$lock_value       = $this->read_lock_transient( $persistence_vocabulary->get_order_lock_key( $order ) );
		$holder           = $this->read_lock_transient( $this->get_lock_holder_key( $order, $persistence_vocabulary ) );
		$holder_operation = null;
		$lock_age_seconds = null;
		$claimed_at       = null;

		// A holder record only describes the live lock when both carry the same lock value.
		if ( is_array( $holder ) && false !== $lock_value && isset( $holder['operation'], $holder['lock_value'], $holder['claimed_at'] ) && (string) $lock_value === $holder['lock_value'] ) {
			$holder_operation = (string) $holder['operation'];
			$claimed_at       = (int) $holder['claimed_at'];
			$lock_age_seconds = max( 0, time() - $claimed_at );
		}

		$level = $retry_queued && null !== $lock_age_seconds && $lock_age_seconds < MINUTE_IN_SECONDS ? 'notice' : 'warning';
		wc_get_logger()->$level(
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
					'retry_queued'      => $retry_queued,
				)
			)
		);

		return $claimed_at;
	}

	/**
	 * Release the order payment lock, but only while the caller's claim still holds it.
	 *
	 * The counterpart of claim(): an operation that ran past the lock TTL must not
	 * release a lock another claim has since taken over, even one with the same lock value, so the holder
	 * record must still carry this claim's token.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                               $order               Order being unlocked.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param string                                 $lock_token          Token the claim returned.
	 */
	public function release( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary, string $lock_token ): void {
		global $wpdb;

		unset( $this->held_claims[ $lock_token ] );
		$lock_key   = $persistence_vocabulary->get_order_lock_key( $order );
		$holder_key = $this->get_lock_holder_key( $order, $persistence_vocabulary );

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

		// The holder row is read as stored, expired or not: the delete below matches it exactly, so it removes a lock this
		// claim still holds and never one a later claim took over.
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
	 * Get the value stored in the order payment lock for this claim.
	 *
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param string|null                            $lock_value          What this claim processes, stored as the lock's value: a payment
	 *                                                                    reference, an operation key or a provider event key; the provider's
	 *                                                                    sentinel when null.
	 * @return string
	 */
	private function get_lock_value( ProviderPersistenceVocabularyInterface $persistence_vocabulary, ?string $lock_value ): string {
		return empty( $lock_value ) ? $persistence_vocabulary->get_lock_sentinel() : $lock_value;
	}

	/**
	 * Get the transient key of the record naming the operation that holds the order payment lock.
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return string
	 */
	private function get_lock_holder_key( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): string {
		return $persistence_vocabulary->get_order_lock_key( $order ) . '_holder';
	}
}
