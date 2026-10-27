<?php
/**
 * ExtensionBoundRenewalPlugins class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the plugins that charge renewals or scheduled payments through the WooPayments extension's own classes, finds the
 * active ones, and names them for merchant messages.
 *
 * The built-in WooPayments does not provide those classes, so the switch waits while one is active, and a store the
 * built-in WooPayments owns is told which ones cannot charge. The list here is the floor: a list served by the platform is
 * meant to replace it when present, and an empty or malformed answer keeps this one.
 *
 * @since 11.2.0
 * @internal
 */
class ExtensionBoundRenewalPlugins {

	/**
	 * The plugins by slug (the plugin's folder), each with its name and the version whose WooPayments integration was read.
	 *
	 * @var array<string,array{name:string,checked_version:string}>
	 */
	private const PLUGINS = array(
		'yith-woocommerce-subscription-premium' => array(
			'name'            => 'YITH WooCommerce Subscription Premium',
			'checked_version' => '4.26.3',
		),
		'order-subscriptions'                   => array(
			'name'            => 'Order Subscriptions for WooCommerce',
			'checked_version' => '1.2.15',
		),
		'ultimate-payment-plans'                => array(
			'name'            => 'Ultimate Payment Plans',
			'checked_version' => '4.5.0',
		),
		'deposit-and-partial-payment-plan'      => array(
			'name'            => 'Deposit and Partial Payment Plan',
			'checked_version' => '1.0.5',
		),
	);

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy $legacy_proxy Legacy proxy.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Get the listed plugins' files that are active for this site or, on a multisite, for the whole network.
	 *
	 * Their charges depend on WooPayments extension APIs the built-in WooPayments does not provide, so they are found by
	 * slug rather than by class. A listed plugin in a renamed folder is not found.
	 *
	 * @return string[]
	 */
	public function get_active_plugin_files(): array {
		$plugin_files = (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() );
		if ( $this->legacy_proxy->call_function( 'is_multisite' ) ) {
			$plugin_files = array_merge( $plugin_files, array_keys( (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) );
		}
		$matches = array();
		foreach ( $plugin_files as $plugin_file ) {
			if ( is_string( $plugin_file ) && isset( self::PLUGINS[ self::get_plugin_slug( $plugin_file ) ] ) && ! in_array( $plugin_file, $matches, true ) ) {
				$matches[] = $plugin_file;
			}
		}
		return $matches;
	}

	/**
	 * Get the names of the listed plugins that are active, for merchant messages.
	 *
	 * Each name comes from the plugin header, or is the plugin's slug when the header cannot be read.
	 *
	 * @return string[]
	 */
	public function get_active_plugin_names(): array {
		$plugin_files = $this->get_active_plugin_files();
		if ( array() === $plugin_files ) {
			return array();
		}
		// WordPress 6.8+ loads this file on every request (#62244); kept as WooCommerce's own pattern (see src/Admin/PluginsHelper.php).
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = $this->legacy_proxy->call_function( 'get_plugins' );
		$names   = array();
		foreach ( $plugin_files as $plugin_file ) {
			$name = is_array( $plugins ) && isset( $plugins[ $plugin_file ]['Name'] ) && is_scalar( $plugins[ $plugin_file ]['Name'] ) ? trim( (string) $plugins[ $plugin_file ]['Name'] ) : '';
			$name = '' !== $name ? $name : self::get_plugin_slug( $plugin_file );
			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Get a plugin's slug: its folder, or the file name for a plugin without one.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins folder.
	 * @return string
	 */
	private static function get_plugin_slug( string $plugin_file ): string {
		$parts = explode( '/', $plugin_file, 2 );
		return 2 === count( $parts ) ? $parts[0] : basename( $plugin_file, '.php' );
	}
}
