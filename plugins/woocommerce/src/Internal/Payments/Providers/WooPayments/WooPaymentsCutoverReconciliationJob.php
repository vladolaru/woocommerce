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
	 * Initialize the job.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter       $arbiter          Runtime owner arbiter.
	 * @param WooPaymentsCutoverStateStore       $state_store      Persisted state store.
	 * @param WooPaymentsCutoverActionScheduler  $scheduler        Action Scheduler adapter.
	 * @param WooPaymentsCutoverPreflightService $preflight_service Headless cutover facts.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsCutoverStateStore $state_store, WooPaymentsCutoverActionScheduler $scheduler, WooPaymentsCutoverPreflightService $preflight_service ): void {
		$this->arbiter           = $arbiter;
		$this->state_store       = $state_store;
		$this->scheduler         = $scheduler;
		$this->preflight_service = $preflight_service;
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
		if ( ! isset( $this->preflight_service ) || ! $this->arbiter->is_native_runtime_enabled() ) {
			return false;
		}

		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
		if ( null === $token ) {
			return false;
		}

		try {
			$record = $this->state_store->get_record();
			if ( is_array( $record ) ) {
				if ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
					return $this->ensure_record_scheduled( $record, $now );
				}

				return true;
			}

			$record = $this->create_pending_record( $source, $now );
			if ( ! $this->state_store->save_record( $record ) ) {
				return false;
			}

			return $this->ensure_record_scheduled( $record, $now );
		} finally {
			$this->state_store->release_lease( $token );
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

			$claimed                     = $record;
			$claimed['revision']         = $record['revision'] + 1;
			$claimed['state']            = WooPaymentsCutoverState::RUNNING;
			$claimed['attempt']          = $attempt;
			$claimed['action_id']        = 0;
			$claimed['current_step']     = 'running';
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
			$this->reconcile_claim( $claimed );
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
	 * Task 4 owns all-clear finalization. This task resolves or defers every
	 * condition without allowing a worker to remain in the running state.
	 *
	 * @param array<string,mixed> $claimed Exact running state owned by this worker.
	 */
	private function reconcile_claim( array $claimed ): void {
		try {
			$failures = $this->normalize_codes( $this->preflight_service->get_reconciliation_failures() );
			if ( in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true ) ) {
				$this->exclude( $claimed, 'legacy_stripe_billing_subscriptions_present' );
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
				$this->exclude( $claimed, 'legacy_stripe_billing_subscriptions_present' );
				return;
			}
			if ( $plugin_update_succeeded && in_array( 'woopayments_plugin_version_unsupported', $remaining_failures, true ) ) {
				$this->log_error( 'WooPayments cutover plugin update completed without installing a supported version.' );
			}

			$this->defer( $claimed, array() === $remaining_failures ? array( 'finalization_pending' ) : $remaining_failures, $outcomes );
		} catch ( \Throwable $error ) {
			$this->log_error( 'WooPayments cutover reconciliation resolver failed.', array( 'error' => $error->getMessage() ) );
			$this->defer( $claimed, array( 'reconciliation_resolver_failed' ) );
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
			$this->state_store->compare_and_set_record( $record, $excluded );
			$this->scheduler->cancel( $record['generation'], $record['attempt'] + 1 );
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

			if ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
				$this->ensure_record_scheduled( $record, $now );
			}
		} finally {
			$this->state_store->release_lease( $token );
		}
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
			'origin_plugin_file'     => null,
			'origin_plugin_scope'    => null,
			'request_origin_token'   => null,
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
			$timestamp = WooPaymentsCutoverState::DEFERRED === $record['state'] ? max( $now, $record['next_attempt_at'] ) : $now;
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
