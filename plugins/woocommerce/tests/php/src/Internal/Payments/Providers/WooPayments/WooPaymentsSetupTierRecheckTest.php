<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\OnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusChangeRetryListener;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * The setup tier re-check: after the built-in drops a connected or active tier to available, four scheduled steps read
 * the account and raise the tier again, writing only over what they read, and never lowering it.
 *
 * Steps run through Action Scheduler and the hook name is written out here, so a store without the re-check runs these
 * rows too: its steps fail with "no callbacks" and the rows that expect a raise fail.
 */
class WooPaymentsSetupTierRecheckTest extends WC_Unit_Test_Case {

	private const HOOK = 'woocommerce_woopayments_setup_tier_recheck';

	private const GROUP = 'woocommerce_payments';

	private const ROW = 'wcpay_account_data';

	private const DISABLED_FLAG = 'wcpay_on_boarding_disabled';

	private const OFFSETS = array(
		1 => 15 * MINUTE_IN_SECONDS,
		2 => 75 * MINUTE_IN_SECONDS,
		3 => 435 * MINUTE_IN_SECONDS,
		4 => 1875 * MINUTE_IN_SECONDS,
	);

	/**
	 * The platform client both the account service and the onboarding service use.
	 *
	 * @var RecheckApiClient
	 */
	private RecheckApiClient $client;

	/**
	 * Account service from the container.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Setup tier store from the container.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $tier;

	/**
	 * Runtime arbiter from the container.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Recorded log lines.
	 *
	 * @var RecordingWcLogger
	 */
	private RecordingWcLogger $logger;

	/**
	 * The shopper session before the case.
	 *
	 * @var \WC_Session|null
	 */
	private $session_before = null;

	/**
	 * Set up a store whose built-in WooPayments owns payments, with a platform client that answers as connected.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );
		delete_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION );
		delete_transient( self::DISABLED_FLAG );

		$this->client = new RecheckApiClient();
		wc_get_container()->replace( Client::class, $this->client );
		wc_get_container()->reset_all_resolved();

		$this->arbiter         = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$this->tier            = wc_get_container()->get( WooPaymentsSetupTier::class );
		$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$this->logger         = RecordingWcLogger::install();
		$this->session_before = WC()->session;
		as_unschedule_all_actions( self::HOOK );
	}

	/**
	 * Drop the replacements and the services the case resolved, with their request memos.
	 */
	public function tearDown(): void {
		global $wpdb;

		try {
			$wpdb->suppress_errors( false );
			WC()->session = $this->session_before;
			as_unschedule_all_actions( self::HOOK );
			$this->reset_container_replacements();
		} finally {
			wc_get_container()->reset_all_resolved();
			parent::tearDown();
		}
	}

	/**
	 * @testdox R3: a drop from active schedules four steps at +15 min, +1 h 15, +7 h 15 and +31 h 15, before the tier write: with that write throwing, the steps exist.
	 */
	public function test_r3_a_drop_schedules_four_steps_before_the_tier_write(): void {
		$this->store_has_account( 'yes' );
		$throw = static function () {
			throw new \RuntimeException( 'The tier write failed.' );
		};
		add_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, $throw );
		$before = time();

		try {
			$this->account_service->overwrite_cache_with_no_account();
		} finally {
			remove_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, $throw );
		}

		$steps = $this->pending_steps();
		$this->assertCount( 4, $steps, 'Four steps, each with its own arguments.' );
		$drop = $steps[0]['args'][0];
		$this->assertGreaterThanOrEqual( $before, $drop );
		$this->assertLessThanOrEqual( time(), $drop );
		foreach ( self::OFFSETS as $step => $offset ) {
			$this->assertSame( array( $drop, $step ), $steps[ $step - 1 ]['args'] );
			$this->assertSame( $drop + $offset, $steps[ $step - 1 ]['date'], "Step $step's time." );
		}
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier(), 'The throwing write left the tier as it was.' );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_reads_that_schedule_nothing(): array {
		return array(
			'a first not-found from disabled'          => array( WooPaymentsSetupTier::DISABLED, false ),
			'a not-found from available'               => array( WooPaymentsSetupTier::AVAILABLE, false ),
			'a drop while the extension owns payments' => array( WooPaymentsSetupTier::ACTIVE, true ),
		);
	}

	/**
	 * @testdox R4: no steps for $_dataName.
	 * @dataProvider provide_reads_that_schedule_nothing
	 *
	 * @param string $stored_tier     The stored tier before the read.
	 * @param bool   $extension_owner Whether the extension owns payments.
	 */
	public function test_r4_no_steps_without_a_built_in_drop_from_a_set_up_tier( string $stored_tier, bool $extension_owner ): void {
		if ( WooPaymentsSetupTier::ACTIVE === $stored_tier ) {
			$this->store_has_account( 'yes' );
		} else {
			update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier );
		}
		if ( $extension_owner ) {
			update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
		}
		$this->arbiter->invalidate();
		$this->tier->invalidate();

		$this->account_service->overwrite_cache_with_no_account();

		$this->assertSame( array(), $this->pending_steps() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
	}

	/**
	 * @testdox R5: an account answer raises the tier to $expected_tier, stores the row marked as never fetched, fires no refresh hook, removes the later steps and logs one line; the onboarding-disabled flag is untouched ($flag_set).
	 * @testWith ["yes", "active", true]
	 *           ["yes", "active", false]
	 *           ["no", "connected", true]
	 *           ["no", "connected", false]
	 *
	 * @param string $enabled       The gateway's enabled setting.
	 * @param string $expected_tier The tier the raise writes.
	 * @param bool   $flag_set      Whether the onboarding-disabled flag is set before the step.
	 */
	public function test_r5_an_account_answer_raises_the_tier( string $enabled, string $expected_tier, bool $flag_set ): void {
		$drop = $this->drop_from_a_connected_account( $enabled );
		if ( $flag_set ) {
			set_transient( self::DISABLED_FLAG, true, HOUR_IN_SECONDS );
		}
		$flag_before = $this->transient_rows();
		$refreshes   = 0;
		add_action(
			'woocommerce_payments_account_refreshed',
			static function () use ( &$refreshes ) {
				++$refreshes;
			}
		);
		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $this->run_step( $drop, 1 ) );

		$this->assertSame( $expected_tier, $this->stored_tier() );
		$row = $this->stored_row();
		$this->assertSame( $this->account(), $row['data'] );
		$this->assertSame( 0, $row['fetched'], 'Marked as never fetched, so the first foreground read refreshes it.' );
		$this->assertFalse( $row['errored'] );
		$this->assertSame( 0, $refreshes, 'The step fires no refresh hook.' );
		$this->assertSame( array(), $this->pending_steps(), 'The later steps are gone.' );
		$this->assertSame( $flag_before, $this->transient_rows(), 'The onboarding-disabled flag is as it was.' );
		$this->assertSame( array( 'info' ), $this->recheck_levels() );
	}

	/**
	 * @return array<string,array{string,bool,int}>
	 */
	public static function provide_no_account_answers(): array {
		$rows = array();
		foreach ( array( 1, 2, 3 ) as $step ) {
			$rows[ "not found, flag set, step $step" ]           = array( 'wcpay_account_not_found', true, $step );
			$rows[ "onboarding disabled, no flag, step $step" ]  = array( 'wcpay_on_boarding_disabled', false, $step );
			$rows[ "onboarding disabled, flag set, step $step" ] = array( 'wcpay_on_boarding_disabled', true, $step );
		}

		return $rows;
	}

	/**
	 * @testdox R6: a no-account answer before the last step writes nothing (row, tier and flag bytes unchanged), keeps the later steps and logs nothing: $_dataName.
	 * @dataProvider provide_no_account_answers
	 *
	 * @param string $code     The platform error code.
	 * @param bool   $flag_set Whether the onboarding-disabled flag is set before the step.
	 * @param int    $step     The step that runs.
	 */
	public function test_r6_a_no_account_answer_writes_nothing_and_keeps_the_later_steps( string $code, bool $flag_set, int $step ): void {
		$drop = $this->drop_from_a_connected_account();
		if ( $flag_set ) {
			set_transient( self::DISABLED_FLAG, true, HOUR_IN_SECONDS );
		}
		$this->client->answer = static function () use ( $code ) {
			throw new ApiException( 'No account.', $code, 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A platform error code from the data provider, never output.
		};
		$this->register_cron_request();
		$before = $this->stored_state();

		$this->run_step( $drop, $step );

		$this->assertSame( $before, $this->stored_state(), 'Row, tier and flag bytes are unchanged.' );
		$this->assertSame( array_values( array_diff( array( 1, 2, 3, 4 ), array( $step ) ) ), $this->pending_step_numbers( $drop ), 'The later steps are kept.' );
		$this->assertSame( array(), $this->recheck_levels() );
		$this->assertSame( 1, $this->client->plain_gets, 'The step read the account.' );
	}

	/**
	 * @testdox R7: a failed read at steps 1 to 3 writes nothing, keeps the later steps and logs nothing; at step 4 it logs exactly one warning and leaves no step.
	 */
	public function test_r7_failed_reads_write_nothing_and_the_last_one_warns_once(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = static function () {
			throw new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 );
		};
		$this->register_cron_request();
		$before = $this->stored_state();

		foreach ( array( 1, 2, 3 ) as $step ) {
			$this->run_step( $drop, $step );
			$this->assertSame( $before, $this->stored_state(), "Step $step writes nothing." );
			$this->assertSame( range( $step + 1, 4 ), $this->pending_step_numbers( $drop ), "Step $step keeps the later steps." );
			$this->assertSame( array(), $this->recheck_levels(), "Step $step logs nothing." );
		}

		$this->run_step( $drop, 4 );

		$this->assertSame( $before, $this->stored_state() );
		$this->assertSame( array(), $this->pending_steps() );
		$this->assertSame( array( 'warning' ), $this->recheck_levels(), 'The last step warns once.' );
	}

	/**
	 * @testdox R8: a writer that raises the tier to active just before the step's tier write keeps active.
	 */
	public function test_r8_a_tier_raised_just_before_the_tier_write_stands(): void {
		global $wpdb;

		$drop                 = $this->drop_from_a_connected_account( 'no' );
		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();
		$raised               = false;
		$writer               = static function ( $query ) use ( &$raised, $wpdb ) {
			if ( ! $raised && 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, WooPaymentsSetupTier::OPTION_NAME ) ) {
				$raised = true;
				$wpdb->update( $wpdb->options, array( 'option_value' => WooPaymentsSetupTier::ACTIVE ), array( 'option_name' => WooPaymentsSetupTier::OPTION_NAME ) );
			}
			return $query;
		};
		add_filter( 'query', $writer );

		try {
			$this->run_step( $drop, 1 );
		} finally {
			remove_filter( 'query', $writer );
		}

		$this->assertTrue( $raised, 'The other writer ran.' );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
	}

	/**
	 * @testdox R9: a newer row a foreground read stores during the step's read survives.
	 */
	public function test_r9_a_newer_row_stored_during_the_read_survives(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$newer                = array_merge( $this->account(), array( 'payments_enabled' => false ) );
		$this->client->answer = function () use ( $newer ) {
			$this->account_service->cache_account_data( $newer );
			return $this->account();
		};
		$this->register_cron_request();

		$this->run_step( $drop, 1 );

		$this->assertSame( $newer, $this->stored_row()['data'] );
	}

	/**
	 * @return array<string,array{bool}>
	 */
	public static function provide_old_step_answers(): array {
		return array(
			'no account'       => array( false ),
			'with the account' => array( true ),
		);
	}

	/**
	 * @testdox R10: an older drop's step answered $_dataName leaves a newer drop's four steps.
	 * @dataProvider provide_old_step_answers
	 *
	 * @param bool $found Whether the platform finds the account.
	 */
	public function test_r10_an_old_step_leaves_a_newer_drops_steps( bool $found ): void {
		$newer = $this->drop_from_a_connected_account();
		$older = $newer - HOUR_IN_SECONDS;
		foreach ( self::OFFSETS as $step => $offset ) {
			as_schedule_single_action( $older + $offset, self::HOOK, array( $older, $step ), self::GROUP );
		}
		$this->client->answer = $found ? fn() => $this->account() : static function () {
			throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
		};
		$this->register_cron_request();

		$this->run_step( $older, 1 );

		$this->assertSame( array( 1, 2, 3, 4 ), $this->pending_step_numbers( $newer ) );
	}

	/**
	 * @testdox R11: when the extension takes over during the read, nothing is written and the drop's steps are gone.
	 */
	public function test_r11_a_takeover_during_the_read_writes_nothing(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = function () {
			update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
			return $this->account();
		};
		$this->register_cron_request();
		$row_before = $this->raw_row();

		$this->run_step( $drop, 1 );

		$this->assertSame( $row_before, $this->raw_row() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
		$this->assertSame( array(), $this->pending_steps() );
	}

	/**
	 * @testdox R12: a successful deletion's clean-up during the read leaves the row absent and the tier available.
	 */
	public function test_r12_a_deletion_during_the_read_stands(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = function () {
			$this->account_service->overwrite_cache_with_no_account();
			$this->account_service->cleanup_after_account_reset();
			return $this->account();
		};
		$this->register_cron_request();

		$this->run_step( $drop, 1 );

		$this->assertNull( $this->raw_row() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
	}

	/**
	 * @testdox R13: a refused reset whose tier restore fails keeps its steps, and the first step raises the tier from the restored row.
	 */
	public function test_r13_a_failed_tier_restore_keeps_the_steps_and_heals(): void {
		$this->store_has_account( 'yes' );
		$keep                        = static fn( $value, $old_value ) => $old_value;
		$this->client->delete_answer = static function () use ( $keep ) {
			add_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, $keep, 10, 2 );
			throw new ApiException( 'Refused.', 'bad_request', 400 );
		};

		try {
			$this->onboarding_service()->reset_onboarding();
		} finally {
			remove_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, $keep, 10 );
		}

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier(), 'The restore failed.' );
		$this->assertSame( $this->account(), $this->stored_row()['data'], 'The row is restored.' );
		$steps = $this->pending_steps();
		$this->assertCount( 4, $steps, 'The steps are kept.' );

		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();
		$this->run_step( $steps[0]['args'][0], 1 );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
	}

	/**
	 * @testdox R16: a reset killed after the drop leaves the steps, and the first step raises the tier.
	 */
	public function test_r16_a_killed_reset_heals_at_the_first_step(): void {
		$this->store_has_account( 'yes' );
		$this->client->delete_answer = static function () {
			throw new \RuntimeException( 'The process died.' );
		};

		try {
			$this->onboarding_service()->reset_onboarding();
			$this->fail( 'The reset was meant to die.' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
		$steps = $this->pending_steps();
		$this->assertCount( 4, $steps );

		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();
		$this->run_step( $steps[0]['args'][0], 1 );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
		$this->assertSame( $this->account(), $this->stored_row()['data'] );
	}

	/**
	 * @testdox R17: a tier another writer raised after this request read it is seen, and the step reads and writes nothing.
	 */
	public function test_r17_a_tier_raised_after_the_request_memo_is_seen(): void {
		global $wpdb;

		$drop = $this->drop_from_a_connected_account();
		$this->register_cron_request();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->tier->get_stored_tier(), 'The request remembers available.' );
		$wpdb->update( $wpdb->options, array( 'option_value' => WooPaymentsSetupTier::CONNECTED ), array( 'option_name' => WooPaymentsSetupTier::OPTION_NAME ) );
		$this->client->answer = fn() => $this->account();
		$row_before           = $this->raw_row();

		$this->run_step( $drop, 1 );

		$this->assertSame( 0, $this->client->plain_gets );
		$this->assertSame( $row_before, $this->raw_row() );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->stored_tier() );
	}

	/**
	 * @testdox R23: step 1 answered no account writes nothing and keeps steps 2 to 4; step 2 answered with the account raises the tier, removes steps 3 and 4 and logs one line.
	 */
	public function test_r23_a_wrong_first_answer_then_a_true_one_raises_the_tier(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = static function () {
			throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
		};
		$this->register_cron_request();
		$before = $this->stored_state();

		$this->run_step( $drop, 1 );

		$this->assertSame( $before, $this->stored_state() );
		$this->assertSame( array( 2, 3, 4 ), $this->pending_step_numbers( $drop ) );
		$this->assertSame( array(), $this->recheck_levels() );

		$this->client->answer = fn() => $this->account();
		$this->run_step( $drop, 2 );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
		$this->assertSame( $this->account(), $this->stored_row()['data'] );
		$this->assertSame( array(), $this->pending_steps() );
		$this->assertSame( array( 'info' ), $this->recheck_levels() );
	}

	/**
	 * Every path a step or a restore can take, with the number of re-check lines it logs.
	 *
	 * @return array<string,array{string,int}>
	 */
	public static function provide_paths(): array {
		return array(
			'H0a no row, step 1'                        => array( 'H0a', 0 ),
			'H0b no row, step 4'                        => array( 'H0b', 1 ),
			'H1 owner changed before the step'          => array( 'H1', 1 ),
			'H2 tier already raised'                    => array( 'H2', 1 ),
			'H3 read failed, step 1'                    => array( 'H3', 0 ),
			'H4 read failed, step 4'                    => array( 'H4', 1 ),
			'H5a no account, step 2'                    => array( 'H5a', 0 ),
			'H5b no account, step 4'                    => array( 'H5b', 1 ),
			'H6 owner changed during the read'          => array( 'H6', 1 ),
			'H7 row changed, step 1'                    => array( 'H7', 0 ),
			'H8 row changed, step 4'                    => array( 'H8', 1 ),
			'H9 raised to active'                       => array( 'H9', 1 ),
			'H10 raised to connected'                   => array( 'H10', 1 ),
			'H11 tier moved before the tier write'      => array( 'H11', 1 ),
			'H12a tier write failed, step 1'            => array( 'H12a', 0 ),
			'H12b tier write failed, step 4'            => array( 'H12b', 1 ),
			'H13a account with no ID, step 1'           => array( 'H13a', 0 ),
			'H13b account with no ID, step 4'           => array( 'H13b', 1 ),
			'X1 refused reset puts the tier back'       => array( 'X1', 1 ),
			'X2 refused reset whose tier restore fails' => array( 'X2', 0 ),
		);
	}

	/**
	 * @testdox R24: every ending path logs exactly one re-check line and every other path none: $_dataName.
	 * @dataProvider provide_paths
	 *
	 * @param string $path     The path.
	 * @param int    $expected Re-check lines expected.
	 */
	public function test_r24_each_ending_logs_one_line( string $path, int $expected ): void {
		global $wpdb;

		if ( 'X1' === $path || 'X2' === $path ) {
			$this->store_has_account( 'yes' );
			$keep                        = static fn( $value, $old_value ) => $old_value;
			$this->client->delete_answer = static function () use ( $path, $keep ) {
				if ( 'X2' === $path ) {
					add_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, $keep, 10, 2 );
				}
				throw new ApiException( 'Refused.', 'bad_request', 400 );
			};
			$this->onboarding_service()->reset_onboarding();
			remove_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, $keep, 10 );
			$this->assertCount( 'X1' === $path ? 0 : 4, $this->pending_steps() );
			$this->assertCount( $expected, $this->recheck_levels() );
			return;
		}

		$drop = $this->drop_from_a_connected_account( 'H10' === $path ? 'no' : 'yes' );
		$step = in_array( $path, array( 'H0b', 'H4', 'H5b', 'H8', 'H12b', 'H13b' ), true ) ? 4 : ( 'H5a' === $path ? 2 : 1 );
		$this->register_cron_request();

		$found                = fn() => $this->account();
		$this->client->answer = $found;
		switch ( $path ) {
			case 'H0a':
			case 'H0b':
				delete_option( self::ROW );
				break;
			case 'H1':
				update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
				break;
			case 'H2':
				update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::CONNECTED );
				break;
			case 'H3':
			case 'H4':
				$this->client->answer = static function () {
					throw new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 );
				};
				break;
			case 'H5a':
			case 'H5b':
				$this->client->answer = static function () {
					throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
				};
				break;
			case 'H13a':
			case 'H13b':
				$this->client->answer = fn() => array_diff_key( $this->account(), array( 'account_id' => true ) );
				break;
			case 'H6':
				$this->client->answer = function () use ( $found ) {
					update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
					return $found();
				};
				break;
			case 'H7':
			case 'H8':
				$this->client->answer = function () use ( $wpdb, $found ) {
					$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array( 'data' => array() ) ) ), array( 'option_name' => self::ROW ) );
					return $found();
				};
				break;
			case 'H11':
			case 'H12a':
			case 'H12b':
				$this->before_tier_update(
					static function ( string $query ) use ( $path, $wpdb ): string {
						if ( 'H11' === $path ) {
							$wpdb->update( $wpdb->options, array( 'option_value' => WooPaymentsSetupTier::ACTIVE ), array( 'option_name' => WooPaymentsSetupTier::OPTION_NAME ) );
							return $query;
						}
						return 'UPDATE no_such_table SET a = 1';
					}
				);
				break;
		}

		$this->run_step( $drop, $step );

		$this->assertCount( $expected, $this->recheck_levels(), 'Re-check lines.' );
		if ( in_array( $path, array( 'H12a', 'H12b' ), true ) ) {
			$this->assertNotEmpty( $this->logger->get_errors(), 'The setup tier logs its write failure.' );
			$this->assertSame( $this->account(), $this->stored_row()['data'], 'The row was written.' );
		}
	}

	/**
	 * @testdox R25: a step that starts with no account row reads nothing, so a successful reset's clean-up and a stale account answer cannot bring the row back.
	 */
	public function test_r25_no_row_at_the_start_reads_nothing(): void {
		$drop = $this->drop_from_a_connected_account();
		delete_option( self::ROW );
		$this->client->answer = function () {
			$this->account_service->overwrite_cache_with_no_account();
			$this->account_service->cleanup_after_account_reset();
			return $this->account();
		};
		$this->register_cron_request();

		$this->run_step( $drop, 1 );

		$this->assertSame( 0, $this->client->plain_gets );
		$this->assertNull( $this->raw_row() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
	}

	/**
	 * @testdox R26: after a successful reset the four steps read nothing, write nothing and log one info line at step 4.
	 */
	public function test_r26_a_successful_reset_ends_with_one_line_and_no_reads(): void {
		$this->store_has_account( 'yes' );
		$this->client->delete_answer = static fn() => array( 'result' => 'success' );
		$this->onboarding_service()->reset_onboarding();
		$steps                       = $this->pending_steps();
		$this->assertCount( 4, $steps );
		$drop                 = $steps[0]['args'][0];
		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();
		$before               = $this->stored_state();

		foreach ( array( 1, 2, 3, 4 ) as $step ) {
			$this->run_step( $drop, $step );
		}

		$this->assertSame( 0, $this->client->plain_gets );
		$this->assertSame( $before, $this->stored_state() );
		$this->assertSame( array( 'info' ), $this->recheck_levels() );
	}

	/**
	 * @testdox R27: a row a foreground read writes after step 1 found none is what step 2 compares against.
	 */
	public function test_r27_a_row_written_after_an_absent_start_is_compared(): void {
		$drop = $this->drop_from_a_connected_account();
		delete_option( self::ROW );
		$this->register_cron_request();
		$this->run_step( $drop, 1 );
		$this->assertSame( 0, $this->client->plain_gets );
		$this->next_request();

		// The onboarding page reads the account while the platform still answers no account.
		$this->client->answer = static function () {
			throw new ApiException( 'No account.', 'wcpay_account_not_found', 404 );
		};
		$this->account_service->get_cached_account_data( true );
		$this->assertSame( array(), $this->stored_row()['data'] );
		$this->client->answer = fn() => $this->account();

		$this->run_step( $drop, 2 );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
		$this->assertSame( $this->account(), $this->stored_row()['data'] );
	}

	/**
	 * @testdox R30: after a raise, the first front read refetches once and fires the refresh hook, which schedules the failed webhook events fetch; later reads and an AJAX read make no request.
	 */
	public function test_r30_the_first_front_read_after_a_raise_refreshes_once(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$account              = array_merge( $this->account(), array( WebhookReliabilityService::CONTINUOUS_FETCH_FLAG_ACCOUNT_DATA => true ) );
		$this->client->answer = static fn() => $account;
		$this->register_cron_request();
		$this->run_step( $drop, 1 );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
		$this->next_request();
		as_unschedule_all_actions( WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION );
		wc_get_container()->get( WebhookReliabilityService::class )->register();
		$refreshes = 0;
		add_action(
			'woocommerce_payments_account_refreshed',
			static function () use ( &$refreshes ) {
				++$refreshes;
			}
		);
		$this->client->answer = static function () use ( $account ) {
			return $account;
		};

		$this->account_service->get_cached_account_data();
		$this->account_service->get_cached_account_data();
		$this->account_service->get_cached_account_data();
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->account_service->get_cached_account_data();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( 1, $this->client->foreground_gets, 'One request, in the first read.' );
		$this->assertSame( 1, $refreshes );
		$this->assertTrue( as_has_scheduled_action( WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION ), 'The failed webhook events are fetched.' );
		as_unschedule_all_actions( WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION );
	}

	/**
	 * @testdox P1: the first read after a raise blocks on the request; when it fails, the read serves the raised account, stores it errored with a fresh time and fires no refresh hook.
	 */
	public function test_p1_a_failed_first_read_after_a_raise_serves_the_raised_account(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();
		$this->run_step( $drop, 1 );
		$this->next_request();
		$refreshes = 0;
		add_action(
			'woocommerce_payments_account_refreshed',
			static function () use ( &$refreshes ) {
				++$refreshes;
			}
		);
		$this->client->answer = static function () {
			throw new ApiException( 'Timed out.', 'wcpay_http_request_failed', 500 );
		};
		$before               = time();

		$served = $this->account_service->get_cached_account_data();

		$this->assertSame( 1, $this->client->foreground_gets, 'The read was made inside the request.' );
		$this->assertSame( $this->account(), $served );
		$row = $this->stored_row();
		$this->assertTrue( $row['errored'] );
		$this->assertGreaterThanOrEqual( $before, $row['fetched'] );
		$this->assertSame( 0, $refreshes );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public static function provide_answers_with_no_account_id(): array {
		return array(
			'no account ID'                      => array( null ),
			'an empty account ID'                => array( '' ),
			'an account ID that is not a scalar' => array( array( 'acct_123' ) ),
		);
	}

	/**
	 * @testdox R31: an account answer with $_dataName writes nothing, as the foreground tier write refuses it; steps 1 to 3 keep the later steps and log nothing, and step 4 logs one warning.
	 * @dataProvider provide_answers_with_no_account_id
	 *
	 * @param mixed $account_id The answer's account ID, or null for none.
	 */
	public function test_r31_an_account_with_no_id_raises_nothing( $account_id ): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = function () use ( $account_id ) {
			$account = array_diff_key( $this->account(), array( 'account_id' => true ) );
			if ( null !== $account_id ) {
				$account['account_id'] = $account_id;
			}
			return $account;
		};
		$this->register_cron_request();
		$before = $this->stored_state();

		$this->run_step( $drop, 1 );

		$this->assertSame( 1, $this->client->plain_gets, 'The step read the account.' );
		$this->assertSame( $before, $this->stored_state(), 'Row, tier and flag bytes are unchanged.' );
		$this->assertSame( array( 2, 3, 4 ), $this->pending_step_numbers( $drop ), 'The later steps are kept.' );
		$this->assertSame( array(), $this->recheck_levels() );

		$this->run_step( $drop, 4 );

		$this->assertSame( $before, $this->stored_state() );
		$this->assertSame( array( 'warning' ), $this->recheck_levels() );
	}

	/**
	 * @testdox R33: a drop reads the stored tier past this request's memo, so a tier another request raised after the memo was filled gets its four steps.
	 */
	public function test_r33_a_drop_reads_the_stored_tier_past_the_request_memo(): void {
		$this->store_has_account( 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->tier->get_stored_tier(), 'The request remembers available.' );
		// Another request raises the tier again, and this request's options see it.
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->tier->get_stored_tier(), 'The memo still holds available.' );

		$this->account_service->overwrite_cache_with_no_account();

		$this->assertCount( 4, $this->pending_steps(), 'The drop from active scheduled its steps.' );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
	}

	/**
	 * @return array<string,array{string,bool,array<int,string>}>
	 */
	public static function provide_lowest_tier_cron_registrations(): array {
		return array(
			'R1 stored available, built-in owner' => array( 'builtin', true, array( ReconciliationJob::class, PluginLifecycleListener::class, WooPaymentsStatusChangeRetryListener::class ) ),
			'R2 feature off'                      => array( 'feature_off', false, array( WooPaymentsStatusChangeRetryListener::class ) ),
			'R2 extension owner'                  => array( 'extension', false, array( ReconciliationJob::class, PluginLifecycleListener::class, WooPaymentsStatusChangeRetryListener::class ) ),
			'R2 stored disabled'                  => array( 'disabled', false, array() ),
		);
	}

	/**
	 * @testdox R1 and R2: on a cron request the low tiers' cron root registers the re-check only on a stored available tier the built-in owns, with no query, option read or class load where the guard is computed or where the hook is added, and the class list stays as it was: $_dataName.
	 * @dataProvider provide_lowest_tier_cron_registrations
	 *
	 * @param string            $store    The store's shape.
	 * @param bool              $expected Whether the re-check is registered.
	 * @param array<int,string> $classes  The classes the request loads.
	 */
	public function test_r1_r2_a_lowest_tier_cron_request_gains_one_hook_and_nothing_else( string $store, bool $expected, array $classes ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, 'disabled' === $store ? WooPaymentsSetupTier::DISABLED : WooPaymentsSetupTier::AVAILABLE );
		if ( 'feature_off' === $store ) {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		}
		if ( 'extension' === $store ) {
			update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
		}
		$container = $this->make_container();

		$this->bootstrap()->register( $container, RequestTypeUtil::CRON );

		$this->assertSame( $expected ? 1 : 0, $this->recheck_callbacks() );
		$this->assertSame( $classes, $container->resolved, 'The class list is the one the request loaded before.' );
		// The guard is computed while the class list is built: that costs what the list's own inputs cost, and no more.
		$inputs  = $this->meter_call(
			function (): void {
				$this->tier->get_effective_tier();
				$this->tier->get_stored_tier();
			}
		);
		$listing = $this->meter_call(
			function (): void {
				$this->tier->get_classes_for_request( RequestTypeUtil::CRON );
			}
		);
		$this->assertSame( $inputs->options, $listing->options, 'No option read where the guard is computed.' );
		$this->assertSame( $inputs->queries, $listing->queries, 'No query where the guard is computed.' );
		$this->assertSame( array(), $listing->autoloads, 'No class load where the guard is computed.' );
		$this->assertSame( 0, $container->meters['register']->queries, 'No query.' );
		$this->assertSame( 0, $container->meters['register']->option_reads, 'No option read.' );
		$this->assertSame( array(), $container->meters['register']->autoloads, 'No class load.' );
	}

	/**
	 * @testdox R2b: on a stored available tier, a $request request gains no re-check until Action Scheduler begins an action, and then exactly one, with no query, no option read and only the cron classes the request already registers there.
	 * @testWith ["front"]
	 *           ["admin"]
	 *           ["ajax"]
	 *           ["rest"]
	 *
	 * @param string $request Request type.
	 */
	public function test_r2b_a_lowest_tier_page_request_registers_only_when_the_scheduler_runs( string $request ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		$container = $this->make_container();

		$this->bootstrap()->register( $container, $request );

		$this->assertSame( 0, $this->recheck_callbacks(), 'No hook at bootstrap.' );
		$resolved_at_bootstrap = $container->resolved;
		// The bootstrap's priority-0 listener removes itself while the hook runs, and WordPress then skips the next
		// priority, so a no-op takes priority 1 and the meter stops at 2.
		$meter = $this->bracket_before_execute( -1, 2 );
		add_action( 'action_scheduler_before_execute', '__return_null', 1, 0 );

		$this->run_noop_action();

		$this->assertTrue( $meter->stopped, 'The meter stopped.' );
		$this->assertSame( 1, $this->recheck_callbacks() );
		$this->assertSame( 0, $meter->queries, implode( "\n", $meter->sql ) );
		$this->assertSame( 0, $meter->option_reads );
		$this->assertSame(
			'admin' === $request ? array( WooPaymentsStatusChangeRetryListener::class ) : array( ReconciliationJob::class, WooPaymentsStatusChangeRetryListener::class ),
			array_values( array_diff( $container->resolved, $resolved_at_bootstrap ) ),
			'The cron classes the request already registered on demand.'
		);
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function provide_set_up_requests(): array {
		$rows = array();
		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $tier ) {
			foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron' ) as $request ) {
				$rows[ "$tier $request" ] = array( $tier, $request );
			}
		}

		return $rows;
	}

	/**
	 * @testdox R2: a set-up store's request gains nothing until Action Scheduler begins an action, then exactly one re-check hook with no query, option read or class, and none for a second action: $_dataName.
	 * @dataProvider provide_set_up_requests
	 *
	 * @param string $tier    Stored tier.
	 * @param string $request Request type.
	 */
	public function test_r2_a_set_up_store_registers_only_when_the_scheduler_runs( string $tier, string $request ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, $tier );
		$container = $this->make_container();

		$this->bootstrap()->register( $container, $request );

		$this->assertSame( 0, $this->recheck_callbacks(), 'No hook at bootstrap.' );
		$this->assertSame( 0, $container->meters['register']->queries, 'The account service registers with no query.' );
		$this->assertSame( 0, $container->meters['register']->option_reads );
		$meter    = $this->bracket_before_execute( 9, 11 );
		$resolved = array();
		$count    = static function () use ( $container, &$resolved ) {
			$resolved[] = count( $container->resolved );
		};
		add_action( 'action_scheduler_before_execute', $count, 9, 0 );
		add_action( 'action_scheduler_before_execute', $count, 11, 0 );

		$this->run_noop_action();

		$this->assertTrue( $meter->stopped, 'The meter stopped.' );
		$this->assertSame( 1, $this->recheck_callbacks() );
		$this->assertSame( 0, $meter->queries, implode( "\n", $meter->sql ) );
		$this->assertSame( 0, $meter->option_reads );
		$this->assertSame( array(), $meter->autoloads );
		$this->assertSame( $resolved[0], $resolved[1], 'No class is resolved at that moment.' );

		$this->run_noop_action();

		$this->assertSame( 1, $this->recheck_callbacks(), 'A second action adds no second callback.' );
	}

	/**
	 * @testdox R18: a step run inside a front request through the bootstrap's on-demand path reaches the handler.
	 */
	public function test_r18_a_step_in_a_front_request_reaches_the_handler(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = fn() => $this->account();
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$this->bootstrap()->register( $this->make_container(), RequestTypeUtil::FRONT );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $this->run_step( $drop, 1 ) );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->stored_tier() );
	}

	/**
	 * @testdox R19: a runtime owner memo dropped before the scheduler begins step 4 loses no step: it runs and warns once.
	 */
	public function test_r19_a_dropped_owner_memo_loses_no_step(): void {
		$drop                 = $this->drop_from_a_connected_account();
		$this->client->answer = static function () {
			throw new ApiException( 'The platform is unavailable.', 'wcpay_platform_error', 503 );
		};
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$this->bootstrap()->register( $this->make_container(), RequestTypeUtil::FRONT );
		$this->arbiter->invalidate();

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $this->run_step( $drop, 4 ) );

		$this->assertSame( array( 'warning' ), $this->recheck_levels() );
	}

	/**
	 * @testdox R20: a step left on a store a foreground read raised to connected removes its own steps and logs one line, run from $_dataName.
	 * @dataProvider provide_runners
	 *
	 * @param string $request Request type.
	 */
	public function test_r20_a_leftover_step_on_a_raised_store_ends_itself( string $request ): void {
		$drop = $this->drop_from_a_connected_account();
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'enabled' => 'no' ) );
		$this->account_service->cache_account_data( $this->account() );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->stored_tier() );
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$filter = 'cron' === $request ? 'wp_doing_cron' : ( 'ajax' === $request ? 'wp_doing_ajax' : null );
		if ( null !== $filter ) {
			add_filter( $filter, '__return_true' );
		}

		try {
			$this->bootstrap()->register( $this->make_container(), $request );
			$status = $this->run_step( $drop, 1 );
		} finally {
			if ( null !== $filter ) {
				remove_filter( $filter, '__return_true' );
			}
		}

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $status, 'No Action Scheduler failure.' );
		$this->assertSame( array(), $this->pending_steps() );
		$this->assertSame( array( 'info' ), $this->recheck_levels() );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->stored_tier() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_runners(): array {
		return array(
			'WP-Cron'                            => array( RequestTypeUtil::CRON ),
			'the async runner over admin-ajax'   => array( RequestTypeUtil::AJAX ),
			'an ALTERNATE_WP_CRON front request' => array( RequestTypeUtil::FRONT ),
		);
	}

	/**
	 * @testdox R21 and R22: on a store the extension now owns, each step fails once in Action Scheduler with no WooPayments line, nothing written or rescheduled; a later drop schedules four new steps.
	 */
	public function test_r21_r22_steps_on_an_extension_store_fail_once_and_do_not_block_a_new_drop(): void {
		$drop = $this->drop_from_a_connected_account();
		update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
		$this->client->answer = fn() => $this->account();
		$this->register_cron_request();
		$before               = $this->stored_state();

		foreach ( array( 1, 2, 3, 4 ) as $step ) {
			$this->assertSame( ActionScheduler_Store::STATUS_FAILED, $this->run_step( $drop, $step ), "Step $step fails once." );
		}

		$this->assertSame( array(), $this->pending_steps(), 'Nothing is rescheduled.' );
		$this->assertSame( $before, $this->stored_state() );
		$this->assertSame( 0, $this->client->plain_gets );
		$this->assertSame( array(), array_filter( $this->logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] ) );

		update_option( 'active_plugins', array() );
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$this->store_has_account( 'yes' );
		$this->account_service->overwrite_cache_with_no_account();

		$this->assertCount( 4, $this->pending_steps() );
	}

	/**
	 * The answers R29 sends through the transport.
	 *
	 * @return array<string,array{string}>
	 */
	public static function provide_transport_answers(): array {
		return array(
			'a live account'                             => array( 'live' ),
			'a sandbox account in dev mode'              => array( 'sandbox' ),
			'404 account not found'                      => array( 'not_found' ),
			'onboarding disabled'                        => array( 'disabled' ),
			'500 error'                                  => array( 'error' ),
			'transport error'                            => array( 'transport' ),
			'empty 200 body'                             => array( 'empty' ),
			'top-level card testing code with a session' => array( 'card_testing' ),
			'fraudulent decline code with a session'     => array( 'fraudulent' ),
			'401 with a WordPress.com connection error'  => array( 'jetpack' ),
		);
	}

	/**
	 * @testdox R29: apart from the account row and the tier, a step leaves every stored value as it found it, whatever the platform answers, the transport's own records excepted: $_dataName.
	 * @dataProvider provide_transport_answers
	 *
	 * @param string $answer The platform's answer.
	 */
	public function test_r29_a_step_changes_only_the_row_and_the_tier( string $answer ): void {
		\Jetpack_Options::update_option( 'id', 123 );
		\Jetpack_Options::update_option( 'blog_token', 'tokenkey.blogsecret' );
		\Jetpack_Options::update_option( 'time_diff', 0 );
		update_option( 'wcpay_onboarding_test_mode', 'no' );
		$drop = $this->drop_from_a_connected_account();
		set_transient( self::DISABLED_FLAG, true, HOUR_IN_SECONDS );
		$this->use_real_client();
		if ( 'sandbox' === $answer ) {
			add_filter( 'wcpay_dev_mode', '__return_true' );
		}
		$session = null;
		if ( 'card_testing' === $answer || 'fraudulent' === $answer ) {
			$session = $this->start_session_with_fraud_protection();
		}
		add_action(
			'woocommerce_payments_account_refreshed',
			static function () {
				update_option( 'b936_account_refreshed', 'yes' );
			}
		);
		add_filter(
			'pre_http_request',
			static function () use ( $answer ) {
				return self::transport_answer( $answer );
			}
		);
		$this->register_cron_request();
		$token_before = null === $session ? null : $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME );
		$before       = $this->snapshot();

		do_action( self::HOOK, $drop, 2 );

		$changed = $this->changed_keys( $before, $this->snapshot() );
		$this->assertSame( in_array( $answer, array( 'live', 'sandbox' ), true ) ? array( self::ROW, WooPaymentsSetupTier::OPTION_NAME ) : array(), $changed );
		if ( null !== $session ) {
			$this->assertSame( $token_before, $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ), 'The shopper token is not rotated.' );
		}
		if ( 'sandbox' === $answer ) {
			remove_filter( 'wcpay_dev_mode', '__return_true' );
			$this->assertSame( 'no', get_option( 'wcpay_onboarding_test_mode' ) );
		}
	}

	/**
	 * Build the transport response for an answer.
	 *
	 * @param string $answer The answer's name.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function transport_answer( string $answer ) {
		$account = array(
			'account_id'           => 'acct_123',
			'is_live'              => 'sandbox' !== $answer,
			'payments_enabled'     => true,
			'details_submitted'    => true,
			'live_publishable_key' => 'pk_live_123',
			'test_publishable_key' => 'pk_test_123',
		);
		$bodies  = array(
			'live'         => array( 200, $account ),
			'sandbox'      => array( 200, $account ),
			'not_found'    => array(
				404,
				array(
					'error' => array(
						'code'    => 'wcpay_account_not_found',
						'message' => 'No account.',
					),
				),
			),
			'disabled'     => array(
				400,
				array(
					'error' => array(
						'code'    => 'wcpay_on_boarding_disabled',
						'message' => 'Onboarding is disabled.',
					),
				),
			),
			'error'        => array(
				500,
				array(
					'error' => array(
						'code'    => 'wcpay_platform_error',
						'message' => 'Unavailable.',
					),
				),
			),
			'empty'        => array( 200, '' ),
			'card_testing' => array(
				400,
				array(
					'code'    => 'wcpay_card_testing_prevention',
					'message' => 'Card testing.',
				),
			),
			'fraudulent'   => array(
				402,
				array(
					'error' => array(
						'code'         => 'card_declined',
						'decline_code' => 'fraudulent',
						'message'      => 'Declined.',
					),
				),
			),
			'jetpack'      => array(
				401,
				array(
					'error'   => 'invalid_token',
					'message' => 'The token is invalid.',
				),
			),
		);
		if ( 'transport' === $answer ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		list( $code, $body ) = $bodies[ $answer ];

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Every stored value a step could touch: the options table and the WooCommerce sessions table, without the records the
	 * WordPress.com connection writes on every signed request.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(): array {
		global $wpdb;

		wp_cache_delete( 'alloptions', 'options' );
		$snapshot = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options}", ARRAY_A ) as $row ) {
			$name = $row['option_name'];
			if (
				in_array( $name, array( 'jetpack_sync_error_idc', 'jetpack_migrate_for_idc', 'jetpack_connection_xmlrpc_errors', 'jetpack_connection_xmlrpc_verified_errors' ), true )
				|| 0 === strpos( $name, '_transient_jetpack_connection_error_reporting_gate_' )
				|| 0 === strpos( $name, '_transient_timeout_jetpack_connection_error_reporting_gate_' )
			) {
				continue;
			}
			$value = $row['option_value'];
			if ( 'jetpack_options' === $name ) {
				$options = maybe_unserialize( $value );
				unset( $options['fallback_no_verify_ssl_certs'], $options['time_diff'] );
				$value = $options;
			}
			$snapshot[ 'option:' . $name ] = array( $value, $row['autoload'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $wpdb->get_results( "SELECT session_key, session_value FROM {$wpdb->prefix}woocommerce_sessions", ARRAY_A ) as $row ) {
			$snapshot[ 'session:' . $row['session_key'] ] = $row['session_value'];
		}

		return $snapshot;
	}

	/**
	 * Name what changed between two snapshots: option names and session keys.
	 *
	 * @param array<string,mixed> $before Snapshot before.
	 * @param array<string,mixed> $after  Snapshot after.
	 * @return array<int,string>
	 */
	private function changed_keys( array $before, array $after ): array {
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) || $before[ $key ] !== $after[ $key ] ) {
				$changed[] = (string) preg_replace( '/^option:/', '', $key );
			}
		}
		sort( $changed );

		return $changed;
	}

	/**
	 * Serve the account service a real client whose transport signs with the WordPress.com connection, so the platform's
	 * answers go through Jetpack's request handling.
	 */
	private function use_real_client(): void {
		$http   = new class() extends HttpClient {
			/**
			 * The store is connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return true;
			}
		};
		$client = new Client();
		$client->init( $http, $this->account_service, wc_get_container()->get( TransportLog::class ) );
		wc_get_container()->replace( Client::class, $client );
	}

	/**
	 * Start a shopper session with a fraud-prevention token and card-testing protection on.
	 *
	 * @return \WC_Session_Handler
	 */
	private function start_session_with_fraud_protection(): \WC_Session_Handler {
		$service = new class() extends WooPaymentsFraudPreventionService {
			/**
			 * Card-testing protection is on.
			 *
			 * @return bool
			 */
			public function is_enabled(): bool {
				return true;
			}
		};
		$service->init( $this->account_service );
		wc_get_container()->replace( WooPaymentsFraudPreventionService::class, $service );
		$session = new \WC_Session_Handler();
		$session->init();
		WC()->session = $session;
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'token-before' );

		return $session;
	}

	/**
	 * Put a connected account in the store with the gateway on or off; the tier becomes active or connected.
	 *
	 * @param string $enabled The gateway's enabled setting.
	 */
	private function store_has_account( string $enabled = 'yes' ): void {
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'                        => $enabled,
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cache_account_data( $this->account() );
		$this->assertSame( 'yes' === $enabled ? WooPaymentsSetupTier::ACTIVE : WooPaymentsSetupTier::CONNECTED, $this->stored_tier() );
	}

	/**
	 * Drop a store with a connected account to available through a no-account read, and get the drop's time. Without the
	 * re-check nothing is scheduled; the four steps are then scheduled here, so the rows still run them.
	 *
	 * @param string $enabled The gateway's enabled setting.
	 * @return int
	 */
	private function drop_from_a_connected_account( string $enabled = 'yes' ): int {
		global $wpdb;

		$this->store_has_account( $enabled );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->stored_tier() );
		// A step runs at least 15 minutes after the drop, so a row rewritten by a step never has the drop's bytes.
		$row            = $this->stored_row();
		$row['fetched'] = (int) $row['fetched'] - 15 * MINUTE_IN_SECONDS;
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $row ) ), array( 'option_name' => self::ROW ) );
		wp_cache_delete( self::ROW, 'options' );
		$this->logger->lines    = array();
		$this->logger->contexts = array();

		$steps = $this->pending_steps();
		if ( array() !== $steps ) {
			return $steps[0]['args'][0];
		}

		$drop = time();
		foreach ( self::OFFSETS as $step => $offset ) {
			as_schedule_single_action( $drop + $offset, self::HOOK, array( $drop, $step ), self::GROUP );
		}

		return $drop;
	}

	/**
	 * Start what the next request starts with: the services and their request memos fresh, the stored values as they are.
	 */
	private function next_request(): void {
		wc_get_container()->reset_all_resolved();
		$this->arbiter         = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$this->tier            = wc_get_container()->get( WooPaymentsSetupTier::class );
		$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::ROW, 'options' );
	}

	/**
	 * Build the onboarding service over the case's client and the container's account service.
	 *
	 * @return OnboardingService
	 */
	private function onboarding_service(): OnboardingService {
		$service = new OnboardingService();
		$service->init( wc_get_container()->get( LegacyProxy::class ), new PaymentMethodRegistry(), $this->client, $this->account_service );

		return $service;
	}

	/**
	 * Load what a cron request loads on this store's tier: build its class list and register the status-change listener.
	 */
	private function register_cron_request(): void {
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$this->tier->get_classes_for_request( RequestTypeUtil::CRON );
		wc_get_container()->get( WooPaymentsStatusChangeRetryListener::class )->register();
	}

	/**
	 * Run a drop's step through Action Scheduler, as its queue runner does, and get the action's status after.
	 *
	 * @param int $drop When the tier dropped.
	 * @param int $step The step.
	 * @return string
	 */
	private function run_step( int $drop, int $step ): string {
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => self::HOOK,
				'args'   => array( $drop, $step ),
				'group'  => self::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $ids, "Step $step is pending." );
		$id = (int) reset( $ids );
		ActionScheduler::runner()->process_action( $id, 'WP Cron' );
		$this->arbiter->invalidate();
		$this->tier->invalidate();

		return ActionScheduler::store()->get_status( $id );
	}

	/**
	 * Run a no-op action through Action Scheduler.
	 */
	private function run_noop_action(): void {
		add_action( 'b936_noop_action', '__return_null' );
		$id = as_schedule_single_action( time(), 'b936_noop_action', array( wp_rand() ), 'b936' );
		ActionScheduler::runner()->process_action( $id, 'WP Cron' );
	}

	/**
	 * Get the pending re-check steps, in order of their time.
	 *
	 * @return array<int,array{args:array<int,int>,date:int}>
	 */
	private function pending_steps(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);
		$steps   = array();
		foreach ( $actions as $action ) {
			$steps[] = array(
				'args' => array_map( 'intval', $action->get_args() ),
				'date' => $action->get_schedule()->get_date()->getTimestamp(),
			);
		}

		return $steps;
	}

	/**
	 * Get the numbers of a drop's pending steps.
	 *
	 * @param int $drop When the tier dropped.
	 * @return array<int,int>
	 */
	private function pending_step_numbers( int $drop ): array {
		$steps = array();
		foreach ( $this->pending_steps() as $step ) {
			if ( $drop === $step['args'][0] ) {
				$steps[] = $step['args'][1];
			}
		}
		sort( $steps );

		return $steps;
	}

	/**
	 * Get the levels of the re-check lines logged.
	 *
	 * @return array<int,string>
	 */
	private function recheck_levels(): array {
		$levels = array();
		foreach ( $this->logger->lines as $line ) {
			if ( false !== strpos( $line[1], 'setup tier re-check' ) ) {
				$levels[] = $line[0];
			}
		}

		return $levels;
	}

	/**
	 * Get the stored tier, read from the database.
	 *
	 * @return string
	 */
	private function stored_tier(): string {
		global $wpdb;

		$this->tier->invalidate();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( WooPaymentsSetupTier::OPTION_NAME, 'options' );

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WooPaymentsSetupTier::OPTION_NAME ) );
	}

	/**
	 * Get the account row's stored bytes.
	 *
	 * @return string|null
	 */
	private function raw_row(): ?string {
		global $wpdb;

		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ROW ) );

		return is_string( $row ) ? $row : null;
	}

	/**
	 * Get the account row, unserialized.
	 *
	 * @return array<string,mixed>
	 */
	private function stored_row(): array {
		$row = maybe_unserialize( (string) $this->raw_row() );
		$this->assertIsArray( $row );

		return $row;
	}

	/**
	 * Get the onboarding-disabled flag's two rows.
	 *
	 * @return array<string,string|null>
	 */
	private function transient_rows(): array {
		global $wpdb;

		$rows = array();
		foreach ( array( '_transient_' . self::DISABLED_FLAG, '_transient_timeout_' . self::DISABLED_FLAG ) as $name ) {
			$value         = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$rows[ $name ] = is_string( $value ) ? $value : null;
		}

		return $rows;
	}

	/**
	 * Get the bytes of the account row, the tier and the onboarding-disabled flag.
	 *
	 * @return array<string,mixed>
	 */
	private function stored_state(): array {
		return array(
			'row'  => $this->raw_row(),
			'tier' => $this->stored_tier(),
			'flag' => $this->transient_rows(),
		);
	}

	/**
	 * Run a callback on the query that writes the tier from available, and send what it returns.
	 *
	 * @param callable $callback Takes the query and returns the query to send.
	 */
	private function before_tier_update( callable $callback ): void {
		global $wpdb;

		$wpdb->suppress_errors( true );
		add_filter(
			'query',
			static function ( $query ) use ( $callback ) {
				if ( is_string( $query ) && 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, WooPaymentsSetupTier::OPTION_NAME ) && false !== strpos( $query, "option_value = '" . WooPaymentsSetupTier::AVAILABLE . "'" ) ) {
					return $callback( $query );
				}
				return $query;
			}
		);
	}

	/**
	 * Count the callbacks on the re-check hook.
	 *
	 * @return int
	 */
	private function recheck_callbacks(): int {
		global $wp_filter;

		$count = 0;
		foreach ( isset( $wp_filter[ self::HOOK ] ) ? $wp_filter[ self::HOOK ]->callbacks : array() as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}

	/**
	 * Meter queries, option reads and class loads between two priorities of the hook Action Scheduler fires before an action.
	 *
	 * @param int $start Priority that starts the meter.
	 * @param int $stop  Priority that stops it.
	 * @return object Meter.
	 */
	private function bracket_before_execute( int $start, int $stop ): object {
		$meter = $this->meter();
		add_action( 'action_scheduler_before_execute', array( $meter, 'start' ), $start, 0 );
		add_action( 'action_scheduler_before_execute', array( $meter, 'stop' ), $stop, 0 );

		return $meter;
	}

	/**
	 * Build a meter of queries, option reads and class autoloads. The suite autoloads classes earlier cases used, so
	 * the class count shows only loads of classes not loaded yet.
	 *
	 * @return object
	 */
	private function meter(): object {
		return new class() {
			/** @var int */
			public int $queries = 0;

			/** @var int */
			public int $option_reads = 0;

			/** @var array<int,string> */
			public array $options = array();

			/** @var array<int,string> */
			public array $autoloads = array();

			/** @var array<int,string> */
			public array $sql = array();

			/** @var bool */
			public bool $stopped = false;

			/** @var array<string,callable> */
			private array $callbacks = array();

			/** Start counting. */
			public function start(): void {
				$this->callbacks = array(
					'query'    => function ( $query ) {
						++$this->queries;
						$this->sql[] = $query;
						return $query;
					},
					'all'      => function ( $hook_name ) {
						if ( is_string( $hook_name ) && 0 === strpos( $hook_name, 'pre_option_' ) ) {
							++$this->option_reads;
							$this->options[] = substr( $hook_name, strlen( 'pre_option_' ) );
						}
					},
					'autoload' => function ( $class_name ) {
						$this->autoloads[] = $class_name;
					},
				);
				add_filter( 'query', $this->callbacks['query'] );
				add_action( 'all', $this->callbacks['all'] );
				spl_autoload_register( $this->callbacks['autoload'], true, true );
			}

			/** Stop counting. */
			public function stop(): void {
				if ( array() === $this->callbacks ) {
					return;
				}
				remove_filter( 'query', $this->callbacks['query'] );
				remove_action( 'all', $this->callbacks['all'] );
				spl_autoload_unregister( $this->callbacks['autoload'] );
				$this->callbacks = array();
				$this->stopped   = true;
			}
		};
	}

	/**
	 * Meter one call made with the setup tier's and the runtime arbiter's request memos empty.
	 *
	 * @param callable $call The call.
	 * @return object Meter.
	 */
	private function meter_call( callable $call ): object {
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$meter = $this->meter();
		$meter->start();
		try {
			$call();
		} finally {
			$meter->stop();
		}

		return $meter;
	}

	/**
	 * Build the bootstrap with the setup tier's class lists, as WooCommerce does.
	 *
	 * @return PaymentsBootstrap
	 */
	private function bootstrap(): PaymentsBootstrap {
		return new PaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		);
	}

	/**
	 * A container that hands out the real setup tier, runtime arbiter, status-change listener and account service, the
	 * last two metered while they register, and a no-op for every other class. It records what it resolved.
	 *
	 * @return RuntimeContainer&object{resolved:array<int,string>,meters:array<string,object>}
	 */
	private function make_container(): RuntimeContainer {
		$this->arbiter->invalidate();
		$this->tier->invalidate();
		$meter = $this->meter();

		return new class( $meter ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var array<string,object> */
			public array $meters;

			/**
			 * Initialize the container.
			 *
			 * @param object $meter Meter for the real classes' registration.
			 */
			public function __construct( object $meter ) {
				parent::__construct( array() );
				$this->meters = array( 'register' => $meter );
			}

			/**
			 * Resolve a class.
			 *
			 * @param string $class_name Class name.
			 * @return object
			 */
			public function get( string $class_name ) {
				if ( in_array( $class_name, array( WooPaymentsSetupTier::class, WooPaymentsRuntimeArbiter::class ), true ) ) {
					return wc_get_container()->get( $class_name );
				}
				$this->resolved[] = $class_name;
				if ( in_array( $class_name, array( WooPaymentsStatusChangeRetryListener::class, WooPaymentsAccountService::class ), true ) ) {
					$real  = wc_get_container()->get( $class_name );
					$meter = $this->meters['register'];
					return new class( $real, $meter ) {
						/** @var object */
						private $real;

						/** @var object */
						private $meter;

						/**
						 * Wrap a registrar.
						 *
						 * @param object $real  The real registrar.
						 * @param object $meter The meter.
						 */
						public function __construct( $real, $meter ) {
							$this->real  = $real;
							$this->meter = $meter;
						}

						/** Register the real class under the meter. */
						public function register(): void {
							$this->meter->start();
							try {
								$this->real->register();
							} finally {
								$this->meter->stop();
							}
						}
					};
				}

				return new class() {
					/**
					 * Do nothing.
					 *
					 * @param string           $name      Method name.
					 * @param array<int,mixed> $arguments Arguments.
					 * @return null
					 */
					public function __call( $name, $arguments ) {
						return null;
					}
				};
			}
		};
	}

	/**
	 * A connected live account, as the platform answers it.
	 *
	 * @return array<string,mixed>
	 */
	private function account(): array {
		return array(
			'account_id'           => 'acct_123',
			'is_live'              => true,
			'payments_enabled'     => true,
			'details_submitted'    => true,
			'live_publishable_key' => 'pk_live_123',
		);
	}
}
