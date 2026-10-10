<?php
/**
 * PayPalWalletBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli\CollectCommand;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\CollectingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PerAppBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WalletProperties;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\Module;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Package;
use WP_CLI;

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
	 * The option that holds the wallet's owner on the previous request, so a hand-back from the extension is noticed.
	 * An option, not a transient: an evicted transient would skip the hand-back reconcile. Autoloaded, as every request
	 * reads it.
	 *
	 * @since 11.3.0
	 */
	public const LAST_OWNER_OPTION = 'wc_paypal_wallet_last_owner';

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
	 * The surfaces that work whoever owns the wallet, once built.
	 *
	 * @var OwnerIndependent|null
	 */
	private ?OwnerIndependent $surfaces = null;

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
		// The collecting state is core's, not the wallet container's: these listeners run whoever owns the wallet and whether or not it booted.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		// update_option() on a gateway row that does not exist yet falls through to add_option(), which fires the add hook only.
		add_action( 'update_option_woocommerce_ppcp-gateway_settings', array( $this, 'on_gateway_settings_updated' ), 10, 2 );
		add_action( 'add_option_woocommerce_ppcp-gateway_settings', array( $this, 'on_gateway_settings_added' ), 10, 2 );
		// A dormant store never boots the collecting module, so the shell owns the command.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wc paypal-wallet collect', array( new CollectCommand( $this->collecting_state(), array( RuntimeServices::class, 'transport' ) ), 'collect' ) ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
		}
	}

	/**
	 * Leave the collecting state when the PayPal Payments extension is activated: the extension takes over.
	 *
	 * Hooked to `activated_plugin`. The state is kept while orders are still held for the payee; the platform apps' cached
	 * tokens are deleted either way, since the store leaves the platform. Connecting the extension to a PayPal account
	 * later is a first-party connection, which leaves the kept state too (see OwnerIndependent).
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $plugin The activated plugin's basename.
	 */
	public function on_plugin_activated( $plugin ): void {
		if ( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE !== $plugin ) {
			return;
		}
		// The store leaves the platform: the extension never uses the platform apps' tokens.
		if ( ( new ConnectionState() )->has_platform_state() ) {
			PerAppBearer::forget_stored_tokens();
		}
		if ( ! $this->has_collecting_option() ) {
			return;
		}

		$this->collecting_state()->abandon( CollectingState::ABANDON_TAKEOVER );
	}

	/**
	 * Record who owns the wallet, and queue one reconcile when the extension hands it back to native.
	 *
	 * While the extension owned the wallet, its webhook endpoint rejected the platform apps' events, so held orders and
	 * onboarding can be out of date. The reconcile calls PayPal for every held order, so it is queued as an async action
	 * rather than run in this request. Only a store the platform serves has anything to reconcile, so the option is read
	 * and written only on a store with a platform state; {@see CollectingState::abandon()} deletes it with the state.
	 * Writes the option only when the owner changed.
	 *
	 * @since 11.3.0
	 *
	 * @return bool Whether this request is the first after a hand-back, and a reconcile was queued.
	 */
	public function track_runtime_owner(): bool {
		if ( ! ( new ConnectionState() )->has_platform_state() ) {
			return false;
		}
		$owner = $this->arbiter->get_runtime_owner();
		$last  = get_option( self::LAST_OWNER_OPTION, false );
		if ( $last === $owner ) {
			return false;
		}
		update_option( self::LAST_OWNER_OPTION, $owner, true );

		if ( PayPalWalletRuntimeArbiter::OWNER_EXTENSION !== $last || PayPalWalletRuntimeArbiter::OWNER_NATIVE !== $owner ) {
			return false;
		}

		// Action Scheduler takes actions from init on.
		if ( did_action( 'init' ) ) {
			$this->queue_hand_back_reconcile();
		} else {
			add_action( 'init', array( $this, 'queue_hand_back_reconcile' ), 20 );
		}

		return true;
	}

	/**
	 * Queue one hand-back reconcile, unless one is already queued.
	 *
	 * The run carries its own arguments, so the daily recurring action (no arguments) does not count as a queued one:
	 * Action Scheduler's unique check compares hook, group and arguments (K7).
	 *
	 * @internal
	 * @since 11.3.0
	 */
	public function queue_hand_back_reconcile(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( Reconciler::HOOK, Reconciler::HAND_BACK_ARGS, Reconciler::GROUP, true );
		}
	}

	/**
	 * Leave the collecting state when the merchant turns the PayPal gateway off.
	 *
	 * Hooked to `update_option_woocommerce_ppcp-gateway_settings`. Acts only on the move from enabled to disabled; a
	 * missing `enabled` key counts as enabled, as in {@see self::is_dormant()}. The state is kept while orders are still
	 * held for the payee.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $old_value The settings before the update.
	 * @param mixed $value     The settings after the update.
	 */
	public function on_gateway_settings_updated( $old_value, $value ): void {
		if ( ! is_array( $old_value ) || ! is_array( $value ) ) {
			return;
		}
		if ( 'no' === ( $old_value['enabled'] ?? null ) || 'no' !== ( $value['enabled'] ?? null ) ) {
			return;
		}
		if ( ! $this->has_collecting_option() ) {
			return;
		}

		$this->collecting_state()->abandon( CollectingState::ABANDON_DISABLED );
	}

	/**
	 * Leave the collecting state when the gateway's settings row is created with the gateway turned off.
	 *
	 * Hooked to `add_option_woocommerce_ppcp-gateway_settings`. A row that did not exist counts as enabled, so creating
	 * it with `enabled` set to `no` is a move from enabled to disabled, handled as {@see self::on_gateway_settings_updated()}.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $option The option name.
	 * @param mixed $value  The settings that were added.
	 */
	public function on_gateway_settings_added( $option, $value ): void {
		unset( $option );
		$this->on_gateway_settings_updated( array(), $value );
	}

	/**
	 * Append the collecting module after the wallet's own modules, so its extensions wrap theirs.
	 *
	 * Hooked to `woocommerce_paypal_payments_modules` at priority 10, only for a store the platform serves, and
	 * removed again once core has built its own list. The PayPal Payments extension applies the same filter to its
	 * own modules, so a list that holds none of the fork's modules is left alone (K6).
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $modules The module list.
	 *
	 * @return mixed The list with the collecting module last, or the value unchanged when it is not core's module list.
	 */
	public function append_collecting_module( $modules ) {
		if ( ! is_array( $modules ) || ! $this->holds_fork_modules( $modules ) ) {
			return $modules;
		}
		$modules[] = new CollectingModule();

		return $modules;
	}

	/**
	 * Whether a module list holds at least one module of the fork's (prefixed) Modularity.
	 *
	 * @param array $modules The module list.
	 *
	 * @return bool
	 */
	private function holds_fork_modules( array $modules ): bool {
		foreach ( $modules as $module ) {
			if ( $module instanceof Module ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The collecting state, built with no container, over the held-orders query.
	 *
	 * The container is not booted when the listeners run, so the state is built directly. A test overrides this to
	 * substitute a held-orders count.
	 *
	 * @return CollectingState
	 */
	protected function collecting_state(): CollectingState {
		return new CollectingState( new Options(), new HeldOrders() );
	}

	/**
	 * The surfaces that concern held orders and work whoever owns the wallet, built once.
	 *
	 * Registering them twice would attach their hooks twice, and maybe_boot() can run more than once.
	 *
	 * @return OwnerIndependent
	 */
	protected function owner_independent_surfaces(): OwnerIndependent {
		if ( null === $this->surfaces ) {
			$this->surfaces = new OwnerIndependent(
				null,
				null,
				function (): bool {
					return $this->arbiter->is_native_enabled();
				}
			);
		}

		return $this->surfaces;
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
		// The held-order surfaces (Home task, Inbox note, emails) work whoever owns the wallet, so they register before every ownership return below.
		$this->owner_independent_surfaces()->register();
		$this->track_runtime_owner();
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
		// The admin client registers the wallet's Payments settings route only when core owns the wallet.
		if ( is_admin() ) {
			add_filter( 'woocommerce_admin_shared_settings', array( $this, 'share_ownership_with_admin_app' ) );
		}
		if ( $this->is_dormant() ) {
			// Only the admin screens and the wc-admin REST routes that build the Payments settings list show the placeholder row.
			if ( is_admin() || $this->is_wc_admin_rest_request() ) {
				add_filter( 'woocommerce_payment_gateways', array( $this, 'register_dormant_gateway' ) );
			}
			return;
		}

		if ( ( new ConnectionState() )->is_served_by_platform() ) {
			add_filter( 'woocommerce_paypal_payments_modules', array( $this, 'append_collecting_module' ), 10 );
		}

		$this->define_constants();
		// The DTOs the wallet stores as PHP objects keep the extension's class names; see the loader for why.
		require_once __DIR__ . '/Wallet/SerializedClasses/load.php';

		$modules = ( require __DIR__ . '/Wallet/modules.php' )();
		/** This filter is documented in the extension's bootstrap.php. */
		$modules = apply_filters( 'woocommerce_paypal_payments_modules', $modules ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
		// Only core's own list may receive the collecting module: the extension applies this filter too when it is activated in this request.
		remove_filter( 'woocommerce_paypal_payments_modules', array( $this, 'append_collecting_module' ), 10 );
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
	 * A store the platform serves (a platform merchant ID or a collecting payee) boots like a connected one, as long as
	 * the wallet gateway is not turned off.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_dormant(): bool {
		if ( $this->is_merchant_connected() ) {
			return false;
		}
		// A store the platform serves (connected or collecting) boots like a connected one, unless its gateway is turned off.
		// First-party credentials are ruled out above, so the platform options alone decide; they are not read twice.
		if ( ( new ConnectionState() )->has_platform_state() && $this->is_gateway_enabled() ) {
			return false;
		}

		return ! $this->is_wallet_admin_request();
	}

	/**
	 * Tell the admin client that core owns the wallet, as `wcSettings.admin.paypalWalletOwned`.
	 *
	 * Hooked to `woocommerce_admin_shared_settings` only when core owns the wallet.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $settings The shared admin settings.
	 *
	 * @return mixed The settings with the flag added, or the value unchanged when it is not an array.
	 */
	public function share_ownership_with_admin_app( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$settings['paypalWalletOwned'] = true;

		return $settings;
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
	 * Whether the wallet gateway is not turned off: its `enabled` setting is anything but `no`.
	 *
	 * A missing option or key counts as enabled, so a store that never saved the gateway's settings still boots. The
	 * gateway's own form default is `no`, but WooCommerce only stores `no` once a merchant saves or toggles the gateway.
	 * Reads only.
	 *
	 * @return bool
	 */
	private function is_gateway_enabled(): bool {
		$settings = get_option( 'woocommerce_ppcp-gateway_settings' );

		return ! is_array( $settings ) || 'no' !== ( $settings['enabled'] ?? null );
	}

	/**
	 * Whether the collecting option is present.
	 *
	 * @return bool
	 */
	private function has_collecting_option(): bool {
		return array() !== ( new Options() )->collecting();
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
