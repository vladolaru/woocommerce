<?php
/**
 * WooPaymentsCutoverReconciliationJob class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates durable site-local WooPayments cutover attempts.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverReconciliationJob implements RegisterHooksInterface {

	/** Autoloaded admin-notice Stripe Billing classification, keyed by record revision. */
	public const ADMIN_CLASSIFICATION_OPTION = 'woocommerce_woopayments_cutover_admin_classification';

	/** Completion notice: the switch finished. */
	public const NOTICE_SUCCESS = 'success';

	/** Notice telling a bundled-flavor store why it cannot switch yet. */
	public const NOTICE_BUNDLED_EXCLUSION = 'bundled_exclusion';

	/** Step of a generation queued for its next attempt. */
	private const STEP_QUEUED = 'queued';

	/** Step of an attempt a worker has claimed. */
	private const STEP_RUNNING = 'running';

	/** Step of a generation waiting for a retry. */
	private const STEP_DEFERRED = 'deferred';

	/** Step of a generation excluded from the cutover. */
	private const STEP_EXCLUDED = 'excluded';

	/** Step of a completed cutover. */
	private const STEP_DONE = 'done';

	/** Step of a generation that waits for the merchant's start. */
	private const STEP_AWAITING_MERCHANT_START = 'awaiting_merchant_start';

	/** Step of a finalized generation whose next attempt checks that the built-in WooPayments owns payments. */
	private const STEP_VERIFY_BUILTIN_OWNERSHIP = 'verify_builtin_ownership';

	/** Step of a claim recovered after its worker stopped. */
	private const STEP_RECOVERED_STALE_RUNNING = 'recovered_stale_running';

	/** Step of a network generation waiting to carry an exclusion to every site. */
	private const STEP_NETWORK_EXCLUSION_PROPAGATION = 'network_exclusion_propagation';

	/** Source of the merchant's start. */
	private const SOURCE_MERCHANT = 'merchant';

	/** Source of a generation opened by the WooPayments extension being activated again. */
	private const SOURCE_ROLLBACK = 'rollback';

	/** Source of a site excluded on its own. */
	private const SOURCE_LOCAL_EXCLUSION = 'local_exclusion';

	/** Source of a site record written while carrying a network exclusion. */
	private const SOURCE_NETWORK_EXCLUSION_REPAIR = 'network_exclusion_repair';

	/** Source of a site record written while repairing the network fan-out. */
	private const SOURCE_NETWORK_FANOUT_REPAIR = 'network_fanout_repair';

	/** Source of a site record moved to a newer network generation. */
	private const SOURCE_NETWORK_GENERATION_SUPERSEDED = 'network_generation_superseded';

	/** Record key of the claiming worker's token; the stored name keeps "lease". */
	private const CLAIM_TOKEN_KEY = 'lease_token';

	/** Record key of the time a claim may be recovered; the stored name keeps "lease". */
	private const CLAIM_EXPIRES_AT_KEY = 'lease_expires_at';

	/** Record key of the codes that block or closed the generation; the stored name keeps "deferred". */
	private const BLOCKING_CODES_KEY = 'deferred_codes';

	/** Condition code of a data migration that failed; the stored code keeps its first name. */
	private const CODE_DATA_MIGRATION_FAILED = 'normalization_failed';

	/** Time a claimed attempt may run before repair_schedule() recovers it as stale; fixed at claim, there is no heartbeat. */
	public const RUNNING_TIMEOUT = 15 * MINUTE_IN_SECONDS;

	/** Fast retry delay during the first day. */
	private const FAST_RETRY_DELAY = 15 * MINUTE_IN_SECONDS;

	/** Age at which retries become daily. */
	private const FAST_RETRY_WINDOW = DAY_IN_SECONDS;

	/** Slow retry delay after the first day. */
	private const SLOW_RETRY_DELAY = DAY_IN_SECONDS;

	/** Source used for cutover diagnostics. */
	private const LOG_SOURCE = 'woocommerce-woopayments-cutover';

	/** WordPress core upgrader lock name for the filesystem-global plugin update. */
	private const PLUGIN_UPDATE_LOCK_NAME = 'woocommerce_woopayments_cutover_plugin_update_lock';

	/** Maximum duration for the plugin-update lock. */
	private const PLUGIN_UPDATE_LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Queued WooPayments actions the cutover carries over: their callbacks continue after the cutover.
	 *
	 * The Stripe Billing migration actions are among them: the built-in migrator takes them with the same arguments, so a
	 * migration off Stripe Billing started by the WooPayments extension finishes after the cutover. The preflight's
	 * BUILTIN_HANDLED_QUEUE_HOOKS lists every hook that does not block the cutover.
	 */
	private const ADOPTED_QUEUE_HOOKS = array(
		WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION,
		WooPaymentsAccountMilestonesController::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION,
		WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK,
		WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK,
		'wcpay_schedule_subscription_migrations',
		'wcpay_migrate_subscription',
		'wcpay_migrate_subscription_retry',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Persisted state store.
	 *
	 * @var WooPaymentsCutoverStateStore
	 */
	private WooPaymentsCutoverStateStore $state_store;

	/**
	 * Action Scheduler adapter.
	 *
	 * @var WooPaymentsCutoverActionScheduler
	 */
	private WooPaymentsCutoverActionScheduler $scheduler;

	/**
	 * Headless cutover preflight facts.
	 *
	 * @var WooPaymentsCutoverPreflightService
	 */
	private WooPaymentsCutoverPreflightService $preflight_service;

	/**
	 * Cutover normalization runner.
	 *
	 * @var WooPaymentsCutoverNormalizationRunner
	 */
	private WooPaymentsCutoverNormalizationRunner $normalization_runner;

	/**
	 * WooPayments account state.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Request-local token shared by every job instance in the current PHP request.
	 *
	 * @var string|null
	 */
	private static ?string $request_token = null;

	/**
	 * Whether this job is changing plugin activation state in the current request.
	 *
	 * @var bool
	 */
	private bool $internal_plugin_lifecycle_change = false;

	/**
	 * Initialize the job.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter             $arbiter          Runtime owner arbiter.
	 * @param WooPaymentsCutoverStateStore          $state_store      Persisted state store.
	 * @param WooPaymentsCutoverActionScheduler     $scheduler        Action Scheduler adapter.
	 * @param WooPaymentsCutoverPreflightService    $preflight_service   Headless cutover facts.
	 * @param WooPaymentsCutoverNormalizationRunner $normalization_runner Cutover normalization runner.
	 * @param WooPaymentsAccountService             $account_service     WooPayments account state.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsCutoverStateStore $state_store, WooPaymentsCutoverActionScheduler $scheduler, WooPaymentsCutoverPreflightService $preflight_service, WooPaymentsCutoverNormalizationRunner $normalization_runner, WooPaymentsAccountService $account_service ): void {
		$this->arbiter              = $arbiter;
		$this->state_store          = $state_store;
		$this->scheduler            = $scheduler;
		$this->preflight_service    = $preflight_service;
		$this->normalization_runner = $normalization_runner;
		$this->account_service      = $account_service;
		if ( null === self::$request_token ) {
			self::$request_token = wp_generate_uuid4();
		}
	}

	/**
	 * Register the callback and repair durable state whose action disappeared.
	 *
	 * @since 11.2.0
	 */
	public function register(): void {
		add_action( WooPaymentsCutoverActionScheduler::ACTION_HOOK, array( $this, 'handle_reconcile' ), 10, 2 );
		add_action( 'action_scheduler_init', array( $this, 'repair_schedule' ) );

		if ( did_action( 'action_scheduler_init' ) ) {
			$this->repair_schedule();
		}
	}

	/**
	 * Start one reconciliation generation.
	 *
	 * @since 11.2.0
	 *
	 * @param string $source Trigger source recorded for diagnostics.
	 * @return bool True when durable work already exists or was scheduled.
	 */
	public function enqueue( string $source ): bool {
		$accepted = $this->start_generation( $source );
		if ( $accepted && self::SOURCE_MERCHANT === $source ) {
			$this->scheduler->dispatch_async();
		}

		return $accepted;
	}

	/**
	 * Tell lifecycle observers whether this job initiated the current activation change.
	 *
	 * @return bool
	 */
	public function is_internal_plugin_lifecycle_change(): bool {
		return $this->internal_plugin_lifecycle_change;
	}

	/**
	 * Start one reconciliation generation: across the network where the extension is network-active, else on this site.
	 *
	 * @param string $source Trigger source.
	 * @return bool True when durable work already exists or was scheduled.
	 */
	private function start_generation( string $source ): bool {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return false;
		}
		if ( is_multisite() && $this->preflight_service->is_woopayments_network_active() ) {
			return $this->enqueue_network_generation( $source );
		}

		return $this->enqueue_local_generation( $source );
	}

	/**
	 * Start one site-local generation without applying network fan-out.
	 *
	 * @param string $source Trigger source.
	 * @return bool True when durable work exists or was scheduled.
	 */
	private function enqueue_local_generation( string $source ): bool {
		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $source, $now ): bool {
				$record = $this->state_store->get_record();
				if ( is_array( $record ) ) {
					if ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) ) {
						if ( self::STEP_AWAITING_MERCHANT_START === $record['current_step'] ) {
							return $this->start_awaiting_generation( $record, $source, $now );
						}
						return $this->ensure_record_scheduled( $record, $now );
					}
					if ( $this->can_reopen_terminal_record( $record ) ) {
						return $this->replace_terminal_with_pending( $record, $source, $now );
					}

					return true;
				}

				$record = $this->create_pending_record( $source, $now );
				if ( ! $this->state_store->create_record( $record ) ) {
					return false;
				}

				return $this->ensure_record_scheduled( $record, $now );
			},
			false
		);
	}

	/**
	 * Record a successful external plugin activation as a rollback awaiting merchant confirmation.
	 *
	 * A completed switch is replaced by a new generation awaiting the merchant's start. An open site-level one that is not
	 * awaiting it, a claim running in another request included, is rolled back (roll_back_open_generation()); a running
	 * worker's finalize fence then refuses to deactivate the extension.
	 *
	 * @param bool $network_wide Whether the external activation was network-wide.
	 * @return bool True when the rollback was already recorded or a new generation was opened.
	 */
	public function record_plugin_activation( bool $network_wide = false ): bool {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return false;
		}
		if ( $network_wide && is_multisite() ) {
			return $this->record_network_plugin_activation();
		}

		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $now ): bool {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) ) {
					return false;
				}
				if ( $this->is_awaiting_start( $record ) ) {
					return true;
				}
				// A site-level activation means the extension is not network-active, so a network record here follows the
				// network handoff: this site rolls back on its own, as a local generation the network one leaves alone.
				if ( WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] ) {
					return $this->replace_terminal_with_awaiting_start( $record, self::SOURCE_ROLLBACK, $now, false );
				}
				// The hook fires only for an inactive extension, so an open merchant start sees this only after finalize
				// deactivated the extension.
				if ( ! in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED, WooPaymentsCutoverStateStore::STATE_RUNNING ), true ) ) {
					return false;
				}

				return $this->roll_back_open_generation( $record, $now, false );
			},
			false
		);
	}

	/**
	 * Open one coherent unscheduled rollback generation across the current network.
	 *
	 * @return bool True when every site is at the rollback generation or newer active work.
	 */
	private function record_network_plugin_activation(): bool {
		$site_ids = $this->get_current_network_site_ids();
		if ( array() === $site_ids ) {
			return false;
		}
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return false;
		}

		$maximum_generation    = 0;
		$awaiting_generation   = 0;
		$has_completed_cutover = false;
		try {
			$this->run_on_sites(
				$site_ids,
				function () use ( &$maximum_generation, &$awaiting_generation, &$has_completed_cutover ): void {
					$record = $this->state_store->get_record();
					if ( ! is_array( $record ) || ! $this->is_network( $record ) ) {
						return;
					}
					$maximum_generation = max( $maximum_generation, $record['generation'] );
					// The activation hook fires only for an inactive extension, so an open network generation here is past a
					// deactivation (the job's or the merchant's): a rollback, as a completed one is.
					if ( WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] || ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED, WooPaymentsCutoverStateStore::STATE_RUNNING ), true ) && ! $this->is_awaiting_start( $record ) ) ) {
						$has_completed_cutover = true;
					}
					if ( $this->is_awaiting_start( $record ) ) {
						$awaiting_generation = max( $awaiting_generation, $record['generation'] );
					}
				}
			);
			if ( ! $has_completed_cutover && 0 === $awaiting_generation ) {
				return false;
			}

			$generation = $awaiting_generation >= $maximum_generation ? $awaiting_generation : $maximum_generation + 1;
			$complete   = true;
			$this->run_on_sites_with_write_locks(
				$site_ids,
				function ( int $site_id, bool $lock_held ) use ( &$complete, $generation ): void {
					if ( ! $lock_held ) {
						$complete = false;
						return;
					}
						$record = $this->state_store->get_record();
					if ( is_array( $record ) && $record['generation'] > $generation ) {
						return;
					}
					if ( is_array( $record ) && $record['generation'] === $generation ) {
						if ( $this->is_awaiting_start( $record ) ) {
							return;
						}
						$complete = false;
						return;
					}

						$now      = time();
						$awaiting = $this->to_awaiting_merchant_start( self::SOURCE_ROLLBACK, $now, $generation, true );
					if ( is_array( $record ) ) {
						$awaiting['revision'] = $record['revision'] + 1;
						$stored               = $this->state_store->compare_and_set_record( $record, $awaiting );
						if ( $stored ) {
							$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
						}
					} else {
						$stored = $this->state_store->create_record( $awaiting );
					}
						$complete = $stored && $complete;
				}
			);

			return $complete;
		} finally {
			$this->release_network_lease( $network_token );
		}
	}

	/**
	 * Classify the Stripe Billing marker for the admin notices and return the record to render.
	 *
	 * Not read-only: it may exclude, supersede or create the record before returning it.
	 *
	 * @return array<string,mixed>|null Current durable record.
	 */
	public function classify_for_admin_notice(): ?array {
		$record = $this->state_store->get_record();
		if ( is_array( $record ) ) {
			if ( $this->is_awaiting_start( $record ) && $this->record_has_stripe_billing_failure( $record ) ) {
				if ( $this->is_network( $record ) ) {
					if ( $this->schedule_network_exclusion_convergence( $record, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS ) ) {
						$this->propagate_network_exclusion( $record['generation'], WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
					}
				} else {
					$this->exclude_awaiting_record( $record, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
				}
				return $this->state_store->get_record();
			}
			if ( $this->can_reopen_terminal_record( $record, true ) ) {
				$now = time();
				$this->with_write_lock(
					$now,
					function () use ( $record, $now ): void {
						$current = $this->state_store->get_record();
						if ( is_array( $current ) && $current === $record ) {
							$this->replace_terminal_with_awaiting_start( $current, 'terminal_superseded', $now );
						}
					}
				);
			}
			return $this->state_store->get_record();
		}
		// Without the extension or a store waiting for its switch there is nothing to offer, so the preflight scan would run
		// on every admin page for nothing.
		if ( ! $this->arbiter->is_builtin_enabled() || ! ( $this->arbiter->is_extension_owner() || $this->arbiter->is_awaiting_switch() ) ) {
			return $record;
		}

		if ( true !== $this->get_admin_stripe_billing_classification( null ) ) {
			return null;
		}

		$now = time();
		// True means the exclusion was written; the record is read again once the lock is released.
		$result = $this->with_write_lock(
			$now,
			function () use ( $now ) {
				if ( is_array( $this->state_store->get_record() ) ) {
					return $this->state_store->get_record();
				}
				$excluded                             = $this->create_pending_record( self::SOURCE_LOCAL_EXCLUSION, $now );
				$excluded['state']                    = WooPaymentsCutoverStateStore::STATE_EXCLUDED;
				$excluded['current_step']             = self::STEP_EXCLUDED;
				$excluded[ self::BLOCKING_CODES_KEY ] = array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
				$excluded['next_attempt_at']          = null;
				$excluded['step_log'][]               = array(
					'step'    => self::STEP_EXCLUDED,
					'at'      => $now,
					'context' => array( 'code' => WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS ),
				);

				$this->state_store->create_record( $excluded );
				return true;
			}
		);
		return true === $result ? $this->state_store->get_record() : $result;
	}

	/**
	 * Tell whether the stored record lets an extension-owned store start a cutover generation.
	 *
	 * @return bool
	 */
	public function should_offer_start(): bool {
		$record = $this->state_store->get_record();
		if ( ! is_array( $record ) ) {
			return true;
		}
		if ( $this->is_awaiting_start( $record ) ) {
			return ! $this->record_has_stripe_billing_failure( $record );
		}

		return $this->can_reopen_terminal_record( $record, true );
	}

	/**
	 * Atomically consume the one reconnect notice while leaving silent retries active.
	 *
	 * @return bool True only for the request that claimed the notice.
	 */
	public function consume_reconnect_notice(): bool {
		return $this->consume_notice( fn( ?array $record ): bool => $this->is_reconnect_notice_due( $record ), 'reconnect_notice_shown' );
	}

	/**
	 * Tell whether a completed record still shows a completion notice, which stays until a manager dismisses it.
	 *
	 * @param array<string,mixed>|null $record Cutover record.
	 * @param string                   $notice One of the NOTICE_* constants.
	 * @return bool
	 */
	public function is_completion_notice_due( ?array $record, string $notice ): bool {
		return is_array( $record )
			&& WooPaymentsCutoverStateStore::STATE_DONE === ( $record['state'] ?? null )
			&& self::NOTICE_SUCCESS === $notice
			&& is_array( $record['informational_outcomes'] ?? null )
			&& ! $this->has_information_outcome( $record['informational_outcomes'], array( 'code' => $notice . '_notice_dismissed' ) );
	}

	/**
	 * Record a manager's dismissal of a cutover notice (completion or bundled exclusion) on the durable record.
	 *
	 * @param string $notice One of the NOTICE_* constants.
	 * @return bool True only for the request that recorded the dismissal.
	 */
	public function dismiss_completion_notice( string $notice ): bool {
		if ( self::NOTICE_BUNDLED_EXCLUSION === $notice ) {
			return $this->consume_notice( fn( ?array $record ): bool => $this->is_bundled_exclusion_notice_due( $record ), $notice . '_notice_dismissed' );
		}

		return $this->consume_notice( fn( ?array $record ): bool => $this->is_completion_notice_due( $record, $notice ), $notice . '_notice_dismissed' );
	}

	/**
	 * Tell whether a store excluded for the bundled WooPayments subscriptions still owes its notice.
	 *
	 * The dismissal is recorded on the record, so a reopened and excluded-again store, which gets a new generation, sees it again.
	 *
	 * @param array<string,mixed>|null $record Current record.
	 * @return bool
	 */
	public function is_bundled_exclusion_notice_due( ?array $record ): bool {
		return is_array( $record )
			&& WooPaymentsCutoverStateStore::STATE_EXCLUDED === ( $record['state'] ?? null )
			&& in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, (array) ( $record[ self::BLOCKING_CODES_KEY ] ?? array() ), true )
			&& is_array( $record['informational_outcomes'] ?? null )
			&& ! $this->has_information_outcome( $record['informational_outcomes'], array( 'code' => self::NOTICE_BUNDLED_EXCLUSION . '_notice_dismissed' ) );
	}

	/**
	 * Forget the cached admin classification, so the next admin page checks the bundled-flavor rule again.
	 */
	public function forget_admin_classification(): void {
		delete_option( self::ADMIN_CLASSIFICATION_OPTION );
	}

	/**
	 * Record a shown or dismissed notice on the current record.
	 *
	 * @param callable $is_due Tells whether a record still owes the notice.
	 * @param string   $code   Informational outcome and step recorded once shown or dismissed.
	 * @return bool True only for the request that recorded it.
	 */
	private function consume_notice( callable $is_due, string $code ): bool {
		// Checked before the lease too: admin pages call this, and the lease writes options.
		if ( ! $is_due( $this->state_store->get_record() ) ) {
			return false;
		}
		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $is_due, $now, $code ): bool {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || ! $is_due( $record ) ) {
					return false;
				}
				$updated                           = $record;
				$updated['revision']               = $record['revision'] + 1;
				$updated['updated_at']             = $now;
				$updated['informational_outcomes'] = $this->merge_information_outcomes( $record['informational_outcomes'], array( array( 'code' => $code ) ) );
				$updated                           = $this->append_step( $updated, $code, $now );

				return $this->state_store->compare_and_set_record( $record, $updated );
			},
			false
		);
	}

	/**
	 * Tell whether a record carries a reconnect notice that has not been shown yet.
	 *
	 * @param array<string,mixed>|null $record Cutover record.
	 * @return bool
	 */
	private function is_reconnect_notice_due( ?array $record ): bool {
		return is_array( $record )
			&& in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true )
			&& $this->has_information_outcome( $record['informational_outcomes'], array( 'code' => 'reconnect_required' ) )
			&& ! $this->has_information_outcome( $record['informational_outcomes'], array( 'code' => 'reconnect_notice_shown' ) );
	}

	/**
	 * Fan one generation out to every site in the current network.
	 *
	 * A start, merchant or mandatory, is refused and writes nothing unless the WooPayments feature is on at every runnable
	 * site, so no generation holds a site the built-in WooPayments cannot run on. An archived, spam or deleted site does not
	 * hold the start: its feature is turned on with the start, so once restored it finishes its own switch.
	 *
	 * @param string $source Trigger source.
	 * @return bool True when at least one site owns scheduled durable work; the first attempt's fan-out repair adds any site skipped here.
	 */
	private function enqueue_network_generation( string $source ): bool {
		$site_ids = $this->get_current_network_site_ids();
		if ( array() === $site_ids ) {
			return false;
		}
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return false;
		}

		$scheduled_somewhere = false;
		$propagate_exclusion = null;
		try {
			$maximum_generation   = 0;
			$active_generations   = array();
			$terminal_generations = array();
			$excluded_generations = array();
			$feature_off_sites    = array();
			$paused_feature_off   = array();
			$this->run_on_sites(
				$site_ids,
				function ( int $site_id ) use ( &$maximum_generation, &$active_generations, &$terminal_generations, &$excluded_generations, &$feature_off_sites, &$paused_feature_off ): void {
					if ( ! $this->arbiter->is_builtin_enabled() ) {
						if ( $this->is_site_runnable( $site_id ) ) {
							$feature_off_sites[] = $site_id;
						} else {
							$paused_feature_off[] = $site_id;
						}
					}
						$record             = $this->state_store->get_record();
						$record_generation  = is_array( $record ) ? $record['generation'] : 0;
						$maximum_generation = max( $maximum_generation, $record_generation );
					if ( is_array( $record ) && $this->is_network( $record ) ) {
						if ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_RUNNING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) ) {
							$active_generations[] = $record_generation;
						} else {
							$terminal_generations[] = $record_generation;
							if ( WooPaymentsCutoverStateStore::STATE_EXCLUDED === $record['state'] ) {
								$excluded_generations[ $record_generation ] = $record[ self::BLOCKING_CODES_KEY ][0] ?? WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS;
							}
						}
					}
				}
			);
			if ( array() !== $feature_off_sites ) {
				$this->log_error( 'WooPayments cutover: the network switch was not started because the WooPayments feature is off at some sites.', array( 'site_ids' => $feature_off_sites ) );
				return false;
			}
			// The network deactivation takes the extension from a paused site too; with the feature on, the site restored later
			// reads as waiting for its switch instead of having no payments at all.
			$this->run_on_sites(
				$paused_feature_off,
				function (): void {
					wc_get_container()->get( FeaturesController::class )->change_feature_enable( WooPaymentsFeatureController::FEATURE_ID, true );
				}
			);
			$highest_active_generation = array() === $active_generations ? 0 : max( $active_generations );
			$generation                = $highest_active_generation >= $maximum_generation ? max( 1, $highest_active_generation ) : $maximum_generation + 1;
			if ( isset( $excluded_generations[ $generation ] ) ) {
				$propagate_exclusion = $excluded_generations[ $generation ];
			}

			if ( null === $propagate_exclusion ) {
				$this->run_on_sites_with_write_locks(
					$site_ids,
					function ( int $site_id, bool $lock_held ) use ( $generation, &$scheduled_somewhere, $source ): void {
							// A peer whose lease is busy is skipped; the first attempt's fan-out repair adds it.
						if ( ! $lock_held ) {
							return;
						}
							$record = $this->state_store->get_record();
						if ( is_array( $record ) && $generation === $record['generation'] && $this->is_network( $record ) ) {
							if ( $this->is_awaiting_start( $record ) ) {
								$scheduled_somewhere = $this->start_awaiting_generation( $record, $source, time() ) || $scheduled_somewhere;
							} elseif ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) ) {
								$scheduled_somewhere = $this->ensure_record_scheduled( $record, time() ) || $scheduled_somewhere;
							} else {
								// A running attempt of this generation is durable work already.
								$scheduled_somewhere = true;
							}
							return;
						}

							$now                        = time();
							$pending                    = $this->create_pending_record( $source, $now );
							$pending['generation']      = $generation;
							$pending['network_cutover'] = true;
						if ( is_array( $record ) ) {
							$pending['revision'] = $record['revision'] + 1;
							$stored              = $this->state_store->compare_and_set_record( $record, $pending );
							if ( $stored ) {
								$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
							}
						} else {
							$stored = $this->state_store->create_record( $pending );
						}
							$scheduled_somewhere = ( $stored && $this->ensure_record_scheduled( $pending, $now ) ) || $scheduled_somewhere;
					}
				);
			}
		} finally {
			$this->release_network_lease( $network_token );
		}

		return null !== $propagate_exclusion ? $this->propagate_network_exclusion( $generation, $propagate_exclusion ) : $scheduled_somewhere;
	}

	/**
	 * Get site IDs in the current network.
	 *
	 * @return int[]
	 */
	private function get_current_network_site_ids(): array {
		$site_ids = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'fields'     => 'ids',
				'number'     => 0,
			)
		);

		return array_values( array_map( 'intval', $site_ids ) );
	}

	/**
	 * Run a callback with each given site current, then restore the site the request started on.
	 *
	 * @param int[]    $site_ids Site IDs.
	 * @param callable $callback Called with the site ID while that site is current.
	 */
	private function run_on_sites( array $site_ids, callable $callback ): void {
		$current_blog_id = get_current_blog_id();
		foreach ( $site_ids as $site_id ) {
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			try {
				$callback( $site_id );
			} finally {
				if ( get_current_blog_id() !== $current_blog_id ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Run a callback with each given site current and its write lock taken, then restore the site the request started on.
	 *
	 * The lock is taken after the switch and released before the restore. The network main site takes no site lock: the
	 * network lock is its lock, so it always counts as held. The callback decides what a busy lock means.
	 *
	 * @param int[]    $site_ids Site IDs.
	 * @param callable $callback Called with the site ID and whether its write lock is held, while that site is current.
	 */
	private function run_on_sites_with_write_locks( array $site_ids, callable $callback ): void {
		$current_blog_id      = get_current_blog_id();
		$network_main_site_id = get_main_site_id( get_current_network_id() );
		foreach ( $site_ids as $site_id ) {
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			$site_token = $network_main_site_id === $site_id ? null : $this->state_store->acquire_lease( time() );
			try {
				$callback( $site_id, $network_main_site_id === $site_id || null !== $site_token );
			} finally {
				if ( is_string( $site_token ) ) {
					$this->state_store->release_lease( $site_token );
				}
				if ( get_current_blog_id() !== $current_blog_id ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Claim one current Action Scheduler callback.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $generation Scheduled generation.
	 * @param mixed $attempt    Scheduled attempt.
	 */
	public function handle_reconcile( $generation, $attempt ): void {
		$generation = $this->normalize_action_identity_value( $generation );
		$attempt    = $this->normalize_action_identity_value( $attempt );
		if ( null === $generation || null === $attempt ) {
			return;
		}

		$this->handle_reconcile_attempt( $generation, $attempt );
	}

	/**
	 * Claim one validated Action Scheduler callback.
	 *
	 * @param int $generation Scheduled generation.
	 * @param int $attempt    Scheduled attempt.
	 */
	private function handle_reconcile_attempt( int $generation, int $attempt ): void {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return;
		}

		$now     = time();
		$claimed = null;
		$this->with_write_lock(
			$now,
			function ( string $token ) use ( $generation, $attempt, $now, &$claimed ): void {
				$record = $this->state_store->get_record();
				if (
					! is_array( $record )
					|| $generation !== $record['generation']
					|| $attempt !== $record['attempt'] + 1
					|| ! in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true )
				) {
					return;
				}
				if ( self::STEP_VERIFY_BUILTIN_OWNERSHIP === $record['current_step'] && self::$request_token === $record['request_origin_token'] ) {
					return;
				}

				$resume_step                           = $record['current_step'];
				$claimed                               = $record;
				$claimed['revision']                   = $record['revision'] + 1;
				$claimed['state']                      = WooPaymentsCutoverStateStore::STATE_RUNNING;
				$claimed['attempt']                    = $attempt;
				$claimed['action_id']                  = 0;
				$claimed['current_step']               = self::STEP_VERIFY_BUILTIN_OWNERSHIP === $resume_step ? $resume_step : self::STEP_RUNNING;
				$claimed['updated_at']                 = $now;
				$claimed['next_attempt_at']            = null;
				$claimed[ self::CLAIM_TOKEN_KEY ]      = $token;
				$claimed[ self::CLAIM_EXPIRES_AT_KEY ] = $now + self::RUNNING_TIMEOUT;
				$claimed                               = $this->append_step( $claimed, self::STEP_RUNNING, $now );
				if ( ! $this->state_store->compare_and_set_record( $record, $claimed ) ) {
					$claimed = null;
				}
			}
		);

		if ( is_array( $claimed ) ) {
			if ( self::STEP_VERIFY_BUILTIN_OWNERSHIP === $claimed['current_step'] ) {
				$this->verify_native_ownership_claim( $claimed );
			} else {
				$this->reconcile_claim( $claimed );
			}
		}
	}

	/**
	 * Persist a deferred result and schedule its successor.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $expected_claim Exact running revision owned by this worker.
	 * @param array<int,string>   $codes          Remaining condition codes.
	 * @param array<int,mixed>    $outcomes       Informational outcomes to retain.
	 * @return bool True when the next attempt is scheduled.
	 */
	public function defer( array $expected_claim, array $codes, array $outcomes = array() ): bool {
		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $expected_claim, $now, $codes, $outcomes ): bool {
				$record = $this->state_store->get_record();
				if (
					! is_array( $record )
					|| $record !== $expected_claim
					|| WooPaymentsCutoverStateStore::STATE_RUNNING !== $record['state']
					|| $record[ self::CLAIM_EXPIRES_AT_KEY ] <= $now
				) {
					return false;
				}

				$deferred                               = $record;
				$deferred['revision']                   = $record['revision'] + 1;
				$deferred['state']                      = WooPaymentsCutoverStateStore::STATE_DEFERRED;
				$deferred['action_id']                  = 0;
				$deferred['current_step']               = self::STEP_DEFERRED;
				$deferred['updated_at']                 = $now;
				$deferred[ self::BLOCKING_CODES_KEY ]   = $this->normalize_codes( $codes );
				$deferred['informational_outcomes']     = $this->merge_information_outcomes( $record['informational_outcomes'], $outcomes );
				$deferred['next_attempt_at']            = $now + $this->get_retry_delay( $record, $now );
				$deferred[ self::CLAIM_TOKEN_KEY ]      = null;
				$deferred[ self::CLAIM_EXPIRES_AT_KEY ] = null;
				$deferred                               = $this->append_step( $deferred, self::STEP_DEFERRED, $now, array( 'codes' => $deferred[ self::BLOCKING_CODES_KEY ] ) );
				if ( ! $this->state_store->compare_and_set_record( $record, $deferred ) ) {
					return false;
				}

				return $this->ensure_record_scheduled( $deferred, $now );
			},
			false
		);
	}

	/**
	 * Disposition one claimed reconciliation attempt.
	 *
	 * Resolve, finalize, exclude, or defer a claimed attempt without leaving it running.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function reconcile_claim( array $claimed ): void {
		try {
			// A merchant who turned the extension back on after this generation's finalize deactivated it chose the extension:
			// the rollback, not the switch (that finalize left its request token on the record). The request reads ownership
			// once, so this sees an activation made before the request started.
			if ( ! $this->is_network( $claimed ) && null !== ( $claimed['request_origin_token'] ?? null ) && $this->arbiter->is_extension_owner() ) {
				$this->roll_back_claim( $claimed );
				return;
			}
			if ( $this->is_network( $claimed ) ) {
				$fanout = $this->repair_network_generation_fanout( $claimed );
				if ( 'superseded' === $fanout['status'] ) {
					$this->supersede_network_claim( $claimed, $fanout['generation'], $fanout['phase'] );
					return;
				}
				if ( 'excluded' === $fanout['status'] ) {
					if ( ! $this->propagate_network_exclusion( $claimed['generation'], $fanout['code'] ) ) {
						$this->defer( $claimed, array( 'network_exclusion_propagation_pending' ) );
					}
					return;
				}
				if ( 'complete' !== $fanout['status'] ) {
					$this->defer( $claimed, array( 'network_fanout_pending' ) );
					return;
				}
			}
			$this->log_late_first_attempt( $claimed );
			// Past finalize the extension is already off and the built-in WooPayments owns payments, so an exclusion would
			// protect nothing and leave the store with no owner: the bundled failure defers the claim instead. A token next to an
			// active extension (a network barrier that stopped before or at the deactivation) is not past finalize.
			// On a network the handoff is the network deactivation: once the extension is off network-wide, no site of the
			// generation is excluded (propagate_network_exclusion() refuses too).
			$past_finalize = $this->is_network( $claimed )
				? ! $this->preflight_service->is_woopayments_network_active()
				: null !== ( $claimed['request_origin_token'] ?? null ) && ! $this->arbiter->is_extension_owner();
			$failures      = $this->normalize_codes( $this->preflight_service->get_failures() );
			if ( ! $past_finalize && in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $failures, true ) ) {
				if ( $this->is_network( $claimed ) ) {
					if ( ! $this->propagate_network_exclusion( $claimed['generation'], WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS ) ) {
						$this->defer( $claimed, array( 'network_exclusion_propagation_pending' ) );
					}
				} else {
					$this->exclude( $claimed, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
				}
				return;
			}

			$outcomes = array();
			if ( in_array( WooPaymentsPlatformConnectionService::FAILURE_CONNECTION_OWNER_USER_TOKEN_UNAVAILABLE, $failures, true ) && $this->preflight_service->is_cutover_connection_owner_user_missing() ) {
				$outcomes[] = array( 'code' => 'reconnect_required' );
			}

			$plugin_update_succeeded = false;
			if ( in_array( WooPaymentsCutoverPreflightService::FAILURE_PLUGIN_VERSION_UNSUPPORTED, $failures, true ) ) {
				$plugin_update_succeeded = $this->update_woopayments_plugin();
			}

			$outcomes = array_merge( $outcomes, $this->get_adopted_queue_action_outcomes() );

			$this->preflight_service->invalidate_current_blog_memoization();
			$remaining_failures = $this->normalize_codes( $this->preflight_service->get_failures() );
			if ( ! $past_finalize && in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $remaining_failures, true ) ) {
				if ( $this->is_network( $claimed ) ) {
					if ( ! $this->propagate_network_exclusion( $claimed['generation'], WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS ) ) {
						$this->defer( $claimed, array( 'network_exclusion_propagation_pending' ), $outcomes );
					}
				} else {
					$this->exclude( $claimed, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
				}
				return;
			}
			// The plugin records its new version only on its next request, so check the installed files instead.
			if ( $plugin_update_succeeded && in_array( WooPaymentsCutoverPreflightService::FAILURE_PLUGIN_VERSION_UNSUPPORTED, $remaining_failures, true ) && ! $this->is_installed_woopayments_plugin_version_supported() ) {
				$this->log_error( 'WooPayments cutover plugin update completed without installing a supported version.' );
			}

			if ( array() !== $remaining_failures ) {
				$this->defer( $claimed, $remaining_failures, $outcomes );
				return;
			}

			if ( $this->is_network( $claimed ) ) {
				$this->finalize_network_claim( $claimed, $outcomes );
			} else {
				$this->finalize_site_claim( $claimed, $outcomes );
			}
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover reconciliation resolver failed.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'reconciliation_resolver_failed' ) );
		}
	}

	/**
	 * Repair missing or older site records before a network worker can enter the all-site barrier.
	 *
	 * @param array<string,mixed> $claimed Exact running source record.
	 * @return array{status:string,generation:int,code:string,phase:string} Fan-out disposition.
	 */
	private function repair_network_generation_fanout( array $claimed ): array {
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return array(
				'status'     => 'incomplete',
				'generation' => $claimed['generation'],
				'code'       => '',
				'phase'      => 'active',
			);
		}

		$complete = true;
		try {
			$highest_generation = $claimed['generation'];
			$excluded_code      = '';
			$highest_phase      = 'active';
			$sites_to_repair    = array();
			$this->run_on_sites(
				$this->get_current_network_site_ids(),
				function ( int $site_id ) use ( $claimed, &$sites_to_repair, &$highest_generation, &$highest_phase, &$excluded_code ): void {
						$record = $this->state_store->get_record();
					if ( $this->has_left_network_generation( $record, $claimed['generation'] ) ) {
						return;
					}
					if ( ! $this->is_settled_network_peer( $record, $claimed['generation'] ) ) {
						$sites_to_repair[] = $site_id;
					}
					if ( is_array( $record ) && $record['generation'] > $highest_generation ) {
						$highest_generation = $record['generation'];
						$highest_phase      = $this->is_awaiting_start( $record ) ? 'awaiting_merchant_start' : 'active';
					}
					if ( is_array( $record ) && $record['generation'] === $highest_generation && $highest_generation > $claimed['generation'] && self::STEP_AWAITING_MERCHANT_START !== $record['current_step'] ) {
						$highest_phase = 'active';
					}
					if ( is_array( $record ) && $record['generation'] === $claimed['generation'] && WooPaymentsCutoverStateStore::STATE_EXCLUDED === $record['state'] ) {
						$excluded_code = $record[ self::BLOCKING_CODES_KEY ][0] ?? WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS;
					}
				}
			);
			if ( $highest_generation > $claimed['generation'] ) {
				return array(
					'status'     => 'superseded',
					'generation' => $highest_generation,
					'code'       => '',
					'phase'      => $highest_phase,
				);
			}
			if ( '' !== $excluded_code ) {
				return array(
					'status'     => 'excluded',
					'generation' => $claimed['generation'],
					'code'       => $excluded_code,
					'phase'      => 'active',
				);
			}

			// Only sites the read-only pass found unsettled are written, so a steady network takes no site leases.
			$this->run_on_sites_with_write_locks(
				$sites_to_repair,
				function ( int $site_id, bool $lock_held ) use ( &$complete, $claimed ): void {
					if ( ! $lock_held ) {
						$complete = false;
						return;
					}

						$record = $this->state_store->get_record();
					if ( is_array( $record ) && $record['generation'] > $claimed['generation'] ) {
						$complete = false;
						return;
					}
					if ( is_array( $record ) && $record['generation'] === $claimed['generation'] && $this->is_network( $record ) ) {
						if ( $this->is_awaiting_start( $record ) ) {
							$complete = $this->start_awaiting_generation( $record, self::SOURCE_NETWORK_FANOUT_REPAIR, time() ) && $complete;
						} elseif ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) ) {
							$complete = $this->ensure_record_scheduled( $record, time() ) && $complete;
						}
						return;
					}

						$now                        = time();
						$pending                    = $this->create_pending_record( self::SOURCE_NETWORK_FANOUT_REPAIR, $now );
						$pending['generation']      = $claimed['generation'];
						$pending['network_cutover'] = true;
					if ( is_array( $record ) ) {
						$pending['revision'] = $record['revision'] + 1;
						if ( $pending['generation'] === $record['generation'] ) {
							$pending['attempt'] = $record['attempt'];
						}
						$stored = $this->state_store->compare_and_set_record( $record, $pending );
						if ( $stored ) {
							$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
						}
					} else {
						$stored = $this->state_store->create_record( $pending );
					}
						$complete = $stored && $this->ensure_record_scheduled( $pending, $now ) && $complete;
				}
			);
		} finally {
			$this->release_network_lease( $network_token );
		}

		return array(
			'status'     => $complete ? 'complete' : 'incomplete',
			'generation' => $claimed['generation'],
			'code'       => '',
			'phase'      => 'active',
		);
	}

	/**
	 * Replace a stale source claim with durable work for the higher observed network generation.
	 *
	 * @param array<string,mixed> $claimed    Exact stale running source record.
	 * @param int                 $generation Higher observed generation.
	 * @param string              $phase      Higher generation phase.
	 */
	private function supersede_network_claim( array $claimed, int $generation, string $phase ): void {
		$now = time();
		$this->with_write_lock(
			$now,
			function () use ( $claimed, $now, $generation, $phase ): void {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $record !== $claimed ) {
					return;
				}
				if ( 'awaiting_merchant_start' === $phase ) {
					$pending = $this->to_awaiting_merchant_start( self::SOURCE_NETWORK_GENERATION_SUPERSEDED, $now, $generation, true );
				} else {
					$pending                    = $this->create_pending_record( self::SOURCE_NETWORK_GENERATION_SUPERSEDED, $now );
					$pending['generation']      = $generation;
					$pending['network_cutover'] = true;
				}
				$pending['revision'] = $record['revision'] + 1;
				if ( $this->state_store->compare_and_set_record( $record, $pending ) ) {
					if ( 'awaiting_merchant_start' !== $phase ) {
						$this->ensure_record_scheduled( $pending, $now );
					}
				}
			}
		);
	}

	/**
	 * Normalize one clear site and schedule ownership verification for a later request.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 */
	private function finalize_site_claim( array $claimed, array $outcomes ): void {
		$claimed = $this->prepare_finalization_claim( $claimed, $outcomes );
		if ( null === $claimed ) {
			return;
		}
		// The fence stores this request's token, which makes the built-in WooPayments the owner once the extension is inactive
		// (WooPaymentsRuntimeArbiter), so a lost write deactivates nothing and the extension keeps payments.
		$claimed = $this->fence_finalizing_claim( $claimed, self::$request_token );
		if ( null === $claimed ) {
			return;
		}
		$deactivated = true;
		try {
			// An extension the merchant already deactivated, or a retry whose earlier finalize did, has nothing to deactivate.
			if ( $this->preflight_service->is_woopayments_site_active() || $this->preflight_service->is_woopayments_network_active() ) {
				$this->internal_plugin_lifecycle_change = true;
				$deactivated                            = $this->preflight_service->deactivate_woopayments_plugin();
			}
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover plugin deactivation failed.', array( 'error' => $error->getMessage() ) );
			$deactivated = false;
		} finally {
			$this->internal_plugin_lifecycle_change = false;
		}
		if ( ! $deactivated ) {
			// A hook that threw after WordPress wrote the active plugins still left the extension inactive.
			try {
				$deactivated = ! $this->preflight_service->is_woopayments_site_active() && ! $this->preflight_service->is_woopayments_network_active();
			} catch ( \Throwable $error ) {
				$deactivated = false;
			}
		}
		if ( ! $deactivated ) {
			// The token stays, so the next attempt finds the extension active and rolls the switch back to it.
			$this->defer( $claimed, array( 'plugin_deactivation_failed' ), $outcomes );
			return;
		}
		// The tier follows now; this write takes no lock. A rollback made since is unharmed: the effective tier reads
		// available while the extension owns payments (WooPaymentsSetupTier::get_effective_tier()).
		$this->account_service->sync_setup_tier_from_options( false );

		$this->schedule_ownership_verification( $claimed, $outcomes );
	}

	/**
	 * Fence a claim before finalize deactivates the extension, or before the ownership check moves the tier.
	 *
	 * The compare-and-set fails when the record changed since this worker claimed it (an extension activation rolled it
	 * back, or repair_schedule() recovered the claim), or when another request holds the write lock, so a stale or blocked
	 * worker finalizes nothing.
	 *
	 * @param array<string,mixed> $claimed        Exact running state owned by this worker.
	 * @param string|null         $finalize_token Finalize's request token, stored with the fence before the deactivation.
	 * @return array<string,mixed>|null The fenced claim, or null when the claim is no longer this worker's.
	 */
	private function fence_finalizing_claim( array $claimed, ?string $finalize_token = null ): ?array {
		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $claimed, $now, $finalize_token ): ?array {
				if ( $claimed[ self::CLAIM_EXPIRES_AT_KEY ] <= $now ) {
					return null;
				}
				$fenced               = $claimed;
				$fenced['revision']   = $claimed['revision'] + 1;
				$fenced['updated_at'] = $now;
				if ( null !== $finalize_token ) {
					$fenced['request_origin_token'] = $finalize_token;
				}

				return $this->state_store->compare_and_set_record( $claimed, $fenced ) ? $fenced : null;
			},
			null
		);
	}

	/**
	 * Roll back this worker's claim as the merchant's choice of the WooPayments extension.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function roll_back_claim( array $claimed ): void {
		$now = time();
		$this->with_write_lock(
			$now,
			function () use ( $claimed, $now ): void {
				$this->roll_back_open_generation( $claimed, $now );
			}
		);
	}

	/**
	 * Replace an open generation with a rollback generation awaiting the merchant's start; call with the write lock held.
	 *
	 * The open generation loses its next attempt, so it never deactivates the extension or migrates data again, and the
	 * tier is re-synced for the extension.
	 *
	 * @param array<string,mixed> $record  Current open record.
	 * @param int                 $now     Current UTC timestamp.
	 * @param bool|null           $network Whether the rollback generation is a network one; null keeps the record's scope.
	 * @return bool True when the rollback generation was stored.
	 */
	private function roll_back_open_generation( array $record, int $now, ?bool $network = null ): bool {
		if ( ! $this->replace_terminal_with_awaiting_start( $record, self::SOURCE_ROLLBACK, $now, $network ) ) {
			return false;
		}
		$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
		$this->account_service->sync_setup_tier_from_options( true );

		return true;
	}

	/**
	 * Run the data migration for a claim about to finalize.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 * @return array<string,mixed>|null The claim, or null after a deferred result.
	 */
	private function prepare_finalization_claim( array $claimed, array $outcomes ): ?array {
		try {
			$result = $this->normalization_runner->run();
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover normalization failed.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( self::CODE_DATA_MIGRATION_FAILED ), $outcomes );
			return null;
		}
		if ( in_array( 'settings_persistence_failed', $result['changes'], true ) ) {
			$this->defer( $claimed, array( self::CODE_DATA_MIGRATION_FAILED ), $outcomes );
			return null;
		}

		return $claimed;
	}

	/**
	 * Mark one site ready, then let the last ready site complete the network barrier.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 */
	private function finalize_network_claim( array $claimed, array $outcomes ): void {
		$claimed = $this->prepare_finalization_claim( $claimed, $outcomes );
		if ( null === $claimed ) {
			return;
		}

		$outcomes[] = array(
			'code'       => 'network_site_ready',
			'generation' => $claimed['generation'],
			'site_id'    => get_current_blog_id(),
		);
		if ( ! $this->defer( $claimed, array( 'network_barrier' ), $outcomes ) ) {
			return;
		}

		$this->try_complete_network_barrier( $claimed['generation'] );
	}

	/**
	 * Complete an all-ready network generation once under a network-wide lock.
	 *
	 * @param int $generation Generation being finalized.
	 */
	private function try_complete_network_barrier( int $generation ): void {
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return;
		}

		$paused_site_marker = null;
		try {
			if ( ! $this->is_network_generation_ready( $generation ) ) {
				return;
			}

			// A paused site never runs its own claim, so its Stripe Billing marker is checked here, before the plugin leaves every
			// site. Once the extension is off network-wide the switch has handed over, and an exclusion would only remove owners.
			$paused_site_marker = $this->preflight_service->is_woopayments_network_active() ? $this->paused_site_has_stripe_billing_marker() : false;
			if ( false === $paused_site_marker ) {
				$this->deactivate_network_plugin_for_generation( $generation );
			}
		} finally {
			$this->release_network_lease( $network_token );
		}

		// Propagation takes the network lease itself; when it cannot, the sites waiting at the barrier retry and check again.
		if ( true === $paused_site_marker ) {
			$this->propagate_network_exclusion( $generation, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
		}
	}

	/**
	 * Deactivate the network-active plugin for a completed barrier, then schedule every site's ownership verification.
	 *
	 * @param int $generation Completed network barrier generation.
	 */
	private function deactivate_network_plugin_for_generation( int $generation ): void {
		$marked_sites = $this->store_network_finalize_tokens( $generation );
		if ( null === $marked_sites ) {
			return;
		}
		$deactivated = ! $this->preflight_service->is_woopayments_network_active();
		if ( ! $deactivated ) {
			try {
				$this->internal_plugin_lifecycle_change = true;
				$deactivated                            = $this->preflight_service->deactivate_woopayments_plugin();
			} catch ( \Throwable $error ) {
				$this->log_error( 'WooPayments network cutover plugin deactivation failed.', array( 'error' => $error->getMessage() ) );
				$deactivated = false;
			} finally {
				$this->internal_plugin_lifecycle_change = false;
			}
		}
		if ( ! $deactivated ) {
			$this->clear_network_finalize_tokens( $generation, $marked_sites );
			return;
		}

		$this->schedule_network_ownership_verification( $generation );
	}

	/**
	 * Store this request's token and the built-in tier on every site waiting at the network barrier, before the network
	 * deactivation, so each site's built-in WooPayments owns payments once the extension leaves.
	 *
	 * @param int $generation Completed network barrier generation.
	 * @return int[]|null The sites whose token this request stored, or null when a site failed: nothing is deactivated, the
	 *                    tokens this pass stored are cleared while the extension stays, and the barrier retries.
	 */
	private function store_network_finalize_tokens( int $generation ): ?array {
		$stored       = true;
		$marked_sites = array();
		$this->run_on_sites_with_write_locks(
			$this->get_current_network_site_ids(),
			function ( int $site_id, bool $lock_held ) use ( $generation, &$stored, &$marked_sites ): void {
				if ( ! $stored ) {
					return;
				}
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $generation !== $record['generation'] || WooPaymentsCutoverStateStore::STATE_DEFERRED !== $record['state'] || ! in_array( 'network_barrier', $record[ self::BLOCKING_CODES_KEY ], true ) ) {
					// A site the barrier does not wait for (archived, spam or deleted), or one already handed over, needs nothing.
					// Any other runnable site moved on since the readiness check, so it is not prepared and the extension stays.
					if ( $this->is_site_runnable( $site_id ) && ! $this->has_network_handoff( $record, $generation ) && ! $this->has_left_network_generation( $record, $generation ) ) {
						$stored = false;
					}
					return;
				}
				if ( ! $lock_held ) {
					$stored = false;
					return;
				}
				$marked                         = $record;
				$marked['revision']             = $record['revision'] + 1;
				$marked['updated_at']           = time();
				$marked['request_origin_token'] = self::$request_token;
				$stored                         = $this->state_store->compare_and_set_record( $record, $marked );
				if ( $stored ) {
					$marked_sites[] = $site_id;
					$this->account_service->sync_setup_tier_from_options( false );
				}
			}
		);
		if ( ! $stored ) {
			$this->clear_network_finalize_tokens( $generation, $marked_sites );
			return null;
		}

		return $marked_sites;
	}

	/**
	 * Clear the tokens this request stored at the barrier while the extension stays network-active, so no site keeps a
	 * token beside the active extension once the barrier stops; a later deactivation by the merchant then switches no site alone.
	 *
	 * @param int   $generation Network generation.
	 * @param int[] $site_ids   Sites whose token this request stored.
	 */
	private function clear_network_finalize_tokens( int $generation, array $site_ids ): void {
		if ( array() === $site_ids || ! $this->preflight_service->is_woopayments_network_active() ) {
			return;
		}
		$this->run_on_sites_with_write_locks(
			$site_ids,
			function ( int $site_id, bool $lock_held ) use ( $generation ): void {
				$record = $this->state_store->get_record();
				if ( ! $lock_held || ! is_array( $record ) || $generation !== $record['generation'] || ( $record['request_origin_token'] ?? null ) !== self::$request_token ) {
					$this->log_error( 'WooPayments network cutover could not clear a barrier token beside the active extension.', array( 'site_id' => $site_id ) );
					return;
				}
				$cleared                         = $record;
				$cleared['revision']             = $record['revision'] + 1;
				$cleared['updated_at']           = time();
				$cleared['request_origin_token'] = null;
				$this->state_store->compare_and_set_record( $record, $cleared );
			}
		);
	}

	/**
	 * Tell whether a site left a network generation through its own rollback: a site-level activation of the extension
	 * after the handoff leaves a local record of a later generation (record_plugin_activation()).
	 *
	 * @param mixed $record     The site's cutover record.
	 * @param int   $generation Network generation.
	 * @return bool
	 */
	private function has_left_network_generation( $record, int $generation ): bool {
		return is_array( $record ) && ! $this->is_network( $record ) && $record['generation'] > $generation;
	}

	/**
	 * Tell whether a site's record already holds this generation's handoff: finalize's token, the ownership check, or done.
	 *
	 * @param mixed $record     The site's cutover record.
	 * @param int   $generation Network generation.
	 * @return bool
	 */
	private function has_network_handoff( $record, int $generation ): bool {
		return is_array( $record )
			&& $generation === $record['generation']
			&& ( null !== ( $record['request_origin_token'] ?? null ) || in_array( $record['current_step'], array( self::STEP_VERIFY_BUILTIN_OWNERSHIP, self::STEP_DONE ), true ) );
	}

	/**
	 * Tell whether an archived, spam or deleted site of the network has the Stripe Billing marker.
	 *
	 * @return bool|null True or false, or null when a site could not be checked, so the barrier waits.
	 */
	private function paused_site_has_stripe_billing_marker(): ?bool {
		$current_blog_id = get_current_blog_id();
		foreach ( $this->get_current_network_site_ids() as $site_id ) {
			if ( $this->is_site_runnable( $site_id ) ) {
				continue;
			}
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			try {
				$this->preflight_service->invalidate_current_blog_memoization();
				if ( in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $this->normalize_codes( $this->preflight_service->get_failures() ), true ) ) {
					return true;
				}
			} catch ( \Throwable $error ) {
				$this->log_error(
					'WooPayments network cutover could not check a paused site before deactivating the plugin.',
					array(
						'site_id' => $site_id,
						'error'   => $error->getMessage(),
					)
				);
				return null;
			} finally {
				if ( get_current_blog_id() !== $current_blog_id ) {
					restore_current_blog();
				}
			}
		}

		return false;
	}

	/**
	 * Tell whether every site reached the barrier for one generation.
	 *
	 * While the extension is still network-active, a runnable site whose WooPayments feature is off holds the barrier, so the
	 * network deactivation never leaves a site without a payments runtime. Once the extension is gone the read protects
	 * nothing, so a site that arrives late, restored or left behind by a busy lock, still completes.
	 *
	 * @param int $generation Generation being checked.
	 * @return bool
	 */
	private function is_network_generation_ready( int $generation ): bool {
		$current_blog_id  = get_current_blog_id();
		$extension_active = $this->preflight_service->is_woopayments_network_active();
		foreach ( $this->get_current_network_site_ids() as $site_id ) {
			// An archived, spam or deleted site's queue does not run, so it cannot hold the barrier. Its record stays: if
			// the site is restored, its pending attempt runs, finds the barrier complete and verifies on its own.
			if ( ! $this->is_site_runnable( $site_id ) ) {
				continue;
			}
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			try {
				if ( $extension_active && ! $this->arbiter->is_builtin_enabled() ) {
					$this->write_log_warning(
						sprintf( 'WooPayments cutover: generation %1$d waits at the network barrier while the WooPayments feature is off on site %2$d', $generation, $site_id ),
						array(
							'source'     => self::LOG_SOURCE,
							'generation' => $generation,
							'site_id'    => $site_id,
						)
					);
					return false;
				}
				$record = $this->state_store->get_record();
				if ( $this->has_left_network_generation( $record, $generation ) ) {
					continue;
				}
				$is_ready      = is_array( $record ) && WooPaymentsCutoverStateStore::STATE_DEFERRED === $record['state'] && in_array( 'network_barrier', $record[ self::BLOCKING_CODES_KEY ], true );
				$is_finalizing = is_array( $record ) && ( in_array( $record['current_step'], array( self::STEP_VERIFY_BUILTIN_OWNERSHIP, self::STEP_DONE ), true ) );
				if ( ! is_array( $record ) || $generation !== $record['generation'] || ( ! $is_ready && ! $is_finalizing ) ) {
					return false;
				}
			} finally {
				if ( get_current_blog_id() !== $current_blog_id ) {
					restore_current_blog();
				}
			}
		}

		return true;
	}

	/**
	 * Tell whether a network site's scheduled actions run: archived, spam and deleted sites do not serve requests.
	 *
	 * @param int $site_id Site ID.
	 * @return bool
	 */
	private function is_site_runnable( int $site_id ): bool {
		$site = get_site( $site_id );

		return $site instanceof \WP_Site && '1' !== (string) $site->archived && '1' !== (string) $site->spam && '1' !== (string) $site->deleted;
	}

	/**
	 * Schedule a future native-ownership probe on every ready site.
	 *
	 * @param int $generation Completed network barrier generation.
	 */
	private function schedule_network_ownership_verification( int $generation ): void {
		$this->run_on_sites_with_write_locks(
			$this->get_current_network_site_ids(),
			function ( int $site_id, bool $lock_held ) use ( $generation ): void {
				if ( ! $lock_held ) {
					return;
				}
					$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $generation !== $record['generation'] || WooPaymentsCutoverStateStore::STATE_DEFERRED !== $record['state'] || ! in_array( 'network_barrier', $record[ self::BLOCKING_CODES_KEY ], true ) ) {
					return;
				}
					$now                                      = time();
					$verification                             = $record;
					$verification['revision']                 = $record['revision'] + 1;
					$verification['state']                    = WooPaymentsCutoverStateStore::STATE_PENDING;
					$verification['action_id']                = 0;
					$verification['current_step']             = self::STEP_VERIFY_BUILTIN_OWNERSHIP;
					$verification['updated_at']               = $now;
					$verification[ self::BLOCKING_CODES_KEY ] = array();
					$verification['next_attempt_at']          = $now + self::FAST_RETRY_DELAY;
					$verification['request_origin_token']     = self::$request_token;
					$verification                             = $this->append_step( $verification, self::STEP_VERIFY_BUILTIN_OWNERSHIP, $now );
				if ( $this->state_store->compare_and_set_record( $record, $verification ) ) {
					$this->account_service->sync_setup_tier_from_options( false );
					$this->scheduler->cancel( $generation, $record['attempt'] + 1 );
					$this->ensure_record_scheduled( $verification, $now );
				}
			}
		);
	}

	/**
	 * Complete one verification claim only after a fresh request observes native ownership.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function verify_native_ownership_claim( array $claimed ): void {
		try {
			$plugin_active = $this->arbiter->is_extension_owner();
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover could not verify native ownership.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'builtin_ownership_verification_failed' ) );
			return;
		}
		if ( $plugin_active ) {
			$this->defer( $claimed, array( 'builtin_ownership_unverified' ) );
			return;
		}
		$claimed = $this->fence_finalizing_claim( $claimed );
		if ( null === $claimed ) {
			return;
		}
		$this->account_service->sync_setup_tier_from_options( false );
		// Every finalized claim reaches this step on its own site, network ones included, once native owns it.
		try {
			$remediation_scheduled = $this->preflight_service->ensure_fee_remediation_scheduled();
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover could not schedule the canceled-authorization fee remediation.', array( 'error' => $error->getMessage() ) );
			$remediation_scheduled = false;
		}
		if ( ! $remediation_scheduled ) {
			$this->defer( $claimed, array( WooPaymentsCutoverPreflightService::FAILURE_FEE_REMEDIATION_UNAVAILABLE ) );
			return;
		}

		$now = time();
		$this->with_write_lock(
			$now,
			function () use ( $claimed, $now ): void {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverStateStore::STATE_RUNNING !== $record['state'] ) {
					return;
				}

				$done                               = $record;
				$done['revision']                   = $record['revision'] + 1;
				$done['state']                      = WooPaymentsCutoverStateStore::STATE_DONE;
				$done['action_id']                  = 0;
				$done['current_step']               = self::STEP_DONE;
				$done['updated_at']                 = $now;
				$done[ self::BLOCKING_CODES_KEY ]   = array();
				$done['next_attempt_at']            = null;
				$done[ self::CLAIM_TOKEN_KEY ]      = null;
				$done[ self::CLAIM_EXPIRES_AT_KEY ] = null;
				$done['request_origin_token']       = null;
				$done                               = $this->append_step( $done, self::STEP_DONE, $now );
				$this->state_store->compare_and_set_record( $record, $done );
			}
		);
	}

	/**
	 * Tell whether a record is a generation waiting for the merchant's start.
	 *
	 * @param array<string,mixed> $record Cutover record.
	 * @return bool
	 */
	public function is_awaiting_start( array $record ): bool {
		return WooPaymentsCutoverStateStore::STATE_PENDING === $record['state'] && self::STEP_AWAITING_MERCHANT_START === ( $record['current_step'] ?? null );
	}

	/**
	 * Tell whether a record belongs to a network-wide cutover.
	 *
	 * @param array<string,mixed> $record Cutover record.
	 * @return bool
	 */
	private function is_network( array $record ): bool {
		return true === ( $record['network_cutover'] ?? false );
	}

	/**
	 * Persist a future ownership-verification attempt after plugin deactivation.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 * @return bool True when the future verification action is scheduled.
	 */
	private function schedule_ownership_verification( array $claimed, array $outcomes ): bool {
		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $claimed, $now, $outcomes ): bool {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverStateStore::STATE_RUNNING !== $record['state'] ) {
					return false;
				}

				$verification                               = $record;
				$verification['revision']                   = $record['revision'] + 1;
				$verification['state']                      = WooPaymentsCutoverStateStore::STATE_PENDING;
				$verification['action_id']                  = 0;
				$verification['current_step']               = self::STEP_VERIFY_BUILTIN_OWNERSHIP;
				$verification['updated_at']                 = $now;
				$verification[ self::BLOCKING_CODES_KEY ]   = array();
				$verification['informational_outcomes']     = $this->merge_information_outcomes( $record['informational_outcomes'], $outcomes );
				$verification['next_attempt_at']            = $now + self::FAST_RETRY_DELAY;
				$verification[ self::CLAIM_TOKEN_KEY ]      = null;
				$verification[ self::CLAIM_EXPIRES_AT_KEY ] = null;
				$verification['request_origin_token']       = self::$request_token;
				$verification                               = $this->append_step( $verification, self::STEP_VERIFY_BUILTIN_OWNERSHIP, $now );
				if ( ! $this->state_store->compare_and_set_record( $record, $verification ) ) {
					return false;
				}

				return $this->ensure_record_scheduled( $verification, $now );
			},
			false
		);
	}

	/**
	 * Persist a terminal exclusion for a claimed generation.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 * @param string              $code    Exclusion condition code.
	 */
	private function exclude( array $claimed, string $code ): void {
		$now = time();
		$this->with_write_lock(
			$now,
			function () use ( $claimed, $now, $code ): void {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverStateStore::STATE_RUNNING !== $record['state'] ) {
					return;
				}

				$excluded                               = $record;
				$excluded['revision']                   = $record['revision'] + 1;
				$excluded['state']                      = WooPaymentsCutoverStateStore::STATE_EXCLUDED;
				$excluded['action_id']                  = 0;
				$excluded['current_step']               = self::STEP_EXCLUDED;
				$excluded['updated_at']                 = $now;
				$excluded[ self::BLOCKING_CODES_KEY ]   = array( $code );
				$excluded['next_attempt_at']            = null;
				$excluded[ self::CLAIM_TOKEN_KEY ]      = null;
				$excluded[ self::CLAIM_EXPIRES_AT_KEY ] = null;
				$excluded                               = $this->append_step( $excluded, self::STEP_EXCLUDED, $now, array( 'code' => $code ) );
				if ( $this->state_store->compare_and_set_record( $record, $excluded ) ) {
					$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
				}
			}
		);
	}

	/**
	 * Fence every site in a network generation into the same terminal exclusion.
	 *
	 * @param int    $generation Excluded network generation.
	 * @param string $code       Exclusion condition code.
	 */
	private function propagate_network_exclusion( int $generation, string $code ): bool {
		// Once the extension is off network-wide the switch handed over: an exclusion would only remove owners.
		if ( ! $this->preflight_service->is_woopayments_network_active() ) {
			return true;
		}
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return false;
		}
		$current_blog_id      = get_current_blog_id();
		$network_main_site_id = get_main_site_id( get_current_network_id() );
		$site_ids             = $this->get_current_network_site_ids();
		usort(
			$site_ids,
			static function ( int $first, int $second ) use ( $current_blog_id ): int {
				return (int) ( $first === $current_blog_id ) <=> (int) ( $second === $current_blog_id );
			}
		);
		$complete = true;
		try {
			foreach ( $site_ids as $site_id ) {
				if ( $current_blog_id === $site_id && ! $complete ) {
					continue;
				}
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$site_token = $network_main_site_id === $site_id ? null : $this->state_store->acquire_lease( time() );
				try {
					if ( $network_main_site_id !== $site_id && null === $site_token ) {
						$complete = false;
						continue;
					}
					$site_complete = false;
					for ( $tries = 0; $tries < 10; ++$tries ) {
						$record = $this->state_store->get_record();
						if ( is_array( $record ) && $record['generation'] > $generation ) {
							$site_complete = true;
							break;
						}
						if ( is_array( $record ) && $generation === $record['generation'] && WooPaymentsCutoverStateStore::STATE_EXCLUDED === $record['state'] ) {
							$site_complete = true;
							break;
						}
						// A site that handed over (its token, its ownership check, or done) is not excluded.
						if ( $this->has_network_handoff( $record, $generation ) || ( is_array( $record ) && WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] ) ) {
							$site_complete = true;
							break;
						}
						$now                                    = time();
						$excluded                               = is_array( $record ) && $generation === $record['generation'] ? $record : $this->create_pending_record( self::SOURCE_NETWORK_EXCLUSION_REPAIR, $now );
						$excluded['generation']                 = $generation;
						$excluded['revision']                   = is_array( $record ) ? $record['revision'] + 1 : 1;
						$excluded['state']                      = WooPaymentsCutoverStateStore::STATE_EXCLUDED;
						$excluded['action_id']                  = 0;
						$excluded['current_step']               = self::STEP_EXCLUDED;
						$excluded['updated_at']                 = $now;
						$excluded[ self::BLOCKING_CODES_KEY ]   = array( $code );
						$excluded['next_attempt_at']            = null;
						$excluded[ self::CLAIM_TOKEN_KEY ]      = null;
						$excluded[ self::CLAIM_EXPIRES_AT_KEY ] = null;
						$excluded['request_origin_token']       = null;
						$excluded['network_cutover']            = true;
						$excluded                               = $this->append_step( $excluded, self::STEP_EXCLUDED, $now, array( 'code' => $code ) );
						$stored                                 = is_array( $record ) ? $this->state_store->compare_and_set_record( $record, $excluded ) : $this->state_store->create_record( $excluded );
						if ( $stored ) {
							$this->scheduler->cancel( is_array( $record ) ? $record['generation'] : $generation, is_array( $record ) ? $record['attempt'] + 1 : 1 );
							$site_complete = true;
							break;
						}
					}
					$complete = $site_complete && $complete;
				} finally {
					if ( is_string( $site_token ) ) {
						$this->state_store->release_lease( $site_token );
					}
					if ( get_current_blog_id() !== $current_blog_id ) {
						restore_current_blog();
					}
				}
			}
		} finally {
			$this->release_network_lease( $network_token );
		}

		return $complete;
	}

	/**
	 * Turn an awaiting source site into durable retry work before network exclusion propagation.
	 *
	 * @param array<string,mixed> $record Current awaiting source record.
	 * @param string              $code   Exclusion condition code.
	 * @return bool True when convergence is already durable or was just scheduled.
	 */
	private function schedule_network_exclusion_convergence( array $record, string $code ): bool {
		$now = time();
		return $this->with_write_lock(
			$now,
			function () use ( $record, $now, $code ): bool {
				$current = $this->state_store->get_record();
				if ( ! is_array( $current ) || $current !== $record ) {
					return is_array( $current ) && ! $this->is_awaiting_start( $current );
				}

				$deferred                               = $current;
				$deferred['revision']                   = $current['revision'] + 1;
				$deferred['state']                      = WooPaymentsCutoverStateStore::STATE_DEFERRED;
				$deferred['action_id']                  = 0;
				$deferred['current_step']               = self::STEP_NETWORK_EXCLUSION_PROPAGATION;
				$deferred['updated_at']                 = $now;
				$deferred[ self::BLOCKING_CODES_KEY ]   = array( 'network_exclusion_propagation_pending' );
				$deferred['next_attempt_at']            = $now;
				$deferred[ self::CLAIM_TOKEN_KEY ]      = null;
				$deferred[ self::CLAIM_EXPIRES_AT_KEY ] = null;
				$deferred                               = $this->append_step( $deferred, self::STEP_NETWORK_EXCLUSION_PROPAGATION, $now, array( 'code' => $code ) );
				if ( ! $this->state_store->compare_and_set_record( $current, $deferred ) ) {
					return false;
				}

				$this->ensure_record_scheduled( $deferred, $now );
				return true;
			},
			false
		);
	}

	/**
	 * Record the queued WooPayments actions the cutover carries over; their callbacks run after the cutover.
	 *
	 * @return array<int,mixed> Informational operational-action outcomes.
	 */
	private function get_adopted_queue_action_outcomes(): array {
		$outcomes = array();
		foreach ( $this->preflight_service->get_queued_operational_actions() as $action ) {
			if ( WooPaymentsCutoverActionScheduler::ACTION_HOOK === $action['hook'] && WooPaymentsCutoverActionScheduler::ACTION_GROUP === $action['group'] ) {
				continue;
			}
			if ( in_array( $action['hook'], self::ADOPTED_QUEUE_HOOKS, true ) ) {
				$outcomes[] = array(
					'code' => 'operational_action_adopted',
					'hook' => $action['hook'],
				);
			}
		}

		return $outcomes;
	}

	/**
	 * Update the resolved active WooPayments plugin with WordPress core APIs.
	 *
	 * Runs only where the site allows automatic file changes: the upgrader classes do not check DISALLOW_FILE_MODS or the
	 * `file_mod_allowed` filter, so this asks the same question core's background updater asks first.
	 *
	 * @return bool True only when WordPress reports a completed plugin upgrade.
	 */
	private function update_woopayments_plugin(): bool {
		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			$this->log_error( 'WooPayments cutover cannot update the WooPayments plugin because this site does not allow file changes.' );
			return false;
		}

		$plugin_file = $this->preflight_service->get_active_woopayments_plugin_file();
		if ( '' === $plugin_file ) {
			$this->log_error( 'WooPayments cutover could not resolve the active plugin file for updating.' );
			return false;
		}

		try {
			// The same two files core's own background updater loads before upgrading
			// (`wp_maybe_auto_update()`, `wp-includes/update.php`). `wp_update_plugins()` living in
			// `wp-includes/update.php` made the old `function_exists( 'wp_update_plugins' )` guard always
			// true, so a WP-Cron retry never loaded `file.php`/`plugin.php` and every such attempt threw.
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$switched = $this->switch_to_plugin_update_lock_blog();
			if ( null === $switched ) {
				$this->log_error( 'WooPayments cutover could not resolve the network main site for the shared plugin update lock.' );
				return false;
			}

			$lock_created = false;
			try {
				$lock_created = \WP_Upgrader::create_lock( self::PLUGIN_UPDATE_LOCK_NAME, self::PLUGIN_UPDATE_LOCK_TTL );
				if ( ! $lock_created ) {
					$this->log_error( 'WooPayments cutover deferred because another site is updating the shared plugin files.' );
					return false;
				}

				$this->refresh_plugin_update_metadata();
				$result = $this->upgrade_woopayments_plugin_file( $plugin_file );
				if ( ! is_array( $result ) ) {
					$this->log_error( 'WooPayments cutover plugin update did not complete.', array( 'plugin_file' => $plugin_file ) );
					return false;
				}
				return true;
			} finally {
				try {
					if ( $lock_created ) {
						\WP_Upgrader::release_lock( self::PLUGIN_UPDATE_LOCK_NAME );
					}
				} finally {
					$this->restore_plugin_update_lock_blog( $switched );
				}
			}
		} catch ( \Throwable $error ) {
			$this->log_error(
				'WooPayments cutover plugin update failed.',
				array(
					'plugin_file' => $plugin_file,
					'error'       => $error->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Refresh WordPress plugin update metadata before upgrading WooPayments.
	 */
	protected function refresh_plugin_update_metadata(): void {
		wp_update_plugins();
	}

	/**
	 * Upgrade one resolved WooPayments plugin file with WordPress core.
	 *
	 * Uses `bulk_upgrade()`, core's own AJAX "Update now" path. Unlike `Plugin_Upgrader::upgrade()`, it
	 * never deactivates the active plugin outside WP-Cron (it uses maintenance mode instead), so the
	 * plugin keeps owning payments through a deferred update instead of being silently deactivated with
	 * nothing to reactivate it.
	 *
	 * @param string $plugin_file Active WooPayments plugin file.
	 * @return mixed WordPress upgrader result for this plugin file (an array on a completed install).
	 */
	protected function upgrade_woopayments_plugin_file( string $plugin_file ) {
		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$results  = $upgrader->bulk_upgrade( array( $plugin_file ) );
		return is_array( $results ) ? ( $results[ $plugin_file ] ?? false ) : false;
	}

	/**
	 * Tell whether the installed WooPayments plugin files carry a cutover-supported version.
	 *
	 * @return bool
	 */
	private function is_installed_woopayments_plugin_version_supported(): bool {
		$plugin_file = $this->preflight_service->get_active_woopayments_plugin_file();
		$version     = '' === $plugin_file ? '' : $this->get_installed_plugin_version( $plugin_file );

		return '' !== $version && version_compare( $version, WooPaymentsCutoverPreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION, '>=' );
	}

	/**
	 * Read the header version of an installed plugin file.
	 *
	 * @since 11.2.0
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return string Header version, or an empty string when unreadable.
	 */
	protected function get_installed_plugin_version( string $plugin_file ): string {
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_readable( $plugin_path ) ) {
			return '';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( $plugin_path, false, false );

		return is_string( $plugin_data['Version'] ?? null ) ? trim( $plugin_data['Version'] ) : '';
	}

	/**
	 * Switch to the current network's main site for unique-option lock operations.
	 *
	 * @return bool|null Whether the current blog must be restored afterward, or null when no main site is available.
	 */
	private function switch_to_plugin_update_lock_blog(): ?bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$main_site_id = get_main_site_id( get_current_network_id() );
		if ( $main_site_id < 1 ) {
			return null;
		}
		if ( get_current_blog_id() === $main_site_id ) {
			return false;
		}

		switch_to_blog( $main_site_id );
		return true;
	}

	/**
	 * Run work while holding the cutover state store's write lock.
	 *
	 * Takes the lock, calls $work with the lock token and releases the lock afterwards, also when $work throws. While another
	 * request holds the lock, returns $when_busy without calling $work. Every read, check, write and in-lock effect stays in $work.
	 *
	 * @param int      $now       Current UTC timestamp.
	 * @param callable $work      Work to run with the lock held; receives the lock token.
	 * @param mixed    $when_busy Result when another request holds the lock.
	 * @return mixed The result of $work, or $when_busy.
	 */
	private function with_write_lock( int $now, callable $work, $when_busy = null ) {
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return $when_busy;
		}

		try {
			return $work( $token );
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Acquire the existing atomic state-store lease on the network main site.
	 *
	 * @param int $now Current UTC timestamp.
	 * @return string|null Lease token, or null while another network operation owns it.
	 */
	private function acquire_network_lease( int $now ): ?string {
		$switched = $this->switch_to_plugin_update_lock_blog();
		if ( null === $switched ) {
			return null;
		}
		try {
			return $this->state_store->acquire_lease( $now );
		} finally {
			$this->restore_plugin_update_lock_blog( $switched );
		}
	}

	/**
	 * Release a network lease on the same main-site option that granted it.
	 *
	 * @param string $token Lease owner token.
	 */
	private function release_network_lease( string $token ): void {
		$switched = $this->switch_to_plugin_update_lock_blog();
		if ( null === $switched ) {
			return;
		}
		try {
			$this->state_store->release_lease( $token );
		} finally {
			$this->restore_plugin_update_lock_blog( $switched );
		}
	}

	/**
	 * Restore a calling blog after a main-site plugin-lock operation.
	 *
	 * @param bool $switched Whether this worker switched to the main site.
	 */
	private function restore_plugin_update_lock_blog( bool $switched ): void {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Log one cutover error with its stable source.
	 *
	 * @param string              $message Error message.
	 * @param array<string,mixed> $context Additional diagnostic context.
	 */
	private function log_error( string $message, array $context = array() ): void {
		$this->write_log_error( $message, array_merge( $context, array( 'source' => self::LOG_SOURCE ) ) );
	}

	/**
	 * Warn when a switch's first attempt runs past the fast retry window, so day-late switches are visible in the logs.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function log_late_first_attempt( array $claimed ): void {
		$delay_seconds = time() - (int) $claimed['started_at'];
		if ( 1 !== $claimed['attempt'] || $delay_seconds < self::FAST_RETRY_WINDOW ) {
			return;
		}

		$this->write_log_warning(
			sprintf( 'WooPayments cutover: generation %1$d proceeds with its first attempt %2$ds after the merchant started it', (int) $claimed['generation'], $delay_seconds ),
			array(
				'source'        => self::LOG_SOURCE,
				'generation'    => (int) $claimed['generation'],
				'delay_seconds' => $delay_seconds,
			)
		);
	}

	/**
	 * Write a cutover warning to the WooCommerce logger.
	 *
	 * @param string              $message Warning message.
	 * @param array<string,mixed> $context Warning context.
	 */
	protected function write_log_warning( string $message, array $context ): void {
		wc_get_logger()->warning( $message, $context );
	}

	/**
	 * Write a normalized cutover error to the WooCommerce logger.
	 *
	 * @param string              $message Error message.
	 * @param array<string,mixed> $context Normalized error context.
	 */
	protected function write_log_error( string $message, array $context ): void {
		wc_get_logger()->error( $message, $context );
	}

	/**
	 * Repair missing actions and stale running records once Action Scheduler is initialized.
	 *
	 * @internal
	 */
	public function repair_schedule(): void {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return;
		}

		$now    = time();
		$record = $this->state_store->get_record();
		// A done network record beside an active extension is a rollback that missed this site (its lock was busy, or the
		// activation's request had the feature off): it reopens here, so a later deactivation by the merchant never switches it alone.
		if ( is_array( $record ) && $this->is_network( $record ) && WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] && $this->arbiter->is_extension_owner() ) {
			$this->with_write_lock(
				$now,
				function () use ( $record, $now ): void {
					if ( $this->state_store->get_record() === $record ) {
						$this->replace_terminal_with_awaiting_start( $record, self::SOURCE_ROLLBACK, $now, $this->preflight_service->is_woopayments_network_active() );
					}
				}
			);
			return;
		}
		if (
			! is_array( $record )
			|| $this->is_awaiting_start( $record )
			|| (
				! in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true )
				&& ( WooPaymentsCutoverStateStore::STATE_RUNNING !== $record['state'] || $record[ self::CLAIM_EXPIRES_AT_KEY ] > $now )
			)
		) {
			return;
		}

		// This runs on every request, so a waiting attempt that is still scheduled is read, never leased.
		if ( WooPaymentsCutoverStateStore::STATE_RUNNING !== $record['state'] && $this->is_recorded_attempt_scheduled( $record ) ) {
			return;
		}

		$this->with_write_lock(
			$now,
			function () use ( $record, $now ): void {
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) ) {
					return;
				}

				if ( WooPaymentsCutoverStateStore::STATE_RUNNING === $record['state'] ) {
					if ( $record[ self::CLAIM_EXPIRES_AT_KEY ] > $now ) {
						return;
					}

					$recovered                               = $record;
					$recovered['revision']                   = $record['revision'] + 1;
					$recovered['state']                      = WooPaymentsCutoverStateStore::STATE_DEFERRED;
					$recovered['action_id']                  = 0;
					$recovered['current_step']               = self::STEP_RECOVERED_STALE_RUNNING;
					$recovered['updated_at']                 = $now;
					$recovered['next_attempt_at']            = $now;
					$recovered[ self::CLAIM_TOKEN_KEY ]      = null;
					$recovered[ self::CLAIM_EXPIRES_AT_KEY ] = null;
					$recovered                               = $this->append_step( $recovered, self::STEP_RECOVERED_STALE_RUNNING, $now );
					if ( ! $this->state_store->compare_and_set_record( $record, $recovered ) ) {
						return;
					}
					$record = $recovered;
				}

				if ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) && self::STEP_AWAITING_MERCHANT_START !== $record['current_step'] ) {
					$this->ensure_record_scheduled( $record, $now );
				}
			}
		);
	}

	/**
	 * Tell whether the record's next attempt is scheduled under the action id the record already holds.
	 *
	 * @param array<string,mixed> $record Current record.
	 * @return bool
	 */
	private function is_recorded_attempt_scheduled( array $record ): bool {
		return $record['action_id'] > 0 && $record['action_id'] === $this->scheduler->get_scheduled_action_id( $record['generation'], $record['attempt'] + 1 );
	}

	/**
	 * Tell whether a site's record already belongs to the network generation and needs no fan-out repair.
	 *
	 * @param array<string,mixed>|null $record     Site record, or null when none exists.
	 * @param int                      $generation Network generation being repaired.
	 * @return bool
	 */
	private function is_settled_network_peer( ?array $record, int $generation ): bool {
		if ( ! is_array( $record ) || $record['generation'] !== $generation || ! $this->is_network( $record ) ) {
			return false;
		}
		if ( ! in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) ) {
			return true;
		}

		return self::STEP_AWAITING_MERCHANT_START !== $record['current_step'] && $this->is_recorded_attempt_scheduled( $record );
	}

	/**
	 * Open a new generation after rollback or removal of a local exclusion marker.
	 *
	 * @param array<string,mixed> $record Current terminal record.
	 * @param string              $source Trigger source.
	 * @param int                 $now    Current UTC timestamp.
	 * @return bool True when the new generation was stored and scheduled.
	 */
	private function replace_terminal_with_pending( array $record, string $source, int $now ): bool {
		$replacement                           = $this->create_pending_record( $source, $now );
		$replacement['generation']             = $record['generation'] + 1;
		$replacement['revision']               = $record['revision'] + 1;
		$replacement['informational_outcomes'] = array();
		if ( ! $this->state_store->compare_and_set_record( $record, $replacement ) ) {
			return false;
		}

		return $this->ensure_record_scheduled( $replacement, $now );
	}

	/**
	 * Open a superseding generation without scheduling it before merchant confirmation.
	 *
	 * @param array<string,mixed> $record  Current record it supersedes.
	 * @param string              $source  Supersession source.
	 * @param int                 $now     Current UTC timestamp.
	 * @param bool|null           $network Whether the awaiting generation is a network one; null keeps the record's scope.
	 * @return bool True when the awaiting generation was stored.
	 */
	private function replace_terminal_with_awaiting_start( array $record, string $source, int $now, ?bool $network = null ): bool {
		$replacement             = $this->to_awaiting_merchant_start( $source, $now, $record['generation'] + 1, $network ?? $this->is_network( $record ) );
		$replacement['revision'] = $record['revision'] + 1;

		return $this->state_store->compare_and_set_record( $record, $replacement );
	}

	/**
	 * Schedule an awaiting generation only after an explicit merchant or mandatory start.
	 *
	 * @param array<string,mixed> $record Current awaiting record.
	 * @param string              $source Start source.
	 * @param int                 $now    Current UTC timestamp.
	 * @return bool True when the generation was queued.
	 */
	private function start_awaiting_generation( array $record, string $source, int $now ): bool {
		$queued                 = $record;
		$queued['revision']     = $record['revision'] + 1;
		$queued['current_step'] = self::STEP_QUEUED;
		// The retry cadence counts from the merchant's start, not from when the offer was created.
		$queued['started_at'] = $now;
		$queued['updated_at'] = $now;
		$queued               = $this->append_step( $queued, self::STEP_QUEUED, $now, array( 'source' => $source ) );
		if ( ! $this->state_store->compare_and_set_record( $record, $queued ) ) {
			return false;
		}

		return $this->ensure_record_scheduled( $queued, $now );
	}

	/**
	 * Tell whether a terminal record is superseded by current local facts.
	 *
	 * @param array<string,mixed> $record           Current terminal record.
	 * @param bool                $for_admin_notice Whether an admin notice asks, which may use the cached classification.
	 * @return bool
	 */
	private function can_reopen_terminal_record( array $record, bool $for_admin_notice = false ): bool {
		if ( WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] ) {
			try {
				return $this->arbiter->is_extension_owner();
			} catch ( \Throwable $error ) {
				return false;
			}
		}
		if ( WooPaymentsCutoverStateStore::STATE_EXCLUDED !== $record['state'] ) {
			return false;
		}

		if ( $for_admin_notice ) {
			return false === $this->get_admin_stripe_billing_classification( $record );
		}
		if ( $this->is_network( $record ) && is_multisite() ) {
			return ! $this->network_has_stripe_billing_failure();
		}

		try {
			$failures = $this->normalize_codes( $this->preflight_service->get_failures() );
		} catch ( \Throwable $error ) {
			return false;
		}

		return ! in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $failures, true );
	}

	/**
	 * Tell whether the record's site or network currently has a Stripe Billing exclusion marker.
	 *
	 * @param array<string,mixed> $record Current record.
	 * @return bool
	 */
	private function record_has_stripe_billing_failure( array $record ): bool {
		return true === $this->get_admin_stripe_billing_classification( $record );
	}

	/**
	 * Classify the Stripe Billing marker for admin notices, reusing the result for one record revision and an hour.
	 *
	 * The full preflight scans Action Scheduler and order meta, and a network record scans it on every site, so it must not
	 * run on every admin page. The worker re-checks before it acts.
	 *
	 * @param array<string,mixed>|null $record Current record, or null when none exists.
	 * @return bool|null Whether the marker is present, or null when the preflight failed.
	 */
	private function get_admin_stripe_billing_classification( ?array $record ): ?bool {
		$network = is_array( $record ) && $this->is_network( $record ) && is_multisite();
		$key     = is_array( $record ) ? $record['generation'] . ':' . $record['revision'] . ( $network ? ':network' : '' ) : 'none';
		$cached  = get_option( self::ADMIN_CLASSIFICATION_OPTION, null );
		if ( is_array( $cached ) && ( $cached['key'] ?? null ) === $key && is_bool( $cached['present'] ?? null ) && time() < (int) ( $cached['expires_at'] ?? 0 ) ) {
			return $cached['present'];
		}

		try {
			$present = $network
				? $this->network_has_stripe_billing_failure()
				: in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $this->normalize_codes( $this->preflight_service->get_failures() ), true );
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover could not classify the admin notice.', array( 'error' => $error->getMessage() ) );
			return null;
		}
		update_option(
			self::ADMIN_CLASSIFICATION_OPTION,
			array(
				'key'        => $key,
				'present'    => $present,
				'expires_at' => time() + HOUR_IN_SECONDS,
			),
			true
		);

		return $present;
	}

	/**
	 * Replace an unscheduled awaiting record with a local terminal exclusion.
	 *
	 * @param array<string,mixed> $record Current awaiting record.
	 * @param string              $code   Exclusion code.
	 */
	private function exclude_awaiting_record( array $record, string $code ): void {
		$now = time();
		$this->with_write_lock(
			$now,
			function () use ( $record, $now, $code ): void {
				$current = $this->state_store->get_record();
				if ( ! is_array( $current ) || $current !== $record ) {
					return;
				}
				$excluded                               = $current;
				$excluded['revision']                   = $current['revision'] + 1;
				$excluded['state']                      = WooPaymentsCutoverStateStore::STATE_EXCLUDED;
				$excluded['current_step']               = self::STEP_EXCLUDED;
				$excluded['updated_at']                 = $now;
				$excluded[ self::BLOCKING_CODES_KEY ]   = array( $code );
				$excluded['next_attempt_at']            = null;
				$excluded[ self::CLAIM_TOKEN_KEY ]      = null;
				$excluded[ self::CLAIM_EXPIRES_AT_KEY ] = null;
				$excluded                               = $this->append_step( $excluded, self::STEP_EXCLUDED, $now, array( 'code' => $code ) );
				$this->state_store->compare_and_set_record( $current, $excluded );
			}
		);
	}

	/**
	 * Tell whether any site in the current network still has a Stripe Billing marker.
	 *
	 * @return bool
	 */
	private function network_has_stripe_billing_failure(): bool {
		$current_blog_id = get_current_blog_id();
		foreach ( $this->get_current_network_site_ids() as $site_id ) {
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			try {
				$this->preflight_service->invalidate_current_blog_memoization();
				$failures = $this->normalize_codes( $this->preflight_service->get_failures() );
				if ( in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $failures, true ) ) {
					return true;
				}
			} catch ( \Throwable $error ) {
				return true;
			} finally {
				if ( get_current_blog_id() !== $current_blog_id ) {
					restore_current_blog();
				}
			}
		}

		return false;
	}

	/**
	 * Build a new pending record.
	 *
	 * @param string $source Trigger source.
	 * @param int    $now    Current UTC timestamp.
	 * @return array<string,mixed>
	 */
	private function create_pending_record( string $source, int $now ): array {
		return array(
			'schema_version'           => WooPaymentsCutoverStateStore::SCHEMA_VERSION,
			'generation'               => $this->state_store->get_next_generation(),
			'revision'                 => 1,
			'state'                    => WooPaymentsCutoverStateStore::STATE_PENDING,
			'started_at'               => $now,
			'updated_at'               => $now,
			'attempt'                  => 0,
			'action_id'                => 0,
			'current_step'             => self::STEP_QUEUED,
			'step_log'                 => array(
				array(
					'step'    => self::STEP_QUEUED,
					'at'      => $now,
					'context' => array( 'source' => $source ),
				),
			),
			self::BLOCKING_CODES_KEY   => array(),
			'informational_outcomes'   => array(),
			'next_attempt_at'          => null,
			self::CLAIM_TOKEN_KEY      => null,
			self::CLAIM_EXPIRES_AT_KEY => null,
			'request_origin_token'     => null,
			'network_cutover'          => false,
		);
	}

	/**
	 * Build a generation record that waits for the merchant's start, with no attempt scheduled.
	 *
	 * The callers set its revision against the record it replaces, if any.
	 *
	 * @param string $source     Source recorded in its first step.
	 * @param int    $now        Current UTC timestamp.
	 * @param int    $generation Generation it opens.
	 * @param bool   $network    Whether it belongs to a network-wide cutover.
	 * @return array<string,mixed>
	 */
	private function to_awaiting_merchant_start( string $source, int $now, int $generation, bool $network ): array {
		$awaiting                    = $this->create_pending_record( $source, $now );
		$awaiting['generation']      = $generation;
		$awaiting['network_cutover'] = $network;
		$awaiting['current_step']    = self::STEP_AWAITING_MERCHANT_START;
		$awaiting['step_log']        = array(
			array(
				'step'    => self::STEP_AWAITING_MERCHANT_START,
				'at'      => $now,
				'context' => array( 'source' => $source ),
			),
		);

		return $awaiting;
	}

	/**
	 * Ensure the next monotonic attempt has exactly one action.
	 *
	 * @param array<string,mixed> $record Durable record.
	 * @param int                 $now    Current UTC timestamp.
	 * @return bool True when an action is scheduled.
	 */
	private function ensure_record_scheduled( array $record, int $now ): bool {
		$attempt   = $record['attempt'] + 1;
		$action_id = $this->scheduler->get_scheduled_action_id( $record['generation'], $attempt );

		if ( 0 === $action_id ) {
			$timestamp = is_int( $record['next_attempt_at'] ) ? max( $now, $record['next_attempt_at'] ) : $now;
			$action_id = $this->scheduler->schedule( $timestamp, $record['generation'], $attempt );
		}

		if ( $action_id < 1 ) {
			return false;
		}

		if ( $action_id !== $record['action_id'] ) {
			$scheduled               = $record;
			$scheduled['revision']   = $record['revision'] + 1;
			$scheduled['action_id']  = $action_id;
			$scheduled['updated_at'] = $now;
			if ( ! $this->state_store->compare_and_set_record( $record, $scheduled ) ) {
				$this->scheduler->cancel( $record['generation'], $attempt );
				return false;
			}
		}

		return true;
	}

	/**
	 * Append one bounded diagnostic step.
	 *
	 * @param array<string,mixed> $record  Durable record.
	 * @param string              $step    Step name.
	 * @param int                 $now     Current UTC timestamp.
	 * @param array<string,mixed> $context Optional diagnostic context.
	 * @return array<string,mixed>
	 */
	private function append_step( array $record, string $step, int $now, array $context = array() ): array {
		$entry = array(
			'step' => $step,
			'at'   => $now,
		);
		if ( ! empty( $context ) ) {
			$entry['context'] = $context;
		}

		$record['step_log'][] = $entry;
		$record['step_log']   = array_slice( $record['step_log'], -WooPaymentsCutoverStateStore::MAX_STEP_LOG_ENTRIES );

		return $record;
	}

	/**
	 * Normalize condition codes before persistence.
	 *
	 * @param array<int,string> $codes Condition codes.
	 * @return array<int,string>
	 */
	private function normalize_codes( array $codes ): array {
		return array_values( array_unique( array_filter( $codes, 'is_string' ) ) );
	}

	/**
	 * Merge informational outcomes without duplicating a persisted observation.
	 *
	 * @param array<int,mixed> $existing Existing informational outcomes.
	 * @param array<int,mixed> $additional Outcomes to append.
	 * @return array<int,mixed>
	 */
	private function merge_information_outcomes( array $existing, array $additional ): array {
		foreach ( $additional as $outcome ) {
			if ( ! $this->has_information_outcome( $existing, $outcome ) ) {
				$existing[] = $outcome;
			}
		}

		return $existing;
	}

	/**
	 * Tell whether an informational outcome has already been persisted.
	 *
	 * @param array<int,mixed> $outcomes Existing informational outcomes.
	 * @param mixed            $candidate Candidate outcome.
	 * @return bool
	 */
	private function has_information_outcome( array $outcomes, $candidate ): bool {
		foreach ( $outcomes as $outcome ) {
			if ( $outcome === $candidate ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize one Action Scheduler identity value from an untrusted hook.
	 *
	 * @param mixed $value Hook value.
	 * @return int|null Positive integer, or null for malformed input.
	 */
	private function normalize_action_identity_value( $value ): ?int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}

		$normalized = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );

		return false === $normalized ? null : $normalized;
	}

	/**
	 * Calculate retry delay from the original job start.
	 *
	 * @param array<string,mixed> $record Durable record.
	 * @param int                 $now    Current UTC timestamp.
	 * @return int
	 */
	private function get_retry_delay( array $record, int $now ): int {
		return $now - $record['started_at'] < self::FAST_RETRY_WINDOW ? self::FAST_RETRY_DELAY : self::SLOW_RETRY_DELAY;
	}
}
