<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTierRecheck;
use WC_Unit_Test_Case;

/**
 * Pins the account reads scheduled when the setup tier drops from connected or active.
 *
 * Any write that lowers the tier starts four WP-Cron reads afresh. A read can only raise the tier: an account the platform
 * finds is written as a normal read writes it, and no account or a failed read writes nothing. The rows follow the
 * invariants page of this change, numbered as there.
 */
class WooPaymentsSetupTierRecheckTest extends WC_Unit_Test_Case {

	private const EXTENSION_PLUGIN = 'woocommerce-payments/woocommerce-payments.php';

	private const ACCOUNT_CACHE_OPTION = 'wcpay_account_data';

	/** Seconds from the drop to each of the four reads. */
	private const READ_OFFSETS = array( 15 * MINUTE_IN_SECONDS, 75 * MINUTE_IN_SECONDS, 435 * MINUTE_IN_SECONDS, 1875 * MINUTE_IN_SECONDS );

	/**
	 * The platform client double the account service reads the account through.
	 *
	 * @var Client|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $client;

	/**
	 * The account service the container hands out.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * The setup tier store.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $tier;

	/**
	 * The recheck handler the container hands out.
	 *
	 * @var WooPaymentsSetupTierRecheck
	 */
	private WooPaymentsSetupTierRecheck $sut;

	/**
	 * Set up a store with the built-in WooPayments on and a platform client that answers as connected.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );

		$this->client = $this->createMock( Client::class );
		$this->client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $this->client );

		$container = wc_get_container();
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->tier            = $container->get( WooPaymentsSetupTier::class );
		$this->account_service = $container->get( WooPaymentsAccountService::class );
		$this->sut             = $container->get( WooPaymentsSetupTierRecheck::class );
		$this->tier->invalidate();
	}

	/**
	 * Drop the container replacement and the memoized owner and tier the tests changed.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
		} finally {
			remove_all_actions( WooPaymentsSetupTierRecheck::ACTION );
			remove_all_filters( 'wp_doing_cron' );
			Constants::clear_single_constant( 'WP_CLI' );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			$this->tier->invalidate();
			parent::tearDown();
		}
	}

	/**
	 * Each tier a store can drop from, with the gateway setting that gives it.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function provide_tiers_that_drop(): array {
		return array(
			'active'    => array( 'yes', WooPaymentsSetupTier::ACTIVE ),
			'connected' => array( 'no', WooPaymentsSetupTier::CONNECTED ),
		);
	}

	/**
	 * @testdox A drop from $prior_tier starts four reads, the first about 15 minutes out, a second drop starts them afresh, and a read that finds the account puts the tier back and ends them, even when the reset was killed mid-call.
	 * @dataProvider provide_tiers_that_drop
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the store holds before the drop.
	 */
	public function test_a_drop_from_connected_or_active_starts_the_reads_that_heal_the_tier( string $gateway_enabled, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->assertSame( array(), $this->pending_reads(), 'Nothing is scheduled while the tier holds.' );

		// The account cache overwrite a reset starts with. A process killed after it runs nothing more.
		$this->account_service->overwrite_cache_with_no_account();

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assert_ladder_started_at( time() );

		$this->account_service->overwrite_cache_with_no_account();
		$this->assert_ladder_started_at( time() );

		$this->client->method( 'get_account' )->willReturn( $this->account() );
		$this->fire_next_read();

		$this->assert_tier_is( $prior_tier );
		$this->assertSame( array(), $this->pending_reads(), 'A read that found the account ends the reads.' );
	}

	/**
	 * @testdox Invariant 6: a new drop while read 2 is pending starts the ladder afresh: exactly four reads, the first about 15 minutes out.
	 */
	public function test_a_new_drop_starts_the_ladder_afresh(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 ) );
		$this->fire_next_read();
		$this->fire_next_read();
		$this->assertCount( 2, $this->pending_reads(), 'Reads 2 and 3 are pending.' );
		// The first drop was hours ago, so its reads are not where a fresh ladder puts them.
		$this->move_pending_reads_earlier( 4 * HOUR_IN_SECONDS + 15 * MINUTE_IN_SECONDS );

		// Another reader finds the account, then the tier drops again.
		$this->account_service->cache_account_data( $this->account() );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();

		$this->assert_ladder_started_at( time() );
	}

	/**
	 * @testdox A drop while the WooPayments extension owns payments schedules nothing.
	 */
	public function test_a_drop_while_the_extension_owns_payments_schedules_nothing(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		update_option( 'active_plugins', array( self::EXTENSION_PLUGIN ) );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$this->account_service->overwrite_cache_with_no_account();

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( array(), $this->pending_reads() );
	}

	/**
	 * @testdox A store that never held a connected or active tier schedules nothing, on its first account read that finds no account and on every one after it.
	 */
	public function test_a_store_that_was_never_set_up_schedules_nothing(): void {
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		$this->tier->invalidate();
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'No account.', 'wcpay_account_not_found', 404 ) );

		$this->assertSame( array(), $this->account_service->refresh_account_data() );
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( array(), $this->pending_reads(), 'The first read, disabled to available.' );

		$this->assertSame( array(), $this->account_service->refresh_account_data() );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( array(), $this->pending_reads(), 'Reads that find the tier already available.' );
	}

	/**
	 * Request types the setup tier lists the recheck for, and the ones it does not.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_request_types(): array {
		return array(
			'cron'  => array( 'cron', true ),
			'cli'   => array( 'cli', true ),
			'front' => array( 'front', false ),
			'admin' => array( 'admin', false ),
			'ajax'  => array( 'ajax', false ),
			'rest'  => array( 'rest', false ),
		);
	}

	/**
	 * @testdox With a read pending on an available store, a $request_type request loads the recheck: $loads; with no read pending, or on a store never set up, none does and no handler is registered.
	 * @dataProvider provide_request_types
	 *
	 * @param string $request_type Request type.
	 * @param bool   $loads        Whether the recheck is loaded with a read pending.
	 */
	public function test_the_recheck_loads_only_on_cron_and_cli_while_a_read_is_pending( string $request_type, bool $loads ): void {
		// A store never set up, with reads nobody could have scheduled: nothing loads.
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		$this->tier->invalidate();
		$this->schedule_a_read();
		$this->assertNotContains( WooPaymentsSetupTierRecheck::class, $this->classes_in( $request_type ), 'A store that was never set up loads nothing.' );
		wp_clear_scheduled_hook( WooPaymentsSetupTierRecheck::ACTION );

		$this->tier->write_tier( WooPaymentsSetupTier::AVAILABLE );
		$this->assertNotContains( WooPaymentsSetupTierRecheck::class, $this->classes_in( $request_type ), 'No read pending.' );
		$this->assertFalse( $this->handler_registers_in( $request_type ), 'No read pending: no handler.' );

		$this->schedule_a_read();
		$this->assertSame( $loads, in_array( WooPaymentsSetupTierRecheck::class, $this->classes_in( $request_type ), true ) );
		$this->assertSame( $loads, $this->handler_registers_in( $request_type ), 'The handler registers where the class is listed.' );
	}

	/**
	 * Answers a read can get that the handler must not persist, and whether they end the reads.
	 *
	 * @return array<string,array{\Closure,bool}>
	 */
	public static function provide_answers_the_handler_does_not_write(): array {
		return array(
			'(a) no account'          => array( static fn() => new ApiException( 'No account.', 'wcpay_account_not_found', 404 ), true ),
			'(b) platform error'      => array( static fn() => new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 500 ), false ),
			'(c) onboarding disabled' => array( static fn() => new ApiException( 'Onboarding is disabled.', 'wcpay_on_boarding_disabled', 400 ), true ),
		);
	}

	/**
	 * @testdox Invariant 1: a read that gets $_dataName leaves the account cache row and the tier byte for byte as they were.
	 * @dataProvider provide_answers_the_handler_does_not_write
	 *
	 * @param \Closure $answer    Builds the exception the platform call throws.
	 * @param bool     $answered  Whether the answer ends the reads.
	 */
	public function test_a_read_never_writes_an_empty_or_errored_answer( \Closure $answer, bool $answered ): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		// A row a write would change in any shape: an older fetch time.
		update_option(
			self::ACCOUNT_CACHE_OPTION,
			array(
				'data'               => array(),
				'fetched'            => time() - 600,
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		$cache_before = $this->raw_option( self::ACCOUNT_CACHE_OPTION );
		$tier_before  = $this->raw_option( WooPaymentsSetupTier::OPTION_NAME );
		$this->client->method( 'get_account' )->willThrowException( $answer() );

		$this->fire_next_read();

		$this->assertSame( $cache_before, $this->raw_option( self::ACCOUNT_CACHE_OPTION ), 'The account cache row is unchanged.' );
		$this->assertSame( $tier_before, $this->raw_option( WooPaymentsSetupTier::OPTION_NAME ), 'The tier is unchanged.' );
		$this->assertCount( $answered ? 0 : 3, $this->pending_reads(), $answered ? 'An answer ends the reads.' : 'A failed read keeps the later reads.' );
		$this->assertSame( array(), array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ), 'Nothing is logged.' );
	}

	/**
	 * @testdox Invariant 6: when the last read fails, nothing is left pending and one line is logged; the reads before it log nothing.
	 */
	public function test_the_last_failed_read_logs_one_line(): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 ) );
		$recheck_lines = static fn(): array => array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );

		for ( $read = 0; $read < 3; $read++ ) {
			$this->fire_next_read();
			$this->assertSame( array(), $recheck_lines(), "Read $read logs nothing." );
		}
		$this->fire_next_read();

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( array(), $this->pending_reads(), 'The reads end.' );
		$lines = $recheck_lines();
		$this->assertCount( 1, $lines, 'One line is logged.' );
		$this->assertSame( 'warning', $lines[0][0] );
		$this->assertStringContainsString( 'could not be confirmed', $lines[0][1] );
	}

	/**
	 * @testdox Invariant 6: a drop whose reads WordPress refuses to schedule logs one line.
	 */
	public function test_a_failed_schedule_write_is_logged_once(): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		add_filter( 'pre_schedule_event', '__return_false' );

		try {
			$this->account_service->overwrite_cache_with_no_account();
		} finally {
			remove_filter( 'pre_schedule_event', '__return_false' );
		}

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( array(), $this->pending_reads() );
		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );
		$this->assertCount( 1, $lines, 'One line is logged.' );
		$this->assertStringContainsString( 'could not update its WP-Cron events', $lines[0][1] );
	}

	/**
	 * @testdox Invariant 3: a read that finds the account raises the tier as a normal read does, to $prior_tier, fires the refresh hook once and ends the reads.
	 * @dataProvider provide_tiers_that_drop
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the account and the gateway setting give.
	 */
	public function test_a_read_that_finds_the_account_raises_the_tier( string $gateway_enabled, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->account_service->overwrite_cache_with_no_account();
		$this->client->method( 'get_account' )->willReturn( $this->account() );
		$refreshed = 0;
		$count     = static function () use ( &$refreshed ) {
			++$refreshed;
		};
		add_action( 'woocommerce_payments_account_refreshed', $count );

		try {
			$this->fire_next_read();
		} finally {
			remove_action( 'woocommerce_payments_account_refreshed', $count );
		}

		$this->assert_tier_is( $prior_tier );
		$this->assertSame( 1, $refreshed, 'The refresh hook fires once.' );
		$this->assertSame( array(), $this->pending_reads(), 'The reads end.' );
		$this->assertSame( $this->account(), get_option( self::ACCOUNT_CACHE_OPTION )['data'] );
	}

	/**
	 * @testdox Invariant 3: a read that finds the account but cannot store it counts as failed: the tier stays available and the later reads stay pending.
	 */
	public function test_a_failed_cache_write_counts_as_a_failed_read(): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->client->method( 'get_account' )->willReturn( $this->account() );
		$refuse = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . self::ACCOUNT_CACHE_OPTION, $refuse, 10, 2 );
		// The cache logs a failed write once per request and key; each test is its own request.
		$logged = new \ReflectionProperty( WooPaymentsDatabaseCache::class, 'logged_write_failures' );
		$logged->setAccessible( true );
		$logged->setValue( wc_get_container()->get( WooPaymentsDatabaseCache::class ), array() );

		try {
			$this->fire_next_read();
		} finally {
			remove_filter( 'pre_update_option_' . self::ACCOUNT_CACHE_OPTION, $refuse, 10 );
		}

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertCount( 3, $this->pending_reads(), 'The later reads stay pending.' );
		$warnings = array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && false !== strpos( $line[1], 'did not store a platform cache entry' ) );
		$this->assertCount( 1, $warnings, 'The cache logs the failed write.' );
	}

	/**
	 * The orders onboarding and the read can finish in.
	 *
	 * @return array<string,array{string}>
	 */
	public static function provide_onboarding_race_orders(): array {
		return array(
			'onboarding first, the read finds no account'  => array( 'no account' ),
			'onboarding first, the read finds the account' => array( 'account' ),
			'the read first, then onboarding'              => array( 'read first' ),
		);
	}

	/**
	 * @testdox Invariant 4: onboarding that completes while the read is out leaves the tier high, $_dataName.
	 * @dataProvider provide_onboarding_race_orders
	 *
	 * @param string $order Which finishes first, and what the read answers.
	 */
	public function test_onboarding_that_completes_during_the_read_keeps_the_tier_high( string $order ): void {
		$this->store_has_account( 'no', WooPaymentsSetupTier::CONNECTED );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$partial  = array(
			'account_id'           => 'acct_123',
			'live_publishable_key' => 'pk_live_123',
		);
		$settings = array(
			'enabled'                        => 'yes',
			'upe_enabled_payment_method_ids' => array( 'card' ),
		);
		// Onboarding in another request: it turns the gateway on, stores the partial account and raises the tier.
		$onboarding_row = array(
			'data'               => $partial,
			'fetched'            => 0,
			'errored'            => false,
			'consecutive_errors' => 0,
		);
		$onboarding     = function () use ( $settings, $onboarding_row ) {
			$this->write_option_in_another_request( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );
			$this->write_option_in_another_request( self::ACCOUNT_CACHE_OPTION, $onboarding_row );
			$this->write_option_in_another_request( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		};
		$this->client->method( 'get_account' )->willReturnCallback(
			function () use ( $order, $onboarding ) {
				if ( 'read first' === $order ) {
					throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
				}
				$onboarding();
				if ( 'no account' === $order ) {
					throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
				}
				return $this->account();
			}
		);

		$this->fire_next_read();
		if ( 'read first' === $order ) {
			update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );
			$this->account_service->cache_account_data_until_refreshed( $partial );
		}

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->raw_option( WooPaymentsSetupTier::OPTION_NAME ), 'The tier ends high.' );
		$row = maybe_unserialize( $this->raw_option( self::ACCOUNT_CACHE_OPTION ) );
		$this->assertSame( 'account' === $order ? $this->account() : $partial, $row['data'], 'The cache holds the newest account.' );
	}

	/**
	 * @testdox Invariant 4, known outcome: a deletion that lands while the read is out is undone by the read's stale answer; after a reset the gateway is off, so the tier ends connected, with nothing scheduled.
	 */
	public function test_a_deletion_during_the_read_ends_connected(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->client->method( 'get_account' )->willReturnCallback(
			function () {
				// The merchant's reset succeeds while the read is out.
				$this->account_service->overwrite_cache_with_no_account();
				$this->account_service->cleanup_after_account_reset();
				return $this->account();
			}
		);

		$this->fire_next_read();

		$this->assert_tier_is( WooPaymentsSetupTier::CONNECTED );
		$this->assertSame( array(), $this->pending_reads(), 'Nothing is scheduled; the foreground readers on connected correct it.' );
	}

	/**
	 * @testdox Invariant 2: a deleted account drops the tier in the foreground read and starts the reads; the read confirms the low tier, writes nothing, ends the reads and logs nothing.
	 */
	public function test_a_real_deletion_is_confirmed_without_a_write_a_reschedule_or_a_log_line(): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'No account.', 'wcpay_account_not_found', 404 ) );

		wc_get_container()->get( WooPaymentsAccountEventHandler::class )->process( 'account.deleted' );

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assert_ladder_started_at( time() );

		$tier_writes = 0;
		add_filter(
			'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME,
			static function ( $value ) use ( &$tier_writes ) {
				++$tier_writes;
				return $value;
			}
		);
		$options_before = $this->autoloaded_options_without_cron();
		$lines_before   = count( $logger->lines );

		$this->fire_next_read();

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( 0, $tier_writes, 'The tier is not written again.' );
		$this->assertSame( $options_before, $this->autoloaded_options_without_cron(), 'No setting, flag or notice changes.' );
		$this->assertSame( array(), $this->pending_reads(), 'The reads end.' );
		$this->assertCount( $lines_before, $logger->lines, 'Nothing is logged.' );
		$cache = get_option( self::ACCOUNT_CACHE_OPTION );
		$this->assertFalse( $cache['errored'], 'The foreground read was answered.' );
		$this->assertSame( array(), $cache['data'] );
	}

	/**
	 * @testdox A disconnect from WordPress.com clears the account cache and does not drop the tier, so it schedules nothing.
	 */
	public function test_a_disconnect_does_not_drop_the_tier(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$disconnected = $this->createMock( Client::class );
		$disconnected->method( 'is_available' )->willReturn( false );
		wc_get_container()->replace( Client::class, $disconnected );

		$this->account_service->clear_cache();
		$this->assertSame( array(), $this->account_service->get_cached_account_data() );

		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->assertSame( array(), $this->pending_reads() );
	}

	/**
	 * @testdox When the WooPayments extension becomes the owner between the drop and the read, the read asks the platform nothing and ends the reads.
	 */
	public function test_the_recheck_reads_nothing_once_the_extension_owns_payments(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertCount( 4, $this->pending_reads() );

		update_option( 'active_plugins', array( self::EXTENSION_PLUGIN ) );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->client->expects( $this->never() )->method( 'get_account' );

		$this->fire_next_read();

		$this->assertSame( array(), $this->pending_reads() );
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * @testdox When another read put the tier back before the event, the read asks the platform nothing and ends the reads.
	 */
	public function test_the_recheck_reads_nothing_once_the_tier_is_back(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->account_service->cache_account_data( $this->account() );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->client->expects( $this->never() )->method( 'get_account' );

		$this->fire_next_read();

		$this->assertSame( array(), $this->pending_reads() );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
	}

	/**
	 * Request types and stored tiers, with the pending-read lookups a bootstrap may make.
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function provide_lookup_counts(): array {
		$rows = array();
		foreach ( array( 'front', 'admin', 'ajax', 'rest' ) as $request_type ) {
			$rows[ "$request_type on available" ] = array( $request_type, WooPaymentsSetupTier::AVAILABLE, 0 );
		}
		$rows['cron on available'] = array( 'cron', WooPaymentsSetupTier::AVAILABLE, 1 );
		$rows['cli on available']  = array( 'cli', WooPaymentsSetupTier::AVAILABLE, 1 );
		foreach ( array( WooPaymentsSetupTier::DISABLED, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $tier ) {
			$rows[ "cron on $tier" ] = array( 'cron', $tier, 0 );
		}

		return $rows;
	}

	/**
	 * @testdox Invariant 7: with reads pending, a $request_type bootstrap on a $tier store looks for them $lookups times, the cron list a request builds on demand included.
	 * @dataProvider provide_lookup_counts
	 *
	 * @param string $request_type Request type.
	 * @param string $tier         Stored tier.
	 * @param int    $lookups      Lookups of the pending reads.
	 */
	public function test_a_request_looks_for_pending_reads_at_most_once( string $request_type, string $tier, int $lookups ): void {
		$this->schedule_a_read();
		if ( WooPaymentsSetupTier::DISABLED === $tier ) {
			delete_option( WooPaymentsSetupTier::OPTION_NAME );
		} else {
			$this->tier->write_tier( $tier );
		}
		$this->tier->invalidate();
		$count = 0;
		$spy   = static function ( $pre, $hook ) use ( &$count ) {
			if ( WooPaymentsSetupTierRecheck::ACTION === $hook ) {
				++$count;
			}
			return $pre;
		};
		add_filter( 'pre_get_scheduled_event', $spy, 10, 2 );

		try {
			$this->handler_registers_in( $request_type );
		} finally {
			remove_filter( 'pre_get_scheduled_event', $spy, 10 );
		}

		$this->assertSame( $lookups, $count );
	}

	/**
	 * Put a connected account in the store, with the gateway on or off, and check the tier it gives.
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $expected_tier   The tier the account write must give: active or connected.
	 */
	private function store_has_account( string $gateway_enabled, string $expected_tier ): void {
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'                        => $gateway_enabled,
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cache_account_data( $this->account() );
		$this->assert_tier_is( $expected_tier );
	}

	/**
	 * A connected live account, as the platform answers it.
	 *
	 * @return array<string,mixed>
	 */
	private function account(): array {
		return array(
			'account_id'        => 'acct_123',
			'is_live'           => true,
			'payments_enabled'  => true,
			'details_submitted' => true,
		);
	}

	/**
	 * Run the next recheck event as WP-Cron does: the event leaves the schedule, then its handler runs with its arguments.
	 */
	private function fire_next_read(): void {
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ WooPaymentsSetupTierRecheck::ACTION ] ?? array() as $event ) {
				wp_unschedule_event( $timestamp, WooPaymentsSetupTierRecheck::ACTION, $event['args'] );
				$this->sut->recheck( ...$event['args'] );
				return;
			}
		}
		$this->fail( 'No recheck event is pending.' );
	}

	/**
	 * Schedule one read as the ladder does, as the scheduler would see it.
	 */
	private function schedule_a_read(): void {
		wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, WooPaymentsSetupTierRecheck::ACTION );
	}

	/**
	 * Move every pending recheck event earlier, as if the drop that scheduled it happened that long before.
	 *
	 * @param int $seconds Seconds to move them by.
	 */
	private function move_pending_reads_earlier( int $seconds ): void {
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ WooPaymentsSetupTierRecheck::ACTION ] ?? array() as $event ) {
				wp_unschedule_event( $timestamp, WooPaymentsSetupTierRecheck::ACTION, $event['args'] );
				wp_schedule_single_event( $timestamp - $seconds, WooPaymentsSetupTierRecheck::ACTION, $event['args'] );
			}
		}
	}

	/**
	 * Get the times of the pending recheck events, earliest first.
	 *
	 * @return array<int,int>
	 */
	private function pending_reads(): array {
		$pending = array();
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ WooPaymentsSetupTierRecheck::ACTION ] ?? array() as $event ) {
				$pending[] = (int) $timestamp;
			}
		}
		sort( $pending );

		return $pending;
	}

	/**
	 * Assert the four reads of a ladder started at a time.
	 *
	 * @param int $started When the drop started the ladder.
	 */
	private function assert_ladder_started_at( int $started ): void {
		$pending = $this->pending_reads();
		$this->assertCount( 4, $pending, 'Four reads are pending.' );
		foreach ( self::READ_OFFSETS as $i => $offset ) {
			$this->assertEqualsWithDelta( $started + $offset, $pending[ $i ], 5, "Read $i." );
		}
	}

	/**
	 * Get an option's stored bytes, read from the database.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private function raw_option( string $name ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Write an option's value in the database only, as another request would, leaving this request's caches stale.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	private function write_option_in_another_request( string $name, $value ): void {
		global $wpdb;

		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_name' => $name ) );
	}

	/**
	 * Get the autoloaded options, without the cron schedule that a fired event changes.
	 *
	 * @return array<string,mixed>
	 */
	private function autoloaded_options_without_cron(): array {
		$options = wp_load_alloptions( true );
		unset( $options['cron'] );

		return $options;
	}

	/**
	 * Run a request type's bootstrap, in that request's context, and tell whether it registered the recheck handler.
	 *
	 * @param string $request_type Request type.
	 * @return bool
	 */
	private function handler_registers_in( string $request_type ): bool {
		remove_all_actions( WooPaymentsSetupTierRecheck::ACTION );
		$this->in_request(
			$request_type,
			function () use ( $request_type ) {
				( new PaymentsBootstrap(
					static fn( $container, string $type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $type ),
					static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
				) )->register( wc_get_container(), $request_type );
			}
		);

		return false !== has_action( WooPaymentsSetupTierRecheck::ACTION );
	}

	/**
	 * Get the classes the tier lists for a request type, in that request's context.
	 *
	 * @param string $request_type Request type.
	 * @return array<int,class-string>
	 */
	private function classes_in( string $request_type ): array {
		$classes = array();
		$this->in_request(
			$request_type,
			function () use ( $request_type, &$classes ) {
				$classes = $this->tier->get_classes_for_request( $request_type );
			}
		);

		return $classes;
	}

	/**
	 * Run a callback with the signals of a request type set: WP_CLI, wp_doing_cron, wp_doing_ajax or the admin screen.
	 *
	 * @param string   $request_type Request type.
	 * @param callable $callback     Callback.
	 */
	private function in_request( string $request_type, callable $callback ): void {
		if ( 'cli' === $request_type ) {
			Constants::set_constant( 'WP_CLI', true );
		}
		if ( 'ajax' === $request_type ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}
		if ( 'cron' === $request_type ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		}
		if ( 'admin' === $request_type ) {
			set_current_screen( 'edit-page' );
		}

		try {
			$callback();
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_doing_cron', '__return_true' );
			Constants::clear_single_constant( 'WP_CLI' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Assert the stored tier, read past the request memo.
	 *
	 * @param string $expected The expected tier.
	 */
	private function assert_tier_is( string $expected ): void {
		$this->tier->invalidate();
		wp_cache_delete( WooPaymentsSetupTier::OPTION_NAME, 'options' );
		$this->assertSame( $expected, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'Stored tier.' );
		$this->assertSame( $expected, $this->tier->get_stored_tier(), 'Tier the store reads.' );
	}
}
