<?php
/**
 * WooPaymentsCutoverReconciliationJob tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler;
use ActionScheduler_QueueRunner;
use ActionScheduler_Store;
use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsCutoverReconciliationJob.
 */
class WooPaymentsCutoverReconciliationJobTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCutoverReconciliationJob|null
	 */
	private ?WooPaymentsCutoverReconciliationJob $sut = null;

	/**
	 * State store fixture.
	 *
	 * @var WooPaymentsCutoverStateStore|null
	 */
	private ?WooPaymentsCutoverStateStore $state_store = null;

	/**
	 * Action Scheduler fixture.
	 *
	 * @var WooPaymentsCutoverActionScheduler|null
	 */
	private ?WooPaymentsCutoverActionScheduler $scheduler = null;

	/**
	 * Job instances whose callbacks must be removed.
	 *
	 * @var array<int,WooPaymentsCutoverReconciliationJob>
	 */
	private array $jobs = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if (
			class_exists( WooPaymentsCutoverReconciliationJob::class )
			&& class_exists( WooPaymentsCutoverStateStore::class )
			&& class_exists( WooPaymentsCutoverActionScheduler::class )
		) {
			$this->state_store = new WooPaymentsCutoverStateStore();
			$this->scheduler   = new WooPaymentsCutoverActionScheduler();
			$this->cleanup_state();
			$this->sut = $this->create_job( true );
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->jobs as $job ) {
			remove_action( 'woocommerce_woopayments_cutover_reconcile', array( $job, 'handle_reconcile' ), 10 );
			remove_action( 'action_scheduler_init', array( $job, 'handle_action_scheduler_init' ), 10 );
		}

		if ( $this->state_store instanceof WooPaymentsCutoverStateStore ) {
			$this->cleanup_state();
		}

		parent::tearDown();
	}

	/**
	 * @testdox Enqueue creates one complete autoloaded state record and one exact action.
	 */
	public function test_enqueue_persists_initial_state_and_exact_action(): void {
		$sut    = $this->require_sut();
		$before = time();

		$this->assertTrue( $sut->enqueue( 'merchant' ), 'An enabled native runtime should accept cutover work.' );

		$after      = time();
		$record     = $this->require_state_store()->get_record();
		$alloptions = wp_load_alloptions( true );
		$this->assertIsArray( $record );
		$this->assertSame( 1, $record['schema_version'] );
		$this->assertSame( 1, $record['generation'] );
		$this->assertGreaterThanOrEqual( 2, $record['revision'], 'Persisting the scheduled action should advance the initial revision.' );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $record['state'] );
		$this->assertGreaterThanOrEqual( $before, $record['started_at'] );
		$this->assertLessThanOrEqual( $after, $record['started_at'] );
		$this->assertSame( $record['started_at'], $record['updated_at'] );
		$this->assertSame( 0, $record['attempt'] );
		$this->assertNull( $record['lease_token'] );
		$this->assertNull( $record['lease_expires_at'] );
		$this->assertGreaterThan( 0, $record['action_id'] );
		$this->assertSame( 'queued', $record['current_step'] );
		$this->assertSame(
			array(
				'deferred_codes'         => array(),
				'informational_outcomes' => array(),
				'next_attempt_at'        => null,
			),
			array_intersect_key( $record, array_flip( array( 'deferred_codes', 'informational_outcomes', 'next_attempt_at' ) ) )
		);
		$this->assertSame( 'queued', $record['step_log'][0]['step'] ?? null );
		$this->assertSame( 'merchant', $record['step_log'][0]['context']['source'] ?? null );
		$this->assertArrayHasKey( 'woocommerce_woopayments_cutover_state', $alloptions, 'The state should be autoloaded on the current site.' );

		$action = ActionScheduler::store()->fetch_action( $record['action_id'] );
		$this->assertSame( 'woocommerce_woopayments_cutover_reconcile', $action->get_hook() );
		$this->assertSame( 'woocommerce_woopayments_cutover', $action->get_group() );
		$this->assertSame(
			array(
				'generation' => 1,
				'attempt'    => 1,
			),
			$action->get_args()
		);
	}

	/**
	 * @testdox Duplicate enqueue preserves the active record and does not create another action.
	 */
	public function test_enqueue_is_idempotent_for_an_active_job(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$first_record = $this->require_state_store()->get_record();

		$this->assertTrue( $sut->enqueue( 'manual_deactivation' ) );

		$this->assertSame( $first_record, $this->require_state_store()->get_record(), 'A duplicate trigger should not rewrite the active generation.' );
		$this->assertSame( 1, $this->count_cutover_actions(), 'A duplicate trigger should retain one scheduled action.' );
	}

	/**
	 * @testdox Disabled native runtime refuses work without persisting state or scheduling an action.
	 */
	public function test_enqueue_does_nothing_when_native_runtime_is_disabled(): void {
		$this->require_sut();
		$sut = $this->create_job( false );

		$this->assertFalse( $sut->enqueue( 'merchant' ) );
		$this->assertNull( $this->require_state_store()->get_record() );
		$this->assertSame( 0, $this->count_cutover_actions() );
	}

	/**
	 * @testdox Deferred work persists its due time before scheduling the next monotonic attempt.
	 * @testWith [3600, 900]
	 *           [86401, 86400]
	 *
	 * @param int $age            Job age in seconds.
	 * @param int $expected_delay Expected retry delay in seconds.
	 */
	public function test_defer_uses_the_retry_cadence_from_started_at( int $age, int $expected_delay ): void {
		$sut       = $this->require_sut();
		$scheduler = $this->require_scheduler();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$scheduler->cancel( $record['generation'], 1 );
		$aged               = $record;
		$aged['revision']   = $record['revision'] + 1;
		$aged['started_at'] = time() - $age;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $record, $aged ) );
		$record = $aged;
		$sut->handle_reconcile( $record['generation'], 1 );
		$claimed = $this->require_state_store()->get_record();
		$this->assertIsArray( $claimed );
		$before = time();

		$this->assertTrue( $sut->defer( $claimed, array( 'native_transport_unavailable' ) ) );

		$after    = time();
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( 1, $deferred['attempt'] );
		$this->assertSame( array( 'native_transport_unavailable' ), $deferred['deferred_codes'] );
		$this->assertGreaterThanOrEqual( $before + $expected_delay, $deferred['next_attempt_at'] );
		$this->assertLessThanOrEqual( $after + $expected_delay, $deferred['next_attempt_at'] );
		$this->assertSame( $deferred['action_id'], $scheduler->get_scheduled_action_id( $deferred['generation'], 2 ) );
	}

	/**
	 * @testdox Registration repairs a state whose initial Action Scheduler insert failed.
	 */
	public function test_register_repairs_a_failed_initial_schedule(): void {
		$sut    = $this->require_sut();
		$filter = static function (): int {
			return 0;
		};
		add_filter( 'pre_as_schedule_single_action', $filter, 10, 7 );
		try {
			$this->assertFalse( $sut->enqueue( 'merchant' ), 'A zero action ID should report a failed enqueue.' );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter, 10 );
		}

		$failed_record = $this->require_state_store()->get_record();
		$this->assertIsArray( $failed_record );
		$this->assertSame( 0, $failed_record['action_id'], 'The durable record should expose that scheduling did not succeed.' );

		$sut->register();
		$repaired_record = $this->require_state_store()->get_record();

		$this->assertIsArray( $repaired_record );
		$this->assertGreaterThan( 0, $repaired_record['action_id'] );
		$this->assertSame( $repaired_record['action_id'], $this->require_scheduler()->get_scheduled_action_id( $repaired_record['generation'], 1 ) );
	}

	/**
	 * @testdox Late registration repairs pending state when its recorded action was deleted.
	 */
	public function test_late_register_repairs_pending_state_after_action_was_deleted(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$old_action_id = $record['action_id'];
		ActionScheduler::store()->delete_action( $old_action_id );
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'The fixture should exercise the late-registration fallback.' );

		$sut->register();
		$repaired = $this->require_state_store()->get_record();

		$this->assertIsArray( $repaired );
		$this->assertGreaterThan( 0, $repaired['action_id'] );
		$this->assertNotSame( $old_action_id, $repaired['action_id'] );
		$this->assertSame( 1, $this->count_cutover_actions() );
	}

	/**
	 * @testdox Late registration repairs pending state after its recorded action failed.
	 */
	public function test_late_register_repairs_pending_state_after_action_failed(): void {
		$old_action_id = $this->prepare_pending_action();
		ActionScheduler::store()->mark_failure( $old_action_id );

		$this->require_sut()->register();

		$this->assert_repaired_action_replaced( $old_action_id );
	}

	/**
	 * @testdox Late registration repairs pending state after its recorded action was canceled.
	 */
	public function test_late_register_repairs_pending_state_after_action_was_canceled(): void {
		$old_action_id = $this->prepare_pending_action();
		$record        = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );

		$this->require_sut()->register();

		$this->assert_repaired_action_replaced( $old_action_id );
	}

	/**
	 * @testdox Late registration repairs pending state after its recorded action completed without advancing state.
	 */
	public function test_late_register_repairs_pending_state_after_action_completed(): void {
		$old_action_id = $this->prepare_pending_action();
		ActionScheduler::store()->mark_complete( $old_action_id );

		$this->require_sut()->register();

		$this->assert_repaired_action_replaced( $old_action_id );
	}

	/**
	 * @testdox Late registration repairs deferred state when its successor disappeared.
	 */
	public function test_late_register_repairs_deferred_state_after_action_was_deleted(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
		$claimed = $this->require_state_store()->get_record();
		$this->assertIsArray( $claimed );
		$this->assertTrue( $sut->defer( $claimed, array( 'native_transport_unavailable' ) ) );
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$old_action_id = $deferred['action_id'];
		ActionScheduler::store()->delete_action( $old_action_id );

		$sut->register();

		$this->assert_repaired_action_replaced( $old_action_id );
	}

	/**
	 * @testdox Pre-init registration defers repair until Action Scheduler initialization.
	 */
	public function test_pre_init_register_repairs_only_after_action_scheduler_init(): void {
		global $wp_actions, $wp_filter;

		$old_action_id = $this->prepare_pending_action();
		ActionScheduler::store()->delete_action( $old_action_id );
		$previous_count = $wp_actions['action_scheduler_init'] ?? null;
		$previous_hook  = $wp_filter['action_scheduler_init'] ?? null;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate the Action Scheduler pre-init lifecycle, then restore it in finally.
		$wp_actions['action_scheduler_init'] = 0;
		unset( $wp_filter['action_scheduler_init'] );

		try {
			$this->require_sut()->register();

			$this->assertSame( 0, $this->count_cutover_actions(), 'Registration before Action Scheduler init should only attach recovery.' );
			$this->assertSame( 10, has_action( 'action_scheduler_init', array( $this->require_sut(), 'handle_action_scheduler_init' ) ) );

			do_action( 'action_scheduler_init' );

			$this->assert_repaired_action_replaced( $old_action_id );
		} finally {
			if ( null === $previous_count ) {
				unset( $wp_actions['action_scheduler_init'] );
			} else {
				$wp_actions['action_scheduler_init'] = $previous_count;
			}

			if ( null === $previous_hook ) {
				unset( $wp_filter['action_scheduler_init'] );
			} else {
				$wp_filter['action_scheduler_init'] = $previous_hook;
			}
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * @testdox Late registration without cutover state does not write a coordination lease.
	 */
	public function test_register_without_state_does_not_write_a_lease(): void {
		$lease_events = $this->capture_lease_write_events(
			function (): void {
				$this->require_sut()->register();
			}
		);

		$this->assertSame( array(), $lease_events, 'An absent state has nothing for registration to repair.' );
		$this->assertNull( get_option( WooPaymentsCutoverStateStore::LEASE_OPTION_NAME, null ) );
	}

	/**
	 * @testdox Late registration with terminal state does not write a coordination lease.
	 * @testWith ["done"]
	 *           ["excluded"]
	 *
	 * @param string $state Terminal state.
	 */
	public function test_register_with_terminal_state_does_not_write_a_lease( string $state ): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$terminal                 = $pending;
		$terminal['revision']     = $pending['revision'] + 1;
		$terminal['state']        = $state;
		$terminal['action_id']    = 0;
		$terminal['current_step'] = $state;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $terminal ) );

		$lease_events = $this->capture_lease_write_events(
			function () use ( $sut ): void {
				$sut->register();
			}
		);

		$this->assertSame( array(), $lease_events, 'Terminal state cannot be repaired by the local scheduler.' );
		$this->assertSame( $terminal, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox Registration recovers a stale running claim into one immediately due deferred attempt.
	 */
	public function test_register_recovers_stale_running_state(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );
		$sut->handle_reconcile( $record['generation'], 1 );
		$running = $this->require_state_store()->get_record();
		$this->assertIsArray( $running );
		$expired                     = $running;
		$expired['revision']         = $running['revision'] + 1;
		$expired['updated_at']       = time() - WooPaymentsCutoverReconciliationJob::RUNNING_TIMEOUT - 1;
		$expired['lease_expires_at'] = time() - 1;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $running, $expired ) );
		$before = time();

		$sut->register();

		$after     = time();
		$recovered = $this->require_state_store()->get_record();
		$this->assertIsArray( $recovered );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $recovered['state'] );
		$this->assertSame( 'recovered_stale_running', $recovered['current_step'] );
		$this->assertGreaterThanOrEqual( $before, $recovered['next_attempt_at'] );
		$this->assertLessThanOrEqual( $after, $recovered['next_attempt_at'] );
		$this->assertSame( $recovered['action_id'], $this->require_scheduler()->get_scheduled_action_id( $recovered['generation'], 2 ) );
	}

	/**
	 * @testdox A fresh running claim is left for its current worker rather than repaired concurrently.
	 */
	public function test_register_does_not_repair_a_fresh_running_state(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );
		$sut->handle_reconcile( $record['generation'], 1 );
		$running = $this->require_state_store()->get_record();

		$lease_events = $this->capture_lease_write_events(
			function () use ( $sut ): void {
				$sut->register();
			}
		);

		$this->assertSame( $running, $this->require_state_store()->get_record() );
		$this->assertSame( 0, $this->count_cutover_actions() );
		$this->assertSame( array(), $lease_events, 'A live running claim is not repairable.' );
	}

	/**
	 * @testdox Duplicate and stale callbacks cannot reclaim a generation or advance its attempt.
	 */
	public function test_handle_reconcile_ignores_duplicate_and_stale_callbacks(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );

		$sut->handle_reconcile( 2, 1 );
		$this->assertSame( $record, $this->require_state_store()->get_record(), 'A callback for another generation must not mutate current state.' );

		$sut->handle_reconcile( 1, 1 );
		$claimed = $this->require_state_store()->get_record();
		$this->assertIsArray( $claimed );
		$this->assertSame( WooPaymentsCutoverState::RUNNING, $claimed['state'] );
		$this->assertSame( $record['revision'] + 1, $claimed['revision'] );
		$this->assertIsString( $claimed['lease_token'] );
		$this->assertNotSame( '', $claimed['lease_token'] );
		$this->assertIsInt( $claimed['lease_expires_at'] );
		$sut->handle_reconcile( 1, 1 );

		$this->assertSame( $claimed, $this->require_state_store()->get_record(), 'A duplicate callback must not reclaim an already running attempt.' );
	}

	/**
	 * @testdox The public callback safely ignores malformed hook arguments before writing a lease.
	 * @testWith ["invalid", 1]
	 *           [1, "invalid"]
	 *           [null, 1]
	 *           [1, null]
	 *           [true, 1]
	 *           [1, false]
	 *           [[], 1]
	 *           [1, []]
	 *           [1.5, 1]
	 *           [1, 1.5]
	 *           [0, 1]
	 *           [1, 0]
	 *
	 * @param mixed $generation Hook generation value.
	 * @param mixed $attempt    Hook attempt value.
	 */
	public function test_handle_reconcile_safely_ignores_malformed_hook_arguments( $generation, $attempt ): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$thrown = null;

		$lease_events = $this->capture_lease_write_events(
			static function () use ( $sut, $generation, $attempt, &$thrown ): void {
				try {
					$sut->handle_reconcile( $generation, $attempt );
				} catch ( \Throwable $error ) {
					$thrown = $error;
				}
			}
		);

		$this->assertNull( $thrown, 'Malformed public hook values must not cause a TypeError.' );
		$this->assertSame( array(), $lease_events, 'Malformed hook values should be rejected before coordination.' );
		$this->assertSame( $pending, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox The public callback coerces positive integer strings before claiming the scheduled attempt.
	 */
	public function test_handle_reconcile_coerces_positive_integer_strings(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( (string) $pending['generation'], '1' );

		$claimed = $this->require_state_store()->get_record();
		$this->assertIsArray( $claimed );
		$this->assertSame( WooPaymentsCutoverState::RUNNING, $claimed['state'] );
		$this->assertSame( 1, $claimed['attempt'] );
	}

	/**
	 * @testdox Claiming an attempt retains only the newest bounded diagnostic steps.
	 */
	public function test_handle_reconcile_bounds_the_step_log(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );
		$filled             = $record;
		$filled['revision'] = $record['revision'] + 1;
		$filled['step_log'] = array();
		for ( $index = 0; $index < WooPaymentsCutoverStateStore::MAX_STEP_LOG_ENTRIES; ++$index ) {
			$filled['step_log'][] = array(
				'step' => 'step-' . $index,
				'at'   => $record['updated_at'] + $index,
			);
		}
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $record, $filled ) );

		$sut->handle_reconcile( $filled['generation'], 1 );

		$claimed = $this->require_state_store()->get_record();
		$this->assertIsArray( $claimed );
		$this->assertCount( WooPaymentsCutoverStateStore::MAX_STEP_LOG_ENTRIES, $claimed['step_log'] );
		$this->assertSame( 'step-1', $claimed['step_log'][0]['step'] );
		$this->assertSame( 'running', $claimed['step_log'][ WooPaymentsCutoverStateStore::MAX_STEP_LOG_ENTRIES - 1 ]['step'] );
	}

	/**
	 * @testdox An expired claim cannot persist a deferred completion before repair fences it.
	 */
	public function test_defer_rejects_an_expired_running_claim(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );
		$sut->handle_reconcile( $record['generation'], 1 );
		$running = $this->require_state_store()->get_record();
		$this->assertIsArray( $running );
		$expired                     = $running;
		$expired['revision']         = $running['revision'] + 1;
		$expired['lease_expires_at'] = time() - 1;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $running, $expired ) );

		$this->assertFalse( $sut->defer( $expired, array( 'native_transport_unavailable' ) ) );
		$this->assertSame( $expired, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox A fenced terminal update prevents the stale claimant from persisting its completion.
	 */
	public function test_defer_rejects_a_claim_superseded_by_a_terminal_transition(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
		$claimed = $this->require_state_store()->get_record();
		$this->assertIsArray( $claimed );

		$excluded                     = $claimed;
		$excluded['revision']         = $claimed['revision'] + 1;
		$excluded['state']            = WooPaymentsCutoverState::EXCLUDED;
		$excluded['attempt']          = $claimed['attempt'] + 1;
		$excluded['lease_token']      = null;
		$excluded['lease_expires_at'] = null;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $claimed, $excluded ) );

		$this->assertFalse( $sut->defer( $claimed, array( 'native_transport_unavailable' ) ) );
		$this->assertSame( $excluded, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox A real running Action Scheduler callback can persist and schedule its successor.
	 */
	public function test_running_action_can_schedule_deferred_successor_inside_its_callback(): void {
		$sut = $this->require_sut();
		$sut->register();
		$defer_callback = function ( int $generation, int $attempt ) use ( $sut ): void {
			unset( $generation, $attempt );
			$claimed = $this->require_state_store()->get_record();
			$this->assertIsArray( $claimed );
			$sut->defer( $claimed, array( 'native_transport_unavailable' ) );
		};
		add_action( 'woocommerce_woopayments_cutover_reconcile', $defer_callback, 20, 2 );
		try {
			$sut->enqueue( 'merchant' );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );

			ActionScheduler_QueueRunner::instance()->process_action( $pending['action_id'], 'WooPayments cutover unit test' );
		} finally {
			remove_action( 'woocommerce_woopayments_cutover_reconcile', $defer_callback, 20 );
		}

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $pending['action_id'] ) );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertGreaterThan( 0, $deferred['action_id'] );
		$this->assertSame( $deferred['action_id'], $this->require_scheduler()->get_scheduled_action_id( $deferred['generation'], 2 ) );
	}

	/**
	 * Create a job with a deterministic native-runtime answer.
	 *
	 * @param bool $native_enabled Whether native payments are enabled.
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function create_job( bool $native_enabled ): WooPaymentsCutoverReconciliationJob {
		$arbiter = new class( $native_enabled ) extends NativePaymentsRuntimeArbiter {
			/** @var bool */
			private bool $native_enabled;

			/**
			 * Initialize the static runtime answer.
			 *
			 * @param bool $native_enabled Whether native payments are enabled.
			 */
			public function __construct( bool $native_enabled ) {
				$this->native_enabled = $native_enabled;
			}

			/** Return the configured native feature state. */
			public function is_native_runtime_enabled(): bool {
				return $this->native_enabled;
			}
		};

		$job = new WooPaymentsCutoverReconciliationJob();
		$job->init( $arbiter, $this->require_state_store(), $this->require_scheduler() );
		$this->jobs[] = $job;

		return $job;
	}

	/**
	 * Require the job after the initial class-existence red assertion.
	 *
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function require_sut(): WooPaymentsCutoverReconciliationJob {
		$this->assertTrue( class_exists( WooPaymentsCutoverReconciliationJob::class ), 'The cutover reconciliation job has not been implemented yet.' );
		$this->assertInstanceOf( WooPaymentsCutoverReconciliationJob::class, $this->sut, 'The cutover reconciliation job has not been implemented yet.' );

		return $this->sut;
	}

	/**
	 * Require the state store fixture.
	 *
	 * @return WooPaymentsCutoverStateStore
	 */
	private function require_state_store(): WooPaymentsCutoverStateStore {
		$this->assertTrue( class_exists( WooPaymentsCutoverStateStore::class ), 'The cutover state store has not been implemented yet.' );
		$this->assertInstanceOf( WooPaymentsCutoverStateStore::class, $this->state_store, 'The cutover state store has not been implemented yet.' );

		return $this->state_store;
	}

	/**
	 * Require the scheduler fixture.
	 *
	 * @return WooPaymentsCutoverActionScheduler
	 */
	private function require_scheduler(): WooPaymentsCutoverActionScheduler {
		$this->assertTrue( class_exists( WooPaymentsCutoverActionScheduler::class ), 'The cutover scheduler has not been implemented yet.' );
		$this->assertInstanceOf( WooPaymentsCutoverActionScheduler::class, $this->scheduler, 'The cutover scheduler has not been implemented yet.' );

		return $this->scheduler;
	}

	/**
	 * Create one pending job and return its recorded action ID.
	 *
	 * @return int
	 */
	private function prepare_pending_action(): int {
		$this->require_sut()->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );

		return $record['action_id'];
	}

	/**
	 * Assert that repair recorded one new pending action.
	 *
	 * @param int $old_action_id Superseded action ID.
	 */
	private function assert_repaired_action_replaced( int $old_action_id ): void {
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->assertGreaterThan( 0, $record['action_id'] );
		$this->assertNotSame( $old_action_id, $record['action_id'] );
		$this->assertSame( $record['action_id'], $this->require_scheduler()->get_scheduled_action_id( $record['generation'], $record['attempt'] + 1 ) );
		$this->assertSame( 1, $this->count_cutover_actions() );
	}

	/**
	 * Capture real writes to the option-backed coordination lease.
	 *
	 * @param callable():void $operation Operation whose lease writes should be observed.
	 * @return array<int,string> Option lifecycle hooks observed for the lease.
	 */
	private function capture_lease_write_events( callable $operation ): array {
		$events   = array();
		$observer = static function ( string $option ) use ( &$events ): void {
			if ( WooPaymentsCutoverStateStore::LEASE_OPTION_NAME === $option ) {
				$events[] = current_filter();
			}
		};
		add_action( 'added_option', $observer, 10, 1 );
		add_action( 'deleted_option', $observer, 10, 1 );

		try {
			$operation();
		} finally {
			remove_action( 'added_option', $observer, 10 );
			remove_action( 'deleted_option', $observer, 10 );
		}

		return $events;
	}

	/**
	 * Count pending or running cutover actions.
	 *
	 * @return int
	 */
	private function count_cutover_actions(): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'   => 'woocommerce_woopayments_cutover_reconcile',
					'group'  => 'woocommerce_woopayments_cutover',
					'status' => array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ),
				)
			)
		);
	}

	/**
	 * Delete state and cancel test actions.
	 */
	private function cleanup_state(): void {
		delete_option( WooPaymentsCutoverStateStore::OPTION_NAME );
		delete_option( WooPaymentsCutoverStateStore::LEASE_OPTION_NAME );

		foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$action_ids = as_get_scheduled_actions(
				array(
					'hook'     => 'woocommerce_woopayments_cutover_reconcile',
					'group'    => 'woocommerce_woopayments_cutover',
					'status'   => $status,
					'per_page' => -1,
				),
				'ids'
			);

			foreach ( $action_ids as $action_id ) {
				ActionScheduler::store()->cancel_action( (int) $action_id );
			}
		}
	}
}
