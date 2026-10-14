<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAdminMenuBadgeService class.
 */
class WooPaymentsAdminMenuBadgeServiceTest extends WC_Unit_Test_Case {

	/**
	 * Database cache of the service the last helper created.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'wcpay_dispute_status_counts_cache' );
		delete_option( 'wcpay_test_dispute_status_counts_cache' );
		delete_option( 'wcpay_authorization_summary_cache' );
		delete_option( 'wcpay_test_authorization_summary_cache' );
		remove_all_filters( 'wcpay_database_cache_ttl' );

		parent::tearDown();
	}

	/**
	 * @testdox Should sum only actionable dispute statuses for the menu badge.
	 */
	public function test_get_disputes_awaiting_response_count_sums_only_actionable_statuses(): void {
		$api_client = $this->create_api_client(
			array(
				'needs_response'         => 2,
				'warning_needs_response' => 1,
				'under_review'           => 7,
			)
		);
		$sut        = $this->create_service( $api_client );

		$this->assertSame( 3, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 1, $api_client->dispute_status_count_calls );
		$this->assertSame(
			array(
				'data'               => array(
					'needs_response'         => 2,
					'warning_needs_response' => 1,
					'under_review'           => 7,
				),
				'fetched'            => get_option( 'wcpay_dispute_status_counts_cache' )['fetched'],
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			get_option( 'wcpay_dispute_status_counts_cache' )
		);
	}

	/**
	 * @testdox Should use stale valid dispute cache when refreshing fails.
	 */
	public function test_get_disputes_awaiting_response_count_uses_stale_valid_cache_when_fetch_fails(): void {
		update_option(
			'wcpay_dispute_status_counts_cache',
			array(
				'data'    => array( 'needs_response' => 4 ),
				'fetched' => time() - DAY_IN_SECONDS - 5,
				'errored' => false,
			),
			false
		);

		$api_client                    = $this->create_api_client();
		$api_client->throw_on_disputes = true;
		$sut                           = $this->create_service( $api_client );

		$this->assertSame( 4, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 1, $api_client->dispute_status_count_calls );
		$this->assertSame( array( 'needs_response' => 4 ), get_option( 'wcpay_dispute_status_counts_cache' )['data'] );
		$this->assertTrue( get_option( 'wcpay_dispute_status_counts_cache' )['errored'] );
	}

	/**
	 * @testdox Should return zero when dispute count refresh fails without valid stale data.
	 */
	public function test_get_disputes_awaiting_response_count_returns_zero_without_valid_cache_on_fetch_failure(): void {
		$api_client                    = $this->create_api_client();
		$api_client->throw_on_disputes = true;
		$sut                           = $this->create_service( $api_client );

		$this->assertSame( 0, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 1, $api_client->dispute_status_count_calls );
		$this->assertTrue( get_option( 'wcpay_dispute_status_counts_cache' )['errored'] );

		$this->assertSame( 0, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 1, $api_client->dispute_status_count_calls, 'A second read in the same request should not refetch after a failed refresh.' );
	}

	/**
	 * @testdox Should log a failed badge cache refresh with its cache key, without the failure text.
	 */
	public function test_logs_a_failed_badge_cache_refresh(): void {
		add_filter( 'wcpay_dev_mode', '__return_true' );
		$logger                        = RecordingWcLogger::install();
		$api_client                    = $this->create_api_client();
		$api_client->throw_on_disputes = true;

		$this->create_service( $api_client )->get_disputes_awaiting_response_count();

		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'Failed to refresh a WooPayments admin cache.', $errors[0][1] );
		$context = $logger->contexts[ array_search( $errors[0], $logger->lines, true ) ];
		$this->assertSame( 'wcpay_dispute_status_counts_cache', $context['cache_key'] );
		$this->assertStringNotContainsString( 'Failed dispute count fetch', wp_json_encode( $logger->contexts ) );
	}

	/**
	 * @testdox Should keep an errored badge cache for the full TTL, like the client database cache, so admin pages do not refetch.
	 */
	public function test_get_disputes_awaiting_response_count_keeps_cold_failure_for_the_full_ttl(): void {
		update_option(
			'wcpay_dispute_status_counts_cache',
			array(
				'data'    => null,
				'fetched' => time() - HOUR_IN_SECONDS,
				'errored' => true,
			),
			false
		);

		$api_client = $this->create_api_client( array( 'needs_response' => 2 ) );
		$sut        = $this->create_service( $api_client );

		$this->assertSame( 0, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 0, $api_client->dispute_status_count_calls, 'Client 11.1.0 Database_Cache::get_ttl() keeps badge keys for 24 hours, errored or not.' );
	}

	/**
	 * @testdox Should retry an errored badge cache once the TTL expires.
	 */
	public function test_get_disputes_awaiting_response_count_retries_cold_failure_after_ttl(): void {
		update_option(
			'wcpay_dispute_status_counts_cache',
			array(
				'data'    => null,
				'fetched' => time() - DAY_IN_SECONDS - 1,
				'errored' => true,
			),
			false
		);

		$api_client = $this->create_api_client( array( 'needs_response' => 2 ) );
		$sut        = $this->create_service( $api_client );

		$this->assertSame( 2, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 1, $api_client->dispute_status_count_calls );
		$this->assertFalse( get_option( 'wcpay_dispute_status_counts_cache' )['errored'] );
	}

	/**
	 * @testdox Should count errors in a row on the $key cache and reset the count on success, as client 11.1.0 `class-database-cache.php:364-382`.
	 *
	 * @testWith ["wcpay_dispute_status_counts_cache"]
	 *           ["wcpay_authorization_summary_cache"]
	 *
	 * @param string $key Badge cache option.
	 */
	public function test_counts_consecutive_errors_on_the_badge_caches( string $key ): void {
		$api_client                    = $this->create_api_client( array( 'needs_response' => 2 ), array( 'count' => 2 ) );
		$api_client->throw_on_disputes = true;
		$api_client->throw_on_summary  = true;
		$read                          = function () use ( $key, $api_client ): void {
			$sut = $this->create_service( $api_client, false, 'yes' );
			if ( 'wcpay_dispute_status_counts_cache' === $key ) {
				$sut->get_disputes_awaiting_response_count();
			} else {
				$sut->get_uncaptured_transactions_count();
			}
		};

		$read();
		$this->assertSame( 1, get_option( $key )['consecutive_errors'] );

		$stored            = get_option( $key );
		$stored['fetched'] = time() - DAY_IN_SECONDS - 1;
		update_option( $key, $stored, false );
		$read();
		$this->assertSame( 2, get_option( $key )['consecutive_errors'] );

		$stored            = get_option( $key );
		$stored['fetched'] = time() - DAY_IN_SECONDS - 1;
		update_option( $key, $stored, false );
		$api_client->throw_on_disputes = false;
		$api_client->throw_on_summary  = false;
		$read();
		$this->assertSame( 0, get_option( $key )['consecutive_errors'] );
		$this->assertFalse( get_option( $key )['errored'] );
	}

	/**
	 * @testdox Should not refresh an expired badge cache while an Action Scheduler job runs, as client 11.1.0 `class-database-cache.php:295-301`.
	 */
	public function test_does_not_refresh_the_badge_caches_while_refresh_is_disabled(): void {
		update_option(
			'wcpay_dispute_status_counts_cache',
			array(
				'data'    => array( 'needs_response' => 4 ),
				'fetched' => time() - DAY_IN_SECONDS - 5,
				'errored' => false,
			),
			false
		);
		$api_client = $this->create_api_client( array( 'needs_response' => 2 ) );
		$sut        = $this->create_service( $api_client );
		$this->database_cache->disable_refresh();

		$this->assertSame( 4, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 0, $api_client->dispute_status_count_calls );
	}

	/**
	 * @testdox Should fetch the badge counts once per request even when storing them fails, as client 11.1.0 `class-database-cache.php:101-109,340-353`.
	 */
	public function test_fetches_the_badge_counts_once_per_request_when_the_write_fails(): void {
		add_filter(
			'pre_update_option_wcpay_dispute_status_counts_cache',
			static function ( $value, $old_value ) {
				unset( $value );
				return $old_value;
			},
			10,
			2
		);
		$api_client = $this->create_api_client( array( 'needs_response' => 2 ) );
		$sut        = $this->create_service( $api_client );

		$this->assertSame( 2, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 2, $sut->get_disputes_awaiting_response_count() );
		$this->assertSame( 1, $api_client->dispute_status_count_calls );
	}

	/**
	 * @testdox Should drop the object-cache entry after storing badge counts, so other processes read the new row, as client 11.1.0 `class-database-cache.php:387-398`.
	 */
	public function test_drops_the_object_cache_entry_after_storing_badge_counts(): void {
		$this->create_service( $this->create_api_client( array( 'needs_response' => 2 ) ) )->get_disputes_awaiting_response_count();

		$this->assertFalse( wp_cache_get( 'wcpay_dispute_status_counts_cache', 'options' ) );
		$this->assertSame( array( 'needs_response' => 2 ), get_option( 'wcpay_dispute_status_counts_cache' )['data'] );
	}

	/**
	 * @testdox Should delete both authorization summary cache options on invalidation.
	 */
	public function test_invalidate_authorization_summary_caches_deletes_both_options(): void {
		update_option(
			'wcpay_authorization_summary_cache',
			array(
				'data'    => array( 'count' => 3 ),
				'fetched' => time(),
				'errored' => false,
			),
			false
		);
		update_option(
			'wcpay_test_authorization_summary_cache',
			array(
				'data'    => array( 'count' => 5 ),
				'fetched' => time(),
				'errored' => false,
			),
			false
		);

		$this->create_service( $this->create_api_client( array(), array( 'count' => 3 ) ) )->invalidate_authorization_summary_caches();

		$this->assertFalse( get_option( 'wcpay_authorization_summary_cache', false ) );
		$this->assertFalse( get_option( 'wcpay_test_authorization_summary_cache', false ) );
	}

	/**
	 * @testdox Invalidation purges a stale object-cache entry even when the option row is already gone.
	 */
	public function test_invalidate_authorization_summary_caches_purges_stale_object_cache(): void {
		// delete_option() returns before touching caches when the row does not
		// exist, so a stale external-object-cache entry would survive it.
		delete_option( 'wcpay_authorization_summary_cache' );
		wp_cache_set(
			'wcpay_authorization_summary_cache',
			array(
				'data'    => array( 'count' => 9 ),
				'fetched' => time(),
				'errored' => false,
			),
			'options'
		);

		$this->create_service( $this->create_api_client( array(), array( 'count' => 3 ) ) )->invalidate_authorization_summary_caches();

		$this->assertFalse( wp_cache_get( 'wcpay_authorization_summary_cache', 'options' ) );
	}

	/**
	 * @testdox Should fetch the authorization summary again after invalidation in the same request ($description).
	 * @testWith ["live mode", false, "wcpay_authorization_summary_cache"]
	 *           ["test mode", true, "wcpay_test_authorization_summary_cache"]
	 *
	 * @param string $description Mode the row covers.
	 * @param bool   $test_mode   Whether test mode is enabled.
	 * @param string $option      Cache option of that mode.
	 */
	public function test_reads_the_authorization_summary_again_after_invalidation_in_the_same_request( string $description, bool $test_mode, string $option ): void {
		unset( $description );
		// A reduced authorization summary: the client reads only its `count` (includes/admin/class-wc-payments-admin.php:1473-1493).
		$api_client = $this->create_api_client( array(), array( 'count' => 4 ) );
		$sut        = $this->create_service( $api_client, $test_mode, 'yes' );
		$this->assertSame( 4, $sut->get_uncaptured_transactions_count() );

		$sut->invalidate_authorization_summary_caches();

		$this->assertSame( 4, $sut->get_uncaptured_transactions_count() );
		$this->assertSame( 2, $api_client->authorization_summary_calls, 'Invalidation should clear the in-request copy too, so the next read fetches again.' );
		$this->assertSame( array( 'count' => 4 ), get_option( $option )['data'] );
	}

	/**
	 * @testdox Should return zero when manual capture is disabled.
	 */
	public function test_get_uncaptured_transactions_count_returns_zero_when_manual_capture_is_disabled(): void {
		$api_client = $this->create_api_client( array(), array( 'count' => 5 ) );
		$sut        = $this->create_service( $api_client, false, 'no' );

		$this->assertSame( 0, $sut->get_uncaptured_transactions_count() );
		$this->assertSame( 0, $api_client->authorization_summary_calls );
	}

	/**
	 * @testdox Should read authorization summary when manual capture is enabled.
	 */
	public function test_get_uncaptured_transactions_count_reads_authorization_summary_when_manual_capture_is_enabled(): void {
		$api_client = $this->create_api_client( array(), array( 'count' => 6 ) );
		$sut        = $this->create_service( $api_client, false, 'yes' );

		$this->assertSame( 6, $sut->get_uncaptured_transactions_count() );
		$this->assertSame( 1, $api_client->authorization_summary_calls );
	}

	/**
	 * @testdox Should use test-mode cache key for authorization summary.
	 */
	public function test_get_uncaptured_transactions_count_uses_test_mode_cache_key(): void {
		$api_client = $this->create_api_client( array(), array( 'count' => 2 ) );
		$sut        = $this->create_service( $api_client, true, 'yes' );

		$this->assertSame( 2, $sut->get_uncaptured_transactions_count() );
		$this->assertFalse( get_option( 'wcpay_authorization_summary_cache', false ) );
		$this->assertSame( array( 'count' => 2 ), get_option( 'wcpay_test_authorization_summary_cache' )['data'] );
	}

	/**
	 * Create the service under test.
	 *
	 * @param WooPaymentsApiClient $api_client       API client fake.
	 * @param bool                 $test_mode        Whether test mode is enabled.
	 * @param string               $manual_capture   Manual capture setting.
	 * @return WooPaymentsAdminMenuBadgeService
	 */
	private function create_service( WooPaymentsApiClient $api_client, bool $test_mode = false, string $manual_capture = 'no' ): WooPaymentsAdminMenuBadgeService {
		$account_service      = $this->create_account_service( $test_mode, $manual_capture );
		$this->database_cache = new WooPaymentsDatabaseCache();
		$this->database_cache->init( new LegacyProxy() );
		$sut = new WooPaymentsAdminMenuBadgeService();
		$sut->init( $account_service, $api_client, $this->database_cache );

		return $sut;
	}

	/**
	 * Create an account service mock.
	 *
	 * @param bool   $test_mode      Whether test mode is enabled.
	 * @param string $manual_capture Manual capture setting.
	 * @return WooPaymentsAccountService&MockObject
	 */
	private function create_account_service( bool $test_mode, string $manual_capture ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_gateway_setting' )->willReturnMap(
			array(
				array( 'manual_capture', 'no', $manual_capture ),
			)
		);

		return $account_service;
	}

	/**
	 * Create an API client fake.
	 *
	 * @param array<string,mixed> $dispute_status_counts Dispute status-count payload.
	 * @param array<string,mixed> $authorization_summary Authorization summary payload.
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client( array $dispute_status_counts = array(), array $authorization_summary = array() ): WooPaymentsApiClient {
		return new class( $dispute_status_counts, $authorization_summary ) extends WooPaymentsApiClient {
			/**
			 * Dispute status-count payload.
			 *
			 * @var array<string,mixed>
			 */
			private array $dispute_status_counts;

			/**
			 * Authorization summary payload.
			 *
			 * @var array<string,mixed>
			 */
			private array $authorization_summary;

			/**
			 * Dispute status-count call count.
			 *
			 * @var int
			 */
			public int $dispute_status_count_calls = 0;

			/**
			 * Authorization summary call count.
			 *
			 * @var int
			 */
			public int $authorization_summary_calls = 0;

			/**
			 * Whether dispute calls should fail.
			 *
			 * @var bool
			 */
			public bool $throw_on_disputes = false;

			/**
			 * Whether authorization summary calls should fail.
			 *
			 * @var bool
			 */
			public bool $throw_on_summary = false;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $dispute_status_counts Dispute status-count payload.
			 * @param array<string,mixed> $authorization_summary Authorization summary payload.
			 */
			public function __construct( array $dispute_status_counts, array $authorization_summary ) {
				$this->dispute_status_counts = $dispute_status_counts;
				$this->authorization_summary = $authorization_summary;
			}

			/**
			 * Return fake dispute status-count data.
			 *
			 * @return array<string,mixed>
			 */
			public function get_dispute_status_counts(): array {
				++$this->dispute_status_count_calls;

				if ( $this->throw_on_disputes ) {
					throw new RuntimeException( 'Failed dispute count fetch.' );
				}

				return $this->dispute_status_counts;
			}

			/**
			 * Return fake authorization summary data.
			 *
			 * @param array<string,mixed> $query Query params.
			 * @return array<string,mixed>
			 */
			public function get_authorizations_summary( array $query = array() ): array {
				unset( $query );
				++$this->authorization_summary_calls;

				if ( $this->throw_on_summary ) {
					throw new RuntimeException( 'Failed authorization summary fetch.' );
				}

				return $this->authorization_summary;
			}
		};
	}
}
