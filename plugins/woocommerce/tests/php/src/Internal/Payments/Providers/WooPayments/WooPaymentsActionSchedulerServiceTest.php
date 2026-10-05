<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsActionSchedulerService class.
 */
class WooPaymentsActionSchedulerServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private $sut;

	/**
	 * Test hook name.
	 *
	 * @var string
	 */
	private string $hook = 'wcpay_native_payments_test_action';

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsActionSchedulerService::class );
		$this->unschedule_test_actions();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->unschedule_test_actions();
		parent::tearDown();
	}

	/**
	 * @testdox The scheduler uses the preserved WooPayments group.
	 */
	public function test_group_id_preserves_woopayments_group(): void {
		$this->assertSame( 'woocommerce_payments', WooPaymentsActionSchedulerService::GROUP_ID );
	}

	/**
	 * @testdox Jobs asked for before Action Scheduler initializes are scheduled once when it does, one per args and group, with the latest timestamp (G1-2).
	 *
	 * Before action_scheduler_init, Action Scheduler's functions return without scheduling; client 11.1.0
	 * `class-wc-payments-action-scheduler-service.php:220-249` keeps one deferred job per hook, args and group.
	 */
	public function test_schedule_job_before_action_scheduler_init_waits_for_it(): void {
		$sut   = new WooPaymentsActionSchedulerService();
		$later = time() + 120;

		$this->while_action_scheduler_is_not_initialized(
			function () use ( $sut, $later ): void {
				$sut->schedule_job( $this->hook, array( 'event_id' => 'evt_deferred' ), time() + 60 );
				$sut->schedule_job( $this->hook, array( 'event_id' => 'evt_deferred' ), $later );
				$sut->schedule_job( $this->hook, array( 'event_id' => 'evt_other' ), $later );
				$sut->schedule_job( $this->hook, array( 'event_id' => 'evt_deferred' ), $later, 'woocommerce-payments' );
			}
		);

		$this->assertSame( 0, $this->count_pending_actions( $this->hook, array( 'event_id' => 'evt_deferred' ) ), 'Nothing is scheduled before Action Scheduler initializes.' );
		// Run the service's own callback, not Action Scheduler's initialization.
		$sut->handle_action_scheduler_init();

		$this->assertSame( array( $later ), $this->get_pending_timestamps( array( 'event_id' => 'evt_deferred' ) ), 'The latest timestamp wins.' );
		$this->assertCount( 1, $this->get_pending_timestamps( array( 'event_id' => 'evt_other' ) ), 'Other args are their own job.' );
		$this->assertSame( 1, $this->count_pending_actions( $this->hook, array( 'event_id' => 'evt_deferred' ), 'woocommerce-payments' ), 'Another group is its own job.' );
		$this->assertFalse( has_action( 'action_scheduler_init', array( $sut, 'handle_action_scheduler_init' ) ), 'The callback runs once.' );
	}

	/**
	 * @testdox Once Action Scheduler runs, a job already pending keeps its first timestamp (recorded difference: the client replaces it).
	 */
	public function test_schedule_job_keeps_the_first_timestamp_of_a_pending_job(): void {
		$first = time() + 60;

		$this->sut->schedule_job( $this->hook, array( 'event_id' => 'evt_first' ), $first );
		$this->sut->schedule_job( $this->hook, array( 'event_id' => 'evt_first' ), time() + 120 );

		$this->assertSame( array( $first ), $this->get_pending_timestamps( array( 'event_id' => 'evt_first' ) ) );
	}

	/**
	 * @testdox A job asked for before Action Scheduler initializes, on another blog of a network, is scheduled for that blog.
	 * @group multisite
	 */
	public function test_deferred_job_is_scheduled_on_the_blog_it_was_asked_for(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$sut     = new WooPaymentsActionSchedulerService();
		$blog_id = self::factory()->blog->create();
		$this->while_action_scheduler_is_not_initialized(
			function () use ( $sut, $blog_id ): void {
				switch_to_blog( $blog_id );
				try {
					$sut->schedule_job( $this->hook, array( 'event_id' => 'evt_blog' ) );
				} finally {
					restore_current_blog();
				}
			}
		);
		// A test blog has no Action Scheduler tables, so record the blog each insert runs on and stop it there.
		$scheduled_on = array();
		$record       = function ( $pre, $timestamp, $hook ) use ( &$scheduled_on ) {
			unset( $timestamp );
			if ( $this->hook === $hook ) {
				$scheduled_on[] = get_current_blog_id();
				return 1;
			}
			return $pre;
		};
		add_filter( 'pre_as_schedule_single_action', $record, 10, 3 );

		try {
			$sut->handle_action_scheduler_init();
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $record, 10 );
		}

		$this->assertSame( array( $blog_id ), $scheduled_on );
		$this->assertSame( get_main_site_id(), get_current_blog_id(), 'The blog is restored after scheduling.' );
	}

	/**
	 * Run a callback while WordPress reports that action_scheduler_init has not fired.
	 *
	 * @param callable $callback Callback.
	 */
	private function while_action_scheduler_is_not_initialized( callable $callback ): void {
		global $wp_actions;
		$fired = $wp_actions['action_scheduler_init'] ?? null;
		unset( $wp_actions['action_scheduler_init'] );
		try {
			$callback();
		} finally {
			if ( null !== $fired ) {
				$wp_actions['action_scheduler_init'] = $fired; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the count the test hid.
			}
		}
	}

	/**
	 * Get the timestamps of pending test actions with the given args in the canonical group.
	 *
	 * @param array<string,mixed> $args Action args.
	 * @return int[]
	 */
	private function get_pending_timestamps( array $args ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => $this->hook,
				'args'   => $args,
				'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		return array_values( array_map( static fn( $action ): int => $action->get_schedule()->get_date()->getTimestamp(), $actions ) );
	}

	/**
	 * @testdox Scheduling avoids duplicate pending actions with the same hook, args, and group.
	 */
	public function test_schedule_job_avoids_duplicate_pending_actions(): void {
		$args = array( 'event_id' => 'evt_123' );

		$this->sut->schedule_job( $this->hook, $args );
		$this->sut->schedule_job( $this->hook, $args );

		$this->assertSame( 1, $this->count_pending_actions( $this->hook, $args ) );
	}

	/**
	 * @testdox Scheduling under a given group avoids duplicate pending actions in that group.
	 *
	 * Client 11.1.0 class-wc-payments-action-scheduler-service.php:207-220 takes an optional group
	 * and only replaces a job with the same hook, args and group.
	 */
	public function test_schedule_job_avoids_duplicate_pending_actions_in_given_group(): void {
		$args = array( 'stage' => 7 );

		$this->sut->schedule_job( $this->hook, $args, null, 'woocommerce-payments' );
		$this->sut->schedule_job( $this->hook, $args, null, 'woocommerce-payments' );

		$this->assertSame( 1, $this->count_pending_actions( $this->hook, $args, 'woocommerce-payments' ) );
		$this->assertSame( 0, $this->count_pending_actions( $this->hook, $args ) );
	}

	/**
	 * @testdox Scheduling does not pass the hook-unique flag so per-event args stay schedulable.
	 */
	public function test_schedule_job_does_not_pass_unique_flag(): void {
		$captured_unique = null;
		$filter          = function ( $pre, $timestamp, $hook, $args, $group, $priority, $unique ) use ( &$captured_unique ) {
			// Avoid parameter not used PHPCS errors.
			unset( $pre, $timestamp, $hook, $args, $group, $priority );
			$captured_unique = $unique;

			// Short-circuit the real Action Scheduler insert with a fake action ID.
			return 1;
		};

		add_filter( 'pre_as_schedule_single_action', $filter, 10, 7 );
		try {
			$this->sut->schedule_job( $this->hook, array( 'event_id' => 'evt_unique' ) );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter, 10 );
		}

		$this->assertFalse( $captured_unique, 'schedule_job() must not pass Action Scheduler hook-level uniqueness; the pending pre-check already dedupes by hook, args, and group.' );
	}

	/**
	 * @testdox Scheduling keeps distinct args as distinct actions.
	 */
	public function test_schedule_job_keeps_distinct_args_as_distinct_actions(): void {
		$this->sut->schedule_job( $this->hook, array( 'event_id' => 'evt_123' ) );
		$this->sut->schedule_job( $this->hook, array( 'event_id' => 'evt_456' ) );

		$this->assertSame( 1, $this->count_pending_actions( $this->hook, array( 'event_id' => 'evt_123' ) ) );
		$this->assertSame( 1, $this->count_pending_actions( $this->hook, array( 'event_id' => 'evt_456' ) ) );
	}

	/**
	 * @testdox A running action does not block scheduling the next page.
	 */
	public function test_schedule_job_does_not_treat_running_actions_as_pending_duplicates(): void {
		$args      = array();
		$action_id = as_schedule_single_action( time(), $this->hook, $args, 'woocommerce_payments' );

		ActionScheduler::store()->log_execution( $action_id );

		$this->sut->schedule_job( $this->hook, $args );

		$this->assertSame( 1, $this->count_pending_actions( $this->hook, $args ) );

		ActionScheduler::store()->mark_complete( $action_id );
	}

	/**
	 * Count pending test actions.
	 *
	 * @param string              $hook Hook name.
	 * @param array<string,mixed> $args Action args.
	 * @param string              $group Action Scheduler group.
	 * @return int
	 */
	private function count_pending_actions( string $hook, array $args, string $group = 'woocommerce_payments' ): int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'args'   => $args,
				'group'  => $group,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		return count( $actions );
	}

	/**
	 * Remove test actions from Action Scheduler.
	 */
	private function unschedule_test_actions(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			foreach ( array( 'woocommerce_payments', 'woocommerce-payments' ) as $group ) {
				$action_ids = as_get_scheduled_actions(
					array(
						'hook'   => $this->hook,
						'group'  => $group,
						'status' => $status,
					),
					'ids'
				);

				foreach ( $action_ids as $action_id ) {
					ActionScheduler::store()->cancel_action( (int) $action_id );
				}
			}
		}
	}
}
