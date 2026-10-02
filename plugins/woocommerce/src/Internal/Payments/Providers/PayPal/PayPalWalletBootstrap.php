<?php
/**
 * PayPalWalletBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Boots the vendored PayPal Payments extension from core when the native wallet owns the site.
 *
 * Mirrors what the extension's main plugin file does on plugins_loaded (constants, autoloader,
 * bootstrap, PPCP::init, built-container action, version/migration hook), gated by the arbiter and
 * trimmed through the extension's own feature flags and module filter: card fields and store sync are
 * forced off and the fraud protection, abilities, status report and uninstall modules are dropped.
 * Apple Pay, Google Pay, Fastlane, local APM and order tracking modules stay loaded because kept
 * modules read their services; WalletStubsModule makes them take their "not eligible" path. When the
 * extension owns the site nothing is required, so the two copies never load together.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PayPalWalletBootstrap implements RegisterHooksInterface {

	/**
	 * Absolute path of the vendored extension (no trailing slash).
	 */
	public const VENDORED_DIR = __DIR__ . '/woocommerce-paypal-payments';

	/**
	 * Feature flags (suffixes of woocommerce.feature-flags.woocommerce_paypal_payments.*) forced off.
	 * Apple Pay, Google Pay and Fastlane flags are left alone; their modules load and turn themselves off through the stubbed eligibility checks.
	 */
	public const DISABLED_FEATURE_FLAGS = array(
		'card_fields_enabled',
		'store_sync_enabled',
	);

	/**
	 * Module classes removed from the extension's module list. No kept module reads their services.
	 */
	public const DROPPED_MODULE_CLASSES = array(
		'WooCommerce\\PayPalCommerce\\FraudProtection\\FraudProtectionModule',
		'WooCommerce\\PayPalCommerce\\Abilities\\AbilitiesModule',
		'WooCommerce\\PayPalCommerce\\StatusReport\\StatusReportModule',
		'WooCommerce\\PayPalCommerce\\Uninstall\\UninstallModule',
	);

	/**
	 * The runtime arbiter.
	 *
	 * @var PayPalWalletRuntimeArbiter
	 */
	private PayPalWalletRuntimeArbiter $arbiter;

	/**
	 * Whether the vendored container has been booted in this request.
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
	 * Whether the vendored container was booted in this request.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Boot the vendored extension if native owns the site. Safe to call more than once.
	 */
	public function maybe_boot(): void {
		if ( $this->booted ) {
			return;
		}
		// The extension skips its own bootstrap during manual plugin updates; do the same.
		if ( 'update.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}
		$autoload = self::VENDORED_DIR . '/vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			return;
		}

		$this->define_constants();
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PluginModule' ) ) {
			require $autoload;
		}
		$this->add_trimming_filters();

		$bootstrap = require self::VENDORED_DIR . '/bootstrap.php';
		$container = $bootstrap( self::VENDORED_DIR, array(), array( new WalletStubsModule() ) );
		\WooCommerce\PayPalCommerce\PPCP::init( $container );
		$this->booted = true;

		/** This action is documented in the vendored woocommerce-paypal-payments.php. */
		do_action( 'woocommerce_paypal_payments_built_container', $container ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment

		add_action( 'init', array( $this, 'maybe_run_migrations' ), -1 );
	}

	/**
	 * Fire the extension's install/update migration actions when its stored version differs, as its main file does.
	 */
	public function maybe_run_migrations(): void {
		if ( ! $this->booted ) {
			return;
		}
		$current_version   = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'ppcp.plugin-version' );
		$installed_version = get_option( 'woocommerce-ppcp-version' );
		if ( $installed_version === $current_version ) {
			return;
		}
		update_option( 'woocommerce-ppcp-version', $current_version );

		/** This action is documented in the vendored woocommerce-paypal-payments.php. */
		do_action( 'woocommerce_paypal_payments_gateway_migrate', $installed_version ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
		if ( $installed_version ) {
			/** This action is documented in the vendored woocommerce-paypal-payments.php. */
			do_action( 'woocommerce_paypal_payments_gateway_migrate_on_update' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment
		}
	}

	/**
	 * Remove the given module classes from a Modularity module list.
	 *
	 * @param array    $modules         Module instances.
	 * @param string[] $dropped_classes Fully qualified class names to drop.
	 * @return array
	 */
	public function filter_modules( $modules, array $dropped_classes = self::DROPPED_MODULE_CLASSES ): array {
		$modules = is_array( $modules ) ? $modules : array();
		return array_values(
			array_filter(
				$modules,
				static function ( $module ) use ( $dropped_classes ): bool {
					return ! is_object( $module ) || ! in_array( get_class( $module ), $dropped_classes, true );
				}
			)
		);
	}

	/**
	 * Define the constants the extension's main file defines, when not already defined.
	 */
	private function define_constants(): void {
		$constants = array(
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
		foreach ( $constants as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
	}

	/**
	 * Add the filters that trim the extension to the wallet.
	 */
	private function add_trimming_filters(): void {
		foreach ( self::DISABLED_FEATURE_FLAGS as $flag ) {
			add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.' . $flag, '__return_false' );
		}
		add_filter( 'woocommerce_paypal_payments_modules', array( $this, 'filter_modules' ) );
		add_filter( 'woocommerce_paypal_payments_gateway_group_cards', '__return_empty_array' );
		add_filter( 'woocommerce_paypal_payments_gateway_group_apm', '__return_empty_array' );
	}
}
