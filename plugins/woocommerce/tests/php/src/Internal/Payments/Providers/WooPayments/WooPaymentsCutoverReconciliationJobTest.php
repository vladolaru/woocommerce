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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
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
	 * Headless preflight fixture.
	 *
	 * @var WooPaymentsCutoverPreflightService|null
	 */
	private ?WooPaymentsCutoverPreflightService $preflight_service = null;

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
			$this->state_store       = new WooPaymentsCutoverStateStore();
			$this->scheduler         = new WooPaymentsCutoverActionScheduler();
			$this->preflight_service = new WooPaymentsCutoverPreflightService();
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
	 * @testdox The job receives the explicitly injected headless preflight service.
	 */
	public function test_job_receives_the_explicit_preflight_service(): void {
		$job      = $this->require_sut();
		$property = new \ReflectionProperty( $job, 'preflight_service' );
		$property->setAccessible( true );

		$this->assertSame( $this->preflight_service, $property->getValue( $job ) );
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
	 * @testdox Each reconciliation condition receives one durable disposition.
	 *
	 * @dataProvider reconciliation_disposition_provider
	 *
	 * @param string $condition      Reconciliation condition.
	 * @param string $expected_state Expected persisted state.
	 */
	public function test_reconciliation_dispositions_are_exclusive( string $condition, string $expected_state ): void {
		$preflight = $this->create_preflight_with_failures( array( $condition ) );
		$sut       = $this->create_job_with_preflight( 'native_runtime_disabled' !== $condition, $preflight );

		if ( 'native_runtime_disabled' === $condition ) {
			$this->assertFalse( $sut->enqueue( 'merchant' ) );
			$this->assertNull( $this->require_state_store()->get_record() );
			return;
		}

		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->register();
		$sut->handle_reconcile( $pending['generation'], 1 );

		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->assertSame( $expected_state, $record['state'] );
		$this->assertNotSame( WooPaymentsCutoverState::RUNNING, $record['state'], 'A disposition must not leave its claimed record running.' );
	}

	/**
	 * Provide each known reconciliation condition and its one disposition state.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function reconciliation_disposition_provider(): array {
		return array(
			'native runtime disabled'                    => array( 'native_runtime_disabled', 'none' ),
			'unsupported WooPayments version'            => array( 'woopayments_plugin_version_unsupported', WooPaymentsCutoverState::DEFERRED ),
			'unsupported payment methods'                => array( 'unsupported_payment_methods_enabled', WooPaymentsCutoverState::DEFERRED ),
			'operational queue hooks'                    => array( 'operational_queue_hooks_undispositioned', WooPaymentsCutoverState::DEFERRED ),
			'legacy Stripe Billing subscriptions'        => array( 'legacy_stripe_billing_subscriptions_present', WooPaymentsCutoverState::EXCLUDED ),
			'native transport unavailable'               => array( 'native_transport_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'multi-currency rates unavailable'           => array( 'multi_currency_rates_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'native admin surfaces unavailable'          => array( 'native_admin_surfaces_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'provider events undispositioned'            => array( 'provider_events_undispositioned', WooPaymentsCutoverState::DEFERRED ),
			'financial migrations unavailable'           => array( 'financial_migrations_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com blog ID unavailable'          => array( 'wpcom_blog_id_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com connection unavailable'       => array( 'wpcom_connection_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com connection owner unavailable' => array( 'wpcom_connection_owner_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com owner token unavailable'      => array( 'wpcom_connection_owner_user_token_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'invalid final preflight filter'             => array( 'preflight_filter_invalid', WooPaymentsCutoverState::DEFERRED ),
			'invalid provider-events filter'             => array( 'provider_events_filter_invalid', WooPaymentsCutoverState::DEFERRED ),
			'invalid operational-queue filter'           => array( 'operational_queue_hooks_filter_invalid', WooPaymentsCutoverState::DEFERRED ),
		);
	}

	/**
	 * @testdox A missing connection owner records the sanctioned reconnect information once while retrying.
	 */
	public function test_missing_connection_owner_records_reconnect_information_once(): void {
		$preflight = $this->create_preflight_with_failures( array( 'wpcom_connection_owner_user_token_unavailable' ), true );
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->register();
		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$sut->handle_reconcile( $deferred['generation'], 2 );

		$replayed = $this->require_state_store()->get_record();
		$this->assertIsArray( $replayed );
		$reconnect_outcomes = array_filter(
			$replayed['informational_outcomes'],
			static function ( $outcome ): bool {
				return array( 'code' => 'reconnect_required' ) === $outcome;
			}
		);
		$this->assertCount( 1, $reconnect_outcomes );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
	}

	/**
	 * @testdox Replaying an invalid-filter observation does not persist another diagnostic outcome.
	 *
	 * @dataProvider engineering_error_condition_provider
	 *
	 * @param string $condition Invalid-filter condition code.
	 */
	public function test_replayed_invalid_filter_observation_is_persisted_once( string $condition ): void {
		$preflight = $this->create_preflight_with_failures( array( $condition ) );
		$job       = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var array<int,array<string,mixed>> */
			private array $logged_errors = array();

			/** @var string[] */
			private array $tracked_diagnostics = array();

			/**
			 * Record controlled diagnostic logging.
			 *
			 * @param string              $message Error message.
			 * @param array<string,mixed> $context Error context.
			 */
			protected function write_log_error( string $message, array $context ): void {
				$this->logged_errors[] = array_merge( array( 'message' => $message ), $context );
			}

			/**
			 * Record controlled Tracks diagnostics.
			 *
			 * @param string $code Invalid-filter condition code.
			 */
			protected function record_tracks_diagnostic( string $code ): void {
				$this->tracked_diagnostics[] = $code;
			}

			/** @return array<int,array<string,mixed>> */
			public function get_logged_errors(): array {
				return $this->logged_errors;
			}

			/** @return string[] */
			public function get_tracked_diagnostics(): array {
				return $this->tracked_diagnostics;
			}
		};
		$sut       = $this->create_job( true, $preflight, $job );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->register();

		$sut->handle_reconcile( $pending['generation'], 1 );
		$first = $this->require_state_store()->get_record();
		$this->assertIsArray( $first );
		$sut->handle_reconcile( $first['generation'], 2 );

		$second = $this->require_state_store()->get_record();
		$this->assertIsArray( $second );
		$diagnostics = array_filter(
			$second['informational_outcomes'],
			static function ( $outcome ) use ( $condition ): bool {
				return array(
					'code'      => 'diagnostic_observed',
					'condition' => $condition,
				) === $outcome;
			}
		);
		$this->assertCount( 1, $diagnostics );
		$this->assertCount( 1, $job->get_logged_errors() );
		$this->assertSame( 'woocommerce-woopayments-cutover', $job->get_logged_errors()[0]['source'] );
		$this->assertSame( array( $condition ), $job->get_tracked_diagnostics() );
	}

	/**
	 * Provide all invalid-filter condition codes that are diagnosed once per persisted observation.
	 *
	 * @return array<string,array{string}>
	 */
	public function engineering_error_condition_provider(): array {
		return array(
			'preflight filter'         => array( 'preflight_filter_invalid' ),
			'provider-events filter'   => array( 'provider_events_filter_invalid' ),
			'operational-queue filter' => array( 'operational_queue_hooks_filter_invalid' ),
		);
	}

	/**
	 * @testdox A thrown resolver is deferred instead of leaving its claim running.
	 */
	public function test_thrown_resolver_defers_the_claim(): void {
		$preflight = $this->create_preflight_with_failures( array(), false, true );
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->register();

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'reconciliation_resolver_failed' ), $deferred['deferred_codes'] );
	}

	/**
	 * @testdox Reconciliation adopts native queue callbacks and cancels pending legacy migrators.
	 */
	public function test_reconciliation_adopts_native_queue_callbacks_and_cancels_legacy_migrators(): void {
		$native_action_id   = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_store_setup_sync', array(), 'cutover-test', false );
		$migrator_action_id = as_schedule_single_action( time() - MINUTE_IN_SECONDS, 'wcpay_migrate_subscription_retry', array(), 'cutover-test', false );
		$this->assertIsInt( $native_action_id );
		$this->assertIsInt( $migrator_action_id );
		$preflight = $this->create_preflight_with_failures(
			array( 'operational_queue_hooks_undispositioned' ),
			false,
			false,
			array(
				array(
					'action_id' => $native_action_id,
					'hook'      => 'wcpay_store_setup_sync',
					'group'     => 'cutover-test',
				),
				array(
					'action_id' => $migrator_action_id,
					'hook'      => 'wcpay_migrate_subscription_retry',
					'group'     => 'cutover-test',
				),
			)
		);
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->register();
		$canceled_action_ids = array();
		$record_cancellation = static function ( $action_id ) use ( &$canceled_action_ids ): void {
			$canceled_action_ids[] = $action_id;
		};
		add_action( 'action_scheduler_canceled_action', $record_cancellation );

		try {
			$sut->handle_reconcile( $pending['generation'], 1 );
		} finally {
			remove_action( 'action_scheduler_canceled_action', $record_cancellation );
		}

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $native_action_id ) );
		$this->assertSame( ActionScheduler_Store::STATUS_CANCELED, ActionScheduler::store()->get_status( $migrator_action_id ) );
		$this->assertContains(
			array(
				'code' => 'operational_action_adopted',
				'hook' => 'wcpay_store_setup_sync',
			),
			$deferred['informational_outcomes']
		);
		$this->assertContains(
			array(
				'code' => 'legacy_migrator_canceled',
				'hook' => 'wcpay_migrate_subscription_retry',
			),
			$deferred['informational_outcomes']
		);
		$this->assertSame( array( $migrator_action_id ), $canceled_action_ids );
		$store = new \ActionScheduler_DBStore();
		$claim = $store->stake_claim( 1, new \DateTime( '@' . time() ), array( 'wcpay_migrate_subscription_retry' ), 'cutover-test' );
		try {
			$this->assertNotContains( $migrator_action_id, $claim->get_actions() );
		} finally {
			$store->release_claim( $claim );
		}
	}

	/**
	 * @testdox A lost pending-action cancellation race leaves its migration blocker deferred and unadopted.
	 */
	public function test_lost_pending_migrator_cancellation_race_defers_without_a_canceled_outcome(): void {
		$hook      = 'wcpay_migrate_subscription_retry';
		$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook, array(), 'cutover-test', false );
		$this->assertIsInt( $action_id );
		$preflight = $this->create_preflight_with_failures(
			array( 'operational_queue_hooks_undispositioned' ),
			false,
			false,
			array(
				array(
					'action_id' => $action_id,
					'hook'      => $hook,
					'group'     => 'cutover-test',
				),
			)
		);
		$job       = new class() extends WooPaymentsCutoverReconciliationJob {
			/**
			 * Simulate a runner claiming the migrator immediately before its conditional cancellation.
			 *
			 * @param int $action_id Action Scheduler action ID.
			 * @return bool
			 */
			protected function cancel_pending_legacy_migrator( int $action_id ): bool {
				return false;
			}
		};
		$sut       = $this->create_job( true, $preflight, $job );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'operational_queue_hooks_undispositioned' ), $deferred['deferred_codes'] );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $action_id ) );
		$this->assertNotContains(
			array(
				'code' => 'legacy_migrator_canceled',
				'hook' => $hook,
			),
			$deferred['informational_outcomes']
		);
	}

	/**
	 * @testdox A claimed custom-table migrator cannot be canceled by reconciliation's pending-only compare-and-set.
	 */
	public function test_claimed_pending_migrator_remains_queued_when_conditional_cancellation_loses_its_compare_and_set(): void {
		$hook      = 'wcpay_migrate_subscription_retry';
		$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook, array(), 'cutover-test', false );
		$this->assertIsInt( $action_id );
		global $wpdb;
		$this->assertSame(
			1,
			$wpdb->update(
				$wpdb->actionscheduler_actions,
				array( 'claim_id' => 123 ),
				array( 'action_id' => $action_id ),
				array( '%d' ),
				array( '%d' )
			)
		);
		ActionScheduler::store()->flush_caches();
		$preflight = $this->create_preflight_with_failures(
			array( 'operational_queue_hooks_undispositioned' ),
			false,
			false,
			array(
				array(
					'action_id' => $action_id,
					'hook'      => $hook,
					'group'     => 'cutover-test',
				),
			)
		);
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'operational_queue_hooks_undispositioned' ), $deferred['deferred_codes'] );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $action_id ) );
		$this->assertNotContains(
			array(
				'code' => 'legacy_migrator_canceled',
				'hook' => $hook,
			),
			$deferred['informational_outcomes']
		);
	}

	/**
	 * @testdox A lone native-owned fee-remediation action is adopted even after preflight removes its blocker.
	 */
	public function test_reconciliation_adopts_a_lone_native_owned_fee_remediation_action(): void {
		$hook      = WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK;
		$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook, array(), 'cutover-test', false );
		$this->assertIsInt( $action_id );
		$preflight = $this->create_preflight_with_failures(
			array(),
			false,
			false,
			array(
				array(
					'action_id' => $action_id,
					'hook'      => $hook,
					'group'     => 'cutover-test',
				),
			)
		);
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'finalization_pending' ), $deferred['deferred_codes'] );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $action_id ) );
		$sut->handle_reconcile( $deferred['generation'], 2 );

		$replayed = $this->require_state_store()->get_record();
		$this->assertIsArray( $replayed );
		$adopted_outcomes = array_filter(
			$replayed['informational_outcomes'],
			static function ( $outcome ) use ( $hook ): bool {
				return array(
					'code' => 'operational_action_adopted',
					'hook' => $hook,
				) === $outcome;
			}
		);
		$this->assertCount( 1, $adopted_outcomes );
		$this->assertContains(
			array(
				'code' => 'operational_action_adopted',
				'hook' => $hook,
			),
			$deferred['informational_outcomes']
		);
	}

	/**
	 * @testdox Plugin updates hold and release the WordPress core upgrader lock.
	 */
	public function test_plugin_update_uses_the_wordpress_core_upgrader_lock(): void {
		$job       = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var bool[] */
			private array $lock_observations = array();

			/** Refresh controlled update metadata. */
			protected function refresh_plugin_update_metadata(): void {
			}

			/**
			 * Return a controlled unsuccessful update.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return bool
			 */
			protected function upgrade_woopayments_plugin_file( string $plugin_file ): bool {
				$this->lock_observations[] = false !== get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false );
				return false;
			}

			/** @return bool[] */
			public function get_lock_observations(): array {
				return $this->lock_observations;
			}
		};
		$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), 'woocommerce-payments/woocommerce-payments.php' );
		$sut       = $this->create_job( true, $preflight, $job );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$this->assertSame( array( true ), $job->get_lock_observations() );
		$this->assertFalse( get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false ) );
	}

	/**
	 * @testdox A contending WordPress core upgrader lock defers without running the updater.
	 */
	public function test_contending_plugin_update_lock_defers_without_running_the_updater(): void {
		$job       = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int */
			private int $upgrade_count = 0;

			/** Refresh controlled update metadata. */
			protected function refresh_plugin_update_metadata(): void {
			}

			/**
			 * Record any unexpected core update call.
			 *
			 * @param string $plugin_file Active WooPayments plugin file.
			 * @return bool
			 */
			protected function upgrade_woopayments_plugin_file( string $plugin_file ): bool {
				unset( $plugin_file );
				++$this->upgrade_count;
				return true;
			}

			/** @return int */
			public function get_upgrade_count(): int {
				return $this->upgrade_count;
			}
		};
		$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), 'woocommerce-payments/woocommerce-payments.php' );
		$sut       = $this->create_job( true, $preflight, $job );

		$this->assertTrue( \WP_Upgrader::create_lock( 'woocommerce_woopayments_cutover_plugin_update_lock', 5 * MINUTE_IN_SECONDS ) );
		try {
			$sut->enqueue( 'merchant' );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );
			$sut->handle_reconcile( $pending['generation'], 1 );
		} finally {
			\WP_Upgrader::release_lock( 'woocommerce_woopayments_cutover_plugin_update_lock' );
		}

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $deferred['deferred_codes'] );
		$this->assertSame( 0, $job->get_upgrade_count() );
	}

	/**
	 * @testdox A network plugin update uses the main site core lock and restores a subsite context.
	 */
	public function test_network_plugin_update_lock_uses_the_main_site_and_restores_the_calling_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$network = get_network();
		$this->assertInstanceOf( \WP_Network::class, $network );
		$main_site_id = get_main_site_id( (int) $network->id );
		$this->assertGreaterThan( 0, $main_site_id );
		$subsite_id = self::factory()->blog->create();
		$this->assertIsInt( $subsite_id );
		switch_to_blog( $subsite_id );
		try {
			$job       = new class() extends WooPaymentsCutoverReconciliationJob {
				/** @var int[] */
				private array $upgrade_blogs = array();

				/** @var bool[] */
				private array $lock_observations = array();

				/** Refresh controlled update metadata. */
				protected function refresh_plugin_update_metadata(): void {
				}

				/**
				 * Return a controlled unsuccessful update.
				 *
				 * @param string $plugin_file Active plugin file.
				 * @return bool
				 */
				protected function upgrade_woopayments_plugin_file( string $plugin_file ): bool {
					$this->upgrade_blogs[]     = get_current_blog_id();
					$this->lock_observations[] = false !== get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false );
					return false;
				}

				/** @return int[] */
				public function get_upgrade_blogs(): array {
					return $this->upgrade_blogs;
				}

				/** @return bool[] */
				public function get_lock_observations(): array {
					return $this->lock_observations;
				}
			};
			$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), 'woocommerce-payments/woocommerce-payments.php' );
			$this->create_job( true, $preflight, $job );
			$this->assertSame( $subsite_id, get_current_blog_id() );
			$method = new \ReflectionMethod( WooPaymentsCutoverReconciliationJob::class, 'update_woopayments_plugin' );
			$method->setAccessible( true );
			$this->assertFalse( $method->invoke( $job ) );

			$this->assertSame( array( $main_site_id ), $job->get_upgrade_blogs() );
			$this->assertSame( array( true ), $job->get_lock_observations() );
			$this->assertFalse( get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false ) );
			$this->assertSame( $subsite_id, get_current_blog_id() );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * @testdox A throwing core lock release still restores the calling multisite blog.
	 */
	public function test_throwing_network_plugin_update_lock_release_restores_the_calling_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$network = get_network();
		$this->assertInstanceOf( \WP_Network::class, $network );
		$main_site_id = get_main_site_id( (int) $network->id );
		$this->assertGreaterThan( 0, $main_site_id );
		$subsite_id = self::factory()->blog->create();
		$this->assertIsInt( $subsite_id );
		switch_to_blog( $subsite_id );
		$lock_name   = 'woocommerce_woopayments_cutover_plugin_update_lock';
		$delete_hook = 'delete_option_' . $lock_name . '.lock';
		$thrower     = static function (): void {
			throw new \RuntimeException( 'Expected core lock release failure.' );
		};
		try {
			$job       = new class() extends WooPaymentsCutoverReconciliationJob {
				/** @var array<int,array<string,mixed>> */
				private array $errors = array();

				/** Refresh controlled update metadata. */
				protected function refresh_plugin_update_metadata(): void {
				}

				/**
				 * Return a controlled unsuccessful update.
				 *
				 * @param string $plugin_file Active WooPayments plugin file.
				 * @return bool
				 */
				protected function upgrade_woopayments_plugin_file( string $plugin_file ): bool {
					unset( $plugin_file );
					return false;
				}

				/**
				 * Record the handled release failure without relying on the global logger.
				 *
				 * @param string              $message Error message.
				 * @param array<string,mixed> $context Error context.
				 */
				protected function write_log_error( string $message, array $context ): void {
					$this->errors[] = array(
						'message' => $message,
						'context' => $context,
					);
				}

				/** @return array<int,array<string,mixed>> */
				public function get_errors(): array {
					return $this->errors;
				}
			};
			$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), 'woocommerce-payments/woocommerce-payments.php' );
			$this->create_job( true, $preflight, $job );
			add_action( $delete_hook, $thrower );

			$method = new \ReflectionMethod( WooPaymentsCutoverReconciliationJob::class, 'update_woopayments_plugin' );
			$method->setAccessible( true );
			$this->assertFalse( $method->invoke( $job ) );

			$this->assertSame( $subsite_id, get_current_blog_id() );
			$this->assertCount( 2, $job->get_errors() );
			$this->assertSame( 'WooPayments cutover plugin update failed.', $job->get_errors()[1]['message'] );
		} finally {
			remove_action( $delete_hook, $thrower );
			if ( get_current_blog_id() !== $main_site_id ) {
				switch_to_blog( $main_site_id );
				$cleanup_switched = true;
			} else {
				$cleanup_switched = false;
			}
			\WP_Upgrader::release_lock( $lock_name );
			if ( $cleanup_switched ) {
				restore_current_blog();
			}
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * @testdox A replay does not normalize the same unsupported payment methods twice.
	 */
	public function test_replayed_unsupported_payment_method_resolution_does_not_repeat_the_mutation(): void {
		$preflight = $this->create_preflight_with_failures( array( 'unsupported_payment_methods_enabled' ) );
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );
		$first = $this->require_state_store()->get_record();
		$this->assertIsArray( $first );
		$sut->handle_reconcile( $first['generation'], 2 );

		$this->assertSame( 1, $preflight->get_remove_unsupported_payment_method_call_count() );
	}

	/**
	 * @testdox An unknown prefixed action remains queued and defers reconciliation.
	 */
	public function test_unknown_prefixed_operational_action_defers_without_cancellation(): void {
		$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_unknown_legacy_hook', array(), 'cutover-test', false );
		$this->assertIsInt( $action_id );
		$preflight = $this->create_preflight_with_failures(
			array( 'operational_queue_hooks_undispositioned' ),
			false,
			false,
			array(
				array(
					'action_id' => $action_id,
					'hook'      => 'wcpay_unknown_legacy_hook',
					'group'     => 'cutover-test',
				),
			)
		);
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $action_id ) );
	}

	/**
	 * @testdox Replaying an excluded generation leaves its terminal state unchanged.
	 */
	public function test_replaying_an_excluded_generation_is_a_no_op(): void {
		$preflight = $this->create_preflight_with_failures( array( 'legacy_stripe_billing_subscriptions_present' ) );
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );
		$excluded = $this->require_state_store()->get_record();
		$this->assertIsArray( $excluded );
		$sut->handle_reconcile( $excluded['generation'], $excluded['attempt'] );

		$this->assertSame( $excluded, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox Plugin update failures defer one attempt without installing another plugin copy.
	 *
	 * @dataProvider plugin_update_result_provider
	 *
	 * @param mixed $result Upgrade result.
	 * @param bool  $throws Whether the core upgrader throws.
	 */
	public function test_plugin_update_failures_defer_without_a_second_install( $result, bool $throws ): void {
		$job       = new class( $result, $throws ) extends WooPaymentsCutoverReconciliationJob {
			/** @var mixed */
			private $upgrade_result;

			/** @var bool */
			private bool $throws;

			/** @var int */
			private int $metadata_refreshes = 0;

			/** @var string[] */
			private array $upgraded_plugin_files = array();

			/** @var bool[] */
			private array $lock_observations = array();

			/**
			 * Initialize the controlled core updater result.
			 *
			 * @param mixed $upgrade_result Upgrade result.
			 * @param bool  $throws         Whether the core upgrader throws.
			 */
			public function __construct( $upgrade_result, bool $throws ) {
				$this->upgrade_result = $upgrade_result;
				$this->throws         = $throws;
			}

			/** Refresh controlled update metadata. */
			protected function refresh_plugin_update_metadata(): void {
				++$this->metadata_refreshes;
			}

			/**
			 * Return the controlled core upgrader result.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return mixed
			 */
			protected function upgrade_woopayments_plugin_file( string $plugin_file ) {
				$this->upgraded_plugin_files[] = $plugin_file;
				$this->lock_observations[]     = false !== get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false );
				if ( $this->throws ) {
					throw new \RuntimeException( 'Expected core upgrader failure.' );
				}
				return $this->upgrade_result;
			}

			/** @return int */
			public function get_metadata_refresh_count(): int {
				return $this->metadata_refreshes;
			}

			/** @return string[] */
			public function get_upgraded_plugin_files(): array {
				return $this->upgraded_plugin_files;
			}

			/** @return bool[] */
			public function get_lock_observations(): array {
				return $this->lock_observations;
			}
		};
		$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), 'woocommerce-payments/woocommerce-payments.php' );
		$sut       = $this->create_job( true, $preflight, $job );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $deferred['deferred_codes'] );
		$this->assertSame( 1, $job->get_metadata_refresh_count() );
		$this->assertSame( array( 'woocommerce-payments/woocommerce-payments.php' ), $job->get_upgraded_plugin_files() );
		$this->assertSame( array( true ), $job->get_lock_observations() );
		$this->assertFalse( get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false ) );
	}

	/**
	 * @testdox A successful plugin update clears its blocker and is not repeated by the successor attempt.
	 */
	public function test_successful_plugin_update_is_not_repeated_after_the_version_blocker_clears(): void {
		$preflight = new class() extends WooPaymentsCutoverPreflightService {
			/** @var bool */
			private bool $version_unsupported = true;

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->version_unsupported ? array( 'woopayments_plugin_version_unsupported' ) : array();
			}

			/** Clear the controlled version failure after the core update succeeds. */
			public function mark_plugin_version_supported(): void {
				$this->version_unsupported = false;
			}

			/** Invalidate the controlled preflight result. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** Return the controlled active plugin file. */
			public function get_active_woopayments_plugin_file(): string {
				return 'woocommerce-payments/woocommerce-payments.php';
			}
		};
		$job       = new class(
			static function () use ( $preflight ): void {
				$preflight->mark_plugin_version_supported();
			}
		) extends WooPaymentsCutoverReconciliationJob {
			/** @var \Closure */
			private \Closure $mark_plugin_version_supported;

			/** @var int */
			private int $metadata_refreshes = 0;

			/** @var string[] */
			private array $upgraded_plugin_files = array();

			/** @var bool[] */
			private array $lock_observations = array();

			/**
			 * Initialize the controlled core update completion callback.
			 *
			 * @param \Closure $mark_plugin_version_supported Marks the controlled version as supported.
			 */
			public function __construct( \Closure $mark_plugin_version_supported ) {
				$this->mark_plugin_version_supported = $mark_plugin_version_supported;
			}

			/** Refresh controlled update metadata. */
			protected function refresh_plugin_update_metadata(): void {
				++$this->metadata_refreshes;
			}

			/**
			 * Complete one controlled core plugin update.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return bool
			 */
			protected function upgrade_woopayments_plugin_file( string $plugin_file ): bool {
				$this->upgraded_plugin_files[] = $plugin_file;
				$this->lock_observations[]     = false !== get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false );
				( $this->mark_plugin_version_supported )();
				return true;
			}

			/** @return int */
			public function get_metadata_refresh_count(): int {
				return $this->metadata_refreshes;
			}

			/** @return string[] */
			public function get_upgraded_plugin_files(): array {
				return $this->upgraded_plugin_files;
			}

			/** @return bool[] */
			public function get_lock_observations(): array {
				return $this->lock_observations;
			}
		};
		$sut       = $this->create_job( true, $preflight, $job );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$first = $this->require_state_store()->get_record();
		$this->assertIsArray( $first );
		$this->assertSame( array( 'finalization_pending' ), $first['deferred_codes'] );
		$this->assertSame( 1, $job->get_metadata_refresh_count() );
		$this->assertSame( array( 'woocommerce-payments/woocommerce-payments.php' ), $job->get_upgraded_plugin_files() );
		$this->assertSame( array( true ), $job->get_lock_observations() );
		$this->assertFalse( get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false ) );
		$sut->handle_reconcile( $first['generation'], 2 );

		$second = $this->require_state_store()->get_record();
		$this->assertIsArray( $second );
		$this->assertSame( array( 'finalization_pending' ), $second['deferred_codes'] );
		$this->assertSame( 1, $job->get_metadata_refresh_count() );
		$this->assertSame( array( 'woocommerce-payments/woocommerce-payments.php' ), $job->get_upgraded_plugin_files() );
	}

	/**
	 * @testdox A running migrator defers until its completed callback creates a retry that is canceled next.
	 */
	public function test_running_legacy_migrator_defers_then_cancels_its_retry(): void {
		$hook      = 'wcpay_migrate_subscription_retry';
		$group     = 'cutover-test';
		$action_id = as_schedule_single_action( time() - MINUTE_IN_SECONDS, $hook, array(), $group, false );
		$this->assertIsInt( $action_id );
		$store = new \ActionScheduler_DBStore();
		$claim = $store->stake_claim( 1, new \DateTime( '@' . time() ), array( $hook ), $group );
		try {
			$this->assertCount( 1, $claim->get_actions() );
			$this->assertSame( array( $action_id ), $claim->get_actions() );
			$store->log_execution( $action_id );
			$this->assertSame( ActionScheduler_Store::STATUS_RUNNING, $store->get_status( $action_id ) );
			$preflight = $this->create_preflight_with_failures(
				array( 'operational_queue_hooks_undispositioned' ),
				false,
				false,
				array(
					array(
						'action_id' => $action_id,
						'hook'      => $hook,
						'group'     => $group,
					),
				),
				'',
				true
			);
			$sut       = $this->create_job_with_preflight( true, $preflight );
			$sut->enqueue( 'merchant' );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );

			$sut->handle_reconcile( $pending['generation'], 1 );
			$deferred = $this->require_state_store()->get_record();
			$this->assertIsArray( $deferred );
			$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
			$this->assertSame( array( 'operational_queue_hooks_undispositioned' ), $deferred['deferred_codes'] );
			$this->assertSame( ActionScheduler_Store::STATUS_RUNNING, $store->get_status( $action_id ) );
			$store->mark_complete( $action_id );
			$retry_action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook, array(), $group, false );
			$this->assertIsInt( $retry_action_id );
			$preflight->add_queued_operational_action(
				array(
					'action_id' => $retry_action_id,
					'hook'      => $hook,
					'group'     => $group,
				)
			);

			$sut->handle_reconcile( $deferred['generation'], 2 );

			$resolved = $this->require_state_store()->get_record();
			$this->assertIsArray( $resolved );
			$this->assertSame( array( 'finalization_pending' ), $resolved['deferred_codes'] );
			$this->assertSame( ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $retry_action_id ) );
		} finally {
			$store->release_claim( $claim );
		}
	}

	/**
	 * Provide WordPress core updater failure modes and the supported-but-still-old case.
	 *
	 * @return array<string,array{mixed,bool}>
	 */
	public function plugin_update_result_provider(): array {
		return array(
			'false result'                => array( false, false ),
			'null result'                 => array( null, false ),
			'WordPress error result'      => array( new \WP_Error( 'upgrade_failed' ), false ),
			'still old successful result' => array( true, false ),
			'thrown result'               => array( null, true ),
		);
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
		$sut       = $this->create_job_with_preflight( true, $this->create_preflight_with_failures( array( 'native_transport_unavailable' ) ) );
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
		$before = time();
		$sut->handle_reconcile( $record['generation'], 1 );
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
		$sut = $this->create_job_with_preflight( true, $this->create_preflight_with_failures( array( 'native_transport_unavailable' ) ) );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
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
		$running                     = $this->create_running_record( $record );
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
		$running = $this->create_running_record( $record );

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
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $claimed['state'] );
		$this->assertSame( 1, $claimed['attempt'] );
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
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $claimed['state'] );
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
		$this->assertSame( 'step-2', $claimed['step_log'][0]['step'] );
		$this->assertSame( 'deferred', $claimed['step_log'][ WooPaymentsCutoverStateStore::MAX_STEP_LOG_ENTRIES - 1 ]['step'] );
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
		$running                     = $this->create_running_record( $record );
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
	 * @param bool                                     $native_enabled     Whether native payments are enabled.
	 * @param WooPaymentsCutoverPreflightService|null  $preflight_service Controlled preflight facts, when needed.
	 * @param WooPaymentsCutoverReconciliationJob|null $job               Job instance, when a test needs a narrow override.
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function create_job( bool $native_enabled, ?WooPaymentsCutoverPreflightService $preflight_service = null, ?WooPaymentsCutoverReconciliationJob $job = null ): WooPaymentsCutoverReconciliationJob {
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

		$job               = $job ?? new WooPaymentsCutoverReconciliationJob();
		$preflight_service = $preflight_service ?? $this->preflight_service;
		$this->assertInstanceOf( WooPaymentsCutoverPreflightService::class, $preflight_service );
		$job->init( $arbiter, $this->require_state_store(), $this->require_scheduler(), $preflight_service );
		$this->jobs[] = $job;

		return $job;
	}

	/**
	 * Create a job whose reconciliation facts are controlled by the test.
	 *
	 * @param bool                               $native_enabled Whether native payments are enabled.
	 * @param WooPaymentsCutoverPreflightService $preflight      Controlled preflight facts.
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function create_job_with_preflight( bool $native_enabled, WooPaymentsCutoverPreflightService $preflight ): WooPaymentsCutoverReconciliationJob {
		return $this->create_job( $native_enabled, $preflight );
	}

	/**
	 * Create deterministic preflight facts without loading external services.
	 *
	 * @param string[]                                                 $failures      Reconciliation failures.
	 * @param bool                                                     $owner_missing Whether the saved connection owner is gone.
	 * @param bool                                                     $should_throw  Whether resolving facts should throw.
	 * @param array<int,array{action_id:int,hook:string,group:string}> $queued_actions Controlled operational actions.
	 * @param string                                                   $plugin_file    Resolved active plugin file.
	 * @param bool                                                     $derive_operational_queue_failure Whether queued actions dynamically control their failure.
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_preflight_with_failures( array $failures, bool $owner_missing = false, bool $should_throw = false, array $queued_actions = array(), string $plugin_file = '', bool $derive_operational_queue_failure = false ): WooPaymentsCutoverPreflightService {
		return new class( $failures, $owner_missing, $should_throw, $queued_actions, $plugin_file, $derive_operational_queue_failure ) extends WooPaymentsCutoverPreflightService {
			/** @var string[] */
			private array $failures;

			/** @var bool */
			private bool $owner_missing;

			/** @var bool */
			private bool $should_throw;

			/** @var array<int,array{action_id:int,hook:string,group:string}> */
			private array $queued_actions;

			/** @var string */
			private string $plugin_file;

			/** @var bool */
			private bool $derive_operational_queue_failure;

			/** @var int */
			private int $remove_unsupported_payment_method_call_count = 0;

			/**
			 * Initialize the controlled failures.
			 *
			 * @param string[]                                                 $failures      Reconciliation failures.
			 * @param bool                                                     $owner_missing Whether the saved connection owner is gone.
			 * @param bool                                                     $should_throw  Whether resolving facts should throw.
			 * @param array<int,array{action_id:int,hook:string,group:string}> $queued_actions Controlled operational actions.
			 * @param string                                                   $plugin_file    Resolved active plugin file.
			 * @param bool                                                     $derive_operational_queue_failure Whether queued actions dynamically control their failure.
			 */
			public function __construct( array $failures, bool $owner_missing, bool $should_throw, array $queued_actions, string $plugin_file, bool $derive_operational_queue_failure ) {
				$this->failures                         = $failures;
				$this->owner_missing                    = $owner_missing;
				$this->should_throw                     = $should_throw;
				$this->queued_actions                   = $queued_actions;
				$this->plugin_file                      = $plugin_file;
				$this->derive_operational_queue_failure = $derive_operational_queue_failure;
			}

			/**
			 * Get the controlled reconciliation failures.
			 *
			 * @return string[]
			 */
			public function get_reconciliation_failures(): array {
				if ( $this->should_throw ) {
					throw new \RuntimeException( 'Expected resolver failure.' );
				}
				if ( $this->derive_operational_queue_failure && array() === $this->get_queued_operational_actions() ) {
					return array_values( array_diff( $this->failures, array( 'operational_queue_hooks_undispositioned' ) ) );
				}
				return $this->failures;
			}

			/**
			 * Invalidate the controlled memoization.
			 */
			public function invalidate_current_blog_memoization(): void {
			}

			/**
			 * Return the controlled active plugin file.
			 */
			public function get_active_woopayments_plugin_file(): string {
				return $this->plugin_file;
			}

			/**
			 * Keep the matrix owner-token condition on the ordinary retry path.
			 */
			public function is_cutover_connection_owner_user_missing(): bool {
				return $this->owner_missing;
			}

			/**
			 * Remove the unsupported-method condition once normalized.
			 *
			 * @return string[]
			 */
			public function remove_unsupported_enabled_payment_method_ids(): array {
				++$this->remove_unsupported_payment_method_call_count;
				$this->failures = array_values( array_diff( $this->failures, array( 'unsupported_payment_methods_enabled' ) ) );
				return array( 'bancontact' );
			}

			/**
			 * Get the number of unsupported-method normalization attempts.
			 *
			 * @return int
			 */
			public function get_remove_unsupported_payment_method_call_count(): int {
				return $this->remove_unsupported_payment_method_call_count;
			}

			/**
			 * Return no queued plugin actions in this isolated matrix test.
			 *
			 * @return array<int,array{action_id:int,hook:string,group:string}>
			 */
			public function get_queued_operational_actions(): array {
				return array_values(
					array_filter(
						$this->queued_actions,
						static function ( array $action ): bool {
							if ( $action['action_id'] < 1 ) {
								return true;
							}
							$status = \ActionScheduler::store()->get_status( $action['action_id'] );
							return in_array( $status, array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ), true );
						}
					)
				);
			}

			/**
			 * Add one controlled queued action.
			 *
			 * @param array{action_id:int,hook:string,group:string} $action Operational action.
			 */
			public function add_queued_operational_action( array $action ): void {
				$this->queued_actions[] = $action;
			}
		};
	}

	/**
	 * Create a valid running record for state-store fencing tests.
	 *
	 * @param array<string,mixed> $record Pending record to claim.
	 * @return array<string,mixed>
	 */
	private function create_running_record( array $record ): array {
		$running                     = $record;
		$running['revision']         = $record['revision'] + 1;
		$running['state']            = WooPaymentsCutoverState::RUNNING;
		$running['attempt']          = $record['attempt'] + 1;
		$running['action_id']        = 0;
		$running['current_step']     = 'running';
		$running['updated_at']       = time();
		$running['next_attempt_at']  = null;
		$running['lease_token']      = 'test-lease-token';
		$running['lease_expires_at'] = time() + WooPaymentsCutoverReconciliationJob::RUNNING_TIMEOUT;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $record, $running ) );

		return $running;
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
