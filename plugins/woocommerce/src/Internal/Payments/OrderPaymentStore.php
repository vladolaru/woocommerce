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
	 * the plugin would have allowed. Release the lock with release_order_payment_lock().
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                      $order               Order being locked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference being processed.
	 * @return bool True when the lock was claimed.
	 */
	public function claim_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference = null ): bool {
		$lock_key = $persistence_profile->get_order_lock_key( $order );
		$value    = $this->get_lock_value( $persistence_profile, $payment_reference );
		$ttl      = $persistence_profile->get_lock_ttl_seconds();

		if ( $this->lock_lives_in_object_cache() ) {
			return wp_cache_add( $lock_key, $value, 'transient', $ttl );
		}

		return $this->claim_lock_rows( $lock_key, $value, $ttl );
	}

	/**
	 * Claim the order payment lock and record which operation holds it.
	 *
	 * The record lets a later refusal name the holder and the lock's age in its warning.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                      $order               Order being locked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference being processed.
	 * @param string                        $operation           Operation claiming the lock, such as 'refund' or 'capture'.
	 * @return bool True when the lock was claimed.
	 */
	public function claim_order_payment_lock_for_operation( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference, string $operation ): bool {
		if ( ! $this->claim_order_payment_lock( $order, $persistence_profile, $payment_reference ) ) {
			return false;
		}

		set_transient(
			$this->get_lock_holder_key( $order, $persistence_profile ),
			array(
				'operation'  => $operation,
				'lock_value' => $this->get_lock_value( $persistence_profile, $payment_reference ),
				'claimed_at' => time(),
			),
			$persistence_profile->get_lock_ttl_seconds()
		);

		return true;
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
	 * Release the order payment lock, but only while the caller still holds it.
	 *
	 * The counterpart of claim_order_payment_lock(): an operation that ran past the lock TTL must not
	 * release a lock another operation has since taken over, so the stored value is compared first.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order                      $order               Order being unlocked.
	 * @param ProviderPersistenceVocabulary $persistence_profile Provider persistence vocabulary.
	 * @param string|null                   $payment_reference   Payment reference the lock was claimed with.
	 */
	public function release_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $persistence_profile, ?string $payment_reference = null ): void {
		global $wpdb;

		$lock_key = $persistence_profile->get_order_lock_key( $order );
		$value    = $this->get_lock_value( $persistence_profile, $payment_reference );

		if ( $this->lock_lives_in_object_cache() ) {
			// The object cache API has no compare-and-delete, so this check and delete are not atomic.
			$released = wp_cache_get( $lock_key, 'transient' ) === $value && wp_cache_delete( $lock_key, 'transient' );
		} else {
			$value_option   = '_transient_' . $lock_key;
			$timeout_option = '_transient_timeout_' . $lock_key;

			// One statement deletes the value and expiry rows together, and only when the value is ours.
			$delete  = $wpdb->prepare(
				"DELETE lock_value, lock_timeout FROM {$wpdb->options} AS lock_value
				LEFT JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
				WHERE lock_value.option_name = %s AND lock_value.option_value = %s",
				$timeout_option,
				$value_option,
				maybe_serialize( $value )
			);
			$deleted = $wpdb->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( false === $deleted ) {
				// A failed delete, such as a deadlock victim, would otherwise leave the lock held for a full TTL.
				$deleted = $wpdb->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			}

			$released = 0 < (int) $deleted;
			$this->forget_cached_options( array( $value_option, $timeout_option ) );
		}

		if ( $released ) {
			delete_transient( $this->get_lock_holder_key( $order, $persistence_profile ) );
		}
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
	 * overlapping takeovers changes the rows.
	 *
	 * @param string $lock_key Lock transient key.
	 * @param string $value    Lock value.
	 * @param int    $ttl      Lock time-to-live, in seconds.
	 * @return bool True when the lock was claimed.
	 */
	private function claim_lock_rows( string $lock_key, string $value, int $ttl ): bool {
		global $wpdb;

		$value_option   = '_transient_' . $lock_key;
		$timeout_option = '_transient_timeout_' . $lock_key;
		$stored_value   = maybe_serialize( $value );
		$expiration     = (string) ( time() + $ttl );

		// Read before competing for the value row: when this request wins it, an expiry row seen here is one a
		// stopped release left behind, which the winner replaces.
		$leftover_timeout = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_option ) );

		$inserted = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$value_option,
				$stored_value
			)
		);

		if ( 1 === $inserted ) {
			$claimed = $this->write_claimed_lock_expiry( $timeout_option, $leftover_timeout, $expiration );
		} else {
			$claimed = $this->take_over_expired_lock_rows( $value_option, $timeout_option, $stored_value, $expiration );
		}

		$this->forget_cached_options( array( $value_option, $timeout_option ) );

		return $claimed;
	}

	/**
	 * Write the expiry row of a lock whose value row this request just inserted.
	 *
	 * A leftover expiry row is replaced only while it still holds the value read before the insert. When it
	 * has passed, another claimant may take the lock over in between; that takeover changes the expiry, so
	 * this write then matches nothing and the claim is lost, leaving the value row to the taker.
	 *
	 * @param string      $timeout_option   Lock expiry option name.
	 * @param string|null $leftover_timeout Expiry row value read before the value row was inserted, if any.
	 * @param string      $expiration       New lock expiry timestamp.
	 * @return bool True when this request holds the lock.
	 */
	private function write_claimed_lock_expiry( string $timeout_option, ?string $leftover_timeout, string $expiration ): bool {
		global $wpdb;

		if ( null === $leftover_timeout ) {
			// A claimant that finds the value row without an expiry gives it one; the lock is still ours.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					$timeout_option,
					$expiration
				)
			);

			return true;
		}

		if ( $leftover_timeout === $expiration ) {
			// Nothing to change, and an unexpired expiry cannot have been taken over.
			return true;
		}

		return 0 < (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$expiration,
				$timeout_option,
				$leftover_timeout
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
	 * @return bool True when this request took the lock over.
	 */
	private function take_over_expired_lock_rows( string $value_option, string $timeout_option, string $stored_value, string $expiration ): bool {
		global $wpdb;

		$rows = $this->select_lock_rows( $value_option, $timeout_option );

		if ( ! isset( $rows[ $value_option ] ) ) {
			return false;
		}

		if ( ! isset( $rows[ $timeout_option ] ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					$timeout_option,
					$expiration
				)
			);

			return false;
		}

		if ( (int) $rows[ $timeout_option ]->option_value >= time() ) {
			return false;
		}

		return 0 < (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} AS lock_value
				INNER JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
				SET lock_value.option_value = %s, lock_timeout.option_value = %s
				WHERE lock_value.option_name = %s AND lock_value.option_value = %s AND lock_timeout.option_value = %s",
				$timeout_option,
				$stored_value,
				$expiration,
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
