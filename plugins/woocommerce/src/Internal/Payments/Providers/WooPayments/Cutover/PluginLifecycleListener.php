<?php
/**
 * PluginLifecycleListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Hears WordPress activate or deactivate a plugin on every request class and hands the change to the cutover controller.
 *
 * WordPress fires these hooks from activate_plugin() and deactivate_plugins() in whatever request calls them: wp-admin,
 * admin-ajax, the wp/v2/plugins and wc-admin plugin REST routes, Action Scheduler and WP-CLI. The WooPayments extension
 * registers its lifecycle callbacks on every request (client 11.1.0 `woocommerce-payments.php:67-68`). It also hears a
 * plugin update finish. The controller is resolved only when a plugin changes, so requests that change no plugin pay for
 * four hook registrations.
 *
 * @since 11.2.0
 * @internal
 */
class PluginLifecycleListener implements RegisterHooksInterface {

	/**
	 * Register the plugin lifecycle hooks.
	 */
	public function register() {
		add_action( 'activate_plugin', array( $this, 'guard_woopayments_activation' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'handle_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivated' ) );
		add_action( 'upgrader_process_complete', array( $this, 'handle_upgrader_process_complete' ), 10, 2 );
	}

	/**
	 * Hand an update of the WooPayments extension's files to the cutover controller.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $upgrader   WordPress upgrader instance.
	 * @param mixed $hook_extra What the upgrader did, from the public WordPress hook.
	 */
	public function handle_upgrader_process_complete( $upgrader, $hook_extra = array() ): void {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? null ) || 'update' !== ( $hook_extra['action'] ?? null ) ) {
			return;
		}
		$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array( $hook_extra['plugin'] ?? null );
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && WooPaymentsRuntimeArbiter::is_woopayments_plugin_file( $plugin ) ) {
				$this->get_controller()->handle_woopayments_updated();
				return;
			}
		}
	}

	/**
	 * Refuse a WooPayments activation the cutover does not allow, from any plugin folder.
	 *
	 * The guard matches the arbiter's identity rule, the main file name in any folder, rather than the canonical
	 * folder's own activation hook. A network-wide activation is checked site by site by PerSiteActivationController.
	 *
	 * @internal
	 *
	 * @param mixed $plugin       Activated plugin path from the public WordPress hook.
	 * @param mixed $network_wide Whether WordPress activates the plugin for every site.
	 */
	public function guard_woopayments_activation( $plugin = '', $network_wide = false ): void {
		if ( true === $network_wide || ! is_string( $plugin ) || ! WooPaymentsRuntimeArbiter::is_woopayments_plugin_file( $plugin ) ) {
			return;
		}

		$this->get_controller()->guard_woopayments_activation();
	}

	/**
	 * Hand a plugin activation to the cutover controller.
	 *
	 * @internal
	 *
	 * @param mixed $plugin       Activated plugin path from the public WordPress hook.
	 * @param mixed $network_wide Whether WordPress activated the plugin network-wide; a caller firing the hook with one argument means no.
	 */
	public function handle_plugin_activated( $plugin, $network_wide = false ): void {
		$this->get_controller()->handle_plugin_activated( $plugin, $network_wide );
	}

	/**
	 * Hand a plugin deactivation to the cutover controller, which forgets its cached bundled subscriptions classification
	 * when WooCommerce Subscriptions changes and brings forward a switch held back by a renewal plugin; a WooPayments
	 * deactivation records nothing.
	 *
	 * @internal
	 *
	 * @param mixed $plugin Deactivated plugin path from the public WordPress hook.
	 */
	public function handle_plugin_deactivated( $plugin ): void {
		$this->get_controller()->handle_plugin_deactivated( $plugin );
	}

	/**
	 * Get the cutover controller.
	 *
	 * @return CutoverController
	 */
	private function get_controller(): CutoverController {
		return wc_get_container()->get( CutoverController::class );
	}
}
