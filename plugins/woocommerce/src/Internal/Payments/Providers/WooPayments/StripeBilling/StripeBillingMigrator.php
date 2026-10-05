<?php
/**
 * StripeBillingMigrator class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use RuntimeException;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Moves Stripe-billed subscriptions to on-site renewals with a saved token: cancels the Stripe subscription, keeps the
 * subscription renewing in WooCommerce and renames its Stripe Billing meta with a `_migrated` prefix.
 *
 * Port of client 11.1.0 `includes/subscriptions/class-wc-payments-subscriptions-migrator.php`, with its Action Scheduler
 * hooks, options and meta keys, so a migration started by the plugin finishes on native and the other way round.
 * WooCommerce Subscriptions' background repairer schedules and pages the work. The module builds the one instance with `new`
 * only when that class exists; it is never resolved from the container, which would call `init()`, the repairer's hook
 * registration, on every resolve.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingMigrator extends \WCS_Background_Repairer {

	/**
	 * Action Scheduler hook that finds the subscriptions to migrate and schedules one migration each.
	 */
	public const SCHEDULED_HOOK = 'wcpay_schedule_subscription_migrations';

	/**
	 * Action Scheduler hook that migrates one subscription; retries use it with a `_retry` suffix.
	 */
	public const MIGRATE_HOOK = 'wcpay_migrate_subscription';

	/**
	 * Option holding the start time of the current migration, which marks the subscriptions it migrated.
	 */
	public const MIGRATION_BATCH_OPTION = 'wcpay_subscription_migration_batch';

	/**
	 * Stripe subscription statuses that are cancelled at Stripe.
	 */
	private const ACTIVE_STATUSES = array( 'active', 'past_due', 'trialing', 'paused' );

	/**
	 * Stripe subscription statuses the migration log names; any other status Stripe returns is logged as `unknown`.
	 */
	private const LOGGABLE_STATUSES = array( 'active', 'canceled', 'incomplete', 'incomplete_expired', 'past_due', 'paused', 'trialing', 'unpaid' );

	/**
	 * Subscription meta renamed with a `_migrated` prefix.
	 */
	private const META_KEYS_TO_MIGRATE = array(
		StripeBillingSubscriptionService::SUBSCRIPTION_ID_META_KEY,
		StripeBillingInvoiceService::ORDER_INVOICE_ID_KEY,
		StripeBillingInvoiceService::PENDING_INVOICE_ID_KEY,
		StripeBillingSubscriptionService::SUBSCRIPTION_DISCOUNT_IDS_META_KEY,
	);

	/**
	 * Seconds to wait before each retry of a failed migration.
	 */
	private const RETRY_DELAYS = array( 60, 300, 600, 1800, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS );

	/**
	 * Most saved tokens of a customer looked through for the Stripe subscription's payment method.
	 */
	private const CUSTOMER_TOKENS_LIMIT = 100;

	/**
	 * Action Scheduler hook WooCommerce Subscriptions' background updater runs.
	 *
	 * @var string
	 */
	protected $scheduled_hook = self::SCHEDULED_HOOK;

	/**
	 * Migration log.
	 *
	 * @var StripeBillingMigrationLogHandler
	 */
	private StripeBillingMigrationLogHandler $migration_log;

	/**
	 * Build the migrator with its log.
	 */
	public function __construct() {
		$this->migration_log = new StripeBillingMigrationLogHandler();
	}

	/**
	 * Attach the migration hooks, and WooCommerce Subscriptions' background repairer hooks through `init()`.
	 */
	public function init_hooks(): void {
		$this->migration_log->init_hooks();

		// Migrated meta must not be copied to renewal, switch and resubscribe orders.
		add_filter( 'wc_subscriptions_object_data', array( $this, 'exclude_migrated_meta' ), 10, 1 );
		add_filter( 'woocommerce_debug_tools', array( $this, 'add_manual_migration_tool' ) );
		// The repairer only hooks the migration with one argument; retries carry the attempt as well.
		add_action( self::MIGRATE_HOOK . '_retry', array( $this, 'migrate_wcpay_subscription' ), 10, 2 );

		$this->init();
	}

	/**
	 * Set the migration hook, then let WooCommerce Subscriptions' background repairer attach its hooks.
	 *
	 * @internal
	 */
	final public function init(): void {
		$this->repair_hook = self::MIGRATE_HOOK;

		parent::init();
	}

	/**
	 * Migrate one Stripe-billed subscription, rescheduling it when it fails.
	 *
	 * @internal
	 *
	 * @param mixed $subscription_id Subscription ID.
	 * @param mixed $attempt         Attempts made so far.
	 */
	public function migrate_wcpay_subscription( $subscription_id, $attempt = 0 ): void {
		$subscription_id = absint( $subscription_id );
		$attempt         = absint( $attempt );

		try {
			add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'handle_unexpected_shutdown' ), 10, 2 );
			add_action( 'action_scheduler_failed_execution', array( $this, 'handle_unexpected_action_failure' ), 10, 2 );

			$this->migration_log->log( sprintf( 'Migrating subscription #%1$d.%2$s', $subscription_id, ( $attempt > 0 ? ' Attempt: ' . ( $attempt + 1 ) : '' ) ) );

			$subscription       = $this->validate_subscription_to_migrate( $subscription_id );
			$wcpay_subscription = $this->fetch_wcpay_subscription( $subscription );

			$this->maybe_cancel_wcpay_subscription( $wcpay_subscription );

			if ( $subscription->has_status( 'active' ) ) {
				$this->update_next_payment_date( $subscription, $wcpay_subscription );
			}

			// An active or on-hold subscription must keep a valid token to go on renewing.
			if ( $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
				$this->verify_subscription_payment_token( $subscription, $wcpay_subscription );
			}

			$this->update_wcpay_subscription_meta( $subscription );

			if ( WooPaymentsPersistenceProfile::GATEWAY_ID === $subscription->get_payment_method() ) {
				$subscription->add_order_note( __( 'This subscription has been successfully migrated to a WooPayments tokenized subscription.', 'woocommerce' ) );
			}

			$this->migration_log->log( sprintf( '---- Subscription #%d migration complete.', $subscription_id ) );
		} catch ( \Exception $e ) {
			$this->migration_log->log( $e->getMessage() );

			$this->maybe_reschedule_migration( $subscription_id, $attempt, $e );
		}

		remove_action( 'action_scheduler_unexpected_shutdown', array( $this, 'handle_unexpected_shutdown' ) );
		remove_action( 'action_scheduler_failed_execution', array( $this, 'handle_unexpected_action_failure' ) );
	}

	/**
	 * Keep migrated Stripe Billing meta off the orders WooCommerce Subscriptions creates from a subscription.
	 *
	 * @internal
	 *
	 * @param mixed $meta_data Meta data to copy.
	 * @return mixed
	 */
	public function exclude_migrated_meta( $meta_data ) {
		if ( ! is_array( $meta_data ) ) {
			return $meta_data;
		}

		foreach ( self::META_KEYS_TO_MIGRATE as $key ) {
			unset( $meta_data[ '_migrated' . $key ] );
		}

		return $meta_data;
	}

	/**
	 * Log a fatal error while a migration action ran, and reschedule the migration.
	 *
	 * @internal
	 *
	 * @param mixed $action_id Action Scheduler action ID.
	 * @param mixed $error     Error from `error_get_last()`.
	 */
	public function handle_unexpected_shutdown( $action_id, $error = null ): void {
		$migration_args = $this->get_migration_action_args( $action_id );
		if ( ! isset( $migration_args['migrate_subscription'], $migration_args['attempt'] ) ) {
			return;
		}

		if ( is_array( $error ) && ! empty( $error['type'] ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			// The client logs the fatal's message; native leaves it out, since an uncaught exception's message can be the platform's text.
			$this->migration_log->log( sprintf( '---- ERROR: Unexpected shutdown while migrating subscription #%1$d: PHP error type %2$d in %3$s on line %4$s.', $migration_args['migrate_subscription'], (int) $error['type'], $error['file'] ?? 'no file found', $error['line'] ?? '0' ) );
		}

		$this->maybe_reschedule_migration( $migration_args['migrate_subscription'], $migration_args['attempt'] );
	}

	/**
	 * Log an exception a migration action threw, and reschedule the migration.
	 *
	 * @internal
	 *
	 * @param mixed $action_id Action Scheduler action ID.
	 * @param mixed $exception Exception thrown.
	 */
	public function handle_unexpected_action_failure( $action_id, $exception ): void {
		$migration_args = $this->get_migration_action_args( $action_id );
		if ( ! isset( $migration_args['migrate_subscription'], $migration_args['attempt'] ) ) {
			return;
		}

		$this->migration_log->log( sprintf( '---- ERROR: Unexpected failure while migrating subscription #%1$d: %2$s', $migration_args['migrate_subscription'], $exception instanceof \Throwable ? $exception->getMessage() : '' ) );
		$this->maybe_reschedule_migration( $migration_args['migrate_subscription'], $migration_args['attempt'] );
	}

	/**
	 * Add "Migrate Stripe Billing subscriptions" to WooCommerce > Status > Tools while Stripe-billed subscriptions remain.
	 *
	 * Hidden on stores using the WooPayments bundled subscriptions and while WooCommerce Subscriptions is inactive.
	 *
	 * @internal
	 *
	 * @param mixed $tools WooCommerce debug tools.
	 * @return mixed
	 */
	public function add_manual_migration_tool( $tools ) {
		if ( ! is_array( $tools ) || '1' === get_option( '_wcpay_feature_subscriptions', '0' ) || ! WooPaymentsStripeBillingModule::is_woocommerce_subscriptions_active() ) {
			return $tools;
		}

		$wcpay_subscriptions_count = $this->get_subscription_service()->get_stripe_billing_subscription_count();
		if ( $wcpay_subscriptions_count < 1 ) {
			return $tools;
		}

		$disabled = $this->is_migrating();

		$tools['migrate_wcpay_subscriptions'] = array(
			'name'             => __( 'Migrate Stripe Billing subscriptions', 'woocommerce' ),
			'button'           => $disabled ? __( 'Migration in progress', 'woocommerce' ) . '&#8230;' : __( 'Migrate Subscriptions', 'woocommerce' ),
			'desc'             => sprintf(
				// translators: %1$s is a line break and %2$d is the number of subscriptions.
				__( 'This tool will migrate all Stripe Billing subscriptions to tokenized subscriptions with WooPayments.%1$sNumber of Stripe Billing subscriptions found: %2$d', 'woocommerce' ),
				'<br>',
				$wcpay_subscriptions_count
			),
			'callback'         => array( $this, 'schedule_migrate_wcpay_subscriptions_action' ),
			'disabled'         => $disabled,
			'requires_refresh' => true,
		);

		return $tools;
	}

	/**
	 * Start a migration, unless one is already scheduled.
	 */
	public function schedule_migrate_wcpay_subscriptions_action(): void {
		if ( as_next_scheduled_action( self::SCHEDULED_HOOK ) ) {
			return;
		}

		update_option( self::MIGRATION_BATCH_OPTION, time() );

		$this->migration_log->log( 'Started scheduling subscription migrations.' );
		$this->schedule_repair();
	}

	/**
	 * Schedule a retry of a failed migration with a growing delay, up to seven retries over about 20 hours.
	 *
	 * A migration skipped on purpose is not retried. After the last retry, the exception passed in is rethrown.
	 *
	 * @param int             $subscription_id Subscription ID.
	 * @param int             $attempt         Attempts made so far.
	 * @param \Exception|null $exception       Exception the migration threw.
	 * @throws \Exception The exception passed in, after the last retry.
	 */
	public function maybe_reschedule_migration( int $subscription_id, int $attempt = 0, ?\Exception $exception = null ): void {
		if ( $exception && false !== strpos( $exception->getMessage(), 'Skipping migration' ) ) {
			return;
		}

		if ( isset( self::RETRY_DELAYS[ $attempt ] ) ) {
			$this->migration_log->log( sprintf( '---- Rescheduling migration of subscription #%1$d.', $subscription_id ) );

			as_schedule_single_action(
				time() + self::RETRY_DELAYS[ $attempt ],
				self::MIGRATE_HOOK . '_retry',
				array(
					'migrate_subscription' => $subscription_id,
					'attempt'              => $attempt + 1,
				)
			);

			return;
		}

		$this->migration_log->log( sprintf( '---- FAILED: Subscription #%d could not be migrated.', $subscription_id ) );

		if ( $exception ) {
			// Action Scheduler logs the rethrown exception; this handler must not log and reschedule it again.
			remove_action( 'action_scheduler_failed_execution', array( $this, 'handle_unexpected_action_failure' ) );

			throw $exception;
		}
	}

	/**
	 * Tell whether a migration is running: its scheduling action, a migration or a retry is pending.
	 *
	 * @return bool
	 */
	public function is_migrating(): bool {
		return (bool) as_next_scheduled_action( self::SCHEDULED_HOOK ) || (bool) as_next_scheduled_action( self::MIGRATE_HOOK ) || (bool) as_next_scheduled_action( self::MIGRATE_HOOK . '_retry' );
	}

	/**
	 * Schedule the migration of one subscription a minute from now, unless it is already scheduled.
	 *
	 * The repairer schedules it an hour out and does not check for a pending one.
	 *
	 * @param mixed $item Subscription ID.
	 */
	public function update_item( $item ): void {
		if ( ! as_next_scheduled_action( self::MIGRATE_HOOK, array( 'migrate_subscription' => $item ) ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::MIGRATE_HOOK, array( 'migrate_subscription' => $item ) );
		}

		unset( $this->items_to_repair[ $item ] );
	}

	/**
	 * Migrate the subscription of a scheduled migration action.
	 *
	 * @param mixed $item Subscription ID.
	 */
	public function repair_item( $item ): void {
		$this->migrate_wcpay_subscription( $item );
	}

	/**
	 * Get a page of 100 subscriptions to migrate.
	 *
	 * Subscriptions this migration already moved stay in the results through their `_wcpay_subscription_migrated_during`
	 * meta, so later pages do not shift.
	 *
	 * @param mixed $page Page number.
	 * @return int[]
	 */
	public function get_items_to_repair( $page ): array {
		$items_to_migrate = function_exists( 'wcs_get_orders_with_meta_query' ) ? wcs_get_orders_with_meta_query(
			array(
				'return'     => 'ids',
				'type'       => 'shop_subscription',
				'limit'      => 100,
				'status'     => 'any',
				'paged'      => $page,
				'order'      => 'ASC',
				'orderby'    => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => StripeBillingSubscriptionService::SUBSCRIPTION_ID_META_KEY,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_wcpay_subscription_migrated_during',
						'value'   => get_option( self::MIGRATION_BATCH_OPTION, 0 ),
						'compare' => '=',
					),
				),
			)
		) : array();

		if ( empty( $items_to_migrate ) ) {
			$this->migration_log->log( 'Finished scheduling subscription migrations.' );
		}

		return is_array( $items_to_migrate ) ? array_map( 'absint', $items_to_migrate ) : array();
	}

	/**
	 * Clear the migration's batch marker once every subscription has been scheduled.
	 */
	protected function unschedule_background_updates(): void {
		parent::unschedule_background_updates();

		delete_option( self::MIGRATION_BATCH_OPTION );
	}

	/**
	 * Get the subscription to migrate, or skip it: WooCommerce Subscriptions inactive, staging copy, not found or already migrated.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return WC_Order
	 * @throws RuntimeException When the migration is skipped.
	 */
	private function validate_subscription_to_migrate( int $subscription_id ): WC_Order {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go to the migration log, not to output.
		if ( ! WooPaymentsStripeBillingModule::is_woocommerce_subscriptions_active() || ! function_exists( 'wcs_get_subscription' ) ) {
			throw new RuntimeException( sprintf( '---- Skipping migration of subscription #%d. The WooCommerce Subscriptions extension is not active.', $subscription_id ) );
		}

		if ( WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
			throw new RuntimeException( sprintf( '---- Skipping migration of subscription #%d. Site is in staging mode.', $subscription_id ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Order ) {
			throw new RuntimeException( sprintf( '---- Skipping migration of subscription #%d. Subscription not found.', $subscription_id ) );
		}

		$migrated_wcpay_subscription_id = $subscription->get_meta( StripeBillingSubscriptionService::MIGRATED_SUBSCRIPTION_ID_META_KEY, true );
		if ( ! empty( $migrated_wcpay_subscription_id ) ) {
			throw new RuntimeException( sprintf( '---- Skipping migration of subscription #%1$d (%2$s). Subscription has already been migrated.', $subscription_id, $migrated_wcpay_subscription_id ) );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $subscription;
	}

	/**
	 * Fetch the Stripe subscription of a subscription and check it has an ID and a status.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the subscription has no Stripe subscription, or it cannot be fetched or is invalid.
	 */
	private function fetch_wcpay_subscription( WC_Order $subscription ): array {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go to the migration log, not to output.
		$wcpay_subscription_id = $this->get_subscription_service()->get_wcpay_subscription_id( $subscription );
		if ( ! $wcpay_subscription_id ) {
			throw new RuntimeException( sprintf( '---- Skipping migration of subscription #%d. Subscription is not a WCPay Subscription.', $subscription->get_id() ) );
		}

		try {
			$wcpay_subscription = $this->get_api()->get_subscription( $wcpay_subscription_id );
		} catch ( WooPaymentsApiException $e ) {
			throw new RuntimeException( sprintf( '---- ERROR: Failed to fetch subscription #%1$d (%2$s) from Stripe. %3$s', $subscription->get_id(), $wcpay_subscription_id, $this->describe_failure( $e ) ) );
		}

		if ( empty( $wcpay_subscription['id'] ) || empty( $wcpay_subscription['status'] ) ) {
			// The client logs the whole fetched subscription; native leaves the platform's body out of the log.
			throw new RuntimeException( sprintf( '---- ERROR: Cannot migrate subscription #%1$d (%2$s). Invalid data fetched from Stripe: the subscription has no ID or status.', $subscription->get_id(), $wcpay_subscription_id ) );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $wcpay_subscription;
	}

	/**
	 * Cancel the Stripe subscription when it can still bill: active, past due, trialing or paused.
	 *
	 * @param array<string,mixed> $wcpay_subscription Stripe subscription.
	 * @throws RuntimeException When the cancellation fails.
	 */
	private function maybe_cancel_wcpay_subscription( array $wcpay_subscription ): void {
		if ( ! in_array( $wcpay_subscription['status'], self::ACTIVE_STATUSES, true ) ) {
			$this->migration_log->log( sprintf( '---- Stripe subscription (%1$s) has "%2$s" status. Skipping canceling the subscription at Stripe.', $wcpay_subscription['id'], $this->get_wcpay_subscription_status( $wcpay_subscription ) ) );
			return;
		}

		$this->migration_log->log( sprintf( '---- Stripe subscription (%1$s) has "%2$s" status. Canceling the subscription.', $wcpay_subscription['id'], $this->get_wcpay_subscription_status( $wcpay_subscription ) ) );

		try {
			$wcpay_subscription = $this->get_api()->cancel_subscription( (string) $wcpay_subscription['id'] );
		} catch ( WooPaymentsApiException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message goes to the migration log, not to output.
			throw new RuntimeException( sprintf( '---- ERROR: Failed to cancel the Stripe subscription (%1$s). %2$s', $wcpay_subscription['id'], $this->describe_failure( $e ) ) );
		}

		$this->migration_log->log( sprintf( '---- Stripe subscription (%1$s) successfully canceled.', $wcpay_subscription['id'] ?? '' ) );
	}

	/**
	 * Rename the Stripe Billing meta of a subscription with a `_migrated` prefix, and mark it as moved by this migration.
	 *
	 * @param WC_Order $subscription Subscription.
	 */
	private function update_wcpay_subscription_meta( WC_Order $subscription ): void {
		$updated = false;

		// A subscription moved while the migration is still paging stays in its query results, so later pages do not shift.
		$migration_start = get_option( self::MIGRATION_BATCH_OPTION, 0 );
		if ( 0 !== $migration_start ) {
			$subscription->update_meta_data( '_wcpay_subscription_migrated_during', $migration_start );
			$updated = true;
		}

		foreach ( self::META_KEYS_TO_MIGRATE as $meta_key ) {
			if ( $subscription->meta_exists( $meta_key ) ) {
				$subscription->update_meta_data( '_migrated' . $meta_key, $subscription->get_meta( $meta_key, true ) );
				$subscription->delete_meta_data( $meta_key );
				$updated = true;
			}
		}

		if ( $updated ) {
			$subscription->save();
		}
	}

	/**
	 * Move the next payment date so the subscription has a renewal scheduled in WooCommerce.
	 *
	 * The new date is the first of: the next payment date one second later when it is in the future; the Stripe
	 * subscription's `current_period_end` when the subscription still pays with WooPayments and that is in the future; a
	 * date WooCommerce Subscriptions calculates, when it is in the future.
	 *
	 * @param WC_Order            $subscription       Subscription.
	 * @param array<string,mixed> $wcpay_subscription Stripe subscription.
	 */
	private function update_next_payment_date( WC_Order $subscription, array $wcpay_subscription ): void {
		if ( ! is_callable( array( $subscription, 'get_time' ) ) || ! is_callable( array( $subscription, 'update_dates' ) ) ) {
			return;
		}

		try {
			if ( $subscription->get_time( 'next_payment' ) > time() ) {
				$new_next_payment = gmdate( 'Y-m-d H:i:s', $subscription->get_time( 'next_payment' ) + 1 );
				$subscription->update_dates( array( 'next_payment' => $new_next_payment ) );
				$this->migration_log->log( sprintf( '---- Next payment date updated to %1$s to ensure subscription #%2$d has a pending scheduled payment.', $new_next_payment, $subscription->get_id() ) );

				return;
			}

			$current_period_end = isset( $wcpay_subscription['current_period_end'] ) && is_numeric( $wcpay_subscription['current_period_end'] ) ? absint( $wcpay_subscription['current_period_end'] ) : 0;
			if ( WooPaymentsPersistenceProfile::GATEWAY_ID === $subscription->get_payment_method() && $current_period_end > time() ) {
				$new_next_payment = gmdate( 'Y-m-d H:i:s', $current_period_end );
				$subscription->update_dates( array( 'next_payment' => $new_next_payment ) );
				$this->migration_log->log( sprintf( '---- Next payment date updated to %1$s to match Stripe subscription record and to ensure subscription #%2$d has a pending scheduled payment.', $new_next_payment, $subscription->get_id() ) );

				return;
			}

			$new_next_payment = is_callable( array( $subscription, 'calculate_date' ) ) ? (string) $subscription->calculate_date( 'next_payment' ) : '';
			if ( function_exists( 'wcs_date_to_time' ) && wcs_date_to_time( $new_next_payment ) > time() ) {
				$subscription->update_dates( array( 'next_payment' => $new_next_payment ) );
				$this->migration_log->log( sprintf( '---- Calculated a new next payment date (%1$s) to ensure subscription #%2$d has a pending scheduled payment in the future.', $new_next_payment, $subscription->get_id() ) );

				return;
			}

			$this->migration_log->log(
				sprintf(
					'---- ERROR: Failed to update subscription #%1$d next payment date. Current next payment date (%2$s) is in the past, Stripe "current_period_end" data is invalid (%3$s) and an attempt to calculate a new date also failed (%4$s).',
					$subscription->get_id(),
					gmdate( 'Y-m-d H:i:s', $subscription->get_time( 'next_payment' ) ),
					isset( $wcpay_subscription['current_period_end'] ) ? gmdate( 'Y-m-d H:i:s', $current_period_end ) : 'no data',
					$new_next_payment
				)
			);
		} catch ( \Exception $e ) {
			$this->migration_log->log( sprintf( '---- ERROR: Failed to update subscription #%1$d next payment date. %2$s', $subscription->get_id(), $e->getMessage() ) );
		}
	}

	/**
	 * Get the Stripe subscription status for the log; an active subscription whose collection is paused reads `paused`,
	 * and a status Stripe does not document reads `unknown`.
	 *
	 * A subscription put on hold in WooCommerce stays active at Stripe with its collection paused.
	 *
	 * @param array<string,mixed> $wcpay_subscription Stripe subscription.
	 * @return string
	 */
	private function get_wcpay_subscription_status( array $wcpay_subscription ): string {
		if ( empty( $wcpay_subscription['status'] ) ) {
			return 'unknown';
		}

		if ( 'active' === $wcpay_subscription['status'] && 'void' === ( $wcpay_subscription['pause_collection']['behavior'] ?? '' ) ) {
			return 'paused';
		}

		return in_array( $wcpay_subscription['status'], self::LOGGABLE_STATUSES, true ) ? $wcpay_subscription['status'] : 'unknown';
	}

	/**
	 * Describe a failure for the migration log without its message: a platform error by its HTTP status and listed
	 * code, anything else by its class. The client logs the message, which the platform writes.
	 *
	 * @param \Throwable $failure Failure.
	 * @return string
	 */
	private function describe_failure( \Throwable $failure ): string {
		$context = WooPaymentsLogger::get_api_error_context( $failure );
		if ( array() === $context ) {
			return sprintf( 'Failure: %s.', get_class( $failure ) );
		}

		return sprintf( 'Platform error: HTTP status %1$d, error code %2$s.', $context['http_status'], $context['error_code'] );
	}

	/**
	 * Make sure a subscription paying with WooPayments has the Stripe subscription's default payment method as its token.
	 *
	 * @param WC_Order            $subscription       Subscription.
	 * @param array<string,mixed> $wcpay_subscription Stripe subscription.
	 */
	private function verify_subscription_payment_token( WC_Order $subscription, array $wcpay_subscription ): void {
		if ( WooPaymentsPersistenceProfile::GATEWAY_ID !== $subscription->get_payment_method() ) {
			$this->migration_log->log( sprintf( '---- Skipped verifying the payment token. Subscription #%1$d has "%2$s" as the payment method.', $subscription->get_id(), $subscription->get_payment_method() ) );
			return;
		}

		$default_payment_method = isset( $wcpay_subscription['default_payment_method'] ) && is_string( $wcpay_subscription['default_payment_method'] ) ? $wcpay_subscription['default_payment_method'] : '';
		if ( '' === $default_payment_method ) {
			$this->migration_log->log( sprintf( '---- Could not verify the payment method. Stripe Billing subscription (%1$s) does not have a default payment method.', $wcpay_subscription['id'] ?? 'unknown' ) );
			return;
		}

		$tokens   = $subscription->get_payment_tokens();
		$token_id = end( $tokens );
		$token    = $token_id ? WC_Payment_Tokens::get( $token_id ) : null;

		if ( $token && $token->get_token() === $default_payment_method ) {
			$this->migration_log->log( sprintf( '---- Payment token on subscription #%1$d matches the payment method on the Stripe Billing subscription (%2$s).', $subscription->get_id(), $wcpay_subscription['id'] ?? 'unknown' ) );
			return;
		}

		if ( $this->maybe_create_and_update_payment_token( $subscription, $default_payment_method ) ) {
			$this->migration_log->log( sprintf( '---- Payment token on subscription #%1$d has been updated (from %2$s to %3$s) to match the payment method on the Stripe Billing subscription.', $subscription->get_id(), $token ? $token->get_token() : 'missing', $default_payment_method ) );
		}
	}

	/**
	 * Find the customer's token for a payment method, or save one, and set it on the subscription without updating Stripe.
	 *
	 * @param WC_Order $subscription      Subscription.
	 * @param string   $payment_method_id Stripe payment method ID.
	 * @return WC_Payment_Token|null The token, or null when none could be saved.
	 */
	private function maybe_create_and_update_payment_token( WC_Order $subscription, string $payment_method_id ): ?WC_Payment_Token {
		$token   = null;
		$user_id = $subscription->get_user_id();

		$customer_tokens = WC_Payment_Tokens::get_tokens(
			array(
				'user_id'    => $user_id,
				'gateway_id' => WooPaymentsPersistenceProfile::GATEWAY_ID,
				'limit'      => self::CUSTOMER_TOKENS_LIMIT,
			)
		);
		foreach ( $customer_tokens as $customer_token ) {
			if ( $customer_token->get_token() === $payment_method_id ) {
				$token = $customer_token;
				break;
			}
		}

		if ( ! $token ) {
			try {
				$token = wc_get_container()->get( WooPaymentsTokenService::class )->get_or_create_token_for_user( $payment_method_id, $user_id );
				$error = $token ? '' : 'The payment method could not be saved.';
			} catch ( \Exception $e ) {
				$token = null;
				$error = $this->describe_failure( $e );
			}

			if ( ! $token ) {
				$this->migration_log->log( sprintf( '---- WARNING: Subscription #%1$d is missing a payment token and we failed to create one. Error: %2$s', $subscription->get_id(), $error ) );
				return null;
			}

			$this->migration_log->log( sprintf( '---- Created a new payment token (%1$s) for subscription #%2$d.', $token->get_token(), $subscription->get_id() ) );
		}

		// The Stripe subscription was just cancelled, so the new token must not be sent to it.
		$this->get_subscription_service()->run_without_payment_method_sync(
			static function () use ( $subscription, $token ) {
				$subscription->add_payment_token( $token );
			}
		);

		return $token;
	}

	/**
	 * Get the subscription ID and attempt from a migration action, or nothing for another action.
	 *
	 * @param mixed $action_id Action Scheduler action ID.
	 * @return array{migrate_subscription?:int,attempt?:int}
	 */
	private function get_migration_action_args( $action_id ): array {
		$action = ActionScheduler_Store::instance()->fetch_action( (string) $action_id );

		if ( ! in_array( $action->get_hook(), array( self::MIGRATE_HOOK, self::MIGRATE_HOOK . '_retry' ), true ) ) {
			return array();
		}

		$action_args = $action->get_args();
		if ( ! isset( $action_args['migrate_subscription'] ) ) {
			return array();
		}

		return array(
			'migrate_subscription' => absint( $action_args['migrate_subscription'] ),
			'attempt'              => absint( $action_args['attempt'] ?? 0 ),
		);
	}

	/**
	 * Get the Stripe Billing platform calls.
	 *
	 * @return StripeBillingApi
	 */
	private function get_api(): StripeBillingApi {
		return wc_get_container()->get( StripeBillingApi::class );
	}

	/**
	 * Get the subscription service.
	 *
	 * @return StripeBillingSubscriptionService
	 */
	private function get_subscription_service(): StripeBillingSubscriptionService {
		return wc_get_container()->get( StripeBillingSubscriptionService::class );
	}
}
