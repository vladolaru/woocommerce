<?php
/**
 * WooPaymentsPerSiteActivationController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a super admin turn a network-wide activation of the WooPayments extension into one activation per site.
 *
 * Each site switches to WooPayments in WooCommerce on its own, and a switch deactivates the extension on its own site only,
 * so no site can start it while the extension is network-active. The network admin notice offers the conversion while the
 * extension is network-active and the WooPayments feature is on at one site or more. The conversion moves no data, writes
 * no cutover record and leaves the feature as it is.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPerSiteActivationController implements RegisterHooksInterface {

	/** Query argument that carries the action. */
	public const QUERY_ACTION = 'wc_woopayments_per_site_activation';

	/** Action value that runs the conversion. */
	public const ACTION_CONVERT = 'convert';

	/** Nonce action of the conversion button. */
	public const NONCE_ACTION = 'woocommerce_woopayments_per_site_activation';

	/** Nonce query argument of the conversion button. */
	public const NONCE_NAME = '_wc_woopayments_per_site_nonce';

	/** Query argument set on the page a conversion returns to. */
	public const QUERY_DONE = 'wc_woopayments_per_site_done';

	/** Query argument listing the sites where the activation failed. */
	public const QUERY_FAILED = 'wc_woopayments_per_site_failed';

	/** Query argument listing the sites that keep WooPayments in WooCommerce. */
	public const QUERY_KEPT = 'wc_woopayments_per_site_kept';

	/** Log source shared with the switch. */
	private const LOG_SOURCE = 'woocommerce-woopayments-cutover';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Plugin activity facts.
	 *
	 * @var WooPaymentsCutoverPreflightService
	 */
	private WooPaymentsCutoverPreflightService $preflight_service;

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Whether the conversion is offered, worked out once per request.
	 *
	 * @var bool|null
	 */
	private ?bool $is_offered = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter          $arbiter           Runtime owner arbiter.
	 * @param WooPaymentsCutoverPreflightService $preflight_service Plugin activity facts.
	 * @param LegacyProxy                        $legacy_proxy      Legacy proxy.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsCutoverPreflightService $preflight_service, LegacyProxy $legacy_proxy ): void {
		$this->arbiter           = $arbiter;
		$this->preflight_service = $preflight_service;
		$this->legacy_proxy      = $legacy_proxy;
	}

	/**
	 * Register the network admin notice and its button's handler.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_admin_init' ) );
		add_action( 'network_admin_notices', array( $this, 'output_network_admin_notice' ) );
	}

	/**
	 * Run the conversion when a super admin clicks the button, then return to the network Plugins page with its result.
	 *
	 * @internal
	 */
	public function handle_admin_init(): void {
		$action = isset( $_GET[ self::QUERY_ACTION ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ACTION ] ) ) : '';
		if ( self::ACTION_CONVERT !== $action ) {
			return;
		}
		$nonce = isset( $_GET[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
		}
		if ( ! $this->legacy_proxy->call_function( 'current_user_can', 'manage_network_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce' ), '', array( 'response' => 403 ) );
		}

		$result = $this->convert();
		wp_safe_redirect(
			add_query_arg(
				array(
					self::QUERY_DONE   => 1,
					self::QUERY_FAILED => implode( ',', $result['failed'] ),
					self::QUERY_KEPT   => implode( ',', $result['kept'] ),
				),
				network_admin_url( 'plugins.php' )
			)
		);
		$this->legacy_proxy->exit();
	}

	/**
	 * Deactivate the WooPayments extension network-wide, then activate it on each site that is not deleted.
	 *
	 * Archived and spam sites are included, so a restored site keeps the extension. Both steps run WordPress's hooks, as
	 * they would by hand. A site where the extension is already active is skipped, so a run after a partial failure finishes
	 * the conversion. A site with the WooPayments feature on that never ran the extension is skipped too: WooPayments in
	 * WooCommerce owns its payments once the extension is gone, and the activation guard refuses the extension there.
	 * Between the network deactivation and its own activation, a site has no WooPayments owner, for a few seconds.
	 *
	 * @return array<string,int[]> Site IDs by outcome: activated, skipped (already active), kept and failed.
	 */
	public function convert(): array {
		$result      = array(
			'activated' => array(),
			'skipped'   => array(),
			'kept'      => array(),
			'failed'    => array(),
		);
		$site_ids    = $this->get_site_ids();
		$plugin_file = $this->get_plugin_file();
		if ( '' === $plugin_file ) {
			$this->log( 'error', 'WooPayments per-site activation found no WooPayments extension to activate.', array() );
			$result['failed'] = $site_ids;
			return $result;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( $this->preflight_service->is_woopayments_network_active() ) {
			$this->legacy_proxy->call_function( 'deactivate_plugins', $plugin_file, false, true );
			if ( array_key_exists( $plugin_file, (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) ) {
				$this->log( 'error', 'WooPayments per-site activation could not deactivate the network-wide WooPayments extension.', array( 'plugin_file' => $plugin_file ) );
				$result['failed'] = $site_ids;
				return $result;
			}
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				// The owner read before the deactivation is stale.
				$this->arbiter->invalidate();
				$outcome = $this->activate_on_current_site( $plugin_file );
			} catch ( \Throwable $error ) {
				$this->log( 'error', 'WooPayments per-site activation failed on a site.', array( 'error' => $error->getMessage() ) );
				$outcome = 'failed';
			} finally {
				restore_current_blog();
			}
			$result[ $outcome ][] = $site_id;
		}

		return $result;
	}

	/**
	 * Output the network admin notice: the conversion offer, or the result of the last run.
	 *
	 * @internal
	 */
	public function output_network_admin_notice(): void {
		if ( ! is_multisite() || ! $this->legacy_proxy->call_function( 'current_user_can', 'manage_network_plugins' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the redirect's result.
		if ( isset( $_GET[ self::QUERY_DONE ] ) ) {
			$failed = $this->parse_site_ids( isset( $_GET[ self::QUERY_FAILED ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_FAILED ] ) ) : '' );
			$kept   = $this->parse_site_ids( isset( $_GET[ self::QUERY_KEPT ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_KEPT ] ) ) : '' );
			$this->output_result_notice( $failed, $kept );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $this->is_conversion_offered() ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'WooPayments is now part of WooCommerce, and each site switches to it on its own. No site can switch while the WooPayments extension is active for the whole network. Activate WooPayments per site so each site\'s administrators can start their switch. Best run off-peak: each site has no WooPayments for a few seconds while it is activated there.', 'woocommerce' ); ?></p>
			<p><?php $this->output_button(); ?></p>
		</div>
		<?php
	}

	/**
	 * Tell whether the conversion is offered: the WooPayments extension is network-active and the WooPayments feature is on
	 * at one site or more. Worked out once per request.
	 *
	 * @return bool
	 */
	private function is_conversion_offered(): bool {
		if ( null === $this->is_offered ) {
			$this->is_offered = $this->preflight_service->is_woopayments_network_active() && $this->is_feature_on_at_some_site();
		}

		return $this->is_offered;
	}

	/**
	 * Tell whether the WooPayments feature is on at one site of the network or more, deleted sites aside.
	 *
	 * @return bool
	 */
	private function is_feature_on_at_some_site(): bool {
		foreach ( $this->get_site_ids() as $site_id ) {
			switch_to_blog( $site_id );
			try {
				if ( $this->arbiter->is_builtin_enabled() ) {
					return true;
				}
			} finally {
				restore_current_blog();
			}
		}

		return false;
	}

	/**
	 * Activate the WooPayments extension on the current site, unless it is active already or the site keeps WooPayments
	 * in WooCommerce.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return string One of activated, skipped, kept or failed.
	 */
	private function activate_on_current_site( string $plugin_file ): string {
		$context = array( 'site_id' => get_current_blog_id() );
		if ( $this->preflight_service->is_woopayments_site_active() ) {
			$this->log( 'info', 'WooPayments per-site activation: the WooPayments extension is already active on this site.', $context );
			return 'skipped';
		}
		if ( $this->arbiter->is_builtin_enabled() && ! $this->arbiter->has_run_woopayments_extension() ) {
			$this->log( 'info', 'WooPayments per-site activation: WooPayments in WooCommerce owns payments here, so the WooPayments extension is not activated.', $context );
			return 'kept';
		}

		$activated = $this->legacy_proxy->call_function( 'activate_plugin', $plugin_file );
		if ( is_wp_error( $activated ) ) {
			$this->log( 'error', 'WooPayments per-site activation could not activate the WooPayments extension on this site.', array_merge( $context, array( 'error' => $activated->get_error_message() ) ) );
			return 'failed';
		}
		$this->log( 'info', 'WooPayments per-site activation activated the WooPayments extension on this site.', $context );

		return 'activated';
	}

	/**
	 * Get the WooPayments extension's plugin file: the active one, else an installed copy, the canonical folder first.
	 *
	 * @return string Plugin file relative to the plugins directory, or an empty string when none is installed.
	 */
	private function get_plugin_file(): string {
		$active = $this->preflight_service->get_active_woopayments_plugin_file();
		if ( '' !== $active ) {
			return $active;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = array_keys( (array) $this->legacy_proxy->call_function( 'get_plugins' ) );
		if ( in_array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, $installed, true ) ) {
			return WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		}
		foreach ( $installed as $plugin_file ) {
			if ( is_string( $plugin_file ) && WooPaymentsRuntimeArbiter::is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}

		return '';
	}

	/**
	 * Get the IDs of the network's sites that are not deleted; archived and spam sites are included.
	 *
	 * @return int[]
	 */
	private function get_site_ids(): array {
		$site_ids = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'deleted'    => 0,
				'fields'     => 'ids',
				'number'     => 0,
			)
		);

		return array_values( array_map( 'intval', $site_ids ) );
	}

	/**
	 * Output the result of a conversion: the sites that failed, with the button to finish, and the sites that keep
	 * WooPayments in WooCommerce.
	 *
	 * @param int[] $failed Sites where the activation failed.
	 * @param int[] $kept   Sites that keep WooPayments in WooCommerce.
	 */
	private function output_result_notice( array $failed, array $kept ): void {
		if ( array() !== $failed ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					/* translators: %s: comma-separated list of sites. */
					echo esc_html( sprintf( __( 'WooPayments could not be activated on these sites: %s. Activate WooPayments per site again to finish.', 'woocommerce' ), $this->get_site_labels( $failed ) ) );
					?>
				</p>
				<p><?php $this->output_button(); ?></p>
			</div>
			<?php
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'WooPayments is now active on each site. Each site\'s administrators can start their switch.', 'woocommerce' ) . '</p></div>';
		}
		if ( array() !== $kept ) {
			/* translators: %s: comma-separated list of sites. */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( 'Kept WooPayments in WooCommerce: %s.', 'woocommerce' ), $this->get_site_labels( $kept ) ) ) . '</p></div>';
		}
	}

	/**
	 * Output the conversion button.
	 */
	private function output_button(): void {
		$url = wp_nonce_url( add_query_arg( self::QUERY_ACTION, self::ACTION_CONVERT, network_admin_url( 'plugins.php' ) ), self::NONCE_ACTION, self::NONCE_NAME );
		printf( '<a class="button button-primary" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Activate WooPayments per site', 'woocommerce' ) );
	}

	/**
	 * Parse a comma-separated list of site IDs, keeping the network's sites only.
	 *
	 * @param string $value Query value.
	 * @return int[]
	 */
	private function parse_site_ids( string $value ): array {
		$ids = array_filter( array_map( 'absint', explode( ',', $value ) ) );

		return array_values( array_intersect( $ids, $this->get_site_ids() ) );
	}

	/**
	 * Label sites by their address, for the result notice.
	 *
	 * @param int[] $site_ids Site IDs.
	 * @return string Comma-separated addresses.
	 */
	private function get_site_labels( array $site_ids ): string {
		$labels = array();
		foreach ( $site_ids as $site_id ) {
			$site     = get_site( $site_id );
			$labels[] = $site instanceof \WP_Site ? untrailingslashit( $site->domain . $site->path ) : (string) $site_id;
		}

		return implode( ', ', $labels );
	}

	/**
	 * Write one log line under the switch's log source.
	 *
	 * @param string              $level   Log level: info or error.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	private function log( string $level, string $message, array $context ): void {
		wc_get_logger()->log( $level, $message, array_merge( $context, array( 'source' => self::LOG_SOURCE ) ) );
	}
}
