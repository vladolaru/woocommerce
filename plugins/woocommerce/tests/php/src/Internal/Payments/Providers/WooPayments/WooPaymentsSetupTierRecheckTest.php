<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTierRecheck;
use WC_Unit_Test_Case;

/**
 * Pins the one confirming account read scheduled when the setup tier drops from connected or active.
 *
 * Any write that lowers the tier gets one WP-Cron event about 15 minutes out. Its handler refetches the account, which
 * rewrites the tier, and retries a read the platform did not answer on a short ladder that ends in a log line.
 */
class WooPaymentsSetupTierRecheckTest extends WC_Unit_Test_Case {

	private const EXTENSION_PLUGIN = 'woocommerce-payments/woocommerce-payments.php';

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
	 * @testdox A drop from $prior_tier schedules exactly one recheck about 15 minutes out, a second drop schedules none, and the recheck puts the tier back from the platform's answer, even when the reset was killed mid-call.
	 * @dataProvider provide_tiers_that_drop
	 *
	 * @param string $gateway_enabled The gateway's enabled setting.
	 * @param string $prior_tier      The tier the store holds before the drop.
	 */
	public function test_a_drop_from_connected_or_active_schedules_one_recheck_that_heals_the_tier( string $gateway_enabled, string $prior_tier ): void {
		$this->store_has_account( $gateway_enabled, $prior_tier );
		$this->assertSame( array(), $this->pending_reads(), 'Nothing is scheduled while the tier holds.' );

		// The account cache overwrite a reset starts with. A process killed after it runs nothing more.
		$this->account_service->overwrite_cache_with_no_account();

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$pending = $this->pending_reads();
		$this->assertSame( array( 0 ), array_keys( $pending ), 'One recheck, the first read.' );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, $pending[0], 5 );

		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( array( 0 ), array_keys( $this->pending_reads() ), 'A second drop schedules no second recheck.' );
		$this->assertSame( 1, $this->count_events(), 'One event in the cron array.' );

		$this->client->method( 'get_account' )->willReturn( $this->account() );
		$this->fire_event( 0 );

		$this->assert_tier_is( $prior_tier );
		$this->assertSame( array(), $this->pending_reads(), 'A read the platform answered schedules nothing further.' );
	}

	/**
	 * @testdox A drop while a retry of an errored read is still pending schedules no first read beside it.
	 */
	public function test_a_drop_while_a_retry_is_pending_schedules_nothing_more(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 ) );
		$this->fire_event( 0 );
		$this->assertSame( array( 1 ), array_keys( $this->pending_reads() ), 'The errored first read left its retry.' );

		$this->account_service->restore_setup_tier( $this->account() );
		$this->account_service->overwrite_cache_with_no_account();

		$this->assertSame( array( 1 ), array_keys( $this->pending_reads() ), 'The retry is the only recheck pending.' );
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
		$this->assertSame( 0, $this->count_events() );
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
		$this->assertSame( 0, $this->count_events(), 'The first read, disabled to available.' );

		$this->assertSame( array(), $this->account_service->refresh_account_data() );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( 0, $this->count_events(), 'Reads that find the tier already available.' );
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
	 * @testdox With its event pending on an available store, a $request_type request loads the recheck: $loads; with no event pending, or on a store never set up, none does and no handler is registered.
	 * @dataProvider provide_request_types
	 *
	 * @param string $request_type Request type.
	 * @param bool   $loads        Whether the recheck is loaded with its event pending.
	 */
	public function test_the_recheck_loads_only_on_cron_and_cli_while_its_event_is_pending( string $request_type, bool $loads ): void {
		// A store never set up, with an event nobody could have scheduled: nothing loads.
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		$this->tier->invalidate();
		WooPaymentsSetupTierRecheck::schedule_first_read();
		$this->assertNotContains( WooPaymentsSetupTierRecheck::class, $this->tier->get_classes_for_request( $request_type ), 'A store that was never set up loads nothing.' );
		wp_clear_scheduled_hook( WooPaymentsSetupTierRecheck::ACTION, array( 0 ) );

		$this->tier->write_tier( WooPaymentsSetupTier::AVAILABLE );
		$this->assertNotContains( WooPaymentsSetupTierRecheck::class, $this->tier->get_classes_for_request( $request_type ), 'No event pending.' );
		$this->assertFalse( $this->handler_registers_in( $request_type ), 'No event pending: no handler.' );

		WooPaymentsSetupTierRecheck::schedule_first_read();
		$this->assertSame( $loads, in_array( WooPaymentsSetupTierRecheck::class, $this->tier->get_classes_for_request( $request_type ), true ) );
		$this->assertSame( $loads, $this->handler_registers_in( $request_type ), 'The handler registers where the class is listed.' );
	}

	/**
	 * Reads that errored, the delay before the next, and what ends the ladder.
	 *
	 * @return array<string,array{int,?int}>
	 */
	public static function provide_errored_reads(): array {
		return array(
			'first read'  => array( 0, HOUR_IN_SECONDS ),
			'second read' => array( 1, 6 * HOUR_IN_SECONDS ),
			'third read'  => array( 2, DAY_IN_SECONDS ),
			'last read'   => array( 3, null ),
		);
	}

	/**
	 * @testdox When read $read errors, $next_delay seconds is the delay to the next read; after the last read nothing is scheduled and one line is logged.
	 * @dataProvider provide_errored_reads
	 *
	 * @param int      $read       Number of the read that errors.
	 * @param int|null $next_delay Delay to the next read, or null when the ladder ends.
	 */
	public function test_an_errored_read_retries_on_a_bounded_ladder_that_ends_in_a_log_line( int $read, ?int $next_delay ): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		// The drop's own event is out of the way, so the retries are the only events left to see.
		wp_clear_scheduled_hook( WooPaymentsSetupTierRecheck::ACTION, array( 0 ) );
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 ) );

		$this->fire_event( $read );

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$pending = $this->pending_reads();
		if ( null === $next_delay ) {
			$this->assertSame( array(), $pending, 'The ladder ends.' );
			$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );
			$this->assertCount( 1, $lines, 'One line is logged.' );
			$this->assertSame( 'warning', $lines[0][0] );
			$this->assertStringContainsString( 'could not be confirmed', $lines[0][1] );
			return;
		}

		$this->assertSame( array( $read + 1 ), array_keys( $pending ), 'One retry.' );
		$this->assertEqualsWithDelta( time() + $next_delay, $pending[ $read + 1 ], 5 );
		$this->assertSame( array(), array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] ), 'Nothing is logged before the ladder ends.' );
	}

	/**
	 * @testdox A deleted account drops the tier and gets its one recheck; the read confirms the low tier, writes nothing new, schedules nothing further and logs nothing.
	 */
	public function test_a_real_deletion_is_confirmed_without_a_write_a_reschedule_or_a_log_line(): void {
		$logger = RecordingWcLogger::install();
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->client->method( 'get_account' )->willThrowException( new ApiException( 'No account.', 'wcpay_account_not_found', 404 ) );

		wc_get_container()->get( WooPaymentsAccountEventHandler::class )->process( 'account.deleted' );

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( array( 0 ), array_keys( $this->pending_reads() ), 'The deletion gets its one recheck.' );

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

		$this->fire_event( 0 );

		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
		$this->assertSame( 0, $tier_writes, 'The tier is not written again.' );
		$this->assertSame( $options_before, $this->autoloaded_options_without_cron(), 'No setting, flag or notice changes.' );
		$this->assertSame( array(), $this->pending_reads(), 'Nothing is scheduled further.' );
		$this->assertCount( $lines_before, $logger->lines, 'Nothing is logged.' );
		$cache = get_option( 'wcpay_account_data' );
		$this->assertFalse( $cache['errored'], 'The read was answered.' );
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
		$this->assertSame( 0, $this->count_events() );
	}

	/**
	 * @testdox When the WooPayments extension becomes the owner between the drop and the event, the event reads nothing from the platform and schedules nothing.
	 */
	public function test_the_recheck_reads_nothing_once_the_extension_owns_payments(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( array( 0 ), array_keys( $this->pending_reads() ) );

		update_option( 'active_plugins', array( self::EXTENSION_PLUGIN ) );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->client->expects( $this->never() )->method( 'get_account' );

		$this->fire_event( 0 );

		$this->assertSame( array(), $this->pending_reads() );
		$this->assert_tier_is( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * @testdox When the tier is already back before the event, the event reads nothing and schedules nothing.
	 */
	public function test_the_recheck_reads_nothing_once_the_tier_is_back(): void {
		$this->store_has_account( 'yes', WooPaymentsSetupTier::ACTIVE );
		$this->account_service->overwrite_cache_with_no_account();
		// A reset the platform refused puts the tier back and leaves its event pending.
		$this->account_service->restore_setup_tier( $this->account() );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
		$this->client->expects( $this->never() )->method( 'get_account' );

		$this->fire_event( 0 );

		$this->assertSame( array(), $this->pending_reads() );
		$this->assert_tier_is( WooPaymentsSetupTier::ACTIVE );
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
	 * Run one recheck event as WP-Cron does: the event leaves the schedule, then its handler runs.
	 *
	 * @param int $read Number of the read.
	 */
	private function fire_event( int $read ): void {
		wp_clear_scheduled_hook( WooPaymentsSetupTierRecheck::ACTION, array( $read ) );
		$this->sut->recheck( $read );
	}

	/**
	 * Get the pending recheck events: the read number and its time.
	 *
	 * @return array<int,int>
	 */
	private function pending_reads(): array {
		$pending = array();
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ WooPaymentsSetupTierRecheck::ACTION ] ?? array() as $event ) {
				$pending[ $event['args'][0] ] = (int) $timestamp;
			}
		}
		ksort( $pending );

		return $pending;
	}

	/**
	 * Count the recheck events in the cron array.
	 *
	 * @return int
	 */
	private function count_events(): int {
		$count = 0;
		foreach ( _get_cron_array() as $hooks ) {
			$count += count( $hooks[ WooPaymentsSetupTierRecheck::ACTION ] ?? array() );
		}

		return $count;
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
	 * Tell whether a request type's bootstrap registers the recheck handler.
	 *
	 * @param string $request_type Request type.
	 * @return bool
	 */
	private function handler_registers_in( string $request_type ): bool {
		remove_all_actions( WooPaymentsSetupTierRecheck::ACTION );
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
			( new PaymentsBootstrap(
				static fn( $container, string $type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $type ),
				static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
			) )->register( wc_get_container(), $request_type );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_doing_cron', '__return_true' );
			Constants::clear_single_constant( 'WP_CLI' );
			set_current_screen( 'front' );
		}

		return false !== has_action( WooPaymentsSetupTierRecheck::ACTION );
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
