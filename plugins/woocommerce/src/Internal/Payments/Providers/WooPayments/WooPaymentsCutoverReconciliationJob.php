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
	 * Initialize the job.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter      $arbiter     Runtime owner arbiter.
	 * @param WooPaymentsCutoverStateStore      $state_store Persisted state store.
	 * @param WooPaymentsCutoverActionScheduler $scheduler   Action Scheduler adapter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsCutoverStateStore $state_store, WooPaymentsCutoverActionScheduler $scheduler ): void {
		$this->arbiter     = $arbiter;
		$this->state_store = $state_store;
		$this->scheduler   = $scheduler;
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
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
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

		$now   = time();
		$token = $this->state_store->acquire_lease( $now );
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
			$this->state_store->compare_and_set_record( $record, $claimed );
		} finally {
			$this->state_store->release_lease( $token );
		}
	}

	/**
	 * Persist a deferred result and schedule its successor.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $expected_claim Exact running revision owned by this worker.
	 * @param array<int,string>   $codes          Remaining condition codes.
	 * @return bool True when the next attempt is scheduled.
	 */
	public function defer( array $expected_claim, array $codes ): bool {
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

			$deferred                     = $record;
			$deferred['revision']         = $record['revision'] + 1;
			$deferred['state']            = WooPaymentsCutoverState::DEFERRED;
			$deferred['action_id']        = 0;
			$deferred['current_step']     = 'deferred';
			$deferred['updated_at']       = $now;
			$deferred['deferred_codes']   = $this->normalize_codes( $codes );
			$deferred['next_attempt_at']  = $now + $this->get_retry_delay( $record, $now );
			$deferred['lease_token']      = null;
			$deferred['lease_expires_at'] = null;
			$deferred                     = $this->append_step( $deferred, 'deferred', $now, array( 'codes' => $deferred['deferred_codes'] ) );
			if ( ! $this->state_store->compare_and_set_record( $record, $deferred ) ) {
				return false;
			}

			return $this->ensure_record_scheduled( $deferred, $now );
		} finally {
			$this->state_store->release_lease( $token );
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
