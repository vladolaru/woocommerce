<?php
/**
 * WooPaymentsCutoverController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the WooPayments plugin-to-native cutover UX.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverController implements RegisterHooksInterface {

	/**
	 * Query action value used to disable the standalone WooPayments plugin.
	 *
	 * @var string
	 */
	public const ACTION_DISABLE = 'disable_woopayments';

	/**
	 * Filter that controls the soft cutover admin notice.
	 *
	 * @var string
	 */
	public const FILTER_SOFT_CUTOVER_ENABLED = 'woocommerce_woopayments_soft_cutover_enabled';

	/**
	 * Filter that controls mandatory WooPayments auto-deactivation and activation blocking.
	 *
	 * @var string
	 */
	public const FILTER_MANDATORY_CUTOVER_ENABLED = 'woocommerce_woopayments_mandatory_cutover_enabled';

	/**
	 * Default state for mandatory WooPayments native cutover.
	 *
	 * This intentionally remains false until the final A5 stage-boundary gates approve the release/default-on flip.
	 *
	 * @var bool
	 */
	public const DEFAULT_MANDATORY_CUTOVER_ENABLED = false;

	/**
	 * Filter that reports whether a core-owned WooPayments transport is ready to process after deactivation.
	 *
	 * @var string
	 */
	public const FILTER_NATIVE_TRANSPORT_READY = 'woocommerce_woopayments_cutover_transport_ready';

	/**
	 * Filter that reports whether native WooPayments merchant admin surfaces are ready after deactivation.
	 *
	 * @var string
	 */
	public const FILTER_NATIVE_ADMIN_SURFACES_READY = 'woocommerce_woopayments_cutover_admin_surfaces_ready';

	/**
	 * Filter that reports provider event types still pending native cutover disposition.
	 *
	 * @var string
	 */
	public const FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER = 'woocommerce_woopayments_cutover_pending_event_types';

	/**
	 * Filter that reports operational queue hooks still pending native cutover disposition.
	 *
	 * @var string
	 */
	public const FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER = 'woocommerce_woopayments_cutover_pending_operational_queue_hooks';

	/**
	 * Filter for cutover preflight failures.
	 *
	 * @var string
	 */
	public const FILTER_PREFLIGHT_FAILURES = 'woocommerce_woopayments_cutover_preflight_failures';

	/**
	 * Nonce action for the one-click disable action.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'woocommerce_disable_woopayments';

	/**
	 * Nonce query parameter for the one-click disable action.
	 *
	 * @var string
	 */
	public const NONCE_NAME = '_wc_woopayments_cutover_nonce';

	/**
	 * Query parameter that carries the cutover action.
	 *
	 * @var string
	 */
	public const QUERY_ACTION = 'wc_woopayments_cutover_action';

	/**
	 * Query parameter that carries the cutover status notice.
	 *
	 * @var string
	 */
	public const QUERY_STATUS = 'wc_woopayments_cutover_status';

	/**
	 * Transient that carries a one-time mandatory cutover status across plugin deactivation redirects.
	 *
	 * @var string
	 */
	private const NOTICE_STATUS_TRANSIENT = 'woocommerce_woopayments_native_cutover_status';

	/**
	 * Legacy operational queue hooks that still need native cutover disposition.
	 *
	 * @var string[]
	 */
	private const DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS = array();

	/**
	 * Status value for a successful plugin disable.
	 *
	 * @var string
	 */
	public const STATUS_DISABLED = 'disabled';

	/**
	 * Status value for a blocked plugin disable.
	 *
	 * @var string
	 */
	public const STATUS_BLOCKED = 'blocked';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Cutover status produced during the current request.
	 *
	 * @var string
	 */
	private string $current_request_status = '';

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Native WooPayments provider.
	 *
	 * @var WooPaymentsProvider
	 */
	private WooPaymentsProvider $provider;

	/**
	 * Legacy subscription data guard.
	 *
	 * @var WooPaymentsLegacySubscriptionsGuard
	 */
	private WooPaymentsLegacySubscriptionsGuard $legacy_subscriptions_guard;

	/**
	 * Canceled-authorization fee remediation queue owner.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service;

	/**
	 * Platform connection readiness service.
	 *
	 * @var WooPaymentsPlatformConnectionService
	 */
	private WooPaymentsPlatformConnectionService $platform_connection_service;

	/**
	 * Native rate account boundary.
	 *
	 * @var WooPaymentsNativeAccountAdapter
	 */
	private WooPaymentsNativeAccountAdapter $native_rate_account;

	/**
	 * Native rate API client boundary.
	 *
	 * @var WooPaymentsNativeApiClientAdapter
	 */
	private WooPaymentsNativeApiClientAdapter $native_rate_api_client;

	/**
	 * Native WooPayments admin navigation owner.
	 *
	 * @var WooPaymentsAdminNavigationController
	 */
	private WooPaymentsAdminNavigationController $admin_navigation_controller;

	/**
	 * Request-local cutover preflight failures.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $preflight_memo = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter                               $arbiter                    Runtime owner arbiter.
	 * @param LegacyProxy                                                $legacy_proxy               Legacy proxy.
	 * @param WooPaymentsProvider                                        $provider                   Native WooPayments provider.
	 * @param WooPaymentsLegacySubscriptionsGuard|null                   $legacy_subscriptions_guard Legacy subscription data guard.
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService|null $fee_remediation_service    Canceled-authorization fee remediation queue owner.
	 * @param WooPaymentsPlatformConnectionService|null                  $platform_connection_service Platform connection readiness service.
	 * @param WooPaymentsNativeAccountAdapter|null                       $native_rate_account         Native rate account boundary.
	 * @param WooPaymentsNativeApiClientAdapter|null                     $native_rate_api_client      Native rate API client boundary.
	 * @param WooPaymentsAdminNavigationController|null                  $admin_navigation_controller Native admin navigation owner.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		LegacyProxy $legacy_proxy,
		WooPaymentsProvider $provider,
		?WooPaymentsLegacySubscriptionsGuard $legacy_subscriptions_guard = null,
		?WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service = null,
		?WooPaymentsPlatformConnectionService $platform_connection_service = null,
		?WooPaymentsNativeAccountAdapter $native_rate_account = null,
		?WooPaymentsNativeApiClientAdapter $native_rate_api_client = null,
		?WooPaymentsAdminNavigationController $admin_navigation_controller = null
	): void {
		$this->arbiter                     = $arbiter;
		$this->legacy_proxy                = $legacy_proxy;
		$this->provider                    = $provider;
		$this->legacy_subscriptions_guard  = $legacy_subscriptions_guard ?? wc_get_container()->get( WooPaymentsLegacySubscriptionsGuard::class );
		$this->fee_remediation_service     = $fee_remediation_service ?? wc_get_container()->get( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		$this->platform_connection_service = $platform_connection_service ?? wc_get_container()->get( WooPaymentsPlatformConnectionService::class );
		$this->native_rate_account         = $native_rate_account ?? wc_get_container()->get( WooPaymentsNativeAccountAdapter::class );
		$this->native_rate_api_client      = $native_rate_api_client ?? wc_get_container()->get( WooPaymentsNativeApiClientAdapter::class );
		$this->admin_navigation_controller = $admin_navigation_controller ?? wc_get_container()->get( WooPaymentsAdminNavigationController::class );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
		add_action( 'admin_notices', array( $this, 'output_admin_notices' ) );
		add_action( 'activate_plugin', array( $this, 'guard_woopayments_activation' ) );
	}

	/**
	 * Handle admin init cutover actions.
	 *
	 * @internal
	 */
	public function handle_admin_init(): void {
		$this->maybe_auto_deactivate_plugin();

		$action = isset( $_GET[ self::QUERY_ACTION ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ACTION ] ) ) : '';
		if ( self::ACTION_DISABLE !== $action ) {
			return;
		}

		$nonce = isset( $_GET[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
		}

		$status = $this->disable_woopayments_plugin() ? self::STATUS_DISABLED : self::STATUS_BLOCKED;
		$url    = add_query_arg(
			array(
				self::QUERY_STATUS => $status,
			),
			admin_url( 'plugins.php' )
		);

		wp_safe_redirect( $url );
		$this->legacy_proxy->exit();
	}

	/**
	 * Output WooPayments cutover admin notices.
	 *
	 * @internal
	 */
	public function output_admin_notices(): void {
		$status                       = $this->current_request_status;
		$is_current_request_status    = '' !== $status;
		$this->current_request_status = '';

		if ( '' === $status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads post-redirect status only; no state change is performed here.
			$status = isset( $_GET[ self::QUERY_STATUS ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_STATUS ] ) ) : '';
		}

		if ( '' === $status ) {
			$status = $this->consume_stored_notice_status();
		}

		if ( self::STATUS_DISABLED === $status && ! $is_current_request_status && $this->arbiter->is_plugin_runtime_active() ) {
			$status = self::STATUS_BLOCKED;
		}

		if ( self::STATUS_DISABLED === $status ) {
			if ( $is_current_request_status ) {
				$this->delete_stored_notice_status();
			}
			$this->output_success_notice();
			return;
		}

		if ( self::STATUS_BLOCKED === $status ) {
			$this->output_blocked_notice();
			return;
		}

		if ( $this->should_show_soft_cutover_notice() ) {
			$this->output_soft_cutover_notice();
		}
	}

	/**
	 * Tell whether the soft cutover notice should be shown.
	 *
	 * @return bool
	 */
	public function should_show_soft_cutover_notice(): bool {
		return $this->is_soft_cutover_enabled()
			&& $this->arbiter->is_plugin_runtime_active()
			&& $this->current_user_can_cutover()
			&& $this->is_cutover_ready();
	}

	/**
	 * Disable the standalone WooPayments plugin when all cutover guards pass.
	 *
	 * @return bool True when the plugin no longer owns the runtime.
	 */
	public function disable_woopayments_plugin(): bool {
		if ( ! $this->arbiter->is_plugin_runtime_active() || ! $this->current_user_can_cutover() || ! $this->is_cutover_ready() ) {
			return false;
		}

		return $this->deactivate_woopayments_plugin();
	}

	/**
	 * Deactivate the standalone WooPayments plugin.
	 *
	 * @return bool True when the plugin no longer owns the runtime.
	 */
	private function deactivate_woopayments_plugin(): bool {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( 'unavailable' === $this->fee_remediation_service->ensure_scheduled() ) {
			wc_get_logger()->error(
				'WooPayments could not be deactivated because native WooPayments could not schedule canceled-authorization fee remediation.',
				array( 'source' => 'woocommerce-woopayments-cutover' )
			);
			return false;
		}

		$plugin_file = $this->get_active_woopayments_plugin_file();
		if ( '' === $plugin_file ) {
			wc_get_logger()->error(
				'WooPayments could not be deactivated because the active plugin file could not be resolved.',
				array( 'source' => 'woocommerce-woopayments-cutover' )
			);
			return false;
		}

		$this->legacy_proxy->call_function(
			'deactivate_plugins',
			$plugin_file,
			false,
			$this->is_woopayments_network_active()
		);

		return ! $this->is_woopayments_site_active() && ! $this->is_woopayments_network_active();
	}

	/**
	 * Guard WooPayments activation once mandatory native cutover is enabled.
	 *
	 * @internal
	 *
	 * @param string $plugin Plugin path being activated.
	 */
	public function guard_woopayments_activation( string $plugin ): void {
		if (
			NativePaymentsRuntimeArbiter::PLUGIN_FILE !== $plugin ||
			! $this->is_mandatory_cutover_enabled() ||
			! $this->is_cutover_ready() ||
			Constants::is_true( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' )
		) {
			return;
		}

		wp_die(
			esc_html__( 'WooPayments cannot be activated because its functionality is now included in WooCommerce core.', 'woocommerce' ),
			esc_html__( 'Plugin activation error', 'woocommerce' ),
			array(
				'link_url'  => esc_url( admin_url( 'plugins.php' ) ),
				'link_text' => esc_html__( 'Return to the Plugins page', 'woocommerce' ),
			)
		);
	}

	/**
	 * Get cutover preflight failure codes.
	 *
	 * @return array<int,string> Failure codes.
	 */
	public function get_preflight_failures(): array {
		if ( null !== $this->preflight_memo ) {
			return $this->preflight_memo;
		}

		if ( ! $this->arbiter->is_native_runtime_enabled() ) {
			$this->preflight_memo = array( 'native_runtime_disabled' );
			return $this->preflight_memo;
		}

		$failures = array();

		/**
		 * Filters whether the native WooPayments transport is ready for cutover.
		 *
		 * @param bool $is_ready Whether the native transport can process WooPayments requests.
		 *
		 * @since 11.0.0
		 */
		if ( ! (bool) apply_filters( self::FILTER_NATIVE_TRANSPORT_READY, $this->is_native_transport_ready() ) ) {
			$failures[] = 'native_transport_unavailable';
		}

		$platform_connection_failures = $this->platform_connection_service->get_cutover_preflight_failures();
		$failures                     = array_merge( $failures, $platform_connection_failures );
		$protected_failures           = $platform_connection_failures;

		if ( $this->has_unsupported_enabled_payment_methods() ) {
			$failures[]           = 'unsupported_payment_methods_enabled';
			$protected_failures[] = 'unsupported_payment_methods_enabled';
		}

		if ( $this->has_unavailable_multi_currency_rate_provider() ) {
			$failures[]           = 'multi_currency_rates_unavailable';
			$protected_failures[] = 'multi_currency_rates_unavailable';
		}

		/**
		 * Filters whether native WooPayments merchant admin surfaces are ready after deactivation.
		 *
		 * @param bool $is_ready Whether native merchant admin surfaces are ready.
		 *
		 * @since 11.0.0
		 */
		if ( ! (bool) apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, $this->admin_navigation_controller->are_all_available_routes_registered() ) ) {
			$failures[] = 'native_admin_surfaces_unavailable';
		}

		if ( array() !== $this->get_pending_provider_event_types() ) {
			$failures[] = 'provider_events_undispositioned';
		}

		if ( array() !== $this->get_pending_operational_queue_hooks() ) {
			$failures[] = 'operational_queue_hooks_undispositioned';
		}

		if ( ! $this->fee_remediation_service->can_schedule_cutover_remediation() ) {
			$failures[] = 'financial_migrations_unavailable';
		}

		/**
		 * Filters WooPayments native cutover preflight failures.
		 *
		 * This filter runs only after the native runtime is enabled. When native runtime is disabled,
		 * preflight returns `native_runtime_disabled` before running platform, queue, or filter checks.
		 *
		 * @param array<int,string> $failures Failure codes.
		 *
		 * @since 11.0.0
		 */
		$failures = apply_filters( self::FILTER_PREFLIGHT_FAILURES, $failures );

		$failures = is_array( $failures ) ? array_values( array_map( 'strval', $failures ) ) : array( 'preflight_filter_invalid' );

		foreach ( $protected_failures as $failure ) {
			$failure = (string) $failure;
			if ( '' !== $failure && ! in_array( $failure, $failures, true ) ) {
				$failures[] = $failure;
			}
		}

		if (
			$this->legacy_subscriptions_guard->has_legacy_stripe_billing_subscription_markers() &&
			! in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true )
		) {
			$failures[] = 'legacy_stripe_billing_subscriptions_present';
		}

		$this->preflight_memo = $failures;
		return $this->preflight_memo;
	}

	/**
	 * Determine whether legacy settings enable methods native WooPayments cannot charge yet.
	 *
	 * @return bool
	 */
	private function has_unsupported_enabled_payment_methods(): bool {
		$natively_chargeable_payment_method_ids = WooPaymentsSettingsService::get_natively_chargeable_payment_method_ids();

		foreach ( $this->get_enabled_legacy_payment_method_ids() as $payment_method_id ) {
			if ( ! in_array( $payment_method_id, $natively_chargeable_payment_method_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether cutover would leave automatic multi-currency rates without a provider.
	 *
	 * @return bool
	 */
	private function has_unavailable_multi_currency_rate_provider(): bool {
		if ( ! $this->has_automatic_multi_currency_rate_currencies() ) {
			return false;
		}

		try {
			return ! (
				$this->native_rate_api_client->is_server_connected()
				&& $this->native_rate_account->is_provider_connected()
				&& ! $this->native_rate_account->is_account_rejected()
			);
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Tell whether enabled multi-currency includes any automatic-rate non-default currency.
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
			if ( '' === $currency_code || $store_currency === $currency_code ) {
				continue;
			}

			if ( 'manual' !== (string) get_option( 'wcpay_multi_currency_exchange_rate_' . strtolower( $currency_code ), 'automatic' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get enabled payment method IDs from the standalone WooPayments settings option.
	 *
	 * @return string[]
	 */
	private function get_enabled_legacy_payment_method_ids(): array {
		$settings = get_option( WooPaymentsSettingsService::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$payment_method_ids = $settings['upe_enabled_payment_method_ids'] ?? array( 'card' );
		if ( ! is_array( $payment_method_ids ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $payment_method_id ): string => is_scalar( $payment_method_id ) ? (string) $payment_method_id : '',
						$payment_method_ids
					),
					static fn( string $payment_method_id ): bool => '' !== $payment_method_id
				)
			)
		);
	}

	/**
	 * Auto-deactivate WooPayments when mandatory cutover is enabled and safe.
	 */
	private function maybe_auto_deactivate_plugin(): void {
		if (
			! $this->is_mandatory_cutover_enabled() ||
			! $this->arbiter->is_plugin_runtime_active() ||
			Constants::is_true( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' )
		) {
			return;
		}

		if ( ! $this->is_cutover_ready() ) {
			$this->set_current_request_status( self::STATUS_BLOCKED );
			return;
		}

		$this->set_current_request_status( self::STATUS_DISABLED, true );
		if ( $this->deactivate_woopayments_plugin() ) {
			return;
		}

		$this->delete_stored_notice_status();
		$this->set_current_request_status( self::STATUS_BLOCKED );
	}

	/**
	 * Set the cutover notice status for the current request.
	 *
	 * @param string $status  Cutover notice status.
	 * @param bool   $persist Whether to store the notice for the next request.
	 */
	private function set_current_request_status( string $status, bool $persist = false ): void {
		$this->current_request_status = $status;
		$_GET[ self::QUERY_STATUS ]   = $status;

		if ( $persist ) {
			set_transient( self::NOTICE_STATUS_TRANSIENT, $status, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Consume a stored cutover notice status.
	 *
	 * @return string Cutover status, or empty string when none is stored.
	 */
	private function consume_stored_notice_status(): string {
		$status = get_transient( self::NOTICE_STATUS_TRANSIENT );
		$this->delete_stored_notice_status();

		return is_string( $status ) ? sanitize_key( $status ) : '';
	}

	/**
	 * Delete the stored cutover notice status.
	 */
	private function delete_stored_notice_status(): void {
		delete_transient( self::NOTICE_STATUS_TRANSIENT );
	}

	/**
	 * Tell whether all deterministic cutover preflight checks pass.
	 *
	 * @return bool
	 */
	private function is_cutover_ready(): bool {
		return array() === $this->get_preflight_failures();
	}

	/**
	 * Tell whether native WooPayments can process after plugin deactivation.
	 *
	 * @return bool
	 */
	private function is_native_transport_ready(): bool {
		try {
			return $this->provider->can_process_payments();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get provider event types that still need native cutover disposition.
	 *
	 * @return array<int,string> Event type identifiers.
	 */
	private function get_pending_provider_event_types(): array {
		/**
		 * Filters provider event types that still need native cutover disposition.
		 *
		 * @param array<int,string> $event_types Event types that still block cutover.
		 *
		 * @since 11.0.0
		 */
		$event_types = apply_filters( self::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES );

		if ( ! is_array( $event_types ) ) {
			return array( 'provider_events_filter_invalid' );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $event_types ),
					static fn( string $event_type ): bool => '' !== $event_type
				)
			)
		);
	}

	/**
	 * Get operational queue hooks that still need native cutover disposition.
	 *
	 * @return array<int,string> Operational queue hook names.
	 */
	private function get_pending_operational_queue_hooks(): array {
		/**
		 * Filters operational queue hooks that still need native cutover disposition.
		 *
		 * @param array<int,string> $hook_names Operational queue hooks that still block cutover.
		 *
		 * @since 11.0.0
		 */
		$hook_names = apply_filters( self::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, self::DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS );

		if ( ! is_array( $hook_names ) ) {
			return array( 'operational_queue_hooks_filter_invalid' );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $hook_names ),
					static fn( string $hook_name ): bool => '' !== $hook_name
				)
			)
		);
	}

	/**
	 * Tell whether the soft cutover notice is enabled.
	 *
	 * @return bool
	 */
	private function is_soft_cutover_enabled(): bool {
		/**
		 * Filters whether the WooPayments native soft cutover notice is enabled.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether the soft cutover notice is enabled.
		 */
		return (bool) apply_filters( self::FILTER_SOFT_CUTOVER_ENABLED, true );
	}

	/**
	 * Tell whether mandatory native cutover is enabled.
	 *
	 * @return bool
	 */
	private function is_mandatory_cutover_enabled(): bool {
		/**
		 * Filters whether mandatory WooPayments native cutover is enabled.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether mandatory cutover is enabled.
		 */
		return (bool) apply_filters( self::FILTER_MANDATORY_CUTOVER_ENABLED, self::DEFAULT_MANDATORY_CUTOVER_ENABLED );
	}

	/**
	 * Tell whether the current user can perform cutover actions.
	 *
	 * @return bool
	 */
	private function current_user_can_cutover(): bool {
		if ( ! $this->legacy_proxy->call_function( 'current_user_can', 'manage_woocommerce' ) ) {
			return false;
		}

		$plugin_capability = $this->is_woopayments_network_active() ? 'manage_network_plugins' : 'activate_plugins';

		return (bool) $this->legacy_proxy->call_function( 'current_user_can', $plugin_capability );
	}

	/**
	 * Tell whether WooPayments is active network-wide.
	 *
	 * @return bool
	 */
	private function is_woopayments_network_active(): bool {
		$network_active = (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() );

		foreach ( array_keys( $network_active ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether WooPayments is active for the current site.
	 *
	 * @return bool
	 */
	private function is_woopayments_site_active(): bool {
		$active_plugins = (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() );

		foreach ( $active_plugins as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the active WooPayments plugin file.
	 *
	 * @return string Active WooPayments plugin file, or an empty string when unresolved.
	 */
	private function get_active_woopayments_plugin_file(): string {
		$active_plugins = (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() );
		foreach ( $active_plugins as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}

		$network_active = (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() );
		foreach ( array_keys( $network_active ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}

		return '';
	}

	/**
	 * Tell whether a plugin file is the WooPayments main plugin file.
	 *
	 * @param string $plugin_file Plugin file path.
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
	 * Get a nonce-protected URL for the soft cutover action.
	 *
	 * @return string
	 */
	private function get_disable_url(): string {
		$url = add_query_arg(
			array(
				self::QUERY_ACTION => self::ACTION_DISABLE,
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $url, self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Output the soft cutover notice.
	 */
	private function output_soft_cutover_notice(): void {
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'WooPayments is now part of WooCommerce core. Disable the WooPayments extension to continue processing payments with WooPayments.', 'woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->get_disable_url() ); ?>">
					<?php esc_html_e( 'Disable WooPayments', 'woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Output the successful cutover notice.
	 */
	public function output_success_notice(): void {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'WooPayments is now fully native in WooCommerce. Everything works as before.', 'woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output the blocked cutover notice.
	 */
	public function output_blocked_notice(): void {
		$failures = $this->get_preflight_failures();
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'WooPayments could not be disabled because native WooPayments is not ready to process payments yet.', 'woocommerce' ); ?></p>
			<?php if ( in_array( 'legacy_stripe_billing_subscriptions_present', $failures, true ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: WooCommerce Subscriptions product name. */
						esc_html__( 'This store still has legacy Stripe Billing subscription data. Install %s, run the WooPayments Stripe Billing migration from the WooPayments extension, then try again.', 'woocommerce' ),
						'WooCommerce Subscriptions'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
