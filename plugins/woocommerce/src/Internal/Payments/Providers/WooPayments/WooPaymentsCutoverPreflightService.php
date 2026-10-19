<?php
/**
 * WooPaymentsCutoverPreflightService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Provides headless facts for WooPayments cutover reconciliation.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverPreflightService {

	public const MINIMUM_CUTOVER_PLUGIN_VERSION = '10.5.0';

	/** The WooPayments feature is off on this site, so no other check runs. */
	public const FAILURE_BUILTIN_RUNTIME_DISABLED = 'builtin_runtime_disabled';

	/** The recorded WooPayments extension version is below MINIMUM_CUTOVER_PLUGIN_VERSION. */
	public const FAILURE_PLUGIN_VERSION_UNSUPPORTED = 'woopayments_plugin_version_unsupported';

	/** The built-in WooPayments cannot process payments for this account yet. */
	public const FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE = 'builtin_transport_unavailable';

	/** A currency on automatic exchange rates has no rate provider. */
	public const FAILURE_MULTI_CURRENCY_RATES_UNAVAILABLE = 'multi_currency_rates_unavailable';

	/** A queued WooPayments action has no built-in handler. */
	public const FAILURE_QUEUE_HOOKS_UNHANDLED = 'operational_queue_hooks_unhandled';

	/** The canceled-authorization fee remediation cannot be scheduled; the stored code keeps its first name. */
	public const FAILURE_FEE_REMEDIATION_UNAVAILABLE = 'financial_migrations_unavailable';

	/** The store bills subscriptions through the WooPayments extension's bundled subscriptions; such a store is excluded. */
	public const FAILURE_BUNDLED_SUBSCRIPTIONS = 'bundled_stripe_billing_subscriptions_present';

	private const WOOPAYMENTS_VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	private const IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK = 'woocommerce_woopayments_identifier_placeholders_unavailable';

	private const OPERATIONAL_QUEUE_QUERY_FAILED_HOOK = 'woocommerce_woopayments_operational_queue_query_failed';


	/**
	 * Action Scheduler hooks the built-in WooPayments handles, so a queued action on one does not block the cutover.
	 *
	 * The reconciliation job's ADOPTED_QUEUE_HOOKS lists the ones it records as carried over.
	 *
	 * @var string[]
	 */
	private const BUILTIN_HANDLED_QUEUE_HOOKS = array(
		WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION,
		WooPaymentsTokenService::UPDATE_SAVED_PAYMENT_METHOD_ACTION,
		WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
		WooPaymentsStoreSetupReportController::UPDATE_COMPATIBILITY_DATA_ACTION,
		WooPaymentsAccountMilestonesController::INSTANT_DEPOSIT_REMINDER_ACTION,
		WooPaymentsAccountMilestonesController::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION,
		WooPaymentsFraudOrderTrackingService::TRACK_NEW_ORDER_ACTION,
		WooPaymentsFraudOrderTrackingService::TRACK_UPDATE_ORDER_ACTION,
		WooPaymentsApplePayDomainService::RETRY_ACTION,
		WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK,
		WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK,
		WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_AFFECTED_ORDERS_HOOK,
		WooPaymentsWebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION,
		WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
		// Stripe Billing migration off Stripe, continued by native's migrator after the switch.
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
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Native WooPayments provider.
	 *
	 * @var WooPaymentsProvider|null
	 */
	private ?WooPaymentsProvider $provider = null;

	/**
	 * Legacy subscription data guard.
	 *
	 * @var WooPaymentsLegacySubscriptionsGuard|null
	 */
	private ?WooPaymentsLegacySubscriptionsGuard $legacy_subscriptions_guard = null;

	/**
	 * Fee remediation owner.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService|null
	 */
	private ?WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service = null;

	/**
	 * Platform connection readiness service.
	 *
	 * @var WooPaymentsPlatformConnectionService|null
	 */
	private ?WooPaymentsPlatformConnectionService $platform_connection_service = null;

	/**
	 * Native rate account boundary.
	 *
	 * @var WooPaymentsNativeAccountAdapter|null
	 */
	private ?WooPaymentsNativeAccountAdapter $native_rate_account = null;

	/**
	 * Native rate API client boundary.
	 *
	 * @var WooPaymentsNativeApiClientAdapter|null
	 */
	private ?WooPaymentsNativeApiClientAdapter $native_rate_api_client = null;

	/**
	 * Request-local reconciliation failures keyed by blog ID.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $reconciliation_memo = array();

	/**
	 * Initialize the service instance.
	 *
	 * The check collaborators are resolved when a preflight runs, so registering the cutover surfaces loads none of them.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter      Runtime owner arbiter.
	 * @param LegacyProxy               $legacy_proxy Legacy proxy.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy ): void {
		$this->arbiter      = $arbiter;
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Invalidate request-local facts for the current blog.
	 */
	public function invalidate_current_blog_memoization(): void {
		$blog_id = get_current_blog_id();
		unset( $this->reconciliation_memo[ $blog_id ] );
	}

	/**
	 * Get the conditions that block the cutover on this site.
	 *
	 * @return string[] Failure codes.
	 */
	public function get_failures(): array {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->reconciliation_memo ) ) {
			$this->reconciliation_memo[ $blog_id ] = $this->evaluate_failures();
		}
		return $this->reconciliation_memo[ $blog_id ];
	}

	/**
	 * Determine whether a saved connection owner no longer maps to a WordPress user.
	 *
	 * A missing owner ID is a separate preflight condition and does not qualify for the deleted-owner informational outcome.
	 *
	 * @return bool
	 */
	public function is_cutover_connection_owner_user_missing(): bool {
		$status = $this->get_platform_connection_service()->get_cutover_connection_owner_user_token_status();

		return $status['owner_id'] > 0 && ! $status['owner_exists'];
	}

	/**
	 * Evaluate the cutover failures for the current site.
	 *
	 * @return string[] Failure codes.
	 */
	private function evaluate_failures(): array {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return array( self::FAILURE_BUILTIN_RUNTIME_DISABLED );
		}

		$failures = array();
		if ( ! $this->is_woopayments_plugin_version_supported() ) {
			$failures[] = self::FAILURE_PLUGIN_VERSION_UNSUPPORTED;
		}
		if ( ! $this->is_native_transport_ready() ) {
			$failures[] = self::FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE;
		}
		$failures = array_merge( $failures, $this->get_platform_connection_service()->get_cutover_preflight_failures() );
		if ( $this->has_unavailable_multi_currency_rate_provider() ) {
			$failures[] = self::FAILURE_MULTI_CURRENCY_RATES_UNAVAILABLE;
		}
		if ( array() !== $this->get_unhandled_queue_hooks() ) {
			$failures[] = self::FAILURE_QUEUE_HOOKS_UNHANDLED;
		}
		if ( ! $this->get_fee_remediation_service()->can_schedule_cutover_remediation() ) {
			$failures[] = self::FAILURE_FEE_REMEDIATION_UNAVAILABLE;
		}
		if ( $this->get_legacy_subscriptions_guard()->is_bundled_stripe_billing_store() ) {
			$failures[] = self::FAILURE_BUNDLED_SUBSCRIPTIONS;
		}

		return self::normalize_string_list( $failures );
	}

	/**
	 * Get pending or running WooPayments Action Scheduler actions (wcpay_ and woocommerce_woopayments_ hooks), one per hook and group.
	 *
	 * Callers only decide by hook and group, so a queue of thousands of same-hook actions is read as one row. On a database
	 * without identifier placeholders or a failed query, returns one sentinel row (action_id 0) whose hook is one of the
	 * *_HOOK constants, which blocks the switch.
	 *
	 * @return array<int,array{action_id:int,hook:string,group:string}> The lowest action ID of each hook and group, ordered by it.
	 */
	public function get_queued_woopayments_actions(): array {
		$wpdb = $this->get_database();
		if ( ! class_exists( '\\ActionScheduler_Store' ) || empty( $wpdb->actionscheduler_actions ) || empty( $wpdb->actionscheduler_groups ) ) {
			return array();
		}
		if ( ! $wpdb->has_cap( 'identifier_placeholders' ) ) {
			return array(
				array(
					'action_id' => 0,
					'hook'      => self::IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK,
					'group'     => '',
				),
			);
		}
		$query   = $wpdb->prepare(
			'SELECT MIN( actions.action_id ) AS action_id, actions.hook, action_groups.slug AS action_group FROM %i AS actions LEFT JOIN %i AS action_groups ON actions.group_id = action_groups.group_id WHERE actions.status IN ( %s, %s ) AND ( actions.hook LIKE %s OR actions.hook LIKE %s ) GROUP BY actions.hook, action_groups.slug ORDER BY action_id ASC',
			$wpdb->actionscheduler_actions,
			$wpdb->actionscheduler_groups,
			\ActionScheduler_Store::STATUS_PENDING,
			\ActionScheduler_Store::STATUS_RUNNING,
			$wpdb->esc_like( 'wcpay_' ) . '%',
			$wpdb->esc_like( 'woocommerce_woopayments_' ) . '%'
		);
		$actions = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One request-memoized indexed operational preflight scan.
		if ( ! is_array( $actions ) || '' !== $wpdb->last_error ) {
			return array(
				array(
					'action_id' => 0,
					'hook'      => self::OPERATIONAL_QUEUE_QUERY_FAILED_HOOK,
					'group'     => '',
				),
			);
		}

		return array_values(
			array_map(
				static function ( array $action ): array {
					return array(
						'action_id' => (int) $action['action_id'],
						'hook'      => (string) $action['hook'],
						'group'     => isset( $action['action_group'] ) ? (string) $action['action_group'] : '',
					);
				},
				$actions
			)
		);
	}

	/**
	 * Get operational actions after excluding the cutover job itself.
	 *
	 * @return array<int,array{action_id:int,hook:string,group:string}> Actions ordered by ID.
	 */
	public function get_queued_operational_actions(): array {
		return array_values( array_filter( $this->get_queued_woopayments_actions(), array( $this, 'is_not_cutover_action' ) ) );
	}

	/**
	 * Resolve the active WooPayments plugin file.
	 *
	 * @return string Plugin file, or an empty string when unresolved.
	 */
	public function get_active_woopayments_plugin_file(): string {
		foreach ( (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}
		foreach ( array_keys( (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}
		return '';
	}

	/**
	 * Tell whether WooPayments is active for the current site.
	 *
	 * @since 11.2.0
	 */
	public function is_woopayments_site_active(): bool {
		foreach ( (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tell whether WooPayments is active network-wide.
	 */
	public function is_woopayments_network_active(): bool {
		foreach ( array_keys( (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deactivate WooPayments on the current site only.
	 *
	 * A plugin that is no longer active counts as deactivated: a retry after a crash that followed a successful deactivation
	 * finds nothing to deactivate. A plugin active for every site stays active, and this reports false.
	 */
	public function deactivate_woopayments_plugin(): bool {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// The lookup scans the same active-plugins lists as the activity checks, so an empty result means inactive.
		$plugin_file = $this->get_active_woopayments_plugin_file();
		if ( '' === $plugin_file ) {
			return true;
		}
		$this->legacy_proxy->call_function( 'deactivate_plugins', $plugin_file, false, false );
		return ! $this->is_woopayments_site_active() && ! $this->is_woopayments_network_active();
	}

	/**
	 * Make sure native runs the canceled-authorization fee remediation the plugin left behind on this site.
	 *
	 * Called once native owns the site, so the remediation's Action Scheduler callback is registered when it runs.
	 *
	 * @return bool False when the remediation is needed but cannot be scheduled.
	 */
	public function ensure_fee_remediation_scheduled(): bool {
		if ( 'unavailable' === $this->get_fee_remediation_service()->ensure_scheduled() ) {
			wc_get_logger()->error( 'Native WooPayments could not schedule the canceled-authorization fee remediation after the cutover.', array( 'source' => 'woocommerce-woopayments-cutover' ) );
			return false;
		}

		return true;
	}

	/**
	 * Tell whether the WooPayments extension version this store last ran supports the switch.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_woopayments_plugin_version_supported(): bool {
		$version = get_option( self::WOOPAYMENTS_VERSION_OPTION, '' );
		return is_string( $version ) && 1 === preg_match( '/^\\d+(?:\\.\\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/D', trim( $version ) ) && version_compare( trim( $version ), self::MINIMUM_CUTOVER_PLUGIN_VERSION, '>=' );
	}

	/**
	 * Determine whether native transport is ready.
	 *
	 * @return bool
	 */
	private function is_native_transport_ready(): bool {
		try {
			return $this->get_provider()->can_process_payments();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Determine whether automatic multi-currency rates lack a provider.
	 *
	 * @return bool
	 */
	private function has_unavailable_multi_currency_rate_provider(): bool {
		if ( ! $this->has_automatic_multi_currency_rate_currencies() ) {
			return false;
		}
		try {
			return ! ( $this->get_native_rate_api_client()->is_server_connected() && $this->get_native_rate_account()->is_provider_connected() && ! $this->get_native_rate_account()->is_account_rejected() );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Determine whether any enabled currencies use automatic rates.
	 *
	 * @return bool
	 */
	private function has_automatic_multi_currency_rate_currencies(): bool {
		if ( '1' !== (string) get_option( '_wcpay_feature_customer_multi_currency', '1' ) ) {
			return false;
		}
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		if ( ! is_array( $enabled_currencies ) || array() === $enabled_currencies ) {
			return false;
		}
		$store_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		foreach ( $enabled_currencies as $currency_code ) {
			if ( ! is_scalar( $currency_code ) ) {
				continue;
			}
			$currency_code = strtoupper( trim( (string) $currency_code ) );
			if ( '' !== $currency_code && $store_currency !== $currency_code && 'manual' !== (string) get_option( 'wcpay_multi_currency_exchange_rate_' . strtolower( $currency_code ), 'automatic' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the queued WooPayments hooks the built-in WooPayments does not handle.
	 *
	 * @return string[]
	 */
	private function get_unhandled_queue_hooks(): array {
		$actions = $this->get_queued_operational_actions();
		$hooks   = self::normalize_string_list( array_column( $actions, 'hook' ) );
		if ( in_array( self::IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK, $hooks, true ) ) {
			return array( self::IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK );
		}
		return array_values( array_diff( $hooks, self::BUILTIN_HANDLED_QUEUE_HOOKS ) );
	}

	/**
	 * Determine whether an action is outside the active cutover job identity.
	 *
	 * @param array{hook:string,group:string} $action Action Scheduler identity.
	 * @return bool
	 */
	private function is_not_cutover_action( array $action ): bool {
		if ( WooPaymentsCutoverActionScheduler::ACTION_GROUP !== $action['group'] ) {
			return true;
		}
		return WooPaymentsCutoverActionScheduler::ACTION_HOOK !== $action['hook'];
	}

	/**
	 * Determine whether a plugin file belongs to WooPayments.
	 *
	 * @param string $plugin_file Plugin file identifier.
	 * @return bool
	 */
	private function is_woopayments_plugin_file( string $plugin_file ): bool {
		if ( WooPaymentsRuntimeArbiter::PLUGIN_FILE === $plugin_file ) {
			return true;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = $this->legacy_proxy->call_function( 'get_plugins' );
		if ( ! is_array( $plugins ) || ! isset( $plugins[ $plugin_file ] ) || ! is_array( $plugins[ $plugin_file ] ) ) {
			return false;
		}
		$plugin_data = $plugins[ $plugin_file ];
		$name        = isset( $plugin_data['Name'] ) && is_scalar( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';
		$text_domain = isset( $plugin_data['TextDomain'] ) && is_scalar( $plugin_data['TextDomain'] ) ? (string) $plugin_data['TextDomain'] : '';
		return 'WooPayments' === $name || 'woocommerce-payments' === $text_domain;
	}

	/**
	 * Get the WordPress database connection.
	 *
	 * @return \wpdb
	 */
	protected function get_database(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Normalize scalar values into a unique non-empty string list.
	 *
	 * @param array<mixed> $values Values to normalize.
	 * @return string[]
	 */
	private static function normalize_string_list( array $values ): array {
		$normalized = array();
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value && ! in_array( $value, $normalized, true ) ) {
				$normalized[] = $value;
			}
		}
		return $normalized;
	}

	/**
	 * Get the native WooPayments provider, resolving it on first use.
	 *
	 * @return WooPaymentsProvider
	 */
	private function get_provider(): WooPaymentsProvider {
		if ( null === $this->provider ) {
			$this->provider = wc_get_container()->get( WooPaymentsProvider::class );
		}

		return $this->provider;
	}

	/**
	 * Get the legacy subscription data guard, resolving it on first use.
	 *
	 * @return WooPaymentsLegacySubscriptionsGuard
	 */
	private function get_legacy_subscriptions_guard(): WooPaymentsLegacySubscriptionsGuard {
		if ( null === $this->legacy_subscriptions_guard ) {
			$this->legacy_subscriptions_guard = wc_get_container()->get( WooPaymentsLegacySubscriptionsGuard::class );
		}

		return $this->legacy_subscriptions_guard;
	}

	/**
	 * Get the fee remediation owner, resolving it on first use.
	 *
	 * @return WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private function get_fee_remediation_service(): WooPaymentsCanceledAuthorizationFeeRemediationService {
		if ( null === $this->fee_remediation_service ) {
			$this->fee_remediation_service = wc_get_container()->get( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		}

		return $this->fee_remediation_service;
	}

	/**
	 * Get the platform connection readiness service, resolving it on first use.
	 *
	 * @return WooPaymentsPlatformConnectionService
	 */
	private function get_platform_connection_service(): WooPaymentsPlatformConnectionService {
		if ( null === $this->platform_connection_service ) {
			$this->platform_connection_service = wc_get_container()->get( WooPaymentsPlatformConnectionService::class );
		}

		return $this->platform_connection_service;
	}

	/**
	 * Get the native rate account boundary, resolving it on first use.
	 *
	 * @return WooPaymentsNativeAccountAdapter
	 */
	private function get_native_rate_account(): WooPaymentsNativeAccountAdapter {
		if ( null === $this->native_rate_account ) {
			$this->native_rate_account = wc_get_container()->get( WooPaymentsNativeAccountAdapter::class );
		}

		return $this->native_rate_account;
	}

	/**
	 * Get the native rate API client boundary, resolving it on first use.
	 *
	 * @return WooPaymentsNativeApiClientAdapter
	 */
	private function get_native_rate_api_client(): WooPaymentsNativeApiClientAdapter {
		if ( null === $this->native_rate_api_client ) {
			$this->native_rate_api_client = wc_get_container()->get( WooPaymentsNativeApiClientAdapter::class );
		}

		return $this->native_rate_api_client;
	}
}
