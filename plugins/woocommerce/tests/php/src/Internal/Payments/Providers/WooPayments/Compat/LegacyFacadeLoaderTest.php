<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\LegacyFacadeLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests the WooPayments legacy facade compatibility boundary.
 */
class LegacyFacadeLoaderTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The APFS compatibility gate recognizes the native runtime.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apfs_gate_recognizes_native_runtime(): void {
		$this->register_legacy_facades();

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
	 * @testdox The AutomateWoo compatibility gate recognizes the native runtime.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_automatewoo_gate_recognizes_native_runtime(): void {
		$this->register_legacy_facades();

		$this->assertTrue( class_exists( '\\WC_Payments' ), 'AutomateWoo should enter its WooPayments integration branch for the native runtime.' );
	}

	/**
	 * @testdox The PayPal Payments compatibility gate recognizes the native runtime.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_paypal_payments_gate_recognizes_native_runtime(): void {
		$this->register_legacy_facades();

		$this->assertTrue( class_exists( '\\WC_Payments' ), 'PayPal Payments should recognize native WooPayments when making its onboarding decision.' );
	}

	/**
	 * @testdox Core does not mistake its compatibility facade for the standalone WooPayments runtime.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_native_facade_does_not_report_standalone_runtime_as_loaded(): void {
		$this->register_legacy_facades();

		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_plugin_runtime_active' )->willReturn( false );

		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyProxy(), $arbiter );

		$this->assertTrue( class_exists( 'WC_Payments' ), 'Extension compatibility requires the native facade to be available.' );
		$this->assertFalse( $legacy_runtime->is_loaded(), 'Core must retain its Payments menu and other native-only behavior when only the facade is present.' );
	}

	/**
	 * @testdox The WC_Payments facade returns the container-owned native gateway.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_payments_facade_returns_native_gateway(): void {
		$this->register_legacy_facades();
		$this->assertTrue( class_exists( 'WC_Payments' ), 'The legacy WooPayments facade should be loaded.' );
		if ( ! class_exists( 'WC_Payments' ) ) {
			return;
		}

		$this->setExpectedDeprecated( 'WC_Payments::get_gateway' );
		$this->assertSame( wc_get_container()->get( NativeWooPaymentsGateway::class ), \WC_Payments::get_gateway(), 'The facade must expose the same native gateway instance as the dependency-injection container.' );
	}

	/**
	 * @testdox The WC_Payments facade keeps the legacy Blueprint settings call safe.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_payments_facade_keeps_blueprint_settings_call_safe(): void {
		$this->register_legacy_facades();
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
	 * @testdox The loader does not emulate the standalone plugin gateway class.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_native_gateway_does_not_satisfy_legacy_gateway_class_identity(): void {
		$this->register_legacy_facades();

		$gateway = wc_get_container()->get( NativeWooPaymentsGateway::class );

		$this->assertFalse( class_exists( 'WC_Payment_Gateway_WCPay', false ), 'The compatibility boundary must not declare the standalone plugin gateway class.' );
		$this->assertFalse( $gateway instanceof \WC_Payment_Gateway_WCPay, 'The native gateway must not pretend to be the standalone plugin gateway class.' );
	}

	/**
	 * @testdox Legacy facades stay absent when native does not own payments.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facades_stay_absent_when_native_does_not_own_payments(): void {
		$this->register_legacy_facades( false );

		$this->assertFalse( class_exists( 'WC_Payments', false ), 'The plugin or ownerless runtime must retain authority over the WC_Payments symbol.' );
		$this->assertFalse( class_exists( 'WC_Payments_Features', false ), 'The plugin or ownerless runtime must retain authority over the WC_Payments_Features symbol.' );
		$this->assertFalse( defined( 'WCPAY_VERSION_NUMBER' ), 'The native compatibility version must not leak outside native ownership.' );
	}

	/**
	 * @testdox A WordPress admin activation sandbox can declare the standalone plugin facades.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_activation_sandbox_can_declare_plugin_facades(): void {
		$_REQUEST['action'] = 'activate'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing WordPress's already-authorized plugin activation request.
		$_REQUEST['plugin'] = NativePaymentsRuntimeArbiter::PLUGIN_FILE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing WordPress's already-authorized plugin activation request.

		$this->register_legacy_facades();

		define( 'WP_SANDBOX_SCRAPING', true );
		require __DIR__ . '/Fixtures/woocommerce-payments-activation-bootstrap.inc';

		$this->assertSame( 'plugin', \WC_Payments::DECLARATION_OWNER, 'The activation sandbox must retain authority to declare the plugin bootstrap class.' );
		$this->assertSame( 'plugin', \WC_Payments_Features::DECLARATION_OWNER, 'The activation sandbox must retain authority to declare the plugin feature class.' );
	}

	/**
	 * @testdox Official WordPress activation requests keep the plugin-owned symbols available.
	 * @dataProvider official_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<string,mixed>  $request Request parameters.
	 * @param array<string,string> $server  Server parameters.
	 */
	public function test_official_activation_requests_keep_plugin_symbols_available( array $request, array $server ): void {
		$_REQUEST = $request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing WordPress's already-authorized plugin activation requests.
		$_SERVER  = array_merge( $_SERVER, $server );

		$this->register_legacy_facades();

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
			'bulk plugins screen'                   => array(
				array(
					'action'  => 'activate-selected',
					'checked' => array( 'another-plugin/another-plugin.php', NativePaymentsRuntimeArbiter::PLUGIN_FILE ),
				),
				array(),
			),
			'bulk bottom selector'                  => array(
				array(
					'action'  => '-1',
					'action2' => 'activate-selected',
					'checked' => array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ),
				),
				array(),
			),
			'plugin installer ajax'                 => array(
				array(
					'action' => 'activate-plugin',
					'plugin' => NativePaymentsRuntimeArbiter::PLUGIN_FILE,
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
		);
	}

	/**
	 * @testdox WP-CLI keeps plugin-owned symbols available for programmatic activation.
	 * @dataProvider wp_cli_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<int,string> $arguments WP-CLI arguments.
	 */
	public function test_wp_cli_keeps_plugin_symbols_available_for_activation( array $arguments ): void {
		define( 'WP_CLI', true );
		$_SERVER['argv'] = $arguments;

		$this->register_legacy_facades();

		// Probing with autoload: a recognized activation registers no autoloader, so even a probe declares nothing.
		$this->assertFalse( class_exists( 'WC_Payments' ), 'WP-CLI must be able to activate the standalone plugin without a class collision. ' . $this->describe_facade_state() );
		$this->assertFalse( class_exists( 'WC_Payments_Features' ), 'WP-CLI must retain the plugin feature-class name for an activation sandbox. ' . $this->describe_facade_state() );
	}

	/**
	 * WP-CLI commands that may activate WooPayments after bootstrap.
	 *
	 * @return array<string,array{array<int,string>}>
	 */
	public function wp_cli_activation_request_provider(): array {
		return array(
			'activate slug'             => array( array( 'wp', 'plugin', 'activate', 'woocommerce-payments' ) ),
			'activate plugin file'      => array( array( 'wp', 'plugin', 'activate', NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ),
			'activate all'              => array( array( 'wp', 'plugin', 'activate', '--all' ) ),
			'install and activate'      => array( array( 'wp', 'plugin', 'install', 'woocommerce-payments', '--activate' ) ),
			'install network activated' => array( array( 'wp', 'plugin', 'install', 'woocommerce-payments', '--activate-network' ) ),
			'toggle'                    => array( array( 'wp', 'plugin', 'toggle', 'woocommerce-payments' ) ),
		);
	}

	/**
	 * @testdox Non-activating WP-CLI requests retain native compatibility facades.
	 * @dataProvider wp_cli_non_activation_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param array<int,string> $arguments WP-CLI arguments.
	 */
	public function test_ordinary_wp_cli_request_keeps_native_facades( array $arguments ): void {
		define( 'WP_CLI', true );
		$_SERVER['argv'] = $arguments;

		$this->register_legacy_facades();

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
	 * @testdox The facades are declared on first use, so an activation nothing probed first leaves both names to the plugin.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facades_are_declared_on_first_use(): void {
		$this->register_legacy_facades();

		$this->assertFalse( class_exists( 'WC_Payments', false ), 'Loading must not declare the bootstrap facade before it is used.' );
		$this->assertFalse( class_exists( 'WC_Payments_Features', false ), 'Loading must not declare the feature facade before it is used.' );
		$this->assertTrue( defined( 'WCPAY_VERSION_NUMBER' ) );

		// An activation path no request shape reveals (an Action Scheduler activation callback, activate_plugin() from code).
		define( 'WP_SANDBOX_SCRAPING', true );
		require __DIR__ . '/Fixtures/woocommerce-payments-activation-bootstrap.inc';

		$this->assertSame( 'plugin', \WC_Payments::DECLARATION_OWNER );
		$this->assertSame( 'plugin', \WC_Payments_Features::DECLARATION_OWNER );
	}

	/**
	 * @testdox Probing WC_Payments declares only that facade, so the plugin's main file can still declare WC_Payments_Features.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_probing_wc_payments_leaves_the_feature_class_to_the_plugin(): void {
		$this->register_legacy_facades();

		$this->assertTrue( class_exists( 'WC_Payments' ), 'A consumer probe declares the bootstrap facade.' );
		$this->assertFalse( class_exists( 'WC_Payments_Features', false ), 'The bootstrap facade must not pull in the feature facade.' );

		require __DIR__ . '/Fixtures/woocommerce-payments-main-file.inc';

		$this->assertSame( 'plugin', \WC_Payments_Features::DECLARATION_OWNER );
	}

	/**
	 * @testdox Activating an unrelated plugin does not hide the native compatibility facades.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unrelated_plugin_activation_keeps_native_facades(): void {
		$_REQUEST['action'] = 'activate'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing WordPress's already-authorized plugin activation request.
		$_REQUEST['plugin'] = 'another-plugin/another-plugin.php'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing WordPress's already-authorized plugin activation request.

		$this->register_legacy_facades();

		$this->assertTrue( class_exists( 'WC_Payments' ), 'Unrelated activation requests should retain native WooPayments compatibility.' );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ), 'Unrelated activation requests should retain native WooPayments feature compatibility.' );
	}

	/**
	 * @testdox WooCommerce's plugin job status routes are not activations and keep the native facades.
	 * @dataProvider plugin_status_route_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $route Status route.
	 */
	public function test_plugin_status_routes_keep_native_facades( string $route ): void {
		$_REQUEST = array( 'rest_route' => $route ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing a REST request.
		$_SERVER  = array_merge( $_SERVER, array( 'REQUEST_METHOD' => 'POST' ) );

		$this->register_legacy_facades();

		$this->assertTrue( class_exists( 'WC_Payments' ) );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ) );
	}

	/**
	 * WooCommerce plugin job status routes.
	 *
	 * @return array<string,array{string}>
	 */
	public function plugin_status_route_provider(): array {
		return array(
			'activation status' => array( '/wc-admin/plugins/activate/status' ),
			'install status'    => array( '/wc-admin/plugins/install/status/job_1' ),
		);
	}

	/**
	 * Register the facade loader under a controlled ownership decision.
	 *
	 * @param bool $native_owns Whether the native runtime owns payments.
	 */
	private function register_legacy_facades( bool $native_owns = true ): void {
		$this->assertTrue( class_exists( LegacyFacadeLoader::class ), 'The native runtime should provide a dedicated legacy facade loader.' );

		// A process whose bootstrap found native payments enabled already registered the production loader for an ordinary
		// request; the request under test replaces it, as it would be the only loader in that request.
		foreach ( spl_autoload_functions() as $autoloader ) {
			if ( is_array( $autoloader ) && $autoloader[0] instanceof LegacyFacadeLoader ) {
				spl_autoload_unregister( $autoloader );
			}
		}

		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );

		$loader = new LegacyFacadeLoader();
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
				'runtime_owner'             => wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->get_runtime_owner(),
				'native_option'             => get_option( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, null ),
				'version_constant'          => defined( 'WCPAY_VERSION_NUMBER' ) ? constant( 'WCPAY_VERSION_NUMBER' ) : null,
				'active_plugins'            => get_option( 'active_plugins', array() ),
			)
		);
	}
}
