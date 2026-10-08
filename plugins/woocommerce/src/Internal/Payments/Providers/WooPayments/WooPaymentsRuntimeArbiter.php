<?php
/**
 * WooPaymentsRuntimeArbiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Decides per site whether the WooPayments extension, the built-in WooPayments or nothing owns payments.
 *
 * Exactly one WooPayments runtime registers on a site; the WooPayments extension wins while it is active. The extension is
 * found by its main file name, in any folder, in the per-site and network active-plugins lists, with `WCPAY_PLUGIN_FILE` as
 * the fallback for a copy included before WooCommerce. The owner is resolved once per blog and request.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsRuntimeArbiter {

	/**
	 * Owner value: the standalone WooPayments plugin owns the runtime.
	 *
	 * @var string
	 */
	public const OWNER_EXTENSION = 'extension';

	/**
	 * Owner value: the core-native payments runtime owns the runtime.
	 *
	 * @var string
	 */
	public const OWNER_BUILTIN = 'builtin';

	/**
	 * Owner value: no payments runtime is active for this site.
	 *
	 * @var string
	 */
	public const OWNER_NONE = 'none';

	/**
	 * The WooPayments plugin's main file, as it appears in the active-plugins option.
	 *
	 * @var string
	 */
	public const PLUGIN_FILE = 'woocommerce-payments/woocommerce-payments.php';

	/**
	 * The WooPayments main file name, matched in any plugin folder.
	 *
	 * @var string
	 */
	private const PLUGIN_MAIN_FILE_NAME = 'woocommerce-payments.php';

	/**
	 * Option that enables the built-in WooPayments for this site ('yes' or 'no').
	 *
	 * @var string
	 */
	public const BUILTIN_ENABLED_OPTION = 'woocommerce_woopayments_builtin_enabled';

	/**
	 * Filter that reports whether the built-in WooPayments is enabled for this site.
	 *
	 * The stored option supplies the default. Even when enabled, the plugin still wins while it is
	 * active.
	 *
	 * Early native registrations resolve this filter while WooCommerce is being loaded. To affect all
	 * native registrations in a request, set the filter from a mu-plugin or earlier bootstrap code.
	 * Filters added from ordinary plugins may run too late for services registered during WooCommerce
	 * inclusion.
	 *
	 * @var string
	 */
	public const BUILTIN_ENABLED_FILTER = 'woocommerce_woopayments_builtin_enabled';

	/**
	 * Option that disables native payments in the rollout filter default.
	 *
	 * The rollout filter retains final authority and may explicitly override this option.
	 *
	 * @var string
	 */
	public const BUILTIN_KILL_SWITCH_OPTION = 'woocommerce_woopayments_builtin_kill_switch';

	/**
	 * The legacy proxy, used for mockable calls to global functions.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Request-local runtime owners keyed by blog ID.
	 *
	 * @var array<int,string>
	 */
	private array $runtime_owners = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy $legacy_proxy The legacy proxy.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Get the payments runtime owner for the current site.
	 *
	 * @since 11.0.0
	 *
	 * @return string One of self::OWNER_EXTENSION, self::OWNER_BUILTIN, self::OWNER_NONE.
	 */
	public function get_runtime_owner(): string {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->runtime_owners ) ) {
			// Plugin-wins is the only allowed state while the plugin is active; native is dormant.
			$this->runtime_owners[ $blog_id ] = $this->is_woopayments_plugin_active() ? self::OWNER_EXTENSION : ( $this->is_builtin_enabled() ? self::OWNER_BUILTIN : self::OWNER_NONE );
		}

		return $this->runtime_owners[ $blog_id ];
	}

	/**
	 * Invalidate one blog's memoized runtime owner.
	 *
	 * @since 11.2.0
	 *
	 * @param int|null $blog_id Blog ID, or null for the current blog.
	 */
	public function invalidate( ?int $blog_id = null ): void {
		unset( $this->runtime_owners[ $blog_id ?? get_current_blog_id() ] );
	}

	/**
	 * Tell whether core-native code may perform mutating registration for this site.
	 *
	 * This is the guard every native registration must consult before acting.
	 *
	 * @since 11.0.0
	 *
	 * @return bool True only when the native runtime owns this site.
	 */
	public function is_builtin_owner(): bool {
		return self::OWNER_BUILTIN === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the WooPayments plugin owns the runtime for this site.
	 *
	 * The migration-notice / auto-deactivation component uses this to know when to surface the
	 * "WooPayments is now in core — deactivate the extension" notice and the cutover action.
	 *
	 * @since 11.0.0
	 *
	 * @return bool True when the plugin owns this site's payments runtime.
	 */
	public function is_extension_owner(): bool {
		return self::OWNER_EXTENSION === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the core-native payments runtime is enabled for this site.
	 *
	 * This is the feature-flag state, independent of ownership: native can be enabled while the
	 * plugin still owns the runtime (in which case the plugin still wins).
	 *
	 * @since 11.0.0
	 *
	 * @return bool True when the native runtime is enabled.
	 */
	public function is_builtin_enabled(): bool {
		$option_enabled = 'yes' === get_option( self::BUILTIN_ENABLED_OPTION, 'no' );
		$filter_default = $this->is_kill_switch_active() ? false : $option_enabled;

		/**
		 * Filters whether the core-native payments runtime is enabled for this site.
		 *
		 * This value is resolved during WooCommerce loading for early native registrations. Use a
		 * mu-plugin or earlier bootstrap when the filter must control the whole native payments
		 * registration cluster for the request.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether the native runtime is enabled.
		 */
		return (bool) apply_filters( self::BUILTIN_ENABLED_FILTER, $filter_default );
	}

	/**
	 * Tell whether the stored kill switch is on.
	 *
	 * Scalar values follow wc_string_to_bool(); any other stored value (such as an array) is cast to bool.
	 *
	 * @since 11.2.0
	 *
	 * @return bool True when the kill switch option is on.
	 */
	public function is_kill_switch_active(): bool {
		$stored = get_option( self::BUILTIN_KILL_SWITCH_OPTION, false );

		if ( ! is_scalar( $stored ) && null !== $stored ) {
			return (bool) $stored;
		}

		return wc_string_to_bool( is_bool( $stored ) ? $stored : (string) $stored );
	}

	/**
	 * Tell whether the WooPayments plugin is active for the current site.
	 *
	 * Primary signal is the active-plugins list (per-site + network), which is reliable in the
	 * early-boot window and correct per-site under multisite. The include-time WCPAY_PLUGIN_FILE
	 * constant is the fallback for a plugin included before WooCommerce without a list entry.
	 *
	 * @return bool True when the WooPayments plugin is active.
	 */
	private function is_woopayments_plugin_active(): bool {
		if ( $this->plugin_in_active_list() ) {
			return true;
		}

		return (bool) $this->legacy_proxy->call_function( 'defined', 'WCPAY_PLUGIN_FILE' );
	}

	/**
	 * Tell whether the WooPayments plugin appears in the active-plugins lists.
	 *
	 * @return bool True when the plugin is in the per-site or network active-plugins list.
	 */
	private function plugin_in_active_list(): bool {
		$site_active = (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() );
		if ( $this->has_plugin_entry( $site_active ) ) {
			return true;
		}

		// A single site has no network plugin list; reading it would query a missing option on every request.
		if ( ! $this->legacy_proxy->call_function( 'is_multisite' ) ) {
			return false;
		}

		$network_active = (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() );
		return $this->has_plugin_entry( array_keys( $network_active ) );
	}

	/**
	 * Tell whether an active-plugins list holds the WooPayments main file, in any folder.
	 *
	 * WooPayments is the only wordpress.org plugin with this main file name.
	 *
	 * @param array<int|string,mixed> $plugins Plugin files relative to the plugins directory.
	 * @return bool
	 */
	private function has_plugin_entry( array $plugins ): bool {
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && self::PLUGIN_MAIN_FILE_NAME === wp_basename( $plugin ) ) {
				return true;
			}
		}

		return false;
	}
}
