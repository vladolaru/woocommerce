<?php
/**
 * WooPaymentsCutoverController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
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
	 * Headless cutover preflight facts.
	 *
	 * @var WooPaymentsCutoverPreflightService
	 */
	private WooPaymentsCutoverPreflightService $preflight_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter       $arbiter          Runtime owner arbiter.
	 * @param LegacyProxy                        $legacy_proxy     Legacy proxy.
	 * @param WooPaymentsCutoverPreflightService $preflight_service Headless cutover facts.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		LegacyProxy $legacy_proxy,
		WooPaymentsCutoverPreflightService $preflight_service
	): void {
		$this->arbiter           = $arbiter;
		$this->legacy_proxy      = $legacy_proxy;
		$this->preflight_service = $preflight_service;
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
		return $this->preflight_service->deactivate_woopayments_plugin();
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
		if ( is_multisite() && $this->is_woopayments_network_active() ) {
			return array() === $this->get_network_preflight_failing_site_ids();
		}

		return array() === $this->get_preflight_failures();
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
		$failures         = $this->get_preflight_failures();
		$failing_site_ids = $this->get_network_preflight_failing_site_ids();
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'WooPayments could not be disabled because native WooPayments is not ready to process payments yet.', 'woocommerce' ); ?></p>
			<?php if ( array() !== $failing_site_ids ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: comma-separated list of site IDs. */
						esc_html( _n( 'Native WooPayments is not ready on site ID %s.', 'Native WooPayments is not ready on site IDs %s.', count( $failing_site_ids ), 'woocommerce' ) ),
						esc_html( implode( ', ', $failing_site_ids ) )
					);
					?>
				</p>
			<?php endif; ?>
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
