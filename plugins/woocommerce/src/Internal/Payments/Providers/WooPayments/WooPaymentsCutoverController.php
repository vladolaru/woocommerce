<?php
/**
 * WooPaymentsCutoverController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
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
	 * Minimum last-active WooPayments plugin version eligible for native cutover.
	 *
	 * @var string
	 */
	public const MINIMUM_CUTOVER_PLUGIN_VERSION = '10.5.0';

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

	/** Legacy query parameter retained for URL compatibility. */
	public const QUERY_STATUS = 'wc_woopayments_cutover_status';

	/**
	 * Legacy status value retained for URL compatibility.
	 *
	 * @var string
	 */
	public const STATUS_DISABLED = 'disabled';

	/**
	 * Legacy blocked value retained for URL compatibility.
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
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Headless cutover preflight facts.
	 *
	 * @var WooPaymentsCutoverPreflightService
	 */
	private WooPaymentsCutoverPreflightService $preflight_service;

	/**
	 * Durable reconciliation workflow.
	 *
	 * @var WooPaymentsCutoverReconciliationJob
	 */
	private WooPaymentsCutoverReconciliationJob $reconciliation_job;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter        $arbiter          Runtime owner arbiter.
	 * @param LegacyProxy                         $legacy_proxy     Legacy proxy.
	 * @param WooPaymentsCutoverPreflightService  $preflight_service Headless cutover facts.
	 * @param WooPaymentsCutoverReconciliationJob $reconciliation_job Durable reconciliation workflow.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		LegacyProxy $legacy_proxy,
		WooPaymentsCutoverPreflightService $preflight_service,
		WooPaymentsCutoverReconciliationJob $reconciliation_job
	): void {
		$this->arbiter            = $arbiter;
		$this->legacy_proxy       = $legacy_proxy;
		$this->preflight_service  = $preflight_service;
		$this->reconciliation_job = $reconciliation_job;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
		add_action( 'admin_notices', array( $this, 'output_admin_notices' ) );
		add_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE, array( $this, 'guard_woopayments_activation' ) );
		add_action( 'activated_plugin', array( $this, 'handle_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivated' ), 10, 2 );
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

		$this->disable_woopayments_plugin();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		$this->legacy_proxy->exit();
	}

	/**
	 * Output WooPayments cutover admin notices.
	 *
	 * @internal
	 */
	public function output_admin_notices(): void {
		$this->output_reconciliation_notice();
	}

	/**
	 * Tell whether the soft cutover notice should be shown.
	 *
	 * @return bool
	 */
	public function should_show_soft_cutover_notice(): bool {
		if ( ! $this->is_start_eligible() ) {
			return false;
		}
		$this->reconciliation_job->classify_for_admin_notice();
		return $this->reconciliation_job->should_offer_start();
	}

	/**
	 * Queue merchant-requested WooPayments reconciliation when the request is eligible.
	 *
	 * @return bool True when reconciliation was queued.
	 */
	public function disable_woopayments_plugin(): bool {
		if ( ! $this->arbiter->is_plugin_runtime_active() || ! $this->current_user_can_cutover() ) {
			return false;
		}
		return $this->reconciliation_job->enqueue( 'merchant' );
	}

	/**
	 * Record a manual WooPayments deactivation for reconciliation in a later request.
	 *
	 * @internal
	 *
	 * @param mixed $plugin               Deactivated plugin path from the public WordPress hook.
	 * @param mixed $network_deactivating Whether WordPress is deactivating the plugin network-wide.
	 */
	public function handle_plugin_deactivated( $plugin, $network_deactivating ): void {
		if ( $this->reconciliation_job->is_internal_plugin_lifecycle_change() || ! is_string( $plugin ) || ! is_bool( $network_deactivating ) ) {
			return;
		}
		$active_plugin_file = $this->preflight_service->get_active_woopayments_plugin_file();
		if ( NativePaymentsRuntimeArbiter::PLUGIN_FILE !== $plugin && $active_plugin_file !== $plugin ) {
			return;
		}

		$this->reconciliation_job->enqueue_manual_deactivation( $plugin, $network_deactivating );
	}

	/**
	 * Open a new generation when a completed WooPayments cutover is rolled back by plugin activation.
	 *
	 * @internal
	 *
	 * @param mixed $plugin       Activated plugin path from the public WordPress hook.
	 * @param mixed $network_wide Whether WordPress activated the plugin network-wide.
	 */
	public function handle_plugin_activated( $plugin, $network_wide ): void {
		if ( $this->reconciliation_job->is_internal_plugin_lifecycle_change() || ! is_string( $plugin ) || ! is_bool( $network_wide ) ) {
			return;
		}
		$active_plugin_file = $this->preflight_service->get_active_woopayments_plugin_file();
		if ( NativePaymentsRuntimeArbiter::PLUGIN_FILE !== $plugin && $active_plugin_file !== $plugin ) {
			return;
		}

		$this->reconciliation_job->record_plugin_activation( $network_wide );
	}

	/**
	 * Guard WooPayments activation once mandatory native cutover is enabled.
	 *
	 * @internal
	 */
	public function guard_woopayments_activation(): void {
		$record = $this->reconciliation_job->get_state_record();
		if (
			$this->reconciliation_job->is_internal_plugin_lifecycle_change() ||
			! $this->is_mandatory_cutover_enabled() ||
			! is_array( $record ) || WooPaymentsCutoverState::DONE !== $record['state'] ||
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
		return $this->preflight_service->get_preflight_failures();
	}

	/**
	 * Get site IDs whose per-site preflight blocks network-wide deactivation.
	 *
	 * @return int[] Failing site IDs in ascending order.
	 */
	public function get_network_preflight_failing_site_ids(): array {
		return $this->preflight_service->get_network_preflight_failing_site_ids();
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

		$this->reconciliation_job->enqueue( 'mandatory' );
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
		return $this->preflight_service->is_woopayments_network_active();
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
				<?php esc_html_e( 'WooPayments is now part of WooCommerce. Start the switch: we will migrate what is needed and disable the WooPayments extension.', 'woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->get_disable_url() ); ?>">
					<?php esc_html_e( 'Start the switch', 'woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the notice derived from durable reconciliation state.
	 */
	private function output_reconciliation_notice(): void {
		$record = $this->reconciliation_job->classify_for_admin_notice();
		if ( is_array( $record ) ) {
			if ( WooPaymentsCutoverState::PENDING === $record['state'] && 'awaiting_merchant_start' === ( $record['current_step'] ?? null ) ) {
				if ( $this->is_start_eligible() && $this->reconciliation_job->should_offer_start() ) {
					$this->output_soft_cutover_notice();
				}
				return;
			}
			if ( in_array( $record['state'], array( WooPaymentsCutoverState::PENDING, WooPaymentsCutoverState::RUNNING, WooPaymentsCutoverState::DEFERRED ), true ) ) {
				$is_manual_deactivation = is_string( $record['origin_plugin_file'] ?? null ) && '' !== $record['origin_plugin_file'] && in_array( $record['origin_plugin_scope'] ?? null, array( 'site', 'network' ), true );
				if ( ! $is_manual_deactivation ) {
					?>
						<div class="notice notice-info"><p><?php esc_html_e( 'Switch in progress', 'woocommerce' ); ?></p></div>
						<?php
				}
				if ( $this->reconciliation_job->consume_reconnect_notice() ) {
					?>
						<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'The connection owner is no longer available. Reconnect this site to continue the switch.', 'woocommerce' ); ?></p></div>
						<?php
				}
					return;
			}
			if ( WooPaymentsCutoverState::EXCLUDED === $record['state'] ) {
				return;
			}
			if ( WooPaymentsCutoverState::DONE === $record['state'] && ! $this->arbiter->is_plugin_runtime_active() ) {
				$this->output_success_notice();
				$this->output_disabled_payment_methods_notice( $record['informational_outcomes'] ?? array() );
				return;
			}
		}

		if ( $this->is_start_eligible() && $this->reconciliation_job->should_offer_start() ) {
			$this->output_soft_cutover_notice();
		}
	}

	/**
	 * Tell whether the current admin request may offer the merchant start action.
	 *
	 * @return bool
	 */
	private function is_start_eligible(): bool {
		return $this->is_soft_cutover_enabled() && $this->arbiter->is_plugin_runtime_active() && $this->current_user_can_cutover();
	}

	/**
	 * Render the payment methods disabled during reconciliation.
	 *
	 * @param array<int,mixed> $outcomes Persisted informational outcomes.
	 */
	private function output_disabled_payment_methods_notice( array $outcomes ): void {
		foreach ( $outcomes as $outcome ) {
			if ( ! is_array( $outcome ) || 'unsupported_payment_methods_disabled' !== ( $outcome['code'] ?? null ) || ! is_array( $outcome['payment_method_ids'] ?? null ) ) {
				continue;
			}
			?>
			<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Some payment methods that are not supported by native WooPayments were disabled during the switch.', 'woocommerce' ); ?></p></div>
			<?php
			return;
		}
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
}
