<?php
/**
 * PayPalWalletRuntimeArbiter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Decides whether the core-native PayPal wallet or the WooCommerce PayPal Payments extension owns a site.
 *
 * Rule: the extension wins whenever it is active. Native owns the site only when the extension is not
 * active and the native runtime is enabled. Every native registration must consult
 * {@see self::should_native_register()} before loading or registering anything, including the vendored
 * autoloader, so that both copies of the extension's code never load in one request.
 *
 * Detection reads the active-plugins lists only: the extension loads after WooCommerce alphabetically,
 * so a constant-based fallback would fire too late.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PayPalWalletRuntimeArbiter {

	public const OWNER_EXTENSION = 'extension';
	public const OWNER_NATIVE    = 'native';
	public const OWNER_NONE      = 'none';

	/**
	 * The extension's main file, as listed in the active-plugins option.
	 */
	public const EXTENSION_PLUGIN_FILE = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';

	/**
	 * Option: 'yes' enables the native wallet runtime (still subordinate to the extension).
	 */
	public const ENABLED_OPTION = 'woocommerce_native_paypal_wallet_enabled';

	/**
	 * Option: any truthy value disables the native wallet runtime regardless of ENABLED_OPTION.
	 */
	public const KILL_SWITCH_OPTION = 'woocommerce_native_paypal_wallet_killswitch';

	/**
	 * Filter with the final word on whether the native wallet runtime is enabled.
	 */
	public const FILTER_ENABLED = 'woocommerce_native_paypal_wallet_enabled';

	/**
	 * The legacy proxy, used for mockable calls to global functions.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Request-local owners keyed by blog ID.
	 *
	 * @var array<int, string>
	 */
	private array $owners = array();

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
	 * Get the wallet runtime owner for the current site.
	 *
	 * @return string One of the OWNER_* constants.
	 */
	public function get_runtime_owner(): string {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->owners ) ) {
			if ( $this->is_extension_active() ) {
				$this->owners[ $blog_id ] = self::OWNER_EXTENSION;
			} else {
				$this->owners[ $blog_id ] = $this->is_native_enabled() ? self::OWNER_NATIVE : self::OWNER_NONE;
			}
		}

		return $this->owners[ $blog_id ];
	}

	/**
	 * Forget the memoized owner for a blog.
	 *
	 * @param int|null $blog_id Blog ID, or null for the current blog.
	 */
	public function invalidate( ?int $blog_id = null ): void {
		unset( $this->owners[ $blog_id ?? get_current_blog_id() ] );
	}

	/**
	 * Tell whether core-native code may load and register the wallet for this site.
	 *
	 * @return bool
	 */
	public function should_native_register(): bool {
		return self::OWNER_NATIVE === $this->get_runtime_owner();
	}

	/**
	 * Tell whether the PayPal Payments extension is active for the current site.
	 *
	 * @return bool
	 */
	public function is_extension_active(): bool {
		$site_active = (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() );
		if ( in_array( self::EXTENSION_PLUGIN_FILE, $site_active, true ) ) {
			return true;
		}

		if ( ! $this->legacy_proxy->call_function( 'is_multisite' ) ) {
			return false;
		}

		$network_active = (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() );
		return isset( $network_active[ self::EXTENSION_PLUGIN_FILE ] );
	}

	/**
	 * Tell whether the native wallet runtime is enabled (independent of who owns the site).
	 *
	 * @return bool
	 */
	public function is_native_enabled(): bool {
		$option_enabled     = 'yes' === $this->legacy_proxy->call_function( 'get_option', self::ENABLED_OPTION, 'no' );
		$kill_switch_active = (bool) $this->legacy_proxy->call_function( 'get_option', self::KILL_SWITCH_OPTION, false );

		/**
		 * Filters whether the core-native PayPal wallet runtime is enabled for this site.
		 *
		 * @since 11.3.0
		 *
		 * @param bool $enabled Whether the native wallet runtime is enabled.
		 */
		return (bool) apply_filters( self::FILTER_ENABLED, $kill_switch_active ? false : $option_enabled );
	}
}
