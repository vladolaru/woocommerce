<?php
/**
 * WooPaymentsCutoverController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the cutover notices, takes the merchant's start click and the notice dismissals, and applies the mandatory lever.
 *
 * The plugin lifecycle listener calls its activation and deactivation handlers.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsCutoverController implements RegisterHooksInterface {

	/**
	 * Query action value that starts the switch.
	 *
	 * @var string
	 */
	public const ACTION_START = 'start';

	/**
	 * Filter that controls mandatory WooPayments auto-deactivation.
	 *
	 * @var string
	 */
	public const FILTER_MANDATORY_CUTOVER_ENABLED = 'woocommerce_woopayments_mandatory_cutover_enabled';

	/**
	 * Default state of the mandatory lever: off, and a host turns it on through the filter.
	 *
	 * @var bool
	 */
	public const DEFAULT_MANDATORY_CUTOVER_ENABLED = false;

	/**
	 * Nonce action for the one-click start action.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'woocommerce_woopayments_cutover_start';

	/**
	 * Nonce query parameter for the one-click start action.
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
	 * Admin action that dismisses a cutover notice (completion, bundled exclusion or reconnect).
	 *
	 * @var string
	 */
	public const ACTION_DISMISS_NOTICE = 'dismiss_notice';

	/**
	 * Query argument naming the cutover notice to dismiss.
	 *
	 * @var string
	 */
	public const QUERY_NOTICE = 'wc_woopayments_cutover_notice';

	/**
	 * Nonce action for cutover notice dismissals.
	 *
	 * @var string
	 */
	public const DISMISS_NONCE_ACTION = 'woocommerce_woopayments_cutover_dismiss_notice';

	/**
	 * Notice key of the switch notice, which "Not now" hides outside Payments settings.
	 *
	 * @var string
	 */
	public const NOTICE_START_SWITCH = 'start_switch';

	/**
	 * Time "Not now" hides the switch notice outside Payments settings.
	 *
	 * @var int
	 */
	private const START_NOTICE_SNOOZE = 30 * DAY_IN_SECONDS;

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
	 * Cutover preflight service.
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
	 * @param WooPaymentsRuntimeArbiter           $arbiter          Runtime owner arbiter.
	 * @param LegacyProxy                         $legacy_proxy     Legacy proxy.
	 * @param WooPaymentsCutoverPreflightService  $preflight_service Cutover preflight service.
	 * @param WooPaymentsCutoverReconciliationJob $reconciliation_job Durable reconciliation workflow.
	 */
	final public function init(
		WooPaymentsRuntimeArbiter $arbiter,
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
	 * Register the admin notice and click hooks.
	 *
	 * The plugin lifecycle hooks are registered by WooPaymentsCutoverPluginLifecycleListener, which loads on every request
	 * class where a plugin can change and calls this controller's handlers.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
		add_action( 'admin_notices', array( $this, 'output_admin_notices' ) );
	}

	/**
	 * Handle admin init cutover actions.
	 *
	 * @internal
	 */
	public function handle_admin_init(): void {
		$this->enqueue_mandatory_cutover();

		$action = isset( $_GET[ self::QUERY_ACTION ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ACTION ] ) ) : '';
		if ( self::ACTION_DISMISS_NOTICE === $action ) {
			$this->dismiss_notice();
			return;
		}
		if ( self::ACTION_START !== $action ) {
			return;
		}

		$nonce = isset( $_GET[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->refuse_action();
		}

		$this->enqueue_merchant_start();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		$this->legacy_proxy->exit();
	}

	/**
	 * Persist a store manager's dismissal of a cutover notice, then return to the same page: the completion, bundled
	 * exclusion or reconnect notice on the durable record, or "Not now" on the switch notice for 30 days.
	 */
	private function dismiss_notice(): void {
		$nonce = isset( $_GET[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_NONCE_ACTION ) ) {
			$this->refuse_action();
		}
		if ( ! $this->legacy_proxy->call_function( 'current_user_can', 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce' ), '', array( 'response' => 403 ) );
		}

		$notice = isset( $_GET[ self::QUERY_NOTICE ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_NOTICE ] ) ) : '';
		if ( self::NOTICE_START_SWITCH === $notice ) {
			update_option( WooPaymentsCutoverReconciliationJob::START_NOTICE_SNOOZED_UNTIL_OPTION, (int) $this->legacy_proxy->call_function( 'time' ) + self::START_NOTICE_SNOOZE );
		} else {
			$this->reconciliation_job->dismiss_notice( $notice );
		}

		$return_url = remove_query_arg( array( self::QUERY_ACTION, self::QUERY_NOTICE, self::NONCE_NAME ), $this->get_request_uri() );
		wp_safe_redirect( '' !== $return_url ? $return_url : admin_url() );
		$this->legacy_proxy->exit();
	}

	/**
	 * Get the current request URI.
	 *
	 * @return string
	 */
	private function get_request_uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	/**
	 * Build the nonce-protected dismiss URL for a cutover notice, returning to the current page.
	 *
	 * @param string $notice One of the WooPaymentsCutoverReconciliationJob::NOTICE_* constants, or NOTICE_START_SWITCH.
	 * @return string
	 */
	private function get_dismiss_notice_url( string $notice ): string {
		$url = add_query_arg(
			array(
				self::QUERY_ACTION => self::ACTION_DISMISS_NOTICE,
				self::QUERY_NOTICE => $notice,
			),
			'' !== $this->get_request_uri() ? $this->get_request_uri() : admin_url()
		);

		return wp_nonce_url( $url, self::DISMISS_NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Build the nonce-protected dismiss link for a cutover notice.
	 *
	 * @param string $notice One of the WooPaymentsCutoverReconciliationJob::NOTICE_* constants.
	 * @return string
	 */
	private function get_dismiss_notice_link( string $notice ): string {
		return sprintf(
			'<a class="woocommerce-message-close notice-dismiss" href="%s" style="position:relative;float:right;padding:9px 0 9px 9px;text-decoration:none;"><span class="screen-reader-text">%s</span></a>',
			esc_url( $this->get_dismiss_notice_url( $notice ) ),
			esc_html__( 'Dismiss this notice.', 'woocommerce' )
		);
	}

	/**
	 * Output WooPayments cutover admin notices.
	 *
	 * @internal
	 */
	public function output_admin_notices(): void {
		// Only store managers can act on the switch, and classifying it can be costly.
		if ( ! $this->legacy_proxy->call_function( 'current_user_can', 'manage_woocommerce' ) ) {
			return;
		}

		$this->output_cutover_notice();
	}

	/**
	 * Queue merchant-requested WooPayments reconciliation when the WooPayments feature is on and the request may start the switch.
	 *
	 * @return bool True when reconciliation was queued.
	 */
	public function enqueue_merchant_start(): bool {
		if ( ! $this->arbiter->is_builtin_enabled() || ! $this->can_start_cutover() ) {
			return false;
		}
		if ( $this->reconciliation_job->enqueue( WooPaymentsCutoverReconciliationJob::SOURCE_MERCHANT ) ) {
			return true;
		}

		// A store manager who may start the switch asked for it and nothing was queued, for example while another request held
		// the write lock.
		$this->legacy_proxy->call_function( 'wc_get_logger' )->error( 'WooPayments cutover: the merchant asked to start the switch, but no reconciliation was queued.', array( 'source' => 'woopayments-cutover' ) );
		$this->refuse_action();
	}

	/**
	 * Forget the cached bundled subscriptions classification when WooCommerce Subscriptions is deactivated.
	 *
	 * A deactivation of the WooPayments extension records nothing: it is never a request to switch.
	 *
	 * @internal
	 *
	 * @param mixed $plugin Deactivated plugin path from the public WordPress hook.
	 */
	public function handle_plugin_deactivated( $plugin ): void {
		if ( is_string( $plugin ) ) {
			$this->maybe_forget_admin_classification( $plugin );
		}
	}

	/**
	 * Open a new generation when activating the WooPayments extension again rolls back a completed cutover.
	 *
	 * An activation for every site of a multisite records nothing: each site is a store of its own, and a switched site
	 * beside the reactivated extension is reopened the next time an admin page reads its record.
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
		$this->maybe_forget_admin_classification( $plugin );
		if ( $network_wide ) {
			return;
		}
		$active_plugin_file = $this->preflight_service->get_active_woopayments_plugin_file();
		if ( WooPaymentsRuntimeArbiter::PLUGIN_FILE !== $plugin && $active_plugin_file !== $plugin ) {
			return;
		}

		$this->reconciliation_job->record_plugin_activation();
	}

	/**
	 * Forget the cached bundled subscriptions classification when WooCommerce Subscriptions changes, since it decides the exclusion.
	 *
	 * Current site only: other sites catch up when their cached classification expires.
	 *
	 * @param string $plugin Activated or deactivated plugin path.
	 */
	private function maybe_forget_admin_classification( string $plugin ): void {
		if ( WooPaymentsBundledSubscriptionsGuard::SUBSCRIPTIONS_PLUGIN_FILE === basename( $plugin ) ) {
			$this->reconciliation_job->forget_admin_classification();
		}
	}

	/**
	 * Refuse activating the WooPayments extension on a store that never ran it while the built-in WooPayments owns payments.
	 *
	 * A store that ran the extension (its version option is stored) may activate it, which is the rollback after a switch.
	 *
	 * @internal
	 */
	public function guard_woopayments_activation(): void {
		if ( $this->reconciliation_job->is_internal_plugin_lifecycle_change() || Constants::is_true( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' ) ) {
			return;
		}

		if ( ! $this->arbiter->is_builtin_owner() || $this->arbiter->has_run_woopayments_extension() ) {
			return;
		}

		wp_die(
			esc_html__( 'WooPayments is already available in WooCommerce. Set up WooPayments in Payments settings instead.', 'woocommerce' ),
			esc_html__( 'Plugin activation error', 'woocommerce' ),
			array(
				'link_url'  => esc_url( Utils::wc_payments_settings_url() ),
				'link_text' => esc_html__( 'Go to Payments settings', 'woocommerce' ),
			)
		);
	}

	/**
	 * Queue the mandatory cutover when the lever is on and the WooPayments extension owns the store.
	 */
	private function enqueue_mandatory_cutover(): void {
		if (
			! $this->is_mandatory_cutover_enabled() ||
			! $this->arbiter->is_extension_owner() ||
			Constants::is_true( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' )
		) {
			return;
		}

		$this->reconciliation_job->enqueue( WooPaymentsCutoverReconciliationJob::SOURCE_MANDATORY );
	}

	/**
	 * Tell whether the mandatory lever is on.
	 *
	 * @return bool
	 */
	private function is_mandatory_cutover_enabled(): bool {
		/**
		 * Filters whether the mandatory lever starts the cutover on admin requests of stores where the WooPayments extension owns payments.
		 *
		 * @since 11.2.0
		 *
		 * @param bool $enabled Whether the mandatory lever is on.
		 */
		return (bool) apply_filters( self::FILTER_MANDATORY_CUTOVER_ENABLED, self::DEFAULT_MANDATORY_CUTOVER_ENABLED );
	}

	/**
	 * Tell whether this request may start the switch: it may manage it, the WooPayments extension is not active for every
	 * site (a switch deactivates it on its own site only), and a store waiting for its switch last ran an extension version
	 * the switch supports.
	 *
	 * @return bool
	 */
	private function can_start_cutover(): bool {
		return $this->can_manage_switch() && ! $this->preflight_service->is_woopayments_network_active() && ! $this->needs_extension_update_first();
	}

	/**
	 * Tell whether a store waiting for its switch last ran a WooPayments extension too old to switch from.
	 *
	 * Only an active extension can be updated (the job updates it while it owns payments), and its version is recorded only
	 * when it runs, so such a store never gets through the switch's version check until the merchant updates it.
	 *
	 * @return bool
	 */
	private function needs_extension_update_first(): bool {
		return ! $this->arbiter->is_extension_owner() && $this->arbiter->is_awaiting_switch() && ! $this->preflight_service->is_woopayments_plugin_version_supported();
	}

	/**
	 * Tell whether this request may manage the switch: the WooPayments extension owns payments, or the store waits for its
	 * switch after the extension was deactivated, and the current user may manage WooCommerce and activate plugins.
	 *
	 * @return bool
	 */
	private function can_manage_switch(): bool {
		if ( ! ( $this->arbiter->is_extension_owner() || $this->arbiter->is_awaiting_switch() ) || ! $this->legacy_proxy->call_function( 'current_user_can', 'manage_woocommerce' ) ) {
			return false;
		}

		return (bool) $this->legacy_proxy->call_function( 'current_user_can', 'activate_plugins' );
	}

	/**
	 * Get the nonce-protected URL of the link that starts the switch.
	 *
	 * @return string
	 */
	private function get_start_url(): string {
		$url = add_query_arg(
			array(
				self::QUERY_ACTION => self::ACTION_START,
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $url, self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Output the notice that offers the switch, with "Not now" outside Payments settings.
	 *
	 * @param bool $can_snooze Whether the notice offers "Not now".
	 */
	private function output_start_switch_notice( bool $can_snooze ): void {
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'WooPayments is now part of WooCommerce. Start the switch: we will migrate what is needed and disable the WooPayments extension.', 'woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->get_start_url() ); ?>">
					<?php esc_html_e( 'Start the switch', 'woocommerce' ); ?>
				</a>
				<?php if ( $can_snooze ) : ?>
					<a href="<?php echo esc_url( $this->get_dismiss_notice_url( self::NOTICE_START_SWITCH ) ); ?>">
						<?php esc_html_e( 'Not now', 'woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Tell whether the request is the Payments settings tab or one of its pages, where the switch notice stays.
	 *
	 * @return bool
	 */
	private function is_payments_settings_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request routing.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && 'checkout' === $tab;
	}

	/**
	 * Tell whether "Not now" still hides the switch notice.
	 *
	 * @return bool
	 */
	private function is_start_notice_snoozed(): bool {
		return (int) get_option( WooPaymentsCutoverReconciliationJob::START_NOTICE_SNOOZED_UNTIL_OPTION, 0 ) > (int) $this->legacy_proxy->call_function( 'time' );
	}

	/**
	 * Offer the switch, or say what stands in the way: the WooPayments extension is active for every site, or a store
	 * waiting for its switch last ran an extension too old to switch from.
	 */
	private function output_start_notice(): void {
		if ( $this->preflight_service->is_woopayments_network_active() ) {
			$this->output_sitewide_refusal_notice();
			return;
		}
		if ( $this->can_start_cutover() ) {
			// Payments settings keeps the switch notice, with no "Not now"; every other admin page honours the snooze.
			$on_payments_settings = $this->is_payments_settings_request();
			if ( ( $on_payments_settings || ! $this->is_start_notice_snoozed() ) && $this->reconciliation_job->can_offer_start() ) {
				$this->output_start_switch_notice( ! $on_payments_settings );
			}
			return;
		}
		if ( $this->can_manage_switch() && $this->needs_extension_update_first() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooPayments is now part of WooCommerce. To switch, first activate the WooPayments extension in Plugins and update it to the latest version.', 'woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * Render the notice derived from durable reconciliation state.
	 */
	private function output_cutover_notice(): void {
		$record = $this->reconciliation_job->refresh_notice_record();
		if ( is_array( $record ) ) {
			if ( $this->reconciliation_job->is_awaiting_start( $record ) ) {
				$this->output_start_notice();
				return;
			}
			if ( in_array( $record['state'], array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_RUNNING, WooPaymentsCutoverStateStore::STATE_DEFERRED ), true ) ) {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Switch in progress', 'woocommerce' ) . '</p></div>';
				// A switch whose extension became active for every site after its start waits for the per-site activation, and stops saying so once it no longer is.
				if ( WooPaymentsCutoverStateStore::STATE_DEFERRED === $record['state'] && $this->preflight_service->is_woopayments_network_active() && in_array( WooPaymentsCutoverReconciliationJob::CODE_PLUGIN_ACTIVE_SITEWIDE, (array) ( $record[ WooPaymentsCutoverStateStore::BLOCKING_CODES_KEY ] ?? array() ), true ) ) {
					$this->output_sitewide_refusal_notice();
				}
				// The job cannot update the extension itself where the site blocks file changes, so retries alone never finish.
				if ( WooPaymentsCutoverStateStore::STATE_DEFERRED === $record['state'] && in_array( WooPaymentsCutoverPreflightService::FAILURE_PLUGIN_VERSION_UNSUPPORTED, (array) ( $record[ WooPaymentsCutoverStateStore::BLOCKING_CODES_KEY ] ?? array() ), true ) && ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'The switch needs a newer version of the WooPayments extension, but this site does not allow automatic plugin updates. Update WooPayments to continue the switch.', 'woocommerce' ) . '</p></div>';
				}
				// Shown until dismissed; rendering claims nothing, since some screens hide notices.
				if ( $this->reconciliation_job->is_reconnect_notice_due( $record ) ) {
					$this->output_reconnect_notice();
				}
				return;
			}
			if ( WooPaymentsCutoverStateStore::STATE_EXCLUDED === $record['state'] ) {
				if ( $this->reconciliation_job->is_bundled_exclusion_notice_due( $record ) ) {
					$this->output_bundled_exclusion_notice();
				}
				return;
			}
			if ( WooPaymentsCutoverStateStore::STATE_DONE === $record['state'] && ! $this->arbiter->is_extension_owner() ) {
				// Shown until dismissed; rendering claims nothing, since some screens hide notices.
				if ( $this->reconciliation_job->is_completion_notice_due( $record, WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS ) ) {
					$this->output_success_notice();
				}
				return;
			}
		}

		$this->output_start_notice();
	}

	/**
	 * Output the notice telling a store that it cannot switch while the WooPayments extension is active for every site, with
	 * the button that activates WooPayments per site for a user who may manage network plugins.
	 */
	private function output_sitewide_refusal_notice(): void {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'WooPayments is now part of WooCommerce. This store can\'t switch yet because the WooPayments extension is active for every site of the network. A super admin first needs to activate WooPayments on each site instead.', 'woocommerce' ) . '</p>';
		if ( $this->legacy_proxy->call_function( 'current_user_can', 'manage_network_plugins' ) ) {
			echo '<p>';
			WooPaymentsPerSiteActivationController::output_button();
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Output the notice telling a store whose connection owner is gone that the switch needs a reconnect.
	 */
	private function output_reconnect_notice(): void {
		?>
		<div class="notice notice-info" style="position:relative;">
			<?php echo $this->get_dismiss_notice_link( WooPaymentsCutoverReconciliationJob::NOTICE_RECONNECT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
			<p><?php esc_html_e( 'The connection owner is no longer available. Reconnect this site to continue the switch.', 'woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output the notice telling a store on the bundled WooPayments subscriptions why it cannot switch yet.
	 *
	 * Its last sentence says what keeps payments running: the extension while it is active, or the extension again on a store
	 * that deactivated it and waits for its switch, where nothing owns payments.
	 */
	private function output_bundled_exclusion_notice(): void {
		$last_sentence = $this->arbiter->is_awaiting_switch()
			? __( 'Until then, activate the WooPayments extension again to keep taking payments.', 'woocommerce' )
			: __( 'Until then nothing changes and WooPayments keeps running from the WooPayments extension.', 'woocommerce' );
		?>
		<div class="notice notice-info" style="position:relative;">
			<?php echo $this->get_dismiss_notice_link( WooPaymentsCutoverReconciliationJob::NOTICE_BUNDLED_EXCLUSION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
			<p><?php esc_html_e( "WooPayments is now part of WooCommerce, but this store can't switch yet. Its subscriptions are billed through Stripe Billing, which needs the Woo Subscriptions extension. Install and activate Woo Subscriptions to make the switch available.", 'woocommerce' ); ?> <?php echo esc_html( $last_sentence ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output the successful cutover notice.
	 */
	private function output_success_notice(): void {
		?>
		<div class="notice notice-success" style="position:relative;">
			<?php echo $this->get_dismiss_notice_link( WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
			<p><?php esc_html_e( 'WooPayments is now built into WooCommerce. Everything works as before.', 'woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Stop a cutover admin action that cannot go ahead, asking the store manager to refresh and retry.
	 *
	 * @return never
	 */
	private function refuse_action(): void {
		wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
	}
}
