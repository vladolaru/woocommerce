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
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverNormalizationRunner;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
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
	 * @var RecordingWooPaymentsCutoverActionScheduler|null
	 */
	private ?RecordingWooPaymentsCutoverActionScheduler $scheduler = null;

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
			$request_token = new \ReflectionProperty( WooPaymentsCutoverReconciliationJob::class, 'request_token' );
			$request_token->setAccessible( true );
			$request_token->setValue( null );
			$this->state_store       = new WooPaymentsCutoverStateStore();
			$this->scheduler         = new RecordingWooPaymentsCutoverActionScheduler( $this->state_store );
			$this->preflight_service = new class() extends WooPaymentsCutoverPreflightService {
				/**
				 * Report the controlled site-local (non-network) activation state.
				 *
				 * The real implementation reads through `$legacy_proxy`, which this shared
				 * fixture never initializes; every test using the default preflight service
				 * exercises site-local reconciliation, so the answer is fixed.
				 */
				public function is_woopayments_network_active(): bool {
					return false;
				}
			};
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
		remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'active_plugins' );
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();

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
	 * @testdox Task 3.1 one-click cutover dispatches accepted merchant work after releasing its state lease.
	 */
	public function test_enqueue_dispatches_accepted_merchant_work_after_releasing_its_state_lease(): void {
		$scheduler = $this->require_scheduler();

		$this->assertTrue( $this->require_sut()->enqueue( 'merchant' ) );

		$this->assertSame( 1, $scheduler->dispatch_count, 'Accepted merchant work should request one immediate async dispatch.' );
		$this->assertTrue( $scheduler->dispatch_observed_released_lease, 'The durable state lease must be released before the async request can race the job.' );
	}

	/**
	 * @testdox Non-merchant cutover work retains the ordinary Action Scheduler dispatch path.
	 */
	public function test_enqueue_does_not_explicitly_dispatch_non_merchant_work(): void {
		$scheduler = $this->require_scheduler();

		$this->assertTrue( $this->require_sut()->enqueue( 'mandatory' ) );

		$this->assertSame( 0, $scheduler->dispatch_count, 'Mandatory cutover work should retain the ordinary queue dispatch path.' );
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
		$this->assertSame( 0, $this->require_scheduler()->dispatch_count, 'Rejected merchant work must not request an async dispatch.' );
	}

	/**
	 * @testdox Platform-ineligible accounts refuse cutover without persisting or scheduling work.
	 */
	public function test_enqueue_does_nothing_when_platform_account_is_ineligible(): void {
		$this->require_sut();
		$sut = $this->create_job( true, null, null, true, null, false );

		$this->assertFalse( $sut->enqueue( 'merchant' ) );
		$this->assertNull( $this->require_state_store()->get_record() );
		$this->assertSame( 0, $this->count_cutover_actions() );
		$this->assertSame( 0, $this->require_scheduler()->dispatch_count, 'Ineligible merchant work must not request an async dispatch.' );
	}

	/**
	 * @testdox An all-clear attempt normalizes, seeds features, deactivates WooPayments, and schedules fresh-request ownership verification.
	 */
	public function test_all_clear_attempt_starts_two_request_finalization(): void {
		$plugin_active = true;
		$preflight     = new class( $plugin_active ) extends WooPaymentsCutoverPreflightService {
			/** @var bool */
			private bool $plugin_active;

			/** @var int */
			private int $deactivation_calls = 0;

			/**
			 * @param bool $plugin_active Whether the plugin is active.
			 */
			public function __construct( bool &$plugin_active ) {
				$this->plugin_active =& $plugin_active;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Deactivate the controlled plugin. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				$this->plugin_active = false;
				return true;
			}

			/** Return the number of deactivation calls. */
			public function get_deactivation_calls(): int {
				return $this->deactivation_calls;
			}

			/**
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; this scenario is site-local, so
			 * the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @var int */
			private int $run_count = 0;

			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				++$this->run_count;
				return array(
					'ran'     => true,
					'changes' => array( 'no_changes' ),
				);
			}

			/** Return the number of normalization calls. */
			public function get_run_count(): int {
				return $this->run_count;
			}
		};
		$arbiter       = new class() extends WooPaymentsRuntimeArbiter {
			/** Return an enabled native runtime. */
			public function is_builtin_enabled(): bool {
				return true;
			}
		};
		$sut           = new WooPaymentsCutoverReconciliationJob();
		$sut->init( $arbiter, $this->require_state_store(), $this->require_scheduler(), $preflight, $normalization, $this->create_native_eligibility_service( true ) );
		$this->jobs[]  = $sut;
		$seeded        = 0;
		$seed_features = static function () use ( &$seeded ): void {
			++$seeded;
		};
		add_action( 'woocommerce_woopayments_cutover_seed_features', $seed_features );
		// Plugin Multi-Currency use (client 11.1.0 `includes/multi-currency/MultiCurrency.php:767-783`) next to a stored core choice: the
		// handover runs on the first native-owned request, so the reconciliation itself must leave the choice alone.
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );
			$before = time();

			$sut->handle_reconcile( $pending['generation'], 1 );
		} finally {
			remove_action( 'woocommerce_woopayments_cutover_seed_features', $seed_features );
		}

		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( 1, $normalization->get_run_count() );
		$this->assertSame( 1, $seeded );
		$this->assertSame( 'no', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ), 'The reconciliation does not write the Multi-Currency option.' );
		$this->assertSame( 1, $preflight->get_deactivation_calls() );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $verification['state'] );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->assertSame( 1, $verification['attempt'] );
		$this->assertIsString( $verification['request_origin_token'] );
		$this->assertNotSame( '', $verification['request_origin_token'] );
		$this->assertGreaterThan( $before, $verification['next_attempt_at'] );
		$this->assertSame( $verification['action_id'], $this->require_scheduler()->get_scheduled_action_id( $verification['generation'], 2 ) );
	}

	/**
	 * @testdox Finalization moves a plugin-era store to the active tier so the next shopper request registers the native gateway.
	 *
	 * Source: data/task-1.4-dormancy-design.md:84-96 (cutover completion is an authoritative state writer; account present plus enabled 'yes' derives active).
	 */
	public function test_finalization_activates_native_payments_for_the_next_request(): void {
		$this->arrange_plugin_era_store();
		$preflight = $this->create_plugin_deactivating_preflight();
		$origin    = $this->create_state_writing_job( true, $preflight );

		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$origin->handle_reconcile( $pending['generation'], 1 );

		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'The deactivating request must leave the tier the next request bootstraps from.' );
		$this->assert_next_front_request_registers_native_gateway();

		$this->run_ownership_verification_in_a_fresh_request( $verification, $preflight );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
		$this->assert_next_front_request_registers_native_gateway();
	}

	/**
	 * @testdox A switch whose account loses native eligibility after the click defers with the plugin still active, and finishes once eligibility returns.
	 */
	public function test_withdrawn_eligibility_defers_the_switch_without_deactivating_the_plugin(): void {
		$this->arrange_plugin_era_store();
		$preflight = $this->create_plugin_deactivating_preflight();
		$origin    = $this->create_state_writing_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$this->set_native_eligibility( false );

		$origin->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'builtin_ineligible' ), $deferred['deferred_codes'] );
		$this->assertContains( array( 'code' => 'eligibility_withdrawn' ), $deferred['informational_outcomes'] );
		$this->assertSame( $deferred['action_id'], $this->require_scheduler()->get_scheduled_action_id( $deferred['generation'], 2 ) );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), get_option( 'active_plugins' ), 'The plugin must keep the runtime while the store is ineligible.' );

		$this->set_native_eligibility( true );
		$this->require_scheduler()->cancel( $deferred['generation'], 2 );
		$origin->handle_reconcile( $deferred['generation'], 2 );

		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'], 'Returned eligibility finishes the switch the merchant already started.' );
		$this->assertSame( array(), get_option( 'active_plugins' ) );
	}

	/**
	 * @testdox A switch still ineligible after the fast retry window closes without finalizing, writes disabled and leaves the plugin active.
	 */
	public function test_withdrawn_eligibility_closes_the_switch_after_the_retry_window(): void {
		$this->arrange_plugin_era_store();
		$preflight = $this->create_plugin_deactivating_preflight();
		$origin    = $this->create_state_writing_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$aged               = $pending;
		$aged['revision']   = $pending['revision'] + 1;
		$aged['started_at'] = time() - DAY_IN_SECONDS - 1;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $aged ) );
		$this->set_native_eligibility( false );

		$origin->handle_reconcile( $aged['generation'], 1 );

		$closed = $this->require_state_store()->get_record();
		$this->assertIsArray( $closed );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $closed['state'] );
		$this->assertSame( array( 'builtin_ineligible' ), $closed['deferred_codes'] );
		$this->assertSame( 0, $this->require_scheduler()->get_scheduled_action_id( $closed['generation'], 2 ) );
		$this->assertSame( WooPaymentsSetupTier::DISABLED, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), get_option( 'active_plugins' ), 'Closing the switch must never leave the store without a payments runtime.' );
	}

	/**
	 * @testdox A switch held for eligibility past the retry window ($markers) closes on its next attempt even when eligibility has returned.
	 * @testWith ["both markers"]
	 *           ["the ineligibility deferral only"]
	 *           ["the eligibility_withdrawn outcome only"]
	 *
	 * @param string $markers Which eligibility markers the aged claim keeps.
	 */
	public function test_expired_held_switch_does_not_complete_on_the_old_click( string $markers ): void {
		$this->arrange_plugin_era_store();
		$preflight = $this->create_plugin_deactivating_preflight();
		$origin    = $this->create_state_writing_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$this->set_native_eligibility( false );
		$origin->handle_reconcile( $pending['generation'], 1 );
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( array( 'builtin_ineligible' ), $deferred['deferred_codes'] );
		$aged               = $deferred;
		$aged['revision']   = $deferred['revision'] + 1;
		$aged['started_at'] = time() - DAY_IN_SECONDS - 1;
		if ( 'the ineligibility deferral only' === $markers ) {
			$aged['informational_outcomes'] = array_values(
				array_filter(
					$aged['informational_outcomes'],
					static fn( $outcome ): bool => array( 'code' => 'eligibility_withdrawn' ) !== $outcome
				)
			);
		} elseif ( 'the eligibility_withdrawn outcome only' === $markers ) {
			$aged['deferred_codes'] = array();
		}
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $deferred, $aged ) );
		$this->require_scheduler()->cancel( $aged['generation'], 2 );
		$this->set_native_eligibility( true );

		$origin->handle_reconcile( $aged['generation'], 2 );

		$closed = $this->require_state_store()->get_record();
		$this->assertIsArray( $closed );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $closed['state'] );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), get_option( 'active_plugins' ), 'A claim held for eligibility past the window closes instead of switching the store.' );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'The tier follows the account again, so the start notice can offer a fresh switch.' );
	}

	/**
	 * @testdox A first attempt that runs on time logs no delay warning.
	 */
	public function test_on_time_first_attempt_logs_no_delay_warning(): void {
		$this->arrange_plugin_era_store();
		$job    = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var array<int,array<string,mixed>> */
			public array $logged_warnings = array();

			/**
			 * Capture warnings.
			 *
			 * @param string              $message Warning message.
			 * @param array<string,mixed> $context Warning context.
			 */
			protected function write_log_warning( string $message, array $context ): void {
				$this->logged_warnings[] = array_merge( array( 'message' => $message ), $context );
			}
		};
		$origin = $this->create_job( true, $this->create_plugin_deactivating_preflight(), $job, true, $this->create_noop_normalization(), true, wc_get_container()->get( WooPaymentsAccountService::class ) );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$origin->handle_reconcile( $pending['generation'], 1 );

		$this->assertSame( 'verify_builtin_ownership', $this->require_state_store()->get_record()['current_step'] ?? null );
		$this->assertSame( array(), $job->logged_warnings );
	}

	/**
	 * @testdox A first attempt that runs a day after the merchant's click still finishes the switch, and logs a warning for the delay.
	 */
	public function test_switch_first_attempted_after_the_retry_window_still_finishes(): void {
		$this->arrange_plugin_era_store();
		$preflight     = $this->create_plugin_deactivating_preflight();
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array( 'no_changes' ),
				);
			}
		};
		$job           = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var array<int,array<string,mixed>> */
			public array $logged_warnings = array();

			/**
			 * Capture warnings.
			 *
			 * @param string              $message Warning message.
			 * @param array<string,mixed> $context Warning context.
			 */
			protected function write_log_warning( string $message, array $context ): void {
				$this->logged_warnings[] = array_merge( array( 'message' => $message ), $context );
			}
		};
		$origin        = $this->create_job( true, $preflight, $job, true, $normalization, true, wc_get_container()->get( WooPaymentsAccountService::class ) );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$aged               = $pending;
		$aged['revision']   = $pending['revision'] + 1;
		$aged['started_at'] = time() - DAY_IN_SECONDS - 1;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $aged ) );

		$origin->handle_reconcile( $aged['generation'], 1 );

		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $verification['state'], 'The click is the decision; a late queue does not ask the merchant again.' );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->assertSame( array(), get_option( 'active_plugins' ) );
		$this->assertCount( 1, $job->logged_warnings );
		$this->assertSame( 'woocommerce-woopayments-cutover', $job->logged_warnings[0]['source'] );
		$this->assertSame( $aged['generation'], $job->logged_warnings[0]['generation'] );
		$this->assertGreaterThanOrEqual( DAY_IN_SECONDS, $job->logged_warnings[0]['delay_seconds'] );
	}

	/**
	 * @testdox The merchant's start restarts the retry clock of an offer created earlier.
	 */
	public function test_merchant_start_restarts_the_retry_clock(): void {
		$sut = $this->require_sut();
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$awaiting                 = $pending;
		$awaiting['revision']     = $pending['revision'] + 1;
		$awaiting['current_step'] = 'awaiting_merchant_start';
		$awaiting['action_id']    = 0;
		$awaiting['started_at']   = time() - 3 * DAY_IN_SECONDS;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $awaiting ) );
		$before = time();

		$this->assertTrue( $sut->enqueue( 'merchant' ) );

		$queued = $this->require_state_store()->get_record();
		$this->assertIsArray( $queued );
		$this->assertSame( 'queued', $queued['current_step'] );
		$this->assertGreaterThanOrEqual( $before, $queued['started_at'] );
	}

	/**
	 * Set the cached account's native eligibility, as a platform account refresh would.
	 *
	 * @param bool $eligible Whether the platform keeps the account eligible.
	 */
	private function set_native_eligibility( bool $eligible ): void {
		$cache                            = get_option( 'wcpay_account_data' );
		$cache['data']['native_payments'] = array( 'eligible' => $eligible );
		$cache['fetched']                 = time();
		// Clearing the in-memory cache deletes the option, so it goes first.
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		update_option( 'wcpay_account_data', $cache, false );
	}

	/**
	 * @testdox Ownership verification rewrites the tier when the finalization write did not land.
	 *
	 * Source: data/task-1.4-dormancy-design.md:84-96 (a failed derived-state write is retried by a later writer; cutover completion is a writer).
	 */
	public function test_ownership_verification_repairs_the_tier_before_completion(): void {
		$this->arrange_plugin_era_store();
		$preflight = $this->create_plugin_deactivating_preflight();
		$origin    = $this->create_state_writing_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$origin->handle_reconcile( $pending['generation'], 1 );
		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );

		$this->run_ownership_verification_in_a_fresh_request( $verification, $preflight );

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
		$this->assert_next_front_request_registers_native_gateway();
	}

	/**
	 * @testdox A store with Amazon Pay enabled in the plugin's settings keeps it enabled after the switch.
	 */
	public function test_switch_keeps_amazon_pay_enabled(): void {
		$this->arrange_plugin_era_store();
		$enabled_payment_method_ids = array( 'card', 'amazon_pay' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => $enabled_payment_method_ids,
			)
		);
		$preflight = $this->create_settings_reading_preflight();
		$origin    = $this->create_state_writing_job( true, $preflight );

		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$origin->handle_reconcile( $pending['generation'], 1 );
		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->run_ownership_verification_in_a_fresh_request( $verification, $preflight );

		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );
		$this->assertIsArray( $settings );
		$this->assertSame( $enabled_payment_method_ids, $settings['upe_enabled_payment_method_ids'] ?? null, 'The switch must leave the merchant\'s enabled payment methods as they were.' );
	}

	/**
	 * @testdox Reconciliation preserves plugin-origin USD and EUR order money records.
	 */
	public function test_reconciliation_preserves_plugin_origin_multi_currency_orders(): void {
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$usd_order   = $this->create_plugin_shaped_order( $customer_id, 'USD', '10.00', 'pi_plugin_usd' );
		$eur_order   = $this->create_plugin_shaped_order( $customer_id, 'EUR', '12.34', 'pi_plugin_eur' );

		// Oracle: WooPayments 11.1.0 includes/multi-currency/FrontendPrices.php::add_order_meta() omits default-currency metadata and :8082 order 2210 recorded these exact EUR values.
		$eur_order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.88' );
		$eur_order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
		$eur_order->update_meta_data( '_wcpay_multi_currency_stripe_exchange_rate', '1.14382' );
		$eur_order->save();

		$before   = array(
			'USD' => $this->snapshot_historical_money_order( $usd_order->get_id() ),
			'EUR' => $this->snapshot_historical_money_order( $eur_order->get_id() ),
		);
		$expected = array(
			'USD' => array(
				'customer_id'          => $customer_id,
				'currency'             => 'USD',
				'total'                => '10.00',
				'status'               => 'completed',
				'payment_method'       => 'woocommerce_payments',
				'payment_method_title' => 'Visa credit card',
				'transaction_id'       => 'pi_plugin_usd',
				'multi_currency_meta'  => array(),
			),
			'EUR' => array(
				'customer_id'          => $customer_id,
				'currency'             => 'EUR',
				'total'                => '12.34',
				'status'               => 'completed',
				'payment_method'       => 'woocommerce_payments',
				'payment_method_title' => 'Visa credit card',
				'transaction_id'       => 'pi_plugin_eur',
				'multi_currency_meta'  => array(
					'_wcpay_multi_currency_order_default_currency' => array( 'USD' ),
					'_wcpay_multi_currency_order_exchange_rate'    => array( '0.88' ),
					'_wcpay_multi_currency_stripe_exchange_rate'   => array( '1.14382' ),
				),
			),
		);
		$this->assertSame(
			$expected,
			$before,
			'The two fixtures should match the exact WooPayments 11.1.0 plugin-era oracle.'
		);

		delete_option( 'woocommerce_woopayments_cutover_normalization_version' );
		update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		$normalization = new WooPaymentsCutoverNormalizationRunner();
		$sut           = $this->create_job( true, $this->create_preflight_with_failures( array() ), null, true, $normalization );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$after = array(
			'USD' => $this->snapshot_historical_money_order( $usd_order->get_id() ),
			'EUR' => $this->snapshot_historical_money_order( $eur_order->get_id() ),
		);
		$this->assertSame( $expected, $after, 'Every historical order field and multi-currency metadata value should remain exact.' );
		$this->assertSame( $before, $after, 'Cutover normalization must not rewrite historical order money records.' );
		$this->assertSame( '4', get_option( 'woocommerce_woopayments_cutover_normalization_version' ), 'The actual normalization runner should complete.' );
		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $verification['state'], 'With no WooPayments plugin active, the attempt finalizes after actual normalization.' );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'], 'The attempt should continue to ownership verification.' );
	}

	/**
	 * @testdox Reconciliation preserves a plugin-origin saved card and test customer relationship byte-for-byte.
	 */
	public function test_reconciliation_preserves_plugin_origin_saved_card(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, '_wcpay_customer_id_test', 'cus_plugin_history' );

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_plugin_history' );
		$token->set_default( true );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '02' );
		$token->set_expiry_year( '2045' );
		$token->save();
		/** @var \WC_Payment_Token_Data_Store $token_data_store */
		$token_data_store = \WC_Data_Store::load( 'payment-token' );
		$token_data_store->set_default_status( $token->get_id(), true );

		// Oracle: WooPayments 11.1.0 WC_Payments_Token_Service::add_token_to_user() writes this WC_Payment_Token_CC shape, and the read-only :8082 capture recorded Visa 4242 expiring 02/2045 with the test customer option.
		$expected = array(
			'token_id'                   => $token->get_id(),
			'provider_payment_method_id' => 'pm_plugin_history',
			'gateway_id'                 => 'woocommerce_payments',
			'type'                       => 'CC',
			'is_default'                 => true,
			'brand'                      => 'visa',
			'last4'                      => '4242',
			'expiry_month'               => '02',
			'expiry_year'                => '2045',
			'user_id'                    => $user_id,
			'customer_id'                => 'cus_plugin_history',
		);
		$before   = $this->snapshot_historical_saved_card( $token->get_id() );
		$this->assertSame( $expected, $before, 'The fixture should match the exact WooPayments 11.1.0 plugin-era shape.' );

		delete_option( 'woocommerce_woopayments_cutover_normalization_version' );
		update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		$normalization = new WooPaymentsCutoverNormalizationRunner();
		$sut           = $this->create_job( true, $this->create_preflight_with_failures( array() ), null, true, $normalization );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$after = $this->snapshot_historical_saved_card( $token->get_id() );
		$this->assertSame( $expected, $after, 'Every historical token and customer field should remain exact.' );
		$this->assertSame( $before, $after, 'Cutover normalization must not rewrite plugin-origin saved-card state.' );
		$this->assertSame( '4', get_option( 'woocommerce_woopayments_cutover_normalization_version' ), 'The actual normalization runner should complete.' );
	}

	/**
	 * @testdox Reconciliation preserves the selected persisted fields of a plugin-origin subscription-shaped order graph exactly.
	 */
	public function test_reconciliation_preserves_plugin_origin_subscription_persistence_shape(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, '_wcpay_customer_id_test', 'cus_plugin_subscription' );

		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_plugin_subscription' );
		$token->set_default( true );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '02' );
		$token->set_expiry_year( '2045' );
		$token->save();
		/** @var \WC_Payment_Token_Data_Store $token_data_store */
		$token_data_store = \WC_Data_Store::load( 'payment-token' );
		$token_data_store->set_default_status( $token->get_id(), true );

		$parent_order = $this->create_plugin_shaped_order( $user_id, 'USD', '11.98', 'pi_plugin_subscription_parent' );
		$parent_order->add_payment_token( $token );
		$parent_order->update_meta_data( '_payment_method_id', 'pm_plugin_subscription' );
		$parent_order->update_meta_data( '_stripe_customer_id', 'cus_plugin_subscription' );
		$parent_order->update_meta_data( '_stripe_mandate_id', 'mandate_plugin_subscription' );
		$parent_order->update_meta_data( '_wcpay_intent_status', 'succeeded' );
		$parent_order->update_meta_data( '_wcpay_charge_id', 'ch_plugin_subscription_parent' );
		$parent_order->save();

		$subscription = wc_create_order( array( 'customer_id' => $user_id ) );
		if ( ! $subscription instanceof \WC_Order ) {
			throw new \RuntimeException( 'Could not create a plugin-shaped historical subscription.' );
		}
		$subscription->set_parent_id( $parent_order->get_id() );
		$subscription->set_currency( 'USD' );
		$subscription->set_total( '9.99' );
		$subscription_status_filter = static function ( array $statuses ): array {
			$statuses['wc-active'] = 'Active';
			return $statuses;
		};
		add_filter( 'wc_order_statuses', $subscription_status_filter );
		try {
			$subscription->set_status( 'active' );
			$subscription->set_payment_method( 'woocommerce_payments' );
			$subscription->set_payment_method_title( 'Visa credit card' );
			$subscription->add_payment_token( $token );
			$subscription->update_meta_data( '_schedule_start', '2026-09-22 15:00:00' );
			$subscription->update_meta_data( '_schedule_next_payment', '2026-10-22 15:00:00' );
			$subscription->update_meta_data( '_billing_period', 'month' );
			$subscription->update_meta_data( '_billing_interval', '1' );
			$subscription->update_meta_data( '_requires_manual_renewal', 'false' );
			$subscription->update_meta_data( '_payment_method_id', 'pm_plugin_subscription' );
			$subscription->update_meta_data( '_stripe_customer_id', 'cus_plugin_subscription' );
			$subscription->update_meta_data( '_stripe_mandate_id', 'mandate_plugin_subscription' );
			$subscription->save();
		} finally {
			remove_filter( 'wc_order_statuses', $subscription_status_filter );
		}

		// Oracle: WooPayments 11.1.0 OrderService::get_payment_metadata() and token service preserve this WCS order/token graph; the read-only :8082 capture recorded the same USD 11.98 parent, USD 9.99 monthly renewal, Visa 4242, customer, gateway, and schedule shape.
		$expected = array(
			'parent'       => array(
				'order_id'          => $parent_order->get_id(),
				'customer_id'       => $user_id,
				'currency'          => 'USD',
				'total'             => '11.98',
				'status'            => 'completed',
				'payment_method'    => 'woocommerce_payments',
				'transaction_id'    => 'pi_plugin_subscription_parent',
				'payment_token_ids' => array( $token->get_id() ),
				'meta'              => array(
					'_payment_method_id'   => 'pm_plugin_subscription',
					'_stripe_customer_id'  => 'cus_plugin_subscription',
					'_stripe_mandate_id'   => 'mandate_plugin_subscription',
					'_wcpay_intent_status' => 'succeeded',
					'_wcpay_charge_id'     => 'ch_plugin_subscription_parent',
				),
			),
			'subscription' => array(
				'order_id'          => $subscription->get_id(),
				'parent_order_id'   => $parent_order->get_id(),
				'customer_id'       => $user_id,
				'currency'          => 'USD',
				'recurring_total'   => '9.99',
				'status'            => 'active',
				'payment_method'    => 'woocommerce_payments',
				'payment_token_ids' => array( $token->get_id() ),
				'meta'              => array(
					'_schedule_start'          => '2026-09-22 15:00:00',
					'_schedule_next_payment'   => '2026-10-22 15:00:00',
					'_billing_period'          => 'month',
					'_billing_interval'        => '1',
					'_requires_manual_renewal' => 'false',
					'_payment_method_id'       => 'pm_plugin_subscription',
					'_stripe_customer_id'      => 'cus_plugin_subscription',
					'_stripe_mandate_id'       => 'mandate_plugin_subscription',
				),
			),
			'token'        => array(
				'token_id'                   => $token->get_id(),
				'provider_payment_method_id' => 'pm_plugin_subscription',
				'gateway_id'                 => 'woocommerce_payments',
				'type'                       => 'CC',
				'is_default'                 => true,
				'brand'                      => 'visa',
				'last4'                      => '4242',
				'expiry_month'               => '02',
				'expiry_year'                => '2045',
				'user_id'                    => $user_id,
				'customer_id'                => 'cus_plugin_subscription',
			),
		);
		$before   = $this->snapshot_historical_subscription_graph( $parent_order->get_id(), $subscription->get_id(), $token->get_id() );
		$this->assertSame( $expected, $before, 'The fixture should match the exact WooPayments 11.1.0 and read-only :8082 subscription shape.' );

		delete_option( 'woocommerce_woopayments_cutover_normalization_version' );
		update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		$normalization = new WooPaymentsCutoverNormalizationRunner();
		$sut           = $this->create_job( true, $this->create_preflight_with_failures( array() ), null, true, $normalization );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$after = $this->snapshot_historical_subscription_graph( $parent_order->get_id(), $subscription->get_id(), $token->get_id() );
		$this->assertSame( $expected, $after, 'Every selected persisted subscription-shaped order, parent order, token, customer, schedule, total, gateway, and provider metadata field should remain exact.' );
		$this->assertSame( $before, $after, 'Cutover normalization must not rewrite the selected plugin-origin persistence fields.' );
		$this->assertSame( '4', get_option( 'woocommerce_woopayments_cutover_normalization_version' ), 'The actual normalization runner should complete.' );
	}

	/**
	 * @testdox Ownership verification cannot complete in its originating request and completes only after a fresh native-owned request.
	 */
	public function test_ownership_verification_requires_a_fresh_native_owned_request(): void {
		$preflight = $this->create_preflight_with_failures( array() );
		$origin    = $this->create_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$queued = $this->require_state_store()->get_record();
		$this->assertIsArray( $queued );
		$this->require_scheduler()->cancel( $queued['generation'], 1 );
		$request_token = new \ReflectionProperty( WooPaymentsCutoverReconciliationJob::class, 'request_token' );
		$request_token->setAccessible( true );
		$verification                         = $queued;
		$verification['revision']             = $queued['revision'] + 1;
		$verification['attempt']              = 1;
		$verification['action_id']            = 0;
		$verification['current_step']         = 'verify_builtin_ownership';
		$verification['next_attempt_at']      = time() + MINUTE_IN_SECONDS;
		$verification['request_origin_token'] = $request_token->getValue();
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $queued, $verification ) );

		$origin->handle_reconcile( $verification['generation'], 2 );

		$this->assertSame( $verification, $this->require_state_store()->get_record(), 'The request that scheduled verification must not claim or complete it.' );
		$same_request = $this->create_job( true, $preflight, null, false );
		$same_request->handle_reconcile( $verification['generation'], 2 );
		$this->assertSame( $verification, $this->require_state_store()->get_record(), 'A second job instance in the same request must share the origin fence.' );

		$request_token->setValue( null );
		$fresh_request = $this->create_job( true, $preflight, null, false );
		$fresh_request->handle_reconcile( $verification['generation'], 2 );

		$done = $this->require_state_store()->get_record();
		$this->assertIsArray( $done );
		$this->assertSame( WooPaymentsCutoverState::DONE, $done['state'] );
		$this->assertSame( 'done', $done['current_step'] );
		$this->assertNull( $done['next_attempt_at'] );
		$this->assertNull( $done['request_origin_token'] );
	}

	/**
	 * @testdox A throwing feature-seeding callback defers the current revision and retries the idempotent action before completion.
	 */
	public function test_throwing_feature_seeding_callback_defers_the_current_claim(): void {
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array( 'no_changes' ),
				);
			}
		};
		$sut           = new WooPaymentsCutoverReconciliationJob();
		$arbiter       = new class() extends WooPaymentsRuntimeArbiter {
			/** Return an enabled native runtime. */
			public function is_builtin_enabled(): bool {
				return true;
			}
		};
		$sut->init( $arbiter, $this->require_state_store(), $this->require_scheduler(), $this->create_preflight_with_failures( array() ), $normalization, $this->create_native_eligibility_service( true ) );
		$this->jobs[]  = $sut;
		$throwing_seed = static function (): void {
			throw new \RuntimeException( 'Expected feature seeding failure.' );
		};
		add_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $throwing_seed );
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );

			$sut->handle_reconcile( $pending['generation'], 1 );
		} finally {
			remove_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $throwing_seed );
		}

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'feature_seeding_failed' ), $deferred['deferred_codes'] );
		$this->assertNotSame( 'done', $deferred['current_step'] );
		$this->assertContains( array( 'code' => 'feature_seeding_started' ), $deferred['informational_outcomes'] );
		$this->assertNotContains( array( 'code' => 'feature_seeding_completed' ), $deferred['informational_outcomes'] );

		$retry_calls = 0;
		$retry_seed  = static function () use ( &$retry_calls ): void {
			++$retry_calls;
		};
		add_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $retry_seed );
		try {
			$this->require_scheduler()->cancel( $deferred['generation'], 2 );
			$sut->handle_reconcile( $deferred['generation'], 2 );
		} finally {
			remove_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $retry_seed );
		}

		$retried = $this->require_state_store()->get_record();
		$this->assertIsArray( $retried );
		$this->assertSame( 1, $retry_calls );
		$this->assertContains( array( 'code' => 'feature_seeding_completed' ), $retried['informational_outcomes'] );
	}

	/**
	 * @testdox A throwing ownership probe defers verification without leaving its claim running.
	 */
	public function test_throwing_ownership_probe_defers_verification(): void {
		$preflight = $this->create_preflight_with_failures( array() );
		$origin    = $this->create_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$queued = $this->require_state_store()->get_record();
		$this->assertIsArray( $queued );
		$this->require_scheduler()->cancel( $queued['generation'], 1 );
		$verification                         = $queued;
		$verification['revision']             = $queued['revision'] + 1;
		$verification['attempt']              = 1;
		$verification['action_id']            = 0;
		$verification['current_step']         = 'verify_builtin_ownership';
		$verification['next_attempt_at']      = time() + MINUTE_IN_SECONDS;
		$verification['request_origin_token'] = 'previous-request-token';
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $queued, $verification ) );
		$arbiter              = new class() extends WooPaymentsRuntimeArbiter {
			/** Return an enabled native runtime. */
			public function is_builtin_enabled(): bool {
				return true;
			}

			/** Throw while probing plugin ownership. */
			public function is_extension_owner(): bool {
				throw new \RuntimeException( 'Expected ownership probe failure.' );
			}
		};
		$sut                  = new WooPaymentsCutoverReconciliationJob();
		$normalization_runner = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** Return a controlled persistence failure. */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array( 'settings_persistence_failed' ),
				);
			}
		};
		$sut->init( $arbiter, $this->require_state_store(), $this->require_scheduler(), $preflight, $normalization_runner, $this->create_native_eligibility_service( true ) );
		$this->jobs[] = $sut;

		$sut->handle_reconcile( $verification['generation'], 2 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'builtin_ownership_verification_failed' ), $deferred['deferred_codes'] );
	}
	/**
	 * @testdox Manual deactivation keeps the plugin inactive and persists its exact origin with an unresolved disposition.
	 */
	public function test_manual_deactivation_defers_without_reactivating_plugin(): void {
		$preflight = $this->create_preflight_with_failures( array( 'builtin_transport_unavailable' ) );
		$sut       = $this->create_job( true, $preflight );

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'builtin_transport_unavailable' ), $deferred['deferred_codes'] );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $deferred['origin_plugin_file'] );
		$this->assertSame( 'site', $deferred['origin_plugin_scope'] );
		$this->assertFalse( method_exists( $sut, 'activate_woopayments_plugin_file' ) );
	}

	/**
	 * @testdox An all-clear manual deactivation remains native-owned and completes after a future ownership check.
	 */
	public function test_all_clear_manual_deactivation_verifies_native_ownership_without_deactivating_again(): void {
		$deactivation_calls = 0;
		$preflight          = new class( $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param int $deactivation_calls Deactivation calls.
			 */
			public function __construct( int &$deactivation_calls ) {
				$this->deactivation_calls =& $deactivation_calls;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Record any unexpected second deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}

			/**
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; this scenario is site-local, so
			 * the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$normalization      = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut                = $this->create_job( true, $preflight, null, false, $normalization );

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$before = time();
		$sut->handle_reconcile( $pending['generation'], 1 );

		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( 0, $deactivation_calls );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $verification['state'] );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $verification['origin_plugin_file'] );
		$this->assertSame( 'site', $verification['origin_plugin_scope'] );
		$this->assertGreaterThan( $before, $verification['next_attempt_at'] );
		$this->assertGreaterThan( 0, $verification['action_id'] );
		$this->assertSame( $verification['action_id'], $this->require_scheduler()->get_scheduled_action_id( $verification['generation'], 2 ) );

		$this->require_scheduler()->cancel( $verification['generation'], 2 );
		$request_token = new \ReflectionProperty( WooPaymentsCutoverReconciliationJob::class, 'request_token' );
		$request_token->setAccessible( true );
		$request_token->setValue( null );
		$fresh_request = $this->create_job( true, $preflight, null, false, $normalization );
		$fresh_request->handle_reconcile( $verification['generation'], 2 );

		$done = $this->require_state_store()->get_record();
		$this->assertIsArray( $done );
		$this->assertSame( WooPaymentsCutoverState::DONE, $done['state'] );
		$this->assertSame( 'done', $done['current_step'] );
		$this->assertSame( 0, $deactivation_calls );
	}

	/**
	 * @testdox A claim that finds WooPayments already inactive at finalization continues to ownership verification instead of deferring forever.
	 */
	public function test_claim_with_an_already_inactive_plugin_reaches_ownership_verification(): void {
		$sut = $this->create_job( true, $this->create_preflight_with_failures( array() ), null, false, $this->create_noop_normalization() );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $verification['state'] );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->assertSame( array(), $verification['deferred_codes'] );
	}

	/**
	 * @testdox Ownership verification schedules the canceled-authorization fee remediation once native owns the site, for a manual deactivation too.
	 */
	public function test_ownership_verification_schedules_fee_remediation_for_a_manual_deactivation(): void {
		$scheduling_calls = 0;
		$schedulable      = true;
		$throws           = false;
		$preflight        = $this->create_preflight_with_fee_remediation( $scheduling_calls, $schedulable, $throws );
		$sut              = $this->create_job( true, $preflight, null, false, $this->create_noop_normalization() );

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
		$this->assertSame( 0, $scheduling_calls, 'Remediation waits until a fresh request sees native ownership.' );

		$this->require_scheduler()->cancel( $verification['generation'], 2 );
		$this->start_fresh_request();
		$this->create_job( true, $preflight, null, false, $this->create_noop_normalization() )->handle_reconcile( $verification['generation'], 2 );

		$done = $this->require_state_store()->get_record();
		$this->assertIsArray( $done );
		$this->assertSame( WooPaymentsCutoverState::DONE, $done['state'] );
		$this->assertSame( 1, $scheduling_calls );
	}

	/**
	 * @testdox Ownership verification defers instead of finishing when the fee remediation cannot be scheduled, or its scheduling throws, and finishes once it can.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $throws Whether scheduling throws instead of reporting unavailable.
	 */
	public function test_ownership_verification_defers_when_fee_remediation_cannot_be_scheduled( bool $throws ): void {
		$scheduling_calls = 0;
		$schedulable      = false;
		$preflight        = $this->create_preflight_with_fee_remediation( $scheduling_calls, $schedulable, $throws );
		$sut              = $this->create_job( true, $preflight, null, false, $this->create_noop_normalization() );

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
		$verification = $this->require_state_store()->get_record();
		$this->assertIsArray( $verification );
		$this->require_scheduler()->cancel( $verification['generation'], 2 );
		$this->start_fresh_request();
		$this->create_job( true, $preflight, null, false, $this->create_noop_normalization() )->handle_reconcile( $verification['generation'], 2 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'financial_migrations_unavailable' ), $deferred['deferred_codes'] );
		$this->assertSame( 1, $scheduling_calls );

		// Scheduling works again: the retries finish the switch without another merchant action.
		$schedulable = true;
		$throws      = false;
		$record      = $deferred;
		for ( $step = 0; $step < 4 && WooPaymentsCutoverState::DONE !== $record['state']; ++$step ) {
			$next_attempt = $record['attempt'] + 1;
			$this->require_scheduler()->cancel( $record['generation'], $next_attempt );
			$this->start_fresh_request();
			$this->create_job( true, $preflight, null, false, $this->create_noop_normalization() )->handle_reconcile( $record['generation'], $next_attempt );
			$record = $this->require_state_store()->get_record();
			$this->assertIsArray( $record );
		}
		$this->assertSame( WooPaymentsCutoverState::DONE, $record['state'] );
		$this->assertSame( 2, $scheduling_calls, 'The remediation is scheduled once more, by the verification that finishes.' );
	}

	/**
	 * @testdox Ownership verification from a fresh request defers while the plugin still owns the runtime, without finishing or scheduling the fee remediation.
	 */
	public function test_ownership_verification_defers_while_the_plugin_still_runs(): void {
		$scheduling_calls = 0;
		$schedulable      = true;
		$throws           = false;
		$preflight        = $this->create_preflight_with_fee_remediation( $scheduling_calls, $schedulable, $throws );
		$origin           = $this->create_job( true, $preflight );
		$this->assertTrue( $origin->enqueue( 'merchant' ) );
		$queued = $this->require_state_store()->get_record();
		$this->assertIsArray( $queued );
		$this->require_scheduler()->cancel( $queued['generation'], 1 );
		$verification                         = $queued;
		$verification['revision']             = $queued['revision'] + 1;
		$verification['attempt']              = 1;
		$verification['action_id']            = 0;
		$verification['current_step']         = 'verify_builtin_ownership';
		$verification['next_attempt_at']      = time() + MINUTE_IN_SECONDS;
		$verification['request_origin_token'] = 'previous-request-token';
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $queued, $verification ) );

		$this->start_fresh_request();
		$this->create_job( true, $preflight, null, true, $this->create_noop_normalization() )->handle_reconcile( $verification['generation'], 2 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'builtin_ownership_unverified' ), $deferred['deferred_codes'] );
		$this->assertSame( 0, $scheduling_calls, 'The fee remediation waits until native owns the site.' );
	}

	/**
	 * @testdox A merchant-started claim whose plugin deactivation fails (throws: $throws) defers without moving to ownership verification or syncing native state.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $throws Whether deactivation throws instead of reporting failure.
	 */
	public function test_failed_plugin_deactivation_defers_before_ownership_verification( bool $throws ): void {
		$deactivation_calls = 0;
		$preflight          = new class( $deactivation_calls, $throws ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $deactivation_calls;

			/** @var bool */
			private bool $throws;

			/**
			 * @param int  $deactivation_calls Deactivation calls.
			 * @param bool $throws             Whether deactivation throws.
			 */
			public function __construct( int &$deactivation_calls, bool $throws ) {
				$this->deactivation_calls =& $deactivation_calls;
				$this->throws             = $throws;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Fail to deactivate the plugin. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				if ( $this->throws ) {
					throw new \RuntimeException( 'Expected deactivation failure.' );
				}
				return false;
			}

			/** Site-local scenario. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$sync_calls         = 0;
		$account_service    = new class( $sync_calls ) extends WooPaymentsAccountService {
			/** @var int */
			private int $sync_calls;

			/**
			 * @param int $sync_calls Native state synchronization calls.
			 */
			public function __construct( int &$sync_calls ) {
				$this->sync_calls =& $sync_calls;
			}

			/** The account is native-eligible. */
			public function is_native_eligible(): bool {
				return true;
			}

			/**
			 * Count native state synchronizations.
			 *
			 * @param bool $plugin_runtime_active Whether the plugin owns the runtime.
			 */
			public function sync_setup_tier_from_options( bool $plugin_runtime_active ): void {
				unset( $plugin_runtime_active );
				++$this->sync_calls;
			}
		};
		$sut                = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization(), true, $account_service );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( 1, $deactivation_calls );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'plugin_deactivation_failed' ), $deferred['deferred_codes'] );
		$this->assertSame( 'deferred', $deferred['current_step'] );
		$this->assertSame( 0, $sync_calls, 'Native state must not be synced while the plugin still runs.' );
	}

	/**
	 * @testdox A Stripe Billing marker first seen on the re-read after the dispositions excludes the switch on that attempt, without deactivating the plugin.
	 */
	public function test_stripe_billing_marker_seen_after_dispositions_excludes_on_the_same_attempt(): void {
		$reads              = 0;
		$deactivation_calls = 0;
		$preflight          = new class( $reads, $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $reads;

			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param int $reads              Reconciliation failure reads.
			 * @param int $deactivation_calls Deactivation calls.
			 */
			public function __construct( int &$reads, int &$deactivation_calls ) {
				$this->reads              =& $reads;
				$this->deactivation_calls =& $deactivation_calls;
			}

			/**
			 * Report all clear on the first read and the marker on every later read.
			 *
			 * @return string[]
			 */
			public function get_reconciliation_failures(): array {
				++$this->reads;
				return 1 === $this->reads ? array() : array( 'bundled_stripe_billing_subscriptions_present' );
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Record any deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}

			/** Site-local scenario. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$sut                = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$excluded = $this->require_state_store()->get_record();
		$this->assertIsArray( $excluded );
		$this->assertGreaterThanOrEqual( 2, $reads, 'The marker was read again after the dispositions.' );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $excluded['state'] );
		$this->assertSame( array( 'bundled_stripe_billing_subscriptions_present' ), $excluded['deferred_codes'] );
		$this->assertSame( 0, $deactivation_calls );
	}

	/**
	 * Clear the request token so the next job instance runs as a fresh request.
	 */
	private function start_fresh_request(): void {
		$request_token = new \ReflectionProperty( WooPaymentsCutoverReconciliationJob::class, 'request_token' );
		$request_token->setAccessible( true );
		$request_token->setValue( null );
	}

	/**
	 * Create a normalization runner that changes nothing.
	 *
	 * @return WooPaymentsCutoverNormalizationRunner
	 */
	private function create_noop_normalization(): WooPaymentsCutoverNormalizationRunner {
		return new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
	}

	/**
	 * Create an all-clear site-local preflight whose fee remediation scheduling is controlled.
	 *
	 * @param int  $scheduling_calls Counter of fee remediation scheduling calls.
	 * @param bool $schedulable      Whether scheduling succeeds.
	 * @param bool $throws           Whether scheduling throws.
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_preflight_with_fee_remediation( int &$scheduling_calls, bool &$schedulable, bool &$throws ): WooPaymentsCutoverPreflightService {
		return new class( $scheduling_calls, $schedulable, $throws ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $scheduling_calls;

			/** @var bool */
			private bool $schedulable;

			/** @var bool */
			private bool $throws;

			/**
			 * @param int  $scheduling_calls Counter of fee remediation scheduling calls.
			 * @param bool $schedulable      Whether scheduling succeeds.
			 * @param bool $throws           Whether scheduling throws.
			 */
			public function __construct( int &$scheduling_calls, bool &$schedulable, bool &$throws ) {
				$this->scheduling_calls =& $scheduling_calls;
				$this->schedulable      =& $schedulable;
				$this->throws           =& $throws;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** The plugin is already inactive in these scenarios. */
			public function deactivate_woopayments_plugin(): bool {
				return true;
			}

			/** Count and control the fee remediation scheduling. */
			public function ensure_fee_remediation_scheduled(): bool {
				++$this->scheduling_calls;
				if ( $this->throws ) {
					throw new \RuntimeException( 'Controlled scheduling failure.' );
				}
				return $this->schedulable;
			}

			/** Site-local scenario. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
	}

	/**
	 * @testdox Every exceptional manual-deactivation exit defers without reactivating and retains exact origin metadata.
	 * @dataProvider manual_exception_provider
	 *
	 * @param string $failure_step  Controlled failing step.
	 * @param string $expected_code Expected deferred code.
	 */
	public function test_manual_deactivation_exception_defers_without_reactivation( string $failure_step, string $expected_code ): void {
		$preflight     = new class( $failure_step ) extends WooPaymentsCutoverPreflightService {
			/** @var string */
			private string $failure_step;

			/**
			 * @param string $failure_step Controlled failing step.
			 */
			public function __construct( string $failure_step ) {
				$this->failure_step = $failure_step;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				if ( 'resolver' === $this->failure_step ) {
					throw new \RuntimeException( 'Expected resolver failure.' );
				}
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/**
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; this scenario is site-local, so
			 * the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$normalization = new class( $failure_step ) extends WooPaymentsCutoverNormalizationRunner {
			/** @var string */
			private string $failure_step;

			/**
			 * @param string $failure_step Controlled failing step.
			 */
			public function __construct( string $failure_step ) {
				$this->failure_step = $failure_step;
			}

			/** Return controlled normalization. */
			public function run(): array {
				if ( 'normalization' === $this->failure_step ) {
					throw new \RuntimeException( 'Expected normalization failure.' );
				}
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut           = $this->create_job( true, $preflight, null, true, $normalization );
		$throwing_seed = static function (): void {
			throw new \RuntimeException( 'Expected feature seeding failure.' );
		};
		if ( 'feature_seeding' === $failure_step ) {
			add_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $throwing_seed );
		}
		try {
			$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );
			$sut->handle_reconcile( $pending['generation'], 1 );
		} finally {
			remove_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $throwing_seed );
		}

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( $expected_code ), $deferred['deferred_codes'] );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $deferred['origin_plugin_file'] );
		$this->assertSame( 'site', $deferred['origin_plugin_scope'] );
	}

	/** @return array<string,array{string,string}> */
	public function manual_exception_provider(): array {
		return array(
			'resolver'        => array( 'resolver', 'reconciliation_resolver_failed' ),
			'normalization'   => array( 'normalization', 'normalization_failed' ),
			'feature seeding' => array( 'feature_seeding', 'feature_seeding_failed' ),
		);
	}

	/**
	 * @testdox A completed cutover keeps the completion notice until a manager dismisses it, and records the dismissal on the durable record.
	 */
	public function test_completion_notice_stays_until_dismissed(): void {
		$sut   = $this->create_job( true );
		$store = $this->require_state_store();
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $store->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$done                           = $pending;
		$done['revision']               = $pending['revision'] + 1;
		$done['state']                  = WooPaymentsCutoverState::DONE;
		$done['action_id']              = 0;
		$done['current_step']           = 'done';
		$done['next_attempt_at']        = null;
		$done['informational_outcomes'] = array();
		$this->assertTrue( $store->compare_and_set_record( $pending, $done ) );
		$success = WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS;

		for ( $page = 0; $page < 2; $page++ ) {
			$this->assertTrue( $sut->is_completion_notice_due( $store->get_record(), $success ) );
		}
		$this->assertSame( $done, $store->get_record(), 'Showing the notice must not change the record.' );
		$this->assertFalse( $sut->dismiss_completion_notice( 'unknown' ) );
		$this->assertSame( $done, $store->get_record(), 'An unknown notice must not change the record.' );

		$this->assertTrue( $sut->dismiss_completion_notice( $success ) );
		$this->assertFalse( $sut->dismiss_completion_notice( $success ), 'A dismissed notice stays dismissed.' );
		wp_cache_flush();
		$dismissed = $store->get_record();
		$this->assertIsArray( $dismissed );
		$this->assertSame( $done['revision'] + 1, $dismissed['revision'] );
		$this->assertContains( array( 'code' => 'success_notice_dismissed' ), $dismissed['informational_outcomes'] );
		$this->assertFalse( $sut->is_completion_notice_due( $dismissed, $success ) );
	}

	/**
	 * @testdox Admin-notice classification on a plugin store awaiting the start click scans once per record revision, not on every admin page.
	 */
	public function test_admin_notice_classification_reuses_the_scan_for_one_record_revision(): void {
		$preflight = new class() extends WooPaymentsCutoverPreflightService {
			/** @var int Number of preflight scans. */
			public int $scans = 0;

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				++$this->scans;
				return array();
			}
		};
		$sut       = $this->create_job( true, $preflight );

		for ( $page = 0; $page < 3; $page++ ) {
			$this->assertNull( $sut->classify_for_admin_notice() );
			$this->assertTrue( $sut->should_offer_start() );
		}
		$this->assertSame( 1, $preflight->scans, 'Three admin pages without a record should share one scan.' );

		$cached               = get_option( 'woocommerce_woopayments_cutover_admin_classification' );
		$cached['expires_at'] = time() - 1;
		update_option( 'woocommerce_woopayments_cutover_admin_classification', $cached );
		$sut->classify_for_admin_notice();
		$this->assertSame( 2, $preflight->scans, 'An expired classification must be scanned again.' );
	}

	/**
	 * @testdox Admin-notice classification runs no preflight scan on a native store that has no cutover record and no plugin.
	 */
	public function test_admin_notice_classification_skips_preflight_without_record_or_plugin(): void {
		$preflight = new class() extends WooPaymentsCutoverPreflightService {
			/** @var int Number of preflight scans. */
			public int $scans = 0;

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				++$this->scans;
				return array( 'bundled_stripe_billing_subscriptions_present' );
			}
		};
		$sut       = $this->create_job( true, $preflight, null, false );

		$this->assertNull( $sut->classify_for_admin_notice() );
		$this->assertSame( 0, $preflight->scans, 'Every admin page would otherwise scan Action Scheduler, order meta and tables.' );
		$this->assertNull( $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox A bundled-flavor store owes its notice once per exclusion: dismissed, it stays dismissed; reopened and excluded again, it is owed again (spec section 7).
	 */
	public function test_bundled_exclusion_notice_is_owed_once_per_exclusion(): void {
		$failures  = array( 'bundled_stripe_billing_subscriptions_present' );
		$preflight = $this->create_controllable_preflight( $failures );
		$sut       = $this->create_job( true, $preflight );
		$bundled   = WooPaymentsCutoverReconciliationJob::NOTICE_BUNDLED_EXCLUSION;

		$excluded = $sut->classify_for_admin_notice();
		$this->assertTrue( $sut->is_bundled_exclusion_notice_due( $excluded ) );
		$this->assertTrue( $sut->dismiss_completion_notice( $bundled ) );
		$this->assertFalse( $sut->dismiss_completion_notice( $bundled ), 'A dismissed notice stays dismissed.' );
		wp_cache_flush();
		$this->assertFalse( $sut->is_bundled_exclusion_notice_due( $this->require_state_store()->get_record() ) );

		$failures = array();
		$awaiting = $sut->classify_for_admin_notice();
		$this->assertFalse( $sut->is_bundled_exclusion_notice_due( $awaiting ), 'A store that can switch gets the start notice instead.' );
		$failures   = array( 'bundled_stripe_billing_subscriptions_present' );
		$reexcluded = $sut->classify_for_admin_notice();
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $reexcluded['state'] );
		$this->assertSame( $excluded['generation'] + 1, $reexcluded['generation'] );
		$this->assertTrue( $sut->is_bundled_exclusion_notice_due( $reexcluded ), 'The dismissal belonged to the earlier exclusion.' );
	}

	/**
	 * @testdox An excluded store waits for the hour-long cached classification, unless it is forgotten, as when WooCommerce Subscriptions is activated.
	 */
	public function test_forgetting_the_admin_classification_reopens_an_excluded_store_at_once(): void {
		$failures  = array( 'bundled_stripe_billing_subscriptions_present' );
		$preflight = $this->create_controllable_preflight( $failures );
		$sut       = $this->create_job( true, $preflight );
		$excluded  = $sut->classify_for_admin_notice();
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $excluded['state'] );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $sut->classify_for_admin_notice()['state'], 'The excluded record is classified and cached.' );

		$failures = array();
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $sut->classify_for_admin_notice()['state'], 'The cached classification still says bundled.' );
		$sut->forget_admin_classification();

		$this->assertSame( 'awaiting_merchant_start', $sut->classify_for_admin_notice()['current_step'] );
	}

	/**
	 * Create a preflight double that reports the given failures, by reference so a test can change them.
	 *
	 * @param string[] $failures Failures to report.
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_controllable_preflight( array &$failures ): WooPaymentsCutoverPreflightService {
		return new class( $failures ) extends WooPaymentsCutoverPreflightService {
			/** @var string[] */
			private array $failures;

			/**
			 * @param string[] $failures Controlled failures.
			 */
			public function __construct( array &$failures ) {
				$this->failures =& $failures;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures;
			}

			/** Report a site-local activation; this double never initializes the legacy proxy. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
	}

	/**
	 * @testdox Stripe exclusion removal opens an unscheduled generation that only a merchant click can start.
	 */
	public function test_removed_stripe_exclusion_waits_for_merchant_start(): void {
		$failures  = array( 'bundled_stripe_billing_subscriptions_present' );
		$preflight = new class( $failures ) extends WooPaymentsCutoverPreflightService {
			/** @var string[] */
			private array $failures;

			/**
			 * @param string[] $failures Controlled failures.
			 */
			public function __construct( array &$failures ) {
				$this->failures =& $failures;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures;
			}

			/**
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; this scenario is site-local, so
			 * the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$sut       = $this->create_job( true, $preflight );

		$excluded = $sut->classify_for_admin_notice();
		$this->assertIsArray( $excluded );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $excluded['state'] );
		$this->assertSame( 0, $excluded['action_id'] );
		$failures = array();

		$awaiting = $sut->classify_for_admin_notice();
		$this->assertIsArray( $awaiting );
		$this->assertSame( $excluded['generation'] + 1, $awaiting['generation'] );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $awaiting['state'] );
		$this->assertSame( 'awaiting_merchant_start', $awaiting['current_step'] );
		$this->assertSame( 0, $awaiting['action_id'] );
		$failures   = array( 'bundled_stripe_billing_subscriptions_present' );
		$reexcluded = $sut->classify_for_admin_notice();
		$this->assertIsArray( $reexcluded );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $reexcluded['state'] );
		$this->assertSame( $awaiting['generation'], $reexcluded['generation'] );
		$failures = array();
		$awaiting = $sut->classify_for_admin_notice();
		$this->assertIsArray( $awaiting );
		$this->assertSame( 'awaiting_merchant_start', $awaiting['current_step'] );
		$sut->register();
		$this->assertSame( 0, $this->require_scheduler()->get_scheduled_action_id( $awaiting['generation'], 1 ) );

		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$queued = $this->require_state_store()->get_record();
		$this->assertIsArray( $queued );
		$this->assertSame( $awaiting['generation'], $queued['generation'] );
		$this->assertSame( 'queued', $queued['current_step'] );
		$this->assertGreaterThan( 0, $queued['action_id'] );
	}

	/**
	 * @testdox The start click on a Stripe-excluded store runs a fresh preflight, so a cached "marker gone" admin classification cannot reopen the switch.
	 */
	public function test_start_click_ignores_the_cached_admin_classification(): void {
		$preflight = $this->create_preflight_with_failures( array( 'bundled_stripe_billing_subscriptions_present' ) );
		$sut       = $this->create_job( true, $preflight );
		$excluded  = $sut->classify_for_admin_notice();
		$this->assertIsArray( $excluded );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $excluded['state'] );
		// An earlier admin page cached this revision as "marker gone"; the subscription is still there.
		update_option(
			WooPaymentsCutoverReconciliationJob::ADMIN_CLASSIFICATION_OPTION,
			array(
				'key'        => $excluded['generation'] . ':' . $excluded['revision'],
				'present'    => false,
				'expires_at' => time() + HOUR_IN_SECONDS,
			),
			true
		);

		$this->assertTrue( $sut->enqueue( 'merchant' ) );

		$this->assertSame( $excluded, $this->require_state_store()->get_record(), 'A store that still has Stripe Billing subscriptions must stay excluded after the click.' );
		$this->assertSame( 0, $this->require_scheduler()->get_scheduled_action_id( $excluded['generation'] + 1, 1 ), 'No generation may be scheduled.' );
	}

	/** @testdox A manual deactivation supersedes a current Stripe exclusion and persists the same exclusion without reactivation. */
	public function test_manual_deactivation_supersedes_a_current_stripe_exclusion(): void {
		$preflight = $this->create_preflight_with_failures( array( 'bundled_stripe_billing_subscriptions_present' ) );
		$sut       = $this->create_job( true, $preflight );
		$excluded  = $sut->classify_for_admin_notice();
		$this->assertIsArray( $excluded );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $excluded['state'] );

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
		$manual = $this->require_state_store()->get_record();
		$this->assertIsArray( $manual );
		$this->assertSame( $excluded['generation'] + 1, $manual['generation'] );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $manual['state'] );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $manual['origin_plugin_file'] );
		$this->assertSame( 'site', $manual['origin_plugin_scope'] );
		$this->assertGreaterThan( 0, $manual['action_id'] );
		$this->require_scheduler()->cancel( $manual['generation'], 1 );
		$sut->handle_reconcile( $manual['generation'], 1 );
		$manual_excluded = $this->require_state_store()->get_record();
		$this->assertIsArray( $manual_excluded );
		$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $manual_excluded['state'] );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $manual_excluded['origin_plugin_file'] );
		$this->assertSame( 'site', $manual_excluded['origin_plugin_scope'] );
	}

	/** @testdox A classifier cannot overwrite pending work created while it resolves an initial Stripe marker. */
	public function test_no_record_notice_classifier_rereads_under_the_state_lease(): void {
		$job       = null;
		$preflight = new class( $job ) extends WooPaymentsCutoverPreflightService {
			/** @var WooPaymentsCutoverReconciliationJob|null */
			private ?WooPaymentsCutoverReconciliationJob $job;

			/**
			 * @param WooPaymentsCutoverReconciliationJob|null $job Concurrent job.
			 */
			public function __construct( ?WooPaymentsCutoverReconciliationJob &$job ) {
				$this->job =& $job;
			}

			/** Resolve a marker after interleaving a merchant enqueue. */
			public function get_reconciliation_failures(): array {
				if ( $this->job instanceof WooPaymentsCutoverReconciliationJob ) {
					$job       = $this->job;
					$this->job = null;
					$job->enqueue( 'merchant' );
				}
				return array( 'bundled_stripe_billing_subscriptions_present' );
			}

			/**
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; this scenario is site-local, so
			 * the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$job       = $this->create_job( true, $preflight );

		$classified = $job->classify_for_admin_notice();
		$this->assertIsArray( $classified );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $classified['state'] );
		$this->assertSame( 'queued', $classified['current_step'] );
		$this->assertGreaterThan( 0, $classified['action_id'] );
	}

	/**
	 * @testdox Manual deactivation supersedes an existing queued attempt with exact origin context and a fresh action.
	 */
	public function test_manual_deactivation_replaces_pending_action_with_exact_origin(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A network-scope deactivation takes the network path on multisite; see the multisite twin below.' );
		}

		$sut = $this->require_sut();
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$queued = $this->require_state_store()->get_record();
		$this->assertIsArray( $queued );
		$old_action_id = $queued['action_id'];

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', true ) );
		$manual = $this->require_state_store()->get_record();
		$this->assertIsArray( $manual );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $manual['origin_plugin_file'] );
		$this->assertSame( 'network', $manual['origin_plugin_scope'] );
		$this->assertSame( $queued['attempt'] + 1, $manual['attempt'] );
		$this->assertNotSame( $old_action_id, $manual['action_id'] );
		$this->assertSame( ActionScheduler_Store::STATUS_CANCELED, ActionScheduler::store()->get_status( $old_action_id ) );
		$this->assertSame( $manual['action_id'], $this->require_scheduler()->get_scheduled_action_id( $manual['generation'], $manual['attempt'] + 1 ) );
	}

	/**
	 * @testdox Network deactivation of a plugin that was site-active when the cutover started opens a new network generation with the exact origin on every site.
	 *
	 * Source: plan-cutover-reconciliation.md:112-113 (a manual deactivation opens a new generation carrying its exact origin).
	 *
	 * @group multisite
	 */
	public function test_network_deactivation_of_a_site_level_cutover_carries_the_exact_origin_everywhere(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-network-deactivation.example.org' );
		$preflight      = new class() extends WooPaymentsCutoverPreflightService {
			/** @var bool */
			public bool $network_active = false;

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return the controlled network activation. */
			public function is_woopayments_network_active(): bool {
				return $this->network_active;
			}
		};
		$sut            = $this->create_job( true, $preflight );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$site_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $site_pending );
			$this->assertFalse( $site_pending['network_cutover'] );
			$old_action_id = $site_pending['action_id'];
			switch_to_blog( $second_site_id );
			$this->assertNull( $this->require_state_store()->get_record(), 'A site-level cutover must not reach other sites.' );
			restore_current_blog();

			$preflight->network_active = true;
			$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', true ) );

			$this->assertSame( ActionScheduler_Store::STATUS_CANCELED, ActionScheduler::store()->get_status( $old_action_id ) );
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$manual = $this->require_state_store()->get_record();
				$this->assertIsArray( $manual );
				$this->assertSame( $site_pending['generation'] + 1, $manual['generation'], "Site {$site_id} must join the new network generation." );
				$this->assertTrue( $manual['network_cutover'] );
				$this->assertSame( WooPaymentsCutoverState::PENDING, $manual['state'] );
				$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $manual['origin_plugin_file'] );
				$this->assertSame( 'network', $manual['origin_plugin_scope'] );
				$this->assertGreaterThan( 0, $manual['action_id'] );
				$this->assertSame( $manual['action_id'], $this->require_scheduler()->get_scheduled_action_id( $manual['generation'], $manual['attempt'] + 1 ) );
				restore_current_blog();
			}
		} finally {
			while ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox Manual deactivation fences a running worker before recording exact origin context.
	 */
	public function test_manual_deactivation_supersedes_running_claim(): void {
		$sut = $this->require_sut();
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$running = $this->create_running_record( $pending );

		$this->assertTrue( $sut->enqueue_manual_deactivation( 'renamed-wcpay/woocommerce-payments.php', false ) );
		$manual = $this->require_state_store()->get_record();
		$this->assertIsArray( $manual );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $manual['state'] );
		$this->assertSame( 'renamed-wcpay/woocommerce-payments.php', $manual['origin_plugin_file'] );
		$this->assertSame( 'site', $manual['origin_plugin_scope'] );
		$this->assertFalse( $sut->defer( $running, array( 'builtin_transport_unavailable' ) ) );
		$this->assertSame( $manual, $this->require_state_store()->get_record() );
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
		$sut       = $this->create_job_with_preflight( 'builtin_runtime_disabled' !== $condition, $preflight );

		if ( 'builtin_runtime_disabled' === $condition ) {
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
			'native runtime disabled'                    => array( 'builtin_runtime_disabled', 'none' ),
			'unsupported WooPayments version'            => array( 'woopayments_plugin_version_unsupported', WooPaymentsCutoverState::DEFERRED ),
			'operational queue hooks'                    => array( 'operational_queue_hooks_unhandled', WooPaymentsCutoverState::DEFERRED ),
			'legacy Stripe Billing subscriptions'        => array( 'bundled_stripe_billing_subscriptions_present', WooPaymentsCutoverState::EXCLUDED ),
			'native transport unavailable'               => array( 'builtin_transport_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'multi-currency rates unavailable'           => array( 'multi_currency_rates_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'native admin surfaces unavailable'          => array( 'builtin_admin_surfaces_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'provider events undispositioned'            => array( 'provider_events_unhandled', WooPaymentsCutoverState::DEFERRED ),
			'financial migrations unavailable'           => array( 'financial_migrations_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com blog ID unavailable'          => array( 'wpcom_blog_id_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com connection unavailable'       => array( 'wpcom_connection_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com connection owner unavailable' => array( 'wpcom_connection_owner_unavailable', WooPaymentsCutoverState::DEFERRED ),
			'WordPress.com owner token unavailable'      => array( 'wpcom_connection_owner_user_token_unavailable', WooPaymentsCutoverState::DEFERRED ),
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
		$this->assertTrue( $sut->consume_reconnect_notice(), 'The first admin request must atomically claim the sanctioned notice.' );
		$this->assertFalse( $sut->consume_reconnect_notice(), 'A concurrent or later admin request must not show the notice again.' );
		$after_notice = $this->require_state_store()->get_record();
		$this->assertIsArray( $after_notice );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $after_notice['state'], 'Consuming information must not stop silent retries.' );
	}

	/** @testdox An admin page with no reconnect notice due takes no cutover lease. */
	public function test_reconnect_notice_check_takes_no_lease_when_nothing_is_due(): void {
		$sut = $this->create_job( true );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$lease_events = $this->capture_lease_write_events(
			function () use ( $sut ): void {
				$this->assertFalse( $sut->consume_reconnect_notice() );
			}
		);

		$this->assertSame( array(), $lease_events, 'Every admin page renders this check while a switch is pending.' );
	}

	/** @testdox Consuming reconnect information cannot revise or fence a live worker claim. */
	public function test_reconnect_notice_consumption_does_not_revise_a_running_claim(): void {
		$preflight = $this->create_preflight_with_failures( array( 'wpcom_connection_owner_user_token_unavailable' ), true );
		$sut       = $this->create_job_with_preflight( true, $preflight );
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->require_scheduler()->cancel( $deferred['generation'], $deferred['attempt'] + 1 );
		$running = $this->create_running_record( $deferred );

		$this->assertFalse( $sut->consume_reconnect_notice() );
		$this->assertSame( $running, $this->require_state_store()->get_record() );
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
	 * @testdox Reconciliation adopts native queue callbacks, Stripe Billing migrations included: a pending migration stays queued for native's migrator (spec section 7).
	 */
	public function test_reconciliation_adopts_native_queue_callbacks_and_stripe_billing_migrations(): void {
		$native_action_id   = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_store_setup_sync', array(), 'cutover-test', false );
		$migrator_action_id = as_schedule_single_action(
			time() - MINUTE_IN_SECONDS,
			'wcpay_migrate_subscription_retry',
			array(
				'migrate_subscription' => 4712,
				'attempt'              => 1,
			),
			'cutover-test',
			false
		);
		$this->assertIsInt( $native_action_id );
		$this->assertIsInt( $migrator_action_id );
		$preflight = $this->create_preflight_with_failures(
			array( 'operational_queue_hooks_unhandled' ),
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

		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $native_action_id ) );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $migrator_action_id ) );
		$this->assertSame(
			array(
				'migrate_subscription' => 4712,
				'attempt'              => 1,
			),
			ActionScheduler::store()->fetch_action( (string) $migrator_action_id )->get_args()
		);
		foreach ( array( 'wcpay_store_setup_sync', 'wcpay_migrate_subscription_retry' ) as $hook ) {
			$this->assertContains(
				array(
					'code' => 'operational_action_adopted',
					'hook' => $hook,
				),
				$deferred['informational_outcomes']
			);
		}
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
		$this->assertSame( array( 'normalization_failed' ), $deferred['deferred_codes'] );
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
	 * @testdox A site that disallows file modifications defers the switch with the version code and never runs the updater.
	 */
	public function test_disallowed_file_modifications_defer_without_running_the_updater(): void {
		$job       = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int */
			public int $upgrade_count = 0;

			/** @var int */
			public int $metadata_refreshes = 0;

			/** Record any update preparation. */
			protected function refresh_plugin_update_metadata(): void {
				++$this->metadata_refreshes;
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
		};
		$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), 'woocommerce-payments/woocommerce-payments.php' );
		$sut       = $this->create_job( true, $preflight, $job );
		// DISALLOW_FILE_MODS and hosts set this policy through wp_is_file_mod_allowed(); the upgrader classes do not check it.
		add_filter(
			'file_mod_allowed',
			static function ( $allowed, $context ) {
				return 'automatic_updater' === $context ? false : $allowed;
			},
			10,
			2
		);

		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $deferred['deferred_codes'] );
		$this->assertSame( 0, $job->upgrade_count );
		$this->assertSame( 0, $job->metadata_refreshes, 'Nothing is prepared for an update the site forbids.' );
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
	 * @testdox A started network source queues an awaiting same-generation peer before entering the barrier.
	 * @group multisite
	 */
	public function test_network_repair_starts_an_awaiting_same_generation_peer(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-awaiting-peer.example.org' );
		$sut            = $this->create_job( true, $this->create_network_preflight_with_failures( array() ) );
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$this->assertSame( 1, $this->require_scheduler()->dispatch_count, 'A network merchant start should request one immediate async dispatch.' );
			$this->assertTrue( $this->require_scheduler()->dispatch_observed_released_lease, 'Network fan-out must release the main-site lease before dispatch.' );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			$peer = $this->require_state_store()->get_record();
			$this->assertIsArray( $peer );
			$this->require_scheduler()->cancel( $peer['generation'], 1 );
			$awaiting                    = $peer;
			$awaiting['revision']        = $peer['revision'] + 1;
			$awaiting['action_id']       = 0;
			$awaiting['current_step']    = 'awaiting_merchant_start';
			$awaiting['next_attempt_at'] = null;
			$this->assertTrue( $this->require_state_store()->compare_and_set_record( $peer, $awaiting ) );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			switch_to_blog( $second_site_id );
			$started_peer = $this->require_state_store()->get_record();
			$this->assertIsArray( $started_peer );
			$this->assertSame( $main_pending['generation'], $started_peer['generation'] );
			$this->assertSame( WooPaymentsCutoverState::PENDING, $started_peer['state'] );
			$this->assertSame( 'queued', $started_peer['current_step'] );
			$this->assertGreaterThan( 0, $started_peer['action_id'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A stale lower callback mirrors a higher awaiting generation's origin without scheduling or regressing it.
	 * @group multisite
	 */
	public function test_stale_network_callback_mirrors_higher_awaiting_origin_without_starting_it(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-higher-awaiting.example.org' );
		$sut            = $this->create_job( true, $this->create_network_preflight_with_failures( array() ) );
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			$peer = $this->require_state_store()->get_record();
			$this->assertIsArray( $peer );
			$this->require_scheduler()->cancel( $peer['generation'], 1 );
			$higher                        = $peer;
			$higher['generation']          = $peer['generation'] + 1;
			$higher['revision']            = $peer['revision'] + 1;
			$higher['action_id']           = 0;
			$higher['current_step']        = 'awaiting_merchant_start';
			$higher['next_attempt_at']     = null;
			$higher['network_cutover']     = false;
			$higher['origin_plugin_file']  = 'newer-wcpay/woocommerce-payments.php';
			$higher['origin_plugin_scope'] = 'network';
			$this->assertTrue( $this->require_state_store()->compare_and_set_record( $peer, $higher ) );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$superseded = $this->require_state_store()->get_record();
			$this->assertIsArray( $superseded );
			$this->assertSame( $higher['generation'], $superseded['generation'] );
			$this->assertSame( WooPaymentsCutoverState::PENDING, $superseded['state'] );
			$this->assertSame( 'awaiting_merchant_start', $superseded['current_step'] );
			$this->assertSame( 0, $superseded['action_id'] );
			$this->assertSame( 'newer-wcpay/woocommerce-payments.php', $superseded['origin_plugin_file'] );
			$this->assertSame( 'network', $superseded['origin_plugin_scope'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A source callback observing a same-generation excluded peer converges the whole network to exclusion.
	 * @group multisite
	 */
	public function test_network_repair_propagates_a_same_generation_peer_exclusion(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-peer-exclusion.example.org' );
		$sut            = $this->create_job( true, $this->create_network_preflight_with_failures( array() ) );
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			$peer = $this->require_state_store()->get_record();
			$this->assertIsArray( $peer );
			$this->require_scheduler()->cancel( $peer['generation'], 1 );
			$excluded                     = $peer;
			$excluded['revision']         = $peer['revision'] + 1;
			$excluded['state']            = WooPaymentsCutoverState::EXCLUDED;
			$excluded['action_id']        = 0;
			$excluded['current_step']     = 'excluded';
			$excluded['deferred_codes']   = array( 'bundled_stripe_billing_subscriptions_present' );
			$excluded['next_attempt_at']  = null;
			$excluded['lease_token']      = null;
			$excluded['lease_expires_at'] = null;
			$this->assertTrue( $this->require_state_store()->compare_and_set_record( $peer, $excluded ) );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $this->require_state_store()->get_record()['state'] ?? null );
			switch_to_blog( $second_site_id );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $this->require_state_store()->get_record()['state'] ?? null );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox Network-wide plugin activation opens one coherent unscheduled rollback generation on every site.
	 * @group multisite
	 */
	public function test_network_plugin_activation_opens_one_awaiting_generation_everywhere(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-network-rollback.example.org' );
		$sut            = $this->create_job( true, $this->create_network_preflight_with_failures( array() ) );
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$generation = 0;
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$pending = $this->require_state_store()->get_record();
				$this->assertIsArray( $pending );
				$generation = $pending['generation'];
				$this->require_scheduler()->cancel( $generation, 1 );
				$done                         = $pending;
				$done['revision']             = $pending['revision'] + 1;
				$done['state']                = WooPaymentsCutoverState::DONE;
				$done['action_id']            = 0;
				$done['current_step']         = 'done';
				$done['next_attempt_at']      = null;
				$done['request_origin_token'] = null;
				$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $done ) );
				if ( get_current_blog_id() !== $main_site_id ) {
					restore_current_blog();
				}
			}

			$this->assertTrue( $sut->record_plugin_activation( true ) );
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$awaiting = $this->require_state_store()->get_record();
				$this->assertIsArray( $awaiting );
				$this->assertSame( $generation + 1, $awaiting['generation'] );
				$this->assertSame( WooPaymentsCutoverState::PENDING, $awaiting['state'] );
				$this->assertSame( 'awaiting_merchant_start', $awaiting['current_step'] );
				$this->assertSame( 0, $awaiting['action_id'] );
				$this->assertTrue( $awaiting['network_cutover'] );
				$this->assertSame( 0, $this->require_scheduler()->get_scheduled_action_id( $awaiting['generation'], 1 ) );
				if ( get_current_blog_id() !== $main_site_id ) {
					restore_current_blog();
				}
			}
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A partially recorded network rollback converges when the merchant starts the new generation.
	 * @group multisite
	 */
	public function test_network_plugin_activation_lease_contention_converges_on_merchant_start(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-network-rollback-contention.example.org' );
		$sut            = $this->create_job( true, $this->create_network_preflight_with_failures( array() ) );
		$site_token     = null;
		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$generation = 0;
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$pending = $this->require_state_store()->get_record();
				$this->assertIsArray( $pending );
				$generation = $pending['generation'];
				$this->require_scheduler()->cancel( $generation, 1 );
				$done                         = $pending;
				$done['revision']             = $pending['revision'] + 1;
				$done['state']                = WooPaymentsCutoverState::DONE;
				$done['action_id']            = 0;
				$done['current_step']         = 'done';
				$done['next_attempt_at']      = null;
				$done['request_origin_token'] = null;
				$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $done ) );
				if ( get_current_blog_id() !== $main_site_id ) {
					restore_current_blog();
				}
			}

			switch_to_blog( $second_site_id );
			$site_token = $this->require_state_store()->acquire_lease( time() );
			$this->assertIsString( $site_token );
			restore_current_blog();

			$this->assertFalse( $sut->record_plugin_activation( true ) );
			$partial = $this->require_state_store()->get_record();
			$this->assertIsArray( $partial );
			$this->assertSame( $generation + 1, $partial['generation'] );
			$this->assertSame( 'awaiting_merchant_start', $partial['current_step'] );
			$this->assertSame( 0, $partial['action_id'] );

			switch_to_blog( $second_site_id );
			$this->require_state_store()->release_lease( $site_token );
			$site_token = null;
			restore_current_blog();

			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$started = $this->require_state_store()->get_record();
				$this->assertIsArray( $started );
				$this->assertSame( $generation + 1, $started['generation'] );
				$this->assertSame( WooPaymentsCutoverState::PENDING, $started['state'] );
				$this->assertSame( 'queued', $started['current_step'] );
				$this->assertGreaterThan( 0, $started['action_id'] );
				if ( get_current_blog_id() !== $main_site_id ) {
					restore_current_blog();
				}
			}
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				if ( is_string( $site_token ) ) {
					$this->require_state_store()->release_lease( $site_token );
				}
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A network merchant start fans out site-local state and the last ready site completes the barrier once.
	 * @group multisite
	 */
	public function test_network_fanout_and_all_ready_barrier(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = self::factory()->blog->create(
			array(
				'domain' => 'cutover-barrier.example.org',
				'path'   => '/',
			)
		);
		switch_to_blog( $second_site_id );
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		restore_current_blog();
		$failures_by_site   = array(
			$main_site_id   => array(),
			$second_site_id => array(),
		);
		$deactivation_calls = 0;
		$preflight          = new class( $failures_by_site, $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,string[]> */
			private array $failures_by_site;

			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param array<int,string[]> $failures_by_site  Controlled failures.
			 * @param int                 $deactivation_calls Deactivation calls.
			 */
			public function __construct( array &$failures_by_site, int &$deactivation_calls ) {
				$this->failures_by_site   =& $failures_by_site;
				$this->deactivation_calls =& $deactivation_calls;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures_by_site[ get_current_blog_id() ] ?? array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}

			/** Record one network deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}
		};
		$normalization      = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** Return successful normalization. */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut                = $this->create_job( true, $preflight, null, true, $normalization );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			$second_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_pending );
			$this->assertSame( $main_pending['generation'], $second_pending['generation'] );
			$this->assertTrue( $second_pending['network_cutover'] );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$main_ready = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_ready );
			$this->assertSame( array( 'network_barrier' ), $main_ready['deferred_codes'] );
			$this->assertSame( 0, $deactivation_calls );

			switch_to_blog( $second_site_id );
			$this->require_scheduler()->cancel( $second_pending['generation'], 1 );
			$sut->handle_reconcile( $second_pending['generation'], 1 );
			$second_verifying = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_verifying );
			$this->assertSame( 'verify_builtin_ownership', $second_verifying['current_step'] );
			restore_current_blog();
			$main_verifying = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_verifying );
			$this->assertSame( 'verify_builtin_ownership', $main_verifying['current_step'] );
			$this->assertSame( 1, $deactivation_calls );
			$this->assertSame( $main_site_id, get_current_blog_id() );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox The network barrier completes over the live sites while a $status site keeps its record, and the site finishes on its own once restored.
	 * @group multisite
	 * @testWith ["archived"]
	 *           ["spam"]
	 *           ["deleted"]
	 *
	 * @param string $status Site status whose queue does not run.
	 */
	public function test_network_barrier_ignores_sites_whose_actions_never_run( string $status ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id     = get_current_blog_id();
		$second_site_id   = $this->create_cutover_multisite_site( 'cutover-live-peer.example.org' );
		$archived_site_id = $this->create_cutover_multisite_site( 'cutover-' . $status . '-peer.example.org' );
		update_blog_status( $archived_site_id, $status, '1' );
		$deactivation_calls = 0;
		$preflight          = new class( $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param int $deactivation_calls Deactivation calls.
			 */
			public function __construct( int &$deactivation_calls ) {
				$this->deactivation_calls =& $deactivation_calls;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Network-active until the barrier deactivates it. */
			public function is_woopayments_network_active(): bool {
				return 0 === $this->deactivation_calls;
			}

			/** Record one network deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}

			/** @var array<int,int> */
			public array $remediation_by_site = array();

			/** Count remediation scheduling per site. */
			public function ensure_fee_remediation_scheduled(): bool {
				$site_id                               = get_current_blog_id();
				$this->remediation_by_site[ $site_id ] = ( $this->remediation_by_site[ $site_id ] ?? 0 ) + 1;
				return true;
			}
		};
		$normalization      = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @var array<int,int> */
			public array $runs_by_site = array();

			/** Count normalization per site. */
			public function run(): array {
				$site_id                        = get_current_blog_id();
				$this->runs_by_site[ $site_id ] = ( $this->runs_by_site[ $site_id ] ?? 0 ) + 1;
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut                = $this->create_job( true, $preflight, null, true, $normalization );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $archived_site_id );
			$parked = $this->require_state_store()->get_record();
			$this->assertIsArray( $parked, 'The site keeps its record, so it can still finish if restored.' );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			switch_to_blog( $second_site_id );
			$second_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_pending );
			$this->require_scheduler()->cancel( $second_pending['generation'], 1 );
			$sut->handle_reconcile( $second_pending['generation'], 1 );
			restore_current_blog();

			$this->assertSame( 1, $deactivation_calls, 'The last live site completes the barrier.' );
			$main_verifying = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_verifying );
			$this->assertSame( 'verify_builtin_ownership', $main_verifying['current_step'] );

			// Restored, the site runs its pending attempt, finds the barrier complete and moves to its own verification.
			update_blog_status( $archived_site_id, $status, '0' );
			switch_to_blog( $archived_site_id );
			$this->require_scheduler()->cancel( $parked['generation'], 1 );
			$sut->handle_reconcile( $parked['generation'], 1 );
			$restored = $this->require_state_store()->get_record();
			$this->assertIsArray( $restored );
			$this->assertSame( 'verify_builtin_ownership', $restored['current_step'] );
			$this->require_scheduler()->cancel( $restored['generation'], 2 );
			$this->start_fresh_request();
			$this->create_job( true, $preflight, null, false, $normalization )->handle_reconcile( $restored['generation'], 2 );
			$done = $this->require_state_store()->get_record();
			$this->assertIsArray( $done );
			$this->assertSame( WooPaymentsCutoverState::DONE, $done['state'] );
			restore_current_blog();
			$this->assertSame( 1, $deactivation_calls, 'The plugin is not deactivated a second time.' );
			$this->assertSame( 1, $normalization->runs_by_site[ $archived_site_id ] ?? 0, 'The restored site runs its own normalization.' );
			$this->assertSame( 1, $preflight->remediation_by_site[ $archived_site_id ] ?? 0, 'The restored site schedules its own fee remediation.' );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			foreach ( array( $second_site_id, $archived_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$this->cleanup_state();
				restore_current_blog();
				wpmu_delete_blog( $site_id, true );
			}
		}
	}

	/**
	 * @testdox A network start that schedules the current site while a peer's lease is busy is accepted, and the first attempt adds the peer.
	 * @group multisite
	 */
	public function test_network_start_with_a_busy_peer_is_accepted_and_repaired(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-busy-peer.example.org' );
		$preflight      = new class() extends WooPaymentsCutoverPreflightService {
			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut            = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );

		try {
			switch_to_blog( $second_site_id );
			$busy = $this->require_state_store()->acquire_lease( time() );
			$this->assertIsString( $busy );
			restore_current_blog();

			$this->assertTrue( $sut->enqueue( 'merchant' ), 'The current site has durable work, so the click is accepted.' );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			$this->assertGreaterThan( 0, $main_pending['action_id'] );
			switch_to_blog( $second_site_id );
			$this->assertNull( $this->require_state_store()->get_record(), 'The busy peer was skipped.' );
			$this->require_state_store()->release_lease( $busy );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			switch_to_blog( $second_site_id );
			$repaired = $this->require_state_store()->get_record();
			restore_current_blog();
			$this->assertIsArray( $repaired, 'The first attempt adds the skipped peer.' );
			$this->assertSame( $main_pending['generation'], $repaired['generation'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A paused site with bundled Stripe Billing subscriptions keeps the network from switching: the barrier excludes every site instead of deactivating the plugin.
	 * @group multisite
	 */
	public function test_network_barrier_checks_paused_sites_for_the_stripe_billing_marker(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id     = get_current_blog_id();
		$second_site_id   = $this->create_cutover_multisite_site( 'cutover-live-peer-marker.example.org' );
		$archived_site_id = $this->create_cutover_multisite_site( 'cutover-archived-peer-marker.example.org' );
		update_blog_status( $archived_site_id, 'archived', '1' );
		$failures_by_site   = array( $archived_site_id => array( 'bundled_stripe_billing_subscriptions_present' ) );
		$deactivation_calls = 0;
		$preflight          = new class( $failures_by_site, $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,string[]> */
			private array $failures_by_site;

			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param array<int,string[]> $failures_by_site   Controlled failures.
			 * @param int                 $deactivation_calls Deactivation calls.
			 */
			public function __construct( array $failures_by_site, int &$deactivation_calls ) {
				$this->failures_by_site   = $failures_by_site;
				$this->deactivation_calls =& $deactivation_calls;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures_by_site[ get_current_blog_id() ] ?? array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Network-active until the barrier deactivates it. */
			public function is_woopayments_network_active(): bool {
				return 0 === $this->deactivation_calls;
			}

			/** Record one network deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}
		};
		$sut                = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			switch_to_blog( $second_site_id );
			$second_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_pending );
			$this->require_scheduler()->cancel( $second_pending['generation'], 1 );
			$sut->handle_reconcile( $second_pending['generation'], 1 );
			restore_current_blog();

			$this->assertSame( 0, $deactivation_calls, 'The plugin must stay on every site while a paused site holds bundled Stripe Billing subscriptions.' );
			foreach ( array( $main_site_id, $second_site_id, $archived_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$record = $this->require_state_store()->get_record();
				restore_current_blog();
				$this->assertIsArray( $record );
				$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $record['state'], "Site $site_id must be excluded with the network." );
				$this->assertSame( array( 'bundled_stripe_billing_subscriptions_present' ), $record['deferred_codes'] );
			}
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			foreach ( array( $second_site_id, $archived_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$this->cleanup_state();
				restore_current_blog();
				wpmu_delete_blog( $site_id, true );
			}
		}
	}

	/**
	 * @testdox A paused site whose Stripe Billing check throws keeps the network plugin active and every live site at the barrier, and the error names the site.
	 * @group multisite
	 */
	public function test_network_barrier_waits_when_a_paused_site_cannot_be_checked(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id       = get_current_blog_id();
		$second_site_id     = $this->create_cutover_multisite_site( 'cutover-live-peer-unchecked.example.org' );
		$archived_site_id   = $this->create_cutover_multisite_site( 'cutover-archived-peer-unchecked.example.org' );
		$deactivation_calls = 0;
		update_blog_status( $archived_site_id, 'archived', '1' );
		$preflight = new class( $archived_site_id, $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $unreadable_site_id;

			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param int $unreadable_site_id Site whose check throws.
			 * @param int $deactivation_calls Deactivation calls.
			 */
			public function __construct( int $unreadable_site_id, int &$deactivation_calls ) {
				$this->unreadable_site_id = $unreadable_site_id;
				$this->deactivation_calls =& $deactivation_calls;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				if ( get_current_blog_id() === $this->unreadable_site_id ) {
					throw new \RuntimeException( 'Expected paused-site check failure.' );
				}
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Network-active until the barrier deactivates it. */
			public function is_woopayments_network_active(): bool {
				return 0 === $this->deactivation_calls;
			}

			/** Record one network deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}
		};
		$job       = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var array<int,array<string,mixed>> */
			public array $errors = array();

			/**
			 * Record errors without relying on the global logger.
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
		};
		$sut       = $this->create_job( true, $preflight, $job, true, $this->create_noop_normalization() );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			switch_to_blog( $second_site_id );
			$second_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_pending );
			$this->require_scheduler()->cancel( $second_pending['generation'], 1 );
			$sut->handle_reconcile( $second_pending['generation'], 1 );
			restore_current_blog();

			$this->assertSame( 0, $deactivation_calls, 'The plugin stays on every site while a paused site could not be checked.' );
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$record = $this->require_state_store()->get_record();
				restore_current_blog();
				$this->assertIsArray( $record );
				$this->assertSame( WooPaymentsCutoverState::DEFERRED, $record['state'], "Site $site_id must wait at the barrier." );
				$this->assertSame( array( 'network_barrier' ), $record['deferred_codes'] );
				$this->assertSame( 'deferred', $record['current_step'] );
			}
			$unchecked = array_values(
				array_filter(
					$job->errors,
					static function ( array $error ): bool {
						return 'WooPayments network cutover could not check a paused site before deactivating the plugin.' === $error['message'];
					}
				)
			);
			$this->assertCount( 1, $unchecked );
			$this->assertSame( $archived_site_id, $unchecked[0]['context']['site_id'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			foreach ( array( $second_site_id, $archived_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$this->cleanup_state();
				restore_current_blog();
				wpmu_delete_blog( $site_id, true );
			}
		}
	}

	/**
	 * @testdox A network claim whose fan-out cannot reach a busy peer defers with network_fanout_pending before seeding features.
	 * @group multisite
	 */
	public function test_network_claim_with_an_incomplete_fanout_defers_before_seeding_features(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-busy-peer-fanout.example.org' );
		$sut            = $this->create_job( true, $this->create_network_preflight_with_failures( array() ), null, true, $this->create_noop_normalization() );
		$seeded         = 0;
		$seed_features  = static function () use ( &$seeded ): void {
			++$seeded;
		};
		$busy           = null;
		add_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $seed_features );

		try {
			switch_to_blog( $second_site_id );
			$busy = $this->require_state_store()->acquire_lease( time() );
			$this->assertIsString( $busy );
			restore_current_blog();

			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );

			$deferred = $this->require_state_store()->get_record();
			$this->assertIsArray( $deferred );
			$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
			$this->assertSame( array( 'network_fanout_pending' ), $deferred['deferred_codes'] );
			$this->assertSame( 0, $seeded, 'Features are not seeded before every peer has its record.' );
		} finally {
			remove_action( WooPaymentsCutoverReconciliationJob::ACTION_SEED_FEATURES, $seed_features );
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			if ( is_string( $busy ) ) {
				$this->require_state_store()->release_lease( $busy );
			}
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox The admin notice classification of a network record runs the network-wide preflight once and reuses it within the hour.
	 * @group multisite
	 */
	public function test_network_notice_classification_reuses_the_hourly_result(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-notice-cache.example.org' );
		$preflight_runs = 0;
		$preflight      = new class( $preflight_runs ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $preflight_runs;

			/**
			 * @param int $preflight_runs Counter of full preflight runs.
			 */
			public function __construct( int &$preflight_runs ) {
				$this->preflight_runs =& $preflight_runs;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				++$this->preflight_runs;
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut            = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->assertTrue( $pending['network_cutover'] );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );
			$awaiting                 = $pending;
			$awaiting['revision']     = $pending['revision'] + 1;
			$awaiting['current_step'] = 'awaiting_merchant_start';
			$awaiting['action_id']    = 0;
			$this->assertTrue( $this->require_state_store()->compare_and_set_record( $pending, $awaiting ) );
			delete_option( 'woocommerce_woopayments_cutover_admin_classification' );

			$preflight_runs = 0;
			$sut->classify_for_admin_notice();
			$first_page = $preflight_runs;
			$sut->classify_for_admin_notice();

			$this->assertGreaterThan( 0, $first_page, 'The first admin page classifies the network.' );
			$this->assertSame( $first_page, $preflight_runs, 'The next admin page reuses the classification instead of scanning every site again.' );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A network worker leaves a peer whose attempt is still scheduled alone, and reschedules one whose action was lost.
	 * @group multisite
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $peer_action_lost Whether the peer's scheduled action disappeared before the worker ran.
	 */
	public function test_network_fanout_repair_writes_only_peers_that_need_it( bool $peer_action_lost ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-scheduled-peer.example.org' );
		$preflight      = new class() extends WooPaymentsCutoverPreflightService {
			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut            = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			$second_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_pending );
			$this->assertGreaterThan( 0, $second_pending['action_id'], 'The fan-out scheduled the peer attempt.' );
			if ( $peer_action_lost ) {
				$this->require_scheduler()->cancel( $second_pending['generation'], 1 );
			}
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$lease_events = $this->capture_lease_write_events(
				static function () use ( $sut, $main_pending ): void {
					$sut->handle_reconcile( $main_pending['generation'], 1 );
				}
			);
			$peer_leases  = count(
				array_filter(
					$lease_events,
					static function ( array $event ) use ( $second_site_id ): bool {
						return 'INSERT' === $event['write'] && $second_site_id === $event['blog_id'];
					}
				)
			);

			$main_waiting = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_waiting );
			$this->assertSame( array( 'network_barrier' ), $main_waiting['deferred_codes'], 'The worker passed fan-out repair and waits at the barrier.' );
			switch_to_blog( $second_site_id );
			$second_after = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_after );
			if ( $peer_action_lost ) {
				$this->assertSame( 1, $peer_leases, 'The lost attempt is rescheduled under the peer lease.' );
				$this->assertGreaterThan( 0, $second_after['action_id'] );
				$this->assertSame( $second_after['action_id'], $this->require_scheduler()->get_scheduled_action_id( $second_after['generation'], 1 ) );
			} else {
				$this->assertSame( 0, $peer_leases, 'Nothing on the peer needed repair, so its lease is never written.' );
				$this->assertSame( $second_pending, $second_after );
			}
			restore_current_blog();
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A network cutover schedules the canceled-authorization fee remediation on every site, at that site's ownership verification.
	 * @group multisite
	 */
	public function test_network_cutover_schedules_fee_remediation_on_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id      = get_current_blog_id();
		$second_site_id    = $this->create_cutover_multisite_site( 'cutover-remediation-peer.example.org' );
		$scheduled_by_site = array();
		$preflight         = new class( $scheduled_by_site ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,int> */
			private array $scheduled_by_site;

			/**
			 * @param array<int,int> $scheduled_by_site Remediation scheduling calls per site.
			 */
			public function __construct( array &$scheduled_by_site ) {
				$this->scheduled_by_site =& $scheduled_by_site;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}

			/** Deactivate once, network-wide. */
			public function deactivate_woopayments_plugin(): bool {
				return true;
			}

			/** Count remediation scheduling per site. */
			public function ensure_fee_remediation_scheduled(): bool {
				$site_id                             = get_current_blog_id();
				$this->scheduled_by_site[ $site_id ] = ( $this->scheduled_by_site[ $site_id ] ?? 0 ) + 1;
				return true;
			}
		};
		$sut               = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$pending = $this->require_state_store()->get_record();
				$this->assertIsArray( $pending );
				$this->require_scheduler()->cancel( $pending['generation'], 1 );
				$sut->handle_reconcile( $pending['generation'], 1 );
				restore_current_blog();
			}
			$this->assertSame( array(), $scheduled_by_site, 'Nothing is scheduled while the plugin still owns the network.' );

			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$verification = $this->require_state_store()->get_record();
				$this->assertIsArray( $verification );
				$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
				$this->require_scheduler()->cancel( $verification['generation'], 2 );
				$this->start_fresh_request();
				$this->create_job( true, $preflight, null, false, $this->create_noop_normalization() )->handle_reconcile( $verification['generation'], 2 );
				$done = $this->require_state_store()->get_record();
				$this->assertIsArray( $done );
				$this->assertSame( WooPaymentsCutoverState::DONE, $done['state'] );
				restore_current_blog();
			}
			$this->assertSame(
				array(
					$main_site_id   => 1,
					$second_site_id => 1,
				),
				$scheduled_by_site
			);
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A completed network barrier moves every site to the active tier before ownership verification runs.
	 *
	 * Source: data/task-1.4-dormancy-design.md:84-96 (cutover completion is an authoritative state writer); the
	 * single-site finalize path writes it right after deactivation, and the network barrier is where the plugin goes away.
	 *
	 * @group multisite
	 */
	public function test_network_barrier_activates_native_payments_on_every_site_before_verification(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = $this->create_cutover_multisite_site( 'cutover-barrier-state.example.org' );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
			switch_to_blog( $site_id );
			// The account cache envelope (data, fetched, errored, consecutive_errors): client 11.1.0 includes/class-database-cache.php:377-382; the account_id and is_live fields: includes/class-wc-payments-account.php:164-171, :757-759.
			update_option(
				'wcpay_account_data',
				array(
					'data'               => array(
						'account_id' => 'acct_network_barrier_' . $site_id,
						'is_live'    => true,
					),
					'fetched'            => time(),
					'errored'            => false,
					'consecutive_errors' => 0,
				),
				false
			);
			update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
			update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
			restore_current_blog();
		}
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$preflight     = new class() extends WooPaymentsCutoverPreflightService {
			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}

			/** Report a completed network deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				return true;
			}
		};
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut           = $this->create_job( true, $preflight, null, true, $normalization, true, wc_get_container()->get( WooPaymentsAccountService::class ) );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$this->assertSame( WooPaymentsSetupTier::AVAILABLE, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'No site may leave the plugin tier before the whole network finalizes.' );

			switch_to_blog( $second_site_id );
			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			restore_current_blog();

			foreach ( array( $main_site_id, $second_site_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$verification = $this->require_state_store()->get_record();
				$this->assertIsArray( $verification );
				$this->assertSame( WooPaymentsCutoverState::PENDING, $verification['state'], "Site {$site_id} must still await ownership verification." );
				$this->assertSame( 'verify_builtin_ownership', $verification['current_step'] );
				$this->assertSame( WooPaymentsSetupTier::ACTIVE, get_option( WooPaymentsSetupTier::OPTION_NAME ), "Site {$site_id} must register the native gateway as soon as the network deactivates the plugin." );
				restore_current_blog();
			}
		} finally {
			while ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A site archived after fan-out whose callback still runs and finds an exclusion excludes itself and its peers.
	 * @group multisite
	 */
	public function test_archived_site_callback_excludes_itself_and_its_peers(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id     = get_current_blog_id();
		$archived_site_id = $this->create_cutover_multisite_site( 'cutover-archived-exclusion.example.org' );
		$failures_by_site = array(
			$main_site_id     => array(),
			$archived_site_id => array( 'bundled_stripe_billing_subscriptions_present' ),
		);
		$preflight        = new class( $failures_by_site ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,string[]> */
			private array $failures_by_site;

			/**
			 * @param array<int,string[]> $failures_by_site Controlled failures.
			 */
			public function __construct( array &$failures_by_site ) {
				$this->failures_by_site =& $failures_by_site;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures_by_site[ get_current_blog_id() ] ?? array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut              = $this->create_job( true, $preflight, null, true, $this->create_noop_normalization() );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			update_blog_status( $archived_site_id, 'archived', '1' );
			// A super admin request, or a worker already running, can still run the archived site's callback.
			switch_to_blog( $archived_site_id );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->assertGreaterThan( 0, $this->require_scheduler()->get_scheduled_action_id( $pending['generation'], 1 ), 'The fan-out scheduled the site before it was archived.' );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );
			$sut->handle_reconcile( $pending['generation'], 1 );
			$own = $this->require_state_store()->get_record();
			$this->assertIsArray( $own );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $own['state'], 'The archived site excludes its own record.' );
			restore_current_blog();

			$peer = $this->require_state_store()->get_record();
			$this->assertIsArray( $peer );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $peer['state'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $archived_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $archived_site_id, true );
		}
	}

	/**
	 * @testdox Network exclusion fences a paused worker and marker removal reopens one merchant-started generation everywhere.
	 * @group multisite
	 */
	public function test_network_exclusion_fences_paused_worker_and_reopens_after_marker_removal(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = self::factory()->blog->create(
			array(
				'domain' => 'cutover-exclusion.example.org',
				'path'   => '/',
			)
		);
		switch_to_blog( $second_site_id );
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		restore_current_blog();
		$failures_by_site = array(
			$main_site_id   => array( 'bundled_stripe_billing_subscriptions_present' ),
			$second_site_id => array(),
		);
		$preflight        = new class( $failures_by_site ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,string[]> */
			private array $failures_by_site;

			/**
			 * @param array<int,string[]> $failures_by_site Controlled failures.
			 */
			public function __construct( array &$failures_by_site ) {
				$this->failures_by_site =& $failures_by_site;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures_by_site[ get_current_blog_id() ] ?? array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$normalization    = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** Return successful normalization. */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut              = $this->create_job( true, $preflight, null, true, $normalization );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			$second_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_pending );
			$this->require_scheduler()->cancel( $second_pending['generation'], 1 );
			$sut->handle_reconcile( $second_pending['generation'], 1 );
			$second_ready = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_ready );
			$this->require_scheduler()->cancel( $second_ready['generation'], 2 );
			$paused                     = $second_ready;
			$paused['revision']         = $second_ready['revision'] + 1;
			$paused['state']            = WooPaymentsCutoverState::RUNNING;
			$paused['attempt']          = 2;
			$paused['action_id']        = 0;
			$paused['current_step']     = 'running';
			$paused['lease_token']      = 'paused-worker';
			$paused['lease_expires_at'] = time() + MINUTE_IN_SECONDS;
			$paused['next_attempt_at']  = null;
			$this->assertTrue( $this->require_state_store()->compare_and_set_record( $second_ready, $paused ) );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$main_excluded = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_excluded );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $main_excluded['state'] );
			switch_to_blog( $second_site_id );
			$second_excluded = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_excluded );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $second_excluded['state'] );
			$this->assertFalse( $sut->defer( $paused, array( 'network_barrier' ) ), 'The paused worker must lose its stale compare-and-set after propagation.' );
			$this->assertSame( $second_excluded, $sut->classify_for_admin_notice(), 'A visit to the clear site must remain excluded while another site still has the marker.' );

			$failures_by_site[ $main_site_id ] = array();
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $sut->classify_for_admin_notice()['state'], 'The notice reuses the cached network classification.' );
			$sut->forget_admin_classification();
			$awaiting = $sut->classify_for_admin_notice();
			$this->assertIsArray( $awaiting );
			$this->assertSame( 'awaiting_merchant_start', $awaiting['current_step'] );
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$second_reopened = $this->require_state_store()->get_record();
			$this->assertIsArray( $second_reopened );
			restore_current_blog();
			$main_reopened = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_reopened );
			$this->assertSame( $second_reopened['generation'], $main_reopened['generation'] );
			$this->assertSame( 'queued', $main_reopened['current_step'] );
			$this->assertSame( 'queued', $second_reopened['current_step'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox Network exclusion repairs a site whose fan-out record was lost before the source worker resumes.
	 * @group multisite
	 */
	public function test_network_exclusion_repairs_a_missing_partial_fanout_record(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = self::factory()->blog->create(
			array(
				'domain' => 'cutover-partial-fanout.example.org',
				'path'   => '/',
			)
		);
		switch_to_blog( $second_site_id );
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		restore_current_blog();
		$failures_by_site = array(
			$main_site_id   => array( 'bundled_stripe_billing_subscriptions_present' ),
			$second_site_id => array(),
		);
		$preflight        = new class( $failures_by_site ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,string[]> */
			private array $failures_by_site;

			/**
			 * @param array<int,string[]> $failures_by_site Controlled failures.
			 */
			public function __construct( array &$failures_by_site ) {
				$this->failures_by_site =& $failures_by_site;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures_by_site[ get_current_blog_id() ] ?? array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut              = $this->create_job( true, $preflight );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			delete_option( WooPaymentsCutoverStateStore::OPTION_NAME );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $this->require_state_store()->get_record()['state'] ?? null );
			switch_to_blog( $second_site_id );
			$repaired = $this->require_state_store()->get_record();
			$this->assertIsArray( $repaired );
			$this->assertSame( $main_pending['generation'], $repaired['generation'] );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $repaired['state'] );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A clear network callback repairs missing fan-out work before entering the all-site barrier.
	 * @group multisite
	 */
	public function test_network_reconciliation_repairs_missing_fanout_before_the_barrier(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id       = get_current_blog_id();
		$second_site_id     = self::factory()->blog->create(
			array(
				'domain' => 'cutover-clear-partial-fanout.example.org',
				'path'   => '/',
			)
		);
		$deactivation_calls = 0;
		switch_to_blog( $second_site_id );
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		restore_current_blog();
		$preflight     = new class( $deactivation_calls ) extends WooPaymentsCutoverPreflightService {
			/** @var int */
			private int $deactivation_calls;

			/**
			 * @param int $deactivation_calls Deactivation calls.
			 */
			public function __construct( int &$deactivation_calls ) {
				$this->deactivation_calls =& $deactivation_calls;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}

			/** Record network deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}
		};
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** Return successful normalization. */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut           = $this->create_job( true, $preflight, null, true, $normalization );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$main_pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_pending );
			switch_to_blog( $second_site_id );
			delete_option( WooPaymentsCutoverStateStore::OPTION_NAME );
			restore_current_blog();

			$this->require_scheduler()->cancel( $main_pending['generation'], 1 );
			$sut->handle_reconcile( $main_pending['generation'], 1 );
			$main_barrier = $this->require_state_store()->get_record();
			$this->assertIsArray( $main_barrier );
			$this->assertSame( array( 'network_barrier' ), $main_barrier['deferred_codes'] );
			switch_to_blog( $second_site_id );
			$repaired = $this->require_state_store()->get_record();
			$this->assertIsArray( $repaired );
			$this->assertSame( $main_pending['generation'], $repaired['generation'] );
			$this->assertSame( WooPaymentsCutoverState::PENDING, $repaired['state'] );
			$this->assertGreaterThan( 0, $repaired['action_id'] );
			$this->require_scheduler()->cancel( $repaired['generation'], 1 );
			$sut->handle_reconcile( $repaired['generation'], 1 );
			restore_current_blog();
			$this->assertSame( 1, $deactivation_calls );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A contended notice-time exclusion suppresses Start and schedules durable network convergence.
	 * @group multisite
	 */
	public function test_notice_time_network_exclusion_contention_schedules_convergence(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id   = get_current_blog_id();
		$second_site_id = self::factory()->blog->create(
			array(
				'domain' => 'cutover-notice-contention.example.org',
				'path'   => '/',
			)
		);
		switch_to_blog( $second_site_id );
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		restore_current_blog();
		$failures_by_site = array(
			$main_site_id   => array( 'bundled_stripe_billing_subscriptions_present' ),
			$second_site_id => array(),
		);
		$preflight        = new class( $failures_by_site ) extends WooPaymentsCutoverPreflightService {
			/** @var array<int,string[]> */
			private array $failures_by_site;

			/**
			 * @param array<int,string[]> $failures_by_site Controlled failures.
			 */
			public function __construct( array &$failures_by_site ) {
				$this->failures_by_site =& $failures_by_site;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures_by_site[ get_current_blog_id() ] ?? array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut              = $this->create_job( true, $preflight );

		try {
			$this->assertTrue( $sut->enqueue( 'merchant' ) );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );
			$sut->handle_reconcile( $pending['generation'], 1 );
			$this->assertSame( WooPaymentsCutoverState::EXCLUDED, $this->require_state_store()->get_record()['state'] ?? null );

			$failures_by_site[ $main_site_id ] = array();
			switch_to_blog( $second_site_id );
			$awaiting = $sut->classify_for_admin_notice();
			$this->assertIsArray( $awaiting );
			$this->assertSame( 'awaiting_merchant_start', $awaiting['current_step'] );
			restore_current_blog();

			$failures_by_site[ $main_site_id ] = array( 'bundled_stripe_billing_subscriptions_present' );
			$network_lease                     = $this->require_state_store()->acquire_lease( time() );
			$this->assertIsString( $network_lease );
			switch_to_blog( $second_site_id );
			$classified = $sut->classify_for_admin_notice();
			$this->assertIsArray( $classified );
			$this->assertSame( WooPaymentsCutoverState::DEFERRED, $classified['state'] );
			$this->assertSame( array( 'network_exclusion_propagation_pending' ), $classified['deferred_codes'] );
			$this->assertGreaterThan( 0, $classified['action_id'] );
			$this->assertFalse( $sut->should_offer_start() );
			restore_current_blog();
			$this->require_state_store()->release_lease( $network_lease );
		} finally {
			if ( get_current_blog_id() !== $main_site_id ) {
				restore_current_blog();
			}
			if ( isset( $network_lease ) && is_string( $network_lease ) ) {
				$this->require_state_store()->release_lease( $network_lease );
			}
			switch_to_blog( $second_site_id );
			$this->cleanup_state();
			restore_current_blog();
			wpmu_delete_blog( $second_site_id, true );
		}
	}

	/**
	 * @testdox A network plugin update uses the main site core lock and restores a subsite context.
	 * @group multisite
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
	 * @group multisite
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
	 * @testdox An unknown prefixed action remains queued and defers reconciliation.
	 */
	public function test_unknown_prefixed_operational_action_defers_without_cancellation(): void {
		$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_unknown_legacy_hook', array(), 'cutover-test', false );
		$this->assertIsInt( $action_id );
		$preflight = $this->create_preflight_with_failures(
			array( 'operational_queue_hooks_unhandled' ),
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
		$preflight = $this->create_preflight_with_failures( array( 'bundled_stripe_billing_subscriptions_present' ) );
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
	 * @param mixed  $result           Upgrade result.
	 * @param bool   $throws           Whether the core upgrader throws.
	 * @param string $expected_message Expected logged error message for this result.
	 */
	public function test_plugin_update_failures_defer_without_a_second_install( $result, bool $throws, string $expected_message ): void {
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

			/** @var array<int,array<string,mixed>> */
			private array $errors = array();

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

			/**
			 * Report that the installed plugin files still carry an unsupported version.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return string
			 */
			protected function get_installed_plugin_version( string $plugin_file ): string {
				unset( $plugin_file ); // Avoid parameter not used PHPCS errors.
				return '10.4.0';
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

			/** @return array<int,array<string,mixed>> */
			public function get_errors(): array {
				return $this->errors;
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

		$this->assertCount( 1, $job->get_errors(), 'Every plugin update result must log exactly one outcome message.' );
		$this->assertSame( $expected_message, $job->get_errors()[0]['message'] );
	}

	/**
	 * @testdox A successful plugin update defers quietly until the plugin records its new version, then finalizes without a second update.
	 *
	 * Source: client 11.1.0 `includes/class-wc-payments.php:376` bumps the recorded version on the plugin's next `init`,
	 * so the request that ran the update still reads the old version.
	 */
	public function test_successful_plugin_update_is_not_repeated_after_the_version_blocker_clears(): void {
		$preflight     = new class() extends WooPaymentsCutoverPreflightService {
			/** @var bool */
			private bool $version_unsupported = true;

			/** @var int */
			public int $deactivation_calls = 0;

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->version_unsupported ? array( 'woopayments_plugin_version_unsupported' ) : array();
			}

			/** Clear the controlled version failure, as the plugin's own next request does. */
			public function mark_plugin_version_supported(): void {
				$this->version_unsupported = false;
			}

			/** Invalidate the controlled preflight result. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return the controlled active plugin file. */
			public function get_active_woopayments_plugin_file(): string {
				return 'woocommerce-payments/woocommerce-payments.php';
			}

			/** Record the finalization deactivation. */
			public function deactivate_woopayments_plugin(): bool {
				++$this->deactivation_calls;
				return true;
			}

			/**
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; this scenario is site-local, so
			 * the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$job           = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int */
			private int $metadata_refreshes = 0;

			/** @var string[] */
			private array $upgraded_plugin_files = array();

			/** @var bool[] */
			private array $lock_observations = array();

			/** @var array<int,array<string,mixed>> */
			private array $errors = array();

			/** Refresh controlled update metadata. */
			protected function refresh_plugin_update_metadata(): void {
				++$this->metadata_refreshes;
			}

			/**
			 * Complete one controlled core plugin update.
			 *
			 * Returns an array, the real shape `Plugin_Upgrader::bulk_upgrade()` reports for a completed
			 * install. The recorded version option does not move here: the plugin bumps it on its next request.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return array<string,mixed>
			 */
			protected function upgrade_woopayments_plugin_file( string $plugin_file ): array {
				$this->upgraded_plugin_files[] = $plugin_file;
				$this->lock_observations[]     = false !== get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false );
				return array( 'destination_name' => 'woocommerce-payments' );
			}

			/**
			 * Report the header version of the freshly installed plugin files.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return string
			 */
			protected function get_installed_plugin_version( string $plugin_file ): string {
				unset( $plugin_file ); // Avoid parameter not used PHPCS errors.
				return '11.1.0';
			}

			/**
			 * Record logged errors without relying on the global logger.
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

			/** @return array<int,array<string,mixed>> */
			public function get_errors(): array {
				return $this->errors;
			}
		};
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array(),
				);
			}
		};
		$sut           = $this->create_job( true, $preflight, $job, true, $normalization );
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );

		$sut->handle_reconcile( $pending['generation'], 1 );

		$first = $this->require_state_store()->get_record();
		$this->assertIsArray( $first );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $first['state'] );
		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $first['deferred_codes'] );
		$this->assertSame( array(), $job->get_errors(), 'A completed update that installed a supported version must not log an error.' );
		$this->assertSame( 1, $job->get_metadata_refresh_count() );
		$this->assertSame( array( 'woocommerce-payments/woocommerce-payments.php' ), $job->get_upgraded_plugin_files() );
		$this->assertSame( array( true ), $job->get_lock_observations() );
		$this->assertFalse( get_option( 'woocommerce_woopayments_cutover_plugin_update_lock.lock', false ) );
		$this->assertSame( 0, $preflight->deactivation_calls );

		$preflight->mark_plugin_version_supported();
		$this->require_scheduler()->cancel( $first['generation'], 2 );
		$sut->handle_reconcile( $first['generation'], 2 );

		$second = $this->require_state_store()->get_record();
		$this->assertIsArray( $second );
		$this->assertSame( WooPaymentsCutoverState::PENDING, $second['state'] );
		$this->assertSame( 'verify_builtin_ownership', $second['current_step'] );
		$this->assertSame( 1, $preflight->deactivation_calls );
		$this->assertSame( 1, $job->get_metadata_refresh_count() );
		$this->assertSame( array( 'woocommerce-payments/woocommerce-payments.php' ), $job->get_upgraded_plugin_files() );
		$this->assertSame( array(), $job->get_errors() );
	}

	/**
	 * Provide installed plugin headers around the minimum cutover version.
	 *
	 * @return array<string,array{?string,string,int}>
	 */
	public function installed_plugin_version_provider(): array {
		return array(
			'the minimum supported version' => array( '10.5.0', '10.5.0', 0 ),
			'one patch below the minimum'   => array( '10.4.9', '10.4.9', 1 ),
			'a missing plugin file'         => array( null, '', 1 ),
		);
	}

	/**
	 * @testdox The installed plugin header decides whether a completed update logs a failed version install.
	 *
	 * @dataProvider installed_plugin_version_provider
	 *
	 * @param string|null $header_version   Version header written to the fixture plugin file, or null for no file.
	 * @param string      $expected_version Expected version read from the installed file.
	 * @param int         $expected_errors  Expected number of logged errors after the completed update.
	 */
	public function test_installed_plugin_header_decides_the_completed_update_log( ?string $header_version, string $expected_version, int $expected_errors ): void {
		// A fixture directory no real plugin uses, so a mounted WooPayments install is never read or touched.
		$plugin_file     = 'woopayments-cutover-version-fixture/woocommerce-payments.php';
		$plugin_absolute = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( null !== $header_version ) {
			wp_mkdir_p( dirname( $plugin_absolute ) );
			file_put_contents( $plugin_absolute, "<?php\n/**\n * Plugin Name: WooPayments Fixture\n * Version: {$header_version}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture written to the test install's own plugins directory.
		}

		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var array<int,array<string,mixed>> */
			private array $errors = array();

			/** No-op controlled update metadata refresh, so the real wordpress.org API is never called. */
			protected function refresh_plugin_update_metadata(): void {
			}

			/**
			 * Complete one controlled core plugin update without touching the fixture file.
			 *
			 * @param string $plugin_file Active plugin file.
			 * @return array<string,mixed>
			 */
			protected function upgrade_woopayments_plugin_file( string $plugin_file ): array {
				unset( $plugin_file ); // Avoid parameter not used PHPCS errors.
				return array( 'destination_name' => 'woocommerce-payments' );
			}

			/**
			 * Record logged errors without relying on the global logger.
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

			/**
			 * Read the installed header through the real seam.
			 *
			 * @param string $plugin_file Plugin file relative to the plugins directory.
			 * @return string
			 */
			public function read_installed_plugin_version( string $plugin_file ): string {
				return $this->get_installed_plugin_version( $plugin_file );
			}

			/** @return array<int,array<string,mixed>> */
			public function get_errors(): array {
				return $this->errors;
			}
		};

		try {
			$installed_version = $job->read_installed_plugin_version( $plugin_file );

			$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), $plugin_file );
			$sut       = $this->create_job( true, $preflight, $job );
			$sut->enqueue( 'merchant' );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );

			$sut->handle_reconcile( $pending['generation'], 1 );
		} finally {
			if ( null !== $header_version ) {
				wp_delete_file( $plugin_absolute );
				@rmdir( dirname( $plugin_absolute ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup of a directory this test created.
			}
		}

		$this->assertSame( $expected_version, $installed_version );
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $deferred['deferred_codes'] );
		$this->assertCount( $expected_errors, $job->get_errors() );
		if ( $expected_errors > 0 ) {
			$this->assertSame( 'WooPayments cutover plugin update completed without installing a supported version.', $job->get_errors()[0]['message'] );
		}
	}

	/**
	 * Provide the real-world request contexts a plugin-update attempt can run in.
	 *
	 * @return array<string,array{bool}>
	 */
	public function plugin_update_request_context_provider(): array {
		return array(
			'the async admin-ajax runner native dispatches (not WP-Cron)' => array( false ),
			'a WP-Cron run, where core already protects the plugin'       => array( true ),
		);
	}

	/**
	 * @testdox A deferred version-blocked update must leave the plugin active and owning, because WordPress core's real upgrader silently deactivates an active plugin outside WP-Cron and nothing restores it.
	 *
	 * @dataProvider plugin_update_request_context_provider
	 *
	 * @param bool $doing_cron Whether to force the WP-Cron request-context signal WordPress core's upgrader checks.
	 */
	public function test_deferred_plugin_update_does_not_deactivate_the_still_owning_plugin( bool $doing_cron ): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is required to build the test package.' );
		}

		// The upgrader runs core's update checks; answer them with "no updates", since this case is about deactivation.
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				unset( $args );
				$no_updates = array(
					'/core/version-check/'   => array(
						'offers'       => array(),
						'translations' => array(),
					),
					'/plugins/update-check/' => array(
						'plugins'      => array(),
						'translations' => array(),
						'no_update'    => array(),
					),
					'/themes/update-check/'  => array(
						'themes'       => array(),
						'translations' => array(),
						'no_update'    => array(),
					),
				);
				foreach ( $no_updates as $path => $body ) {
					if ( false === $response && 'api.wordpress.org' === wp_parse_url( $url, PHP_URL_HOST ) && false !== strpos( $url, $path ) ) {
						return array(
							'headers'  => array(),
							'body'     => wp_json_encode( $body ),
							'response' => array(
								'code'    => 200,
								'message' => 'OK',
							),
							'cookies'  => array(),
							'filename' => null,
						);
					}
				}

				return $response;
			},
			10,
			3
		);

		// This PHPUnit environment does not mount the real WooPayments plugin (only the browser/e2e
		// environments do), so create a minimal fixture plugin under the test install's own plugins
		// directory. Only remove what this test itself created, so a real mounted plugin is never touched.
		$plugin_file       = 'woocommerce-payments/woocommerce-payments.php';
		$plugin_absolute   = WP_PLUGIN_DIR . '/' . $plugin_file;
		$fixture_directory = null;
		if ( ! file_exists( $plugin_absolute ) ) {
			$fixture_directory = dirname( $plugin_absolute );
			wp_mkdir_p( $fixture_directory );
			file_put_contents( $plugin_absolute, "<?php\n/**\n * Plugin Name: WooPayments Fixture\n * Version: 10.4.0\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture written to the test install's own plugins directory.
		}

		update_option( 'active_plugins', array( $plugin_file ) );

		// Build a tiny real package so WordPress core's real upgrader can run its real install flow up to
		// (but never through) the copy step: the pre-install observer below always stops it there, so this
		// works the same whether the plugin above is a fixture this test wrote or a real mounted install.
		$package = wp_tempnam( 'woocommerce-payments-fixture.zip' );
		// `unpack_package()` unzips into this working directory (`WP_Upgrader::unpack_package()`'s own name
		// derivation) and only removes it once `install_package()` reaches its `clear_working` step, which
		// the pre-install stop below never reaches; clean it up ourselves.
		$upgrade_working_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/' . basename( basename( $package, '.tmp' ), '.zip' );
		$zip                 = new \ZipArchive();
		$zip->open( $package, \ZipArchive::OVERWRITE );
		$zip->addFromString( $plugin_file, "<?php\n/**\n * Plugin Name: WooPayments Fixture\n * Version: 10.5.0\n */\n" );
		$zip->close();

		set_site_transient(
			'update_plugins',
			(object) array(
				'response' => array(
					$plugin_file => (object) array(
						'package'     => $package,
						'new_version' => '10.5.0',
						'slug'        => 'woocommerce-payments',
					),
				),
			)
		);

		$download = static function () use ( $package ) {
			return $package;
		};
		add_filter( 'upgrader_pre_download', $download );

		// Observe activation right at the point core's real upgrader would copy files in, one priority
		// after core's own `deactivate_plugin_before_upgrade()` (added at priority 10 by `upgrade()`), then
		// stop before any write happens, so nothing is ever copied into the plugin directory.
		$active_at_pre_install = null;
		$observe               = function () use ( $plugin_file, &$active_at_pre_install ) {
			$active_at_pre_install = is_plugin_active( $plugin_file );
			return new \WP_Error( 'test_stop_before_copy', 'Stop before the copy step so nothing is written to the plugin directory.' );
		};
		add_filter( 'upgrader_pre_install', $observe, 20 );

		if ( $doing_cron ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		}

		$ob_level                  = ob_get_level();
		$active_after_attempt      = null;
		$owner_after_attempt       = null;
		$maintenance_after_attempt = null;
		try {
			$job       = new class() extends WooPaymentsCutoverReconciliationJob {
				/** No-op controlled update metadata refresh, so the real wordpress.org API is never called. */
				protected function refresh_plugin_update_metadata(): void {
				}
			};
			$preflight = $this->create_preflight_with_failures( array( 'woopayments_plugin_version_unsupported' ), false, false, array(), $plugin_file );
			$sut       = $this->create_job( true, $preflight, $job );
			$sut->enqueue( 'merchant' );
			$pending = $this->require_state_store()->get_record();
			$this->assertIsArray( $pending );
			$this->require_scheduler()->cancel( $pending['generation'], 1 );

			$sut->handle_reconcile( $pending['generation'], 1 );

			// Capture post-attempt state before the cleanup below resets `active_plugins`.
			$active_after_attempt = is_plugin_active( $plugin_file );
			$arbiter              = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
			$arbiter->invalidate();
			$owner_after_attempt = $arbiter->get_runtime_owner();
			$arbiter->invalidate();
			// `bulk_upgrade()` turns maintenance mode on for the copy in every request context (unlike
			// `upgrade()`, which only did so in WP-Cron) and always turns it back off after the loop; the
			// pre-install stop above never reaches that point, so this must still be off on its own.
			$maintenance_after_attempt = file_exists( ABSPATH . '.maintenance' );
		} finally {
			remove_filter( 'upgrader_pre_download', $download );
			remove_filter( 'upgrader_pre_install', $observe, 20 );
			if ( $doing_cron ) {
				remove_filter( 'wp_doing_cron', '__return_true' );
			}
			wp_delete_file( $package );
			update_option( 'active_plugins', array() );
			if ( null !== $fixture_directory ) {
				wp_delete_file( $plugin_absolute );
				@rmdir( $fixture_directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup of a directory this test created.
			}
			// Safety net in case the upgrader above left maintenance mode on (see the capture above).
			if ( file_exists( ABSPATH . '.maintenance' ) ) {
				wp_delete_file( ABSPATH . '.maintenance' );
			}
			global $wp_filesystem;
			if ( $wp_filesystem instanceof \WP_Filesystem_Base && $wp_filesystem->is_dir( $upgrade_working_dir ) ) {
				$wp_filesystem->delete( $upgrade_working_dir, true );
			}
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}

		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $deferred['deferred_codes'] );

		$this->assertTrue( $active_at_pre_install, 'The plugin must still be active at the pre-install point: a deferred update must keep the plugin owning payments until a later attempt finalizes.' );
		$this->assertTrue( $active_after_attempt, 'A deferred version-blocked update must leave the plugin active; only finalize() may deactivate it.' );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $owner_after_attempt, 'The plugin must still own the runtime while the version blocker defers the cutover.' );
		$this->assertFalse( $maintenance_after_attempt, 'bulk_upgrade() must always turn maintenance mode back off, even though this attempt stops before install.' );
	}

	/**
	 * Provide WordPress core updater result shapes and the message each must log.
	 *
	 * `true` is `bulk_upgrade()`'s "up to date" result (no update was offered), not a completed
	 * install: only an array result (the real `install_package()` shape) counts as completed.
	 *
	 * @return array<string,array{mixed,bool,string}>
	 */
	public function plugin_update_result_provider(): array {
		$did_not_complete = 'WooPayments cutover plugin update did not complete.';

		return array(
			'false result'                   => array( false, false, $did_not_complete ),
			'null result'                    => array( null, false, $did_not_complete ),
			'WordPress error result'         => array( new \WP_Error( 'upgrade_failed' ), false, $did_not_complete ),
			'up to date result'              => array( true, false, $did_not_complete ),
			'completed but still old result' => array( array( 'destination_name' => 'woocommerce-payments' ), false, 'WooPayments cutover plugin update completed without installing a supported version.' ),
			'thrown result'                  => array( null, true, 'WooPayments cutover plugin update failed.' ),
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
		$sut       = $this->create_job_with_preflight( true, $this->create_preflight_with_failures( array( 'builtin_transport_unavailable' ) ) );
		$scheduler = $this->require_scheduler();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$scheduler->cancel( $record['generation'], 1 );
		// A first attempt a day after the start is a held switch and closes, so the cadence is read on a second attempt.
		$aged               = $record;
		$aged['revision']   = $record['revision'] + 1;
		$aged['attempt']    = 1;
		$aged['started_at'] = time() - $age;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $record, $aged ) );
		$record = $aged;
		$before = time();
		$sut->handle_reconcile( $record['generation'], 2 );
		$after    = time();
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );
		$this->assertSame( 2, $deferred['attempt'] );
		$this->assertSame( array( 'builtin_transport_unavailable' ), $deferred['deferred_codes'] );
		$this->assertGreaterThanOrEqual( $before + $expected_delay, $deferred['next_attempt_at'] );
		$this->assertLessThanOrEqual( $after + $expected_delay, $deferred['next_attempt_at'] );
		$this->assertSame( $deferred['action_id'], $scheduler->get_scheduled_action_id( $deferred['generation'], 3 ) );
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
		$sut = $this->create_job_with_preflight( true, $this->create_preflight_with_failures( array( 'builtin_transport_unavailable' ) ) );
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
	 * @testdox Every request that runs Action Scheduler while a switch waits on its scheduled attempt takes no coordination lease.
	 */
	public function test_register_with_a_scheduled_attempt_does_not_write_a_lease(): void {
		$sut = $this->require_sut();
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->assertGreaterThan( 0, $pending['action_id'] );

		$lease_events = $this->capture_lease_write_events(
			function () use ( $sut ): void {
				$sut->register();
			}
		);

		$this->assertSame( array(), $lease_events, 'Nothing needs repair, so the request must not write the lease option.' );
		$this->assertSame( $pending, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox A record still pointing at a replaced action is repaired under the lease to the action that is scheduled now.
	 */
	public function test_register_with_a_replaced_attempt_records_the_scheduled_action(): void {
		$sut = $this->require_sut();
		$this->assertTrue( $sut->enqueue( 'merchant' ) );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$replacement_id = $this->require_scheduler()->schedule( time(), $pending['generation'], 1 );
		$this->assertGreaterThan( 0, $replacement_id );
		$this->assertNotSame( $pending['action_id'], $replacement_id );

		$lease_events = $this->capture_lease_write_events(
			function () use ( $sut ): void {
				$sut->register();
			}
		);

		$this->assertContains( 'INSERT', array_column( $lease_events, 'write' ), 'The stale record must be repaired under the lease.' );
		$repaired = $this->require_state_store()->get_record();
		$this->assertIsArray( $repaired );
		$this->assertSame( $replacement_id, $repaired['action_id'] );
		$this->assertSame( 1, $this->count_cutover_actions(), 'The repair records the scheduled action instead of adding another.' );
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
	 * @testdox A callback that skips ahead of the next scheduled attempt cannot claim the generation.
	 */
	public function test_handle_reconcile_ignores_a_skipped_ahead_attempt(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$pending = $this->require_state_store()->get_record();
		$this->assertIsArray( $pending );
		$this->require_scheduler()->cancel( $pending['generation'], 1 );
		$sut->handle_reconcile( $pending['generation'], 1 );
		$deferred = $this->require_state_store()->get_record();
		$this->assertIsArray( $deferred );
		$this->assertSame( WooPaymentsCutoverState::DEFERRED, $deferred['state'] );

		$sut->handle_reconcile( $deferred['generation'], $deferred['attempt'] + 2 );

		$this->assertSame( $deferred, $this->require_state_store()->get_record(), 'Only the next attempt may claim the generation.' );
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

		$this->assertFalse( $sut->defer( $expired, array( 'builtin_transport_unavailable' ) ) );
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

		$this->assertFalse( $sut->defer( $claimed, array( 'builtin_transport_unavailable' ) ) );
		$this->assertSame( $excluded, $this->require_state_store()->get_record() );
	}

	/**
	 * @testdox A stale claim cannot defer over the live running claim of the worker that took the generation after recovery.
	 */
	public function test_defer_rejects_a_claim_superseded_by_a_newer_running_claim(): void {
		$sut = $this->require_sut();
		$sut->enqueue( 'merchant' );
		$record = $this->require_state_store()->get_record();
		$this->assertIsArray( $record );
		$this->require_scheduler()->cancel( $record['generation'], 1 );
		$stale_claim = $this->create_running_record( $record );

		// Stale-running recovery, then another worker's claim of the next attempt.
		$recovered                     = $stale_claim;
		$recovered['revision']         = $stale_claim['revision'] + 1;
		$recovered['state']            = WooPaymentsCutoverState::DEFERRED;
		$recovered['current_step']     = 'recovered_stale_running';
		$recovered['next_attempt_at']  = time();
		$recovered['lease_token']      = null;
		$recovered['lease_expires_at'] = null;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $stale_claim, $recovered ) );
		$live_claim                     = $recovered;
		$live_claim['revision']         = $recovered['revision'] + 1;
		$live_claim['state']            = WooPaymentsCutoverState::RUNNING;
		$live_claim['attempt']          = $recovered['attempt'] + 1;
		$live_claim['current_step']     = 'running';
		$live_claim['next_attempt_at']  = null;
		$live_claim['lease_token']      = 'second-worker-lease-token';
		$live_claim['lease_expires_at'] = time() + WooPaymentsCutoverReconciliationJob::RUNNING_TIMEOUT;
		$this->assertTrue( $this->require_state_store()->compare_and_set_record( $recovered, $live_claim ) );

		$this->assertFalse( $sut->defer( $stale_claim, array( 'builtin_transport_unavailable' ) ) );
		$this->assertSame( $live_claim, $this->require_state_store()->get_record() );
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
			$sut->defer( $claimed, array( 'builtin_transport_unavailable' ) );
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
	 * Arrange a store that upgraded WooCommerce with WooPayments active and a connected, enabled account.
	 */
	private function arrange_plugin_era_store(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		// The account cache envelope (data, fetched, errored, consecutive_errors): client 11.1.0 includes/class-database-cache.php:377-382; the account_id and is_live fields: includes/class-wc-payments-account.php:164-171, :757-759.
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id' => 'acct_cutover_state',
					'is_live'    => true,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->assertTrue( wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->is_extension_owner() );
	}

	/**
	 * Create all-clear preflight facts whose deactivation removes the plugin from the active list.
	 *
	 * Like production, it leaves the in-request runtime owner memo untouched.
	 *
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_plugin_deactivating_preflight(): WooPaymentsCutoverPreflightService {
		return new class() extends WooPaymentsCutoverPreflightService {
			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array();
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Deactivate the plugin in the active-plugins option. */
			public function deactivate_woopayments_plugin(): bool {
				update_option( 'active_plugins', array() );
				return true;
			}

			/** Report a site-local activation. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
	}

	/**
	 * Create preflight facts that read the store's payment settings for real and clear only the environment facts this suite cannot provide.
	 *
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_settings_reading_preflight(): WooPaymentsCutoverPreflightService {
		$preflight = new class() extends WooPaymentsCutoverPreflightService {
			/** Environment failures a unit test store always reports: no plugin files, no platform connection, no provider transport. */
			private const ENVIRONMENT_FAILURES = array(
				'woopayments_plugin_version_unsupported',
				'builtin_transport_unavailable',
				'wpcom_blog_id_unavailable',
				'wpcom_connection_unavailable',
				'wpcom_connection_owner_unavailable',
			);

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return array_values( array_diff( parent::get_reconciliation_failures(), self::ENVIRONMENT_FAILURES ) );
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Deactivate the plugin in the active-plugins option. */
			public function deactivate_woopayments_plugin(): bool {
				update_option( 'active_plugins', array() );
				return true;
			}

			/** Report a site-local activation. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$preflight->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( LegacyProxy::class ) );

		return $preflight;
	}

	/**
	 * Create a job that finalizes cleanly and writes state through the container's account service.
	 *
	 * @param bool                               $plugin_active Whether the job's arbiter reports plugin ownership.
	 * @param WooPaymentsCutoverPreflightService $preflight     Controlled preflight facts.
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function create_state_writing_job( bool $plugin_active, WooPaymentsCutoverPreflightService $preflight ): WooPaymentsCutoverReconciliationJob {
		$normalization = new class() extends WooPaymentsCutoverNormalizationRunner {
			/** @return array{ran:bool,changes:string[]} */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array( 'no_changes' ),
				);
			}
		};

		return $this->create_job( true, $preflight, null, $plugin_active, $normalization, true, wc_get_container()->get( WooPaymentsAccountService::class ) );
	}

	/**
	 * Complete a scheduled ownership verification from a new request that no longer loads the plugin.
	 *
	 * @param array<string,mixed>                $verification Scheduled verification record.
	 * @param WooPaymentsCutoverPreflightService $preflight    Controlled preflight facts.
	 */
	private function run_ownership_verification_in_a_fresh_request( array $verification, WooPaymentsCutoverPreflightService $preflight ): void {
		$request_token = new \ReflectionProperty( WooPaymentsCutoverReconciliationJob::class, 'request_token' );
		$request_token->setAccessible( true );
		$request_token->setValue( null );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->require_scheduler()->cancel( $verification['generation'], $verification['attempt'] + 1 );

		$this->create_state_writing_job( false, $preflight )->handle_reconcile( $verification['generation'], $verification['attempt'] + 1 );

		$done = $this->require_state_store()->get_record();
		$this->assertIsArray( $done );
		$this->assertSame( WooPaymentsCutoverState::DONE, $done['state'] );
	}

	/**
	 * Assert that a new shopper request resolves the active tier and bootstraps the native gateway registry.
	 */
	private function assert_next_front_request_registers_native_gateway(): void {
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$state = wc_get_container()->get( WooPaymentsSetupTier::class );
		$state->invalidate();
		$effective_state = $state->get_effective_tier();

		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $effective_state );
		$this->assertContains( WooPaymentsProvider::class, WooPaymentsProvider::get_classes_by_setup_tier()[ $effective_state ]['front'] ?? array() );
	}

	/**
	 * Create a job with a deterministic native-runtime answer.
	 *
	 * @param bool                                       $native_enabled     Whether native payments are enabled.
	 * @param WooPaymentsCutoverPreflightService|null    $preflight_service Controlled preflight facts, when needed.
	 * @param WooPaymentsCutoverReconciliationJob|null   $job               Job instance, when a test needs a narrow override.
	 * @param bool                                       $plugin_active     Whether the plugin owns the runtime.
	 * @param WooPaymentsCutoverNormalizationRunner|null $normalization_runner Controlled normalization runner.
	 * @param bool                                       $native_eligible  Whether the platform account is native-eligible.
	 * @param WooPaymentsAccountService|null             $account_service  Account service, when a test needs the real state writer.
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function create_job( bool $native_enabled, ?WooPaymentsCutoverPreflightService $preflight_service = null, ?WooPaymentsCutoverReconciliationJob $job = null, bool $plugin_active = true, ?WooPaymentsCutoverNormalizationRunner $normalization_runner = null, bool $native_eligible = true, ?WooPaymentsAccountService $account_service = null ): WooPaymentsCutoverReconciliationJob {
		$arbiter = new class( $native_enabled, $plugin_active ) extends WooPaymentsRuntimeArbiter {
			/** @var bool */
			private bool $native_enabled;

			/** @var bool */
			private bool $plugin_active;

			/**
			 * Initialize the static runtime answer.
			 *
			 * @param bool $native_enabled Whether native payments are enabled.
			 * @param bool $plugin_active Whether the plugin owns the runtime.
			 */
			public function __construct( bool $native_enabled, bool $plugin_active ) {
				$this->native_enabled = $native_enabled;
				$this->plugin_active  = $plugin_active;
			}

			/** Return the configured native feature state. */
			public function is_builtin_enabled(): bool {
				return $this->native_enabled;
			}

			/** Return the configured plugin ownership state. */
			public function is_extension_owner(): bool {
				return $this->plugin_active;
			}
		};

		$job               = $job ?? new WooPaymentsCutoverReconciliationJob();
		$preflight_service = $preflight_service ?? $this->preflight_service;
		$this->assertInstanceOf( WooPaymentsCutoverPreflightService::class, $preflight_service );
		$normalization_runner = $normalization_runner ?? new class() extends WooPaymentsCutoverNormalizationRunner {
			/** Return a controlled persistence failure for tests that do not exercise finalization. */
			public function run(): array {
				return array(
					'ran'     => true,
					'changes' => array( 'settings_persistence_failed' ),
				);
			}
		};
		$job->init( $arbiter, $this->require_state_store(), $this->require_scheduler(), $preflight_service, $normalization_runner, $account_service ?? $this->create_native_eligibility_service( $native_eligible ) );
		$this->jobs[] = $job;

		return $job;
	}

	/**
	 * Create an account-service double with deterministic platform eligibility.
	 *
	 * @param bool $native_eligible Controlled eligibility answer.
	 * @return WooPaymentsAccountService
	 */
	private function create_native_eligibility_service( bool $native_eligible ): WooPaymentsAccountService {
		return new class( $native_eligible ) extends WooPaymentsAccountService {
			/** @var bool */
			private bool $native_eligible;

			/**
			 * @param bool $native_eligible Controlled eligibility answer.
			 */
			public function __construct( bool $native_eligible ) {
				$this->native_eligible = $native_eligible;
			}

			/** Return the controlled eligibility answer. */
			public function is_native_eligible(): bool {
				return $this->native_eligible;
			}
		};
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
	 * Create a multisite blog with Action Scheduler tables ready for cutover actions.
	 *
	 * @param string $domain Test blog domain.
	 * @return int
	 */
	private function create_cutover_multisite_site( string $domain ): int {
		$site_id = self::factory()->blog->create(
			array(
				'domain' => $domain,
				'path'   => '/',
			)
		);
		switch_to_blog( $site_id );
		try {
			( new \ActionScheduler_StoreSchema() )->register_tables( true );
			( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		} finally {
			restore_current_blog();
		}

		return $site_id;
	}

	/**
	 * Create deterministic network-active preflight facts.
	 *
	 * @param string[] $failures Reconciliation failures.
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_network_preflight_with_failures( array $failures ): WooPaymentsCutoverPreflightService {
		return new class( $failures ) extends WooPaymentsCutoverPreflightService {
			/** @var string[] */
			private array $failures;

			/**
			 * @param string[] $failures Controlled failures.
			 */
			public function __construct( array $failures ) {
				$this->failures = $failures;
			}

			/** @return string[] */
			public function get_reconciliation_failures(): array {
				return $this->failures;
			}

			/** Invalidate controlled facts. */
			public function invalidate_current_blog_memoization(): void {
			}

			/** @return array<int,array{action_id:int,hook:string,group:string}> */
			public function get_queued_operational_actions(): array {
				return array();
			}

			/** Return network activation. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
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
					return array_values( array_diff( $this->failures, array( 'operational_queue_hooks_unhandled' ) ) );
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
			 * Report the controlled site-local (non-network) activation state.
			 *
			 * The real implementation reads through `$legacy_proxy`, which this
			 * isolated double never initializes; every reconciliation case built
			 * from this helper is a site-local scenario, so the answer is fixed.
			 */
			public function is_woopayments_network_active(): bool {
				return false;
			}

			/**
			 * Keep the matrix owner-token condition on the ordinary retry path.
			 */
			public function is_cutover_connection_owner_user_missing(): bool {
				return $this->owner_missing;
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
	 * @return RecordingWooPaymentsCutoverActionScheduler
	 */
	private function require_scheduler(): RecordingWooPaymentsCutoverActionScheduler {
		$this->assertTrue( class_exists( WooPaymentsCutoverActionScheduler::class ), 'The cutover scheduler has not been implemented yet.' );
		$this->assertInstanceOf( RecordingWooPaymentsCutoverActionScheduler::class, $this->scheduler, 'The cutover scheduler test fixture has not been initialized.' );

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
	 * The lease is written with direct SQL rather than the options API, so the writes are read from the queries themselves.
	 *
	 * @param callable():void $operation Operation whose lease writes should be observed.
	 * @return array<int,array{write:string,blog_id:int}> Lease writes (INSERT, UPDATE or DELETE) with the blog they hit.
	 */
	private function capture_lease_write_events( callable $operation ): array {
		$events   = array();
		$observer = static function ( $query ) use ( &$events ) {
			if ( is_string( $query ) && false !== strpos( $query, WooPaymentsCutoverStateStore::LEASE_OPTION_NAME ) && 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $query, $verb ) ) {
				$events[] = array(
					'write'   => strtoupper( $verb[1] ),
					'blog_id' => get_current_blog_id(),
				);
			}
			return $query;
		};
		add_filter( 'query', $observer );

		try {
			$operation();
		} finally {
			remove_filter( 'query', $observer );
		}

		return $events;
	}

	/**
	 * Create a completed order with the fields written by WooPayments 11.1.0.
	 *
	 * @param int    $customer_id    Customer ID.
	 * @param string $currency       Order currency.
	 * @param string $total          Order total.
	 * @param string $transaction_id WooPayments transaction ID.
	 * @return \WC_Order
	 */
	private function create_plugin_shaped_order( int $customer_id, string $currency, string $total, string $transaction_id ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		if ( ! $order instanceof \WC_Order ) {
			throw new \RuntimeException( 'Could not create a plugin-shaped historical order.' );
		}
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->set_status( 'completed' );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_payment_method_title( 'Visa credit card' );
		$order->set_transaction_id( $transaction_id );
		$order->save();

		return $order;
	}

	/**
	 * Read the historical money fields and exact WooPayments multi-currency metadata.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>
	 */
	private function snapshot_historical_money_order( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \RuntimeException( 'Could not cold-read the historical order.' );
		}
		$multi_currency_keys = array(
			'_wcpay_multi_currency_order_exchange_rate',
			'_wcpay_multi_currency_order_default_currency',
			'_wcpay_multi_currency_stripe_exchange_rate',
		);
		$multi_currency_meta = array();
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) $data['key'];
			if ( in_array( $key, $multi_currency_keys, true ) ) {
				$multi_currency_meta[ $key ][] = (string) $data['value'];
			}
		}
		ksort( $multi_currency_meta );

		return array(
			'customer_id'          => $order->get_customer_id(),
			'currency'             => $order->get_currency(),
			'total'                => $order->get_total(),
			'status'               => $order->get_status(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'transaction_id'       => $order->get_transaction_id(),
			'multi_currency_meta'  => $multi_currency_meta,
		);
	}

	/**
	 * Read one plugin-origin saved card and its WooPayments test customer relationship.
	 *
	 * @param int $token_id Payment token ID.
	 * @return array<string,mixed>
	 */
	private function snapshot_historical_saved_card( int $token_id ): array {
		$token = \WC_Payment_Tokens::get( $token_id );
		if ( ! $token instanceof \WC_Payment_Token_CC ) {
			throw new \RuntimeException( 'Could not cold-read the historical saved card.' );
		}

		return array(
			'token_id'                   => $token->get_id(),
			'provider_payment_method_id' => $token->get_token(),
			'gateway_id'                 => $token->get_gateway_id(),
			'type'                       => $token->get_type(),
			'is_default'                 => $token->is_default(),
			'brand'                      => $token->get_card_type(),
			'last4'                      => $token->get_last4(),
			'expiry_month'               => $token->get_expiry_month(),
			'expiry_year'                => $token->get_expiry_year(),
			'user_id'                    => $token->get_user_id(),
			'customer_id'                => get_user_option( '_wcpay_customer_id_test', $token->get_user_id() ),
		);
	}

	/**
	 * Cold-read selected persisted fields from one plugin-origin subscription-shaped order, its parent order, and saved card.
	 *
	 * @param int $parent_order_id Parent order ID.
	 * @param int $subscription_id Subscription order ID.
	 * @param int $token_id        Payment token ID.
	 * @return array<string,mixed>
	 */
	private function snapshot_historical_subscription_graph( int $parent_order_id, int $subscription_id, int $token_id ): array {
		$parent_order = wc_get_order( $parent_order_id );
		$subscription = wc_get_order( $subscription_id );
		if ( ! $parent_order instanceof \WC_Order || ! $subscription instanceof \WC_Order ) {
			throw new \RuntimeException( 'Could not cold-read the historical subscription graph.' );
		}

		return array(
			'parent'       => array(
				'order_id'          => $parent_order->get_id(),
				'customer_id'       => $parent_order->get_customer_id(),
				'currency'          => $parent_order->get_currency(),
				'total'             => $parent_order->get_total(),
				'status'            => $parent_order->get_status(),
				'payment_method'    => $parent_order->get_payment_method(),
				'transaction_id'    => $parent_order->get_transaction_id(),
				'payment_token_ids' => array_map( 'absint', $parent_order->get_payment_tokens() ),
				'meta'              => array(
					'_payment_method_id'   => (string) $parent_order->get_meta( '_payment_method_id', true ),
					'_stripe_customer_id'  => (string) $parent_order->get_meta( '_stripe_customer_id', true ),
					'_stripe_mandate_id'   => (string) $parent_order->get_meta( '_stripe_mandate_id', true ),
					'_wcpay_intent_status' => (string) $parent_order->get_meta( '_wcpay_intent_status', true ),
					'_wcpay_charge_id'     => (string) $parent_order->get_meta( '_wcpay_charge_id', true ),
				),
			),
			'subscription' => array(
				'order_id'          => $subscription->get_id(),
				'parent_order_id'   => $subscription->get_parent_id(),
				'customer_id'       => $subscription->get_customer_id(),
				'currency'          => $subscription->get_currency(),
				'recurring_total'   => $subscription->get_total(),
				'status'            => $subscription->get_status(),
				'payment_method'    => $subscription->get_payment_method(),
				'payment_token_ids' => array_map( 'absint', $subscription->get_payment_tokens() ),
				'meta'              => array(
					'_schedule_start'          => (string) $subscription->get_meta( '_schedule_start', true ),
					'_schedule_next_payment'   => (string) $subscription->get_meta( '_schedule_next_payment', true ),
					'_billing_period'          => (string) $subscription->get_meta( '_billing_period', true ),
					'_billing_interval'        => (string) $subscription->get_meta( '_billing_interval', true ),
					'_requires_manual_renewal' => (string) $subscription->get_meta( '_requires_manual_renewal', true ),
					'_payment_method_id'       => (string) $subscription->get_meta( '_payment_method_id', true ),
					'_stripe_customer_id'      => (string) $subscription->get_meta( '_stripe_customer_id', true ),
					'_stripe_mandate_id'       => (string) $subscription->get_meta( '_stripe_mandate_id', true ),
				),
			),
			'token'        => $this->snapshot_historical_saved_card( $token_id ),
		);
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
		delete_option( 'woocommerce_woopayments_cutover_admin_classification' );

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
