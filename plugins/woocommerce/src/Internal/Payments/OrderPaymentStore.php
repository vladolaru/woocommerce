<?php
/**
 * OrderPaymentStore class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Throwable;
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
	 * Fixed prefix of the warning logged when the lock refuses an operation, so it can be found in logs.
	 *
	 * @var string
	 */
	private const LOCK_REFUSAL_LOG_PREFIX = 'order payment lock refused';

	/**
	 * Log source for lock refusals, matching the WooPayments plugin's log file.
	 *
	 * @var string
	 */
	private const LOCK_REFUSAL_LOG_SOURCE = 'woopayments';

	/**
	 * Get the preserved provider order/refund payment meta keys.
	 *
	 * @since 11.0.0
	 *
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return string[]
	 */
	public static function get_payment_meta_keys( ProviderPersistenceVocabulary $persistence_profile ): array {
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
	 * @param WC_Order                      $order               Order to project.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return array<string,mixed>
	 */
	public function read_payment_surface( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile ): array {
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
	 * @param WC_Order                      $order               Order being checked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference currently being processed.
	 * @return bool True when processing is locked.
	 */
	public function is_order_payment_locked( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference = null ): bool {
		$processing = $this->read_lock_transient( $persistence_profile->get_order_lock_key( $order ) );

		return $persistence_profile->get_lock_sentinel() === $processing
			|| ( null !== $payment_reference && $processing === $payment_reference );
	}

	/**
	 * Atomically claim the order payment lock for a money-moving operation.
	 *
	 * Unlike WooPayments-compatible reference checks, native processing uses this as an order-wide
	 * claim: any active lock value blocks checkout, refund, capture, and cancel from starting.
	 * WooPayments 11.1.0 locks only intent-driven status updates; this stricter lock is owner-ratified
	 * money-path hardening (inbox N-270), and log_order_payment_lock_refusal() records each refusal
	 * the plugin would have allowed. Release the lock with release_order_payment_lock() and the returned token.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                      $order               Order being locked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference being processed.
	 * @return string|null The claim token, or null when the lock is held.
	 */
	public function claim_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference = null ): ?string {
		return $this->claim_order_payment_lock_for_operation( $order, $persistence_profile, $payment_reference, 'payment operation' );
	}

	/**
	 * Claim the order payment lock and record which operation holds it.
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

		return $this->claim_lock_rows( $lock_key, $value, $ttl, $holder_key, maybe_serialize( $holder ) ) ? $token : null;
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
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}

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
	 * Lock an order for payment processing.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                      $order               Order being locked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference being processed.
	 */
	public function lock_order_payment( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference = null ): void {
		set_transient(
			$persistence_profile->get_order_lock_key( $order ),
			$this->get_lock_value( $persistence_profile, $payment_reference ),
			$persistence_profile->get_lock_ttl_seconds()
		);
	}

	/**
	 * Unlock an order for payment processing, whoever holds the lock.
	 *
	 * Pairs with lock_order_payment(); a claimed lock is released with release_order_payment_lock().
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                      $order               Order being unlocked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 */
	public function unlock_order_payment( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile ): void {
		delete_transient( $persistence_profile->get_order_lock_key( $order ) );
		delete_transient( $this->get_lock_holder_key( $order, $persistence_profile ) );
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

		$value_option          = '_transient_' . $lock_key;
		$timeout_option        = '_transient_timeout_' . $lock_key;
		$holder_option         = '_transient_' . $holder_key;
		$holder_timeout_option = '_transient_timeout_' . $holder_key;
		$stored_holder         = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $holder_option ) );
		$holder                = null === $stored_holder ? null : maybe_unserialize( $stored_holder );

		if ( ! $this->is_holder_of_claim( $holder, $lock_token ) ) {
			return;
		}

		// One statement deletes the lock and its holder record, and only while both are still this claim's: a takeover
		// rewrites the holder record in the same statement that takes the lock over.
		$delete  = $wpdb->prepare(
			"DELETE lock_value, lock_timeout, lock_holder, lock_holder_timeout FROM {$wpdb->options} AS lock_value
			INNER JOIN {$wpdb->options} AS lock_holder ON lock_holder.option_name = %s AND lock_holder.option_value = %s
			LEFT JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
			LEFT JOIN {$wpdb->options} AS lock_holder_timeout ON lock_holder_timeout.option_name = %s
			WHERE lock_value.option_name = %s AND lock_value.option_value = %s",
			$holder_option,
			$stored_holder,
			$timeout_option,
			$holder_timeout_option,
			$value_option,
			maybe_serialize( $holder['lock_value'] )
		);
		$deleted = $wpdb->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( false === $deleted ) {
			// A failed delete, such as a deadlock victim, would otherwise leave the lock held for a full TTL.
			$wpdb->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}

		$this->forget_cached_options( array( $value_option, $timeout_option, $holder_option, $holder_timeout_option ) );
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
		return ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() )
			|| ( function_exists( 'wp_installing' ) && wp_installing() );
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

		$value_option   = '_transient_' . $key;
		$timeout_option = '_transient_timeout_' . $key;
		$rows           = $this->select_lock_rows( $value_option, $timeout_option );

		if ( ! isset( $rows[ $value_option ] ) || ( isset( $rows[ $timeout_option ] ) && (int) $rows[ $timeout_option ]->option_value < time() ) ) {
			return false;
		}

		return maybe_unserialize( $rows[ $value_option ]->option_value );
	}

	/**
	 * Read the value and expiry rows of a lock transient straight from the database.
	 *
	 * @param string $value_option   Lock value option name.
	 * @param string $timeout_option Lock expiry option name.
	 * @return array<string,\stdClass> Rows keyed by option name.
	 */
	private function select_lock_rows( string $value_option, string $timeout_option ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				$value_option,
				$timeout_option
			),
			OBJECT_K
		);
	}

	/**
	 * Claim the order payment lock rows in the options table, so exactly one concurrent claimant wins.
	 *
	 * WordPress add_option() trusts the request's notoptions cache and then upserts, so it can overwrite a
	 * lock another request stored after this one read it as missing. Like WC_Install::seed_autoloaded_option(),
	 * the claim uses INSERT IGNORE instead. Claimants compete for the value row alone; the one that inserts
	 * it then writes the expiry row, replacing an expiry row a stopped release left behind. An expired lock
	 * is taken over with one UPDATE that matches the exact value and expiry read, so only one of several
	 * overlapping takeovers changes the rows. The winner then writes its holder record; a takeover also replaces
	 * the former holder's record in the same UPDATE, so the former holder can no longer release the lock. Only a lock
	 * removed without its release, by the expired-transient cleanup or an unconditional unlock, can leave a former
	 * holder's record in place until a new claim's holder write replaces it.
	 *
	 * @param string $lock_key      Lock transient key.
	 * @param string $value         Lock value.
	 * @param int    $ttl           Lock time-to-live, in seconds.
	 * @param string $holder_key    Holder record transient key.
	 * @param string $stored_holder Serialized holder record of this claim.
	 * @return bool True when the lock was claimed.
	 */
	private function claim_lock_rows( string $lock_key, string $value, int $ttl, string $holder_key, string $stored_holder ): bool {
		global $wpdb;

		$value_option          = '_transient_' . $lock_key;
		$timeout_option        = '_transient_timeout_' . $lock_key;
		$holder_option         = '_transient_' . $holder_key;
		$holder_timeout_option = '_transient_timeout_' . $holder_key;
		$stored_value          = maybe_serialize( $value );
		$expiration            = (string) ( time() + $ttl );

		// Read before competing for the value row: when this request wins it, an expiry row seen here was left by
		// a stopped release or by a holder that released since, and the winner replaces it.
		$leftover_timeout = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_option ) );

		$inserted = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$value_option,
				$stored_value
			)
		);

		if ( 1 === $inserted ) {
			$claimed = $this->write_claimed_lock_expiry( $value_option, $timeout_option, $stored_value, $leftover_timeout, $expiration );
		} else {
			$claimed = $this->take_over_expired_lock_rows( $value_option, $timeout_option, $stored_value, $expiration, $holder_option, $stored_holder );
		}

		if ( $claimed ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off'), (%s, %s, 'off')
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					$holder_option,
					$stored_holder,
					$holder_timeout_option,
					$expiration
				)
			);
		}

		$this->forget_cached_options( array( $value_option, $timeout_option, $holder_option, $holder_timeout_option ) );

		return $claimed;
	}

	/**
	 * Write the expiry row of a lock whose value row this request just inserted.
	 *
	 * A leftover expiry row read before the insert is replaced only while it still holds that value. When the
	 * expiry row changed meanwhile, the claim holds the lock exactly when the value row is still its own, and
	 * then makes sure an expiry row exists. A takeover of an expired leftover also leaves the value unchanged
	 * when the taker uses the same lock value, so after one could have run the claim is refused.
	 *
	 * @param string      $value_option     Lock value option name.
	 * @param string      $timeout_option   Lock expiry option name.
	 * @param string      $stored_value     Serialized lock value this request inserted.
	 * @param string|null $leftover_timeout Expiry row value read before the value row was inserted, if any.
	 * @param string      $expiration       New lock expiry timestamp.
	 * @return bool True when this request holds the lock.
	 */
	private function write_claimed_lock_expiry( string $value_option, string $timeout_option, string $stored_value, ?string $leftover_timeout, string $expiration ): bool {
		global $wpdb;

		if ( null === $leftover_timeout ) {
			$written = $this->insert_lock_expiry( $timeout_option, $expiration );
		} else {
			$written = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$expiration,
					$timeout_option,
					$leftover_timeout
				)
			);
		}

		if ( 0 < $written ) {
			return true;
		}

		// The expiry row was deleted by a release, filled in by a refused rival, already held this expiry, or
		// was replaced by a takeover.
		$rows = $this->select_lock_rows( $value_option, $timeout_option );

		if ( ! isset( $rows[ $value_option ] ) || $rows[ $value_option ]->option_value !== $stored_value ) {
			return false;
		}

		if ( ! isset( $rows[ $timeout_option ] ) ) {
			$this->insert_lock_expiry( $timeout_option, $expiration );

			return true;
		}

		// Every expiry row written after the read is in the future, so only an expired leftover can be taken over.
		return null === $leftover_timeout || (int) $leftover_timeout >= time();
	}

	/**
	 * Insert the expiry row of a lock unless one already exists.
	 *
	 * @param string $timeout_option Lock expiry option name.
	 * @param string $expiration     Lock expiry timestamp.
	 * @return int Number of rows inserted.
	 */
	private function insert_lock_expiry( string $timeout_option, string $expiration ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$timeout_option,
				$expiration
			)
		);
	}

	/**
	 * Take over order payment lock rows whose expiry has passed.
	 *
	 * A value row without an expiry row is treated as held. It appears while a claimant is between inserting
	 * its value row and its expiry row, or when it stopped there, so it gets an expiry here instead of
	 * blocking the order forever.
	 *
	 * @param string $value_option   Lock value option name.
	 * @param string $timeout_option Lock expiry option name.
	 * @param string $stored_value   Serialized lock value to store.
	 * @param string $expiration     New lock expiry timestamp.
	 * @param string $holder_option  Holder record option name.
	 * @param string $stored_holder  Serialized holder record of this claim.
	 * @return bool True when this request took the lock over.
	 */
	private function take_over_expired_lock_rows( string $value_option, string $timeout_option, string $stored_value, string $expiration, string $holder_option, string $stored_holder ): bool {
		global $wpdb;

		$rows = $this->select_lock_rows( $value_option, $timeout_option );

		if ( ! isset( $rows[ $value_option ] ) ) {
			return false;
		}

		if ( ! isset( $rows[ $timeout_option ] ) ) {
			$this->insert_lock_expiry( $timeout_option, $expiration );

			return false;
		}

		if ( (int) $rows[ $timeout_option ]->option_value >= time() ) {
			return false;
		}

		return 0 < (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} AS lock_value
				INNER JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
				LEFT JOIN {$wpdb->options} AS lock_holder ON lock_holder.option_name = %s
				SET lock_value.option_value = %s, lock_timeout.option_value = %s, lock_holder.option_value = %s
				WHERE lock_value.option_name = %s AND lock_value.option_value = %s AND lock_timeout.option_value = %s",
				$timeout_option,
				$holder_option,
				$stored_value,
				$expiration,
				$stored_holder,
				$value_option,
				$rows[ $value_option ]->option_value,
				$rows[ $timeout_option ]->option_value
			)
		);
	}

	/**
	 * Drop options written with raw SQL from the object caches, so later reads see the database.
	 *
	 * @param string[] $option_names Option names.
	 */
	private function forget_cached_options( array $option_names ): void {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$alloptions = wp_cache_get( 'alloptions', 'options' );

		foreach ( $option_names as $option_name ) {
			wp_cache_delete( $option_name, 'options' );

			if ( is_array( $notoptions ) && isset( $notoptions[ $option_name ] ) ) {
				unset( $notoptions[ $option_name ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}

			if ( is_array( $alloptions ) && isset( $alloptions[ $option_name ] ) ) {
				unset( $alloptions[ $option_name ] );
				wp_cache_set( 'alloptions', $alloptions, 'options' );
			}
		}
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

	/**
	 * Read preserved payment meta from an order or refund object.
	 *
	 * @param WC_Abstract_Order             $order               Order or refund object.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return array<string,string>
	 */
	private function read_payment_meta( WC_Abstract_Order $order, ProviderPersistenceVocabulary $persistence_profile ): array {
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
	 * @param WC_Order                      $order               Order object.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_refund_surfaces( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile ): array {
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
