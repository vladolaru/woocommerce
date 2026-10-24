<?php
/**
 * WooPaymentsCutoverStateStore class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates the cutover record, provides atomic writes, and manages its write lock.
 *
 * WooPaymentsRuntimeArbiter reads the record's `state` and `request_origin_token` from this option directly for the ownership
 * rule; finalize sets the token before it deactivates the extension.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsCutoverStateStore {

	/** State option name. */
	public const OPTION_NAME = 'woocommerce_woopayments_cutover_state';

	/** Autoloaded value seeded at install for a store with no record, so reading it runs no query. */
	public const ABSENT_RECORD = 'none';

	/** Write lock option name. The stored name keeps "lease". */
	public const LEASE_OPTION_NAME = 'woocommerce_woopayments_cutover_state_lease';

	/** Current persisted record schema. */
	public const SCHEMA_VERSION = 1;

	/** Record key of the claiming worker's token. */
	public const CLAIM_TOKEN_KEY = 'lease_token';

	/** Record key of the time a claim may be recovered. */
	public const CLAIM_EXPIRES_AT_KEY = 'lease_expires_at';

	/** Record key of the codes that block or closed the generation. */
	public const BLOCKING_CODES_KEY = 'deferred_codes';

	/** Maximum age of the write lock before another request may take it over. */
	public const WRITE_LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/** Maximum number of diagnostic steps retained in one record. */
	public const MAX_STEP_LOG_ENTRIES = 50;

	/** The job is waiting for its next action, or for the merchant to start it (current_step awaiting_merchant_start). */
	public const STATE_PENDING = 'pending';

	/** The job claimed an attempt and is running it; the record's lease_expires_at is when repair_schedule() may recover it. */
	public const STATE_RUNNING = 'running';

	/** The job is waiting for a scheduled retry. */
	public const STATE_DEFERRED = 'deferred';

	/** The store completed its cutover. */
	public const STATE_DONE = 'done';

	/** The store is excluded from the cutover. */
	public const STATE_EXCLUDED = 'excluded';

	/** Valid persisted state values. */
	private const VALID_STATES = array(
		self::STATE_PENDING,
		self::STATE_RUNNING,
		self::STATE_DEFERRED,
		self::STATE_DONE,
		self::STATE_EXCLUDED,
	);

	/**
	 * Read the current validated record.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed>|null Valid record, or null when no usable record exists.
	 */
	public function get_record(): ?array {
		$record = get_option( self::OPTION_NAME, null );

		return is_array( $record ) && $this->is_valid_record( $record ) ? $record : null;
	}

	/**
	 * Create the site's record as an autoloaded option: insert it, or replace the absent marker; refuse any other record.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $record Record to persist.
	 * @return bool True when the requested record is stored.
	 * @throws InvalidArgumentException When the record does not match the current schema.
	 */
	public function create_record( array $record ): bool {
		if ( ! $this->is_valid_record( $record ) ) {
			throw new InvalidArgumentException( 'Invalid WooPayments cutover state record.' );
		}

		global $wpdb;
		$current = get_option( self::OPTION_NAME, null );
		if ( null === $current ) {
			// Not add_option(): it trusts this request's notoptions cache and upserts, so it could overwrite a record created since.
			$value    = maybe_serialize( $record );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::OPTION_NAME,
					$value,
					wp_determine_option_autoload_value( self::OPTION_NAME, $record, $value, true )
				)
			);
			$this->invalidate_record_cache();
			if ( 1 !== $inserted ) {
				return false;
			}
		} elseif ( self::ABSENT_RECORD === $current ) {
			// Replace only the marker, so a concurrent first save still fails the readback below.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $record ), self::OPTION_NAME, self::ABSENT_RECORD ) );
			$this->invalidate_record_cache();
		} elseif ( $current !== $record ) {
			return false;
		}

		return get_option( self::OPTION_NAME, null ) === $record;
	}

	/**
	 * Atomically replace one exact persisted revision.
	 *
	 * The serialized option value is the compare key, so generation, attempt,
	 * revision, claim owner, and state are fenced together.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $expected    Exact record previously read by the caller.
	 * @param array<string,mixed> $replacement Next complete record.
	 * @return bool True only when the expected record was still current and the replacement was read back.
	 * @throws InvalidArgumentException When either record does not match the current schema.
	 */
	public function compare_and_set_record( array $expected, array $replacement ): bool {
		if ( ! $this->is_valid_record( $expected ) || ! $this->is_valid_record( $replacement ) ) {
			throw new InvalidArgumentException( 'Invalid WooPayments cutover state record.' );
		}

		if (
			$replacement['revision'] !== $expected['revision'] + 1
			|| $replacement['generation'] < $expected['generation']
			|| ( $replacement['generation'] === $expected['generation'] && $replacement['attempt'] < $expected['attempt'] )
		) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
				maybe_serialize( $replacement ),
				self::OPTION_NAME,
				maybe_serialize( $expected )
			)
		);
		$this->invalidate_record_cache();

		return 1 === $updated && get_option( self::OPTION_NAME, null ) === $replacement;
	}

	/**
	 * Return the next generation after any previously stored record.
	 *
	 * @since 11.2.0
	 *
	 * @return int Positive monotonic generation.
	 */
	public function get_next_generation(): int {
		$record  = get_option( self::OPTION_NAME, null );
		$current = is_array( $record ) && is_int( $record['generation'] ?? null ) ? $record['generation'] : 0;

		return max( 0, $current ) + 1;
	}

	/**
	 * Acquire the site-local write lock.
	 *
	 * @since 11.2.0
	 *
	 * @param int $now Current UTC timestamp.
	 * @return string|null Write lock token, or null while another worker owns it.
	 */
	public function acquire_write_lock( int $now ): ?string {
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'      => $token,
			'expires_at' => $now + self::WRITE_LOCK_TTL,
		);

		if ( $this->add_write_lock( $lock ) ) {
			return $token;
		}

		$current = get_option( self::LEASE_OPTION_NAME, null );
		if ( ! is_array( $current ) || ! is_int( $current['expires_at'] ?? null ) || $current['expires_at'] > $now ) {
			return null;
		}

		if ( ! $this->replace_write_lock_if_unchanged( $current, $lock ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Release the write lock only when the caller still owns it.
	 *
	 * @since 11.2.0
	 *
	 * @param string $token Write lock owner token.
	 */
	public function release_write_lock( string $token ): void {
		$current = get_option( self::LEASE_OPTION_NAME, null );
		if ( ! is_array( $current ) || ( $current['token'] ?? null ) !== $token ) {
			return;
		}

		$this->delete_write_lock_if_unchanged( $current );
	}

	/**
	 * Validate the record's required keys and their types; `request_origin_token` is not checked here, and
	 * WooPaymentsRuntimeArbiter reads it from the option directly.
	 *
	 * @param array<string,mixed> $record Candidate record.
	 * @return bool
	 */
	private function is_valid_record( array $record ): bool {
		$required_keys = array(
			'schema_version',
			'generation',
			'revision',
			'state',
			'started_at',
			'updated_at',
			'attempt',
			'action_id',
			'current_step',
			'step_log',
			self::BLOCKING_CODES_KEY,
			'informational_outcomes',
			'next_attempt_at',
			self::CLAIM_TOKEN_KEY,
			self::CLAIM_EXPIRES_AT_KEY,
		);

		if ( array_diff( $required_keys, array_keys( $record ) ) ) {
			return false;
		}

		if (
			self::SCHEMA_VERSION !== $record['schema_version']
			|| ! is_int( $record['generation'] ) || $record['generation'] < 1
			|| ! is_int( $record['revision'] ) || $record['revision'] < 1
			|| ! is_string( $record['state'] ) || ! in_array( $record['state'], self::VALID_STATES, true )
			|| ! is_int( $record['started_at'] ) || $record['started_at'] < 0
			|| ! is_int( $record['updated_at'] ) || $record['updated_at'] < 0
			|| ! is_int( $record['attempt'] ) || $record['attempt'] < 0
			|| ! is_int( $record['action_id'] ) || $record['action_id'] < 0
			|| ! is_string( $record['current_step'] )
			|| ! is_array( $record['step_log'] ) || count( $record['step_log'] ) > self::MAX_STEP_LOG_ENTRIES
			|| ! is_array( $record[ self::BLOCKING_CODES_KEY ] )
			|| ! is_array( $record['informational_outcomes'] )
			|| ( null !== $record['next_attempt_at'] && ( ! is_int( $record['next_attempt_at'] ) || $record['next_attempt_at'] < 0 ) )
			|| ( null !== $record[ self::CLAIM_TOKEN_KEY ] && ( ! is_string( $record[ self::CLAIM_TOKEN_KEY ] ) || '' === $record[ self::CLAIM_TOKEN_KEY ] ) )
			|| ( null !== $record[ self::CLAIM_EXPIRES_AT_KEY ] && ( ! is_int( $record[ self::CLAIM_EXPIRES_AT_KEY ] ) || $record[ self::CLAIM_EXPIRES_AT_KEY ] < 0 ) )
		) {
			return false;
		}

		if ( self::STATE_DEFERRED === $record['state'] && ! is_int( $record['next_attempt_at'] ) ) {
			return false;
		}

		if (
			( self::STATE_RUNNING === $record['state'] && ( ! is_string( $record[ self::CLAIM_TOKEN_KEY ] ) || ! is_int( $record[ self::CLAIM_EXPIRES_AT_KEY ] ) ) )
			|| ( self::STATE_RUNNING !== $record['state'] && ( null !== $record[ self::CLAIM_TOKEN_KEY ] || null !== $record[ self::CLAIM_EXPIRES_AT_KEY ] ) )
		) {
			return false;
		}

		foreach ( $record[ self::BLOCKING_CODES_KEY ] as $code ) {
			if ( ! is_string( $code ) ) {
				return false;
			}
		}

		foreach ( $record['step_log'] as $entry ) {
			if (
				! is_array( $entry )
				|| ! is_string( $entry['step'] ?? null )
				|| ! is_int( $entry['at'] ?? null )
				|| ( isset( $entry['context'] ) && ! is_array( $entry['context'] ) )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete the write lock only if its serialized value has not changed since it was read.
	 *
	 * @param array<string,mixed> $lock Write lock value read by the caller.
	 * @return bool True when that exact write lock was deleted.
	 */
	private function delete_write_lock_if_unchanged( array $lock ): bool {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
				self::LEASE_OPTION_NAME,
				maybe_serialize( $lock )
			)
		);
		wp_cache_delete( self::LEASE_OPTION_NAME, 'options' );

		return 1 === $deleted;
	}

	/**
	 * Replace an expired write lock only if its serialized value is still current.
	 *
	 * @param array<string,mixed>                $expected    Write lock value read by the caller.
	 * @param array{token:string,expires_at:int} $replacement New write lock value.
	 * @return bool True when that exact write lock was replaced.
	 */
	private function replace_write_lock_if_unchanged( array $expected, array $replacement ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
				maybe_serialize( $replacement ),
				self::LEASE_OPTION_NAME,
				maybe_serialize( $expected )
			)
		);
		wp_cache_delete( self::LEASE_OPTION_NAME, 'options' );

		return 1 === $updated && get_option( self::LEASE_OPTION_NAME, null ) === $replacement;
	}

	/**
	 * Add a non-autoloaded write lock when no worker currently owns it.
	 *
	 * @param array{token:string,expires_at:int} $lock Write lock value to add.
	 * @return bool
	 */
	private function add_write_lock( array $lock ): bool {
		global $wpdb;

		// Not add_option(): it trusts this request's notoptions cache and upserts, so it could take over a write lock taken since.
		$value    = maybe_serialize( $lock );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::LEASE_OPTION_NAME,
				$value,
				wp_determine_option_autoload_value( self::LEASE_OPTION_NAME, $lock, $value, false )
			)
		);
		wp_cache_delete( self::LEASE_OPTION_NAME, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return 1 === $inserted;
	}

	/**
	 * Clear every WordPress option cache that can hold the state record.
	 */
	private function invalidate_record_cache(): void {
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
