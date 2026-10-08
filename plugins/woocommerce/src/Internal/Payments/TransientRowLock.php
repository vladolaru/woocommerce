<?php
/**
 * TransientRowLock class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Holds a lock in the database rows of a transient, so exactly one of several concurrent requests holds it.
 *
 * WordPress add_option() and set_transient() can let two requests both believe they stored a lock, so a claim inserts with INSERT
 * IGNORE, as WC_Install::seed_autoloaded_option() does, and takes an expired lock over with one compare-and-set UPDATE.
 * A release deletes the rows only while they hold the caller's value; an optional holder record fences it on a token.
 *
 * @since 11.2.0
 * @internal
 */
class TransientRowLock {

	/**
	 * Claim the lock rows of a transient.
	 *
	 * Claimants compete for the value row alone; the one that inserts it then writes the expiry row, replacing an
	 * expiry row a stopped release left behind. An expired lock is taken over with one UPDATE that matches the exact
	 * value and expiry read, so only one of several overlapping takeovers changes the rows. With a holder, the winner
	 * then writes its holder record; a takeover also replaces the former holder's record in the same UPDATE, so the
	 * former holder can no longer release the lock. Only a lock removed without its release, by the expired-transient
	 * cleanup or an unconditional unlock, can leave a former holder's record in place until a new claim's holder write
	 * replaces it.
	 *
	 * The rows are always in the options table, so the caller decides whether the lock belongs there or in the object
	 * cache. A holder record keeps the release token apart from a lock value other readers expect.
	 *
	 * @since 11.2.0
	 *
	 * @param string      $key          Transient key of the lock.
	 * @param string      $value        Lock value, as stored.
	 * @param int         $ttl          Lock time-to-live, in seconds.
	 * @param string|null $holder_key   Transient key of the holder record, if the caller keeps one.
	 * @param string|null $holder_value Holder record of this claim, as stored. Required with a holder key.
	 * @return bool True when this request holds the lock.
	 */
	public function claim( string $key, string $value, int $ttl, ?string $holder_key = null, ?string $holder_value = null ): bool {
		global $wpdb;

		$value_option   = '_transient_' . $key;
		$timeout_option = '_transient_timeout_' . $key;
		$expiration     = (string) ( time() + $ttl );
		$holder         = $this->get_holder_rows( $holder_key, $holder_value );

		// Read before competing for the value row: when this request wins it, an expiry row seen here was left by
		// a stopped release or by a holder that released since, and the winner replaces it.
		$leftover_timeout = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_option ) );

		$inserted = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$value_option,
				$value
			)
		);

		if ( 1 === $inserted ) {
			$claimed = $this->write_claimed_lock_expiry( $value_option, $timeout_option, $value, $leftover_timeout, $expiration );
		} else {
			$claimed = $this->take_over_expired_lock_rows( $value_option, $timeout_option, $value, $expiration, $holder );
		}

		if ( $claimed && null !== $holder ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off'), (%s, %s, 'off')
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					$holder['option'],
					$holder['value'],
					$holder['timeout_option'],
					$expiration
				)
			);
		}

		$this->forget_cached_options( $this->get_option_names( $value_option, $timeout_option, $holder ) );

		return $claimed;
	}

	/**
	 * Release the lock rows of a transient, but only while they still hold the caller's value.
	 *
	 * With a holder, the lock is released only while the holder row still holds the caller's record too, and the
	 * holder rows are deleted with it. One statement checks and deletes, so a takeover that ran meanwhile is kept.
	 *
	 * @since 11.2.0
	 *
	 * @param string      $key          Transient key of the lock.
	 * @param string      $value        Lock value the caller claimed, as stored.
	 * @param string|null $holder_key   Transient key of the holder record, if the caller keeps one.
	 * @param string|null $holder_value Holder record of the caller's claim, as stored. Required with a holder key.
	 */
	public function release( string $key, string $value, ?string $holder_key = null, ?string $holder_value = null ): void {
		global $wpdb;

		$value_option   = '_transient_' . $key;
		$timeout_option = '_transient_timeout_' . $key;
		$holder         = $this->get_holder_rows( $holder_key, $holder_value );

		if ( null === $holder ) {
			$delete = $wpdb->prepare(
				"DELETE lock_value, lock_timeout FROM {$wpdb->options} AS lock_value
				LEFT JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
				WHERE lock_value.option_name = %s AND lock_value.option_value = %s",
				$timeout_option,
				$value_option,
				$value
			);
		} else {
			// A takeover rewrites the holder record in the same statement that takes the lock over.
			$delete = $wpdb->prepare(
				"DELETE lock_value, lock_timeout, lock_holder, lock_holder_timeout FROM {$wpdb->options} AS lock_value
				INNER JOIN {$wpdb->options} AS lock_holder ON lock_holder.option_name = %s AND lock_holder.option_value = %s
				LEFT JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
				LEFT JOIN {$wpdb->options} AS lock_holder_timeout ON lock_holder_timeout.option_name = %s
				WHERE lock_value.option_name = %s AND lock_value.option_value = %s",
				$holder['option'],
				$holder['value'],
				$timeout_option,
				$holder['timeout_option'],
				$value_option,
				$value
			);
		}

		$deleted = $wpdb->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( false === $deleted ) {
			// A failed delete, such as a deadlock victim, would otherwise leave the lock held for a full TTL.
			$wpdb->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}

		$this->forget_cached_options( $this->get_option_names( $value_option, $timeout_option, $holder ) );
	}

	/**
	 * Read the value of a transient straight from its database rows, without the expired-row deletion get_transient() does.
	 *
	 * WordPress get_transient() deletes an expired transient by name, so a reader could delete rows a takeover just
	 * wrote. Expired rows are left for the next claim to take over.
	 *
	 * @since 11.2.0
	 *
	 * @param string $key Transient key.
	 * @return string|null Stored value, or null when missing or expired.
	 */
	public function read( string $key ): ?string {
		$value_option   = '_transient_' . $key;
		$timeout_option = '_transient_timeout_' . $key;
		$rows           = $this->select_lock_rows( $value_option, $timeout_option );

		if ( ! isset( $rows[ $value_option ] ) || ( isset( $rows[ $timeout_option ] ) && (int) $rows[ $timeout_option ]->option_value < time() ) ) {
			return null;
		}

		return (string) $rows[ $value_option ]->option_value;
	}

	/**
	 * Read the value and expiry rows of a lock straight from the database.
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
	 * Write the expiry row of a lock whose value row this request just inserted.
	 *
	 * A leftover expiry row read before the insert is replaced only while it still holds that value. When the
	 * expiry row changed meanwhile, the claim holds the lock exactly when the value row is still its own, and
	 * then makes sure an expiry row exists. A takeover of an expired leftover also leaves the value unchanged
	 * when the taker uses the same lock value, so after one could have run the claim is refused.
	 *
	 * @param string      $value_option     Lock value option name.
	 * @param string      $timeout_option   Lock expiry option name.
	 * @param string      $stored_value     Lock value this request inserted, as stored.
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
	 * Take over lock rows whose expiry has passed.
	 *
	 * A value row without an expiry row is treated as held. It appears while a claimant is between inserting
	 * its value row and its expiry row, or when it stopped there, so it gets an expiry here instead of
	 * blocking the lock forever.
	 *
	 * @param string                                                       $value_option   Lock value option name.
	 * @param string                                                       $timeout_option Lock expiry option name.
	 * @param string                                                       $stored_value   Lock value to store.
	 * @param string                                                       $expiration     New lock expiry timestamp.
	 * @param array{option:string,timeout_option:string,value:string}|null $holder         Holder rows of this claim, if any.
	 * @return bool True when this request took the lock over.
	 */
	private function take_over_expired_lock_rows( string $value_option, string $timeout_option, string $stored_value, string $expiration, ?array $holder ): bool {
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

		if ( null === $holder ) {
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

		return 0 < (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} AS lock_value
				INNER JOIN {$wpdb->options} AS lock_timeout ON lock_timeout.option_name = %s
				LEFT JOIN {$wpdb->options} AS lock_holder ON lock_holder.option_name = %s
				SET lock_value.option_value = %s, lock_timeout.option_value = %s, lock_holder.option_value = %s
				WHERE lock_value.option_name = %s AND lock_value.option_value = %s AND lock_timeout.option_value = %s",
				$timeout_option,
				$holder['option'],
				$stored_value,
				$expiration,
				$holder['value'],
				$value_option,
				$rows[ $value_option ]->option_value,
				$rows[ $timeout_option ]->option_value
			)
		);
	}

	/**
	 * Get the option names and value of a holder record, when the caller keeps one.
	 *
	 * @param string|null $holder_key   Transient key of the holder record.
	 * @param string|null $holder_value Holder record, as stored.
	 * @return array{option:string,timeout_option:string,value:string}|null
	 */
	private function get_holder_rows( ?string $holder_key, ?string $holder_value ): ?array {
		if ( null === $holder_key || null === $holder_value ) {
			return null;
		}

		return array(
			'option'         => '_transient_' . $holder_key,
			'timeout_option' => '_transient_timeout_' . $holder_key,
			'value'          => $holder_value,
		);
	}

	/**
	 * Get every option name a lock writes.
	 *
	 * @param string                                                       $value_option   Lock value option name.
	 * @param string                                                       $timeout_option Lock expiry option name.
	 * @param array{option:string,timeout_option:string,value:string}|null $holder         Holder rows, if any.
	 * @return string[]
	 */
	private function get_option_names( string $value_option, string $timeout_option, ?array $holder ): array {
		return null === $holder
			? array( $value_option, $timeout_option )
			: array( $value_option, $timeout_option, $holder['option'], $holder['timeout_option'] );
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
}
