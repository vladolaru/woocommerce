<?php
/**
 * WooPaymentsCutoverReconciliationJob class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates durable site-local WooPayments cutover attempts.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverReconciliationJob implements RegisterHooksInterface {

	/** State option name. */
	public const STATE_OPTION = WooPaymentsCutoverStateStore::OPTION_NAME;

	/** Reconciliation action hook. */
	public const ACTION_HOOK = WooPaymentsCutoverActionScheduler::ACTION_HOOK;

	/** Reconciliation action group. */
	public const ACTION_GROUP = WooPaymentsCutoverActionScheduler::GROUP_ID;

	/** Feature-seeding extension action fired before plugin deactivation. */
	public const ACTION_SEED_FEATURES = 'woocommerce_woopayments_cutover_seed_features';

	/** Maximum age of a running heartbeat before registration recovers it. */
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

	/** Operational actions whose native callbacks continue after cutover. */
	private const ADOPTED_OPERATIONAL_QUEUE_HOOKS = array(
		WooPaymentsOperationalQueueService::STORE_SETUP_SYNC_ACTION,
		WooPaymentsOperationalQueueService::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION,
		WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK,
		WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK,
	);

	/** Legacy subscription migration actions that must not survive cutover. */
	private const LEGACY_SUBSCRIPTION_MIGRATOR_HOOKS = array(
		'wcpay_schedule_subscription_migrations',
		'wcpay_migrate_subscription',
		'wcpay_migrate_subscription_retry',
	);

	/** Conditions caused by an invalid extension filter. */
	private const ENGINEERING_ERROR_CODES = array(
		'preflight_filter_invalid',
		'provider_events_filter_invalid',
		'operational_queue_hooks_filter_invalid',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

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
	 * @param NativePaymentsRuntimeArbiter          $arbiter          Runtime owner arbiter.
	 * @param WooPaymentsCutoverStateStore          $state_store      Persisted state store.
	 * @param WooPaymentsCutoverActionScheduler     $scheduler        Action Scheduler adapter.
	 * @param WooPaymentsCutoverPreflightService    $preflight_service   Headless cutover facts.
	 * @param WooPaymentsCutoverNormalizationRunner $normalization_runner Cutover normalization runner.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsCutoverStateStore $state_store, WooPaymentsCutoverActionScheduler $scheduler, WooPaymentsCutoverPreflightService $preflight_service, WooPaymentsCutoverNormalizationRunner $normalization_runner ): void {
		$this->arbiter              = $arbiter;
		$this->state_store          = $state_store;
		$this->scheduler            = $scheduler;
		$this->preflight_service    = $preflight_service;
		$this->normalization_runner = $normalization_runner;
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
		add_action( self::ACTION_HOOK, array( $this, 'handle_reconcile' ), 10, 2 );
		add_action( 'action_scheduler_init', array( $this, 'handle_action_scheduler_init' ) );

		if ( did_action( 'action_scheduler_init' ) ) {
			$this->handle_action_scheduler_init();
		}
	}

	/**
	 * Repair durable scheduling after Action Scheduler is initialized.
	 *
	 * @internal
	 */
	public function handle_action_scheduler_init(): void {
		$this->repair_schedule();
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
		return $this->enqueue_with_context( $source, null, null );
	}

	/**
	 * Record a manual deactivation and reconcile it after WordPress updates active-plugin options.
	 *
	 * @param string $plugin_file  Exact deactivated WooPayments plugin file.
	 * @param bool   $network_wide Whether WordPress is deactivating it network-wide.
	 * @return bool True when durable reconciliation already exists or was scheduled.
	 */
	public function enqueue_manual_deactivation( string $plugin_file, bool $network_wide ): bool {
		return $this->enqueue_with_context( 'manual_deactivation', $plugin_file, $network_wide ? 'network' : 'site' );
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
	 * Start one reconciliation generation with optional plugin-origin context.
	 *
	 * @param string      $source              Trigger source.
	 * @param string|null $origin_plugin_file  Exact plugin path for manual deactivation.
	 * @param string|null $origin_plugin_scope Either site or network for manual deactivation.
	 * @return bool True when durable work already exists or was scheduled.
	 */
	private function enqueue_with_context( string $source, ?string $origin_plugin_file, ?string $origin_plugin_scope ): bool {
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			return false;
		}
		if ( is_multisite() && ( 'network' === $origin_plugin_scope || $this->preflight_service->is_woopayments_network_active() ) ) {
			return $this->enqueue_network_generation( $source, $origin_plugin_file, $origin_plugin_scope );
		}

		return $this->enqueue_local_generation( $source, $origin_plugin_file, $origin_plugin_scope );
	}

	/**
	 * Start one site-local generation without applying network fan-out.
	 *
	 * @param string      $source              Trigger source.
	 * @param string|null $origin_plugin_file  Exact deactivated plugin path.
	 * @param string|null $origin_plugin_scope Site or network scope.
	 * @return bool True when durable work exists or was scheduled.
	 */
	private function enqueue_local_generation( string $source, ?string $origin_plugin_file, ?string $origin_plugin_scope ): bool {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}

		try {
			$record = $this->state_store->get_record();
			if ( is_array( $record ) ) {
				if ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
					if ( null !== $origin_plugin_file && null !== $origin_plugin_scope ) {
						return $this->replace_pending_with_manual_origin( $record, $source, $origin_plugin_file, $origin_plugin_scope, $now );
					}
					if ( 'awaiting_merchant_start' === $record['current_step'] ) {
						return $this->start_awaiting_generation( $record, $source, $now );
					}
					return $this->ensure_record_scheduled( $record, $now );
				}
				if ( WooPaymentsCutoverState::RUNNING === $record['state'] && null !== $origin_plugin_file && null !== $origin_plugin_scope ) {
					return $this->replace_running_with_manual_origin( $record, $source, $origin_plugin_file, $origin_plugin_scope, $now );
				}
				if ( null !== $origin_plugin_file && null !== $origin_plugin_scope && in_array( $record['state'], array( WooPaymentsCutoverState::DONE, WooPaymentsCutoverState::EXCLUDED ), true ) ) {
					return $this->replace_terminal_with_pending( $record, $source, $origin_plugin_file, $origin_plugin_scope, $now );
				}
				if ( $this->can_reopen_terminal_record( $record ) ) {
					return $this->replace_terminal_with_pending( $record, $source, $origin_plugin_file, $origin_plugin_scope, $now );
				}

				return true;
			}

			$record = $this->create_pending_record( $source, $now, $origin_plugin_file, $origin_plugin_scope );
			if ( ! $this->state_store->save_record( $record ) ) {
				return false;
			}

			return $this->ensure_record_scheduled( $record, $now );
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Record a successful external plugin activation as a rollback awaiting merchant confirmation.
	 *
	 * @param bool $network_wide Whether the external activation was network-wide.
	 * @return bool True when the rollback was already recorded or a new generation was opened.
	 */
	public function record_plugin_activation( bool $network_wide = false ): bool {
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			return false;
		}
		if ( $network_wide && is_multisite() ) {
			return $this->record_network_plugin_activation();
		}

		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}
		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) ) {
				return false;
			}
			if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ) {
				return true;
			}
			if ( WooPaymentsCutoverState::DONE !== $record['state'] ) {
				return false;
			}

			return $this->replace_terminal_with_awaiting_start( $record, 'rollback', $now );
		} finally {
			$this->state_store->release_lease( $token );
		}
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

		$current_blog_id       = get_current_blog_id();
		$network_main_site_id  = get_main_site_id( get_current_network_id() );
		$maximum_generation    = 0;
		$awaiting_generation   = 0;
		$has_completed_cutover = false;
		try {
			foreach ( $site_ids as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				try {
					$record = $this->state_store->get_record();
					if ( ! is_array( $record ) || true !== ( $record['network_cutover'] ?? false ) ) {
						continue;
					}
					$maximum_generation = max( $maximum_generation, $record['generation'] );
					if ( WooPaymentsCutoverState::DONE === $record['state'] ) {
						$has_completed_cutover = true;
					}
					if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ) {
						$awaiting_generation = max( $awaiting_generation, $record['generation'] );
					}
				} finally {
					if ( get_current_blog_id() !== $current_blog_id ) {
						restore_current_blog();
					}
				}
			}
			if ( ! $has_completed_cutover && 0 === $awaiting_generation ) {
				return false;
			}

			$generation = $awaiting_generation >= $maximum_generation ? $awaiting_generation : $maximum_generation + 1;
			$complete   = true;
			foreach ( $site_ids as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$site_token = $network_main_site_id === $site_id ? null : $this->state_store->acquire_lease( time() );
				try {
					if ( $network_main_site_id !== $site_id && null === $site_token ) {
						$complete = false;
						continue;
					}
					$record = $this->state_store->get_record();
					if ( is_array( $record ) && $record['generation'] > $generation ) {
						continue;
					}
					if ( is_array( $record ) && $record['generation'] === $generation ) {
						if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ) {
							continue;
						}
						$complete = false;
						continue;
					}

					$now                         = time();
					$awaiting                    = $this->create_pending_record( 'rollback', $now );
					$awaiting['generation']      = $generation;
					$awaiting['network_cutover'] = true;
					$awaiting['current_step']    = 'awaiting_merchant_start';
					$awaiting['next_attempt_at'] = null;
					$awaiting['step_log']        = array(
						array(
							'step'    => 'awaiting_merchant_start',
							'at'      => $now,
							'context' => array( 'source' => 'rollback' ),
						),
					);
					if ( is_array( $record ) ) {
						$awaiting['revision'] = $record['revision'] + 1;
						$stored               = $this->state_store->compare_and_set_record( $record, $awaiting );
						if ( $stored ) {
							$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
						}
					} else {
						$stored = $this->state_store->save_record( $awaiting );
					}
					$complete = $stored && $complete;
				} finally {
					if ( is_string( $site_token ) ) {
						$this->state_store->release_lease( $site_token );
					}
					if ( get_current_blog_id() !== $current_blog_id ) {
						restore_current_blog();
					}
				}
			}

			return $complete;
		} finally {
			$this->release_network_lease( $network_token );
		}
	}

	/**
	 * Classify a local Stripe Billing marker before admin notice rendering.
	 *
	 * @return array<string,mixed>|null Current durable record.
	 */
	public function classify_for_admin_notice(): ?array {
		$record = $this->state_store->get_record();
		if ( is_array( $record ) ) {
			if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] && $this->record_has_stripe_billing_failure( $record ) ) {
				if ( true === ( $record['network_cutover'] ?? false ) ) {
					if ( $this->schedule_network_exclusion_convergence( $record, 'legacy_stripe_billing_subscriptions_present' ) ) {
						$this->propagate_network_exclusion( $record['generation'], 'legacy_stripe_billing_subscriptions_present' );
					}
				} else {
					$this->exclude_awaiting_record( $record, 'legacy_stripe_billing_subscriptions_present' );
				}
				return $this->state_store->get_record();
			}
			if ( $this->can_reopen_terminal_record( $record ) ) {
				$now   = time();
				$token = $this->state_store->acquire_lease( $now );
				if ( null !== $token ) {
					try {
						$current = $this->state_store->get_record();
						if ( is_array( $current ) && $current === $record ) {
							$this->replace_terminal_with_awaiting_start( $current, 'terminal_superseded', $now );
						}
					} finally {
						$this->state_store->release_lease( $token );
					}
				}
			}
			return $this->state_store->get_record();
		}
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			return $record;
		}

		try {
			$failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover could not classify the admin notice.', array( 'error' => $error->getMessage() ) );
			return null;
		}
		if ( ! in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true ) ) {
			return null;
		}

		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return null;
		}
		try {
			if ( is_array( $this->state_store->get_record() ) ) {
				return $this->state_store->get_record();
			}
			$excluded                    = $this->create_pending_record( 'local_exclusion', $now );
			$excluded['state']           = WooPaymentsCutoverState::EXCLUDED;
			$excluded['current_step']    = 'excluded';
			$excluded['deferred_codes']  = array( 'legacy_stripe_billing_subscriptions_present' );
			$excluded['next_attempt_at'] = null;
			$excluded['step_log'][]      = array(
				'step'    => 'excluded',
				'at'      => $now,
				'context' => array( 'code' => 'legacy_stripe_billing_subscriptions_present' ),
			);

			$this->state_store->save_record( $excluded );
		} finally {
			$this->state_store->release_lease( $token );
		}
		return $this->state_store->get_record();
	}

	/**
	 * Tell whether an eligible active-plugin store may start a cutover generation.
	 *
	 * @return bool
	 */
	public function should_offer_start(): bool {
		$record = $this->state_store->get_record();
		if ( ! is_array( $record ) ) {
			return true;
		}
		if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ) {
			return ! $this->record_has_stripe_billing_failure( $record );
		}

		return $this->can_reopen_terminal_record( $record );
	}

	/**
	 * Atomically consume the one reconnect notice while leaving silent retries active.
	 *
	 * @return bool True only for the request that claimed the notice.
	 */
	public function consume_reconnect_notice(): bool {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}
		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) || ! in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) || ! $this->has_information_outcome( $record['informational_outcomes'], array( 'code' => 'reconnect_required' ) ) || $this->has_information_outcome( $record['informational_outcomes'], array( 'code' => 'reconnect_notice_shown' ) ) ) {
				return false;
			}
			$updated                           = $record;
			$updated['revision']               = $record['revision'] + 1;
			$updated['updated_at']             = $now;
			$updated['informational_outcomes'] = $this->merge_information_outcomes( $record['informational_outcomes'], array( array( 'code' => 'reconnect_notice_shown' ) ) );
			$updated                           = $this->append_step( $updated, 'reconnect_notice_shown', $now );

			return $this->state_store->compare_and_set_record( $record, $updated );
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Fan one generation out to every site in the current network.
	 *
	 * @param string      $source              Trigger source.
	 * @param string|null $origin_plugin_file  Exact deactivated plugin path.
	 * @param string|null $origin_plugin_scope Site or network scope.
	 * @return bool True when every site owns scheduled durable work.
	 */
	private function enqueue_network_generation( string $source, ?string $origin_plugin_file, ?string $origin_plugin_scope ): bool {
		$site_ids = $this->get_current_network_site_ids();
		if ( array() === $site_ids ) {
			return false;
		}
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return false;
		}

		$current_blog_id      = get_current_blog_id();
		$network_main_site_id = get_main_site_id( get_current_network_id() );
		$scheduled_everywhere = false;
		$propagate_exclusion  = null;
		try {
			$maximum_generation   = 0;
			$active_generations   = array();
			$terminal_generations = array();
			$excluded_generations = array();
			foreach ( $site_ids as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				try {
					$record             = $this->state_store->get_record();
					$record_generation  = is_array( $record ) ? $record['generation'] : 0;
					$maximum_generation = max( $maximum_generation, $record_generation );
					if ( is_array( $record ) && true === ( $record['network_cutover'] ?? false ) ) {
						if ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::RUNNING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
							$active_generations[] = $record_generation;
						} else {
							$terminal_generations[] = $record_generation;
							if ( WooPaymentsCutoverState::EXCLUDED === $record['state'] ) {
								$excluded_generations[ $record_generation ] = $record['deferred_codes'][0] ?? 'legacy_stripe_billing_subscriptions_present';
							}
						}
					}
				} finally {
					if ( get_current_blog_id() !== $current_blog_id ) {
						restore_current_blog();
					}
				}
			}
			$highest_active_generation  = array() === $active_generations ? 0 : max( $active_generations );
			$manual_supersedes_terminal = null !== $origin_plugin_file && null !== $origin_plugin_scope && in_array( $maximum_generation, $terminal_generations, true );
			$generation                 = ! $manual_supersedes_terminal && $highest_active_generation >= $maximum_generation ? max( 1, $highest_active_generation ) : $maximum_generation + 1;
			if ( isset( $excluded_generations[ $generation ] ) ) {
				$propagate_exclusion = $excluded_generations[ $generation ];
			} else {
				$scheduled_everywhere = true;
			}

			if ( null === $propagate_exclusion ) {
				foreach ( $site_ids as $site_id ) {
					if ( get_current_blog_id() !== $site_id ) {
						switch_to_blog( $site_id );
					}
					$site_token = $network_main_site_id === $site_id ? null : $this->state_store->acquire_lease( time() );
					try {
						if ( $network_main_site_id !== $site_id && null === $site_token ) {
							$scheduled_everywhere = false;
							continue;
						}
						$record = $this->state_store->get_record();
						if ( is_array( $record ) && $generation === $record['generation'] && true === ( $record['network_cutover'] ?? false ) ) {
							if ( null !== $origin_plugin_file && null !== $origin_plugin_scope && in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
								$scheduled_everywhere = $this->replace_pending_with_manual_origin( $record, $source, $origin_plugin_file, $origin_plugin_scope, time() ) && $scheduled_everywhere;
							} elseif ( null !== $origin_plugin_file && null !== $origin_plugin_scope && WooPaymentsCutoverState::RUNNING === $record['state'] ) {
								$scheduled_everywhere = $this->replace_running_with_manual_origin( $record, $source, $origin_plugin_file, $origin_plugin_scope, time() ) && $scheduled_everywhere;
							} elseif ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ) {
								$scheduled_everywhere = $this->start_awaiting_generation( $record, $source, time() ) && $scheduled_everywhere;
							} elseif ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
								$scheduled_everywhere = $this->ensure_record_scheduled( $record, time() ) && $scheduled_everywhere;
							}
							continue;
						}

						$now                        = time();
						$pending                    = $this->create_pending_record( $source, $now, $origin_plugin_file, $origin_plugin_scope );
						$pending['generation']      = $generation;
						$pending['network_cutover'] = true;
						if ( is_array( $record ) ) {
							$pending['revision'] = $record['revision'] + 1;
							$stored              = $this->state_store->compare_and_set_record( $record, $pending );
							if ( $stored ) {
								$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
							}
						} else {
							$stored = $this->state_store->save_record( $pending );
						}
						$scheduled_everywhere = $stored && $this->ensure_record_scheduled( $pending, $now ) && $scheduled_everywhere;
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
		} finally {
			$this->release_network_lease( $network_token );
		}

		return null !== $propagate_exclusion ? $this->propagate_network_exclusion( $generation, $propagate_exclusion ) : $scheduled_everywhere;
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
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			return;
		}

		$now     = time();
		$token   = $this->state_store->acquire_lease( $now );
		$claimed = null;
		if ( null === $token ) {
			return;
		}

		try {
			$record = $this->state_store->get_record();
			if (
				! is_array( $record )
				|| $generation !== $record['generation']
				|| $attempt !== $record['attempt'] + 1
				|| ! in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true )
			) {
				return;
			}
			if ( 'verify_native_ownership' === $record['current_step'] && self::$request_token === $record['request_origin_token'] ) {
				return;
			}

			$resume_step                 = $record['current_step'];
			$claimed                     = $record;
			$claimed['revision']         = $record['revision'] + 1;
			$claimed['state']            = WooPaymentsCutoverState::RUNNING;
			$claimed['attempt']          = $attempt;
			$claimed['action_id']        = 0;
			$claimed['current_step']     = 'verify_native_ownership' === $resume_step ? $resume_step : 'running';
			$claimed['updated_at']       = $now;
			$claimed['next_attempt_at']  = null;
			$claimed['lease_token']      = $token;
			$claimed['lease_expires_at'] = $now + self::RUNNING_TIMEOUT;
			$claimed                     = $this->append_step( $claimed, 'running', $now );
			if ( ! $this->state_store->compare_and_set_record( $record, $claimed ) ) {
				$claimed = null;
			}
		} finally {
			$this->state_store->release_lease( $token );
		}

		if ( is_array( $claimed ) ) {
			if ( 'verify_native_ownership' === $claimed['current_step'] ) {
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
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}

		try {
			$record = $this->state_store->get_record();
			if (
				! is_array( $record )
				|| $record !== $expected_claim
				|| WooPaymentsCutoverState::RUNNING !== $record['state']
				|| $record['lease_expires_at'] <= $now
			) {
				return false;
			}

			$deferred                           = $record;
			$deferred['revision']               = $record['revision'] + 1;
			$deferred['state']                  = WooPaymentsCutoverState::DEFERRED;
			$deferred['action_id']              = 0;
			$deferred['current_step']           = 'deferred';
			$deferred['updated_at']             = $now;
			$deferred['deferred_codes']         = $this->normalize_codes( $codes );
			$deferred['informational_outcomes'] = $this->merge_information_outcomes( $record['informational_outcomes'], $outcomes );
			$deferred['next_attempt_at']        = $now + $this->get_retry_delay( $record, $now );
			$deferred['lease_token']            = null;
			$deferred['lease_expires_at']       = null;
			$deferred                           = $this->append_step( $deferred, 'deferred', $now, array( 'codes' => $deferred['deferred_codes'] ) );
			if ( ! $this->state_store->compare_and_set_record( $record, $deferred ) ) {
				return false;
			}

			return $this->ensure_record_scheduled( $deferred, $now );
		} finally {
			$this->state_store->release_lease( $token );
		}
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
			if ( true === ( $claimed['network_cutover'] ?? false ) ) {
				$fanout = $this->repair_network_generation_fanout( $claimed );
				if ( 'superseded' === $fanout['status'] ) {
					$this->supersede_network_claim( $claimed, $fanout['generation'], $fanout['phase'], $fanout['origin_plugin_file'], $fanout['origin_plugin_scope'] );
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
			$failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
			if ( in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true ) ) {
				if ( true === ( $claimed['network_cutover'] ?? false ) ) {
					if ( ! $this->propagate_network_exclusion( $claimed['generation'], 'legacy_stripe_billing_subscriptions_present' ) ) {
						$this->defer( $claimed, array( 'network_exclusion_propagation_pending' ) );
					}
				} else {
					$this->exclude( $claimed, 'legacy_stripe_billing_subscriptions_present' );
				}
				return;
			}

			$claimed  = $this->record_engineering_error_observations( $claimed, $failures );
			$outcomes = array();
			if ( in_array( 'wpcom_connection_owner_user_token_unavailable', $failures, true ) && $this->preflight_service->is_cutover_connection_owner_user_missing() ) {
				$outcomes[] = array( 'code' => 'reconnect_required' );
			}

			if ( in_array( 'unsupported_payment_methods_enabled', $failures, true ) ) {
				$removed_payment_method_ids = $this->preflight_service->remove_unsupported_enabled_payment_method_ids();
				if ( array() !== $removed_payment_method_ids ) {
					$outcomes[] = array(
						'code'               => 'unsupported_payment_methods_disabled',
						'payment_method_ids' => $removed_payment_method_ids,
					);
				}
			}

			$plugin_update_succeeded = false;
			if ( in_array( 'woopayments_plugin_version_unsupported', $failures, true ) ) {
				$plugin_update_succeeded = $this->update_woopayments_plugin();
			}

			$outcomes = array_merge( $outcomes, $this->disposition_operational_queue() );

			$this->preflight_service->invalidate_current_blog_memoization();
			$remaining_failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
			if ( in_array( 'legacy_stripe_billing_subscriptions_present', $remaining_failures, true ) ) {
				if ( true === ( $claimed['network_cutover'] ?? false ) ) {
					if ( ! $this->propagate_network_exclusion( $claimed['generation'], 'legacy_stripe_billing_subscriptions_present' ) ) {
						$this->defer( $claimed, array( 'network_exclusion_propagation_pending' ), $outcomes );
					}
				} else {
					$this->exclude( $claimed, 'legacy_stripe_billing_subscriptions_present' );
				}
				return;
			}
			if ( $plugin_update_succeeded && in_array( 'woopayments_plugin_version_unsupported', $remaining_failures, true ) ) {
				$this->log_error( 'WooPayments cutover plugin update completed without installing a supported version.' );
			}

			if ( array() !== $remaining_failures ) {
				$this->defer( $claimed, $remaining_failures, $outcomes );
				return;
			}

			if ( true === ( $claimed['network_cutover'] ?? false ) ) {
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
	 * @return array{status:string,generation:int,code:string,phase:string,origin_plugin_file:?string,origin_plugin_scope:?string} Fan-out disposition.
	 */
	private function repair_network_generation_fanout( array $claimed ): array {
		$network_token = $this->acquire_network_lease( time() );
		if ( null === $network_token ) {
			return array(
				'status'              => 'incomplete',
				'generation'          => $claimed['generation'],
				'code'                => '',
				'phase'               => 'active',
				'origin_plugin_file'  => null,
				'origin_plugin_scope' => null,
			);
		}

		$current_blog_id      = get_current_blog_id();
		$network_main_site_id = get_main_site_id( get_current_network_id() );
		$complete             = true;
		try {
			$highest_generation      = $claimed['generation'];
			$excluded_code           = '';
			$highest_phase           = 'active';
			$highest_origin_file     = null;
			$highest_origin_scope    = null;
			$highest_origin_conflict = false;
			foreach ( $this->get_current_network_site_ids() as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				try {
					$record = $this->state_store->get_record();
					if ( is_array( $record ) && $record['generation'] > $highest_generation ) {
						$highest_generation      = $record['generation'];
						$highest_phase           = WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ? 'awaiting_merchant_start' : 'active';
						$highest_origin_file     = null;
						$highest_origin_scope    = null;
						$highest_origin_conflict = false;
					}
					if ( is_array( $record ) && $record['generation'] === $highest_generation && $highest_generation > $claimed['generation'] ) {
						if ( 'awaiting_merchant_start' !== $record['current_step'] ) {
							$highest_phase = 'active';
						}
						$record_origin_file  = is_string( $record['origin_plugin_file'] ?? null ) && '' !== $record['origin_plugin_file'] ? $record['origin_plugin_file'] : null;
						$record_origin_scope = in_array( $record['origin_plugin_scope'] ?? null, array( 'site', 'network' ), true ) ? $record['origin_plugin_scope'] : null;
						if ( null !== $record_origin_file && null !== $record_origin_scope ) {
							if ( null === $highest_origin_file || null === $highest_origin_scope ) {
								$highest_origin_file  = $record_origin_file;
								$highest_origin_scope = $record_origin_scope;
							} elseif ( $record_origin_file !== $highest_origin_file || $record_origin_scope !== $highest_origin_scope ) {
								$highest_origin_conflict = true;
							}
						}
					}
					if ( is_array( $record ) && $record['generation'] === $claimed['generation'] && WooPaymentsCutoverState::EXCLUDED === $record['state'] ) {
						$excluded_code = $record['deferred_codes'][0] ?? 'legacy_stripe_billing_subscriptions_present';
					}
				} finally {
					if ( get_current_blog_id() !== $current_blog_id ) {
						restore_current_blog();
					}
				}
			}
			if ( $highest_generation > $claimed['generation'] ) {
				if ( $highest_origin_conflict ) {
					$this->log_error( 'WooPayments cutover found conflicting manual origins for a newer network generation.', array( 'generation' => $highest_generation ) );
					return array(
						'status'              => 'incomplete',
						'generation'          => $claimed['generation'],
						'code'                => '',
						'phase'               => 'active',
						'origin_plugin_file'  => null,
						'origin_plugin_scope' => null,
					);
				}
				return array(
					'status'              => 'superseded',
					'generation'          => $highest_generation,
					'code'                => '',
					'phase'               => $highest_phase,
					'origin_plugin_file'  => $highest_origin_file,
					'origin_plugin_scope' => $highest_origin_scope,
				);
			}
			if ( '' !== $excluded_code ) {
				return array(
					'status'              => 'excluded',
					'generation'          => $claimed['generation'],
					'code'                => $excluded_code,
					'phase'               => 'active',
					'origin_plugin_file'  => null,
					'origin_plugin_scope' => null,
				);
			}

			foreach ( $this->get_current_network_site_ids() as $site_id ) {
				if ( get_current_blog_id() !== $site_id ) {
					switch_to_blog( $site_id );
				}
				$site_token = $network_main_site_id === $site_id ? null : $this->state_store->acquire_lease( time() );
				try {
					if ( $network_main_site_id !== $site_id && null === $site_token ) {
						$complete = false;
						continue;
					}

					$record = $this->state_store->get_record();
					if ( is_array( $record ) && $record['generation'] > $claimed['generation'] ) {
						$complete = false;
						continue;
					}
					if ( is_array( $record ) && $record['generation'] === $claimed['generation'] && true === ( $record['network_cutover'] ?? false ) ) {
						if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] ) {
							$complete = $this->start_awaiting_generation( $record, 'network_fanout_repair', time() ) && $complete;
						} elseif ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
							$complete = $this->ensure_record_scheduled( $record, time() ) && $complete;
						}
						continue;
					}

					$now                        = time();
					$pending                    = $this->create_pending_record( 'network_fanout_repair', $now, $claimed['origin_plugin_file'] ?? null, $claimed['origin_plugin_scope'] ?? null );
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
						$stored = $this->state_store->save_record( $pending );
					}
					$complete = $stored && $this->ensure_record_scheduled( $pending, $now ) && $complete;
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

		return array(
			'status'              => $complete ? 'complete' : 'incomplete',
			'generation'          => $claimed['generation'],
			'code'                => '',
			'phase'               => 'active',
			'origin_plugin_file'  => null,
			'origin_plugin_scope' => null,
		);
	}

	/**
	 * Replace a stale source claim with durable work for the higher observed network generation.
	 *
	 * @param array<string,mixed> $claimed    Exact stale running source record.
	 * @param int                 $generation Higher observed generation.
	 * @param string              $phase      Higher generation phase.
	 * @param string|null         $origin_plugin_file  Higher generation's manual-deactivation path.
	 * @param string|null         $origin_plugin_scope Higher generation's manual-deactivation scope.
	 */
	private function supersede_network_claim( array $claimed, int $generation, string $phase, ?string $origin_plugin_file, ?string $origin_plugin_scope ): void {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return;
		}
		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) || $record !== $claimed ) {
				return;
			}
			$pending                    = $this->create_pending_record( 'network_generation_superseded', $now, $origin_plugin_file, $origin_plugin_scope );
			$pending['generation']      = $generation;
			$pending['revision']        = $record['revision'] + 1;
			$pending['network_cutover'] = true;
			if ( 'awaiting_merchant_start' === $phase ) {
				$pending['current_step']    = 'awaiting_merchant_start';
				$pending['next_attempt_at'] = null;
				$pending['step_log']        = array(
					array(
						'step'    => 'awaiting_merchant_start',
						'at'      => $now,
						'context' => array( 'source' => 'network_generation_superseded' ),
					),
				);
			}
			if ( $this->state_store->compare_and_set_record( $record, $pending ) ) {
				if ( 'awaiting_merchant_start' !== $phase ) {
					$this->ensure_record_scheduled( $pending, $now );
				}
			}
		} finally {
			$this->state_store->release_lease( $token );
		}
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

		$deactivated = true;
		if ( ! $this->is_manual_deactivation_claim( $claimed ) ) {
			try {
				$this->internal_plugin_lifecycle_change = true;
				$deactivated                            = $this->preflight_service->deactivate_woopayments_plugin();
			} catch ( \Throwable $error ) {
				$this->log_error( 'WooPayments cutover plugin deactivation failed.', array( 'error' => $error->getMessage() ) );
				$deactivated = false;
			} finally {
				$this->internal_plugin_lifecycle_change = false;
			}
		}
		if ( ! $deactivated ) {
			$this->defer( $claimed, array( 'plugin_deactivation_failed' ), $outcomes );
			return;
		}

		$this->schedule_ownership_verification( $claimed, $outcomes );
	}

	/**
	 * Run finalization prerequisites while retaining the current running revision.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 * @return array<string,mixed>|null Updated running claim, or null after a deferred result.
	 */
	private function prepare_finalization_claim( array $claimed, array $outcomes ): ?array {
		try {
			$result = $this->normalization_runner->run();
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover normalization failed.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'normalization_failed' ), $outcomes );
			return null;
		}
		if ( in_array( 'settings_persistence_failed', $result['changes'], true ) ) {
			$this->defer( $claimed, array( 'normalization_failed' ), $outcomes );
			return null;
		}

		$seed_started   = array( 'code' => 'feature_seeding_started' );
		$seed_completed = array( 'code' => 'feature_seeding_completed' );
		if ( ! $this->has_information_outcome( $claimed['informational_outcomes'], $seed_completed ) ) {
			if ( ! $this->has_information_outcome( $claimed['informational_outcomes'], $seed_started ) ) {
				$seeded_claim = $this->persist_information_outcomes( $claimed, array( $seed_started ) );
				if ( null === $seeded_claim ) {
					return null;
				}
				$claimed = $seeded_claim;
			}
			try {
				/**
				 * Fires before WooPayments plugin deactivation so feature owners can seed native settings.
				 *
				 * Listeners must be idempotent for each generation and site. A crash before completion is persisted causes the action to run again.
				 *
				 * @since 11.2.0
				 *
				 * @param int $generation Cutover generation being finalized.
				 * @param int $site_id    Site whose native feature settings should be seeded.
				 */
				do_action( self::ACTION_SEED_FEATURES, (int) $claimed['generation'], get_current_blog_id() );
			} catch ( \Throwable $error ) {
				$this->log_error( 'WooPayments cutover feature seeding failed.', array( 'error' => $error->getMessage() ) );
				$this->defer( $claimed, array( 'feature_seeding_failed' ), $outcomes );
				return null;
			}
			$seeded_claim = $this->persist_information_outcomes( $claimed, array( $seed_completed ) );
			if ( null === $seeded_claim ) {
				return null;
			}
			$claimed = $seeded_claim;
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

		try {
			if ( ! $this->is_network_generation_ready( $generation ) ) {
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
				return;
			}

			$this->schedule_network_ownership_verification( $generation );
		} finally {
			$this->release_network_lease( $network_token );
		}
	}

	/**
	 * Tell whether every site reached the barrier for one generation.
	 *
	 * @param int $generation Generation being checked.
	 * @return bool
	 */
	private function is_network_generation_ready( int $generation ): bool {
		$current_blog_id = get_current_blog_id();
		foreach ( $this->get_current_network_site_ids() as $site_id ) {
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			try {
				$record        = $this->state_store->get_record();
				$is_ready      = is_array( $record ) && WooPaymentsCutoverState::DEFERRED === $record['state'] && in_array( 'network_barrier', $record['deferred_codes'], true );
				$is_finalizing = is_array( $record ) && ( in_array( $record['current_step'], array( 'verify_native_ownership', 'done' ), true ) );
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
	 * Schedule a future native-ownership probe on every ready site.
	 *
	 * @param int $generation Completed network barrier generation.
	 */
	private function schedule_network_ownership_verification( int $generation ): void {
		$current_blog_id      = get_current_blog_id();
		$network_main_site_id = get_main_site_id( get_current_network_id() );
		foreach ( $this->get_current_network_site_ids() as $site_id ) {
			if ( get_current_blog_id() !== $site_id ) {
				switch_to_blog( $site_id );
			}
			$site_token = $network_main_site_id === $site_id ? null : $this->state_store->acquire_lease( time() );
			try {
				if ( $network_main_site_id !== $site_id && null === $site_token ) {
					continue;
				}
				$record = $this->state_store->get_record();
				if ( ! is_array( $record ) || $generation !== $record['generation'] || WooPaymentsCutoverState::DEFERRED !== $record['state'] || ! in_array( 'network_barrier', $record['deferred_codes'], true ) ) {
					continue;
				}
				$now                                  = time();
				$verification                         = $record;
				$verification['revision']             = $record['revision'] + 1;
				$verification['state']                = WooPaymentsCutoverState::PENDING;
				$verification['action_id']            = 0;
				$verification['current_step']         = 'verify_native_ownership';
				$verification['updated_at']           = $now;
				$verification['deferred_codes']       = array();
				$verification['next_attempt_at']      = $now + self::FAST_RETRY_DELAY;
				$verification['request_origin_token'] = self::$request_token;
				$verification                         = $this->append_step( $verification, 'verify_native_ownership', $now );
				if ( $this->state_store->compare_and_set_record( $record, $verification ) ) {
					$this->scheduler->cancel( $generation, $record['attempt'] + 1 );
					$this->ensure_record_scheduled( $verification, $now );
				}
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
	 * Complete one verification claim only after a fresh request observes native ownership.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function verify_native_ownership_claim( array $claimed ): void {
		try {
			$plugin_active = $this->arbiter->is_plugin_runtime_active();
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover could not verify native ownership.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'native_ownership_verification_failed' ) );
			return;
		}
		if ( $plugin_active ) {
			$this->defer( $claimed, array( 'native_ownership_unverified' ) );
			return;
		}

		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return;
		}

		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverState::RUNNING !== $record['state'] ) {
				return;
			}

			$done                         = $record;
			$done['revision']             = $record['revision'] + 1;
			$done['state']                = WooPaymentsCutoverState::DONE;
			$done['action_id']            = 0;
			$done['current_step']         = 'done';
			$done['updated_at']           = $now;
			$done['deferred_codes']       = array();
			$done['next_attempt_at']      = null;
			$done['lease_token']          = null;
			$done['lease_expires_at']     = null;
			$done['request_origin_token'] = null;
			$done                         = $this->append_step( $done, 'done', $now );
			$this->state_store->compare_and_set_record( $record, $done );
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Tell whether the claim originated from WordPress's manual deactivation hook.
	 *
	 * @param array<string,mixed> $claimed Cutover claim.
	 * @return bool
	 */
	private function is_manual_deactivation_claim( array $claimed ): bool {
		return is_string( $claimed['origin_plugin_file'] ?? null ) && '' !== $claimed['origin_plugin_file'] && in_array( $claimed['origin_plugin_scope'] ?? null, array( 'site', 'network' ), true );
	}
	/**
	 * Persist a future ownership-verification attempt after plugin deactivation.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Informational outcomes accumulated during reconciliation.
	 * @return bool True when the future verification action is scheduled.
	 */
	private function schedule_ownership_verification( array $claimed, array $outcomes ): bool {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}

		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverState::RUNNING !== $record['state'] ) {
				return false;
			}

			$verification                           = $record;
			$verification['revision']               = $record['revision'] + 1;
			$verification['state']                  = WooPaymentsCutoverState::PENDING;
			$verification['action_id']              = 0;
			$verification['current_step']           = 'verify_native_ownership';
			$verification['updated_at']             = $now;
			$verification['deferred_codes']         = array();
			$verification['informational_outcomes'] = $this->merge_information_outcomes( $record['informational_outcomes'], $outcomes );
			$verification['next_attempt_at']        = $now + self::FAST_RETRY_DELAY;
			$verification['lease_token']            = null;
			$verification['lease_expires_at']       = null;
			$verification['request_origin_token']   = self::$request_token;
			$verification                           = $this->append_step( $verification, 'verify_native_ownership', $now );
			if ( ! $this->state_store->compare_and_set_record( $record, $verification ) ) {
				return false;
			}

			return $this->ensure_record_scheduled( $verification, $now );
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Persist a terminal exclusion for a claimed generation.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 * @param string              $code    Exclusion condition code.
	 */
	private function exclude( array $claimed, string $code ): void {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return;
		}

		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverState::RUNNING !== $record['state'] ) {
				return;
			}

			$excluded                     = $record;
			$excluded['revision']         = $record['revision'] + 1;
			$excluded['state']            = WooPaymentsCutoverState::EXCLUDED;
			$excluded['action_id']        = 0;
			$excluded['current_step']     = 'excluded';
			$excluded['updated_at']       = $now;
			$excluded['deferred_codes']   = array( $code );
			$excluded['next_attempt_at']  = null;
			$excluded['lease_token']      = null;
			$excluded['lease_expires_at'] = null;
			$excluded                     = $this->append_step( $excluded, 'excluded', $now, array( 'code' => $code ) );
			if ( $this->state_store->compare_and_set_record( $record, $excluded ) ) {
				$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
			}
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Fence every site in a network generation into the same terminal exclusion.
	 *
	 * @param int    $generation Excluded network generation.
	 * @param string $code       Exclusion condition code.
	 */
	private function propagate_network_exclusion( int $generation, string $code ): bool {
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
						if ( is_array( $record ) && $generation === $record['generation'] && WooPaymentsCutoverState::EXCLUDED === $record['state'] ) {
							$site_complete = true;
							break;
						}
						$now                              = time();
						$excluded                         = is_array( $record ) && $generation === $record['generation'] ? $record : $this->create_pending_record( 'network_exclusion_repair', $now );
						$excluded['generation']           = $generation;
						$excluded['revision']             = is_array( $record ) ? $record['revision'] + 1 : 1;
						$excluded['state']                = WooPaymentsCutoverState::EXCLUDED;
						$excluded['action_id']            = 0;
						$excluded['current_step']         = 'excluded';
						$excluded['updated_at']           = $now;
						$excluded['deferred_codes']       = array( $code );
						$excluded['next_attempt_at']      = null;
						$excluded['lease_token']          = null;
						$excluded['lease_expires_at']     = null;
						$excluded['request_origin_token'] = null;
						$excluded['network_cutover']      = true;
						$excluded                         = $this->append_step( $excluded, 'excluded', $now, array( 'code' => $code ) );
						$stored                           = is_array( $record ) ? $this->state_store->compare_and_set_record( $record, $excluded ) : $this->state_store->save_record( $excluded );
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
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}
		try {
			$current = $this->state_store->get_record();
			if ( ! is_array( $current ) || $current !== $record ) {
				return is_array( $current ) && ! ( WooPaymentsCutoverState::PENDING === $current['state'] && 'awaiting_merchant_start' === $current['current_step'] );
			}

			$deferred                     = $current;
			$deferred['revision']         = $current['revision'] + 1;
			$deferred['state']            = WooPaymentsCutoverState::DEFERRED;
			$deferred['action_id']        = 0;
			$deferred['current_step']     = 'network_exclusion_propagation';
			$deferred['updated_at']       = $now;
			$deferred['deferred_codes']   = array( 'network_exclusion_propagation_pending' );
			$deferred['next_attempt_at']  = $now;
			$deferred['lease_token']      = null;
			$deferred['lease_expires_at'] = null;
			$deferred                     = $this->append_step( $deferred, 'network_exclusion_propagation', $now, array( 'code' => $code ) );
			if ( ! $this->state_store->compare_and_set_record( $current, $deferred ) ) {
				return false;
			}

			$this->ensure_record_scheduled( $deferred, $now );
			return true;
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Record and report newly observed invalid-filter diagnostics.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param string[]            $failures Current reconciliation failures.
	 * @return array<string,mixed> Current claimed revision after observations are persisted.
	 */
	private function record_engineering_error_observations( array $claimed, array $failures ): array {
		foreach ( array_intersect( self::ENGINEERING_ERROR_CODES, $failures ) as $code ) {
			$outcome = array(
				'code'      => 'diagnostic_observed',
				'condition' => $code,
			);
			if ( $this->has_information_outcome( $claimed['informational_outcomes'], $outcome ) ) {
				continue;
			}

			$observed = $this->persist_information_outcomes( $claimed, array( $outcome ) );
			if ( null === $observed ) {
				continue;
			}

			$claimed = $observed;
			$this->log_error( 'WooPayments cutover encountered an invalid preflight filter.', array( 'condition' => $code ) );
			$this->record_tracks_diagnostic( $code );
		}

		return $claimed;
	}

	/**
	 * Persist informational outcomes while retaining the running lease fence.
	 *
	 * @param array<string,mixed> $claimed  Exact running state owned by this worker.
	 * @param array<int,mixed>    $outcomes Outcomes to persist.
	 * @return array<string,mixed>|null Updated claim, or null when it was fenced.
	 */
	private function persist_information_outcomes( array $claimed, array $outcomes ): ?array {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return null;
		}

		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) || $record !== $claimed || WooPaymentsCutoverState::RUNNING !== $record['state'] || $record['lease_expires_at'] <= $now ) {
				return null;
			}

			$updated                           = $record;
			$updated['revision']               = $record['revision'] + 1;
			$updated['updated_at']             = $now;
			$updated['informational_outcomes'] = $this->merge_information_outcomes( $record['informational_outcomes'], $outcomes );
			$updated                           = $this->append_step( $updated, 'observed_diagnostic', $now, array( 'outcomes' => $outcomes ) );

			return $this->state_store->compare_and_set_record( $record, $updated ) ? $updated : null;
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Reconcile queued legacy actions without canceling native-owned callbacks.
	 *
	 * @return array<int,mixed> Informational operational-action outcomes.
	 */
	private function disposition_operational_queue(): array {
		$outcomes = array();
		foreach ( $this->preflight_service->get_queued_operational_actions() as $action ) {
			if ( self::ACTION_HOOK === $action['hook'] && self::ACTION_GROUP === $action['group'] ) {
				continue;
			}
			if ( in_array( $action['hook'], self::ADOPTED_OPERATIONAL_QUEUE_HOOKS, true ) ) {
				$outcomes[] = array(
					'code' => 'operational_action_adopted',
					'hook' => $action['hook'],
				);
				continue;
			}
			if ( ! in_array( $action['hook'], self::LEGACY_SUBSCRIPTION_MIGRATOR_HOOKS, true ) || $action['action_id'] < 1 ) {
				continue;
			}
			$cancelled = $this->cancel_pending_legacy_migrator( $action['action_id'] );
			if ( true === $cancelled ) {
				$outcomes[] = array(
					'code' => 'legacy_migrator_canceled',
					'hook' => $action['hook'],
				);
			} elseif ( null === $cancelled ) {
				$this->log_error(
					'WooPayments cutover could not cancel a legacy subscription migrator.',
					array(
						'action_id' => $action['action_id'],
					)
				);
			}
		}

		return $outcomes;
	}

	/**
	 * Atomically cancel one unclaimed pending legacy migrator in the custom Action Scheduler table.
	 *
	 * @param int $action_id Action Scheduler action ID.
	 * @return bool|null True when canceled, false when claimed or changed, null on database failure.
	 */
	protected function cancel_pending_legacy_migrator( int $action_id ): ?bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->actionscheduler_actions} SET status = %s WHERE action_id = %d AND status = %s AND claim_id = %d",
				\ActionScheduler_Store::STATUS_CANCELED,
				$action_id,
				\ActionScheduler_Store::STATUS_PENDING,
				0
			)
		);
		if ( false === $updated ) {
			return null;
		}
		if ( 1 !== $updated ) {
			return false;
		}

		\ActionScheduler::store()->flush_caches();
		/**
		 * Fires after reconciliation atomically cancels one pending legacy migrator.
		 *
		 * @param int $action_id Action Scheduler action ID.
		 * @since 11.2.0
		 */
		do_action( 'action_scheduler_canceled_action', $action_id );
		return true;
	}

	/**
	 * Update the resolved active WooPayments plugin with WordPress core APIs.
	 *
	 * @return bool True only when WordPress reports a completed plugin upgrade.
	 */
	private function update_woopayments_plugin(): bool {
		$plugin_file = $this->preflight_service->get_active_woopayments_plugin_file();
		if ( '' === $plugin_file ) {
			$this->log_error( 'WooPayments cutover could not resolve the active plugin file for updating.' );
			return false;
		}

		try {
			if ( ! function_exists( 'wp_update_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			if ( ! class_exists( '\Plugin_Upgrader' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			}
			if ( ! class_exists( '\Automatic_Upgrader_Skin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php';
			}

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
				if ( false === $result || null === $result || is_wp_error( $result ) ) {
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
	 * @param string $plugin_file Active WooPayments plugin file.
	 * @return mixed WordPress upgrader result.
	 */
	protected function upgrade_woopayments_plugin_file( string $plugin_file ) {
		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		return $upgrader->upgrade( $plugin_file );
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
	 * Write a normalized cutover error to the WooCommerce logger.
	 *
	 * @param string              $message Error message.
	 * @param array<string,mixed> $context Normalized error context.
	 */
	protected function write_log_error( string $message, array $context ): void {
		wc_get_logger()->error( $message, $context );
	}

	/**
	 * Emit a best-effort Tracks diagnostic when the recorder is available.
	 *
	 * @param string $code Invalid filter condition code.
	 */
	protected function record_tracks_diagnostic( string $code ): void {
		if ( ! class_exists( '\WC_Tracks' ) || ! is_callable( array( '\WC_Tracks', 'record_event' ) ) ) {
			return;
		}

		try {
			\WC_Tracks::record_event( 'woocommerce_woopayments_cutover_diagnostic', array( 'condition' => $code ) );
		} catch ( \Throwable $error ) {
			return;
		}
	}

	/**
	 * Read the current validated state record.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_state_record(): ?array {
		return $this->state_store->get_record();
	}

	/**
	 * Repair missing actions and stale running records at bootstrap time.
	 */
	private function repair_schedule(): void {
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			return;
		}

		$now    = time();
		$record = $this->state_store->get_record();
		if (
			! is_array( $record )
			|| ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === $record['current_step'] )
			|| (
				! in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true )
				&& ( WooPaymentsCutoverState::RUNNING !== $record['state'] || $record['lease_expires_at'] > $now )
			)
		) {
			return;
		}

		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return;
		}

		try {
			$record = $this->state_store->get_record();
			if ( ! is_array( $record ) ) {
				return;
			}

			if ( WooPaymentsCutoverState::RUNNING === $record['state'] ) {
				if ( $record['lease_expires_at'] > $now ) {
					return;
				}

				$recovered                     = $record;
				$recovered['revision']         = $record['revision'] + 1;
				$recovered['state']            = WooPaymentsCutoverState::DEFERRED;
				$recovered['action_id']        = 0;
				$recovered['current_step']     = 'recovered_stale_running';
				$recovered['updated_at']       = $now;
				$recovered['next_attempt_at']  = $now;
				$recovered['lease_token']      = null;
				$recovered['lease_expires_at'] = null;
				$recovered                     = $this->append_step( $recovered, 'recovered_stale_running', $now );
				if ( ! $this->state_store->compare_and_set_record( $record, $recovered ) ) {
					return;
				}
				$record = $recovered;
			}

			if ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) && 'awaiting_merchant_start' !== $record['current_step'] ) {
				$this->ensure_record_scheduled( $record, $now );
			}
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Replace a queued attempt with an exact manual-deactivation origin and a fresh action identity.
	 *
	 * @param array<string,mixed> $record              Current pending or deferred record.
	 * @param string              $source              Trigger source.
	 * @param string              $origin_plugin_file  Exact deactivated plugin path.
	 * @param string              $origin_plugin_scope Site or network scope.
	 * @param int                 $now                 Current UTC timestamp.
	 * @return bool True when the replacement was stored and scheduled.
	 */
	private function replace_pending_with_manual_origin( array $record, string $source, string $origin_plugin_file, string $origin_plugin_scope, int $now ): bool {
		$old_attempt                         = $record['attempt'] + 1;
		$replacement                         = $record;
		$replacement['revision']             = $record['revision'] + 1;
		$replacement['attempt']              = $old_attempt;
		$replacement['action_id']            = 0;
		$replacement['current_step']         = 'queued';
		$replacement['updated_at']           = $now;
		$replacement['next_attempt_at']      = $now;
		$replacement['origin_plugin_file']   = $origin_plugin_file;
		$replacement['origin_plugin_scope']  = $origin_plugin_scope;
		$replacement['request_origin_token'] = null;
		$replacement                         = $this->append_step(
			$replacement,
			'manual_deactivation_recorded',
			$now,
			array(
				'source'      => $source,
				'plugin_file' => $origin_plugin_file,
				'scope'       => $origin_plugin_scope,
			)
		);
		if ( ! $this->state_store->compare_and_set_record( $record, $replacement ) ) {
			return false;
		}

		$this->scheduler->cancel( $record['generation'], $old_attempt );
		return $this->ensure_record_scheduled( $replacement, $now );
	}

	/**
	 * Fence a running worker and schedule a fresh attempt carrying exact manual-deactivation context.
	 *
	 * @param array<string,mixed> $record              Current running record.
	 * @param string              $source              Trigger source.
	 * @param string              $origin_plugin_file  Exact deactivated plugin path.
	 * @param string              $origin_plugin_scope Site or network scope.
	 * @param int                 $now                 Current UTC timestamp.
	 * @return bool True when the replacement was stored and scheduled.
	 */
	private function replace_running_with_manual_origin( array $record, string $source, string $origin_plugin_file, string $origin_plugin_scope, int $now ): bool {
		$replacement                         = $record;
		$replacement['revision']             = $record['revision'] + 1;
		$replacement['state']                = WooPaymentsCutoverState::PENDING;
		$replacement['action_id']            = 0;
		$replacement['current_step']         = 'queued';
		$replacement['updated_at']           = $now;
		$replacement['next_attempt_at']      = $now;
		$replacement['lease_token']          = null;
		$replacement['lease_expires_at']     = null;
		$replacement['origin_plugin_file']   = $origin_plugin_file;
		$replacement['origin_plugin_scope']  = $origin_plugin_scope;
		$replacement['request_origin_token'] = null;
		$replacement                         = $this->append_step(
			$replacement,
			'manual_deactivation_recorded',
			$now,
			array(
				'source'      => $source,
				'plugin_file' => $origin_plugin_file,
				'scope'       => $origin_plugin_scope,
			)
		);
		if ( ! $this->state_store->compare_and_set_record( $record, $replacement ) ) {
			return false;
		}

		$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
		return $this->ensure_record_scheduled( $replacement, $now );
	}

	/**
	 * Open a new generation after rollback or removal of a local exclusion marker.
	 *
	 * @param array<string,mixed> $record              Current terminal record.
	 * @param string              $source              Trigger source.
	 * @param string|null         $origin_plugin_file  Exact deactivated plugin path.
	 * @param string|null         $origin_plugin_scope Site or network scope.
	 * @param int                 $now                 Current UTC timestamp.
	 * @return bool True when the new generation was stored and scheduled.
	 */
	private function replace_terminal_with_pending( array $record, string $source, ?string $origin_plugin_file, ?string $origin_plugin_scope, int $now ): bool {
		$replacement                           = $this->create_pending_record( $source, $now, $origin_plugin_file, $origin_plugin_scope );
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
	 * @param array<string,mixed> $record Current terminal record.
	 * @param string              $source Supersession source.
	 * @param int                 $now    Current UTC timestamp.
	 * @return bool True when the awaiting generation was stored.
	 */
	private function replace_terminal_with_awaiting_start( array $record, string $source, int $now ): bool {
		$replacement                    = $this->create_pending_record( $source, $now );
		$replacement['generation']      = $record['generation'] + 1;
		$replacement['revision']        = $record['revision'] + 1;
		$replacement['current_step']    = 'awaiting_merchant_start';
		$replacement['network_cutover'] = true === ( $record['network_cutover'] ?? false );
		$replacement['step_log']        = array(
			array(
				'step'    => 'awaiting_merchant_start',
				'at'      => $now,
				'context' => array( 'source' => $source ),
			),
		);

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
		$queued['current_step'] = 'queued';
		$queued['updated_at']   = $now;
		$queued                 = $this->append_step( $queued, 'queued', $now, array( 'source' => $source ) );
		if ( ! $this->state_store->compare_and_set_record( $record, $queued ) ) {
			return false;
		}

		return $this->ensure_record_scheduled( $queued, $now );
	}

	/**
	 * Tell whether a terminal record is superseded by current local facts.
	 *
	 * @param array<string,mixed> $record Current terminal record.
	 * @return bool
	 */
	private function can_reopen_terminal_record( array $record ): bool {
		if ( WooPaymentsCutoverState::DONE === $record['state'] ) {
			try {
				return $this->arbiter->is_plugin_runtime_active();
			} catch ( \Throwable $error ) {
				return false;
			}
		}
		if ( WooPaymentsCutoverState::EXCLUDED !== $record['state'] ) {
			return false;
		}

		if ( true === ( $record['network_cutover'] ?? false ) && is_multisite() ) {
			return ! $this->network_has_stripe_billing_failure();
		}

		try {
			$failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
		} catch ( \Throwable $error ) {
			return false;
		}

		return ! in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true );
	}

	/**
	 * Tell whether the record's site or network currently has a Stripe Billing exclusion marker.
	 *
	 * @param array<string,mixed> $record Current record.
	 * @return bool
	 */
	private function record_has_stripe_billing_failure( array $record ): bool {
		if ( true === ( $record['network_cutover'] ?? false ) && is_multisite() ) {
			return $this->network_has_stripe_billing_failure();
		}

		try {
			$failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
		} catch ( \Throwable $error ) {
			return false;
		}

		return in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true );
	}

	/**
	 * Replace an unscheduled awaiting record with a local terminal exclusion.
	 *
	 * @param array<string,mixed> $record Current awaiting record.
	 * @param string              $code   Exclusion code.
	 */
	private function exclude_awaiting_record( array $record, string $code ): void {
		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return;
		}
		try {
			$current = $this->state_store->get_record();
			if ( ! is_array( $current ) || $current !== $record ) {
				return;
			}
			$excluded                     = $current;
			$excluded['revision']         = $current['revision'] + 1;
			$excluded['state']            = WooPaymentsCutoverState::EXCLUDED;
			$excluded['current_step']     = 'excluded';
			$excluded['updated_at']       = $now;
			$excluded['deferred_codes']   = array( $code );
			$excluded['next_attempt_at']  = null;
			$excluded['lease_token']      = null;
			$excluded['lease_expires_at'] = null;
			$excluded                     = $this->append_step( $excluded, 'excluded', $now, array( 'code' => $code ) );
			$this->state_store->compare_and_set_record( $current, $excluded );
		} finally {
			$this->state_store->release_lease( $token );
		}
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
				$failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
				if ( in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true ) ) {
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
	 * @param string      $source              Trigger source.
	 * @param int         $now                 Current UTC timestamp.
	 * @param string|null $origin_plugin_file  Exact plugin path for manual deactivation.
	 * @param string|null $origin_plugin_scope Site or network scope for manual deactivation.
	 * @return array<string,mixed>
	 */
	private function create_pending_record( string $source, int $now, ?string $origin_plugin_file = null, ?string $origin_plugin_scope = null ): array {
		return array(
			'schema_version'         => WooPaymentsCutoverStateStore::SCHEMA_VERSION,
			'generation'             => $this->state_store->get_next_generation(),
			'revision'               => 1,
			'state'                  => WooPaymentsCutoverState::PENDING,
			'started_at'             => $now,
			'updated_at'             => $now,
			'attempt'                => 0,
			'action_id'              => 0,
			'current_step'           => 'queued',
			'step_log'               => array(
				array(
					'step'    => 'queued',
					'at'      => $now,
					'context' => array( 'source' => $source ),
				),
			),
			'deferred_codes'         => array(),
			'informational_outcomes' => array(),
			'next_attempt_at'        => null,
			'lease_token'            => null,
			'lease_expires_at'       => null,
			'origin_plugin_file'     => $origin_plugin_file,
			'origin_plugin_scope'    => $origin_plugin_scope,
			'request_origin_token'   => null,
			'network_cutover'        => false,
		);
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
