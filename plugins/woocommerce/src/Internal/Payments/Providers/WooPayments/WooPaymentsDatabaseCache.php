<?php
/**
 * WooPaymentsDatabaseCache class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Reads WooPayments platform data through an option-backed cache.
 *
 * Each entry is stored as `data`, `fetched`, `errored` and `consecutive_errors`. It is refreshed when it is missing,
 * invalid or expired, keeps its last good data after an error, waits longer after each error in a row, and is read
 * from the database once per request. The option names, the stored shape and the TTLs are the WooPayments extension's
 * (client 11.1.0 `includes/class-database-cache.php`).
 * A failed recommended payment methods answer is the exception: it is retried on the error ladder instead of being kept
 * for a day.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsDatabaseCache {

	public const ACCOUNT_KEY = 'wcpay_account_data';

	public const ONBOARDING_FIELDS_DATA_KEY = 'wcpay_onboarding_fields_data';

	public const BUSINESS_TYPES_KEY = 'wcpay_business_types_data';

	public const FRAUD_SERVICES_KEY = 'wcpay_fraud_services_data';

	public const RECOMMENDED_PAYMENT_METHODS_KEY = 'wcpay_recommended_payment_methods';

	public const ADDRESS_AUTOCOMPLETE_JWT_KEY = 'wcpay_address_autocomplete_jwt';

	public const DISPUTE_STATUS_COUNTS_KEY = 'wcpay_dispute_status_counts_cache';

	public const DISPUTE_STATUS_COUNTS_KEY_TEST_MODE = 'wcpay_test_dispute_status_counts_cache';

	public const ACTIVE_DISPUTES_KEY = 'wcpay_active_dispute_cache';

	public const AUTHORIZATION_SUMMARY_KEY = 'wcpay_authorization_summary_cache';

	public const AUTHORIZATION_SUMMARY_KEY_TEST_MODE = 'wcpay_test_authorization_summary_cache';

	public const CONNECT_INCENTIVE_KEY = 'wcpay_connect_incentive';

	public const TRACKING_INFO_KEY = 'wcpay_tracking_info_cache';

	/**
	 * Every cache key, in the order an account reset deletes them.
	 *
	 * @var string[]
	 */
	public const ALL_KEYS = array(
		self::ACCOUNT_KEY,
		self::ADDRESS_AUTOCOMPLETE_JWT_KEY,
		self::ONBOARDING_FIELDS_DATA_KEY,
		self::BUSINESS_TYPES_KEY,
		self::FRAUD_SERVICES_KEY,
		self::RECOMMENDED_PAYMENT_METHODS_KEY,
		self::DISPUTE_STATUS_COUNTS_KEY,
		self::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE,
		self::ACTIVE_DISPUTES_KEY,
		self::AUTHORIZATION_SUMMARY_KEY,
		self::AUTHORIZATION_SUMMARY_KEY_TEST_MODE,
		self::CONNECT_INCENTIVE_KEY,
		self::TRACKING_INFO_KEY,
	);

	private const ACCOUNT_ADMIN_TTL = 2 * HOUR_IN_SECONDS;

	private const ACCOUNT_FRONTEND_TTL = DAY_IN_SECONDS;

	private const ONBOARDING_FIELDS_DATA_TTL = WEEK_IN_SECONDS;

	private const ERRORED_TTL_LADDER = array(
		2 * MINUTE_IN_SECONDS,
		5 * MINUTE_IN_SECONDS,
		10 * MINUTE_IN_SECONDS,
		15 * MINUTE_IN_SECONDS,
	);

	/**
	 * The LegacyProxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Cache contents read or written in this request, keyed by blog ID and then by cache key.
	 *
	 * @var array<int,array<string,array<string,mixed>|false>>
	 */
	private array $contents = array();

	/**
	 * Whether refreshes are disabled for this request.
	 *
	 * @var bool
	 */
	private bool $refresh_disabled = false;

	/**
	 * Cache keys whose failed write was logged in this request.
	 *
	 * @var array<string,true>
	 */
	private array $logged_write_failures = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy $legacy_proxy The LegacyProxy instance.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Get a cached value, refreshing it from the generator when it is missing, invalid or expired.
	 *
	 * A generator that returns null or false, or throws, counts as an error: the last valid data is kept and returned.
	 *
	 * @since 11.2.0
	 *
	 * @param string   $key           Cache key.
	 * @param callable $generator     Fetches fresh data.
	 * @param callable $validate_data Tells whether cached data can be served.
	 * @return mixed The cached or fresh data, or null when there is none.
	 */
	public function get_or_add( string $key, callable $generator, callable $validate_data ) {
		$cache_contents = $this->get_contents( $key );
		$data           = null;
		$old_data       = null;

		if ( is_array( $cache_contents ) && array_key_exists( 'data', $cache_contents ) && $validate_data( $cache_contents['data'] ) ) {
			$data     = $cache_contents['data'];
			$old_data = $data;
		}

		if ( $this->should_refresh( $key, $cache_contents, $validate_data, false ) ) {
			try {
				$data    = $generator();
				$errored = false === $data || null === $data;
			} catch ( \Throwable $e ) {
				$errored = true;
			}

			if ( $errored ) {
				$data = $old_data;
			}

			$this->write( $key, $this->build_contents( $key, $data, $errored ) );
		}

		return $data;
	}

	/**
	 * Get the stored cache contents, read once per request and blog.
	 *
	 * With an error handler, a read that throws is handed to it and treated as no contents. Without one, it propagates.
	 *
	 * @since 11.2.0
	 *
	 * @param string        $key           Cache key.
	 * @param callable|null $on_read_error Receives the throwable of a failed read.
	 * @return array<string,mixed>|false The stored contents, or false when there are none.
	 * @throws \Throwable When the read throws and there is no error handler.
	 */
	public function get_contents( string $key, ?callable $on_read_error = null ) {
		$blog_id = get_current_blog_id();
		if ( isset( $this->contents[ $blog_id ] ) && array_key_exists( $key, $this->contents[ $blog_id ] ) ) {
			return $this->contents[ $blog_id ][ $key ];
		}

		try {
			$cache_contents = $this->legacy_proxy->call_function( 'get_option', $key );
		} catch ( \Throwable $e ) {
			if ( null === $on_read_error ) {
				throw $e;
			}
			$on_read_error( $e );
			$cache_contents = false;
		}

		$this->contents[ $blog_id ][ $key ] = is_array( $cache_contents ) ? $cache_contents : false;

		return $this->contents[ $blog_id ][ $key ];
	}

	/**
	 * Tell whether cache contents should be refreshed.
	 *
	 * Cron, AJAX and Action Scheduler requests never refresh unless forced, like client 11.1.0
	 * `class-database-cache.php:289-330`.
	 *
	 * @since 11.2.0
	 *
	 * @param string   $key            Cache key.
	 * @param mixed    $cache_contents Stored contents.
	 * @param callable $validate_data  Tells whether cached data can be served.
	 * @param bool     $force_refresh  Whether to refresh whatever the contents.
	 * @return bool
	 */
	public function should_refresh( string $key, $cache_contents, callable $validate_data, bool $force_refresh ): bool {
		if ( $force_refresh ) {
			return true;
		}

		if (
			defined( 'DOING_CRON' )
			|| wp_doing_ajax()
			|| $this->refresh_disabled
		) {
			return false;
		}

		if ( false === $cache_contents ) {
			return true;
		}

		if (
			! is_array( $cache_contents )
			|| empty( $cache_contents )
			|| ! array_key_exists( 'data', $cache_contents )
			|| ! isset( $cache_contents['fetched'] )
			|| ! array_key_exists( 'errored', $cache_contents )
		) {
			return true;
		}

		if ( ! $cache_contents['errored'] && ! $validate_data( $cache_contents['data'] ) ) {
			return true;
		}

		return $this->is_expired( $key, $cache_contents );
	}

	/**
	 * Build the stored contents for data.
	 *
	 * Each errored write adds one to the stored error count; a successful write resets it.
	 *
	 * @since 11.2.0
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $data    Data to store.
	 * @param bool   $errored Whether the refresh that produced the data failed.
	 * @return array<string,mixed>
	 */
	public function build_contents( string $key, $data, bool $errored ): array {
		$consecutive_errors = 0;
		if ( $errored ) {
			$previous           = $this->get_contents( $key );
			$previous_count     = is_array( $previous ) && isset( $previous['consecutive_errors'] )
				? (int) $previous['consecutive_errors']
				: 0;
			$consecutive_errors = $previous_count + 1;
		}

		return array(
			'data'               => $data,
			'fetched'            => $this->legacy_proxy->call_function( 'time' ),
			'errored'            => $errored,
			'consecutive_errors' => $consecutive_errors,
		);
	}

	/**
	 * Store cache contents in their option, not autoloaded, and keep them for the rest of the request.
	 *
	 * The contents carry their fetch time, so update_option() returns false only when the write failed. A stale object-cache
	 * entry for a missing row fails every write, so a failed write flushes the entry and is retried once; a second failure
	 * is logged once per request and key.
	 *
	 * @since 11.2.0
	 *
	 * @param string              $key            Cache key.
	 * @param array<string,mixed> $cache_contents Contents to store.
	 * @return void
	 */
	public function write( string $key, array $cache_contents ): void {
		$this->contents[ get_current_blog_id() ][ $key ] = $cache_contents;

		$result = $this->legacy_proxy->call_function( 'update_option', $key, $cache_contents, 'no' );
		if ( false === $result ) {
			$this->legacy_proxy->call_function( 'wp_cache_delete', $key, 'options' );
			$result = $this->legacy_proxy->call_function( 'update_option', $key, $cache_contents, 'no' );
		}

		if ( false !== $result ) {
			$this->legacy_proxy->call_function( 'wp_cache_delete', $key, 'options' );
			return;
		}

		if ( ! isset( $this->logged_write_failures[ $key ] ) ) {
			$this->logged_write_failures[ $key ] = true;
			wc_get_logger()->warning(
				'WooPayments could not store a platform cache entry; the next request fetches it again.',
				array(
					'source' => WooPaymentsLogger::SOURCE,
					'key'    => $key,
					'bytes'  => strlen( maybe_serialize( $cache_contents ) ),
				)
			);
		}
	}

	/**
	 * Delete a cache entry, including its object-cache entry when the option row is already gone.
	 *
	 * The object-cache entry goes whatever delete_option() did: it returns before touching the object cache when the row
	 * is already gone, which left a persistent object cache serving stale dispute counts (client 11.1.0
	 * `class-database-cache.php:230-243`, its #9639 fix).
	 *
	 * @since 11.2.0
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function delete( string $key ): void {
		unset( $this->contents[ get_current_blog_id() ][ $key ] );
		$this->legacy_proxy->call_function( 'delete_option', $key );
		$this->legacy_proxy->call_function( 'wp_cache_delete', $key, 'options' );
	}

	/**
	 * Delete every cache entry.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function delete_all(): void {
		foreach ( self::ALL_KEYS as $key ) {
			$this->delete( $key );
		}
	}

	/**
	 * Delete the live and test dispute counts and the active disputes, so the next read fetches them.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function delete_dispute_caches(): void {
		$this->delete( self::DISPUTE_STATUS_COUNTS_KEY );
		$this->delete( self::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE );
		$this->delete( self::ACTIVE_DISPUTES_KEY );
	}

	/**
	 * Stop refreshing cache entries for the rest of the request, as while an Action Scheduler job runs.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function disable_refresh(): void {
		$this->refresh_disabled = true;
	}

	/**
	 * Tell whether cache contents are past their TTL.
	 *
	 * @param string              $key            Cache key.
	 * @param array<string,mixed> $cache_contents Stored contents.
	 * @return bool
	 */
	private function is_expired( string $key, array $cache_contents ): bool {
		$fetched = is_numeric( $cache_contents['fetched'] ?? null ) ? (int) $cache_contents['fetched'] : 0;
		$ttl     = $this->get_ttl( $key, $cache_contents );

		$now = (int) $this->legacy_proxy->call_function( 'time' );

		return $fetched + $ttl < $now;
	}

	/**
	 * Get the TTL of cache contents for the current request.
	 *
	 * @param string              $key            Cache key.
	 * @param array<string,mixed> $cache_contents Stored contents.
	 * @return int
	 */
	private function get_ttl( string $key, array $cache_contents ): int {
		$errored = ! empty( $cache_contents['errored'] );

		switch ( $key ) {
			case self::ACCOUNT_KEY:
				if ( is_admin() ) {
					$ttl = $errored
						? $this->get_errored_ttl( (int) ( $cache_contents['consecutive_errors'] ?? 0 ) )
						: self::ACCOUNT_ADMIN_TTL;
				} else {
					$ttl = self::ACCOUNT_FRONTEND_TTL;
				}
				break;
			case self::ONBOARDING_FIELDS_DATA_KEY:
				$ttl = $errored
					? $this->get_errored_ttl( (int) ( $cache_contents['consecutive_errors'] ?? 0 ) )
					: self::ONBOARDING_FIELDS_DATA_TTL;
				break;
			case self::RECOMMENDED_PAYMENT_METHODS_KEY:
				// Retry a failed answer on the error ladder, so one platform error does not hide the recommendations for a day.
				$ttl = $errored
					? $this->get_errored_ttl( (int) ( $cache_contents['consecutive_errors'] ?? 0 ) )
					: DAY_IN_SECONDS;
				break;
			case self::TRACKING_INFO_KEY:
				$ttl = $errored
					? $this->get_errored_ttl( (int) ( $cache_contents['consecutive_errors'] ?? 0 ) )
					: MONTH_IN_SECONDS;
				break;
			default:
				$ttl = DAY_IN_SECONDS;
				break;
		}

		/**
		 * Filters the WooPayments database cache TTL.
		 *
		 * @since 11.0.0
		 *
		 * @param int                 $ttl            Cache TTL in seconds.
		 * @param string              $key            Cache option key.
		 * @param array<string,mixed> $cache_contents Cache wrapper.
		 */
		return (int) apply_filters( 'wcpay_database_cache_ttl', $ttl, $key, $cache_contents );
	}

	/**
	 * Get the TTL after errors in a row: 2, 5, 10, then 15 minutes.
	 *
	 * @param int $consecutive_errors Errors in a row, the latest included.
	 * @return int
	 */
	private function get_errored_ttl( int $consecutive_errors ): int {
		$index = max( 0, min( count( self::ERRORED_TTL_LADDER ) - 1, $consecutive_errors - 1 ) );

		return self::ERRORED_TTL_LADDER[ $index ];
	}
}
