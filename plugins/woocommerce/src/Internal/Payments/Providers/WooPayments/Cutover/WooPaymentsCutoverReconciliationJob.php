<?php
/**
 * WooPaymentsCutoverReconciliationJob class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountMilestonesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStoreSetupReportController;
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

	/** Option holding the time until which the switch notice outside Payments settings stays hidden ("Not now"). */
	public const START_NOTICE_SNOOZED_UNTIL_OPTION = 'woocommerce_woopayments_cutover_notice_snoozed_until';

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

	/** Source of the merchant's start. */
	private const SOURCE_MERCHANT = 'merchant';

	/** Source of a generation opened by the WooPayments extension being activated again. */
	private const SOURCE_ROLLBACK = 'rollback';

	/** Source of a site excluded on its own. */
	private const SOURCE_LOCAL_EXCLUSION = 'local_exclusion';

	/** Record key of the claiming worker's token; the stored name keeps "lease". */
	private const CLAIM_TOKEN_KEY = 'lease_token';

	/** Record key of the time a claim may be recovered; the stored name keeps "lease". */
	private const CLAIM_EXPIRES_AT_KEY = 'lease_expires_at';

	/** Record key of the codes that block or closed the generation; the stored name keeps "deferred". */
	private const BLOCKING_CODES_KEY = 'deferred_codes';

	/** Condition code of a data migration that failed; the stored code keeps its first name. */
	private const CODE_DATA_MIGRATION_FAILED = 'normalization_failed';

	/** Condition code of a finalize that waits because the WooPayments extension is active for every site. */
	public const CODE_PLUGIN_ACTIVE_SITEWIDE = 'plugin_active_sitewide';

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
	 * Cutover data migration.
	 *
	 * @var WooPaymentsCutoverDataMigration
	 */
	private WooPaymentsCutoverDataMigration $data_migration;

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
	 * @param WooPaymentsRuntimeArbiter          $arbiter          Runtime owner arbiter.
	 * @param WooPaymentsCutoverStateStore       $state_store      Persisted state store.
	 * @param WooPaymentsCutoverActionScheduler  $scheduler        Action Scheduler adapter.
	 * @param WooPaymentsCutoverPreflightService $preflight_service   Headless cutover facts.
	 * @param WooPaymentsCutoverDataMigration    $data_migration Cutover data migration.
	 * @param WooPaymentsAccountService          $account_service     WooPayments account state.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsCutoverStateStore $state_store, WooPaymentsCutoverActionScheduler $scheduler, WooPaymentsCutoverPreflightService $preflight_service, WooPaymentsCutoverDataMigration $data_migration, WooPaymentsAccountService $account_service ): void {
		$this->arbiter           = $arbiter;
		$this->state_store       = $state_store;
		$this->scheduler         = $scheduler;
		$this->preflight_service = $preflight_service;
		$this->data_migration    = $data_migration;
		$this->account_service   = $account_service;
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
	 * Run a callback that activates or deactivates plugins, marked as a change this job initiated.
	 *
	 * The plugin lifecycle observers ignore the changes made inside the callback, as they do the job's own deactivation of
	 * the extension. The previous mark is restored when the callback returns or throws.
	 *
	 * @param callable $callback Callback that changes plugins.
	 * @return mixed What the callback returns.
	 */
	public function run_while_changing_plugins( callable $callback ) {
		$previous                               = $this->internal_plugin_lifecycle_change;
		$this->internal_plugin_lifecycle_change = true;
		try {
			return $callback();
		} finally {
			$this->internal_plugin_lifecycle_change = $previous;
		}
	}

	/**
	 * Start one reconciliation generation on this site.
	 *
	 * Nothing starts while the WooPayments extension is active sitewide: a switch deactivates the extension on its own site
	 * only, so a super admin first activates WooPayments per site.
	 *
	 * @param string $source Trigger source.
	 * @return bool True when durable work already exists or was scheduled.
	 */
	private function start_generation( string $source ): bool {
		if ( ! $this->arbiter->is_builtin_enabled() || ( is_multisite() && $this->preflight_service->is_woopayments_network_active() ) ) {
			return false;
		}

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
	 * A completed switch is replaced by a new generation awaiting the merchant's start. An open one that is not awaiting it,
	 * a claim running in another request included, is rolled back (roll_back_open_generation()); a running worker's
	 * finalize fence then refuses to deactivate the extension.
	 *
	 * @return bool True when the rollback was already recorded or a new generation was opened.
	 */
	public function record_plugin_activation(): bool {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return false;
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
				if ( WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] ) {
					return $this->replace_terminal_with_awaiting_start( $record, self::SOURCE_ROLLBACK, $now );
				}
				// The hook fires only for an inactive extension, so an open merchant start sees this only after finalize
				// deactivated the extension.
				if ( ! in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED, WooPaymentsCutoverStateStore::STATE_RUNNING ), true ) ) {
					return false;
				}

				return $this->roll_back_open_generation( $record, $now );
			},
			false
		);
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
			if ( $this->is_awaiting_start( $record ) && $this->refresh_has_bundled_subscriptions_marker( $record ) ) {
				$this->exclude_awaiting_record( $record, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
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

		if ( true !== $this->refresh_admin_classification( null ) ) {
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
			return ! $this->refresh_has_bundled_subscriptions_marker( $record );
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
		// Checked before the write lock too: admin pages call this, and taking the write lock writes options.
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
				$this->complete_verified_claim( $claimed );
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
			if ( null !== ( $claimed['request_origin_token'] ?? null ) && $this->arbiter->is_extension_owner() ) {
				$this->roll_back_claim( $claimed );
				return;
			}
			$this->log_late_first_attempt( $claimed );
			// Past finalize the extension is already off and the built-in WooPayments owns payments, so an exclusion would
			// protect nothing and leave the store with no owner: the bundled failure defers the claim instead.
			$past_finalize = null !== ( $claimed['request_origin_token'] ?? null ) && ! $this->arbiter->is_extension_owner();
			$failures      = $this->normalize_codes( $this->preflight_service->get_failures() );
			if ( ! $past_finalize && in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $failures, true ) ) {
				$this->exclude( $claimed, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
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
				$this->exclude( $claimed, WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS );
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

			$this->finalize_site_claim( $claimed, $outcomes );
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover reconciliation resolver failed.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'reconciliation_resolver_failed' ) );
		}
	}

	/**
	 * Normalize one clear site and schedule ownership verification for a later request.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 */
	private function finalize_site_claim( array $claimed, array $outcomes ): void {
		// Deactivating an extension that is active sitewide would take it from every site, leaving the sites that still run it
		// with no payments, so the switch waits until a super admin activates WooPayments per site.
		if ( $this->preflight_service->is_woopayments_network_active() ) {
			$this->defer( $claimed, array( self::CODE_PLUGIN_ACTIVE_SITEWIDE ), $outcomes );
			return;
		}
		$claimed = $this->migrate_data_for_claim( $claimed, $outcomes );
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
			if ( $this->preflight_service->is_woopayments_site_active() ) {
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
				$deactivated = ! $this->preflight_service->is_woopayments_site_active();
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
	 * @param array<string,mixed> $record Current open record.
	 * @param int                 $now    Current UTC timestamp.
	 * @return bool True when the rollback generation was stored.
	 */
	private function roll_back_open_generation( array $record, int $now ): bool {
		if ( ! $this->replace_terminal_with_awaiting_start( $record, self::SOURCE_ROLLBACK, $now ) ) {
			return false;
		}
		$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
		$this->account_service->sync_setup_tier_from_options( true );

		return true;
	}

	/**
	 * Run the data migration for a claim about to finalize.
	 *
	 * A migration that throws, or that could not persist the gateway settings, defers the claim.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 * @return array<string,mixed>|null The claim, or null after a deferred result.
	 */
	private function migrate_data_for_claim( array $claimed, array $outcomes ): ?array {
		try {
			$result = $this->data_migration->run();
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
	 * Complete one verification claim once a fresh request shows that the built-in WooPayments owns the site: fence the claim,
	 * sync the setup tier, schedule the fee remediation and record the switch as done.
	 *
	 * An extension that still owns the site, or an ownership check that throws, defers the claim.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function complete_verified_claim( array $claimed ): void {
		try {
			$extension_owns = $this->arbiter->is_extension_owner();
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover could not verify native ownership.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'builtin_ownership_verification_failed' ) );
			return;
		}
		if ( $extension_owns ) {
			$this->defer( $claimed, array( 'builtin_ownership_unverified' ) );
			return;
		}
		$claimed = $this->fence_finalizing_claim( $claimed );
		if ( null === $claimed ) {
			return;
		}
		$this->account_service->sync_setup_tier_from_options( false );
		// Every finalized claim reaches this step once the built-in WooPayments owns its site.
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
				if ( $this->state_store->compare_and_set_record( $record, $done ) ) {
					delete_option( self::START_NOTICE_SNOOZED_UNTIL_OPTION );
				}
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
	 * Tell whether this site's switch has started and not reached finalize: its record is pending past the merchant's start,
	 * running or deferred, and carries no request token.
	 *
	 * This generation has no persisted finalize token; the conversion keeps the merchant's existing start. A start that
	 * meets the network activation defers before it migrates (plugin_active_sitewide). Read-only.
	 *
	 * @return bool
	 */
	public function has_started_switch_before_finalize(): bool {
		$record = $this->state_store->get_record();

		return is_array( $record )
			&& in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_DEFERRED, WooPaymentsCutoverStateStore::STATE_RUNNING ), true )
			&& ! $this->is_awaiting_start( $record )
			&& null === ( $record['request_origin_token'] ?? null );
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

			$switched = $this->switch_to_main_site();
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
					$this->restore_from_main_site( $switched );
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
	private function switch_to_main_site(): ?bool {
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
		$token = $this->state_store->acquire_write_lock( $now );
		if ( null === $token ) {
			return $when_busy;
		}

		try {
			return $work( $token );
		} finally {
			$this->state_store->release_write_lock( $token );
		}
	}

	/**
	 * Restore a calling blog after a main-site plugin-lock operation.
	 *
	 * @param bool $switched Whether this worker switched to the main site.
	 */
	private function restore_from_main_site( bool $switched ): void {
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

		// This runs on every request, so a waiting attempt that is still scheduled is read without taking the write lock.
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
	 * @param array<string,mixed> $record Current record it supersedes.
	 * @param string              $source Supersession source.
	 * @param int                 $now    Current UTC timestamp.
	 * @return bool True when the awaiting generation was stored.
	 */
	private function replace_terminal_with_awaiting_start( array $record, string $source, int $now ): bool {
		$replacement             = $this->to_awaiting_merchant_start( $source, $now, $record['generation'] + 1 );
		$replacement['revision'] = $record['revision'] + 1;
		if ( ! $this->state_store->compare_and_set_record( $record, $replacement ) ) {
			return false;
		}
		// A rollback offers the switch again at once, on every admin page.
		if ( self::SOURCE_ROLLBACK === $source ) {
			delete_option( self::START_NOTICE_SNOOZED_UNTIL_OPTION );
		}

		return true;
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
			return false === $this->refresh_admin_classification( $record );
		}

		try {
			$failures = $this->normalize_codes( $this->preflight_service->get_failures() );
		} catch ( \Throwable $error ) {
			return false;
		}

		return ! in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $failures, true );
	}

	/**
	 * Tell whether the record's site currently has the bundled subscriptions exclusion marker.
	 *
	 * Refreshes the cached admin classification when it is stale, which runs the preflight and writes an option.
	 *
	 * @param array<string,mixed> $record Current record.
	 * @return bool
	 */
	private function refresh_has_bundled_subscriptions_marker( array $record ): bool {
		return true === $this->refresh_admin_classification( $record );
	}

	/**
	 * Refresh the cached bundled subscriptions classification for admin notices, reusing it for one record revision and an hour.
	 *
	 * Otherwise this runs the preflight and writes the cache option. The full preflight scans Action Scheduler and order meta,
	 * so it must not run on every admin page. The worker re-checks before it acts.
	 *
	 * @param array<string,mixed>|null $record Current record, or null when none exists.
	 * @return bool|null Whether the marker is present, or null when the preflight failed.
	 */
	private function refresh_admin_classification( ?array $record ): ?bool {
		$key    = is_array( $record ) ? $record['generation'] . ':' . $record['revision'] : 'none';
		$cached = get_option( self::ADMIN_CLASSIFICATION_OPTION, null );
		if ( is_array( $cached ) && ( $cached['key'] ?? null ) === $key && is_bool( $cached['present'] ?? null ) && time() < (int) ( $cached['expires_at'] ?? 0 ) ) {
			return $cached['present'];
		}

		try {
			$present = in_array( WooPaymentsCutoverPreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS, $this->normalize_codes( $this->preflight_service->get_failures() ), true );
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
	 * @return array<string,mixed>
	 */
	private function to_awaiting_merchant_start( string $source, int $now, int $generation ): array {
		$awaiting                 = $this->create_pending_record( $source, $now );
		$awaiting['generation']   = $generation;
		$awaiting['current_step'] = self::STEP_AWAITING_MERCHANT_START;
		$awaiting['step_log']     = array(
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
