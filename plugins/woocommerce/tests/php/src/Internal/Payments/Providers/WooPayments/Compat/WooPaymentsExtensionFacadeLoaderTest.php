<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests the WooPayments legacy facade compatibility boundary.
 */
class WooPaymentsExtensionFacadeLoaderTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The APFS compatibility gate recognizes the built-in WooPayments.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apfs_gate_recognizes_the_builtin_woopayments(): void {
		$this->register_extension_facades();

		$gate_open = class_exists( 'WC_Payments' ) && class_exists( 'WC_Payments_Features' );
		$this->assertTrue( $gate_open, 'APFS should recognize native WooPayments through both legacy facade classes.' );
		if ( ! $gate_open ) {
			return;
		}

		$this->setExpectedDeprecated( 'WC_Payments_Features::is_wcpay_subscriptions_enabled' );
		$subscriptions_enabled = \WC_Payments_Features::is_wcpay_subscriptions_enabled();
		$this->assertFalse( $subscriptions_enabled, 'Native subscriptions are provided through the gateway contract, not the plugin feature flag.' );
		$this->assertTrue( defined( 'WCPAY_VERSION_NUMBER' ), 'The version constant must exist whenever the legacy facade classes exist.' );
		if ( ! defined( 'WCPAY_VERSION_NUMBER' ) ) {
			return;
		}

		$this->assertSame( WooPaymentsClientVersion::VERSION, WCPAY_VERSION_NUMBER, 'The legacy constant must report the platform compatibility version native WooPayments implements.' );
		$this->assertFalse( ! $subscriptions_enabled && version_compare( WCPAY_VERSION_NUMBER, '3.2.0' ) < 0, 'The exact APFS gate must not treat native WooPayments as an outdated plugin.' );
	}

	/**
	 * @testdox Core does not mistake its compatibility facade for the WooPayments extension's runtime.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facade_does_not_report_the_extension_runtime_as_loaded(): void {
		$this->register_extension_facades();

		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_extension_owner' )->willReturn( false );

		$extension_runtime = new WooPaymentsExtensionRuntime();
		$extension_runtime->init( new LegacyProxy(), $arbiter );

		$this->assertTrue( class_exists( 'WC_Payments' ), 'Extension compatibility requires the native facade to be available.' );
		$this->assertFalse( $extension_runtime->is_loaded(), 'Core must retain its Payments menu and other native-only behavior when only the facade is present.' );
	}

	/**
	 * @testdox The WC_Payments facade returns the container-owned gateway.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_payments_facade_returns_the_container_gateway(): void {
		$this->register_extension_facades();
		$this->assertTrue( class_exists( 'WC_Payments' ), 'The legacy WooPayments facade should be loaded.' );
		if ( ! class_exists( 'WC_Payments' ) ) {
			return;
		}

		$this->setExpectedDeprecated( 'WC_Payments::get_gateway' );
		$this->assertSame( wc_get_container()->get( WooPaymentsGateway::class ), \WC_Payments::get_gateway(), 'The facade must expose the same native gateway instance as the dependency-injection container.' );
	}

	/**
	 * @testdox The WC_Payments facade keeps the extension's Blueprint settings call safe.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_payments_facade_keeps_blueprint_settings_call_safe(): void {
		$this->register_extension_facades();
		$this->assertTrue( class_exists( 'WC_Payments' ), 'The legacy WooPayments facade should be loaded.' );
		if ( ! class_exists( 'WC_Payments' ) ) {
			return;
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		$this->setExpectedDeprecated( 'WC_Payments::hide_gateways_on_settings_page' );
		$this->assertNull( \WC_Payments::hide_gateways_on_settings_page(), 'The native facade should preserve the plugin method\'s void-like return shape.' );
		$this->assertSame( $gateways, WC()->payment_gateways->payment_gateways(), 'The native facade must not remove gateways by emulating the unsupported plugin gateway class.' );
	}

	/**
	 * @testdox The loader does not emulate the WooPayments extension's gateway class.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_gateway_does_not_satisfy_the_extension_gateway_class_identity(): void {
		$this->register_extension_facades();

		$gateway = wc_get_container()->get( WooPaymentsGateway::class );

		$this->assertFalse( class_exists( 'WC_Payment_Gateway_WCPay', false ), 'The compatibility boundary must not declare the standalone plugin gateway class.' );
		$this->assertFalse( $gateway instanceof \WC_Payment_Gateway_WCPay, 'The native gateway must not pretend to be the standalone plugin gateway class.' );
	}

	/**
	 * @testdox The extension facades stay absent when the built-in WooPayments does not own payments.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facades_stay_absent_when_the_builtin_does_not_own_payments(): void {
		if ( 'yes' === getenv( 'WOOPAYMENTS_FEATURE' ) ) {
			$this->markTestSkipped( 'The feature-on run loads the built-in WooPayments at boot, which declares the facades for the whole process.' );
		}
		$this->register_extension_facades( false );

		$this->assertFalse( class_exists( 'WC_Payments', false ), 'The plugin or ownerless runtime must retain authority over the WC_Payments symbol.' );
		$this->assertFalse( class_exists( 'WC_Payments_Features', false ), 'The plugin or ownerless runtime must retain authority over the WC_Payments_Features symbol.' );
		$this->assertFalse( defined( 'WCPAY_VERSION_NUMBER' ), 'The native compatibility version must not leak outside native ownership.' );
	}

	/**
	 * @testdox Official WordPress activation requests keep the extension-owned symbols available.
	 * @dataProvider official_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<string,mixed>  $request Request parameters.
	 * @param array<string,string> $server  Server parameters.
	 */
	public function test_official_activation_requests_keep_extension_symbols_available( array $request, array $server ): void {
		$_REQUEST = $request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing WordPress's already-authorized plugin activation requests.
		$_SERVER  = array_merge( $_SERVER, $server );

		$this->register_extension_facades();

		// Even a consumer probing the classes earlier in the request must not declare them: no autoloader is registered.
		$this->assertFalse( class_exists( 'WC_Payments' ), 'An activation request must reach the plugin sandbox without a predeclared bootstrap class. ' . $this->describe_facade_state() );
		$this->assertFalse( class_exists( 'WC_Payments_Features' ), 'An activation request must reach the plugin sandbox without a predeclared feature class. ' . $this->describe_facade_state() );
	}

	/**
	 * Official WordPress request shapes that can activate WooPayments after plugins_loaded.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>}>
	 */
	public function official_activation_request_provider(): array {
		return array(
			'plugins screen activate link'          => array(
				array(
					'action' => 'activate',
					'plugin' => WooPaymentsRuntimeArbiter::PLUGIN_FILE,
				),
				array(),
			),
			'bulk plugins screen'                   => array(
				array(
					'action'  => 'activate-selected',
					'checked' => array( 'another-plugin/another-plugin.php', WooPaymentsRuntimeArbiter::PLUGIN_FILE ),
				),
				array(),
			),
			'bulk bottom selector'                  => array(
				array(
					'action'  => '-1',
					'action2' => 'activate-selected',
					'checked' => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ),
				),
				array(),
			),
			'plugin installer ajax'                 => array(
				array(
					'action' => 'activate-plugin',
					'plugin' => WooPaymentsRuntimeArbiter::PLUGIN_FILE,
					'slug'   => 'woocommerce-payments',
				),
				array(),
			),
			'plugins REST item'                     => array(
				array(),
				array(
					'REQUEST_METHOD' => 'PUT',
					'REQUEST_URI'    => '/wp-json/wp/v2/plugins/woocommerce-payments%2Fwoocommerce-payments',
				),
			),
			'plugins REST collection'               => array(
				array( 'rest_route' => '/wp/v2/plugins' ),
				array( 'REQUEST_METHOD' => 'POST' ),
			),
			'WooCommerce plugins activate route'    => array(
				array(),
				array(
					'REQUEST_METHOD' => 'POST',
					'REQUEST_URI'    => '/wp-json/wc-admin/plugins/activate',
				),
			),
			'WooCommerce plugins install route'     => array(
				array( 'rest_route' => '/wc-admin/plugins/install' ),
				array( 'REQUEST_METHOD' => 'POST' ),
			),
			'WooCommerce PluginsInstaller URL'      => array(
				array(
					'plugin_action' => 'install-activate',
					'plugins'       => 'jetpack,woocommerce-payments',
				),
				array(),
			),
			'WooCommerce PluginsInstaller activate' => array(
				array(
					'plugin_action' => 'activate',
					'plugins'       => 'woocommerce-payments',
				),
				array(),
			),
			// WordPress 7.1.3 wp-admin/plugins.php: `activate` reads `plugin`, `activate-selected` reads `checked[]`.
			'renamed folder activate link'          => array(
				array(
					'action' => 'activate',
					'plugin' => 'woocommerce-payments-10.5.0/woocommerce-payments.php',
				),
				array(),
			),
			'renamed folder bulk plugins screen'    => array(
				array(
					'action'  => 'activate-selected',
					'checked' => array( 'another-plugin/another-plugin.php', 'woocommerce-payments-10.5.0/woocommerce-payments.php' ),
				),
				array(),
			),
			// WordPress 7.1.3 WP_REST_Plugins_Controller: the item pattern `[^.\/]+(?:\/[^.\/]+)?` rejects dots, so this renamed
			// folder has none, and sanitize_plugin_param() appends `.php` to the matched `<folder>/<file>`.
			'renamed folder plugins REST item'      => array(
				array(),
				array(
					'REQUEST_METHOD' => 'PUT',
					'REQUEST_URI'    => '/wp-json/wp/v2/plugins/woocommerce-payments-copy%2Fwoocommerce-payments',
				),
			),
			'renamed folder REST item with query'   => array(
				array(),
				array(
					'REQUEST_METHOD' => 'PUT',
					'REQUEST_URI'    => '/wp-json/wp/v2/plugins/woocommerce-payments-copy%2Fwoocommerce-payments?context=edit',
				),
			),
		);
	}

	/**
	 * @testdox WP-CLI keeps extension-owned symbols available for programmatic activation.
	 * @dataProvider wp_cli_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<int,string> $arguments WP-CLI arguments.
	 */
	public function test_wp_cli_keeps_extension_symbols_available_for_activation( array $arguments ): void {
		define( 'WP_CLI', true );
		$_SERVER['argv'] = $arguments;

		// A plugins directory holding WooPayments in a renamed folder, which WP-CLI also activates by its folder name.
		$plugins_dir = sys_get_temp_dir() . '/wc-facade-loader-' . wp_generate_uuid4();
		$main_file   = $plugins_dir . '/woocommerce-payments-10.5.0/woocommerce-payments.php';
		wp_mkdir_p( dirname( $main_file ) );
		file_put_contents( $main_file, "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
		Constants::set_constant( 'WP_PLUGIN_DIR', $plugins_dir );

		try {
			$this->register_extension_facades();

			// Probing with autoload: a recognized activation registers no autoloader, so even a probe declares nothing.
			$this->assertFalse( class_exists( 'WC_Payments' ), 'WP-CLI must be able to activate the standalone plugin without a class collision. ' . $this->describe_facade_state() );
			$this->assertFalse( class_exists( 'WC_Payments_Features' ), 'WP-CLI must retain the plugin feature-class name for an activation sandbox. ' . $this->describe_facade_state() );
		} finally {
			wp_delete_file( $main_file );
			rmdir( dirname( $main_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $plugins_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		}
	}

	/**
	 * WP-CLI commands that may activate WooPayments after bootstrap.
	 *
	 * @return array<string,array{array<int,string>}>
	 */
	public function wp_cli_activation_request_provider(): array {
		return array(
			'activate slug'                  => array( array( 'wp', 'plugin', 'activate', 'woocommerce-payments' ) ),
			'activate plugin file'           => array( array( 'wp', 'plugin', 'activate', WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ),
			'activate all'                   => array( array( 'wp', 'plugin', 'activate', '--all' ) ),
			'install and activate'           => array( array( 'wp', 'plugin', 'install', 'woocommerce-payments', '--activate' ) ),
			'install network activated'      => array( array( 'wp', 'plugin', 'install', 'woocommerce-payments', '--activate-network' ) ),
			'toggle'                         => array( array( 'wp', 'plugin', 'toggle', 'woocommerce-payments' ) ),
			// WP-CLI 2.12.0 (extension-command 2.1.24) Fetchers\Plugin::get() resolves `<name>.php`, the plugin file, or its folder.
			'activate renamed folder'        => array( array( 'wp', 'plugin', 'activate', 'woocommerce-payments-10.5.0/woocommerce-payments.php' ) ),
			'activate renamed folder name'   => array( array( 'wp', 'plugin', 'activate', 'woocommerce-payments-10.5.0' ) ),
			'activate file without .php'     => array( array( 'wp', 'plugin', 'activate', 'woocommerce-payments-10.5.0/woocommerce-payments' ) ),
			// WP-CLI 2.12.0 CommandWithUpgrade::install() takes a URL (`://`) or a local `.zip`, then activates the unpacked folder.
			'install local zip and activate' => array( array( 'wp', 'plugin', 'install', './woocommerce-payments.11.1.0.zip', '--activate' ) ),
			'install URL and activate'       => array( array( 'wp', 'plugin', 'install', 'https://api.github.com/repos/Automattic/woocommerce-payments/zipball/11.1.0', '--activate' ) ),
		);
	}

	/**
	 * @testdox Non-activating WP-CLI requests retain the compatibility facades.
	 * @dataProvider wp_cli_non_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<int,string> $arguments WP-CLI arguments.
	 */
	public function test_ordinary_wp_cli_request_keeps_the_facades( array $arguments ): void {
		define( 'WP_CLI', true );
		$_SERVER['argv'] = $arguments;

		$this->register_extension_facades();

		$this->assertTrue( class_exists( 'WC_Payments' ), 'Ordinary WP-CLI commands should retain native WooPayments compatibility.' );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ), 'Ordinary WP-CLI commands should retain native WooPayments feature compatibility.' );
	}

	/**
	 * WP-CLI commands that cannot activate WooPayments after bootstrap.
	 *
	 * @return array<string,array{array<int,string>}>
	 */
	public function wp_cli_non_activation_request_provider(): array {
		return array(
			'ordinary command'           => array( array( 'wp', 'option', 'get', 'home' ) ),
			'unrelated activation'       => array( array( 'wp', 'plugin', 'activate', 'akismet' ) ),
			'install without activation' => array( array( 'wp', 'plugin', 'install', 'woocommerce-payments' ) ),
		);
	}

	/**
	 * @testdox The facades are declared on first use, so an activation nothing probed first leaves both names to the extension.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facades_are_declared_on_first_use(): void {
		$this->register_extension_facades();

		$this->assertFalse( class_exists( 'WC_Payments', false ), 'Loading must not declare the bootstrap facade before it is used.' );
		$this->assertFalse( class_exists( 'WC_Payments_Features', false ), 'Loading must not declare the feature facade before it is used.' );
		$this->assertTrue( defined( 'WCPAY_VERSION_NUMBER' ) );

		// An activation path no request shape reveals (an Action Scheduler activation callback, activate_plugin() from code).
		define( 'WP_SANDBOX_SCRAPING', true );
		require __DIR__ . '/Fixtures/woocommerce-payments-activation-bootstrap.fixture';

		$this->assertSame( 'plugin', \WC_Payments::DECLARATION_OWNER );
		$this->assertSame( 'plugin', \WC_Payments_Features::DECLARATION_OWNER );
	}

	/**
	 * @testdox Probing WC_Payments declares only that facade, so the extension's main file can still declare WC_Payments_Features.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_probing_wc_payments_leaves_the_feature_class_to_the_extension(): void {
		$this->register_extension_facades();

		$this->assertTrue( class_exists( 'WC_Payments' ), 'A consumer probe declares the bootstrap facade.' );
		$this->assertFalse( class_exists( 'WC_Payments_Features', false ), 'The bootstrap facade must not pull in the feature facade.' );

		require __DIR__ . '/Fixtures/woocommerce-payments-main-file.fixture';

		$this->assertSame( 'plugin', \WC_Payments_Features::DECLARATION_OWNER );
	}

	/**
	 * @testdox A request that is not a WooPayments activation keeps the compatibility facades ($_dataName).
	 * @dataProvider non_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<string,mixed>  $request Request parameters.
	 * @param array<string,string> $server  Server parameters.
	 */
	public function test_non_activation_requests_keep_the_facades( array $request, array $server ): void {
		$_REQUEST = $request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing a plugin or REST request.
		$_SERVER  = array_merge( $_SERVER, $server );

		$this->register_extension_facades();

		$this->assertTrue( class_exists( 'WC_Payments' ), 'Requests that do not activate WooPayments should retain native WooPayments compatibility.' );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ), 'Requests that do not activate WooPayments should retain native WooPayments feature compatibility.' );
	}

	/**
	 * Requests that cannot activate WooPayments: another plugin's activation and WooCommerce's plugin job status routes.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>}>
	 */
	public function non_activation_request_provider(): array {
		return array(
			'activating an unrelated plugin' => array(
				array(
					'action' => 'activate',
					'plugin' => 'another-plugin/another-plugin.php',
				),
				array(),
			),
			'activation status route'        => array(
				array( 'rest_route' => '/wc-admin/plugins/activate/status' ),
				array( 'REQUEST_METHOD' => 'POST' ),
			),
			'install status route'           => array(
				array( 'rest_route' => '/wc-admin/plugins/install/status/job_1' ),
				array( 'REQUEST_METHOD' => 'POST' ),
			),
		);
	}

	/**
	 * Register the facade loader under a controlled ownership decision.
	 *
	 * @param bool $builtin_owns Whether the native runtime owns payments.
	 */
	private function register_extension_facades( bool $builtin_owns = true ): void {
		// A process whose bootstrap found native payments enabled already registered the production loader for an ordinary
		// request; the request under test replaces it, as it would be the only loader in that request.
		foreach ( spl_autoload_functions() as $autoloader ) {
			if ( is_array( $autoloader ) && $autoloader[0] instanceof WooPaymentsExtensionFacadeLoader ) {
				spl_autoload_unregister( $autoloader );
			}
		}

		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( $builtin_owns );

		$loader = new WooPaymentsExtensionFacadeLoader();
		$loader->init( $arbiter );
		$loader->register();
	}

	/**
	 * Describe where the facade names come from, so a failure in another environment names its source.
	 *
	 * @return string JSON state: the file declaring WC_Payments, the registered autoloaders and the runtime ownership inputs.
	 */
	private function describe_facade_state(): string {
		$autoloaders = array();
		foreach ( spl_autoload_functions() as $autoloader ) {
			if ( is_array( $autoloader ) ) {
				$autoloaders[] = ( is_object( $autoloader[0] ) ? get_class( $autoloader[0] ) : (string) $autoloader[0] ) . '::' . $autoloader[1];
			} elseif ( $autoloader instanceof \Closure ) {
				$reflection    = new \ReflectionFunction( $autoloader );
				$autoloaders[] = 'closure@' . $reflection->getFileName() . ':' . $reflection->getStartLine();
			} else {
				$autoloaders[] = is_string( $autoloader ) ? $autoloader : gettype( $autoloader );
			}
		}

		return (string) wp_json_encode(
			array(
				'wc_payments_file'          => class_exists( 'WC_Payments', false ) ? ( new \ReflectionClass( 'WC_Payments' ) )->getFileName() : null,
				'wc_payments_features_file' => class_exists( 'WC_Payments_Features', false ) ? ( new \ReflectionClass( 'WC_Payments_Features' ) )->getFileName() : null,
				'autoloaders'               => $autoloaders,
				'runtime_owner'             => wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->get_runtime_owner(),
				'feature_option'            => get_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, null ),
				'version_constant'          => defined( 'WCPAY_VERSION_NUMBER' ) ? constant( 'WCPAY_VERSION_NUMBER' ) : null,
				'active_plugins'            => get_option( 'active_plugins', array() ),
			)
		);
	}
}
