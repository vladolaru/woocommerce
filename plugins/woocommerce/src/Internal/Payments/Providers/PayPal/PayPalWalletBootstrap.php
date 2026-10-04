<?php
/**
 * PayPalWalletBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WalletProperties;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\Module;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Package;

/**
 * Boots the forked PayPal wallet from core when the native wallet owns the site.
 *
 * Mirrors what the extension's main plugin file does on plugins_loaded (constants, module list, Modularity
 * package, PPCP::init, built-container action, version/migration hook), gated by the arbiter. The wallet code
 * is core's own copy under Wallet/, loaded by core's autoloader; the module list in Wallet/modules.php holds only
 * the wallet modules. When the extension owns the site nothing is booted, so the two copies never run together.
 *
 * When core owns the wallet, the wallet stays dormant until a merchant connects: it is not built at all, except on its
 * own admin and REST surfaces. While dormant, a placeholder gateway keeps the Payments settings row.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PayPalWalletBootstrap implements RegisterHooksInterface {

	/**
	 * Constants the extension's main file defines, copied so core defines them without that file. Both copies
	 * guard them with defined(), so whichever loads first wins and the values are identical.
	 */
	private const EXTENSION_CONSTANTS = array(
		'PAYPAL_API_URL'                  => 'https://api-m.paypal.com',
		'PAYPAL_URL'                      => 'https://www.paypal.com',
		'PAYPAL_SANDBOX_API_URL'          => 'https://api-m.sandbox.paypal.com',
		'PAYPAL_SANDBOX_URL'              => 'https://www.sandbox.paypal.com',
		'PAYPAL_INTEGRATION_DATE'         => '2026-09-02',
		'PPCP_PAYPAL_BN_CODE'             => 'Woo_PPCP',
		'CONNECT_WOO_CLIENT_ID'           => 'AcCAsWta_JTL__OfpjspNyH7c1GGHH332fLwonA5CwX4Y10mhybRZmHLA0GdRbwKwjQIhpDQy0pluX_P',
		'CONNECT_WOO_SANDBOX_CLIENT_ID'   => 'AYmOHbt1VHg-OZ_oihPdzKEVbU3qg0qXonBcAztuzniQRaKE0w1Hr762cSFwd4n8wxOl-TCWohEa0XM_',
		'CONNECT_WOO_MERCHANT_ID'         => 'K8SKZ36LQBWXJ',
		'CONNECT_WOO_SANDBOX_MERCHANT_ID' => 'MPMFHQTVMBZ6G',
		'CONNECT_WOO_URL'                 => 'https://api.woocommerce.com/integrations/ppc',
		'CONNECT_WOO_SANDBOX_URL'         => 'https://api.woocommerce.com/integrations/ppcsandbox',
	);

	/**
	 * The Payments settings route that serves the wallet's settings app.
	 *
	 * @since 11.3.0
	 */
	public const SETTINGS_ROUTE_PATH = '/paypal-wallet';

	/**
	 * The runtime arbiter.
	 *
	 * @var PayPalWalletRuntimeArbiter
	 */
	private PayPalWalletRuntimeArbiter $arbiter;

	/**
	 * Whether the wallet container has been booted in this request.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param PayPalWalletRuntimeArbiter $arbiter The runtime arbiter.
	 */
	final public function init( PayPalWalletRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'plugins_loaded', array( $this, 'maybe_boot' ), 10 );
	}

	/**
	 * The URL of the wallet's settings, a route of the Payments settings app.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public static function get_settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&path=' . self::SETTINGS_ROUTE_PATH );
	}

	/**
	 * Whether the wallet container was booted in this request.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Boot the wallet if native owns the site. Safe to call more than once.
	 */
	public function maybe_boot(): void {
		if ( $this->booted ) {
			return;
		}
		// Runs before the loaded-elsewhere return: on the extension's deactivation request its main file is already loaded, so that guard would return first.
		// The extension owns this request; if native is enabled it owns the next one, so keep the PayPal webhooks across the hand-back.
		if ( PayPalWalletRuntimeArbiter::OWNER_EXTENSION === $this->arbiter->get_runtime_owner() && $this->arbiter->is_native_enabled() ) {
			add_filter( 'woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate', '__return_true' );
		}
		// The extension's own copy is already running (for example from a renamed folder); never boot a second container.
		if ( $this->is_extension_loaded_elsewhere() ) {
			return;
		}
		// The extension skips its own bootstrap during manual plugin updates; do the same.
		if ( 'update.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		// WordPress includes the extension's main file next on this request, and it defines the same constants.
		if ( $this->is_extension_activation_request() ) {
			return;
		}
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}
		if ( $this->is_dormant() ) {
			// Only the admin screens and the wc-admin REST routes that build the Payments settings list show the placeholder row.
			if ( is_admin() || $this->is_wc_admin_rest_request() ) {
				add_filter( 'woocommerce_payment_gateways', array( $this, 'register_dormant_gateway' ) );
			}
			return;
		}

		$this->define_constants();
		// The DTOs the wallet stores as PHP objects keep the extension's class names; see the loader for why.
		require_once __DIR__ . '/Wallet/SerializedClasses/load.php';

		$modules = ( require __DIR__ . '/Wallet/modules.php' )();
		/** This filter is documented in the extension's bootstrap.php. */
		$modules = apply_filters( 'woocommerce_paypal_payments_modules', $modules ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
		// Any callback can return anything; Package::addModule() throws a TypeError on a non-module, so keep only real modules.
		$modules = is_array( $modules ) ? array_values(
			array_filter(
				$modules,
				static function ( $module ): bool {
					return $module instanceof Module;
				}
			)
		) : array();

		$package = Package::new( WalletProperties::new() );
		foreach ( $modules as $module ) {
			$package->addModule( $module );
		}
		$package->boot();
		$container = $package->container();

		PPCP::init( $container );
		$this->booted = true;

		/** This action is documented in the extension's woocommerce-paypal-payments.php. */
		do_action( 'woocommerce_paypal_payments_built_container', $container ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment

		add_action( 'init', array( $this, 'maybe_run_migrations' ), -1 );
	}

	/**
	 * Whether the wallet has nothing to do in this request: no merchant is connected and this is not a wallet admin request.
	 *
	 * A connected store boots on every request (its gateway, webhooks and REST must work). A store with no connected
	 * merchant can render nothing on the storefront and has no settings to serve outside its own admin surfaces, so the
	 * modules are not built at all: no hooks, no script handles, no container.
	 *
	 * A merchant connected through the legacy (pre 4.0) settings counts as connected, so that store boots on its first
	 * request, which runs the migration into the shared settings option. The install and update migrations run on
	 * `init` only after a boot.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_dormant(): bool {
		if ( $this->is_merchant_connected() ) {
			return false;
		}

		return ! $this->is_wallet_admin_request();
	}

	/**
	 * Add the placeholder gateway to the gateways list, unless a gateway with the wallet's ID is already there.
	 *
	 * Hooked to `woocommerce_payment_gateways` while the wallet is dormant, so the Payments settings list shows a
	 * "PayPal Wallet" row to finish setting up.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $gateways The registered gateways: class names and gateway objects.
	 *
	 * @return array
	 */
	public function register_dormant_gateway( $gateways ) {
		$gateways = is_array( $gateways ) ? $gateways : array();
		foreach ( $gateways as $gateway ) {
			if ( DormantPayPalGateway::class === $gateway || ( is_object( $gateway ) && 'ppcp-gateway' === ( $gateway->id ?? '' ) ) ) {
				return $gateways;
			}
		}
		$gateways[] = DormantPayPalGateway::class;

		return $gateways;
	}

	/**
	 * Whether a merchant is connected, in the shared settings option or, for a merchant not migrated yet, the legacy one.
	 * Reads the options without building the wallet.
	 *
	 * @return bool
	 */
	private function is_merchant_connected(): bool {
		return GeneralSettings::read_connection_from_options()['connected'];
	}

	/**
	 * Whether this request addresses the wallet's own admin surfaces: its settings page (the Payments settings route or
	 * the legacy section) or its REST namespaces.
	 *
	 * @return bool
	 */
	private function is_wallet_admin_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
		if ( is_admin() && isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] ) {
			$path    = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
			$section = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
			if ( 0 === strpos( $path, self::SETTINGS_ROUTE_PATH ) || 'ppcp-gateway' === $section ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$rest_route = $this->get_rest_route();

		return 0 === strpos( $rest_route, '/wc/v3/wc_paypal' ) || 0 === strpos( $rest_route, '/paypal/v1' );
	}

	/**
	 * Whether this request is a wc-admin REST request, such as the one that loads the Payments settings list.
	 *
	 * @return bool
	 */
	private function is_wc_admin_rest_request(): bool {
		$rest_route = $this->get_rest_route();

		return 0 === strpos( $rest_route, '/wc-admin/' ) || 0 === strpos( $rest_route, '/wc-analytics/' );
	}

	/**
	 * The REST route this request addresses, or an empty string when it is not a REST request.
	 *
	 * This runs on plugins_loaded, before WordPress parses the request, so the route is read from the plain-permalink
	 * query argument first, then from the request path, where the route is whatever follows the REST prefix segment.
	 *
	 * @return string
	 */
	private function get_rest_route(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		// Find the REST prefix segment anywhere in the path, as WooCommerce's is_rest_api_request() does: index permalinks
		// (/index.php/wp-json/...) and subdirectory installs (/sub/index.php/wp-json/...) put it after other segments.
		$path        = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$rest_prefix = '/' . rest_get_url_prefix() . '/';
		$position    = strpos( $path, $rest_prefix );

		return false === $position ? '' : '/' . substr( $path, $position + strlen( $rest_prefix ) );
	}

	/**
	 * Whether the extension's main file has already been included, from a folder core does not boot from.
	 *
	 * Core never includes the extension's main file, so its init function existing means another copy of the
	 * extension is loaded, for example from the plugins folder or an mu-plugin.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_extension_loaded_elsewhere(): bool {
		return function_exists( $this->get_extension_init_function() );
	}

	/**
	 * Name of the function the extension's main file declares once it has been included.
	 *
	 * @return string
	 */
	protected function get_extension_init_function(): string {
		return 'WooCommerce\\PayPalCommerce\\init';
	}

	/**
	 * Whether this request activates the PayPal Payments extension from the plugins screen.
	 *
	 * Covers single and bulk activation. Only reads the request to decide whether to skip a boot.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_extension_activation_request(): bool {
		if ( 'plugins.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check that only skips a boot; no action is performed.
		// The bulk form posts, the single link is a GET; $_REQUEST covers both, as core's current_action() does.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 'activate' === $action ) {
			$plugin = isset( $_REQUEST['plugin'] ) && is_string( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : '';
			return PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE === $plugin;
		}
		if ( 'activate-selected' === $action ) {
			$checked = isset( $_REQUEST['checked'] ) ? wp_unslash( $_REQUEST['checked'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			if ( ! is_array( $checked ) ) {
				return false;
			}
			$checked = array_map( 'sanitize_text_field', array_filter( $checked, 'is_string' ) );
			return in_array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE, $checked, true );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return false;
	}

	/**
	 * Fire the extension's install/update migration actions when its stored version differs, as its main file does.
	 */
	public function maybe_run_migrations(): void {
		if ( ! $this->booted ) {
			return;
		}
		$current_version   = PPCP::container()->get( 'ppcp.plugin-version' );
		$installed_version = get_option( 'woocommerce-ppcp-version' );
		if ( $installed_version === $current_version ) {
			return;
		}
		update_option( 'woocommerce-ppcp-version', $current_version );

		/** This action is documented in the extension's woocommerce-paypal-payments.php. */
		do_action( 'woocommerce_paypal_payments_gateway_migrate', $installed_version ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
		if ( $installed_version ) {
			/** This action is documented in the extension's woocommerce-paypal-payments.php. */
			do_action( 'woocommerce_paypal_payments_gateway_migrate_on_update' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
		}
	}

	/**
	 * The constants core defines on the extension's behalf, by name.
	 *
	 * @since 11.3.0
	 * @internal Exposed for the boot test.
	 *
	 * @return array<string, string>
	 */
	public static function get_extension_constants(): array {
		return self::EXTENSION_CONSTANTS;
	}

	/**
	 * Define the constants the extension's main file defines, when not already defined.
	 */
	private function define_constants(): void {
		foreach ( self::EXTENSION_CONSTANTS as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
	}
}
