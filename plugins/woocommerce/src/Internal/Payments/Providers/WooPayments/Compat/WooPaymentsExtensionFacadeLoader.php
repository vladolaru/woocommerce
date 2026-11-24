<?php
/**
 * WooPaymentsExtensionFacadeLoader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * While the built-in WooPayments owns payments with an account connected (the connected and active setup tiers), defines
 * `WCPAY_VERSION_NUMBER` and declares the extension's `WC_Payments`, `WC_Payments_Features`, `WC_Payments_Utils` and `WCPay\Core\Mode`
 * classes on first use, except in a request that is activating the extension. Below that tier these symbols are absent, as with the
 * feature off.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsExtensionFacadeLoader implements RegisterHooksInterface {

	/**
	 * Facade class names and the files that declare them.
	 */
	private const FACADE_FILES = array(
		'WC_Payments'          => 'class-wc-payments.php',
		'WC_Payments_Features' => 'class-wc-payments-features.php',
		'WC_Payments_Utils'    => 'class-wc-payments-utils.php',
		'WCPay\\Core\\Mode'    => 'class-mode.php',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Setup tier.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Initialize the loader.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param WooPaymentsSetupTier      $setup_tier Setup tier.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsSetupTier $setup_tier ): void {
		$this->arbiter    = $arbiter;
		$this->setup_tier = $setup_tier;
	}

	/**
	 * Schedule the facade declarations after every active plugin file has loaded.
	 *
	 * @since 11.2.0
	 */
	public function register() {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->load();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'load' ), 0 );
	}

	/**
	 * Make the facades available when the built-in WooPayments owns payments and an account is connected.
	 *
	 * Below the connected tier other plugins see no WooPayments, as on a store with the feature off: until an account is
	 * connected the built-in takes no card payment, so no plugin should hide its own card methods for it.
	 *
	 * The classes are declared on first use through an autoloader, so a request that activates WooPayments without
	 * anything asking for them first leaves the names to the extension's own declarations. Requests recognized as a
	 * WooPayments activation get no facades at all, since a consumer probing the class earlier in the same request would
	 * otherwise declare it before the extension does.
	 *
	 * @since 11.2.0
	 */
	public function load(): void {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( ! in_array( $this->setup_tier->get_effective_tier(), array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true ) ) {
			return;
		}

		if ( self::is_woopayments_activation_request() ) {
			return;
		}

		// The extension defines its own version constant in WC_Payments::init(), never while its activation file loads.
		if ( ! defined( 'WCPAY_VERSION_NUMBER' ) ) {
			define( 'WCPAY_VERSION_NUMBER', WooPaymentsClientVersion::VERSION );
		}

		spl_autoload_register( array( $this, 'autoload_facade' ) );
	}

	/**
	 * Declare a facade class the first time it is used.
	 *
	 * @internal
	 *
	 * @param string $class_name Class being autoloaded.
	 */
	public function autoload_facade( string $class_name ): void {
		if ( isset( self::FACADE_FILES[ $class_name ] ) ) {
			// The facades live outside every Composer-scanned tree, so only this loader can declare them.
			require_once WC_ABSPATH . 'includes/legacy/woopayments-compat/' . self::FACADE_FILES[ $class_name ];
		}
	}

	/**
	 * Tell whether an official WordPress request may sandbox-load WooPayments later in this process.
	 *
	 * WordPress provides no pre-sandbox activation hook: `activate_plugin()` includes the target before
	 * firing `activate_plugin`. The request must therefore be recognized before the facades are declared.
	 *
	 * @return bool True when the facade declarations must stay available to the extension.
	 */
	public static function is_woopayments_activation_request(): bool {
		if ( Constants::is_true( 'WP_SANDBOX_SCRAPING' ) ) {
			return true;
		}

		if ( self::is_wp_cli_woopayments_activation_request() ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only collision avoidance for WordPress's separately authorized activation handlers.
		$request            = wp_unslash( $_REQUEST );
		$action             = isset( $request['action'] ) && is_string( $request['action'] ) ? sanitize_key( $request['action'] ) : '';
		$secondary_action   = isset( $request['action2'] ) && is_string( $request['action2'] ) ? sanitize_key( $request['action2'] ) : '';
		$activation_actions = array( 'activate', 'activate-plugin', 'activate-selected' );

		if ( ( in_array( $action, $activation_actions, true ) || in_array( $secondary_action, $activation_actions, true ) ) && self::request_targets_woopayments( $request ) ) {
			return true;
		}

		// WooCommerce's PluginsInstaller activates from an admin URL with a comma-separated `plugins` list.
		$plugin_action = isset( $request['plugin_action'] ) && is_string( $request['plugin_action'] ) ? sanitize_key( $request['plugin_action'] ) : '';
		if ( in_array( $plugin_action, array( 'activate', 'install-activate' ), true ) && isset( $request['plugins'] ) && is_string( $request['plugins'] ) && self::request_targets_woopayments( array( 'plugins' => explode( ',', $request['plugins'] ) ) ) ) {
			return true;
		}

		return self::is_plugins_rest_mutation_request( $request ) || self::is_jetpack_plugin_request();
	}

	/**
	 * Recognize plugin installation and activation through Jetpack's JSON API, including batched XML-RPC calls.
	 */
	private static function is_jetpack_plugin_request(): bool {
		if ( ! Constants::is_true( 'XMLRPC_REQUEST' ) ) {
			return false;
		}

		// xmlrpc.php reads the body before it loads WordPress. Leave ordinary XML-RPC requests, including uploads, alone.
		$body = $GLOBALS['HTTP_RAW_POST_DATA'] ?? null;
		if ( ! is_string( $body ) || ! str_contains( $body, 'jetpack.jsonAPI' ) ) {
			return false;
		}

		require_once ABSPATH . Constants::get_constant( 'WPINC' ) . '/class-IXR.php';
		$message = new \IXR_Message( $body );
		if ( ! $message->parse() ) {
			return false;
		}
		$method = $message->methodName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WordPress IXR_Message property.
		$calls  = 'system.multicall' === $method ? ( $message->params[0] ?? null ) : array(
			array(
				'methodName' => $method,
				'params'     => $message->params,
			),
		);
		if ( ! is_array( $calls ) ) {
			return false;
		}
		foreach ( $calls as $call ) {
			if ( ! is_array( $call ) || 'jetpack.jsonAPI' !== ( $call['methodName'] ?? null ) || ! is_array( $call['params'] ?? null ) ) {
				continue;
			}
			$args = $call['params'][0] ?? null;
			if ( ! is_array( $args ) || 'POST' !== ( $args[0] ?? null ) ) {
				continue;
			}
			if ( self::is_jetpack_plugin_mutation( $args ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tell whether a Jetpack plugins POST can install or activate WooPayments.
	 *
	 * @param array $args JSON API arguments from the XML-RPC call.
	 */
	private static function is_jetpack_plugin_mutation( array $args ): bool {
		$url  = $args[1] ?? null;
		$path = is_string( $url ) ? wp_parse_url( $url, PHP_URL_PATH ) : null;
		if ( ! is_string( $path ) || ! preg_match( '~/sites/[^/]+/plugins(?:/([^/]+))?(/install)?/?$~', $path, $matches ) ) {
			return false;
		}

		// A zip install does not identify the plugin until the archive is unpacked.
		$plugin = str_replace( ' ', '/', urldecode( $matches[1] ?? '' ) );
		if ( 'new' === $plugin ) {
			return true;
		}
		if ( '' !== $plugin && ! self::request_targets_woopayments( array( 'plugins' => array( $plugin, $plugin . '.php' ) ) ) ) {
			return false;
		}
		if ( isset( $matches[2] ) ) {
			return true;
		}

		// Jetpack accepts both JSON and form bodies. A secure body is retrieved by Jetpack later in the request.
		$body = $args[2] ?? null;
		if ( ! is_string( $body ) ) {
			return false;
		}
		$input = json_decode( $body, true );
		if ( ! is_array( $input ) ) {
			$input = array();
			parse_str( $body, $input );
		}
		$active = $input['active'] ?? false;
		return isset( $input['secure_key'] ) || 'install' === ( $input['action'] ?? null )
			|| ( is_scalar( $active ) && in_array( strtolower( (string) $active ), array( '1', 'true', 't' ), true ) );
	}

	/**
	 * Tell whether WP-CLI will activate WooPayments after WordPress finishes bootstrapping.
	 *
	 * @return bool True when the command can sandbox-load WooPayments in this process.
	 */
	private static function is_wp_cli_woopayments_activation_request(): bool {
		if ( ! Constants::is_true( 'WP_CLI' ) ) {
			return false;
		}

		$arguments = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? wp_unslash( $_SERVER['argv'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each scalar argument is sanitized below.
		$arguments = array_values(
			array_map(
				static function ( $argument ): string {
					return is_scalar( $argument ) ? sanitize_text_field( (string) $argument ) : '';
				},
				$arguments
			)
		);

		for ( $index = 0, $count = count( $arguments ); $index < $count - 1; ++$index ) {
			if ( 'plugin' !== $arguments[ $index ] ) {
				continue;
			}

			$command           = $arguments[ $index + 1 ];
			$command_arguments = array_slice( $arguments, $index + 2 );
			if ( 'activate' === $command || 'toggle' === $command ) {
				// WP-CLI also resolves a plugin file named without its `.php` extension.
				$plugin_files = array_map(
					static function ( string $argument ): string {
						return $argument . '.php';
					},
					$command_arguments
				);
				return in_array( '--all', $command_arguments, true ) || self::request_targets_woopayments( array( 'plugins' => array_merge( $command_arguments, $plugin_files ) ) );
			}

			if ( 'install' === $command ) {
				$activates_plugin = in_array( '--activate', $command_arguments, true ) || in_array( '--activate-network', $command_arguments, true );
				if ( ! $activates_plugin ) {
					return false;
				}

				// A zip path or URL names no plugin folder until WP-CLI unpacks it, so any such install counts.
				foreach ( $command_arguments as $argument ) {
					if ( str_contains( $argument, '://' ) || str_ends_with( $argument, '.zip' ) ) {
						return true;
					}
				}

				return self::request_targets_woopayments( array( 'plugins' => $command_arguments ) );
			}
		}

		return false;
	}

	/**
	 * Tell whether request parameters target the WooPayments extension.
	 *
	 * @param array<string,mixed> $request Unslashed request parameters.
	 * @return bool True when WooPayments is one of the requested plugins.
	 */
	private static function request_targets_woopayments( array $request ): bool {
		$candidates = array();
		foreach ( array( 'plugin', 'checked', 'plugins', 'slug' ) as $key ) {
			if ( ! isset( $request[ $key ] ) ) {
				continue;
			}

			$candidates = array_merge( $candidates, is_array( $request[ $key ] ) ? $request[ $key ] : array( $request[ $key ] ) );
		}

		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$plugin = ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $candidate ) ), '/' );
			if ( 'woocommerce-payments' === $plugin || WooPaymentsRuntimeArbiter::is_woopayments_plugin_file( $plugin ) || self::is_woopayments_plugin_folder( $plugin ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether a bare plugin folder name, which WP-CLI accepts, holds the WooPayments main file.
	 *
	 * @param string $plugin Requested plugin, without its main file.
	 * @return bool True when the folder holds the WooPayments main file.
	 */
	private static function is_woopayments_plugin_folder( string $plugin ): bool {
		if ( str_contains( $plugin, "\0" ) || str_contains( $plugin, '..' ) || str_contains( $plugin, '/' ) || '' === $plugin ) {
			return false;
		}

		return is_file( Constants::get_constant( 'WP_PLUGIN_DIR' ) . '/' . $plugin . '/' . wp_basename( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
	}

	/**
	 * Tell whether a plugins REST route (core's or WooCommerce's) may install or activate WooPayments.
	 *
	 * @param array<string,mixed> $request Unslashed request parameters.
	 * @return bool True for a mutating plugins collection request or WooPayments item request.
	 */
	private static function is_plugins_rest_mutation_request( array $request ): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized inline.
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return false;
		}

		$route = isset( $request['rest_route'] ) && is_string( $request['rest_route'] ) ? $request['rest_route'] : '';
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			$route = sanitize_text_field( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized inline.
		}

		$route = rawurldecode( $route );
		// WooCommerce's own plugin routes take their plugin list in a JSON body, so any install or activation request counts.
		foreach ( array( '/wc-admin/plugins/activate', '/wc-admin/plugins/install' ) as $wc_admin_route ) {
			$route_position = strpos( $route, $wc_admin_route );
			if ( false !== $route_position && ! str_starts_with( substr( $route, $route_position + strlen( $wc_admin_route ) ), '/status' ) ) {
				return true;
			}
		}

		$plugins_marker  = '/wp/v2/plugins';
		$marker_position = strpos( $route, $plugins_marker );
		if ( false === $marker_position ) {
			return false;
		}

		$tail = substr( $route, $marker_position + strlen( $plugins_marker ) );
		if ( '' === $tail || '/' === $tail || str_starts_with( $tail, '?' ) || str_starts_with( $tail, '&' ) ) {
			return true;
		}

		// The item route names the plugin file without its `.php` extension.
		$plugin = strtok( ltrim( $tail, '/' ), '?&' );
		return false !== $plugin && WooPaymentsRuntimeArbiter::is_woopayments_plugin_file( rtrim( $plugin, '/' ) . '.php' );
	}
}
