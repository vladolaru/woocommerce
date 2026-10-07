<?php
/**
 * WooPaymentsCutoverPluginLifecycleListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Listens to WooPayments plugin activation and deactivation on every request class and hands them to the cutover controller.
 *
 * WordPress fires these hooks from activate_plugin() and deactivate_plugins() in whatever request calls them: wp-admin,
 * admin-ajax, the wp/v2/plugins and wc-admin plugin REST routes, Action Scheduler and WP-CLI. The client registers its
 * lifecycle callbacks on every request (client 11.1.0 `woocommerce-payments.php:67-68`). The controller is resolved only
 * when a plugin changes, so requests that change no plugin pay for three hook registrations.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCutoverPluginLifecycleListener implements RegisterHooksInterface {

	/**
	 * Register the plugin lifecycle hooks.
	 */
	public function register() {
		add_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE, array( $this, 'guard_woopayments_activation' ) );
		add_action( 'activated_plugin', array( $this, 'handle_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivated' ), 10, 2 );
	}

	/**
	 * Refuse a WooPayments activation the cutover does not allow.
	 *
	 * @internal
	 */
	public function guard_woopayments_activation(): void {
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
	 * Hand a plugin deactivation to the cutover controller.
	 *
	 * @internal
	 *
	 * @param mixed $plugin               Deactivated plugin path from the public WordPress hook.
	 * @param mixed $network_deactivating Whether WordPress deactivated the plugin network-wide; a caller firing the hook with one argument means no.
	 */
	public function handle_plugin_deactivated( $plugin, $network_deactivating = false ): void {
		$this->get_controller()->handle_plugin_deactivated( $plugin, $network_deactivating );
	}

	/**
	 * Get the cutover controller.
	 *
	 * @return WooPaymentsCutoverController
	 */
	private function get_controller(): WooPaymentsCutoverController {
		return wc_get_container()->get( WooPaymentsCutoverController::class );
	}
}
