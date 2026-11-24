<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use WC_Unit_Test_Case;

/**
 * Tests the WooPayments legacy facade compatibility boundary.
 *
 * The request rows run in the main process, which always holds a `WC_Payments` class (a legacy onboarding test declares a
 * fake at file load, and the feature-on boot declares the facade), so they observe the loader's own autoloader and the
 * version constant instead of class names. Rows that need the real facade or fixture classes keep a separate process.
 * Each request row sets every input the activation predicate reads, since earlier tests leave request globals behind and
 * WordPress's activate_plugin() defines `WP_SANDBOX_SCRAPING` for the rest of the process.
 */
class WooPaymentsExtensionFacadeLoaderTest extends WC_Unit_Test_Case {

	/**
	 * WooPayments in a renamed folder, under the plugins directory the request rows use.
	 */
	private const RENAMED_MAIN_FILE = 'woocommerce-payments-10.5.0/woocommerce-payments.php';

	/**
	 * Facade loader autoloaders registered before the test (the boot loader's on the feature-on leg).
	 *
	 * @var array<int,callable>
	 */
	private array $boot_autoloaders = array();

	/**
	 * The loader the test registered, if any.
	 *
	 * @var WooPaymentsExtensionFacadeLoader|null
	 */
	private ?WooPaymentsExtensionFacadeLoader $loader = null;

	/**
	 * Request globals before the test: `$_GET`, `$_POST`, `$_REQUEST` and `$_SERVER`.
	 *
	 * @var array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}
	 */
	private array $request_globals = array( array(), array(), array(), array() );

	/**
	 * The plugins directory the test created, if any.
	 *
	 * @var string
	 */
	private string $plugins_dir = '';

	/**
	 * Record the facade loader autoloaders and the request globals the test may change.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->boot_autoloaders = array_values( array_filter( $this->registered_autoloaders(), array( $this, 'is_facade_autoloader' ) ) );
		$this->request_globals  = array( $_GET, $_POST, $_REQUEST, $_SERVER ); // phpcs:ignore WordPress.Security.NonceVerification -- Saved for restore only.
	}

	/**
	 * Unregister the test's loader, restore the boot loaders, the request globals and the constants the rows set, and
	 * remove the plugins directory.
	 */
	public function tearDown(): void {
		try {
			if ( null !== $this->loader ) {
				spl_autoload_unregister( array( $this->loader, 'autoload_facade' ) );
			}
			foreach ( $this->boot_autoloaders as $autoloader ) {
				if ( ! in_array( $autoloader, $this->registered_autoloaders(), true ) ) {
					spl_autoload_register( $autoloader );
				}
			}
			list( $_GET, $_POST, $_REQUEST, $_SERVER ) = $this->request_globals;
			foreach ( array( 'WP_CLI', 'WP_PLUGIN_DIR', 'WP_SANDBOX_SCRAPING' ) as $name ) {
				Constants::clear_single_constant( $name );
			}
			if ( '' !== $this->plugins_dir ) {
				wp_delete_file( $this->plugins_dir . '/' . self::RENAMED_MAIN_FILE );
				rmdir( dirname( $this->plugins_dir . '/' . self::RENAMED_MAIN_FILE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				rmdir( $this->plugins_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox The legacy amount helper rounds once and preserves zero-decimal currencies.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_prepare_amount(): void {
		$this->register_extension_facades();
		$this->setExpectedIncorrectUsage( 'WC_Payments_Utils::prepare_amount()' );
		$warnings = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $member, $message, $version ) use ( &$warnings ) {
				$warnings[] = array( $member, $message, $version );
			},
			10,
			3
		);
		$this->assertSame( 1001, \WC_Payments_Utils::prepare_amount( 10.005, 'USD' ) );
		$this->assertSame( 1000, \WC_Payments_Utils::prepare_amount( '1000', 'JPY' ) );
		$this->assertSame( 1000, \WC_Payments_Utils::prepare_amount( '1000', 'jpy' ) );
		$this->assertSame( -125, \WC_Payments_Utils::prepare_amount( '-1.25' ) );
		$this->assertSame( 125, \WC_Payments_Utils::prepare_amount( 1.25, null ) );
		$this->assertSame(
			array( array( 'WC_Payments_Utils::prepare_amount()', 'WC_Payments_Utils::prepare_amount() is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.', '11.4.0' ) ),
			$warnings
		);
	}

	/**
	 * @testdox The country and order-lock probes still skip the extension's APIs.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_country_and_lock_probes(): void {
		$this->register_extension_facades();
		class_exists( 'WC_Payments_Utils' );
		$this->assertNull( wc_get_container()->get( WooPaymentsExtensionRuntime::class )->get_supported_countries() );
		foreach ( array( 'lock_order_payment', 'unlock_order_payment', 'is_order_locked' ) as $method ) {
			$this->assertFalse( method_exists( 'WC_Payments_Utils', $method ), $method );
		}
		$this->assertFalse( class_exists( 'WC_Payments_Order_Service' ) );
	}

	/**
	 * @testdox Jetpack plugin installation and activation leave the extension free to declare its own classes.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @testWith ["jetpack.jsonAPI", "POST", "/rest/v1.2/sites/123/plugins/woocommerce-payments%2Fwoocommerce-payments", true, true]
	 *           ["jetpack.jsonAPI", "POST", "/rest/v1.1/sites/123/plugins/woocommerce-payments/install", false, true]
	 *           ["jetpack.jsonAPI", "POST", "/rest/v1.2/sites/123/plugins/woocommerce-payments%20woocommerce-payments", true, true, "active=true"]
	 *           ["jetpack.jsonAPI", "POST", "/rest/v1.2/sites/123/plugins/woocommerce-payments", true, false, "active=false"]
	 *           ["jetpack.jsonAPI", "POST", "/rest/v1.2/sites/123/plugins/another-plugin/install", true, false]
	 *           ["jetpack.jsonAPI", "POST", "/rest/v1.2/sites/123/plugins", false, true, "{\"plugins\":[\"another-plugin/another-plugin\"],\"active\":true}"]
	 *           ["jetpack.jsonAPI", "GET", "/rest/v1.2/sites/123/plugins/woocommerce-payments", true, false]
	 *           ["jetpack.jsonAPI", "POST", "/rest/v1.2/sites/123/posts/new", true, false]
	 *           ["another.method", "POST", "/rest/v1.2/sites/123/plugins/new", false, false]
	 *
	 * @param string $method    XML-RPC method.
	 * @param string $verb      Inner API method.
	 * @param string $path      Inner API path.
	 * @param bool   $multicall Whether the call is in a system.multicall envelope.
	 * @param bool   $activates Whether the request can load the extension.
	 * @param string $body      Inner JSON or form body.
	 */
	public function test_remote_plugin_management( string $method, string $verb, string $path, bool $multicall, bool $activates, string $body = '{"active":true}' ): void {
		require_once ABSPATH . Constants::get_constant( 'WPINC' ) . '/class-IXR.php';
		$this->set_request( array(), array(), null, array( 'XMLRPC_REQUEST' => true ) );
		$args    = array( array( $verb, $path, $body, 123, array(), 'en_US' ), array() );
		$payload = array(
			'methodName' => $method,
			'params'     => $args,
		);
		$call    = $multicall
			? new \IXR_Request( 'system.multicall', array( array( $payload ) ) )
			: new \IXR_Request( $method, $args );

		$previous = $GLOBALS['HTTP_RAW_POST_DATA'] ?? null;
		try {
			$GLOBALS['HTTP_RAW_POST_DATA'] = $call->getXml(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The request body set by xmlrpc.php before plugins load.
			$this->register_extension_facades();
			$this->assertSame( ! $activates, in_array( array( $this->loader, 'autoload_facade' ), $this->registered_autoloaders(), true ) );
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
			} else {
				$GLOBALS['HTTP_RAW_POST_DATA'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original request.
			}
			Constants::clear_single_constant( 'XMLRPC_REQUEST' );
		}
	}

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

		$this->setExpectedIncorrectUsage( 'WC_Payments_Features::is_wcpay_subscriptions_enabled()' );
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
	 * @testdox Each WC_Payments method reports its compatibility warning once per request.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_payments_member_warns_once_per_request(): void {
		$loader = new WooPaymentsExtensionFacadeLoader();
		$loader->autoload_facade( 'WC_Payments' );
		$this->setExpectedIncorrectUsage( 'WC_Payments::get_gateway()' );
		$this->setExpectedIncorrectUsage( 'WC_Payments::hide_gateways_on_settings_page()' );

		$warnings = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $member, $message, $version ) use ( &$warnings ) {
				$warnings[] = array( $member, $message, $version );
			},
			10,
			3
		);

		for ( $i = 0; $i < 5; ++$i ) {
			\WC_Payments::get_gateway();
		}
		\WC_Payments::hide_gateways_on_settings_page();

		$this->assertSame(
			array(
				array( 'WC_Payments::get_gateway()', esc_html( "Use WC()->payment_gateways()->payment_gateways()['woocommerce_payments'] ?? null instead." ), '11.4.0' ),
				array( 'WC_Payments::hide_gateways_on_settings_page()', 'WC_Payments::hide_gateways_on_settings_page() is a compatibility shim for the WooPayments extension with no replacement in WooCommerce and will be removed in 12.0.0.', '11.4.0' ),
			),
			$warnings
		);
	}

	/**
	 * @testdox The WC_Payments facade returns the container-owned gateway and keeps the extension's Blueprint settings call safe.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_payments_facade_returns_the_container_gateway(): void {
		$this->register_extension_facades();
		$this->assertTrue( class_exists( 'WC_Payments' ), 'The legacy WooPayments facade should be loaded.' );
		if ( ! class_exists( 'WC_Payments' ) ) {
			return;
		}

		$this->setExpectedIncorrectUsage( 'WC_Payments::get_gateway()' );
		$this->assertSame( wc_get_container()->get( WooPaymentsGateway::class ), \WC_Payments::get_gateway(), 'The facade must expose the same native gateway instance as the dependency-injection container.' );

		$gateways = WC()->payment_gateways->payment_gateways();

		$this->setExpectedIncorrectUsage( 'WC_Payments::hide_gateways_on_settings_page()' );
		$this->assertNull( \WC_Payments::hide_gateways_on_settings_page(), 'The native facade should preserve the plugin method\'s void-like return shape.' );
		$this->assertSame( $gateways, WC()->payment_gateways->payment_gateways(), 'The native facade must not remove gateways by emulating the unsupported plugin gateway class.' );
	}

	/**
	 * @testdox The loader does not emulate the WooPayments extension's gateway class.
	 */
	public function test_gateway_does_not_satisfy_the_extension_gateway_class_identity(): void {
		$loader = new WooPaymentsExtensionFacadeLoader();
		$loader->autoload_facade( 'WC_Payment_Gateway_WCPay' );

		$gateway = wc_get_container()->get( WooPaymentsGateway::class );

		$this->assertFalse( class_exists( 'WC_Payment_Gateway_WCPay', false ), 'The compatibility boundary must not declare the standalone plugin gateway class.' );
		$this->assertFalse( $gateway instanceof \WC_Payment_Gateway_WCPay, 'The native gateway must not pretend to be the standalone plugin gateway class.' );
	}

	/**
	 * @testdox The loader registers no facade autoloader and leaves the version constant alone when the built-in WooPayments does not own payments.
	 */
	public function test_facades_stay_absent_when_the_builtin_does_not_own_payments(): void {
		$this->set_request( array() );
		$version_defined = defined( 'WCPAY_VERSION_NUMBER' );

		$this->register_extension_facades( false );

		$this->assert_loader_stood_aside( $version_defined, 'outside native ownership.' );
	}

	/**
	 * @testdox The loader reads the setup tier only on a store the built-in WooPayments owns: $_dataName.
	 * @dataProvider tier_read_provider
	 *
	 * @param bool $builtin_owns     Whether the built-in WooPayments owns payments.
	 * @param bool $feature_on       Whether the WooPayments feature is on.
	 * @param bool $expect_tier_read Whether the loader must read the setup tier.
	 */
	public function test_the_setup_tier_is_read_only_on_a_store_the_builtin_owns( bool $builtin_owns, bool $feature_on, bool $expect_tier_read ): void {
		$this->set_request( array() );
		$version_defined = defined( 'WCPAY_VERSION_NUMBER' );

		// The real setup tier asks the arbiter whether the feature is on as its first step, so that call counts tier reads.
		$tier_reads = 0;
		$this->register_extension_facades(
			$builtin_owns,
			WooPaymentsSetupTier::AVAILABLE,
			$feature_on,
			false,
			function ( $arbiter ) use ( &$tier_reads ): void {
				$arbiter->expects( $this->any() )->method( 'is_builtin_enabled' )->willReturnCallback(
					function () use ( &$tier_reads ): bool {
						// The stub registered first decides the answer; this one only counts.
						++$tier_reads;
						return true;
					}
				);
			}
		);

		$this->assertSame( $expect_tier_read ? 1 : 0, $tier_reads, 'The loader must read the setup tier once on a store the built-in owns and not at all on any other store, which does the work it did before.' );
		$this->assert_loader_stood_aside( $version_defined, 'on a store the built-in does not own or below the connected tier.' );
	}

	/**
	 * The owner decision and the feature state, with the tier read each must make.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool}>
	 */
	public function tier_read_provider(): array {
		return array(
			// Owner, feature on, tier read expected.
			'the built-in owns payments'            => array( true, true, true ),
			'another runtime owns payments'         => array( false, true, false ),
			'feature off, the extension runs alone' => array( false, false, false ),
		);
	}

	/**
	 * @testdox The facades and the version constant exist only on the connected and active tiers: $_dataName.
	 * @dataProvider setup_tier_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool        $builtin_owns  Whether the built-in WooPayments owns payments.
	 * @param string|null $stored_tier   The stored setup tier, or null when the option was never written.
	 * @param bool        $feature_on    Whether the WooPayments feature is on.
	 * @param bool        $extension_ran Whether the WooPayments extension ran on this store.
	 * @param bool        $expect_facade Whether the facades and the version constant must exist.
	 */
	public function test_facades_exist_only_on_the_connected_and_active_tiers( bool $builtin_owns, ?string $stored_tier, bool $feature_on, bool $extension_ran, bool $expect_facade ): void {
		$this->set_request( array() );

		$this->register_extension_facades( $builtin_owns, $stored_tier, $feature_on, $extension_ran );

		if ( ! $expect_facade ) {
			$this->assertFalse( in_array( array( $this->loader, 'autoload_facade' ), $this->registered_autoloaders(), true ), 'The loader must register no facade autoloader below the connected tier.' );
			$this->assertFalse( class_exists( 'WC_Payments' ), 'A consumer probing WC_Payments must find nothing below the connected tier.' );
			$this->assertFalse( class_exists( 'WC_Payments_Features' ), 'A consumer probing WC_Payments_Features must find nothing below the connected tier.' );
			$this->assertFalse( class_exists( 'WC_Payments_Utils' ), 'A consumer probing WC_Payments_Utils must find nothing below the connected tier.' );
			$this->assertFalse( defined( 'WCPAY_VERSION_NUMBER' ), 'The version constant must stay undefined below the connected tier.' );
			return;
		}

		$this->assertTrue( class_exists( 'WC_Payments' ), 'A consumer probing WC_Payments must find the facade on the connected and active tiers.' );
		$this->assertTrue( class_exists( 'WC_Payments_Features' ), 'A consumer probing WC_Payments_Features must find the facade on the connected and active tiers.' );
		$this->assertTrue( class_exists( 'WC_Payments_Utils' ), 'A consumer probing WC_Payments_Utils must find the facade on the connected and active tiers.' );
		$this->assertTrue( defined( 'WCPAY_VERSION_NUMBER' ), 'The version constant must exist where the facade classes do.' );
		if ( defined( 'WCPAY_VERSION_NUMBER' ) ) {
			$this->assertSame( WooPaymentsClientVersion::VERSION, WCPAY_VERSION_NUMBER );
			$this->assertGreaterThanOrEqual( 0, version_compare( WCPAY_VERSION_NUMBER, '3.2.0' ), 'The APFS version floor must pass.' );
		}
	}

	/**
	 * One row per setup tier, the feature off, the moment of the switch and a disconnect.
	 *
	 * @return array<string,array{0:bool,1:string|null,2:bool,3:bool,4:bool}>
	 */
	public function setup_tier_provider(): array {
		return array(
			// Owner, stored tier, feature on, extension ran, facade expected.
			'never set up'                              => array( true, null, true, false, false ),
			// A switched store with no account data still holds the install value, which reads as available.
			'available, switched with no account data'  => array( true, WooPaymentsSetupTier::DISABLED, true, true, false ),
			'onboarding started'                        => array( true, WooPaymentsSetupTier::AVAILABLE, true, false, false ),
			'connected'                                 => array( true, WooPaymentsSetupTier::CONNECTED, true, false, true ),
			'active'                                    => array( true, WooPaymentsSetupTier::ACTIVE, true, false, true ),
			// A connected store that turned the feature off: the stored tier alone must not bring the facades back.
			'feature off'                               => array( false, WooPaymentsSetupTier::CONNECTED, false, false, false ),
			// The tier the cutover job's finalize step writes before the request that deactivates the extension ends, and
			// the one it leaves while that write has not happened yet.
			'switch moment, the tier finalize wrote'    => array( true, WooPaymentsSetupTier::CONNECTED, true, true, true ),
			'switch moment, before the tier is written' => array( true, WooPaymentsSetupTier::AVAILABLE, true, true, false ),
			// The tier an account reset or a deleted-account event leaves.
			'disconnect, the tier the reset leaves'     => array( true, WooPaymentsSetupTier::AVAILABLE, true, false, false ),
		);
	}

	/**
	 * @testdox Official WordPress activation requests and WordPress's plugin sandbox get no facade autoloader and no version constant from the loader.
	 * @dataProvider official_activation_request_provider
	 *
	 * @param array<string,mixed>  $request   Request parameters.
	 * @param array<string,string> $server    Server parameters.
	 * @param array<string,bool>   $constants Constants WordPress defines for the request.
	 */
	public function test_official_activation_requests_keep_extension_symbols_available( array $request, array $server, array $constants = array() ): void {
		$this->set_request( $request, $server, null, $constants );
		$version_defined = defined( 'WCPAY_VERSION_NUMBER' );

		$this->register_extension_facades();

		// Without an autoloader, even a consumer probing the classes earlier in the request declares nothing.
		$this->assert_loader_stood_aside( $version_defined, 'for an activation request.' );
	}

	/**
	 * Official WordPress request shapes that can activate WooPayments after plugins_loaded, and the plugin sandbox.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>,2?:array<string,bool>}>
	 */
	public function official_activation_request_provider(): array {
		return array(
			// WordPress defines WP_SANDBOX_SCRAPING while it sandbox-loads a plugin and in the file editor's scrape request.
			// The loader runs inside that request when WooCommerce is activated in the plugin sandbox, or in the file
			// editor's scrape request.
			'sandbox scraping'                      => array( array(), array(), array( 'WP_SANDBOX_SCRAPING' => true ) ),
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
	 * @testdox WP-CLI activation commands get no facade autoloader and no version constant from the loader.
	 * @dataProvider wp_cli_activation_request_provider
	 *
	 * @param array<int,string> $arguments WP-CLI arguments.
	 */
	public function test_wp_cli_keeps_extension_symbols_available_for_activation( array $arguments ): void {
		$this->set_request( array(), array(), $arguments );
		$version_defined = defined( 'WCPAY_VERSION_NUMBER' );

		$this->register_extension_facades();

		// Without an autoloader, even a consumer probing the classes earlier in the command declares nothing.
		$this->assert_loader_stood_aside( $version_defined, 'for a WP-CLI activation.' );
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
	 * @testdox Non-activating WP-CLI requests are not recognized as a WooPayments activation.
	 * @dataProvider wp_cli_non_activation_request_provider
	 *
	 * @param array<int,string> $arguments WP-CLI arguments.
	 */
	public function test_ordinary_wp_cli_request_keeps_the_facades( array $arguments ): void {
		$this->set_request( array(), array(), $arguments );

		$this->assertFalse( $this->is_activation_request(), 'Ordinary WP-CLI commands should retain native WooPayments compatibility.' );
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

		// The extension sandbox-loaded after load() ran, as activate_plugin() from code or an Action Scheduler callback does.
		// The loader's WP_SANDBOX_SCRAPING check never sees this; it decides only when WooCommerce is activated in the
		// plugin sandbox, or in the file editor's scrape request. Here, declaring on first use leaves both names free.
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
	 * @testdox A request that is not a WooPayments activation is not recognized as one ($_dataName).
	 * @dataProvider non_activation_request_provider
	 *
	 * @param array<string,mixed>  $request Request parameters.
	 * @param array<string,string> $server  Server parameters.
	 */
	public function test_non_activation_requests_keep_the_facades( array $request, array $server ): void {
		$this->set_request( $request, $server );

		$this->assertFalse( $this->is_activation_request(), 'Requests that do not activate WooPayments should retain native WooPayments compatibility.' );
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
	 * Give the activation predicate this request and nothing an earlier test left behind: the request parameters, the
	 * method and URI, the WP-CLI arguments, and the constants it reads, with a plugins directory holding WooPayments in a
	 * renamed folder. tearDown() restores them.
	 *
	 * @param array<string,mixed>    $request   Request parameters.
	 * @param array<string,string>   $server    `REQUEST_METHOD` and `REQUEST_URI`, when the request has them.
	 * @param array<int,string>|null $arguments WP-CLI arguments, or null outside WP-CLI.
	 * @param array<string,bool>     $constants Constants WordPress defines for the request.
	 */
	private function set_request( array $request, array $server = array(), ?array $arguments = null, array $constants = array() ): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = $request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reproducing a plugin, REST or WP-CLI request.
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['argv'] );
		$_SERVER = array_merge( $_SERVER, $server );
		if ( null !== $arguments ) {
			$_SERVER['argv'] = $arguments;
		}

		$this->plugins_dir = sys_get_temp_dir() . '/wc-facade-loader-' . wp_generate_uuid4();
		wp_mkdir_p( dirname( $this->plugins_dir . '/' . self::RENAMED_MAIN_FILE ) );
		file_put_contents( $this->plugins_dir . '/' . self::RENAMED_MAIN_FILE, "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.

		// Constants set here win over defined ones, so a WP_SANDBOX_SCRAPING an earlier activate_plugin() defined reads false.
		Constants::set_constant( 'WP_CLI', null !== $arguments );
		Constants::set_constant( 'WP_SANDBOX_SCRAPING', false );
		Constants::set_constant( 'WP_PLUGIN_DIR', $this->plugins_dir );
		foreach ( $constants as $name => $value ) {
			Constants::set_constant( $name, $value );
		}
	}

	/**
	 * Register the facade loader under a controlled ownership decision.
	 *
	 * The setup tier is the real class on a mocked arbiter, with the stored tier written to its option (connected by
	 * default, so rows about other decisions reach them).
	 *
	 * @param bool          $builtin_owns  Whether the native runtime owns payments.
	 * @param string|null   $stored_tier   The stored setup tier, or null to leave the option unwritten.
	 * @param bool          $feature_on    Whether the WooPayments feature is on.
	 * @param bool          $extension_ran Whether the WooPayments extension ran on this store.
	 * @param callable|null $arbiter_spy   Called with the arbiter mock before the loader runs, to record or forbid calls.
	 */
	private function register_extension_facades( bool $builtin_owns = true, ?string $stored_tier = WooPaymentsSetupTier::CONNECTED, bool $feature_on = true, bool $extension_ran = false, ?callable $arbiter_spy = null ): void {
		// A process whose bootstrap found native payments enabled already registered the production loader for an ordinary
		// request; the request under test replaces it, as it would be the only loader in that request. tearDown() puts
		// it back.
		foreach ( $this->boot_autoloaders as $autoloader ) {
			spl_autoload_unregister( $autoloader );
		}

		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( $builtin_owns );
		$arbiter->method( 'is_builtin_enabled' )->willReturn( $feature_on );
		$arbiter->method( 'get_runtime_owner' )->willReturn( $builtin_owns ? WooPaymentsRuntimeArbiter::OWNER_BUILTIN : WooPaymentsRuntimeArbiter::OWNER_NONE );
		$arbiter->method( 'has_run_woopayments_extension' )->willReturn( $extension_ran );
		if ( null !== $arbiter_spy ) {
			$arbiter_spy( $arbiter );
		}

		if ( null === $stored_tier ) {
			delete_option( WooPaymentsSetupTier::OPTION_NAME );
		} else {
			update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_tier );
		}
		$setup_tier = new WooPaymentsSetupTier();
		$setup_tier->init( $arbiter );

		$this->loader = new WooPaymentsExtensionFacadeLoader();
		$this->loader->init( $arbiter, $setup_tier );
		$this->loader->register();
	}

	/**
	 * Assert the test's loader registered no facade autoloader and left the version constant as it found it.
	 *
	 * @param bool   $version_defined Whether `WCPAY_VERSION_NUMBER` was defined before the loader ran.
	 * @param string $context         The request, for the failure message.
	 */
	private function assert_loader_stood_aside( bool $version_defined, string $context ): void {
		$this->assertFalse( in_array( array( $this->loader, 'autoload_facade' ), $this->registered_autoloaders(), true ), 'The loader must register no facade autoloader ' . $context );
		$this->assertSame( $version_defined, defined( 'WCPAY_VERSION_NUMBER' ), 'The loader must leave the version constant as it found it ' . $context );
	}

	/**
	 * Ask a fresh loader whether the current request activates WooPayments, without load() defining the version constant
	 * for the rest of the process.
	 *
	 * @return bool The loader's private activation-request answer.
	 */
	private function is_activation_request(): bool {
		$method = new \ReflectionMethod( WooPaymentsExtensionFacadeLoader::class, 'is_woopayments_activation_request' );
		$method->setAccessible( true );

		return (bool) $method->invoke( new WooPaymentsExtensionFacadeLoader() );
	}

	/**
	 * The registered autoloaders. PHP 7.4's spl_autoload_functions() can return false where PHP 8 returns an empty list.
	 *
	 * @return array<int,callable>
	 */
	private function registered_autoloaders(): array {
		$autoloaders = spl_autoload_functions();

		return is_array( $autoloaders ) ? $autoloaders : array();
	}

	/**
	 * Tell whether an autoloader belongs to a facade loader.
	 *
	 * @param mixed $autoloader A registered autoloader.
	 * @return bool True for a facade loader's autoloader.
	 */
	private function is_facade_autoloader( $autoloader ): bool {
		return is_array( $autoloader ) && $autoloader[0] instanceof WooPaymentsExtensionFacadeLoader;
	}
}
