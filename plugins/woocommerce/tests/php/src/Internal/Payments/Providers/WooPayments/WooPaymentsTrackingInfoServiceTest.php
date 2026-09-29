<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;
use WC_Unit_Test_Case;

/**
 * Tests for the platform tracking info cache.
 *
 * Expectations come from plugin 11.1.0 `WC_Payments_Account::get_tracking_info()` and `Database_Cache`
 * (`get_or_add()`, `should_refresh_cache()`, `write_to_cache()`, the `TRACKING_INFO_KEY` TTL and the error ladder).
 */
class WooPaymentsTrackingInfoServiceTest extends WC_Unit_Test_Case {

	private const OPTION = 'wcpay_tracking_info_cache';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsTrackingInfoService
	 */
	private WooPaymentsTrackingInfoService $sut;

	/**
	 * Recording API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new class() extends WooPaymentsApiClient {

			/** @var bool */
			public bool $available = true;

			/** @var int */
			public int $calls = 0;

			/** @var array<string,mixed> */
			public array $response = array( 'hosting_provider' => 'fixture-host-3' );

			/** @var bool */
			public bool $fail = false;

			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Return the configured tracking info.
			 *
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When configured to fail.
			 */
			public function get_tracking_info(): array {
				++$this->calls;
				if ( $this->fail ) {
					throw new WooPaymentsApiException( 'Request failed.', 'wcpay_http_request_failed', 500 );
				}

				return $this->response;
			}
		};

		$this->sut = new WooPaymentsTrackingInfoService();
		$this->sut->init( $this->api_client );
		delete_option( self::OPTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( self::OPTION );
		remove_all_filters( 'wp_doing_ajax' );

		parent::tearDown();
	}

	/**
	 * @testdox Before the site is connected there is no tracking info and no platform request.
	 */
	public function test_returns_null_when_not_connected(): void {
		$this->api_client->available = false;
		$this->seed( array( 'hosting_provider' => 'cached-host' ), time(), false );

		$this->assertNull( $this->sut->get_tracking_info() );
		$this->assertSame( 0, $this->api_client->calls );
	}

	/**
	 * @testdox A missing cache is fetched from the platform and stored in the plugin's cache shape.
	 */
	public function test_fetches_and_stores_missing_cache(): void {
		$this->assertSame( array( 'hosting_provider' => 'fixture-host-3' ), $this->sut->get_tracking_info() );
		$this->assertSame( 1, $this->api_client->calls );

		$stored = get_option( self::OPTION );
		$this->assertSame( array( 'hosting_provider' => 'fixture-host-3' ), $stored['data'] );
		$this->assertFalse( $stored['errored'] );
		$this->assertSame( 0, $stored['consecutive_errors'] );
		$this->assertEqualsWithDelta( time(), $stored['fetched'], 5 );
		$this->assertTrue( $this->is_autoload_off() );
	}

	/**
	 * @testdox Successful tracking info is served from the cache for a month and refetched after.
	 *
	 * @testWith [2505600, 0, "cached-host"]
	 *           [2595600, 1, "fixture-host-3"]
	 *
	 * @param int    $age           Seconds since the cache was written (29 days, then 30 days plus an hour).
	 * @param int    $expected_call Expected platform requests.
	 * @param string $expected_host Expected hosting provider.
	 */
	public function test_success_ttl_is_a_month( int $age, int $expected_call, string $expected_host ): void {
		$this->seed( array( 'hosting_provider' => 'cached-host' ), time() - $age, false );

		$this->assertSame( array( 'hosting_provider' => $expected_host ), $this->sut->get_tracking_info() );
		$this->assertSame( $expected_call, $this->api_client->calls );
	}

	/**
	 * @testdox A failed refresh keeps the previous tracking info and counts the consecutive error.
	 */
	public function test_failed_refresh_keeps_old_data(): void {
		$this->api_client->fail = true;
		$this->seed( array( 'hosting_provider' => 'cached-host' ), time() - 3 * MONTH_IN_SECONDS, true, 2 );

		$this->assertSame( array( 'hosting_provider' => 'cached-host' ), $this->sut->get_tracking_info() );
		$this->assertSame( 1, $this->api_client->calls );

		$stored = get_option( self::OPTION );
		$this->assertSame( array( 'hosting_provider' => 'cached-host' ), $stored['data'] );
		$this->assertTrue( $stored['errored'] );
		$this->assertSame( 3, $stored['consecutive_errors'] );
	}

	/**
	 * @testdox An errored cache is retried on the plugin's 2/5/10/15 minute backoff ladder.
	 *
	 * @testWith [1, 180, 1]
	 *           [2, 180, 0]
	 *           [2, 360, 1]
	 *           [3, 540, 0]
	 *           [3, 660, 1]
	 *           [9, 840, 0]
	 *           [9, 960, 1]
	 *
	 * @param int $consecutive_errors Consecutive errors recorded in the cache.
	 * @param int $age                Seconds since the errored write.
	 * @param int $expected_calls     Expected platform requests.
	 */
	public function test_errored_cache_backoff( int $consecutive_errors, int $age, int $expected_calls ): void {
		$this->seed( null, time() - $age, true, $consecutive_errors );

		$this->sut->get_tracking_info();

		$this->assertSame( $expected_calls, $this->api_client->calls );
	}

	/**
	 * @testdox AJAX requests never refresh the cache and serve stale data as is.
	 */
	public function test_ajax_requests_do_not_refresh(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->seed( array( 'hosting_provider' => 'cached-host' ), time() - 3 * MONTH_IN_SECONDS, false );

		$this->assertSame( array( 'hosting_provider' => 'cached-host' ), $this->sut->get_tracking_info() );
		$this->assertSame( 0, $this->api_client->calls );
	}

	/**
	 * Seed the cache option.
	 *
	 * @param array<string,mixed>|null $data               Cached data.
	 * @param int                      $fetched            Fetch timestamp.
	 * @param bool                     $errored            Whether the write errored.
	 * @param int                      $consecutive_errors Consecutive error count.
	 */
	private function seed( ?array $data, int $fetched, bool $errored, int $consecutive_errors = 0 ): void {
		update_option(
			self::OPTION,
			array(
				'data'               => $data,
				'fetched'            => $fetched,
				'errored'            => $errored,
				'consecutive_errors' => $consecutive_errors,
			),
			false
		);
	}

	/**
	 * Tell whether the cache option is stored without autoload, like the plugin's cache.
	 *
	 * @return bool
	 */
	private function is_autoload_off(): bool {
		global $wpdb;

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		return in_array( $autoload, array( 'no', 'off' ), true );
	}
}
