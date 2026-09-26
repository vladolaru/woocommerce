<?php
/**
 * WooPaymentsCutoverStateStore class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Persists validated site-local WooPayments cutover state and its worker lease.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverStateStore {

	/** State option name. */
	public const OPTION_NAME = 'woocommerce_woopayments_cutover_state';

	/** Worker lease option name. */
	public const LEASE_OPTION_NAME = 'woocommerce_woopayments_cutover_state_lease';

	/** Current persisted record schema. */
	public const SCHEMA_VERSION = 1;

	/** Maximum lease age before another request may recover the job. */
	public const LEASE_TTL = 5 * MINUTE_IN_SECONDS;

	/** Maximum number of diagnostic steps retained in one record. */
	public const MAX_STEP_LOG_ENTRIES = 50;

	/** Valid persisted state values. */
	private const VALID_STATES = array(
		WooPaymentsCutoverState::PENDING,
		WooPaymentsCutoverState::RUNNING,
		WooPaymentsCutoverState::DEFERRED,
		WooPaymentsCutoverState::DONE,
		WooPaymentsCutoverState::EXCLUDED,
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
	 * Persist an initial complete record as an autoloaded site option.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $record Record to persist.
	 * @return bool True when the requested record is stored.
	 * @throws InvalidArgumentException When the record does not match the current schema.
	 */
	public function save_record( array $record ): bool {
		if ( ! $this->is_valid_record( $record ) ) {
			throw new InvalidArgumentException( 'Invalid WooPayments cutover state record.' );
		}

		$current = get_option( self::OPTION_NAME, null );
		if ( null === $current ) {
			add_option( self::OPTION_NAME, $record, '', true );
		} elseif ( $current !== $record ) {
			return false;
		} elseif ( ! array_key_exists( self::OPTION_NAME, wp_load_alloptions() ) ) {
			wp_set_option_autoload( self::OPTION_NAME, true );
		}

		return get_option( self::OPTION_NAME, null ) === $record;
	}

	/**
	 * Atomically replace one exact persisted revision.
	 *
	 * The serialized option value is the compare key, so generation, attempt,
	 * revision, lease owner, and state are fenced together.
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
	 * Acquire the site-local worker lease.
	 *
	 * @since 11.2.0
	 *
	 * @param int $now Current UTC timestamp.
	 * @return string|null Lease token, or null while another worker owns it.
	 */
	public function acquire_lease( int $now ): ?string {
		$token = wp_generate_uuid4();
		$lease = array(
			'token'      => $token,
			'expires_at' => $now + self::LEASE_TTL,
		);

		if ( $this->add_lease( $lease ) ) {
			return $token;
		}

		$current = get_option( self::LEASE_OPTION_NAME, null );
		if ( ! is_array( $current ) || ! is_int( $current['expires_at'] ?? null ) || $current['expires_at'] > $now ) {
			return null;
		}

		if ( ! $this->replace_lease_if_unchanged( $current, $lease ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Release the lease only when the caller still owns it.
	 *
	 * @since 11.2.0
	 *
	 * @param string $token Lease owner token.
	 */
	public function release_lease( string $token ): void {
		$current = get_option( self::LEASE_OPTION_NAME, null );
		if ( ! is_array( $current ) || ( $current['token'] ?? null ) !== $token ) {
			return;
		}

		$this->delete_lease_if_unchanged( $current );
	}

	/**
	 * Validate the complete persisted record shape.
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
			'deferred_codes',
			'informational_outcomes',
			'next_attempt_at',
			'lease_token',
			'lease_expires_at',
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
			|| ! is_array( $record['deferred_codes'] )
			|| ! is_array( $record['informational_outcomes'] )
			|| ( null !== $record['next_attempt_at'] && ( ! is_int( $record['next_attempt_at'] ) || $record['next_attempt_at'] < 0 ) )
			|| ( null !== $record['lease_token'] && ( ! is_string( $record['lease_token'] ) || '' === $record['lease_token'] ) )
			|| ( null !== $record['lease_expires_at'] && ( ! is_int( $record['lease_expires_at'] ) || $record['lease_expires_at'] < 0 ) )
		) {
			return false;
		}

		if ( WooPaymentsCutoverState::DEFERRED === $record['state'] && ! is_int( $record['next_attempt_at'] ) ) {
			return false;
		}

		if (
			( WooPaymentsCutoverState::RUNNING === $record['state'] && ( ! is_string( $record['lease_token'] ) || ! is_int( $record['lease_expires_at'] ) ) )
			|| ( WooPaymentsCutoverState::RUNNING !== $record['state'] && ( null !== $record['lease_token'] || null !== $record['lease_expires_at'] ) )
		) {
			return false;
		}

		foreach ( $record['deferred_codes'] as $code ) {
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
	 * Delete a lease only if its serialized value has not changed since it was read.
	 *
	 * @param array<string,mixed> $lease Lease value read by the caller.
	 * @return bool True when that exact lease was deleted.
	 */
	private function delete_lease_if_unchanged( array $lease ): bool {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
				self::LEASE_OPTION_NAME,
				maybe_serialize( $lease )
			)
		);
		wp_cache_delete( self::LEASE_OPTION_NAME, 'options' );

		return 1 === $deleted;
	}

	/**
	 * Replace an expired lease only if its serialized value is still current.
	 *
	 * @param array<string,mixed>                $expected    Lease value read by the caller.
	 * @param array{token:string,expires_at:int} $replacement New lease value.
	 * @return bool True when that exact lease was replaced.
	 */
	private function replace_lease_if_unchanged( array $expected, array $replacement ): bool {
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
	 * Add a non-autoloaded lease when no worker currently owns it.
	 *
	 * @param array{token:string,expires_at:int} $lease Lease value to add.
	 * @return bool
	 */
	private function add_lease( array $lease ): bool {
		return add_option( self::LEASE_OPTION_NAME, $lease, '', false );
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
