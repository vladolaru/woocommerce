<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDatabaseCache;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyDatabaseCache class.
 */
class MultiCurrencyDatabaseCacheTest extends WC_Unit_Test_Case {

	/**
	 * Cache key used by these tests.
	 *
	 * @var string
	 */
	private string $cache_key = MultiCurrencyCacheInterface::CURRENCIES_KEY;

	/**
	 * Clean up test cache state before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( $this->cache_key );
		wp_cache_delete( $this->cache_key, 'options' );
	}

	/**
	 * Clean up test cache state after each test.
	 */
	public function tear_down(): void {
		delete_option( $this->cache_key );
		wp_cache_delete( $this->cache_key, 'options' );

		parent::tear_down();
	}

	/**
	 * @testdox Should generate and store cache data.
	 */
	public function test_generates_and_stores_cache_data(): void {
		$cache = new MultiCurrencyDatabaseCache();

		$refreshed = false;
		$value     = $cache->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			false,
			$refreshed
		);

		$this->assertSame(
			array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			$value
		);
		$this->assertTrue( $refreshed );
		$this->assertSame( $value, $cache->get( $this->cache_key ) );
	}

	/**
	 * @testdox Should reuse valid cached data without regenerating.
	 */
	public function test_reuses_cached_data_without_regenerating(): void {
		$cache = new MultiCurrencyDatabaseCache();

		$first_refresh = false;
		$cache->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			false,
			$first_refresh
		);

		$second_refresh = false;
		$second_value   = $cache->get_or_add(
			$this->cache_key,
			static function () {
				throw new \RuntimeException( 'Generator should not run for valid cached data.' );
			},
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			false,
			$second_refresh
		);

		$this->assertTrue( $first_refresh );
		$this->assertFalse( $second_refresh );
		$this->assertSame(
			array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			$second_value
		);
	}

	/**
	 * @testdox Should not refresh expired FX rates inside Action Scheduler jobs.
	 */
	public function test_does_not_refresh_inside_action_scheduler_jobs(): void {
		global $wp_actions;

		$had_action_count = is_array( $wp_actions ) && array_key_exists( 'action_scheduler_before_execute', $wp_actions );
		$previous_count   = $had_action_count ? $wp_actions['action_scheduler_before_execute'] : null;

		$seed_refreshed = false;
		( new MultiCurrencyDatabaseCache() )->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			false,
			$seed_refreshed
		);
		$this->assertTrue( $seed_refreshed );

		$contents            = get_option( $this->cache_key );
		$contents['fetched'] = time() - YEAR_IN_SECONDS;
		update_option( $this->cache_key, $contents );
		wp_cache_delete( $this->cache_key, 'options' );

		do_action( 'action_scheduler_before_execute' );

		try {
			$generator_calls = 0;
			$stale_refresh   = false;
			$value           = ( new MultiCurrencyDatabaseCache() )->get_or_add(
				$this->cache_key,
				static function () use ( &$generator_calls ): array {
					++$generator_calls;

					return array(
						'currencies' => array( 'eur' => 9.9 ),
						'updated'    => 456,
					);
				},
				static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
				false,
				$stale_refresh
			);

			$this->assertSame( 0, $generator_calls, 'The FX generator must not run inside Action Scheduler jobs.' );
			$this->assertFalse( $stale_refresh );
			$this->assertSame(
				array(
					'currencies' => array( 'eur' => 1.2 ),
					'updated'    => 123,
				),
				$value
			);
		} finally {
			if ( $had_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the pre-test action count.
				$wp_actions['action_scheduler_before_execute'] = $previous_count;
			} else {
				unset( $wp_actions['action_scheduler_before_execute'] );
			}
		}
	}

	/**
	 * @testdox Should shorten the currencies TTL on admin-originated REST requests like the reference client.
	 */
	public function test_currencies_ttl_uses_admin_api_branch(): void {
		$seed_refreshed = false;
		( new MultiCurrencyDatabaseCache() )->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			false,
			$seed_refreshed
		);

		// Age the payload past the 3-hour admin TTL but inside the 12-hour frontend TTL.
		$contents            = get_option( $this->cache_key );
		$contents['fetched'] = time() - 4 * HOUR_IN_SECONDS;
		update_option( $this->cache_key, $contents );
		wp_cache_delete( $this->cache_key, 'options' );

		$admin_api_context = $this->createMock( \Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext::class );
		$admin_api_context->method( 'is_admin_api_request' )->willReturn( true );

		$generator_calls = 0;
		$generator       = static function () use ( &$generator_calls ): array {
			++$generator_calls;

			return array(
				'currencies' => array( 'eur' => 1.3 ),
				'updated'    => 456,
			);
		};
		$validator       = static fn( $data ) => isset( $data['currencies'], $data['updated'] );

		$frontend_refresh = false;
		( new MultiCurrencyDatabaseCache() )->get_or_add( $this->cache_key, $generator, $validator, false, $frontend_refresh );

		$this->assertSame( 0, $generator_calls, 'A 4-hour-old payload is fresh on the frontend (12-hour TTL).' );

		$admin_api_refresh = false;
		( new MultiCurrencyDatabaseCache( $admin_api_context ) )->get_or_add( $this->cache_key, $generator, $validator, false, $admin_api_refresh );

		$this->assertSame( 1, $generator_calls, 'A 4-hour-old payload is stale on admin-originated REST requests (3-hour TTL).' );
		$this->assertTrue( $admin_api_refresh );
	}

	/**
	 * @testdox Should honor the wcpay_database_cache_ttl filter with the reference signature.
	 */
	public function test_currencies_ttl_honors_database_cache_ttl_filter(): void {
		$seed_refreshed = false;
		( new MultiCurrencyDatabaseCache() )->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			false,
			$seed_refreshed
		);

		$contents            = get_option( $this->cache_key );
		$contents['fetched'] = time() - HOUR_IN_SECONDS;
		update_option( $this->cache_key, $contents );
		wp_cache_delete( $this->cache_key, 'options' );

		$filter_args = array();
		$ttl_filter  = static function ( $ttl, $key, $cache_contents ) use ( &$filter_args ) {
			$filter_args = array( $ttl, $key, $cache_contents );

			return MINUTE_IN_SECONDS;
		};
		add_filter( 'wcpay_database_cache_ttl', $ttl_filter, 10, 3 );

		try {
			$generator_calls = 0;
			$refreshed       = false;
			( new MultiCurrencyDatabaseCache() )->get_or_add(
				$this->cache_key,
				static function () use ( &$generator_calls ): array {
					++$generator_calls;

					return array(
						'currencies' => array( 'eur' => 1.3 ),
						'updated'    => 456,
					);
				},
				static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
				false,
				$refreshed
			);

			$this->assertSame( 1, $generator_calls, 'A one-minute filtered TTL must expire an hour-old payload.' );
			$this->assertSame( 12 * HOUR_IN_SECONDS, $filter_args[0] );
			$this->assertSame( $this->cache_key, $filter_args[1] );
			$this->assertIsArray( $filter_args[2] );
			$this->assertArrayHasKey( 'fetched', $filter_args[2] );
		} finally {
			remove_filter( 'wcpay_database_cache_ttl', $ttl_filter, 10 );
		}
	}

	/**
	 * @testdox Should return previous valid data when regeneration fails.
	 */
	public function test_returns_previous_value_when_regeneration_fails(): void {
		$cache = new MultiCurrencyDatabaseCache();

		$cache->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] )
		);

		$refreshed = true;
		$value     = $cache->get_or_add(
			$this->cache_key,
			static fn() => null,
			static fn( $data ) => isset( $data['currencies'], $data['updated'] ),
			true,
			$refreshed
		);
		$stored    = get_option( $this->cache_key );

		$this->assertFalse( $refreshed );
		$this->assertSame(
			array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			$value
		);
		$this->assertIsArray( $stored );
		$this->assertTrue( $stored['errored'] );
		$this->assertSame( 1, $stored['consecutive_errors'] );
	}

	/**
	 * @testdox Should delete option and in-memory cache data.
	 */
	public function test_delete_removes_option_and_memory_cache(): void {
		$cache = new MultiCurrencyDatabaseCache();

		$cache->get_or_add(
			$this->cache_key,
			static fn() => array(
				'currencies' => array( 'eur' => 1.2 ),
				'updated'    => 123,
			),
			static fn( $data ) => isset( $data['currencies'], $data['updated'] )
		);

		$cache->delete( $this->cache_key );

		$this->assertFalse( get_option( $this->cache_key ) );
		$this->assertNull( $cache->get( $this->cache_key ) );
	}
}
