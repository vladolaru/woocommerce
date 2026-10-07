<?php
/**
 * LegacyFacadeLoader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Loads the legacy WooPayments facades while the native runtime owns payments.
 *
 * @since 11.2.0
 * @internal Transitional compatibility boundary scheduled for removal in WooCommerce 12.0.0.
 */
class LegacyFacadeLoader implements RegisterHooksInterface {

	/**
	 * Facade class names and the files that declare them.
	 */
	private const FACADE_FILES = array(
		'WC_Payments'          => 'class-wc-payments.php',
		'WC_Payments_Features' => 'class-wc-payments-features.php',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the loader.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
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
	 * Make the legacy facades available when the native runtime owns payments.
	 *
	 * The two classes are declared on first use through an autoloader, so a request that activates WooPayments without
	 * anything asking for them first leaves the names to the plugin's own declarations. Requests recognized as a
	 * WooPayments activation get no facades at all, since a consumer probing the class earlier in the same request would
	 * otherwise declare it before the plugin does.
	 *
	 * @since 11.2.0
	 */
	public function load(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( $this->is_woopayments_activation_request() ) {
			return;
		}

		// The plugin defines its own version constant in WC_Payments::init(), never while its activation file loads.
		if ( ! defined( 'WCPAY_VERSION_NUMBER' ) ) {
			define( 'WCPAY_VERSION_NUMBER', WooPaymentsClientVersion::VERSION );
		}

		spl_autoload_register( array( $this, 'autoload_facade' ) );
	}

	/**
	 * Declare a legacy facade class the first time it is used.
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
	 * @return bool True when the facade declarations must stay available to the plugin.
	 */
	private function is_woopayments_activation_request(): bool {
		if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
			return true;
		}

		if ( $this->is_wp_cli_woopayments_activation_request() ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only collision avoidance for WordPress's separately authorized activation handlers.
		$request            = wp_unslash( $_REQUEST );
		$action             = isset( $request['action'] ) && is_string( $request['action'] ) ? sanitize_key( $request['action'] ) : '';
		$secondary_action   = isset( $request['action2'] ) && is_string( $request['action2'] ) ? sanitize_key( $request['action2'] ) : '';
		$activation_actions = array( 'activate', 'activate-plugin', 'activate-selected' );

		if ( ( in_array( $action, $activation_actions, true ) || in_array( $secondary_action, $activation_actions, true ) ) && $this->request_targets_woopayments( $request ) ) {
			return true;
		}

		// WooCommerce's PluginsInstaller activates from an admin URL with a comma-separated `plugins` list.
		$plugin_action = isset( $request['plugin_action'] ) && is_string( $request['plugin_action'] ) ? sanitize_key( $request['plugin_action'] ) : '';
		if ( in_array( $plugin_action, array( 'activate', 'install-activate' ), true ) && isset( $request['plugins'] ) && is_string( $request['plugins'] ) && $this->request_targets_woopayments( array( 'plugins' => explode( ',', $request['plugins'] ) ) ) ) {
			return true;
		}

		return $this->is_plugins_rest_mutation_request( $request );
	}

	/**
	 * Tell whether WP-CLI will activate WooPayments after WordPress finishes bootstrapping.
	 *
	 * @return bool True when the command can sandbox-load WooPayments in this process.
	 */
	private function is_wp_cli_woopayments_activation_request(): bool {
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
				return in_array( '--all', $command_arguments, true ) || $this->request_targets_woopayments( array( 'plugins' => $command_arguments ) );
			}

			if ( 'install' === $command ) {
				$activates_plugin = in_array( '--activate', $command_arguments, true ) || in_array( '--activate-network', $command_arguments, true );
				return $activates_plugin && $this->request_targets_woopayments( array( 'plugins' => $command_arguments ) );
			}
		}

		return false;
	}

	/**
	 * Tell whether request parameters target the standalone WooPayments plugin.
	 *
	 * @param array<string,mixed> $request Unslashed request parameters.
	 * @return bool True when WooPayments is one of the requested plugins.
	 */
	private function request_targets_woopayments( array $request ): bool {
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
			if ( NativePaymentsRuntimeArbiter::PLUGIN_FILE === $plugin || 'woocommerce-payments' === $plugin || str_ends_with( $plugin, '/' . NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether a plugins REST route (core's or WooCommerce's) may install or activate WooPayments.
	 *
	 * @param array<string,mixed> $request Unslashed request parameters.
	 * @return bool True for a mutating plugins collection request or WooPayments item request.
	 */
	private function is_plugins_rest_mutation_request( array $request ): bool {
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

		return str_starts_with( $tail, '/woocommerce-payments/woocommerce-payments' );
	}
}
