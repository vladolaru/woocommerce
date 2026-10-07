<?php
/**
 * WooPaymentsCutoverPreflightService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Provides headless facts for WooPayments cutover reconciliation.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverPreflightService {

	public const FILTER_NATIVE_TRANSPORT_READY = 'woocommerce_woopayments_cutover_transport_ready';

	public const FILTER_NATIVE_ADMIN_SURFACES_READY = 'woocommerce_woopayments_cutover_admin_surfaces_ready';

	public const FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER = 'woocommerce_woopayments_cutover_pending_event_types';

	public const FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER = 'woocommerce_woopayments_cutover_pending_operational_queue_hooks';

	public const FILTER_PREFLIGHT_FAILURES = 'woocommerce_woopayments_cutover_preflight_failures';

	public const MINIMUM_CUTOVER_PLUGIN_VERSION = '10.5.0';

	private const WOOPAYMENTS_VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	private const NETWORK_PREFLIGHT_BATCH_SIZE = 100;

	private const IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK = 'woocommerce_woopayments_identifier_placeholders_unavailable';

	private const OPERATIONAL_QUEUE_QUERY_FAILED_HOOK = 'woocommerce_woopayments_operational_queue_query_failed';


	/**
	 * Action Scheduler hooks with native Core consumers.
	 *
	 * @var string[]
	 */
	private const NATIVE_OWNED_OPERATIONAL_QUEUE_HOOKS = array(
		WooPaymentsOperationalQueueService::STORE_SETUP_SYNC_ACTION,
		WooPaymentsOperationalQueueService::UPDATE_SAVED_PAYMENT_METHOD_ACTION,
		WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
		WooPaymentsOperationalQueueService::UPDATE_COMPATIBILITY_DATA_ACTION,
		WooPaymentsOperationalQueueService::INSTANT_DEPOSIT_REMINDER_ACTION,
		WooPaymentsOperationalQueueService::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION,
		WooPaymentsOrderTrackingService::TRACK_NEW_ORDER_ACTION,
		WooPaymentsOrderTrackingService::TRACK_UPDATE_ORDER_ACTION,
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
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

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
	 * Native admin navigation owner.
	 *
	 * @var WooPaymentsAdminNavigationController|null
	 */
	private ?WooPaymentsAdminNavigationController $admin_navigation_controller = null;

	/**
	 * Request-local reconciliation failures keyed by blog ID.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $reconciliation_memo = array();

	/**
	 * Request-local compatibility preflight failures keyed by blog ID.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $preflight_memo = array();

	/**
	 * Request-local network preflight failures.
	 *
	 * @var int[]|null
	 */
	private ?array $network_preflight_failing_site_ids_memo = null;

	/**
	 * Initialize the service instance.
	 *
	 * The check collaborators are resolved when a preflight runs, so registering the cutover surfaces loads none of them.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter      Runtime owner arbiter.
	 * @param LegacyProxy                  $legacy_proxy Legacy proxy.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy ): void {
		$this->arbiter      = $arbiter;
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Invalidate request-local facts for the current blog.
	 */
	public function invalidate_current_blog_memoization(): void {
		$blog_id = get_current_blog_id();
		unset( $this->reconciliation_memo[ $blog_id ], $this->preflight_memo[ $blog_id ] );
		$this->network_preflight_failing_site_ids_memo = null;
	}

	/**
	 * Get every condition the reconciliation job must disposition.
	 *
	 * @return string[] Failure codes.
	 */
	public function get_reconciliation_failures(): array {
		$blog_id = get_current_blog_id();
		$this->evaluate_failures( $blog_id );
		return $this->reconciliation_memo[ $blog_id ];
	}

	/**
	 * Get the legacy compatibility-facing preflight shape.
	 *
	 * @return string[] Failure codes.
	 */
	public function get_preflight_failures(): array {
		$blog_id = get_current_blog_id();
		$this->evaluate_failures( $blog_id );
		return $this->preflight_memo[ $blog_id ];
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
	 * Evaluate and memoize cutover failures for a site.
	 *
	 * @param int $blog_id Current blog ID.
	 */
	private function evaluate_failures( int $blog_id ): void {
		if ( array_key_exists( $blog_id, $this->reconciliation_memo ) ) {
			return;
		}
		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			$this->reconciliation_memo[ $blog_id ] = array( 'native_runtime_disabled' );
			$this->preflight_memo[ $blog_id ]      = array( 'native_runtime_disabled' );
			return;
		}

		$raw_failures           = array();
		$compatibility_failures = array();
		$protected_failures     = array();
		if ( ! $this->is_woopayments_plugin_version_supported() ) {
			$raw_failures[]           = 'woopayments_plugin_version_unsupported';
			$compatibility_failures[] = 'woopayments_plugin_version_unsupported';
			$protected_failures[]     = 'woopayments_plugin_version_unsupported';
		}
		/**
		 * Filters whether native WooPayments transport is ready for cutover.
		 *
		 * @since 11.0.0
		 * @param bool $is_ready Whether native transport can process WooPayments requests.
		 */
		if ( ! (bool) apply_filters( self::FILTER_NATIVE_TRANSPORT_READY, $this->is_native_transport_ready() ) ) {
			$raw_failures[]           = 'native_transport_unavailable';
			$compatibility_failures[] = 'native_transport_unavailable';
		}
		$platform_failures      = $this->get_platform_connection_service()->get_cutover_preflight_failures();
		$raw_failures           = array_merge( $raw_failures, $platform_failures );
		$compatibility_failures = array_merge( $compatibility_failures, $platform_failures );
		$protected_failures     = array_merge( $protected_failures, $platform_failures );
		if ( $this->has_unavailable_multi_currency_rate_provider() ) {
			$raw_failures[]           = 'multi_currency_rates_unavailable';
			$compatibility_failures[] = 'multi_currency_rates_unavailable';
			$protected_failures[]     = 'multi_currency_rates_unavailable';
		}
		/**
		 * Filters whether native WooPayments merchant admin surfaces are ready after deactivation.
		 *
		 * @since 11.0.0
		 * @param bool $is_ready Whether native merchant admin surfaces are ready.
		 */
		if ( ! (bool) apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, $this->get_admin_navigation_controller()->are_all_available_routes_registered() ) ) {
			$raw_failures[]           = 'native_admin_surfaces_unavailable';
			$compatibility_failures[] = 'native_admin_surfaces_unavailable';
		}
		$pending_provider_event_types = $this->get_pending_provider_event_types();
		if ( array() !== $pending_provider_event_types ) {
			$raw_failures[]           = in_array( 'provider_events_filter_invalid', $pending_provider_event_types, true ) ? 'provider_events_filter_invalid' : 'provider_events_undispositioned';
			$compatibility_failures[] = 'provider_events_undispositioned';
		}
		$pending_operational_queue_hooks = $this->get_pending_operational_queue_hooks();
		if ( array() !== $pending_operational_queue_hooks ) {
			$raw_failures[]           = in_array( 'operational_queue_hooks_filter_invalid', $pending_operational_queue_hooks, true ) ? 'operational_queue_hooks_filter_invalid' : 'operational_queue_hooks_undispositioned';
			$compatibility_failures[] = 'operational_queue_hooks_undispositioned';
		}
		if ( ! $this->get_fee_remediation_service()->can_schedule_cutover_remediation() ) {
			$raw_failures[]           = 'financial_migrations_unavailable';
			$compatibility_failures[] = 'financial_migrations_unavailable';
		}
		if ( $this->get_legacy_subscriptions_guard()->is_bundled_stripe_billing_store() ) {
			$raw_failures[] = 'legacy_stripe_billing_subscriptions_present';
		}
		$raw_failures           = self::normalize_string_list( $raw_failures );
		$compatibility_failures = self::normalize_string_list( $compatibility_failures );
		$protected_failures     = self::normalize_string_list( $protected_failures );
		/**
		 * Filters WooPayments native cutover preflight failures.
		 *
		 * @since 11.0.0
		 * @param array<int,string> $failures Failure codes.
		 */
		$filtered_failures = apply_filters( self::FILTER_PREFLIGHT_FAILURES, array_values( $compatibility_failures ) );
		if ( is_array( $filtered_failures ) ) {
			$filtered_failures                     = self::normalize_string_list( $filtered_failures );
			$extensions                            = array_values( array_diff( $filtered_failures, $compatibility_failures ) );
			$this->reconciliation_memo[ $blog_id ] = self::normalize_string_list( array_merge( $raw_failures, $extensions ) );
			$this->preflight_memo[ $blog_id ]      = self::normalize_string_list( array_merge( $filtered_failures, $protected_failures, in_array( 'legacy_stripe_billing_subscriptions_present', $raw_failures, true ) ? array( 'legacy_stripe_billing_subscriptions_present' ) : array() ) );
			return;
		}
		$this->reconciliation_memo[ $blog_id ] = self::normalize_string_list( array_merge( $raw_failures, array( 'preflight_filter_invalid' ) ) );
		$this->preflight_memo[ $blog_id ]      = self::normalize_string_list( array_merge( array( 'preflight_filter_invalid' ), $protected_failures, in_array( 'legacy_stripe_billing_subscriptions_present', $raw_failures, true ) ? array( 'legacy_stripe_billing_subscriptions_present' ) : array() ) );
	}

	/**
	 * Get site IDs whose preflight blocks network-wide deactivation.
	 *
	 * @return int[] Failing site IDs in ascending order.
	 */
	public function get_network_preflight_failing_site_ids(): array {
		if ( ! is_multisite() || ! $this->is_woopayments_network_active() ) {
			return array();
		}
		if ( null !== $this->network_preflight_failing_site_ids_memo ) {
			return $this->network_preflight_failing_site_ids_memo;
		}

		$failing_site_ids = array();
		$offset           = 0;
		do {
			$site_ids = get_sites(
				array(
					'fields'     => 'ids',
					'network_id' => get_current_network_id(),
					'number'     => self::NETWORK_PREFLIGHT_BATCH_SIZE,
					'offset'     => $offset,
					'orderby'    => 'id',
					'order'      => 'ASC',
				)
			);
			foreach ( $site_ids as $site_id ) {
				$site_id  = (int) $site_id;
				$switched = get_current_blog_id() !== $site_id;
				if ( $switched ) {
					switch_to_blog( $site_id );
				}
				try {
					if ( array() !== $this->get_preflight_failures() ) {
						$failing_site_ids[] = $site_id;
					}
				} finally {
					if ( $switched ) {
						restore_current_blog();
					}
				}
			}
			$site_count = count( $site_ids );
			$offset    += $site_count;
		} while ( self::NETWORK_PREFLIGHT_BATCH_SIZE === $site_count );

		$this->network_preflight_failing_site_ids_memo = $failing_site_ids;
		return $this->network_preflight_failing_site_ids_memo;
	}

	/**
	 * Get pending or running plugin-prefixed Action Scheduler actions.
	 *
	 * @return array<int,array{action_id:int,hook:string,group:string}> Actions ordered by ID.
	 */
	public function get_queued_plugin_actions(): array {
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
			'SELECT actions.action_id, actions.hook, action_groups.slug AS action_group FROM %i AS actions LEFT JOIN %i AS action_groups ON actions.group_id = action_groups.group_id WHERE actions.status IN ( %s, %s ) AND ( actions.hook LIKE %s OR actions.hook LIKE %s ) ORDER BY actions.action_id ASC',
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
		return array_values( array_filter( $this->get_queued_plugin_actions(), array( $this, 'is_not_cutover_action' ) ) );
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
	 * Deactivate WooPayments on the site, or network-wide where it is network-active.
	 *
	 * A plugin that is no longer active on the site or the network counts as deactivated: a retry after a crash that
	 * followed a successful deactivation finds nothing to deactivate.
	 */
	public function deactivate_woopayments_plugin(): bool {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// The lookup scans the same site and network lists as the activity checks, so an empty result means inactive.
		$plugin_file = $this->get_active_woopayments_plugin_file();
		if ( '' === $plugin_file ) {
			return true;
		}
		$this->legacy_proxy->call_function( 'deactivate_plugins', $plugin_file, false, $this->is_woopayments_network_active() );
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
	 * Determine whether the installed WooPayments version supports cutover.
	 *
	 * @return bool
	 */
	private function is_woopayments_plugin_version_supported(): bool {
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
	 * Get provider event types that still need disposition.
	 *
	 * @return string[]
	 */
	private function get_pending_provider_event_types(): array {
		/**
		 * Filters provider event types that still need native cutover disposition.
		 *
		 * @since 11.0.0
		 * @param array<int,string> $event_types Event types that still block cutover.
		 */
		$event_types = apply_filters( self::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES );
		return is_array( $event_types ) ? self::normalize_string_list( $event_types ) : array( 'provider_events_filter_invalid' );
	}

	/**
	 * Get operational queue hooks that still need disposition.
	 *
	 * @return string[]
	 */
	private function get_pending_operational_queue_hooks(): array {
		$actions = $this->get_queued_operational_actions();
		$hooks   = self::normalize_string_list( array_column( $actions, 'hook' ) );
		if ( in_array( self::IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK, $hooks, true ) ) {
			return array( self::IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK );
		}
		/**
		 * Filters operational queue hooks that still need native cutover disposition.
		 *
		 * @since 11.0.0
		 * @param array<int,string> $hook_names Operational queue hooks that still block cutover.
		 */
		$hook_names = apply_filters( self::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, array_values( array_diff( $hooks, self::NATIVE_OWNED_OPERATIONAL_QUEUE_HOOKS ) ) );
		return is_array( $hook_names ) ? self::normalize_string_list( $hook_names ) : array( 'operational_queue_hooks_filter_invalid' );
	}

	/**
	 * Determine whether an action is outside the active cutover job identity.
	 *
	 * @param array{hook:string,group:string} $action Action Scheduler identity.
	 * @return bool
	 */
	private function is_not_cutover_action( array $action ): bool {
		if ( WooPaymentsCutoverActionScheduler::GROUP_ID !== $action['group'] ) {
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
		if ( NativePaymentsRuntimeArbiter::PLUGIN_FILE === $plugin_file ) {
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

	/**
	 * Get the native admin navigation owner, resolving it on first use.
	 *
	 * @return WooPaymentsAdminNavigationController
	 */
	private function get_admin_navigation_controller(): WooPaymentsAdminNavigationController {
		if ( null === $this->admin_navigation_controller ) {
			$this->admin_navigation_controller = wc_get_container()->get( WooPaymentsAdminNavigationController::class );
		}

		return $this->admin_navigation_controller;
	}
}
