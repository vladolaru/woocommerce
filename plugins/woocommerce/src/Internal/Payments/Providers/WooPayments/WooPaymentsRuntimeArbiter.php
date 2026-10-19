<?php
/**
 * WooPaymentsRuntimeArbiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Decides per site whether the WooPayments extension, the built-in WooPayments or nothing owns payments.
 * The WooPayments feature (WooPaymentsFeatureController) decides whether the built-in WooPayments is enabled.
 *
 * The owner is, in this order:
 * - the extension, while it is active, whatever the feature says;
 * - the built-in WooPayments, while the feature is on and the store either never ran the extension or switched from it
 *   with the one-click switch (its cutover record is done, or the switch's finalize deactivated the extension);
 * - nothing otherwise. A store that ran the extension and deactivated it without switching has no WooPayments, as it
 *   would without the extension; with the feature on it is offered the switch (is_awaiting_switch()).
 *
 * At most one WooPayments runtime owns payments on a site; the WooPayments extension wins while it is active. The extension is
 * found by its main file name, in any folder, in the per-site and network active-plugins lists, with `WCPAY_PLUGIN_FILE` as
 * the fallback for a copy included before WooCommerce. The owner is resolved once per blog and request.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsRuntimeArbiter {

	/**
	 * Owner value: the WooPayments extension owns payments.
	 *
	 * @var string
	 */
	public const OWNER_EXTENSION = 'extension';

	/**
	 * Owner value: the built-in WooPayments owns payments.
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
	 * The WooPayments extension's main file, as it appears in the active-plugins option.
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
	 * The option the WooPayments extension writes its version to on every update.
	 *
	 * @var string
	 */
	private const EXTENSION_VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	/**
	 * The legacy proxy, used for mockable calls to global functions.
	 *
	 * The plugin lists, the multisite check and the WCPAY_PLUGIN_FILE constant are read through it so tests can stand in
	 * for them; the WooPayments feature option is a plain option, read with get_option().
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
			$this->runtime_owners[ $blog_id ] = $this->resolve_runtime_owner();
		}

		return $this->runtime_owners[ $blog_id ];
	}

	/**
	 * Work out the owner, in the order of the class docblock; the extension evidence and the cutover record are read only
	 * on a store with the feature on and the extension inactive.
	 *
	 * @return string One of self::OWNER_EXTENSION, self::OWNER_BUILTIN, self::OWNER_NONE.
	 */
	private function resolve_runtime_owner(): string {
		// The WooPayments extension wins while it is active, whatever the built-in WooPayments setting says.
		if ( $this->is_woopayments_plugin_active() ) {
			return self::OWNER_EXTENSION;
		}
		if ( ! $this->is_builtin_enabled() ) {
			return self::OWNER_NONE;
		}

		return ! $this->has_run_woopayments_extension() || $this->has_switched_from_extension() ? self::OWNER_BUILTIN : self::OWNER_NONE;
	}

	/**
	 * Tell whether this site switched from the extension: its cutover record is done, or the current generation holds the
	 * token the switch's finalize leaves on it once the extension is deactivated (it stays through deferred retries of the
	 * ownership check, and every new generation, a rollback included, starts without it).
	 *
	 * @return bool
	 */
	private function has_switched_from_extension(): bool {
		$record = get_option( WooPaymentsCutoverStateStore::OPTION_NAME, null );

		return is_array( $record )
			&& ( WooPaymentsCutoverStateStore::STATE_DONE === ( $record['state'] ?? null ) || null !== ( $record['request_origin_token'] ?? null ) );
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
	 * Tell whether the built-in WooPayments owns payments on this site.
	 *
	 * The setup tier registers built-in WooPayments classes only while it owns the store.
	 *
	 * @since 11.0.0
	 *
	 * @return bool True only when the built-in WooPayments owns this site.
	 */
	public function is_builtin_owner(): bool {
		return self::OWNER_BUILTIN === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the WooPayments extension owns payments on this site.
	 *
	 * Code that only runs while the extension owns payments checks it, such as the notice that offers the switch to the
	 * built-in WooPayments and the setup tier sync.
	 *
	 * @since 11.0.0
	 *
	 * @return bool True when the WooPayments extension owns this site's payments.
	 */
	public function is_extension_owner(): bool {
		return self::OWNER_EXTENSION === $this->get_runtime_owner();
	}

	/**
	 * Tell whether this site waits for its one-click switch: the feature is on, and the store ran the extension, deactivated
	 * it and has not switched, so nothing owns payments.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_awaiting_switch(): bool {
		return self::OWNER_NONE === $this->get_runtime_owner() && $this->is_builtin_enabled();
	}

	/**
	 * Tell whether the built-in WooPayments is enabled for this site: the WooPayments feature is on.
	 *
	 * This is the enabled setting, independent of ownership: the built-in WooPayments can be enabled
	 * while the WooPayments extension owns payments, and the extension still wins.
	 *
	 * @since 11.0.0
	 *
	 * @return bool True when the built-in WooPayments is enabled.
	 */
	public function is_builtin_enabled(): bool {
		return 'yes' === get_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, wc_bool_to_string( WooPaymentsFeatureController::ENABLED_BY_DEFAULT ) );
	}

	/**
	 * Tell whether the WooPayments extension ever ran on this site: its version option is stored.
	 *
	 * The extension writes the option autoloaded and nothing deletes it, so this is one option read with no plugin scan. Orders
	 * and tokens are not evidence: the built-in WooPayments writes the same gateway ids.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function has_run_woopayments_extension(): bool {
		return false !== get_option( self::EXTENSION_VERSION_OPTION, false );
	}

	/**
	 * Tell whether a plugin file is the WooPayments extension's: its main file name, in any plugin folder.
	 *
	 * WooPayments is the only wordpress.org plugin with this main file name. The check reads only the string, so callers
	 * on every request pay for no option read or plugin scan.
	 *
	 * @since 11.2.0
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return bool
	 */
	public static function is_woopayments_plugin_file( string $plugin_file ): bool {
		return self::PLUGIN_MAIN_FILE_NAME === wp_basename( $plugin_file );
	}

	/**
	 * Tell whether the WooPayments extension is active for the current site.
	 *
	 * The active-plugins lists (per-site and network) are the primary signal: they are reliable early
	 * in boot and per-site under multisite. The include-time WCPAY_PLUGIN_FILE constant is the fallback
	 * for an extension included before WooCommerce without a list entry.
	 *
	 * @return bool True when the WooPayments extension is active.
	 */
	private function is_woopayments_plugin_active(): bool {
		if ( $this->plugin_in_active_list() ) {
			return true;
		}

		return (bool) $this->legacy_proxy->call_function( 'defined', 'WCPAY_PLUGIN_FILE' );
	}

	/**
	 * Tell whether the WooPayments extension appears in the active-plugins lists.
	 *
	 * @return bool True when its main file is in the per-site or network active-plugins list.
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
	 * @param array<int|string,mixed> $plugins Plugin files relative to the plugins directory.
	 * @return bool
	 */
	private function has_plugin_entry( array $plugins ): bool {
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && self::is_woopayments_plugin_file( $plugin ) ) {
				return true;
			}
		}

		return false;
	}
}
