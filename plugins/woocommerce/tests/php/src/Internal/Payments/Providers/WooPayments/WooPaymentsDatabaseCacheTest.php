<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDatabaseCache class.
 */
class WooPaymentsDatabaseCacheTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsDatabaseCache();
		$this->sut->init( new LegacyProxy() );
	}

	/**
	 * @testdox Should keep $key entries for their TTL in $screen requests: $description.
	 * @dataProvider provide_ttls
	 *
	 * @param string   $key                Cache key.
	 * @param string   $screen             Screen, so is_admin() matches the request.
	 * @param string   $description        What the row checks.
	 * @param bool     $errored            Whether the entry is errored.
	 * @param int|null $consecutive_errors Stored error count, or null when the entry has none.
	 * @param int      $age                Seconds since the fetch.
	 * @param bool     $expected_refresh   Whether the entry should be refreshed.
	 */
	public function test_keeps_entries_for_their_ttl( string $key, string $screen, string $description, bool $errored, ?int $consecutive_errors, int $age, bool $expected_refresh ): void {
		unset( $description );
		set_current_screen( $screen );
		// Shapes as client 11.1.0 includes/class-database-cache.php:364-382 writes them, before and after its error count.
		$contents = array(
			'data'    => array( 'cached' => true ),
			'fetched' => time() - $age,
			'errored' => $errored,
		);
		if ( null !== $consecutive_errors ) {
			$contents['consecutive_errors'] = $consecutive_errors;
		}

		$this->assertSame( $expected_refresh, $this->sut->should_refresh( $key, $contents, 'is_array', false ) );
	}

	/**
	 * Provide TTL rows: key, screen, case, errored, error count, age, expected refresh.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool,4:int|null,5:int,6:bool}>
	 */
	public function provide_ttls(): array {
		$account = WooPaymentsDatabaseCache::ACCOUNT_KEY;
		$fields  = WooPaymentsDatabaseCache::ONBOARDING_FIELDS_DATA_KEY;
		$counts  = WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY;
		$track   = WooPaymentsDatabaseCache::TRACKING_INFO_KEY;
		$methods = WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY;

		return array(
			'account admin, before 2 hours'        => array( $account, 'dashboard', 'before 2 hours', false, 0, 2 * HOUR_IN_SECONDS - 60, false ),
			'account admin, after 2 hours'         => array( $account, 'dashboard', 'after 2 hours', false, 0, 2 * HOUR_IN_SECONDS + 60, true ),
			'account front, before a day'          => array( $account, 'front', 'before a day', false, 0, DAY_IN_SECONDS - 60, false ),
			'account front, after a day'           => array( $account, 'front', 'after a day', false, 0, DAY_IN_SECONDS + 60, true ),
			'account front, errored before a day'  => array( $account, 'front', 'errored, no backoff', true, 1, HOUR_IN_SECONDS, false ),
			'account admin, 1 error, 1 minute'     => array( $account, 'dashboard', '1 error, 1 minute', true, 1, 60, false ),
			'account admin, 1 error, 3 minutes'    => array( $account, 'dashboard', '1 error, 3 minutes', true, 1, 180, true ),
			'account admin, 2 errors, 4 minutes'   => array( $account, 'dashboard', '2 errors, 4 minutes', true, 2, 240, false ),
			'account admin, 2 errors, 6 minutes'   => array( $account, 'dashboard', '2 errors, 6 minutes', true, 2, 360, true ),
			'account admin, 3 errors, 9 minutes'   => array( $account, 'dashboard', '3 errors, 9 minutes', true, 3, 540, false ),
			'account admin, 3 errors, 11 minutes'  => array( $account, 'dashboard', '3 errors, 11 minutes', true, 3, 660, true ),
			'account admin, 9 errors, 14 minutes'  => array( $account, 'dashboard', '9 errors, 14 minutes', true, 9, 840, false ),
			'account admin, 9 errors, 16 minutes'  => array( $account, 'dashboard', '9 errors, 16 minutes', true, 9, 960, true ),
			'account admin, no error count, 3 min' => array( $account, 'dashboard', 'no error count, 3 minutes', true, null, 180, true ),
			'fields, before a week'                => array( $fields, 'front', 'before a week', false, 0, WEEK_IN_SECONDS - 60, false ),
			'fields, after a week'                 => array( $fields, 'front', 'after a week', false, 0, WEEK_IN_SECONDS + 60, true ),
			'fields, 1 error, 3 minutes'           => array( $fields, 'front', '1 error, 3 minutes', true, 1, 180, true ),
			'recommended, before a day'            => array( $methods, 'dashboard', 'before a day', false, 0, DAY_IN_SECONDS - 60, false ),
			'recommended, after a day'             => array( $methods, 'dashboard', 'after a day', false, 0, DAY_IN_SECONDS + 60, true ),
			'recommended, 1 error, 1 minute'       => array( $methods, 'dashboard', '1 error, 1 minute', true, 1, 60, false ),
			'recommended, 1 error, 3 minutes'      => array( $methods, 'dashboard', '1 error, 3 minutes', true, 1, 180, true ),
			'recommended, 4 errors, 14 minutes'    => array( $methods, 'dashboard', '4 errors, 14 minutes', true, 4, 840, false ),
			'recommended, 4 errors, 16 minutes'    => array( $methods, 'dashboard', '4 errors, 16 minutes', true, 4, 960, true ),
			'tracking, before a month'             => array( $track, 'front', 'before a month', false, 0, MONTH_IN_SECONDS - 60, false ),
			'tracking, after a month'              => array( $track, 'front', 'after a month', false, 0, MONTH_IN_SECONDS + 60, true ),
			'tracking, 2 errors, 4 minutes'        => array( $track, 'front', '2 errors, 4 minutes', true, 2, 240, false ),
			'tracking, 2 errors, 6 minutes'        => array( $track, 'front', '2 errors, 6 minutes', true, 2, 360, true ),
			'tracking, no error count, 1 minute'   => array( $track, 'front', 'no error count, 1 minute', true, null, 60, false ),
			'tracking, no error count, 3 min'      => array( $track, 'front', 'no error count, 3 minutes', true, null, 180, true ),
			'other keys, errored before a day'     => array( $counts, 'dashboard', 'errored, no backoff', true, 1, DAY_IN_SECONDS - 60, false ),
			'other keys, errored after a day'      => array( $counts, 'dashboard', 'errored, after a day', true, 1, DAY_IN_SECONDS + 60, true ),
			'other keys, after a day'              => array( $counts, 'dashboard', 'after a day', false, 0, DAY_IN_SECONDS + 60, true ),
		);
	}

	/**
	 * @testdox Should let the wcpay_database_cache_ttl filter change the TTL, with the key and the stored contents.
	 */
	public function test_ttl_filter_receives_the_key_and_contents(): void {
		$seen     = array();
		$contents = array(
			'data'               => array( 'cached' => true ),
			'fetched'            => time() - HOUR_IN_SECONDS,
			'errored'            => false,
			'consecutive_errors' => 0,
		);
		add_filter(
			'wcpay_database_cache_ttl',
			static function ( $ttl, $key, $cache_contents ) use ( &$seen ) {
				$seen = array( $ttl, $key, $cache_contents );
				return 60;
			},
			10,
			3
		);

		$this->assertTrue( $this->sut->should_refresh( WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY, $contents, 'is_array', false ) );
		$this->assertSame( array( DAY_IN_SECONDS, WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY, $contents ), $seen );
	}

	/**
	 * @testdox Should refresh $description.
	 * @dataProvider provide_refresh_rules
	 *
	 * @param string $description What the row checks.
	 * @param mixed  $contents Stored contents.
	 * @param bool   $force    Whether the refresh is forced.
	 * @param string $context  Request context: plain, ajax or action_scheduler.
	 * @param bool   $expected Whether the entry should be refreshed.
	 */
	public function test_refresh_rules( string $description, $contents, bool $force, string $context, bool $expected ): void {
		unset( $description );
		if ( 'ajax' === $context ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		} elseif ( 'action_scheduler' === $context ) {
			$this->sut->disable_refresh();
		}

		$this->assertSame( $expected, $this->sut->should_refresh( WooPaymentsDatabaseCache::ACCOUNT_KEY, $contents, 'is_array', $force ) );
	}

	/**
	 * Provide refresh rule rows: case, contents, force, context, expected.
	 *
	 * @return array<string,array{0:string,1:mixed,2:bool,3:string,4:bool}>
	 */
	public function provide_refresh_rules(): array {
		$fresh = array(
			'data'               => array( 'cached' => true ),
			'fetched'            => time(),
			'errored'            => false,
			'consecutive_errors' => 0,
		);

		return array(
			'nothing stored'           => array( 'when nothing is stored', false, false, 'plain', true ),
			'empty wrapper'            => array( 'an empty wrapper', array(), false, 'plain', true ),
			'no fetch time'            => array(
				'a wrapper without a fetch time',
				array(
					'data'    => array(),
					'errored' => false,
				),
				false,
				'plain',
				true,
			),
			'no errored flag'          => array(
				'a wrapper without an errored flag',
				array(
					'data'    => array(),
					'fetched' => time(),
				),
				false,
				'plain',
				true,
			),
			'invalid data'             => array( 'invalid data that did not error', array_merge( $fresh, array( 'data' => 'scalar' ) ), false, 'plain', true ),
			'invalid errored data'     => array(
				'no fresh errored entry, whatever its data',
				array_merge(
					$fresh,
					array(
						'data'               => null,
						'errored'            => true,
						'consecutive_errors' => 1,
					)
				),
				false,
				'plain',
				false,
			),
			'fresh'                    => array( 'no fresh valid entry', $fresh, false, 'plain', false ),
			'forced'                   => array( 'a fresh entry when forced', $fresh, true, 'plain', true ),
			'forced during an AS job'  => array( 'a forced entry during an Action Scheduler job', $fresh, true, 'action_scheduler', true ),
			'missing during ajax'      => array( 'nothing during AJAX requests', false, false, 'ajax', false ),
			'missing during an AS job' => array( 'nothing during Action Scheduler jobs', false, false, 'action_scheduler', false ),
		);
	}

	/**
	 * @testdox Should keep the last valid data and count the error when the generator $description.
	 * @testWith ["returns null"]
	 *           ["returns false"]
	 *           ["throws"]
	 *
	 * @param string $description How the generator fails.
	 */
	public function test_get_or_add_keeps_the_last_valid_data_on_failure( string $description ): void {
		update_option(
			WooPaymentsDatabaseCache::TRACKING_INFO_KEY,
			array(
				'data'               => array( 'host' => 'cached' ),
				'fetched'            => time() - 2 * MONTH_IN_SECONDS,
				'errored'            => true,
				'consecutive_errors' => 2,
			),
			false
		);
		$generator = static function () use ( $description ) {
			if ( 'throws' === $description ) {
				throw new RuntimeException( 'Platform failure.' );
			}
			return 'returns null' === $description ? null : false;
		};

		$this->assertSame( array( 'host' => 'cached' ), $this->sut->get_or_add( WooPaymentsDatabaseCache::TRACKING_INFO_KEY, $generator, 'is_array' ) );
		$stored = get_option( WooPaymentsDatabaseCache::TRACKING_INFO_KEY );
		$this->assertSame( array( 'host' => 'cached' ), $stored['data'] );
		$this->assertTrue( $stored['errored'] );
		$this->assertSame( 3, $stored['consecutive_errors'] );
	}

	/**
	 * @testdox Should store fresh data not autoloaded, reset the error count and drop the object-cache entry.
	 */
	public function test_get_or_add_stores_fresh_data(): void {
		global $wpdb;

		$this->assertSame( array( 'host' => 'fresh' ), $this->sut->get_or_add( WooPaymentsDatabaseCache::TRACKING_INFO_KEY, static fn() => array( 'host' => 'fresh' ), 'is_array' ) );
		$this->assertFalse( wp_cache_get( WooPaymentsDatabaseCache::TRACKING_INFO_KEY, 'options' ) );

		$stored = get_option( WooPaymentsDatabaseCache::TRACKING_INFO_KEY );
		$this->assertSame( array( 'host' => 'fresh' ), $stored['data'] );
		$this->assertFalse( $stored['errored'] );
		$this->assertSame( 0, $stored['consecutive_errors'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WooPaymentsDatabaseCache::TRACKING_INFO_KEY ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * A stale object-cache entry for a missing row makes update_option() return false (WordPress Trac #38931), and so does
	 * an option that already holds the same contents (client 11.1.0 includes/class-database-cache.php:387-393). Fails when
	 * the retry is dropped (the retry row), when it runs more than once or falls back to add_option() (every row), when the
	 * option is not read back after a second false (the double-false rows), or when the first failure, every failed write
	 * or an option already holding the contents logs, or when only the data is compared and a row whose fetch time did not
	 * change goes unlogged (the double-false rows).
	 *
	 * @testdox Should flush the object-cache entry and retry a failed write once, never adding the option: $_dataName.
	 *
	 * @testWith ["the first write succeeds", [true], 1, "other", ["update_option", "wp_cache_delete"], 0]
	 *           ["the retry succeeds", [false, true], 1, "other", ["update_option", "wp_cache_delete", "update_option", "wp_cache_delete"], 0]
	 *           ["both writes fail, twice in a request", [false, false, false, false], 2, "other", ["update_option", "wp_cache_delete", "update_option", "get_option", "update_option", "wp_cache_delete", "update_option", "get_option"], 1]
	 *           ["both return false while the option holds these contents", [false, false], 1, "same", ["update_option", "wp_cache_delete", "update_option", "get_option"], 0]
	 *           ["both return false while the option holds the same data fetched earlier", [false, false], 1, "older", ["update_option", "wp_cache_delete", "update_option", "get_option"], 1]
	 *
	 * @param string   $name           Case label.
	 * @param bool[]   $results        update_option() results, in call order.
	 * @param int      $writes         Calls to write().
	 * @param string   $stored         What the option holds when read back: the same contents, the same data fetched earlier, or other contents.
	 * @param string[] $expected_calls Expected option and cache calls, in order.
	 * @param int      $expected_logs  Expected warning lines.
	 */
	public function test_write_retries_a_failed_update_once( string $name, array $results, int $writes, string $stored, array $expected_calls, int $expected_logs ): void {
		unset( $name );
		$calls    = array();
		$contents = array(
			'data'    => array( 'account_id' => 'acct_native_test' ),
			'fetched' => time(),
			'errored' => false,
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'update_option'   => static function ( $key, $value ) use ( &$calls, &$results, $contents ) {
					$calls[] = 'update_option';
					if ( WooPaymentsDatabaseCache::ACCOUNT_KEY !== $key || $contents !== $value ) {
						return null;
					}

					return array_shift( $results );
				},
				'get_option'      => static function ( $key ) use ( &$calls, $contents, $stored ) {
					$calls[] = WooPaymentsDatabaseCache::ACCOUNT_KEY === $key ? 'get_option' : 'get_option of another key';

					if ( 'same' === $stored ) {
						return $contents;
					}

					return 'older' === $stored ? array_merge( $contents, array( 'fetched' => $contents['fetched'] - 60 ) ) : array( 'data' => array( 'account_id' => 'acct_other' ) );
				},
				'add_option'      => static function () use ( &$calls ) {
					$calls[] = 'add_option';

					return true;
				},
				'wp_cache_delete' => static function ( $key, $group ) use ( &$calls ) {
					$calls[] = WooPaymentsDatabaseCache::ACCOUNT_KEY === $key && 'options' === $group ? 'wp_cache_delete' : 'wp_cache_delete of another key';

					return true;
				},
			)
		);
		$logger = RecordingWcLogger::install();
		$sut    = new WooPaymentsDatabaseCache();
		$sut->init( wc_get_container()->get( LegacyProxy::class ) );

		for ( $i = 0; $i < $writes; $i++ ) {
			$sut->write( WooPaymentsDatabaseCache::ACCOUNT_KEY, $contents );
		}

		$warnings = array_values( array_intersect_key( $logger->contexts, array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ) ) );
		$this->assertSame( $expected_calls, $calls );
		$this->assertCount( $expected_logs, $warnings );
		foreach ( $warnings as $context ) {
			$this->assertSame( WooPaymentsDatabaseCache::ACCOUNT_KEY, $context['key'] );
			$this->assertSame( strlen( maybe_serialize( $contents ) ), $context['bytes'] );
		}
		$this->assertSame( $contents, $sut->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY ), 'The request keeps the fetched contents.' );
	}

	/**
	 * @testdox Should read an entry from the database once per request and blog, then serve it from memory.
	 */
	public function test_reads_an_entry_once_per_request(): void {
		$calls     = 0;
		$generator = static function () use ( &$calls ): array {
			++$calls;
			return array( 'host' => 'fresh' );
		};

		$this->sut->get_or_add( WooPaymentsDatabaseCache::TRACKING_INFO_KEY, $generator, 'is_array' );
		update_option( WooPaymentsDatabaseCache::TRACKING_INFO_KEY, 'written elsewhere' );

		$this->assertSame( array( 'host' => 'fresh' ), $this->sut->get_or_add( WooPaymentsDatabaseCache::TRACKING_INFO_KEY, $generator, 'is_array' ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * @testdox Should hand a throwing read to the error handler once and treat it as nothing stored, or let it propagate without one.
	 */
	public function test_get_contents_read_errors(): void {
		$throw = static function () {
			throw new RuntimeException( 'Filter failed.' );
		};
		add_filter( 'pre_option_' . WooPaymentsDatabaseCache::ACCOUNT_KEY, $throw );
		add_filter( 'pre_option_' . WooPaymentsDatabaseCache::ONBOARDING_FIELDS_DATA_KEY, $throw );
		$errors  = 0;
		$handler = static function () use ( &$errors ): void {
			++$errors;
		};

		$this->assertFalse( $this->sut->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY, $handler ) );
		$this->assertFalse( $this->sut->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY, $handler ) );
		$this->assertSame( 1, $errors );

		$this->expectException( RuntimeException::class );
		$this->sut->get_contents( WooPaymentsDatabaseCache::ONBOARDING_FIELDS_DATA_KEY );
	}

	/**
	 * @testdox A dispute cache entry left in the object cache after its option row is gone is cleared too.
	 *
	 * Client 11.1.0 `class-database-cache.php:230-243` deletes the object-cache entry whatever delete_option() did
	 * (its #9639 regression fix): delete_option() returns before touching the cache when the row is already gone.
	 *
	 * @testWith ["wcpay_dispute_status_counts_cache"]
	 *           ["wcpay_test_dispute_status_counts_cache"]
	 *           ["wcpay_active_dispute_cache"]
	 *
	 * @param string $key Dispute cache option.
	 */
	public function test_delete_dispute_caches_clears_a_stale_object_cache_entry_without_its_option_row( string $key ): void {
		global $wpdb;
		update_option( $key, array( 'needs_response' => 3 ), false );
		// The row disappears while a persistent object cache still holds the old value.
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) );
		wp_cache_set( $key, array( 'needs_response' => 3 ), 'options' );

		$this->sut->delete_dispute_caches();

		$this->assertFalse( wp_cache_get( $key, 'options' ) );
	}

	/**
	 * @testdox Should delete every entry and its object-cache entry, and forget what it read.
	 */
	public function test_delete_all_deletes_every_entry(): void {
		foreach ( WooPaymentsDatabaseCache::ALL_KEYS as $key ) {
			update_option( $key, array( 'stale' => true ), false );
		}
		$this->sut->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		foreach ( WooPaymentsDatabaseCache::ALL_KEYS as $key ) {
			wp_cache_set( $key, array( 'stale' => true ), 'options' );
		}

		$this->sut->delete_all();

		foreach ( WooPaymentsDatabaseCache::ALL_KEYS as $key ) {
			$this->assertFalse( get_option( $key, false ), "{$key} should be deleted." );
			$this->assertFalse( wp_cache_get( $key, 'options' ), "{$key} should leave the object cache." );
		}
		$this->assertFalse( $this->sut->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY ) );
	}
}
